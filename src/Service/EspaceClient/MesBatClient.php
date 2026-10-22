<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\ControlePrePresse;

/**
 * « Fichiers et BAT » (maquettes DB04 à DB07) : les BAT qui attendent le
 * client avec la date limite pour garder ses dates, l'historique des BAT
 * validés (qui, quand), et les versions d'un BAT pour les comparer.
 */
final class MesBatClient
{
    public function __construct(
        private readonly EspaceClient $espace,
        private readonly DelaisCommande $delais,
    ) {
    }

    /**
     * BAT à valider, le plus pressé d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function aValider(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $cartes = [];
        foreach ($this->espace->bats($client, true) as $ctl) {
            $cartes[] = $this->carte($ctl, $maintenant);
        }
        usort($cartes, static fn (array $a, array $b): int => [$a['limite'] ?? $a['pret'], $a['controle']->getId()] <=> [$b['limite'] ?? $b['pret'], $b['controle']->getId()]);

        return $cartes;
    }

    /** @return array<string, mixed> */
    public function carte(ControlePrePresse $ctl, \DateTimeImmutable $maintenant): array
    {
        $ligne = $ctl->getCommandeDetail();
        $conception = $this->espace->conception($ctl);
        $evenements = $conception?->getEvenements() ?? [];
        $envois = array_values(array_filter($evenements, static fn (array $e): bool => 'bat' === ($e['type'] ?? null)));
        $retours = array_values(array_filter($evenements, static fn (array $e): bool => 'retour' === ($e['type'] ?? null)));
        $version = max(1, $conception?->getVersions() ?? 0, \count($envois));
        $images = $this->espace->imagesBat($ctl);
        $delai = $this->delais->limiteBat($ligne->getCommande(), $maintenant);
        $dernierRetour = [] !== $retours ? $retours[\count($retours) - 1] : null;
        $demande = null;
        if ($version > 1 && null !== $dernierRetour) {
            $demande = preg_match('/« (.+?) »/u', (string) ($dernierRetour['detail'] ?? ''), $m) ? $m[1] : null;
            $demande ??= implode(', ', array_column($dernierRetour['reperes'] ?? [], 't')) ?: null;
        }

        return [
            'controle' => $ctl,
            'titre' => EspaceClient::titreLigne($ligne),
            'commande' => $ligne->getCommande(),
            'version' => $version,
            'envoyeLe' => $conception?->getDernierBatLe(),
            'par' => [] !== $envois ? ($envois[\count($envois) - 1]['par'] ?? null) : null,
            'vignette' => $images[0] ?? null,
            'limite' => $delai['limite'],
            'pret' => $delai['pret'],
            'tenue' => $delai['tenue'],
            'modifie' => $demande,
        ];
    }

    /**
     * BAT validés : titre, commande, versions, quand, par qui, le fichier.
     *
     * @return list<array<string, mixed>>
     */
    public function historique(Clients $client): array
    {
        $liste = [];
        foreach ($this->espace->bats($client) as $ctl) {
            if (!$ctl->isBatValide()) {
                continue;
            }
            $conception = $this->espace->conception($ctl);
            $validation = null;
            foreach ($conception?->getEvenements() ?? [] as $e) {
                $validation = 'valide' === ($e['type'] ?? null) ? $e : $validation;
            }
            $fichiers = $this->espace->fichiersBat($ctl);
            $liste[] = [
                'controle' => $ctl,
                'titre' => EspaceClient::titreLigne($ctl->getCommandeDetail()),
                'commande' => $ctl->getCommandeDetail()->getCommande(),
                'version' => max(1, $conception?->getVersions() ?? 0),
                'valideLe' => $conception?->getValideLe() ?? $ctl->getValideLe(),
                'par' => $validation['par'] ?? null,
                'fichier' => $fichiers[0] ?? null,
            ];
        }
        usort($liste, static fn (array $a, array $b): int => ($b['valideLe']?->getTimestamp() ?? 0) <=> ($a['valideLe']?->getTimestamp() ?? 0));

        return $liste;
    }

    /**
     * Les versions d'un BAT envoyées par l'atelier, de la plus ancienne à
     * l'actuelle : numéro => fichiers (images d'abord).
     *
     * @return array<int, list<CommandeDetailFichier>>
     */
    public function versions(ControlePrePresse $ctl): array
    {
        $fichiers = array_filter($ctl->getFichiers()->toArray(), static fn (CommandeDetailFichier $f): bool => $f->isActif() && $f->estUploadTermine() && CommandeDetailFichier::ORIGINE_CLIENT !== $f->getOrigine());
        $versions = [];
        foreach ($fichiers as $f) {
            $versions[$f->getVersion()][] = $f;
        }
        ksort($versions);

        return $versions;
    }

    /** La validation du BAT telle que le client la voit (maquette DB07) : version, qui, quand, d'où, vérifications. */
    public function trace(ControlePrePresse $ctl): ?array
    {
        $conception = $this->espace->conception($ctl);
        foreach (array_reverse($conception?->getEvenements() ?? []) as $e) {
            if ('valide' === ($e['type'] ?? null)) {
                return [
                    'version' => max(1, $conception->getVersions()),
                    'par' => $e['par'] ?? null,
                    'le' => $e['le'],
                    'depuis' => $e['depuis'] ?? null,
                    'verifications' => \count($e['verifications'] ?? []),
                ];
            }
        }

        return null;
    }
}
