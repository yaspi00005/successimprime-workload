<?php

namespace App\Service\Promotions;

use App\Entity\AnniversaireClient;
use App\Entity\Campagne;
use App\Entity\CampagneDestinataire;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ModeleAnniversaire;
use App\Entity\ModeleMessage;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\OrangeSmsService;
use App\Service\SegmentsClients;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Anniversaires des clients (docs/promotions.md) : chaque année de
 * fidélité, une promotion préparée à l'avance (modèle du palier) et un SMS
 * le jour J.
 *
 * L'administrateur voit les anniversaires des semaines à venir, change la
 * promotion d'un client, l'exclut ou valide tout. Le jour J, la tâche
 * app:promotions crée la promotion du client seul et envoie le SMS par
 * Orange, ou prépare un lien WhatsApp quand les SMS ne sont pas branchés.
 * Jamais de montant dans le texte, jamais de message à une fiche de
 * démonstration (code DEMO-). Les clients au prix B2B ne sont pas fêtés :
 * la promotion ne s'appliquerait pas sans exception.
 */
final class AnniversairesClients
{
    public const ENTITE_JOURNAL = 'AnniversaireClient';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Promotions $promotions,
        private readonly OrangeSmsService $sms,
    ) {
    }

    /** @return list<ModeleAnniversaire> du plus petit palier au plus grand */
    public function modeles(): array
    {
        return $this->em->getRepository(ModeleAnniversaire::class)->findBy([], ['annees' => 'ASC']);
    }

    /**
     * Anniversaires entre $du et $au (compris), du plus proche au plus lointain.
     *
     * @return list<array{client: Clients, depart: \DateTimeImmutable, date: \DateTimeImmutable, annees: int, anniversaire: ?AnniversaireClient, modele: ?ModeleAnniversaire, ca: ?int, whatsapp: ?string}>
     */
    public function entre(\DateTimeImmutable $du, \DateTimeImmutable $au, bool $avecCa = false): array
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);
        $lignes = $this->em->createQueryBuilder()
            ->select('cl.id AS id', 'cl.createdAt AS creation', 'MIN(c.dateCommande) AS premiere')
            ->from(Clients::class, 'cl')
            ->leftJoin(Commandes::class, 'c', 'WITH', 'c.clients = cl AND c.deleted = false')
            ->where('cl.typeClient != :b2b')->andWhere('cl.statut = true OR cl.statut IS NULL')
            ->setParameter('b2b', 'B2B')
            ->groupBy('cl.id')
            ->getQuery()->getArrayResult();

        $trouves = [];
        foreach ($lignes as $l) {
            $depart = CalculAnniversaire::dateDepart($l['creation'], null !== $l['premiere'] ? new \DateTimeImmutable((string) $l['premiere']) : null);
            if (null === $depart) {
                continue;
            }
            $prochain = CalculAnniversaire::prochain($depart, $du);
            if ($prochain['date'] <= $au) {
                $trouves[(int) $l['id']] = ['depart' => $depart] + $prochain;
            }
        }
        if ([] === $trouves) {
            return [];
        }

        $clients = [];
        foreach ($this->em->getRepository(Clients::class)->findBy(['id' => array_keys($trouves)]) as $c) {
            $clients[(int) $c->getId()] = $c;
        }
        $existants = [];
        foreach ($this->em->createQueryBuilder()->select('a')->from(AnniversaireClient::class, 'a')
            ->where('a.client IN (:ids)')->setParameter('ids', array_keys($trouves))
            ->getQuery()->getResult() as $a) {
            $existants[$a->getClient()->getId().'-'.$a->getAnnees()] = $a;
        }
        $ca = $avecCa ? $this->ca12Mois(array_keys($trouves), $du) : [];
        $modeles = $this->modeles();

        $resultat = [];
        foreach ($trouves as $id => $t) {
            if (!isset($clients[$id])) {
                continue;
            }
            $a = $existants[$id.'-'.$t['annees']] ?? null;
            $resultat[] = [
                'client' => $clients[$id],
                'depart' => $t['depart'],
                'date' => $t['date'],
                'annees' => $t['annees'],
                'anniversaire' => $a,
                'modele' => $a?->getModele() ?? CalculAnniversaire::modelePour($modeles, $t['annees']),
                'ca' => $avecCa ? ($ca[$id] ?? 0) : null,
                'whatsapp' => null !== $a && AnniversaireClient::CANAL_WHATSAPP === $a->getCanal() ? $this->lienWhatsApp($a) : null,
            ];
        }
        usort($resultat, static fn (array $x, array $y): int => [$x['date'], $x['client']->getNomComplet()] <=> [$y['date'], $y['client']->getNomComplet()]);

        return $resultat;
    }

    /**
     * Décision de l'administrateur sur un anniversaire à venir : valider
     * (avec un autre modèle si besoin) ou exclure.
     *
     * @throws \DomainException
     */
    public function decider(Clients $client, int $annees, \DateTimeImmutable $date, string $decision, ?ModeleAnniversaire $modele, User $par, \DateTimeImmutable $maintenant): AnniversaireClient
    {
        $this->promotions->surLaBoutique();
        if ($client->isB2B()) {
            throw new \DomainException('Client au prix B2B : pas de promotion d\'anniversaire.');
        }
        $a = $this->obtenir($client, $annees, $date);
        $avant = ['decision' => $a->getDecision(), 'modele' => $a->getModele()?->getNom()];
        $a->decider($decision, $modele, $par, $maintenant);
        $this->em->flush();
        $this->promotions->journaliser(self::ENTITE_JOURNAL, (int) $a->getId(), AnniversaireClient::EXCLU === $decision ? 'anniversaire_exclu' : 'anniversaire_valide', $avant, ['client' => $client->getCode(), 'annees' => $annees, 'modele' => $a->getModele()?->getNom()], $par);
        $this->em->flush();

        return $a;
    }

    /**
     * Valide d'un clic les anniversaires pas encore décidés qui ont un modèle.
     *
     * @param list<array{client: Clients, date: \DateTimeImmutable, annees: int, anniversaire: ?AnniversaireClient, modele: ?ModeleAnniversaire}> $liste
     */
    public function toutValider(array $liste, User $par, \DateTimeImmutable $maintenant): int
    {
        $this->promotions->surLaBoutique();
        $n = 0;
        foreach ($liste as $e) {
            $a = $e['anniversaire'];
            if (null === $e['modele'] || (null !== $a && (null !== $a->getDecision() || $a->estTraite())) || $e['client']->isB2B()) {
                continue;
            }
            $a ??= $this->obtenir($e['client'], $e['annees'], $e['date']);
            $a->decider(AnniversaireClient::VALIDE, $a->getModele(), $par, $maintenant);
            ++$n;
        }
        if ($n > 0) {
            $this->em->flush();
            $this->promotions->journaliser(self::ENTITE_JOURNAL, 0, 'anniversaire_valide', null, ['tout_valider' => $n], $par);
            $this->em->flush();
        }

        return $n;
    }

    /**
     * Tâche du jour (app:promotions) : promotions et messages des
     * anniversaires du jour (et des jours manqués, RATTRAPAGE_JOURS), puis
     * rappels. Relancée le même jour, elle ne refait rien.
     *
     * @return array{fetes: int, sms: int, whatsapp: int, sans_message: int, ecartes: int, attente: int, rappels: int}
     */
    public function passageDuJour(\DateTimeImmutable $aujourdhui, ?User $par = null): array
    {
        $this->promotions->surLaBoutique();
        $reglages = $this->promotions->reglages();
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $maintenant = new \DateTimeImmutable();
        $compte = ['fetes' => 0, 'sms' => 0, 'whatsapp' => 0, 'sans_message' => 0, 'ecartes' => 0, 'attente' => 0, 'rappels' => 0];
        $modeles = $this->modeles();

        foreach ($this->entre($aujourdhui->modify('-'.(CalculAnniversaire::RATTRAPAGE_JOURS + 1).' days'), $aujourdhui) as $e) {
            $a = $e['anniversaire'];
            switch (CalculAnniversaire::actionDuJour($a, $e['date'], $aujourdhui, $reglages['anniversaire_validation'])) {
                case 'creer':
                    $a ??= $this->obtenir($e['client'], $e['annees'], $e['date']);
                    $modele = $a->getModele() ?? CalculAnniversaire::modelePour($modeles, $e['annees']);
                    if (null === $modele) {
                        $a->marquerTraite(null, null, $maintenant, 'Aucun modèle de promotion pour '.CalculAnniversaire::libelleAnnees($e['annees']).'.');
                        ++$compte['ecartes'];
                        break;
                    }
                    $promotion = $modele->creerPromotion($e['client'], $e['annees'], $e['date'], $aujourdhui, $par);
                    $this->em->persist($promotion);
                    $a->marquerTraite($promotion, $modele, $maintenant);
                    ++$compte['fetes'];
                    $canal = $this->envoyer($a, $modele->getTexteSms(), $promotion, $reglages['anniversaire_consentement'], $maintenant);
                    ++$compte[match ($canal) {
                        AnniversaireClient::CANAL_SMS => 'sms',
                        AnniversaireClient::CANAL_WHATSAPP => 'whatsapp',
                        default => 'sans_message',
                    }];
                    break;
                case 'exclu':
                    $a?->marquerTraite(null, null, $maintenant, 'Exclu avant le jour J.');
                    ++$compte['ecartes'];
                    break;
                case 'trop_tard':
                    ($a ?? $this->obtenir($e['client'], $e['annees'], $e['date']))->marquerTraite(null, null, $maintenant, 'Pas validé à temps.');
                    ++$compte['ecartes'];
                    break;
                case 'attente_validation':
                    ++$compte['attente'];
                    break;
            }
            // Un anniversaire à la fois : la ligne doit exister avant le suivant (contrainte client + années).
            $this->em->flush();
        }

        $compte['rappels'] = $this->rappels($aujourdhui, $reglages['anniversaire_rappel_jours'], $reglages['anniversaire_consentement'], $maintenant);
        $this->em->flush();

        return $compte;
    }

    /**
     * Lien WhatsApp du message (ou du rappel) quand les SMS ne sont pas
     * branchés. Jamais pour une fiche de démonstration.
     */
    public function lienWhatsApp(AnniversaireClient $a, bool $rappel = false): ?string
    {
        $client = $a->getClient();
        $promotion = $a->getPromotion();
        if (null === $client || null === $promotion || NotificationsClient::estDemo($client)) {
            return null;
        }
        $numero = preg_replace('/\D+/', '', (string) $client->getTelephone()) ?? '';
        if (8 === \strlen($numero)) {
            $numero = '223'.$numero;
        }
        if (\strlen($numero) < 11) {
            return null;
        }
        $texte = $this->texte($a, $rappel);

        return null === $texte ? null : 'https://wa.me/'.$numero.'?text='.rawurlencode($texte);
    }

    /**
     * Suivi depuis une date : anniversaires fêtés, messages partis,
     * promotions utilisées et, avec le droit de voir les ventes, le CA des
     * lignes qui les portent.
     *
     * @return array{fetes: int, sms: int, whatsapp: int, utilisees: int, rappels: int, ca: ?int}
     */
    public function suivi(\DateTimeImmutable $depuis, bool $avecCa): array
    {
        /** @var list<AnniversaireClient> $traites */
        $traites = $this->em->createQueryBuilder()->select('a')->from(AnniversaireClient::class, 'a')
            ->where('a.traiteLe >= :depuis')->andWhere('a.promotion IS NOT NULL')
            ->setParameter('depuis', $depuis)->getQuery()->getResult();
        $ids = array_map(static fn (AnniversaireClient $a): int => (int) $a->getPromotion()?->getId(), $traites);
        $utilisees = 0;
        $ca = null;
        if ([] !== $ids) {
            $r = $this->em->createQueryBuilder()
                ->select('COUNT(DISTINCT l.promotion) AS utilisees', 'COALESCE(SUM(l.totalHt), 0) AS ca')
                ->from(CommandesDetails::class, 'l')->join('l.commande', 'c')
                ->where('l.promotion IN (:ids)')->andWhere('c.deleted = false')->andWhere('l.statutProduction != :annulee')
                ->setParameter('ids', $ids)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
                ->getQuery()->getSingleResult();
            $utilisees = (int) $r['utilisees'];
            $ca = $avecCa ? (int) $r['ca'] : null;
        }

        return [
            'fetes' => \count($traites),
            'sms' => \count(array_filter($traites, static fn (AnniversaireClient $a): bool => AnniversaireClient::CANAL_SMS === $a->getCanal() && null !== $a->getEnvoyeLe())),
            'whatsapp' => \count(array_filter($traites, static fn (AnniversaireClient $a): bool => AnniversaireClient::CANAL_WHATSAPP === $a->getCanal())),
            'utilisees' => $utilisees,
            'rappels' => \count(array_filter($traites, static fn (AnniversaireClient $a): bool => null !== $a->getRappelLe())),
            'ca' => $avecCa ? ($ca ?? 0) : null,
        ];
    }

    /** @return list<AnniversaireClient> fêtés depuis cette date, les plus récents d'abord */
    public function recents(\DateTimeImmutable $depuis, int $max = 50): array
    {
        return $this->em->createQueryBuilder()->select('a', 'cl', 'p')->from(AnniversaireClient::class, 'a')
            ->join('a.client', 'cl')->leftJoin('a.promotion', 'p')
            ->where('a.traiteLe >= :depuis')->setParameter('depuis', $depuis)
            ->orderBy('a.dateAnniversaire', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }

    private function obtenir(Clients $client, int $annees, \DateTimeImmutable $date): AnniversaireClient
    {
        $a = $this->em->getRepository(AnniversaireClient::class)->findOneBy(['client' => $client, 'annees' => $annees]);
        if (null === $a) {
            $a = new AnniversaireClient($client, $annees, $date);
            $this->em->persist($a);
        }

        return $a;
    }

    /** Envoie le message du jour J ; rend le canal utilisé. */
    private function envoyer(AnniversaireClient $a, string $modeleTexte, Promotion $promotion, string $consentement, \DateTimeImmutable $maintenant): string
    {
        $client = $a->getClient();
        $refus = $this->refusMessage($client, $modeleTexte, $consentement);
        if (null !== $refus) {
            $a->marquerEnvoi(AnniversaireClient::CANAL_AUCUN, null, $refus);

            return AnniversaireClient::CANAL_AUCUN;
        }
        $texte = CalculAnniversaire::texte($modeleTexte, $client->getPrenom(), $client->getNom(), $a->getAnnees(), $promotion->getFin());
        if (!$this->sms->estConfigure()) {
            $a->marquerEnvoi(AnniversaireClient::CANAL_WHATSAPP, $maintenant, null);
            $this->tracer($client, CampagneDestinataire::STATUT_IGNORE, 'SMS non branchés : lien WhatsApp sur la page Anniversaires.', $maintenant);

            return AnniversaireClient::CANAL_WHATSAPP;
        }
        $r = $this->sms->envoyerSms((string) $client->getTelephone(), $texte);
        $a->marquerEnvoi(AnniversaireClient::CANAL_SMS, $r['succes'] ? $maintenant : null, $r['succes'] ? null : ($r['erreur'] ?? 'Erreur inconnue.'));
        $this->tracer($client, $r['succes'] ? CampagneDestinataire::STATUT_ENVOYE : CampagneDestinataire::STATUT_ECHEC, $r['succes'] ? null : $r['erreur'], $maintenant);

        return $r['succes'] ? AnniversaireClient::CANAL_SMS : AnniversaireClient::CANAL_AUCUN;
    }

    /** Pourquoi aucun message ne part vers ce client, ou null. */
    private function refusMessage(Clients $client, string $modeleTexte, string $consentement): ?string
    {
        return match (true) {
            NotificationsClient::estDemo($client) => 'Fiche de démonstration : aucun message.',
            CalculAnniversaire::contientMontant($modeleTexte) => 'Le texte du modèle donne un montant : rien n\'est envoyé.',
            [] === SegmentsClients::joignables([$client], ModeleMessage::CANAL_SMS, $consentement) => 'Pas de numéro, ou le client ne veut pas de messages.',
            default => null,
        };
    }

    /**
     * Rappel 7 jours (réglable) avant la fin, si la promotion n'a pas servi.
     */
    private function rappels(\DateTimeImmutable $aujourdhui, int $joursAvant, string $consentement, \DateTimeImmutable $maintenant): int
    {
        if ($joursAvant <= 0) {
            return 0;
        }
        /** @var list<AnniversaireClient> $candidats */
        $candidats = $this->em->createQueryBuilder()->select('a', 'p', 'cl')->from(AnniversaireClient::class, 'a')
            ->join('a.promotion', 'p')->join('a.client', 'cl')
            ->where('a.rappelLe IS NULL')->andWhere('a.envoyeLe IS NOT NULL')
            ->andWhere('p.fin >= :jour')->andWhere('p.fin <= :limite')
            ->setParameter('jour', $aujourdhui)->setParameter('limite', $aujourdhui->modify('+'.$joursAvant.' days'))
            ->getQuery()->getResult();
        $n = 0;
        foreach ($candidats as $a) {
            $promotion = $a->getPromotion();
            $client = $a->getClient();
            $utilisee = ($this->promotions->utilisations($client, [(int) $promotion->getId()], null)[(int) $promotion->getId()] ?? 0) > 0;
            if (!CalculAnniversaire::rappelDu($a, $promotion->getFin(), $aujourdhui, $joursAvant, $utilisee)) {
                continue;
            }
            $a->marquerRappel($maintenant);
            ++$n;
            if (AnniversaireClient::CANAL_SMS !== $a->getCanal() || null !== $this->refusMessage($client, CalculAnniversaire::TEXTE_RAPPEL, $consentement)) {
                // WhatsApp : le lien du rappel est sur la page Anniversaires.
                continue;
            }
            $r = $this->sms->envoyerSms((string) $client->getTelephone(), (string) $this->texte($a, true));
            $this->tracer($client, $r['succes'] ? CampagneDestinataire::STATUT_ENVOYE : CampagneDestinataire::STATUT_ECHEC, $r['succes'] ? null : $r['erreur'], $maintenant);
        }

        return $n;
    }

    private function texte(AnniversaireClient $a, bool $rappel): ?string
    {
        $client = $a->getClient();
        $promotion = $a->getPromotion();
        if (null === $client || null === $promotion) {
            return null;
        }
        $modele = $rappel ? CalculAnniversaire::TEXTE_RAPPEL : ($a->getModele()?->getTexteSms() ?? ModeleAnniversaire::TEXTE_PAR_DEFAUT);
        if (CalculAnniversaire::contientMontant($modele)) {
            return null;
        }

        return CalculAnniversaire::texte($modele, $client->getPrenom(), $client->getNom(), $a->getAnnees(), $promotion->getFin());
    }

    /**
     * Trace l'envoi dans la campagne du mois « Anniversaires clients ». Les
     * destinataires y entrent déjà traités : l'envoi des campagnes
     * (app:envoyer-campagnes) ne les reprend jamais.
     */
    private function tracer(Clients $client, string $statut, ?string $erreur, \DateTimeImmutable $le): void
    {
        $nom = 'Anniversaires clients '.$le->format('m/Y');
        $campagne = $this->em->getRepository(Campagne::class)->findOneBy(['nom' => $nom]);
        if (null === $campagne) {
            $campagne = (new Campagne())->setNom($nom)->setCanal(ModeleMessage::CANAL_SMS)->setStatut(Campagne::STATUT_TERMINEE);
            $this->em->persist($campagne);
        }
        $campagne->setNombreTotal($campagne->getNombreTotal() + 1);
        $this->em->persist((new CampagneDestinataire())
            ->setCampagne($campagne)
            ->setClient($client)
            ->setStatut($statut)
            ->setErreur(null !== $erreur ? mb_substr($erreur, 0, 255) : null)
            ->setDateEnvoi($le));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int> CA (commandes TTC) des 12 derniers mois par client
     */
    private function ca12Mois(array $ids, \DateTimeImmutable $jour): array
    {
        $resultat = [];
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'COALESCE(SUM(c.totalTtc), 0) AS ca')
            ->from(Commandes::class, 'c')
            ->where('c.clients IN (:ids)')->andWhere('c.deleted = false')->andWhere('c.dateCommande >= :an')
            ->setParameter('ids', $ids)->setParameter('an', $jour->modify('-12 months'))
            ->groupBy('c.clients')->getQuery()->getArrayResult() as $l) {
            $resultat[(int) $l['client']] = (int) $l['ca'];
        }

        return $resultat;
    }
}
