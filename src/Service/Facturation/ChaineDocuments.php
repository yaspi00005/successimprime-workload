<?php

namespace App\Service\Facturation;

use App\Entity\Avoir;
use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\Paiements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les documents d'une commande dans l'ordre (maquette FD05) : devis →
 * commande → BAT → BL → factures → avoirs → paiements, avec l'équilibre
 * du dossier (facturé, avoirs, payé, reste) et l'écart devis → facture.
 * Les étapes que la personne n'a pas le droit d'ouvrir restent visibles
 * sans lien ; les paiements seulement avec le droit « Voir les paiements ».
 */
final class ChaineDocuments
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{etapes: list<array{type: string, titre: string, numero: ?string, date: ?\DateTimeInterface, montant: ?int, detail: ?string, etat: ?string, classe: string, url: ?string, courant: bool}>, equilibre: array{facture: int, avoirs: int, paye: int, aConfirmer: int, reste: int}, ecart: ?array{devis: int, facture: int, ecart: int, devisNumero: ?string}, aFaire: list<array{texte: string, url: ?string, bouton: ?string}>}
     */
    public function pour(Commandes $commande, ?Factures $courante = null): array
    {
        $etapes = [];
        $voir = fn (string $role): bool => $this->security->isGranted($role);
        $voirPaiements = $voir('ROLE_PAIEMENT_VOIR');

        $devis = $this->em->getRepository(Devis::class)->findOneBy(['commande' => $commande], ['id' => 'DESC']);
        if (null !== $devis) {
            $etapes[] = $this->etape('devis', 'Devis '.mb_strtolower($devis->getStatutLibelle()), $devis->getNumero(), $devis->getDateDevis(), $devis->getTotalTtc(), null, null, 'devis',
                $voir('ROLE_DEVIS') ? $this->urls->generate('app_devis_show', ['id' => $devis->getId()]) : null);
        }

        $lignes = array_values(array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
        $resume = 1 === \count($lignes) ? $lignes[0]->getDesignation().' × '.$lignes[0]->getQuantite() : \count($lignes).' lignes';
        $etapes[] = $this->etape('commande', 'Commande', $commande->getNumero(), $commande->getDateCommande(), $commande->getTotalTtc(), $resume, null, 'facture',
            $voir('ROLE_COMMANDE') ? $this->urls->generate('app_commandes_show', ['id' => $commande->getId()]) : null);

        $controles = 0;
        $dernier = null;
        $valides = 0;
        foreach ($lignes as $l) {
            foreach ($l->getControlesPrePresse() as $c) {
                ++$controles;
                $dernier = null === $dernier || $c->getCreeLe() > $dernier ? $c->getCreeLe() : $dernier;
            }
            $valides += $l->isBatValide() ? 1 : 0;
        }
        if ($controles > 0) {
            $tous = $valides === \count($lignes);
            $etapes[] = $this->etape('bat', $tous ? 'BAT validé' : 'BAT en cours', null, $dernier, null, $controles.' version'.($controles > 1 ? 's' : ''), $tous ? null : 'en cours', $tous ? 'recu' : 'acompte', null);
        }

        foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $commande], ['id' => 'ASC']) as $b) {
            if (BonLivraison::STATUT_ANNULE === $b->getStatut()) {
                continue;
            }
            $livre = BonLivraison::STATUT_LIVRE === $b->getStatut();
            $etapes[] = $this->etape('bl', 'Bon de livraison', $b->getNumero(), $b->getLivreLe() ?? $b->getCreeLe(), null, $livre ? (null !== $b->getSignature() ? 'remis · signé' : 'remis') : 'à livrer', $livre ? null : 'à livrer', 'bl',
                $voir('ROLE_LIVRAISON') || $voir('ROLE_COMMANDE') ? $this->urls->generate('app_bons_livraison_show', ['id' => $b->getId()]) : null);
        }

        $factures = $this->em->getRepository(Factures::class)->findBy(['commande' => $commande], ['id' => 'ASC']);
        $facture = 0;
        $avoirsTotal = 0;
        $factureOfficielle = null;
        foreach ($factures as $f) {
            if ($f->estAnnulee()) {
                continue;
            }
            $titre = match (true) { $f->estAcompte() => 'Facture d\'acompte', $f->estProforma() => 'Proforma', default => 'Facture' };
            $etapes[] = $this->etape($f->estAcompte() ? 'acompte' : ($f->estProforma() ? 'proforma' : 'facture'), $titre, $f->getNumero() ?? 'brouillon', $f->getDateFacture(), $f->getTotalTtc(),
                $f->getDateEcheance() && $f->estFacture() ? 'échéance '.$f->getDateEcheance()->format('d/m') : null, $f->estBrouillon() ? 'brouillon' : null, $f->estAcompte() ? 'acompte' : ($f->estProforma() ? 'proforma' : 'facture'),
                $this->urls->generate('app_factures_show', ['id' => $f->getId()]), null !== $courante && $courante->getId() === $f->getId());
            if ($f->estFacture() && $f->estEmise()) {
                $facture += $f->getTotalTtc();
                $factureOfficielle ??= $f;
                foreach ($this->em->getRepository(Avoir::class)->findBy(['facture' => $f], ['id' => 'ASC']) as $a) {
                    $avoirsTotal += $a->getTotal();
                    $etapes[] = $this->etape('avoir', 'Avoir', $a->getNumero(), $a->getCreeLe(), -$a->getTotal(), $a->getMotifLibelle(), null, 'avoir',
                        $this->urls->generate('app_factures_avoir_show', ['id' => $a->getId()]));
                }
            }
        }

        $paye = 0;
        $aConfirmer = 0;
        $aFaire = [];
        foreach ($commande->getPaiements() as $p) {
            if (Paiements::MODE_SOLDE_CLIENT === $p->getMode() && str_starts_with((string) $p->getObservation(), 'Avoir ')) {
                continue; // déjà dans la ligne « Avoirs »
            }
            if ($p->estValide()) {
                $paye += (int) $p->getMontant();
            } elseif (Paiements::STATUT_EN_ATTENTE === $p->getStatut()) {
                $aConfirmer += (int) $p->getMontant();
            } else {
                continue;
            }
            if ($voirPaiements) {
                $enAttente = Paiements::STATUT_EN_ATTENTE === $p->getStatut();
                $etapes[] = $this->etape('paiement', 'Paiement', $p->getNumeroRecu() ?? ($p->estValide() ? Paiements::numeroRecuDe((int) $p->getId()) : null), $p->getDate(), (int) $p->getMontant(),
                    (Paiements::MODES_LABELS[$p->getMode()] ?? $p->getMode()).($enAttente ? ' · à confirmer' : ''), $enAttente ? 'en validation' : null, 'recu',
                    $p->estValide() ? $this->urls->generate('app_caisse_recu', ['id' => $p->getId()]) : null);
                if ($enAttente && $voir('ROLE_ADMIN')) {
                    $aFaire[] = ['texte' => \sprintf('Confirmer le paiement de %s F (%s) après vérification du relevé.', number_format((int) $p->getMontant(), 0, ',', ' '), Paiements::MODES_LABELS[$p->getMode()] ?? $p->getMode()), 'url' => $this->urls->generate('app_a_valider_index'), 'bouton' => 'Confirmer le paiement'];
                }
            }
        }

        $base = $facture > 0 ? $facture : $commande->getTotalTtc();
        $reste = max(0, $base - $avoirsTotal - $paye - $aConfirmer);
        if (null === $factureOfficielle && $voir('ROLE_FACTURE')) {
            $aFaire[] = ['texte' => 'La commande n\'a pas encore de facture validée.', 'url' => null, 'bouton' => null];
        } elseif ($reste > 0 && null !== $factureOfficielle && null !== $factureOfficielle->getDateEcheance() && $factureOfficielle->getDateEcheance() < new \DateTimeImmutable('today')) {
            $aFaire[] = ['texte' => \sprintf('Facture échue : %s F restent à encaisser.', number_format($reste, 0, ',', ' ')), 'url' => null, 'bouton' => null];
        }

        $ecart = null;
        if (null !== $devis && $facture > 0) {
            $ecart = ['devis' => $devis->getTotalTtc(), 'facture' => $facture, 'ecart' => $facture - $devis->getTotalTtc(), 'devisNumero' => $devis->getNumero()];
        }

        return [
            'etapes' => $etapes,
            'equilibre' => ['facture' => $base, 'avoirs' => $avoirsTotal, 'paye' => $paye, 'aConfirmer' => $aConfirmer, 'reste' => $reste, 'surCommande' => 0 === $facture],
            'ecart' => $ecart,
            'aFaire' => $aFaire,
            'voirPaiements' => $voirPaiements,
        ];
    }

    /** @return array{type: string, titre: string, numero: ?string, date: ?\DateTimeInterface, montant: ?int, detail: ?string, etat: ?string, classe: string, url: ?string, courant: bool} */
    private function etape(string $type, string $titre, ?string $numero, ?\DateTimeInterface $date, ?int $montant, ?string $detail, ?string $etat, string $classe, ?string $url, bool $courant = false): array
    {
        return ['type' => $type, 'titre' => $titre, 'numero' => $numero, 'date' => $date, 'montant' => $montant, 'detail' => $detail, 'etat' => $etat, 'classe' => $classe, 'url' => $url, 'courant' => $courant];
    }
}
