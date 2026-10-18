<?php

namespace App\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Repository\PaiementsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Avoir du client sur le site en ligne (« Factures et paiements ») : son
 * solde à l'imprimerie (Clients::soldeCredit, monnaie non rendue, trop-perçu),
 * tenu au local et reçu en lecture seule. Le site ne le change jamais :
 * l'utiliser crée un paiement « solde client » en attente, que l'imprimerie
 * valide (le solde baisse alors au local et redescend) ou refuse.
 *
 * Disponible = solde − ce qui attend déjà la validation − ce que réserve
 * un règlement parti chez Orange Money il y a peu (RESERVATION_MINUTES).
 */
final class AvoirClient
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{solde: int, enValidation: int, reserve: int, disponible: int}
     */
    public function etat(Clients $client, \DateTimeImmutable $maintenant, ?ReglementEnLigne $sauf = null): array
    {
        $paiements = $this->em->getRepository(Paiements::class);
        $enValidation = $paiements instanceof PaiementsRepository ? $paiements->avoirEnValidation($client) : 0;

        $reserve = 0;
        $enCours = $this->em->getRepository(ReglementEnLigne::class)->findBy(['client' => $client, 'statut' => ReglementEnLigne::EN_COURS]);
        foreach ($enCours as $r) {
            if ($r !== $sauf && $r->reserveAvoir($maintenant)) {
                $reserve += $r->getAvoir();
            }
        }

        return self::calculer($client->getSoldeCredit(), $enValidation, $reserve);
    }

    public function disponible(Clients $client, \DateTimeImmutable $maintenant, ?ReglementEnLigne $sauf = null): int
    {
        return $this->etat($client, $maintenant, $sauf)['disponible'];
    }

    /** @return array{solde: int, enValidation: int, reserve: int, disponible: int} */
    public static function calculer(int $solde, int $enValidation, int $reserve): array
    {
        $solde = max(0, $solde);

        return [
            'solde' => $solde,
            'enValidation' => max(0, $enValidation),
            'reserve' => max(0, $reserve),
            'disponible' => max(0, $solde - max(0, $enValidation) - max(0, $reserve)),
        ];
    }
}
