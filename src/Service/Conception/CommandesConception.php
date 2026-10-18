<?php

namespace App\Service\Conception;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Commandes nées d'une demande de conception (docs/demande-conception.md) :
 *  - la conception par un graphiste : une ligne « Conception » au forfait,
 *    commande confirmée (le prix vient des réglages) ; l'acompte payé par
 *    Orange Money y est rattaché, « à valider » par la caisse ;
 *  - un pack de crédit IA : une ligne sans fabrication.
 *
 * Pour un paiement Orange Money, la commande n'est créée qu'une fois
 * l'argent reçu (OrangeMoney::appliquer appelle apresPaiement).
 */
final class CommandesConception
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CreditsIa $credits,
    ) {
    }

    /**
     * Commande de la conception : ligne au forfait du support, brief et
     * éléments dans la demande. Rien n'est enregistré ici.
     */
    public static function commandeConception(DemandeConception $demande, \DateTimeImmutable $le): Commandes
    {
        $ligne = (new CommandesDetails())
            ->setTypeLigne(CommandesDetails::TYPE_LIBRE)
            ->setDesignation(mb_substr('Conception · '.$demande->titreSupport(), 0, 255))
            ->setModeCalcul('forfait')
            ->setQuantite(1)
            ->setPrixUnitaire($demande->getForfait())
            ->setTva(0)
            ->setDemandeClient(self::brief($demande))
            ->setPrePresseNecessaire(true)
            ->setProductionNecessaire(false);
        $ligne->calculerTotaux();

        return self::nouvelleCommande($demande->getClient() ?? throw new \LogicException('Demande sans client'), $ligne, $le);
    }

    /** Commande d'un pack de crédit IA : rien à fabriquer ni à contrôler. */
    public static function commandePack(Clients $client, int $images, int $montant, \DateTimeImmutable $le): Commandes
    {
        $ligne = (new CommandesDetails())
            ->setTypeLigne(CommandesDetails::TYPE_LIBRE)
            ->setDesignation(CreditsIa::libellePack($images))
            ->setModeCalcul('forfait')
            ->setQuantite(1)
            ->setPrixUnitaire($montant)
            ->setTva(0)
            ->setPrePresseNecessaire(false)
            ->setProductionNecessaire(false);
        $ligne->calculerTotaux();

        return self::nouvelleCommande($client, $ligne, $le);
    }

    /**
     * Sans paiement en ligne (clés Orange Money absentes, ou acompte à 0) :
     * la commande de conception est enregistrée et la demande part à
     * l'atelier ; l'acompte se règle au numéro marchand ou à l'imprimerie.
     */
    public function envoyerAuGraphiste(DemandeConception $demande, ?CompteClient $compte, \DateTimeImmutable $le): Commandes
    {
        $commande = self::commandeConception($demande, $le);
        $this->preparer($commande, $compte);
        $this->enregistrer($commande, $le);
        $demande->setChoix(DemandeConception::GRAPHISTE)->setCommande($commande);
        $demande->envoyer(DemandeConception::RECUE, $le);
        $this->em->flush();

        return $commande;
    }

    /** Pack payé au numéro marchand : commande confirmée, crédit en attente de la caisse. */
    public function packEnAttente(Clients $client, ?CompteClient $compte, int $images, int $montant, \DateTimeImmutable $le): Commandes
    {
        $commande = self::commandePack($client, $images, $montant, $le);
        $this->preparer($commande, $compte);
        $this->enregistrer($commande, $le);
        $this->credits->acheterEnAttente($client, $compte?->getNomAffiche(), $images, $montant, $commande, $le);
        $this->em->flush();

        return $commande;
    }

    /**
     * Argent reçu par Orange Money pour un acompte ou un pack : la
     * commande est créée maintenant (rend la commande sur laquelle le
     * paiement est enregistré).
     */
    public function commandePour(TransactionOrangeMoney $transaction, \DateTimeImmutable $le): ?Commandes
    {
        $client = $transaction->getClient();
        if (null === $client) {
            return null;
        }
        // Deuxième acompte payé pour la même demande (deux onglets) : sur la même commande, la caisse rembourse.
        $existante = $transaction->getDemandeConception()?->getCommande();
        if (TransactionOrangeMoney::OBJET_ACOMPTE_CONCEPTION === $transaction->getObjet() && null !== $existante && !$existante->isDeleted()) {
            return $existante;
        }
        $commande = match ($transaction->getObjet()) {
            TransactionOrangeMoney::OBJET_ACOMPTE_CONCEPTION => null !== $transaction->getDemandeConception() ? self::commandeConception($transaction->getDemandeConception(), $le) : null,
            TransactionOrangeMoney::OBJET_CREDIT_IA => self::commandePack($client, (int) $transaction->getPackImages(), $transaction->getMontant(), $le),
            default => null,
        };
        if (null === $commande) {
            return null;
        }
        $this->preparer($commande, $transaction->getCompte());
        $this->enregistrer($commande, $le);

        return $commande;
    }

    /** Paiement enregistré : la demande part à l'atelier, ou le crédit devient utilisable. */
    public function apresPaiement(TransactionOrangeMoney $transaction, Paiements $paiement, \DateTimeImmutable $le): void
    {
        $commande = $transaction->getCommande();
        $client = $transaction->getClient();
        if (null === $commande || null === $client) {
            return;
        }
        if (TransactionOrangeMoney::OBJET_ACOMPTE_CONCEPTION === $transaction->getObjet() && null !== ($demande = $transaction->getDemandeConception())) {
            $demande->setChoix(DemandeConception::GRAPHISTE)->setCommande($commande)->setPaiementAcompte($demande->getPaiementAcompte() ?? $paiement);
            if ($demande->estOuverte()) {
                $demande->envoyer(DemandeConception::RECUE, $le);
            }
        } elseif (TransactionOrangeMoney::OBJET_CREDIT_IA === $transaction->getObjet()) {
            $this->credits->acheterParOrangeMoney($client, $transaction->getCompte()?->getNomAffiche(), (int) $transaction->getPackImages(), $transaction->getMontant(), $commande, $paiement, $transaction->getOrderId(), $le);
        }
    }

    /** Ce que le client a écrit, pour le graphiste (sur la ligne de la commande). */
    public static function brief(DemandeConception $d): string
    {
        $lignes = [\sprintf('Demande de conception %s', (string) $d->getNumero())];
        foreach (['Titre' => $d->getTitre(), 'Sous-titre' => $d->getSousTitre(), 'Date et heure' => $d->getDateHeure(), 'Coordonnées' => $d->getCoordonnees(), 'Consignes' => $d->getConsignes()] as $libelle => $texte) {
            if (null !== $texte && '' !== $texte) {
                $lignes[] = $libelle.' : '.$texte;
            }
        }
        if ([] !== $d->getCouleurs()) {
            $lignes[] = 'Couleurs : '.implode(', ', array_map([PromptConception::class, 'nomCouleur'], $d->getCouleurs()));
        }
        if ([] !== $d->getStyles()) {
            $lignes[] = 'Style : '.implode(', ', array_map(static fn (string $s): string => DemandeConception::STYLES[$s] ?? $s, $d->getStyles()));
        }
        if (null !== $d->getQuantite()) {
            $lignes[] = 'Quantité à imprimer ensuite : '.$d->getQuantite();
        }
        if (null !== $d->getPourQuand()) {
            $lignes[] = 'Pour le : '.$d->getPourQuand()->format('d/m/Y');
        }
        if (null !== $d->getExempleLien()) {
            $lignes[] = 'Exemple aimé : '.$d->getExempleLien();
        }

        return mb_substr(implode("\n", $lignes), 0, 3000);
    }

    private static function nouvelleCommande(Clients $client, CommandesDetails $ligne, \DateTimeImmutable $le): Commandes
    {
        $commande = (new Commandes())
            ->setClients($client)
            ->setDateCommande($le)
            ->setCanal(Commandes::CANAL_EN_LIGNE)
            // Prix fixé par les réglages : pas de confirmation à attendre.
            ->setStatut(true)
            ->setEtat(true);
        $commande->addCommandesDetail($ligne);
        $commande->recalculerTotaux();

        return $commande;
    }

    /** Qui l'a demandée, dans un compte d'entreprise. */
    private function preparer(Commandes $commande, ?CompteClient $compte): void
    {
        if (null !== $compte && null !== ($client = $compte->getClient()) && CompteClient::clientAPlusieursComptes($client)) {
            $commande->setDemandeePar($compte->getNomAffiche())->setCompteDemandeur($compte);
        }
    }

    /** Même numéro qu'au comptoir ; le site y ajoute W-. */
    private function enregistrer(Commandes $commande, \DateTimeImmutable $le): void
    {
        $this->em->persist($commande);
        $this->em->flush();
        $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $le->format('m-Y')));
    }
}
