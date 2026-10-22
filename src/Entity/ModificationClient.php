<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des modifications d'une fiche client (maquette K04) : rien ne
 * s'efface, chaque changement garde l'ancienne valeur, l'auteur, l'heure
 * et d'où il vient.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['client_id', 'le'])]
class ModificationClient
{
    public const CATEGORIES = ['identite' => 'Identité', 'conditions' => 'Conditions commerciales', 'contacts' => 'Contacts'];
    public const DEPUIS = ['erp_local' => 'ERP local', 'admin_en_ligne' => 'Admin en ligne', 'espace_client' => 'Espace client', 'synchronisation' => 'Synchronisation', 'import' => 'Import de données'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    #[ORM\Column]
    private \DateTimeImmutable $le;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par = null;

    /** Nom affiché de l'auteur (client, système) quand ce n'est pas un employé. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(length: 60)]
    private string $champ;

    #[ORM\Column(length: 20)]
    private string $categorie;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apres = null;

    #[ORM\Column(length: 20)]
    private string $depuis = 'erp_local';

    public function __construct(Clients $client, string $champ, string $categorie, ?string $avant, ?string $apres, \DateTimeImmutable $le, ?User $par, string $depuis = 'erp_local', ?string $auteur = null)
    {
        $this->client = $client;
        $this->champ = mb_substr($champ, 0, 60);
        $this->categorie = isset(self::CATEGORIES[$categorie]) ? $categorie : 'identite';
        $this->avant = $avant !== null ? mb_substr($avant, 0, 255) : null;
        $this->apres = $apres !== null ? mb_substr($apres, 0, 255) : null;
        $this->le = $le;
        $this->par = $par;
        $this->depuis = isset(self::DEPUIS[$depuis]) ? $depuis : 'erp_local';
        $this->auteur = $auteur !== null ? mb_substr($auteur, 0, 120) : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Clients { return $this->client; }
    public function getLe(): \DateTimeImmutable { return $this->le; }
    public function getPar(): ?User { return $this->par; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getChamp(): string { return $this->champ; }
    public function getCategorie(): string { return $this->categorie; }
    public function getAvant(): ?string { return $this->avant; }
    public function getApres(): ?string { return $this->apres; }
    public function getDepuis(): string { return $this->depuis; }
    public function getDepuisLabel(): string { return self::DEPUIS[$this->depuis]; }
}
