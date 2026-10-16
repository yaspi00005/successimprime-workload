<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\ProduitConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Ancienne ligne au m² enregistrée en mode « automatique » (CMD-D00744) :
 * la réenregistrer ne doit ni effacer ni changer sa largeur et sa longueur.
 */
final class LigneAuMetreCarreTest extends TestCase
{
    /** Configuration du catalogue qui n'impose aucune dimension (mode « format » par défaut). */
    private static function configuration(): ProduitConfiguration
    {
        return (new ProduitConfiguration())->setModeCalcul('metre_carre')->setPrixBase(3500);
    }

    public function testCalculerTotauxGardeLesDimensionsDuneLigneAutomatique(): void
    {
        $ligne = (new CommandesDetails())
            ->setModeConfiguration('automatique')
            ->setProduitConfiguration(self::configuration())
            ->setDesignation('Bâche imprimée')
            ->setModeCalcul('metre_carre')
            ->setLargeur(3)
            ->setLongueur(1.5)
            ->setQuantite(1)
            ->setPrixUnitaire(3000);

        $ligne->calculerTotaux(true);

        // Avant : la configuration réappliquée vidait largeur et longueur, et le prix passait à 3 500.
        self::assertSame(3.0, (float) $ligne->getLargeur());
        self::assertSame(1.5, (float) $ligne->getLongueur());
        self::assertSame(4.5, (float) $ligne->getSurface());
        self::assertSame(3000.0, (float) $ligne->getPrixUnitaire());
        self::assertSame(13500, $ligne->getTotalHt());
    }

    public function testDevisCalculerTotauxGardeLesDimensions(): void
    {
        $ligne = (new DevisDetails())
            ->setModeConfiguration('automatique')
            ->setProduitConfiguration(self::configuration())
            ->setDesignation('Bâche façade')
            ->setModeCalcul('metre_carre')
            ->setLargeur(6)
            ->setLongueur(3)
            ->setQuantite(1)
            ->setPrixUnitaire(3000);

        $ligne->calculerTotaux();

        self::assertSame(6.0, (float) $ligne->getLargeur());
        self::assertSame(3.0, (float) $ligne->getLongueur());
        self::assertSame(54000, $ligne->getTotalHt());
    }

    public function testLeMessageNommeLaLigneDeCommande(): void
    {
        $commande = new Commandes();
        $commande->addCommandesDetail((new CommandesDetails())->setDesignation('Flyers A5')->setModeCalcul('unite')->setPrixUnitaire(50));
        $bache = (new CommandesDetails())->setDesignation('Bâche imprimée')->setModeCalcul('metre_carre')->setPrixUnitaire(3500);
        $commande->addCommandesDetail($bache);

        $messages = $this->messages(static fn (ExecutionContextInterface $c) => $bache->validerModeCommande($c));

        self::assertContains('Ligne 2 « Bâche imprimée » : la largeur et la longueur sont obligatoires pour un calcul au mètre carré.', $messages);
    }

    public function testLeMessageNommeLaLigneDeDevis(): void
    {
        $devis = new Devis();
        $ligne = (new DevisDetails())->setDesignation('Banderole')->setModeCalcul('metre')->setPrixUnitaire(2000);
        $devis->addDevisDetail($ligne);

        $messages = $this->messages(static fn (ExecutionContextInterface $c) => $ligne->validerModeDevis($c));

        self::assertContains('Ligne 1 « Banderole » : la longueur est obligatoire pour un calcul au mètre.', $messages);
    }

    public function testTotauxDuDevisRefaitsDepuisLesLignes(): void
    {
        $devis = (new Devis())->setRemise(1000)->setTotalTtc(39000);
        $devis->addDevisDetail((new DevisDetails())->setModeCalcul('unite')->setQuantite(2)->setPrixUnitaire(5000)->calculerTotaux());
        $devis->addDevisDetail((new DevisDetails())->setModeCalcul('metre_carre')->setLargeur(2)->setLongueur(1)->setQuantite(1)->setPrixUnitaire(3000)->calculerTotaux());

        $devis->recalculerTotaux();

        // Le total envoyé par le navigateur (39 000) n'est pas repris ; la
        // remise globale est déduite du total (TotauxDocument).
        self::assertSame(15000, $devis->getTotalTtc());
        self::assertSame(15000, $devis->getMontantApayer());
    }

    /**
     * Messages des violations, paramètres remplacés.
     *
     * @return list<string>
     */
    private function messages(callable $valider): array
    {
        $messages = [];
        $contexte = $this->createStub(ExecutionContextInterface::class);
        $contexte->method('buildViolation')->willReturnCallback(function (string $message) use (&$messages) {
            $parametres = [];
            $constructeur = $this->createStub(ConstraintViolationBuilderInterface::class);
            $constructeur->method('setParameter')->willReturnCallback(function (string $cle, string $valeur) use (&$parametres, &$constructeur) {
                $parametres[$cle] = $valeur;

                return $constructeur;
            });
            $constructeur->method('atPath')->willReturnSelf();
            $constructeur->method('addViolation')->willReturnCallback(function () use (&$messages, &$parametres, $message): void {
                $messages[] = strtr($message, $parametres);
            });

            return $constructeur;
        });

        $valider($contexte);

        return $messages;
    }
}
