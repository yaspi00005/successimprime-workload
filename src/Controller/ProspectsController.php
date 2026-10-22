<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ClientsRepository;
use App\Service\PipelineProspects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Prospects (maquette 34) : pipeline commercial du premier
 * contact jusqu'à l'affaire gagnée, qui devient alors une fiche client.
 *
 * Le total en francs du pipeline n'est montré qu'avec ROLE_STATS_VENTES.
 */
#[Route('/clients/prospects', name: 'app_prospects_')]
#[IsGranted('ROLE_CLIENT')]
final class ProspectsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PipelineProspects $pipeline): Response
    {
        $recherche = mb_substr(trim((string) $request->query->get('q')), 0, 80);

        return $this->render('prospects/index.html.twig', $pipeline->donnees(new \DateTimeImmutable(), $recherche) + [
            'recherche' => $recherche,
            'voir_montants' => $this->isGranted('ROLE_STATS_VENTES'),
        ]);
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['POST'])]
    public function nouveau(Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request);
        $prospect = (new Prospect())->setAgent($this->utilisateur());
        $erreur = $this->remplir($prospect, $request);
        if (null !== $erreur) {
            $this->addFlash('warning', $erreur);

            return $this->redirectToRoute('app_prospects_index');
        }
        $em->persist($prospect);
        $em->flush();
        $this->addFlash('success', sprintf('Prospect « %s » ajouté.', $prospect->getNom()));

        return $this->redirectToRoute('app_prospects_index');
    }

    #[Route('/{id}/modifier', name: 'modifier', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function modifier(Prospect $prospect, Request $request, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($request);
        $erreur = $this->remplir($prospect, $request);
        if (null === $erreur) {
            $etape = (string) $request->request->get('etape', $prospect->getEtape());
            try {
                if ($etape !== $prospect->getEtape() || Prospect::ETAPE_PERDU === $etape) {
                    $prospect->changerEtape($etape, (string) $request->request->get('motif_perte'));
                }
            } catch (\InvalidArgumentException $e) {
                $erreur = $e->getMessage();
            }
        }
        if (null !== $erreur) {
            $em->refresh($prospect);
            $this->addFlash('warning', $erreur);

            return $this->redirectToRoute('app_prospects_index');
        }
        if ($prospect->estClos()) {
            $prospect->setProchaineRelance(null);
        }
        $prospect->toucher();
        $em->flush();
        $this->addFlash('success', sprintf('« %s » : %s.', $prospect->getNom(), mb_strtolower(Prospect::ETAPES[$prospect->getEtape()])));

        return $this->redirectToRoute('app_prospects_index');
    }

    /**
     * Affaire gagnée : relie le prospect à la fiche client du même numéro,
     * ou la crée avec le nom et le prénom du contact.
     */
    #[Route('/{id}/fiche-client', name: 'fiche_client', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ficheClient(Prospect $prospect, Request $request, EntityManagerInterface $em, ClientsRepository $clients): Response
    {
        $this->verifierJeton($request);
        if (null !== $prospect->getClient()) {
            return $this->redirectToRoute('app_clients_show', ['publicId' => $prospect->getClient()->getPublicId()]);
        }
        if (Prospect::ETAPE_GAGNE !== $prospect->getEtape()) {
            $this->addFlash('warning', 'La fiche client se crée quand l’affaire est gagnée.');

            return $this->redirectToRoute('app_prospects_index');
        }
        // Les fiches clients gardent le numéro malien sans l'indicatif 223.
        $telephone = preg_replace('/^(?:\+|00)?223(\d{8})$/', '$1', (string) $prospect->getTelephone());
        if (\strlen($telephone) < 8) {
            $this->addFlash('warning', 'Ajoutez le numéro de téléphone du prospect avant de créer sa fiche.');

            return $this->redirectToRoute('app_prospects_index');
        }

        $client = $clients->findOneBy(['telephone' => $telephone]);
        if (null === $client) {
            $nom = trim((string) $request->request->get('nom'));
            $prenom = trim((string) $request->request->get('prenom'));
            if ('' === $nom || '' === $prenom) {
                $this->addFlash('warning', 'Le nom et le prénom du contact sont obligatoires pour la fiche client.');

                return $this->redirectToRoute('app_prospects_index');
            }
            $entreprise = trim((string) $request->request->get('entreprise'));
            $client = (new Clients())
                ->setCode('TMP-'.bin2hex(random_bytes(12)))
                ->setNom(mb_substr($nom, 0, 50))
                ->setPrenom(mb_substr($prenom, 0, 50))
                ->setRaisonSociale('' === $entreprise ? null : mb_substr($entreprise, 0, 100))
                ->setTypeCompte('' === $entreprise ? Clients::TYPE_COMPTE_PARTICULIER : Clients::TYPE_COMPTE_ENTREPRISE)
                ->setTelephone($telephone)
                ->setObservation(sprintf('Prospect gagné (source : %s).', Prospect::SOURCES_LIBELLES[$prospect->getSource()]))
                ->setStatut(true);
            $em->persist($client);
            $em->flush();
            $client->genererCodeDepuisId();
            $this->addFlash('success', sprintf('Fiche client %s créée.', $client->getCode()));
        } else {
            $this->addFlash('info', 'Ce numéro correspond à une fiche existante : le prospect y est rattaché.');
        }
        $prospect->setClient($client)->toucher();
        $em->flush();

        return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()]);
    }

    /**
     * Champs communs à la création et à la modification. Renvoie le
     * message d'erreur à afficher, ou null.
     */
    private function remplir(Prospect $prospect, Request $request): ?string
    {
        $nom = trim((string) $request->request->get('nom'));
        if ('' === $nom) {
            return 'Le nom du prospect est obligatoire.';
        }
        $montant = trim(str_replace([' ', "\u{202F}", "\u{A0}"], '', (string) $request->request->get('montant')));
        if ('' !== $montant && !ctype_digit($montant)) {
            return 'Le montant estimé doit être un nombre entier de francs.';
        }
        $relance = trim((string) $request->request->get('relance'));
        $date = '' === $relance ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $relance);
        if (false === $date) {
            return 'La date de relance n’est pas valide.';
        }
        $prospect
            ->setNom($nom)
            ->setTelephone((string) $request->request->get('telephone'))
            ->setBesoin((string) $request->request->get('besoin'))
            ->setMontantEstime('' === $montant ? null : (int) $montant)
            ->setSource((string) $request->request->get('source'))
            ->setProchaineRelance($date);

        return null;
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('prospect', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function utilisateur(): ?User
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof User ? $utilisateur : null;
    }
}
