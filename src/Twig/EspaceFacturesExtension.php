<?php

namespace App\Twig;

use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use App\Service\EspaceClient\FacturesClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFilter;

/** « Factures et paiements » de l'espace client : montants et états, dits pour le client. */
final class EspaceFacturesExtension
{
    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** « 139 000 F », espaces insécables : le montant ne se coupe pas en fin de ligne. */
    #[AsTwigFilter('francs')]
    public function francs(int|float|null $montant): string
    {
        return number_format((float) $montant, 0, ',', "\u{00A0}")."\u{00A0}F";
    }

    /** @return array{libelle: string, couleur: string} */
    #[AsTwigFilter('facture_etat')]
    public function etatFacture(Factures $f): array
    {
        return FacturesClient::etatFacture($f, new \DateTimeImmutable());
    }

    /** @return array{libelle: string, couleur: string} */
    #[AsTwigFilter('paiement_etat')]
    public function etatPaiement(Paiements $p): array
    {
        return FacturesClient::etatPaiement($p);
    }

    /**
     * Numéro du reçu de la caisse : sur le site, celui que la
     * synchronisation a apporté (les id du site ne sont pas ceux de la
     * caisse) ; au local, celui de la caisse. Rien avant la validation.
     */
    #[AsTwigFilter('numero_recu')]
    public function numeroRecu(Paiements $p): ?string
    {
        return $this->enLigne ? $p->getNumeroRecu() : Paiements::numeroRecuEnvoye($p->getNumeroRecu(), $p);
    }

    #[AsTwigFilter('paiement_moyen')]
    public function moyen(Paiements $p): string
    {
        return FacturesClient::moyen($p);
    }

    /** @return array{libelle: string, couleur: string} */
    #[AsTwigFilter('part_etat')]
    public function etatPart(ReglementEnLignePart $part): array
    {
        return FacturesClient::etatPart($part);
    }

    /** @return array{libelle: string, couleur: string} */
    #[AsTwigFilter('avoir_etat')]
    public function etatAvoir(ReglementEnLigne $r): array
    {
        return FacturesClient::etatAvoir($r);
    }
}
