<?php

namespace App\Tests\Unit\Service\Recette;

use App\Service\Recette\BilanRecette;
use App\Service\Recette\DroitsRoute;
use App\Service\Recette\RapportRecette;
use App\Service\Recette\RecetteAcces;
use App\Service\Recette\RecetteEspaceClient;
use App\Service\Recette\RecetteVisiteur;
use App\Service\Recette\Reponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

/** Règles de la recette des accès qui ne touchent ni la base ni le noyau. */
final class RecetteOutilsTest extends TestCase
{
    public function testCasesDeLaMatrice(): void
    {
        self::assertSame(Reponse::OUVERT, Reponse::classer(200));
        self::assertSame(Reponse::REFUSE, Reponse::classer(403));
        self::assertSame(Reponse::REFUSE, Reponse::classer(401));
        self::assertSame(Reponse::INTROUVABLE, Reponse::classer(404));
        self::assertSame(Reponse::INTROUVABLE, Reponse::classer(405));
        self::assertSame(Reponse::ERREUR, Reponse::classer(500));
        self::assertSame(Reponse::CONNEXION, Reponse::classer(302, 'http://localhost/login'));
        self::assertSame(Reponse::CONNEXION, Reponse::classer(302, '/espace/connexion'));
        self::assertSame(Reponse::CONNEXION, Reponse::classer(302, '/verification'));
        self::assertSame(Reponse::REDIRIGE, Reponse::classer(302, '/commandes/12'));
        self::assertSame(Reponse::REDIRIGE, Reponse::classer(302, '/login-aide'), 'seulement la page de connexion elle-même');
    }

    public function testDroitsEcritsDansLeCode(): void
    {
        $code = <<<'PHP'
            $this->denyAccessUnlessGranted('ROLE_RH');
            if ($x) {
                $this->denyAccessUnlessGranted('ROLE_ADMIN');
            }
            $this->denyAccessUnlessGranted(User::ROLE_ADMIN);
            $this->denyAccessUnlessGranted('ROLE_CLIENT', $client);
            PHP;

        // Un appel seul sur sa ligne, sans sujet (dans un bloc « if » aussi : la page est ensuite ouverte pour de vrai).
        self::assertSame(['ROLE_RH', 'ROLE_ADMIN'], DroitsRoute::refusDansTexte($code));
    }

    public function testOngletDuMenuLePlusPrecis(): void
    {
        self::assertSame('Planning des livraisons', DroitsRoute::ongletDe('app_livraisons_planning')['libelle'] ?? null);
        self::assertSame('Livraisons', DroitsRoute::ongletDe('app_livraisons_index')['libelle'] ?? null);
        self::assertNull(DroitsRoute::ongletDe('app_route_inconnue'));
    }

    public function testPagesDeLAdmin(): void
    {
        self::assertTrue(RecetteAcces::estPageAdmin('app_commandes_index', new Route('/commandes')));
        self::assertFalse(RecetteAcces::estPageAdmin('app_commandes_new', new Route('/commandes/new', methods: ['POST'])), 'envoi');
        self::assertFalse(RecetteAcces::estPageAdmin('app_espace_accueil', new Route('/espace')), 'espace client');
        self::assertFalse(RecetteAcces::estPageAdmin('app_site_accueil', new Route('/site/produits')), 'site public');
        self::assertFalse(RecetteAcces::estPageAdmin('app_factures_pdf_public', new Route('/factures/{id}/pdf-public/{token}')), 'lien signé');
        self::assertFalse(RecetteAcces::estPageAdmin('_profiler', new Route('/_profiler')));
    }

    public function testProductionDepuisLePosteDUneMachine(): void
    {
        self::assertTrue(RecetteAcces::depuisUnPoste('app_production_index'));
        self::assertFalse(RecetteAcces::depuisUnPoste('app_productivite_index'));
        self::assertFalse(RecetteAcces::depuisUnPoste('app_commandes_index'));
    }

    public function testEcartsVoulus(): void
    {
        $liste = ['app_planning_' => 'tout le personnel', 'app_user_change_password' => 'son mot de passe'];

        self::assertSame('tout le personnel', RecetteAcces::raison($liste, 'app_planning_index'));
        self::assertSame('son mot de passe', RecetteAcces::raison($liste, 'app_user_change_password'));
        self::assertNull(RecetteAcces::raison($liste, 'app_user_change_password_autre'), 'nom exact sans « _ » final');
        self::assertNull(RecetteAcces::raison($liste, 'app_commandes_index'));
    }

