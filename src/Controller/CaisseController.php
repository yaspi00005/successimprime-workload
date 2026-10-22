<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\ClotureCaisse;
use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Entity\User;
use App\Form\PaiementsType;
use App\Repository\ClientsRepository;
use App\Repository\ParametresPaiementRepository;
use App\Security\AccesEnLigne;
use App\Service\Caisse;
use App\Service\DocumentsEntreprise;
use App\Service\Http\Entree;
use App\Service\NotificationService;
use App\Service\Paiement\EncaissementGroupe;
use App\Service\Paiement\SoldeClient;
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
 * Orange Money : le fonds de soutien et le frais de retrait, payés par le
 * client en plus, sont cochés d'office ; ils restent sur le paiement.
 *
 * « Plusieurs commandes » (EncaissementGroupe) : un montant reçu se répartit
 * sur les commandes cochées du client, la plus ancienne d'abord, avec un
 * seul reçu.
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
    public function index(Request $request, ClientsRepository $clients, EntityManagerInterface $em, AccesEnLigne $acces, Parametres $parametres, ParametresPaiementRepository $parametresPaiement, SoldeClient $soldeClient): Response
    {
        $utilisateur = $this->utilisateur();
        $maintenant = new \DateTimeImmutable();
        $client = $clients->find(Entree::entier($request->query, 'client')) ?? null;
        $impayees = $client instanceof Clients ? $this->caisse->commandesImpayees($client) : [];

        $commande = null;
        $reste = 0;
        foreach ($impayees as $ligne) {
            if ($ligne['commande']->getId() === Entree::entier($request->query, 'commande') || (null === $commande && 1 === \count($impayees))) {
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

        // Frais Orange Money payés par le client en plus (Réglages › Paiements) : cochés d'office à la caisse.
        $taux = $parametresPaiement->recuperer();

        $recu = null;
        if (Entree::entier($request->query, 'recu') > 0) {
            $recu = $this->recuAutorise($em->getRepository(Paiements::class)->find(Entree::entier($request->query, 'recu')));
        }

        // Compte du client (docs/finances.md) : son solde, payer avec, déposer ; le reçu d'un dépôt fait ici.
        $compteClient = null;
        if ($client instanceof Clients) {
            $compteClient = [
                'solde' => $client->getSoldeCredit(),
                'disponible' => $soldeClient->disponiblePour($client),
                'en_ligne' => $acces->estEnLigne(),
                'comptes' => array_values(array_filter($comptes, static fn (CompteTresorerie $c): bool => !$c->isOrangeMoneyEnLigne() && \in_array($c->getType(), SoldeClient::TYPES_COMPTES, true))),
            ];
            $compteClient['engage'] = max(0, $compteClient['solde'] - $compteClient['disponible']);
        }
        $recuSolde = null;
        if (Entree::entier($request->query, 'recu_solde') > 0) {
            $ligne = $em->getRepository(MouvementSoldeClient::class)->find(Entree::entier($request->query, 'recu_solde'));
            $recuSolde = null !== $ligne && ($this->isGranted('ROLE_PAIEMENT_VOIR') || $ligne->getPar() === $utilisateur) ? $ligne : null;
        }

        $recuGroupe = $request->query->getString('recu_groupe');

        return $this->render('caisse/index.html.twig', [
            'recu_groupe' => 1 === preg_match('/^G-[0-9A-F]{6}$/D', $recuGroupe) ? $recuGroupe : null,
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
            'compte_client' => $compteClient,
            'recu_solde' => $recuSolde,
            'modes' => array_intersect_key(self::LIBELLES_MODES, array_flip($parametres->modesPaiementActifs())),
            'virement_seuil' => (int) $parametres->valeur('paiements', 'virement_seuil'),
            'recu_envoi' => $recu ? self::envoiRecu($recu, (string) $parametres->valeur('paiements', 'recu')) : null,
            'frais_om' => ['fonds' => $taux->getTauxFondsSoutien(), 'retrait' => $taux->getTauxFraisRetrait()],
        ]);
    }

    /** Reçu à imprimer (ticket 80 mm), sans données internes. */
    #[Route('/recu/{id}', name: 'recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(Paiements $paiement, DocumentsEntreprise $documents, EntityManagerInterface $em): Response
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
            // « Monnaie gardée sur votre compte » : le client n'a pas repris sa monnaie.
            'monnaie' => $em->getRepository(MouvementSoldeClient::class)->findOneBy(['paiement' => $paiement, 'type' => MouvementSoldeClient::MONNAIE]),
        ]);
    }

    /**
     * Un montant reçu pour plusieurs commandes du client : réparti sur les
     * commandes cochées (montants de la page, ou la plus ancienne d'abord
     * quand ils sont vides), contrôlé ici, un paiement par commande.
     */
    #[Route('/groupe', name: 'groupe', methods: ['POST'])]
    public function groupe(Request $request, ClientsRepository $clients, EntityManagerInterface $em, AccesEnLigne $acces, EncaissementGroupe $groupe): Response
    {
        if (!$this->isCsrfTokenValid('caisse_groupe', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $utilisateur = $this->utilisateur();
        $client = $clients->find(Entree::entier($request->request, 'client')) ?? throw $this->createNotFoundException();
        $retour = fn (): Response => $this->redirectToRoute('app_caisse_index', ['client' => $client->getId(), 'plusieurs' => 1]);

        $restes = [];
        foreach ($this->caisse->commandesImpayees($client) as $ligne) {
            $restes[(int) $ligne['commande']->getId()] = ['reste' => $ligne['reste']];
        }
        $recu = self::francs((string) $request->request->get('recu'));
        $cochees = array_map('intval', (array) $request->request->all('commandes'));
        $parts = [];
        foreach ((array) $request->request->all('parts') as $id => $montant) {
            if (\in_array((int) $id, $cochees, true)) {
                $parts[(int) $id] = self::francs((string) $montant);
            }
        }
        if (0 === array_sum($parts)) {
            // Sans montants saisis (page sans script) : la plus ancienne d'abord.
            $parts = EncaissementGroupe::repartir($recu, array_map(static fn (array $r): int => $r['reste'], array_intersect_key($restes, array_flip($cochees))))['parts'];
        }
        $surplus = $recu - array_sum(array_filter($parts, static fn (int $m): bool => $m > 0));

        $comptes = $this->comptesAutorises($utilisateur);
        $compte = null;
        foreach ($comptes as $c) {
            $compte = $c->getId() === Entree::entier($request->request, 'compte') ? $c : $compte;
        }
        $mode = $request->request->getString('mode');
        try {
            if (null === $compte) {
                throw new \DomainException('Choisissez le compte qui reçoit l\'argent.');
            }
            if (!isset(Paiements::MODES_LABELS[$mode]) || \in_array($mode, Paiements::MODES_SANS_ENCAISSEMENT, true)) {
                throw new \DomainException('Choisissez le mode de paiement.');
            }
            EncaissementGroupe::verifier($parts, $restes, $recu, $surplus);
            $surCompte = $surplus > 0 && 'compte' === $request->request->getString('surplus') ? $surplus : 0;
            $resultat = $groupe->encaisser($client, $parts, $mode, $compte, $request->request->getString('reference'), $surCompte, $acces->estEnLigne(), $utilisateur);
        } catch (\DomainException $e) {
            $this->addFlash('error', 'Rien n’a été enregistré : '.$e->getMessage());

            return $retour();
        }

        $this->addFlash('success', \sprintf(
            '%s F encaissés sur %d commande%s.%s',
            number_format(array_sum($parts), 0, ',', ' '),
            \count($resultat['paiements']),
            \count($resultat['paiements']) > 1 ? 's' : '',
            $surplus > 0 ? ($resultat['surplus'] > 0 ? \sprintf(' %s F gardés sur le compte du client.', number_format($surplus, 0, ',', ' ')) : \sprintf(' Monnaie à rendre : %s F.', number_format($surplus, 0, ',', ' '))) : '',
        ));

        return $this->redirectToRoute('app_caisse_index', ['client' => $client->getId(), 'recu_groupe' => $resultat['groupe']]);
    }

    /** Reçu d'un encaissement groupé : toutes les commandes payées, le total, le reste dû. */
    #[Route('/recu-groupe/{groupe}', name: 'recu_groupe', requirements: ['groupe' => 'G-[0-9A-F]{6}'], methods: ['GET'])]
    public function recuGroupe(string $groupe, DocumentsEntreprise $documents, EntityManagerInterface $em): Response
    {
        /** @var list<Paiements> $paiements */
        $paiements = $em->getRepository(Paiements::class)->createQueryBuilder('p')
            ->andWhere('p.observation LIKE :groupe')
            ->setParameter('groupe', 'Encaissement groupé '.$groupe.' %')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();
        $paiements = array_values(array_filter($paiements, fn (Paiements $p): bool => null !== $this->recuAutorise($p)));
        if ([] === $paiements) {
            throw $this->createNotFoundException();
        }
        $lignes = [];
        foreach ($paiements as $p) {
            $commande = $p->getCommande();
            $lignes[] = ['paiement' => $p, 'commande' => $commande, 'reste' => max(0, (int) $commande?->getTotalTtc() - (int) $commande?->getTotalPaye())];
        }
        $client = $paiements[0]->getCommande()?->getClients();
        $surplus = $em->getRepository(MouvementSoldeClient::class)->createQueryBuilder('m')
            ->andWhere('m.motif = :motif')->setParameter('motif', \sprintf('Surplus de l\'encaissement groupé %s.', $groupe))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $entreprise = $documents->entreprise(Factures::EMETTEUR_MDG_SUCCESS, Factures::EMETTEURS_LABELS[Factures::EMETTEUR_MDG_SUCCESS]);

        return $this->render('caisse/recu_groupe.html.twig', [
            'entreprise' => $entreprise,
            'images' => $documents->images($entreprise),
            'groupe' => $groupe,
            'lignes' => $lignes,
            'client' => $client,
            'premier' => $paiements[0],
            'total' => array_sum(array_map(static fn (Paiements $p): int => (int) $p->getMontant(), $paiements)),
            'resteClient' => array_sum(array_map(static fn (array $l): int => $l['reste'], $lignes)),
            'surplus' => $surplus,
        ]);
    }

    /** @return list<CompteTresorerie> les comptes que la personne peut créditer (ses caisses du jour) */
    private function comptesAutorises(User $utilisateur): array
    {
        $formulaire = $this->createForm(PaiementsType::class, new Paiements(), ['est_admin' => $this->isGranted('ROLE_ADMIN'), 'utilisateur' => $utilisateur]);

        return array_map(static fn ($choix): CompteTresorerie => $choix->data, array_values($formulaire->get('compteTresorerie')->createView()->vars['choices']));
    }

    /** « 200 000 », « 200000 F » : un entier en francs, 0 si illisible. */
    private static function francs(string $saisie): int
    {
        $chiffres = str_replace([' ', "\u{202F}", "\u{00A0}", 'F', 'f'], '', trim($saisie));

        return ctype_digit($chiffres) && \strlen($chiffres) <= 12 ? (int) $chiffres : 0;
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
            if ($ligne['compte']->getId() === Entree::entier($request->request, 'compte')) {
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

        // Reçu à distance, pas encore vérifié sur le relevé (maquette FD07) : pas de numéro de reçu.
        if (Paiements::STATUT_EN_ATTENTE === $paiement->getStatut()) {
            $message = \sprintf(
                "Bonjour %s, nous avons bien noté votre paiement de %s F (%s%s) pour la commande %s. Il est en cours de validation : le reçu suivra après vérification. Merci, Succès Imprim.",
                $client->getPrenom() ?: $client->getNomComplet(),
                $f((int) $paiement->getMontant()),
                Paiements::MODES_LABELS[$paiement->getMode()] ?? $paiement->getMode(),
                $paiement->getReference() ? ', réf. '.$paiement->getReference() : '',
                $commande->getNumero(),
            );
        } else {
            $message = \sprintf(
                "Bonjour %s, nous avons bien reçu %s F (%s) pour la commande %s. %s Reçu R-%06d. Merci, Succès Imprim.",
                $client->getPrenom() ?: $client->getNomComplet(),
                $f((int) $paiement->getMontant()),
                Paiements::MODES_LABELS[$paiement->getMode()] ?? $paiement->getMode(),
                $commande->getNumero(),
                $reste > 0 ? 'Reste à payer : '.$f($reste).' F.' : 'Votre commande est entièrement payée.',
                $paiement->getId(),
            );
        }

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
