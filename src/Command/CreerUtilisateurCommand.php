<?php

namespace App\Command;

use App\Entity\Employes;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\RoleUtilisateurRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un compte depuis le terminal, utile pour le premier administrateur
 * d'une installation neuve (personne ne peut encore se connecter à
 * Réglages > Utilisateurs). Le mot de passe est demandé sans être affiché.
 *
 *   php bin/console app:creer-utilisateur yaya
 *   php bin/console app:creer-utilisateur aminata --role=ROLE_CAISSE
 *   php bin/console app:creer-utilisateur yaya --reinitialiser
 */
#[AsCommand(name: 'app:creer-utilisateur', description: 'Crée un utilisateur (administrateur par défaut) ou réinitialise son mot de passe')]
final class CreerUtilisateurCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly RoleUtilisateurRepository $roles,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifiant', InputArgument::REQUIRED, 'Identifiant de connexion')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Code du rôle (Réglages > Rôles et droits)', RoleUtilisateur::CODE_ADMIN)
            ->addOption('reinitialiser', null, InputOption::VALUE_NONE, 'Le compte existe déjà : change seulement son mot de passe et le réactive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifiant = trim((string) $input->getArgument('identifiant'));
        $existant = $this->utilisateurs->findOneBy(['username' => $identifiant]);

        if ($existant instanceof User && !$input->getOption('reinitialiser')) {
            $io->error(\sprintf('« %s » existe déjà. Ajoutez --reinitialiser pour changer son mot de passe.', $identifiant));

            return Command::FAILURE;
        }

        if (!$existant instanceof User && $input->getOption('reinitialiser')) {
            $io->error(\sprintf('« %s » n\'existe pas.', $identifiant));

            return Command::FAILURE;
        }

        $profil = null;
        if (!$existant instanceof User) {
            $code = (string) $input->getOption('role');
            $profil = $this->roles->findOneBy(['code' => $code]);
            if (!$profil instanceof RoleUtilisateur) {
                $codes = array_map(static fn (RoleUtilisateur $r): string => $r->getCode(), $this->roles->dansLOrdre());
                $io->error(\sprintf('Rôle « %s » inconnu. Rôles possibles : %s.', $code, implode(', ', $codes)));

                return Command::FAILURE;
            }
        }

        $employe = null;
        if (!$existant instanceof User) {
            // Chaque compte est rattaché à une fiche employé (nom et téléphone obligatoires).
            $employe = (new Employes())
                ->setPrenom((string) $io->ask('Prénom', ucfirst($identifiant)))
                ->setNom((string) $io->ask('Nom', ' '))
                ->setTelephone((string) $io->ask('Téléphone', '-'))
                ->setFonction($profil->getNom());
            $this->em->persist($employe);
        }

        $motDePasse = (string) $io->askHidden('Mot de passe (8 caractères minimum, rien ne s\'affiche)', static function (?string $valeur): string {
            if (mb_strlen((string) $valeur) < 8) {
                throw new \RuntimeException('8 caractères minimum.');
            }

            return (string) $valeur;
        });

        if ($io->askHidden('Confirmez le mot de passe') !== $motDePasse) {
            $io->error('Les deux mots de passe sont différents.');

            return Command::FAILURE;
        }

        $utilisateur = $existant ?? (new User())->setUsername($identifiant)->setProfil($profil)->setEmploye($employe);
        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setMustChangePassword(false);
        $utilisateur->setActif(true);

        $this->em->persist($utilisateur);
        $this->em->flush();

        $io->success($existant instanceof User
            ? \sprintf('Mot de passe de « %s » changé.', $identifiant)
            : \sprintf('Compte « %s » créé avec le rôle %s.', $identifiant, $profil->getNom()));

        return Command::SUCCESS;
    }
}
