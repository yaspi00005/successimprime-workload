<?php

namespace App\Service\Recette;

use App\Entity\ActionSociale;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ImageSite;
use App\Service\EspaceClient\AppareilsClient;
use App\Service\Site\ActionsSociales;
use App\Service\Site\ImagesSite;
use App\Service\Site\SoutienSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * « Nos actions sociales » et « Nous soutenir » (docs/site-vitrine.md,
 * docs/recette-par-role.md) :
 *  - sans action montrée, la page est introuvable, sans lien dans le pied
 *    de page, sans bloc sur l'accueil ni place dans le plan du site ;
 *  - avec une action publiée (texte et photo), elle paraît partout, et
 *    jamais un brouillon ni une action sans photo ;
 *  - le lien de l'avis Google s'ouvre dans un nouvel onglet (noopener), le
 *    QR code est fait par le site ; lien vidé : tout disparaît ;
 *  - espace client (en ligne) : la carte du tableau de bord se ferme pour
 *    une personne seulement, y compris un compte « Lecture · suivi » ;
 *  - ERP local : les onglets « Actions sociales » et « Nous soutenir » de
 *    Réglages › Site vitrine ne s'ouvrent qu'avec le droit Site vitrine,
 *    et leur enregistrement est refusé aux autres.
 *
 * Tout se passe dans la transaction annulée du NavigateurInterne ; les
 * fichiers publics de la photo d'essai sont retirés à la fin.
 */
