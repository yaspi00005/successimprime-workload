<?php

namespace App\Controller;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Repository\CompteTresorerieRepository;
use App\Repository\MouvementTresorerieRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/gestion/tresorerie/journal-caisse')]

/*
 * Le rapport GLOBAL contient potentiellement :
 *
 * - salaires ;
 * - charges confidentielles ;
 * - résultats globaux ;
 * - soldes consolidés.
 *
 * Donc Admin uniquement pour le moment.
 */
#[IsGranted('ROLE_ADMIN')]
class JournalCaisseController extends AbstractController
{
    #[Route(
        '',
        name: 'app_journal_caisse_index',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        CompteTresorerieRepository $compteRepository,
        MouvementTresorerieRepository $mouvementRepository
    ): Response {
        $donnees = $this->construireDonnees($request, $compteRepository, $mouvementRepository);

        return $this->render(
            'journal_caisse/index.html.twig',
            $donnees
        );
    }

    #[Route(
        '/export/pdf',
        name: 'app_journal_caisse_export_pdf',
        methods: ['GET']
    )]
    public function exporterPdf(
        Request $request,
        CompteTresorerieRepository $compteRepository,
        MouvementTresorerieRepository $mouvementRepository
    ): Response {
        $donnees = $this->construireDonnees($request, $compteRepository, $mouvementRepository);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        $html = $this->renderView('journal_caisse/pdf.html.twig', $donnees);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $nomFichier = sprintf(
            'journal-caisse_%s_au_%s.pdf',
            $donnees['filtres']['dateDebut'],
            $donnees['filtres']['dateFin']
        );

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $nomFichier),
            ]
        );
    }

    #[Route(
        '/export/excel',
        name: 'app_journal_caisse_export_excel',
        methods: ['GET']
    )]
    public function exporterExcel(
        Request $request,
        CompteTresorerieRepository $compteRepository,
        MouvementTresorerieRepository $mouvementRepository
    ): Response {
        $donnees = $this->construireDonnees($request, $compteRepository, $mouvementRepository);

        $spreadsheet = new Spreadsheet();

        $this->remplirFeuilleResume($spreadsheet, $donnees);
        $this->remplirFeuilleMouvements($spreadsheet, $donnees);

        $spreadsheet->setActiveSheetIndex(0);

        $nomFichier = sprintf(
            'journal-caisse_%s_au_%s.xlsx',
            $donnees['filtres']['dateDebut'],
            $donnees['filtres']['dateFin']
        );

        $fluxTemporaire = fopen('php://temp', 'w+');

        if ($fluxTemporaire === false) {
            throw new \RuntimeException('Impossible de générer le fichier Excel.');
        }

        (new Xlsx($spreadsheet))->save($fluxTemporaire);
        rewind($fluxTemporaire);
        $contenu = stream_get_contents($fluxTemporaire);
        fclose($fluxTemporaire);

        return new Response(
            $contenu === false ? '' : $contenu,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $nomFichier),
            ]
        );
    }

    private function remplirFeuilleResume(Spreadsheet $spreadsheet, array $donnees): void
    {
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Résumé');

        $styleEntete = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1643A3'],
            ],
        ];

        $feuille->setCellValue('A1', 'Journal de caisse');
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $feuille->setCellValue('A2', 'Période :');
        $feuille->setCellValue('B2', $donnees['filtres']['dateDebut'] . ' au ' . $donnees['filtres']['dateFin']);

        $feuille->setCellValue('A4', 'Indicateur');
        $feuille->setCellValue('B4', 'Montant (FCFA)');
        $feuille->getStyle('A4:B4')->applyFromArray($styleEntete);

        $rapport = $donnees['rapportGlobal'];

        $lignesResume = [
            ['Total entrées (période)', $rapport['entrees']],
            ['Total sorties (période)', $rapport['sorties']],
            ['Variation de trésorerie', $rapport['variationTresorerie']],
            ['Produits (impact résultat)', $rapport['produits']],
            ['Charges (impact résultat)', $rapport['charges']],
            ['Résultat net', $rapport['resultatNet']],
            ['Transferts internes', $rapport['transferts']],
        ];

        $ligne = 5;

        foreach ($lignesResume as [$libelle, $montant]) {
            $feuille->setCellValue('A' . $ligne, $libelle);
            $feuille->setCellValue('B' . $ligne, $montant);
            ++$ligne;
        }

        $ligne += 1;
        $feuille->setCellValue('A' . $ligne, 'Compte');
        $feuille->setCellValue('B' . $ligne, 'Type');
        $feuille->setCellValue('C' . $ligne, 'Portée');
        $feuille->setCellValue('D' . $ligne, 'Solde actuel (FCFA)');
        $feuille->getStyle('A' . $ligne . ':D' . $ligne)->applyFromArray($styleEntete);
        ++$ligne;

        foreach ($donnees['soldes']['comptes'] as $ligneCompte) {
            /** @var CompteTresorerie $compte */
            $compte = $ligneCompte['compte'];

            $feuille->setCellValue('A' . $ligne, $compte->getNom());
            $feuille->setCellValue('B' . $ligne, $compte->getTypeLabel());
            $feuille->setCellValue('C' . $ligne, $compte->getPorteeLabel());
            $feuille->setCellValue('D' . $ligne, $ligneCompte['solde']);
            ++$ligne;
        }

        foreach (['A', 'B', 'C', 'D'] as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }
    }

    private function remplirFeuilleMouvements(Spreadsheet $spreadsheet, array $donnees): void
    {
        $feuille = $spreadsheet->createSheet();
        $feuille->setTitle('Mouvements');

        $entetes = [
            'Date', 'Référence', 'Type', 'Compte source', 'Compte destination',
            'Catégorie', 'Libellé', 'Agent', 'Mode', 'Entrée', 'Sortie', 'Transfert',
        ];

        foreach ($entetes as $index => $intitule) {
            $colonne = chr(ord('A') + $index);
            $feuille->setCellValue($colonne . '1', $intitule);
        }

        $feuille->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1643A3'],
            ],
        ]);

        $ligne = 2;

        foreach ($donnees['journalGlobal']['lignes'] as $donneesLigne) {
            /** @var MouvementTresorerie $mouvement */
            $mouvement = $donneesLigne['mouvement'];

            $feuille->setCellValue('A' . $ligne, $mouvement->getDateOperation()?->format('d/m/Y H:i'));
            $feuille->setCellValue('B' . $ligne, $mouvement->getReference());
            $feuille->setCellValue('C' . $ligne, $mouvement->getTypeLabel());
            $feuille->setCellValue('D' . $ligne, $mouvement->getCompteSource()?->getNom() ?? '');
            $feuille->setCellValue('E' . $ligne, $mouvement->getCompteDestination()?->getNom() ?? '');
            $feuille->setCellValue('F' . $ligne, $mouvement->getCategorieLabel());
            $feuille->setCellValue('G' . $ligne, $mouvement->getLibelle());
            $feuille->setCellValue('H' . $ligne, $mouvement->getAgent()?->getUsername() ?? '');
            $feuille->setCellValue('I' . $ligne, $mouvement->getModePaiement() ?? '');
            $feuille->setCellValue('J' . $ligne, $donneesLigne['entree']);
            $feuille->setCellValue('K' . $ligne, $donneesLigne['sortie']);
            $feuille->setCellValue('L' . $ligne, $donneesLigne['transfert']);
            ++$ligne;
        }

        foreach (range('A', 'L') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }
    }

    /**
     * Calcule toutes les données du journal de caisse (soldes, journal
     * détaillé d'un compte, journal global, rapport financier). Utilisé
     * par la page HTML et par les deux exports (PDF, Excel) pour ne
     * jamais dupliquer cette logique.
     *
     * @return array<string, mixed>
     */
    private function construireDonnees(
        Request $request,
        CompteTresorerieRepository $compteRepository,
        MouvementTresorerieRepository $mouvementRepository
    ): array {
        /*
         * ========================================================
         * PÉRIODE
         * ========================================================
         */

        $aujourdhui =
            new \DateTimeImmutable();


        $dateDebutValeur =
            trim(
                (string)
                $request->query->get(
                    'dateDebut',
                    $aujourdhui->format('Y-m-01')
                )
            );


        $dateFinValeur =
            trim(
                (string)
                $request->query->get(
                    'dateFin',
                    $aujourdhui->format('Y-m-d')
                )
            );


        /*
         * ========================================================
         * CAISSE OPTIONNELLE
         * ========================================================
         *
         * Aucun compte sélectionné :
         * rapport global.
         *
         * Compte sélectionné :
         * rapport global + journal détaillé.
         * ========================================================
         */

       $compteIdBrut = trim(
    (string) $request->query->get(
        'compte',
        ''
    )
);

$compteId = ctype_digit($compteIdBrut)
    ? (int) $compteIdBrut
    : 0;

        /*
         * ========================================================
         * DATES
         * ========================================================
         */

        try {
            $dateDebut =
                new \DateTimeImmutable(
                    $dateDebutValeur
                    . ' 00:00:00'
                );
        } catch (\Throwable) {
            $dateDebut =
                $aujourdhui
                    ->modify(
                        'first day of this month'
                    )
                    ->setTime(
                        0,
                        0,
                        0
                    );

            $dateDebutValeur =
                $dateDebut
                    ->format('Y-m-d');

            $this->addFlash(
                'warning',
                'La date de début était invalide et a été corrigée.'
            );
        }


        try {
            $dateFin =
                new \DateTimeImmutable(
                    $dateFinValeur
                    . ' 23:59:59'
                );
        } catch (\Throwable) {
            $dateFin =
                $aujourdhui
                    ->setTime(
                        23,
                        59,
                        59
                    );

            $dateFinValeur =
                $dateFin
                    ->format('Y-m-d');

            $this->addFlash(
                'warning',
                'La date de fin était invalide et a été corrigée.'
            );
        }


        if (
            $dateDebut
            >
            $dateFin
        ) {
            $this->addFlash(
                'error',
                'La date de début ne peut pas être postérieure à la date de fin.'
            );

            /*
             * On remet le mois courant.
             */
            $dateDebut =
                $aujourdhui
                    ->modify(
                        'first day of this month'
                    )
                    ->setTime(
                        0,
                        0,
                        0
                    );

            $dateFin =
                $aujourdhui
                    ->setTime(
                        23,
                        59,
                        59
                    );

            $dateDebutValeur =
                $dateDebut
                    ->format('Y-m-d');

            $dateFinValeur =
                $dateFin
                    ->format('Y-m-d');
        }


        /*
         * ========================================================
         * TOUS LES COMPTES ACTIFS
         * ========================================================
         */

        $comptes =
            $compteRepository->findBy(
                [
                    'actif' => true,
                ],
                [
                    'type' => 'ASC',
                    'nom' => 'ASC',
                ]
            );


        /*
         * ========================================================
         * CAISSES PHYSIQUES
         * ========================================================
         *
         * Utilisées pour le journal détaillé.
         * ========================================================
         */

        /*
 * ========================================================
 * COMPTES DISPONIBLES DANS LE JOURNAL
 * ========================================================
 *
 * ADMIN :
 * tous les comptes actifs.
 *
 * Le journal n'est donc plus limité aux caisses physiques.
 * ========================================================
 */

$comptesJournal =
    $compteRepository->findBy(
        [
            'actif' => true,
        ],
        [
            'type' => 'ASC',
            'nom' => 'ASC',
        ]
    );


        /*
         * ========================================================
         * MOUVEMENTS GLOBAUX DE LA PÉRIODE
         * ========================================================
         */

        $filtresGlobaux = [
            'recherche' => '',
            'compte' => null,
            'agent' => null,
            'type' => null,

            'statut' =>
                MouvementTresorerie::STATUT_VALIDE,

            'modePaiement' => null,

            'dateDebut' =>
                $dateDebut
                    ->format('Y-m-d'),

            'dateFin' =>
                $dateFin
                    ->format('Y-m-d'),
        ];


        $mouvementsGlobaux =
            $mouvementRepository
                ->rechercherAvecFiltres(
                    $filtresGlobaux
                );


        /*
         * ========================================================
         * RAPPORT GLOBAL
         * ========================================================
         */

        $rapportGlobal = [
            /*
             * Flux externes.
             */
            'entrees' => 0,
            'sorties' => 0,

            /*
             * Résultat de gestion.
             */
            'produits' => 0,
            'charges' => 0,
            'resultatNet' => 0,

            /*
             * Transferts internes.
             */
            'transferts' => 0,

            /*
             * Quantités.
             */
            'nombreEncaissements' => 0,
            'nombreDecaissements' => 0,
            'nombreTransferts' => 0,
            'nombreMouvements' => 0,

            /*
             * Ventilation.
             */
            'produitsParCategorie' => [],
            'chargesParCategorie' => [],
        ];


        foreach (
            $mouvementsGlobaux
            as $mouvement
        ) {
            /*
             * Sécurité supplémentaire.
             */
            if (!$mouvement->isValide()) {
                continue;
            }


            $montant =
                (int)
                $mouvement->getMontant();


            if ($montant <= 0) {
                continue;
            }


            ++$rapportGlobal[
                'nombreMouvements'
            ];


            /*
             * ====================================================
             * TRANSFERT
             * ====================================================
             *
             * Le transfert est totalement neutre dans :
             *
             * - les recettes ;
             * - les charges ;
             * - le résultat.
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_TRANSFERT
            ) {
                $rapportGlobal[
                    'transferts'
                ] += $montant;

                ++$rapportGlobal[
                    'nombreTransferts'
                ];

                continue;
            }


            /*
             * ====================================================
             * ENCAISSEMENT
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_ENCAISSEMENT
            ) {
                $rapportGlobal[
                    'entrees'
                ] += $montant;

                ++$rapportGlobal[
                    'nombreEncaissements'
                ];


                /*
                 * Produit réel pour le résultat.
                 */
                if (
                    $mouvement
                        ->isImpactResultat()
                ) {
                    $rapportGlobal[
                        'produits'
                    ] += $montant;


                    $categorie =
                        $mouvement
                            ->getCategorie()
                        ?: 'autre_produit';


                    if (
                        !isset(
                            $rapportGlobal[
                                'produitsParCategorie'
                            ][$categorie]
                        )
                    ) {
                        $rapportGlobal[
                            'produitsParCategorie'
                        ][$categorie] = 0;
                    }


                    $rapportGlobal[
                        'produitsParCategorie'
                    ][$categorie] += $montant;
                }


                continue;
            }


            /*
             * ====================================================
             * DÉCAISSEMENT
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_DECAISSEMENT
            ) {
                $rapportGlobal[
                    'sorties'
                ] += $montant;

                ++$rapportGlobal[
                    'nombreDecaissements'
                ];


                /*
                 * Charge réelle pour le résultat.
                 */
                if (
                    $mouvement
                        ->isImpactResultat()
                ) {
                    $rapportGlobal[
                        'charges'
                    ] += $montant;


                    $categorie =
                        $mouvement
                            ->getCategorie()
                        ?: 'autre_charge';


                    if (
                        !isset(
                            $rapportGlobal[
                                'chargesParCategorie'
                            ][$categorie]
                        )
                    ) {
                        $rapportGlobal[
                            'chargesParCategorie'
                        ][$categorie] = 0;
                    }


                    $rapportGlobal[
                        'chargesParCategorie'
                    ][$categorie] += $montant;
                }
            }
        }
