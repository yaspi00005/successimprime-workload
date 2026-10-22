<?php

namespace App\Controller;

use App\Entity\Achats;
use App\Entity\Fournisseurs;
use App\Form\FournisseursType;
use App\Repository\FournisseursRepository;
use App\Service\Achats\CalculsAchat;
use App\Service\Achats\SuiviAchats;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats › Fournisseurs (maquette SU01) et fiche fournisseur (maquette
 * SU02), docs/achats.md. Réservé à la direction (access_control, /fournisseurs).
 */
#[Route('/fournisseurs')]
final class FournisseursController extends AbstractController
{
    #[Route('', name: 'app_fournisseur_index', methods: ['GET'])]
    public function index(
        FournisseursRepository $fournisseursRepository,
        SuiviAchats $suivi
    ): Response {
        $aujourdhui = new \DateTimeImmutable('today');
        $fournisseurs = $fournisseursRepository->findBy([], ['actif' => 'DESC', 'nom' => 'ASC']);
        $lignes = $this->lignesListe($fournisseurs, $suivi, $aujourdhui);
        $dettes = $suivi->dettes($aujourdhui);
        $reappro = $suivi->reappro($aujourdhui);

        // Achats du mois : commandes passées ce mois-ci, rendues Bamako.
        $mois = ['montant' => 0, 'nombre' => 0];
        $enRoute = [];
        foreach ($suivi->achats() as $achat) {
            if (!SuiviAchats::estPassee($achat)) {
                continue;
            }
            if (SuiviAchats::dateCommande($achat)->format('Y-m') === $aujourdhui->format('Y-m')) {
                $mois['montant'] += $achat->getTotalRendu();
                ++$mois['nombre'];
            }
            if ($achat->estEnCours()) {
                $enRoute[] = ['achat' => $achat, 'prevue' => $suivi->livraisonPrevue($achat, $aujourdhui)];
            }
        }
        usort($enRoute, static fn (array $a, array $b): int => ($a['prevue'] ?? $aujourdhui) <=> ($b['prevue'] ?? $aujourdhui));

        $echeances = array_filter(array_column($dettes, 'echeance'));

        return $this->render('fournisseurs/index.html.twig', [
            'lignes' => $lignes,
            'kpis' => [
                'mois' => $mois,
                'du' => array_sum(array_column($dettes, 'du')),
                'echeance' => $echeances !== [] ? min($echeances) : null,
                'en_route' => $enRoute,
                'bientot' => \count(array_filter($reappro, static fn (array $l): bool => $l['bientot'])),
            ],
            'aujourdhui' => $aujourdhui,
            'marge' => $suivi->margeSecurite(),
        ]);
    }

