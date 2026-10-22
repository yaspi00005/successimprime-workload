<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use App\Entity\TransactionOrangeMoney;
use App\Repository\PaiementsRepository;
use App\Service\Paiement\AvoirClient;
use App\Service\Paiement\OrangeMoney;
use App\Service\Paiement\PaiementsDuReglement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paiement de plusieurs factures en une fois (« Factures et paiements ») :
 * un seul passage chez Orange Money, un paiement « en attente » par
 * commande, l'avoir réservé puis utilisé, jamais rien de validé ici.
 */
final class ReglementEnLigneTest extends TestCase
{
    private const MAINTENANT = '2026-10-15 10:15';

    /** @var list<object> */
    private array $persistes = [];

    /** @var list<ReglementEnLigne> règlements en cours du client */
    private array $enCours = [];

    private int $avoirEnValidation = 0;

    /** @var list<TransactionOrangeMoney> */
    private array $transactions = [];

    public function testUnPaiementEnAttenteParCommande(): void
    {
        [$reglement, $commandes] = $this->reglement(avoir: 5000);
        $premier = $this->paiements()->creer($reglement, 'MP261015.1015.A1', new \DateTimeImmutable(self::MAINTENANT));

        self::assertTrue($reglement->estRecu());
        self::assertSame(self::MAINTENANT, $reglement->getRecuLe()?->format('Y-m-d H:i'));
        // Roll-up : l'avoir (5 000) et le reste par Orange Money ; cartes et t-shirts : Orange Money.
        self::assertCount(2, $commandes[0]->getPaiements());
        self::assertCount(1, $commandes[1]->getPaiements());
        self::assertCount(1, $commandes[2]->getPaiements());
        foreach ($commandes as $c) {
            foreach ($c->getPaiements() as $p) {
                self::assertSame(Paiements::STATUT_EN_ATTENTE, $p->getStatut(), 'jamais validé tout seul');
            }
        }
        $om = $reglement->getParts()[0]->getPaiementOm();
        self::assertSame($premier, $om);
        self::assertSame([Paiements::MODE_ORANGE_MONEY, 57500, 575, 575], [$om->getMode(), $om->getMontant(), $om->getMontantFondsSoutien(), $om->getMontantFraisRetrait()]);
        self::assertSame('F-SI-2026-0431', $om->getFacture()?->getNumero());
        // Une référence par paiement : la caisse les valide sans « référence déjà utilisée ».
        self::assertSame(['OM MP261015.1015.A1 1/3', 'OM MP261015.1015.A1 2/3', 'OM MP261015.1015.A1 3/3'], array_map(static fn (ReglementEnLignePart $p): ?string => $p->getPaiementOm()?->getReference(), $reglement->getParts()));
        $avoir = $reglement->getParts()[0]->getPaiementAvoir();
        self::assertSame([Paiements::MODE_SOLDE_CLIENT, 5000, 'AV '.$reglement->getReference()], [$avoir->getMode(), $avoir->getMontant(), $avoir->getReference()]);
        self::assertTrue($avoir->estAvoirEnLigne());
        self::assertFalse($om->estAvoirEnLigne());
        // Les frais vont sur les paiements, jamais dans le prix : leur somme est celle débitée par Orange.
        $frais = array_sum(array_map(static fn (ReglementEnLignePart $p): int => (int) $p->getPaiementOm()?->getMontantFondsSoutien() + (int) $p->getPaiementOm()?->getMontantFraisRetrait(), $reglement->getParts()));
        self::assertSame($reglement->getTotalOm() - $reglement->getMontantOm(), $frais);
        self::assertSame(62500, $commandes[0]->getTotalTtc(), 'le prix de la commande ne change pas');
        // Plus rien n'est redemandé en ligne pour ces commandes, en attendant l'imprimerie.
        self::assertSame([0, 0, 0], array_map(OrangeMoney::aPayer(...), $commandes));
        self::assertSame(5000, OrangeMoney::avoirEnValidation($commandes[0]));
    }

    public function testRejoueNeCreeRienDePlus(): void
    {
        [$reglement, $commandes] = $this->reglement();
        $service = $this->paiements();
        $premier = $service->creer($reglement, 'MP1', new \DateTimeImmutable(self::MAINTENANT));
        $nombre = \count($this->persistes);

        self::assertSame($premier, $service->creer($reglement, 'MP1', new \DateTimeImmutable(self::MAINTENANT)));
        self::assertCount($nombre, $this->persistes);
        self::assertCount(1, $commandes[1]->getPaiements());
    }

    public function testAvoirDejaUtiliseAilleursNEstPasPromisDeuxFois(): void
    {
        [$reglement, $commandes] = $this->reglement(avoir: 5000, solde: 5000);
        // L'imprimerie a utilisé 3 000 F de l'avoir en caisse entre-temps.
        $this->avoirEnValidation = 3000;
        $this->paiements()->creer($reglement, 'MP1', new \DateTimeImmutable(self::MAINTENANT));

        self::assertNull($reglement->getParts()[0]->getPaiementAvoir());
        self::assertCount(1, $commandes[0]->getPaiements(), 'seule la part Orange Money, reçue, est enregistrée');
        self::assertTrue($reglement->estRecu());
    }

