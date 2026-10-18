<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Entity\AgentMessagerie;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\User;
use App\Security\MessagerieVoter;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\DelaisReponse;
use App\Service\Messagerie\ReglesReponse;
use PHPUnit\Framework\TestCase;

/** Messages des clients (docs/messagerie-clients.md, maquettes MS01 à MS04). */
final class MessagerieTest extends TestCase
{
    public function testDureeLisible(): void
    {
        self::assertSame('0 min', ReglesReponse::duree(-3));
        self::assertSame('12 min', ReglesReponse::duree(12));
        self::assertSame('1 h', ReglesReponse::duree(60));
        self::assertSame('1 h 05', ReglesReponse::duree(65));
        self::assertSame('2 h 40', ReglesReponse::duree(160));
    }

    public function testMontantsMasquesPourUnDemandeur(): void
    {
        self::assertSame('La pose est comprise (… pour les 20 plaques).', Conversations::sansMontants('La pose est comprise (16 000 F pour les 20 plaques).'));
        self::assertSame('Reste … à payer', Conversations::sansMontants('Reste 5.500 FCFA à payer'));
        // « 20 plaques », « 85 × 200 cm » et les heures ne sont pas des montants.
        self::assertSame('Livraison à 9 h, roll-up 85 × 200 cm', Conversations::sansMontants('Livraison à 9 h, roll-up 85 × 200 cm'));
    }

    public function testSujetDevine(): void
    {
        self::assertSame(ConversationClient::SUJET_PAIEMENT, Conversations::devinerSujet('Pouvez-vous m\'envoyer la facture ?', null, null));
        self::assertSame(ConversationClient::SUJET_COMMANDE, Conversations::devinerSujet('Le BAT est prêt ?', new Commandes(), null));
        self::assertSame(ConversationClient::SUJET_AUTRE, Conversations::devinerSujet('Vous faites les mugs ?', null, null));
    }

