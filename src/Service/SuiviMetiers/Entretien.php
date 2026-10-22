<?php

namespace App\Service\SuiviMetiers;

use App\Entity\Pointage;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\PointageRepository;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Agent d'entretien (docs/suivi-metiers.md, maquette W05) : les tâches
 * « Entretien » du planning, validées par une photo prise en direct à
 * l'heure du serveur, le contrôle du responsable sur des tâches tirées au
 * hasard, et les points du barème « Agent d'entretien ».
 */
final class Entretien
{
    public const CATEGORIE = 'entretien';
    /** Au-delà de la fin du créneau, la tâche est en retard. */
    public const RETARD_MAX = 30;
    private const PHOTO_MAX = 4 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly PointageRepository $pointages,
        #[Autowire('%kernel.project_dir%/var/uploads/entretien')]
        private readonly string $dossier,
    ) {
    }

    /** Faite dans le créneau, avec photo. */
    public static function dansLeCreneau(Tache $t): bool
    {
        $fin = $t->finCreneau();

        return $t->estFaite() && $t->getPhoto() !== null && ($fin === null || $t->getFaitLe() <= $fin);
    }

    /** Minutes après la fin du créneau (faite, ou pas faite à cette heure). */
    public static function retard(Tache $t, \DateTimeImmutable $maintenant): int
    {
        $fin = $t->finCreneau();
        if ($fin === null) {
            return 0;
        }
        $reference = $t->estFaite() ? $t->getFaitLe() : $maintenant;

        return max(0, intdiv($reference->getTimestamp() - $fin->getTimestamp(), 60));
    }

    /** @return array<string, int> */
    public static function faits(Tache $t, \DateTimeImmutable $maintenant): array
    {
        $faits = [];
        if (self::dansLeCreneau($t)) {
            $faits['e_creneau'] = 1;
        }
        if (self::retard($t, $maintenant) > self::RETARD_MAX) {
            $faits['e_retard'] = 1;
        }
        if ($t->isFermeture() && $t->estFaite() && $t->toutesEtapesFaites()) {
            $faits['e_fermeture'] = 1;
        }
        if ($t->getControle() !== null) {
            $faits[$t->getControle() === 'conforme' ? 'e_controle' : 'e_non_conforme'] = 1;
        }

        return $faits;
    }

    /** @return array<string, int> */
    public static function parfaits(Tache $t): array
    {
        return ['e_creneau' => 1] + ($t->isFermeture() ? ['e_fermeture' => 1] : []) + ($t->getControle() !== null ? ['e_controle' => 1] : []);
    }

    /**
     * Tâches d'entretien d'un jour (d'un agent, ou de tous), par heure.
     *
     * @return list<Tache>
     */
    public function duJour(\DateTimeImmutable $jour, ?User $agent = null): array
    {
        $qb = $this->em->getRepository(Tache::class)->createQueryBuilder('t')
            ->where('t.categorie = :c')->andWhere('t.prevueLe = :j')->andWhere('t.statut != :annulee')
            ->setParameter('c', self::CATEGORIE)->setParameter('j', $jour->setTime(0, 0), 'date_immutable')->setParameter('annulee', Tache::ANNULEE)
            ->orderBy('t.heure', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($agent !== null) {
            $qb->andWhere('t.responsable = :a')->setParameter('a', $agent);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Journée d'un agent (W05) : tâches, points, arrivée pointée, contrôles du mois.
     *
     * @return array<string, mixed>
     */
    public function journee(User $agent, \DateTimeImmutable $maintenant): array
    {
        $bareme = $this->parametres->bareme();
        $taches = $this->duJour($maintenant, $agent);
        $points = [];
        foreach ($taches as $t) {
            if ($t->estFaite() || self::retard($t, $maintenant) > self::RETARD_MAX) {
                $points[$t->getId()] = $bareme->points(self::faits($t, $maintenant));
            }
        }
        $arrivee = null;
        foreach ($this->pointages->entre($maintenant->setTime(0, 0), $maintenant->setTime(23, 59, 59), $agent) as $p) {
            \assert($p instanceof Pointage);
            $arrivee = $arrivee === null || $p->getArriveeLe() < $arrivee ? $p->getArriveeLe() : $arrivee;
        }

        return [
            'taches' => $taches,
            'faites' => \count(array_filter($taches, static fn (Tache $t): bool => $t->estFaite())),
            'points' => $points,
            'jour' => array_sum($points),
            'arrivee' => $arrivee,
            'mois' => $this->mois($agent, $maintenant),
        ];
    }

    /**
     * Points du mois et contrôles conformes.
     *
     * @return array{gagnes: int, possibles: int, note: int, controles: int, conformes: int}
     */
    public function mois(User $agent, \DateTimeImmutable $maintenant): array
    {
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0);
        /** @var list<Tache> $taches */
        $taches = $this->em->getRepository(Tache::class)->createQueryBuilder('t')
            ->where('t.categorie = :c')->andWhere('t.responsable = :a')->andWhere('t.statut != :annulee')
            ->andWhere('t.prevueLe >= :d')->andWhere('t.prevueLe <= :f')
            ->setParameter('c', self::CATEGORIE)->setParameter('a', $agent)->setParameter('annulee', Tache::ANNULEE)
            ->setParameter('d', $debut, 'date_immutable')->setParameter('f', $maintenant->setTime(0, 0), 'date_immutable')
            ->getQuery()->getResult();

        return self::totaux($taches, $this->parametres->bareme(), $maintenant);
    }

    /**
     * @param list<Tache> $taches
     *
     * @return array{gagnes: int, possibles: int, note: int, controles: int, conformes: int}
     */
    public static function totaux(array $taches, Bareme $bareme, \DateTimeImmutable $maintenant): array
    {
        $gagnes = 0;
        $possibles = 0;
        $controles = 0;
        $conformes = 0;
        foreach ($taches as $t) {
            // Une tâche du jour pas encore due ne compte pas encore.
            if (!$t->estFaite() && self::retard($t, $maintenant) <= self::RETARD_MAX && $t->getPrevueLe() >= $maintenant->setTime(0, 0)) {
                continue;
            }
            $gagnes += $bareme->points(self::faits($t, $maintenant));
            $possibles += $bareme->possibles(self::parfaits($t));
            if ($t->getControle() !== null) {
                ++$controles;
                $conformes += $t->getControle() === 'conforme' ? 1 : 0;
            }
        }

        return ['gagnes' => $gagnes, 'possibles' => $possibles, 'note' => Bareme::note($gagnes, $possibles), 'controles' => $controles, 'conformes' => $conformes];
    }

    /**
     * Tâches à contrôler du jour : tirées au hasard parmi les tâches faites,
     * toujours les mêmes pour un jour donné.
     *
     * @param list<Tache> $taches
     *
     * @return list<int> identifiants
     */
    public static function tirage(array $taches, \DateTimeImmutable $jour, int $nombre): array
    {
        $faites = array_values(array_filter($taches, static fn (Tache $t): bool => $t->estFaite()));
        usort($faites, static fn (Tache $a, Tache $b): int => strcmp(md5($jour->format('Ymd').'-'.$a->getId()), md5($jour->format('Ymd').'-'.$b->getId())));

        return array_map(static fn (Tache $t): int => (int) $t->getId(), \array_slice($faites, 0, max(0, $nombre)));
    }

    /** Valeurs du barème affichées sur l'écran de validation. @return array{creneau: int, controle: int, fermeture: int} */
    public function valeurs(): array
    {
        $b = $this->parametres->bareme();

        return ['creneau' => (int) $b->valeur('e_creneau'), 'controle' => (int) $b->valeur('e_controle'), 'fermeture' => (int) $b->valeur('e_fermeture')];
    }

    public function points(Tache $t, \DateTimeImmutable $maintenant): int
    {
        return $this->parametres->bareme()->points(self::faits($t, $maintenant));
    }

    public function nombreControles(): int
    {
        return (int) $this->parametres->bareme()->valeur('controles_entretien');
    }

    /**
     * Validation par l'agent : étapes cochées et photo prise en direct.
     *
     * @param list<int> $etapes index des étapes faites
     */
    public function valider(Tache $t, User $agent, ?string $photoDataUrl, array $etapes, \DateTimeImmutable $maintenant): ?string
    {
        if ($t->estFaite()) {
            return 'Cette tâche est déjà validée.';
        }
        if ($photoDataUrl === null || !preg_match('#^data:image/jpeg;base64,(.+)$#', $photoDataUrl, $m)) {
            return 'Prenez la photo après le nettoyage : elle est obligatoire.';
        }
        $jpeg = base64_decode($m[1], true);
        if ($jpeg === false || \strlen($jpeg) < 100 || \strlen($jpeg) > self::PHOTO_MAX || !str_starts_with($jpeg, "\xFF\xD8\xFF")) {
            return 'La photo n\'a pas pu être lue : recommencez.';
        }
        $relatif = $maintenant->format('Y/m').'/'.bin2hex(random_bytes(16)).'.jpg';
        $chemin = $this->dossier.'/'.$relatif;
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0770, true);
        }
        file_put_contents($chemin, $jpeg);
        $t->definirEtapes(array_map('intval', $etapes));
        $t->setPhoto($relatif);
        $t->commencer($maintenant);
        $t->cocher($agent, $maintenant);
        $this->em->flush();

        return null;
    }

    public function cheminPhoto(Tache $t): ?string
    {
        $photo = $t->getPhoto();
        if ($photo === null || !preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#', $photo)) {
            return null;
        }

        return is_file($this->dossier.'/'.$photo) ? $this->dossier.'/'.$photo : null;
    }
}
