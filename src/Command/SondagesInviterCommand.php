<?php

namespace App\Command;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\Sondages\InvitationsSondage;
use App\Service\Sondages\SondageCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rattrapage des invitations au sondage de commande (docs/sondages.md) :
 * les commandes livrées ces derniers jours qui n'en ont pas encore (par
 * exemple livrées avant la mise en service). Au local seulement ; les SMS
 * ne partent qu'avec --sms.
 */
#[AsCommand(name: 'app:sondages:inviter', description: 'Crée les invitations au sondage des commandes livrées récemment qui n\'en ont pas (--sms pour envoyer aussi le SMS).')]
final class SondagesInviterCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationsSondage $invitations,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('jours', null, InputOption::VALUE_REQUIRED, 'Commandes livrées depuis ce nombre de jours.', (string) SondageCommande::JOURS_POUR_REPONDRE)
            ->addOption('sms', null, InputOption::VALUE_NONE, 'Envoie aussi le SMS d\'invitation quand tout le permet.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->error('Les invitations se créent à l\'ERP local.');

            return Command::FAILURE;
        }
        $maintenant = new \DateTimeImmutable();
        $depuis = $maintenant->modify(\sprintf('-%d days', max(1, (int) $input->getOption('jours'))));
        /** @var list<Commandes> $commandes */
        $commandes = $this->em->createQueryBuilder()->select('DISTINCT c')->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'd')
            ->where('c.statut = true')->andWhere('c.deleted = false')->andWhere('c.clients IS NOT NULL')
            ->andWhere('d.statutProduction = :livree')->andWhere('COALESCE(d.productionTermineeLe, c.dateLivraison, c.dateCommande) >= :depuis')
            ->setParameter('livree', CommandesDetails::PRODUCTION_LIVREE)->setParameter('depuis', $depuis)
            ->getQuery()->getResult();

        $creees = 0;
        foreach ($commandes as $c) {
            $invitation = $this->invitations->inviter($c, $maintenant, false);
            // Créée à l'instant (et non retrouvée) : même date que maintenant.
            if (null !== $invitation && $invitation->getCreeLe() == $maintenant) {
                ++$creees;
                if ($input->getOption('sms')) {
                    $this->invitations->envoyerSms($invitation, $maintenant);
                }
            }
        }
        $io->success(\sprintf('%d commande%s examinée%s, %d invitation%s créée%s.', \count($commandes), \count($commandes) > 1 ? 's' : '', \count($commandes) > 1 ? 's' : '', $creees, $creees > 1 ? 's' : '', $creees > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
