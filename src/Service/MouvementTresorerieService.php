<?php

namespace App\Service;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class MouvementTresorerieService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Enregistre un nouveau mouvement.
     *
     * Les mouvements concernant uniquement une caisse,
     * Orange Money ou Wave sont automatiquement validés.
     *
     * Un mouvement impliquant un compte bancaire reste
     * en attente de validation manuelle.
     */
    public function enregistrer(
        MouvementTresorerie $mouvement
    ): MouvementTresorerie {
        return $this->entityManager->wrapInTransaction(
            function () use ($mouvement): MouvementTresorerie {
                if ($mouvement->getId() !== null) {
                    throw new \LogicException(
                        'Ce mouvement est déjà enregistré.'
                    );
                }

                $this->preparerMouvement($mouvement);
                $this->verifierMouvement($mouvement);

                $this->entityManager->persist($mouvement);

                if ($mouvement->necessiteValidationManuelle()) {
                    $mouvement->setStatut(
                        MouvementTresorerie::STATUT_EN_ATTENTE
                    );
                } else {
                    $this->appliquerAuxSoldes($mouvement);
                    $mouvement->marquerCommeValide();
                }

                $this->entityManager->flush();

                return $mouvement;
            }
        );
    }

    /**
     * Valide manuellement un mouvement en attente,
     * notamment lorsqu’un compte bancaire est concerné.
     */
    public function valider(
        MouvementTresorerie $mouvement
    ): MouvementTresorerie {
        return $this->entityManager->wrapInTransaction(
            function () use ($mouvement): MouvementTresorerie {
                if ($mouvement->getId() === null) {
                    throw new \LogicException(
                        'Le mouvement doit d’abord être enregistré.'
                    );
                }

                if (!$mouvement->isEnAttente()) {
                    throw new \LogicException(
                        'Seul un mouvement en attente peut être validé.'
                    );
                }

                /*
                 * Recharge l’état actuel du mouvement depuis la base
                 * et empêche deux validations simultanées.
                 */
                $this->entityManager->lock(
                    $mouvement,
                    LockMode::PESSIMISTIC_WRITE
                );

                $this->entityManager->refresh($mouvement);

                if (!$mouvement->isEnAttente()) {
                    throw new \LogicException(
                        'Ce mouvement a déjà été traité.'
                    );
                }

                $this->verifierMouvement($mouvement);
                $this->appliquerAuxSoldes($mouvement);
                $mouvement->marquerCommeValide();

                $this->entityManager->flush();

                return $mouvement;
            }
        );
    }

    /**
     * Annule un mouvement qui n’a pas encore affecté les soldes.
     */
    public function annulerEnAttente(
        MouvementTresorerie $mouvement,
        string $motif
    ): MouvementTresorerie {
        return $this->entityManager->wrapInTransaction(
            function () use (
                $mouvement,
                $motif
            ): MouvementTresorerie {
                if ($mouvement->getId() === null) {
                    throw new \LogicException(
                        'Le mouvement n’est pas enregistré.'
                    );
                }

                $this->entityManager->lock(
                    $mouvement,
                    LockMode::PESSIMISTIC_WRITE
                );

                $this->entityManager->refresh($mouvement);

                if (!$mouvement->isEnAttente()) {
                    throw new \LogicException(
                        'Seul un mouvement en attente peut être annulé.'
                    );
                }

                $mouvement->marquerCommeAnnule($motif);

                $this->entityManager->flush();

                return $mouvement;
            }
        );
    }

    /**
     * Annule un mouvement déjà validé, en contrepassant son
     * impact sur les soldes des comptes concernés.
     *
     * Réservé à l’administrateur : corriger une erreur de
     * saisie sur un mouvement déjà appliqué se fait toujours
     * par annulation + nouvelle saisie, jamais par modification
     * silencieuse du montant d’un mouvement historique.
     */
    public function annulerValide(
        MouvementTresorerie $mouvement,
        string $motif
    ): MouvementTresorerie {
        return $this->entityManager->wrapInTransaction(
            function () use (
                $mouvement,
                $motif
            ): MouvementTresorerie {
                if ($mouvement->getId() === null) {
                    throw new \LogicException(
                        'Le mouvement n’est pas enregistré.'
                    );
                }

                $this->entityManager->lock(
                    $mouvement,
                    LockMode::PESSIMISTIC_WRITE
                );

                $this->entityManager->refresh($mouvement);

                if (!$mouvement->isValide()) {
                    throw new \LogicException(
                        'Seul un mouvement validé peut être contrepassé.'
                    );
                }

                $this->contrepasserSoldes($mouvement);

                $mouvement->marquerCommeAnnule($motif);

                $this->entityManager->flush();

                return $mouvement;
            }
        );
    }

    /**
     * Inverse exactement l’effet d’un mouvement validé
     * sur les soldes des comptes concernés.
     */
    private function contrepasserSoldes(
        MouvementTresorerie $mouvement
    ): void {
        $source = $mouvement->getCompteSource();
        $destination = $mouvement->getCompteDestination();
        $montant = $mouvement->getMontant();

        $this->verrouillerComptes($source, $destination);

        switch ($mouvement->getType()) {
            case MouvementTresorerie::TYPE_ENCAISSEMENT:
                if ($destination === null) {
                    throw new \LogicException(
                        'Compte destination absent.'
                    );
                }

                $destination->debiter($montant);
                break;

            case MouvementTresorerie::TYPE_DECAISSEMENT:
                if ($source === null) {
                    throw new \LogicException(
                        'Compte source absent.'
                    );
                }

                $source->crediter($montant);
                break;

            case MouvementTresorerie::TYPE_TRANSFERT:
                if ($source === null || $destination === null) {
                    throw new \LogicException(
                        'Comptes du transfert absents.'
                    );
                }

                $destination->debiter($montant);
                $source->crediter($montant);
                break;

            default:
                throw new \LogicException(
                    'Type de mouvement non pris en charge.'
                );
        }
    }

    private function preparerMouvement(
        MouvementTresorerie $mouvement
    ): void {
        if (trim($mouvement->getReference()) === '') {
            $mouvement->setReference(
                $this->genererReference($mouvement->getType())
            );
        }

        /*
         * Supprime les comptes qui ne correspondent pas
         * au type choisi.
         */
        if (
            $mouvement->getType()
            === MouvementTresorerie::TYPE_ENCAISSEMENT
        ) {
            $mouvement->setCompteSource(null);
        }

        if (
            $mouvement->getType()
            === MouvementTresorerie::TYPE_DECAISSEMENT
        ) {
            $mouvement->setCompteDestination(null);
        }
    }

    private function verifierMouvement(
        MouvementTresorerie $mouvement
    ): void {
        if ($mouvement->getMontant() <= 0) {
            throw new \InvalidArgumentException(
                'Le montant doit être supérieur à zéro.'
            );
        }

        if (
            !in_array(
                $mouvement->getType(),
                MouvementTresorerie::TYPES,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Le type de mouvement est invalide.'
            );
        }

        $source = $mouvement->getCompteSource();
        $destination = $mouvement->getCompteDestination();

        switch ($mouvement->getType()) {
            case MouvementTresorerie::TYPE_ENCAISSEMENT:
                if ($destination === null) {
                    throw new \LogicException(
                        'Le compte destination est obligatoire.'
                    );
                }

                break;

            case MouvementTresorerie::TYPE_DECAISSEMENT:
                if ($source === null) {
                    throw new \LogicException(
                        'Le compte source est obligatoire.'
                    );
                }

                break;

            case MouvementTresorerie::TYPE_TRANSFERT:
                if ($source === null || $destination === null) {
                    throw new \LogicException(
                        'Les comptes source et destination sont obligatoires.'
                    );
                }

                if ($source === $destination) {
                    throw new \LogicException(
                        'Le transfert doit utiliser deux comptes différents.'
                    );
                }

                break;
        }

        $this->verifierCompteActif($source);
        $this->verifierCompteActif($destination);

        $deviseMouvement = strtoupper($mouvement->getDevise());

        if (
            $source !== null
            && strtoupper($source->getDevise()) !== $deviseMouvement
        ) {
            throw new \LogicException(
                sprintf(
                    'La devise du compte source %s ne correspond pas au mouvement.',
                    $source->getNom()
                )
            );
        }

        if (
            $destination !== null
            && strtoupper($destination->getDevise())
                !== $deviseMouvement
        ) {
            throw new \LogicException(
                sprintf(
                    'La devise du compte destination %s ne correspond pas au mouvement.',
                    $destination->getNom()
                )
            );
        }
    }

    private function verifierCompteActif(
        ?CompteTresorerie $compte
    ): void {
        if ($compte === null) {
            return;
        }

        if (!$compte->isActif()) {
            throw new \LogicException(
                sprintf(
                    'Le compte "%s" est désactivé.',
                    $compte->getNom()
                )
            );
        }
    }

    private function appliquerAuxSoldes(
        MouvementTresorerie $mouvement
    ): void {
        $source = $mouvement->getCompteSource();
        $destination = $mouvement->getCompteDestination();
        $montant = $mouvement->getMontant();

        /*
         * Verrouillage des comptes avant modification.
         * Pour un transfert, ils sont verrouillés par ordre d’ID
         * afin de limiter les risques de blocage simultané.
         */
        $this->verrouillerComptes($source, $destination);

        switch ($mouvement->getType()) {
            case MouvementTresorerie::TYPE_ENCAISSEMENT:
                if ($destination === null) {
                    throw new \LogicException(
                        'Compte destination absent.'
                    );
                }

                $destination->crediter($montant);
                break;

            case MouvementTresorerie::TYPE_DECAISSEMENT:
                if ($source === null) {
                    throw new \LogicException(
                        'Compte source absent.'
                    );
                }

                $source->debiter($montant);
                break;

            case MouvementTresorerie::TYPE_TRANSFERT:
                if ($source === null || $destination === null) {
                    throw new \LogicException(
                        'Comptes du transfert absents.'
                    );
                }

                /*
                 * Le débit est effectué avant le crédit.
                 * Si le débit échoue, aucun compte n’est modifié.
                 */
                $source->debiter($montant);
                $destination->crediter($montant);
                break;

            default:
                throw new \LogicException(
                    'Type de mouvement non pris en charge.'
                );
        }
    }

    private function verrouillerComptes(
        ?CompteTresorerie $source,
        ?CompteTresorerie $destination
    ): void {
        $comptes = [];

        if ($source !== null) {
            $comptes[] = $source;
        }

        if (
            $destination !== null
            && $destination !== $source
        ) {
            $comptes[] = $destination;
        }

        usort(
            $comptes,
            static fn (
                CompteTresorerie $a,
                CompteTresorerie $b
            ): int => ($a->getId() ?? 0)
                <=> ($b->getId() ?? 0)
        );

        foreach ($comptes as $compte) {
            $this->entityManager->lock(
                $compte,
                LockMode::PESSIMISTIC_WRITE
            );

            /*
             * Récupère le solde le plus récent après verrouillage.
             */
            $this->entityManager->refresh($compte);
        }
    }

    private function genererReference(string $type): string
    {
        $prefixe = match ($type) {
            MouvementTresorerie::TYPE_ENCAISSEMENT => 'ENC',
            MouvementTresorerie::TYPE_DECAISSEMENT => 'DEC',
            MouvementTresorerie::TYPE_TRANSFERT => 'TRF',
            default => 'MVT',
        };

        return sprintf(
            '%s-%s-%s',
            $prefixe,
            (new \DateTimeImmutable())->format('Ymd-His'),
            strtoupper(bin2hex(random_bytes(3)))
        );
    }
}