<?php

namespace App\Entity;

use App\Repository\EntrepriseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entreprise émettrice des documents (maquette I03) : son en-tête, ses
 * mentions légales, ses couleurs et sa numérotation.
 *
 * Les factures et les devis la désignent par son code (colonne
 * `emetteur`) : drepa, mdg, mdg_success…
 */
#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\Table(name: 'entreprise')]
class Entreprise
{
    public const COULEURS_PAR_DEFAUT = ['#0F4590', '#DE1028', '#FDC82A', '#E95B35', '#E8398A'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $code = '';

    /*
     * Raison sociale affichée : « Madial Group SARL ».
     */
    #[ORM\Column(length: 150)]
    private string $raisonSociale = '';

    /*
     * Nom commercial mis en avant, s'il y en a un : « Succès Imprim ».
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nomCommercial = null;

    /*
     * « Nom commercial de Madial Group SARL », « Société mère »…
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $mention = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $activite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $siteWeb = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $rccm = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nif = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $banque = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $compteBancaire = null;

    /*
     * Numéro marchand Orange Money affiché sur la facture. Les clés de
     * l'API restent dans .env.local, jamais ici.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $marchandOrangeMoney = null;

    /*
     * Chemins sous public/ : logo de l'en-tête, petit logo du pied de
     * page, cachet et signature.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPied = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signature = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $couleurs = self::COULEURS_PAR_DEFAUT;

    /*
     * Format des numéros de facture, par exemple F-SI-{AAAA}-{0000}.
     * Vide : la numérotation actuelle (FAC-000123-10-2026) continue.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $formatNumero = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $compteur = 0;

    /*
     * Année du compteur : il repart à 1 chaque année si le format
     * contient l'année.
     */
    #[ORM\Column(nullable: true)]
    private ?int $compteurAnnee = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $qrSuivi = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $qrPaiement = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $afficherLogoPied = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $montantEnLettres = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtolower(trim($code));

        return $this;
    }

    public function getRaisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(string $raisonSociale): static
    {
        $this->raisonSociale = trim($raisonSociale);

        return $this;
    }

    public function getNomCommercial(): ?string
    {
        return $this->nomCommercial;
    }

    public function setNomCommercial(?string $nomCommercial): static
    {
        $this->nomCommercial = self::texte($nomCommercial);

        return $this;
    }

    /**
     * Le nom que voit le client : le nom commercial s'il y en a un.
     */
    public function getNomAffiche(): string
    {
        return $this->nomCommercial ?? $this->raisonSociale;
    }

    public function getLibelle(): string
    {
        return $this->nomCommercial !== null ? $this->nomCommercial.' ('.$this->raisonSociale.')' : $this->raisonSociale;
    }

    public function getMention(): ?string
    {
        return $this->mention;
    }

    public function setMention(?string $mention): static
    {
        $this->mention = self::texte($mention);

        return $this;
    }

    public function getActivite(): ?string
    {
        return $this->activite;
    }

    public function setActivite(?string $activite): static
    {
        $this->activite = self::texte($activite);

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = self::texte($adresse);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = self::texte($telephone);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = self::texte($email);

        return $this;
    }

    public function getSiteWeb(): ?string
    {
        return $this->siteWeb;
    }

    public function setSiteWeb(?string $siteWeb): static
    {
        $this->siteWeb = self::texte($siteWeb);

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = self::texte($rccm);

        return $this;
    }

    public function getNif(): ?string
    {
        return $this->nif;
    }

    public function setNif(?string $nif): static
    {
        $this->nif = self::texte($nif);

        return $this;
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(?string $banque): static
    {
        $this->banque = self::texte($banque);

        return $this;
    }

    public function getCompteBancaire(): ?string
    {
        return $this->compteBancaire;
    }

    public function setCompteBancaire(?string $compteBancaire): static
    {
        $this->compteBancaire = self::texte($compteBancaire);

        return $this;
    }

    public function getMarchandOrangeMoney(): ?string
    {
        return $this->marchandOrangeMoney;
    }

    public function setMarchandOrangeMoney(?string $marchandOrangeMoney): static
    {
        $this->marchandOrangeMoney = self::texte($marchandOrangeMoney);

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = self::texte($logo);

        return $this;
    }

    public function getLogoPied(): ?string
    {
        return $this->logoPied;
    }

    public function setLogoPied(?string $logoPied): static
    {
        $this->logoPied = self::texte($logoPied);

        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = self::texte($signature);

        return $this;
    }

    /**
     * @return list<string> 5 couleurs : principale, accent, puis les bandes
     */
    public function getCouleurs(): array
    {
        $couleurs = array_values(array_filter($this->couleurs, static fn ($c) => \is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)));

        return \count($couleurs) === 5 ? $couleurs : self::COULEURS_PAR_DEFAUT;
    }

    /**
     * @param list<string> $couleurs
     */
    public function setCouleurs(array $couleurs): static
    {
        $this->couleurs = array_values($couleurs);

        return $this;
    }

    public function getCouleurPrincipale(): string
    {
        return $this->getCouleurs()[0];
    }

    public function getCouleurAccent(): string
    {
        return $this->getCouleurs()[1];
    }

    public function getFormatNumero(): ?string
    {
        return $this->formatNumero;
    }

    public function setFormatNumero(?string $formatNumero): static
    {
        $this->formatNumero = self::texte($formatNumero);

        return $this;
    }

    public function getCompteur(): int
    {
        return $this->compteur;
    }

    public function getCompteurAnnee(): ?int
    {
        return $this->compteurAnnee;
    }

    public function avancerCompteur(int $compteur, ?int $annee): static
    {
        $this->compteur = $compteur;
        $this->compteurAnnee = $annee;

        return $this;
    }

    public function isQrSuivi(): bool
    {
        return $this->qrSuivi;
    }

    public function setQrSuivi(bool $qrSuivi): static
    {
        $this->qrSuivi = $qrSuivi;

        return $this;
    }

    public function isQrPaiement(): bool
    {
        return $this->qrPaiement;
    }

    public function setQrPaiement(bool $qrPaiement): static
    {
        $this->qrPaiement = $qrPaiement;

        return $this;
    }

    public function isAfficherLogoPied(): bool
    {
        return $this->afficherLogoPied;
    }

    public function setAfficherLogoPied(bool $afficherLogoPied): static
    {
        $this->afficherLogoPied = $afficherLogoPied;

        return $this;
    }

    public function isMontantEnLettres(): bool
    {
        return $this->montantEnLettres;
    }

    public function setMontantEnLettres(bool $montantEnLettres): static
    {
        $this->montantEnLettres = $montantEnLettres;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    /**
     * Ligne de mentions légales du pied de page.
     */
    public function getMentionsLegales(): string
    {
        return implode(' · ', array_filter([
            $this->raisonSociale,
            $this->adresse,
            $this->rccm !== null ? 'RCCM '.$this->rccm : null,
            $this->nif !== null ? 'NIF '.$this->nif : null,
            $this->banque !== null || $this->compteBancaire !== null ? trim(($this->banque ?? 'Banque').' '.($this->compteBancaire ?? '')) : null,
        ]));
    }

    private static function texte(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}
