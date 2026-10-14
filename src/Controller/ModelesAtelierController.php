<?php

namespace App\Controller;

use App\Entity\ModeleAtelier;
use App\Entity\ProduitConfiguration;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\EspaceClient\NouvelleCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Paramètres › Atelier 3D : les articles proposés dans
 * l'atelier (docs/atelier-3d.md). Chacun est un produit du catalogue (son
 * prix public et B2B restent ceux de la grille) avec le modèle 3D qui le
 * montre, ses couleurs, ses tailles et sa zone d'impression. Réglés au
 * local par la direction, ils descendent sur le site ; en ligne, la page
 * se consulte.
 */
#[Route('/reglages/atelier-3d', name: 'app_reglages_parametres_atelier_')]
#[IsGranted('ROLE_ADMIN')]
final class ModelesAtelierController extends AbstractController
{
    private const CSRF = 'modeles-atelier';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueAtelier $catalogue,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('', name: 'modeles', methods: ['GET'])]
    public function modeles(NouvelleCommande $catalogue): Response
    {
        return $this->render('creations_atelier/modeles.html.twig', [
            'modeles' => $this->em->getRepository(ModeleAtelier::class)->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']),
            'proposes' => array_map(static fn (ModeleAtelier $m): int => (int) $m->getId(), $this->catalogue->modeles()),
            'produits' => $catalogue->produits(),
            'textiles' => $this->enLigne ? [] : $this->catalogue->textilesSansModele(),
            'enLigne' => $this->enLigne,
            'jeton' => self::CSRF,
        ]);
    }

    /** Un article par produit textile du catalogue qui n'en a pas (famille d'article « Textile »). */
    #[Route('/textiles', name: 'textiles', methods: ['POST'])]
    public function textiles(Request $request): Response
    {
        $this->verifier($request);
        $n = 0;
        foreach ($this->catalogue->textilesSansModele() as $config) {
            $this->em->persist(ModeleAtelier::pour($config, ModeleAtelier::formeDevinee((string) $config->getProduit()?->getNom()))->setOrdre(10 * ++$n));
        }
        $this->em->flush();
        $this->addFlash('success', 0 === $n ? 'Tous les produits textile ont déjà leur article.' : \sprintf('%d article%s ajouté%s avec les couleurs et tailles du prototype : vérifiez la forme, les couleurs et la zone d\'impression.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : ''));

        return $this->redirectToRoute('app_reglages_parametres_atelier_modeles');
    }

    #[Route('/{id}', name: 'enregistrer', requirements: ['id' => '\d+|nouveau'], methods: ['POST'])]
    public function enregistrer(string $id, Request $request): Response
    {
        $this->verifier($request);
        $r = $request->request;
        $config = $this->em->find(ProduitConfiguration::class, $r->getInt('configuration'));
        if (!$config instanceof ProduitConfiguration) {
            $this->addFlash('error', 'Choisissez le produit du catalogue.');

            return $this->redirectToRoute('app_reglages_parametres_atelier_modeles');
        }
        $modele = 'nouveau' === $id ? ModeleAtelier::pour($config, $r->getString('forme')) : $this->em->find(ModeleAtelier::class, (int) $id);
        if (!$modele instanceof ModeleAtelier) {
            throw $this->createNotFoundException();
        }
        $couleurs = ModeleAtelier::lireCouleurs($r->getString('couleurs'));
        if ([] === $couleurs) {
            $this->addFlash('error', 'Au moins une couleur, une par ligne : « Noir #1F2026 ».');

            return $this->redirectToRoute('app_reglages_parametres_atelier_modeles');
        }
        $centimetres = static fn (string $cle, float $defaut): float => '' === trim($r->getString($cle)) ? $defaut : (float) str_replace(',', '.', $r->getString($cle));
        $modele->setConfiguration($config)
            ->setForme($r->getString('forme'))
            ->setCouleurs($couleurs)
            ->setTailles(ModeleAtelier::CASQUETTE === $modele->getForme() ? [] : ModeleAtelier::lireTailles($r->getString('tailles')))
            ->setZoneLargeurCm($centimetres('zone_largeur', $modele->getZoneLargeurCm()))
            ->setZoneHauteurCm($centimetres('zone_hauteur', $modele->getZoneHauteurCm()))
            ->setActif($r->getBoolean('actif'))
            ->setOrdre(max(0, min(999, $r->getInt('ordre'))));
        $this->em->persist($modele);
        $this->em->flush();
        $this->addFlash('success', \sprintf('Article « %s » enregistré. Il descend sur le site à la prochaine synchronisation.', $config->getProduit()?->getNom()));

        return $this->redirectToRoute('app_reglages_parametres_atelier_modeles');
    }

    private function verifier(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($this->enLigne) {
            throw $this->createAccessDeniedException('Les articles de l\'atelier se règlent au local, puis descendent sur le site.');
        }
    }
}
