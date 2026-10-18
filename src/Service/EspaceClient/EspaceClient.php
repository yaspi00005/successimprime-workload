<?php

namespace App\Service\EspaceClient;

use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\Devis;
use App\Entity\CompteClient;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\ReclamationClient;
use App\Repository\EntrepriseRepository;
use App\Service\Catalogue\NomCommercial;
use App\Service\Conception\DemandesConception;
use App\Service\FichiersPrives;
use App\Service\Messagerie\Conversations;
use App\Service\NotificationService;
use App\Service\Paiement\OrangeMoney;
use App\Service\Parametres\Parametres;
use App\Service\SuiviCommandeService;
use App\Service\Synchro\JournalSynchro;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Espace client (maquettes 13 à 19) : tout ce que le client connecté voit,
 * toujours limité à sa propre fiche. Pas de coûts, de marges ni de noms
 * d'employés ; les montants sont ceux de ses propres documents.
 */
final class EspaceClient
{
    public const DEVIS_VISIBLES = [Devis::STATUT_ENVOYE, Devis::STATUT_VU, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE, Devis::STATUT_CONVERTI];

    /** Gestes du client sur le site, rejoués au local (synchronisation). */
    public const ACTION_BAT_VALIDE = 'bat_valide';
    public const ACTION_BAT_MODIFIE = 'bat_modifie';

    /** Repères posés sur l'image d'un BAT : 10 au plus, 200 caractères chacun. */
    public const REPERES_MAX = 10;
    public const REPERE_TEXTE_MAX = 200;

    /** Le fil remonte à 45 jours. */
    public const FIL_JOURS = 45;

    /** Rappel d'une facture restée due une semaine après l'échéance. */
    public const RELANCE_ECHEANCE_JOURS = 7;

