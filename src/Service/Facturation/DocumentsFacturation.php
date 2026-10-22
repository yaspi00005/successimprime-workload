<?php

namespace App\Service\Facturation;

use App\Entity\Avoir;
use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\Paiements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Documents de facturation du mois sur une seule liste (maquette FD06) :
 * factures, proformas, avoirs, reçus de paiement, bons de livraison et
 * devis, chacun avec son statut et la commande à laquelle il est lié.
 *
 * Le contrôleur dit quels types la personne a le droit de voir : les
 * reçus et l'encaissé seulement avec le droit « Voir les paiements », les
 * devis avec « Devis », les bons de livraison avec « Livraisons » ou
 * « Commandes ».
 */
final class DocumentsFacturation
{
    public const FACTURE = 'facture';
    public const PROFORMA = 'proforma';
    public const AVOIR = 'avoir';
    public const RECU = 'recu';
    public const BL = 'bl';
    public const DEVIS = 'devis';

    /** Onglets, dans l'ordre de la maquette. */
    public const TYPES = [
        self::FACTURE => 'Factures',
        self::AVOIR => 'Avoirs',
        self::RECU => 'Reçus',
        self::PROFORMA => 'Proformas',
        self::BL => 'Bons de livraison',
        self::DEVIS => 'Devis',
    ];

    public const LIBELLES = [
        self::FACTURE => 'Facture',
        self::PROFORMA => 'Proforma',
        self::AVOIR => 'Avoir',
        self::RECU => 'Reçu de paiement',
        self::BL => 'Bon de livraison',
        self::DEVIS => 'Devis',
    ];

