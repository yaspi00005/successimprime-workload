<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
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

    private const MESSAGES_PAR_DEFAUT = [
        '[NOUVEAU] Textile DTF dès 1 pièce',
        'Payez par [Orange Money]',
        'Commandez sur [succesimprim.com]',
    ];

    private const MOIS_COURTS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/public/uploads/tv')]
        private readonly string $dossierMedias,
        #[Autowire('%kernel.project_dir%/var/ecran-tv/reglages.json')]
        private readonly string $fichierReglages,
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

        $legendes = $this->reglages()['legendes'];
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
     * @return array{messages: list<string>, noteGoogle: ?float, avisGoogle: ?int, dureeImage: int, legendes: array<string, string>}
     */
    public function reglages(): array
    {
        $lus = is_file($this->fichierReglages)
            ? json_decode((string) file_get_contents($this->fichierReglages), true)
            : null;
        $lus = \is_array($lus) ? $lus : [];

        return [
            'messages' => array_values(array_filter(array_map('strval', $lus['messages'] ?? self::MESSAGES_PAR_DEFAUT))),
            'noteGoogle' => isset($lus['noteGoogle']) ? (float) $lus['noteGoogle'] : null,
            'avisGoogle' => isset($lus['avisGoogle']) ? (int) $lus['avisGoogle'] : null,
            'dureeImage' => max(4, min(60, (int) ($lus['dureeImage'] ?? 8))),
            'legendes' => array_map('strval', \is_array($lus['legendes'] ?? null) ? $lus['legendes'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $reglages
     */
    public function enregistrerReglages(array $reglages): void
    {
        $dossier = \dirname($this->fichierReglages);

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier des réglages de l\'écran TV.');
        }

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
