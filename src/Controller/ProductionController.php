<?php

namespace App\Controller;

use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Entity\Machines;
use App\Repository\OrdreProductionRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\StockService;
use App\Service\NotificationService;
use App\Entity\Articles;
use App\Entity\StockSorties;
use App\Repository\ArticlesRepository;



#[Route('/production', name: 'app_production_')]
final class ProductionController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        OrdreProductionRepository $ordreProductionRepository,
        TypesImpressionRepository $typesImpressionRepository
    ): Response {
        /*
     * Récupération du filtre depuis l’URL :
     * /production?typeImpression=2
     */
        $typeImpressionId = $request->query->getInt(
            'typeImpression'
        );

        if ($typeImpressionId <= 0) {
            $typeImpressionId = null;
        }

        /*
     * Types d’impression visibles dans le menu.
     */
        $typesImpression = $typesImpressionRepository->findBy(
            ['publie' => true],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        );

        /*
     * Vérification du type demandé.
     */
        $typeSelectionne = null;

        if ($typeImpressionId !== null) {
            $typeSelectionne = $typesImpressionRepository->find(
                $typeImpressionId
            );

            if ($typeSelectionne === null) {
                throw $this->createNotFoundException(
                    'Le type d’impression sélectionné est introuvable.'
                );
            }
        }

        /*
     * On récupère tous les travaux actifs une seule fois.
     *
     * Important : aucun filtre par type n’est appliqué ici,
     * afin de calculer correctement tous les compteurs.
     */
        $tousLesOrdres = $ordreProductionRepository
            ->rechercherTravauxAtelier();

        /*
     * Cette fonction recherche le type d’impression d’un ordre.
     *
     * Priorité :
     * 1. ProduitConfiguration → TypeImpression
     * 2. CommandesDetails → TypeImpression
     */
        $recupererTypeImpression = static function (
            OrdreProduction $ordre
        ) {
            $detail = $ordre->getCommandeDetail();

            if ($detail === null) {
                return null;
            }

            $typeConfiguration = $detail
                ->getProduitConfiguration()
                ?->getTypeImpression();

            if ($typeConfiguration !== null) {
                return $typeConfiguration;
            }

            return $detail->getTypeImpression();
        };

        /*
     * Calcul des compteurs à partir des ordres réellement
     * affichés dans la production.
     */
        $compteurs = [];

        foreach ($tousLesOrdres as $ordre) {
            $typeImpression = $recupererTypeImpression($ordre);

            if (
                $typeImpression === null
                || $typeImpression->getId() === null
            ) {
                continue;
            }

            $typeId = $typeImpression->getId();

            $compteurs[$typeId] =
                ($compteurs[$typeId] ?? 0) + 1;
        }

        /*
     * Construction du menu des types d’impression.
     */
        $menuTypesImpression = [];

        foreach ($typesImpression as $typeImpression) {
            $typeId = $typeImpression->getId();

            $menuTypesImpression[] = [
                'id' => $typeId,
                'nom' => $typeImpression->getNom(),
                'nombre' => $compteurs[$typeId] ?? 0,
                'ordre' => $typeImpression->getOrdre(),
            ];
        }

        /*
     * Classement :
     *
     * 1. Types avec le plus de travaux ;
     * 2. Ordre défini dans la base ;
     * 3. Nom alphabétique.
     *
     * Les types à zéro arrivent automatiquement à la fin.
     */
        usort(
            $menuTypesImpression,
            static function (array $a, array $b): int {
                $comparaisonNombre =
                    $b['nombre'] <=> $a['nombre'];

                if ($comparaisonNombre !== 0) {
                    return $comparaisonNombre;
                }

                $comparaisonOrdre =
                    $a['ordre'] <=> $b['ordre'];

                if ($comparaisonOrdre !== 0) {
                    return $comparaisonOrdre;
                }

                return strcasecmp(
                    $a['nom'],
                    $b['nom']
                );
            }
        );

        /*
     * Le compteur « Tous » correspond au nombre réel
     * de travaux actifs, même si certains n’ont aucun type.
     */
        $nombreTotalActif = count($tousLesOrdres);

        /*
     * Filtrage des ordres après le calcul des compteurs.
     */
        $ordres = $tousLesOrdres;

        if ($typeImpressionId !== null) {
            $ordres = array_values(
                array_filter(
                    $tousLesOrdres,
                    static function (
                        OrdreProduction $ordre
                    ) use (
                        $typeImpressionId,
                        $recupererTypeImpression
                    ): bool {
                        $typeImpression =
                            $recupererTypeImpression($ordre);

                        if ($typeImpression === null) {
                            return false;
                        }

                        return $typeImpression->getId()
                            === $typeImpressionId;
                    }
                )
            );
        }

        /*
     * Statistiques correspondant uniquement aux ordres
     * actuellement affichés.
     */
        $statistiques = [
            'total' => count($ordres),
            'aProduire' => 0,
            'enCours' => 0,
            'enPause' => 0,
            'enRetard' => 0,
        ];

        foreach ($ordres as $ordre) {
            match ($ordre->getStatut()) {
                OrdreProduction::STATUT_A_PRODUIRE =>
                $statistiques['aProduire']++,

                OrdreProduction::STATUT_EN_COURS =>
                $statistiques['enCours']++,

                OrdreProduction::STATUT_EN_PAUSE =>
                $statistiques['enPause']++,

                default => null,
            };

            if ($ordre->estEnRetard()) {
                $statistiques['enRetard']++;
            }
        }

        return $this->render(
            'production/index.html.twig',
            [
                'ordres' => $ordres,
                'statistiques' => $statistiques,
                'typesImpression' => $menuTypesImpression,
                'typeSelectionne' => $typeSelectionne,
                'nombreTotalActif' => $nombreTotalActif,
            ]
        );
    }

    /*
     * ============================================================
     * VUE D'ENSEMBLE
     * ============================================================
     *
     * Liste à plat de tous les travaux actuellement en production
     * (à produire, en cours, en pause), sans regroupement par poste
     * ni détection de machine : consultable même sans être connecté
     * sur une machine d'impression.
     * ============================================================
     */

    #[Route('/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(
        OrdreProductionRepository $ordreProductionRepository
    ): Response {
        $ordres = $ordreProductionRepository->rechercherTravauxAtelier();

        return $this->render(
            'production/apercu.html.twig',
            [
                'ordres' => $ordres,
            ]
        );
    }

    #[Route(
        '/{id}',
        name: 'show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(
        OrdreProduction $ordre,
        Request $request,
        ArticlesRepository $articlesRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var Machines|null $machineCourante */
        $machineCourante = $request->attributes->get(
            '_production_machine'
        );

        $detail = $ordre->getCommandeDetail();

        $consommables = $detail === null
            ? []
            : $em->getRepository(StockSorties::class)->findBy(
                [
                    'commandeDetail' => $detail,
                    'origine' => StockSorties::ORIGINE_MANUELLE,
                    'referenceOrigine' => $ordre->getNumero(),
                ],
                ['date' => 'DESC']
            );

        return $this->render(
            'production/show.html.twig',
            [
                'ordre' => $ordre,
                'machineCourante' => $machineCourante,
                'adresseIpCourante' => $request->getClientIp(),
                'articlesConsommables' => $articlesRepository->findConsommables(),
                'consommables' => $consommables,
            ]
        );
    }

    /**
     * Affecte ou modifie le numéro d’étiquette d’un ordre.
     *
     * Si aucun numéro n’est saisi, un numéro automatique est généré
     * sous la forme ETQ-2026-000125.
     */
    #[Route(
        '/{id}/etiquette/affecter',
        name: 'affecter_etiquette',
        requirements: ['id' => '\\d+'],
        methods: ['POST']
    )]
    public function affecterEtiquette(
        OrdreProduction $ordre,
        Request $request,
        OrdreProductionRepository $ordreProductionRepository,
        EntityManagerInterface $em
    ): Response {
        $utilisateur = $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_affecter_etiquette_' . $ordre->getId()
        );

        try {
            $numeroEtiquette = strtoupper(trim(
                (string) $request->request->get('numero_etiquette')
            ));

            if ($numeroEtiquette === '') {
                $numeroEtiquette = $this->genererNumeroEtiquette(
                    $ordre
                );
            }

            $ordreExistant = $ordreProductionRepository->findOneBy([
                'numeroEtiquette' => $numeroEtiquette,
            ]);

            if (
                $ordreExistant !== null
                && $ordreExistant->getId() !== $ordre->getId()
            ) {
                throw new \InvalidArgumentException(
                    'Ce numéro d’étiquette est déjà affecté à un autre ordre.'
                );
            }

            $ordre->affecterEtiquette(
                $numeroEtiquette,
                $utilisateur
            );

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'L’étiquette %s a été affectée avec succès.',
                    $ordre->getNumeroEtiquette()
                )
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Retire l’étiquette actuellement affectée à l’ordre.
     */
    #[Route(
        '/{id}/etiquette/retirer',
        name: 'retirer_etiquette',
        requirements: ['id' => '\\d+'],
        methods: ['POST']
    )]
    public function retirerEtiquette(
        OrdreProduction $ordre,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_retirer_etiquette_' . $ordre->getId()
        );

        if (!$ordre->aUneEtiquette()) {
            $this->addFlash(
                'error',
                'Aucune étiquette n’est affectée à cet ordre.'
            );

            return $this->redirigerVersOrdre($ordre);
        }

        $ancienNumero = $ordre->getNumeroEtiquette();

        $ordre->retirerEtiquette();
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'L’étiquette %s a été retirée.',
                $ancienNumero
            )
        );

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Affiche la version imprimable de l’étiquette.
     */
    #[Route(
        '/{id}/etiquette/imprimer',
        name: 'imprimer_etiquette',
        requirements: ['id' => '\\d+'],
        methods: ['GET']
    )]

    #[Route(
        '/{id}/etiquette/imprimer',
        name: 'imprimer_etiquette',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function imprimerEtiquette(
        OrdreProduction $ordre
    ): Response {
        $this->utilisateurConnecte();

        if (!$ordre->aUneEtiquette()) {
            throw $this->createNotFoundException(
                'Aucune étiquette n’est affectée à cet ordre.'
            );
        }

        /*
     * URL absolue de la fiche de production.
     */
        $urlProduction = $this->generateUrl(
            'app_production_show',
            [
                'id' => $ordre->getId(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        /*
     * Génération du QR Code.
     */
        $qrCode = new QrCode(
            data: $urlProduction,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $writer = new PngWriter();

        $result = $writer->write($qrCode);

        /*
     * Conversion en Data URI.
     * Aucune image physique n'est créée dans public/.
     */
        $qrCodeDataUri = $result->getDataUri();

        return $this->render(
            'production/etiquette.html.twig',
            [
                'ordre' => $ordre,
                'qrCode' => $qrCodeDataUri,
                'urlProduction' => $urlProduction,
            ]
        );
    }
    /**
     * Enregistre la transmission de l’ordre à la production.
     */
    #[Route(
        '/{id}/transmettre',
        name: 'transmettre',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function transmettre(
        OrdreProduction $ordre,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_transmettre_' . $ordre->getId()
        );

        try {
            $ordre->transmettre();

            $em->flush();

            $this->addFlash(
                'success',
                'L’ordre a été transmis à la production avec succès.'
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Enregistre le début prévu et la durée estimée.
     */
    #[Route(
        '/{id}/planifier',
        name: 'planifier',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function planifier(
        OrdreProduction $ordre,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_planifier_' . $ordre->getId()
        );

        try {
            if ($ordre->estTermine()) {
                throw new \LogicException(
                    'Un ordre terminé ne peut plus être planifié.'
                );
            }

            if ($ordre->estAnnule()) {
                throw new \LogicException(
                    'Un ordre annulé ne peut pas être planifié.'
                );
            }

            $debutPrevuValeur = trim(
                (string) $request->request->get('debut_prevu')
            );

            $dureeValeur = trim(
                (string) $request->request->get(
                    'duree_estimee_minutes'
                )
            );

            $debutPrevu = $this->convertirDateHeure(
                $debutPrevuValeur
            );

            $dureeEstimee = $dureeValeur !== ''
                ? filter_var(
                    $dureeValeur,
                    FILTER_VALIDATE_INT,
                    [
                        'options' => [
                            'min_range' => 1,
                        ],
                    ]
                )
                : null;

            if (
                $dureeValeur !== ''
                && $dureeEstimee === false
            ) {
                throw new \InvalidArgumentException(
                    'La durée estimée doit être un nombre entier supérieur à zéro.'
                );
            }

            if (
                $debutPrevu === null
                && $dureeEstimee === null
            ) {
                throw new \InvalidArgumentException(
                    'Indiquez une date de début ou une durée estimée.'
                );
            }

            $ordre->planifier(
                $debutPrevu,
                $dureeEstimee
            );

            $em->flush();

            $message = 'La production a été planifiée avec succès.';

            if ($ordre->getFinPrevueLe() !== null) {
                $message .= sprintf(
                    ' Fin prévue le %s à %s.',
                    $ordre->getFinPrevueLe()->format('d/m/Y'),
                    $ordre->getFinPrevueLe()->format('H:i')
                );
            }

            $this->addFlash('success', $message);
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirigerVersOrdre($ordre);
    }

    #[Route(
        '/{id}/demarrer',
        name: 'demarrer',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function demarrer(
        OrdreProduction $ordre,
        Request $request,
        MachinesRepository $machinesRepository,
        EntityManagerInterface $em
    ): Response {
        $utilisateur = $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_demarrer_' . $ordre->getId()
        );

        try {
            if (!$ordre->estADemarrer()) {
                throw new \LogicException(
                    'Seul un ordre en attente peut être démarré.'
                );
            }

            $detail = $ordre->getCommandeDetail();

            if ($detail === null) {
                throw new \LogicException(
                    'Aucun détail de commande n’est associé à cet ordre.'
                );
            }

            /*
         * Le BAT est contrôlé AVANT toute modification
         * de l'ordre de production.
         */


            $machine = $this->recupererMachine(
                $request,
                $machinesRepository
            );

            /*
         * 1. Démarrage du détail de commande.
         *
         * C'est ici que son statutProduction passe
         * réellement à en_cours.
         */
            $detail->demarrerProduction(
                $utilisateur,
                $machine
            );

            /*
         * 2. Démarrage de l'ordre de production.
         */
            $ordre->demarrer(
                $utilisateur,
                $machine
            );

            /*
         * Une seule sauvegarde pour les deux objets.
         */
            $em->flush();
            $this->addFlash(
                'success',
                sprintf(
                    'La production a démarré sur la machine %s.',
                    $machine?->getNom() ?? 'du poste'
                )
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Met la production en pause.
     */
    #[Route(
        '/{id}/pause',
        name: 'pause',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function pause(
        OrdreProduction $ordre,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_pause_' . $ordre->getId()
        );

        try {
            $ordre->mettreEnPause();

            $em->flush();

            $this->addFlash(
                'success',
                'La production est maintenant en pause.'
            );
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Reprend une production en pause.
     *
     * La méthode demarrer() de l’entité ferme automatiquement
     * la pause et cumule sa durée.
     */
    #[Route(
        '/{id}/reprendre',
        name: 'reprendre',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function reprendre(
        OrdreProduction $ordre,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $utilisateur = $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'production_reprendre_' . $ordre->getId()
        );

        try {
            if (!$ordre->estEnPause()) {
                throw new \LogicException(
                    'Seul un ordre en pause peut être repris.'
                );
            }

            $ordre->demarrer(
                $utilisateur,
                $ordre->getMachine()
            );

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'La production a été reprise. Temps de pause cumulé : %d minute(s).',
                    $ordre->getDureePauseMinutes()
                )
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Termine la production et enregistre les résultats.
     */
#[Route(
    '/{id}/terminer',
    name: 'terminer',
    requirements: ['id' => '\d+'],
    methods: ['POST']
)]
public function terminer(
    OrdreProduction $ordre,
    Request $request,
    EntityManagerInterface $em,
    StockService $stockService,
    NotificationService $notificationService
): Response {
    $utilisateur = $this->utilisateurConnecte();

    $this->verifierJeton(
        $request,
        'production_terminer_' . $ordre->getId()
    );

    try {
        /*
         * ========================================================
         * CONTRÔLE DE L'ÉTAT
         * ========================================================
         */
        if (!$ordre->estEnCours()) {
            throw new \LogicException(
                'La production doit être en cours avant d’être terminée.'
            );
        }

        $detail = $ordre->getCommandeDetail();

        if ($detail === null) {
            throw new \LogicException(
                'Aucun détail de commande n’est associé à cet ordre.'
            );
        }

        /*
         * Le détail doit déjà avoir été démarré.
         */
        if (!$detail->estEnProduction()) {
            throw new \LogicException(
                'Le détail de commande n’est pas synchronisé avec l’ordre de production.'
            );
        }

        /*
         * ========================================================
         * QUANTITÉS RÉELLES
         * ========================================================
         */
        $quantiteProduite = max(
            0,
            $request->request->getInt(
                'quantite_produite'
            )
        );

        $quantiteRebut = max(
            0,
            $request->request->getInt(
                'quantite_rebut'
            )
        );

        $quantiteTraitee =
            $quantiteProduite
            + $quantiteRebut;

        if ($quantiteTraitee <= 0) {
            throw new \InvalidArgumentException(
                'La quantité produite ou rebut doit être supérieure à zéro.'
            );
        }

        $observation = trim(
            (string) $request->request->get(
                'observation'
            )
        );

        /*
         * ========================================================
         * SORTIE PHYSIQUE DU STOCK
         * ========================================================
         *
         * IMPORTANT :
         *
         * On consomme selon la quantité réellement traitée :
         *
         * conforme + rebut.
         *
         * Exemple :
         * commande = 100
         * conforme = 95
         * rebut = 5
         *
         * consommation matière = 100
         */
        $referenceStock = sprintf(
            'PROD-%06d',
            $ordre->getId()
        );

        $stockService->consommerPourDetail(
            $detail,
            StockSorties::ORIGINE_PRODUCTION,
            $referenceStock,
            (float) $quantiteTraitee
        );

        /*
         * ========================================================
         * TERMINAISON DU DÉTAIL
         * ========================================================
         */
        $detail->terminerProduction(
            $utilisateur,
            $quantiteProduite,
            $quantiteRebut,
            $observation
        );

        /*
         * ========================================================
         * TERMINAISON DE L'ORDRE
         * ========================================================
         */
        $ordre->terminer(
            $utilisateur,
            $quantiteProduite,
            $quantiteRebut,
            $observation
        );

        /*
         * ========================================================
         * COMPTEUR D'USURE MACHINE
         * ========================================================
         *
         * Suivi de l'usage uniquement (maintenance) : n'affecte pas
         * l'amortissement, calculé sur le temps écoulé. Alimente
         * compteurM2 ou compteurFeuilles selon le mode de facturation
         * de la machine ; ignoré si la ligne n'a pas de dimensions
         * (article en stock, saisie libre).
         */
        $machineUtilisee = $ordre->getMachine();

        if ($machineUtilisee instanceof Machines) {
            $largeurDetail = (float) ($detail->getLargeur() ?? 0);
            $longueurDetail = (float) ($detail->getLongueur() ?? 0);

            if ($largeurDetail > 0 && $longueurDetail > 0) {
                $machineUtilisee->enregistrerUsage(
                    $largeurDetail * $longueurDetail * $quantiteTraitee
                );
            }
        }

        /*
         * ========================================================
         * NOTIFICATION
         * ========================================================
         *
         * Prévient la livraison et le commercial que la production
         * est terminée pour cette commande.
         */
        $commande = $detail->getCommande();

        if ($commande !== null) {
            $notificationService->notifierRoles(
                ['ROLE_LIVRAISON', 'ROLE_COMMERCIAL'],
                sprintf(
                    'Production terminée pour la commande %s : prête pour la suite.',
                    $commande->getNumero() ?? ('#' . $commande->getId())
                ),
                'app_commandes_show',
                ['id' => $commande->getId()]
            );
        }

        /*
         * ========================================================
         * SAUVEGARDE ATOMIQUE
         * ========================================================
         *
         * Ce flush enregistre ensemble :
         *
         * - la sortie StockSorties ;
         * - la consommation de StockReservation ;
         * - le détail terminé ;
         * - l'ordre terminé ;
         * - la notification de fin de production.
         */
        $em->flush();

        /*
         * ========================================================
         * MESSAGE
         * ========================================================
         */
        $message = sprintf(
            'Production terminée : %d conforme(s), %d rebut(s).',
            $ordre->getQuantiteConforme(),
            $ordre->getQuantiteRebut()
        );

        if ($ordre->estEnRetard()) {
            $message .= sprintf(
                ' Retard constaté : %d minute(s).',
                $ordre->getRetardMinutes()
            );
        } else {
            $message .=
                ' Le délai prévu a été respecté.';
        }

        $this->addFlash(
            'success',
            $message
        );

    } catch (
        \DomainException |
        \LogicException |
        \InvalidArgumentException $e
    ) {
        $this->addFlash(
            'error',
            $e->getMessage()
        );
    }

    return $this->redirigerVersOrdre(
        $ordre
    );
}

    /**
     * Enregistre un consommable utilisé pendant la production
     * (non prévu dans la nomenclature du produit) : retiré
     * immédiatement du stock disponible.
     */
    #[Route(
        '/{id}/consommable/ajouter',
        name: 'ajouter_consommable',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function ajouterConsommable(
        OrdreProduction $ordre,
        Request $request,
        ArticlesRepository $articlesRepository,
        StockService $stockService
    ): Response {
        $this->verifierJeton(
            $request,
            'production_ajouter_consommable_' . $ordre->getId()
        );

        try {
            if ($ordre->estTermine()) {
                throw new \LogicException(
                    'Cet ordre est terminé, il n’est plus possible d’y ajouter un consommable.'
                );
            }

            $detail = $ordre->getCommandeDetail();

            if ($detail === null) {
                throw new \LogicException(
                    'Aucun détail de commande n’est associé à cet ordre.'
                );
            }

            $articleId = $request->request->getInt('article_id');
            $article = $articlesRepository->find($articleId);

            if (!$article instanceof Articles) {
                throw new \InvalidArgumentException(
                    'Veuillez sélectionner un article.'
                );
            }

            $quantite = $request->request->getInt('quantite');

            $stockService->enregistrerConsommableManuel(
                $detail,
                $article,
                $quantite,
                $ordre->getNumero()
            );

            $this->addFlash(
                'success',
                sprintf(
                    '%s retiré du stock (%d).',
                    (string) $article,
                    $quantite
                )
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Supprime un consommable enregistré par erreur : la sortie de
     * stock correspondante est annulée (le stock redevient
     * disponible).
     */
    #[Route(
        '/{id}/consommable/{sortie}/supprimer',
        name: 'supprimer_consommable',
        requirements: ['id' => '\d+', 'sortie' => '\d+'],
        methods: ['POST']
    )]
    public function supprimerConsommable(
        OrdreProduction $ordre,
        StockSorties $sortie,
        Request $request,
        StockService $stockService
    ): Response {
        $this->verifierJeton(
            $request,
            'production_supprimer_consommable_' . $sortie->getId()
        );

        try {
            if (
                $sortie->getCommandeDetail() !== $ordre->getCommandeDetail()
                || $sortie->getOrigine() !== StockSorties::ORIGINE_MANUELLE
                || $sortie->getReferenceOrigine() !== $ordre->getNumero()
            ) {
                throw new \LogicException(
                    'Ce consommable n’appartient pas à cet ordre de production.'
                );
            }

            $stockService->supprimerConsommableManuel($sortie);

            $this->addFlash(
                'success',
                'Le consommable a été retiré et le stock recrédité.'
            );
        } catch (\LogicException $e) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirigerVersOrdre($ordre);
    }

    /**
     * Annule un ordre non terminé.
     */
    #[Route(
        '/{id}/annuler',
        name: 'annuler',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    

    private function recupererMachine(
        Request $request,
        MachinesRepository $machinesRepository
    ): mixed {
        $machineId = $request->request->getInt('machine_id');

        if ($machineId < 1) {
            return null;
        }

        $machine = $machinesRepository->find($machineId);

        if ($machine === null) {
            throw new \InvalidArgumentException(
                'La machine sélectionnée est introuvable.'
            );
        }

        return $machine;
    }

    private function convertirDateHeure(
        string $valeur
    ): ?\DateTimeImmutable {
        if ($valeur === '') {
            return null;
        }

        /*
         * Format envoyé par un champ HTML datetime-local :
         * 2026-08-06T14:30
         */
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $valeur
        );

        $erreurs = \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array($erreurs)
                && (
                    $erreurs['warning_count'] > 0
                    || $erreurs['error_count'] > 0
                )
            )
        ) {
            throw new \InvalidArgumentException(
                'La date et l’heure de début prévues sont invalides.'
            );
        }

        return $date;
    }

    /**
     * Génère un numéro unique et reproductible à partir de l’ordre.
     */
    private function genererNumeroEtiquette(
        OrdreProduction $ordre
    ): string {
        if ($ordre->getId() === null) {
            throw new \LogicException(
                'L’ordre doit être enregistré avant l’affectation d’une étiquette.'
            );
        }

        return sprintf(
            'ETQ-%s-%06d',
            (new \DateTimeImmutable())->format('Y'),
            $ordre->getId()
        );
    }

    private function utilisateurConnecte(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté.'
            );
        }

        return $user;
    }

    private function verifierJeton(
        Request $request,
        string $id
    ): void {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }
    }

    private function redirigerVersOrdre(
        OrdreProduction $ordre
    ): Response {
        return $this->redirectToRoute(
            'app_production_show',
            ['id' => $ordre->getId()]
        );
    }

    private function detecterMachineCourante(
        Request $request,
        MachinesRepository $machinesRepository
    ): ?Machines {
        $adresseIp = $request->getClientIp();

        if ($adresseIp === null) {
            return null;
        }

        return $machinesRepository->findOneByAdresseIp(
            $adresseIp
        );
    }

    #[Route(
        '/termines',
        name: 'termines',
        methods: ['GET']
    )]
    public function termines(
        Request $request,
        OrdreProductionRepository $ordreProductionRepository,
        MachinesRepository $machinesRepository
    ): Response {
        $recherche = trim(
            (string) $request->query->get('q', '')
        );

        $machineId = $request->query->getInt(
            'machine'
        );

        $operateurId = $request->query->getInt(
            'operateur'
        );

        $dateDebut = trim(
            (string) $request->query->get(
                'date_debut',
                ''
            )
        );

        $dateFin = trim(
            (string) $request->query->get(
                'date_fin',
                ''
            )
        );

        if ($machineId <= 0) {
            $machineId = null;
        }

        if ($operateurId <= 0) {
            $operateurId = null;
        }

        $ordres = $ordreProductionRepository
            ->rechercherOrdresTermines(
                $recherche,
                $machineId,
                $operateurId,
                $dateDebut !== '' ? $dateDebut : null,
                $dateFin !== '' ? $dateFin : null
            );

        $machines = $machinesRepository->findBy(
            [],
            [
                'nom' => 'ASC',
            ]
        );

        /*
     * On construit la liste des opérateurs présents
     * dans les productions terminées.
     */
        $operateurs = [];

        foreach ($ordres as $ordre) {
            $operateur = $ordre->getTerminePar();

            if ($operateur !== null) {
                $operateurs[$operateur->getId()] =
                    $operateur;
            }
        }

        return $this->render(
            'production/termines.html.twig',
            [
                'ordres' => $ordres,
                'machines' => $machines,
                'operateurs' => $operateurs,

                'filtres' => [
                    'q' => $recherche,
                    'machine' => $machineId,
                    'operateur' => $operateurId,
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ],
            ]
        );
    }
    #[Route(
    '/{id}/annuler',
    name: 'annuler',
    requirements: ['id' => '\d+'],
    methods: ['POST']
)]
public function annuler(
    OrdreProduction $ordre,
    Request $request,
    EntityManagerInterface $em,
    StockService $stockService
): Response {
    $this->utilisateurConnecte();

    $this->verifierJeton(
        $request,
        'production_annuler_' . $ordre->getId()
    );

    try {
        $motif = trim(
            (string) $request->request->get(
                'motif'
            )
        );

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif d’annulation est obligatoire.'
            );
        }

        if ($ordre->estTermine()) {
            throw new \LogicException(
                'Un ordre terminé ne peut pas être annulé.'
            );
        }

        if ($ordre->estAnnule()) {
            throw new \LogicException(
                'Cet ordre est déjà annulé.'
            );
        }

        $detail =
            $ordre->getCommandeDetail();

        if ($detail === null) {
            throw new \LogicException(
                'Aucun détail de commande n’est associé à cet ordre.'
            );
        }

        /*
         * ========================================================
         * VÉRIFICATION D'UNE CONSOMMATION DÉJÀ EFFECTUÉE
         * ========================================================
         *
         * Si aucune sortie de stock production n'existe,
         * la matière n'a pas encore été consommée :
         * on peut donc libérer la réservation.
         */
        $referenceStock = sprintf(
            'PROD-%06d',
            $ordre->getId()
        );

        $sortieExistante = $em
            ->getRepository(
                StockSorties::class
            )
            ->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere(
                's.commandeDetail = :detail'
            )
            ->andWhere(
                's.origine = :origine'
            )
            ->andWhere(
                's.referenceOrigine = :reference'
            )
            ->setParameter(
                'detail',
                $detail
            )
            ->setParameter(
                'origine',
                StockSorties::ORIGINE_PRODUCTION
            )
            ->setParameter(
                'reference',
                $referenceStock
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        /*
         * ========================================================
         * LIBÉRATION DES RÉSERVATIONS
         * ========================================================
         */
        if ($sortieExistante === null) {
            $stockService
                ->libererReservationsDetail(
                    $detail
                );
        }

        /*
         * ========================================================
         * ANNULATION DE L'ORDRE
         * ========================================================
         */
        $ordre->annuler(
            $motif
        );

        /*
         * Une seule sauvegarde :
         *
         * - ordre annulé
         * - réservations libérées si nécessaire
         */
        $em->flush();

        if ($sortieExistante === null) {
            $this->addFlash(
                'success',
                'L’ordre de production a été annulé et les réservations de stock ont été libérées.'
            );
        } else {
            $this->addFlash(
                'success',
                'L’ordre de production a été annulé. Une consommation de stock avait déjà été enregistrée et n’a pas été annulée automatiquement.'
            );
        }

    } catch (
        \DomainException |
        \LogicException |
        \InvalidArgumentException $e
    ) {
        $this->addFlash(
            'error',
            $e->getMessage()
        );
    }

    return $this->redirigerVersOrdre(
        $ordre
    );
}
}
