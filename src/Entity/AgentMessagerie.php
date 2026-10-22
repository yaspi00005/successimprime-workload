<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que le client voit d'un employé qui lui écrit dans Messages (maquette
 * MS01) : son prénom et son nom, son métier (« Caisse et commercial »,
 * « Graphiste PAO ») et sa petite photo quand sa fiche en a une, sinon ses
 * initiales.
 *
 * Créée au local à la première réponse de l'employé, mise à jour quand sa
 * fiche change ; elle descend au site (docs/synchronisation.md). Jamais le
 * téléphone, ni le compte, ni la fiche : seulement ces trois champs et une
 * vignette carrée de 96 px réencodée sans métadonnées.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_messagerie')]
class AgentMessagerie
{
    use PublicIdTrait;

    /** Teintes du disque aux initiales (classes t0 à t8 de l'espace client). */
    public const TEINTES = 9;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Au local seulement. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $utilisateur = null;

    /** « Aminata Keïta » (le nom complet de la fiche, comme sur la maquette). */
    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $metier = null;

    /** Vignette JPEG 96 × 96 en base64, ou null (initiales). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column]
    private \DateTimeImmutable $majLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->majLe = new \DateTimeImmutable();
    }

    public static function pour(User $u): self
    {
        $a = new self();
        $a->utilisateur = $u;

        return $a;
    }

    /** Change seulement ce qui a changé (la synchronisation ne repart que sur un vrai changement). */
    public function mettreAJour(string $nom, ?string $metier, ?string $photo, \DateTimeImmutable $le): bool
    {
        $nom = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nom)), 0, 80);
        $metier = null !== $metier && '' !== trim($metier) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $metier)), 0, 80) : null;
        if ($nom === $this->nom && $metier === $this->metier && $photo === $this->photo) {
            return false;
        }
        [$this->nom, $this->metier, $this->photo, $this->majLe] = [$nom, $metier, $photo, $le];

        return true;
    }

    public function getInitiales(): string
    {
        $initiales = '';
        foreach (preg_split('/[\s\-]+/u', $this->nom, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            if (1 === preg_match('/^\p{L}/u', $mot) && mb_strlen($initiales) < 2) {
                $initiales .= mb_strtoupper(mb_substr($mot, 0, 1));
            }
        }

        return '' !== $initiales ? $initiales : '?';
    }

    public function getTeinte(): int
    {
        return crc32(mb_strtolower($this->nom)) % self::TEINTES;
    }

    public function getPhotoDataUri(): ?string
    {
        return null !== $this->photo ? 'data:image/jpeg;base64,'.$this->photo : null;
    }

    /** @return array{nom: string, metier: ?string, initiales: string, teinte: int, photo: ?string} */
    public function getSignature(): array
    {
        return ['nom' => $this->nom, 'metier' => $this->metier, 'initiales' => $this->getInitiales(), 'teinte' => $this->getTeinte(), 'photo' => $this->getPhotoDataUri()];
    }

    public function getId(): ?int { return $this->id; }
    public function getUtilisateur(): ?User { return $this->utilisateur; }
    public function getNom(): string { return $this->nom; }
    public function getMetier(): ?string { return $this->metier; }
    public function getPhoto(): ?string { return $this->photo; }
    public function getMajLe(): \DateTimeImmutable { return $this->majLe; }
}
