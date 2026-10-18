<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Reglage;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Paiement\OrangeMoney;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Interrupteurs « Commande en ligne » et « Paiement Orange Money en ligne »
 * de Réglages › Espace client : appliqués par le serveur (nouvelle
 * commande, Mes produits, Atelier 3D, conception ; tout paiement Orange
 * Money de l'espace).
 */
final class InterrupteursEnLigneTest extends TestCase
{
    public function testCommandeEnLigneOuverteParDefaut(): void
    {
        self::assertTrue($this->nouvelleCommande([])->estOuverte());
        // Sans réglages (tests, outils) : ouverte.
        self::assertTrue((new NouvelleCommande($this->em(0)))->estOuverte());
    }

    public function testCommandeEnLigneCoupeeRefuseToutEnregistrement(): void
    {
        $service = $this->nouvelleCommande(['commande_en_ligne' => false]);

        self::assertFalse($service->estOuverte());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(NouvelleCommande::FERMEE);
        $service->verifierEnAttente(new Clients());
    }

    public function testCommandeEnLigneOuverteGardeLaLimiteDesCommandesEnAttente(): void
    {
        $this->nouvelleCommande(['commande_en_ligne' => true])->verifierEnAttente(new Clients());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('plusieurs commandes en attente');
        (new NouvelleCommande($this->em(NouvelleCommande::EN_ATTENTE_MAX), null, static fn (): Parametres => self::parametres([])))->verifierEnAttente(new Clients());
    }

    public function testPaiementEnLigneOuvertParDefautQuandLesClesSontPosees(): void
    {
        // Clés posées, interrupteur jamais enregistré : ouvert (défaut de la
        // tuile), comme avant que l'interrupteur soit appliqué.
        self::assertTrue($this->orangeMoney([])->estConfigure());
        self::assertTrue($this->orangeMoney([])->estOuvert());
        self::assertFalse($this->orangeMoney(['paiement_en_ligne' => false])->estOuvert());
    }

    public function testSansClesLePaiementResteFermeMemeAllume(): void
    {
        self::assertFalse($this->orangeMoney(['paiement_en_ligne' => true], '')->estOuvert());
    }

    public function testPaiementCoupeRefuseDOuvrirUnPaiement(): void
    {
        $commande = (new Commandes())->setNumero('CMD-000001-10-2026')->setTotalTtc(25000)->setStatut(true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('pas ouvert pour le moment');
        $this->orangeMoney(['paiement_en_ligne' => false])->demarrer($commande, new CompteClient(), new \DateTimeImmutable());
    }

    /** @param array<string, mixed> $espaceClient */
    private function nouvelleCommande(array $espaceClient): NouvelleCommande
    {
        return new NouvelleCommande($this->em(0), null, static fn (): Parametres => self::parametres($espaceClient));
    }

    /** @param array<string, mixed> $espaceClient */
    private function orangeMoney(array $espaceClient, string $cleMarchand = 'cle-om'): OrangeMoney
    {
        $parametres = self::parametres($espaceClient);

        return new OrangeMoney(
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient(static fn () => throw new \LogicException('Aucun appel à Orange quand le paiement est fermé.')),
            new ArrayAdapter(),
            $this->createStub(UrlGeneratorInterface::class),
            new NullLogger(),
            'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id-om', 'secret-om', $cleMarchand,
            reglages: static fn (): Parametres => $parametres,
        );
    }

    private function em(int $enAttente): EntityManagerInterface
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('count')->willReturn($enAttente);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        return $em;
    }

    /** @param array<string, mixed> $espaceClient valeurs enregistrées de la tuile ; [] : jamais enregistrée */
    private static function parametres(array $espaceClient): Parametres
    {
        $parametres = (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor();
        $reglages = [] === $espaceClient ? [] : ['espace_client' => (new Reglage('espace_client'))->modifier($espaceClient, null, new \DateTimeImmutable())];
        (new \ReflectionProperty(Parametres::class, 'reglages'))->setValue($parametres, $reglages);

        return $parametres;
    }
}
