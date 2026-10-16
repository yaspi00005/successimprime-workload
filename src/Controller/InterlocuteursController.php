<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Service\Clients\EditionClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Interlocuteurs proposés dans la saisie d'un devis ou d'une commande
 * (« À l'attention de », « Commandé par ») : une entreprise a souvent deux
 * ou trois personnes qui commandent pour elle. Les contacts de la fiche,
 * puis les noms déjà notés sur ses devis et commandes (dont les personnes
 * qui commandent en ligne). Des noms et des fonctions seulement : ni
 * numéro, ni montant.
 */
final class InterlocuteursController extends AbstractController
{
    /** Noms au plus dans la liste. */
    private const MAX = 20;

    #[Route('/saisie/interlocuteurs/{id}', name: 'app_saisie_interlocuteurs', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_DEVIS') or is_granted('ROLE_COMMANDE') or is_granted('ROLE_CLIENT')"))]
    public function liste(#[MapEntity(id: 'id')] Clients $client, EditionClient $edition, EntityManagerInterface $em): JsonResponse
    {
        $noms = [];
        foreach ($edition->contacts($client) as $k) {
            $noms[mb_strtolower($k->getNom())] ??= ['nom' => $k->getNom(), 'precision' => trim(implode(' · ', array_filter([$k->getFonction(), 'principal' === $k->getRole() ? 'contact principal' : null])))];
        }
        $deja = [
            ...$em->createQuery('SELECT c.demandeePar AS nom, MAX(c.id) AS dernier FROM '.Commandes::class.' c WHERE c.clients = :client AND c.demandeePar IS NOT NULL GROUP BY c.demandeePar ORDER BY dernier DESC')
                ->setParameter('client', $client)->setMaxResults(self::MAX)->getArrayResult(),
            ...$em->createQuery('SELECT d.interlocuteur AS nom, MAX(d.id) AS dernier FROM '.Devis::class.' d WHERE d.clients = :client AND d.interlocuteur IS NOT NULL GROUP BY d.interlocuteur ORDER BY dernier DESC')
                ->setParameter('client', $client)->setMaxResults(self::MAX)->getArrayResult(),
        ];
        foreach ($deja as $ligne) {
            $nom = trim((string) $ligne['nom']);
            if ('' !== $nom) {
                $noms[mb_strtolower($nom)] ??= ['nom' => $nom, 'precision' => 'déjà noté'];
            }
        }

        $reponse = new JsonResponse(['interlocuteurs' => \array_slice(array_values($noms), 0, self::MAX)]);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
