<?php

namespace App\Service\Synchro;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Uid\Uuid;

/**
 * Transforme une donnée en tableau envoyable, et l'inverse.
 *
 * Seuls les champs de CatalogueSynchro sont lus et écrits. Les liens vers
 * d'autres données passent par leur identifiant public (UUID) : les id
 * numériques diffèrent entre les deux bases.
 */
final class Serialiseur
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array{champs: array<string, mixed>, relations: array<string, ?string>, listes: array<string, list<string>>} */
    public function versTableau(string $type, object $entite): array
    {
        $def = CatalogueSynchro::types()[$type];
        $meta = $this->em->getClassMetadata($def['classe']);
        $plateforme = $this->em->getConnection()->getDatabasePlatform();

        $champs = [];
        foreach ($def['champs'] as $champ) {
            $valeur = $meta->getFieldValue($entite, $champ);
            $champs[$champ] = $valeur === null ? null : Type::getType($meta->getTypeOfField($champ))->convertToDatabaseValue($valeur, $plateforme);
        }
        $relations = [];
        foreach ($def['relations'] as $champ => $cible) {
            $lie = $meta->getFieldValue($entite, $champ);
            $relations[$champ] = $lie === null ? null : self::idPublic($lie);
        }
        $listes = [];
        foreach ($def['listes'] as $champ => $cible) {
            $ids = [];
            foreach ($meta->getFieldValue($entite, $champ) ?? [] as $lie) {
                $ids[] = self::idPublic($lie);
            }
            sort($ids);
            $listes[$champ] = $ids;
        }

        return ['champs' => $champs, 'relations' => $relations, 'listes' => $listes];
    }

    /**
     * Écrit le tableau dans la donnée (créée si besoin). Ne flushe pas.
     *
     * @param array{champs?: array<string, mixed>, relations?: array<string, ?string>, listes?: array<string, list<string>>} $donnees
     *
     * @return array{0: object, 1: bool} la donnée et « créée »
     */
    public function depuisTableau(string $type, string $publicId, array $donnees): array
    {
        $def = CatalogueSynchro::types()[$type];
        $meta = $this->em->getClassMetadata($def['classe']);
        $plateforme = $this->em->getConnection()->getDatabasePlatform();

        $entite = $this->trouver($type, $publicId);
        $creee = $entite === null;
        if ($creee) {
            $entite = new ($def['classe'])();
            $meta->setFieldValue($entite, 'publicId', Uuid::fromString($publicId));
        }

        foreach ($def['champs'] as $champ) {
            if (!\array_key_exists($champ, $donnees['champs'] ?? [])) {
                continue;
            }
            $brut = $donnees['champs'][$champ];
            $meta->setFieldValue($entite, $champ, $brut === null ? null : Type::getType($meta->getTypeOfField($champ))->convertToPHPValue($brut, $plateforme));
        }
        foreach ($def['relations'] as $champ => $cible) {
            if (!\array_key_exists($champ, $donnees['relations'] ?? [])) {
                continue;
            }
            $id = $donnees['relations'][$champ];
            $meta->setFieldValue($entite, $champ, $id === null ? null : $this->exiger($cible, $id));
        }
        foreach ($def['listes'] as $champ => $cible) {
            if (!\array_key_exists($champ, $donnees['listes'] ?? [])) {
                continue;
            }
            $this->remplacerListe($meta, $entite, $champ, array_map(fn (string $id) => $this->exiger($cible, $id), $donnees['listes'][$champ]));
        }

        return [$entite, $creee];
    }

    public function trouver(string $type, string $publicId): ?object
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }

        return $this->em->getRepository(CatalogueSynchro::types()[$type]['classe'])->findOneBy(['publicId' => Uuid::fromString($publicId)]);
    }

    /** Libellé lisible pour le journal des échanges. */
    public static function titre(string $type, ?object $entite, ?array $donnees = null): string
    {
        $def = CatalogueSynchro::types()[$type] ?? null;
        if ($def === null) {
            return $type;
        }
        $morceaux = [];
        foreach ($def['titre'] as $champ) {
            $v = $donnees['champs'][$champ] ?? null;
            if ($v === null && $entite !== null) {
                $getter = 'get'.ucfirst($champ);
                $v = method_exists($entite, $getter) ? $entite->$getter() : null;
            }
            if (\is_string($v) && trim($v) !== '') {
                $morceaux[] = trim($v);
            }
            if ($morceaux !== [] && $champ === $def['titre'][0]) {
                break; // la raison sociale suffit quand elle existe
            }
        }

        return $def['libelle'].' '.($morceaux !== [] ? mb_substr(implode(' ', $morceaux), 0, 80) : '');
    }

    private function exiger(string $type, string $publicId): object
    {
        return $this->trouver($type, $publicId)
            ?? throw new DependanceManquante(\sprintf('%s %s pas encore reçu', CatalogueSynchro::types()[$type]['libelle'], substr($publicId, 0, 8)));
    }

    /** @param list<object> $cibles */
    private function remplacerListe(ClassMetadata $meta, object $entite, string $champ, array $cibles): void
    {
        $collection = $meta->getFieldValue($entite, $champ);
        $voulus = [];
        foreach ($cibles as $c) {
            $voulus[spl_object_id($c)] = $c;
        }
        foreach ($collection->toArray() as $existant) {
            if (!isset($voulus[spl_object_id($existant)])) {
                $collection->removeElement($existant);
            }
        }
        foreach ($voulus as $c) {
            if (!$collection->contains($c)) {
                $collection->add($c);
            }
        }
    }

    private static function idPublic(object $entite): string
    {
        return (string) $entite->getPublicId()->toRfc4122();
    }
}
