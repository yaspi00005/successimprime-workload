<?php

namespace App\Tests\Unit\Controller;

use App\Controller\MouvementTresorerieController;
use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Form\MouvementTresorerieType;
use PHPUnit\Framework\TestCase;

/**
 * Saisie d'une entrée, d'une sortie ou d'un virement par la caisse sans
 * le droit « Trésorerie et soldes » : aucun solde dans les listes de
 * comptes ni dans la confirmation, retour à une page qu'elle ouvre.
 */
final class SaisieTresorerieSansSoldeTest extends TestCase
{
    private const SOLDE = 1234567;

    public function testAucunSoldeDansLesComptesProposesSansLeDroit(): void
    {
        $caissiere = new User();
        $comptes = [
            $this->compte('Caisse boutique', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PARTAGEE),
            $this->compte('Orange Money marchand', CompteTresorerie::TYPE_ORANGE_MONEY, CompteTresorerie::PORTEE_PARTAGEE),
            $this->compte('Caisse Aminata', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PERSONNELLE, $caissiere),
            $this->compte('Caisse Moussa', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PERSONNELLE, new User()),
            $this->compte('Banque BDM', CompteTresorerie::TYPE_BANQUE, CompteTresorerie::PORTEE_ADMIN),
        ];

        foreach ($comptes as $compte) {
            foreach ([
                MouvementTresorerieType::libelleSource($compte, false),
                MouvementTresorerieType::libelleDestination($compte, false, false, $caissiere),
                // Même la Direction sans le droit (retiré sur sa fiche) ne voit pas de solde.
                MouvementTresorerieType::libelleDestination($compte, true, false, $caissiere),
            ] as $libelle) {
                self::assertStringStartsWith($compte->getNom().' — ', $libelle);
                self::assertStringNotContainsString('FCFA', $libelle, $libelle);
                self::assertStringNotContainsString('234', $libelle, $libelle);
            }
        }
    }

    public function testAvecLeDroitLesSoldesRestentCommeAvant(): void
    {
        $caissiere = new User();
        $caisse = $this->compte('Caisse boutique', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PARTAGEE);
        $banque = $this->compte('Banque BDM', CompteTresorerie::TYPE_BANQUE, CompteTresorerie::PORTEE_ADMIN);
        $collegue = $this->compte('Caisse Moussa', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PERSONNELLE, new User());

        self::assertSame('Caisse boutique — Caisse — 1 234 567 FCFA', MouvementTresorerieType::libelleSource($caisse, true));
        self::assertSame('Caisse boutique — 1 234 567 FCFA', MouvementTresorerieType::libelleDestination($caisse, false, true, $caissiere));
        // La banque et la caisse d'un collègue restent sans solde pour la caisse, même avec le droit.
        self::assertSame('Banque BDM — Compte bancaire', MouvementTresorerieType::libelleSource($banque, true));
        self::assertSame('Banque BDM — Banque', MouvementTresorerieType::libelleDestination($banque, false, true, $caissiere));
        self::assertSame('Caisse Moussa — Caisse personnelle', MouvementTresorerieType::libelleDestination($collegue, false, true, $caissiere));
        // La Direction voit tout.
        self::assertStringEndsWith('1 234 567 FCFA', MouvementTresorerieType::libelleDestination($banque, true, true, $caissiere));
    }

    public function testTypesSelonLesDroits(): void
    {
        $entreeSortie = [MouvementTresorerie::TYPE_ENCAISSEMENT, MouvementTresorerie::TYPE_DECAISSEMENT];

        self::assertSame($entreeSortie, array_values(MouvementTresorerieType::typesPermis(false, true, false)));
        self::assertSame([MouvementTresorerie::TYPE_TRANSFERT], array_values(MouvementTresorerieType::typesPermis(false, false, true)));
        self::assertSame([...$entreeSortie, MouvementTresorerie::TYPE_TRANSFERT], array_values(MouvementTresorerieType::typesPermis(false, true, true)));
        self::assertSame([...$entreeSortie, MouvementTresorerie::TYPE_TRANSFERT], array_values(MouvementTresorerieType::typesPermis(true, false, false)));
        self::assertSame([], MouvementTresorerieType::typesPermis(false, false, false));
    }

