<?php

namespace App\Service\Rapports;

use App\Entity\EnvoiRapport;
use App\Entity\User;
use App\Service\Statistiques\Objectifs;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Envoie les rapports par e-mail et garde l'historique (docs/rapports-mail.md).
 *
 * Une seule fois par période : la ligne envoi_rapport (clé unique
 * « jour:2026-10-17 ») est posée avant le premier e-mail ; une tâche
 * lancée en même temps bute sur la contrainte et s'arrête. Chaque
 * destinataire reçoit sa version, calculée avec ses droits ; le résultat
 * de chacun est noté au fil de l'envoi, si bien qu'un envoi interrompu
 * reprend là où il s'est arrêté, sans renvoyer à ceux qui l'ont reçu.
 *
 * Les e-mails partent par le transport de Symfony Mailer (MAILER_DSN)
 * tout de suite, sans file d'attente, pour connaître le résultat.
 */
final class EnvoiRapports
{
    /** Reprises automatiques des destinataires en échec, au plus, après le premier envoi. */
    public const REPRISES_MAX = 3;

    /** Attente avant de reprendre un envoi en échec, ou de juger interrompu un envoi resté « en cours ». */
    public const ATTENTE_REPRISE = '+30 minutes';

    /** « M'envoyer un test » : au plus autant d'essais par personne et par heure. */
    public const ESSAIS_PAR_HEURE = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DestinatairesRapports $destinataires,
        private readonly CourrielRapport $courriel,
        private readonly ReglagesRapportsMail $reglages,
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** L'envoi déjà fait (ou en cours) de cette période, s'il existe. */
    public function envoiDe(PeriodeRapport $p): ?EnvoiRapport
    {
        return $this->em->getRepository(EnvoiRapport::class)->findOneBy(['cle' => EnvoiRapport::cleDe($p->type, $p->code())]);
    }

