<?php

namespace App\Tests\Unit\Entity;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\User;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Livraisons\SuiviLivraisons;
use PHPUnit\Framework\TestCase;

/**
 * Échec d'une livraison et statut lisible du suivi (à livrer, en tournée,
 * livrée, échec) : quand, par qui, reçue par qui.
 */
final class BonLivraisonEchecTest extends TestCase
{
    private function bon(string $statut): BonLivraison
    {
        $b = new BonLivraison();
        $this->poser($b, 'statut', $statut);

        return $b;
    }

    private function poser(BonLivraison $b, string $champ, mixed $valeur): void
    {
        (new \ReflectionProperty(BonLivraison::class, $champ))->setValue($b, $valeur);
    }

    public function testEtatsDuSuivi(): void
    {
        $b = $this->bon(BonLivraison::STATUT_BROUILLON);
        self::assertSame(BonLivraison::ETAT_A_LIVRER, $b->etatSuivi());
        self::assertNull($b->momentSuivi());

        $b = $this->bon(BonLivraison::STATUT_VALIDE);
        self::assertSame(BonLivraison::ETAT_A_LIVRER, $b->etatSuivi());
        $charge = new \DateTimeImmutable('2026-10-16 09:10');
        $b->avancer('charge', $charge);
        self::assertSame(BonLivraison::ETAT_EN_TOURNEE, $b->etatSuivi());
        self::assertSame($charge, $b->momentSuivi());
        $route = $charge->modify('+20 minutes');
        $b->avancer('en_route', $route);
        self::assertSame($route, $b->momentSuivi());

        self::assertSame(BonLivraison::ETAT_ANNULEE, $this->bon(BonLivraison::STATUT_ANNULE)->etatSuivi());
        foreach ([BonLivraison::ETAT_A_LIVRER, BonLivraison::ETAT_EN_TOURNEE, BonLivraison::ETAT_LIVREE, BonLivraison::ETAT_ECHEC, BonLivraison::ETAT_ANNULEE] as $etat) {
            self::assertArrayHasKey($etat, BonLivraison::ETATS_SUIVI);
        }
    }

    public function testEchecNoteQuandQuiPourquoi(): void
    {
        $livreur = new User();
        $prevu = new User();
        $b = $this->bon(BonLivraison::STATUT_VALIDE)->setLivreur($prevu);
        $b->avancer('arrive', new \DateTimeImmutable('2026-10-16 10:00'));
        $le = new \DateTimeImmutable('2026-10-16 10:12');

        $b->echouer($livreur, "  Client absent :\n  portail fermé ", $le);

        self::assertSame(BonLivraison::ETAT_ECHEC, $b->etatSuivi());
        self::assertTrue($b->isEchecEnCours());
        self::assertSame($le, $b->getEchecLe());
        self::assertSame($livreur, $b->getEchecPar());
        self::assertSame('Client absent : portail fermé', $b->getEchecMotif());
        self::assertSame($le, $b->momentSuivi());
        self::assertSame($livreur, $b->livreurSuivi());
        self::assertNull($b->receptionnaireSuivi());
        // Le colis revient : le prochain passage refera les étapes.
        self::assertSame([null, null, null], [$b->getChargeLe(), $b->getEnRouteLe(), $b->getArriveLe()]);

        // Une étape franchie : nouveau passage.
        $b->avancer('charge', $le->modify('+3 hours'));
        self::assertSame(BonLivraison::ETAT_EN_TOURNEE, $b->etatSuivi());
        $b->echouer($livreur, 'Client absent', $le);

        // Replanifiée ou réessayée : de nouveau à livrer, le dernier échec reste en historique.
        $b->reprendre();
        self::assertSame(BonLivraison::ETAT_A_LIVRER, $b->etatSuivi());
        self::assertSame($le, $b->getEchecLe());
        self::assertSame($prevu, $b->livreurSuivi());
    }

    public function testEchecDemandeUneRaisonEtUneLivraisonEnCours(): void
    {
        try {
            $this->bon(BonLivraison::STATUT_VALIDE)->echouer(new User(), '   ', new \DateTimeImmutable());
            self::fail('Une raison vide est refusée.');
        } catch (\InvalidArgumentException) {
        }
        foreach ([BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_LIVRE, BonLivraison::STATUT_ANNULE] as $statut) {
            try {
                $this->bon($statut)->echouer(new User(), 'Client absent', new \DateTimeImmutable());
                self::fail($statut.' : pas d\'échec possible.');
            } catch (\LogicException) {
            }
        }
        $b = $this->bon(BonLivraison::STATUT_VALIDE);
        $b->echouer(new User(), str_repeat('x', 400), new \DateTimeImmutable());
        self::assertSame(255, mb_strlen((string) $b->getEchecMotif()));
    }

