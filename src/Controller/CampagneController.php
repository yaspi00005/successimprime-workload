<?php

namespace App\Controller;

use App\Entity\Campagne;
use App\Entity\CampagneDestinataire;
use App\Entity\ModeleMessage;
use App\Entity\User;
use App\Repository\CampagneDestinataireRepository;
use App\Repository\CampagneRepository;
use App\Repository\ClientsRepository;
use App\Repository\ModeleMessageRepository;
use App\Service\CampagneService;
use App\Service\ResultatsCampagnes;
use App\Service\SegmentsClients;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Campagnes de communication (promo, vœux de fêtes...) envoyées en
 * masse à une sélection de clients. L'envoi réel est étalé dans le
 * temps par CampagneService, via la commande app:envoyer-campagnes
 * (tâche planifiée) -- créer une campagne ici ne fait que la mettre
 * en file d'attente.
 */
#[Route('/communications/campagnes', name: 'app_campagne_')]
#[IsGranted('ROLE_ADMIN')]
final class CampagneController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CampagneRepository $campagneRepository,
        SegmentsClients $segments,
        ResultatsCampagnes $resultats,
        WhatsAppService $whatsApp
    ): Response {
        $campagnes = \array_slice($campagneRepository->findToutes(), 0, 12);
        $parCampagne = $resultats->parCampagne($campagnes);
        $comptes = [];
        foreach ($segments->parSegment(new \DateTimeImmutable()) as $cle => $clients) {
            foreach (array_keys(ModeleMessage::CANAUX_LABELS) as $canal) {
                foreach (array_keys(SegmentsClients::CONSENTEMENTS) as $consentement) {
                    $comptes[$cle][$canal][$consentement] = \count(SegmentsClients::joignables($clients, $canal, $consentement));
                }
            }
        }

        return $this->render('campagne/index.html.twig', [
            'campagnes' => $campagnes,
            'resultats' => $parCampagne,
            'meilleure' => ResultatsCampagnes::meilleure($parCampagne),
            'comptes' => $comptes,
            'whatsapp_api' => $whatsApp->estConfigure(),
        ]);
    }

    /**
     * Nouvelle campagne de la maquette 35 : un segment, un canal, un
     * texte, une date d'envoi et le consentement exigé.
     */
    #[Route('/programmer', name: 'programmer', methods: ['POST'])]
    public function programmer(Request $request, SegmentsClients $segments, CampagneService $campagneService): Response
    {
        if (!$this->isCsrfTokenValid('campagne_programmer', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $p = $request->getPayload();
        $nom = mb_substr(trim($p->getString('nom')), 0, 255);
        $segment = $p->getString('segment');
        $canal = $p->getString('canal');
        $consentement = $p->getString('consentement');
        $message = trim($p->getString('message'));
        $envoi = trim($p->getString('envoi'));
        $date = '' === $envoi ? null : \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $envoi);

        $erreur = match (true) {
            '' === $nom => 'Donnez un nom à la campagne.',
            !isset(SegmentsClients::SEGMENTS[$segment]) => 'Choisissez un segment.',
            !isset(ModeleMessage::CANAUX_LABELS[$canal]) => 'Choisissez un canal.',
            !isset(SegmentsClients::CONSENTEMENTS[$consentement]) => 'Choisissez le consentement exigé.',
            '' === $message => 'Écrivez le message.',
            false === $date => 'La date d’envoi n’est pas valide.',
            default => null,
        };
        $clients = null === $erreur ? SegmentsClients::joignables($segments->parSegment(new \DateTimeImmutable())[$segment], $canal, $consentement) : [];
        if (null === $erreur && [] === $clients) {
            $erreur = 'Aucun client joignable dans ce segment avec ce consentement.';
        }
        if (null !== $erreur) {
            $this->addFlash('warning', $erreur);

            return $this->redirectToRoute('app_campagne_index');
        }

        $utilisateur = $this->getUser();
        $campagne = $campagneService->programmer(
            $nom,
            $canal,
            $message,
            $segment,
            null !== $date && $date > new \DateTimeImmutable() ? $date : null,
            $clients,
            $utilisateur instanceof User ? $utilisateur : null
        );
        $this->addFlash('success', sprintf(
            '« %s » : %d destinataire%s, envoi %s.',
            $campagne->getNom(),
            \count($clients),
            \count($clients) > 1 ? 's' : '',
            null === $campagne->getEnvoiPrevuLe() ? 'en cours' : 'le '.$campagne->getEnvoiPrevuLe()->format('d/m à H\hi')
        ));

        return $this->redirectToRoute('app_campagne_show', ['id' => $campagne->getId()]);
    }

    /**
     * Sans l'API WhatsApp : l'agent ouvre le lien wa.me puis marque le
     * message comme envoyé.
     */
    #[Route('/{id}/envoye/{destinataire}', name: 'marquer_envoye', requirements: ['id' => '\d+', 'destinataire' => '\d+'], methods: ['POST'])]
    public function marquerEnvoye(int $id, int $destinataire, Request $request, CampagneDestinataireRepository $destinataires, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('campagne_manuel', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $d = $destinataires->find($destinataire);
        $campagne = $d?->getCampagne();
        if (null === $d || null === $campagne || $campagne->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        if (CampagneDestinataire::STATUT_ENVOYE !== $d->getStatut()) {
            $d->setStatut(CampagneDestinataire::STATUT_ENVOYE)->setErreur(null)->setDateEnvoi(new \DateTimeImmutable());
            $em->flush();
            if (0 === $destinataires->compteEnAttente($campagne)) {
                $campagne->setStatut(Campagne::STATUT_TERMINEE);
                $em->flush();
            }
        }
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true]);
        }

        return $this->redirectToRoute('app_campagne_show', ['id' => $id]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ModeleMessageRepository $modeleMessageRepository,
        ClientsRepository $clientsRepository,
        CampagneService $campagneService
    ): Response {
        $modeles = $modeleMessageRepository->findTous();
        $clients = $clientsRepository->findActifsPourCampagne();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('campagne_new', $request->getPayload()->getString('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide, merci de réessayer.');

                return $this->redirectToRoute('app_campagne_new');
            }

            $nom = trim($request->getPayload()->getString('nom'));
            $modeleId = $request->getPayload()->getInt('modele');
            $clientIds = array_map('intval', $request->getPayload()->all('clients'));
            $senderName = trim($request->getPayload()->getString('senderName'));

            $modele = $modeleMessageRepository->find($modeleId);

            $erreurs = [];

            if ($nom === '') {
                $erreurs[] = 'Le nom de la campagne est obligatoire.';
            }

            if ($modele === null || !$modele->isActif()) {
                $erreurs[] = 'Veuillez sélectionner un modèle de message valide.';
            }

            if ($clientIds === []) {
                $erreurs[] = 'Sélectionnez au moins un client.';
            }

            if ($erreurs === []) {
                $clientsSelectionnes = array_values(array_filter(
                    array_map(
                        static fn (int $id) => $clientsRepository->find($id),
                        $clientIds
                    )
                ));

                $utilisateur = $this->getUser();

                $campagne = $campagneService->creerEtLancer(
                    $nom,
                    $modele,
                    $clientsSelectionnes,
                    $utilisateur instanceof User ? $utilisateur : null,
                    $senderName !== '' ? $senderName : null
                );

                $this->addFlash(
                    'success',
                    sprintf(
                        'La campagne « %s » a été créée pour %d client(s). L’envoi se fait progressivement en arrière-plan.',
                        $campagne->getNom(),
                        count($clientsSelectionnes)
                    )
                );

                return $this->redirectToRoute('app_campagne_show', ['id' => $campagne->getId()]);
            }

            foreach ($erreurs as $erreur) {
                $this->addFlash('danger', $erreur);
            }
        }

        return $this->render('campagne/new.html.twig', [
            'modeles' => $modeles,
            'clients' => $clients,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        CampagneRepository $campagneRepository,
        CampagneDestinataireRepository $destinataireRepository,
        ResultatsCampagnes $resultats,
        WhatsAppService $whatsApp
    ): Response {
        $campagne = $campagneRepository->find($id);

        if ($campagne === null) {
            throw $this->createNotFoundException('Campagne introuvable.');
        }

        return $this->render('campagne/show.html.twig', [
            'campagne' => $campagne,
            'destinataires' => $destinataireRepository->findParCampagne($campagne),
            'compteurs' => $destinataireRepository->compterParStatut($campagne),
            'resultat' => $resultats->parCampagne([$campagne])[$id],
            'envoi_manuel' => ModeleMessage::CANAL_WHATSAPP === $campagne->getCanal() && !$whatsApp->estConfigure(),
        ]);
    }
}
