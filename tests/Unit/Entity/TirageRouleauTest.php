<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Machines;
use App\Entity\TirageRouleau;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Rouleau envoyé au RIP puis replacé : il redevient « Préparé » et attend
 * un nouvel envoi avant « Démarrer » (docs/production-rouleau.md).
 */
final class TirageRouleauTest extends TestCase
{
    public function testUnPlacementChangeApresLEnvoiDemandeDeRenvoyer(): void
    {
        $le = new \DateTimeImmutable('2026-10-21 09:00');
        $par = new User();
        $tirage = new TirageRouleau('R-1021-01', new Machines(), 'Bâche 440 g', 'BACHE440', 3.2, 10, 4, $par, $le);
        self::assertFalse($tirage->aRenvoyer(), 'jamais envoyé : rien à renvoyer');

        $tirage->enregistrerEnvoi(TirageRouleau::ENVOI_UNIQUE, ['nom' => 'R-1021-01.pdf', 'chemin' => 'production/rouleaux/r.pdf', 'empreinte' => 'abc', 'taille' => 10], null, $par, $le);
        self::assertSame(TirageRouleau::STATUT_ENVOYE, $tirage->getStatut());
        self::assertFalse($tirage->aRenvoyer());

        $tirage->placementChange($le->modify('+5 minutes'));
        self::assertSame(TirageRouleau::STATUT_PREPARE, $tirage->getStatut());
        self::assertTrue($tirage->aRenvoyer());

        // « Démarrer » refuse tant que le nouveau fichier n'est pas parti.
        $this->expectException(\LogicException::class);
        $tirage->demarrer($par, $le->modify('+10 minutes'));
    }

    public function testRenvoyerRendLeRouleauDemarrable(): void
    {
        $le = new \DateTimeImmutable('2026-10-21 09:00');
        $par = new User();
        $tirage = new TirageRouleau('R-1021-02', new Machines(), 'Bâche 440 g', 'BACHE440', 3.2, 10, 4, $par, $le);
        $fichier = ['nom' => 'R-1021-02.pdf', 'chemin' => 'production/rouleaux/r.pdf', 'empreinte' => 'abc', 'taille' => 10];
        $tirage->enregistrerEnvoi(TirageRouleau::ENVOI_UNIQUE, $fichier, null, $par, $le);
        $tirage->placementChange($le);
        $tirage->enregistrerEnvoi(TirageRouleau::ENVOI_UNIQUE, $fichier, null, $par, $le);

        self::assertFalse($tirage->aRenvoyer());
        self::assertSame(2, $tirage->getEnvois()[0]['version']);
        $tirage->demarrer($par, $le);
        self::assertSame(TirageRouleau::STATUT_EN_IMPRESSION, $tirage->getStatut());
        // Une fois en impression, un changement de placement ne la remet pas en « Préparé ».
        $tirage->placementChange($le);
        self::assertSame(TirageRouleau::STATUT_EN_IMPRESSION, $tirage->getStatut());
    }
}