    private const LIMITE = 400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<string> $types types permis à la personne
     *
     * @return list<array{type: string, libelle: string, numero: string, client: string, date: \DateTimeInterface, montant: int|null, montantTexte: ?string, statut: string, classe: string, commande: ?string, commandeUrl: ?string, url: ?string, pdf: ?string}>
     */
    public function documents(\DateTimeImmutable $mois, array $types, ?string $recherche = null, bool $voirPaiements = true): array
    {
        [$debut, $fin] = self::bornes($mois);
        $lignes = [];
        $aujourdhui = new \DateTimeImmutable('today');

        if (array_intersect([self::FACTURE, self::PROFORMA], $types)) {
            $factures = $this->em->createQueryBuilder()->select('f', 'c', 'cl')->from(Factures::class, 'f')
                ->leftJoin('f.commande', 'c')->leftJoin('c.clients', 'cl')
                ->where('f.dateFacture >= :d AND f.dateFacture < :f')->setParameter('d', $debut)->setParameter('f', $fin)
                ->orderBy('f.dateFacture', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
            foreach ($factures as $f) {
                /** @var Factures $f */
                $type = $f->estFacture() ? self::FACTURE : self::PROFORMA;
                if (!\in_array($type, $types, true)) {
                    continue;
                }
                [$statut, $classe] = self::statutFacture($f, $aujourdhui, $voirPaiements);
                $lignes[] = $this->ligne($type, $f->getNumero() ?? 'Brouillon', $f->getCommande(), $f->isFacturerAUnTiers() ? $f->getNomFacturation() : null, $f->getDateFacture() ?? $aujourdhui, $f->getTotalTtc(), null, $statut, $classe,
                    $this->urls->generate('app_factures_show', ['id' => $f->getId()]), $this->urls->generate('app_factures_pdf', ['id' => $f->getId()]));
            }
        }

        if (\in_array(self::AVOIR, $types, true)) {
            $avoirs = $this->em->createQueryBuilder()->select('a', 'f', 'c')->from(Avoir::class, 'a')
                ->join('a.facture', 'f')->leftJoin('f.commande', 'c')
                ->where('a.creeLe >= :d AND a.creeLe < :f')->setParameter('d', $debut)->setParameter('f', $fin)
                ->orderBy('a.creeLe', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
            foreach ($avoirs as $a) {
                /** @var Avoir $a */
                $statut = match ($a->getSuite()) {
                    Avoir::DEDUIRE => 'Validé · déduit',
                    Avoir::REMBOURSER => 'Validé · remboursé',
                    default => 'Validé · en crédit',
                };
                $lignes[] = $this->ligne(self::AVOIR, (string) $a->getNumero(), $a->getFacture()?->getCommande(), $a->getClient()?->getNomComplet(), $a->getCreeLe() ?? $aujourdhui, -$a->getTotal(), null, $statut, 'avoir',
                    $this->urls->generate('app_factures_avoir_show', ['id' => $a->getId()]), $this->urls->generate('app_factures_avoir_pdf', ['id' => $a->getId()]));
            }
        }

        if (\in_array(self::RECU, $types, true)) {
            $paiements = $this->em->createQueryBuilder()->select('p', 'c', 'cl')->from(Paiements::class, 'p')
                ->leftJoin('p.commande', 'c')->leftJoin('c.clients', 'cl')
                ->where('p.date >= :d AND p.date < :f')->andWhere('p.mode NOT IN (:sans)')
                ->setParameter('d', $debut)->setParameter('f', $fin)->setParameter('sans', Paiements::MODES_SANS_ENCAISSEMENT)
                ->orderBy('p.date', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
            foreach ($paiements as $p) {
                /** @var Paiements $p */
                [$statut, $classe] = match ($p->getStatut()) {
                    Paiements::STATUT_VALIDE => ['Validé · '.(Paiements::MODES_LABELS[$p->getMode()] ?? $p->getMode()), 'ok'],
                    Paiements::STATUT_EN_ATTENTE => ['À confirmer', 'attente'],
                    Paiements::STATUT_REJETE => ['Rejeté', 'retard'],
                    default => ['Annulé', 'brouillon'],
                };
                $numero = $p->getNumeroRecu() ?? ($p->estValide() ? Paiements::numeroRecuDe((int) $p->getId()) : 'Paiement #'.$p->getId());
                $lignes[] = $this->ligne(self::RECU, $numero, $p->getCommande(), null, $p->getDate() ?? $aujourdhui, (int) $p->getMontant(), null, $statut, $classe,
                    null !== $p->getCommande() ? $this->urls->generate('app_commandes_show', ['id' => $p->getCommande()->getId()]) : null,
                    $p->estValide() ? $this->urls->generate('app_caisse_recu', ['id' => $p->getId()]) : null);
            }
        }

        if (\in_array(self::BL, $types, true)) {
            $bons = $this->em->createQueryBuilder()->select('b', 'c', 'cl')->from(BonLivraison::class, 'b')
                ->leftJoin('b.commande', 'c')->leftJoin('c.clients', 'cl')
                ->where('b.creeLe >= :d AND b.creeLe < :f')->setParameter('d', $debut)->setParameter('f', $fin)
                ->orderBy('b.creeLe', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
            foreach ($bons as $b) {
                /** @var BonLivraison $b */
                [$statut, $classe] = match ($b->getStatut()) {
                    BonLivraison::STATUT_LIVRE => [null !== $b->getSignature() ? 'Livré · signé' : 'Livré', 'ok'],
                    BonLivraison::STATUT_VALIDE => ['À livrer', 'envoye'],
                    BonLivraison::STATUT_ANNULE => ['Annulé', 'brouillon'],
                    default => ['Brouillon', 'brouillon'],
                };
                $articles = [];
                foreach ($b->getLignes() as $l) {
                    $articles[] = $l->getDesignation().' × '.$l->getQuantiteCommandee();
                }
                $resume = \count($articles) > 1 ? \count($articles).' articles' : ($articles[0] ?? '—');
                $lignes[] = $this->ligne(self::BL, (string) $b->getNumero(), $b->getCommande(), null, $b->getCreeLe() ?? $aujourdhui, null, $resume, $statut, $classe,
                    $this->urls->generate('app_bons_livraison_show', ['id' => $b->getId()]), null);
            }
        }

        if (\in_array(self::DEVIS, $types, true)) {
            $devis = $this->em->createQueryBuilder()->select('d', 'cl')->from(Devis::class, 'd')->leftJoin('d.clients', 'cl')
                ->where('d.dateDevis >= :d AND d.dateDevis < :f')->andWhere('d.deleted = false')
                ->setParameter('d', $debut)->setParameter('f', $fin)
                ->orderBy('d.dateDevis', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
            foreach ($devis as $d) {
                /** @var Devis $d */
                $classe = match ($d->getStatut()) {
                    Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI => 'ok',
                    Devis::STATUT_ENVOYE, Devis::STATUT_VU => 'envoye',
                    Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE => 'retard',
                    default => 'brouillon',
                };
                $lignes[] = $this->ligne(self::DEVIS, (string) $d->getNumero(), $d->getCommande(), $d->getClients()?->getNomComplet(), $d->getDateDevis() ?? $aujourdhui, $d->getTotalTtc(), null, $d->getStatutLibelle(), $classe,
                    $this->urls->generate('app_devis_show', ['id' => $d->getId()]), $this->urls->generate('app_devis_pdf', ['id' => $d->getId()]));
            }
        }

        if (null !== $recherche && '' !== trim($recherche)) {
            $r = mb_strtolower(trim($recherche));
            $lignes = array_values(array_filter($lignes, static fn (array $l): bool => str_contains(mb_strtolower($l['numero'].' '.$l['client'].' '.$l['commande']), $r)));
        }
        usort($lignes, static fn (array $a, array $b): int => [$b['date'], $b['numero']] <=> [$a['date'], $a['numero']]);

        return $lignes;
    }

    /**
     * Les quatre chiffres du haut : facturé dans le mois, encaissé (dont à
     * confirmer), à encaisser et échu (toutes factures validées ouvertes).
     *
     * @return array{facture: int, nbFactures: int, avoirs: int, encaisse: int, aConfirmer: int, aEncaisser: int, nbOuvertes: int, echu: int, nbEchues: int}
     */
    public function chiffres(\DateTimeImmutable $mois, bool $avecEncaissements): array
    {
        [$debut, $fin] = self::bornes($mois);
        $facture = $this->em->createQueryBuilder()->select('COALESCE(SUM(f.totalTtc), 0) AS total', 'COUNT(f.id) AS nb')->from(Factures::class, 'f')
            ->where('f.typeDocument = :t AND f.etat = :e')->andWhere('f.dateFacture >= :d AND f.dateFacture < :f')
            ->setParameter('t', Factures::TYPE_FACTURE)->setParameter('e', Factures::ETAT_EMISE)->setParameter('d', $debut)->setParameter('f', $fin)
            ->getQuery()->getSingleResult();
        $avoirs = (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(a.total), 0)')->from(Avoir::class, 'a')
            ->where('a.creeLe >= :d AND a.creeLe < :f')->setParameter('d', $debut)->setParameter('f', $fin)
            ->getQuery()->getSingleScalarResult();
        $ouvertes = $this->em->createQueryBuilder()->select('COALESCE(SUM(f.resteAPayer), 0) AS reste', 'COUNT(f.id) AS nb')->from(Factures::class, 'f')
            ->where('f.typeDocument = :t AND f.etat = :e AND f.resteAPayer > 0')
            ->setParameter('t', Factures::TYPE_FACTURE)->setParameter('e', Factures::ETAT_EMISE)
            ->getQuery()->getSingleResult();
        $echues = $this->em->createQueryBuilder()->select('COALESCE(SUM(f.resteAPayer), 0) AS reste', 'COUNT(f.id) AS nb')->from(Factures::class, 'f')
            ->where('f.typeDocument = :t AND f.etat = :e AND f.resteAPayer > 0 AND f.dateEcheance < :auj')
            ->setParameter('t', Factures::TYPE_FACTURE)->setParameter('e', Factures::ETAT_EMISE)->setParameter('auj', new \DateTimeImmutable('today'))
            ->getQuery()->getSingleResult();
        $encaisse = $aConfirmer = 0;
        if ($avecEncaissements) {
            foreach ([Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE] as $statut) {
                $somme = (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(p.montant), 0)')->from(Paiements::class, 'p')
                    ->where('p.statut = :s AND p.mode NOT IN (:sans)')->andWhere('p.date >= :d AND p.date < :f')
                    ->setParameter('s', $statut)->setParameter('sans', Paiements::MODES_SANS_ENCAISSEMENT)->setParameter('d', $debut)->setParameter('f', $fin)
                    ->getQuery()->getSingleScalarResult();
                Paiements::STATUT_VALIDE === $statut ? $encaisse = $somme : $aConfirmer = $somme;
            }
        }

        return [
            'facture' => (int) $facture['total'], 'nbFactures' => (int) $facture['nb'], 'avoirs' => $avoirs,
            'encaisse' => $encaisse, 'aConfirmer' => $aConfirmer,
            'aEncaisser' => (int) $ouvertes['reste'], 'nbOuvertes' => (int) $ouvertes['nb'],
            'echu' => (int) $echues['reste'], 'nbEchues' => (int) $echues['nb'],
        ];
    }

    /**
     * Statut d'une facture comme sur la maquette : payée, échue avec le
     * reste, validée avec l'échéance, brouillon ou annulée.
     *
     * @return array{0: string, 1: string} libellé, classe du badge
     */
    public static function statutFacture(Factures $f, \DateTimeImmutable $aujourdhui, bool $voirPaiements = true): array
    {
        // Sans « Voir les paiements », ni payé ni reste : seulement validée ou échue.
        $voir = $voirPaiements;
        if ($f->estAnnulee()) {
            return ['Annulée', 'brouillon'];
        }
        if ($f->estBrouillon()) {
            return ['Brouillon', 'brouillon'];
        }
        if ($f->estProforma()) {
            return ['Émise', 'envoye'];
        }
        $reste = $f->getResteAPayer();
        if ($reste <= 0) {
            return $voir ? ['Payée', 'ok'] : ['Validée', 'envoye'];
        }
        $echeance = $f->getDateEcheance();
        if (null !== $echeance && $echeance < $aujourdhui) {
            return [$voir ? 'Échue · reste '.number_format($reste, 0, ',', ' ').' F' : 'Échue', 'retard'];
        }
        if ($voir && $f->getMontantPaye() > 0) {
            return ['Payée en partie · reste '.number_format($reste, 0, ',', ' ').' F', 'attente'];
        }

        return [null !== $echeance ? 'Validée · échéance '.$echeance->format('d/m') : 'Validée', 'envoye'];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public static function bornes(\DateTimeImmutable $mois): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);

        return [$debut, $debut->modify('+1 month')];
    }

    /** @return array{type: string, libelle: string, numero: string, client: string, date: \DateTimeInterface, montant: int|null, montantTexte: ?string, statut: string, classe: string, commande: ?string, commandeUrl: ?string, url: ?string, pdf: ?string} */
    private function ligne(string $type, string $numero, ?Commandes $commande, ?string $client, \DateTimeInterface $date, ?int $montant, ?string $montantTexte, string $statut, string $classe, ?string $url, ?string $pdf): array
    {
        return [
            'type' => $type, 'libelle' => self::LIBELLES[$type], 'numero' => $numero,
            'client' => $client ?? $commande?->getClients()?->getNomComplet() ?? '—',
            'date' => $date, 'montant' => $montant, 'montantTexte' => $montantTexte, 'statut' => $statut, 'classe' => $classe,
            'commande' => $commande?->getNumero(),
            'commandeUrl' => null !== $commande ? $this->urls->generate('app_commandes_show', ['id' => $commande->getId()]) : null,
            'url' => $url, 'pdf' => $pdf,
        ];
    }
}
