<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\CompteTresorerie;
use App\Entity\DepotSoldeClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use App\Repository\ParametresPaiementRepository;
use App\Security\AccesEnLigne;
use App\Service\DocumentsEntreprise;
use App\Service\Http\Entree;
use App\Service\NotificationService;
use App\Service\Paiement\FraisOrangeMoney;
use App\Service\Paiement\SoldeClient;
use App\Entity\Factures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Compte du client à l'imprimerie (docs/finances.md, « Compte du client ») :
 * dépôt sans rien payer, argent rendu, dépôts Orange Money de l'espace
 * client à valider, et le reçu de chaque dépôt ou remboursement.
 *
 * Depuis l'onglet « Compte » de la fiche client et la caisse. Dépôt et
 * validation : qui encaisse ; argent rendu : le droit « Rendre l'argent du
 * compte d'un client » (Direction par défaut), avec une confirmation. Le
 * solde est tenu au local : rien de tout cela ne se fait en ligne.
 */
#[Route('/caisse/solde-client', name: 'app_caisse_solde_')]
#[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
final class SoldeClientController extends AbstractController
{
    public function __construct(
        private readonly SoldeClient $soldes,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('/{id}/depot', name: 'depot', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deposer(Clients $client, Request $request, CompteTresorerieRepository $comptes, ParametresPaiementRepository $parametres, NotificationService $notifications, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request, 'solde-depot-'.$client->getId());
        if (null !== ($refus = $this->refusEnLigne($request, $client))) {
            return $refus;
        }
        $par = $this->utilisateur();
        $montant = self::francs($request->request->getString('montant'));
        $mode = $request->request->getString('mode', Paiements::MODE_ESPECES);
        $compte = $this->compteAutorise($comptes, Entree::entier($request->request, 'compte'));
        $frais = \in_array($mode, FraisOrangeMoney::MODES, true) && $request->request->getBoolean('frais')
            ? FraisOrangeMoney::calculer($montant, $parametres->recuperer())->frais()
            : 0;

        try {
            if (null === $compte) {
                throw new \DomainException('Choisissez le compte qui reçoit l\'argent.');
            }
            $ligne = $this->soldes->deposer($client, $montant, $mode, $compte, $request->request->getString('reference'), $frais, $par, $request->request->getString('note'));
        } catch (\DomainException|\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->retour($request, $client);
        }
        $notifications->notifierRoles(['ROLE_ADMIN'], sprintf('Dépôt %s sur le compte de %s.', $ligne->getReference(), $client->getNomComplet()), 'app_clients_show', ['publicId' => $client->getPublicId()], $par);
        $em->flush();
        $this->addFlash('success', sprintf('Dépôt de %s enregistré sur le compte du client (reçu %s).', self::f($montant), $ligne->getReference()));

        return $this->retour($request, $client, $ligne);
    }

    #[Route('/{id}/rembourser', name: 'rembourser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SOLDE_CLIENT_REMBOURSER')]
    public function rembourser(Clients $client, Request $request, CompteTresorerieRepository $comptes, NotificationService $notifications, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request, 'solde-rembourser-'.$client->getId());
        if (null !== ($refus = $this->refusEnLigne($request, $client))) {
            return $refus;
        }
        // Confirmation : la case « J'ai rendu l'argent au client » doit être cochée.
        if (!$request->request->getBoolean('confirme')) {
            $this->addFlash('warning', 'Cochez la confirmation avant de rendre l\'argent.');

            return $this->retour($request, $client);
        }
        $par = $this->utilisateur();
        $montant = self::francs($request->request->getString('montant'));
        $compte = $this->compteAutorise($comptes, Entree::entier($request->request, 'compte'));

        try {
            if (null === $compte) {
                throw new \DomainException('Choisissez le compte d\'où sort l\'argent.');
            }
            $ligne = $this->soldes->rembourser($client, $montant, $compte, $request->request->getString('motif'), $par);
        } catch (\DomainException|\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->retour($request, $client);
        }
        // Pas de montant dans la notification : il est sur la fiche, pour qui a le droit.
        $notifications->notifierRoles(['ROLE_ADMIN'], sprintf('Argent rendu à %s depuis son compte client (%s, par %s).', $client->getNomComplet(), $ligne->getReference(), $par->getUserIdentifier()), 'app_clients_show', ['publicId' => $client->getPublicId()], $par);
        $em->flush();
        $this->addFlash('success', sprintf('%s rendus au client (reçu %s).', self::f($montant), $ligne->getReference()));

        return $this->retour($request, $client, $ligne);
    }

