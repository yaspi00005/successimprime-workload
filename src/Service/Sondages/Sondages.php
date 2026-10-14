<?php

namespace App\Service\Sondages;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\InvitationSondage;
use App\Entity\NoteArticleSondage;
use App\Entity\ReclamationClient;
use App\Entity\ReponseSondage;
use App\Entity\Sondage;
use App\Service\EspaceClient\EspaceClient;
use App\Service\NotificationService;
use App\Service\SegmentsClients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sondages et satisfaction client (docs/sondages.md) : ce que le client
 * peut remplir, l'enregistrement des réponses, et le travail de l'atelier
 * sur les sondages (création, lancement, propositions, mauvaises notes).
 *
 * Le client ne voit que ses commandes et les sondages de son segment ;
 * une réponse donnée ne se refait pas.
 */
final class Sondages
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $urlSite,
    ) {
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /* ---------- Côté client ---------- */

    /** Commande livrée (ou retirée), confirmée et pas annulée. */
    public static function estLivree(Commandes $c): bool
    {
        return $c->isStatut() && !$c->isDeleted() && 'livree' === $c->getStatutTravaux();
    }

    /** Date à partir de laquelle le délai pour répondre court. */
    public static function dateDeReference(Commandes $c): \DateTimeImmutable
    {
        $dates = array_filter([$c->getDateCommande(), $c->getDateLivraison()]);
        foreach ($c->getCommandesDetails() as $l) {
            $dates[] = $l->getProductionTermineeLe();
        }
        $dates = array_values(array_filter($dates));

        return [] === $dates ? new \DateTimeImmutable('@0') : \DateTimeImmutable::createFromInterface(max($dates));
    }

    /** Le client peut-il encore donner son avis sur cette commande ? */
    public function peutNoter(Commandes $c, \DateTimeImmutable $maintenant): bool
    {
        return self::estLivree($c)
            && self::dateDeReference($c) >= $maintenant->modify(\sprintf('-%d days', SondageCommande::JOURS_POUR_REPONDRE))
            && null === $this->reponseCommande($c);
    }

    public function reponseCommande(Commandes $c): ?ReponseSondage
    {
        return null === $c->getId() ? null : $this->em->getRepository(ReponseSondage::class)->findOneBy(['commande' => $c, 'type' => ReponseSondage::COMMANDE]);
    }

    /**
     * Commandes livrées du client qui attendent son avis.
     *
     * @param list<Commandes> $commandes ses commandes (EspaceClient::commandes)
     *
     * @return list<Commandes>
     */
    public function commandesANoter(array $commandes, \DateTimeImmutable $maintenant): array
    {
        $candidates = array_values(array_filter($commandes, static fn (Commandes $c): bool => self::estLivree($c)
            && self::dateDeReference($c) >= $maintenant->modify(\sprintf('-%d days', SondageCommande::JOURS_POUR_REPONDRE))));
        if ([] === $candidates) {
            return [];
        }
        $notees = array_flip(array_map('intval', $this->em->createQueryBuilder()
            ->select('IDENTITY(r.commande)')->from(ReponseSondage::class, 'r')
            ->where('r.commande IN (:c)')->setParameter('c', $candidates)
            ->getQuery()->getSingleColumnResult()));

        return array_values(array_filter($candidates, static fn (Commandes $c): bool => !isset($notees[$c->getId()])));
    }

    /**
     * Sondages globaux en cours proposés à ce client (son segment), qu'il
     * n'a pas encore remplis.
     *
     * @return list<Sondage>
     */
    public function sondagesPour(Clients $client, \DateTimeImmutable $maintenant): array
    {
        /** @var list<Sondage> $ouverts */
        $ouverts = $this->em->getRepository(Sondage::class)->findBy(['type' => Sondage::GLOBAL, 'statut' => Sondage::OUVERT], ['id' => 'DESC']);
        if ([] === $ouverts) {
            return [];
        }
        $segments = $this->segmentsDe($client, $maintenant);

        return array_values(array_filter($ouverts, fn (Sondage $s): bool => \in_array($s->getSegment(), $segments, true) && !$this->aRepondu($s, $client)));
    }

    /** Le sondage global ouvert à ce client, ou null. */
    public function sondagePour(Clients $client, string $publicId, \DateTimeImmutable $maintenant): ?Sondage
    {
        foreach ($this->sondagesPour($client, $maintenant) as $s) {
            if ((string) $s->getPublicId() === $publicId) {
                return $s;
            }
        }

        return null;
    }

    public function aRepondu(Sondage $s, Clients $client): bool
    {
        return null !== $this->em->getRepository(ReponseSondage::class)->findOneBy(['sondage' => $s, 'client' => $client]);
    }

    /** @return list<ReponseSondage> les derniers avis du client */
    public function reponsesDuClient(Clients $client, int $nombre = 10): array
    {
        return $this->em->getRepository(ReponseSondage::class)->findBy(['client' => $client], ['id' => 'DESC'], $nombre);
    }

    /**
     * Segments du client, calculés sur ses commandes comme pour les
     * campagnes (SegmentsClients).
     *
     * @return list<string>
     */
    public function segmentsDe(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $ligne = $this->em->createQueryBuilder()
            ->select('MAX(c.dateCommande) AS derniere, SUM(CASE WHEN c.dateCommande >= :an THEN c.totalTtc ELSE 0 END) AS ca')
            ->from(Commandes::class, 'c')
            ->where('c.clients = :cl')->andWhere('c.deleted = false')
            ->setParameter('cl', $client)->setParameter('an', $maintenant->modify('-12 months'))
            ->getQuery()->getSingleResult();
        $achat = null === $ligne['derniere'] ? null : ['derniere' => new \DateTimeImmutable((string) $ligne['derniere']), 'ca' => (int) $ligne['ca']];

        return SegmentsClients::segmentsDe($client, $achat, $maintenant);
    }

    /** Invitation d'une commande par son jeton (lien du SMS ou de WhatsApp). */
    public function invitation(string $jeton): ?InvitationSondage
    {
        return $this->em->getRepository(InvitationSondage::class)->findOneBy(['jeton' => $jeton]);
    }

    /** Sondage libre en cours par le jeton de son lien. */
    public function sondageLibre(string $jeton): ?Sondage
    {
        $s = $this->em->getRepository(Sondage::class)->findOneBy(['jeton' => $jeton, 'type' => Sondage::LIBRE]);

        return $s instanceof Sondage && !$s->estBrouillon() ? $s : null;
    }

    /**
     * Articles d'une commande que le client note : les lignes non annulées.
     *
     * @return list<CommandesDetails>
     */
    public static function articles(Commandes $c): array
    {
        return array_values(array_filter($c->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
    }

    /* ---------- Réponses ---------- */

    /**
     * Avis sur une commande livrée.
     *
     * @param array<string, mixed> $saisie
     *
     * @throws \DomainException message à montrer
     */
    public function repondreCommande(Commandes $commande, ?CompteClient $compte, array $saisie, string $canal, \DateTimeImmutable $maintenant): ReponseSondage
    {
        if (!self::estLivree($commande)) {
            throw new \DomainException('Vous pourrez donner votre avis une fois la commande livrée.');
        }
        if (null !== $this->reponseCommande($commande)) {
            throw new \DomainException('Vous avez déjà donné votre avis sur cette commande. Merci !');
        }
        $articles = self::articles($commande);
        $parId = [];
        foreach ($articles as $l) {
            $parId[(string) $l->getPublicId()] = $l;
        }
        $valide = SondageCommande::valider(array_keys($parId), $saisie);
        $r = $valide['reponses'];

        $reponse = ReponseSondage::nouvelle(ReponseSondage::COMMANDE, $r, $canal, $maintenant)
            ->setCommande($commande)
            ->setClient($commande->getClients())
            ->setNotes($r[SondageCommande::GLOBALE] ?? null, $r[SondageCommande::NPS] ?? null, $r[SondageCommande::DELAI] ?? null, $r[SondageCommande::ACCUEIL] ?? null)
            ->setProposition($r[SondageCommande::PROPOSITION] ?? null)
            ->setAuteur(self::auteur($compte));
        foreach ($valide['articles'] as $id => $note) {
            NoteArticleSondage::pour($reponse, $parId[$id], EspaceClient::titreLigne($parId[$id]), $note);
        }

        return $this->enregistrer($reponse);
    }

    /**
     * Réponse à un sondage global (client connecté) ou libre (lien ou QR).
     *
     * @param array<string, mixed> $saisie
     *
     * @throws \DomainException message à montrer
     */
    public function repondreSondage(Sondage $sondage, ?Clients $client, ?CompteClient $compte, array $saisie, string $canal, ?string $nom, ?string $telephone, \DateTimeImmutable $maintenant): ReponseSondage
    {
        if (!$sondage->estOuvert()) {
            throw new \DomainException('Ce sondage est terminé. Merci de votre intérêt !');
        }
        if (Sondage::GLOBAL === $sondage->getType()) {
            if (null === $client || !\in_array($sondage->getSegment(), $this->segmentsDe($client, $maintenant), true)) {
                throw new \DomainException('Ce sondage ne vous est pas proposé.');
            }
            if ($this->aRepondu($sondage, $client)) {
                throw new \DomainException('Vous avez déjà répondu à ce sondage. Merci !');
            }
        }
        $nom = trim((string) $nom);
        $telephone = trim((string) $telephone);
        if ('' !== $telephone) {
            $telephone = CompteClient::normaliserTelephone($telephone) ?? throw new \DomainException('Le numéro de téléphone doit avoir 8 chiffres.');
        }
        if ($sondage->estLibre() && null === $client && !$sondage->isAnonymeAutorise() && ('' === $nom || '' === $telephone)) {
            throw new \DomainException('Indiquez votre nom et votre téléphone.');
        }
        $questions = $sondage->getQuestions();
        $r = Questions::valider($questions, $saisie);
        $cle = static fn (string $type): ?string => Questions::premiere($questions, $type);
        $texte = $cle(Questions::TEXTE);

        $reponse = ReponseSondage::nouvelle($sondage->getType(), $r, $canal, $maintenant)
            ->setSondage($sondage)
            ->setClient($client)
            ->setAuteur(self::auteur($compte))
            ->setIdentite($nom, $telephone)
            ->setNotes(self::entier($r, $cle(Questions::ETOILES)), self::entier($r, $cle(Questions::NPS)))
            ->setProposition(null === $texte ? null : (string) ($r[$texte] ?? ''));

        return $this->enregistrer($reponse);
    }

    private function enregistrer(ReponseSondage $reponse): ReponseSondage
    {
        $this->em->persist($reponse);
        foreach ($reponse->getNotes() as $n) {
            $this->em->persist($n);
        }
        $this->em->flush();
        // En ligne, l'alerte part du local quand la réponse y arrive (synchronisation).
        if (!$this->enLigne && $reponse->alerteOuverte()) {
            $this->notifierAlerte($reponse);
            $this->em->flush();
        }

        return $reponse;
    }

    /**
     * Note de 1 ou 2 : l'agent qui a suivi la commande est prévenu, sinon
     * les personnes du rôle Clients. Ne flushe pas.
     */
    public function notifierAlerte(ReponseSondage $r): void
    {
        $message = \sprintf(
            'Note de %d/5 de %s%s : à rappeler.',
            $r->noteLaPlusBasse() ?? 0,
            $r->getQui(),
            null !== $r->getCommande() ? ' sur la commande '.$r->getCommande()->getNumero() : ($r->getSondage() ? ' (sondage « '.$r->getSondage()->getTitre().' »)' : ''),
        );
        $agent = $r->getCommande()?->getAgents();
        if (null !== $agent && $agent->isActif()) {
            $this->notifications->notifierUtilisateur($agent, $message, 'app_sondages_reponse', ['id' => $r->getId()]);
        } else {
            $this->notifications->notifierRoles(['ROLE_CLIENT'], $message, 'app_sondages_reponse', ['id' => $r->getId()]);
        }
    }

    /* ---------- Côté atelier ---------- */

    /** @return list<Sondage> */
    public function liste(): array
    {
        return $this->em->getRepository(Sondage::class)->findBy([], ['id' => 'DESC'], 100);
    }

    /**
     * Crée ou modifie un sondage global ou libre. Les questions ne changent
     * plus une fois des réponses reçues.
     *
     * @param array{type?: string, titre?: string, introduction?: string, segment?: string, anonyme?: bool, questions?: list<array<string, mixed>>} $saisie
     *
     * @throws \DomainException
     */
    public function enregistrerSondage(?Sondage $sondage, array $saisie, string $par): Sondage
    {
        $this->exigerLocal();
        $titre = trim((string) ($saisie['titre'] ?? ''));
        if ('' === $titre) {
            throw new \DomainException('Donnez un titre au sondage.');
        }
        $questions = Questions::normaliser($saisie['questions'] ?? []);
        $nouveau = null === $sondage;
        $sondage ??= (new Sondage())->setType((string) ($saisie['type'] ?? Sondage::GLOBAL))->setCreePar($par);
        if (!$nouveau && $this->nombreReponses($sondage) > 0 && $questions !== $sondage->getQuestions()) {
            throw new \DomainException('Des clients ont déjà répondu : les questions ne changent plus. Créez un autre sondage.');
        }
        $sondage->setTitre($titre)
            ->setIntroduction($saisie['introduction'] ?? null)
            ->setSegment((string) ($saisie['segment'] ?? 'tous'))
            ->setAnonymeAutorise((bool) ($saisie['anonyme'] ?? true))
            ->setQuestions($questions);
        $this->em->persist($sondage);
        $this->em->flush();

        return $sondage;
    }

    /** @throws \DomainException */
    public function ouvrir(Sondage $sondage, SegmentsClients $segments, \DateTimeImmutable $maintenant): void
    {
        $this->exigerLocal();
        $destinataires = Sondage::GLOBAL === $sondage->getType() ? \count($segments->parSegment($maintenant)[$sondage->getSegment()] ?? []) : null;
        $sondage->ouvrir($maintenant, $destinataires);
        $this->em->flush();
    }

    public function clore(Sondage $sondage, \DateTimeImmutable $maintenant): void
    {
        $this->exigerLocal();
        $sondage->clore($maintenant);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function supprimer(Sondage $sondage): void
    {
        $this->exigerLocal();
        if ($this->nombreReponses($sondage) > 0) {
            throw new \DomainException('Ce sondage a des réponses : clôturez-le plutôt.');
        }
        $this->em->remove($sondage);
        $this->em->flush();
    }

    public function nombreReponses(Sondage $sondage): int
    {
        return null === $sondage->getId() ? 0 : $this->em->getRepository(ReponseSondage::class)->count(['sondage' => $sondage]);
    }

    /** @throws \DomainException */
    public function classer(ReponseSondage $r, string $theme, string $statut): void
    {
        $this->exigerLocal();
        $r->classer($theme, $statut);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function traiterAlerte(ReponseSondage $r, string $suite, ?string $note, string $par, \DateTimeImmutable $maintenant): void
    {
        $this->exigerLocal();
        $r->traiterAlerte($suite, $note, $par, $maintenant);
        $this->em->flush();
    }

    /**
     * Ouvre une réclamation pour le client à partir de sa mauvaise note :
     * il la voit dans son espace et l'atelier y répond comme d'habitude.
     *
     * @throws \DomainException
     */
    public function ouvrirReclamation(ReponseSondage $r, string $par, \DateTimeImmutable $maintenant): ReclamationClient
    {
        $this->exigerLocal();
        $client = $r->getClient() ?? throw new \DomainException('Réponse anonyme : pas de fiche client pour la réclamation.');
        if (null !== $r->getReclamation()) {
            return $r->getReclamation();
        }
        $description = \sprintf('Ouverte par %s après votre note de %d/5%s.', $par, $r->noteLaPlusBasse() ?? 0, null !== $r->getProposition() ? ' : « '.$r->getProposition().' »' : '');
        $reclamation = ReclamationClient::nouvelle($client, 'Suite à votre avis du '.$r->getCreeLe()->format('d/m'), $description, $maintenant)
            ->setMotif('autre')
            ->setCommande($r->getCommande());
        $this->em->persist($reclamation);
        $this->em->flush();
        $reclamation->setNumero(\sprintf('R-%04d', $reclamation->getId()));
        $r->traiterAlerte(ReponseSondage::ALERTE_RECLAMATION, null, $par, $maintenant, $reclamation);
        $this->em->flush();

        return $reclamation;
    }

    /**
     * Adresse publique d'une page de réponse, sur le site en ligne : c'est
     * là que les clients répondent. Sans site réglé, celle de ce serveur.
     *
     * @param array<string, string> $parametres
     */
    public function lienPublic(string $route, array $parametres): string
    {
        if (!$this->enLigne && preg_match('#^https?://#', $this->urlSite)) {
            return rtrim($this->urlSite, '/').$this->urls->generate($route, $parametres);
        }

        return $this->urls->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Les réglages du sondage se font au local, maître des données. */
    private function exigerLocal(): void
    {
        if ($this->enLigne) {
            throw new \DomainException('Les sondages se gèrent sur l\'ERP de la boutique : le site en ligne reçoit les changements.');
        }
    }

    private static function auteur(?CompteClient $compte): ?string
    {
        $client = $compte?->getClient();

        return null !== $client && CompteClient::clientAPlusieursComptes($client) ? $compte->getNomAffiche() : null;
    }

    /** @param array<string, mixed> $r */
    private static function entier(array $r, ?string $cle): ?int
    {
        return null !== $cle && \is_int($r[$cle] ?? null) ? $r[$cle] : null;
    }
}
