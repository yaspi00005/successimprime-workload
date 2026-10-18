<?php

namespace App\Service\Messagerie;

use App\Entity\CompteClient;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Security\EntetesSecurite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

/**
 * Notes vocales de la messagerie (docs/messagerie-clients.md, « Notes
 * vocales ») : enregistrées dans le navigateur ou le téléphone, jointes à
 * un message de la conversation d'une commande (vu par le client) ou à une
 * note interne (équipe seulement).
 *
 * Les fichiers sont sous var/uploads/vocales, jamais publics ; ils ne
 * sortent que par les routes des contrôleurs de la messagerie, après le
 * contrôle des droits de la conversation.
 */
final class NotesVocales
{
    /** Durée maximale d'un enregistrement (3 minutes). */
    public const DUREE_MAX = 180;

    /** Marge pour l'arrêt de l'enregistreur un peu après 3:00. */
    public const TOLERANCE = 5;

    public const DUREE_MIN = 1;

    public const TAILLE_MAX = 10 * 1024 * 1024;

    public const DOSSIER = 'var/uploads/vocales';

    /** Nom de stockage choisi par le serveur. */
    public const NOM = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(webm|ogg|m4a)$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    public function dossier(): string
    {
        $dossier = $this->projet.'/'.self::DOSSIER;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des notes vocales impossible à créer.');
        }

        return $dossier;
    }

    /**
     * Vérifie le fichier envoyé (taille, type lu dans le contenu, durée) et
     * le range sous un nom choisi par le serveur.
     *
     * @return array{nom: string, type: string, taille: int, duree: int, empreinte: string}
     *
     * @throws \DomainException
     */
    public function enregistrer(?UploadedFile $fichier): array
    {
        if (!$fichier instanceof UploadedFile) {
            throw new \DomainException('Aucune note vocale reçue.');
        }
        if (!$fichier->isValid()) {
            throw new \DomainException(\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'Note vocale trop lourde.'
                : 'La note vocale n\'est pas arrivée en entier. Réessayez.');
        }

        return $this->ranger((string) file_get_contents($fichier->getPathname(), false, null, 0, self::TAILLE_MAX + 1));
    }

    /**
     * Range un contenu audio vérifié (envoi, démonstration).
     *
     * @return array{nom: string, type: string, taille: int, duree: int, empreinte: string}
     *
     * @throws \DomainException
     */
    public function ranger(string $contenu): array
    {
        $vocale = self::verifier($contenu);
        $vocale['nom'] = Uuid::v4()->toRfc4122().'.'.AnalyseAudio::TYPES[$vocale['type']];
        $cible = $this->dossier().'/'.$vocale['nom'];
        if (false === file_put_contents($cible, $contenu, \LOCK_EX)) {
            throw new \RuntimeException('Note vocale impossible à enregistrer.');
        }
        @chmod($cible, 0640);

        return $vocale;
    }

    /**
     * Contrôle d'un contenu audio : taille, format de l'enregistreur du
     * navigateur, durée (1 s à 3 min).
     *
     * @return array{type: string, taille: int, duree: int, empreinte: string}
     *
     * @throws \DomainException
     */
    public static function verifier(string $contenu): array
    {
        $taille = \strlen($contenu);
        if ($taille > self::TAILLE_MAX) {
            throw new \DomainException(\sprintf('Note vocale trop lourde (%d Mo au plus).', self::TAILLE_MAX / 1048576));
        }
        $audio = AnalyseAudio::analyser($contenu);
        if (null === $audio['duree']) {
            throw new \DomainException('Durée de la note vocale illisible : réenregistrez-la.');
        }
        if ($audio['duree'] > self::DUREE_MAX + self::TOLERANCE) {
            throw new \DomainException(\sprintf('Note vocale trop longue : %d minutes au plus.', intdiv(self::DUREE_MAX, 60)));
        }
        if ($audio['duree'] < self::DUREE_MIN - 0.5) {
            throw new \DomainException('Note vocale trop courte.');
        }

        return [
            'type' => $audio['type'],
            'taille' => $taille,
            'duree' => min(self::DUREE_MAX, max(self::DUREE_MIN, (int) round($audio['duree']))),
            'empreinte' => hash('sha256', $contenu),
        ];
    }

    /**
     * Retire un fichier tout juste rangé quand le message n'a pas pu être
     * écrit (demande clôturée, trop de messages).
     *
     * @param array{nom?: string}|null $vocale
     */
    public function retirer(?array $vocale): void
    {
        $nom = (string) ($vocale['nom'] ?? '');
        if (preg_match(self::NOM, $nom)) {
            @unlink($this->projet.'/'.self::DOSSIER.'/'.$nom);
        }
    }

    /** Le fichier sur le disque, ou null (nom refusé, absent, pas encore arrivé par la synchronisation). */
    public function chemin(MessageClient|NoteConversation $element): ?string
    {
        $nom = (string) $element->getVocaleNom();
        if (!preg_match(self::NOM, $nom)) {
            return null;
        }
        $chemin = $this->projet.'/'.self::DOSSIER.'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Réponse pour l'écoute, après contrôle des droits par le contrôleur :
     * type lu dans le contenu, rien ne s'exécute, cache privé, lecture par
     * morceaux (Safari en a besoin).
     */
    public function reponse(MessageClient|NoteConversation $element): BinaryFileResponse
    {
        $chemin = $this->chemin($element) ?? throw new \RuntimeException('Note vocale absente.');
        try {
            $type = AnalyseAudio::analyser((string) file_get_contents($chemin, false, null, 0, self::TAILLE_MAX))['type'];
        } catch (\DomainException) {
            $type = 'application/octet-stream';
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', $type);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Content-Security-Policy', EntetesSecurite::POLITIQUE_FICHIER);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'note-vocale.'.(AnalyseAudio::TYPES[$type] ?? 'bin'));
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);

        return $reponse;
    }

    /** La note vocale fait partie de cette conversation (l'adresse ne mélange pas deux conversations). */
    public static function appartient(MessageClient|NoteConversation $element, ConversationClient $c): bool
    {
        return $element->aUneNoteVocale() && $element->getConversation() === $c;
    }

    /**
     * Le client écoute une note de sa conversation : jamais une note
     * interne (ce n'est pas un MessageClient), jamais celle d'un autre
     * client, pas pour un compte « Lecture · suivi ».
     */
    public static function clientPeutEcouter(CompteClient $compte, ConversationClient $c, MessageClient $m): bool
    {
        $client = $compte->getClient();

        return null !== $client
            && !$compte->estLectureSeule()
            && $c->getClient() === $client
            && $m->getClient() === $client
            && !$m->estUnEvenement()
            && self::appartient($m, $c);
    }
}
