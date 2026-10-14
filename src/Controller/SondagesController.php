<?php

namespace App\Controller;

use App\Entity\InvitationSondage;
use App\Entity\ReponseSondage;
use App\Entity\Sondage;
use App\Entity\User;
use App\Service\DocumentsEntreprise;
use App\Service\SegmentsClients;
use App\Service\Sondages\AnalyseSondages;
use App\Service\Sondages\Indicateurs;
use App\Service\Sondages\InvitationsSondage;
use App\Service\Sondages\Questions;
use App\Service\Sondages\SondageCommande;
use App\Service\Sondages\Sondages;
use App\Service\Sondages\Themes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Sondages et avis (docs/sondages.md) : satisfaction moyenne,
 * clients satisfaits, NPS et taux de réponse comparés au mois précédent
 * et à N−1 ; résultats des sondages de commande (par produit, machine,
 * agent, mois) ; sondages globaux et libres ; propositions classées par
 * thème ; mauvaises notes à rappeler.
 *
 * Rôle Clients pour consulter et suivre ; créer, lancer ou clore un
 * sondage et voir la note par agent : direction (ROLE_ADMIN).
 */
#[Route('/clients/sondages', name: 'app_sondages_')]
#[IsGranted('ROLE_CLIENT')]
final class SondagesController extends AbstractController
{
    private const CSRF = 'sondages';

    /** Propositions montrées à la fois : les filtres par thème et statut mènent aux autres. */
    private const PROPOSITIONS_MONTREES = 60;

    public function __construct(
        private readonly Sondages $sondages,
        private readonly AnalyseSondages $analyse,
        private readonly InvitationsSondage $invitations,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $periode = self::periode($request);
        $type = $request->query->getString('type');
        $type = isset(ReponseSondage::TYPES[$type]) ? $type : null;
        $depuisToujours = new \DateTimeImmutable('2000-01-01');
        $listeSondages = array_map(fn (Sondage $s): array => [
            'sondage' => $s,
            'resultats' => $this->analyse->indicateurs(null, $s, $depuisToujours, $maintenant->modify('+1 day')),
        ], $this->sondages->liste());

        return $this->render('sondages/index.html.twig', [
            'periode' => $periode,
            'type' => $type,
            'tableau' => $this->analyse->tableau($periode, $type, null, $maintenant),
            'parMois' => $this->analyse->parMois($type, null, $maintenant),
            'alertes' => $this->analyse->alertes(true, 4),
            'nombreAlertes' => $this->analyse->nombreAlertes(),
            'propositions' => $this->analyse->propositions(null, null, 5)['liste'],
            'sondages' => $listeSondages,
            'commandes' => $this->analyse->indicateurs(ReponseSondage::COMMANDE, null, $maintenant->modify('-12 months'), $maintenant->modify('+1 day')),
        ]);
    }

