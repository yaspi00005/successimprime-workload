<?php

namespace App\Command;

use App\Entity\BulletinPaie;
use App\Entity\User;
use App\Repository\EvaluationPrimeRepository;
use App\Security\AccesEnLigne;
use App\Service\Pointage\Pointages;
use App\Service\Primes\Primes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Performance et primes (docs/immobilisations-primes.md).
 *
 * - sans option (chaque matin) : reporte les primes validées par la
 *   direction sur le bulletin de paie en brouillon (somme due ; rien
 *   n'est versé automatiquement) ;
 * - --demo : évaluations des quatre derniers mois du personnel de
 *   démonstration, avec appréciation, validation, une nouvelle version
 *   et une prime suspendue ; septembre de Seydou reste sans appréciation
 *   (refusé en production).
 */
#[AsCommand(name: 'app:primes', description: 'Reporte les primes validées sur les bulletins de paie en brouillon (ou --demo).')]
final class PrimesCommand extends Command
{
    private const APPRECIATIONS = [
        'Très bonne relation client', 'Sérieux et ponctuel', 'Bon esprit d\'équipe', 'Doit gagner en rigueur', 'Très bonne implication ce mois-ci',
        'Travail soigné', 'Peut mieux faire sur les délais', 'Toujours disponible',
    ];

    public function __construct(
        private readonly Primes $primes,
        private readonly EvaluationPrimeRepository $evaluations,
        private readonly Pointages $pointages,
        private readonly EntityManagerInterface $em,
        private readonly AccesEnLigne $acces,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Évaluations des quatre derniers mois du personnel de démonstration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->acces->estEnLigne()) {
            $io->error('Les primes restent sur l\'ERP de la boutique : lancez la commande en local.');

            return Command::FAILURE;
        }
        if (!$input->getOption('demo')) {
            $lignes = $this->primes->reporterEnPaie(new \DateTimeImmutable());
            $io->success(sprintf('%d ligne%s de prime ajoutée%s aux bulletins en brouillon.', $lignes, $lignes > 1 ? 's' : '', $lignes > 1 ? 's' : ''));

            return Command::SUCCESS;
        }
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }

        return $this->demo($io, new \DateTimeImmutable());
    }

    private function demo(SymfonyStyle $io, \DateTimeImmutable $maintenant): int
    {
        $direction = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.kadiatou']);
        if (!$direction instanceof User) {
            $io->warning('Démonstration absente (demo.kadiatou) : lancez d\'abord app:demo.');

            return Command::SUCCESS;
        }
        $agents = array_values(array_filter($this->pointages->personnel(), fn (User $u): bool => $u->getId() >= 900000 && null !== $this->primes->role($u)));
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        $faites = 0;
        for ($k = 4; $k >= 1; --$k) {
            $mois = $courant->modify(sprintf('-%d months', $k));
            $le = $mois->modify('+1 month')->setTime(10, 0)->modify('+1 day');
            foreach ($agents as $agent) {
                if ([] !== $this->evaluations->versions($agent, $mois)) {
                    continue;
                }
                $nomUtilisateur = (string) $agent->getUserIdentifier();
                // Septembre de Seydou : appréciation pas encore saisie (N01 « Appréciation à saisir »).
                if (1 === $k && 'demo.seydou' === $nomUtilisateur) {
                    continue;
                }
                $graine = crc32($nomUtilisateur.$mois->format('Y-m'));
                $par = Primes::responsableDe($agent) ?? $direction;
                $suspendue = 3 === $k && 'demo.sekou' === $nomUtilisateur;
                $this->primes->apprecier(
                    $agent,
                    $mois,
                    72 + $graine % 25,
                    self::APPRECIATIONS[$graine % \count(self::APPRECIATIONS)],
                    $suspendue,
                    $suspendue ? 'Colis égaré pendant une tournée, non déclaré' : null,
                    $par,
                    $le,
                );
                $erreur = $this->primes->valider($agent, $mois, $par, $le);
                if (null !== $erreur) {
                    $io->text(sprintf('%s %s : %s', Pointages::nom($agent), $mois->format('m/Y'), $erreur));
                    continue;
                }
                ++$faites;
                // Août de Moussa : une facture saisie en retard, recalcul en version 2.
                if (2 === $k && 'demo.moussa' === $nomUtilisateur) {
                    $plusTard = $le->modify('+3 days');
                    $this->primes->recalculer($agent, $mois, 'Facture du 28 saisie en retard', $direction, $plusTard);
                    $this->primes->valider($agent, $mois, $direction, $plusTard);
                }
            }
            // Mois déjà payés : primes validées par la direction et versées avec le bulletin du mois ;
            // le mois dernier attend « Valider les primes ».
            if ($k >= 2) {
                foreach ($this->evaluations->dernieresDuMois($mois) as $e) {
                    if ($e->getAgent()->getId() >= 900000 && $e->estValidee() && !$e->primeEstValidee()) {
                        $e->validerPrime($direction, $le->modify('+2 days'));
                        $bulletin = $this->em->getRepository(BulletinPaie::class)->findOneBy(['utilisateur' => $e->getAgent(), 'mois' => $mois]);
                        if ($bulletin instanceof BulletinPaie) {
                            $e->reporter($e->isSuspendue() ? 0 : $e->getPrime(), $bulletin);
                        }
                    }
                }
                $this->em->flush();
            }
        }
        $io->success(sprintf('%d évaluation%s de démonstration validée%s (%d personnes).', $faites, $faites > 1 ? 's' : '', $faites > 1 ? 's' : '', \count($agents)));

        return Command::SUCCESS;
    }
}
