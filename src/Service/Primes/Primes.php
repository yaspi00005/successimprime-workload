<?php

namespace App\Service\Primes;

use App\Entity\BaremePrime;
use App\Entity\BulletinPaie;
use App\Entity\EvaluationPrime;
use App\Entity\PrimeAgent;
use App\Entity\User;
use App\Repository\BaremePrimeRepository;
use App\Repository\EvaluationPrimeRepository;
use App\Repository\PrimeAgentRepository;
use App\Service\EspaceEmploye\Paie;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\Pointages;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\SuiviEquipes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Performance et primes (maquettes N01 à N04) : rôle et barème de
 * chacun, évaluation du mois (calcul en direct tant qu'elle est en
 * brouillon, figée une fois validée, nouvelle version pour un recalcul),
 * validation des primes par la direction et report dans la paie.
 *
 * Qui voit quoi (PrimesVoter) : l'agent sa propre note et sa prime
 * estimée, le responsable son équipe (Employes::responsable), l'admin
 * tout le monde, les autres rien.
 */
final class Primes
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BaremePrimeRepository $baremes,
        private readonly PrimeAgentRepository $reglages,
        private readonly EvaluationPrimeRepository $evaluations,
        private readonly MesuresPrime $mesures,
        private readonly Pointages $pointages,
        private readonly Parametres $parametres,
        private readonly NotificationService $notifications,
    ) {
    }

    /** Rôle de prime déduit du profil : comptable, métier suivi, ou caisse. */
    public static function roleDuProfil(User $u): ?string
    {
        if ($u->isAdmin()) {
            return null;
        }
        if (\in_array('ROLE_COMPTABILITE', $u->getRoles(), true)) {
            return 'comptable';
        }

        return match (SuiviEquipes::metier($u)) {
            Bareme::GRAPHISTE => 'graphiste',
            Bareme::OPERATEUR => 'production',
            Bareme::LIVREUR => 'livreur',
            Bareme::ENTRETIEN => 'entretien',
            default => 'ROLE_CAISSE_COMMANDE' === $u->getProfil()?->getCode() ? 'caisse' : null,
        };
    }

    /** Rôle de prime d'une personne : celui imposé sur sa fiche de prime, sinon celui de son profil. */
    public function role(User $u): ?string
    {
        $reglage = $this->reglages->findOneBy(['agent' => $u]);
        if (null !== $reglage?->getRole()) {
            return isset(CalculPrime::ROLES[$reglage->getRole()]) ? $reglage->getRole() : null;
        }

        return self::roleDuProfil($u);
    }

    /**
     * Barème d'un rôle : celui enregistré (N03), sinon celui par défaut.
     *
     * @return array{prime: int, criteres: list<array{cle: string, poids: int, objectif: int|float|null}>, modifie: ?BaremePrime}
     */
    public function bareme(string $role): array
    {
        $enregistre = $this->baremes->findOneBy(['role' => $role]);
        if (null !== $enregistre && [] !== $enregistre->getCriteres()) {
            return ['prime' => $enregistre->getPrimeCible(), 'criteres' => $enregistre->getCriteres(), 'modifie' => $enregistre];
        }

        return CalculPrime::BAREMES[$role] + ['modifie' => null];
    }

    /**
     * Enregistre le barème d'un rôle (N03).
     *
     * @param list<array{cle: string, poids: int, objectif: int|float|null}> $criteres
     */
    public function enregistrerBareme(string $role, int $primeCible, array $criteres, User $par, \DateTimeImmutable $maintenant): ?string
    {
        if (!isset(CalculPrime::ROLES[$role])) {
            return 'Rôle inconnu.';
        }
        $criteres = array_values(array_filter($criteres, static fn (array $c): bool => $c['poids'] > 0));
        if (100 !== array_sum(array_column($criteres, 'poids'))) {
            return sprintf('Les poids font %d %% : ils doivent faire 100 %%.', array_sum(array_column($criteres, 'poids')));
        }
        $bareme = $this->baremes->findOneBy(['role' => $role]) ?? new BaremePrime($role);
        $bareme->modifier($primeCible, $criteres, $par, $maintenant);
        $this->em->persist($bareme);
        $this->em->flush();

        return null;
    }

    public function reglage(User $agent): ?PrimeAgent
    {
        return $this->reglages->findOneBy(['agent' => $agent]);
    }

    /** Rôle imposé et prime cible propres à une personne (null = ceux du rôle). */
    public function regler(User $agent, ?string $role, ?int $primeCible, User $par, \DateTimeImmutable $maintenant): void
    {
        $reglage = $this->reglages->findOneBy(['agent' => $agent]) ?? new PrimeAgent($agent);
        $reglage->modifier(null !== $role && (isset(CalculPrime::ROLES[$role]) || 'aucun' === $role) ? $role : null, $primeCible, $par, $maintenant);
        $this->em->persist($reglage);
        $this->em->flush();
    }

    public function primeCible(User $agent, string $role): int
    {
        return $this->reglages->findOneBy(['agent' => $agent])?->getPrimeCible() ?? $this->bareme($role)['prime'];
    }

    /**
     * Personnes que $moi peut évaluer : tout le personnel pour l'admin,
     * son équipe pour un responsable. Jamais soi-même.
     *
     * @return list<User>
     */
    public function agentsVisibles(User $moi): array
    {
        return self::visiblesPar($moi, $this->pointages->personnel());
    }

    /**
     * Filtre des droits : l'admin voit tout le personnel, un responsable son
     * équipe (Employes::responsable), les autres personne ; jamais soi-même.
     *
     * @param list<User> $personnel
     *
     * @return list<User>
     */
    public static function visiblesPar(User $moi, array $personnel): array
    {
        return array_values(array_filter(
            $personnel,
            static fn (User $u): bool => $u !== $moi && ($moi->isAdmin() || $u->getEmploye()?->getResponsable() === $moi),
        ));
    }

    /** Responsable de l'agent (Employes::responsable), ou null. */
    public static function responsableDe(User $agent): ?User
    {
        return $agent->getEmploye()?->getResponsable();
    }

    /**
     * Évaluation d'un agent pour un mois : la dernière version enregistrée,
     * figée si elle est validée ; sinon le calcul en direct (appliqué au
     * brouillon s'il existe, sans l'enregistrer).
     *
     * @return array{agent: User, role: string, mois: \DateTimeImmutable, evaluation: ?EvaluationPrime, enVigueur: ?EvaluationPrime, versions: list<EvaluationPrime>, resultat: array<string, mixed>, mesures: array<string, mixed>, fige: bool}|null
     */
    public function fiche(User $agent, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): ?array
    {
        $mois = $mois->modify('first day of this month')->setTime(0, 0);
        $versions = $this->evaluations->versions($agent, $mois);
        $derniere = $versions[0] ?? null;
        $role = $derniere?->getRole() ?? $this->role($agent);
        if (null === $role) {
            return null;
        }
        $enVigueur = null;
        foreach ($versions as $v) {
            if ($v->estValidee()) {
                $enVigueur = $v;
                break;
            }
        }
        $mesures = [];
        if (null !== $derniere && !$derniere->estModifiable()) {
            $resultat = self::resultatFige($derniere);
        } else {
            if (null === $derniere) {
                $role = $this->role($agent) ?? $role;
            }
            [$resultat, $mesures] = $this->calculer($agent, $role, $mois, $maintenant, $derniere);
            $derniere?->appliquer($resultat, $role);
        }

        return [
            'agent' => $agent,
            'role' => $role,
            'mois' => $mois,
            'evaluation' => $derniere,
            'enVigueur' => $enVigueur,
            'versions' => $versions,
            'resultat' => $resultat,
            'mesures' => $mesures,
            'fige' => null !== $derniere && !$derniere->estModifiable(),
        ];
    }

    /**
     * Calcul en direct : mesures de l'ERP, appréciation et suspension du brouillon.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function calculer(User $agent, string $role, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant, ?EvaluationPrime $brouillon): array
    {
        $bareme = $this->bareme($role);
        $mesures = $this->mesures->mesurer($agent, $role, $bareme['criteres'], $mois, $maintenant);
        $note = $brouillon?->getAppreciationNote();
        $mesures['appreciation'] = [
            'note' => $note,
            'mesure' => null !== $brouillon?->getAppreciationTexte() ? '« '.$brouillon->getAppreciationTexte().' »' : (null !== $note ? 'note saisie' : 'à saisir par le responsable'),
        ];

        return [CalculPrime::evaluer($bareme['criteres'], $mesures, $this->primeCible($agent, $role), (bool) $brouillon?->isSuspendue()), $mesures];
    }

    /** @return array<string, mixed> */
    public static function resultatFige(EvaluationPrime $e): array
    {
        return [
            'score' => $e->getScore(),
            'taux' => $e->getTaux(),
            'primeCible' => $e->getPrimeCible(),
            'prime' => $e->getPrime(),
            'criteres' => $e->getCriteres(),
            'manquants' => array_values(array_map(static fn (array $c): string => (string) $c['libelle'], array_filter($e->getCriteres(), static fn (array $c): bool => null === $c['note']))),
        ];
    }

    /**
     * Vue d'ensemble de l'équipe (N01).
     *
     * @return array<string, mixed>
     */
    public function equipe(User $moi, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $mois = $mois->modify('first day of this month')->setTime(0, 0);
        $termine = $mois < $maintenant->modify('first day of this month')->setTime(0, 0);
        $lignes = [];
        $sansRole = [];
        $sansActivite = [];
        foreach ($this->agentsVisibles($moi) as $agent) {
            $fiche = $this->fiche($agent, $mois, $maintenant);
            if (null === $fiche) {
                // La direction n'est pas évaluée : seules les autres personnes sans rôle sont signalées.
                if (!$agent->isAdmin()) {
                    $sansRole[] = $agent;
                }
                continue;
            }
            if ($termine && self::sansActivite($fiche)) {
                $sansActivite[] = $agent;
                continue;
            }
            $lignes[] = $fiche + ['statut' => self::statut($fiche, $mois, $maintenant)];
        }
        usort($lignes, static fn (array $a, array $b): int => [$b['resultat']['score'] ?? -1, Pointages::nom($a['agent'])] <=> [$a['resultat']['score'] ?? -1, Pointages::nom($b['agent'])]);

        $scores = array_values(array_filter(array_map(static fn (array $l): ?int => $l['resultat']['score'], $lignes), static fn (?int $s): bool => null !== $s));
        $precedents = [];
        foreach ($this->evaluations->dernieresDuMois($mois->modify('-1 month')) as $id => $e) {
            if ($e->estValidee() && null !== $e->getScore() && \in_array($id, array_map(static fn (array $l): int => (int) $l['agent']->getId(), $lignes), true)) {
                $precedents[] = $e->getScore();
            }
        }
        $moyenne = [] !== $scores ? round(array_sum($scores) / \count($scores), 1) : null;
        $moyennePrecedente = [] !== $precedents ? round(array_sum($precedents) / \count($precedents), 1) : null;
        $suivant = $mois->modify('+1 month');

        return [
            'mois' => $mois,
            'lignes' => $lignes,
            'sansRole' => $sansRole,
            'sansActivite' => $sansActivite,
            'moyenne' => $moyenne,
            'ecart' => null !== $moyenne && null !== $moyennePrecedente ? round($moyenne - $moyennePrecedente, 1) : null,
            'primes' => array_sum(array_map(static fn (array $l): int => $l['resultat']['prime'], $lignes)),
            'possibles' => array_sum(array_map(static fn (array $l): int => $l['resultat']['primeCible'], $lignes)),
            'aFinaliser' => \count(array_filter($lignes, static fn (array $l): bool => !$l['fige'])),
            'sansAppreciation' => \count(array_filter($lignes, static fn (array $l): bool => !$l['fige'] && null === $l['evaluation']?->getAppreciationNote())),
            'aValiderPrimes' => \count(array_filter($lignes, static fn (array $l): bool => null !== $l['enVigueur'] && !$l['enVigueur']->primeEstValidee())),
            'termine' => $termine,
            'paie' => $suivant->setDate((int) $suivant->format('Y'), (int) $suivant->format('m'), min((int) $suivant->format('t'), max(1, (int) ($this->parametres->valeur('paie', 'jour_paie') ?? 5)))),
        ];
    }

    /**
     * Mois terminé sans aucune donnée (ni mesure, ni évaluation commencée) :
     * congé, arrivée en cours de mois ou compte inutilisé. La personne sort
     * du classement de N01 au lieu d'y figurer à 0 ; son responsable peut
     * toujours ouvrir sa fiche et l'évaluer.
     *
     * @param array<string, mixed> $fiche
     */
    public static function sansActivite(array $fiche): bool
    {
        return null === $fiche['evaluation'] && null === $fiche['resultat']['score'];
    }

    /**
     * Statut d'une ligne de N01 : texte et couleur.
     *
     * @param array<string, mixed> $fiche
     *
     * @return array{0: string, 1: string}
     */
    public static function statut(array $fiche, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $vigueur = $fiche['enVigueur'];
        if ($fiche['fige'] && null !== $vigueur && $vigueur->primeEstValidee()) {
            return ['Prime validée', 'bleu'];
        }
        if ($fiche['fige']) {
            return ['Prêt', 'vert'];
        }
        if (null === $fiche['evaluation']?->getAppreciationNote()) {
            return ['Appréciation à saisir', 'orange'];
        }
        if ($mois >= $maintenant->modify('first day of this month')->setTime(0, 0)) {
            return ['Mois en cours', 'gris'];
        }

        return [null !== $vigueur ? 'Nouvelle version à valider' : 'À valider', 'orange'];
    }

    /** Brouillon du mois (créé au besoin), sur lequel on saisit l'appréciation. */
    private function brouillon(User $agent, \DateTimeImmutable $mois, User $par, \DateTimeImmutable $maintenant): EvaluationPrime
    {
        $versions = $this->evaluations->versions($agent, $mois);
        $derniere = $versions[0] ?? null;
        if (null !== $derniere && $derniere->estModifiable()) {
            return $derniere;
        }
        if (null !== $derniere) {
            throw new \DomainException('Évaluation validée : utilisez « Recalculer » avec un motif pour en faire une nouvelle version.');
        }
        $role = $this->role($agent) ?? throw new \DomainException('Cette personne n\'a pas de rôle de prime.');
        $brouillon = new EvaluationPrime($agent, $mois, $role, 1, $maintenant, $par);
        $this->em->persist($brouillon);

        return $brouillon;
    }

    /** Appréciation du responsable (et suspension éventuelle), sur le brouillon. */
    public function apprecier(User $agent, \DateTimeImmutable $mois, ?int $note, ?string $texte, bool $suspendue, ?string $motif, User $par, \DateTimeImmutable $maintenant): void
    {
        $brouillon = $this->brouillon($agent, $mois, $par, $maintenant);
        $brouillon->apprecier($note, $texte)->suspendre($suspendue, $motif);
        [$resultat] = $this->calculer($agent, $brouillon->getRole(), $brouillon->getMois(), $maintenant, $brouillon);
        $brouillon->appliquer($resultat, $brouillon->getRole());
        $this->em->flush();
    }

    /**
     * « Valider l'évaluation » : fige la photo des critères. Le mois doit
     * être terminé, et l'appréciation saisie si la tuile Primes l'exige.
     */
    public function valider(User $agent, \DateTimeImmutable $mois, User $par, \DateTimeImmutable $maintenant): ?string
    {
        $mois = $mois->modify('first day of this month')->setTime(0, 0);
        if ($mois >= $maintenant->modify('first day of this month')->setTime(0, 0)) {
            return 'Le mois n\'est pas terminé : l\'évaluation se valide à partir du 1er du mois suivant.';
        }
        try {
            $brouillon = $this->brouillon($agent, $mois, $par, $maintenant);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
        if (null === $brouillon->getAppreciationNote() && $this->appreciationObligatoire()) {
            return 'Saisissez d\'abord l\'appréciation du responsable.';
        }
        [$resultat] = $this->calculer($agent, $brouillon->getRole(), $mois, $maintenant, $brouillon);
        $brouillon->appliquer($resultat, $brouillon->getRole())->valider($par, $maintenant);
        foreach ($this->evaluations->versions($agent, $mois) as $v) {
            if ($v !== $brouillon && $v->estValidee()) {
                $v->remplacer();
            }
        }
        $this->em->flush();

        return null;
    }

    /** Nouvelle version d'une évaluation validée, avec son motif (recalcul). */
    public function recalculer(User $agent, \DateTimeImmutable $mois, string $motif, User $par, \DateTimeImmutable $maintenant): ?string
    {
        if (mb_strlen(trim($motif)) < 5) {
            return 'Indiquez le motif du recalcul.';
        }
        $versions = $this->evaluations->versions($agent, $mois);
        $derniere = $versions[0] ?? null;
        if (null === $derniere || !$derniere->estValidee()) {
            return null === $derniere || $derniere->estModifiable() ? 'L\'évaluation n\'est pas encore validée : elle se recalcule d\'elle-même.' : 'Cette version est remplacée.';
        }
        $suivante = $derniere->nouvelleVersion($motif, $par, $maintenant);
        $this->em->persist($suivante);
        [$resultat] = $this->calculer($agent, $suivante->getRole(), $suivante->getMois(), $maintenant, $suivante);
        $suivante->appliquer($resultat, $suivante->getRole());
        $this->em->flush();

        return null;
    }

    /**
     * « Valider les primes » (direction) : les évaluations validées du
     * mois passent en sommes dues, puis sur le bulletin de paie en
     * brouillon s'il existe. Rien n'est versé automatiquement.
     *
     * @return array{validees: int, reportees: int}
     */
    public function validerPrimes(\DateTimeImmutable $mois, User $par, \DateTimeImmutable $maintenant): array
    {
        $validees = 0;
        foreach ($this->evaluations->dernieresDuMois($mois) as $e) {
            $vigueur = $e->estValidee() ? $e : null;
            if (null === $vigueur) {
                foreach ($this->evaluations->versions($e->getAgent(), $mois) as $v) {
                    if ($v->estValidee()) {
                        $vigueur = $v;
                        break;
                    }
                }
            }
            if (null !== $vigueur && !$vigueur->primeEstValidee()) {
                $vigueur->validerPrime($par, $maintenant);
                ++$validees;
                $this->notifications->notifierUtilisateur($vigueur->getAgent(), sprintf('Votre prime de %s est validée : %s F.', Paie::mois($vigueur->getMois()), number_format($vigueur->getPrime(), 0, ',', ' ')), 'app_mon_espace_prime');
            }
        }
        $this->em->flush();

        return ['validees' => $validees, 'reportees' => $this->reporterEnPaie($maintenant)];
    }

    /**
     * Reporte les primes validées sur le premier bulletin en brouillon
     * de l'agent (mois de la prime ou après) : ligne « Prime » positive,
     * ou régularisation négative si une nouvelle version a baissé la
     * prime. Appelé après « Valider les primes » et après la préparation
     * de la paie.
     *
     * @return int lignes ajoutées
     */
    public function reporterEnPaie(\DateTimeImmutable $maintenant, ?User $agent = null): int
    {
        $lignes = 0;
        foreach ($this->evaluations->primesAReporter($agent) as $e) {
            $dejaReporte = array_sum(array_map(static fn (EvaluationPrime $v): int => $v->getPrimeReportee(), $this->evaluations->versions($e->getAgent(), $e->getMois())));
            $ecart = ($e->isSuspendue() ? 0 : $e->getPrime()) - $dejaReporte;
            if (0 === $ecart) {
                continue;
            }
            $bulletin = $this->em->getRepository(BulletinPaie::class)->createQueryBuilder('b')
                ->where('b.utilisateur = :u')->andWhere('b.etat = :brouillon')->andWhere('b.mois >= :m')
                ->setParameter('u', $e->getAgent())->setParameter('brouillon', BulletinPaie::BROUILLON)->setParameter('m', $e->getMois())
                ->orderBy('b.mois', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (!$bulletin instanceof BulletinPaie) {
                continue;
            }
            // Le bulletin passe par les RH : ni score ni critère sur la ligne, seulement la somme due.
            $libelle = $ecart > 0
                ? sprintf('Prime de performance %s', Paie::mois($e->getMois()))
                : sprintf('Régularisation prime %s (version %d)', Paie::mois($e->getMois()), $e->getVersion());
            if (null === $bulletin->ajouterLigne($ecart > 0 ? 'prime' : 'autre', $libelle, $ecart)) {
                $e->reporter($ecart, $bulletin);
                ++$lignes;
            }
        }
        $this->em->flush();

        return $lignes;
    }

    /**
     * Mois précédents d'un agent (N02 « 6 derniers mois », N04 « Mes
     * primes ») : version en vigueur de chaque mois validé.
     *
     * @return array<string, EvaluationPrime> par mois « AAAA-MM »
     */
    public function historique(User $agent, int $mois = 12): array
    {
        $parMois = [];
        foreach ($this->evaluations->valideesDe($agent, $mois) as $e) {
            $parMois[$e->getMois()->format('Y-m')] ??= $e;
        }

        return $parMois;
    }

    /** Tuile Primes : faut-il l'appréciation du responsable pour valider ? */
    public function appreciationObligatoire(): bool
    {
        return (bool) ($this->parametres->valeur('primes', 'appreciation_obligatoire') ?? true);
    }

    /** L'agent voit-il sa note et sa prime estimée dans Mon espace (tuile Primes) ? */
    public function agentVoitNote(): bool
    {
        return (bool) ($this->parametres->valeur('primes', 'agent_voit_note') ?? true);
    }

    /** Mois proposé par défaut : le mois précédent tant que sa paie n'est pas passée, sinon le mois en cours. */
    public function moisParDefaut(\DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        $jourPaie = max(1, (int) ($this->parametres->valeur('paie', 'jour_paie') ?? 5));
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0);

        return (int) $maintenant->format('j') <= $jourPaie ? $debut->modify('-1 month') : $debut;
    }
}
