<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une demande du client dans Messages (maquettes MS01 à MS05) : une
 * conversation par commande, par devis, ou une « question générale ».
 *
 * Le client l'ouvre en ligne (ou l'atelier au local), elle passe par la
 * synchronisation comme ses messages (docs/synchronisation.md) : créée en
 * ligne, elle monte ; l'atelier la tient au local et elle redescend. Ses
 * décisions prises en ligne (confirmer la clôture, rouvrir, noter, lire)
 * sont des « remontées » acceptées par le local.
 *
 * Restent au local (jamais envoyés) : la personne assignée, les collègues
 * ajoutés, le chronomètre (attente, escalade, relance) et la première
 * réponse (supervision des délais).
 */
#[ORM\Entity]
#[ORM\Table(name: 'conversation_client')]
#[ORM\Index(name: 'idx_conversation_client_statut', columns: ['client_id', 'statut'])]
#[ORM\Index(name: 'idx_conversation_client_attente', columns: ['attente_depuis'])]
class ConversationClient
{
    use PublicIdTrait;

    public const OUVERTE = 'ouverte';
    public const CLOTUREE = 'cloturee';

    /** Sujet : décide qui doit répondre (paiement et facture → trésorière). */
    public const SUJET_COMMANDE = 'commande';
    public const SUJET_PAIEMENT = 'paiement';
    public const SUJET_AUTRE = 'autre';

    public const SUJETS = [
        self::SUJET_COMMANDE => 'La commande (fichier, BAT, délai, livraison)',
        self::SUJET_PAIEMENT => 'Un paiement ou une facture',
        self::SUJET_AUTRE => 'Autre question',
    ];

    /** Objet d'une demande sans commande ni devis. */
    public const QUESTION_GENERALE = 'Question générale';

    public const OBJET_MAX = 120;

    /** Ouverte au local pour un message du formulaire Contact du site (maquette EC08) : jamais envoyée au site. */
    public const ORIGINE_SITE = 'site';

    /** Qui a clôturé quand la clôture est automatique (72 h sans réponse du client). */
    public const CLOTURE_AUTO = 'Clôture automatique';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    /** Demande clôturée depuis plus de 7 jours dont celle-ci est la suite (« nouvelle demande liée »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ConversationClient $precedente = null;

    #[ORM\Column(length: self::OBJET_MAX)]
    private string $objet = self::QUESTION_GENERALE;

    #[ORM\Column(length: 12, options: ['default' => self::SUJET_COMMANDE])]
    private string $sujet = self::SUJET_COMMANDE;

    #[ORM\Column(length: 12, options: ['default' => self::OUVERTE])]
    private string $statut = self::OUVERTE;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dernierMessageLe = null;

    /** L'agent propose de clôturer (« Oui, c'est résolu » / « J'ai encore une question »). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureProposeeLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clotureProposeePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureeLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $clotureePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rouverteLe = null;

    /** Avis du client à la clôture, de 1 à 5 étoiles. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $avis = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $avisLe = null;

    /** Dernière lecture par le client (en ligne, remonte) : « Lu ✓✓ » sous les messages de l'atelier. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $luClientLe = null;

    /** Dernière lecture par l'atelier (au local, descend) : « Lu ✓✓ » sous les messages du client. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $luAtelierLe = null;

    // ---- Au local seulement (ne part pas) ----

    /** Personne responsable de répondre : le retard lui est imputé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assigneA = null;

    /** Collègues ajoutés à la conversation (identifiants des comptes du personnel). @var list<int> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $participants = null;

    /** Premier message du client resté sans réponse : le chronomètre part de là. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attenteDepuis = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $premiereReponseLe = null;

    /** Direction prévenue (retard au-delà de l'escalade) pour l'attente en cours. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $escaladeeLe = null;

    /** Dernière relance de l'agent depuis la supervision. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $relanceeLe = null;

    /**
     * Au local : « site » pour une conversation ouverte à partir d'un message
     * du formulaire Contact. Elle reste au local (CatalogueSynchro, « pret ») :
     * le visiteur a pu taper le numéro d'un autre, le client ne doit jamais
     * la voir dans son espace.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $origine = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouvelle(Clients $client, ?Commandes $commande, ?Devis $devis, ?string $objet, string $sujet, \DateTimeImmutable $le): self
    {
        $c = new self();
        $c->client = $client;
        $c->commande = $commande;
        $c->devis = null === $commande ? $devis : null;
        $c->sujet = \array_key_exists($sujet, self::SUJETS) ? $sujet : self::SUJET_AUTRE;
        $objet = trim((string) preg_replace('/\s+/u', ' ', (string) $objet));
        $c->objet = mb_substr('' !== $objet ? $objet : self::QUESTION_GENERALE, 0, self::OBJET_MAX);
        $c->creeLe = $le;

        return $c;
    }

    public function getOrigine(): ?string
    {
        return $this->origine;
    }

    public function setOrigine(?string $origine): static
    {
        $this->origine = $origine;

        return $this;
    }

    public function estDuSite(): bool
    {
        return self::ORIGINE_SITE === $this->origine;
    }

    public function estOuverte(): bool
    {
        return self::OUVERTE === $this->statut;
    }

    public function estCloturee(): bool
    {
        return self::CLOTUREE === $this->statut;
    }

    public function clotureEnAttente(): bool
    {
        return $this->estOuverte() && null !== $this->clotureProposeeLe;
    }

    /** Rouvrable par le client pendant $jours après la clôture ; après, une nouvelle demande liée. */
    public function rouvrable(\DateTimeImmutable $maintenant, int $jours): bool
    {
        return $this->estCloturee() && null !== $this->clotureeLe && $this->clotureeLe->modify('+'.max(0, $jours).' days') > $maintenant;
    }

