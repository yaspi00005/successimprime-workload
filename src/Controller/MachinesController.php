<?php

namespace App\Controller;

use App\Entity\Machines;
use App\Repository\MachinesRepository;
use App\Service\MachineRentabiliteService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/machines', name: 'app_machines_')]
final class MachinesController extends AbstractController
{
    /*
     * ============================================================
     * LISTE DES MACHINES
     * ============================================================
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        MachinesRepository $machinesRepository
    ): Response {
        return $this->render(
            'machines/index.html.twig',
            [
                'machines' => $machinesRepository->findBy(
                    [],
                    ['nom' => 'ASC']
                ),
            ]
        );
    }

    /*
     * ============================================================
     * RENTABILITÉ DES MACHINES
     * ============================================================
     */
    #[Route('/rentabilite', name: 'rentabilite', methods: ['GET'])]
    public function rentabilite(
        MachineRentabiliteService $machineRentabiliteService
    ): Response {
        return $this->render(
            'machines/rentabilite.html.twig',
            [
                'rentabilites' => $machineRentabiliteService->calculerToutes(),
            ]
        );
    }

    /*
     * ============================================================
     * CRÉATION AJAX
     * ============================================================
     */
    #[Route(
        '/ajax/create',
        name: 'create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        MachinesRepository $machinesRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        /*
         * Vérification CSRF
         */
        $this->verifierJeton(
            $request,
            'create_machine'
        );

