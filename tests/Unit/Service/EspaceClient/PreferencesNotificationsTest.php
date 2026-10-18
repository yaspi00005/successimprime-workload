<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Service\EspaceClient\PreferencesNotifications as P;
use PHPUnit\Framework\TestCase;

final class PreferencesNotificationsTest extends TestCase
{
    public function testReglagesDeDepartDuTitulaire(): void
    {
        $titulaire = self::compte(self::entreprise(), [CompteClient::DROIT_ADMINISTRATEUR]);

        $grille = P::parDefaut($titulaire);

        // Le SMS (payant) seulement pour ce qui attend une réponse ou un déplacement.
        self::assertSame([P::APP, P::WHATSAPP, P::SMS], $grille['bat']);
        self::assertSame([P::APP], $grille['production']);
        self::assertSame([P::APP, P::WHATSAPP, P::SMS], $grille['livraison']);
        self::assertSame([P::APP, P::WHATSAPP, P::EMAIL], $grille['factures']);
        self::assertSame([P::APP, P::WHATSAPP, P::SMS], $grille['messages']);
        // Offres décochées sans accord.
        self::assertSame([], $grille['offres']);
        self::assertFalse(P::veutLesOffres($titulaire));
    }

    public function testUnePersonneAjouteeChoisitSesSms(): void
    {
        $client = self::entreprise();
        $titulaire = self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR]);
        $valideur = self::compte($client, [CompteClient::DROIT_VALIDEUR])->setAjoutePar($titulaire);

        self::assertNotContains(P::SMS, P::parDefaut($valideur)['bat']);
        self::assertContains(P::APP, P::parDefaut($valideur)['bat']);
    }

    public function testAccordDejaDonneALaBoutiqueApparaitCoche(): void
    {
        $client = self::entreprise()->setAccepteMarketing(true);

        self::assertSame([P::WHATSAPP, P::SMS], P::parDefaut(self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR]))['offres']);
    }

    public function testLesFacturesNeVontQuAuxPayeurs(): void
    {
        $client = self::entreprise();
        $valideur = self::compte($client, [CompteClient::DROIT_VALIDEUR])->setPreferencesNotifications(['factures' => [P::APP, P::SMS]]);
        $payeur = self::compte($client, [CompteClient::DROIT_PAYEUR]);

        self::assertNotContains('factures', P::categoriesPermises($valideur));
        self::assertSame([], P::canaux($valideur, 'factures'), 'coché en base, jamais envoyé');
        self::assertContains('factures', P::categoriesPermises($payeur));
        self::assertSame([], P::lire(['factures' => [P::SMS]], $valideur)['factures']);
    }

    public function testLectureSeuleProductionEtLivraisonSeulement(): void
    {
        $lecture = self::compte(self::entreprise(), [CompteClient::DROIT_LECTURE]);

        self::assertSame(['production', 'livraison'], P::categoriesPermises($lecture));
        $grille = P::lire(['bat' => [P::SMS], 'devis' => [P::APP], 'livraison' => [P::SMS, 'pigeon']], $lecture);
        self::assertSame([], $grille['bat']);
        self::assertSame([], $grille['devis']);
        self::assertSame([P::SMS], $grille['livraison'], 'canal inconnu écarté');
    }

    public function testUnParticulierRecoitTout(): void
    {
        $particulier = self::compte((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER), []);

        self::assertSame(array_keys(P::CATEGORIES), P::categoriesPermises($particulier));
    }

    public function testReglagesEnregistresCompletesParLeDefaut(): void
    {
        $compte = self::compte(self::entreprise(), [CompteClient::DROIT_ADMINISTRATEUR])->setPreferencesNotifications(['bat' => [P::EMAIL]]);

        self::assertSame([P::EMAIL], P::canaux($compte, 'bat'));
        self::assertSame([P::APP, P::WHATSAPP, P::SMS], P::canaux($compte, 'livraison'), 'catégorie absente : réglage de départ');
    }

    public function testHeuresCalmes(): void
    {
        self::assertFalse(P::heuresCalmes(new \DateTimeImmutable('2026-10-14 07:00')));
        self::assertFalse(P::heuresCalmes(new \DateTimeImmutable('2026-10-14 20:59')));
        self::assertTrue(P::heuresCalmes(new \DateTimeImmutable('2026-10-14 21:00')));
        self::assertTrue(P::heuresCalmes(new \DateTimeImmutable('2026-10-15 06:59')));

        self::assertEquals(new \DateTimeImmutable('2026-10-15 07:00'), P::finHeuresCalmes(new \DateTimeImmutable('2026-10-14 22:30')));
        self::assertEquals(new \DateTimeImmutable('2026-10-15 07:00'), P::finHeuresCalmes(new \DateTimeImmutable('2026-10-15 03:10')));
    }

    public function testLivraisonDuJourPasseLesHeuresCalmes(): void
    {
        $soir = new \DateTimeImmutable('2026-10-14 21:30');

        self::assertTrue(P::livraisonDuJour(['type' => 'route'], $soir));
        self::assertTrue(P::livraisonDuJour(['type' => 'livree'], $soir));
        self::assertTrue(P::livraisonDuJour(['type' => 'livraison_prevue', 'jour' => new \DateTimeImmutable('2026-10-14')], $soir));
        self::assertFalse(P::livraisonDuJour(['type' => 'livraison_prevue', 'jour' => new \DateTimeImmutable('2026-10-15')], $soir));
        self::assertFalse(P::livraisonDuJour(['type' => 'bat'], $soir));
    }

    public function testAccordOffres(): void
    {
        // Cocher donne l'accord ; décocher le retire, sauf si une autre personne les reçoit encore.
        self::assertTrue(P::accordOffres(false, true, false, false));
        self::assertFalse(P::accordOffres(true, false, false, true));
        self::assertTrue(P::accordOffres(true, false, true, true));
        // Sans changement, l'accord donné à l'imprimerie reste.
        self::assertTrue(P::accordOffres(false, false, false, true));
    }

    public function testResumeQuiRecoitQuoi(): void
    {
        $client = self::entreprise();
        $titulaire = self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR]);
        $valideur = self::compte($client, [CompteClient::DROIT_VALIDEUR])->setAjoutePar($titulaire);
        $payeur = self::compte($client, [CompteClient::DROIT_PAYEUR])->setAjoutePar($titulaire)
            ->setPreferencesNotifications(['bat' => [], 'production' => [], 'livraison' => [], 'factures' => [P::EMAIL], 'devis' => [], 'messages' => []]);

        self::assertSame('tout', P::resume($titulaire));
        self::assertSame('tout sauf factures', P::resume($valideur));
        self::assertSame('factures et paiements', P::resume($payeur));
    }

    private static function entreprise(): Clients
    {
        return (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }
}
