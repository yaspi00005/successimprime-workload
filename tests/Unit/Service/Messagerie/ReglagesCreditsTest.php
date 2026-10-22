<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Entity\EnvoiMessage;
use App\Security\Droits;
use App\Security\ModulesRoles;
use App\Service\Messagerie\CreditsMessages;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Synchro\CatalogueSynchro;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Crédits SMS et e-mail : droit, menu, tuile de réglages, synchronisation. */
final class ReglagesCreditsTest extends TestCase
{
    public function testDroitAttribuableEtDonneALaDirection(): void
    {
        self::assertArrayHasKey('ROLE_CREDITS_MESSAGES', Droits::catalogue());
        self::assertSame(['ROLE_CREDITS_MESSAGES'], ModulesRoles::catalogue()['messagerie']['droits']);

        $securite = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/security.yaml');
        self::assertContains('ROLE_CREDITS_MESSAGES', $securite['security']['role_hierarchy']['ROLE_ADMIN']);
    }

    public function testOngletDansClients(): void
    {
        $onglets = array_column(NavigationExtension::ONGLETS['clients'], 'role', 'route');
        self::assertSame('ROLE_CREDITS_MESSAGES', $onglets['app_credits_messages_index']);
    }

    public function testTuileDansClientsEtCommunication(): void
    {
        $tuile = CatalogueParametres::tuiles()[CreditsMessages::TUILE];

        self::assertSame('clients', $tuile['famille']);
        self::assertSame(['ROLE_ADMIN', 'ROLE_CREDITS_MESSAGES'], $tuile['roles']);
        self::assertSame('app_credits_messages_index', $tuile['route']);

        $valeurs = CatalogueParametres::avecDefauts($tuile['champs'], []);
        self::assertSame(100, $valeurs['seuil_sms']);
        self::assertSame(15, $valeurs['alerte_jours']);

        // Solde saisi à la main et dates de fin : sur la page complète, pas dans le panneau rapide.
        $panneau = array_column(CatalogueParametres::champsPanneau(CreditsMessages::TUILE), 'cle');
        self::assertSame(['seuil_sms', 'alerte_jours', 'email_hebergeur', 'email_quota_heure', 'email_quota_jour', 'email_quota_mois'], $panneau);
    }

    public function testChampDate(): void
    {
        $champs = [['cle' => 'fin', 'type' => 'date', 'libelle' => 'Fin', 'defaut' => '']];

        self::assertSame('2026-11-30', CatalogueParametres::lireSaisie($champs, ['fin' => '2026-11-30'])['valeurs']['fin']);
        self::assertSame('2026-11-30', CatalogueParametres::lireSaisie($champs, ['fin' => '30/11/2026'])['valeurs']['fin']);
        self::assertSame('', CatalogueParametres::lireSaisie($champs, ['fin' => ''])['valeurs']['fin'], 'vide : pas de date');
        foreach (['31/02/2026', '2026-13-01', 'demain', '01/01/1999'] as $faux) {
            self::assertArrayHasKey('fin', CatalogueParametres::lireSaisie($champs, ['fin' => $faux])['erreurs'], $faux);
        }

        self::assertSame('30/11/2026', CatalogueParametres::afficher($champs[0], '2026-11-30'));
        self::assertSame('aucune', CatalogueParametres::afficher($champs[0], ''));
    }

    /**
     * Les envois du site montent au local, en lecture seule : la direction
     * voit tout l'historique sur l'ERP de l'imprimerie. Rien ne redescend.
     */
    public function testLesEnvoisDuSiteMontentAuLocal(): void
    {
        $type = CatalogueSynchro::types()['envoi_message'];

        self::assertSame(CatalogueSynchro::ASCENDANT, $type['sens']);
        self::assertSame('envoi_message', CatalogueSynchro::typeDe(new EnvoiMessage()));
        self::assertTrue(CatalogueSynchro::envoye('envoi_message', true));
        self::assertTrue(CatalogueSynchro::accepte('envoi_message', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('envoi_message', false));
        self::assertFalse(CatalogueSynchro::envoye('envoi_message', false), 'les envois du local ne vont pas au site');
        self::assertFalse(CatalogueSynchro::accepte('envoi_message', true));

        // Liste blanche : ni texte, ni code, ni lien.
        self::assertSame(['canal', 'type', 'sujet', 'destinataire', 'statut', 'erreur', 'unites', 'envoyeLe', 'origine'], $type['champs']);
        self::assertSame([], $type['relations']);
        self::assertSame(['origine' => EnvoiMessage::EN_LIGNE], $type['imposeAuLocal']);
        foreach ($type['champs'] as $champ) {
            self::assertDoesNotMatchRegularExpression('/texte|message|corps|code|lien|jeton|prix|montant/i', $champ);
        }
    }
}
