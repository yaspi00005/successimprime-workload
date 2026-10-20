<?php

namespace App\Tests\Unit\Service\Achats;

use App\Entity\Articles;
use App\Entity\ReclamationFournisseur;
use App\Service\Achats\CalculsAchat;
use App\Service\Achats\CalculsReception;
use PHPUnit\Framework\TestCase;

/**
 * Réception d'une commande d'achat (docs/achats.md, maquette SU05), avec
 * les chiffres de la maquette : CA-2026-0041 de Dakar Signs, 4 rouleaux
 * de vinyle blanc (76 m² chacun) dont un écrasé, 2 de micro-perforé
 * (68,5 m²), transport réel 121 000 F au lieu de 114 700 F.
 */
final class CalculsReceptionTest extends TestCase
{
    public function testBadgesDEcart(): void
    {
        self::assertSame(['libelle' => '1 rouleau écrasé', 'classe' => 'retard'], CalculsReception::ecart(4, 4, 3, 'rouleau', 'écrasé'));
        self::assertSame(['libelle' => 'conforme', 'classe' => 'ok'], CalculsReception::ecart(2, 2, 2, 'rouleau'));
        self::assertSame(['libelle' => 'manque 1', 'classe' => 'retard'], CalculsReception::ecart(4, 3, 3, 'rouleau'));
        self::assertSame(['libelle' => 'en trop 2', 'classe' => 'attente'], CalculsReception::ecart(4, 6, 6, 'rouleau'));
        self::assertSame('2 rouleaux abîmés · manque 1', CalculsReception::ecart(5, 4, 2, 'rouleau')['libelle'], 'sans nature de la casse : « abîmé »');
        self::assertSame('1 plaque rayée', CalculsReception::ecart(10, 10, 9, 'plaque', 'rayé')['libelle'], 'accord au féminin');
    }

    public function testQuantiteAReclamerAuFournisseur(): void
    {
        // La facture compte 4 rouleaux, 3 sont en bon état.
        self::assertSame(1, CalculsReception::quantiteAReclamer(4, 3));
        // Le fournisseur a déjà retiré le rouleau abîmé de sa facture : rien à réclamer.
        self::assertSame(0, CalculsReception::quantiteAReclamer(3, 3));
        self::assertSame(0, CalculsReception::quantiteAReclamer(2, 3));
        self::assertSame('1 rouleau à remplacer ou à déduire (159 600 F)', CalculsReception::texteReclamation(1, 'rouleau', 159600));
        self::assertSame('2 rouleaux à remplacer ou à déduire', CalculsReception::texteReclamation(2, 'rouleau', null), 'sans le droit trésorerie, pas de montant');
    }

    public function testCoutDeRevientAvecLesFraisReelsSurLesUnitesEnBonEtat(): void
    {
        $prevu = CalculsReception::coutsReels([
            ['recue' => 304.0, 'bon' => 304.0, 'unite' => 'm²', 'montantRecu' => 638400, 'montantBon' => 638400],
            ['recue' => 137.0, 'bon' => 137.0, 'unite' => 'm²', 'montantRecu' => 452100, 'montantBon' => 452100],
        ], 114700, 39700);
        // Sans casse et avec les frais prévus : le coût de la commande (SU03).
        self::assertSame(2450, $prevu[0]['cout']);
        self::assertSame(3650, $prevu[1]['cout']);

        $reel = CalculsReception::coutsReels([
            ['recue' => 304.0, 'bon' => 228.0, 'unite' => 'm²', 'montantRecu' => 638400, 'montantBon' => 478800],
            ['recue' => 137.0, 'bon' => 137.0, 'unite' => 'm²', 'montantRecu' => 452100, 'montantBon' => 452100],
        ], 121000, 39700);

        // Les frais se répartissent sur les m² livrés, abîmés compris…
        self::assertSame(121000, $reel[0]['partTransport'] + $reel[1]['partTransport']);
        self::assertSame(83410, $reel[0]['partTransport']);
        // … puis chaque ligne les porte sur ses seuls m² en bon état : (3 × 159 600 + 83 410 + 27 367) ÷ 228.
        self::assertSame(2586, $reel[0]['cout']);
        // Le micro-perforé ne porte que le surcoût du transport, pas la casse du vinyle.
        self::assertSame(3664, $reel[1]['cout']);
        // La valeur entrée en stock égale ce qui est payé (au franc près de l'arrondi).
        self::assertEqualsWithDelta(478800 + 452100 + 121000 + 39700, 228 * 2586 + 137 * 3664, 200);
    }

    public function testRienEnBonEtatPasDeCout(): void
    {
        $couts = CalculsReception::coutsReels([['recue' => 76.0, 'bon' => 0.0, 'unite' => 'm²', 'montantRecu' => 159600, 'montantBon' => 0]], 30000, 0);

        self::assertNull($couts[0]['cout']);
        self::assertSame(30000, $couts[0]['partTransport']);
    }

