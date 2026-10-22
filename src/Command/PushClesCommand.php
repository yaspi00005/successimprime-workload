<?php

namespace App\Command;

use App\Service\Push\WebPush;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée les clés des notifications push (VAPID). À lancer une seule fois
 * par serveur ; les lignes affichées vont dans .env.local, jamais dans le
 * dépôt. Changer les clés oblige chaque téléphone à réactiver les
 * notifications.
 */
#[AsCommand(name: 'app:push:cles', description: 'Crée les clés des notifications sur le téléphone (à mettre dans .env.local).')]
final class PushClesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cles = WebPush::nouvellesCles();
        $io->text('Ajoutez ces deux lignes à .env.local sur le serveur, puis lancez php bin/console cache:clear :');
        $io->newLine();
        $output->writeln('VAPID_PUBLIC_KEY='.$cles['publique']);
        $output->writeln('VAPID_PRIVATE_KEY='.$cles['prive']);
        $io->newLine();
        $io->warning('La clé privée ne se partage pas et ne va jamais dans le dépôt.');

        return Command::SUCCESS;
    }
}
