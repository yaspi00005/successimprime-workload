<?php

namespace App\Controller;

use App\Entity\MouvementTresorerie;
use App\Entity\Reclamation;
use App\Entity\User;
use App\Form\ReclamationPaiementType;
use App\Form\ReclamationType;
use App\Repository\ReclamationRepository;
use App\Service\MouvementTresorerieService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\FileException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reclamations', name: 'app_reclamation_')]
final class ReclamationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ReclamationRepository $reclamationRepository
    ): Response {
        $user = $this->utilisateurConnecte();
        $estAdmin = $this->isGranted('ROLE_ADMIN');
        $peutPayer = $this->isGranted('ROLE_TRESORERIE_SAISIR')
            || $this->isGranted('ROLE_CAISSE_COMMANDE');

        /*
         * Un agent ne voit que ses propres réclamations. Un admin ou
         * une caisse habilitée à payer doit voir toutes les
         * réclamations de tout le monde, sinon il ne peut jamais
         * tomber sur celles des autres agents à payer.
         */
        $reclamations = ($estAdmin || $peutPayer)
            ? $reclamationRepository->findToutes()
            : $reclamationRepository->findPourAgent($user);

        return $this->render('reclamation/index.html.twig', [
            'reclamations' => $reclamations,
            'estAdmin' => $estAdmin,
            'peutPayer' => $peutPayer,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ReclamationRepository $reclamationRepository
    ): Response {
        $user = $this->utilisateurConnecte();

        $reclamation = new Reclamation();
        $reclamation->setAgent($user);

        $form = $this->createForm(ReclamationType::class, $reclamation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $justificatif */
            $justificatif = $form->get('justificatifFichier')->getData();

            if ($justificatif) {
                $nomFichier = uniqid('justificatif_') . '.' . $justificatif->guessExtension();
                $dossier = $this->getParameter('reclamations_directory');

                try {
                    if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                        throw new FileException(sprintf('Impossible de créer le dossier "%s".', $dossier));
                    }

                    $justificatif->move(
                        $dossier,
                        $nomFichier
                    );

                    $reclamation->setJustificatif($nomFichier);
                } catch (FileException $exception) {
                    $this->addFlash('error', 'Le justificatif n’a pas pu être enregistré : ' . $exception->getMessage());

                    return $this->render('reclamation/new.html.twig', [
                        'form' => $form,
                    ]);
                }
            }

            $reclamation->setReference($this->genererReference($reclamationRepository));

            $entityManager->persist($reclamation);
            $entityManager->flush();

            $this->addFlash('success', 'Votre demande de remboursement a été envoyée.');

            return $this->redirectToRoute('app_reclamation_show', ['id' => $reclamation->getId()]);
        }

        return $this->render('reclamation/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Reclamation $reclamation): Response
    {
        $this->verifierAccesReclamation($reclamation);

        return $this->render('reclamation/show.html.twig', [
            'reclamation' => $reclamation,
            'peutValider' => $this->isGranted('ROLE_ADMIN'),
            'peutPayer' => $this->isGranted('ROLE_TRESORERIE_SAISIR')
                || $this->isGranted('ROLE_CAISSE_COMMANDE'),
            'peutVoirTresorerie' => $this->isGranted('ROLE_TRESORERIE_VOIR'),
        ]);
    }

    #[Route('/{id}/valider', name: 'valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function valider(
        Reclamation $reclamation,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid('valider_reclamation_' . $reclamation->getId(), $data['_token'] ?? null)) {
            return $this->json(['success' => false, 'message' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $reclamation->marquerCommeValidee($this->utilisateurConnecte());
        } catch (\LogicException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La réclamation a été validée. La caisse peut maintenant procéder au paiement.',
        ]);
    }

    #[Route('/{id}/refuser', name: 'refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refuser(
        Reclamation $reclamation,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid('refuser_reclamation_' . $reclamation->getId(), $data['_token'] ?? null)) {
            return $this->json(['success' => false, 'message' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN);
        }

        $motif = trim((string) ($data['motif'] ?? ''));

        if ($motif === '') {
            return $this->json(['success' => false, 'message' => 'Le motif de refus est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $reclamation->marquerCommeRefusee($this->utilisateurConnecte(), $motif);
        } catch (\LogicException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La réclamation a été refusée.',
        ]);
    }

    #[Route('/{id}/payer', name: 'payer', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function payer(
        Reclamation $reclamation,
        Request $request,
        EntityManagerInterface $entityManager,
        MouvementTresorerieService $mouvementTresorerieService
    ): Response {
        if (
            !$this->isGranted('ROLE_TRESORERIE_SAISIR')
            && !$this->isGranted('ROLE_CAISSE_COMMANDE')
        ) {
            throw $this->createAccessDeniedException(
                'Vous n’avez pas le droit de payer une réclamation.'
            );
        }

        if (!$reclamation->isValidee()) {
            $this->addFlash('error', 'Seule une réclamation validée peut être payée.');

            return $this->redirectToRoute('app_reclamation_show', ['id' => $reclamation->getId()]);
        }

        $form = $this->createForm(ReclamationPaiementType::class, null, [
            'est_admin' => $this->isGranted('ROLE_ADMIN'),
            'utilisateur' => $this->utilisateurConnecte(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $mouvement = new MouvementTresorerie();
            $mouvement
                ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
                ->setCategorie($form->get('categorie')->getData())
                ->setCompteSource($form->get('compteSource')->getData())
                ->setMontant($reclamation->getMontant())
                ->setModePaiement($form->get('modePaiement')->getData())
                ->setLibelle(sprintf(
                    'Remboursement %s — %s',
                    $reclamation->getReference(),
                    mb_strimwidth($reclamation->getMotif(), 0, 180, '…')
                ))
                ->setAgent($reclamation->getAgent());

            try {
                $mouvementTresorerieService->enregistrer($mouvement);

                $reclamation->marquerCommePayee($this->utilisateurConnecte(), $mouvement);
                $entityManager->flush();
            } catch (\Throwable $exception) {
                $this->addFlash('error', 'Le paiement a échoué : ' . $exception->getMessage());

                return $this->render('reclamation/payer.html.twig', [
                    'reclamation' => $reclamation,
                    'form' => $form,
                ]);
            }

            $this->addFlash('success', sprintf(
                'La réclamation %s a été payée et enregistrée en décaissement (%s).',
                $reclamation->getReference(),
                $mouvement->getReference()
            ));

            return $this->redirectToRoute('app_reclamation_show', ['id' => $reclamation->getId()]);
        }

        return $this->render('reclamation/payer.html.twig', [
            'reclamation' => $reclamation,
            'form' => $form,
        ]);
    }

    private function utilisateurConnecte(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        return $user;
    }

    /**
     * Un agent ne peut voir que ses propres réclamations ; un admin
     * ou un utilisateur habilité à payer (caisse) voit tout.
     */
    private function verifierAccesReclamation(Reclamation $reclamation): void
    {
        if (
            $this->isGranted('ROLE_ADMIN')
            || $this->isGranted('ROLE_TRESORERIE_SAISIR')
            || $this->isGranted('ROLE_CAISSE_COMMANDE')
        ) {
            return;
        }

        if ($reclamation->getAgent() !== $this->utilisateurConnecte()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas consulter cette réclamation.');
        }
    }

    private function genererReference(ReclamationRepository $repository): string
    {
        do {
            $reference = sprintf(
                'REC-%s-%04d',
                (new \DateTimeImmutable())->format('YmdHis'),
                random_int(1, 9999)
            );
        } while ($repository->referenceExiste($reference));

        return $reference;
    }
}
