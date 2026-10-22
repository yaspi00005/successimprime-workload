<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Service\EspaceClient\MesCommandesClient;
use PHPUnit\Framework\TestCase;

/**
 * « Mes commandes » d'un compte entreprise : le total pour qui voit les
 * montants (valideur), payé et reste pour le payeur seul, rien pour un
 * demandeur ; jamais le total dépensé à l'imprimerie.
 */
final class MesCommandesClientTest extends TestCase
{
    public function testLeValideurVoitLeTotalSansCeQuiEstPaye(): void
    {
        $l = MesCommandesClient::ligne($this->carte(), null, $this->compte([CompteClient::DROIT_VALIDEUR]), new \DateTimeImmutable('2026-10-21 10:00'));

        self::assertSame(45000, $l['montants']['total']);
        self::assertNull($l['montants']['paye']);
        self::assertNull($l['montants']['reste']);
        self::assertNull($l['montants']['enValidation']);
        self::assertNotContains('a_payer', $l['onglets']);
    }

    public function testLePayeurVoitPayeEtReste(): void
    {
        $l = MesCommandesClient::ligne($this->carte(), null, $this->compte([CompteClient::DROIT_PAYEUR]), new \DateTimeImmutable('2026-10-21 10:00'));

        self::assertSame(45000, $l['montants']['total']);
        self::assertSame(45000, $l['montants']['reste']);
        self::assertSame(0, $l['montants']['paye']);
    }

    public function testLeDemandeurNeVoitAucunMontant(): void
    {
        $l = MesCommandesClient::ligne($this->carte(), null, $this->compte([]), new \DateTimeImmutable('2026-10-21 10:00'));

        self::assertNull($l['montants']);
    }

    public function testLeResumeNeDonneQueLeNombreDeCommandes(): void
    {
        $compte = $this->compte([CompteClient::DROIT_VALIDEUR]);
        $l = MesCommandesClient::ligne($this->carte(), null, $compte, new \DateTimeImmutable('2026-10-21 10:00'));
        $resume = MesCommandesClient::resume([$l], 2026, true);

        self::assertSame(1, $resume['commandees']);
        self::assertArrayNotHasKey('montantCommande', $resume);
    }

    /** @param list<string> $droits */
    private function compte(array $droits): CompteClient
    {
        return (new CompteClient())->setClient((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE))->setDroits($droits);
    }

    /** @return array<string, mixed> */
    private function carte(): array
    {
        $c = (new Commandes())->setNumero('CMD-000183')->setTotalTtc(45000)->setDateCommande(new \DateTimeImmutable('2026-10-20 09:00'));
        $c->setStatut(true);

        return [
            'commande' => $c,
            'titre' => 'Bâche 300 × 100',
            'etapes' => [['cle' => 'bat', 'etat' => 'faite'], ['cle' => 'impression', 'etat' => 'en_cours']],
            'courante' => 1,
            'annulee' => false,
            'batEnAttente' => false,
            'aConfirmer' => false,
            'aValiderEntreprise' => false,
            'refuseeEntreprise' => false,
        ];
    }
}
