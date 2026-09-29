<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDernierMessage = null;

    /**
     * Identifiant opaque utilisé dans l'URL à la place de l'id
     * numérique auto-incrémenté (pour ne pas exposer/laisser deviner
     * le nombre de conversations).
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $jeton = '';

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: ConversationParticipant::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $participants;

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, orphanRemoval: true)]
    #[ORM\OrderBy(['dateEnvoi' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function initialiserJeton(): void
    {
        if ($this->jeton === '') {
            $this->jeton = bin2hex(random_bytes(16));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateDernierMessage(): ?\DateTimeImmutable
    {
        return $this->dateDernierMessage;
    }

    public function setDateDernierMessage(\DateTimeImmutable $dateDernierMessage): static
    {
        $this->dateDernierMessage = $dateDernierMessage;

        return $this;
    }

    /**
     * @return Collection<int, ConversationParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function ajouterParticipant(User $utilisateur): static
    {
        if ($this->aPourParticipant($utilisateur)) {
            return $this;
        }

        $participant = (new ConversationParticipant())
            ->setConversation($this)
            ->setUtilisateur($utilisateur);

        $this->participants->add($participant);

        return $this;
    }

    public function aPourParticipant(User $utilisateur): bool
    {
        return $this->getParticipantPour($utilisateur) !== null;
    }

    public function getParticipantPour(User $utilisateur): ?ConversationParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUtilisateur() === $utilisateur) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * Libellé de la conversation pour un utilisateur donné : les
     * autres participants (affichage type "discussion avec X").
     */
    public function getLibellePour(User $utilisateur): string
    {
        $autres = [];

        foreach ($this->participants as $participant) {
            if ($participant->getUtilisateur() !== $utilisateur) {
                $autres[] = $participant->getUtilisateur()->getUsername();
            }
        }

        return $autres !== [] ? implode(', ', $autres) : 'Vous';
    }

    /**
     * L'autre participant d'une conversation directe (à deux), pour
     * afficher son avatar/nom. Renvoie le premier trouvé si jamais la
     * conversation a plus de deux participants.
     */
    public function getAutreUtilisateurPour(User $utilisateur): ?User
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUtilisateur() !== $utilisateur) {
                return $participant->getUtilisateur();
            }
        }

        return null;
    }

    public function getDernierMessage(): ?Message
    {
        return $this->messages->last() ?: null;
    }
}
