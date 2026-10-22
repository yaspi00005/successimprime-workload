<?php

namespace App\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\CompteTresorerie;
use App\Entity\Paiements;
use App\Entity\PointPointage;
use App\Entity\User;
use App\Service\PaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tournées et remise (docs/documents-livraisons.md, L02 et L03) : la
 * tournée du jour d'un livreur, les étapes, la remise signée avec photos,
 * et l'encaissement du livreur, provisoire jusqu'à la validation à la
 * caisse. Photos et signatures sont rangées hors du dossier public.
 */
final class Tournee
{
    private const TYPES_PHOTO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_PHOTO = 8 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaiementService $paiements,
        #[Autowire('%kernel.project_dir%/var/uploads/livraisons')]
        private readonly string $dossier,
    ) {
    }

    /**
     * Livraisons du jour, par livreur (null = toutes), dans l'ordre de tournée.
     *
     * @return list<BonLivraison>
     */
    public function duJour(\DateTimeImmutable $jour, ?User $livreur = null): array
    {
        $qb = $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
            ->addSelect('c', 'cli')->join('b.commande', 'c')->leftJoin('c.clients', 'cli')
            ->where('b.prevuLe = :jour')->andWhere('b.statut IN (:statuts)')
            ->setParameter('jour', $jour->setTime(0, 0), 'date_immutable')
            ->setParameter('statuts', [BonLivraison::STATUT_VALIDE, BonLivraison::STATUT_LIVRE])
            ->orderBy('b.ordreTournee', 'ASC')->addOrderBy('b.creneau', 'ASC')->addOrderBy('b.id', 'ASC');
        if ($livreur !== null) {
            $qb->andWhere('b.livreur = :l')->setParameter('l', $livreur);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Planning du jour (L01) : tournées par livreur et chiffres.
     *
     * @return array{tournees: list<array{livreur: ?User, bons: list<BonLivraison>, livrees: int}>, total: int, livrees: int, aLHeure: int, aEncaisser: int, encaisse: int, partielles: int, echecs: int, reportees: int}
     */
    public function planning(\DateTimeImmutable $jour): array
    {
        $bons = $this->duJour($jour);
        $tournees = [];
        $c = ['livrees' => 0, 'aLHeure' => 0, 'aEncaisser' => 0, 'encaisse' => 0, 'partielles' => 0, 'echecs' => 0];
        foreach ($bons as $b) {
            $cle = $b->getLivreur()?->getId() ?? 0;
            $tournees[$cle] ??= ['livreur' => $b->getLivreur(), 'bons' => [], 'livrees' => 0];
            $tournees[$cle]['bons'][] = $b;
            if ($b->getStatut() === BonLivraison::STATUT_LIVRE) {
                ++$tournees[$cle]['livrees'];
                ++$c['livrees'];
                $c['aLHeure'] += self::aLHeure($b) ? 1 : 0;
                $c['partielles'] += $b->estPartielle() ? 1 : 0;
                $c['encaisse'] += (int) $b->getEncaisseMontant();
                $c['aEncaisser'] += (int) $b->getEncaisseMontant();
            } else {
                $c['aEncaisser'] += (int) $b->getCommande()?->getResteAPayer();
                $c['echecs'] += $b->etatSuivi() === BonLivraison::ETAT_ECHEC ? 1 : 0;
            }
        }
        $reportees = (int) $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')->select('COUNT(b.id)')
            ->where('b.statut = :v')->andWhere('b.prevuLe < :jour')
            ->setParameter('v', BonLivraison::STATUT_VALIDE)->setParameter('jour', $jour->setTime(0, 0), 'date_immutable')
            ->getQuery()->getSingleScalarResult();

        return ['tournees' => array_values($tournees), 'total' => \count($bons), 'reportees' => $reportees] + $c;
    }

    /** Livrée avant la fin du créneau (sans créneau : livrée le jour prévu). */
    public static function aLHeure(BonLivraison $b): bool
    {
        $livre = $b->getLivreLe();
        if ($livre === null) {
            return false;
        }
        if (preg_match('/(\d{1,2})[:h](\d{2})\s*$/', (string) $b->getCreneau(), $m)) {
            return $livre->format('H:i') <= \sprintf('%02d:%s', $m[1], $m[2]);
        }

        return $b->getPrevuLe() === null || $livre->format('Y-m-d') <= $b->getPrevuLe()->format('Y-m-d');
    }

    /** Point de départ des tournées : le premier lieu de pointage placé sur la carte. @return array{0: float, 1: float}|null */
    public function depot(): ?array
    {
        foreach ($this->em->getRepository(PointPointage::class)->findBy(['actif' => true], ['id' => 'ASC']) as $p) {
            if ($p->getLatitude() !== null && $p->getLongitude() !== null) {
                return [$p->getLatitude(), $p->getLongitude()];
            }
        }

        return null;
    }

    /**
     * Réordonne la tournée restante d'un livreur : au plus proche, en
     * partant du dépôt (ou du dernier arrêt livré). Les arrêts sans
     * position passent à la fin.
     */
    public function reordonner(\DateTimeImmutable $jour, User $livreur): int
    {
        $bons = $this->duJour($jour, $livreur);
        $faits = array_values(array_filter($bons, static fn (BonLivraison $b): bool => $b->getStatut() === BonLivraison::STATUT_LIVRE));
        $reste = array_values(array_filter($bons, static fn (BonLivraison $b): bool => $b->getStatut() !== BonLivraison::STATUT_LIVRE));
        $depart = $this->depot();
        foreach ($faits as $b) {
            $depart = self::position($b) ?? $depart;
        }
        $points = [];
        foreach ($reste as $i => $b) {
            $points[$i] = self::position($b);
        }
        $ordre = [...$faits, ...array_map(static fn (int $i): BonLivraison => $reste[$i], self::auPlusProche($points, $depart))];
        foreach ($ordre as $rang => $b) {
            $b->setOrdreTournee($rang + 1);
        }
        $this->em->flush();

        return \count($reste);
    }

    /**
     * Ordre « au plus proche voisin ». Clés sans position à la fin.
     *
     * @param array<int, array{0: float, 1: float}|null> $points
     * @param array{0: float, 1: float}|null              $depart
     *
     * @return list<int>
     */
    public static function auPlusProche(array $points, ?array $depart): array
    {
        $places = array_filter($points);
        $sans = array_keys(array_diff_key($points, $places));
        $ordre = [];
        $ici = $depart ?? ($places !== [] ? reset($places) : null);
        while ($places !== []) {
            $meilleur = null;
            foreach ($places as $k => $p) {
                $d = ($p[0] - $ici[0]) ** 2 + (($p[1] - $ici[1]) * cos(deg2rad($ici[0]))) ** 2;
                if ($meilleur === null || $d < $meilleur[1]) {
                    $meilleur = [$k, $d];
                }
            }
            $ordre[] = $meilleur[0];
            $ici = $places[$meilleur[0]];
            unset($places[$meilleur[0]]);
        }

        return [...$ordre, ...$sans];
    }

    /** @return array{0: float, 1: float}|null */
    public static function position(BonLivraison $b): ?array
    {
        $c = $b->getCommande()?->getClients();

        return $c !== null && $c->estLocalise() ? [(float) $c->getLatitude(), (float) $c->getLongitude()] : null;
    }

    /** Personnes qui livrent : comptes actifs avec le rôle livreur. @return list<User> */
    public function livreurs(): array
    {
        $tous = $this->em->getRepository(User::class)->findBy(['actif' => true]);

        return array_values(array_filter($tous, static fn (User $u): bool => \in_array(User::ROLE_LIVREUR, $u->getRoles(), true)));
    }

    /** Quantité qu'un article peut encore remettre : le reste à livrer de la commande. */
    public static function maxRemise(BonLivraisonLigne $ligne): int
    {
        $detail = $ligne->getCommandeDetail();

        return $detail !== null ? min((int) $ligne->getQuantiteCommandee(), $detail->getQuantiteRestanteLivraison()) : (int) $ligne->getQuantiteCommandee();
    }

    /**
     * Remise au client : quantités livrées, photos, signature, encaissement.
     *
     * @param array<int, int|string>   $quantites id de ligne => quantité remise
     * @param list<UploadedFile>       $photos
     * @param ?float                   $latitude position du téléphone à la remise (preuve GPS)
     */
    public function remettre(BonLivraison $bon, User $livreur, array $quantites, array $photos, ?string $signaturePng, ?string $signataire, ?string $mode, ?int $montant, \DateTimeImmutable $maintenant, ?float $latitude = null, ?float $longitude = null): ?string
    {
        if ($bon->getStatut() !== BonLivraison::STATUT_VALIDE) {
            return 'Cette livraison n\'est pas en cours.';
        }
        // Le code de réception donné par le client prouve la remise (maquettes DB10, DB11) ; sans code, sa signature.
        $avecCode = null !== $bon->getCodeVerifieLe();
        if (!$avecCode && ($signaturePng === null || $signaturePng === '')) {
            return 'Tapez le code de réception du client, ou faites-le signer.';
        }
        // Qui a reçu le colis : le bureau doit pouvoir le dire (suivi des livraisons).
        if ($signataire === null || trim($signataire) === '') {
            return 'Indiquez le nom de la personne qui reçoit le colis.';
        }
        $photos = array_values(array_filter($photos, fn ($p): bool => $p instanceof UploadedFile && $p->isValid() && isset(self::TYPES_PHOTO[(string) $p->getMimeType()]) && $p->getSize() <= self::MAX_PHOTO));
        if ([] === $photos) {
            return 'Prenez la photo du colis remis : elle sert de preuve au client.';
        }
        $remises = [];
        foreach ($bon->getLignes() as $ligne) {
            $remises[] = [$ligne, max(0, min((int) ($quantites[$ligne->getId()] ?? 0), self::maxRemise($ligne)))];
        }
        if (array_sum(array_column($remises, 1)) === 0) {
            return 'Cochez au moins un article remis.';
        }
        if ($mode !== null && $mode !== '') {
            $reste = (int) $bon->getCommande()?->getResteAPayer();
            if ($mode !== 'credit' && ($montant === null || $montant <= 0 || $montant > $reste)) {
                return \sprintf('Montant encaissé invalide : au plus %s F restent à payer.', number_format($reste, 0, ',', ' '));
            }
            $bon->noterEncaissement($montant, $mode, $maintenant);
        }
        if ($signaturePng !== null && $signaturePng !== '') {
            $signature = $this->rangerSignature($signaturePng, $maintenant);
            if ($signature === null) {
                return 'La signature n\'a pas pu être lue : recommencez.';
            }
            $bon->signer($signature, $signataire);
        } else {
            $bon->signer('', $signataire);
        }
        foreach ($photos as $k => $photo) {
            $nom = $this->rangerPhoto($photo, $maintenant);
            if ($nom !== null) {
                $bon->ajouterPhoto($nom);
                // La première photo, réduite, part en ligne pour le client (maquette DB11).
                if (0 === $k) {
                    $bon->setPhotoClient(self::reduire($this->dossier.'/'.$nom), $maintenant);
                }
            }
        }
        foreach ($remises as [$ligne, $q]) {
            // Article non remis : il sort du bon et reste à livrer sur la commande.
            $q > 0 ? $ligne->setQuantiteLivree($q) : $bon->removeLigne($ligne);
        }
        $bon->noterPosition($latitude, $longitude);
        $bon->avancer('arrive', $maintenant);
        $bon->marquerLivre($livreur);
        $bon->oublierLivreurEnRoute();
        $this->em->flush();

        return null;
    }

    /** Ce que le client voit du livreur pendant la tournée : prénom et téléphone (maquette DB10). */
    public static function suiviLivreur(BonLivraison $bon, User $livreur): void
    {
        $employe = $livreur->getEmploye();
        $bon->noterLivreurSuivi($employe?->getPrenom() ?: $livreur->getUsername(), $employe?->getTelephone());
    }

    /**
     * Position du livreur, notée sur ses livraisons en route du jour (le
     * client la suit, maquette DB10). Renvoie le nombre de livraisons mises à jour.
     */
    public function noterPosition(User $livreur, float $latitude, float $longitude, \DateTimeImmutable $maintenant): int
    {
        $n = 0;
        foreach ($this->duJour($maintenant, $livreur) as $bon) {
            if ($bon->getStatut() === BonLivraison::STATUT_VALIDE && null !== $bon->getEnRouteLe() && !$bon->isEchecEnCours()) {
                $bon->noterPositionLivreur($latitude, $longitude, $maintenant);
                ++$n;
            }
        }
        $this->em->flush();

        return $n;
    }

    /** Photo réduite (800 px au plus, JPEG) en base64, ou null si elle ne se lit pas. */
    public static function reduire(string $chemin, int $cote = 800): ?string
    {
        $octets = is_file($chemin) ? file_get_contents($chemin) : false;
        $image = false === $octets ? false : @imagecreatefromstring($octets);
        if (false === $image) {
            return null;
        }
        $l = imagesx($image);
        $h = imagesy($image);
        $r = min(1, $cote / max($l, $h));
        $petite = imagescale($image, max(1, (int) round($l * $r)), max(1, (int) round($h * $r)));
        if (false === $petite) {
            return null;
        }
        ob_start();
        imagejpeg($petite, null, 72);

        return base64_encode((string) ob_get_clean());
    }

    /**
     * Validation à la caisse : l'argent ou la preuve Orange Money est
     * remis, le paiement est créé et validé (il entre en trésorerie).
     */
    public function validerEncaissement(BonLivraison $bon, CompteTresorerie $compte, User $caissier): ?string
    {
        if (!$bon->encaissementProvisoire()) {
            return 'Aucun encaissement à valider sur cette livraison.';
        }
        $commande = $bon->getCommande();
        $montant = (int) $bon->getEncaisseMontant();
        if ($commande === null || $montant > $commande->getResteAPayer()) {
            return 'Le montant dépasse le reste à payer de la commande : vérifiez avant de valider.';
        }
        $paiement = (new Paiements())
            ->setCommande($commande)
            ->setEncaissePar($bon->getLivreur() ?? $bon->getLivrePar() ?? $caissier)
            ->setDate($bon->getEncaisseLe() ?? new \DateTimeImmutable())
            ->setMontant($montant)
            ->setMode($bon->getEncaisseMode() === 'orange_money' ? Paiements::MODE_ORANGE_MONEY : Paiements::MODE_ESPECES)
            ->setCompteTresorerie($compte)
            ->setReference('Livraison '.$bon->getNumero())
            ->setObservation('Encaissé à la livraison, validé à la caisse.');
        try {
            $this->paiements->valider($paiement, $caissier);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $bon->setPaiementLivraison($paiement);
        $this->em->flush();

        return null;
    }

    /** Chemin d'une preuve (photo ou signature) de ce bon, ou null. */
    public function cheminPreuve(BonLivraison $bon, string $fichier): ?string
    {
        if (!\in_array($fichier, [...$bon->getPhotos(), $bon->getSignature()], true) || !preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp)$#', $fichier)) {
            return null;
        }
        $chemin = $this->dossier.'/'.$fichier;

        return is_file($chemin) ? $chemin : null;
    }

    private function rangerPhoto(UploadedFile $photo, \DateTimeImmutable $maintenant): ?string
    {
        $type = $photo->isValid() ? $photo->getMimeType() : null;
        if ($type === null || !isset(self::TYPES_PHOTO[$type]) || $photo->getSize() > self::MAX_PHOTO) {
            return null;
        }
        $nom = $maintenant->format('Y/m').'/'.bin2hex(random_bytes(16)).'.'.self::TYPES_PHOTO[$type];
        $photo->move(\dirname($this->dossier.'/'.$nom), basename($nom));

        return $nom;
    }

    private function rangerSignature(string $dataUrl, \DateTimeImmutable $maintenant): ?string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return null;
        }
        $octets = base64_decode($m[1], true);
        if ($octets === false || \strlen($octets) > 500_000 || !str_starts_with($octets, "\x89PNG")) {
            return null;
        }
        $nom = $maintenant->format('Y/m').'/'.bin2hex(random_bytes(16)).'.png';
        $chemin = $this->dossier.'/'.$nom;
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o775, true);
        }
        file_put_contents($chemin, $octets);

        return $nom;
    }
}
