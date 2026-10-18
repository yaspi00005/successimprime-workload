<?php

namespace App\Service\Devis;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Service\DevisCommandeConverter;
use App\Service\EspaceClient\MessagesClient;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réponse du client à un devis depuis son espace (maquettes DT01, DT04) :
 * accepter (la commande est créée sans ressaisie, « à confirmer » par la
 * boutique comme une commande en ligne, l'acompte se paie ensuite par
 * Orange Money), refuser avec un motif, demander une modification (le
 * texte part aussi dans ses messages). Les droits (valideur) sont
 * vérifiés par le contrôleur.
 */
final class AcceptationDevis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DevisCommandeConverter $converter,
        private readonly NotificationService $notifications,
        private readonly MessagesClient $messages,
    ) {
    }

    /**
     * @throws \DomainException message à montrer au client
     */
    public function accepter(Devis $devis, CompteClient $compte, \DateTimeImmutable $maintenant): Commandes
    {
        if (!$devis->estEnAttente()) {
            throw new \DomainException('Ce devis ne peut plus être accepté.');
        }
        if (SuiviDevis::estAExpirer($devis, $maintenant)) {
            throw new \DomainException('Ce devis a expiré : demandez-nous une nouvelle version.');
        }
        SuiviDevis::accepter($devis, $compte->getNomAffiche(), $maintenant);
        $this->em->flush();
        try {
            $commande = $this->converter->convertir($devis);
        } catch (\LogicException $e) {
            throw new \DomainException($e->getMessage());
        }
        // Comme une commande passée en ligne : la boutique la confirme (prix déjà fixé par le devis).
        $commande->setCanal(Commandes::CANAL_EN_LIGNE)->setStatut(false)->setDemandeePar($devis->getInterlocuteur() ?? $compte->getNomAffiche());
        $this->em->flush();

        $this->notifications->notifierRoles(['ROLE_DEVIS', 'ROLE_COMMANDE'], \sprintf('Devis %s accepté par %s dans son espace : commande %s créée, à confirmer.', $devis->getNumero(), $compte->getNomAffiche(), $commande->getNumero()), 'app_devis_show', ['id' => $devis->getId()]);
        $this->em->flush();

        return $commande;
    }

    /** @throws \DomainException */
    public function refuser(Devis $devis, CompteClient $compte, string $motif, ?string $precision, \DateTimeImmutable $maintenant): void
    {
        if (!$devis->estEnAttente()) {
            throw new \DomainException('Ce devis n\'attend plus de réponse.');
        }
        SuiviDevis::refuser($devis, isset(Devis::MOTIFS_REFUS[$motif]) ? $motif : 'autre', $precision, $compte->getNomAffiche(), $maintenant);
        $this->em->flush();
        $this->notifications->notifierRoles(['ROLE_DEVIS'], \sprintf('Devis %s refusé par %s : %s.', $devis->getNumero(), $compte->getNomAffiche(), $devis->getMotifRefus()), 'app_devis_show', ['id' => $devis->getId()]);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function demanderModification(Devis $devis, CompteClient $compte, string $texte, \DateTimeImmutable $maintenant): void
    {
        if (!$devis->estEnAttente() && Devis::STATUT_EXPIRE !== $devis->getStatut()) {
            throw new \DomainException('Ce devis n\'attend plus de réponse.');
        }
        $texte = trim($texte);
        if (mb_strlen($texte) < 3) {
            throw new \DomainException('Dites-nous ce qu\'il faut changer.');
        }
        $texte = mb_substr($texte, 0, MessageClient::TEXTE_MAX - 40);
        SuiviDevis::demanderModification($devis, $texte, $compte->getNomAffiche(), $maintenant);
        // Dans ses messages aussi : l'atelier répond dans la conversation habituelle.
        $this->messages->ecrire($compte, \sprintf('Devis %s : %s', $devis->getNumero(), $texte), null, $maintenant);
        $this->notifications->notifierRoles(['ROLE_DEVIS'], \sprintf('Devis %s : %s demande une modification.', $devis->getNumero(), $compte->getNomAffiche()), 'app_devis_show', ['id' => $devis->getId()]);
        $this->em->flush();
    }

    /** Dernier message de la conversation qui parle de ce devis (son numéro), ou null. */
    public function dernierMessage(Devis $devis): ?MessageClient
    {
        if (null === $devis->getClients() || null === $devis->getNumero()) {
            return null;
        }

        return $this->em->getRepository(MessageClient::class)->createQueryBuilder('m')
            ->where('m.client = :c')->andWhere('m.texte LIKE :n')
            ->setParameter('c', $devis->getClients())->setParameter('n', '%'.addcslashes($devis->getNumero(), '%_').'%')
            ->orderBy('m.creeLe', 'DESC')->addOrderBy('m.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
