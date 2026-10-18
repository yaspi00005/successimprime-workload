<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\EspaceClient\OptionsCommande;
use App\Service\EspaceClient\PaiementALaCommande;
use App\Service\EspaceClient\Panier;
use App\Service\Http\Entree;
use App\Service\Paiement\OrangeMoney;
use App\Service\Parametres\ReglagesSaisie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, panier de « Mes produits » (maquettes MP05 et MP07) : les
 * produits choisis, la réception, le récapitulatif avec l'acompte, et ce
 * qui partira directement en impression. Validé, le panier devient la
 * commande ; le payeur règle l'acompte par Orange Money (même paiement que
 * la commande en ligne). Un client dispensé d'acompte valide sans payer ;
 * un demandeur envoie la demande à son valideur, sans voir de prix.
 */
#[Route('/espace/panier', name: 'app_espace_panier')]
final class EspacePanierController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly Panier $panier,
        private readonly MesProduits $mesProduits,
        private readonly CompteEntreprise $compteEntreprise,
        private readonly DelaisCommande $delais,
        private readonly ReglagesSaisie $reglagesSaisie,
        private readonly OrangeMoney $orangeMoney,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function voir(): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $montants = $compte->montantsVisibles();
        $peutPayer = $compte->peut(CompteClient::DROIT_PAYEUR);
        $detail = $this->panier->detail($compte, $montants);
        $pourcentage = $this->delais->pourcentageAcompte($client);
        $pret = $this->delais->pretLe(Commandes::DELAI_STANDARD, new \DateTimeImmutable())['pret'];
        $frais = $this->reglagesSaisie->fraisEnLigne();

        return $this->render('espace/app/panier.html.twig', [
            'onglet' => 'commandes',
            'moi' => $client,
            'peutPayer' => $peutPayer,
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'detail' => $detail,
            // L'acompte ne se montre qu'à qui paie (docs/espace-client.md).
            'acompte' => $peutPayer && $montants && $pourcentage > 0 ? ['pourcentage' => $pourcentage, 'montant' => DelaisCommande::acompte($detail['total'], $pourcentage)] : null,
            'sansAcompte' => $pourcentage <= 0,
            'pret' => DelaisCommande::jour($pret),
            'fraisLivraison' => $frais,
            'adresses' => $this->compteEntreprise->adresses($client),
            'creneaux' => $this->delais->creneauxPour($pret),
            'entreprise' => CompteClient::clientAPlusieursComptes($client),
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
            'omActif' => $this->orangeMoney->estOuvert(),
        ]);
    }

    #[Route('/{rang}/quantite', name: '_quantite', requirements: ['rang' => '\d{1,2}'], methods: ['POST'])]
    public function quantite(int $rang, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->panier->changerQuantite($this->compte(), $rang, (float) str_replace([' ', ','], ['', '.'], $request->request->getString('quantite')));
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_panier');
    }

    #[Route('/{rang}/retirer', name: '_retirer', requirements: ['rang' => '\d{1,2}'], methods: ['POST'])]
    public function retirer(int $rang, Request $request): Response
    {
        $this->jeton($request);
        $this->panier->retirer($this->compte(), $rang);

        return $this->redirectToRoute('app_espace_panier');
    }

    /** « Valider et payer » : le panier devient la commande. */
    #[Route('/valider', name: '_valider', methods: ['POST'])]
    public function valider(Request $request, NouvelleCommande $nouvelleCommande): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $client = $compte->getClient();
        try {
            if (!$nouvelleCommande->estOuverte()) {
                throw new \DomainException(NouvelleCommande::FERMEE);
            }
            $selection = $this->panier->selection($compte);
            $commande = $this->mesProduits->commander($client, $selection, new \DateTimeImmutable(), $this->options($compte, $request));
            $this->compteEntreprise->preparerCommande($compte, $commande, $request->request->getString('bon_commande'), null);
            $nouvelleCommande->enregistrer();
            $this->panier->vider($compte);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_panier');
        }

        $directs = \count(array_filter($commande->getCommandesDetails()->toArray(), static fn ($l): bool => !$l->isPrePresseNecessaire()));
        $suite = $directs > 0 ? \sprintf(' %d produit%s partir%s directement en impression%s.', $directs, $directs > 1 ? 's' : '', $directs > 1 ? 'ont' : 'a', $directs < $commande->getCommandesDetails()->count() ? ' ; pour les autres, un BAT vous est envoyé avant l\'impression' : '') : ' Un BAT vous est envoyé avant l\'impression.';
        if ($commande->attendValidationEntreprise()) {
            $this->compteEntreprise->prevenirValideurs($commande, $compte);
            $this->addFlash('succes', \sprintf('Demande %s envoyée. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero()));
        } elseif ($this->delais->pourcentageAcompte($client) <= 0) {
            $this->addFlash('succes', \sprintf('Commande %s validée, sans acompte : elle part en production.%s', $commande->getNumero(), $suite));
        } elseif ($compte->peut(CompteClient::DROIT_PAYEUR) && $this->orangeMoney->estOuvert() && PaiementALaCommande::payable($commande, $compte) && OrangeMoney::aPayer($commande) > 0) {
            // Récapitulatif avec les frais Orange Money, puis la page d'Orange : l'acompte lance la production.
            return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => (string) $commande->getPublicId(), 'part' => PaiementALaCommande::ACOMPTE, 'envoyee' => 1]);
        } else {
            $this->addFlash('succes', \sprintf('Commande %s envoyée. Elle part en production dès que l\'acompte est réglé%s.%s', $commande->getNumero(), $compte->peut(CompteClient::DROIT_PAYEUR) ? ' (à l\'imprimerie ou dans « Factures et paiements »)' : ' par la personne qui paie pour votre entreprise', $suite));
        }

        return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
    }

    /** @throws \DomainException */
    private function options(CompteClient $compte, Request $request): OptionsCommande
    {
        $pret = $this->delais->pretLe(Commandes::DELAI_STANDARD, new \DateTimeImmutable())['pret'];
        if ('livraison' !== $request->request->getString('reception')) {
            return new OptionsCommande(Commandes::DELAI_STANDARD, 0, false, 0, null, null, $pret);
        }
        $enregistree = Entree::entier($request->request, 'adresse') > 0 ? $this->compteEntreprise->adresse($compte->getClient(), Entree::entier($request->request, 'adresse')) : null;
        $adresse = null !== $enregistree ? $enregistree->texte() : trim(mb_substr($request->request->getString('adresse_texte'), 0, 200));
        if ('' === $adresse) {
            throw new \DomainException('Indiquez l\'adresse de livraison à Bamako.');
        }
        $contact = trim(mb_substr($request->request->getString('contact'), 0, 80));
        if (null === $enregistree && '' !== $contact) {
            $adresse .= "\nContact : ".$contact;
        }
        $choisi = DelaisCommande::creneauChoisi($request->request->getString('creneau'), $this->delais->creneauxPour($pret)) ?? throw new \DomainException('Choisissez un créneau de livraison.');

        return new OptionsCommande(Commandes::DELAI_STANDARD, 0, true, $this->reglagesSaisie->fraisEnLigne(), mb_substr($adresse, 0, 255), $choisi['libelle'], $choisi['jour']);
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

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
