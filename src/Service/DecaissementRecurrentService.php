<?php

namespace App\Service;

use App\Entity\DecaissementRecurrent;
use App\Entity\MouvementTresorerie;
use App\Repository\DecaissementRecurrentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère automatiquement les MouvementTresorerie des charges
 * récurrentes (frais bancaires, remboursement de crédit...) dont
 * l'échéance est atteinte. Appelée par la commande
 * app:executer-decaissements-recurrents (tâche planifiée).
 */
class DecaissementRecurrentService
{
    /**
     * Si une charge n'a pas pu tourner depuis longtemps (poste
     * éteint plusieurs mois, tâche planifiée non installée...), on
     * rattrape les échéances manquées une par une, mais jamais plus
     * que ça d'un coup : au-delà, mieux vaut que l'utilisateur
     * vérifie la charge lui-même plutôt que de débiter le compte
     * en boucle sans surveillance.
     */
    private const MAX_RATTRAPAGE_PAR_CHARGE = 24;

    public function __construct(
        private readonly DecaissementRecurrentRepository $decaissementRecurrentRepository,
        private readonly MouvementTresorerieService $mouvementTresorerieService,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return array{executes: int, echecs: int, details: string[]}
     */
    public function executerDecaissementsDus(?\DateTimeImmutable $reference = null): array
    {
        $reference = $reference ?? new \DateTimeImmutable('today');

        $charges = $this->decaissementRecurrentRepository->findActifsDus($reference);

        $executes = 0;
        $echecs = 0;
        $details = [];

        foreach ($charges as $charge) {
            $iterations = 0;

            while ($charge->estDue($reference) && $iterations < self::MAX_RATTRAPAGE_PAR_CHARGE) {
                $iterations++;

                $resultat = $this->executerUneEcheance($charge, $reference);

                $details[] = $resultat['message'];

                if ($resultat['succes']) {
                    $executes++;
                } else {
                    $echecs++;

                    /*
                     * Un échec (compte insuffisant, compte inactif...)
                     * n'a pas fait avancer l'échéance : inutile de
                     * retenter la même charge dans cette exécution,
                     * elle échouera de la même façon. On passe à la
                     * charge suivante et on réessaiera au prochain
                     * passage de la tâche planifiée.
                     */
                    break;
                }
            }
        }

        return [
            'executes' => $executes,
            'echecs' => $echecs,
            'details' => $details,
        ];
    }

    /**
     * @return array{succes: bool, message: string}
     */
    private function executerUneEcheance(
        DecaissementRecurrent $charge,
        \DateTimeImmutable $reference
    ): array {
        $compte = $charge->getCompteSource();

        try {
            $mouvement = new MouvementTresorerie();
            $mouvement
                ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
                ->setCategorie($charge->getCategorie())
                ->setCompteSource($compte)
                ->setMontant($charge->getMontant())
                ->setLibelle(sprintf(
                    '%s (décaissement automatique %s)',
                    $charge->getLibelle(),
                    $charge->getFrequence() === DecaissementRecurrent::FREQUENCE_ANNUELLE
                        ? 'annuel'
                        : 'mensuel'
                ));

            $this->mouvementTresorerieService->enregistrer($mouvement);

            $charge->marquerExecutee($reference);

            $this->notificationService->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf(
                    'Décaissement automatique : « %s » — %d %s sur %s.',
                    $charge->getLibelle(),
                    $charge->getMontant(),
                    $mouvement->getDevise(),
                    $compte?->getNom() ?? 'compte inconnu'
                ),
                'app_mouvement_tresorerie_show',
                ['id' => $mouvement->getId()]
            );

            $this->entityManager->flush();

            return [
                'succes' => true,
                'message' => sprintf(
                    '[OK] %s : %d %s débités de "%s" (mouvement %s).',
                    $charge->getLibelle(),
                    $charge->getMontant(),
                    $mouvement->getDevise(),
                    $compte?->getNom() ?? '?',
                    $mouvement->getReference()
                ),
            ];
        } catch (\Throwable $exception) {
            $this->notificationService->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf(
                    'Échec du décaissement automatique « %s » (%s) : %s',
                    $charge->getLibelle(),
                    $compte?->getNom() ?? 'compte inconnu',
                    $exception->getMessage()
                ),
                'app_decaissement_recurrent_index'
            );

            $this->entityManager->flush();

            return [
                'succes' => false,
                'message' => sprintf(
                    '[ECHEC] %s : %s',
                    $charge->getLibelle(),
                    $exception->getMessage()
                ),
            ];
        }
    }
}
