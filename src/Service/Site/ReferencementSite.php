<?php

namespace App\Service\Site;

use App\Entity\ReglageSite;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réglages de référencement du site vitrine (Réglages › Site vitrine ›
 * Référencement, docs/referencement.md) : codes de vérification de Google
 * Search Console et de Bing, identifiant de mesure Google Analytics (balise
 * Google, gtag.js), lien de la fiche Google (Google Business Profile) et
 * pages des réseaux sociaux.
 *
 * Rien de secret : les codes de vérification et l'identifiant de mesure
 * sont faits pour être lus dans la page. Rangés dans un ReglageSite, ils
 * descendent au site par la synchronisation (type site_reglage), comme la
 * règle des chiffres.
 */
final class ReferencementSite
{
    public const CLE_REGLAGE = 'referencement';

    /** Réseaux sociaux : clé => libellé et domaines acceptés. */
    public const RESEAUX = [
        'facebook' => ['libelle' => 'Facebook', 'domaines' => ['facebook.com', 'fb.com', 'fb.me']],
        'instagram' => ['libelle' => 'Instagram', 'domaines' => ['instagram.com']],
        'tiktok' => ['libelle' => 'TikTok', 'domaines' => ['tiktok.com']],
        'linkedin' => ['libelle' => 'LinkedIn', 'domaines' => ['linkedin.com']],
        'youtube' => ['libelle' => 'YouTube', 'domaines' => ['youtube.com', 'youtu.be']],
    ];

    /** Lien de la fiche Google : domaines de Google seulement. */
    public const DOMAINES_FICHE_GOOGLE = ['google.com', 'google.ml', 'goo.gl', 'g.page', 'g.co'];

    /** Fiche Google de la boutique : lien « Partager » envoyé par Yaya le 15/10/2026 (un lien saisi la remplace). */
    public const FICHE_GOOGLE = 'https://maps.app.goo.gl/S53cwaekd2L3FHP86';

    /**
     * Identifiant de mesure Google Analytics de la boutique, envoyé par Yaya
     * le 15/10/2026 avec la balise Google (gtag.js). Un identifiant saisi le
     * remplace ; un champ vidé coupe la mesure (MesureAudience).
     */
    public const MESURE_GOOGLE = 'G-634VEQPP69';

    public const DEFAUTS = ['google' => '', 'bing' => '', 'mesure' => self::MESURE_GOOGLE, 'fiche_google' => self::FICHE_GOOGLE, 'facebook' => '', 'instagram' => '', 'tiktok' => '', 'linkedin' => '', 'youtube' => ''];

    /** @var array<string, string>|null */
    private ?array $reglages = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<string, string> les valeurs retenues (vides si rien n'est réglé) */
    public function reglages(): array
    {
        if (null === $this->reglages) {
            $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);
            // Ce qui arrive par la synchronisation est relu comme une saisie.
            $this->reglages = self::lire($reglage?->getValeurs() ?? [])['valeurs'];
        }

        return $this->reglages;
    }

    /**
     * Pages des réseaux sociaux et fiche Google (« sameAs » des données
     * structurées, liens du pied de page).
     *
     * @return array<string, array{libelle: string, url: string}>
     */
    public function reseaux(): array
    {
        $liens = [];
        foreach (self::RESEAUX as $cle => $reseau) {
            if ('' !== $url = $this->reglages()[$cle]) {
                $liens[$cle] = ['libelle' => $reseau['libelle'], 'url' => $url];
            }
        }

        return $liens;
    }

    /**
     * Enregistre la saisie de l'onglet Référencement. Une valeur refusée
     * n'est pas enregistrée (l'ancienne reste) et son message revient.
     *
     * @param array<string, mixed> $donnees
     *
     * @return list<string> messages des valeurs refusées
     */
    public function enregistrer(array $donnees): array
    {
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);
        if (null === $reglage) {
            $reglage = new ReglageSite(self::CLE_REGLAGE);
            $this->em->persist($reglage);
        }
        $lu = self::lire($donnees);
        $anciennes = self::lire($reglage->getValeurs())['valeurs'];
        $valeurs = $lu['valeurs'];
        foreach (array_keys($lu['erreurs']) as $cle) {
            $valeurs[$cle] = $anciennes[$cle];
        }
        $reglage->setValeurs($valeurs);
        $this->em->flush();
        $this->reglages = $valeurs;