/*
 * ========================================================
 * JOURNAL GLOBAL
 * ========================================================
 */

$journalGlobal = [
    'lignes' => [],
    'totalEntrees' => 0,
    'totalSorties' => 0,
    'transferts' => 0,
    'variation' => 0,
    'nombre' => 0,
];


foreach ($mouvementsGlobaux as $mouvement) {

    /*
     * Uniquement les mouvements validés.
     */
    if (!$mouvement->isValide()) {
        continue;
    }

    $montant = (int) $mouvement->getMontant();

    if ($montant <= 0) {
        continue;
    }


    $entree = 0;
    $sortie = 0;
    $transfert = 0;


    /*
     * ====================================================
     * ENCAISSEMENT
     * ====================================================
     */
    if (
        $mouvement->getType()
        ===
        MouvementTresorerie::TYPE_ENCAISSEMENT
    ) {
        $entree = $montant;

        $journalGlobal[
            'totalEntrees'
        ] += $montant;
    }


    /*
     * ====================================================
     * DÉCAISSEMENT
     * ====================================================
     */
    elseif (
        $mouvement->getType()
        ===
        MouvementTresorerie::TYPE_DECAISSEMENT
    ) {
        $sortie = $montant;

        $journalGlobal[
            'totalSorties'
        ] += $montant;
    }


    /*
     * ====================================================
     * TRANSFERT INTERNE
     * ====================================================
     *
     * Il apparaît dans le journal mais ne constitue
     * ni une entrée ni une sortie globale de l'entreprise.
     */
    elseif (
        $mouvement->getType()
        ===
        MouvementTresorerie::TYPE_TRANSFERT
    ) {
        $transfert = $montant;

        $journalGlobal[
            'transferts'
        ] += $montant;
    }


    /*
     * Ajout dans le tableau du journal.
     */
    $journalGlobal['lignes'][] = [
        'mouvement' => $mouvement,
        'entree' => $entree,
        'sortie' => $sortie,
        'transfert' => $transfert,
    ];
}


