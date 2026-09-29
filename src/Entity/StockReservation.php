<?php

namespace App\Entity;

use App\Repository\StockReservationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockReservationRepository::class)]
#[ORM\Table(name: 'stock_reservation')]
#[ORM\Index(
    name: 'idx_stock_reservation_article',
    columns: ['article_id']
)]
#[ORM\Index(
    name: 'idx_stock_reservation_detail',
    columns: ['commande_detail_id']
)]
#[ORM\Index(
    name: 'idx_stock_reservation_statut',
    columns: ['statut']
)]
class StockReservation
{
    public const STATUT_ACTIVE = 'active';
    public const STATUT_CONSOMMEE = 'consommee';
    public const STATUT_LIBEREE = 'liberee';
    public const STATUT_ANNULEE = 'annulee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'article_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    private ?Articles $article = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'commande_detail_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?CommandesDetails $commandeDetail = null;

    #[ORM\Column(
        type: 'decimal',
        precision: 14,
        scale: 3
    )]
    private string $quantite = '0.000';

    #[ORM\Column(
        length: 30,
        options: ['default' => self::STATUT_ACTIVE]
    )]
    private string $statut = self::STATUT_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $dateReservation;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateLiberation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateConsommation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $observation = null;


    public function __construct()
    {
        $this->dateReservation =
            new \DateTimeImmutable();
    }


    public function getId(): ?int
    {
        return $this->id;
    }


    public function getArticle(): ?Articles
    {
        return $this->article;
    }


    public function setArticle(
        ?Articles $article
    ): static {
        $this->article = $article;

        return $this;
    }


    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }


    public function setCommandeDetail(
        ?CommandesDetails $commandeDetail
    ): static {
        $this->commandeDetail =
            $commandeDetail;

        return $this;
    }


    public function getQuantite(): string
    {
        return $this->quantite;
    }


    public function setQuantite(
        string|int|float $quantite
    ): static {
        $quantite =
            max(
                0,
                (float) $quantite
            );

        $this->quantite =
            number_format(
                $quantite,
                3,
                '.',
                ''
            );

        return $this;
    }


    public function getStatut(): string
    {
        return $this->statut;
    }


    public function setStatut(
        string $statut
    ): static {
        $statutsAutorises = [
            self::STATUT_ACTIVE,
            self::STATUT_CONSOMMEE,
            self::STATUT_LIBEREE,
            self::STATUT_ANNULEE,
        ];

        if (
            !in_array(
                $statut,
                $statutsAutorises,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Statut de réservation invalide.'
            );
        }

        $this->statut = $statut;

        return $this;
    }


    public function getDateReservation(): \DateTimeImmutable
    {
        return $this->dateReservation;
    }


    public function setDateReservation(
        \DateTimeImmutable $dateReservation
    ): static {
        $this->dateReservation =
            $dateReservation;

        return $this;
    }


    public function getDateLiberation(): ?\DateTimeImmutable
    {
        return $this->dateLiberation;
    }


    public function setDateLiberation(
        ?\DateTimeImmutable $dateLiberation
    ): static {
        $this->dateLiberation =
            $dateLiberation;

        return $this;
    }


    public function getDateConsommation(): ?\DateTimeImmutable
    {
        return $this->dateConsommation;
    }


    public function setDateConsommation(
        ?\DateTimeImmutable $dateConsommation
    ): static {
        $this->dateConsommation =
            $dateConsommation;

        return $this;
    }


    public function getObservation(): ?string
    {
        return $this->observation;
    }


    public function setObservation(
        ?string $observation
    ): static {
        $this->observation =
            $observation !== null
                ? trim($observation)
                : null;

        return $this;
    }


    public function isActive(): bool
    {
        return $this->statut
            === self::STATUT_ACTIVE;
    }


    public function marquerConsommee(): static
    {
        $this->statut =
            self::STATUT_CONSOMMEE;

        $this->dateConsommation =
            new \DateTimeImmutable();

        return $this;
    }


    public function liberer(): static
    {
        $this->statut =
            self::STATUT_LIBEREE;

        $this->dateLiberation =
            new \DateTimeImmutable();

        return $this;
    }


    public function annuler(): static
    {
        $this->statut =
            self::STATUT_ANNULEE;

        $this->dateLiberation =
            new \DateTimeImmutable();

        return $this;
    }
    public function getStockReserve(
    Articles $article
): float {
    $resultat = $this->entityManager
        ->getRepository(StockReservation::class)
        ->createQueryBuilder('r')
        ->select('COALESCE(SUM(r.quantite), 0)')
        ->andWhere('r.article = :article')
        ->andWhere('r.statut = :statut')
        ->setParameter('article', $article)
        ->setParameter(
            'statut',
            StockReservation::STATUT_ACTIVE
        )
        ->getQuery()
        ->getSingleScalarResult();

    return max(0, (float) $resultat);
}


public function getStockDisponible(
    Articles $article
): float {
    return max(
        0,
        $this->getStockPhysique($article)
        - $this->getStockReserve($article)
    );
}
}