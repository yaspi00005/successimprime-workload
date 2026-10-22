<?php

namespace App\Controller;

use App\Entity\ActionCaisse;
use App\Entity\User;
use App\Service\ActionsCaisse\ActionsCaisse;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mes actions (maquettes AC01 et AC02) : les lignes de la personne de
 * caisse, le compte à rebours en heures de travail et le résultat d'un
 * appel.
 */
#[Route('/caisse/actions', name: 'app_actions_')]
#[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
final class ActionsCaisseController extends AbstractController
{
    public const CSRF = 'actions-caisse';

    public function __construct(
        private readonly ActionsCaisse $actions,
        private readonly CacheItemPoolInterface $cache,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $this->rafraichir($maintenant);

        return $this->render('actions_caisse/index.html.twig', [
            'tableau' => $this->actions->tableau($this->moi(), $maintenant),
            'maintenant' => $maintenant,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(ActionCaisse $action): Response
    {
        $this->verifierAcces($action);
        $maintenant = new \DateTimeImmutable();

        return $this->render('actions_caisse/voir.html.twig', [
            'action' => $action,
            'compteur' => $this->actions->compteur($action, $maintenant),
            'ecoule' => $action->getFaiteLe() ? $this->actions->heures()->ecoule($action->getDepartLe(), $action->getFaiteLe()) : null,
            'regles' => $this->actions->regles(),
            'jeton' => self::CSRF,
            'resultats' => array_filter(ActionCaisse::RESULTATS, static fn (string $cle): bool => $action->attendPaiement() || !\in_array($cle, ActionCaisse::RESULTATS_PAIEMENT, true), \ARRAY_FILTER_USE_KEY),
        ]);
    }

    /** Appel ou WhatsApp touché : l'heure est la preuve enregistrée. */
    #[Route('/{id}/contact', name: 'contact', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function contact(ActionCaisse $action, Request $request): Response
    {
        $this->verifierAcces($action);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        if ($action->getEtat() === ActionCaisse::A_FAIRE) {
            $action->noterContact((string) $request->request->get('canal'), new \DateTimeImmutable());
            $this->em->flush();
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/faire', name: 'faire', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function faire(ActionCaisse $action, Request $request): Response
    {
        $this->verifierAcces($action);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $retour = $this->redirectToRoute('app_actions_voir', ['id' => $action->getId()]);
        if ($action->getEtat() !== ActionCaisse::A_FAIRE) {
            $this->addFlash('warning', 'Cette ligne est déjà fermée.');

            return $retour;
        }

        $resultat = (string) $request->request->get('resultat');
        $note = trim((string) $request->request->get('note'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('date')) ?: null;
        $moyen = trim((string) $request->request->get('moyen')) ?: null;
        $maintenant = new \DateTimeImmutable();

        $erreur = match (true) {
            !isset(ActionCaisse::RESULTATS[$resultat]) => 'Choisissez le résultat : sans résultat, la ligne ne compte pas comme faite.',
            \in_array($resultat, ActionCaisse::RESULTATS_PAIEMENT, true) && !$action->attendPaiement() => 'Ce résultat ne concerne que les paiements.',
            $resultat === 'promesse' && ($date === null || $date < $maintenant->setTime(0, 0)) => 'Indiquez la date promise (aujourd\'hui ou plus tard).',
            $action->getContactLe() === null && mb_strlen($note) < 5 => 'Sans appel ni WhatsApp depuis l\'application, une note est obligatoire.',
            default => null,
        };
        if ($erreur !== null) {
            $this->addFlash('danger', $erreur);

            return $retour;
        }

        $suite = $this->actions->faire($action, $resultat, mb_substr($note, 0, 1000), $this->moi(), $maintenant, $date, $moyen ? mb_substr($moyen, 0, 30) : null);
        $this->addFlash('success', $action->getPoints() > 0 ? \sprintf('Action faite : +%d pts.', $action->getPoints()) : 'Action faite, après le délai : pas de points.');
        if ($suite !== null) {
            $this->addFlash('info', \sprintf('Nouvelle ligne créée : %s le %s.', mb_strtolower($suite->libelleType()), $suite->getCreeLe()->format('d/m')));
        }

        return $retour;
    }

    /**
     * Création et mise à jour des lignes au plus toutes les 5 minutes, en
     * plus de la tâche planifiée (utile en local sans cron).
     */
    private function rafraichir(\DateTimeImmutable $maintenant): void
    {
        $verrou = $this->cache->getItem('actions_caisse_rafraichies');
        if ($verrou->isHit()) {
            $this->actions->actualiser($maintenant);

            return;
        }
        $this->actions->generer($maintenant);
        $this->actions->actualiser($maintenant);
        $this->cache->save($verrou->set(true)->expiresAfter(300));
    }

    private function verifierAcces(ActionCaisse $action): void
    {
        $a = $action->getAssigneA();
        if ($a !== null && $a !== $this->moi() && !$this->isGranted('ROLE_ACTIONS_GERER')) {
            throw $this->createAccessDeniedException('Cette ligne est attribuée à une autre personne.');
        }
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $moi;
    }
}
