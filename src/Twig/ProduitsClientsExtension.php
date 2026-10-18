<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Service\ProduitsClient\Aiguillage;
use App\Service\ProduitsClient\FilePrepresseProduits;
use App\Service\ProduitsClient\LiensFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Bandeau du prépresse (maquette MP02) : nombre de clients dont des produits
 * attendent leur fichier traité, pour le prépresse seulement ; aiguillage
 * des lignes d'une commande (MP06) et file d'impression d'une machine.
 */
final class ProduitsClientsExtension extends AbstractExtension
{
    private ?int $nombre = null;

    public function __construct(
        private readonly FilePrepresseProduits $file,
        private readonly Security $security,
        private readonly Aiguillage $aiguillage,
        private readonly EntityManagerInterface $em,
        private readonly LiensFichiers $liens,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('produits_clients_a_preparer', $this->nombre(...)),
            new TwigFunction('aiguillage_commande', $this->aiguillageCommande(...)),
            new TwigFunction('depuis_mes_produits', Aiguillage::depuisMesProduits(...)),
            new TwigFunction('file_impression', $this->fileImpression(...)),
            new TwigFunction('etat_fichier_lie', $this->etatFichierLie(...)),
        ];
    }

    /**
     * Aiguillage des lignes (MP06), seulement si une ligne vient de « Mes
     * produits ».
     *
     * @return array<string, mixed>|null
     */
    public function aiguillageCommande(Commandes $commande): ?array
    {
        foreach ($commande->getCommandesDetails() as $ligne) {
            if (null !== $ligne->getProduitClient()) {
                return $this->aiguillage->pourCommande($commande);
            }
        }

        return null;
    }

    /**
     * État du fichier lié d'une ligne, empreinte recalculée (identique,
     * modifie, introuvable, hors_atelier), ou null sans fichier lié.
     */
    public function etatFichierLie(?CommandesDetails $ligne): ?string
    {
        $lien = $ligne?->getFichierLie();
        if (null === $lien || null === $ligne->getProduitClient()) {
            return null;
        }

        return $this->liens->verifier($ligne->getProduitClient(), $lien, $this->enLigne)['etat'];
    }

    /**
     * Les ordres en attente ou en cours d'une machine (file de l'opérateur).
     *
     * @return list<OrdreProduction>
     */
    public function fileImpression(?string $machine, int $max = 6): array
    {
        if (null === $machine || '' === $machine) {
            return [];
        }

        return $this->em->getRepository(OrdreProduction::class)->createQueryBuilder('o')
            ->join('o.machine', 'm')->addSelect('m')
            ->where('m.nom = :nom')->setParameter('nom', $machine)
            ->andWhere('o.statut IN (:statuts)')->setParameter('statuts', [OrdreProduction::STATUT_A_PRODUIRE, OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE])
            ->orderBy('o.id', 'DESC')->setMaxResults($max)
            ->getQuery()->getResult();
    }

    public function nombre(): int
    {
        if (null !== $this->nombre) {
            return $this->nombre;
        }
        if (!$this->security->getUser() instanceof User || !$this->security->isGranted('ROLE_PREPRESSE')) {
            return $this->nombre = 0;
        }

        return $this->nombre = $this->file->nombre();
    }
}
