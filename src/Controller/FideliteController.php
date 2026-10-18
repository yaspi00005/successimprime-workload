<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\JournalActivite;
use App\Entity\Paiements;
use App\Entity\Produits;
use App\Entity\PromoReveil;
use App\Entity\RegleFidelite;
use App\Entity\User;
use App\Security\RetourInterne;
use App\Service\Fidelite\CalculFidelite;
use App\Service\Fidelite\Fidelite;
use App\Service\Fidelite\ReveilClients;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fidélité côté boutique (docs/fidelite.md).
 *
 * - Direction (ROLE_ADMIN) : la fidélité par article, les clients
 *   dormeurs et leur promo de réveil, le journal ; sur la fiche client,
 *   les points ajoutés ou retirés à la main et les valeurs propres à un
 *   gros client.
 * - Qui gère les commandes (ROLE_COMMANDE) : utiliser les points d'un
 *   client sur sa commande, les retirer, appliquer sa promo de réveil.
 *
 * Tout se fait sur l'ERP de la boutique : le site en ligne reçoit le
 * relevé par la synchronisation et refuse ces actions.
 */
#[Route('/fidelite')]
final class FideliteController extends AbstractController
{
    private const ONGLETS = ['articles' => 'Par article', 'dormeurs' => 'Clients dormeurs', 'journal' => 'Journal'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fidelite $fidelite,
        private readonly ReveilClients $reveil,
    ) {
    }

    #[Route('', name: 'app_fidelite_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): Response
    {
        $onglet = $request->query->getString('onglet', 'articles');
        $onglet = isset(self::ONGLETS[$onglet]) ? $onglet : 'articles';
        $maintenant = new \DateTimeImmutable();
        $reglagesReveil = $this->reveil->reglages();
        $jours = max(1, Entree::entier($request->query, 'jours', $reglagesReveil['jours']));

        $donnees = [
            'onglet' => $onglet,
            'onglets' => self::ONGLETS,
            'reglages' => $this->fidelite->reglages(),
            'en_ligne' => $this->fidelite->estEnLigne(),
            'maintenant' => $maintenant,
        ];

        return $this->render('fidelite/index.html.twig', $donnees + match ($onglet) {
            'articles' => ['regles' => $this->em->getRepository(RegleFidelite::class)->createQueryBuilder('r')
                ->join('r.produit', 'p')->addSelect('p')
                ->orderBy('p.nom', 'ASC')->addOrderBy('r.debut', 'DESC')
                ->getQuery()->getResult()],
            'dormeurs' => [
                'jours' => $jours,
                'reveil' => $reglagesReveil,
                'dormeurs' => $this->reveil->dormants($jours, $maintenant),
            ],
            'journal' => ['journal' => $this->journal()],
        });
    }

    /* ---------- Fidélité par article ---------- */

    #[Route('/article/{id}', name: 'app_fidelite_regle_produit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regleDuProduit(Produits $produit): Response
    {
        $regles = $this->em->getRepository(RegleFidelite::class)->findBy(['produit' => $produit], ['debut' => 'DESC']);
        $jour = new \DateTimeImmutable('today');
        $regle = CalculFidelite::regleDuJour($regles, $jour) ?? ($regles[0] ?? null);

        return null === $regle
            ? $this->redirectToRoute('app_fidelite_regle_nouvelle', ['produit' => $produit->getId()])
            : $this->redirectToRoute('app_fidelite_regle_modifier', ['id' => $regle->getId()]);
    }

    #[Route('/regle/nouvelle', name: 'app_fidelite_regle_nouvelle', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nouvelleRegle(Request $request): Response
    {
        $regle = new RegleFidelite();
        $produit = Entree::entier($request->query, 'produit') > 0 ? $this->em->find(Produits::class, Entree::entier($request->query, 'produit')) : null;
        $regle->setProduit($produit)->setDebut(new \DateTimeImmutable('today'))->setValeurPoint($this->fidelite->reglages()->valeurPoint);
        if (null !== $produit && 'metre_carre' === $produit->getModeCalcul()) {
            $regle->setValeur($this->fidelite->reglages()->pointsParM2)->setSeuil($this->fidelite->reglages()->seuilM2);
        }

        return $this->formulaireRegle($request, $regle, true);
    }

