<?php

namespace App\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\DemandeDevis;
use App\Entity\Factures;
use App\Entity\ProduitConfiguration;
use App\Entity\Promotion;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Paiement\OrangeMoney;
use App\Service\Site\Calculateur;
use App\Service\Site\CatalogueSite;
use App\Service\Site\FormulaireDemande;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Outils que le modèle peut appeler (docs/assistant-client.md). Ils
 * tournent ici, sur le serveur : le modèle ne lit jamais la base.
 *
 * Le site a les outils publics (catalogue au tarif public, boutique,
 * pages, promotions de tout le monde, conseiller). L'espace client y
 * ajoute ceux du compte connecté : la fiche vient toujours de la session
 * (ContexteAssistant), et les montants ne sortent que pour qui paie.
 *
 * Les définitions ne changent pas d'une question à l'autre : elles font
 * partie du début de requête mis en cache.
 */
final class OutilsAssistant
{
    public const OUTILS_SITE = ['chercher_produits', 'estimer_prix', 'infos_boutique', 'pages_du_site', 'promotions_en_cours', 'passer_a_un_agent'];
    public const OUTILS_ESPACE = [...self::OUTILS_SITE, 'mes_commandes', 'reste_a_payer', 'mes_produits', 'preparer_demande_devis'];

    /** Sujets de l'écran Analyse. */
    public const LIBELLES = [
        'chercher_produits' => 'Produits du catalogue',
        'estimer_prix' => 'Prix (calculateur)',
        'infos_boutique' => 'Horaires, adresse, fichiers, délais',
        'pages_du_site' => 'Pages du site (FAQ, guide…)',
        'promotions_en_cours' => 'Promotions',
        'passer_a_un_agent' => 'Passage à un conseiller',
        'mes_commandes' => 'Suivi de commande',
        'reste_a_payer' => 'Reste à payer',
        'mes_produits' => 'Mes produits',
        'preparer_demande_devis' => 'Demande de devis préparée',
    ];

    public const MOTIFS_AGENT = ['question_sans_reponse', 'demande_du_client', 'argent', 'reclamation', 'autre'];

    public const RESUME_MAX = 500;
    public const MESSAGE_DEVIS_MAX = 1000;
    public const COTE_MAX_CM = 5000;
    public const PRODUITS_MAX = 8;
    public const COMMANDES_MAX = 5;
    public const PAGE_MAX = 3500;

    private const MOTS_VIDES = ['le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'au', 'aux', 'pour', 'avec', 'sur', 'en', 'et', 'ou', 'a', 'quel', 'quelle', 'quels', 'quelles',
        'prix', 'combien', 'cout', 'coute', 'coutent', 'tarif', 'tarifs', 'je', 'j', 'veux', 'voudrais', 'besoin', 'faire', 'imprimer', 'svp', 'merci', 'bonjour', 'vous', 'avez', 'est', 'ce', 'que', 'qui', 'mon', 'ma', 'mes'];

    public function __construct(
        private readonly SourceSite $site,
        private readonly SourceEspace $espace,
        private readonly ReglagesAssistant $reglages,
        #[Autowire(service: 'limiter.assistant_devis_compte')] private readonly RateLimiterFactoryInterface $limiteDevis,
    ) {
    }

    /** @return list<string> */
    public static function noms(string $contexte): array
    {
        return AssistantConversation::ESPACE === $contexte ? self::OUTILS_ESPACE : self::OUTILS_SITE;
    }

