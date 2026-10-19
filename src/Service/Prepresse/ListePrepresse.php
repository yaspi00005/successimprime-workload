<?php

namespace App\Service\Prepresse;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\OrdreProduction;
use App\Entity\RetourPrepresse;
use App\Service\Catalogue\NomCommercial;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les commandes de la prépresse, une ligne par commande (maquette PQ01)
 * et une carte par colonne (PQ03), avec leurs indicateurs. Lit la base
 * seulement : rien n'est lu sur le disque pendant l'affichage.
 *
 * Une commande est là tant qu'elle a une ligne qui demande la prépresse
 * et n'est pas encore partie en production (les lignes « non requise »,
 * hors imprimerie, n'y sont jamais : docs/hors-imprimerie.md).
 */
final class ListePrepresse
{
    /** Cartes montrées par colonne du tableau avant « + N autres ». */
    public const CARTES_PAR_COLONNE = 6;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array<string, mixed>> les commandes, les plus anciennes d'abord
     */
    public function commandes(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Commandes> $commandes */
        $commandes = $this->em->createQueryBuilder()
            ->select('c', 'd', 'cl', 'f', 'ctl', 'fo', 's')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'd')
            ->leftJoin('c.clients', 'cl')
            ->leftJoin('d.fichiers', 'f')
            ->leftJoin('d.controlesPrePresse', 'ctl')
            ->leftJoin('d.format', 'fo')
            ->leftJoin('d.support', 's')
            ->where('c.deleted = false AND c.statut = true')
            ->andWhere('d.prePresseNecessaire = true AND d.statutProduction = :a')
            ->setParameter('a', CommandesDetails::PRODUCTION_A_PRODUIRE)
            ->orderBy('c.dateCommande', 'ASC')->addOrderBy('d.id', 'ASC')
            ->getQuery()->getResult();
        if ([] === $commandes) {
            return [];
        }
        $retours = $this->retoursOuverts();
        $ordres = $this->ordres($commandes);
        $conceptions = $this->conceptions($commandes);
        $lignes = [];
        foreach ($commandes as $c) {
            $lignes[] = $this->ligne($c, $retours, $ordres, $conceptions, $maintenant);
        }

