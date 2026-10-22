<?php

namespace App\Controller;

use App\Entity\ProduitConfiguration;
use App\Entity\SupportConception;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Paramètres › Conception : les supports proposés dans la
 * demande de conception (forfait du graphiste, acompte, délai du premier
 * BAT, corrections, produits du catalogue qui les impriment). Réglés au
 * local, ils descendent sur le site ; en ligne, la page se consulte.
 */
#[Route('/reglages/conception', name: 'app_reglages_parametres_conception_')]
#[IsGranted('ROLE_ADMIN')]
final class SupportsConceptionController extends AbstractController
{
    private const CSRF = 'supports-conception';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('', name: 'supports', methods: ['GET'])]
    public function supports(NouvelleCommande $catalogue): Response
    {
        return $this->render('demandes_conception/supports.html.twig', [
            'supports' => $this->em->getRepository(SupportConception::class)->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']),
            'produits' => $catalogue->produits(),
            'enLigne' => $this->enLigne,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'enregistrer', requirements: ['id' => '\d+|nouveau'], methods: ['POST'])]
    public function enregistrer(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($this->enLigne) {
            throw $this->createAccessDeniedException('Les supports se règlent au local, puis descendent sur le site.');
        }
        $support = 'nouveau' === $id ? new SupportConception() : $this->em->find(SupportConception::class, (int) $id);
        if (!$support instanceof SupportConception) {
            throw $this->createNotFoundException();
        }
        $r = $request->request;
        $nom = trim($r->getString('nom'));
        $code = mb_substr(trim((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(trim($r->getString('code')) ?: $nom)), '_'), 0, 30);
        if ('' === $nom || '' === $code) {
            $this->addFlash('error', 'Nom du support attendu.');

            return $this->redirectToRoute('app_reglages_parametres_conception_supports');
        }
        $doublon = $this->em->getRepository(SupportConception::class)->findOneBy(['code' => $code]);
        if (null !== $doublon && $doublon !== $support) {
            $this->addFlash('error', \sprintf('Le code « %s » est déjà pris.', $code));

            return $this->redirectToRoute('app_reglages_parametres_conception_supports');
        }
        $centimetres = static fn (string $cle): ?float => '' === trim($r->getString($cle)) ? null : max(0.5, min(5000.0, (float) str_replace(',', '.', $r->getString($cle))));
        $support->setCode(mb_substr($code, 0, 30))
            ->setNom(mb_substr($nom, 0, 80))
            ->setCourt(mb_substr(trim($r->getString('court')) ?: $nom, 0, 30))
            ->setFormat(mb_substr(trim($r->getString('format')), 0, 60))
            ->setLargeurCm($centimetres('largeur'))
            ->setHauteurCm($centimetres('hauteur'))
            ->setForfait(max(0, min(10_000_000, Entree::entier($r, 'forfait'))))
            ->setAcomptePourcent(max(0, min(100, Entree::entier($r, 'acompte'))))
            ->setDelaiBatHeures(max(1, min(720, Entree::entier($r, 'delai') ?: 24)))
            ->setCorrectionsIncluses(max(0, min(20, Entree::entier($r, 'corrections'))))
            ->setCouleur(preg_match('/^#[0-9A-Fa-f]{6}$/', $r->getString('couleur')) ? strtoupper($r->getString('couleur')) : '#9CA3AF')
            ->setActif($r->getBoolean('actif'))
            ->setOrdre(max(0, min(999, Entree::entier($r, 'ordre'))));
        $choisis = array_map('intval', $r->all('produits'));
        foreach ($support->getConfigurations()->toArray() as $c) {
            if (!\in_array($c->getId(), $choisis, true)) {
                $support->removeConfiguration($c);
            }
        }
        foreach ($choisis as $idConfig) {
            $config = $this->em->find(ProduitConfiguration::class, $idConfig);
            if ($config instanceof ProduitConfiguration) {
                $support->addConfiguration($config);
            }
        }
        $this->em->persist($support);
        $this->em->flush();
        $this->addFlash('success', \sprintf('Support « %s » enregistré. Il descend sur le site à la prochaine synchronisation.', $support->getNom()));

        return $this->redirectToRoute('app_reglages_parametres_conception_supports');
    }
}
