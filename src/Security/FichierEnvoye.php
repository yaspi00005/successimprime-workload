<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Nom de fichier sûr pour un envoi rangé sous public/ (docs/securite.md).
 *
 * L'extension ne vient jamais de ce que le navigateur annonce : elle est
 * choisie d'après le contenu du fichier, dans une liste fermée. Sans cela,
 * un fichier PHP envoyé dans le chat finissait en « .php » dans
 * public/uploads/chat et le serveur l'aurait exécuté.
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
}
