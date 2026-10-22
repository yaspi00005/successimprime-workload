<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AssistantMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une question du client ou une réponse de l'assistant
 * (docs/assistant-client.md).
 *
 * Ce qui monte au local : le texte montré, l'issue, les outils appelés,
 * l'avis (pouce) et la dépense. Les échanges exacts avec l'API (contenuApi,
 * rejoués tels quels à la question suivante) et les boutons proposés
 * restent en ligne.
 */
#[ORM\Entity(repositoryClass: AssistantMessageRepository::class)]
#[ORM\Table(name: 'assistant_message')]
#[ORM\Index(name: 'idx_assistant_message_cree', columns: ['cree_le'])]
class AssistantMessage
{
    use PublicIdTrait;

    public const CLIENT = 'client';
    public const ASSISTANT = 'assistant';

    /** Réponse donnée avec les outils. */
    public const ISSUE_REPONSE = 'reponse';
    /** Lien WhatsApp vers un conseiller donné (demande, argent, réponse introuvable). */
    public const ISSUE_AGENT = 'agent';
    /** Le modèle a refusé de répondre : passage à un conseiller. */
    public const ISSUE_REFUS = 'refus';
    /** API injoignable ou réponse inutilisable. */
    public const ISSUE_ERREUR = 'erreur';
    /** Plafond de dépense du jour atteint : pas d'appel. */
    public const ISSUE_PLAFOND = 'plafond';
    /** Conversation trop longue : pas d'appel. */
    public const ISSUE_LIMITE = 'limite';

    public const ISSUES = [
        self::ISSUE_REPONSE => 'Répondu',
        self::ISSUE_AGENT => 'Vers un conseiller',
        self::ISSUE_REFUS => 'Refus du modèle',
        self::ISSUE_ERREUR => 'Erreur',
        self::ISSUE_PLAFOND => 'Plafond du jour',
        self::ISSUE_LIMITE => 'Conversation trop longue',
    ];

    public const NOTE_UTILE = 1;
    public const NOTE_PAS_UTILE = -1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AssistantConversation $conversation = null;

    #[ORM\Column(length: 10)]
    private string $role = self::CLIENT;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    /** Réponse de l'assistant seulement. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $issue = null;

    /** Outils appelés pour cette réponse, séparés par des virgules. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $outils = null;

    /** Pouce du client : 1 utile, -1 pas utile. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $note = null;

    /** Modèle qui a répondu (celui de la conversation, ou celui de repli du serveur). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $modele = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $jetonsEntree = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $jetonsSortie = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $jetonsCacheLecture = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $jetonsCacheEcriture = 0;

    /** Dépense de l'API pour cette réponse, en dollars. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $depenseUsd = '0.000000';

    /** La même en FCFA, au taux du réglage ce jour-là : c'est elle que compte le plafond du jour. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $depenseFcfa = '0.00';

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /* ---------- En ligne seulement (jamais envoyés) ---------- */

    /**
     * Messages de l'API de ce tour, tels qu'envoyés et reçus (réflexion,
     * appels d'outils et résultats compris), rejoués sans changement. Null :
     * tour hors de l'historique (refus, erreur, plafond).
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $contenuApi = null;

    /** @var list<array{libelle: string, url: string, type: string}>|null boutons montrés sous la réponse */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $actions = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function question(AssistantConversation $conversation, string $texte, \DateTimeImmutable $le): self
    {
        $m = new self();
        $m->conversation = $conversation;
        $m->role = self::CLIENT;
        $m->texte = $texte;
        $m->creeLe = $le;

        return $m;
    }

    public static function reponse(AssistantConversation $conversation, string $texte, string $issue, \DateTimeImmutable $le): self
    {
        $m = new self();
        $m->conversation = $conversation;
        $m->role = self::ASSISTANT;
        $m->texte = $texte;
        $m->issue = isset(self::ISSUES[$issue]) ? $issue : self::ISSUE_ERREUR;
        $m->creeLe = $le;

        return $m;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?AssistantConversation
    {
        return $this->conversation;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function estDuClient(): bool
    {
        return self::CLIENT === $this->role;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getIssue(): ?string
    {
        return $this->issue;
    }

    public function getLibelleIssue(): string
    {
        return self::ISSUES[$this->issue ?? ''] ?? '';
    }

    /** L'assistant a répondu (passage à un conseiller compris) ; sinon refus, erreur, plafond ou limite. */
    public function estRepondue(): bool
    {
        return \in_array($this->issue, [self::ISSUE_REPONSE, self::ISSUE_AGENT], true);
    }

    public function getOutils(): ?string
    {
        return $this->outils;
    }

    /** @return list<string> */
    public function getListeOutils(): array
    {
        return null === $this->outils || '' === $this->outils ? [] : explode(',', $this->outils);
    }

    /** @param list<string> $outils */
    public function setOutils(array $outils): static
    {
        $outils = array_values(array_unique($outils));
        $this->outils = [] === $outils ? null : mb_substr(implode(',', $outils), 0, 255);

        return $this;
    }

    public function getNote(): ?int
    {
        return $this->note;
    }

    /** 1, -1, ou null pour retirer son avis. */
    public function noter(?int $note): static
    {
        $this->note = \in_array($note, [self::NOTE_UTILE, self::NOTE_PAS_UTILE], true) ? $note : null;

        return $this;
    }

    public function getModele(): ?string
    {
        return $this->modele;
    }

    /**
     * Jetons et dépense de toutes les requêtes de cette réponse.
     *
     * @param array{entree: int, sortie: int, cacheLecture: int, cacheEcriture: int} $jetons
     */
    public function noterDepense(?string $modele, array $jetons, float $usd, float $fcfa): static
    {
        $this->modele = null === $modele ? null : mb_substr($modele, 0, 60);
        $this->jetonsEntree = $jetons['entree'];
        $this->jetonsSortie = $jetons['sortie'];
        $this->jetonsCacheLecture = $jetons['cacheLecture'];
        $this->jetonsCacheEcriture = $jetons['cacheEcriture'];
        $this->depenseUsd = number_format($usd, 6, '.', '');
        $this->depenseFcfa = number_format($fcfa, 2, '.', '');

        return $this;
    }

    public function getJetonsEntree(): int
    {
        return $this->jetonsEntree;
    }

    public function getJetonsSortie(): int
    {
        return $this->jetonsSortie;
    }

    public function getJetonsCacheLecture(): int
    {
        return $this->jetonsCacheLecture;
    }

    public function getJetonsCacheEcriture(): int
    {
        return $this->jetonsCacheEcriture;
    }

    public function getDepenseUsd(): float
    {
        return (float) $this->depenseUsd;
    }

    public function getDepenseFcfa(): float
    {
        return (float) $this->depenseFcfa;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** @return list<array<string, mixed>>|null */
    public function getContenuApi(): ?array
    {
        return $this->contenuApi;
    }

    /** @param list<array<string, mixed>>|null $contenu */
    public function setContenuApi(?array $contenu): static
    {
        $this->contenuApi = $contenu;

        return $this;
    }

    /** @return list<array{libelle: string, url: string, type: string}> */
    public function getActions(): array
    {
        return $this->actions ?? [];
    }

    /** @param list<array{libelle: string, url: string, type: string}> $actions */
    public function setActions(array $actions): static
    {
        $this->actions = [] === $actions ? null : $actions;

        return $this;
    }
}
