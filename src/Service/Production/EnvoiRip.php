<?php

namespace App\Service\Production;

use App\Entity\TirageRouleau;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;

/**
 * « Envoyer au RIP » (maquette PD02, docs/production-rouleau.md) : le PDF
 * du rouleau, monté dans le navigateur (pdf-lib), arrive par morceaux, est
 * rangé dans l'ERP (var/uploads/production/rouleaux, jamais servi en
 * direct) puis copié dans le dossier de la machine. Sinon, « fichiers
 * séparés » : chaque fichier sous son nom de production et une feuille de
 * placement (PDF). Les fichiers de découpe partent chez le traceur.
 */
final class EnvoiRip
{
    /** Taille maximale du PDF du rouleau (et des fichiers à y monter). */
    public const TAILLE_MAX = 400 * 1024 * 1024;
    /** Morceaux de 5 Mo au plus (moins si PHP n'accepte pas plus). */
    public const MORCEAU = 5 * 1024 * 1024;
    private const SESSION = 'production_rip_envois';
    private const DOSSIER = 'var/uploads/production/rouleaux';
    private const TEMPORAIRE = 'var/uploads/production/tmp';

    public function __construct(
        private readonly TiragesRouleau $tirages,
        private readonly FichierProduction $fichiers,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** Taille d'un morceau : 5 Mo, ou moins si PHP refuse plus. */
    public static function morceau(): int
    {
        return (int) max(256 * 1024, min(self::MORCEAU, UploadedFile::getMaxFilesize() - 64 * 1024));
    }

    /** Ouvre un envoi par morceaux pour ce tirage ; rend le jeton. */
    public function initialiser(TirageRouleau $tirage, int $taille, int $morceaux, SessionInterface $session): string
    {
        if ($taille <= 0 || $taille > self::TAILLE_MAX) {
            throw new \DomainException(\sprintf('Le PDF du rouleau doit faire moins de %d Mo : envoyez les fichiers séparés.', self::TAILLE_MAX / 1024 / 1024));
        }
        if ($morceaux !== (int) ceil($taille / self::morceau())) {
            throw new \DomainException('Découpage de l\'envoi incorrect.');
        }
        $this->nettoyer();
        $jeton = bin2hex(random_bytes(32));
        $envois = $session->get(self::SESSION, []);
        $envois = \is_array($envois) ? \array_slice($envois, -5, null, true) : [];
        $envois[$jeton] = ['tirage' => $tirage->getId(), 'taille' => $taille, 'morceaux' => $morceaux, 'le' => time()];
        $session->set(self::SESSION, $envois);

        return $jeton;
    }

    /** Range un morceau (un morceau déjà reçu n'est pas compté deux fois). Rend le nombre reçu. */
    public function recevoir(TirageRouleau $tirage, string $jeton, int $index, UploadedFile $morceau, SessionInterface $session): int
    {
        $envoi = $this->envoi($tirage, $jeton, $session);
        if ($index < 0 || $index >= $envoi['morceaux']) {
            throw new \DomainException('Morceau inattendu.');
        }
        if (!$morceau->isValid() || $morceau->getSize() > self::morceau()) {
            throw new \DomainException('Morceau refusé (taille).');
        }
        $dossier = $this->temporaire($jeton);
        $nom = \sprintf('%08d.part', $index);
        if (!is_file($dossier.'/'.$nom)) {
            $morceau->move($dossier, $nom);
        }

        return \count(glob($dossier.'/*.part') ?: []);
    }

    /**
     * Assemble le PDF reçu, le range, le copie dans le dossier de la
     * machine et y joint les fichiers de découpe. Ne flushe pas.
     *
     * @return array{copie: ?string, decoupes: int, message: string}
     */
    public function terminer(TirageRouleau $tirage, string $jeton, int $pages, User $par, \DateTimeImmutable $le, SessionInterface $session): array
    {
        $envoi = $this->envoi($tirage, $jeton, $session);
        $dossier = $this->temporaire($jeton);
        $parts = glob($dossier.'/*.part') ?: [];
        sort($parts);
        if (\count($parts) !== $envoi['morceaux']) {
            throw new \DomainException('L\'envoi n\'est pas complet : relancez « Envoyer au RIP ».');
        }
        $nom = $this->tirages->nomRip($tirage);
        $stockage = $this->stockage($tirage, 'pdf');
        $sortie = fopen($stockage, 'x');
        if (false === $sortie) {
            throw new \DomainException('Le PDF du rouleau n\'a pas pu être rangé.');
        }
        foreach ($parts as $part) {
            $entree = fopen($part, 'r');
            if (false !== $entree) {
                stream_copy_to_stream($entree, $sortie);
                fclose($entree);
            }
        }
        fclose($sortie);
        $this->supprimerDossier($dossier);
        $envois = $session->get(self::SESSION, []);
        unset($envois[$jeton]);
        $session->set(self::SESSION, $envois);

        clearstatcache(true, $stockage);
        $debut = (string) file_get_contents($stockage, false, null, 0, 5);
        if (filesize($stockage) !== $envoi['taille'] || '%PDF-' !== $debut) {
            @unlink($stockage);
            throw new \DomainException('Le fichier reçu n\'est pas le PDF attendu.');
        }

        return $this->enregistrer($tirage, TirageRouleau::ENVOI_UNIQUE, $stockage, $nom, max(1, min(999, $pages)), [], $par, $le);
    }

    /**
     * « Envoyer les fichiers séparés » : la feuille de placement (PDF) et
     * chaque fichier sous son nom de production. Ne flushe pas.
     *
     * @return array{copie: ?string, decoupes: int, message: string}
     */
    public function envoyerSepares(TirageRouleau $tirage, User $par, \DateTimeImmutable $le): array
    {
        $html = $this->twig->render('production_rouleau/placement_pdf.html.twig', [
            'tirage' => $tirage,
            'travaux' => $this->tirages->travaux($tirage),
            'pieces' => $tirage->piecesTriees(),
            'le' => $le,
        ]);
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $stockage = $this->stockage($tirage, 'pdf', '_placement');
        if (false === file_put_contents($stockage, (string) $dompdf->output(), \LOCK_EX)) {
            throw new \DomainException('La feuille de placement n\'a pas pu être rangée.');
        }

        // Chaque fichier sous son nom de production dans le dossier de la machine (si réglé).
        $noms = [];
        $code = $this->fichiers->codeDossier($tirage->getMachine());
        $racine = null !== $this->fichiers->racine() && null !== $code;
        foreach ($tirage->ordres() as $o) {
            $noms[] = FichierProduction::nomProduction($o);
            if ($racine) {
                $source = $this->fichiers->source($o);
                if (null === $source['chemin']) {
                    throw new \DomainException(\sprintf('%s : %s', $o->getNumero(), $source['raison']));
                }
                $o->noterCopieMachine($this->fichiers->copier($source['chemin'], (string) $code, FichierProduction::nomProduction($o)), $par, $le);
            }
        }

        return $this->enregistrer($tirage, TirageRouleau::ENVOI_SEPARES, $stockage, str_replace('.pdf', '_placement.pdf', $this->tirages->nomRip($tirage)), 1, $noms, $par, $le);
    }

    /** @return array{copie: ?string, decoupes: int, message: string} */
    private function enregistrer(TirageRouleau $tirage, string $mode, string $stockage, string $nom, int $pages, array $noms, User $par, \DateTimeImmutable $le): array
    {
        $copie = null;
        $code = $this->fichiers->codeDossier($tirage->getMachine());
        if (null !== $this->fichiers->racine() && null !== $code) {
            $copie = $this->fichiers->copier($stockage, $code, $nom);
            if (TirageRouleau::ENVOI_UNIQUE === $mode) {
                foreach ($tirage->ordres() as $o) {
                    $o->noterCopieMachine($copie, $par, $le);
                }
            }
        }
        $decoupes = 0;
        foreach ($tirage->ordres() as $o) {
            if (null !== $this->fichiers->copierDecoupe($o)) {
                ++$decoupes;
            }
        }
        $tirage->enregistrerEnvoi($mode, [
            'chemin' => $this->relatif($stockage),
            'nom' => $nom,
            'empreinte' => hash_file('sha256', $stockage) ?: null,
            'taille' => (int) filesize($stockage),
            'pages' => $pages,
            'fichiers' => $noms,
        ], $copie, $par, $le);

        $quoi = TirageRouleau::ENVOI_UNIQUE === $mode ? 'Un seul PDF pour le rouleau ('.$nom.')' : 'Fichiers séparés : '.\count($noms).' fichier'.(\count($noms) > 1 ? 's' : '').' sous leur nom de production et la feuille de placement';
        $ou = null !== $copie ? ' copié dans '.$this->fichiers->dossierAffiche($code).'.' : ' : '.(null === $code && null !== $this->fichiers->racine() ? 'la machine n\'a pas de dossier, le fichier reste dans l\'ERP.' : 'dossier de la machine non réglé, le fichier reste dans l\'ERP (à télécharger).');

        return ['copie' => $copie, 'decoupes' => $decoupes, 'message' => $quoi.$ou.($decoupes > 0 ? \sprintf(' %d fichier%s de découpe pour le traceur.', $decoupes, $decoupes > 1 ? 's' : '') : '')];
    }

    /** Le fichier rangé d'un envoi (chemin relatif enregistré), ou null. */
    public function chemin(?string $relatif): ?string
    {
        $relatif = str_replace('\\', '/', trim((string) $relatif));
        if (!str_starts_with($relatif, 'production/rouleaux/') || str_contains($relatif, '..') || str_contains($relatif, "\0")) {
            return null;
        }
        $base = realpath($this->projet.'/'.self::DOSSIER);
        $reel = realpath($this->projet.'/var/uploads/'.$relatif);

        return false !== $base && false !== $reel && str_starts_with($reel, $base.\DIRECTORY_SEPARATOR) && is_file($reel) ? $reel : null;
    }

    /** @return array{tirage: int, taille: int, morceaux: int, le: int} */
    private function envoi(TirageRouleau $tirage, string $jeton, SessionInterface $session): array
    {
        $envois = $session->get(self::SESSION, []);
        $envoi = \is_array($envois) ? ($envois[$jeton] ?? null) : null;
        if (!preg_match('/^[a-f0-9]{64}$/', $jeton) || !\is_array($envoi) || (int) $envoi['tirage'] !== $tirage->getId()) {
            throw new \DomainException('Envoi inconnu : relancez « Envoyer au RIP ».');
        }

        return $envoi;
    }

    private function temporaire(string $jeton): string
    {
        $dossier = $this->projet.'/'.self::TEMPORAIRE.'/'.$jeton;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \DomainException('Dossier temporaire impossible à créer.');
        }

        return $dossier;
    }

    /** Nouveau fichier sous var/uploads/production/rouleaux (jamais écrasé). */
    private function stockage(TirageRouleau $tirage, string $extension, string $suffixe = ''): string
    {
        $dossier = $this->projet.'/'.self::DOSSIER;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \DomainException('Dossier des rouleaux impossible à créer.');
        }
        $version = \count($tirage->getEnvois()) + 1;

        return \sprintf('%s/%s_v%d%s_%s.%s', $dossier, $tirage->getNumero(), $version, $suffixe, bin2hex(random_bytes(4)), $extension);
    }

    private function relatif(string $chemin): string
    {
        return 'production/rouleaux/'.basename($chemin);
    }

    /** Envois abandonnés depuis plus d'un jour. */
    private function nettoyer(): void
    {
        foreach (glob($this->projet.'/'.self::TEMPORAIRE.'/*', \GLOB_ONLYDIR) ?: [] as $d) {
            if ((int) @filemtime($d) < time() - 86400) {
                $this->supprimerDossier($d);
            }
        }
    }

    private function supprimerDossier(string $dossier): void
    {
        foreach (glob($dossier.'/*.part') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
    }
}