    /** Dépôt Orange Money de l'espace client : la caisse a vu l'argent sur le compte « Orange Money en ligne ». */
    #[Route('/depot-en-ligne/{id}/valider', name: 'depot_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validerDepot(DepotSoldeClient $depot, Request $request): Response
    {
        $client = $depot->getClient() ?? throw $this->createNotFoundException();
        $this->verifierJeton($request, 'solde-depot-en-ligne-'.$depot->getId());
        if (null !== ($refus = $this->refusEnLigne($request, $client))) {
            return $refus;
        }
        try {
            $this->soldes->validerDepot($depot, $this->utilisateur());
            $this->addFlash('success', sprintf('Dépôt Orange Money de %s ajouté au solde du client.', self::f($depot->getMontant())));
        } catch (\DomainException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->retour($request, $client);
    }

    #[Route('/depot-en-ligne/{id}/rejeter', name: 'depot_rejeter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejeterDepot(DepotSoldeClient $depot, Request $request): Response
    {
        $client = $depot->getClient() ?? throw $this->createNotFoundException();
        $this->verifierJeton($request, 'solde-depot-en-ligne-'.$depot->getId());
        if (null !== ($refus = $this->refusEnLigne($request, $client))) {
            return $refus;
        }
        try {
            $this->soldes->rejeterDepot($depot, $request->request->getString('motif'), $this->utilisateur());
            $this->addFlash('success', 'Dépôt refusé : le solde du client ne change pas.');
        } catch (\DomainException|\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->retour($request, $client);
    }

    /** Reçu d'un dépôt ou d'un remboursement (ticket 80 mm), pour qui voit les paiements ou l'a fait. */
    #[Route('/recu/{id}', name: 'recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(MouvementSoldeClient $ligne, DocumentsEntreprise $documents): Response
    {
        if (!$this->isGranted('ROLE_PAIEMENT_VOIR') && $ligne->getPar() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        if (!\in_array($ligne->getType(), [MouvementSoldeClient::DEPOT, MouvementSoldeClient::REMBOURSEMENT], true)) {
            throw $this->createNotFoundException();
        }
        $entreprise = $documents->entreprise(Factures::EMETTEUR_MDG_SUCCESS, Factures::EMETTEURS_LABELS[Factures::EMETTEUR_MDG_SUCCESS]);

        return $this->render('caisse/recu_solde.html.twig', [
            'entreprise' => $entreprise,
            'images' => $documents->images($entreprise),
            'ligne' => $ligne,
        ]);
    }

    private function retour(Request $request, Clients $client, ?MouvementSoldeClient $recu = null): Response
    {
        if ('caisse' === $request->request->getString('retour')) {
            return $this->redirectToRoute('app_caisse_index', array_filter(['client' => $client->getId(), 'recu_solde' => $recu?->getId()]));
        }

        return $this->redirect($this->generateUrl('app_clients_show', array_filter(['publicId' => $client->getPublicId(), 'recu_solde' => $recu?->getId()])).'#compte');
    }

    /** Le solde est tenu au local : le site en ligne ne le change pas. */
    private function refusEnLigne(Request $request, Clients $client): ?Response
    {
        if (!$this->acces->estEnLigne()) {
            return null;
        }
        $this->addFlash('warning', 'Le compte du client se gère à l\'imprimerie, pas en ligne.');

        return $this->retour($request, $client);
    }

    /** Un compte que la personne peut utiliser (comme à la caisse), sauf le compte des paiements en ligne. */
    private function compteAutorise(CompteTresorerieRepository $comptes, int $id): ?CompteTresorerie
    {
        foreach ($comptes->trouverDisponiblesPour($this->utilisateur(), $this->isGranted('ROLE_ADMIN')) as $compte) {
            if ($compte->getId() === $id && !$compte->isOrangeMoneyEnLigne()) {
                return $compte;
            }
        }

        return null;
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function utilisateur(): User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : throw $this->createAccessDeniedException();
    }

    private static function francs(string $saisi): int
    {
        return (int) preg_replace('/\D/', '', $saisi);
    }

    private static function f(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' F';
    }
}
