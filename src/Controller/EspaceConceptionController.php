<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use App\Repository\CompteClientRepository;
use App\Service\Conception\CommandesConception;
use App\Service\Conception\CreditsIa;
use App\Service\Conception\DemandesConception;
use App\Service\Conception\DroitsConception;
use App\Service\Conception\ImagesIa;
use App\Service\Conception\PromptConception;
use App\Service\Conception\ReglagesConception;
use App\Service\Conception\TarifConception;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FichiersClient;
use App\Service\Paiement\OrangeMoney;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demande de conception dans l'espace client (maquettes DC01 à DC05,
 * docs/demande-conception.md) : support, informations, graphiste ou IA,
 * paiement. Tout se limite aux demandes du client connecté, et chaque
 * envoi de formulaire revérifie les droits du compte (DroitsConception).
 */
#[Route('/espace/conception')]
final class EspaceConceptionController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly DemandesConception $demandes,
        private readonly ReglagesConception $reglages,
        private readonly CreditsIa $credits,
        private readonly OrangeMoney $orangeMoney,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Étape 1 (maquette DC01) : le support à concevoir. */
    #[Route('', name: 'app_espace_conception', methods: ['GET'])]
    public function supports(Request $request): Response
    {
        $compte = $this->compte();
        if (DroitsConception::lectureSeule($compte)) {
            // Arrivée par le menu : la liste suffit, sans message d'erreur.
            return $this->redirectToRoute('app_espace_conception_demandes');
        }
        $demande = '' !== $request->query->getString('demande') ? $this->demandes->trouver($compte->getClient(), $request->query->getString('demande')) : null;

        return $this->vue('espace/conception/supports.html.twig', [
            'supports' => $this->demandes->supports(),
            'demande' => null !== $demande && $demande->estOuverte() ? $demande : null,
            'enCours' => $this->demandes->enCours($compte->getClient()),
            'etapes' => DemandesConception::etapes(1),
            'iaDisponible' => $this->reglages->iaDisponible(),
            'prixImage' => $this->reglages->prixImage(),
        ]);
    }

    #[Route('/commencer', name: 'app_espace_conception_commencer', methods: ['POST'])]
    public function commencer(Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->preparateur();
        $support = $this->demandes->support($request->request->getInt('support')) ?? throw $this->createNotFoundException();
        $demande = '' !== $request->request->getString('demande') ? $this->demandes->trouver($compte->getClient(), $request->request->getString('demande')) : null;
        try {
            if (null !== $demande && DroitsConception::peutModifier($compte, $demande)) {
                // Changement de support d'un brouillon : ses textes restent.
                $demande->choisirSupport($support);
                $demande->toucher(new \DateTimeImmutable());
                $this->em->flush();
            } else {
                $demande = $this->demandes->commencer($compte, $support, new \DateTimeImmutable());
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_conception_demandes');
        }

        return $this->redirectToRoute('app_espace_conception_informations', ['id' => (string) $demande->getPublicId()]);
    }

    /** « Mes demandes » : toutes les demandes du client et leur statut. */
    #[Route('/demandes', name: 'app_espace_conception_demandes', methods: ['GET'])]
    public function mesDemandes(): Response
    {
        $client = $this->compte()->getClient();

        return $this->vue('espace/conception/demandes.html.twig', [
            'liste' => $this->demandes->demandes($client),
            'enCours' => $this->demandes->enCours($client),
        ]);
    }

    /** Une demande : le parcours si elle se prépare encore, son suivi sinon. */
    #[Route('/{id}', name: 'app_espace_conception_demande', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function demande(string $id, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $demande = $this->trouver($compte, $id);
        // Lecture seule : le suivi, même d'une demande encore en préparation.
        if ($demande->estOuverte() && !DroitsConception::lectureSeule($compte)) {
            return $this->redirectToRoute(DemandeConception::EN_IA === $demande->getStatut() ? 'app_espace_conception_ia' : 'app_espace_conception_informations', ['id' => $id]);
        }
        $commande = $demande->getCommande();
        $controle = DemandesConception::dernierControle($demande);

        return $this->vue('espace/conception/suivi.html.twig', [
            'demande' => $demande,
            'commande' => null !== $commande && null !== $espace->commande($compte->getClient(), (string) $commande->getPublicId()) ? $commande : null,
            'controle' => $controle,
            'acompte' => TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent()),
            'solde' => TarifConception::solde($demande->getForfait(), $demande->getAcomptePourcent()),
            'acompteStatut' => $this->statutAcompte($demande),
        ]);
    }

    /** Étapes 2 et 3 (maquettes DC02 et DC05) : les informations, puis graphiste ou IA. */
    #[Route('/{id}/informations', name: 'app_espace_conception_informations', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function informations(string $id, Request $request, FichiersClient $fichiers, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $demande = $this->trouver($compte, $id);
        if (!$demande->estOuverte() || DroitsConception::lectureSeule($compte)) {
            return $this->redirectToRoute('app_espace_conception_demande', ['id' => $id]);
        }
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Paiement annulé sur la page Orange Money : rien n\'a été débité.');

            return $this->redirectToRoute('app_espace_conception_informations', ['id' => $id]);
        }
        $client = $compte->getClient();
        $this->credits->rapprocher($client, new \DateTimeImmutable());
        $choisis = $demande->getElements()->toArray();
        $proposes = array_merge($choisis, $fichiers->liste($client, true, 12), $fichiers->liste($client, false, 12));
        $elements = [];
        foreach ($proposes as $f) {
            $elements[$f->getId()] ??= $f;
        }

        return $this->vue('espace/conception/informations.html.twig', [
            'demande' => $demande,
            'supports' => $this->demandes->supports(),
            'elements' => \array_slice(array_values($elements), 0, 12),
            'choisis' => array_map(static fn (CommandeDetailFichier $f): ?int => $f->getId(), $choisis),
            'palette' => PromptConception::COULEURS,
            'styles' => DemandeConception::STYLES,
            'etapes' => DemandesConception::etapes(null !== $demande->getChoix() ? 3 : 2),
            'tarif' => $this->tarif($demande, $client->getId()),
            'omActif' => $this->orangeMoney->estConfigure(),
            'marchand' => $espace->marchandOrangeMoney(),
            'payeurs' => DroitsConception::peutPayer($compte) ? [] : array_map(static fn (CompteClient $c): string => $c->getNomAffiche(), $this->demandes->payeurs($client)),
            'extensions' => FichiersClient::extensions(),
            'enCours' => $this->demandes->enCours($client),
        ]);
    }

    /**
     * Enregistre les informations : appelé par la page à chaque pause de
     * saisie (JSON), ou par le formulaire sans JavaScript.
     */
    #[Route('/{id}/informations', name: 'app_espace_conception_enregistrer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function enregistrer(string $id, Request $request, FichiersClient $fichiers): Response
    {
        $json = str_contains((string) $request->headers->get('Content-Type'), 'application/json');
        $saisie = $json ? (array) json_decode($request->getContent(), true) : $request->request->all();
        if (!$this->isCsrfTokenValid('espace', (string) ($json ? $request->headers->get('X-CSRF-TOKEN') : ($saisie['_token'] ?? '')))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->preparateur();
        $demande = $this->trouver($compte, $id);
        if (!DroitsConception::peutModifier($compte, $demande)) {
            return $json ? $this->json(['erreur' => 'Cette demande ne se modifie plus.'], 409) : $this->redirectToRoute('app_espace_conception_demande', ['id' => $id]);
        }
        $elements = [];
        foreach (\is_array($saisie['elements'] ?? null) ? $saisie['elements'] : [] as $idFichier) {
            if (null !== ($f = $fichiers->trouver($compte->getClient(), (int) $idFichier))) {
                $elements[] = $f;
            }
        }
        $erreurs = $this->demandes->enregistrer($demande, $saisie, $elements, new \DateTimeImmutable());
        if ($json) {
            return $this->json(['ok' => [] === $erreurs, 'erreurs' => $erreurs]);
        }
        foreach ($erreurs as $message) {
            $this->addFlash('error', $message);
        }
        if ('ia' === ($saisie['suite'] ?? null) && [] === $erreurs) {
            return $this->redirectToRoute('app_espace_conception_ia', ['id' => $id]);
        }
        if ([] === $erreurs) {
            $this->addFlash('succes', 'Informations enregistrées.');
        }

        return $this->redirectToRoute('app_espace_conception_informations', ['id' => $id]);
    }

    /** Acompte du graphiste par Orange Money (maquette DC03) : départ vers la page de paiement d'Orange. */
    #[Route('/{id}/acompte', name: 'app_espace_conception_acompte', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function acompte(string $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->payeur();
        $demande = $this->trouver($compte, $id);
        try {
            if (!$demande->estOuverte()) {
                throw new \DomainException('Cette demande est déjà partie à l\'atelier.');
            }
            DemandesConception::verifierComplete($demande);
            $montant = TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent());
            if (OrangeMoney::totalChange($request->request->getString('total'), $this->orangeMoney->frais($montant))) {
                throw new \DomainException('Le montant a changé : vérifiez le récapitulatif avant de payer.');
            }
            $demande->setChoix(DemandeConception::GRAPHISTE)->setEtape(4);
            $this->em->flush();

            return $this->redirect($this->orangeMoney->demarrerAcompte($demande, $compte, $montant, new \DateTimeImmutable())->getLienPaiement());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_conception_informations', ['id' => $id]);
        }
    }

    /**
     * Sans paiement Orange Money en ligne (clés absentes) ou sans acompte :
     * la demande part à l'atelier, l'acompte se règle au numéro marchand.
     */
    #[Route('/{id}/envoyer', name: 'app_espace_conception_envoyer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function envoyer(string $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->payeur();
        $demande = $this->trouver($compte, $id);
        $acompte = TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent());
        try {
            if ($acompte > 0 && $this->orangeMoney->estConfigure()) {
                throw new \DomainException('Payez l\'acompte par Orange Money pour envoyer la demande.');
            }
            $this->demandes->envoyerSansPaiementEnLigne($demande, $compte, new \DateTimeImmutable());
            $this->addFlash('succes', $acompte > 0
                ? \sprintf('Demande %s envoyée. La conception démarre dès réception de l\'acompte (Orange Money au numéro marchand, ou en boutique).', $demande->getNumero())
                : \sprintf('Demande %s envoyée à l\'atelier.', $demande->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_conception_informations', ['id' => $id]);
        }

        return $this->redirectToRoute('app_espace_conception_demande', ['id' => $id]);
    }

    /** Un demandeur prépare : les personnes qui paient pour l'entreprise sont prévenues. */
    #[Route('/{id}/demander-paiement', name: 'app_espace_conception_demander_paiement', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function demanderPaiement(string $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->preparateur();
        $demande = $this->trouver($compte, $id);
        try {
            if (!DroitsConception::peutModifier($compte, $demande)) {
                throw new \DomainException('Cette demande ne se modifie plus.');
            }
            $noms = $this->demandes->demanderPaiement($demande, $compte, new \DateTimeImmutable());
            $this->addFlash('succes', \sprintf('Demande %s prête. %s peut la payer depuis son espace : nous l\'avons prévenu.', $demande->getNumero(), implode(' ou ', $noms)));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_conception_informations', ['id' => $id]);
    }

    /** Génération par IA (maquettes DC04 et DC05). */
    #[Route('/{id}/ia', name: 'app_espace_conception_ia', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function ia(string $id, Request $request, ImagesIa $images): Response
    {
        $compte = $this->compte();
        $demande = $this->trouver($compte, $id);
        if (!$demande->estOuverte() || DroitsConception::lectureSeule($compte)) {
            return $this->redirectToRoute('app_espace_conception_demande', ['id' => $id]);
        }
        $client = $compte->getClient();
        $this->credits->rapprocher($client, new \DateTimeImmutable());
        $liste = $images->images($demande);
        $choisie = null;
        foreach ($liste as $i) {
            if ($i->getId() === $request->query->getInt('image')) {
                $choisie = $i;
            }
        }

        return $this->vue('espace/conception/ia.html.twig', [
            'demande' => $demande,
            'images' => $liste,
            'choisie' => $choisie ?? ([] !== $liste ? $liste[\count($liste) - 1] : null),
            'tarif' => $this->tarif($demande, $client->getId()),
            'estimation' => $this->demandes->estimationImpression($demande, $client->isB2B()),
            'styles' => DemandeConception::STYLES,
            'palette' => PromptConception::COULEURS,
            'consigneMax' => PromptConception::CONSIGNE_MAX,
            'payeurs' => DroitsConception::peutPayer($compte) ? [] : array_map(static fn (CompteClient $c): string => $c->getNomAffiche(), $this->demandes->payeurs($client)),
        ]);
    }

    /** Génère une image (JSON) : crédit débité, remboursé si le fournisseur échoue. */
    #[Route('/{id}/generer', name: 'app_espace_conception_generer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function generer(string $id, Request $request, ImagesIa $images): JsonResponse
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $compte = $this->compte();
        if (!DroitsConception::peutPayer($compte)) {
            return $this->json(['erreur' => 'Chaque image est payée : seule une personne qui paie pour l\'entreprise peut générer.'], 403);
        }
        $demande = $this->trouver($compte, $id);
        $donnees = (array) json_decode($request->getContent(), true);
        try {
            $image = $images->generer($demande, $compte, \is_string($donnees['consigne'] ?? null) ? $donnees['consigne'] : null, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json([
            'image' => $image->getId(),
            'url' => $this->generateUrl('app_espace_conception_ia', ['id' => $id, 'image' => $image->getId()]),
            'solde' => $this->credits->solde($compte->getClient()),
        ]);
    }

    /** Image générée, servie à son seul client (jamais depuis public/). */
    #[Route('/image/{id}/{taille}', name: 'app_espace_conception_image', requirements: ['id' => '\d+', 'taille' => 'v|g'], methods: ['GET'])]
    public function image(int $id, string $taille, ImagesIa $images): Response
    {
        $image = $images->trouver($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        $apercu = $images->apercu($image, $taille) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($apercu['chemin'], 200, ['Content-Type' => $apercu['type'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** « Choisir et commander l'impression » (maquette DC04) : commande en ligne à confirmer par l'atelier. */
    #[Route('/{id}/commander', name: 'app_espace_conception_commander', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function commander(string $id, Request $request, ImagesIa $images): Response
    {
        $this->jeton($request);
        $compte = $this->preparateur();
        $demande = $this->trouver($compte, $id);
        $image = $images->trouver($compte->getClient(), $request->request->getInt('image'));
        try {
            if (null === $image || $image->getDemande() !== $demande) {
                throw new \DomainException('Choisissez une image.');
            }
            $commande = $this->em->wrapInTransaction(function () use ($images, $image, $demande, $compte, $request) {
                $fichier = $images->enFichierClient($image, $demande);

                return $this->demandes->commanderImpression($demande, $compte, $image, $fichier, $request->request->getBoolean('finalisation'), new \DateTimeImmutable());
            });
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_conception_ia', ['id' => $id]);
        }
        $this->addFlash('succes', $commande->attendValidationEntreprise()
            ? \sprintf('Demande %s envoyée : elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero())
            : \sprintf('Commande %s envoyée. L\'atelier confirme le prix et remplace les textes avant l\'impression, puis vous prévient.', $commande->getNumero()));

        return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
    }

    #[Route('/{id}/supprimer', name: 'app_espace_conception_supprimer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function supprimer(string $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->preparateur();
        $demande = $this->trouver($compte, $id);
        try {
            if (!DroitsConception::peutModifier($compte, $demande)) {
                throw new \DomainException('Cette demande ne se supprime plus.');
            }
            $this->demandes->supprimer($demande);
            $this->addFlash('succes', 'Demande supprimée.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_conception_demandes');
    }

    /** Crédit IA : solde, packs, relevé. */
    #[Route('/credit', name: 'app_espace_conception_credit', methods: ['GET'])]
    public function credit(Request $request, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        if (DroitsConception::lectureSeule($compte)) {
            return $this->lectureSeule();
        }
        if (!DroitsConception::peutPayer($compte)) {
            // Le solde du crédit est un montant de l'entreprise : comme le solde des factures, pour qui paie seulement.
            return $this->redirectToRoute('app_espace_conception_demandes');
        }
        $client = $compte->getClient();
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Paiement annulé sur la page Orange Money : rien n\'a été débité.');

            return $this->redirectToRoute('app_espace_conception_credit', array_filter(['demande' => $request->query->getString('demande')]));
        }
        $this->credits->rapprocher($client, new \DateTimeImmutable());
        $demande = '' !== $request->query->getString('demande') ? $this->demandes->trouver($client, $request->query->getString('demande')) : null;
        $prix = $this->reglages->prixImage();

        return $this->vue('espace/conception/credit.html.twig', [
            'solde' => $this->credits->solde($client),
            'prixImage' => $prix,
            // Chaque pack avec son récapitulatif Orange Money, calculé ici.
            'packs' => array_map(fn (int $n): array => ['images' => $n, 'prix' => TarifConception::prixPack($n, $prix), 'om' => $this->orangeMoney->frais(TarifConception::prixPack($n, $prix))], $this->reglages->packs()),
            'releve' => $this->credits->releve($client),
            'enAttente' => $this->credits->enAttente($client),
            'omActif' => $this->orangeMoney->estConfigure(),
            'marchand' => $espace->marchandOrangeMoney(),
            'demande' => $demande,
            'iaDisponible' => $this->reglages->iaDisponible(),
        ]);
    }

    /** Achat d'un pack : Orange Money en ligne, sinon commande à régler au numéro marchand. */
    #[Route('/credit', name: 'app_espace_conception_credit_acheter', methods: ['POST'])]
    public function acheterCredit(Request $request, CommandesConception $commandes): Response
    {
        $this->jeton($request);
        $compte = $this->payeur();
        $client = $compte->getClient();
        $images = $request->request->getInt('images');
        $demande = '' !== $request->request->getString('demande') ? $this->demandes->trouver($client, $request->request->getString('demande')) : null;
        $retour = array_filter(['demande' => null !== $demande ? (string) $demande->getPublicId() : null]);
        try {
            if (!\in_array($images, $this->reglages->packs(), true)) {
                throw new \DomainException('Choisissez un pack de crédit.');
            }
            $montant = TarifConception::prixPack($images, $this->reglages->prixImage());
            if ($montant <= 0) {
                throw new \DomainException('Ce pack n\'est pas à vendre.');
            }
            if ($this->orangeMoney->estConfigure()) {
                if (OrangeMoney::totalChange($request->request->getString('total'), $this->orangeMoney->frais($montant))) {
                    throw new \DomainException('Le montant a changé : vérifiez le récapitulatif avant de payer.');
                }

                return $this->redirect($this->orangeMoney->demarrerCredit($client, $compte, $images, $montant, new \DateTimeImmutable(), $retour['demande'] ?? null)->getLienPaiement());
            }
            $commande = $commandes->packEnAttente($client, $compte, $images, $montant, new \DateTimeImmutable());
            $this->addFlash('succes', \sprintf('Pack de %d image%s commandé (%s). Payez-le par Orange Money au numéro marchand ou en boutique : le crédit s\'ajoute dès que la caisse a validé le paiement.', $images, $images > 1 ? 's' : '', $commande->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_conception_credit', $retour);
    }

    /**
     * Retour de la page Orange Money (acompte ou pack) : l'état est relu
     * chez Orange, sans attendre sa notification.
     */
    #[Route('/retour', name: 'app_espace_conception_retour', methods: ['GET'])]
    public function retour(Request $request): Response
    {
        $client = $this->compte()->getClient();
        $transaction = $this->em->getRepository(TransactionOrangeMoney::class)->findOneBy(['orderId' => mb_substr($request->query->getString('o'), 0, 40)]);
        if (!$transaction instanceof TransactionOrangeMoney || TransactionOrangeMoney::OBJET_COMMANDE === $transaction->getObjet() || $transaction->getClient()?->getId() !== $client->getId()) {
            throw $this->createNotFoundException();
        }
        $statut = $this->orangeMoney->verifier($transaction, new \DateTimeImmutable());
        // Ce qu'Orange a débité : le montant et les frais.
        $total = TarifConception::francs($transaction->getTotal());
        $credit = TransactionOrangeMoney::OBJET_CREDIT_IA === $transaction->getObjet();
        $this->addFlash(...match ($statut) {
            TransactionOrangeMoney::REUSSIE => ['succes', $credit
                ? \sprintf('Paiement Orange Money de %s reçu, merci ! Votre crédit IA est prêt ; la caisse valide le paiement et le reçu arrive dans votre espace.', $total)
                : \sprintf('Acompte de %s reçu (%s payés avec Orange Money), merci ! Votre demande part à l\'atelier ; la caisse valide le paiement et le reçu arrive dans votre espace.', TarifConception::francs($transaction->getMontant()), $total)],
            TransactionOrangeMoney::ECHOUEE => ['error', 'Le paiement Orange Money n\'a pas abouti : rien n\'a été débité. Vous pouvez réessayer.'],
            TransactionOrangeMoney::EXPIREE => ['error', 'Le lien de paiement a expiré. Relancez le paiement.'],
            default => ['succes', 'Paiement en cours chez Orange Money : il apparaît ici dès qu\'Orange le confirme.'],
        });

        $demande = $transaction->getDemandeConception();
        if (null !== $demande) {
            return $this->redirectToRoute('app_espace_conception_demande', ['id' => (string) $demande->getPublicId()]);
        }
        $pour = $request->query->getString('demande');

        return '' !== $pour && null !== $this->demandes->trouver($client, $pour)
            ? $this->redirectToRoute('app_espace_conception_ia', ['id' => $pour])
            : $this->redirectToRoute('app_espace_conception_credit');
    }

    /**
     * Prix montrés au client : ceux des réglages, jamais un coût.
     *
     * @return array<string, mixed>
     */
    private function tarif(DemandeConception $demande, ?int $client): array
    {
        $solde = null !== $client ? $this->credits->solde($demande->getClient()) : 0;
        $prix = $this->reglages->prixImage();

        return [
            'forfait' => $demande->getForfait(),
            'pourcent' => $demande->getAcomptePourcent(),
            'acompte' => TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent()),
            'solde' => TarifConception::solde($demande->getForfait(), $demande->getAcomptePourcent()),
            'delai' => $demande->getDelaiBatHeures(),
            'corrections' => $demande->getCorrectionsIncluses(),
            'iaDisponible' => $this->reglages->iaDisponible(),
            'prixImage' => $prix,
            'finalisation' => $this->reglages->finalisation(),
            // Récapitulatif Orange Money de l'acompte (fonds de soutien, frais de retrait, total).
            'omAcompte' => $this->orangeMoney->frais(TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent())),
            'credit' => $solde,
            'imagesPossibles' => TarifConception::imagesPossibles($solde, $prix),
        ];
    }

    /** Où en est l'acompte : payé (à valider ou validé), ou à régler. */
    private function statutAcompte(DemandeConception $demande): ?string
    {
        if (DemandeConception::GRAPHISTE !== $demande->getChoix()) {
            return null;
        }
        $paiement = $demande->getPaiementAcompte();
        if (null !== $paiement) {
            return match ($paiement->getStatut()) {
                Paiements::STATUT_VALIDE => 'valide',
                Paiements::STATUT_REJETE, Paiements::STATUT_ANNULE => 'rejete',
                default => 'a_valider',
            };
        }
        $commande = $demande->getCommande();
        if (null !== $commande && $commande->getTotalPaye() >= TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent())) {
            return 'valide';
        }

        return TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent()) > 0 ? 'a_regler' : null;
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, array $parametres): Response
    {
        $compte = $this->compte();

        return $this->render($gabarit, $parametres + [
            'onglet' => 'commandes',
            'moi' => $compte->getClient(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'conceptionPeutPreparer' => DroitsConception::peutPreparer($compte),
            'conceptionPeutPayer' => DroitsConception::peutPayer($compte),
            // Lecture seule : le suivi, sans montant (comme ses commandes).
            'conceptionLecture' => DroitsConception::lectureSeule($compte),
            'credit' => $this->credits->solde($compte->getClient()),
        ]);
    }

    /** Pages qui ne servent qu'à préparer ou payer : un compte en lecture seule va à la liste de ses demandes. */
    private function lectureSeule(): Response
    {
        $this->addFlash('error', 'Votre accès est en lecture seule : vous suivez les demandes de conception, sans les préparer ni les payer.');

        return $this->redirectToRoute('app_espace_conception_demandes');
    }

    private function trouver(CompteClient $compte, string $id): DemandeConception
    {
        $demande = $this->demandes->trouver($compte->getClient(), $id);
        if (null === $demande || !DroitsConception::peutVoir($compte, $demande)) {
            throw $this->createNotFoundException();
        }

        return $demande;
    }

    /** Préparer une demande : tout compte sauf la lecture seule. */
    private function preparateur(): CompteClient
    {
        $compte = $this->compte();
        if (!DroitsConception::peutPreparer($compte)) {
            throw $this->createAccessDeniedException('Votre accès est en lecture seule.');
        }

        return $compte;
    }

    /** Payer un acompte, acheter du crédit IA : un payeur (ou un particulier). */
    private function payeur(): CompteClient
    {
        $compte = $this->compte();
        if (!DroitsConception::peutPayer($compte)) {
            throw $this->createAccessDeniedException('Seule une personne qui paie pour l\'entreprise peut payer.');
        }

        return $compte;
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** Le compte frais de la base (celui de la session ne garde pas la fiche client). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
