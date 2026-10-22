<?php

namespace App\Controller;

use App\Entity\DemandeDevisLigne;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\User;
use App\Service\Caisse;
use App\Service\Corbeille\Corbeille;
use App\Service\Devis\EnvoiDevis;
use App\Service\Devis\OptionsDevis;
use App\Service\Devis\ListeDevis;
use App\Service\Devis\PdfDevis;
use App\Service\Devis\RentabiliteDevis;
use App\Service\Devis\SuiviDevis;
use App\Service\MontantFinitionSaisie;
use App\Service\DevisCommandeConverter;
use App\Entity\DevisDetailFinition;
use App\Entity\ProduitConfigurationFinition;
use App\Form\DevisType;
use App\Repository\DevisRepository;
use App\Service\NotificationService;
use App\Service\Promotions\PromotionsSaisie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Form\FormInterface;

/**
 * Devis côté imprimerie (docs/devis.md) : la liste (maquette GC03), la
 * saisie (GC04), la fiche (DT03), l'envoi au client, les versions, les
 * relances WhatsApp et la transformation en commande. La rentabilité
 * (coût, marge) n'est calculée et montrée qu'avec le droit « Marges et
 * coûts » (ROLE_COUT_MARGE), jamais au client.
 */
#[Route('/devis', name: 'app_devis_')]
class DevisController extends AbstractController
{
    /** Paliers de prix, grille du client et prix négocié (docs/produits.md), posés à l'enregistrement. */
    public function __construct(private readonly \App\Service\Catalogue\TarifClient $tarifClient)
    {
    }

