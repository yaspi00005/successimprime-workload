<?php

namespace App\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\DepotSoldeClient;
use App\Entity\Factures;
use App\Entity\ModificationClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use App\Repository\PaiementsRepository;
use App\Service\Import\Valeurs;
use App\Service\MouvementTresorerieService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compte du client à la boutique (docs/finances.md, « Compte du client ») :
 * le seul endroit qui fait monter ou baisser Clients::soldeCredit, en
 * écrivant chaque fois une ligne du relevé (MouvementSoldeClient).
 *
 * Ce qui entre :
 *  - la monnaie que le client laisse à la caisse (« Garder la monnaie sur
 *    le compte du client ») : l'argent reste dans la caisse ;
 *  - un dépôt à la boutique (espèces, Orange Money, Wave) ;
 *  - un dépôt Orange Money fait depuis l'espace client, une fois validé
 *    par la caisse (jamais tout seul) ;
 *  - un paiement par le solde annulé (l'argent revient au compte).
 * Ce qui sort :
 *  - le paiement d'une commande ou d'une facture avec le solde (à la
 *    caisse, ou en ligne validé à la boutique) ;
 *  - l'argent rendu au client (droit « Rendre l'argent d'un compte client »).
 *
 * L'argent entré ou sorti passe en trésorerie dans la catégorie « avance
 * client » (compte 4191) : ni vente ni charge. Le paiement d'une commande
 * avec le solde ne touche aucun compte de trésorerie (l'argent est déjà
 * là) : en comptabilité, il solde l'avance contre la créance du client.
 *
 * Le solde ne descend jamais sous zéro : chaque sortie verrouille la fiche
 * du client et relit son solde avant de décider.
 */
final class SoldeClient
{
    /**
     * Comptes où l'argent du compte client entre et d'où il sort : caisse,
     * Orange Money, Wave. Pas la banque : son mouvement attendrait la
     * validation alors que le solde du client bougerait tout de suite.
     */
    public const TYPES_COMPTES = [CompteTresorerie::TYPE_CAISSE, CompteTresorerie::TYPE_ORANGE_MONEY, CompteTresorerie::TYPE_WAVE];

    /**
     * Plus grand montant que gardent les colonnes de la base (entier) : au
     * delà, c'est une faute de frappe (un chiffre de trop), refusée avec un
     * message plutôt qu'une erreur de la base. Ce n'est pas un plafond voulu.
     */
    public const MONTANT_MAXIMUM = 2147483647;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementTresorerieService $tresorerie,
        private readonly ?ComptesOrangeMoney $comptesOrangeMoney = null,
    ) {
    }

    /**
     * Monnaie à garder : ce que le client a remis moins ce qu'il paie
     * (0 s'il n'a pas remis plus).
     */
    public static function monnaie(int $remis, int $montant): int
    {
        return max(0, $remis - max(0, $montant));
    }

    /**
     * Ce que le client peut encore utiliser : son solde moins l'avoir
     * qu'il a déjà engagé en ligne et que la boutique n'a pas validé.
     */
    public static function disponible(int $solde, int $avoirEnValidation): int
    {
        return max(0, max(0, $solde) - max(0, $avoirEnValidation));
    }

    public function disponiblePour(Clients $client): int
    {
        $paiements = $this->em->getRepository(Paiements::class);

        return self::disponible($client->getSoldeCredit(), $paiements instanceof PaiementsRepository ? $paiements->avoirEnValidation($client) : 0);
    }

    /**
     * Relevé du client, le plus récent d'abord.
     *
     * @return list<MouvementSoldeClient>
     */
    public function releve(Clients $client, int $limite = 100): array
    {
        return $this->em->getRepository(MouvementSoldeClient::class)->findBy(['client' => $client], ['creeLe' => 'DESC', 'id' => 'DESC'], $limite);
    }

    /**
     * Ce que montre l'onglet « Compte » de la fiche client et la caisse.
     *
     * @return array{solde: int, disponible: int, engage: int, releve: list<MouvementSoldeClient>, depots: list<DepotSoldeClient>}
     */
    public function etat(Clients $client, int $limite = 100): array
    {
        $disponible = $this->disponiblePour($client);

        return [
            'solde' => $client->getSoldeCredit(),
            'disponible' => $disponible,
            // Avoir déjà utilisé en ligne, que la boutique n'a pas encore validé.
            'engage' => max(0, $client->getSoldeCredit() - $disponible),
            'releve' => $this->releve($client, $limite),
            'depots' => $this->depotsEnAttente($client),
        ];
    }

    /**
     * Dépôts Orange Money de l'espace client reçus et pas encore traités.
     *
     * @return list<DepotSoldeClient>
     */
    public function depotsEnAttente(?Clients $client = null): array
    {
        return $this->em->getRepository(DepotSoldeClient::class)->findBy(array_filter(['statut' => DepotSoldeClient::RECU, 'client' => $client]), ['recuLe' => 'ASC']);
    }

    /**
     * Monnaie gardée : le client a remis plus que le montant payé en
     * espèces et laisse la différence sur son compte. L'argent est dans la
     * caisse du paiement : elle reçoit la monnaie en plus (« avance client »).
     *
     * @throws \DomainException message à montrer à la caissière
     */
    public function garderMonnaie(Paiements $paiement, int $monnaie, User $par): MouvementSoldeClient
    {
        $client = $paiement->getCommande()?->getClients();
        $compte = $paiement->getCompteTresorerie();
        self::verifierMonnaie($paiement, $monnaie);
        if (null === $client || null === $compte) {
            throw new \DomainException('La monnaie ne se garde que pour un client connu, sur la caisse du paiement.');
        }
        $recu = sprintf('R-%06d', (int) $paiement->getId());

        return $this->enTransaction(function () use ($paiement, $monnaie, $par, $client, $compte, $recu): MouvementSoldeClient {
            $mouvement = $this->entree($compte, $monnaie, Paiements::MODE_ESPECES, $recu, sprintf('Monnaie gardée — %s', $client->getNomComplet()), sprintf('Monnaie laissée par le client sur son compte (paiement %s de la commande %s).', $recu, $paiement->getCommande()?->getNumero()), $par);
            $ligne = $this->crediter($client, MouvementSoldeClient::MONNAIE, $monnaie, $par, Paiements::MODE_ESPECES, $recu)
                ->setPaiement($paiement)
                ->setCommande($paiement->getCommande())
                ->setMouvementTresorerie($mouvement);
            $this->em->flush();

            return $ligne;
        });
    }

    /**
     * Contrôles de la monnaie gardée, avant d'enregistrer le paiement.
     *
     * @throws \DomainException
     */
    public static function verifierMonnaie(Paiements $paiement, int $monnaie): void
    {
        if ($monnaie <= 0) {
            throw new \DomainException('Aucune monnaie à garder : le client n\'a pas remis plus que le montant.');
        }
        if (Paiements::MODE_ESPECES !== $paiement->getMode()) {
            throw new \DomainException('La monnaie ne se garde que sur un paiement en espèces.');
        }
        if (null === $paiement->getCommande()?->getClients()) {
            throw new \DomainException('La monnaie ne se garde que pour un client connu.');
        }
        if (null !== $paiement->getCompteTresorerie() && CompteTresorerie::TYPE_CAISSE !== $paiement->getCompteTresorerie()->getType()) {
            throw new \DomainException('La monnaie ne se garde que dans une caisse.');
        }
        self::verifierChiffres($monnaie, (int) $paiement->getCommande()?->getClients()?->getSoldeCredit());
    }

    /**
     * Un montant (ou le solde qu'il donne) trop grand pour la base : un
     * chiffre de trop au clavier.
     *
     * @throws \DomainException
     */
    private static function verifierChiffres(int $montant, int $soldeAvant): void
    {
        if ($montant > self::MONTANT_MAXIMUM || $soldeAvant + $montant > self::MONTANT_MAXIMUM) {
            throw new \DomainException('Montant trop grand : vérifiez le nombre de chiffres.');
        }
    }

    /**
     * Dépôt à la boutique : le client laisse de l'argent sur son compte
     * sans rien payer. Orange Money ou Wave : les frais payés par le
     * client en plus restent hors du solde (mouvement à part).
     *
     * @throws \DomainException
     */
    public function deposer(Clients $client, int $montant, string $mode, CompteTresorerie $compte, ?string $referenceExterne, int $frais, User $par, ?string $note = null): MouvementSoldeClient
    {
        if ($montant <= 0) {
            throw new \DomainException('Le montant du dépôt doit être supérieur à zéro.');
        }
        self::verifierChiffres($montant, $client->getSoldeCredit());
        if (!isset(MouvementSoldeClient::MODES[$mode])) {
            throw new \DomainException('Un dépôt se fait en espèces, par Orange Money ou par Wave.');
        }
        if (!\in_array($compte->getType(), Paiements::typesComptesPour($mode), true) || $compte->isOrangeMoneyEnLigne()) {
            throw new \DomainException(sprintf('Le compte « %s » ne reçoit pas ce mode de paiement.', $compte->getNom()));
        }
        if (Paiements::MODE_ESPECES !== $mode && '' === trim((string) $referenceExterne)) {
            throw new \DomainException('Indiquez la référence de la transaction.');
        }
        $frais = Paiements::MODE_ESPECES === $mode ? 0 : max(0, $frais);

        return $this->enTransaction(function () use ($client, $montant, $mode, $compte, $referenceExterne, $frais, $par, $note): MouvementSoldeClient {
            $reference = trim((string) $referenceExterne) ?: null;
            $mouvement = $this->entree($compte, $montant, $mode, $reference, sprintf('Dépôt sur le compte client — %s', $client->getNomComplet()), 'Argent déposé par le client sur son compte, sans rien payer.', $par);
            if ($frais > 0) {
                $this->tresorerie->enregistrer(self::mouvementFrais($compte, $frais, $mode, $reference, $client, $par));
            }
            $ligne = $this->crediter($client, MouvementSoldeClient::DEPOT, $montant, $par, $mode, null)
                ->setMouvementTresorerie($mouvement)
                ->setMotif($note);
            $this->em->flush();
            $ligne->setReference(sprintf('D-%06d', (int) $ligne->getId()));
            $mouvement->setReferenceExterne($reference ?? $ligne->getReference());
            $this->em->flush();

            return $ligne;
        });
    }

    /**
     * Argent rendu au client : sort du compte de trésorerie choisi, jamais
     * plus que le solde disponible. Le motif est obligatoire.
     *
     * @throws \DomainException
     */
    public function rembourser(Clients $client, int $montant, CompteTresorerie $compte, string $motif, User $par): MouvementSoldeClient
    {
        if ($montant <= 0) {
            throw new \DomainException('Le montant à rendre doit être supérieur à zéro.');
        }
        if ('' === trim($motif)) {
            throw new \DomainException('Indiquez pourquoi l\'argent est rendu.');
        }
        if ($compte->isOrangeMoneyEnLigne()) {
            throw new \DomainException('L\'argent ne se rend pas depuis le compte Orange Money en ligne.');
        }
        if (!\in_array($compte->getType(), self::TYPES_COMPTES, true)) {
            throw new \DomainException('L\'argent se rend depuis une caisse, Orange Money ou Wave.');
        }

        return $this->enTransaction(function () use ($client, $montant, $compte, $motif, $par): MouvementSoldeClient {
            $this->verrouiller($client);
            if ($montant > $this->disponiblePour($client)) {
                throw new \DomainException(sprintf('Le client n\'a que %s disponibles sur son compte.', Valeurs::francs($this->disponiblePour($client))));
            }
            $mode = match ($compte->getType()) {
                CompteTresorerie::TYPE_ORANGE_MONEY => Paiements::MODE_ORANGE_MONEY,
                CompteTresorerie::TYPE_WAVE => Paiements::MODE_WAVE,
                default => Paiements::MODE_ESPECES,
            };
            $mouvement = (new MouvementTresorerie())
                ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
                ->setCategorie(MouvementTresorerie::CATEGORIE_AVANCE_CLIENT)
                ->setConfidentiel(false)
                ->setCompteSource($compte)
                ->setMontant($montant)
                ->setModePaiement($mode)
                ->setLibelle(mb_substr(sprintf('Argent rendu au client — %s', $client->getNomComplet()), 0, 255))
                ->setDescription(mb_substr('Argent rendu sur le compte du client : '.trim($motif), 0, 1000))
                ->setAgent($par)
                ->setDateOperation(new \DateTimeImmutable());
            $this->tresorerie->enregistrer($mouvement);
            $ligne = $this->debiter($client, MouvementSoldeClient::REMBOURSEMENT, $montant, $par, $mode, null)
                ->setMouvementTresorerie($mouvement)
                ->setMotif($motif);
            $this->em->flush();
            $ligne->setReference(sprintf('RB-%06d', (int) $ligne->getId()));
            $mouvement->setReferenceExterne($ligne->getReference());
            $this->em->flush();

            return $ligne;
        });
    }

    /**
     * Paie une commande (ou sa facture) avec le solde : tout ou partie,
     * jamais plus que le reste dû ni que le solde disponible. Le paiement
     * « solde client » est validé tout de suite (aucun compte de trésorerie).
     *
     * @throws \DomainException
     */
    public function utiliser(Commandes $commande, int $montant, User $par): Paiements
    {
        $client = $commande->getClients();
        if (null === $client) {
            throw new \DomainException('Cette commande n\'a pas de client.');
        }
        if ('annulee' === $commande->getStatutTravaux()) {
            throw new \DomainException('Cette commande est annulée : aucun paiement ne peut plus y être enregistré.');
        }

        return $this->enTransaction(function () use ($commande, $client, $montant, $par): Paiements {
            $this->verrouiller($client);
            $disponible = $this->disponiblePour($client);
            $reste = $commande->getResteAPayer();
            if ($disponible <= 0) {
                throw new \DomainException('Ce client n\'a aucun solde à utiliser.');
            }
            if ($reste <= 0) {
                throw new \DomainException('Cette commande est déjà entièrement payée.');
            }
            if ($montant <= 0) {
                throw new \DomainException('Le montant doit être supérieur à zéro.');
            }
            if ($montant > $disponible) {
                throw new \DomainException(sprintf('Le client n\'a que %s disponibles sur son compte.', Valeurs::francs($disponible)));
            }
            if ($montant > $reste) {
                throw new \DomainException(sprintf('Le montant dépasse le reste à payer (%s).', Valeurs::francs($reste)));
            }

            $paiement = (new Paiements())
                ->setCommande($commande)
                ->setEncaissePar($par)
                ->setDate(new \DateTimeImmutable())
                ->setMode(Paiements::MODE_SOLDE_CLIENT)
                ->setMontant($montant)
                ->setObservation('Payé avec le solde du compte du client (monnaie gardée, dépôt).');
            $paiement->validerPar($par);
            $commande->addPaiement($paiement);
            $this->em->persist($paiement);
            $this->actualiserCommande($commande, $paiement);

            $this->debiter($client, MouvementSoldeClient::UTILISATION, $montant, $par, null, $commande->getNumero())
                ->setCommande($commande)
                ->setPaiement($paiement);
            $this->em->flush();

            return $paiement;
        });
    }

    /**
     * Avoir utilisé en ligne (« Factures et paiements », paiement « AV … »
     * en attente) : la validation à la boutique retire le montant du solde.
     * Refusé si le solde ne suffit plus ou si le montant dépasse ce qui
     * reste dû sur la commande.
     */
    public function validerAvoir(Paiements $paiement, User $par): void
    {
        if (Paiements::MODE_SOLDE_CLIENT !== $paiement->getMode()) {
            throw new \LogicException('Ce paiement n’utilise pas le solde du client.');
        }

        $this->enTransaction(function () use ($paiement, $par): void {
            if (null !== $paiement->getId()) {
                $this->em->lock($paiement, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($paiement);
            }
            if (!$paiement->estEnAttente()) {
                throw new \LogicException('Seul un paiement en attente peut être validé.');
            }
            $commande = $paiement->getCommande();
            $client = $commande?->getClients();
            if (null === $commande || null === $client) {
                throw new \LogicException('Ce paiement n’est rattaché à aucun client.');
            }
            $this->verrouiller($client);
            $montant = (int) $paiement->getMontant();
            if ($client->getSoldeCredit() < $montant) {
                throw new \LogicException(sprintf('Solde du client insuffisant : %s disponibles pour %s. Rejetez ce paiement.', Valeurs::francs($client->getSoldeCredit()), Valeurs::francs($montant)));
            }
            if ($montant > $commande->getResteAPayer()) {
                throw new \LogicException('Le montant dépasse le reste à payer de la commande. Rejetez ce paiement.');
            }
            $paiement->validerPar($par);
            $this->debiter($client, MouvementSoldeClient::UTILISATION, $montant, $par, null, $paiement->getReference() ?? $commande->getNumero())
                ->setCommande($commande)
                ->setPaiement($paiement);
            $this->em->flush();
        });
    }

    /**
     * Paiement par le solde annulé : le montant revient au compte du
     * client (aucun compte de trésorerie). Appelé par PaiementService::annuler.
     */
    public function annulerUtilisation(Paiements $paiement, string $motif, User $par): void
    {
        $this->enTransaction(function () use ($paiement, $motif, $par): void {
            if (null !== $paiement->getId()) {
                $this->em->lock($paiement, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($paiement);
            }
            if (Paiements::MODE_SOLDE_CLIENT !== $paiement->getMode() || !$paiement->estValide()) {
                throw new \LogicException('Seul un paiement par le solde, validé, revient au compte du client.');
            }
            $client = $paiement->getCommande()?->getClients() ?? throw new \LogicException('Ce paiement n’est rattaché à aucun client.');
            $paiement->annuler($motif, $par);
            $this->crediter($client, MouvementSoldeClient::RETOUR, (int) $paiement->getMontant(), $par, null, $paiement->getCommande()?->getNumero())
                ->setCommande($paiement->getCommande())
                ->setPaiement($paiement)
                ->setMotif($motif);
            $commande = $paiement->getCommande();
            if (null !== $commande) {
                $this->actualiserCommande($commande, null);
            }
            $this->em->flush();
        });
    }

    /**
     * Dépôt Orange Money de l'espace client, vu sur le compte « Orange
     * Money en ligne » : le solde monte du montant, le compte reçoit le
     * montant (avance client) et les frais (à prélever par Orange).
     */
    public function validerDepot(DepotSoldeClient $depot, User $par): MouvementSoldeClient
    {
        return $this->enTransaction(function () use ($depot, $par): MouvementSoldeClient {
            if (null !== $depot->getId()) {
                $this->em->lock($depot, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($depot);
            }
            $client = $depot->getClient() ?? throw new \LogicException('Dépôt sans client.');
            $compte = $this->comptesOrangeMoney?->compteEnLigne() ?? throw new \LogicException('Compte « Orange Money en ligne » introuvable.');
            $depot->valider($par, new \DateTimeImmutable());
            $reference = 'OM '.($depot->getTxnid() ?? $depot->getReference());
            $mouvement = $this->entree($compte, $depot->getMontant(), Paiements::MODE_ORANGE_MONEY, $reference, sprintf('Dépôt Orange Money en ligne — %s', $client->getNomComplet()), sprintf('Dépôt fait par le client depuis son espace (%s).', $depot->getReference()), $par);
            $frais = $depot->getFondsSoutien() + $depot->getFraisRetrait();
            if ($frais > 0) {
                $this->tresorerie->enregistrer(self::mouvementFrais($compte, $frais, Paiements::MODE_ORANGE_MONEY, $reference, $client, $par));
            }
            $ligne = $this->crediter($client, MouvementSoldeClient::DEPOT, $depot->getMontant(), $par, Paiements::MODE_ORANGE_MONEY, $reference)
                ->setDepot($depot)
                ->setMouvementTresorerie($mouvement);
            $this->em->flush();

            return $ligne;
        });
    }

    public function rejeterDepot(DepotSoldeClient $depot, string $motif, User $par): void
    {
        if ('' === trim($motif)) {
            throw new \InvalidArgumentException('Le motif du refus est obligatoire.');
        }
        // Verrouillé et relu comme à la validation : deux caisses ne traitent pas le même dépôt.
        $this->enTransaction(function () use ($depot, $motif, $par): void {
            if (null !== $depot->getId()) {
                $this->em->lock($depot, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($depot);
            }
            $depot->rejeter($motif, $par, new \DateTimeImmutable());
            $this->em->flush();
        });
    }

    /* ---------- Écritures ---------- */

    private function crediter(Clients $client, string $type, int $montant, ?User $par, ?string $mode, ?string $reference): MouvementSoldeClient
    {
        $this->verrouiller($client);

        return $this->ecrire($client, $type, $montant, $par, $mode, $reference);
    }

    private function debiter(Clients $client, string $type, int $montant, ?User $par, ?string $mode, ?string $reference): MouvementSoldeClient
    {
        if ($montant > $client->getSoldeCredit()) {
            throw new \DomainException(sprintf('Le solde de %s ne suffit pas.', $client->getNomComplet()));
        }

        return $this->ecrire($client, $type, -$montant, $par, $mode, $reference);
    }

    private function ecrire(Clients $client, string $type, int $variation, ?User $par, ?string $mode, ?string $reference): MouvementSoldeClient
    {
        $avant = $client->getSoldeCredit();
        $client->setSoldeCredit($avant + $variation);
        $ligne = MouvementSoldeClient::nouveau($client, $type, $variation, $client->getSoldeCredit(), $par, new \DateTimeImmutable())
            ->setMode($mode)
            ->setReference($reference);
        $this->em->persist($ligne);
        // Journal de la fiche (onglet Historique), comme avant le relevé.
        $this->em->persist(new ModificationClient($client, 'Solde client', 'conditions', Valeurs::francs($avant), sprintf('%s (%s%s)', Valeurs::francs($client->getSoldeCredit()), mb_strtolower(MouvementSoldeClient::TYPES[$type]), null !== $reference ? ', '.$reference : ''), new \DateTimeImmutable(), $par));

        return $ligne;
    }

    /**
     * Le travail dans une transaction, enregistré à la fin. Un refus (solde
     * insuffisant, dépôt déjà traité…) annule la transaction sans fermer
     * l'EntityManager, contrairement à wrapInTransaction : « À valider »
     * traite plusieurs opérations d'affilée, et la suivante doit passer.
     * Les refus sont levés avant toute écriture.
     *
     * @template T
     *
     * @param callable(): T $travail
     *
     * @return T
     */
    private function enTransaction(callable $travail): mixed
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $resultat = $travail();
            $this->em->flush();
            $connexion->commit();

            return $resultat;
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }
    }

    /** Fiche du client verrouillée et relue : deux caisses ne prennent pas le même solde. */
    private function verrouiller(Clients $client): void
    {
        if (null !== $client->getId() && $this->em->getConnection()->isTransactionActive()) {
            $this->em->lock($client, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($client);
        }
    }

    private function entree(CompteTresorerie $compte, int $montant, string $mode, ?string $referenceExterne, string $libelle, string $description, User $par): MouvementTresorerie
    {
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_AVANCE_CLIENT)
            ->setConfidentiel(false)
            ->setCompteDestination($compte)
            ->setMontant($montant)
            ->setModePaiement($mode)
            ->setReferenceExterne($referenceExterne)
            ->setLibelle(mb_substr($libelle, 0, 255))
            ->setDescription($description)
            ->setAgent($par)
            ->setDateOperation(new \DateTimeImmutable());
        $this->tresorerie->enregistrer($mouvement);

        return $mouvement;
    }

    /** Frais Orange Money ou Wave payés par le client en plus de son dépôt : jamais sur son solde. */
    public static function mouvementFrais(CompteTresorerie $compte, int $frais, string $mode, ?string $reference, Clients $client, ?User $par): MouvementTresorerie
    {
        $orange = $compte->estOrangeMoney();

        return (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)
            ->setCategorie(ComptesOrangeMoney::categorieFrais($compte))
            ->setConfidentiel(false)
            ->setCompteDestination($compte)
            ->setMontant($frais)
            ->setModePaiement($mode)
            ->setReferenceExterne($reference)
            ->setLibelle(mb_substr(sprintf('%s - dépôt sur le compte client — %s', $orange ? 'Frais Orange Money à prélever' : 'Frais mobile money', $client->getNomComplet()), 0, 255))
            ->setDescription('Frais payés par le client en plus de son dépôt : ils ne vont pas sur son solde.'.($orange ? ' Orange les débite quand l\'argent sort du compte.' : ''))
            ->setAgent($par)
            ->setDateOperation(new \DateTimeImmutable());
    }

    /** Total payé, reste à payer, état du paiement et facture de la commande. */
    private function actualiserCommande(Commandes $commande, ?Paiements $paiement): void
    {
        $paye = $commande->getTotalPaye();
        $commande->setTotalPaye($paye);
        $commande->setResteAPayer(max(0, (int) $commande->getTotalTtc() - $paye));
        $commande->actualiserStatutPaiement();
        $facture = $this->em->getRepository(Factures::class)->findOneBy(['commande' => $commande, 'comptabilisee' => true]);
        if ($facture instanceof Factures) {
            $facture->synchroniserPaiementsDepuisCommande();
            if (null !== $paiement && null === $paiement->getFacture()) {
                $paiement->setFacture($facture);
            }
        }
    }
}
