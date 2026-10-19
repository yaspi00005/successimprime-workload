<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que le client voit d'une livraison (maquettes DB08 à DB13) : le code
 * de réception qu'il donne au livreur à la remise, la photo de remise, le
 * livreur et sa position pendant la tournée, et son avis. Le code protège
 * les deux côtés : il prouve que le bon client a reçu le colis.
 */
trait SuiviClientLivraisonTrait
{
    /** Essais de code avant de passer à la signature. */
    public const CODE_ESSAIS_MAX = 5;

    /** Étiquettes de l'avis (maquette DB11). */
    public const NOTE_TAGS = ['a_l_heure' => 'À l\'heure', 'livreur_poli' => 'Livreur poli', 'bien_emballe' => 'Bien emballé'];

    /** Délai pour signaler un problème après la remise. */
    public const HEURES_PROBLEME = 48;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $codeReception = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $codeVerifieLe = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $codeEssais = 0;

    /** Photo de remise réduite (JPEG en base64, quelques dizaines de Ko) : elle seule part en ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photoClient = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $photoClientLe = null;

    /** Prénom du livreur, et son téléphone pendant la tournée seulement. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $livreurNom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $livreurTelephone = null;

    #[ORM\Column(nullable: true)]
    private ?float $livreurLatitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $livreurLongitude = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $livreurPositionLe = null;

    /** Repère pour le livreur (« à côté de la pharmacie »). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $repere = null;

    /** Qui reçoit si la personne prévue est absente. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $absentNom = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $conformeLe = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $noteLivraison = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $noteTags = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $noteLe = null;

    /** Code à 4 chiffres, tiré une fois. */
    public function donnerCode(): static
    {
        $this->codeReception ??= str_pad((string) random_int(0, 9999), 4, '0', \STR_PAD_LEFT);

        return $this;
    }

    /**
     * Le livreur tape le code donné par le client. Après CODE_ESSAIS_MAX
     * essais faux, plus d'essai : la signature reste possible.
     */
    public function verifierCode(string $saisi, \DateTimeImmutable $le): bool
    {
        if (null !== $this->codeVerifieLe) {
            return true;
        }
        $saisi = preg_replace('/\D/', '', $saisi) ?? '';
        if (null === $this->codeReception || '' === $saisi || $this->codeEssais >= self::CODE_ESSAIS_MAX) {
            return false;
        }
        ++$this->codeEssais;
        if (!hash_equals($this->codeReception, $saisi)) {
            return false;
        }
        $this->codeVerifieLe = $le;

        return true;
    }

    public function essaisCodeRestants(): int
    {
        return max(0, self::CODE_ESSAIS_MAX - $this->codeEssais);
    }

    /** Livreur visible du client : prénom ; téléphone tant qu'il n'a pas remis. */
    public function noterLivreurSuivi(?string $nom, ?string $telephone): static
    {
        $this->livreurNom = null !== $nom && '' !== trim($nom) ? mb_substr(trim($nom), 0, 80) : null;
        $this->livreurTelephone = null !== $telephone && '' !== trim($telephone) ? mb_substr(preg_replace('/[^\d+]/', '', $telephone) ?? '', 0, 20) : null;

        return $this;
    }

    public function noterPositionLivreur(float $latitude, float $longitude, \DateTimeImmutable $le): static
    {
        if (abs($latitude) <= 90 && abs($longitude) <= 180 && (0.0 !== $latitude || 0.0 !== $longitude)) {
            $this->livreurLatitude = round($latitude, 5);
            $this->livreurLongitude = round($longitude, 5);
            $this->livreurPositionLe = $le;
        }

        return $this;
    }

    /** Remise faite : le téléphone et la position du livreur ne servent plus. */
    public function oublierLivreurEnRoute(): static
    {
        $this->livreurTelephone = null;
        $this->livreurLatitude = null;
        $this->livreurLongitude = null;
        $this->livreurPositionLe = null;

        return $this;
    }

    public function setPhotoClient(?string $base64, ?\DateTimeImmutable $le): static
    {
        $this->photoClient = $base64;
        $this->photoClientLe = null === $base64 ? null : $le;

        return $this;
    }

    public function confirmerConforme(\DateTimeImmutable $le): static
    {
        $this->conformeLe ??= $le;

        return $this;
    }

    /** @param list<string> $tags */
    public function noterLivraison(int $note, array $tags, \DateTimeImmutable $le): static
    {
        $this->noteLivraison = max(1, min(5, $note));
        $this->noteTags = array_values(array_intersect(array_keys(self::NOTE_TAGS), $tags));
        $this->noteLe = $le;

        return $this;
    }

    /** Le client peut encore signaler un problème (48 h après la remise). */
    public function problemePossible(\DateTimeImmutable $maintenant): bool
    {
        $livre = $this->getLivreLe();

        return null !== $livre && $maintenant < $livre->modify('+'.self::HEURES_PROBLEME.' hours');
    }

    public function getCodeReception(): ?string { return $this->codeReception; }
    public function getCodeVerifieLe(): ?\DateTimeImmutable { return $this->codeVerifieLe; }
    public function getCodeEssais(): int { return $this->codeEssais; }
    public function getPhotoClient(): ?string { return $this->photoClient; }
    public function getPhotoClientLe(): ?\DateTimeImmutable { return $this->photoClientLe; }
    public function getLivreurNom(): ?string { return $this->livreurNom; }
    public function getLivreurTelephone(): ?string { return $this->livreurTelephone; }
    public function getLivreurLatitude(): ?float { return $this->livreurLatitude; }
    public function getLivreurLongitude(): ?float { return $this->livreurLongitude; }
    public function getLivreurPositionLe(): ?\DateTimeImmutable { return $this->livreurPositionLe; }
    public function getRepere(): ?string { return $this->repere; }
    public function setRepere(?string $repere): static { $this->repere = null !== $repere && '' !== trim($repere) ? mb_substr(trim($repere), 0, 160) : null; return $this; }
    public function getAbsentNom(): ?string { return $this->absentNom; }
    public function setAbsentNom(?string $nom): static { $this->absentNom = null !== $nom && '' !== trim($nom) ? mb_substr(trim($nom), 0, 120) : null; return $this; }
    public function getConformeLe(): ?\DateTimeImmutable { return $this->conformeLe; }
    public function getNoteLivraison(): ?int { return $this->noteLivraison; }
    /** @return list<string> */
    public function getNoteTags(): array { return $this->noteTags ?? []; }
    public function getNoteLe(): ?\DateTimeImmutable { return $this->noteLe; }
}
