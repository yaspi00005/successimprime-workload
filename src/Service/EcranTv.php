<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Repository\PartenaireRepository;
use App\Repository\RealisationRepository;
use App\Service\Site\ImagesSite;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Écran TV de l'accueil (maquette 42) : chiffres publics, médias qui
 * défilent et réglages.
 *
 * Uniquement des volumes et des pourcentages, jamais de montants ni de
 * noms de clients (docs/indicateurs.md, section 12) : la page est
 * publique.
 */
final class EcranTv
{
    public const EXTENSIONS_IMAGE = ['jpg', 'jpeg', 'png', 'webp'];
    public const EXTENSIONS_VIDEO = ['mp4', 'webm'];

    private const STATUTS_IMPRIMES = [
        CommandesDetails::PRODUCTION_TERMINEE,
        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
        CommandesDetails::PRODUCTION_EN_LIVRAISON,
        CommandesDetails::PRODUCTION_LIVREE,
    ];

    // Même regle que Commandes::getStatutTravaux() pour « en preparation ».
    private const STATUTS_EN_PREPARATION = [
        CommandesDetails::PRODUCTION_A_PRODUIRE,
        CommandesDetails::PRODUCTION_EN_COURS,
        CommandesDetails::PRODUCTION_TERMINEE,
        CommandesDetails::PRODUCTION_NON_REQUISE,
    ];

    private const TAILLE_FLUX = 6;

