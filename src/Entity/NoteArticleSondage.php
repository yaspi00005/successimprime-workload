<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note donnée à un article d'une commande dans le sondage de commande.
 * Sert la satisfaction par produit, machine et agent. Voyage avec sa
 * réponse (synchronisation « enfants ») : pas d'identifiant public, et
 * chaque propriété a une valeur de départ (créée sans constructeur).
 */
#[ORM\Entity]
#[ORM\Table(name: 'note_article_sondage')]
class NoteArticleSondage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ReponseSondage $reponse = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandesDetails $ligne = null;

    /** Titre de l'article au moment de la réponse (« Bâche 300 × 100 × 2 »). */
    #[ORM\Column(length: 160)]
    private string $designation = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $note = 0;

    public static function pour(ReponseSondage $reponse, CommandesDetails $ligne, string $designation, int $note): self
    {
        $n = new self();
        $n->reponse = $reponse;
        $n->ligne = $ligne;
        $n->designation = mb_substr($designation, 0, 160);
        $n->note = max(1, min(5, $note));
        $reponse->ajouterNote($n);

        return $n;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReponse(): ?ReponseSondage
    {
        return $this->reponse;
    }

    public function getLigne(): ?CommandesDetails
    {
        return $this->ligne;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function getNote(): int
    {
        return $this->note;
    }
}
