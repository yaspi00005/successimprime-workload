<?php

namespace App\Service\Site;

use App\Entity\CompteClient;
use App\Entity\DemandeDevis;
use App\Entity\ProduitConfiguration;
use App\Service\EspaceClient\NouvelleCommande;

/**
 * Formulaires sans compte du site (maquette 11) : contrôle de la saisie,
 * préremplissage depuis le calculateur et protections contre les robots
 * (champ piège, temps de saisie minimum signé). Tout est statique et pur
 * pour être testé sans base.
 */
final class FormulaireDemande
{
    /** Un humain met plus que ça à remplir le formulaire. */
    public const DELAI_MIN_SECONDES = 4;

    /** Au-delà, la page est trop ancienne : on la fait revalider. */
    public const DELAI_MAX_SECONDES = 12 * 3600;

    /** Champ caché aux humains : un robot le remplit. */
    public const PIEGE = 'site_web';

    public const QUANTITE_MAX = 100000;

    /** Jeton « heure d'affichage » signé, posé dans le formulaire. */
    public static function jetonDelai(int $maintenant, string $secret): string
    {
        return $maintenant.'.'.self::signature($maintenant, $secret);
    }

    /** Message à montrer, ou null si le formulaire a été rempli à vitesse humaine. */
    public static function verifierDelai(?string $jeton, int $maintenant, string $secret): ?string
    {
        if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', (string) $jeton, $m) || !hash_equals(self::signature((int) $m[1], $secret), $m[2])) {
            return 'La page a expiré : vérifiez votre demande puis envoyez-la à nouveau.';
        }
        $ecoule = $maintenant - (int) $m[1];
        if ($ecoule < self::DELAI_MIN_SECONDES) {
            return 'Envoi trop rapide : vérifiez votre demande puis envoyez-la à nouveau.';
        }

