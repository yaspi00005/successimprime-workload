<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Fichiers envoyés par le personnel (docs/securite.md).
 *
 * À l'arrivée : nom choisi par le serveur, extension choisie d'après le
 * contenu du fichier, dans une liste fermée, jamais d'après ce que le
 * navigateur annonce. Sans cela, un fichier PHP envoyé dans le chat
 * finissait en « .php » dans public/uploads/chat et le serveur l'aurait
 * exécuté.
 *
 * Au départ (reponse()) : servi par un contrôleur qui a vérifié les droits,
 * avec un type lu dans le contenu ; une image ou un PDF s'ouvre dans le
 * navigateur, le reste se télécharge, rien ne s'exécute.
 */
final class FichierEnvoye
{
    /** Pièces jointes du chat : type lu dans le contenu => extension enregistrée. */
    public const TYPES_CHAT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    public const TAILLE_MAX_CHAT = 10 * 1024 * 1024;

    /** Types qui s'ouvrent dans le navigateur ; tout le reste se télécharge. Jamais SVG ni HTML. */
    public const AFFICHABLES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'application/pdf'];

    /**
     * Jamais écrites dans un dossier public : le serveur les exécuterait
     * (PHP, CGI) ou le navigateur les ouvrirait comme une page du site
     * (HTML, SVG, JavaScript).
     */
    public const EXTENSIONS_INTERDITES = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'htaccess', 'ini',
    ];

    /** Extension à enregistrer pour un fichier accepté, null s'il est refusé (type ou taille). */
    public static function extensionPour(UploadedFile $fichier, array $types, int $tailleMax): ?string
    {
        if (!$fichier->isValid() || $fichier->getSize() > $tailleMax) {
            return null;
        }

        return $types[strtolower((string) $fichier->getMimeType())] ?? null;
    }

    /** Extension proposée gardée seulement si elle ne peut pas s'exécuter ; sinon « bin ». */
    public static function extensionPublique(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension));

        return '' === $extension || !preg_match('/^[a-z0-9]{1,8}$/', $extension) || \in_array($extension, self::EXTENSIONS_INTERDITES, true)
            ? 'bin'
            : $extension;
    }

    /**
     * Réponse pour un fichier privé, après contrôle des droits par le
     * contrôleur. Le type vient du contenu (jamais de la base ni du nom) :
     * une page HTML ou un SVG déguisés partent en téléchargement, en
     * « application/octet-stream ». En plus des en-têtes de
     * EntetesSecuriteSubscriber, posés ici pour ne pas en dépendre :
     * nosniff, politique « sandbox » (PDF : seulement le cadre), cache
     * privé.
     *
     * @param string|null $nom nom proposé au téléchargement (nom d'origine)
     */
    public static function reponse(string $chemin, ?string $nom = null, bool $telecharger = false): BinaryFileResponse
    {
        $type = strtolower(trim(explode(';', (string) @mime_content_type($chemin))[0]));
        $affichable = \in_array($type, self::AFFICHABLES, true);
        // Documents du chat (Word, Excel, ZIP, texte…) : type gardé, mais toujours téléchargés.
        $typeEnvoye = $affichable || isset(self::TYPES_CHAT[$type]) ? $type : 'application/octet-stream';

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', $typeEnvoye);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Content-Security-Policy', 'application/pdf' === $typeEnvoye ? "frame-ancestors 'self'" : EntetesSecurite::POLITIQUE_FICHIER);
        $reponse->setContentDisposition(
            $affichable && !$telecharger ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            self::nomTelechargement($nom, $chemin),
        );
        // Gardé une heure par le navigateur de la personne seulement (jamais par un relais).
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);

        return $reponse;
    }

    /** Nom proposé au téléchargement : sans « / », « \ » ni caractère de contrôle (l'en-tête les refuse). */
    public static function nomTelechargement(?string $nom, string $chemin): string
    {
        $nom = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '-', (string) $nom), " -\t");

        return '' !== $nom ? mb_substr($nom, -150) : basename($chemin);
    }
}
