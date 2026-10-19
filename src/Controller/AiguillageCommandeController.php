<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Service\NotificationsEquipe\AccordsPlafond;
use App\Service\ProduitsClient\Aiguillage;
use App\Service\ProduitsClient\LiensFichiers;
use App\Service\Stock\ReservationsCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aiguillage des lignes venues de « Mes produits » (maquette MP06,
 * docs/mes-produits.md) : « Confirmer et lancer » depuis la fiche commande,
 * et ouverture du fichier lié par l'opérateur ou le prépresse, après
 * vérification de son empreinte.
 */
final class AiguillageCommandeController extends AbstractController
{
    public function __construct(
        private readonly Aiguillage $aiguillage,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** Confirme la commande (si besoin) et applique l'aiguillage : file d'impression ou prépresse. */
    #[Route('/commandes/{id}/aiguillage/lancer', name: 'app_commandes_aiguillage_lancer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function lancer(#[MapEntity(id: 'id')] Commandes $commande, Request $request, ReservationsCommande $reservations, AccordsPlafond $plafond): Response
    {
        if (!$this->isCsrfTokenValid('aiguillage-'.$commande->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
        if ($this->enLigne) {
            $this->addFlash('warning', 'La file d\'impression est gérée par l\'ERP de l\'atelier : lancez la commande depuis le local.');
        } elseif ($commande->isDeleted() || $commande->attendValidationEntreprise() || $commande->estRefuseeParEntreprise()) {
            $this->addFlash('danger', 'Cette commande ne peut pas être lancée.');
        } elseif ($plafond->bloquee($commande)) {
            // Fenêtre bloquante (docs/notifications-equipe.md) : acompte ou accord de la direction d'abord.
            $this->addFlash('plafond_bloquant', $commande->getId());
        } else {
            $confirmee = !$commande->isStatut();
            $commande->setStatut(true);
            $utilisateur = $this->getUser();
            $crees = $this->aiguillage->lancer($commande, $utilisateur instanceof User ? $utilisateur : null);
            $this->em->flush();
            $etat = $this->aiguillage->pourCommande($commande);
            $this->addFlash('success', \sprintf('%s%d ligne%s dans la file d\'impression%s.', $confirmee ? 'Commande confirmée. ' : '', $crees, $crees > 1 ? 's' : '', $etat['prepresse'] > 0 ? \sprintf(', %d au prépresse', $etat['prepresse']) : ''));
            // Stock réservé à l'enregistrement (ReservationsCommandeListener) : un manque ne bloque pas.
            $manquants = $reservations->manquants($commande);
            if ([] !== $manquants) {
                $this->addFlash('warning', 'Stock insuffisant, commande lancée quand même : '.implode(' ; ', $manquants).'.');
            }
        }

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()]);
    }

    /**
     * « Ouvrir le PDF » : le fichier lié de la ligne, lu dans le dossier du
     * client. Si son empreinte ne correspond plus, une alerte s'affiche
     * avant (règle « Fichier modifié »).
     */
    #[Route('/commande-fichiers/lie/{id}', name: 'app_commande_fichier_lie', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ouvrir(#[MapEntity(id: 'id')] CommandesDetails $ligne, Request $request, LiensFichiers $liens): Response
    {
        if (!$this->isGranted('ROLE_PREPRESSE') && !$this->isGranted('ROLE_PRODUCTION_VOIR')) {
            throw $this->createAccessDeniedException();
        }
        $produit = $ligne->getProduitClient();
        $lien = $ligne->getFichierLie();
        if (null === $produit || null === $lien) {
            throw $this->createNotFoundException('Cette ligne n\'a pas de fichier lié.');
        }
        $verif = $liens->verifier($produit, $lien, $this->enLigne);
        if (LiensFichiers::IDENTIQUE === $verif['etat'] || ($request->query->getBoolean('quandMeme') && LiensFichiers::MODIFIE === $verif['etat'])) {
            return ProduitsClientsPrepresseController::envoyer((string) $verif['chemin']);
        }

        return $this->render('commandes/fichier_lie_alerte.html.twig', ['ligne' => $ligne, 'lien' => $lien, 'etat' => $verif['etat']], new Response('', Response::HTTP_CONFLICT));
    }
}
