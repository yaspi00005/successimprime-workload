<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\AvisClient;
use App\Entity\Realisation;
use App\Service\Synchro\CatalogueSynchro;
use App\Service\Synchro\Synchro;
use PHPUnit\Framework\TestCase;

/** Contenu du site vitrine : saisi au local, descendu champ par champ, sans rien de privé. */
final class SynchroSiteTest extends TestCase
{
    private const TYPES = ['site_image', 'site_page', 'site_realisation', 'site_avis', 'site_article', 'site_partenaire', 'site_equipe', 'site_reglage'];

    public function testDescendSeulement(): void
    {
        $types = CatalogueSynchro::types();
        foreach (self::TYPES as $type) {
            self::assertArrayHasKey($type, $types);
            self::assertSame(CatalogueSynchro::DESCENDANT, $types[$type]['sens'], $type);
            self::assertTrue(CatalogueSynchro::envoye($type, false), $type.' part du local');
            self::assertFalse(CatalogueSynchro::envoye($type, true), $type.' ne remonte jamais');
            self::assertFalse(CatalogueSynchro::accepte($type, false), $type.' : le local refuse un contenu venu du site');
            self::assertSame($type, CatalogueSynchro::typeDe(new ($types[$type]['classe'])()));
        }
    }

    public function testChampsListes(): void
    {
        $types = CatalogueSynchro::types();
        $attendus = [
            'site_image' => ['contenu', 'type', 'largeur', 'hauteur', 'empreinte', 'usage', 'texteAlternatif', 'ordre', 'creeLe'],
            'site_page' => ['slug', 'titre', 'accroche', 'description', 'corps', 'publie', 'ordre', 'modifieLe'],
            'site_realisation' => ['titre', 'categorieRef', 'description', 'nomClient', 'accordClient', 'publie', 'dateRealisation'],
            'site_avis' => ['nomAffiche', 'texte', 'note', 'source', 'dateAvis', 'accordClient', 'publie'],
            'site_article' => ['titre', 'slug', 'rubrique', 'resume', 'corps', 'publieLe', 'publie', 'modifieLe'],
            'site_partenaire' => ['nom', 'lien', 'ordre', 'visible', 'accordObtenu'],
            'site_equipe' => ['nom', 'poste', 'ordre', 'visible', 'accordPersonne'],
            'site_reglage' => ['cle', 'valeurs'],
        ];
        foreach ($attendus as $type => $champs) {
            self::assertSame($champs, $types[$type]['champs'], $type.' : un nouveau champ ne descend que si on l\'ajoute ici');
        }
        // La réalisation ne cite pas la catégorie (chargée plus loin) : son identifiant public suffit.
        self::assertSame([], $types['site_realisation']['relations']);
        self::assertSame(['images' => 'site_image'], $types['site_realisation']['listes']);
    }

    public function testNomEtTexteSansAccordRestentAuLocal(): void
    {
        $types = CatalogueSynchro::types();

        $filtre = $types['site_realisation']['filtres']['nomClient'];
        $realisation = (new Realisation())->setTitre('Vitrine')->setNomClient('Pharmacie du Fleuve');
        self::assertNull($filtre('Pharmacie du Fleuve', $realisation));
        self::assertSame('Pharmacie du Fleuve', $filtre('Pharmacie du Fleuve', $realisation->setAccordClient(true)));

        $avis = (new AvisClient())->setNomAffiche('Awa D.')->setTexte('Très bien.');
        foreach (['nomAffiche' => 'Awa D.', 'texte' => 'Très bien.'] as $champ => $valeur) {
            self::assertSame('', ($types['site_avis']['filtres'][$champ])($valeur, $avis), $champ);
        }
        $avis->setAccordClient(true);
        self::assertSame('Très bien.', ($types['site_avis']['filtres']['texte'])('Très bien.', $avis));
    }

    public function testLesImagesPassentAvantCeQuiLesCite(): void
    {
        $ordre = array_keys(CatalogueSynchro::types());
        $image = array_search('site_image', $ordre, true);
        self::assertSame(array_search('entreprise', $ordre, true) + 1, $image, 'juste après l\'entreprise');
        foreach (['site_page', 'site_realisation', 'site_article', 'site_partenaire', 'site_equipe'] as $type) {
            self::assertGreaterThan($image, array_search($type, $ordre, true), $type);
        }
    }

    public function testUnLotTientDansUnEnvoi(): void
    {
        $corpsMax = (new \ReflectionClassConstant(\App\Controller\Api\SynchroApiController::class, 'CORPS_MAX'))->getValue();
        // Une image de 300 Ko en base64 fait 400 Ko : un lot plein reste loin de la limite d'un envoi.
        self::assertLessThan($corpsMax - 2 * 1024 * 1024, Synchro::LOT_OCTETS);
    }
}
