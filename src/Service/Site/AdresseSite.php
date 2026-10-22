<?php

namespace App\Service\Site;

use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Twig\SiteExtension;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adresse publique du site vitrine (docs/referencement.md), pour tout ce
 * que lisent les moteurs de recherche et les réseaux sociaux : adresse
 * canonique des pages, plan du site, robots.txt, images de partage.
 *
 * Jamais l'adresse de l'ERP local ni une adresse technique de
 * l'hébergeur : d'abord le champ « Site » de l'entité Succès Imprim
 * (Réglages › Entités et en-têtes, qui descend au site), puis DEFAULT_URI
 * sur le serveur en ligne ou SYNC_URL sur l'ERP local, enfin l'adresse de
 * la requête. Toujours en https : c'est l'hébergeur qui y renvoie.
 */
final class AdresseSite
{
    private ?string $base = null;

    public function __construct(
        private readonly EntrepriseRepository $entreprises,
        private readonly AccesEnLigne $acces,
        private readonly RequestStack $requetes,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $adresseServeur = '',
        #[Autowire('%env(SYNC_URL)%')] private readonly string $adresseSynchro = '',
    ) {
    }

    /** « https://succesimprim.com », sans barre finale. */
    public function base(): string
    {
        if (null === $this->base) {
            $requete = $this->requetes->getMainRequest();
            $this->base = self::choisir(
                $this->entreprises->parCode(SiteExtension::CODE_ENTREPRISE)?->getSiteWeb(),
                $this->acces->estEnLigne(),
                $this->adresseServeur,
                $this->adresseSynchro,
                $requete?->getSchemeAndHttpHost(),
            );
        }

        return $this->base;
    }

    /** Adresse complète d'un chemin du site (« /faq », « images/x.png »). */
    public function url(string $chemin): string
    {
        // Un chemin déjà complet (image de l'asset mapper, lien absolu) garde seulement son chemin.
        if (preg_match('#^https?://#i', $chemin)) {
            $chemin = (string) parse_url($chemin, \PHP_URL_PATH);
        }

        return $this->base().'/'.ltrim($chemin, '/');
    }

    /**
     * Adresse retenue, dans l'ordre : champ « Site » de l'entité, adresse
     * du serveur en ligne (DEFAULT_URI), adresse du site connue de l'ERP
     * local (SYNC_URL), adresse de la requête, et à défaut le domaine prévu.
     */
    public static function choisir(?string $siteWeb, bool $enLigne, string $adresseServeur, string $adresseSynchro, ?string $requete): string
    {
        $candidats = [$siteWeb, $enLigne ? $adresseServeur : $adresseSynchro];
        foreach ($candidats as $candidat) {
            if (null !== $adresse = self::normaliser($candidat)) {
                return $adresse;
            }
        }

        return self::normaliser($requete, false) ?? 'https://succesimprim.com';
    }

    /**
     * « succesimprim.com », « https://www.succesimprim.com/ » ou
     * « http://succesimprim.com/accueil » donnent l'origine en https,
     * toujours sans « www » (« https://succesimprim.com »). Une adresse locale (localhost, IP,
     * nom sans point) ou illisible donne null, sauf si $publique est faux.
     */
    public static function normaliser(?string $saisie, bool $publique = true): ?string
    {
        $saisie = trim((string) $saisie);
        if ('' === $saisie || preg_match('/\s/', $saisie)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $saisie)) {
            $saisie = 'https://'.$saisie;
        }
        $parties = parse_url($saisie);
        $hote = strtolower((string) ($parties['host'] ?? ''));
        if (false === $parties || !\in_array(strtolower((string) ($parties['scheme'] ?? '')), ['http', 'https'], true) || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $hote)) {
            return null;
        }
        if (!$publique) {
            return strtolower((string) $parties['scheme']).'://'.$hote.(isset($parties['port']) ? ':'.$parties['port'] : '');
        }
        // Pas d'adresse d'essai : il faut un vrai domaine (au moins un point, pas une IP ni localhost).
        if (!str_contains($hote, '.') || filter_var($hote, \FILTER_VALIDATE_IP) || str_ends_with($hote, '.localhost') || str_ends_with($hote, '.local') || isset($parties['port'])) {
            return null;
        }

        // Domaine choisi sans « www » (décision du gérant, 15/10/2026) : www.succesimprim.com est redirigé
        // en 301 vers succesimprim.com (public/.htaccess). Canonique, plan du site et partages suivent.
        return 'https://'.(str_starts_with($hote, 'www.') && substr_count($hote, '.') >= 2 ? substr($hote, 4) : $hote);
    }
}
