<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\Push\EnvoiPush;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Notifications de l'équipe à niveaux (maquettes NT01 à NT05,
 * docs/notifications-equipe.md).
 *
 * Pour créer une notification depuis un autre module (par exemple une
 * @mention ou un message privé de la messagerie interne) :
 *
 *     $notificationsEquipe->notifier(
 *         type: 'mention',                     // TypesNotification::TYPES
 *         titre: 'Awa vous cite',              // sans montant
 *         destinataires: [$personne],          // User, ou droits (« ROLE_ADMIN »)
 *         detail: '« Le devis inclut la pose ? »',
 *         action: 'Répondre',
 *         route: 'app_chat_index',
 *         parametres: [],
 *         empreinte: 'mention:fil:42',          // regroupe les répétitions
 *     );
 *     $em->flush();
 *
 * Le niveau vient de la sorte (une mention est « Urgent ») ; les réglages
 * par rôle décident qui la reçoit et par quel moyen ; pendant les heures
 * calmes, seules les critiques dérangent. N'appelle pas flush().
 */
final class NotificationsEquipe implements ResetInterface
{
    /** Passage des sources au plus toutes les N secondes (interrogation des écrans). */
    public const PASSAGE_SECONDES = 60;

    /** @var list<User>|null */
    private ?array $actifs = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $depot,
        private readonly UserRepository $utilisateurs,
        private readonly ReglagesNotifications $reglages,
        private readonly CanauxExternes $canaux,
        private readonly EnvoiPush $push,
        private readonly SourcesNotifications $sources,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    public function reset(): void
    {
        $this->actifs = null;
    }

    public function reglages(): ReglagesNotifications
    {
        return $this->reglages;
    }

    /**
     * Crée (ou regroupe) une notification pour chaque destinataire qui doit
     * la recevoir. N'appelle pas flush().
     *
     * @param list<User|string>    $destinataires personnes, ou droits : toutes les personnes actives qui ont l'un d'eux
     * @param array<string, mixed> $parametres
     *
     * @return list<Notification> les notifications créées ou regroupées
     */
    public function notifier(
        string $type,
        string $titre,
        array $destinataires,
        ?string $detail = null,
        ?string $action = null,
        ?string $route = null,
        array $parametres = [],
        ?string $empreinte = null,
        ?int $montant = null,
        ?string $droitMontant = null,
        ?\DateTimeImmutable $maintenant = null,
    ): array {
        if (!TypesNotification::existe($type)) {
            throw new \InvalidArgumentException('Sorte de notification inconnue : '.$type);
        }

        return $this->distribuer(new Alerte($type, $empreinte ?? $type.':'.sha1($titre.'|'.$route.'|'.json_encode($parametres)), $titre, $destinataires, $detail, $action, $route, $parametres, $montant, $droitMontant), $maintenant ?? new \DateTimeImmutable(), true);
    }

