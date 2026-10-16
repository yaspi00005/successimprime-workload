<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\SuspensionEspace;
use App\Entity\User;
use App\Service\Clients\EditionClient;
use App\Service\EspaceClient\SuspensionsEspace;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche client (ERP local) › Espace client : suspendre l'accès de tout le
 * compte ou d'une personne, avec un motif, et le rétablir. Droit Clients ;
 * refusé en « Voir » (LectureSeuleSubscriber, routes app_clients_*) et sur
 * le site en ligne (SuspensionsEspace::empechement). La suspension descend
 * au site à la prochaine synchronisation.
 */
#[Route('/clients/fiche/{publicId}/espace')]
#[IsGranted('ROLE_CLIENT')]
final class EspaceSuspensionController extends AbstractController
{
    public function __construct(
        private readonly SuspensionsEspace $suspensions,
        private readonly EditionClient $edition,
    ) {
    }

    #[Route('/suspendre', name: 'app_clients_espace_suspendre', methods: ['POST'])]
    public function suspendre(
        #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('espace-suspendre-'.$client->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $qui = $request->getPayload()->getString('qui');
        $telephone = null;
        $nom = null;
        if ('compte' !== $qui) {
            // Une personne : choisie dans la liste (numéro), ou un autre numéro tapé.
            $telephone = 'autre' === $qui ? $request->getPayload()->getString('telephone') : $qui;
            foreach (SuspensionsEspace::personnes($client, $this->edition->contacts($client)) as $p) {
                if ($p['telephone'] === CompteClient::normaliserTelephone($telephone)) {
                    $nom = $p['nom'];
                }
            }
            $nom ??= $request->getPayload()->getString('nom');
            if ('' === trim($telephone)) {
                $this->addFlash('warning', 'Choisissez la personne à suspendre, ou tout le compte.');

                return $this->retour($client);
            }
        }
        try {
            $suspension = $this->suspensions->suspendre($client, $telephone, $nom, $request->getPayload()->getString('motif'), $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf(
                'Accès à l\'espace client suspendu (%s). Le site le reçoit à la prochaine synchronisation : la personne ne pourra plus se connecter et ne recevra plus de notification.',
                SuspensionsEspace::qui($suspension),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->retour($client);
    }

    #[Route('/retablir/{suspension}', name: 'app_clients_espace_retablir', requirements: ['suspension' => '\d+'], methods: ['POST'])]
    public function retablir(
        #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client,
        #[MapEntity(id: 'suspension')] SuspensionEspace $suspension,
        Request $request,
    ): Response {
        if ($suspension->getClient()?->getId() !== $client->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('espace-retablir-'.$suspension->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        try {
            $this->suspensions->retablir($suspension, $this->moi(), $request->getPayload()->getString('note'), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Accès à l\'espace client rétabli (%s) à la prochaine synchronisation.', SuspensionsEspace::qui($suspension)));
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->retour($client);
    }

    private function moi(): ?User
    {
        $moi = $this->getUser();

        return $moi instanceof User ? $moi : null;
    }

    private function retour(Clients $client): Response
    {
        return $this->redirect($this->generateUrl('app_clients_show', ['publicId' => $client->getPublicId()]).'#espace-client');
    }
}
