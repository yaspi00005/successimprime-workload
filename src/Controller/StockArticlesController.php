<?php

namespace App\Controller;

use App\Entity\ArticleFournisseur;
use App\Entity\Articles;
use App\Entity\StockEntrees;
use App\Entity\StockSorties;
use App\Repository\ArticlesRepository;
use App\Repository\FamilleArticleRepository;
use App\Repository\FournisseursRepository;
use App\Repository\MachinesRepository;
use App\Service\CatalogueStock;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Articles et stock (maquette S01) et fiche article (maquette S02).
 */
#[Route('/stock/articles', name: 'app_stock_articles_')]
#[IsGranted('ROLE_ADMIN')]
final class StockArticlesController extends AbstractController
{
    public const MOTIFS_AJUSTEMENT = [
        'Comptage',
        'Casse ou chute',
        'Erreur de saisie',
        'Échantillon ou usage interne',
        'Retour fournisseur',
    ];

    public function __construct(
        private readonly ArticlesRepository $articles,
        private readonly FamilleArticleRepository $familles,
        private readonly CatalogueStock $catalogue,
        private readonly StockService $stock,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $filtre = (string) $request->query->get('filtre', 'tous');
        $recherche = trim((string) $request->query->get('q', ''));

        $toutes = $this->catalogue->lignes($this->articles->findBy([], ['reference' => 'ASC']), $maintenant);
        $indicateurs = CatalogueStock::indicateurs($toutes);

        $lignes = array_values(array_filter($toutes, static function (array $ligne) use ($filtre, $recherche): bool {
            $article = $ligne['article'];

            if ($recherche !== '' && !str_contains(mb_strtolower($article->getReference().' '.$article->getDesignation()), mb_strtolower($recherche))) {
                return false;
            }

            return match (true) {
                $filtre === 'sous-seuil' => $ligne['etat'] === CatalogueStock::ETAT_COMMANDER,
                $filtre === 'dormants' => $ligne['dormant'],
                ctype_digit($filtre) => $article->getFamille()?->getId() === (int) $filtre,
                default => true,
            };
        }));

        return $this->render('stock_articles/index.html.twig', [
            'lignes' => $lignes,
            'indicateurs' => $indicateurs,
            'familles' => $this->familles->dansLOrdre(),
            'filtre' => $filtre,
            'recherche' => $recherche,
        ]);
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): StreamedResponse
    {
        $lignes = $this->catalogue->lignes($this->articles->findBy([], ['reference' => 'ASC']), new \DateTimeImmutable());
        $etats = [CatalogueStock::ETAT_OK => 'OK', CatalogueStock::ETAT_BIENTOT => 'Bientôt', CatalogueStock::ETAT_COMMANDER => 'Commander'];

        $reponse = new StreamedResponse(static function () use ($lignes, $etats): void {
            $sortie = fopen('php://output', 'wb');
            // BOM pour qu'Excel lise les accents.
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Code', 'Article', 'Famille', 'Unité', 'En stock', 'Réservé', 'Disponible', 'Seuil', 'Maximum', 'Coût moyen', 'Valeur', 'Fournisseur', 'Emplacement', 'État'], ';', '"', '');

            foreach ($lignes as $ligne) {
                $article = $ligne['article'];
                fputcsv($sortie, [
                    $article->getReference(),
                    $article->getDesignation(),
                    $article->getFamille()?->getNom(),
                    $article->getUniteCourte(),
                    self::nombreExcel($ligne['physique']),
                    self::nombreExcel($ligne['reserve']),
                    self::nombreExcel($ligne['disponible']),
                    self::nombreExcel($article->getStockMin()),
                    $article->getStockMax() !== null ? self::nombreExcel($article->getStockMax()) : '',
                    $article->getCoutUnitaireStock(),
                    $ligne['valeur'],
                    $article->getFournisseurPrincipal()?->getFournisseur()?->getNom(),
                    $article->getEmplacement(),
                    $etats[$ligne['etat']],
                ], ';', '"', '');
            }

            fclose($sortie);
        });

        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="articles-stock-%s.csv"', date('Y-m-d')));

        return $reponse;
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, FournisseursRepository $fournisseurs, MachinesRepository $machines): Response
    {
        return $this->formulaire(new Articles(), $request, $fournisseurs, $machines);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Articles $article, Request $request, FournisseursRepository $fournisseurs, MachinesRepository $machines): Response
    {
        return $this->formulaire($article, $request, $fournisseurs, $machines);
    }

