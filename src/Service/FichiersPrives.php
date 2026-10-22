<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers envoyés qui ne sont pas publics (audit de sécurité du
 * 15/10/2026, D3, docs/securite.md « Fichiers envoyés ») : chat,
 * pré-presse, étiquettes, justificatifs de remboursement, photos du
 * personnel, logos et cachet des entreprises.
 *
 * Ils étaient sous public/uploads/<dossier> : quiconque connaissait
 * l'adresse les ouvrait sans compte. Ils sont maintenant sous
 * var/uploads/<dossier> (comme les fichiers des clients) et ne sortent
 * que par un contrôleur qui vérifie les droits (FichiersPrivesVoter).
 *
 * Les chemins enregistrés en base (« uploads/prepresse/commande_3/… »,
 * « uploads/etiquettes/LOT-…/… ») ne changent pas : ils se lisent
 * désormais sous var/ au lieu de public/. Tant que la commande
 * app:fichiers:deplacer n'est pas passée sur une machine, un fichier
 * encore à l'ancien endroit est trouvé quand même (chemin()).
 */
final class FichiersPrives
{
    /** Dossiers de public/uploads devenus privés : même nom sous var/uploads. */
    public const DOSSIERS = ['chat', 'prepresse', 'etiquettes', 'reclamations', 'employes', 'entreprises', 'fichiers'];

