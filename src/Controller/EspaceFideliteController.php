<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\CompteFidelite;
use App\Entity\MouvementFidelite;
use App\Repository\CompteClientRepository;
use App\Service\Fidelite\Fidelite;
use App\Service\Fidelite\ReveilClients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, Fidélité (maquette 18, téléphone de droite, adaptée aux
 * règles du 13/10/2026) : le solde en points et en francs, la progression
 * vers chaque seuil, le ×2 du paiement le jour même, les points qui vont
 * expirer, la promo de réveil, l'historique. Lecture seule : l'atelier
 * utilise les points sur une commande. Chacun ne voit que sa fiche ; rien
 * des agents ni des motifs internes.
 */
final class EspaceFideliteController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EntityManagerInterface $em,
        private readonly ReveilClients $reveil,
        private readonly Fidelite $fidelite,
    ) {
    }

    #[Route('/espace/fidelite', name: 'app_espace_fidelite', methods: ['GET'])]
    public function index(): Response
    {
        $compte = $this->compte();
        if (!$compte->peut(CompteClient::DROIT_PAYEUR)) {
            // Comme les factures : les points ne regardent que ceux qui paient.
            throw $this->createAccessDeniedException();
        }
        $client = $compte->getClient();
        $resume = Fidelite::resumeEspace($this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]));
        $exclu = $client->isB2B() || $resume['exclu'];
        $mouvements = $exclu ? [] : $this->em->getRepository(MouvementFidelite::class)->findBy(['client' => $client], ['date' => 'DESC', 'id' => 'DESC'], 200);

        return $this->render('espace/app/fidelite.html.twig', [
            'onglet' => 'compte',
            'moi' => $client,
            'peutPayer' => true,
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'exclu' => $exclu,
            'resume' => $resume,
            'historique' => Fidelite::historique($mouvements, true, 15, $resume['delaiHeures']),
            'promo' => $exclu ? null : $this->reveil->active($client, new \DateTimeImmutable()),
            'validiteMois' => $this->fidelite->reglages()->validiteMois,
        ]);
    }

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
