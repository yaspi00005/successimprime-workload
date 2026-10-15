<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Entity\CreationAtelier;
use App\Entity\ModeleAtelier;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Service\Atelier\CreationsAtelier;
use PHPUnit\Framework\TestCase;

/**
 * Articles et créations de l'Atelier 3D (docs/atelier-3d.md) : ce que la
 * page envoie est ramené aux valeurs permises, jamais cru tel quel.
 */
final class CreationAtelierTest extends TestCase
{
    private static function tshirt(): ModeleAtelier
    {
        return ModeleAtelier::pour(new ProduitConfiguration(), ModeleAtelier::TSHIRT)
            ->setCouleurs([['nom' => 'Noir', 'hex' => '#1F2026'], ['nom' => 'Blanc', 'hex' => '#F4F4F1']]);
    }

    public function testFormeDevineeSansConfondreCapuche(): void
    {
        self::assertSame(ModeleAtelier::CASQUETTE, ModeleAtelier::formeDevinee('Casquette 6 panneaux'));
        self::assertSame(ModeleAtelier::CASQUETTE, ModeleAtelier::formeDevinee('Cap brodée'));
        self::assertSame(ModeleAtelier::POLO, ModeleAtelier::formeDevinee('Polo piqué'));
        self::assertSame(ModeleAtelier::TSHIRT, ModeleAtelier::formeDevinee('Sweat à capuche'));
        self::assertSame(ModeleAtelier::TSHIRT, ModeleAtelier::formeDevinee('T-shirt coton'));
    }

    public function testCouleursEtTaillesTapeesDansLesReglages(): void
    {
        $couleurs = ModeleAtelier::lireCouleurs("Noir #1f2026\nBleu Succès : 0F4590\nsans code\nnoir #000000\n  Rouge;#de1028  ");
        self::assertSame([
            ['nom' => 'Noir', 'hex' => '#1F2026'],
            ['nom' => 'Bleu Succès', 'hex' => '#0F4590'],
            ['nom' => 'Rouge', 'hex' => '#DE1028'],
        ], $couleurs, 'lignes illisibles et doublons écartés');
        self::assertSame(['S', 'M', 'XL', '3XL'], ModeleAtelier::lireTailles('s, M ;xl  M 3xl'));
        self::assertCount(ModeleAtelier::TAILLES_MAX, ModeleAtelier::lireTailles(implode(' ', range(1, 30))));
    }

    public function testNouvelArticleAvecLesValeursDuPrototype(): void
    {
        $polo = ModeleAtelier::pour(new ProduitConfiguration(), ModeleAtelier::POLO);
        self::assertSame([24.0, 30.0], [$polo->getZoneLargeurCm(), $polo->getZoneHauteurCm()]);
        self::assertTrue($polo->aDesTailles());
        self::assertFalse(ModeleAtelier::pour(new ProduitConfiguration(), ModeleAtelier::CASQUETTE)->aDesTailles());
        self::assertSame(ModeleAtelier::TSHIRT, (new ModeleAtelier())->setForme('robe')->getForme());
    }

    public function testReglagesDUneFaceRamenesAuxValeursPermises(): void
    {
        self::assertNull(CreationAtelier::reglagesFace(['texte' => '  '], false, null, ModeleAtelier::TSHIRT, 'devant'), 'face vide : pas gardée');
        $r = CreationAtelier::reglagesFace([
            'texte' => "TEAM\x07 SUCCÈS ".str_repeat('X', 40),
            'taille' => 400, 'hauteur' => -90,
            'police' => 'Comic Sans', 'couleurTexte' => 'Violet', 'placeTexte' => 'dessus',
            'position' => 'dos-haut', 'gras' => 'oui',
        ], true, 'logo.png', ModeleAtelier::TSHIRT, 'devant');
        self::assertNotNull($r);
        self::assertSame(CreationAtelier::TEXTE_MAX, mb_strlen($r['texte']));
        self::assertStringStartsWith('TEAM  SUCCÈS', $r['texte'], 'caractères de contrôle remplacés');
        self::assertSame(100, $r['taille']);
        self::assertSame(-40, $r['hauteur']);
        self::assertSame(CreationAtelier::POLICES[0], $r['police']);
        self::assertSame('Blanc', $r['couleurTexte']);
        self::assertSame('dessus', $r['placeTexte']);
        self::assertSame('logo.png', $r['nomImage']);
        self::assertSame('devant-centre', $r['position'], 'emplacement du dos refusé devant : celui des créations d\'avant le studio');
        self::assertFalse($r['gras'], 'seul un vrai oui (true) met le texte en gras');

        $r = CreationAtelier::reglagesFace(['texte' => 'SI', 'position' => 'poitrine-gauche', 'gras' => true, 'police' => 'Montserrat'], false, null, ModeleAtelier::POLO, 'devant');
        self::assertSame(['poitrine-gauche', true, 'Montserrat'], [$r['position'] ?? null, $r['gras'] ?? null, $r['police'] ?? null]);
        self::assertSame('devant', CreationAtelier::reglagesFace(['texte' => 'SI', 'position' => 'poitrine-gauche'], false, null, ModeleAtelier::CASQUETTE, 'devant')['position'] ?? null);
        self::assertSame('cote', CreationAtelier::reglagesFace(['texte' => 'SI', 'position' => 'cote'], false, null, ModeleAtelier::CASQUETTE, 'dos')['position'] ?? null);
    }