    private const MOIS_COURTS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/public/uploads/tv')]
        private readonly string $dossierMedias,
        #[Autowire('%kernel.project_dir%/var/ecran-tv/reglages.json')]
        private readonly string $fichierReglages,
        private readonly RealisationRepository $realisations,
        private readonly PartenaireRepository $partenaires,
        private readonly ImagesSite $imagesSite,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant): array
    {
        $il12Mois = $maintenant->modify('-12 months');
        $il24Mois = $maintenant->modify('-24 months');
        $debutJour = $maintenant->setTime(0, 0);

        $livrees = $this->commandesLivrees();
        $m2 = $this->metresCarres();
        $clients = $this->clientsServis();

        return [
            'livrees' => [
                'total' => $livrees(null, null),
                'variation' => TableauDeBordService::variation($livrees($il12Mois, $maintenant), $livrees($il24Mois, $il12Mois)),
            ],
            'metresCarres' => [
                'total' => $m2(null, null),
                'variation' => TableauDeBordService::variation($m2($il12Mois, $maintenant), $m2($il24Mois, $il12Mois)),
            ],
            'clients' => [
                'total' => $clients(null, null),
                'variation' => TableauDeBordService::variation($clients($il12Mois, $maintenant), $clients($il24Mois, $il12Mois)),
            ],
            'enLigne' => [
                'aujourdhui' => $this->commandesEnLigne($debutJour, $maintenant),
                'mois' => $this->commandesEnLigne($maintenant->modify('first day of this month')->setTime(0, 0), $maintenant),
            ],
            'parMois' => $this->commandesParMois($maintenant),
            'pretes' => $this->commandesPretes(),
            'aujourdhui' => [
                'commandes' => $this->commandesDuJour($debutJour, $maintenant),
                'metresCarres' => $m2($debutJour, $maintenant->modify('+1 second')),
            ],
            'flux' => $this->fluxEnLigne($maintenant),
            'misAJour' => $maintenant->format('H:i'),
        ];
    }

    /**
     * @return list<array{fichier: string, type: 'image'|'video', legende: string}>
     */
    public function medias(): array
    {
        if (!is_dir($this->dossierMedias)) {
            return [];
        }

        $legendes = $this->legendes();
        $medias = [];

        foreach (scandir($this->dossierMedias) ?: [] as $fichier) {
            $type = self::typeMedia($fichier);

            if ($type === null || !is_file($this->dossierMedias.'/'.$fichier)) {
                continue;
            }

            $medias[] = [
                'fichier' => $fichier,
                'type' => $type,
                'legende' => $legendes[$fichier] ?? self::legendeDepuisNom($fichier),
            ];
        }

        usort($medias, static fn (array $a, array $b) => strnatcasecmp($a['fichier'], $b['fichier']));

        return $medias;
    }

    public function dossierMedias(): string
    {
        return $this->dossierMedias;
    }

    /**
     * Légendes des médias, modifiées directement (hors programme publié).
     *
     * @return array<string, string>
     */
    public function legendes(): array
    {
        $lus = $this->lire()['legendes'] ?? [];

        return array_map('strval', \is_array($lus) ? $lus : []);
    }

    /**
     * @param array<string, string> $legendes
     */
    public function enregistrerLegendes(array $legendes): void
    {
        $this->ecrire(['legendes' => $legendes]);
    }

    /**
     * Après un renommage des fichiers : légendes et scènes suivent.
     *
     * @param array<string, string> $nouveaux ancien nom => nouveau nom
     */
    public function renommerMedias(array $nouveaux): void
    {
        $lus = $this->lire();
        $valeurs = ['legendes' => []];

        foreach ($this->legendes() as $fichier => $legende) {
            if (isset($nouveaux[$fichier])) {
                $valeurs['legendes'][$nouveaux[$fichier]] = $legende;
            }
        }

        foreach (['brouillon', 'publie'] as $cle) {
            if (!\is_array($lus[$cle]['scenes'] ?? null)) {
                continue;
            }

            foreach ($lus[$cle]['scenes'] as $i => $scene) {
                $lus[$cle]['scenes'][$i]['medias'] = array_values(array_filter(array_map(
                    static fn ($f) => $nouveaux[$f] ?? null,
                    \is_array($scene['medias'] ?? null) ? $scene['medias'] : []
                )));
            }

            $valeurs[$cle] = $lus[$cle];
        }

        $this->ecrire($valeurs);
    }

    /**
     * Programme publié (celui de l'écran) ou brouillon (celui de l'aperçu).
     *
     * @return array<string, mixed>
     */
    public function programme(bool $publie = true): array
    {
        $lus = $this->lire();
        $programme = $lus[$publie ? 'publie' : 'brouillon'] ?? ($publie ? null : ($lus['publie'] ?? null));

        if (\is_array($programme)) {
            return ProgrammeEcranTv::normaliser($programme);
        }

        // Premier passage : le programme de départ, avec les messages et la note de la v1.
        $defaut = ProgrammeEcranTv::defaut();

        foreach (['messages', 'noteGoogle', 'avisGoogle'] as $cle) {
            if (\array_key_exists($cle, $lus)) {
                $defaut[$cle] = $lus[$cle];
            }
        }

        return ProgrammeEcranTv::normaliser($defaut);
    }

    /**
     * @param array<mixed> $programme
     */
    public function enregistrerBrouillon(array $programme): void
    {
        $this->ecrire(['brouillon' => ProgrammeEcranTv::normaliser($programme)]);
    }

    public function publier(\DateTimeImmutable $maintenant): void
    {
        $this->ecrire(['publie' => $this->programme(false), 'publieLe' => $maintenant->format(\DATE_ATOM)]);
    }

    public function aDesModificationsNonPubliees(): bool
    {
        return $this->programme(false) != $this->programme(true);
    }

    public function publieLe(): ?\DateTimeImmutable
    {
        $date = $this->lire()['publieLe'] ?? null;

        return \is_string($date) ? new \DateTimeImmutable($date) : null;
    }

    /**
     * Jeton du lien de l'écran : la Smart TV l'ouvre sans compte utilisateur.
     */
    public function jeton(): string
    {
        $jeton = $this->lire()['jeton'] ?? null;

        return \is_string($jeton) && \strlen($jeton) >= 24 ? $jeton : $this->nouveauJeton();
    }

    /**
     * L'ancien lien cesse de marcher : à faire si le lien a circulé.
     */
    public function nouveauJeton(): string
    {
        $jeton = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->ecrire(['jeton' => $jeton]);

        return $jeton;
    }

    public function jetonValide(string $jeton): bool
    {
        $attendu = $this->lire()['jeton'] ?? null;

        return \is_string($attendu) && $attendu !== '' && hash_equals($attendu, $jeton);
    }

    /**
     * L'écran est passé : sert à la carte « Écran boutique » de l'admin.
     */
    public function noterPassage(): void
    {
        $fichier = \dirname($this->fichierReglages).'/passage';

        if (is_dir(\dirname($fichier)) || @mkdir(\dirname($fichier), 0775, true)) {
            @touch($fichier);
        }
    }

    public function dernierPassage(): ?\DateTimeImmutable
    {
        $fichier = \dirname($this->fichierReglages).'/passage';
        clearstatcache(true, $fichier);

        return is_file($fichier) ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($fichier)) : null;
    }

    /**
     * Ce que l'écran affiche à cet instant : ouvert ou fermé, scènes du
     * moment avec leurs médias, et une signature qui change dès que
     * l'écran doit se recharger (publication, scène qui entre ou sort).
     *
     * @param array<string, mixed> $programme
     *
     * @return array{ouvert: bool, prochaineOuverture: ?string, scenes: list<array<string, mixed>>, signature: string}
     */
    public function etat(array $programme, \DateTimeImmutable $maintenant, bool $ignorerHoraires = false): array
    {
        $ouvert = $ignorerHoraires || ProgrammeEcranTv::estOuvert($programme['horaires'], $maintenant);
        $medias = [];

        foreach ($this->medias() as $media) {
            $medias[$media['fichier']] = $media;
        }

        $scenes = [];

        foreach (ProgrammeEcranTv::scenesDuMoment($programme['scenes'], $maintenant) as $scene) {
            $choisis = array_values(array_filter(array_map(static fn (string $f) => $medias[$f] ?? null, $scene['medias'])));

            if ($scene['type'] === ProgrammeEcranTv::TYPE_PHOTOS) {
                // Aucune photo cochée : toutes les images de la bibliothèque, puis les réalisations publiées du site.
                $choisis = $choisis !== [] ? $choisis : [...array_values(array_filter($medias, static fn (array $m) => $m['type'] === 'image')), ...$this->photosRealisations()];
                $choisis = array_values(array_filter($choisis, static fn (array $m) => $m['type'] === 'image'));

                if ($choisis === []) {
                    continue;
                }
            }

            if ($scene['type'] === ProgrammeEcranTv::TYPE_PARTENAIRES) {
                $scene['logos'] = $this->logosPartenaires();

                if ($scene['logos'] === []) {
                    continue;
                }
            }

            if ($scene['type'] === ProgrammeEcranTv::TYPE_VIDEO && ($choisis === [] || $choisis[0]['type'] !== 'video')) {
                continue;
            }

            if ($scene['type'] === ProgrammeEcranTv::TYPE_AVIS && $programme['avis'] === []) {
                continue;
            }

            $scene['medias'] = $choisis;
            $scenes[] = $scene;
        }

        $signature = hash('xxh64', json_encode([$programme, $ouvert, array_column($scenes, 'id'), $this->publieLe()?->getTimestamp()]) ?: '');

        return [
            'ouvert' => $ouvert,
            'prochaineOuverture' => $ouvert ? null : ProgrammeEcranTv::prochaineOuverture($programme['horaires'], $maintenant),
            'scenes' => $scenes,
            'signature' => $signature,
        ];
    }

    /**
     * Réalisations publiées sur le site, en photos pour l'écran : le titre
     * seulement, jamais le nom du client (l'écran est vu de tous).
     *
     * @return list<array{fichier: string, type: string, legende: string, chemin: string}>
     */
    private function photosRealisations(): array
    {
        $photos = [];
        foreach (\array_slice($this->realisations->publiees(), 0, 12) as $r) {
            $image = $r->getImagePrincipale();
            $fichier = $image === null ? null : $this->imagesSite->fichier($image);
            if ($fichier === null) {
                continue;
            }
            $categorie = $r->getCategorie()?->getNom();
            $photos[] = [
                'fichier' => basename($fichier['chemin']),
                'type' => 'image',
                'legende' => $r->getTitre().($categorie !== null && $categorie !== '' ? ' · '.$categorie : ''),
                'chemin' => $fichier['chemin'],
            ];
        }

        return $photos;
    }

    /**
     * Logos des partenaires montrés sur le site (visibles et avec leur accord).
     *
     * @return list<array{nom: string, chemin: string}>
     */
    private function logosPartenaires(): array
    {
        $logos = [];
        foreach ($this->partenaires->visibles() as $p) {
            $fichier = $p->getLogo() === null ? null : $this->imagesSite->fichier($p->getLogo());
            if ($fichier !== null) {
                $logos[] = ['nom' => $p->getNom(), 'chemin' => $fichier['chemin']];
            }
        }

        return \array_slice($logos, 0, 12);
    }

    /**
     * Tout ce que la page de l'écran affiche.
     *
     * @return array<string, mixed>
     */
    public function vue(\DateTimeImmutable $maintenant, bool $apercu): array
    {
        $programme = $this->programme(!$apercu);
        $etat = $this->etat($programme, $maintenant, $apercu);

        foreach ($etat['scenes'] as $i => $scene) {
            $etat['scenes'][$i]['titreMorceaux'] = self::morceauxMessage($scene['titre']);
        }

        return [
            'programme' => $programme,
            'etat' => $etat,
            'donnees' => $this->donnees($maintenant),
            'messages' => array_map(self::morceauxMessage(...), $programme['messages']),
            'apercu' => $apercu,
        ];
    }

    /**
     * Chiffres relus par l'écran toutes les minutes.
     *
     * @return array<string, mixed>
     */
    public function rafraichissement(\DateTimeImmutable $maintenant, bool $apercu): array
    {
        $programme = $this->programme(!$apercu);
        $etat = $this->etat($programme, $maintenant, $apercu);

        return $this->donnees($maintenant) + ['signature' => $etat['signature'], 'ouvert' => $etat['ouvert']];
    }

    /**
     * @return array<string, mixed>
     */
    private function lire(): array
    {
        $lus = is_file($this->fichierReglages)
            ? json_decode((string) file_get_contents($this->fichierReglages), true)
            : null;

        return \is_array($lus) ? $lus : [];
    }

    /**
     * @param array<string, mixed> $valeurs
     */
    private function ecrire(array $valeurs): void
    {
        $dossier = \dirname($this->fichierReglages);

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier des réglages de l\'écran TV.');
        }

        $reglages = array_merge($this->lire(), $valeurs);
        file_put_contents($this->fichierReglages, json_encode($reglages, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
    }

    /**
     * @return 'image'|'video'|null
     */
    public static function typeMedia(string $fichier): ?string
    {
        if (str_starts_with($fichier, '.')) {
            return null;
        }

        $extension = strtolower(pathinfo($fichier, \PATHINFO_EXTENSION));

        return match (true) {
            \in_array($extension, self::EXTENSIONS_IMAGE, true) => 'image',
            \in_array($extension, self::EXTENSIONS_VIDEO, true) => 'video',
            default => null,
        };
    }

    /**
     * « 02_habillage-boutique.jpg » donne « Habillage boutique ».
     */
    public static function legendeDepuisNom(string $fichier): string
    {
        $nom = pathinfo($fichier, \PATHINFO_FILENAME);
        $nom = (string) preg_replace('/^\d+[\s_-]*/', '', $nom);
        $nom = trim(str_replace(['_', '-'], ' ', $nom));

        return $nom === '' ? '' : mb_strtoupper(mb_substr($nom, 0, 1)).mb_substr($nom, 1);
    }

    /**
     * « CMD-2026-0151 » donne « 0151 » : le numéro seul, sans nom.
     */
    public static function numeroCourt(?string $numero, int $id): string
    {
        if ($numero !== null && preg_match('/(\d+)\s*$/', $numero, $m)) {
            return $m[1];
        }

        return (string) $id;
    }

    /**
     * Découpe un message en morceaux : le texte entre crochets est mis en avant.
     *
     * @return list<array{texte: string, fort: bool}>
     */
    public static function morceauxMessage(string $message): array
    {
        $morceaux = [];

        foreach (preg_split('/(\[[^\]]+\])/u', $message, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [] as $morceau) {
            $fort = str_starts_with($morceau, '[') && str_ends_with($morceau, ']');
            $morceaux[] = ['texte' => $fort ? substr($morceau, 1, -1) : $morceau, 'fort' => $fort];
        }

        return $morceaux;
    }

    /**
     * Commandes dont toutes les lignes sont livrées (ou annulées).
     *
     * @return \Closure(?\DateTimeImmutable, ?\DateTimeImmutable): int
     */
    private function commandesLivrees(): \Closure
    {
        return function (?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): int {
            $qb = $this->em->createQueryBuilder()
                ->select('COUNT(c.id)')
                ->from(Commandes::class, 'c')
                ->where('c.deleted = false')
                ->andWhere(sprintf('EXISTS (SELECT l1.id FROM %s l1 WHERE l1.commande = c AND l1.statutProduction = :livree)', CommandesDetails::class))
                ->andWhere(sprintf('NOT EXISTS (SELECT l2.id FROM %s l2 WHERE l2.commande = c AND l2.statutProduction NOT IN (:finies))', CommandesDetails::class))
                ->setParameter('livree', CommandesDetails::PRODUCTION_LIVREE)
                ->setParameter('finies', [CommandesDetails::PRODUCTION_LIVREE, CommandesDetails::PRODUCTION_ANNULEE]);

            return (int) self::periode($qb, 'c.dateCommande', $debut, $fin)->getQuery()->getSingleScalarResult();
        };
    }

    /**
     * m² des lignes imprimées : surface unitaire × quantité.
     *
     * @return \Closure(?\DateTimeImmutable, ?\DateTimeImmutable): int
     */
    private function metresCarres(): \Closure
    {
        return function (?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): int {
            $qb = $this->em->createQueryBuilder()
                ->select('COALESCE(SUM(d.surface * d.quantite), 0)')
                ->from(CommandesDetails::class, 'd')
                ->join('d.commande', 'c')
                ->where('c.deleted = false')
                ->andWhere('d.statutProduction IN (:imprimes)')
                ->setParameter('imprimes', self::STATUTS_IMPRIMES);

            return (int) round((float) self::periode($qb, 'COALESCE(d.productionTermineeLe, c.dateCommande)', $debut, $fin)->getQuery()->getSingleScalarResult());
        };
    }

    /**
     * @return \Closure(?\DateTimeImmutable, ?\DateTimeImmutable): int
     */
    private function clientsServis(): \Closure
    {
        return function (?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): int {
            $qb = $this->em->createQueryBuilder()
                ->select('COUNT(DISTINCT IDENTITY(c.clients))')
                ->from(Commandes::class, 'c')
                ->where('c.deleted = false');

            return (int) self::periode($qb, 'c.dateCommande', $debut, $fin)->getQuery()->getSingleScalarResult();
        };
    }

    private function commandesEnLigne(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.canal = :enLigne')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande <= :fin')
            ->setParameter('enLigne', Commandes::CANAL_EN_LIGNE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function commandesDuJour(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande <= :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dernières commandes en ligne : le produit et la quantité seulement,
     * jamais le client ni le montant.
     *
     * @return list<array{id: int, libelle: string, minutes: int, couleur: int}>
     */
    private function fluxEnLigne(\DateTimeImmutable $maintenant): array
    {
        $commandes = $this->em->createQueryBuilder()
            ->select('c.id, c.dateCommande')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.canal = :enLigne')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('enLigne', Commandes::CANAL_EN_LIGNE)
            ->setParameter('depuis', $maintenant->modify('-7 days'))
            ->orderBy('c.dateCommande', 'DESC')
            ->setMaxResults(self::TAILLE_FLUX)
            ->getQuery()
            ->getArrayResult();

        if ($commandes === []) {
            return [];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.commande) AS commande, p.nom AS produit, d.quantite')
            ->from(CommandesDetails::class, 'd')
            ->leftJoin('d.produit', 'p')
            ->where('d.commande IN (:commandes)')
            ->setParameter('commandes', array_column($commandes, 'id'))
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $parCommande = [];

        foreach ($lignes as $ligne) {
            $parCommande[(int) $ligne['commande']][] = $ligne;
        }

        $flux = [];

        foreach ($commandes as $commande) {
            $id = (int) $commande['id'];
            $libelle = self::libelleFlux($parCommande[$id] ?? []);
            $flux[] = [
                'id' => $id,
                'libelle' => $libelle,
                'minutes' => max(0, (int) floor(($maintenant->getTimestamp() - $commande['dateCommande']->getTimestamp()) / 60)),
                'couleur' => (int) (crc32($libelle) % 6),
            ];
        }

        return $flux;
    }

    /**
     * « Roll-up × 5 », « T-shirts DTF × 40 + 2 autres » : le nom du produit
     * au catalogue, jamais la désignation saisie (elle peut citer le client).
     *
     * @param list<array{produit: ?string, quantite: int|string}> $lignes
     */
    public static function libelleFlux(array $lignes): string
    {
        if ($lignes === []) {
            return 'Nouvelle commande';
        }

        $premiere = $lignes[0];
        $libelle = trim((string) ($premiere['produit'] ?? '')) ?: 'Impression';
        $quantite = (int) $premiere['quantite'];

        if ($quantite > 1) {
            $libelle .= ' × '.$quantite;
        }

        $autres = \count($lignes) - 1;

        return $autres > 0 ? sprintf('%s + %d autre%s', $libelle, $autres, $autres > 1 ? 's' : '') : $libelle;
    }

    /**
     * @return list<array{libelle: string, nombre: int, enCours: bool}>
     */
    private function commandesParMois(\DateTimeImmutable $maintenant): array
    {
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0)->modify('-11 months');
        $dates = $this->em->createQueryBuilder()
            ->select('c.dateCommande')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut')
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getSingleColumnResult();

        $parCle = [];

        foreach ($dates as $date) {
            $cle = ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date))->format('Y-m');
            $parCle[$cle] = ($parCle[$cle] ?? 0) + 1;
        }

        $serie = [];

        for ($i = 0; $i < 12; ++$i) {
            $mois = $debut->modify(sprintf('+%d months', $i));
            $serie[] = [
                'libelle' => self::MOIS_COURTS[(int) $mois->format('n') - 1],
                'nombre' => $parCle[$mois->format('Y-m')] ?? 0,
                'enCours' => $i === 11,
            ];
        }

        return $serie;
    }

    /**
     * Numéros des commandes prêtes à retirer, sans nom de client.
     *
     * @return list<string>
     */
    private function commandesPretes(): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('c.id, c.numero')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')
            ->andWhere('c.recupereParClient = false')
            ->andWhere(sprintf('EXISTS (SELECT l1.id FROM %s l1 WHERE l1.commande = c AND l1.statutProduction = :prete)', CommandesDetails::class))
            ->andWhere(sprintf('NOT EXISTS (SELECT l2.id FROM %s l2 WHERE l2.commande = c AND l2.statutProduction IN (:enPreparation))', CommandesDetails::class))
            ->setParameter('prete', CommandesDetails::PRODUCTION_PRETE_LIVRAISON)
            ->setParameter('enPreparation', self::STATUTS_EN_PREPARATION)
            ->orderBy('c.dateCommande', 'ASC')
            ->setMaxResults(12)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l) => self::numeroCourt($l['numero'], (int) $l['id']), $lignes);
    }

    private static function periode(QueryBuilder $qb, string $champ, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): QueryBuilder
    {
        if ($debut !== null) {
            $qb->andWhere($champ.' >= :debut')->setParameter('debut', $debut);
        }

        if ($fin !== null) {
            $qb->andWhere($champ.' < :fin')->setParameter('fin', $fin);
        }

        return $qb;
    }
}
