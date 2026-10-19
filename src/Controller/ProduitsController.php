<?php

namespace App\Controller;

use App\Service\Http\Entree;
use App\Entity\Articles;
use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Machines;
use App\Entity\PrixSpecial;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\Catalogue\CatalogueProduits;
use App\Service\Catalogue\CoutProduit;
use App\Service\Catalogue\PaliersPrix;
use App\Service\Catalogue\EditionProduit;
use App\Service\Catalogue\GardeFousPrix;
use App\Service\Catalogue\NomCommercial;
use App\Service\Catalogue\OptionsPartagees;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\GrilleTarifaire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes › Produits et services (maquettes PR01 et PR02 du 18/10/2026,
 * docs/produits.md) : le catalogue avec prix, coût de revient, marge,
 * délai, machine et visibilité en ligne, l'export de la grille et la
 * fiche produit.
 *
 * Lecture : la direction et qui saisit devis ou commandes. Modification :
 * la direction, sur l'ERP de l'atelier (le site en ligne reçoit le
 * catalogue par la synchronisation). Coût de revient et marge : droit
 * « Marges et coûts de revient » seulement, jamais calculés sans lui.
 */
#[Route('/produits', name: 'app_produits_')]
#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_COMMANDE') or is_granted('ROLE_DEVIS')"))]
final class ProduitsController extends AbstractController
{
    public function __construct(
        private readonly CatalogueProduits $catalogue,
        private readonly GardeFousPrix $gardeFous,
        private readonly AccesEnLigne $acces,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $avecCouts = $this->isGranted('ROLE_COUT_MARGE');
        $g = $this->gardeFous->valeurs();
        [$debut, $fin] = self::mois();
        $ventes = $this->catalogue->ventes($debut, $fin);
        $toutes = CatalogueProduits::lignes($this->catalogue->produits(), $ventes, $avecCouts, $g['cible'], $g['minimum']);

        $famille = Entree::entier($request->query, 'famille') ?: null;
        $recherche = mb_substr(trim($request->query->getString('q')), 0, 80);
        $lignes = null === $famille && '' === $recherche
            ? $toutes
            : CatalogueProduits::lignes($this->catalogue->produits($famille, $recherche), $ventes, $avecCouts, $g['cible'], $g['minimum']);

        return $this->render('produits/index.html.twig', [
            'lignes' => $lignes,
            'familles' => $this->catalogue->familles(),
            'famille' => $famille,
            'recherche' => $recherche,
            'total' => \count($toutes),
            'indicateurs' => CatalogueProduits::indicateurs($toutes, $avecCouts, $g['cible']),
            'masques' => array_values(array_filter($toutes, static fn (array $l): bool => $l['produit']->isActif() && !$l['produit']->isVisibleEnLigne())),
            'garde_fous' => $g,
            'avec_couts' => $avecCouts,
            'voir_ca' => $this->isGranted('ROLE_STATS_GLOBAL') || $this->isGranted('ROLE_STATS_VENTES'),
            'modifiable' => $this->peutModifier(),
        ]);
    }

