<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\SupportConception;
use App\Repository\CompteClientRepository;
use App\Service\Conception\CommandesConception;
use App\Service\Conception\CreditsIa;
use App\Service\Conception\DemandesConception;
use App\Service\Conception\Generateur\GenerateurDemo;
use App\Service\Conception\ReglagesConception;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\OrangeSmsService;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * « Choisir et commander l'impression » (maquette DC04) : commande en
 * ligne à confirmer, prix recalculé par le serveur avec le produit du
 * catalogue lié au support, image IA en fichier du client, textes à
 * remplacer par le graphiste, finalisation en option.
 */
final class CommanderImpressionTest extends TestCase
{
    private Clients $client;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setNom('Hôtel Niger')->setTypeClient('B2C')->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER);
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($this->client, 9);
    }

    public function testPrixDuCatalogueEtFinalisation(): void
    {
        [$demande, $image] = $this->demandeAvecImage(true);
        $fichier = new CommandeDetailFichier();

        $commande = $this->service()->commanderImpression($demande, $this->compte(), $image, $fichier, true, new \DateTimeImmutable('2026-10-14 14:30'));

        self::assertFalse($commande->isStatut(), 'à confirmer par l\'atelier');
        self::assertSame(Commandes::CANAL_EN_LIGNE, $commande->getCanal());
        $lignes = $commande->getCommandesDetails()->toArray();
        self::assertCount(2, $lignes);
        // 2 kakémonos à 22 000 F : le prix vient du produit, jamais du navigateur.
        self::assertSame(44000, $lignes[0]->getTotalTtc());
        self::assertStringContainsString('Remplacement des textes par le graphiste avant impression', (string) $lignes[0]->getDemandeClient());
        self::assertSame('Finalisation graphiste · visuel IA', $lignes[1]->getDesignation());
        self::assertSame(5000, $lignes[1]->getTotalTtc());
        self::assertSame(49000, $commande->getTotalTtc());
        self::assertSame($lignes[0], $fichier->getCommandeDetail(), 'l\'image choisie est le fichier de la ligne');

        self::assertSame(DemandeConception::COMMANDEE, $demande->getStatut());
        self::assertSame(DemandeConception::IA, $demande->getChoix());
        self::assertSame($fichier, $demande->getImageChoisie());
        self::assertTrue($demande->isFinalisationGraphiste());
        self::assertNotNull($demande->getEnvoyeeLe());
    }

    public function testSansProduitLieLAtelierMetLePrix(): void
    {
        [$demande, $image] = $this->demandeAvecImage(false);
        $fichier = new CommandeDetailFichier();

        $commande = $this->service()->commanderImpression($demande, $this->compte(), $image, $fichier, false, new \DateTimeImmutable());

        $lignes = $commande->getCommandesDetails()->toArray();
        self::assertCount(1, $lignes);
        self::assertSame(0, $commande->getTotalTtc());
        self::assertStringContainsString('prix confirmé par l\'atelier', (string) $lignes[0]->getDesignation());
        self::assertTrue($lignes[0]->isPrePresseNecessaire());
        self::assertFalse($commande->isStatut());
    }

    public function testImageDUneAutreDemandeOuPasPreteRefusee(): void
    {
        [$demande] = $this->demandeAvecImage(true);
        [, $autre] = $this->demandeAvecImage(true);
        $enCours = new ImageIa($demande, 3, null, 'p', 'demo', 'apercu-gd', 1000, new \DateTimeImmutable());

        foreach ([$autre, $enCours] as $image) {
            try {
                $this->service()->commanderImpression($demande, $this->compte(), $image, new CommandeDetailFichier(), false, new \DateTimeImmutable());
                self::fail('Refus attendu');
            } catch (\DomainException) {
            }
        }
        self::assertSame(DemandeConception::BROUILLON, $demande->getStatut());
    }

    public function testCommandeDeConceptionAuForfaitDuSupport(): void
    {
        [$demande] = $this->demandeAvecImage(true);
        $commande = CommandesConception::commandeConception($demande, new \DateTimeImmutable());
        $ligne = $commande->getCommandesDetails()->first();

        self::assertInstanceOf(CommandesDetails::class, $ligne);
        self::assertSame(10000, $commande->getTotalTtc());
        self::assertTrue($commande->isStatut());
        self::assertSame(CommandesDetails::PRODUCTION_NON_REQUISE, $ligne->getStatutProduction());
        self::assertStringContainsString('Titre : Soirée jazz', (string) $ligne->getDemandeClient());
    }

    /** @return array{DemandeConception, ImageIa} */
    private function demandeAvecImage(bool $avecProduit): array
    {
        $support = (new SupportConception())->setCode('kakemono')->setNom('Kakémono / roll-up')->setCourt('Kakémono')->setFormat('85 × 200 cm')->setLargeurCm(85)->setHauteurCm(200)->setForfait(10000);
        if ($avecProduit) {
            $config = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Kakémono')->setActif(true)->setPublie(true))
                ->setModeDimension('aucune')->setModeCalcul('unite')->setPrixBase(22000)->setActive(true);
            // Un produit non publié est ignoré.
            $brouillon = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Ancien')->setActif(true)->setPublie(false))->setPrixBase(1)->setActive(true);
            $support->addConfiguration($brouillon)->addConfiguration($config);
        }
        $demande = DemandeConception::nouvelle($this->client, null, $support, new \DateTimeImmutable('2026-10-14 14:00'));
        $demande->setNumero('DC-2026-015');
        $demande->ecrireContenu('Soirée jazz', 'au bord du fleuve', null, null, null);
        $demande->setQuantite(2);
        $image = new ImageIa($demande, 2, null, 'prompt', 'demo', 'apercu-gd', 1000, new \DateTimeImmutable('2026-10-14 14:21'));
        $image->reussir('ia/9/abc.png', 768, 1152, new \DateTimeImmutable('2026-10-14 14:21'));

        return [$demande, $image];
    }

    private function compte(): CompteClient
    {
        return (new CompteClient())->setClient($this->client)->setNom('Ibrahim');
    }

    private function service(): DemandesConception
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('count')->willReturn(0);
        $depot->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $f): mixed => $f($em));

        // Réglages par défaut (finalisation 5 000 F), sans base.
        $parametres = (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Parametres::class, 'reglages'))->setValue($parametres, []);
        $sms = new OrangeSmsService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '', '', '', '');

        return new DemandesConception(
            $em,
            new ReglagesConception($parametres, new GenerateurDemo(sys_get_temp_dir())),
            new CommandesConception($em, new CreditsIa($em)),
            new CompteEntreprise($em, $this->createStub(CompteClientRepository::class), $sms),
            new NouvelleCommande($em),
            $sms,
        );
    }
}
