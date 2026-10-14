<?php

namespace App\Tests\Unit\Security;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Security\DroitsEspace;
use PHPUnit\Framework\TestCase;

/** Compte « Lecture · suivi » : refusé côté serveur, quelle que soit la page. */
final class DroitsEspaceTest extends TestCase
{
    public function testLaLectureSeuleNEcritRien(): void
    {
        $lecture = self::compte([CompteClient::DROIT_LECTURE]);

        foreach (['app_espace_nouvelle_commande', 'app_espace_produits_commander', 'app_espace_bat', 'app_espace_devis_accepter', 'app_espace_payer_orange_money',
            'app_espace_messages', 'app_espace_compte_entreprise', 'app_espace_utilisateur_nouveau', 'app_espace_adresses', 'app_espace_fichier_envoi',
            'app_espace_reclamation_nouvelle', 'app_espace_commande_valider', 'app_espace_preferences'] as $route) {
            self::assertFalse(DroitsEspace::permis($lecture, 'POST', $route), $route);
        }
        // Ce qui ne regarde qu'elle : lu, réglages de notification, mot de passe, ses appareils.
        foreach (DroitsEspace::ENVOIS_LECTURE as $route) {
            self::assertTrue(DroitsEspace::permis($lecture, 'POST', $route), $route);
        }
    }

    public function testLaLectureSeuleNOuvrePasLesPagesQuiEcrivent(): void
    {
        $lecture = self::compte([CompteClient::DROIT_LECTURE]);

        foreach (DroitsEspace::PAGES_ECRITURE as $route) {
            self::assertFalse(DroitsEspace::permis($lecture, 'GET', $route), $route);
        }
        foreach (['app_espace_accueil', 'app_espace_commandes', 'app_espace_commande', 'app_espace_livraisons', 'app_espace_fichiers', 'app_espace_bat',
            'app_espace_notifications', 'app_espace_compte', 'app_espace_recherche', 'app_espace_mes_donnees'] as $route) {
            self::assertTrue(DroitsEspace::permis($lecture, 'GET', $route), $route);
        }
    }

    public function testLesAutresComptesNeSontPasBridesIci(): void
    {
        foreach ([[CompteClient::DROIT_ADMINISTRATEUR], [CompteClient::DROIT_VALIDEUR], [CompteClient::DROIT_PAYEUR], []] as $droits) {
            self::assertTrue(DroitsEspace::permis(self::compte($droits), 'POST', 'app_espace_messages'));
            self::assertTrue(DroitsEspace::permis(self::compte($droits), 'GET', 'app_espace_nouvelle_commande'));
        }
    }

    public function testDroitsParTypeDeCompte(): void
    {
        $lecture = self::compte([CompteClient::DROIT_LECTURE, CompteClient::DROIT_PAYEUR]);
        self::assertSame([CompteClient::DROIT_LECTURE], $lecture->getDroits(), 'lecture l\'emporte');
        self::assertTrue($lecture->estLectureSeule());
        self::assertFalse($lecture->peutEcrire());
        self::assertFalse($lecture->peut(CompteClient::DROIT_PAYEUR));
        self::assertFalse($lecture->estDemandeur());
        self::assertSame('Lecture · suivi', $lecture->getLibelleDroits());
        self::assertSame('lecture · suivi des commandes et livraisons', $lecture->getResumeDroits());

        $valideur = self::compte([CompteClient::DROIT_VALIDEUR]);
        self::assertTrue($valideur->peut(CompteClient::DROIT_VALIDEUR));
        self::assertFalse($valideur->peut(CompteClient::DROIT_PAYEUR));
        self::assertSame('commandes et devis, BAT', $valideur->getResumeDroits());

        $payeur = self::compte([CompteClient::DROIT_PAYEUR]);
        self::assertTrue($payeur->peut(CompteClient::DROIT_PAYEUR));
        self::assertTrue($payeur->estDemandeur());
        self::assertSame('demandes de commande, BAT, factures et paiements', $payeur->getResumeDroits());

        $admin = self::compte([CompteClient::DROIT_ADMINISTRATEUR]);
        self::assertTrue($admin->peut(CompteClient::DROIT_PAYEUR) && $admin->peut(CompteClient::DROIT_VALIDEUR));

        $demandeur = self::compte([]);
        self::assertTrue($demandeur->peutEcrire());
        self::assertTrue($demandeur->estDemandeur());
        self::assertSame('Demandeur', $demandeur->getLibelleDroits());

        // Un particulier n'a qu'un compte : il fait tout, sauf s'il est en lecture.
        $particulier = (new CompteClient())->setClient((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER))->setDroits([]);
        self::assertTrue($particulier->peut(CompteClient::DROIT_PAYEUR));
        // Grille revendeur : plusieurs comptes possibles, même sans type entreprise.
        $revendeur = (new CompteClient())->setClient((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER)->setTypeClient('B2B'))->setDroits([]);
        self::assertFalse($revendeur->peut(CompteClient::DROIT_PAYEUR));
    }

    /** @param list<string> $droits */
    private static function compte(array $droits): CompteClient
    {
        return (new CompteClient())->setClient((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE))->setDroits($droits);
    }
}