    /**
     * Envoie le rapport de cette période à chaque destinataire, une seule
     * fois. Null s'il est déjà parti ou part en ce moment.
     */
    public function envoyer(PeriodeRapport $p, string $declencheur, \DateTimeImmutable $maintenant, ?User $par = null): ?EnvoiRapport
    {
        if (null !== $this->envoiDe($p)) {
            return null;
        }
        $envoi = (new EnvoiRapport($p->type, $p->code(), $p->debut, $declencheur, $maintenant, $par))
            ->preparer(array_map(static fn (array $d): array => [
                'utilisateur' => (int) $d['utilisateur']->getId(),
                'nom' => Objectifs::nomAgent($d['utilisateur']),
                'adresse' => $d['adresse'],
                'version' => DroitsRapport::version($d['droits']),
            ], $this->destinataires->liste()), $this->reglages->pieceJointe());

        // La trace d'abord : une seconde tâche bute sur la clé unique.
        try {
            $this->em->persist($envoi);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->expedier($envoi, $p, array_keys($envoi->getLignes()), $maintenant);

        return $envoi;
    }

    /**
     * Le rapport est-il à reprendre maintenant ? Des destinataires en
     * échec ou jamais servis, l'envoi fini (ou resté « en cours » depuis
     * longtemps : tâche interrompue) depuis au moins 30 minutes, et pas
     * plus de REPRISES_MAX reprises. Règle pure.
     */
    public static function aReprendre(EnvoiRapport $envoi, \DateTimeImmutable $maintenant): bool
    {
        if ([] === $envoi->aReprendre() || $envoi->getTentatives() > self::REPRISES_MAX) {
            return false;
        }
        $depuis = $envoi->getTermineLe() ?? $envoi->getDemarreLe();

        return $depuis->modify(self::ATTENTE_REPRISE) <= $maintenant;
    }

    /** Renvoie le rapport aux seuls destinataires en échec ou pas encore servis. */
    public function reprendre(EnvoiRapport $envoi, \DateTimeImmutable $maintenant): EnvoiRapport
    {
        $p = PeriodeRapport::lire($envoi->getType(), $envoi->getPeriode());
        if (null === $p) {
            throw new \InvalidArgumentException('Période illisible : '.$envoi->getPeriode());
        }
        $rangs = $envoi->aReprendre();
        $envoi->rouvrir();
        $this->em->flush();
        $this->expedier($envoi, $p, $rangs, $maintenant);

        return $envoi;
    }

    /**
     * « M'envoyer un test » : sa propre version, à sa propre adresse,
     * gardé dans l'historique comme essai (ne compte pas comme l'envoi de
     * la période).
     */
    public function essai(User $u, PeriodeRapport $p, \DateTimeImmutable $maintenant): EnvoiRapport
    {
        $droits = $this->destinataires->droits($u);
        $envoi = (new EnvoiRapport($p->type, $p->code(), $p->debut, EnvoiRapport::ESSAI, $maintenant, $u))
            ->preparer([[
                'utilisateur' => (int) $u->getId(),
                'nom' => Objectifs::nomAgent($u),
                'adresse' => DestinatairesRapports::adresse($u),
                'version' => DroitsRapport::version($droits),
            ]], $this->reglages->pieceJointe());
        $this->em->persist($envoi);
        $this->em->flush();
        $this->expedier($envoi, $p, [0], $maintenant, true);

        return $envoi;
    }

    /** Essais demandés par cette personne dans la dernière heure. */
    public function essaisRecents(User $u, \DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(EnvoiRapport::class, 'e')
            ->andWhere('e.declencheur = :essai')
            ->andWhere('e.demandePar = :u')
            ->andWhere('e.demarreLe > :depuis')
            ->setParameter('essai', EnvoiRapport::ESSAI)
            ->setParameter('u', $u)
            ->setParameter('depuis', $maintenant->modify('-1 hour'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Derniers envois, essais compris, du plus récent au plus ancien.
     *
     * @return list<EnvoiRapport>
     */
    public function historique(int $nombre = 30): array
    {
        return $this->em->getRepository(EnvoiRapport::class)->findBy([], ['demarreLe' => 'DESC', 'id' => 'DESC'], $nombre);
    }

    /**
     * Erreur d'envoi lisible et sans secret (un identifiant dans une
     * adresse smtp:// n'apparaît jamais dans l'historique).
     */
    public static function erreurLisible(\Throwable $e): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        $message = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^@\s/]+@#i', '$1***@', $message);
        $message = (string) preg_replace('/(password|passwd|pass|pwd|secret|token|key)=[^\s&"]+/i', '$1=***', $message);
        if ('' === $message) {
            $message = 'erreur inconnue';
        }

        return mb_substr($message, 0, 240);
    }

    /**
     * Envoie à chaque destinataire de rang $rangs, avec ses droits du
     * moment (un compte désactivé ou qui a perdu le droit ne reçoit plus
     * rien), et note le résultat au fil de l'eau.
     *
     * @param list<int> $rangs
     */
    private function expedier(EnvoiRapport $envoi, PeriodeRapport $p, array $rangs, \DateTimeImmutable $maintenant, bool $essai = false): void
    {
        $de = $this->reglages->expediteur();
        $lignes = $envoi->getLignes();
        try {
            foreach ($rangs as $i) {
                $u = $this->em->find(User::class, $lignes[$i]['utilisateur']);
                if (null === $u || !$u->isActif()) {
                    $envoi->noter($i, EnvoiRapport::LIGNE_ECHEC, new \DateTimeImmutable(), 'Compte supprimé ou désactivé');
                    $this->em->flush();
                    continue;
                }
                $adresse = DestinatairesRapports::adresse($u);
                $envoi->adresser($i, $adresse);
                if (null === $adresse) {
                    $this->em->flush();
                    continue;
                }
                $roles = $this->destinataires->roles($u);
                $droits = DroitsRapport::depuisRoles($roles);
                if (!$droits['rapport']) {
                    $envoi->noter($i, EnvoiRapport::LIGNE_ECHEC, new \DateTimeImmutable(), 'N’a plus le droit « Chiffre d’affaires global »');
                    $this->em->flush();
                    continue;
                }
                try {
                    if (null === $de) {
                        throw new \RuntimeException('Expéditeur (MAILER_FROM_ADDRESS) non réglé');
                    }
                    $contexte = $this->courriel->contexte($u, $droits, $roles, $p, $maintenant, $essai);
                    $this->transport->send($this->courriel->email($de, $adresse, $contexte, $envoi->isPieceJointe()));
                    $envoi->noter($i, EnvoiRapport::LIGNE_ENVOYE, new \DateTimeImmutable());
                } catch (\Throwable $e) {
                    $this->logger->warning('Rapport par e-mail non envoyé ({rapport}) : {erreur}', ['rapport' => $envoi->getCle() ?? 'essai', 'erreur' => self::erreurLisible($e)]);
                    $envoi->noter($i, EnvoiRapport::LIGNE_ECHEC, new \DateTimeImmutable(), self::erreurLisible($e));
                }
                $this->em->flush();
            }
        } finally {
            $envoi->terminer(new \DateTimeImmutable());
            $this->courriel->oublier();
            $this->em->flush();
        }
    }
}
