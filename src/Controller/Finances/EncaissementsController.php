<?php

namespace App\Controller\Finances;

use App\Entity\LigneReleveOrangeMoney;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\Finances\Encaissements;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finances › Encaissements et rapprochement Orange Money (maquette F02).
 * Voir : ROLE_PAIEMENT_VOIR (sinon, avec ROLE_FACTURE, on arrive sur les
 * créances) ; importer le relevé et associer : ROLE_PAIEMENT_ENCAISSER ;
 * écarter une transaction qui n'est pas un paiement client : ROLE_ADMIN.
 * Une association crée un paiement EN ATTENTE : la validation se fait
 * dans À valider, jamais ici.
 */
#[Route('/finances/encaissements', name: 'app_finances_encaissements')]
final class EncaissementsController extends AbstractController
{
    use DroitsFinances;

    /** Lignes du tableau des encaissements. */
    public const NOMBRE = 15;

    public function __construct(
        private readonly Encaissements $encaissements,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isGranted('ROLE_PAIEMENT_VOIR')) {
            if ($this->isGranted('ROLE_FACTURE')) {
                return $this->redirectToRoute('app_creances_index');
            }
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $aAssocier = $this->encaissements->aAssocier();

        // Une piste par transaction, pour la pastille « Suggestion » du tableau.
        $pistes = [];
        foreach (\array_slice($aAssocier, 0, 20) as $ligne) {
            $pistes[(int) $ligne->getId()] = $this->encaissements->suggestions($ligne, $maintenant, 3);
        }

        // Transaction ouverte à droite : celle demandée, sinon celle qui a la meilleure piste.
        $choisie = null;
        $meilleur = -1;
        $id = Entree::entier($request->query, 'ligne');
        foreach ($aAssocier as $ligne) {
            $score = $pistes[(int) $ligne->getId()][0]['score'] ?? 0;
            if ($ligne->getId() === $id || (0 === $id && $score > $meilleur)) {
                $choisie = $ligne;
                $meilleur = $ligne->getId() === $id ? 100 : $score;
            }
        }
        $suggestions = \count(array_filter($pistes, static fn (array $p): bool => ($p[0]['score'] ?? 0) >= Encaissements::SCORE_SUGGESTION));
        if (null !== $choisie && !isset($pistes[(int) $choisie->getId()])) {
            $pistes[(int) $choisie->getId()] = $this->encaissements->suggestions($choisie, $maintenant, 3);
        }

        // Juste après une association : le reçu à envoyer (lien wa.me).
        $recu = null;
        if (Entree::entier($request->query, 'recu') > 0) {
            $paiement = $this->em->find(Paiements::class, Entree::entier($request->query, 'recu'));
            $ligne = null === $paiement ? null : $this->em->getRepository(LigneReleveOrangeMoney::class)->findOneBy(['paiement' => $paiement]);
            if (null !== $paiement && null !== $ligne) {
                $recu = ['paiement' => $paiement, 'ligne' => $ligne, 'lien' => Encaissements::lienRecu($paiement, $ligne)];
            }
        }

        // Tableau : transactions à associer et derniers paiements, du plus récent au plus ancien.
        $lignes = [];
        foreach ($aAssocier as $l) {
            $lignes[] = ['date' => $l->getRecuLe(), 'releve' => $l, 'paiement' => null, 'lie' => false];
        }
        foreach ($this->encaissements->derniersPaiements(self::NOMBRE) as $p) {
            $lignes[] = ['date' => $p['paiement']->getDate(), 'releve' => null, 'paiement' => $p['paiement'], 'lie' => $p['releve']];
        }
        usort($lignes, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        $montantAAssocier = array_sum(array_map(static fn (LigneReleveOrangeMoney $l): int => $l->getMontant(), $aAssocier));

        return $this->render('finances/encaissements.html.twig', [
            'droits' => $this->droits(),
            'kpi' => $this->encaissements->indicateurs($maintenant, \count($aAssocier), $montantAAssocier),
            'lignes' => \array_slice($lignes, 0, self::NOMBRE),
            'a_associer' => $aAssocier,
            'pistes' => $pistes,
            'choisie' => $choisie,
            'creances' => null !== $choisie && $this->isGranted('ROLE_PAIEMENT_ENCAISSER') ? $this->encaissements->creancesOuvertes($maintenant) : [],
            'recu' => $recu,
            'score_suggestion' => Encaissements::SCORE_SUGGESTION,
            'nb_suggestions' => $suggestions,
            'aujourdhui' => $maintenant->setTime(0, 0),
        ]);
    }

    #[Route('/importer', name: '_importer', methods: ['POST'])]
    public function importer(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PAIEMENT_ENCAISSER');
        if (!$this->isCsrfTokenValid('importer-releve-om', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $fichier = $request->files->get('releve');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            $this->addFlash('danger', 'Choisissez le relevé Orange Money (fichier CSV).');

            return $this->redirectToRoute('app_finances_encaissements');
        }
        $extension = mb_strtolower($fichier->getClientOriginalExtension());
        if (!\in_array($extension, ['csv', 'txt'], true) || $fichier->getSize() > Encaissements::TAILLE_MAX) {
            $this->addFlash('danger', 'Le relevé doit être un fichier CSV de 2 Mo au plus (export Excel « CSV »).');

            return $this->redirectToRoute('app_finances_encaissements');
        }

        try {
            // Le fichier n'est pas conservé : seules les transactions lues sont enregistrées.
            $bilan = $this->encaissements->importer((string) file_get_contents($fichier->getPathname()), $this->utilisateur(), new \DateTimeImmutable());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_finances_encaissements');
        }
        $this->addFlash($bilan['ajoutees'] > 0 ? 'success' : 'info', Encaissements::messageImport($bilan));
        if ([] !== $bilan['erreurs']) {
            $this->addFlash('warning', implode(' ', \array_slice($bilan['erreurs'], 0, 5)).(\count($bilan['erreurs']) > 5 ? ' …' : ''));
        }

        return $this->redirectToRoute('app_finances_encaissements');
    }

    #[Route('/releve/{id}/associer', name: '_associer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function associer(LigneReleveOrangeMoney $ligne, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PAIEMENT_ENCAISSER');
        if (!$this->isCsrfTokenValid('associer-om-'.$ligne->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $choix = (string) $request->request->get('choix');
        if ('autre' === $choix) {
            $choix = (string) $request->request->get('autre');
        }
        $score = $request->request->has('score_'.$choix) ? max(0, min(99, Entree::entier($request->request, 'score_'.$choix))) : null;

        try {
            $paiement = $this->encaissements->associer($ligne, $choix, $score, $this->utilisateur(), new \DateTimeImmutable());
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_finances_encaissements', ['ligne' => $ligne->getId()]);
        }
        $this->addFlash('success', str_starts_with($choix, 'paiement-')
            ? 'Transaction rapprochée du paiement déjà saisi.'
            : 'Paiement associé, en attente de validation dans « À valider ».');

        return $this->redirectToRoute('app_finances_encaissements', ['recu' => $paiement->getId()]);
    }

    #[Route('/releve/{id}/ignorer', name: '_ignorer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignorer(LigneReleveOrangeMoney $ligne, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('ignorer-om-'.$ligne->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try {
            $this->encaissements->ignorer($ligne, mb_substr((string) $request->request->get('motif'), 0, 255), $this->utilisateur(), new \DateTimeImmutable());
            $this->addFlash('success', 'Transaction écartée : elle n’apparaît plus dans « À associer ».');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_finances_encaissements', ['ligne' => $ligne->getId()]);
        }

        return $this->redirectToRoute('app_finances_encaissements');
    }

    private function utilisateur(): User
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $utilisateur;
    }
}
