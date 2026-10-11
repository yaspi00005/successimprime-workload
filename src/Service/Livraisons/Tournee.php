<?php

namespace App\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Entity\CompteTresorerie;
use App\Entity\Paiements;
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

    /** Personnes qui livrent : comptes actifs avec le rôle livreur. @return list<User> */
    public function livreurs(): array
    {
        $tous = $this->em->getRepository(User::class)->findBy(['actif' => true]);

        return array_values(array_filter($tous, static fn (User $u): bool => \in_array(User::ROLE_LIVREUR, $u->getRoles(), true)));
    }

    /**
     * Remise au client : quantités livrées, photos, signature, encaissement.
     *
     * @param array<int, int|string>   $quantites id de ligne => quantité remise
     * @param list<UploadedFile>       $photos
     */
    public function remettre(BonLivraison $bon, User $livreur, array $quantites, array $photos, ?string $signaturePng, ?string $signataire, ?string $mode, ?int $montant, \DateTimeImmutable $maintenant): ?string
    {
        if ($bon->getStatut() !== BonLivraison::STATUT_VALIDE) {
            return 'Cette livraison n\'est pas en cours.';
        }
        if ($signaturePng === null || $signaturePng === '') {
            return 'La signature du client est obligatoire.';
        }
        $total = 0;
        foreach ($bon->getLignes() as $ligne) {
            $q = (int) ($quantites[$ligne->getId()] ?? 0);
            $q = max(0, min($q, (int) $ligne->getQuantiteCommandee()));
            $ligne->setQuantiteLivree($q);
            $total += $q;
        }
        if ($total === 0) {
            return 'Cochez au moins un article remis.';
        }
        if ($mode !== null && $mode !== '') {
            $reste = (int) $bon->getCommande()?->getResteAPayer();
            if ($mode !== 'credit' && ($montant === null || $montant <= 0 || $montant > $reste)) {
                return \sprintf('Montant encaissé invalide : au plus %s F restent à payer.', number_format($reste, 0, ',', ' '));
            }
            $bon->noterEncaissement($montant, $mode, $maintenant);
        }
        $signature = $this->rangerSignature($signaturePng, $maintenant);
        if ($signature === null) {
            return 'La signature n\'a pas pu être lue : recommencez.';
        }
        $bon->signer($signature, $signataire);
        foreach ($photos as $photo) {
            $nom = $this->rangerPhoto($photo, $maintenant);
            if ($nom !== null) {
                $bon->ajouterPhoto($nom);
            }
        }
        $bon->avancer('arrive', $maintenant);
        $bon->marquerLivre($livreur);
        $this->em->flush();

        return null;
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
