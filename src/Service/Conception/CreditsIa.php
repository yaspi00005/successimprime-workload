<?php

namespace App\Service\Conception;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\MouvementCreditIa;
use App\Entity\Paiements;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crédit IA des clients (tenu en ligne, docs/demande-conception.md) :
 * achats de packs, image générée, remboursement d'une génération qui a
 * échoué, paiement rejeté par la caisse. Chaque mouvement est écrit dans
 * le relevé (MouvementCreditIa), qui monte au local.
 *
 * Le solde ne change que par des requêtes conditionnelles : une image ne
 * se débite que si le solde la couvre, dans la même requête, donc deux
 * générations lancées ensemble ne dépensent jamais plus que le crédit.
 *
 * Règles d'argent :
 *  - payé par Orange Money (succès vérifié par le serveur) : crédit
 *    utilisable tout de suite ; le paiement reste « à valider » ;
 *  - payé au numéro marchand : crédit utilisable une fois le paiement
 *    validé par la caisse (au local, redescendu par la synchronisation) ;
 *  - paiement rejeté par la caisse : le crédit est retiré, quitte à
 *    passer sous zéro, et la génération reste bloquée tant qu'il l'est.
 */
final class CreditsIa
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function solde(Clients $client): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT solde FROM credit_ia WHERE client_id = ?', [$client->getId()]);
    }

    /**
     * Débite une image du crédit.
     *
     * @throws \DomainException crédit insuffisant (message pour le client)
     */
    public function debiter(Clients $client, int $prix, string $libelle, ?string $auteur, \DateTimeImmutable $le): MouvementCreditIa
    {
        return $this->em->wrapInTransaction(function () use ($client, $prix, $libelle, $auteur, $le): MouvementCreditIa {
            $this->ouvrirCompte($client);
            $debite = $this->em->getConnection()->executeStatement(
                'UPDATE credit_ia SET solde = solde - :prix WHERE client_id = :client AND solde >= :prix',
                ['prix' => $prix, 'client' => $client->getId()],
            );
            if (1 !== $debite) {
                throw new \DomainException('Crédit IA insuffisant : rechargez-le par Orange Money pour générer une image.');
            }
            $mouvement = MouvementCreditIa::nouveau($client, MouvementCreditIa::DEBIT, -$prix, -1, $libelle, $auteur, null, $le);
            $this->em->persist($mouvement);
            $this->em->flush();

            return $mouvement;
        });
    }

    /** Génération échouée : l'image est rendue au crédit, une seule fois. */
    public function rembourser(MouvementCreditIa $debit, \DateTimeImmutable $le): MouvementCreditIa
    {
        return $this->em->wrapInTransaction(function () use ($debit, $le): MouvementCreditIa {
            $cle = 'remb-'.$debit->getPublicId()->toBase58();
            $deja = $this->em->getRepository(MouvementCreditIa::class)->findOneBy(['cle' => $cle]);
            if ($deja instanceof MouvementCreditIa) {
                return $deja;
            }
            $client = $debit->getClient() ?? throw new \LogicException('Mouvement sans client');
            $montant = abs($debit->getMontant());
            $this->em->getConnection()->executeStatement('UPDATE credit_ia SET solde = solde + :montant WHERE client_id = :client', ['montant' => $montant, 'client' => $client->getId()]);
            $mouvement = MouvementCreditIa::nouveau($client, MouvementCreditIa::REMBOURSEMENT, $montant, 1, 'Génération échouée : image remboursée', null, $cle, $le);
            $this->em->persist($mouvement);
            $this->em->flush();

            return $mouvement;
        });
    }

    /**
     * Pack payé par Orange Money, succès vérifié par le serveur : crédit
     * utilisable tout de suite (une seule fois par transaction).
     */
    public function acheterParOrangeMoney(Clients $client, ?string $auteur, int $images, int $montant, Commandes $commande, Paiements $paiement, string $orderId, \DateTimeImmutable $le): MouvementCreditIa
    {
        $cle = 'achat-'.$orderId;
        $deja = $this->em->getRepository(MouvementCreditIa::class)->findOneBy(['cle' => $cle]);
        if ($deja instanceof MouvementCreditIa) {
            return $deja;
        }
        $mouvement = MouvementCreditIa::nouveau($client, MouvementCreditIa::ACHAT, $montant, $images, self::libellePack($images).' · Orange Money', $auteur, $cle, $le)
            ->setCommande($commande)->setPaiement($paiement);
        $this->ouvrirCompte($client);
        $this->em->getConnection()->executeStatement('UPDATE credit_ia SET solde = solde + :montant WHERE client_id = :client', ['montant' => $montant, 'client' => $client->getId()]);
        $this->em->persist($mouvement);

        return $mouvement;
    }

    /** Pack à payer au numéro marchand ou en boutique : compté une fois validé par la caisse. */
    public function acheterEnAttente(Clients $client, ?string $auteur, int $images, int $montant, Commandes $commande, \DateTimeImmutable $le): MouvementCreditIa
    {
        $mouvement = MouvementCreditIa::nouveau($client, MouvementCreditIa::ACHAT, $montant, $images, self::libellePack($images).' · à régler', $auteur, null, $le, MouvementCreditIa::EN_ATTENTE)
            ->setCommande($commande);
        $this->em->persist($mouvement);

        return $mouvement;
    }

    /**
     * Reprend les décisions de la caisse : pack validé (devient
     * utilisable), paiement Orange Money rejeté (crédit retiré). Sans
     * effet la deuxième fois. À appeler avant d'afficher ou de dépenser.
     */
    public function rapprocher(Clients $client, \DateTimeImmutable $le): void
    {
        $achats = $this->em->getRepository(MouvementCreditIa::class)->findBy(['client' => $client, 'type' => MouvementCreditIa::ACHAT]);
        if (!$this->aRapprocher($achats)) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($client, $le): void {
            $this->ouvrirCompte($client);
            // Verrou sur la ligne du client : deux pages ouvertes ensemble ne rapprochent pas deux fois.
            $this->em->getConnection()->fetchOne('SELECT solde FROM credit_ia WHERE client_id = ? FOR UPDATE', [$client->getId()]);
            foreach ($this->em->getRepository(MouvementCreditIa::class)->findBy(['client' => $client, 'type' => MouvementCreditIa::ACHAT]) as $achat) {
                $this->em->refresh($achat);
                $commande = $achat->getCommande();
                if (MouvementCreditIa::EN_ATTENTE === $achat->getEtat()) {
                    if (null === $commande || $commande->isDeleted()) {
                        $achat->annuler();
                    } elseif ($commande->getTotalPaye() >= $achat->getMontant()) {
                        $achat->acquerir();
                        $this->em->getConnection()->executeStatement('UPDATE credit_ia SET solde = solde + :montant WHERE client_id = :client', ['montant' => $achat->getMontant(), 'client' => $client->getId()]);
                    }
                    continue;
                }
                $paiement = $achat->getPaiement();
                if ($achat->estAcquis() && null !== $paiement && \in_array($paiement->getStatut(), [Paiements::STATUT_REJETE, Paiements::STATUT_ANNULE], true)) {
                    $cle = 'rejet-'.$achat->getPublicId()->toBase58();
                    if (null === $this->em->getRepository(MouvementCreditIa::class)->findOneBy(['cle' => $cle])) {
                        $this->em->getConnection()->executeStatement('UPDATE credit_ia SET solde = solde - :montant WHERE client_id = :client', ['montant' => $achat->getMontant(), 'client' => $client->getId()]);
                        $rejet = MouvementCreditIa::nouveau($client, MouvementCreditIa::REJET, -$achat->getMontant(), -$achat->getImages(), 'Paiement rejeté par la caisse : crédit retiré', null, $cle, $le)
                            ->setCommande($commande)->setPaiement($paiement);
                        $this->em->persist($rejet);
                    }
                }
            }
            $this->em->flush();
        });
    }

    /**
     * Relevé, le plus récent d'abord.
     *
     * @return list<MouvementCreditIa>
     */
    public function releve(Clients $client, int $nombre = 30): array
    {
        return array_values($this->em->getRepository(MouvementCreditIa::class)->findBy(['client' => $client], ['id' => 'DESC'], $nombre));
    }

    /**
     * Packs payés au numéro marchand, pas encore validés par la caisse.
     *
     * @return list<MouvementCreditIa>
     */
    public function enAttente(Clients $client): array
    {
        return array_values($this->em->getRepository(MouvementCreditIa::class)->findBy(['client' => $client, 'type' => MouvementCreditIa::ACHAT, 'etat' => MouvementCreditIa::EN_ATTENTE], ['id' => 'DESC']));
    }

    /**
     * Réserve une image dans le plafond du jour, pour tout le site.
     */
    public function reserverJour(\DateTimeImmutable $jour, int $plafond): bool
    {
        if ($plafond <= 0) {
            return false;
        }
        $connexion = $this->em->getConnection();
        $connexion->executeStatement('INSERT IGNORE INTO compteur_ia_jour (jour, images) VALUES (?, 0)', [$jour->format('Y-m-d')]);

        return 1 === $connexion->executeStatement('UPDATE compteur_ia_jour SET images = images + 1 WHERE jour = :jour AND images < :plafond', ['jour' => $jour->format('Y-m-d'), 'plafond' => $plafond]);
    }

    /** Génération échouée : elle ne compte pas dans le plafond. */
    public function libererJour(\DateTimeImmutable $jour): void
    {
        $this->em->getConnection()->executeStatement('UPDATE compteur_ia_jour SET images = images - 1 WHERE jour = ? AND images > 0', [$jour->format('Y-m-d')]);
    }

    public function imagesDuJour(\DateTimeImmutable $jour): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT images FROM compteur_ia_jour WHERE jour = ?', [$jour->format('Y-m-d')]);
    }

    public static function libellePack(int $images): string
    {
        return \sprintf('Crédit IA · %d image%s', $images, $images > 1 ? 's' : '');
    }

    /** @param list<MouvementCreditIa> $achats */
    private function aRapprocher(array $achats): bool
    {
        foreach ($achats as $a) {
            if (MouvementCreditIa::EN_ATTENTE === $a->getEtat()) {
                return true;
            }
            if ($a->estAcquis() && \in_array($a->getPaiement()?->getStatut(), [Paiements::STATUT_REJETE, Paiements::STATUT_ANNULE], true)) {
                return true;
            }
        }

        return false;
    }

    /** La ligne de solde du client, créée au premier besoin. */
    private function ouvrirCompte(Clients $client): void
    {
        $this->em->getConnection()->executeStatement('INSERT IGNORE INTO credit_ia (client_id, solde) VALUES (?, 0)', [$client->getId()]);
    }
}
