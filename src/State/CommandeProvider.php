<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Commande;
use App\ApiResource\Objet\LigneCommande;
use App\ApiResource\Objet\Reference;
use App\Entity\Commandes;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Commande>
 */
final class CommandeProvider extends LectureApi implements ProviderInterface
{
    public function __construct(Pagination $pagination, private readonly EntityManagerInterface $em, private readonly Security $security)
    {
        parent::__construct($pagination);
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $this->lire($operation, $uriVariables, $context);
    }

    protected function requete(array $filtres): QueryBuilder
    {
        $requete = $this->em->createQueryBuilder()
            ->select('c', 'cl')->from(Commandes::class, 'c')
            ->leftJoin('c.clients', 'cl')
            ->where('c.deleted = false')
            ->orderBy('c.dateCommande', 'DESC')->addOrderBy('c.id', 'DESC');
        if (null !== $q = self::texte($filtres, 'q')) {
            $requete->andWhere('c.numero LIKE :q OR cl.nom LIKE :q OR cl.prenom LIKE :q OR cl.raisonSociale LIKE :q')->setParameter('q', self::like($q));
        }
        if (null !== $client = self::entier($filtres, 'client')) {
            $requete->andWhere('cl.id = :client')->setParameter('client', $client);
        }
        if (null !== $depuis = self::date($filtres, 'depuis')) {
            $requete->andWhere('c.dateCommande >= :d')->setParameter('d', $depuis);
        }
        if (self::oui($filtres, 'aConfirmer')) {
            $requete->andWhere('c.statut = false')->andWhere('c.canal = :canal')->setParameter('canal', Commandes::CANAL_EN_LIGNE);
        }

        return $requete;
    }

    protected function convertir(object $entite): Commande
    {
        \assert($entite instanceof Commandes);
        $paiements = $this->security->isGranted('ROLE_PAIEMENT_VOIR');
        $client = $entite->getClients();
        $lignes = [];
        foreach ($entite->getCommandesDetails() as $l) {
            $lignes[] = new LigneCommande(
                (string) $l->getDesignation(),
                $l->getQuantite(),
                null !== $l->getLargeur() ? round((float) $l->getLargeur() * 100, 1) : null,
                null !== $l->getLongueur() ? round((float) $l->getLongueur() * 100, 1) : null,
                $l->getStatutProduction(),
                $l->getTotalTtc(),
            );
        }

        return new Commande(
            (int) $entite->getId(),
            $entite->getNumero(),
            $entite->getDateCommande(),
            $entite->getDateLivraison(),
            null !== $client ? new Reference((int) $client->getId(), $client->getNomComplet()) : null,
            $entite->getCanal(),
            $entite->isStatut(),
            $entite->getStatutTravaux(),
            $entite->getTotalTtc(),
            $paiements ? $entite->getTotalPaye() : null,
            $paiements ? $entite->getResteAPayer() : null,
            $paiements ? $entite->getStatutPaiement() : null,
            $lignes,
        );
    }
}
