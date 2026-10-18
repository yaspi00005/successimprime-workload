<?php

namespace App\Service\Recette;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\MembreEquipe;
use App\Entity\PageSite;
use App\Entity\ProduitConfiguration;
use App\Repository\MembreEquipeRepository;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Site\Calculateur;
use App\Service\Site\ContenuInitial;
use App\Service\Site\FormulaireDemande;
use App\Service\Site\ReferencementSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Lignes « Visiteur du site » de docs/recette-par-role.md qu'une machine
 * peut vérifier : robots.txt et sitemap.xml, suivi de commande sans compte
 * (mauvais téléphone = commande inconnue, pas de montant), calculateur
 * jamais au prix B2B, aucun nom de client sans son accord ni montant
 * d'un client sur les pages publiques, aucune personne de l'équipe
 * masquée ou sans son accord sur « Qui sommes-nous ? », les en-têtes de
 * sécurité, la mesure d'audience Google (pages publiques du site en ligne
 * seulement) et les vraies photos du local (accueil, contact).
 */
final class RecetteVisiteur
{
    /** Pages publiques du site vitrine à lire. */
    private const PREFIXES_PUBLICS = ['app_site_', 'app_home'];

    /** Pages publiques à ne pas lire : formulaires envoyés, fichiers. */
    private const EXCLUES = ['app_site_robots', 'app_site_plan', 'app_site_devis_envoyee', 'app_site_contact_envoye', 'app_site_contenu_index', 'app_site_contenu_nouveau', 'app_site_contenu_fiche', 'app_site_contenu_supprimer', 'app_site_contenu_chiffres'];

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
        private readonly ParametresRoute $parametres,
        private readonly ReferencementSite $referencement,
    ) {
    }

    public function verifier(BilanRecette $bilan, bool $enLigne): void
    {
        $this->navigateur->commencer();
        try {
            $this->referencement($bilan, $enLigne);
            $this->entetesSecurite($bilan);
            $this->mesureAudience($bilan, $enLigne);
            $this->photosDuLocal($bilan, $enLigne);
            if ($enLigne) {
                $this->ecransDuLivreurFermes($bilan);
                $this->suivi($bilan);
                $this->calculateur($bilan);
                $this->piecesAuM2($bilan);
                $this->pagesPubliques($bilan);
                $this->equipe($bilan);
            }
        } finally {
            $this->navigateur->terminer();
        }
    }

    private function referencement(BilanRecette $bilan, bool $enLigne): void
    {
        $robots = $this->navigateur->get('/robots.txt');
        if ($enLigne) {
            $ok = 200 === $robots->statut && str_contains($robots->contenu, 'Allow: /') && str_contains($robots->contenu, 'Disallow: /espace') && str_contains($robots->contenu, 'Sitemap:');
            $bilan->controler('Visiteur : /robots.txt ouvre le site, ferme l\'espace et l\'admin, donne le plan', $ok, (string) $robots->statut);
            $plan = $this->navigateur->get('/sitemap.xml');
            $bilan->controler('Visiteur : /sitemap.xml répond', 200 === $plan->statut && str_contains($plan->contenu, '<urlset'), (string) $plan->statut);
            $prives = preg_match_all('#<loc>[^<]*/(espace|login|api|clients|commandes|factures)\b#', $plan->contenu);
            $bilan->controler('Visiteur : /sitemap.xml ne liste aucune page privée', 0 === $prives, $prives.' adresse(s) privée(s)');
        } else {
            $bilan->controler('ERP local : /robots.txt répond « Disallow: / »', 200 === $robots->statut && 1 === preg_match('#^Disallow: /\s*$#m', $robots->contenu), trim($robots->contenu));
        }
    }

    /**
     * Ma tournée, la remise, Mes livraisons et le suivi des livraisons
     * n'existent que sur l'ERP local (LivreurSeulSubscriber).
     */
    private function ecransDuLivreurFermes(BilanRecette $bilan): void
    {
        $reponses = [];
        foreach (['app_livraisons_tournee', 'app_livraisons_mes_livraisons', 'app_livraisons_suivi'] as $route) {
            $chemin = $this->routeur->generate($route);
            $reponses[] = $chemin.' → '.$this->navigateur->get($chemin)->statut;
        }
        $ok = [] === array_filter($reponses, static fn (string $r): bool => !str_ends_with($r, '→ 404'));
        $bilan->controler('Site en ligne : écrans du livreur et suivi des livraisons introuvables', $ok, implode(', ', $reponses));
    }

    /**
     * En-têtes de sécurité (EntetesSecuriteSubscriber, docs/securite.md) sur
     * le site, l'espace client et la connexion du personnel : politique de
     * contenu, cadre, type de contenu, référent. (L'en-tête X-Powered-By de
     * PHP ne passe pas par ici : il se vérifie sur le serveur, docs/securite.md.)
     */
    private function entetesSecurite(BilanRecette $bilan): void
    {
        foreach (['/', '/espace/connexion', '/login'] as $chemin) {
            $e = $this->navigateur->get($chemin)->entetes;
            $politique = $e['content-security-policy'] ?? '';
            $manque = array_keys(array_filter([
                'politique de contenu' => !str_contains($politique, "object-src 'none'") || !str_contains($politique, "frame-ancestors 'self'") || !str_contains($politique, "base-uri 'self'"),
                'nosniff' => 'nosniff' !== ($e['x-content-type-options'] ?? ''),
                'cadre' => 'SAMEORIGIN' !== ($e['x-frame-options'] ?? ''),
                'référent' => '' === ($e['referrer-policy'] ?? ''),
            ]));
            $bilan->controler('Visiteur : '.$chemin.' a ses en-têtes de sécurité', [] === $manque, [] === $manque ? 'complets' : 'à revoir : '.implode(', ', $manque));
        }
    }

    /**
     * Mesure d'audience Google (MesureAudience, docs/securite.md) : la balise
     * et la politique qui l'autorise sur les pages publiques du site en
     * ligne quand l'identifiant est réglé ; ni l'une ni l'autre sur l'ERP
     * local, dans l'espace client, aux connexions ni sur une adresse à code.
     */
    private function mesureAudience(BilanRecette $bilan, bool $enLigne): void
    {
        $avec = static function (Reponse $r): array {
            return [str_contains($r->contenu, 'mesure-audience.js'), str_contains($r->entetes['content-security-policy'] ?? '', 'googletagmanager')];
        };
        $reglee = '' !== $this->referencement->reglages()['mesure'];
        foreach (['/', '/demander-un-devis'] as $chemin) {
            [$balise, $politique] = $avec($this->navigateur->get($chemin));
            $attendu = $enLigne && $reglee;
            $bilan->controler(
                'Visiteur : '.$chemin.($attendu ? ' porte la mesure d\'audience et sa politique' : ' sans mesure d\'audience'.($enLigne ? ' (identifiant vidé)' : ' (ERP local)')),
                $attendu === $balise && $attendu === $politique,
                \sprintf('balise %s, politique %s', $balise ? 'oui' : 'non', $politique ? 'oui' : 'non'),
            );
        }
        $privees = ['/espace/connexion', '/login', '/suivre-ma-commande', '/f/ABCD-EF', '/avis/c/'.str_repeat('0', 24), '/ecran-accueil/'.str_repeat('a', 32)];
        $fuites = [];
        foreach ($privees as $chemin) {
            [$balise, $politique] = $avec($this->navigateur->get($chemin));
            if ($balise || $politique) {
                $fuites[] = $chemin;
            }
        }
        $bilan->controler('Visiteur : pas de mesure d\'audience dans l\'espace client, aux connexions ni sur une adresse à code', [] === $fuites, [] === $fuites ? \count($privees).' pages lues' : 'sur '.implode(', ', $fuites));
    }

    /**
     * Vraies photos du local (docs/site-vitrine.md) : « Venez nous voir » sur
     * l'accueil (façade, Itinéraire), l'allée et la galerie « Notre local »
     * sur Contact, la vraie façade dans la fiche pour Google ; aucun montant
     * dans le bandeau. Sur l'ERP local, l'aperçu /site.
     */
    private function photosDuLocal(BilanRecette $bilan, bool $enLigne): void
    {
        $accueil = $this->navigateur->get($enLigne ? '/' : $this->routeur->generate('app_site_accueil'));
        $debut = strpos($accueil->contenu, '<section class="si-visite"');
        $bande = false === $debut ? '' : substr($accueil->contenu, $debut, (strpos($accueil->contenu, '</section>', $debut) ?: $debut) - $debut);
        $montants = ContenuSensible::montants(ContenuSensible::texteVisible($bande));
        $ok = 200 === $accueil->statut && str_contains($bande, 'facade-local-succes-imprim-bamako-') && 1 === preg_match('#<a href="https://[^"]+"[^>]*>(?:(?!</a>).)*Itinéraire#su', $bande) && [] === $montants;
        $bilan->controler('Visiteur : l\'accueil montre « Venez nous voir » (vraie façade, Itinéraire), sans montant', $ok, \sprintf('%d, bandeau %s, %d montant(s)', $accueil->statut, '' === $bande ? 'absent' : 'présent', \count($montants)));

        $contact = $this->navigateur->get($this->routeur->generate('app_site_contact'));
        $vignettes = substr_count($contact->contenu, 'class="si-local-vignette"');
        $fenetres = substr_count($contact->contenu, 'class="si-local-visionneuse"');
        $ok = 200 === $contact->statut && str_contains($contact->contenu, 'class="si-local-entete"') && str_contains($contact->contenu, 'allee-entree-succes-imprim-') && 3 === $vignettes && 3 === $fenetres;
        $bilan->controler('Visiteur : /contact montre l\'allée et la galerie « Notre local » (3 photos, visionneuse)', $ok, \sprintf('%d, %d vignette(s), %d visionneuse(s)', $contact->statut, $vignettes, $fenetres));
        $bilan->controler('Visiteur : la fiche de l\'imprimerie pour Google montre la vraie façade (16:9, 4:3, 1:1)', str_contains($contact->contenu, 'images/site/fiche/facade-local-succes-imprim-bamako-1x1.webp'), str_contains($contact->contenu, '"LocalBusiness"') ? 'fiche lue' : 'fiche absente');
    }

    /**
     * « Suivre ma commande » : avec le bon téléphone, les étapes sans montant ;
     * un mauvais téléphone donne le même message qu'une commande inconnue.
     */
    private function suivi(BilanRecette $bilan): void
    {
        $ligne = $this->em->createQueryBuilder()->select('c.numero', 'cl.telephone')->from(Commandes::class, 'c')->join('c.clients', 'cl')
            ->where('c.statut = true')->andWhere('c.deleted = false')->andWhere("cl.code LIKE 'DEMO-%'")->andWhere("cl.telephone IS NOT NULL AND cl.telephone <> ''")
            ->orderBy('c.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (null === $ligne) {
            $bilan->controler('Visiteur : suivi de commande', false, 'aucune commande de démonstration avec un téléphone');

            return;
        }
        foreach (['app_site_suivi' => 'site-suivi', 'app_espace_suivre' => 'espace-suivre'] as $route => $jetonId) {
            if (null === $this->routeur->getRouteCollection()->get($route)) {
                continue;
            }
            $chemin = $this->routeur->generate($route);
            $session = $this->navigateur->session(null);
            $page = $this->navigateur->get($chemin, $session);
            if (!preg_match('/name="_token" value="([^"]+)"/', $page->contenu, $m)) {
                $bilan->controler('Visiteur : '.$chemin.' (jeton du formulaire)', false, 'introuvable');
                continue;
            }
            $jeton = html_entity_decode($m[1]);
            $faux = '60'.substr(str_pad((string) ((int) substr(preg_replace('/\D/', '', (string) $ligne['telephone']), -6) + 111111), 6, '0', \STR_PAD_LEFT), -6);
            $bon = $this->navigateur->post($chemin, ['_token' => $jeton, 'numero' => $ligne['numero'], 'telephone' => $ligne['telephone']], $session);
            $mauvais = $this->navigateur->post($chemin, ['_token' => $jeton, 'numero' => $ligne['numero'], 'telephone' => $faux], $session);
            $inconnue = $this->navigateur->post($chemin, ['_token' => $jeton, 'numero' => 'CMD-999999-01-2001', 'telephone' => $faux], $session);

            $montants = ContenuSensible::montants($bon->texte());
            $bilan->controler('Visiteur : '.$chemin.' avec le bon téléphone montre la commande, sans montant', 200 === $bon->statut && str_contains($bon->texte(), (string) $ligne['numero']) && [] === $montants, \sprintf('%d, %d montant(s)', $bon->statut, \count($montants)));
            $texteMauvais = self::sansSaisie($mauvais->texte(), [(string) $ligne['numero'], $faux]);
            $texteInconnue = self::sansSaisie($inconnue->texte(), ['CMD-999999-01-2001', $faux]);
            $bilan->controler('Visiteur : '.$chemin.' avec un mauvais téléphone répond comme une commande inconnue', $mauvais->statut === $inconnue->statut && $texteMauvais === $texteInconnue && !str_contains($mauvais->texte(), (string) $ligne['numero'].' ·'), \sprintf('%d / %d', $mauvais->statut, $inconnue->statut));
        }
    }

    /** Le calculateur public donne le prix du comptoir, jamais le prix B2B. */
    private function calculateur(BilanRecette $bilan): void
    {
        $essais = 0;
        $justes = 0;
        /** @var list<ProduitConfiguration> $configurations */
        $configurations = $this->em->getRepository(ProduitConfiguration::class)->findBy([], ['id' => 'ASC'], 60);
        foreach ($configurations as $c) {
            foreach ([['quantite' => '1', 'largeur' => '100', 'hauteur' => '100'], ['quantite' => '10', 'largeur' => '200', 'hauteur' => '100']] as $saisie) {
                $saisie['produit'] = (string) $c->getId();
                try {
                    // Les mêmes valeurs que le calculateur (taille imposée, côté proposé…).
                    $v = Calculateur::valeurs($c, Calculateur::lire($saisie));
                    $public = NouvelleCommande::estimer($c, $v['quantite'], $v['largeur'], $v['hauteur'], $v['finitions'], false)['total'];
                    $b2b = NouvelleCommande::estimer($c, $v['quantite'], $v['largeur'], $v['hauteur'], $v['finitions'], true)['total'];
                } catch (\Throwable) {
                    continue;
                }
                if ((float) $public === (float) $b2b) {
                    continue;
                }
                $reponse = $this->navigateur->get($this->routeur->generate('app_site_calculateur_estimation', $saisie));
                $total = json_decode($reponse->contenu, true)['total'] ?? null;
                if (null === $total) {
                    continue;
                }
                ++$essais;
                if ((float) $total === (float) $public) {
                    ++$justes;
                } else {
                    $bilan->constater((float) $total === (float) $b2b ? BilanRecette::FUITE : BilanRecette::A_EXAMINER, 'prix_b2b', 'app_site_calculateur_estimation', '/calculateur/estimation?produit='.$c->getId(), 'visiteur', \sprintf('total %s au lieu du prix comptoir %s (B2B %s)', $total, $public, $b2b));
                }
            }
        }
        $bilan->controler('Visiteur : le calculateur donne le prix du comptoir, jamais le prix B2B', $essais > 0 && $justes === $essais, \sprintf('%d estimation(s) où les deux prix diffèrent, %d au prix comptoir', $essais, $justes));
    }

    /**
     * Demande de devis d'un produit au m² (pièces au m², docs/saisie-devis-commandes.md) :
     * le bloc « Quantité en pièces / m² » est montré, avec l'espacement du réglage,
     * et la page ne donne toujours aucun prix (ni dans le texte, ni dans les fiches).
     */
    private function piecesAuM2(BilanRecette $bilan): void
    {
        $produit = null;
        foreach ($this->em->getRepository(ProduitConfiguration::class)->findBy(['modeCalcul' => 'metre_carre', 'active' => true], ['id' => 'ASC'], 20) as $c) {
            if (FormulaireDemande::piecesAuM2Possible($c)) {
                $produit = $c;
                break;
            }
        }
        if (null === $produit) {
            $bilan->controler('Visiteur : demande de devis en pièces au m²', true, 'aucun produit au m² : rien à vérifier');

            return;
        }

        $chemin = $this->routeur->generate('app_site_devis', ['produit' => $produit->getId(), 'largeur' => '5', 'hauteur' => '5', 'quantite' => '1000']);
        $reponse = $this->navigateur->get($chemin);
        $bloc = preg_match('#<div class="si-pm2" id="pieces-m2"([^>]*)>#', $reponse->contenu, $m) ? $m[1] : null;
        $fiches = preg_match('#<script type="application/json" id="fiches-produits">(.*?)</script>#s', $reponse->contenu, $f) ? $f[1] : '';
        $montants = ContenuSensible::montants($reponse->texte());
        $bilan->controler(
            'Visiteur : demande de devis d\'un produit au m², quantité en pièces ou en m², sans prix',
            200 === $reponse->statut && null !== $bloc && !str_contains($bloc, ' hidden') && str_contains($bloc, 'data-espacement=') && [] === $montants && !preg_match('/"prix/i', $fiches),
            \sprintf('%d, bloc %s, %d montant(s)', $reponse->statut, null === $bloc ? 'absent' : (str_contains($bloc, ' hidden') ? 'caché' : 'montré'), \count($montants)),
        );
    }

    /**
     * Pages publiques : pas d'erreur, aucun nom de client sans son accord
     * (réalisations, avis), aucune page privée.
     */
    private function pagesPubliques(BilanRecette $bilan): void
    {
        $noms = $this->nomsDeClients();
        $consentis = $this->nomsConsentis();
        $lues = 0;
        $problemes = 0;
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            $methodes = $route->getMethods();
            if (\in_array($nom, self::EXCLUES, true) || [] === array_filter(self::PREFIXES_PUBLICS, static fn (string $p): bool => str_starts_with($nom, $p)) || ([] !== $methodes && !\in_array('GET', $methodes, true)) || str_starts_with($route->getPath(), '/reglages')) {
                continue;
            }
            $jeux = $this->parametres->remplir($nom, $route);
            if (\is_string($jeux)) {
                continue;
            }
            $chemin = $this->routeur->generate($nom, $jeux[0]);
            $reponse = $this->navigateur->get($chemin);
            ++$lues;
            if (Reponse::ERREUR === $reponse->etat()) {
                ++$problemes;
                $bilan->constater(BilanRecette::ERREUR, 'erreur', $nom, $chemin, 'visiteur', (string) $reponse->erreur);
                continue;
            }
            if (!$reponse->estHtml()) {
                continue;
            }
            $texte = mb_strtolower($reponse->texte());
            foreach ($noms as $client) {
                if (str_contains($texte, mb_strtolower($client)) && !\in_array(mb_strtolower($client), $consentis, true)) {
                    ++$problemes;
                    $bilan->constater(BilanRecette::FUITE, 'nom_client', $nom, $chemin, 'visiteur', 'nom d\'un client sans son accord : « '.$client.' »');
                }
            }
        }
        $bilan->controler('Visiteur : pages publiques sans erreur ni nom de client sans accord', 0 === $problemes, \sprintf('%d pages lues, %d nom(s) de client cherché(s)', $lues, \count($noms)));
    }

    /**
     * « Notre équipe » de « Qui sommes-nous ? » : une personne masquée ou
     * qui n'a pas donné son accord n'y paraît ni par son nom, ni par le
     * texte de sa photo. Trois témoins sont ajoutés le temps de la recette
     * (la transaction est annulée à la fin) : visible avec accord (doit
     * paraître, sinon le contrôle ne prouve rien), visible sans accord,
     * masqué avec accord. Les personnes cachées déjà en base (la personne
     * sans accord de la démonstration) sont cherchées aussi. Sans page
     * publiée, la page est publiée le temps du contrôle.
     *
     * « Le mot du gérant » aussi : deux gérants témoins, l'un sans accord,
     * l'autre sans photo ; ni leur mot ni (sans accord) leur nom ne
     * paraissent. Le gérant montré (la gérante de la démonstration) doit
     * avoir son mot sur la page, et ne pas être répété dans la grille sauf
     * « aussi dans l'équipe ».
     */
    private function equipe(BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_site_a_propos')) {
            return;
        }
        $marque = strtoupper(bin2hex(random_bytes(3)));
        $temoin = static fn (string $nom, bool $visible, bool $accord): MembreEquipe => (new MembreEquipe())
            ->setNom($nom.' '.$marque)->setPoste('Témoin de recette')->setOrdre(999)->setVisible($visible)->setAccordPersonne($accord);
        $montre = $temoin('Témoin Montré', true, true);
        $caches = [
            $temoin('Témoin SansAccord', true, false),
            $temoin('Témoin Masqué', false, true),
            $temoin('Témoin GérantSansAccord', true, false)->setGerant(true)->setMotGerant('Mot témoin sans accord '.$marque),
            $temoin('Témoin GérantSansPhoto', true, true)->setGerant(true)->setMotGerant('Mot témoin sans photo '.$marque),
        ];
        $page = $this->em->getRepository(PageSite::class)->findOneBy(['slug' => 'a-propos']);
        if (null === $page) {
            $texte = ContenuInitial::pages()['a-propos'];
            $page = (new PageSite('a-propos'))->setTitre($texte['titre'])->setCorps($texte['corps'])->noterModification(new \DateTimeImmutable());
            $this->em->persist($page);
        }
        $page->setPublie(true);
        foreach ([$montre, ...$caches] as $m) {
            $this->em->persist($m);
        }
        $this->em->flush();
        $noms = [];
        $mots = [];
        foreach ($this->em->getRepository(MembreEquipe::class)->findAll() as $m) {
            if (!$m->estVisible() && '' !== $m->getNom()) {
                $noms[] = $m->getNom();
            }
            if (!$m->estMotVisible() && [] !== $m->getParagraphesMot()) {
                $mots[] = $m->getParagraphesMot()[0];
            }
        }
        $gerant = MembreEquipeRepository::gerantDe($this->em->getRepository(MembreEquipe::class)->visibles());

        $chemin = $this->routeur->generate('app_site_a_propos');
        $reponse = $this->navigateur->get($chemin);
        $html = html_entity_decode($reponse->contenu, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        // La section « Notre équipe » seule : un même prénom ailleurs (un avis) n'est pas une fuite.
        $section = preg_match('#<section[^>]*id="notre-equipe".*?</section>#su', $html, $m) ? mb_strtolower($m[0]) : '';
        $sectionMot = preg_match('#<section[^>]*id="mot-du-gerant".*?</section>#su', $html, $m) ? mb_strtolower($m[0]) : '';
        $fuites = 0;
        foreach (array_unique($mots) as $mot) {
            if (str_contains(mb_strtolower($html), mb_strtolower($mot))) {
                ++$fuites;
                $bilan->constater(BilanRecette::FUITE, 'mot_gerant_cache', 'app_site_a_propos', $chemin, 'visiteur', 'mot du gérant montré alors qu\'il est sans accord, sans photo, sans texte ou masqué : « '.mb_substr($mot, 0, 40).'… »');
            }
        }
        // Le gérant montré : son mot paraît, et une seule fois sur la page sauf choix de l'atelier.
        $motVu = null === $gerant || str_contains($sectionMot, mb_strtolower($gerant->getParagraphesMot()[0]));
        $doublon = null !== $gerant && !$gerant->isAussiDansEquipe() && str_contains($section, mb_strtolower($gerant->getNom()));
        foreach (array_unique($noms) as $nom) {
            $cherche = mb_strtolower($nom);
            if (str_contains($section, $cherche) || str_contains(mb_strtolower($html), 'photo de '.$cherche)) {
                ++$fuites;
                $bilan->constater(BilanRecette::FUITE, 'equipe_sans_accord', 'app_site_a_propos', $chemin, 'visiteur', 'personne de l\'équipe montrée alors qu\'elle est masquée ou sans son accord : « '.$nom.' »');
            }
        }
        $temoinVu = str_contains($section, mb_strtolower($montre->getNom()));
        $bilan->controler(
            'Visiteur : « Qui sommes-nous ? » ne montre de l\'équipe (et du mot du gérant) que les personnes visibles avec leur accord',
            200 === $reponse->statut && $temoinVu && $motVu && !$doublon && 0 === $fuites,
            \sprintf(
                '%d, témoin visible %s, %d personne(s) et %d mot(s) cachés cherchés, %d montré(s) ; mot du gérant %s%s',
                $reponse->statut, $temoinVu ? 'montré' : 'absent', \count(array_unique($noms)), \count(array_unique($mots)), $fuites,
                null === $gerant ? 'absent (aucun gérant complet)' : ($motVu ? 'montré' : 'manquant'), $doublon ? ', gérant répété dans la grille' : '',
            ),
        );
    }

    /**
     * Noms de clients assez précis pour être cherchés : raison sociale, ou
     * prénom et nom.
     *
     * @return list<string>
     */
    private function nomsDeClients(): array
    {
        $noms = [];
        foreach ($this->em->createQueryBuilder()->select('c.raisonSociale', 'c.prenom', 'c.nom')->from(Clients::class, 'c')->getQuery()->toIterable() as $c) {
            foreach ([(string) $c['raisonSociale'], trim($c['prenom'].' '.$c['nom'])] as $n) {
                $n = trim($n);
                if (mb_strlen($n) >= 8 && str_contains($n, ' ')) {
                    $noms[$n] = true;
                }
            }
        }

        return array_keys($noms);
    }

    /**
     * Noms affichés avec l'accord du client (réalisations, avis).
     *
     * @return list<string>
     */
    private function nomsConsentis(): array
    {
        $connexion = $this->em->getConnection();
        $noms = [
            ...$connexion->fetchFirstColumn("SELECT nom_client FROM site_realisation WHERE accord_client = 1 AND nom_client IS NOT NULL AND nom_client <> ''"),
            ...$connexion->fetchFirstColumn("SELECT nom_affiche FROM site_avis WHERE accord_client = 1 AND nom_affiche <> ''"),
        ];

        return array_values(array_map(static fn (mixed $n): string => mb_strtolower(trim((string) $n)), $noms));
    }

    /**
     * Texte d'une réponse sans ce que le visiteur a tapé (repris dans le formulaire).
     *
     * @param list<string> $saisies
     */
    public static function sansSaisie(string $texte, array $saisies): string
    {
        return trim(str_replace($saisies, '…', $texte));
    }
}
