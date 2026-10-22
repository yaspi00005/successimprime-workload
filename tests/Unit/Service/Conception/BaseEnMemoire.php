<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\ImageIa;
use App\Entity\MouvementCreditIa;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Base de données du crédit IA en mémoire pour les tests : les requêtes
 * SQL du solde et du compteur du jour sont rejouées sur des tableaux,
 * avec les mêmes conditions que MariaDB (« WHERE solde >= :prix »,
 * « images < :plafond »).
 */
trait BaseEnMemoire
{
    /** @var array<int, int> solde par client */
    private array $soldes = [];

    /** @var array<string, int> images par jour */
    private array $jours = [];

    /** @var list<MouvementCreditIa> */
    private array $mouvements = [];

    /** @var list<ImageIa> */
    private array $images = [];

    private function em(): EntityManagerInterface
    {
        $connexion = $this->createStub(Connection::class);
        $connexion->method('executeStatement')->willReturnCallback(function (string $sql, array $p = []): int {
            $sql = (string) preg_replace('/\s+/', ' ', $sql);

            return match (true) {
                str_starts_with($sql, 'INSERT IGNORE INTO credit_ia') => $this->insererSiAbsent($this->soldes, $p[0]),
                str_contains($sql, 'SET solde = solde - :prix') && str_contains($sql, 'solde >= :prix') => $this->changer($p['client'], -$p['prix'], $p['prix']),
                str_contains($sql, 'SET solde = solde + :montant') => $this->changer($p['client'], $p['montant']),
                str_contains($sql, 'SET solde = solde - :montant') => $this->changer($p['client'], -$p['montant']),
                str_starts_with($sql, 'INSERT IGNORE INTO compteur_ia_jour') => $this->insererSiAbsent($this->jours, $p[0]),
                str_contains($sql, 'images = images + 1') => $this->compter($p['jour'], 1, $p['plafond']),
                str_contains($sql, 'images = images - 1') => $this->compter($p[0], -1, null),
                default => throw new \LogicException('Requête non prévue : '.$sql),
            };
        });
        $connexion->method('fetchOne')->willReturnCallback(fn (string $sql, array $p = []): int|false => str_contains($sql, 'compteur_ia_jour')
            ? ($this->jours[$p[0]] ?? false)
            : ($this->soldes[$p[0]] ?? false));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connexion);
        $em->method('getRepository')->willReturnCallback(fn (string $classe): EntityRepository => $this->depot($classe));
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $f): mixed => $f($em));
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof MouvementCreditIa && !\in_array($o, $this->mouvements, true)) {
                $this->mouvements[] = $o;
            }
            if ($o instanceof ImageIa && !\in_array($o, $this->images, true)) {
                $this->images[] = $o;
            }
        });

        return $em;
    }

    /** @param class-string $classe */
    private function depot(string $classe): EntityRepository
    {
        $lignes = fn (array $c): array => array_values(array_filter(
            ImageIa::class === $classe ? $this->images : $this->mouvements,
            static fn (object $o): bool => self::correspond($o, $c),
        ));
        $depot = $this->createStub(EntityRepository::class);
        // Les lignes sont dans l'ordre de création : un tri décroissant les inverse.
        $depot->method('findOneBy')->willReturnCallback(fn (array $c, ?array $tri = null): ?object => (null !== $tri ? array_reverse($lignes($c)) : $lignes($c))[0] ?? null);
        $depot->method('findBy')->willReturnCallback(function (array $c, ?array $tri = null, ?int $limite = null) use ($lignes): array {
            $trouvees = 'DESC' === strtoupper((string) (array_values($tri ?? [])[0] ?? '')) ? array_reverse($lignes($c)) : $lignes($c);

            return \array_slice($trouvees, 0, $limite);
        });

        return $depot;
    }

    /** @param array<array-key, int> $table « INSERT IGNORE » : 1 ligne créée, 0 si elle existe */
    private function insererSiAbsent(array &$table, int|string $cle): int
    {
        if (isset($table[$cle])) {
            return 0;
        }
        $table[$cle] = 0;

        return 1;
    }

    /** Compteur du jour : +1 sous le plafond, -1 s'il reste des images. */
    private function compter(string $jour, int $pas, ?int $plafond): int
    {
        $images = $this->jours[$jour] ?? null;
        if (null === $images || (null !== $plafond && $images >= $plafond) || ($pas < 0 && $images <= 0)) {
            return 0;
        }
        $this->jours[$jour] = $images + $pas;

        return 1;
    }

    /** Solde changé ; avec un minimum, seulement s'il le couvre (comme « WHERE solde >= :prix »). */
    private function changer(int $client, int $montant, ?int $minimum = null): int
    {
        if (!isset($this->soldes[$client]) || (null !== $minimum && $this->soldes[$client] < $minimum)) {
            return 0;
        }
        $this->soldes[$client] += $montant;

        return 1;
    }

    /** @param array<string, mixed> $critere */
    private static function correspond(object $o, array $critere): bool
    {
        foreach ($critere as $champ => $valeur) {
            $getter = 'get'.ucfirst($champ);
            if ($o->$getter() !== $valeur) {
                return false;
            }
        }

        return true;
    }
}
