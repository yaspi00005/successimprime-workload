<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Service\EspaceClient\CoqueEspace;
use App\Service\PastillesMenu;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/** Menu de gauche de l'espace client selon le type de compte (maquette EC01). */
final class CoqueEspaceTest extends TestCase
{
    public function testMenuSelonLesDroits(): void
    {
        $entreprise = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setTypeClient('B2C');
        $tous = ['tableau', 'notifications', 'nouvelle', 'conception', 'creations', 'produits', 'devis', 'commandes', 'fichiers', 'factures', 'livraisons', 'messages', 'promotions', 'fidelite', 'compte'];

        self::assertSame($tous, CoqueEspace::entreesPermises(self::compte($entreprise, [CompteClient::DROIT_ADMINISTRATEUR])));
        self::assertSame($tous, CoqueEspace::entreesPermises(self::compte($entreprise, [CompteClient::DROIT_PAYEUR])));
        // Ni factures ni fidélité pour qui ne paie pas.
        self::assertSame(array_values(array_diff($tous, ['factures', 'fidelite'])), CoqueEspace::entreesPermises(self::compte($entreprise, [CompteClient::DROIT_VALIDEUR])));
        self::assertSame(array_values(array_diff($tous, ['factures', 'fidelite'])), CoqueEspace::entreesPermises(self::compte($entreprise, [])));
        // Lecture · suivi : rien qui commande, accepte, paie ou écrit.
        self::assertSame(['tableau', 'notifications', 'conception', 'commandes', 'fichiers', 'livraisons', 'promotions', 'compte'], CoqueEspace::entreesPermises(self::compte($entreprise, [CompteClient::DROIT_LECTURE])));
    }

    public function testFideliteHorsGrilleRevendeur(): void
    {
        $revendeur = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setTypeClient('B2B');
        $particulier = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER)->setTypeClient('B2C');

        self::assertNotContains('fidelite', CoqueEspace::entreesPermises(self::compte($revendeur, [CompteClient::DROIT_ADMINISTRATEUR])));
        self::assertNotContains('promotions', CoqueEspace::entreesPermises(self::compte($revendeur, [CompteClient::DROIT_ADMINISTRATEUR])));
        self::assertContains('factures', CoqueEspace::entreesPermises(self::compte($revendeur, [CompteClient::DROIT_ADMINISTRATEUR])));
        // Le particulier fait tout (un seul compte).
        self::assertContains('fidelite', CoqueEspace::entreesPermises(self::compte($particulier, [])));
        self::assertFalse(CoqueEspace::droits(self::compte($revendeur, [CompteClient::DROIT_PAYEUR]))['fidelite']);
    }

    public function testEntreeAlumeeParLaRoute(): void
    {
        self::assertSame(['cle' => 'commandes', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_commande'));
        self::assertSame(['cle' => 'commandes', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_avis_commande'), 'le plus long préfixe l\'emporte');
        self::assertSame(['cle' => 'compte', 'onglet' => 'compte'], CoqueEspace::entreeDeLaRoute('app_espace_avis'));
        self::assertSame(['cle' => 'nouvelle', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_nouvelle_commande'));
        self::assertSame(['cle' => 'fichiers', 'onglet' => 'fichiers'], CoqueEspace::entreeDeLaRoute('app_espace_bat'));
        self::assertSame(['cle' => 'conception', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_conception_credit'));
        self::assertSame(['cle' => 'creations', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_atelier'));
        self::assertSame(['cle' => 'creations', 'onglet' => 'commandes'], CoqueEspace::entreeDeLaRoute('app_espace_creation_fichier'));
        self::assertSame(['cle' => 'factures', 'onglet' => 'factures'], CoqueEspace::entreeDeLaRoute('app_espace_payer_retour'));
        self::assertSame(['cle' => 'compte', 'onglet' => 'compte'], CoqueEspace::entreeDeLaRoute('app_espace_mes_donnees'));
        self::assertNull(CoqueEspace::entreeDeLaRoute('app_espace_connexion'));
    }

    public function testMenuFacileAEtendre(): void
    {
        $cles = array_column(CoqueEspace::MENU, 'cle');
        self::assertSame(\count($cles), \count(array_unique($cles)));
        foreach (CoqueEspace::MENU as $e) {
            self::assertSame(['cle', 'route', 'libelle', 'icone', 'pages', 'visible', 'onglet'], array_keys($e), $e['cle']);
            self::assertStringStartsWith('app_espace_', $e['route']);
        }
    }

    /**
     * La coque (personne, droits, cloche) et les pastilles du menu sont
     * gardées le temps d'une requête : vidées avant la suivante, sinon un
     * processus qui sert plusieurs personnes montrerait celles de la
     * première aux autres (recette des accès, serveur qui garde PHP en mémoire).
     */
    public function testVideesEntreDeuxRequetes(): void
    {
        foreach ([CoqueEspace::class => 'memo', PastillesMenu::class => 'compteurs'] as $classe => $propriete) {
            $service = (new \ReflectionClass($classe))->newInstanceWithoutConstructor();
            self::assertInstanceOf(ResetInterface::class, $service, $classe);
            $memo = new \ReflectionProperty($classe, $propriete);
            $memo->setValue($service, ['payeur' => true]);
            $service->reset();
            self::assertNull($memo->getValue($service), $classe);
        }
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }
}
