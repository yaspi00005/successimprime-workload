<?php

namespace App\Command;

use App\Repository\ClientsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:clients:initialiser-plafond-credit',
    description: 'Donne un plafond de crédit par défaut aux clients qui n’en ont pas encore un.'
)]
final class InitialiserPlafondCreditClientsCommand extends Command
{
    private const PLAFOND_PAR_DEFAUT = 100000;

    public function __construct(
        private readonly ClientsRepository $clientsRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'confirmer',
            null,
            InputOption::VALUE_NONE,
            'Applique réellement le changement (sans cette option, la commande ne fait qu’un aperçu, sans rien modifier).'
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        /*
         * Ne touche jamais un client qui a déjà un plafond configuré
         * (0 ou une valeur négative comptent comme "jamais configuré").
         */
        $clients = $this->clientsRepository->findAll();

        $concernes = [];

        foreach ($clients as $client) {
            if ((int) $client->getPlafondCredit() <= 0) {
                $concernes[] = $client;
            }
        }

        if ($concernes === []) {
            $output->writeln(
                '<info>Tous les clients ont déjà un plafond de crédit configuré. Rien à faire.</info>'
            );

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>%d client(s) sans plafond de crédit (0 ou vide) recevront un plafond de %s FCFA :</comment>',
            count($concernes),
            number_format(self::PLAFOND_PAR_DEFAUT, 0, ',', ' ')
        ));

        foreach ($concernes as $client) {
            $output->writeln(sprintf(
                '  - #%d %s (plafond actuel : %d)',
                $client->getId(),
                $client->getNomComplet(),
                (int) $client->getPlafondCredit()
            ));
        }

        if (!$input->getOption('confirmer')) {
            $output->writeln('');
            $output->writeln(
                '<comment>Aperçu uniquement, rien n’a été modifié. Relancez avec --confirmer pour appliquer.</comment>'
            );

            return Command::SUCCESS;
        }

        foreach ($concernes as $client) {
            $client->setPlafondCredit(self::PLAFOND_PAR_DEFAUT);
        }

        $this->entityManager->flush();

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%d client(s) mis à jour avec un plafond de %s FCFA.</info>',
            count($concernes),
            number_format(self::PLAFOND_PAR_DEFAUT, 0, ',', ' ')
        ));

        return Command::SUCCESS;
    }
}
