<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de messages entre un client et l'atelier (Messages, maquette EC01) :
 * texte seulement, rattaché si besoin à une de ses commandes. Le client
 * écrit en ligne, le message monte au local ; l'atelier répond depuis
 * Clients › Messages et la réponse redescend, le client en est prévenu.
 */
#[ORM\Entity]
#[ORM\Table(name: 'message_client')]
#[ORM\Index(name: 'idx_message_client_fil', columns: ['client_id', 'cree_le'])]
class MessageClient
{
    use PublicIdTrait;

    public const CLIENT = 'client';
    public const ATELIER = 'atelier';

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

    /** client ou atelier. */
    #[ORM\Column(length: 10)]
    private string $sens = self::CLIENT;

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
        $m->sens = self::ATELIER === $sens ? self::ATELIER : self::CLIENT;
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
     * Qui a écrit pour l'atelier, tel que le client le voit : le prénom, le
     * métier (« Seydou · graphiste PAO ») et un disque à l'initiale du
     * prénom. Tiré du seul auteur enregistré à la réponse (prénom et métier,
     * jamais le nom de famille), qui redescend en ligne. Pas de photo : la
     * fiche employé n'a pas d'accord pour montrer la photo aux clients.
     * Null pour un message du client.
     *
     * @return array{nom: string, metier: ?string, initiales: string, teinte: ?int}|null
     */
    public function getSignature(): ?array
    {
        return $this->estDeLAtelier() ? self::signatureDe($this->auteur) : null;
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
