<?php

namespace App\Tests\Unit\Service\Devis;

use App\Entity\Devis;
use App\Service\Devis\RentabiliteDevis;
use App\Service\Devis\SuiviDevis;
use PHPUnit\Framework\TestCase;

/**
 * Suivi d'un devis (docs/devis.md) : envoi, ouvertures, versions,
 * relance à 3 jours, expiration, refus et textes de la liste.
 */
final class SuiviDevisTest extends TestCase
{
    private function devisEnvoye(string $le = '2026-10-10 10:00'): Devis
    {
        $d = (new Devis())->setNumero('DEV-000001-10-2026')->setStatut(Devis::STATUT_BROUILLON);
        SuiviDevis::envoyer($d, [Devis::CANAL_WHATSAPP], 'Moussa (commercial)', new \DateTimeImmutable($le));

        return $d;
    }

    public function testClientDispenseDAcompteNaRienAPayerDAvance(): void
    {
        $d = (new Devis())->setAcomptePourcentage(50);
        self::assertSame(50, $d->getAcomptePourcentage());

        $d->setClients((new \App\Entity\Clients())->setSansAcompte(true));
        self::assertSame(0, $d->getAcomptePourcentage());
        self::assertSame(0, $d->getMontantAcompte());
    }

    public function testEnvoyerNoteLaVersionEtLaValidite(): void
    {
        $d = $this->devisEnvoye();

        self::assertSame(Devis::STATUT_ENVOYE, $d->getStatut());
        self::assertSame('2026-10-25', $d->getDateValidite()?->format('Y-m-d'));
        self::assertSame([Devis::CANAL_WHATSAPP], $d->getCanauxEnvoi());
        self::assertSame('Moussa (commercial)', $d->getEnvoyePar());
        self::assertCount(1, $d->getVersions());
        self::assertSame(1, $d->getVersions()[0]['numero']);
        self::assertNotEmpty($d->getTokenAuthenticite());
    }

    public function testSansCanalLEspaceClientParDefaut(): void
    {
        $d = (new Devis())->setStatut(Devis::STATUT_BROUILLON);
        SuiviDevis::envoyer($d, [], null, new \DateTimeImmutable('2026-10-10'));

        self::assertSame([Devis::CANAL_ESPACE], $d->getCanauxEnvoi());
    }

    public function testUneOuvertureParDemiHeureEtPassageAVu(): void
    {
        $d = $this->devisEnvoye();

        self::assertTrue(SuiviDevis::noterOuverture($d, 'Awa', new \DateTimeImmutable('2026-10-10 12:00')));
        self::assertSame(Devis::STATUT_VU, $d->getStatut());
        self::assertFalse(SuiviDevis::noterOuverture($d, 'Awa', new \DateTimeImmutable('2026-10-10 12:10')));
        self::assertTrue(SuiviDevis::noterOuverture($d, 'lien WhatsApp', new \DateTimeImmutable('2026-10-11 08:00')));
        self::assertSame(2, $d->getNombreVues());
        self::assertSame('Awa', $d->getVuPar(), 'La première personne qui ouvre reste notée.');
    }

    public function testUnDevisAccepteNeCompteplusLesOuvertures(): void
    {
        $d = $this->devisEnvoye();
        SuiviDevis::accepter($d, 'Awa', new \DateTimeImmutable('2026-10-11'));

        self::assertFalse(SuiviDevis::noterOuverture($d, 'Awa', new \DateTimeImmutable('2026-10-12')));
        self::assertSame(Devis::STATUT_ACCEPTE, $d->getStatut());
    }

    public function testRelanceDueTroisJoursApresUneSeuleFois(): void
    {
        $d = $this->devisEnvoye('2026-10-10 10:00');

        self::assertFalse(SuiviDevis::relanceDue($d, new \DateTimeImmutable('2026-10-12 18:00')));
        self::assertTrue(SuiviDevis::relanceDue($d, new \DateTimeImmutable('2026-10-13 08:00')));
        self::assertSame('2026-10-13 09:00', SuiviDevis::relancePrevue($d)?->format('Y-m-d H:i'));

        $d->noterRelance(new \DateTimeImmutable('2026-10-13 09:00'), Devis::CANAL_WHATSAPP, true, null);
        self::assertFalse(SuiviDevis::relanceDue($d, new \DateTimeImmutable('2026-10-14')));
        self::assertNull(SuiviDevis::relancePrevue($d));
    }