    public function testMontantsQuiNeSontPasDesPaiements(): void
    {
        self::assertNotNull(RecetteAcces::contexteVoulu('CMD-D00751 50,5 Mo Solde disponible 1 728 points = 1 728 F'));
        self::assertNotNull(RecetteAcces::contexteVoulu('Acompte demandé50 % · 0 F'));
        self::assertNotNull(RecetteAcces::contexteVoulu('Salon Espoir — En préparation 2 travaux Impayée 5 000 FCFA'));
        self::assertNotNull(RecetteAcces::contexteVoulu('Montant TTC Paiement CMD-D00753 10/10/2026 11:22 45 000 FCFA'));
        self::assertNull(RecetteAcces::contexteVoulu('Total payé 407 500 F'));
        // Une ligne de paiement (date, commande, mode) reste à examiner.
        self::assertNull(RecetteAcces::contexteVoulu('10/10/2026 11:27 CMD-D00753 Chèque Banque BDM — 45 000 FCFA'));
    }

    public function testDonneesDeLEspaceSansArgent(): void
    {
        $donnees = [
            'personne' => ['nom' => 'Awa'],
            'commandes' => [['numero' => 'CMD-1', 'etat' => 'prete', 'resteAPayer' => 0], ['numero' => 'CMD-2', 'montantTtc' => null]],
            'factures' => [],
            'devis' => [['numero' => 'DV-1', 'totalTtc' => 31000]],
        ];

        // Une valeur vide ne compte pas ; 0 est un montant.
        self::assertSame(['commandes.0.resteAPayer', 'devis.0.totalTtc'], RecetteEspaceClient::clesArgent($donnees));
    }

    public function testMontantsVoulusDeLEspace(): void
    {
        self::assertNotNull(RecetteEspaceClient::montantVoulu('Kakémonos du hall × 2dernière fois 26/09 54 000 F'));
        self::assertNotNull(RecetteEspaceClient::montantVoulu('Finition Prête à retirer Total indicatif 3 000 F'));
        self::assertNull(RecetteEspaceClient::montantVoulu('Reste à payer 3 000 F'));
        self::assertNull(RecetteEspaceClient::montantVoulu('Paiement reçu Wave · commande CMD-D00654 · 37 500 F'));
        self::assertTrue(RecetteEspaceClient::montantDeDevis('Nouveau devis DV-D00557 · Kakémono 80 × 180 cm · 31 000 F'));
        self::assertFalse(RecetteEspaceClient::montantDeDevis('Nouvelle facture F-D00498 · commande CMD-D00654 · 75 000 F'));
    }

    public function testReponseDuSuiviSansCeQuiAEteTape(): void
    {
        self::assertSame(
            RecetteVisiteur::sansSaisie('Commande CMD-9 introuvable pour 60111111', ['CMD-9', '60111111']),
            RecetteVisiteur::sansSaisie('Commande CMD-1 introuvable pour 60222222', ['CMD-1', '60222222']),
        );
    }

    public function testBilanEtRapport(): void
    {
        $bilan = new BilanRecette();
        $bilan->comptes = ['demo.oumar' => ['libelle' => 'Opérateur', 'droits' => ['ROLE_PRODUCTION_VOIR']], 'demo.awa' => ['libelle' => 'Entretien', 'droits' => []]];
        $bilan->pages = ['/types/impression' => ['route' => 'app_type_impression_index', 'onglet' => 'Grille tarifaire']];
        $bilan->matrice = ['/types/impression' => ['demo.oumar' => Reponse::OUVERT, 'demo.awa' => Reponse::REFUSE]];
        $bilan->constater(BilanRecette::FUITE, 'sans_droit', 'app_type_impression_index', '/types/impression', 'demo.oumar', 'ouverte sans ROLE_ADMIN | réglages');
        $bilan->controler('Mode « Voir »', true, '0 arrivé au contrôleur');

        self::assertFalse($bilan->sansFuiteNiErreur());
        self::assertSame(1, $bilan->compterConstats()[BilanRecette::FUITE]);
        self::assertSame(1, $bilan->resumeParCompte()['demo.oumar'][Reponse::OUVERT]);
        self::assertSame(1, $bilan->resumeParCompte()['demo.awa'][Reponse::REFUSE]);

        $csv = RapportRecette::csv($bilan);
        self::assertSame("route;adresse;onglet;demo.oumar;demo.awa\napp_type_impression_index;/types/impression;\"Grille tarifaire\";O;R\n", $csv);

        $md = RapportRecette::markdown($bilan, 'local', new \DateTimeImmutable('2026-10-14 20:00'));
        self::assertStringContainsString('# Recette des accès (ERP local, 14/10/2026 20:00)', $md);
        self::assertStringContainsString('| demo.oumar | `/types/impression` | app_type_impression_index | ouverte sans ROLE_ADMIN / réglages |', $md, 'la barre ne casse pas le tableau');
        self::assertStringContainsString('- OK Mode « Voir » : 0 arrivé au contrôleur', $md);
    }
}
