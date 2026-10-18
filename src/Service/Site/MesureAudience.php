<?php

namespace App\Service\Site;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mesure d'audience Google Analytics (balise Google, gtag.js) demandée par
 * Yaya le 15/10/2026, docs/referencement.md et docs/securite.md.
 *
 * Seule exception à la règle « rien d'un autre site » : gtag.js vient de
 * www.googletagmanager.com, seulement
 *  - sur le site en ligne (APP_EN_LIGNE=1), jamais sur l'ERP local ;
 *  - sur les pages publiques du site vitrine de PAGES, pour un visiteur
 *    (le personnel connecté n'est pas compté) ;
 *  - quand l'identifiant de mesure est réglé (Réglages › Site vitrine ›
 *    Référencement ; vide : coupé).
 *
 * Jamais dans l'espace client, l'administration, les pages de connexion
 * ou de code, ni sur une adresse qui porte un code ou un jeton secret
 * (suivi de facture /f/…, sondages /avis/…, PDF publics, retours de
 * paiement, écran TV, tablette, activation d'un appareil…) : ces pages ne
 * sont pas dans PAGES. La même réponse décide de la balise dans la page
 * (site/_site.html.twig) et de la politique de contenu qui l'autorise
 * (EntetesSecuriteSubscriber) : l'une ne va jamais sans l'autre.
 *
 * Aucune donnée personnelle : ni identifiant d'utilisateur, ni client, ni
 * paramètre d'adresse autre qu'une campagne (public/js/mesure-audience.js).
 */
final class MesureAudience
{
    /**
     * Pages mesurées : celles du site vitrine faites pour être trouvées
     * (ReferencementSubscriber::INDEXABLES, sans robots.txt ni le plan du
     * site) et les confirmations d'envoi d'une demande (le numéro de la
     * demande est dans la page, jamais dans son adresse ni son titre).
     * « Suivre ma commande » n'y est pas : on y tape un numéro et un
     * téléphone.
     */
    public const PAGES = [
        'app_home', 'app_site_produits', 'app_site_calculateur', 'app_site_atelier', 'app_site_realisations',
        'app_site_equipements', 'app_site_guide', 'app_site_a_propos', 'app_site_engagements', 'app_site_faq',
        'app_site_conseils', 'app_site_conseil', 'app_site_contact', 'app_site_devis',
        'app_site_actions_sociales',
        'app_site_devis_envoyee', 'app_site_contact_envoye',
    ];

    /** Script de Google appelé par public/js/mesure-audience.js (SansCdnTest). */
    public const SCRIPT = 'https://www.googletagmanager.com/gtag/js';

    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        private readonly ReferencementSite $referencement,
        private readonly Security $security,
    ) {
    }

    /**
     * Identifiant de mesure à poser sur la page de cette requête (la
     * requête principale), ou null : pas de balise, politique sans Google.
     */
    public function identifiant(?Request $requete): ?string
    {
        if (null === $requete || !self::pageMesuree($this->enLigne, (string) $requete->attributes->get('_route', ''), $this->security->getUser() instanceof User)) {
            return null;
        }

        return $this->referencement->reglages()['mesure'] ?: null;
    }

    public static function pageMesuree(bool $enLigne, string $route, bool $personnelConnecte): bool
    {
        return $enLigne && !$personnelConnecte && \in_array($route, self::PAGES, true);
    }
}
