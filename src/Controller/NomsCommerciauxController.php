<?php

namespace App\Controller;

use App\Entity\Articles;
use App\Entity\AUnNomCommercial;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Service\Catalogue\NomCommercial;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Noms commerciaux du catalogue (demande de Yaya du 16/10/2026,
 * docs/noms-commerciaux.md) : à côté de chaque désignation technique, le
 * nom que tout le monde connaît, saisi en une fois pour les produits, les
 * supports, les types d'impression et les articles vendus. Réservé à la
 * direction, comme le reste du catalogue.
 */
#[Route('/produits/noms-commerciaux', name: 'app_produits_noms_commerciaux')]
#[IsGranted('ROLE_ADMIN')]
final class NomsCommerciauxController extends AbstractController
{
    public const JETON = 'noms_commerciaux';

    /** Rubriques de la page : clé du formulaire => entité, titre et aide. */
    public const RUBRIQUES = [
        'produits' => ['classe' => Produits::class, 'titre' => 'Produits', 'aide' => 'Ce que le client commande : sur le site, dans l\'espace client, sur les devis et les factures.'],
        'supports' => ['classe' => Supports::class, 'titre' => 'Supports et matières', 'aide' => 'La matière imprimée, choisie avec le produit.'],
        'types' => ['classe' => TypesImpression::class, 'titre' => 'Types d\'impression', 'aide' => 'La façon d\'imprimer, choisie avec le produit.'],
        // Yaya, 18/10/2026 : aussi les formats et les finitions.
        'formats' => ['classe' => Format::class, 'titre' => 'Formats', 'aide' => 'La taille choisie avec le produit (« Carte de visite » pour 85 × 55).'],
        'finitions' => ['classe' => Finition::class, 'titre' => 'Finitions', 'aide' => 'Ce qu\'on ajoute après l\'impression (« Trous pour accrocher » pour œillets).'],
        'articles' => ['classe' => Articles::class, 'titre' => 'Articles vendus', 'aide' => 'Articles de stock vendus tels quels (case « vendable » de la fiche article). Ils restent à l\'ERP local.'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $rubriques = [];
        foreach (self::RUBRIQUES as $cle => $r) {
            $elements = $this->elements($cle);
            $rubriques[$cle] = $r + [
                'elements' => $elements,
                'remplis' => \count(array_filter($elements, static fn (AUnNomCommercial $e): bool => null !== $e->getNomCommercial())),
            ];
        }

        return $this->render('produits/noms_commerciaux.html.twig', [
            'rubriques' => $rubriques,
            'longueur' => NomCommercial::LONGUEUR,
        ]);
    }

    /**
     * Enregistre les noms reçus (noms[rubrique][id]) ; un nom absent du
     * formulaire ne change pas, un nom vidé est retiré.
     */
    #[Route('', name: '_enregistrer', methods: ['POST'])]
    public function enregistrer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::JETON, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité périmé : rechargez la page puis recommencez.');

            return $this->redirectToRoute('app_produits_noms_commerciaux');
        }

        $recus = $request->request->all('noms');
        $changes = 0;
        foreach (self::RUBRIQUES as $cle => $r) {
            $valeurs = \is_array($recus[$cle] ?? null) ? $recus[$cle] : [];
            $ids = array_values(array_filter(array_map('intval', array_keys($valeurs)), static fn (int $id): bool => $id > 0));
            if ([] === $ids) {
                continue;
            }
            /** @var list<AUnNomCommercial> $elements */
            $elements = $this->em->getRepository($r['classe'])->findBy(['id' => $ids]);
            foreach ($elements as $element) {
                $nouveau = NomCommercial::nettoyer(\is_string($v = $valeurs[$element->getId()] ?? null) ? $v : null);
                if ($nouveau !== $element->getNomCommercial()) {
                    $element->setNomCommercial($nouveau);
                    ++$changes;
                }
            }
        }
        $this->em->flush();

        $this->addFlash($changes > 0 ? 'success' : 'info', match (true) {
            0 === $changes => 'Aucun nom commercial n\'a changé.',
            1 === $changes => 'Un nom commercial enregistré.',
            default => \sprintf('%d noms commerciaux enregistrés.', $changes),
        });

        return $this->redirectToRoute('app_produits_noms_commerciaux');
    }

    /** @return list<AUnNomCommercial> */
    private function elements(string $rubrique): array
    {
        return match ($rubrique) {
            'produits' => $this->em->getRepository(Produits::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'supports' => $this->em->getRepository(Supports::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'types' => $this->em->getRepository(TypesImpression::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'formats' => $this->em->getRepository(Format::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']),
            'finitions' => $this->em->getRepository(Finition::class)->findBy([], ['nom' => 'ASC']),
            'articles' => $this->em->getRepository(Articles::class)->findBy(['vendable' => true], ['designation' => 'ASC']),
            default => [],
        };
    }
}
