<?php

namespace App\Service\Prepresse;

use App\Entity\Clients;
use App\Entity\User;
use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\JournalDossiers;
use App\Service\DossiersClients\NommageFichier;
use App\Service\DossiersClients\ReglagesDossiers;
use App\Service\ProduitsClient\DossierClient;

/**
 * Dépôt groupé (PQ02) : une copie de chaque fichier reçu est aussi rangée
 * dans le dossier du client sur le serveur de l'atelier, sous « Fichiers
 * reçus\AAAA-MM » (docs/dossiers-clients.md).
 *
 * La règle des dossiers clients tient : l'ERP COPIE, il ne déplace, ne
 * renomme ni ne supprime aucun fichier de l'atelier, et n'écrase rien (un
 * nom déjà pris devient « -2 »). Le dossier d'un client qui n'en a pas
 * encore est créé avec les sous-dossiers du modèle (exception prévue).
 * Sur le site en ligne, ou sans dossier racine réglé : rien.
 */
final class RangementDossierClient
{
    /** Clé du sous-dossier du modèle pour les fichiers envoyés par le client. */
    public const SOUS_DOSSIER = 'recus';

    public function __construct(
        private readonly ReglagesDossiers $reglages,
        private readonly DossierClient $dossiers,
        private readonly AnalyseDossiers $analyse,
        private readonly JournalDossiers $journal,
    ) {
    }

    public function actif(): bool
    {
        $racine = $this->reglages->racine();

        return '' !== $racine && is_dir($racine);
    }

    /**
     * Le dossier tel que le voient les postes : « \\ATELIER\Clients\C-0087
     * Fofana\02 Fichiers reçus\2026-10 », ou null si rien n'est rangé.
     */
    public function cheminAffiche(Clients $client, \DateTimeImmutable $le): ?string
    {
        if (!$this->actif()) {
            return null;
        }
        $dossier = $this->dossiers->dossierClient($client);
        $nomClient = null !== $dossier ? basename($dossier) : AnalyseDossiers::nomDossierClient($client);
        $racine = $this->reglages->racinePostes() ?: $this->reglages->racine();
        $sep = str_contains($racine, '\\') ? '\\' : '/';
        $sous = null !== $dossier ? $this->sousDossierExistant($dossier) : null;

        return rtrim($racine, '\\/').$sep.$nomClient.$sep.($sous ?? $this->nomSousDossier()).$sep.$le->format('Y-m');
    }

    /**
     * Copie le fichier rangé dans l'ERP vers le dossier du client. Le chemin
     * de la copie, ou null (pas de dossier réglé, droits d'écriture).
     */
    public function ranger(Clients $client, string $source, string $nom, ?User $par, \DateTimeImmutable $le): ?string
    {
        if (!$this->actif() || !is_file($source)) {
            return null;
        }
        try {
            $dossier = $this->dossiers->dossierClient($client);
            if (null === $dossier) {
                $this->analyse->creerDossier($client, $par);
                $dossier = $this->dossiers->dossierClient($client);
            }
        } catch (\DomainException) {
            return null;
        }
        if (null === $dossier) {
            return null;
        }
        $cible = $dossier.\DIRECTORY_SEPARATOR.($this->sousDossierExistant($dossier) ?? $this->nomSousDossier()).\DIRECTORY_SEPARATOR.$le->format('Y-m');
        if (!is_dir($cible) && !@mkdir($cible, 0775, true) && !is_dir($cible)) {
            return null;
        }
        $nom = NommageFichier::nomLibre(basename($nom), static fn (string $n): bool => file_exists($cible.\DIRECTORY_SEPARATOR.$n));
        $destination = $cible.\DIRECTORY_SEPARATOR.$nom;
        // copy() lit et écrit par blocs : un gros fichier ne monte pas en mémoire.
        if (!@copy($source, $destination)) {
            return null;
        }
        $this->journal->noter('ajout', $client, $par, ['fichier' => $destination, 'origine' => 'prépresse']);

        return $destination;
    }

    /** Le sous-dossier « Fichiers reçus » déjà présent chez le client (nom de l'atelier), s'il existe. */
    private function sousDossierExistant(string $dossier): ?string
    {
        foreach (@scandir($dossier) ?: [] as $n) {
            if ('.' !== $n[0] && is_dir($dossier.\DIRECTORY_SEPARATOR.$n) && self::SOUS_DOSSIER === $this->reglages->modele()->reconnaitre($n)) {
                return $n;
            }
        }

        return null;
    }

    private function nomSousDossier(): string
    {
        return $this->reglages->modele()->entree(self::SOUS_DOSSIER)['dossier'] ?? 'Fichiers reçus';
    }
}