final class RecetteSiteSocial
{
    private const TITRE_VISIBLE = 'Recette : action sociale montrée';
    private const TITRE_BROUILLON = 'Recette : action sociale en brouillon';
    private const TITRE_SANS_PHOTO = 'Recette : action sociale sans photo';

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
        private readonly ImagesSite $images,
        private readonly SoutienSite $soutien,
        private readonly RecetteEspaceClient $espace,
        private readonly RecetteAcces $personnel,
        private readonly RoleHierarchyInterface $hierarchie,
    ) {
    }

    public function verifier(BilanRecette $bilan, bool $enLigne): void
    {
        $image = $this->imageDEssai();
        $this->navigateur->commencer();
        try {
            $this->actionsSociales($bilan, $enLigne, $image);
            if ($enLigne) {
                $this->espaceClient($bilan);
            } else {
                $this->administration($bilan);
            }
            // En dernier : le lien vidé reste vidé jusqu'à la fin de la transaction.
            $this->soutien($bilan);
        } finally {
            $this->navigateur->terminer();
            // Les pages de l'accueil ont pu refaire ses fichiers jusqu'au bout : retirés après.
            $this->images->nettoyer($image, true);
        }
    }

    private function actionsSociales(BilanRecette $bilan, bool $enLigne, ImageSite $image): void
    {
        $page = $this->routeur->generate(ActionsSociales::ROUTE);
        $accueil = $enLigne ? '/' : $this->routeur->generate('app_site_accueil');
        $autre = $this->routeur->generate('app_site_produits');

        // Rien de montré : les actions de la base sont dépubliées le temps de la recette.
        $this->em->getConnection()->executeStatement('UPDATE site_action_sociale SET publie = 0');
        $etat = $this->navigateur->get($page)->etat();
        $home = $this->navigateur->get($accueil);
        $pied = $this->navigateur->get($autre);
        $plan = $enLigne ? $this->navigateur->get('/sitemap.xml')->contenu : '';
        $traces = array_keys(array_filter([
            'page '.$etat => Reponse::INTROUVABLE !== $etat,
            'bloc de l\'accueil' => str_contains($home->contenu, 'id="si-social-titre"'),
            'lien de l\'accueil' => str_contains($home->contenu, 'href="'.$page),
            'lien du pied de page' => str_contains($pied->contenu, 'href="'.$page),
            'plan du site' => str_contains($plan, $page),
        ]));
        $bilan->controler('Visiteur : « Nos actions sociales » cachée tant qu\'aucune action n\'est montrée', [] === $traces, [] === $traces ? 'page introuvable, ni lien, ni bloc, ni plan' : 'encore : '.implode(', ', $traces));

        $this->em->persist($image);
        $this->em->persist((new ActionSociale())->setTitre(self::TITRE_VISIBLE)->setTexte('Action d\'essai de la recette.')->setLieu('Bamako')->setOrdre(0)->setPublie(true)->ajouterImage($image));
        $this->em->persist((new ActionSociale())->setTitre(self::TITRE_BROUILLON)->setTexte('Brouillon d\'essai.')->setOrdre(0)->setPublie(false)->ajouterImage($image));
        $this->em->persist((new ActionSociale())->setTitre(self::TITRE_SANS_PHOTO)->setTexte('Action d\'essai sans photo.')->setOrdre(0)->setPublie(true));
        $this->em->flush();

        $reponse = $this->navigateur->get($page);
        $home = $this->navigateur->get($accueil);
        $pied = $this->navigateur->get($autre);
        $plan = $enLigne ? $this->navigateur->get('/sitemap.xml')->contenu : $page;
        $manques = array_keys(array_filter([
            'page '.$reponse->etat() => Reponse::OUVERT !== $reponse->etat(),
            'action sur la page' => !str_contains($reponse->contenu, self::TITRE_VISIBLE),
            'photo sur la page' => !str_contains($reponse->contenu, '/uploads/site/'),
            'bloc de l\'accueil' => !str_contains($home->contenu, self::TITRE_VISIBLE),
            'lien du pied de page' => !str_contains($pied->contenu, 'href="'.$page.'">Nos actions sociales'),
            'plan du site' => !str_contains($plan, $page),
        ]));
        $bilan->controler('Visiteur : une action publiée avec texte et photo paraît (page, accueil, pied de page, plan)', [] === $manques, [] === $manques ? 'partout' : 'manque : '.implode(', ', $manques));
        $fuites = array_keys(array_filter([
            'brouillon' => str_contains($reponse->contenu.$home->contenu, self::TITRE_BROUILLON),
            'action sans photo' => str_contains($reponse->contenu.$home->contenu, self::TITRE_SANS_PHOTO),
        ]));
        $bilan->controler('Visiteur : ni brouillon ni action sans photo parmi les actions sociales', [] === $fuites, [] === $fuites ? 'aucun' : 'montré : '.implode(', ', $fuites));
        if ([] !== $fuites) {
            $bilan->constater(BilanRecette::FUITE, 'action_sociale', ActionsSociales::ROUTE, $page, 'visiteur', 'action non publiée ou incomplète montrée : '.implode(', ', $fuites));
        }
    }

    /**
     * Réglages › Site vitrine › Actions sociales et Nous soutenir : ouverts
     * avec le droit Site vitrine, refusés sans (adresse tapée à la main
     * comprise) ; l'enregistrement du lien refusé sans le droit ne change
     * rien.
     */
    private function administration(BilanRecette $bilan): void
    {
        $pages = [
            $this->routeur->generate('app_site_contenu_index', ['onglet' => 'actions']),
            $this->routeur->generate('app_site_contenu_index', ['onglet' => 'soutien']),
            $this->routeur->generate('app_site_contenu_nouveau', ['type' => 'actions']),
        ];
        $enregistrer = $this->routeur->generate('app_site_contenu_soutien');
        $lien = $this->soutien->reglages()['lien'];
        $ecarts = [];
        $comptes = 0;
        foreach ($this->personnel->comptes() as $compte) {
            ++$comptes;
            $permis = \in_array('ROLE_SITE_VITRINE', $this->hierarchie->getReachableRoleNames($compte->getRoles()), true);
            $session = $this->navigateur->session($compte);
            foreach ($pages as $page) {
                $etat = $this->navigateur->get($page, $session)->etat();
                if ($permis ? Reponse::OUVERT !== $etat : !\in_array($etat, [Reponse::REFUSE, Reponse::CONNEXION], true)) {
                    $ecarts[] = $compte->getUserIdentifier().' '.$page.' '.$etat;
                    if (!$permis && Reponse::OUVERT === $etat) {
                        $bilan->constater(BilanRecette::FUITE, 'sans_droit', 'app_site_contenu_index', $page, $compte->getUserIdentifier(), 'réglages du site vitrine ouverts sans le droit Site vitrine');
                    }
                }
            }
            if (!$permis) {
                $etat = $this->navigateur->post($enregistrer, ['lien' => 'https://g.page/r/recette/review', '_token' => 'faux'], $session)->etat();
                $this->soutien->reset();
                if (!\in_array($etat, [Reponse::REFUSE, Reponse::CONNEXION], true) || $lien !== $this->soutien->reglages()['lien']) {
                    $ecarts[] = $compte->getUserIdentifier().' enregistrement '.$etat;
                    $bilan->constater(BilanRecette::FUITE, 'sans_droit', 'app_site_contenu_soutien', $enregistrer, $compte->getUserIdentifier(), 'lien « Nous soutenir » enregistrable sans le droit Site vitrine');
                }
            }
        }
        $bilan->controler('Personnel : « Actions sociales » et « Nous soutenir » réservés au droit Site vitrine', 0 < $comptes && [] === $ecarts, [] === $ecarts ? $comptes.' compte(s) essayé(s)' : implode(' ; ', \array_slice($ecarts, 0, 6)));
    }

    private function soutien(BilanRecette $bilan): void
    {
        $lien = $this->soutien->lien();
        $qr = $this->routeur->generate('app_site_soutien_qr');
        $accueil = $this->routeur->generate('app_site_accueil');
        if (null !== $lien) {
            $home = $this->navigateur->get($accueil)->contenu;
            $ancre = '#<a href="'.preg_quote(htmlspecialchars($lien, \ENT_QUOTES), '#').'"[^>]*>#';
            preg_match_all($ancre, $home, $liens);
            $sures = array_filter($liens[0], static fn (string $a): bool => str_contains($a, 'target="_blank"') && str_contains($a, 'rel="noopener"'));
            $bilan->controler('Visiteur : « Nous soutenir » (accueil et pied de page) ouvre la fiche Google dans un nouvel onglet', \count($liens[0]) >= 2 && \count($sures) === \count($liens[0]), \sprintf('%d lien(s), %d avec target et noopener', \count($liens[0]), \count($sures)));
            if ($this->soutien->avecQr()) {
                $svg = $this->navigateur->get($qr);
                $ok = Reponse::OUVERT === $svg->etat() && str_starts_with($svg->type, 'image/svg+xml') && str_contains($svg->contenu, '<svg');
                $bilan->controler('Visiteur : QR code de l\'avis fait par le site', $ok, $svg->statut.' '.$svg->type);
            }
        }

        // Lien vidé dans les réglages : plus rien.
        $this->soutien->enregistrer(['lien' => '', 'qr' => '1']);
        $home = $this->navigateur->get($accueil)->contenu;
        $restes = array_keys(array_filter([
            'bloc' => str_contains($home, 'id="si-soutien-titre"'),
            'pied de page' => str_contains($home, '>Nous soutenir</a>'),
            'QR code' => Reponse::INTROUVABLE !== $this->navigateur->get($qr)->etat(),
        ]));
        $bilan->controler('Visiteur : « Nous soutenir » disparaît quand le lien est vidé', [] === $restes, [] === $restes ? 'ni bloc, ni lien, ni QR' : 'reste : '.implode(', ', $restes));
    }

    /**
     * La carte du tableau de bord se ferme pour la personne qui la ferme
     * (même un compte « Lecture · suivi »), pas pour les autres personnes
     * de l'entreprise ; l'invitation paraît sur une commande livrée.
     */
    private function espaceClient(BilanRecette $bilan): void
    {
        if (null === $this->soutien->lien()) {
            return;
        }
        $comptes = $this->espace->comptes(new BilanRecette());
        $lecture = $comptes['lecture'] ?? null;
        $titulaire = $comptes['titulaire'] ?? null;
        if (null === $lecture || null === $titulaire) {
            $bilan->controler('Espace client : carte « Nous soutenir » fermée par une personne', false, 'pas de comptes titulaire et lecture dans la démonstration');

            return;
        }
        $sessionLecture = $this->navigateur->session($lecture, 'espace', [AppareilsClient::SESSION_VERSION => $lecture->getVersionSession()]);
        $sessionTitulaire = $this->navigateur->session($titulaire, 'espace', [AppareilsClient::SESSION_VERSION => $titulaire->getVersionSession()]);
        $tableau = $this->routeur->generate('app_espace_accueil');

        $avant = $this->navigateur->get($tableau, $sessionLecture);
        $carteAvant = str_contains($avant->contenu, 'id="ec-soutien-titre"');
        $jeton = preg_match('#data-soutien-fermer>\s*<input type="hidden" name="_token" value="([^"]+)"#', $avant->contenu, $m) ? html_entity_decode($m[1]) : '';
        $fermeture = $this->navigateur->post($this->routeur->generate('app_espace_soutien_masquer'), ['_token' => $jeton], $sessionLecture, ['X-Requested-With' => 'fetch']);
        $carteApres = str_contains($this->navigateur->get($tableau, $sessionLecture)->contenu, 'id="ec-soutien-titre"');
        $carteAutre = str_contains($this->navigateur->get($tableau, $sessionTitulaire)->contenu, 'id="ec-soutien-titre"');
        $ok = $carteAvant && 204 === $fermeture->statut && !$carteApres && $carteAutre;
        $bilan->controler('Espace client : carte « Nous soutenir » fermée par une personne (Lecture · suivi), gardée pour les autres', $ok, \sprintf('avant %s, fermeture %d, après %s, titulaire %s', $carteAvant ? 'montrée' : 'absente', $fermeture->statut, $carteApres ? 'montrée' : 'fermée', $carteAutre ? 'montrée' : 'fermée'));

        $sansJeton = $this->navigateur->post($this->routeur->generate('app_espace_soutien_masquer'), ['_token' => 'faux'], $sessionTitulaire);
        $toujours = str_contains($this->navigateur->get($tableau, $sessionTitulaire)->contenu, 'id="ec-soutien-titre"');
        $bilan->controler('Espace client : fermer la carte sans jeton valable est refusé', Reponse::REFUSE === $sansJeton->etat() && $toujours, $sansJeton->etat());

        $this->invitationCommande($bilan, $titulaire, $sessionTitulaire);
    }

    private function invitationCommande(BilanRecette $bilan, CompteClient $compte, MockArraySessionStorage $session): void
    {
        $livrees = [];
        foreach ($this->em->getRepository(Commandes::class)->findBy(['clients' => $compte->getClient(), 'statut' => true, 'deleted' => false], ['id' => 'DESC'], 50) as $c) {
            $livrees['livree' === $c->getStatutTravaux() ? 'oui' : 'non'] ??= (string) $c->getPublicId();
        }
        if (!isset($livrees['oui'])) {
            $bilan->controler('Espace client : invitation « Nous soutenir » sur une commande livrée', false, 'pas de commande livrée dans la démonstration');

            return;
        }
        $avec = $this->navigateur->get($this->routeur->generate('app_espace_commande', ['id' => $livrees['oui']]), $session)->contenu;
        $sans = isset($livrees['non']) ? $this->navigateur->get($this->routeur->generate('app_espace_commande', ['id' => $livrees['non']]), $session)->contenu : '';
        $lien = htmlspecialchars((string) $this->soutien->lien(), \ENT_QUOTES);
        $ok = str_contains($avec, 'href="'.$lien.'"') && !str_contains($sans, 'href="'.$lien.'"');
        $bilan->controler('Espace client : invitation « Nous soutenir » sur une commande livrée seulement', $ok, \sprintf('livrée %s, en cours %s', str_contains($avec, 'href="'.$lien.'"') ? 'avec' : 'sans', isset($livrees['non']) ? (str_contains($sans, 'href="'.$lien.'"') ? 'avec' : 'sans') : 'aucune'));
    }

    /** Petite photo PNG faite ici, réencodée comme une photo envoyée. */
    private function imageDEssai(): ImageSite
    {
        $gd = imagecreatetruecolor(64, 48);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 15, 69, 144));
        ob_start();
        imagepng($gd);
        $octets = (string) ob_get_clean();
        [$sortie, $type, $largeur, $hauteur] = ImagesSite::preparer($octets, 1200);

        return ImageSite::depuis($sortie, $type, $largeur, $hauteur)->setTexteAlternatif('Photo d\'essai de la recette');
    }
}
