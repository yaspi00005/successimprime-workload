<?php

namespace App\Command;

use App\Service\Paiement\OrangeMoney;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Essai des clés Orange Money posées dans .env.local, sans en afficher
 * aucune. Montre aussi les frais d'un paiement de 100 (fonds de soutien et
 * frais de retrait, taux de Réglages › Paiements), même sans clé. Avec
 * --paiement (API d'essai seulement), ouvre un paiement de 100 OUV, frais
 * compris, pour vérifier la clé marchand. Montre enfin les derniers
 * paiements de clients qu'Orange n'a pas ouverts, avec sa réponse.
 */
#[AsCommand(name: 'app:orange-money:tester', description: 'Teste les clés Orange Money de .env.local (aucun secret affiché).')]
final class OrangeMoneyTesterCommand extends Command
{
    public function __construct(private readonly OrangeMoney $orangeMoney)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('paiement', null, InputOption::VALUE_NONE, 'Ouvre aussi un paiement d\'essai de 100 OUV frais compris (API d\'essai seulement) pour vérifier la clé marchand.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Essai des clés Orange Money');
        $etapes = $this->orangeMoney->diagnostic((bool) $input->getOption('paiement'), new \DateTimeImmutable());
        $io->table(['Étape', 'Résultat', 'Détail'], array_map(
            static fn (array $e): array => [$e['etape'], $e['ok'] ? '<info>OK</info>' : '<error>Erreur</error>', $e['detail']],
            $etapes,
        ));
        // Ce qu'Orange a répondu quand un client n'a pas pu payer (le site ne le montre pas au client).
        $refus = $this->orangeMoney->derniersRefus();
        if ([] !== $refus) {
            $io->section('Derniers paiements de clients refusés par Orange');
            $io->table(['Date', 'Référence', 'Raison'], array_map(
                static fn (array $r): array => [(new \DateTimeImmutable($r['le']))->format('d/m/Y H:i'), $r['reference'], $r['raison']],
                $refus,
            ));
        }
        if (array_filter($etapes, static fn (array $e): bool => !$e['ok'])) {
            $io->error('Corrigez .env.local, puis php bin/console cache:clear et relancez l\'essai.');

            return Command::FAILURE;
        }
        $io->success($input->getOption('paiement') ? 'Les clés et la clé marchand sont acceptées par Orange.' : 'Clés acceptées par Orange. Ajoutez --paiement pour vérifier aussi la clé marchand.');

        return Command::SUCCESS;
    }
}
