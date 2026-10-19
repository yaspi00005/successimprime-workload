<?php

namespace App\Service;

use App\Entity\ActionCaisse;
use App\Entity\Commandes;
use App\Entity\DemandeConception;
use App\Entity\DemandeEmploye;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\ConversationClient;
use App\Security\MessagerieInterneVoter;
use App\Security\MessagerieVoter;
use App\Service\MessagerieInterne\MessagesEquipe;
use App\Entity\Paiements;
use App\Entity\ReclamationClient;
use App\Entity\ReponseSondage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Pastilles jaunes du menu (maquettes R01 et R02) : ce qui attend une
 * décision ou une action de la personne connectée. Chaque compte n'est
 * calculé que pour qui a le droit de voir l'écran, une fois par requête
 * (vidé entre deux requêtes : ResetInterface).
 */
final class PastillesMenu implements ResetInterface
{
    /** @var array<string, int>|null */
    private ?array $compteurs = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly MessagesEquipe $messagesEquipe,
    ) {
    }

    public function reset(): void
    {
        $this->compteurs = null;
    }

    /**
     * Nombre à afficher par route d'écran (0 : pas de pastille).
     *
     * @return array<string, int>
     */
    public function parRoute(): array
    {
        if (null !== $this->compteurs) {
            return $this->compteurs;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof User) {
            return $this->compteurs = [];
        }

        $compteurs = [];
        $maintenant = new \DateTimeImmutable();

        if ($this->security->isGranted('ROLE_ADMIN')) {
            // Paiements saisis en ligne ou au comptoir, à valider au local.
            $compteurs['app_a_valider_index'] = $this->compter(Paiements::class, 'p', 'p.statut = :s', ['s' => Paiements::STATUT_EN_ATTENTE]);
        }
        if ($this->security->isGranted('ROLE_PAIEMENT_ENCAISSER')) {
            $compteurs['app_actions_index'] = $this->compter(
                ActionCaisse::class, 'a',
                'a.etat = :e AND a.creeLe <= :m AND (a.assigneA = :moi OR a.assigneA IS NULL)',
                ['e' => ActionCaisse::A_FAIRE, 'm' => $maintenant, 'moi' => $utilisateur],
            );
        }
        if ($this->security->isGranted('ROLE_COMMANDE')) {
            // Commandes passées dans l'espace client, à vérifier et confirmer.
            $compteurs['app_ventes_index'] = $this->compter(
                Commandes::class, 'c',
                'c.canal = :canal AND c.statut = false AND c.deleted = false AND (c.validationEntreprise IS NULL OR c.validationEntreprise = :validee)',
                ['canal' => Commandes::CANAL_EN_LIGNE, 'validee' => Commandes::VALIDATION_ACCEPTEE],
            );
        }
        if ($this->security->isGranted('ROLE_PREPRESSE') || $this->security->isGranted('ROLE_COMMANDE')) {
            // Demandes de conception envoyées de l'espace client, pas encore prises en charge.
            $compteurs['app_demandes_conception_index'] = $this->compter(
                DemandeConception::class, 'd',
                'd.statut IN (:statuts) AND d.priseEnChargeLe IS NULL',
                ['statuts' => [DemandeConception::RECUE, DemandeConception::COMMANDEE]],
            );
        }
        if ($this->security->isGranted('ROLE_CLIENT')) {
            // Réclamations écrites par les clients, pas encore répondues.
            $compteurs['app_reclamations_clients_index'] = $this->compter(ReclamationClient::class, 'r', 'r.statut = :s', ['s' => ReclamationClient::OUVERTE]);
            // Notes de 1 ou 2 laissées dans un sondage : le client est à rappeler.
            $compteurs['app_sondages_index'] = $this->compter(ReponseSondage::class, 'r', 'r.alerte = :a', ['a' => ReponseSondage::ALERTE_A_RAPPELER]);
        }
        if ($this->security->isGranted(MessagerieVoter::BOITE)) {
            // Conversations de clients qui attendent une réponse : les siennes et les non assignées (toutes pour la Direction).
            $compteurs['app_messages_clients_index'] = $this->security->isGranted('ROLE_ADMIN')
                ? $this->compter(ConversationClient::class, 'c', 'c.statut = :o AND c.attenteDepuis IS NOT NULL', ['o' => ConversationClient::OUVERTE])
                : $this->compter(ConversationClient::class, 'c', 'c.statut = :o AND c.attenteDepuis IS NOT NULL AND (c.assigneA = :moi OR c.assigneA IS NULL)', ['o' => ConversationClient::OUVERTE, 'moi' => $utilisateur]);
        }
        if ($this->security->isGranted(MessagerieInterneVoter::ACCES)) {
            // Messagerie de l'équipe : fils (groupes, messages privés) avec un message non lu.
            $compteurs['app_messagerie_interne_index'] = $this->messagesEquipe->filsNonLus($utilisateur);
        }
        if ($this->security->isGranted('ROLE_RH')) {
            $compteurs['app_demandes_index'] = $this->compter(DemandeEmploye::class, 'd', 'd.etat = :e', ['e' => DemandeEmploye::EN_ATTENTE]);
        }
        if ($this->security->isGranted('ROLE_PROMOTIONS')) {
            // Exceptions B2B demandées par un agent sur une ligne, à accepter ou refuser.
            $compteurs['app_promotions_index'] = $this->compter(DemandeExceptionPromotion::class, 'x', 'x.statut = :s', ['s' => DemandeExceptionPromotion::EN_ATTENTE]);
        }

        return $this->compteurs = array_filter($compteurs);
    }

    /**
     * @param class-string         $classe
     * @param array<string, mixed> $parametres
     */
    private function compter(string $classe, string $alias, string $condition, array $parametres): int
    {
        $requete = $this->em->createQueryBuilder()->select(\sprintf('COUNT(%s.id)', $alias))->from($classe, $alias)->where($condition);
        foreach ($parametres as $cle => $valeur) {
            $requete->setParameter($cle, $valeur);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }
}
