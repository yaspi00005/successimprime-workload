<?php

namespace App\Service\Recette;

use App\Entity\AdresseLivraison;
use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ControlePrePresse;
use App\Entity\CreationAtelier;
use App\Entity\DepotSoldeClient;
use App\Entity\DemandeConception;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\FichierPublie;
use App\Entity\ImageIa;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\Paiements;
use App\Entity\ProduitClient;
use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
use App\Entity\ReglementEnLigne;
use App\Entity\SuspensionEspace;
use App\Entity\User;
use App\Security\DroitsEspace;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\EspaceClient\AppareilsClient;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Site\FormulaireDemande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Recette de l'espace client sur le site en ligne (docs/espace-client.md) :
 *  - un particulier, le titulaire d'une autre entreprise B2B et chaque
 *    personne d'un compte entreprise (titulaire, valideur, payeur,
 *    demandeur, lecture · suivi) ouvrent chaque page de l'espace ;
 *  - montants (solde, factures, crédit IA, paiement) seulement pour qui
 *    paie ; prix des commandes, des devis et du catalogue pour qui voit
 *    les montants (CompteClient::montantsVisibles : valideur ou payeur) ;
 *    rien du tout pour un demandeur ni un compte en lecture seule, ni dans
 *    les pages, ni dans leurs données JSON, ni dans les appels de prix ;
 *    accepter un devis seulement pour un valideur ;
 *  - quantité en m² de Nouvelle commande (pièces au m²) : l'encadré se
 *    montre à qui commande, l'estimation en m² compte les pièces, sans
 *    aucun montant pour un demandeur ;
 *  - valider un BAT demande chaque point de « Avant de valider, vérifiez »
 *    (vérifié par le serveur) ; un compte en lecture seule ne valide pas ;
 *  - les fiches d'un autre client (commande, facture, fichier, BAT, photo
 *    de réclamation, conception, notification, utilisateur, adresse…)
 *    demandées par leur numéro dans l'adresse ou glissées dans un envoi
 *    doivent être refusées ;
 *  - l'admin et l'API ne s'ouvrent pas sans appareil autorisé.
 */
final class RecetteEspaceClient
{
    /** Pages de l'espace jamais demandées : connexion, déconnexion, retours de paiement. */
    private const EXCLUES = ['app_espace_connexion', 'app_espace_code', 'app_espace_creer_compte', 'app_espace_suivre', 'app_espace_deconnexion', 'app_espace_payer_retour', 'app_espace_conception_retour'];

    /** Fiche à mettre dans l'adresse de chaque page : route => sorte de fiche. */
    public const FICHES_DES_ROUTES = [
        'app_espace_commande' => 'commande',
        'app_espace_avis_commande' => 'commande',
        'app_espace_payer' => 'commande',
        // Paiement à la commande (nouvelle commande, étape 4) : une commande en ligne encore « à confirmer ».
        'app_espace_nouvelle_commande_paiement' => 'commande',
        'app_espace_devis_pdf' => 'devis',
        'app_espace_devis_detail' => 'devis',
        // Demande de devis dont le devis se prépare (« Voir ma demande », maquette DB01).
        'app_espace_devis_demande' => 'demande_devis',
        'app_espace_facture_pdf' => 'facture',
        // Factures et paiements : détail d'une facture, reçu d'un paiement validé, retour d'Orange Money.
        'app_espace_facture_detail' => 'facture_uuid',
        'app_espace_facture_recu' => 'paiement',
        'app_espace_payer_recu' => 'reglement',
        // Mon solde : retour d'Orange Money après un dépôt.
        'app_espace_solde_retour' => 'depot',
        'app_espace_fichier' => 'fichier',
        'app_espace_bat' => 'bat',
        'app_espace_bat_fichier' => 'bat_fichier',
        'app_espace_conception_demande' => 'conception',
        'app_espace_conception_informations' => 'conception',
        'app_espace_conception_ia' => 'conception',
        'app_espace_conception_image' => 'image',
        'app_espace_reclamation_photo' => 'photo',
        'app_espace_notification_ouvrir' => 'notification',
        'app_espace_utilisateur' => 'utilisateur',
        // Vue rendue ou image d'une création de l'atelier 3D.
        'app_espace_creation_fichier' => 'creation',
        // Note vocale de la messagerie (docs/messagerie-clients.md) : la conversation et le message.
        'app_espace_message_vocale_ecouter' => 'vocale',
        // Fichier publié par l'atelier depuis le dossier du client (docs/dossiers-clients.md).
        'app_espace_fichier_publie' => 'fichier_publie',
        // Livraisons (maquettes DB08 à DB11) : programmer une commande, suivre ou voir la preuve d'un bon de livraison.
        'app_espace_livraison_programmer' => 'commande',
        'app_espace_livraison' => 'livraison',
        'app_espace_livraison_position' => 'livraison',
        'app_espace_livraison_photo' => 'livraison',
    ];

    /** Pages réservées à qui paie (factures, solde, paiement). */
    public const PAGES_PAYEUR = [
        'app_espace_factures', 'app_espace_facture_pdf', 'app_espace_payer', 'app_espace_conception_credit',
        'app_espace_facture_detail', 'app_espace_facture_recu', 'app_espace_facture_releve', 'app_espace_releve', 'app_espace_payer_selection', 'app_espace_payer_recu',
        'app_espace_nouvelle_commande_paiement',
        'app_espace_solde', 'app_espace_solde_retour',
    ];

    /** Pages où un prix est voulu pour qui voit les montants (valideur) : catalogue et prix du jour. */
    public const PAGES_PRIX = ['app_espace_nouvelle_commande', 'app_espace_produits', 'app_espace_promotions', 'app_espace_conception', 'app_espace_conception_demande', 'app_espace_conception_informations', 'app_espace_conception_ia', 'app_espace_devis', 'app_espace_devis_detail', 'app_espace_panier'];

    /** Pages qui ne sont que des montants (le PDF d'un devis) : refusées à qui ne voit pas les montants. */
    public const PAGES_MONTANTS = ['app_espace_devis_pdf'];

    /** Pages où un montant est une règle (seuil, avantage d'une promotion), pour tous, même un demandeur. */
    public const PAGES_REGLES = ['app_espace_promotions'];

    /**
     * Montants voulus pour qui voit les montants sans payer (valideur),
     * d'après ce qui les précède : motif => raison. Jamais pour un
     * demandeur ni un compte en lecture seule.
     */
    public const MONTANTS_VOULUS = [
        '/dernière fois \d{2}\/\d{2} [\d \x{00A0}\x{202F}.]+ F$/u' => '« Recommander rapidement » : prix du jour du catalogue, pour qui voit les montants',
        '/\bTotal(?: indicatif)? [\d \x{00A0}\x{202F}.]+ F$/u' => 'total de la commande, pour qui voit les montants (le reste à payer est réservé au payeur)',
        '/\bFrais de livraison [\d \x{00A0}\x{202F}.]+ F$/u' => 'frais de livraison de la commande, compris dans son total, pour qui voit les montants',
        '/\bRemise [−-] ?[\d \x{00A0}\x{202F}.]+ F$/u' => 'remise globale de la commande, déjà déduite de son total, pour qui voit les montants',
    ];

