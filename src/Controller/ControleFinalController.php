<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\ControleFinalRepository;
use App\Security\FichierEnvoye;
use App\Service\Atelier\RegleAtelier;
use App\Service\Http\Entree;
use App\Service\Production\ColisCommandes;
use App\Service\Production\ControlesFinaux;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôle final (PD06, docs/production-colis.md) : commandes à contrôler,
 * comparaison au BAT et photo, cases par ligne, colis scannés, temps et
 * consommé, « Refaire une pièce », « Prêt pour la livraison », puis la
 * tournée (bureau) et le message WhatsApp préparé. ERP local seulement.
 */
#[Route('/atelier/production/controle', name: 'app_atelier_production_controle')]
final class ControleFinalController extends AbstractController
{
    private const CSRF = ProductionColisController::CSRF;

    public function __construct(
        private readonly ControlesFinaux $controles,
        private readonly ColisCommandes $colis,
        private readonly ControleFinalRepository $depot,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    #[IsGranted('ROLE_PRODUCTION_VOIR')]
    public function liste(): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('production_colis/controles.html.twig', [
            'liste' => $this->controles->aControler($maintenant),
            'pretes' => $this->controles->preteesDuJour($maintenant),
        ]);
    }

    #[Route('/{id}', name: '_commande', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_PRODUCTION_VOIR')]
    public function commande(Commandes $commande): Response
    {
        if ($commande->isDeleted()) {
            throw $this->createNotFoundException();
        }
        $maintenant = new \DateTimeImmutable();

        return $this->render('production_colis/controle.html.twig', $this->controles->page($commande, $this->moi(), $this->peutPlanifier(), $maintenant) + [
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
            'maintenant' => $maintenant,
        ]);
    }

    /** « Prêt pour la livraison » : les cases de chaque ligne ; la commande passe « Prête » si les colis sont scannés. */
    #[Route('/{id}/valider', name: '_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function valider(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        try {
            $saisie = $request->request->all('lignes');
            $r = $this->controles->valider($commande, $saisie, $this->moi(), new \DateTimeImmutable());
            if ([] !== $r['manque']) {
                $this->addFlash('danger', 'Contrôle pas encore validé. À vérifier : '.implode(' ; ', \array_slice($r['manque'], 0, 6)).'.');
            } elseif ($r['prete']) {
                $this->addFlash('success', \sprintf('%s prête pour la livraison.', $commande->getNumero()));
            } else {
                $etat = $this->colis->etat($commande);
                $this->addFlash('success', 0 === $etat['total']
                    ? 'Contrôle validé. Imprimez et collez les étiquettes des colis : la commande passera « Prête » au dernier scan.'
                    : \sprintf('Contrôle validé. %d colis sur %d scannés : la commande passera « Prête » au dernier scan.', $etat['scannes'], $etat['total']));
            }
            if ([] !== ($r['notes'] ?? [])) {
                $this->addFlash('warning', implode(' ', $r['notes']));
            }
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
    }

    /** Photo de la pièce imprimée (appareil photo du téléphone ou caméra de la page). */
    #[Route('/{id}/photo', name: '_photo', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function photo(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        $fichier = $request->files->get('photo');
        try {
            if (!$fichier instanceof UploadedFile) {
                throw new \DomainException('Prenez ou choisissez une photo.');
            }
            $this->controles->photographier($commande, $fichier, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', 'Photo de la pièce enregistrée.');
        } catch (\DomainException|\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
    }

    /** La photo, à qui voit la production (jamais depuis public/). */
    #[Route('/{id}/photo', name: '_photo_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_PRODUCTION_VOIR')]
    public function photoVoir(Commandes $commande): Response
    {
        $chemin = $this->controles->cheminPhoto($this->depot->deLaCommande($commande));
        if (null === $chemin) {
            throw $this->createNotFoundException('Aucune photo pour ce contrôle.');
        }

        return FichierEnvoye::reponse($chemin, 'controle-'.$commande->getNumero().'.'.pathinfo($chemin, \PATHINFO_EXTENSION));
    }

    /** « Refaire une pièce » : le travail repart en production pour N pièces. */
    #[Route('/ordre/{id}/refaire', name: '_refaire', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function refaire(OrdreProduction $ordre, Request $request, RegleAtelier $regle): Response
    {
        $this->jeton($request);
        $commande = $ordre->getCommandeDetail()?->getCommande() ?? throw $this->createNotFoundException();
        $refus = $regle->raisonRefus($ordre, $this->moi());
        if (null !== $refus) {
            $this->addFlash('danger', $refus);
        } else {
            try {
                $pieces = Entree::entier($request->request, 'pieces', 1);
                $this->controles->refaire($ordre, $pieces, (string) $request->request->get('motif'), $this->moi(), new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('%d pièce%s à refaire : le travail est revenu dans Ma file (colonne « Prêt »), le chef d\'atelier est prévenu. Ses colis seront à rescanner.', $pieces, $pieces > 1 ? 's' : ''));
            } catch (\LogicException|\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
    }

    /**
     * Bureau : bon de livraison des lignes prêtes, validé et planifié dans la
     * tournée du livreur, en un geste (droits de BonLivraisonController et
     * de TourneeController réunis, pas élargis).
     */
    #[Route('/{id}/tournee', name: '_tournee', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('ROLE_LIVRAISON') and (is_granted('ROLE_ADMIN') or is_granted('ROLE_COMMANDE'))"))]
    public function tournee(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        $livreur = Entree::entier($request->request, 'livreur') > 0 ? $this->em->find(User::class, Entree::entier($request->request, 'livreur')) : null;
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour')) ?: null;
        $livreurs = $this->controles->livreurs();
        try {
            if (null === $livreur || !\in_array($livreur, $livreurs, true)) {
                throw new \InvalidArgumentException('Choisissez le livreur.');
            }
            if (null === $jour || $jour < (new \DateTimeImmutable())->setTime(0, 0)) {
                throw new \InvalidArgumentException('Choisissez le jour de la livraison (aujourd\'hui ou plus tard).');
            }
            $bon = $this->controles->ajouterATournee($commande, $this->moi(), $livreur, $jour, (string) $request->request->get('creneau'));
            $this->addFlash('success', \sprintf('Ajoutée à la tournée (bon %s).', $bon->getNumero()));
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
    }

    /**
     * « Prévenir sur WhatsApp » : note au journal de la commande, puis le
     * lien wa.me prêt (ouvert dans un nouvel onglet). Rien pour une fiche
     * de démonstration ni pour le livreur de cette livraison.
     */
    #[Route('/{id}/whatsapp', name: '_whatsapp', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function whatsapp(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        $maintenant = new \DateTimeImmutable();
        if (!ColisCommandes::estPrete($commande)) {
            $this->addFlash('danger', 'La commande n\'est pas encore prête.');

            return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
        }
        $lien = $this->controles->lienMessage($commande, $this->moi(), $maintenant);
        if (null === $lien) {
            $this->addFlash('warning', ColisCommandes::client($commande->getClients())['demo'] ? 'Fiche de démonstration : aucun message.' : 'Aucun numéro WhatsApp utilisable pour ce client.');

            return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commande->getId()]);
        }
        $this->controles->noterMessage($commande, $this->moi(), $maintenant);

        return $this->redirect($lien);
    }

    private function peutPlanifier(): bool
    {
        return $this->isGranted('ROLE_LIVRAISON') && ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_COMMANDE'));
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        \assert($moi instanceof User);

        return $moi;
    }
}
