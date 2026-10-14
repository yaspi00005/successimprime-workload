<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\Factures;
use App\Entity\ModeleMessage;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RelanceCreance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Factures et créances (maquette 30) : ce que chaque client doit encore,
 * classé par ancienneté (balance âgée), avec la dernière relance.
 *
 * La créance est le reste dû de la commande (paiements validés
 * seulement) ; le document affiché est sa facture quand elle existe.
 */
final class Creances
{
    public const TRANCHES = [
        '0_30' => '0–30 j',
        '31_60' => '31–60 j',
        '61_90' => '61–90 j',
        'plus_90' => '+90 j',
    ];

    public const FILTRES = [
        'ouvertes' => 'À encaisser',
        'echues' => 'Échues',
        'payees' => 'Payées',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Caisse $caisse,
    ) {
    }

    /**
     * @return array{lignes: list<array<string, mixed>>, balance: array<string, int>, total: int, nb_echues: int}
     */
    public function donnees(\DateTimeImmutable $maintenant, string $filtre = 'ouvertes', string $recherche = ''): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $ouvertes = [];
        $encours = [];
        foreach ($this->caisse->commandesImpayees() as $impayee) {
            $commande = $impayee['commande'];
            $facture = $this->factureDe($commande);
            $echeance = self::echeance($facture, $commande);
            $jours = null === $echeance ? 0 : (int) $echeance->setTime(0, 0)->diff($aujourdhui)->format('%r%a');
            $ouvertes[] = [
                'commande' => $commande,
                'facture' => $facture,
                'echeance' => $echeance,
                'jours' => $jours,
                'reste' => $impayee['reste'],
            ];
            $client = $commande->getClients()?->getId();
            if (null !== $client) {
                $encours[$client] = ($encours[$client] ?? 0) + $impayee['reste'];
            }
        }

        $balance = array_fill_keys(array_keys(self::TRANCHES), 0);
        $nbEchues = 0;
        foreach ($ouvertes as $o) {
            $balance[self::tranche($o['jours'])] += $o['reste'];
            $nbEchues += $o['jours'] > 0 ? 1 : 0;
        }

        $selection = match ($filtre) {
            'echues' => array_values(array_filter($ouvertes, static fn (array $o): bool => $o['jours'] > 0)),
            'payees' => $this->payees(),
            default => $ouvertes,
        };
        // Les plus en retard d'abord, puis les échéances proches.
        if ('payees' !== $filtre) {
            usort($selection, static fn (array $a, array $b): int => $b['jours'] <=> $a['jours']);
        }

        $relances = $this->dernieresRelances(array_map(static fn (array $o): Commandes => $o['commande'], $selection));
        $recherche = mb_strtolower(trim($recherche));
        $lignes = [];
        foreach ($selection as $o) {
            $commande = $o['commande'];
            $client = $commande->getClients();
            $nom = $o['facture']?->getNomFacturation() ?: ($client?->getNomComplet() ?? '');
            $document = $o['facture']?->getNumero() ?? $commande->getLibelle();
            if ('' !== $recherche && !str_contains(mb_strtolower($nom.' '.$document.' '.$commande->getNumero()), $recherche)) {
                continue;
            }
            $plafond = $client?->getPlafondCredit();
            $lignes[] = $o + [
                'client' => $nom,
                'document' => $document,
                'montant' => (int) $commande->getTotalTtc(),
                'statut' => self::statut($o['reste'], $o['jours']),
                'plafond_depasse' => null !== $client && null !== $plafond && $plafond > 0 && ($encours[$client->getId()] ?? 0) > $plafond,
                'relance' => $relances[$commande->getId()] ?? null,
                'telephone' => $client?->getTelephone(),
            ];
        }