    /** Clés d'argent d'un JSON (« Télécharger mes données », données d'une page, appels de prix). */
    private const CLES_ARGENT = '/^(solde|factures?|paiements?|montant\w*|reste\w*|total\w*|credit\w*|plafond\w*|prix\w*|remise\w*)$/i';

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly ParametresRoute $parametres,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
        private readonly FilNotifications $fil,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly CatalogueAtelier $atelier,
    ) {
    }

    public function verifier(?callable $suivi = null): BilanRecette
    {
        $bilan = new BilanRecette();
        $this->navigateur->commencer();
        try {
            $comptes = $this->comptes($bilan);
            $sessions = [];
            $fiches = [];
            foreach ($comptes as $libelle => $compte) {
                $suivi && $suivi('espace client : '.$libelle);
                $sessions[$libelle] = $this->navigateur->session($compte, 'espace', [AppareilsClient::SESSION_VERSION => $compte->getVersionSession()]);
                $fiches[$libelle] = $this->fiches($compte);
                $this->verifierPages($libelle, $compte, $sessions[$libelle], $fiches[$libelle], $bilan);
            }
            $suivi && $suivi('espace client : fiches d\'un autre client');
            foreach ([['particulier', 'titulaire'], ['titulaire', 'particulier'], ['b2b', 'titulaire']] as [$a, $b]) {
                if (isset($comptes[$a], $comptes[$b])) {
                    $this->verifierFichesAutreClient($a, $comptes[$a], $sessions[$a], $b, $fiches[$b], $bilan);
                }
            }
            $suivi && $suivi('espace client : droits du compte entreprise');
            $this->verifierDroitsEntreprise($comptes, $sessions, $fiches, $bilan);
            $suivi && $suivi('espace client : validation d\'un BAT');
            $this->verifierBat($comptes, $sessions, $fiches, $bilan);
            $suivi && $suivi('espace client : appels de prix sans montant');
            $this->verifierAppelsSansMontant($comptes, $sessions, $fiches, $bilan);
            $suivi && $suivi('espace client : quantité en m² de Nouvelle commande');
            $this->verifierPiecesM2($comptes, $sessions, $bilan);
            $suivi && $suivi('espace client : accès suspendu par l\'imprimerie');
            $this->verifierSuspension($comptes, $bilan);
            $suivi && $suivi('admin et API en ligne');
            $this->verifierAdminEnLigne($bilan);
        } finally {
            $this->navigateur->terminer();
        }

        return $bilan;
    }

    /**
     * Les comptes essayés : un particulier, le titulaire d'une entreprise B2B,
     * et chaque sorte de personne du compte entreprise qui en a le plus.
     *
     * @return array<string, CompteClient>
     */
    public function comptes(BilanRecette $bilan): array
    {
        /** @var list<CompteClient> $tous */
        $tous = $this->em->getRepository(CompteClient::class)->createQueryBuilder('k')
            ->join('k.client', 'c')->addSelect('c')->where('k.actif = true')->andWhere("c.code LIKE 'DEMO-%'")->orderBy('k.id')->getQuery()->getResult();
        $parClient = [];
        foreach ($tous as $k) {
            $parClient[$k->getClient()->getId()][] = $k;
        }
        uasort($parClient, static fn (array $a, array $b): int => \count($b) <=> \count($a));
        $comptes = [];
        $entreprise = null;
        foreach ($parClient as $liste) {
            if (\count($liste) < 3 || !CompteClient::clientAPlusieursComptes($liste[0]->getClient())) {
                continue;
            }
            $entreprise = $liste[0]->getClient();
            foreach ($liste as $k) {
                $d = $k->getDroits();
                $role = match (true) {
                    $k->estLectureSeule() => 'lecture',
                    \in_array(CompteClient::DROIT_ADMINISTRATEUR, $d, true) => 'titulaire',
                    [CompteClient::DROIT_VALIDEUR] === $d => 'valideur',
                    [CompteClient::DROIT_PAYEUR] === $d => 'payeur',
                    [] === $d => 'demandeur',
                    default => null,
                };
                if (null !== $role) {
                    $comptes[$role] ??= $k;
                }
            }
            break;
        }
        $particulier = null;
        $b2b = null;
        foreach ($parClient as $liste) {
            $client = $liste[0]->getClient();
            if ($client === $entreprise) {
                continue;
            }
            $commandes = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM commandes WHERE clients_id = ? AND statut = 1 AND deleted = 0', [$client->getId()]);
            if (!CompteClient::clientAPlusieursComptes($client) && $commandes > ($particulier[1] ?? 0)) {
                $particulier = [$liste[0], $commandes];
            }
            if ($client->isB2B() && $commandes > ($b2b[1] ?? 0)) {
                $b2b = [$liste[0], $commandes];
            }
        }
        if (null !== $particulier) {
            $comptes = ['particulier' => $particulier[0]] + $comptes;
        }
        if (null !== $b2b) {
            $comptes['b2b'] = $b2b[0];
        }
        foreach (['particulier', 'titulaire', 'valideur', 'payeur', 'demandeur', 'lecture', 'b2b'] as $attendu) {
            $bilan->controler('Espace client : compte d\'essai « '.$attendu.' »', isset($comptes[$attendu]), isset($comptes[$attendu]) ? $comptes[$attendu]->getClient()->getCode().', '.self::masquer($comptes[$attendu]->getTelephone()) : 'introuvable dans la démonstration');
        }
        foreach ($comptes as $libelle => $k) {
            $droits = array_values(array_filter([
                $k->peut(CompteClient::DROIT_ADMINISTRATEUR) ? 'gère le compte' : null,
                $k->peut(CompteClient::DROIT_VALIDEUR) ? 'valideur' : null,
                $k->peut(CompteClient::DROIT_PAYEUR) ? 'payeur' : null,
            ]));
            $bilan->comptes[$libelle] = ['libelle' => $k->getClient()->getCode().' · '.$libelle, 'droits' => $k->estLectureSeule() ? ['lecture · suivi'] : ([] === $droits ? ['demandeur'] : $droits)];
        }

        return $comptes;
    }

    /**
     * Une fiche de chaque sorte du client de ce compte (null s'il n'en a pas).
     *
     * @return array<string, string|int|null>
     */
    public function fiches(CompteClient $compte): array
    {
        $client = $compte->getClient();
        $commandes = $this->espace->commandes($client, 30);
        $confirmee = null;
        foreach ($commandes as $c) {
            if ($c->isStatut() && null === $confirmee) {
                $confirmee = $c;
            }
        }
        $devis = $this->espace->devis($client);
        $envoye = array_values(array_filter($devis, static fn (Devis $d): bool => $d->estEnAttente()));
        $factures = $this->espace->factures($client);
        $bats = $this->espace->bats($client);
        $batsEnAttente = $this->espace->bats($client, true);
        $fichiersBat = [] === $bats ? [] : $this->espace->fichiersBat($bats[0]);
        $notifications = $this->fil->pour($compte, new \DateTimeImmutable());
        $aValider = array_values(array_filter($commandes, static fn (Commandes $c): bool => $c->attendValidationEntreprise()));
        // Une note vocale de sa messagerie (docs/messagerie-clients.md) : conversation et message.
        $vocale = $this->em->createQuery('SELECT c.publicId AS conversation, m.publicId AS message FROM '.MessageClient::class.' m JOIN m.conversation c WHERE m.client = :client AND c.client = :client AND m.vocaleNom IS NOT NULL ORDER BY m.id ASC')
            ->setParameter('client', $client)->setMaxResults(1)->getOneOrNullResult();
        if (null !== $vocale) {
            $vocale = ['conversation' => self::uuid($vocale['conversation']), 'message' => self::uuid($vocale['message'])];
        }

        return [
            'commande' => null === $confirmee ? null : self::uuid($confirmee->getPublicId()),
            'commande_a_valider' => [] === $aValider ? null : self::uuid($aValider[0]->getPublicId()),
            'devis' => [] === $devis ? null : $devis[0]->getId(),
            'devis_envoye' => [] === $envoye ? null : $envoye[0]->getId(),
            'facture' => [] === $factures ? null : $factures[0]->getId(),
            'facture_uuid' => [] === $factures ? null : self::uuid($factures[0]->getPublicId()),
            'paiement' => $this->paiementValide($client),
            'reglement' => $this->premiere(ReglementEnLigne::class, $client, '1 = 1', 'reference'),
            'depot' => $this->premiere(DepotSoldeClient::class, $client, '1 = 1', 'reference'),
            'demande_devis' => $this->premiere(DemandeDevis::class, $client, "e.nature = 'devis'"),
            'livraison' => $this->livraison($client),
            'fichier' => $this->premiere(CommandeDetailFichier::class, $client, 'e.actif = true'),
            'bat' => [] === $bats ? null : $bats[0]->getId(),
            'bat_attente' => [] === $batsEnAttente ? null : $batsEnAttente[0]->getId(),
            'bat_fichier' => [] === $fichiersBat ? null : $fichiersBat[0]->getId(),
            'conception' => $this->premiere(DemandeConception::class, $client, '1 = 1', 'publicId'),
            'image' => $this->premiere(ImageIa::class, $client),
            'photo' => $this->photo($client),
            'notification' => $notifications[0]['cle'] ?? null,
            'utilisateur' => $this->autreCompte($compte),
            'adresse' => $this->premiere(AdresseLivraison::class, $client, 'e.actif = true'),
            'produit' => $this->premiere(ProduitClient::class, $client, '1 = 1', 'publicId'),
            'creation' => $this->premiere(CreationAtelier::class, $client, '1 = 1', 'publicId'),
            'fichier_publie' => $this->premiere(FichierPublie::class, $client, 'e.retireLe IS NULL', 'publicId'),
            'vocale' => $vocale['conversation'] ?? null,
            'vocale_message' => $vocale['message'] ?? null,
        ];
    }

    /**
     * @param array<string, string|int|null> $fiches
     */
    private function verifierPages(string $libelle, CompteClient $compte, MockArraySessionStorage $session, array $fiches, BilanRecette $bilan): void
    {
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR);
        // La règle de l'espace : prix et montants pour un particulier, un valideur ou un payeur.
        $montants = $compte->montantsVisibles();
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            $methodes = $route->getMethods();
            if (!str_starts_with($route->getPath(), '/espace') || \in_array($nom, self::EXCLUES, true) || ([] !== $methodes && !\in_array('GET', $methodes, true))) {
                continue;
            }
            $parametres = [];
            foreach ($route->compile()->getPathVariables() as $variable) {
                $valeur = match ($variable) {
                    'taille' => 'v',
                    'quoi' => 'rendu-devant',
                    'message' => $fiches['vocale_message'] ?? null,
                    default => $fiches[self::FICHES_DES_ROUTES[$nom] ?? ''] ?? null,
                };
                if (null === $valeur) {
                    $bilan->ignorees[$nom.' ('.$libelle.')'] = 'pas de fiche « '.(self::FICHES_DES_ROUTES[$nom] ?? $variable).' » pour ce client';
                    continue 2;
                }
                $parametres[$variable] = $valeur;
            }
            $chemin = $this->routeur->generate($nom, $parametres);
            $reponse = $this->navigateur->get($chemin, $session);
            $etat = $reponse->etat();
            $bilan->pages[$nom] ??= ['route' => $nom, 'onglet' => null];
            $bilan->matrice[$nom][$libelle] = $etat;

            if (Reponse::ERREUR === $etat) {
                $bilan->constater(BilanRecette::ERREUR, 'erreur', $nom, $chemin, $libelle, (string) $reponse->erreur);
                continue;
            }
            $ouverte = Reponse::OUVERT === $etat || (Reponse::REDIRIGE === $etat && str_contains($reponse->redirection, '/pdf-public/'));
            if (!$payeur && \in_array($nom, self::PAGES_PAYEUR, true) && $ouverte) {
                $bilan->constater(BilanRecette::FUITE, 'payeur', $nom, $chemin, $libelle, 'page de paiement ou de factures ouverte sans le droit payeur');
            }
            if (!$montants && \in_array($nom, self::PAGES_MONTANTS, true) && $ouverte) {
                $bilan->constater(BilanRecette::FUITE, 'montant', $nom, $chemin, $libelle, 'document chiffré ouvert à un compte qui ne voit pas les montants');
            }
            if ($compte->estLectureSeule() && !DroitsEspace::permis($compte, 'GET', $nom) && $ouverte) {
                $bilan->constater(BilanRecette::FUITE, 'lecture', $nom, $chemin, $libelle, 'page qui commande, paie ou écrit ouverte à un compte Lecture · suivi');
            }
            if (Reponse::OUVERT !== $etat || $payeur) {
                continue;
            }
            if ('app_espace_mes_donnees' === $nom) {
                // Qui voit les montants (valideur) a le prix des devis qu'il accepte (MonCompte::construire).
                $cles = array_values(array_filter(self::clesArgent(json_decode($reponse->contenu, true)), static fn (string $c): bool => !$montants || !str_starts_with($c, 'devis.')));
                if ([] !== $cles) {
                    $bilan->constater(BilanRecette::FUITE, 'montant', $nom, $chemin, $libelle, 'le fichier de ses données contient des montants sans le droit payeur : '.implode(', ', \array_slice($cles, 0, 5)));
                }
                continue;
            }
            if (!$montants) {
                $this->verifierSansMontant($libelle, $nom, $chemin, $reponse, $bilan);
                continue;
            }
            if (!$reponse->estHtml() || \in_array($nom, self::PAGES_PRIX, true)) {
                continue;
            }
            // Valideur : les prix des commandes et des devis, pas le solde, les factures ni les paiements.
            $trouves = array_values(array_filter(ContenuSensible::montants($reponse->texte()), static fn (array $m): bool => !self::montantDeDevis($m['contexte']) && null === self::montantVoulu($m['contexte'])));
            if ([] !== $trouves) {
                $argent = array_values(array_filter($trouves, static fn (array $m): bool => 'paiement' === $m['categorie']));
                $exemples = array_map(static fn (array $m): string => '« …'.$m['contexte'].' »', \array_slice([] !== $argent ? $argent : $trouves, 0, 3));
                $bilan->constater([] !== $argent ? BilanRecette::FUITE : BilanRecette::A_EXAMINER, 'montant', $nom, $chemin, $libelle, \sprintf('%d montant(s) sans le droit payeur : %s', \count($trouves), implode(' ; ', $exemples)));
            }
        }
    }

    /**
     * Demandeur ou lecture seule (CompteClient::montantsVisibles faux) : aucun
     * montant dans le texte de la page (sauf les règles d'une promotion), ni
     * dans ses données JSON (`<script type="application/json">`), ni dans
     * une réponse JSON.
     */
    private function verifierSansMontant(string $libelle, string $nom, string $chemin, Reponse $reponse, BilanRecette $bilan): void
    {
        if (!$reponse->estHtml()) {
            $cles = str_contains($reponse->type, 'json') ? self::clesArgent(json_decode($reponse->contenu, true)) : [];
            if ([] !== $cles) {
                $bilan->constater(BilanRecette::FUITE, 'montant', $nom, $chemin, $libelle, 'réponse JSON avec des montants pour un compte qui ne les voit pas : '.implode(', ', \array_slice($cles, 0, 5)));
            }

            return;
        }
        $trouves = \in_array($nom, self::PAGES_REGLES, true) ? [] : ContenuSensible::montants($reponse->texte());
        if ([] !== $trouves) {
            $exemples = array_map(static fn (array $m): string => '« …'.$m['contexte'].' »', \array_slice($trouves, 0, 3));
            $bilan->constater(BilanRecette::FUITE, 'montant', $nom, $chemin, $libelle, \sprintf('%d montant(s) pour un compte qui ne voit pas les montants : %s', \count($trouves), implode(' ; ', $exemples)));
        }
        $cles = self::clesArgentDesScripts($reponse->contenu);
        if ([] !== $cles) {
            $bilan->constater(BilanRecette::FUITE, 'montant', $nom, $chemin, $libelle, 'données de la page avec des montants pour un compte qui ne les voit pas : '.implode(', ', \array_slice($cles, 0, 5)));
        }
    }

    /**
     * Appels de prix d'une page (estimation de Nouvelle commande, calcul de
     * Mes produits, prix de l'atelier 3D) faits par chaque compte qui ne voit
     * pas les montants : refusés, ou une réponse sans aucun montant.
     *
     * @param array<string, CompteClient>                   $comptes
     * @param array<string, MockArraySessionStorage>        $sessions
     * @param array<string, array<string, string|int|null>> $fiches
     */
    private function verifierAppelsSansMontant(array $comptes, array $sessions, array $fiches, BilanRecette $bilan): void
    {
        $produit = $this->nouvelleCommande->produits()[0] ?? null;
        $modele = $this->atelier->modeles()[0] ?? null;
        foreach ($comptes as $libelle => $compte) {
            if ($compte->montantsVisibles() || !isset($sessions[$libelle])) {
                continue;
            }
            $jeton = (string) ($this->jeton($sessions[$libelle]) ?? $this->jeton($sessions['titulaire'] ?? $sessions[$libelle]));
            $entetes = ['X-CSRF-TOKEN' => $jeton];
            $appels = [];
            if (null !== $produit) {
                $chemin = $this->routeur->generate('app_espace_estimer');
                $appels['estimation de Nouvelle commande'] = ['app_espace_estimer', $chemin, $this->navigateur->postJson($chemin, ['produit' => $produit->getId(), 'quantite' => max(1, $produit->getQuantiteMinimale()), 'largeur' => 100, 'hauteur' => 100], $sessions[$libelle], $entetes)];
            }
            if (null !== ($fiches[$libelle]['produit'] ?? null)) {
                $chemin = $this->routeur->generate('app_espace_produits_calcul', ['id' => $fiches[$libelle]['produit']]);
                $appels['calcul de Mes produits'] = ['app_espace_produits_calcul', $chemin, $this->navigateur->postJson($chemin, ['quantite' => '10', 'saisie' => '', 'inferieur' => false], $sessions[$libelle], $entetes)];
                // « Ajouter au panier » (MP04) rend le volet du panier : ni prix ni total.
                $chemin = $this->routeur->generate('app_espace_produits_panier', ['id' => $fiches[$libelle]['produit']]);
                $appels['ajout au panier de Mes produits'] = ['app_espace_produits_panier', $chemin, $this->navigateur->postJson($chemin, ['quantite' => '10', 'saisie' => '', 'inferieur' => false], $sessions[$libelle], $entetes)];
            }
            try {
                $chemin = null !== $modele ? $this->routeur->generate('app_espace_atelier_prix', ['modele' => $modele->getPublicId()->toRfc4122(), 'quantite' => 30, 'faces' => 2]) : null;
            } catch (RoutingException) {
                $chemin = null; // L'atelier 3D a changé d'adresse : la page elle-même est vérifiée avec les autres.
            }
            if (null !== $chemin) {
                $appels['prix de l\'atelier 3D'] = ['app_espace_atelier_prix', $chemin, $this->navigateur->get($chemin, $sessions[$libelle], ['Accept' => 'application/json'])];
            } else {
                $bilan->ignorees['app_espace_atelier_prix ('.$libelle.')'] = null === $modele ? 'aucun article dans l\'atelier 3D' : 'appel de prix de l\'atelier 3D introuvable';
            }
            foreach ($appels as $quoi => [$route, $chemin, $reponse]) {
                // Refusé (lecture seule) ou répondu : seule une réponse avec un montant est une fuite.
                $cles = self::clesArgent(json_decode($reponse->contenu, true));
                $ok = Reponse::ERREUR !== $reponse->etat() && [] === $cles;
                $bilan->controler(\sprintf('Espace client : %s, %s sans montant', $libelle, $quoi), $ok, $chemin.' → '.$reponse->etat().([] !== $cles ? ' : '.implode(', ', \array_slice($cles, 0, 5)) : ''));
                if (!$ok) {
                    $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::FUITE, 'montant', $route, $chemin, $libelle, [] !== $cles ? $quoi.' avec des montants : '.implode(', ', \array_slice($cles, 0, 5)) : (string) $reponse->erreur);
                }
            }
        }
    }

    /**
     * Pièces au m² (docs/espace-client.md, « Quantité en pièces ou en m² ») :
     * l'étape Options d'un produit au m², ouverte en m² (2,5 m² de pièces de
     * 5 × 5 cm), montre l'encadré « Quantité en pièces / m² » à chaque
     * compte qui commande, et l'estimation en m² compte les pièces au m².
     * Pour qui ne voit pas les montants (demandeur) : ni la page ni
     * l'estimation n'ont un montant.
     *
     * @param array<string, CompteClient>            $comptes
     * @param array<string, MockArraySessionStorage> $sessions
     */
    private function verifierPiecesM2(array $comptes, array $sessions, BilanRecette $bilan): void
    {
        $produit = null;
        foreach ($this->nouvelleCommande->produits() as $c) {
            if (FormulaireDemande::piecesAuM2Possible($c)) {
                $produit = $c;
                break;
            }
        }
        if (null === $produit) {
            $bilan->ignorees['app_espace_nouvelle_commande (pièces au m²)'] = 'aucun produit au m² dans le catalogue publié';

            return;
        }
        foreach (['particulier', 'payeur', 'demandeur'] as $libelle) {
            if (!isset($comptes[$libelle], $sessions[$libelle])) {
                continue;
            }
            $compte = $comptes[$libelle];
            $session = $sessions[$libelle];

            $chemin = $this->routeur->generate('app_espace_nouvelle_commande', ['produit' => $produit->getId(), 'largeur' => 5, 'hauteur' => 5, 'unite_quantite' => FormulaireDemande::UNITE_M2, 'quantite_m2' => '2,5']);
            $page = $this->navigateur->get($chemin, $session);
            $encadre = preg_match('/<div\b[^>]*\bid="pm2"[^>]*>/', $page->contenu, $m) && str_contains($m[0], 'data-pieces-m2') && 1 !== preg_match('/\shidden(?:[\s=>]|$)/', $m[0]);
            $ok = Reponse::OUVERT === $page->etat() && $encadre;
            $bilan->controler(\sprintf('Espace client : %s, encadré des pièces au m² de Nouvelle commande', $libelle), $ok, $chemin.' → '.$page->etat().($encadre ? ', encadré montré' : ', encadré absent'));
            if (Reponse::ERREUR === $page->etat()) {
                $bilan->constater(BilanRecette::ERREUR, 'erreur', 'app_espace_nouvelle_commande', $chemin, $libelle, (string) $page->erreur);
            } elseif (!$compte->montantsVisibles() && Reponse::OUVERT === $page->etat()) {
                $this->verifierSansMontant($libelle, 'app_espace_nouvelle_commande', $chemin, $page, $bilan);
            }

            $jeton = (string) $this->jeton($session);
            $chemin = $this->routeur->generate('app_espace_estimer');
            $reponse = $this->navigateur->postJson($chemin, ['produit' => $produit->getId(), 'quantite' => 1, 'largeur' => 5, 'hauteur' => 5, 'unite' => 'cm', 'unite_quantite' => FormulaireDemande::UNITE_M2, 'quantite_m2' => '2,5'], $session, ['X-CSRF-TOKEN' => $jeton]);
            $donnees = json_decode($reponse->contenu, true);
            $lignes = \is_array($donnees) ? array_column((array) ($donnees['recap']['lignes'] ?? []), 'libelle') : [];
            $cles = $compte->montantsVisibles() ? [] : self::clesArgent($donnees);
            $ok = Reponse::OUVERT === $reponse->etat() && \in_array('Pièces au m²', $lignes, true) && [] === $cles;
            $bilan->controler(\sprintf('Espace client : %s, estimation en m² de Nouvelle commande', $libelle), $ok, $chemin.' → '.$reponse->etat().(\in_array('Pièces au m²', $lignes, true) ? ', en pièces au m²' : ', pas en pièces au m²').([] !== $cles ? ' : '.implode(', ', \array_slice($cles, 0, 5)) : ''));
            if (Reponse::ERREUR === $reponse->etat()) {
                $bilan->constater(BilanRecette::ERREUR, 'erreur', 'app_espace_estimer', $chemin, $libelle, (string) $reponse->erreur);
            } elseif ([] !== $cles) {
                $bilan->constater(BilanRecette::FUITE, 'montant', 'app_espace_estimer', $chemin, $libelle, 'estimation en m² avec des montants : '.implode(', ', \array_slice($cles, 0, 5)));
            }
        }
    }

    /**
     * Le compte A demande les fiches du client B par leur numéro, puis
     * glisse ces numéros dans ses envois : tout doit être refusé.
     *
     * @param array<string, string|int|null> $fichesB
     */
    private function verifierFichesAutreClient(string $a, CompteClient $compteA, MockArraySessionStorage $session, string $b, array $fichesB, BilanRecette $bilan): void
    {
        $refusees = 0;
        $essais = 0;
        foreach (self::FICHES_DES_ROUTES as $route => $sorte) {
            $valeur = $fichesB[$sorte] ?? null;
            if (null === $valeur || null === $this->routeur->getRouteCollection()->get($route)) {
                continue;
            }
            $parametres = ['id' => $valeur];
            if ('notification' === $sorte) {
                $parametres = ['cle' => $valeur];
            } elseif ('reglement' === $sorte || 'depot' === $sorte) {
                $parametres = ['reference' => $valeur];
            } elseif ('image' === $sorte) {
                $parametres['taille'] = 'v';
            } elseif ('creation' === $sorte) {
                $parametres['quoi'] = 'rendu-devant';
            } elseif ('fichier_publie' === $sorte) {
                $parametres = ['publicId' => $valeur];
            } elseif ('vocale' === $sorte) {
                $parametres['message'] = $fichesB['vocale_message'] ?? '';
            }
            $chemin = $this->routeur->generate($route, $parametres);
            $reponse = $this->navigateur->get($chemin, $session);
            ++$essais;
            if ($this->refuse($reponse, (string) $valeur)) {
                ++$refusees;
                continue;
            }
            $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::FUITE, 'autre_client', $route, $chemin, $a, \sprintf('fiche « %s » du client %s ouverte (%s%s)', $sorte, $b, $reponse->etat(), '' !== $reponse->redirection ? ' vers '.$reponse->redirection : ''));
        }
        $bilan->controler(\sprintf('Espace client : %s demande %d fiches de %s par leur numéro', $a, $essais, $b), $refusees === $essais, \sprintf('%d refusées sur %d', $refusees, $essais));

        $this->verifierEnvoisAutreClient($a, $compteA, $session, $b, $fichesB, $bilan);
    }

    /**
     * Envois avec le numéro d'une fiche d'un autre client (jeton valide de A).
     *
     * @param array<string, string|int|null> $fichesB
     */
    private function verifierEnvoisAutreClient(string $a, CompteClient $compteA, MockArraySessionStorage $session, string $b, array $fichesB, BilanRecette $bilan): void
    {
        $jeton = $this->jeton($session);
        if (null === $jeton) {
            $bilan->controler('Espace client : jeton des formulaires de '.$a, false, 'introuvable dans la page Nouvelle commande');

            return;
        }
        // Témoin : un envoi permis avec ce jeton passe (sinon les refus ne prouveraient rien).
        $temoin = $this->navigateur->post($this->routeur->generate('app_espace_notifications_tout_lu'), ['_token' => $jeton], $session);
        $bilan->controler('Espace client : envoi témoin de '.$a.' (tout marquer comme lu)', Reponse::REDIRIGE === $temoin->etat(), $temoin->etat());

        $envois = [
            ['app_espace_devis_accepter', 'devis_envoye', [], 'accepter le devis', fn (int|string $v): bool => (bool) $this->em->find(Devis::class, $v)?->estEnAttente()],
            ['app_espace_devis_refuser', 'devis_envoye', ['motif' => 'prix'], 'refuser le devis', fn (int|string $v): bool => (bool) $this->em->find(Devis::class, $v)?->estEnAttente()],
            ['app_espace_fichier_retirer', 'fichier', [], 'retirer le fichier', fn (int|string $v): bool => (bool) $this->em->find(CommandeDetailFichier::class, $v)?->isActif()],
            ['app_espace_commande_valider', 'commande_a_valider', [], 'valider la demande', null],
            ['app_espace_commande_refuser', 'commande_a_valider', ['motif' => 'Recette'], 'refuser la demande', null],
            ['app_espace_adresse_supprimer', 'adresse', [], 'supprimer l\'adresse', fn (int|string $v): bool => (bool) $this->em->find(AdresseLivraison::class, $v)?->isActif()],
            ['app_espace_utilisateur_retirer', 'utilisateur', [], 'retirer la personne', fn (int|string $v): bool => (bool) $this->em->find(CompteClient::class, $v)?->isActif()],
            ['app_espace_conception_supprimer', 'conception', [], 'supprimer la demande de conception', null],
            ['app_espace_bat', 'bat', ['action' => 'valider'], 'valider le BAT', null],
            ['app_espace_payer_orange_money', 'commande', [], 'payer la commande', null],
            ['app_espace_nouvelle_commande_paiement', 'commande', [], 'payer à la commande la commande', null],
            ['app_espace_message_vocale', 'vocale', [], 'envoyer une note vocale dans la conversation', null],
        ];
        foreach ($envois as [$route, $sorte, $donnees, $libelle, $intacte]) {
            $valeur = $fichesB[$sorte] ?? null;
            if (null === $valeur || null === $this->routeur->getRouteCollection()->get($route)) {
                continue;
            }
            $this->em->clear();
            $chemin = $this->routeur->generate($route, ['id' => $valeur]);
            $reponse = $this->navigateur->post($chemin, ['_token' => $jeton] + $donnees, $session);
            $ok = \in_array($reponse->etat(), [Reponse::INTROUVABLE, Reponse::REFUSE], true) && (null === $intacte || $intacte($valeur));
            $bilan->controler(\sprintf('Espace client : %s veut %s de %s', $a, $libelle, $b), $ok, $chemin.' → '.$reponse->etat());
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', $route, $chemin, $a, \sprintf('%s d\'un autre client (%s) : la page répond « %s »', $libelle, $b, $reponse->etat()));
            }
        }

        // Fichier et commande d'un autre client glissés dans une nouvelle commande, un message, une réclamation.
        $fichierB = $fichesB['fichier'] ?? null;
        $commandeB = $fichesB['commande'] ?? null;
        if (null !== $fichierB) {
            $produit = (int) $this->em->getConnection()->fetchOne('SELECT id FROM produit_configuration WHERE active = 1 ORDER BY id LIMIT 1');
            $this->navigateur->post($this->routeur->generate('app_espace_nouvelle_commande'), ['_token' => $jeton, 'produit' => $produit, 'quantite' => 1, 'largeur' => 100, 'hauteur' => 100, 'fichiers' => [$fichierB]], $session);
            $this->em->clear();
            $f = $this->em->find(CommandeDetailFichier::class, $fichierB);
            $ok = null === $f?->getCommandeDetail() || $f->getClient()?->getId() === $f->getCommandeDetail()->getCommande()?->getClients()?->getId();
            $bilan->controler(\sprintf('Espace client : %s met le fichier de %s dans sa commande', $a, $b), $ok);
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_nouvelle_commande', '/espace/commandes/nouvelle', $a, 'le fichier d\'un autre client a été mis dans sa commande');
            }
            // Contrôle automatique (nouvelle commande, étape 3) du fichier d'un autre client : introuvable.
            if (null !== $this->routeur->getRouteCollection()->get('app_espace_nouvelle_commande_controle')) {
                $chemin = $this->routeur->generate('app_espace_nouvelle_commande_controle', ['id' => $fichierB]);
                $reponse = $this->navigateur->postJson($chemin, ['largeur' => '1', 'hauteur' => '1', 'unite' => 'm'], $session, ['X-CSRF-TOKEN' => $jeton]);
                $ok = \in_array($reponse->etat(), [Reponse::INTROUVABLE, Reponse::REFUSE], true);
                $bilan->controler(\sprintf('Espace client : %s fait contrôler le fichier de %s', $a, $b), $ok, $chemin.' → '.$reponse->etat());
                if (!$ok) {
                    $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_nouvelle_commande_controle', $chemin, $a, \sprintf('contrôle du fichier d\'un autre client (%s) : la page répond « %s »', $b, $reponse->etat()));
                }
            }
        }
        if (null !== $commandeB) {
            $avant = $this->compter(MessageClient::class, $commandeB) + $this->compter(ConversationClient::class, $commandeB);
            $this->navigateur->post($this->routeur->generate('app_espace_message_nouvelle'), ['_token' => $jeton, 'texte' => 'Recette des accès', 'document' => 'c:'.$commandeB], $session);
            $ok = $this->compter(MessageClient::class, $commandeB) + $this->compter(ConversationClient::class, $commandeB) === $avant;
            $bilan->controler(\sprintf('Espace client : %s écrit un message sur la commande de %s', $a, $b), $ok);
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_message_nouvelle', '/espace/messages/nouvelle', $a, 'message rattaché à la commande d\'un autre client');
            }
            $avant = $this->compter(ReclamationClient::class, $commandeB);
            $this->navigateur->post($this->routeur->generate('app_espace_reclamation_nouvelle'), ['_token' => $jeton, 'commande' => $commandeB, 'motif' => 'autre', 'objet' => 'Recette', 'description' => 'Recette des accès'], $session);
            $ok = $this->compter(ReclamationClient::class, $commandeB) === $avant;
            $bilan->controler(\sprintf('Espace client : %s fait une réclamation sur la commande de %s', $a, $b), $ok);
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_reclamation_nouvelle', '/espace/reclamations/nouvelle', $a, 'réclamation rattachée à la commande d\'un autre client');
            }
        }
        if (null !== ($fichesB['adresse'] ?? null)) {
            $this->em->clear();
            $avant = $this->em->find(AdresseLivraison::class, $fichesB['adresse'])?->getAdresse();
            $this->navigateur->post($this->routeur->generate('app_espace_adresses'), ['_token' => $jeton, 'id' => $fichesB['adresse'], 'libelle' => 'Recette', 'adresse' => 'Adresse changée par la recette'], $session);
            $this->em->clear();
            $ok = $this->em->find(AdresseLivraison::class, $fichesB['adresse'])?->getAdresse() === $avant;
            $bilan->controler(\sprintf('Espace client : %s modifie l\'adresse de %s', $a, $b), $ok);
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_adresses', '/espace/compte/adresses', $a, 'adresse de livraison d\'un autre client modifiée');
            }
        }
        if (null !== $commandeB) {
            $this->verifierPaiementAutreClient($a, $session, $jeton, $b, (string) $commandeB, $bilan);
        }
    }

    /**
     * La commande d'un autre client glissée dans « Factures et paiements » :
     * le récapitulatif et le départ vers Orange Money répondent
     * « introuvable » (ou refusé sans le droit payeur), et aucun règlement
     * n'est créé.
     */
    private function verifierPaiementAutreClient(string $a, MockArraySessionStorage $session, string $jeton, string $b, string $commandeB, BilanRecette $bilan): void
    {
        $cle = 'c-'.$commandeB;
        $chemin = $this->routeur->generate('app_espace_payer_selection', ['e' => [$cle]]);
        $reponse = $this->navigateur->get($chemin, $session);
        $ok = \in_array($reponse->etat(), [Reponse::INTROUVABLE, Reponse::REFUSE], true);
        $bilan->controler(\sprintf('Espace client : %s prépare le paiement de la commande de %s', $a, $b), $ok, $chemin.' → '.$reponse->etat());
        if (!$ok) {
            $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::FUITE, 'autre_client', 'app_espace_payer_selection', $chemin, $a, 'récapitulatif de paiement d\'une commande d\'un autre client ('.$reponse->etat().')');
        }
        $avant = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reglement_en_ligne');
        $chemin = $this->routeur->generate('app_espace_payer_reglement');
        $reponse = $this->navigateur->post($chemin, ['_token' => $jeton, 'e' => [$cle], 'avoir' => '1', 'total' => '0', 'avoir_montant' => '0'], $session);
        $ok = \in_array($reponse->etat(), [Reponse::INTROUVABLE, Reponse::REFUSE], true) && (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM reglement_en_ligne') === $avant;
        $bilan->controler(\sprintf('Espace client : %s paie la commande de %s', $a, $b), $ok, $chemin.' → '.$reponse->etat());
        if (!$ok) {
            $bilan->constater(BilanRecette::FUITE, 'autre_client_envoi', 'app_espace_payer_reglement', $chemin, $a, 'paiement lancé pour la commande d\'un autre client ('.$reponse->etat().')');
        }
    }

    /**
     * Dans un même compte entreprise : seul le valideur accepte un devis,
     * seul le payeur ouvre les factures et paie, la lecture n'envoie rien.
     *
     * @param array<string, CompteClient>                     $comptes
     * @param array<string, MockArraySessionStorage>          $sessions
     * @param array<string, array<string, string|int|null>>   $fiches
     */
    private function verifierDroitsEntreprise(array $comptes, array $sessions, array $fiches, BilanRecette $bilan): void
    {
        foreach (['payeur', 'demandeur', 'lecture'] as $libelle) {
            $devis = $fiches[$libelle]['devis_envoye'] ?? null;
            if (!isset($comptes[$libelle]) || null === $devis) {
                $bilan->controler('Espace client : '.$libelle.' accepte un devis', false, 'pas de devis envoyé à essayer');
                continue;
            }
            $jeton = $this->jeton($sessions[$libelle]) ?? $this->jeton($sessions['titulaire'] ?? $sessions[$libelle]);
            $this->em->clear();
            $chemin = $this->routeur->generate('app_espace_devis_accepter', ['id' => $devis]);
            $reponse = $this->navigateur->post($chemin, ['_token' => (string) $jeton], $sessions[$libelle]);
            $this->em->clear();
            $ok = \in_array($reponse->etat(), [Reponse::REFUSE, Reponse::INTROUVABLE], true) && (bool) $this->em->find(Devis::class, $devis)?->estEnAttente();
            $bilan->controler('Espace client : '.$libelle.' (sans le droit valideur) accepte un devis', $ok, $chemin.' → '.$reponse->etat());
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'valideur', 'app_espace_devis_accepter', $chemin, $libelle, 'devis accepté sans le droit valideur');
            }
        }
        // Mon solde : un dépôt Orange Money seulement par qui paie ; sinon refusé, et aucun dépôt créé.
        if (null !== $this->routeur->getRouteCollection()->get('app_espace_solde_deposer')) {
            $chemin = $this->routeur->generate('app_espace_solde_deposer');
            foreach (['valideur', 'demandeur', 'lecture'] as $libelle) {
                if (!isset($comptes[$libelle], $sessions[$libelle])) {
                    continue;
                }
                $jeton = $this->jeton($sessions[$libelle]) ?? $this->jeton($sessions['titulaire'] ?? $sessions[$libelle]);
                $avant = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM depot_solde_client');
                $reponse = $this->navigateur->post($chemin, ['_token' => (string) $jeton, 'montant' => '5000', 'total' => '5100'], $sessions[$libelle]);
                $crees = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM depot_solde_client') - $avant;
                $ok = Reponse::REFUSE === $reponse->etat() && 0 === $crees;
                $bilan->controler('Espace client : '.$libelle.' (sans le droit payeur) dépose sur Mon solde', $ok, $chemin.' → '.$reponse->etat().($crees > 0 ? ', dépôt créé' : ''));
                if (!$ok) {
                    $bilan->constater(BilanRecette::FUITE, 'payeur', 'app_espace_solde_deposer', $chemin, $libelle, 'dépôt sur Mon solde lancé sans le droit payeur ('.$reponse->etat().')');
                }
            }
        }
        foreach (['valideur', 'payeur'] as $libelle) {
            $commande = $fiches[$libelle]['commande_a_valider'] ?? null;
            if (!isset($comptes[$libelle], $sessions[$libelle]) || null === $commande || 'valideur' === $libelle) {
                continue;
            }
            $jeton = $this->jeton($sessions[$libelle]);
            $chemin = $this->routeur->generate('app_espace_commande_valider', ['id' => $commande]);
            $reponse = $this->navigateur->post($chemin, ['_token' => (string) $jeton], $sessions[$libelle]);
            $ok = \in_array($reponse->etat(), [Reponse::REFUSE, Reponse::INTROUVABLE], true) || (Reponse::REDIRIGE === $reponse->etat() && $this->attendToujours($commande));
            $bilan->controler('Espace client : '.$libelle.' (sans le droit valideur) valide une demande', $ok, $chemin.' → '.$reponse->etat());
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'valideur', 'app_espace_commande_valider', $chemin, $libelle, 'demande validée sans le droit valideur');
            }
        }
        if (isset($sessions['lecture'])) {
            $jeton = $this->jeton($sessions['titulaire'] ?? $sessions['lecture']);
            $refus = 0;
            $essais = 0;
            foreach (['app_espace_nouvelle_commande' => ['produit' => 1], 'app_espace_message_nouvelle' => ['texte' => 'Recette'], 'app_espace_preferences' => []] as $route => $donnees) {
                $reponse = $this->navigateur->post($this->routeur->generate($route), ['_token' => (string) $jeton] + $donnees, $sessions['lecture']);
                ++$essais;
                $permis = DroitsEspace::permis($comptes['lecture'], 'POST', $route);
                if ($permis || Reponse::REFUSE === $reponse->etat()) {
                    ++$refus;
                } else {
                    $bilan->constater(BilanRecette::FUITE, 'lecture', $route, $this->routeur->generate($route), 'lecture', 'envoi accepté pour un compte Lecture · suivi ('.$reponse->etat().')');
                }
            }
            $bilan->controler('Espace client : envois d\'un compte Lecture · suivi', $refus === $essais, \sprintf('%d sur %d conformes', $refus, $essais));
        }
    }

    /**
     * BAT à valider (maquette CO05) : valider sans avoir coché chaque point
     * de « Avant de valider, vérifiez » est refusé par le serveur (le
     * bouton grisé ne suffit pas) ; un compte Lecture · suivi ne valide pas,
     * même tout coché. Le BAT doit attendre toujours le client. Tout est
     * annulé à la fin de la recette (transaction).
     *
     * @param array<string, CompteClient>                     $comptes
     * @param array<string, MockArraySessionStorage>          $sessions
     * @param array<string, array<string, string|int|null>>   $fiches
     */
    private function verifierBat(array $comptes, array $sessions, array $fiches, BilanRecette $bilan): void
    {
        $tout = array_keys(EspaceClient::verificationsBat(null));
        $essais = [
            // Le BAT en attente du compte entreprise de démonstration (Salon Espoir, CMD-D00768).
            'titulaire' => [\array_slice($tout, 0, 3), 'valide un BAT sans cocher chaque point de « Avant de valider, vérifiez »'],
            'valideur' => [[], 'valide un BAT sans rien cocher'],
            'lecture' => [$tout, '(lecture seule) valide un BAT, tout coché'],
        ];
        foreach ($essais as $libelle => [$cochees, $quoi]) {
            $bat = $fiches[$libelle]['bat_attente'] ?? null;
            if (!isset($comptes[$libelle], $sessions[$libelle]) || null === $bat) {
                $bilan->controler('Espace client : '.$libelle.' '.$quoi, false, 'pas de BAT en attente à essayer');
                continue;
            }
            $jeton = $this->jeton($sessions[$libelle]) ?? $this->jeton($sessions['titulaire'] ?? $sessions[$libelle]);
            $chemin = $this->routeur->generate('app_espace_bat', ['id' => $bat]);
            $reponse = $this->navigateur->post($chemin, ['_token' => (string) $jeton, 'action' => 'valider', 'verifications' => $cochees, 'retour' => 'commande'], $sessions[$libelle]);
            $this->em->clear();
            $ok = ControlePrePresse::STATUT_EN_ATTENTE_BAT === $this->em->find(ControlePrePresse::class, $bat)?->getStatut();
            $bilan->controler('Espace client : '.$libelle.' '.$quoi, $ok, $chemin.' → '.$reponse->etat());
            if (!$ok) {
                $bilan->constater(BilanRecette::FUITE, 'bat', 'app_espace_bat', $chemin, $libelle, 'BAT validé : '.$quoi);
            }
        }
    }

    /**
     * Accès suspendu par l'imprimerie (fiche client, SuspensionsEspace) : la
     * session ouverte de la personne tombe à la page suivante, avec le
     * message ; les autres personnes de l'entreprise entrent toujours. La
     * suspension d'essai est retirée ensuite (et la transaction de la
     * recette est annulée à la fin).
     *
     * @param array<string, CompteClient> $comptes
     */
    private function verifierSuspension(array $comptes, BilanRecette $bilan): void
    {
        $compte = $comptes['demandeur'] ?? null;
        $autre = $comptes['valideur'] ?? $comptes['titulaire'] ?? null;
        $client = $compte?->getClient();
        if (null === $compte || null === $autre || null === $client) {
            $bilan->controler('Espace client : session d\'une personne suspendue', false, 'pas de compte demandeur dans la démonstration');

            return;
        }
        $session = $this->navigateur->session($compte, 'espace', [AppareilsClient::SESSION_VERSION => $compte->getVersionSession()]);
        $sessionAutre = $this->navigateur->session($autre, 'espace', [AppareilsClient::SESSION_VERSION => $autre->getVersionSession()]);
        $chemin = $this->routeur->generate('app_espace_accueil');
        $avant = $this->navigateur->get($chemin, $session)->etat();

        // Les vérifications d'avant vident l'EntityManager : la fiche est reprise.
        $fiche = $this->em->getReference(Clients::class, $client->getId());
        $suspension = (new SuspensionEspace())->setClient($fiche)->setTelephone($compte->getTelephone())->setMotif('Recette des accès');
        $this->em->persist($suspension);
        $this->em->flush();
        try {
            $coupee = $this->navigateur->get($chemin, $session);
            $toujours = $this->navigateur->get($chemin, $sessionAutre)->etat();
        } finally {
            // Les pages demandées vident l'EntityManager : retirée par son identifiant.
            $this->em->createQuery('DELETE FROM '.SuspensionEspace::class.' s WHERE s.id = :id')->setParameter('id', $suspension->getId())->execute();
        }
        $ok = Reponse::OUVERT === $avant && Reponse::CONNEXION === $coupee->etat() && Reponse::OUVERT === $toujours;
        $bilan->controler('Espace client : session d\'une personne suspendue', $ok, \sprintf('avant %s, suspendue %s, une autre personne %s', $avant, $coupee->etat(), $toujours));
        if (!$ok && Reponse::CONNEXION !== $coupee->etat()) {
            $bilan->constater(BilanRecette::FUITE, 'suspension', 'app_espace_accueil', $chemin, 'demandeur', 'page ouverte malgré l\'accès suspendu ('.$coupee->etat().')');
        }
    }

    /**
     * Sur le site en ligne, sans appareil autorisé : chaque page de l'admin
     * renvoie à la connexion (ou n'existe pas), l'API répond « introuvable »,
     * et un mot de passe juste ne suffit pas.
     */
    private function verifierAdminEnLigne(BilanRecette $bilan): void
    {
        $ouvertes = [];
        $n = 0;
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            if (!RecetteAcces::estPageAdmin($nom, $route) || \in_array($nom, RecetteAcces::ROUTES_EXCLUES, true)) {
                continue;
            }
            $jeux = $this->parametres->remplir($nom, $route);
            if (\is_string($jeux)) {
                continue;
            }
            $chemin = $this->routeur->generate($nom, $jeux[0]);
            $etat = $this->navigateur->get($chemin)->etat();
            ++$n;
            if (!\in_array($etat, [Reponse::CONNEXION, Reponse::INTROUVABLE, Reponse::REFUSE], true)) {
                $ouvertes[] = $chemin.' ('.$etat.')';
                $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'admin_en_ligne', $nom, $chemin, 'visiteur', 'page de l\'admin en ligne sans connexion : '.$etat);
            }
        }
        $bilan->controler('En ligne : pages de l\'admin sans connexion', [] === $ouvertes, \sprintf('%d pages demandées, %d ouvertes', $n, \count($ouvertes)));

        foreach (['/api/v1', '/api/v1/docs', '/api/v1/commandes', '/api/v1/clients'] as $chemin) {
            $etat = $this->navigateur->get($chemin, null, ['Accept' => 'application/json'])->etat();
            $bilan->controler('En ligne : '.$chemin.' répond « introuvable »', Reponse::INTROUVABLE === $etat, $etat);
        }

        // Le bon mot de passe depuis un appareil non enrôlé : la session est fermée à la première page.
        $direction = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.kadiatou']);
        if (null !== $direction) {
            $session = $this->navigateur->session($direction);
            foreach (['/', '/clients', '/finances', '/corbeille'] as $chemin) {
                $etat = $this->navigateur->get($chemin, $session)->etat();
                $bilan->controler('En ligne : direction connectée depuis un appareil non autorisé ouvre '.$chemin, \in_array($etat, [Reponse::CONNEXION, Reponse::REFUSE, Reponse::INTROUVABLE], true), $etat);
            }
        }
    }

    private function refuse(Reponse $reponse, string $valeur): bool
    {
        return match ($reponse->etat()) {
            Reponse::INTROUVABLE, Reponse::REFUSE, Reponse::CONNEXION => true,
            // Renvoyé vers une liste (notification inconnue) : pas vers la fiche ni son PDF.
            Reponse::REDIRIGE => !str_contains($reponse->redirection, '/pdf-public/') && !str_contains($reponse->redirection, $valeur),
            default => false,
        };
    }

    /** Jeton des formulaires de l'espace, lu dans une page de la session. */
    private function jeton(MockArraySessionStorage $session): ?string
    {
        foreach (['app_espace_nouvelle_commande', 'app_espace_reclamation_nouvelle', 'app_espace_compte'] as $route) {
            $page = $this->navigateur->get($this->routeur->generate($route), $session);
            if (preg_match('/name="_token" value="([^"]+)"/', $page->contenu, $m)) {
                return html_entity_decode($m[1]);
            }
        }

        return null;
    }

    private function attendToujours(string $publicId): bool
    {
        $this->em->clear();
        foreach ($this->em->getRepository(Commandes::class)->findBy(['canal' => Commandes::CANAL_EN_LIGNE], ['id' => 'DESC'], 200) as $c) {
            if (self::uuid($c->getPublicId()) === $publicId) {
                return $c->attendValidationEntreprise();
            }
        }

        return true;
    }

    /** @param class-string $classe */
    private function compter(string $classe, string $commandePublicId): int
    {
        $this->em->clear();

        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from($classe, 'e')->join('e.commande', 'c')
            ->where('c.publicId = :p')->setParameter('p', $commandePublicId, 'uuid')->getQuery()->getSingleScalarResult();
    }

    /**
     * @param class-string $classe
     */
    private function premiere(string $classe, Clients $client, string $condition = '1 = 1', string $champ = 'id'): string|int|null
    {
        $ligne = $this->em->createQueryBuilder()->select('e.'.$champ)->from($classe, 'e')
            ->where('e.client = :cl')->andWhere($condition)->setParameter('cl', $client)
            ->orderBy('e.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $valeur = $ligne[$champ] ?? null;

        return $valeur instanceof AbstractUid ? $valeur->toRfc4122() : $valeur;
    }

    /** Un bon de livraison du client (suivi ou preuve), le plus récent. */
    private function livraison(Clients $client): ?string
    {
        $ligne = $this->em->createQueryBuilder()->select('bl.publicId')->from(BonLivraison::class, 'bl')->join('bl.commande', 'c')
            ->where('c.clients = :cl')->andWhere('bl.statut != :annule')->setParameter('cl', $client)->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->orderBy('bl.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return null === $ligne ? null : self::uuid($ligne['publicId']);
    }

    /** Un paiement validé du client (son reçu existe), le plus récent. */
    private function paiementValide(Clients $client): ?string
    {
        $ligne = $this->em->createQueryBuilder()->select('p.publicId')->from(Paiements::class, 'p')->join('p.commande', 'c')
            ->where('c.clients = :cl')->andWhere('p.statut = :valide')->setParameter('cl', $client)->setParameter('valide', Paiements::STATUT_VALIDE)
            ->orderBy('p.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return null === $ligne ? null : self::uuid($ligne['publicId']);
    }

    private function photo(Clients $client): ?string
    {
        $ligne = $this->em->createQueryBuilder()->select('p.publicId')->from(ReclamationClientPhoto::class, 'p')->join('p.reclamation', 'r')
            ->where('r.client = :cl')->setParameter('cl', $client)->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return null === $ligne ? null : self::uuid($ligne['publicId']);
    }

    private function autreCompte(CompteClient $compte): ?int
    {
        foreach ($this->em->getRepository(CompteClient::class)->findBy(['client' => $compte->getClient(), 'actif' => true]) as $k) {
            if ($k->getId() !== $compte->getId()) {
                return $k->getId();
            }
        }

        return null;
    }

    private static function uuid(mixed $valeur): ?string
    {
        return $valeur instanceof AbstractUid ? $valeur->toRfc4122() : (null === $valeur ? null : (string) $valeur);
    }

    /**
     * Clés d'argent non vides d'un JSON (« Télécharger mes données », appel
     * de prix). Un booléen (« montants » : false) n'est pas un montant.
     *
     * @return list<string>
     */
    public static function clesArgent(mixed $donnees, string $chemin = ''): array
    {
        if (!\is_array($donnees)) {
            return [];
        }
        $trouvees = [];
        foreach ($donnees as $cle => $valeur) {
            $ici = '' === $chemin ? (string) $cle : $chemin.'.'.$cle;
            // Une adresse du site (« urls.prix » de l'atelier 3D) n'est pas un montant.
            $adresse = \is_string($valeur) && str_starts_with($valeur, '/');
            if (\is_string($cle) && preg_match(self::CLES_ARGENT, $cle) && null !== $valeur && [] !== $valeur && '' !== $valeur && !\is_bool($valeur) && !$adresse) {
                $trouvees[] = $ici;
                continue;
            }
            array_push($trouvees, ...self::clesArgent($valeur, $ici));
        }

        return $trouvees;
    }

    /**
     * Clés d'argent des données JSON glissées dans une page
     * (`<script type="application/json">`), que le navigateur reçoit même
     * si elles ne s'affichent pas.
     *
     * @return list<string>
     */
    public static function clesArgentDesScripts(string $html): array
    {
        preg_match_all('#<script\b[^>]*type="application/json"[^>]*>(.*?)</script>#is', $html, $m);
        $cles = [];
        foreach ($m[1] as $i => $json) {
            foreach (self::clesArgent(json_decode(html_entity_decode($json), true)) as $c) {
                $cles[] = 'script'.$i.'.'.$c;
            }
        }

        return $cles;
    }

    /** Raison pour laquelle ce montant est voulu pour qui voit les montants (MONTANTS_VOULUS), ou null. */
    public static function montantVoulu(string $contexte): ?string
    {
        foreach (self::MONTANTS_VOULUS as $motif => $raison) {
            if (preg_match($motif, $contexte)) {
                return $raison;
            }
        }

        return null;
    }

    /** Montant d'un devis (« Nouveau devis DV-… · 31 000 F », « Devis … ») : voulu pour le valideur. */
    public static function montantDeDevis(string $contexte): bool
    {
        // DV- : devis de démonstration ; DEV- : numérotation réelle (DevisController::new).
        return 1 === preg_match('/\bdevis\b|\bDV-|\bDEV-/iu', $contexte);
    }

    private static function masquer(string $telephone): string
    {
        return substr($telephone, 0, -6).'••••'.substr($telephone, -2);
    }
}
