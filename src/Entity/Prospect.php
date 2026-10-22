<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Prospect suivi dans le pipeline commercial (maquette 34), du premier
 * contact jusqu'à la commande gagnée ou l'affaire perdue. Une fois gagné,
 * le prospect est relié à la fiche client créée ou retrouvée.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_prospect_etape', columns: ['etape'])]
class Prospect
{
    public const SOURCES = [
        'site' => 'site',
        'whatsapp' => 'WhatsApp',
        'boutique' => 'à l\'imprimerie',
        'telephone' => 'téléphone',
        'recommandation' => 'recommandation',
        'autre' => 'autre',
    ];

    public const SOURCES_LIBELLES = [
        'site' => 'Site web',
        'whatsapp' => 'WhatsApp',
        'boutique' => 'À l\'imprimerie',
        'telephone' => 'Téléphone',
        'recommandation' => 'Recommandation',
        'autre' => 'Autre',
    ];

    public const ETAPE_NOUVEAU = 'nouveau';
    public const ETAPE_DEVIS_ENVOYE = 'devis_envoye';
    public const ETAPE_NEGOCIATION = 'negociation';
    public const ETAPE_GAGNE = 'gagne';
    public const ETAPE_PERDU = 'perdu';

    public const ETAPES = [
        self::ETAPE_NOUVEAU => 'Nouveau',
        self::ETAPE_DEVIS_ENVOYE => 'Devis envoyé',
        self::ETAPE_NEGOCIATION => 'Négociation',
        self::ETAPE_GAGNE => 'Gagné',
        self::ETAPE_PERDU => 'Perdu',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $besoin = null;

    #[ORM\Column(nullable: true)]
    private ?int $montantEstime = null;

    #[ORM\Column(length: 20)]
    private string $source = 'autre';

    #[ORM\Column(length: 20)]
    private string $etape = self::ETAPE_NOUVEAU;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifPerte = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prochaineRelance = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $agent = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(name: 'mis_a_jour_le')]
    private \DateTimeImmutable $misAJourLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureLe = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->misAJourLe = $this->creeLe;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr(trim($nom), 0, 120);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $telephone = null === $telephone ? '' : preg_replace('/[^0-9+]/', '', $telephone);
        $this->telephone = '' === $telephone ? null : mb_substr((string) $telephone, 0, 30);

        return $this;
    }

    public function getBesoin(): ?string
    {
        return $this->besoin;
    }

    public function setBesoin(?string $besoin): static
    {
        $besoin = trim((string) $besoin);
        $this->besoin = '' === $besoin ? null : mb_substr($besoin, 0, 255);

        return $this;
    }

    public function getMontantEstime(): ?int
    {
        return $this->montantEstime;
    }

    public function setMontantEstime(?int $montantEstime): static
    {
        $this->montantEstime = null === $montantEstime ? null : max(0, $montantEstime);

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = isset(self::SOURCES[$source]) ? $source : 'autre';

        return $this;
    }

    public function getEtape(): string
    {
        return $this->etape;
    }

    /**
     * Change d'étape. Une affaire perdue garde son motif ; gagnée ou perdue,
     * elle est datée de sa clôture, et rouverte, elle repart sans date.
     */
    public function changerEtape(string $etape, ?string $motifPerte = null, ?\DateTimeImmutable $le = null): static
    {
        if (!isset(self::ETAPES[$etape])) {
            throw new \InvalidArgumentException(sprintf('Étape inconnue « %s ».', $etape));
        }
        $le ??= new \DateTimeImmutable();
        if (self::ETAPE_PERDU === $etape) {
            $motif = trim((string) $motifPerte);
            if ('' === $motif) {
                throw new \InvalidArgumentException('Indiquez pourquoi l’affaire est perdue.');
            }
            $this->motifPerte = mb_substr($motif, 0, 255);
        } else {
            $this->motifPerte = null;
        }
        if (!$this->estClos($etape)) {
            $this->clotureLe = null;
        } elseif ($this->etape !== $etape || null === $this->clotureLe) {
            $this->clotureLe = $le;
            $this->prochaineRelance = null;
        }
        $this->etape = $etape;
        $this->misAJourLe = $le;

        return $this;
    }

    public function estClos(?string $etape = null): bool
    {
        return \in_array($etape ?? $this->etape, [self::ETAPE_GAGNE, self::ETAPE_PERDU], true);
    }

    public function getMotifPerte(): ?string
    {
        return $this->motifPerte;
    }

    public function getProchaineRelance(): ?\DateTimeImmutable
    {
        return $this->prochaineRelance;
    }

    public function setProchaineRelance(?\DateTimeImmutable $prochaineRelance): static
    {
        $this->prochaineRelance = $prochaineRelance;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAgent(): ?User
    {
        return $this->agent;
    }

    public function setAgent(?User $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $creeLe): static
    {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function getMisAJourLe(): \DateTimeImmutable
    {
        return $this->misAJourLe;
    }

    public function toucher(): static
    {
        $this->misAJourLe = new \DateTimeImmutable();

        return $this;
    }

    public function getClotureLe(): ?\DateTimeImmutable
    {
        return $this->clotureLe;
    }
}
