<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de conception écrite par le client dans son espace (maquettes
 * DC01 à DC05, docs/demande-conception.md) : le support, ses textes, ses
 * éléments, puis un graphiste (acompte Orange Money) ou l'IA (crédit).
 *
 * Brouillon tenu en ligne entre les étapes ; elle monte au local une fois
 * envoyée (acompte reçu, ou visuel IA commandé). Le suivi de l'atelier
 * (prise en charge, terminée) redescend.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demande_conception')]
#[ORM\Index(name: 'idx_demande_conception_statut', columns: ['statut'])]
class DemandeConception
{
    use PublicIdTrait;

    public const GRAPHISTE = 'graphiste';
    public const IA = 'ia';

    public const BROUILLON = 'brouillon';
    /** Préparée par un demandeur : attend une personne qui paie pour l'entreprise. */
    public const A_PAYER = 'a_payer';
    /** Génération IA en cours (images, crédit) : rien n'est encore commandé. */
    public const EN_IA = 'en_ia';
    public const RECUE = 'recue';
    public const EN_COURS = 'en_cours';
    public const BAT_ENVOYE = 'bat_envoye';
    public const COMMANDEE = 'commandee';
    public const TERMINEE = 'terminee';
    public const ANNULEE = 'annulee';

    public const STATUTS = [
        self::BROUILLON => 'Brouillon',
        self::A_PAYER => 'Attend le paiement',
        self::EN_IA => 'Proposition IA',
        self::RECUE => 'Reçue',
        self::EN_COURS => 'En cours',
        self::BAT_ENVOYE => 'BAT envoyé',
        self::COMMANDEE => 'Impression commandée',
        self::TERMINEE => 'Terminée',
        self::ANNULEE => 'Annulée',
    ];

    /** Pas encore à l'atelier : se modifie et se supprime en ligne. */
    public const OUVERTES = [self::BROUILLON, self::A_PAYER, self::EN_IA];

    /** Le client peut en garder au plus autant en cours de préparation. */
    public const OUVERTES_MAX = 5;

