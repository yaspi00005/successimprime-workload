<?php

namespace App\Service;

use App\Entity\ActionCaisse;
use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\Conception;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\ModeleMessage;
use App\Entity\ModificationClient;
use App\Entity\NoteClient;
use App\Entity\Paiements;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RelanceCreance;
use App\Service\Pointage\Pointages;
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
    /** Filtres de l'historique d'activité (maquette K03). */
    public const CATEGORIES = [
        'commandes' => 'Commandes',
        'paiements' => 'Paiements',
        'echanges' => 'Échanges',
        'bat_livraisons' => 'BAT et livraisons',
        'modifications' => 'Modifications',
    ];

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
            'historique' => $this->historique($client, $commandes, $devis, $voirPaiements),
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
     * @return list<array{date: \DateTimeInterface, categorie: string, icone: string, titre: string, detail: ?string, montant: ?int, lien: ?array{route: string, parametres: array<string, mixed>}}>
     */
    private function historique(Clients $client, array $commandes, array $devis, bool $voirPaiements): array
    {
        $evenements = [];
        $qui = static fn (?\App\Entity\User $u): ?string => null !== $u ? Pointages::nom($u) : null;
        foreach ($devis as $d) {
            if (null === $d->getDateDevis()) {
                continue;
            }
            $evenements[] = [
                'date' => $d->getDateDevis(),
                'categorie' => 'commandes',
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
                'categorie' => 'commandes',
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
                        'categorie' => 'paiements',
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
                    'categorie' => 'echanges',
                    'icone' => 'fa-bell',
                    'titre' => sprintf('Relance %s · %s', ModeleMessage::CANAUX_LABELS[$r['canal']] ?? $r['canal'], $r['numero']),
                    'detail' => $r['automatique'] ? 'rappel automatique' : null,
                    'montant' => null,
                    'lien' => null,
                ];
            }
        }

        // Notes et comptes rendus d'appel.
        foreach ($this->em->getRepository(NoteClient::class)->findBy(['client' => $client]) as $n) {
            $evenements[] = [
                'date' => $n->getLe(),
                'categorie' => 'echanges',
                'icone' => 'appel' === $n->getType() ? 'fa-phone' : ('visite' === $n->getType() ? 'fa-walking' : 'fa-sticky-note'),
                'titre' => $n->getTexte(),
                'detail' => implode(' · ', array_filter([$n->getTypeLabel(), $n->getRappelLe() ? 'rappel le '.$n->getRappelLe()->format('d/m') : null, $qui($n->getAuteur())])),
                'montant' => null,
                'lien' => null,
            ];
        }
        // Actions faites (appels, relances, prospection) liées au client.
        foreach ($this->em->getRepository(ActionCaisse::class)->findBy(['client' => $client, 'etat' => ActionCaisse::FAITE]) as $a) {
            if (null === $a->getFaiteLe()) {
                continue;
            }
            $evenements[] = [
                'date' => $a->getFaiteLe(),
                'categorie' => 'echanges',
                'icone' => 'fa-phone',
                'titre' => $a->getLibelle().(null !== $a->getResultat() ? ' · '.mb_strtolower(ActionCaisse::RESULTATS[$a->getResultat()] ?? $a->getResultat()) : ''),
                'detail' => implode(' · ', array_filter([$a->getNote(), $qui($a->getFaitePar())])),
                'montant' => null,
                'lien' => null,
            ];
        }
        // BAT (envoi, retour, validation) et livraisons.
        if ([] !== $commandes) {
            $conceptions = $this->em->createQueryBuilder()->select('k', 'ctl', 'det')->from(Conception::class, 'k')
                ->join('k.controle', 'ctl')->join('ctl.commandeDetail', 'det')
                ->where('det.commande IN (:c)')->setParameter('c', $commandes)
                ->getQuery()->getResult();
            foreach ($conceptions as $k) {
                $commande = $k->getControle()->getCommandeDetail()?->getCommande();
                foreach ($k->getEvenements() as $e) {
                    $titre = match ($e['type']) {
                        'bat' => $e['titre'],
                        'retour' => 'Corrections demandées sur le BAT',
                        'valide' => 'BAT validé',
                        default => null,
                    };
                    if (null === $titre) {
                        continue;
                    }
                    $evenements[] = [
                        'date' => $e['le'],
                        'categorie' => 'bat_livraisons',
                        'icone' => 'valide' === $e['type'] ? 'fa-check' : 'fa-pen-ruler',
                        'titre' => $titre.(null !== $commande ? ' · '.$commande->getNumero() : ''),
                        'detail' => $e['detail'],
                        'montant' => null,
                        'lien' => null !== $commande ? ['route' => 'app_commandes_show', 'parametres' => ['id' => $commande->getId()]] : null,
                    ];
                }
            }
            foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $commandes, 'statut' => BonLivraison::STATUT_LIVRE]) as $b) {
                if (null === $b->getLivreLe()) {
                    continue;
                }
                $evenements[] = [
                    'date' => $b->getLivreLe(),
                    'categorie' => 'bat_livraisons',
                    'icone' => 'fa-truck',
                    'titre' => sprintf('Commande %s livrée%s', $b->getCommande()?->getNumero(), $b->getNomReceptionnaire() ? ' et remise à '.$b->getNomReceptionnaire() : ''),
                    'detail' => $qui($b->getLivrePar() ?? $b->getLivreur()),
                    'montant' => null,
                    'lien' => null,
                ];
            }
        }
        // Journal des modifications de la fiche.
        foreach ($this->em->getRepository(ModificationClient::class)->findBy(['client' => $client]) as $m) {
            $evenements[] = [
                'date' => $m->getLe(),
                'categorie' => 'modifications',
                'icone' => 'fa-pen',
                'titre' => match (true) {
                    null === $m->getAvant() => sprintf('%s : %s', $m->getChamp(), $m->getApres() ?? '—'),
                    null === $m->getApres() => sprintf('%s : %s', $m->getChamp(), $m->getAvant()),
                    'contacts' === $m->getCategorie() => sprintf('%s : %s → %s', $m->getChamp(), $m->getAvant(), $m->getApres()),
                    default => sprintf('%s modifié : %s → %s', $m->getChamp(), $m->getAvant(), $m->getApres()),
                },
                'detail' => implode(' · ', array_filter([$m->getDepuisLabel(), $m->getAuteur()])),
                'montant' => null,
                'lien' => ['route' => 'app_clients_journal', 'parametres' => ['publicId' => $client->getPublicId()]],
            ];
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
