<?php

namespace App\Service\DossiersClients;

/**
 * Modèle de sous-dossiers du dossier d'un client (maquettes DS01 et DS04) :
 * « 01 Conceptions », « 02 Fichiers reçus »… Chaque sous-dossier a une clé
 * stable (les droits et le classement s'y réfèrent), un nom et une couleur.
 * Le numéro vient de l'ordre.
 *
 * Un sous-dossier existant chez l'atelier est reconnu par son nom, sans
 * numéro, sans accents ni casse (« BAT », « 3-BAT envoyés », « Produits »
 * de « Mes produits » rangé avec « Prêts à imprimer »). Ce qui ne se
 * reconnaît pas tombe dans « Autres dossiers » (AUTRES).
 *
 * Classe pure, testée sans base.
 */
final class ModeleSousDossiers
{
    /** Fichiers à la racine du dossier du client, ou dans un sous-dossier non reconnu. */
    public const AUTRES = '_autres';

    public const DEFAUT = [
        ['cle' => 'conceptions', 'nom' => 'Conceptions', 'couleur' => '#DB2777'],
        ['cle' => 'recus', 'nom' => 'Fichiers reçus', 'couleur' => '#1D4ED8'],
        ['cle' => 'bat', 'nom' => 'BAT envoyés', 'couleur' => '#BE185D'],
        ['cle' => 'prets', 'nom' => 'Prêts à imprimer', 'couleur' => '#16A34A'],
        ['cle' => 'photos', 'nom' => 'Photos de pose', 'couleur' => '#F97316'],
        ['cle' => 'documents', 'nom' => 'Documents', 'couleur' => '#6B7280'],
    ];

    /** Autres noms reconnus pour chaque clé du modèle par défaut (sans accents, minuscules). */
    private const ALIAS = [
        'conceptions' => ['conception', 'creation', 'creations', 'maquette', 'maquettes', 'sources', 'source', 'travail', 'pao'],
        'recus' => ['fichiers recus', 'fichier recu', 'recus', 'recu', 'du client', 'client', 'fichiers client', 'fichiers du client', 'a traiter'],
        'bat' => ['bat', 'bats', 'bat envoyes', 'bat envoye', 'epreuves', 'epreuve'],
        'prets' => ['prets a imprimer', 'pret a imprimer', 'a imprimer', 'impression', 'final', 'finaux', 'produits', 'production', 'prets'],
        'photos' => ['photos de pose', 'photos', 'photo', 'pose', 'chantier', 'photos chantier'],
        'documents' => ['documents', 'document', 'docs', 'devis', 'factures', 'administratif', 'contrats'],
    ];

    /** @var list<array{cle: string, nom: string, couleur: string}> */
    private array $entrees;

    /**
     * @param array<mixed>|null $entrees réglage enregistré (null ou vide : le modèle par défaut)
     */
    public function __construct(?array $entrees = null)
    {
        $this->entrees = self::lire($entrees ?? []) ?: self::DEFAUT;
    }

    /**
     * Garde les entrées valides : clé [a-z0-9_] unique, nom de 1 à 60
     * caractères sans séparateur de chemin, couleur #RRGGBB. 12 au plus.
     *
     * @param array<mixed> $brut
     *
     * @return list<array{cle: string, nom: string, couleur: string}>
     */
    public static function lire(array $brut): array
    {
        $entrees = [];
        $cles = [];
        foreach ($brut as $e) {
            if (!\is_array($e)) {
                continue;
            }
            $nom = trim(preg_replace('/\s+/', ' ', (string) ($e['nom'] ?? '')) ?? '');
            $nom = trim((string) preg_replace('/^\d+\s*[-_.]?\s*/', '', $nom));
            if ('' === $nom || mb_strlen($nom) > 60 || preg_match('#[\\\\/:*?"<>|]#', $nom)) {
                continue;
            }
            $cle = strtolower(trim((string) ($e['cle'] ?? '')));
            if ('' === $cle) {
                $cle = substr(str_replace('-', '_', self::slug($nom)), 0, 30) ?: 'dossier';
            }
            if (!preg_match('/^[a-z0-9_]{1,30}$/', $cle) || self::AUTRES === $cle) {
                continue;
            }
            while (isset($cles[$cle])) {
                $cle .= '_2';
            }
            $couleur = strtoupper(trim((string) ($e['couleur'] ?? '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $couleur)) {
                $couleur = '#6B7280';
            }
            $cles[$cle] = true;
            $entrees[] = ['cle' => $cle, 'nom' => $nom, 'couleur' => $couleur];
            if (\count($entrees) >= 12) {
                break;
            }
        }

        return $entrees;
    }

    /** @return list<array{cle: string, nom: string, couleur: string, numero: string, dossier: string}> */
    public function entrees(): array
    {
        $liste = [];
        foreach ($this->entrees as $i => $e) {
            $numero = \sprintf('%02d', $i + 1);
            $liste[] = $e + ['numero' => $numero, 'dossier' => $numero.' '.$e['nom']];
        }

        return $liste;
    }

    /** @return list<string> */
    public function cles(): array
    {
        return array_column($this->entrees, 'cle');
    }

    /** @return array{cle: string, nom: string, couleur: string, numero: string, dossier: string}|null */
    public function entree(string $cle): ?array
    {
        foreach ($this->entrees() as $e) {
            if ($e['cle'] === $cle) {
                return $e;
            }
        }

        return null;
    }

    /** « 04 Prêts à imprimer » pour une clé, « Autres dossiers » pour AUTRES. */
    public function libelle(string $cle): string
    {
        return $this->entree($cle)['dossier'] ?? (self::AUTRES === $cle ? 'Autres dossiers' : $cle);
    }

    /**
     * La clé du modèle que désigne un nom de sous-dossier trouvé chez
     * l'atelier, ou null.
     */
    public function reconnaitre(string $nomDossier): ?string
    {
        $n = self::normaliser($nomDossier);
        if ('' === $n) {
            return null;
        }
        // 1. Le nom du modèle, puis les autres noms connus de la clé.
        foreach ($this->entrees as $e) {
            if ($n === self::normaliser($e['nom'])) {
                return $e['cle'];
            }
        }
        foreach ($this->entrees as $e) {
            if (\in_array($n, self::ALIAS[$e['cle']] ?? [], true)) {
                return $e['cle'];
            }
        }
        // 2. Le premier mot (« BAT client » → bat, « Photos 2026 » → photos).
        $premier = explode(' ', $n)[0];
        foreach ($this->entrees as $e) {
            if ($premier === explode(' ', self::normaliser($e['nom']))[0] || \in_array($premier, self::ALIAS[$e['cle']] ?? [], true)) {
                return $e['cle'];
            }
        }

        return null;
    }

    /** « 03 - BAT Envoyés » → « bat envoyes » : sans numéro de tête, accents, casse ni ponctuation. */
    public static function normaliser(string $nom): string
    {
        $nom = preg_replace('/^\s*\d+\s*[-_.)]?\s*/', '', $nom) ?? '';

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Rapprochement::sansAccents(mb_strtolower($nom))));
    }

    public static function slug(string $texte): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', Rapprochement::sansAccents(mb_strtolower($texte))), '-');
    }
}
