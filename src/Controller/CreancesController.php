<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\ModeleMessage;
use App\Entity\RelanceCreance;
use App\Entity\User;
use App\Service\Creances;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Factures et créances (maquette 30) : balance âgée, liste
 * des restes dus et relance groupée des clients par WhatsApp.
 */
#[Route('/finances/creances', name: 'app_creances_')]
#[IsGranted('ROLE_FACTURE')]
final class CreancesController extends AbstractController
{
    public function __construct(private readonly Creances $creances)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtre = (string) $request->query->get('filtre', 'ouvertes');
        if (!isset(Creances::FILTRES[$filtre])) {
            $filtre = 'ouvertes';
        }
        $recherche = mb_substr(trim((string) $request->query->get('q')), 0, 80);

        return $this->render('creances/index.html.twig', $this->creances->donnees(new \DateTimeImmutable(), $filtre, $recherche) + [
            'filtre' => $filtre,
            'recherche' => $recherche,
        ]);
    }

    /**
     * Enregistre la relance de chaque commande cochée et prépare le
     * message WhatsApp de chaque client (un lien par client).
     */
    #[Route('/relancer', name: 'relancer', methods: ['POST'])]
    public function relancer(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('relancer-creances', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $ids = array_values(array_unique(array_map('intval', (array) $request->request->all('commandes'))));
        if ([] === $ids) {
            $this->addFlash('warning', 'Cochez au moins une créance à relancer.');

            return $this->redirectToRoute('app_creances_index');
        }

        $parCommande = [];
        foreach ($this->creances->donnees(new \DateTimeImmutable())['lignes'] as $ligne) {
            $parCommande[$ligne['commande']->getId()] = $ligne;
        }
        $utilisateur = $this->getUser();
        // Un message par client, avec toutes ses créances choisies.
        $parClient = [];
        foreach ($ids as $id) {
            $ligne = $parCommande[$id] ?? null;
            if (null === $ligne) {
                continue;
            }
            /** @var Commandes $commande */
            $commande = $ligne['commande'];
            $em->persist((new RelanceCreance())
                ->setCommande($commande)
                ->setCanal(ModeleMessage::CANAL_WHATSAPP)
                ->setPar($utilisateur instanceof User ? $utilisateur : null));
            $cle = $commande->getClients()?->getId() ?? 'c'.$id;
            $parClient[$cle] ??= ['client' => $ligne['client'], 'telephone' => $ligne['telephone'], 'dus' => []];
            $parClient[$cle]['dus'][] = [
                'document' => ($ligne['facture'] ? 'facture ' : 'commande ').$ligne['document'],
                'reste' => $ligne['reste'],
                'echeance' => $ligne['echeance'],
                'statut' => $ligne['statut'],
            ];
        }
        $relances = [];
        foreach ($parClient as $groupe) {
            $message = Creances::messageRelance($groupe['client'], $groupe['dus']);
            $telephone = $groupe['telephone'] ? UtilisateursController::numeroWhatsApp($groupe['telephone']) : null;
            $relances[] = $groupe + [
                'reste' => array_sum(array_column($groupe['dus'], 'reste')),
                'message' => $message,
                'lien' => $telephone ? 'https://wa.me/'.$telephone.'?text='.rawurlencode($message) : null,
            ];
        }
        $em->flush();

        return $this->render('creances/relances.html.twig', ['relances' => $relances]);
    }
}