    #[Route('/regle/{id}', name: 'app_fidelite_regle_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function modifierRegle(Request $request, RegleFidelite $regle): Response
    {
        return $this->formulaireRegle($request, $regle, false);
    }

    #[Route('/regle/{id}/supprimer', name: 'app_fidelite_regle_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supprimerRegle(Request $request, RegleFidelite $regle): Response
    {
        if ($this->isCsrfTokenValid('regle-fidelite-'.$regle->getId(), $request->request->getString('_token'))) {
            $this->journaliserRegle($regle, 'suppression', $regle->instantane(), null);
            $this->em->remove($regle);
            $this->em->flush();
            $this->addFlash('success', 'Règle supprimée : l\'article suit de nouveau les valeurs générales.');
        }

        return $this->redirectToRoute('app_fidelite_index', ['onglet' => 'articles']);
    }

    private function formulaireRegle(Request $request, RegleFidelite $regle, bool $nouvelle): Response
    {
        $erreurs = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('regle-fidelite', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $avant = $nouvelle ? null : $regle->instantane();
            $f = $request->request;
            $produit = Entree::entier($f, 'produit') > 0 ? $this->em->find(Produits::class, Entree::entier($f, 'produit')) : null;
            $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', $f->getString('debut')) ?: null;
            $fin = '' === $f->getString('fin') ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $f->getString('fin')) ?: false);
            $valeur = (float) str_replace([',', ' '], ['.', ''], $f->getString('valeur'));
            $seuil = (float) str_replace([',', ' '], ['.', ''], $f->getString('seuil'));

            if (null === $produit) {
                $erreurs[] = 'Choisissez l\'article.';
            }
            if (null === $debut) {
                $erreurs[] = 'Indiquez la date de début.';
            }
            if (false === $fin || (null !== $fin && null !== $debut && $fin < $debut)) {
                $erreurs[] = 'La date de fin doit suivre la date de début.';
            }
            if ($f->getBoolean('actif') && $valeur <= 0) {
                $erreurs[] = 'Indiquez les points gagnés (par unité ou en %).';
            }
            if (RegleFidelite::MODE_POURCENTAGE === $f->getString('mode') && $valeur > 100) {
                $erreurs[] = 'Le pourcentage va jusqu\'à 100 %.';
            }

            $regle->setProduit($produit)
                ->setActif($f->getBoolean('actif'))
                ->setMode($f->getString('mode'))
                ->setValeur($valeur)
                ->setValeurPoint(max(1, Entree::entier($f, 'valeur_point', 1)))
                ->setSeuil($seuil)
                ->setPortee($f->getString('portee'))
                ->setNote($f->getString('note'));
            if (null !== $debut) {
                $regle->setDebut($debut);
            }
            $regle->setFin($fin ?: null);

