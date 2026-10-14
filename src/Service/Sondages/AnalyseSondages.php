<?php

namespace App\Service\Sondages;

use App\Entity\InvitationSondage;
use App\Entity\NoteArticleSondage;
use App\Entity\ReponseSondage;
use App\Entity\Sondage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Résultats des sondages pour l'atelier (docs/sondages.md) : indicateurs
 * d'une période comparés au mois précédent et à N−1, satisfaction par
 * produit, agent, machine et mois, propositions et mauvaises notes.
 */
final class AnalyseSondages
{
    public const PERIODES = [
        'mois' => 'Ce mois',
        'trimestre' => '3 derniers mois',
        'annee' => '12 derniers mois',
    ];

    /** Sur l'écran TV, la note ne s'affiche qu'à partir de ce nombre d'avis. */
    public const AVIS_MIN_TV = 5;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Début et fin (exclue) d'une période, de la période d'avant de même
     * durée, et de la même période l'an dernier.
     *
     * @return array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, avant: array{0: \DateTimeImmutable, 1: \DateTimeImmutable}, anPasse: ?array{0: \DateTimeImmutable, 1: \DateTimeImmutable}, libelleAvant: string}
     */
    public static function bornes(string $periode, \DateTimeImmutable $maintenant): array
    {
        $mois = match ($periode) { 'trimestre' => 3, 'annee' => 12, default => 1 };
        $fin = $maintenant->modify('first day of next month')->setTime(0, 0);
        $debut = $fin->modify(\sprintf('-%d months', $mois));
        $avant = [$debut->modify(\sprintf('-%d months', $mois)), $debut];

        return [
            'debut' => $debut,
            'fin' => $fin,
            'avant' => $avant,
            // Sur 12 mois, la période d'avant est déjà l'année passée.
            'anPasse' => 12 === $mois ? null : [$debut->modify('-1 year'), $fin->modify('-1 year')],
            'libelleAvant' => match ($mois) { 1 => 'vs mois précédent', 3 => 'vs 3 mois d\'avant', default => 'vs 12 mois d\'avant' },
        ];
    }

    /**
     * Indicateurs de la période, comparés à la période d'avant et à N−1.
     *
     * @return array{bornes: array<string, mixed>, actuel: array<string, mixed>, avant: array<string, mixed>, anPasse: ?array<string, mixed>}
     */
    public function tableau(string $periode, ?string $type, ?Sondage $sondage, \DateTimeImmutable $maintenant): array
    {
        $b = self::bornes($periode, $maintenant);

        return [
            'bornes' => $b,
            'actuel' => $this->indicateurs($type, $sondage, $b['debut'], $b['fin']),
            'avant' => $this->indicateurs($type, $sondage, ...$b['avant']),
            'anPasse' => null === $b['anPasse'] ? null : $this->indicateurs($type, $sondage, ...$b['anPasse']),
        ];
    }

    /**
     * « repondus » : les réponses comptées dans le taux (avis de commande
     * quand tous les types sont mêlés).
     *
     * @return array{reponses: int, moyenne: ?float, satisfaits: ?int, nps: ?int, taux: ?int, alerte: bool, alerteNps: bool, envoyes: ?int, repondus: int}
     */
    public function indicateurs(?string $type, ?Sondage $sondage, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->filtrer($this->em->createQueryBuilder()->select('r.noteGlobale AS note, r.nps AS nps, r.type AS type')->from(ReponseSondage::class, 'r'), $type, $sondage, $debut, $fin)
            ->getQuery()->getArrayResult();
        $envoyes = null;
        $reponsesComptees = \count($lignes);
        if (null !== $sondage) {
            $envoyes = $sondage->getDestinataires();
        } elseif (null === $type || ReponseSondage::COMMANDE === $type) {
            // Taux de réponse des sondages de commande : avis reçus ÷ invitations envoyées.
            $envoyes = (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(InvitationSondage::class, 'i')
                ->where('i.creeLe >= :d')->andWhere('i.creeLe < :f')->setParameter('d', $debut)->setParameter('f', $fin)
                ->getQuery()->getSingleScalarResult();
            $reponsesComptees = \count(array_filter($lignes, static fn (array $l): bool => ReponseSondage::COMMANDE === $l['type']));
        }
        $resume = Indicateurs::resume(array_column($lignes, 'note'), array_column($lignes, 'nps'), \count($lignes), null);
        $resume['taux'] = Indicateurs::tauxReponse($reponsesComptees, $envoyes);

        return $resume + ['envoyes' => $envoyes, 'repondus' => $reponsesComptees];
    }

    /**
     * Note moyenne par mois sur 12 mois.
     *
     * @return list<array{mois: \DateTimeImmutable, moyenne: ?float, nombre: int, enCours: bool}>
     */
    public function parMois(?string $type, ?Sondage $sondage, \DateTimeImmutable $maintenant): array
    {
        $fin = $maintenant->modify('first day of next month')->setTime(0, 0);
        $debut = $fin->modify('-12 months');
        $lignes = $this->filtrer($this->em->createQueryBuilder()->select('r.noteGlobale AS note, r.creeLe AS le')->from(ReponseSondage::class, 'r'), $type, $sondage, $debut, $fin)
            ->getQuery()->getArrayResult();
        $notes = [];
        foreach ($lignes as $l) {
            $notes[$l['le']->format('Y-m')][] = $l['note'];
        }
        $mois = [];
        for ($m = $debut; $m < $fin; $m = $m->modify('+1 month')) {
            $n = $notes[$m->format('Y-m')] ?? [];
            $mois[] = ['mois' => $m, 'moyenne' => Indicateurs::moyenne($n), 'nombre' => \count(array_filter($n, static fn ($x): bool => null !== $x)), 'enCours' => $m->format('Y-m') === $maintenant->format('Y-m')];
        }

        return $mois;
    }

    /**
     * Satisfaction par produit, d'après les notes par article.
     *
     * @return list<array{nom: string, moyenne: float, nombre: int, alerte: bool}>
     */
    public function parProduit(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->notesArticles('p.nom', 'l.produit', 'p', $debut, $fin, 'Sans produit du catalogue');
    }

    /** @return list<array{nom: string, moyenne: float, nombre: int, alerte: bool}> */
    public function parMachine(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->notesArticles('m.nom', 'l.machine', 'm', $debut, $fin, 'Sans machine');
    }

    /**
     * Note globale des commandes par agent qui les a suivies. Réservé à la
     * direction : c'est une appréciation du travail de chacun.
     *
     * @return list<array{agent: ?User, moyenne: float, nombre: int, alerte: bool}>
     */
    public function parAgent(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.agents) AS agent, AVG(r.noteGlobale) AS moyenne, COUNT(r.id) AS nombre')
            ->from(ReponseSondage::class, 'r')->join('r.commande', 'c')
            ->where('r.type = :t')->andWhere('r.noteGlobale IS NOT NULL')
            ->andWhere('r.creeLe >= :d')->andWhere('r.creeLe < :f')
            ->setParameter('t', ReponseSondage::COMMANDE)->setParameter('d', $debut)->setParameter('f', $fin)
            ->groupBy('c.agents')->orderBy('moyenne', 'ASC')
            ->getQuery()->getArrayResult();
        $agents = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_filter(array_column($lignes, 'agent'))]) as $u) {
            $agents[$u->getId()] = $u;
        }

        return array_map(static fn (array $l): array => [
            'agent' => $agents[(int) $l['agent']] ?? null,
            'moyenne' => round((float) $l['moyenne'], 1),
            'nombre' => (int) $l['nombre'],
            'alerte' => Indicateurs::moyenneEnAlerte(round((float) $l['moyenne'], 1)),
        ], $lignes);
    }

    /**
     * Les réponses, la plus récente d'abord.
     *
     * @return list<ReponseSondage>
     */
    public function reponses(?string $type, ?Sondage $sondage, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin, ?string $note = null, int $nombre = 200): array
    {
        $qb = $this->filtrer($this->em->createQueryBuilder()->select('r')->from(ReponseSondage::class, 'r'), $type, $sondage, $debut, $fin)
            ->orderBy('r.id', 'DESC')->setMaxResults($nombre);
        if ('basses' === $note) {
            $qb->andWhere('r.noteGlobale <= :n')->setParameter('n', Indicateurs::NOTE_ALERTE_MAX);
        } elseif ('hautes' === $note) {
            $qb->andWhere('r.noteGlobale >= 4');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Propositions des clients, avec le nombre par thème et par statut.
     *
     * @return array{liste: list<ReponseSondage>, themes: array<string, int>, statuts: array<string, int>}
     */
    public function propositions(?string $theme, ?string $statut, int $nombre = 200): array
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(ReponseSondage::class, 'r')
            ->where('r.proposition IS NOT NULL')->orderBy('r.id', 'DESC')->setMaxResults($nombre);
        if (null !== $theme && isset(Themes::THEMES[$theme])) {
            $qb->andWhere('r.theme = :th')->setParameter('th', $theme);
        }
        if (null !== $statut && isset(Themes::STATUTS[$statut])) {
            $qb->andWhere('r.statutProposition = :st')->setParameter('st', $statut);
        }
        $compte = fn (string $champ): array => array_column($this->em->createQueryBuilder()
            ->select(\sprintf('r.%s AS cle, COUNT(r.id) AS n', $champ))->from(ReponseSondage::class, 'r')
            ->where('r.proposition IS NOT NULL')->groupBy('r.'.$champ)
            ->getQuery()->getArrayResult(), 'n', 'cle');

        return ['liste' => $qb->getQuery()->getResult(), 'themes' => $compte('theme'), 'statuts' => $compte('statutProposition')];
    }

    /**
     * Mauvaises notes : à rappeler d'abord, puis celles déjà traitées.
     *
     * @return list<ReponseSondage>
     */
    public function alertes(bool $ouvertes, int $nombre = 100): array
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(ReponseSondage::class, 'r')->orderBy('r.id', 'DESC')->setMaxResults($nombre);
        $ouvertes
            ? $qb->where('r.alerte = :a')->setParameter('a', ReponseSondage::ALERTE_A_RAPPELER)
            : $qb->where('r.alerte IS NOT NULL')->andWhere('r.alerte != :a')->setParameter('a', ReponseSondage::ALERTE_A_RAPPELER);

        return $qb->getQuery()->getResult();
    }

    public function nombreAlertes(): int
    {
        return $this->em->getRepository(ReponseSondage::class)->count(['alerte' => ReponseSondage::ALERTE_A_RAPPELER]);
    }

    /**
     * Commandes livrées dont l'avis n'est pas encore arrivé, encore dans le
     * délai : l'atelier peut envoyer le lien par WhatsApp.
     *
     * @return list<InvitationSondage>
     */
    public function invitationsEnAttente(\DateTimeImmutable $maintenant, int $nombre = 30): array
    {
        return $this->em->createQueryBuilder()->select('i')->from(InvitationSondage::class, 'i')
            ->where('i.creeLe >= :d')->setParameter('d', $maintenant->modify(\sprintf('-%d days', SondageCommande::JOURS_POUR_REPONDRE)))
            ->andWhere(\sprintf('NOT EXISTS (SELECT r.id FROM %s r WHERE r.commande = i.commande)', ReponseSondage::class))
            ->orderBy('i.id', 'DESC')->setMaxResults($nombre)
            ->getQuery()->getResult();
    }

    /**
     * Tableau de bord direction : satisfaction du mois et propositions
     * récentes.
     *
     * @return array{moyenne: ?float, ecart: ?float, reponses: int, satisfaits: ?int, alerte: bool, alertes: int, propositions: list<ReponseSondage>}
     */
    public function resumeDirection(\DateTimeImmutable $maintenant): array
    {
        $t = $this->tableau('mois', null, null, $maintenant);

        return [
            'moyenne' => $t['actuel']['moyenne'],
            'ecart' => Indicateurs::ecart($t['actuel']['moyenne'], $t['avant']['moyenne']),
            'reponses' => $t['actuel']['reponses'],
            'satisfaits' => $t['actuel']['satisfaits'],
            'alerte' => $t['actuel']['alerte'],
            'alertes' => $this->nombreAlertes(),
            'propositions' => $this->propositions(null, null, 4)['liste'],
        ];
    }

    /**
     * Note moyenne des 12 derniers mois pour l'écran TV : un chiffre, jamais
     * de nom ni de texte de client.
     *
     * @return array{moyenne: float, nombre: int}|null
     */
    public function noteEcranTv(\DateTimeImmutable $maintenant): ?array
    {
        $lignes = $this->em->createQueryBuilder()->select('AVG(r.noteGlobale) AS moyenne, COUNT(r.noteGlobale) AS nombre')
            ->from(ReponseSondage::class, 'r')->where('r.creeLe >= :d')->setParameter('d', $maintenant->modify('-12 months'))
            ->getQuery()->getSingleResult();
        $nombre = (int) $lignes['nombre'];

        return $nombre < self::AVIS_MIN_TV ? null : ['moyenne' => round((float) $lignes['moyenne'], 1), 'nombre' => $nombre];
    }

    /** @return list<array{nom: string, moyenne: float, nombre: int, alerte: bool}> */
    private function notesArticles(string $nom, string $jointure, string $alias, \DateTimeImmutable $debut, \DateTimeImmutable $fin, string $sansNom): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select(\sprintf('%s AS nom, AVG(n.note) AS moyenne, COUNT(n.id) AS nombre', $nom))
            ->from(NoteArticleSondage::class, 'n')->join('n.reponse', 'r')->leftJoin('n.ligne', 'l')->leftJoin($jointure, $alias)
            ->where('r.creeLe >= :d')->andWhere('r.creeLe < :f')->setParameter('d', $debut)->setParameter('f', $fin)
            ->groupBy($alias.'.id')->orderBy('moyenne', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l): array => [
            'nom' => (string) ($l['nom'] ?? $sansNom),
            'moyenne' => round((float) $l['moyenne'], 1),
            'nombre' => (int) $l['nombre'],
            'alerte' => Indicateurs::produitEnAlerte(round((float) $l['moyenne'], 1)),
        ], $lignes);
    }

    private function filtrer(QueryBuilder $qb, ?string $type, ?Sondage $sondage, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): QueryBuilder
    {
        if (null !== $sondage) {
            $qb->andWhere('r.sondage = :s')->setParameter('s', $sondage);
        } elseif (null !== $type && isset(ReponseSondage::TYPES[$type])) {
            $qb->andWhere('r.type = :t')->setParameter('t', $type);
        }
        if (null !== $debut) {
            $qb->andWhere('r.creeLe >= :d')->setParameter('d', $debut);
        }
        if (null !== $fin) {
            $qb->andWhere('r.creeLe < :f')->setParameter('f', $fin);
        }

        return $qb;
    }
}
