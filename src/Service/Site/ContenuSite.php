<?php

namespace App\Service\Site;

use App\Entity\ActionSociale;
use App\Entity\Annonce;
use App\Entity\ArticleConseil;
use App\Entity\AvisClient;
use App\Entity\CategorieProduit;
use App\Entity\ImageSite;
use App\Entity\PageSite;
use App\Entity\Partenaire;
use App\Entity\Realisation;
use App\Entity\ReglageSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Contenu du site vitrine saisi dans l'admin (Réglages › Site vitrine) :
 * lecture des formulaires, règles de publication et images.
 *
 * Les accords se font respecter ici : un avis sans « accord du client »,
 * un partenaire sans « accord obtenu » restent hors du site, même si la
 * case « publié » est cochée.
 */
final class ContenuSite
{
    /** Onglets de l'écran : type => classe et libellés. */
    public const TYPES = [
        'pages' => ['classe' => PageSite::class, 'libelle' => 'Pages', 'un' => 'Page', 'nouveau' => null, 'cree' => 'Page créée.', 'supprime' => 'Page supprimée.'],
        'realisations' => ['classe' => Realisation::class, 'libelle' => 'Réalisations', 'un' => 'Réalisation', 'nouveau' => 'Nouvelle réalisation', 'cree' => 'Réalisation créée.', 'supprime' => 'Réalisation supprimée.'],
        'avis' => ['classe' => AvisClient::class, 'libelle' => 'Avis clients', 'un' => 'Avis', 'nouveau' => 'Nouvel avis', 'cree' => 'Avis créé.', 'supprime' => 'Avis supprimé.'],
        'conseils' => ['classe' => ArticleConseil::class, 'libelle' => 'Conseils', 'un' => 'Conseil', 'nouveau' => 'Nouveau conseil', 'cree' => 'Conseil créé.', 'supprime' => 'Conseil supprimé.'],
        'partenaires' => ['classe' => Partenaire::class, 'libelle' => 'Partenaires', 'un' => 'Partenaire', 'nouveau' => 'Nouveau partenaire', 'cree' => 'Partenaire créé.', 'supprime' => 'Partenaire supprimé.'],
        // Bandes en haut du site public et de l'espace client (demande de Yaya du 15/10/2026).
        'annonces' => ['classe' => Annonce::class, 'libelle' => 'Annonces', 'un' => 'Annonce', 'nouveau' => 'Nouvelle annonce', 'cree' => 'Annonce créée.', 'supprime' => 'Annonce supprimée.'],
        // « Nos actions sociales » (demande de Yaya du 16/10/2026) : invisible tant qu'aucune n'est montrable.
        'actions' => ['classe' => ActionSociale::class, 'libelle' => 'Actions sociales', 'un' => 'Action sociale', 'nouveau' => 'Nouvelle action', 'cree' => 'Action sociale créée.', 'supprime' => 'Action sociale supprimée.'],
    ];

    /** Photos d'une réalisation, au plus. */
    public const IMAGES_MAX = 6;

    /** Longueur d'une adresse de conseil, sans le suffixe « -2 ». */
    public const SLUG_MAX = 90;

