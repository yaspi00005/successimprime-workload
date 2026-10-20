<?php

namespace App\Controller;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\Articles;
use App\Entity\Fournisseurs;
use App\Entity\User;
use App\Repository\ArticlesRepository;
use App\Repository\FournisseursRepository;
use App\Service\Achats\CalculsAchat;
use App\Service\Achats\SuiviAchats;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats › Commandes d'achat (maquette SU03, docs/achats.md) : la
 * commande se prépare en brouillon, part directement sous le seuil de
 * validation ou attend la direction au-dessus, puis est en route et
 * reçue. Réservé à la direction (access_control, /achats).
 */
#[Route('/achats')]
final class AchatsController extends AbstractController
{
    /*
     * Qui valide une commande au-dessus du seuil : la direction.
     */
    public const ROLE_VALIDATION = 'ROLE_ADMIN';

    /*
     * Onglets de la liste des commandes d'achat.
     */
    public const FILTRES = [
        'en_cours' => ['libelle' => 'En cours', 'statuts' => [Achats::STATUT_BROUILLON, Achats::STATUT_A_VALIDER, Achats::STATUT_COMMANDEE, Achats::STATUT_EN_ROUTE, Achats::STATUT_DEMANDE]],
        'a_valider' => ['libelle' => 'À valider', 'statuts' => [Achats::STATUT_A_VALIDER]],
        'recu' => ['libelle' => 'Reçues', 'statuts' => [Achats::STATUT_RECU]],
        'annule' => ['libelle' => 'Annulées', 'statuts' => [Achats::STATUT_ANNULE]],
        'toutes' => ['libelle' => 'Toutes', 'statuts' => Achats::STATUTS],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuiviAchats $suivi,
    ) {
    }

    #[Route('', name: 'app_achat_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtre = (string) $request->query->get('statut', 'en_cours');
        $filtre = isset(self::FILTRES[$filtre]) ? $filtre : 'en_cours';
        $aujourdhui = new \DateTimeImmutable('today');

        $achats = $this->em->createQueryBuilder()
            ->select('a', 'f')
            ->from(Achats::class, 'a')
            ->join('a.fournisseur', 'f')
            ->where('a.statut IN (:statuts)')
            ->setParameter('statuts', self::FILTRES[$filtre]['statuts'])
            ->orderBy('a.dateDemande', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        $nombres = [];
        foreach ($this->em->createQueryBuilder()->select('a.statut, COUNT(a.id) AS n')->from(Achats::class, 'a')->groupBy('a.statut')->getQuery()->getArrayResult() as $ligne) {
            $nombres[$ligne['statut']] = (int) $ligne['n'];
        }

        $lignes = [];
        foreach ($achats as $achat) {
            $lignes[] = ['achat' => $achat, 'prevue' => $achat->estEnCours() ? $this->suivi->livraisonPrevue($achat, $aujourdhui) : null];
        }

        return $this->render('achats/index.html.twig', [
            'lignes' => $lignes,
            'filtre' => $filtre,
            'filtres' => array_map(static fn (array $f): array => $f + ['nombre' => array_sum(array_intersect_key($nombres, array_flip($f['statuts'])))], self::FILTRES),
            'aujourdhui' => $aujourdhui,
        ]);
    }

