<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteTresorerie;
use App\Entity\Devis;
use App\Entity\Paiements;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres de « Mes statistiques » (maquette U05) : ceux de la personne
 * sur un mois, et en bas les chiffres de l'entreprise que ses droits
 * lui laissent voir.
 */
final class MesStatistiques
{
    private const DEVIS_EMIS = [Devis::STATUT_ENVOYE, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE, Devis::STATUT_CONVERTI];
    private const DEVIS_GAGNES = [Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function pour(User $agent, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $fin = min($debut->modify('+1 month'), $maintenant->modify('+1 second'));
        // Mois precedent compare au meme jour, pour un mois en cours.
        $debutAvant = $debut->modify('-1 month');
        $finAvant = $debutAvant->modify(sprintf('+%d seconds', $fin->getTimestamp() - $debut->getTimestamp()));

        $ventes = $this->ventes($agent, $debut, $fin);
        $ventesAvant = $this->ventes($agent, $debutAvant, $finAvant);
        $devis = $this->devis($agent, $debut, $fin);
        $encaissements = $this->encaissements($agent, $debut, $fin);
        $objectif = $agent->getObjectifVentesMois();

        $parMois = [];

        for ($i = 5; $i >= 0; --$i) {
            $debutMois = $debut->modify(sprintf('-%d months', $i));
            $parMois[] = [
                'mois' => $debutMois,
                'montant' => $this->ventes($agent, $debutMois, $debutMois->modify('+1 month'))['montant'],
            ];
        }

        return [
            'ventes' => $ventes['montant'],
            'variationVentes' => TableauDeBordService::variation($ventes['montant'], $ventesAvant['montant']),
            'objectif' => $objectif,
            'avancementObjectif' => $objectif ? (int) round($ventes['montant'] / $objectif * 100) : null,
            'devisEmis' => $devis['emis'],
            'tauxTransformation' => $devis['emis'] > 0 ? (int) round($devis['gagnes'] / $devis['emis'] * 100) : null,
            'encaissements' => $encaissements['total'],
            'partOrangeMoney' => $encaissements['total'] > 0 ? (int) round($encaissements['orangeMoney'] / $encaissements['total'] * 100) : null,
            'parMois' => $parMois,
            'maxParMois' => max(1, ...array_column($parMois, 'montant')),
            'commandes' => $ventes['nombre'],
            'panierMoyen' => $ventes['nombre'] > 0 ? (int) round($ventes['montant'] / $ventes['nombre']) : 0,
            'devisARelancer' => $this->devisARelancer($agent, $maintenant),
            'tauxRemise' => $ventes['montant'] + $ventes['remises'] > 0 ? round($ventes['remises'] / ($ventes['montant'] + $ventes['remises']) * 100, 1) : null,
        ];
    }

    /**
     * Chiffre d'affaires de l'entreprise sur les 12 derniers mois.
     */
    public function chiffreAffairesGlobal(\DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(c.totalTtc), 0)')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut')
            ->setParameter('debut', $maintenant->modify('-12 months'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marge brute de l'entreprise sur le mois (prix HT moins cout de revient).
     *
     * @return array{marge: int, taux: ?float}
     */
    public function margeDuMois(\DateTimeImmutable $mois): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $ligne = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.totalHt), 0) AS ht, COALESCE(SUM(d.coutRevient), 0) AS cout')
            ->from(CommandesDetails::class, 'd')
            ->join('d.commande', 'c')
            ->where('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $debut->modify('+1 month'))
            ->getQuery()
            ->getSingleResult();

        return ['marge' => (int) $ligne['ht'] - (int) $ligne['cout'], 'taux' => CalculateurCoutRevient::tauxMarge((int) $ligne['ht'], (int) $ligne['cout'])];
    }

    public function tresorerie(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(t.soldeActuel), 0)')
            ->from(CompteTresorerie::class, 't')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{montant: int, nombre: int, remises: int}
     */
    private function ventes(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $ligne = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(c.totalTtc), 0) AS montant, COUNT(c.id) AS nombre, COALESCE(SUM(c.remise), 0) AS remises')
            ->from(Commandes::class, 'c')
            ->where('c.agents = :agent')
            ->andWhere('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('agent', $agent)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();

        return ['montant' => (int) $ligne['montant'], 'nombre' => (int) $ligne['nombre'], 'remises' => (int) $ligne['remises']];
    }

    /**
     * @return array{emis: int, gagnes: int}
     */
    private function devis(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $statuts = $this->em->createQueryBuilder()
            ->select('d.statut')
            ->from(Devis::class, 'd')
            ->where('d.agents = :agent')
            ->andWhere('d.deleted = false')
            ->andWhere('d.statut IN (:emis)')
            ->andWhere('d.dateDevis >= :debut AND d.dateDevis < :fin')
            ->setParameter('agent', $agent)
            ->setParameter('emis', self::DEVIS_EMIS)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleColumnResult();

        return [
            'emis' => \count($statuts),
            'gagnes' => \count(array_intersect($statuts, self::DEVIS_GAGNES)),
        ];
    }

    /**
     * @return array{total: int, orangeMoney: int}
     */
    private function encaissements(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('p.mode, COALESCE(SUM(p.montant), 0) AS montant')
            ->from(Paiements::class, 'p')
            ->where('p.encaissePar = :agent')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->groupBy('p.mode')
            ->setParameter('agent', $agent)
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        $parMode = array_column($lignes, 'montant', 'mode');

        return [
            'total' => (int) array_sum($parMode),
            'orangeMoney' => (int) ($parMode[Paiements::MODE_ORANGE_MONEY] ?? 0),
        ];
    }

    private function devisARelancer(User $agent, \DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Devis::class, 'd')
            ->where('d.agents = :agent')
            ->andWhere('d.deleted = false')
            ->andWhere('d.statut = :envoye')
            ->andWhere('d.dateDevis <= :limite')
            ->setParameter('agent', $agent)
            ->setParameter('envoye', Devis::STATUT_ENVOYE)
            ->setParameter('limite', $maintenant->setTime(23, 59, 59)->modify(sprintf('-%d days', VentesService::JOURS_AVANT_RELANCE)))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