    public function testLivreeApresUnEchec(): void
    {
        $premier = new User();
        $second = new User();
        $b = $this->bon(BonLivraison::STATUT_VALIDE)->setLivreur($second);
        $b->echouer($premier, 'Client injoignable', new \DateTimeImmutable('2026-10-15 16:00'));
        // Remise le lendemain par un collègue (marquerLivre efface « en cours »).
        $livre = new \DateTimeImmutable('2026-10-16 11:30');
        $this->poser($b, 'statut', BonLivraison::STATUT_LIVRE);
        $this->poser($b, 'echecEnCours', false);
        $this->poser($b, 'livreLe', $livre);
        $this->poser($b, 'livrePar', $second);
        $this->poser($b, 'signataire', 'Awa Traoré');
        $b->setNomReceptionnaire('Mme Traoré');

        self::assertSame(BonLivraison::ETAT_LIVREE, $b->etatSuivi());
        self::assertSame($livre, $b->momentSuivi());
        self::assertSame($second, $b->livreurSuivi());
        // Le nom donné à la signature d'abord.
        self::assertSame('Awa Traoré', $b->receptionnaireSuivi());

        // Mes livraisons : la remise pour celui qui a livré, l'échec pour le premier.
        $vuesSecond = SuiviLivraisons::vuesDuLivreur([$b], $second);
        self::assertSame([BonLivraison::ETAT_LIVREE], array_column($vuesSecond, 'etat'));
        $vuesPremier = SuiviLivraisons::vuesDuLivreur([$b], $premier);
        self::assertSame([BonLivraison::ETAT_ECHEC], array_column($vuesPremier, 'etat'));
        self::assertSame([], SuiviLivraisons::vuesDuLivreur([$b], new User()));
    }

    public function testVuesDuLivreurDeLaPlusRecente(): void
    {
        $moi = new User();
        $ancien = $this->bon(BonLivraison::STATUT_VALIDE);
        $ancien->echouer($moi, 'Adresse introuvable', new \DateTimeImmutable('2026-10-14 15:00'));
        $recent = $this->bon(BonLivraison::STATUT_LIVRE);
        $this->poser($recent, 'livreLe', new \DateTimeImmutable('2026-10-16 09:00'));
        $this->poser($recent, 'livrePar', $moi);

        $vues = SuiviLivraisons::vuesDuLivreur([$ancien, $recent], $moi);
        self::assertSame([$recent, $ancien], array_column($vues, 'bon'));
    }

    /** Espace client : « reportée » avec la date, ni raison ni livreur, et rien de plus envoyé. */
    public function testEspaceClientVoitLaLivraisonReportee(): void
    {
        $commande = (new Commandes())->setNumero('CMD-2026-0042');
        $b = $this->bon(BonLivraison::STATUT_VALIDE);
        $this->poser($b, 'valideLe', new \DateTimeImmutable('2026-10-15 08:00'));
        $b->setPrevuLe(new \DateTimeImmutable('2026-10-16'));
        $prevue = EspaceClient::evenementLivraison($b, $commande);
        self::assertNotNull($prevue);

        $b->avancer('en_route', new \DateTimeImmutable('2026-10-16 10:00'));
        self::assertSame('route', EspaceClient::evenementLivraison($b, $commande)['type'] ?? null);

        $b->echouer(new User(), 'Paiement pas prêt', new \DateTimeImmutable('2026-10-16 10:30'));
        $reportee = EspaceClient::evenementLivraison($b, $commande);
        self::assertNotNull($reportee);
        self::assertSame('Livraison reportée', $reportee['titre']);
        self::assertStringNotContainsString('Paiement', $reportee['texte']);
        // Même type et même date que « prévue » : même clé, pas de nouvel envoi.
        self::assertSame([$prevue['type'], $prevue['date']], [$reportee['type'], $reportee['date']]);
        self::assertNull($reportee['jour']);
    }
}