    /**
     * Export de la grille (CSV pour Excel). Coût et marge seulement avec le droit.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $avecCouts = $this->isGranted('ROLE_COUT_MARGE');
        $g = $this->gardeFous->valeurs();
        [$debut, $fin] = self::mois();
        $lignes = CatalogueProduits::lignes($this->catalogue->produits(), $this->catalogue->ventes($debut, $fin), $avecCouts, $g['cible'], $g['minimum']);

        return new Response(CatalogueProduits::csv($lignes, $avecCouts), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="grille-produits-%s.csv"', date('Y-m-d')),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request, EditionProduit $edition, OptionsPartagees $options, DelaisCommande $delais): Response
    {
        $this->refuserEnLigne();
        $produit = (new Produits())->setModeCalcul('unite');
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'produit-nouveau');
            $erreurs = $edition->appliquer($produit, $request->request->all(), $this->isGranted('ROLE_COUT_MARGE'), $this->utilisateur());
            if ([] === $erreurs) {
                $this->addFlash('success', \sprintf('Produit « %s » créé. Ajoutez ses configurations de prix pour le vendre.', $produit->getNom()));

                return $this->redirectToRoute('app_produits_fiche', ['id' => $produit->getId()]);
            }
            foreach ($erreurs as $e) {
                $this->addFlash('danger', $e);
            }
        }

        return $this->fiche($produit, $options, $delais);
    }

    #[Route('/{id}', name: 'fiche', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function voir(Produits $produit, Request $request, EditionProduit $edition, OptionsPartagees $options, DelaisCommande $delais): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
            $this->refuserEnLigne();
            $this->verifierJeton($request, 'produit-'.$produit->getId());
            $erreurs = $edition->appliquer($produit, $request->request->all(), $this->isGranted('ROLE_COUT_MARGE'), $this->utilisateur());
            if ([] === $erreurs) {
                $this->addFlash('success', 'Fiche enregistrée.');

                return $this->redirectToRoute('app_produits_fiche', ['id' => $produit->getId()]);
            }
            foreach ($erreurs as $e) {
                $this->addFlash('danger', $e);
            }
            $this->em->refresh($produit);
        }

        return $this->fiche($produit, $options, $delais);
    }

    #[Route('/{id}/dupliquer', name: 'dupliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function dupliquer(Produits $produit, Request $request, EditionProduit $edition): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'produit-dupliquer-'.$produit->getId());
        $copie = $edition->dupliquer($produit);
        $this->addFlash('success', \sprintf('Copie créée : « %s », masquée en ligne tant que vous ne l\'avez pas relue.', $copie->getNom()));

        return $this->redirectToRoute('app_produits_fiche', ['id' => $copie->getId()]);
    }

    /**
     * Interrupteur « En ligne » de la liste.
     */
    #[Route('/{id}/en-ligne', name: 'en_ligne', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enLigne(Produits $produit, Request $request): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'produit-en-ligne-'.$produit->getId());
        $visible = !$produit->isVisibleEnLigne();
        $produit->setVisibleEnLigne($visible);
        if (!$visible) {
            $produit->setMotifHorsLigne($request->request->getString('motif'));
        }
        $this->em->flush();
        $this->addFlash('success', $visible ? \sprintf('« %s » est de nouveau visible en ligne.', $produit->getNom()) : \sprintf('« %s » est masqué en ligne ; il reste vendu au comptoir.', $produit->getNom()));

