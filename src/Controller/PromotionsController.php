<?php

namespace App\Controller;

use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\CodePromo;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\JournalActivite;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\Http\Entree;
use App\Service\Promotions\CalculCodePromo;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\CodesPromo;
use App\Service\Promotions\Promotions;
use App\Service\Promotions\PromotionsSaisie;
use App\Service\Promotions\SuiviPromotions;
use App\Service\Statistiques\DroitsStatistiques;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Promotions par segment de clients (docs/promotions.md).
 *
 * - Droit Promotions (ROLE_PROMOTIONS, l'administrateur l'a) : créer,
 *   modifier, suspendre une promotion, accepter ou refuser les exceptions
 *   des revendeurs au prix B2B.
 * - Qui saisit les devis et commandes : voir les promotions en cours (sans
 *   le code ni la note), demander une exception sur une ligne.
 * - Le suivi ne montre un montant qu'avec le droit qui va avec
 *   (DroitsStatistiques) : CA et remises, marges.
 *
 * Les promotions se gèrent sur l'ERP de l'imprimerie : le site en ligne
 * refuse ces écritures.
 */
#[Route('/promotions')]
final class PromotionsController extends AbstractController
{
    private const ETATS = ['en_cours' => 'En cours', 'a_venir' => 'À venir', 'terminee' => 'Terminées', 'suspendue' => 'Suspendues'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Promotions $promotions,
        private readonly PromotionsSaisie $saisie,
        private readonly SuiviPromotions $suivi,
        private readonly CodesPromo $codes,
    ) {
    }

    #[Route('', name: 'app_promotions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->autoriserLecture();
        $jour = new \DateTimeImmutable('today');
        $etat = $request->query->getString('etat', 'en_cours');
        $etat = isset(self::ETATS[$etat]) ? $etat : 'en_cours';

        /** @var list<Promotion> $toutes */
        $toutes = $this->em->createQueryBuilder()->select('p', 'pr', 'cat')->from(Promotion::class, 'p')
            ->leftJoin('p.produits', 'pr')->leftJoin('p.categorie', 'cat')
            ->orderBy('p.debut', 'DESC')->addOrderBy('p.id', 'DESC')
            ->getQuery()->getResult();
        $parEtat = array_fill_keys(array_keys(self::ETATS), []);
        $anniversaires = 0;
        foreach ($toutes as $p) {
            // Une promotion d'anniversaire par client : elles sont sur la page Anniversaires.
            if ($p->estAnniversaire()) {
                $anniversaires += 'en_cours' === $p->etat($jour) ? 1 : 0;
                continue;
            }
            $parEtat[$p->etat($jour)][] = $p;
        }
        $gestion = $this->isGranted('ROLE_PROMOTIONS');
        $liste = $parEtat[$etat];
        if ('terminee' === $etat) {
            $liste = \array_slice($liste, 0, 100);
        }

