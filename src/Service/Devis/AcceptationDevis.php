<?php

namespace App\Service\Devis;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Service\DevisCommandeConverter;
use App\Service\Messagerie\Conversations;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réponse du client à un devis depuis son espace (maquettes DT01, DT04) :
 * accepter (la commande est créée sans ressaisie, « à confirmer » par l'imprimerie
 * comme une commande en ligne, l'acompte se paie ensuite par
 * Orange Money), refuser avec un motif, demander une modification (le
 * texte part aussi dans la conversation du devis). Les droits (valideur) sont
 * vérifiés par le contrôleur.
 */
final class AcceptationDevis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DevisCommandeConverter $converter,
        private readonly NotificationService $notifications,
        private readonly Conversations $conversations,
    ) {
    }

    /**
     * @param array<string, mixed> $choix quantités choisies parmi les options (id public de ligne => quantité, DB02)
     * @param ?string             $acompteMode Devis::ACOMPTE_ORANGE_MONEY ou ACOMPTE_IMPRIMERIE (DB03)
     *
     * @throws \DomainException message à montrer au client
     */
    public function accepter(Devis $devis, CompteClient $compte, \DateTimeImmutable $maintenant, array $choix = [], bool $conditions = true, ?string $acompteMode = null): Commandes
    {
        if (!$devis->estEnAttente()) {
            throw new \DomainException('Ce devis ne peut plus être accepté.');
        }
        if (SuiviDevis::estAExpirer($devis, $maintenant)) {
            throw new \DomainException('Ce devis a expiré : demandez-nous une nouvelle version.');
        }
        if (!$conditions) {
            throw new \DomainException('Cochez « J\'accepte ce devis et les conditions de vente » pour accepter.');
        }
        // Quantités choisies : les montants sont refaits par les lignes, jamais reçus du navigateur.
        OptionsDevis::appliquer($devis, $choix);
        $devis->setConditionsAccepteesLe($maintenant)->setAcompteMode($devis->getMontantAcompte() > 0 ? $acompteMode : null);
        SuiviDevis::accepter($devis, $compte->getNomAffiche(), $maintenant);
        $this->em->flush();
        try {
            $commande = $this->converter->convertir($devis);
        } catch (\LogicException $e) {
            throw new \DomainException($e->getMessage());
        }
        // Comme une commande passée en ligne : l'imprimerie la confirme (prix déjà fixé par le devis).
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
        // Dans la conversation du devis aussi : l'atelier y répond, le délai de réponse court.
        $this->conversations->ouvrir($compte, null, $devis, null, ConversationClient::SUJET_COMMANDE, 'Modification demandée : '.$texte, $maintenant);
        $this->notifications->notifierRoles(['ROLE_DEVIS'], \sprintf('Devis %s : %s demande une modification.', $devis->getNumero(), $compte->getNomAffiche()), 'app_devis_show', ['id' => $devis->getId()]);
        $this->em->flush();
    }
}
