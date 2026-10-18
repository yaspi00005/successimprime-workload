<?php

namespace App\Entity;

use App\Repository\EmployesRepository;
use App\Service\Pointage\HoraireType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployesRepository::class)]
class Employes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    private ?string $prenom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(length: 50)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photos = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $CIN = null;

    #[ORM\Column(nullable: true)]
    private ?int $salaires = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateNaissances = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $matricules = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateEmbauches = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresses = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhotos(): ?string
    {
        return $this->photos;
    }

    public function setPhotos(string $photos): static
    {
        $this->photos = $photos;

        return $this;
    }

    public function getCIN(): ?string
    {
        return $this->CIN;
    }

    public function setCIN(string $CIN): static
    {
        $this->CIN = $CIN;

        return $this;
    }

    public function getSalaires(): ?int
    {
        return $this->salaires;
    }

    public function setSalaires(int $salaires): static
    {
        $this->salaires = $salaires;

        return $this;
    }

    public function getDateNaissances(): ?\DateTime
    {
        return $this->dateNaissances;
    }

    public function setDateNaissances(\DateTime $dateNaissances): static
    {
        $this->dateNaissances = $dateNaissances;

        return $this;
    }

    public function getMatricules(): ?string
    {
        return $this->matricules;
    }

    public function setMatricules(string $matricules): static
    {
        $this->matricules = $matricules;

        return $this;
    }

    public function getDateEmbauches(): ?\DateTime
    {
        return $this->dateEmbauches;
    }

    public function setDateEmbauches(\DateTime $dateEmbauches): static
    {
        $this->dateEmbauches = $dateEmbauches;

        return $this;
    }

    public function getAdresses(): ?string
    {
        return $this->adresses;
    }

    public function setAdresses(string $adresses): static
    {
        $this->adresses = $adresses;

        return $this;
    }

 

#[ORM\OneToOne(
    targetEntity: User::class,
    mappedBy: 'employe'
)]
private ?User $user = null;


public function getUser(): ?User
{
    return $this->user;
}