    /**
     * Définitions envoyées à l'API, toujours les mêmes pour un contexte.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(string $contexte): array
    {
        $tous = [
            'chercher_produits' => [
                'Cherche dans le catalogue publié de Succès Imprim. Renvoie au plus 8 produits avec leur référence (ref), leur prix de départ au tarif public, si une taille est à donner, la quantité minimale et les finitions possibles. Appelle-le avant de parler d\'un produit ou d\'un prix, et pour connaître la référence à donner à estimer_prix.',
                ['texte' => ['type' => 'string', 'description' => 'Mots du produit cherché, par exemple « bâche », « cartes de visite », « t-shirt ». Vide : les premiers produits du catalogue.']],
            ],
            'estimer_prix' => [
                'Calcule le prix d\'un produit au tarif public, comme le calculateur du site : total et détail. Donne la référence trouvée avec chercher_produits, la quantité, la largeur et la hauteur en centimètres (0 si le produit n\'a pas de taille à donner) et les références des finitions voulues (liste vide sinon). C\'est une estimation : le prix ferme est celui du devis.',
                [
                    'produit' => ['type' => 'integer', 'description' => 'Référence (ref) du produit, donnée par chercher_produits.'],
                    'quantite' => ['type' => 'integer', 'description' => 'Nombre d\'exemplaires.'],
                    'largeur_cm' => ['type' => 'number', 'description' => 'Largeur en cm, 0 si sans objet.'],
                    'hauteur_cm' => ['type' => 'number', 'description' => 'Hauteur (ou longueur) en cm, 0 si sans objet.'],
                    'finitions' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Références des finitions choisies.'],
                ],
            ],
            'infos_boutique' => [
                'Coordonnées publiques de la boutique (adresse, téléphone, e-mail), horaires et ouverture en ce moment, formats de fichiers acceptés, délais à annoncer, moyens de suivre une commande.',
                [],
            ],
            'pages_du_site' => [
                'Texte d\'une page publiée du site : questions fréquentes (faq), guide pour préparer ses fichiers (guide-fichiers), engagements de l\'atelier (nos-engagements), présentation (a-propos), machines et savoir-faire (equipements).',
                ['page' => ['type' => 'string', 'enum' => array_keys(SourceSiteApplication::PAGES), 'description' => 'Page à lire.']],
            ],
            'promotions_en_cours' => [
                'Promotions en cours : sur le site, celles faites à tout le monde ; dans l\'espace client, celles de ce client. Avantage, articles concernés, date de fin et conditions.',
                [],
            ],
            'passer_a_un_agent' => [
                'Propose un conseiller de l\'atelier sur WhatsApp : un bouton s\'affiche sous ta réponse avec ton résumé déjà écrit. À appeler quand les outils ne donnent pas la réponse, quand la personne le demande ou est mécontente, et pour tout ce qui touche à l\'argent (remise, annulation, remboursement, litige).',
                [
                    'motif' => ['type' => 'string', 'enum' => self::MOTIFS_AGENT, 'description' => 'Pourquoi un conseiller.'],
                    'resume' => ['type' => 'string', 'description' => 'Résumé de la demande en une ou deux phrases, écrit pour l\'atelier, à la première personne du client (sans mot de passe ni code).'],
                ],
            ],
            'mes_commandes' => [
                'Commandes du client connecté : étape en cours, étapes faites, BAT à valider, livraison prévue ; montants seulement si ce compte paie. Sans numéro : les 5 plus récentes.',
                ['numero' => ['type' => 'string', 'description' => 'Numéro de commande cité par le client (ex. CMD-00012), ou vide.']],
            ],
            'reste_a_payer' => [
                'Ce qui reste à payer sur les commandes en cours et les factures à régler du client connecté. Refusé si ce compte ne paie pas.',
                [],
            ],
            'mes_produits' => [
                'Produits enregistrés pour le client connecté (« Mes produits ») : nom, format, et prix si ce compte paie.',
                [],
            ],
            'preparer_demande_devis' => [
                'Enregistre une demande de devis pour le client connecté, la même que le formulaire du site : l\'atelier prépare le devis et le met dans l\'espace client. Récapitule d\'abord et attends l\'accord de la personne. Produit 0 si aucun produit du catalogue ne convient (décris alors le projet dans le message).',
                [
                    'produit' => ['type' => 'integer', 'description' => 'Référence du produit (chercher_produits), ou 0.'],
                    'quantite' => ['type' => 'integer', 'description' => 'Quantité, ou 0 si elle n\'est pas connue.'],
                    'largeur_cm' => ['type' => 'number', 'description' => 'Largeur en cm, 0 si sans objet.'],
                    'hauteur_cm' => ['type' => 'number', 'description' => 'Hauteur en cm, 0 si sans objet.'],
                    'finitions' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Références des finitions choisies.'],
                    'message' => ['type' => 'string', 'description' => 'Le projet en quelques phrases : usage, délai souhaité par le client, questions.'],
                ],
            ],
        ];

        $definitions = [];
        foreach (self::noms($contexte) as $nom) {
            [$description, $proprietes] = $tous[$nom];
            $definitions[] = [
                'name' => $nom,
                'description' => $description,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [] === $proprietes ? new \stdClass() : $proprietes,
                    'required' => array_keys($proprietes),
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ];
        }

        return $definitions;
    }

    /**
     * Exécute un appel du modèle. L'entrée est revérifiée ici : ce que le
     * modèle écrit n'est qu'une demande.
     *
     * @param array<string, mixed> $entree
     */
    public function executer(string $nom, array $entree, ContexteAssistant $ctx, ?AssistantConversation $conversation): ResultatOutil
    {
        if (!\in_array($nom, self::noms($ctx->estEspace() ? AssistantConversation::ESPACE : AssistantConversation::SITE), true)) {
            return ResultatOutil::erreur('Outil inconnu.');
        }
        try {
            return match ($nom) {
                'chercher_produits' => $this->chercherProduits(self::texte($entree['texte'] ?? '', 120)),
                'estimer_prix' => $this->estimerPrix($entree, $ctx),
                'infos_boutique' => $this->infosBoutique($ctx),
                'pages_du_site' => $this->pageDuSite(self::texte($entree['page'] ?? '', 40)),
                'promotions_en_cours' => $this->promotions($ctx),
                'passer_a_un_agent' => $this->passerAUnAgent(self::texte($entree['motif'] ?? '', 40), self::texte($entree['resume'] ?? '', self::RESUME_MAX), $ctx, $conversation),
                'mes_commandes' => $this->mesCommandes(self::texte($entree['numero'] ?? '', 30), $ctx),
                'reste_a_payer' => $this->resteAPayer($ctx),
                'mes_produits' => $this->mesProduits($ctx),
                'preparer_demande_devis' => $this->preparerDemandeDevis($entree, $ctx),
            };
        } catch (\DomainException $e) {
            return ResultatOutil::erreur($e->getMessage());
        }
    }

