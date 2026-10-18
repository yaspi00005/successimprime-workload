<?php

namespace App\Twig;

use App\Entity\AUnNomCommercial;
use App\Entity\CommandesDetails;
use App\Entity\ProduitConfiguration;
use App\Service\Catalogue\NomCommercial;
use App\Service\EspaceClient\EspaceClient;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Nom commercial dans les gabarits (docs/noms-commerciaux.md).
 *
 * - « nom_client » : <span>Autocollant</span> <small>Vinyle adhésif</small> (site, espace client, documents) ;
 * - « nom_interne » : Vinyle adhésif <small>Autocollant</small> (écrans de l'ERP) ;
 * - « ligne_client » / « ligne_interne » : la même chose pour une ligne de devis,
 *   de commande, de facture ou de bon de livraison (désignation enregistrée + nom
 *   commercial de son produit ou de son article) ;
 * - « texte_client », « texte_interne », « texte_ligne » : en texte simple.
 *
 * Sans nom commercial : la désignation seule. Tout est échappé ici.
 */
final class NomCommercialExtension
{
    #[AsTwigFilter('nom_client', isSafe: ['html'])]
    public function nomClient(?AUnNomCommercial $element, ?string $designation = null): string
    {
        $n = NomCommercial::client($element?->getNomCommercial(), $designation ?? $element?->getNomTechnique());

        return self::client($n['nom'], $n['technique']);
    }

    #[AsTwigFilter('nom_interne', isSafe: ['html'])]
    public function nomInterne(?AUnNomCommercial $element, ?string $designation = null): string
    {
        $n = NomCommercial::interne($element?->getNomCommercial(), $designation ?? $element?->getNomTechnique());

        return self::interne($n['nom'], $n['commercial']);
    }

    #[AsTwigFilter('texte_client')]
    public function texteClient(?AUnNomCommercial $element, ?string $designation = null): string
    {
        return NomCommercial::texteClient($element?->getNomCommercial(), $designation ?? $element?->getNomTechnique());
    }

    #[AsTwigFilter('texte_interne')]
    public function texteInterne(?AUnNomCommercial $element, ?string $designation = null): string
    {
        return NomCommercial::texteInterne($element?->getNomCommercial(), $designation ?? $element?->getNomTechnique());
    }

    /** Ligne pour un client ; $defaut quand elle n'a ni désignation ni produit. */
    #[AsTwigFilter('ligne_client', isSafe: ['html'])]
    public function ligneClient(mixed $ligne, string $defaut = 'Prestation'): string
    {
        $n = self::ligne($ligne);

        return '' === $n['nom'] ? self::e($defaut) : self::client($n['nom'], $n['technique']);
    }

    #[AsTwigFilter('ligne_interne', isSafe: ['html'])]
    public function ligneInterne(mixed $ligne, string $defaut = 'Prestation'): string
    {
        $n = self::ligne($ligne);
        if ('' === $n['nom']) {
            return self::e($defaut);
        }

        // La désignation enregistrée d'abord, le nom commercial ensuite.
        return null === $n['technique'] ? self::e($n['nom']) : self::interne($n['technique'], $n['nom']);
    }

    /**
     * Titre d'une ligne de commande dans l'espace client, en HTML :
     * « <span>Autocollant</span> <small>Vinyle adhésif</small> 100 × 50 × 2 »
     * (le texte simple reste le filtre espace_titre_ligne).
     */
    #[AsTwigFilter('titre_ligne_client', isSafe: ['html'])]
    public function titreLigneClient(CommandesDetails $ligne): string
    {
        $n = self::ligne($ligne);
        if ('' === $n['nom']) {
            $n = ['nom' => 'Travail', 'technique' => null];
        }
        $texte = null === $n['technique'] ? $n['nom'] : $n['nom'].' · '.$n['technique'];

        return self::client($n['nom'], $n['technique']).self::e(EspaceClient::mesuresLigne($ligne, $texte));
    }

    #[AsTwigFilter('texte_ligne')]
    public function texteLigne(mixed $ligne, string $defaut = 'Prestation'): string
    {
        $n = self::ligne($ligne);
        if ('' === $n['nom']) {
            return $defaut;
        }

        return null === $n['technique'] ? $n['nom'] : $n['nom'].' · '.$n['technique'];
    }

    /** Ligne pour l'ERP, en texte : « Vinyle adhésif (100 x 50 cm) (Autocollant) ». */
    #[AsTwigFilter('texte_ligne_interne')]
    public function texteLigneInterne(mixed $ligne, string $defaut = 'Prestation'): string
    {
        $n = self::ligne($ligne);
        if ('' === $n['nom']) {
            return $defaut;
        }

        return null === $n['technique'] ? $n['nom'] : $n['technique'].' ('.$n['nom'].')';
    }

    #[AsTwigFilter('configuration_client', isSafe: ['html'])]
    public function configurationClient(?ProduitConfiguration $config): string
    {
        $n = NomCommercial::configuration($config);

        return self::client($n['nom'], $n['technique']);
    }

    /** Clé de recherche sans accents des noms donnés, pour un attribut data-recherche. */
    #[AsTwigFunction('cle_recherche')]
    public function cleRecherche(?string ...$textes): string
    {
        return NomCommercial::cle(...$textes);
    }

    /**
     * Une ligne enregistrée (devis, commande, bon de livraison), ou un
     * tableau avec sa « designation » (aperçu d'un document sans base).
     *
     * @return array{nom: string, technique: ?string}
     */
    private static function ligne(mixed $ligne): array
    {
        if (\is_array($ligne)) {
            return ['nom' => trim((string) ($ligne['designation'] ?? '')), 'technique' => null];
        }
        if (!\is_object($ligne)) {
            return ['nom' => '', 'technique' => null];
        }

        return NomCommercial::ligne(method_exists($ligne, 'getDesignation') ? $ligne->getDesignation() : null, NomCommercial::elementDeLigne($ligne));
    }

    private static function client(string $nom, ?string $technique): string
    {
        if (null === $technique) {
            return self::e($nom);
        }

        return '<span class="nc-nom">'.self::e($nom).'</span> <small class="nc-technique">'.self::e($technique).'</small>';
    }

    private static function interne(string $nom, ?string $commercial): string
    {
        if (null === $commercial) {
            return self::e($nom);
        }

        return self::e($nom).' <small class="nc-commercial text-muted">'.self::e($commercial).'</small>';
    }

    private static function e(string $texte): string
    {
        return htmlspecialchars($texte, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
