<?php

namespace App\Service\Import;

use App\Entity\ImportDonnees;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un type de données importables (clients, produits, articles de stock,
 * soldes d'ouverture). Voir docs/import-donnees.md.
 */
#[AutoconfigureTag('app.type_import')]
interface TypeImport
{
    /** Clé courte, dans l'adresse et le journal (« clients »). */
    public function cle(): string;

    public function libelle(): string;

    /** Une phrase pour l'écran de choix. */
    public function description(): string;

    /**
     * Droits tous nécessaires pour importer ce type (la direction les a
     * tous ; les soldes demandent aussi les droits Finances).
     *
     * @return list<string>
     */
    public function droits(): array;

    /** Les soldes s'importent en entier ou pas du tout : aucune ligne en erreur. */
    public function toutOuRien(): bool;

    /** Les soldes ont une date d'ouverture ; les clients, l'option « compléter ». */
    public function avecDateOuverture(): bool;

    public function avecCompletion(): bool;

    /**
     * Colonnes du modèle : clé, libellé d'en-tête, autres noms acceptés,
     * obligatoire, deux exemples, aide pour la feuille Notes, droit
     * nécessaire pour la voir (coûts : ROLE_COUT_MARGE).
     *
     * @return list<array{cle: string, libelle: string, alias: list<string>, obligatoire: bool, exemples: array{0: string, 1: string}, aide: string, droit?: string}>
     */
    public function colonnes(): array;

    /**
     * Phrases générales de la feuille Notes du modèle.
     *
     * @return list<string>
     */
    public function notes(): array;

    /**
     * Décide de chaque ligne sans rien enregistrer.
     *
     * @param list<LigneImport> $lignes
     */
    public function analyser(array $lignes, ContexteImport $contexte): void;

    /**
     * Enregistre les lignes à créer ou à mettre à jour (dans la transaction
     * de l'appelant) et note dans chaque ligne ce qui a été fait (« fait »).
     *
     * @param list<LigneImport> $lignes
     */
    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void;

    /**
     * Raisons qui empêchent d'annuler l'import (une fiche a servi depuis).
     * Vide : l'annulation est possible.
     *
     * @return list<string>
     */
    public function blocagesAnnulation(ImportDonnees $import): array;

    /** Défait ce que l'import a fait (dans la transaction de l'appelant). */
    public function annuler(ImportDonnees $import, ContexteImport $contexte): void;
}
