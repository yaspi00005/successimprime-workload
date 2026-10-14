<?php

namespace App\Service\EspaceClient;

use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\NotificationClientLue;
use App\Service\Paiement\OrangeMoney;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le fil des notifications tel qu'une personne le voit (cloche, page
 * Notifications, activité récente : maquettes EC01 à EC03 et EC05) :
 * seulement les catégories que ses droits permettent, les montants
 * seulement à qui peut les voir, l'état « nouveau » propre à chacun et
 * les boutons d'action qu'elle a le droit d'utiliser.
 *
 * L'état lu est tenu en ligne seulement, par personne : les clés lues
 * (NotificationClientLue) et la date de « Tout marquer comme lu ».
 */
final class FilNotifications
{
    /** Au-delà, un événement non lu n'est plus « nouveau ». */
    public const NOUVEAU_JOURS = 14;

    /** Pastille de chaque type (maquette EC03) : sigle et couleur. */
    public const BADGES = [
        'bat' => ['BAT', 'bat'],
        'impression' => ['PRO', 'pro'],
        'prete' => ['PRO', 'pro'],
        'livraison_prevue' => ['LIV', 'liv'],
        'route' => ['LIV', 'liv'],
        'livree' => ['LIV', 'liv'],
        'paiement' => ['FAC', 'fac'],
        'facture' => ['FAC', 'fac'],
        'echeance' => ['ÉCH', 'ech'],
        'devis' => ['DEV', 'dev'],
        'message' => ['MSG', 'msg'],
        'reclamation' => ['MSG', 'msg'],
        'conception' => ['PAO', 'pro'],
    ];

    /** Puces de filtre de la page, dans l'ordre. */
    public const FILTRES = [
        'toutes' => 'Toutes',
        'bat' => 'BAT',
        'production' => 'Commandes',
        'factures' => 'Factures',
        'devis' => 'Devis',
        'livraison' => 'Livraisons',
        'messages' => 'Messages',
    ];

    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Le fil de cette personne, le plus récent d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function pour(CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $cle = $compte->getId().'|'.$maintenant->format('Y-m-d H:i');
        if (!isset($this->memo[$cle])) {
            $client = $compte->getClient();
            $fil = null === $client ? [] : $this->espace->notifications($client, $maintenant, 80);
            $this->memo[$cle] = self::presenter($fil, $compte, $this->cleslues($compte), $maintenant, $this->actions(...));
        }

        return $this->memo[$cle];
    }

    /**
     * Filtre, montants, état lu : la partie qui ne touche pas la base.
     *
     * @param list<array<string, mixed>> $fil    EspaceClient::notifications
     * @param array<string, true>        $lues   clés lues par la personne
     * @param ?callable                  $actions (événement, compte) → boutons
     *
     * @return list<array<string, mixed>>
     */
    public static function presenter(array $fil, CompteClient $compte, array $lues, \DateTimeImmutable $maintenant, ?callable $actions = null): array
    {
        $permises = PreferencesNotifications::categoriesPermises($compte);
        $limite = $maintenant->modify(\sprintf('-%d days', self::NOUVEAU_JOURS));
        if (null !== $compte->getNotificationsLuesJusqua() && $compte->getNotificationsLuesJusqua() > $limite) {
            $limite = $compte->getNotificationsLuesJusqua();
        }
        $liste = [];
        foreach ($fil as $n) {
            $categorie = PreferencesNotifications::CATEGORIE_DU_TYPE[$n['type']] ?? null;
            if (null === $categorie || !\in_array($categorie, $permises, true)) {
                continue;
            }
            $cle = NotificationsClient::cle($n);
            [$sigle, $couleur] = self::BADGES[$n['type']] ?? ['MSG', 'msg'];
            $liste[] = [
                'cle' => $cle,
                'categorie' => $categorie,
                'sigle' => $sigle,
                'couleur' => $couleur,
                'nouveau' => !isset($lues[$cle]) && $n['date'] > $limite,
                'montant' => self::montantVisible($n, $compte) ? ($n['montant'] ?? null) : null,
                'actions' => null !== $actions ? $actions($n, $compte) : [],
            ] + $n;
        }

        return $liste;
    }

