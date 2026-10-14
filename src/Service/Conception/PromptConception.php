<?php

namespace App\Service\Conception;

use App\Entity\DemandeConception;

/**
 * Consigne envoyée au générateur d'images, construite par le serveur à
 * partir de la demande (maquette DC04) : format et sens du support, textes
 * du client cités tels quels, couleurs, style, consigne ajoutée.
 *
 * Pas de logos de marques ni de personnes connues : une consigne qui en
 * demande est refusée, un nom glissé dans un texte est retiré, et la
 * consigne rappelle toujours l'interdiction au générateur.
 *
 * Tout est statique et pur pour être testé sans base ni réseau.
 */
final class PromptConception
{
    /** Consigne ajoutée par le client (« Modifier la consigne »). */
    public const CONSIGNE_MAX = 300;

    /** Consigne complète au plus (les modèles d'images en acceptent peu). */
    public const PROMPT_MAX = 1800;

    public const PORTRAIT = 'portrait';
    public const PAYSAGE = 'paysage';
    public const CARRE = 'carre';

    /** Pastilles proposées au client (maquette DC02) : couleur => nom. */
    public const COULEURS = [
        '#13285C' => 'bleu nuit',
        '#F2C230' => 'or',
        '#FFFFFF' => 'blanc',
        '#E2468F' => 'rose',
        '#E0102B' => 'rouge',
        '#16A34A' => 'vert',
        '#FF7900' => 'orange',
        '#111111' => 'noir',
    ];

    private const COULEURS_ANGLAIS = [
        '#13285C' => 'midnight blue', '#F2C230' => 'gold', '#FFFFFF' => 'white', '#E2468F' => 'pink',
        '#E0102B' => 'red', '#16A34A' => 'green', '#FF7900' => 'orange', '#111111' => 'black',
    ];

    private const STYLES_ANGLAIS = [
        'elegant' => 'elegant, refined',
        'moderne' => 'modern, clean',
        'festif' => 'festive, joyful',
        'institutionnel' => 'institutional, sober and trustworthy',
    ];

    /** Support => ce qu'il faut dessiner. */
    private const SUPPORTS_ANGLAIS = [
        'kakemono' => 'tall roll-up banner',
        'bache' => 'large outdoor banner',
        'flyer' => 'flyer',
        'carte_visite' => 'business card (front side)',
        'affiche' => 'poster',
        'textile' => 'T-shirt print artwork (the design only, no garment)',
        'enseigne' => 'shop front sign',
        'autocollant' => 'sticker or window decal',
        'logo' => 'simple flat logo emblem',
    ];

    /**
     * Marques et personnes connues (sans accents, en minuscules). Un nom de
     * famille seul (Keïta, Traoré…) n'y est pas : il est porté par beaucoup
     * de clients ; ni un mot qui est aussi un prénom courant (Fanta) ou un
     * mot de tous les jours (puma).
     */
    private const INTERDITS = [
        // Marques
        'coca-cola', 'coca cola', 'pepsi', 'sprite', 'nike', 'adidas', 'reebok', 'apple', 'iphone', 'samsung', 'huawei',
        'tecno', 'infinix', 'google', 'facebook', 'instagram', 'whatsapp', 'tiktok', 'youtube', 'twitter', 'snapchat', 'microsoft',
        'amazon', 'netflix', 'disney', 'marvel', 'pixar', 'mickey', 'spider-man', 'spiderman', 'batman', 'superman', 'pokemon',
        'hello kitty', 'mercedes', 'bmw', 'toyota', 'ferrari', 'lamborghini', 'porsche', 'gucci', 'louis vuitton', 'chanel', 'dior',
        'prada', 'versace', 'rolex', 'lacoste', 'mcdonald', 'kfc', 'burger king', 'starbucks', 'heineken', 'guinness', 'red bull',
        'nescafe', 'nestle', 'maggi', 'real madrid', 'barcelone', 'fc barcelona', 'psg', 'paris saint-germain', 'manchester united',
        'chelsea', 'arsenal', 'juventus', 'fifa', 'uefa', 'jeux olympiques',
        // Personnes connues
        'messi', 'cristiano', 'ronaldo', 'neymar', 'mbappe', 'beyonce', 'rihanna', 'drake', 'obama', 'trump', 'macron', 'biden',
        'poutine', 'putin', 'elon musk', 'bill gates', 'mandela', 'salif keita', 'aya nakamura', 'sidiki diabate', 'oumou sangare',
        'toumani diabate', 'fally ipupa', 'davido', 'burna boy', 'wizkid', 'drogba', 'sadio mane', 'michael jackson', 'bob marley',
        'assimi goita',
    ];

