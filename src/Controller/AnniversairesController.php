<?php

namespace App\Controller;

use App\Entity\AnniversaireClient;
use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\ModeleAnniversaire;
use App\Entity\Produits;
use App\Entity\User;
use App\Service\Promotions\AnniversairesClients;
use App\Service\Promotions\CalculAnniversaire;
use App\Service\Promotions\Promotions;
use App\Service\Statistiques\DroitsStatistiques;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Anniversaires des clients (docs/promotions.md), droit Promotions : les
 * anniversaires des semaines à venir (changer la promotion d'un client,
 * l'exclure, tout valider), les modèles par nombre d'années et le suivi.
 * Le jour J, c'est la tâche app:promotions qui crée la promotion et envoie
 * le SMS. Le CA d'un client n'est montré qu'avec le droit de voir les
 * ventes.
 */
#[Route('/promotions/anniversaires')]
#[IsGranted('ROLE_PROMOTIONS')]
final class AnniversairesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnniversairesClients $anniversaires,
        private readonly Promotions $promotions,
    ) {
    }

    #[Route('', name: 'app_anniversaires_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $reglages = $this->promotions->reglages();
        $jours = max(1, min(90, $request->query->getInt('jours', $reglages['anniversaire_delai_jours'])));
        $aujourdhui = new \DateTimeImmutable('today');
        $voirCa = $this->droits()['ventes'];
        // Les jours de rattrapage d'abord : un anniversaire passé pas encore validé peut encore l'être.
        $liste = $this->anniversaires->entre($aujourdhui->modify('-'.CalculAnniversaire::RATTRAPAGE_JOURS.' days'), $aujourdhui->modify('+'.$jours.' days'), $voirCa);
        $debutMois = $aujourdhui->modify('first day of this month');
        $recents = $this->anniversaires->recents($aujourdhui->modify('-60 days'), 30);
        // Liens WhatsApp quand les SMS ne sont pas branchés (jamais pour une fiche de démonstration).
        $liens = [];
        foreach ($recents as $a) {
            if (AnniversaireClient::CANAL_WHATSAPP === $a->getCanal()) {
                $liens[(int) $a->getId()] = ['message' => $this->anniversaires->lienWhatsApp($a), 'rappel' => null !== $a->getRappelLe() ? $this->anniversaires->lienWhatsApp($a, true) : null];
            }
        }

        return $this->render('anniversaires/index.html.twig', [
            'jours' => $jours,
            'aujourdhui' => $aujourdhui,
            'liste' => $liste,
            'a_valider' => \count(array_filter($liste, static fn (array $e): bool => null !== $e['modele'] && (null === $e['anniversaire'] || (null === $e['anniversaire']->getDecision() && !$e['anniversaire']->estTraite())))),
            'modeles' => $this->anniversaires->modeles(),
            'reglages' => $reglages,
            'voir_ca' => $voirCa,
            'suivi' => $this->anniversaires->suivi($debutMois, $voirCa),
            'debut_mois' => $debutMois,
            'recents' => $recents,
            'liens' => $liens,
            'en_ligne' => $this->promotions->estEnLigne(),
        ]);
    }

    #[Route('/decider', name: 'app_anniversaires_decider', methods: ['POST'])]
    public function decider(Request $request): Response
    {
        $f = $request->request;
        if ($this->isCsrfTokenValid('anniversaires', $f->getString('_token'))) {
            try {
                $client = $this->em->find(Clients::class, $f->getInt('client')) ?? throw new \DomainException('Client introuvable.');
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $f->getString('date')) ?: throw new \DomainException('Date illisible.');
                $modele = $f->getInt('modele') > 0 ? $this->em->find(ModeleAnniversaire::class, $f->getInt('modele')) : null;
                $decision = 'exclure' === $f->getString('decision') ? AnniversaireClient::EXCLU : AnniversaireClient::VALIDE;
                $this->anniversaires->decider($client, max(1, $f->getInt('annees')), $date, $decision, $modele, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', AnniversaireClient::EXCLU === $decision
                    ? \sprintf('%s : anniversaire exclu, ni promotion ni SMS.', $client->getNomComplet())
                    : \sprintf('%s : anniversaire validé%s.', $client->getNomComplet(), null !== $modele ? ' avec « '.$modele->getNom().' »' : ''));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_anniversaires_index', ['jours' => $f->getInt('jours') ?: null], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tout-valider', name: 'app_anniversaires_tout_valider', methods: ['POST'])]
    public function toutValider(Request $request): Response
    {
        $jours = max(1, min(90, $request->request->getInt('jours', 21)));
        if ($this->isCsrfTokenValid('anniversaires', $request->request->getString('_token'))) {
            try {
                $aujourdhui = new \DateTimeImmutable('today');
                $liste = $this->anniversaires->entre($aujourdhui->modify('-'.CalculAnniversaire::RATTRAPAGE_JOURS.' days'), $aujourdhui->modify('+'.$jours.' days'));
                $n = $this->anniversaires->toutValider($liste, $this->agent(), new \DateTimeImmutable());
                $this->addFlash('success', 0 === $n ? 'Rien à valider.' : \sprintf('%d anniversaire%s validé%s.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : ''));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_anniversaires_index', ['jours' => $jours], Response::HTTP_SEE_OTHER);
    }

    /* ---------- Modèles ---------- */

    #[Route('/modele/nouveau', name: 'app_anniversaires_modele_nouveau', methods: ['GET', 'POST'])]
    public function nouveauModele(Request $request): Response
    {
        $modele = new ModeleAnniversaire();
        $existants = array_map(static fn (ModeleAnniversaire $m): int => $m->getAnnees(), $this->anniversaires->modeles());
        $modele->setAnnees([] === $existants ? 1 : max($existants) + 1);

        return $this->formulaireModele($request, $modele);
    }

    #[Route('/modele/{id}', name: 'app_anniversaires_modele', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifierModele(Request $request, ModeleAnniversaire $modele): Response
    {
        return $this->formulaireModele($request, $modele);
    }

    #[Route('/modele/{id}/supprimer', name: 'app_anniversaires_modele_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerModele(Request $request, ModeleAnniversaire $modele): Response
    {
        if ($this->isCsrfTokenValid('modele-anniversaire-'.$modele->getId(), $request->request->getString('_token'))) {
            try {
                $this->promotions->surLaBoutique();
                $this->promotions->journaliser('ModeleAnniversaire', (int) $modele->getId(), 'suppression', ['annees' => $modele->getAnnees(), 'nom' => $modele->getNom()], null, $this->agent());
                // Les promotions déjà créées restent : elles ne dépendent pas du modèle.
                $this->em->remove($modele);
                $this->em->flush();
                $this->addFlash('success', 'Modèle supprimé.');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_anniversaires_index', ['_fragment' => 'modeles'], Response::HTTP_SEE_OTHER);
    }

    private function formulaireModele(Request $request, ModeleAnniversaire $modele): Response
    {
        $nouveau = null === $modele->getId();
        $erreurs = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('modele-anniversaire', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $f = $request->request;
            $avant = $nouveau ? null : ['annees' => $modele->getAnnees(), 'nom' => $modele->getNom(), 'avantage' => $modele->getLibelleAvantage(), 'articles' => $modele->getLibelleArticles(), 'validite' => $modele->getValiditeJours(), 'texte' => $modele->getTexteSms(), 'actif' => $modele->isActif()];
            $modele->setAnnees($f->getInt('annees'))
                ->setNom($f->getString('nom'))
                ->remplacerProduits($this->em->getRepository(Produits::class)->findBy(['id' => array_map('intval', $f->all('produits'))]))
                ->setCategorie($f->getInt('categorie') > 0 ? $this->em->find(CategorieProduit::class, $f->getInt('categorie')) : null)
                ->setAvantage($f->getString('avantage'))
                ->setValeur((float) str_replace([',', ' ', "\u{202f}", "\u{a0}"], ['.', '', '', ''], $f->getString('valeur')))
                ->setValiditeJours($f->getInt('validite', 30))
                ->setTexteSms($f->getString('texte'))
                ->setActif($f->getBoolean('actif'));

            if ('' === $modele->getNom()) {
                $erreurs[] = 'Donnez un nom à la promotion (le client le voit).';
            }
            if ($modele->getProduits()->isEmpty() && null === $modele->getCategorie()) {
                $erreurs[] = 'Choisissez au moins un produit ou une catégorie.';
            }
            if ($modele->getValeur() <= 0 || (\App\Entity\Promotion::AVANTAGE_POURCENTAGE === $modele->getAvantage() && $modele->getValeur() > 90)) {
                $erreurs[] = 'L\'avantage doit être supérieur à zéro (90 % au plus).';
            }
            if ('' === $modele->getTexteSms()) {
                $erreurs[] = 'Écrivez le texte du SMS.';
            } elseif (CalculAnniversaire::contientMontant($modele->getTexteSms())) {
                $erreurs[] = 'Le SMS ne donne ni montant ni pourcentage : le client découvre son cadeau en boutique ou dans son espace.';
            }
            $autre = $this->em->getRepository(ModeleAnniversaire::class)->findOneBy(['annees' => $modele->getAnnees()]);
            if (null !== $autre && $autre !== $modele) {
                $erreurs[] = \sprintf('Il y a déjà un modèle pour %s.', CalculAnniversaire::libelleAnnees($modele->getAnnees()));
            }

            if ([] === $erreurs) {
                try {
                    $this->promotions->surLaBoutique();
                    $modele->marquerModifie(new \DateTimeImmutable());
                    $this->em->persist($modele);
                    $this->em->flush();
                    $this->promotions->journaliser('ModeleAnniversaire', (int) $modele->getId(), $nouveau ? 'creation' : 'modification', $avant, ['annees' => $modele->getAnnees(), 'nom' => $modele->getNom(), 'avantage' => $modele->getLibelleAvantage(), 'articles' => $modele->getLibelleArticles(), 'validite' => $modele->getValiditeJours(), 'texte' => $modele->getTexteSms(), 'actif' => $modele->isActif()], $this->agent());
                    $this->em->flush();
                    $this->addFlash('success', \sprintf('Modèle « %s » enregistré.', CalculAnniversaire::libelleAnnees($modele->getAnnees())));

                    return $this->redirectToRoute('app_anniversaires_index', ['_fragment' => 'modeles'], Response::HTTP_SEE_OTHER);
                } catch (\DomainException $e) {
                    $erreurs[] = $e->getMessage();
                }
            }
        }

        return $this->render('anniversaires/modele.html.twig', [
            'modele' => $modele,
            'nouveau' => $nouveau,
            'erreurs' => $erreurs,
            'produits' => $this->em->getRepository(Produits::class)->findBy([], ['nom' => 'ASC']),
            'categories' => $this->em->getRepository(CategorieProduit::class)->findBy([], ['nom' => 'ASC']),
            'exemple' => CalculAnniversaire::texte($modele->getTexteSms(), 'Awa', 'Traoré', $modele->getAnnees(), (new \DateTimeImmutable('today'))->modify('+'.($modele->getValiditeJours() - 1).' days')),
        ]);
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
