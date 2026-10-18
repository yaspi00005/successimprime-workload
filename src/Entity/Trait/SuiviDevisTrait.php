<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Suivi d'un devis (maquettes GC03, GC04, DT01, DT03, docs/devis.md) :
 * conditions proposées au client, versions envoyées, ouvertures par le
 * client, réponse (acceptation, refus et son motif, demande de
 * modification) et relances WhatsApp.
 *
 * - versions : instantané de chaque version envoyée (numéro, date, total,
 *   résumé des lignes, commentaire du client qui l'a fait naître). Pas de
 *   coût ni de marge : ce champ descend au site pour l'espace client.
 * - nombreVues, premiereVueLe, derniereVueLe, vuPar : ouvertures par le
 *   client (page du devis dans son espace ou lien WhatsApp). Elles naissent
 *   en ligne et remontent au local (CatalogueSynchro « remontees »).
 * - relances : relances WhatsApp (date, canal, automatique ou par qui).
 *   Restent au local.
 */
trait SuiviDevisTrait
{
    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    /** @var list<array{numero: int, le: string, total: int, resume: string, lignes: list<array{designation: string, quantite: float, total: int}>, commentaire: ?string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $versions = null;

    #[ORM\Column(options: ['default' => 15])]
    private int $validiteJours = 15;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValidite = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $delaiRealisation = null;

    #[ORM\Column(options: ['default' => 50])]
    private int $acomptePourcentage = 50;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $conditionSolde = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $conditionFichier = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $conditionGarantie = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $canauxEnvoi = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    /** Ce que le client voit de la personne qui a envoyé : « Moussa (commercial) » (Employes::getNomPublic). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $envoyePar = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreVues = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $premiereVueLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereVueLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $vuPar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reponseLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reponduPar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRefus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $demandeModification = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demandeModificationLe = null;

    /** @var list<array{le: string, canal: string, auto: bool, par: ?string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $relances = null;

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = max(1, $version);

        return $this;
    }

    /** @return list<array{numero: int, le: string, total: int, resume: string, lignes: list<array{designation: string, quantite: float, total: int}>, commentaire: ?string}> */
    public function getVersions(): array
    {
        $versions = $this->versions ?? [];
        usort($versions, static fn (array $a, array $b): int => ($b['numero'] ?? 0) <=> ($a['numero'] ?? 0));

        return $versions;
    }

    /** Remplace la version du même numéro, ou l'ajoute. */
    public function noterVersion(array $instantane): static
    {
        $versions = array_values(array_filter($this->versions ?? [], static fn (array $v): bool => ($v['numero'] ?? 0) !== $instantane['numero']));
        $versions[] = $instantane;
        $this->versions = $versions;

        return $this;
    }

    public function getVersionNumero(int $numero): ?array
    {
        foreach ($this->versions ?? [] as $v) {
            if (($v['numero'] ?? 0) === $numero) {
                return $v;
            }
        }

        return null;
    }

    public function getValiditeJours(): int
    {
        return $this->validiteJours;
    }

    public function setValiditeJours(?int $jours): static
    {
        $this->validiteJours = max(1, min(365, $jours ?? 15));

        return $this;
    }

    public function getDateValidite(): ?\DateTimeImmutable
    {
        return $this->dateValidite;
    }

    public function setDateValidite(?\DateTimeImmutable $date): static
    {
        $this->dateValidite = $date?->setTime(0, 0);

        return $this;
    }

    public function getDelaiRealisation(): ?string
    {
        return $this->delaiRealisation;
    }

    public function setDelaiRealisation(?string $delai): static
    {
        $this->delaiRealisation = self::ligneCourte($delai, 80);

        return $this;
    }

    public function getAcomptePourcentage(): int
    {
        // Client dispensé d'acompte (fiche client, direction) : rien d'avance, devis compris.
        if (true === $this->getClients()?->isSansAcompte()) {
            return 0;
        }

        return $this->acomptePourcentage;
    }

    public function setAcomptePourcentage(?int $pourcentage): static
    {
        $this->acomptePourcentage = max(0, min(100, $pourcentage ?? 50));

        return $this;
    }

    /** Acompte en F, arrondi au franc supérieur (comme à la caisse et en ligne). */
    public function getMontantAcompte(): int
    {
        $total = max(0, $this->getTotalTtc());

        return (int) min($total, ceil($total * $this->getAcomptePourcentage() / 100));
    }

    public function getConditionSolde(): ?string
    {
        return $this->conditionSolde;
    }

    public function setConditionSolde(?string $texte): static
    {
        $this->conditionSolde = self::ligneCourte($texte, 120);

        return $this;
    }

    public function getConditionFichier(): ?string
    {
        return $this->conditionFichier;
    }

    public function setConditionFichier(?string $texte): static
    {
        $this->conditionFichier = self::ligneCourte($texte, 120);

        return $this;
    }

    public function getConditionGarantie(): ?string
    {
        return $this->conditionGarantie;
    }

    public function setConditionGarantie(?string $texte): static
    {
        $this->conditionGarantie = self::ligneCourte($texte, 120);

        return $this;
    }

    /** @return list<string> */
    public function getCanauxEnvoi(): array
    {
        return $this->canauxEnvoi ?? [];
    }

    /** @param list<string> $canaux */
    public function setCanauxEnvoi(array $canaux): static
    {
        $this->canauxEnvoi = array_values(array_intersect(array_keys(self::CANAUX_LIBELLES), $canaux));

        return $this;
    }

    public function getEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function setEnvoyeLe(?\DateTimeImmutable $le): static
    {
        $this->envoyeLe = $le;

        return $this;
    }

    public function getEnvoyePar(): ?string
    {
        return $this->envoyePar;
    }

    public function setEnvoyePar(?string $nom): static
    {
        $this->envoyePar = self::ligneCourte($nom, 80);

        return $this;
    }

    public function getNombreVues(): int
    {
        return $this->nombreVues;
    }

    public function setNombreVues(int $nombre): static
    {
        $this->nombreVues = max(0, $nombre);

        return $this;
    }

    public function getPremiereVueLe(): ?\DateTimeImmutable
    {
        return $this->premiereVueLe;
    }

    public function setPremiereVueLe(?\DateTimeImmutable $le): static
    {
        $this->premiereVueLe = $le;

        return $this;
    }

    public function getDerniereVueLe(): ?\DateTimeImmutable
    {
        return $this->derniereVueLe;
    }

    public function setDerniereVueLe(?\DateTimeImmutable $le): static
    {
        $this->derniereVueLe = $le;

        return $this;
    }

    public function getVuPar(): ?string
    {
        return $this->vuPar;
    }

    public function setVuPar(?string $nom): static
    {
        $this->vuPar = self::ligneCourte($nom, 80);

        return $this;
    }

    public function getReponseLe(): ?\DateTimeImmutable
    {
        return $this->reponseLe;
    }

    public function setReponseLe(?\DateTimeImmutable $le): static
    {
        $this->reponseLe = $le;

        return $this;
    }

    public function getReponduPar(): ?string
    {
        return $this->reponduPar;
    }

    public function setReponduPar(?string $nom): static
    {
        $this->reponduPar = self::ligneCourte($nom, 80);

        return $this;
    }

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function setMotifRefus(?string $motif): static
    {
        $this->motifRefus = self::ligneCourte($motif, 255);

        return $this;
    }

    /** « prix », « délai »… : le motif court de la liste (colonne Suivi de la liste des devis). */
    public function getMotifRefusCourt(): ?string
    {
        if (null === $this->motifRefus) {
            return null;
        }
        $libelle = trim(explode(' · ', $this->motifRefus)[0]);
        $court = ['Le prix' => 'prix', 'Le délai' => 'délai', 'Fait ailleurs' => 'fait ailleurs', 'Projet abandonné' => 'abandonné', 'Autre raison' => 'autre'];

        return $court[$libelle] ?? mb_strtolower(mb_substr($libelle, 0, 30));
    }

    public function getDemandeModification(): ?string
    {
        return $this->demandeModification;
    }

    public function setDemandeModification(?string $texte): static
    {
        $texte = trim((string) $texte);
        $this->demandeModification = '' === $texte ? null : mb_substr($texte, 0, 1000);

        return $this;
    }

    public function getDemandeModificationLe(): ?\DateTimeImmutable
    {
        return $this->demandeModificationLe;
    }

    public function setDemandeModificationLe(?\DateTimeImmutable $le): static
    {
        $this->demandeModificationLe = $le;

        return $this;
    }

    /** Une demande de modification arrivée depuis l'envoi de la version en cours. */
    public function attendUneModification(): bool
    {
        return null !== $this->demandeModificationLe && (null === $this->envoyeLe || $this->demandeModificationLe >= $this->envoyeLe);
    }

    /** @return list<array{le: string, canal: string, auto: bool, par: ?string}> */
    public function getRelances(): array
    {
        return $this->relances ?? [];
    }

    public function noterRelance(\DateTimeImmutable $le, string $canal, bool $auto, ?string $par): static
    {
        $relances = $this->relances ?? [];
        $relances[] = ['le' => $le->format(\DATE_ATOM), 'canal' => $canal, 'auto' => $auto, 'par' => $par];
        $this->relances = \array_slice($relances, -30);

        return $this;
    }

    /** Dernière relance faite depuis l'envoi de la version en cours, ou null. */
    public function getDerniereRelanceDepuisEnvoi(): ?array
    {
        $derniere = null;
        foreach ($this->relances ?? [] as $r) {
            if (null === $this->envoyeLe || new \DateTimeImmutable($r['le']) >= $this->envoyeLe) {
                $derniere = $r;
            }
        }

        return $derniere;
    }

    private static function ligneCourte(?string $texte, int $max): ?string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', (string) $texte));

        return '' === $texte ? null : mb_substr($texte, 0, $max);
    }
}