    /**
     * L'action est faite : les notifications de cette empreinte disparaissent
     * pour tout le monde. N'appelle pas flush().
     */
    public function resoudre(string $empreinte, string $resolution = Notification::RESOLUE_ACTION, ?\DateTimeImmutable $le = null): int
    {
        $n = 0;
        foreach ($this->depot->parEmpreinte($empreinte) as $notification) {
            if (!$notification->estResolue()) {
                $notification->resoudre($resolution, $le ?? new \DateTimeImmutable());
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Met les notifications d'une sorte au niveau de ce que la source
     * constate : nouvelles alertes distribuées, celles qui durent mises à
     * jour, celles qui ont cessé résolues toutes seules. N'appelle pas flush().
     *
     * @param list<Alerte> $vivantes
     *
     * @return array{creees: int, resolues: int}
     */
    public function synchroniser(string $type, array $vivantes, \DateTimeImmutable $maintenant): array
    {
        $empreintes = [];
        $creees = 0;
        foreach ($vivantes as $alerte) {
            $empreintes[$alerte->empreinte] = true;
            $creees += \count($this->distribuer($alerte, $maintenant, false));
        }
        $resolues = 0;
        foreach ($this->depot->activesDuType($type) as $n) {
            if (null !== $n->getEmpreinte() && !isset($empreintes[$n->getEmpreinte()])) {
                $n->resoudre(Notification::RESOLUE_AUTO, $maintenant);
                ++$resolues;
            }
        }

        return ['creees' => $creees, 'resolues' => $resolues];
    }

    /**
     * Passe toutes les sources (au local seulement) puis enregistre. Au plus
     * une fois par minute quand ce sont les écrans qui demandent.
     *
     * @return array<string, array{creees: int, resolues: int}>|null null si le passage n'était pas dû
     */
    public function actualiser(\DateTimeImmutable $maintenant, bool $force = false): ?array
    {
        if ($this->enLigne) {
            return null;
        }
        if (!$force) {
            $du = false;
            $this->cache->get('notifications_equipe_passage_'.intdiv($maintenant->getTimestamp(), self::PASSAGE_SECONDES), static function (ItemInterface $item) use (&$du): bool {
                $item->expiresAfter(self::PASSAGE_SECONDES * 2);
                $du = true;

                return true;
            });
            if (!$du) {
                return null;
            }
        }
        $bilan = [];
        foreach ($this->sources->constater($maintenant) as $type => $vivantes) {
            try {
                $bilan[$type] = $this->synchroniser($type, $vivantes, $maintenant);
            } catch (\Throwable $e) {
                $this->logger->warning('Notifications de l\'équipe : source {type} non lue ({classe}).', ['type' => $type, 'classe' => $e::class]);
            }
        }
        $this->em->flush();

        return $bilan;
    }

    /**
     * Remontée des urgentes et critiques non traitées : au responsable du
     * rôle, puis à la direction. N'appelle pas flush().
     *
     * @return int notifications remontées (groupes)
     */
    public function escalader(\DateTimeImmutable $maintenant): int
    {
        $valeurs = $this->reglages->valeurs();
        $calme = ReglesNotifications::estCalme($maintenant, $valeurs['calme']);
        $groupes = [];
        foreach ($this->depot->activesGraves() as $n) {
            // Une notification reçue par remontée ne remonte pas elle-même.
            if ($n->getEscalade() < 9) {
                $groupes[$n->getEmpreinte() ?? 'n'.$n->getId()][] = $n;
            }
        }
        $remontees = 0;
        foreach ($groupes as $lignes) {
            $premiere = $lignes[0];
            $niveau = ReglesNotifications::remontee($premiere, $maintenant, $valeurs['escalade']);
            if (null === $niveau || ($calme && Notification::CRITIQUE !== $premiere->getNiveau())) {
                continue;
            }
            $deja = array_map(static fn (Notification $n): ?int => $n->getDestinataire()?->getId(), $lignes);
            $vers = [];
            if (1 === $niveau) {
                foreach ($lignes as $n) {
                    $role = null !== $n->getDestinataire() ? ReglagesNotifications::roleDe($n->getDestinataire()) : null;
                    $id = null !== $role ? ($valeurs['escalade']['responsables'][$role] ?? null) : null;
                    if (null !== $id && null !== ($u = $this->utilisateurs->find($id)) && $u->isActif()) {
                        $vers[$u->getId()] = $u;
                    }
                }
                if ([] === array_diff(array_keys($vers), $deja)) {
                    // Pas de responsable réglé (ou déjà prévenu) : directement la direction.
                    $niveau = 2;
                }
            }
            if (2 === $niveau) {
                $vers = [];
                foreach ($this->actifsAyant(['ROLE_ADMIN']) as $u) {
                    $vers[$u->getId()] = $u;
                }
            }
            $attente = intdiv($maintenant->getTimestamp() - ($premiere->getDateCreation()?->getTimestamp() ?? $maintenant->getTimestamp()), 60);
            foreach ($vers as $id => $u) {
                if (\in_array($id, $deja, true)) {
                    continue;
                }
                $copie = $this->nouvelle($premiere->getType() ?? 'mention', $premiere->getMessage(), \sprintf('Remontée : non traitée depuis %s · %s', self::duree($attente), $premiere->getDetail() ?? ''), $premiere->getActionLibelle(), $premiere->getRoute(), $premiere->getRouteParametres(), $premiere->getEmpreinte(), $premiere->getMontant(), $premiere->getDroitMontant(), $u, false);
                $copie->escalader(9, $maintenant);
                $this->em->persist($copie);
                $role = ReglagesNotifications::roleDe($u);
                $canaux = $this->reglages->canaux($role, $copie->getType() ?? 'mention');
                // À la direction, une critique non traitée part aussi par WhatsApp (si la clé Meta est posée).
                $this->canaux->prevoir($copie, ['whatsapp' => Notification::CRITIQUE === $copie->getNiveau() && $canaux['whatsapp'], 'sms' => Notification::CRITIQUE === $copie->getNiveau() && $canaux['sms'], 'email' => $canaux['email']], $valeurs['payants_par_jour']);
            }
            foreach ($lignes as $n) {
                $n->escalader($niveau, $maintenant);
            }
            ++$remontees;
        }

        return $remontees;
    }

    /**
     * Rappel sur le téléphone des urgentes non traitées (toutes les 30 min
     * par défaut), hors heures calmes. N'appelle pas flush().
     */
    public function rappeler(\DateTimeImmutable $maintenant): int
    {
        $valeurs = $this->reglages->valeurs();
        if (ReglesNotifications::estCalme($maintenant, $valeurs['calme'])) {
            return 0;
        }
        $n = 0;
        foreach ($this->depot->activesGraves() as $notification) {
            if (Notification::URGENT !== $notification->getNiveau() || $notification->estReportee($maintenant)) {
                continue;
            }
            $depuis = $notification->getRappeleLe() ?? $notification->getDateCreation();
            if (null !== $depuis && $depuis->getTimestamp() + $valeurs['rappel_minutes'] * 60 <= $maintenant->getTimestamp()) {
                $notification->marquerRappelee($maintenant);
                $notification->setSilencieuse(false);
                $this->push->aEnvoyer($notification);
                ++$n;
            }
        }

        return $n;
    }

    public function reporter(Notification $n, string $duree, \DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        $fin = ReglesNotifications::finReport($duree, $maintenant);
        $n->reporter($fin);

        return $fin;
    }

    /**
     * Ce que l'écran de cette personne doit montrer, rangé : critiques
     * (bannière), urgentes, à faire, info, mentions ; reportées à part.
     *
     * @return array{critiques: list<Notification>, urgentes: list<Notification>, a_faire: list<Notification>, info: list<Notification>, mentions: list<Notification>, reportees: list<Notification>, toutes: list<Notification>, non_lues: int}
     */
    public function pour(User $u, \DateTimeImmutable $maintenant): array
    {
        $listes = ['critiques' => [], 'urgentes' => [], 'a_faire' => [], 'info' => [], 'mentions' => [], 'reportees' => [], 'toutes' => [], 'non_lues' => 0];
        $actives = array_values(array_filter($this->depot->actives($u), static fn (Notification $n): bool => NotificationService::peutRecevoir($u, $n->getRoute(), true)));
        usort($actives, ReglesNotifications::comparer(...));
        foreach ($actives as $n) {
            if ($n->estReportee($maintenant)) {
                $listes['reportees'][] = $n;
                continue;
            }
            $listes['toutes'][] = $n;
            $listes[match ($n->getNiveau()) {
                Notification::CRITIQUE => 'critiques',
                Notification::URGENT => 'urgentes',
                Notification::A_FAIRE => 'a_faire',
                default => 'info',
            }][] = $n;
            if ('mention' === $n->getType()) {
                $listes['mentions'][] = $n;
            }
            if (!$n->isLue()) {
                ++$listes['non_lues'];
            }
        }

        return $listes;
    }

    /**
     * État pour l'interrogation des écrans (toutes les 30 s) : compteurs,
     * bannière, petites fenêtres à montrer maintenant (notées montrées).
     * Enregistre.
     *
     * @return array{total: int, compteurs: array<string, int>, critiques: list<Notification>, fenetres: list<Notification>, son: bool, sonner: bool}
     */
    public function etat(User $u, \DateTimeImmutable $maintenant): array
    {
        $listes = $this->pour($u, $maintenant);
        $valeurs = $this->reglages->valeurs();
        $calme = ReglesNotifications::estCalme($maintenant, $valeurs['calme']);
        $sonActif = !$this->reglages->sonCoupe($u);
        $fenetres = [];
        $sonner = false;
        $change = false;
        foreach ($listes['toutes'] as $n) {
            $canaux = null !== $n->getType() ? $this->reglages->canaux(ReglagesNotifications::roleDe($u), $n->getType()) : ['son' => false];
            if (ReglesNotifications::sonCritique($n, $maintenant)) {
                $sonner = $sonner || $canaux['son'];
                $n->marquerAlertee($maintenant);
                $change = true;
            } elseif (ReglesNotifications::petiteFenetre($n, $maintenant, $calme, $valeurs['rappel_minutes'])) {
                if (\count($fenetres) < 3) {
                    $fenetres[] = $n;
                }
                $sonner = $sonner || (Notification::A_FAIRE !== $n->getNiveau() || \in_array($n->getType(), TypesNotification::SON_PAR_DEFAUT, true)) && $canaux['son'];
                $n->marquerAlertee($maintenant);
                $change = true;
            }
        }
        if ($change) {
            $this->em->flush();
        }

        return [
            'total' => $listes['non_lues'],
            'compteurs' => [
                'toutes' => \count($listes['toutes']),
                'critiques' => \count($listes['critiques']),
                'urgentes' => \count($listes['urgentes']),
                'a_faire' => \count($listes['a_faire']),
                'info' => \count($listes['info']),
                'mentions' => \count($listes['mentions']),
                'reportees' => \count($listes['reportees']),
            ],
            'critiques' => $listes['critiques'],
            'fenetres' => $fenetres,
            'son' => $sonActif,
            'sonner' => $sonActif && $sonner,
        ];
    }

    /** Nombre pour la pastille du menu : critiques, urgentes et à faire non lues. */
    public function aTraiter(User $u, \DateTimeImmutable $maintenant): int
    {
        $listes = $this->pour($u, $maintenant);

        return \count(array_filter([...$listes['critiques'], ...$listes['urgentes'], ...$listes['a_faire']], static fn (Notification $n): bool => !$n->isLue()));
    }

    /** « Tout marquer lu » : les infos disparaissent, les autres restent jusqu'à l'action. N'appelle pas flush(). */
    public function toutMarquerLu(User $u): int
    {
        $n = 0;
        foreach ($this->depot->actives($u, 500) as $notification) {
            if (!$notification->isLue()) {
                $notification->marquerLue();
                ++$n;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------

    /**
     * @param bool $personnelle les destinataires donnés en personne passent sans le contrôle des droits de la sorte
     *
     * @return list<Notification>
     */
    private function distribuer(Alerte $alerte, \DateTimeImmutable $maintenant, bool $regrouper): array
    {
        $type = TypesNotification::type($alerte->type);
        $valeurs = $this->reglages->valeurs();
        $calme = ReglesNotifications::estCalme($maintenant, $valeurs['calme']);

        $existantes = [];
        foreach ($this->depot->parEmpreinte($alerte->empreinte) as $n) {
            $existantes[$n->getDestinataire()?->getId()][] = $n;
        }

        $resultat = [];
        foreach ($this->personnes($alerte->destinataires, $type['droits']) as $u) {
            $role = ReglagesNotifications::roleDe($u);
            $canaux = $this->reglages->canaux($role, $alerte->type);
            if (!$canaux['app'] || !NotificationService::peutRecevoir($u, $alerte->route, true)) {
                continue;
            }
            $siennes = $existantes[$u->getId()] ?? [];
            $active = null;
            $dejaTraitee = false;
            foreach ($siennes as $n) {
                if ($n->estActive() || $n->estReportee($maintenant)) {
                    $active ??= $n;
                } elseif (Notification::RESOLUE_AUTO !== $n->getResolution()) {
                    // Déjà vue et traitée pendant cet épisode (info lue, accord donné) : pas de nouvelle.
                    $dejaTraitee = true;
                }
            }
            if (null !== $active) {
                if ($regrouper) {
                    $resultat[] = $active->regrouper($alerte->titre, $alerte->detail, $maintenant);
                } else {
                    $active->actualiserTexte($alerte->titre, $alerte->detail, $alerte->montant, $maintenant);
                }
                continue;
            }
            if ($dejaTraitee && !$regrouper) {
                continue;
            }
            $actionPermise = null === $type['droit_action'] || \in_array($type['droit_action'], $u->getRoles(), true) || \in_array(User::ROLE_ADMIN, $u->getRoles(), true);
            $n = $this->nouvelle($alerte->type, $alerte->titre, $alerte->detail, $actionPermise ? $alerte->action : null, $actionPermise ? $alerte->route : null, $actionPermise ? $alerte->parametres : [], $alerte->empreinte, $alerte->montant, $alerte->droitMontant, $u, $calme);
            $this->em->persist($n);
            $resultat[] = $n;
            // L'ancienne notification du même lien (sans niveau) est remplacée.
            if (null !== $alerte->route) {
                foreach ($this->depot->anciennesMemeLien($u, $alerte->route, $alerte->parametres) as $ancienne) {
                    $ancienne->marquerLue();
                }
            }
            if (ReglesNotifications::peutDeranger($n->getNiveau(), $calme)) {
                $this->canaux->prevoir($n, [
                    'email' => $canaux['email'],
                    'sms' => $canaux['sms'] && Notification::CRITIQUE === $n->getNiveau(),
                    'whatsapp' => $canaux['whatsapp'] && Notification::CRITIQUE === $n->getNiveau(),
                ], $valeurs['payants_par_jour']);
            }
        }

        return $resultat;
    }

    /**
     * @param array<string, mixed> $parametres
     */
    private function nouvelle(string $type, string $titre, ?string $detail, ?string $action, ?string $route, array $parametres, ?string $empreinte, ?int $montant, ?string $droitMontant, User $u, bool $calme): Notification
    {
        $niveau = TypesNotification::niveau($type);

        return (new Notification())
            ->setDestinataire($u)
            ->setType($type)
            ->setNiveau($niveau)
            ->setMessage($titre)
            ->setDetail($detail)
            ->setActionLibelle(null !== $route ? $action : null)
            ->setRoute($route)
            ->setRouteParametres($parametres)
            ->setEmpreinte($empreinte)
            ->setMontant($montant, $droitMontant)
            // Hors heures (sauf critique) et pour l'info : ni téléphone, ni son (EnvoiPush).
            ->setSilencieuse(!ReglesNotifications::peutDeranger($niveau, $calme));
    }

    /**
     * @param list<User|string> $destinataires
     * @param list<string>      $droitsType
     *
     * @return array<int, User>
     */
    private function personnes(array $destinataires, array $droitsType): array
    {
        $personnes = [];
        $droits = [];
        foreach ($destinataires as $d) {
            if ($d instanceof User) {
                if ($d->isActif()) {
                    $personnes[(int) $d->getId()] = $d;
                }
            } else {
                $droits[] = $d;
            }
        }
        if ([] !== $droits) {
            foreach ($this->actifsAyant($droits) as $u) {
                // Par droit : seulement qui a aussi un droit de la sorte (« chacun ne reçoit que ce qui concerne son rôle »).
                if ([] === $droitsType || [] !== array_intersect($droitsType, $u->getRoles()) || \in_array(User::ROLE_ADMIN, $u->getRoles(), true)) {
                    $personnes[(int) $u->getId()] = $u;
                }
            }
        }

        return $personnes;
    }

    /**
     * @param list<string> $droits
     *
     * @return list<User>
     */
    private function actifsAyant(array $droits): array
    {
        $this->actifs ??= $this->utilisateurs->findBy(['actif' => true]);

        // La Direction a tous les droits (hiérarchie des rôles), même s'ils ne sont pas listés sur son compte.
        return array_values(array_filter($this->actifs, static fn (User $u): bool => ([] !== array_intersect($droits, $u->getRoles()) || \in_array(User::ROLE_ADMIN, $u->getRoles(), true) || \in_array('ROLE_USER', $droits, true))
            && !\in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true)));
    }

    public static function duree(int $minutes): string
    {
        return $minutes >= 120 ? intdiv($minutes, 60).' h' : ($minutes >= 60 ? \sprintf('1 h %02d', $minutes - 60) : $minutes.' min');
    }
}