    public function testAvoirSeulSansOrangeMoney(): void
    {
        $client = (new Clients())->setSoldeCredit(20000);
        $commande = $this->commande('CM-0163', 12500);
        $reglement = new ReglementEnLigne('AV261015101500ABC123', $client, null, new \DateTimeImmutable(self::MAINTENANT));
        $reglement->ajouterPart(new ReglementEnLignePart($reglement, $commande, null, ReglementEnLignePart::ACOMPTE, 0, 12500, 0, 0, 1));
        $premier = $this->paiements()->creer($reglement, null, new \DateTimeImmutable(self::MAINTENANT));

        self::assertSame(0, $reglement->getTotalOm());
        self::assertSame([Paiements::MODE_SOLDE_CLIENT, 12500, 'AV AV261015101500ABC123'], [$premier?->getMode(), $premier?->getMontant(), $premier?->getReference()]);
        self::assertNull($reglement->getParts()[0]->getPaiementOm());
    }

    public function testAvoirReserveLeTempsDuPassageChezOrange(): void
    {
        $client = (new Clients())->setSoldeCredit(10000);
        $this->avoirEnValidation = 3000;
        $parti = $this->enCoursDepuis($client, 2000, '-5 minutes');
        $vieux = $this->enCoursDepuis($client, 1000, '-40 minutes');
        $this->enCours = [$parti, $vieux];
        $avoirs = new AvoirClient($this->em());
        $maintenant = new \DateTimeImmutable(self::MAINTENANT);

        // 10 000 − 3 000 en validation − 2 000 réservés (le règlement de 40 minutes ne réserve plus rien).
        self::assertSame(['solde' => 10000, 'enValidation' => 3000, 'reserve' => 2000, 'disponible' => 5000], $avoirs->etat($client, $maintenant));
        // Le règlement lui-même compte son avoir comme disponible.
        self::assertSame(7000, $avoirs->disponible($client, $maintenant, $parti));
        $parti->abandonner();
        self::assertSame(7000, $avoirs->disponible($client, $maintenant), 'abandonné : l\'avoir n\'est plus réservé');
        self::assertSame(['solde' => 0, 'enValidation' => 0, 'reserve' => 0, 'disponible' => 0], AvoirClient::calculer(-500, 0, 0));
        self::assertSame(0, AvoirClient::calculer(1000, 800, 500)['disponible']);
    }

    public function testUnSeulPassageChezOrangePourLeTotal(): void
    {
        [$reglement] = $this->reglement(avoir: 5000);
        $transaction = TransactionOrangeMoney::pourReglement($reglement, null, new \DateTimeImmutable(self::MAINTENANT));

        self::assertSame(TransactionOrangeMoney::OBJET_REGLEMENT, $transaction->getObjet());
        self::assertSame($reglement->getReference(), $transaction->getOrderId());
        self::assertSame([134000, 1340, 1340, 136680], [$transaction->getMontant(), $transaction->getFondsSoutien(), $transaction->getFraisRetrait(), $transaction->getTotal()]);
        self::assertSame($reglement->getTotalOm(), $transaction->getTotal());
        self::assertNull($transaction->getCommande());
        self::assertSame($reglement->getClient(), $transaction->getClient());
    }

