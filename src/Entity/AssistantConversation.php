<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AssistantConversationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Conversation avec l'assistant du site ou de l'espace client
 * (docs/assistant-client.md).
 *
 * Créée en ligne, elle monte au local (docs/synchronisation.md) pour
 * Clients › Assistant du site : numéro, page, client et compteurs
 * seulement. Le jeton du visiteur et le compte connecté restent en ligne.
 */
#[ORM\Entity(repositoryClass: AssistantConversationRepository::class)]
#[ORM\Table(name: 'assistant_conversation')]
#[ORM\Index(name: 'idx_assistant_conversation_debut', columns: ['debut_le'])]
#[ORM\Index(name: 'idx_assistant_conversation_jeton', columns: ['jeton'])]
class AssistantConversation
{
    use PublicIdTrait;

    public const SITE = 'site';
    public const ESPACE = 'espace';

    public const CONTEXTES = [
        self::SITE => 'Site',
        self::ESPACE => 'Espace client',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** AS-0012 ; W-AS-0012 quand elle commence en ligne. Rappelé dans le message WhatsApp. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 10)]
    private string $contexte = self::SITE;

    /** Client connecté (espace client) ; jamais pour un visiteur du site. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    /** Personne du compte, au moment de la conversation. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $interlocuteur = null;

    /** Page où la conversation a commencé (chemin, sans paramètres). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $page = null;

    /** Modèle de toute la conversation : un changement de réglage vaut pour les suivantes. */
    #[ORM\Column(length: 60)]
    private string $modele;

    #[ORM\Column]
    private \DateTimeImmutable $debutLe;

    #[ORM\Column]
    private \DateTimeImmutable $derniereLe;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreQuestions = 0;

    /** Lien WhatsApp vers l'atelier donné (passage à un conseiller). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passageAgentLe = null;

    /* ---------- En ligne seulement (jamais envoyés) ---------- */

    /** Empreinte SHA-256 du jeton gardé par le navigateur du visiteur. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $jeton = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compte = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->debutLe = new \DateTimeImmutable();
        $this->derniereLe = $this->debutLe;
        $this->modele = '';
    }

    public static function nouvelle(string $contexte, string $modele, \DateTimeImmutable $le): self
    {
        $c = new self();
        $c->contexte = isset(self::CONTEXTES[$contexte]) ? $contexte : self::SITE;
        $c->modele = $modele;
        $c->debutLe = $le;
        $c->derniereLe = $le;

        return $c;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getContexte(): string
    {
        return $this->contexte;
    }

    public function getLibelleContexte(): string
    {
        return self::CONTEXTES[$this->contexte] ?? $this->contexte;
    }

    public function estEspace(): bool
    {
        return self::ESPACE === $this->contexte;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function getInterlocuteur(): ?string
    {
        return $this->interlocuteur;
    }

    /** Compte connecté de l'espace client : la fiche et le nom de la personne. */
    public function rattacher(CompteClient $compte): static
    {
        $this->compte = $compte;
        $this->client = $compte->getClient();
        $this->interlocuteur = mb_substr($compte->getNomAffiche(), 0, 120);

        return $this;
    }

    public function getCompte(): ?CompteClient
    {
        return $this->compte;
    }

    public function getPage(): ?string
    {
        return $this->page;
    }

    public function setPage(?string $page): static
    {
        $page = null === $page ? '' : trim($page);
        $this->page = '' === $page ? null : mb_substr($page, 0, 255);

        return $this;
    }

    public function getModele(): string
    {
        return $this->modele;
    }

    public function getDebutLe(): \DateTimeImmutable
    {
        return $this->debutLe;
    }

    public function getDerniereLe(): \DateTimeImmutable
    {
        return $this->derniereLe;
    }

    public function getNombreQuestions(): int
    {
        return $this->nombreQuestions;
    }

    /** Une question de plus, répondue ou non. */
    public function noterQuestion(\DateTimeImmutable $le): static
    {
        ++$this->nombreQuestions;
        $this->derniereLe = $le;

        return $this;
    }

    public function getPassageAgentLe(): ?\DateTimeImmutable
    {
        return $this->passageAgentLe;
    }

    public function noterPassageAgent(\DateTimeImmutable $le): static
    {
        $this->passageAgentLe ??= $le;

        return $this;
    }

    public function getJeton(): ?string
    {
        return $this->jeton;
    }

    /** Range l'empreinte du jeton donné au navigateur ; le jeton lui-même n'est jamais gardé. */
    public function setJeton(string $jetonClair): static
    {
        $this->jeton = self::empreinte($jetonClair);

        return $this;
    }

    public static function empreinte(string $jetonClair): string
    {
        return hash('sha256', $jetonClair);
    }
}