    public function testZoneImprimeeSelonLEmplacement(): void
    {
        // T-shirt, zone 28 × 35 cm : un logo de poitrine tient dans 10 × 10 cm, le dos haut dans une bande de 10 cm.
        self::assertSame([28.0, 35.0], CreationAtelier::zoneImpression(ModeleAtelier::TSHIRT, 'devant', 'devant-centre', 28.0, 35.0));
        self::assertSame([10.0, 10.0], CreationAtelier::zoneImpression(ModeleAtelier::TSHIRT, 'devant', 'poitrine-droite', 28.0, 35.0));
        self::assertSame([28.0, 10.0], CreationAtelier::zoneImpression(ModeleAtelier::TSHIRT, 'dos', 'dos-haut', 28.0, 35.0));
        self::assertSame([28.0, 35.0], CreationAtelier::zoneImpression(ModeleAtelier::TSHIRT, 'dos', null, 28.0, 35.0), 'création d\'avant le studio : toute la zone');
        // La zone de l'article borne toujours : une petite zone de 8 × 6 cm reste 8 × 6.
        self::assertSame([8.0, 6.0], CreationAtelier::zoneImpression(ModeleAtelier::POLO, 'devant', 'poitrine-gauche', 8.0, 6.0));
        self::assertSame([6.0, 4.0], CreationAtelier::zoneImpression(ModeleAtelier::CASQUETTE, 'dos', 'cote', 10.0, 6.0));
        self::assertSame([8.0, 4.0], CreationAtelier::zoneImpression(ModeleAtelier::CASQUETTE, 'dos', 'inconnu', 10.0, 6.0));

        // La même image passe sur la poitrine et devient juste sur tout le devant.
        $modele = self::tshirt();
        $poitrine = CreationsAtelier::analyse(600, 600, $modele, 60, 'devant', 'poitrine-gauche');
        $devant = CreationsAtelier::analyse(600, 600, $modele, 60, 'devant', 'devant-centre');
        self::assertSame('ok', $poitrine[1]['etat']);
        self::assertStringContainsString('6 × 6 cm', $poitrine[1]['libelle']);
        self::assertSame('erreur', $devant[1]['etat']);
    }

    public function testTailleDeLImageAvecOuSansTexte(): void
    {
        self::assertSame(60, CreationAtelier::tailleImage(['taille' => 60, 'texte' => '']));
        self::assertSame(49, CreationAtelier::tailleImage(['taille' => 60, 'texte' => 'TEAM']), 'la place du texte est retirée, comme dans la page');
        self::assertSame(15, CreationAtelier::tailleImage(['taille' => 2]));
    }

    public function testQuantitesSeulementPourLesTaillesDeLArticle(): void
    {
        self::assertSame(['S' => 5, 'L' => CreationAtelier::QUANTITE_TAILLE_MAX], CreationAtelier::quantitesParTaille(
            ['S' => '5', 'M' => '0', 'L' => '999999', 'XXXXL' => 50, 'XL' => -3],
            ['S', 'M', 'L', 'XL'],
        ));
    }

