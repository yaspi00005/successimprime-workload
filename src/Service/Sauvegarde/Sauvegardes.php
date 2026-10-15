<?php

namespace App\Service\Sauvegarde;

use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Sauvegardes automatiques (jalon J4) : base + fichiers des clients et du
 * personnel, vérifiées, tournantes, et copiées sur un second disque.
 *
 * Chaque sauvegarde est un dossier « AAAAMMJJ-HHMMSS » sous le dossier des
 * sauvegardes (hors de public/, jamais téléchargeable depuis le site) :
 *   base.sql.gz      la base complète
 *   fichiers.tar.gz  les dossiers de DOSSIERS (une fois par jour)
 *   manifeste.json   tailles, empreintes sha256, version du schéma
 * Le dossier est écrit sous un nom provisoire puis renommé : une sauvegarde
 * interrompue n'apparaît jamais comme valable.
 */
final class Sauvegardes
{
    /** Dossiers de fichiers sauvegardés, relatifs au projet. */
    public const DOSSIERS = [
        'public/uploads',
        'var/uploads',
        'var/storage',
        'var/pointage',
        'var/espace-employe',
        'var/ecran-tv',
    ];

    /** Pas de sauvegarde réussie depuis ce délai : alerte au tableau de bord. */
    public const RETARD_HEURES = 26;

    private const JOURNAL = 'journal.json';

    public function __construct(
        private readonly DumpBase $dump,
        private readonly Parametres $parametres,
        private readonly Connection $connexion,
        private readonly string $projectDir,
        private readonly string $dossier,
        private readonly string $copie = '',
    ) {
    }

    /**
     * Fait une sauvegarde. Lève une exception en cas d'échec, après l'avoir
     * notée au journal.
     *
     * @return array<string, mixed> le manifeste
     */
    public function lancer(bool $avecFichiers, string $declencheur, ?string $par = null, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $this->preparerDossier($this->dossier);
        $verrou = fopen($this->dossier.'/.verrou', 'c');
        if ($verrou === false || !flock($verrou, \LOCK_EX | \LOCK_NB)) {
            throw new \RuntimeException('Une sauvegarde est déjà en cours.');
        }

        $nom = $maintenant->format('Ymd-His');
        $provisoire = $this->dossier.'/'.$nom.'.en-cours';
        $debut = microtime(true);
        try {
            if (is_dir($this->dossier.'/'.$nom)) {
                throw new \RuntimeException('Une sauvegarde porte déjà ce nom : '.$nom);
            }
            $this->supprimer($provisoire);
            mkdir($provisoire, 0700);

            $base = $this->dump->exporter($provisoire.'/base.sql.gz');
            $manifeste = [
                'nom' => $nom,
                'debut' => $maintenant->format(\DATE_ATOM),
                'declencheur' => $declencheur,
                'par' => $par,
                'schema' => $this->versionSchema(),
                'base' => [
                    'fichier' => 'base.sql.gz',
                    'octets' => $base['octets'],
                    'sha256' => hash_file('sha256', $provisoire.'/base.sql.gz'),
                    'methode' => $base['methode'],
                    'tables' => $base['tables'],
                ],
                'fichiers' => null,
                'message' => $base['message'],
            ];

            if ($avecFichiers) {
                $archive = ArchiveTar::creer($provisoire.'/fichiers.tar.gz');
                foreach (self::DOSSIERS as $relatif) {
                    $archive->ajouterDossier($this->projectDir.'/'.$relatif, $relatif);
                }
                $contenu = $archive->fermer();
                $verif = ArchiveTar::parcourir($provisoire.'/fichiers.tar.gz');
                if ($verif['fichiers'] !== $contenu['fichiers']) {
                    throw new \RuntimeException('Archive des fichiers incomplète à la relecture');
                }
                $manifeste['fichiers'] = [
                    'fichier' => 'fichiers.tar.gz',
                    'octets' => (int) filesize($provisoire.'/fichiers.tar.gz'),
                    'sha256' => hash_file('sha256', $provisoire.'/fichiers.tar.gz'),
                    'nombre' => $contenu['fichiers'],
                    'volume' => $contenu['octets'],
                ];
            }

            $manifeste['duree'] = round(microtime(true) - $debut, 1);
            $this->ecrireManifeste($provisoire, $manifeste);
            foreach (glob($provisoire.'/*') ?: [] as $f) {
                @chmod($f, 0600); // données des clients et du personnel : lisibles du seul compte du serveur
            }
            if (!rename($provisoire, $this->dossier.'/'.$nom)) {
                throw new \RuntimeException('Impossible de finaliser le dossier '.$nom);
            }

            $manifeste['copie'] = $this->copier($nom);
            $this->ecrireManifeste($this->dossier.'/'.$nom, $manifeste);
            $manifeste['supprimees'] = $this->tourner($this->dossier, $maintenant);
            if ($this->copie !== '' && $manifeste['copie'] === 'ok') {
                $this->tourner($this->copie, $maintenant);
            }

            $this->noter([
                'nom' => $nom, 'date' => $maintenant->format(\DATE_ATOM), 'statut' => 'ok',
                'declencheur' => $declencheur, 'par' => $par, 'avecFichiers' => $avecFichiers,
                'copie' => $manifeste['copie'], 'message' => $manifeste['message'],
            ]);

            return $manifeste;
        } catch (\Throwable $e) {
            $this->supprimer($provisoire);
            $this->noter([
                'nom' => $nom, 'date' => $maintenant->format(\DATE_ATOM), 'statut' => 'echec',
                'declencheur' => $declencheur, 'par' => $par, 'avecFichiers' => $avecFichiers,
                'message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            throw $e;
        } finally {
            flock($verrou, \LOCK_UN);
            fclose($verrou);
        }
    }

    /**
     * Pour la tâche planifiée lancée chaque heure : null = rien à faire,
     * true = sauvegarde complète (une fois par jour, après l'heure réglée),
     * false = base seule (toutes les N heures).
     */
    public static function aFaire(\DateTimeImmutable $maintenant, ?\DateTimeImmutable $derniereComplete, ?\DateTimeImmutable $derniereBase, string $heure, int $heuresBase): ?bool
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => '0']);
        $moment = $maintenant->setTime($h, $m);
        if ($maintenant < $moment) {
            $moment = $moment->modify('-1 day');
        }
        if ($derniereComplete === null || $derniereComplete < $moment) {
            return true;
        }
        // 10 minutes de marge : la tâche de 8 h ne doit pas sauter parce que celle de 2 h a fini à 2 h 01.
        $limite = $maintenant->modify('-'.max(1, $heuresBase).' hours')->modify('+10 minutes');
        if ($derniereBase === null || $derniereBase <= $limite) {
            return false;
        }

