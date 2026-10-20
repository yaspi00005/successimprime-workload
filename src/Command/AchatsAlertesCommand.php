<?php

namespace App\Command;

use App\Service\Achats\AlertesAchats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Alertes d'achat (maquettes SU06 et SU07, docs/achats.md), chaque heure
 * sur l'ERP de l'imprimerie : stock bas, hausse de prix d'un fournisseur,
 * fournisseur en retard, commande oubliée. Chaque alerte nouvelle devient
 * une notification de l'équipe pour la direction (application et
 * téléphone, pas la nuit) ; celles qui ont cessé (article commandé,
 * commande reçue) sont retirées. Les règles s'allument dans Réglages ›
 * Achats et fournisseurs.
 */
#[AsCommand(name: 'app:achats:alertes', description: 'Alertes d\'achat : stock bas, prix, retard, commande oubliée.')]
final class AchatsAlertesCommand extends Command
{
    public function __construct(
        private readonly AlertesAchats $alertes,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->warning('À lancer sur l\'ERP de l\'imprimerie : le stock et les achats y sont tenus.');

            return Command::SUCCESS;
        }

        $maintenant = new \DateTimeImmutable();
        $bilan = $this->alertes->notifier($maintenant);
        $creees = array_sum(array_column($bilan, 'creees'));
        $resolues = array_sum(array_column($bilan, 'resolues'));

        if ($output->isVerbose() || $creees + $resolues > 0) {
            $details = [];
            foreach ($bilan as $regle => $b) {
                $details[] = \sprintf('%s %d/%d', AlertesAchats::LIBELLES[$regle], $b['creees'], $b['resolues']);
            }
            $io->writeln(\sprintf('[%s] %d notification(s) nouvelle(s), %d réglée(s) (%s).', $maintenant->format('Y-m-d H:i'), $creees, $resolues, implode(', ', $details)));
        }

        return Command::SUCCESS;
    }
}
