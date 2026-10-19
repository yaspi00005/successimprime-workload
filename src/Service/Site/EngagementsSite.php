<?php

namespace App\Service\Site;

use App\Entity\ReglageSite;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Nos engagements (textes du gérant du 19/10/2026, maquettes EC01 et EC02) :
 * les 6 promesses avec « Si on ne tient pas → », ce que l'imprimerie ne
 * promet pas et le délai de réponse aux réclamations (tuile « Site :
 * engagements et rappels ») ; les chiffres calculés par l'ERP, montrés
 * seulement après leur publication par la direction (EC09, réglage du site
 * « engagements_chiffres ») ; l'accueil (délai du visuel, packs).
 */
class EngagementsSite
{
    public const CLE_CHIFFRES = 'engagements_chiffres';
    public const NOMBRE = 6;
    public const PACKS = 4;
    /** Icône de chaque promesse, dans l'ordre (dessins de templates/site/_icone_engagement.svg.twig). */
    public const ICONES = ['bat', 'prix', 'date', 'fichier', 'refait', 'cadenas'];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Promesses remplies (un titre suffit), dans l'ordre.
     *
     * @return list<array{titre: string, promesse: string, sinon: ?string, icone: string}>
     */
    public function promesses(): array
    {
        $promesses = [];
        for ($i = 1; $i <= self::NOMBRE; ++$i) {
            $titre = $this->texte('site_engagements', 'e'.$i.'_titre');
            if (null === $titre) {
                continue;
            }
            $promesses[] = [
                'titre' => $titre,
                'promesse' => (string) $this->texte('site_engagements', 'e'.$i.'_promesse'),
                'sinon' => $this->texte('site_engagements', 'e'.$i.'_sinon'),
                'icone' => self::ICONES[$i - 1],
            ];
        }

        return $promesses;
    }

    /** @return list<string> « Ce que nous ne promettons pas », une phrase par point. */
    public function nePromettonsPas(): array
    {
        return self::points((string) $this->parametres->valeur('site_engagements', 'ne_promet_pas'));
    }

    /** @return list<string> */
    public static function points(string $saisi): array
    {
        return array_values(array_filter(array_map(static fn (string $p): string => trim((string) preg_replace('/\s+/u', ' ', $p)), explode('|', $saisi)), static fn (string $p): bool => '' !== $p));
    }

    public function delaiReclamation(): int
    {
        return max(1, (int) ($this->parametres->valeur('site_engagements', 'delai_reclamation') ?? 24));
    }

    /**
     * Chiffres publiés par la direction (EC09) : rien tant qu'ils ne l'ont
     * pas été. Chaque chiffre absent ou nul n'est pas montré.
     *
     * @return array{chiffres: list<array{cle: string, valeur: string, libelle: string}>, periode: ?string, publieLe: ?\DateTimeImmutable}|null
     */
    public function chiffresPublies(): ?array
    {
        $valeurs = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_CHIFFRES])?->getValeurs() ?? [];

        return self::lireChiffres($valeurs);
    }

    /** Libellés des chiffres publiables, dans l'ordre de la page. */
    public const LIBELLES = [
        'a_la_date' => 'des commandes prêtes à la date annoncée',
        'bat' => 'des impressions avec un BAT validé',
        'reclamations_resolues' => 'des réclamations résolues',
        'reponse' => 'des messages répondus dans le délai',
    ];

    /**
     * @param array<string, mixed> $valeurs
     *
     * @return array{chiffres: list<array{cle: string, valeur: string, libelle: string}>, periode: ?string, publieLe: ?\DateTimeImmutable}|null
     */
    public static function lireChiffres(array $valeurs): ?array
    {
        $chiffres = [];
        foreach (self::LIBELLES as $cle => $libelle) {
            $v = $valeurs[$cle] ?? null;
            if (is_numeric($v) && (float) $v > 0 && (float) $v <= 100) {
                $chiffres[] = ['cle' => $cle, 'valeur' => (string) (int) round((float) $v), 'libelle' => $libelle];
            }
        }
        if ([] === $chiffres) {
            return null;
        }
        $le = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) ($valeurs['publie_le'] ?? ''));

        return [
            'chiffres' => $chiffres,
            'periode' => isset($valeurs['periode']) && '' !== trim((string) $valeurs['periode']) ? mb_substr(trim((string) $valeurs['periode']), 0, 60) : null,
            'publieLe' => false === $le ? null : $le,
        ];
    }

    /** Délai du visuel annoncé en haut de l'accueil, en heures ; null : la phrase n'est pas affichée. */
    public function delaiVisuel(): ?int
    {
        $h = (int) ($this->parametres->valeur('site_accueil', 'delai_visuel') ?? 0);

        return $h > 0 ? $h : null;
    }

    /**
     * Packs « Pour votre occasion » (éteints par défaut, tuile « Site :
     * accueil et packs ») ; un pack sans nom est caché, un prix à 0 devient
     * « sur devis ».
     *
     * @return list<array{nom: string, contenu: string, prix: ?int}>
     */
    public function packs(): array
    {
        if (!(bool) $this->parametres->valeur('site_accueil', 'packs_visibles')) {
            return [];
        }
        $packs = [];
        for ($i = 1; $i <= self::PACKS; ++$i) {
            $nom = $this->texte('site_accueil', 'pack'.$i.'_nom');
            if (null === $nom) {
                continue;
            }
            $prix = (int) ($this->parametres->valeur('site_accueil', 'pack'.$i.'_prix') ?? 0);
            $packs[] = ['nom' => $nom, 'contenu' => (string) $this->texte('site_accueil', 'pack'.$i.'_contenu'), 'prix' => $prix > 0 ? $prix : null];
        }

        return $packs;
    }

    private function texte(string $tuile, string $cle): ?string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', (string) $this->parametres->valeur($tuile, $cle)));

        return '' === $texte ? null : $texte;
    }
}