    /** Clé de session du lien wa.me à ouvrir après un envoi (bandeau de la fiche). */
    private const SESSION_WHATSAPP = 'devis_whatsapp_';

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, ListeDevis $liste, EnvoiDevis $envoi): Response
    {
        $maintenant = new \DateTimeImmutable();
        $texteMois = (string) $request->query->get('mois', '');
        $mois = preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $texteMois) ? new \DateTimeImmutable($texteMois.'-01') : $maintenant;
        $filtre = (string) $request->query->get('filtre', '');
        $page = $liste->page($mois, $filtre, (string) $request->query->get('q', ''), $maintenant);

        $suivis = [];
        $objets = [];
        foreach ($page['devis'] as $d) {
            $suivis[$d->getId()] = SuiviDevis::texteSuivi($d, $maintenant);
            // Sans objet tapé : les lignes en une phrase (« Kakémono × 3 · Pose »).
            $objets[$d->getId()] = $d->getObjet() ?: SuiviDevis::instantane($d, $maintenant)['resume'];
        }
        $aRelancer = array_map(static fn (Devis $d): int => (int) $d->getId(), $page['aRelancer']);

        return $this->render('devis/index.html.twig', $page + [
            'filtre' => isset(ListeDevis::FILTRES[$filtre]) ? $filtre : '',
            'filtres' => ListeDevis::FILTRES,
            'q' => trim((string) $request->query->get('q', '')),
            'suivis' => $suivis,
            'objets' => $objets,
            'idsARelancer' => $aRelancer,
            'moisPrecedent' => $page['mois']->modify('-1 month'),
            'moisSuivant' => $page['mois']->modify('+1 month') <= $maintenant ? $page['mois']->modify('+1 month') : null,
            'whatsAppAuto' => $envoi->whatsAppAutomatique(),
            'maintenant' => $maintenant,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        PromotionsSaisie $promotionsSaisie,
        EnvoiDevis $envoi,
    ): Response {
        $devi = new Devis();
        // Par défaut : l'espace client et WhatsApp (maquette GC04).
        $devi->setCanauxEnvoi([Devis::CANAL_ESPACE, Devis::CANAL_WHATSAPP])->setConditionSolde('à la livraison');

        // Depuis la liste ou la fiche client : client déjà choisi.
        if ($request->isMethod('GET') && (int) $request->query->get('client') > 0) {
            $devi->setClients($entityManager->find(\App\Entity\Clients::class, (int) $request->query->get('client')));
        }

        $form = $this->createForm(DevisType::class, $devi);

        /*
         * Restauration d'un brouillon gardé en session (voir
         * BrouillonSaisieController) : le formulaire est pré-rempli,
         * jamais enregistré ; l'agent soumet lui-même.
         */
        if ($request->isMethod('GET') && '1' === $request->query->get('restaurer')) {
            $brouillon = $request->getSession()->get('brouillon_devis');
            if (\is_array($brouillon) && !empty($brouillon['champs'])) {
                $form->submit($brouillon['champs'], false);
            }

            return $this->vueSaisie('devis/new.html.twig', $devi, $form);
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->preparer($devi, $form, $entityManager, $promotionsSaisie);
        }

        // Une erreur métier a pu être ajoutée ci-dessus.
        if ($form->isSubmitted() && $form->isValid()) {
            $maintenant = new \DateTimeImmutable();
            $utilisateur = $this->utilisateur();
            $devi->setDateDevis($maintenant)->setAgents($utilisateur)->setStatut(Devis::STATUT_BROUILLON);

            // Premier enregistrement pour obtenir l'identifiant du numéro.
            $entityManager->persist($devi);
            $entityManager->flush();
            $devi->setNumero(\sprintf('DEV-%06d-%s', $devi->getId(), $maintenant->format('m-Y')));
            if ('' === trim((string) $devi->getTokenAuthenticite())) {
                $devi->genererTokenAuthenticite();
            }

            $notificationService->notifierRoles(
                ['ROLE_ADMIN'],
                \sprintf('Nouveau devis %s créé par %s.', $devi->getNumero(), $utilisateur->getUsername()),
                'app_devis_show',
                ['id' => $devi->getId()],
                $utilisateur
            );
            $entityManager->flush();
            $request->getSession()->remove('brouillon_devis');

            return $this->apresEnregistrement($request, $devi, $form, $envoi);
        }

        return $this->vueSaisie('devis/new.html.twig', $devi, $form);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Devis $devi,
        Request $request,
        PromotionsSaisie $promotionsSaisie,
        RentabiliteDevis $rentabilite,
        EnvoiDevis $envoi,
        Caisse $caisse,
        DevisRepository $devisRepository,
    ): Response {
        if ($devi->isDeleted()) {
            throw $this->createNotFoundException();
        }
        $maintenant = new \DateTimeImmutable();
        $client = $devi->getClients();

        // Carte du client : « 5 devis · 4 acceptés », solde impayé.
        $devisClient = null !== $client ? $devisRepository->findBy(['clients' => $client, 'deleted' => false]) : [];
        $devisClient = array_filter($devisClient, static fn (Devis $d): bool => !\in_array($d->getStatut(), [Devis::STATUT_BROUILLON, Devis::STATUT_ANNULE], true));
        $impayees = null !== $client ? $caisse->commandesImpayees($client) : [];

        // Versions : la plus récente d'abord, avec ce qui a changé et la demande du client.
        $versions = [];
        $liste = $devi->getVersions();
        foreach ($liste as $i => $v) {
            $precedente = $liste[$i + 1] ?? null;
            $suivante = $liste[$i - 1] ?? null;
            $versions[] = $v + [
                'changements' => null !== $precedente ? SuiviDevis::resumeChangements($precedente, $v) : null,
                'demandeClient' => isset($suivante['commentaire']) ? mb_strimwidth((string) $suivante['commentaire'], 0, 70, '…') : null,
                'resume' => mb_strimwidth((string) ($v['resume'] ?? ''), 0, 70, '…'),
                'actuelle' => $v['numero'] === $devi->getVersion() && Devis::STATUT_BROUILLON !== $devi->getStatut(),
            ];
        }

        $session = $request->getSession();
        $lienWhatsApp = $session->get(self::SESSION_WHATSAPP.$devi->getId());
        $session->remove(self::SESSION_WHATSAPP.$devi->getId());

        return $this->render('devis/show.html.twig', [
            'devis' => $devi,
            'maintenant' => $maintenant,
            'evenements' => SuiviDevis::evenements($devi, $maintenant),
            'versions' => $versions,
            'rentabilite' => $this->isGranted('ROLE_COUT_MARGE') ? $rentabilite->pourDevis($devi) : null,
            'seuilMarge' => RentabiliteDevis::SEUIL_ALERTE,
            'nombreDevisClient' => \count($devisClient),
            'nombreAcceptesClient' => \count(array_filter($devisClient, static fn (Devis $d): bool => $d->estAccepte())),
            'impaye' => array_sum(array_column($impayees, 'reste')),
            'nombreImpayees' => \count($impayees),
            'lienWhatsApp' => \is_string($lienWhatsApp) ? $lienWhatsApp : null,
            'relanceDue' => SuiviDevis::relanceDue($devi, $maintenant),
            'relancePrevue' => SuiviDevis::relancePrevue($devi),
            'whatsAppAuto' => $envoi->whatsAppAutomatique(),
            'motifs' => Devis::MOTIFS_REFUS,
            // Revendeur au prix B2B : promotions qu'une exception permettrait, ligne par ligne.
            'promotionsB2b' => $promotionsSaisie->possibilitesB2b($devi, $maintenant),
        ]);
    }

    /**
     * Modifier : un brouillon se modifie tel quel ; un devis déjà envoyé
     * (sans réponse, refusé ou expiré) devient sa version suivante à
     * l'enregistrement. Un devis accepté ne se modifie plus : sa commande
     * existe ou va être créée.
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Devis $devi,
        EntityManagerInterface $entityManager,
        PromotionsSaisie $promotionsSaisie,
        EnvoiDevis $envoi,
    ): Response {
        if ($devi->isDeleted() || $devi->estAccepte() || Devis::STATUT_ANNULE === $devi->getStatut()) {
            $this->addFlash('warning', 'Ce devis est accepté : il ne se modifie plus. Modifiez la commande.');

            return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
        }
        if ([] === $devi->getCanauxEnvoi() && !$request->isMethod('POST')) {
            // Brouillon d'avant le suivi des devis : mêmes moyens d'envoi qu'un nouveau devis.
            $devi->setCanauxEnvoi([Devis::CANAL_ESPACE, Devis::CANAL_WHATSAPP]);
        }
        $form = $this->createForm(DevisType::class, $devi);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (Devis::STATUT_BROUILLON !== $devi->getStatut()) {
                SuiviDevis::nouvelleVersion($devi);
            }
            $this->preparer($devi, $form, $entityManager, $promotionsSaisie);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->flush();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $form->addError(new FormError('Une même finition ne peut pas être ajoutée deux fois au même détail.'));

                return $this->vueSaisie('devis/edit.html.twig', $devi, $form);
            }

            return $this->apresEnregistrement($request, $devi, $form, $envoi);
        }

        return $this->vueSaisie('devis/edit.html.twig', $devi, $form);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Devis $devi, EntityManagerInterface $entityManager, Corbeille $corbeille): Response
    {
        // Suppression douce (Devis::$deleted) : l'historique reste, la corbeille permet de revenir en arrière.
        if ($this->isCsrfTokenValid('delete'.$devi->getId(), $request->getPayload()->getString('_token'))) {
            // Corbeille (docs/corbeille.md) : récupérable pendant la durée réglée.
            $corbeille->masquer($devi, 'deleted', false);
            $devi->setDeleted(true);
            $entityManager->flush();

            $this->addFlash('success', \sprintf('Le devis %s a été supprimé. Il reste %s dans la corbeille.', $devi->getNumero(), $corbeille->dureeTexte()));
        }

        return $this->redirectToRoute('app_devis_index', [], Response::HTTP_SEE_OTHER);
    }

    /** « Envoyer au client » depuis la fiche d'un brouillon, par les canaux enregistrés. */
    #[Route('/{id}/envoyer', name: 'envoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function envoyer(Devis $devi, Request $request, EnvoiDevis $envoi, EntityManagerInterface $em): Response
    {
        $this->jeton($request, 'devis-'.$devi->getId());
        if (Devis::STATUT_BROUILLON !== $devi->getStatut() || $devi->getDevisDetails()->isEmpty()) {
            $this->addFlash('warning', 'Seul un brouillon avec au moins une ligne s\'envoie.');

            return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
        }
        // Ligne venue du panier de devis du site (« Autre projet », produit non chiffrable) restée à 0 F : la chiffrer avant d'envoyer.
        $aChiffrer = (int) $em->createQueryBuilder()->select('COUNT(l.id)')->from(DemandeDevisLigne::class, 'l')
            ->join('l.devisDetail', 'd')->where('d.devis = :devis')->andWhere('d.prixUnitaire <= 0')
            ->setParameter('devis', $devi)->getQuery()->getSingleScalarResult();
        if ($aChiffrer > 0) {
            $this->addFlash('warning', 'Une ligne venue de la demande du site est encore à 0 F : chiffrez-la (Modifier) avant d\'envoyer le devis au client.');

            return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
        }

        return $this->envoyerEtRevenir($request, $devi, $devi->getCanauxEnvoi(), null, $envoi);
    }

    /** « Nouvelle version » : le devis repasse en brouillon, version suivante, puis la saisie. */
    #[Route('/{id}/nouvelle-version', name: 'nouvelle_version', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function nouvelleVersion(Devis $devi, Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request, 'devis-'.$devi->getId());
        if (Devis::STATUT_BROUILLON === $devi->getStatut() || $devi->estAccepte() || Devis::STATUT_ANNULE === $devi->getStatut()) {
            return $this->redirectToRoute('app_devis_edit', ['id' => $devi->getId()]);
        }
        SuiviDevis::nouvelleVersion($devi);
        $em->flush();
        $this->addFlash('success', \sprintf('Version %d en préparation. Modifiez les lignes puis « Envoyer au client ».', $devi->getVersion()));

        return $this->redirectToRoute('app_devis_edit', ['id' => $devi->getId()]);
    }

    /**
     * « Relancer sur WhatsApp » : sans clé Meta, le lien wa.me avec le
     * message déjà écrit s'ouvre (le formulaire vise un nouvel onglet) ;
     * avec la clé, l'application envoie elle-même.
     */
    #[Route('/{id}/relancer', name: 'relancer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function relancer(Devis $devi, Request $request, EnvoiDevis $envoi): Response
    {
        $this->jeton($request, 'devis-'.$devi->getId());
        if (!$devi->estEnAttente()) {
            $this->addFlash('warning', 'Ce devis n\'attend plus de réponse : rien à relancer.');

            return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
        }
        $resultat = $envoi->relancer($devi, $this->utilisateur(), new \DateTimeImmutable());
        if (null !== $resultat['lien']) {
            return new RedirectResponse($resultat['lien']);
        }
        $this->addFlash(null === $resultat['note'] ? 'success' : 'warning', $resultat['note'] ?? \sprintf('Relance WhatsApp envoyée pour le devis %s.', $devi->getNumero()));

        return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
    }

    /**
     * Quantités au choix du client sur une ligne (maquette DB02) : 500,
     * 1 000 ou 2 000 flyers, chacune avec son prix unitaire. Sans changer le
     * prix du devis ; le client choisit dans son espace en acceptant.
     */
    #[Route('/{id}/options/{ligne}', name: 'options', requirements: ['id' => '\d+', 'ligne' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_DEVIS')]
    public function options(Devis $devi, int $ligne, Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request, 'devis-'.$devi->getId());
        $detail = null;
        foreach ($devi->getDevisDetails() as $l) {
            $detail = $l->getId() === $ligne ? $l : $detail;
        }
        if (null === $detail || !(Devis::STATUT_BROUILLON === $devi->getStatut() || $devi->estEnAttente())) {
            $this->addFlash('warning', 'Les quantités au choix se changent tant que le devis attend la réponse du client.');

            return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
        }
        $saisies = $request->request->all()['options'] ?? [];
        $detail->setOptionsQuantite(OptionsDevis::lire(\is_array($saisies) ? array_filter($saisies, 'is_array') : [], $detail->getQuantite()));
        $em->flush();
        $this->addFlash('success', [] === $detail->getOptionsQuantite() ? 'Plus de quantité au choix sur cette ligne.' : \sprintf('%d quantité(s) au choix enregistrée(s) : le client les voit dans son espace.', \count($detail->getOptionsQuantite())));

        return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
    }

    /** Refus dit à l'imprimerie (au téléphone, au comptoir) : noté avec son motif. */
    #[Route('/{id}/refuser', name: 'refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(Devis $devi, Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request, 'devis-'.$devi->getId());
        if ($devi->estEnAttente() || Devis::STATUT_EXPIRE === $devi->getStatut()) {
            $motif = $request->request->getString('motif');
            SuiviDevis::refuser($devi, isset(Devis::MOTIFS_REFUS[$motif]) ? $motif : 'autre', $request->request->getString('precision'), (SuiviDevis::nomPublic($this->utilisateur()) ?? '').' (noté à l\'imprimerie)', new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', \sprintf('Devis %s noté refusé : %s.', $devi->getNumero(), $devi->getMotifRefus()));
        }

        return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()]);
    }

    /**
     * Rentabilité pendant la saisie (carte « Interne » de GC04) : coût
     * estimé des lignes envoyées par la page, rien n'est enregistré.
     */
    #[Route('/rentabilite', name: 'rentabilite', methods: ['POST'])]
    #[IsGranted('ROLE_COUT_MARGE')]
    public function rentabilite(Request $request, RentabiliteDevis $rentabilite): JsonResponse
    {
        if (!$this->isCsrfTokenValid('devis-rentabilite', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erreur' => 'Jeton de sécurité invalide.'], 403);
        }
        $donnees = $request->toArray();

        return new JsonResponse($rentabilite->estimer(array_values(array_filter((array) ($donnees['lignes'] ?? []), 'is_array')), max(0, (int) ($donnees['remise'] ?? 0))));
    }

    private function convertirDimension(
        int|float|string|null $valeur
    ): ?float {
        if ($valeur === null) {
            return null;
        }

        $valeurNormalisee = str_replace(
            ',',
            '.',
            trim((string) $valeur)
        );

        if (
            $valeurNormalisee === ''
            || !is_numeric($valeurNormalisee)
        ) {
            return null;
        }

        return max(
            0.0,
            (float) $valeurNormalisee
        );
    }
    

    private function synchroniserDetailsEtFinitions(
    Devis $devi,
    FormInterface $form,
    EntityManagerInterface $entityManager
): void {
    $detailsForm =
        $form->get('devisDetails');

    $clientB2B =
        $devi->getClients()?->isB2B()
        ?? false;

    foreach ($detailsForm as $detailForm) {
        $detail =
            $detailForm->getData();

        if (
            !$detail instanceof DevisDetails
        ) {
            continue;
        }


        /*
         * ====================================================
         * ARTICLE EN STOCK
         * ====================================================
         */
        if (
            $detail->getTypeLigne()
            === DevisDetails::TYPE_ARTICLE
        ) {
            $article =
                $detail->getArticle();

            if ($article === null) {
                throw new \DomainException(
                    'Veuillez sélectionner un article en stock.'
                );
            }

            if (
                method_exists(
                    $article,
                    'isActif'
                )
                && !$article->isActif()
            ) {
                throw new \DomainException(
                    'Cet article est désactivé.'
                );
            }

            if (
                method_exists(
                    $article,
                    'isVendable'
                )
                && !$article->isVendable()
            ) {
                throw new \DomainException(
                    'Cet article n’est pas autorisé à la vente directe.'
                );
            }


            $detail->setProduit(null);
            $detail->setProduitConfiguration(null);
            $detail->setTypeImpression(null);
            $detail->setSupport(null);
            $detail->setFormat(null);


            /*
             * Désignation automatique.
             */
            $detail->setDesignation(
                $article->getDesignation()
            );


            /*
             * Prix automatique si aucun prix saisi.
             */
            if (
                $detail->getPrixUnitaire()
                <= 0
            ) {
                $detail->setPrixUnitaire(
                    (int) (
                        $article->getPrixVente()
                        ?? 0
                    )
                );
            }


            $detail->setModeCalcul(
                'unite'
            );


            /*
             * Aucune finition produit.
             */
            foreach (
                $detail->getFinitions()->toArray()
                as $finition
            ) {
                $detail->removeFinition(
                    $finition
                );

                if (
                    $finition->getId()
                    !== null
                ) {
                    $entityManager->remove(
                        $finition
                    );
                }
            }


            $detail->calculerTotaux(
                $clientB2B
            );

            continue;
        }


        /*
         * ====================================================
         * SAISIE LIBRE
         * ====================================================
         */
        if (
            $detail->getTypeLigne()
            === DevisDetails::TYPE_LIBRE
        ) {
            $detail->setArticle(null);

            $detail->setProduit(null);
            $detail->setProduitConfiguration(null);
            $detail->setTypeImpression(null);
            $detail->setSupport(null);
            $detail->setFormat(null);


            $this->synchroniserDetailLibre(
                $detail,
                $entityManager
            );


            $detail->calculerTotaux(
                $clientB2B
            );

            continue;
        }


        /*
         * ====================================================
         * PRODUIT
         * ====================================================
         */
        if (
            $detail->getTypeLigne()
            !== DevisDetails::TYPE_PRODUIT
        ) {
            throw new \DomainException(
                'Le type de ligne est invalide.'
            );
        }


        $detail->setArticle(null);


        if (
            $detail->isConfigurationAutomatique()
        ) {
            $this->synchroniserDetailAutomatique(
                $detail,
                $detailForm,
                $entityManager
            );

        } elseif (
            $detail->isConfigurationManuelle()
        ) {
            $this->synchroniserDetailManuel(
                $detail,
                $detailForm,
                $entityManager
            );

        } elseif (
            $detail->isSaisieLibre()
        ) {
            /*
             * Compatibilité temporaire ancien mode libre.
             */
            $this->synchroniserDetailLibre(
                $detail,
                $entityManager
            );

        } else {
            throw new \DomainException(
                'Le mode de saisie du travail est invalide.'
            );
        }


        $detail->calculerTotaux(
            $clientB2B
        );
    }
}
   private function synchroniserDetailAutomatique(
    DevisDetails $detail,
    FormInterface $detailForm,
    EntityManagerInterface $entityManager
): void {
    $configuration = $detail->getProduitConfiguration();

    if ($configuration === null) {
        throw new \DomainException(
            'Veuillez sélectionner une configuration de produit.'
        );
    }

    if (!$configuration->isActive()) {
        throw new \DomainException(
            'La configuration sélectionnée est inactive.'
        );
    }

    /*
     * Liste des configurations de finition sélectionnées.
     * La clé correspond à l'identifiant de la configuration.
     */
    $finitionsSelectionnees = [];

    if ($detailForm->has('finitionsSelectionnees')) {
        $valeurs = $detailForm
            ->get('finitionsSelectionnees')
            ->getData();

        if (is_iterable($valeurs)) {
            foreach ($valeurs as $configurationFinition) {
                if (
                    !$configurationFinition
                    instanceof ProduitConfigurationFinition
                ) {
                    continue;
                }

                if ($configurationFinition->getId() === null) {
                    continue;
                }

                $finitionsSelectionnees[
                    $configurationFinition->getId()
                ] = $configurationFinition;
            }
        }
    }

    /*
     * Ajout automatique des finitions obligatoires.
     */
    foreach (
        $configuration->getConfigurationFinitions()
        as $configurationFinition
    ) {
        if (
            !$configurationFinition->isActive()
            || !$configurationFinition->isObligatoire()
            || $configurationFinition->getId() === null
        ) {
            continue;
        }

        $finitionsSelectionnees[
            $configurationFinition->getId()
        ] = $configurationFinition;
    }

    /*
     * Indexation des finitions déjà enregistrées.
     * Elles seront réutilisées au lieu d'être supprimées puis recréées.
     */
    $finitionsExistantes = [];

    foreach ($detail->getFinitions()->toArray() as $finitionExistante) {
        $configurationExistante = $finitionExistante
            ->getConfigurationFinition();

        $configurationId = $configurationExistante?->getId();

        /*
         * Une finition automatique doit avoir une configuration.
         */
        if ($configurationId === null) {
            $detail->removeFinition($finitionExistante);

            if ($finitionExistante->getId() !== null) {
                $entityManager->remove($finitionExistante);
            }

            continue;
        }

        /*
         * Protection contre un éventuel doublon déjà présent
         * dans la collection PHP.
         */
        if (isset($finitionsExistantes[$configurationId])) {
            $detail->removeFinition($finitionExistante);

            if ($finitionExistante->getId() !== null) {
                $entityManager->remove($finitionExistante);
            }

            continue;
        }

        $finitionsExistantes[$configurationId] = $finitionExistante;
    }

    /*
     * Suppression uniquement des finitions qui ne sont plus sélectionnées.
     */
    foreach (
        $finitionsExistantes
        as $configurationId => $finitionExistante
    ) {
        if (isset($finitionsSelectionnees[$configurationId])) {
            continue;
        }

        $detail->removeFinition($finitionExistante);

        if ($finitionExistante->getId() !== null) {
            $entityManager->remove($finitionExistante);
        }

        unset($finitionsExistantes[$configurationId]);
    }

    $quantiteDetail = max(
        1,
        (int) ($detail->getQuantite() ?? 1)
    );

    /*
     * Mise à jour des finitions existantes ou création des nouvelles.
     */
    foreach (
        $finitionsSelectionnees
        as $configurationId => $configurationFinition
    ) {
        if (
            $configurationFinition->getProduitConfiguration()
            !== $configuration
        ) {
            throw new \DomainException(
                'Une finition sélectionnée ne correspond pas '
                . 'à la configuration du produit.'
            );
        }

        if (!$configurationFinition->isActive()) {
            continue;
        }

        $prix = max(
            0,
            (int) ($configurationFinition->getPrix() ?? 0)
        );

        $modeCalcul = (string) (
            $configurationFinition->getModeCalcul()
            ?: 'forfait'
        );

        $quantiteFinition = match ($modeCalcul) {
            'forfait' => 1,

            'unite',
            'exemplaire',
            'feuille',
            'face',
            'point' => $quantiteDetail,

            default => $quantiteDetail,
        };

        $montant = $modeCalcul === 'forfait'
            ? $prix
            : $prix * $quantiteFinition;

        /*
         * Réutilisation de l'entité existante pendant l'édition.
         */
        $finition = $finitionsExistantes[$configurationId]
            ?? new DevisDetailFinition();

        $finition->setConfigurationFinition(
            $configurationFinition
        );

        $finition->setFinition(
            $configurationFinition->getFinition()
        );

        $finition->setPrixApplique($prix);
        $finition->setModeCalcul($modeCalcul);
        $finition->setQuantite($quantiteFinition);
        $finition->setMontant($montant);

        /*
         * addFinition() n'est appelée que pour une nouvelle entité.
         */
        if (!$detail->getFinitions()->contains($finition)) {
            $detail->addFinition($finition);
        }
    }
}
    private function synchroniserDetailManuel(
        DevisDetails $detail,
        FormInterface $detailForm,
        EntityManagerInterface $entityManager
    ): void {
        foreach ($detail->getFinitions() as $finition) {
            if ($finition->getFinition() === null) {
                throw new \DomainException(
                    'Veuillez sélectionner la finition manuelle.'
                );
            }

            $prix = max(
                0,
                (int) ($finition->getPrixApplique() ?? 0)
            );

            $quantite = max(
                1,
                (int) ($finition->getQuantite() ?? 1)
            );

            $modeCalcul = $finition->getModeCalcul()
                ?: 'forfait';

            // Finition enregistrée et inchangée : son montant ne bouge pas (MontantFinitionSaisie).
            $montant = MontantFinitionSaisie::calculer(
                [
                    'finition' => $finition->getFinition()->getId(),
                    'prixApplique' => $prix,
                    'modeCalcul' => $modeCalcul,
                    'quantite' => $quantite,
                ],
                MontantFinitionSaisie::origine($finition, $entityManager)
            );

            $finition->setPrixApplique($prix);
            $finition->setQuantite($quantite);
            $finition->setModeCalcul($modeCalcul);
            $finition->setMontant($montant);
        }
    }

    private function synchroniserDetailLibre(
        DevisDetails $detail,
        EntityManagerInterface $entityManager
    ): void {
        if (trim((string) $detail->getDesignation()) === '') {
            throw new \DomainException(
                'La désignation de la saisie libre est obligatoire.'
            );
        }

        if ((int) $detail->getQuantite() < 1) {
            throw new \DomainException(
                'La quantité doit être supérieure à zéro.'
            );
        }

        if ((int) $detail->getPrixUnitaire() < 0) {
            throw new \DomainException(
                'Le prix unitaire ne peut pas être négatif.'
            );
        }

        /*
     * Une saisie libre ne dépend pas d’une configuration.
     */
        $detail->setProduitConfiguration(null);

        foreach ($detail->getFinitions()->toArray() as $finition) {
            $detail->removeFinition($finition);

            if ($finition->getId() !== null) {
                $entityManager->remove($finition);
            }
        }
    }
   

    /**
     * « Convertir en commande » : un devis accepté, ou dont la réponse
     * est venue par téléphone ou au comptoir (envoyé, vu), devient la
     * commande sans ressaisie.
     */
    #[Route('/{id}/convertir-commande', name: 'convertir_commande', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function convertirCommande(Devis $devis, Request $request, DevisCommandeConverter $converter, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('convertir-devis-'.$devis->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try {
            if ($devis->estEnAttente()) {
                // Acceptation dite à l'imprimerie : notée avec qui l'a enregistrée.
                SuiviDevis::accepter($devis, (SuiviDevis::nomPublic($this->utilisateur()) ?? '').' (noté à l\'imprimerie)', new \DateTimeImmutable());
                $em->flush();
            }
            $commande = $converter->convertir($devis);
            $this->addFlash('success', \sprintf('Le devis %s est devenu la commande %s.', $devis->getNumero(), $commande->getNumero() ?? '#'.$commande->getId()));

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()]);
        } catch (\LogicException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('app_devis_show', ['id' => $devis->getId()]);
    }

    #[Route('/{id}/pdf', name: 'pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(Devis $devis, EntityManagerInterface $entityManager, PdfDevis $pdf): Response
    {
        $tokenAvant = $devis->getTokenAuthenticite();
        $contenu = $pdf->contenu($devis);
        // Jeton d'authenticité (QR code) créé à l'instant : enregistré.
        if ($tokenAvant !== $devis->getTokenAuthenticite()) {
            $entityManager->flush();
        }

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="%s.pdf"', PdfDevis::nomFichier($devis)),
        ]);
    }

    /*
     * PDF public, sans connexion, avec le jeton d'authenticité du devis
     * (ancien lien partagé ; les nouveaux envois passent par /d/{jeton}).
     */
    #[Route('/{id}/pdf-public/{token}', name: 'pdf_public', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdfPublic(Devis $devis, string $token, EntityManagerInterface $entityManager, PdfDevis $pdf): Response
    {
        $tokenAttendu = (string) $devis->getTokenAuthenticite();
        if ('' === $tokenAttendu || !hash_equals($tokenAttendu, $token) || $devis->isDeleted()) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        return $this->pdf($devis, $entityManager, $pdf);
    }

    #[Route('/verifier/{token}', name: 'verifier', methods: ['GET'])]
    public function verifier(string $token, DevisRepository $devisRepository): Response
    {
        $devis = $devisRepository->findOneBy(['tokenAuthenticite' => $token]);
        if (!$devis instanceof Devis) {
            return $this->render('devis/verifier.html.twig', ['valide' => false, 'devis' => null, 'token' => $token], new Response('', Response::HTTP_NOT_FOUND));
        }

        return $this->render('devis/verifier.html.twig', ['valide' => true, 'devis' => $devis, 'token' => $token]);
    }

    /** Lignes, promotions et totaux recalculés ici : ceux du navigateur ne sont pas gardés. */
    private function preparer(Devis $devi, FormInterface $form, EntityManagerInterface $em, PromotionsSaisie $promotionsSaisie): void
    {
        try {
            $this->synchroniserDetailsEtFinitions($devi, $form, $em);
            // Palier, grille du client et prix négocié (docs/produits.md), avant la promotion qui s'y compare.
            $this->tarifClient->appliquerDevis($devi, new \DateTimeImmutable());
            // Promotions des lignes (docs/promotions.md).
            $promotionsSaisie->appliquerDevis($devi, $form->get('codePromo')->getData(), new \DateTimeImmutable());
            $devi->recalculerTotaux();
            if (!\in_array($devi->getValiditeJours(), Devis::VALIDITES, true)) {
                $devi->setValiditeJours(15);
            }
        } catch (\DomainException|\RuntimeException $exception) {
            $form->addError(new FormError($exception->getMessage()));
        }
    }

    /** Après « Enregistrer le brouillon », « Aperçu PDF » ou « Envoyer au client ». */
    private function apresEnregistrement(Request $request, Devis $devi, FormInterface $form, EnvoiDevis $envoi): Response
    {
        $action = $request->request->getString('action', 'brouillon');
        if ('envoyer' === $action) {
            if ($devi->getDevisDetails()->isEmpty()) {
                $this->addFlash('warning', 'Ajoutez au moins une ligne avant d\'envoyer le devis.');

                return $this->redirectToRoute('app_devis_edit', ['id' => $devi->getId()]);
            }
            $message = $form->has('messageClient') ? (string) $form->get('messageClient')->getData() : null;

            return $this->envoyerEtRevenir($request, $devi, $devi->getCanauxEnvoi(), $message, $envoi);
        }
        if ('apercu' === $action) {
            $request->getSession()->set('devis_apercu', $devi->getId());
            $this->addFlash('success', \sprintf('Brouillon %s enregistré.', $devi->getNumero()));

            return $this->redirectToRoute('app_devis_edit', ['id' => $devi->getId()]);
        }
        $this->addFlash('success', \sprintf('Brouillon %s enregistré%s.', $devi->getNumero(), $devi->getVersion() > 1 ? ' (version '.$devi->getVersion().')' : ''));

        return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()], Response::HTTP_SEE_OTHER);
    }

    /** @param list<string> $canaux */
    private function envoyerEtRevenir(Request $request, Devis $devi, array $canaux, ?string $message, EnvoiDevis $envoi): Response
    {
        $devi->setCanauxEnvoi($canaux);
        $resultat = $envoi->envoyer($devi, $devi->getCanauxEnvoi(), $message, $this->utilisateur(), new \DateTimeImmutable());
        foreach ($resultat['notes'] as $note) {
            $this->addFlash('warning', $note);
        }
        if (null !== $resultat['whatsapp']) {
            $request->getSession()->set(self::SESSION_WHATSAPP.$devi->getId(), $resultat['whatsapp']);
        }
        $canauxTexte = implode(' + ', array_map(static fn (string $c): string => Devis::CANAUX_LIBELLES[$c] ?? $c, $devi->getCanauxEnvoi()));
        $this->addFlash('success', \sprintf('Devis %s envoyé (%s). Valable jusqu\'au %s.', $devi->getNumero(), $canauxTexte, $devi->getDateValidite()?->format('d/m/Y')));

        return $this->redirectToRoute('app_devis_show', ['id' => $devi->getId()], Response::HTTP_SEE_OTHER);
    }

    private function vueSaisie(string $gabarit, Devis $devi, FormInterface $form): Response
    {
        $apercu = null;
        if (null !== $devi->getId() && $this->container->get('request_stack')->getSession()->get('devis_apercu') === $devi->getId()) {
            $this->container->get('request_stack')->getSession()->remove('devis_apercu');
            $apercu = $devi->getId();
        }

        return $this->render($gabarit, [
            'devi' => $devi,
            'form' => $form,
            'apercu' => $apercu,
            'seuilMarge' => RentabiliteDevis::SEUIL_ALERTE,
            'joursRelance' => SuiviDevis::JOURS_RELANCE,
        ], $form->isSubmitted() && !$form->isValid() ? new Response('', Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    private function jeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page.');
        }
    }

    private function utilisateur(): User
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté.');
        }

        return $utilisateur;
    }

}
