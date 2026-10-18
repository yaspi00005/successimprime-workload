<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message d'une conversation entre un client et l'atelier (Messages,
 * maquettes MS01 à MS05) : texte, avec au besoin un BAT ou une facture de
 * la commande joints. Le client écrit en ligne, le message monte au local ;
 * l'atelier répond depuis Clients › Messages et la réponse redescend, le
 * client en est prévenu. Un « événement » (collègue ajouté, clôture
 * proposée) s'affiche au milieu du fil, sans bulle.
 *
 * Restent au local : le traitement, l'employé qui a répondu, le délai cible,
 * le retard, la personne à qui il est imputé et la pénalité.
 */
#[ORM\Entity]
#[ORM\Table(name: 'message_client')]
#[ORM\Index(name: 'idx_message_client_fil', columns: ['client_id', 'cree_le'])]
#[ORM\Index(name: 'idx_message_client_conversation', columns: ['conversation_id', 'cree_le'])]
class MessageClient
{
    use PublicIdTrait;

    public const CLIENT = 'client';
    public const ATELIER = 'atelier';
    /** Ligne d'information du fil (« Aminata a ajouté Kadiatou Sow (Trésorière) à la conversation »). */
    public const EVENEMENT = 'evenement';

    /** Longueur d'un message. */
    public const TEXTE_MAX = 1000;

    /** Signature d'une réponse de l'atelier quand l'employé n'a pas de prénom (et des anciennes réponses). */
    public const NOM_ATELIER = 'Succès Imprim';

    /** Nombre de teintes du disque à l'initiale (classes t0 à t8 de l'espace client, palette de l'équipe). */
    public const TEINTES = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ConversationClient $conversation = null;

    /** client, atelier ou evenement. */
    #[ORM\Column(length: 10)]
    private string $sens = self::CLIENT;

    /** Qui a écrit pour l'atelier : nom, métier et photo tels que le client les voit. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AgentMessagerie $agent = null;

    /** BAT de la commande joint au message (« BAT n°2.pdf »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ControlePrePresse $bat = null;

    /** Facture jointe au message. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Factures $facture = null;

    /** Réponse de l'atelier : minutes ouvrées depuis le message du client (« répondu en 4 min »). Part au site. */
    #[ORM\Column(nullable: true)]
    private ?int $delaiMinutes = null;

    /** Au local : délai cible de cette réponse (1re réponse ou suivantes). */
    #[ORM\Column(nullable: true)]
    private ?int $cibleMinutes = null;

    /** Au local : minutes au-delà de la cible (0 à l'heure). */
    #[ORM\Column(nullable: true)]
    private ?int $retardMinutes = null;

    /** Au local : points retirés à la personne responsable pour ce retard. */
    #[ORM\Column(options: ['default' => 0])]
    private int $penalite = 0;

    /** Au local : personne responsable de répondre, à qui le retard est imputé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $imputeA = null;

    /** Au local : première réponse de la conversation. */
    #[ORM\Column(options: ['default' => false])]
    private bool $premiere = false;

    /** Personne du compte qui l'a écrit ; pour l'atelier, le prénom et le métier de l'employé ou « Succès Imprim ». */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Au local : message du client traité (répondu ou classé). Ne part pas. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    /** Au local : employé qui a répondu. Ne part pas. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $ecritPar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouveau(Clients $client, string $sens, string $texte, ?string $auteur, ?Commandes $commande, \DateTimeImmutable $le): self
    {
        $m = new self();
        $m->client = $client;
        $m->sens = \in_array($sens, [self::ATELIER, self::EVENEMENT], true) ? $sens : self::CLIENT;
        $m->texte = mb_substr(trim($texte), 0, self::TEXTE_MAX);
        $m->auteur = null !== $auteur ? mb_substr($auteur, 0, 80) : null;
        $m->commande = $commande;
        $m->creeLe = $le;

        return $m;
    }

    public function estDeLAtelier(): bool
    {
        return self::ATELIER === $this->sens;
    }

    public function estUnEvenement(): bool
    {
        return self::EVENEMENT === $this->sens;
    }

    public function estDuClient(): bool
    {
        return self::CLIENT === $this->sens;
    }

    /** Délai de la réponse, cible, retard et pénalité (au local). */
    public function noterDelai(int $delai, int $cible, ?User $imputeA, bool $premiere, int $pointsParRetard): static
    {
        $this->delaiMinutes = max(0, $delai);
        $this->cibleMinutes = max(0, $cible);
        $this->retardMinutes = max(0, $delai - $cible);
        $this->imputeA = $imputeA;
        $this->premiere = $premiere;
        $this->penalite = $this->retardMinutes > 0 && null !== $imputeA ? max(0, $pointsParRetard) : 0;

        return $this;
    }

