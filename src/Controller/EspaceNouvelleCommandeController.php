<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\ImageIa;
use App\Entity\ProduitConfiguration;
use App\Repository\CompteClientRepository;
use App\Security\EssaisCodePromo;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\Conception\ImagesIa;
use App\Service\EspaceClient\BordsFichier;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\EspaceClient\ElementAPayer;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\EspaceClient\OptionsCommande;
use App\Service\EspaceClient\PageNouvelleCommande;
use App\Service\EspaceClient\PaiementALaCommande;
use App\Service\EspaceClient\RecommanderClient;
use App\Service\Paiement\OrangeMoney;
use App\Service\Paiement\ReglementsEnLigne;
use App\Service\Parametres\ReglagesSaisie;
use App\Service\PiecesParMetreCarre;
use App\Service\Promotions\CodePromoRefuse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nouvelle commande de l'espace client en quatre étapes (maquettes CO01 à
 * CO04, téléphone CO06) : produit, options, fichier, livraison et
 * paiement. Les prix sont toujours calculés ici ; la commande arrive à
 * l'atelier en brouillon « à confirmer ». Le payeur peut régler l'acompte
 * ou le tout par Orange Money dès l'envoi : le paiement reste « en
 * attente » jusqu'à la caisse et la production attend la confirmation de
 * la boutique. Un demandeur d'entreprise ne voit aucun montant et ne paie
 * pas : sa demande attend son valideur.
 */
