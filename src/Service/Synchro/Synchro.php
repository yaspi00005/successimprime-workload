<?php

namespace App\Service\Synchro;

use App\Entity\DepotSoldeClient;
use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\DemandeConception;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\ModificationClient;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\MessageClient;
use App\Entity\PropositionClient;
use App\Entity\ReclamationClient;
use App\Entity\Reglage;
use App\Entity\ReponseSondage;
use App\Entity\SynchroEchange;
use App\Entity\SynchroEtat;
use App\Entity\SynchroJournal;
use App\Entity\SynchroRecu;
use App\Entity\User;
use App\Repository\ParametresPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\NotificationService;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Sondages\Sondages;
use App\Service\Site\DemandesSite;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur de la synchronisation, commun aux deux côtés : file d'envoi,
 * application des données reçues (une seule fois chacune, dans l'ordre),
 * état et historique pour l'écran Synchronisation.
 */
final class Synchro
{
    public const LOT = 100;

    /** Volume au plus des contenus d'un lot (un envoi signé fait 10 Mo au plus). */
    public const LOT_OCTETS = 6 * 1024 * 1024;

    /** Pas de synchro réussie depuis ce délai : alerte. */
    public const ALERTE_MINUTES = 15;

    public const RESULTAT_OK = 'ok';
    public const RESULTAT_CONFLIT = 'conflit';
    public const RESULTAT_DEJA = 'deja';
    public const RESULTAT_IGNORE = 'ignore';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly Serialiseur $serialiseur,
        private readonly JournalSynchro $journal,
        private readonly NotificationService $notifications,
        /** @var \Closure(): EspaceClient (différé : Parametres dépend de Synchro) */
        #[AutowireServiceClosure(EspaceClient::class)] private readonly \Closure $espaceClient,
        #[AutowireServiceClosure(NotificationsClient::class)] private readonly \Closure $notificationsClient,
        /** @var \Closure(): Sondages */
        #[AutowireServiceClosure(Sondages::class)] private readonly \Closure $sondages,
        /** @var \Closure(): DemandesSite */
        #[AutowireServiceClosure(DemandesSite::class)] private readonly \Closure $demandesSite,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $url,
        #[Autowire('%env(SYNC_CLE)%')] private readonly string $cle,
    ) {
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /** Côté local : adresse du site et clé réglées. Côté site : clé réglée. */
    public function estConfiguree(): bool
    {
        return Signature::cleValable($this->cle) && ($this->enLigne || str_starts_with($this->url, 'https://') || str_starts_with($this->url, 'http://'));
    }

    public function url(): string
    {
        return rtrim($this->url, '/');
    }

    public function cle(): string
    {
        return $this->cle;
    }

    /* ---------- Envoi ---------- */

    /**
     * Prochains éléments à envoyer, dans l'ordre, avec leur contenu actuel.
     * Les éléments bloqués ou écartés ne partent plus.
     *
     * @return list<array{id: int, evenement: string, type: string, publicId: string, operation: string, le: string, donnees: ?array}>
     */
    public function lot(int $apres = 0, int $max = self::LOT): array
    {
        /** @var list<SynchroJournal> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('j')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')
            ->andWhere('j.ignore = false')
            ->andWhere('j.tentatives < :max')->setParameter('max', SynchroJournal::TENTATIVES_MAX)
            ->andWhere('j.id > :apres')->setParameter('apres', $apres)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults($max)
            ->getQuery()->getResult();

        $contenus = [];
        $lot = [];
        $octets = 0;
        foreach ($lignes as $l) {
            $cle = $l->getType().$l->getPublicId();
            if ($l->getOperation() === SynchroJournal::ACTION) {
                $contenus[$cle] = $l->getDonnees(); // un geste : son contenu est fixé au moment où il a été fait
            } elseif (!\array_key_exists($cle, $contenus)) {
                $entite = $l->getOperation() === SynchroJournal::MAJ ? $this->serialiseur->trouver($l->getType(), $l->getPublicId()) : null;
                $contenus[$cle] = $entite === null ? null : $this->serialiseur->versTableau($l->getType(), $entite);
            }
            // Des images (site vitrine, photos de réclamation) : le lot reste sous la taille d'un envoi signé.
            $octets += \strlen((string) json_encode($contenus[$cle]));
            if ($lot !== [] && $octets > self::LOT_OCTETS) {
                break;
            }
            $lot[] = [
                'id' => (int) $l->getId(),
                'evenement' => $l->getEvenement(),
                'type' => $l->getType(),
                'publicId' => $l->getPublicId(),
                'operation' => $l->getOperation(),
                'le' => $l->getCreeLe()->format(\DATE_ATOM),
                'donnees' => $contenus[$cle],
            ];
        }
        $this->em->clear();

        return $lot;
    }

    /** @param list<int> $ids */
    public function marquerEnvoyes(array $ids, ?\DateTimeImmutable $le = null): void
    {
        if ($ids === []) {
            return;
        }
        $this->em->createQueryBuilder()
            ->update(SynchroJournal::class, 'j')
            ->set('j.envoyeLe', ':le')->set('j.erreur', 'NULL')
            ->where('j.id IN (:ids)')
            ->setParameter('le', $le ?? new \DateTimeImmutable())
            ->setParameter('ids', $ids)
            ->getQuery()->execute();
    }

    /** Marque comme envoyé tout ce qui va jusqu'à $id (accusé de réception de l'agent). */
    public function marquerEnvoyesJusqua(int $id): int
    {
        return $this->em->createQueryBuilder()
            ->update(SynchroJournal::class, 'j')
            ->set('j.envoyeLe', ':le')->set('j.erreur', 'NULL')
            ->where('j.id <= :id')->andWhere('j.envoyeLe IS NULL')
            ->setParameter('le', new \DateTimeImmutable())
            ->setParameter('id', $id)
            ->getQuery()->execute();
    }

    public function noterEchecEnvoi(int $id, string $message): ?SynchroJournal
    {
        $ligne = $this->em->find(SynchroJournal::class, $id);
        $ligne?->noterEchec($message);
        $this->em->flush();

        return $ligne;
    }

    /** Remet en file tout ce qui existe (premier chargement du site). Ligne de commande seulement. */
    public function initialiser(?string $seulement = null): int
    {
        if ($seulement !== null && $seulement !== CatalogueSynchro::ACTION_REGLAGES && !isset(CatalogueSynchro::types()[$seulement])) {
            throw new \InvalidArgumentException(\sprintf('Type inconnu : %s (%s)', $seulement, implode(', ', [...array_keys(CatalogueSynchro::types()), CatalogueSynchro::ACTION_REGLAGES])));
        }
        $n = 0;
        if (!$this->enLigne && \in_array($seulement, [null, CatalogueSynchro::ACTION_REGLAGES], true)) {
            foreach (array_keys(CatalogueSynchro::REGLAGES_PUBLICS) as $tuile) {
                $this->noterReglages($tuile, $this->em->getRepository(Reglage::class)->findOneBy(['cle' => $tuile])?->getValeurs() ?? []);
                ++$n;
            }
        }
        foreach (CatalogueSynchro::types() as $type => $def) {
            if (!CatalogueSynchro::envoye($type, $this->enLigne) || ($seulement !== null && $type !== $seulement)) {
                continue;
            }
            $ids = $this->em->createQuery('SELECT e.publicId FROM '.$def['classe'].' e ORDER BY e.id ASC')->getSingleColumnResult();
            if (isset($def['pret'])) {
                // Comme au fil de l'eau : ce qui n'est pas « prêt » ne part pas (ligne de fidélité à 0 point, envoi de fichier inachevé).
                $ids = [];
                foreach ($this->em->createQuery('SELECT e FROM '.$def['classe'].' e ORDER BY e.id ASC')->toIterable() as $e) {
                    if ($def['pret']($e)) {
                        $ids[] = $e->getPublicId();
                    }
                }
            }
            foreach ($ids as $publicId) {
                // Lecture brute : la colonne revient en binaire.
                $this->em->persist(new SynchroJournal($type, ($publicId instanceof Uuid ? $publicId : Uuid::fromString((string) $publicId))->toRfc4122(), SynchroJournal::MAJ));
                if (++$n % 500 === 0) {
                    $this->em->flush();
                    $this->em->clear();
                }
            }
        }
        $this->em->flush();
        $this->em->clear();

        return $n;
    }

    /* ---------- Réception ---------- */

    /**
     * Applique les éléments reçus, dans l'ordre, chacun dans sa propre
     * transaction. Un élément qui attend une donnée arrivant plus loin dans
     * le lot (fichier mis dans une commande créée après lui) est repris dès
     * qu'elle est là. S'arrête au premier échec : la suite repartira au
     * prochain cycle, dans le même ordre (ce qui est déjà appliqué est
     * reconnu et sauté).
     *
     * @param list<array<string, mixed>> $evenements
     *
     * @return array{resultats: list<array{id: int, evenement: string, type: string, publicId: string, titre: string, statut: string, message: ?string}>, erreur: ?array{id: int, evenement: string, type: string, titre: string, message: string, reprise: bool}}
     */
    public function appliquer(array $evenements): array
    {
        $resultats = [];
        $enAttente = []; // éléments qui attendent une donnée plus loin, avec leur erreur
        foreach ($evenements as $e) {
            $r = $this->appliquerEvenement($e);
            if ($r['erreur'] !== null && !$r['erreur']['reprise']) {
                return ['resultats' => self::avant($resultats, $enAttente, $r['erreur']['id']), 'erreur' => $r['erreur']];
            }
            if ($r['erreur'] !== null) {
                $enAttente[] = [$e, $r['erreur']];
                continue;
            }
            $resultats[] = $r['resultat'];
            // La donnée attendue vient peut-être d'arriver.
            do {
                $avance = false;
                foreach ($enAttente as $i => [$attente]) {
                    $nouvel = $this->appliquerEvenement($attente);
                    if ($nouvel['erreur'] === null) {
                        $resultats[] = $nouvel['resultat'];
                        unset($enAttente[$i]);
                        $avance = true;
                    } else {
                        $enAttente[$i][1] = $nouvel['erreur'];
                    }
                }
            } while ($avance && $enAttente !== []);
        }
        if ($enAttente !== []) {
            $premiere = reset($enAttente)[1];

            return ['resultats' => self::avant($resultats, $enAttente, $premiere['id']), 'erreur' => $premiere];
        }
        usort($resultats, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return ['resultats' => $resultats, 'erreur' => null];
    }

    /**
     * Résultats à accuser : seulement ceux d'avant l'élément bloqué (le
     * curseur ne doit pas le dépasser). Ceux d'après repasseront, déjà notés reçus.
     *
     * @return list<array<string, mixed>>
     */
    private static function avant(array $resultats, array $enAttente, int $bloque): array
    {
        foreach ($enAttente as [, $erreur]) {
            $bloque = min($bloque, $erreur['id']);
        }
        $resultats = array_values(array_filter($resultats, static fn (array $r): bool => $r['id'] < $bloque));
        usort($resultats, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $resultats;
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array{resultat: ?array<string, mixed>, erreur: ?array{id: int, evenement: string, type: string, titre: string, message: string, reprise: bool}}
     */
    private function appliquerEvenement(array $e): array
    {
        $id = (int) ($e['id'] ?? 0);
        $evenement = (string) ($e['evenement'] ?? '');
        $type = (string) ($e['type'] ?? '');
        $publicId = (string) ($e['publicId'] ?? '');
        $titre = isset(CatalogueSynchro::types()[$type]) ? Serialiseur::titre($type, null, \is_array($e['donnees'] ?? null) ? $e['donnees'] : null) : $type;
        try {
            [$statut, $message, $titre] = $this->appliquerUn($evenement, $type, $publicId, (string) ($e['operation'] ?? ''), \is_array($e['donnees'] ?? null) ? $e['donnees'] : null, $titre);
            // Sans ce vidage, chaque flush relit toutes les données déjà reçues du lot.
            $this->em->clear();

            return ['resultat' => ['id' => $id, 'evenement' => $evenement, 'type' => $type, 'publicId' => $publicId, 'titre' => $titre, 'statut' => $statut, 'message' => $message], 'erreur' => null];
        } catch (\Throwable $ex) {
            if (!$this->em->isOpen()) {
                $this->doctrine->resetManager();
            }
            $this->em->clear();

            return ['resultat' => null, 'erreur' => [
                'id' => $id, 'evenement' => $evenement, 'type' => $type, 'titre' => $titre,
                'message' => self::messageErreur($ex), 'reprise' => $ex instanceof DependanceManquante,
            ]];
        }
    }

    /** @return array{0: string, 1: ?string, 2: string} statut, message, titre */
    private function appliquerUn(string $evenement, string $type, string $publicId, string $operation, ?array $donnees, string $titre): array
    {
        if ($evenement === '' || $this->em->find(SynchroRecu::class, $evenement) !== null) {
            return [self::RESULTAT_DEJA, null, $titre];
        }
        if ($operation === SynchroJournal::ACTION) {
            return $this->journal->sansEcho(fn (): array => $this->em->wrapInTransaction(function () use ($evenement, $type, $publicId, $donnees, $titre): array {
                $resultat = $this->appliquerAction($type, $publicId, $donnees ?? [], $titre);
                $this->noterRecu($evenement);
                $this->em->flush();

                return $resultat;
            }));
        }
        if (!CatalogueSynchro::accepte($type, $this->enLigne)) {
            $this->noterRecu($evenement);

            return [self::RESULTAT_IGNORE, 'Type non accepté de ce côté', $titre];
        }

        return $this->journal->sansEcho(function () use ($evenement, $type, $publicId, $operation, $donnees, $titre): array {
            return $this->em->wrapInTransaction(function () use ($evenement, $type, $publicId, $operation, $donnees, $titre): array {
                $existante = $this->serialiseur->trouver($type, $publicId);
                $resultat = [self::RESULTAT_OK, null, $titre];

                $ecarts = !$this->enLigne && $existante !== null && $operation === SynchroJournal::MAJ && $donnees !== null
                    ? $this->serialiseur->differences($type, $existante, $donnees) : [];
                if ($existante instanceof Commandes && $existante->isDeleted() && $existante->estRefuseeParEntreprise()) {
                    // Demande refusée chez le client et classée ici : le site la garde
                    // visible pour le client avec son motif, ce n'est pas un conflit.
                    unset($ecarts['deleted']);
                }
                $remontees = $this->remontees($type, $ecarts);
                if (!$this->enLigne && $existante !== null && ($operation === SynchroJournal::SUPPRESSION || \count($ecarts) > \count($remontees))) {
                    // Ce qui n'est pas repris au local est remis en ligne : la fiche du local repart.
                    $this->em->persist(new SynchroJournal($type, $publicId, SynchroJournal::MAJ));
                }
                if ($remontees !== []) {
                    // Décision du client prise en ligne (devis accepté, SMS) : appliquée au local.
                    $this->serialiseur->ecrireChamps($type, $existante, array_map(static fn (array $e): ?string => $e[1], $remontees));
                    $this->apresRemontee($existante, $remontees);
                    $resultat = [self::RESULTAT_OK, 'Repris du site : '.implode(', ', array_keys($remontees)), Serialiseur::titre($type, $existante)];
                } elseif (!$this->enLigne && $existante !== null && $operation === SynchroJournal::MAJ && $ecarts === []) {
                    $resultat = [self::RESULTAT_IGNORE, 'Déjà à jour', $titre];
                } elseif (!$this->enLigne && $existante !== null) {
                    // Le local est maître : une modification ou une suppression faite en ligne
                    // d'une fiche qu'il possède n'est pas appliquée, elle est signalée.
                    $resultat = [self::RESULTAT_CONFLIT, $operation === SynchroJournal::SUPPRESSION
                        ? 'Supprimée en ligne : gardée au local'
                        : 'Modifiée en ligne : la fiche du local est gardée', Serialiseur::titre($type, $existante)];
                } elseif ($existante === null && !CatalogueSynchro::creationAcceptee($type, $this->enLigne)) {
                    $resultat = [self::RESULTAT_IGNORE, 'Créée en ligne : ce type ne se crée qu\'au local', $titre];
                } elseif ($operation === SynchroJournal::SUPPRESSION) {
                    if ($existante !== null) {
                        $this->em->remove($existante);
                    }
                } elseif ($operation === SynchroJournal::MAJ && $donnees !== null) {
                    [$entite] = $this->serialiseur->depuisTableau($type, $publicId, $donnees);
                    if (!$this->enLigne) {
                        // Création faite en ligne : certaines valeurs ne se décident qu'au local
                        // (un paiement saisi en ligne reste à valider).
                        $meta = $this->em->getClassMetadata($entite::class);
                        foreach (CatalogueSynchro::types()[$type]['imposeAuLocal'] ?? [] as $champ => $valeur) {
                            $meta->setFieldValue($entite, $champ, $valeur);
                        }
                        if ($existante === null && $entite instanceof CommandesDetails) {
                            self::verifierLigneEnLigne($entite);
                        }
                    }
                    $this->em->persist($entite);
                    if (!$this->enLigne && $existante === null && $entite instanceof Commandes && $entite->attendValidationEntreprise()) {
                        // Demande d'un employé du client : rien à faire avant l'accord de son valideur.
                        $resultat = [self::RESULTAT_OK, 'Demande en attente de validation chez le client', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof DemandeDevis) {
                        // Demande d'un visiteur du site : prospect ou client du même numéro, devis brouillon, équipe prévenue.
                        $this->em->flush();
                        $resultat = [self::RESULTAT_OK, ($this->demandesSite)()->preparer($entite, true), $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof ReclamationClient) {
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_CLIENT'], \sprintf('Réclamation %s de %s : %s', $entite->getNumero(), $entite->getClient()?->getNomComplet() ?? 'un client', $entite->getObjet()), 'app_reclamations_clients_voir', ['id' => $entite->getId()]);
                        $resultat = [self::RESULTAT_OK, 'Réclamation du client : à traiter', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof MessageClient) {
                        // Message écrit dans l'espace client (Messages) : l'équipe répond depuis Clients › Messages.
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_CLIENT'], \sprintf('Message de %s (%s) : « %s »', $entite->getAuteur() ?? 'un client', $entite->getClient()?->getNomComplet() ?? '?', mb_substr($entite->getTexte(), 0, 100)), 'app_messages_clients_voir', ['publicId' => $entite->getClient()?->getPublicId()]);
                        $resultat = [self::RESULTAT_OK, 'Message du client : à lire', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof PropositionClient) {
                        // Raison sociale ou NIF à changer : jamais appliqué tout seul, l'équipe décide sur la fiche.
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_CLIENT'], \sprintf('%s demande de changer la raison sociale ou le NIF de %s : à appliquer ou refuser sur la fiche.', $entite->getAuteur() ?? 'Le client', $entite->getClient()?->getNomComplet() ?? '?'), 'app_clients_show', ['publicId' => $entite->getClient()?->getPublicId()]);
                        $resultat = [self::RESULTAT_OK, 'Changement demandé par le client : à valider', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof DemandeConception) {
                        // Demande de conception du client (docs/demande-conception.md) : la PAO et les ventes sont prévenues.
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_PREPRESSE', 'ROLE_COMMANDE'], \sprintf('Demande de conception %s de %s : %s (%s).', $entite->getNumero(), $entite->getClient()?->getNomComplet() ?? 'un client', $entite->titreSupport(), DemandeConception::IA === $entite->getChoix() ? 'visuel IA à finaliser' : 'graphiste'), 'app_demandes_conception_voir', ['id' => $entite->getId()]);
                        $resultat = [self::RESULTAT_OK, 'Demande de conception : à prendre en charge', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof ReponseSondage && $entite->alerteOuverte()) {
                        // Note de 1 ou 2 laissée en ligne : le client est à rappeler.
                        $this->em->flush();
                        ($this->sondages)()->notifierAlerte($entite);
                        $resultat = [self::RESULTAT_OK, 'Mauvaise note : client à rappeler', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof Paiements && $entite->estOrangeMoneyEnLigne()) {
                        // Payé par le client depuis son espace : la caisse vérifie la réception sur le compte
                        // « Orange Money en ligne ». Pas de montant dans la notification (il est sur le paiement).
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_ADMIN'], \sprintf('Paiement Orange Money reçu en ligne pour la commande %s (%s) : à valider après contrôle du compte Orange Money en ligne.', $entite->getCommande()?->getNumero() ?? '?', $entite->getReference()), 'app_paiements_show', ['id' => $entite->getId()]);
                        $resultat = [self::RESULTAT_OK, 'Paiement Orange Money du client : à valider', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof Paiements && $entite->estAvoirEnLigne()) {
                        // Avoir utilisé par le client depuis son espace : la validation retire le montant de son solde.
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_ADMIN'], \sprintf('Avoir utilisé en ligne pour la commande %s (%s) : à valider, le solde du client baisse à la validation.', $entite->getCommande()?->getNumero() ?? '?', $entite->getReference()), 'app_paiements_show', ['id' => $entite->getId()]);
                        $resultat = [self::RESULTAT_OK, 'Avoir utilisé par le client : à valider', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof DepotSoldeClient) {
                        // Dépôt Orange Money fait depuis « Mon solde » : la caisse valide après contrôle du compte
                        // « Orange Money en ligne ». Le solde du client ne monte qu'à la validation. Pas de montant ici.
                        $this->em->flush();
                        $this->notifications->notifierRoles(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_ADMIN'], \sprintf('Dépôt Orange Money reçu en ligne sur le compte de %s (%s) : à valider après contrôle du compte Orange Money en ligne.', $entite->getClient()?->getNomComplet() ?? 'un client', $entite->getReference()), 'app_clients_show', ['publicId' => $entite->getClient()?->getPublicId()]);
                        $resultat = [self::RESULTAT_OK, 'Dépôt sur le compte du client : à valider', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof Commandes && $entite->estRefuseeParEntreprise() && !$entite->isStatut()) {
                        // Refusée chez le client avant même d'arriver : classée, l'atelier n'en entend pas parler.
                        $entite->setDeleted(true);
                        $resultat = [self::RESULTAT_OK, 'Demande refusée par l\'entreprise du client', $titre];
                    } elseif (!$this->enLigne && $existante === null && $entite instanceof Commandes && Commandes::CANAL_EN_LIGNE === $entite->getCanal() && !$entite->isStatut()) {
                        $this->em->flush();
                        $validee = null !== $entite->getValideePar() ? ', validée par ' . $entite->getValideePar() : '';
                        $this->notifications->notifierRoles(['ROLE_COMMANDE'], \sprintf('Nouvelle commande en ligne %s%s : prix et fichier à vérifier, puis à confirmer.', $entite->getNumero(), $validee), 'app_commandes_show', ['id' => $entite->getId()]);
                        $resultat = [self::RESULTAT_OK, 'Commande passée en ligne : à confirmer', $titre];
                    }
                } else {
                    $resultat = [self::RESULTAT_IGNORE, 'Donnée supprimée avant l\'envoi', $titre];
                }

                $this->noterRecu($evenement);
                $this->em->flush();
                if ($this->enLigne) {
                    // BAT prêt, commande prête, paiement validé… : le client est prévenu une fois la réception finie.
                    ($this->notificationsClient)()->aVerifier(self::clientDe($this->serialiseur->trouver($type, $publicId)));
                }

                return $resultat;
            });
        });
    }

    /**
     * Ligne créée en ligne : elle ne saute la PAO que si elle vient d'un
     * produit de ce client dont le BAT est validé (« Mes produits »).
     */
    public static function verifierLigneEnLigne(CommandesDetails $ligne): void
    {
        $produit = $ligne->getProduitClient();
        $client = $ligne->getCommande()?->getClients();
        if (CommandesDetails::TYPE_LIBRE === $ligne->getTypeLigne() && CommandesDetails::PRODUCTION_NON_REQUISE === $ligne->getStatutProduction() && !$ligne->isPrePresseNecessaire()) {
            // Ligne sans rien à fabriquer (pack de crédit IA) : ni PAO ni production.
            return;
        }
        if (null !== $produit && (null === $client || $produit->getClient()?->getId() !== $client->getId())) {
            $ligne->setProduitClient($produit = null);
        }
        if (null === $produit?->getBatValide()) {
            $ligne->setPrePresseNecessaire(true)->setBatValide(false);
        }
    }

    private static function clientDe(?object $entite): ?Clients
    {
        return match (true) {
            $entite instanceof Commandes => $entite->getClients(),
            $entite instanceof CommandesDetails => $entite->getCommande()?->getClients(),
            $entite instanceof ControlePrePresse => $entite->getCommandeDetail()?->getCommande()?->getClients(),
            $entite instanceof Paiements => $entite->getCommande()?->getClients(),
            $entite instanceof ReclamationClient => $entite->getClient(),
            $entite instanceof DemandeConception => $entite->getClient(),
            $entite instanceof BonLivraison => $entite->getCommande()?->getClients(),
            // Nouveau devis, facture émise, réponse de l'atelier : dans le fil du client (espace client).
            $entite instanceof Devis => $entite->getClients(),
            $entite instanceof \App\Entity\Factures => $entite->getCommande()?->getClients(),
            $entite instanceof MessageClient => $entite->getClient(),
            default => null,
        };
    }

    /**
     * Changements faits en ligne que le local accepte (CatalogueSynchro
     * « remontees ») : [champ => [au local, en ligne]].
     *
     * @param array<string, array{0: ?string, 1: ?string}> $ecarts
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private function remontees(string $type, array $ecarts): array
    {
        return array_filter(
            $ecarts,
            static fn (array $e, string $champ): bool => CatalogueSynchro::remonteePermise($type, $champ, $e[0], $e[1]),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Geste du client fait en ligne, rejoué ici avec les règles du local
     * (points du graphiste, alerte à la prépresse).
     *
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function appliquerAction(string $action, string $publicId, array $donnees, string $titre): array
    {
        if ($action === CatalogueSynchro::ACTION_REGLAGES && $this->enLigne) {
            return $this->recevoirReglages($donnees);
        }
        if ($action === CatalogueSynchro::ACTION_PROPOSITION && !$this->enLigne) {
            return $this->recevoirProposition($donnees);
        }
        if ($this->enLigne || !\in_array($action, [EspaceClient::ACTION_BAT_VALIDE, EspaceClient::ACTION_BAT_MODIFIE], true)) {
            return [self::RESULTAT_IGNORE, 'Geste inconnu de ce côté', $titre];
        }
        $controle = $this->serialiseur->trouver('controle', $publicId);
        if (!$controle instanceof ControlePrePresse) {
            throw new DependanceManquante('BAT '.substr($publicId, 0, 8).' inconnu au local');
        }
        $titre = 'BAT commande '.($controle->getCommandeDetail()?->getCommande()?->getNumero() ?? '?');
        $le = new \DateTimeImmutable((string) ($donnees['le'] ?? 'now'));
        if (ControlePrePresse::STATUT_EN_ATTENTE_BAT !== $controle->getStatut()) {
            if ($action === EspaceClient::ACTION_BAT_MODIFIE) {
                $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('Demande du client reçue après coup (%s) : « %s »', $titre, mb_substr((string) ($donnees['texte'] ?? ''), 0, 120)), 'app_controle_pre_presse_controler', ['id' => $controle->getId()]);
            }
            $this->em->persist(new SynchroJournal('controle', $publicId, SynchroJournal::MAJ)); // le site reprend l'état du local

            return [self::RESULTAT_CONFLIT, 'Le BAT n\'attendait plus le client au local : état du local gardé', $titre];
        }
        if ($action === EspaceClient::ACTION_BAT_VALIDE) {
            ($this->espaceClient)()->validerBat($controle, mb_substr((string) ($donnees['nom'] ?? 'client'), 0, 80), $le);
            $message = 'BAT validé par le client en ligne';
        } else {
            $espace = ($this->espaceClient)();
            $espace->modifierBat($controle, mb_substr((string) ($donnees['texte'] ?? ''), 0, 2000), $le, EspaceClient::reperes($donnees['reperes'] ?? [], \count($espace->imagesBat($controle))));
            $message = 'Modification demandée par le client en ligne';
        }
        $this->em->persist(new SynchroJournal('controle', $publicId, SynchroJournal::MAJ)); // suivi du BAT à jour en ligne

        return [self::RESULTAT_OK, $message, $titre];
    }

    /* ---------- Réglages ---------- */

    /**
     * Au local : les champs publics d'une tuile partent vers le site (à
     * appeler avant le flush qui enregistre le changement).
     *
     * @param array<string, mixed> $stockees valeurs enregistrées de la tuile
     */
    public function noterReglages(string $tuile, array $stockees): void
    {
        if ($this->enLigne || !isset(CatalogueSynchro::REGLAGES_PUBLICS[$tuile])) {
            return;
        }
        if ('paiements' === $tuile) {
            // Taux des frais Orange Money : tenus dans ParametresPaiement, pas dans la tuile.
            $parametres = $this->em->getRepository(ParametresPaiement::class);
            $p = $parametres instanceof ParametresPaiementRepository ? $parametres->recuperer() : new ParametresPaiement();
            foreach (ParametresPaiement::CHAMPS_REGLAGES as $champ) {
                $stockees[$champ] = $p->taux($champ);
            }
        }
        $this->em->persist(new SynchroJournal(CatalogueSynchro::ACTION_REGLAGES, Uuid::v4()->toRfc4122(), SynchroJournal::ACTION, null, [
            'tuile' => $tuile,
            'valeurs' => CatalogueSynchro::reglagesPublics($tuile, $stockees),
        ]));
    }

    /**
     * Sur le site : un changement de l'admin en ligne part vers le local, où
     * il attend qu'un administrateur le valide (à appeler avant le flush).
     *
     * @param array<string, mixed> $valeurs champs changés, déjà contrôlés
     */
    public function noterProposition(string $tuile, array $valeurs, ?User $par, \DateTimeImmutable $le): void
    {
        if (!$this->enLigne) {
            return;
        }
        $this->em->persist(new SynchroJournal(CatalogueSynchro::ACTION_PROPOSITION, Uuid::v4()->toRfc4122(), SynchroJournal::ACTION, null, [
            'tuile' => $tuile,
            'valeurs' => $valeurs,
            'par' => $par?->getUserIdentifier(),
            'le' => $le->format(\DATE_ATOM),
        ]));
    }

    /**
     * Valeurs reçues de l'autre côté, contrôlées comme une saisie : seuls
     * les champs connus de la tuile (et, si donnés, de la liste) passent.
     *
     * @param list<string>|null $permis
     *
     * @return array<string, mixed>|null null si une valeur est refusée
     */
    private static function lireReglages(string $tuile, mixed $valeurs, ?array $permis): ?array
    {
        if (!\is_array($valeurs)) {
            return null;
        }
        $champs = array_values(array_filter(
            CatalogueParametres::tuiles()[$tuile]['champs'] ?? [],
            static fn (array $c): bool => \array_key_exists($c['cle'], $valeurs) && ($permis === null || \in_array($c['cle'], $permis, true)),
        ));
        if ($champs === []) {
            return null;
        }
        $lu = CatalogueParametres::lireSaisie($champs, $valeurs);

        return $lu['erreurs'] === [] ? $lu['valeurs'] : null;
    }

    /** @return array{0: string, 1: ?string, 2: string} */
    private function recevoirReglages(array $donnees): array
    {
        $tuile = (string) ($donnees['tuile'] ?? '');
        $titre = 'Réglages · '.(CatalogueParametres::tuiles()[$tuile]['libelle'] ?? $tuile);
        $valeurs = isset(CatalogueSynchro::REGLAGES_PUBLICS[$tuile]) ? self::lireReglages($tuile, $donnees['valeurs'] ?? null, CatalogueSynchro::REGLAGES_PUBLICS[$tuile]) : null;
        if ($valeurs === null) {
            return [self::RESULTAT_IGNORE, 'Réglage non accepté sur le site', $titre];
        }
        [$valeurs, $taux] = CatalogueSynchro::separerSourcePaiement($tuile, $valeurs);
        if ([] !== $taux) {
            // Taux des frais Orange Money : le paiement de l'espace client les lit dans ParametresPaiement.
            $parametres = $this->em->getRepository(ParametresPaiement::class);
            $p = $parametres instanceof ParametresPaiementRepository ? $parametres->recuperer() : new ParametresPaiement();
            foreach ($taux as $champ => $valeur) {
                $p->changerTaux($champ, $valeur);
            }
            $this->em->persist($p);
        }
        if ([] !== $valeurs) {
            $reglage = $this->em->getRepository(Reglage::class)->findOneBy(['cle' => $tuile]);
            if ($reglage === null) {
                $reglage = new Reglage($tuile);
                $this->em->persist($reglage);
            }
            $reglage->modifier(array_replace($reglage->getValeurs(), $valeurs), null, new \DateTimeImmutable());
        }

        return [self::RESULTAT_OK, 'Réglages du local appliqués', $titre];
    }

    /** @return array{0: string, 1: ?string, 2: string} */
    private function recevoirProposition(array $donnees): array
    {
        $tuile = (string) ($donnees['tuile'] ?? '');
        $libelle = CatalogueParametres::tuiles()[$tuile]['libelle'] ?? null;
        $titre = 'Réglages · '.($libelle ?? $tuile);
        $valeurs = $libelle === null ? null : self::lireReglages($tuile, $donnees['valeurs'] ?? null, null);
        if ($valeurs === null) {
            return [self::RESULTAT_IGNORE, 'Proposition refusée : tuile ou valeur inconnue au local', $titre];
        }
        $nom = mb_substr((string) ($donnees['par'] ?? ''), 0, 180);
        $par = $nom === '' ? null : $this->em->getRepository(User::class)->findOneBy(['username' => $nom]);
        $reglage = $this->em->getRepository(Reglage::class)->findOneBy(['cle' => $tuile]);
        if ($reglage === null) {
            $reglage = new Reglage($tuile);
            $this->em->persist($reglage);
        }
        try {
            $le = new \DateTimeImmutable((string) ($donnees['le'] ?? 'now'));
        } catch (\Exception) {
            $le = new \DateTimeImmutable();
        }
        $reglage->proposer($valeurs, $par, $le);
        $this->em->flush();
        $this->notifications->notifierRoles(['ROLE_ADMIN'], \sprintf('Réglage « %s » changé depuis l\'admin en ligne%s : à valider ou refuser.', $libelle, $nom !== '' ? ' par '.$nom : ''), 'app_reglages_parametres_index');

        return [self::RESULTAT_OK, 'Changement proposé en ligne, à valider au local', $titre];
    }

    /** @param array<string, array{0: ?string, 1: ?string}> $remontees */
    private function apresRemontee(object $entite, array $remontees): void
    {
        if ($entite instanceof Devis && Devis::STATUT_ACCEPTE === ($remontees['statut'][1] ?? null)) {
            $this->notifications->notifierRoles(['ROLE_DEVIS'], \sprintf('Devis %s accepté par le client dans son espace : à transformer en commande.', $entite->getNumero()), 'app_devis_show', ['id' => $entite->getId()]);
        }
        if ($entite instanceof Commandes && isset($remontees['validationEntreprise'])) {
            if (Commandes::VALIDATION_ACCEPTEE === $remontees['validationEntreprise'][1]) {
                $this->em->flush();
                $this->notifications->notifierRoles(['ROLE_COMMANDE'], \sprintf('Nouvelle commande en ligne %s, validée par %s : prix et fichier à vérifier, puis à confirmer.', $entite->getNumero(), $entite->getValideePar() ?? 'le client'), 'app_commandes_show', ['id' => $entite->getId()]);
            } elseif (!$entite->isStatut()) {
                // Refusée par l'entreprise avant toute confirmation : elle ne vient pas à l'atelier.
                $entite->setDeleted(true);
            }
        }
        if ($entite instanceof Clients) {
            $libelles = ['recevoirSms' => 'Recevoir les SMS', 'consentementMarketingLe' => 'Accord pour les offres', 'email' => 'E-mail'];
            $lisible = static fn (string $champ, ?string $v): ?string => 'recevoirSms' === $champ && null !== $v ? ('1' === $v ? 'Oui' : 'Non') : $v;
            foreach ($remontees as $champ => [$avant, $apres]) {
                [$avant, $apres] = [$lisible($champ, $avant), $lisible($champ, $apres)];
                $this->em->persist(new ModificationClient($entite, $libelles[$champ] ?? $champ, 'contacts', $avant, $apres, new \DateTimeImmutable(), null, 'espace_client', 'Le client, en ligne'));
            }
        }
    }

    private function noterRecu(string $evenement): void
    {
        $this->em->persist(new SynchroRecu($evenement));
    }

    private static function messageErreur(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'Duplicate entry')) {
            return 'Doublon : une fiche avec la même valeur unique existe déjà ('.mb_substr(preg_replace('/.*Duplicate entry/s', 'Duplicate entry', $message), 0, 160).')';
        }

        return mb_substr($message, 0, 500);
    }

    /* ---------- État et historique ---------- */

    public function etat(string $cle): ?string
    {
        return $this->em->find(SynchroEtat::class, $cle)?->getValeur();
    }

    public function noterEtat(string $cle, ?string $valeur): void
    {
        $etat = $this->em->find(SynchroEtat::class, $cle) ?? new SynchroEtat($cle);
        $etat->setValeur($valeur);
        $this->em->persist($etat);
        $this->em->flush();
    }

    public function dateEtat(string $cle): ?\DateTimeImmutable
    {
        $v = $this->etat($cle);

        return $v === null ? null : (new \DateTimeImmutable($v));
    }

    /** $enregistrer = false : gardé en attente jusqu'à enregistrerEchanges() (un lot entier). */
    public function noterEchange(string $sens, ?string $type, string $libelle, string $statut, ?string $message = null, bool $enregistrer = true): SynchroEchange
    {
        $echange = new SynchroEchange($sens, $type, $libelle, $statut, $message);
        $this->em->persist($echange);
        if ($enregistrer) {
            $this->em->flush();
        }

        return $echange;
    }

    /** Écrit les échanges notés d'un lot en une fois et libère la mémoire. */
    public function enregistrerEchanges(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    /** Les erreurs passées sont résolues quand un cycle complet réussit. */
    public function resoudreErreurs(): void
    {
        $this->em->createQueryBuilder()
            ->update(SynchroEchange::class, 'e')
            ->set('e.resolu', 'true')
            ->where('e.statut IN (:statuts)')->andWhere('e.resolu = false')
            ->setParameter('statuts', [SynchroEchange::ERREUR, SynchroEchange::REPRISE])
            ->getQuery()->execute();
    }

    /** @return list<SynchroEchange> */
    public function echanges(int $max = 50, ?string $statut = null, ?string $sens = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('e')->from(SynchroEchange::class, 'e')
            ->orderBy('e.id', 'DESC')->setMaxResults($max);
        if ($statut !== null) {
            $qb->andWhere('e.statut = :statut')->setParameter('statut', $statut);
        }
        if ($sens !== null) {
            $qb->andWhere('e.sens = :sens')->setParameter('sens', $sens);
        }

        return $qb->getQuery()->getResult();
    }

    public function enAttente(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(j.id)')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')->andWhere('j.ignore = false')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<SynchroJournal> */
    public function bloques(): array
    {
        return $this->em->createQueryBuilder()
            ->select('j')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')->andWhere('j.ignore = false')
            ->andWhere('j.tentatives >= :max')->setParameter('max', SynchroJournal::TENTATIVES_MAX)
            ->orderBy('j.id', 'ASC')->getQuery()->getResult();
    }

    public function ignorer(int $id): bool
    {
        $ligne = $this->em->find(SynchroJournal::class, $id);
        if ($ligne === null || !$ligne->estBloque()) {
            return false;
        }
        $ligne->ignorer(new \DateTimeImmutable());
        $this->em->flush();

        return true;
    }

    public function compterEchanges(string $sens, \DateTimeImmutable $depuis, ?array $statuts = null, bool $nonResolusSeulement = false): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(SynchroEchange::class, 'e')
            ->andWhere('e.le >= :depuis')->setParameter('depuis', $depuis);
        if ($sens !== '') {
            $qb->andWhere('e.sens = :sens')->setParameter('sens', $sens);
        }
        if ($statuts !== null) {
            $qb->andWhere('e.statut IN (:statuts)')->setParameter('statuts', $statuts);
        }
        if ($nonResolusSeulement) {
            $qb->andWhere('e.resolu = false');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Texte de l'alerte, ou null. Rien tant que la synchronisation n'est pas
     * réglée.
     */
    public static function alerte(bool $configuree, ?\DateTimeImmutable $derniereReussite, ?\DateTimeImmutable $miseEnRoute, \DateTimeImmutable $maintenant, ?string $derniereErreur): ?string
    {
        if (!$configuree) {
            return null;
        }
        $reference = $derniereReussite ?? $miseEnRoute;
        if ($reference === null) {
            return null; // l'agent n'a encore jamais tourné
        }
        $minutes = intdiv($maintenant->getTimestamp() - $reference->getTimestamp(), 60);
        if ($minutes < self::ALERTE_MINUTES) {
            return null;
        }
        $duree = $minutes >= 120 ? intdiv($minutes, 60).' h' : $minutes.' min';

        return ($derniereReussite === null ? 'La synchronisation n\'a jamais réussi' : 'Pas de synchronisation réussie depuis '.$duree)
            .($derniereErreur ? ' : '.$derniereErreur : '.');
    }

    /** Purge des historiques (une fois par jour). */
    public function purger(\DateTimeImmutable $maintenant): void
    {
        $this->em->createQueryBuilder()->delete(SynchroRecu::class, 'r')->where('r.recuLe < :d')
            ->setParameter('d', $maintenant->modify('-60 days'))->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(SynchroJournal::class, 'j')->where('j.envoyeLe < :d')
            ->setParameter('d', $maintenant->modify('-30 days'))->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(SynchroEchange::class, 'e')->where('e.le < :d')
            ->setParameter('d', $maintenant->modify('-90 days'))->getQuery()->execute();
    }
}
