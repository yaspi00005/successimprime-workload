<?php

namespace App\Tests\Unit\Controller;

use App\Controller\CaisseController;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Paiements;
use PHPUnit\Framework\TestCase;

/**
 * Après « Encaisser et imprimer le reçu », la caisse propose d'envoyer le
 * reçu au client (Paramètres › Paiements › Reçu envoyé).
 */
final class CaisseRecuTest extends TestCase
{
    public function testLienWhatsAppDuRecuAvecLeTelephoneDuClient(): void
    {
        $envoi = CaisseController::envoiRecu($this->paiement(), 'whatsapp');

        self::assertNotNull($envoi);
        self::assertSame('whatsapp', $envoi['canal']);
        self::assertStringStartsWith('https://wa.me/22370112233?text=', $envoi['lien']);
        $message = rawurldecode(substr($envoi['lien'], strpos($envoi['lien'], '=') + 1));
        self::assertStringContainsString('Bonjour Awa', $message);
        self::assertStringContainsString('commande CMD-000777-10-2026', $message);
        self::assertStringContainsString('Reçu R-000042', $message);
    }

    public function testSmsOuRienSelonLeReglage(): void
    {
        self::assertStringStartsWith('sms:+22370112233?body=', CaisseController::envoiRecu($this->paiement(), 'sms')['lien'] ?? '');
        self::assertNull(CaisseController::envoiRecu($this->paiement(), 'aucun'));
    }

    private function paiement(): Paiements
    {
        $client = (new Clients())->setPrenom('Awa')->setNom('Formation')->setTelephone('70 11 22 33');
        $commande = (new Commandes())->setClients($client)->setNumero('CMD-000777-10-2026')->setTotalTtc(10500);
        $paiement = (new Paiements())->setMontant(5250)->setMode(Paiements::MODE_ESPECES);
        $commande->addPaiement($paiement);
        (new \ReflectionProperty(Paiements::class, 'id'))->setValue($paiement, 42);

        return $paiement;
    }
}
