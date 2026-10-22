<?php

namespace App\Service\Assistant;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Répond à une question (docs/assistant-client.md) : appels de l'API avec
 * les outils, jusqu'à la réponse finale, puis enregistrement.
 *
 * L'historique ne fait que s'allonger : chaque tour (le message du client,
 * puis les réponses de l'API telles quelles, réflexion comprise, et les
 * résultats d'outils) est gardé et rejoué à l'identique à la question
 * suivante. Rien n'est réécrit ni réordonné : le cache et la réflexion
 * des tours précédents restent valables. Un tour sans réponse utilisable
 * (refus, erreur) n'entre pas dans l'historique.
 */
final class Assistant
{
    public const QUESTION_MAX = 800;
    /** Au-delà, une nouvelle conversation (l'historique rejoué coûte à chaque question). */
    public const QUESTIONS_MAX = 30;
    /** Appels de l'API pour une question (outils compris). */
    public const TOURS_MAX = 6;
    public const JETONS_MAX = 4096;
    public const ACTIONS_MAX = 4;

    /** Repli du serveur (server-side fallback) : seulement pour les modèles qui l'acceptent. */
    public const MODELES_AVEC_REPLI = ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];
    public const BETA_REPLI = 'server-side-fallback-2026-07-01';

    public const TEXTE_PLAFOND = 'L\'assistant a beaucoup répondu aujourd\'hui et fait une pause jusqu\'à demain. Écrivez-nous sur WhatsApp : un conseiller vous répond.';
    public const TEXTE_LIMITE = 'Cette conversation est déjà longue. Recommencez-en une nouvelle (bouton « Nouvelle conversation »), ou écrivez-nous sur WhatsApp.';
    public const TEXTE_REFUS = 'Je ne peux pas répondre à cette question ici. Un conseiller de l\'atelier peut vous aider sur WhatsApp.';
    public const TEXTE_ERREUR = 'Je n\'arrive pas à répondre pour le moment. Réessayez dans un instant, ou écrivez-nous sur WhatsApp.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssistantMessageRepository $messages,
        private readonly ReglagesAssistant $reglages,
        private readonly OutilsAssistant $outils,
        private readonly FabriqueClaude $fabrique,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Nouvelle conversation, avec le modèle du moment.
     *
     * @return array{0: AssistantConversation, 1: string} la conversation et le jeton à garder par le navigateur
     */
    public function commencer(ContexteAssistant $ctx): array
    {
        $jeton = bin2hex(random_bytes(32));
        $conversation = AssistantConversation::nouvelle($ctx->contexte, $this->reglages->modele(), $ctx->maintenant)
            ->setJeton($jeton)
            ->setPage($ctx->page);
        if ($ctx->estEspace() && null !== $ctx->compte) {
            $conversation->rattacher($ctx->compte);
        }
        $this->em->persist($conversation);
        $this->em->flush();
        if (null !== $conversation->getId()) {
            // Le numéro vient de l'id ; en ligne il reçoit le préfixe W-.
            $conversation->setNumero(\sprintf('AS-%04d', $conversation->getId()));
            $this->em->flush();
        }

        return [$conversation, $jeton];
    }

    /**
     * @return array{id: string, texte: string, actions: list<array{libelle: string, url: string, type: string}>, issue: string}
     *
     * @throws \DomainException question vide ou trop longue
     */
    public function repondre(AssistantConversation $conversation, ContexteAssistant $ctx, string $question): array
    {
        $question = self::verifierQuestion($question);

        $debut = $ctx->maintenant;
        $messageClient = AssistantMessage::question($conversation, $question, $debut);
        $conversation->noterQuestion($debut);

        if ($conversation->getNombreQuestions() > self::QUESTIONS_MAX) {
            return $this->sansAppel($conversation, $ctx, $messageClient, AssistantMessage::ISSUE_LIMITE, self::TEXTE_LIMITE, $question);
        }
        if (CoutAssistant::plafondAtteint($this->messages->depenseDepuis($debut->setTime(0, 0)), $this->reglages->limiteJour())) {
            return $this->sansAppel($conversation, $ctx, $messageClient, AssistantMessage::ISSUE_PLAFOND, self::TEXTE_PLAFOND, $question);
        }

        // L'historique tel qu'il a été envoyé et reçu, puis ce tour.
        $historique = [];
        foreach ($this->messages->deLaConversation($conversation) as $m) {
            foreach ($m->getContenuApi() ?? [] as $tour) {
                $historique[] = self::restaurer($tour);
            }
        }
        $tourClient = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => ConsignesAssistant::contexte($ctx)],
            ['type' => 'text', 'text' => $question],
        ]];
        $nouveaux = [];
        $outilsAppeles = [];
        $actions = [];
        $passageAgent = false;
        $jetons = ['entree' => 0, 'sortie' => 0, 'cacheLecture' => 0, 'cacheEcriture' => 0];
        $usd = 0.0;
        $modeleReponse = null;
        $texte = '';
        $issue = AssistantMessage::ISSUE_ERREUR;

        try {
            $client = $this->fabrique->client();
            for ($tour = 1; $tour <= self::TOURS_MAX; ++$tour) {
                $reponse = $this->appeler($client, $conversation, [...$historique, $tourClient, ...$nouveaux]);
                $cout = CoutAssistant::calculer(self::enTableau($reponse->usage), $conversation->getModele(), $this->reglages->tarif());
                foreach ($cout['jetons'] as $cle => $n) {
                    $jetons[$cle] += $n;
                }
                $usd += $cout['usd'];
                $modeleReponse = $reponse->model;

                // Refus : avant de lire le contenu. Le tour n'entre pas dans l'historique.
                if ('refusal' === $reponse->stopReason) {
                    $issue = AssistantMessage::ISSUE_REFUS;
                    $this->logger->notice('Assistant : refus du modèle pour la conversation {numero}.', ['numero' => $conversation->getNumero(), 'categorie' => self::enTableau($reponse->stopDetails ?? [])['category'] ?? null]);
                    break;
                }

                $contenu = self::enTableau($reponse->content);
                $nouveaux[] = ['role' => 'assistant', 'content' => self::restaurerBlocs($contenu)];

                if ('tool_use' !== $reponse->stopReason) {
                    $texte = self::texte($contenu);
                    $issue = '' === $texte ? AssistantMessage::ISSUE_ERREUR : ($passageAgent ? AssistantMessage::ISSUE_AGENT : AssistantMessage::ISSUE_REPONSE);
                    break;
                }

                $resultats = [];
                foreach ($contenu as $bloc) {
                    if ('tool_use' !== ($bloc['type'] ?? null)) {
                        continue;
                    }
                    $nom = (string) ($bloc['name'] ?? '');
                    $outilsAppeles[] = $nom;
                    $resultat = $this->executer($nom, \is_array($bloc['input'] ?? null) ? $bloc['input'] : [], $ctx, $conversation);
                    $passageAgent = $passageAgent || $resultat->passageAgent;
                    $actions = [...$actions, ...$resultat->actions];
                    $resultats[] = ['type' => 'tool_result', 'tool_use_id' => (string) ($bloc['id'] ?? ''), 'content' => $resultat->texte()] + ($resultat->erreur ? ['is_error' => true] : []);
                }
                $nouveaux[] = ['role' => 'user', 'content' => $resultats];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Assistant : appel de l\'API impossible pour la conversation {numero} : {erreur}', ['numero' => $conversation->getNumero(), 'erreur' => $e->getMessage(), 'classe' => $e::class]);
            $issue = AssistantMessage::ISSUE_ERREUR;
        }

        $termine = \in_array($issue, [AssistantMessage::ISSUE_REPONSE, AssistantMessage::ISSUE_AGENT], true);
        if (!$termine) {
            $texte = AssistantMessage::ISSUE_REFUS === $issue ? self::TEXTE_REFUS : self::TEXTE_ERREUR;
            $actions = [];
            if (null !== $lien = $this->reglages->lienWhatsApp(OutilsAssistant::messageWhatsApp(mb_substr($question, 0, 200), $ctx, $conversation->getNumero()))) {
                $actions[] = ResultatOutil::lien('Écrire à un conseiller sur WhatsApp', $lien, 'whatsapp');
            }
        }
        if ($passageAgent || !$termine) {
            $conversation->noterPassageAgent($debut);
        }

        // Le tour entre dans l'historique seulement s'il finit par une réponse.
        $messageClient->setContenuApi($termine ? [$tourClient] : null);
        $reponseAssistant = AssistantMessage::reponse($conversation, $texte, $issue, $ctx->maintenant)
            ->setOutils($outilsAppeles)
            ->setActions(self::dedoublonner($actions))
            ->setContenuApi($termine ? $nouveaux : null)
            ->noterDepense($modeleReponse ?? $conversation->getModele(), $jetons, $usd, CoutAssistant::fcfa($usd, $this->reglages->tauxUsd()));
        $this->em->persist($messageClient);
        $this->em->persist($reponseAssistant);
        $this->em->flush();

        return self::vue($reponseAssistant);
    }

    /**
     * La question nettoyée, à vérifier avant d'ouvrir une conversation.
     *
     * @throws \DomainException question vide ou trop longue
     */
    public static function verifierQuestion(string $question): string
    {
        $question = ConsignesAssistant::question($question);
        if ('' === $question) {
            throw new \DomainException('Écrivez votre question.');
        }
        if (mb_strlen($question) > self::QUESTION_MAX) {
            throw new \DomainException(\sprintf('%d caractères au plus.', self::QUESTION_MAX));
        }

        return $question;
    }

    /** Pouce sur une réponse ; null retire l'avis. */
    public function noter(AssistantConversation $conversation, string $publicId, ?int $note): bool
    {
        $message = $this->messages->reponse($conversation, $publicId);
        if (null === $message) {
            return false;
        }
        $message->noter($note);
        $this->em->flush();

        return true;
    }

    /**
     * Messages à remontrer dans le panneau (autre page, retour plus tard).
     *
     * @return list<array{id: string, role: string, texte: string, actions: list<array{libelle: string, url: string, type: string}>, note: ?int}>
     */
    public function historique(AssistantConversation $conversation): array
    {
        return array_map(static fn (AssistantMessage $m): array => self::vue($m) + ['role' => $m->getRole(), 'note' => $m->getNote()], $this->messages->deLaConversation($conversation));
    }

    /** @return array{id: string, texte: string, actions: list<array{libelle: string, url: string, type: string}>, issue: string} */
    public static function vue(AssistantMessage $m): array
    {
        return ['id' => (string) $m->getPublicId(), 'texte' => $m->getTexte(), 'actions' => $m->getActions(), 'issue' => (string) $m->getIssue()];
    }

    /** @param list<array<string, mixed>> $messages */
    private function appeler(Client $client, AssistantConversation $conversation, array $messages): BetaMessage
    {
        $modele = $conversation->getModele();
        $repli = \in_array($modele, self::MODELES_AVEC_REPLI, true);

        return $client->beta->messages->create(
            maxTokens: self::JETONS_MAX,
            messages: $messages,
            model: $modele,
            // Outils puis consignes, toujours les mêmes : en cache jusqu'au dernier bloc système.
            cacheControl: ['type' => 'ephemeral'],
            fallbacks: $repli ? 'default' : null,
            outputConfig: ['effort' => $this->reglages->effort()],
            system: ConsignesAssistant::systeme(),
            tools: OutilsAssistant::definitions($conversation->getContexte()),
            betas: $repli ? [self::BETA_REPLI] : null,
        );
    }

    private function executer(string $nom, array $entree, ContexteAssistant $ctx, AssistantConversation $conversation): ResultatOutil
    {
        try {
            return $this->outils->executer($nom, $entree, $ctx, $conversation);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant : outil {outil} en échec : {erreur}', ['outil' => $nom, 'erreur' => $e->getMessage()]);

            return ResultatOutil::erreur('L\'outil n\'a pas répondu. Propose un conseiller.');
        }
    }

    /** @return array{id: string, texte: string, actions: list<array{libelle: string, url: string, type: string}>, issue: string} */
    private function sansAppel(AssistantConversation $conversation, ContexteAssistant $ctx, AssistantMessage $messageClient, string $issue, string $texte, string $question): array
    {
        $actions = [];
        if (null !== $lien = $this->reglages->lienWhatsApp(OutilsAssistant::messageWhatsApp(mb_substr($question, 0, 200), $ctx, $conversation->getNumero()))) {
            $actions[] = ResultatOutil::lien('Écrire à un conseiller sur WhatsApp', $lien, 'whatsapp');
        }
        $conversation->noterPassageAgent($ctx->maintenant);
        $reponse = AssistantMessage::reponse($conversation, $texte, $issue, $ctx->maintenant)->setActions($actions);
        $this->em->persist($messageClient);
        $this->em->persist($reponse);
        $this->em->flush();

        return self::vue($reponse);
    }

    /** Texte montré : les blocs de texte, sans la réflexion. */
    public static function texte(array $contenu): string
    {
        $morceaux = [];
        foreach ($contenu as $bloc) {
            if ('text' === ($bloc['type'] ?? null) && '' !== trim((string) ($bloc['text'] ?? ''))) {
                $morceaux[] = trim((string) $bloc['text']);
            }
        }

        return implode("\n\n", $morceaux);
    }

    /** Objet du SDK au format de l'API (clés de l'API). */
    public static function enTableau(mixed $objet): array
    {
        $tableau = json_decode((string) json_encode($objet), true);

        return \is_array($tableau) ? $tableau : [];
    }

    /**
     * Un message gardé, prêt à être renvoyé tel qu'il a été reçu : un objet
     * vide redevient un objet (le JSON des tableaux PHP le perd).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public static function restaurer(array $message): array
    {
        if (\is_array($message['content'] ?? null)) {
            $message['content'] = self::restaurerBlocs($message['content']);
        }

        return $message;
    }

    /**
     * @param list<array<string, mixed>> $blocs
     *
     * @return list<array<string, mixed>>
     */
    public static function restaurerBlocs(array $blocs): array
    {
        foreach ($blocs as $i => $bloc) {
            if (\in_array($bloc['type'] ?? null, ['tool_use', 'server_tool_use'], true) && [] === ($bloc['input'] ?? null)) {
                $blocs[$i]['input'] = new \stdClass();
            }
        }

        return $blocs;
    }

    /**
     * @param list<array{libelle: string, url: string, type: string}> $actions
     *
     * @return list<array{libelle: string, url: string, type: string}>
     */
    public static function dedoublonner(array $actions): array
    {
        $vues = [];
        foreach ($actions as $a) {
            $vues[$a['url']] ??= $a;
        }

        return \array_slice(array_values($vues), 0, self::ACTIONS_MAX);
    }
}
