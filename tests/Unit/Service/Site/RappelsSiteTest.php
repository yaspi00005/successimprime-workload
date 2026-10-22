<?php

namespace App\Tests\Unit\Service\Site;

use App\Controller\SiteDemandeController;
use App\Entity\DemandeDevis;
use App\Service\Site\ContactSite;
use App\Service\Site\FormulaireDemande;
use App\Service\Site\RappelsSite;
use PHPUnit\Framework\TestCase;

/** Page Contact (maquettes EC04 à EC06) : créneaux de rappel, n° de commande, horaires de la semaine. */
final class RappelsSiteTest extends TestCase
{
    /** Lundi au samedi, 8 h – 18 h. */
    private static function grille(string $maintenant, array $prises = [], int $premierJour = 0): ?array
    {
        return RappelsSite::grille(
            static fn (\DateTimeImmutable $j): bool => (int) $j->format('N') <= 6,
            static fn (\DateTimeImmutable $j): array => [[$j->setTime(8, 0), $j->setTime(18, 0)]],
            8,
            11,
            new \DateTimeImmutable($maintenant),
            static fn (\DateTimeImmutable $j): array => $prises[$j->format('Y-m-d')] ?? [],
            3,
            $premierJour,
        );
    }

    public function testLeSoirOnProposeDemainDeHuitAOnzeHeures(): void
    {
        $g = self::grille('2026-10-19 21:30');

        self::assertSame('demain', $g['quand']);
        self::assertSame('mardi 20/10', $g['libelle']);
        self::assertSame([8, 9, 10, 11], array_column($g['heures'], 'heure'));
        self::assertSame('2026-10-20 08:00', $g['heures'][0]['valeur']);
        self::assertSame([true, true, true, true], array_column($g['heures'], 'libre'));
    }

    public function testTroisRappelsParHeureAuPlus(): void
    {
        $g = self::grille('2026-10-19 21:30', ['2026-10-20' => [8 => 3, 9 => 2]]);

        self::assertSame([false, true, true, true], array_column($g['heures'], 'libre'));
    }

    public function testLeSamediSoirOnSauteLeDimanche(): void
    {
        $g = self::grille('2026-10-24 20:00');

        self::assertSame('le', $g['quand']);
        self::assertSame('lundi 26/10', $g['libelle']);
    }

    public function testLeMatinTotLesCreneauxPassesOuTropProchesDisparaissent(): void
    {
        // 9 h 40 : 10 h est à moins de 30 minutes, il reste 11 h.
        $g = self::grille('2026-10-19 09:40');

        self::assertSame('aujourd\'hui', $g['quand']);
        self::assertSame([11], array_column($g['heures'], 'heure'));
    }

    public function testJourCompletOnPasseAuSuivant(): void
    {
        $g = self::grille('2026-10-19 21:30', ['2026-10-20' => [8 => 3, 9 => 3, 10 => 3, 11 => 3]]);

        self::assertSame('mercredi 21/10', $g['libelle']);
    }

    public function testFermetureExceptionnelleRienAvantDemain(): void
    {
        $g = self::grille('2026-10-19 06:00', [], 1);

        self::assertSame('demain', $g['quand']);
    }

    public function testRienSiLAtelierNeRouvrePas(): void
    {
        self::assertNull(RappelsSite::grille(static fn (): bool => false, static fn (): array => [], 8, 11, new \DateTimeImmutable('2026-10-19 21:00'), static fn (): array => [], 3));
    }

    public function testRappelSansCreneauRefuse(): void
    {
        $saisie = ['nom' => 'Awa Diarra', 'telephone' => '76 12 34 56', 'creneau' => ''];
        self::assertSame('Choisissez l\'heure du rappel.', FormulaireDemande::valider(DemandeDevis::NATURE_RAPPEL, $saisie, [])['erreurs']['creneau']);

        $pris = FormulaireDemande::valider(DemandeDevis::NATURE_RAPPEL, ['creneau' => '2026-10-20 08:00'] + $saisie, [], creneau: static fn (): ?\DateTimeImmutable => null);
        self::assertStringContainsString('vient d\'être pris', $pris['erreurs']['creneau']);

        $ok = FormulaireDemande::valider(DemandeDevis::NATURE_RAPPEL, ['creneau' => '2026-10-20 09:00'] + $saisie, [], creneau: static fn (string $v): \DateTimeImmutable => new \DateTimeImmutable($v));
        self::assertSame([], $ok['erreurs']);
        self::assertSame('2026-10-20 09:00', $ok['valeurs']['rappelLe']->format('Y-m-d H:i'));
        self::assertNull($ok['valeurs']['message'], 'le message est facultatif pour un rappel');
    }

    public function testNumeroDeCommande(): void
    {
        self::assertNull(FormulaireDemande::numeroCommande('  '));
        self::assertSame('CM-0183', FormulaireDemande::numeroCommande(' cm-0183 '));
        self::assertSame('CMD-2026/12', FormulaireDemande::numeroCommande('cmd-2026/12'));
        self::assertFalse(FormulaireDemande::numeroCommande('<script>'));
        self::assertFalse(FormulaireDemande::numeroCommande('X'));

        $v = FormulaireDemande::valider(DemandeDevis::NATURE_CONTACT, ['nom' => 'Awa', 'telephone' => '76123456', 'objet' => 'commande', 'numero_commande' => 'cm 0183', 'message' => 'Où en est ma commande ?'], []);
        self::assertSame([], $v['erreurs']);
        self::assertSame('CM0183', $v['valeurs']['numeroCommande']);
        self::assertSame('commande', $v['valeurs']['objet']);

        $faux = FormulaireDemande::valider(DemandeDevis::NATURE_CONTACT, ['nom' => 'Awa', 'telephone' => '76123456', 'numero_commande' => 'é!', 'message' => 'Bonjour à vous'], []);
        self::assertArrayHasKey('numero_commande', $faux['erreurs']);
    }

    public function testSemaineAvecAujourdhuiEtJoursFermes(): void
    {
        $s = ContactSite::semaine('lun-sam', '08:00', '18:00', new \DateTimeImmutable('2026-10-21 10:00'));

        self::assertCount(7, $s);
        self::assertSame('Lundi', $s[0]['nom']);
        self::assertTrue($s[2]['aujourdhui']);
        self::assertFalse($s[0]['aujourdhui']);
        self::assertNotNull($s[5]['plage']);
        self::assertNull($s[6]['plage'], 'dimanche fermé');

        // Un jour férié (lundi) est dit fermé.
        $ferie = ContactSite::semaine('lun-sam', '08:00', '18:00', new \DateTimeImmutable('2026-10-21 10:00'), static fn (\DateTimeImmutable $j): bool => !\in_array((int) $j->format('N'), [1, 7], true));
        self::assertNull($ferie[0]['plage']);
    }

    public function testPrenomDuTitreDeConfirmation(): void
    {
        self::assertSame('Awa', SiteDemandeController::prenom('  Awa   Diarra '));
        self::assertSame('', SiteDemandeController::prenom(''));
    }
}