/*
 * Nombre total de mouvements.
 */
$journalGlobal['nombre'] =
    count(
        $journalGlobal['lignes']
    );


/*
 * Variation réelle de trésorerie externe.
 */
$journalGlobal['variation'] =
    $journalGlobal['totalEntrees']
    -
    $journalGlobal['totalSorties'];

        /*
         * ========================================================
         * RÉSULTAT NET
         * ========================================================
         */

        $rapportGlobal['resultatNet'] =
            $rapportGlobal['produits']
            -
            $rapportGlobal['charges'];


        /*
         * ========================================================
         * VARIATION DE TRÉSORERIE
         * ========================================================
         *
         * Les transferts internes ne comptent pas.
         * ========================================================
         */

        $rapportGlobal[
            'variationTresorerie'
        ] =
            $rapportGlobal['entrees']
            -
            $rapportGlobal['sorties'];


        /*
         * ========================================================
         * SOLDES ACTUELS + DÉTAIL PAR COMPTE
         * ========================================================
         *
         * Pour chaque compte : solde actuel, mais aussi nombre de
         * mouvements et date du dernier mouvement SUR LA PÉRIODE
         * affichée (utile pour repérer un compte inactif).
         * ========================================================
         */

        $soldes = [
            'total' => 0,

            'caisses' => 0,
            'orangeMoney' => 0,
            'wave' => 0,
            'banques' => 0,

            'comptes' => [],
        ];


        $statsParCompte = [];

        foreach ($journalGlobal['lignes'] as $ligneJournal) {
            $mouvementLigne = $ligneJournal['mouvement'];

            foreach ([$mouvementLigne->getCompteSource(), $mouvementLigne->getCompteDestination()] as $compteMouvement) {
                if (!$compteMouvement instanceof CompteTresorerie || $compteMouvement->getId() === null) {
                    continue;
                }

                $idCompte = $compteMouvement->getId();

                if (!isset($statsParCompte[$idCompte])) {
                    $statsParCompte[$idCompte] = [
                        'nombre' => 0,
                        'dernierMouvement' => null,
                    ];
                }

                ++$statsParCompte[$idCompte]['nombre'];

                $dateOperation = $mouvementLigne->getDateOperation();

                if (
                    $dateOperation !== null
                    && (
                        $statsParCompte[$idCompte]['dernierMouvement'] === null
                        || $dateOperation > $statsParCompte[$idCompte]['dernierMouvement']
                    )
                ) {
                    $statsParCompte[$idCompte]['dernierMouvement'] = $dateOperation;
                }
            }
        }


        foreach (
            $comptes
            as $compte
        ) {
            $solde =
                (int)
                $compte->getSoldeActuel();


            $soldes['total'] +=
                $solde;


            switch (
                $compte->getType()
            ) {
                case
                    CompteTresorerie::TYPE_CAISSE:

                    $soldes[
                        'caisses'
                    ] += $solde;


                    break;


                case
                    CompteTresorerie::TYPE_ORANGE_MONEY:

                    $soldes[
                        'orangeMoney'
                    ] += $solde;

                    break;


                case
                    CompteTresorerie::TYPE_WAVE:

                    $soldes[
                        'wave'
                    ] += $solde;

                    break;


                case
                    CompteTresorerie::TYPE_BANQUE:

                    $soldes[
                        'banques'
                    ] += $solde;

                    break;
            }


            $idCompte = $compte->getId();
            $statsCompte = $idCompte !== null ? ($statsParCompte[$idCompte] ?? null) : null;

            $soldes['comptes'][] = [
                'compte' =>
                    $compte,

                'solde' =>
                    $solde,

                'nombreMouvementsPeriode' => $statsCompte['nombre'] ?? 0,

                'dernierMouvementPeriode' => $statsCompte['dernierMouvement'] ?? null,
            ];
        }


        /*
         * ========================================================
         * JOURNAL D'UNE CAISSE
         * ========================================================
         */

        $compteSelectionne =
            null;

        $journal =
            $this->journalVide();


        if (
            $compteId > 0
        ) {
           $compteSelectionne =
    $compteRepository
        ->findOneBy([
            'id' =>
                $compteId,

            'actif' =>
                true,
        ]);


            if (
                !$compteSelectionne
                instanceof
                CompteTresorerie
            ) {
                $this->addFlash(
                    'error',
                    'La caisse sélectionnée est introuvable ou inactive.'
                );

                $compteSelectionne =
                    null;
            } else {
                $journal =
                    $mouvementRepository
                        ->construireJournalCompte(
                            $compteSelectionne,
                            $dateDebut,
                            $dateFin
                        );
            }
        }


        return [
        'comptesJournal' =>
            $comptesJournal,

        'comptes' =>
            $comptes,

        'compteSelectionne' =>
            $compteSelectionne,

        'journal' =>
            $journal,

        /*
         * IMPORTANT :
         * journal global de tous les comptes
         */
        'journalGlobal' =>
            $journalGlobal,

        /*
         * Statistiques financières globales
         */
        'rapportGlobal' =>
            $rapportGlobal,

        /*
         * Soldes actuels de tous les comptes
         */
        'soldes' =>
            $soldes,

        /*
         * Liste brute des mouvements
         */
        'mouvementsGlobaux' =>
            $mouvementsGlobaux,

        'filtres' => [
            'compte' =>
                $compteId > 0
                    ? $compteId
                    : null,

            'dateDebut' =>
                $dateDebutValeur,

            'dateFin' =>
                $dateFinValeur,
        ],
        ];
    }


    private function journalVide(): array
    {
        return [
            'lignes' => [],
            'soldeOuverture' => 0,
            'totalEntrees' => 0,
            'totalSorties' => 0,
            'soldeCloture' => 0,
            'nombre' => 0,
        ];
    }
}
