<?php

namespace App\Service\Devis;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\Produits;
use App\Repository\MachinesRepository;
use App\Service\CalculateurCoutRevient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rentabilité interne d'un devis (maquettes GC04 et DT03) : coût estimé,
 * marge et taux de marge. Jamais montrée au client ; à l'écran seulement
 * avec le droit « Marges et coûts de revient » (ROLE_COUT_MARGE), vérifié
 * par l'appelant.
 *
 * Coût d'une ligne : celui noté sur la ligne s'il y en a un, sinon le
 * calcul du coût de revient (CalculateurCoutRevient) sur une ligne de
 * commande faite en mémoire, avec la machine qui gâche le moins de
 * support (elle n'est choisie qu'en prépresse).
 */
final class RentabiliteDevis
{
    /** Taux de marge sous lequel l'écran alerte. */
    public const SEUIL_ALERTE = 25.0;

    /** @var list<Machines>|null */
    private ?array $machines = null;

    public function __construct(
        private readonly CalculateurCoutRevient $calculateur,
        private readonly MachinesRepository $machinesRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{cout: int, ht: int, marge: int, taux: ?float, alerte: bool, incomplet: bool, manquants: list<string>}
     */
    public function pourDevis(Devis $devis): array
    {
        $lignes = [];
        foreach ($devis->getDevisDetails() as $l) {
            $lignes[] = ['ht' => $l->getTotalHt(), 'cout' => $this->coutLigne($l)];
        }
        // La remise globale baisse le prix de vente, pas le coût.
        $ht = max(0, array_sum(array_column($lignes, 'ht')) - $devis->getRemise());

        return self::bilan($ht, $lignes);
    }

    /**
     * Estimation pendant la saisie, depuis ce que la page envoie (rien
     * n'est enregistré) : produit, dimensions en cm, quantité, faces,
     * finitions, prix HT de chaque ligne.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array{cout: int, ht: int, marge: int, taux: ?float, alerte: bool, incomplet: bool, manquants: list<string>}
     */
    public function estimer(array $lignes, int $remise): array
    {
        $resultats = [];
        foreach (\array_slice($lignes, 0, 60) as $donnees) {
            $produit = (int) ($donnees['produit'] ?? 0) > 0 ? $this->em->find(Produits::class, (int) $donnees['produit']) : null;
            $ligne = (new CommandesDetails())
                ->setProduit($produit)
                ->setLargeur(max(0.0, (float) ($donnees['largeur'] ?? 0)) / 100)
                ->setLongueur(max(0.0, (float) ($donnees['longueur'] ?? 0)) / 100)
                ->setQuantite(max(1, (int) ($donnees['quantite'] ?? 1)))
                ->setNombreFaces(max(1, (int) ($donnees['nombreFaces'] ?? 1)));
            foreach (array_unique(array_map('intval', (array) ($donnees['finitions'] ?? []))) as $id) {
                $finition = $id > 0 ? $this->em->find(Finition::class, $id) : null;
                if ($finition instanceof Finition) {
                    $ligne->addFinition((new CommandeDetailFinition())->setFinition($finition)->setQuantite(1));
                }
            }
            $cout = $this->coutCalcule($ligne);
            $resultats[] = ['ht' => max(0, (int) ($donnees['prixHt'] ?? 0)), 'cout' => $cout];
        }

        return self::bilan(max(0, array_sum(array_column($resultats, 'ht')) - max(0, $remise)), $resultats);
    }

    /**
     * @param list<array{ht: int, cout: array{total: int, manquants: list<string>}}> $lignes
     *
     * @return array{cout: int, ht: int, marge: int, taux: ?float, alerte: bool, incomplet: bool, manquants: list<string>}
     */
    public static function bilan(int $ht, array $lignes): array
    {
        $cout = array_sum(array_map(static fn (array $l): int => $l['cout']['total'], $lignes));
        $manquants = array_values(array_unique(array_merge([], ...array_map(static fn (array $l): array => $l['cout']['manquants'], $lignes))));
        // Aucun coût calculable (lignes libres seulement) : pas de taux, plutôt qu'un faux 100 %.
        $taux = $cout > 0 ? CalculateurCoutRevient::tauxMarge($ht, $cout) : null;

        return [
            'cout' => $cout,
            'ht' => $ht,
            'marge' => $ht - $cout,
            'taux' => $taux,
            'alerte' => null !== $taux && $taux < self::SEUIL_ALERTE,
            'incomplet' => [] !== $manquants,
            'manquants' => \array_slice($manquants, 0, 5),
        ];
    }

    /** @return array{total: int, manquants: list<string>} */
    private function coutLigne(DevisDetails $l): array
    {
        if ($l->getCoutRevient() > 0) {
            return ['total' => $l->getCoutRevient(), 'manquants' => []];
        }
        $ligne = (new CommandesDetails())
            ->setProduit($l->getProduit())
            // Les lignes du devis gardent des centimètres (comme l'écran) ; le calcul veut des mètres.
            ->setLargeur((float) ($l->getLargeur() ?? 0) / 100)
            ->setLongueur((float) ($l->getLongueur() ?? 0) / 100)
            ->setSurface($l->getSurface())
            ->setQuantite(max(1, (int) $l->getQuantite()))
            ->setNombreFaces(max(1, $l->getNombreFaces()));
        foreach ($l->getFinitions() as $f) {
            if (null !== $f->getFinition()) {
                $ligne->addFinition((new CommandeDetailFinition())->setFinition($f->getFinition())->setQuantite(max(1, (int) $f->getQuantite())));
            }
        }

        return $this->coutCalcule($ligne);
    }

    /** @return array{total: int, manquants: list<string>} */
    private function coutCalcule(CommandesDetails $ligne): array
    {
        if (null === $ligne->getProduit()) {
            // Ligne libre (pose, déplacement) : pas de coût calculable, pas compté comme manquant.
            return ['total' => 0, 'manquants' => []];
        }
        $ligne->setMachine($this->machine((float) $ligne->getLargeur(), (float) $ligne->getLongueur(), max(1, $ligne->getQuantite())));
        $calcul = $this->calculateur->pourLigne($ligne);

        return ['total' => $calcul['total'], 'manquants' => $calcul['manquants']];
    }

    private function machine(float $largeur, float $hauteur, int $quantite): ?Machines
    {
        if ($largeur <= 0 || $hauteur <= 0) {
            return null;
        }
        $this->machines ??= array_values(array_filter($this->machinesRepository->findAll(), static fn (Machines $m): bool => null !== $m->getLaizeMetres()));
        $choix = null;
        $moins = null;
        foreach ($this->machines as $m) {
            $imposition = CalculateurCoutRevient::imposition($largeur, $hauteur, $quantite, $m->getLaizeMetres());
            if ($imposition['surfaceConsommee'] > 0 && (null === $moins || $imposition['surfaceConsommee'] < $moins)) {
                [$choix, $moins] = [$m, $imposition['surfaceConsommee']];
            }
        }

        return $choix;
    }
}
