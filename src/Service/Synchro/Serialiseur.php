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
            if (isset($def['filtres'][$champ])) {
                // Réduit le champ selon la donnée (nom d'un client sans son accord, par exemple).
                $valeur = ($def['filtres'][$champ])($valeur, $entite);
            }
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

        $resultat = ['champs' => $champs, 'relations' => $relations, 'listes' => $listes];
        foreach ($def['enfants'] ?? [] as $champ => $enfant) {
            $resultat['enfants'][$champ] = $this->enfantsVersTableau($enfant, $this->enfantsDe($entite, $champ, $enfant));
        }

        return $resultat;
    }

    /** @return list<array{champs: array<string, mixed>, relations: array<string, ?string>}> */
    private function enfantsVersTableau(array $enfant, iterable $collection): array
    {
        $meta = $this->em->getClassMetadata($enfant['classe']);
        $plateforme = $this->em->getConnection()->getDatabasePlatform();
        $liste = [];
        foreach ($collection as $e) {
            $ligne = ['champs' => [], 'relations' => []];
            foreach ($enfant['champs'] as $champ) {
                $v = $meta->getFieldValue($e, $champ);
                if (isset($enfant['filtres'][$champ]) && $v !== null) {
                    $v = ($enfant['filtres'][$champ])($v);
                }
                $ligne['champs'][$champ] = $v === null ? null : Type::getType($meta->getTypeOfField($champ))->convertToDatabaseValue($v, $plateforme);
            }
            foreach ($enfant['relations'] as $champ => $cible) {
                $lie = $meta->getFieldValue($e, $champ);
                $ligne['relations'][$champ] = $lie === null ? null : self::idPublic($lie);
            }
            $liste[] = $ligne;
        }
        // Ordre stable : la même liste donne le même contenu des deux côtés.
        usort($liste, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        return $liste;
    }

    /** Remplace les enfants s'ils ont changé. */
    private function remplacerEnfants(object $entite, string $champ, array $enfant, array $voulus): void
    {
        $parentMeta = $this->em->getClassMetadata($entite::class);
        // Sans collection côté parent (suivi d'un BAT, un pour un), les enfants se cherchent en base.
        $collection = $parentMeta->hasAssociation($champ) ? $parentMeta->getFieldValue($entite, $champ) : null;
        $actuels = $this->enfantsDe($entite, $champ, $enfant);
        if (json_encode($this->enfantsVersTableau($enfant, $actuels)) === json_encode($this->normaliserEnfants($enfant, $voulus))) {
            return;
        }
        $meta = $this->em->getClassMetadata($enfant['classe']);
        $plateforme = $this->em->getConnection()->getDatabasePlatform();
        // Un enfant se reconnaît à ses liens (la finition) : il est mis à jour sur place,
        // sinon supprimer puis recréer le même heurterait la clé unique (ligne, finition).
        $cle = function (array $relations) use ($enfant): string {
            $k = [];
            foreach ($enfant['relations'] as $c => $cible) {
                $k[] = $relations[$c] ?? '';
            }

            return implode('|', $k);
        };
        $existants = [];
        foreach ($actuels as $e) {
            $relations = [];
            foreach ($enfant['relations'] as $c => $cible) {
                $lie = $meta->getFieldValue($e, $c);
                $relations[$c] = $lie === null ? null : self::idPublic($lie);
            }
            $existants[$cle($relations)][] = $e;
        }
        foreach ($voulus as $donnees) {
            $k = $cle($donnees['relations'] ?? []);
            $e = isset($existants[$k]) ? array_shift($existants[$k]) : null;
            if ($e === null) {
                // Sans constructeur : certains enfants en exigent un (le suivi du BAT), les champs suivent.
                $e = $meta->newInstance();
                $meta->setFieldValue($e, $enfant['parent'], $entite);
                foreach ($enfant['relations'] as $c => $cible) {
                    $id = $donnees['relations'][$c] ?? null;
                    $meta->setFieldValue($e, $c, $id === null ? null : $this->exiger($cible, $id));
                }
                $collection?->add($e);
                $this->em->persist($e);
            }
            foreach ($enfant['champs'] as $c) {
                $brut = $donnees['champs'][$c] ?? null;
                if ($brut !== null || $meta->isNullable($c)) {
                    $meta->setFieldValue($e, $c, $brut === null ? null : Type::getType($meta->getTypeOfField($c))->convertToPHPValue($brut, $plateforme));
                }
            }
        }
        foreach ($existants as $restants) {
            foreach ($restants as $ancien) {
                if ($collection !== null) {
                    $collection->removeElement($ancien); // orphanRemoval : supprimé au flush
                } else {
                    $this->em->remove($ancien);
                }
            }
        }
    }

    /** @return list<object> */
    private function enfantsDe(object $entite, string $champ, array $enfant): array
    {
        $meta = $this->em->getClassMetadata($entite::class);
        if ($meta->hasAssociation($champ)) {
            return array_values(($meta->getFieldValue($entite, $champ) ?? new \Doctrine\Common\Collections\ArrayCollection())->toArray());
        }
        if ($meta->getIdentifierValues($entite) === []) {
            return []; // pas encore enregistrée : rien en base
        }

        return $this->em->getRepository($enfant['classe'])->findBy([$enfant['parent'] => $entite]);
    }

    /** Même forme que enfantsVersTableau, pour comparer à ce qui est reçu. */
    private function normaliserEnfants(array $enfant, array $voulus): array
    {
        $liste = [];
        foreach ($voulus as $d) {
            $ligne = ['champs' => [], 'relations' => []];
            foreach ($enfant['champs'] as $c) {
                $ligne['champs'][$c] = $d['champs'][$c] ?? null;
            }
            foreach ($enfant['relations'] as $c => $cible) {
                $ligne['relations'][$c] = $d['relations'][$c] ?? null;
            }
            $liste[] = $ligne;
        }
        usort($liste, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        return $liste;
    }

    /**
     * Écrit le tableau dans la donnée (créée si besoin). Ne flushe pas.
     *
     * @param array{champs?: array<string, mixed>, relations?: array<string, ?string>, listes?: array<string, list<string>>, enfants?: array<string, list<array>>} $donnees
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
            foreach ($def['aLaCreation'] ?? [] as $champ => $fabrique) {
                $meta->setFieldValue($entite, $champ, $fabrique());
            }
        }
        if (method_exists($entite, 'marquerCopieSynchronisee')) {
            $entite->marquerCopieSynchronisee(); // contrôles propres à la saisie sautés
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
            $facultatif = \in_array($champ, $def['facultatives'] ?? [], true);
            $meta->setFieldValue($entite, $champ, $id === null ? null : ($facultatif ? $this->trouver($cible, $id) : $this->exiger($cible, $id)));
        }
        foreach ($def['listes'] as $champ => $cible) {
            if (!\array_key_exists($champ, $donnees['listes'] ?? [])) {
                continue;
            }
            $this->remplacerListe($meta, $entite, $champ, array_map(fn (string $id) => $this->exiger($cible, $id), $donnees['listes'][$champ]));
        }
        foreach ($def['enfants'] ?? [] as $champ => $enfant) {
            if (\is_array($donnees['enfants'][$champ] ?? null)) {
                $this->remplacerEnfants($entite, $champ, $enfant, $donnees['enfants'][$champ]);
            }
        }

        return [$entite, $creee];
    }

    /**
     * Champs dont la valeur reçue diffère de la donnée, sous leur forme
     * envoyée (chaîne ou null) : [champ => [ici, reçu]].
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function differences(string $type, object $entite, array $donnees): array
    {
        $ici = $this->versTableau($type, $entite)['champs'];
        $ecarts = [];
        foreach ($donnees['champs'] ?? [] as $champ => $recu) {
            if (!\array_key_exists($champ, $ici)) {
                continue;
            }
            $a = $ici[$champ] === null ? null : (string) $ici[$champ];
            $b = $recu === null ? null : (string) $recu;
            if ($a !== $b) {
                $ecarts[$champ] = [$a, $b];
            }
        }
        // Un lien que le site peut changer (fichier mis dans une commande) se compare aussi.
        $def = CatalogueSynchro::types()[$type];
        $meta = $this->em->getClassMetadata($def['classe']);
        foreach (array_intersect_key($def['relations'], $def['remontees'] ?? []) as $champ => $cible) {
            if (!\array_key_exists($champ, $donnees['relations'] ?? [])) {
                continue;
            }
            $lie = $meta->getFieldValue($entite, $champ);
            $a = $lie === null ? null : self::idPublic($lie);
            $b = $donnees['relations'][$champ];
            if ($a !== $b) {
                $ecarts[$champ] = [$a, $b === null ? null : (string) $b];
            }
        }

        return $ecarts;
    }

    /** Écrit seulement ces champs (forme envoyée) dans la donnée. Ne flushe pas. */
    public function ecrireChamps(string $type, object $entite, array $valeurs): void
    {
        $meta = $this->em->getClassMetadata(CatalogueSynchro::types()[$type]['classe']);
        $plateforme = $this->em->getConnection()->getDatabasePlatform();
        $relations = CatalogueSynchro::types()[$type]['relations'];
        foreach ($valeurs as $champ => $brut) {
            if (isset($relations[$champ])) {
                $meta->setFieldValue($entite, $champ, $brut === null ? null : $this->exiger($relations[$champ], $brut));
                continue;
            }
            $meta->setFieldValue($entite, $champ, $brut === null ? null : Type::getType($meta->getTypeOfField($champ))->convertToPHPValue($brut, $plateforme));
        }
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
            if (\is_int($v) || (\is_string($v) && $champ === 'montant' && is_numeric($v))) {
                $morceaux[] = number_format((float) $v, 0, ',', ' ').' F';
            } elseif (\is_string($v) && trim($v) !== '') {
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
