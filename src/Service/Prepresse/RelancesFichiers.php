<?php

namespace App\Service\Prepresse;

use App\Controller\UtilisateursController;
use App\Entity\ActionCaisse;
use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\RelanceFichier;
use App\Entity\User;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\Messagerie\Conversations;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use App\Service\Site\AdresseSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Relances des fichiers manquants (maquette PQ04, docs/prepresse.md).
 *
 * Le message liste les formats qui manquent et donne le lien de la
 * commande dans l'espace client (le client s'y connecte par son numéro
 * et un code). WhatsApp s'ouvre par un lien wa.me avec le message prêt :
 * pas d'API Meta, l'envoi se fait du téléphone ou du poste de l'agent,
 * qui le confirme ensuite (« Marquer envoyé »).
 *
 * Les étapes automatiques (J+1, J+3, J+7, J+15) se règlent dans
 * Réglages › Relances des fichiers ; app:prepresse:relances les fait
 * une fois par commande.
 */
final class RelancesFichiers
{
    public const TUILE = 'relances_fichiers';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly ListePrepresse $liste,
        private readonly DepotFichiers $depot,
        private readonly NotificationService $notifications,
        private readonly ActionsCaisse $actions,
        private readonly Conversations $conversations,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdresseSite $adresse,
    ) {
    }

    /** @return array<string, bool> étape => allumée */
    public function actives(): array
    {
        $actives = [];
        foreach (EtapesRelance::REGLAGES as $etape => $cle) {
            $actives[$etape] = (bool) $this->parametres->valeur(self::TUILE, $cle);
        }

        return $actives;
    }

    /** Le lien de la commande dans l'espace client, sur le site en ligne. */
    public function lien(Commandes $commande): string
    {
        return $this->adresse->url($this->urls->generate('app_espace_commande', ['id' => (string) $commande->getPublicId()]));
    }

    /**
     * @param list<string> $manquants formats sans fichier
     */
    public function message(Commandes $commande, array $manquants): string
    {
        $lignes = DepotFichiers::lignes($commande);
        $designations = array_map(static fn ($l): string => EtatPrepresse::sansDimensions((string) $l->getDesignation()), $lignes);
        // « étiquettes vinyle » : la première désignation, minuscule au début seulement (« DTF », « A4 » gardent leurs capitales).
        $travail = trim((string) (array_values(array_filter(array_map('trim', $designations)))[0] ?? ''));
        if (preg_match('/^\p{Lu}\p{Ll}/u', $travail)) {
            $travail = mb_strtolower(mb_substr($travail, 0, 1)).mb_substr($travail, 1);
        }

        return EtapesRelance::message((string) ListePrepresse::nomClient($commande->getClients()), (string) $commande->getNumero(), $travail, \count($lignes), $manquants, $this->lien($commande), self::decoupesManquantes($commande));
    }

    /**
     * Formats qui attendent encore leur fichier de découpe (RegleDecoupe) :
     * le message de relance le demande aussi.
     *
     * @return list<string>
     */
    public static function decoupesManquantes(Commandes $commande): array
    {
        $manquants = [];
        foreach (DepotFichiers::lignes($commande) as $l) {
            if (RegleDecoupe::MANQUANT === $l->etatDecoupe()) {
                $manquants[] = str_replace(' cm', '', DepotFichiers::libelleFormat($l));
            }
        }

        return $manquants;
    }

    /** Lien wa.me avec le message, ou null sans numéro. */
    public static function whatsapp(?Clients $client, string $message): ?string
    {
        $telephone = trim((string) ($client?->getTelephone() ?: $client?->getTelephone2()));
        if ('' === $telephone) {
            return null;
        }

        return 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($message);
    }

    /** @return list<string> formats encore sans fichier */
    public static function manquants(Commandes $commande): array
    {
        $manquants = [];
        foreach (DepotFichiers::lignes($commande) as $l) {
            if (!DepotFichiers::aFichier($l)) {
                $manquants[] = str_replace(' cm', '', DepotFichiers::libelleFormat($l));
            }
        }

        return $manquants;
    }

    /**
     * Les commandes sans fichier, les plus anciennes d'abord, avec leurs
     * relances (écran PQ04).
     *
     * @return list<array<string, mixed>>
     */
    public function aRelancer(\DateTimeImmutable $maintenant): array
    {
        // Il manque un fichier à imprimer, ou un fichier de découpe.
        $lignes = array_values(array_filter($this->liste->commandes($maintenant), static fn (array $c): bool => $c['manquants'] > 0 || $c['decoupesManquantes'] > 0));
        if ([] === $lignes) {
            return [];
        }
        $ids = array_column($lignes, 'id');
        $relances = [];
        foreach ($this->em->getRepository(RelanceFichier::class)->findBy(['commande' => $ids], ['creeLe' => 'ASC']) as $r) {
            $relances[$r->getCommande()->getId()][] = $r;
        }
        $appels = [];
        foreach ($this->em->getRepository(ActionCaisse::class)->findBy(['cause' => array_map(static fn (int $id): string => 'fichier:'.$id, $ids)], ['id' => 'ASC']) as $a) {
            $appels[(int) substr($a->getCause(), 8)] = $a;
        }
        $commandes = [];
        foreach ($this->em->getRepository(Commandes::class)->findBy(['id' => $ids]) as $c) {
            $commandes[$c->getId()] = $c;
        }
        foreach ($lignes as &$l) {
            $c = $commandes[$l['id']];
            $siennes = $relances[$l['id']] ?? [];
            $manquants = self::manquants($c);
            $message = $this->message($c, $manquants);
            $l['manquantsListe'] = $manquants;
            $l['decoupesListe'] = self::decoupesManquantes($c);
            $l['relances'] = $siennes;
            $l['frise'] = EtapesRelance::frise(self::etapesEnvoyees($siennes));
            $l['aEnvoyer'] = array_values(array_filter($siennes, static fn (RelanceFichier $r): bool => RelanceFichier::WHATSAPP === $r->getCanal() && !$r->estEnvoyee()));
            $l['message'] = $message;
            $l['whatsapp'] = $l['clientJoignable'] ? self::whatsapp($c->getClients(), $message) : null;
            $l['impossible'] = null === $c->getClients() ? 'impossible : pas de client' : (!$l['clientJoignable'] ? 'impossible : pas de numéro' : null);
            $l['reponse'] = $this->reponse($siennes, $appels[$l['id']] ?? null, $l['clientJoignable']);
            $l['attente'] = EtapesRelance::jours($l['dateCommande'], $maintenant);
        }
        unset($l);

        return $lignes;
    }

    /**
     * Relance envoyée à la main (« Relancer la sélection ») : l'étape du
     * moment est notée envoyée (la relance préparée de cette étape, sinon
     * une nouvelle).
     */
    public function marquerEnvoyee(Commandes $commande, User $par, \DateTimeImmutable $maintenant): RelanceFichier
    {
        $etape = EtapesRelance::etapeAtteinte(EtapesRelance::jours(\DateTimeImmutable::createFromInterface($commande->getDateCommande() ?? $maintenant), $maintenant));
        // J+7 (appel) et J+15 (pause) ne sont pas des messages : un WhatsApp envoyé à la main à ce moment-là est une relance de plus.
        if (!\in_array(RelanceFichier::WHATSAPP, EtapesRelance::CANAUX[$etape] ?? [], true)) {
            $etape = EtapesRelance::MANUELLE;
        }
        $relance = $this->em->getRepository(RelanceFichier::class)->findOneBy(['commande' => $commande, 'etape' => $etape, 'canal' => RelanceFichier::WHATSAPP, 'envoyeLe' => null]);
        $deja = null !== $this->em->getRepository(RelanceFichier::class)->findOneBy(['commande' => $commande, 'etape' => $etape, 'canal' => RelanceFichier::WHATSAPP]);
        if (null === $relance) {
            // Étape déjà envoyée : une relance de plus, notée à part.
            $relance = new RelanceFichier($commande, $deja ? EtapesRelance::MANUELLE : $etape, RelanceFichier::WHATSAPP, $maintenant, $par);
            $this->em->persist($relance);
        }
        $manquants = self::manquants($commande);
        $message = $this->message($commande, $manquants);
        $relance->preparer(\count($manquants) + \count(self::decoupesManquantes($commande)), $message, self::whatsapp($commande->getClients(), $message))->envoyer($par, $maintenant);
        $this->depot->noter($commande, $par, ['relance' => $relance->getLibelleEtape(), 'canal' => 'WhatsApp'], $maintenant);
        $this->em->flush();

        return $relance;
    }

    /** « Je passe samedi » : la réponse du client, sur la dernière relance. */
    public function noterReponse(Commandes $commande, string $reponse, User $par, \DateTimeImmutable $maintenant): void
    {
        $relance = $this->em->getRepository(RelanceFichier::class)->findOneBy(['commande' => $commande], ['creeLe' => 'DESC', 'id' => 'DESC']);
        if (null === $relance) {
            $relance = (new RelanceFichier($commande, EtapesRelance::MANUELLE, RelanceFichier::WHATSAPP, $maintenant, $par));
            $this->em->persist($relance);
        }
        $relance->noterReponse($reponse, $maintenant);
        $this->depot->noter($commande, $par, ['reponse' => $reponse], $maintenant);
        $this->em->flush();
    }

    /**
     * La tâche des relances (app:prepresse:relances, chaque heure) :
     * l'étape due de chaque commande sans fichier, une seule fois ;
     * reprend les commandes en pause dont le fichier est arrivé ; note les
     * appels faits par la caisse.
     *
     * @return array{faites: list<string>, reprises: int, appels: int}
     */
    public function executer(\DateTimeImmutable $maintenant): array
    {
        $bilan = ['faites' => [], 'reprises' => 0, 'appels' => 0];
        // 1. Commandes en pause dont un fichier est arrivé par un autre chemin.
        foreach ($this->em->getRepository(Commandes::class)->createQueryBuilder('c')->where('c.enPauseFichiersLe IS NOT NULL')->getQuery()->getResult() as $c) {
            if ($this->depot->reprendreSiFichier($c, null, $maintenant)) {
                ++$bilan['reprises'];
            }
        }
        // 2. Appels faits par la caisse : la relance J+7 est faite, avec la note.
        foreach ($this->em->getRepository(RelanceFichier::class)->findBy(['canal' => RelanceFichier::APPEL, 'envoyeLe' => null]) as $r) {
            $action = $this->em->getRepository(ActionCaisse::class)->findOneBy(['cause' => 'fichier:'.$r->getCommande()->getId(), 'etat' => ActionCaisse::FAITE], ['id' => 'DESC']);
            if (null !== $action) {
                $r->envoyer($action->getFaitePar(), $action->getFaiteLe() ?? $maintenant);
                $r->noterReponse(trim((ActionCaisse::RESULTATS[(string) $action->getResultat()] ?? '').(null !== $action->getNote() ? ' · '.$action->getNote() : ''), ' ·'), $maintenant);
                ++$bilan['appels'];
            }
        }
        $this->em->flush();

        // 3. Étapes dues.
        $actives = $this->actives();
        if (!\in_array(true, $actives, true)) {
            return $bilan;
        }
        foreach ($this->liste->commandes($maintenant) as $ligne) {
            if ($ligne['manquants'] <= 0) {
                continue;
            }
            $commande = $this->em->find(Commandes::class, $ligne['id']);
            if (!$commande instanceof Commandes) {
                continue;
            }
            $faites = array_values(array_unique(array_map(static fn (RelanceFichier $r): string => $r->getEtape(), $this->em->getRepository(RelanceFichier::class)->findBy(['commande' => $commande]))));
            $etape = EtapesRelance::aFaire($ligne['dateCommande'], $maintenant, $faites, $actives);
            if (null === $etape) {
                continue;
            }
            $this->faire($commande, $etape, $maintenant);
            $bilan['faites'][] = $commande->getNumero().' '.EtapesRelance::LIBELLES[$etape];
            $this->em->flush();
        }

        return $bilan;
    }

    /**
     * Temps moyen entre la commande et l'arrivée de son premier fichier,
     * en jours, sur les commandes des 30 derniers jours (objectif : moins
     * d'un jour). Null sans donnée.
     */
    public function tempsMoyenReception(\DateTimeImmutable $maintenant): ?float
    {
        $lignes = $this->em->createQuery('SELECT c.dateCommande AS commande, MIN(f.creeLe) AS fichier FROM '.CommandeDetailFichier::class.' f JOIN f.commandeDetail d JOIN d.commande c
                WHERE c.dateCommande >= :depuis AND d.prePresseNecessaire = true AND f.statut = :t AND c.deleted = false GROUP BY c.id, c.dateCommande')
            ->setParameter('depuis', $maintenant->modify('-30 days'))->setParameter('t', CommandeDetailFichier::STATUT_TERMINE)->getArrayResult();
        $jours = [];
        foreach ($lignes as $l) {
            $debut = $l['commande'] instanceof \DateTimeInterface ? $l['commande'] : new \DateTimeImmutable((string) $l['commande']);
            $fin = $l['fichier'] instanceof \DateTimeInterface ? $l['fichier'] : new \DateTimeImmutable((string) $l['fichier']);
            $jours[] = max(0, $fin->getTimestamp() - $debut->getTimestamp()) / 86400;
        }

        return [] !== $jours ? round(array_sum($jours) / \count($jours), 1) : null;
    }

    /** Commandes du mois dont le client n'est pas joignable (pas de numéro). */
    public function sansClientCeMois(\DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(c.id) FROM '.Commandes::class.' c LEFT JOIN c.clients cl
                WHERE c.dateCommande >= :debut AND c.deleted = false
                AND (cl.id IS NULL OR ((cl.telephone IS NULL OR cl.telephone = \'\') AND (cl.telephone2 IS NULL OR cl.telephone2 = \'\')))')
            ->setParameter('debut', $maintenant->modify('first day of this month')->setTime(0, 0))->getSingleScalarResult();
    }

    private function faire(Commandes $commande, string $etape, \DateTimeImmutable $maintenant): void
    {
        $manquants = self::manquants($commande);
        $message = $this->message($commande, $manquants);
        $client = $commande->getClients();
        $nom = (string) (ListePrepresse::nomClient($client) ?? 'client sans fiche');
        foreach (EtapesRelance::CANAUX[$etape] as $canal) {
            $relance = (new RelanceFichier($commande, $etape, $canal, $maintenant))->preparer(\count($manquants), $message, RelanceFichier::WHATSAPP === $canal ? self::whatsapp($client, $message) : null);
            $this->em->persist($relance);
            match ($canal) {
                // WhatsApp : préparé, la prépresse l'ouvre d'un clic.
                RelanceFichier::WHATSAPP => $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('Relance %s à envoyer : %s (%s), %d fichier%s manquant%s.', EtapesRelance::LIBELLES[$etape], $commande->getNumero(), $nom, \count($manquants), \count($manquants) > 1 ? 's' : '', \count($manquants) > 1 ? 's' : ''), 'app_controle_pre_presse_relances', ['commande' => $commande->getId()]),
                RelanceFichier::ESPACE => $this->ecrireAuClient($commande, $message, $maintenant) ? $relance->envoyer(null, $maintenant) : null,
                RelanceFichier::APPEL => $this->appel($commande, $manquants, $maintenant),
                RelanceFichier::PAUSE => $this->pause($commande, $relance, $maintenant),
                default => null,
            };
        }
    }

    /**
     * Message dans la conversation de la commande (espace client) : le
     * client en est prévenu comme d'une réponse de l'atelier
     * (NotificationsClient). Sans toucher aux messages qu'il attend.
     */
    private function ecrireAuClient(Commandes $commande, string $texte, \DateTimeImmutable $maintenant): bool
    {
        $client = $commande->getClients();
        if (null === $client) {
            return false;
        }
        $conversation = $this->conversations->pourDocument($commande);
        if (null === $conversation || !$conversation->estOuverte()) {
            $conversation = ConversationClient::nouvelle($client, $commande, null, 'Fichiers de la commande '.$commande->getNumero(), ConversationClient::SUJET_COMMANDE, $maintenant);
            $this->em->persist($conversation);
        }
        $message = MessageClient::nouveau($client, MessageClient::ATELIER, $texte, MessageClient::NOM_ATELIER, $commande, $maintenant)->setConversation($conversation);
        $conversation->setDernierMessageLe($maintenant)->setLuAtelierLe($maintenant);
        $this->em->persist($message);

        return true;
    }

    /** J+7 : une ligne « Fichier manquant » dans « Mes actions » de la caisse. */
    private function appel(Commandes $commande, array $manquants, \DateTimeImmutable $maintenant): void
    {
        $cause = 'fichier:'.$commande->getId();
        if (null !== $this->em->getRepository(ActionCaisse::class)->findOneBy(['cause' => $cause, 'etat' => ActionCaisse::A_FAIRE])) {
            return;
        }
        $this->actions->nouvelle('fichier', $cause, (string) (ListePrepresse::nomClient($commande->getClients()) ?? 'Client sans fiche'), $maintenant, $maintenant)
            ->lier($commande->getClients(), null, 'commande', $commande->getId())
            ->setDetail(mb_substr(\sprintf('Appeler pour les fichiers de %s : %s. Notez la réponse.', $commande->getNumero(), implode(' · ', $manquants) ?: 'fichiers'), 0, 255));
    }

    private function pause(Commandes $commande, RelanceFichier $relance, \DateTimeImmutable $maintenant): void
    {
        $avant = $commande->getDateLivraison();
        $commande->mettreEnPauseFichiers($maintenant);
        $relance->envoyer(null, $maintenant);
        $this->depot->noter($commande, null, ['pause' => 'J+15 sans fichier', 'dateLivraisonRetiree' => $avant?->format('d/m/Y')], $maintenant);
    }

    /**
     * @param list<RelanceFichier> $relances
     *
     * @return list<string> étapes envoyées (ou faites)
     */
    private static function etapesEnvoyees(array $relances): array
    {
        $etapes = [];
        foreach ($relances as $r) {
            if ($r->estEnvoyee()) {
                $etapes[] = $r->getEtape();
            }
        }

        return array_values(array_unique($etapes));
    }

    /**
     * Colonne « Réponse » : la réponse notée, sinon « lu » si le client a
     * ouvert le message de l'espace client, « à associer » sans client
     * joignable, « pas de réponse » après une relance envoyée.
     *
     * @param list<RelanceFichier> $relances
     *
     * @return array{texte: string, couleur: string}|null
     */
    private function reponse(array $relances, ?ActionCaisse $appel, bool $joignable): ?array
    {
        foreach (array_reverse($relances) as $r) {
            if (null !== $r->getReponse()) {
                return ['texte' => '« '.$r->getReponse().' »', 'couleur' => 'jaune'];
            }
        }
        if (null !== $appel && null !== $appel->getNote() && ActionCaisse::FAITE === $appel->getEtat()) {
            return ['texte' => '« '.$appel->getNote().' »', 'couleur' => 'jaune'];
        }
        if (!$joignable) {
            return ['texte' => 'à associer', 'couleur' => 'rouge'];
        }
        foreach (array_reverse($relances) as $r) {
            if (RelanceFichier::ESPACE === $r->getCanal() && null !== ($c = $this->conversations->pourDocument($r->getCommande())) && null !== $c->getLuClientLe() && $c->getLuClientLe() >= $r->getCreeLe()) {
                return ['texte' => 'lu', 'couleur' => 'jaune'];
            }
        }
        foreach ($relances as $r) {
            if ($r->estEnvoyee() && RelanceFichier::PAUSE !== $r->getCanal()) {
                return ['texte' => 'pas de réponse', 'couleur' => 'rouge'];
            }
        }

        return null;
    }
}