        return $lignes;
    }

    /**
     * Indicateurs du haut de la page (PQ01).
     *
     * @param list<array<string, mixed>> $commandes
     *
     * @return array<string, array<string, mixed>>
     */
    public function indicateurs(array $commandes, \DateTimeImmutable $maintenant): array
    {
        $aujourdhui = $maintenant->format('Y-m-d');
        $sans = array_filter($commandes, static fn (array $c): bool => $c['manquants'] > 0);
        $aControler = array_filter($commandes, static fn (array $c): bool => $c['compte'][EtatPrepresse::A_CONTROLER] > 0);
        $bat = array_filter($commandes, static fn (array $c): bool => $c['compte'][EtatPrepresse::BAT] > 0);
        $retours = array_filter($commandes, static fn (array $c): bool => $c['compte'][EtatPrepresse::RETOUR] > 0);
        $plusAncienBat = null;
        foreach ($bat as $c) {
            $plusAncienBat = null === $plusAncienBat || $c['batEnvoyeLe'] < $plusAncienBat ? $c['batEnvoyeLe'] : $plusAncienBat;
        }
        $avant = null;
        foreach ($retours as $c) {
            if (null !== $c['dateLivraison'] && (null === $avant || $c['dateLivraison'] < $avant)) {
                $avant = $c['dateLivraison'];
            }
        }
        $valides = $this->validesAujourdhui($maintenant);

        return [
            'sans_fichier' => ['nombre' => \count($sans), 'detail' => \sprintf('dont %d depuis plus de 7 jours', \count(array_filter($sans, static fn (array $c): bool => $c['joursCommande'] >= 7)))],
            'a_controler' => ['nombre' => \count($aControler), 'detail' => \sprintf('dont %d à livrer aujourd\'hui', \count(array_filter($aControler, static fn (array $c): bool => null !== $c['dateLivraison'] && $c['dateLivraison']->format('Y-m-d') === $aujourdhui)))],
            'bat' => ['nombre' => \count($bat), 'detail' => null !== $plusAncienBat ? 'le plus ancien envoyé il y a '.EtatPrepresse::age($plusAncienBat, $maintenant) : 'aucun BAT chez un client'],
            'retours' => ['nombre' => \count($retours), 'detail' => null !== $avant ? 'à corriger avant '.($avant->format('Y-m-d') === $aujourdhui ? $avant->format('H:i') : $avant->format('d/m')) : (\count($retours) > 0 ? 'à corriger en priorité' : 'aucun retour de machine')],
            'valides' => ['nombre' => $valides['nombre'], 'detail' => $valides['nombre'] > 0 ? 'temps moyen : '.EtatPrepresse::age($maintenant->modify('-'.$valides['minutes'].' minutes'), $maintenant) : 'aucun encore aujourd\'hui'],
        ];
    }

    /**
     * Colonnes du tableau (PQ03) : une carte par commande dans chaque
     * colonne où elle a une ligne.
     *
     * @param list<array<string, mixed>> $commandes
     *
     * @return array<string, array{libelle: string, cartes: list<array<string, mixed>>, total: int}>
     */
    public function colonnes(array $commandes): array
    {
        $colonnes = [];
        foreach (EtatPrepresse::COLONNES as $etat => $libelle) {
            $cartes = array_values(array_filter($commandes, static fn (array $c): bool => \in_array($etat, $c['onglets'], true) || (EtatPrepresse::A_CONTROLER === $etat && \in_array(EtatPrepresse::RETOUR, $c['onglets'], true))));
            // Prêt pour la production : les plus récemment validées d'abord.
            if (EtatPrepresse::PRET === $etat) {
                usort($cartes, static fn (array $a, array $b): int => ($b['valideLe'] ?? $b['dateCommande']) <=> ($a['valideLe'] ?? $a['dateCommande']));
            }
            $colonnes[$etat] = ['libelle' => $libelle, 'cartes' => $cartes, 'total' => \count($cartes)];
        }

        return $colonnes;
    }

    /** Un client qu'on peut relancer : une fiche avec un numéro de téléphone. */
    public static function clientJoignable(?Clients $client): bool
    {
        return null !== $client && '' !== trim((string) ($client->getTelephone() ?: $client->getTelephone2()));
    }

    /** Le nom court du client (« Fofana »), ou null sans client. */
    public static function nomClient(?Clients $client): ?string
    {
        if (null === $client) {
            return null;
        }
        $nom = trim((string) ($client->getRaisonSociale() ?: $client->getNom()));

        return '' !== $nom ? $nom : (trim($client->getNomComplet()) ?: null);
    }

    /**
     * @param array<int, RetourPrepresse>   $retours     ligne => retour ouvert
     * @param array<int, OrdreProduction>   $ordres      ligne => ordre de production
     * @param array<int, Conception>        $conceptions contrôle => conception
     *
     * @return array<string, mixed>
     */
    private function ligne(Commandes $c, array $retours, array $ordres, array $conceptions, \DateTimeImmutable $maintenant): array
    {
        $formats = [];
        $etats = [];
        $designations = [];
        $quantite = 0;
        $dernierFichier = null;
        $batEnvoyeLe = null;
        $valideLe = null;
        $vignette = null;
        $version = 0;
        $machine = null;
        $retourLe = null;
        foreach (DepotFichiers::lignes($c) as $l) {
            $controle = $l->getControlesPrePresse()->first() ?: null;
            $retour = $retours[(int) $l->getId()] ?? null;
            $recus = DepotFichiers::fichiersRecus($l);
            $etat = EtatPrepresse::ligne(DepotFichiers::aFichier($l), $controle?->getStatut(), null !== $retour);
            $etats[] = $etat;
            $designations[] = EtatPrepresse::sansDimensions(NomCommercial::texteLigne((string) $l->getDesignation(), NomCommercial::elementDeLigne($l)));
            $quantite += $l->getQuantite();
            $mm = DepotFichiers::formatMm($l);
            foreach ($recus as $f) {
                $dernierFichier = null === $dernierFichier || $f->getCreeLe() > $dernierFichier ? $f->getCreeLe() : $dernierFichier;
                if (null === $vignette && str_starts_with((string) $f->getTypeMime(), 'image/') && (int) $f->getTaille() <= 5 * 1024 * 1024) {
                    $vignette = $f->getId();
                }
            }
            if (EtatPrepresse::BAT === $etat && null !== $controle) {
                $conception = $conceptions[(int) $controle->getId()] ?? null;
                $envoi = $conception?->getDernierBatLe() ?? $controle->getTraiteLe() ?? $controle->getCreeLe();
                $batEnvoyeLe = null === $batEnvoyeLe || $envoi < $batEnvoyeLe ? $envoi : $batEnvoyeLe;
                $version = max($version, (int) $conception?->getVersions(), ...array_map(static fn (CommandeDetailFichier $f): int => $f->getVersion(), $controle->getFichiers()->toArray()));
            }
            if (EtatPrepresse::PRET === $etat && null !== $controle) {
                $le = $controle->getValideLe() ?? $controle->getEnvoyeProductionLe() ?? $controle->getCreeLe();
                $valideLe = null === $valideLe || $le > $valideLe ? $le : $valideLe;
                $machine ??= ($ordres[(int) $l->getId()] ?? null)?->getMachine()?->getNom() ?? $l->getMachine()?->getNom() ?? $l->getTypeImpression()?->getNom();
            }
            if (null !== $retour) {
                $retourLe = null === $retourLe || $retour->getCreeLe() < $retourLe ? $retour->getCreeLe() : $retourLe;
            }
            $formats[] = [
                'id' => $l->getId(),
                'format' => DepotFichiers::libelleFormat($l),
                'quantite' => $l->getQuantite(),
                'etat' => $etat,
                'statut' => EtatPrepresse::STATUTS[$etat][0],
                'couleur' => EtatPrepresse::STATUTS[$etat][1],
                'fichiers' => array_map(static fn (CommandeDetailFichier $f): array => ['id' => $f->getId(), 'nom' => $f->getNomOriginal()], $recus),
                'lie' => [] === $recus && [] !== ($l->getFichierLie() ?? []),
                'support' => (string) ($l->getSupport()?->getNom() ?? ''),
                'largeurMm' => $mm[0] ?? null,
                'hauteurMm' => $mm[1] ?? null,
                'retour' => $retour?->getLibelleMotifs(),
            ];
        }
        $compte = array_fill_keys([EtatPrepresse::SANS_FICHIER, EtatPrepresse::A_CONTROLER, EtatPrepresse::RETOUR, EtatPrepresse::BAT, EtatPrepresse::PRET], 0);
        foreach ($etats as $e) {
            ++$compte[$e];
        }
        $client = $c->getClients();
        $joignable = self::clientJoignable($client);
        $resume = EtatPrepresse::resume($designations, $quantite);
        $date = \DateTimeImmutable::createFromInterface($c->getDateCommande() ?? $maintenant);
        $manquants = $compte[EtatPrepresse::SANS_FICHIER];
        // Le badge d'âge dit depuis quand la commande attend, selon ce qui l'arrête.
        [$depuis, $quoi] = match (true) {
            $manquants > 0 => [$date, 'sans fichier'],
            $compte[EtatPrepresse::RETOUR] > 0 => [$retourLe ?? $date, 'en correction'],
            $compte[EtatPrepresse::A_CONTROLER] > 0 => [$dernierFichier ?? $date, 'à contrôler'],
            $compte[EtatPrepresse::BAT] > 0 => [$batEnvoyeLe ?? $date, 'chez le client'],
            default => [$valideLe ?? $date, 'validé'],
        };

        return [
            'id' => $c->getId(),
            'publicId' => (string) $c->getPublicId(),
            'numero' => (string) $c->getNumero(),
            'client' => self::nomClient($client),
            'clientId' => $client?->getId(),
            'clientJoignable' => $joignable,
            'dateCommande' => $date,
            'dateLivraison' => null !== $c->getDateLivraison() ? \DateTimeImmutable::createFromInterface($c->getDateLivraison()) : null,
            'enPause' => $c->estEnPauseFichiers(),
            'joursCommande' => EtapesRelance::jours($date, $maintenant),
            'titre' => $resume['titre'],
            'detail' => $resume['detail'],
            'formats' => $formats,
            'total' => \count($formats),
            'recus' => \count($formats) - $manquants,
            'manquants' => $manquants,
            'compte' => $compte,
            'onglets' => EtatPrepresse::onglets($etats),
            'age' => EtatPrepresse::age($depuis, $maintenant),
            'ageQuoi' => $quoi,
            'ageCouleur' => $manquants > 0 || $compte[EtatPrepresse::RETOUR] > 0 ? EtatPrepresse::couleurAge($depuis, $maintenant) : 'jaune',
            'action' => EtatPrepresse::action($manquants, $compte[EtatPrepresse::A_CONTROLER], $compte[EtatPrepresse::RETOUR], $compte[EtatPrepresse::BAT], $joignable),
            'petits' => EtatPrepresse::petitsFormats(array_map(static fn (array $f): array => ['support' => $f['support'], 'largeurMm' => $f['largeurMm'], 'hauteurMm' => $f['hauteurMm']], $formats)),
            'premiereLigne' => $formats[0]['id'] ?? null,
            'aControlerLigne' => ($f = array_values(array_filter($formats, static fn (array $f): bool => \in_array($f['etat'], [EtatPrepresse::A_CONTROLER, EtatPrepresse::RETOUR], true)))[0] ?? null) ? $f['id'] : null,
            'batLigne' => ($f = array_values(array_filter($formats, static fn (array $f): bool => EtatPrepresse::BAT === $f['etat']))[0] ?? null) ? $f['id'] : null,
            'batEnvoyeLe' => $batEnvoyeLe,
            'version' => $version,
            'valideLe' => $valideLe,
            'machine' => $machine,
            'dernierFichier' => $dernierFichier,
            'vignette' => $vignette,
            'recherche' => mb_strtolower(implode(' ', [$c->getNumero(), self::nomClient($client), $client?->getNom(), $client?->getRaisonSociale(), ...$designations])),
        ];
    }

    /** @return array<int, RetourPrepresse> ligne => retour du machiniste encore ouvert */
    private function retoursOuverts(): array
    {
        $retours = [];
        foreach ($this->em->getRepository(RetourPrepresse::class)->findBy(['corrigeLe' => null]) as $r) {
            $id = (int) $r->getOrdre()->getCommandeDetail()?->getId();
            if ($id > 0 && OrdreProduction::STATUT_A_PRODUIRE === $r->getOrdre()->getStatut()) {
                $retours[$id] ??= $r;
            }
        }

        return $retours;
    }

    /**
     * @param list<Commandes> $commandes
     *
     * @return array<int, OrdreProduction>
     */
    private function ordres(array $commandes): array
    {
        $ordres = [];
        foreach ($this->em->createQuery('SELECT o, m, d FROM '.OrdreProduction::class.' o JOIN o.commandeDetail d LEFT JOIN o.machine m WHERE d.commande IN (:c)')->setParameter('c', $commandes)->getResult() as $o) {
            $ordres[(int) $o->getCommandeDetail()->getId()] = $o;
        }

        return $ordres;
    }

    /**
     * @param list<Commandes> $commandes
     *
     * @return array<int, Conception>
     */
    private function conceptions(array $commandes): array
    {
        $conceptions = [];
        foreach ($this->em->createQuery('SELECT k, ctl FROM '.Conception::class.' k JOIN k.controle ctl JOIN ctl.commandeDetail d WHERE d.commande IN (:c)')->setParameter('c', $commandes)->getResult() as $k) {
            $conceptions[(int) $k->getControle()->getId()] = $k;
        }

        return $conceptions;
    }

    /** @return array{nombre: int, minutes: int} */
    private function validesAujourdhui(\DateTimeImmutable $maintenant): array
    {
        /** @var list<ControlePrePresse> $controles */
        $controles = $this->em->createQuery('SELECT c FROM '.ControlePrePresse::class.' c WHERE c.valideLe >= :debut AND c.valideLe < :fin')
            ->setParameter('debut', $maintenant->setTime(0, 0))->setParameter('fin', $maintenant->setTime(0, 0)->modify('+1 day'))->getResult();
        $minutes = array_map(static fn (ControlePrePresse $c): int => max(0, intdiv((int) $c->getValideLe()?->getTimestamp() - $c->getCreeLe()->getTimestamp(), 60)), $controles);

        return ['nombre' => \count($controles), 'minutes' => [] !== $minutes ? (int) round(array_sum($minutes) / \count($minutes)) : 0];
    }
}
