<?php

namespace App\Command;

use App\Service\CampagneService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:envoyer-campagnes',
    description: 'Envoie un lot de messages de campagne en attente (SMS/email/WhatsApp).'
)]
final class EnvoyerCampagnesCommand extends Command
{
    /**
     * Nombre de destinataires traites a chaque passage. Reste
     * modeste pour ne pas depasser les limites de debit des API
     * SMS/WhatsApp/email : la tache planifiee tourne toutes les 10
     * minutes (voir installer_tache_campagnes.bat), donc une
     * campagne de plusieurs centaines de destinataires s'etale sur
     * quelques heures plutot que de tout envoyer d'un coup.
     */
    private const TAILLE_LOT = 30;

    public function __construct(
        private readonly CampagneService $campagneService
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $resultat = $this->campagneService->envoyerLotEnAttente(self::TAILLE_LOT);

        $output->writeln(sprintf(
            '<info>%d envoyé(s), %d échec(s) sur %d traité(s).</info>',
            $resultat['envoyes'],
            $resultat['echecs'],
            $resultat['total']
        ));

        return Command::SUCCESS;
    }
}
