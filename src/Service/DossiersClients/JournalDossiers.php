<?php

namespace App\Service\DossiersClients;

use App\Entity\Clients;
use App\Entity\JournalActivite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Journal des dossiers clients (maquette DS04, « Sécurité ») : qui a
 * ouvert, ajouté, classé, lié ou publié quel fichier. Dans le journal
 * d'activité commun (entité « DossierClient »), jamais effacé.
 */
final class JournalDossiers
{
    public const ENTITE = 'DossierClient';

    public const ACTIONS = [
        'ouverture' => 'a ouvert',
        'telechargement' => 'a téléchargé',
        'ajout' => 'a ajouté',
        'classement' => 'a classé',
        'publication' => 'a publié pour le client',
        'retrait_publication' => 'a retiré du site',
        'lien_commande' => 'a lié à une commande',
        'retrait_lien' => 'a retiré le lien avec la commande',
        'retrait_liste' => 'a retiré de la liste à classer',
        'liaison' => 'a lié le dossier',
        'creation_dossier' => 'a créé le dossier',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $details nom du fichier, chemin, commande…
     */
    public function noter(string $action, ?Clients $client, ?User $par, array $details): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite(self::ENTITE)
            ->setEntiteId((int) $client?->getId())
            ->setAction(substr($action, 0, 20))
            ->setDonneesApres(['client' => $client?->getNomComplet()] + $details)
            ->setUtilisateur($par));
    }

    /**
     * Dernières lignes, pour l'écran des réglages.
     *
     * @return list<array{le: \DateTimeImmutable, qui: string, quoi: string, fichier: string, client: string}>
     */
    public function dernieres(int $nombre = 8): array
    {
        $lignes = [];
        foreach ($this->em->getRepository(JournalActivite::class)->findBy(['entite' => self::ENTITE], ['id' => 'DESC'], $nombre) as $j) {
            $d = $j->getDonneesApres() ?? [];
            $lignes[] = [
                'le' => $j->getCreatedAt(),
                'qui' => null !== $j->getUtilisateur() ? \App\Service\Pointage\Pointages::nom($j->getUtilisateur()) : 'analyse',
                'quoi' => self::ACTIONS[$j->getAction()] ?? $j->getAction(),
                'fichier' => (string) ($d['fichier'] ?? $d['dossier'] ?? ''),
                'client' => (string) ($d['client'] ?? ''),
            ];
        }

        return $lignes;
    }
}