    public function testNotificationDOrangeRejoueeNeCreeRienDePlus(): void
    {
        [$reglement, $commandes] = $this->reglement(avoir: 5000);
        $transaction = $this->ouverte($reglement);
        $service = $this->orangeMoney();
        $notification = ['status' => 'SUCCESS', 'notif_token' => 'jeton-secret-orange', 'txnid' => 'MP261015.1015.A1'];

        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable(self::MAINTENANT)));
        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-15 10:16')));

        self::assertSame(TransactionOrangeMoney::REUSSIE, $transaction->getStatut());
        self::assertSame('MP261015.1015.A1', $transaction->getTxnid());
        self::assertSame($reglement->getParts()[0]->getPaiementOm(), $transaction->getPaiement());
        self::assertSame([2, 1, 1], array_map(static fn (Commandes $c): int => $c->getPaiements()->count(), $commandes));
        self::assertSame(ReglementEnLigne::RECU, $reglement->getStatut());
    }

    public function testEchecChezOrangeLibereLAvoirSansRienCreer(): void
    {
        [$reglement, $commandes] = $this->reglement(avoir: 5000);
        $transaction = $this->ouverte($reglement);
        $this->orangeMoney()->notification($transaction->getOrderId(), ['status' => 'FAILED', 'notif_token' => 'jeton-secret-orange'], new \DateTimeImmutable(self::MAINTENANT));

        self::assertSame(TransactionOrangeMoney::ECHOUEE, $transaction->getStatut());
        self::assertSame(ReglementEnLigne::ABANDONNE, $reglement->getStatut());
        self::assertFalse($reglement->reserveAvoir(new \DateTimeImmutable(self::MAINTENANT)));
        self::assertSame([0, 0, 0], array_map(static fn (Commandes $c): int => $c->getPaiements()->count(), $commandes));
        // Un règlement abandonné ne devient jamais reçu.
        $reglement->marquerRecu(new \DateTimeImmutable(self::MAINTENANT));
        self::assertFalse($reglement->estRecu());
    }

    public function testMemeSelectionMemeEmpreinte(): void
    {
        [$a] = $this->reglement(avoir: 5000);
        [$b] = $this->reglement(avoir: 5000);
        [$c] = $this->reglement(avoir: 0);
        self::assertNotSame($a->empreinte(), $b->empreinte(), 'd\'autres commandes');
        self::assertStringContainsString(':57500:5000|', $a->empreinte());
        self::assertStringContainsString(':62500:0|', $c->empreinte());
        self::assertSame(139000, $a->getMontant());
        self::assertSame('OM MP1', PaiementsDuReglement::reference('OM MP1', 1, 1));
        self::assertSame('OM MP1 2/3', PaiementsDuReglement::reference('OM MP1', 2, 3));
    }

    /**
     * Roll-up (facture échue), cartes (acompte), t-shirts (facture à venir),
     * comme RepartitionPaiement les répartit : l'avoir à la plus ancienne.
     *
     * @return array{0: ReglementEnLigne, 1: list<Commandes>}
     */
    private function reglement(int $avoir = 0, int $solde = 10000): array
    {
        $client = (new Clients())->setSoldeCredit($solde);
        $commandes = [$this->commande('CM-0147', 62500), $this->commande('CM-0163', 12500), $this->commande('CM-0159', 64000)];
        $facture = (new Factures())->setNumero('F-SI-2026-0431');
        $reglement = new ReglementEnLigne('SI261015101500ABC123', $client, null, new \DateTimeImmutable(self::MAINTENANT));
        $om = [62500 - $avoir, 12500, 64000];
        $frais = [57500 => 575, 62500 => 625, 12500 => 125, 64000 => 640];
        foreach ($commandes as $i => $c) {
            $f = $frais[$om[$i]] ?? 0;
            $reglement->ajouterPart(new ReglementEnLignePart($reglement, $c, 0 === $i ? $facture : null, 1 === $i ? ReglementEnLignePart::ACOMPTE : ReglementEnLignePart::FACTURE, $om[$i], 0 === $i ? $avoir : 0, $f, $f, $i + 1));
        }

        return [$reglement, $commandes];
    }

    private function commande(string $numero, int $total): Commandes
    {
        return (new Commandes())->setNumero($numero)->setTotalTtc($total)->setStatut(true);
    }

    private function enCoursDepuis(Clients $client, int $avoir, string $depuis): ReglementEnLigne
    {
        $r = new ReglementEnLigne('SI'.bin2hex(random_bytes(6)), $client, null, (new \DateTimeImmutable(self::MAINTENANT))->modify($depuis));
        $r->ajouterPart(new ReglementEnLignePart($r, $this->commande('CM-1', 50000), null, ReglementEnLignePart::FACTURE, 10000, $avoir, 0, 0, 1));

        return $r;
    }

    private function ouverte(ReglementEnLigne $reglement): TransactionOrangeMoney
    {
        $t = TransactionOrangeMoney::pourReglement($reglement, null, new \DateTimeImmutable('2026-10-15 10:10'));
        $t->ouvrir('pt-123', 'jeton-secret-orange', 'https://mpayment.orange-money.com/ml/mpayment/abstract/v1abc');
        $this->transactions[] = $t;

        return $t;
    }

    private function paiements(): PaiementsDuReglement
    {
        $em = $this->em();

        return new PaiementsDuReglement($em, new AvoirClient($em), new NullLogger());
    }

    private function orangeMoney(): OrangeMoney
    {
        $em = $this->em();

        return new OrangeMoney($em, new MockHttpClient(), new ArrayAdapter(), $this->createStub(UrlGeneratorInterface::class), new NullLogger(),
            'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id', 'secret', 'cle-marchand', null, null, null, new PaiementsDuReglement($em, new AvoirClient($em), new NullLogger()));
    }

    private function em(): EntityManagerInterface
    {
        $paiements = $this->createStub(PaiementsRepository::class);
        $paiements->method('avoirEnValidation')->willReturnCallback(fn (): int => $this->avoirEnValidation);
        $reglements = $this->createStub(EntityRepository::class);
        $reglements->method('findBy')->willReturnCallback(fn (): array => $this->enCours);
        $transactions = $this->createStub(EntityRepository::class);
        $transactions->method('findOneBy')->willReturnCallback(function (array $critere): ?TransactionOrangeMoney {
            foreach ($this->transactions as $t) {
                if ($t->getOrderId() === ($critere['orderId'] ?? null)) {
                    return $t;
                }
            }

            return null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $classe): object => match ($classe) {
            Paiements::class => $paiements,
            ReglementEnLigne::class => $reglements,
            default => $transactions,
        });
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
        });

        return $em;
    }
}