public function setUser(?User $user): static
{
    $this->user = $user;

    if ($user !== null && $user->getEmploye() !== $this) {
        $user->setEmploye($this);
    }

    return $this;
}



    /*
     * Dossier de l'employé (maquettes E01 à E03) : contrat, paie et
     * horaires. Le dossier existe même sans accès à l'application.
     */

    public const CONTRATS = ['cdi' => 'CDI', 'cdd' => 'CDD', 'essai' => 'Période d\'essai', 'stage' => 'Stage', 'journalier' => 'Journalier'];
    public const REMUNERATIONS = ['salaire' => 'Salaire', 'indemnite' => 'Indemnité', 'journalier' => 'Paie journalière'];
    public const PAIEMENTS = ['especes' => 'Espèces', 'orange_money' => 'Orange Money', 'virement' => 'Virement'];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $contrat = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finPrevue = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $typeRemuneration = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $modePaiement = null;

    /*
     * Horaires propres à l'employé (gardien de nuit, mi-temps…). Vides :
     * ceux de l'atelier (Paramètres › Horaires). Servent au retard et aux
     * heures sup du pointage. Fin avant le début : poste de nuit.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heureDebut = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heureFin = null;

    /** Jours travaillés, de 1 (lundi) à 7 (dimanche) : « 1,2,3,4,5,6 ». */
    #[ORM\Column(length: 13, nullable: true)]
    private ?string $joursTravail = null;

    /**
     * Horaire type du calcul des points (HoraireType : accueil, atelier).
     * Vide : celui du poste. Des horaires propres passent avant.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $horaireType = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $contactUrgence = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $responsable = null;

    public function getContrat(): ?string { return $this->contrat; }
    public function setContrat(?string $c): static { $this->contrat = isset(self::CONTRATS[(string) $c]) ? $c : null; return $this; }
    public function libelleContrat(): ?string { return $this->contrat !== null ? self::CONTRATS[$this->contrat] : null; }
    public function getFinPrevue(): ?\DateTimeImmutable { return $this->finPrevue; }
    public function setFinPrevue(?\DateTimeImmutable $d): static { $this->finPrevue = $d; return $this; }
    public function getTypeRemuneration(): ?string { return $this->typeRemuneration; }
    public function setTypeRemuneration(?string $t): static { $this->typeRemuneration = isset(self::REMUNERATIONS[(string) $t]) ? $t : null; return $this; }
    public function getModePaiement(): ?string { return $this->modePaiement; }
    public function setModePaiement(?string $m): static { $this->modePaiement = isset(self::PAIEMENTS[(string) $m]) ? $m : null; return $this; }
    public function libelleModePaiement(): ?string { return $this->modePaiement !== null ? self::PAIEMENTS[$this->modePaiement] : null; }
    public function getHeureDebut(): ?string { return $this->heureDebut; }
    public function getHeureFin(): ?string { return $this->heureFin; }
    public function getJoursTravail(): ?string { return $this->joursTravail; }
    public function getHoraireType(): ?string { return $this->horaireType; }
    public function setHoraireType(?string $type): static { $this->horaireType = isset(HoraireType::LIBELLES[(string) $type]) ? $type : null; return $this; }

    /** @param list<int>|null $jours */
    public function setHoraires(?string $debut, ?string $fin, ?array $jours): static
    {
        $heure = static fn (?string $h): ?string => $h !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : null;
        $this->heureDebut = $heure($debut);
        $this->heureFin = $this->heureDebut !== null ? $heure($fin) : null;
        $jours = array_values(array_unique(array_filter(array_map('intval', $jours ?? []), static fn (int $j): bool => $j >= 1 && $j <= 7)));
        sort($jours);
        $this->joursTravail = $jours !== [] ? implode(',', $jours) : null;

        return $this;
    }

    /** @return list<int>|null */
    public function listeJoursTravail(): ?array
    {
        return $this->joursTravail !== null ? array_map('intval', explode(',', $this->joursTravail)) : null;
    }

    /** « tous les jours · 19 h – 7 h · repos le mardi », « lun – sam · 8 h – 18 h ». */
    public function libelleHoraires(): ?string
    {
        if ($this->heureDebut === null && $this->joursTravail === null) {
            return null;
        }
        $noms = [1 => 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
        $longs = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $parties = [];
        $jours = $this->listeJoursTravail();
        if ($jours !== null) {
            $suivis = $jours === range($jours[0], $jours[\count($jours) - 1]);
            $parties[] = match (true) {
                \count($jours) === 7 => 'tous les jours',
                \count($jours) === 6 && !$suivis => 'tous les jours',
                $suivis && \count($jours) > 2 => $noms[$jours[0]].' – '.$noms[$jours[\count($jours) - 1]],
                default => implode(', ', array_map(static fn (int $j): string => $noms[$j], $jours)),
            };
        }
        if ($this->heureDebut !== null) {
            $h = static fn (string $x): string => (int) substr($x, 0, 2).' h'.(substr($x, 3) !== '00' ? ' '.substr($x, 3) : '');
            $parties[] = $h($this->heureDebut).($this->heureFin !== null ? ' – '.$h($this->heureFin) : '');
        }
        if ($jours !== null && \count($jours) === 6) {
            $repos = array_values(array_diff(range(1, 7), $jours))[0];
            if ($repos !== 7) {
                $parties[] = 'repos le '.$longs[$repos];
            }
        }

        return implode(' · ', $parties);
    }
    public function getContactUrgence(): ?string { return $this->contactUrgence; }
    public function setContactUrgence(?string $c): static { $this->contactUrgence = $c !== null && trim($c) !== '' ? mb_substr(trim($c), 0, 150) : null; return $this; }
    public function getResponsable(): ?User { return $this->responsable; }
    public function setResponsable(?User $u): static { $this->responsable = $u; return $this; }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '').' '.($this->nom ?? ''));
    }

    /**
     * Ce que le client voit de l'employé dans son espace (suivi d'une
     * commande, BAT, messages) : le prénom et le métier, « Seydou
     * (graphiste PAO) ». Jamais le nom de famille ni le téléphone.
     */
    public function getNomPublic(): ?string
    {
        return self::nomPublic($this->prenom, $this->fonction);
    }

    public static function nomPublic(?string $prenom, ?string $fonction): ?string
    {
        $prenom = trim((string) preg_replace('/\s+/u', ' ', (string) $prenom));
        if ('' === $prenom) {
            return null;
        }
        $fonction = trim((string) preg_replace('/\s+/u', ' ', (string) $fonction));
        // « Graphiste PAO » devient « graphiste PAO » : seule la première lettre change ; un sigle en tête (« DG », « RH ») reste tel quel.
        $fonction = '' !== $fonction && 1 !== preg_match('/^\p{Lu}{2,}(?!\p{Ll})/u', $fonction) ? mb_strtolower(mb_substr($fonction, 0, 1)).mb_substr($fonction, 1) : $fonction;

        return mb_substr('' !== $fonction ? \sprintf('%s (%s)', $prenom, $fonction) : $prenom, 0, 80);
    }
}