    #[Route('/commandes', name: 'commandes', methods: ['GET'])]
    public function commandes(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $periode = self::periode($request);
        $tableau = $this->analyse->tableau($periode, ReponseSondage::COMMANDE, null, $maintenant);
        [$debut, $fin] = [$tableau['bornes']['debut'], $tableau['bornes']['fin']];
        $note = \in_array($request->query->getString('note'), ['basses', 'hautes'], true) ? $request->query->getString('note') : null;
        $enAttente = array_map(fn (InvitationSondage $i): array => [
            'invitation' => $i,
            'whatsapp' => null !== $this->invitations->whatsapp($i),
        ], $this->analyse->invitationsEnAttente($maintenant, 12));

        return $this->render('sondages/commandes.html.twig', [
            'periode' => $periode,
            'note' => $note,
            'tableau' => $tableau,
            'parMois' => $this->analyse->parMois(ReponseSondage::COMMANDE, null, $maintenant),
            'parProduit' => $this->analyse->parProduit($debut, $fin),
            'parMachine' => $this->analyse->parMachine($debut, $fin),
            'parAgent' => $this->isGranted('ROLE_ADMIN') ? $this->analyse->parAgent($debut, $fin) : null,
            'reponses' => $this->analyse->reponses(ReponseSondage::COMMANDE, null, $debut, $fin, $note, 50),
            'enAttente' => $enAttente,
            'questions' => SondageCommande::questions(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouveau(Request $request): Response
    {
        return $this->formulaire($request, null);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifier(Sondage $sondage, Request $request): Response
    {
        return $this->formulaire($request, $sondage);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Sondage $sondage): Response
    {
        $maintenant = new \DateTimeImmutable();
        $reponses = $this->analyse->reponses(null, $sondage, null, null, null, 500);
        $brutes = [];
        foreach ($reponses as $r) {
            $brutes[(int) $r->getId()] = $r->getReponses();
        }
        $lien = $sondage->estLibre() ? $this->sondages->lienPublic('app_avis_sondage', ['jeton' => $sondage->getJeton()]) : null;

        return $this->render('sondages/voir.html.twig', [
            'sondage' => $sondage,
            'resultats' => $this->analyse->indicateurs(null, $sondage, new \DateTimeImmutable('2000-01-01'), $maintenant->modify('+1 day')),
            'questions' => Indicateurs::parQuestion($sondage->getQuestions(), $brutes),
            'reponses' => \array_slice($reponses, 0, 30),
            'lien' => $lien,
            'qr' => null !== $lien ? DocumentsEntreprise::qr($lien.'?via=qr') : null,
            'jeton' => self::CSRF,
        ]);
    }

    /** Affiche à imprimer pour la boutique : titre et QR code du sondage libre. */
    #[Route('/{id}/affiche', name: 'affiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function affiche(Sondage $sondage): Response
    {
        if (!$sondage->estLibre()) {
            throw $this->createNotFoundException();
        }
        $lien = $this->sondages->lienPublic('app_avis_sondage', ['jeton' => $sondage->getJeton()]);

        return $this->render('sondages/affiche.html.twig', [
            'sondage' => $sondage,
            'lien' => $lien,
            'qr' => DocumentsEntreprise::qr($lien.'?via=qr'),
        ]);
    }

    #[Route('/{id}/ouvrir', name: 'ouvrir', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function ouvrir(Sondage $sondage, Request $request, SegmentsClients $segments): Response
    {
        $this->jeton($request);
        try {
            $this->sondages->ouvrir($sondage, $segments, new \DateTimeImmutable());
            $this->addFlash('success', $sondage->estLibre()
                ? 'Sondage lancé : partagez le lien ou imprimez le QR code.'
                : \sprintf('Sondage lancé : il est proposé dans l\'espace client (%s, %d clients).', mb_strtolower($sondage->getLibelleSegment()), $sondage->getDestinataires() ?? 0));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sondages_voir', ['id' => $sondage->getId()]);
    }

    #[Route('/{id}/clore', name: 'clore', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clore(Sondage $sondage, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->sondages->clore($sondage, new \DateTimeImmutable());
            $this->addFlash('success', 'Sondage clos : il n\'accepte plus de réponses.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sondages_voir', ['id' => $sondage->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimer(Sondage $sondage, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->sondages->supprimer($sondage);
            $this->addFlash('success', 'Sondage supprimé.');

            return $this->redirectToRoute('app_sondages_index');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_sondages_voir', ['id' => $sondage->getId()]);
        }
    }

    #[Route('/propositions', name: 'propositions', methods: ['GET'])]
    public function propositions(Request $request): Response
    {
        $theme = $request->query->getString('theme');
        $statut = $request->query->getString('statut');
        $theme = isset(Themes::THEMES[$theme]) ? $theme : null;
        $statut = isset(Themes::STATUTS[$statut]) ? $statut : null;

        return $this->render('sondages/propositions.html.twig', $this->analyse->propositions($theme, $statut, self::PROPOSITIONS_MONTREES) + [
            'theme' => $theme,
            'statut' => $statut,
            'jeton' => self::CSRF,
            'montrees' => self::PROPOSITIONS_MONTREES,
        ]);
    }

    #[Route('/propositions/{id}', name: 'classer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function classer(ReponseSondage $reponse, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->sondages->classer($reponse, $request->request->getString('theme'), $request->request->getString('statut'));
            $this->addFlash('success', \sprintf('Proposition classée : %s, %s.', mb_strtolower($reponse->getLibelleTheme()), mb_strtolower($reponse->getLibelleStatutProposition())));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $retour = $request->request->getString('retour');

        return str_starts_with($retour, '/clients/sondages') ? $this->redirect($retour) : $this->redirectToRoute('app_sondages_propositions');
    }

    #[Route('/alertes', name: 'alertes', methods: ['GET'])]
    public function alertes(): Response
    {
        return $this->render('sondages/alertes.html.twig', [
            'ouvertes' => $this->analyse->alertes(true),
            'traitees' => $this->analyse->alertes(false, 30),
        ]);
    }

    #[Route('/reponse/{id}', name: 'reponse', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function reponse(ReponseSondage $reponse): Response
    {
        return $this->render('sondages/reponse.html.twig', [
            'r' => $reponse,
            'questions' => $reponse->getSondage()?->getQuestions() ?? SondageCommande::questions(),
            'suites' => array_diff_key(ReponseSondage::ALERTES, [ReponseSondage::ALERTE_A_RAPPELER => true, ReponseSondage::ALERTE_RECLAMATION => true]),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/reponse/{id}/alerte', name: 'alerte', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function alerte(ReponseSondage $reponse, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->sondages->traiterAlerte($reponse, $request->request->getString('suite'), $request->request->getString('note'), $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', 'Suite notée.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sondages_reponse', ['id' => $reponse->getId()]);
    }

    #[Route('/reponse/{id}/reclamation', name: 'reclamation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reclamation(ReponseSondage $reponse, Request $request): Response
    {
        $this->jeton($request);
        try {
            $reclamation = $this->sondages->ouvrirReclamation($reponse, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Réclamation %s ouverte : le client la voit dans son espace, répondez-lui ici.', $reclamation->getNumero()));

            return $this->redirectToRoute('app_reclamations_clients_voir', ['id' => $reclamation->getId()]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_sondages_reponse', ['id' => $reponse->getId()]);
        }
    }

    /** Ouvre WhatsApp avec le lien du sondage, et note l'envoi. */
    #[Route('/invitation/{id}/whatsapp', name: 'whatsapp', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function whatsapp(InvitationSondage $invitation, Request $request): Response
    {
        $this->jeton($request);
        $lien = $this->invitations->whatsapp($invitation);
        if (null === $lien) {
            $this->addFlash('error', 'Pas de numéro WhatsApp pour ce client.');

            return $this->redirectToRoute('app_sondages_commandes');
        }
        $this->invitations->noterWhatsapp($invitation, new \DateTimeImmutable());

        return new RedirectResponse($lien);
    }

    private function formulaire(Request $request, ?Sondage $sondage): Response
    {
        $type = $sondage?->getType() ?? (Sondage::LIBRE === $request->query->get('type') ? Sondage::LIBRE : Sondage::GLOBAL);
        $saisie = [
            'type' => $type,
            'titre' => $sondage?->getTitre() ?? '',
            'introduction' => $sondage?->getIntroduction() ?? '',
            'segment' => $sondage?->getSegment() ?? 'tous',
            'anonyme' => $sondage?->isAnonymeAutorise() ?? true,
            'questions' => $sondage?->getQuestions() ?? Questions::modeleParDefaut(),
        ];
        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $saisie = [
                'type' => $type,
                'titre' => $request->request->getString('titre'),
                'introduction' => $request->request->getString('introduction'),
                'segment' => $request->request->getString('segment'),
                'anonyme' => $request->request->getBoolean('anonyme'),
                'questions' => array_values(array_filter($request->request->all('questions'), 'is_array')),
            ];
            try {
                $sondage = $this->sondages->enregistrerSondage($sondage, $saisie, $this->moi());
                $this->addFlash('success', 'Sondage enregistré. Vérifiez-le, puis lancez-le.');

                return $this->redirectToRoute('app_sondages_voir', ['id' => $sondage->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('sondages/formulaire.html.twig', [
            'sondage' => $sondage,
            'saisie' => $saisie,
            'verrouille' => null !== $sondage && $this->sondages->nombreReponses($sondage) > 0,
            'jeton' => self::CSRF,
        ]);
    }

    private static function periode(Request $request): string
    {
        $periode = $request->query->getString('periode');

        return isset(AnalyseSondages::PERIODES[$periode]) ? $periode : 'mois';
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function moi(): string
    {
        $moi = $this->getUser();

        return $moi instanceof User ? (string) ($moi->getEmploye()?->getPrenom() ?? $moi->getUsername()) : 'Atelier';
    }
}
