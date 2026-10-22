<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\CauseSynchro;
use App\Service\Synchro\ErreurSite;
use App\Service\Synchro\Synchro;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * La vraie cause d'une synchronisation qui ne passe pas : « Connexion
 * internet coupée » seulement quand internet l'est (docs/synchronisation.md).
 */
final class CauseSynchroTest extends TestCase
{
    public function testInternetNEstDitCoupeQueSIlNeRepondPas(): void
    {
        self::assertSame(CauseSynchro::INTERNET, CauseSynchro::depuisTransport('Could not resolve host: succesimprim.com', false));
        self::assertSame(CauseSynchro::DNS, CauseSynchro::depuisTransport('Could not resolve host: www.succesimprim.com', true));
        self::assertSame(CauseSynchro::CERTIFICAT, CauseSynchro::depuisTransport('SSL certificate problem: certificate has expired', true));
        self::assertSame(CauseSynchro::DELAI, CauseSynchro::depuisTransport('Idle timeout reached for "https://succesimprim.com/api/synchro/envoyer".', true));
        self::assertSame(CauseSynchro::SITE, CauseSynchro::depuisTransport('Failed to connect to succesimprim.com port 443: Connection refused', true));
    }

    public function testLaCauseDUnEchecDeLiaison(): void
    {
        $sansInternet = static fn (): bool => false;
        $avecInternet = static fn (): bool => true;
        $transport = new TransportException('Operation timed out after 30001 milliseconds');

        self::assertSame(CauseSynchro::INTERNET, AgentSynchro::cause($transport, $sansInternet));
        self::assertSame(CauseSynchro::DELAI, AgentSynchro::cause($transport, $avecInternet));
        self::assertSame(CauseSynchro::REFUS, AgentSynchro::cause(new ErreurSite('refus', 401, CauseSynchro::REFUS), $sansInternet));
        self::assertSame(CauseSynchro::ERREUR_SITE, AgentSynchro::cause(new \RuntimeException('Réponse du site non signée'), $sansInternet));
        self::assertStringStartsWith('Le site répond trop lentement : ', AgentSynchro::messageLiaison($transport, CauseSynchro::DELAI));
    }

    public function testLAgentArretePasseAvantLaDerniereErreur(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 14:00');

        self::assertSame(CauseSynchro::AGENT_ARRETE, CauseSynchro::actuelle(CauseSynchro::INTERNET, new \DateTimeImmutable('2026-10-19 08:03'), $maintenant));
        self::assertSame(CauseSynchro::INTERNET, CauseSynchro::actuelle(CauseSynchro::INTERNET, new \DateTimeImmutable('2026-10-19 13:59'), $maintenant));
        self::assertNull(CauseSynchro::actuelle(null, new \DateTimeImmutable('2026-10-19 13:59'), $maintenant));
        self::assertSame('Synchronisation arrêtée sur cet ordinateur', CauseSynchro::titre(CauseSynchro::AGENT_ARRETE));
        self::assertStringContainsString('sans www', CauseSynchro::conseil(CauseSynchro::DNS, 'www.succesimprim.com'));
    }

    public function testUnLotQueLeSiteNeSuitPasRepartEnPlusPetit(): void
    {
        self::assertSame(Synchro::LOT, AgentSynchro::tailleLot(null));
        self::assertSame(Synchro::LOT, AgentSynchro::tailleLot('0'));
        self::assertSame(6, AgentSynchro::tailleLot('6'));
        self::assertSame([25, 6, 1, 1], [AgentSynchro::reduire(100), AgentSynchro::reduire(25), AgentSynchro::reduire(6), AgentSynchro::reduire(1)]);

        self::assertTrue(AgentSynchro::lotTropLourd(new TransportException('Idle timeout')));
        self::assertTrue(AgentSynchro::lotTropLourd(new ErreurSite('413', 413, CauseSynchro::ERREUR_SITE)));
        self::assertTrue(AgentSynchro::lotTropLourd(new ErreurSite('504', 504, CauseSynchro::ERREUR_SITE)));
        // Refus de signature ou adresse fausse : réduire le lot n'y changerait rien.
        self::assertFalse(AgentSynchro::lotTropLourd(new ErreurSite('401', 401, CauseSynchro::REFUS)));
        self::assertFalse(AgentSynchro::lotTropLourd(new ErreurSite('404', 404, CauseSynchro::ERREUR_SITE)));
    }

    public function testZeroEnvoyeeAvecUneFileDitPourquoi(): void
    {
        self::assertSame('', CauseSynchro::pourquoiRienNePart(0, 0, 0, null));
        self::assertStringContainsString('attend une autre donnée', CauseSynchro::pourquoiRienNePart(2455, 0, 0, 'l\'opération n° 12 attend une autre donnée (commande absente)'));
        self::assertStringContainsString('--relancer-bloques', CauseSynchro::pourquoiRienNePart(2455, 0, 2455, null));
        self::assertStringContainsString('dont 3 bloquées', CauseSynchro::pourquoiRienNePart(2455, 0, 3, null));
        self::assertStringContainsString('2355 restent en file', CauseSynchro::pourquoiRienNePart(2355, 100, 0, null));
    }
}
