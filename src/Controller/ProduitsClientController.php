<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\ProduitClient;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Repository\ProduitClientRepository;
use App\Service\CalculateurCoutRevient;
use App\Service\CommandeProduitClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogue des produits d'un client (« Mes produits », maquette P04).
 */
#[Route('/clients/fiche/{publicId}/produits', name: 'app_clients_produits')]
#[IsGranted('ROLE_COMMANDE')]
final class ProduitsClientController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProduitClientRepository $produitsClient,
    ) {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(
        #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client,
        Request $request,
        CommandeProduitClient $commandeProduitClient,
        CalculateurCoutRevient $calculateur,
    ): Response {
        $produits = $this->produitsClient->findBy(['client' => $client], ['actif' => 'DESC', 'nom' => 'ASC']);
        $choisi = null;

        if ('nouveau' !== $request->query->get('produit')) {
            foreach ($produits as $produit) {
                if ((string) $produit->getId() === $request->query->get('produit')) {
                    $choisi = $produit;
                }
            }

            $choisi ??= $produits[0] ?? null;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('produit-client-'.$client->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $produit = $choisi ?? (new ProduitClient())->setClient($client)->setReference($this->produitsClient->prochaineReference($client));
            $erreur = $this->remplir($produit, $request);

            if (null === $erreur) {
                $this->em->persist($produit);
                $this->em->flush();
                $this->addFlash('success', sprintf('Produit %s enregistré.', $produit->getReference()));

                return $this->redirectToRoute('app_clients_produits', ['publicId' => $client->getPublicId(), 'produit' => $produit->getId()]);
            }

            $this->addFlash('danger', $erreur);
        }

        return $this->render('produits_client/index.html.twig', [
            'client' => $client,
            'produits' => $produits,
            'choisi' => $choisi,
            'nombresCommandes' => $this->nombresCommandes($produits),
            'cout' => $choisi ? $this->coutUnitaire($choisi, $commandeProduitClient, $calculateur) : null,
            'catalogue' => $this->em->getRepository(Produits::class)->findBy([], ['nom' => 'ASC']),
            'supports' => $this->em->getRepository(Supports::class)->findBy([], ['nom' => 'ASC']),
            'machines' => $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']),
            'finitions' => $this->em->getRepository(Finition::class)->findBy([], ['nom' => 'ASC']),
        ]);
    }

    /**
     * « Enregistrer comme produit du client » depuis une ligne livree :
     * reprend le format, la machine, les finitions, le dernier fichier et
     * le BAT valide, au prix de la ligne.
     */
    #[Route('/depuis-ligne/{id}', name: '_depuis_ligne', methods: ['POST'])]
    public function depuisLigne(
        #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client,
        #[MapEntity(id: 'id')] CommandesDetails $ligne,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid('produit-client-ligne-'.$ligne->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        if ($ligne->getCommande()?->getClients() !== $client) {
            throw $this->createNotFoundException();
        }

        $produit = (new ProduitClient())
            ->setClient($client)
            ->setReference($this->produitsClient->prochaineReference($client))
            ->setNom($ligne->getProduit()?->getNom() ?? (string) $ligne->getDesignation())
            ->setProduit($ligne->getProduit())
            ->setSupport($ligne->getSupport())
            ->setMachine($ligne->getMachine())
            ->setFormat($ligne->getLargeur() ? (float) $ligne->getLargeur() : null, $ligne->getLongueur() ? (float) $ligne->getLongueur() : null)
            ->setUniteVente($ligne->getLargeur() && $ligne->getLongueur() ? ProduitClient::UNITE_PIECE_OU_M2 : ProduitClient::UNITE_PIECE)
            ->setPrixUnitaire((int) round($ligne->getTotalTtc() / max(1, $ligne->getQuantite())))
            ->setLigneOrigine($ligne)
            ->setBatValide($ligne->getDernierControlePrePresse());

        foreach ($ligne->getFinitions() as $finition) {
            if ($finition->getFinition()) {
                $produit->addFinition($finition->getFinition());
            }
        }

        $fichiers = $ligne->getFichiers()->toArray();
        $produit->setFichier(end($fichiers) ?: null);

        $this->em->persist($produit);
        $this->em->flush();

        $this->addFlash('success', sprintf('« %s » est maintenant un produit du client (%s). Vérifiez le prix et l\'unité de vente.', $produit->getNom(), $produit->getReference()));

        return $this->redirectToRoute('app_clients_produits', ['publicId' => $client->getPublicId(), 'produit' => $produit->getId()]);
    }

    /**
     * Applique le formulaire ; renvoie un message d'erreur ou null.
     */
    private function remplir(ProduitClient $produit, Request $request): ?string
    {
        $saisie = $request->getPayload();
        $nom = trim($saisie->getString('nom'));

        if ('' === $nom) {
            return 'Le nom du produit est obligatoire.';
        }

        $unite = $saisie->getString('uniteVente');

        if (!\array_key_exists($unite, ProduitClient::UNITES_LABELS)) {
            return 'Unité de vente invalide.';
        }

        $largeur = self::nombre($saisie->getString('largeur'));
        $hauteur = 'libre' === $saisie->getString('typeFormat') ? null : self::nombre($saisie->getString('hauteur'));

        if (\in_array($unite, [ProduitClient::UNITE_M2, ProduitClient::UNITE_PIECE_OU_M2], true) && null === $largeur) {
            return 'Une vente au m² demande au moins la largeur (format ou rouleau).';
        }

        if (ProduitClient::UNITE_LOT === $unite && !self::nombre($saisie->getString('tailleLot'))) {
            return 'Une vente par lot demande la taille du lot.';
        }

        $produit
            ->setNom($nom)
            ->setProduit($this->trouver(Produits::class, $saisie->getString('produit')))
            ->setSupport($this->trouver(Supports::class, $saisie->getString('support')))
            ->setMachine($this->trouver(Machines::class, $saisie->getString('machine')))
            ->setFormat(null === $largeur ? null : $largeur / 100, null === $hauteur ? null : $hauteur / 100)
            ->setUniteVente($unite)
            ->setPrixUnitaire((int) (self::nombre($saisie->getString('prixUnitaire')) ?? 0))
            ->setQuantiteMinimum((int) (self::nombre($saisie->getString('quantiteMinimum')) ?? 1))
            ->setTailleLot(ProduitClient::UNITE_LOT === $unite ? (int) self::nombre($saisie->getString('tailleLot')) : null)
            ->setPosesParFeuille(ProduitClient::UNITE_LOT === $unite && self::nombre($saisie->getString('posesParFeuille')) ? (int) self::nombre($saisie->getString('posesParFeuille')) : null)
            ->setArrondiSuperieurImpose('superieur' === $saisie->getString('arrondi'))
            ->setActif($saisie->getBoolean('actif'));

        $choisies = array_map('intval', $saisie->all('finitions'));

        foreach ($produit->getFinitions()->toArray() as $finition) {
            if (!\in_array($finition->getId(), $choisies, true)) {
                $produit->removeFinition($finition);
            }
        }

        foreach ($choisies as $id) {
            if ($finition = $this->em->find(Finition::class, $id)) {
                $produit->addFinition($finition);
            }
        }

        return null;
    }

    /**
     * Cout et marge d'une unite vendue (piece, m2 ou lot), calcules sur
     * une ligne fictive non enregistree.
     *
     * @return array{montant: int, marge: ?float, unite: string, manquants: list<string>}|null
     */
    private function coutUnitaire(ProduitClient $produit, CommandeProduitClient $service, CalculateurCoutRevient $calculateur): ?array
    {
        try {
            $saisie = match ($produit->getUniteVente()) {
                ProduitClient::UNITE_M2 => CommandeProduitClient::SAISIE_M2,
                ProduitClient::UNITE_LOT => CommandeProduitClient::SAISIE_LOTS,
                default => CommandeProduitClient::SAISIE_PIECES,
            };
            $quantite = match ($saisie) {
                CommandeProduitClient::SAISIE_LOTS => (float) ($produit->getTailleLot() ?? 1),
                default => max(1.0, (float) $produit->getQuantiteMinimum()),
            };
            $ligne = $service->creerLigne(new Commandes(), $produit, $quantite, $saisie);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $calcul = $calculateur->pourLigne($ligne);
        $unites = CommandeProduitClient::SAISIE_LOTS === $saisie ? 1 : max(1.0, $quantite);
        $montant = (int) round($calcul['total'] / $unites);

        return [
            'montant' => $montant,
            'marge' => CalculateurCoutRevient::tauxMarge((int) round($ligne->getTotalHt() / $unites), $montant),
            'unite' => match ($saisie) {
                CommandeProduitClient::SAISIE_M2 => 'm²',
                CommandeProduitClient::SAISIE_LOTS => 'lot',
                default => 'pièce',
            },
            'manquants' => $calcul['manquants'],
        ];
    }

    /**
     * @param list<ProduitClient> $produits
     *
     * @return array<int, int>
     */
    private function nombresCommandes(array $produits): array
    {
        if ([] === $produits) {
            return [];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.produitClient) AS produit, COUNT(DISTINCT l.commande) AS nombre')
            ->from(CommandesDetails::class, 'l')
            ->where('l.produitClient IN (:produits)')
            ->setParameter('produits', $produits)
            ->groupBy('l.produitClient')
            ->getQuery()
            ->getArrayResult();

        return array_column($lignes, 'nombre', 'produit');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    private function trouver(string $classe, string $id): ?object
    {
        return ctype_digit($id) ? $this->em->find($classe, (int) $id) : null;
    }

    private static function nombre(string $valeur): ?float
    {
        $valeur = str_replace([' ', ' ', ','], ['', '', '.'], trim($valeur));

        return is_numeric($valeur) && (float) $valeur > 0 ? (float) $valeur : null;
    }
}
