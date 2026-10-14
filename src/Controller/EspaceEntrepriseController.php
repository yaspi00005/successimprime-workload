<?php

namespace App\Controller;

use App\Entity\AdresseLivraison;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\EspaceClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, compte d'entreprise (maquette 19) : personnes du compte et
 * leurs droits, adresses de livraison, validation interne des commandes.
 * Chacun n'agit que sur la fiche de son entreprise.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceEntrepriseController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $repository,
        private readonly CompteEntreprise $entreprise,
    ) {
    }

    /* ---------- Adresses de livraison ---------- */

    #[Route('/compte/adresses', name: 'adresses', methods: ['GET', 'POST'])]
    public function adresses(Request $request): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $gere = $compte->peut(CompteClient::DROIT_ADMINISTRATEUR);
        $modifiee = $request->query->getInt('modifier') > 0 ? $this->entreprise->adresse($client, $request->query->getInt('modifier')) : null;

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            if (!$gere) {
                throw $this->createAccessDeniedException();
            }
            $id = $request->request->getInt('id');
            $adresse = $id > 0 ? ($this->entreprise->adresse($client, $id) ?? throw $this->createNotFoundException()) : null;
            try {
                $this->entreprise->enregistrerAdresse($client, $adresse, [
                    'libelle' => mb_substr(trim($request->request->getString('libelle')), 0, 60),
                    'adresse' => mb_substr(trim($request->request->getString('adresse')), 0, 255),
                    'repere' => self::texte($request->request->getString('repere'), 160),
                    'contact' => self::texte($request->request->getString('contact'), 80),
                    'telephone' => self::texte($request->request->getString('telephone'), 20),
                    'parDefaut' => $request->request->getBoolean('par_defaut'),
                ]);
                $this->addFlash('succes', null === $adresse ? 'Adresse ajoutée.' : 'Adresse modifiée.');

                return $this->redirectToRoute('app_espace_adresses');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/adresses.html.twig', [
            'compte' => $compte,
            'adresses' => $this->entreprise->adresses($client),
            'gere' => $gere,
            'modifiee' => $modifiee,
            'max' => AdresseLivraison::MAX_PAR_CLIENT,
        ]);
    }

    #[Route('/compte/adresses/{id}/supprimer', name: 'adresse_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerAdresse(int $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        if (!$compte->peut(CompteClient::DROIT_ADMINISTRATEUR)) {
            throw $this->createAccessDeniedException();
        }
        $adresse = $this->entreprise->adresse($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $this->entreprise->supprimerAdresse($adresse);
        $this->addFlash('succes', 'Adresse retirée.');

        return $this->redirectToRoute('app_espace_adresses');
    }

    /* ---------- Personnes du compte ---------- */

    #[Route('/compte/utilisateurs/nouveau', name: 'utilisateur_nouveau', methods: ['GET', 'POST'])]
    public function nouvelUtilisateur(Request $request): Response
    {
        $compte = $this->compteQuiGere();
        $saisie = ['nom' => '', 'telephone' => '', 'fonction' => '', 'droits' => []];

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $saisie = [
                'nom' => mb_substr(trim($request->request->getString('nom')), 0, 80),
                'telephone' => $request->request->getString('telephone'),
                'fonction' => mb_substr(trim($request->request->getString('fonction')), 0, 60),
                'droits' => $request->request->all('droits'),
            ];
            try {
                $ajoute = $this->entreprise->ajouter($compte, $saisie['telephone'], $saisie['nom'], $saisie['droits'], $saisie['fonction']);
                $this->addFlash('succes', \sprintf('%s est ajouté au compte. Un SMS lui explique comment se connecter.', $ajoute->getNomAffiche()));

                return $this->redirectToRoute('app_espace_compte');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/utilisateur.html.twig', ['personne' => null, 'saisie' => $saisie, 'droits' => self::droitsProposes()]);
    }

    #[Route('/compte/utilisateurs/{id}', name: 'utilisateur', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function utilisateur(int $id, Request $request): Response
    {
        $compte = $this->compteQuiGere();
        $personne = $this->personne($compte, $id);

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            try {
                $this->entreprise->changerDroits($compte, $personne, $request->request->all('droits'), mb_substr(trim($request->request->getString('fonction')), 0, 60));
                $this->addFlash('succes', \sprintf('Droits de %s enregistrés.', $personne->getNomAffiche()));

                return $this->redirectToRoute('app_espace_compte');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/utilisateur.html.twig', [
            'personne' => $personne,
            'saisie' => ['nom' => $personne->getNomAffiche(), 'telephone' => $personne->getTelephone(), 'fonction' => $personne->getFonction() ?? '', 'droits' => $personne->getDroits()],
            'droits' => self::droitsProposes(),
        ]);
    }

    #[Route('/compte/utilisateurs/{id}/retirer', name: 'utilisateur_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerUtilisateur(int $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compteQuiGere();
        $personne = $this->personne($compte, $id);
        try {
            $this->entreprise->retirer($compte, $personne);
            $this->addFlash('succes', \sprintf('%s n\'a plus accès au compte.', $personne->getNomAffiche()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_compte');
    }

    /* ---------- Validation interne des commandes ---------- */

    #[Route('/commandes/{id}/valider', name: 'commande_valider', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function valider(string $id, Request $request, EspaceClient $espace): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $commande = $espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $this->entreprise->valider($compte, $commande);
            $this->addFlash('succes', \sprintf('Commande %s validée : elle part à l\'atelier, qui confirme le prix et le fichier.', $commande->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
    }

    #[Route('/commandes/{id}/refuser', name: 'commande_refuser', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function refuser(string $id, Request $request, EspaceClient $espace): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $commande = $espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $this->entreprise->refuser($compte, $commande, $request->request->getString('motif'));
            $this->addFlash('succes', \sprintf('Commande %s refusée. %s le voit dans son espace.', $commande->getNumero(), $commande->getDemandeePar() ?? 'La personne qui l\'a demandée'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
    }

    /* ---------- Outils ---------- */

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, array $parametres): Response
    {
        $compte = $this->compte();

        return $this->render($gabarit, $parametres + [
            'onglet' => 'compte',
            'moi' => $compte->getClient(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
        ]);
    }

    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->repository->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }

    private function compteQuiGere(): CompteClient
    {
        $compte = $this->compte();
        if (!$compte->peut(CompteClient::DROIT_ADMINISTRATEUR) || !CompteClient::clientAPlusieursComptes($compte->getClient())) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }

    /** Une personne de la meme entreprise, ou 404. */
    private function personne(CompteClient $compte, int $id): CompteClient
    {
        foreach ($this->entreprise->utilisateurs($compte->getClient()) as $c) {
            if ($c->getId() === $id && $c->isActif()) {
                return $c;
            }
        }

        throw $this->createNotFoundException();
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** @return array<string, string> les droits du formulaire, « Lecture · suivi » en dernier */
    private static function droitsProposes(): array
    {
        return CompteClient::DROITS + [CompteClient::DROIT_LECTURE => CompteClient::LIBELLE_LECTURE];
    }

    private static function texte(string $valeur, int $longueur): ?string
    {
        $valeur = mb_substr(trim($valeur), 0, $longueur);

        return '' === $valeur ? null : $valeur;
    }
}