    #[Route('/{id}', name: 'fiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fiche(Articles $article): Response
    {
        $maintenant = new \DateTimeImmutable();
        $ligne = $this->catalogue->lignes([$article], $maintenant)[0];
        $consommation = $this->catalogue->consommationParMois($article, $maintenant);

        return $this->render('stock_articles/fiche.html.twig', [
            'article' => $article,
            'ligne' => $ligne,
            'consommation' => $consommation,
            'maxConsommation' => max(1, ...array_column($consommation, 'quantite')),
            'couverture' => CatalogueStock::couverture($consommation, $ligne['disponible']),
            'derniersAchats' => $this->catalogue->derniersAchats($article),
            'mouvements' => $this->catalogue->derniersMouvements($article),
            'motifs' => self::MOTIFS_AJUSTEMENT,
        ]);
    }

    /**
     * « Ajuster le stock » : on saisit la quantité réellement comptée,
     * l'écart devient une entrée ou une sortie avec son motif.
     */
    #[Route('/{id}/ajuster', name: 'ajuster', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajuster(Articles $article, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ajuster-stock-'.$article->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $compte = self::nombre($request->request->get('compte'));
        $motif = (string) $request->request->get('motif');

        if ($compte === null || $compte < 0 || !\in_array($motif, self::MOTIFS_AJUSTEMENT, true)) {
            $this->addFlash('error', 'Indiquez la quantité comptée et le motif.');

            return $this->redirectToRoute('app_stock_articles_fiche', ['id' => $article->getId()]);
        }

        $ecart = round($compte - $this->stock->getStockPhysique($article), 3);
        $reference = mb_substr(sprintf('Ajustement · %s · %s', $motif, $this->getUser()?->getUserIdentifier()), 0, 100);

        if ($ecart > 0) {
            // Une remise à niveau ne change pas le coût moyen : on la valorise à ce coût.
            $this->em->persist((new StockEntrees())
                ->setArticle($article)
                ->setQuantites($ecart)
                ->setPrix($article->getCoutUnitaireStock() ?? 0)
                ->setDate(new \DateTimeImmutable()));
            $this->em->flush();
        } elseif ($ecart < 0) {
            $this->stock->enregistrerConsommableManuel(null, $article, -$ecart, $reference);
        }

        $this->addFlash('success', $ecart == 0
            ? 'Le stock compté correspond déjà au stock enregistré.'
            : sprintf('Stock ajusté de %s%s %s (%s).', $ecart > 0 ? '+' : '−', self::format(abs($ecart)), $article->getUniteCourte(), $motif));

