<?php

namespace App\Command;

use App\Entity\Devis;
use App\Service\Devis\EnvoiDevis;
use App\Service\Devis\SuiviDevis;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tâche planifiée de l'ERP local (docs/devis.md), chaque heure :
 *
 * - les devis dont la validité est passée sans réponse deviennent
 *   « Expiré » (le client le voit à la synchronisation suivante) ;
 * - les devis envoyés depuis 3 jours, pas ouverts ou pas acceptés, sont
 *   relancés sur WhatsApp une fois par version, entre 8 h et 19 h. Avec la
 *   clé Meta (docs/messagerie-marketing.md), l'application envoie
 *   elle-même ; sans clé, ils restent dans « À relancer aujourd'hui » de
 *   la liste des devis, relancés d'un clic (lien wa.me, message écrit).
 */
#[AsCommand(name: 'app:devis:relances', description: 'Devis : expiration des devis sans réponse et relance WhatsApp 3 jours après l\'envoi.')]
final class DevisRelancesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnvoiDevis $envoi,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montrer ce qui serait fait, sans rien changer ni envoyer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->error('Les devis sont suivis par l\'ERP local : lancez cette tâche à l\'atelier.');

            return Command::FAILURE;
        }
        $aBlanc = (bool) $input->getOption('a-blanc');
        $maintenant = new \DateTimeImmutable();
        $heure = (int) $maintenant->format('G');

        /** @var list<Devis> $devis */
        $devis = $this->em->createQueryBuilder()->select('d', 'c')->from(Devis::class, 'd')->leftJoin('d.clients', 'c')
            ->where('d.deleted = false')->andWhere('d.statut IN (:attente)')
            ->setParameter('attente', Devis::STATUTS_EN_ATTENTE)
            ->getQuery()->getResult();

        $expires = $relances = $aLaMain = 0;
        foreach ($devis as $d) {
            if (SuiviDevis::estAExpirer($d, $maintenant)) {
                ++$expires;
                if (!$aBlanc) {
                    $d->setStatut(Devis::STATUT_EXPIRE);
                }
                continue;
            }
            if (!SuiviDevis::relanceDue($d, $maintenant)) {
                continue;
            }
            // Pas de message au client la nuit.
            if ($heure < 8 || $heure >= 19 || !$this->envoi->whatsAppAutomatique()) {
                ++$aLaMain;
                continue;
            }
            if ($aBlanc) {
                ++$relances;
                continue;
            }
            $this->envoi->relancerAutomatiquement($d, $maintenant) ? ++$relances : ++$aLaMain;
        }
        if (!$aBlanc) {
            $this->em->flush();
        }

        $io->writeln(\sprintf(
            '%sDevis expirés : %d. Relances WhatsApp envoyées : %d. À relancer depuis la liste : %d.',
            $aBlanc ? '[à blanc] ' : '',
            $expires, $relances, $aLaMain,
        ));

        return Command::SUCCESS;
    }
}
