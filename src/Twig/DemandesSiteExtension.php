<?php

namespace App\Twig;

use App\Entity\DemandeDevis;
use App\Entity\Devis;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille « Site » des listes de devis et encart de la demande sur la
 * fiche du devis : les devis brouillons nés d'une demande du site
 * (maquette 11), à vérifier avant tout envoi.
 */
final class DemandesSiteExtension extends AbstractExtension
{
    /** @var array<int, string>|null */
    private ?array $devis = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('devis_du_site', $this->devisDuSite(...)),
            new TwigFunction('demande_du_devis', $this->demandeDuDevis(...)),
        ];
    }

    /**
     * Devis nés d'une demande du site : id du devis => numéro de la demande.
     *
     * @return array<int, string>
     */
    public function devisDuSite(): array
    {
        if (null === $this->devis) {
            $this->devis = [];
            $lignes = $this->em->createQueryBuilder()
                ->select('IDENTITY(d.devis) AS devis', 'd.numero')
                ->from(DemandeDevis::class, 'd')
                ->where('d.devis IS NOT NULL')
                ->getQuery()->getArrayResult();
            foreach ($lignes as $l) {
                $this->devis[(int) $l['devis']] = (string) $l['numero'];
            }
        }

        return $this->devis;
    }

    public function demandeDuDevis(Devis $devis): ?DemandeDevis
    {
        if (null === $devis->getId() || !isset($this->devisDuSite()[$devis->getId()])) {
            return null;
        }

        return $this->em->getRepository(DemandeDevis::class)->findOneBy(['devis' => $devis]);
    }
}
