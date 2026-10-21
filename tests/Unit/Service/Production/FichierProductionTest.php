<?php

namespace App\Tests\Unit\Service\Production;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Finition;
use App\Entity\OrdreProduction;
use App\Entity\Supports;
use App\Service\Production\FichierProduction;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

final class FichierProductionTest extends TestCase
{
    /** La ligne de la maquette PD01 : 2 bâches 300 × 100, ourlet et œillets tous les 50 cm. */
    public function testNomDeLaMaquettePd01(): void
    {
        $commande = (new Commandes())->setNumero('CMD-000183-10-2026');
        $ligne = $this->ligne('Bâche 440 g roll-up', '3.00', '1.00', 2, ['Ourlet', 'Œillets tous les 50 cm']);
        $commande->addCommandesDetail($ligne);
        $ordre = (new OrdreProduction())->setCommandeDetail($ligne);

        FichierProduction::preparer($ordre);

        self::assertSame('CMD-000183_L1_BACHE440_300x100_x2_OURLET-OEIL50.pdf', $ordre->getNomFichierProduction());
        self::assertNull($ordre->getNomFichierDecoupe());
    }

    public function testCodesReglesEtFichierDeDecoupe(): void
    {
        $commande = (new Commandes())->setNumero('CMD-000430-10-2026');
        $commande->addCommandesDetail($this->ligne('Vinyle', '0.5', '0.5', 1, []));
        $ligne = $this->ligne('Bâche 440 g frontlit', '1.20', '2.00', 1, ['Fourreau haut et bas']);
        $ligne->getSupport()?->setCodeProduction('bache-fl 440');
        $commande->addCommandesDetail($ligne);
        $ordre = (new OrdreProduction())->setCommandeDetail($ligne);
        $ordre->addFichier($this->fichier('Kakemono Koita.PNG', 'impression'));
        $ordre->addFichier($this->fichier('contour.svg', 'decoupe'));

        FichierProduction::preparer($ordre);

        // Ligne 2 de la commande ; code réglé à la main (tiret et espace retirés) ; extension du fichier source.
        self::assertSame('CMD-000430_L2_BACHEFL440_120x200_x1_FOURREAU.png', $ordre->getNomFichierProduction());
        self::assertSame('CMD-000430_L2_BACHEFL440_120x200_x1_FOURREAU_decoupe.svg', $ordre->getNomFichierDecoupe());
    }

    public function testCodeDeProductionJamaisSynchronise(): void
    {
        foreach (CatalogueSynchro::types() as $type => $definition) {
            self::assertNotContains('codeProduction', $definition['champs'] ?? [], $type);
        }
    }

    /** @param list<string> $finitions */
    private function ligne(string $support, string $largeur, string $longueur, int $quantite, array $finitions): CommandesDetails
    {
        $ligne = (new CommandesDetails())
            ->setSupport((new Supports())->setNom($support))
            ->setLargeur($largeur)
            ->setLongueur($longueur)
            ->setQuantite($quantite);
        foreach ($finitions as $nom) {
            $ligne->addFinition((new CommandeDetailFinition())->setFinition((new Finition())->setNom($nom))->setNomFinition($nom));
        }

        return $ligne;
    }

    private function fichier(string $nom, string $role): CommandeDetailFichier
    {
        return (new CommandeDetailFichier())->setNomOriginal($nom)->setNomStockage('x')->setRole($role)->setActif(true);
    }
}
