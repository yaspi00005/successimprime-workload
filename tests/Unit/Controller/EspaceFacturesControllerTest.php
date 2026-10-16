<?php

namespace App\Tests\Unit\Controller;

use App\Controller\EspaceFacturesController;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Service\EspaceClient\ElementAPayer;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FacturesClient;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * « Factures et paiements » de l'espace client : qui y entre, et une
 * facture, un paiement, un règlement ou une commande d'un autre client
 * donnent « introuvable ».
 */
final class EspaceFacturesControllerTest extends TestCase
{
    public function testSeulQuiPaieVoitLesFactures(): void
    {
        $entreprise = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $attendus = [
            'particulier titulaire' => [self::compte(new Clients(), []), true],
            'titulaire de l\'entreprise' => [self::compte($entreprise, [CompteClient::DROIT_ADMINISTRATEUR]), true],
            'payeur' => [self::compte($entreprise, [CompteClient::DROIT_PAYEUR]), true],
            'valideur et payeur' => [self::compte($entreprise, [CompteClient::DROIT_VALIDEUR, CompteClient::DROIT_PAYEUR]), true],
            // Le valideur accepte les devis, il ne paie pas : ni factures, ni solde, ni avoir.
            'valideur' => [self::compte($entreprise, [CompteClient::DROIT_VALIDEUR]), false],
            'demandeur' => [self::compte($entreprise, []), false],
            'lecture' => [self::compte($entreprise, [CompteClient::DROIT_LECTURE]), false],
            'lecture et payeur' => [self::compte($entreprise, [CompteClient::DROIT_LECTURE, CompteClient::DROIT_PAYEUR]), false],
        ];
        foreach ($attendus as $qui => [$compte, $voit]) {
            self::assertSame($voit, EspaceFacturesController::peutVoirLesFactures($compte), $qui);
        }
        self::assertFalse(EspaceFacturesController::peutVoirLesFactures(new CompteClient()), 'sans client');
    }

    public function testClesLisiblesSeulement(): void
    {
        $a = 'c-0192f0a1-1111-7000-8000-000000000001';
        self::assertSame([$a], EspaceFacturesController::cles([$a, $a, 'c-1', '42', ['x'], 'c-0192F0A1-1111-7000-8000-000000000001', "c-0192f0a1-1111-7000-8000-000000000001\n"]));
        self::assertSame([], EspaceFacturesController::cles('c-0192f0a1-1111-7000-8000-000000000001'));
        $beaucoup = array_map(static fn (int $i): string => \sprintf('c-0192f0a1-1111-7000-8000-%012d', $i), range(1, 60));
        self::assertCount(50, EspaceFacturesController::cles($beaucoup));
        self::assertSame(25000, EspaceFacturesController::montant('25 000 F'));
        self::assertSame(25000, EspaceFacturesController::montant('25000'));
        self::assertNull(EspaceFacturesController::montant('vingt'));
        self::assertNull(EspaceFacturesController::montant('99999999999'));
    }

    public function testQrSeulementAvecUnCodeAuBonFormat(): void
    {
        $facture = new Factures();
        self::assertNull(EspaceFacturesController::codeSuivi($facture), 'pas de code : le site n\'en crée pas');
        $facture->genererCodeSuivi();
        self::assertSame($facture->getCodeSuivi(), EspaceFacturesController::codeSuivi($facture));
        (new \ReflectionProperty(Factures::class, 'codeSuivi'))->setValue($facture, 'UW374XZ3E');
        self::assertNull(EspaceFacturesController::codeSuivi($facture), 'ancien format : pas de QR plutôt qu\'une erreur');
    }

    public function testUneCommandeQuiNEstPasAPayerEstSignalee(): void
    {
        $sienne = (new Commandes())->setNumero('CM-1')->setTotalTtc(1000)->setStatut(true);
        $element = new ElementAPayer($sienne, null, ElementAPayer::COMMANDE, 1000, 1000, new \DateTimeImmutable(), ElementAPayer::A_PAYER);
        $autre = 'c-'.(new Commandes())->getPublicId()->toRfc4122();

        self::assertSame([$autre], EspaceFacturesController::horsDeLaListe([$element->cle(), $autre], [$element]));
        self::assertSame([], EspaceFacturesController::horsDeLaListe([$element->cle()], [$element]));
    }

    public function testFactureEtPaiementDUnAutreClientIntrouvables(): void
    {
        $client = new Clients();
        $sienne = new Factures();
        $paiement = new Paiements();
        $service = $this->factures([Factures::class => [$sienne], Paiements::class => [$paiement]]);

        self::assertSame($sienne, $service->facture($client, (string) $sienne->getPublicId()));
        // La requête ne rend que les factures du client : celle d'un autre n'y est pas.
        self::assertNull($service->facture($client, (string) (new Factures())->getPublicId()));
        self::assertNull($service->facture($client, '42'));
        self::assertSame($paiement, $service->paiement($client, (string) $paiement->getPublicId()));
        self::assertNull($service->paiement($client, (string) (new Paiements())->getPublicId()));
        self::assertNull($service->paiement($client, 'pas-un-uuid'));
    }

    public function testReglementDUnAutreClientIntrouvable(): void
    {
        $moi = new Clients();
        $autre = new Clients();
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($moi, 1);
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($autre, 2);
        $sien = new ReglementEnLigne('SI261015101500AAAAAA', $moi, null, new \DateTimeImmutable());
        $celuiDUnAutre = new ReglementEnLigne('SI261015101500BBBBBB', $autre, null, new \DateTimeImmutable());
        $service = $this->factures([ReglementEnLigne::class => [$sien, $celuiDUnAutre]]);

        self::assertSame($sien, $service->reglement($moi, 'SI261015101500AAAAAA'));
        self::assertNull($service->reglement($moi, 'SI261015101500BBBBBB'));
        self::assertNull($service->reglement($moi, 'SI000000000000CCCCCC'));
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }

    /**
     * Le service tel que le contrôleur l'utilise, sur des requêtes qui ne
     * rendent que ce qui appartient au client.
     *
     * @param array<class-string, list<object>> $lignes
     */
    private function factures(array $lignes): FacturesClient
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $classe) use ($lignes): EntityRepository {
            $liste = $lignes[$classe] ?? [];
            $requete = $this->createStub(Query::class);
            $requete->method('getResult')->willReturn($liste);
            $qb = $this->createStub(QueryBuilder::class);
            foreach (['join', 'where', 'andWhere', 'setParameter', 'orderBy', 'addOrderBy', 'setMaxResults'] as $m) {
                $qb->method($m)->willReturnSelf();
            }
            $qb->method('getQuery')->willReturn($requete);
            $depot = $this->createStub(EntityRepository::class);
            $depot->method('createQueryBuilder')->willReturn($qb);
            $depot->method('findOneBy')->willReturnCallback(static function (array $critere) use ($liste): ?object {
                foreach ($liste as $o) {
                    if ($o instanceof ReglementEnLigne && $o->getReference() === ($critere['reference'] ?? null)) {
                        return $o;
                    }
                }

                return null;
            });

            return $depot;
        });

        return new FacturesClient(
            $em,
            (new \ReflectionClass(EspaceClient::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor(),
        );
    }
}