        return $this->redirect($this->retour($request));
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Produits $produit, Request $request): Response
    {
        $this->refuserEnLigne();
        $this->verifierJeton($request, 'produit-supprimer-'.$produit->getId());
        if (!$produit->getCommandesDetails()->isEmpty()) {
            $this->addFlash('warning', 'Ce produit est déjà dans des commandes : désactivez-le (« En vente » décoché) au lieu de le supprimer.');

            return $this->redirectToRoute('app_produits_fiche', ['id' => $produit->getId()]);
        }
        $nom = $produit->getNom();
        $this->em->remove($produit);
        $this->em->flush();
        $this->addFlash('success', \sprintf('Produit « %s » supprimé.', $nom));

        return $this->redirectToRoute('app_produits_index');
    }

    private function fiche(Produits $produit, OptionsPartagees $options, DelaisCommande $delais): Response
    {
        $avecCouts = $this->isGranted('ROLE_COUT_MARGE');
        $g = $this->gardeFous->valeurs();
        $base = CoutProduit::configurationDeBase($produit);
        $mode = $base?->getModeCalcul() ?? $produit->getModeCalcul();
        $prix = CoutProduit::prixBase($produit, $base);
        $cout = $avecCouts ? CoutProduit::detail($produit, $mode) : null;

        $configurations = [];
        foreach ($produit->getConfigurations() as $c) {
            $parties = array_filter([$c->getSupport()?->getNom(), $c->getFormat()?->getNom(), $c->getTypeImpression()?->getNom()]);
            $coutConfig = $avecCouts ? CoutProduit::detail($produit, $c->getModeCalcul())['total'] : null;
            $configurations[] = [
                'configuration' => $c,
                'libelle' => [] === $parties ? 'Configuration '.$c->getId() : implode(' · ', $parties),
                'unite' => CoutProduit::unite($c->getModeCalcul()),
                'cout' => $coutConfig,
                'marge' => $avecCouts ? CoutProduit::marge($c->getPrixBase(), $coutConfig) : null,
                'margeRevendeur' => $avecCouts ? CoutProduit::marge($c->getPrixB2B(), $coutConfig) : null,
            ];
        }

        $optionsProduit = [];
        foreach ($produit->getConfigurations() as $c) {
            foreach ($c->getConfigurationFinitions() as $cf) {
                if ($cf->isActive() && $c->isActive() && null !== $cf->getFinition()) {
                    $optionsProduit[$cf->getFinition()->getId()]['nom'] = (string) $cf->getFinition()->getNom();
                    $optionsProduit[$cf->getFinition()->getId()]['liens'][] = ['prix' => $cf->getPrix(), 'payante' => $cf->isPayante(), 'mode' => $cf->getModeCalcul()];
                    $optionsProduit[$cf->getFinition()->getId()]['obligatoire'] = ($optionsProduit[$cf->getFinition()->getId()]['obligatoire'] ?? false) || $cf->isObligatoire();
                }
            }
        }
        $optionsProduit = array_map(static fn (array $o): array => ['nom' => $o['nom'], 'prix' => OptionsPartagees::resumePrix($o['liens'])['texte'], 'obligatoire' => $o['obligatoire']], array_values($optionsProduit));

        [$debut, $fin] = self::mois();
        $ventes = null === $produit->getId() ? [] : $this->catalogue->ventes($debut, $fin, $produit);
        $reglagesDelais = $delais->reglages();

        $simulation = [];
        foreach ($produit->getConfigurations() as $c) {
            if ($c->isActive() && (int) $c->getPrixBase() > 0) {
                $simulation[] = [
                    'id' => $c->getId(),
                    'libelle' => GrilleTarifaire::libelle($c),
                    'prix' => (int) $c->getPrixBase(),
                    'mode' => $c->getModeCalcul(),
                    'unite' => CoutProduit::unite($c->getModeCalcul()),
                    'largeur' => null !== $c->getLargeurDefaut() ? (float) $c->getLargeurDefaut() : null,
                    'hauteur' => null !== $c->getLongueurDefaut() ? (float) $c->getLongueurDefaut() : null,
                    // Le coût n'est écrit dans la page que pour qui a le droit de le voir.
                    'cout' => $avecCouts ? CoutProduit::detail($produit, $c->getModeCalcul())['total'] : null,
                    'coutComplet' => $avecCouts ? CoutProduit::detail($produit, $c->getModeCalcul())['complet'] : true,
                    // Paliers de prix et prix revendeur : le simulateur refait le calcul du serveur (public/js/paliers-prix.js).
                    'paliers' => PaliersPrix::pourLaPage($c),
                    'prixRevendeur' => $c->getPrixB2B(),
                ];
            }
        }

        $nouveau = null === $produit->getId();

        // Paliers de prix par configuration (docs/produits.md) : tranches à lire, lignes à saisir.
        $paliers = [];
        foreach ($produit->getConfigurations() as $c) {
            $quantite = $c->getPaliersQuantite();
            $surface = $c->getRemisesSurface();
            $paliers[] = [
                'configuration' => $c,
                'libelle' => GrilleTarifaire::libelle($c),
                'unite' => CoutProduit::unite($c->getModeCalcul()),
                'm2' => 'metre_carre' === $c->getModeCalcul(),
                'quantite' => $quantite,
                'surface' => $surface,
                'tranches' => [] === $quantite ? [] : PaliersPrix::tranches($quantite, (int) $c->getPrixBase(), $c->getQuantiteMinimale()),
                'lignesQuantite' => array_pad($quantite, min(PaliersPrix::MAX, \count($quantite) + 2), null),
                'lignesSurface' => array_pad($surface, min(PaliersPrix::MAX, \count($surface) + 2), null),
            ];
        }
        $paliersBase = null !== $base && $base->aDesPaliers() ? [
            'quantite' => PaliersPrix::tranches($base->getPaliersQuantite(), (int) $base->getPrixBase(), $base->getQuantiteMinimale()),
            'surface' => 'metre_carre' === $base->getModeCalcul() ? $base->getRemisesSurface() : [],
        ] : null;

        return $this->render('produits/fiche.html.twig', [
            'produit' => $produit,
            'nouveau' => $nouveau,
            'base' => $base,
            'prix' => $prix,
            'unite' => CoutProduit::unite($mode),
            'mode' => $mode,
            'cout' => $cout,
            'marge' => $avecCouts ? CoutProduit::marge($prix, $cout['total'] ?? null) : null,
            'configurations' => $configurations,
            'options_produit' => $optionsProduit,
            'delais' => $reglagesDelais,
            'ventes' => $ventes[(int) $produit->getId()] ?? ['ca' => 0, 'quantite' => 0.0, 'clients' => 0],
            'simulation' => $simulation,
            'paliers' => $paliers,
            'paliers_base' => $paliersBase,
            'noms_client' => NomCommercial::client($produit->getNomCommercial(), (string) $produit->getNom()),
            'clients_revendeurs' => $this->em->getRepository(Clients::class)->count(['typeClient' => 'B2B']),
            'prix_speciaux' => $nouveau ? 0 : (int) $this->em->createQueryBuilder()->select('COUNT(ps.id)')->from(PrixSpecial::class, 'ps')->innerJoin('ps.configuration', 'pc')
                ->where('pc.produit = :p')->andWhere('ps.statut = :actif')->setParameter('p', $produit)->setParameter('actif', PrixSpecial::ACTIF)->getQuery()->getSingleScalarResult(),
            'garde_fous' => $g,
            'avec_couts' => $avecCouts,
            'voir_ca' => $this->isGranted('ROLE_STATS_GLOBAL') || $this->isGranted('ROLE_STATS_VENTES'),
            'modifiable' => $this->peutModifier(),
            'categories' => $this->em->getRepository(CategorieProduit::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'machines' => $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']),
            'modes' => EditionProduit::MODES,
            'modes_nomenclature' => EditionProduit::MODES_NOMENCLATURE,
            'types' => $this->em->getRepository(TypesImpression::class)->findBy([], ['nom' => 'ASC']),
            'supports' => $this->em->getRepository(Supports::class)->findBy([], ['nom' => 'ASC']),
            'formats' => $this->em->getRepository(Format::class)->findBy([], ['nom' => 'ASC']),
            'finitions' => $this->em->getRepository(Finition::class)->findBy([], ['nom' => 'ASC']),
            'articles' => $this->peutModifier() ? $this->em->getRepository(Articles::class)->findBy(['actif' => true], ['designation' => 'ASC']) : [],
        ]);
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private static function mois(): array
    {
        $debut = new \DateTimeImmutable('first day of this month 00:00');

        return [$debut, $debut->modify('+1 month')];
    }

    private function peutModifier(): bool
    {
        return $this->isGranted('ROLE_ADMIN') && !$this->acces->estEnLigne();
    }

    private function refuserEnLigne(): void
    {
        if ($this->acces->estEnLigne()) {
            throw $this->createAccessDeniedException('Le catalogue se modifie sur l\'ERP de l\'atelier, puis se synchronise.');
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

    private function retour(Request $request): string
    {
        $retour = $request->request->getString('retour');

        return str_starts_with($retour, '/produits') && !str_starts_with($retour, '//') ? $retour : $this->generateUrl('app_produits_index');
    }
}
