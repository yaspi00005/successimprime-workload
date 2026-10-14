<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\ProduitClient;
use App\Service\CommandeProduitClient;
use App\Service\Synchro\Synchro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Mes produits » côté client (maquettes P01 à P03, docs/mes-produits.md) :
 * les produits déjà validés du client, la quantité en pièces, en m² ou par
 * lot convertie par CommandeProduitClient, et la commande de la sélection.
 * Le prix vient toujours de la fiche du produit, jamais du navigateur.
 */
final class MesProduits
{
    /** Produits différents dans une même commande. */
    public const SELECTION_MAX = 12;

    /** Quantité saisie au plus (pièces, m² ou exemplaires). */
    public const QUANTITE_MAX = 100000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommandeProduitClient $conversion,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly EspaceClient $espace,
    ) {
    }

    /** @return list<ProduitClient> */
    public function liste(Clients $client): array
    {
        return $this->em->getRepository(ProduitClient::class)->findBy(['client' => $client, 'actif' => true], ['reference' => 'ASC']);
    }

    public function trouver(Clients $client, string $publicId): ?ProduitClient
    {
        foreach ($this->liste($client) as $p) {
            if ((string) $p->getPublicId() === $publicId) {
                return $p;
            }
        }

        return null;
    }

    /** Façons de saisir la quantité, la première par défaut. */
    public static function saisies(ProduitClient $p): array
    {
        return match (true) {
            ProduitClient::UNITE_LOT === $p->getUniteVente() => [CommandeProduitClient::SAISIE_LOTS],
            $p->estLongueurLibre() => [CommandeProduitClient::SAISIE_M2],
            $p->estFormatFixe() && ProduitClient::UNITE_PIECE_OU_M2 === $p->getUniteVente() => [CommandeProduitClient::SAISIE_PIECES, CommandeProduitClient::SAISIE_M2],
            $p->estFormatFixe() && ProduitClient::UNITE_M2 === $p->getUniteVente() => [CommandeProduitClient::SAISIE_M2],
            default => [CommandeProduitClient::SAISIE_PIECES],
        };
    }

    /**
     * Ce que montre la carte du produit. Sans $montants (demandeur,
     * CompteClient::montantsVisibles), pas de prix : « prix » reste null.
     *
     * @return array<string, mixed>
     */
    public function fiche(ProduitClient $p, bool $montants = true): array
    {
        $saisies = self::saisies($p);
        $lot = (int) ($p->getTailleLot() ?? 1);
        $image = null;
        if (null !== $p->getFichier() && str_starts_with((string) $p->getFichier()->getTypeMime(), 'image/')) {
            $image = $p->getFichier();
        } elseif (null !== $p->getBatValide()) {
            $image = $this->espace->imagesBat($p->getBatValide())[0] ?? null;
        }

        return [
            'p' => $p,
            'id' => (string) $p->getPublicId(),
            'saisies' => $saisies,
            'badge' => match (true) {
                ProduitClient::UNITE_LOT === $p->getUniteVente() => 'par lot',
                \count($saisies) > 1 => 'm² ou pièces',
                [CommandeProduitClient::SAISIE_M2] === $saisies => 'au m²',
                default => 'à l\'unité',
            },
            'prix' => !$montants ? null : match ($p->getUniteVente()) {
                ProduitClient::UNITE_LOT => \sprintf('%s F / %s', self::f($p->getPrixUnitaire()), self::f($lot)),
                ProduitClient::UNITE_M2 => \sprintf('%s F / m²', self::f($p->getPrixUnitaire())),
                default => \sprintf('%s F / pièce', self::f($p->getPrixUnitaire())),
            },
            'format' => self::format($p),
            'batLe' => $p->getBatValide()?->getValideLe(),
            'categorie' => $p->getProduit()?->getCategorieProduit()?->getNom(),
            'image' => $image instanceof CommandeDetailFichier && null !== $this->espace->cheminSurDisque($image) ? $image->getId() : null,
            'pas' => match ($saisies[0]) {
                CommandeProduitClient::SAISIE_LOTS => $lot,
                default => 1,
            },
            'raccourcis' => match ($saisies[0]) {
                CommandeProduitClient::SAISIE_LOTS => [$lot, 2 * $lot, 4 * $lot, 10 * $lot],
                CommandeProduitClient::SAISIE_M2 => [2, 4, 6, 10],
                default => [1, 2, 5, 10],
            },
            'raccourcisM2' => [6, 12, 20, 30],
            'minimum' => $p->getQuantiteMinimum(),
        ];
    }

    /**
     * Ce que l'atelier imprimera et le prix, pour la quantité saisie.
     *
     * @return array{quantite: float, saisie: string, inferieur: bool, pieces: int, surface: float, longueur: ?float, montant: int, production: string, resume: string, detail: string, alternative: ?array{pieces: int, surface: float, montant: int}}
     *
     * @throws \DomainException message à montrer au client
     */
    public function calculer(ProduitClient $p, float $quantite, string $saisie, bool $inferieur = false): array
    {
        if (!\in_array($saisie, self::saisies($p), true)) {
            $saisie = self::saisies($p)[0];
        }
        if ($quantite <= 0 || $quantite > self::QUANTITE_MAX) {
            throw new \DomainException('Indiquez une quantité.');
        }
        try {
            $r = $this->conversion->proposer($p, $quantite, $saisie, $inferieur);
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage());
        }
        $inferieur = $inferieur && null !== $r['alternative'] && $r['alternative']['pieces'] > $r['pieces'];
        [$resume, $detail] = match (true) {
            CommandeProduitClient::SAISIE_LOTS === $saisie => [\sprintf('%s ex.', self::f($r['pieces'])), $r['production']],
            null !== $r['longueur'] => [\sprintf('%s m²', self::n($quantite)), \sprintf('= %s m linéaires', self::n($r['longueur']))],
            $p->estFormatFixe() => [self::pieces($r['pieces']), \sprintf('= %s m²', self::n($r['surface']))],
            default => [self::pieces($r['pieces']), ''],
        };

        return $r + ['quantite' => $quantite, 'saisie' => $saisie, 'inferieur' => $inferieur, 'resume' => $resume, 'detail' => $detail];
    }

    /**
     * Ce que la page reçoit du calcul (feuille de quantité) : la conversion
     * toujours, le prix seulement pour qui voit les montants. Un demandeur
     * reçoit les pièces, les m² et ce que l'atelier imprimera, sans montant
     * (ni pour le choix au-dessous).
     *
     * @param array<string, mixed> $calcul MesProduits::calculer
     *
     * @return array<string, mixed>
     */
    public static function calculPourLaPage(array $calcul, bool $montants): array
    {
        $page = array_intersect_key($calcul, array_flip(['quantite', 'saisie', 'inferieur', 'pieces', 'surface', 'longueur', 'montant', 'production', 'resume', 'detail', 'alternative']));
        if (!$montants) {
            unset($page['montant']);
            if (\is_array($page['alternative'] ?? null)) {
                unset($page['alternative']['montant']);
            }
        }

        return ['montants' => $montants] + $page;
    }

    /**
     * Commande la sélection : une ligne par produit, « à confirmer » par
     * l'atelier comme toute commande passée en ligne.
     *
     * @param list<array{produit: ProduitClient, quantite: float, saisie: string, inferieur: bool}> $selection
     *
     * @throws \DomainException
     */
    public function commander(Clients $client, array $selection, \DateTimeImmutable $maintenant): Commandes
    {
        if ([] === $selection) {
            throw new \DomainException('Choisissez au moins un produit.');
        }
        if (\count($selection) > self::SELECTION_MAX) {
            throw new \DomainException(\sprintf('%d produits au plus par commande.', self::SELECTION_MAX));
        }
        $this->nouvelleCommande->verifierEnAttente($client);

        $commande = (new Commandes())
            ->setClients($client)
            ->setDateCommande($maintenant)
            ->setCanal(Commandes::CANAL_EN_LIGNE)
            ->setStatut(false) // à confirmer par l'atelier
            ->setEtat(true);
        $vus = [];
        foreach ($selection as $choix) {
            $p = $choix['produit'];
            if ($p->getClient()?->getId() !== $client->getId() || isset($vus[$p->getId()])) {
                throw new \DomainException('Sélection invalide. Rechargez la page.');
            }
            $vus[$p->getId()] = true;
            $calcul = $this->calculer($p, $choix['quantite'], $choix['saisie'], $choix['inferieur']);
            $ligne = $this->conversion->creerLigne($commande, $p, $calcul['quantite'], $calcul['saisie'], $calcul['inferieur']);
            // Sans BAT validé sur la fiche, la ligne repasse par la PAO.
            Synchro::verifierLigneEnLigne($ligne);
        }

        $this->em->wrapInTransaction(function () use ($commande, $maintenant): void {
            $this->em->persist($commande);
            $this->em->flush();
            // Même numéro qu'au comptoir ; le site y ajoute W-.
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $maintenant->format('m-Y')));
            $this->em->flush();
        });

        return $commande;
    }

    /** « 400 × 150 cm », « Laize 1,37 m · longueur libre », support. */
    public static function format(ProduitClient $p): string
    {
        $morceaux = [];
        if ($p->estFormatFixe()) {
            $morceaux[] = \sprintf('%s × %s cm', self::n($p->getLargeur() * 100), self::n($p->getHauteur() * 100));
        } elseif ($p->estLongueurLibre()) {
            $morceaux[] = \sprintf('Laize %s m · longueur libre', self::n((float) $p->getLargeur()));
        }
        if (null !== $p->getSupport()?->getNom()) {
            $morceaux[] = $p->getSupport()->getNom();
        }
        if (ProduitClient::UNITE_LOT === $p->getUniteVente() && null !== $p->getTailleLot()) {
            $morceaux[] = 'par '.self::f($p->getTailleLot());
        }

        return implode(' · ', $morceaux);
    }

    private static function pieces(int $n): string
    {
        return \sprintf('%s pièce%s', self::f($n), $n > 1 ? 's' : '');
    }

    private static function f(int|float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private static function n(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
