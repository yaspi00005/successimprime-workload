<?php

namespace App\Service\DossiersClients;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Conception;
use App\Entity\FichierDossier;
use App\Entity\LienDossierClient;
use App\Entity\ProduitClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que montre l'onglet « Fichiers » de la fiche client (maquette DS01),
 * lu dans la base (l'analyse a déjà lu le disque) et filtré par les droits
 * de la personne : sous-dossiers qu'elle voit, fichiers en miniatures,
 * recherche, détail d'un fichier avec ses versions et les BAT de sa
 * commande.
 *
 * En ligne : seulement les fichiers publiés pour le client.
 */
final class ExplorateurDossier
{
    public const PAR_PAGE = 120;
    public const TRIS = ['recents' => 'récents', 'nom' => 'nom', 'taille' => 'taille'];
    public const FILTRES = ['commande' => 'Liés à une commande', 'produit' => 'Liés à un produit', 'semaine' => 'Modifiés cette semaine', 'sources' => 'Fichiers sources (AI, PSD)'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDossiers $reglages,
        private readonly PublicationFichiers $publications,
        private readonly AnalyseDossiers $analyse,
    ) {
    }

    /**
     * @param array{sd?: string, q?: string, filtre?: string, tri?: string, f?: int} $choix
     *
     * @return array<string, mixed>
     */
    public function pourClient(Clients $client, ?User $u, array $choix = []): array
    {
        if ($this->reglages->enLigne()) {
            return ['en_ligne' => true, 'publies' => $this->publications->pourClient($client)];
        }
        $modele = $this->reglages->modele();
        $droits = $this->reglages->droitsDe($u);
        $voirTout = true === $droits['voir'];
        $comptes = [];
        foreach ($this->em->createQueryBuilder()->select('f.sousDossier AS sd, COUNT(f.id) AS n, SUM(f.octets) AS o')->from(FichierDossier::class, 'f')
            ->where('f.client = :c AND f.absent = false')->setParameter('c', $client)->groupBy('f.sousDossier')->getQuery()->getArrayResult() as $l) {
            $comptes[$l['sd']] = ['n' => (int) $l['n'], 'o' => (int) $l['o']];
        }
        $sousDossiers = [];
        foreach ($modele->entrees() as $e) {
            if ($this->reglages->peut($u, 'voir', $e['cle'])) {
                $sousDossiers[$e['cle']] = $e + ['fichiers' => $comptes[$e['cle']]['n'] ?? 0];
            }
        }
        if ($voirTout && isset($comptes[ModeleSousDossiers::AUTRES])) {
            $sousDossiers[ModeleSousDossiers::AUTRES] = ['cle' => ModeleSousDossiers::AUTRES, 'nom' => 'Autres dossiers', 'couleur' => '#94A3B8', 'numero' => '', 'dossier' => 'Autres dossiers', 'fichiers' => $comptes[ModeleSousDossiers::AUTRES]['n']];
        }
        $lien = $this->em->getRepository(LienDossierClient::class)->findOneBy(['client' => $client]);
        $base = [
            'en_ligne' => false,
            'lien' => $lien,
            'racine_reglee' => '' !== $this->reglages->racine(),
            'sous_dossiers' => $sousDossiers,
            'aucun_droit' => [] === $sousDossiers,
            'publies' => $this->publications->pourClient($client),
            'peut_lier_dossier' => null !== $u && (\in_array('ROLE_ADMIN', $u->getRoles(), true) || \in_array('ROLE_PREPRESSE', $u->getRoles(), true)),
            'etat' => $this->analyse->etat(),
            'texte_pdf' => $this->reglages->rechercheTextePdf(),
        ];
        if ([] === $sousDossiers || null === $lien || !$lien->estLie()) {
            return $base + ['fichiers' => [], 'detail' => null, 'sd' => null, 'q' => '', 'filtre' => '', 'tri' => 'recents', 'total' => 0, 'octets' => 0, 'chemin' => null];
        }
        $q = trim((string) ($choix['q'] ?? ''));
        $filtre = \array_key_exists((string) ($choix['filtre'] ?? ''), self::FILTRES) ? (string) $choix['filtre'] : '';
        $tri = \array_key_exists((string) ($choix['tri'] ?? ''), self::TRIS) ? (string) $choix['tri'] : 'recents';
        $sd = (string) ($choix['sd'] ?? '');
        if (!isset($sousDossiers[$sd])) {
            $sd = (string) array_key_first(array_filter($sousDossiers, static fn (array $s): bool => $s['fichiers'] > 0) ?: $sousDossiers);
        }
        $visibles = array_keys($sousDossiers);
        $qb = $this->em->createQueryBuilder()->select('f', 'c')->from(FichierDossier::class, 'f')->leftJoin('f.commande', 'c')
            ->where('f.client = :client AND f.absent = false')->setParameter('client', $client)
            ->andWhere('f.sousDossier IN (:sds)')->setParameter('sds', '' !== $q ? $visibles : [$sd]);
        if ('' !== $q) {
            $qb->andWhere('f.nom LIKE :q OR f.chemin LIKE :q OR c.numero LIKE :q OR f.texte LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }
        match ($filtre) {
            'commande' => $qb->andWhere('f.commande IS NOT NULL'),
            'semaine' => $qb->andWhere('f.modifieLe >= :semaine')->setParameter('semaine', new \DateTimeImmutable('-7 days')),
            'sources' => $qb->andWhere('f.extension IN (:src)')->setParameter('src', NommageFichier::SOURCES),
            default => null,
        };
        match ($tri) {
            'nom' => $qb->orderBy('f.nom', 'ASC'),
            'taille' => $qb->orderBy('f.octets', 'DESC'),
            default => $qb->orderBy('f.modifieLe', 'DESC'),
        };
        /** @var list<FichierDossier> $fichiers */
        $fichiers = $qb->setMaxResults(self::PAR_PAGE * 2)->getQuery()->getResult();
        $produits = $this->produitsParFichier($client);
        if ('produit' === $filtre) {
            $fichiers = array_values(array_filter($fichiers, static fn (FichierDossier $f): bool => isset($produits[$f->getNom()])));
        }
        $fichiers = \array_slice($fichiers, 0, self::PAR_PAGE);
        $detail = null;
        $choisi = (int) ($choix['f'] ?? 0);
        if ($choisi > 0) {
            $f = $this->em->find(FichierDossier::class, $choisi);
            if (null !== $f && $f->getClient()->getId() === $client->getId() && !$f->isAbsent() && $this->reglages->peut($u, 'voir', $f->getSousDossier())) {
                $detail = $this->detail($f, $u);
            }
        }

        return [
            'fichiers' => $fichiers,
            'produits' => $produits,
            'detail' => $detail,
            'sd' => $sd,
            'q' => $q,
            'filtre' => $filtre,
            'tri' => $tri,
            'total' => array_sum(array_map(static fn (array $s): int => $s['fichiers'], $sousDossiers)),
            'octets' => array_sum(array_map(static fn (string $c): int => $comptes[$c]['o'] ?? 0, $visibles)),
            'chemin' => $this->cheminPoste($lien, '' === $sd ? null : $this->dossierReel($client, $sd)),
            'peut_ajouter' => '' !== $sd && $this->reglages->peut($u, 'ajouter', $sd) && ModeleSousDossiers::AUTRES !== $sd,
        ] + $base;
    }

    /**
     * Détail d'un fichier (volet de droite de DS01).
     *
     * @return array<string, mixed>
     */
    public function detail(FichierDossier $f, ?User $u): array
    {
        $versions = $this->em->getRepository(FichierDossier::class)->findBy(['client' => $f->getClient(), 'baseVersion' => $f->getBaseVersion(), 'absent' => false], ['version' => 'DESC', 'modifieLe' => 'DESC'], 12);
        $commande = $f->getCommande();
        foreach ($versions as $v) {
            $commande ??= $v->getCommande();
        }
        $bats = null !== $commande ? $this->batsDe($commande) : [];
        $lignes = [];
        $ordre = array_reverse($versions);
        foreach ($ordre as $i => $v) {
            // Rapprochement avec les BAT de la commande : le n-ième BAT après la n-ième version, quand les dates concordent.
            $bat = null;
            foreach ($bats as $b) {
                if ($b['le'] >= $v->getModifieLe()->modify('-1 day') && (!isset($ordre[$i + 1]) || $b['le'] < $ordre[$i + 1]->getModifieLe()->modify('+1 day'))) {
                    $bat = $b;
                }
            }
            $lignes[] = ['fichier' => $v, 'bat' => $bat];
        }
        $lien = $this->em->getRepository(LienDossierClient::class)->findOneBy(['client' => $f->getClient()]);
        $commandes = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.clients = :client AND c.deleted = false AND c.soldeOuverture = false')->setParameter('client', $f->getClient())
            ->orderBy('c.dateCommande', 'DESC')->setMaxResults(40)->getQuery()->getResult();
        $sd = $f->getSousDossier();

        return [
            'fichier' => $f,
            'versions' => array_reverse($lignes),
            'commande' => $commande,
            'produit' => $this->produitsParFichier($f->getClient())[$f->getNom()] ?? null,
            'chemin' => null !== $lien ? $this->cheminPoste($lien, $f->getChemin()) : null,
            'commandes' => $commandes,
            'publications' => array_values(array_filter($this->publications->pourClient($f->getClient()), static fn ($p): bool => $p->getFichierDossier()?->getId() === $f->getId())),
            'categorie' => PublicationFichiers::categorieProposee($f),
            'peut_publier' => $this->reglages->peut($u, 'ajouter', $sd),
            'peut_lier' => self::peutLier($this->reglages, $u, $sd),
            'peut_supprimer' => $this->reglages->peut($u, 'supprimer', $sd),
        ];
    }

    /** Lier à une commande : qui ajoute dans ce sous-dossier, ou qui voit le fichier et a le droit Commandes. */
    public static function peutLier(ReglagesDossiers $reglages, ?User $u, string $sd): bool
    {
        return $reglages->peut($u, 'ajouter', $sd) || ($reglages->peut($u, 'voir', $sd) && null !== $u && \in_array('ROLE_COMMANDE', $u->getRoles(), true));
    }

    /** Nom du sous-dossier sur le disque (« 03 BAT envoyés »), lu dans la base, sinon celui du modèle. */
    public function dossierReel(Clients $client, string $sd): ?string
    {
        if (ModeleSousDossiers::AUTRES === $sd) {
            return null;
        }
        $chemin = $this->em->createQueryBuilder()->select('f.chemin')->from(FichierDossier::class, 'f')
            ->where('f.client = :c AND f.sousDossier = :sd AND f.absent = false')->setParameter('c', $client)->setParameter('sd', $sd)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (\is_array($chemin) && str_contains((string) $chemin['chemin'], '/')) {
            return explode('/', (string) $chemin['chemin'])[0];
        }

        return $this->reglages->modele()->entree($sd)['dossier'] ?? null;
    }

    /** Chemin vu des postes (« \\SERVEUR\Clients\C-0087 Hôtel Niger\01 Conceptions »), sans lire le disque. */
    public function cheminPoste(LienDossierClient $lien, ?string $relatif): ?string
    {
        $racine = $this->reglages->racine();
        if ('' === $racine) {
            return null;
        }
        $serveur = rtrim($racine, '/\\').\DIRECTORY_SEPARATOR.$lien->getNomDossier().(null !== $relatif ? '/'.$relatif : '');

        return LienProtocole::cheminPoste($serveur, $racine, $this->reglages->racinePostes());
    }

    /**
     * Événements de BAT de la commande (envoyé, corrections, validé), du plus ancien au plus récent.
     *
     * @return list<array{le: \DateTimeImmutable, titre: string, etat: string}>
     */
    private function batsDe(Commandes $commande): array
    {
        $conceptions = $this->em->createQueryBuilder()->select('k', 'ctl', 'det')->from(Conception::class, 'k')
            ->join('k.controle', 'ctl')->join('ctl.commandeDetail', 'det')
            ->where('det.commande = :c')->setParameter('c', $commande)
            ->getQuery()->getResult();
        $evenements = [];
        foreach ($conceptions as $k) {
            foreach ($k->getEvenements() as $e) {
                if (\in_array($e['type'] ?? null, ['bat', 'retour', 'valide'], true) && ($e['le'] ?? null) instanceof \DateTimeInterface) {
                    $evenements[] = $e;
                }
            }
        }
        usort($evenements, static fn (array $a, array $b): int => $a['le'] <=> $b['le']);
        $bats = [];
        foreach ($evenements as $e) {
            if ('bat' === $e['type']) {
                $bats[] = ['le' => \DateTimeImmutable::createFromInterface($e['le']), 'titre' => (string) ($e['titre'] ?? 'BAT envoyé'), 'etat' => 'envoye'];
            } elseif ([] !== $bats) {
                $bats[\count($bats) - 1]['etat'] = 'valide' === $e['type'] ? 'valide' : 'refuse';
            }
        }

        return $bats;
    }

    /**
     * Produits du client (« Mes produits ») dont le fichier lié porte ce nom.
     *
     * @return array<string, string> nom du fichier => nom du produit
     */
    private function produitsParFichier(Clients $client): array
    {
        $liste = [];
        foreach ($this->em->getRepository(ProduitClient::class)->findBy(['client' => $client]) as $p) {
            foreach ($p->getFichiersLies() as $l) {
                if (\is_string($l['nom'] ?? null)) {
                    $liste[basename(str_replace('\\', '/', $l['nom']))] = (string) $p->getNom();
                }
            }
        }

        return $liste;
    }
}
