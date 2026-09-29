<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\Factures;
use App\Form\FacturesType;
use App\Repository\FacturesRepository;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Dompdf\Dompdf;
use Dompdf\Options;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/factures')]
class FacturesController extends AbstractController
{
    /*
     * ============================================================
     * LISTE
     * ============================================================
     */
    #[Route(
        '/',
        name: 'app_factures_index',
        methods: ['GET']
    )]
    public function index(
        FacturesRepository $facturesRepository
    ): Response {
        $factures =
            $facturesRepository->findBy(
                [],
                [
                    'dateFacture' => 'DESC',
                    'id' => 'DESC',
                ]
            );

        return $this->render(
            'factures/index.html.twig',
            [
                'factures' => $factures,
            ]
        );
    }


    /*
     * ============================================================
     * CRÉER MANUELLEMENT UN DOCUMENT DE FACTURATION
     * ============================================================
     */
    #[Route(
        '/commande/{id}/nouveau',
        name: 'app_factures_nouveau',
        methods: ['GET', 'POST']
    )]
    public function nouveau(
        Commandes $commande,
        Request $request,
        EntityManagerInterface $entityManager,
        FacturesRepository $facturesRepository
    ): Response {
        /*
         * --------------------------------------------------------
         * Création du document
         * --------------------------------------------------------
         */
        $facture = new Factures();

        /*
         * On copie les montants actuels de la commande.
         *
         * IMPORTANT :
         * cette copie est faite maintenant seulement.
         *
         * Une fois enregistré, le document reste figé.
         */
        $facture->chargerDepuisCommande(
            $commande
        );

        /*
         * Valeurs par défaut.
         */
        $facture->setTypeDocument(
            Factures::TYPE_FACTURE
        );

        $facture->setEmetteur(
            Factures::EMETTEUR_MDG_SUCCESS
        );


        /*
         * --------------------------------------------------------
         * Formulaire
         * --------------------------------------------------------
         */
        $form =
            $this->createForm(
                FacturesType::class,
                $facture
            );

        $form->handleRequest(
            $request
        );


        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            /*
             * ====================================================
             * TYPE DE DOCUMENT
             * ====================================================
             */
            $typeDocument =
                $facture->getTypeDocument();


            /*
             * ====================================================
             * FACTURE OFFICIELLE
             * ====================================================
             *
             * Une seule facture officielle comptabilisée
             * est autorisée pour une commande.
             */
            if (
                $typeDocument
                === Factures::TYPE_FACTURE
            ) {
                $factureComptableExistante =
                    $facturesRepository->findOneBy(
                        [
                            'commande' => $commande,
                            'comptabilisee' => true,
                        ]
                    );

                if (
                    $factureComptableExistante
                    !== null
                ) {
                    $form->get('typeDocument')
                        ->addError(
                            new FormError(
                                sprintf(
                                    'La commande possède déjà '
                                        . 'une facture officielle '
                                        . 'comptabilisée : %s.',
                                    $factureComptableExistante
                                        ->getNumero()
                                        ?? '#'
                                        . $factureComptableExistante
                                        ->getId()
                                )
                            )
                        );
                }
            }


            /*
             * ====================================================
             * MAJORATION
             * ====================================================
             */
            if (
                $form->isValid()
                && $facture->isSurfacturation()
            ) {
                $taux =
                    $facture
                    ->getTauxSurfacturation();

                $montant =
                    $facture
                    ->getMontantSurfacturation();


                /*
                 * On ne permet pas de renseigner
                 * pourcentage + montant fixe simultanément.
                 */
                if (
                    $taux !== null
                    && $taux > 0
                    && $montant > 0
                ) {
                    $form
                        ->get('tauxSurfacturation')
                        ->addError(
                            new FormError(
                                'Choisissez soit un taux, '
                                    . 'soit un montant fixe, '
                                    . 'mais pas les deux.'
                            )
                        );

                    $form
                        ->get('montantSurfacturation')
                        ->addError(
                            new FormError(
                                'Choisissez soit un taux, '
                                    . 'soit un montant fixe.'
                            )
                        );
                }


                /*
                 * Si surfacturation activée,
                 * au moins une valeur doit être renseignée.
                 */
                if (
                    ($taux === null || $taux <= 0)
                    && $montant <= 0
                ) {
                    $form
                        ->get('surfacturation')
                        ->addError(
                            new FormError(
                                'Indiquez un taux ou '
                                    . 'un montant de majoration.'
                            )
                        );
                }


                /*
                 * La majoration n'est appliquée
                 * qu'aux documents non comptabilisés.
                 *
                 * La facture comptable conserve
                 * les montants réels.
                 */
                if (
                    $typeDocument
                    === Factures::TYPE_FACTURE
                ) {
                    $form
                        ->get('surfacturation')
                        ->addError(
                            new FormError(
                                'La facture officielle '
                                    . 'comptabilisée doit conserver '
                                    . 'les montants réels de la commande. '
                                    . 'Utilisez une pro forma / simulation '
                                    . 'pour appliquer une majoration.'
                            )
                        );
                }
            }


            /*
             * ====================================================
             * ENREGISTREMENT
             * ====================================================
             */
            if ($form->isValid()) {

                /*
                 * Facture officielle :
                 * comptabilisée.
                 */
                if (
                    $typeDocument
                    === Factures::TYPE_FACTURE
                ) {
                    $facture->setComptabilisee(
                        true
                    );

                    /*
                     * Sécurité :
                     * aucune majoration.
                     */
                    $facture->setSurfacturation(
                        false
                    );
                }


                /*
                 * Pro forma / simulation :
                 * jamais comptabilisée.
                 */
                if (
                    $typeDocument
                    === Factures::TYPE_PROFORMA
                ) {
                    $facture->setComptabilisee(
                        false
                    );
                }


                /*
                 * ------------------------------------------------
                 * Calcul de la majoration %
                 * ------------------------------------------------
                 */
                if (
                    !$facture->isComptabilisee()
                    && $facture->isSurfacturation()
                ) {
                    $taux =
                        $facture
                        ->getTauxSurfacturation();

                    /*
                     * Si on utilise un taux,
                     * l'entité calcule automatiquement
                     * le montant.
                     */
                    if (
                        $taux !== null
                        && $taux > 0
                    ) {
                        $facture
                            ->appliquerTauxSurfacturation(
                                $taux
                            );
                    }
                }


                /*
                 * ------------------------------------------------
                 * Situation financière facture officielle
                 * ------------------------------------------------
                 */
                if (
                    $facture->isComptabilisee()
                ) {
                    $facture
                        ->synchroniserPaiementsDepuisCommande();
                }


                /*
                 * ------------------------------------------------
                 * État
                 * ------------------------------------------------
                 *
                 * Le document est d'abord enregistré
                 * comme brouillon.
                 *
                 * Il pourra être émis ensuite.
                 */
                $facture->setEtat(
                    Factures::ETAT_BROUILLON
                );


                /*
                 * ------------------------------------------------
                 * Token
                 * ------------------------------------------------
                 */
                $facture
                    ->genererTokenAuthenticite();


                /*
                 * ------------------------------------------------
                 * Premier persist
                 * ------------------------------------------------
                 */
                $entityManager->persist(
                    $facture
                );

                $entityManager->flush();


                /*
                 * =================================================
                 * NUMÉRO
                 * =================================================
                 */

                if (
                    $facture->estFacture()
                ) {
                    $prefixe = 'FAC';
                } else {
                    $prefixe = 'PF';
                }


                /*
                 * Exemples :
                 *
                 * FAC-000001-08-2026
                 * PF-000002-08-2026
                 */
                $facture->setNumero(
                    sprintf(
                        '%s-%06d-%s',
                        $prefixe,
                        $facture->getId(),
                        $facture
                            ->getDateFacture()
                            ->format('m-Y')
                    )
                );


                /*
                 * ------------------------------------------------
                 * Paiements
                 * ------------------------------------------------
                 *
                 * On rattache les paiements validés
                 * uniquement à la facture comptabilisée.
                 */
                if (
                    $facture->isComptabilisee()
                ) {
                    foreach (
                        $commande->getPaiements()
                        as $paiement
                    ) {
                        if (
                            !$paiement->estValide()
                        ) {
                            continue;
                        }

                        /*
                         * Un paiement peut avoir été créé
                         * avant la facture.
                         */
                        if (
                            $paiement->getFacture()
                            === null
                        ) {
                            $paiement->setFacture(
                                $facture
                            );

                            $entityManager->persist(
                                $paiement
                            );
                        }
                    }
                }


                $entityManager->flush();


                $this->addFlash(
                    'success',
                    sprintf(
                        '%s %s a été enregistré avec succès.',
                        $facture
                            ->getTypeDocumentLabel(),
                        $facture
                            ->getNumero()
                    )
                );


                return $this->redirectToRoute(
                    'app_factures_show',
                    [
                        'id' =>
                        $facture->getId(),
                    ]
                );
            }
        }


        /*
         * --------------------------------------------------------
         * Facture comptable existante
         * --------------------------------------------------------
         *
         * Utile pour informer l'utilisateur
         * dans la page de création.
         */
        $factureComptableExistante =
            $facturesRepository->findOneBy(
                [
                    'commande' =>
                    $commande,

                    'comptabilisee' =>
                    true,
                ]
            );


        return $this->render(
            'factures/new.html.twig',
            [
                'commande' =>
                $commande,

                'facture' =>
                $facture,

                'form' =>
                $form->createView(),

                'factureComptableExistante' =>
                $factureComptableExistante,
            ]
        );
    }


    /*
     * ============================================================
     * AFFICHER
     * ============================================================
     */
    #[Route(
        '/{id}',
        name: 'app_factures_show',
        methods: ['GET']
    )]
    public function show(
        Factures $facture,
        EntityManagerInterface $entityManager
    ): Response {

        /*
     * ============================================================
     * SYNCHRONISATION DE LA SITUATION DE PAIEMENT
     * ============================================================
     *
     * ATTENTION :
     *
     * On ne recharge PAS les montants de la commande.
     * La facture reste figée.
     *
     * On actualise uniquement :
     *
     * - montant payé
     * - reste à payer
     * - statut de paiement
     */
        if (
            $facture->isComptabilisee()
            && !$facture->estAnnulee()
        ) {

            $facture
                ->synchroniserPaiementsDepuisCommande();

            /*
         * Rattache les paiements validés
         * à cette facture officielle.
         */
            $commande =
                $facture->getCommande();

            if ($commande !== null) {

                foreach (
                    $commande->getPaiements()
                    as $paiement
                ) {

                    /*
                 * On ignore les paiements
                 * encore en attente/rejetés/annulés.
                 */
                    if (
                        !$paiement->estValide()
                    ) {
                        continue;
                    }

                    /*
                 * Paiement pas encore rattaché
                 * à une facture.
                 */
                    if (
                        $paiement->getFacture()
                        === null
                    ) {

                        $paiement->setFacture(
                            $facture
                        );

                        $entityManager->persist(
                            $paiement
                        );
                    }
                }
            }

            $entityManager->persist(
                $facture
            );

            $entityManager->flush();
        }


        return $this->render(
            'factures/show.html.twig',
            [
                'facture' =>
                $facture,

                'commande' =>
                $facture->getCommande(),

                'totalCommande' =>
                $facture->getTotalTtc(),

                'montantPaye' =>
                $facture->getMontantPaye(),

                'resteAPayer' =>
                $facture->getResteAPayer(),

                'statutPaiement' =>
                $facture->getStatutPaiement(),
            ]
        );
    }

    /*
     * ============================================================
     * ÉMETTRE LE DOCUMENT
     * ============================================================
     */
    #[Route(
        '/{id}/emettre',
        name: 'app_factures_emettre',
        methods: ['POST']
    )]
    public function emettre(
        Factures $facture,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'emettre-facture-'
                    . $facture->getId(),
                (string) $request
                    ->request
                    ->get('_token')
            )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Jeton CSRF invalide.'
                );
        }


        if ($facture->estAnnulee()) {
            $this->addFlash(
                'error',
                'Un document annulé ne peut pas être émis.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        if ($facture->estEmise()) {
            $this->addFlash(
                'warning',
                'Ce document est déjà émis.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        $facture->setEtat(
            Factures::ETAT_EMISE
        );

        $entityManager->flush();


        $this->addFlash(
            'success',
            sprintf(
                '%s est maintenant émis.',
                $facture->getNumero()
            )
        );


        return $this->redirectToRoute(
            'app_factures_show',
            [
                'id' =>
                $facture->getId(),
            ]
        );
    }


    /*
     * ============================================================
     * SYNCHRONISER UNIQUEMENT LES PAIEMENTS
     * ============================================================
     */
    #[Route(
        '/{id}/synchroniser-paiements',
        name: 'app_factures_synchroniser',
        methods: ['POST']
    )]
    public function synchroniserPaiements(
        Factures $facture,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'synchroniser-facture-'
                    . $facture->getId(),
                (string) $request
                    ->request
                    ->get('_token')
            )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Jeton CSRF invalide.'
                );
        }


        /*
         * Les simulations ne sont pas liées
         * aux paiements réels.
         */
        if (
            !$facture->isComptabilisee()
        ) {
            $this->addFlash(
                'warning',
                'Les paiements ne sont pas synchronisés '
                    . 'sur une pro forma ou une simulation.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        $facture
            ->synchroniserPaiementsDepuisCommande();


        $commande =
            $facture->getCommande();


        if ($commande !== null) {

            foreach (
                $commande->getPaiements()
                as $paiement
            ) {
                if (
                    !$paiement->estValide()
                ) {
                    continue;
                }

                if (
                    $paiement->getFacture()
                    === null
                ) {
                    $paiement->setFacture(
                        $facture
                    );

                    $entityManager->persist(
                        $paiement
                    );
                }
            }
        }


        $entityManager->flush();


        $this->addFlash(
            'success',
            'Les paiements ont été synchronisés.'
        );


        return $this->redirectToRoute(
            'app_factures_show',
            [
                'id' =>
                $facture->getId(),
            ]
        );
    }


    /*
     * ============================================================
     * ANNULER
     * ============================================================
     */
    #[Route(
        '/{id}/actualiser',
        name: 'app_factures_actualiser',
        methods: ['POST']
    )]
    public function actualiser(
        Factures $facture,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'actualiser-facture-'
                    . $facture->getId(),
                (string) $request
                    ->request
                    ->get('_token')
            )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Jeton CSRF invalide.'
                );
        }


        if ($facture->estAnnulee()) {
            $this->addFlash(
                'warning',
                'Ce document est annulé, il ne peut plus être actualisé.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        /*
         * Une fois la facture totalement payée, elle redevient un
         * document figé (comme à l'émission) : plus de mise à jour
         * possible, seul un avoir permettrait de la corriger.
         */
        if (
            $facture->getMontantPaye()
            >= $facture->getTotalTtc()
        ) {
            $this->addFlash(
                'warning',
                'Cette facture est totalement payée, elle ne peut plus être actualisée.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        $commande = $facture->getCommande();

        if ($commande === null) {
            $this->addFlash(
                'warning',
                'Aucune commande liée à ce document, impossible de l’actualiser.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        $facture->chargerDepuisCommande($commande);

        $entityManager->flush();


        $this->addFlash(
            'success',
            sprintf(
                '%s a été actualisé avec les montants actuels de la commande.',
                $facture->getNumero()
                    ?? 'Le document'
            )
        );


        return $this->redirectToRoute(
            'app_factures_show',
            [
                'id' =>
                $facture->getId(),
            ]
        );
    }

    #[Route(
        '/{id}/annuler',
        name: 'app_factures_annuler',
        methods: ['POST']
    )]
    public function annuler(
        Factures $facture,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                'annuler-facture-'
                    . $facture->getId(),
                (string) $request
                    ->request
                    ->get('_token')
            )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Jeton CSRF invalide.'
                );
        }


        if ($facture->estAnnulee()) {
            $this->addFlash(
                'warning',
                'Ce document est déjà annulé.'
            );

            return $this->redirectToRoute(
                'app_factures_show',
                [
                    'id' =>
                    $facture->getId(),
                ]
            );
        }


        $facture->setEtat(
            Factures::ETAT_ANNULEE
        );

        $entityManager->flush();


        $this->addFlash(
            'success',
            sprintf(
                '%s a été annulé.',
                $facture->getNumero()
                    ?? 'Le document'
            )
        );


        return $this->redirectToRoute(
            'app_factures_show',
            [
                'id' =>
                $facture->getId(),
            ]
        );
    }
  #[Route(
    '/{id}/pdf',
    name: 'app_factures_pdf',
    requirements: [
        'id' => '\d+',
    ],
    methods: ['GET']
)]
public function pdf(
    Factures $facture,
    EntityManagerInterface $entityManager
): Response {

    /*
     * ============================================================
     * DOSSIER RACINE
     * ============================================================
     */
    $projectDir =
        (string) $this->getParameter(
            'kernel.project_dir'
        );


    /*
     * ============================================================
     * PDF DÉJÀ ARCHIVÉ
     * ============================================================
     *
     * Très important :
     *
     * si la facture possède déjà un PDF,
     * on NE LE RÉGÉNÈRE PAS.
     *
     * On renvoie exactement le fichier
     * qui avait été archivé.
     */
    if (
        $facture->getPdfFichier() !== null
        && trim(
            $facture->getPdfFichier()
        ) !== ''
    ) {

        $cheminPdfArchive =
            $projectDir
            . '/var/storage/factures/'
            . ltrim(
                $facture->getPdfFichier(),
                '/'
            );


        if (
            is_file(
                $cheminPdfArchive
            )
            && is_readable(
                $cheminPdfArchive
            )
        ) {

            /*
             * Vérification facultative mais recommandée
             * de l'intégrité du PDF.
             */
            $hashEnregistre =
                $facture->getPdfHash();

            if (
                $hashEnregistre !== null
                && trim($hashEnregistre) !== ''
            ) {

                $hashActuel =
                    hash_file(
                        'sha256',
                        $cheminPdfArchive
                    );


                if (
                    $hashActuel === false
                    || !hash_equals(
                        $hashEnregistre,
                        $hashActuel
                    )
                ) {
                    throw new \RuntimeException(
                        'Le PDF archivé de cette facture ne correspond plus à son empreinte d’intégrité.'
                    );
                }
            }


            /*
             * Nom présenté au navigateur.
             */
            $numero =
                trim(
                    (string) (
                        $facture->getNumero()
                        ?? 'facture-'
                            . $facture->getId()
                    )
                );

            $numero =
                preg_replace(
                    '/[^A-Za-z0-9\-_]/',
                    '-',
                    $numero
                );

            $numero =
                $numero
                ?: 'facture-'
                    . $facture->getId();


            return new Response(
                file_get_contents(
                    $cheminPdfArchive
                ),
                Response::HTTP_OK,
                [
                    'Content-Type' =>
                        'application/pdf',

                    'Content-Disposition' =>
                        sprintf(
                            'inline; filename="%s%s.pdf"',
                            $numero,
                            $this->suffixeNomClientPdf($facture)
                        ),

                    'Content-Length' =>
                        (string) filesize(
                            $cheminPdfArchive
                        ),

                    /*
                     * Le document est archivé :
                     * le navigateur peut le conserver.
                     */
                    'Cache-Control' =>
                        'private, max-age=3600',
                ]
            );
        }


        /*
         * La BDD indique un PDF,
         * mais le fichier physique est absent.
         *
         * Pour une facture officielle,
         * on ne régénère PAS silencieusement.
         */
        if (
            $facture->isComptabilisee()
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Le PDF archivé de la facture "%s" est introuvable sur le serveur.',
                    $facture->getNumero()
                        ?? '#' . $facture->getId()
                )
            );
        }
    }


    /*
     * ============================================================
     * COMMANDE
     * ============================================================
     */
    $commande =
        $facture->getCommande();


    if ($commande === null) {

        throw $this
            ->createNotFoundException(
                'La commande associée à cette facture est introuvable.'
            );
    }


    /*
     * ============================================================
     * SITUATION PAIEMENT
     * ============================================================
     *
     * Cette synchronisation est faite AVANT
     * la première génération du document.
     *
     * Après archivage, le PDF ne sera plus modifié.
     */
    if (
        $facture->isComptabilisee()
        && !$facture->estAnnulee()
    ) {

        $facture
            ->synchroniserPaiementsDepuisCommande();
    }


    /*
     * ============================================================
     * URL D'AUTHENTICITÉ
     * ============================================================
     */
    $urlFacture =
        $this->generateUrl(
            'app_factures_show',
            [
                'id' =>
                    $facture->getId(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );


    /*
     * ============================================================
     * QR CODE
     * ============================================================
     */
    $contenuQr =
        sprintf(
            "%s\nFacture: %s\nAuthenticite: %s",
            $urlFacture,
            $facture->getNumero()
                ?? '#' . $facture->getId(),
            $facture->getTokenAuthenticite()
                ?? ''
        );


    $qrCode =
        new QrCode(
            data: $contenuQr,
            encoding:
                new Encoding(
                    'UTF-8'
                ),
            errorCorrectionLevel:
                ErrorCorrectionLevel::Medium,
            size: 250,
            margin: 8,
            roundBlockSizeMode:
                RoundBlockSizeMode::Margin
        );


    $writer =
        new PngWriter();


    $qrResult =
        $writer->write(
            $qrCode
        );


    $qrCodeDataUri =
        $qrResult->getDataUri();


    /*
     * ============================================================
     * LOGOS / SIGNATURES
     * ============================================================
     */
    $documents = [

        /*
         * DREPA TECHNOLOGIE
         */
        'drepa' => [

            'logo' =>
                $this->imageVersDataUri(
                    $projectDir
                    . '/public/assets/images/documents/drepa-logo.png'
                ),

            'signature' =>
                $this->imageVersDataUri(
                    $projectDir
                    . '/public/assets/images/documents/drepa-signature-cachet.png'
                ),
        ],


        /*
         * MADIAL GROUP / SUCCESS IMPRIM
         */
        'mdg' => [

            'logo' =>
                $this->imageVersDataUri(
                    $projectDir
                    . '/public/assets/images/documents/mdg-logo.png'
                ),

            'success_logo' =>
                $this->imageVersDataUri(
                    $projectDir
                    . '/public/assets/images/documents/success-imprim-logo.png'
                ),

            'signature' =>
                $this->imageVersDataUri(
                    $projectDir
                    . '/public/assets/images/documents/mdg-signature-cachet.png'
                ),
        ],
    ];


    /*
     * ============================================================
     * HTML
     * ============================================================
     */
    $html =
        $this->renderView(
            'factures/pdf.html.twig',
            [
                'facture' =>
                    $facture,

                'commande' =>
                    $commande,

                'client' =>
                    $commande->getClients(),

                'qrCode' =>
                    $qrCodeDataUri,

                'urlFacture' =>
                    $urlFacture,

                'documents' =>
                    $documents,
            ]
        );


    /*
     * ============================================================
     * DOMPDF
     * ============================================================
     */
    $options =
        new Options();


    $options->set(
        'defaultFont',
        'DejaVu Sans'
    );


    $options->set(
        'isRemoteEnabled',
        true
    );


    $options->set(
        'isHtml5ParserEnabled',
        true
    );


    $dompdf =
        new Dompdf(
            $options
        );


    $dompdf->loadHtml(
        $html,
        'UTF-8'
    );


    $dompdf->setPaper(
        'A4',
        'portrait'
    );


    $dompdf->render();


    /*
     * ============================================================
     * CONTENU PDF
     * ============================================================
     */
    $contenuPdf =
        $dompdf->output();


    if (
        $contenuPdf === ''
    ) {
        throw new \RuntimeException(
            'La génération du PDF de la facture a échoué.'
        );
    }


    /*
     * ============================================================
     * NUMÉRO / NOM DE FICHIER
     * ============================================================
     */
    $numero =
        trim(
            (string) (
                $facture->getNumero()
                ?? (
                    $facture->isComptabilisee()
                        ? 'facture-'
                        : 'proforma-'
                )
                . $facture->getId()
            )
        );


    $numeroNettoye =
        preg_replace(
            '/[^A-Za-z0-9\-_]/',
            '-',
            $numero
        );


    if (
        !is_string(
            $numeroNettoye
        )
        || trim(
            $numeroNettoye
        ) === ''
    ) {

        $numeroNettoye =
            (
                $facture->isComptabilisee()
                    ? 'facture-'
                    : 'proforma-'
            )
            . $facture->getId();
    }


    $nomFichier =
        $numeroNettoye
        . '.pdf';


    $nomTelechargement =
        $numeroNettoye
        . $this->suffixeNomClientPdf(
            $facture
        )
        . '.pdf';


    /*
     * ============================================================
     * DOSSIER ANNÉE
     * ============================================================
     */
    $dateFacture =
        $facture->getDateFacture()
        ?? new \DateTimeImmutable();


    $annee =
        $dateFacture->format(
            'Y'
        );


    /*
     * Le chemin relatif enregistré en BDD.
     *
     * Exemple :
     *
     * 2026/FAC-000001-08-2026.pdf
     */
    $cheminRelatif =
        $annee
        . '/'
        . $nomFichier;


    /*
     * Chemin physique.
     */
    $dossier =
        $projectDir
        . '/var/storage/factures/'
        . $annee;


    $cheminAbsolu =
        $dossier
        . '/'
        . $nomFichier;


    /*
     * ============================================================
     * CRÉATION DU DOSSIER
     * ============================================================
     */
    if (
        !is_dir(
            $dossier
        )
    ) {

        if (
            !mkdir(
                $dossier,
                0775,
                true
            )
            && !is_dir(
                $dossier
            )
        ) {

            throw new \RuntimeException(
                sprintf(
                    'Impossible de créer le dossier d’archivage "%s".',
                    $dossier
                )
            );
        }
    }


    /*
     * ============================================================
     * ÉCRITURE DU PDF
     * ============================================================
     */
    $octetsEcrits =
        file_put_contents(
            $cheminAbsolu,
            $contenuPdf,
            LOCK_EX
        );


    if (
        $octetsEcrits === false
    ) {

        throw new \RuntimeException(
            sprintf(
                'Impossible d’enregistrer le PDF dans "%s".',
                $cheminAbsolu
            )
        );
    }


    /*
     * ============================================================
     * HASH SHA-256
     * ============================================================
     */
    $hash =
        hash_file(
            'sha256',
            $cheminAbsolu
        );


    if ($hash === false) {

        /*
         * Si le calcul échoue,
         * supprimer le fichier incomplet.
         */
        @unlink(
            $cheminAbsolu
        );

        throw new \RuntimeException(
            'Impossible de calculer l’empreinte du PDF.'
        );
    }


    /*
     * ============================================================
     * ARCHIVAGE EN BASE
     * ============================================================
     */
    $facture
        ->setPdfFichier(
            $cheminRelatif
        );


    $facture
        ->setPdfGenereLe(
            new \DateTimeImmutable()
        );


    $facture
        ->setPdfHash(
            $hash
        );


    $entityManager
        ->persist(
            $facture
        );


    $entityManager
        ->flush();


    /*
     * ============================================================
     * RÉPONSE
     * ============================================================
     */
    return new Response(
        $contenuPdf,
        Response::HTTP_OK,
        [
            'Content-Type' =>
                'application/pdf',

            'Content-Disposition' =>
                sprintf(
                    'inline; filename="%s"',
                    $nomTelechargement
                ),

            'Content-Length' =>
                (string) strlen(
                    $contenuPdf
                ),

            /*
             * Permet également de contrôler
             * l'intégrité depuis la réponse.
             */
            'X-Document-SHA256' =>
                $hash,
        ]
    );
}

    /*
     * Version publique du PDF, accessible sans connexion via le
     * jeton d'authenticite de la facture (deja genere au PrePersist).
     * Sert de lien a partager par WhatsApp/e-mail au client : ce
     * dernier n'a pas de compte sur l'application.
     */
    #[Route(
        '/{id}/pdf-public/{token}',
        name: 'app_factures_pdf_public',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function pdfPublic(
        Factures $facture,
        string $token,
        EntityManagerInterface $entityManager
    ): Response {
        $tokenAttendu = (string) $facture->getTokenAuthenticite();

        if (
            $tokenAttendu === ''
            || !hash_equals($tokenAttendu, $token)
        ) {
            throw $this->createNotFoundException(
                'Document introuvable.'
            );
        }

        return $this->pdf($facture, $entityManager);
    }

    /*
     * Envoie la facture par WhatsApp au client (API WhatsApp Business
     * de Meta), sous forme de modele avec le PDF en lien de
     * telechargement (route publique pdf-public ci-dessus).
     */
    #[Route(
        '/{id}/whatsapp',
        name: 'app_factures_whatsapp',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function envoyerWhatsapp(
        Factures $facture,
        Request $request,
        WhatsAppService $whatsAppService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid(
            'whatsapp_facture_' . $facture->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json(
                ['success' => false, 'message' => 'Jeton de sécurité invalide.'],
                Response::HTTP_FORBIDDEN
            );
        }

        if (!$whatsAppService->estConfigure()) {
            return $this->json(
                [
                    'success' => false,
                    'message' => "L'envoi WhatsApp n'est pas configuré : "
                        . "le compte Meta Business (jeton, numéro) n'a pas été renseigné.",
                ],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $client = $facture->getCommande()?->getClients();
        $telephone = $client?->getTelephone();

        if (!$client || !$telephone) {
            return $this->json(
                ['success' => false, 'message' => "Ce client n'a pas de numéro de téléphone enregistré."],
                Response::HTTP_BAD_REQUEST
            );
        }

        $lienDocument = $this->generateUrl(
            'app_factures_pdf_public',
            [
                'id' => $facture->getId(),
                'token' => $facture->getTokenAuthenticite(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $numero = $facture->getNumero() ?? ('#' . $facture->getId());

        $nomClient = trim((string) $client->getRaisonSociale())
            ?: trim(trim((string) $client->getPrenom()) . ' ' . trim((string) $client->getNom()))
            ?: 'Client';

        try {
            $whatsAppService->envoyerDocument(
                $telephone,
                $this->getParameter('app.whatsapp_template_document'),
                $lienDocument,
                $numero . '.pdf',
                [$nomClient, 'facture', $numero]
            );
        } catch (\Throwable $exception) {
            return $this->json(
                ['success' => false, 'message' => "L'envoi a échoué : " . $exception->getMessage()],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return $this->json([
            'success' => true,
            'message' => 'La facture a été envoyée par WhatsApp.',
        ]);
    }

    private function suffixeNomClientPdf(
        Factures $facture
    ): string {
        $client =
            $facture->getCommande()
                ?->getClients();

        if ($client === null) {
            return '';
        }

        $nom =
            preg_replace(
                '/[^A-Za-z0-9\-_]/',
                '-',
                $client->getNomComplet()
            );

        $nom =
            trim(
                (string) $nom,
                '-'
            );

        return $nom !== ''
            ? '_' . $nom
            : '';
    }

    private function imageVersDataUri(
        string $chemin
    ): ?string {
        if (
            !is_file($chemin)
            || !is_readable($chemin)
        ) {
            return null;
        }

        $contenu =
            file_get_contents(
                $chemin
            );

        if ($contenu === false) {
            return null;
        }

        $mime =
            mime_content_type(
                $chemin
            );

        if (!$mime) {
            $mime = 'image/png';
        }

        return sprintf(
            'data:%s;base64,%s',
            $mime,
            base64_encode(
                $contenu
            )
        );
    }
}
