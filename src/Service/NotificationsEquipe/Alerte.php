<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\User;

/**
 * Une alerte en cours, telle qu'une source la constate (paiement à
 * valider, conversation en retard, machine en panne…), avant d'être
 * distribuée en notifications (une par personne).
 */
final class Alerte
{
    /**
     * @param list<User|string>    $destinataires personnes, ou droits (« ROLE_ADMIN ») : toutes les personnes actives qui l'ont
     * @param array<string, mixed> $parametres    paramètres de la route du bouton
     */
    public function __construct(
        public readonly string $type,
        public readonly string $empreinte,
        public readonly string $titre,
        public readonly array $destinataires,
        public readonly ?string $detail = null,
        public readonly ?string $action = null,
        public readonly ?string $route = null,
        public readonly array $parametres = [],
        public readonly ?int $montant = null,
        public readonly ?string $droitMontant = null,
    ) {
    }
}