    public function testLirePrendLesPrixDuServeurEtRefuseLesChoixHorsListe(): void
    {
        $lu = CreationsAtelier::lire(self::tshirt(), [
            'couleur' => 'Noir',
            'bords' => 'Blanc', // pas de bords sur un t-shirt
            'tailles' => ['M' => 10, 'L' => 5, 'XXXL' => 9],
            'prix' => 1, 'total' => 1, // ignorés : le prix est toujours recalculé
            'faces' => json_encode(['devant' => ['texte' => 'BAMAKO', 'image' => null, 'position' => 'poitrine-gauche'], 'dos' => ['texte' => '']]),
        ]);
        self::assertSame(15, $lu['quantite']);
        self::assertSame(['M' => 10, 'L' => 5], $lu['tailles']);
        self::assertSame(['devant'], array_keys($lu['faces']));
        self::assertSame('poitrine-gauche', $lu['faces']['devant']['reglages']['position']);
        self::assertNull($lu['visiere']);
        self::assertArrayNotHasKey('prix', $lu);

        foreach ([
            [['couleur' => 'Fuchsia', 'tailles' => ['M' => 1], 'faces' => '{"devant":{"texte":"A"}}'], 'couleur'],
            [['couleur' => 'Noir', 'tailles' => ['M' => 0], 'faces' => '{"devant":{"texte":"A"}}'], 'pièce'],
            [['couleur' => 'Noir', 'tailles' => ['M' => 2], 'faces' => 'pas du json'], 'face'],
        ] as [$donnees, $mot]) {
            try {
                CreationsAtelier::lire(self::tshirt(), $donnees);
                self::fail('refus attendu : '.$mot);
            } catch (\DomainException $e) {
                self::assertStringContainsString($mot, $e->getMessage());
            }
        }
    }

    public function testCasquetteSansTaillesAvecSaVisiere(): void
    {
        $cap = ModeleAtelier::pour(new ProduitConfiguration(), ModeleAtelier::CASQUETTE)
            ->setCouleurs([['nom' => 'Noir', 'hex' => '#1F2026'], ['nom' => 'Rouge', 'hex' => '#DE1028']]);
        $lu = CreationsAtelier::lire($cap, ['couleur' => 'Rouge', 'visiere' => 'Inconnue', 'quantite' => '30', 'faces' => '{"devant":{"texte":"SI"}}']);
        self::assertSame(30, $lu['quantite']);
        self::assertSame('Rouge', $lu['visiere']['nom'], 'visière inconnue : la couleur du tissu');

        $c = (new CreationAtelier())->setForme(ModeleAtelier::CASQUETTE)->setCouleur($lu['couleur'])->setCouleurVisiere(['nom' => 'Noir', 'hex' => '#1F2026'])
            ->setQuantite(30)->setNumero('W-CR-0009')
            ->setReglages(['dos' => CreationAtelier::reglagesFace(['texte' => 'ÉQUIPE'], false, null, ModeleAtelier::CASQUETTE, 'dos')]);
        self::assertSame('Rouge · visière Noir', $c->libelleCouleur());
        self::assertSame('30 pièces', $c->resumeTailles());
        self::assertSame(['Arrière : texte « ÉQUIPE » (Montserrat, blanc, dessous)'], $c->resumeMarquage());
        self::assertSame('Arrière', $c->libelleFace('dos'));

        $c->setReglages(['dos' => CreationAtelier::reglagesFace(['texte' => 'SI', 'position' => 'cote', 'gras' => true], false, null, ModeleAtelier::CASQUETTE, 'dos')]);
        self::assertSame('Côté', $c->libelleFace('dos'), 'la vue de l\'arrière montre le côté marqué');
        self::assertSame(['Côté : texte « SI » (Montserrat gras, blanc, dessous)'], $c->resumeMarquage());
    }