    /** @var array<string, list<array<string, mixed>>> fil par client, pour la requête en cours */
    private array $fils = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuiviCommandeService $suivi,
        private readonly NotificationService $notifications,
        private readonly Parametres $parametres,
        private readonly EntrepriseRepository $entreprises,
        private readonly JournalSynchro $journal,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projet,
    ) {
    }

    /**
     * Commandes du client. Le solde d'ouverture (ce qu'il devait au
     * démarrage, docs/import-donnees.md) n'est pas une commande : il n'est
     * dans la liste que pour le solde, que seul le payeur voit.
     *
     * @return list<Commandes>
     */
    public function commandes(Clients $client, int $nombre = 100, bool $soldesOuverture = false): array
    {
        $qb = $this->em->getRepository(Commandes::class)->createQueryBuilder('c')
            // Une demande refusée par l'entreprise reste visible avec son motif, même classée par l'atelier.
            ->where('c.clients = :cl')->andWhere('c.deleted = false OR c.validationEntreprise = :refusee')->setParameter('refusee', Commandes::VALIDATION_REFUSEE)
            // Ses commandes passées en ligne se voient aussi pendant que l'atelier les confirme.
            ->andWhere('c.statut = true OR c.canal = :enLigne')->setParameter('enLigne', Commandes::CANAL_EN_LIGNE)
            ->setParameter('cl', $client)->orderBy('c.id', 'DESC')->setMaxResults($nombre);
        if (!$soldesOuverture) {
            $qb->andWhere('c.soldeOuverture = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * La commande du client, ou null si elle est à un autre. Le solde
     * d'ouverture ne se trouve que pour le payer (pages du payeur).
     */
    public function commande(Clients $client, string $publicId, bool $soldeOuverture = false): ?Commandes
    {
        foreach ($this->em->getRepository(Commandes::class)->findBy(['clients' => $client]) as $c) {
            if (($c->isDeleted() && !$c->estRefuseeParEntreprise()) || ($c->estSoldeOuverture() && !$soldeOuverture)) {
                continue;
            }
            if ((string) $c->getPublicId() === $publicId && ($c->isStatut() || Commandes::CANAL_EN_LIGNE === $c->getCanal())) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Carte d'une commande : titre court, étapes, avancement.
     *
     * @return array{commande: Commandes, titre: string, etapes: list<array<string, mixed>>, courante: ?array<string, mixed>, avancement: int, terminee: bool, annulee: bool, batEnAttente: bool, aConfirmer: bool, aValiderEntreprise: bool, refuseeEntreprise: bool}
     */
    public function carte(Commandes $c): array
    {
        $etapes = $this->suivi->etapesClient($c);
        $faites = \count(array_filter($etapes, static fn (array $e): bool => 'faite' === $e['etat']));
        $courante = null;
        foreach ($etapes as $e) {
            if ('en_cours' === $e['etat']) {
                $courante = $e;
                break;
            }
        }

        return [
            'commande' => $c,
            'titre' => self::titre($c),
            'etapes' => $etapes,
            'courante' => $courante,
            'avancement' => (int) round(100 * $faites / \count($etapes)),
            'terminee' => null === $courante,
            'annulee' => 'annulee' === $c->getStatutTravaux() || $c->estRefuseeParEntreprise(),
            'batEnAttente' => $this->batEnAttente($c),
            'aConfirmer' => !$c->isStatut(),
            'aValiderEntreprise' => $c->attendValidationEntreprise(),
            'refuseeEntreprise' => $c->estRefuseeParEntreprise(),
        ];
    }

    /**
     * Les cinq étapes du tableau de bord (maquette EC01) : Commande › BAT ›
     * Impression › Finition › Livraison (prête, puis livrée ou retirée).
     *
     * @param list<array{cle: string, etat: string}> $etapes SuiviCommandeService::etapesClient
     *
     * @return array{etapes: list<array{libelle: string, etat: string}>, courante: ?string, avancement: int}
     */
    public static function etapesTableau(array $etapes, bool $retrait): array
    {
        $etat = array_column($etapes, 'etat', 'cle');
        $livraison = match (true) {
            'faite' === ($etat['livree'] ?? null) => 'faite',
            'a_venir' !== ($etat['prete'] ?? 'a_venir') || 'a_venir' !== ($etat['livree'] ?? 'a_venir') => 'en_cours',
            default => 'a_venir',
        };
        $cinq = [
            ['libelle' => 'Commande', 'etat' => $etat['recue'] ?? 'faite'],
            ['libelle' => 'BAT', 'etat' => $etat['bat'] ?? 'a_venir'],
            ['libelle' => 'Impression', 'etat' => $etat['impression'] ?? 'a_venir'],
            ['libelle' => 'Finition', 'etat' => $etat['finition'] ?? 'a_venir'],
            ['libelle' => $retrait ? 'Retrait' : 'Livraison', 'etat' => $livraison],
        ];
        $courante = null;
        $rang = 5;
        foreach ($cinq as $i => $e) {
            if ('en_cours' === $e['etat']) {
                $courante = $e['libelle'];
                $rang = $i;
                break;
            }
        }

        // Barre du téléphone : les étapes faites, et la moitié de celle en cours.
        return ['etapes' => $cinq, 'courante' => $courante, 'avancement' => (int) round(100 * min(5, $rang + (null !== $courante ? .5 : 0)) / 5)];
    }

    private function batEnAttente(Commandes $c): bool
    {
        foreach ($c->getCommandesDetails() as $l) {
            $dernier = null;
            foreach ($l->getControlesPrePresse() as $ctl) {
                $dernier = null === $dernier || $ctl->getId() > $dernier->getId() ? $ctl : $dernier;
            }
            if (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $dernier?->getStatut()) {
                return true;
            }
        }

        return false;
    }

    /** « Banderole 300 × 100 × 2 », ou « Cartes de visite × 500 + 2 autres ». */
    public static function titre(Commandes $c): string
    {
        if ($c->estSoldeOuverture()) {
            return 'Solde d\'ouverture';
        }
        $lignes = array_values(array_filter($c->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
        if ([] === $lignes) {
            return 'Commande '.$c->getNumero();
        }
        $titre = self::titreLigne($lignes[0]);

        return \count($lignes) > 1 ? \sprintf('%s + %d autre%s', $titre, \count($lignes) - 1, \count($lignes) > 2 ? 's' : '') : $titre;
    }

    /** « Autocollant · Vinyle adhésif 100 × 50 × 2 » : nom commercial d'abord (docs/noms-commerciaux.md). */
    public static function titreLigne(CommandesDetails $l): string
    {
        $nom = trim(NomCommercial::texteLigne($l->getDesignation(), NomCommercial::elementDeLigne($l))) ?: 'Travail';

        return $nom.self::mesuresLigne($l, $nom);
    }

    /** Ce qui suit le nom d'une ligne : « 100 × 50 × 2 » (la taille seulement si le nom ne la porte pas). */
    public static function mesuresLigne(CommandesDetails $l, string $nom): string
    {
        $largeur = (float) $l->getLargeur();
        $longueur = (float) $l->getLongueur();
        // La désignation porte parfois déjà la taille : « Bâche (400 x 150 cm) ».
        $taille = $largeur > 0 && $longueur > 0 && !preg_match('/\d\s*[x×]\s*\d/u', $nom) ? ' '.self::nombre($largeur).' × '.self::nombre($longueur) : '';

        // Quantité donnée en m² (pièces au m²) : la surface demandée, que « Refaire » reprend.
        $m2 = 'm2' === $l->getUniteDemandee() && null !== $l->getQuantiteDemandee() ? ' ('.number_format($l->getQuantiteDemandee(), 2, ',', ' ').' m²)' : '';

        return $taille.' × '.self::nombre((float) $l->getQuantite()).$m2;
    }

    private static function nombre(float $n): string
    {
        return number_format($n, floor($n) == $n ? 0 : 1, ',', ' ');
    }

    /**
     * BAT du client : dernier contrôle de chaque ligne qui en demande un.
     *
     * @return list<ControlePrePresse>
     */
    public function bats(Clients $client, bool $aValiderSeulement = false): array
    {
        /** @var list<ControlePrePresse> $controles */
        $controles = $this->em->createQueryBuilder()->select('ctl')->from(ControlePrePresse::class, 'ctl')
            ->join('ctl.commandeDetail', 'd')->join('d.commande', 'c')
            ->where('c.clients = :cl')->andWhere('c.statut = true')->andWhere('c.deleted = false')
            ->setParameter('cl', $client)->orderBy('ctl.id', 'DESC')->setMaxResults(200)
            ->getQuery()->getResult();
        $vus = [];
        $liste = [];
        foreach ($controles as $ctl) {
            $ligne = $ctl->getCommandeDetail()?->getId();
            if (isset($vus[$ligne])) {
                continue;
            }
            $vus[$ligne] = true;
            $envoye = ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut();
            if ($aValiderSeulement ? $envoye : ($envoye || $ctl->isBatValide() || ($ctl->isBatNecessaire() && null !== $this->conception($ctl)?->getDernierBatLe()))) {
                $liste[] = $ctl;
            }
        }

        return $liste;
    }

    /** Le contrôle s'il appartient au client. */
    public function bat(Clients $client, int $id): ?ControlePrePresse
    {
        $ctl = $this->em->find(ControlePrePresse::class, $id);

        return $ctl instanceof ControlePrePresse && $ctl->getCommandeDetail()?->getCommande()?->getClients()?->getId() === $client->getId() ? $ctl : null;
    }

    public function conception(ControlePrePresse $ctl): ?Conception
    {
        return $this->em->getRepository(Conception::class)->findOneBy(['controle' => $ctl]);
    }

    /**
     * Fichiers montrés au client pour un BAT : ceux préparés par l'atelier
     * (dernière version), sinon les siens.
     *
     * @return list<CommandeDetailFichier>
     */
    public function fichiersBat(ControlePrePresse $ctl): array
    {
        $fichiers = array_values(array_filter($ctl->getFichiers()->toArray(), static fn (CommandeDetailFichier $f): bool => $f->isActif() && $f->estUploadTermine()));
        $internes = array_values(array_filter($fichiers, static fn (CommandeDetailFichier $f): bool => CommandeDetailFichier::ORIGINE_CLIENT !== $f->getOrigine()));
        $liste = [] !== $internes ? $internes : $fichiers;
        if ([] === $liste) {
            return [];
        }
        $version = max(array_map(static fn (CommandeDetailFichier $f): int => $f->getVersion(), $liste));

        return array_values(array_filter($liste, static fn (CommandeDetailFichier $f): bool => $f->getVersion() === $version));
    }

    /**
     * Images du BAT, dans l'ordre : les repères du client s'y rapportent par leur rang.
     *
     * @return list<CommandeDetailFichier>
     */
    public function imagesBat(ControlePrePresse $ctl): array
    {
        return array_values(array_filter($this->fichiersBat($ctl), static fn (CommandeDetailFichier $f): bool => str_starts_with((string) $f->getTypeMime(), 'image/')));
    }

    /** Fichier d'un BAT du client, sur le disque, ou null. */
    public function cheminFichier(Clients $client, int $id): ?array
    {
        $f = $this->em->find(CommandeDetailFichier::class, $id);
        if (!$f instanceof CommandeDetailFichier || $f->getCommandeDetail()?->getCommande()?->getClients()?->getId() !== $client->getId() || !$f->isActif()) {
            return null;
        }
        $chemin = $this->cheminSurDisque($f);

        return null === $chemin ? null : ['chemin' => $chemin, 'nom' => (string) $f->getNomOriginal(), 'type' => (string) ($f->getTypeMime() ?: 'application/octet-stream')];
    }

    /**
     * Où le fichier est rangé sur ce serveur, ou null. Sert aussi à la
     * synchronisation (FichiersSynchro) dans les deux sens.
     */
    public function cheminSurDisque(CommandeDetailFichier $f): ?string
    {
        return self::cheminDansProjet($this->projet, $f);
    }

    /**
     * Fichier traité en pré-presse sous var/<chemin> (encore sous public/
     * avant app:fichiers:deplacer, FichiersPrives), sinon rangé à plat
     * dans var/uploads/commandes (fichiers des clients, BAT reçus par la
     * synchronisation). Jamais hors de ces dossiers.
     */
    public static function cheminDansProjet(string $projet, CommandeDetailFichier $f): ?string
    {
        if ('' !== trim((string) $f->getChemin()) && null !== ($chemin = (new FichiersPrives($projet))->chemin($f->getChemin()))) {
            return $chemin;
        }
        $nom = basename(trim((string) $f->getNomStockage()));
        if ('' === $nom || '.' === $nom) {
            return null;
        }
        $racine = realpath($projet.'/var/uploads/commandes');
        $reel = realpath($projet.'/var/uploads/commandes/'.$nom);

        return false !== $racine && false !== $reel && str_starts_with($reel, $racine.\DIRECTORY_SEPARATOR) && is_file($reel) ? $reel : null;
    }

    /**
     * Ce que le client coche avant de valider un BAT (maquette CO05) :
     * tout doit être coché, vérifié aussi par le serveur. Les libellés
     * cochés restent avec la validation (frise du BAT).
     *
     * @return array<string, string> clé => libellé
     */
    public static function verificationsBat(?CommandesDetails $ligne): array
    {
        $taille = null !== $ligne ? self::taille($ligne) : null;

        return [
            'orthographe' => 'L\'orthographe des textes',
            'coordonnees' => 'Les numéros de téléphone et les adresses',
            'couleurs' => 'Les couleurs et le logo',
            'taille' => null !== $taille ? 'La taille : '.$taille : 'La taille et le format',
        ];
    }

    /**
     * Les points cochés, dans l'ordre de la liste, ou null s'il en manque un.
     *
     * @return list<string>|null libellés cochés
     */
    public static function verificationsCochees(?CommandesDetails $ligne, mixed $cochees): ?array
    {
        // Seulement des textes : une liste glissée dans l'envoi (verifications[x][]) ne compte pas.
        $cochees = \is_array($cochees) ? array_map('strval', array_filter($cochees, 'is_scalar')) : [];
        $attendues = self::verificationsBat($ligne);

        return [] === array_diff(array_keys($attendues), $cochees) ? array_values($attendues) : null;
    }

    /**
     * Taille d'une ligne pour le client : « 3 m × 1 m », « 20 × 10 cm »
     * (largeur et longueur sont en mètres), ou null sans taille.
     */
    public static function taille(CommandesDetails $l): ?string
    {
        $largeur = (float) $l->getLargeur();
        $longueur = (float) $l->getLongueur();
        if ($largeur <= 0 || $longueur <= 0) {
            return null;
        }
        if ($largeur >= 1 && $longueur >= 1) {
            return self::nombre($largeur).' m × '.self::nombre($longueur).' m';
        }

        return self::nombre(round($largeur * 100, 1)).' × '.self::nombre(round($longueur * 100, 1)).' cm';
    }

    /**
     * Corrections du BAT demandées par le client, et combien restent
     * gratuites (Réglages › Espace client, 2 par défaut). Au-delà, la
     * demande reste possible : elle peut être facturée (aucun prix calculé ici).
     *
     * @return array{faites: int, gratuites: int, restantes: int}
     */
    public function correctionsBat(ControlePrePresse $ctl): array
    {
        $gratuites = max(0, (int) ($this->parametres->valeur('espace_client', 'corrections_bat_gratuites') ?? 2));
        $faites = $this->conception($ctl)?->correctionsDemandees() ?? 0;

        return ['faites' => $faites, 'gratuites' => $gratuites, 'restantes' => max(0, $gratuites - $faites)];
    }

    /** « au-delà des 2 corrections gratuites : peut être facturée ». */
    public static function auDelaDesGratuites(int $gratuites): string
    {
        return match (true) {
            $gratuites <= 0 => 'correction qui peut être facturée',
            1 === $gratuites => 'au-delà de la correction gratuite : peut être facturée',
            default => \sprintf('au-delà des %d corrections gratuites : peut être facturée', $gratuites),
        };
    }

    /**
     * Le client valide le BAT : il est noté validé, la prépresse finit la
     * validation des fichiers et l'envoi en production.
     *
     * @param list<string> $verifications points cochés par le client avant de valider
     */
    public function validerBat(ControlePrePresse $ctl, string $nom, \DateTimeImmutable $maintenant, array $verifications = []): void
    {
        $ctl->setBatNecessaire(true)->setBatValide(true)->setCorrectionNecessaire(false)->setStatut(ControlePrePresse::STATUT_EN_COURS);
        $this->conception($ctl)?->valider($this->parametres->bareme(), $maintenant, 'par le client dans son espace · '.$nom, $verifications);
        $this->journal->noterAction($this->em, self::ACTION_BAT_VALIDE, $ctl, ['nom' => $nom, 'le' => $maintenant->format(\DATE_ATOM), 'verifications' => $verifications]);
        $this->em->flush();
        $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('BAT validé par le client : %s, commande %s.', TableauProduction::titre($ctl->getCommandeDetail()), $ctl->getCommandeDetail()->getCommande()->getNumero()), 'app_controle_pre_presse_controler', ['id' => $ctl->getId()]);
        $this->em->flush();
    }

    /**
     * @param list<array{i: int, x: float, y: float, t: string}> $reperes repères posés sur l'image (voir reperes())
     */
    public function modifierBat(ControlePrePresse $ctl, string $texte, \DateTimeImmutable $maintenant, array $reperes = []): void
    {
        // Compté avant d'ajouter celle-ci : la prépresse sait si elle peut être facturée.
        $corrections = $this->correctionsBat($ctl);
        $ctl->setCorrectionNecessaire(true)->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE);
        $this->conception($ctl)?->retour(Conception::RETOUR_CLIENT, $texte, $maintenant, $reperes);
        $this->journal->noterAction($this->em, self::ACTION_BAT_MODIFIE, $ctl, ['texte' => $texte, 'le' => $maintenant->format(\DATE_ATOM), 'reperes' => $reperes]);
        $this->em->flush();
        $resume = implode(' ; ', array_filter([
            '' !== trim($texte) ? '« '.mb_substr(trim($texte), 0, 120).' »' : null,
            match (\count($reperes)) { 0 => null, 1 => '1 repère sur le BAT', default => \count($reperes).' repères sur le BAT' },
            0 === $corrections['restantes'] ? self::auDelaDesGratuites($corrections['gratuites']) : null,
        ]));
        $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('Modification demandée par le client sur %s (commande %s) : %s', TableauProduction::titre($ctl->getCommandeDetail()), $ctl->getCommandeDetail()->getCommande()->getNumero(), $resume), 'app_controle_pre_presse_controler', ['id' => $ctl->getId()]);
        $this->em->flush();
    }

    /**
     * Repères posés par le client sur les images du BAT, vérifiés : image
     * qui existe, position dans l'image, texte court, 10 au plus.
     *
     * @return list<array{i: int, x: float, y: float, t: string}>
     */
    public static function reperes(mixed $brut, int $images): array
    {
        $reperes = [];
        foreach (\is_array($brut) ? $brut : [] as $r) {
            if (!\is_array($r) || !is_numeric($r['x'] ?? null) || !is_numeric($r['y'] ?? null) || !\is_string($r['t'] ?? null)) {
                continue;
            }
            $i = filter_var($r['i'] ?? 0, \FILTER_VALIDATE_INT);
            $texte = trim((string) preg_replace('/\s+/u', ' ', $r['t']));
            if (false === $i || $i < 0 || $i >= $images || '' === $texte) {
                continue;
            }
            $reperes[] = [
                'i' => $i,
                'x' => round(min(1, max(0, (float) $r['x'])), 4),
                'y' => round(min(1, max(0, (float) $r['y'])), 4),
                't' => mb_substr($texte, 0, self::REPERE_TEXTE_MAX),
            ];
            if (\count($reperes) >= self::REPERES_MAX) {
                break;
            }
        }

        return $reperes;
    }

    /** @return list<Devis> */
    public function devis(Clients $client): array
    {
        return $this->em->getRepository(Devis::class)->createQueryBuilder('d')
            ->where('d.clients = :cl')->andWhere('d.statut IN (:s)')
            ->setParameter('cl', $client)->setParameter('s', self::DEVIS_VISIBLES)
            ->orderBy('d.id', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** Le devis accepté dont la commande est née, ou null. */
    public function devisDeLaCommande(Commandes $commande): ?Devis
    {
        return null === $commande->getId() ? null : $this->em->getRepository(Devis::class)->findOneBy(['commande' => $commande]);
    }

    /** @return list<Factures> */
    public function factures(Clients $client): array
    {
        return $this->em->getRepository(Factures::class)->createQueryBuilder('f')
            ->join('f.commande', 'c')
            ->where('c.clients = :cl')->andWhere('f.etat = :e')
            ->setParameter('cl', $client)->setParameter('e', Factures::ETAT_EMISE)
            ->orderBy('f.id', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /**
     * Reste à payer sur les commandes en cours, et les commandes concernées.
     * Ce qui a été payé par Orange Money en ligne et attend la caisse n'est
     * plus demandé (enValidation).
     *
     * @return array{total: float, enValidation: int, commandes: list<Commandes>}
     */
    public function solde(Clients $client): array
    {
        $enCours = array_values(array_filter($this->commandes($client, 300, true), static fn (Commandes $c): bool => $c->isStatut() && 'annulee' !== $c->getStatutTravaux() && $c->getResteAPayer() > 0));
        $aPayer = array_values(array_filter($enCours, static fn (Commandes $c): bool => OrangeMoney::aPayer($c) > 0));

        return [
            'total' => (float) array_sum(array_map(OrangeMoney::aPayer(...), $aPayer)),
            'enValidation' => array_sum(array_map(OrangeMoney::enValidation(...), $enCours)),
            'commandes' => $aPayer,
        ];
    }

    /** Numéro marchand Orange Money de l'entité principale, s'il est saisi. */
    public function marchandOrangeMoney(): ?string
    {
        if (!(bool) $this->parametres->valeur('paiements', 'orange_money')) {
            return null;
        }
        foreach ($this->entreprises->dansLOrdre() as $e) {
            if (null !== $e->getMarchandOrangeMoney()) {
                return $e->getMarchandOrangeMoney();
            }
        }

        return null;
    }

    /**
     * Fil des notifications (maquettes EC02, EC03), reconstruit à partir
     * des documents du client : BAT prêt, impression lancée, commande
     * prête, livraison prévue, livreur en route, livrée, paiement reçu,
     * facture émise, rappel d'échéance, nouveau devis, réponse de l'atelier
     * à un message ou à une réclamation. Calculé une fois par requête.
     * FilNotifications le filtre selon les droits de la personne,
     * NotificationsClient en envoie les nouveautés.
     *
     * Le montant est à part (« montant ») : il ne s'affiche qu'à qui a le
     * droit de le voir et ne part jamais dans un SMS.
     *
     * @return list<array{type: string, titre: string, texte: string, montant: ?int, date: \DateTimeInterface, lien: array{0: string, 1: array<string, mixed>}, numero: ?string, jour: ?\DateTimeInterface, cible: array<string, mixed>}>
     */
    public function notifications(Clients $client, \DateTimeImmutable $maintenant, int $nombre = 30): array
    {
        $cle = $client->getId().'|'.$maintenant->format('Y-m-d H:i');
        $this->fils[$cle] ??= $this->construireFil($client, $maintenant);

        return \array_slice($this->fils[$cle], 0, $nombre);
    }

    /** @return list<array<string, mixed>> */
    private function construireFil(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $depuis = $maintenant->modify(\sprintf('-%d days', self::FIL_JOURS));
        $fil = [];
        foreach ($this->commandes($client, 40) as $c) {
            $id = (string) $c->getPublicId();
            $suivre = ['app_espace_commande', ['id' => $id]];
            foreach ($c->getPaiements() as $p) {
                if ($p->estValide() && null !== $p->getDate()) {
                    $fil[] = self::evenement('paiement', 'Paiement reçu', \sprintf('%s · commande %s', $p->getModeLabel(), $c->getNumero()), $p->getDateValidation() ?? $p->getDate(), ['app_espace_factures', []], $c->getNumero(), ['montant' => $p->getMontant(), 'cible' => ['commande' => $id]]);
                }
            }
            foreach ($c->getCommandesDetails() as $l) {
                foreach ($l->getControlesPrePresse() as $ctl) {
                    if (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut()) {
                        $conception = $this->conception($ctl);
                        // Date de la dernière version envoyée : chaque nouvelle version compte.
                        $fil[] = self::evenement('bat', \sprintf('BAT V%d disponible', max(1, (int) $conception?->getVersions())), \sprintf('%s · %s', self::titreLigne($l), $c->getNumero()), $conception?->getDernierBatLe() ?? $ctl->getTraiteLe() ?? $ctl->getCreeLe(), ['app_espace_bat', ['id' => $ctl->getId()]], $c->getNumero(), [
                            'cible' => ['bat' => $ctl->getId(), 'commande' => $id, 'avant' => $conception?->getEcheanceBat()],
                        ]);
                    }
                }
                if (null !== $l->getProductionDebuteLe()) {
                    $fil[] = self::evenement('impression', 'En impression', \sprintf('%s · %s%s', self::titreLigne($l), $c->getNumero(), null !== $c->getDateLivraison() ? ' · '.($c->estALivrer() ? 'livraison prévue le ' : 'prête le ').$c->getDateLivraison()->format('d/m') : ''), $l->getProductionDebuteLe(), $suivre, $c->getNumero(), ['cible' => ['commande' => $id]]);
                }
            }
            $carte = $this->carte($c);
            $prete = $carte['etapes'][4];
            if ('faite' === $prete['etat'] && null !== $prete['date'] && 'faite' !== $carte['etapes'][5]['etat']) {
                $fil[] = self::evenement('prete', 'Commande prête', \sprintf('%s · %s', $c->getNumero(), $c->estALivrer() ? 'livraison à venir' : 'à retirer en boutique'), $prete['date'], $suivre, $c->getNumero(), ['cible' => ['commande' => $id]]);
            }
            foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $c]) as $bl) {
                if (null !== ($e = self::evenementLivraison($bl, $c))) {
                    $fil[] = $e;
                }
            }
        }
        foreach ($this->factures($client) as $f) {
            $commande = $f->getCommande();
            $cible = ['facture' => $f->getId(), 'commande' => null !== $commande ? (string) $commande->getPublicId() : null];
            $date = $f->getCreatedAt() ?? $f->getDateFacture();
            if (null !== $date) {
                $fil[] = self::evenement('facture', $f->estProforma() ? 'Facture proforma' : 'Nouvelle facture', \sprintf('%s · commande %s', $f->getNumero(), $commande?->getNumero()), $date, ['app_espace_factures', ['f' => $f->getId()]], $f->getNumero(), ['montant' => $f->getTotalTtc(), 'cible' => $cible]);
            }
            // Payée par Orange Money en attente de la caisse : plus de rappel.
            if (null === $commande || OrangeMoney::aPayer($commande) > 0) {
                array_push($fil, ...self::rappelsEcheance($f, $maintenant));
            }
        }
        foreach ($this->devis($client) as $d) {
            if (null !== $d->getDateDevis()) {
                $ligne = $d->getDevisDetails()->first();
                // « Autocollant · Vinyle adhésif · DV-… » comme les autres événements : le numéro
                // reste collé au prix qui suit (docs/noms-commerciaux.md, recette des accès).
                $nom = false !== $ligne && '' !== trim((string) $ligne->getDesignation()) ? NomCommercial::texteLigne($ligne->getDesignation(), NomCommercial::elementDeLigne($ligne)) : '';
                $fil[] = self::evenement('devis', 'Nouveau devis', trim(('' !== $nom ? $nom.' · ' : '').$d->getNumero()), $d->getDateDevis(), ['app_espace_devis_detail', ['id' => $d->getId()]], $d->getNumero(), [
                    'montant' => $d->getTotalTtc(),
                    'cible' => ['devis' => $d->getId(), 'statut' => $d->getStatut()],
                ]);
            }
        }
        foreach ($this->em->getRepository(MessageClient::class)->findBy(['client' => $client, 'sens' => MessageClient::ATELIER], ['id' => 'DESC'], 20) as $m) {
            // Notification sans montant, pour tous les comptes (le montant se lit dans la conversation, selon les droits).
            $fil[] = self::evenement('message', 'Réponse de l\'atelier', mb_strimwidth(Conversations::sansMontants((string) preg_replace('/\s+/u', ' ', $m->getTexte())), 0, 110, '…'), $m->getCreeLe(), null !== $m->getConversation() ? ['app_espace_message', ['id' => (string) $m->getConversation()->getPublicId()]] : ['app_espace_messages', []], $m->getCommande()?->getNumero(), ['cible' => ['commande' => null !== $m->getCommande() ? (string) $m->getCommande()->getPublicId() : null]]);
        }
        foreach ($this->em->getRepository(ReclamationClient::class)->findBy(['client' => $client], ['id' => 'DESC'], 20) as $r) {
            if (null !== $r->getReponduLe()) {
                $fil[] = self::evenement('reclamation', 'Réponse à votre réclamation', \sprintf('%s · %s', $r->getNumero(), $r->getObjet()), $r->getReponduLe(), ['app_espace_reclamations', []], $r->getNumero());
            }
        }
        array_push($fil, ...DemandesConception::fil($this->em, $client));
        $fil = array_values(array_filter($fil, static fn (array $n): bool => $n['date'] >= $depuis && $n['date'] <= $maintenant));
        usort($fil, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $fil;
    }

    /**
     * @param array{0: string, 1: array<string, mixed>} $lien
     * @param array{montant?: ?int, jour?: ?\DateTimeInterface, cible?: array<string, mixed>} $plus
     *
     * @return array{type: string, titre: string, texte: string, montant: ?int, date: \DateTimeInterface, lien: array{0: string, 1: array<string, mixed>}, numero: ?string, jour: ?\DateTimeInterface, cible: array<string, mixed>}
     */
    public static function evenement(string $type, string $titre, string $texte, \DateTimeInterface $date, array $lien, ?string $numero, array $plus = []): array
    {
        return ['type' => $type, 'titre' => $titre, 'texte' => $texte, 'montant' => $plus['montant'] ?? null, 'date' => $date, 'lien' => $lien, 'numero' => $numero, 'jour' => $plus['jour'] ?? null, 'cible' => $plus['cible'] ?? []];
    }

    /**
     * Où en est un bon de livraison : livrée, livreur en route, reportée
     * après un échec ou livraison prévue (jour et créneau). Ni livreur, ni
     * réceptionnaire, ni photos, ni signature, ni raison de l'échec : ils
     * restent au local. Reportée garde la clé de « prévue » (même type, même
     * date) : rien de plus n'est envoyé au client, l'atelier l'appelle.
     *
     * @return array<string, mixed>|null
     */
    public static function evenementLivraison(BonLivraison $bl, Commandes $c): ?array
    {
        if (BonLivraison::STATUT_ANNULE === $bl->getStatut()) {
            return null;
        }
        $lien = ['app_espace_commande', ['id' => (string) $c->getPublicId()]];
        $cible = ['commande' => (string) $c->getPublicId(), 'livraison' => $bl->getId()];
        $creneau = null !== $bl->getCreneau() && '' !== trim($bl->getCreneau()) ? ' · créneau '.trim($bl->getCreneau()) : '';

        return match (true) {
            null !== $bl->getLivreLe() => self::evenement('livree', \sprintf('Commande %s livrée', $c->getNumero()), \sprintf('Remise à %s', $bl->getLivreLe()->format('H:i')), $bl->getLivreLe(), $lien, $c->getNumero(), ['cible' => $cible]),
            null !== $bl->getEnRouteLe() => self::evenement('route', 'Livreur en route', $c->getNumero().$creneau, $bl->getEnRouteLe(), $lien, $c->getNumero(), ['cible' => $cible, 'jour' => $bl->getEnRouteLe()]),
            $bl->isEchecEnCours() && null !== $bl->getPrevuLe() && null !== ($bl->getValideLe() ?? $bl->getCreeLe()) => self::evenement('livraison_prevue', 'Livraison reportée', $c->getNumero().' · l\'atelier vous recontacte pour une nouvelle date', $bl->getValideLe() ?? $bl->getCreeLe(), $lien, $c->getNumero(), ['cible' => $cible]),
            null !== $bl->getPrevuLe() && null !== ($bl->getValideLe() ?? $bl->getCreeLe()) => self::evenement('livraison_prevue', \sprintf('Livraison prévue le %s', $bl->getPrevuLe()->format('d/m')), $c->getNumero().$creneau, $bl->getValideLe() ?? $bl->getCreeLe(), $lien, $c->getNumero(), ['cible' => $cible, 'jour' => $bl->getPrevuLe()]),
            default => null,
        };
    }

    /**
     * Rappels d'une facture à régler (type ÉCH) : le jour de l'échéance à
     * 8 h, puis une semaine après si elle reste due. Calculés en ligne à
     * partir de la facture synchronisée ; les notes d'appel et promesses
     * des créances restent au local.
     *
     * @return list<array<string, mixed>>
     */
    public static function rappelsEcheance(Factures $f, \DateTimeImmutable $maintenant): array
    {
        if ($f->estProforma() || Factures::ETAT_EMISE !== $f->getEtat() || $f->getResteAPayer() <= 0 || null === $f->getDateEcheance()) {
            return [];
        }
        $echeance = \DateTimeImmutable::createFromInterface($f->getDateEcheance())->setTime(8, 0);
        $lien = ['app_espace_factures', ['f' => $f->getId()]];
        $plus = ['montant' => $f->getResteAPayer(), 'cible' => ['facture' => $f->getId(), 'commande' => null !== $f->getCommande() ? (string) $f->getCommande()->getPublicId() : null, 'echeance' => $echeance]];
        $rappels = [];
        if ($echeance <= $maintenant) {
            $aujourdhui = $echeance->format('Y-m-d') === $maintenant->format('Y-m-d');
            $rappels[] = self::evenement('echeance', $aujourdhui ? 'Rappel : paiement prévu aujourd\'hui' : \sprintf('Échéance du %s', $echeance->format('d/m')), \sprintf('Facture %s', $f->getNumero()), $echeance, $lien, $f->getNumero(), $plus);
        }
        $relance = $echeance->modify(\sprintf('+%d days', self::RELANCE_ECHEANCE_JOURS));
        if ($relance <= $maintenant) {
            $rappels[] = self::evenement('echeance', 'Facture échue', \sprintf('Facture %s · échue depuis le %s', $f->getNumero(), $echeance->format('d/m')), $relance, $lien, $f->getNumero(), $plus);
        }

        return $rappels;
    }

    /**
     * Factures émises qui restent à payer (pas les proformas), l'échéance la
     * plus proche d'abord.
     *
     * @return list<Factures>
     */
    public function facturesARegler(Clients $client): array
    {
        $liste = array_values(array_filter($this->factures($client), static fn (Factures $f): bool => !$f->estProforma() && $f->getResteAPayer() > 0));
        usort($liste, static fn (Factures $a, Factures $b): int => ($a->getDateEcheance() ?? $a->getDateFacture()) <=> ($b->getDateEcheance() ?? $b->getDateFacture()));

        return $liste;
    }

    /**
     * « Mon compte en un coup d'œil » (maquette EC01), pour qui paie :
     * solde dû et part échue, plafond de crédit utilisé, commandes de
     * l'année, Mon solde. Les points fidélité viennent de FideliteExtension.
     *
     * @return array{solde: float, echu: int, plafond: ?int, utilise: ?int, annee: int, commandesAnnee: int, montantAnnee: int, enValidation: int, avoir: int}
     */
    public function apercuCompte(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $solde = $this->solde($client);
        $echu = 0;
        foreach ($this->facturesARegler($client) as $f) {
            if (null !== $f->getDateEcheance() && $f->getDateEcheance()->format('Y-m-d') < $maintenant->format('Y-m-d')) {
                $echu += $f->getResteAPayer();
            }
        }
        $annee = (int) $maintenant->format('Y');
        $commandesAnnee = array_values(array_filter(
            $this->commandes($client, 500),
            static fn (Commandes $c): bool => $c->isStatut() && !$c->isDeleted() && 'annulee' !== $c->getStatutTravaux() && (int) $c->getDateCommande()?->format('Y') === $annee,
        ));
        // Le plafond n'a de sens que pour un client qui paie à terme (entreprise).
        $plafond = CompteClient::clientAPlusieursComptes($client) && (int) $client->getPlafondCredit() > 0 ? (int) $client->getPlafondCredit() : null;

        return [
            'solde' => $solde['total'],
            'echu' => (int) min($echu, $solde['total']),
            'plafond' => $plafond,
            'utilise' => null !== $plafond ? (int) min(999, round(100 * $solde['total'] / $plafond)) : null,
            'annee' => $annee,
            'commandesAnnee' => \count($commandesAnnee),
            'montantAnnee' => array_sum(array_map(static fn (Commandes $c): int => $c->getTotalTtc(), $commandesAnnee)),
            'enValidation' => $solde['enValidation'],
            // Mon solde : l'argent laissé à la boutique (monnaie gardée, dépôts), tenu au local.
            'avoir' => max(0, $client->getSoldeCredit()),
        ];
    }

    /**
     * Livraisons du client (maquette EC01, menu Livraisons) : à venir, en
     * route, livrées, d'après les bons de livraison synchronisés (statut,
     * jour prévu, créneau, heures). Le reste du bon reste au local.
     *
     * @return array{route: list<array<string, mixed>>, aVenir: list<array<string, mixed>>, livrees: list<array<string, mixed>>}
     */
    public function livraisons(Clients $client): array
    {
        $bons = $this->em->createQueryBuilder()->select('bl', 'c')->from(BonLivraison::class, 'bl')->join('bl.commande', 'c')
            ->where('c.clients = :cl')->andWhere('bl.statut != :annule')
            ->setParameter('cl', $client)->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->orderBy('bl.id', 'DESC')->setMaxResults(120)->getQuery()->getResult();
        $liste = ['route' => [], 'aVenir' => [], 'livrees' => []];
        foreach ($bons as $bl) {
            $c = $bl->getCommande();
            $ligne = ['bon' => $bl, 'commande' => $c, 'titre' => self::titre($c)];
            match (true) {
                null !== $bl->getLivreLe() => $liste['livrees'][] = $ligne,
                null !== $bl->getEnRouteLe() => $liste['route'][] = $ligne,
                default => $liste['aVenir'][] = $ligne,
            };
        }
        usort($liste['aVenir'], static fn (array $a, array $b): int => [$a['bon']->getPrevuLe() ?? new \DateTimeImmutable('2999-01-01'), $a['bon']->getId()] <=> [$b['bon']->getPrevuLe() ?? new \DateTimeImmutable('2999-01-01'), $b['bon']->getId()]);

        return $liste;
    }

    /**
     * Recherche de la barre du haut (maquette EC01), dans les documents du
     * client seulement et selon les droits de la personne : commandes
     * (numéro, désignation), devis (sauf lecture seule), fichiers, factures (payeur).
     *
     * @return array{commandes: list<Commandes>, devis: list<Devis>, fichiers: list<CommandeDetailFichier>, factures: list<Factures>}
     */
    public function rechercher(CompteClient $compte, string $texte): array
    {
        $client = $compte->getClient();
        $texte = trim(mb_substr($texte, 0, 60));
        if (null === $client || mb_strlen($texte) < 2) {
            return ['commandes' => [], 'devis' => [], 'fichiers' => [], 'factures' => []];
        }

        // Chargé par fiche client et selon les droits ; resultatsRecherche revérifie les deux.
        return self::resultatsRecherche($compte, $texte, [
            'commandes' => $this->commandes($client, 300),
            'devis' => $compte->estLectureSeule() ? [] : $this->devis($client),
            'fichiers' => $this->em->getRepository(CommandeDetailFichier::class)->findBy(['client' => $client, 'actif' => true, 'statut' => CommandeDetailFichier::STATUT_TERMINE], ['id' => 'DESC'], 300),
            'factures' => $compte->peut(CompteClient::DROIT_PAYEUR) ? $this->factures($client) : [],
        ]);
    }

    /**
     * Ce qui correspond au texte, parmi ce qui appartient à la fiche de la
     * personne et que ses droits lui montrent : pas de devis pour la
     * lecture seule, les factures aux payeurs seulement. 20 par rubrique.
     *
     * @param array{commandes?: iterable<Commandes>, devis?: iterable<Devis>, fichiers?: iterable<CommandeDetailFichier>, factures?: iterable<Factures>} $sources
     *
     * @return array{commandes: list<Commandes>, devis: list<Devis>, fichiers: list<CommandeDetailFichier>, factures: list<Factures>}
     */
    public static function resultatsRecherche(CompteClient $compte, string $texte, array $sources): array
    {
        $resultats = ['commandes' => [], 'devis' => [], 'fichiers' => [], 'factures' => []];
        $client = $compte->getClient();
        $texte = trim(mb_substr($texte, 0, 60));
        if (null === $client || mb_strlen($texte) < 2) {
            return $resultats;
        }
        $aLui = static fn (?Clients $c): bool => null !== $c && $c === $client;
        // Sans accents ; les noms aussi sans ponctuation ni pluriel (« autocollants » : docs/noms-commerciaux.md).
        $trouve = static fn (?string ...$champs): bool => [] !== array_filter($champs, static fn (?string $c): bool => null !== $c && (str_contains(self::sansAccents($c), self::sansAccents($texte)) || NomCommercial::correspond($texte, $c)));
        foreach ($sources['commandes'] ?? [] as $c) {
            $designations = self::nomsLignes($c->getCommandesDetails()->toArray());
            if ($aLui($c->getClients()) && $trouve($c->getNumero(), $c->getBonCommande(), ...$designations)) {
                $resultats['commandes'][] = $c;
            }
        }
        if (!$compte->estLectureSeule()) {
            foreach ($sources['devis'] ?? [] as $d) {
                $designations = self::nomsLignes($d->getDevisDetails()->toArray());
                if ($aLui($d->getClients()) && $trouve($d->getNumero(), ...$designations)) {
                    $resultats['devis'][] = $d;
                }
            }
        }
        foreach ($sources['fichiers'] ?? [] as $f) {
            if ($aLui($f->getClient()) && $trouve($f->getNomOriginal())) {
                $resultats['fichiers'][] = $f;
            }
        }
        if ($compte->peut(CompteClient::DROIT_PAYEUR)) {
            foreach ($sources['factures'] ?? [] as $f) {
                if ($aLui($f->getCommande()?->getClients()) && $trouve($f->getNumero(), $f->getCommande()?->getNumero())) {
                    $resultats['factures'][] = $f;
                }
            }
        }

        return array_map(static fn (array $l): array => \array_slice($l, 0, 20), $resultats);
    }

    /**
     * Ce que la recherche lit d'une ligne : sa désignation et les deux noms
     * de ce qu'elle vend (« autocollant » trouve une ligne « Vinyle adhésif »).
     *
     * @param list<object> $lignes
     *
     * @return list<?string>
     */
    private static function nomsLignes(array $lignes): array
    {
        $noms = [];
        foreach ($lignes as $l) {
            $element = NomCommercial::elementDeLigne($l);
            array_push($noms, $l->getDesignation(), $element?->getNomTechnique(), $element?->getNomCommercial());
        }

        return $noms;
    }

    private static function sansAccents(string $texte): string
    {
        $t = mb_strtolower($texte);

        return strtr($t, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '×' => 'x']);
    }

    /** Bon de livraison en cours d'une commande (livreur en route, créneau). */
    public function livraison(Commandes $c): ?BonLivraison
    {
        foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $c], ['id' => 'DESC']) as $bl) {
            if (BonLivraison::STATUT_ANNULE !== $bl->getStatut()) {
                return $bl;
            }
        }

        return null;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
