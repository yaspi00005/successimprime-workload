<?php

namespace App\Controller;

use App\Entity\DemandeDevis;
use App\Entity\User;
use App\Security\MessagerieVoter;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\ReglesReponse;
use App\Service\Site\EngagementsSite;
use App\Service\Site\RappelsSite;
use App\Service\Site\TableauEngagements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ERP de l'imprimerie, côté site :
 *  - « Demandes de contact » (maquette EC08) : chaque message ou rappel
 *    laissé sur la page Contact, avec la conversation attribuée qu'il a
 *    ouverte dans la messagerie clients (le délai y est imputé à la
 *    personne attribuée), et la liste « À rappeler » triée par heure
 *    (caisse et droit de la messagerie) ;
 *  - « Engagements clients » (EC09, direction) : les chiffres tenus du
 *    mois et leur publication sur la page « Nos engagements ».
 */
final class DemandesContactController extends AbstractController
{
    private const CSRF = 'demandes-contact';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RappelsSite $rappels,
        private readonly ReglesReponse $regles,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    #[Route('/clients/messages/site', name: 'app_messages_clients_site', methods: ['GET'])]
    #[IsGranted(MessagerieVoter::BOITE)]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $onglet = 'rappels' === $request->query->get('onglet') ? 'rappels' : 'demandes';
        /** @var list<DemandeDevis> $demandes */
        $demandes = $this->em->createQuery('SELECT d, c FROM '.DemandeDevis::class.' d LEFT JOIN d.conversation c WHERE d.nature IN (:n) AND d.creeLe >= :depuis ORDER BY d.creeLe DESC')
            ->setParameter('n', [DemandeDevis::NATURE_CONTACT, DemandeDevis::NATURE_RAPPEL])
            ->setParameter('depuis', $maintenant->modify('-30 days')->setTime(0, 0))
            ->setMaxResults(200)->getResult();

        $heures = $this->regles->heures();
        $cible = $this->regles->premiere();
        $lignes = [];
        $aujourdhui = 0;
        $delais = [];
        $enRetard = 0;
        foreach ($demandes as $d) {
            $c = $d->getConversation();
            if ($d->getCreeLe()->format('Y-m-d') === $maintenant->format('Y-m-d')) {
                ++$aujourdhui;
            }
            $delai = null;
            $retard = false;
            if (null !== $c && null !== $c->getPremiereReponseLe()) {
                $delai = $heures->ecoule($c->getCreeLe(), $c->getPremiereReponseLe());
                $delais[] = $delai;
                $retard = $delai > $cible;
            } elseif (null !== $c && $c->estEnAttente() && null !== $c->getAttenteDepuis()) {
                $delai = $heures->ecoule($c->getAttenteDepuis(), $maintenant);
                $retard = $delai > $cible;
                $enRetard += $retard ? 1 : 0;
            }
            $lignes[] = ['demande' => $d, 'conversation' => $c, 'delai' => $delai, 'retard' => $retard, 'attente' => null !== $c && null === $c->getPremiereReponseLe()];
        }
        $aRappeler = $this->rappels->aRappeler();

        return $this->render('demandes_contact/index.html.twig', [
            'onglet' => $onglet,
            'lignes' => $lignes,
            'aRappeler' => $aRappeler,
            'kpis' => [
                'aujourdhui' => $aujourdhui,
                'moyenne' => [] === $delais ? null : (int) round(array_sum($delais) / \count($delais)),
                'enRetard' => $enRetard,
                'rappels' => \count($aRappeler),
                'cible' => $cible,
            ],
            'rappelActif' => $this->rappels->actif(),
            'maintenant' => $maintenant,
            'jeton' => self::CSRF,
            'enLigne' => $this->enLigne,
        ]);
    }

    /** Rappel passé : il sort de la liste « À rappeler ». */
    #[Route('/clients/messages/site/rappel/{id}/fait', name: 'app_messages_clients_site_rappel_fait', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(MessagerieVoter::BOITE)]
    public function rappelFait(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $d = $this->em->find(DemandeDevis::class, $id);
        if (!$d instanceof DemandeDevis || !$d->estRappel()) {
            throw $this->createNotFoundException();
        }
        if (null === $d->getRappelFaitLe()) {
            $moi = $this->getUser();
            $d->marquerRappelFait($moi instanceof User ? Conversations::nom($moi) : 'Équipe', new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('Rappel de %s noté comme fait.', $d->getNom()));
        }

        return $this->redirectToRoute('app_messages_clients_site', ['onglet' => 'rappels'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/clients/engagements', name: 'app_engagements_direction', methods: ['GET'])]
    #[IsGranted(MessagerieVoter::DIRECTION)]
    public function engagements(Request $request, TableauEngagements $tableau, EngagementsSite $engagements): Response
    {
        [$debut, $fin, $mois] = self::mois($request->query->getString('mois'));
        $maintenant = new \DateTimeImmutable();

        return $this->render('demandes_contact/engagements.html.twig', [
            'mesures' => $tableau->mesurer($debut, $fin, $maintenant),
            'debut' => $debut,
            'mois' => $mois,
            'periode' => self::nomMois($debut),
            'publies' => $engagements->chiffresPublies(),
            'promesses' => $engagements->promesses(),
            'libelles' => EngagementsSite::LIBELLES,
            'jeton' => self::CSRF,
            'enLigne' => $this->enLigne,
        ]);
    }

    #[Route('/clients/engagements/publier', name: 'app_engagements_direction_publier', methods: ['POST'])]
    #[IsGranted(MessagerieVoter::DIRECTION)]
    public function publier(Request $request, TableauEngagements $tableau): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        [$debut, $fin, $mois] = self::mois($request->request->getString('mois'));
        $maintenant = new \DateTimeImmutable();
        if ('retirer' === $request->request->get('action')) {
            $tableau->retirer();
            $this->addFlash('success', 'Les chiffres ne sont plus affichés sur le site.');
        } else {
            $n = $tableau->publier($tableau->mesurer($debut, $fin, $maintenant), self::nomMois($debut), $maintenant);
            $this->addFlash(0 === $n ? 'warning' : 'success', 0 === $n
                ? 'Aucun chiffre mesuré pour ce mois : rien n\'est affiché sur le site.'
                : \sprintf('%d chiffre%s publié%s : le site les affiche après la prochaine synchronisation.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : ''));
        }

        return $this->redirectToRoute('app_engagements_direction', ['mois' => $mois], Response::HTTP_SEE_OTHER);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string} début, fin (exclue), « 2026-10 » */
    public static function mois(string $saisi): array
    {
        $debut = preg_match('/^(\d{4})-(\d{2})$/', $saisi, $m) && checkdate((int) $m[2], 1, (int) $m[1])
            ? new \DateTimeImmutable($m[1].'-'.$m[2].'-01')
            : (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);

        return [$debut, $debut->modify('+1 month'), $debut->format('Y-m')];
    }

    /** « octobre 2026 » */
    public static function nomMois(\DateTimeImmutable $d): string
    {
        $noms = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $noms[(int) $d->format('n')].' '.$d->format('Y');
    }
}
