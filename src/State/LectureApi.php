<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Socle des ressources de l'API (docs/api.md) : une requête Doctrine
 * filtrée, paginée, puis chaque entité recopiée dans sa ressource avec
 * les seuls champs que la personne a le droit de voir.
 */
abstract class LectureApi
{
    public function __construct(private readonly Pagination $pagination)
    {
    }

    /**
     * @param array<string, mixed> $filtres
     */
    abstract protected function requete(array $filtres): QueryBuilder;

    abstract protected function convertir(object $entite): object;

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    protected function lire(Operation $operation, array $uriVariables, array $context): object|iterable|null
    {
        $requete = $this->requete($operation instanceof CollectionOperationInterface ? ($context['filters'] ?? []) : []);
        $racine = $requete->getRootAliases()[0];

        if (!$operation instanceof CollectionOperationInterface) {
            $entite = $requete->andWhere($racine.'.id = :id')->setParameter('id', (int) ($uriVariables['id'] ?? 0))
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();

            return null === $entite ? null : $this->convertir($entite);
        }

        [$page, $debut, $nombre] = $this->pagination->getPagination($operation, $context);
        $pages = new Paginator($requete->setFirstResult($debut)->setMaxResults($nombre)->getQuery(), true);

        return new TraversablePaginator(
            new \ArrayIterator(array_map($this->convertir(...), iterator_to_array($pages))),
            $page,
            $nombre,
            \count($pages),
        );
    }

    /**
     * @param array<string, mixed> $filtres
     */
    protected static function texte(array $filtres, string $cle): ?string
    {
        $valeur = $filtres[$cle] ?? null;
        $valeur = \is_scalar($valeur) ? trim((string) $valeur) : '';

        return '' === $valeur ? null : mb_substr($valeur, 0, 100);
    }

    /**
     * @param array<string, mixed> $filtres
     */
    protected static function date(array $filtres, string $cle): ?\DateTimeImmutable
    {
        $texte = self::texte($filtres, $cle);
        $date = null !== $texte ? \DateTimeImmutable::createFromFormat('!Y-m-d', $texte) : false;

        return false === $date ? null : $date;
    }

    /**
     * @param array<string, mixed> $filtres
     */
    protected static function entier(array $filtres, string $cle): ?int
    {
        $texte = self::texte($filtres, $cle);

        return null !== $texte && ctype_digit($texte) ? (int) $texte : null;
    }

    /**
     * @param array<string, mixed> $filtres
     */
    protected static function oui(array $filtres, string $cle): bool
    {
        return \in_array(self::texte($filtres, $cle), ['1', 'true', 'oui'], true);
    }

    protected static function like(string $texte): string
    {
        return '%'.addcslashes($texte, '%_\\').'%';
    }
}
