<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Facture;
use App\ApiResource\Objet\Reference;
use App\Entity\Factures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Facture>
 */
final class FactureProvider extends LectureApi implements ProviderInterface
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
            ->select('f', 'c', 'cl')->from(Factures::class, 'f')
            ->leftJoin('f.commande', 'c')
            ->leftJoin('c.clients', 'cl')
            ->where('f.etat <> :brouillon')->setParameter('brouillon', Factures::ETAT_BROUILLON)
            ->orderBy('f.dateFacture', 'DESC')->addOrderBy('f.id', 'DESC');
        if (null !== $q = self::texte($filtres, 'q')) {
            $requete->andWhere('f.numero LIKE :q OR cl.nom LIKE :q OR cl.prenom LIKE :q OR cl.raisonSociale LIKE :q')->setParameter('q', self::like($q));
        }
        if (null !== $client = self::entier($filtres, 'client')) {
            $requete->andWhere('cl.id = :client')->setParameter('client', $client);
        }
        if (null !== $depuis = self::date($filtres, 'depuis')) {
            $requete->andWhere('f.dateFacture >= :d')->setParameter('d', $depuis);
        }
        $type = self::texte($filtres, 'type');
        if (\in_array($type, [Factures::TYPE_FACTURE, Factures::TYPE_PROFORMA], true)) {
            $requete->andWhere('f.typeDocument = :type')->setParameter('type', $type);
        }

        return $requete;
    }

    protected function convertir(object $entite): Facture
    {
        \assert($entite instanceof Factures);
        $paiements = $this->security->isGranted('ROLE_PAIEMENT_VOIR');
        $client = $entite->getCommande()?->getClients();

        return new Facture(
            (int) $entite->getId(),
            $entite->getNumero(),
            $entite->getTypeDocument(),
            $entite->getEtat(),
            $entite->getDateFacture(),
            $entite->getDateEcheance(),
            $entite->getCommande()?->getNumero(),
            null !== $client ? new Reference((int) $client->getId(), $client->getNomComplet()) : null,
            $entite->getTotalHt(),
            $entite->getMontantTva(),
            $entite->getTotalTtc(),
            $paiements ? $entite->getMontantPaye() : null,
            $paiements ? $entite->getResteAPayer() : null,
            $paiements ? $entite->getStatutPaiement() : null,
        );
    }
}
