<?php

namespace App\Service\EspaceClient;

use App\Controller\UtilisateursController;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ce que la coque de l'espace client montre sur chaque page (maquettes
 * EC01 à EC05) : la personne connectée, le menu de gauche et ses
 * pastilles, les onglets du téléphone, la cloche, la carte « Votre
 * conseiller(ère) ». Calculé une fois par requête, toujours selon les
 * droits de la personne : pas de factures pour qui ne paie pas, rien qui
 * écrive pour un compte « Lecture · suivi ».
 *
 * Vidée entre deux requêtes (ResetInterface) : un processus qui sert
 * plusieurs personnes (tests, recette des accès, serveur qui garde PHP en
 * mémoire) ne montre jamais la coque de l'une à l'autre.
 */
final class CoqueEspace implements ResetInterface
{
    /**
     * Menu de gauche, dans l'ordre d'affichage. Ajouter une entrée = ajouter
     * une ligne ici :
     *  - cle : identifiant (pastille, entrée allumée) ;
     *  - route, libelle, icone (tracé SVG 24 × 24) ;
     *  - pages : préfixes des routes qui allument l'entrée ;
     *  - visible : qui la voit (VISIBLE_*) ;
     *  - onglet : onglet du téléphone allumé sur ces pages (clé de ONGLETS).
     *
     * @var list<array{cle: string, route: string, libelle: string, icone: string, pages: list<string>, visible: string, onglet: string}>
     */
    public const MENU = [
        ['cle' => 'tableau', 'route' => 'app_espace_accueil', 'libelle' => 'Tableau de bord', 'icone' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
            'pages' => ['app_espace_accueil', 'app_espace_recherche'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'tableau'],
        ['cle' => 'notifications', 'route' => 'app_espace_notifications', 'libelle' => 'Notifications', 'icone' => 'M6 16V11a6 6 0 1 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0',
            'pages' => ['app_espace_notification'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'tableau'],
        ['cle' => 'nouvelle', 'route' => 'app_espace_nouvelle_commande', 'libelle' => 'Nouvelle commande', 'icone' => 'M12 3 20 7.5v9L12 21l-8-4.5v-9zM4 7.5l8 4.5 8-4.5M12 12v9',
            'pages' => ['app_espace_nouvelle_commande'], 'visible' => self::VISIBLE_ECRIT, 'onglet' => 'commandes'],
        // Lecture · suivi : la liste et le suivi des demandes, sans les préparer.
        ['cle' => 'conception', 'route' => 'app_espace_conception', 'libelle' => 'Demande de conception', 'icone' => 'M12 19l7-7 3 3-7 7zM18 13l-1.5-7.5L2 2l3.5 14.5L13 18zM2 2l7.6 7.6M13 11a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
            'pages' => ['app_espace_conception'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'commandes'],
        // Atelier 3D (docs/atelier-3d.md) : modèles validés, à rouvrir ou à commander à nouveau.
        ['cle' => 'creations', 'route' => 'app_espace_creations', 'libelle' => 'Mes créations', 'icone' => 'M8.5 3 4 5 2 9.5l3 1.8 1-1.3V21h12V10l1 1.3 3-1.8L20 5l-4.5-2c-.6 1.6-1.9 2.6-3.5 2.6S9.1 4.6 8.5 3z',
            'pages' => ['app_espace_creation', 'app_espace_atelier'], 'visible' => self::VISIBLE_ECRIT, 'onglet' => 'commandes'],
        ['cle' => 'produits', 'route' => 'app_espace_produits', 'libelle' => 'Mes produits', 'icone' => 'M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z',
            'pages' => ['app_espace_produits'], 'visible' => self::VISIBLE_ECRIT, 'onglet' => 'commandes'],
        ['cle' => 'devis', 'route' => 'app_espace_devis', 'libelle' => 'Mes devis', 'icone' => 'M6 2h9l5 5v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm8 1.5V8h4.5M8 13h8M8 17h5',
            'pages' => ['app_espace_devis'], 'visible' => self::VISIBLE_ECRIT, 'onglet' => 'commandes'],
        ['cle' => 'commandes', 'route' => 'app_espace_commandes', 'libelle' => 'Mes commandes', 'icone' => 'M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9zM7.5 7.5h.01',
            'pages' => ['app_espace_commande', 'app_espace_avis_commande'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'commandes'],
        ['cle' => 'fichiers', 'route' => 'app_espace_fichiers', 'libelle' => 'Fichiers et BAT', 'icone' => 'M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1M15 4v4M9 10v4M17 16v4',
            'pages' => ['app_espace_fichier', 'app_espace_bat'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'fichiers'],
        ['cle' => 'factures', 'route' => 'app_espace_factures', 'libelle' => 'Factures et paiements', 'icone' => 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M7 15h4',
            'pages' => ['app_espace_facture', 'app_espace_payer'], 'visible' => self::VISIBLE_PAYEUR, 'onglet' => 'factures'],
        ['cle' => 'livraisons', 'route' => 'app_espace_livraisons', 'libelle' => 'Livraisons', 'icone' => 'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM4 9h16M8 2v4M16 2v4',
            'pages' => ['app_espace_livraisons'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'commandes'],
        ['cle' => 'messages', 'route' => 'app_espace_messages', 'libelle' => 'Messages', 'icone' => 'M4 5h16v11H9l-5 4z',
            'pages' => ['app_espace_message'], 'visible' => self::VISIBLE_ECRIT, 'onglet' => 'compte'],
        ['cle' => 'promotions', 'route' => 'app_espace_promotions', 'libelle' => 'Mes promotions', 'icone' => 'M20 12 12 20l-8-8V4h8zM8 8h.01M9 15l6-6',
            'pages' => ['app_espace_promotions'], 'visible' => self::VISIBLE_PROMOTIONS, 'onglet' => 'compte'],
        ['cle' => 'fidelite', 'route' => 'app_espace_fidelite', 'libelle' => 'Fidélité', 'icone' => 'M4 10h16v10H4zM3 7h18v3H3zM12 7v13M12 7c-1.5-3-5-3-5-1s3 1 5 1zm0 0c1.5-3 5-3 5-1s-3 1-5 1z',
            'pages' => ['app_espace_fidelite'], 'visible' => self::VISIBLE_FIDELITE, 'onglet' => 'compte'],
        ['cle' => 'compte', 'route' => 'app_espace_compte', 'libelle' => 'Mon compte', 'icone' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7.5M22 21a7 7 0 0 0-4-6.3',
            'pages' => ['app_espace_compte', 'app_espace_adresse', 'app_espace_utilisateur', 'app_espace_reclamation', 'app_espace_avis', 'app_espace_mes_donnees'], 'visible' => self::VISIBLE_TOUS, 'onglet' => 'compte'],
    ];

    /** Qui voit une entrée du menu. */
    public const VISIBLE_TOUS = 'tous';
    /** Pas les comptes « Lecture · suivi » (commander, devis, messages). */
    public const VISIBLE_ECRIT = 'ecrit';
    public const VISIBLE_PAYEUR = 'payeur';
    /** Programme fidélité : hors grille revendeur (B2B), pour qui paie. */
    public const VISIBLE_FIDELITE = 'fidelite';
    /** Promotions : tout client hors grille revendeur (B2B). */
    public const VISIBLE_PROMOTIONS = 'promotions';

    /** Onglets du bas sur téléphone (maquette EC05). */
    private const ONGLETS = [
        'tableau' => ['app_espace_accueil', 'Accueil', 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
        'commandes' => ['app_espace_commandes', 'Commandes', 'M6 2h9l5 5v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm8 1.5V8h4.5M8 12h8M8 16h8'],
        'fichiers' => ['app_espace_fichiers', 'Fichiers', 'M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
        'factures' => ['app_espace_factures', 'Factures', 'M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM8 8h8M8 12h8M8 16h5'],
        'compte' => ['app_espace_compte', 'Compte', 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm-8 9a8 8 0 0 1 16 0'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly Security $security,
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
        private readonly FilNotifications $fil,
        private readonly EntrepriseRepository $entreprises,
        private readonly RequestStack $requetes,
    ) {
    }

    public function reset(): void
    {
        $this->memo = null;
    }

    /** @return array<string, mixed>|null rien hors de l'espace client connecté */
    public function donnees(): ?array
    {
        if (null !== $this->memo) {
            return $this->memo;
        }
        $session = $this->security->getUser();
        $compte = $session instanceof CompteClient && null !== $session->getId() ? $this->comptes->find($session->getId()) : null;
        $client = $compte?->getClient();
        if (!$compte instanceof CompteClient || !$client instanceof Clients) {
            return null;
        }
        $maintenant = new \DateTimeImmutable();
        $droits = self::droits($compte);
        $nonLues = $this->fil->nonLues($compte, $maintenant);
        $pastilles = [
            'notifications' => ['nombre' => $nonLues, 'couleur' => 'rouge'],
            'devis' => ['nombre' => $droits['valideur'] ? \count(array_filter($this->espace->devis($client), static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())) : 0, 'couleur' => 'jaune'],
            'fichiers' => ['nombre' => $droits['lecture'] ? 0 : \count($this->espace->bats($client, true)), 'couleur' => 'jaune'],
            'factures' => ['nombre' => $droits['payeur'] ? \count($this->espace->solde($client)['commandes']) : 0, 'couleur' => 'jaune'],
        ];
        $entree = self::entreeDeLaRoute((string) $this->requetes->getMainRequest()?->attributes->get('_route'));
        $actif = $entree['cle'] ?? null;
        $menu = [];
        foreach (self::MENU as $e) {
            if (self::entreeVisible($e, $droits)) {
                $menu[] = ['cle' => $e['cle'], 'route' => $e['route'], 'libelle' => $e['libelle'], 'icone' => $e['icone'], 'pastille' => $pastilles[$e['cle']] ?? null, 'actif' => $e['cle'] === $actif];
            }
        }
        // Sur téléphone, chaque page allume l'un des cinq onglets.
        $onglet = $entree['onglet'] ?? 'compte';
        $onglets = [];
        foreach (self::ONGLETS as $cle => [$route, $libelle, $icone]) {
            if ('factures' !== $cle || $droits['payeur']) {
                $onglets[] = ['cle' => $cle, 'route' => $route, 'libelle' => $libelle, 'icone' => $icone, 'actif' => $cle === $onglet];
            }
        }
        $entreprise = CompteClient::clientAPlusieursComptes($client);
        $personne = $compte->getNomAffiche();
        $nomClient = $entreprise ? (string) ($client->getRaisonSociale() ?: $client->getNomComplet()) : $client->getNomComplet();

        return $this->memo = $droits + [
            'compte' => $compte,
            'client' => $client,
            'entreprise' => $entreprise,
            'personne' => $personne,
            'prenom' => self::prenom($compte),
            'nomClient' => $nomClient,
            // L'avatar porte les initiales de l'entreprise, ou de la personne.
            'initiales' => CompteClient::initiales($entreprise ? $nomClient : $personne),
            'sousTitre' => $entreprise ? implode(' · ', array_filter([$nomClient, $compte->getFonction() ?? ($droits['lecture'] ? 'Lecture · suivi' : null)])) : ($compte->getFonction() ?? 'Client particulier'),
            'menu' => $menu,
            'onglets' => $onglets,
            'actif' => $actif,
            'nonLues' => $nonLues,
            'cloche' => \array_slice($this->fil->pour($compte, $maintenant), 0, 6),
            'conseiller' => $this->conseiller($client),
            'maintenant' => $maintenant,
        ];
    }

    /**
     * Droits de la personne, en clair pour les gabarits.
     *
     * @return array{lecture: bool, payeur: bool, valideur: bool, administrateur: bool, ecrit: bool, fidelite: bool, promotions: bool}
     */
    public static function droits(CompteClient $compte): array
    {
        $client = $compte->getClient();

        return [
            'lecture' => $compte->estLectureSeule(),
            'payeur' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'valideur' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'administrateur' => $compte->peut(CompteClient::DROIT_ADMINISTRATEUR),
            'ecrit' => $compte->peutEcrire(),
            // Programme fidélité : tout client hors grille revendeur (B2B), pour qui paie.
            'fidelite' => null !== $client && !$client->isB2B() && $compte->peut(CompteClient::DROIT_PAYEUR),
            // Les promotions ne s'ajoutent pas aux prix revendeur (docs/promotions.md).
            'promotions' => null !== $client && !$client->isB2B(),
        ];
    }

    /**
     * Entrées du menu que la personne peut ouvrir, dans l'ordre.
     *
     * @return list<string>
     */
    public static function entreesPermises(CompteClient $compte): array
    {
        $d = self::droits($compte);

        return array_values(array_map(
            static fn (array $e): string => $e['cle'],
            array_filter(self::MENU, static fn (array $e): bool => self::entreeVisible($e, $d)),
        ));
    }

    /** @param array{lecture: bool, payeur: bool, fidelite: bool, promotions: bool} $droits */
    private static function entreeVisible(array $entree, array $droits): bool
    {
        return match ($entree['visible']) {
            self::VISIBLE_ECRIT => !$droits['lecture'],
            self::VISIBLE_PAYEUR => $droits['payeur'],
            self::VISIBLE_FIDELITE => $droits['fidelite'],
            self::VISIBLE_PROMOTIONS => $droits['promotions'],
            default => true,
        };
    }

    /**
     * L'entrée du menu allumée pour cette route : le plus long préfixe de
     * ses « pages » l'emporte (app_espace_commandes et app_espace_commande).
     *
     * @return array{cle: string, onglet: string}|null
     */
    public static function entreeDeLaRoute(string $route): ?array
    {
        $trouvee = null;
        $longueur = 0;
        foreach (self::MENU as $e) {
            foreach ($e['pages'] as $prefixe) {
                if (str_starts_with($route, $prefixe) && \strlen($prefixe) > $longueur) {
                    $trouvee = ['cle' => $e['cle'], 'onglet' => $e['onglet']];
                    $longueur = \strlen($prefixe);
                }
            }
        }

        return $trouvee;
    }

    /** « Ibrahim » : le prénom de la personne (compte d'entreprise) ou du client. */
    public static function prenom(CompteClient $compte): string
    {
        $nom = trim((string) $compte->getNom());
        if ('' !== $nom) {
            return explode(' ', $nom)[0];
        }
        $c = $compte->getClient();

        return (string) ($c?->getPrenom() ?: $c?->getNom() ?: $c?->getRaisonSociale());
    }

    /**
     * Carte « Votre conseiller(ère) » : prénom et nom de la personne qui
     * suit le client (champ conseiller, seul renseignement synchronisé sur
     * l'employé). WhatsApp et Appeler vont au numéro de la boutique, jamais
     * au téléphone personnel. Rien si personne ne suit le client.
     *
     * @return array{nom: string, initiales: string, whatsapp: ?string, appeler: ?string}|null
     */
    public function conseiller(Clients $client): ?array
    {
        $nom = trim((string) $client->getConseiller());
        if ('' === $nom) {
            return null;
        }
        $telephone = null;
        foreach ($this->entreprises->dansLOrdre() as $e) {
            if ('' !== trim((string) $e->getTelephone())) {
                $telephone = UtilisateursController::numeroWhatsApp((string) $e->getTelephone());
                break;
            }
        }

        return [
            'nom' => $nom,
            'initiales' => CompteClient::initiales($nom),
            'whatsapp' => null !== $telephone ? 'https://wa.me/'.$telephone.'?text='.rawurlencode(\sprintf('Bonjour, je souhaite parler à %s.', $nom)) : null,
            'appeler' => null !== $telephone ? 'tel:+'.$telephone : null,
        ];
    }
}
