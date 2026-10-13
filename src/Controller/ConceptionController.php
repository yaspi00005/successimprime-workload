<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\Conception;
use App\Entity\User;
use App\Service\AnalyseFichierImpression;
use App\Service\EspaceClient\EspaceClient;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\TableauConception;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de conception du graphiste (W01) et évolution d'un travail
 * (W02). Chacun voit ses travaux et ceux que personne n'a pris ; le
 * responsable de production voit tout le monde.
 */
#[Route('/atelier/pao', name: 'app_conception_')]
#[IsGranted('ROLE_PREPRESSE')]
final class ConceptionController extends AbstractController
{
    private const CSRF = 'conception';

    public function __construct(
        private readonly TableauConception $tableau,
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    #[Route('', name: 'tableau', methods: ['GET'])]
    public function tableau(Request $request): Response
    {
        $moi = $this->moi();
        $maintenant = new \DateTimeImmutable();
        $this->tableau->synchroniser($maintenant);
        $tous = $this->responsable() && $request->query->getBoolean('tous');
        $colonnes = $this->tableau->colonnes($moi, $tous, $maintenant);
        $enCours = $this->tableau->enCours($moi);

        return $this->render('conception/tableau.html.twig', [
            'colonnes' => $colonnes,
            'etapes' => Conception::ETAPES,
            'chiffres' => $this->tableau->chiffres($moi, $colonnes, $maintenant),
            'enCours' => $enCours !== null ? $this->tableau->carte($enCours, $maintenant) : null,
            'tous' => $tous,
            'responsable' => $this->responsable(),
            'maintenant' => $maintenant,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Conception $conception): Response
    {
        $this->autoriser($conception);
        $maintenant = new \DateTimeImmutable();
        $carte = $this->tableau->carte($conception, $maintenant);
        $fichiers = $conception->getControle()->getFichiers()->toArray();
        usort($fichiers, static fn ($a, $b): int => $a->getVersion() <=> $b->getVersion());

        return $this->render('conception/voir.html.twig', [
            'carte' => $carte,
            'c' => $conception,
            'evenements' => $conception->getEvenements(),
            'fichiers' => $fichiers,
            'difficultes' => Bareme::DIFFICULTES,
            'tempsPrevus' => Conception::TEMPS_PREVU,
            'regles' => Bareme::METIERS[Bareme::GRAPHISTE]['regles'],
            'valeursRegles' => array_map(fn (string $cle): int|float => $this->parametres->bareme()->valeur($cle), array_combine(array_keys(Bareme::METIERS[Bareme::GRAPHISTE]['regles']), array_keys(Bareme::METIERS[Bareme::GRAPHISTE]['regles']))),
            'faits' => $conception->getValideLe() !== null ? $conception->getFaits() : $conception->faitsPour($maintenant),
            'voirPoints' => $conception->getGraphiste() === null || $conception->getGraphiste() === $this->getUser() || $this->responsable(),
            'maintenant' => $maintenant,
            'message' => $this->tableau->message($conception),
            'jeton' => self::CSRF,
        ]);
    }

    /**
     * Poste du graphiste (maquette R03) : ses travaux à gauche, l'aperçu
     * du fichier au centre, le chronomètre et les échanges à droite.
     */
    #[Route('/poste/{id}', name: 'poste', requirements: ['id' => '\d+'], defaults: ['id' => null], methods: ['GET'])]
    public function poste(?int $id, EspaceClient $espace): Response
    {
        $moi = $this->moi();
        $maintenant = new \DateTimeImmutable();
        $this->tableau->synchroniser($maintenant);

        $travaux = [];
        foreach ($this->tableau->colonnes($moi, false, $maintenant) as $etape => $cartes) {
            if ($etape !== Conception::VALIDE) {
                array_push($travaux, ...$cartes);
            }
        }
        // Celui en cours d'abord, puis par échéance ; ceux chez le client à la fin.
        $rang = static fn (array $x): array => [
            $x['c']->enCours() ? 0 : ($x['etape'] === Conception::BAT_ENVOYE ? 2 : 1),
            $x['c']->getEcheanceBat()?->getTimestamp() ?? \PHP_INT_MAX,
        ];
        usort($travaux, static fn (array $a, array $b): int => $rang($a) <=> $rang($b));

        $conception = null;
        if ($id !== null) {
            $conception = $this->em->find(Conception::class, $id) ?? throw $this->createNotFoundException();
            $this->autoriser($conception);
        } elseif ($travaux !== []) {
            $conception = $travaux[0]['c'];
        }
        if ($conception === null) {
            return $this->render('conception/poste.html.twig', ['travaux' => [], 'carte' => null]);
        }

        $fichiers = array_map(static fn (CommandeDetailFichier $f): array => [
            'f' => $f,
            'disponible' => $espace->cheminSurDisque($f) !== null,
            'type' => str_starts_with((string) $f->getTypeMime(), 'image/') ? 'image' : ($f->getTypeMime() === 'application/pdf' ? 'pdf' : 'autre'),
            'fondsPerdus' => self::fondsPerdus($f),
        ], $espace->fichiersBat($conception->getControle()));

        return $this->render('conception/poste.html.twig', [
            'travaux' => $travaux,
            'carte' => $this->tableau->carte($conception, $maintenant),
            'c' => $conception,
            'fichiers' => $fichiers,
            'evenements' => array_values(array_filter($conception->getEvenements(), static fn (array $e): bool => \in_array($e['type'], ['brief', 'bat', 'retour', 'erreur', 'valide'], true))),
            'message' => $this->tableau->message($conception),
            'maintenant' => $maintenant,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/suivant', name: 'suivant', methods: ['POST'])]
    public function suivant(Request $request): Response
    {
        $this->verifierJeton($request);
        $maintenant = new \DateTimeImmutable();
        $suivant = $this->tableau->suivant($this->moi(), $maintenant);
        if ($suivant === null) {
            $this->addFlash('info', 'Rien à démarrer : votre tableau est à jour.');
        } else {
            $this->tableau->demarrer($suivant, $this->moi(), $maintenant);
        }

        return $this->redirectToRoute('app_conception_tableau');
    }

    #[Route('/{id}/{action}', name: 'agir', requirements: ['id' => '\d+', 'action' => 'demarrer|pause|bat|retour|valider|regler'], methods: ['POST'])]
    public function agir(Conception $conception, string $action, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->autoriser($conception);
        $moi = $this->moi();
        $maintenant = new \DateTimeImmutable();
        $etape = $conception->etape();

        switch ($action) {
            case 'demarrer':
                if (\in_array($etape, [Conception::A_FAIRE, Conception::EN_CONCEPTION, Conception::CORRECTIONS], true)) {
                    $this->tableau->demarrer($conception, $moi, $maintenant);
                }
                break;
            case 'pause':
                $this->tableau->pause($conception, $maintenant);
                break;
            case 'bat':
                if (\in_array($etape, [Conception::EN_CONCEPTION, Conception::CORRECTIONS, Conception::A_FAIRE], true)) {
                    $this->tableau->envoyerBat($conception, $moi, $maintenant);
                    $message = $this->tableau->message($conception);
                    $this->addFlash('success', \sprintf('BAT V%d envoyé. Le chronomètre est arrêté pendant l\'attente du client.', $conception->getVersions()));
                    if ($message !== null) {
                        $this->addFlash('bat_whatsapp', ['numero' => UtilisateursController::numeroWhatsApp($message['telephone']), 'message' => $message['texte']]);
                    }
                }
                break;
            case 'retour':
                if ($etape === Conception::BAT_ENVOYE) {
                    $this->tableau->retour($conception, (string) $request->request->get('raison'), (string) $request->request->get('texte'), $maintenant);
                    $this->addFlash('info', $request->request->get('raison') === Conception::RETOUR_ERREUR ? 'Erreur notée : les points du barème en tiennent compte.' : 'Retour du client noté : aucun point retiré.');
                }
                break;
            case 'valider':
                if ($etape === Conception::BAT_ENVOYE) {
                    $reste = $this->tableau->valider($conception, $moi, $maintenant);
                    $this->addFlash($reste === null ? 'success' : 'warning', $reste === null
                        ? \sprintf('BAT validé : %+d points.', (int) $conception->getPoints())
                        : 'BAT validé et points comptés. Validation prépresse à terminer : '.$reste);
                }
                break;
            case 'regler':
                $echeance = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) $request->request->get('echeance')) ?: null;
                $temps = (int) $request->request->get('temps_prevu');
                if ($conception->getValideLe() === null) {
                    $conception->regler((string) $request->request->get('difficulte'), $echeance, $temps > 0 ? $temps : null);
                    $this->em->flush();
                    $this->addFlash('success', 'Difficulté et échéance enregistrées.');
                }
                break;
        }

        return match ($request->request->get('retour')) {
            'voir' => $this->redirectToRoute('app_conception_voir', ['id' => $conception->getId()]),
            'poste' => $this->redirectToRoute('app_conception_poste', ['id' => $conception->getId()]),
            default => $this->redirectToRoute('app_conception_tableau'),
        };
    }

    /** « fond perdu 2 mm ✓ » quand le contrôle automatique du fichier l'a mesuré. */
    private static function fondsPerdus(CommandeDetailFichier $f): ?array
    {
        foreach ($f->getAnalyse() ?? [] as $controle) {
            if (($controle['libelle'] ?? null) === 'Fonds perdus') {
                return ['valeur' => (string) $controle['valeur'], 'ok' => ($controle['etat'] ?? null) === AnalyseFichierImpression::OK];
            }
        }

        return null;
    }

    private function autoriser(Conception $c): void
    {
        $graphiste = $c->getGraphiste();
        if ($graphiste !== null && $graphiste !== $this->getUser() && !$this->responsable()) {
            throw $this->createAccessDeniedException('Ce travail est suivi par un autre graphiste.');
        }
    }

    private function responsable(): bool
    {
        return $this->isGranted('ROLE_PRODUCTION_GERER') || $this->isGranted('ROLE_ADMIN');
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
