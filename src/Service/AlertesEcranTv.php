<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\DemandeConception;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Entity\Paiements;
use App\Entity\ReclamationClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Alertes sonores de l'écran TV (demande de Yaya du 15/10/2026,
 * docs/indicateurs.md, section 12) : un carillon puis une voix
 * française annoncent ce qui vient d'arriver à l'imprimerie
 * (« Nouvelle commande en ligne disponible », « Deux nouvelles demandes
 * de devis »…), réglé dans Réglages › Écran TV.
 *
 * Ce que l'écran reçoit à chaque minute : pour chaque événement annoncé,
 * des jetons opaques (HMAC) des fiches qui attendent une action, les mêmes
 * que les pastilles du menu (PastillesMenu). L'écran compare avec ce qu'il
 * a déjà vu (stockage du navigateur) et annonce les nouveaux. Jamais un
 * numéro, un nom ni un montant : la page de l'écran est publique et
 * l'annonce s'entend dans l'imprimerie.
 */
final class AlertesEcranTv
{
    public const COMMANDE_EN_LIGNE = 'commande_en_ligne';
    public const COMMANDE = 'commande';
    public const DEMANDE_DEVIS = 'demande_devis';
    public const MESSAGE_SITE = 'message_site';
    public const DEMANDE_CONCEPTION = 'demande_conception';
    public const PAIEMENT = 'paiement';
    public const RECLAMATION = 'reclamation';
    public const MESSAGE_CLIENT = 'message_client';
    public const DEVIS_ACCEPTE = 'devis_accepte';
    public const COMMANDE_PRETE = 'commande_prete';

    /**
     * Événements annoncés : libellé des réglages, phrase pour un seul,
     * fin de phrase pour plusieurs (« Trois » + fin), annoncé par défaut.
     * Des phrases fixes : rien de la fiche n'y entre.
     *
     * @var array<string, array{libelle: string, aide: string, un: string, plusieurs: string, defaut: bool}>
     */
    public const TYPES = [
        self::COMMANDE_EN_LIGNE => [
            'libelle' => 'Commande passée en ligne', 'aide' => 'à vérifier et confirmer',
            'un' => 'Nouvelle commande en ligne disponible', 'plusieurs' => 'nouvelles commandes en ligne disponibles', 'defaut' => true,
        ],
        self::COMMANDE => [
            'libelle' => 'Commande saisie à l\'imprimerie', 'aide' => 'nouvelle commande du jour',
            'un' => 'Nouvelle commande disponible', 'plusieurs' => 'nouvelles commandes disponibles', 'defaut' => true,
        ],
        self::DEMANDE_DEVIS => [
            'libelle' => 'Demande de devis du site', 'aide' => 'formulaire « Demander un devis »',
            'un' => 'Nouvelle demande de devis', 'plusieurs' => 'nouvelles demandes de devis', 'defaut' => true,
        ],
        self::MESSAGE_SITE => [
            'libelle' => 'Message de la page Contact', 'aide' => 'formulaire de contact du site',
            'un' => 'Nouveau message du site', 'plusieurs' => 'nouveaux messages du site', 'defaut' => true,
        ],
        self::DEMANDE_CONCEPTION => [
            'libelle' => 'Demande de conception', 'aide' => 'envoyée de l\'espace client',
            'un' => 'Nouvelle demande de conception', 'plusieurs' => 'nouvelles demandes de conception', 'defaut' => true,
        ],
        self::PAIEMENT => [
            'libelle' => 'Paiement à valider', 'aide' => 'Orange Money en ligne ou saisi au comptoir',
            'un' => 'Nouveau paiement à valider', 'plusieurs' => 'nouveaux paiements à valider', 'defaut' => true,
        ],
        self::RECLAMATION => [
            'libelle' => 'Réclamation d\'un client', 'aide' => 'écrite dans l\'espace client',
            'un' => 'Nouvelle réclamation d\'un client', 'plusieurs' => 'nouvelles réclamations de clients', 'defaut' => true,
        ],
        self::MESSAGE_CLIENT => [
            'libelle' => 'Message de l\'espace client', 'aide' => 'Clients › Messages',
            'un' => 'Nouveau message d\'un client', 'plusieurs' => 'nouveaux messages de clients', 'defaut' => true,
        ],
        self::DEVIS_ACCEPTE => [
            'libelle' => 'Devis accepté par le client', 'aide' => 'à transformer en commande',
            'un' => 'Devis accepté par un client', 'plusieurs' => 'devis acceptés par des clients', 'defaut' => true,
        ],
        self::COMMANDE_PRETE => [
            'libelle' => 'Commande prête à retirer', 'aide' => 'la colonne « Prêtes à retirer » de l\'écran',
            'un' => 'Une commande est prête à retirer', 'plusieurs' => 'commandes sont prêtes à retirer', 'defaut' => false,
        ],
    ];

