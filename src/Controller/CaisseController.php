<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\ClotureCaisse;
use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\User;
use App\Form\PaiementsType;
use App\Repository\ClientsRepository;
use App\Security\AccesEnLigne;
use App\Service\Caisse;
use App\Service\DocumentsEntreprise;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Caisse (maquette 28) : choisir le client, la commande à
 * régler, le mode, encaisser et imprimer le reçu ; à droite la caisse du
 * jour et les derniers encaissements ; en fin de journée, la clôture.
 *
 * L'enregistrement réutilise la route app_commandes_paiement (mêmes
 * contrôles, mouvement de trésorerie, facture) avec retour à la caisse.
 */
#[Route('/caisse', name: 'app_caisse_')]
#[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
final class CaisseController extends AbstractController
{
    /** Boutons de la caisse, dans l'ordre d'usage au comptoir. */
    private const LIBELLES_MODES = [
        Paiements::MODE_ESPECES => 'Espèces',
        Paiements::MODE_ORANGE_MONEY => 'Orange Money',
        Paiements::MODE_VIREMENT => 'Virement',
        Paiements::MODE_CHEQUE => 'Chèque',
    ];

    public function __construct(private readonly Caisse $caisse)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ClientsRepository $clients, EntityManagerInterface $em, AccesEnLigne $acces, Parametres $parametres): Response
    {
        $utilisateur = $this->utilisateur();
        $maintenant = new \DateTimeImmutable();
        $client = $clients->find($request->query->getInt('client')) ?? null;
        $impayees = $client instanceof Clients ? $this->caisse->commandesImpayees($client) : [];

        $commande = null;
        $reste = 0;
        foreach ($impayees as $ligne) {
            if ($ligne['commande']->getId() === $request->query->getInt('commande') || (null === $commande && 1 === \count($impayees))) {
                $commande = $ligne['commande'];
                $reste = $ligne['reste'];
            }
        }

        $paiement = (new Paiements())->setMontant(max(1, $reste))->setMode(Paiements::MODE_ESPECES);
        $formulaire = $this->createForm(PaiementsType::class, $paiement, [
            'est_admin' => $this->isGranted('ROLE_ADMIN'),
            'utilisateur' => $utilisateur,
            // Le solde des comptes reste à la trésorerie ; la caisse du jour (à droite) suffit pour clôturer.
            'voir_soldes' => $this->isGranted('ROLE_TRESORERIE_VOIR'),
            'action' => null !== $commande ? $this->generateUrl('app_commandes_paiement', ['id' => $commande->getId(), 'retour' => 'caisse']) : '#',
        ]);

        // Les comptes que la personne peut créditer : ce sont aussi ses caisses du jour.
        $comptes = array_map(static fn ($choix): CompteTresorerie => $choix->data, array_values($formulaire->get('compteTresorerie')->createView()->vars['choices']));

        $recu = null;
        if ($request->query->getInt('recu') > 0) {
            $recu = $this->recuAutorise($em->getRepository(Paiements::class)->find($request->query->getInt('recu')));
        }

        return $this->render('caisse/index.html.twig', [
            'en_ligne' => $acces->estEnLigne(),
            'clients_avec_solde' => $this->caisse->clientsAvecSolde(),
            'client' => $client,
            'impayees' => $impayees,
            'commande' => $commande,
            'reste' => $reste,
            'formulaire' => $formulaire->createView(),
            'comptes' => $comptes,
            'caisses' => $this->caisse->caisseDuJour($comptes, $maintenant),
            'encaissements' => $this->caisse->encaissementsDuJour($maintenant, $this->isGranted('ROLE_PAIEMENT_VOIR') ? null : $utilisateur),
            'recu' => $recu,
            'modes' => array_intersect_key(self::LIBELLES_MODES, array_flip($parametres->modesPaiementActifs())),
            'virement_seuil' => (int) $parametres->valeur('paiements', 'virement_seuil'),
            'recu_envoi' => $recu ? self::envoiRecu($recu, (string) $parametres->valeur('paiements', 'recu')) : null,
        ]);
    }

    /** Reçu à imprimer (ticket 80 mm), sans données internes. */
    #[Route('/recu/{id}', name: 'recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(Paiements $paiement, DocumentsEntreprise $documents): Response
    {
        $paiement = $this->recuAutorise($paiement) ?? throw $this->createAccessDeniedException();
        $commande = $paiement->getCommande();
        $paye = 0;
        foreach ($commande?->getPaiements() ?? [] as $p) {
            $paye += $p->estValide() ? (int) $p->getMontant() : 0;
        }

        // Même en-tête que la facture de la commande (Succès Imprim par défaut).
        $facture = $paiement->getFacture();
        foreach ($commande?->getFactures() ?? [] as $f) {
            $facture ??= $f;
        }
        $entreprise = $documents->entreprise(
            $facture?->getEmetteur() ?? Factures::EMETTEUR_MDG_SUCCESS,
            $facture?->getEmetteurLabel() ?? Factures::EMETTEURS_LABELS[Factures::EMETTEUR_MDG_SUCCESS],
        );

        return $this->render('caisse/recu.html.twig', [
            'entreprise' => $entreprise,
            'images' => $documents->images($entreprise),
            'paiement' => $paiement,
            'commande' => $commande,
            'reste' => max(0, (int) $commande?->getTotalTtc() - $paye),
        ]);
    }

    #[Route('/cloturer', name: 'cloturer', methods: ['POST'])]
    #[IsGranted('ROLE_JOURNAL_CAISSE')]
    public function cloturer(Request $request, EntityManagerInterface $em, NotificationService $notifications): Response
    {
        if (!$this->isCsrfTokenValid('cloturer-caisse', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $utilisateur = $this->utilisateur();
        $formulaire = $this->createForm(PaiementsType::class, new Paiements(), ['est_admin' => $this->isGranted('ROLE_ADMIN'), 'utilisateur' => $utilisateur]);
        $comptes = array_map(static fn ($choix): CompteTresorerie => $choix->data, array_values($formulaire->get('compteTresorerie')->createView()->vars['choices']));
        $caisse = null;
        foreach ($this->caisse->caisseDuJour($comptes, new \DateTimeImmutable()) as $ligne) {
            if ($ligne['compte']->getId() === $request->request->getInt('compte')) {
                $caisse = $ligne;
            }
        }
        $saisi = trim((string) $request->request->get('compte_especes'));
        if (null === $caisse || '' === $saisi || !ctype_digit(str_replace([' ', "\u{202F}", "\u{00A0}"], '', $saisi))) {
            $this->addFlash('error', 'Indiquez la caisse et le montant compté (en francs).');

            return $this->redirectToRoute('app_caisse_index');
        }

        $cloture = (new ClotureCaisse())
            ->setCompte($caisse['compte'])
            ->setMontantTheorique($caisse['theorique'])
            ->setMontantCompte((int) str_replace([' ', "\u{202F}", "\u{00A0}"], '', $saisi))
            ->setObservation(mb_substr((string) $request->request->get('observation'), 0, 1000))
            ->setCloturePar($utilisateur);
        $em->persist($cloture);

        $ecart = $cloture->getEcart();
        if (0 !== $ecart) {
            $notifications->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf('Écart de caisse : %s, %s F à la clôture par %s.', $caisse['compte']->getNom(), ($ecart > 0 ? '+' : '').number_format($ecart, 0, ',', ' '), $utilisateur->getUserIdentifier()),
                'app_caisse_index',
                [],
                $utilisateur,
            );
        }
        $em->flush();
        $this->addFlash(0 === $ecart ? 'success' : 'warning', 0 === $ecart
            ? 'Caisse clôturée : le compte correspond au théorique.'
            : sprintf('Caisse clôturée avec un écart de %s F. Le responsable est prévenu.', ($ecart > 0 ? '+' : '').number_format($ecart, 0, ',', ' ')));

        return $this->redirectToRoute('app_caisse_index');
    }

    /** Un reçu ne s'ouvre que pour qui voit les paiements ou l'a encaissé. */
    /**
     * Lien pour envoyer le reçu au client (Paramètres › Paiements › Reçu envoyé).
     *
     * @return array{canal: string, lien: string}|null
     */
    public static function envoiRecu(Paiements $paiement, string $canal): ?array
    {
        $commande = $paiement->getCommande();
        $client = $commande?->getClients();
        $telephone = $client?->getTelephone() ? UtilisateursController::numeroWhatsApp($client->getTelephone()) : null;
        if (!\in_array($canal, ['whatsapp', 'sms'], true) || $telephone === null || $telephone === '') {
            return null;
        }

        $paye = 0;
        foreach ($commande->getPaiements() as $p) {
            $paye += $p->estValide() ? (int) $p->getMontant() : 0;
        }
        $reste = max(0, (int) $commande->getTotalTtc() - $paye);
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ');

        $message = \sprintf(
            "Bonjour %s, nous avons bien reçu %s F (%s) pour la commande %s. %s Reçu R-%06d. Merci, Succès Imprim.",
            $client->getPrenom() ?: $client->getNomComplet(),
            $f((int) $paiement->getMontant()),
            Paiements::MODES_LABELS[$paiement->getMode()] ?? $paiement->getMode(),
            $commande->getNumero(),
            $reste > 0 ? 'Reste à payer : '.$f($reste).' F.' : 'Votre commande est entièrement payée.',
            $paiement->getId(),
        );

        return [
            'canal' => $canal,
            'lien' => $canal === 'whatsapp'
                ? 'https://wa.me/'.$telephone.'?text='.rawurlencode($message)
                : 'sms:+'.$telephone.'?body='.rawurlencode($message),
        ];
    }

    private function recuAutorise(?Paiements $paiement): ?Paiements
    {
        if (null === $paiement) {
            return null;
        }

        return $this->isGranted('ROLE_PAIEMENT_VOIR') || $paiement->getEncaissePar() === $this->getUser() ? $paiement : null;
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
