<?php

namespace App\Command;

use App\Repository\CommandeDetailFichierRepository;
use App\Repository\OrdreProductionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:nettoyer-fichiers-production',
    description: 'Supprime les fichiers de production arrivés à expiration.'
)]
final class NettoyerFichiersProductionCommand extends Command
{
    public function __construct(
        private readonly CommandeDetailFichierRepository $fichierRepository,
        private readonly OrdreProductionRepository $ordreProductionRepository,
        private readonly EntityManagerInterface $entityManager,

        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,

        #[Autowire('%env(int:FICHIER_PRODUCTION_RETENTION_MONTHS)%')]
        private readonly int $retentionMonths
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $dateLimite = new \DateTimeImmutable(
            sprintf(
                '-%d months',
                $this->retentionMonths
            )
        );

        $racine =
            $this->projectDir
            . '/var/uploads/commandes';

        $supprimes = 0;
        $ignores = 0;

        $fichiers =
            $this->fichierRepository->findBy([
                'supprimeStockage' => false,
            ]);

        foreach ($fichiers as $fichier) {

            $detail =
                $fichier->getCommandeDetail();

            if ($detail === null) {
                $ignores++;
                continue;
            }

            /*
             * On prend uniquement une production réellement terminée.
             */
            $ordre =
                $this->ordreProductionRepository
                    ->findOneBy(
                        [
                            'commandeDetail' => $detail,
                            'statut' => 'termine',
                        ],
                        [
                            'termineLe' => 'DESC',
                        ]
                    );

            if ($ordre === null) {
                $ignores++;
                continue;
            }

            /*
             * IMPORTANT :
             * la durée commence à la date de fin réelle
             * de production.
             */
            $dateFin =
                $ordre->getTermineLe();

            if ($dateFin === null) {
                $ignores++;
                continue;
            }

            if ($dateFin > $dateLimite) {
                continue;
            }

            $nomStockage = basename(
                trim(
                    (string) $fichier->getNomStockage()
                )
            );

            if (
                $nomStockage === ''
                || $nomStockage === '.'
            ) {
                $ignores++;
                continue;
            }

            $chemin =
                $racine
                . DIRECTORY_SEPARATOR
                . $nomStockage;

            /*
             * Suppression du fichier original.
             */
            if (is_file($chemin)) {
                @unlink($chemin);
            }

            /*
             * Suppression éventuelle de l'aperçu.
             */
            $cheminApercu =
                $racine
                . DIRECTORY_SEPARATOR
                . 'apercus'
                . DIRECTORY_SEPARATOR
                . $nomStockage;

            if (is_file($cheminApercu)) {
                @unlink($cheminApercu);
            }

            /*
             * On conserve la trace en base.
             */
            $fichier
                ->setSupprimeStockage(true)
                ->setSupprimeStockageLe(
                    new \DateTimeImmutable()
                )
                ->setActif(false);

            $supprimes++;
        }

        $this->entityManager->flush();

        $output->writeln(
            sprintf(
                '<info>%d fichier(s) supprimé(s).</info>',
                $supprimes
            )
        );

        $output->writeln(
            sprintf(
                '<comment>%d fichier(s) ignoré(s).</comment>',
                $ignores
            )
        );

        return Command::SUCCESS;
    }
}