        return null;
    }

    /** Ce que la tâche planifiée doit faire maintenant (voir aFaire). */
    public function aFaireMaintenant(\DateTimeImmutable $maintenant): ?bool
    {
        $complete = null;
        $base = null;
        foreach ($this->liste() as $s) {
            $date = Rotation::date($s['nom']);
            $base ??= $date;
            if ($complete === null && $s['fichiers'] !== null) {
                $complete = $date;
            }
        }

        return self::aFaire($maintenant, $complete, $base, (string) $this->reglage('heure'), (int) $this->reglage('base_heures'));
    }

    /**
     * Sauvegardes présentes sur le disque, plus récente d'abord. Un fichier
     * manquant (supprimé par la rotation) est mis à null.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(?string $racine = null): array
    {
        $racine ??= $this->dossier;
        $resultat = [];
        foreach (is_dir($racine) ? (scandir($racine, \SCANDIR_SORT_DESCENDING) ?: []) : [] as $nom) {
            if (Rotation::date($nom) === null || !is_file($racine.'/'.$nom.'/manifeste.json')) {
                continue;
            }
            $m = json_decode((string) file_get_contents($racine.'/'.$nom.'/manifeste.json'), true);
            if (!\is_array($m)) {
                continue;
            }
            $m['nom'] = $nom;
            foreach (['base', 'fichiers'] as $partie) {
                if (!\is_array($m[$partie] ?? null) || !is_file($racine.'/'.$nom.'/'.$m[$partie]['fichier'])) {
                    $m[$partie] = null;
                }
            }
            if ($m['base'] !== null || $m['fichiers'] !== null) {
                $resultat[] = $m;
            }
        }

        return $resultat;
    }

    /** @return array<string, mixed>|null */
    public function trouver(string $nom): ?array
    {
        foreach ($this->liste() as $s) {
            if ($s['nom'] === $nom) {
                return $s;
            }
        }

        return null;
    }

    /**
     * État pour l'écran Sauvegardes et l'alerte du tableau de bord.
     *
     * @return array{derniere: ?array, derniereComplete: ?array, dernierEchec: ?array, alerte: ?string, total: int, espaceLibre: ?float, copie: string, copieAccessible: bool, dossier: string}
     */
    public function etat(\DateTimeImmutable $maintenant): array
    {
        $liste = $this->liste();
        $derniere = $liste[0] ?? null;
        $complete = null;
        $total = 0;
        foreach ($liste as $s) {
            $complete ??= $s['fichiers'] !== null ? $s : null;
            $total += ($s['base']['octets'] ?? 0) + ($s['fichiers']['octets'] ?? 0);
        }
        $journal = $this->journal();
        $dernierEchec = ($journal[0]['statut'] ?? null) === 'echec' ? $journal[0] : null;
        $dernierJournal = $journal[0] ?? null;

        return [
            'derniere' => $derniere,
            'derniereComplete' => $complete,
            'dernierEchec' => $dernierEchec,
            'alerte' => self::alerte($maintenant, $derniere !== null ? Rotation::date($derniere['nom']) : null, $dernierEchec, $dernierJournal['copie'] ?? null),
            'total' => $total,
            'espaceLibre' => is_dir($this->dossier) ? (@disk_free_space($this->dossier) ?: null) : null,
            'copie' => $this->copie,
            'copieAccessible' => $this->copie !== '' && is_dir($this->copie) && is_writable($this->copie),
            'dossier' => $this->dossier,
        ];
    }

    /**
     * Texte de l'alerte, ou null quand tout va bien.
     *
     * @param array<string, mixed>|null $dernierEchec
     */
    public static function alerte(\DateTimeImmutable $maintenant, ?\DateTimeImmutable $derniere, ?array $dernierEchec, ?string $copie): ?string
    {
        if ($dernierEchec !== null) {
            return 'La dernière sauvegarde a échoué : '.$dernierEchec['message'];
        }
        if ($derniere === null) {
            return 'Aucune sauvegarde n\'a encore été faite.';
        }
        $heures = intdiv($maintenant->getTimestamp() - $derniere->getTimestamp(), 3600);
        if ($heures >= self::RETARD_HEURES) {
            return \sprintf('Pas de sauvegarde depuis %s.', $heures >= 48 ? intdiv($heures, 24).' jours' : $heures.' heures');
        }
        if ($copie !== null && $copie !== 'ok') {
            return 'La copie sur le second disque a échoué : '.$copie;
        }

        return null;
    }

    /** @return list<array<string, mixed>> les 100 dernières tentatives, plus récente d'abord */
    public function journal(): array
    {
        $chemin = $this->dossier.'/'.self::JOURNAL;
        if (!is_file($chemin)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($chemin), true);

        return \is_array($j) ? array_values($j) : [];
    }

    /**
     * Remet la base d'une sauvegarde, après vérification de l'empreinte.
     * $cible permet de la remettre dans une autre base pour la contrôler
     * sans toucher à la base en service.
     */
    public function restaurerBase(string $nom, ?Connection $cible = null): int
    {
        $s = $this->trouver($nom) ?? throw new \RuntimeException('Sauvegarde introuvable : '.$nom);
        if ($s['base'] === null) {
            throw new \RuntimeException('Cette sauvegarde n\'a plus de base (supprimée par la rotation).');
        }
        $chemin = $this->dossier.'/'.$nom.'/'.$s['base']['fichier'];
        $this->verifierEmpreinte($chemin, $s['base']['sha256']);

        return $this->dump->importer($chemin, $cible);
    }

    /**
     * Remet les fichiers par-dessus ceux du projet (les fichiers ajoutés
     * depuis restent en place).
     *
     * @return array{fichiers: int, octets: int, ignores: int}
     */
    public function restaurerFichiers(string $nom): array
    {
        $s = $this->trouver($nom) ?? throw new \RuntimeException('Sauvegarde introuvable : '.$nom);
        if ($s['fichiers'] === null) {
            throw new \RuntimeException('Cette sauvegarde ne contient pas les fichiers.');
        }
        $chemin = $this->dossier.'/'.$nom.'/'.$s['fichiers']['fichier'];
        $this->verifierEmpreinte($chemin, $s['fichiers']['sha256']);

        return ArchiveTar::extraire($chemin, $this->projectDir, self::DOSSIERS);
    }

    /**
     * Range une copie rapatriée de hors site (déjà déchiffrée) comme une
     * sauvegarde ordinaire, pour qu'elle se restaure avec la même commande.
     * $source est déplacé ; il doit être dans le dossier des sauvegardes.
     */
    public function ajouterDepuisCopie(string $nom, string $partie, string $source): void
    {
        if (Rotation::date($nom) === null || !\in_array($partie, ['base', 'fichiers'], true)) {
            throw new \InvalidArgumentException('Nom de copie inattendu : '.$nom.' '.$partie);
        }
        $dossier = $this->dossier.'/'.$nom;
        $this->preparerDossier($this->dossier);
        if (!is_dir($dossier) && !@mkdir($dossier, 0700) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer '.$dossier);
        }
        $fichier = $partie === 'base' ? 'base.sql.gz' : 'fichiers.tar.gz';
        if (is_file($dossier.'/'.$fichier)) {
            throw new \RuntimeException('Cette sauvegarde a déjà sa '.($partie === 'base' ? 'base' : 'archive de fichiers').' : '.$dossier);
        }
        if ($partie === 'base') {
            $this->dump->verifierExport($source);
            $entree = ['fichier' => $fichier, 'octets' => (int) filesize($source), 'sha256' => hash_file('sha256', $source), 'methode' => 'copie hors site', 'tables' => null];
        } else {
            $contenu = ArchiveTar::parcourir($source);
            $entree = ['fichier' => $fichier, 'octets' => (int) filesize($source), 'sha256' => hash_file('sha256', $source), 'nombre' => $contenu['fichiers'], 'volume' => $contenu['octets']];
        }
        rename($source, $dossier.'/'.$fichier);
        @chmod($dossier.'/'.$fichier, 0600);

        $m = is_file($dossier.'/manifeste.json') ? json_decode((string) file_get_contents($dossier.'/manifeste.json'), true) : null;
        $m = \is_array($m) ? $m : [
            'nom' => $nom, 'debut' => Rotation::date($nom)->format(\DATE_ATOM), 'declencheur' => 'copie hors site', 'par' => null,
            'schema' => null, 'base' => null, 'fichiers' => null, 'message' => 'Rapatriée de la copie hors site',
        ];
        $m[$partie] = $entree;
        $this->ecrireManifeste($dossier, $m);
        @chmod($dossier.'/manifeste.json', 0600);
    }

    /** « 12,4 Mo » */
    public static function taille(int|float $octets): string
    {
        foreach (['o', 'Ko', 'Mo', 'Go'] as $i => $unite) {
            if ($octets < 1024 || $unite === 'Go') {
                return ($i === 0 ? (string) $octets : number_format($octets, 1, ',', ' ')).' '.$unite;
            }
            $octets /= 1024;
        }

        return '';
    }

    /** « 12/10/2026 à 14 h 05 » depuis un nom de sauvegarde. */
    public static function dateLisible(string $nom): string
    {
        $d = Rotation::date($nom);

        return $d === null ? $nom : $d->format('d/m/Y').' à '.$d->format('G').' h '.$d->format('i');
    }

    public function dossier(): string
    {
        return $this->dossier;
    }

    /**
     * Applique la rotation séparément aux bases et aux archives de fichiers.
     *
     * @return int fichiers supprimés
     */
    private function tourner(string $racine, \DateTimeImmutable $maintenant): int
    {
        $jours = (int) $this->reglage('jours');
        $semaines = (int) $this->reglage('semaines');
        $mois = (int) $this->reglage('mois');
        $supprimes = 0;
        $liste = $this->liste($racine);
        foreach (['base', 'fichiers'] as $partie) {
            $noms = array_values(array_map(static fn ($s) => $s['nom'], array_filter($liste, static fn ($s) => $s[$partie] !== null)));
            $garder = array_flip(Rotation::aGarder($noms, $maintenant, $jours, $semaines, $mois));
            foreach ($liste as $s) {
                if ($s[$partie] !== null && !isset($garder[$s['nom']])) {
                    @unlink($racine.'/'.$s['nom'].'/'.$s[$partie]['fichier']);
                    ++$supprimes;
                }
            }
        }
        foreach ($liste as $s) {
            $d = $racine.'/'.$s['nom'];
            if (!is_file($d.'/base.sql.gz') && !is_file($d.'/fichiers.tar.gz')) {
                $this->supprimer($d);
            }
        }

        return $supprimes;
    }

    /** Copie sur le second disque, vérifiée par empreinte ; « ok » ou la raison de l'échec. */
    private function copier(string $nom): ?string
    {
        if ($this->copie === '') {
            return null;
        }
        if (!is_dir($this->copie) || !is_writable($this->copie)) {
            return 'dossier '.$this->copie.' introuvable ou protégé (disque débranché ?)';
        }
        $source = $this->dossier.'/'.$nom;
        $provisoire = $this->copie.'/'.$nom.'.en-cours';
        try {
            $this->supprimer($provisoire);
            mkdir($provisoire, 0700);
            foreach (['base.sql.gz', 'fichiers.tar.gz', 'manifeste.json'] as $f) {
                if (is_file($source.'/'.$f)) {
                    if (!copy($source.'/'.$f, $provisoire.'/'.$f) || hash_file('sha256', $source.'/'.$f) !== hash_file('sha256', $provisoire.'/'.$f)) {
                        throw new \RuntimeException('copie de '.$f.' incorrecte');
                    }
                }
            }
            $m = json_decode((string) file_get_contents($provisoire.'/manifeste.json'), true);
            $m['copie'] = 'ok';
            $this->ecrireManifeste($provisoire, $m);
            if (!rename($provisoire, $this->copie.'/'.$nom)) {
                throw new \RuntimeException('renommage impossible');
            }

            return 'ok';
        } catch (\Throwable $e) {
            $this->supprimer($provisoire);

            return mb_substr($e->getMessage(), 0, 200);
        }
    }

    private function verifierEmpreinte(string $chemin, string $attendue): void
    {
        if (!hash_equals($attendue, (string) hash_file('sha256', $chemin))) {
            throw new \RuntimeException('Le fichier '.basename($chemin).' a été modifié ou abîmé depuis la sauvegarde (empreinte différente).');
        }
    }

    private function versionSchema(): ?string
    {
        try {
            $v = $this->connexion->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions');

            return $v === false || $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Réglage de la tuile Sauvegardes. Une base pas encore à jour (bascule
     * d'une ancienne base, restauration d'une ancienne sauvegarde) n'a pas
     * la table des réglages, ou pas sous cette forme : les valeurs par
     * défaut sont prises, pour que la sauvegarde de sécurité ne soit jamais
     * refusée à ce moment-là.
     */
    private function reglage(string $cle): mixed
    {
        try {
            return $this->parametres->valeur('sauvegardes', $cle);
        } catch (TableNotFoundException|InvalidFieldNameException) {
            return self::reglageParDefaut($cle);
        }
    }

    public static function reglageParDefaut(string $cle): mixed
    {
        return CatalogueParametres::avecDefauts(CatalogueParametres::tuiles()['sauvegardes']['champs'], [])[$cle] ?? null;
    }

    /** @param array<string, mixed> $entree */
    private function noter(array $entree): void
    {
        try {
            $this->preparerDossier($this->dossier);
            $journal = array_slice([$entree, ...$this->journal()], 0, 100);
            file_put_contents($this->dossier.'/'.self::JOURNAL, json_encode($journal, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        } catch (\Throwable) {
            // le journal ne doit jamais masquer l'erreur d'origine
        }
    }

    /** @param array<string, mixed> $m */
    private function ecrireManifeste(string $dossier, array $m): void
    {
        unset($m['supprimees']);
        file_put_contents($dossier.'/manifeste.json', json_encode($m, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function preparerDossier(string $dossier): void
    {
        if (!is_dir($dossier) && !@mkdir($dossier, 0700, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier des sauvegardes '.$dossier);
        }
        // Le dossier n'est jamais servi par le site ; ce fichier le rappelle à Apache s'il était déplacé sous public/.
        if (!is_file($dossier.'/.htaccess')) {
            @file_put_contents($dossier.'/.htaccess', "Require all denied\n");
        }
    }

    private function supprimer(string $chemin): void
    {
        if (is_file($chemin) || is_link($chemin)) {
            @unlink($chemin);

            return;
        }
        if (!is_dir($chemin)) {
            return;
        }
        foreach (scandir($chemin) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                $this->supprimer($chemin.'/'.$f);
            }
        }
        @rmdir($chemin);
    }
}