    /** Demandes générales refusées dans une consigne. */
    private const INTERDITS_TOURNURES = ['celebrite', 'personne celebre', 'personnalite connue', 'logo officiel', 'marque deposee', 'logo de la marque', 'visage de la star'];

    public static function orientation(?float $largeurCm, ?float $hauteurCm): string
    {
        if (null === $largeurCm || null === $hauteurCm || $largeurCm <= 0 || $hauteurCm <= 0) {
            return self::CARRE;
        }
        $rapport = $hauteurCm / $largeurCm;

        return match (true) {
            $rapport > 1.15 => self::PORTRAIT,
            $rapport < 0.87 => self::PAYSAGE,
            default => self::CARRE,
        };
    }

    /** Nom d'une couleur choisie : « bleu nuit », sinon son code. */
    public static function nomCouleur(string $hex): string
    {
        return self::COULEURS[strtoupper($hex)] ?? strtoupper($hex);
    }

    /**
     * Le terme interdit trouvé dans le texte, ou null.
     */
    public static function interdit(?string $texte): ?string
    {
        $normal = self::normaliser((string) $texte);
        if ('' === $normal) {
            return null;
        }
        foreach ([...self::INTERDITS, ...self::INTERDITS_TOURNURES] as $terme) {
            if (preg_match('/(^|[^a-z0-9])'.preg_quote($terme, '/').'([^a-z0-9]|$)/', $normal)) {
                return $terme;
            }
        }

        return null;
    }

    /**
     * Consigne ajoutée par le client : coupée à CONSIGNE_MAX, refusée si
     * elle demande une marque ou une personne connue.
     *
     * @throws \DomainException message à montrer au client
     */
    public static function verifierConsigne(?string $consigne): ?string
    {
        $consigne = trim(preg_replace('/\s+/u', ' ', (string) $consigne) ?? '');
        if ('' === $consigne) {
            return null;
        }
        if (mb_strlen($consigne) > self::CONSIGNE_MAX) {
            throw new \DomainException(\sprintf('La consigne fait plus de %d caractères : raccourcissez-la.', self::CONSIGNE_MAX));
        }
        if (null !== ($terme = self::interdit($consigne))) {
            throw new \DomainException(\sprintf('Pas de logos de marques ni de personnes connues : retirez « %s » de la consigne.', $terme));
        }

        return $consigne;
    }

    /** Texte à afficher, sans marque ni personne connue (le graphiste remet les vrais textes). */
    public static function neutraliser(?string $texte): string
    {
        $texte = trim(preg_replace('/\s+/u', ' ', str_replace(['"', '“', '”'], "'", (string) $texte)) ?? '');
        for ($i = 0; $i < 5 && null !== ($terme = self::interdit($texte)); ++$i) {
            $texte = self::retirer($texte, $terme);
        }

        return trim($texte);
    }

