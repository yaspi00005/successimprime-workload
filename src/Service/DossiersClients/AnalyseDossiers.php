<?php

namespace App\Service\DossiersClients;

use App\Entity\ActionCaisse;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\FichierAClasser;
use App\Entity\FichierDossier;
use App\Entity\LienDossierClient;
use App\Entity\User;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\ProduitsClient\ControleFichierLie;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Analyse des dossiers des clients, faite par la tâche planifiée
 * (app:dossiers-clients:analyser) et jamais pendant l'affichage d'une
 * page lourde :
 *
 * - rapide (« Surveiller les changements », toutes les 5 minutes) : les
 *   dossiers liés sont parcourus, seuls les fichiers ajoutés ou modifiés
 *   (taille ou date) sont relus ; le dossier « À classer » aussi ;
 * - complète (« Analyse complète chaque nuit », à l'heure réglée) : en plus,
 *   les dossiers des clients sont recherchés (DS02) et tout ce qui manque
 *   (miniature, dimensions, texte des PDF) est refait.
 *
 * Lecture seule sur le disque, sauf trois gestes demandés : poser la
 * marque « .succes » dans un dossier lié, créer le dossier d'un nouveau
 * client, et ranger la copie d'un fichier classé (ClassementFichiers).
 */
final class AnalyseDossiers
{
    public const MARQUE = '.succes';
    private const PROFONDEUR = 4;
    private const MAX_FICHIERS_CLIENT = 5000;
    private const MAX_COMPTE = 2000;
    /** Fichiers que l'on ne montre jamais. */
    private const IGNORES = ['thumbs.db', 'desktop.ini', '.ds_store', self::MARQUE];

    /** @var array<int, array{client: Clients, noms: list<string>, code: string}>|null */
    private ?array $clientsEnMemoire = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDossiers $reglages,
        private readonly DossierClient $dossiers,
        private readonly Miniatures $miniatures,
        private readonly ControleFichierLie $controle,
        private readonly ActionsCaisse $actions,
        private readonly JournalDossiers $journal,
    ) {
    }

    /* ---------- Tout ---------- */

    /**
     * @param callable(string): void|null $suivi
     *
     * @return array{complete: bool, dossiers: int, lies: int, suggeres: int, crees?: int, fichiers: int, a_classer: int, actions: int, erreurs: list<string>}
     */
    public function analyser(bool $complete, ?callable $suivi = null, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $bilan = ['complete' => $complete, 'dossiers' => 0, 'lies' => 0, 'suggeres' => 0, 'fichiers' => 0, 'a_classer' => 0, 'actions' => 0, 'erreurs' => []];
        if ($this->reglages->enLigne()) {
            $bilan['erreurs'][] = 'Site en ligne : les dossiers de l\'atelier ne sont pas lisibles d\'ici.';

            return $bilan;
        }
        $racine = $this->reglages->racine();
        $debut = microtime(true);
        if ('' === $racine || !is_dir($racine)) {
            $bilan['erreurs'][] = '' === $racine ? 'Dossier racine pas réglé.' : 'Dossier racine introuvable : '.$racine;
        } else {
            $suivi && $suivi($complete ? 'Recherche des dossiers des clients' : 'Dossiers liés');
            $r = $this->rapprocher(null, $complete);
            $bilan['dossiers'] = $r['dossiers'];
            $bilan['lies'] = $r['lies'];
            $bilan['suggeres'] = $r['suggeres'];
            $bilan['crees'] = $this->dossiersNouveauxClients($maintenant);
            $lies = [];
            foreach ($this->em->getRepository(LienDossierClient::class)->findBy(['etat' => LienDossierClient::LIE]) as $lien) {
                $lies[(int) $lien->getClient()->getId()] = $lien->getNomDossier();
            }
            foreach ($lies as $idClient => $nomDossier) {
                $client = $this->em->find(Clients::class, $idClient);
                if (null === $client) {
                    continue;
                }
                $suivi && $suivi('Dossier '.$nomDossier);
                $bilan['fichiers'] += $this->analyserClient($client, $complete, $maintenant);
                // La mémoire reste petite même avec des centaines de dossiers.
                $this->em->clear();
                $this->clientsEnMemoire = null;
            }
        }
        $suivi && $suivi('Fichiers à classer');
        $bilan['a_classer'] = $this->analyserAClasser($maintenant);
        $bilan['actions'] = $this->actionsEnRetard($maintenant);
        $this->em->flush();
        $this->noterEtat($complete ? 'complete' : 'rapide', $maintenant, $bilan + ['duree' => (int) round(microtime(true) - $debut)]);

        return $bilan;
    }

    /** L'analyse complète de la nuit est-elle due (réglage allumé, heure passée, pas encore faite aujourd'hui) ? */
    public function completeDue(\DateTimeImmutable $maintenant): bool
    {
        if (!$this->reglages->valeur('analyse_nuit')) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', (string) ($this->reglages->valeur('analyse_heure') ?: '02:00')) + [1 => 0]);
        $heure = $maintenant->setTime($h, $m);
        $derniere = $this->etat()['complete']['le'] ?? null;

        return $maintenant >= $heure && (null === $derniere || new \DateTimeImmutable($derniere) < $heure);
    }

    /* ---------- Dossiers des clients (DS02) ---------- */

    /**
     * Noms des dossiers de premier niveau de la racine (sans les dossiers
     * cachés ni « À classer » s'il y est rangé).
     *
     * @return list<string>
     */
    public function dossiersRacine(): array
    {
        $racine = $this->reglages->racine();
        if ('' === $racine || !is_dir($racine)) {
            return [];
        }
        $aClasser = $this->reglages->dossierAClasser();
        $noms = [];
        foreach (@scandir($racine) ?: [] as $nom) {
            $chemin = $racine.\DIRECTORY_SEPARATOR.$nom;
            if ('.' === $nom[0] || '$' === $nom[0] || !is_dir($chemin) || ('' !== $aClasser && realpath($chemin) === realpath($aClasser))) {
                continue;
            }
            $noms[] = $nom;
        }
        natcasesort($noms);

        return array_values($noms);
    }

    /**
     * Retrouve le dossier de chaque client : suit les dossiers renommés
     * grâce à leur marque, lie d'office ceux qui portent le code du client,
     * propose les autres d'après la ressemblance des noms.
     *
     * @return array{dossiers: int, lies: int, suggeres: int, sans_client: int}
     */
    public function rapprocher(?User $par = null, bool $suggestions = true): array
    {
        $maintenant = new \DateTimeImmutable();
        $racine = $this->reglages->racine();
        $dossiers = $this->dossiersRacine();
        $depot = $this->em->getRepository(LienDossierClient::class);
        /** @var array<int, LienDossierClient> $liens */
        $liens = [];
        foreach ($depot->findAll() as $l) {
            $liens[(int) $l->getClient()->getId()] = $l;
        }
        // Marques posées : identifiant public du client => dossier.
        $marques = [];
        foreach ($dossiers as $d) {
            $m = self::lireMarque($racine.\DIRECTORY_SEPARATOR.$d);
            if (null !== $m) {
                $marques[$m['client']] = $d;
            }
        }
        $existe = array_flip($dossiers);
        $pris = [];
        // 1. Dossiers liés : présents, renommés (retrouvés par leur marque) ou absents.
        foreach ($liens as $l) {
            if (!$l->estLie()) {
                continue;
            }
            $publicId = $l->getClient()->getPublicId()->toRfc4122();
            if (!isset($existe[$l->getNomDossier()]) && isset($marques[$publicId])) {
                $ancien = $l->getNomDossier();
                $l->renommer($marques[$publicId]);
                $this->journal->noter('liaison', $l->getClient(), null, ['dossier' => $l->getNomDossier(), 'detail' => 'dossier renommé, suivi par sa marque (avant : '.$ancien.')']);
            }
            $l->setAbsent(!isset($existe[$l->getNomDossier()]));
            $pris[$l->getNomDossier()] = true;
        }
        // 2. Marque d'un client sans liaison (base restaurée) : liée d'office.
        foreach ($marques as $publicId => $d) {
            if (isset($pris[$d])) {
                continue;
            }
            $client = $this->em->getRepository(Clients::class)->findOneBy(['publicId' => \Symfony\Component\Uid\Uuid::fromString($publicId)]);
            if (null === $client || (isset($liens[$client->getId()]) && $liens[$client->getId()]->estLie())) {
                continue;
            }
            $l = $liens[$client->getId()] ?? new LienDossierClient($client, $d, LienDossierClient::LIE, 100);
            $l->lier($d, 100, null, $maintenant)->setMarquePosee(true);
            $this->em->persist($l);
            $liens[(int) $client->getId()] = $l;
            $pris[$d] = true;
        }
        // 3. Les autres clients : code dans le nom, puis ressemblance.
        if ($suggestions) {
            $libres = array_values(array_filter($dossiers, static fn (string $d): bool => !isset($pris[$d])));
            $candidats = [];
            foreach ($this->clients() as $id => $c) {
                if (!isset($liens[$id]) || !$liens[$id]->estLie()) {
                    $candidats[$id] = ['code' => $c['code'], 'noms' => $c['noms']];
                }
            }
            foreach (Rapprochement::proposer($candidats, $libres) as $id => $p) {
                $l = $liens[$id] ?? null;
                $client = $this->clients()[$id]['client'];
                if (Rapprochement::INTROUVABLE === $p['statut']) {
                    if (null !== $l) {
                        $this->em->remove($l);
                        unset($liens[$id]);
                    }
                    continue;
                }
                $fichiers = $this->compter($racine.\DIRECTORY_SEPARATOR.$p['dossier']);
                if (null === $l) {
                    $l = new LienDossierClient($client, (string) $p['dossier'], LienDossierClient::SUGGERE, $p['score']);
                    $this->em->persist($l);
                    $liens[$id] = $l;
                }
                if (Rapprochement::LIE === $p['statut']) {
                    $l->lier((string) $p['dossier'], 100, null, $maintenant)->setNombreFichiers($fichiers);
                    $l->setMarquePosee($this->poserMarque($racine.\DIRECTORY_SEPARATOR.$p['dossier'], $client));
                    $this->journal->noter('liaison', $client, null, ['dossier' => $p['dossier'], 'detail' => 'code du client dans le nom du dossier']);
                } else {
                    $l->suggerer((string) $p['dossier'], $p['score'], $fichiers);
                }
            }
        }
        $this->em->flush();
        $lies = \count(array_filter($liens, static fn (LienDossierClient $l): bool => $l->estLie()));
        $suggeres = \count(array_filter($liens, static fn (LienDossierClient $l): bool => !$l->estLie()));
        $utilises = array_flip(array_map(static fn (LienDossierClient $l): string => $l->getNomDossier(), $liens));
        $bilan = ['dossiers' => \count($dossiers), 'lies' => $lies, 'suggeres' => $suggeres, 'sans_client' => \count(array_filter($dossiers, static fn (string $d): bool => !isset($utilises[$d])))];
        $this->noterEtat('rapprochement', $maintenant, $bilan);

        return $bilan;
    }

    /**
     * Nouveau client (créé ces 2 derniers jours, sans dossier) : un dossier
     * proche est proposé s'il existe, sinon son dossier est créé avec les
     * sous-dossiers du modèle. Au-delà de 10 nouveaux clients d'un coup
     * (import), rien n'est créé : on choisit dans « Lier les dossiers ».
     *
     * @return int dossiers créés
     */
    public function dossiersNouveauxClients(\DateTimeImmutable $maintenant): int
    {
        $nouveaux = $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
            ->leftJoin(LienDossierClient::class, 'l', 'WITH', 'l.client = c')
            ->where('l.id IS NULL AND c.createdAt >= :depuis AND c.id < :demo')
            ->setParameter('depuis', \DateTime::createFromImmutable($maintenant->modify('-2 days')))
            ->setParameter('demo', 900000)
            ->setMaxResults(11)->getQuery()->getResult();
        if ([] === $nouveaux || \count($nouveaux) > 10) {
            return 0;
        }
        $racine = $this->reglages->racine();
        $pris = array_flip(array_map(static fn (LienDossierClient $l): string => $l->getNomDossier(), $this->em->getRepository(LienDossierClient::class)->findAll()));
        $libres = array_values(array_filter($this->dossiersRacine(), static fn (string $d): bool => !isset($pris[$d])));
        $crees = 0;
        foreach ($nouveaux as $client) {
            $noms = array_values(array_unique(array_filter([$client->getNomComplet(), (string) $client->getRaisonSociale()], static fn (string $n): bool => '' !== trim($n))));
            $p = Rapprochement::proposer([(int) $client->getId() => ['code' => (string) $client->getCode(), 'noms' => $noms]], $libres)[(int) $client->getId()] ?? null;
            if (null !== $p && Rapprochement::INTROUVABLE !== $p['statut']) {
                $l = new LienDossierClient($client, (string) $p['dossier'], LienDossierClient::SUGGERE, $p['score']);
                $l->suggerer((string) $p['dossier'], $p['score'], $this->compter($racine.\DIRECTORY_SEPARATOR.$p['dossier']));
                $this->em->persist($l);
                continue;
            }
            try {
                $this->creerDossier($client, null);
                ++$crees;
            } catch (\DomainException) {
                // Dossier racine en lecture seule : le client reste « introuvable », à créer depuis l'écran.
            }
        }
        $this->em->flush();

        return $crees;
    }

    /**
     * Valide une suggestion ou choisit un autre dossier : liaison, marque
     * « .succes » posée si possible, puis lecture du dossier.
     */
    public function lier(Clients $client, string $nomDossier, ?User $par): LienDossierClient
    {
        $racine = $this->reglages->racine();
        if (!DossierClient::nomSur($nomDossier) || '' === $racine || !is_dir($racine.\DIRECTORY_SEPARATOR.$nomDossier)) {
            throw new \DomainException('Ce dossier n\'existe pas dans le dossier des clients.');
        }
        $depot = $this->em->getRepository(LienDossierClient::class);
        $autre = $depot->findOneBy(['nomDossier' => $nomDossier, 'etat' => LienDossierClient::LIE]);
        if (null !== $autre && $autre->getClient()->getId() !== $client->getId()) {
            throw new \DomainException(\sprintf('Ce dossier est déjà lié à %s.', $autre->getClient()->getNomComplet()));
        }
        // Une suggestion du même dossier pour un autre client n'a plus lieu d'être.
        foreach ($depot->findBy(['nomDossier' => $nomDossier, 'etat' => LienDossierClient::SUGGERE]) as $s) {
            if ($s->getClient()->getId() !== $client->getId()) {
                $this->em->remove($s);
            }
        }
        $lien = $depot->findOneBy(['client' => $client]);
        $score = null !== $lien && $lien->getNomDossier() === $nomDossier ? $lien->getScore() : 100;
        if (null === $lien) {
            $lien = new LienDossierClient($client, $nomDossier, LienDossierClient::LIE, $score);
            $this->em->persist($lien);
        }
        $lien->lier($nomDossier, $score, $par, new \DateTimeImmutable());
        $lien->setMarquePosee($this->poserMarque($racine.\DIRECTORY_SEPARATOR.$nomDossier, $client));
        $lien->setNombreFichiers($this->compter($racine.\DIRECTORY_SEPARATOR.$nomDossier));
        $this->journal->noter('liaison', $client, $par, ['dossier' => $nomDossier]);
        $this->em->flush();
        $this->analyserClient($client, true, new \DateTimeImmutable());

        return $lien;
    }

    /**
     * Crée le dossier d'un client (« C-0176 Garage Sanogo ») et les
     * sous-dossiers du modèle, puis le lie. Rien n'est écrasé : un dossier
     * du même nom déjà là est simplement lié.
     */
    public function creerDossier(Clients $client, ?User $par): LienDossierClient
    {
        $racine = $this->reglages->racine();
        if ('' === $racine || !is_dir($racine)) {
            throw new \DomainException('Le dossier racine des clients n\'est pas réglé ou pas accessible.');
        }
        $nom = self::nomDossierClient($client);
        $chemin = $racine.\DIRECTORY_SEPARATOR.$nom;
        if (!is_dir($chemin) && !@mkdir($chemin, 0775) && !is_dir($chemin)) {
            throw new \DomainException('Le dossier n\'a pas pu être créé (droits d\'écriture du serveur de l\'ERP sur le dossier des clients).');
        }
        foreach ($this->reglages->modele()->entrees() as $e) {
            $sous = $chemin.\DIRECTORY_SEPARATOR.$e['dossier'];
            if (!is_dir($sous)) {
                @mkdir($sous, 0775);
            }
        }
        $this->journal->noter('creation_dossier', $client, $par, ['dossier' => $nom]);

        return $this->lier($client, $nom, $par);
    }

    /** « C-0176 Garage Sanogo » : le code, puis le nom sans caractère interdit par Windows. */
    public static function nomDossierClient(Clients $client): string
    {
        $nom = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', ' ', $client->getNomComplet()));
        $nom = trim((string) preg_replace('/\s+/', ' ', $nom), ' .');

        return trim(($client->getCode() ?? '').' '.mb_substr($nom, 0, 80));
    }

    /**
     * Pose le petit fichier caché « .succes » (identifiant du client) : la
     * liaison suivra le dossier s'il est renommé. false si l'écriture n'est
     * pas permise (la liaison reste alors par le nom du dossier).
     */
    public function poserMarque(string $dossier, Clients $client): bool
    {
        $chemin = $dossier.\DIRECTORY_SEPARATOR.self::MARQUE;
        $m = self::lireMarque($dossier);
        if (null !== $m && $m['client'] === $client->getPublicId()->toRfc4122()) {
            return true;
        }
        $contenu = json_encode(['client' => $client->getPublicId()->toRfc4122(), 'code' => $client->getCode(), 'nom' => $client->getNomComplet(), 'pose_le' => date(\DATE_ATOM), 'note' => 'Fichier de l\'ERP Succès Imprim : il relie ce dossier à la fiche du client. Ne pas supprimer.'], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (false === @file_put_contents($chemin, $contenu)) {
            return false;
        }
        // Caché sous Windows (attribut H) quand le serveur de l'ERP est un PC Windows.
        if ('\\' === \DIRECTORY_SEPARATOR) {
            @exec('attrib +h '.escapeshellarg($chemin));
        }

        return true;
    }

    /** @return array{client: string, code: ?string}|null */
    public static function lireMarque(string $dossier): ?array
    {
        $chemin = $dossier.\DIRECTORY_SEPARATOR.self::MARQUE;
        if (!is_file($chemin) || filesize($chemin) > 4096) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($chemin), true);
        if (!\is_array($d) || !\is_string($d['client'] ?? null) || !preg_match('/^[0-9a-f-]{36}$/', $d['client'])) {
            return null;
        }

        return ['client' => $d['client'], 'code' => \is_string($d['code'] ?? null) ? $d['code'] : null];
    }

    /* ---------- Fichiers d'un client (DS01) ---------- */

    /**
     * Lit le dossier d'un client : chaque fichier est rangé dans son
     * sous-dossier ; seuls ceux qui ont changé sont relus (sauf analyse
     * complète). Renvoie le nombre de fichiers.
     */
    public function analyserClient(Clients $client, bool $complete, \DateTimeImmutable $maintenant): int
    {
        $dossier = $this->dossiers->dossierClient($client);
        if (null === $dossier) {
            return 0;
        }
        $modele = $this->reglages->modele();
        $apercus = (bool) $this->reglages->valeur('apercus');
        $textePdf = $this->reglages->rechercheTextePdf();
        $depot = $this->em->getRepository(FichierDossier::class);
        /** @var array<string, FichierDossier> $connus */
        $connus = [];
        foreach ($depot->findBy(['client' => $client]) as $f) {
            $connus[$f->getChemin()] = $f;
        }
        $vus = [];
        $n = 0;
        foreach (self::parcourir($dossier, self::PROFONDEUR, self::MAX_FICHIERS_CLIENT) as $relatif => $chemin) {
            $vus[$relatif] = true;
            ++$n;
            $f = $connus[$relatif] ?? null;
            if (null === $f) {
                $f = new FichierDossier($client, $relatif);
                $this->em->persist($f);
                $connus[$relatif] = $f;
            }
            $premier = str_contains($relatif, '/') ? explode('/', $relatif)[0] : null;
            $f->setSousDossier(null === $premier ? ModeleSousDossiers::AUTRES : ($modele->reconnaitre($premier) ?? ModeleSousDossiers::AUTRES));
            $change = $f->noterDisque((int) @filesize($chemin), (new \DateTimeImmutable())->setTimestamp((int) @filemtime($chemin)), $maintenant);
            if ($change || ($complete && null === $f->getResume())) {
                $this->decrire($f, $chemin);
            }
            if ($apercus && ($change || null === $this->miniatures->chemin($f->getMiniature()))) {
                if ($change || $complete || null === $f->getMiniature()) {
                    $f->setMiniature($this->miniatures->faire($chemin, $f->getSignature()));
                }
            }
            if ($textePdf && 'pdf' === $f->getExtension() && ($change || null === $f->getTexte())) {
                $f->setTexte(self::textePdf($chemin) ?? '');
            }
            if (0 === $n % 200) {
                $this->em->flush();
            }
        }
        foreach ($connus as $relatif => $f) {
            if (!isset($vus[$relatif])) {
                $f->setAbsent(true);
            }
        }
        $this->numeroterVersions(array_values(array_filter($connus, static fn (FichierDossier $f): bool => !$f->isAbsent())));
        $lien = $this->em->getRepository(LienDossierClient::class)->findOneBy(['client' => $client]);
        $lien?->setNombreFichiers($n);
        $this->em->flush();

        return $n;
    }

    /**
     * v1, v2, v3 par famille (nom sans « _v2 », « FINAL », « (1) ») : le
     * numéro écrit dans le nom s'il y en a, sinon l'ordre des dates.
     *
     * @param list<FichierDossier> $fichiers
     */
    public function numeroterVersions(array $fichiers): void
    {
        $familles = [];
        foreach ($fichiers as $f) {
            $v = NommageFichier::version($f->getNom());
            $familles[$f->getSousDossier() === 'bat' ? 'bat:'.$v['base'] : $v['base']][] = [$f, $v['version']];
        }
        foreach ($familles as $base => $membres) {
            // Numéros écrits dans tous les noms (« _v1 », « _v2 ») : on les garde ; sinon l'ordre des dates.
            $tousNumerotes = [] === array_filter($membres, static fn (array $m): bool => null === $m[1]);
            usort($membres, static fn (array $a, array $b): int => $tousNumerotes ? [$a[1], $a[0]->getModifieLe()] <=> [$b[1], $b[0]->getModifieLe()] : [$a[0]->getModifieLe(), $a[1] ?? 0] <=> [$b[0]->getModifieLe(), $b[1] ?? 0]);
            foreach ($membres as $i => [$f, $explicite]) {
                $f->setVersion((string) $base, $tousNumerotes ? (int) $explicite : $i + 1);
            }
        }
    }

    /**
     * « 850 × 2 000 mm · taille du produit inconnue » → « 85 × 200 cm » ;
     * le contrôle des fichiers liés ajoute une comparaison inutile ici.
     */
    public static function dimensionsCourtes(string $valeur): string
    {
        $v = trim((string) preg_replace('/\s*[·:]\s*taille du produit inconnue.*$/u', '', $valeur));
        if (preg_match('/^([\d\s\x{202F}\x{00A0},]+)\s*×\s*([\d\s\x{202F}\x{00A0},]+)\s*mm$/u', $v, $m)) {
            $nombre = static fn (string $n): float => (float) str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], $n);
            [$l, $h] = [$nombre($m[1]), $nombre($m[2])];
            if ($l >= 100 && $h >= 100) {
                $cm = static fn (float $x): string => rtrim(rtrim(number_format($x / 10, 1, ',', ' '), '0'), ',');

                return $cm($l).' × '.$cm($h).' cm';
            }
        }

        return $v;
    }

    private function decrire(FichierDossier $f, string $chemin): void
    {
        $lisibles = [...DossierClient::EXTENSIONS, 'gif', 'webp'];
        if (!\in_array($f->getExtension(), $lisibles, true)) {
            $f->decrire(null, NommageFichier::estSource($f->getNom()) ? 'fichier de travail' : null);

            return;
        }
        try {
            $controles = $this->controle->controler($chemin, null, null);
            $dimensions = $controles[0]['valeur'] ?? null;
            if (null !== $dimensions && str_contains($dimensions, 'à vérifier')) {
                $dimensions = null;
            }
            $f->decrire(null !== $dimensions ? self::dimensionsCourtes($dimensions) : null, $this->controle->resume($chemin));
        } catch (\Throwable) {
            $f->decrire(null, null);
        }
    }

    /**
     * Fichiers d'un dossier, chemin relatif (« / ») => chemin complet, sans
     * les fichiers cachés ni les fichiers temporaires des logiciels.
     *
     * @return \Generator<string, string>
     */
    public static function parcourir(string $dossier, int $profondeur, int $max): \Generator
    {
        $n = 0;
        $pile = [[$dossier, '', 0]];
        while ([] !== $pile) {
            [$chemin, $prefixe, $niveau] = array_pop($pile);
            $noms = @scandir($chemin) ?: [];
            natcasesort($noms);
            foreach ($noms as $nom) {
                if ('.' === $nom[0] || '~' === $nom[0] || str_starts_with($nom, '~$') || \in_array(strtolower($nom), self::IGNORES, true)) {
                    continue;
                }
                $complet = $chemin.\DIRECTORY_SEPARATOR.$nom;
                if (is_link($complet)) {
                    continue; // jamais suivre un raccourci qui mènerait hors du dossier
                }
                if (is_dir($complet)) {
                    if ($niveau + 1 < $profondeur) {
                        $pile[] = [$complet, $prefixe.$nom.'/', $niveau + 1];
                    }
                    continue;
                }
                if (!is_file($complet)) {
                    continue;
                }
                yield $prefixe.$nom => $complet;
                if (++$n >= $max) {
                    return;
                }
            }
        }
    }

    private function compter(string $dossier): int
    {
        $n = 0;
        foreach (self::parcourir($dossier, self::PROFONDEUR, self::MAX_COMPTE) as $_) {
            ++$n;
        }

        return $n;
    }

    /** Texte d'un PDF par pdftotext (20 premières pages), ou null. */
    public static function textePdf(string $chemin): ?string
    {
        try {
            $p = new Process(['pdftotext', '-l', '20', '-enc', 'UTF-8', $chemin, '-']);
            $p->setTimeout(30);
            $p->run();

            return $p->isSuccessful() ? mb_substr(trim((string) preg_replace('/\s+/', ' ', $p->getOutput())), 0, 60000) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /* ---------- Fichiers à classer (DS03) ---------- */

    /** Lit le dossier « À classer » ; renvoie le nombre de fichiers en attente. */
    public function analyserAClasser(\DateTimeImmutable $maintenant): int
    {
        $dossier = $this->reglages->dossierAClasser();
        if ('' === $dossier || !is_dir($dossier)) {
            return 0;
        }
        $depot = $this->em->getRepository(FichierAClasser::class);
        $connus = [];
        foreach ($depot->findAll() as $f) {
            $connus[$f->getChemin()] = $f;
        }
        $vus = [];
        $apercus = (bool) $this->reglages->valeur('apercus');
        foreach (self::parcourir($dossier, 3, 2000) as $relatif => $chemin) {
            $vus[$relatif] = true;
            $f = $connus[$relatif] ?? null;
            if (null === $f) {
                $f = new FichierAClasser($relatif, $maintenant);
                $this->em->persist($f);
                $connus[$relatif] = $f;
            }
            if (!$f->noterDisque((int) @filesize($chemin), (new \DateTimeImmutable())->setTimestamp((int) @filemtime($chemin)), $maintenant) || !$f->estAClasser()) {
                continue;
            }
            $origine = NommageFichier::origine($relatif);
            $f->setOrigine($origine['type'], $origine['libelle']);
            $this->proposer($f);
            if ($apercus) {
                $f->setMiniature($this->miniatures->faire($chemin, sha1($f->getOctets().'|'.$f->getModifieLe()->getTimestamp())));
            }
        }
        foreach ($connus as $relatif => $f) {
            if (!isset($vus[$relatif])) {
                $f->disparaitre();
            }
        }
        $this->em->flush();

        return \count(array_filter($connus, static fn (FichierAClasser $f): bool => $f->estAClasser()));
    }

    /**
     * Propose le client, la commande, le sous-dossier et le nom propre
     * d'un fichier à classer.
     */
    public function proposer(FichierAClasser $f): void
    {
        $texte = str_replace(['/', '\\'], ' ', $f->getChemin());
        $numeros = NommageFichier::numeros($texte);
        $client = null;
        $commande = null;
        $raison = null;
        $confiance = 0;
        if ([] !== $numeros) {
            $commande = $this->em->getRepository(Commandes::class)->findOneBy(['numero' => $numeros, 'deleted' => false]);
            if (null !== $commande && null !== $commande->getClients()) {
                $client = $commande->getClients();
                $confiance = 96;
                $raison = 'numéro de commande '.$commande->getNumero().' dans le nom';
            } else {
                $commande = null;
                $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $numeros]);
                if (null !== $client) {
                    $confiance = 94;
                    $raison = 'numéro de client '.$client->getCode().' dans le nom';
                } else {
                    $devis = $this->em->getRepository(Devis::class)->findOneBy(['numero' => $numeros]);
                    if (null !== $devis && null !== $devis->getClients()) {
                        $client = $devis->getClients();
                        $confiance = 90;
                        $raison = 'numéro de devis '.$devis->getNumero().' dans le nom';
                    }
                }
            }
        }
        if (null === $client) {
            [$client, $confiance, $raison] = $this->clientParNom($texte);
        }
        if (null !== $client && null === $commande) {
            $commande = $this->commandeEnCours($client);
            if (null !== $commande) {
                $raison .= ' · commande en cours la plus récente';
            }
        }
        $sd = NommageFichier::sousDossier($f->getNom(), $f->getOrigineType());
        if (null === $client) {
            $f->proposer(null, null, $sd['cle'], 0, null, null);

            return;
        }
        $f->proposer($client, $commande, $sd['cle'], (int) round(0.75 * $confiance + 0.25 * $sd['surete']), $this->nomPropose($f, $client, $commande, $sd['cle']), $raison);
    }

    public function nomPropose(FichierAClasser $f, Clients $client, ?Commandes $commande, string $sousDossier): string
    {
        $designation = null;
        if (null !== $commande) {
            foreach ($commande->getCommandesDetails() as $ligne) {
                $designation = $ligne->getDesignation() ?: $ligne->getProduit()?->getNom();
                if ($designation) {
                    break;
                }
            }
        }
        $date = $f->getModifieLe();
        if (preg_match('/(20\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])/', $f->getNom(), $m)) {
            $date = new \DateTimeImmutable($m[1].'-'.$m[2].'-'.$m[3]);
        }

        return NommageFichier::nomAutomatique((string) ($commande?->getNumero() ?? $client->getCode()), NommageFichier::description($f->getNom(), $sousDossier, $designation, $f->getOrigineType()), $date, $f->getExtension());
    }

    /**
     * Le client dont le nom est dans le nom du fichier : tous ses mots, ou
     * un mot rare qu'aucun autre client ne porte (« clinique »).
     *
     * @return array{0: ?Clients, 1: int, 2: ?string}
     */
    private function clientParNom(string $texte): array
    {
        $meilleur = [null, 0, null];
        $motsTexte = Rapprochement::mots($texte);
        $parMot = [];
        foreach ($this->clients() as $c) {
            foreach ($c['noms'] as $nom) {
                $mots = Rapprochement::mots($nom);
                if ([] === $mots) {
                    continue;
                }
                $couverture = Rapprochement::couverture($nom, $texte);
                if ($couverture >= 1.0 && (\count($mots) >= 2 || \strlen($mots[0]) >= 6)) {
                    $conf = \count($mots) >= 2 ? 85 : 72;
                    if ($conf > $meilleur[1]) {
                        $meilleur = [$c['client'], $conf, 'nom du client dans le nom du fichier'];
                    }
                }
                foreach ($mots as $m) {
                    if (\strlen($m) >= 5) {
                        $parMot[$m][(int) $c['client']->getId()] = $c['client'];
                    }
                }
            }
        }
        if (null !== $meilleur[0]) {
            return $meilleur;
        }
        foreach ($motsTexte as $m) {
            if (1 === \count($parMot[$m] ?? [])) {
                return [array_values($parMot[$m])[0], 62, '« '.$m.' » : un seul client porte ce mot'];
            }
        }

        return [null, 0, null];
    }

    private function commandeEnCours(Clients $client): ?Commandes
    {
        $commandes = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.clients = :client AND c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('client', $client)->setParameter('depuis', new \DateTimeImmutable('-90 days'))
            ->orderBy('c.dateCommande', 'DESC')->setMaxResults(5)
            ->getQuery()->getResult();
        foreach ($commandes as $c) {
            if (!$c->isRecupereParClient()) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Clients en mémoire pour une analyse : code et noms (nom affiché,
     * raison sociale, prénom et nom).
     *
     * @return array<int, array{client: Clients, noms: list<string>, code: string}>
     */
    private function clients(): array
    {
        if (null !== $this->clientsEnMemoire) {
            return $this->clientsEnMemoire;
        }
        $this->clientsEnMemoire = [];
        foreach ($this->em->getRepository(Clients::class)->findAll() as $c) {
            $noms = array_values(array_unique(array_filter([$c->getNomComplet(), (string) $c->getRaisonSociale(), trim($c->getPrenom().' '.$c->getNom())], static fn (string $n): bool => '' !== trim($n))));
            $this->clientsEnMemoire[(int) $c->getId()] = ['client' => $c, 'noms' => $noms, 'code' => (string) $c->getCode()];
        }

        return $this->clientsEnMemoire;
    }

    /* ---------- Mes actions : non classé depuis 24 h ---------- */

    /**
     * Un fichier non classé depuis 24 h crée une ligne « Fichier à classer »
     * dans « Mes actions » de la caisse (une seule par fichier).
     */
    public function actionsEnRetard(\DateTimeImmutable $maintenant): int
    {
        $anciens = $this->em->createQueryBuilder()->select('f')->from(FichierAClasser::class, 'f')
            ->where('f.etat = :a AND f.apparuLe <= :limite')
            ->setParameter('a', FichierAClasser::A_CLASSER)->setParameter('limite', $maintenant->modify('-24 hours'))
            ->getQuery()->getResult();
        if ([] === $anciens) {
            return 0;
        }
        $causes = array_map(static fn (FichierAClasser $f): string => 'classer:'.$f->getId(), $anciens);
        $etat = $this->em->getRepository(ActionCaisse::class)->etatDesCauses($causes);
        $crees = 0;
        foreach ($anciens as $f) {
            if (isset($etat['classer:'.$f->getId()])) {
                continue;
            }
            $this->actions->nouvelle('classer', 'classer:'.$f->getId(), $f->getClient()?->getNomComplet() ?? 'Fichier à classer', $maintenant, $maintenant)
                ->lier($f->getClient(), null, 'fichier_a_classer', $f->getId())
                ->setDetail(mb_substr('« '.$f->getNom().' » déposé le '.$f->getApparuLe()->format('d/m à H:i').', pas encore classé', 0, 255));
            ++$crees;
        }
        $this->em->flush();

        return $crees;
    }

    /* ---------- État ---------- */

    /** @return array<string, array<string, mixed>> */
    public function etat(): array
    {
        $fichier = $this->reglages->dossierTravail().'/etat.json';
        $d = is_file($fichier) ? json_decode((string) file_get_contents($fichier), true) : null;

        return \is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $bilan */
    private function noterEtat(string $quoi, \DateTimeImmutable $le, array $bilan): void
    {
        $dossier = $this->reglages->dossierTravail();
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return;
        }
        $etat = $this->etat();
        $etat[$quoi] = ['le' => $le->format(\DATE_ATOM)] + $bilan;
        @file_put_contents($dossier.'/etat.json', json_encode($etat, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
    }
}
