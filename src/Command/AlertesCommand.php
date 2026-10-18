<?php

namespace App\Command;

use App\Entity\AlerteSysteme;
use App\Repository\AlerteSystemeRepository;
use App\Service\Alertes\AlertesSysteme;
use App\Service\Alertes\VerificationsSysteme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Alertes système (docs/alertes-systeme.md), toutes les 5 minutes sur
 * l'ERP local ET sur le serveur en ligne (tâche planifiée Windows ou
 * cron) : vérifie la synchronisation, les sauvegardes, l'espace disque et
 * la file de messages, envoie les alertes qui attendaient la fin du délai
 * entre deux e-mails, et oublie les alertes résolues depuis 90 jours.
 */
#[AsCommand(name: 'app:alertes', description: 'Vérifie la synchronisation, les sauvegardes, le disque et la file de messages, et envoie les alertes système en attente.')]
final class AlertesCommand extends Command
{
    public function __construct(
        private readonly VerificationsSysteme $verifications,
        private readonly AlertesSysteme $alertes,
        private readonly AlerteSystemeRepository $depot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('essai', null, InputOption::VALUE_NONE, 'Crée une alerte d\'essai et l\'envoie tout de suite à ses destinataires')
            ->addOption('sans-verification', null, InputOption::VALUE_NONE, 'Envoie seulement les alertes en attente');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        date_default_timezone_set(RapportsMailCommand::FUSEAU);
        $maintenant = new \DateTimeImmutable();

        if ($input->getOption('essai')) {
            $id = $this->alertes->signaler(AlerteSysteme::ESSAI, 'Alerte d\'essai (commande app:alertes --essai)', 'Essai lancé depuis la ligne de commande. Rien n\'est cassé : résolvez-la.', ['essai', $maintenant->format('U')], AlerteSysteme::AVERTISSEMENT, $maintenant);
            $io->writeln(null === $id ? 'Alerte d\'essai non créée (voir le journal du serveur).' : 'Alerte d\'essai créée.');
        }

        if (!$input->getOption('sans-verification')) {
            $constats = $this->verifications->verifier($maintenant);
            foreach ($constats as $constat) {
                $io->writeln('• '.$constat);
            }
            if ([] === $constats) {
                $io->writeln('Tout va bien : synchronisation, sauvegardes, disque et file de messages.', OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        $bilan = $this->alertes->traiterEnAttente($maintenant);
        if ($bilan['envoyees'] > 0 || $bilan['attente'] > 0) {
            $io->writeln(\sprintf('Alertes prévenues : %d (%d e-mail%s) ; en attente du délai : %d.', $bilan['envoyees'], $bilan['emails'], $bilan['emails'] > 1 ? 's' : '', $bilan['attente']));
        }

        $purgees = $this->depot->purger($maintenant->modify('-'.AlertesSysteme::GARDE_JOURS.' days'));
        if ($purgees > 0) {
            $io->writeln(\sprintf('%d alerte%s résolue%s depuis plus de %d jours oubliée%s.', $purgees, $purgees > 1 ? 's' : '', $purgees > 1 ? 's' : '', AlertesSysteme::GARDE_JOURS, $purgees > 1 ? 's' : ''), OutputInterface::VERBOSITY_VERBOSE);
        }

        return Command::SUCCESS;
    }
}