    public function testConversionEnUniteDeStock(): void
    {
        // 3 rouleaux de vinyle de 76 m² entrent : 228 m², 57,8 → 285,8 m².
        $vinyle = (new Articles())->setQuantiteParUniteAchat(76);
        self::assertSame(228.0, $vinyle->convertirAchatEnStock(3));
        self::assertEqualsWithDelta(285.8, 57.8 + $vinyle->convertirAchatEnStock(3), 0.001);

        // Sans quantité notée : longueur × laize pour un article au m².
        $micro = (new Articles())->setUnite('m2')->setLongueurUniteAchat(50)->setLaize(1.37);
        self::assertSame(137.0, $micro->convertirAchatEnStock(2));
    }

    public function testPaiementBloqueTantQuUneReclamationEstOuverte(): void
    {
        self::assertTrue(CalculsReception::paiementBloque([ReclamationFournisseur::OUVERTE], false));
        self::assertTrue(CalculsReception::paiementBloque([ReclamationFournisseur::DEDUITE, ReclamationFournisseur::OUVERTE], false));
        self::assertFalse(CalculsReception::paiementBloque([ReclamationFournisseur::REMPLACEE, ReclamationFournisseur::DEDUITE], false), 'réclamations réglées : le solde se paie');
        self::assertFalse(CalculsReception::paiementBloque([ReclamationFournisseur::CLOSE], false));
        self::assertFalse(CalculsReception::paiementBloque([], false));
        self::assertFalse(CalculsReception::paiementBloque([ReclamationFournisseur::OUVERTE], true), 'déblocage forcé par la finance');
    }

    public function testRaisonDuBlocage(): void
    {
        $vinyle = ['nom' => 'Vinyle blanc', 'uniteAchat' => 'rouleau', 'facturee' => 4, 'recue' => 4, 'bonEtat' => 3, 'ouverte' => true];

        self::assertSame(
            'La facture compte 4 rouleaux de vinyle blanc alors qu\'un est abîmé : paiement du solde bloqué jusqu\'à l\'avoir du fournisseur.',
            CalculsReception::motifBlocage([$vinyle])
        );
        self::assertNull(CalculsReception::motifBlocage([['ouverte' => false] + $vinyle]), 'réclamation réglée');
        self::assertNull(CalculsReception::motifBlocage([['facturee' => 3] + $vinyle]), 'la facture compte déjà le bon état');
        self::assertStringContainsString('alors que seulement 3 sont arrivés en bon état', (string) CalculsReception::motifBlocage([['recue' => 3] + $vinyle]), 'un rouleau manquant mais facturé');
    }

    public function testSoldeAPayer(): void
    {
        // Facture de 1 090 500 F, acompte de 50 % versé à la commande.
        self::assertSame(545250, CalculsReception::soldeAPayer(1090500, 545250));
        self::assertSame(0, CalculsReception::soldeAPayer(100000, 150000));
    }

    public function testLivraisonEtPonctualite(): void
    {
        $promise = new \DateTimeImmutable('2026-10-26');

        self::assertSame('Livraison : 28/10, 2 jours après la date promise. Compté dans la performance de Dakar Signs.', CalculsReception::texteLivraison($promise, new \DateTimeImmutable('2026-10-28 15:00'), 'Dakar Signs'));
        self::assertSame('Livraison : 26/10, le jour promis. Compté dans la performance de Dakar Signs.', CalculsReception::texteLivraison($promise, new \DateTimeImmutable('2026-10-26'), 'Dakar Signs'));
        self::assertSame('Livraison : 25/10, 1 jour avant la date promise. Compté dans la performance de Dakar Signs.', CalculsReception::texteLivraison($promise, new \DateTimeImmutable('2026-10-25'), 'Dakar Signs'));

        // La réception compte dans la ponctualité et le délai réel du fournisseur.
        $avant = [['promise' => new \DateTimeImmutable('2026-09-01'), 'recue' => new \DateTimeImmutable('2026-09-01'), 'commande' => new \DateTimeImmutable('2026-08-25')]];
        $apres = [...$avant, ['promise' => $promise, 'recue' => new \DateTimeImmutable('2026-10-28'), 'commande' => new \DateTimeImmutable('2026-10-19')]];
        self::assertSame(100, CalculsAchat::pourcentageALHeure($avant));
        self::assertSame(50, CalculsAchat::pourcentageALHeure($apres));
        self::assertSame(1.0, CalculsAchat::retardMoyen($apres));
        self::assertSame(8.0, CalculsAchat::delaiReelMoyen($apres));
    }

    public function testMessageAuFournisseurEtLienWhatsApp(): void
    {
        $message = CalculsReception::messageReclamation('M. Ndiaye', 'CA-2026-0041', new \DateTimeImmutable('2026-10-28'), 1, 'rouleau', 'Vinyle blanc', 'écrasé', 1, 159600, 'FDS-26-1290');

        self::assertStringStartsWith('Bonjour M. Ndiaye,', $message);
        self::assertStringContainsString('1 rouleau de vinyle blanc écrasé', $message);
        self::assertStringContainsString('Merci de le remplacer ou de le déduire de votre facture FDS-26-1290 (159 600 F)', $message);
        self::assertSame('https://wa.me/221774125810?text='.rawurlencode($message), CalculsReception::lienWhatsApp('221774125810', $message));
        self::assertNull(CalculsReception::lienWhatsApp(null, $message));
    }
}
