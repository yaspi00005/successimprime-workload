<?php

namespace App\Service\Livraisons;

use App\Entity\AdresseLivraison;
use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Programmer une livraison depuis l'espace client (maquette DB09) : où
 * (adresse, repère, position), quand (jour et créneau, les créneaux pleins
 * barrés), qui reçoit (et qui si absent), comment payer le reste. Le choix
 * est gardé sur la commande (Commandes::programmationLivraison), remonte au
 * local et y est appliqué au bon de livraison (appliquer).
 */
final class ProgrammationLivraison
{
    /** Créneaux de deux heures (clé => libellé du bon de livraison). */
    public const CRENEAUX = ['08-10' => '8 h – 10 h', '10-12' => '10 h – 12 h', '14-16' => '14 h – 16 h', '16-18' => '16 h – 18 h'];

    /** Livraisons au plus par créneau, tous livreurs confondus. */
    public const CAPACITE = 4;

    /** Jours proposés. */
    public const JOURS = 4;

    public const PAIEMENT_OM = 'orange_money';
    public const PAIEMENT_LIVREUR = 'livreur';
    public const MODE_RETRAIT = 'retrait';
    public const MODE_LIVRAISON = 'livraison';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * Jours et créneaux proposés, avec ceux qui sont pleins ou passés.
     *
     * @return list<array{jour: \DateTimeImmutable, libelle: string, creneaux: list<array{cle: string, libelle: string, plein: bool}>}>
     */
    public function jours(?\DateTimeImmutable $pret, \DateTimeImmutable $maintenant, ?Commandes $sauf = null): array
    {
        $heures = $this->parametres->heuresTravail();
        $jour = max($maintenant->setTime(0, 0), ($pret ?? $maintenant)->setTime(0, 0));
        if (!$heures->estOuvre($jour)) {
            $jour = DelaisCommande::jourOuvreSuivant($heures, $jour);
        }
        $jours = [];
        for ($i = 0; $i < self::JOURS; ++$i) {
            $jours[] = $jour;
            $jour = DelaisCommande::jourOuvreSuivant($heures, $jour);
        }
        $pris = $this->pris($jours[0], $jours[\count($jours) - 1], $sauf);

        return self::grille($jours, $pris, $maintenant);
    }

    /**
     * @param list<\DateTimeImmutable> $jours
     * @param array<string, int>       $pris  « Y-m-d|clé » => livraisons déjà prévues
     *
     * @return list<array{jour: \DateTimeImmutable, libelle: string, creneaux: list<array{cle: string, libelle: string, plein: bool}>}>
     */
    public static function grille(array $jours, array $pris, \DateTimeImmutable $maintenant): array
    {
        $grille = [];
        foreach ($jours as $jour) {
            $creneaux = [];
            foreach (self::CRENEAUX as $cle => $libelle) {
                // Un créneau du jour même se réserve au plus tard une heure avant son début.
                $debut = $jour->setTime((int) substr($cle, 0, 2), 0);
                $passe = $maintenant > $debut->modify('-1 hour');
                $creneaux[] = ['cle' => $cle, 'libelle' => $libelle, 'plein' => $passe || ($pris[$jour->format('Y-m-d').'|'.$cle] ?? 0) >= self::CAPACITE];
            }
            if (array_filter($creneaux, static fn (array $c): bool => !$c['plein']) !== []) {
                $grille[] = ['jour' => $jour, 'libelle' => self::jourCourt($jour), 'creneaux' => $creneaux];
            }
        }

        return $grille;
    }

    /** @return array<string, int> */
    private function pris(\DateTimeImmutable $du, \DateTimeImmutable $au, ?Commandes $sauf): array
    {
        $pris = [];
        $commandes = [];
        $bons = $this->em->createQueryBuilder()->select('bl')->from(BonLivraison::class, 'bl')
            ->where('bl.prevuLe BETWEEN :du AND :au')->andWhere('bl.statut != :annule')->andWhere('bl.creneau IS NOT NULL')
            ->setParameter('du', $du)->setParameter('au', $au->setTime(23, 59))->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->getQuery()->getResult();
        foreach ($bons as $bl) {
            $cle = array_search($bl->getCreneau(), self::CRENEAUX, true);
            $id = $bl->getCommande()?->getId();
            if (false !== $cle && $id !== $sauf?->getId()) {
                $commandes[$id] = $bl->getPrevuLe()->format('Y-m-d').'|'.$cle;
            }
        }
        // Programmées en ligne, pas encore reportées sur un bon de livraison.
        $programmees = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.programmationLivraison IS NOT NULL')->andWhere('c.deleted = false')
            ->orderBy('c.id', 'DESC')->setMaxResults(500)->getQuery()->getResult();
        foreach ($programmees as $c) {
            $p = $c->getProgrammationLivraison() ?? [];
            if (self::MODE_LIVRAISON === ($p['mode'] ?? null) && $c->getId() !== $sauf?->getId() && !isset($commandes[$c->getId()])) {
                $commandes[$c->getId()] = ($p['jour'] ?? '').'|'.($p['creneau'] ?? '');
            }
        }
        foreach ($commandes as $cle) {
            $pris[$cle] = ($pris[$cle] ?? 0) + 1;
        }

        return $pris;
    }

    /** @return list<AdresseLivraison> */
    public function adresses(Clients $client): array
    {
        return $this->em->getRepository(AdresseLivraison::class)->findBy(['client' => $client, 'actif' => true], ['parDefaut' => 'DESC', 'id' => 'ASC']);
    }

