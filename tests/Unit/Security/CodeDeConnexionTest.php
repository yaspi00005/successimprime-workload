<?php

namespace App\Tests\Unit\Security;

use App\Security\CodeDeConnexion;
use App\Security\DestinationCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CodeDeConnexionTest extends TestCase
{
    private const CLE = 'code_test';

    private static function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    /** Le code envoyé, lu dans l'e-mail. */
    private static function codeDe(FabriqueCodes $fabrique): string
    {
        self::assertNotEmpty($fabrique->emails);
        preg_match('/^\s+(\d{6})$/m', (string) end($fabrique->emails)->getTextBody(), $m);

        return $m[1] ?? '';
    }

    public function testParEmailQuandLaFicheAUneAdresse(): void
    {
        $fabrique = new FabriqueCodes();
        $codes = $fabrique->codes();
        $session = self::session();

        $envoi = $codes->envoyer($session, self::CLE, DestinationCode::vers('76124212', ' Yaya@Gmail.com '), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertTrue($envoi['envoye']);
        self::assertSame(CodeDeConnexion::EMAIL, $envoi['canal']);
        self::assertSame('y•••@gmail.com', $envoi['destination']);
        self::assertNull($envoi['codeDev'], 'parti : pas de code affiché');
        self::assertCount(1, $fabrique->emails);
        self::assertSame('yaya@gmail.com', $fabrique->emails[0]->getTo()[0]->getAddress());
        self::assertSame('Succès Imprim', $fabrique->emails[0]->getFrom()[0]->getName());
        self::assertSame('ok', $codes->verifier($session, self::CLE, self::codeDe($fabrique)));
    }

    public function testLEmailDonneLeCodeSaDureeEtLAvertissementSansMontantNiLien(): void
    {
        $texte = CodeDeConnexion::texteEmail('482913', CodeDeConnexion::ESPACE);

        self::assertStringContainsString('482913', $texte);
        self::assertStringContainsString('valable 5 minutes', $texte);
        self::assertStringContainsString('Ne le donnez à personne', $texte);
        self::assertStringContainsString('Succès Imprim', $texte);
        self::assertDoesNotMatchRegularExpression('#https?://|www\.|\d\s?F\b|FCFA#', $texte);
        self::assertStringContainsString('Prévenez la direction', CodeDeConnexion::texteEmail('482913', CodeDeConnexion::PERSONNEL));
    }

    public function testParSmsSansAdresse(): void
    {
        $fabrique = new FabriqueCodes();
        $envoi = $fabrique->codes()->envoyer(self::session(), self::CLE, DestinationCode::vers('76124212', null), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertSame(CodeDeConnexion::SMS, $envoi['canal']);
        self::assertSame('+223 •• •• 42 12', $envoi['destination']);
        // SMS non configuré dans les tests : l'envoi échoue, le code s'affiche hors production.
        self::assertFalse($envoi['envoye']);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $envoi['codeDev']);
        self::assertSame([], $fabrique->emails);
    }

    public function testAdresseNonValableOuEmailPasRegleSurLeServeur(): void
    {
        $fabrique = new FabriqueCodes();
        self::assertSame(CodeDeConnexion::SMS, $fabrique->codes()->canal(DestinationCode::vers('76124212', 'pas-une-adresse')));
        self::assertSame(CodeDeConnexion::SMS, $fabrique->codes(emailRegle: false)->canal(DestinationCode::vers('76124212', 'yaya@gmail.com')));
        self::assertFalse($fabrique->codes(emailRegle: false)->emailPret());
        self::assertNull($fabrique->codes()->canal(DestinationCode::vers('', null)));
    }

    public function testLeSmsPrendLeRelaisQuandLEmailEchoue(): void
    {
        $fabrique = new FabriqueCodes();
        $fabrique->emailEnPanne = true;
        $envoi = $fabrique->codes()->envoyer(self::session(), self::CLE, DestinationCode::vers('76124212', 'yaya@gmail.com'), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertSame(CodeDeConnexion::SMS, $envoi['canal']);
        self::assertSame('+223 •• •• 42 12', $envoi['destination']);
    }

    public function testFicheDeDemonstrationNeRecoitRien(): void
    {
        $fabrique = new FabriqueCodes();
        $envoi = $fabrique->codes()->envoyer(self::session(), self::CLE, DestinationCode::demonstration('60697804'), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertFalse($envoi['envoye']);
        self::assertSame([], $fabrique->emails);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $envoi['codeDev']);
        $prod = (new FabriqueCodes())->codes('prod')->envoyer(self::session(), self::CLE, DestinationCode::demonstration('60697804'), CodeDeConnexion::ESPACE, '10.0.0.1');
        self::assertNull($prod['codeDev'], 'jamais de code affiché en production');
    }

    public function testNumeroInconnuMemeReponseSansRienEnvoyer(): void
    {
        $fabrique = new FabriqueCodes();
        $session = self::session();
        $envoi = $fabrique->codes()->envoyer($session, self::CLE, DestinationCode::inconnue('76000001'), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertSame(['envoye' => true, 'canal' => CodeDeConnexion::SMS, 'destination' => '+223 •• •• 00 01', 'erreur' => null, 'codeDev' => null, 'limite' => false], $envoi);
        self::assertSame([], $fabrique->emails);
        self::assertTrue($fabrique->codes()->enAttente($session, self::CLE));
    }

    public function testCodeEnProductionJamaisAffiche(): void
    {
        $envoi = (new FabriqueCodes())->codes('prod')->envoyer(self::session(), self::CLE, DestinationCode::vers('76124212'), CodeDeConnexion::ESPACE, '10.0.0.1');

        self::assertFalse($envoi['envoye']);
        self::assertNull($envoi['codeDev']);
    }

    public function testTroisCodesParNumeroEnQuinzeMinutesEtLeDernierResteBon(): void
    {
        $fabrique = new FabriqueCodes();
        $codes = $fabrique->codes();
        $session = self::session();
        for ($i = 0; $i < 3; ++$i) {
            self::assertFalse($codes->envoyer($session, self::CLE, DestinationCode::vers('76124212', 'yaya@gmail.com'), CodeDeConnexion::ESPACE, '10.0.0.'.$i)['limite']);
        }
        $dernier = self::codeDe($fabrique);

        $refus = $codes->envoyer($session, self::CLE, DestinationCode::vers('76124212', 'yaya@gmail.com'), CodeDeConnexion::ESPACE, '10.0.0.9');
        self::assertTrue($refus['limite']);
        self::assertSame(CodeDeConnexion::TROP_DE_CODES, $refus['erreur']);
        self::assertCount(3, $fabrique->emails, 'rien ne part au-delà');
        self::assertSame('ok', $codes->verifier($session, self::CLE, $dernier), 'le code déjà envoyé reste bon');
    }

    public function testLaLimiteSuitLAdresseEmailQuelQueSoitLeNumero(): void
    {
        $codes = (new FabriqueCodes())->codes();
        foreach (['76000001', '76000002', '76000003'] as $i => $telephone) {
            self::assertFalse($codes->envoyer(self::session(), self::CLE, DestinationCode::vers($telephone, 'cible@gmail.com'), CodeDeConnexion::ESPACE, '10.0.1.'.$i)['limite']);
        }

        self::assertTrue($codes->envoyer(self::session(), self::CLE, DestinationCode::vers('76000004', 'cible@gmail.com'), CodeDeConnexion::ESPACE, '10.0.1.9')['limite']);
        self::assertFalse($codes->envoyer(self::session(), self::CLE, DestinationCode::vers('76000004', null), CodeDeConnexion::ESPACE, '10.0.1.9')['limite'], 'le même numéro par SMS passe');
    }

    public function testDixCodesParAdresseIpEnUneHeureMemePourDesNumerosInconnus(): void
    {
        $codes = (new FabriqueCodes())->codes();
        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($codes->envoyer(self::session(), self::CLE, DestinationCode::inconnue('7600001'.$i), CodeDeConnexion::ESPACE, '10.0.2.1')['limite']);
        }

        self::assertTrue($codes->envoyer(self::session(), self::CLE, DestinationCode::inconnue('76000020'), CodeDeConnexion::ESPACE, '10.0.2.1')['limite']);
        self::assertFalse($codes->envoyer(self::session(), self::CLE, DestinationCode::vers('76000020'), CodeDeConnexion::PERSONNEL, '10.0.2.1')['limite'], 'le personnel a ses propres limites');
    }

    public function testCinqEssaisPuisLeCodeEstBloque(): void
    {
        $fabrique = new FabriqueCodes();
        $codes = $fabrique->codes();
        $session = self::session();
        $codes->envoyer($session, self::CLE, DestinationCode::vers('76124212', 'yaya@gmail.com'), CodeDeConnexion::ESPACE, '10.0.0.1');
        $code = self::codeDe($fabrique);
        $faux = '000000' === $code ? '111111' : '000000';

        for ($i = 1; $i < CodeDeConnexion::ESSAIS_MAX; ++$i) {
            self::assertSame('faux', $codes->verifier($session, self::CLE, $faux));
        }
        self::assertSame('trop_essais', $codes->verifier($session, self::CLE, $faux));
        self::assertSame('trop_essais', $codes->verifier($session, self::CLE, $code));
    }

    public function testEssaisDUnCodeDAccesLimitesParNumeroEtParAdresseIp(): void
    {
        $codes = (new FabriqueCodes())->codes();

        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($codes->essaiPermis('76124212', '10.0.0.1'));
        }
        self::assertFalse($codes->essaiPermis('+223 76 12 42 12', '10.0.0.2'), '3 essais par numéro en 15 minutes');
        // Compté à part des codes envoyés : on peut encore recevoir un code par e-mail ou SMS.
        self::assertFalse($codes->envoyer(self::session(), self::CLE, DestinationCode::vers('76124212', 'yaya@gmail.com'), CodeDeConnexion::ESPACE, '10.0.0.3')['limite']);

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($codes->essaiPermis('6600000'.$i, '10.0.0.9'));
        }
        self::assertFalse($codes->essaiPermis('66000010', '10.0.0.9'), '10 essais par adresse IP en une heure, numéros différents compris');
    }

    public function testAdresseMasquee(): void
    {
        self::assertSame('y•••@gmail.com', DestinationCode::masquerEmail('yaya.diallo@gmail.com'));
        self::assertSame('y•••@gmail.com', DestinationCode::masquerEmail('Y@GMAIL.COM'));
        // Le domaine d'une entreprise dirait de qui il s'agit : masqué aussi.
        self::assertSame('c•••@h•••.ml', DestinationCode::masquerEmail('contact@hotel-niger.ml'));
        self::assertSame('+223 •• •• 42 12', DestinationCode::masquerTelephone('22376124212'));
        self::assertNull(DestinationCode::emailValable('yaya@'));
        self::assertSame('yaya@gmail.com', DestinationCode::emailValable(' Yaya@Gmail.com'));
    }
}
