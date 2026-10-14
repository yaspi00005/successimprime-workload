<?php

namespace App\Service\Societes;

use App\Entity\AppartientASociete;
use App\Entity\ClotureCaisse;
use App\Entity\DecaissementRecurrent;
use App\Entity\Depense;
use App\Entity\Devis;
use App\Entity\DotationAmortissement;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\RapprochementBancaire;
use App\Entity\Societe;
use App\Entity\StockEntrees;
use App\Entity\StockSorties;
use App\Repository\EntrepriseRepository;
use App\Repository\SocieteRepository;

/**
 * La société d'un nouveau document (docs/societes.md, étape 1).
 *
 * Règle, la même que celle de la migration pour les documents existants :
 * - un document qui a un émetteur (devis, facture) prend la société de
 *   l'entreprise émettrice ;
 * - un document lié à un autre prend la société de celui-ci (paiement ←
 *   facture, sinon commande ; mouvement ← son compte ; sortie de stock ←
 *   son article ; dotation ← son bien…) ;
 * - tout le reste prend la société par défaut.
 *
 * Étape 2 : la société de travail choisie dans l'en-tête remplacera la
 * société par défaut.
 */
final class SocieteCourante
{
    /**
     * Les données dont un document reprend la société, dans l'ordre : la
     * première présente l'emporte. Une donnée absente d'ici (et sans
     * émetteur) prend la société par défaut.
     *
     * Un mouvement suit son compte plutôt que son paiement : le solde d'un
     * compte reste dans une seule société. Un paiement d'une facture DREPA
     * encaissé dans la caisse de Madial Group est un échange entre
     * sociétés, à traiter à l'étape 2.
     */
    public const LIENS = [
        Paiements::class => ['getFacture', 'getCommande'],
        MouvementTresorerie::class => ['getCompteSource', 'getCompteDestination', 'getPaiement'],
        DecaissementRecurrent::class => ['getCompteSource'],
        RapprochementBancaire::class => ['getCompteTresorerie'],
        ClotureCaisse::class => ['getCompte'],
        Depense::class => ['getCompte', 'getArticle'],
        StockEntrees::class => ['getArticle'],
        StockSorties::class => ['getArticle'],
        DotationAmortissement::class => ['getImmobilisation'],
    ];

    public function __construct(
        private readonly SocieteRepository $societes,
        private readonly EntrepriseRepository $entreprises,
    ) {
    }

    public function parDefaut(): Societe
    {
        return $this->societes->parDefaut()
            ?? throw new \LogicException('Aucune société en base : lancer les migrations (doctrine:migrations:migrate).');
    }

    /**
     * Société de l'entreprise émettrice de ce code ; un code inconnu ne
     * bloque jamais un document : la société par défaut le prend.
     */
    public function pourEmetteur(?string $code): Societe
    {
        return $this->entreprises->parCode($code)?->getSociete() ?? $this->parDefaut();
    }

    public function pour(AppartientASociete $document): Societe
    {
        if (null !== $document->getSociete()) {
            return $document->getSociete();
        }
        if ($document instanceof Devis || $document instanceof Factures) {
            return $this->pourEmetteur($document->getEmetteur());
        }
        foreach (self::liens($document) as $lie) {
            if (null !== $lie) {
                return $this->pour($lie);
            }
        }

        return $this->parDefaut();
    }

    /**
     * @return list<?AppartientASociete>
     */
    public static function liens(AppartientASociete $document): array
    {
        foreach (self::LIENS as $classe => $getters) {
            if ($document instanceof $classe) {
                return array_map(static fn (string $getter): ?AppartientASociete => $document->{$getter}(), $getters);
            }
        }

        return [];
    }
}
