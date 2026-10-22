<?php

namespace App\Controller;

use App\Entity\JetonApi;
use App\Entity\User;
use App\Repository\JetonApiRepository;
use App\Repository\UserRepository;
use App\Security\AccesEnLigne;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Sécurité › Accès API (docs/api.md) : jetons qui permettent à
 * un logiciel (tableur, outil de rapports, application) de lire l'ERP au
 * nom d'une personne, avec ses droits. Le jeton n'est montré qu'une fois.
 */
#[Route('/reglages/securite/api', name: 'app_jetons_api_')]
#[IsGranted('ROLE_ADMIN')]
final class JetonsApiController extends AbstractController
{
    public function __construct(
        private readonly JetonApiRepository $jetons,
        private readonly AccesEnLigne $acces,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, UserRepository $utilisateurs): Response
    {
        $nouveau = $request->getSession()->getFlashBag()->get('jeton_api')[0] ?? null;

        return $this->render('jetons_api/index.html.twig', [
            'jetons' => $this->jetons->pourLaListe(),
            'utilisateurs' => $utilisateurs->findBy(['actif' => true], ['username' => 'ASC']),
            'durees' => JetonApi::DUREES,
            'nouveau' => \is_array($nouveau) ? $nouveau : null,
            'en_ligne' => $this->acces->estEnLigne(),
            'docs' => $this->generateUrl('api_doc', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/creer', name: 'creer', methods: ['POST'])]
    public function creer(Request $request, UserRepository $utilisateurs): Response
    {
        if (!$this->isCsrfTokenValid('jeton-api-creer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $utilisateur = $utilisateurs->find(Entree::entier($request->request, 'utilisateur'));
        $nom = trim((string) $request->request->get('nom'));
        $jours = Entree::entier($request->request, 'duree', 90);
        $auteur = $this->getUser();

        if ($this->acces->estEnLigne()) {
            $this->addFlash('error', 'Les jetons se créent sur l\'ERP local : l\'API n\'est pas ouverte en ligne.');

            return $this->redirectToRoute('app_jetons_api_index');
        }
        if (!$utilisateur instanceof User || !$utilisateur->isActif() || '' === $nom || !\array_key_exists($jours, JetonApi::DUREES)) {
            $this->addFlash('error', 'Choisissez la personne, la durée et donnez un nom au jeton (l\'outil qui l\'utilisera).');

            return $this->redirectToRoute('app_jetons_api_index');
        }

        [$jeton, $texte] = JetonApi::creer($utilisateur, $nom, $auteur instanceof User ? $auteur : null, $jours, new \DateTimeImmutable());
        $this->em->persist($jeton);
        $this->em->flush();
        $this->addFlash('jeton_api', ['texte' => $texte, 'nom' => $jeton->getNom(), 'utilisateur' => $utilisateur->getUserIdentifier()]);

        return $this->redirectToRoute('app_jetons_api_index', ['_fragment' => 'nouveau']);
    }

    #[Route('/{id}/revoquer', name: 'revoquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoquer(Request $request, JetonApi $jeton): Response
    {
        if (!$this->isCsrfTokenValid('jeton-api-'.$jeton->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if (null === $jeton->getRevoqueLe()) {
            $jeton->revoquer(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('Jeton « %s » révoqué : il ne donne plus accès à l\'API.', $jeton->getNom()));
        }

        return $this->redirectToRoute('app_jetons_api_index');
    }
}