        return array_values($lu['erreurs']);
    }

    /**
     * Lit une saisie : chaque champ est nettoyé, ou refusé avec un message.
     *
     * @param array<string, mixed> $saisie
     *
     * @return array{valeurs: array<string, string>, erreurs: array<string, string>}
     */
    public static function lire(array $saisie): array
    {
        $valeurs = self::DEFAUTS;
        $erreurs = [];
        $texte = static fn (string $cle): string => trim(\is_scalar($saisie[$cle] ?? null) ? (string) $saisie[$cle] : '');

        foreach (['google' => 'Google Search Console', 'bing' => 'Bing'] as $cle => $nom) {
            if ('' === $texte($cle)) {
                continue;
            }
            $code = self::codeVerification($texte($cle), $cle);
            if (null === $code) {
                $erreurs[$cle] = 'Code '.$nom.' non reconnu : collez la balise donnée par '.$nom.' ou seulement le code entre les guillemets de content="…".';
            } else {
                $valeurs[$cle] = $code;
            }
        }

        // Mesure d'audience : absente (réglage enregistré avant elle), celle de la
        // boutique ; vidée exprès, coupée ; sinon l'identifiant saisi.
        if (\array_key_exists('mesure', $saisie)) {
            if ('' === $texte('mesure')) {
                $valeurs['mesure'] = '';
            } elseif (null === $mesure = self::identifiantMesure($texte('mesure'))) {
                $erreurs['mesure'] = 'Identifiant de mesure Google non reconnu : collez la balise Google (gtag.js) ou seulement l\'identifiant « G-… » ; laissez vide pour couper la mesure.';
            } else {
                $valeurs['mesure'] = $mesure;
            }
        }

        if ('' !== $texte('fiche_google')) {
            $lien = self::lien($texte('fiche_google'), self::DOMAINES_FICHE_GOOGLE);
            if (null === $lien) {
                $erreurs['fiche_google'] = 'Lien de la fiche Google non reconnu : copiez le lien « Partager » de la fiche (https://maps.app.goo.gl/… ou https://g.page/…).';
            } else {
                $valeurs['fiche_google'] = $lien;
            }
        }

        foreach (self::RESEAUX as $cle => $reseau) {
            if ('' === $texte($cle)) {
                continue;
            }
            $lien = self::lien($texte($cle), $reseau['domaines']);
            if (null === $lien) {
                $erreurs[$cle] = 'Lien '.$reseau['libelle'].' non reconnu : collez l\'adresse complète de la page (https://…'.$reseau['domaines'][0].'/…).';
            } else {
                $valeurs[$cle] = $lien;
            }
        }

        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }

    /** Ce qui manque à la fiche de l'imprimerie pour Google : fait => où le régler. */
    public const MANQUES = [
        'site' => 'Adresse du site (succesimprim.com) : Réglages › Entités et en-têtes › Succès Imprim, champ « Site ».',
        'adresse' => 'Adresse de la boutique : Réglages › Entités et en-têtes › Succès Imprim.',
        'telephone' => 'Téléphone : Réglages › Entités et en-têtes › Succès Imprim.',
        'email' => 'E-mail de contact public : Réglages › Entités et en-têtes › Succès Imprim (sinon, Google n\'en affiche pas).',
        'position' => 'Position GPS de la boutique : Réglages › Paramètres › « Site : page Contact », saisir « latitude, longitude » (un lien court maps.app.goo.gl ne la donne pas).',
        'plan' => 'Lien du plan (bouton Itinéraire de la page Contact) : Réglages › Paramètres › « Site : page Contact ».',
        'fiche_google' => 'Lien de la fiche Google (Google Business Profile) : ci-dessus.',
        'reseaux' => 'Pages Facebook, Instagram ou TikTok : ci-dessus.',
        'google' => 'Code de vérification Google Search Console : ci-dessus, puis déclarer le plan du site dans Search Console.',
    ];

    /**
     * Ce qui manque encore, dans l'ordre de MANQUES.
     *
     * @param array<string, bool> $faits clé de MANQUES => renseigné ou non
     *
     * @return list<string>
     */
    public static function manques(array $faits): array
    {
        $manques = [];
        foreach (self::MANQUES as $cle => $message) {
            if (!($faits[$cle] ?? false)) {
                $manques[] = $message;
            }
        }

        return $manques;
    }

    /**
     * Code de vérification d'un moteur de recherche, tapé seul ou dans la
     * balise copiée (<meta name="google-site-verification" content="…">).
     * Google : lettres, chiffres, « - » et « _ » ; Bing : 32 caractères
     * hexadécimaux.
     */
    public static function codeVerification(string $saisie, string $moteur): ?string
    {
        $saisie = trim($saisie);
        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $saisie, $m)) {
            $saisie = trim($m[1]);
        }

        return match ($moteur) {
            'google' => preg_match('/^[A-Za-z0-9_-]{20,100}$/', $saisie) ? $saisie : null,
            'bing' => preg_match('/^[A-Fa-f0-9]{32}$/', $saisie) ? strtoupper($saisie) : null,
            default => null,
        };
    }

    /**
     * Identifiant de mesure Google Analytics 4 (« G-XXXXXXXXXX »), tapé seul
     * ou trouvé dans la balise Google copiée depuis Analytics
     * (gtag/js?id=G-… ou gtag('config', 'G-…')). Rien d'autre de la balise
     * n'est gardé.
     */
    public static function identifiantMesure(string $saisie): ?string
    {
        $saisie = trim($saisie);
        if (preg_match('/(?:[?&]id=|config[\'"]?\s*,\s*[\'"])(G-[A-Za-z0-9]+)/', $saisie, $m)) {
            $saisie = $m[1];
        }
        $saisie = strtoupper($saisie);

        return preg_match('/^G-[A-Z0-9]{4,20}$/', $saisie) ? $saisie : null;
    }

    /**
     * Lien https vers un des domaines donnés (ou un de leurs
     * sous-domaines), sans espace ni guillemet ; sinon null.
     *
     * @param list<string> $domaines
     */
    public static function lien(string $saisie, array $domaines): ?string
    {
        $saisie = trim($saisie);
        if (!preg_match('#^https://#i', $saisie)) {
            $saisie = preg_match('#^[a-z0-9.-]+\.[a-z]{2,}/#i', $saisie) ? 'https://'.$saisie : $saisie;
        }
        if (\strlen($saisie) > 500 || preg_match('/[\s"\'<>\\\\]/', $saisie) || false === filter_var($saisie, \FILTER_VALIDATE_URL) || !str_starts_with(strtolower($saisie), 'https://')) {
            return null;
        }
        $hote = strtolower((string) parse_url($saisie, \PHP_URL_HOST));
        foreach ($domaines as $domaine) {
            if ($hote === $domaine || str_ends_with($hote, '.'.$domaine)) {
                return $saisie;
            }
        }

        return null;
    }
}