        return [
            'lignes' => $lignes,
            'balance' => $balance,
            'total' => array_sum($balance),
            'nb_echues' => $nbEchues,
        ];
    }

    /** Échéance : celle de la facture, sinon le jour de livraison prévu, sinon la date de commande. */
    public static function echeance(?Factures $facture, Commandes $commande): ?\DateTimeImmutable
    {
        $date = $facture?->getDateEcheance() ?? $commande->getDateLivraison() ?? $commande->getDateCommande();

        return null === $date ? null : \DateTimeImmutable::createFromInterface($date);
    }

    public static function tranche(int $joursDeRetard): string
    {
        return match (true) {
            $joursDeRetard <= 30 => '0_30',
            $joursDeRetard <= 60 => '31_60',
            $joursDeRetard <= 90 => '61_90',
            default => 'plus_90',
        };
    }

    /**
     * @return array{cle: string, libelle: string}
     */
    public static function statut(int $reste, int $joursDeRetard): array
    {
        return match (true) {
            $reste <= 0 => ['cle' => 'payee', 'libelle' => 'Payée'],
            $joursDeRetard <= 0 => ['cle' => 'a_echoir', 'libelle' => 'À échoir'],
            $joursDeRetard <= 30 => ['cle' => 'echue', 'libelle' => sprintf('Échue %d j', $joursDeRetard)],
            default => ['cle' => 'retard', 'libelle' => sprintf('Échue %d j', $joursDeRetard)],
        };
    }

    /**
     * Message de relance prêt à envoyer, sans donnée interne : un seul
     * message par client, qui reprend chacun de ses documents dus.
     *
     * @param list<array{document: string, reste: int, echeance: ?\DateTimeImmutable}> $dus
     */
    public static function messageRelance(string $client, array $dus): string
    {
        $lignes = array_map(static fn (array $d): string => sprintf(
            '- %s : %s F%s',
            $d['document'],
            number_format($d['reste'], 0, ',', ' '),
            null === $d['echeance'] ? '' : ' (échéance du '.$d['echeance']->format('d/m/Y').')',
        ), $dus);
        $total = array_sum(array_column($dus, 'reste'));

        return sprintf(
            "Bonjour %s,\nnous vous rappelons %s :\n%s%s\nVous pouvez payer en boutique, par Orange Money ou par virement. Merci de votre confiance.\nSuccès Imprim",
            '' !== trim($client) ? trim($client) : 'cher client',
            \count($dus) > 1 ? 'les montants restant à régler' : 'le montant restant à régler',
            implode("\n", $lignes),
            \count($dus) > 1 ? "\nTotal : ".number_format($total, 0, ',', ' ').' F' : '',
        );
    }

    private function factureDe(Commandes $commande): ?Factures
    {
        $retenue = null;
        foreach ($commande->getFactures() as $facture) {
            if ($facture->estFacture() && $facture->isComptabilisee() && !$facture->estAnnulee()) {
                $retenue = $facture;
            }
        }

        return $retenue;
    }

    /**
     * Les 50 dernières commandes soldées.
     *
     * @return list<array<string, mixed>>
     */
    private function payees(): array
    {
        /** @var list<Commandes> $commandes */
        $commandes = $this->em->createQueryBuilder()
            ->select('commande', 'client')
            ->from(Commandes::class, 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('commande.statut = true')
            ->andWhere('commande.totalTtc > 0')
            ->orderBy('commande.id', 'DESC')
            // La colonne statut_paiement n'est pas toujours à jour : on recalcule depuis les paiements.
            ->setMaxResults(120)
            ->getQuery()
            ->getResult();

        $lignes = [];
        foreach ($commandes as $commande) {
            if ($commande->getTotalPaye() < (int) $commande->getTotalTtc()) {
                continue;
            }
            $facture = $this->factureDe($commande);
            $lignes[] = ['commande' => $commande, 'facture' => $facture, 'echeance' => self::echeance($facture, $commande), 'jours' => 0, 'reste' => 0];
            if (\count($lignes) >= 50) {
                break;
            }
        }

        return $lignes;
    }

    /**
     * Dernière relance de chaque commande, manuelle ou automatique.
     *
     * @param list<Commandes> $commandes
     *
     * @return array<int, array{canal: string, date: \DateTimeImmutable}>
     */
    private function dernieresRelances(array $commandes): array
    {
        if ([] === $commandes) {
            return [];
        }
        $dernieres = [];
        $retenir = static function (int $commande, string $canal, \DateTimeImmutable $date) use (&$dernieres): void {
            if (!isset($dernieres[$commande]) || $dernieres[$commande]['date'] < $date) {
                $dernieres[$commande] = ['canal' => ModeleMessage::CANAUX_LABELS[$canal] ?? $canal, 'date' => $date];
            }
        };
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(r.commande) AS commande', 'r.canal', 'r.envoyeeLe AS date')
            ->from(RelanceCreance::class, 'r')
            ->andWhere('r.commande IN (:commandes)')
            ->setParameter('commandes', $commandes)
            ->getQuery()
            ->getArrayResult() as $r) {
            $retenir((int) $r['commande'], $r['canal'], $r['date']);
        }
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(r.commande) AS commande', 'r.canal', 'r.dateEnvoi AS date')
            ->from(RappelPaiementEnvoye::class, 'r')
            ->andWhere('r.commande IN (:commandes)')
            ->setParameter('commandes', $commandes)
            ->getQuery()
            ->getArrayResult() as $r) {
            $retenir((int) $r['commande'], $r['canal'], $r['date']);
        }

        return $dernieres;
    }
}
