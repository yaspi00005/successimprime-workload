<?php

namespace App\Service\ProduitsClient;

use App\Entity\Clients;
use App\Entity\CommandesDetails;
use App\Entity\ProduitClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * File du prépresse pour « Mes produits » (maquette MP02) : les produits
 * ajoutés par les clients dont le fichier traité n'est pas encore lié, ceux
 * dont l'image a été remplacée (lien suspendu), et les tailles commandées
 * qui ne sont pas encore validées (règle « Tailles validées » : le graphiste
 * adapte le fichier puis lie la nouvelle taille pour la prochaine fois).
 */
final class FilePrepresseProduits
{
    /** Les commandes plus anciennes ne remontent plus dans la file. */
    private const JOURS_TAILLES = 120;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Les clients à traiter, le plus ancien en premier.
     *
     * @return list<array{client: Clients, produits: int, tailles: int, depuis: \DateTimeImmutable}>
     */
    public function clients(): array
    {
        $parClient = [];
        foreach ($this->produitsAPreparer(null) as $p) {
            $c = $p->getClient();
            $id = $c->getId();
            $parClient[$id] ??= ['client' => $c, 'produits' => 0, 'tailles' => 0, 'depuis' => $p->getImageRemplaceeLe() ?? $p->getCreeLe()];
            ++$parClient[$id]['produits'];
            $parClient[$id]['depuis'] = min($parClient[$id]['depuis'], $p->getImageRemplaceeLe() ?? $p->getCreeLe());
        }
        foreach ($this->taillesCommandees(null) as $t) {
            $c = $t['produit']->getClient();
            $id = $c->getId();
            $parClient[$id] ??= ['client' => $c, 'produits' => 0, 'tailles' => 0, 'depuis' => $t['le']];
            ++$parClient[$id]['tailles'];
            $parClient[$id]['depuis'] = min($parClient[$id]['depuis'], $t['le']);
        }
        $liste = array_values($parClient);
        usort($liste, static fn (array $a, array $b): int => $a['depuis'] <=> $b['depuis']);

        return $liste;
    }

    /** Nombre de clients en attente (bandeau du prépresse). */
    public function nombre(): int
    {
        return \count($this->clients());
    }

    /**
     * @return list<ProduitClient>
     */
    public function produitsAPreparer(?Clients $client): array
    {
        $qb = $this->em->getRepository(ProduitClient::class)->createQueryBuilder('p')
            ->join('p.client', 'c')->addSelect('c')
            ->where('p.actif = true')
            ->andWhere('p.etatLien = :suspendu OR (p.etatLien = :aPreparer AND p.origine = :client)')
            ->setParameter('suspendu', ProduitClient::LIEN_SUSPENDU)
            ->setParameter('aPreparer', ProduitClient::LIEN_A_PREPARER)
            ->setParameter('client', ProduitClient::ORIGINE_CLIENT)
            ->orderBy('p.creeLe', 'ASC')->addOrderBy('p.id', 'ASC');
        if (null !== $client) {
            $qb->andWhere('p.client = :cl')->setParameter('cl', $client);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tailles commandées que le produit n'a pas encore validées (une par
     * produit et par taille, la commande la plus récente).
     *
     * @return list<array{produit: ProduitClient, ligne: CommandesDetails, l: float, h: float, le: \DateTimeImmutable}>
     */
    public function taillesCommandees(?Clients $client): array
    {
        $qb = $this->em->getRepository(CommandesDetails::class)->createQueryBuilder('d')
            ->join('d.produitClient', 'p')->addSelect('p')
            ->join('d.commande', 'co')->addSelect('co')
            ->where('p.actif = true')
            ->andWhere('d.prePresseNecessaire = true')
            ->andWhere('co.dateCommande >= :depuis')
            ->setParameter('depuis', new \DateTimeImmutable('-'.self::JOURS_TAILLES.' days'))
            ->orderBy('co.dateCommande', 'DESC');
        if (null !== $client) {
            $qb->andWhere('p.client = :cl')->setParameter('cl', $client);
        }
        $vues = [];
        $liste = [];
        foreach ($qb->getQuery()->getResult() as $ligne) {
            $produit = $ligne->getProduitClient();
            [$l, $h] = Aiguillage::tailleMm($ligne);
            if (null === $l || null === $h || $produit->estTailleValidee($l, $h) || ProduitClient::LIEN_SUSPENDU === $produit->getEtatLien()) {
                continue;
            }
            // Le produit pas encore lié du tout apparaît déjà comme « à préparer » à son format.
            if (ProduitClient::LIEN_A_PREPARER === $produit->getEtatLien() && null !== $produit->getLargeurMm() && ProduitClient::memeTaille($l, $h, (float) $produit->getLargeurMm(), (float) $produit->getHauteurMm())) {
                continue;
            }
            $cle = $produit->getId().'-'.round($l).'x'.round($h);
            if (isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $date = $ligne->getCommande()->getDateCommande();
            $liste[] = ['produit' => $produit, 'ligne' => $ligne, 'l' => $l, 'h' => $h, 'le' => null !== $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable()];
        }

        return $liste;
    }

    /** Le client qui suit dans la file, après celui-ci (ou le premier). */
    public function suivant(Clients $client): ?Clients
    {
        $liste = array_map(static fn (array $c): Clients => $c['client'], $this->clients());
        foreach ($liste as $i => $c) {
            if ($c->getId() === $client->getId()) {
                return $liste[$i + 1] ?? (1 === \count($liste) ? null : ($liste[0] ?? null));
            }
        }

        return $liste[0] ?? null;
    }
}
