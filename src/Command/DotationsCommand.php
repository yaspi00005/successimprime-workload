<?php

namespace App\Command;

use App\Security\AccesEnLigne;
use App\Service\Immobilisations\CalculAmortissement;
use App\Service\Immobilisations\Patrimoine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dotations aux amortissements du mois (docs/immobilisations-primes.md) :
 * une ligne par bien, écriture 681 / 28x dans le journal comptable.
 *
 * - sans option (chaque matin) : dote le mois précédent ; un bien déjà doté
 *   est sauté, une machine suivie par relevé attend son relevé jusqu'au
 *   jour limite (tuile Patrimoine), la commande peut donc tourner chaque jour ;
 * - --mois=AAAA-MM : dote un mois terminé précis (rattrapage) ;
 * - --demo : dote les six derniers mois terminés (refusé en production).
 */
#[AsCommand(name: 'app:immobilisations:dotations', description: 'Passe les dotations aux amortissements du mois précédent (681 / 28x).')]
final class DotationsCommand extends Command
{
    public function __construct(
        private readonly Patrimoine $patrimoine,
        private readonly AccesEnLigne $acces,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mois', null, InputOption::VALUE_REQUIRED, 'Mois terminé à doter (AAAA-MM), par défaut le mois précédent.')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Dote les six derniers mois terminés (démonstration).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->acces->estEnLigne()) {
            $io->error('Le patrimoine reste sur l\'ERP de l\'imprimerie : lancez la commande en local.');

            return Command::FAILURE;
        }
        $maintenant = new \DateTimeImmutable();
        $courant = CalculAmortissement::premierDuMois($maintenant);

        if ($input->getOption('demo')) {
            if ('prod' === $this->environnement) {
                $io->error('Pas de données fictives en production.');

                return Command::FAILURE;
            }
            $mois = [];
            for ($k = 6; $k >= 1; --$k) {
                $mois[] = $courant->modify(sprintf('-%d months', $k));
            }
        } elseif (null !== $input->getOption('mois')) {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $input->getOption('mois'))) {
                $io->error('Indiquez --mois=AAAA-MM.');

                return Command::INVALID;
            }
            $mois = [new \DateTimeImmutable($input->getOption('mois').'-01')];
        } else {
            $mois = [$courant->modify('-1 month')];
        }

        foreach ($mois as $m) {
            try {
                $r = $this->patrimoine->doter($m, null, $maintenant);
            } catch (\DomainException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->text(sprintf('%s : %d dotation%s, %s F.', $m->format('m/Y'), $r['creees'], $r['creees'] > 1 ? 's' : '', number_format($r['montant'], 0, ',', ' ')));
            foreach ($r['attente'] as $bien) {
                $io->text('  en attente du relevé : '.$bien);
            }
        }
        $io->success('Dotations passées. Écritures dans Finances › Comptabilité (journal des opérations diverses).');

        return Command::SUCCESS;
    }
}