        return $this->redirectToRoute('app_stock_articles_fiche', ['id' => $article->getId()]);
    }

    private function formulaire(Articles $article, Request $request, FournisseursRepository $fournisseurs, MachinesRepository $machines): Response
    {
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('article-stock', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $donnees = $request->request;
            $famille = self::entite($this->familles, $donnees->get('famille'));
            $designation = trim((string) $donnees->get('designation'));
            $reference = strtoupper(trim((string) $donnees->get('reference')));

            if ($designation === '') {
                $erreurs[] = 'La désignation est obligatoire.';
            }

            if ($reference === '') {
                $reference = $this->prochaineReference($famille?->getPrefixe() ?: 'ART');
            }

            $doublon = $this->articles->findOneBy(['reference' => $reference]);

            if ($doublon !== null && $doublon !== $article) {
                $erreurs[] = sprintf('Le code %s est déjà pris par « %s ».', $reference, $doublon->getDesignation());
            }

            $minimum = self::nombre($donnees->get('stock_min')) ?? 0.0;
            $maximum = self::nombre($donnees->get('stock_max'));

            if ($maximum !== null && $maximum < $minimum) {
                $erreurs[] = 'Le maximum doit être supérieur au minimum.';
            }

            $article
                ->setReference($reference)
                ->setDesignation($designation !== '' ? $designation : (string) $article->getDesignation())
                ->setFamille($famille)
                ->setUnite(\array_key_exists((string) $donnees->get('unite'), Articles::UNITES_COURTES) ? (string) $donnees->get('unite') : 'unite')
                ->setUniteAchat($donnees->get('unite_achat'))
                ->setQuantiteParUniteAchat($donnees->get('quantite_par_unite_achat'))
                ->setLongueurUniteAchat($donnees->get('longueur_unite_achat'))
                ->setLaize($donnees->get('laize'))
                ->setUniteVente($donnees->get('unite_vente'))
                ->setStockMin($minimum)
                ->setStockMax($maximum)
                ->setEmplacement($donnees->get('emplacement'))
                ->setMachine(self::entite($machines, $donnees->get('machine')))
                ->setPrixVente(($p = self::nombre($donnees->get('prix_vente'))) !== null ? (int) round($p) : null)
                ->setDescription(trim((string) $donnees->get('description')) ?: null)
                ->setActif($donnees->getBoolean('actif'))
                ->setVendable($donnees->getBoolean('vendable'))
                ->setConsommableProduction($donnees->getBoolean('consommable_production'));

            $this->appliquerFournisseurs($article, $request, $fournisseurs);

            if ($erreurs === []) {
                $this->em->persist($article);
                $this->em->flush();
                $this->addFlash('success', sprintf('Article %s enregistré.', $article->getReference()));

                return $this->redirectToRoute('app_stock_articles_fiche', ['id' => $article->getId()]);
            }
        }

        return $this->render('stock_articles/formulaire.html.twig', [
            'article' => $article,
            'familles' => $this->familles->dansLOrdre(),
            'fournisseurs' => $fournisseurs->findBy([], ['nom' => 'ASC']),
            'machines' => $machines->findBy([], ['nom' => 'ASC']),
            'unites' => Articles::UNITES_COURTES,
            'erreurs' => $erreurs,
        ]);
    }

    /**
     * Lignes fournisseur du formulaire : fournisseur, prix par unité d'achat,
     * délai, et un seul fournisseur principal.
     */
    private function appliquerFournisseurs(Articles $article, Request $request, FournisseursRepository $fournisseurs): void
    {
        $ids = $request->request->all('fournisseur_id');
        $prix = $request->request->all('fournisseur_prix');
        $delais = $request->request->all('fournisseur_delai');
        $principal = (string) $request->request->get('fournisseur_principal', '0');
        $gardes = [];

        foreach ($ids as $i => $id) {
            $fournisseur = ctype_digit((string) $id) ? $fournisseurs->find((int) $id) : null;

            if ($fournisseur === null || isset($gardes[$fournisseur->getId()])) {
                continue;
            }

            $lien = null;

            foreach ($article->getFournisseurs() as $existant) {
                if ($existant->getFournisseur() === $fournisseur) {
                    $lien = $existant;
                }
            }

            $lien ??= (new ArticleFournisseur())->setFournisseur($fournisseur);
            $prixLigne = self::nombre($prix[$i] ?? null);
            $delai = self::nombre($delais[$i] ?? null);

            $lien
                ->setPrixAchat($prixLigne !== null ? (int) round($prixLigne) : null)
                ->setDelaiJours($delai !== null ? (int) $delai : null)
                ->setPrincipal((string) $i === $principal);

            $article->addFournisseur($lien);
            $gardes[$fournisseur->getId()] = $lien;
        }

        foreach ($article->getFournisseurs()->toArray() as $existant) {
            if (!\in_array($existant, $gardes, true)) {
                $article->removeFournisseur($existant);
            }
        }

        // Compatibilité : l'ancien champ fournisseur et le prix d'achat suivent le principal.
        $principalLien = $article->getFournisseurPrincipal();

        if ($principalLien !== null && !$principalLien->isPrincipal()) {
            $principalLien->setPrincipal(true);
        }

        $article->setFournisseur($principalLien?->getFournisseur());

        if ($principalLien?->getPrixAchat() !== null) {
            $article->setPrixAchat($principalLien->getPrixAchat());
        }
    }

    private function prochaineReference(string $prefixe): string
    {
        $references = $this->em->createQueryBuilder()
            ->select('a.reference')
            ->from(Articles::class, 'a')
            ->where('a.reference LIKE :prefixe')
            ->setParameter('prefixe', $prefixe.'-%')
            ->getQuery()
            ->getSingleColumnResult();

        $plusGrand = 0;

        foreach ($references as $reference) {
            if (preg_match('/-(\d+)$/', (string) $reference, $m)) {
                $plusGrand = max($plusGrand, (int) $m[1]);
            }
        }

        return sprintf('%s-%03d', $prefixe, $plusGrand + 1);
    }

    /**
     * @template T of object
     *
     * @param \Doctrine\Persistence\ObjectRepository<T> $depot
     *
     * @return T|null
     */
    private static function entite(\Doctrine\Persistence\ObjectRepository $depot, mixed $id): ?object
    {
        return ctype_digit((string) $id) ? $depot->find((int) $id) : null;
    }

    private static function nombre(mixed $valeur): ?float
    {
        $valeur = str_replace([' ', "\u{202F}", ','], ['', '', '.'], trim((string) $valeur));

        return $valeur !== '' && is_numeric($valeur) ? (float) $valeur : null;
    }

    private static function format(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 3, ',', ' '), '0'), ',');
    }

    private static function nombreExcel(float $valeur): string
    {
        return str_replace('.', ',', (string) round($valeur, 3));
    }
}
