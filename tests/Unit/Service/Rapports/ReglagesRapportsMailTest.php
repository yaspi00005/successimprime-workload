<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Entity\Reglage;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\ReglagesRapportsMail;
use PHPUnit\Framework\TestCase;

/**
 * Réglages des rapports par e-mail (tuile « Rapports par e-mail ») :
 * valeurs par défaut de Yaya, liste des destinataires, adresse de l'ERP,
 * messagerie réglée ou non. Aucun e-mail ne part en développement ni en
 * test tant que MAILER_DSN vaut null://.
 */
final class ReglagesRapportsMailTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $tuiles valeurs enregistrées par tuile */
    public static function reglages(array $tuiles = [], string $dsn = 'null://null', string $expediteur = '', string $environnement = 'test'): ReglagesRapportsMail
    {
        $parametres = (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor();
        $stockes = [];
        foreach ($tuiles as $cle => $valeurs) {
            $stockes[$cle] = (new Reglage($cle))->modifier($valeurs, null, new \DateTimeImmutable());
        }
        (new \ReflectionProperty(Parametres::class, 'reglages'))->setValue($parametres, $stockes);

        return new ReglagesRapportsMail($parametres, $dsn, $expediteur, $environnement);
    }

    public function testValeursParDefaut(): void
    {
        $r = self::reglages();

        foreach (PeriodeRapport::TYPES as $type => $titre) {
            self::assertTrue($r->actif($type), $titre);
        }
        self::assertSame('20:00', $r->heure(PeriodeRapport::JOUR));
        self::assertSame('07:00', $r->heure(PeriodeRapport::SEMAINE));
        self::assertSame('07:00', $r->heure(PeriodeRapport::MOIS));
        self::assertSame(1, $r->jourSemaine());
        self::assertSame(1, $r->jourMois());
        self::assertTrue($r->pieceJointe());
        self::assertNull($r->adresseErp());
        // Tant que la direction n'a rien choisi : null (la direction reçoit).
        self::assertNull($r->destinatairesChoisis());
        self::assertSame('Chaque jour d’ouverture à 20 h, pour la journée', $r->libelle(PeriodeRapport::JOUR));
        self::assertSame('Chaque lundi à 7 h, pour la semaine passée', $r->libelle(PeriodeRapport::SEMAINE));
        self::assertSame('Le 1er du mois à 7 h, pour le mois passé', $r->libelle(PeriodeRapport::MOIS));
    }

    public function testValeursChoisies(): void
    {
        $r = self::reglages([ReglagesRapportsMail::TUILE => [
            'jour_actif' => false, 'jour_heure' => '19:30', 'semaine_jour' => 'mercredi', 'mois_jour' => 5,
            'destinataires_choisis' => true, 'destinataires' => '12, 5, x, 12',
            'adresse_erp' => 'http://192.168.1.10/successImprim/public/',
        ]]);

        self::assertFalse($r->actif(PeriodeRapport::JOUR));
        self::assertNull($r->aEnvoyer(PeriodeRapport::JOUR, new \DateTimeImmutable('2026-10-17 21:00')));
        self::assertSame('19:30', $r->heure(PeriodeRapport::JOUR));
        self::assertSame(3, $r->jourSemaine());
        self::assertSame('Chaque jour d’ouverture à 19 h 30, pour la journée', $r->libelle(PeriodeRapport::JOUR));
        self::assertSame(5, $r->jourMois());
        self::assertSame([5, 12], $r->destinatairesChoisis());
        self::assertSame('http://192.168.1.10/successImprim/public', $r->adresseErp());
        self::assertSame('2026-10-21 07:00', $r->prochainEnvoi(PeriodeRapport::SEMAINE, new \DateTimeImmutable('2026-10-19 08:00'))->format('Y-m-d H:i'));
    }

    public function testRapportDuJourLeMatinPourLaVeille(): void
    {
        $r = self::reglages([ReglagesRapportsMail::TUILE => ['jour_heure' => '07:00']]);
        self::assertSame('Le lendemain de chaque jour d’ouverture à 7 h, pour la veille', $r->libelle(PeriodeRapport::JOUR));

        $r = self::reglages([ReglagesRapportsMail::TUILE => ['jour_heure' => '06:30', 'jour_ouvres' => false]]);
        self::assertSame('Chaque jour à 6 h 30, pour la veille', $r->libelle(PeriodeRapport::JOUR));
        self::assertSame('2026-10-17', $r->aEnvoyer(PeriodeRapport::JOUR, new \DateTimeImmutable('2026-10-18 06:45'))?->code());
    }

    public function testListeDesComptes(): void
    {
        self::assertSame([3, 7, 12], ReglagesRapportsMail::lireIds("12;7\n3, 7, -4, abc"));
        self::assertSame([], ReglagesRapportsMail::lireIds(''));
        self::assertSame('3, 9', ReglagesRapportsMail::ecrireIds([9, 3, 9]));
    }

    public function testAdresseDeLErp(): void
    {
        self::assertSame('https://erp.local', ReglagesRapportsMail::lireAdresse(' https://erp.local/ '));
        self::assertNull(ReglagesRapportsMail::lireAdresse('javascript:alert(1)'));
        self::assertNull(ReglagesRapportsMail::lireAdresse('ftp://192.168.1.10'));
        self::assertNull(ReglagesRapportsMail::lireAdresse('192.168.1.10'));
    }

    public function testMessagerieReglee(): void
    {
        // En production, null:// veut dire « pas réglé » : la tâche ne fait rien.
        self::assertFalse(ReglagesRapportsMail::configure('null://null', 'contact@succesimprim.com', 'prod'));
        // En développement et en tests, null:// laisse tout essayer sans qu'aucun e-mail ne sorte.
        self::assertTrue(ReglagesRapportsMail::configure('null://null', 'contact@succesimprim.com', 'dev'));
        self::assertFalse(ReglagesRapportsMail::configure('', 'contact@succesimprim.com', 'dev'));
        self::assertFalse(ReglagesRapportsMail::configure('smtp://localhost:25', '', 'prod'));
        self::assertFalse(ReglagesRapportsMail::configure('smtp://localhost:25', 'pas une adresse', 'prod'));
        self::assertTrue(ReglagesRapportsMail::configure('smtp://localhost:25', 'contact@succesimprim.com', 'prod'));

        self::assertSame('"Succès Imprim" <contact@succesimprim.com>', ReglagesRapportsMail::adresseExpediteur('contact@succesimprim.com')?->toString());
        self::assertSame('"Atelier" <atelier@succesimprim.com>', ReglagesRapportsMail::adresseExpediteur('Atelier <atelier@succesimprim.com>')?->toString());
        self::assertTrue(self::reglages()->transportFactice());
        self::assertFalse(self::reglages()->courrielConfigure());
    }

    public function testLaTuileGardeLesDestinatairesHorsDuPanneau(): void
    {
        $panneau = array_column(CatalogueParametres::champsPanneau(ReglagesRapportsMail::TUILE), 'cle');

        self::assertContains('jour_heure', $panneau);
        // La liste se choisit sur l'écran (droit vérifié), pas en tapant des numéros.
        self::assertNotContains('destinataires', $panneau);
        self::assertNotContains('destinataires_choisis', $panneau);
        self::assertSame(['ROLE_ADMIN'], CatalogueParametres::tuiles()[ReglagesRapportsMail::TUILE]['roles']);
    }
}