    /** Nombres dits en toutes lettres ; au-delà : « Plus de dix ». */
    private const NOMBRES = [2 => 'Deux', 3 => 'Trois', 4 => 'Quatre', 5 => 'Cinq', 6 => 'Six', 7 => 'Sept', 8 => 'Huit', 9 => 'Neuf', 10 => 'Dix'];

    /** Fiches comparées par événement : les plus récentes. */
    public const LIMITE = 30;

    /** Heures de silence par défaut : ni carillon ni voix (l'alerte s'affiche quand même). */
    public const SILENCE_DEBUT = '20:00';
    public const SILENCE_FIN = '07:30';

    public const VOLUME = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** @return list<string> */
    public static function evenementsParDefaut(): array
    {
        return array_keys(array_filter(self::TYPES, static fn (array $t): bool => $t['defaut']));
    }

    /**
     * « Nouvelle commande en ligne disponible », « Trois nouvelles
     * commandes disponibles », « Plus de dix nouveaux paiements à valider ».
     */
    public static function phrase(string $type, int $nombre): string
    {
        $t = self::TYPES[$type];
        if ($nombre <= 1) {
            return $t['un'];
        }

        return (self::NOMBRES[$nombre] ?? 'Plus de dix').' '.$t['plusieurs'];
    }

    /**
     * Phrases de l'écran, préparées ici : clé « 1 » à « 10 », puis « plus ».
     *
     * @param list<string> $types
     *
     * @return array<string, array<string, string>>
     */
    public static function phrases(array $types): array
    {
        $phrases = [];
        foreach ($types as $type) {
            foreach (range(1, 10) as $n) {
                $phrases[$type][(string) $n] = self::phrase($type, $n);
            }
            $phrases[$type]['plus'] = self::phrase($type, 11);
        }

        return $phrases;
    }

    /**
     * Heures de silence, de début à fin, éventuellement par-dessus minuit
     * (20:00 à 07:30). Une heure vide ou deux heures égales : jamais.
     */
    public static function enSilence(?string $debut, ?string $fin, \DateTimeImmutable $maintenant): bool
    {
        if (null === $debut || null === $fin || $debut === $fin) {
            return false;
        }
        $heure = $maintenant->format('H:i');

        return $debut < $fin ? $heure >= $debut && $heure < $fin : $heure >= $debut || $heure < $fin;
    }

    /**
     * Ce que l'écran compare d'une minute à l'autre.
     *
     * @param array{evenements: list<string>, silenceDebut: ?string, silenceFin: ?string} $reglages programme['alertes'] (ProgrammeEcranTv)
     *
     * @return array{silence: bool, jetons: array<string, list<string>>}
     */
    public function etat(array $reglages, \DateTimeImmutable $maintenant): array
    {
        $jetons = [];
        foreach ($reglages['evenements'] as $type) {
            if (isset(self::TYPES[$type])) {
                $jetons[$type] = array_map(fn (int $id): string => $this->jeton($type, $id), $this->identifiants($type, $maintenant));
            }
        }

        return [
            'silence' => self::enSilence($reglages['silenceDebut'], $reglages['silenceFin'], $maintenant),
            'jetons' => $jetons,
        ];
    }

