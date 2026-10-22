<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\PreferencesNotifications;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Notifications de l'espace client (maquettes EC02, EC03, EC05) : le fil
 * de la personne connectée, filtré selon ses droits, l'état lu (le sien),
 * et « Comment je suis prévenu ». L'état lu et les réglages restent sur
 * le site en ligne ; seul l'accord pour les offres monte à la fiche.
 */
#[Route('/espace/notifications', name: 'app_espace_notification')]
final class EspaceNotificationsController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly FilNotifications $fil,
    ) {
    }

    #[Route('', name: 's', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $maintenant = new \DateTimeImmutable();
        $fil = $this->fil->pour($compte, $maintenant);
        $filtre = (string) $request->query->get('filtre', 'toutes');
        $filtre = isset(FilNotifications::FILTRES[$filtre]) ? $filtre : 'toutes';
        $entreprise = CompteClient::clientAPlusieursComptes($client);
        $actifs = array_values(array_filter($this->comptes->findBy(['client' => $client], ['id' => 'ASC']), static fn (CompteClient $c): bool => $c->isActif()));

        return $this->render('espace/app/notifications.html.twig', [
            'filtre' => $filtre,
            'compteurs' => FilNotifications::compter($fil),
            // Puces : seulement les catégories que la personne peut voir.
            'filtres' => array_filter(FilNotifications::FILTRES, static fn (string $libelle, string $cle): bool => 'toutes' === $cle || PreferencesNotifications::permise($compte, $cle), \ARRAY_FILTER_USE_BOTH),
            'groupes' => FilNotifications::parJour('toutes' === $filtre ? $fil : array_values(array_filter($fil, static fn (array $n): bool => $n['categorie'] === $filtre)), $maintenant),
            'preferences' => PreferencesNotifications::de($compte),
            'permises' => PreferencesNotifications::categoriesPermises($compte),
            // « Qui reçoit quoi » : la personne qui gère le compte d'entreprise.
            'quiRecoit' => $entreprise && $compte->peut(CompteClient::DROIT_ADMINISTRATEUR)
                ? array_map(static fn (CompteClient $c): array => ['nom' => $c->getNomAffiche(), 'fonction' => $c->getFonction() ?? $c->getLibelleDroits(), 'resume' => PreferencesNotifications::resume($c), 'moi' => $c->getId() === $compte->getId()], $actifs)
                : [],
            'compte' => $compte,
            'client' => $client,
            'gereLaFiche' => $compte->peut(CompteClient::DROIT_ADMINISTRATEUR),
        ]);
    }

    /** Ouvre une notification : elle est lue, et la page qu'elle concerne s'ouvre. */
    #[Route('/{cle}/ouvrir', name: '_ouvrir', requirements: ['cle' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function ouvrir(string $cle): Response
    {
        $compte = $this->compte();
        $maintenant = new \DateTimeImmutable();
        $n = $this->fil->trouver($compte, $cle, $maintenant);
        if (null === $n) {
            return $this->redirectToRoute('app_espace_notifications');
        }
        $this->fil->marquerLue($compte, $cle, $maintenant);
        // Les droits de la page d'arrivée sont vérifiés par cette page.
        $action = $n['actions'][0] ?? null;

        return $this->redirect(null !== $action && !($action['post'] ?? false) ? $action['url'] : $this->generateUrl($n['lien'][0], $n['lien'][1]));
    }

    #[Route('/{cle}/lue', name: '_lue', requirements: ['cle' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function lue(string $cle, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $maintenant = new \DateTimeImmutable();
        if (null !== $this->fil->trouver($compte, $cle, $maintenant)) {
            $this->fil->marquerLue($compte, $cle, $maintenant);
        }
        if ('fetch' === $request->headers->get('X-Requested-With')) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('app_espace_notifications', array_filter(['filtre' => $request->request->getString('filtre')]));
    }

    #[Route('/tout-lu', name: 's_tout_lu', methods: ['POST'])]
    public function toutLu(Request $request): Response
    {
        $this->jeton($request);
        $this->fil->toutMarquerLu($this->compte(), new \DateTimeImmutable());
        $retour = $request->request->getString('retour');

        // Retour à la page d'où vient la cloche, dans l'espace seulement.
        return preg_match('#^/espace(/[A-Za-z0-9/_-]*)?$#', $retour) ? $this->redirect($request->getBasePath().$retour) : $this->redirectToRoute('app_espace_notifications');
    }

    /** « Comment je suis prévenu » : les réglages de la personne connectée. */
    #[Route('/preferences', name: 's_preferences', methods: ['POST'])]
    public function preferences(Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $client = $compte->getClient();
        $avant = PreferencesNotifications::veutLesOffres($compte);
        $compte->setPreferencesNotifications(PreferencesNotifications::lire($request->request->all()['prefs'] ?? [], $compte));
        $apres = PreferencesNotifications::veutLesOffres($compte);
        $autres = [] !== array_filter(
            $this->comptes->findBy(['client' => $client, 'actif' => true]),
            static fn (CompteClient $c): bool => $c->getId() !== $compte->getId() && PreferencesNotifications::veutLesOffres($c),
        );
        // L'accord pour les offres est celui de la fiche : il monte au local (synchronisation).
        $client->setAccepteMarketing(PreferencesNotifications::accordOffres($avant, $apres, $autres, $client->isAccepteMarketing()));
        $em->flush();
        $this->addFlash('succes', 'Préférences enregistrées.');

        return $this->redirect($this->generateUrl('app_espace_notifications').'#preferences');
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** Le compte frais de la base (celui de la session ne garde pas la fiche client). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
