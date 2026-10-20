<?php

namespace App\Entity;

use App\Repository\OrdreProductionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrdreProductionRepository::class)]
#[ORM\Table(name: 'ordre_production')]
#[ORM\UniqueConstraint(
    name: 'uniq_ordre_controle',
    columns: ['controle_pre_presse_id']
)]
class OrdreProduction
{
    public const STATUT_A_PRODUIRE = 'a_produire';
    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_EN_PAUSE = 'en_pause';
    public const STATUT_TERMINE = 'termine';
    public const STATUT_ANNULE = 'annule';

    public const PRIORITE_BASSE = 'basse';
    public const PRIORITE_NORMALE = 'normale';
    public const PRIORITE_HAUTE = 'haute';
    public const PRIORITE_URGENTE = 'urgente';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $numero;

    /**
     * Numéro de l’étiquette physique affectée au travail.
     * Il est distinct du numéro de l’ordre de production.
     */
    #[ORM\Column(
        name: 'numero_etiquette',
        length: 50,
        unique: true,
        nullable: true
    )]
    private ?string $numeroEtiquette = null;

    #[ORM\Column(
        name: 'etiquette_affectee_le',
        nullable: true
    )]
    private ?\DateTimeImmutable $etiquetteAffecteeLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'etiquette_affectee_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $etiquetteAffecteePar = null;

    #[ORM\ManyToOne(targetEntity: CommandesDetails::class)]
    #[ORM\JoinColumn(
        name: 'commande_detail_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?CommandesDetails $commandeDetail = null;

    #[ORM\OneToOne(targetEntity: ControlePrePresse::class)]
    #[ORM\JoinColumn(
        name: 'controle_pre_presse_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?ControlePrePresse $controlePrePresse = null;

    /**
     * Fichiers validés transmis à la production.
     *
     * @var Collection<int, CommandeDetailFichier>
     */
    #[ORM\ManyToMany(targetEntity: CommandeDetailFichier::class)]
    #[ORM\JoinTable(name: 'ordre_production_fichier')]
    private Collection $fichiers;

    #[ORM\ManyToOne(targetEntity: Machines::class)]
    #[ORM\JoinColumn(
        name: 'machine_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Machines $machine = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_A_PRODUIRE;

    #[ORM\Column(length: 20)]
    private string $priorite = self::PRIORITE_NORMALE;

    /**
     * Quantité demandée dans l’ordre de production.
     */
    #[ORM\Column(nullable: true)]
    private ?int $quantite = null;

    /**
     * Quantité totale effectivement produite,
     * y compris les rebuts.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $quantiteProduite = 0;

    /**
     * Quantité non conforme ou perdue.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $quantiteRebut = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observationFin = null;

    /**
     * Utilisateur ayant créé l’ordre.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'cree_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $creePar = null;

    /**
     * Opérateur ayant démarré ou repris la production.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'operateur_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $operateur = null;

    /**
     * Utilisateur ayant terminé la production.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'termine_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $terminePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateLimite = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demarreLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $transmisLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $debutPrevuLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finPrevueLe = null;

    /**
     * Durée estimée en minutes.
     */
    #[ORM\Column(nullable: true)]
    private ?int $dureeEstimeeMinutes = null;

    /**
     * Total des pauses en minutes.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $dureePauseMinutes = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pauseDebuteLe = null;

    /*
     * Poste machiniste (maquettes OP01 à OP06, docs/poste-machiniste.md) :
     * le fichier vérifié avant l'impression, l'estimation par étape, les
     * étiquettes des colis et le passage « Prêt pour livraison ».
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fichierVerifieLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'fichier_verifie_par_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $fichierVerifiePar = null;

    /**
     * Contrôle avant impression coché par le machiniste : clé => vrai/faux.
     *
     * @var array<string, bool>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $controleMachiniste = null;

    /**
     * Temps estimé par le machiniste, étape par étape :
     * {etapes: [{cle, libelle, propose, estime}], raison, machine}.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $estimation = null;

    /**
     * « Refaire une pièce » au contrôle final (PD06) : pièces à imprimer de
     * nouveau ; null quand aucune reprise n'est en cours.
     */
    #[ORM\Column(name: 'pieces_a_refaire', nullable: true)]
    private ?int $piecesARefaire = null;

    /** Nombre de reprises : la sortie de stock d'une reprise porte PROD-xxxxxx-R<n>. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $reimpressions = 0;

    #[ORM\Column(nullable: true)]
    private ?int $nombreColis = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $etiquettesImprimeesLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pretLivraisonLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'pret_livraison_par_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $pretLivraisonPar = null;

    public function __construct()
    {
        $this->fichiers = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();

        $this->numero = sprintf(
            'OP-%s-%s',
            $this->creeLe->format('Ymd-His'),
            strtoupper(bin2hex(random_bytes(2)))
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getNumeroEtiquette(): ?string
    {
        return $this->numeroEtiquette;
    }

    public function getEtiquetteAffecteeLe(): ?\DateTimeImmutable
    {
        return $this->etiquetteAffecteeLe;
    }

    public function getEtiquetteAffecteePar(): ?User
    {
        return $this->etiquetteAffecteePar;
    }

    public function aUneEtiquette(): bool
    {
        return $this->numeroEtiquette !== null;
    }

    /**
     * Affecte ou remplace l’étiquette du travail.
     * L’unicité définitive du numéro est garantie par la base.
     */
    public function affecterEtiquette(
        string $numeroEtiquette,
        ?User $utilisateur = null
    ): static {
        $numeroEtiquette = strtoupper(trim($numeroEtiquette));

        if ($numeroEtiquette === '') {
            throw new \InvalidArgumentException(
                'Le numéro d’étiquette est obligatoire.'
            );
        }

        if (mb_strlen($numeroEtiquette) > 50) {
            throw new \InvalidArgumentException(
                'Le numéro d’étiquette ne peut pas dépasser 50 caractères.'
            );
        }

        if (!preg_match('/^[A-Z0-9._\/-]+$/', $numeroEtiquette)) {
            throw new \InvalidArgumentException(
                'Le numéro d’étiquette contient des caractères non autorisés.'
            );
        }

        $this->numeroEtiquette = $numeroEtiquette;
        $this->etiquetteAffecteeLe = new \DateTimeImmutable();
        $this->etiquetteAffecteePar = $utilisateur;

        return $this;
    }

    public function retirerEtiquette(): static
    {
        $this->numeroEtiquette = null;
        $this->etiquetteAffecteeLe = null;
        $this->etiquetteAffecteePar = null;

        return $this;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

    public function setCommandeDetail(
        CommandesDetails $commandeDetail
    ): static {
        $this->commandeDetail = $commandeDetail;

        if ($this->quantite === null) {
            $this->quantite = $commandeDetail->getQuantite();
        }

        return $this;
    }

    public function getControlePrePresse(): ?ControlePrePresse
    {
        return $this->controlePrePresse;
    }

    public function setControlePrePresse(
        ControlePrePresse $controlePrePresse
    ): static {
        $this->controlePrePresse = $controlePrePresse;

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

    public function getMachine(): ?Machines
    {
        return $this->machine;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    /**
     * @return list<string>
     */
    public static function getStatutsAutorises(): array
    {
        return [
            self::STATUT_A_PRODUIRE,
            self::STATUT_EN_COURS,
            self::STATUT_EN_PAUSE,
            self::STATUT_TERMINE,
            self::STATUT_ANNULE,
        ];
    }

    public function getPriorite(): string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): static
    {
        if (!in_array($priorite, self::getPrioritesAutorisees(), true)) {
            throw new \InvalidArgumentException(
                'Priorité de production invalide.'
            );
        }

        $this->priorite = $priorite;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function getPrioritesAutorisees(): array
    {
        return [
            self::PRIORITE_BASSE,
            self::PRIORITE_NORMALE,
            self::PRIORITE_HAUTE,
            self::PRIORITE_URGENTE,
        ];
    }

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }

    public function setQuantite(?int $quantite): static
    {
        if ($quantite !== null && $quantite < 1) {
            throw new \InvalidArgumentException(
                'La quantité demandée doit être supérieure à zéro.'
            );
        }

        $this->quantite = $quantite;

        return $this;
    }

    public function getQuantiteProduite(): int
    {
        return $this->quantiteProduite;
    }

    public function getQuantiteRebut(): int
    {
        return $this->quantiteRebut;
    }

    public function getQuantiteConforme(): int
    {
        return max(
            0,
            $this->quantiteProduite - $this->quantiteRebut
        );
    }

    public function getQuantiteRestante(): int
    {
        return max(
            0,
            ($this->quantite ?? 0) - $this->getQuantiteConforme()
        );
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function setInstructions(?string $instructions): static
    {
        $this->instructions = $this->nettoyerTexte($instructions);

        return $this;
    }

    public function getObservationFin(): ?string
    {
        return $this->observationFin;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getOperateur(): ?User
    {
        return $this->operateur;
    }

    public function getTerminePar(): ?User
    {
        return $this->terminePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDateLimite(): ?\DateTimeImmutable
    {
        return $this->dateLimite;
    }

    public function setDateLimite(
        ?\DateTimeImmutable $dateLimite
    ): static {
        $this->dateLimite = $dateLimite;

        return $this;
    }

    public function getDemarreLe(): ?\DateTimeImmutable
    {
        return $this->demarreLe;
    }

    public function getTermineLe(): ?\DateTimeImmutable
    {
        return $this->termineLe;
    }

    public function getTransmisLe(): ?\DateTimeImmutable
    {
        return $this->transmisLe;
    }

    public function getDebutPrevuLe(): ?\DateTimeImmutable
    {
        return $this->debutPrevuLe;
    }

    public function getFinPrevueLe(): ?\DateTimeImmutable
    {
        return $this->finPrevueLe;
    }

    public function getDureeEstimeeMinutes(): ?int
    {
        return $this->dureeEstimeeMinutes;
    }

    public function getDureePauseMinutes(): int
    {
        return $this->dureePauseMinutes;
    }

    public function getPauseDebuteLe(): ?\DateTimeImmutable
    {
        return $this->pauseDebuteLe;
    }

    public function transmettre(
        ?\DateTimeImmutable $date = null
    ): static {
        if ($this->transmisLe !== null) {
            throw new \LogicException(
                'Cet ordre a déjà été transmis à la production.'
            );
        }

        $this->transmisLe = $date ?? new \DateTimeImmutable();

        return $this;
    }

    public function planifier(
        ?\DateTimeImmutable $debutPrevu,
        ?int $dureeEstimeeMinutes
    ): static {
        if (
            $dureeEstimeeMinutes !== null
            && $dureeEstimeeMinutes < 1
        ) {
            throw new \InvalidArgumentException(
                'La durée estimée doit être supérieure à zéro.'
            );
        }

        $this->debutPrevuLe = $debutPrevu;
        $this->dureeEstimeeMinutes = $dureeEstimeeMinutes;

        if (
            $debutPrevu !== null
            && $dureeEstimeeMinutes !== null
        ) {
            $this->finPrevueLe = $debutPrevu->modify(
                sprintf('+%d minutes', $dureeEstimeeMinutes)
            );
        } else {
            $this->finPrevueLe = null;
        }

        return $this;
    }

    public function estADemarrer(): bool
    {
        return $this->statut === self::STATUT_A_PRODUIRE;
    }

    public function estEnCours(): bool
    {
        return $this->statut === self::STATUT_EN_COURS;
    }

    public function estEnPause(): bool
    {
        return $this->statut === self::STATUT_EN_PAUSE;
    }

    public function estTermine(): bool
    {
        return $this->statut === self::STATUT_TERMINE;
    }

    public function estAnnule(): bool
    {
        return $this->statut === self::STATUT_ANNULE;
    }

    public function demarrer(
        User $operateur,
        ?Machines $machine = null
    ): static {
        if (
            !in_array(
                $this->statut,
                [
                    self::STATUT_A_PRODUIRE,
                    self::STATUT_EN_PAUSE,
                ],
                true
            )
        ) {
            throw new \LogicException(
                'Cet ordre ne peut pas être démarré.'
            );
        }

        if ($this->controlePrePresse === null) {
            throw new \LogicException(
                'Le contrôle prépresse est obligatoire.'
            );
        }

        $maintenant = new \DateTimeImmutable();

        /*
     * Si la transmission n’a pas été enregistrée auparavant,
     * le premier démarrage devient aussi la date de transmission.
     */
        $this->transmisLe ??= $maintenant;

        /*
     * Lors d’une reprise, calcul de la durée de la pause (une pièce à
     * refaire compte le temps passé depuis la première fin comme une pause).
     */
        if (
            ($this->statut === self::STATUT_EN_PAUSE || $this->piecesARefaire !== null)
            && $this->pauseDebuteLe !== null
        ) {
            $pauseSecondes = max(
                0,
                $maintenant->getTimestamp()
                    - $this->pauseDebuteLe->getTimestamp()
            );

            $this->dureePauseMinutes += (int) ceil(
                $pauseSecondes / 60
            );

            $this->pauseDebuteLe = null;
        }

        $this->operateur = $operateur;
        $this->machine = $machine ?? $this->machine;
        $this->demarreLe ??= $maintenant;
        $this->statut = self::STATUT_EN_COURS;

        return $this;
    }

    public function mettreEnPause(): static
    {
        if (!$this->estEnCours()) {
            throw new \LogicException(
                'Seul un ordre en cours peut être mis en pause.'
            );
        }

        if ($this->pauseDebuteLe !== null) {
            throw new \LogicException(
                'Une pause est déjà en cours.'
            );
        }

        $this->pauseDebuteLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_EN_PAUSE;

        return $this;
    }

    public function terminer(
        User $utilisateur,
        int $quantiteProduite,
        int $quantiteRebut = 0,
        ?string $observation = null
    ): static {
        if (!$this->estEnCours()) {
            throw new \LogicException(
                'Seul un ordre en cours peut être terminé.'
            );
        }

        if ($quantiteProduite < 1) {
            throw new \InvalidArgumentException(
                'La quantité produite doit être supérieure à zéro.'
            );
        }

        if (
            $quantiteRebut < 0
            || $quantiteRebut > $quantiteProduite
        ) {
            throw new \InvalidArgumentException(
                'La quantité de rebut est incorrecte.'
            );
        }

        // Une pièce refaite s'ajoute à ce qui avait été produit la première fois.
        if ($this->piecesARefaire !== null) {
            $quantiteProduite += $this->quantiteProduite;
            $quantiteRebut += $this->quantiteRebut;
        }
        $quantiteConforme = $quantiteProduite - $quantiteRebut;

        if (
            $this->quantite !== null
            && $quantiteConforme < $this->quantite
        ) {
            throw new \LogicException(sprintf(
                'La quantité conforme est insuffisante : %d sur %d.',
                $quantiteConforme,
                $this->quantite
            ));
        }

        $this->quantiteProduite = $quantiteProduite;
        $this->quantiteRebut = $quantiteRebut;
        $this->piecesARefaire = null;
        $this->observationFin = $this->nettoyerTexte(
            $observation
        );
        $this->terminePar = $utilisateur;
        $this->termineLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_TERMINE;

        return $this;
    }

    public function annuler(?string $motif = null): static
    {
        if ($this->estTermine()) {
            throw new \LogicException(
                'Un ordre terminé ne peut pas être annulé.'
            );
        }

        if ($this->estAnnule()) {
            throw new \LogicException(
                'Cet ordre est déjà annulé.'
            );
        }

        $this->observationFin = $this->nettoyerTexte($motif);
        $this->statut = self::STATUT_ANNULE;

        return $this;
    }

    public function getFichierVerifieLe(): ?\DateTimeImmutable
    {
        return $this->fichierVerifieLe;
    }

    public function getFichierVerifiePar(): ?User
    {
        return $this->fichierVerifiePar;
    }

    public function estFichierVerifie(): bool
    {
        return $this->fichierVerifieLe !== null;
    }

    /** @return array<string, bool> */
    public function getControleMachiniste(): array
    {
        return $this->controleMachiniste ?? [];
    }

    /**
     * Le machiniste déclare le fichier conforme (OP02).
     *
     * @param array<string, bool> $controles
     */
    public function verifierFichier(User $par, array $controles, ?\DateTimeImmutable $le = null): static
    {
        if (!$this->estADemarrer()) {
            throw new \LogicException('Le fichier se vérifie avant le démarrage.');
        }
        $this->fichierVerifieLe = $le ?? new \DateTimeImmutable();
        $this->fichierVerifiePar = $par;
        $this->controleMachiniste = $controles;

        return $this;
    }

    /** Retour au prépresse (OP04) : le fichier corrigé sera vérifié à nouveau. */
    public function annulerVerification(): static
    {
        $this->fichierVerifieLe = null;
        $this->fichierVerifiePar = null;
        $this->controleMachiniste = null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getEstimation(): array
    {
        return $this->estimation ?? [];
    }

    /**
     * Temps estimé par le machiniste (OP03). La durée totale et le début
     * prévu alimentent planifier(), lus aussi par l'atelier (files, planning).
     *
     * @param list<array{cle: string, libelle: string, propose: ?int, estime: int}> $etapes
     */
    public function estimer(array $etapes, ?string $raison, \DateTimeImmutable $debutPrevu, ?Machines $machine): static
    {
        if ($this->estTermine() || $this->estAnnule()) {
            throw new \LogicException('Un travail terminé ou annulé ne s\'estime plus.');
        }
        $total = array_sum(array_map(static fn (array $e): int => max(0, (int) $e['estime']), $etapes));
        if ($total < 1) {
            throw new \InvalidArgumentException('Indiquez le temps d\'au moins une étape.');
        }
        $raison = $this->nettoyerTexte($raison);
        $this->estimation = ['etapes' => array_values($etapes), 'raison' => $raison, 'propose' => array_sum(array_map(static fn (array $e): int => (int) ($e['propose'] ?? 0), $etapes))];
        if ($machine !== null) {
            $this->machine = $machine;
        }

        return $this->planifier($this->estEnCours() || $this->estEnPause() ? ($this->demarreLe ?? $debutPrevu) : $debutPrevu, $total);
    }

    public function getNombreColis(): ?int
    {
        return $this->nombreColis;
    }

    public function getEtiquettesImprimeesLe(): ?\DateTimeImmutable
    {
        return $this->etiquettesImprimeesLe;
    }

    /** Étiquettes des colis imprimées (OP05). */
    public function etiqueterColis(int $nombreColis, ?\DateTimeImmutable $le = null): static
    {
        if (!$this->estTermine()) {
            throw new \LogicException('Les étiquettes s\'impriment une fois le travail terminé.');
        }
        if ($nombreColis < 1 || $nombreColis > 99) {
            throw new \InvalidArgumentException('Le nombre de colis va de 1 à 99.');
        }
        $this->nombreColis = $nombreColis;
        $this->etiquettesImprimeesLe = $le ?? new \DateTimeImmutable();

        return $this;
    }

    public function getPiecesARefaire(): ?int
    {
        return $this->piecesARefaire;
    }

    public function getReimpressions(): int
    {
        return $this->reimpressions;
    }

    /**
     * « Refaire une pièce » (PD06) : le travail terminé repart en production
     * pour $pieces pièces, comptées en rebut. Il réapparaît « Prêt à
     * démarrer » dans Ma file (fichier déjà vérifié, temps déjà estimé).
     */
    public function refaire(int $pieces, ?\DateTimeImmutable $le = null): static
    {
        if (!$this->estTermine()) {
            throw new \LogicException('Seul un travail terminé peut être refait.');
        }
        if ($this->estPretLivraison()) {
            throw new \LogicException('Ce travail est déjà prêt pour la livraison.');
        }
        if ($pieces < 1 || $pieces > max(1, $this->getQuantiteConforme())) {
            throw new \InvalidArgumentException(sprintf('Le nombre de pièces à refaire va de 1 à %d.', max(1, $this->getQuantiteConforme())));
        }
        $this->piecesARefaire = $pieces;
        ++$this->reimpressions;
        $this->quantiteRebut += $pieces;
        $this->pauseDebuteLe = $this->termineLe ?? $le ?? new \DateTimeImmutable();
        $this->termineLe = null;
        $this->terminePar = null;
        $this->statut = self::STATUT_A_PRODUIRE;

        return $this;
    }

    /** Référence de la sortie de stock du travail : PROD-000123, puis PROD-000123-R1 pour une reprise. */
    public function referenceStock(): string
    {
        return self::referenceStockDe((int) $this->id, $this->piecesARefaire !== null ? $this->reimpressions : 0);
    }

    public static function referenceStockDe(int $id, int $reprise): string
    {
        return sprintf('PROD-%06d', $id).($reprise > 0 ? '-R'.$reprise : '');
    }

    public function getPretLivraisonLe(): ?\DateTimeImmutable
    {
        return $this->pretLivraisonLe;
    }

    public function getPretLivraisonPar(): ?User
    {
        return $this->pretLivraisonPar;
    }

    public function estPretLivraison(): bool
    {
        return $this->pretLivraisonLe !== null;
    }

    public function marquerPretLivraison(User $par, ?\DateTimeImmutable $le = null): static
    {
        if (!$this->estTermine()) {
            throw new \LogicException('Le travail doit être terminé.');
        }
        if ($this->etiquettesImprimeesLe === null) {
            throw new \LogicException('Imprimez d\'abord les étiquettes des colis.');
        }
        $this->pretLivraisonLe = $le ?? new \DateTimeImmutable();
        $this->pretLivraisonPar = $par;

        return $this;
    }

    private function nettoyerTexte(?string $texte): ?string
    {
        $texte = trim((string) $texte);

        return $texte !== '' ? $texte : null;
    }

    public function __toString(): string
    {
        return $this->numero;
    }
    public function getDureeAttenteMinutes(): ?int
    {
        if ($this->transmisLe === null || $this->demarreLe === null) {
            return null;
        }

        return max(
            0,
            (int) floor(
                ($this->demarreLe->getTimestamp()
                    - $this->transmisLe->getTimestamp()) / 60
            )
        );
    }

    public function getDureeReelleMinutes(): ?int
    {
        if ($this->demarreLe === null || $this->termineLe === null) {
            return null;
        }

        $dureeTotale = (int) floor(
            ($this->termineLe->getTimestamp()
                - $this->demarreLe->getTimestamp()) / 60
        );

        return max(0, $dureeTotale - $this->dureePauseMinutes);
    }

    public function getDureeTotaleTraitementMinutes(): ?int
    {
        if ($this->transmisLe === null || $this->termineLe === null) {
            return null;
        }

        return max(
            0,
            (int) floor(
                ($this->termineLe->getTimestamp()
                    - $this->transmisLe->getTimestamp()) / 60
            )
        );
    }

    public function getRetardMinutes(): int
    {
        if ($this->finPrevueLe === null) {
            return 0;
        }

        $dateComparaison = $this->termineLe
            ?? new \DateTimeImmutable();

        return max(
            0,
            (int) ceil(
                (
                    $dateComparaison->getTimestamp()
                    - $this->finPrevueLe->getTimestamp()
                ) / 60
            )
        );
    }

    public function getEcartDureeMinutes(): ?int
    {
        $dureeReelle = $this->getDureeReelleMinutes();

        if (
            $dureeReelle === null
            || $this->dureeEstimeeMinutes === null
        ) {
            return null;
        }

        return $dureeReelle - $this->dureeEstimeeMinutes;
    }

    public function getTauxRespectDelai(): ?float
    {
        if (
            $this->dureeEstimeeMinutes === null
            || $this->dureeEstimeeMinutes === 0
            || $this->getDureeReelleMinutes() === null
        ) {
            return null;
        }

        return round(
            (
                $this->getDureeReelleMinutes()
                / $this->dureeEstimeeMinutes
            ) * 100,
            2
        );
    }
    public function estEnRetard(): bool
    {
        if ($this->finPrevueLe === null) {
            return false;
        }

        $dateComparaison = $this->termineLe
            ?? new \DateTimeImmutable();

        return $dateComparaison > $this->finPrevueLe;
    }
}