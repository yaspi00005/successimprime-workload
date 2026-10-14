<?php

namespace App\Command;

use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\Signature;
use App\Service\Synchro\Synchro;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agent de synchronisation de l'ERP local (docs/synchronisation.md).
 *
 * La tâche planifiée le lance chaque minute. --boucle le garde en marche
 * et refait un cycle toutes les 30 secondes (service sur le serveur local).
 */
#[AsCommand(name: 'app:synchro', description: 'Synchronise l\'ERP local avec le site en ligne (envoi puis réception).')]
final class SynchroCommand extends Command
{
    public function __construct(
        private readonly AgentSynchro $agent,
        private readonly Synchro $synchro,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('initialiser', null, InputOption::VALUE_OPTIONAL, 'Met en file tout ce qui part vers le site : catalogue, clients, devis, commandes, factures, paiements (premier chargement). Avec un type (ex. --initialiser=configuration) : ce type seulement', false)
            ->addOption('boucle', null, InputOption::VALUE_NONE, 'Tourne sans s\'arrêter, un cycle toutes les 30 s')
            ->addOption('nouvelle-cle', null, InputOption::VALUE_NONE, 'Affiche une clé SYNC_CLE à copier dans .env.local des deux machines')
            ->addOption('empreinte', null, InputOption::VALUE_NONE, 'Affiche l\'empreinte de SYNC_CLE et l\'heure de cette machine, à comparer avec l\'autre machine (la clé ne s\'affiche pas)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('nouvelle-cle')) {
            $io->writeln('SYNC_CLE='.bin2hex(random_bytes(32)));
            $io->note('À mettre dans .env.local de l\'ERP local ET du site. Ne jamais l\'envoyer par message ni la mettre dans le dépôt.');

            return Command::SUCCESS;
        }
        if ($input->getOption('empreinte')) {
            // Sur les deux machines : même empreinte et même longueur, heures à moins de 5 minutes.
            $cle = $this->synchro->cle();
            $io->writeln(\sprintf('Empreinte de SYNC_CLE : %s · longueur %d · heure de cette machine : %s UTC', $cle === '' ? 'aucune clé' : Signature::empreinte($cle), \strlen($cle), gmdate('H:i:s')));

            return Command::SUCCESS;
        }
        if ($this->synchro->estEnLigne()) {
            $io->error('Cette commande tourne sur l\'ERP local : c\'est lui qui appelle le site.');

            return Command::FAILURE;
        }
        if (false !== ($type = $input->getOption('initialiser'))) {
            $io->success($this->synchro->initialiser($type ?: null).' données mises en file pour le site.');
        }

        do {
            $r = $this->agent->cycle();
            $ligne = \sprintf('[%s] %s : %d envoyées, %d reçues%s%s', date('H:i:s'), $r['statut'], $r['envoyes'], $r['recus'], $r['conflits'] > 0 ? ', '.$r['conflits'].' conflit(s)' : '', $r['message'] ? ' · '.$r['message'] : '');
            // Un cycle sans rien à faire ne remplit pas le journal de la tâche planifiée.
            $rien = $r['statut'] === 'ok' && $r['envoyes'] === 0 && $r['recus'] === 0;
            $io->writeln($ligne, $rien ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
            if ($input->getOption('boucle')) {
                sleep(30);
            }
        } while ($input->getOption('boucle'));

        return \in_array($r['statut'], ['ok', 'occupe', 'non_configuree'], true) ? Command::SUCCESS : Command::FAILURE;
    }
}
