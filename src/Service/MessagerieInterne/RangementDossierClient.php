<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Clients;
use App\Service\ProduitsClient\DossierClient;

/**
 * « Ranger aussi dans le dossier du client » (maquette MI03) : une copie
 * du fichier envoyé dans la messagerie va dans le dossier du client sur le
 * serveur de l'atelier, sous-dossier « 02 Fichiers reçus » par défaut.
 *
 * Jamais d'écrasement ni de suppression : un nom déjà pris devient
 * « nom (2).pdf ». Le sous-dossier est créé s'il manque ; le dossier du
 * client, lui, doit exister (il est retrouvé par DossierClient d'après le
 * code du client).
 *
 * Point d'accroche pour les dossiers clients complets (branche
 * w/dossiers-1910, sous-dossiers « 01 Conceptions » à « 06 Documents ») :
 * c'est la seule méthode que la messagerie appelle.
 */
class RangementDossierClient
{
    public const SOUS_DOSSIER = '02 Fichiers reçus';

    public function __construct(private readonly DossierClient $dossiers)
    {
    }

    /** Le réglage « Dossier racine des clients » est posé et le dossier existe. */
    public function disponible(): bool
    {
        $racine = $this->dossiers->racine();

        return '' !== $racine && is_dir($racine);
    }

    /** Pourquoi la case est grisée, ou null si elle peut être cochée. */
    public function raisonIndisponible(?Clients $client): ?string
    {
        if (!$this->disponible()) {
            return 'Le dossier racine des clients n\'est pas réglé (Paramètres › Atelier › Dossier des clients).';
        }
        if (null === $client) {
            return 'Ce groupe n\'est lié à aucun client.';
        }
        if (null === $this->dossiers->dossierDuClient($client)) {
            return \sprintf('Le dossier du client %s est introuvable sur le serveur.', $client->getCode());
        }

        return null;
    }

    /** « C-0087 Hôtel Niger\02 Fichiers reçus » : l'endroit montré sous la case. */
    public function destination(Clients $client, string $sousDossier = self::SOUS_DOSSIER): string
    {
        $dossier = $this->dossiers->dossierDuClient($client);

        return (null === $dossier ? $client->getCode().' '.$client->getNomComplet() : basename($dossier)).'\\'.$sousDossier;
    }

    /**
     * Copie le fichier dans le dossier du client. Rend le chemin relatif au
     * dossier du client (« 02 Fichiers reçus/devis.pdf »).
     *
     * @throws \DomainException message à montrer
     */
    public function ranger(Clients $client, string $source, string $nom, string $sousDossier = self::SOUS_DOSSIER): string
    {
        $raison = $this->raisonIndisponible($client);
        if (null !== $raison) {
            throw new \DomainException($raison);
        }
        if (!is_file($source) || !is_readable($source)) {
            throw new \DomainException('Le fichier à ranger est introuvable.');
        }
        $sousDossier = self::nomPropre($sousDossier) ?? self::SOUS_DOSSIER;
        $nom = self::nomPropre($nom) ?? 'fichier';
        $dossierClient = (string) $this->dossiers->dossierDuClient($client);
        $cible = $dossierClient.\DIRECTORY_SEPARATOR.$sousDossier;
        if (!is_dir($cible) && !@mkdir($cible, 0775) && !is_dir($cible)) {
            throw new \DomainException(\sprintf('Le dossier « %s » n\'a pas pu être créé chez le client.', $sousDossier));
        }

        $base = pathinfo($nom, \PATHINFO_FILENAME);
        $extension = pathinfo($nom, \PATHINFO_EXTENSION);
        for ($i = 1; $i <= 200; ++$i) {
            $essai = 1 === $i ? $nom : \sprintf('%s (%d)%s', $base, $i, '' !== $extension ? '.'.$extension : '');
            $chemin = $cible.\DIRECTORY_SEPARATOR.$essai;
            // « x » : création seulement, jamais d'écrasement même si un autre poste écrit au même moment.
            $sortie = @fopen($chemin, 'x');
            if (false === $sortie) {
                continue;
            }
            $entree = fopen($source, 'r');
            $ok = false !== $entree && false !== stream_copy_to_stream($entree, $sortie);
            if (false !== $entree) {
                fclose($entree);
            }
            fclose($sortie);
            if (!$ok || filesize($chemin) !== filesize($source)) {
                // Copie ratée : seul le fichier que l'on vient de créer est retiré.
                @unlink($chemin);
                throw new \DomainException('La copie dans le dossier du client a échoué.');
            }

            return $sousDossier.'/'.$essai;
        }

        throw new \DomainException('Trop de fichiers du même nom dans le dossier du client.');
    }

    /** Nom de fichier ou de dossier sans chemin ni caractère interdit sous Windows, ou null. */
    public static function nomPropre(string $nom): ?string
    {
        $nom = trim((string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '-', $nom), " .-\t");
        if ('' === $nom || '..' === $nom) {
            return null;
        }

        return mb_substr($nom, 0, 150);
    }
}