    /**
     * Montants : factures et paiements aux payeurs (la catégorie est déjà
     * réservée), devis à qui voit les montants (CompteClient::montantsVisibles :
     * valideur ou payeur) ; jamais à un demandeur ni à un compte « Lecture · suivi ».
     *
     * @param array<string, mixed> $n
     */
    public static function montantVisible(array $n, CompteClient $compte): bool
    {
        return match (PreferencesNotifications::CATEGORIE_DU_TYPE[$n['type']] ?? null) {
            'factures' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'devis' => $compte->montantsVisibles(),
            default => false,
        };
    }

    public function nonLues(CompteClient $compte, \DateTimeImmutable $maintenant): int
    {
        return \count(array_filter($this->pour($compte, $maintenant), static fn (array $n): bool => $n['nouveau']));
    }

    /**
     * Non lues par puce de filtre (« BAT · 1 »).
     *
     * @param list<array<string, mixed>> $fil
     *
     * @return array<string, int>
     */
    public static function compter(array $fil): array
    {
        $nombres = array_fill_keys(array_keys(self::FILTRES), 0);
        foreach ($fil as $n) {
            if ($n['nouveau']) {
                ++$nombres['toutes'];
                ++$nombres[$n['categorie']];
            }
        }

        return $nombres;
    }

    /**
     * Regroupé par jour : « Aujourd'hui · mercredi 14 octobre », « Hier · … », « samedi 10 octobre ».
     *
     * @param list<array<string, mixed>> $fil
     *
     * @return list<array{libelle: string, notifications: list<array<string, mixed>>}>
     */
    public static function parJour(array $fil, \DateTimeImmutable $maintenant): array
    {
        $groupes = [];
        foreach ($fil as $n) {
            $jour = $n['date']->format('Y-m-d');
            $groupes[$jour] ??= ['libelle' => self::libelleJour($n['date'], $maintenant), 'notifications' => []];
            $groupes[$jour]['notifications'][] = $n;
        }

        return array_values($groupes);
    }

    public static function libelleJour(\DateTimeInterface $date, \DateTimeImmutable $maintenant): string
    {
        $complet = self::JOURS[(int) $date->format('w')].' '.$date->format('j').' '.self::MOIS[(int) $date->format('n')]
            .($date->format('Y') !== $maintenant->format('Y') ? ' '.$date->format('Y') : '');

        return match ($date->format('Y-m-d')) {
            $maintenant->format('Y-m-d') => 'Aujourd\'hui · '.$complet,
            $maintenant->modify('-1 day')->format('Y-m-d') => 'Hier · '.$complet,
            default => $complet,
        };
    }

    /** « 09:40 » aujourd'hui, « hier », sinon « 10/10 ». */
    public static function quand(\DateTimeInterface $date, \DateTimeImmutable $maintenant): string
    {
        return match ($date->format('Y-m-d')) {
            $maintenant->format('Y-m-d') => $date->format('H:i'),
            $maintenant->modify('-1 day')->format('Y-m-d') => 'hier',
            default => $date->format('d/m'),
        };
    }