    /**
     * Dossiers qui restent publics par nature : images du site vitrine
     * (logos des partenaires compris) et médias de l'écran TV, faits pour
     * être vus de tous.
     */
    public const PUBLICS = ['site', 'tv'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** var/uploads/<dossier>, créé au besoin. */
    public function dossier(string $dossier): string
    {
        self::verifierDossier($dossier);
        $chemin = $this->projet.'/var/uploads/'.$dossier;
        if (!is_dir($chemin) && !@mkdir($chemin, 0775, true) && !is_dir($chemin)) {
            throw new \RuntimeException(\sprintf('Dossier var/uploads/%s impossible à créer.', $dossier));
        }

        return $chemin;
    }

    /**
     * Où écrire un fichier dont le chemin relatif est enregistré en base
     * (« uploads/etiquettes/LOT-…/ETQ-000001.png ») : sous var/.
     */
    public function cible(string $relatif): string
    {
        $relatif = self::normaliser($relatif) ?? throw new \InvalidArgumentException('Chemin de fichier privé refusé.');

        return $this->projet.'/var/'.$relatif;
    }

    /**
     * Le fichier sur le disque, ou null : sous var/ d'abord, puis à
     * l'ancien endroit sous public/ (machine pas encore passée par
     * app:fichiers:deplacer). Jamais en dehors du dossier du type.
     */
    public function chemin(?string $relatif): ?string
    {
        $relatif = self::normaliser((string) $relatif);
        if (null === $relatif) {
            return null;
        }
        $dossier = explode('/', $relatif)[1];
        foreach ([$this->projet.'/var', $this->projet.'/public'] as $racine) {
            $base = realpath($racine.'/uploads/'.$dossier);
            $reel = realpath($racine.'/'.$relatif);
            if (false !== $base && false !== $reel && str_starts_with($reel, $base.\DIRECTORY_SEPARATOR) && is_file($reel)) {
                return $reel;
            }
        }

        return null;
    }

    /** Fichier rangé à plat (chat, justificatifs, photos du personnel) : seulement son nom en base. */
    public function cheminDans(string $dossier, ?string $nom): ?string
    {
        $nom = trim((string) $nom);

        return '' === $nom || basename($nom) !== $nom ? null : $this->chemin('uploads/'.$dossier.'/'.$nom);
    }

    /**
     * « uploads/<dossier privé>/… » propre, ou null (dossier public,
     * inconnu, « .. », chemin absolu).
     */
    public static function normaliser(string $relatif): ?string
    {
        $relatif = ltrim(str_replace('\\', '/', trim($relatif)), '/');
        if (str_contains($relatif, "\0") || !preg_match('#^uploads/([a-z]+)/(.+)$#', $relatif, $m) || !\in_array($m[1], self::DOSSIERS, true)) {
            return null;
        }
        foreach (explode('/', $m[2]) as $morceau) {
            if ('' === $morceau || '.' === $morceau || '..' === $morceau) {
                return null;
            }
        }

        return $relatif;
    }

    /**
     * Range sous var/uploads les fichiers restés sous public/uploads
     * (app:fichiers:deplacer). Sans $executer, compte seulement.
     *
     * Un fichier déjà présent à l'identique à la nouvelle place est
     * retiré de l'ancienne ; s'il diffère, les deux restent (conflit).
     * Les dossiers publics (site, tv) et les dossiers inconnus ne sont
     * pas touchés, seulement comptés. Relancer ne change rien.
     *
     * @return array{
     *     dossiers: array<string, array{fichiers: int, deplaces: int, identiques: int, conflits: int, erreurs: int, octets: int}>,
     *     publics: array<string, int>,
     *     inconnus: array<string, int>
     * }
     */
    public function deplacer(bool $executer): array
    {
        $source = $this->projet.'/public/uploads';
        $bilan = ['dossiers' => [], 'publics' => [], 'inconnus' => []];
        foreach (self::DOSSIERS as $dossier) {
            $bilan['dossiers'][$dossier] = $this->deplacerDossier($source.'/'.$dossier, $this->projet.'/var/uploads/'.$dossier, $executer);
        }
        foreach (is_dir($source) ? (scandir($source) ?: []) : [] as $nom) {
            if ('.' === $nom || '..' === $nom || '.htaccess' === $nom || \in_array($nom, self::DOSSIERS, true)) {
                continue;
            }
            $estDossier = is_dir($source.'/'.$nom);
            // Sans les fichiers cachés (.gitkeep du dossier de l'écran TV).
            $nombre = $estDossier ? \count(array_filter(self::fichiersSous($source.'/'.$nom), static fn (string $f): bool => !str_starts_with(basename($f), '.'))) : 1;
            if (\in_array($nom, self::PUBLICS, true)) {
                $bilan['publics'][$nom] = $nombre;
            } elseif ($nombre > 0) {
                $cle = $estDossier ? $nom : '(fichiers à la racine)';
                $bilan['inconnus'][$cle] = ($bilan['inconnus'][$cle] ?? 0) + $nombre;
            }
        }

        return $bilan;
    }

    /** Nombre de fichiers privés encore sous public/uploads (app:verifier-installation). */
    public function restantsSousPublic(): int
    {
        $n = 0;
        foreach (self::DOSSIERS as $dossier) {
            $n += \count(self::fichiersSous($this->projet.'/public/uploads/'.$dossier));
        }

        return $n;
    }

    /**
     * @return array{fichiers: int, deplaces: int, identiques: int, conflits: int, erreurs: int, octets: int}
     */
    private function deplacerDossier(string $source, string $cible, bool $executer): array
    {
        $compte = ['fichiers' => 0, 'deplaces' => 0, 'identiques' => 0, 'conflits' => 0, 'erreurs' => 0, 'octets' => 0];
        foreach (self::fichiersSous($source) as $relatif) {
            ++$compte['fichiers'];
            $depart = $source.'/'.$relatif;
            $arrivee = $cible.'/'.$relatif;
            if (is_link($depart)) {
                ++$compte['erreurs']; // un lien n'est jamais suivi
                continue;
            }
            if (file_exists($arrivee)) {
                if (is_file($arrivee) && filesize($arrivee) === filesize($depart) && hash_file('sha256', $arrivee) === hash_file('sha256', $depart)) {
                    ++$compte['identiques'];
                    if ($executer && !@unlink($depart)) {
                        ++$compte['erreurs'];
                    }
                } else {
                    ++$compte['conflits'];
                }
                continue;
            }
            $taille = (int) filesize($depart);
            if ($executer) {
                $dossier = \dirname($arrivee);
                if ((!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) || !@rename($depart, $arrivee)) {
                    ++$compte['erreurs'];
                    continue;
                }
            }
            ++$compte['deplaces'];
            $compte['octets'] += $taille;
        }
        if ($executer) {
            self::retirerDossiersVides($source);
        }

        return $compte;
    }

    /** @return list<string> chemins relatifs des fichiers (liens compris), du plus profond au moins profond */
    private static function fichiersSous(string $dossier): array
    {
        if (!is_dir($dossier) || is_link($dossier)) {
            return [];
        }
        $fichiers = [];
        $parcours = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($parcours as $element) {
            /** @var \SplFileInfo $element */
            if ($element->isLink() || $element->isFile()) {
                $fichiers[] = substr($element->getPathname(), \strlen($dossier) + 1);
            }
        }
        sort($fichiers);

        return $fichiers;
    }

    private static function retirerDossiersVides(string $dossier): void
    {
        if (!is_dir($dossier) || is_link($dossier)) {
            return;
        }
        $parcours = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($parcours as $element) {
            /** @var \SplFileInfo $element */
            if ($element->isDir() && !$element->isLink()) {
                @rmdir($element->getPathname()); // échoue sans bruit s'il reste quelque chose
            }
        }
        @rmdir($dossier);
    }

    private static function verifierDossier(string $dossier): void
    {
        if (!\in_array($dossier, self::DOSSIERS, true)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est pas un dossier de fichiers privés.', $dossier));
        }
    }
}