    #[Route('/export', name: 'app_fournisseur_export', methods: ['GET'])]
    public function export(
        FournisseursRepository $fournisseursRepository,
        SuiviAchats $suivi
    ): StreamedResponse {
        $aujourdhui = new \DateTimeImmutable('today');
        $lignes = $this->lignesListe($fournisseursRepository->findBy([], ['nom' => 'ASC']), $suivi, $aujourdhui);
        $voirDu = $this->isGranted('ROLE_TRESORERIE_VOIR');

        $reponse = new StreamedResponse(static function () use ($lignes, $voirDu, $aujourdhui): void {
            $sortie = fopen('php://output', 'wb');
            // BOM pour qu'Excel lise les accents.
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Fournisseur', 'Fournit', 'Origine', 'Contact', 'Téléphone', 'Délai promis (j)', 'Délai réel moyen (j)', 'À l\'heure sur 12 mois (%)', 'Achats '.$aujourdhui->format('Y'), 'Dû', 'Prochaine commande', 'Actif'], ';', '"', '');

            foreach ($lignes as $l) {
                $f = $l['fournisseur'];
                $prochaine = $l['prochaine'];
                fputcsv($sortie, [
                    $f->getNomCourt(),
                    $f->getFournit(),
                    $f->getOrigine(),
                    $f->getContactNom(),
                    $f->getContactTelephone() ?? $f->getTelephone(),
                    $f->getDelaiPromisJours(),
                    $l['stats']['delaiReel'] !== null ? str_replace('.', ',', (string) $l['stats']['delaiReel']) : '',
                    $l['stats']['aLHeure'],
                    $l['stats']['achatsAnnee'],
                    $voirDu ? ($l['du']['du'] ?? 0) : '',
                    $prochaine !== null ? SuiviAchats::nomCourt($prochaine['article']).' · '.CalculsAchat::texteProchaineCommande($prochaine['urgence'], $prochaine['date']) : '',
                    $f->isActif() ? 'oui' : 'non',
                ], ';', '"', '');
            }

            fclose($sortie);
        });

        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="fournisseurs-%s.csv"', $aujourdhui->format('Y-m-d')));

        return $reponse;
    }

    #[Route('/new', name: 'app_fournisseur_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $fournisseur = new Fournisseurs();
        $form = $this->createForm(FournisseursType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->persist($fournisseur);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash(
                    'error',
                    'Un fournisseur portant ce nom existe déjà.'
                );

                return $this->render('fournisseurs/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Le fournisseur « %s » a été ajouté avec succès.',
                    $fournisseur->getNom()
                )
            );

            return $this->redirectToRoute(
                'app_fournisseur_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('fournisseurs/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_fournisseur_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(Fournisseurs $fournisseur, SuiviAchats $suivi): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $stats = $suivi->statistiquesDe($fournisseur, $aujourdhui);

        $commandes = [];
        foreach ($suivi->achats() as $achat) {
            if ($achat->getFournisseur() !== $fournisseur) {
                continue;
            }
            $abimees = 0;
            $unite = null;
            foreach ($achat->getLignes() as $ligne) {
                $abimees += $ligne->getQuantiteAbimee();
                if ($ligne->getQuantiteAbimee() > 0) {
                    $unite = $ligne->getArticle()?->getUniteAchat();
                }
            }
            $prevue = $achat->estEnCours() ? $suivi->livraisonPrevue($achat, $aujourdhui) : null;
            $commandes[] = [
                'achat' => $achat,
                'date' => SuiviAchats::dateCommande($achat),
                'ecart' => CalculsAchat::ecart($achat->getStatut() === Achats::STATUT_DEMANDE ? Achats::STATUT_COMMANDEE : $achat->getStatut(), $achat->getDatePromise(), $achat->getDateReception(), $abimees, $unite, $prevue),
            ];
        }

        $delaiReel = $stats['delaiReel'];
        $promis = $fournisseur->getDelaiPromisJours();

        return $this->render('fournisseurs/show.html.twig', [
            'fournisseur' => $fournisseur,
            'stats' => $stats,
            'produits' => $suivi->produits($fournisseur, $aujourdhui),
            'commandes' => $commandes,
            // Conseil : compter le délai réel dans les alertes quand il dépasse le délai promis.
            'conseil' => $delaiReel !== null && $promis !== null && (int) ceil($delaiReel) > $promis ? ['reel' => (int) ceil($delaiReel), 'promis' => $promis] : null,
            'du' => $suivi->dettes($aujourdhui)[(int) $fournisseur->getId()] ?? null,
        ]);
    }

    /**
     * Une ligne par fournisseur pour la liste et l'export.
     *
     * @param list<Fournisseurs> $fournisseurs
     *
     * @return list<array{fournisseur: Fournisseurs, stats: array<string, mixed>, du: ?array<string, mixed>, prochaine: ?array<string, mixed>}>
     */
    private function lignesListe(array $fournisseurs, SuiviAchats $suivi, \DateTimeImmutable $aujourdhui): array
    {
        $dettes = $suivi->dettes($aujourdhui);
        $prochaines = SuiviAchats::prochainesCommandes($suivi->reappro($aujourdhui));
        $lignes = [];

        foreach ($fournisseurs as $fournisseur) {
            $id = (int) $fournisseur->getId();
            $lignes[] = [
                'fournisseur' => $fournisseur,
                'stats' => $suivi->statistiquesDe($fournisseur, $aujourdhui),
                'du' => $dettes[$id] ?? null,
                'prochaine' => $prochaines[$id] ?? null,
            ];
        }

        return $lignes;
    }

    #[Route(
        '/{id}/edit',
        name: 'app_fournisseur_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(FournisseursType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash(
                    'error',
                    'Un autre fournisseur porte déjà ce nom.'
                );

                return $this->render('fournisseurs/edit.html.twig', [
                    'fournisseur' => $fournisseur,
                    'form' => $form,
                ]);
            }

            $this->addFlash(
                'success',
                'Le fournisseur a été modifié avec succès.'
            );

            return $this->redirectToRoute(
                'app_fournisseur_show',
                ['id' => $fournisseur->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('fournisseurs/edit.html.twig', [
            'fournisseur' => $fournisseur,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}/delete',
        name: 'app_fournisseur_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $fournisseur->getId(),
            $request->getPayload()->getString('_token')
        )) {
            if (!$fournisseur->getAchats()->isEmpty()) {
                $this->addFlash(
                    'error',
                    'Ce fournisseur a des achats liés et ne peut pas être supprimé. Désactivez-le à la place.'
                );

                return $this->redirectToRoute(
                    'app_fournisseur_show',
                    ['id' => $fournisseur->getId()],
                    Response::HTTP_SEE_OTHER
                );
            }

            $entityManager->remove($fournisseur);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Le fournisseur a été supprimé.'
            );
        }

        return $this->redirectToRoute(
            'app_fournisseur_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route(
        '/{id}/toggle-actif',
        name: 'app_fournisseur_toggle_actif',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggleActif(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'toggle_actif' . $fournisseur->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $fournisseur->setActif(!$fournisseur->isActif());
            $entityManager->flush();

            $this->addFlash(
                'success',
                $fournisseur->isActif()
                    ? 'Le fournisseur a été réactivé.'
                    : 'Le fournisseur a été désactivé.'
            );
        }

        return $this->redirectToRoute(
            'app_fournisseur_show',
            ['id' => $fournisseur->getId()],
            Response::HTTP_SEE_OTHER
        );
    }
}