    public function estEnRetard(): bool
    {
        return ($this->retardMinutes ?? 0) > 0;
    }

    public function traiter(?\DateTimeImmutable $le): static
    {
        $this->traiteLe = $le;

        return $this;
    }

    public function setEcritPar(?User $u): static
    {
        $this->ecritPar = $u;

        return $this;
    }

    /**
     * Qui a écrit pour l'atelier, tel que le client le voit (maquette MS01) :
     * nom, métier (« Caisse et commercial ») et petite photo de l'employé
     * (AgentMessagerie, qui descend au site), sinon ses initiales sur un
     * disque de teinte fixe. Les anciennes réponses, sans agent, gardent
     * l'auteur enregistré (« Seydou (graphiste PAO) »). Null pour un message
     * du client ou un événement.
     *
     * @return array{nom: string, metier: ?string, initiales: string, teinte: ?int, photo: ?string}|null
     */
    public function getSignature(): ?array
    {
        if (!$this->estDeLAtelier()) {
            return null;
        }
        if (null !== $this->agent && '' !== $this->agent->getNom()) {
            return ['nom' => $this->agent->getNom(), 'metier' => $this->agent->getMetier(), 'initiales' => $this->agent->getInitiales(), 'teinte' => $this->agent->getTeinte(), 'photo' => $this->agent->getPhotoDataUri()];
        }

        return self::signatureDe($this->auteur) + ['photo' => null];
    }

    /**
     * « Seydou (graphiste PAO) » donne Seydou, graphiste PAO, « S » et une
     * teinte fixe pour ce prénom ; rien ou « Succès Imprim » donne la
     * signature de la boutique (« SI », teinte null : le bleu de la marque).
     *
     * @return array{nom: string, metier: ?string, initiales: string, teinte: ?int}
     */
    public static function signatureDe(?string $auteur): array
    {
        $auteur = trim((string) preg_replace('/\s+/u', ' ', (string) $auteur));
        if ('' === $auteur || mb_strtolower($auteur) === mb_strtolower(self::NOM_ATELIER)) {
            return ['nom' => self::NOM_ATELIER, 'metier' => null, 'initiales' => 'SI', 'teinte' => null];
        }
        $metier = null;
        $nom = $auteur;
        // La parenthèse fermante peut manquer : l'auteur est coupé à 80 caractères.
        if (1 === preg_match('/^(.+?)\s*\((.+?)\)?$/u', $auteur, $m)) {
            $nom = trim($m[1]);
            $metier = trim($m[2]);
        }
        // Prénom composé (« Mame Diarra », « Jean-Marc ») : deux initiales au plus, du prénom seulement.
        $initiales = '';
        foreach (preg_split('/[\s\-]+/u', $nom, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            if (1 === preg_match('/^\p{L}/u', $mot) && mb_strlen($initiales) < 2) {
                $initiales .= mb_strtoupper(mb_substr($mot, 0, 1));
            }
        }

        return ['nom' => $nom, 'metier' => $metier, 'initiales' => '' !== $initiales ? $initiales : '?', 'teinte' => crc32(mb_strtolower($nom)) % self::TEINTES];
    }

    public function getConversation(): ?ConversationClient { return $this->conversation; }
    public function setConversation(?ConversationClient $c): static { $this->conversation = $c; return $this; }
    public function getAgent(): ?AgentMessagerie { return $this->agent; }
    public function setAgent(?AgentMessagerie $a): static { $this->agent = $a; return $this; }
    public function getBat(): ?ControlePrePresse { return $this->bat; }
    public function setBat(?ControlePrePresse $b): static { $this->bat = $b; return $this; }
    public function getFacture(): ?Factures { return $this->facture; }
    public function setFacture(?Factures $f): static { $this->facture = $f; return $this; }
    public function getDelaiMinutes(): ?int { return $this->delaiMinutes; }
    public function getCibleMinutes(): ?int { return $this->cibleMinutes; }
    public function getRetardMinutes(): ?int { return $this->retardMinutes; }
    public function getPenalite(): int { return $this->penalite; }
    public function getImputeA(): ?User { return $this->imputeA; }
    public function isPremiere(): bool { return $this->premiere; }
    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function getSens(): string { return $this->sens; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getTexte(): string { return $this->texte; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function getEcritPar(): ?User { return $this->ecritPar; }
}
