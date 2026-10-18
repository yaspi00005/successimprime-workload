<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Clients;
use App\Entity\Employes;
use App\Entity\MessageClient;
use PHPUnit\Framework\TestCase;

/**
 * Signature d'une réponse de l'atelier dans les messages de l'espace
 * client : prénom, métier et disque à l'initiale, jamais le nom de famille.
 */
final class MessageClientTest extends TestCase
{
    public function testPrenomMetierEtInitialeDuPrenom(): void
    {
        $s = MessageClient::signatureDe((string) Employes::nomPublic('Seydou', 'Graphiste PAO'));

        self::assertSame('Seydou', $s['nom']);
        self::assertSame('graphiste PAO', $s['metier']);
        self::assertSame('S', $s['initiales']);
        self::assertIsInt($s['teinte']);
        self::assertGreaterThanOrEqual(0, $s['teinte']);
        self::assertLessThan(MessageClient::TEINTES, $s['teinte']);
        self::assertSame($s['teinte'], MessageClient::signatureDe('seydou')['teinte'], 'une teinte fixe pour un prénom, métier ou pas');
    }

    public function testPrenomComposeEtSansMetier(): void
    {
        self::assertSame(['nom' => 'Mame Diarra', 'metier' => null, 'initiales' => 'MD'], array_diff_key(MessageClient::signatureDe('  Mame   Diarra '), ['teinte' => 0]));
        self::assertSame('JM', MessageClient::signatureDe('Jean-Marc (accueil)')['initiales']);
        self::assertSame('ÉL', MessageClient::signatureDe('éloïse Léa Awa')['initiales'], 'deux initiales au plus, en majuscules');
        self::assertSame('?', MessageClient::signatureDe('2B (graphiste)')['initiales']);
    }

    public function testAuteurCoupeA80Caracteres(): void
    {
        $s = MessageClient::signatureDe('Awa (responsable de la production et du contrôle des fichiers');

        self::assertSame('Awa', $s['nom']);
        self::assertSame('responsable de la production et du contrôle des fichiers', $s['metier']);
        self::assertSame('A', $s['initiales']);
    }

    public function testBoutiqueSansPrenom(): void
    {
        $boutique = ['nom' => 'Succès Imprim', 'metier' => null, 'initiales' => 'SI', 'teinte' => null];

        self::assertSame($boutique, MessageClient::signatureDe(null));
        self::assertSame($boutique, MessageClient::signatureDe(' '));
        self::assertSame($boutique, MessageClient::signatureDe('SUCCÈS IMPRIM'));
    }

    public function testSeulementPourLAtelier(): void
    {
        $client = new Clients();
        $atelier = MessageClient::nouveau($client, MessageClient::ATELIER, 'Votre BAT est prêt.', 'Fanta (accueil)', null, new \DateTimeImmutable());
        $duClient = MessageClient::nouveau($client, MessageClient::CLIENT, 'Merci', 'Amadou Sow', null, new \DateTimeImmutable());

        self::assertSame('Fanta', $atelier->getSignature()['nom'] ?? null);
        self::assertNull($duClient->getSignature(), 'un message du client garde son affichage');
    }
}