        try {
            $machine = new Machines();

            $this->remplirMachineDepuisRequest(
                $machine,
                $request,
                $machinesRepository
            );

            $em->persist($machine);
            $em->flush();

            return $this->json([
                'success' => true,
                'message' => 'La machine a été enregistrée avec succès.',
                'machine' => $this->machineVersTableau($machine),
            ]);
        } catch (
            \InvalidArgumentException |
            \LogicException $e
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_BAD_REQUEST
            );
        } catch (UniqueConstraintViolationException) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Une machine utilisant cette adresse IP existe déjà.',
                ],
                Response::HTTP_CONFLICT
            );
        }
    }

    /*
     * ============================================================
     * RÉCUPÉRATION D'UNE MACHINE
     * ============================================================
     */
    #[Route(
        '/{id}/ajax',
        name: 'get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(
        Machines $machine
    ): JsonResponse {
        return $this->json([
            'success' => true,
            'machine' => $this->machineVersTableau($machine),
        ]);
    }

    /*
     * ============================================================
     * MODIFICATION AJAX
     * ============================================================
     */
    #[Route(
        '/{id}/ajax/update',
        name: 'update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        Machines $machine,
        Request $request,
        MachinesRepository $machinesRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->verifierJeton(
            $request,
            'update_machine_' . $machine->getId()
        );

        try {
            $this->remplirMachineDepuisRequest(
                $machine,
                $request,
                $machinesRepository
            );

            $em->flush();

            return $this->json([
                'success' => true,
                'message' =>
                    'La machine a été modifiée avec succès.',
                'machine' =>
                    $this->machineVersTableau($machine),
            ]);
        } catch (
            \InvalidArgumentException |
            \LogicException $e
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' => $e->getMessage(),
                ],
                Response::HTTP_BAD_REQUEST
            );
        } catch (UniqueConstraintViolationException) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cette adresse IP est déjà utilisée par une autre machine.',
                ],
                Response::HTTP_CONFLICT
            );
        }
    }

    /*
     * ============================================================
     * SUPPRESSION AJAX
     * ============================================================
     */
    #[Route(
        '/{id}/ajax/delete',
        name: 'delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function deleteAjax(
        Machines $machine,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $this->verifierJeton(
            $request,
            'delete_machine_' . $machine->getId()
        );

        /*
         * Une machine utilisée dans l'historique de production
         * ne devrait pas être supprimée.
         */
        if (!$machine->getProductions()->isEmpty()) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cette machine possède déjà un historique de production et ne peut pas être supprimée.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$machine->getCommandesDetails()->isEmpty()) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cette machine est liée à des détails de commande et ne peut pas être supprimée.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$machine->getMaintenances()->isEmpty()) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cette machine possède un historique de maintenance et ne peut pas être supprimée.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        $nom = $machine->getNom();

        $em->remove($machine);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => sprintf(
                'La machine « %s » a été supprimée.',
                $nom
            ),
        ]);
    }

    /*
     * ============================================================
     * REMPLISSAGE / VALIDATION
     * ============================================================
     */
    private function remplirMachineDepuisRequest(
        Machines $machine,
        Request $request,
        MachinesRepository $machinesRepository
    ): void {
        $nom = trim(
            (string) $request->request->get('nom')
        );

        $adresseIp = trim(
            (string) $request->request->get('adresseIp')
        );

        $marque = trim(
            (string) $request->request->get('marque')
        );

        $modeles = trim(
            (string) $request->request->get('modeles')
        );

        $numeroSerie = trim(
            (string) $request->request->get('numeroSerie')
        );

        $typeMachine = trim(
            (string) $request->request->get('typeMachine')
        );

        $largeurImpression = trim(
            (string) $request->request->get(
                'largeurImpression'
            )
        );

        $etat = trim(
            (string) $request->request->get('etat')
        );

        /*
         * Champs obligatoires.
         */
        if ($nom === '') {
            throw new \InvalidArgumentException(
                'Le nom de la machine est obligatoire.'
            );
        }

        if ($adresseIp === '') {
            throw new \InvalidArgumentException(
                'L’adresse IP est obligatoire.'
            );
        }

        /*
         * Validation IPv4 ou IPv6.
         */
        if (
            filter_var(
                $adresseIp,
                FILTER_VALIDATE_IP
            ) === false
        ) {
            throw new \InvalidArgumentException(
                'L’adresse IP saisie est invalide.'
            );
        }

        /*
         * Vérification métier de l'unicité de l'IP.
         */
        $machineIpExistante =
            $machinesRepository->findOneBy([
                'adresseIp' => $adresseIp,
            ]);

        if (
            $machineIpExistante !== null
            && $machineIpExistante->getId()
                !== $machine->getId()
        ) {
            throw new \InvalidArgumentException(
                'Cette adresse IP est déjà affectée à une autre machine.'
            );
        }

        if ($marque === '') {
            throw new \InvalidArgumentException(
                'La marque est obligatoire.'
            );
        }

        if ($modeles === '') {
            throw new \InvalidArgumentException(
                'Le modèle est obligatoire.'
            );
        }

        if ($numeroSerie === '') {
            throw new \InvalidArgumentException(
                'Le numéro de série est obligatoire.'
            );
        }

        if ($typeMachine === '') {
            throw new \InvalidArgumentException(
                'Le type de machine est obligatoire.'
            );
        }

        if ($largeurImpression === '') {
            throw new \InvalidArgumentException(
                'La largeur d’impression est obligatoire.'
            );
        }

        if ($etat === '') {
            $etat = 'disponible';
        }

        /*
         * Nombre de têtes.
         */
        $nbTetesValeur = trim(
            (string) $request->request->get('nbTetes')
        );

        if (
            $nbTetesValeur === ''
            || !ctype_digit($nbTetesValeur)
        ) {
            throw new \InvalidArgumentException(
                'Le nombre de têtes doit être un entier positif.'
            );
        }

        $nbTetes = (int) $nbTetesValeur;

        if ($nbTetes < 0) {
            throw new \InvalidArgumentException(
                'Le nombre de têtes est invalide.'
            );
        }

        /*
         * Compteurs.
         */
        $compteurM2 = $this->recupererDecimal(
            $request,
            'compteurM2',
            0.0
        );

        $compteurHeures = $this->recupererEntier(
            $request,
            'compteurHeures',
            0
        );

        $compteurFeuilles = $this->recupererEntier(
            $request,
            'compteurFeuilles',
            0
        );

        /*
         * Dates.
         */
        $dateAchat = $this->recupererDate(
            $request,
            'dateAchat'
        );

        $dateMiseService = $this->recupererDate(
            $request,
            'dateMiseService'
        );

        /*
         * Rentabilité / amortissement.
         */
        $modeFacturation = trim(
            (string) $request->request->get(
                'modeFacturation',
                Machines::MODE_FACTURATION_METRE_CARRE
            )
        );

        if ($modeFacturation === '') {
            $modeFacturation = Machines::MODE_FACTURATION_METRE_CARRE;
        }

        if (!in_array($modeFacturation, Machines::MODES_FACTURATION, true)) {
            throw new \InvalidArgumentException(
                'Le mode de facturation de la machine est invalide.'
            );
        }

        $prixAchatValeur = trim(
            (string) $request->request->get('prixAchat')
        );
        $prixAchat = $prixAchatValeur === '' ? null : $this->recupererEntier($request, 'prixAchat');

        $dureeAmortissementValeur = trim(
            (string) $request->request->get('dureeAmortissementMois')
        );
        $dureeAmortissementMois = $dureeAmortissementValeur === ''
            ? null
            : $this->recupererEntier($request, 'dureeAmortissementMois');

        $revenuAvantSuivi = $this->recupererEntier(
            $request,
            'revenuAvantSuivi',
            0
        );

        $dateDebutSuivi = $this->recupererDate(
            $request,
            'dateDebutSuivi'
        );

        /*
         * Affectation.
         */
        $machine
            ->setNom($nom)
            ->setAdresseIp($adresseIp)
            ->setMarque($marque)
            ->setModeles($modeles)
            ->setNumeroSerie($numeroSerie)
            ->setTypeMachine($typeMachine)
            ->setLargeurImpression($largeurImpression)
            ->setNbTetes($nbTetes)
            ->setCompteurM2($compteurM2)
            ->setCompteurHeures($compteurHeures)
            ->setCompteurFeuilles($compteurFeuilles)
            ->setEtat($etat)
            ->setModeFacturation($modeFacturation)
            ->setPrixAchat($prixAchat)
            ->setDureeAmortissementMois($dureeAmortissementMois)
            ->setRevenuAvantSuivi($revenuAvantSuivi)
            ->setDateDebutSuivi($dateDebutSuivi);

        if ($dateAchat !== null) {
            $machine->setDateAchat(
                $dateAchat
            );
        }

        if ($dateMiseService !== null) {
            $machine->setDateMiseService(
                $dateMiseService
            );
        }
    }

    /*
     * ============================================================
     * CONVERSION DATE HTML -> DATETIME
     * ============================================================
     */
    private function recupererDate(
        Request $request,
        string $champ
    ): ?\DateTime {
        $valeur = trim(
            (string) $request->request->get($champ)
        );

        if ($valeur === '') {
            return null;
        }

        $date = \DateTime::createFromFormat(
            '!Y-m-d',
            $valeur
        );

        $erreurs = \DateTime::getLastErrors();

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
                sprintf(
                    'La date du champ %s est invalide.',
                    $champ
                )
            );
        }

        return $date;
    }

    /*
     * ============================================================
     * ENTIER PROVENANT D'UN FORMULAIRE
     * ============================================================
     */
    private function recupererEntier(
        Request $request,
        string $champ,
        int $valeurParDefaut = 0
    ): int {
        $valeur = trim(
            (string) $request->request->get($champ)
        );

        if ($valeur === '') {
            return $valeurParDefaut;
        }

        if (!ctype_digit($valeur)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Le champ %s doit contenir un nombre entier.',
                    $champ
                )
            );
        }

        return (int) $valeur;
    }

    /*
     * Comme recupererEntier(), mais accepte les decimales -- utilise
     * pour compteurM2, alimente automatiquement avec des surfaces
     * fractionnaires (voir Machines::enregistrerUsage()).
     */
    private function recupererDecimal(
        Request $request,
        string $champ,
        float $valeurParDefaut = 0.0
    ): float {
        $valeur = trim(
            (string) $request->request->get($champ)
        );

        if ($valeur === '') {
            return $valeurParDefaut;
        }

        $valeur = str_replace(',', '.', $valeur);

        if (!is_numeric($valeur)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Le champ %s doit contenir un nombre.',
                    $champ
                )
            );
        }

        return max(0.0, (float) $valeur);
    }

    /*
     * ============================================================
     * TRANSFORMATION POUR LES RÉPONSES AJAX
     * ============================================================
     */
    private function machineVersTableau(
        Machines $machine
    ): array {
        return [
            'id' => $machine->getId(),
            'nom' => $machine->getNom(),
            'adresseIp' => $machine->getAdresseIp(),
            'marque' => $machine->getMarque(),
            'modeles' => $machine->getModeles(),
            'numeroSerie' => $machine->getNumeroSerie(),
            'typeMachine' => $machine->getTypeMachine(),
            'largeurImpression' =>
                $machine->getLargeurImpression(),
            'nbTetes' => $machine->getNbTetes(),

            'dateAchat' => $machine->getDateAchat()
                ? $machine->getDateAchat()->format('Y-m-d')
                : null,

            'dateMiseService' =>
                $machine->getDateMiseService()
                    ? $machine
                        ->getDateMiseService()
                        ->format('Y-m-d')
                    : null,

            'compteurM2' =>
                $machine->getCompteurM2(),

            'compteurHeures' =>
                $machine->getCompteurHeures(),

            'compteurFeuilles' =>
                $machine->getCompteurFeuilles(),

            'etat' => $machine->getEtat(),

            'modeFacturation' => $machine->getModeFacturation(),
            'prixAchat' => $machine->getPrixAchat(),
            'dureeAmortissementMois' => $machine->getDureeAmortissementMois(),
            'revenuAvantSuivi' => $machine->getRevenuAvantSuivi(),

            'dateDebutSuivi' => $machine->getDateDebutSuivi()
                ? $machine->getDateDebutSuivi()->format('Y-m-d')
                : null,
        ];
    }

    /*
     * ============================================================
     * CSRF
     * ============================================================
     */
    private function verifierJeton(
        Request $request,
        string $id
    ): void {
        $token = (string) $request->request->get(
            '_token'
        );

        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }
    }
}