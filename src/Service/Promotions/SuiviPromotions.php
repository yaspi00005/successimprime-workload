<?php

namespace App\Service\Promotions;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteFidelite;
use App\Entity\DevisDetails;
use App\Entity\Factures;
use App\Entity\Promotion;
use App\Service\CalculateurCoutRevient;
use App\Service\SegmentsClients;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi d'une promotion (docs/promotions.md) : CA réalisé avec elle, son
 * coût, les clients touchés, l'effet sur le segment et la marge restante.
 *
 * Règle du propriétaire : un montant n'est calculé que pour qui a le droit
 * de le voir (DroitsStatistiques : « ventes » pour le CA et les remises,
 * « marges » pour la marge). Sans droit, seuls les nombres de lignes, de
 * commandes et de clients sont donnés.
 */
final class SuiviPromotions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculateurCoutRevient $calculateur,
        private readonly Promotions $promotions,
    ) {
    }

    /**
     * @param array{ventes: bool, marges: bool} $droits
     *
     * @return array{comptes: array{lignes: int, devis: int, commandes: int, facturees: int, clients: int}, chiffres: ?array<string, mixed>, periode: array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, jours: int}}
     */
    public function suivi(Promotion $p, array $droits, \DateTimeImmutable $maintenant): array
    {
        $lignes = $this->lignes($p);
        $commandes = [];
        $facturees = [];
        $clients = [];
        foreach ($lignes as $l) {
            $commandes[$l['commande']] = true;
            if ($l['facturee']) {
                $facturees[$l['commande']] = true;
            }
            if (null !== $l['client']) {
                $clients[$l['client']] = true;
            }
        }
        $fin = min($p->getFin(), $maintenant->setTime(0, 0));
        $periode = ['debut' => $p->getDebut(), 'fin' => $fin, 'jours' => max(0, (int) $p->getDebut()->diff($fin)->format('%r%a') + 1)];
        $comptes = [
            'lignes' => \count($lignes),
            'devis' => (int) $this->em->createQueryBuilder()->select('COUNT(d.id)')->from(DevisDetails::class, 'd')->where('d.promotion = :p')->setParameter('p', $p)->getQuery()->getSingleScalarResult(),
            'commandes' => \count($commandes),
            'facturees' => \count($facturees),
            'clients' => \count($clients),
        ];
        if (!$droits['ventes'] && !$droits['marges']) {
            return ['comptes' => $comptes, 'chiffres' => null, 'periode' => $periode];
        }

        $reglages = $this->promotions->reglages();
        $factureesLignes = array_values(array_filter($lignes, static fn (array $l): bool => $l['facturee']));
        $donnees = [];
        if ($droits['ventes']) {
            $donnees['ca'] = array_sum(array_column($factureesLignes, 'ht'));
            $donnees['remises'] = array_sum(array_column($factureesLignes, 'promotion'));
            $donnees['remises_en_cours'] = array_sum(array_column($lignes, 'promotion')) - $donnees['remises'];
            $donnees['ca_periode'] = $periode['jours'] > 0 ? $this->caFacture($periode['debut'], $periode['fin']) : 0;
            if ($periode['jours'] > 0) {
                $avantFin = $p->getDebut()->modify('-1 day');
                $avantDebut = $avantFin->modify('-'.($periode['jours'] - 1).' days');
                $membres = $this->membresDuSegment($p);
                $donnees['ca_segment'] = $this->caCommandes($membres, $periode['debut'], $periode['fin']);
                $donnees['ca_segment_avant'] = $this->caCommandes($membres, $avantDebut, $avantFin);
                $donnees['avant'] = ['debut' => $avantDebut, 'fin' => $avantFin];
            }
        }
        if ($droits['marges']) {
            $chiffre = 0;
            $cout = 0;
            $total = 0;
            foreach ($factureesLignes as $l) {
                $total += $l['ht'];
                $c = $this->cout($l['entite']);
                if (null !== $c) {
                    $chiffre += $l['ht'];
                    $cout += $c;
                }
            }
            $donnees['ca_chiffre'] = $chiffre;
            $donnees['cout'] = $cout;
            $donnees['ca_marges'] = $total;
        }

        return ['comptes' => $comptes, 'chiffres' => self::indicateurs($donnees, $reglages['alerte_cout_pct'], $reglages['alerte_marge_pct']) + $donnees, 'periode' => $periode];
    }

    /**
     * Les indicateurs et leurs alertes, à partir des montants permis.
     *
     *  - coût : remises de la promotion rapportées au CA facturé de toute
     *    l'entreprise sur la période (indicateur 2 : alerte au-delà de 5 %) ;
     *  - marge : sur les lignes facturées dont le coût de revient est connu
     *    (alerte sous 35 %) ;
     *  - effet : commandes du segment pendant la promotion comparées à la
     *    même durée juste avant.
     *
     * @param array<string, mixed> $d
     *
     * @return array{taux_cout: ?float, alerte_cout: bool, marge: ?int, taux_marge: ?float, alerte_marge: bool, part_chiffree: ?int, effet: ?float}
     */
    public static function indicateurs(array $d, float $alerteCoutPct = 5.0, float $alerteMargePct = 35.0): array
    {
        $tauxCout = null;
        if (isset($d['remises'], $d['ca_periode'])) {
            $tauxCout = $d['ca_periode'] > 0 ? round($d['remises'] * 100 / $d['ca_periode'], 1) : null;
        }
        $marge = null;
        $tauxMarge = null;
        $part = null;
        if (isset($d['ca_chiffre'], $d['cout'])) {
            if ($d['ca_chiffre'] > 0) {
                $marge = $d['ca_chiffre'] - $d['cout'];
                $tauxMarge = round($marge * 100 / $d['ca_chiffre'], 1);
            }
            $part = ($d['ca_marges'] ?? 0) > 0 ? (int) round($d['ca_chiffre'] * 100 / $d['ca_marges']) : null;
        }
        $effet = null;
        if (isset($d['ca_segment'], $d['ca_segment_avant']) && $d['ca_segment_avant'] > 0) {
            $effet = round(($d['ca_segment'] - $d['ca_segment_avant']) * 100 / $d['ca_segment_avant'], 1);
        }

        return [
            'taux_cout' => $tauxCout,
            'alerte_cout' => null !== $tauxCout ? $tauxCout > $alerteCoutPct : (($d['remises'] ?? 0) > 0 && 0 === ($d['ca_periode'] ?? null)),
            'marge' => $marge,
            'taux_marge' => $tauxMarge,
            'alerte_marge' => null !== $tauxMarge && $tauxMarge < $alerteMargePct,
            'part_chiffree' => $part,
            'effet' => $effet,
        ];
    }

    /**
     * Par promotion : lignes de commande et remise accordée, pour la liste.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{lignes: int, clients: int, remises: int}>
     */
    public function resumes(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $r = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.promotion) AS promotion', 'COUNT(l.id) AS lignes', 'COUNT(DISTINCT c.clients) AS clients', 'COALESCE(SUM(l.montantPromotion), 0) AS remises')
            ->from(CommandesDetails::class, 'l')->join('l.commande', 'c')
            ->where('l.promotion IN (:ids)')->andWhere('c.deleted = false')->andWhere('l.statutProduction != :annulee')
            ->setParameter('ids', $ids)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->groupBy('l.promotion')->getQuery()->getArrayResult();
        $resultat = [];
        foreach ($r as $ligne) {
            $resultat[(int) $ligne['promotion']] = ['lignes' => (int) $ligne['lignes'], 'clients' => (int) $ligne['clients'], 'remises' => (int) $ligne['remises']];
        }

        return $resultat;
    }

    /** @return list<array{entite: CommandesDetails, commande: int, client: ?int, facturee: bool, ht: int, promotion: int}> */
    private function lignes(Promotion $p): array
    {
        /** @var list<CommandesDetails> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('l', 'c')
            ->from(CommandesDetails::class, 'l')->join('l.commande', 'c')
            ->where('l.promotion = :p')->andWhere('c.deleted = false')->andWhere('l.statutProduction != :annulee')
            ->setParameter('p', $p)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->getQuery()->getResult();
        $ids = array_values(array_unique(array_map(static fn (CommandesDetails $l): int => (int) $l->getCommande()?->getId(), $lignes)));
        $facturees = [];
        if ([] !== $ids) {
            foreach ($this->em->createQueryBuilder()->select('DISTINCT IDENTITY(f.commande) AS commande')->from(Factures::class, 'f')
                ->where('f.commande IN (:ids)')->andWhere('f.etat = :emise')->andWhere('f.typeDocument = :facture')
                ->setParameter('ids', $ids)->setParameter('emise', Factures::ETAT_EMISE)->setParameter('facture', Factures::TYPE_FACTURE)
                ->getQuery()->getArrayResult() as $f) {
                $facturees[(int) $f['commande']] = true;
            }
        }

        return array_map(static fn (CommandesDetails $l): array => [
            'entite' => $l,
            'commande' => (int) $l->getCommande()?->getId(),
            'client' => $l->getCommande()?->getClients()?->getId(),
            'facturee' => isset($facturees[(int) $l->getCommande()?->getId()]),
            'ht' => $l->getTotalHt(),
            'promotion' => $l->getMontantPromotion(),
        ], $lignes);
    }

    /** Coût de revient de la ligne : celui enregistré, sinon le calcul s'il est complet. */
    private function cout(CommandesDetails $l): ?int
    {
        if ($l->getCoutRevient() > 0) {
            return $l->getCoutRevient();
        }
        $calcul = $this->calculateur->pourLigne($l);

        return [] === $calcul['manquants'] && $calcul['total'] > 0 ? (int) $calcul['total'] : null;
    }

    private function caFacture(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(f.totalHt), 0)')->from(Factures::class, 'f')
            ->where('f.etat = :emise')->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateFacture >= :debut AND f.dateFacture < :fin')
            ->setParameter('emise', Factures::ETAT_EMISE)->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('debut', $debut->setTime(0, 0))->setParameter('fin', $fin->setTime(0, 0)->modify('+1 day'))
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Commandes (HT) des clients du segment sur la période ; null = tous les clients.
     *
     * @param list<int>|null $clients
     */
    private function caCommandes(?array $clients, \DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        if ([] === $clients) {
            return 0;
        }
        $qb = $this->em->createQueryBuilder()->select('COALESCE(SUM(c.totalHt), 0)')->from(Commandes::class, 'c')
            ->where('c.deleted = false')->andWhere('c.statut = true')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('debut', $debut->setTime(0, 0))->setParameter('fin', $fin->setTime(0, 0)->modify('+1 day'));
        if (null !== $clients) {
            $qb->andWhere('c.clients IN (:clients)')->setParameter('clients', $clients);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Clients du segment au début de la promotion (commandes d'avant le
     * début seulement), pour comparer les deux périodes sur les mêmes
     * clients ; null pour « tous les clients ».
     *
     * @return list<int>|null
     */
    private function membresDuSegment(Promotion $p): ?array
    {
        $segment = $p->getSegment();
        if (Promotion::SEGMENT_TOUS === $segment) {
            return null;
        }
        if (Promotion::SEGMENT_LISTE === $segment) {
            return array_values(array_filter(array_map(static fn (Clients $c): ?int => $c->getId(), $p->getClients()->toArray())));
        }
        if (Promotion::SEGMENT_FIDELITE === $segment) {
            $ids = [];
            foreach ($this->em->getRepository(CompteFidelite::class)->findAll() as $compte) {
                if (Promotions::seuilFranchi($compte) && null !== $compte->getClient()?->getId()) {
                    $ids[] = (int) $compte->getClient()->getId();
                }
            }

            return $ids;
        }
        $clients = $this->em->getRepository(Clients::class)->findBy(['statut' => true]);
        $debut = $p->getDebut();
        $achats = [];
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'MAX(c.dateCommande) AS derniere', 'SUM(CASE WHEN c.dateCommande >= :an THEN c.totalTtc ELSE 0 END) AS ca')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')->andWhere('c.clients IS NOT NULL')->andWhere('c.dateCommande < :debut')
            ->setParameter('an', $debut->modify('-12 months'))->setParameter('debut', $debut)
            ->groupBy('c.clients')->getQuery()->getArrayResult() as $l) {
            $achats[(int) $l['client']] = ['derniere' => new \DateTimeImmutable((string) $l['derniere']), 'ca' => (int) $l['ca']];
        }
        $membres = [];
        foreach ($clients as $c) {
            if (\in_array($segment, SegmentsClients::segmentsDe($c, $achats[(int) $c->getId()] ?? null, $debut), true)) {
                $membres[] = (int) $c->getId();
            }
        }

        return $membres;
    }
}
