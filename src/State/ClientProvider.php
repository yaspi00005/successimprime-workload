<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Client;
use App\Entity\Clients;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Client>
 */
final class ClientProvider extends LectureApi implements ProviderInterface
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
            ->select('c')->from(Clients::class, 'c')
            ->orderBy('c.id', 'DESC');
        if (null !== $q = self::texte($filtres, 'q')) {
            $requete->andWhere('c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q OR c.code LIKE :q OR c.telephone LIKE :q OR c.telephone2 LIKE :q')
                ->setParameter('q', self::like($q));
        }
        if (null !== $depuis = self::date($filtres, 'modifieDepuis')) {
            $requete->andWhere('c.updatedAt >= :d OR c.createdAt >= :d')->setParameter('d', $depuis);
        }

        return $requete;
    }

    protected function convertir(object $entite): Client
    {
        \assert($entite instanceof Clients);
        $paiements = $this->security->isGranted('ROLE_PAIEMENT_VOIR');

        return new Client(
            (int) $entite->getId(),
            $entite->getCode(),
            $entite->getNomComplet(),
            $entite->getTypeClient(),
            $entite->getTelephone(),
            $entite->getEmail(),
            $entite->getVille(),
            $entite->getCommune(),
            $entite->getQuartier(),
            false !== $entite->isStatut(),
            $entite->getCreatedAt(),
            $paiements ? $entite->getPlafondCredit() : null,
            $paiements ? $entite->getSoldeCredit() : null,
        );
    }
}