        return $this->render('promotions/index.html.twig', [
            'etat' => $etat,
            'etats' => self::ETATS,
            'comptes' => array_map('count', $parEtat),
            'promotions' => $liste,
            'jour' => $jour,
            'gestion' => $gestion,
            // Remises accordées : un montant, seulement avec le droit de voir les ventes.
            'resumes' => $gestion ? $this->suivi->resumes(array_map(static fn (Promotion $p): int => (int) $p->getId(), $liste)) : [],
            'voir_montants' => $this->droits()['ventes'],
            'exceptions' => $gestion ? \count($this->saisie->demandesEnAttente()) : 0,
            'anniversaires' => $anniversaires,
            'en_ligne' => $this->promotions->estEnLigne(),
        ]);
    }

    #[Route('/nouvelle', name: 'app_promotions_nouvelle', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function nouvelle(Request $request): Response
    {
        $promotion = new Promotion();
        $promotion->setDebut(new \DateTimeImmutable('today'))->setFin(new \DateTimeImmutable('today +14 days'));

        return $this->formulaire($request, $promotion);
    }

    #[Route('/{id}/modifier', name: 'app_promotions_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function modifier(Request $request, Promotion $promotion): Response
    {
        return $this->formulaire($request, $promotion);
    }

    #[Route('/{id}', name: 'app_promotions_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Promotion $promotion): Response
    {
        $this->autoriserLecture();
        $droits = $this->droits();
        $maintenant = new \DateTimeImmutable();

        return $this->render('promotions/voir.html.twig', [
            'promotion' => $promotion,
            'jour' => $maintenant->setTime(0, 0),
            'gestion' => $this->isGranted('ROLE_PROMOTIONS'),
            'suivi' => $this->suivi->suivi($promotion, $droits, $maintenant),
            'droits' => $droits,
            'reglages' => $this->promotions->reglages(),
            'utilisee' => $this->promotions->nombreLignes($promotion) > 0,
            'historique' => $this->isGranted('ROLE_PROMOTIONS') ? $this->journal(Promotions::ENTITE_JOURNAL, (int) $promotion->getId()) : [],
            'en_ligne' => $this->promotions->estEnLigne(),
            // Les codes eux-mêmes : droit Promotions seulement.
            'nombre_codes' => $this->isGranted('ROLE_PROMOTIONS') ? \count($this->codes->codes($promotion)) : 0,
        ]);
    }

    /* ---------- Codes promo générés ---------- */

    /**
     * Codes de la promotion : un code partagé, ou un lot de codes uniques,
     * avec leurs utilisations (document et client).
     */
    #[Route('/{id}/codes', name: 'app_promotions_codes', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codes(Promotion $promotion): Response
    {
        $codes = $this->codes->codes($promotion);
        // La liste recompte : une commande supprimée ou annulée a pu rendre son utilisation.
        foreach ($codes as $c) {
            $this->codes->recompter($c);
        }
        if (!$this->promotions->estEnLigne()) {
            $this->em->flush();
        }

        return $this->render('promotions/codes.html.twig', [
            'promotion' => $promotion,
            'codes' => $codes,
            'lots' => $this->codes->lots($promotion),
            'documents' => $this->codes->documents($codes),
            'jour' => new \DateTimeImmutable('today'),
            'en_ligne' => $this->promotions->estEnLigne(),
            'voir_montants' => $this->droits()['ventes'],
            'lot_max' => CalculCodePromo::LOT_MAX,
        ]);
    }

    #[Route('/{id}/codes/partage', name: 'app_promotions_code_partage', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codePartage(Request $request, Promotion $promotion): Response
    {
        $this->verifierJetonCodes($request, $promotion);
        $f = $request->request;
        try {
            $code = $this->codes->creerPartage(
                $promotion,
                $f->getString('code'),
                (int) self::entier($f->getString('utilisations_max')),
                (int) (self::entier($f->getString('par_client')) ?? 1),
                self::date($f->getString('debut')),
                self::date($f->getString('expiration')),
                $this->agent(),
                new \DateTimeImmutable(),
            );
            $this->addFlash('success', \sprintf('Code %s créé.', $code->getCodeAffiche()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_promotions_codes', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/codes/lot', name: 'app_promotions_codes_lot', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codesLot(Request $request, Promotion $promotion): Response
    {
        $this->verifierJetonCodes($request, $promotion);
        $f = $request->request;
        try {
            $nombre = (int) self::entier($f->getString('nombre'));
            $lot = $this->codes->genererLot($promotion, $nombre, self::date($f->getString('debut')), self::date($f->getString('expiration')), $this->agent(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Lot %s : %d code%s unique%s généré%s.', $lot, $nombre, $nombre > 1 ? 's' : '', $nombre > 1 ? 's' : '', $nombre > 1 ? 's' : ''));

            return $this->redirectToRoute('app_promotions_codes', ['id' => $promotion->getId(), 'lot' => $lot, '_fragment' => 'lot-'.$lot], Response::HTTP_SEE_OTHER);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_promotions_codes', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/codes/{id}/basculer', name: 'app_promotions_code_basculer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codeBasculer(Request $request, CodePromo $code): Response
    {
        $promotion = $code->getPromotion();
        if (null === $promotion) {
            throw $this->createNotFoundException();
        }
        $this->verifierJetonCodes($request, $promotion);
        try {
            $this->codes->basculer($code, $this->agent(), new \DateTimeImmutable());
            $this->addFlash('success', $code->isActive()
                ? \sprintf('Code %s réactivé.', $code->getCodeAffiche())
                : \sprintf('Code %s désactivé : il ne sert plus ; les devis et commandes qui le portent le gardent.', $code->getCodeAffiche()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_promotions_codes', ['id' => $promotion->getId(), 'lot' => $code->getLot()], Response::HTTP_SEE_OTHER);
    }

    /** Les codes d'un lot (ou de toute la promotion), un par ligne, pour un tableur ou un envoi. */
    #[Route('/{id}/codes/export', name: 'app_promotions_codes_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codesExport(Request $request, Promotion $promotion): Response
    {
        $codes = $this->codesDuLot($promotion, $request->query->getString('lot'));
        $lignes = ['code'];
        foreach ($codes as $c) {
            $lignes[] = $c->getCodeAffiche();
        }
        $nom = \sprintf('codes-promo-%d%s.csv', $promotion->getId(), '' !== $request->query->getString('lot') ? '-'.strtolower(preg_replace('/[^A-Za-z0-9]/', '', $request->query->getString('lot')) ?? '') : '');

        return new Response(implode("\r\n", $lignes)."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nom.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Feuille à imprimer pour le comptoir : un coupon par code, à découper. */
    #[Route('/{id}/codes/feuille', name: 'app_promotions_codes_feuille', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function codesFeuille(Request $request, Promotion $promotion): Response
    {
        $codes = array_values(array_filter($this->codesDuLot($promotion, $request->query->getString('lot')), static fn (CodePromo $c): bool => $c->isActive()));

        $reponse = $this->render('promotions/codes_feuille.html.twig', [
            'promotion' => $promotion,
            'codes' => $codes,
            'lot' => $request->query->getString('lot'),
        ]);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /** @return list<CodePromo> */
    private function codesDuLot(Promotion $promotion, string $lot): array
    {
        return array_values(array_filter($this->codes->codes($promotion), static fn (CodePromo $c): bool => '' === $lot || $c->getLot() === $lot));
    }

    private function verifierJetonCodes(Request $request, Promotion $promotion): void
    {
        if (!$this->isCsrfTokenValid('codes-promo-'.$promotion->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    private static function entier(string $v): ?int
    {
        $v = preg_replace('/[^\d]/', '', $v) ?? '';

        return '' === $v ? null : (int) $v;
    }

    private static function date(string $v): ?\DateTimeImmutable
    {
        return '' === $v ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null);
    }

    #[Route('/{id}/suspendre', name: 'app_promotions_suspendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function suspendre(Request $request, Promotion $promotion): Response
    {
        if ($this->isCsrfTokenValid('promotion-'.$promotion->getId(), $request->request->getString('_token'))) {
            try {
                $avant = $promotion->instantane();
                $promotion->setActive(!$promotion->isActive());
                $this->promotions->enregistrer($promotion, $avant, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', $promotion->isActive()
                    ? 'Promotion reprise : elle s\'applique de nouveau à la saisie.'
                    : 'Promotion suspendue : elle ne s\'applique plus ; les lignes qui la portent la gardent.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_promotions_voir', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/supprimer', name: 'app_promotions_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function supprimer(Request $request, Promotion $promotion): Response
    {
        if (!$this->isCsrfTokenValid('promotion-'.$promotion->getId(), $request->request->getString('_token'))) {
            return $this->redirectToRoute('app_promotions_voir', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
        }
        try {
            $this->promotions->surLaBoutique();
            if ($this->promotions->nombreLignes($promotion) > 0) {
                throw new \DomainException('Cette promotion est déjà sur des devis ou des commandes : suspendez-la plutôt, elle reste dans l\'historique.');
            }
            $id = (int) $promotion->getId();
            $avant = $promotion->instantane();
            $this->em->remove($promotion);
            $this->promotions->journaliser(Promotions::ENTITE_JOURNAL, $id, JournalActivite::ACTION_SUPPRESSION, $avant, null, $this->agent());
            $this->em->flush();
            $this->addFlash('success', 'Promotion supprimée.');

            return $this->redirectToRoute('app_promotions_index', [], Response::HTTP_SEE_OTHER);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_promotions_voir', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
        }
    }

    /* ---------- Exceptions B2B ---------- */

    #[Route('/exceptions', name: 'app_promotions_exceptions', methods: ['GET'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function exceptions(): Response
    {
        return $this->render('promotions/exceptions.html.twig', [
            'attente' => $this->saisie->demandesEnAttente(),
            'traitees' => $this->saisie->demandesTraitees(),
            'en_ligne' => $this->promotions->estEnLigne(),
        ]);
    }

    #[Route('/exceptions/{id}/decider', name: 'app_promotions_exception_decider', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PROMOTIONS')]
    public function decider(Request $request, DemandeExceptionPromotion $demande): Response
    {
        if ($this->isCsrfTokenValid('exception-promotion', $request->request->getString('_token'))) {
            try {
                $accepter = 'accepter' === $request->request->getString('decision');
                $this->saisie->deciderException($demande, $accepter, $request->request->getString('reponse'), $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', $accepter
                    ? \sprintf('Exception acceptée : la ligne de %s prend la promotion.', $demande->getDocument())
                    : 'Exception refusée : la ligne reste au prix B2B.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_promotions_exceptions', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * L'agent demande l'exception depuis la fiche du devis ou de la commande.
     */
    #[Route('/exceptions/demander', name: 'app_promotions_exception_demander', methods: ['POST'])]
    public function demander(Request $request): Response
    {
        $type = $request->request->getString('type');
        $ligne = 'devis' === $type
            ? $this->em->find(DevisDetails::class, Entree::entier($request->request, 'ligne'))
            : $this->em->find(CommandesDetails::class, Entree::entier($request->request, 'ligne'));
        if (null === $ligne) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($ligne instanceof DevisDetails ? 'ROLE_DEVIS' : 'ROLE_COMMANDE');
        $document = $ligne instanceof DevisDetails ? $ligne->getDevis() : $ligne->getCommande();
        $promotion = $this->em->find(Promotion::class, Entree::entier($request->request, 'promotion'));

        if ($this->isCsrfTokenValid('exception-demande-'.$ligne->getId(), $request->request->getString('_token'))) {
            try {
                if (null === $promotion) {
                    throw new \DomainException('Promotion introuvable.');
                }
                $this->saisie->demanderException($ligne, $promotion, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', 'Exception demandée : la ligne reste au prix B2B tant qu\'un responsable n\'a pas accepté.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $document instanceof Devis
            ? $this->redirectToRoute('app_devis_show', ['id' => $document->getId()], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_commandes_show', ['id' => $document?->getId()], Response::HTTP_SEE_OTHER);
    }

    /* ---------- Saisie d'un devis ou d'une commande ---------- */

    /**
     * Aperçu des promotions des lignes en cours de saisie. Rien n'est
     * écrit : l'enregistrement recalcule tout.
     */
    #[Route('/saisie/apercu', name: 'app_promotions_apercu', methods: ['POST'])]
    public function apercu(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_COMMANDE') && !$this->isGranted('ROLE_DEVIS')) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('promotions-saisie', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erreur' => 'La page a expiré.'], Response::HTTP_FORBIDDEN);
        }
        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees)) {
            return new JsonResponse(['erreur' => 'Requête illisible.'], Response::HTTP_BAD_REQUEST);
        }
        $client = (int) ($donnees['client'] ?? 0) > 0 ? $this->em->find(Clients::class, (int) $donnees['client']) : null;
        $document = match ($donnees['document']['type'] ?? null) {
            'commande' => (int) ($donnees['document']['id'] ?? 0) > 0 ? $this->em->find(Commandes::class, (int) $donnees['document']['id']) : null,
            'devis' => (int) ($donnees['document']['id'] ?? 0) > 0 ? $this->em->find(Devis::class, (int) $donnees['document']['id']) : null,
            default => null,
        };
        $lignes = [];
        foreach (\array_slice(\is_array($donnees['lignes'] ?? null) ? $donnees['lignes'] : [], 0, 100) as $l) {
            if (!\is_array($l) || !isset($l['cle'])) {
                continue;
            }
            $lignes[] = [
                'cle' => mb_substr((string) $l['cle'], 0, 40),
                'id' => (int) ($l['id'] ?? 0) > 0 ? (int) $l['id'] : null,
                'produit' => (int) ($l['produit'] ?? 0) > 0 ? (int) $l['produit'] : null,
                'prix' => (float) ($l['prix'] ?? 0),
                'remise' => (int) round((float) ($l['remise'] ?? 0)),
                'quantite' => (int) ($l['quantite'] ?? 1),
                'brut' => (int) round((float) ($l['brut'] ?? 0)),
                'unites' => max(0.0, (float) ($l['unites'] ?? 0)),
                'tva' => max(0, min(100, (int) ($l['tva'] ?? 0))),
            ];
        }
        $resultat = $this->saisie->apercu($client, $document, $lignes, isset($donnees['code']) ? mb_substr((string) $donnees['code'], 0, 40) : null, new \DateTimeImmutable());

        return new JsonResponse($resultat + ['b2b' => null !== $client && $client->isB2B()]);
    }

    /* ---------- Formulaire ---------- */

    private function formulaire(Request $request, Promotion $promotion): Response
    {
        $nouvelle = null === $promotion->getId();
        $erreurs = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('promotion-formulaire', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $avant = $nouvelle ? null : $promotion->instantane();
            $f = $request->request;
            $date = static fn (string $v): ?\DateTimeImmutable => \DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null;
            $entier = static function (string $v): ?int {
                $v = preg_replace('/[^\d]/', '', $v) ?? '';

                return '' === $v ? null : (int) $v;
            };
            $debut = $date($f->getString('debut'));
            $fin = $date($f->getString('fin'));
            if (null === $debut || null === $fin) {
                $erreurs[] = 'Indiquez le début et la fin.';
            }
            $produits = $this->em->getRepository(Produits::class)->findBy(['id' => array_map('intval', $f->all('produits'))]);
            // « — » envoie une chaîne vide, que getInt() refuse (400).
            $idCategorie = (int) $f->getString('categorie');
            $categorie = $idCategorie > 0 ? $this->em->find(CategorieProduit::class, $idCategorie) : null;
            $clients = Promotion::SEGMENT_LISTE === $f->getString('segment')
                ? $this->em->getRepository(Clients::class)->findBy(['id' => array_map('intval', $f->all('clients'))])
                : [];

            $promotion->setNom($f->getString('nom'))
                ->remplacerProduits($produits)
                ->setCategorie($categorie)
                ->setAvantage($f->getString('avantage'))
                ->setValeur((float) str_replace([',', ' ', "\u{202f}", "\u{a0}"], ['.', '', '', ''], $f->getString('valeur')))
                ->setSegment($f->getString('segment'))
                ->remplacerClients($clients)
                ->setQuantiteMin($entier($f->getString('quantite_min')))
                ->setMontantMin($entier($f->getString('montant_min')))
                ->setCode($f->getString('code'))
                ->setUtilisationsParClient($entier($f->getString('utilisations')))
                ->setAvantageCommande($f->getString('avantage_commande'))
                ->setValeurCommande((float) str_replace([',', ' ', "\u{202f}", "\u{a0}"], ['.', '', '', ''], $f->getString('valeur_commande')))
                ->setPlafondCommande($entier($f->getString('plafond_commande')))
                ->setMinimumCommande($entier($f->getString('minimum_commande')))
                // Des codes déjà générés la gardent réservée aux codes.
                ->setACodes($f->getBoolean('a_codes') || (!$nouvelle && [] !== $this->codes->codes($promotion)))
                ->setNote($f->getString('note'));
            if (null === $promotion->getAvantageCommande()) {
                $promotion->setValeurCommande(0)->setPlafondCommande(null)->setMinimumCommande(null);
            }
            if (null !== $debut) {
                $promotion->setDebut($debut);
            }
            if (null !== $fin) {
                $promotion->setFin($fin);
            }

            $erreurs = array_merge($erreurs, $this->promotions->erreurs($promotion, new \DateTimeImmutable('today')));
            if ([] === $erreurs) {
                try {
                    $this->promotions->enregistrer($promotion, $avant, $this->agent(), new \DateTimeImmutable());
                    $this->addFlash('success', \sprintf('Promotion « %s » enregistrée.', $promotion->getNom()));

                    return $this->redirectToRoute('app_promotions_voir', ['id' => $promotion->getId()], Response::HTTP_SEE_OTHER);
                } catch (\DomainException $e) {
                    $erreurs[] = $e->getMessage();
                }
            }
            // En cas d'erreur, rien n'est enregistré : la page revient avec la saisie.
        }

        return $this->render('promotions/formulaire.html.twig', [
            'promotion' => $promotion,
            'nouvelle' => $nouvelle,
            'erreurs' => $erreurs,
            'produits' => $this->em->getRepository(Produits::class)->findBy([], ['nom' => 'ASC']),
            'categories' => $this->em->getRepository(CategorieProduit::class)->findBy([], ['nom' => 'ASC']),
            'clients' => $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
                ->where('c.statut = true OR c.statut IS NULL')->orderBy('c.nom', 'ASC')->addOrderBy('c.prenom', 'ASC')
                ->getQuery()->getResult(),
            'utilisee' => !$nouvelle && $this->promotions->nombreLignes($promotion) > 0,
            'codes_generes' => !$nouvelle && [] !== $this->codes->codes($promotion),
            'pourcentage_max' => CalculCodePromo::POURCENTAGE_MAX,
        ]);
    }

    /**
     * @return list<array{le: \DateTimeImmutable, action: string, qui: ?User, details: list<string>}>
     */
    private function journal(string $entite, int $id): array
    {
        $lignes = $this->em->getRepository(JournalActivite::class)->findBy(['entite' => $entite, 'entiteId' => $id], ['createdAt' => 'DESC', 'id' => 'DESC'], 20);
        $libelles = [
            JournalActivite::ACTION_CREATION => 'Création', JournalActivite::ACTION_MODIFICATION => 'Modification', JournalActivite::ACTION_SUPPRESSION => 'Suppression',
            'code_cree' => 'Code partagé créé', 'codes_generes' => 'Lot de codes généré', 'code_desactive' => 'Code désactivé', 'code_reactive' => 'Code réactivé',
        ];

        return array_map(static function (JournalActivite $j) use ($libelles): array {
            $avant = $j->getDonneesAvant() ?? [];
            $details = [];
            foreach ($j->getDonneesApres() ?? [] as $cle => $valeur) {
                if (\array_key_exists($cle, $avant) && $avant[$cle] !== $valeur) {
                    $details[] = $cle.' : '.self::texte($avant[$cle]).' → '.self::texte($valeur);
                }
            }
            // Codes : ce qui a été créé (jamais les codes d'un lot).
            if (\in_array($j->getAction(), ['code_cree', 'codes_generes', 'code_desactive', 'code_reactive'], true)) {
                $apres = $j->getDonneesApres() ?? [];
                $details = array_values(array_filter([
                    isset($apres['code']) ? 'code '.$apres['code'] : null,
                    isset($apres['lot']) ? 'lot '.$apres['lot'] : null,
                    isset($apres['nombre']) ? $apres['nombre'].' codes' : null,
                    isset($apres['au']) ? 'jusqu\'au '.(new \DateTimeImmutable((string) $apres['au']))->format('d/m/Y') : null,
                ]));
            }

            return ['le' => $j->getCreatedAt(), 'action' => $libelles[$j->getAction()] ?? $j->getAction(), 'qui' => $j->getUtilisateur(), 'details' => $details];
        }, $lignes);
    }

    private static function texte(mixed $v): string
    {
        return match (true) {
            null === $v => '—',
            true === $v => 'oui',
            false === $v => 'non',
            default => (string) $v,
        };
    }

    /** Voir les promotions : qui les gère, ou qui saisit devis et commandes. */
    private function autoriserLecture(): void
    {
        if (!$this->isGranted('ROLE_PROMOTIONS') && !$this->isGranted('ROLE_COMMANDE') && !$this->isGranted('ROLE_DEVIS')) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return array{global: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, perso: bool, admin: bool} */
    private function droits(): array
    {
        return DroitsStatistiques::calculer(fn (string $role): bool => $this->isGranted($role));
    }

    private function agent(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }
}