#[Route('/espace/commandes', name: 'app_espace_')]
final class EspaceNouvelleCommandeController extends AbstractController
{
    private const UUID = '[0-9a-f-]{36}';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly CompteEntreprise $compteEntreprise,
        private readonly NouvelleCommande $service,
        private readonly DelaisCommande $delais,
        private readonly ReglagesSaisie $reglagesSaisie,
        private readonly OrangeMoney $orangeMoney,
        /** Espacement des pièces au m² (réglage Ventes › « Pièces au m² ») : quantité donnée en m². */
        private readonly PiecesParMetreCarre $piecesM2,
    ) {
    }

    #[Route('/nouvelle', name: 'nouvelle_commande', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, FichiersClient $fichiers, CatalogueAtelier $atelier, EssaisCodePromo $essaisCode, RecommanderClient $recommander, MesProduits $mesProduits, ImagesIa $imagesIa, EntityManagerInterface $em): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $b2b = $client->isB2B();
        $montants = $compte->montantsVisibles();
        $produitsEnregistres = $mesProduits->liste($client);
        $commun = [
            'onglet' => 'commandes',
            'moi' => $client,
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'montants' => $montants,
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
            'mesProduits' => \array_slice($produitsEnregistres, 0, 2),
            'nombreMesProduits' => \count($produitsEnregistres),
        ];

        if (!$this->service->estOuverte()) {
            // Réglages › Espace client › Commande en ligne coupé : la page l'explique, rien ne s'enregistre.
            if ($request->isMethod('POST')) {
                $this->jeton($request);
                $this->addFlash('error', NouvelleCommande::FERMEE);
            }

            return $this->render('espace/app/nouvelle_commande.html.twig', $commun + ['etape' => 1, 'fermee' => true]);
        }
        $configs = $this->service->produits();

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            try {
                $commande = $this->commander($request, $compte, $fichiers, $essaisCode, $imagesIa, $em);
                $paiement = $request->request->getString('paiement');
                if ($commande->attendValidationEntreprise()) {
                    $this->compteEntreprise->prevenirValideurs($commande, $compte);
                    $this->addFlash('succes', \sprintf('Demande %s envoyée. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero()));
                } elseif (\in_array($paiement, ['acompte', 'tout'], true) && $this->peutPayerEnLigne($compte, $commande)) {
                    // Récapitulatif avec les frais Orange Money, puis la page d'Orange.
                    return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => (string) $commande->getPublicId(), 'part' => $paiement, 'envoyee' => 1]);
                } else {
                    $this->addFlash('succes', \sprintf('Commande %s envoyée. L\'atelier confirme le prix et votre fichier, puis vous prévient.%s', $commande->getNumero(), 'boutique' === $paiement ? ' Vous paierez en boutique.' : ''));
                }

                return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $choix = self::saisies($request->request->all() + $request->query->all());
        $config = $this->service->produit((int) ($choix['produit'] ?? 0));

        if (null === $config) {
            // Étape 1 (maquette CO01) : le catalogue et « Commander à nouveau ».
            $catalogue = PageNouvelleCommande::catalogue($configs, $b2b, $montants);
            $categorie = $request->query->getInt('categorie');

            return $this->render('espace/app/nouvelle_commande.html.twig', $commun + [
                'etape' => 1,
                'catalogue' => $catalogue,
                'categorie' => $categorie,
                'atelier' => [] !== $atelier->modeles(),
                'recommander' => $recommander->articles($client, 3, $montants),
            ]);
        }

        // Étapes 2 à 4 (maquettes CO02 à CO04) : un seul formulaire, une étape à la fois.
        $matieres = PageNouvelleCommande::memeProduit($config, $configs);
        $refaire = $this->ligneARefaire($client, (string) ($choix['refaire'] ?? ''), $em);
        $creations = array_values(array_filter(
            $em->getRepository(ImageIa::class)->findBy(['client' => $client, 'statut' => ImageIa::PRETE], ['id' => 'DESC'], 12),
            static fn (ImageIa $i): bool => null !== $imagesIa->chemin($i),
        ));
        $reglages = $this->delais->reglages();
        $estimation = $this->estimation($compte, $config, [
            'quantite' => $choix['quantite'] ?? null,
            'largeur' => $choix['largeur'] ?? null,
            'hauteur' => $choix['hauteur'] ?? null,
            'unite' => $choix['unite_taille'] ?? 'cm',
            'unite_quantite' => $choix['unite_quantite'] ?? null,
            'quantite_m2' => $choix['quantite_m2'] ?? null,
            'finitions' => $choix['finitions'] ?? null,
            'delai' => $choix['delai'] ?? null,
            'reception' => $choix['reception'] ?? null,
        ]);
        $mesFichiers = array_values(array_filter($fichiers->liste($client), static fn (CommandeDetailFichier $f): bool => null === $f->getCommandeDetail()));

        return $this->render('espace/app/nouvelle_commande.html.twig', $commun + [
            'etape' => max(2, min(4, (int) ($choix['etape'] ?? 2))),
            'config' => $config,
            'nomProduit' => \App\Service\Site\CatalogueSite::nomAffiche((string) $config->getProduit()?->getNom()),
            'matieres' => PageNouvelleCommande::matieres($matieres, $b2b, $montants),
            'formats' => PageNouvelleCommande::FORMATS_COURANTS,
            'choix' => $choix,
            'estimation' => $estimation,
            'reglages' => $reglages,
            'mesFichiers' => \array_slice($mesFichiers, 0, 6),
            'creations' => $creations,
            'refaire' => $refaire,
            'extensions' => FichiersClient::extensions(),
            'adresses' => $this->compteEntreprise->adresses($client),
            'entreprise' => CompteClient::clientAPlusieursComptes($client),
            // « Oumar · +223 60 69 78 04 » (maquette CO04) : le livreur appelle cette personne.
            'contact' => trim(explode(' ', trim((string) $compte->getNomAffiche()))[0].' · '.self::telephone((string) $compte->getTelephone()), ' ·'),
            'fraisLivraison' => $this->reglagesSaisie->fraisEnLigne(),
            // Paiement à la commande : le payeur, quand Orange Money est ouvert en ligne.
            'omActif' => $compte->peut(CompteClient::DROIT_PAYEUR) && $this->orangeMoney->estOuvert(),
            'telephoneOm' => $compte->getTelephone(),
            'champCodePromo' => !$b2b,
            'devis' => ['produit' => $config->getId()],
        ]);
    }

    /**
     * Prix et délais recalculés à chaque changement (même calcul qu'à
     * l'enregistrement). Un demandeur reçoit les mêmes contrôles (taille,
     * quantité), les délais et les créneaux, mais aucun montant.
     */
    #[Route('/estimer', name: 'estimer', methods: ['POST'])]
    public function estimer(Request $request): JsonResponse
    {
        $this->jetonEntete($request);
        $compte = $this->compte();
        if (!$this->service->estOuverte()) {
            return $this->json(['erreur' => NouvelleCommande::FERMEE], 403);
        }
        $donnees = json_decode(mb_substr($request->getContent(), 0, 4000), true, 4);
        $donnees = self::saisies(\is_array($donnees) ? $donnees : []);
        $config = $this->service->produit((int) ($donnees['produit'] ?? 0));
        if (null === $config) {
            return $this->json(['erreur' => 'Choisissez un produit.'], 422);
        }
        $estimation = $this->estimation($compte, $config, $donnees);

        return $this->json($estimation, isset($estimation['erreur']) ? 422 : 200);
    }

    /**
     * Contrôle automatique d'un fichier du client à la taille commandée
     * (maquette CO03) : dimensions, résolution, couleurs et texte près du
     * bord. Le contrôle est gardé sur le fichier.
     */
    #[Route('/nouvelle/controle/{id}', name: 'nouvelle_commande_controle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function controle(int $id, Request $request, FichiersClient $fichiers): JsonResponse
    {
        $this->jetonEntete($request);
        $client = $this->compte()->getClient();
        if (!$this->service->estOuverte()) {
            return $this->json(['erreur' => NouvelleCommande::FERMEE], 403);
        }
        $f = $fichiers->trouver($client, $id) ?? throw $this->createNotFoundException();
        $donnees = json_decode(mb_substr($request->getContent(), 0, 1000), true, 3);
        $donnees = self::saisies(\is_array($donnees) ? $donnees : []);
        [$largeur, $hauteur] = self::taille($donnees['largeur'] ?? null, $donnees['hauteur'] ?? null, (string) ($donnees['unite'] ?? 'cm'));
        if (null === $f->getCommandeDetail()) {
            $fichiers->recontroler($f, $largeur, $hauteur);
            $this->service->enregistrer();
        }
        $chemin = $fichiers->chemin($f);

        return $this->json([
            'id' => $f->getId(),
            'nom' => $f->getNomOriginal(),
            'taille' => self::octets((int) $f->getTaille()),
            'image' => \in_array($f->getTypeMime(), ['image/jpeg', 'image/png'], true),
            'resume' => FichiersClient::resume($f->getAnalyse()),
            'controles' => self::liste($f->getAnalyse() ?? [], null !== $chemin ? BordsFichier::controler($chemin, $largeur, $hauteur, (bool) ($donnees['oeillets'] ?? false)) : null, $largeur, $hauteur),
        ]);
    }

    /**
     * Paiement à la commande (étape 4, puis ce récapitulatif) : acompte ou
     * tout, frais Orange Money compris, avant la page d'Orange. Le même
     * règlement que « Factures et paiements » (ReglementsEnLigne) : retour
     * sur sa page « Paiement reçu », un paiement « en attente » à la caisse.
     * Seulement pour le payeur, et une commande en ligne de son client
     * encore « à confirmer » (PaiementALaCommande::payable).
     */
    #[Route('/nouvelle/{id}/paiement', name: 'nouvelle_commande_paiement', requirements: ['id' => self::UUID], methods: ['GET', 'POST'])]
    public function paiement(
        string $id,
        Request $request,
        EspaceClient $espace,
        ReglementsEnLigne $reglements,
        #[Autowire(service: 'limiter.espace_paiement_compte')] RateLimiterFactoryInterface $limite,
    ): Response {
        $compte = $this->compte();
        if (!$compte->peut(CompteClient::DROIT_PAYEUR)) {
            throw $this->createAccessDeniedException('Le paiement est réservé aux personnes qui paient pour l\'entreprise.');
        }
        $commande = $espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        if ($commande->isStatut()) {
            // Confirmée entre-temps : « Factures et paiements » (nouveau total compris).
            return $this->redirectToRoute('app_espace_payer_selection', ['e' => [ElementAPayer::cleDe($commande)]]);
        }
        $part = PaiementALaCommande::TOUT === ($request->request->getString('part') ?: $request->query->getString('part')) ? PaiementALaCommande::TOUT : PaiementALaCommande::ACOMPTE;
        $maintenant = new \DateTimeImmutable();
        $pourcentage = $this->delais->pourcentageAcompte($compte->getClient());
        $element = PaiementALaCommande::payable($commande, $compte) ? PaiementALaCommande::element($commande, $part, $pourcentage, $maintenant) : null;
        if (null === $element) {
            return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
        }
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Paiement annulé sur la page Orange Money : rien n\'a été débité. Votre commande est bien envoyée ; vous pouvez payer maintenant ou plus tard.');

            return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => $id, 'part' => $part]);
        }
        $part = $element->estAcompte() ? PaiementALaCommande::ACOMPTE : PaiementALaCommande::TOUT;
        // Orange Money seul : l'avoir de la boutique se règle dans « Factures et paiements ».
        $plan = $reglements->plan($compte, [$element], false, null, $maintenant);

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            if ((string) $plan['frais']->total() !== $request->request->getString('total')) {
                $this->addFlash('error', 'Le montant a changé : vérifiez le récapitulatif avant de payer.');

                return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => $id, 'part' => $part]);
            }
            if (!$limite->create('compte-'.$compte->getId())->consume()->isAccepted()) {
                $this->addFlash('error', 'Vous avez lancé beaucoup de paiements. Réessayez dans quelques minutes, ou payez en boutique.');

                return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
            }
            try {
                $resultat = $reglements->demarrer($compte, [$element], $plan, $maintenant);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => $id, 'part' => $part]);
            }

            return null !== $resultat['lien']
                ? $this->redirect($resultat['lien'])
                : $this->redirectToRoute('app_espace_payer_recu', ['reference' => $resultat['reglement']->getReference()]);
        }

        $tout = PaiementALaCommande::element($commande, PaiementALaCommande::TOUT, $pourcentage, $maintenant);
        $acompte = PaiementALaCommande::element($commande, PaiementALaCommande::ACOMPTE, $pourcentage, $maintenant);

        return $this->render('espace/app/nouvelle_commande_paiement.html.twig', [
            'onglet' => 'commandes',
            'moi' => $compte->getClient(),
            'peutPayer' => true,
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'commande' => $commande,
            'titre' => EspaceClient::titre($commande),
            'compte' => $compte,
            'part' => $part,
            'pourcentage' => $pourcentage,
            'acompteDisponible' => null !== $acompte && $acompte->estAcompte(),
            'element' => $element,
            'aPayer' => null !== $tout ? $tout->montant : 0,
            'frais' => $plan['frais'],
            'omActif' => $this->orangeMoney->estOuvert(),
            'marchand' => $espace->marchandOrangeMoney(),
        ]);
    }

    /**
     * Lit le formulaire et enregistre la commande « à confirmer ».
     *
     * @throws \DomainException
     */
    private function commander(Request $request, CompteClient $compte, FichiersClient $fichiers, EssaisCodePromo $essaisCode, ImagesIa $imagesIa, EntityManagerInterface $em): Commandes
    {
        $client = $compte->getClient();
        $r = $request->request;
        $config = $this->service->produit($r->getInt('produit'));
        if (null === $config) {
            throw new \DomainException('Choisissez un produit.');
        }
        // Les étapes ne se montrent qu'avec le navigateur : sans case cochée, rien ne part (recette, ancien formulaire).
        if ($r->has('etapes') && !$r->getBoolean('conditions')) {
            throw new \DomainException('Cochez « J\'accepte les conditions de vente » pour envoyer la commande.');
        }
        // Commande en ligne coupée, trop de commandes en attente : refusé avant de copier un fichier.
        $this->service->verifierEnAttente($client);
        [$largeur, $hauteur] = self::taille($r->get('largeur'), $r->get('hauteur'), $r->getString('unite_taille', 'cm'));
        // Quantité lue comme l'estimation (vide : le minimum que « Votre commande » montrait), jamais
        // une page d'erreur ; en m² (pièces au m²), elle vient de la surface. Contrôlée avant de copier un fichier.
        $quantite = max(1, (int) $r->getString('quantite') ?: max(1, $config->getQuantiteMinimale()));
        $espacement = $this->piecesM2->espacement();
        $saisie = NouvelleCommande::quantiteSaisie($config, $quantite, $r->getString('unite_quantite'), $r->getString('quantite_m2'), $largeur, $hauteur, $espacement);
        if (null !== $saisie['erreur']) {
            throw new \DomainException($saisie['erreur']);
        }
        $m2 = $saisie['m2'];
        $b2b = $client->isB2B();
        $ajuster = !$saisie['piecesM2'] ? null : static function (CommandesDetails $ligne) use ($m2, $espacement, $b2b): void {
            NouvelleCommande::enPiecesAuM2($ligne, $m2, $espacement, $b2b);
        };
        $mode = $r->getString('fichier_mode', 'fichier');

        $choisis = [];
        $notes = [];
        if ('fichier' === $mode) {
            foreach ($r->all('fichiers') as $fid) {
                $f = $fichiers->trouver($client, (int) $fid);
                if (null !== $f && null === $f->getCommandeDetail() && !$f->isLogo() && $f->estUploadTermine()) {
                    $choisis[] = $f;
                }
            }
        } elseif ('creation' === $mode) {
            $image = $imagesIa->trouver($client, $r->getInt('creation'));
            if (null === $image) {
                throw new \DomainException('Choisissez une de vos créations, ou envoyez votre fichier.');
            }
            $choisis[] = $imagesIa->enFichierClient($image, $image->getDemande());
            $notes[] = \sprintf('Fichier : création %s n° %d.', $image->getDemande()->getNumero(), $image->getNumero());
        } elseif ('aucun' === $mode) {
            $notes[] = 'Pas de fichier : visuel à concevoir par l\'atelier (le client attend votre appel).';
        }
        // « Refaire » : même fichier que la ligne d'une ancienne commande.
        $refaire = $this->ligneARefaire($client, $r->getString('refaire'), $em);
        if (null !== $refaire && 'fichier' === $mode && [] === $choisis) {
            foreach ($refaire->getFichiers() as $ancien) {
                $copie = $fichiers->reprendre($ancien, $client);
                if (null !== $copie) {
                    $choisis[] = $copie;
                }
            }
            $notes[] = \sprintf('Refaite à l\'identique de la commande %s : même fichier%s.', $refaire->getCommande()?->getNumero(), [] === $choisis ? ' (à reprendre de cette commande)' : '');
        }

        $options = $this->options($compte, $r->getString('delai'), 'livraison' === $r->getString('reception'), $r->getInt('adresse'), $r->getString('adresse_texte'), $r->getString('contact'), $r->getString('creneau'), true);

        // Code promo (docs/promotions.md) : les codes refusés sont comptés par compte et par connexion.
        $codePromo = mb_substr(trim($r->getString('code_promo')), 0, 40);
        if ('' !== $codePromo && $essaisCode->bloque((int) $client->getId(), (string) $request->getClientIp())) {
            throw new \DomainException('Trop de codes promo refusés : réessayez plus tard, ou passez la commande sans code.');
        }
        $note = trim(implode("\n", array_filter([trim($r->getString('note')), ...$notes])));
        try {
            $commande = $this->service->commander($client, $config, $saisie['quantite'], $largeur, $hauteur, array_map('intval', $r->all('finitions')), $choisis, '' === $note ? null : mb_substr($note, 0, 1500), new \DateTimeImmutable(), $ajuster, $codePromo, $options, $this->delais->reglages()['delaiExpress']);
        } catch (CodePromoRefuse $e) {
            $essaisCode->noterRefus((int) $client->getId(), (string) $request->getClientIp());
            throw $e;
        }
        // Le fichier se contrôle à la taille commandée.
        foreach ($choisis as $f) {
            $fichiers->recontroler($f, $largeur, $hauteur);
        }
        // Adresse déjà mise par les options : seulement le bon de commande et la validation interne.
        $this->compteEntreprise->preparerCommande($compte, $commande, $r->getString('bon_commande'), null);
        $this->service->enregistrer();

        return $commande;
    }

    /**
     * Prix, délais et créneaux pour la page. Les montants (total, détail,
     * acompte, frais Orange Money) seulement pour qui les voit ; l'acompte
     * et les frais Orange Money seulement pour le payeur.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private function estimation(CompteClient $compte, ProduitConfiguration $config, array $donnees): array
    {
        $client = $compte->getClient();
        $montants = $compte->montantsVisibles();
        [$largeur, $hauteur] = self::taille($donnees['largeur'] ?? null, $donnees['hauteur'] ?? null, (string) ($donnees['unite'] ?? 'cm'));
        $finitions = isset($donnees['finitions']) ? array_map('intval', (array) $donnees['finitions']) : null;
        if (null === $finitions) {
            // Première vue : les finitions proposées par défaut.
            $finitions = [];
            foreach ($config->getConfigurationFinitions() as $cf) {
                if ($cf->isActive() && ($cf->isSelectionneeParDefaut() || $cf->isObligatoire())) {
                    $finitions[] = (int) $cf->getId();
                }
            }
        }
        $quantite = max(1, (int) ($donnees['quantite'] ?? 0) ?: max(1, $config->getQuantiteMinimale()));
        // Quantité donnée en m² (pièces au m²) : les pièces viennent de la surface, comme à l'enregistrement.
        $espacement = $this->piecesM2->espacement();
        $saisie = NouvelleCommande::quantiteSaisie($config, $quantite, (string) ($donnees['unite_quantite'] ?? ''), (string) ($donnees['quantite_m2'] ?? ''), $largeur, $hauteur, $espacement);
        $options = $this->options($compte, (string) ($donnees['delai'] ?? ''), 'livraison' === ($donnees['reception'] ?? null), 0, '', '', '', false);
        $reglages = $this->delais->reglages();
        $heures = $options->estExpress() ? $reglages['delaiExpress'] : $reglages['standard'];
        $maintenant = new \DateTimeImmutable();
        $pret = $this->delais->pretLe($options->delai, $maintenant);
        $sortie = [
            'montants' => $montants,
            'pret' => ['jour' => DelaisCommande::jour($pret['pret']), 'quand' => DelaisCommande::quand($pret['depart'], $maintenant), 'limite' => str_replace(':00', ' h', $reglages['limite'])],
            'creneaux' => array_map(static fn (array $c): array => ['valeur' => $c['valeur'], 'libelle' => $c['libelle']], $this->delais->creneauxPour($pret['pret'])),
            'largeur' => $largeur,
            'hauteur' => $hauteur,
        ];
        if (null !== $saisie['erreur']) {
            // Montré dans « Votre commande » et sous la surface tapée.
            return $sortie + ['erreur' => $saisie['erreur'], 'erreurM2' => $saisie['erreur']];
        }
        try {
            $ligne = NouvelleCommande::construireLigne($config, $saisie['quantite'], $largeur, $hauteur, $finitions, $client->isB2B());
            if ($saisie['piecesM2']) {
                NouvelleCommande::enPiecesAuM2($ligne, $saisie['m2'], $espacement, $client->isB2B());
            }
        } catch (\DomainException $e) {
            return $sortie + ['erreur' => $e->getMessage()];
        }
        $commande = NouvelleCommande::assembler($ligne, $options, $reglages['delaiExpress']);
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR);
        $sortie['recap'] = PageNouvelleCommande::recapitulatif($commande, $ligne, $config, $montants, $heures, $payeur ? $this->delais->pourcentageAcompte($client) : null);
        if ($payeur && $montants && $this->orangeMoney->estOuvert()) {
            // Frais Orange Money en plus de chaque somme payée (fonds de soutien, retrait) : annoncés avant de payer.
            $acompte = $sortie['recap']['acompte']['maintenant'] ?? null;
            $sortie['orangeMoney'] = [
                'tout' => $this->orangeMoney->frais($commande->getTotalTtc())->total(),
                'acompte' => null !== $acompte ? $this->orangeMoney->frais($acompte)->total() : null,
            ];
        }

        return $sortie;
    }

    /**
     * Délai et réception choisis, avec les montants des Réglages. $strict :
     * à l'enregistrement, l'adresse et le créneau d'une livraison sont exigés.
     *
     * @throws \DomainException
     */
    private function options(CompteClient $compte, string $delai, bool $livraison, int $adresseId, string $adresseTexte, string $contact, string $creneau, bool $strict): OptionsCommande
    {
        $reglages = $this->delais->reglages();
        $delai = $this->delais->delai($delai);
        $pret = $this->delais->pretLe($delai, new \DateTimeImmutable())['pret'];
        if (!$livraison) {
            return new OptionsCommande($delai, $reglages['majoration'], false, 0, null, null, $pret);
        }
        $adresse = null;
        $choisi = null;
        if ($strict) {
            $enregistree = $adresseId > 0 ? $this->compteEntreprise->adresse($compte->getClient(), $adresseId) : null;
            $adresse = null !== $enregistree ? $enregistree->texte() : trim(mb_substr($adresseTexte, 0, 200));
            if ('' === $adresse) {
                throw new \DomainException('Indiquez l\'adresse de livraison à Bamako.');
            }
            $contact = trim(mb_substr($contact, 0, 80));
            if (null === $enregistree && '' !== $contact) {
                $adresse .= "\nContact : ".$contact;
            }
            $choisi = DelaisCommande::creneauChoisi($creneau, $this->delais->creneauxPour($pret));
            if (null === $choisi) {
                throw new \DomainException('Choisissez un créneau de livraison.');
            }
        }

        return new OptionsCommande($delai, $reglages['majoration'], true, $this->reglagesSaisie->fraisEnLigne(), null !== $adresse ? mb_substr($adresse, 0, 255) : null, $choisi['libelle'] ?? null, $choisi['jour'] ?? $pret);
    }

    /** Le payeur peut-il régler cette commande en ligne tout de suite ? */
    private function peutPayerEnLigne(CompteClient $compte, Commandes $commande): bool
    {
        return $compte->peut(CompteClient::DROIT_PAYEUR) && $this->orangeMoney->estOuvert() && PaiementALaCommande::payable($commande, $compte) && OrangeMoney::aPayer($commande) > 0;
    }

    /** Ligne d'une commande de ce client (« Refaire »), ou null. */
    private function ligneARefaire(\App\Entity\Clients $client, string $publicId, EntityManagerInterface $em): ?CommandesDetails
    {
        if (1 !== preg_match('/^'.self::UUID.'$/', $publicId)) {
            return null;
        }
        foreach ($em->getRepository(CommandesDetails::class)->findBy(['publicId' => \Symfony\Component\Uid\Uuid::fromString($publicId)], null, 1) as $ligne) {
            $commande = $ligne->getCommande();
            if (null !== $commande && $commande->getClients()?->getId() === $client->getId() && !$commande->isDeleted()) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * Les quatre contrôles montrés au client (maquette CO03), d'après le
     * contrôle automatique du fichier.
     *
     * @param list<array{libelle: string, valeur: string, etat: string}> $analyse
     * @param array{libelle: string, valeur: string, etat: string}|null  $bord
     *
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    private static function liste(array $analyse, ?array $bord, ?float $largeur, ?float $hauteur): array
    {
        $trouver = static function (string $debut) use ($analyse): ?array {
            foreach ($analyse as $c) {
                if (str_starts_with(mb_strtolower($c['libelle']), $debut)) {
                    return $c;
                }
            }

            return null;
        };
        $taille = null !== $largeur && null !== $hauteur ? \sprintf('%s × %s cm', PageNouvelleCommande::f($largeur), PageNouvelleCommande::f($hauteur)) : null;
        $ratio = $trouver('ratio') ?? $trouver('format de page');
        $liste = [];
        $liste[] = null !== $ratio
            ? ['libelle' => 'Dimensions', 'valeur' => (null !== $taille ? $taille.', ' : '').(\App\Service\AnalyseFichierImpression::OK === $ratio['etat'] ? 'proportions correctes' : $ratio['valeur']), 'etat' => $ratio['etat']]
            : ['libelle' => 'Dimensions', 'valeur' => 'Vérifiées par le graphiste'.(null !== $taille ? ' ('.$taille.')' : ''), 'etat' => \App\Service\AnalyseFichierImpression::A_VERIFIER];
        foreach (['résolution' => 'Résolution', 'mode colorimétrique' => 'Couleurs', 'polices' => 'Polices', 'fonds perdus' => 'Fonds perdus', 'type de fichier' => 'Type de fichier'] as $debut => $libelle) {
            $c = $trouver($debut);
            if (null !== $c && ('polices' !== $debut || \App\Service\AnalyseFichierImpression::OK !== $c['etat'])) {
                $liste[] = ['libelle' => $libelle, 'valeur' => $c['valeur'], 'etat' => $c['etat']];
            }
        }
        if (null !== $bord) {
            $liste[] = $bord;
        }

        return $liste;
    }

    /**
     * Largeur et hauteur saisies, en cm. La page les saisit en mètres
     * (« 3,00 ») ; les autres appels, en cm.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function taille(mixed $largeur, mixed $hauteur, string $unite): array
    {
        $facteur = 'm' === $unite ? 100 : 1;
        $l = self::nombre($largeur);
        $h = self::nombre($hauteur);

        return [null !== $l ? round($l * $facteur, 1) : null, null !== $h ? round($h * $facteur, 1) : null];
    }

    /**
     * Saisies de la page ou de l'appel du navigateur : des textes, et des
     * listes pour les finitions et les fichiers. Une valeur d'une autre
     * forme (tableau à la place d'un texte) est écartée.
     *
     * @param array<array-key, mixed> $brut
     *
     * @return array<string, string|list<string>>
     */
    private static function saisies(array $brut): array
    {
        $saisies = [];
        foreach ($brut as $cle => $valeur) {
            if (\in_array($cle, ['finitions', 'fichiers'], true)) {
                if (\is_array($valeur)) {
                    $saisies[$cle] = array_values(array_map('strval', array_filter($valeur, 'is_scalar')));
                }
            } elseif (\is_scalar($valeur)) {
                $saisies[(string) $cle] = (string) $valeur;
            }
        }

        return $saisies;
    }

    /** Nombre saisi (« 85 », « 85,5 ») ou null. */
    private static function nombre(mixed $valeur): ?float
    {
        $texte = str_replace([' ', ','], ['', '.'], trim(\is_scalar($valeur) ? (string) $valeur : ''));

        return is_numeric($texte) && (float) $texte > 0 ? (float) $texte : null;
    }

    /** « 22360006212 » → « +223 60 00 62 12 » */
    public static function telephone(string $numero): string
    {
        return 1 === preg_match('/^223(\d{8})$/', $numero, $m) ? '+223 '.implode(' ', str_split($m[1], 2)) : '+'.$numero;
    }

    private static function octets(int $octets): string
    {
        return $octets >= 1048576 ? str_replace('.', ',', (string) round($octets / 1048576, 1)).' Mo' : max(1, (int) round($octets / 1024)).' Ko';
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    private function jetonEntete(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** Le compte frais de la base, qui peut commander (pas « Lecture · suivi »). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }
        if (!$compte->peutEcrire()) {
            throw $this->createAccessDeniedException('Votre compte est en lecture seule : il ne passe pas de commande.');
        }

        return $compte;
    }
}
