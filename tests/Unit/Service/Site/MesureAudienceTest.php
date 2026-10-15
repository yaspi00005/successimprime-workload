<?php

namespace App\Tests\Unit\Service\Site;

use App\Controller\SiteReferencementController;
use App\Entity\ReglageSite;
use App\Entity\User;
use App\EventSubscriber\ReferencementSubscriber;
use App\Service\Site\MesureAudience;
use App\Service\Site\ReferencementSite;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mesure d'audience Google (docs/referencement.md) : seulement sur les
 * pages publiques du site en ligne, jamais une page privée ou à code.
 */
final class MesureAudienceTest extends TestCase
{
    public function testPagesMesurees(): void
    {
        foreach (['app_home', 'app_site_produits', 'app_site_faq', 'app_site_conseil', 'app_site_contact', 'app_site_devis', 'app_site_devis_envoyee', 'app_site_atelier'] as $route) {
            self::assertTrue(MesureAudience::pageMesuree(true, $route, false), $route);
            // Jamais sur l'ERP local, jamais pour le personnel connecté.
            self::assertFalse(MesureAudience::pageMesuree(false, $route, false), $route);
            self::assertFalse(MesureAudience::pageMesuree(true, $route, true), $route);
        }
    }

    public function testJamaisDansLEspaceClientNiSurUnePageACode(): void
    {
        $privees = [
            // Espace client, connexions, codes.
            'app_espace_accueil', 'app_espace_connexion', 'app_espace_code', 'app_espace_suivre', 'app_espace_premiere_connexion',
            'app_espace_creer_compte', 'app_espace_bat', 'app_espace_payer', 'app_espace_payer_retour', 'app_espace_conception_retour',
            'app_espace_fichier_morceau', 'app_login', 'app_verification', 'app_appareil_autoriser',
            // Adresses qui portent un code ou un jeton secret.
            'app_facture_suivi', 'app_avis_commande', 'app_avis_sondage', 'app_devis_pdf_public', 'app_factures_pdf_public',
            'app_suivi_etiquette_public', 'app_orange_money_notification', 'app_tv_ecran', 'app_tv_ecran_donnees',
            // Suivi de commande (numéro et téléphone), réponses en JSON, aperçu de l'ERP, robots.
            'app_site_suivi', 'app_site_calculateur_estimation', 'app_site_atelier_prix', 'app_site_accueil', 'app_site_robots', 'app_site_plan',
            'app_assistant_public_message', '',
        ];
        foreach ($privees as $route) {
            self::assertFalse(MesureAudience::pageMesuree(true, $route, false), $route);
        }
    }

    public function testPagesMesureesFaitesPourEtreTrouvees(): void
    {
        foreach (MesureAudience::PAGES as $route) {
            self::assertTrue(
                \in_array($route, ReferencementSubscriber::INDEXABLES, true) || \in_array($route, ['app_site_devis_envoyee', 'app_site_contact_envoye'], true),
                $route,
            );
            self::assertStringNotContainsString('espace', $route);
        }
        // Les adresses fermées aux robots ne sont pas mesurées (les préfixes de PAGES restent publics).
        self::assertContains('/f/', SiteReferencementController::INTERDITS);
    }

    public function testIdentifiantSelonLeReglage(): void
    {
        $faq = Request::create('https://succesimprim.com/faq');
        $faq->attributes->set('_route', 'app_site_faq');

        self::assertSame('G-634VEQPP69', $this->mesure(true, null)->identifiant($faq), 'Jamais réglée : celle de la boutique.');
        self::assertSame('G-AUTRE1234', $this->mesure(true, ['mesure' => 'G-AUTRE1234'])->identifiant($faq));
        self::assertNull($this->mesure(true, ['mesure' => ''])->identifiant($faq), 'Vidée : coupée.');
        self::assertNull($this->mesure(false, null)->identifiant($faq), 'ERP local.');
        self::assertNull($this->mesure(true, null, new User())->identifiant($faq), 'Personnel connecté.');
        self::assertNull($this->mesure(true, null)->identifiant(null));

        $espace = Request::create('https://succesimprim.com/espace');
        $espace->attributes->set('_route', 'app_espace_accueil');
        self::assertNull($this->mesure(true, null)->identifiant($espace));
    }

    /** @param array<string, string>|null $valeurs */
    private function mesure(bool $enLigne, ?array $valeurs, ?User $utilisateur = null): MesureAudience
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findOneBy')->willReturn(null === $valeurs ? null : (new ReglageSite(ReferencementSite::CLE_REGLAGE))->setValeurs($valeurs));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($utilisateur);

        return new MesureAudience($enLigne, new ReferencementSite($em), $security);
    }
}
