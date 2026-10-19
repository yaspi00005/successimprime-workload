<?php

namespace App\Service\Commandes;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Synchro\Synchro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dupliquer une commande (demande de Yaya du 19/10/2026) : la cliente
 * revient deux semaines ou deux mois après pour la même commande, au même
 * prix, rien ne change.
 *
 * - Administration, « Dupliquer » : même client, mêmes lignes, mêmes prix,
 *   remises et finitions, mêmes fichiers (mêmes octets, rien recopié).
 *   La copie part « à confirmer » au comptoir ; rien des paiements, de la
 *   fabrication ni des livraisons ne suit.
 * - Espace client, « Commander à nouveau » : la même chose sur une de ses
 *   commandes, en ligne, « à confirmer » par l'imprimerie. Sans la ligne
 *   Express ni ce qui n'est pas à fabriquer (crédits) ; le délai et la
 *   date se choisissent à nouveau.
 */
final class DuplicationCommande
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
        private readonly NouvelleCommande $nouvelleCommande,
    ) {
    }

    /** Ce que l'on peut dupliquer : une vraie commande avec au moins une ligne. */
    public static function duplicable(Commandes $source): bool
    {
        return !$source->isDeleted() && !$source->isSoldeOuverture() && null !== $source->getClients() && [] !== self::lignes($source, false);
    }

    /** @throws \DomainException */
    public function dupliquer(Commandes $source, ?User $par, \DateTimeImmutable $maintenant): Commandes
    {
        if (!self::duplicable($source)) {
            throw new \DomainException('Cette commande ne peut pas être dupliquée.');
        }
        $commande = $this->entete($source, $maintenant)
            ->setCanal(Commandes::CANAL_COMPTOIR)
            ->setAgents($par)
            ->setStatut(false); // à confirmer depuis sa fiche, comme « Commander pour lui »
        $manquants = $this->copierLignes($source, $commande, false, $par);

        return $this->enregistrer($commande, $source, $manquants, $maintenant);
    }

    /** @throws \DomainException */
    public function commanderANouveau(Commandes $source, Clients $client, \DateTimeImmutable $maintenant): Commandes
    {
        if ($source->getClients()?->getId() !== $client->getId() || !$source->isStatut() || !self::duplicable($source) || [] === self::lignes($source, true)) {
            throw new \DomainException('Cette commande ne peut pas être commandée à nouveau. Passez une nouvelle commande.');
        }
        $this->nouvelleCommande->verifierEnAttente($client);
        $commande = $this->entete($source, $maintenant)
            ->setCanal(Commandes::CANAL_EN_LIGNE)
            ->setStatut(false); // à confirmer par l'imprimerie
        $manquants = $this->copierLignes($source, $commande, true, null);
        foreach ($commande->getCommandesDetails() as $ligne) {
            // Comme toute ligne créée en ligne : PAO et BAT, sauf produit du client avec fichier lié.
            Synchro::verifierLigneEnLigne($ligne);
        }

        return $this->enregistrer($commande, $source, $manquants, $maintenant);
    }

    /**
     * Les lignes reprises : pas les lignes annulées ; en ligne, pas la ligne
     * Express ni une ligne sans rien à fabriquer (pack de crédits).
     *
     * @return list<CommandesDetails>
     */
    public static function lignes(Commandes $source, bool $enLigne): array
    {
        return array_values(array_filter($source->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()
            && (!$enLigne || CommandesDetails::TYPE_LIBRE !== $l->getTypeLigne() || $l->isProductionNecessaire() || $l->isPrePresseNecessaire())));
    }

    private function entete(Commandes $source, \DateTimeImmutable $maintenant): Commandes
    {
        $commande = (new Commandes())
            ->setClients($source->getClients())
            ->setDateCommande($maintenant)
            ->setEtat(true)
            ->setTva($source->getTva())
            ->setRemise($source->getRemise())
            ->setObjet($source->getObjet())
            ->setModeLivraison($source->getModeLivraison())
            ->setAdresseLivraison($source->getAdresseLivraison())
            ->setFraisLivraison($source->getFraisLivraison());
        if (null !== $source->getSociete()) {
            $commande->setSociete($source->getSociete());
        }

        return $commande;
    }

    /** @return list<string> fichiers qui ne sont plus sur le serveur */
    private function copierLignes(Commandes $source, Commandes $commande, bool $enLigne, ?User $par): array
    {
        $manquants = [];
        foreach (self::lignes($source, $enLigne) as $ligne) {
            $copie = $ligne->copiePourNouvelleCommande();
            $commande->addCommandesDetail($copie);
            $absents = [];
            foreach ($ligne->getFichiers() as $f) {
                if (!$f->isActif() || $f->isLogo() || CommandeDetailFichier::STATUT_TERMINE !== $f->getStatut()) {
                    continue;
                }
                if (null !== $f->getEffaceLe() || null === $this->espace->cheminSurDisque($f)) {
                    $absents[] = (string) $f->getNomOriginal();
                    continue;
                }
                $this->em->persist($f->copiePourLigne($copie, $par));
            }
            $manquants = [...$manquants, ...$absents];
            if ($enLigne) {
                // La note de la commande ne monte pas au local : celle de la ligne, si.
                $copie->setDemandeClient(\sprintf('Refaite à l\'identique de la commande %s, au même prix%s.', $source->getNumero(), [] !== $absents ? ' ; fichier à reprendre de cette commande : '.implode(', ', array_unique($absents)) : ''));
            }
        }

        return array_values(array_unique($manquants));
    }

    /** @param list<string> $manquants */
    private function enregistrer(Commandes $commande, Commandes $source, array $manquants, \DateTimeImmutable $maintenant): Commandes
    {
        $note = \sprintf('Copie de la commande %s du %s, aux mêmes prix.', $source->getNumero(), $source->getDateCommande()?->format('d/m/Y') ?? '');
        if ([] !== $manquants) {
            $note .= ' Fichier(s) à reprendre de l\'atelier (plus sur le serveur) : '.implode(', ', $manquants).'.';
        }
        $commande->setObservation(mb_substr($note, 0, 1000))->recalculerTotaux();
        $this->em->wrapInTransaction(function () use ($commande, $maintenant): void {
            $this->em->persist($commande);
            $this->em->flush();
            // Même numéro qu'au comptoir ; le site y ajoute W-.
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $maintenant->format('m-Y')));
            $this->em->flush();
        });

        return $commande;
    }
}
