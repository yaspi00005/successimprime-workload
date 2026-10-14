<?php

namespace App\Command;

use App\Controller\SiteController;
use App\Entity\ArticleConseil;
use App\Entity\CategorieProduit;
use App\Entity\PageSite;
use App\Entity\Realisation;
use App\Security\AccesEnLigne;
use App\Service\Site\ContenuInitial;
use App\Service\Site\ContenuSite;
use App\Twig\SiteContenuExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Textes provisoires du site vitrine (ContenuInitial) : les pages 05 à 09,
 * et avec --demo quelques réalisations et conseils marqués « (démo) ».
 *
 * Se relance sans risque : ce qui existe déjà (même slug, même titre) n'est
 * jamais modifié, pour ne pas écraser ce que l'atelier a écrit. À lancer
 * sur l'ERP local : le site en ligne reçoit le tout par la synchronisation.
 */
#[AsCommand(name: 'app:site:contenu-initial', description: 'Crée les textes provisoires des pages du site vitrine (et des exemples « (démo) » avec --demo), sans rien écraser.')]
final class SiteContenuInitialCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccesEnLigne $acces,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo', null, InputOption::VALUE_NONE, 'Ajoute aussi des réalisations et des conseils d\'exemple, marqués « (démo) ».');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->acces->estEnLigne()) {
            $io->error('Le contenu du site se crée sur l\'ERP local ; le site en ligne le reçoit par la synchronisation.');

            return Command::FAILURE;
        }

        $maintenant = new \DateTimeImmutable();
        $crees = ['pages' => 0, 'réalisations' => 0, 'conseils' => 0];

        $pages = $this->em->getRepository(PageSite::class);
        $rang = 0;
        foreach (ContenuInitial::pages() as $slug => $texte) {
            if (null === $pages->findOneBy(['slug' => $slug])) {
                $this->em->persist((new PageSite($slug))
                    ->setTitre($texte['titre'])
                    ->setAccroche($texte['accroche'])
                    ->setDescription($texte['description'])
                    ->setCorps($texte['corps'])
                    ->setOrdre($rang)
                    ->setPublie(true)
                    ->noterModification($maintenant));
                ++$crees['pages'];
            }
            ++$rang;
        }

        if ($input->getOption('demo')) {
            $categories = $this->categoriesParFamille();
            $realisations = $this->em->getRepository(Realisation::class);
            foreach (ContenuInitial::realisations() as $exemple) {
                if (null === $realisations->findOneBy(['titre' => $exemple['titre']])) {
                    $this->em->persist((new Realisation())
                        ->setTitre($exemple['titre'])
                        ->setCategorie($categories[$exemple['famille']] ?? null)
                        ->setDescription($exemple['description'])
                        ->setDateRealisation($maintenant->modify('-'.$exemple['jours'].' days')->setTime(0, 0))
                        ->setPublie(true));
                    ++$crees['réalisations'];
                }
            }

            $articles = $this->em->getRepository(ArticleConseil::class);
            foreach (ContenuInitial::conseils() as $exemple) {
                $slug = ContenuSite::slug(SiteContenuExtension::sansDemo($exemple['titre'])).'-demo';
                if (null === $articles->findOneBy(['slug' => $slug])) {
                    $publieLe = $maintenant->modify('-'.$exemple['jours'].' days')->setTime(9, 0);
                    $this->em->persist((new ArticleConseil())
                        ->setTitre($exemple['titre'])
                        ->setSlug($slug)
                        ->setRubrique($exemple['rubrique'])
                        ->setResume($exemple['resume'])
                        ->setCorps($exemple['corps'])
                        ->setPublieLe($publieLe)
                        ->setPublie(true)
                        ->noterModification($publieLe));
                    ++$crees['conseils'];
                }
            }
        }

        $this->em->flush();

        $io->success(\sprintf(
            'Créé(s) : %d page(s), %d réalisation(s), %d conseil(s). Ce qui existait déjà n\'a pas été touché.',
            $crees['pages'], $crees['réalisations'], $crees['conseils'],
        ));
        $io->note('Textes provisoires : à relire et à remplacer dans Réglages › Site vitrine (liste dans docs/site-vitrine.md).');

        return Command::SUCCESS;
    }

    /**
     * Une catégorie publiée par famille d'univers (grand, textile…), la
     * catégorie « (démo) » de préférence pour les exemples.
     *
     * @return array<string, CategorieProduit>
     */
    private function categoriesParFamille(): array
    {
        $parFamille = [];
        foreach ($this->em->getRepository(CategorieProduit::class)->findBy(['publie' => true], ['ordre' => 'ASC', 'nom' => 'ASC']) as $categorie) {
            $nom = (string) $categorie->getNom();
            $famille = SiteController::famille(SiteContenuExtension::sansDemo($nom), 0);
            // Nom sans mot d'une famille : famille() choisirait au rang, la catégorie est ignorée.
            if ($famille !== SiteController::famille(SiteContenuExtension::sansDemo($nom), 1)) {
                continue;
            }
            if (!isset($parFamille[$famille]) || (str_contains($nom, '(démo)') && !str_contains((string) $parFamille[$famille]->getNom(), '(démo)'))) {
                $parFamille[$famille] = $categorie;
            }
        }

        return $parFamille;
    }
}