    public const STYLES = [
        'elegant' => 'Élégant',
        'moderne' => 'Moderne',
        'festif' => 'Festif',
        'institutionnel' => 'Institutionnel',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DC-2026-014 ; W-DC-2026-014 quand elle est créée en ligne. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** Personne du compte qui l'a préparée (en ligne seulement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compte = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SupportConception $support = null;

    // Le support et ses prix au moment de la demande : un changement de tarif ne la touche plus.
    #[ORM\Column(length: 80)]
    private string $supportNom = '';

    #[ORM\Column(length: 60)]
    private string $supportFormat = '';

    #[ORM\Column(nullable: true)]
    private ?float $largeurCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $hauteurCm = null;

    #[ORM\Column]
    private int $forfait = 0;

    #[ORM\Column]
    private int $acomptePourcent = 50;

    #[ORM\Column]
    private int $correctionsIncluses = 2;

    #[ORM\Column]
    private int $delaiBatHeures = 24;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $titre = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sousTitre = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $dateHeure = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $coordonnees = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $consignes = null;

    /**
     * Logo, photos : fichiers du client (Mes fichiers).
     *
     * @var Collection<int, CommandeDetailFichier>
     */
    #[ORM\ManyToMany(targetEntity: CommandeDetailFichier::class)]
    #[ORM\JoinTable(name: 'demande_conception_element')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $elements;

    /** @var list<string> couleurs #RRGGBB */
    #[ORM\Column(type: Types::JSON)]
    private array $couleurs = [];

    /** @var list<string> clés de STYLES */
    #[ORM\Column(type: Types::JSON)]
    private array $styles = [];

    /** Quantité à imprimer ensuite. */
    #[ORM\Column(nullable: true)]
    private ?int $quantite = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pourQuand = null;

    /** Exemple aimé : lien https gardé comme texte, jamais ouvert par le serveur. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exempleLien = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $choix = null;

    #[ORM\Column(length: 12)]
    private string $statut = self::BROUILLON;

    /** Étape du parcours où le client s'est arrêté (en ligne seulement). */
    #[ORM\Column]
    private int $etape = 1;

    /** Visuel IA : finalisation par un graphiste demandée avec l'impression. */
    #[ORM\Column]
    private bool $finalisationGraphiste = false;

    /** Images IA générées pour cette demande. */
    #[ORM\Column]
    private int $nombreImages = 0;

    /** Commande de la conception (graphiste) ou de l'impression (IA). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Acompte payé par Orange Money, « à valider » par la caisse. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiementAcompte = null;

    /** Image IA choisie, devenue fichier du client sur la ligne d'impression. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $imageChoisie = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $priseEnChargeLe = null;

    /** Graphiste qui l'a prise (au local seulement). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $prisePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineeLe = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
        $this->elements = new ArrayCollection();
    }

    public static function nouvelle(Clients $client, ?CompteClient $compte, SupportConception $support, \DateTimeImmutable $le): self
    {
        $d = new self();
        $d->client = $client;
        $d->compte = $compte;
        $d->auteur = null !== $compte ? mb_substr($compte->getNomAffiche(), 0, 80) : null;
        $d->creeLe = $le;
        $d->modifieLe = $le;
        $d->choisirSupport($support);

        return $d;
    }

    /** Le support et ses prix du moment. */
    public function choisirSupport(SupportConception $support): void
    {
        $this->support = $support;
        $this->supportNom = $support->getCourt();
        $this->supportFormat = $support->getFormat();
        $this->largeurCm = $support->getLargeurCm();
        $this->hauteurCm = $support->getHauteurCm();
        $this->forfait = $support->getForfait();
        $this->acomptePourcent = $support->getAcomptePourcent();
        $this->correctionsIncluses = $support->getCorrectionsIncluses();
        $this->delaiBatHeures = $support->getDelaiBatHeures();
    }

    public function estOuverte(): bool
    {
        return \in_array($this->statut, self::OUVERTES, true);
    }

    /** Envoyée à l'atelier : la synchronisation peut la faire monter. */
    public function estEnvoyee(): bool
    {
        return !$this->estOuverte();
    }

    public function getId(): ?int { return $this->id; }
    public function getNumero(): ?string { return $this->numero; }
    public function setNumero(?string $numero): static { $this->numero = $numero; return $this; }
    public function getClient(): ?Clients { return $this->client; }
    public function getCompte(): ?CompteClient { return $this->compte; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getSupport(): ?SupportConception { return $this->support; }
    public function getSupportNom(): string { return $this->supportNom; }
    public function getSupportFormat(): string { return $this->supportFormat; }
    public function getLargeurCm(): ?float { return $this->largeurCm; }
    public function getHauteurCm(): ?float { return $this->hauteurCm; }
    public function getForfait(): int { return $this->forfait; }
    public function getAcomptePourcent(): int { return $this->acomptePourcent; }
    public function getCorrectionsIncluses(): int { return $this->correctionsIncluses; }
    public function getDelaiBatHeures(): int { return $this->delaiBatHeures; }
    public function getTitre(): ?string { return $this->titre; }
    public function getSousTitre(): ?string { return $this->sousTitre; }
    public function getDateHeure(): ?string { return $this->dateHeure; }
    public function getCoordonnees(): ?string { return $this->coordonnees; }
    public function getConsignes(): ?string { return $this->consignes; }

    /** Les textes du client, chacun coupé à sa longueur. */
    public function ecrireContenu(?string $titre, ?string $sousTitre, ?string $dateHeure, ?string $coordonnees, ?string $consignes): void
    {
        $this->titre = self::texte($titre, 120);
        $this->sousTitre = self::texte($sousTitre, 120);
        $this->dateHeure = self::texte($dateHeure, 120);
        $this->coordonnees = self::texte($coordonnees, 160);
        $this->consignes = self::texte($consignes, 1000);
    }

    /** @return Collection<int, CommandeDetailFichier> */
    public function getElements(): Collection { return $this->elements; }

    /** @param list<CommandeDetailFichier> $fichiers fichiers du client, déjà vérifiés */
    public function remplacerElements(array $fichiers): void
    {
        $this->elements->clear();
        foreach (\array_slice($fichiers, 0, 6) as $f) {
            if (!$this->elements->contains($f)) {
                $this->elements->add($f);
            }
        }
    }

    /** @return list<string> */
    public function getCouleurs(): array { return $this->couleurs; }

    /** @param list<string> $couleurs */
    public function setCouleurs(array $couleurs): static
    {
        $this->couleurs = \array_slice(array_values(array_unique(array_map('strtoupper', array_filter($couleurs, static fn ($c): bool => \is_string($c) && (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $c))))), 0, 6);

        return $this;
    }

    /** @return list<string> */
    public function getStyles(): array { return $this->styles; }

    /** @param list<string> $styles */
    public function setStyles(array $styles): static
    {
        $this->styles = array_values(array_intersect(array_keys(self::STYLES), $styles));

        return $this;
    }

    public function getQuantite(): ?int { return $this->quantite; }
    public function setQuantite(?int $quantite): static { $this->quantite = null !== $quantite && $quantite > 0 ? min($quantite, 100000) : null; return $this; }
    public function getPourQuand(): ?\DateTimeImmutable { return $this->pourQuand; }
    public function setPourQuand(?\DateTimeImmutable $date): static { $this->pourQuand = $date; return $this; }
    public function getExempleLien(): ?string { return $this->exempleLien; }

    /**
     * Lien d'exemple : https seulement, gardé tel quel (jamais ouvert ni
     * prévisualisé par le serveur).
     *
     * @throws \DomainException
     */
    public function setExempleLien(?string $lien): static
    {
        $lien = trim((string) $lien);
        if ('' === $lien) {
            $this->exempleLien = null;

            return $this;
        }
        if (!self::lienValide($lien)) {
            throw new \DomainException('L\'exemple doit être un lien qui commence par https://');
        }
        $this->exempleLien = $lien;

        return $this;
    }

    public static function lienValide(string $lien): bool
    {
        return \strlen($lien) <= 255 && str_starts_with($lien, 'https://') && false !== filter_var($lien, \FILTER_VALIDATE_URL)
            && '' !== (string) parse_url($lien, \PHP_URL_HOST) && null === parse_url($lien, \PHP_URL_USER);
    }

    public function getChoix(): ?string { return $this->choix; }
    public function setChoix(?string $choix): static { $this->choix = \in_array($choix, [self::GRAPHISTE, self::IA], true) ? $choix : null; return $this; }
    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = isset(self::STATUTS[$statut]) ? $statut : $this->statut; return $this; }
    public function getLibelleStatut(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function getEtape(): int { return $this->etape; }
    public function setEtape(int $etape): static { $this->etape = max(1, min(4, $etape)); return $this; }
    public function isFinalisationGraphiste(): bool { return $this->finalisationGraphiste; }
    public function setFinalisationGraphiste(bool $oui): static { $this->finalisationGraphiste = $oui; return $this; }
    public function getNombreImages(): int { return $this->nombreImages; }
    public function compterImage(): static { ++$this->nombreImages; return $this; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $commande): static { $this->commande = $commande; return $this; }
    public function getPaiementAcompte(): ?Paiements { return $this->paiementAcompte; }
    public function setPaiementAcompte(?Paiements $paiement): static { $this->paiementAcompte = $paiement; return $this; }
    public function getImageChoisie(): ?CommandeDetailFichier { return $this->imageChoisie; }
    public function setImageChoisie(?CommandeDetailFichier $fichier): static { $this->imageChoisie = $fichier; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function toucher(\DateTimeImmutable $le): static { $this->modifieLe = $le; return $this; }
    public function getEnvoyeeLe(): ?\DateTimeImmutable { return $this->envoyeeLe; }
    public function getPriseEnChargeLe(): ?\DateTimeImmutable { return $this->priseEnChargeLe; }
    public function getPrisePar(): ?string { return $this->prisePar; }
    public function getTermineeLe(): ?\DateTimeImmutable { return $this->termineeLe; }

    /** Partie à l'atelier : acompte reçu (ou à régler à l'imprimerie), ou impression du visuel IA commandée. */
    public function envoyer(string $statut, \DateTimeImmutable $le): void
    {
        $this->statut = $statut;
        $this->envoyeeLe ??= $le;
        $this->modifieLe = $le;
    }

    public function prendreEnCharge(string $par, \DateTimeImmutable $le): void
    {
        if (\in_array($this->statut, [self::RECUE, self::COMMANDEE], true)) {
            $this->statut = self::EN_COURS;
        }
        $this->priseEnChargeLe ??= $le;
        $this->prisePar = mb_substr($par, 0, 80);
        $this->modifieLe = $le;
    }

    public function terminer(string $statut, \DateTimeImmutable $le): void
    {
        $this->statut = self::ANNULEE === $statut ? self::ANNULEE : self::TERMINEE;
        $this->termineeLe = $le;
        $this->modifieLe = $le;
    }

    /** Statut lu dans le BAT de la commande (envoyé, validé) par l'atelier. */
    public function suivreBat(?string $statutBat, \DateTimeImmutable $le): bool
    {
        if (\in_array($this->statut, [self::TERMINEE, self::ANNULEE], true) || $this->estOuverte()) {
            return false;
        }
        $nouveau = match ($statutBat) {
            ControlePrePresse::STATUT_EN_ATTENTE_BAT => self::BAT_ENVOYE,
            ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => self::TERMINEE,
            default => null,
        };
        if (null === $nouveau || $nouveau === $this->statut) {
            return false;
        }
        $this->statut = $nouveau;
        $this->priseEnChargeLe ??= $le;
        if (self::TERMINEE === $nouveau) {
            $this->termineeLe = $le;
        }
        $this->modifieLe = $le;

        return true;
    }

    /** « Kakémono 85 × 200 cm » */
    public function titreSupport(): string
    {
        return trim($this->supportNom.(preg_match('/\d/', $this->supportFormat) ? ' '.$this->supportFormat : ''));
    }

    private static function texte(?string $texte, int $longueur): ?string
    {
        $texte = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $texte) ?? '');

        return '' === $texte ? null : mb_substr($texte, 0, $longueur);
    }
}
