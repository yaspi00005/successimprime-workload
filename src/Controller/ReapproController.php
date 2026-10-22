<?php

namespace App\Controller;

use App\Entity\Fournisseurs;
use App\Entity\User;
use App\Service\Achats\AlertesAchats;
use App\Service\Achats\CalculsAlertes;
use App\Service\Achats\CalculsReception;
use App\Service\Http\Entree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Achats › Réapprovisionnement (maquettes SU06 et SU07, docs/achats.md) :
 * les alertes en cours, le tableau des articles (stock, consommation,
 * jours restants, délai et marge, intervalle habituel, date de commande),
 * la courbe du stock d'un article, les règles d'alerte, et la commande
 * proposée par fournisseur, enregistrée en brouillon d'un geste.
 *
 * Réservé à la direction comme les achats (access_control, /achats).
 */
#[Route('/achats/reapprovisionnement')]
final class ReapproController extends AbstractController
{
    public function __construct(
        private readonly AlertesAchats $alertes,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    #[Route('', name: 'app_reappro_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $analyse = $this->alertes->analyser($aujourdhui);

        // La courbe : l'article choisi (?article=), sinon le plus pressé.
        $choisi = Entree::entier($request->query, 'article');
        $ligne = null;
        foreach ($analyse['lignes'] as $l) {
            if ((int) $l['article']->getId() === $choisi) {
                $ligne = $l;
            }
        }
        $ligne ??= $analyse['lignes'][0] ?? null;

        $moi = $this->getUser();
        $destinataires = $this->alertes->destinataires();
        $alertes = [];
        foreach ($analyse['alertes'] as $a) {
            $alertes[] = $a + ['whatsapp' => $this->liensWhatsApp($a, $destinataires, $moi)] + AlertesAchats::action($a);
        }

        return $this->render('achats/reappro.html.twig', [
            'lignes' => $analyse['lignes'],
            'alertes' => $alertes,
            'propositions' => $analyse['propositions'],
            'sans_conso' => $analyse['sansConso'],
            'ligne' => $ligne,
            'courbe' => $ligne !== null ? $this->alertes->courbe($ligne, $aujourdhui) : null,
            'regles' => $this->alertes->regles(),
            'destinataires' => $destinataires,
            'aujourdhui' => $aujourdhui,
            'en_ligne' => $this->enLigne,
        ]);
    }

    /**
     * Commande proposée à un fournisseur (maquette SU07) : les articles en
     * alerte et ceux à regrouper, aux quantités suggérées.
     */
    #[Route('/fournisseur/{fournisseur}', name: 'app_reappro_proposition', requirements: ['fournisseur' => '\d+'], methods: ['GET'])]
    public function proposition(Fournisseurs $fournisseur): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $proposition = $this->alertes->proposition($fournisseur, $aujourdhui);

