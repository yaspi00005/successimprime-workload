<?php

namespace App\Command;

use App\Security\AccesEnLigne;
use App\Service\Recette\BilanRecette;
use App\Service\Recette\RapportRecette;
use App\Service\Recette\RecetteAcces;
use App\Service\Recette\RecetteEspaceClient;
use App\Service\Recette\RecetteVisiteur;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recette automatique des accès par rôle (jalon J7, docs/recette-par-role.md),
 * à relancer avant chaque mise en production, sur une base d'essai qui a la
 * démonstration (app:demo).
 *
 * Sur l'ERP local : chaque compte demo.* demande chaque page de l'admin
 * (matrice rôle × page, comparée aux droits et aux menus), plus le mode
 * « Voir » et les fiches des autres. Sur le site en ligne : l'espace client
 * avec un particulier et les personnes d'un compte entreprise (montants,
 * devis, fiches d'un autre client), l'admin et l'API, les pages publiques.
 *
 * Sans effet : les pages sont demandées dans ce processus, dans une
 * transaction annulée à la fin, sans fin de requête (aucune notification
 * ne part), avec des sessions en mémoire. Refusée en production.
 */
#[AsCommand(name: 'app:recette:acces', description: 'Recette des accès par rôle : chaque compte de démonstration ouvre chaque page, comparée aux droits (sans rien enregistrer).')]
final class RecetteAccesCommand extends Command
{
    public function __construct(
        private readonly RecetteAcces $personnel,
        private readonly RecetteEspaceClient $espace,
        private readonly RecetteVisiteur $visiteur,
        private readonly RapportRecette $rapport,
        private readonly AccesEnLigne $acces,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('compte', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Seulement ces comptes du personnel (ex. --compte=demo.aminata)')
            ->addOption('sortie', null, InputOption::VALUE_REQUIRED, 'Dossier du bilan (Markdown et matrice CSV)', 'var/recette')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Affiche aussi les pages à examiner et les remarques');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('La recette des accès ne tourne pas en production : la lancer sur une base d\'essai avec la démonstration (app:demo).');

            return Command::FAILURE;
        }
        $enLigne = $this->acces->estEnLigne();
        if (!$this->personnel->demoPresente($enLigne)) {
            $io->error('Pas de données de démonstration dans cette base (comptes demo.*, clients DEMO-) : lancer d\'abord php bin/console app:demo, ou la synchronisation depuis un ERP local d\'essai.');

            return Command::FAILURE;
        }
        $io->title($enLigne ? 'Recette des accès : site en ligne' : 'Recette des accès : ERP local');
        $suivi = static function (string $etape) use ($io): void {
            $io->writeln('  · '.$etape);
        };

        if ($enLigne) {
            $bilan = $this->espace->verifier($suivi);
        } else {
            $comptes = $this->personnel->comptes((array) $input->getOption('compte'));
            if ([] === $comptes) {
                $io->error('Aucun compte de démonstration à essayer.');

                return Command::FAILURE;
            }
            $io->writeln(\sprintf('%d comptes du personnel, chacun sur toutes les pages de l\'admin.', \count($comptes)));
            $bilan = $this->personnel->verifier($comptes, $suivi);
        }
        $this->visiteur->verifier($bilan, $enLigne);

        $dossier = (string) $input->getOption('sortie');
        if (!str_starts_with($dossier, '/')) {
            $dossier = $this->projet.'/'.$dossier;
        }
        $fichiers = $this->rapport->ecrire($bilan, $dossier, $enLigne ? 'en-ligne' : 'local');
        $this->afficher($io, $bilan, (bool) $input->getOption('details'));
        $io->writeln('Bilan : '.implode(', ', $fichiers));

        if ($bilan->sansFuiteNiErreur()) {
            $io->success('Aucune fuite ni erreur. Relire les pages « à examiner » du bilan.');

            return Command::SUCCESS;
        }
        $io->error('Des fuites, des erreurs ou des contrôles en échec : voir le bilan.');

        return Command::FAILURE;
    }

    private function afficher(SymfonyStyle $io, BilanRecette $bilan, bool $details): void
    {
        if ([] !== $bilan->comptes) {
            $lignes = [];
            foreach ($bilan->resumeParCompte() as $compte => $n) {
                $lignes[] = [$compte, $bilan->comptes[$compte]['libelle'], $n['ouvert'], $n['refuse'], $n['connexion'], $n['redirige'], $n['introuvable'], $n['erreur']];
            }
            $io->table(['Compte', 'Rôle', 'Ouvertes', 'Refusées', 'Connexion', 'Redirigées', 'Introuvables', 'Erreurs'], $lignes);
        }
        $n = $bilan->compterConstats();
        $io->writeln(\sprintf('Constats : %d fuite(s), %d erreur(s), %d à examiner, %d remarque(s). Contrôles : %d, dont %d en échec.', $n[BilanRecette::FUITE], $n[BilanRecette::ERREUR], $n[BilanRecette::A_EXAMINER], $n[BilanRecette::INFO], \count($bilan->controles), \count(array_filter($bilan->controles, static fn (array $c): bool => !$c['ok']))));
        foreach ($bilan->constats as $c) {
            if ($details || \in_array($c['gravite'], [BilanRecette::FUITE, BilanRecette::ERREUR], true)) {
                $io->writeln(\sprintf('  [%s] %s %s (%s) : %s', BilanRecette::GRAVITES[$c['gravite']], $c['compte'], $c['chemin'], $c['route'], $c['detail']));
            }
        }
        foreach ($bilan->controles as $c) {
            if (!$c['ok'] || $details) {
                $io->writeln(\sprintf('  [%s] %s %s', $c['ok'] ? 'OK' : 'ÉCHEC', $c['controle'], $c['resultat']));
            }
        }
    }
}