    public function testListesSansLesComptesQueLEnregistrementRefuserait(): void
    {
        $caissiere = new User();
        $sienne = $this->compte('Caisse Aminata', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PERSONNELLE, $caissiere);
        $collegue = $this->compte('Caisse Moussa', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PERSONNELLE, new User());
        $boutique = $this->compte('Caisse boutique', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PARTAGEE);
        $banque = $this->compte('Banque BDM', CompteTresorerie::TYPE_BANQUE, CompteTresorerie::PORTEE_ADMIN);
        $administration = $this->compte('Caisse Administration', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_ADMIN);
        [$enc, $dec, $vir] = [MouvementTresorerie::TYPE_ENCAISSEMENT, MouvementTresorerie::TYPE_DECAISSEMENT, MouvementTresorerie::TYPE_TRANSFERT];
        $types = static fn (CompteTresorerie $c, bool $source, bool $admin, bool $saisir, bool $virer): array => MouvementTresorerieType::typesPourCompte($c, $source, $admin, $saisir, $virer, $caissiere);

        // Caisse avec saisie et virements (demo.aminata) : l'argent ne sort jamais de la banque ni de la caisse d'un collègue.
        self::assertSame([$dec, $vir], $types($sienne, true, false, true, true));
        self::assertSame([$dec, $vir], $types($boutique, true, false, true, true));
        self::assertSame([], $types($banque, true, false, true, true));
        self::assertSame([], $types($collegue, true, false, true, true));
        self::assertSame([], $types($administration, true, false, true, true));
        // Destination : la banque et la caisse d'un collègue pour un virement seulement, jamais la caisse de l'administration.
        self::assertSame([$enc, $vir], $types($sienne, false, false, true, true));
        self::assertSame([$enc, $vir], $types($boutique, false, false, true, true));
        self::assertSame([$vir], $types($banque, false, false, true, true));
        self::assertSame([$vir], $types($collegue, false, false, true, true));
        self::assertSame([], $types($administration, false, false, true, true));

        // Saisie sans virement (demo.moussa) : ni la banque, ni la caisse d'un collègue.
        self::assertSame([$dec], $types($boutique, true, false, true, false));
        self::assertSame([$enc], $types($boutique, false, false, true, false));
        self::assertSame([], $types($banque, false, false, true, false));
        self::assertSame([], $types($collegue, false, false, true, false));

        // Virement seulement : de sa caisse ou d'un compte partagé vers la banque.
        self::assertSame([$vir], $types($boutique, true, false, false, true));
        self::assertSame([$vir], $types($banque, false, false, false, true));

        // La Direction : les comptes administratifs, mais jamais la caisse d'un collègue comme source.
        self::assertSame([$dec, $vir], $types($banque, true, true, true, true));
        self::assertSame([$enc, $vir], $types($administration, false, true, true, true));
        self::assertSame([], $types($collegue, true, true, true, true));
        self::assertSame([$vir], $types($collegue, false, true, true, true));
    }

    public function testRetourApresLaSaisie(): void
    {
        self::assertSame('app_mouvement_tresorerie_index', MouvementTresorerieController::routeApresSaisie(true, true, true));
        // Sans la trésorerie : la caisse d'abord, puis les dépenses, sinon l'accueil.
        self::assertSame('app_caisse_index', MouvementTresorerieController::routeApresSaisie(false, true, true));
        self::assertSame('app_finances_depenses', MouvementTresorerieController::routeApresSaisie(false, false, true));
        self::assertSame('app_home', MouvementTresorerieController::routeApresSaisie(false, false, false));
    }

    public function testConfirmationSansSolde(): void
    {
        $caisse = $this->compte('Caisse boutique', CompteTresorerie::TYPE_CAISSE, CompteTresorerie::PORTEE_PARTAGEE);
        $banque = $this->compte('Banque BDM', CompteTresorerie::TYPE_BANQUE, CompteTresorerie::PORTEE_ADMIN);

        $virement = (new MouvementTresorerie())->setType(MouvementTresorerie::TYPE_TRANSFERT)->setMontant(150000)
            ->setCompteSource($caisse)->setCompteDestination($banque)->setReference('MVT-TEST-1');
        self::assertSame('Virement de 150 000 F de « Caisse boutique » vers « Banque BDM » enregistré (MVT-TEST-1).', MouvementTresorerieController::confirmationSansSolde($virement));

        $entree = (new MouvementTresorerie())->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)->setMontant(5000)
            ->setCompteDestination($caisse)->setReference('MVT-TEST-2');
        self::assertSame('Entrée de 5 000 F sur « Caisse boutique » enregistrée (MVT-TEST-2).', MouvementTresorerieController::confirmationSansSolde($entree));

        $sortie = (new MouvementTresorerie())->setType(MouvementTresorerie::TYPE_DECAISSEMENT)->setMontant(2500)
            ->setCompteSource($caisse)->setReference('MVT-TEST-3');
        $message = MouvementTresorerieController::confirmationSansSolde($sortie);
        self::assertSame('Sortie de 2 500 F de « Caisse boutique » enregistrée (MVT-TEST-3).', $message);
        self::assertStringNotContainsString('234', $message);
    }

    private function compte(string $nom, string $type, string $portee, ?User $proprietaire = null): CompteTresorerie
    {
        return (new CompteTresorerie())->setNom($nom)->setType($type)->setPortee($portee)
            ->setProprietaire($proprietaire)->setSoldeActuel(self::SOLDE);
    }
}
