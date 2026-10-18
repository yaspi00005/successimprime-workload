<?php

namespace App\Tests\Unit\Controller;

use App\Command\RapportsMailCommand;
use App\Controller\RapportsMailController;
use App\EventSubscriber\StatistiquesHorsLigneSubscriber;
use App\Service\Rapports\DroitsRapport;
use App\Service\Rapports\PeriodeRapport;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil › Rapports par e-mail : la période montrée par l'aperçu, le
 * droit demandé partout, la Direction seule pour les destinataires et
 * les reprises, et rien sur le serveur en ligne.
 */
final class RapportsMailControllerTest extends TestCase
{
    public function testPeriodeDeLApercu(): void
    {
        $dimanche = new \DateTimeImmutable('2026-10-18 09:00');

        // Sans période : aujourd'hui, la semaine passée, le mois passé (ce que le dernier e-mail a montré).
        self::assertSame('2026-10-18', RapportsMailController::periodeDemandee(PeriodeRapport::JOUR, '', $dimanche)->code());
        self::assertSame('2026-W41', RapportsMailController::periodeDemandee(PeriodeRapport::SEMAINE, '', $dimanche)->code());
        self::assertSame('2026-09', RapportsMailController::periodeDemandee(PeriodeRapport::MOIS, '', $dimanche)->code());

        self::assertSame('2026-10-03', RapportsMailController::periodeDemandee(PeriodeRapport::JOUR, '2026-10-03', $dimanche)->code());
        // La semaine en cours se regarde (chiffres arrêtés maintenant) ; une période future, non.
        self::assertSame('2026-W42', RapportsMailController::periodeDemandee(PeriodeRapport::SEMAINE, '2026-W42', $dimanche)->code());
        self::assertSame('2026-10-18', RapportsMailController::periodeDemandee(PeriodeRapport::JOUR, '2026-10-19', $dimanche)->code());
        // Plus de deux ans en arrière, ou illisible : la période par défaut.
        self::assertSame('2026-09', RapportsMailController::periodeDemandee(PeriodeRapport::MOIS, '2024-09', $dimanche)->code());
        self::assertSame('2026-09', RapportsMailController::periodeDemandee(PeriodeRapport::MOIS, '2026-9', $dimanche)->code());
    }

    public function testDroitsDeLEcran(): void
    {
        $classe = new \ReflectionClass(RapportsMailController::class);
        $droits = array_map(static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->attribute, $classe->getAttributes(IsGranted::class));
        self::assertSame([DroitsRapport::DROIT], $droits);
        self::assertSame('ROLE_STATS_GLOBAL', DroitsRapport::DROIT);

        // Choisir les destinataires et renvoyer un rapport : la Direction.
        foreach (['destinataires', 'reprendre'] as $methode) {
            $attributs = $classe->getMethod($methode)->getAttributes(IsGranted::class);
            self::assertCount(1, $attributs, $methode);
            self::assertSame('ROLE_ADMIN', $attributs[0]->newInstance()->attribute, $methode);
        }
        // Ce qui envoie ou enregistre ne se fait qu'en POST (avec le jeton du formulaire).
        foreach (['essai', 'destinataires', 'reprendre'] as $methode) {
            self::assertSame(['POST'], $classe->getMethod($methode)->getAttributes(Route::class)[0]->newInstance()->methods, $methode);
        }
    }

    public function testFermeEnLigneEtDansLeMenuAvecLeDroit(): void
    {
        self::assertContains('app_rapports_mail', StatistiquesHorsLigneSubscriber::ROUTES);

        $entrees = array_values(array_filter(NavigationExtension::ONGLETS['accueil'] ?? [], static fn (array $o): bool => 'app_rapports_mail' === $o['route']));
        self::assertCount(1, $entrees);
        self::assertSame(DroitsRapport::DROIT, $entrees[0]['role']);
        self::assertSame('Africa/Bamako', RapportsMailCommand::FUSEAU);
    }
}
