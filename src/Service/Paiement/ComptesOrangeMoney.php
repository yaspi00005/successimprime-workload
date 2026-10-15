<?php

namespace App\Service\Paiement;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\ParametresPaiement;
use App\Entity\User;
use App\Repository\ParametresPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Comptes Orange Money et frais prélevés par Orange (docs/finances.md,
 * explications de Yaya du 15/10/2026).
 *
 * Il y a deux sortes de comptes Orange Money : celui de l'API (« Orange
 * Money en ligne », paiements de l'espace client) et les numéros de la
 * boutique (caisse, transferts directs). Pour les deux, le client paie le
 * fonds de soutien et le frais de retrait en plus du montant : ils
 * arrivent sur le compte avec le paiement, dans un mouvement à part
 * (CATEGORIE_FRAIS_ORANGE, compte de passage : ni produit ni charge), et
 * Orange les débite quand l'argent sort du compte (retrait, chèque,
 * transfert vers la banque ou la caisse).
 *
 * « À prélever » : frais reçus sur le compte moins frais déjà débités ;
 * au moins ce qu'Orange débiterait pour vider le compte (argent entré
 * sans frais suivis : solde d'avant le 15/10, dépôt).
 * « Transférable » : le solde moins ce qui est à prélever.
 */
final class ComptesOrangeMoney
{
    public const NOM_EN_LIGNE = 'Orange Money en ligne';
    public const CODE_EN_LIGNE = 'OM-EN-LIGNE';

    /** Suffixes de référence des frais débités avec une sortie d'argent. */
    public const SUFFIXE_PASSAGE = '-FO';
    public const SUFFIXE_CHARGE = '-FC';

    /** @var array<int, int>|null compte => frais à prélever, lu une fois par requête */
    private ?array $aPrelever = null;

