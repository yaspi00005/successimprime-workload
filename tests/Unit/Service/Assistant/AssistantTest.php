<?php

namespace App\Tests\Unit\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantMessageRepository;
use App\Service\Assistant\Assistant;
use App\Service\Assistant\ConsignesAssistant;
use App\Service\Assistant\ContexteAssistant;
use App\Service\Assistant\FabriqueClaude;
use App\Service\Assistant\OutilsAssistant;
use App\Service\Assistant\ReglagesAssistant;
use App\Service\Assistant\SourceEspace;
use App\Service\Assistant\SourceSite;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Boucle de l'assistant contre un faux transport HTTP : outils, historique
 * rejoué tel quel, refus, erreurs, plafond, repli du serveur.
 */
final class AssistantTest extends TestCase
{
    private TransportFactice $transport;

    /** @var list<AssistantMessage> ce que l'assistant a enregistré */
    private array $enregistres = [];

    private float $depenseDuJour = 0.0;

    private Assistant $assistant;

    private ReglagesAssistant $reglages;

    protected function setUp(): void
    {
        $this->transport = new TransportFactice();
        $this->enregistres = [];
        $this->depenseDuJour = 0.0;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof AssistantMessage && !\in_array($o, $this->enregistres, true)) {
                $this->enregistres[] = $o;
            }
        });

        $messages = $this->createStub(AssistantMessageRepository::class);
        $messages->method('deLaConversation')->willReturnCallback(fn (AssistantConversation $c): array => array_values(array_filter($this->enregistres, static fn (AssistantMessage $m): bool => $m->getConversation() === $c)));
        $messages->method('depenseDepuis')->willReturnCallback(fn (): float => $this->depenseDuJour);

        $this->reglages = $this->createStub(ReglagesAssistant::class);
        $this->reglages->method('demo')->willReturn(false);
        $this->reglages->method('cle')->willReturn('sk-ant-essai');
        $this->reglages->method('modele')->willReturn('claude-opus-5-5');
        $this->reglages->method('effort')->willReturn('low');
        $this->reglages->method('limiteJour')->willReturn(5000);
        $this->reglages->method('tauxUsd')->willReturn(600.0);
        $this->reglages->method('tarif')->willReturn(['entree' => 4.0, 'sortie' => 20.0, 'cacheLecture' => 0.2, 'cacheEcriture' => 5.0]);
        $this->reglages->method('delais')->willReturn('Annoncé à la validation du BAT.');
        $this->reglages->method('lienWhatsApp')->willReturnCallback(static fn (string $texte): string => 'https://wa.me/22370000000?text='.rawurlencode($texte));

        $site = $this->createStub(SourceSite::class);
        $site->method('boutique')->willReturn(['nom' => 'Succès Imprim', 'adresse' => 'Bamako', 'telephone' => '70 00 00 00', 'email' => null, 'horaires' => 'lun – sam · 08:00 – 18:00', 'ouvert' => 'ouvert', 'plan' => null]);
        $site->method('lien')->willReturnCallback(static fn (string $route): string => '/'.$route);

        $outils = new OutilsAssistant($site, $this->createStub(SourceEspace::class), $this->reglages, new RateLimiterFactory(['id' => 'essai', 'policy' => 'no_limit'], new InMemoryStorage()));
        $this->assistant = new Assistant($em, $messages, $this->reglages, $outils, new FabriqueClaude($this->reglages, $this->transport), new NullLogger());
    }

    private static function conversation(string $modele = 'claude-opus-5-5'): AssistantConversation
    {
        return AssistantConversation::nouvelle(AssistantConversation::SITE, $modele, new \DateTimeImmutable('2026-10-14 10:00'))->setNumero('AS-0001');
    }

    private static function ctx(string $heure = '10:00'): ContexteAssistant
    {
        return ContexteAssistant::site(new \DateTimeImmutable('2026-10-14 '.$heure), '/produits');
    }

    /** @return list<array<string, mixed>> réponse qui appelle infos_boutique, avec sa réflexion */
    private static function appelOutil(string $id = 'toolu_1'): array
    {
        return [
            ['type' => 'thinking', 'thinking' => 'La personne veut les horaires.', 'signature' => 'sig-'.$id],
            ['type' => 'tool_use', 'id' => $id, 'name' => 'infos_boutique', 'input' => new \stdClass()],
        ];
    }

    public function testBoucleOutilPuisReponse(): void
    {
        $this->transport
            ->repondre(self::appelOutil(), 'tool_use')
            ->repondre([['type' => 'text', 'text' => 'Nous sommes ouverts du lundi au samedi, de 8 h à 18 h.']]);

        $vue = $this->assistant->repondre(self::conversation(), self::ctx(), 'Quels sont vos horaires ?');

        self::assertSame(AssistantMessage::ISSUE_REPONSE, $vue['issue']);
        self::assertSame('Nous sommes ouverts du lundi au samedi, de 8 h à 18 h.', $vue['texte']);
        self::assertSame([['libelle' => 'Préparer ses fichiers', 'url' => '/app_site_guide', 'type' => 'lien']], $vue['actions']);
        self::assertCount(2, $this->transport->requetes);

        // Deuxième appel : la question, la réponse de l'API telle quelle (réflexion et objet vide compris), le résultat de l'outil.
        $deuxieme = $this->transport->requetes[1]['corps'];
        self::assertCount(3, $deuxieme['messages']);
        self::assertSame('user', $deuxieme['messages'][0]['role']);
        self::assertStringStartsWith('<contexte>', $deuxieme['messages'][0]['content'][0]['text']);
        self::assertSame('Quels sont vos horaires ?', $deuxieme['messages'][0]['content'][1]['text']);
        self::assertEquals(['type' => 'thinking', 'thinking' => 'La personne veut les horaires.', 'signature' => 'sig-toolu_1'], $deuxieme['messages'][1]['content'][0]);
        self::assertStringContainsString('"input":{}', $this->transport->requetes[1]['brut']);
        $resultat = $deuxieme['messages'][2]['content'][0];
        self::assertSame('tool_result', $resultat['type']);
        self::assertSame('toolu_1', $resultat['tool_use_id']);
        self::assertArrayNotHasKey('is_error', $resultat);
        self::assertSame('Succès Imprim', json_decode($resultat['content'], true)['nom']);

        // Enregistrés : la question, la réponse, l'outil, la dépense des deux appels.
        [$question, $reponse] = $this->enregistres;
        self::assertTrue($question->estDuClient());
        self::assertSame(['infos_boutique'], $reponse->getListeOutils());
        self::assertSame(2000, $reponse->getJetonsEntree());
        self::assertSame(200, $reponse->getJetonsSortie());
        self::assertEqualsWithDelta(0.012, $reponse->getDepenseUsd(), 1e-9);
        self::assertEqualsWithDelta(7.2, $reponse->getDepenseFcfa(), 1e-9);
    }

    public function testRequeteEnvoyee(): void
    {
        $this->transport->repondre([['type' => 'text', 'text' => 'Bonjour !']]);
        $this->assistant->repondre(self::conversation(), self::ctx(), 'Bonjour');

        $r = $this->transport->requetes[0];
        $corps = $r['corps'];
        self::assertSame('claude-opus-5-5', $corps['model']);
        self::assertSame(4096, $corps['max_tokens']);
        self::assertSame(['effort' => 'low'], $corps['output_config']);
        // Pas de réflexion imposée, pas de choix d'outil forcé, pas de réponse préremplie.
        self::assertArrayNotHasKey('thinking', $corps);
        self::assertArrayNotHasKey('tool_choice', $corps);
        self::assertSame('user', end($corps['messages'])['role']);
        // Cache : consignes figées, point de cache sur le dernier bloc système, et automatique en haut.
        self::assertSame(ConsignesAssistant::SYSTEME, $corps['system'][0]['text']);
        self::assertSame(['type' => 'ephemeral'], end($corps['system'])['cache_control']);
        self::assertSame(['type' => 'ephemeral'], $corps['cache_control']);
        // Outils stricts du site seulement.
        self::assertSame(OutilsAssistant::OUTILS_SITE, array_column($corps['tools'], 'name'));
        foreach ($corps['tools'] as $outil) {
            self::assertTrue($outil['strict']);
            self::assertFalse($outil['input_schema']['additionalProperties']);
        }
        // Repli du serveur pour Opus 5.5.
        self::assertSame('default', $corps['fallbacks']);
        self::assertStringContainsString(Assistant::BETA_REPLI, $r['entetes']['anthropic-beta'] ?? '');
        self::assertSame('sk-ant-essai', $r['entetes']['x-api-key'] ?? null);
    }

    public function testPasDeRepliPourUnModeleQuiNeLeConnaitPas(): void
    {
        $this->transport->repondre([['type' => 'text', 'text' => 'Bonjour !']], 'end_turn', [], 'claude-haiku-5-5');
        $this->assistant->repondre(self::conversation('claude-haiku-5-5'), self::ctx(), 'Bonjour');

        self::assertArrayNotHasKey('fallbacks', $this->transport->requetes[0]['corps']);
        self::assertStringNotContainsString(Assistant::BETA_REPLI, $this->transport->requetes[0]['entetes']['anthropic-beta'] ?? '');
    }

    public function testHistoriqueRejoueSansRienReecrire(): void
    {
        $conversation = self::conversation();
        $this->transport
            ->repondre(self::appelOutil(), 'tool_use')
            ->repondre([['type' => 'thinking', 'thinking' => 'Réponse.', 'signature' => 'sig-2'], ['type' => 'text', 'text' => 'De 8 h à 18 h.']])
            ->repondre([['type' => 'text', 'text' => 'Oui, le samedi aussi.']]);

        $this->assistant->repondre($conversation, self::ctx('10:00'), 'Vos horaires ?');
        $this->assistant->repondre($conversation, self::ctx('10:05'), 'Et le samedi ?');

        [, $deuxieme, $troisieme] = $this->transport->requetes;
        $avant = $deuxieme['corps']['messages'];
        $apres = $troisieme['corps']['messages'];
        self::assertCount(5, $apres);
        // Le début est identique, octet pour octet : ce qui a été envoyé au tour précédent.
        self::assertSame(json_encode($avant), json_encode(\array_slice($apres, 0, 3)));
        // Puis la réponse finale reçue, réflexion et signature comprises.
        self::assertEquals(['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'Réponse.', 'signature' => 'sig-2'], ['type' => 'text', 'text' => 'De 8 h à 18 h.']]], $apres[3]);
        // Puis la nouvelle question, avec son propre contexte.
        self::assertSame('Et le samedi ?', $apres[4]['content'][1]['text']);
        self::assertStringContainsString('10:05', $apres[4]['content'][0]['text']);
        // Les consignes et les outils ne bougent pas d'une question à l'autre.
        self::assertSame(json_encode($deuxieme['corps']['system']), json_encode($troisieme['corps']['system']));
        self::assertSame(json_encode($deuxieme['corps']['tools']), json_encode($troisieme['corps']['tools']));
    }

    public function testRefusSansLireLeContenuEtHorsHistorique(): void
    {
        $conversation = self::conversation();
        $this->transport
            ->repondre([['type' => 'text', 'text' => 'texte partiel à ne pas montrer']], 'refusal')
            ->repondre([['type' => 'text', 'text' => 'De 8 h à 18 h.']]);

        $vue = $this->assistant->repondre($conversation, self::ctx(), 'Question refusée');

        self::assertSame(AssistantMessage::ISSUE_REFUS, $vue['issue']);
        self::assertSame(Assistant::TEXTE_REFUS, $vue['texte']);
        self::assertSame('whatsapp', $vue['actions'][0]['type']);
        self::assertStringStartsWith('https://wa.me/', $vue['actions'][0]['url']);
        self::assertNotNull($conversation->getPassageAgentLe());
        foreach ($this->enregistres as $m) {
            self::assertNull($m->getContenuApi(), 'un tour refusé n\'entre pas dans l\'historique');
        }

        // La question suivante repart sans le tour refusé.
        $this->assistant->repondre($conversation, self::ctx('10:01'), 'Vos horaires ?');
        $messages = $this->transport->requetes[1]['corps']['messages'];
        self::assertCount(1, $messages);
        self::assertSame('Vos horaires ?', $messages[0]['content'][1]['text']);
    }

    public function testErreurDeLApi(): void
    {
        $this->transport->echouer(400);
        $vue = $this->assistant->repondre(self::conversation(), self::ctx(), 'Bonjour');

        self::assertSame(AssistantMessage::ISSUE_ERREUR, $vue['issue']);
        self::assertSame(Assistant::TEXTE_ERREUR, $vue['texte']);
        self::assertSame('whatsapp', $vue['actions'][0]['type']);
        self::assertNull($this->enregistres[1]->getContenuApi());
    }

    public function testPlafondDuJourSansAppel(): void
    {
        $this->depenseDuJour = 5000.0;
        $vue = $this->assistant->repondre(self::conversation(), self::ctx(), 'Bonjour');

        self::assertSame(AssistantMessage::ISSUE_PLAFOND, $vue['issue']);
        self::assertStringContainsString('WhatsApp', $vue['texte']);
        self::assertSame('whatsapp', $vue['actions'][0]['type']);
        self::assertSame([], $this->transport->requetes);
    }

    public function testConversationTropLongue(): void
    {
        $conversation = self::conversation();
        for ($i = 0; $i < Assistant::QUESTIONS_MAX; ++$i) {
            $conversation->noterQuestion(new \DateTimeImmutable('2026-10-14 09:00'));
        }
        $vue = $this->assistant->repondre($conversation, self::ctx(), 'Encore une');

        self::assertSame(AssistantMessage::ISSUE_LIMITE, $vue['issue']);
        self::assertSame([], $this->transport->requetes);
    }

    public function testPassageAUnConseiller(): void
    {
        $conversation = self::conversation();
        $this->transport
            ->repondre([['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'passer_a_un_agent', 'input' => ['motif' => 'argent', 'resume' => 'Je voudrais une remise sur 200 flyers.']]], 'tool_use')
            ->repondre([['type' => 'text', 'text' => 'Un conseiller peut vous répondre sur WhatsApp.']]);

        $vue = $this->assistant->repondre($conversation, self::ctx(), 'Vous pouvez me faire une remise ?');

        self::assertSame(AssistantMessage::ISSUE_AGENT, $vue['issue']);
        self::assertSame('whatsapp', $vue['actions'][0]['type']);
        $texte = rawurldecode(explode('?text=', $vue['actions'][0]['url'])[1]);
        self::assertStringContainsString('AS-0001', $texte);
        self::assertStringContainsString('Je voudrais une remise sur 200 flyers.', $texte);
        self::assertNotNull($conversation->getPassageAgentLe());
    }

    public function testOutilInconnuOuHorsContexte(): void
    {
        // Sur le site, mes_commandes n'existe pas : le résultat est une erreur lue par le modèle.
        $this->transport
            ->repondre([['type' => 'tool_use', 'id' => 'toolu_5', 'name' => 'mes_commandes', 'input' => ['numero' => '']]], 'tool_use')
            ->repondre([['type' => 'text', 'text' => 'Connectez-vous à votre espace client pour suivre vos commandes.']]);

        $this->assistant->repondre(self::conversation(), self::ctx(), 'Où en est ma commande ?');

        $resultat = $this->transport->requetes[1]['corps']['messages'][2]['content'][0];
        self::assertTrue($resultat['is_error']);
        self::assertSame(['erreur' => 'Outil inconnu.'], json_decode($resultat['content'], true));
    }

    public function testQuestionVerifiee(): void
    {
        self::assertSame('Bonjour', Assistant::verifierQuestion("  Bonjour\u{0007}  "));
        $this->expectException(\DomainException::class);
        Assistant::verifierQuestion(str_repeat('a', Assistant::QUESTION_MAX + 1));
    }
}