        return $ecoule > self::DELAI_MAX_SECONDES ? 'La page a expiré : vérifiez votre demande puis envoyez-la à nouveau.' : null;
    }

    /** Le champ piège est rempli : c'est un robot. */
    public static function estPiege(array $saisie): bool
    {
        return '' !== trim((string) ($saisie[self::PIEGE] ?? ''));
    }

    /**
     * Contrôle la saisie. Les valeurs gardées sont propres ; les erreurs
     * sont par champ, en français, à montrer sous chaque champ.
     *
     * @param array<string, mixed>       $saisie
     * @param list<ProduitConfiguration> $produits catalogue publié (NouvelleCommande::produits())
     *
     * @return array{valeurs: array{nom: string, telephone: ?string, email: ?string, objet: ?string, produit: ?ProduitConfiguration, largeur: ?float, hauteur: ?float, quantite: ?int, finitions: list<int>, message: ?string}, erreurs: array<string, string>}
     */
    public static function valider(string $nature, array $saisie, array $produits): array
    {
        $erreurs = [];
        $texte = static fn (string $cle): string => trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) ($saisie[$cle] ?? '')));

        $nom = (string) preg_replace('/\s+/u', ' ', $texte('nom'));
        if (mb_strlen($nom) < 2) {
            $erreurs['nom'] = 'Indiquez votre nom.';
        } elseif (mb_strlen($nom) > 120) {
            $erreurs['nom'] = '120 caractères au plus.';
        }

        $telephone = CompteClient::normaliserTelephone($texte('telephone'));
        if (null === $telephone) {
            $erreurs['telephone'] = 'Indiquez un numéro malien à 8 chiffres.';
        }

        $email = $texte('email');
        if ('' !== $email && (mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL))) {
            $erreurs['email'] = 'Cette adresse e-mail n\'est pas valide.';
        }

        $message = $texte('message');
        if (mb_strlen($message) > DemandeDevis::MESSAGE_MAX) {
            $erreurs['message'] = \sprintf('%d caractères au plus.', DemandeDevis::MESSAGE_MAX);
        }

        $valeurs = [
            'nom' => $nom, 'telephone' => $telephone, 'email' => '' === $email ? null : $email, 'objet' => null,
            'produit' => null, 'largeur' => null, 'hauteur' => null, 'quantite' => null, 'finitions' => [],
            'message' => '' === $message ? null : $message,
        ];

        if (DemandeDevis::NATURE_CONTACT === $nature) {
            $objet = $texte('objet');
            $valeurs['objet'] = isset(DemandeDevis::OBJETS[$objet]) ? $objet : 'autre';
            if (mb_strlen($message) < 5 && !isset($erreurs['message'])) {
                $erreurs['message'] = 'Écrivez votre message.';
            }

            return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
        }

        $id = $texte('produit');
        $produit = '' === $id ? null : self::produit($produits, $id);
        if ('' !== $id && null === $produit) {
            $erreurs['produit'] = 'Choisissez un produit de la liste.';
        }
        $valeurs['produit'] = $produit;

        $quantite = $texte('quantite');
        if ('' === $quantite) {
            $valeurs['quantite'] = null !== $produit ? max(1, $produit->getQuantiteMinimale()) : null;
        } elseif (!ctype_digit($quantite) || (int) $quantite < 1 || (int) $quantite > self::QUANTITE_MAX) {
            $erreurs['quantite'] = 'Quantité entre 1 et 100 000.';
        } elseif (null !== $produit && (int) $quantite < max(1, $produit->getQuantiteMinimale())) {
            $erreurs['quantite'] = \sprintf('Minimum pour ce produit : %d.', max(1, $produit->getQuantiteMinimale()));
        } else {
            $valeurs['quantite'] = (int) $quantite;
        }

        if (null !== $produit) {
            // La taille n'est demandée que pour un produit à la mesure (bâche, vinyle...).
            foreach (NouvelleCommande::demandeTaille($produit) ? ['largeur', 'hauteur'] : [] as $cote) {
                $cm = self::centimetres($texte($cote));
                if (null === $cm) {
                    $erreurs[$cote] = 'En cm, entre 1 et 5 000 (une estimation suffit).';
                }
                $valeurs[$cote] = $cm;
            }
            $valeurs['finitions'] = self::finitions($produit, $saisie['finitions'] ?? []);
        }

        if (null === $produit && '' === $id && mb_strlen($message) < 5 && !isset($erreurs['message'])) {
            $erreurs['message'] = 'Choisissez un produit ou décrivez votre projet.';
        }

        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }

    /**
     * Valeurs envoyées par le calculateur (?produit=&largeur=&hauteur=&quantite=&finitions=1,2),
     * vérifiées : une valeur fausse est simplement ignorée.
     *
     * @param array<string, mixed>       $requete
     * @param list<ProduitConfiguration> $produits
     *
     * @return array{produit: string, largeur: string, hauteur: string, quantite: string, finitions: list<int>}
     */
    public static function preremplir(array $requete, array $produits): array
    {
        $produit = self::produit($produits, trim((string) (\is_scalar($requete['produit'] ?? null) ? $requete['produit'] : '')));
        $lire = static fn (string $cle): string => \is_scalar($requete[$cle] ?? null) ? trim((string) $requete[$cle]) : '';
        $cm = static fn (string $cle): string => null !== ($v = self::centimetres($lire($cle))) ? self::nombre($v) : '';
        $quantite = $lire('quantite');
        $finitions = $requete['finitions'] ?? [];
        if (\is_string($finitions)) {
            $finitions = explode(',', $finitions);
        }

        return [
            'produit' => null !== $produit ? (string) $produit->getId() : '',
            'largeur' => null !== $produit && NouvelleCommande::demandeTaille($produit) ? $cm('largeur') : '',
            'hauteur' => null !== $produit && NouvelleCommande::demandeTaille($produit) ? $cm('hauteur') : '',
            'quantite' => ctype_digit($quantite) && (int) $quantite >= 1 && (int) $quantite <= self::QUANTITE_MAX ? (string) (int) $quantite : '',
            'finitions' => null !== $produit && \is_array($finitions) ? self::finitions($produit, $finitions) : [],
        ];
    }

    /**
     * Finitions proposées pour ce produit, parmi celles cochées
     * (identifiants de ProduitConfigurationFinition). Les autres sont ignorées.
     *
     * @return list<int>
     */
    public static function finitions(ProduitConfiguration $produit, mixed $cochees): array
    {
        $cochees = \is_array($cochees) ? array_map(static fn ($v): int => \is_scalar($v) && ctype_digit(trim((string) $v)) ? (int) $v : 0, $cochees) : [];
        $retenues = [];
        foreach ($produit->getConfigurationFinitions() as $cf) {
            if ($cf->isActive() && null !== $cf->getId() && \in_array($cf->getId(), $cochees, true)) {
                $retenues[] = $cf->getId();
            }
        }

        return $retenues;
    }

    /** Côté en cm (virgule acceptée), entre 1 et 5 000 ; null sinon. */
    public static function centimetres(string $saisi): ?float
    {
        $saisi = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim($saisi));
        if (!is_numeric($saisi)) {
            return null;
        }
        $cm = round((float) $saisi, 1);

        return $cm >= 1 && $cm <= NouvelleCommande::COTE_MAX_CM ? $cm : null;
    }

    /** @param list<ProduitConfiguration> $produits */
    private static function produit(array $produits, string $id): ?ProduitConfiguration
    {
        if (!ctype_digit($id)) {
            return null;
        }
        foreach ($produits as $p) {
            if ($p->getId() === (int) $id) {
                return $p;
            }
        }

        return null;
    }

    private static function nombre(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : str_replace('.', ',', (string) $n);
    }

    private static function signature(int $t, string $secret): string
    {
        return substr(hash_hmac('sha256', 'site-demande|'.$t, $secret), 0, 32);
    }
}
