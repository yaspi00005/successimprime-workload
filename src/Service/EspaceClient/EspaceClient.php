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
use App\Entity\Factures;
use App\Entity\ReclamationClient;
use App\Repository\EntrepriseRepository;
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
    public const DEVIS_VISIBLES = [Devis::STATUT_ENVOYE, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE, Devis::STATUT_CONVERTI];

    /** Gestes du client sur le site, rejoués au local (synchronisation). */
    public const ACTION_BAT_VALIDE = 'bat_valide';
    public const ACTION_BAT_MODIFIE = 'bat_modifie';

    /** Repères posés sur l'image d'un BAT : 10 au plus, 200 caractères chacun. */
    public const REPERES_MAX = 10;
    public const REPERE_TEXTE_MAX = 200;

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

    /** @return list<Commandes> */
    public function commandes(Clients $client, int $nombre = 100): array
    {
        return $this->em->getRepository(Commandes::class)->createQueryBuilder('c')
            // Une demande refusée par l'entreprise reste visible avec son motif, même classée par l'atelier.
            ->where('c.clients = :cl')->andWhere('c.deleted = false OR c.validationEntreprise = :refusee')->setParameter('refusee', Commandes::VALIDATION_REFUSEE)
            // Ses commandes passées en ligne se voient aussi pendant que l'atelier les confirme.
            ->andWhere('c.statut = true OR c.canal = :enLigne')->setParameter('enLigne', Commandes::CANAL_EN_LIGNE)
            ->setParameter('cl', $client)->orderBy('c.id', 'DESC')->setMaxResults($nombre)
            ->getQuery()->getResult();
    }

    /** La commande du client, ou null si elle est à un autre. */
    public function commande(Clients $client, string $publicId): ?Commandes
    {
        foreach ($this->em->getRepository(Commandes::class)->findBy(['clients' => $client]) as $c) {
            if ($c->isDeleted() && !$c->estRefuseeParEntreprise()) {
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
        $lignes = array_values(array_filter($c->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
        if ([] === $lignes) {
            return 'Commande '.$c->getNumero();
        }
        $titre = self::titreLigne($lignes[0]);

        return \count($lignes) > 1 ? \sprintf('%s + %d autre%s', $titre, \count($lignes) - 1, \count($lignes) > 2 ? 's' : '') : $titre;
    }

    public static function titreLigne(CommandesDetails $l): string
    {
        $nom = trim((string) ($l->getDesignation() ?: $l->getProduit()?->getNom())) ?: 'Travail';
        $largeur = (float) $l->getLargeur();
        $longueur = (float) $l->getLongueur();
        // La désignation porte parfois déjà la taille : « Bâche (400 x 150 cm) ».
        $taille = $largeur > 0 && $longueur > 0 && !preg_match('/\d\s*[x×]\s*\d/u', $nom) ? ' '.self::nombre($largeur).' × '.self::nombre($longueur) : '';

        return $nom.$taille.' × '.self::nombre((float) $l->getQuantite());
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

    /** Où le fichier est rangé sur ce serveur (public/ ou var/uploads/commandes), ou null. */
    public function cheminSurDisque(CommandeDetailFichier $f): ?string
    {
        $candidats = [];
        if ('' !== trim((string) $f->getChemin())) {
            $candidats[] = [$this->projet.'/public', $this->projet.'/public/'.ltrim((string) $f->getChemin(), '/')];
        }
        $nom = basename(trim((string) $f->getNomStockage()));
        if ('' !== $nom && '.' !== $nom) {
            $candidats[] = [$this->projet.'/var/uploads/commandes', $this->projet.'/var/uploads/commandes/'.$nom];
        }
        foreach ($candidats as [$racine, $chemin]) {
            $racine = realpath($racine);
            $reel = realpath($chemin);
            if (false !== $racine && false !== $reel && str_starts_with($reel, $racine.\DIRECTORY_SEPARATOR) && is_file($reel)) {
                return $reel;
            }
        }

        return null;
    }

    /**
     * Le client valide le BAT : il est noté validé, la prépresse finit la
     * validation des fichiers et l'envoi en production.
     */
    public function validerBat(ControlePrePresse $ctl, string $nom, \DateTimeImmutable $maintenant): void
    {
        $ctl->setBatNecessaire(true)->setBatValide(true)->setCorrectionNecessaire(false)->setStatut(ControlePrePresse::STATUT_EN_COURS);
        $this->conception($ctl)?->valider($this->parametres->bareme(), $maintenant, 'par le client dans son espace · '.$nom);
        $this->journal->noterAction($this->em, self::ACTION_BAT_VALIDE, $ctl, ['nom' => $nom, 'le' => $maintenant->format(\DATE_ATOM)]);
        $this->em->flush();
        $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('BAT validé par le client : %s, commande %s.', TableauProduction::titre($ctl->getCommandeDetail()), $ctl->getCommandeDetail()->getCommande()->getNumero()), 'app_controle_pre_presse_controler', ['id' => $ctl->getId()]);
        $this->em->flush();
    }

    /**
     * @param list<array{i: int, x: float, y: float, t: string}> $reperes repères posés sur l'image (voir reperes())
     */
    public function modifierBat(ControlePrePresse $ctl, string $texte, \DateTimeImmutable $maintenant, array $reperes = []): void
    {
        $ctl->setCorrectionNecessaire(true)->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE);
        $this->conception($ctl)?->retour(Conception::RETOUR_CLIENT, $texte, $maintenant, $reperes);
        $this->journal->noterAction($this->em, self::ACTION_BAT_MODIFIE, $ctl, ['texte' => $texte, 'le' => $maintenant->format(\DATE_ATOM), 'reperes' => $reperes]);
        $this->em->flush();
        $resume = implode(' ; ', array_filter([
            '' !== trim($texte) ? '« '.mb_substr(trim($texte), 0, 120).' »' : null,
            match (\count($reperes)) { 0 => null, 1 => '1 repère sur le BAT', default => \count($reperes).' repères sur le BAT' },
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

    public function accepterDevis(Devis $d): void
    {
        $d->setStatut(Devis::STATUT_ACCEPTE);
        $this->em->flush();
        $this->notifications->notifierRoles(['ROLE_DEVIS'], \sprintf('Devis %s accepté par le client dans son espace : à transformer en commande.', $d->getNumero()), 'app_devis_show', ['id' => $d->getId()]);
        $this->em->flush();
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
        $enCours = array_values(array_filter($this->commandes($client, 300), static fn (Commandes $c): bool => $c->isStatut() && 'annulee' !== $c->getStatutTravaux() && $c->getResteAPayer() > 0));
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
     * Fil des notifications (maquette 19), reconstruit à partir des
     * commandes : BAT prêt, paiement reçu, impression lancée, commande
     * prête, livreur en route, livrée, réponse à une réclamation.
     * NotificationsClient en envoie les nouveautés.
     *
     * @return list<array{type: string, titre: string, texte: string, date: \DateTimeInterface, lien: ?array, numero: ?string}>
     */
    public function notifications(Clients $client, \DateTimeImmutable $maintenant, int $nombre = 30): array
    {
        $depuis = $maintenant->modify('-45 days');
        $fil = [];
        foreach ($this->commandes($client, 40) as $c) {
            $id = (string) $c->getPublicId();
            foreach ($c->getPaiements() as $p) {
                if ($p->estValide() && null !== $p->getDate()) {
                    $fil[] = ['type' => 'paiement', 'titre' => 'Paiement reçu', 'texte' => \sprintf('%s F · %s · commande %s', number_format($p->getMontant(), 0, ',', ' '), $p->getModeLabel(), $c->getNumero()), 'date' => $p->getDateValidation() ?? $p->getDate(), 'lien' => ['app_espace_factures', []], 'numero' => $c->getNumero()];
                }
            }
            foreach ($c->getCommandesDetails() as $l) {
                foreach ($l->getControlesPrePresse() as $ctl) {
                    if (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut()) {
                        // Date de la dernière version envoyée : chaque nouvelle version compte.
                        $fil[] = ['type' => 'bat', 'titre' => 'BAT prêt à valider', 'texte' => self::titreLigne($l), 'date' => $this->conception($ctl)?->getDernierBatLe() ?? $ctl->getTraiteLe() ?? $ctl->getCreeLe(), 'lien' => ['app_espace_bat', ['id' => $ctl->getId()]], 'numero' => $c->getNumero()];
                    }
                }
                if (null !== $l->getProductionDebuteLe()) {
                    $fil[] = ['type' => 'impression', 'titre' => 'Impression lancée', 'texte' => \sprintf('Commande %s · %s', $c->getNumero(), self::titreLigne($l)), 'date' => $l->getProductionDebuteLe(), 'lien' => ['app_espace_commande', ['id' => $id]], 'numero' => $c->getNumero()];
                }
            }
            $carte = $this->carte($c);
            $prete = $carte['etapes'][4];
            if ('faite' === $prete['etat'] && null !== $prete['date'] && 'faite' !== $carte['etapes'][5]['etat']) {
                $fil[] = ['type' => 'prete', 'titre' => 'Commande prête', 'texte' => \sprintf('%s · %s', $c->getNumero(), $c->estALivrer() ? 'livraison à venir' : 'à retirer en boutique'), 'date' => $prete['date'], 'lien' => ['app_espace_commande', ['id' => $id]], 'numero' => $c->getNumero()];
            }
            foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $c]) as $bl) {
                if (BonLivraison::STATUT_ANNULE === $bl->getStatut()) {
                    continue;
                }
                if (null !== $bl->getLivreLe()) {
                    $fil[] = ['type' => 'livree', 'titre' => 'Commande livrée', 'texte' => \sprintf('%s · remise à %s', $c->getNumero(), $bl->getLivreLe()->format('H:i')), 'date' => $bl->getLivreLe(), 'lien' => ['app_espace_commande', ['id' => $id]], 'numero' => $c->getNumero()];
                } elseif (null !== $bl->getEnRouteLe()) {
                    $fil[] = ['type' => 'route', 'titre' => 'Livreur en route', 'texte' => \sprintf('%s%s', $c->getNumero(), $bl->getCreneau() ? ' · créneau '.$bl->getCreneau() : ''), 'date' => $bl->getEnRouteLe(), 'lien' => ['app_espace_commande', ['id' => $id]], 'numero' => $c->getNumero()];
                }
            }
        }
        foreach ($this->em->getRepository(ReclamationClient::class)->findBy(['client' => $client], ['id' => 'DESC'], 20) as $r) {
            if (null !== $r->getReponduLe()) {
                $fil[] = ['type' => 'reclamation', 'titre' => 'Réponse à votre réclamation', 'texte' => \sprintf('%s · %s', $r->getNumero(), $r->getObjet()), 'date' => $r->getReponduLe(), 'lien' => ['app_espace_reclamations', []], 'numero' => $r->getNumero()];
            }
        }
        $fil = array_values(array_filter($fil, static fn (array $n): bool => $n['date'] >= $depuis));
        usort($fil, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return \array_slice($fil, 0, $nombre);
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
