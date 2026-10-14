<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Entity\MouvementCreditIa;
use App\Entity\SupportConception;
use App\Service\Conception\CreditsIa;
use App\Service\Conception\Generateur\GenerateurDemo;
use App\Service\Conception\Generateur\GenerateurImages;
use App\Service\Conception\Generateur\ImageGeneree;
use App\Service\Conception\ImagesIa;
use App\Service\Conception\ReglagesConception;
use App\Service\EspaceClient\FichiersClient;
use App\Service\Parametres\Parametres;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Génération d'une image IA : crédit débité avant l'appel, remboursé si
 * le fournisseur échoue, image rangée hors de public/, une génération à
 * la fois par client, limite par heure et plafond du jour.
 */
final class ImagesIaTest extends TestCase
{
    use BaseEnMemoire;

    private Clients $client;

    private DemandeConception $demande;

    private string $projet;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setNom('Hôtel Niger');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($this->client, 7);
        $support = (new SupportConception())->setCode('affiche')->setNom('Affiche A3')->setCourt('Affiche')->setFormat('29,7 × 42 cm')->setLargeurCm(30)->setHauteurCm(42)->setForfait(10000);
        $this->demande = DemandeConception::nouvelle($this->client, null, $support, new \DateTimeImmutable('2026-10-14 14:00'));
        $this->demande->setNumero('W-DC-2026-014');
        $this->demande->ecrireContenu('Soirée jazz', 'au bord du fleuve', null, null, null);
        $this->soldes[7] = 3000;
        $this->projet = sys_get_temp_dir().'/images-ia-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projet)) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projet, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($fichiers as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->projet);
        }
    }

    public function testFournisseurEnPanneCreditRembourse(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 14:21');

        try {
            $this->service($this->enPanne())->generer($this->demande, $this->compte(), 'plus de bleu', $maintenant);
            self::fail('Erreur attendue');
        } catch (\DomainException $e) {
            self::assertStringContainsString('remboursé', $e->getMessage());
            self::assertStringNotContainsString('sk-', $e->getMessage(), 'la raison technique reste dans le journal');
        }

        self::assertSame(3000, $this->soldes[7]);
        self::assertSame([MouvementCreditIa::DEBIT, MouvementCreditIa::REMBOURSEMENT], array_map(static fn (MouvementCreditIa $m): string => $m->getType(), $this->mouvements));
        self::assertCount(1, $this->images);
        self::assertSame(ImageIa::ECHOUEE, $this->images[0]->getStatut());
        self::assertSame(0, $this->jours['2026-10-14'], 'la place du jour est libérée');
    }

    public function testImageRangeeHorsDuSiteEtNumerotee(): void
    {
        $service = $this->service(new GenerateurDemo(sys_get_temp_dir().'/sans-polices'));

        $premiere = $service->generer($this->demande, $this->compte(), null, new \DateTimeImmutable('2026-10-14 14:20'));
        $seconde = $service->generer($this->demande, $this->compte(), 'fond plus clair', new \DateTimeImmutable('2026-10-14 14:21'));

        self::assertTrue($premiere->estPrete());
        self::assertSame([1, 2], [$premiere->getNumero(), $seconde->getNumero()]);
        self::assertFileExists($this->projet.'/var/uploads/ia/7/'.$seconde->getFichier());
        self::assertDirectoryDoesNotExist($this->projet.'/public');
        self::assertSame(1000, $this->soldes[7]);
        self::assertSame(DemandeConception::EN_IA, $this->demande->getStatut());
        self::assertSame(DemandeConception::IA, $this->demande->getChoix());
        self::assertSame([$premiere, $seconde], $service->images($this->demande));
    }

    public function testUneGenerationALaFoisEtLimiteParHeure(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 14:21');
        $this->images[] = new ImageIa($this->demande, 1, null, 'p', 'demo', 'x', 1000, $maintenant->modify('-1 minute'));

        $this->refuse('déjà en cours', $maintenant);

        // Terminée, elle ne bloque plus ; les échecs ne comptent pas dans
        // la limite par heure (10 images réussies, la 11e attend).
        $this->images[0]->echouer('perdue', $maintenant);
        for ($i = 2; $i <= 11; ++$i) {
            $image = new ImageIa($this->demande, $i, null, 'p', 'demo', 'x', 1000, $maintenant->modify('-30 minutes'));
            $image->reussir('x.png', 768, 1152, $maintenant);
            $this->images[] = $image;
        }

        $this->refuse('beaucoup d\'images cette heure-ci', $maintenant);
        self::assertSame(3000, $this->soldes[7]);
        self::assertSame([], $this->mouvements);
    }

    public function testCreditInsuffisantOuPlafondDuJourSansDebit(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 14:21');
        $this->soldes[7] = 500;

        $this->refuse('Crédit IA insuffisant', $maintenant);
        self::assertSame(0, $this->jours['2026-10-14'], 'la place du jour est rendue');

        $this->soldes[7] = 3000;
        $this->jours['2026-10-14'] = 200;
        $this->refuse('reprend demain', $maintenant);
        self::assertSame(3000, $this->soldes[7]);
        self::assertSame([], $this->images);
    }

    public function testDemandeEnvoyeeAuGraphisteRefusee(): void
    {
        $this->demande->envoyer(DemandeConception::RECUE, new \DateTimeImmutable());

        $this->refuse('déjà partie', new \DateTimeImmutable());
    }

    private function refuse(string $message, \DateTimeImmutable $maintenant): void
    {
        try {
            $this->service(new GenerateurDemo(sys_get_temp_dir().'/sans-polices'))->generer($this->demande, $this->compte(), null, $maintenant);
            self::fail('Refus attendu : '.$message);
        } catch (\DomainException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function enPanne(): GenerateurImages
    {
        return new class implements GenerateurImages {
            public function nom(): string
            {
                return 'openai';
            }

            public function modele(): string
            {
                return 'gpt-image-1';
            }

            public function estDisponible(): bool
            {
                return true;
            }

            public function generer(string $prompt, string $orientation, array $textes = []): ImageGeneree
            {
                throw new \RuntimeException('Incorrect API key provided: sk-test');
            }
        };
    }

    private function compte(): CompteClient
    {
        return (new CompteClient())->setClient($this->client)->setNom('Ibrahim');
    }

    private function service(GenerateurImages $generateur): ImagesIa
    {
        $em = $this->em();
        $parametres = (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Parametres::class, 'reglages'))->setValue($parametres, []);
        // Les fichiers du client ne servent qu'à la commande d'impression.
        $fichiers = (new \ReflectionClass(FichiersClient::class))->newInstanceWithoutConstructor();

        return new ImagesIa($em, new ReglagesConception($parametres, $generateur), new CreditsIa($em), $generateur, $fichiers, new NullLogger(), $this->projet);
    }
}
