<?php

namespace App\Service\Fichiers;

use App\Entity\CommandeDetailFichier;
use App\Entity\ProduitClient;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conservation des fichiers de commande (règle de Yaya du 19/10/2026),
 * pour que le serveur ne s'alourdisse pas :
 *
 * - un fichier de commande est effacé du serveur quelques mois après
 *   l'impression de sa ligne (réglage « Fichiers gardés après impression »,
 *   4 mois au départ) ;
 * - « Garder ce fichier 1 an », demandé par le client ou l'équipe, porte
 *   ce délai à 12 mois (réglage) ; sans rien demander, il est effacé ;
 * - jamais un fichier enregistré dans « Mes produits » (fichier ou image
 *   d'un produit du client, même empreinte qu'un fichier lié, ou ligne
 *   commandée depuis Mes produits), ni un logo, ni un fichier sans ligne
 *   (bibliothèque du client), ni une ligne pas encore imprimée.
 *
 * Seuls les octets partent ; la fiche reste avec la date d'effacement.
 * Les dossiers de l'atelier (PC de prépresse, dossiers Clients) ne sont
 * jamais touchés. Chaque serveur (local, en ligne) efface ses propres
 * copies : app:nettoyer-fichiers-production, une fois par nuit.
 */
final class ConservationFichiers
{
    public const MOIS_DEFAUT = 4;
    public const GARDE_DEFAUT = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly EspaceClient $espace,
    ) {
    }

    public function mois(): int
    {
        return max(1, (int) ($this->parametres->valeur('espace_client', 'fichiers_conservation') ?? self::MOIS_DEFAUT));
    }

    public function moisGarde(): int
    {
        return max($this->mois(), (int) ($this->parametres->valeur('espace_client', 'fichiers_garde') ?? self::GARDE_DEFAUT));
    }

    /**
     * Date où le fichier sera effacé, ou null s'il ne l'est pas (protégé,
     * pas encore imprimé, déjà effacé).
     */
    public static function effacementLe(CommandeDetailFichier $f, int $mois, int $moisGarde, bool $protege): ?\DateTimeImmutable
    {
        $imprime = $f->getCommandeDetail()?->getProductionTermineeLe();
        if ($protege || null === $imprime || null !== $f->getEffaceLe() || $f->isLogo()) {
            return null;
        }

        return $imprime->modify('+'.(null !== $f->getGardeDemandeeLe() ? $moisGarde : $mois).' months');
    }

    /** La date d'effacement d'un fichier, avec les réglages et Mes produits. */
    public function effacement(CommandeDetailFichier $f): ?\DateTimeImmutable
    {
        return self::effacementLe($f, $this->mois(), $this->moisGarde(), $this->protege($f));
    }

    /** Enregistré dans « Mes produits » : jamais effacé. */
    public function protege(CommandeDetailFichier $f): bool
    {
        [$ids, $empreintes] = $this->proteges();

        return isset($ids[(int) $f->getId()]) || null !== $f->getCommandeDetail()?->getProduitClient()
            || (null !== $f->getEmpreinte() && isset($empreintes[$f->getEmpreinte()]));
    }

    public function garder(CommandeDetailFichier $f, ?string $par, \DateTimeImmutable $maintenant): void
    {
        if (null === $f->getGardeDemandeeLe()) {
            $f->garderPlusLongtemps($par, $maintenant);
        }
    }

    /**
     * Efface les fichiers arrivés au bout de leur durée.
     *
     * @return array{effaces: int, octets: int, gardes: int}
     */
    public function nettoyer(\DateTimeImmutable $maintenant, bool $essai = false): array
    {
        $mois = $this->mois();
        $moisGarde = $this->moisGarde();
        /** @var list<CommandeDetailFichier> $candidats */
        $candidats = $this->em->createQueryBuilder()->select('f', 'd')->from(CommandeDetailFichier::class, 'f')
            ->join('f.commandeDetail', 'd')
            ->where('f.effaceLe IS NULL')->andWhere('f.logo = false')
            ->andWhere('d.productionTermineeLe IS NOT NULL')->andWhere('d.productionTermineeLe < :limite')
            ->setParameter('limite', $maintenant->modify('-'.$mois.' months'))
            ->getQuery()->getResult();
        $bilan = ['effaces' => 0, 'octets' => 0, 'gardes' => 0];
        foreach ($candidats as $f) {
            $le = self::effacementLe($f, $mois, $moisGarde, $this->protege($f));
            if (null === $le || $le > $maintenant) {
                $bilan['gardes'] += null !== $f->getGardeDemandeeLe() ? 1 : 0;
                continue;
            }
            if (!$essai) {
                $bilan['octets'] += $this->effacer($f, $maintenant);
            }
            ++$bilan['effaces'];
        }
        if (!$essai) {
            $this->em->flush();
        }

        return $bilan;
    }

    /**
     * Efface les octets d'un fichier du serveur et le note effacé (la fiche
     * reste). Sert à la commande de nuit, et à un administrateur qui
     * supprime à la main le fichier d'un produit du client (Mes produits).
     *
     * @return int octets libérés
     */
    public function effacer(CommandeDetailFichier $f, \DateTimeImmutable $maintenant): int
    {
        // Fichier traité en prépresse (var/<chemin>) ou envoyé à plat (var/uploads/commandes).
        $chemin = $this->espace->cheminSurDisque($f);
        $octets = 0;
        // Un autre fichier encore gardé (commande dupliquée) partage les mêmes octets : ils restent.
        if (null !== $chemin && !$this->partage($f)) {
            $octets = (int) filesize($chemin);
            @unlink($chemin);
        }
        $f->marquerEfface($maintenant);

        return $octets;
    }

    private function partage(CommandeDetailFichier $f): bool
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(o.id)')->from(CommandeDetailFichier::class, 'o')
            ->where('o.nomStockage = :nom OR (o.chemin IS NOT NULL AND o.chemin = :chemin)')->andWhere('o.id != :id')->andWhere('o.effaceLe IS NULL')
            ->setParameter('nom', $f->getNomStockage())->setParameter('chemin', (string) $f->getChemin())->setParameter('id', $f->getId())
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** @var array{0: array<int, true>, 1: array<string, true>}|null */
    private ?array $proteges = null;

    /** @return array{0: array<int, true>, 1: array<string, true>} ids de fichiers et empreintes de Mes produits */
    private function proteges(): array
    {
        if (null !== $this->proteges) {
            return $this->proteges;
        }
        $ids = [];
        $empreintes = [];
        foreach ($this->em->getRepository(ProduitClient::class)->findAll() as $p) {
            foreach ([$p->getFichier(), $p->getImage()] as $f) {
                if (null !== $f) {
                    $ids[(int) $f->getId()] = true;
                    if (null !== $f->getEmpreinte()) {
                        $empreintes[$f->getEmpreinte()] = true;
                    }
                }
            }
            foreach ($p->getFichiersLies() as $l) {
                if ('' !== (string) ($l['empreinte'] ?? '')) {
                    $empreintes[(string) $l['empreinte']] = true;
                }
            }
        }

        return $this->proteges = [$ids, $empreintes];
    }
}
