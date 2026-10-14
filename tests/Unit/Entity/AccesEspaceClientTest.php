<?php

namespace App\Tests\Unit\Entity;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use PHPUnit\Framework\TestCase;

/**
 * Code d'accès à l'espace client donné par la boutique sur WhatsApp :
 * 8 caractères faciles à taper, 48 h, une seule fois, empreinte seulement.
 */
final class AccesEspaceClientTest extends TestCase
{
    private static function acces(): AccesEspaceClient
    {
        return (new AccesEspaceClient())->setClient((new Clients())->setCode('CLI-000042')->setNom('Traoré')->setTelephone('76124212'));
    }

    public function testHuitCaracteresSansLettreAmbigue(): void
    {
        $vus = '';
        for ($i = 0; $i < 200; ++$i) {
            $code = self::acces()->nouveauCode('76124212', new \DateTimeImmutable(), null);
            self::assertSame(AccesEspaceClient::LONGUEUR, \strlen($code));
            self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
            $vus .= $code;
        }
        foreach (['0', 'O', '1', 'I', 'L'] as $ambigu) {
            self::assertStringNotContainsString($ambigu, $vus, $ambigu);
        }
        self::assertStringNotContainsString('0', AccesEspaceClient::ALPHABET);
        self::assertStringNotContainsString('O', AccesEspaceClient::ALPHABET);
    }

    public function testSeuleLEmpreinteEstGardee(): void
    {
        $acces = self::acces();
        $code = $acces->nouveauCode('76 12 42 12', new \DateTimeImmutable(), null);

        self::assertSame(hash('sha256', '22376124212|'.$code), $acces->getEmpreinte());
        foreach ((new \ReflectionObject($acces))->getProperties() as $propriete) {
            $valeur = $propriete->getValue($acces);
            if (\is_string($valeur)) {
                self::assertStringNotContainsString($code, $valeur, $propriete->getName());
            }
        }
        self::assertStringNotContainsString($code, serialize($acces));
    }

    public function testLeCodeSeTapeCommeOnVeut(): void
    {
        $acces = self::acces();
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $code = $acces->nouveauCode('76124212', $maintenant, null);
        $affiche = AccesEspaceClient::afficherCode($code);

        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $affiche);
        foreach ([$code, $affiche, strtolower($affiche), ' '.str_replace('-', ' ', $affiche).' '] as $saisi) {
            self::assertTrue($acces->valide(AccesEspaceClient::empreinteDe('+223 76 12 42 12', $saisi), $maintenant), $saisi);
        }
        self::assertFalse($acces->valide(AccesEspaceClient::empreinteDe('76124213', $code), $maintenant), 'avec un autre numéro');
    }

    public function testValable48Heures(): void
    {
        $acces = self::acces();
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $empreinte = AccesEspaceClient::empreinteDe('76124212', $acces->nouveauCode('76124212', $maintenant, null));

        self::assertEquals(new \DateTimeImmutable('2026-10-16 10:00'), $acces->getExpireLe());
        self::assertTrue($acces->valide($empreinte, new \DateTimeImmutable('2026-10-16 09:59')));
        self::assertFalse($acces->valide($empreinte, new \DateTimeImmutable('2026-10-16 10:01')));
    }

    public function testUneSeuleFois(): void
    {
        $acces = self::acces();
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $empreinte = AccesEspaceClient::empreinteDe('76124212', $acces->nouveauCode('76124212', $maintenant, null));

        $acces->utiliser($maintenant);

        self::assertTrue($acces->estUtilise());
        self::assertFalse($acces->valide($empreinte, $maintenant));
    }

    public function testUnNouveauCodeAnnuleLePrecedentEtRepartAZero(): void
    {
        $acces = self::acces();
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $ancien = AccesEspaceClient::empreinteDe('76124212', $acces->nouveauCode('76124212', $maintenant, null));
        $acces->utiliser($maintenant);

        $plusTard = $maintenant->modify('+3 days');
        $nouveau = AccesEspaceClient::empreinteDe('76124212', $acces->nouveauCode('76124212', $plusTard, null));

        self::assertNotSame($ancien, $nouveau);
        self::assertFalse($acces->valide($ancien, $plusTard), 'l\'ancien code ne sert plus');
        self::assertTrue($acces->valide($nouveau, $plusTard), 'le nouveau sert, même après un code déjà utilisé');
        self::assertFalse($acces->estUtilise());
    }

    public function testUneLigneVideNeLaissePasEntrer(): void
    {
        $acces = new AccesEspaceClient();

        self::assertFalse($acces->valide('', new \DateTimeImmutable()));
        self::assertFalse($acces->valide(hash('sha256', '22376124212|'), new \DateTimeImmutable('-1 minute')));
    }
}
