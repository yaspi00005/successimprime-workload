<?php

namespace App\Command;

use App\Service\NotificationService;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * À lancer chaque heure par le cron du serveur :
 * - après l'heure « Départ non pointé » (Paramètres › Pointage), prévient
 *   la RH une fois par jour des arrivées sans départ ;
 * - supprime les photos plus vieilles que la durée de conservation
 *   (les heures restent).
 */
#[AsCommand(name: 'app:pointage:entretien', description: 'Alerte RH des départs non pointés et purge des photos de pointage.')]
final class EntretienPointageCommand extends Command
{
    public function __construct(
        private readonly Pointages $pointages,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = new \DateTimeImmutable();
        $regles = $this->pointages->regles();

        $alerte = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $maintenant->format('Y-m-d').' '.$regles['alerte_depart']);
        $deja = $this->cache->getItem('pointage_alerte_'.$maintenant->format('Ymd'));
        if ($alerte !== false && $maintenant >= $alerte && !$deja->isHit()) {
            $noms = array_map(static fn (array $l): string => $l['nom'], $this->pointages->jour($maintenant, $maintenant)['nonPointes']);
            if ($noms !== []) {
                $this->notifications->notifierRoles(
                    ['ROLE_RH', 'ROLE_ADMIN'],
                    \sprintf('Départ non pointé : %s. À régulariser.', implode(', ', $noms)),
                    'app_pointage_jour',
                );
                $this->em->flush();
            }
            $this->cache->save($deja->set(\count($noms))->expiresAfter(86400 * 2));
            $output->writeln(\sprintf('Départs non pointés signalés : %d.', \count($noms)));
        }

        $output->writeln(\sprintf('Photos de pointage supprimées : %d.', $this->pointages->purgerPhotos($maintenant)));

        return Command::SUCCESS;
    }
}
