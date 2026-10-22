<?php

namespace App\Service\Devis;

use App\Entity\Devis;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste des devis (maquette GC03) : les devis du mois par statut, le taux
 * d'acceptation, ce qui attend une réponse, ce qui est à relancer
 * aujourd'hui, et le tableau (filtré par statut, ou une recherche sur tous
 * les mois).
 */
final class ListeDevis
{
    /** Filtres de la liste : statut(s) de chaque puce. */
    public const FILTRES = [
        'brouillon' => ['libelle' => 'Brouillons', 'statuts' => [Devis::STATUT_BROUILLON]],
        'envoye' => ['libelle' => 'Envoyés', 'statuts' => [Devis::STATUT_ENVOYE]],
        'vu' => ['libelle' => 'Vus', 'statuts' => [Devis::STATUT_VU]],
        'accepte' => ['libelle' => 'Acceptés', 'statuts' => [Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI]],
        'refuse' => ['libelle' => 'Refusés', 'statuts' => [Devis::STATUT_REFUSE]],
        'expire' => ['libelle' => 'Expirés', 'statuts' => [Devis::STATUT_EXPIRE]],
    ];

    public const RESULTATS_MAX = 150;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{
     *   mois: \DateTimeImmutable, devis: list<Devis>, compteurs: array<string, int>, total: int,
     *   envoyes: int, acceptes: int, taux: ?int, attente: array{montant: int, nombre: int},
     *   aRelancer: list<Devis>, recherche: bool, tronque: bool
     * }
     */
    public function page(\DateTimeImmutable $mois, string $filtre, string $recherche, \DateTimeImmutable $maintenant): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $fin = $debut->modify('+1 month');

        /** @var list<Devis> $duMois */
        $duMois = $this->requete()
            ->andWhere('d.dateDevis >= :debut')->andWhere('d.dateDevis < :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getResult();

        $compteurs = array_fill_keys(array_keys(self::FILTRES), 0);
        foreach ($duMois as $d) {
            foreach (self::FILTRES as $cle => $f) {
                if (\in_array($d->getStatut(), $f['statuts'], true)) {
                    ++$compteurs[$cle];
                }
            }
        }
        $total = array_sum($compteurs);
        $envoyes = $total - $compteurs['brouillon'];

        $recherche = trim(mb_substr($recherche, 0, 60));
        if ('' !== $recherche) {
            $qb = $this->requete()
                ->andWhere('d.numero LIKE :q OR cl.nom LIKE :q OR cl.prenom LIKE :q OR cl.raisonSociale LIKE :q OR cl.telephone LIKE :q OR d.objet LIKE :q')
                ->setParameter('q', '%'.addcslashes($recherche, '%_').'%')
                ->setMaxResults(self::RESULTATS_MAX + 1);
            $liste = $qb->getQuery()->getResult();
        } else {
            $liste = $duMois;
        }
        if (isset(self::FILTRES[$filtre])) {
            $liste = array_values(array_filter($liste, static fn (Devis $d): bool => \in_array($d->getStatut(), self::FILTRES[$filtre]['statuts'], true)));
        }

        /** @var list<Devis> $enAttente */
        $enAttente = $this->requete()->andWhere('d.statut IN (:attente)')->setParameter('attente', Devis::STATUTS_EN_ATTENTE)->getQuery()->getResult();

        return [
            'mois' => $debut,
            'devis' => \array_slice($liste, 0, self::RESULTATS_MAX),
            'tronque' => \count($liste) > self::RESULTATS_MAX,
            'compteurs' => $compteurs,
            'total' => $total,
            'envoyes' => $envoyes,
            'acceptes' => $compteurs['accepte'],
            'taux' => $envoyes > 0 ? (int) round($compteurs['accepte'] * 100 / $envoyes) : null,
            'attente' => ['montant' => array_sum(array_map(static fn (Devis $d): int => $d->getTotalTtc(), $enAttente)), 'nombre' => \count($enAttente)],
            'aRelancer' => array_values(array_filter($enAttente, static fn (Devis $d): bool => SuiviDevis::relanceDue($d, $maintenant))),
            'recherche' => '' !== $recherche,
        ];
    }

    /** Devis pas supprimés ni annulés, du plus récent au plus ancien. */
    private function requete(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('d', 'cl', 'a', 'c')->from(Devis::class, 'd')
            ->leftJoin('d.clients', 'cl')->leftJoin('d.agents', 'a')->leftJoin('d.commande', 'c')
            ->where('d.deleted = false')->andWhere('d.statut <> :annule')->setParameter('annule', Devis::STATUT_ANNULE)
            ->orderBy('d.dateDevis', 'DESC')->addOrderBy('d.id', 'DESC');
    }
}
