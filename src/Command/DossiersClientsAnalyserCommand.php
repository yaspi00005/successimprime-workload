<?php

namespace App\Command;

use App\Entity\Clients;
use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\ReglagesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dossiers clients (docs/dossiers-clients.md) : lit les dossiers des
 * clients sur le serveur de l'atelier (fichiers, miniatures, versions) et
 * le dossier « À classer ». Rien n'est lu pendant l'affichage des pages :
 * tout est préparé ici.
 *
 * Une seule tâche planifiée, toutes les 5 minutes, sur l'ERP local
 * (scripts\windows\installer_tache_dossiers_clients.bat) ou par cron :
 *
 *   * /5 * * * * cd /srv/successimprim && php bin/console app:dossiers-clients:analyser -q >> var/log/dossiers_clients.log 2>&1
 *
 * Elle fait l'analyse rapide (si « Surveiller les changements » est
 * allumé) et, une fois par nuit à l'heure réglée (02:00), l'analyse
 * complète. Jamais sur le site en ligne : il ne voit pas le serveur.
 */
#[AsCommand(name: 'app:dossiers-clients:analyser', description: 'Lit les dossiers des clients et le dossier « À classer » (rapide, ou complète la nuit).')]
final class DossiersClientsAnalyserCommand extends Command
{
    public function __construct(
        private readonly AnalyseDossiers $analyse,
        private readonly ReglagesDossiers $reglages,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('complete', null, InputOption::VALUE_NONE, 'Analyse complète tout de suite (tout est relu, les dossiers sont recherchés)')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Seulement le dossier de ce client (code)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->reglages->enLigne()) {
            $output->writeln('Site en ligne : les dossiers de l\'atelier ne sont pas lisibles d\'ici. Rien à faire.');

            return Command::SUCCESS;
        }
        if ('' === $this->reglages->racine() && '' === $this->reglages->dossierAClasser()) {
            $output->writeln('Dossiers clients pas réglés (Réglages › Dossiers clients). Rien à faire.');

            return Command::SUCCESS;
        }
        // Une seule analyse à la fois (la tâche des 5 minutes peut rattraper une analyse de nuit encore en cours).
        $dossier = $this->reglages->dossierTravail();
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        $verrou = @fopen($dossier.'/analyse.lock', 'c');
        if (false === $verrou || !flock($verrou, \LOCK_EX | \LOCK_NB)) {
            $output->writeln('Une analyse est déjà en cours.');

            return Command::SUCCESS;
        }
        try {
            $maintenant = new \DateTimeImmutable();
            if (null !== ($code = $input->getOption('client'))) {
                $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => (string) $code]);
                if (null === $client) {
                    $output->writeln('<error>Client introuvable : '.$code.'</error>');

                    return Command::FAILURE;
                }
                $n = $this->analyse->analyserClient($client, true, $maintenant);
                $this->em->flush();
                $output->writeln(\sprintf('%s : %d fichier%s.', $client->getNomComplet(), $n, $n > 1 ? 's' : ''));

                return Command::SUCCESS;
            }
            $complete = (bool) $input->getOption('complete') || $this->analyse->completeDue($maintenant);
            if (!$complete && !$this->reglages->valeur('surveiller')) {
                $output->writeln('« Surveiller les changements » est éteint : seule l\'analyse de nuit tourne.', OutputInterface::VERBOSITY_VERBOSE);

                return Command::SUCCESS;
            }
            $suivi = $output->isVerbose() ? static fn (string $etape) => $output->writeln('  '.$etape) : null;
            $b = $this->analyse->analyser($complete, $suivi, $maintenant);
            $output->writeln(\sprintf(
                '[%s] Analyse %s : %d dossiers, %d liés, %d suggestions%s, %d fichiers lus, %d à classer, %d ligne%s dans Mes actions.',
                $maintenant->format('d/m H:i'),
                $complete ? 'complète' : 'rapide',
                $b['dossiers'], $b['lies'], $b['suggeres'],
                ($b['crees'] ?? 0) > 0 ? ', '.$b['crees'].' dossier(s) créé(s)' : '',
                $b['fichiers'], $b['a_classer'], $b['actions'], $b['actions'] > 1 ? 's' : '',
            ));
            foreach ($b['erreurs'] as $e) {
                $output->writeln('<comment>'.$e.'</comment>');
            }

            return [] === $b['erreurs'] ? Command::SUCCESS : Command::FAILURE;
        } finally {
            flock($verrou, \LOCK_UN);
            fclose($verrou);
        }
    }
}