        if ($proposition === null) {
            $this->addFlash('info', sprintf('Rien à commander chez %s pour le moment.', $fournisseur->getNomCourt()));

            return $this->redirectToRoute('app_reappro_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('achats/proposition.html.twig', [
            'fournisseur' => $fournisseur,
            'proposition' => $proposition,
            'aujourdhui' => $aujourdhui,
        ]);
    }

    /**
     * La commande proposée devient un brouillon de commande d'achat, à
     * vérifier puis envoyer depuis son écran (seuil de validation compris).
     */
    #[Route('/fournisseur/{fournisseur}/brouillon', name: 'app_reappro_brouillon', requirements: ['fournisseur' => '\d+'], methods: ['POST'])]
    public function brouillon(Request $request, Fournisseurs $fournisseur): Response
    {
        $this->verifierJeton($request, 'reappro_brouillon'.$fournisseur->getId());
        $utilisateur = $this->getUser();
        $achat = $this->alertes->creerBrouillon($fournisseur, new \DateTimeImmutable('today'), $utilisateur instanceof User ? $utilisateur : null);

        if ($achat === null) {
            $this->addFlash('info', sprintf('Rien à commander chez %s pour le moment.', $fournisseur->getNomCourt()));

            return $this->redirectToRoute('app_reappro_index', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', sprintf('Brouillon %s préparé pour %s : vérifiez les quantités, puis envoyez.', $achat->getNumero(), $fournisseur->getNomCourt()));

        return $this->redirectToRoute('app_achat_edit', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Allume ou éteint une règle d'alerte (tuile « Achats et fournisseurs ») ;
     * depuis le site en ligne, le changement est proposé à l'ERP.
     */
    #[Route('/regle/{regle}', name: 'app_reappro_regle', requirements: ['regle' => 'stock_bas|prix|retard|oubli'], methods: ['POST'])]
    public function regle(Request $request, string $regle): Response
    {
        $this->verifierJeton($request, 'reappro_regle'.$regle);
        $utilisateur = $this->getUser();
        $actif = $request->request->getBoolean('actif');
        $resultat = $this->alertes->basculer($regle, $actif, $utilisateur instanceof User ? $utilisateur : null);
        $libelle = AlertesAchats::LIBELLES[$regle];

        $this->addFlash('success', match ($resultat) {
            'propose' => sprintf('« %s » : changement proposé, il sera appliqué à l\'ERP de l\'imprimerie.', $libelle),
            'inchange' => sprintf('« %s » est déjà %s.', $libelle, $actif ? 'allumée' : 'éteinte'),
            default => sprintf('Alerte « %s » %s.', $libelle, $actif ? 'allumée' : 'éteinte'),
        });

        return $this->redirectToRoute('app_reappro_index', ['_fragment' => 'regles'], Response::HTTP_SEE_OTHER);
    }

    /**
     * Envoie tout de suite les notifications des alertes (comme
     * app:achats:alertes). Sur l'ERP de l'imprimerie seulement.
     */
    #[Route('/prevenir', name: 'app_reappro_prevenir', methods: ['POST'])]
    public function prevenir(Request $request): Response
    {
        $this->verifierJeton($request, 'reappro_prevenir');

        if ($this->enLigne) {
            $this->addFlash('info', 'Les alertes partent de l\'ERP de l\'imprimerie, chaque heure.');

            return $this->redirectToRoute('app_reappro_index', [], Response::HTTP_SEE_OTHER);
        }

        $bilan = $this->alertes->notifier(new \DateTimeImmutable());
        $creees = array_sum(array_column($bilan, 'creees'));
        $resolues = array_sum(array_column($bilan, 'resolues'));
        $this->addFlash('success', sprintf('Notifications à jour : %d nouvelle%s, %d réglée%s.', $creees, $creees > 1 ? 's' : '', $resolues, $resolues > 1 ? 's' : ''));

        return $this->redirectToRoute('app_reappro_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Liens wa.me préparés : un par personne prévenue (sauf soi), et la
     * relance du fournisseur en retard. Pas d'API Meta : WhatsApp s'ouvre
     * avec le message, on l'envoie soi-même.
     *
     * @param array<string, mixed>       $alerte
     * @param list<array<string, mixed>> $destinataires
     *
     * @return list<array{libelle: string, url: string}>
     */
    private function liensWhatsApp(array $alerte, array $destinataires, mixed $moi): array
    {
        $action = AlertesAchats::action($alerte);
        $lien = $this->generateUrl($action['route'], $action['parametres'], UrlGeneratorInterface::ABSOLUTE_URL);
        $liens = [];

        foreach ($destinataires as $d) {
            if ($d['whatsapp'] === null || $d['utilisateur'] === $moi) {
                continue;
            }
            $message = CalculsAlertes::messageCollegue($d['prenom'], $alerte['titre'], $alerte['detailCourt'] ?? $alerte['detail'], $lien);
            $liens[] = ['libelle' => 'WhatsApp à '.($d['prenom'] ?: $d['nom']), 'url' => (string) CalculsReception::lienWhatsApp($d['whatsapp'], $message)];
        }

        if (($alerte['relance'] ?? null) !== null) {
            $liens[] = ['libelle' => 'Relancer '.$alerte['fournisseur']->getNomCourt(), 'url' => $alerte['relance']];
        }

        // Sans numéro connu : WhatsApp s'ouvre et on choisit à qui l'envoyer.
        if ($liens === []) {
            $liens[] = ['libelle' => 'Partager sur WhatsApp', 'url' => 'https://wa.me/?text='.rawurlencode(CalculsAlertes::messageCollegue(null, $alerte['titre'], $alerte['detailCourt'] ?? $alerte['detail'], $lien))];
        }

        return $liens;
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
