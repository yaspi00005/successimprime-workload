<?php

namespace App\Service\Sauvegarde;

/**
 * Archive .tar.gz écrite et lue en flux, sans extension zip ni mémoire :
 * les fichiers des clients (uploads) peuvent peser plusieurs Go.
 *
 * Format ustar (chemins jusqu'à 255 caractères), avec l'entête GNU
 * « LongLink » au-delà : l'archive s'ouvre aussi avec tar, 7-Zip ou WinRAR.
 */
final class ArchiveTar
{
    private const BLOC = 512;

    /** @var resource */
    private $flux;

    private int $nbFichiers = 0;
    private int $octets = 0;

    private function __construct(private readonly string $chemin)
    {
        $flux = gzopen($chemin, 'wb6');
        if ($flux === false) {
            throw new \RuntimeException('Impossible de créer '.$chemin);
        }
        $this->flux = $flux;
    }

    public static function creer(string $chemin): self
    {
        return new self($chemin);
    }

    /**
     * Ajoute un dossier entier ; $nomDansArchive est le chemin relatif au
     * projet (ex. « public/uploads »), repris tel quel à la restauration.
     */
    public function ajouterDossier(string $dossier, string $nomDansArchive): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $fichiers = [];
        foreach ($iterateur as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && !$info->isLink()) {
                $fichiers[] = $info->getPathname();
            }
        }
        sort($fichiers);
        $longueur = \strlen(rtrim($dossier, '/\\'));
        foreach ($fichiers as $chemin) {
            $relatif = str_replace('\\', '/', substr($chemin, $longueur + 1));
            $this->ajouterFichier($chemin, rtrim($nomDansArchive, '/').'/'.$relatif);
        }
    }

    public function ajouterFichier(string $chemin, string $nom): void
    {
        $taille = filesize($chemin);
        $source = @fopen($chemin, 'rb');
        if ($taille === false || $source === false) {
            return; // fichier supprimé entre-temps : on continue
        }
        [$prefixe, $court] = self::decouper($nom);
        if ($court === null) {
            $this->ecrireEntete('././@LongLink', \strlen($nom) + 1, 'L', 0);
            $this->ecrireDonnees($nom."\0");
            [$prefixe, $court] = ['', substr($nom, 0, 100)];
        }
        $this->ecrireEntete($court, $taille, '0', (int) filemtime($chemin), $prefixe);
        $ecrit = 0;
        while (!feof($source) && $ecrit < $taille) {
            $morceau = fread($source, min(1 << 20, $taille - $ecrit));
            if ($morceau === false || $morceau === '') {
                break;
            }
            gzwrite($this->flux, $morceau);
            $ecrit += \strlen($morceau);
        }
        fclose($source);
        if ($ecrit < $taille) {
            gzwrite($this->flux, str_repeat("\0", $taille - $ecrit)); // fichier raccourci pendant la lecture
        }
        $this->bourrage($taille);
        ++$this->nbFichiers;
        $this->octets += $taille;
    }

    /** @return array{fichiers: int, octets: int} */
    public function fermer(): array
    {
        gzwrite($this->flux, str_repeat("\0", self::BLOC * 2));
        gzclose($this->flux);

        return ['fichiers' => $this->nbFichiers, 'octets' => $this->octets];
    }

    /**
     * Parcourt l'archive et vérifie chaque entête : c'est le contrôle
     * d'intégrité fait juste après la sauvegarde.
     *
     * @param (callable(string, resource, int): void)|null $surFichier reçoit le nom, le flux gz et la taille ; doit lire exactement la taille
     *
     * @return array{fichiers: int, octets: int}
     */
    public static function parcourir(string $chemin, ?callable $surFichier = null): array
    {
        $flux = gzopen($chemin, 'rb');
        if ($flux === false) {
            throw new \RuntimeException('Archive illisible : '.$chemin);
        }
        $nb = 0;
        $octets = 0;
        $nomLong = null;
        try {
            while (true) {
                $entete = self::lire($flux, self::BLOC);
                if (\strlen($entete) < self::BLOC) {
                    throw new \RuntimeException('Archive tronquée');
                }
                if (trim($entete, "\0") === '') {
                    break; // fin d'archive
                }
                $attendu = octdec(trim(substr($entete, 148, 8), " \0"));
                $calcule = array_sum(array_map('ord', str_split(substr_replace($entete, '        ', 148, 8))));
                if ($attendu !== $calcule) {
                    throw new \RuntimeException('Entête corrompu après '.$nb.' fichiers');
                }
                $nom = rtrim(substr($entete, 0, 100), "\0");
                $prefixe = rtrim(substr($entete, 345, 155), "\0");
                if ($prefixe !== '') {
                    $nom = $prefixe.'/'.$nom;
                }
                $taille = (int) octdec(trim(substr($entete, 124, 12), " \0"));
                $type = $entete[156];

                if ($type === 'L') {
                    $nomLong = rtrim(self::lire($flux, $taille), "\0");
                    self::lire($flux, self::reste($taille));
                    continue;
                }
                if ($nomLong !== null) {
                    $nom = $nomLong;
                    $nomLong = null;
                }
                if ($type === '0' || $type === "\0") {
                    if ($surFichier !== null) {
                        $surFichier($nom, $flux, $taille);
                    } else {
                        self::sauter($flux, $taille);
                    }
                    ++$nb;
                    $octets += $taille;
                } else {
                    self::sauter($flux, $taille);
                }
                self::lire($flux, self::reste($taille));
            }
        } finally {
            gzclose($flux);
        }

        return ['fichiers' => $nb, 'octets' => $octets];
    }

    /**
     * Restaure l'archive sous $racine. Seuls les chemins commençant par un
     * des $dossiersPermis sont écrits ; « .. » et chemins absolus refusés.
     *
     * @param list<string> $dossiersPermis
     *
     * @return array{fichiers: int, octets: int, ignores: int}
     */
    public static function extraire(string $chemin, string $racine, array $dossiersPermis): array
    {
        $ignores = 0;
        $resultat = self::parcourir($chemin, static function (string $nom, $flux, int $taille) use ($racine, $dossiersPermis, &$ignores): void {
            if (!self::cheminPermis($nom, $dossiersPermis)) {
                ++$ignores;
                self::sauter($flux, $taille);

                return;
            }
            $cible = rtrim($racine, '/').'/'.$nom;
            if (!is_dir(\dirname($cible)) && !@mkdir(\dirname($cible), 0775, true) && !is_dir(\dirname($cible))) {
                throw new \RuntimeException('Impossible de créer '.\dirname($cible));
            }
            $sortie = fopen($cible, 'wb');
            if ($sortie === false) {
                throw new \RuntimeException('Impossible d\'écrire '.$cible);
            }
            $reste = $taille;
            while ($reste > 0) {
                $morceau = self::lire($flux, min(1 << 20, $reste));
                if ($morceau === '') {
                    throw new \RuntimeException('Archive tronquée');
                }
                fwrite($sortie, $morceau);
                $reste -= \strlen($morceau);
            }
            fclose($sortie);
        });

        return $resultat + ['ignores' => $ignores];
    }

    /** @param list<string> $dossiersPermis */
    public static function cheminPermis(string $nom, array $dossiersPermis): bool
    {
        if ($nom === '' || $nom[0] === '/' || str_contains($nom, '\\') || preg_match('#(^|/)\.\.(/|$)#', $nom) || str_contains($nom, ':')) {
            return false;
        }
        foreach ($dossiersPermis as $dossier) {
            if (str_starts_with($nom, rtrim($dossier, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chemin ustar : jusqu'à 100 caractères dans « nom », le début (155 au
     * plus) dans « préfixe ». [préfixe, nom], ou nom null si impossible.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function decouper(string $nom): array
    {
        if (\strlen($nom) <= 100) {
            return ['', $nom];
        }
        for ($i = strpos($nom, '/'); $i !== false; $i = strpos($nom, '/', $i + 1)) {
            if ($i <= 155 && \strlen($nom) - $i - 1 <= 100) {
                return [substr($nom, 0, $i), substr($nom, $i + 1)];
            }
        }

        return ['', null];
    }

    private function ecrireEntete(string $nom, int $taille, string $type, int $mtime, string $prefixe = ''): void
    {
        $entete = str_pad(substr($nom, 0, 100), 100, "\0")
            .str_pad('0000644', 7, '0', \STR_PAD_LEFT)."\0"
            .'0000000'."\0"
            .'0000000'."\0"
            .str_pad(decoct($taille), 11, '0', \STR_PAD_LEFT)."\0"
            .str_pad(decoct(max(0, $mtime)), 11, '0', \STR_PAD_LEFT)."\0"
            .'        '
            .$type
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8)
            .str_pad($prefixe, 155, "\0")
            .str_repeat("\0", 12);
        $somme = array_sum(array_map('ord', str_split($entete)));
        $entete = substr_replace($entete, str_pad(decoct($somme), 6, '0', \STR_PAD_LEFT)."\0 ", 148, 8);
        gzwrite($this->flux, $entete);
    }

    private function ecrireDonnees(string $donnees): void
    {
        gzwrite($this->flux, $donnees);
        $this->bourrage(\strlen($donnees));
    }

    private function bourrage(int $taille): void
    {
        $reste = self::reste($taille);
        if ($reste > 0) {
            gzwrite($this->flux, str_repeat("\0", $reste));
        }
    }

    private static function reste(int $taille): int
    {
        return (self::BLOC - $taille % self::BLOC) % self::BLOC;
    }

    /** @param resource $flux */
    private static function lire($flux, int $n): string
    {
        $donnees = '';
        while (\strlen($donnees) < $n && !gzeof($flux)) {
            $morceau = gzread($flux, $n - \strlen($donnees));
            if ($morceau === false || $morceau === '') {
                break;
            }
            $donnees .= $morceau;
        }

        return $donnees;
    }

    /** @param resource $flux */
    private static function sauter($flux, int $n): void
    {
        while ($n > 0) {
            $lu = \strlen(self::lire($flux, min(1 << 20, $n)));
            if ($lu === 0) {
                throw new \RuntimeException('Archive tronquée');
            }
            $n -= $lu;
        }
    }
}