    private ?ParametresPaiement $parametres = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?ParametresPaiementRepository $parametresPaiement = null,
    ) {
    }

    /** Le compte des paiements en ligne s'il existe déjà (rien n'est créé). */
    public function trouverCompteEnLigne(): ?CompteTresorerie
    {
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['orangeMoneyEnLigne' => true], ['id' => 'ASC']);
        foreach ($comptes as $compte) {
            if ($compte->isActif()) {
                return $compte;
            }
        }

        return $comptes[0] ?? null;
    }

    /**
     * Compte qui reçoit les paiements Orange Money en ligne. S'il n'y en a
     * pas : un compte Orange Money déjà nommé « Orange Money en ligne » est
     * marqué, sinon il est créé. Jamais un numéro de la boutique.
     */
    public function compteEnLigne(): CompteTresorerie
    {
        $compte = $this->trouverCompteEnLigne();
        if (null !== $compte) {
            if (!$compte->isActif()) {
                throw new \LogicException(\sprintf('Le compte « %s » des paiements Orange Money en ligne est désactivé : réactivez-le, ou marquez un autre compte Orange Money sur sa fiche.', $compte->getNom()));
            }

            return $compte;
        }

        $depot = $this->em->getRepository(CompteTresorerie::class);
        $compte = $depot->findOneBy(['type' => CompteTresorerie::TYPE_ORANGE_MONEY, 'nom' => self::NOM_EN_LIGNE, 'actif' => true]);
        if (null === $compte) {
            $code = self::CODE_EN_LIGNE;
            for ($n = 2; null !== $depot->findOneBy(['code' => $code]); ++$n) {
                $code = self::CODE_EN_LIGNE.'-'.$n;
            }
            $compte = (new CompteTresorerie())
                ->setCode($code)
                ->setNom(self::NOM_EN_LIGNE)
                ->setType(CompteTresorerie::TYPE_ORANGE_MONEY)
                ->setPortee(CompteTresorerie::PORTEE_ADMIN) // sorties par la direction (chèque, transfert) : pas depuis la caisse
                ->setDescription('Paiements Orange Money de l\'espace client (API) : commandes, acomptes de conception, crédit IA. Orange y débite le fonds de soutien et le frais de retrait quand l\'argent sort du compte.');
            $this->em->persist($compte);
        }
        $compte->setOrangeMoneyEnLigne(true);
        $this->em->flush();

        return $compte;
    }

    /**
     * Garde la marque sur un seul compte : celui qu'on vient de marquer
     * (fiche du compte) la retire aux autres.
     */
    public function marquerSeul(CompteTresorerie $compte): void
    {
        if (!$compte->isOrangeMoneyEnLigne()) {
            return;
        }
        foreach ($this->em->getRepository(CompteTresorerie::class)->findBy(['orangeMoneyEnLigne' => true]) as $autre) {
            if ($autre !== $compte) {
                $autre->setOrangeMoneyEnLigne(false);
            }
        }
    }

    /**
     * Catégorie des frais payés par le client sur un paiement : de passage
     * sur un compte Orange Money (Orange les débitera), autre produit
     * ailleurs (Wave, carte : inchangé).
     */
    public static function categorieFrais(?CompteTresorerie $compte): string
    {
        return null !== $compte && $compte->estOrangeMoney()
            ? MouvementTresorerie::CATEGORIE_FRAIS_ORANGE
            : MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT;
    }

    /**
     * Frais encore à prélever par compte : frais reçus moins frais débités.
     *
     * @param array<int, int> $recus    compte => frais reçus avec les paiements
     * @param array<int, int> $preleves compte => frais déjà débités par Orange
     *
     * @return array<int, int>
     */
    public static function cumuler(array $recus, array $preleves): array
    {
        $aPrelever = [];
        foreach (array_unique([...array_keys($recus), ...array_keys($preleves)]) as $compte) {
            $aPrelever[$compte] = max(0, ($recus[$compte] ?? 0) - ($preleves[$compte] ?? 0));
        }

        return $aPrelever;
    }

    /**
     * Solde, part à prélever par Orange (jamais plus que le solde) et
     * part transférable.
     *
     * @return array{solde: int, a_prelever: int, transferable: int}
     */
    public static function etatDe(int $solde, int $aPrelever): array
    {
        $aPrelever = min(max(0, $aPrelever), max(0, $solde));

        return ['solde' => $solde, 'a_prelever' => $aPrelever, 'transferable' => max(0, $solde - $aPrelever)];
    }

    /**
     * Plus grand montant qui peut sortir d'un solde quand Orange débite en
     * plus le fonds de soutien et le frais de retrait sur ce montant.
     */
    public static function sortieMax(int $solde, ParametresPaiement $parametres): int
    {
        if ($solde <= 0) {
            return 0;
        }
        $taux = 1 + ($parametres->getTauxFondsSoutien() + $parametres->getTauxFraisRetrait()) / 100;
        $montant = max(0, (int) floor($solde / max(1.0, $taux)));
        // Arrondis des deux frais : on ajuste de quelques francs.
        while ($montant > 0 && FraisOrangeMoney::calculer($montant, $parametres)->total() > $solde) {
            --$montant;
        }
        while (FraisOrangeMoney::calculer($montant + 1, $parametres)->total() <= $solde) {
            ++$montant;
        }

        return $montant;
    }

    /**
     * À prélever sur un compte Orange Money : les frais payés par les
     * clients pas encore débités, et au moins ce qu'Orange débiterait pour
     * vider le compte (un solde entré sans frais suivis les paie aussi).
     */
    public static function aPreleverAuRetrait(int $solde, int $fraisSuivis, ParametresPaiement $parametres): int
    {
        return max(max(0, $fraisSuivis), $solde - self::sortieMax($solde, $parametres));
    }

    /** @return array<int, int> compte => frais à prélever (comptes validés seulement) */
    public function fraisAPrelever(): array
    {
        return $this->aPrelever ??= self::cumuler(
            $this->sommes(MouvementTresorerie::TYPE_ENCAISSEMENT, 'compteDestination'),
            $this->sommes(MouvementTresorerie::TYPE_DECAISSEMENT, 'compteSource'),
        );
    }

    /**
     * État d'un compte Orange Money (null pour les autres comptes). À
     * montrer seulement à qui voit les soldes (ROLE_TRESORERIE_VOIR).
     *
     * @return array{solde: int, a_prelever: int, transferable: int}|null
     */
    public function etat(CompteTresorerie $compte): ?array
    {
        if (!$compte->estOrangeMoney()) {
            return null;
        }

        $solde = $compte->getSoldeActuel();

        return self::etatDe($solde, self::aPreleverAuRetrait($solde, $this->fraisAPrelever()[(int) $compte->getId()] ?? 0, $this->parametres()));
    }

    /** Ce qu'Orange devrait débiter sur une sortie de ce montant (taux de Réglages › Paiements). */
    public function estimer(int $montant): int
    {
        return FraisOrangeMoney::calculer($montant, $this->parametres())->frais();
    }

    /** @return array{fonds: float, retrait: float} */
    public function taux(): array
    {
        $parametres = $this->parametres();

        return ['fonds' => $parametres->getTauxFondsSoutien(), 'retrait' => $parametres->getTauxFraisRetrait()];
    }

    /**
     * Mouvements des frais qu'Orange a débités quand l'argent est sorti du
     * compte Orange Money source de $sortie (transfert ou décaissement) :
     *
     * - jusqu'à ce qui reste à prélever, une sortie « Frais Orange Money à
     *   prélever » : le compte de passage revient à zéro ;
     * - au-delà (argent entré sans frais : frais décochés à la caisse,
     *   solde de départ, dépôt), une charge « Frais bancaires ».
     *
     * Référence : celle de la sortie suivie de -FO ou -FC, pour les
     * retrouver à l'annulation.
     *
     * @return list<MouvementTresorerie>
     */
    public static function mouvementsPrelevement(MouvementTresorerie $sortie, int $frais, int $aPrelever, ?User $agent): array
    {
        $compte = $sortie->getCompteSource();
        if ($frais <= 0 || null === $compte || !$compte->estOrangeMoney()) {
            return [];
        }

        $passage = min($frais, max(0, $aPrelever));
        $parts = [
            [$passage, MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, self::SUFFIXE_PASSAGE, 'Fonds de soutien et frais de retrait prélevés par Orange'],
            [$frais - $passage, MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE, self::SUFFIXE_CHARGE, 'Frais Orange Money au-delà des frais payés par les clients'],
        ];
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ');
        $mouvements = [];
        foreach ($parts as [$montant, $categorie, $suffixe, $libelle]) {
            if ($montant <= 0) {
                continue;
            }
            $mouvements[] = (new MouvementTresorerie())
                ->setReference(mb_substr($sortie->getReference(), 0, 50 - \strlen($suffixe)).$suffixe)
                ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
                ->setCategorie($categorie)
                ->setConfidentiel(false)
                ->setCompteSource($compte)
                ->setMontant($montant)
                ->setModePaiement($sortie->getModePaiement())
                ->setReferenceExterne($sortie->getReferenceExterne())
                ->setLibelle(mb_substr($libelle.' — '.$sortie->getLibelle(), 0, 255))
                ->setDescription(\sprintf(
                    'Débité par Orange sur la sortie de %s F de « %s » (%s%s) : %s F en tout.',
                    $f((int) $sortie->getMontant()),
                    $compte->getNom(),
                    $sortie->getReference(),
                    null !== $sortie->getReferenceExterne() && '' !== $sortie->getReferenceExterne() ? ', référence '.$sortie->getReferenceExterne() : '',
                    $f($frais),
                ))
                ->setAgent($agent)
                ->setDateOperation($sortie->getDateOperation() ?? new \DateTimeImmutable());
        }

        return $mouvements;
    }

    private function parametres(): ParametresPaiement
    {
        return $this->parametres ??= $this->parametresPaiement?->recuperer() ?? new ParametresPaiement();
    }

    /** @return array<int, int> */
    private function sommes(string $type, string $champ): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select(\sprintf('IDENTITY(m.%s) AS compte', $champ), 'SUM(m.montant) AS total')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.categorie = :frais')
            ->andWhere('m.type = :type')
            ->andWhere('m.statut = :valide')
            ->andWhere(\sprintf('m.%s IS NOT NULL', $champ))
            ->setParameter('frais', MouvementTresorerie::CATEGORIE_FRAIS_ORANGE)
            ->setParameter('type', $type)
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->groupBy('compte')
            ->getQuery()
            ->getArrayResult();

        $sommes = [];
        foreach ($lignes as $l) {
            $sommes[(int) $l['compte']] = (int) $l['total'];
        }

        return $sommes;
    }
}
