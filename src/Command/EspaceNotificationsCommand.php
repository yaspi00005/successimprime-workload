<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\Factures;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\NotificationsClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tâche planifiée du site en ligne (docs/espace-client.md), toutes les
 * 15 minutes :
 *
 * - envoie les SMS mis de côté pendant les heures calmes (21 h – 7 h) ;
 * - prévient les clients dont une facture arrive à échéance ou est échue
 *   (ces rappels ne viennent d'aucune synchronisation : ils dépendent de
 *   l'heure). Chaque rappel ne part qu'une fois, sans montant.
 */
#[AsCommand(name: 'app:espace:notifications', description: 'Espace client : SMS des heures calmes à 7 h et rappels d\'échéance des factures.')]
final class EspaceNotificationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationsClient $notifications,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->enLigne) {
            $io->error('Les clients sont prévenus par le site en ligne : lancez cette tâche sur le serveur.');

            return Command::FAILURE;
        }
        $maintenant = new \DateTimeImmutable();

        $differees = $this->notifications->envoyerDifferees($maintenant);

        $rappels = 0;
        foreach ($this->clientsAvecEcheance($maintenant) as $client) {
            $rappels += \count(array_filter(
                $this->notifications->notifier($client, $maintenant),
                static fn (array $e): bool => 'echeance' === $e['type'],
            ));
        }

        $io->writeln(\sprintf(
            'Heures calmes : %d SMS envoyé(s), %d périmé(s), %d échec(s). Rappels d\'échéance : %d.',
            $differees['envoyes'], $differees['perimes'], $differees['echecs'], $rappels,
        ));

        return Command::SUCCESS;
    }

    /**
     * Clients qui ont un compte actif et une facture émise, à payer, dont
     * l'échéance est passée ou tombe aujourd'hui (la relance à J+7 aussi).
     *
     * @return list<Clients>
     */
    private function clientsAvecEcheance(\DateTimeImmutable $maintenant): array
    {
        return $this->em->createQuery(
            'SELECT DISTINCT c FROM '.Clients::class.' c
             JOIN '.Factures::class.' f WITH f.commande IS NOT NULL
             JOIN f.commande k
             WHERE k.clients = c AND f.etat = :emise AND f.typeDocument <> :proforma AND f.resteAPayer > 0
               AND f.dateEcheance IS NOT NULL AND f.dateEcheance <= :fin AND f.dateEcheance >= :debut
               AND EXISTS (SELECT 1 FROM '.CompteClient::class.' cc WHERE cc.client = c AND cc.actif = true)',
        )
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('proforma', Factures::TYPE_PROFORMA)
            ->setParameter('fin', $maintenant->setTime(23, 59, 59))
            // Au-delà, le rappel et la relance sont déjà partis (ou trop vieux pour partir).
            ->setParameter('debut', $maintenant->modify(\sprintf('-%d days', EspaceClient::RELANCE_ECHEANCE_JOURS + 1))->setTime(0, 0))
            ->getResult();
    }
}
