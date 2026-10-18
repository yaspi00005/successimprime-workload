<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\MessageClient;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\MessagesClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client (maquette EC01) : recherche de la barre du haut,
 * Livraisons et Messages. Toujours limité à la fiche de la personne
 * connectée et à ses droits.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceSuiviController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
    ) {
    }

    /** Recherche dans ses commandes, devis, fichiers et (payeur) factures. */
    #[Route('/recherche', name: 'recherche', methods: ['GET'])]
    public function recherche(Request $request): Response
    {
        $compte = $this->compte();
        $texte = trim(mb_substr($request->query->getString('q'), 0, 60));
        $resultats = $this->espace->rechercher($compte, $texte);

        return $this->render('espace/app/recherche.html.twig', [
            'texte' => $texte,
            'resultats' => $resultats,
            'total' => array_sum(array_map('count', $resultats)),
        ]);
    }

    /**
     * Livraisons : en route, à venir, livrées, d'après les bons de
     * livraison synchronisés (jour, créneau, heures ; ni livreur, ni
     * réceptionnaire, ni photos, ni signature).
     */
    #[Route('/livraisons', name: 'livraisons', methods: ['GET'])]
    public function livraisons(): Response
    {
        return $this->render('espace/app/livraisons.html.twig', [
            'livraisons' => $this->espace->livraisons($this->compte()->getClient()),
        ]);
    }

    #[Route('/messages', name: 'messages', methods: ['GET', 'POST'])]
    public function messages(
        Request $request,
        MessagesClient $messages,
        EntrepriseRepository $entreprises,
        #[Autowire(service: 'limiter.espace_message_compte')] RateLimiterFactoryInterface $limite,
    ): Response {
        $compte = $this->compte();
        $client = $compte->getClient();
        // « Message » d'une facture (Factures et paiements) : le texte arrive déjà commencé, avec sa référence.
        $saisie = ['texte' => mb_substr($request->query->getString('texte'), 0, 300), 'commande' => ''];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $saisie = ['texte' => mb_substr($request->request->getString('texte'), 0, MessageClient::TEXTE_MAX + 200), 'commande' => $request->request->getString('commande')];
            $commande = '' !== $saisie['commande'] ? $this->espace->commande($client, $saisie['commande']) : null;
            // Écrit depuis « Messages de la commande » (page de la commande) : on y revient.
            $retour = 'commande' === $request->request->get('retour') && null !== $commande
                ? $this->generateUrl('app_espace_commande', ['id' => (string) $commande->getPublicId()]).'#messages'
                : null;
            try {
                if ('' !== $saisie['commande'] && null === $commande) {
                    throw new \DomainException('Choisissez une de vos commandes.');
                }
                if (!$limite->create('compte-'.$compte->getId())->consume()->isAccepted()) {
                    throw new \DomainException('Vous avez envoyé beaucoup de messages. Réessayez dans un moment, ou appelez-nous.');
                }
                $messages->ecrire($compte, $saisie['texte'], $commande, new \DateTimeImmutable());
                $this->addFlash('succes', 'Message envoyé. L\'atelier vous répond ici ; vous êtes prévenu de sa réponse.');

                return $this->redirect($retour ?? $this->generateUrl('app_espace_messages').'#dernier');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                if (null !== $retour) {
                    return $this->redirect($retour);
                }
            }
        }
        $telephone = null;
        foreach ($entreprises->dansLOrdre() as $e) {
            if ('' !== trim((string) $e->getTelephone())) {
                $telephone = UtilisateursController::numeroWhatsApp((string) $e->getTelephone());
                break;
            }
        }

        return $this->render('espace/app/messages.html.twig', [
            'fil' => $messages->fil($client),
            'commandes' => \array_slice($this->espace->commandes($client, 30), 0, 30),
            'saisie' => $saisie,
            'choisie' => $request->query->getString('commande') ?: $saisie['commande'],
            'whatsapp' => null !== $telephone ? 'https://wa.me/'.$telephone : null,
            'appeler' => null !== $telephone ? 'tel:+'.$telephone : null,
            'max' => MessageClient::TEXTE_MAX,
        ]);
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
