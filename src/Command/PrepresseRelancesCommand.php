<?php

namespace App\Command;

use App\Service\Prepresse\RelancesFichiers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tâche planifiée de l'ERP local, chaque heure (docs/prepresse.md) : les
 * relances des fichiers manquants réglées dans Réglages › Relances des
 * fichiers. J+1 : WhatsApp préparé pour la prépresse (lien wa.me) ; J+3 :
 * WhatsApp et message dans l'espace client ; J+7 : appel à faire par la
 * caisse ; J+15 : commande en pause, date de livraison retirée (remise dès
 * que le fichier arrive). Chaque étape n'est faite qu'une fois : relancer
 * la tâche ne refait rien.
 */
#[AsCommand(name: 'app:prepresse:relances', description: 'Prépresse : relances automatiques des clients dont le fichier manque (J+1, J+3, J+7, J+15).')]
final class PrepresseRelancesCommand extends Command
{
    public function __construct(
        private readonly RelancesFichiers $relances,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->error('Les relances des fichiers sont suivies par l\'ERP local : lancez cette tâche à l\'atelier.');

            return Command::FAILURE;
        }
        $bilan = $this->relances->executer(new \DateTimeImmutable());
        foreach ($bilan['faites'] as $fait) {
            $io->writeln($fait);
        }
        $io->writeln(\sprintf('%d relance(s) faite(s), %d commande(s) reprise(s) après pause, %d appel(s) noté(s).', \count($bilan['faites']), $bilan['reprises'], $bilan['appels']));

        return Command::SUCCESS;
    }
}
