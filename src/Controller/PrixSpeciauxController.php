<?php

namespace App\Controller;

use App\Service\Http\Entree;
use App\Entity\Clients;
use App\Entity\PrixSpecial;
use App\Entity\ProduitConfiguration;
use App\Entity\Promotion;
use App\Entity\User;
use App\Repository\PrixSpecialRepository;
use App\Security\AccesEnLigne;
use App\Service\Catalogue\CoutProduit;
use App\Service\Catalogue\GardeFousPrix;
use App\Service\GrilleTarifaire;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes › Produits › Prix spéciaux et promotions (maquette PR03,
 * docs/produits.md) : grilles tarifaires, prix négociés par client,
 * promotions en cours et garde-fous de marge.
 *
 * Voir : la direction, qui saisit devis ou commandes, le droit Promotions.
 * Ajouter ou arrêter un prix négocié : la direction et le droit
 * Promotions. Un prix hors garde-fous attend l'accord de la direction.
 * Les marges ne sont montrées qu'avec « Marges et coûts de revient ».
 */
#[Route('/produits/prix-speciaux', name: 'app_produits_prix_speciaux_')]
#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_COMMANDE') or is_granted('ROLE_DEVIS') or is_granted('ROLE_PROMOTIONS')"))]
final class PrixSpeciauxController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrixSpecialRepository $depot,
        private readonly GardeFousPrix $gardeFous,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $avecCouts = $this->isGranted('ROLE_COUT_MARGE');
        $g = $this->gardeFous->valeurs();
        $jour = new \DateTimeImmutable('today');

        $prix = [];
        foreach ($this->depot->pourEcran() as $ps) {
            $config = $ps->getConfiguration();
            $normal = $config->getPrixApplicable($ps->getClient()->isB2B());
            $cout = $avecCouts ? CoutProduit::detail($config->getProduit(), $config->getModeCalcul())['total'] : null;
            $prix[] = [
                'prix' => $ps,
                'normal' => $normal,
                'unite' => CoutProduit::unite($config->getModeCalcul()),
                'remise' => GardeFousPrix::remise($normal, $ps->getPrix()),
                'marge' => $avecCouts ? CoutProduit::marge($ps->getPrix(), $cout) : null,
                'echu' => $ps->estEchu($jour),
            ];
        }

        // Grilles : Standard (prix public) et Revendeur (prix B2B), avec leur remise moyenne.
        $remises = [];
        $alertes = [];
        foreach ($this->configurations() as $c) {
            if ((int) $c->getPrixBase() > 0 && null !== $c->getPrixB2B()) {
                $remises[] = GardeFousPrix::remise((int) $c->getPrixBase(), $c->getPrixB2B());
            }
            if ($avecCouts) {
                $cout = CoutProduit::detail($c->getProduit(), $c->getModeCalcul())['total'];
                $marge = CoutProduit::marge($c->getPrixB2B() ?? $c->getPrixBase(), $cout);
                if (null !== $marge && $marge < $g['minimum']) {
                    $alertes[] = ['libelle' => GrilleTarifaire::libelle($c), 'grille' => null !== $c->getPrixB2B() ? 'Revendeur' : 'Standard', 'marge' => $marge];
                }
            }
        }
        $clients = $this->em->createQueryBuilder()->select('c.typeClient AS grille', 'COUNT(c.id) AS nombre')->from(Clients::class, 'c')
            ->where('c.statut = true OR c.statut IS NULL')->groupBy('c.typeClient')->getQuery()->getArrayResult();
        $parGrille = array_column($clients, 'nombre', 'grille');

        $promotions = $this->em->createQueryBuilder()->select('p')->from(Promotion::class, 'p')
            ->where('p.fin >= :jour')->setParameter('jour', $jour)
            ->orderBy('p.debut', 'ASC')->setMaxResults(12)->getQuery()->getResult();
        $utilisations = [];
        foreach ($promotions as $p) {
            $utilisations[$p->getId()] = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commandes_details WHERE promotion_id = ?', [$p->getId()]);
        }

        return $this->render('produits/prix_speciaux.html.twig', [
            'prix' => $prix,
            'grilles' => [
                ['nom' => 'Standard', 'detail' => 'prix public, tous les clients', 'remise' => null, 'clients' => (int) ($parGrille['B2C'] ?? 0)],
                ['nom' => 'Revendeur', 'detail' => 'imprimeurs et agences (B2B)', 'remise' => [] === $remises ? null : round(array_sum($remises) / \count($remises)), 'clients' => (int) ($parGrille['B2B'] ?? 0)],
            ],
            'promotions' => $promotions,
            'utilisations' => $utilisations,
            'jour' => $jour,
            'alertes' => $alertes,
            'garde_fous' => $g,
            'avec_couts' => $avecCouts,
            'peut_ajouter' => $this->peutAjouter(),
            'peut_decider' => $this->isGranted('ROLE_ADMIN') && !$this->acces->estEnLigne(),
            'clients' => $this->peutAjouter() ? $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
                ->where('c.statut = true OR c.statut IS NULL')->orderBy('c.raisonSociale', 'ASC')->addOrderBy('c.nom', 'ASC')->getQuery()->getResult() : [],
            'configurations' => $this->peutAjouter() ? $this->configurations() : [],
        ]);
    }

    #[Route('/ajouter', name: 'ajouter', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_PROMOTIONS')"))]
    public function ajouter(Request $request, NotificationService $notifications): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'prix-special-ajouter');
        $client = $this->em->find(Clients::class, Entree::entier($request->request, 'client'));
        $config = $this->em->find(ProduitConfiguration::class, Entree::entier($request->request, 'configuration'));
        $montant = GrilleTarifaire::entier($request->request->getString('prix'));
        $fin = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('fin')) ?: null;
        if (null === $client || null === $config || !\is_int($montant) || $montant <= 0) {
            $this->addFlash('danger', 'Choisissez un client, un produit et un prix en francs.');

            return $this->redirectToRoute('app_produits_prix_speciaux_index');
        }
        if (null !== $fin && $fin < new \DateTimeImmutable('today')) {
            $this->addFlash('danger', 'La date de fin est déjà passée.');

            return $this->redirectToRoute('app_produits_prix_speciaux_index');
        }

        $g = $this->gardeFous->valeurs();
        $normal = $config->getPrixApplicable($client->isB2B());
        $cout = CoutProduit::detail($config->getProduit(), $config->getModeCalcul())['total'];
        $raisons = GardeFousPrix::controler($normal, $montant, $cout, $g['remise'], $g['minimum']);
        $direction = $this->isGranted('ROLE_ADMIN');

        $ps = (new PrixSpecial())->setClient($client)->setConfiguration($config)->setPrix($montant)->setFinLe($fin)
            ->setNote($request->request->getString('note'))->setCreePar($this->utilisateur())
            ->setStatut([] === $raisons || $direction ? PrixSpecial::ACTIF : PrixSpecial::ATTENTE);
        $this->em->persist($ps);
        if (PrixSpecial::ACTIF === $ps->getStatut()) {
            $this->em->getRepository(PrixSpecial::class)->arreterPrecedents($ps);
        }
        $this->em->flush();

        $libelle = \sprintf('%s pour %s', GrilleTarifaire::libelle($config), $client->getNomComplet());
        if (PrixSpecial::ATTENTE === $ps->getStatut()) {
            $notifications->notifierRoles(['ROLE_ADMIN'], \sprintf('Prix négocié à accorder : %s (%s).', $libelle, self::texteRaisons($raisons)), 'app_produits_prix_speciaux_index');
            $this->addFlash('warning', \sprintf('Prix enregistré, en attente de l\'accord de la direction (%s). Il s\'appliquera une fois accordé.', self::texteRaisons($raisons)));
        } else {
            $this->addFlash('success', \sprintf('Prix négocié appliqué : %s.', $libelle).([] !== $raisons ? ' Attention : '.self::texteRaisons($raisons).'.' : ''));
        }

        return $this->redirectToRoute('app_produits_prix_speciaux_index');
    }

    #[Route('/{id}/decider', name: 'decider', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decider(PrixSpecial $ps, Request $request): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'prix-special-'.$ps->getId());
        if (PrixSpecial::ATTENTE === $ps->getStatut()) {
            $accepte = 'accorder' === $request->request->getString('decision');
            $ps->decider($accepte, $this->utilisateur(), new \DateTimeImmutable());
            if ($accepte) {
                $this->em->getRepository(PrixSpecial::class)->arreterPrecedents($ps);
            }
            $this->em->flush();
            $this->addFlash('success', $accepte ? 'Prix accordé : il s\'applique dès maintenant.' : 'Prix refusé.');
        }

        return $this->redirectToRoute('app_produits_prix_speciaux_index');
    }

    #[Route('/{id}/arreter', name: 'arreter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_PROMOTIONS')"))]
    public function arreter(PrixSpecial $ps, Request $request): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'prix-special-'.$ps->getId());
        $ps->setStatut(PrixSpecial::ARRETE);
        $this->em->flush();
        $this->addFlash('success', 'Prix négocié arrêté : le client repasse au prix de sa grille.');

        return $this->redirectToRoute('app_produits_prix_speciaux_index');
    }

    /**
     * @param list<string> $raisons
     */
    private static function texteRaisons(array $raisons): string
    {
        return implode(' et ', array_map(static fn (string $r): string => GardeFousPrix::RAISON_REMISE === $r ? 'remise au-delà du maximum sans accord' : 'sous la marge minimale', $raisons));
    }

    /**
     * @return list<ProduitConfiguration>
     */
    private function configurations(): array
    {
        return $this->em->createQueryBuilder()->select('pc', 'p', 's', 'f')->from(ProduitConfiguration::class, 'pc')
            ->innerJoin('pc.produit', 'p')->leftJoin('pc.support', 's')->leftJoin('pc.format', 'f')
            ->where('pc.active = true')->andWhere('p.actif = true')
            ->orderBy('p.nom', 'ASC')->addOrderBy('pc.ordre', 'ASC')->getQuery()->getResult();
    }

    private function peutAjouter(): bool
    {
        return ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_PROMOTIONS')) && !$this->acces->estEnLigne();
    }

    private function refuserEnLigne(): void
    {
        if ($this->acces->estEnLigne()) {
            throw $this->createAccessDeniedException('Les prix se modifient sur l\'ERP de l\'atelier, puis se synchronisent.');
        }
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function utilisateur(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }
}
