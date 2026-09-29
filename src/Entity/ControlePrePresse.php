<?php

namespace App\Entity;

use App\Repository\ControlePrePresseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ControlePrePresseRepository::class)]
#[ORM\Table(name: 'controle_pre_presse')]
class ControlePrePresse
{
    public const STATUT_A_CONTROLER = 'a_controler';
    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_CORRECTION_REQUISE = 'correction_requise';
    public const STATUT_EN_ATTENTE_BAT = 'en_attente_bat';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_REJETE = 'rejete';
    public const STATUT_ENVOYE_PRODUCTION = 'envoye_production';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /*
     * Ligne de commande concernée.
     *
     * Exemple :
     * 1 000 cartes de visite recto-verso.
     */
    #[ORM\ManyToOne(inversedBy: 'controlesPrePresse')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CommandesDetails $commandeDetail = null;

    /*
     * Un contrôle peut concerner plusieurs fichiers :
     * recto + verso, devant + dos, etc.
     */
    #[ORM\ManyToMany(targetEntity: CommandeDetailFichier::class)]
    #[ORM\JoinTable(name: 'controle_pre_presse_fichier')]
    #[ORM\JoinColumn(
        name: 'controle_pre_presse_id',
        referencedColumnName: 'id',
        onDelete: 'CASCADE'
    )]
    #[ORM\InverseJoinColumn(
        name: 'fichier_id',
        referencedColumnName: 'id',
        onDelete: 'CASCADE'
    )]
    private Collection $fichiers;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_A_CONTROLER;

    /*
     * Contrôles techniques.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $formatConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dimensionsConformes = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $resolutionConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $profilCouleursConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $fondsPerdusConformes = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $margesSecuriteConformes = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $policesConformes = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $orthographeVerifiee = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $orientationConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $nombrePagesConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $rectoVersoConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $supportConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $quantiteConforme = false;

    /*
     * Vrai lorsqu’aucune correction supplémentaire
     * n’est nécessaire.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fichierDejaTraite = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $correctionNecessaire = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $batNecessaire = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $batValide = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anomalies = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionsEffectuees = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column(options: ['default' => true])]
private bool $prePresseNecessaire = true;

    /*
     * Agent ayant commencé ou effectué le contrôle.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $controlePar = null;

    /*
     * Agent ayant effectué les corrections.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traitePar = null;

    /*
     * Agent ayant donné la validation finale.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    /*
     * Agent ayant envoyé les fichiers en production.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $envoyeProductionPar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $controleLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeProductionLe = null;

    public function __construct()
    {
        $this->fichiers = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

    public function setCommandeDetail(
        ?CommandesDetails $commandeDetail
    ): static {
        $this->commandeDetail = $commandeDetail;

        return $this;
    }

    /**
     * @return Collection<int, CommandeDetailFichier>
     */
    public function getFichiers(): Collection
    {
        return $this->fichiers;
    }

    public function addFichier(
        CommandeDetailFichier $fichier
    ): static {
        if (
            $fichier->getCommandeDetail() !== null
            && $this->commandeDetail !== null
            && $fichier->getCommandeDetail() !== $this->commandeDetail
        ) {
            throw new \DomainException(
                'Le fichier ne fait pas partie de cette ligne de commande.'
            );
        }

        if (!$this->fichiers->contains($fichier)) {
            $this->fichiers->add($fichier);
        }

        return $this;
    }

    public function removeFichier(
        CommandeDetailFichier $fichier
    ): static {
        $this->fichiers->removeElement($fichier);

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $statutsAutorises = [
            self::STATUT_A_CONTROLER,
            self::STATUT_EN_COURS,
            self::STATUT_CORRECTION_REQUISE,
            self::STATUT_EN_ATTENTE_BAT,
            self::STATUT_VALIDE,
            self::STATUT_REJETE,
            self::STATUT_ENVOYE_PRODUCTION,
        ];

        if (!in_array($statut, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut prépresse invalide : %s.', $statut)
            );
        }

        $this->statut = $statut;

        return $this;
    }

    public function isFormatConforme(): bool
    {
        return $this->formatConforme;
    }

    public function setFormatConforme(bool $valeur): static
    {
        $this->formatConforme = $valeur;

        return $this;
    }

    public function isDimensionsConformes(): bool
    {
        return $this->dimensionsConformes;
    }

    public function setDimensionsConformes(bool $valeur): static
    {
        $this->dimensionsConformes = $valeur;

        return $this;
    }

    public function isResolutionConforme(): bool
    {
        return $this->resolutionConforme;
    }

    public function setResolutionConforme(bool $valeur): static
    {
        $this->resolutionConforme = $valeur;

        return $this;
    }

    public function isProfilCouleursConforme(): bool
    {
        return $this->profilCouleursConforme;
    }

    public function setProfilCouleursConforme(bool $valeur): static
    {
        $this->profilCouleursConforme = $valeur;

        return $this;
    }

    public function isFondsPerdusConformes(): bool
    {
        return $this->fondsPerdusConformes;
    }

    public function setFondsPerdusConformes(bool $valeur): static
    {
        $this->fondsPerdusConformes = $valeur;

        return $this;
    }

    public function isMargesSecuriteConformes(): bool
    {
        return $this->margesSecuriteConformes;
    }

    public function setMargesSecuriteConformes(bool $valeur): static
    {
        $this->margesSecuriteConformes = $valeur;

        return $this;
    }

    public function isPolicesConformes(): bool
    {
        return $this->policesConformes;
    }

    public function setPolicesConformes(bool $valeur): static
    {
        $this->policesConformes = $valeur;

        return $this;
    }

    public function isOrthographeVerifiee(): bool
    {
        return $this->orthographeVerifiee;
    }

    public function setOrthographeVerifiee(bool $valeur): static
    {
        $this->orthographeVerifiee = $valeur;

        return $this;
    }

    public function isOrientationConforme(): bool
    {
        return $this->orientationConforme;
    }

    public function setOrientationConforme(bool $valeur): static
    {
        $this->orientationConforme = $valeur;

        return $this;
    }

    public function isNombrePagesConforme(): bool
    {
        return $this->nombrePagesConforme;
    }

    public function setNombrePagesConforme(bool $valeur): static
    {
        $this->nombrePagesConforme = $valeur;

        return $this;
    }

    public function isRectoVersoConforme(): bool
    {
        return $this->rectoVersoConforme;
    }

    public function setRectoVersoConforme(bool $valeur): static
    {
        $this->rectoVersoConforme = $valeur;

        return $this;
    }

    public function isSupportConforme(): bool
    {
        return $this->supportConforme;
    }

    public function setSupportConforme(bool $valeur): static
    {
        $this->supportConforme = $valeur;

        return $this;
    }

    public function isQuantiteConforme(): bool
    {
        return $this->quantiteConforme;
    }

    public function setQuantiteConforme(bool $valeur): static
    {
        $this->quantiteConforme = $valeur;

        return $this;
    }

    public function isFichierDejaTraite(): bool
    {
        return $this->fichierDejaTraite;
    }

    public function setFichierDejaTraite(bool $valeur): static
    {
        $this->fichierDejaTraite = $valeur;

        if ($valeur) {
            $this->correctionNecessaire = false;
        }

        return $this;
    }

    public function isCorrectionNecessaire(): bool
    {
        return $this->correctionNecessaire;
    }

    public function setCorrectionNecessaire(bool $valeur): static
    {
        $this->correctionNecessaire = $valeur;

        if ($valeur) {
            $this->fichierDejaTraite = false;
            $this->statut = self::STATUT_CORRECTION_REQUISE;
        }

        return $this;
    }

    public function isBatNecessaire(): bool
    {
        return $this->batNecessaire;
    }

    public function setBatNecessaire(bool $valeur): static
    {
        $this->batNecessaire = $valeur;

        return $this;
    }

    public function isBatValide(): bool
    {
        return $this->batValide;
    }

    public function setBatValide(bool $valeur): static
    {
        if ($valeur && !$this->batNecessaire) {
            throw new \LogicException(
                'Le BAT ne peut pas être validé s’il n’est pas requis.'
            );
        }

        $this->batValide = $valeur;

        return $this;
    }

    public function getAnomalies(): ?string
    {
        return $this->anomalies;
    }

    public function setAnomalies(?string $anomalies): static
    {
        $this->anomalies = $this->nettoyerTexte($anomalies);

        return $this;
    }

    public function getCorrectionsEffectuees(): ?string
    {
        return $this->correctionsEffectuees;
    }

    public function setCorrectionsEffectuees(
        ?string $correctionsEffectuees
    ): static {
        $this->correctionsEffectuees = $this->nettoyerTexte(
            $correctionsEffectuees
        );

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $this->observation = $this->nettoyerTexte($observation);

        return $this;
    }

    public function getControlePar(): ?User
    {
        return $this->controlePar;
    }

    public function getTraitePar(): ?User
    {
        return $this->traitePar;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function getEnvoyeProductionPar(): ?User
    {
        return $this->envoyeProductionPar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getControleLe(): ?\DateTimeImmutable
    {
        return $this->controleLe;
    }

    public function getTraiteLe(): ?\DateTimeImmutable
    {
        return $this->traiteLe;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getEnvoyeProductionLe(): ?\DateTimeImmutable
    {
        return $this->envoyeProductionLe;
    }

    public function commencerControle(User $agent): static
    {
        if ($this->fichiers->isEmpty()) {
            throw new \LogicException(
                'Aucun fichier n’est associé à ce contrôle.'
            );
        }

        $this->controlePar = $agent;
        $this->controleLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_EN_COURS;

        return $this;
    }

    public function enregistrerTraitement(User $agent): static
    {
        $this->traitePar = $agent;
        $this->traiteLe = new \DateTimeImmutable();
        $this->correctionNecessaire = false;

        return $this;
    }

    public function valider(User $agent): static
    {
        if ($this->fichiers->isEmpty()) {
            throw new \LogicException(
                'Le contrôle ne contient aucun fichier.'
            );
        }

        if ($this->correctionNecessaire) {
            throw new \LogicException(
                'Les corrections demandées ne sont pas terminées.'
            );
        }

        if ($this->batNecessaire && !$this->batValide) {
            $this->statut = self::STATUT_EN_ATTENTE_BAT;

            throw new \LogicException(
                'Le BAT doit être validé avant la validation prépresse.'
            );
        }

        foreach ($this->fichiers as $fichier) {
            if (!$fichier->estUploadTermine()) {
                throw new \LogicException(sprintf(
                    'Le fichier « %s » n’est pas entièrement envoyé.',
                    $fichier->getNomOriginal()
                ));
            }

            $fichier->marquerCommePretImpression();
        }

        $this->validePar = $agent;
        $this->valideLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_VALIDE;

        return $this;
    }

    public function envoyerEnProduction(User $agent): static
    {
        if ($this->statut !== self::STATUT_VALIDE) {
            throw new \LogicException(
                'Le contrôle prépresse doit être validé avant la production.'
            );
        }

        foreach ($this->fichiers as $fichier) {
            if (!$fichier->estPretPourImpression()) {
                throw new \LogicException(sprintf(
                    'Le fichier « %s » n’est pas prêt pour impression.',
                    $fichier->getNomOriginal()
                ));
            }

            $fichier->setFichierProduction(true);
        }

        $this->envoyeProductionPar = $agent;
        $this->envoyeProductionLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_ENVOYE_PRODUCTION;

        return $this;
    }

    public function rejeter(
        User $agent,
        string $motif
    ): static {
        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif du rejet est obligatoire.'
            );
        }

        $this->controlePar = $agent;
        $this->controleLe = new \DateTimeImmutable();
        $this->anomalies = $motif;
        $this->statut = self::STATUT_REJETE;

        return $this;
    }

    private function nettoyerTexte(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur !== '' ? $valeur : null;
    }
    public function isPrePresseNecessaire(): bool
{
    return $this->prePresseNecessaire;
}

public function setPrePresseNecessaire(
    bool $prePresseNecessaire
): static {
    $this->prePresseNecessaire = $prePresseNecessaire;

    return $this;
}
}