    public function testNouvelleVersionPuisRenvoiRelancePossible(): void
    {
        $d = $this->devisEnvoye('2026-10-01 10:00');
        $d->noterRelance(new \DateTimeImmutable('2026-10-04 09:00'), Devis::CANAL_WHATSAPP, false, 'Moussa');
        SuiviDevis::demanderModification($d, 'Ajouter les totems', 'Awa', new \DateTimeImmutable('2026-10-05'));

        SuiviDevis::nouvelleVersion($d);
        self::assertSame(2, $d->getVersion());
        self::assertSame(Devis::STATUT_BROUILLON, $d->getStatut());

        SuiviDevis::envoyer($d, [Devis::CANAL_ESPACE], 'Moussa', new \DateTimeImmutable('2026-10-06 10:00'));
        self::assertCount(2, $d->getVersions());
        self::assertSame(2, $d->getVersions()[0]['numero'], 'La plus récente d\'abord.');
        self::assertSame('Ajouter les totems', $d->getVersions()[0]['commentaire']);
        self::assertSame(0, $d->getNombreVues());
        self::assertTrue(SuiviDevis::relanceDue($d, new \DateTimeImmutable('2026-10-09')), 'La relance de la version 1 ne compte plus.');
    }

    public function testExpiration(): void
    {
        $d = $this->devisEnvoye('2026-10-01 10:00');

        self::assertFalse(SuiviDevis::estAExpirer($d, new \DateTimeImmutable('2026-10-16 23:00')));
        self::assertTrue(SuiviDevis::estAExpirer($d, new \DateTimeImmutable('2026-10-17 07:00')));
        self::assertFalse(SuiviDevis::relanceDue($d, new \DateTimeImmutable('2026-10-17 07:00')));
    }

    public function testRefusAvecMotif(): void
    {
        $d = $this->devisEnvoye();
        SuiviDevis::refuser($d, 'prix', '  trop   cher ', 'Awa', new \DateTimeImmutable('2026-10-12'));

        self::assertSame(Devis::STATUT_REFUSE, $d->getStatut());
        self::assertSame('Le prix · trop cher', $d->getMotifRefus());
        self::assertSame(['texte' => 'motif : '.$d->getMotifRefusCourt(), 'ton' => 'rouge'], SuiviDevis::texteSuivi($d, new \DateTimeImmutable('2026-10-12')));
    }

    public function testTexteSuiviVu(): void
    {
        $d = $this->devisEnvoye();
        SuiviDevis::noterOuverture($d, 'Awa', new \DateTimeImmutable('2026-10-10 12:00'));
        SuiviDevis::noterOuverture($d, 'Awa', new \DateTimeImmutable('2026-10-11 12:00'));

        self::assertSame('vu 2 fois · expire 25/10', SuiviDevis::texteSuivi($d, new \DateTimeImmutable('2026-10-12'))['texte']);
    }

    public function testChangementsEntreVersions(): void
    {
        $v1 = ['lignes' => [['designation' => 'Panneau', 'quantite' => 1.0, 'total' => 45000], ['designation' => 'Pose', 'quantite' => 1.0, 'total' => 45000]]];
        $v2 = ['lignes' => [['designation' => 'Panneau', 'quantite' => 2.0, 'total' => 90000], ['designation' => 'Totem', 'quantite' => 3.0, 'total' => 105000]]];

        $c = SuiviDevis::changements($v1, $v2);

        self::assertSame(['Panneau : 1 → 2', 'ajout de 3 × Totem', 'retrait de Pose'], array_column($c, 'texte'));
        self::assertSame('mêmes lignes', SuiviDevis::resumeChangements($v1, $v1));
    }

    public function testMessageWhatsAppSansMontant(): void
    {
        $d = $this->devisEnvoye()->setTotalTtc(420000)->setObjet('Signalétique');
        $texte = SuiviDevis::messageWhatsApp($d, 'https://exemple.test/d/abc', true);

        self::assertStringContainsString('petit rappel', $texte);
        self::assertStringContainsString('https://exemple.test/d/abc', $texte);
        self::assertStringNotContainsString('420', $texte);
    }

    public function testBilanRentabilite(): void
    {
        $b = RentabiliteDevis::bilan(100000, [['ht' => 100000, 'cout' => ['total' => 80000, 'manquants' => []]]]);
        self::assertSame(20000, $b['marge']);
        self::assertTrue($b['alerte'], 'Marge de 20 % : sous le seuil de 25 %.');

        $libre = RentabiliteDevis::bilan(50000, [['ht' => 50000, 'cout' => ['total' => 0, 'manquants' => []]]]);
        self::assertNull($libre['taux'], 'Sans coût calculable, pas de faux 100 %.');
        self::assertFalse($libre['alerte']);
    }
}
