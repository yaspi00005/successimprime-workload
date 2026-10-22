<?php

namespace App\Controller;

use App\Service\Paiement\OrangeMoney;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Notification d'Orange Money après un paiement de l'espace client
 * (notif_url). Adresse publique : elle n'est crue que si elle porte le
 * notif_token reçu à l'ouverture du paiement (OrangeMoney::notification).
 */
final class OrangeMoneyController extends AbstractController
{
    #[Route('/om/notification/{o}', name: 'app_orange_money_notification', requirements: ['o' => 'SI[0-9A-F]{18}'], methods: ['POST'])]
    public function notification(string $o, Request $request, OrangeMoney $orangeMoney): JsonResponse
    {
        $donnees = json_decode(mb_substr($request->getContent(), 0, 4000), true, 4);
        if (!\is_array($donnees)) {
            $donnees = $request->request->all();
        }
        $orangeMoney->notification($o, $donnees, new \DateTimeImmutable());

        // Toujours 200 : rien n'est dit à qui essaierait de deviner un jeton.
        return new JsonResponse(['ok' => true]);
    }
}
