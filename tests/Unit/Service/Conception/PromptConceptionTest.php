<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\DemandeConception;
use App\Entity\SupportConception;
use App\Service\Conception\PromptConception;
use PHPUnit\Framework\TestCase;

/**
 * Consigne envoyée au générateur d'images : construite par le serveur à
 * partir de la demande, sans marque ni personne connue, de longueur bornée.
 */
final class PromptConceptionTest extends TestCase
{
    public function testConsigneConstruiteDepuisLaDemande(): void
    {
        $demande = $this->demande();
        $demande->ecrireContenu('Soirée jazz', 'au bord du fleuve', 'Samedi 24 octobre · 20 h', 'Réservations : +223 70 00 00 00', 'Mettre « Hôtel Niger présente » en haut. Ambiance chic, nuit, musique.');
        $demande->setCouleurs(['#13285C', '#f2c230']);
        $demande->setStyles(['elegant', 'moderne']);

        $prompt = PromptConception::construire($demande, 'Ajouter un saxophone, plus de lumière dorée', 'kakemono', 2);

        self::assertStringContainsString('elegant, refined, modern, clean print-ready tall roll-up banner artwork (85 × 200 cm), portrait orientation', $prompt);
        self::assertStringContainsString('main title "Soirée jazz"; subtitle "au bord du fleuve"; date and time "Samedi 24 octobre · 20 h"', $prompt);
        self::assertStringContainsString('Colour palette: midnight blue (#13285C), gold (#F2C230).', $prompt);
        self::assertStringContainsString('keep a clean empty area for a logo', $prompt);
        self::assertStringContainsString('Do not draw any brand logo, trademark, real person', $prompt);
        self::assertStringContainsString('Latest client request (French): "Ajouter un saxophone, plus de lumière dorée".', $prompt);
        self::assertStringEndsWith('Ambiance chic, nuit, musique.".', $prompt);
    }

    public function testOrientationSelonLeSupport(): void
    {
        self::assertSame(PromptConception::PORTRAIT, PromptConception::orientation(85, 200));
        self::assertSame(PromptConception::PAYSAGE, PromptConception::orientation(300, 100));
        self::assertSame(PromptConception::CARRE, PromptConception::orientation(50, 50));
        self::assertSame(PromptConception::CARRE, PromptConception::orientation(8.5, 5.5 * 1.5));
        self::assertSame(PromptConception::CARRE, PromptConception::orientation(null, 20));
    }

    public function testMarquesEtPersonnesConnuesRefusees(): void
    {
        foreach (['Mettre le logo Coca-Cola', 'un portrait de MESSI', 'style Nike', 'avec Beyoncé au centre', 'une célébrité qui chante'] as $consigne) {
            try {
                PromptConception::verifierConsigne($consigne);
                self::fail('Refus attendu : '.$consigne);
            } catch (\DomainException $e) {
                self::assertStringStartsWith('Pas de logos de marques ni de personnes connues', $e->getMessage());
            }
        }
    }

    public function testMotsVoisinsEtNomsDeFamilleAcceptes(): void
    {
        // Ni un mot qui contient une marque, ni un nom de famille courant, ni un prénom courant.
        foreach (['Ajouter un saxophone', 'Fanta et Aminata Keïta vous invitent', 'Famille Traoré', 'des pommes (apples) mûres', 'tecnologie verte', 'un puma dans la savane'] as $consigne) {
            self::assertNotNull(PromptConception::verifierConsigne($consigne), $consigne);
        }
        self::assertNull(PromptConception::verifierConsigne('   '));
    }

    public function testConsigneTropLongueRefusee(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('plus de 300 caractères');
        PromptConception::verifierConsigne(str_repeat('a', PromptConception::CONSIGNE_MAX + 1));
    }

    public function testConsignesDeLaDemandeAvecUneMarqueRefusees(): void
    {
        $demande = $this->demande();
        $demande->ecrireContenu('Promo', null, null, null, 'Faire comme la pub Pepsi');

        $this->expectException(\DomainException::class);
        PromptConception::construire($demande, null, 'affiche');
    }

    public function testTextesAAfficherNeutralises(): void
    {
        $demande = $this->demande();
        $demande->ecrireContenu('Tournoi Real Madrid', null, null, 'Infos chez "Drogba"', null);
        $prompt = PromptConception::construire($demande, null, 'kakemono');

        self::assertStringNotContainsStringIgnoringCase('real madrid', $prompt);
        self::assertStringNotContainsStringIgnoringCase('drogba', $prompt);
        self::assertStringContainsString('main title "Tournoi"', $prompt);
        self::assertSame('Grand tournoi de quartier', PromptConception::neutraliser('Grand tournoi Nike de quartier'));
    }

    public function testLongueurBornee(): void
    {
        $demande = $this->demande();
        $demande->ecrireContenu(str_repeat('Titre ', 30), str_repeat('Sous-titre ', 20), str_repeat('Date ', 30), str_repeat('Contact ', 30), str_repeat('Consigne longue. ', 70));
        $prompt = PromptConception::construire($demande, str_repeat('b', PromptConception::CONSIGNE_MAX), 'bache', 3);

        self::assertLessThanOrEqual(PromptConception::PROMPT_MAX, mb_strlen($prompt));
        self::assertStringContainsString('Do not draw any brand logo', $prompt);
    }

    private function demande(): DemandeConception
    {
        $support = (new SupportConception())->setCode('kakemono')->setNom('Kakémono / roll-up')->setCourt('Kakémono')->setFormat('85 × 200 cm')->setLargeurCm(85)->setHauteurCm(200)->setForfait(10000);

        return DemandeConception::nouvelle((new Clients())->setNom('Hôtel Niger'), null, $support, new \DateTimeImmutable('2026-10-14 10:00'));
    }
}
