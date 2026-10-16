<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use App\Service\EspaceClient\ElementAPayer;
use App\Service\EspaceClient\FacturesClient;
use PHPUnit\Framework\TestCase;

/** « Factures et paiements » de l'espace client : ce qui est à payer, quand, et dans quel état. */
final class FacturesClientTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-15 10:00';

    public function testCeQuiEstAPayerLaPlusAncienneEcheanceDAbord(): void
    {
        $maintenant = new \DateTimeImmutable(self::AUJOURDHUI);
        $echue = $this->commande(1, 'CM-0147', 125000, '2026-09-20', 'livree');
        $this->paye($echue, 62500);
        $aVenir = $this->commande(2, 'CM-0159', 64000, '2026-10-10', 'livree');
        $acompte = $this->commande(3, 'CM-0163', 25000, '2026-10-14');
        $prete = $this->commande(4, 'CM-0170', 30000, '2026-10-01', 'prete_livraison');
        $prete->setDateLivraison(new \DateTimeImmutable('2026-10-12'));
        $soldee = $this->commande(5, 'CM-0100', 10000, '2026-08-01', 'livree');
        $this->paye($soldee, 10000);
        $annulee = $this->commande(6, 'CM-0101', 10000, '2026-08-01', CommandesDetails::PRODUCTION_ANNULEE);
        $nonConfirmee = $this->commande(7, 'CM-0102', 10000, '2026-10-14')->setStatut(false);
        $factures = [
            1 => $this->facture('F-SI-2026-0431', '2026-09-27', '2026-10-12'),
            2 => $this->facture('F-SI-2026-0446', '2026-10-15', '2026-10-30'),
        ];

        $elements = FacturesClient::elementsDe([$aVenir, $acompte, $echue, $prete, $soldee, $annulee, $nonConfirmee], $factures, 50, null, $maintenant);

        self::assertSame(['CM-0147', 'CM-0170', 'CM-0163', 'CM-0159'], array_map(static fn (ElementAPayer $e): string => (string) $e->commande->getNumero(), $elements));
        [$a, $b, $c, $d] = $elements;
        self::assertSame([ElementAPayer::FACTURE, ElementAPayer::ECHUE, 62500], [$a->nature, $a->etat, $a->montant], 'le reste de la facture échue');
        self::assertSame('F-SI-2026-0431', $a->facture?->getNumero());
        self::assertTrue($a->estPartiel());
        self::assertSame(62500, $a->dejaPaye());
        self::assertSame([ElementAPayer::COMMANDE, 30000, '2026-10-12'], [$b->nature, $b->montant, $b->echeance->format('Y-m-d')], 'prête sans facture : tout, depuis qu\'elle est prête');
        self::assertSame([ElementAPayer::ACOMPTE, 12500, 50], [$c->nature, $c->montant, $c->pourcentage], 'en préparation : l\'acompte');
        self::assertFalse($c->productionLancee);
        self::assertSame([ElementAPayer::FACTURE, ElementAPayer::A_VENIR, 64000], [$d->nature, $d->etat, $d->montant]);
        self::assertSame($d, FacturesClient::prochaineEcheance($elements));
        self::assertMatchesRegularExpression('/^c-[0-9a-f-]{36}$/', $a->cle());
    }

    public function testAcompteDuClientEtDejaVerse(): void
    {
        $maintenant = new \DateTimeImmutable(self::AUJOURDHUI);
        $c = $this->commande(1, 'CM-1', 30000, '2026-10-14');
        // Acompte du client : 30 % ; 4 000 F déjà validés.
        $this->paye($c, 4000);
        $elements = FacturesClient::elementsDe([$c], [], 50, 30, $maintenant);
        self::assertSame(5000, $elements[0]->montant);

        // Acompte déjà couvert : rien n'est demandé avant la livraison.
        $this->paye($c, 5000);
        self::assertSame([], FacturesClient::elementsDe([$c], [], 50, 30, $maintenant));
        // Sans acompte (0 %) : rien avant que la commande soit prête.
        self::assertSame([], FacturesClient::elementsDe([$this->commande(2, 'CM-2', 30000, '2026-10-14')], [], 0, null, $maintenant));
    }

    public function testCeQuiAttendLaBoutiqueNestPlusDemande(): void
    {
        $maintenant = new \DateTimeImmutable(self::AUJOURDHUI);
        $c = $this->commande(1, 'CM-1', 50000, '2026-09-01', 'livree');
        $c->addPaiement((new Paiements())->setMontant(20000)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate($maintenant));
        $c->addPaiement((new Paiements())->setMontant(5000)->setMode(Paiements::MODE_SOLDE_CLIENT)->setDate($maintenant));
        $elements = FacturesClient::elementsDe([$c], [1 => $this->facture('F-1', '2026-09-30', null)], 50, null, $maintenant);

        self::assertSame(25000, $elements[0]->montant);
        self::assertSame(ElementAPayer::ECHUE, $elements[0]->etat, 'sans échéance : due depuis la date de la facture');
        self::assertSame(25000, FacturesClient::enValidation($c));
    }

    public function testSoldeDOuverture(): void
    {
        $solde = Commandes::soldeOuverture(new Clients(), 40000, new \DateTimeImmutable('2026-01-02'), 'SO-0001');
        $solde->setStatut(true);
        $elements = FacturesClient::elementsDe([$solde], [], 50, null, new \DateTimeImmutable(self::AUJOURDHUI));

        self::assertSame([ElementAPayer::SOLDE, 40000], [$elements[0]->nature, $elements[0]->montant]);
    }

    public function testProchainRappel(): void
    {
        $maintenant = new \DateTimeImmutable(self::AUJOURDHUI);
        // Tous les 7 jours depuis le 01/10 : 08/10, 15/10 (aujourd'hui, déjà passé), puis 22/10.
        self::assertSame('2026-10-22', FacturesClient::prochainRappel(new \DateTimeImmutable('2026-10-01'), [7], null, $maintenant)?->format('Y-m-d'));
        // L'échéance de la facture, puis une semaine après, si c'est plus tôt.
        self::assertSame('2026-10-19', FacturesClient::prochainRappel(new \DateTimeImmutable('2026-10-01'), [7], new \DateTimeImmutable('2026-10-12'), $maintenant)?->format('Y-m-d'));
        self::assertSame('2026-10-16', FacturesClient::prochainRappel(null, [], new \DateTimeImmutable('2026-10-16'), $maintenant)?->format('Y-m-d'));
        self::assertNull(FacturesClient::prochainRappel(null, [0], null, $maintenant));
    }

    public function testReleveDeCompte(): void
    {
        $m = static fn (string $date, int $debit, int $credit): array => ['date' => new \DateTimeImmutable($date), 'libelle' => $date, 'debit' => $debit, 'credit' => $credit];
        $releve = FacturesClient::calculerReleve([
            $m('2026-09-27', 125000, 0),
            $m('2026-09-21', 0, 62500),
            $m('2026-10-02', 0, 20000),
            $m('2026-10-15 18:00', 64000, 0),
            $m('2026-10-16', 0, 1000),
        ], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'));

        self::assertSame(62500, $releve['ouverture'], 'ce qui était dû au 1er octobre');
        self::assertSame([42500, 106500], array_column($releve['lignes'], 'solde'));
        self::assertSame([64000, 20000, 106500], [$releve['debits'], $releve['credits'], $releve['cloture']]);
    }

    public function testEtatsHonnetes(): void
    {
        $maintenant = new \DateTimeImmutable(self::AUJOURDHUI);
        $p = (new Paiements())->setMontant(1000)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate($maintenant);
        self::assertSame('Reçu · en validation', FacturesClient::etatPaiement($p)['libelle']);
        self::assertSame('Orange Money', FacturesClient::moyen($p));
        self::valide($p);
        self::assertSame(['libelle' => 'Validé', 'couleur' => 'vert'], FacturesClient::etatPaiement($p));
        self::assertSame(['montant' => 1000, 'nombre' => 1], FacturesClient::payeEn([$p, (new Paiements())->setMontant(500)->setDate($maintenant)], 2026), 'seuls les paiements validés');
        self::assertSame(['montant' => 0, 'nombre' => 0], FacturesClient::payeEn([$p], 2025));

        // Un élément d'un règlement : en cours chez Orange, reçu en validation, puis soldé.
        $c = $this->commande(1, 'CM-1', 12500, '2026-10-14');
        $r = new ReglementEnLigne('SI1', new Clients(), null, $maintenant);
        $part = new ReglementEnLignePart($r, $c, null, ReglementEnLignePart::ACOMPTE, 12500, 0, 125, 125, 1);
        $r->ajouterPart($part);
        self::assertSame('en cours chez Orange Money', FacturesClient::etatPart($part)['libelle']);
        $om = (new Paiements())->setMontant(12500)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate($maintenant);
        $c->addPaiement($om);
        $part->rattacherPaiements($om, null);
        self::assertSame('reçu · en validation', FacturesClient::etatPart($part)['libelle']);
        self::valide($om);
        self::assertSame(['libelle' => 'soldée', 'couleur' => 'vert'], FacturesClient::etatPart($part));

        $abandonne = new ReglementEnLigne('SI2', new Clients(), null, $maintenant);
        $partAbandonnee = new ReglementEnLignePart($abandonne, $c, null, ReglementEnLignePart::FACTURE, 1000, 0, 0, 0, 1);
        $abandonne->ajouterPart($partAbandonnee);
        $abandonne->abandonner();
        self::assertSame('non payé', FacturesClient::etatPart($partAbandonnee)['libelle']);
        self::assertSame('réservé', FacturesClient::etatAvoir(new ReglementEnLigne('SI3', new Clients(), null, $maintenant))['libelle']);
    }

    private function commande(int $id, string $numero, int $total, string $date, ?string $production = null): Commandes
    {
        $c = (new Commandes())->setNumero($numero)->setTotalTtc($total)->setStatut(true)->setDateCommande(new \DateTimeImmutable($date));
        (new \ReflectionProperty(Commandes::class, 'id'))->setValue($c, $id);
        if (null !== $production) {
            $c->addCommandesDetail((new CommandesDetails())->setStatutProduction('livree' === $production ? CommandesDetails::PRODUCTION_LIVREE : $production));
        }

        return $c;
    }

    private function paye(Commandes $c, int $montant): void
    {
        $c->addPaiement(self::valide((new Paiements())->setMontant($montant)->setMode(Paiements::MODE_ESPECES)->setDate(new \DateTimeImmutable('2026-09-21'))));
    }

    /** Validé par la caisse (le compte de trésorerie n'importe pas ici). */
    private static function valide(Paiements $p): Paiements
    {
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($p, Paiements::STATUT_VALIDE);

        return $p;
    }

    private function facture(string $numero, string $date, ?string $echeance): Factures
    {
        return (new Factures())->setNumero($numero)->setDateFacture(new \DateTimeImmutable($date))->setDateEcheance(null !== $echeance ? new \DateTimeImmutable($echeance) : null);
    }
}
