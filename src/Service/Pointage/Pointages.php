<?php

namespace App\Service\Pointage;

use App\Entity\AppareilPointage;
use App\Entity\JournalActivite;
use App\Entity\Pointage;
use App\Entity\PointPointage;
use App\Entity\User;
use App\Repository\AppareilPointageRepository;
use App\Repository\PointageRepository;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pointage du personnel (docs/pointage.md) : enregistrer un pointage,
 * tableaux du jour et du mois, corrections, photos.
 */
final class Pointages
{
    public const PHOTO_MAX_OCTETS = 2_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PointageRepository $pointages,
        private readonly AppareilPointageRepository $appareils,
        private readonly Parametres $parametres,
        #[Autowire('%kernel.project_dir%/var/pointage')] private readonly string $dossierPhotos,
    ) {
    }

    /**
     * Règles de calcul : Paramètres › Pointage et › Horaires, plus le
     * nouveau barème (Paramètres › Points du pointage) sous « heures ».
     *
     * @return array<string, mixed>
     */
    public function regles(): array
    {
        $regles = [];
        foreach (['tolerance_minutes', 'anti_doublon_minutes', 'pause_debut', 'pause_fin', 'alerte_depart', 'photos_jours', 'photo', 'telephone_enregistre', 'gps_zone', 'points_heure', 'points_retard', 'points_soiree', 'points_nuit', 'points_oubli'] as $cle) {
            $regles[$cle] = $this->parametres->valeur('pointage', $cle);
        }
        foreach (['ouverture', 'fermeture', 'jours'] as $cle) {
            $regles[$cle] = $this->parametres->valeur('horaires', $cle);
        }
        $regles['heures'] = [];
        foreach (CatalogueParametres::tuiles()['points_pointage']['champs'] as $champ) {
            $regles['heures'][$champ['cle']] = $this->parametres->valeur('points_pointage', $champ['cle']);
        }

        return $regles;
    }

    /**
     * Le nouveau barème s'applique-t-il à une journée ? Oui à partir de
     * la date de début (Paramètres › Points du pointage) ; sans date,
     * à toutes.
     *
     * @param array<string, mixed> $regles
     */
    public static function nouveauCalcul(array $regles, \DateTimeImmutable $jour): bool
    {
        if (!isset($regles['heures'])) {
            return false;
        }
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($regles['heures']['debut'] ?? ''));

        return $debut === false || $jour >= $debut;
    }

    /**
     * Horaire d'une personne pour le nouveau barème : ses horaires propres
     * (fiche E02), sinon l'horaire type de sa fiche ou de son poste.
     *
     * @param array<string, mixed> $heures valeurs de la tuile Points du pointage
     *
     * @return array{libelle: string, debut: string, fin: string, propre: bool}
     */
    public static function horaireDe(User $utilisateur, array $heures): array
    {
        $e = $utilisateur->getEmploye();
        if ($e !== null && $e->getHeureDebut() !== null && $e->getHeureFin() !== null) {
            return ['libelle' => 'Horaires propres', 'debut' => $e->getHeureDebut(), 'fin' => $e->getHeureFin(), 'propre' => true];
        }
        $type = $e !== null ? HoraireType::de($e) : HoraireType::ATELIER;

        return ['libelle' => HoraireType::LIBELLES[$type], 'debut' => (string) $heures[$type.'_debut'], 'fin' => (string) $heures[$type.'_fin'], 'propre' => false];
    }

    /**
     * Règles d'une personne : ses propres horaires (fiche employé, E02)
     * remplacent ceux de l'atelier. L'alerte « pas pointé le départ »
     * vient 2 h après sa fin. Pour une journée du nouveau barème, son
     * horaire type remplace aussi ceux de l'atelier, et « points » porte
     * les valeurs du barème.
     *
     * @param array<string, mixed> $regles
     *
     * @return array<string, mixed>
     */
    public static function reglesDe(User $utilisateur, array $regles, ?\DateTimeImmutable $jour = null): array
    {
        $heures = $regles['heures'] ?? null;
        unset($regles['heures']);
        if ($heures !== null && $jour !== null && self::nouveauCalcul(['heures' => $heures], $jour)) {
            $horaire = self::horaireDe($utilisateur, $heures);
            $regles['ouverture'] = $horaire['debut'];
            $regles['fermeture'] = $horaire['fin'];
            $regles['points'] = $heures;
        }

        $e = $utilisateur->getEmploye();
        if ($e === null) {
            return $regles;
        }
        if ($e->getHeureDebut() !== null) {
            $regles['ouverture'] = $e->getHeureDebut();
            if ($e->getHeureFin() !== null) {
                $regles['fermeture'] = $e->getHeureFin();
                [$h, $m] = array_map('intval', explode(':', $e->getHeureFin()));
                $regles['alerte_depart'] = \sprintf('%02d:%02d', ($h + 2) % 24, $m);
            }
        }
        if ($e->getJoursTravail() !== null) {
            $regles['jours'] = $e->getJoursTravail();
        }

        return $regles;
    }

    /**
     * Ce que le prochain pointage de la personne sera (arrivée ou départ).
     */
    public function prochain(User $utilisateur, \DateTimeImmutable $maintenant): string
    {
        $dernier = $this->pointages->dernier($utilisateur);
        $action = CalculPointage::decider($this->arriveeOuverte($dernier), $dernier?->dernierPointage(), $maintenant, 0);

        return $action === CalculPointage::DEPART ? CalculPointage::DEPART : CalculPointage::ARRIVEE;
    }

    /**
     * Contrôle l'appareil (option « Téléphone enregistré ») : null si la
     * personne peut pointer, sinon le message à afficher.
     */
    public function controleAppareil(?AppareilPointage $appareil): ?string
    {
        if (!$this->regles()['telephone_enregistre']) {
            return null;
        }

        return match (true) {
            $appareil === null => 'Ce téléphone n\'est pas encore enregistré. Demandez à la RH de le valider.',
            $appareil->getEtat() === AppareilPointage::REFUSE => 'Ce téléphone a été refusé par la RH. Pointez avec votre téléphone enregistré.',
            !$appareil->estAutorise() => 'Ce téléphone attend la validation de la RH.',
            default => null,
        };
    }

    /**
     * Appareil reconnu par son cookie ; le crée en attente s'il est nouveau.
     */
    public function appareil(User $utilisateur, ?string $jeton, ?string $agent): ?AppareilPointage
    {
        if ($jeton === null || \strlen($jeton) < 32) {
            return null;
        }
        $appareil = $this->appareils->findOneBy(['empreinte' => AppareilPointage::empreinte($jeton)]);
        if ($appareil !== null) {
            return $appareil->getUtilisateur() === $utilisateur ? $appareil : null;
        }

        $appareil = new AppareilPointage($utilisateur, $jeton, AppareilPointage::nomDepuis($agent));
        // Sans l'option, l'appareil est simplement noté.
        if (!$this->regles()['telephone_enregistre']) {
            $appareil->decider(true, null);
        }
        $this->em->persist($appareil);
        $this->em->flush();

        return $appareil;
    }

    /**
     * Enregistre un pointage.
     *
     * @param array{latitude: float, longitude: float}|null $position
     *
     * @return array{ok: bool, message?: string, pointage?: Pointage, action?: string}
     */
    public function pointer(User $utilisateur, PointPointage $point, ?string $photoJpeg, ?AppareilPointage $appareil, ?array $position, \DateTimeImmutable $maintenant, bool $tablette = false): array
    {
        $regles = $this->regles();

        // La tablette de l'entrée (maquette E05) est branchée par la RH sur
        // ce point : ni téléphone ni position à contrôler, la photo reste.
        if (!$tablette && ($message = $this->controleAppareil($appareil)) !== null) {
            return ['ok' => false, 'message' => $message];
        }
        if ($regles['photo'] && ($photoJpeg === null || !self::estJpeg($photoJpeg))) {
            return ['ok' => false, 'message' => 'La photo est obligatoire. Autorisez la caméra puis réessayez.'];
        }

        $positionNotee = null;
        if ($position !== null) {
            $distance = $point->distance($position['latitude'], $position['longitude']);
            $positionNotee = $position + ['distance' => $distance];
        }
        if (!$tablette && $regles['gps_zone'] && $point->getLatitude() !== null) {
            if ($positionNotee === null) {
                return ['ok' => false, 'message' => 'Votre position est nécessaire pour pointer. Autorisez la localisation puis réessayez.'];
            }
            if ($positionNotee['distance'] > $point->getRayonMetres()) {
                return ['ok' => false, 'message' => \sprintf('Vous êtes à %d m de %s : le pointage se fait sur place (moins de %d m).', $positionNotee['distance'], $point->getNom(), $point->getRayonMetres())];
            }
        }

        $dernier = $this->pointages->dernier($utilisateur);
        $action = CalculPointage::decider($this->arriveeOuverte($dernier), $dernier?->dernierPointage(), $maintenant, (int) $regles['anti_doublon_minutes']);

        if ($action === CalculPointage::DOUBLON) {
            return ['ok' => true, 'pointage' => $dernier, 'action' => $dernier->getDepartLe() ? CalculPointage::DEPART : CalculPointage::ARRIVEE, 'message' => 'Déjà enregistré il y a moins de '.$regles['anti_doublon_minutes'].' min.'];
        }

        $photo = $photoJpeg !== null && self::estJpeg($photoJpeg) ? $this->enregistrerPhoto($photoJpeg, $maintenant) : null;

        if ($action === CalculPointage::DEPART) {
            $dernier->partir($maintenant, $point, $photo, $appareil, $positionNotee);
            $pointage = $dernier;
        } else {
            $pointage = (new Pointage($utilisateur, $maintenant))->arriver($point, $photo, $appareil, $positionNotee);
            $this->em->persist($pointage);
        }
        $this->em->flush();

        return ['ok' => true, 'pointage' => $pointage, 'action' => $action];
    }

    /**
     * Personnel qui pointe : comptes actifs reliés à une fiche employé.
     *
     * @return list<User>
     */
    public function personnel(): array
    {
        $personnes = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->innerJoin('u.employe', 'e')
            ->andWhere('u.actif = true')
            ->orderBy('e.prenom', 'ASC')->addOrderBy('e.nom', 'ASC')
            ->getQuery()->getResult();

        return array_values(array_filter($personnes, static fn (User $u): bool => !\in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true)));
    }

    public static function nom(User $utilisateur): string
    {
        $employe = $utilisateur->getEmploye();
        $nom = trim(($employe?->getPrenom() ?? '').' '.($employe?->getNom() ?? ''));

        return $nom !== '' ? $nom : (string) $utilisateur->getUsername();
    }

    /**
     * Tableau Q04 : une ligne par personne pour un jour.
     *
     * @return array{lignes: list<array<string, mixed>>, presents: int, total: int, retards: list<array<string, mixed>>, nonPointes: list<array<string, mixed>>, sup: int, supNoms: list<string>}
     */
    public function jour(\DateTimeImmutable $jour, \DateTimeImmutable $maintenant): array
    {
        $regles = $this->regles();
        $debut = $jour->setTime(0, 0);
        $parPersonne = [];
        foreach ($this->pointages->entre($debut, $debut->modify('+1 day')) as $p) {
            $parPersonne[$p->getUtilisateur()->getId()][] = $p;
        }

        $lignes = [];
        foreach ($this->personnel() as $u) {
            $presences = $parPersonne[$u->getId()] ?? [];
            if ($presences === []) {
                $lignes[] = ['utilisateur' => $u, 'nom' => self::nom($u), 'pointage' => null, 'calcul' => null];
                continue;
            }
            foreach ($presences as $p) {
                $lignes[] = ['utilisateur' => $u, 'nom' => self::nom($u), 'pointage' => $p, 'calcul' => $this->calcul($p, $regles, $maintenant)];
            }
        }

        $avec = array_filter($lignes, static fn (array $l): bool => $l['pointage'] !== null);
        $retards = array_values(array_filter($avec, static fn (array $l): bool => $l['calcul']['retard'] > 0));
        $nonPointes = array_values(array_filter($avec, static fn (array $l): bool => $l['calcul']['statut'] === CalculPointage::STATUT_NON_POINTE));
        $sup = array_values(array_filter($avec, static fn (array $l): bool => $l['calcul']['sup'] >= CalculPointage::MINUTES_SUP_AFFICHEES));

        return [
            'lignes' => $lignes,
            'presents' => \count(array_unique(array_map(static fn (array $l): int => (int) $l['utilisateur']->getId(), $avec))),
            'total' => \count($this->personnel()),
            'retards' => $retards,
            'nonPointes' => $nonPointes,
            'sup' => array_sum(array_map(static fn (array $l): int => $l['calcul']['sup'], $sup)),
            'supNoms' => array_map(static fn (array $l): string => $l['nom'], $sup),
        ];
    }

    /**
     * Fiche Q05 : journal du mois d'une personne, totaux, points et note.
     * Pour les journées du nouveau barème : heures faites et dues, et
     * l'horaire de la personne.
     *
     * @return array{jours: list<array<string, mixed>>, presents: int, ouvres: int, retards: int, minutes: int, sup: int, points: int, faites: int, dues: int, aValider: int, nouveau: int, note: ?int, horaire: array{libelle: string, debut: string, fin: string, propre: bool, dues: int}, debutNouveau: ?\DateTimeImmutable}
     */
    public function mois(User $utilisateur, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $regles = $this->regles();
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $fin = $debut->modify('+1 month');
        $parJour = [];
        foreach ($this->pointages->entre($debut, $fin, $utilisateur) as $p) {
            $parJour[$p->getArriveeLe()->format('Y-m-d')][] = $p;
        }

        $ouvresSemaine = CalculPointage::joursOuvres((string) self::reglesDe($utilisateur, $regles)['jours']);
        $jours = [];
        $totaux = ['presents' => 0, 'ouvres' => 0, 'retards' => 0, 'minutes' => 0, 'sup' => 0, 'points' => 0, 'faites' => 0, 'dues' => 0, 'aValider' => 0, 'nouveau' => 0];
        for ($j = $debut; $j < $fin && $j <= $maintenant; $j = $j->modify('+1 day')) {
            $ouvre = \in_array((int) $j->format('N'), $ouvresSemaine, true);
            $presences = $parJour[$j->format('Y-m-d')] ?? [];
            if ($ouvre) {
                ++$totaux['ouvres'];
            }
            if ($presences === [] && !$ouvre) {
                continue;
            }
            $calculs = array_map(fn (Pointage $p): array => ['pointage' => $p, 'calcul' => $this->calcul($p, $regles, $maintenant)], $presences);
            if ($calculs !== []) {
                ++$totaux['presents'];
            }
            foreach ($calculs as $c) {
                $totaux['retards'] += $c['calcul']['retard'] > 0 ? 1 : 0;
                $totaux['minutes'] += (int) $c['calcul']['presence'];
                $totaux['sup'] += $c['calcul']['sup'];
                $totaux['points'] += $c['calcul']['total'];
                $totaux['aValider'] += $c['calcul']['bonusEnAttente'] !== null ? 1 : 0;
                if ($c['calcul']['bareme'] === 'heures') {
                    ++$totaux['nouveau'];
                    $totaux['faites'] += (int) $c['calcul']['faites'];
                    $totaux['dues'] += (int) $c['calcul']['dues'];
                }
            }
            $jours[] = ['jour' => $j, 'ouvre' => $ouvre, 'presences' => $calculs];
        }

        // Note sur 100 : sur la base des points « heures faites » dès qu'une journée suit le nouveau barème.
        $pointsJournee = $totaux['nouveau'] > 0 ? (int) $regles['heures']['points_heures_faites'] : (int) $regles['points_heure'];
        $debutNouveau = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($regles['heures']['debut'] ?? ''));

        $horaire = self::horaireDe($utilisateur, $regles['heures']);

        return ['jours' => $jours] + $totaux + [
            'note' => CalculPointage::note($totaux['points'], $totaux['presents'], $pointsJournee),
            'horaire' => $horaire + ['dues' => self::minutesDues($horaire['debut'], $horaire['fin'], $regles)],
            'debutNouveau' => $debutNouveau ?: null,
        ];
    }

    /**
     * Les deux horaires types avec leurs heures dues, pour les écrans qui
     * expliquent le calcul.
     *
     * @param array<string, mixed> $regles
     *
     * @return list<array{libelle: string, debut: string, fin: string, dues: int}>
     */
    public static function horairesTypes(array $regles): array
    {
        return array_map(static fn (string $type): array => [
            'libelle' => HoraireType::LIBELLES[$type],
            'debut' => (string) $regles['heures'][$type.'_debut'],
            'fin' => (string) $regles['heures'][$type.'_fin'],
            'dues' => self::minutesDues((string) $regles['heures'][$type.'_debut'], (string) $regles['heures'][$type.'_fin'], $regles),
        ], array_keys(HoraireType::LIBELLES));
    }

    /** @param array<string, mixed> $regles */
    private static function minutesDues(string $debut, string $fin, array $regles): int
    {
        $pause = !empty($regles['heures']['pause_deduite']);

        return HoraireType::minutesDues($debut, $fin, $pause ? (string) $regles['pause_debut'] : null, $pause ? (string) $regles['pause_fin'] : null);
    }

    /**
     * @param array<string, mixed> $regles
     *
     * @return array<string, mixed>
     */
    public function calcul(Pointage $p, array $regles, \DateTimeImmutable $maintenant): array
    {
        return CalculPointage::presence($p->getArriveeLe(), $p->getDepartLe(), self::reglesDe($p->getUtilisateur(), $regles, $p->getArriveeLe()), $maintenant, $p->isHeuresSupValidees(), $p->getAjustementPoints());
    }

    /**
     * Correction par la RH, motif obligatoire, avant/après au journal.
     */
    public function corriger(Pointage $p, \DateTimeImmutable $arrivee, ?\DateTimeImmutable $depart, string $motif, ?User $par): void
    {
        $avant = ['arrivee' => $p->getArriveeLe()->format('Y-m-d H:i'), 'depart' => $p->getDepartLe()?->format('Y-m-d H:i')];
        $p->corriger($arrivee, $depart, $motif);
        $this->tracer($p, $avant, ['arrivee' => $arrivee->format('Y-m-d H:i'), 'depart' => $depart?->format('Y-m-d H:i'), 'motif' => $motif], $par);
        $this->em->flush();
    }

    public function ajuster(Pointage $p, int $points, string $motif, ?User $par): void
    {
        $avant = ['ajustement' => $p->getAjustementPoints(), 'motif' => $p->getMotifAjustement()];
        $p->ajuster($points, $motif);
        $this->tracer($p, $avant, ['ajustement' => $points, 'motif' => $motif], $par);
        $this->em->flush();
    }

    public function validerHeuresSup(Pointage $p, ?User $par): void
    {
        $p->setHeuresSupValidees(true);
        $this->tracer($p, ['heures_sup_validees' => false], ['heures_sup_validees' => true], $par);
        $this->em->flush();
    }

    public function cheminPhoto(?string $photo): ?string
    {
        if ($photo === null || !preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#', $photo)) {
            return null;
        }
        $chemin = $this->dossierPhotos.'/'.$photo;

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Supprime les photos au-delà de la durée de conservation ; les heures restent.
     */
    public function purgerPhotos(\DateTimeImmutable $maintenant): int
    {
        $limite = $maintenant->modify('-'.(int) $this->regles()['photos_jours'].' days');
        $nombre = 0;
        foreach ($this->pointages->avecPhotosAvant($limite) as $p) {
            foreach ([$p->getPhotoArrivee(), $p->getPhotoDepart()] as $photo) {
                if (($chemin = $this->cheminPhoto($photo)) !== null) {
                    unlink($chemin);
                    ++$nombre;
                }
            }
            $p->oublierPhotos();
        }
        $this->em->flush();

        return $nombre;
    }

    public static function estJpeg(string $octets): bool
    {
        return \strlen($octets) > 100 && \strlen($octets) <= self::PHOTO_MAX_OCTETS && str_starts_with($octets, "\xFF\xD8\xFF");
    }

    private function arriveeOuverte(?Pointage $dernier): ?\DateTimeImmutable
    {
        return $dernier !== null && $dernier->getDepartLe() === null ? $dernier->getArriveeLe() : null;
    }

    private function enregistrerPhoto(string $jpeg, \DateTimeImmutable $le): string
    {
        $relatif = $le->format('Y/m').'/'.bin2hex(random_bytes(16)).'.jpg';
        $chemin = $this->dossierPhotos.'/'.$relatif;
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0770, true);
        }
        file_put_contents($chemin, $jpeg);

        return $relatif;
    }

    /**
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     */
    private function tracer(Pointage $p, array $avant, array $apres, ?User $par): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite('Pointage')
            ->setEntiteId((int) $p->getId())
            ->setAction(JournalActivite::ACTION_MODIFICATION)
            ->setDonneesAvant($avant)
            ->setDonneesApres($apres)
            ->setUtilisateur($par));
    }
}
