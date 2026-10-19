<?php

namespace App\Controller;

use App\Entity\CommandesDetails;
use App\Service\Fichiers\ConservationFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Garder les fichiers 1 an » sur une ligne de commande (ConservationFichiers) :
 * sans rien demander, les fichiers sont effacés du serveur quelques mois
 * après l'impression.
 */
final class ConservationFichiersController extends AbstractController
{
    #[Route('/commandes/ligne/{id}/garder-fichiers', name: 'app_commande_ligne_garder_fichiers', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function garder(CommandesDetails $ligne, Request $request, ConservationFichiers $conservation, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('garder-fichiers-'.$ligne->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $maintenant = new \DateTimeImmutable();
        $n = 0;
        foreach ($ligne->getFichiers() as $f) {
            if ($f->isActif() && null === $f->getEffaceLe() && null === $f->getGardeDemandeeLe() && !$conservation->protege($f)) {
                $conservation->garder($f, $this->getUser()?->getUserIdentifier(), $maintenant);
                ++$n;
            }
        }
        $em->flush();
        $this->addFlash('success', 0 === $n ? 'Rien à garder sur cette ligne.' : \sprintf('%d fichier%s gardé%s %d mois après l\'impression.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : '', $conservation->moisGarde()));

        return $this->redirectToRoute('app_commandes_show', ['id' => $ligne->getCommande()?->getId()]);
    }
}
