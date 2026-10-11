<?php

namespace App\Service;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\ModeleMessage;
use App\Entity\Paiements;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RelanceCreance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiche client 360° (maquette 33) : chiffres clés, historique de tout ce
 * qui s'est passé avec le client, devis, factures et fichiers.
 *
 * Les montants encaissés et le solde ne sont rendus qu'avec les droits
 * correspondants : le contrôleur choisit ce qui est montré.
 */
final class FicheClient360
{
    public const STATUTS_DEVIS = [
        Devis::STATUT_BROUILLON => 'Brouillon',
        Devis::STATUT_ENVOYE => 'Envoyé',
        Devis::STATUT_ACCEPTE => 'Accepté',
        Devis::STATUT_REFUSE => 'Refusé',
        Devis::STATUT_EXPIRE => 'Expiré',
        Devis::STATUT_CONVERTI => 'Converti en commande',
        Devis::STATUT_ANNULE => 'Annulé',
    ];

    public const STATUTS_TRAVAUX = [
        'preparation' => 'en préparation',
        'livraison' => 'prête ou en livraison',
        'livree' => 'livrée',
        'annulee' => 'annulée',
        'vide' => 'sans ligne',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(Clients $client, \DateTimeImmutable $maintenant, bool $voirPaiements): array
    {
        /** @var list<Commandes> $commandes */
        $commandes = $this->em->createQueryBuilder()
            ->select('commande', 'paiement')
            ->from(Commandes::class, 'commande')
            ->leftJoin('commande.paiements', 'paiement')
            ->andWhere('commande.clients = :client')
            ->andWhere('commande.deleted = false')
            ->setParameter('client', $client)
            ->orderBy('commande.dateCommande', 'DESC')
            ->getQuery()
            ->getResult();
        /** @var list<Devis> $devis */
        $devis = $this->em->getRepository(Devis::class)->findBy(['clients' => $client], ['dateDevis' => 'DESC']);

        $actives = array_values(array_filter($commandes, static fn (Commandes $c): bool => 'annulee' !== $c->getStatutTravaux()));
        $il_y_a_12_mois = $maintenant->modify('-12 months');
        $il_y_a_24_mois = $maintenant->modify('-24 months');
        $ca = 0;
        $caPrecedent = 0;
        foreach ($actives as $commande) {
            $date = $commande->getDateCommande();
            if (null === $date) {
                continue;
            }
            if ($date >= $il_y_a_12_mois) {
                $ca += (int) $commande->getTotalTtc();
            } elseif ($date >= $il_y_a_24_mois) {
                $caPrecedent += (int) $commande->getTotalTtc();
            }
        }

        $soldeDu = 0;
        $echues = 0;
        foreach ($this->creances->donnees($maintenant)['lignes'] as $ligne) {
            if ($ligne['commande']->getClients() === $client) {
                $soldeDu += $ligne['reste'];
                $echues += $ligne['jours'] > 0 ? 1 : 0;
            }
        }
        $derniere = $actives[0] ?? null;

        return [
            'kpi' => [
                'ca' => $ca,
                'evolution' => $caPrecedent > 0 ? (int) round(($ca - $caPrecedent) * 100 / $caPrecedent) : null,
                'commandes' => \count($actives),
                'derniere_jours' => null === $derniere?->getDateCommande() ? null : (int) $derniere->getDateCommande()->setTime(0, 0)->diff($maintenant->setTime(0, 0))->days,
                'solde_du' => $soldeDu,
                'echues' => $echues,
                'panier_moyen' => [] === $actives ? 0 : intdiv(array_sum(array_map(static fn (Commandes $c): int => (int) $c->getTotalTtc(), $actives)), \count($actives)),
                'devis_ouverts' => \count(array_filter($devis, static fn (Devis $d): bool => \in_array($d->getStatut(), [Devis::STATUT_BROUILLON, Devis::STATUT_ENVOYE], true))),
            ],
            'historique' => \array_slice($this->historique($commandes, $devis, $voirPaiements), 0, 40),
            'commandes' => $commandes,
            'devis' => $devis,
            'factures' => $this->factures($commandes),
            'fichiers' => $this->fichiers($commandes),
        ];
    }

    /**
     * Tout ce qui s'est passé avec le client, le plus récent d'abord.
     *
     * @param list<Commandes> $commandes
     * @param list<Devis>     $devis
     *
     * @return list<array{date: \DateTimeInterface, icone: string, titre: string, detail: ?string, montant: ?int, lien: ?array{route: string, parametres: array<string, mixed>}}>
     */
    private function historique(array $commandes, array $devis, bool $voirPaiements): array
    {
        $evenements = [];
        foreach ($devis as $d) {
            if (null === $d->getDateDevis()) {
                continue;
            }
            $evenements[] = [
                'date' => $d->getDateDevis(),
                'icone' => 'fa-file-signature',
                'titre' => sprintf('Devis %s · %s', $d->getNumero() ?? '#'.$d->getId(), mb_strtolower(self::STATUTS_DEVIS[$d->getStatut()] ?? $d->getStatut())),
                'detail' => $d->getAgents()?->getUsername(),
                'montant' => $d->getTotalTtc(),
                'lien' => ['route' => 'app_devis_show', 'parametres' => ['id' => $d->getId()]],
            ];
        }
        foreach ($commandes as $c) {
            if (null === $c->getDateCommande()) {
                continue;
            }
            $evenements[] = [
                'date' => $c->getDateCommande(),
                'icone' => 'fa-box',
                'titre' => sprintf('Commande %s · %s', $c->getNumero(), self::STATUTS_TRAVAUX[$c->getStatutTravaux()] ?? $c->getStatutTravaux()),
                'detail' => $c->getAgents()?->getUsername(),
                'montant' => (int) $c->getTotalTtc(),
                'lien' => ['route' => 'app_commandes_show', 'parametres' => ['id' => $c->getId()]],
            ];
            if ($voirPaiements) {
                foreach ($c->getPaiements() as $p) {
                    if (null === $p->getDate() || \in_array($p->getStatut(), [Paiements::STATUT_ANNULE, Paiements::STATUT_REJETE], true)) {
                        continue;
                    }
                    $evenements[] = [
                        'date' => $p->getDate(),
                        'icone' => 'fa-money-bill-wave',
                        'titre' => sprintf('Paiement %s · %s%s', $c->getNumero(), mb_strtolower($p->getModeLabel()), $p->estValide() ? '' : ' (à valider)'),
                        'detail' => $p->getEncaissePar()?->getUsername(),
                        'montant' => $p->getMontant(),
                        'lien' => $p->getId() ? ['route' => 'app_paiements_show', 'parametres' => ['id' => $p->getId()]] : null,
                    ];
                }
            }
        }
        if ([] !== $commandes) {
            foreach ($this->relances($commandes) as $r) {
                $evenements[] = [
                    'date' => $r['date'],
                    'icone' => 'fa-bell',
                    'titre' => sprintf('Relance %s · %s', ModeleMessage::CANAUX_LABELS[$r['canal']] ?? $r['canal'], $r['numero']),
                    'detail' => $r['automatique'] ? 'rappel automatique' : null,
                    'montant' => null,
                    'lien' => null,
                ];
            }
        }
        usort($evenements, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $evenements;
    }

    /**
     * @param list<Commandes> $commandes
     *
     * @return list<array{date: \DateTimeInterface, canal: string, numero: ?string, automatique: bool}>
     */
    private function relances(array $commandes): array
    {
        $relances = [];
        foreach ($this->em->getRepository(RelanceCreance::class)->findBy(['commande' => $commandes]) as $r) {
            $relances[] = ['date' => $r->getEnvoyeeLe(), 'canal' => $r->getCanal(), 'numero' => $r->getCommande()?->getNumero(), 'automatique' => false];
        }
        foreach ($this->em->getRepository(RappelPaiementEnvoye::class)->findBy(['commande' => $commandes]) as $r) {
            if (null !== $r->getDateEnvoi()) {
                $relances[] = ['date' => $r->getDateEnvoi(), 'canal' => $r->getCanal(), 'numero' => $r->getCommande()?->getNumero(), 'automatique' => true];
            }
        }

        return $relances;
    }

    /**
     * @param list<Commandes> $commandes
     *
     * @return list<\App\Entity\Factures>
     */
    private function factures(array $commandes): array
    {
        $factures = [];
        foreach ($commandes as $commande) {
            foreach ($commande->getFactures() as $facture) {
                if (!$facture->estBrouillon()) {
                    $factures[] = $facture;
                }
            }
        }
        usort($factures, static fn ($a, $b): int => $b->getDateFacture() <=> $a->getDateFacture());

        return $factures;
    }

    /**
     * Fichiers reçus sur les commandes du client, versions corrigées comprises.
     *
     * @param list<Commandes> $commandes
     *
     * @return list<CommandeDetailFichier>
     */
    private function fichiers(array $commandes): array
    {
        if ([] === $commandes) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('fichier', 'detail', 'commande')
            ->from(CommandeDetailFichier::class, 'fichier')
            ->innerJoin('fichier.commandeDetail', 'detail')
            ->innerJoin('detail.commande', 'commande')
            ->andWhere('commande IN (:commandes)')
            ->andWhere('fichier.termineLe IS NOT NULL')
            ->setParameter('commandes', $commandes)
            ->orderBy('fichier.termineLe', 'DESC')
            ->setMaxResults(60)
            ->getQuery()
            ->getResult();
    }
}
