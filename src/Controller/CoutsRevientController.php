<?php

namespace App\Controller;

use App\Entity\Articles;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\ProduitArticleStock;
use App\Service\CalculateurCoutRevient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reglages · Couts de revient : les donnees dont le calcul automatique
 * a besoin (machines, finitions, matieres), regroupees sur un ecran.
 */
#[Route('/reglages/couts', name: 'app_couts_revient_')]
#[IsGranted('ROLE_ADMIN')]
final class CoutsRevientController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $machines = $em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']);
        $finitions = $em->getRepository(Finition::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
        $matieres = $this->matieres($em);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('couts-revient', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $saisie = $request->getPayload()->all();

            foreach ($machines as $machine) {
                $valeurs = $saisie['machines'][$machine->getId()] ?? [];
                $machine
                    ->setVitesseM2Heure(self::decimal($valeurs['vitesse'] ?? null))
                    ->setCoutHoraire(self::entier($valeurs['horaire'] ?? null))
                    ->setCoutEncreM2(self::entier($valeurs['encre'] ?? null));
            }

            foreach ($finitions as $finition) {
                $finition->setCoutUnitaire(self::entier($saisie['finitions'][$finition->getId()] ?? null));
            }

            foreach ($matieres as $article) {
                $article->setPrixAchat(self::entier($saisie['matieres'][$article->getId()] ?? null));
            }

            $em->flush();
            $this->addFlash('success', 'Coûts enregistrés. Ils servent aux prochaines lignes de commande.');

            return $this->redirectToRoute('app_couts_revient_index');
        }

        return $this->render('couts_revient/index.html.twig', [
            'machines' => $machines,
            'coutsHoraires' => array_map(CalculateurCoutRevient::coutHoraire(...), $machines),
            'finitions' => $finitions,
            'matieres' => $matieres,
        ]);
    }

    /**
     * Articles de stock lies a au moins un produit : ce sont eux qui
     * entrent dans le cout des lignes.
     *
     * @return list<Articles>
     */
    private function matieres(EntityManagerInterface $em): array
    {
        return $em->createQueryBuilder()
            ->select('a')
            ->from(Articles::class, 'a')
            ->where(sprintf('a.id IN (SELECT IDENTITY(l.article) FROM %s l WHERE l.actif = true)', ProduitArticleStock::class))
            ->orderBy('a.designation', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private static function entier(mixed $valeur): ?int
    {
        $valeur = str_replace([' ', ' ', ','], ['', '', '.'], trim((string) $valeur));

        return is_numeric($valeur) ? max(0, (int) round((float) $valeur)) : null;
    }

    private static function decimal(mixed $valeur): ?float
    {
        $valeur = str_replace([' ', ','], ['', '.'], trim((string) $valeur));

        return is_numeric($valeur) && (float) $valeur > 0 ? (float) $valeur : null;
    }
}