    public function testPoloAvecOuSansBordsDeCouleur(): void
    {
        $polo = ModeleAtelier::pour(new ProduitConfiguration(), ModeleAtelier::POLO)
            ->setCouleurs([['nom' => 'Blanc', 'hex' => '#F4F4F1'], ['nom' => 'Orange', 'hex' => '#E95B35']]);
        $faces = json_encode(['devant' => ['texte' => 'SI', 'position' => 'poitrine-gauche']]);
        $lu = CreationsAtelier::lire($polo, ['couleur' => 'Blanc', 'bords' => 'Orange', 'tailles' => ['M' => 3], 'faces' => $faces]);
        self::assertSame('Orange', $lu['visiere']['nom'] ?? null);
        self::assertNull(CreationsAtelier::lire($polo, ['couleur' => 'Blanc', 'bords' => 'Fuchsia', 'tailles' => ['M' => 3], 'faces' => $faces])['visiere'], 'bords inconnus : sans bord de couleur');
        self::assertNull(CreationsAtelier::lire($polo, ['couleur' => 'Blanc', 'tailles' => ['M' => 3], 'faces' => $faces])['visiere']);

        $c = (new CreationAtelier())->setForme(ModeleAtelier::POLO)->setCouleur($lu['couleur'])->setCouleurVisiere($lu['visiere'])
            ->setReglages(['devant' => $lu['faces']['devant']['reglages']]);
        self::assertSame('Blanc · bords Orange', $c->libelleCouleur());
        self::assertSame(['Poitrine gauche : texte « SI » (Montserrat, blanc, dessous)'], $c->resumeMarquage());
        self::assertSame('Devant', $c->libelleFace('devant'));
    }

    public function testCreationDAvantLeStudioGardeSesLibelles(): void
    {
        // Réglages enregistrés avant le studio : ni emplacement ni gras.
        $c = (new CreationAtelier())->setForme(ModeleAtelier::TSHIRT)->setCouleur(['nom' => 'Noir', 'hex' => '#1F2026'])
            ->setReglages(['dos' => ['image' => false, 'nomImage' => null, 'taille' => 60, 'hauteur' => 0, 'texte' => 'DIALLO 10', 'police' => 'Bebas Neue', 'couleurTexte' => 'Jaune', 'placeTexte' => 'dessous']]);
        self::assertSame(['Dos : texte « DIALLO 10 » (Bebas Neue, jaune, dessous)'], $c->resumeMarquage());
    }

    public function testNotePourLAtelierSansMontant(): void
    {
        $c = (new CreationAtelier())->setForme(ModeleAtelier::TSHIRT)->setCouleur(['nom' => 'Blanc', 'hex' => '#F4F4F1'])
            ->setTailles(['S' => 5, 'M' => 10])->setQuantite(15)->setNumero('W-CR-0001')
            ->setReglages([
                'devant' => CreationAtelier::reglagesFace(['taille' => 60, 'texte' => 'TEAM', 'position' => 'poitrine-gauche', 'police' => 'Archivo Black'], true, 'logo.png', ModeleAtelier::TSHIRT, 'devant'),
                'dos' => CreationAtelier::reglagesFace(['texte' => 'DIALLO 10', 'police' => 'Bebas Neue', 'gras' => true, 'couleurTexte' => 'Jaune'], false, null, ModeleAtelier::TSHIRT, 'dos'),
            ]);
        self::assertSame(['devant', 'dos'], $c->facesMarquees());
        $note = $c->note();
        self::assertStringContainsString('Atelier 3D W-CR-0001 : Blanc, S × 5, M × 10.', $note);
        self::assertStringContainsString('Poitrine gauche : image « logo.png » à 60 % + texte « TEAM » (Archivo Black, blanc, dessous)', $note);
        self::assertStringContainsString('Dos centre : texte « DIALLO 10 » (Bebas Neue gras, jaune, dessous)', $note);
        self::assertDoesNotMatchRegularExpression('/\d\s?F\b|FCFA/u', $note, 'pas de montant dans la note');
    }

    public function testDesignationSansLeFormatDuCatalogue(): void
    {
        self::assertSame('Article de l\'Atelier 3D', CreationsAtelier::designation(null));
        $config = (new ProduitConfiguration())
            ->setProduit((new Produits())->setNom('T-shirt personnalisé DTF'))
            ->setSupport((new Supports())->setNom('T-shirt coton'));
        self::assertSame('T-shirt personnalisé DTF · T-shirt coton', CreationsAtelier::designation($config));
        $config->setSupport((new Supports())->setNom('dtf'));
        self::assertSame('T-shirt personnalisé DTF', CreationsAtelier::designation($config), 'support déjà dans le nom');
    }
}