    /**
     * Lit le formulaire. Renvoie la programmation, ou la liste des erreurs.
     *
     * @param array<string, mixed>   $saisie
     * @param list<AdresseLivraison> $adresses
     * @param list<array{jour: \DateTimeImmutable, libelle: string, creneaux: list<array{cle: string, libelle: string, plein: bool}>}> $jours
     *
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    public static function lire(array $saisie, array $adresses, array $jours, \DateTimeImmutable $maintenant): array
    {
        $texte = static fn (string $cle, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($saisie[$cle] ?? '')) ?? ''), 0, $max);
        if (self::MODE_RETRAIT === ($saisie['mode'] ?? null)) {
            return [['mode' => self::MODE_RETRAIT, 'le' => $maintenant->format(\DATE_ATOM)], []];
        }
        $erreurs = [];
        $choix = (string) ($saisie['adresse'] ?? '');
        $adresse = null;
        foreach ($adresses as $a) {
            if ((string) $a->getId() === $choix) {
                $adresse = ['libelle' => $a->getLibelle(), 'adresse' => $a->getAdresse()];
            }
        }
        if ('nouvelle' === $choix) {
            $adresse = ['libelle' => $texte('nouvelle_libelle', 60) ?: 'Nouvelle adresse', 'adresse' => $texte('nouvelle_adresse', 255)];
        }
        if (null === $adresse || '' === $adresse['adresse']) {
            $erreurs[] = 'Choisissez l\'adresse de livraison.';
        }
        $lat = is_numeric($saisie['latitude'] ?? null) ? (float) $saisie['latitude'] : null;
        $lng = is_numeric($saisie['longitude'] ?? null) ? (float) $saisie['longitude'] : null;
        if (null === $lat || null === $lng || abs($lat) > 90 || abs($lng) > 180) {
            $lat = $lng = null;
        }
        $jour = (string) ($saisie['jour'] ?? '');
        $creneau = (string) ($saisie['creneau'] ?? '');
        $libre = false;
        foreach ($jours as $j) {
            foreach ($j['creneaux'] as $c) {
                $libre = $libre || ($j['jour']->format('Y-m-d') === $jour && $c['cle'] === $creneau && !$c['plein']);
            }
        }
        if (!$libre) {
            $erreurs[] = 'Choisissez un jour et un créneau libres.';
        }
        $nom = $texte('nom', 120);
        $telephone = mb_substr(preg_replace('/[^\d+ ]/', '', (string) ($saisie['telephone'] ?? '')) ?? '', 0, 20);
        if ('' === $nom || \strlen(preg_replace('/\D/', '', $telephone) ?? '') < 8) {
            $erreurs[] = 'Indiquez le nom et le téléphone de la personne qui reçoit.';
        }
        $paiement = \in_array($saisie['paiement'] ?? null, [self::PAIEMENT_OM, self::PAIEMENT_LIVREUR], true) ? $saisie['paiement'] : self::PAIEMENT_LIVREUR;
        if ([] !== $erreurs) {
            return [null, $erreurs];
        }

        return [[
            'mode' => self::MODE_LIVRAISON,
            'libelle' => $adresse['libelle'], 'adresse' => $adresse['adresse'], 'repere' => $texte('repere', 160) ?: null,
            'latitude' => null === $lat ? null : round($lat, 5), 'longitude' => null === $lng ? null : round($lng, 5),
            'jour' => $jour, 'creneau' => $creneau,
            'nom' => $nom, 'telephone' => trim($telephone), 'siAbsent' => $texte('si_absent', 120) ?: null,
            'paiement' => $paiement, 'le' => $maintenant->format(\DATE_ATOM),
        ], []];
    }

    /** « jeu. 23/10 ». */
    public static function jourCourt(\DateTimeInterface $jour): string
    {
        return [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'][(int) $jour->format('N')].' '.$jour->format('d/m');
    }

    /** « jeu. 23/10 · 10 h – 12 h ». */
    public static function quand(array $p): ?string
    {
        if (self::MODE_LIVRAISON !== ($p['mode'] ?? null) || !isset(self::CRENEAUX[$p['creneau'] ?? ''])) {
            return null;
        }
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p['jour']);

        return false === $jour ? null : self::jourCourt($jour).' · '.self::CRENEAUX[$p['creneau']];
    }

    /**
     * Reporte la programmation sur la commande et sur son bon de livraison
     * encore à livrer (au local à la remontée, en ligne tout de suite).
     */
    public static function appliquer(Commandes $commande, ?BonLivraison $bon): void
    {
        $p = $commande->getProgrammationLivraison();
        if (null === $p) {
            return;
        }
        if (self::MODE_RETRAIT === ($p['mode'] ?? null)) {
            $commande->setModeLivraison(Commandes::LIVRAISON_RETRAIT);

            return;
        }
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($p['jour'] ?? ''));
        $adresse = trim(($p['libelle'] ?? '').' · '.($p['adresse'] ?? ''), ' ·');
        $commande->setModeLivraison(Commandes::LIVRAISON_DOMICILE)->setAdresseLivraison(mb_substr($adresse, 0, 255))->setCreneauLivraison(self::quand($p));
        if (null === $bon || !\in_array($bon->getStatut(), [BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_VALIDE], true) || null !== $bon->getEnRouteLe()) {
            return;
        }
        $bon->setPrevuLe(false === $jour ? null : $jour)->setCreneau(self::CRENEAUX[$p['creneau'] ?? ''] ?? null)
            ->setAdresseLivraison(mb_substr($adresse, 0, 255))->setNomReceptionnaire($p['nom'] ?? null)->setTelephoneReceptionnaire($p['telephone'] ?? null)
            ->setRepere($p['repere'] ?? null)->setAbsentNom($p['siAbsent'] ?? null);
        $bon->reprendre();
    }
}
