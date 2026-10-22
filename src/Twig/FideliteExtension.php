<?php

namespace App\Twig;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteFidelite;
use App\Entity\Paiements;
use App\Service\Fidelite\Fidelite;
use App\Service\Fidelite\ReveilClients;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Données du bloc Fidélité (docs/fidelite.md) : onglet de la fiche client,
 * bloc de la commande, rangée de « Mon compte » dans l'espace client.
 * Les droits se vérifient dans les gabarits et, pour les actions, dans
 * FideliteController.
 */
final class FideliteExtension
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fidelite $fidelite,
        private readonly ReveilClients $reveil,
    ) {
    }

    /** @return array<string, mixed> */
    #[AsTwigFunction('fidelite_client')]
    public function client(Clients $client): array
    {
        $maintenant = new \DateTimeImmutable();
        $compte = $this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]);
        if ($client->isB2B()) {
            return ['exclu' => true, 'en_ligne' => $this->fidelite->estEnLigne()];
        }
        $mouvements = $this->fidelite->mouvements($client, 400);
        $promo = $this->reveil->active($client, $maintenant);

        if ($this->fidelite->estEnLigne()) {
            $resume = Fidelite::resumeEspace($compte);

            return [
                'exclu' => false,
                'en_ligne' => true,
                'resume' => $resume,
                'compte' => $compte,
                'historique' => Fidelite::historique($mouvements, true, 40, $resume['delaiHeures']),
                'promo' => $promo,
            ];
        }

        $r = $this->fidelite->reglages();

        return [
            'exclu' => false,
            'en_ligne' => false,
            'resume' => $this->fidelite->resumeChiffre($client, $compte, $maintenant),
            'compte' => $compte,
            'historique' => Fidelite::historique($mouvements, false, 40, $r->delaiHeures),
            'promo' => $promo,
            'reglages' => $r,
            'reveil' => $this->reveil->reglages(),
        ];
    }

    /** @return array<string, mixed>|null rien pour un client B2B, sans client, ou sur le site en ligne */
    #[AsTwigFunction('fidelite_commande')]
    public function commande(Commandes $commande): ?array
    {
        $client = $commande->getClients();
        if (null === $client || $client->isB2B() || $this->fidelite->estEnLigne()) {
            return null;
        }
        $maintenant = new \DateTimeImmutable();
        $etat = $this->fidelite->etat($client, $maintenant);
        $reste = max(0, $commande->getTotalTtc() - $commande->getTotalPaye());
        $facturee = false;
        foreach ($commande->getFactures() as $f) {
            $facturee = $facturee || ($f->estFacture() && !$f->estAnnulee());
        }
        $points = array_values(array_filter(
            $commande->getPaiements()->toArray(),
            static fn (Paiements $p): bool => Paiements::MODE_POINTS_FIDELITE === $p->getMode() && $p->estValide(),
        ));

        return [
            'actif' => $this->fidelite->reglages()->actif,
            'disponible' => max(0, $etat['disponible']),
            'disponibleF' => max(0, $etat['disponibleF']),
            'expirant' => $etat['expirant'],
            'prochaineExpiration' => $etat['prochaineExpiration'],
            'reste' => $reste,
            'annulee' => Fidelite::commandeAnnulee($commande),
            'confirmee' => $commande->isStatut(),
            'paiements' => $points,
            'estimation' => $this->fidelite->estimer($commande, $maintenant),
            'promo' => $this->reveil->active($client, $maintenant),
            'facturee' => $facturee,
        ];
    }

    /** @return array<string, mixed> le résumé reçu, pour l'espace client */
    #[AsTwigFunction('fidelite_espace')]
    public function espace(Clients $client): array
    {
        return Fidelite::resumeEspace($this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]));
    }
}