            if ([] === $erreurs) {
                $regle->marquerModifiee($this->agent(), new \DateTimeImmutable());
                if ($nouvelle) {
                    $this->em->persist($regle);
                    $this->em->flush();
                }
                $this->journaliserRegle($regle, $nouvelle ? 'creation' : 'modification', $avant, $regle->instantane());
                $this->em->flush();
                $this->addFlash('success', \sprintf('Fidélité de « %s » enregistrée.', $produit?->getNom()));

                return $this->redirectToRoute('app_fidelite_index', ['onglet' => 'articles']);
            }
        }

        $produits = $this->em->getRepository(Produits::class)->findBy([], ['nom' => 'ASC']);

        return $this->render('fidelite/regle.html.twig', [
            'regle' => $regle,
            'nouvelle' => $nouvelle,
            'erreurs' => $erreurs,
            'produits' => $produits,
            'reglages' => $this->fidelite->reglages(),
            'historique' => $nouvelle ? [] : $this->journal($regle),
        ]);
    }

    /* ---------- Fiche client (direction) ---------- */

    #[Route('/client/{publicId}/ajuster', name: 'app_fidelite_ajuster', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function ajuster(Request $request, #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client): Response
    {
        if ($this->isCsrfTokenValid('fidelite-client-'.$client->getId(), $request->request->getString('_token'))) {
            try {
                $points = (int) str_replace([' ', "\u{202f}", "\u{a0}"], '', $request->request->getString('points'));
                $this->fidelite->ajuster($client, $points, $request->request->getString('motif'), $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('%s %s point%s.', $points > 0 ? 'Ajout de' : 'Retrait de', number_format(abs($points), 0, ',', ' '), abs($points) > 1 ? 's' : ''));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->retourFiche($client);
    }

    #[Route('/client/{publicId}/valeurs', name: 'app_fidelite_personnaliser', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function personnaliser(Request $request, #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client): Response
    {
        if ($this->isCsrfTokenValid('fidelite-client-'.$client->getId(), $request->request->getString('_token'))) {
            $f = $request->request;
            $nombre = static function (string $v): ?float {
                $v = trim(str_replace([',', ' ', "\u{202f}", "\u{a0}"], ['.', '', '', ''], $v));

                return '' === $v ? null : max(0.0, (float) $v);
            };
            $motif = trim($f->getString('motif'));
            $valeurs = [
                'pointsParM2' => $f->getBoolean('general') ? null : $nombre($f->getString('points_m2')),
                'tauxAutre' => $f->getBoolean('general') ? null : $nombre($f->getString('taux_autre')),
                'seuilM2' => $f->getBoolean('general') ? null : $nombre($f->getString('seuil_m2')),
                'seuilMontant' => $f->getBoolean('general') || null === $nombre($f->getString('seuil_montant')) ? null : (int) $nombre($f->getString('seuil_montant')),
            ];
            if ($client->isB2B()) {
                $this->addFlash('error', 'Client au prix B2B : hors programme de fidélité.');
            } elseif (mb_strlen($motif) < 3) {
                $this->addFlash('error', 'Le motif est obligatoire.');
            } else {
                $this->fidelite->personnaliser($client, $valeurs, $motif, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', array_filter($valeurs, static fn ($v): bool => null !== $v) ? 'Valeurs propres au client enregistrées.' : 'Le client suit de nouveau les valeurs générales.');
            }
        }

        return $this->retourFiche($client);
    }

    /* ---------- Clients dormeurs ---------- */

    #[Route('/client/{publicId}/reveil', name: 'app_fidelite_reveil_offrir', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function offrirReveil(Request $request, #[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client): Response
    {
        if ($this->isCsrfTokenValid('fidelite-reveil', $request->request->getString('_token'))) {
            try {
                $duree = $request->request->getBoolean('aujourdhui') ? 0 : Entree::entier($request->request, 'duree', $this->reveil->reglages()['duree']);
                $taux = (float) str_replace(',', '.', $request->request->getString('taux'));
                $promo = $this->reveil->offrir($client, $taux, $duree, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('Promo de réveil offerte à %s : −%s %% %s.', $client->getNomComplet(), rtrim(rtrim(number_format($promo->getTaux(), 2, ',', ''), '0'), ','), $promo->estDuJourSeulement() ? 'aujourd\'hui seulement' : 'jusqu\'au '.$promo->getFin()->format('d/m')));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirect(RetourInterne::chemin($request->request->getString('retour')) ?? $this->generateUrl('app_fidelite_index', ['onglet' => 'dormeurs']));
    }

    #[Route('/reveil/{id}/retirer', name: 'app_fidelite_reveil_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function annulerReveil(Request $request, PromoReveil $promo): Response
    {
        if ($this->isCsrfTokenValid('fidelite-reveil', $request->request->getString('_token'))) {
            try {
                $this->reveil->annuler($promo, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', 'Promo de réveil retirée.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirect(RetourInterne::chemin($request->request->getString('retour')) ?? $this->generateUrl('app_fidelite_index', ['onglet' => 'dormeurs']));
    }

    /* ---------- Commande (qui gère les commandes) ---------- */

    #[Route('/commande/{id}/utiliser', name: 'app_fidelite_utiliser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function utiliser(Request $request, Commandes $commande): Response
    {
        if ($commande->estSoldeOuverture()) {
            throw $this->createAccessDeniedException('Ni points ni promotion sur un solde d\'ouverture.');
        }
        if ($this->isCsrfTokenValid('fidelite-commande-'.$commande->getId(), $request->request->getString('_token'))) {
            try {
                $points = (int) str_replace([' ', "\u{202f}", "\u{a0}"], '', $request->request->getString('points'));
                if ($points <= 0) {
                    throw new \DomainException('Indiquez le nombre de points à utiliser.');
                }
                $paiement = $this->fidelite->utiliser($commande, $points, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('Points utilisés : %s F retirés du reste à payer.', number_format($paiement->getMontant(), 0, ',', ' ')));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/commande/{id}/retirer/{paiement}', name: 'app_fidelite_retirer', requirements: ['id' => '\d+', 'paiement' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function retirer(Request $request, Commandes $commande, #[MapEntity(id: 'paiement')] Paiements $paiement): Response
    {
        if ($paiement->getCommande()?->getId() !== $commande->getId()) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('fidelite-commande-'.$commande->getId(), $request->request->getString('_token'))) {
            try {
                if ($this->fidelite->estEnLigne()) {
                    throw new \DomainException('Les points se gèrent sur l\'ERP de la boutique.');
                }
                $this->fidelite->retirer($paiement, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', 'Points retirés de la commande : ils sont rendus au client.');
            } catch (\DomainException|\LogicException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/commande/{id}/reveil', name: 'app_fidelite_reveil_appliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function appliquerReveil(Request $request, Commandes $commande): Response
    {
        if ($commande->estSoldeOuverture()) {
            throw $this->createAccessDeniedException('Ni points ni promotion sur un solde d\'ouverture.');
        }
        if ($this->isCsrfTokenValid('fidelite-commande-'.$commande->getId(), $request->request->getString('_token'))) {
            try {
                if ($this->fidelite->estEnLigne()) {
                    throw new \DomainException('La remise se fait sur l\'ERP de la boutique.');
                }
                $client = $commande->getClients() ?? throw new \DomainException('Commande sans client.');
                $promo = $this->reveil->active($client, new \DateTimeImmutable()) ?? throw new \DomainException('Ce client n\'a pas de promo de réveil en cours.');
                $montant = $this->reveil->appliquer($promo, $commande, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('Promo de réveil appliquée : %s F de remise.', number_format($montant, 0, ',', ' ')));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }

    /* ---------- Outils ---------- */

    private const ACTIONS = [
        'creation' => 'Règle d\'article créée',
        'modification' => 'Règle d\'article modifiée',
        'suppression' => 'Règle d\'article supprimée',
        'ajustement' => 'Points ajustés à la main',
        'valeurs_client' => 'Valeurs propres au client',
        'promo_reveil' => 'Promo de réveil offerte',
        'promo_reveil_retiree' => 'Promo de réveil retirée',
        'promo_reveil_utilisee' => 'Promo de réveil appliquée',
    ];

    private const CHAMPS = [
        'produit' => 'article', 'actif' => 'bonification', 'mode' => 'calcul', 'valeur' => 'points', 'valeurPoint' => 'valeur du point',
        'seuil' => 'plage', 'portee' => 'plage comptée', 'debut' => 'début', 'fin' => 'fin', 'note' => 'note',
        'pointsParM2' => 'points par m²', 'tauxAutre' => '% autres articles', 'seuilM2' => 'seuil m²', 'seuilMontant' => 'seuil F',
        'points' => 'points', 'motif' => 'motif', 'taux' => 'remise %', 'du' => 'du', 'au' => 'au', 'commande' => 'commande',
    ];

    /**
     * Le journal de la fidélité : qui, quand, quoi, avant → après.
     *
     * @return list<array{le: \DateTimeImmutable, qui: ?User, action: string, sujet: ?string, lien: ?string, details: list<string>}>
     */
    private function journal(?RegleFidelite $regle = null): array
    {
        $qb = $this->em->getRepository(JournalActivite::class)->createQueryBuilder('j')
            ->orderBy('j.id', 'DESC')->setMaxResults(150);
        if (null === $regle) {
            $qb->where('j.entite IN (:e)')->setParameter('e', [Fidelite::ENTITE_JOURNAL, 'RegleFidelite']);
        } else {
            $qb->where('j.entite = :e')->andWhere('j.entiteId = :id')->setParameter('e', 'RegleFidelite')->setParameter('id', $regle->getId());
        }
        $entrees = $qb->getQuery()->getResult();
        $ids = [];
        foreach ($entrees as $j) {
            if (Fidelite::ENTITE_JOURNAL === $j->getEntite()) {
                $ids[$j->getEntiteId()] = true;
            }
        }
        $clients = [];
        foreach ([] === $ids ? [] : $this->em->getRepository(Clients::class)->findBy(['id' => array_keys($ids)]) as $c) {
            $clients[$c->getId()] = $c;
        }
        $texte = static fn (mixed $v): string => match (true) {
            null === $v || '' === $v => '—',
            true === $v => 'active',
            false === $v => 'désactivée',
            \is_float($v) || \is_int($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','),
            \is_string($v) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) => (new \DateTimeImmutable($v))->format('d/m/Y'),
            default => (string) (RegleFidelite::MODES[$v] ?? RegleFidelite::PORTEES[$v] ?? $v),
        };

        $liste = [];
        foreach ($entrees as $j) {
            $avant = $j->getDonneesAvant() ?? [];
            $apres = $j->getDonneesApres() ?? [];
            $details = [];
            foreach (array_unique([...array_keys($avant), ...array_keys($apres)]) as $cle) {
                $a = $avant[$cle] ?? null;
                $b = $apres[$cle] ?? null;
                if ($a === $b || ([] !== $avant && [] !== $apres && $a == $b)) {
                    continue;
                }
                $libelle = self::CHAMPS[$cle] ?? $cle;
                $details[] = [] === $avant ? $libelle.' : '.$texte($b) : ([] === $apres ? $libelle.' : '.$texte($a) : $libelle.' : '.$texte($a).' → '.$texte($b));
            }
            $client = Fidelite::ENTITE_JOURNAL === $j->getEntite() ? ($clients[$j->getEntiteId()] ?? null) : null;
            $liste[] = [
                'le' => $j->getCreatedAt(),
                'qui' => $j->getUtilisateur(),
                'action' => self::ACTIONS[$j->getAction()] ?? $j->getAction(),
                'sujet' => $client?->getNomComplet() ?? ($apres['produit'] ?? $avant['produit'] ?? null),
                'lien' => null !== $client ? $this->generateUrl('app_clients_show', ['publicId' => $client->getPublicId()]).'#fidelite' : null,
                'details' => $details,
            ];
        }

        return $liste;
    }

    private function journaliserRegle(RegleFidelite $regle, string $action, ?array $avant, ?array $apres): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite('RegleFidelite')
            ->setEntiteId((int) $regle->getId())
            ->setAction($action)
            ->setDonneesAvant($avant)
            ->setDonneesApres($apres)
            ->setUtilisateur($this->agent()));
    }

    private function retourFiche(Clients $client): Response
    {
        return $this->redirect($this->generateUrl('app_clients_show', ['publicId' => $client->getPublicId()]).'#fidelite', Response::HTTP_SEE_OTHER);
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