    /** Une conversation attend une réponse de l'atelier. */
    public function estEnAttente(): bool
    {
        return $this->estOuverte() && null !== $this->attenteDepuis;
    }

    public function proposerCloture(string $par, \DateTimeImmutable $le): static
    {
        $this->clotureProposeeLe = $le;
        $this->clotureProposeePar = mb_substr($par, 0, 80);

        return $this;
    }

    public function refuserCloture(): static
    {
        $this->clotureProposeeLe = null;
        $this->clotureProposeePar = null;

        return $this;
    }

    public function cloturer(string $par, \DateTimeImmutable $le): static
    {
        $this->statut = self::CLOTUREE;
        $this->clotureeLe = $le;
        $this->clotureePar = mb_substr($par, 0, 80);
        $this->clotureProposeeLe = null;
        $this->clotureProposeePar = null;
        $this->attenteDepuis = null;

        return $this;
    }

    public function rouvrir(\DateTimeImmutable $le): static
    {
        $this->statut = self::OUVERTE;
        $this->rouverteLe = $le;
        $this->clotureeLe = null;
        $this->clotureePar = null;

        return $this;
    }

    public function noter(int $etoiles, \DateTimeImmutable $le): static
    {
        $this->avis = max(1, min(5, $etoiles));
        $this->avisLe = $le;

        return $this;
    }

    /** @return list<int> */
    public function getParticipants(): array
    {
        return array_values(array_map('intval', $this->participants ?? []));
    }

    public function ajouterParticipant(User $u): bool
    {
        $ids = $this->getParticipants();
        if (null === $u->getId() || \in_array($u->getId(), $ids, true)) {
            return false;
        }
        $ids[] = $u->getId();
        $this->participants = $ids;

        return true;
    }

    public function aPourParticipant(User $u): bool
    {
        return null !== $u->getId() && \in_array($u->getId(), $this->getParticipants(), true);
    }

    /** « CM-0168 · Bâche … », « D-2026-0219 · Devis », « Question générale ». */
    public function getTitre(): string
    {
        if (null !== $this->commande) {
            $objet = trim((string) $this->commande->getObjet());

            return trim($this->commande->getNumero().('' !== $objet ? ' · '.$objet : ''));
        }
        if (null !== $this->devis) {
            return trim($this->devis->getNumero().' · Devis');
        }

        return $this->objet;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $c): static { $this->commande = $c; return $this; }
    public function getDevis(): ?Devis { return $this->devis; }
    public function getPrecedente(): ?self { return $this->precedente; }
    public function setPrecedente(?self $c): static { $this->precedente = $c; return $this; }
    public function getObjet(): string { return $this->objet; }
    public function getSujet(): string { return $this->sujet; }
    public function setSujet(string $s): static { $this->sujet = \array_key_exists($s, self::SUJETS) ? $s : self::SUJET_AUTRE; return $this; }
    public function getStatut(): string { return $this->statut; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getDernierMessageLe(): ?\DateTimeImmutable { return $this->dernierMessageLe; }
    public function setDernierMessageLe(?\DateTimeImmutable $d): static { $this->dernierMessageLe = $d; return $this; }
    public function getClotureProposeeLe(): ?\DateTimeImmutable { return $this->clotureProposeeLe; }
    public function getClotureProposeePar(): ?string { return $this->clotureProposeePar; }
    public function getClotureeLe(): ?\DateTimeImmutable { return $this->clotureeLe; }
    public function getClotureePar(): ?string { return $this->clotureePar; }
    public function getRouverteLe(): ?\DateTimeImmutable { return $this->rouverteLe; }
    public function getAvis(): ?int { return $this->avis; }
    public function getAvisLe(): ?\DateTimeImmutable { return $this->avisLe; }
    public function getLuClientLe(): ?\DateTimeImmutable { return $this->luClientLe; }
    public function setLuClientLe(?\DateTimeImmutable $d): static { $this->luClientLe = $d; return $this; }
    public function getLuAtelierLe(): ?\DateTimeImmutable { return $this->luAtelierLe; }
    public function setLuAtelierLe(?\DateTimeImmutable $d): static { $this->luAtelierLe = $d; return $this; }
    public function getAssigneA(): ?User { return $this->assigneA; }
    public function setAssigneA(?User $u): static { $this->assigneA = $u; return $this; }
    public function getAttenteDepuis(): ?\DateTimeImmutable { return $this->attenteDepuis; }
    public function setAttenteDepuis(?\DateTimeImmutable $d): static { $this->attenteDepuis = $d; if (null === $d) { $this->escaladeeLe = null; } return $this; }
    public function getPremiereReponseLe(): ?\DateTimeImmutable { return $this->premiereReponseLe; }
    public function setPremiereReponseLe(?\DateTimeImmutable $d): static { $this->premiereReponseLe = $d; return $this; }
    public function getEscaladeeLe(): ?\DateTimeImmutable { return $this->escaladeeLe; }
    public function setEscaladeeLe(?\DateTimeImmutable $d): static { $this->escaladeeLe = $d; return $this; }
    public function getRelanceeLe(): ?\DateTimeImmutable { return $this->relanceeLe; }
    public function setRelanceeLe(?\DateTimeImmutable $d): static { $this->relanceeLe = $d; return $this; }
}
