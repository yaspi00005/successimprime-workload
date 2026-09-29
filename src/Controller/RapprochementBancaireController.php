<?php

namespace App\Controller;

use App\Entity\LigneRapprochementBancaire;
use App\Entity\MouvementTresorerie;
use App\Entity\RapprochementBancaire;
use App\Form\RapprochementBancaireType;
use App\Repository\MouvementTresorerieRepository;
use App\Repository\RapprochementBancaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/rapprochements-bancaires')]
#[IsGranted('ROLE_USER')]
class RapprochementBancaireController extends AbstractController
{
    #[Route(
        '',
        name: 'app_rapprochement_bancaire_index',
        methods: ['GET']
    )]
    public function index(
        RapprochementBancaireRepository $repository
    ): Response {
        return $this->render(
            'rapprochement_bancaire/index.html.twig',
            [
                'rapprochements' => $repository->findBy(
                    [],
                    ['dateCreation' => 'DESC']
                ),
            ]
        );
    }

    #[Route(
        '/nouveau',
        name: 'app_rapprochement_bancaire_new',
        methods: ['GET', 'POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        MouvementTresorerieRepository $mouvementRepository
    ): Response {
        $rapprochement = new RapprochementBancaire();

        $form = $this->createForm(
            RapprochementBancaireType::class,
            $rapprochement
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $compte = $rapprochement->getCompteTresorerie();
            $dateDebut = $rapprochement->getDateDebut();
            $dateFin = $rapprochement->getDateFin();

            if ($compte === null) {
                $this->addFlash(
                    'error',
                    'Veuillez sélectionner un compte bancaire.'
                );

                return $this->render(
                    'rapprochement_bancaire/new.html.twig',
                    [
                        'rapprochement' => $rapprochement,
                        'form' => $form,
                    ]
                );
            }

            if ($dateDebut === null || $dateFin === null) {
                $this->addFlash(
                    'error',
                    'Veuillez renseigner la période du rapprochement.'
                );

                return $this->render(
                    'rapprochement_bancaire/new.html.twig',
                    [
                        'rapprochement' => $rapprochement,
                        'form' => $form,
                    ]
                );
            }

            if ($dateFin < $dateDebut) {
                $this->addFlash(
                    'error',
                    'La date de fin doit être postérieure ou égale à la date de début.'
                );

                return $this->render(
                    'rapprochement_bancaire/new.html.twig',
                    [
                        'rapprochement' => $rapprochement,
                        'form' => $form,
                    ]
                );
            }

            $rapprochement->setReference(
                $this->genererReference($entityManager)
            );

            $utilisateur = $this->getUser();

            if ($utilisateur !== null) {
                $rapprochement->setCreePar($utilisateur);
            }

            /*
             * Récupération des mouvements bancaires validés
             * et non encore rapprochés.
             */
            $mouvements = $mouvementRepository
    ->findMouvementsARapprocher(
        $compte,
        $dateDebut,
        $dateFin
    );



/*
 * Le solde comptable est calculé à partir
 * du solde d’ouverture et des mouvements.
 */
$soldeComptable = $rapprochement
    ->getSoldeOuvertureReleve();
            /*
             * Le solde comptable est calculé à partir
             * du solde d’ouverture et des mouvements.
             */
            $soldeComptable =
                $rapprochement->getSoldeOuvertureReleve();

            foreach ($mouvements as $mouvement) {
                $soldeComptable += $this->calculerImpactMouvement(
                    $mouvement,
                    $compte
                );

                $ligne = new LigneRapprochementBancaire();

                $ligne
                    ->setMouvementTresorerie($mouvement)
                    ->setDateReleve(
                        $this->convertirEnDateImmutable(
                            $mouvement->getDateOperation()
                        )
                    )
                    ->setMontantReleve(
                        $mouvement->getMontant()
                    )
                    ->setPointee(false);

                $rapprochement->addLigne($ligne);
            }

            $rapprochement
                ->setSoldeComptable($soldeComptable)
                ->calculerEcart();

            $entityManager->persist($rapprochement);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Le rapprochement %s a été créé avec %d mouvement(s) à pointer.',
                    $rapprochement->getReference(),
                    $rapprochement->getNombreLignes()
                )
            );

            return $this->redirectToRoute(
                'app_rapprochement_bancaire_show',
                [
                    'id' => $rapprochement->getId(),
                ]
            );
        }

        return $this->render(
            'rapprochement_bancaire/new.html.twig',
            [
                'rapprochement' => $rapprochement,
                'form' => $form,
            ]
        );
    }

    #[Route(
        '/{id}',
        name: 'app_rapprochement_bancaire_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(
        RapprochementBancaire $rapprochement
    ): Response {
        return $this->render(
            'rapprochement_bancaire/show.html.twig',
            [
                'rapprochement' => $rapprochement,
            ]
        );
    }

    /**
     * Génère une référence comme :
     * RB-20260805-0001
     */
    private function genererReference(
        EntityManagerInterface $entityManager
    ): string {
        $date = new \DateTimeImmutable();

        $prefixe = sprintf(
            'RB-%s-',
            $date->format('Ymd')
        );

        $connexion = $entityManager->getConnection();

        $nombre = (int) $connexion->fetchOne(
            <<<'SQL'
                SELECT COUNT(id)
                FROM rapprochement_bancaire
                WHERE reference LIKE :prefixe
            SQL,
            [
                'prefixe' => $prefixe . '%',
            ]
        );

        do {
            ++$nombre;

            $reference = sprintf(
                '%s%04d',
                $prefixe,
                $nombre
            );

            $existe = (bool) $connexion->fetchOne(
                <<<'SQL'
                    SELECT 1
                    FROM rapprochement_bancaire
                    WHERE reference = :reference
                    LIMIT 1
                SQL,
                [
                    'reference' => $reference,
                ]
            );
        } while ($existe);

        return $reference;
    }

    /**
     * Calcule l’impact d’un mouvement sur le compte bancaire :
     * entrée = positif, sortie = négatif.
     */
    private function calculerImpactMouvement(
        MouvementTresorerie $mouvement,
        object $compte
    ): int {
        $montant = $mouvement->getMontant();

        if (
            $mouvement->getCompteDestination()?->getId()
            === $compte->getId()
        ) {
            return $montant;
        }

        if (
            $mouvement->getCompteSource()?->getId()
            === $compte->getId()
        ) {
            return -$montant;
        }

        return 0;
    }

    private function convertirEnDateImmutable(
        \DateTimeInterface $date
    ): \DateTimeImmutable {
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        return \DateTimeImmutable::createFromInterface($date);
    }
}