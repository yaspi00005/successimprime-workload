<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\OrdreProduction;
use App\Entity\User;

use App\Repository\CommandeDetailFichierRepository;
use App\Repository\CommandesDetailsRepository;
use App\Repository\OrdreProductionRepository;

use App\Service\ControleCreditClientService;
use App\Service\BonusPlafondClientService;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\String\Slugger\SluggerInterface;



#[Route('/pre-presse', name: 'app_controle_pre_presse_')]
final class ControlePrePresseController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        CommandesDetailsRepository $detailsRepository
    ): Response {
        /*
         * Affiche uniquement les lignes ayant au moins un fichier
         * ET nécessitant réellement un contrôle prépresse : une
         * ligne en impression directe (prePresseNecessaire = false)
         * ne doit jamais apparaître ici, même si un fichier y est
         * rattaché.
         */
        $details = $detailsRepository
            ->createQueryBuilder('detail')
            ->addSelect('commande', 'produit', 'fichier')
            ->innerJoin('detail.commande', 'commande')
            ->leftJoin('detail.produit', 'produit')
            ->leftJoin(
                'detail.fichiers',
                'fichier',
                'WITH',
                'fichier.actif = :actif'
            )
            ->andWhere('detail.prePresseNecessaire = :prepresseNecessaire')
            ->setParameter('actif', true)
            ->setParameter('prepresseNecessaire', true)
            ->orderBy('commande.dateCommande', 'DESC')
            ->addOrderBy('detail.id', 'DESC')
            ->distinct()
            ->getQuery()
            ->getResult();

        return $this->render('controle_pre_presse/index.html.twig', [
            'details' => $details,
        ]);
    }

    /*
     * ============================================================
     * VÉRIFIER LES NOUVEAUX ÉLÉMENTS (ALERTE SONORE)
     * ============================================================
     *
     * Interrogé périodiquement en JS depuis la file d'attente pour
     * détecter l'arrivée d'une nouvelle ligne "à contrôler" (aucun
     * contrôle prépresse enregistré) et déclencher une alerte
     * sonore tant que personne ne l'a traitée.
     */
    #[Route('/verifier-nouveaux', name: 'verifier_nouveaux', methods: ['GET'])]
    public function verifierNouveaux(
        CommandesDetailsRepository $detailsRepository
    ): JsonResponse {
        $ids = $detailsRepository
            ->createQueryBuilder('detail')
            ->select('detail.id')
            ->innerJoin('detail.fichiers', 'fichier')
            ->leftJoin('detail.controlesPrePresse', 'controle')
            ->andWhere('fichier.actif = :actif')
            ->andWhere('controle.id IS NULL')
            ->andWhere('detail.prePresseNecessaire = :prepresseNecessaire')
            ->setParameter('actif', true)
            ->setParameter('prepresseNecessaire', true)
            ->distinct()
            ->getQuery()
            ->getResult();

        return $this->json([
            'ids' => array_map(
                static fn (array $ligne): int => (int) $ligne['id'],
                $ids
            ),
        ]);
    }

  #[Route(
    '/travail/{id}',
    name: 'controler',
    requirements: ['id' => '\d+'],
    methods: ['GET', 'POST']
)]
public function controler(
    CommandesDetails $detail,
    Request $request,
    EntityManagerInterface $entityManager,
    OrdreProductionRepository $ordreProductionRepository,
    ControleCreditClientService $controleCreditClientService,
    BonusPlafondClientService $bonusPlafondClientService
): Response {
    /*
     * ============================================================
     * UTILISATEUR CONNECTÉ
     * ============================================================
     */

    $utilisateur = $this->getUser();

    if (!$utilisateur instanceof User) {
        throw $this->createAccessDeniedException(
            'Vous devez être connecté pour effectuer un contrôle prépresse.'
        );
    }


    /*
     * ============================================================
     * COMMANDE
     * ============================================================
     */

    $commande = $detail->getCommande();

    if ($commande === null) {
        throw $this->createNotFoundException(
            'Aucune commande n’est associée à ce travail.'
        );
    }


    /*
     * ============================================================
     * VÉRIFICATION DES FICHIERS
     * ============================================================
     */

    if ($detail->getFichiers()->isEmpty()) {
        $this->addFlash(
            'warning',
            'Cette ligne de commande ne contient aucun fichier.'
        );

        return $this->redirectToRoute(
            'app_controle_pre_presse_index'
        );
    }


    /*
     * ============================================================
     * IMPRESSION DIRECTE : PAS DE PRÉPRESSE
     * ============================================================
     */

    if (!$detail->isPrePresseNecessaire()) {
        $this->addFlash(
            'warning',
            'Cette ligne de commande est en impression directe et ne nécessite pas de contrôle prépresse.'
        );

        return $this->redirectToRoute(
            'app_controle_pre_presse_index'
        );
    }


    /*
     * ============================================================
     * CONTRÔLE FINANCIER POUR AFFICHAGE
     * ============================================================
     *
     * Permet d'afficher dans le Twig :
     *
     * - ancienneté du client ;
     * - plafond ;
     * - encours antérieur ;
     * - avance sur la commande actuelle ;
     * - autorisation ou blocage.
     *
     * La commande actuelle reste neutre dans le calcul
     * du plafond.
     * ============================================================
     */

    $controleCredit =
        $controleCreditClientService
            ->analyser(
                $commande
            );


    /*
     * ============================================================
     * TRAITEMENT POST
     * ============================================================
     */

    if ($request->isMethod('POST')) {

        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        $jeton =
            (string) $request
                ->request
                ->get('_token');


        if (
            !$this->isCsrfTokenValid(
                'controle_pre_presse_' . $detail->getId(),
                $jeton
            )
        ) {
            throw $this->createAccessDeniedException(
                'Le jeton de sécurité est invalide.'
            );
        }


        /*
         * ========================================================
         * NOUVEAU CONTRÔLE PRÉPRESSE
         * ========================================================
         */

        $controle =
            new ControlePrePresse();


        $controle->setCommandeDetail(
            $detail
        );


        /*
         * ========================================================
         * FICHIERS SÉLECTIONNÉS
         * ========================================================
         */

        $fichiersSelectionnes =
            $request
                ->request
                ->all('fichiers');


        foreach (
            $fichiersSelectionnes
            as $fichierId
        ) {
            foreach (
                $detail->getFichiers()
                as $fichier
            ) {
                if (
                    $fichier->getId()
                    ===
                    (int) $fichierId
                    &&
                    $fichier->isActif()
                ) {
                    $controle->addFichier(
                        $fichier
                    );
                }
            }
        }


        /*
         * ========================================================
         * AU MOINS UN FICHIER
         * ========================================================
         */

        if (
            $controle
                ->getFichiers()
                ->isEmpty()
        ) {
            $this->addFlash(
                'error',
                'Sélectionnez au moins un fichier à contrôler.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                [
                    'id' =>
                        $detail->getId(),
                ]
            );
        }


        /*
         * ========================================================
         * CONTRÔLES TECHNIQUES
         * ========================================================
         */

        $controle
            ->setFormatConforme(
                $request
                    ->request
                    ->getBoolean(
                        'formatConforme'
                    )
            )
            ->setDimensionsConformes(
                $request
                    ->request
                    ->getBoolean(
                        'dimensionsConformes'
                    )
            )
            ->setResolutionConforme(
                $request
                    ->request
                    ->getBoolean(
                        'resolutionConforme'
                    )
            )
            ->setProfilCouleursConforme(
                $request
                    ->request
                    ->getBoolean(
                        'profilCouleursConforme'
                    )
            )
            ->setFondsPerdusConformes(
                $request
                    ->request
                    ->getBoolean(
                        'fondsPerdusConformes'
                    )
            )
            ->setMargesSecuriteConformes(
                $request
                    ->request
                    ->getBoolean(
                        'margesSecuriteConformes'
                    )
            )
            ->setPolicesConformes(
                $request
                    ->request
                    ->getBoolean(
                        'policesConformes'
                    )
            )
            ->setOrthographeVerifiee(
                $request
                    ->request
                    ->getBoolean(
                        'orthographeVerifiee'
                    )
            )
            ->setOrientationConforme(
                $request
                    ->request
                    ->getBoolean(
                        'orientationConforme'
                    )
            )
            ->setNombrePagesConforme(
                $request
                    ->request
                    ->getBoolean(
                        'nombrePagesConforme'
                    )
            )
            ->setRectoVersoConforme(
                $request
                    ->request
                    ->getBoolean(
                        'rectoVersoConforme'
                    )
            )
            ->setSupportConforme(
                $request
                    ->request
                    ->getBoolean(
                        'supportConforme'
                    )
            )
            ->setQuantiteConforme(
                $request
                    ->request
                    ->getBoolean(
                        'quantiteConforme'
                    )
            )
            ->setFichierDejaTraite(
                $request
                    ->request
                    ->getBoolean(
                        'fichierDejaTraite'
                    )
            )
            ->setBatNecessaire(
                $request
                    ->request
                    ->getBoolean(
                        'batNecessaire'
                    )
            )
            ->setAnomalies(
                $request
                    ->request
                    ->get(
                        'anomalies'
                    )
            )
            ->setCorrectionsEffectuees(
                $request
                    ->request
                    ->get(
                        'correctionsEffectuees'
                    )
            )
            ->setObservation(
                $request
                    ->request
                    ->get(
                        'observation'
                    )
            );


        /*
         * ========================================================
         * BAT
         * ========================================================
         */

        $batValide =
            $request
                ->request
                ->getBoolean(
                    'batValide'
                );


        if (
            $controle
                ->isBatNecessaire()
        ) {
            $controle->setBatValide(
                $batValide
            );
        }


        /*
         * ========================================================
         * DÉBUT DU CONTRÔLE
         * ========================================================
         */

        $controle->commencerControle(
            $utilisateur
        );


        /*
         * ========================================================
         * CORRECTION NÉCESSAIRE
         * ========================================================
         */

        $correctionNecessaire =
            $request
                ->request
                ->getBoolean(
                    'correctionNecessaire'
                );


        $controle->setCorrectionNecessaire(
            $correctionNecessaire
        );


        /*
         * ========================================================
         * ACTION
         * ========================================================
         */

        $action =
            (string) $request
                ->request
                ->get(
                    'action',
                    'enregistrer'
                );


        /*
         * ========================================================
         * VALIDATION DE L'ACTION
         * ========================================================
         */

        if (
            !in_array(
                $action,
                [
                    'enregistrer',
                    'valider',
                    'valider_production',
                ],
                true
            )
        ) {
            $this->addFlash(
                'error',
                'Action prépresse invalide.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                [
                    'id' =>
                        $detail->getId(),
                ]
            );
        }


        /*
         * ========================================================
         * DÉTERMINATION DU CIRCUIT
         * ========================================================
         */

        $validerControle =
            in_array(
                $action,
                [
                    'valider',
                    'valider_production',
                ],
                true
            );


        $envoyerProduction =
            $action
            ===
            'valider_production';


        /*
         * Valeur par défaut :
         * aucun bonus appliqué.
         */
        $bonusPlafond =
            0;


        try {

            /*
             * ====================================================
             * 1. CONTRÔLE FINANCIER AVANT PRODUCTION
             * ====================================================
             *
             * IMPORTANT :
             *
             * Le contrôle doit être fait AVANT d'ajouter le bonus
             * de 1 % de la commande actuelle.
             *
             * Ainsi :
             *
             * - la commande actuelle reste neutre ;
             * - son propre bonus ne peut pas l'aider à passer ;
             * - seules les anciennes dettes sont prises en compte.
             * ====================================================
             */

            if ($envoyerProduction) {

                $controleCredit =
                    $controleCreditClientService
                        ->analyser(
                            $commande
                        );


                if (
                    $controleCredit['autorise']
                    !==
                    true
                ) {
                    throw new \DomainException(
                        $controleCredit[
                            'motif'
                        ]
                    );
                }
            }


            /*
             * ====================================================
             * 2. VALIDATION PRÉPRESSE
             * ====================================================
             */

            if ($validerControle) {

                $controle->valider(
                    $utilisateur
                );


                /*
                 * =================================================
                 * BONUS PLAFOND CLIENT : +1 %
                 * =================================================
                 *
                 * Appliqué après validation réussie.
                 *
                 * Bonus une seule fois par commande.
                 * =================================================
                 */

                $bonusPlafond =
                    $bonusPlafondClientService
                        ->appliquer(
                            $commande
                        );
            }


            /*
             * ====================================================
             * 3. ENVOI EN PRODUCTION
             * ====================================================
             */

            if ($envoyerProduction) {
                $controle
                    ->envoyerEnProduction(
                        $utilisateur
                    );
            }


            /*
             * ====================================================
             * 4. RATTACHEMENT DU CONTRÔLE
             * ====================================================
             */

            $detail
                ->addControlePrePresse(
                    $controle
                );


            $entityManager->persist(
                $controle
            );


            /*
             * ====================================================
             * 5. CRÉATION ORDRE DE PRODUCTION
             * ====================================================
             */

            $ordre = null;


            if ($envoyerProduction) {

                /*
                 * Recherche ordre existant.
                 */
                $ordre =
                    $ordreProductionRepository
                        ->findOneBy([
                            'commandeDetail' =>
                                $detail,
                        ]);


                /*
                 * =================================================
                 * CRÉATION SI AUCUN ORDRE
                 * =================================================
                 */

                if ($ordre === null) {

                    $ordre =
                        new OrdreProduction();


                    $ordre
                        ->setCommandeDetail(
                            $detail
                        )
                        ->setControlePrePresse(
                            $controle
                        )
                        ->setCreePar(
                            $utilisateur
                        )
                        ->setPriorite(
                            $detail
                                ->getPriorite()
                        )
                        ->setQuantite(
                            $detail
                                ->getQuantite()
                        );


                    /*
                     * =============================================
                     * MACHINE
                     * =============================================
                     */

                    if (
                        $detail->getMachine()
                        !==
                        null
                    ) {
                        $ordre->setMachine(
                            $detail
                                ->getMachine()
                        );
                    }


                    /*
                     * =============================================
                     * INSTRUCTIONS
                     * =============================================
                     */

                    if (
                        $detail
                            ->getObservation()
                        !==
                        null
                    ) {
                        $ordre
                            ->setInstructions(
                                $detail
                                    ->getObservation()
                            );
                    }


                    /*
                     * =============================================
                     * DATE LIMITE
                     * =============================================
                     */

                    if (
                        $commande
                            ->getDateLivraison()
                        !==
                        null
                    ) {
                        $dateLivraison =
                            $commande
                                ->getDateLivraison();


                        if (
                            $dateLivraison
                            instanceof
                            \DateTimeImmutable
                        ) {
                            $ordre->setDateLimite(
                                $dateLivraison
                            );
                        } else {
                            $ordre->setDateLimite(
                                \DateTimeImmutable
                                    ::createFromMutable(
                                        $dateLivraison
                                    )
                            );
                        }
                    }


                    /*
                     * =============================================
                     * FICHIERS VALIDÉS
                     * =============================================
                     */

                    foreach (
                        $controle->getFichiers()
                        as $fichier
                    ) {
                        $ordre->addFichier(
                            $fichier
                        );
                    }


                    /*
                     * =============================================
                     * TRANSMISSION
                     * =============================================
                     */

                    $ordre->transmettre();


                    /*
                     * =============================================
                     * STATUT PRODUCTION DU DÉTAIL
                     * =============================================
                     */

                    $detail
                        ->setStatutProduction(
                            CommandesDetails
                                ::PRODUCTION_A_PRODUIRE
                        );


                    $entityManager->persist(
                        $ordre
                    );


                    /*
                     * =============================================
                     * MESSAGE
                     * =============================================
                     */

                    if ($bonusPlafond > 0) {

                        $message =
                            sprintf(
                                'Prépresse validé. L’ordre %s a été transmis à la production. Le plafond du client a augmenté de %s FCFA.',
                                $ordre->getNumero(),
                                number_format(
                                    $bonusPlafond,
                                    0,
                                    ',',
                                    ' '
                                )
                            );

                    } else {

                        $message =
                            sprintf(
                                'Prépresse validé. L’ordre %s a été transmis à la production.',
                                $ordre->getNumero()
                            );
                    }

                } else {

                    /*
                     * =================================================
                     * ORDRE DÉJÀ EXISTANT
                     * =================================================
                     */

                    $message =
                        sprintf(
                            'Le prépresse est validé. L’ordre %s existe déjà en production.',
                            $ordre->getNumero()
                        );
                }

            } elseif (
                $action ===
                'valider'
            ) {

                /*
                 * =================================================
                 * VALIDATION SANS PRODUCTION
                 * =================================================
                 */

                if ($bonusPlafond > 0) {

                    $message =
                        sprintf(
                            'Le contrôle prépresse a été validé. Le plafond du client a augmenté de %s FCFA. Le travail n’a pas encore été envoyé en production.',
                            number_format(
                                $bonusPlafond,
                                0,
                                ',',
                                ' '
                            )
                        );

                } else {

                    $message =
                        'Le contrôle prépresse a été validé. Le travail n’a pas encore été envoyé en production.';
                }

            } else {

                /*
                 * =================================================
                 * SIMPLE ENREGISTREMENT
                 * =================================================
                 */

                $message =
                    'Le contrôle prépresse a été enregistré.';
            }


            /*
             * ====================================================
             * 6. ENREGISTREMENT GLOBAL
             * ====================================================
             *
             * Doctrine sauvegarde dans le même flush :
             *
             * - contrôle prépresse ;
             * - détail ;
             * - ordre ;
             * - nouveau plafond client ;
             * - marqueur bonus de la commande.
             * ====================================================
             */

            $entityManager->flush();


            /*
             * ====================================================
             * MESSAGE SUCCÈS
             * ====================================================
             */

            $this->addFlash(
                'success',
                $message
            );


            /*
             * ====================================================
             * REDIRECTION VERS PRODUCTION
             * ====================================================
             */

            if (
                $envoyerProduction
                &&
                $ordre !== null
            ) {
                return $this->redirectToRoute(
                    'app_production_show',
                    [
                        'id' =>
                            $ordre->getId(),
                    ]
                );
            }


            /*
             * ====================================================
             * RETOUR PRÉPRESSE
             * ====================================================
             */

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                [
                    'id' =>
                        $detail->getId(),
                ]
            );

        } catch (
            \LogicException |
            \DomainException |
            \InvalidArgumentException $exception
        ) {

            /*
             * ====================================================
             * ERREUR MÉTIER
             * ====================================================
             */

            $this->addFlash(
                'error',
                $exception->getMessage()
            );
        }
    }


    /*
     * ============================================================
     * RECALCUL APRÈS POST / POUR AFFICHAGE GET
     * ============================================================
     */

    $controleCredit =
        $controleCreditClientService
            ->analyser(
                $commande
            );


    /*
     * ============================================================
     * AFFICHAGE
     * ============================================================
     */

    return $this->render(
        'controle_pre_presse/controler.html.twig',
        [
            'detail' =>
                $detail,

            'commande' =>
                $commande,

            'controleCredit' =>
                $controleCredit,
        ]
    );
}
    #[Route(
        '/travail/{id}/fichier-traite',
        name: 'ajouter_fichier_traite',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function ajouterFichierTraite(
        CommandesDetails $detail,
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger
    ): Response {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté.'
            );
        }

        if (!$this->isCsrfTokenValid(
            'ajouter_fichier_traite_' . $detail->getId(),
            (string) $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Le jeton de sécurité est invalide.'
            );
        }
        /** @var UploadedFile|null $fichierUpload */
        $fichierUpload = $request->files->get('fichierTraite');

        if (!$fichierUpload instanceof UploadedFile) {
            $this->addFlash(
                'error',
                'Veuillez sélectionner un fichier traité.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                ['id' => $detail->getId()]
            );
        }

        if (!$fichierUpload->isValid()) {
            $this->addFlash(
                'error',
                'Le fichier n’a pas pu être envoyé correctement.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                ['id' => $detail->getId()]
            );
        }

        /*
     * 600 Mo maximum.
     * Vérifiez également upload_max_filesize et post_max_size dans php.ini.
     */
        $tailleMaximale = 600 * 1024 * 1024;

        if ($fichierUpload->getSize() > $tailleMaximale) {
            $this->addFlash(
                'error',
                'Le fichier dépasse la taille maximale autorisée de 600 Mo.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                ['id' => $detail->getId()]
            );
        }

        $fichierSourceId = $request->request->getInt('fichierSource');

        $fichierSource = null;

        foreach ($detail->getFichiers() as $fichierDetail) {
            if ($fichierDetail->getId() === $fichierSourceId) {
                $fichierSource = $fichierDetail;
                break;
            }
        }

        if (!$fichierSource instanceof CommandeDetailFichier) {
            $this->addFlash(
                'error',
                'Sélectionnez le fichier original correspondant.'
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                ['id' => $detail->getId()]
            );
        }

        /*
         * Ces métadonnées doivent être lues avant move(), car le fichier
         * temporaire PHP n'existe plus à son ancien emplacement ensuite.
         */
        $nomOriginal = $fichierUpload->getClientOriginalName();
        $taille = (int) ($fichierUpload->getSize() ?: 0);
        $typeMime = $fichierUpload->getMimeType()
            ?: $fichierUpload->getClientMimeType()
            ?: 'application/octet-stream';
        $nomSansExtension = pathinfo(
            $nomOriginal,
            PATHINFO_FILENAME
        );

        $nomSecurise = $slugger
            ->slug($nomSansExtension)
            ->lower();

        $extension = $fichierUpload->guessExtension()
            ?: $fichierUpload->getClientOriginalExtension()
            ?: 'bin';

        $nomStockage = sprintf(
            '%s-v%d-%s.%s',
            $nomSecurise,
            $fichierSource->getVersion() + 1,
            bin2hex(random_bytes(8)),
            strtolower($extension)
        );

        $repertoireRelatif = sprintf(
            'uploads/prepresse/commande_%d/detail_%d',
            $detail->getCommande()->getId(),
            $detail->getId()
        );

        $repertoireAbsolu = $this->getParameter('kernel.project_dir')
            . '/public/'
            . $repertoireRelatif;

        try {
            $fichierDeplace = $fichierUpload->move(
                $repertoireAbsolu,
                $nomStockage
            );
        } catch (\Throwable $exception) {
            $this->addFlash(
                'error',
                'Impossible d’enregistrer le fichier traité : '
                    . $exception->getMessage()
            );

            return $this->redirectToRoute(
                'app_controle_pre_presse_controler',
                ['id' => $detail->getId()]
            );
        }

        $fichierTraite = new CommandeDetailFichier();

        $fichierTraite
            ->setCommandeDetail($detail)
            ->setFichierSource($fichierSource)
            ->setJetonUpload(bin2hex(random_bytes(32)))
            ->setNomOriginal($nomOriginal)
            ->setNomStockage($nomStockage)
            ->setChemin($repertoireRelatif . '/' . $nomStockage)
            ->setTypeMime($typeMime)
            ->setTaille($taille)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_TRAITE)
            ->setVersion($fichierSource->getVersion() + 1)
            ->setFace(
                $request->request->get('face') ?: $fichierSource->getFace()
            )
            ->setDesignation(
                $request->request->get('designation')
                    ?: 'Fichier traité'
            )
            ->setGroupeFichier(
                $fichierSource->getGroupeFichier()
            )
            ->setQuantiteAProduire(
                $fichierSource->getQuantiteAProduire()
            )
            ->setObservation(
                $request->request->get('observation')
            )
            ->setAjoutePar($utilisateur)
            ->marquerUploadTermine();

        $detail->addFichier($fichierTraite);

        $entityManager->persist($fichierTraite);
        $entityManager->flush();

        $this->addFlash(
            'success',
            'Le fichier traité a été ajouté avec succès.'
        );

        return $this->redirectToRoute(
            'app_controle_pre_presse_controler',
            ['id' => $detail->getId()]
        );
    }

    /*
     * ============================================================
     * ENVOI PAR MORCEAUX (fichier traité)
     * ============================================================
     *
     * Les fichiers traités en pré-presse (TIFF haute résolution,
     * scans...) peuvent être très volumineux. Plutôt que de dépendre
     * uniquement des réglages upload_max_filesize/post_max_size de
     * PHP (souvent trop bas par défaut, et pas toujours modifiables
     * facilement sur le serveur du client), l'envoi se fait ici
     * découpé en petits morceaux, sur le même principe que
     * FichierUploadController (déjà utilisé pour les fichiers
     * originaux du client) : chaque morceau est une requête HTTP
     * indépendante et légère, réassemblée une fois tous les morceaux
     * reçus. L'ancienne route ajouterFichierTraite() reste en place
     * en secours (si JavaScript est indisponible).
     *
     * Le plafond ci-dessous ne dépend plus des limites PHP
     * (upload_max_filesize/post_max_size, prévues pour un envoi en un
     * seul bloc) : chaque morceau est petit quelle que soit la taille
     * totale du fichier. Il protège seulement l'espace disque du
     * serveur contre un envoi anormalement énorme — les fichiers
     * prépresse de plusieurs gigaoctets (TIFF haute résolution, grand
     * format) restent donc acceptés.
     */
    private const TAILLE_MAX_MORCEAUX = 5 * 1024 * 1024 * 1024;
    private const NOMBRE_MORCEAUX_MAX = 10000;

    #[Route(
        '/travail/{id}/fichier-traite/initialiser',
        name: 'fichier_traite_initialiser',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function initialiserFichierTraite(
        CommandesDetails $detail,
        Request $request,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger
    ): JsonResponse {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            return $this->json(
                ['message' => 'Vous devez être connecté.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$this->isCsrfTokenValid(
            'ajouter_fichier_traite_' . $detail->getId(),
            (string) $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json(
                ['message' => 'Jeton de sécurité invalide.'],
                Response::HTTP_FORBIDDEN
            );
        }

        try {
            $donnees = $request->toArray();
        } catch (\Throwable) {
            return $this->json(
                ['message' => 'Le corps JSON de la requête est invalide.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $nomOriginal = trim((string) ($donnees['nom'] ?? ''));
        $typeMime = trim((string) ($donnees['typeMime'] ?? ''))
            ?: 'application/octet-stream';
        $taille = (int) ($donnees['taille'] ?? 0);
        $nombreMorceaux = (int) ($donnees['nombreMorceaux'] ?? 0);
        $fichierSourceId = (int) ($donnees['fichierSource'] ?? 0);

        if ($nomOriginal === '' || $taille <= 0 || $nombreMorceaux <= 0) {
            return $this->json(
                ['message' => 'Informations du fichier invalides.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($taille > self::TAILLE_MAX_MORCEAUX) {
            return $this->json(
                ['message' => sprintf(
                    'Le fichier dépasse la taille maximale autorisée de %d Go.',
                    (int) (self::TAILLE_MAX_MORCEAUX / 1024 / 1024 / 1024)
                )],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($nombreMorceaux > self::NOMBRE_MORCEAUX_MAX) {
            return $this->json(
                ['message' => 'Le fichier est trop volumineux pour être envoyé par morceaux.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $fichierSource = null;

        foreach ($detail->getFichiers() as $fichierDetail) {
            if ($fichierDetail->getId() === $fichierSourceId) {
                $fichierSource = $fichierDetail;
                break;
            }
        }

        if (!$fichierSource instanceof CommandeDetailFichier) {
            return $this->json(
                ['message' => 'Sélectionnez le fichier original correspondant.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $nomSansExtension = pathinfo($nomOriginal, PATHINFO_FILENAME);
        $nomSecurise = $slugger->slug($nomSansExtension)->lower();
        $extension = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION)) ?: 'bin';

        $jeton = bin2hex(random_bytes(32));

        $nomStockage = sprintf(
            '%s-v%d-%s.%s',
            $nomSecurise,
            $fichierSource->getVersion() + 1,
            bin2hex(random_bytes(8)),
            $extension
        );

        $repertoireRelatif = sprintf(
            'uploads/prepresse/commande_%d/detail_%d',
            $detail->getCommande()->getId(),
            $detail->getId()
        );

        /*
         * Préfixe la désignation de la ligne de commande, comme pour
         * l'envoi classique (ajouterFichierTraite).
         */
        $designationDetail = trim((string) $detail->getDesignation());

        $nomOriginalAffiche = $designationDetail !== ''
            ? sprintf('%s - %s', $designationDetail, $nomOriginal)
            : $nomOriginal;

        $face = trim((string) ($donnees['face'] ?? ''));
        $designationFichier = trim((string) ($donnees['designation'] ?? ''));

        $fichierTraite = new CommandeDetailFichier();

        $fichierTraite
            ->setJetonUpload($jeton)
            ->setCommandeDetail($detail)
            ->setFichierSource($fichierSource)
            ->setNomOriginal($nomOriginalAffiche)
            ->setNomStockage($nomStockage)
            ->setChemin($repertoireRelatif . '/' . $nomStockage)
            ->setTypeMime($typeMime)
            ->setTaille($taille)
            ->setNombreMorceaux($nombreMorceaux)
            ->setMorceauxRecus(0)
            ->setStatut(CommandeDetailFichier::STATUT_EN_COURS)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_TRAITE)
            ->setVersion($fichierSource->getVersion() + 1)
            ->setFace($face !== '' ? $face : $fichierSource->getFace())
            ->setDesignation($designationFichier !== '' ? $designationFichier : 'Fichier traité')
            ->setGroupeFichier($fichierSource->getGroupeFichier())
            ->setQuantiteAProduire($fichierSource->getQuantiteAProduire())
            ->setObservation(
                trim((string) ($donnees['observation'] ?? '')) ?: null
            )
            ->setAjoutePar($utilisateur);

        $detail->addFichier($fichierTraite);

        $entityManager->persist($fichierTraite);
        $entityManager->flush();

        return $this->json([
            'jeton' => $jeton,
            'morceauxRecus' => 0,
            'nombreMorceaux' => $nombreMorceaux,
        ], Response::HTTP_CREATED);
    }

    #[Route(
        '/fichier-traite/{jeton}/morceaux/{index}',
        name: 'fichier_traite_morceau',
        requirements: [
            'jeton' => '[a-f0-9]{64}',
            'index' => '\d+',
        ],
        methods: ['POST']
    )]
    public function envoyerMorceauFichierTraite(
        string $jeton,
        int $index,
        Request $request,
        CommandeDetailFichierRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            return $this->json(
                ['message' => 'Vous devez être connecté.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $fichier = $repository->findOneBy(['jetonUpload' => $jeton]);

        if (!$fichier instanceof CommandeDetailFichier) {
            return $this->json(
                ['message' => 'Session d’upload introuvable.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $detailId = $fichier->getCommandeDetail()?->getId();

        if (
            $detailId === null
            || !$this->isCsrfTokenValid(
                'ajouter_fichier_traite_' . $detailId,
                (string) $request->headers->get('X-CSRF-TOKEN')
            )
        ) {
            return $this->json(
                ['message' => 'Jeton de sécurité invalide.'],
                Response::HTTP_FORBIDDEN
            );
        }

        if ($fichier->getStatut() === CommandeDetailFichier::STATUT_TERMINE) {
            return $this->json([
                'jeton' => $jeton,
                'statut' => CommandeDetailFichier::STATUT_TERMINE,
                'progression' => 100,
            ]);
        }

        $nombreMorceaux = $fichier->getNombreMorceaux();

        if (
            $nombreMorceaux === null
            || $index < 0
            || $index >= $nombreMorceaux
        ) {
            return $this->json(
                ['message' => 'Index du morceau invalide.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var UploadedFile|null $morceau */
        $morceau = $request->files->get('morceau');

        if (!$morceau instanceof UploadedFile || !$morceau->isValid()) {
            return $this->json(
                ['message' => 'Morceau absent ou invalide.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $dossierTemporaire = $this->getParameter('kernel.project_dir')
            . '/var/uploads/prepresse_tmp/' . $jeton;

        if (
            !is_dir($dossierTemporaire)
            && !mkdir($dossierTemporaire, 0775, true)
            && !is_dir($dossierTemporaire)
        ) {
            return $this->json(
                ['message' => 'Impossible de créer le dossier temporaire.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $cheminMorceau = $dossierTemporaire . '/' . sprintf('%08d.part', $index);

        /*
         * Si le morceau existe déjà, on ne le compte pas deux fois :
         * cela permet de reprendre un transfert interrompu.
         */
        if (!is_file($cheminMorceau)) {
            $morceau->move($dossierTemporaire, basename($cheminMorceau));
        }

        $morceauxRecus = count(glob($dossierTemporaire . '/*.part') ?: []);

        $fichier->setMorceauxRecus($morceauxRecus);

        if ($morceauxRecus === $nombreMorceaux) {
            try {
                $this->assemblerFichierTraite($fichier, $dossierTemporaire);
            } catch (\Throwable $exception) {
                return $this->json(
                    ['message' => $exception->getMessage()],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        }

        $entityManager->flush();

        $progression = (int) floor(
            ($fichier->getMorceauxRecus() / $nombreMorceaux) * 100
        );

        return $this->json([
            'jeton' => $jeton,
            'morceauxRecus' => $fichier->getMorceauxRecus(),
            'nombreMorceaux' => $nombreMorceaux,
            'progression' => min(100, $progression),
            'statut' => $fichier->getStatut(),
        ]);
    }

    private function assemblerFichierTraite(
        CommandeDetailFichier $fichier,
        string $dossierTemporaire
    ): void {
        $projet = (string) $this->getParameter('kernel.project_dir');
        $chemin = (string) $fichier->getChemin();

        $repertoireAbsolu = $projet . '/public/' . dirname($chemin);

        if (
            !is_dir($repertoireAbsolu)
            && !mkdir($repertoireAbsolu, 0775, true)
            && !is_dir($repertoireAbsolu)
        ) {
            throw new \RuntimeException(
                'Impossible de créer le dossier final.'
            );
        }

        $cheminFinal = $projet . '/public/' . $chemin;
        $cheminAssemblage = $cheminFinal . '.assemblage';

        $sortie = fopen($cheminAssemblage, 'wb');

        if ($sortie === false) {
            throw new \RuntimeException(
                'Impossible de créer le fichier final.'
            );
        }

        try {
            for (
                $index = 0;
                $index < (int) $fichier->getNombreMorceaux();
                $index++
            ) {
                $cheminMorceau = $dossierTemporaire . '/' . sprintf('%08d.part', $index);

                if (!is_file($cheminMorceau)) {
                    throw new \RuntimeException(
                        sprintf('Le morceau %d est absent.', $index)
                    );
                }

                $entree = fopen($cheminMorceau, 'rb');

                if ($entree === false) {
                    throw new \RuntimeException(
                        sprintf('Impossible de lire le morceau %d.', $index)
                    );
                }

                stream_copy_to_stream($entree, $sortie);
                fclose($entree);
            }
        } catch (\Throwable $exception) {
            @unlink($cheminAssemblage);
            throw $exception;
        } finally {
            fclose($sortie);
        }

        $tailleReelle = filesize($cheminAssemblage);

        if ($tailleReelle === false || $tailleReelle !== $fichier->getTaille()) {
            @unlink($cheminAssemblage);

            throw new \RuntimeException(
                'La taille du fichier assemblé est incorrecte.'
            );
        }

        if (!rename($cheminAssemblage, $cheminFinal)) {
            @unlink($cheminAssemblage);
            throw new \RuntimeException(
                'Impossible de finaliser le fichier assemblé.'
            );
        }

        $typeMime = mime_content_type($cheminFinal);

        if (is_string($typeMime)) {
            $fichier->setTypeMime($typeMime);
        }

        $fichier->marquerUploadTermine();

        foreach (glob($dossierTemporaire . '/*.part') ?: [] as $morceau) {
            @unlink($morceau);
        }

        @rmdir($dossierTemporaire);
    }
    /*
     * Retrouve le chemin reel sur disque d'un fichier de commande,
     * quel que soit le circuit d'upload par lequel il est arrive :
     *   - fichiers "traites" en pre-presse (ajouterFichierTraite) :
     *     stockes sous public/{chemin} (chemin relatif enregistre
     *     en base) ;
     *   - fichiers originaux du client (FichierUploadController) :
     *     stockes a plat sous var/uploads/commandes/{nomStockage},
     *     sans valeur de "chemin" en base.
     * Sans ceci, seul le deuxieme circuit fonctionnait : visualiser
     * ou telecharger un fichier traite renvoyait une 404.
     */
    private function resoudreCheminFichierStocke(
        CommandeDetailFichier $fichier
    ): ?string {
        $projet = (string) $this->getParameter('kernel.project_dir');
        $chemin = trim((string) $fichier->getChemin());

        if ($chemin !== '') {
            $racinePublique = realpath($projet . '/public');

            if ($racinePublique !== false) {
                $cheminComplet = realpath(
                    $projet . '/public/' . $chemin
                );

                if (
                    $cheminComplet !== false
                    && str_starts_with(
                        $cheminComplet,
                        $racinePublique . DIRECTORY_SEPARATOR
                    )
                    && is_file($cheminComplet)
                    && is_readable($cheminComplet)
                ) {
                    return $cheminComplet;
                }
            }
        }

        /*
     * Repertoire reel de stockage (circuit historique) :
     * var/uploads/commandes
     */
        $racineStockage = $projet . '/var/uploads/commandes';
        $racineReelle = realpath($racineStockage);

        if ($racineReelle === false || !is_dir($racineReelle)) {
            return null;
        }

        $nomStockage = basename(
            trim((string) $fichier->getNomStockage())
        );

        if ($nomStockage === '' || $nomStockage === '.') {
            return null;
        }

        $cheminRecherche = $racineStockage
            . DIRECTORY_SEPARATOR
            . $nomStockage;

        $cheminComplet = realpath($cheminRecherche);

        /*
     * Sécurité :
     * le fichier doit obligatoirement rester dans
     * var/uploads/commandes.
     */
        if (
            $cheminComplet === false
            || !str_starts_with(
                $cheminComplet,
                $racineReelle . DIRECTORY_SEPARATOR
            )
            || !is_file($cheminComplet)
            || !is_readable($cheminComplet)
        ) {
            return null;
        }

        /*
     * Détection réelle du type MIME.
     * On ne dépend pas seulement de la valeur enregistrée en base.
     */
        $extension = strtolower(
            pathinfo($cheminComplet, PATHINFO_EXTENSION)
        );

        $extensionsImages = [
            'jpg',
            'jpeg',
            'png',
            'webp',
        ];

        if (in_array($extension, $extensionsImages, true)) {
            $racineApercus = $racineReelle
                . DIRECTORY_SEPARATOR
                . 'apercus';

            $cheminApercu = $racineApercus
                . DIRECTORY_SEPARATOR
                . basename($cheminComplet);

            if (is_file($cheminApercu) && is_readable($cheminApercu)) {
                $cheminComplet = $cheminApercu;
            }
        }

        return $cheminComplet;
    }
    #[Route(
        '/fichier/{id}/visualiser',
        name: 'visualiser_fichier',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function visualiserFichier(
        CommandeDetailFichier $fichier
    ): BinaryFileResponse {
        if (!$fichier->isActif()) {
            throw $this->createNotFoundException(
                'Le fichier demandé est indisponible.'
            );
        }

        $cheminComplet = $this->resoudreCheminFichierStocke($fichier);

        if ($cheminComplet === null) {
            throw $this->createNotFoundException(
                'Le fichier est introuvable sur le serveur.'
            );
        }
        $typeMime = mime_content_type($cheminComplet);

        if ($typeMime === false) {
            $typeMime = $fichier->getTypeMime()
                ?: 'application/octet-stream';
        }

        $nomOriginal = trim(
            (string) $fichier->getNomOriginal()
        );

        if ($nomOriginal === '') {
            $nomOriginal = basename($cheminComplet);
        }

        $response = new BinaryFileResponse($cheminComplet);

        $response->headers->set('Content-Type', $typeMime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, max-age=0');

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $nomOriginal
        );

        return $response;
    }
    #[Route(
        '/fichier/{id}/telecharger',
        name: 'telecharger_fichier',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function telechargerFichier(
        CommandeDetailFichier $fichier
    ): BinaryFileResponse {
        if (!$fichier->isActif()) {
            throw $this->createNotFoundException(
                'Le fichier demandé est indisponible.'
            );
        }

        $cheminComplet = $this->resoudreCheminFichierStocke($fichier);

        if ($cheminComplet === null) {
            throw $this->createNotFoundException(
                'Le fichier original est introuvable sur le serveur.'
            );
        }

        $nomOriginal = trim(
            (string) $fichier->getNomOriginal()
        );

        if ($nomOriginal === '') {
            $nomOriginal = basename($cheminComplet);
        }

        $response = new BinaryFileResponse($cheminComplet);

        /*
     * ATTACHMENT force le téléchargement.
     * La route de visualisation utilise INLINE.
     */
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $nomOriginal
        );

        $typeMime = mime_content_type($cheminComplet);

        $response->headers->set(
            'Content-Type',
            $typeMime ?: 'application/octet-stream'
        );

        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff'
        );

        return $response;
    }
}
