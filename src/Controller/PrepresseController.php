<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Service\Prepresse\AssociationFormats;
use App\Service\Prepresse\DepotFichiers;
use App\Service\Prepresse\DossierPartage;
use App\Service\Prepresse\EtatPrepresse;
use App\Service\Prepresse\ListePrepresse;
use App\Service\Prepresse\RangementDossierClient;
use App\Service\Prepresse\RelancesFichiers;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prépresse, écrans neufs (maquettes PQ01 à PQ04, docs/prepresse.md) :
 * liste par commande, dépôt groupé des fichiers, tableau et relances des
 * fichiers manquants. Le contrôle d'une ligne, le BAT et les retours du
 * machiniste restent dans ControlePrePresseController.
 *
 * Tout /pre-presse est réservé à ROLE_PREPRESSE (security.yaml).
 */
#[Route('/pre-presse', name: 'app_controle_pre_presse_')]
final class PrepresseController extends AbstractController
{
    private const JETON_CSRF = 'prepresse-depot';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListePrepresse $liste,
        private readonly DossierPartage $partage,
    ) {
    }

    /** PQ01 : une ligne par commande, onglets, recherche, dépôt groupé. */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $commandes = $this->liste->commandes($maintenant);
        $onglet = (string) $request->query->get('onglet', '');
        $nombres = [];
        foreach (array_keys(EtatPrepresse::ONGLETS) as $cle) {
            $nombres[$cle] = 'tout' === $cle ? \count($commandes) : \count(array_filter($commandes, static fn (array $c): bool => \in_array($cle, $c['onglets'], true)));
        }
        if (!isset(EtatPrepresse::ONGLETS[$onglet])) {
            // Par défaut, le premier onglet qui a du travail.
            $onglet = 'tout';
            foreach ([EtatPrepresse::SANS_FICHIER, EtatPrepresse::A_CONTROLER, EtatPrepresse::RETOUR, EtatPrepresse::BAT] as $cle) {
                if ($nombres[$cle] > 0) {
                    $onglet = $cle;
                    break;
                }
            }
        }

        return $this->render('controle_pre_presse/liste.html.twig', [
            'commandes' => $commandes,
            'indicateurs' => $this->liste->indicateurs($commandes, $maintenant),
            'onglets' => EtatPrepresse::ONGLETS,
            'nombres' => $nombres,
            'onglet' => $onglet,
            'recherche' => trim((string) $request->query->get('q', '')),
            'ouverte' => $request->query->getInt('commande'),
            'aRelancer' => \count(array_filter($commandes, static fn (array $c): bool => $c['manquants'] > 0 && $c['clientJoignable'])),
            'partage' => \count($this->partage->fichiers()),
            'retirees' => $this->liste->nombreRetirees(),
            // Les nouveaux travaux à contrôler font sonner la page (comme l'ancienne file).
            'idsAControler' => array_merge(...array_map(static fn (array $c): array => array_column(array_filter($c['formats'], static fn (array $f): bool => EtatPrepresse::A_CONTROLER === $f['etat']), 'id'), $commandes) ?: [[]]),
        ]);
    }

    /** PQ03 : sans fichier → à contrôler → BAT chez le client → prêt pour la production. */
    #[Route('/tableau', name: 'tableau', methods: ['GET'])]
    public function tableau(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $commandes = $this->liste->commandes($maintenant);
        $recherche = mb_strtolower(trim((string) $request->query->get('q', '')));
        if ('' !== $recherche) {
            $commandes = array_values(array_filter($commandes, static fn (array $c): bool => str_contains($c['recherche'], $recherche)));
        }

        return $this->render('controle_pre_presse/tableau.html.twig', [
            'colonnes' => $this->liste->colonnes($commandes),
            'parColonne' => ListePrepresse::CARTES_PAR_COLONNE,
            'recherche' => $recherche,
            'maintenant' => $maintenant,
        ]);
    }

    /** PQ04 : fichiers manquants, relances, réglages des relances automatiques. */
    #[Route('/relances', name: 'relances', methods: ['GET'])]
    public function relances(Request $request, RelancesFichiers $relances, Parametres $parametres): Response
    {
        $maintenant = new \DateTimeImmutable();
        $lignes = $relances->aRelancer($maintenant);
        $recherche = mb_strtolower(trim((string) $request->query->get('q', '')));
        if ('' !== $recherche) {
            $lignes = array_values(array_filter($lignes, static fn (array $c): bool => str_contains($c['recherche'], $recherche)));
        }
        $choisie = $request->query->getInt('commande');

        return $this->render('controle_pre_presse/relances.html.twig', [
            'lignes' => $lignes,
            'choisie' => $choisie,
            'recherche' => $recherche,
            'actives' => $relances->actives(),
            'peutRegler' => $parametres->peutVoir(RelancesFichiers::TUILE),
            'tempsMoyen' => $relances->tempsMoyenReception($maintenant),
            'sansClient' => $relances->sansClientCeMois($maintenant),
        ]);
    }

    /** Relance(s) envoyée(s) par WhatsApp : notées avec l'étape, la date et la personne. */
    #[Route('/relances/envoyee', name: 'relance_envoyee', methods: ['POST'])]
    public function relanceEnvoyee(Request $request, RelancesFichiers $relances): Response
    {
        $moi = $this->moi();
        $json = str_contains((string) $request->headers->get('Accept'), 'application/json');
        if (!$this->isCsrfTokenValid('prepresse-relances', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-TOKEN')))) {
            return $json ? $this->json(['message' => 'La page a expiré. Rechargez-la.'], 403) : $this->retourRelances('error', 'La page a expiré. Rechargez-la.');
        }
        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('commandes'))));
        $maintenant = new \DateTimeImmutable();
        $faites = [];
        foreach ($this->em->getRepository(Commandes::class)->findBy(['id' => $ids]) as $commande) {
            if (ListePrepresse::clientJoignable($commande->getClients())) {
                $faites[] = $relances->marquerEnvoyee($commande, $moi, $maintenant)->getCommande()->getNumero();
            }
        }
        if ($json) {
            return $this->json(['faites' => $faites]);
        }

        return $this->retourRelances('success', [] === $faites ? 'Aucune relance notée : clients sans numéro.' : \sprintf('Relance notée envoyée : %s.', implode(', ', $faites)));
    }

    /** La réponse du client (« je passe samedi »). */
    #[Route('/relances/{id}/reponse', name: 'relance_reponse', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function relanceReponse(Commandes $commande, Request $request, RelancesFichiers $relances): Response
    {
        if (!$this->isCsrfTokenValid('prepresse-relances', (string) $request->request->get('_token'))) {
            return $this->retourRelances('error', 'La page a expiré. Rechargez-la.');
        }
        $relances->noterReponse($commande, mb_substr(trim((string) $request->request->get('reponse')), 0, 255), $this->moi(), new \DateTimeImmutable());

        return $this->retourRelances('success', \sprintf('Réponse notée pour %s.', $commande->getNumero()), $commande->getId());
    }

    /**
     * PQ02 : ce qu'il faut à la fenêtre « Déposer les fichiers » : formats
     * de la commande, fichiers du dossier partagé qui semblent lui revenir,
     * dossier du client où une copie sera rangée.
     */
    #[Route('/commande/{id}/depot', name: 'depot', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function depot(Commandes $commande, RangementDossierClient $rangement): JsonResponse
    {
        $formats = [];
        foreach (DepotFichiers::lignes($commande) as $l) {
            $formats[] = [
                'id' => $l->getId(),
                'libelle' => DepotFichiers::libelleFormat($l),
                'quantite' => $l->getQuantite(),
                'recu' => DepotFichiers::aFichier($l),
            ];
        }
        $client = $commande->getClients();

        return $this->json([
            'commande' => ['id' => $commande->getId(), 'numero' => $commande->getNumero(), 'client' => ListePrepresse::nomClient($client)],
            'formats' => $formats,
            'dossierClient' => null !== $client ? $rangement->cheminAffiche($client, new \DateTimeImmutable()) : null,
            'partage' => array_map(static fn (array $f): array => [
                'nom' => $f['nom'],
                'taille' => $f['taille'],
                'raison' => $f['raison'],
                'pret' => $f['pret'],
                'ligne' => $f['ligne'],
            ], $this->partage->pourCommande($commande)),
        ]);
    }

    /**
     * Association proposée (aperçu) pour les fichiers envoyés (jetons) et
     * ceux choisis dans le dossier partagé. Rien n'est enregistré.
     */
    #[Route('/commande/{id}/depot/associer', name: 'depot_associer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function associer(Commandes $commande, Request $request, DepotFichiers $depot): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::JETON_CSRF, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['message' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $donnees = $this->corps($request);
        $fichiers = $this->fichiersAssocies($commande, $donnees, $depot);

        return $this->json([
            'fichiers' => array_values($fichiers['lignes']),
            'resume' => $fichiers['resume'],
        ]);
    }

    /**
     * « Enregistrer · passer en À contrôler » : le serveur refait
     * l'association et garde les corrections faites dans la fenêtre (un
     * format choisi). Un fichier sans format n'est pas rattaché.
     */
    #[Route('/commande/{id}/depot/enregistrer', name: 'depot_enregistrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enregistrer(Commandes $commande, Request $request, DepotFichiers $depot): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::JETON_CSRF, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['message' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $moi = $this->moi();
        $donnees = $this->corps($request);
        $choix = [];
        foreach ((array) ($donnees['choix'] ?? []) as $cle => $ligne) {
            $choix[(string) $cle] = '' === (string) $ligne || 'auto' === $ligne ? $ligne : (int) $ligne;
        }
        $lignes = [];
        foreach (DepotFichiers::lignes($commande) as $l) {
            $lignes[(int) $l->getId()] = $l;
        }
        $associes = $this->fichiersAssocies($commande, $donnees, $depot);
        $maintenant = new \DateTimeImmutable();
        $rattaches = [];
        $erreurs = [];
        foreach ($associes['lignes'] as $cle => $f) {
            $voulu = $choix[$cle] ?? 'auto';
            $ligneId = 'auto' === $voulu ? $f['format'] : ('' === $voulu ? null : $voulu);
            $ligne = null !== $ligneId ? ($lignes[(int) $ligneId] ?? null) : null;
            try {
                if (null === $ligne) {
                    // Pas de format : un envoi est retiré (pas de fichier orphelin) ; un fichier du dossier partagé reste où il est.
                    if (isset($associes['envois'][$cle])) {
                        $this->retirerEnvoi($associes['envois'][$cle], $depot);
                    }
                    continue;
                }
                if (isset($associes['envois'][$cle])) {
                    $depot->rattacherEnvoi($associes['envois'][$cle], $ligne, $moi, $maintenant);
                } elseif (isset($associes['partages'][$cle])) {
                    $depot->rattacherPartage($associes['partages'][$cle], $ligne, $moi, $maintenant, 'dépôt groupé');
                }
                $rattaches[] = $f['nom'].' → '.DepotFichiers::libelleFormat($ligne);
            } catch (\DomainException $e) {
                $erreurs[] = $e->getMessage();
            }
        }
        $this->em->flush();
        if ([] !== $rattaches) {
            $this->addFlash('success', \sprintf('%d fichier%s rattaché%s à %s, à contrôler : %s.', \count($rattaches), \count($rattaches) > 1 ? 's' : '', \count($rattaches) > 1 ? 's' : '', $commande->getNumero(), implode(' · ', $rattaches)));
        }
        foreach ($erreurs as $e) {
            $this->addFlash('error', $e);
        }

        return $this->json(['rattaches' => \count($rattaches), 'erreurs' => $erreurs, 'retour' => $this->generateUrl('app_controle_pre_presse_index', ['onglet' => EtatPrepresse::A_CONTROLER, 'commande' => $commande->getId()])]);
    }

    /**
     * Les fichiers de la fenêtre et leur association : envois terminés
     * (jetons), fichiers du dossier partagé (noms).
     *
     * @param array<string, mixed> $donnees
     *
     * @return array{lignes: array<string, array<string, mixed>>, resume: array{sur: int, verifier: int, choisir: int}, envois: array<string, CommandeDetailFichier>, partages: array<string, string>}
     */
    private function fichiersAssocies(Commandes $commande, array $donnees, DepotFichiers $depot): array
    {
        $descriptions = [];
        $envois = [];
        $partages = [];
        foreach (array_slice(array_unique(array_map('strval', (array) ($donnees['jetons'] ?? []))), 0, 50) as $jeton) {
            if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
                continue;
            }
            $f = $this->em->getRepository(CommandeDetailFichier::class)->findOneBy(['jetonUpload' => $jeton]);
            // Seulement un envoi terminé, pas encore rattaché, qui n'est pas un fichier de l'espace client.
            if (null === $f || CommandeDetailFichier::STATUT_TERMINE !== $f->getStatut() || null !== $f->getCommandeDetail() || null !== $f->getClient()) {
                continue;
            }
            $cle = 'j:'.$jeton;
            $envois[$cle] = $f;
            $descriptions[] = $depot->decrire($cle, (string) $f->getNomOriginal(), $depot->chemin($f));
        }
        foreach (array_slice(array_unique(array_map('strval', (array) ($donnees['partage'] ?? []))), 0, 50) as $nom) {
            $chemin = $this->partage->chemin($nom);
            if (null === $chemin) {
                continue;
            }
            $cle = 'p:'.$nom;
            $partages[$cle] = $chemin;
            $descriptions[] = $depot->decrire($cle, $nom, $chemin);
        }
        $resultat = AssociationFormats::associer(DepotFichiers::formats($commande), $descriptions);
        $lignes = [];
        foreach ($descriptions as $d) {
            $r = $resultat['fichiers'][$d['cle']];
            $lignes[$d['cle']] = ['cle' => $d['cle'], 'nom' => $d['nom'], 'source' => str_starts_with($d['cle'], 'p:') ? 'partage' : 'depot'] + $r;
        }

        return ['lignes' => $lignes, 'resume' => $resultat['resume'], 'envois' => $envois, 'partages' => $partages];
    }

    /** Un envoi sans format : le fichier et sa ligne sont retirés. */
    private function retirerEnvoi(CommandeDetailFichier $f, DepotFichiers $depot): void
    {
        $chemin = $depot->chemin($f);
        if (null !== $chemin) {
            @unlink($chemin);
        }
        $this->em->remove($f);
    }

    /** @return array<string, mixed> */
    private function corps(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * « Retirer de la liste » : les lignes sans fichier d'une commande (ou
     * une seule, avec « ligne ») quittent la liste de la prépresse. La
     * commande n'est ni clôturée ni supprimée ; « Remettre dans la liste »
     * la ramène (docs/prepresse.md).
     */
    #[Route('/commande/{id}/retirer', name: 'retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirer(Commandes $commande, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('prepresse-retirer', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La page a expiré. Rechargez-la.');

            return $this->redirectToRoute('app_controle_pre_presse_index', ['onglet' => EtatPrepresse::SANS_FICHIER]);
        }
        $ligne = $request->request->getInt('ligne');
        $maintenant = new \DateTimeImmutable();
        $moi = $this->moi();
        $par = trim((string) $moi->getEmploye()?->getNomComplet()) ?: $moi->getUsername();
        $retirees = 0;
        foreach (DepotFichiers::lignes($commande) as $l) {
            // Seulement les lignes encore sans fichier et pas commencées au contrôle.
            if ((0 !== $ligne && $l->getId() !== $ligne) || DepotFichiers::aFichier($l) || !$l->getControlesPrePresse()->isEmpty()) {
                continue;
            }
            $l->retirerDePrepresse($maintenant, $par);
            ++$retirees;
        }
        $this->em->flush();
        $this->addFlash(
            $retirees > 0 ? 'success' : 'error',
            $retirees > 0
                ? \sprintf('%s : %d ligne%s sans fichier retirée%s de la liste. La commande n\'est ni clôturée ni supprimée.', $commande->getNumero(), $retirees, $retirees > 1 ? 's' : '', $retirees > 1 ? 's' : '')
                : 'Rien à retirer : seules les lignes encore sans fichier peuvent quitter la liste.',
        );

        return $this->redirectToRoute('app_controle_pre_presse_index', ['onglet' => EtatPrepresse::SANS_FICHIER]);
    }

    /** Les lignes retirées de la liste, avec « Remettre dans la liste ». */
    #[Route('/retirees', name: 'retirees', methods: ['GET'])]
    public function retirees(): Response
    {
        return $this->render('controle_pre_presse/retirees.html.twig', [
            'lignes' => $this->liste->retirees(),
        ]);
    }

    #[Route('/ligne/{id}/remettre', name: 'remettre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remettre(CommandesDetails $ligne, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('prepresse-retirer', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La page a expiré. Rechargez-la.');

            return $this->redirectToRoute('app_controle_pre_presse_retirees');
        }
        if (null !== $ligne->getRetirePrepresseLe()) {
            $ligne->remettreEnPrepresse();
            $this->em->flush();
            $this->addFlash('success', \sprintf('%s : la ligne %s est revenue dans la liste de la prépresse.', $ligne->getCommande()?->getNumero(), DepotFichiers::libelleFormat($ligne)));
        }

        return $this->redirectToRoute('app_controle_pre_presse_retirees');
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $moi;
    }

    private function retourRelances(string $type, string $message, ?int $commande = null): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute('app_controle_pre_presse_relances', null !== $commande ? ['commande' => $commande] : []);
    }
}
