<?php

namespace App\Service\Site;

use App\Service\Parametres\Parametres;

/**
 * Page Contact du site (maquette 11) : horaires de la boutique (tuile
 * Horaires des Paramètres), ouvert ou fermé en ce moment, lien du plan et
 * repère de la carte (tuile « Site : page Contact », par défaut la position
 * de la boutique sur sa fiche Google).
 */
final class ContactSite
{
    private const JOURS = ['lun-ven' => [1, 5], 'lun-sam' => [1, 6], 'lun-dim' => [1, 7]];
    private const NOMS_JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /**
     * @return array{texte: string, ouvert: bool, etat: string}
     */
    public function horaires(\DateTimeImmutable $maintenant): array
    {
        $jours = (string) $this->parametres->valeur('horaires', 'jours');
        $ouverture = (string) $this->parametres->valeur('horaires', 'ouverture');
        $fermeture = (string) $this->parametres->valeur('horaires', 'fermeture');

        return ['texte' => self::texteHoraires($jours, $ouverture, $fermeture)] + self::etat($jours, $ouverture, $fermeture, $maintenant);
    }

    /** Adresse à ouvrir pour l'itinéraire, ou null tant que rien n'est réglé. */
    public function lienPlan(): ?string
    {
        return self::lien((string) $this->parametres->valeur('site_contact', 'lien_plan'));
    }

    /**
     * Lien « Itinéraire » toujours disponible (bandeau « Venez nous voir »
     * de l'accueil) : celui du réglage (position de la boutique), sinon une
     * recherche Google Maps sur le nom et l'adresse de la boutique.
     */
    public function itineraire(string $nom, ?string $adresse): string
    {
        return self::itineraireDe((string) $this->parametres->valeur('site_contact', 'lien_plan'), $nom, $adresse);
    }

    /** Le lien du réglage tel que la page Contact le retient (lien()), sinon la recherche sur l'adresse. */
    public static function itineraireDe(string $saisi, string $nom, ?string $adresse): string
    {
        return self::lien($saisi) ?? self::recherche($nom, $adresse);
    }

    /** Recherche Google Maps « Succès Imprim Bamako, Sotuba ACI » (Bamako ajouté si l'adresse ne le dit pas). */
    public static function recherche(string $nom, ?string $adresse): string
    {
        $adresse = trim((string) preg_replace('/\s+/u', ' ', (string) $adresse));
        if (!str_contains(mb_strtolower($adresse), 'bamako')) {
            $adresse = ('' === $adresse ? '' : $adresse.', ').'Bamako, Mali';
        }

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim($nom.' '.$adresse));
    }

    /**
     * Repère de la boutique pour la carte de la page Contact (coordonnées
     * saisies ou lues dans un lien long, DonneesStructurees::position),
     * seulement s'il y a aussi un lien d'itinéraire ; sinon null (plan
     * dessiné seul).
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function position(): ?array
    {
        return self::repere((string) $this->parametres->valeur('site_contact', 'lien_plan'));
    }

    /** @return array{latitude: float, longitude: float}|null */
    public static function repere(string $saisi): ?array
    {
        $position = null === self::lien($saisi) ? null : DonneesStructurees::position($saisi);

        return null === $position ? null : ['latitude' => $position['latitude'], 'longitude' => $position['longitude']];
    }

    /** « Lun – sam : 8 h – 18 h 30 ». */
    public static function texteHoraires(string $jours, string $ouverture, string $fermeture): string
    {
        $plage = match ($jours) {
            'lun-ven' => 'Lun – ven',
            'lun-dim' => 'Tous les jours',
            default => 'Lun – sam',
        };

        return $plage.' : '.self::heure($ouverture).' – '.self::heure($fermeture);
    }

    /**
     * Ouvert en ce moment, sinon prochaine ouverture (jours fériés de
     * l'atelier non comptés : la boutique peut ouvrir).
     *
     * @return array{ouvert: bool, etat: string}
     */
    public static function etat(string $jours, string $ouverture, string $fermeture, \DateTimeImmutable $maintenant): array
    {
        [$premier, $dernier] = self::JOURS[$jours] ?? self::JOURS['lun-sam'];
        $ouvre = static fn (\DateTimeImmutable $j): bool => (int) $j->format('N') >= $premier && (int) $j->format('N') <= $dernier;
        $a = static fn (\DateTimeImmutable $j, string $h): \DateTimeImmutable => $j->setTime(...array_map('intval', explode(':', $h.':0')) + [1 => 0]);

        if ($ouvre($maintenant) && $maintenant >= $a($maintenant, $ouverture) && $maintenant < $a($maintenant, $fermeture)) {
            return ['ouvert' => true, 'etat' => 'Ouvert maintenant, jusqu\'à '.self::heure($fermeture)];
        }
        for ($i = 0; $i <= 7; ++$i) {
            $jour = $maintenant->modify('+'.$i.' day');
            if ($ouvre($jour) && $a($jour, $ouverture) > $maintenant) {
                $quand = match ($i) {
                    0 => 'aujourd\'hui',
                    1 => 'demain',
                    default => self::NOMS_JOURS[(int) $jour->format('N')],
                };

                return ['ouvert' => false, 'etat' => 'Fermé, ouvre '.$quand.' à '.self::heure($ouverture)];
            }
        }

        return ['ouvert' => false, 'etat' => 'Fermé'];
    }

    /**
     * Lien de partage Google Maps (https) tel quel ; coordonnées
     * « 12.6392, -8.0029 » changées en lien d'itinéraire ; sinon null.
     */
    public static function lien(string $saisi): ?string
    {
        $saisi = trim($saisi);
        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)$/', $saisi, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.$m[1].','.$m[2];
        }
        if (str_starts_with($saisi, 'https://') && false !== filter_var($saisi, \FILTER_VALIDATE_URL) && !preg_match('/[\s"<>]/', $saisi)) {
            return $saisi;
        }

        return null;
    }

    /** « 8 h », « 18 h 30 », avec des espaces insécables (jamais « 8 » en fin de ligne). */
    public static function heure(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => '0']);

        return $h."\u{a0}h".($m > 0 ? "\u{a0}".str_pad((string) $m, 2, '0', \STR_PAD_LEFT) : '');
    }
}
