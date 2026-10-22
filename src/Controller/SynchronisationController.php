<?php

namespace App\Controller;

use App\Entity\SynchroEchange;
use App\Service\Sauvegarde\Sauvegardes;
use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\CauseSynchro;
use App\Service\Synchro\Synchro;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Sécurité › Synchronisation (maquette 39) : état de la liaison
 * entre l'ERP local et le site, derniers échanges, éléments bloqués.
 */
#[Route('/reglages/securite/synchronisation', name: 'app_synchronisation_')]
#[IsGranted('ROLE_ADMIN')]
final class SynchronisationController extends AbstractController
{
    public function __construct(private readonly Synchro $synchro)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Sauvegardes $sauvegardes): Response
    {
        $maintenant = new \DateTimeImmutable();
        $reussite = $this->synchro->dateEtat(AgentSynchro::ETAT_REUSSITE);
        $cycle = $this->synchro->dateEtat(AgentSynchro::ETAT_CYCLE);
        $contact = $this->synchro->dateEtat(AgentSynchro::ETAT_CONTACT);
        $erreur = $this->synchro->etat(AgentSynchro::ETAT_ERREUR);
        $sauvegarde = $sauvegardes->etat($maintenant);

        return $this->render('synchronisation/index.html.twig', [
            'en_ligne' => $this->synchro->estEnLigne(),
            'configuree' => $this->synchro->estConfiguree(),
            'site' => parse_url($this->synchro->url(), \PHP_URL_HOST) ?: $this->synchro->url(),
            'reussite' => $reussite,
            'cycle' => $cycle,
            'contact' => $contact,
            'erreur' => $erreur,
            'latence' => $this->synchro->etat(AgentSynchro::ETAT_LATENCE),
            'alerte' => Synchro::alerte($this->synchro->estConfiguree() && !$this->synchro->estEnLigne(), $reussite, $this->synchro->dateEtat(AgentSynchro::ETAT_MISE_EN_ROUTE), $maintenant, $erreur),
            'en_attente' => $this->synchro->enAttente(),
            'recus_jour' => $this->synchro->compterEchanges(SynchroEchange::VERS_LOCAL, $maintenant->setTime(0, 0), [SynchroEchange::OK, SynchroEchange::CONFLIT]),
            'envoyes_jour' => $this->synchro->compterEchanges(SynchroEchange::VERS_EN_LIGNE, $maintenant->setTime(0, 0), [SynchroEchange::OK]),
            'erreurs' => $this->synchro->compterEchanges('', $maintenant->modify('-24 hours'), [SynchroEchange::ERREUR, SynchroEchange::REPRISE]),
            'erreurs_ouvertes' => $this->synchro->compterEchanges('', $maintenant->modify('-24 hours'), [SynchroEchange::ERREUR, SynchroEchange::REPRISE], true),
            'conflits' => $this->synchro->compterEchanges('', $maintenant->modify('-7 days'), [SynchroEchange::CONFLIT]),
            'echanges' => $this->synchro->echanges(12),
            'bloques' => $this->synchro->bloques(),
            'sauvegarde' => $sauvegarde,
            'schema_site' => $this->synchro->etat(AgentSynchro::ETAT_SCHEMA),
            'panne' => $this->synchro->panne($maintenant),
            'taille_lot' => AgentSynchro::tailleLot($this->synchro->etat(AgentSynchro::ETAT_TAILLE_LOT)),
        ]);
    }

    #[Route('/journal', name: 'journal', methods: ['GET'])]
    public function journal(Request $request): Response
    {
        $statut = \in_array($request->query->get('statut'), [SynchroEchange::OK, SynchroEchange::REPRISE, SynchroEchange::CONFLIT, SynchroEchange::ERREUR], true) ? (string) $request->query->get('statut') : null;
        $sens = \in_array($request->query->get('sens'), [SynchroEchange::VERS_EN_LIGNE, SynchroEchange::VERS_LOCAL, SynchroEchange::SYSTEME], true) ? (string) $request->query->get('sens') : null;

        return $this->render('synchronisation/journal.html.twig', [
            'echanges' => $this->synchro->echanges(300, $statut, $sens),
            'statut' => $statut,
            'sens' => $sens,
        ]);
    }

    #[Route('/maintenant', name: 'maintenant', methods: ['POST'])]
    public function maintenant(Request $request, AgentSynchro $agent): Response
    {
        if (!$this->isCsrfTokenValid('synchro', (string) $request->request->get('_token')) || $this->synchro->estEnLigne()) {
            throw $this->createAccessDeniedException();
        }
        set_time_limit(300);
        $r = $agent->cycle();
        $reste = $this->synchro->enAttente();
        match ($r['statut']) {
            'ok' => $this->addFlash($reste > 0 && 0 === $r['envoyes'] ? 'warning' : 'success', \sprintf('Synchronisé : %d envoyée%s, %d reçue%s%s.', $r['envoyes'], $r['envoyes'] > 1 ? 's' : '', $r['recus'], $r['recus'] > 1 ? 's' : '', $r['conflits'] > 0 ? ', '.$r['conflits'].' conflit(s) à regarder' : '').CauseSynchro::pourquoiRienNePart($reste, $r['envoyes'], \count($this->synchro->bloques()), $r['attente'] ?? null)),
            'occupe' => $this->addFlash('info', 'Une synchronisation est déjà en cours.'),
            default => $this->addFlash('danger', 'Synchronisation impossible : '.$r['message'].(isset($r['cause']) ? ' '.CauseSynchro::conseil($r['cause'], (string) parse_url($this->synchro->url(), \PHP_URL_HOST)) : '')),
        };

        return $this->redirectToRoute('app_synchronisation_index');
    }

    #[Route('/ecarter/{id}', name: 'ecarter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ecarter(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ecarter'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->synchro->ignorer($id)
            ? $this->addFlash('success', 'Élément écarté : il ne sera plus envoyé. La donnée repartira à sa prochaine modification.')
            : $this->addFlash('warning', 'Cet élément n\'est plus bloqué.');

        return $this->redirectToRoute('app_synchronisation_index');
    }
}
