<?php

namespace App\Service\ProduitsClient;

use App\Entity\Clients;
use App\Service\Parametres\Parametres;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Dossier d'un client sur le serveur de l'atelier (maquette MP02), lu par
 * l'ERP local. Un navigateur ne peut pas lire un chemin du disque : c'est
 * le serveur de l'ERP qui liste le dossier et calcule l'empreinte.
 *
 * Réglage « Dossier racine des clients » (Paramètres › Atelier) : un
 * dossier par client dont le nom commence par son code
 * (« C-0231 Awa Diarra »), et dans celui-ci un sous-dossier « Produits »
 * (sinon le dossier du client lui-même). Rien n'est jamais créé, déplacé ni
 * supprimé ici : le dossier reste celui de l'atelier.
 */
final class DossierClient
{
    public const SOUS_DOSSIER = 'Produits';

    /** Extensions qu'on peut lier (fichiers d'impression). */
    public const EXTENSIONS = ['pdf', 'ai', 'eps', 'tif', 'tiff', 'jpg', 'jpeg', 'png', 'psd'];

    /** Fichiers listés au plus. */
    private const MAX = 300;

    public function __construct(
        private readonly Parametres $parametres,
        private readonly ControleFichierLie $controle,
        private readonly CacheInterface $cache,
        private readonly ?string $racineImposee = null,
    ) {
    }

    /** Le réglage, tel que saisi ('' = pas réglé). */
    public function racine(): string
    {
        return rtrim(trim($this->racineImposee ?? (string) $this->parametres->valeur('produits_clients', 'dossier_racine')), '/\\');
    }

    /** Le dossier « Produits » du client, ou null (réglage vide, dossier absent). */
    public function dossier(Clients $client): ?string
    {
        $racine = $this->racine();
        $code = trim((string) $client->getCode());
        if ('' === $racine || '' === $code || !is_dir($racine)) {
            return null;
        }
        $dossierClient = null;
        foreach (@scandir($racine) ?: [] as $nom) {
            if ('.' === $nom[0] || !is_dir($racine.\DIRECTORY_SEPARATOR.$nom)) {
                continue;
            }
            // « C-0231 Awa Diarra », « C-0231_Awa », « C-0231 » : le code, puis une fin de mot.
            if (preg_match('/^'.preg_quote($code, '/').'(?![A-Za-z0-9])/i', $nom)) {
                $dossierClient = $racine.\DIRECTORY_SEPARATOR.$nom;
                break;
            }
        }
        if (null === $dossierClient) {
            return null;
        }
        foreach (@scandir($dossierClient) ?: [] as $nom) {
            if (0 === strcasecmp($nom, self::SOUS_DOSSIER) && is_dir($dossierClient.\DIRECTORY_SEPARATOR.$nom)) {
                return $dossierClient.\DIRECTORY_SEPARATOR.$nom;
            }
        }

        return $dossierClient;
    }

    /** Chemin à montrer quand le dossier manque : « D:\Clients\DEMO-0195 Amadou Sow\Produits ». */
    public function cheminAttendu(Clients $client): string
    {
        $sep = str_contains($this->racine(), '\\') ? '\\' : '/';

        return ($this->racine() ?: '…').$sep.$client->getCode().' '.$client->getNomComplet().$sep.self::SOUS_DOSSIER;
    }

    /**
     * Les fichiers du dossier, les plus récents d'abord. Les sous-dossiers
     * (« _sources ») sont montrés à la fin, sans pouvoir être liés.
     *
     * @return list<array{nom: string, octets: int, modifie: \DateTimeImmutable, extension: string, liable: bool, dossier: bool, resume: string}>
     */
    public function liste(Clients $client): array
    {
        $dossier = $this->dossier($client);
        if (null === $dossier) {
            return [];
        }
        $fichiers = [];
        $dossiers = [];
        foreach (@scandir($dossier) ?: [] as $nom) {
            if ('.' === $nom[0] || \count($fichiers) >= self::MAX) {
                continue;
            }
            $chemin = $dossier.\DIRECTORY_SEPARATOR.$nom;
            if (is_dir($chemin)) {
                $dossiers[] = ['nom' => $nom, 'octets' => 0, 'modifie' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($chemin)), 'extension' => '', 'liable' => false, 'dossier' => true, 'resume' => 'dossier · non lié'];
                continue;
            }
            $fichiers[] = $this->decrire($chemin, $nom);
        }
        usort($fichiers, static fn (array $a, array $b): int => $b['modifie'] <=> $a['modifie']);

        return array_merge($fichiers, $dossiers);
    }

    /**
     * Le chemin complet d'un fichier du dossier, désigné par son nom relatif,
     * ou null s'il n'existe pas ou sort du dossier (« ../ » refusé).
     */
    public function chemin(Clients $client, string $nom): ?string
    {
        $dossier = $this->dossier($client);
        $nom = str_replace('\\', '/', trim($nom));
        if (null === $dossier || '' === $nom || str_contains($nom, '..') || str_starts_with($nom, '/') || str_contains($nom, "\0") || str_contains($nom, '/')) {
            return null;
        }
        $chemin = $dossier.\DIRECTORY_SEPARATOR.$nom;
        $reel = realpath($chemin);
        $base = realpath($dossier);
        if (false === $reel || false === $base || !is_file($reel) || !str_starts_with($reel, $base.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $reel;
    }

    /** Empreinte SHA-256 calculée par le serveur (le fichier peut faire plusieurs centaines de Mo). */
    public static function empreinte(string $chemin): ?string
    {
        $h = @hash_file('sha256', $chemin);

        return false === $h ? null : $h;
    }

    /**
     * @return array{nom: string, octets: int, modifie: \DateTimeImmutable, extension: string, liable: bool, dossier: bool, resume: string}
     */
    private function decrire(string $chemin, string $nom): array
    {
        $octets = (int) @filesize($chemin);
        $modifie = (int) @filemtime($chemin);
        $extension = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));
        $liable = \in_array($extension, self::EXTENSIONS, true);
        // Le résumé lit le fichier : gardé en cache tant que le fichier ne change pas.
        $resume = $liable ? $this->cache->get('dossier_client_'.hash('sha256', $chemin.'|'.$octets.'|'.$modifie), function (ItemInterface $item) use ($chemin): string {
            $item->expiresAfter(30 * 86400);

            return $this->controle->resume($chemin);
        }) : 'format non pris en charge';

        return ['nom' => $nom, 'octets' => $octets, 'modifie' => (new \DateTimeImmutable())->setTimestamp($modifie), 'extension' => $extension, 'liable' => $liable, 'dossier' => false, 'resume' => $resume];
    }
}
