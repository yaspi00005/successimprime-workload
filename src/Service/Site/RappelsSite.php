<?php

namespace App\Service\Site;

use App\Entity\DemandeDevis;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rappel à l'heure choisie quand l'atelier est fermé (page Contact,
 * maquette EC06 ; liste « À rappeler » de la caisse, EC08). L'écran ne
 * s'affiche que si la direction l'a activé (tuile « Site : engagements »,
 * éteinte par défaut) : on n'en promet pas plus qu'on ne peut rappeler.
 *
 * Créneaux : chaque heure de rappel_debut à rappel_fin (incluses) du
 * prochain jour ouvré, dans les heures d'ouverture, rappel_par_heure
 * rappels au plus ; un créneau plein est barré.
 */
class RappelsSite
{
    /** Délai minimum avant un créneau (on ne promet pas de rappeler dans 10 minutes). */
    public const MARGE_MINUTES = 30;

    /** Au-delà de l'heure promise, sans rappel fait, la direction est prévenue. */
    public const RETARD_MINUTES = 15;

    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly EntityManagerInterface $em,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    public function actif(): bool
    {
        return (bool) $this->parametres->valeur('site_engagements', 'rappel_actif');
    }

    /**
     * Créneaux du prochain jour qui en a, ou null (écran éteint ou rien de possible).
     *
     * @return array{jour: \DateTimeImmutable, quand: string, libelle: string, heures: list<array{heure: int, valeur: string, libre: bool}>}|null
     */
    public function creneaux(\DateTimeImmutable $maintenant): ?array
    {
        if (!$this->actif()) {
            return null;
        }
        $heures = $this->parametres->heuresTravail();
        [$debut, $fin, $parHeure] = $this->reglages();

        return self::grille(
            $heures->estOuvre(...),
            $heures->plages(...),
            $debut,
            $fin,
            $maintenant,
            fn (\DateTimeImmutable $jour): array => $this->prises($jour),
            $parHeure,
            // Fermeture exceptionnelle (sans date de fin) : rien avant demain.
            (bool) $this->parametres->valeur('site_contact', 'fermeture') ? 1 : 0,
        );
    }

    /**
     * Calcul pur des créneaux (testé sans base).
     *
     * @param callable(\DateTimeImmutable): bool                                             $estOuvre
     * @param callable(\DateTimeImmutable): list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $plages
     * @param callable(\DateTimeImmutable): array<int, int>                                  $prises   rappels déjà pris par heure ce jour-là
     *
     * @return array{jour: \DateTimeImmutable, quand: string, libelle: string, heures: list<array{heure: int, valeur: string, libre: bool}>}|null
     */
    public static function grille(callable $estOuvre, callable $plages, int $debut, int $fin, \DateTimeImmutable $maintenant, callable $prises, int $parHeure, int $premierJour = 0): ?array
    {
        $limite = $maintenant->modify(\sprintf('+%d minutes', self::MARGE_MINUTES));
        for ($i = $premierJour; $i < 14; ++$i) {
            $jour = $maintenant->setTime(0, 0)->modify(\sprintf('+%d days', $i));
            if (!$estOuvre($jour)) {
                continue;
            }
            $pris = null;
            $liste = [];
            for ($h = $debut; $h <= $fin; ++$h) {
                $moment = $jour->setTime($h, 0);
                if ($moment < $limite || !self::dansPlages($moment, $plages($jour))) {
                    continue;
                }
                $pris ??= $prises($jour);
                $liste[] = ['heure' => $h, 'valeur' => $moment->format('Y-m-d H:i'), 'libre' => ($pris[$h] ?? 0) < $parHeure];
            }
            if ([] !== array_filter($liste, static fn (array $c): bool => $c['libre'])) {
                return ['jour' => $jour, 'quand' => match ($i) { 0 => 'aujourd\'hui', 1 => 'demain', default => 'le' }, 'libelle' => self::JOURS[(int) $jour->format('N')].' '.$jour->format('d/m'), 'heures' => $liste];
            }
        }

        return null;
    }

    /** Créneau choisi s'il est encore proposé et libre, sinon null. */
    public function creneauValide(string $valeur, \DateTimeImmutable $maintenant): ?\DateTimeImmutable
    {
        foreach ($this->creneaux($maintenant)['heures'] ?? [] as $c) {
            if ($c['valeur'] === $valeur && $c['libre']) {
                return new \DateTimeImmutable($valeur);
            }
        }

        return null;
    }

    /**
     * Rappels pas encore faits, triés par heure (liste de la caisse).
     *
     * @return list<DemandeDevis>
     */
    public function aRappeler(): array
    {
        return $this->em->createQuery('SELECT d FROM '.DemandeDevis::class.' d WHERE d.nature = :n AND d.rappelLe IS NOT NULL AND d.rappelFaitLe IS NULL ORDER BY d.rappelLe ASC')
            ->setParameter('n', DemandeDevis::NATURE_RAPPEL)->setMaxResults(200)->getResult();
    }

    /**
     * Prévient la direction, une fois, des rappels non faits à l'heure promise.
     * Lancée avec app:messagerie:delais (chaque minute, au local).
     */
    public function alerterRetards(\DateTimeImmutable $maintenant): int
    {
        $n = 0;
        foreach ($this->aRappeler() as $d) {
            if (null !== $d->getRappelAlerteLe() || $d->getRappelLe() > $maintenant->modify(\sprintf('-%d minutes', self::RETARD_MINUTES))) {
                continue;
            }
            $d->noterRappelAlerte($maintenant);
            $this->notifications?->notifierRoles(['ROLE_ADMIN'], \sprintf('Rappel promis à %s h non fait : %s (%s), demande %s.', $d->getRappelLe()->format('G'), $d->getNom(), $d->getTelephoneLocal(), $d->getNumero()), 'app_messages_clients_site', ['onglet' => 'rappels']);
            ++$n;
        }
        if ($n > 0) {
            $this->em->flush();
        }

        return $n;
    }

    /** @return array{0: int, 1: int, 2: int} début, fin, rappels par heure */
    private function reglages(): array
    {
        $debut = max(0, min(23, (int) $this->parametres->valeur('site_engagements', 'rappel_debut')));
        $fin = max($debut, min(23, (int) $this->parametres->valeur('site_engagements', 'rappel_fin')));

        return [$debut, $fin, max(1, (int) $this->parametres->valeur('site_engagements', 'rappel_par_heure'))];
    }

    /** @return array<int, int> */
    private function prises(\DateTimeImmutable $jour): array
    {
        $lignes = $this->em->createQuery('SELECT d.rappelLe FROM '.DemandeDevis::class.' d WHERE d.nature = :n AND d.rappelLe >= :a AND d.rappelLe < :b')
            ->setParameter('n', DemandeDevis::NATURE_RAPPEL)->setParameter('a', $jour)->setParameter('b', $jour->modify('+1 day'))->getScalarResult();
        $par = [];
        foreach ($lignes as $l) {
            $h = (int) (new \DateTimeImmutable((string) $l['rappelLe']))->format('G');
            $par[$h] = ($par[$h] ?? 0) + 1;
        }

        return $par;
    }

    /** @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $plages */
    private static function dansPlages(\DateTimeImmutable $moment, array $plages): bool
    {
        foreach ($plages as [$a, $b]) {
            if ($moment >= $a && $moment < $b) {
                return true;
            }
        }

        return false;
    }
}
