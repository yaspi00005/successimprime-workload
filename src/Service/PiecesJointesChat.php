<?php

namespace App\Service;

use App\Entity\Message;
use App\Security\FichierEnvoye;
use App\Service\Atelier\ImagesAtelier;
use App\Service\EspaceClient\FichiersClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pièces jointes du chat interne (docs/securite.md, « Fichiers envoyés ») :
 * rangées hors de public/ (var/uploads/chat), servies seulement aux
 * participants de la conversation (ChatController::fichier).
 *
 * Une photo (JPG, PNG, WebP) est réencodée à l'arrivée comme les images
 * des clients (ImagesAtelier::reencoder) : les données EXIF, dont la
 * position GPS du téléphone, disparaissent. Un GIF n'a pas d'EXIF : il
 * est gardé tel quel (animation comprise).
 */
final class PiecesJointesChat
{
    public const DOSSIER = 'chat';

    public const REFUS = 'Fichier refusé : images, PDF, documents Word, Excel, PowerPoint, texte ou ZIP de 10 Mo au plus.';

    /** Extensions (lues dans le contenu) des photos réencodées. */
    private const PHOTOS = ['jpg', 'png', 'webp'];

    public function __construct(private readonly FichiersPrives $fichiers)
    {
    }

    /**
     * Contrôle et range le fichier envoyé.
     *
     * @return array{fichier: string, nomOriginal: string} nom rangé, nom à afficher
     *
     * @throws \DomainException message à montrer
     */
    public function ranger(UploadedFile $envoi): array
    {
        // Type lu dans le contenu, liste fermée : jamais de .php, .html ni .svg.
        $extension = FichierEnvoye::extensionPour($envoi, FichierEnvoye::TYPES_CHAT, FichierEnvoye::TAILLE_MAX_CHAT)
            ?? throw new \DomainException(self::REFUS);
        $nomOriginal = FichiersClient::nomPropre($envoi->getClientOriginalName());
        $dossier = $this->fichiers->dossier(self::DOSSIER);
        $base = 'chat_'.bin2hex(random_bytes(12));

        if (\in_array($extension, self::PHOTOS, true)) {
            $image = ImagesAtelier::reencoder((string) file_get_contents($envoi->getPathname()));
            $nom = $base.'.'.$image['extension'];
            if (false === file_put_contents($dossier.'/'.$nom, $image['octets'], \LOCK_EX)) {
                throw new \DomainException('La photo n\'a pas pu être enregistrée : réessayez.');
            }
            // Un WebP devient PNG : le nom affiché suit.
            $attendues = 'jpg' === $image['extension'] ? ['jpg', 'jpeg'] : [$image['extension']];
            if (!\in_array(strtolower(pathinfo($nomOriginal, \PATHINFO_EXTENSION)), $attendues, true)) {
                $nomOriginal = pathinfo($nomOriginal, \PATHINFO_FILENAME).'.'.$image['extension'];
            }

            return ['fichier' => $nom, 'nomOriginal' => $nomOriginal];
        }

        $nom = $base.'.'.$extension;
        try {
            $envoi->move($dossier, $nom);
        } catch (\Throwable) {
            throw new \DomainException('Le fichier n\'a pas pu être enregistré : réessayez.');
        }

        return ['fichier' => $nom, 'nomOriginal' => $nomOriginal];
    }

    /** Le fichier joint au message sur le disque, ou null. */
    public function chemin(Message $message): ?string
    {
        return $this->fichiers->cheminDans(self::DOSSIER, $message->getPieceJointeFichier());
    }
}
