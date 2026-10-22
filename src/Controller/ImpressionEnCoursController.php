<?php

namespace App\Controller;

use App\Entity\AtelierMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Security\Droits;
use App\Service\Atelier\RegleAtelier;
use App\Service\Production\ImpressionEnCours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Impression en cours d'un rouleau (PD04 sur le poste, PD07 écran 1 sur
 * le téléphone ; docs/production-rouleau.md) : la machine en direct, la
 * pièce en cours, la vitesse, l'encre, l'heure de fin et la suite ;
 * « Pièce imprimée », pause, problème, « Impression terminée », puis
 * « Commencer la finition ». L'état se relit toutes les 20 s (lecture
 * seule). Tout voir : « Production ». Agir : « Statuts de production » et
 * la règle de l'atelier (RegleAtelier). Aucun montant. ERP local
 * seulement (AtelierHorsLigneSubscriber : 404 en ligne).
 */
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class ImpressionEnCoursController extends AbstractController
{
    private const CSRF = 'production-rouleau';

    /** Motifs proposés par « Signaler un problème » ; « pièce ratée » note la pièce au travail en cours. */
    public const PROBLEMES = [
        'piece_ratee' => 'Pièce ratée',
        'bourrage' => 'Bourrage ou support froissé',
        'tete' => 'Tête bouchée ou bandes',
        'couleur' => 'Couleur hors BAT',
        'encre' => 'Encre vide',
        'autre' => 'Autre',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImpressionEnCours $impression,
        private readonly RegleAtelier $regle,
    ) {
    }

    #[Route('/atelier/production/rouleau/{id}/en-cours', name: 'app_atelier_production_en_cours', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function enCours(TirageRouleau $tirage): Response
    {
        if (!\in_array($tirage->getStatut(), [TirageRouleau::STATUT_EN_IMPRESSION, TirageRouleau::STATUT_EN_PAUSE, TirageRouleau::STATUT_IMPRIME, TirageRouleau::STATUT_TERMINE], true)) {
            $this->addFlash('info', 'Ce rouleau n\'a pas démarré : la fiche de production dit ce qui manque.');

            return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId()]);
        }
        $raison = $this->regle->raisonRefusMachine($tirage->getMachine(), $this->moi());

        return $this->render('production_rouleau/en_cours.html.twig', $this->impression->etat($tirage, new \DateTimeImmutable()) + [
            'peutAgir' => null === $raison,
            'raison' => $raison,
            'problemes' => self::PROBLEMES,
        ]);
    }

    /** L'état relu par la page toutes les 20 s : lecture seule. */
    #[Route('/atelier/production/rouleau/{id}/en-cours/etat', name: 'app_atelier_production_en_cours_etat', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function etat(TirageRouleau $tirage): JsonResponse
    {
        $donnees = $this->impression->etat($tirage, new \DateTimeImmutable()) + ['peutAgir' => null === $this->regle->raisonRefusMachine($tirage->getMachine(), $this->moi())];
        $blocs = [];
        foreach (['direct', 'stats', 'pieces'] as $bloc) {
            $blocs[$bloc] = $this->renderView('production_rouleau/_en_cours_'.$bloc.'.html.twig', $donnees);
        }
        $reponse = $this->json(['statut' => $tirage->getStatut(), 'blocs' => $blocs]);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    #[Route('/atelier/production/rouleau/{id}/en-cours/{action}', name: 'app_atelier_production_en_cours_action', requirements: ['id' => '\d+', 'action' => 'piece|pause|reprendre|probleme|terminer'], methods: ['POST'])]
    public function agir(TirageRouleau $tirage, string $action, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine());
        $maintenant = new \DateTimeImmutable();
        try {
            switch ($action) {
                case 'piece':
                    $p = $this->impression->pieceImprimee($tirage, $maintenant);
                    $this->addFlash('success', \sprintf('%s imprimée à %s.', $this->impression->nomPiece($p), $maintenant->format('H:i')));
                    break;
                case 'pause':
                    $this->impression->pause($tirage, $maintenant);
                    $this->addFlash('success', 'Rouleau en pause : le temps de pause ne compte pas dans la vitesse.');
                    break;
                case 'reprendre':
                    $this->impression->reprendre($tirage, $this->moi(), $maintenant);
                    $this->addFlash('success', 'Impression reprise.');
                    break;
                case 'probleme':
                    $motif = (string) $request->request->get('motif');
                    $libelle = self::PROBLEMES[$motif] ?? self::PROBLEMES['autre'];
                    $detail = mb_substr(trim((string) $request->request->get('detail')), 0, 500);
                    $arret = $request->request->getBoolean('arret');
                    $this->impression->probleme($tirage, $this->moi(), 'autre' === $motif && '' !== $detail ? mb_substr($detail, 0, 120) : $libelle, $detail, $arret, 'piece_ratee' === $motif, $maintenant);
                    $this->addFlash('success', ($arret ? $tirage->getMachine()->getNom().' est à l\'arrêt, le rouleau est en pause' : 'Problème noté').' : le chef d\'atelier est prévenu.'.('piece_ratee' === $motif ? ' La pièce ratée est comptée en gâche de son travail : réimprimez-la.' : ''));
                    break;
                case 'terminer':
                    $gaches = [];
                    foreach ((array) $request->request->all('gache') as $id => $n) {
                        if (is_numeric($id) && is_numeric($n)) {
                            $gaches[(int) $id] = max(0, min(999, (int) $n));
                        }
                    }
                    $notes = $this->impression->terminer($tirage, $this->moi(), $gaches, $maintenant);
                    $this->addFlash('success', \sprintf('Rouleau %s imprimé : la machine est libre. Les travaux sèchent, puis « Commencer la finition ».', $tirage->getNumero()).([] !== $notes ? ' '.implode(' ', $notes) : ' Matière et encre déduites du stock.'));
                    break;
            }
        } catch (\DomainException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_en_cours', ['id' => $tirage->getId()]);
    }

    /** « Commencer la finition » d'un travail imprimé (PD04 après l'impression, PD05). */
    #[Route('/atelier/production/ordre/{id}/finition', name: 'app_atelier_production_finition', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function finition(OrdreProduction $ordre, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($ordre->getTirage()?->getMachine() ?? $ordre->getMachine());
        try {
            $this->impression->commencerFinition($ordre, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Finition de %s commencée.', $ordre->getCommandeDetail()?->getCommande()?->getNumero() ?? $ordre->getNumero()));
        } catch (\DomainException|\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
        }
        $tirage = $ordre->getTirage();
        if ('etiquettes' === $request->request->get('retour')) {
            return $this->redirectToRoute('app_atelier_production_etiquettes', ['commande' => $ordre->getCommandeDetail()?->getCommande()?->getId()]);
        }

        return null !== $tirage && !$tirage->estAnnule()
            ? $this->redirectToRoute('app_atelier_production_en_cours', ['id' => $tirage->getId(), '_fragment' => 'apres'])
            : $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
    }

    /**
     * « Mon travail » › impression en cours (PD07) : le rouleau qui
     * s'imprime sur ma machine (opérateur affecté ou poste de la machine) ;
     * pour le chef d'atelier, la liste des rouleaux en impression.
     */
    #[Route('/atelier/operateur/en-cours', name: 'app_atelier_operateur_en_cours', methods: ['GET'])]
    public function operateur(): Response
    {
        $chef = $this->isGranted(Droits::ATELIER_CHEF);
        $machines = [];
        $poste = $this->regle->machineDuPoste();
        if (null !== $poste) {
            $machines[(int) $poste->getId()] = $poste;
        }
        /** @var list<AtelierMachine> $affectees */
        $affectees = $this->em->createQueryBuilder()->select('a', 'm')->from(AtelierMachine::class, 'a')->join('a.machine', 'm')->join('a.operateurs', 'u')
            ->where('u = :moi')->setParameter('moi', $this->moi())->getQuery()->getResult();
        foreach ($affectees as $a) {
            $machines[(int) $a->getMachine()->getId()] ??= $a->getMachine();
        }
        $rouleaux = [];
        foreach ($chef || [] === $machines ? [null] : array_values($machines) as $m) {
            foreach ($this->impression->lances($m) as $t) {
                $rouleaux[(int) $t->getId()] = $t;
            }
        }
        $rouleaux = array_values($rouleaux);
        // Le mien, s'il est seul ; sinon la liste (chef, plusieurs machines, ou personne d'affecté).
        if (1 === \count($rouleaux) && ([] !== $machines || $chef)) {
            return $this->redirectToRoute('app_atelier_production_en_cours', ['id' => $rouleaux[0]->getId()]);
        }

        return $this->render('production_rouleau/operateur_en_cours.html.twig', [
            'rouleaux' => $rouleaux,
            'machines' => array_values(array_map(static fn (Machines $m): string => (string) $m->getNom(), $machines)),
            'chef' => $chef,
        ]);
    }

    private function exigerAction(?Machines $machine): void
    {
        $raison = $this->regle->raisonRefusMachine($machine, $this->moi());
        if (null !== $raison) {
            throw $this->createAccessDeniedException($raison);
        }
    }

    private function verifierJeton(Request $request): void
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
