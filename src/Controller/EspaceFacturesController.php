<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use App\Service\DocumentsEntreprise;
use App\Service\EspaceClient\CoqueEspace;
use App\Service\EspaceClient\ElementAPayer;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FacturesClient;
use App\Service\Paiement\AvoirClient;
use App\Service\Paiement\OrangeMoney;
use App\Service\Paiement\ReglementsEnLigne;
use App\Service\Paiement\RepartitionPaiement;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Espace client › Factures et paiements (maquettes FP01 à FP05) : ce qui
 * est à payer, les factures, les paiements, l'avoir et les reçus ; le
 * détail d'une facture ; le paiement de plusieurs éléments en une fois par
 * Orange Money (avoir déduit) ; la page « Paiement reçu » ; le relevé de
 * compte et les reçus en PDF.
 *
 * Tout est réservé à qui paie (particulier, ou payeur d'une entreprise),
 * vérifié ici à chaque requête ; une facture, un paiement ou un règlement
 * d'un autre client donne « introuvable ». Les montants et les frais sont
 * toujours recalculés par le serveur.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceFacturesController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly FacturesClient $factures,
        private readonly AvoirClient $avoirs,
        private readonly Parametres $parametres,
        private readonly EntrepriseRepository $entreprises,
        private readonly EspaceClient $espace,
    ) {
    }

    /** Vue d'ensemble (maquettes FP01, FP05) : tuiles, à payer, onglets, moyens de paiement. */
    #[Route('/factures', name: 'factures', methods: ['GET'])]
    public function index(Request $request, OrangeMoney $orangeMoney): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $maintenant = new \DateTimeImmutable();

        // Lien d'une notification (rappel d'échéance) : la facture, si c'est bien une des siennes.
        if ($request->query->has('f')) {
            foreach ($this->factures->factures($client) as $f) {
                if ($f->getId() === $request->query->getInt('f')) {
                    return $this->redirectToRoute('app_espace_facture_detail', ['id' => (string) $f->getPublicId()]);
                }
            }
        }
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Paiement annulé sur la page Orange Money : rien n\'a été débité.');

            return $this->redirectToRoute('app_espace_factures');
        }

        $aPayer = $this->factures->aPayer($client, $maintenant);
        $toutes = $this->factures->factures($client);
        $paiements = $this->factures->paiements($client);
        $anneeCourante = (int) $maintenant->format('Y');
        $annees = array_unique([$anneeCourante, ...array_map(static fn (Factures $f): int => (int) $f->getDateFacture()?->format('Y'), $toutes), ...array_map(static fn (Paiements $p): int => (int) $p->getDate()?->format('Y'), $paiements)]);
        rsort($annees);
        $annees = array_values(array_filter($annees));
        $annee = \in_array($request->query->getInt('annee'), $annees, true) ? $request->query->getInt('annee') : $anneeCourante;
        $dansLAnnee = static fn (?\DateTimeInterface $d): bool => null !== $d && (int) $d->format('Y') === $annee;
        $facturesAnnee = array_values(array_filter($toutes, static fn (Factures $f): bool => $dansLAnnee($f->getDateFacture())));
        $paiementsAnnee = array_values(array_filter($paiements, static fn (Paiements $p): bool => $dansLAnnee($p->getDate())));
        $recus = array_values(array_filter($paiementsAnnee, static fn (Paiements $p): bool => $p->estValide()));
        $avoir = $this->avoirs->etat($client, $maintenant);
        $avoirs = array_values(array_filter($paiements, static fn (Paiements $p): bool => Paiements::MODE_SOLDE_CLIENT === $p->getMode()));
        $onglet = \in_array($request->query->getString('onglet'), ['factures', 'paiements', 'avoirs', 'recus'], true) ? $request->query->getString('onglet') : 'factures';
        $cles = array_map(static fn (ElementAPayer $e): string => $e->cle(), $aPayer['elements']);

        return $this->vue('espace/factures/index.html.twig', $compte, [
            'aPayer' => $aPayer,
            'payeAnnee' => FacturesClient::payeEn($paiements, $anneeCourante),
            'anneeCourante' => $anneeCourante,
            'prochaine' => FacturesClient::prochaineEcheance($aPayer['elements']),
            'avoir' => $avoir,
            'annee' => $annee,
            'annees' => $annees,
            'onglet' => $onglet,
            'facturesAnnee' => $facturesAnnee,
            'paiementsAnnee' => $paiementsAnnee,
            'recus' => $recus,
            'avoirs' => $avoirs,
            'clesAPayer' => $cles,
            'maintenant' => $maintenant,
            'moyens' => $this->moyens(),
            'omActif' => $orangeMoney->estConfigure(),
        ]);
    }

    /**
     * Détail d'une facture (maquette FP02) : lignes, totaux, acompte versé,
     * reste, QR de vérification, paiements reçus, rappels envoyés et le
     * prochain.
     */
    #[Route('/factures/{id}', name: 'facture_detail', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function detail(string $id, DocumentsEntreprise $documents): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $facture = $this->factures->facture($client, $id) ?? throw $this->createNotFoundException();
        $commande = $facture->getCommande() ?? throw $this->createNotFoundException();
        $maintenant = new \DateTimeImmutable();
        $entreprise = $documents->entreprise($facture->getEmetteur(), $facture->getEmetteurLabel());
        $paiements = array_values(array_filter($this->factures->paiements($client), static fn (Paiements $p): bool => $p->getCommande() === $commande));
        $element = null;
        foreach ($this->factures->aPayer($client, $maintenant)['elements'] as $e) {
            if ($e->commande === $commande) {
                $element = $e;
            }
        }
        $paye = $commande->getTotalPaye();
        // Le QR de vérification de la facture (/f/{code}), comme sur le PDF : seulement si la facture a déjà un code
        // au bon format (le site ne crée pas de code : il viendrait en conflit avec celui de la boutique).
        $code = self::codeSuivi($facture);
        $urlSuivi = null !== $code && $entreprise->isQrSuivi() ? $this->generateUrl('app_facture_suivi', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL) : null;

        return $this->vue('espace/factures/detail.html.twig', $compte, [
            'facture' => $facture,
            'commande' => $commande,
            'entreprise' => $entreprise,
            'logo' => $documents->images($entreprise)['logo'] ?? $documents->dataUri('images/logo-succes-imprim.png'),
            'qr' => null !== $urlSuivi ? DocumentsEntreprise::qr($urlSuivi) : null,
            'codeSuivi' => $code,
            'paiements' => $paiements,
            'paye' => $paye,
            'reste' => $facture->estProforma() ? 0 : max(0, $commande->getTotalTtc() - $paye),
            'enValidation' => FacturesClient::enValidation($commande),
            'part' => $commande->getTotalTtc() > 0 ? (int) min(100, floor(100 * $paye / $commande->getTotalTtc())) : 0,
            'element' => $element,
            'rappels' => $this->factures->rappels($commande, $facture, $maintenant),
            'livraison' => $this->espace->livraison($commande),
            'maintenant' => $maintenant,
        ]);
    }

    /**
     * Récapitulatif du paiement (maquette FP03) : les éléments choisis,
     * l'avoir, « Tout payer » ou « Autre montant », les frais Orange Money
     * avant de partir. En fenêtre sur la vue d'ensemble (fragment), ou en
     * page sans JavaScript et sur téléphone.
     */
    #[Route('/factures/payer', name: 'payer_selection', methods: ['GET'])]
    public function selection(Request $request, OrangeMoney $orangeMoney, ReglementsEnLigne $reglements): Response
    {
        $compte = $this->payeur();
        $maintenant = new \DateTimeImmutable();
        $elements = $this->choisis($compte, self::cles($request->query->all('e')), $maintenant);
        if ([] === $elements) {
            $this->addFlash('error', 'Rien à payer pour cette sélection : elle a peut-être déjà été payée.');

            return $this->redirectToRoute('app_espace_factures');
        }
        $avecAvoir = !$request->query->has('avoir') || $request->query->getBoolean('avoir');
        $partiel = 'partiel' === $request->query->getString('mode');
        $montant = $partiel ? self::montant($request->query->getString('montant')) : null;
        $erreur = null;
        $plan = $reglements->plan($compte, $elements, $avecAvoir, null, $maintenant);
        // L'avoir couvre tout : plus de montant à choisir.
        $partiel = $partiel && $plan['omMax'] > 0;
        if ($partiel && null !== $montant) {
            try {
                $plan = $reglements->plan($compte, $elements, $avecAvoir, $montant, $maintenant);
            } catch (\DomainException $e) {
                $erreur = $e->getMessage();
            }
        }

        // fragment=1 : le contenu de la fenêtre de la vue d'ensemble ; fragment=page : celui de la page, recalculé sans la recharger.
        $fragment = $request->query->getString('fragment');

        return $this->vue('' !== $fragment ? 'espace/factures/_paiement.html.twig' : 'espace/factures/paiement.html.twig', $compte, [
            'pleinePage' => '1' !== $fragment,
            'elements' => $elements,
            'plan' => $plan,
            'avecAvoir' => $avecAvoir && $plan['avoirDisponible'] > 0,
            'partiel' => $partiel,
            'montantSaisi' => $montant,
            'erreur' => $erreur,
            'minimum' => min(RepartitionPaiement::MINIMUM_PARTIEL, $plan['omMax']),
            'parametres' => $orangeMoney->parametres(),
            'omActif' => $orangeMoney->estConfigure(),
            'marchand' => $this->espace->marchandOrangeMoney(),
        ]);
    }

    /**
     * « Continuer vers Orange Money » (ou « Utiliser mon avoir ») : tout est
     * recalculé ici ; si le total a changé depuis le récapitulatif, le
     * client le revoit. Un élément qui n'est pas à lui : introuvable.
     */
    #[Route('/factures/payer', name: 'payer_reglement', methods: ['POST'])]
    public function payer(
        Request $request,
        ReglementsEnLigne $reglements,
        #[Autowire(service: 'limiter.espace_paiement_compte')] RateLimiterFactoryInterface $limite,
    ): Response {
        $this->jeton($request);
        $compte = $this->payeur();
        $maintenant = new \DateTimeImmutable();
        $cles = self::cles($request->request->all('e'));
        $elements = $this->choisis($compte, $cles, $maintenant);
        $avecAvoir = $request->request->getBoolean('avoir');
        $partiel = 'partiel' === $request->request->getString('mode');
        $montant = $partiel ? self::montant($request->request->getString('montant')) : null;
        $retour = ['e' => $cles, 'avoir' => $avecAvoir ? 1 : 0] + ($partiel ? ['mode' => 'partiel', 'montant' => $montant] : []);
        if ([] === $elements) {
            $this->addFlash('error', 'Rien à payer pour cette sélection : elle a peut-être déjà été payée.');

            return $this->redirectToRoute('app_espace_factures');
        }
        if ($partiel && null === $montant) {
            $this->addFlash('error', 'Indiquez le montant à payer.');

            return $this->redirectToRoute('app_espace_payer_selection', $retour);
        }
        try {
            $plan = $reglements->plan($compte, $elements, $avecAvoir, $montant, $maintenant);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_payer_selection', $retour);
        }
        // Le total montré n'est plus celui d'aujourd'hui (paiement reçu, avoir utilisé, taux changés) : le client le revoit.
        if ((string) $plan['frais']->total() !== $request->request->getString('total') || (string) $plan['avoir'] !== $request->request->getString('avoir_montant')) {
            $this->addFlash('error', 'Le montant a changé : vérifiez le récapitulatif avant de payer.');

            return $this->redirectToRoute('app_espace_payer_selection', $retour);
        }
        if (!$limite->create('compte-'.$compte->getId())->consume()->isAccepted()) {
            $this->addFlash('error', 'Vous avez lancé beaucoup de paiements. Réessayez dans quelques minutes, ou payez en boutique.');

            return $this->redirectToRoute('app_espace_factures');
        }
        try {
            $resultat = $reglements->demarrer($compte, $elements, $plan, $maintenant);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_payer_selection', $retour);
        }

        return null !== $resultat['lien']
            ? $this->redirect($resultat['lien'])
            : $this->redirectToRoute('app_espace_payer_recu', ['reference' => $resultat['reglement']->getReference()]);
    }

    /**
     * Paiement reçu (maquettes FP04, FP05) : retour de la page Orange Money
     * (l'état est relu chez Orange sans attendre sa notification), puis
     * l'état de chaque élément tel que la boutique le valide.
     */
    #[Route('/factures/paiement/{reference}', name: 'payer_recu', requirements: ['reference' => '[A-Z0-9]{8,40}'], methods: ['GET'])]
    public function recu(string $reference, OrangeMoney $orangeMoney, EntityManagerInterface $em, CoqueEspace $coque): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $reglement = $this->factures->reglement($client, $reference) ?? throw $this->createNotFoundException();
        $maintenant = new \DateTimeImmutable();
        $transaction = $em->getRepository(TransactionOrangeMoney::class)->findOneBy(['reglement' => $reglement]);
        if (null !== $transaction && $reglement->estEnCours()) {
            $orangeMoney->verifier($transaction, $maintenant);
            $em->refresh($reglement);
        }
        $aPayer = $this->factures->aPayer($client, $maintenant);
        $paiements = array_values(array_filter($this->factures->paiements($client), static fn (Paiements $p): bool => $p->estValide()));

        return $this->vue('espace/factures/recu_paiement.html.twig', $compte, [
            'reglement' => $reglement,
            'transaction' => $transaction,
            'ouverte' => null !== $transaction && $transaction->estOuverte($maintenant) ? $transaction->getLienPaiement() : null,
            'etMaintenant' => $reglement->estRecu() ? $this->factures->etMaintenant($reglement, $aPayer['elements'], $coque->droits($compte)['fidelite'], $maintenant) : [],
            'aPayer' => $aPayer,
            'derniersRecus' => \array_slice($paiements, 0, 5),
            // Partage sur WhatsApp : jamais de montant dans le texte.
            'partage' => 'https://wa.me/?text='.rawurlencode(\sprintf('Paiement Succès Imprim envoyé par Orange Money (référence %s). Le reçu arrive dans mon espace client une fois validé par la boutique.', $reglement->getReference())),
        ]);
    }

    /** Reçu d'un paiement validé par la boutique, en PDF (numéro de la caisse). */
    #[Route('/factures/recu/{id}', name: 'facture_recu', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function recuPdf(string $id, DocumentsEntreprise $documents): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $paiement = $this->factures->paiement($client, $id) ?? throw $this->createNotFoundException();
        if (!$paiement->estValide()) {
            // Pas de reçu tant que la boutique n'a pas validé : il n'aurait pas de numéro.
            throw $this->createNotFoundException();
        }
        $commande = $paiement->getCommande();
        // L'en-tête de la facture payée, sinon celui de l'entreprise qui facture le client.
        $facture = $paiement->getFacture();
        $entreprise = null !== $facture
            ? $documents->entreprise($facture->getEmetteur(), $facture->getEmetteurLabel())
            : ($this->entrepriseDuClient($client) ?? $documents->entreprise('mdg_success', 'Succès Imprim'));

        return $this->pdf('espace/factures/recu_pdf.html.twig', [
            'paiement' => $paiement,
            'commande' => $commande,
            'client' => $client,
            'entreprise' => $entreprise,
            'logo' => $documents->images($entreprise)['logo'] ?? $documents->dataUri('images/logo-succes-imprim.png'),
            'moyen' => FacturesClient::moyen($paiement),
            'reste' => $commande?->getResteAPayer() ?? 0,
        ], \sprintf('recu-%s.pdf', preg_replace('/[^A-Za-z0-9-]/', '', $paiement->getNumeroRecu() ?? 'paiement')));
    }

    /**
     * Relevé de compte en PDF, entre deux dates (par défaut, depuis le
     * 1er janvier) : en-tête de l'entreprise qui facture le client.
     */
    #[Route('/factures/releve', name: 'facture_releve', methods: ['GET'])]
    public function releve(Request $request, DocumentsEntreprise $documents): Response
    {
        $compte = $this->payeur();
        $client = $compte->getClient();
        $aujourdhui = new \DateTimeImmutable('today');
        $du = self::date($request->query->getString('du')) ?? $aujourdhui->setDate((int) $aujourdhui->format('Y'), 1, 1);
        $au = self::date($request->query->getString('au')) ?? $aujourdhui;
        if ($au < $du) {
            [$du, $au] = [$au, $du];
        }
        if ($au > $aujourdhui) {
            $au = $aujourdhui;
        }
        $entreprise = $this->entrepriseDuClient($client) ?? $documents->entreprise('mdg_success', 'Succès Imprim');

        return $this->pdf('espace/factures/releve_pdf.html.twig', [
            'releve' => $this->factures->releve($client, $du, $au),
            'du' => $du,
            'au' => $au,
            'client' => $client,
            'entreprise' => $entreprise,
            'logo' => $documents->images($entreprise)['logo'] ?? $documents->dataUri('images/logo-succes-imprim.png'),
            'edite' => new \DateTimeImmutable(),
        ], \sprintf('releve-%s-%s.pdf', $du->format('Ymd'), $au->format('Ymd')));
    }

    /**
     * Les éléments choisis, tels que le serveur les calcule. Une clé qui
     * n'est pas un des éléments à payer du client : introuvable (pas un
     * simple refus, pour ne rien dire des commandes des autres).
     *
     * @param list<string> $cles
     *
     * @return list<ElementAPayer>
     */
    private function choisis(CompteClient $compte, array $cles, \DateTimeImmutable $maintenant): array
    {
        $elements = $this->factures->aPayer($compte->getClient(), $maintenant)['elements'];
        foreach (self::horsDeLaListe($cles, $elements) as $cle) {
            // Une de ses commandes déjà réglée entre-temps est simplement retirée ; une autre n'existe pas pour lui.
            if (null === $this->espace->commande($compte->getClient(), substr($cle, 2), true)) {
                throw $this->createNotFoundException();
            }
        }

        // Toujours dans l'ordre des échéances : l'avoir et un paiement partiel vont à la plus ancienne.
        return array_values(array_filter($elements, static fn (ElementAPayer $e): bool => \in_array($e->cle(), $cles, true)));
    }

    /**
     * Les clés choisies qui ne sont pas à payer en ce moment.
     *
     * @param list<string>        $cles
     * @param list<ElementAPayer> $elements
     *
     * @return list<string>
     */
    public static function horsDeLaListe(array $cles, array $elements): array
    {
        $aPayer = array_map(static fn (ElementAPayer $e): string => $e->cle(), $elements);

        return array_values(array_filter($cles, static fn (string $c): bool => !\in_array($c, $aPayer, true)));
    }

    /**
     * Clés « c-<uuid> » lisibles, sans doublon, 50 au plus ; le reste est ignoré.
     *
     * @return list<string>
     */
    public static function cles(mixed $brut): array
    {
        $cles = [];
        foreach (\is_array($brut) ? $brut : [] as $c) {
            if (\is_string($c) && 1 === preg_match('/^c-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $c)) {
                $cles[$c] = $c;
            }
        }

        return \array_slice(array_values($cles), 0, 50);
    }

    /** « 25 000 », « 25000 F » → 25000 ; rien de lisible → null. */
    public static function montant(string $saisie): ?int
    {
        $chiffres = preg_replace('/[^0-9]/', '', $saisie) ?? '';

        return '' === $chiffres || \strlen($chiffres) > 10 ? null : (int) $chiffres;
    }

    private static function date(string $saisie): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        return false !== $d && $d->format('Y-m-d') === $saisie && $d->format('Y') >= 2000 ? $d : null;
    }

    /**
     * Moyens de paiement (panneau de la vue d'ensemble), d'après les
     * réglages de la boutique.
     *
     * @return array{orangeMoney: bool, especes: bool, virement: bool, seuil: int, coordonnees: string, marchand: ?string}
     */
    private function moyens(): array
    {
        $v = fn (string $cle): mixed => $this->parametres->valeur('paiements', $cle);

        return [
            'orangeMoney' => (bool) ($v('orange_money') ?? true),
            'especes' => (bool) ($v('especes') ?? true),
            'virement' => (bool) ($v('virement') ?? true),
            'seuil' => (int) ($v('virement_seuil') ?? 500000),
            'coordonnees' => trim((string) ($v('virement_coordonnees') ?? '')),
            'marchand' => $this->espace->marchandOrangeMoney(),
        ];
    }

    private function entrepriseDuClient(Clients $client): ?Entreprise
    {
        return $this->entreprises->parCode((string) $client->getEntiteFacturation()) ?? ($this->entreprises->dansLOrdre()[0] ?? null);
    }

    /** @param array<string, mixed> $parametres */
    private function pdf(string $gabarit, array $parametres, string $nom): Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        // Images en data: seulement ; rien n'est chargé d'ailleurs.
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderView($gabarit, $parametres), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response((string) $dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="%s"', $nom),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, CompteClient $compte, array $parametres): Response
    {
        return $this->render($gabarit, $parametres + [
            'moi' => $compte->getClient(),
            'peutPayer' => true,
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
        ]);
    }

    /** Le code de vérification de la facture, s'il a le format de /f/{code} (sinon pas de QR). */
    public static function codeSuivi(Factures $facture): ?string
    {
        $code = $facture->getCodeSuivi();

        return null !== $code && 1 === preg_match('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{2}$/D', $code) ? $code : null;
    }

    /**
     * Qui ouvre « Factures et paiements » : un particulier, le titulaire ou
     * le payeur d'une entreprise. Pas le valideur (il voit les prix des
     * commandes et des devis, pas le solde ni les factures), ni le
     * demandeur, ni la lecture seule.
     */
    public static function peutVoirLesFactures(CompteClient $compte): bool
    {
        return null !== $compte->getClient() && $compte->peut(CompteClient::DROIT_PAYEUR);
    }

    /** Factures, avoir et paiement : réservés à qui paie (particulier, ou payeur de l'entreprise). */
    private function payeur(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }
        if (!self::peutVoirLesFactures($compte)) {
            throw $this->createAccessDeniedException('Les factures sont réservées aux personnes qui paient pour l\'entreprise.');
        }

        return $compte;
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }
}
