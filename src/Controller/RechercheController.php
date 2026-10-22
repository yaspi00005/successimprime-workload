<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recherche globale de l'en-tete (Ctrl+K) : clients, commandes, devis et
 * factures, chacun seulement si l'utilisateur a le droit de les voir.
 */
#[IsGranted('ROLE_USER')]
final class RechercheController extends AbstractController
{
    private const LIMITE = 10;

    #[Route('/recherche', name: 'app_recherche', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $resultats = ['clients' => [], 'commandes' => [], 'devis' => [], 'factures' => []];

        if (mb_strlen($q) >= 2) {
            $motif = '%'.addcslashes($q, '%_\\').'%';

            if ($this->isGranted('ROLE_CLIENT')) {
                $resultats['clients'] = $em->createQueryBuilder()
                    ->select('c')->from(Clients::class, 'c')
                    ->where('c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q OR c.telephone LIKE :q OR c.telephone2 LIKE :q OR c.code LIKE :q')
                    ->setParameter('q', $motif)
                    ->orderBy('c.nom', 'ASC')
                    ->setMaxResults(self::LIMITE)
                    ->getQuery()->getResult();
            }

            if ($this->isGranted('ROLE_COMMANDE')) {
                $resultats['commandes'] = $em->createQueryBuilder()
                    ->select('co', 'c')->from(Commandes::class, 'co')
                    ->leftJoin('co.clients', 'c')
                    ->where('co.numero LIKE :q OR c.nom LIKE :q OR c.raisonSociale LIKE :q OR c.telephone LIKE :q')
                    ->andWhere('co.soldeOuverture = false')
                    ->setParameter('q', $motif)
                    ->orderBy('co.dateCommande', 'DESC')
                    ->setMaxResults(self::LIMITE)
                    ->getQuery()->getResult();
            }

            if ($this->isGranted('ROLE_DEVIS')) {
                $resultats['devis'] = $em->createQueryBuilder()
                    ->select('d', 'c')->from(Devis::class, 'd')
                    ->leftJoin('d.clients', 'c')
                    ->where('d.numero LIKE :q OR c.nom LIKE :q OR c.raisonSociale LIKE :q OR c.telephone LIKE :q')
                    ->setParameter('q', $motif)
                    ->orderBy('d.dateDevis', 'DESC')
                    ->setMaxResults(self::LIMITE)
                    ->getQuery()->getResult();
            }

            if ($this->isGranted('ROLE_FACTURE')) {
                $resultats['factures'] = $em->createQueryBuilder()
                    ->select('f', 'co', 'c')->from(Factures::class, 'f')
                    ->join('f.commande', 'co')
                    ->leftJoin('co.clients', 'c')
                    ->where('f.numero LIKE :q OR co.numero LIKE :q OR c.nom LIKE :q OR c.raisonSociale LIKE :q OR c.telephone LIKE :q')
                    ->setParameter('q', $motif)
                    ->orderBy('f.dateFacture', 'DESC')
                    ->setMaxResults(self::LIMITE)
                    ->getQuery()->getResult();
            }

            // Un seul resultat : on ouvre directement la fiche.
            $total = array_sum(array_map('count', $resultats));
            if (1 === $total) {
                foreach (['clients' => 'app_clients_show', 'commandes' => 'app_commandes_show', 'devis' => 'app_devis_show', 'factures' => 'app_factures_show'] as $type => $route) {
                    if ([] !== $resultats[$type]) {
                        $element = $resultats[$type][0];
                        $parametres = $element instanceof Clients ? ['publicId' => $element->getPublicId()] : ['id' => $element->getId()];

                        return $this->redirectToRoute($route, $parametres);
                    }
                }
            }
        }

        return $this->render('recherche/index.html.twig', [
            'q' => $q,
            'resultats' => $resultats,
        ]);
    }
}