    /**
     * Jeton d'une fiche : le même d'une minute à l'autre, impossible à
     * relier à un numéro sans le secret du serveur.
     */
    public function jeton(string $type, int $id): string
    {
        return substr(hash_hmac('sha256', $type.'|'.$id, $this->secret), 0, 16);
    }

    /**
     * Fiches qui attendent une action (comme les pastilles du menu), les
     * plus récentes d'abord.
     *
     * @return list<int>
     */
    private function identifiants(string $type, \DateTimeImmutable $maintenant): array
    {
        $qb = match ($type) {
            // Passées dans l'espace client, à vérifier et confirmer (hors demandes qui attendent le valideur de l'entreprise).
            self::COMMANDE_EN_LIGNE => $this->requete(Commandes::class, 'c', 'c.canal = :canal AND c.statut = false AND c.deleted = false AND c.soldeOuverture = false AND (c.validationEntreprise IS NULL OR c.validationEntreprise = :validee)', ['canal' => Commandes::CANAL_EN_LIGNE, 'validee' => Commandes::VALIDATION_ACCEPTEE]),
            // Saisies à l'imprimerie depuis hier : une commande créée est nouvelle une fois.
            self::COMMANDE => $this->requete(Commandes::class, 'c', 'c.canal <> :canal AND c.deleted = false AND c.soldeOuverture = false AND c.dateCommande >= :depuis', ['canal' => Commandes::CANAL_EN_LIGNE, 'depuis' => $maintenant->modify('-1 day')->setTime(0, 0)]),
            self::DEMANDE_DEVIS => $this->requete(DemandeDevis::class, 'd', 'd.nature = :nature AND d.traiteeLe IS NULL', ['nature' => DemandeDevis::NATURE_DEVIS]),
            self::MESSAGE_SITE => $this->requete(DemandeDevis::class, 'd', 'd.nature = :nature AND d.traiteeLe IS NULL', ['nature' => DemandeDevis::NATURE_CONTACT]),
            self::DEMANDE_CONCEPTION => $this->requete(DemandeConception::class, 'd', 'd.statut IN (:statuts) AND d.priseEnChargeLe IS NULL', ['statuts' => [DemandeConception::RECUE, DemandeConception::COMMANDEE]]),
            self::PAIEMENT => $this->requete(Paiements::class, 'p', 'p.statut = :statut', ['statut' => Paiements::STATUT_EN_ATTENTE]),
            self::RECLAMATION => $this->requete(ReclamationClient::class, 'r', 'r.statut = :statut', ['statut' => ReclamationClient::OUVERTE]),
            self::MESSAGE_CLIENT => $this->requete(MessageClient::class, 'm', 'm.sens = :sens AND m.traiteLe IS NULL', ['sens' => MessageClient::CLIENT]),
            self::DEVIS_ACCEPTE => $this->requete(Devis::class, 'd', 'd.statut = :statut AND d.deleted = false', ['statut' => Devis::STATUT_ACCEPTE]),
            self::COMMANDE_PRETE => EcranTv::requeteCommandesPretes($this->em)->select('c.id'),
        };

        $alias = $qb->getRootAliases()[0];

        return array_map('intval', $qb->orderBy($alias.'.id', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getSingleColumnResult());
    }

    /**
     * @param class-string         $classe
     * @param array<string, mixed> $parametres
     */
    private function requete(string $classe, string $alias, string $condition, array $parametres): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select($alias.'.id')->from($classe, $alias)->where($condition);
        foreach ($parametres as $cle => $valeur) {
            $qb->setParameter($cle, $valeur);
        }

        return $qb;
    }
}
