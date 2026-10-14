<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\Clients;
use App\Entity\CompteurIaJour;
use App\Entity\CreditIa;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Entity\MouvementCreditIa;
use App\Entity\SupportConception;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/**
 * Demande de conception et synchronisation : ce qui monte, ce qui
 * descend, et ce qui ne quitte jamais son serveur (coût d'une image,
 * images IA, solde calculé, graphiste qui a pris la demande).
 */
final class ConceptionSynchroTest extends TestCase
{
    public function testSensDesTypes(): void
    {
        $types = CatalogueSynchro::types();

        self::assertSame(CatalogueSynchro::DESCENDANT, $types['support_conception']['sens']);
        self::assertSame(CatalogueSynchro::DEUX, $types['demande_conception']['sens']);
        self::assertSame(CatalogueSynchro::ASCENDANT, $types['mouvement_credit_ia']['sens']);

        // Les supports se règlent au local ; le relevé du crédit ne fait que monter.
        self::assertFalse(CatalogueSynchro::envoye('support_conception', true));
        self::assertTrue(CatalogueSynchro::accepte('support_conception', true));
        self::assertTrue(CatalogueSynchro::envoye('mouvement_credit_ia', true));
        self::assertFalse(CatalogueSynchro::envoye('mouvement_credit_ia', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('demande_conception', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('mouvement_credit_ia', false));
    }

    public function testListeBlancheDesChamps(): void
    {
        $types = CatalogueSynchro::types();
        $demande = CatalogueSynchro::champsSuivis('demande_conception');

        foreach (['prisePar', 'compte', 'etape', 'modifieLe'] as $champ) {
            self::assertNotContains($champ, $demande, $champ.' reste de son côté');
        }
        self::assertSame([], $types['demande_conception']['remontees'] ?? [], 'le suivi d\'une demande envoyée se décide au local');
        self::assertSame(['priseEnChargeLe' => null, 'termineeLe' => null], $types['demande_conception']['imposeAuLocal']);
        self::assertSame(['type', 'montant', 'images', 'libelle', 'auteur', 'etat', 'cle', 'creeLe'], $types['mouvement_credit_ia']['champs']);

        // Ni les images IA, ni le solde calculé, ni le compteur du jour ne voyagent.
        self::assertNull(CatalogueSynchro::typeDe(new ImageIa(DemandeConception::nouvelle(new Clients(), null, new SupportConception(), new \DateTimeImmutable()), 1, null, 'p', 'demo', 'x', 1000, new \DateTimeImmutable())));
        self::assertNull(CatalogueSynchro::typeDe(new CreditIa(new Clients())));
        self::assertNull(CatalogueSynchro::typeDe(new CompteurIaJour(new \DateTimeImmutable())));
    }

    public function testCoutDUneImageResteAuLocal(): void
    {
        self::assertNotContains('cout_image', CatalogueSynchro::REGLAGES_PUBLICS['conception']);
        $publics = CatalogueSynchro::reglagesPublics('conception', ['cout_image' => 250, 'prix_image' => 1500, 'packs' => '2, 4']);

        self::assertArrayNotHasKey('cout_image', $publics);
        self::assertSame(1500, $publics['prix_image']);
        self::assertSame('2, 4', $publics['packs']);
        self::assertSame(5000, $publics['finalisation']);
    }

    public function testRienNePartAvantLEnvoi(): void
    {
        $types = CatalogueSynchro::types();
        $demande = DemandeConception::nouvelle(new Clients(), null, (new SupportConception())->setCourt('Flyer'), new \DateTimeImmutable());

        self::assertFalse(($types['demande_conception']['pret'])($demande), 'un brouillon reste en ligne');
        $demande->envoyer(DemandeConception::RECUE, new \DateTimeImmutable());
        self::assertTrue(($types['demande_conception']['pret'])($demande));

        $achat = MouvementCreditIa::nouveau(new Clients(), MouvementCreditIa::ACHAT, 3000, 3, 'Crédit IA · 3 images', null, null, new \DateTimeImmutable(), MouvementCreditIa::EN_ATTENTE);
        self::assertFalse(($types['mouvement_credit_ia']['pret'])($achat), 'un pack pas encore payé ne monte pas');
        $achat->acquerir();
        self::assertTrue(($types['mouvement_credit_ia']['pret'])($achat));
    }
}
