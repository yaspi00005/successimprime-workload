<?php

namespace App\Command;

use App\Service\Sauvegarde\Chiffrement;
use App\Service\Sauvegarde\Sauvegardes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rapatrie une copie hors site (fichier .sihs récupéré sur le serveur) :
 * la déchiffre avec SAUVEGARDE_CLE_HORS_SITE, la vérifie et la range parmi
 * les sauvegardes, d'où app:sauvegarde:restaurer la remet (docs/sauvegardes.md).
 */
#[AsCommand(name: 'app:sauvegarde-dechiffrer', description: 'Déchiffre une copie hors site (.sihs) et la range parmi les sauvegardes.')]
final class SauvegardeDechiffrerCommand extends Command
{
    public function __construct(
        private readonly Sauvegardes $sauvegardes,
        #[Autowire('%env(default::SAUVEGARDE_CLE_HORS_SITE)%')] private readonly ?string $cle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('fichier', InputArgument::REQUIRED, 'La copie chiffrée, par exemple 20261012-020000-base.sihs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getArgument('fichier');
        if (!Chiffrement::cleValable((string) $this->cle)) {
            $io->error('SAUVEGARDE_CLE_HORS_SITE manque dans .env.local (la clé gardée hors de la boutique).');

            return Command::FAILURE;
        }
        if (!preg_match('/(\d{8}-\d{6})-(base|fichiers)\.sihs$/', basename($source), $m)) {
            $io->error('Nom attendu : AAAAMMJJ-HHMMSS-base.sihs ou AAAAMMJJ-HHMMSS-fichiers.sihs');

            return Command::FAILURE;
        }
        [, $nom, $partie] = $m;
        @mkdir($this->sauvegardes->dossier(), 0700, true);
        $provisoire = $this->sauvegardes->dossier().'/.'.$nom.'-'.$partie.'.dechiffrement';
        @unlink($provisoire);
        try {
            Chiffrement::dechiffrer($source, $provisoire, (string) $this->cle);
            $this->sauvegardes->ajouterDepuisCopie($nom, $partie, $provisoire);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            @unlink($provisoire);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Copie vérifiée et rangée. Pour la remettre : php bin/console app:sauvegarde:restaurer %s --%s', $nom, $partie));

        return Command::SUCCESS;
    }
}
