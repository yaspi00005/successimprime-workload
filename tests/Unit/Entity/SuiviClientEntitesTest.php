<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandeDetailFichier;
use App\Entity\ControlePrePresse;
use App\Entity\Employes;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le suivi de la commande (espace client, maquette CO05) lit des
 * employés et du contrôle du fichier.
 */
final class SuiviClientEntitesTest extends TestCase
{
    public function testNomPublicPrenomEtMetierSeulement(): void
    {
        $seydou = (new Employes())->setPrenom('Seydou')->setNom('Diarra')->setFonction('Graphiste PAO')->setTelephone('+223 70 00 00 00');

        self::assertSame('Seydou (graphiste PAO)', $seydou->getNomPublic());
        self::assertSame('Awa', Employes::nomPublic('  Awa ', null), 'sans métier : le prénom');
        self::assertSame('Fanta (accueil)', Employes::nomPublic("Fanta\n", '  Accueil '));
        self::assertSame('Moussa (DG adjoint)', Employes::nomPublic('Moussa', 'DG adjoint'), 'un sigle en tête reste en capitales');
        self::assertSame('Fanta (RH)', Employes::nomPublic('Fanta', 'RH'));
        self::assertSame('Awa (école)', Employes::nomPublic('Awa', 'École'));
        self::assertNull(Employes::nomPublic(null, 'Graphiste'), 'sans prénom : rien, jamais le nom de famille');
        self::assertNull(Employes::nomPublic('  ', 'Graphiste'));
        self::assertSame(80, mb_strlen((string) Employes::nomPublic(str_repeat('a', 60), str_repeat('b', 60))));
    }

    public function testFichierControleQuandLeControleEstFini(): void
    {
        $ctl = (new ControlePrePresse())->addFichier(new CommandeDetailFichier());
        self::assertFalse($ctl->isFichierControle(), 'à contrôler');

        $ctl->commencerControle(new User());
        self::assertFalse($ctl->isFichierControle(), 'contrôle commencé, pas fini');

        foreach ([ControlePrePresse::STATUT_EN_ATTENTE_BAT, ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION] as $statut) {
            self::assertTrue($ctl->setStatut($statut)->isFichierControle(), $statut);
        }
        // BAT validé par le client en ligne : le contrôle repasse « en cours » pour finir l'envoi en production.
        self::assertTrue($ctl->setStatut(ControlePrePresse::STATUT_EN_COURS)->setBatNecessaire(true)->setBatValide(true)->isFichierControle());
    }
}
