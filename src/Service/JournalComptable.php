<?php

namespace App\Service;

use App\Entity\Achats;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\DotationAmortissement;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Entity\SoldeOuverture;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Comptabilité SYSCOHADA (maquette 32) : les écritures sont déduites des
 * opérations validées (factures émises, mouvements de trésorerie
 * validés, achats reçus, dotations aux amortissements passées, soldes
 * d'ouverture importés). Rien n'est saisi ici : corriger une écriture,
 * c'est corriger l'opération d'origine.
 *
 * Les numéros de comptes suivent le plan SYSCOHADA révisé ; leur choix
 * fin (sous-comptes) reste à valider avec le cabinet comptable.
 */
final class JournalComptable
{
    public const JOURNAUX = [
        // Soldes d'ouverture repris par l'import des données de démarrage (docs/import-donnees.md).
        'an' => 'À-nouveaux',
        'ventes' => 'Journal des ventes',
        'banque' => 'Banque',
        'mobile' => 'Orange Money et Wave',
        'caisse' => 'Caisse',
        'achats' => 'Achats',
        'od' => 'Opérations diverses',
    ];

    public const CODES_JOURNAUX = [
        'an' => 'AN',
        'ventes' => 'VT',
        'banque' => 'BQ',
        'mobile' => 'MM',
        'caisse' => 'CA',
        'achats' => 'AC',
        'od' => 'OD',
    ];

    public const COMPTES = [
        '162' => 'Emprunts auprès des établissements de crédit',
        '2131' => 'Logiciels',
        '2341' => 'Installations techniques',
        '2411' => 'Matériel industriel',
        '2441' => 'Matériel de bureau',
        '2442' => 'Matériel informatique',
        '2444' => 'Mobilier de bureau',
        '2451' => 'Matériel automobile',
        '2813' => 'Amortissements des logiciels',
        '2834' => 'Amortissements des installations techniques',
        '2841' => 'Amortissements du matériel industriel',
        '2844' => 'Amortissements du matériel et mobilier',
        '2845' => 'Amortissements du matériel de transport',
        '401' => 'Fournisseurs',
        '411' => 'Clients',
        '4431' => 'TVA facturée sur ventes',
        '4452' => 'TVA récupérable sur achats',
        '471' => 'Compte d’attente',
        '521' => 'Banques',
        '5213' => 'Orange Money marchand',
        '5214' => 'Wave marchand',
        '571' => 'Caisse',
        '585' => 'Virements de fonds',
        '602' => 'Achats de matières premières et fournitures',
        '6042' => 'Matières combustibles',
        '6052' => 'Électricité',
        '612' => 'Transports sur achats et ventes',
        '6222' => 'Locations de bâtiments',
        '624' => 'Entretien, réparations et maintenance',
        '631' => 'Frais bancaires',
        '658' => 'Charges diverses',
        '661' => 'Rémunérations du personnel',
        '681' => 'Dotations aux amortissements d’exploitation',
        '702' => 'Ventes de produits finis',
        '758' => 'Produits divers',
    ];

    /** Compte de charge ou de produit de chaque catégorie de mouvement. */
    public const COMPTE_CATEGORIE = [
        MouvementTresorerie::CATEGORIE_VENTE => '702',
        MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT => '758',
        MouvementTresorerie::CATEGORIE_SALAIRE => '661',
        // Un achat payé règle le fournisseur : la charge vient de l'achat reçu.
        MouvementTresorerie::CATEGORIE_ACHAT => '401',
        MouvementTresorerie::CATEGORIE_CARBURANT => '6042',
        MouvementTresorerie::CATEGORIE_TRANSPORT => '612',
        MouvementTresorerie::CATEGORIE_ENTRETIEN => '624',
        MouvementTresorerie::CATEGORIE_ELECTRICITE => '6052',
        MouvementTresorerie::CATEGORIE_LOYER => '6222',
        MouvementTresorerie::CATEGORIE_AUTRE_CHARGE => '658',
        MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE => '585',
        MouvementTresorerie::CATEGORIE_AJUSTEMENT => '471',
        MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE => '631',
        MouvementTresorerie::CATEGORIE_REMBOURSEMENT_CREDIT => '162',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Toutes les écritures du mois, journal par journal, dans l'ordre des dates.
     *
     * @return array<string, list<array{date: \DateTimeImmutable, piece: string, compte: string, tiers: ?string, libelle: string, debit: int, credit: int, lettrage: ?string}>>
     */
    public function ecritures(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $journaux = array_fill_keys(array_keys(self::JOURNAUX), []);
        foreach ($this->factures($debut, $fin) as $facture) {
            array_push($journaux['ventes'], ...self::ecrituresFacture($facture));
        }
        foreach ($this->mouvements($debut, $fin) as $mouvement) {
            [$journal, $lignes] = self::ecrituresMouvement($mouvement);
            array_push($journaux[$journal], ...$lignes);
        }
        foreach ($this->achats($debut, $fin) as $achat) {
            array_push($journaux['achats'], ...self::ecrituresAchat($achat));
        }
        array_push($journaux['od'], ...self::ecrituresDotations($this->dotations($debut, $fin)));
        foreach ($this->soldesOuverture($debut, $fin) as $solde) {
            array_push($journaux['an'], ...self::ecrituresSoldeOuverture($solde));
        }
        foreach ($journaux as &$lignes) {
            // Tri stable : les lignes d'une même pièce restent ensemble.
            usort($lignes, static fn (array $a, array $b): int => [$a['date']->format('Y-m-d'), $a['piece']] <=> [$b['date']->format('Y-m-d'), $b['piece']]);
        }

        return $journaux;
    }

    /**
     * Balance : total débit, crédit et solde de chaque compte.
     *
     * @param array<string, list<array<string, mixed>>> $journaux
     *
     * @return list<array{compte: string, libelle: string, debit: int, credit: int, solde: int}>
     */
    public static function balance(array $journaux): array
    {
        $comptes = [];
        foreach ($journaux as $lignes) {
            foreach ($lignes as $l) {
                $comptes[$l['compte']] ??= ['compte' => $l['compte'], 'libelle' => self::COMPTES[$l['compte']] ?? $l['compte'], 'debit' => 0, 'credit' => 0, 'solde' => 0];
                $comptes[$l['compte']]['debit'] += $l['debit'];
                $comptes[$l['compte']]['credit'] += $l['credit'];
            }
        }
        ksort($comptes, \SORT_STRING);
        foreach ($comptes as &$c) {
            $c['solde'] = $c['debit'] - $c['credit'];
        }

        return array_values($comptes);
    }

    /**
     * Facture émise : client au débit, ventes, majoration et TVA au crédit.
     *
     * @return list<array<string, mixed>>
     */
    public static function ecrituresFacture(Factures $facture): array
    {
        $date = \DateTimeImmutable::createFromInterface($facture->getDateFacture() ?? new \DateTimeImmutable());
        $piece = $facture->getNumero() ?? 'FAC-'.$facture->getId();
        $commande = $facture->getCommande();
        $tiers = $facture->getNomFacturation() ?: $commande?->getClients()?->getNomComplet();
        $ventes = max(0, $facture->getTotalHt() - $facture->getRemise());
        $majoration = $facture->isSurfacturation() ? $facture->getMontantSurfacturation() : 0;
        $lettrage = self::lettrage($commande);

        $lignes = [self::ligne($date, $piece, '411', $tiers, 'Facture '.$piece, $facture->getTotalTtc(), 0, $lettrage)];
        $lignes[] = self::ligne($date, $piece, '702', null, 'Ventes '.($commande?->getNumero() ?? $piece), 0, $ventes);
        if ($majoration > 0) {
            $lignes[] = self::ligne($date, $piece, '702', null, 'Majoration '.$piece, 0, $majoration);
        }
        if ($facture->getMontantTva() > 0) {
            $lignes[] = self::ligne($date, $piece, '4431', null, sprintf('TVA %s %% %s', $facture->getTva(), $piece), 0, $facture->getMontantTva());
        }

        return array_values(array_filter($lignes, static fn (array $l): bool => $l['debit'] > 0 || $l['credit'] > 0));
    }

    /**
     * Mouvement validé : le compte de trésorerie contre le client (si
     * c'est un paiement), le compte de charge ou de produit, ou l'autre
     * compte de trésorerie pour un transfert.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    public static function ecrituresMouvement(MouvementTresorerie $mouvement): array
    {
        $date = \DateTimeImmutable::createFromInterface($mouvement->getDateOperation() ?? new \DateTimeImmutable());
        $piece = $mouvement->getReference();
        $montant = (int) $mouvement->getMontant();
        $libelle = $mouvement->getLibelle();
        $source = $mouvement->getCompteSource();
        $destination = $mouvement->getCompteDestination();

        if (MouvementTresorerie::TYPE_TRANSFERT === $mouvement->getType()) {
            return [self::journalDe($source), [
                self::ligne($date, $piece, self::compteTresorerie($destination), $destination?->getNom(), $libelle, $montant, 0),
                self::ligne($date, $piece, self::compteTresorerie($source), $source?->getNom(), $libelle, 0, $montant),
            ]];
        }

        $encaissement = MouvementTresorerie::TYPE_ENCAISSEMENT === $mouvement->getType();
        $tresorerie = $encaissement ? $destination : $source;
        $paiement = $mouvement->getPaiement();
        $commande = $paiement?->getCommande();
        if (null !== $paiement) {
            $contrepartie = '411';
            $tiers = $commande?->getClients()?->getNomComplet();
            $lettrage = self::lettrage($commande);
        } else {
            $contrepartie = self::COMPTE_CATEGORIE[$mouvement->getCategorie()] ?? ($encaissement ? '758' : '658');
            $tiers = null;
            $lettrage = null;
        }
        $journal = MouvementTresorerie::CATEGORIE_AJUSTEMENT === $mouvement->getCategorie() ? 'od' : self::journalDe($tresorerie);
        $ligneTresorerie = self::ligne($date, $piece, self::compteTresorerie($tresorerie), $tresorerie?->getNom(), $libelle, $encaissement ? $montant : 0, $encaissement ? 0 : $montant);
        $ligneContrepartie = self::ligne($date, $piece, $contrepartie, $tiers, $libelle, $encaissement ? 0 : $montant, $encaissement ? $montant : 0, $lettrage);

        return [$journal, $encaissement ? [$ligneTresorerie, $ligneContrepartie] : [$ligneContrepartie, $ligneTresorerie]];
    }

    /**
     * Achat reçu : matières et TVA récupérable au débit, fournisseur au crédit.
     *
     * @return list<array<string, mixed>>
     */
    public static function ecrituresAchat(Achats $achat): array
    {
        $date = $achat->getDateReception() ?? $achat->getDateDemande() ?? new \DateTimeImmutable();
        $piece = $achat->getNumero() ?? 'ACH-'.$achat->getId();
        $fournisseur = $achat->getFournisseur()?->getNom();
        $tva = max(0, $achat->getTotalTtc() - $achat->getTotalHt());

        return array_values(array_filter([
            self::ligne($date, $piece, '602', null, 'Achat '.$piece, $achat->getTotalHt(), 0),
            self::ligne($date, $piece, '4452', null, 'TVA '.$piece, $tva, 0),
            self::ligne($date, $piece, '401', $fournisseur, 'Facture fournisseur '.$piece, 0, $achat->getTotalTtc()),
        ], static fn (array $l): bool => $l['debit'] > 0 || $l['credit'] > 0));
    }

    /**
     * Dotations aux amortissements (M04) : pour chaque mois, une pièce
     * DOT-AAAA-MM au dernier jour du mois, avec par compte d'amortissement
     * le 681 au débit et le 28x de la catégorie au crédit.
     *
     * @param list<DotationAmortissement> $dotations
     *
     * @return list<array<string, mixed>>
     */
    public static function ecrituresDotations(array $dotations): array
    {
        $parPiece = [];
        foreach ($dotations as $d) {
            if ($d->getMontant() <= 0) {
                continue;
            }
            $parPiece[$d->piece()]['date'] = $d->getMois()->modify('last day of this month');
            $parPiece[$d->piece()]['comptes'][$d->getCompte()] = ($parPiece[$d->piece()]['comptes'][$d->getCompte()] ?? 0) + $d->getMontant();
        }
        $lignes = [];
        foreach ($parPiece as $piece => ['date' => $date, 'comptes' => $comptes]) {
            ksort($comptes, \SORT_STRING);
            foreach ($comptes as $compte => $montant) {
                $libelle = 'Dotation '.$date->format('m/Y').' · '.(self::COMPTES[$compte] ?? $compte);
                $lignes[] = self::ligne($date, $piece, '681', null, $libelle, $montant, 0);
                $lignes[] = self::ligne($date, $piece, (string) $compte, null, $libelle, 0, $montant);
            }
        }

        return $lignes;
    }

    /**
     * À-nouveau d'un solde d'ouverture importé, le jour d'ouverture, avec
     * le compte d'attente 471 en contrepartie : le cabinet le solde contre
     * les capitaux propres au bilan d'ouverture.
     *
     * - client qui doit : 411 au débit (lettré avec ses règlements) ;
     * - avance d'un client : 411 au crédit ;
     * - compte de trésorerie : 571, 521, 5213 ou 5214 au débit ;
     * - dette fournisseur : 401 au crédit (soldé au paiement de la dépense).
     *
     * @return list<array<string, mixed>>
     */
    public static function ecrituresSoldeOuverture(SoldeOuverture $solde): array
    {
        $montant = $solde->getMontant();
        if ($montant <= 0 || $solde->estAnnule()) {
            return [];
        }
        $date = $solde->getDateOuverture();
        $piece = sprintf('AN-%06d', (int) $solde->getId());
        $tiers = $solde->getTiers();
        $libelle = 'Solde d\'ouverture · '.$tiers;

        [$debit, $credit] = match ($solde->getType()) {
            SoldeOuverture::CLIENT => [['411', $tiers, self::lettrage($solde->getCommande())], ['471', null, null]],
            SoldeOuverture::AVANCE_CLIENT => [['471', null, null], ['411', $tiers, null]],
            SoldeOuverture::COMPTE => [[self::compteTresorerie($solde->getCompte()), $tiers, null], ['471', null, null]],
            default => [['471', null, null], ['401', $tiers, null]],
        };

        return [
            self::ligne($date, $piece, $debit[0], $debit[1], $libelle, $montant, 0, $debit[2]),
            self::ligne($date, $piece, $credit[0], $credit[1], $libelle, 0, $montant, $credit[2]),
        ];
    }

    public static function compteTresorerie(?CompteTresorerie $compte): string
    {
        return match ($compte?->getType()) {
            CompteTresorerie::TYPE_BANQUE => '521',
            CompteTresorerie::TYPE_ORANGE_MONEY => '5213',
            CompteTresorerie::TYPE_WAVE => '5214',
            CompteTresorerie::TYPE_CAISSE => '571',
            default => '471',
        };
    }

    public static function journalDe(?CompteTresorerie $compte): string
    {
        return match ($compte?->getType()) {
            CompteTresorerie::TYPE_BANQUE => 'banque',
            CompteTresorerie::TYPE_ORANGE_MONEY, CompteTresorerie::TYPE_WAVE => 'mobile',
            CompteTresorerie::TYPE_CAISSE => 'caisse',
            default => 'od',
        };
    }

    /**
     * Lettre commune à la facture et aux règlements d'une commande soldée.
     */
    public static function lettrage(?Commandes $commande): ?string
    {
        if (null === $commande || null === $commande->getId() || (int) $commande->getTotalTtc() <= 0 || $commande->getTotalPaye() < (int) $commande->getTotalTtc()) {
            return null;
        }

        return strtoupper(base_convert((string) $commande->getId(), 10, 36));
    }

    /**
     * @return array{date: \DateTimeImmutable, piece: string, compte: string, tiers: ?string, libelle: string, debit: int, credit: int, lettrage: ?string}
     */
    private static function ligne(\DateTimeImmutable $date, string $piece, string $compte, ?string $tiers, string $libelle, int $debit, int $credit, ?string $lettrage = null): array
    {
        return ['date' => $date, 'piece' => $piece, 'compte' => $compte, 'tiers' => $tiers, 'libelle' => $libelle, 'debit' => $debit, 'credit' => $credit, 'lettrage' => $lettrage];
    }

    /** @return list<Factures> */
    private function factures(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('facture', 'commande', 'client')
            ->from(Factures::class, 'facture')
            ->leftJoin('facture.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('facture.typeDocument = :facture')
            ->andWhere('facture.comptabilisee = true')
            ->andWhere('facture.etat = :emise')
            ->andWhere('facture.dateFacture BETWEEN :debut AND :fin')
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /** @return list<MouvementTresorerie> */
    private function mouvements(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('m', 'source', 'destination', 'paiement', 'commande', 'client')
            ->from(MouvementTresorerie::class, 'm')
            ->leftJoin('m.compteSource', 'source')
            ->leftJoin('m.compteDestination', 'destination')
            ->leftJoin('m.paiement', 'paiement')
            ->leftJoin('paiement.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.dateOperation BETWEEN :debut AND :fin')
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /** @return list<DotationAmortissement> */
    private function dotations(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('d', 'i')
            ->from(DotationAmortissement::class, 'd')
            ->innerJoin('d.immobilisation', 'i')
            ->andWhere('d.mois BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut->modify('first day of this month')->setTime(0, 0))
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /** @return list<SoldeOuverture> */
    private function soldesOuverture(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(SoldeOuverture::class, 's')
            ->andWhere('s.annuleLe IS NULL')
            ->andWhere('s.montant > 0')
            ->andWhere('s.dateOuverture BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut->setTime(0, 0))
            ->setParameter('fin', $fin)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Achats> */
    private function achats(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('achat', 'fournisseur')
            ->from(Achats::class, 'achat')
            ->leftJoin('achat.fournisseur', 'fournisseur')
            ->andWhere('achat.statut = :recu')
            ->andWhere('achat.dateReception BETWEEN :debut AND :fin')
            ->setParameter('recu', Achats::STATUT_RECU)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }
}