    public function testSeulsCaisseCommercialEtTresoriereSontImputables(): void
    {
        $caisse = self::avec(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_COMMANDE', 'ROLE_CLIENT']);
        $commercial = self::avec(['ROLE_COMMANDE', 'ROLE_CLIENT']);
        $pao = self::avec(['ROLE_COMMANDE', 'ROLE_CLIENT', 'ROLE_PREPRESSE']);
        $comptable = self::avec(['ROLE_TRESORERIE_VOIR']);
        $direction = (new User())->setRoles(['ROLE_ADMIN']);

        self::assertTrue(Conversations::imputable($caisse));
        self::assertTrue(Conversations::imputable($commercial));
        self::assertFalse(Conversations::imputable($pao), 'la PAO aide, le retard reste au responsable');
        self::assertFalse(Conversations::imputable($comptable));
        self::assertTrue(Conversations::imputable($comptable, $comptable), 'la trésorière choisie dans les règles');
        self::assertFalse(Conversations::imputable($direction));
    }

    public function testRetardEtPenalite(): void
    {
        $responsable = new User();
        $m = (new MessageClient())->noterDelai(43, 30, $responsable, true, 5);
        self::assertTrue($m->estEnRetard());
        self::assertSame(13, $m->getRetardMinutes());
        self::assertSame(5, $m->getPenalite());

        $aLHeure = (new MessageClient())->noterDelai(12, 30, $responsable, true, 5);
        self::assertFalse($aLHeure->estEnRetard());
        self::assertSame(0, $aLHeure->getPenalite());

        // Personne d'imputable (Direction, PAO) : le retard est noté, aucun point retiré.
        self::assertSame(0, (new MessageClient())->noterDelai(90, 60, null, false, 5)->getPenalite());
    }

    public function testMediane(): void
    {
        self::assertNull(DelaisReponse::mediane([]));
        self::assertSame(12, DelaisReponse::mediane([40, 12, 4]));
        self::assertSame(10, DelaisReponse::mediane([4, 8, 12, 40]));
    }

    public function testClotureReouvertureSeptJours(): void
    {
        $le = new \DateTimeImmutable('2026-10-01 10:00');
        $c = ConversationClient::nouvelle(new Clients(), null, null, '  Horaires   du samedi ', ConversationClient::SUJET_AUTRE, $le);
        self::assertSame('Horaires du samedi', $c->getObjet());
        self::assertTrue($c->estOuverte());

        $c->proposerCloture('Mariam', $le->modify('+1 hour'));
        self::assertTrue($c->clotureEnAttente());
        $c->refuserCloture();
        self::assertFalse($c->clotureEnAttente(), '« J\'ai encore une question »');

        $c->cloturer('Oumar Guindo', $le->modify('+2 hours'));
        self::assertTrue($c->estCloturee());
        self::assertTrue($c->rouvrable($le->modify('+6 days'), 7));
        self::assertFalse($c->rouvrable($le->modify('+8 days'), 7), 'après 7 jours : une nouvelle demande liée');
        $c->noter(5, $le->modify('+3 hours'));
        self::assertSame(5, $c->getAvis());
    }

    public function testQuiVoitQuoi(): void
    {
        $mariam = self::avec(['ROLE_COMMANDE', 'ROLE_CLIENT']);
        $aminata = self::avec(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_COMMANDE', 'ROLE_CLIENT']);
        $seydou = self::avec(['ROLE_COMMANDE', 'ROLE_CLIENT', 'ROLE_PREPRESSE']);
        $livreur = self::avec(['ROLE_LIVRAISON']);
        $direction = (new User())->setRoles(['ROLE_ADMIN']);

        $assignee = ConversationClient::nouvelle(new Clients(), null, null, null, ConversationClient::SUJET_COMMANDE, new \DateTimeImmutable())->setAssigneA($mariam);
        self::assertTrue(MessagerieVoter::peutVoir($mariam, $assignee));
        self::assertFalse(MessagerieVoter::peutVoir($aminata, $assignee), 'la conversation d\'un collègue');
        self::assertTrue(MessagerieVoter::peutVoir($direction, $assignee));
        (new \ReflectionProperty(User::class, 'id'))->setValue($seydou, 900006);
        $assignee->ajouterParticipant($seydou);
        self::assertTrue(MessagerieVoter::peutVoir($seydou, $assignee), 'ajouté par un collègue');

        $libre = ConversationClient::nouvelle(new Clients(), null, null, null, ConversationClient::SUJET_AUTRE, new \DateTimeImmutable());
        self::assertTrue(MessagerieVoter::peutVoir($aminata, $libre), 'non assignée de son rôle');
        self::assertFalse(MessagerieVoter::peutVoir($seydou, $libre), 'la PAO ne prend pas les non assignées');
        self::assertFalse(MessagerieVoter::peutVoir($livreur, $libre));

        $paiement = ConversationClient::nouvelle(new Clients(), null, null, null, ConversationClient::SUJET_PAIEMENT, new \DateTimeImmutable());
        self::assertTrue(MessagerieVoter::peutVoir($aminata, $paiement));
        self::assertFalse(MessagerieVoter::peutVoir($mariam, $paiement), 'paiement : la caisse ou la trésorière');
    }

    /** Un employé avec ces droits (ceux que son profil lui donne dans l'application). */
    private static function avec(array $droits): User
    {
        return new class($droits) extends User {
            /** @param list<string> $droits */
            public function __construct(private readonly array $droits)
            {
                parent::__construct();
            }

            public function getRoles(): array
            {
                return [...$this->droits, 'ROLE_USER'];
            }
        };
    }

    public function testSignatureDeLAgent(): void
    {
        $agent = new AgentMessagerie();
        self::assertTrue($agent->mettreAJour('  Mariam   Touré ', 'Commerciale', null, new \DateTimeImmutable()));
        self::assertSame('MT', $agent->getInitiales());
        self::assertFalse($agent->mettreAJour('Mariam Touré', 'Commerciale', null, new \DateTimeImmutable()), 'rien de neuf : pas de synchronisation');

        $m = MessageClient::nouveau(new Clients(), MessageClient::ATELIER, 'Bonjour', 'Mariam', null, new \DateTimeImmutable())->setAgent($agent);
        $s = $m->getSignature();
        self::assertSame('Mariam Touré', $s['nom']);
        self::assertSame('Commerciale', $s['metier']);
        self::assertNull($s['photo'], 'sans photo : les initiales');
    }
}