    /**
     * Nouvelle commande : pour un fournisseur (?fournisseur=ID) ou depuis
     * l'alerte d'un article (?article=ID), qui préremplit la ligne et
     * propose d'ajouter les autres articles du même fournisseur à
     * commander bientôt, pour partager le transport.
     */
    #[Route('/new', name: 'app_achat_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        FournisseursRepository $fournisseurs,
        ArticlesRepository $articles
    ): Response {
        $aujourdhui = new \DateTimeImmutable('today');
        $achat = new Achats();
        $alerte = null;
        $article = $articles->find(Entree::entier($request->query, 'article'));

        // Le fournisseur choisi, sinon celui habituel de l'article en alerte.
        $fournisseur = $fournisseurs->find(Entree::entier($request->query, 'fournisseur'));
        if (!$fournisseur instanceof Fournisseurs && $article instanceof Articles) {
            [$fournisseur] = SuiviAchats::fournisseurDe($article);
        }
        $achat->setFournisseur($fournisseur instanceof Fournisseurs ? $fournisseur : null);
        if ($article instanceof Articles) {
            $achat->setOrigineAlerte($article);
        }

        if ($achat->getFournisseur() === null) {
            return $this->render('achats/choisir_fournisseur.html.twig', [
                'fournisseurs' => $fournisseurs->findBy(['actif' => true], ['nom' => 'ASC']),
                'article' => $article,
            ]);
        }

        $achat->setAcomptePourcentage($achat->getFournisseur()->getAcomptePourcentage());

        if ($request->isMethod('POST')) {
            return $this->enregistrer($request, $achat, true);
        }

        $reappro = $this->suivi->reappro($aujourdhui);

        if ($article instanceof Articles) {
            $alerte = $this->preremplirDepuisAlerte($achat, $article, $reappro, $aujourdhui);
        }

        $this->preremplirFrais($achat);

        // Économie d'un seul envoi : le transport qu'aurait coûté un second envoi.
        if ($alerte !== null && $alerte['ajoutes'] !== []) {
            $ajoutes = array_map(static fn (array $l): Articles => $l['article'], $alerte['ajoutes']);
            $part = 0;
            foreach (SuiviAchats::detailCommande($achat)['lignes'] as $l) {
                $part += \in_array($l['article'], $ajoutes, true) ? $l['partTransport'] : 0;
            }
            $alerte['economie'] = CalculsAchat::economieRegroupement($this->suivi->statistiquesDe($achat->getFournisseur(), $aujourdhui)['transports'], $part);
        }

        return $this->afficher($achat, true, $alerte);
    }

    #[Route('/{id}', name: 'app_achat_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Achats $achat): Response
    {
        return $this->afficher($achat, false);
    }

    #[Route('/{id}/edit', name: 'app_achat_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Achats $achat): Response
    {
        if (!$achat->estEnDemande()) {
            $this->addFlash('error', 'Seule une commande en brouillon peut être modifiée.');

            return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
        }

        if ($request->isMethod('POST')) {
            return $this->enregistrer($request, $achat, false);
        }

        return $this->afficher($achat, true);
    }

    /**
     * La direction valide une commande au-dessus du seuil : elle part chez
     * le fournisseur.
     */
    #[Route('/{id}/valider', name: 'app_achat_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'valider_achat'.$achat->getId());
        $this->denyAccessUnlessGranted(self::ROLE_VALIDATION);

        if (!$achat->estAValider()) {
            $this->addFlash('error', 'Cette commande n’attend pas de validation.');

            return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
        }

        $utilisateur = $this->getUser();
        $achat->setValidePar($utilisateur instanceof User ? $utilisateur : null);
        $achat->setValideLe(new \DateTimeImmutable());
        $this->passerCommande($achat);
        $this->em->flush();

        $this->addFlash('success', sprintf('La commande %s est validée et part chez %s.', $achat->getNumero(), $achat->getFournisseur()?->getNomCourt()));

        return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Renvoyée en brouillon par la direction, pour être corrigée.
     */
    #[Route('/{id}/refuser', name: 'app_achat_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'refuser_achat'.$achat->getId());
        $this->denyAccessUnlessGranted(self::ROLE_VALIDATION);

        if ($achat->estAValider()) {
            $achat->setStatut(Achats::STATUT_BROUILLON);
            $this->em->flush();
            $this->addFlash('success', 'La commande est revenue en brouillon.');
        }

        return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Le fournisseur a expédié : la commande est en route.
     */
    #[Route('/{id}/en-route', name: 'app_achat_en_route', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enRoute(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'en_route_achat'.$achat->getId());

        if (\in_array($achat->getStatut(), [Achats::STATUT_COMMANDEE, Achats::STATUT_DEMANDE], true)) {
            $achat->setStatut(Achats::STATUT_EN_ROUTE);
            $this->em->flush();
            $this->addFlash('success', sprintf('La commande %s est en route.', $achat->getNumero()));
        }

        return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/annuler', name: 'app_achat_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'annuler_achat'.$achat->getId());

        if ($achat->estRecu() || $achat->estAnnule()) {
            $this->addFlash('error', 'Une commande reçue ou déjà annulée ne peut pas être annulée.');

            return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
        }

        $achat->setStatut(Achats::STATUT_ANNULE);
        $this->em->flush();

        $this->addFlash('success', 'La commande d’achat a été annulée.');

        return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'app_achat_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Achats $achat): Response
    {
        if ($this->isCsrfTokenValid('delete'.$achat->getId(), $request->getPayload()->getString('_token'))) {
            if (!$achat->estEnDemande()) {
                $this->addFlash('error', 'Seule une commande en brouillon peut être supprimée.');

                return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
            }

            $this->em->remove($achat);
            $this->em->flush();

            $this->addFlash('success', 'La commande d’achat a été supprimée.');
        }

        return $this->redirectToRoute('app_achat_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Écran de la commande (maquette SU03), modifiable ou non.
     *
     * @param array<string, mixed>|null $alerte
     */
    private function afficher(Achats $achat, bool $modifiable, ?array $alerte = null): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $fournisseur = $achat->getFournisseur();
        $stats = $fournisseur !== null ? $this->suivi->statistiquesDe($fournisseur, $aujourdhui) : null;
        $seuil = $this->suivi->seuilValidation();

        // Promis et prévu : la date enregistrée, sinon celle d'un envoi aujourd'hui.
        $promise = $achat->getDatePromise() ?? CalculsAchat::datePromise($aujourdhui, $fournisseur?->getDelaiPromisJours());
        $retard = $stats['retardMoyen'] ?? null;

        // « 4 rouleaux ≈ 21 semaines » : ce que couvre chaque ligne au rythme actuel.
        $reappro = $this->suivi->reappro($aujourdhui);
        $detail = SuiviAchats::detailCommande($achat);
        $suggestions = [];
        foreach ($detail['lignes'] as $l) {
            $suggestions[] = CalculsAchat::suggestion((float) $l['ligne']->getQuantite(), $l['article']->getUniteAchat() ?: $l['article']->getUniteCourte(), $l['article']->getFacteurConversion(), $reappro[(int) $l['article']->getId()]['consoHebdo'] ?? 0.0);
        }

        return $this->render('achats/commande.html.twig', [
            'achat' => $achat,
            'fournisseur' => $fournisseur,
            'modifiable' => $modifiable,
            'detail' => $detail,
            'suggestions' => $suggestions,
            'alerte' => $alerte,
            'articles' => $modifiable ? $this->choixArticles($achat, $aujourdhui) : [],
            'seuil' => $seuil,
            'a_valider' => CalculsAchat::doitEtreValidee($achat->getTotalRendu(), $seuil),
            'peut_valider' => $this->isGranted(self::ROLE_VALIDATION),
            'livraison' => [
                'promise' => $promise,
                'prevue' => CalculsAchat::livraisonPrevue($promise, $retard),
                'retard' => $retard !== null && $retard > 0 ? (int) ceil($retard) : 0,
            ],
        ]);
    }

    /**
     * Articles proposés dans les lignes : ceux du fournisseur d'abord,
     * avec ce qu'il faut au navigateur pour recalculer la commande.
     *
     * @return array{fournisseur: list<array<string, mixed>>, autres: list<array<string, mixed>>}
     */
    private function choixArticles(Achats $achat, \DateTimeImmutable $aujourdhui): array
    {
        $reappro = $this->suivi->reappro($aujourdhui);
        $choix = ['fournisseur' => [], 'autres' => []];
        $articles = $this->em->getRepository(Articles::class)->findBy(['actif' => true], ['designation' => 'ASC']);

        foreach ($articles as $article) {
            $lien = null;
            foreach ($article->getFournisseurs() as $l) {
                if ($l->getFournisseur() === $achat->getFournisseur()) {
                    $lien = $l;
                }
            }

            $choix[$lien !== null ? 'fournisseur' : 'autres'][] = [
                'article' => $article,
                'nom' => SuiviAchats::nomCourt($article),
                'prix' => $lien?->getPrixAchat() ?? $article->getPrixAchat(),
                'facteur' => $article->getFacteurConversion(),
                'unite' => $article->getUniteCourte(),
                'uniteAchat' => $article->getUniteAchat() ?: $article->getUniteCourte(),
                'conso' => $reappro[(int) $article->getId()]['consoHebdo'] ?? 0.0,
            ];
        }

        return $choix;
    }

    /**
     * Ligne de l'article en alerte, plus les autres articles du même
     * fournisseur à commander dans les jours de regroupement.
     *
     * @param array<int, array<string, mixed>> $reappro
     *
     * @return array<string, mixed>
     */
    private function preremplirDepuisAlerte(Achats $achat, Articles $article, array $reappro, \DateTimeImmutable $aujourdhui): array
    {
        $ligneAlerte = $reappro[(int) $article->getId()] ?? null;
        $this->ajouterLigne($achat, $article, $ligneAlerte['unites'] ?? 1);

        $limite = $aujourdhui->modify(sprintf('+%d days', $this->suivi->joursRegroupement()));
        $ajoutes = [];

        foreach ($reappro as $ligne) {
            if ($ligne['article'] === $article || $ligne['fournisseur'] !== $achat->getFournisseur()) {
                continue;
            }

            if ($ligne['bientot'] || ($ligne['date'] !== null && $ligne['date'] <= $limite)) {
                $this->ajouterLigne($achat, $ligne['article'], $ligne['unites']);
                $ajoutes[] = $ligne + ['dans' => $ligne['date'] !== null ? max(0, CalculsAchat::retardJours($aujourdhui, $ligne['date'])) : 0];
            }
        }

        $achat->recalculerTotaux();

        return [
            'article' => $article,
            'texte' => $ligneAlerte !== null ? CalculsAchat::texteProchaineCommande($ligneAlerte['urgence'], $ligneAlerte['date']) : null,
            'ajoutes' => $ajoutes,
        ];
    }

    private function ajouterLigne(Achats $achat, Articles $article, int $quantite): void
    {
        $prix = $article->getPrixAchat() ?? 0;

        foreach ($article->getFournisseurs() as $lien) {
            if ($lien->getFournisseur() === $achat->getFournisseur() && $lien->getPrixAchat() !== null) {
                $prix = $lien->getPrixAchat();
            }
        }

        $ligne = (new AchatDetail())->setArticle($article)->setQuantite(max(1, $quantite))->setPrixUnitaire($prix);
        $achat->addLigne($ligne);
    }

    /**
     * Transport et transit repris de la dernière commande du fournisseur :
     * même libellé, même base, et le même montant par unité de stock
     * appliqué aux quantités de cette commande (arrondi à la centaine).
     */
    private function preremplirFrais(Achats $achat): void
    {
        $precedente = null;
        foreach ($this->suivi->achats() as $a) {
            if ($a->getFournisseur() === $achat->getFournisseur() && SuiviAchats::estPassee($a) && ($a->getFraisTransport() > 0 || $a->getFraisTransit() > 0)) {
                $precedente = $a;
                break;
            }
        }

        if ($precedente === null) {
            return;
        }

        $avant = SuiviAchats::detailCommande($precedente);
        $maintenant = SuiviAchats::detailCommande($achat);
        $mettreAEchelle = static function (int $frais) use ($avant, $maintenant): int {
            if ($avant['quantiteTotale'] <= 0 || $maintenant['quantiteTotale'] <= 0 || !$avant['memeUnite'] || $avant['unite'] !== $maintenant['unite']) {
                return $frais;
            }

            return (int) (round($frais / $avant['quantiteTotale'] * $maintenant['quantiteTotale'] / 100) * 100);
        };

        $achat->setFraisTransportLibelle($precedente->getFraisTransportLibelle())
            ->setFraisTransportBase($precedente->getFraisTransportBase())
            ->setFraisTransport($mettreAEchelle($precedente->getFraisTransport()))
            ->setFraisTransitLibelle($precedente->getFraisTransitLibelle())
            ->setFraisTransitBase($precedente->getFraisTransitBase())
            ->setFraisTransit($mettreAEchelle($precedente->getFraisTransit()));
    }

    /**
     * Lit le formulaire de la commande, l'enregistre en brouillon et,
     * avec « Envoyer », la fait valider ou la passe.
     */
    private function enregistrer(Request $request, Achats $achat, bool $nouvelle): Response
    {
        $this->verifierJeton($request, 'commande_achat');
        $donnees = $request->request->all();

        foreach ($achat->getLignes()->toArray() as $ancienne) {
            $achat->removeLigne($ancienne);
        }

        $articles = $this->em->getRepository(Articles::class);
        foreach ((array) ($donnees['lignes'] ?? []) as $saisie) {
            $article = \is_array($saisie) ? $articles->find((int) ($saisie['article'] ?? 0)) : null;
            $quantite = \is_array($saisie) ? (int) self::entier((string) ($saisie['quantite'] ?? '')) : 0;

            if (!$article instanceof Articles || $quantite <= 0) {
                continue;
            }

            $achat->addLigne((new AchatDetail())->setArticle($article)->setQuantite($quantite)->setPrixUnitaire(self::entier((string) ($saisie['prix'] ?? ''))));
        }

        $achat->setFraisTransport(self::entier((string) ($donnees['transport_montant'] ?? '')))
            ->setFraisTransportLibelle((string) ($donnees['transport_libelle'] ?? ''))
            ->setFraisTransportBase((string) ($donnees['transport_base'] ?? ''))
            ->setFraisTransit(self::entier((string) ($donnees['transit_montant'] ?? '')))
            ->setFraisTransitLibelle((string) ($donnees['transit_libelle'] ?? ''))
            ->setFraisTransitBase((string) ($donnees['transit_base'] ?? ''))
            ->setObservation(trim((string) ($donnees['observation'] ?? '')) ?: null)
            ->setAcomptePourcentage((int) ($achat->getFournisseur()?->getAcomptePourcentage() ?? 0));

        if ($achat->getLignes()->isEmpty()) {
            $this->addFlash('error', 'Ajoutez au moins un article avec une quantité.');

            return $this->afficher($achat, true);
        }

        $achat->recalculerTotaux();
        SuiviAchats::figerCouts($achat);

        if ($nouvelle) {
            $utilisateur = $this->getUser();
            $achat->setCreePar($utilisateur instanceof User ? $utilisateur : null);
            $achat->setNumero($this->suivi->prochainNumero(new \DateTimeImmutable()));
            $this->em->persist($achat);
        }

        $message = sprintf('La commande %s est enregistrée en brouillon.', $achat->getNumero());

        if (($donnees['action'] ?? '') === 'envoyer') {
            $seuil = $this->suivi->seuilValidation();
            if (CalculsAchat::doitEtreValidee($achat->getTotalRendu(), $seuil)) {
                $achat->setStatut(Achats::STATUT_A_VALIDER);
                $message = sprintf('La commande %s dépasse %s F : elle attend la validation de la direction.', $achat->getNumero(), number_format($seuil, 0, ',', ' '));
            } else {
                $this->passerCommande($achat);
                $message = sprintf('La commande %s est passée chez %s.', $achat->getNumero(), $achat->getFournisseur()?->getNomCourt());
            }
        }

        $this->em->flush();
        $this->addFlash('success', $message);

        return $this->redirectToRoute($achat->estBrouillon() ? 'app_achat_edit' : 'app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * La commande part chez le fournisseur : date de commande, et date
     * promise d'après son délai annoncé.
     */
    private function passerCommande(Achats $achat): void
    {
        $maintenant = new \DateTimeImmutable();
        $achat->setStatut(Achats::STATUT_COMMANDEE);
        $achat->setDateCommande($maintenant);
        $achat->setDatePromise(CalculsAchat::datePromise($maintenant, $this->delaiPromis($achat)));
    }

    /**
     * Délai promis : celui du fournisseur, sinon le plus long noté sur
     * ses articles commandés.
     */
    private function delaiPromis(Achats $achat): ?int
    {
        if ($achat->getFournisseur()?->getDelaiPromisJours() !== null) {
            return $achat->getFournisseur()->getDelaiPromisJours();
        }

        $delai = null;
        foreach ($achat->getLignes() as $ligne) {
            foreach ($ligne->getArticle()?->getFournisseurs() ?? [] as $lien) {
                if ($lien->getFournisseur() === $achat->getFournisseur() && $lien->getDelaiJours() !== null) {
                    $delai = max($delai ?? 0, $lien->getDelaiJours());
                }
            }
        }

        return $delai;
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    /**
     * « 159 600 » ou « 159600 F » donnent 159600.
     */
    private static function entier(string $valeur): int
    {
        return (int) preg_replace('/\D+/', '', $valeur);
    }
}