    private static ?AsciiSlugger $slugger = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImagesSite $images,
    ) {
    }

    public static function typeDe(object $objet): ?string
    {
        foreach (self::TYPES as $type => $definition) {
            if ($objet instanceof $definition['classe']) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<object> */
    public function liste(string $type): array
    {
        return $this->em->getRepository(self::TYPES[$type]['classe'])->dansLOrdre();
    }

    public function trouver(string $type, int $id): ?object
    {
        return $this->em->getRepository(self::TYPES[$type]['classe'])->find($id);
    }

    /**
     * Les pages prévues par le site, avec leur fiche quand elle existe.
     *
     * @return list<array{slug: string, libelle: string, route: string, maquette: string, page: ?PageSite}>
     */
    public function pages(): array
    {
        $existantes = [];
        foreach ($this->em->getRepository(PageSite::class)->findAll() as $page) {
            $existantes[$page->getSlug()] = $page;
        }
        $pages = [];
        foreach (PageSite::PAGES as $slug => $definition) {
            $pages[] = ['slug' => $slug, 'page' => $existantes[$slug] ?? null] + $definition;
        }

        return $pages;
    }

    /**
     * Objet vide pour la fiche « nouveau ». Une page ne se crée qu'une
     * fois, et seulement parmi celles que le site sait afficher.
     *
     * @throws \InvalidArgumentException
     */
    public function nouveau(string $type, ?string $slugPage = null): object
    {
        if ('pages' !== $type) {
            $classe = self::TYPES[$type]['classe'];

            return new $classe();
        }
        $slugPage = (string) $slugPage;
        if (!isset(PageSite::PAGES[$slugPage]) || null !== $this->em->getRepository(PageSite::class)->findOneBy(['slug' => $slugPage])) {
            throw new \InvalidArgumentException('Page inconnue, ou déjà créée.');
        }

        return (new PageSite($slugPage))
            ->setTitre(PageSite::PAGES[$slugPage]['libelle'])
            ->setOrdre((int) array_search($slugPage, array_keys(PageSite::PAGES), true));
    }

    /** @return list<CategorieProduit> */
    public function categories(): array
    {
        return $this->em->getRepository(CategorieProduit::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }

    /**
     * Recopie le formulaire dans l'objet et l'enregistre. Avec une erreur,
     * rien n'est enregistré ; un avertissement (accord manquant) laisse
     * enregistrer, mais pas publier.
     *
     * @param array<string, mixed> $donnees  champs du formulaire
     * @param array<string, mixed> $fichiers fichiers envoyés
     *
     * @return array{erreurs: list<string>, avertissements: list<string>}
     */
    public function enregistrer(object $objet, array $donnees, array $fichiers, \DateTimeImmutable $maintenant): array
    {
        $erreurs = [];
        $avertissements = [];

        match (true) {
            $objet instanceof PageSite => $this->lirePage($objet, $donnees, $erreurs),
            $objet instanceof Realisation => $this->lireRealisation($objet, $donnees, $erreurs, $avertissements),
            $objet instanceof AvisClient => $this->lireAvis($objet, $donnees, $erreurs),
            $objet instanceof ArticleConseil => $this->lireConseil($objet, $donnees, $erreurs, $avertissements),
            $objet instanceof Partenaire => $this->lirePartenaire($objet, $donnees, $erreurs, $avertissements),
            $objet instanceof Annonce => $this->lireAnnonce($objet, $donnees, $erreurs, $avertissements),
            $objet instanceof ActionSociale => $this->lireAction($objet, $donnees, $erreurs),
            default => throw new \InvalidArgumentException('Contenu du site inconnu.'),
        };

        // Les images en dernier : un fichier n'est traité que si le reste est bon.
        $nouvelles = [];
        $retirees = [];
        if ([] === $erreurs) {
            try {
                [$nouvelles, $retirees] = $this->lireImages($objet, $donnees, $fichiers);
            } catch (\DomainException $e) {
                $erreurs[] = $e->getMessage();
            }
        }
        if ([] !== $erreurs) {
            foreach ($nouvelles as $image) {
                $this->images->nettoyer($image, true);
            }

            return ['erreurs' => $erreurs, 'avertissements' => $avertissements];
        }

        $publier = !empty($donnees['publie']);
        if ($publier && [] !== $regles = $objet->reglesDePublication()) {
            $avertissements = [...$avertissements, ...$regles];
            $avertissements[] = 'Enregistré sans être publié sur le site.';
            $publier = false;
        }
        $objet instanceof Partenaire ? $objet->setVisible($publier) : $objet->setPublie($publier);
        if ($objet instanceof PageSite || $objet instanceof ArticleConseil || $objet instanceof Annonce || $objet instanceof ActionSociale) {
            $objet->noterModification($maintenant);
        }

        foreach ($nouvelles as $image) {
            $this->em->persist($image);
        }
        foreach ($retirees as $image) {
            $this->em->remove($image);
        }
        $this->em->persist($objet);
        $this->em->flush();

        return ['erreurs' => [], 'avertissements' => $avertissements];
    }

    /** Supprime l'objet et ses images (qui ne servent qu'à lui). */
    public function supprimer(object $objet): void
    {
        foreach (self::imagesDe($objet) as $image) {
            $this->em->remove($image);
        }
        $this->em->remove($objet);
        $this->em->flush();
    }

    /**
     * Réglages de la bande de chiffres, descendus au site par la synchronisation.
     *
     * @param array<string, mixed> $donnees
     */
    public function enregistrerChiffres(array $donnees): void
    {
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => ChiffresSite::CLE_REGLAGE]);
        if (null === $reglage) {
            $reglage = new ReglageSite(ChiffresSite::CLE_REGLAGE);
            $this->em->persist($reglage);
        }
        $reglage->setValeurs(ChiffresSite::normaliser([
            'afficher' => !empty($donnees['afficher']),
            'projets' => (string) ($donnees['projets'] ?? ''),
            'seuil' => (int) ($donnees['seuil'] ?? 1),
        ]));
        $this->em->flush();
    }

    /** « Bâche 440 g ou 510 g ? » donne « bache-440-g-ou-510-g ». */
    public static function slug(string $texte): string
    {
        self::$slugger ??= new AsciiSlugger('fr');

        return trim(mb_substr(self::$slugger->slug(mb_strtolower(trim($texte)))->toString(), 0, self::SLUG_MAX), '-');
    }

    /**
     * La première adresse libre : « base », sinon « base-2 », « base-3 »…
     *
     * @param list<string> $prises
     */
    public static function premiereLibre(string $base, array $prises): string
    {
        $prises = array_flip($prises);
        $slug = $base;
        for ($n = 2; isset($prises[$slug]); ++$n) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** @return list<ImageSite> */
    public static function imagesDe(object $objet): array
    {
        return array_values(array_filter(match (true) {
            $objet instanceof Realisation, $objet instanceof ActionSociale => $objet->getImages()->toArray(),
            $objet instanceof PageSite, $objet instanceof ArticleConseil => [$objet->getImage()],
            $objet instanceof Partenaire => [$objet->getLogo()],
            default => [],
        }));
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     */
    private function lirePage(PageSite $page, array $donnees, array &$erreurs): void
    {
        $page->setTitre(self::texte($donnees, 'titre'))
            ->setAccroche(self::texte($donnees, 'accroche'))
            ->setDescription(self::texte($donnees, 'description'))
            ->setCorps(self::corps($donnees));
        if ('' === $page->getTitre()) {
            $erreurs[] = 'Le titre est obligatoire.';
        }
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     * @param list<string>         $avertissements
     */
    private function lireRealisation(Realisation $realisation, array $donnees, array &$erreurs, array &$avertissements): void
    {
        $categorie = (int) ($donnees['categorie'] ?? 0);
        $realisation->setTitre(self::texte($donnees, 'titre'))
            ->setCategorie($categorie > 0 ? $this->em->getRepository(CategorieProduit::class)->find($categorie) : null)
            ->setDescription(mb_substr(self::texte($donnees, 'description'), 0, 2000))
            ->setNomClient(self::texte($donnees, 'nomClient'))
            ->setAccordClient(!empty($donnees['accordClient']));
        if (null !== $date = self::date($donnees, 'dateRealisation', 'La date de la réalisation', $erreurs)) {
            $realisation->setDateRealisation($date);
        }
        if ('' === $realisation->getTitre()) {
            $erreurs[] = 'Le titre est obligatoire.';
        }
        if (null !== $realisation->getNomClient() && !$realisation->isAccordClient()) {
            $avertissements[] = 'Le nom du client reste à l\'atelier : il ne sera montré qu\'une fois « accord du client » coché.';
        }
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     */
    private function lireAvis(AvisClient $avis, array $donnees, array &$erreurs): void
    {
        $avis->setNomAffiche(self::texte($donnees, 'nomAffiche'))
            ->setTexte(self::texte($donnees, 'texte'))
            ->setNote((int) ($donnees['note'] ?? 5))
            ->setSource(self::texte($donnees, 'source'))
            ->setAccordClient(!empty($donnees['accordClient']));
        if (null !== $date = self::date($donnees, 'dateAvis', 'La date de l\'avis', $erreurs)) {
            $avis->setDateAvis($date);
        }
        if ('' === $avis->getNomAffiche() || '' === $avis->getTexte()) {
            $erreurs[] = 'Le nom affiché et le texte de l\'avis sont obligatoires.';
        }
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     * @param list<string>         $avertissements
     */
    private function lireConseil(ArticleConseil $article, array $donnees, array &$erreurs, array &$avertissements): void
    {
        $article->setTitre(self::texte($donnees, 'titre'))
            ->setRubrique(self::texte($donnees, 'rubrique'))
            ->setResume(self::texte($donnees, 'resume'))
            ->setCorps(self::corps($donnees));
        if (null !== $date = self::date($donnees, 'publieLe', 'La date de publication', $erreurs)) {
            $article->setPublieLe($date);
        }
        if ('' === $article->getTitre()) {
            $erreurs[] = 'Le titre est obligatoire.';

            return;
        }
        $saisi = self::texte($donnees, 'slug');
        $base = self::slug('' !== $saisi ? $saisi : $article->getTitre()) ?: 'conseil';
        $slug = $this->slugLibre($base, $article);
        if ('' !== $saisi && $slug !== $base) {
            $avertissements[] = \sprintf('L\'adresse « %s » est déjà prise : le conseil sera à « %s ».', $base, $slug);
        }
        $article->setSlug($slug);
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     * @param list<string>         $avertissements
     */
    private function lirePartenaire(Partenaire $partenaire, array $donnees, array &$erreurs, array &$avertissements): void
    {
        $lien = self::texte($donnees, 'lien');
        $partenaire->setNom(self::texte($donnees, 'nom'))
            ->setLien($lien)
            ->setOrdre(max(0, min(999, (int) ($donnees['ordre'] ?? 0))))
            ->setAccordObtenu(!empty($donnees['accordObtenu']));
        if ('' !== $lien && null === $partenaire->getLien()) {
            $avertissements[] = 'Lien ignoré : seule une adresse web commençant par http:// ou https:// est acceptée.';
        }
        if ('' === $partenaire->getNom()) {
            $erreurs[] = 'Le nom du partenaire est obligatoire.';
        }
    }

    /**
     * Annonce : une phrase de 200 caractères au plus, un lien facultatif
     * (adresse web ou page du site), un style, où la montrer, sa période.
     *
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     * @param list<string>         $avertissements
     */
    private function lireAnnonce(Annonce $annonce, array $donnees, array &$erreurs, array &$avertissements): void
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', self::texte($donnees, 'texte')));
        $lien = self::texte($donnees, 'lien');
        $annonce->setTexte($texte)
            ->setLien($lien)
            ->setLibelleLien(self::texte($donnees, 'libelleLien'))
            ->setStyle(self::texte($donnees, 'style'))
            ->setCible(self::texte($donnees, 'cible'))
            ->setOrdre((int) ($donnees['ordre'] ?? 0));
        $annonce->setDebut(self::date($donnees, 'debut', 'Le premier jour', $erreurs));
        $annonce->setFin(self::date($donnees, 'fin', 'Le dernier jour', $erreurs));
        if ('' === $texte) {
            $erreurs[] = 'Le texte de l\'annonce est obligatoire.';
        } elseif (mb_strlen($texte) > Annonce::TEXTE_MAX) {
            $erreurs[] = \sprintf('Le texte fait %d caractères : %d au plus, pour tenir sur une ligne.', mb_strlen($texte), Annonce::TEXTE_MAX);
        }
        if ('' !== $lien && null === $annonce->getLien()) {
            $avertissements[] = 'Lien ignoré : seule une adresse web (https://…) ou une page du site (/nos-produits) est acceptée.';
        }
        if (null !== $annonce->getDebut() && null !== $annonce->getFin() && $annonce->getFin() < $annonce->getDebut()) {
            $erreurs[] = 'Le dernier jour est avant le premier : corrigez la période.';
        }
    }

    /**
     * Fichiers envoyés et images retirées. Lève une DomainException (message
     * pour l'écran) si un fichier n'est pas accepté.
     *
     * @param array<string, mixed> $donnees
     * @param array<string, mixed> $fichiers
     *
     * @return array{0: list<ImageSite>, 1: list<ImageSite>} nouvelles, retirées
     */
    private function lireImages(object $objet, array $donnees, array $fichiers): array
    {
        $nouvelles = [];
        $retirees = [];

        if ($objet instanceof ActionSociale) {
            return $this->lirePhotosAction($objet, $donnees, $fichiers);
        }

        if ($objet instanceof Realisation) {
            $reglages = \is_array($donnees['photos'] ?? null) ? $donnees['photos'] : [];
            foreach ($objet->getImages()->toArray() as $image) {
                $reglage = \is_array($reglages[$image->getId()] ?? null) ? $reglages[$image->getId()] : [];
                if (!empty($reglage['retirer'])) {
                    $objet->retirerImage($image);
                    $retirees[] = $image;
                    continue;
                }
                $image->setTexteAlternatif(isset($reglage['alt']) ? (string) $reglage['alt'] : $image->getTexteAlternatif());
                if (isset($reglage['ordre'])) {
                    $image->setOrdre(max(0, min(99, (int) $reglage['ordre'])));
                }
            }
            $envois = array_values(array_filter((array) ($fichiers['images'] ?? []), static fn ($f): bool => $f instanceof UploadedFile));
            if ($objet->getImages()->count() + \count($envois) > self::IMAGES_MAX) {
                throw new \DomainException(\sprintf('Une réalisation a %d photos au plus : retirez-en avant d\'en ajouter.', self::IMAGES_MAX));
            }
            $ordre = 0;
            foreach ($objet->getImages() as $image) {
                $ordre = max($ordre, $image->getOrdre() + 1);
            }
            foreach ($envois as $fichier) {
                try {
                    $image = $this->images->depuisEnvoi($fichier, ImageSite::USAGE_PHOTO);
                } catch (\DomainException $e) {
                    foreach ($nouvelles as $deja) {
                        $this->images->nettoyer($deja, true);
                    }
                    throw $e;
                }
                $image->setOrdre($ordre++)->setTexteAlternatif($objet->getTitre());
                $objet->ajouterImage($image);
                $nouvelles[] = $image;
            }

            return [$nouvelles, $retirees];
        }

        if (!$objet instanceof Partenaire && !$objet instanceof PageSite && !$objet instanceof ArticleConseil) {
            return [[], []]; // un avis n'a pas d'image
        }
        $logo = $objet instanceof Partenaire;
        $champ = $logo ? 'logo' : 'image';
        $actuelle = $logo ? $objet->getLogo() : $objet->getImage();
        $fichier = $fichiers[$champ] ?? null;
        $remplacee = null;
        if ($fichier instanceof UploadedFile) {
            $remplacee = $this->images->depuisEnvoi($fichier, $logo ? ImageSite::USAGE_LOGO : ImageSite::USAGE_PHOTO);
            $nouvelles[] = $remplacee;
        }
        if (null !== $actuelle && (null !== $remplacee || !empty($donnees['retirer_'.$champ]))) {
            $retirees[] = $actuelle;
            $actuelle = null;
        }
        $image = $remplacee ?? $actuelle;
        $image?->setTexteAlternatif($logo ? $objet->getNom() : (isset($donnees['image_alt']) ? (string) $donnees['image_alt'] : $image->getTexteAlternatif()));
        $logo ? $objet->setLogo($image) : $objet->setImage($image);

        return [$nouvelles, $retirees];
    }

    /**
     * Action sociale : titre, date, lieu facultatif, texte, ordre. Les
     * photos suivent (lirePhotosAction) ; la publication attend un texte
     * et au moins une photo (ActionSociale::reglesDePublication).
     *
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     */
    private function lireAction(ActionSociale $action, array $donnees, array &$erreurs): void
    {
        $action->setTitre(self::texte($donnees, 'titre'))
            ->setLieu(self::texte($donnees, 'lieu'))
            ->setTexte(mb_substr(self::texte($donnees, 'texte'), 0, MarkdownSite::LONGUEUR_MAX))
            ->setOrdre((int) ($donnees['ordre'] ?? 0));
        if (null !== $date = self::date($donnees, 'dateAction', 'La date de l\'action', $erreurs)) {
            $action->setDateAction($date);
        }
        if ('' === $action->getTitre()) {
            $erreurs[] = 'Le titre est obligatoire.';
        }
    }

    /**
     * Photos d'une action sociale : description, ordre et retrait des
     * photos en place, puis les photos envoyées (ActionSociale::PHOTOS_MAX
     * au plus), réencodées sans EXIF ni position GPS. L'original envoyé
     * n'est pas gardé : ce sont souvent des personnes, des enfants.
     *
     * @param array<string, mixed> $donnees
     * @param array<string, mixed> $fichiers
     *
     * @return array{0: list<ImageSite>, 1: list<ImageSite>} nouvelles, retirées
     *
     * @throws \DomainException
     */
    private function lirePhotosAction(ActionSociale $action, array $donnees, array $fichiers): array
    {
        $nouvelles = [];
        $retirees = [];
        $reglages = \is_array($donnees['photos'] ?? null) ? $donnees['photos'] : [];
        foreach ($action->getImages()->toArray() as $image) {
            $reglage = \is_array($reglages[$image->getId()] ?? null) ? $reglages[$image->getId()] : [];
            if (!empty($reglage['retirer'])) {
                $action->retirerImage($image);
                $retirees[] = $image;
                continue;
            }
            if (isset($reglage['alt']) && \is_scalar($reglage['alt'])) {
                $image->setTexteAlternatif((string) $reglage['alt']);
            }
            if (isset($reglage['ordre'])) {
                $image->setOrdre(max(0, min(99, (int) $reglage['ordre'])));
            }
        }
        $envois = array_values(array_filter((array) ($fichiers['images'] ?? []), static fn ($f): bool => $f instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $f->getError()));
        if ($action->getImages()->count() + \count($envois) > ActionSociale::PHOTOS_MAX) {
            throw new \DomainException(\sprintf('Une action sociale a %d photos au plus : retirez-en avant d\'en ajouter.', ActionSociale::PHOTOS_MAX));
        }
        $ordre = 0;
        foreach ($action->getImages() as $image) {
            $ordre = max($ordre, $image->getOrdre() + 1);
        }
        foreach ($envois as $fichier) {
            try {
                $image = $this->images->depuisEnvoi($fichier, ImageSite::USAGE_PHOTO);
            } catch (\DomainException $e) {
                foreach ($nouvelles as $deja) {
                    $this->images->nettoyer($deja, true);
                }
                throw $e;
            }
            $this->images->oublierOriginal($image);
            $image->setOrdre($ordre++)->setTexteAlternatif($action->getTitre());
            $action->ajouterImage($image);
            $nouvelles[] = $image;
        }

        return [$nouvelles, $retirees];
    }

    private function slugLibre(string $base, ArticleConseil $article): string
    {
        $prises = $this->em->createQueryBuilder()
            ->select('a.slug')->from(ArticleConseil::class, 'a')
            ->where('a.slug = :base OR a.slug LIKE :suite')
            ->setParameter('base', $base)->setParameter('suite', addcslashes($base, '%_').'-%');
        if (null !== $article->getId()) {
            $prises->andWhere('a.id <> :id')->setParameter('id', $article->getId());
        }

        return self::premiereLibre($base, array_column($prises->getQuery()->getArrayResult(), 'slug'));
    }

    /** @param array<string, mixed> $donnees */
    private static function texte(array $donnees, string $champ): string
    {
        $valeur = $donnees[$champ] ?? '';

        return \is_scalar($valeur) ? trim((string) $valeur) : '';
    }

    /** Texte en Markdown simplifié : fins de ligne unifiées, longueur bornée. */
    private static function corps(array $donnees): string
    {
        $corps = \is_scalar($donnees['corps'] ?? null) ? (string) $donnees['corps'] : '';

        return mb_substr(rtrim(str_replace(["\r\n", "\r"], "\n", $corps)), 0, MarkdownSite::LONGUEUR_MAX);
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string>         $erreurs
     */
    private static function date(array $donnees, string $champ, string $libelle, array &$erreurs): ?\DateTimeImmutable
    {
        $saisie = self::texte($donnees, $champ);
        if ('' === $saisie) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);
        if (false === $date || $date->format('Y-m-d') !== $saisie) {
            $erreurs[] = $libelle.' n\'est pas valable.';

            return null;
        }

        return $date;
    }
}
