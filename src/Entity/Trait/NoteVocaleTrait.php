<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Note vocale jointe à un message de la conversation (MessageClient) ou à
 * une note interne (NoteConversation) : docs/messagerie-clients.md, « Notes
 * vocales ». Le fichier est rangé sous var/uploads/vocales (jamais public)
 * et ne sort que par une route qui vérifie les droits de la conversation.
 *
 * Nom de stockage choisi par le serveur (« <uuid>.webm »), type et durée lus
 * dans le contenu (AnalyseAudio), empreinte SHA-256 pour la synchronisation.
 */
trait NoteVocaleTrait
{
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $vocaleNom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vocaleType = null;

    #[ORM\Column(nullable: true)]
    private ?int $vocaleTaille = null;

    /** Secondes. */
    #[ORM\Column(nullable: true)]
    private ?int $vocaleDuree = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $vocaleEmpreinte = null;

    /** @param array{nom: string, type: string, taille: int, duree: int, empreinte: string} $vocale */
    public function joindreNoteVocale(array $vocale): static
    {
        $this->vocaleNom = $vocale['nom'];
        $this->vocaleType = $vocale['type'];
        $this->vocaleTaille = $vocale['taille'];
        $this->vocaleDuree = $vocale['duree'];
        $this->vocaleEmpreinte = $vocale['empreinte'];

        return $this;
    }

    public function aUneNoteVocale(): bool
    {
        return null !== $this->vocaleNom && '' !== $this->vocaleNom;
    }

    /** « 0:42 » */
    public function getVocaleDureeTexte(): string
    {
        return self::dureeVocale((int) $this->vocaleDuree);
    }

    public static function dureeVocale(int $secondes): string
    {
        $secondes = max(0, $secondes);

        return \sprintf('%d:%02d', intdiv($secondes, 60), $secondes % 60);
    }

    /** Texte enregistré avec une note vocale sans texte : il sert d'aperçu (liste, notification). */
    public static function libelleVocale(int $secondes): string
    {
        return 'Note vocale · '.self::dureeVocale($secondes);
    }

    /** Le texte n'est que le libellé automatique (rien à afficher sous le lecteur). */
    public function texteEstLibelleVocale(): bool
    {
        return $this->aUneNoteVocale() && $this->getTexte() === self::libelleVocale((int) $this->vocaleDuree);
    }

    public function getVocaleNom(): ?string { return $this->vocaleNom; }
    public function getVocaleType(): ?string { return $this->vocaleType; }
    public function getVocaleTaille(): ?int { return $this->vocaleTaille; }
    public function getVocaleDuree(): ?int { return $this->vocaleDuree; }
    public function getVocaleEmpreinte(): ?string { return $this->vocaleEmpreinte; }
}
