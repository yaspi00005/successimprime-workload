<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\DepotSoldeClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\TransactionOrangeMoney;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Paiement\AvoirClient;
use App\Service\Paiement\OrangeMoney;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client › Mon solde (docs/espace-client.md, « Mon solde ») :
 * l'argent que le client a laissé à la boutique (monnaie gardée, dépôts),
 * son relevé, et un dépôt par Orange Money.
 *
 * Réservé à qui paie (particulier, ou payeur d'une entreprise), comme
 * « Factures et paiements ». Le solde et le relevé viennent du local en
 * lecture seule : le site ne les change jamais. Un dépôt en ligne arrive
 * « reçu, en attente » ; la boutique le valide après avoir vu l'argent, et
 * c'est seulement alors que le solde monte. Les frais Orange Money sont
 * payés en plus et ne vont pas au solde.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceSoldeController extends AbstractController
{
    /** Lignes du relevé montrées. */
    private const LIGNES = 60;

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Solde, relevé, dépôts en ligne, et le récapitulatif d'un dépôt (montant choisi, frais compris). */
    #[Route('/solde', name: 'solde', methods: ['GET'])]
    public function index(Request $request, AvoirClient $avoirs, OrangeMoney $orangeMoney, EspaceClient $espace): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $maintenant = new \DateTimeImmutable();
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Dépôt annulé sur la page Orange Money : rien n\'a été débité.');

            return $this->redirectToRoute('app_espace_solde');
        }

        $saisi = $request->query->has('montant') ? EspaceFacturesController::montant($request->query->getString('montant')) : null;
        $erreur = null;
        if ($request->query->has('montant')) {
            $erreur = self::erreurMontant($saisi);
        }
        $frais = null !== $saisi && null === $erreur ? $orangeMoney->frais($saisi) : null;

        return $this->render('espace/solde/index.html.twig', [
            'moi' => $client,
            'peutPayer' => true,
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'avoir' => $avoirs->etat($client, $maintenant),
            'releve' => $this->em->getRepository(MouvementSoldeClient::class)->findBy(['client' => $client], ['creeLe' => 'DESC', 'id' => 'DESC'], self::LIGNES),
            'depots' => $this->depots($compte, $maintenant),
            'montantSaisi' => $saisi,
            'erreur' => $erreur,
            'frais' => $frais,
            'minimum' => OrangeMoney::DEPOT_MINIMUM,
            'maximum' => OrangeMoney::DEPOT_MAXIMUM,
            'omActif' => $orangeMoney->estConfigure(),
            'marchand' => $espace->marchandOrangeMoney(),
        ]);
    }

    /**
     * « Continuer vers Orange Money » : montant et frais recalculés ici ; si
     * le total a changé depuis le récapitulatif, le client le revoit.
     */
    #[Route('/solde/deposer', name: 'solde_deposer', methods: ['POST'])]
    public function deposer(
        Request $request,
        OrangeMoney $orangeMoney,
        #[Autowire(service: 'limiter.espace_paiement_compte')] RateLimiterFactoryInterface $limite,
    ): Response {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->payeur();
        $montant = EspaceFacturesController::montant($request->request->getString('montant'));
        if (null !== ($erreur = self::erreurMontant($montant))) {
            $this->addFlash('error', $erreur);

            return $this->redirectToRoute('app_espace_solde', [], Response::HTTP_SEE_OTHER);
        }
        $frais = $orangeMoney->frais((int) $montant);
        if ((string) $frais->total() !== $request->request->getString('total')) {
            $this->addFlash('error', 'Les frais ont changé : vérifiez le récapitulatif avant de déposer.');

            return $this->redirectToRoute('app_espace_solde', ['montant' => $montant], Response::HTTP_SEE_OTHER);
        }
        if (!$limite->create('compte-'.$compte->getId())->consume()->isAccepted()) {
            $this->addFlash('error', 'Vous avez lancé beaucoup de paiements. Réessayez dans quelques minutes, ou déposez en boutique.');

            return $this->redirectToRoute('app_espace_solde', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $transaction = $orangeMoney->demarrerDepot($compte->getClient(), $compte, (int) $montant, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_solde', ['montant' => $montant], Response::HTTP_SEE_OTHER);
        }

        return $this->redirect((string) $transaction->getLienPaiement());
    }

    /**
     * Retour de la page Orange Money : l'état est relu chez Orange sans
     * attendre sa notification. Le dépôt d'un autre client : introuvable.
     */
    #[Route('/solde/depot/{reference}', name: 'solde_retour', requirements: ['reference' => '[A-Z0-9]{8,40}'], methods: ['GET'])]
    public function retour(string $reference, OrangeMoney $orangeMoney): Response
    {
        $compte = $this->payeur();
        $depot = $this->em->getRepository(DepotSoldeClient::class)->findOneBy(['reference' => $reference, 'client' => $compte->getClient()])
            ?? throw $this->createNotFoundException();
        $transaction = $this->em->getRepository(TransactionOrangeMoney::class)->findOneBy(['depot' => $depot]);
        if (null !== $transaction && DepotSoldeClient::INITIE === $depot->getStatut()) {
            $orangeMoney->verifier($transaction, new \DateTimeImmutable());
            $this->em->refresh($depot);
        }
        // Pas de montant dans les messages : il est dans la liste des dépôts, juste en dessous.
        match ($depot->getStatut()) {
            DepotSoldeClient::RECU => $this->addFlash('succes', 'Dépôt reçu par Orange Money. La boutique le vérifie, puis il s\'ajoute à votre solde.'),
            DepotSoldeClient::VALIDE => $this->addFlash('succes', 'Dépôt ajouté à votre solde.'),
            DepotSoldeClient::ECHOUE => $this->addFlash('error', 'Le dépôt n\'a pas abouti chez Orange Money : rien n\'a été débité.'),
            default => $this->addFlash('succes', 'Dépôt en cours chez Orange Money : il apparaît ici dès qu\'Orange le confirme.'),
        };

        return $this->redirect($this->generateUrl('app_espace_solde').'#depots');
    }

    /** Montant d'un dépôt : null si correct, sinon la raison à montrer. */
    public static function erreurMontant(?int $montant): ?string
    {
        if (null === $montant || $montant <= 0) {
            return 'Indiquez le montant à déposer.';
        }
        if ($montant < OrangeMoney::DEPOT_MINIMUM || $montant > OrangeMoney::DEPOT_MAXIMUM) {
            return \sprintf('Un dépôt va de %s à %s F.', number_format(OrangeMoney::DEPOT_MINIMUM, 0, ',', ' '), number_format(OrangeMoney::DEPOT_MAXIMUM, 0, ',', ' '));
        }

        return null;
    }

    /**
     * Dépôts en ligne du client : reçus, validés ou refusés, et ceux encore
     * ouverts chez Orange. Un départ abandonné (lien expiré) ne s'affiche pas.
     *
     * @return list<DepotSoldeClient>
     */
    private function depots(CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $limite = $maintenant->modify(\sprintf('-%d minutes', TransactionOrangeMoney::VALIDITE_MINUTES));

        return array_values(array_filter(
            $this->em->getRepository(DepotSoldeClient::class)->findBy(['client' => $compte->getClient()], ['creeLe' => 'DESC', 'id' => 'DESC'], 20),
            static fn (DepotSoldeClient $d): bool => DepotSoldeClient::ECHOUE !== $d->getStatut() && (DepotSoldeClient::INITIE !== $d->getStatut() || $d->getCreeLe() > $limite),
        ));
    }

    /** Comme « Factures et paiements » : un particulier, le titulaire ou le payeur d'une entreprise. */
    private function payeur(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }
        if (!EspaceFacturesController::peutVoirLesFactures($compte)) {
            throw $this->createAccessDeniedException('Le solde est réservé aux personnes qui paient pour l\'entreprise.');
        }

        return $compte;
    }
}