    /**
     * Consigne complète pour le générateur.
     *
     * @throws \DomainException si les consignes du client demandent une marque ou une personne connue
     */
    public static function construire(DemandeConception $demande, ?string $consigne, ?string $codeSupport, int $nombreElements = 0): string
    {
        $consigne = self::verifierConsigne($consigne);
        $consignes = trim(preg_replace('/\s+/u', ' ', (string) $demande->getConsignes()) ?? '');
        if (null !== ($terme = self::interdit($consignes))) {
            throw new \DomainException(\sprintf('Pas de logos de marques ni de personnes connues : retirez « %s » des consignes.', $terme));
        }

        $orientation = self::orientation($demande->getLargeurCm(), $demande->getHauteurCm());
        $support = self::SUPPORTS_ANGLAIS[(string) $codeSupport] ?? self::neutraliser($demande->getSupportNom());
        $styles = array_values(array_filter(array_map(static fn (string $s): ?string => self::STYLES_ANGLAIS[$s] ?? null, $demande->getStyles())));
        $format = self::neutraliser($demande->getSupportFormat());

        $lignes = [\sprintf(
            'Design a %sprint-ready %s artwork%s, %s orientation, as a flat design that fills the whole image.',
            [] !== $styles ? implode(', ', $styles).' ' : '',
            $support,
            '' !== $format ? ' ('.$format.')' : '',
            ['portrait' => 'portrait', 'paysage' => 'landscape', 'carre' => 'square'][$orientation],
        )];

        $textes = [];
        foreach (['main title' => $demande->getTitre(), 'subtitle' => $demande->getSousTitre(), 'date and time' => $demande->getDateHeure(), 'contact' => $demande->getCoordonnees()] as $role => $texte) {
            $propre = self::neutraliser($texte);
            if ('' !== $propre) {
                $textes[] = \sprintf('%s "%s"', $role, $propre);
            }
        }
        if ([] !== $textes) {
            $lignes[] = 'Text to display, in French, spelled exactly as written: '.implode('; ', $textes).'.';
        }
        $couleurs = array_map(static fn (string $c): string => \sprintf('%s (%s)', self::COULEURS_ANGLAIS[strtoupper($c)] ?? 'colour', strtoupper($c)), $demande->getCouleurs());
        if ([] !== $couleurs) {
            $lignes[] = 'Colour palette: '.implode(', ', $couleurs).'.';
        }
        if ($nombreElements > 0) {
            $lignes[] = 'The client\'s own logo and photos will be placed later by a designer: keep a clean empty area for a logo, do not invent one.';
        }
        $lignes[] = 'Do not draw any brand logo, trademark, real person, celebrity or recognisable face. Large, legible text. No watermark, no signature, no mockup.';
        if (null !== $consigne) {
            $lignes[] = 'Latest client request (French): "'.str_replace('"', "'", $consigne).'".';
        }

        // Les consignes libres passent en dernier : elles sont coupées si la consigne est trop longue.
        $prompt = implode("\n", $lignes);
        if ('' !== $consignes) {
            $reste = self::PROMPT_MAX - mb_strlen($prompt) - 60;
            if ($reste > 40) {
                $prompt .= "\n".'Other instructions from the client (French): "'.mb_substr(str_replace('"', "'", $consignes), 0, $reste).'".';
            }
        }

        return mb_substr($prompt, 0, self::PROMPT_MAX);
    }

    private static function normaliser(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $sansAccents = \function_exists('transliterator_transliterate') ? transliterator_transliterate('Any-Latin; Latin-ASCII', $texte) : false;
        if (false === $sansAccents || null === $sansAccents) {
            $sansAccents = strtr($texte, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        }

        return str_replace(['’', '‘'], "'", $sansAccents);
    }

    /** Retire un terme (sans tenir compte des accents ni de la casse). */
    private static function retirer(string $texte, string $terme): string
    {
        $motif = '';
        foreach (preg_split('//u', $terme, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $lettre) {
            $motif .= match ($lettre) {
                'e' => '[eéèêëEÉÈÊË]', 'a' => '[aàâäAÀÂÄ]', 'i' => '[iîïIÎÏ]', 'o' => '[oôöOÔÖ]', 'u' => '[uùûüUÙÛÜ]', 'c' => '[cçCÇ]',
                ' ', '-' => '[\s\-]',
                default => preg_quote($lettre, '/'),
            };
        }

        return trim(preg_replace('/\s{2,}/u', ' ', preg_replace('/(?<![\p{L}\p{N}])'.$motif.'(?![\p{L}\p{N}])/iu', '', $texte) ?? $texte) ?? $texte);
    }
}
