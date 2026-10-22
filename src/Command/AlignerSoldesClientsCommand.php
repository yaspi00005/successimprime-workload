<?php

namespace App\Command;

use App\Entity\EcritureDiverse;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Service\JournalComptable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Réponse de Yaya du 18/10/2026 (Q10) : les paiements avec le solde du
 * client faits avant le relevé du compte (ancien bouton « avoir ») n'ont
 * jamais été passés au journal. Cette commande passe pour chacun une
 * opération diverse (journal OD) : 4191 « Clients, avances » au débit,
 * 411 « Clients » au crédit (lettrée avec la facture de la commande),
 * comme le fait aujourd'hui un paiement avec le solde. Le compte 4191
 * rejoint ainsi le total de Caisse › Soldes clients.
 *
 * À lancer une fois sur l'ERP de l'imprimerie (docs/mise-en-production.md).
 * Relancée, elle ne repasse rien : un paiement déjà repris est sauté
 * (pièce OD-SC-<numéro du paiement>, unique). --dry-run montre ce qui
 * serait passé sans rien écrire.
 */
#[AsCommand(name: 'app:solde-client:aligner-4191', description: 'Passe en opération diverse les paiements avec le solde d\'avant le relevé (4191 contre 411), une seule fois.')]
final class AlignerSoldesClientsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalComptable $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait passé, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $essai = (bool) $input->getOption('dry-run');
        $maintenant = new \DateTimeImmutable();

        $io->title('Compte 4191 et soldes des clients');
        [$avant4191, $soldes] = $this->etat($maintenant);
        $io->text(\sprintf('Avant : 4191 = %s F, Soldes clients = %s F (écart %s F).', self::f($avant4191), self::f($soldes), self::f($avant4191 - $soldes)));

        $paiements = $this->aReprendre();
        if ([] === $paiements) {
            $io->success('Rien à reprendre : chaque paiement avec le solde est déjà au journal.');

            return $this->conclure($io, $maintenant);
        }

        $lignes = [];
        $total = 0;
        foreach ($paiements as $p) {
            $commande = $p->getCommande();
            $tiers = $commande?->getClients()?->getNomComplet();
            $lignes[] = [$p->getDate()?->format('d/m/Y') ?? '—', $commande?->getNumero() ?? '—', $tiers ?? '—', self::f($p->getMontant())];
            $total += $p->getMontant();
            if ($essai) {
                continue;
            }
            $piece = self::piece($p);
            $libelle = \sprintf('Reprise : payé avec le solde le %s · %s', $p->getDate()?->format('d/m/Y') ?? '?', $commande?->getNumero() ?? $tiers ?? 'paiement '.$p->getId());
            foreach ([['4191', $p->getMontant(), 0], ['411', 0, $p->getMontant()]] as [$compte, $debit, $credit]) {
                $e = (new EcritureDiverse($piece, $maintenant, $compte, $tiers, $libelle, $debit, $credit, EcritureDiverse::ORIGINE_SOLDE_CLIENT))->rattacher($commande, $p);
                if (null !== $p->getSociete()) {
                    $e->setSociete($p->getSociete());
                }
                $this->em->persist($e);
            }
        }
        $io->table(['Payé le', 'Commande', 'Client', 'Montant (F)'], $lignes);

        if ($essai) {
            $io->note(\sprintf('Essai : %d paiement(s), %s F, rien n\'est écrit. Sans --dry-run, l\'opération diverse est passée au %s.', \count($paiements), self::f($total), $maintenant->format('d/m/Y')));

            return Command::SUCCESS;
        }
        $this->em->flush();
        $io->success(\sprintf('%d paiement(s) repris en opération diverse, %s F (4191 au débit, 411 au crédit), au %s.', \count($paiements), self::f($total), $maintenant->format('d/m/Y')));

        return $this->conclure($io, $maintenant);
    }

    /**
     * Paiements avec le solde validés sans ligne au relevé du compte : ceux
     * de l'ancien bouton, d'avant le 18/10/2026, pas encore repris.
     *
     * @return list<Paiements>
     */
    private function aReprendre(): array
    {
        return $this->em->createQueryBuilder()
            ->select('p', 'c', 'cl')
            ->from(Paiements::class, 'p')
            ->leftJoin('p.commande', 'c')
            ->leftJoin('c.clients', 'cl')
            ->andWhere('p.mode = :solde')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.montant > 0')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.MouvementSoldeClient::class.' m WHERE m.paiement = p)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.EcritureDiverse::class.' e WHERE e.paiement = p)')
            ->setParameter('solde', Paiements::MODE_SOLDE_CLIENT)
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->orderBy('p.date', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public static function piece(Paiements $p): string
    {
        return \sprintf('OD-SC-%06d', (int) $p->getId());
    }

    /** @return array{0: int, 1: int} avance au 4191 (solde créditeur), total des soldes clients */
    private function etat(\DateTimeImmutable $maintenant): array
    {
        $avance = 0;
        foreach (JournalComptable::balance($this->journal->ecritures(new \DateTimeImmutable('2000-01-01'), $maintenant->modify('+1 day'))) as $c) {
            if ('4191' === $c['compte']) {
                $avance = -$c['solde'];
            }
        }
        $soldes = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(solde_credit), 0) FROM clients WHERE solde_credit > 0');

        return [$avance, $soldes];
    }

    private function conclure(SymfonyStyle $io, \DateTimeImmutable $maintenant): int
    {
        [$apres, $soldes] = $this->etat($maintenant);
        $io->text(\sprintf('Après : 4191 = %s F, Soldes clients = %s F.', self::f($apres), self::f($soldes)));
        if ($apres !== $soldes) {
            $io->warning(\sprintf('Il reste un écart de %s F : il ne vient pas des paiements avec le solde (solde déjà sur une fiche sans mouvement de trésorerie, solde d\'ouverture importé…). À voir avec le cabinet comptable ; rien d\'autre n\'est passé.', self::f($apres - $soldes)));
        }

        return Command::SUCCESS;
    }

    private static function f(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
