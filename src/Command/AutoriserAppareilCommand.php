<?php

namespace App\Command;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccesEnLigne;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Premier appareil de l'administration en ligne : personne n'est encore
 * connecté pour afficher le QR des réglages, on donne donc le lien ici.
 * DEFAULT_URI doit être l'adresse publique du site.
 */
#[AsCommand(name: 'app:autoriser-appareil', description: 'Donne un lien (valable 5 min) pour autoriser un appareil à l\'administration en ligne')]
final class AutoriserAppareilCommand extends Command
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly AccesEnLigne $acces,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifiant', InputArgument::REQUIRED, 'Identifiant de la personne')
            ->addOption('nom', null, InputOption::VALUE_REQUIRED, 'Nom de l\'appareil', 'Premier appareil')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Rôle en ligne : '.implode(', ', array_keys(AppareilAutorise::ROLES)), AppareilAutorise::ROLE_ADMIN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $utilisateur = $this->utilisateurs->findOneBy(['username' => $input->getArgument('identifiant')]);
        $role = (string) $input->getOption('role');

        if (!$utilisateur instanceof User || !$utilisateur->isActif()) {
            $io->error('Utilisateur introuvable ou désactivé.');

            return Command::FAILURE;
        }
        if (!isset(AppareilAutorise::ROLES[$role])) {
            $io->error('Rôle inconnu. Choix : '.implode(', ', array_keys(AppareilAutorise::ROLES)).'.');

            return Command::FAILURE;
        }

        $jeton = $this->acces->creerInvitation($utilisateur, $role, (string) $input->getOption('nom'), null);
        $io->success('Ouvrez ce lien sur l\'appareil à autoriser, dans les 5 minutes :');
        $io->writeln($this->urls->generate('app_appareil_autoriser', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL));
        if (!$this->acces->estEnLigne()) {
            $io->note('APP_EN_LIGNE=0 : la règle ne s\'appliquera qu\'une fois APP_EN_LIGNE=1 dans .env.local.');
        }

        return Command::SUCCESS;
    }
}