    /* ---------- Site ---------- */

    private function chercherProduits(string $texte): ResultatOutil
    {
        $configurations = $this->site->configurations();
        $trouves = self::chercher($configurations, $texte);
        if ([] === $trouves) {
            $categories = array_values(array_unique(array_filter(array_map(static fn (ProduitConfiguration $c): ?string => null !== ($n = $c->getProduit()?->getCategorieProduit()?->getNom()) ? CatalogueSite::nomAffiche($n) : null, $configurations))));

            return new ResultatOutil(['produits' => [], 'categories_du_catalogue' => $categories, 'note' => 'Aucun produit publié ne correspond. Propose une catégorie proche, ou un conseiller.'], array_filter([self::lienSite($this->site, 'Voir nos produits', 'app_site_produits')]));
        }

        return new ResultatOutil(
            ['produits' => array_map(self::ficheProduit(...), $trouves), 'note' => 'Prix publics de départ, hors finitions. Pour un prix précis, utilise estimer_prix.'],
            array_filter([self::lienSite($this->site, 'Calculer un prix', 'app_site_calculateur', 1 === \count($trouves) ? ['produit' => $trouves[0]->getId()] : [])]),
        );
    }

    /**
     * Configurations qui répondent le mieux aux mots cherchés, dans l'ordre
     * du catalogue à score égal. Sans mot : les premières du catalogue.
     *
     * @param list<ProduitConfiguration> $configurations
     *
     * @return list<ProduitConfiguration>
     */
    public static function chercher(array $configurations, string $texte): array
    {
        $mots = self::mots($texte);
        if ([] === $mots) {
            return \array_slice($configurations, 0, self::PRODUITS_MAX);
        }
        $scores = [];
        foreach ($configurations as $i => $c) {
            $cible = self::normaliser(implode(' ', [CatalogueSite::libelle($c), (string) $c->getProduit()?->getCategorieProduit()?->getNom(), (string) $c->getProduit()?->getDescription()]));
            $score = self::score($mots, $cible);
            if ($score > 0) {
                $scores[$i] = $score;
            }
        }
        uksort($scores, static fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return array_values(array_map(static fn (int $i): ProduitConfiguration => $configurations[$i], \array_slice(array_keys($scores), 0, self::PRODUITS_MAX)));
    }

    /** Minuscules sans accents ni ponctuation. */
    public static function normaliser(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $texte = strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', '²' => '2']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $texte)));
    }

    /**
     * Mots utiles d'une recherche, au singulier.
     *
     * @return list<string>
     */
    public static function mots(string $texte): array
    {
        $mots = [];
        foreach (explode(' ', self::normaliser($texte)) as $mot) {
            if ('' === $mot || \in_array($mot, self::MOTS_VIDES, true) || (\strlen($mot) < 2 && !ctype_digit($mot))) {
                continue;
            }
            if (\strlen($mot) > 3 && \in_array(substr($mot, -1), ['s', 'x'], true)) {
                $mot = substr($mot, 0, -1);
            }
            $mots[] = $mot;
        }

        return array_values(array_unique($mots));
    }

    /** Nombre de mots trouvés au début d'un mot de la cible (« bache » trouve « baches »). */
    public static function score(array $mots, string $cible): int
    {
        $score = 0;
        foreach ($mots as $mot) {
            if (1 === preg_match('/(^|\s)'.preg_quote($mot, '/').'/', $cible)) {
                ++$score;
            }
        }

        return $score;
    }

    /** @return array<string, mixed> ce que le modèle lit d'un produit : tarif public seulement */
    private static function ficheProduit(ProduitConfiguration $c): array
    {
        $fiche = NouvelleCommande::fiche($c, false);
        $categorie = $c->getProduit()?->getCategorieProduit()?->getNom();

        return [
            'ref' => $c->getId(),
            'nom' => CatalogueSite::libelle($c),
            'categorie' => null !== $categorie ? CatalogueSite::nomAffiche($categorie) : null,
            'prix_depart' => 'à partir de '.Calculateur::fcfa($c->getPrixApplicable(false)).' '.CatalogueSite::unite($c->getModeCalcul()),
            'taille' => $fiche['taille'] ? 'largeur et hauteur à donner en cm'.(null !== $fiche['largeur'] && null !== $fiche['hauteur'] ? \sprintf(' (format courant %s × %s cm)', Calculateur::cm((float) $fiche['largeur']), Calculateur::cm((float) $fiche['hauteur'])) : '') : 'pas de taille à donner',
            'quantite_min' => $fiche['min'],
            'finitions' => array_map(static fn (array $f): array => ['ref' => $f['id'], 'nom' => $f['nom'], 'obligatoire' => $f['obligatoire']], $fiche['finitions']),
        ];
    }

    /** @param array<string, mixed> $entree */
    private function estimerPrix(array $entree, ContexteAssistant $ctx): ResultatOutil
    {
        $configuration = self::configuration($this->site->configurations(), self::entier($entree['produit'] ?? 0));
        if (null === $configuration) {
            throw new \DomainException('Référence de produit inconnue : cherche d\'abord le produit avec chercher_produits.');
        }
        $saisie = [
            'precedent' => $configuration->getId(),
            'largeur' => self::cote($entree['largeur_cm'] ?? 0),
            'hauteur' => self::cote($entree['hauteur_cm'] ?? 0),
            'quantite' => max(0, self::entier($entree['quantite'] ?? 0)),
            'finitions' => Calculateur::identifiants($entree['finitions'] ?? []),
        ];
        $valeurs = Calculateur::valeurs($configuration, $saisie);
        if (NouvelleCommande::demandeTaille($configuration) && (null === $valeurs['largeur'] || null === $valeurs['hauteur'])) {
            throw new \DomainException('Ce produit se calcule à la taille : demande la largeur et la hauteur en cm.');
        }
        $r = $this->site->estimation($configuration, $valeurs);

        $donnees = [
            'produit' => $r['nom'],
            'quantite' => $valeurs['quantite'],
            'taille' => null !== $valeurs['largeur'] && null !== $valeurs['hauteur'] ? Calculateur::cm($valeurs['largeur']).' × '.Calculateur::cm($valeurs['hauteur']).' cm' : null,
            'total_tarif_public' => $r['totalTexte'],
            'detail' => array_map(static fn (array $l): string => $l['libelle'].' : '.$l['texte'], $r['detail']),
            'delai' => $this->reglages->delais(),
            'erreur' => $r['erreur'],
            'note' => 'Estimation au tarif public, TVA selon la facture ; le prix ferme est celui du devis.',
        ];
        if ($ctx->estEspace() && true === $ctx->client()?->isB2B()) {
            $donnees['note'] = 'Ce client a des prix de revendeur : ce calcul est au tarif public ; son prix s\'affiche dans Nouvelle commande et sur le devis.';
        }
        $actions = [];
        if (null === $r['erreur']) {
            if ($ctx->estEspace() && $ctx->peutEcrire() && null !== ($r['liens']['commander'] ?? null)) {
                $actions[] = ResultatOutil::lien('Commander en ligne', (string) $r['liens']['commander']);
            } elseif (!$ctx->estEspace() && null !== ($r['liens']['devis'] ?? null)) {
                $actions[] = ResultatOutil::lien('Demander ce devis', (string) $r['liens']['devis']);
            }
        }

        return new ResultatOutil($donnees, $actions);
    }

    private function infosBoutique(ContexteAssistant $ctx): ResultatOutil
    {
        $b = $this->site->boutique($ctx->maintenant);
        $actions = [];
        if (null !== $b['plan']) {
            $actions[] = ResultatOutil::lien('Itinéraire', $b['plan'], 'externe');
        }
        if (null !== ($guide = $this->site->lien('app_site_guide'))) {
            $actions[] = ResultatOutil::lien('Préparer ses fichiers', $guide);
        }

        return new ResultatOutil([
            'nom' => $b['nom'],
            'adresse' => $b['adresse'],
            'telephone' => $b['telephone'],
            'email' => $b['email'],
            'horaires' => $b['horaires'],
            'ouvert_maintenant' => $b['ouvert'],
            'formats_fichiers' => 'PDF (conseillé), AI, EPS, PSD, JPG, PNG ou TIFF',
            'envoi_fichiers' => $ctx->estEspace() ? 'Dans l\'espace client, avec la commande (500 Mo par fichier au plus).' : 'Avec une demande de devis du site (20 Mo au plus), ou dans l\'espace client après connexion.',
            'delais' => $this->reglages->delais(),
            'suivi_commande' => $ctx->estEspace() ? 'Dans l\'espace client, page Commandes.' : 'Page « Suivre ma commande » du site, ou espace client.',
            'paiement' => 'À la boutique, ou par Orange Money depuis l\'espace client quand le paiement en ligne est ouvert.',
        ], $actions);
    }

    private function pageDuSite(string $slug): ResultatOutil
    {
        $page = $this->site->page($slug);
        if (null === $page) {
            throw new \DomainException('Cette page n\'est pas publiée.');
        }
        $texte = trim((string) preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]+)\]\([^)]*\)/', '/\n{3,}/'], ['', '$1', "\n\n"], $page['texte']));

        return new ResultatOutil(
            ['titre' => $page['titre'], 'texte' => mb_substr($texte, 0, self::PAGE_MAX)],
            null !== $page['url'] ? [ResultatOutil::lien('Lire la page « '.$page['titre'].' »', $page['url'])] : [],
        );
    }

    private function promotions(ContexteAssistant $ctx): ResultatOutil
    {
        if ($ctx->estEspace() && true === $ctx->client()?->isB2B()) {
            return new ResultatOutil(['promotions' => [], 'note' => 'Les prix de revendeur de ce client sont fixés avec l\'atelier : les promotions ne s\'y ajoutent pas.']);
        }
        $liste = array_map(static fn (Promotion $p): array => [
            'nom' => CatalogueSite::nomAffiche($p->getNom()),
            'avantage' => $p->getLibelleAvantage(),
            'articles' => CatalogueSite::nomAffiche($p->getLibelleArticles()),
            'fin' => $p->getFin()->format('d/m/Y'),
            'conditions' => implode(' · ', array_filter([
                null !== $p->getQuantiteMin() ? 'dès '.$p->getQuantiteMin().' unités' : null,
                null !== $p->getMontantMin() ? 'ligne de '.Calculateur::fcfa($p->getMontantMin()).' au moins' : null,
            ])) ?: null,
        ], $this->site->promotions($ctx->maintenant, $ctx->estEspace() ? $ctx->client() : null));

        return new ResultatOutil(['promotions' => $liste, 'note' => 'La remise se pose d\'elle-même sur la commande confirmée par l\'atelier.']);
    }

    private function passerAUnAgent(string $motif, string $resume, ContexteAssistant $ctx, ?AssistantConversation $conversation): ResultatOutil
    {
        $texte = self::messageWhatsApp($resume, $ctx, $conversation?->getNumero());
        $lien = $this->reglages->lienWhatsApp($texte);
        if (null === $lien) {
            throw new \DomainException('Pas de numéro WhatsApp réglé : donne le téléphone de la boutique (infos_boutique).');
        }

        return new ResultatOutil(
            ['ok' => true, 'motif' => \in_array($motif, self::MOTIFS_AGENT, true) ? $motif : 'autre', 'note' => 'Un bouton « Écrire à un conseiller sur WhatsApp » s\'affiche sous ta réponse, avec ce résumé. Dis-le simplement, sans écrire de lien.'],
            [ResultatOutil::lien('Écrire à un conseiller sur WhatsApp', $lien, 'whatsapp')],
            false,
            true,
        );
    }

    /** Premier message WhatsApp : qui écrit, d'où, la conversation, le résumé. */
    public static function messageWhatsApp(string $resume, ContexteAssistant $ctx, ?string $numero): string
    {
        $resume = trim((string) preg_replace('/\s+/u', ' ', $resume));
        $qui = $ctx->estEspace() && null !== $ctx->compte
            ? \sprintf('je suis %s (%s), depuis mon espace client', $ctx->compte->getNomAffiche(), $ctx->client()?->getNomComplet() ?? '')
            : 'je viens de l\'assistant du site';
        $texte = 'Bonjour Succès Imprim, '.$qui.(null !== $numero ? ' (conversation '.$numero.')' : '').'.';

        return '' === $resume ? $texte : $texte.' '.mb_substr($resume, 0, self::RESUME_MAX);
    }

    /* ---------- Espace client (compte connecté seulement) ---------- */

    private function mesCommandes(string $numero, ContexteAssistant $ctx): ResultatOutil
    {
        $client = $ctx->client() ?? throw new \DomainException('Réservé à l\'espace client.');
        $commandes = self::filtrerParNumero($this->espace->commandes($client), $numero);
        if ([] === $commandes) {
            return new ResultatOutil(['commandes' => [], 'note' => '' === $numero ? 'Aucune commande sur ce compte.' : 'Aucune commande '.$numero.' sur ce compte.']);
        }
        $cartes = array_map($this->espace->carte(...), \array_slice($commandes, 0, self::COMMANDES_MAX));
        $actions = [];
        foreach (\array_slice($commandes, 0, 1 === \count($commandes) ? 1 : 0) as $c) {
            if (null !== ($url = $this->site->lien('app_espace_commande', ['id' => (string) $c->getPublicId()]))) {
                $actions[] = ResultatOutil::lien('Voir la commande '.$c->getNumero(), $url);
            }
        }
        if ([] === $actions && null !== ($url = $this->site->lien('app_espace_commandes'))) {
            $actions[] = ResultatOutil::lien('Mes commandes', $url);
        }

        return new ResultatOutil(self::commandesPourLeModele($cartes, $ctx->voitLesMontants()), $actions);
    }

    /**
     * Commandes de la liste (déjà celles du client) qui portent ce numéro ;
     * sans numéro, toutes. « 12 », « CMD-12 » et « W-CMD-00012 » se valent.
     *
     * @param list<Commandes> $commandes
     *
     * @return list<Commandes>
     */
    public static function filtrerParNumero(array $commandes, string $numero): array
    {
        $chiffres = static fn (string $n): string => ltrim((string) preg_replace('/\D+/', '', $n), '0');
        $cherche = $chiffres($numero);
        if ('' === trim($numero)) {
            return $commandes;
        }
        if ('' === $cherche) {
            return [];
        }

        return array_values(array_filter($commandes, static fn (Commandes $c): bool => $chiffres((string) $c->getNumero()) === $cherche));
    }

    /**
     * Ce que le modèle lit des commandes : étapes et dates, et les montants
     * seulement pour qui paie.
     *
     * @param list<array<string, mixed>> $cartes EspaceClient::carte()
     *
     * @return array<string, mixed>
     */
    public static function commandesPourLeModele(array $cartes, bool $montants): array
    {
        $liste = [];
        foreach ($cartes as $carte) {
            /** @var Commandes $c */
            $c = $carte['commande'];
            $etape = match (true) {
                $carte['refuseeEntreprise'] => 'refusée par le valideur de l\'entreprise',
                $carte['annulee'] => 'annulée',
                $carte['aValiderEntreprise'] => 'en attente de l\'accord du valideur de l\'entreprise',
                $carte['aConfirmer'] => 'reçue, prix à confirmer par l\'atelier',
                null === $carte['courante'] => 'terminée',
                default => (string) $carte['courante']['libelle'].(null !== ($carte['courante']['detail'] ?? null) ? ' ('.$carte['courante']['detail'].')' : ''),
            };
            $ligne = [
                'numero' => $c->getNumero(),
                'titre' => $carte['titre'],
                'passee_le' => $c->getDateCommande()?->format('d/m/Y'),
                'etape_actuelle' => $etape,
                'etapes' => array_map(static fn (array $e): array => array_filter([
                    'etape' => $e['libelle'],
                    'etat' => ['faite' => 'faite', 'en_cours' => 'en cours', 'a_venir' => 'à venir'][$e['etat']] ?? $e['etat'],
                    'date' => $e['date'] instanceof \DateTimeInterface ? $e['date']->format('d/m/Y') : null,
                    'detail' => $e['detail'] ?? null,
                ], static fn ($v): bool => null !== $v), $carte['etapes']),
                'livraison_prevue' => !$carte['terminee'] && !$carte['annulee'] ? $c->getDateLivraison()?->format('d/m/Y') : null,
                'bat_a_valider' => $carte['batEnAttente'],
            ];
            if ($montants && !$carte['aConfirmer']) {
                $ligne['total_ttc'] = Calculateur::fcfa($c->getTotalTtc());
                $ligne['reste_a_payer'] = Calculateur::fcfa(max(0, $c->getResteAPayer()));
            }
            $liste[] = $ligne;
        }

        return ['commandes' => $liste, 'montants' => $montants ? 'visibles' : 'Ce compte ne voit pas les montants (ils sont réservés aux personnes qui paient pour l\'entreprise) : ne les donne pas, ne les estime pas.'];
    }

    private function resteAPayer(ContexteAssistant $ctx): ResultatOutil
    {
        $client = $ctx->client() ?? throw new \DomainException('Réservé à l\'espace client.');
        if (!$ctx->voitLesMontants()) {
            throw new \DomainException('Ce compte ne voit pas les montants ni les factures : seules les personnes qui paient pour l\'entreprise les voient.');
        }
        $solde = $this->espace->solde($client);
        $factures = \array_slice($this->espace->facturesARegler($client), 0, 5);
        $actions = [];
        $premiere = $solde['commandes'][0] ?? null;
        if ($premiere instanceof Commandes && $ctx->peutEcrire() && $this->espace->paiementEnLigne() && 1 === \count($solde['commandes'])
            && null !== ($url = $this->site->lien('app_espace_payer', ['id' => (string) $premiere->getPublicId()]))) {
            $actions[] = ResultatOutil::lien('Payer la commande '.$premiere->getNumero(), $url);
        }
        if (null !== ($url = $this->site->lien('app_espace_factures'))) {
            $actions[] = ResultatOutil::lien('Mes factures', $url);
        }

        return new ResultatOutil([
            'reste_a_payer' => Calculateur::fcfa((int) round($solde['total'])),
            'commandes' => array_map(static fn (Commandes $c): array => ['numero' => $c->getNumero(), 'titre' => EspaceClient::titre($c), 'reste' => Calculateur::fcfa(OrangeMoney::aPayer($c))], $solde['commandes']),
            'en_validation' => $solde['enValidation'] > 0 ? Calculateur::fcfa($solde['enValidation']) : null,
            'factures_a_regler' => array_map(static fn (Factures $f): array => ['numero' => $f->getNumero(), 'reste' => Calculateur::fcfa($f->getResteAPayer()), 'echeance' => $f->getDateEcheance()?->format('d/m/Y')], $factures),
        ], $actions);
    }

    private function mesProduits(ContexteAssistant $ctx): ResultatOutil
    {
        $client = $ctx->client() ?? throw new \DomainException('Réservé à l\'espace client.');
        if (!$ctx->peutEcrire()) {
            throw new \DomainException('Un compte « Lecture · suivi » n\'a pas accès à Mes produits.');
        }
        $montants = $ctx->voitLesMontants();
        $produits = array_map(static fn (array $p): array => array_filter([
            'nom' => CatalogueSite::nomAffiche($p['nom']),
            'format' => $p['format'],
            'prix' => $montants ? $p['prix'] : null,
            'bat_valide_le' => $p['batLe'] instanceof \DateTimeInterface ? $p['batLe']->format('d/m/Y') : null,
        ], static fn ($v): bool => null !== $v && '' !== $v), $this->espace->produits($client));

        return new ResultatOutil(
            ['produits' => $produits, 'montants' => $montants ? 'visibles' : 'Ce compte ne voit pas les prix : ne les donne pas.'],
            array_filter([self::lienSite($this->site, 'Mes produits', 'app_espace_produits')]),
        );
    }

    /** @param array<string, mixed> $entree */
    private function preparerDemandeDevis(array $entree, ContexteAssistant $ctx): ResultatOutil
    {
        $compte = $ctx->compte ?? throw new \DomainException('Réservé à l\'espace client.');
        if (!$ctx->peutEcrire()) {
            throw new \DomainException('Un compte « Lecture · suivi » ne peut pas demander de devis : propose un conseiller.');
        }
        $produit = self::entier($entree['produit'] ?? 0);
        $quantite = self::entier($entree['quantite'] ?? 0);
        $largeur = self::cote($entree['largeur_cm'] ?? 0);
        $hauteur = self::cote($entree['hauteur_cm'] ?? 0);
        $message = self::texte($entree['message'] ?? '', self::MESSAGE_DEVIS_MAX);
        $saisie = [
            'nom' => $compte->getNomAffiche(),
            'telephone' => $compte->getTelephone(),
            'email' => (string) ($ctx->client()?->getEmail() ?? ''),
            'produit' => $produit > 0 ? (string) $produit : '',
            'largeur' => null !== $largeur ? (string) $largeur : '',
            'hauteur' => null !== $hauteur ? (string) $hauteur : '',
            'quantite' => $quantite > 0 ? (string) $quantite : '',
            'finitions' => Calculateur::identifiants($entree['finitions'] ?? []),
            'message' => mb_substr('Demande préparée avec l\'assistant de l\'espace client. '.$message, 0, DemandeDevis::MESSAGE_MAX),
        ];
        $controle = FormulaireDemande::valider(DemandeDevis::NATURE_DEVIS, $saisie, $this->site->configurations());
        if ([] !== $controle['erreurs']) {
            if (isset($controle['erreurs']['email'])) {
                $saisie['email'] = '';
                $controle = FormulaireDemande::valider(DemandeDevis::NATURE_DEVIS, $saisie, $this->site->configurations());
            }
            if ([] !== $controle['erreurs']) {
                throw new \DomainException('Demande incomplète : '.implode(' ', array_map(static fn (string $champ, string $e): string => $champ.' : '.$e, array_keys($controle['erreurs']), $controle['erreurs'])));
            }
        }
        if (!$this->limiteDevis->create('compte-'.$compte->getId())->consume()->isAccepted()) {
            throw new \DomainException('Plusieurs demandes viennent d\'être envoyées : la suivante pourra partir dans une heure, ou passe par un conseiller.');
        }
        $demande = $this->espace->enregistrerDemande($controle['valeurs'], $ctx->maintenant);

        return new ResultatOutil([
            'ok' => true,
            'numero' => $demande->getNumero(),
            'produit' => $demande->getDesignation() ?? 'projet décrit dans le message',
            'note' => 'Demande enregistrée : l\'atelier prépare le devis et le dépose dans l\'espace client (Devis). Le prix ferme sera celui du devis.',
        ]);
    }

    /* ---------- Outils de lecture ---------- */

    /** @param list<ProduitConfiguration> $configurations */
    private static function configuration(array $configurations, int $id): ?ProduitConfiguration
    {
        foreach ($configurations as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        return null;
    }

    /** @return array{libelle: string, url: string, type: string}|null */
    private static function lienSite(SourceSite $site, string $libelle, string $route, array $parametres = []): ?array
    {
        $url = $site->lien($route, $parametres);

        return null === $url ? null : ResultatOutil::lien($libelle, $url);
    }

    private static function texte(mixed $valeur, int $max): string
    {
        $texte = \is_scalar($valeur) ? (string) $valeur : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $texte)), 0, $max);
    }

    private static function entier(mixed $valeur): int
    {
        return \is_int($valeur) || (\is_float($valeur) && floor($valeur) === $valeur) || (\is_string($valeur) && ctype_digit($valeur)) ? max(0, min(999_999_999, (int) $valeur)) : 0;
    }

    /** Côté en cm entre 1 et 5 000, sinon null (0 : sans objet). */
    private static function cote(mixed $valeur): ?float
    {
        if (!\is_int($valeur) && !\is_float($valeur)) {
            return null;
        }
        $cm = round((float) $valeur, 1);
        if ($cm <= 0) {
            return null;
        }
        if ($cm > self::COTE_MAX_CM) {
            throw new \DomainException('Taille entre 1 et 5 000 cm.');
        }

        return $cm;
    }
}