    public function marquerLue(CompteClient $compte, string $cle, \DateTimeImmutable $maintenant): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $cle) || null !== $this->em->getRepository(NotificationClientLue::class)->findOneBy(['compte' => $compte, 'cle' => $cle])) {
            return;
        }
        $this->em->persist(new NotificationClientLue($compte, $cle, $maintenant));
        $this->em->flush();
        $this->memo = [];
    }

    /** « Tout marquer comme lu » : une date, les clés lues d'avant ne servent plus. */
    public function toutMarquerLu(CompteClient $compte, \DateTimeImmutable $maintenant): void
    {
        $compte->setNotificationsLuesJusqua($maintenant);
        $this->em->createQuery('DELETE FROM '.NotificationClientLue::class.' l WHERE l.compte = :c AND l.lueLe <= :le')
            ->setParameter('c', $compte)->setParameter('le', $maintenant)->execute();
        $this->em->flush();
        $this->memo = [];
    }

    /**
     * L'événement de cette clé dans le fil de la personne (pour l'ouvrir).
     *
     * @return array<string, mixed>|null
     */
    public function trouver(CompteClient $compte, string $cle, \DateTimeImmutable $maintenant): ?array
    {
        foreach ($this->pour($compte, $maintenant) as $n) {
            if ($n['cle'] === $cle) {
                return $n;
            }
        }

        return null;
    }

    /** @return array<string, true> */
    private function cleslues(CompteClient $compte): array
    {
        if (null === $compte->getId()) {
            return [];
        }
        $cles = $this->em->createQuery('SELECT l.cle FROM '.NotificationClientLue::class.' l WHERE l.compte = :c')
            ->setParameter('c', $compte)->getSingleColumnResult();

        return array_fill_keys($cles, true);
    }

    /**
     * Boutons d'une notification, selon les droits de la personne
     * (maquette EC03). « post » : formulaire (accepter un devis).
     *
     * @param array<string, mixed> $n
     *
     * @return list<array{libelle: string, url: string, style: string, court?: string, post?: bool}>
     */
    private function actions(array $n, CompteClient $compte): array
    {
        $c = $n['cible'] ?? [];
        $url = fn (string $route, array $p = []): string => $this->urls->generate($route, $p);
        $commande = isset($c['commande']) ? $url('app_espace_commande', ['id' => $c['commande']]) : null;

        return match ($n['type']) {
            'bat' => $compte->peutEcrire()
                ? [['libelle' => 'Voir et valider', 'url' => $url('app_espace_bat', ['id' => $c['bat']]), 'style' => 'bat'], ['libelle' => 'Demander une modification', 'url' => $url('app_espace_bat', ['id' => $c['bat']]).'#modifier', 'style' => 'secondaire']]
                : [['libelle' => 'Voir le BAT', 'url' => $url('app_espace_bat', ['id' => $c['bat']]), 'style' => 'bat']],
            'impression', 'prete' => null !== $commande ? [['libelle' => 'Suivre la commande', 'url' => $commande, 'style' => 'bleu']] : [],
            'livraison_prevue', 'route' => null !== $commande ? [['libelle' => 'Suivre la livraison', 'url' => $commande, 'style' => 'liv']] : [],
            // Le bon de livraison signé reste au local : on montre la commande.
            'livree' => null !== $commande ? [['libelle' => 'Voir la commande', 'url' => $commande, 'style' => 'liv']] : [],
            'paiement' => [['libelle' => 'Mes paiements', 'url' => $url('app_espace_factures'), 'style' => 'secondaire']],
            'facture', 'echeance' => $this->actionsFacture($c, $url),
            // Le PDF porte les prix : un demandeur voit le devis dans la liste, sans montant.
            'devis' => array_values(array_filter([
                ['libelle' => 'Voir le devis', 'court' => 'Voir', 'url' => $compte->montantsVisibles() ? $url('app_espace_devis_pdf', ['id' => $c['devis']]) : $url('app_espace_devis'), 'style' => 'dev'],
                Devis::STATUT_ENVOYE === ($c['statut'] ?? null) && $compte->peut(CompteClient::DROIT_VALIDEUR)
                    ? ['libelle' => 'Accepter', 'url' => $url('app_espace_devis_accepter', ['id' => $c['devis']]), 'style' => 'secondaire', 'post' => true]
                    : null,
            ])),
            'message' => [['libelle' => 'Lire le message', 'url' => $url('app_espace_messages'), 'style' => 'bleu']],
            'reclamation' => [['libelle' => 'Voir la réponse', 'url' => $url('app_espace_reclamations'), 'style' => 'bleu']],
            'conception' => [['libelle' => 'Voir la demande', 'url' => $url($n['lien'][0], $n['lien'][1]), 'style' => 'bleu']],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return list<array{libelle: string, url: string, style: string}>
     */
    private function actionsFacture(array $c, \Closure $url): array
    {
        $facture = isset($c['facture']) ? $this->em->find(Factures::class, $c['facture']) : null;
        if (!$facture instanceof Factures) {
            return [];
        }
        $actions = [];
        $commande = $facture->getCommande();
        if (null !== $commande && $commande->isStatut() && !$facture->estProforma() && OrangeMoney::aPayer($commande) > 0) {
            $actions[] = ['libelle' => 'Payer par Orange Money', 'court' => 'Payer', 'url' => $url('app_espace_payer', ['id' => (string) $commande->getPublicId()]), 'style' => 'fac'];
        }
        $actions[] = ['libelle' => 'Télécharger la facture', 'url' => $url('app_espace_facture_pdf', ['id' => $facture->getId()]), 'style' => 'secondaire'];

        return $actions;
    }
}
