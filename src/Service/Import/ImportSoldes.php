<?php

namespace App\Service\Import;

use App\Entity\ImportDonnees;
use App\Entity\SoldeOuverture;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que les trois imports de soldes d'ouverture ont en commun
 * (docs/import-donnees.md) : un jour d'ouverture choisi à l'envoi, tout
 * ou rien (une seule ligne en erreur bloque l'import), une trace
 * SoldeOuverture par ligne enregistrée, qui alimente le journal des
 * à-nouveaux de la comptabilité.
 */
abstract class ImportSoldes implements TypeImport
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly References $references,
    ) {
    }

    public function toutOuRien(): bool
    {
        return true;
    }

    public function avecDateOuverture(): bool
    {
        return true;
    }

    public function avecCompletion(): bool
    {
        return false;
    }

    /**
     * Soldes encore actifs (pas annulés) enregistrés par l'import.
     *
     * @return list<SoldeOuverture>
     */
    protected function soldes(ImportDonnees $import): array
    {
        return $this->em->getRepository(SoldeOuverture::class)->findBy(['import' => $import, 'annuleLe' => null], ['id' => 'ASC']);
    }

    /** Montant lu, avec le signe permis ou non ; le message dit ce qui ne va pas. */
    protected static function lireMontant(LigneImport $ligne, string $cle, bool $negatifPermis): ?int
    {
        if ('' === $ligne->valeur($cle)) {
            $ligne->erreur('Le montant est obligatoire.');

            return null;
        }
        try {
            return Valeurs::montant($ligne->valeur($cle), $negatifPermis);
        } catch (\InvalidArgumentException $e) {
            $ligne->erreur($e->getMessage());

            return null;
        }
    }

    protected static function lireDate(LigneImport $ligne, string $cle): \DateTimeImmutable|false|null
    {
        try {
            return Valeurs::date($ligne->valeur($cle));
        } catch (\InvalidArgumentException $e) {
            $ligne->erreur($e->getMessage());

            return false;
        }
    }

    protected static function noterAutres(ImportDonnees $import, LigneImport $ligne): void
    {
        $import->noter(['ligne' => $ligne->numero, 'statut' => $ligne->statut, 'libelle' => $ligne->libelle, 'raison' => $ligne->raison]);
    }

    protected static function noterSolde(ImportDonnees $import, LigneImport $ligne, SoldeOuverture $solde): void
    {
        $import->noter(['ligne' => $ligne->numero, 'statut' => $ligne->statut, 'id' => $solde->getId(), 'libelle' => $ligne->libelle, 'type' => $solde->getType(), 'montant' => $solde->getMontant(), 'detail' => $ligne->detail]);
    }
}
