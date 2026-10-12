<?php

namespace App\Service\Sauvegarde;

use App\Service\Synchro\FichiersSynchro;
use App\Service\Synchro\Synchro;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copie chiffrée des sauvegardes hors de la boutique (docs/sauvegardes.md).
 *
 * L'agent de synchronisation l'envoie au site en ligne, par morceaux, après
 * l'avoir chiffrée au local : la base de chaque jour, et l'archive des
 * fichiers une fois par semaine. Le site la range hors de public/, sans
 * pouvoir la lire, et n'en garde que les plus récentes. Aucun
 * téléchargement par le site : on la récupère par l'accès au serveur.
 */
final class HorsSite
{
    public const ETAT_BASE = 'hors_site_base';
    public const ETAT_FICHIERS = 'hors_site_fichiers';

    /** L'archive des fichiers, souvent lourde, ne repart qu'une fois par semaine. */
    public const JOURS_FICHIERS = 7;

    /** Ce que le site garde. */
    public const GARDER = ['base' => 14, 'fichiers' => 4];

    /** Sans copie de la base hors site depuis ce délai : alerte. */
    public const RETARD_HEURES = 48;

    private const NOM = '/^(\d{8}-\d{6})-(base|fichiers)\.sihs$/';

    public function __construct(
        private readonly Sauvegardes $sauvegardes,
        private readonly Synchro $synchro,
        #[Autowire('%env(default::SAUVEGARDE_CLE_HORS_SITE)%')] private readonly ?string $cle,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    public function estActive(): bool
    {
        return Chiffrement::cleValable((string) $this->cle) && !$this->synchro->estEnLigne();
    }

    /**
     * Ce qui reste à envoyer : la base de la dernière sauvegarde, et
     * l'archive des fichiers de la dernière complète si la précédente
     * envoyée a plus d'une semaine.
     *
     * @return list<array{nom: string, partie: string, source: string}>
     */
    public function aEnvoyer(\DateTimeImmutable $maintenant): array
    {
        $liste = $this->sauvegardes->liste();
        $dossier = $this->sauvegardes->dossier();
        $items = [];
        foreach ($liste as $s) {
            if ($s['base'] !== null) {
                if ($s['nom'] > (string) $this->synchro->etat(self::ETAT_BASE)) {
                    $items[] = ['nom' => $s['nom'], 'partie' => 'base', 'source' => $dossier.'/'.$s['nom'].'/'.$s['base']['fichier']];
                }
                break;
            }
        }
        $dernier = Rotation::date((string) $this->synchro->etat(self::ETAT_FICHIERS));
        if ($dernier === null || $dernier < $maintenant->modify('-'.self::JOURS_FICHIERS.' days')) {
            foreach ($liste as $s) {
                if ($s['fichiers'] !== null) {
                    if ($s['nom'] > (string) $this->synchro->etat(self::ETAT_FICHIERS)) {
                        $items[] = ['nom' => $s['nom'], 'partie' => 'fichiers', 'source' => $dossier.'/'.$s['nom'].'/'.$s['fichiers']['fichier']];
                    }
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Chiffre (une fois) et envoie ce qui est dû, dans la limite du budget.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler appel signé au site
     *
     * @return list<array{nom: string, partie: string, octets: int}> ce qui est arrivé complet
     */
    public function envoyer(callable $appeler, int $budget = FichiersSynchro::BUDGET_CYCLE, ?\DateTimeImmutable $maintenant = null): array
    {
        if (!$this->estActive()) {
            return [];
        }
        $termines = [];
        $envoye = 0;
        $items = $this->aEnvoyer($maintenant ?? new \DateTimeImmutable());
        // Une copie chiffrée dépassée par une sauvegarde plus récente n'a plus à partir.
        $voulus = array_map(static fn (array $i): string => $i['source'].'.sihs', $items);
        foreach (glob($this->sauvegardes->dossier().'/*/*.sihs') ?: [] as $ancien) {
            if (!\in_array($ancien, $voulus, true)) {
                @unlink($ancien);
            }
        }
        foreach ($items as $item) {
            if ($envoye >= $budget) {
                break;
            }
            $chiffre = $item['source'].'.sihs';
            if (!is_file($chiffre)) {
                $provisoire = $chiffre.'.en-cours';
                @unlink($provisoire);
                Chiffrement::chiffrer($item['source'], $provisoire, (string) $this->cle);
                @chmod($provisoire, 0600);
                rename($provisoire, $chiffre);
            }
            $taille = (int) filesize($chiffre);
            $entete = ['nom' => $item['nom'].'-'.$item['partie'].'.sihs', 'empreinte' => (string) hash_file('sha256', $chiffre), 'taille' => $taille];
            $recu = (int) ($appeler('/api/synchro/sauvegarde', $entete)['recu'] ?? 0);
            $flux = fopen($chiffre, 'rb');
            if ($flux === false) {
                continue;
            }
            try {
                while ($recu < $taille && $envoye < $budget) {
                    fseek($flux, $recu);
                    $morceau = (string) fread($flux, FichiersSynchro::MORCEAU);
                    $suivant = (int) ($appeler('/api/synchro/sauvegarde', $entete + ['position' => $recu, 'morceau' => base64_encode($morceau)])['recu'] ?? 0);
                    $envoye += \strlen($morceau);
                    if ($suivant <= $recu) {
                        throw new \RuntimeException('Le site n\'avance plus sur la sauvegarde '.$item['nom']);
                    }
                    $recu = $suivant;
                }
            } finally {
                fclose($flux);
            }
            if ($recu >= $taille) {
                $this->synchro->noterEtat($item['partie'] === 'base' ? self::ETAT_BASE : self::ETAT_FICHIERS, $item['nom']);
                @unlink($chiffre); // la copie chiffrée n'a plus à rester au local
                $termines[] = ['nom' => $item['nom'], 'partie' => $item['partie'], 'octets' => $taille];
            }
        }

        return $termines;
    }

    /** @return array{active: bool, base: ?\DateTimeImmutable, fichiers: ?\DateTimeImmutable, alerte: ?string} */
    public function etat(\DateTimeImmutable $maintenant): array
    {
        $base = Rotation::date((string) $this->synchro->etat(self::ETAT_BASE));
        $alerte = null;
        if ($this->estActive() && ($base === null || $base < $maintenant->modify('-'.self::RETARD_HEURES.' hours'))) {
            $alerte = $base === null ? 'Aucune copie de la base n\'est encore partie hors site.' : 'Pas de copie de la base hors site depuis le '.$base->format('d/m').'.';
        }

        return ['active' => $this->estActive(), 'base' => $base, 'fichiers' => Rotation::date((string) $this->synchro->etat(self::ETAT_FICHIERS)), 'alerte' => $alerte];
    }

    /* ---------- Côté site ---------- */

    /**
     * Reçoit un morceau de copie chiffrée ; une fois complète, ne garde que
     * les plus récentes.
     *
     * @param array<string, mixed> $donnees nom, empreinte, taille, position?, morceau? (base64)
     *
     * @return array{recu: int, termine: bool}
     */
    public function recevoir(array $donnees): array
    {
        $nom = (string) ($donnees['nom'] ?? '');
        if (!preg_match(self::NOM, $nom)) {
            throw new \InvalidArgumentException('Nom de sauvegarde refusé');
        }
        $morceau = isset($donnees['morceau']) ? base64_decode((string) $donnees['morceau'], true) : null;
        if ($morceau === false) {
            throw new \InvalidArgumentException('Morceau invalide');
        }
        $dossier = $this->dossierSite();
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0700, true);
        }
        $resultat = FichiersSynchro::ecrire($dossier, $nom, strtolower((string) ($donnees['empreinte'] ?? '')), (int) ($donnees['taille'] ?? -1), (int) ($donnees['position'] ?? -1), $morceau);
        if ($resultat['termine']) {
            @chmod($dossier.'/'.$nom, 0600);
            self::tournerSite($dossier);
        }

        return $resultat;
    }

    public function dossierSite(): string
    {
        return $this->projet.'/var/sauvegardes-hors-site';
    }

    /** @return list<string> fichiers supprimés */
    public static function tournerSite(string $dossier): array
    {
        $parPartie = [];
        foreach (scandir($dossier, \SCANDIR_SORT_DESCENDING) ?: [] as $f) {
            if (preg_match(self::NOM, $f, $m)) {
                $parPartie[$m[2]][] = $f;
            }
        }
        $supprimes = [];
        foreach (glob($dossier.'/.synchro-*.part') ?: [] as $partiel) {
            if (filemtime($partiel) < time() - 2 * 86400) {
                @unlink($partiel); // envoi abandonné (dépassé par une sauvegarde plus récente)
                $supprimes[] = basename($partiel);
            }
        }
        foreach ($parPartie as $partie => $fichiers) {
            foreach (\array_slice($fichiers, self::GARDER[$partie]) as $f) {
                @unlink($dossier.'/'.$f);
                $supprimes[] = $f;
            }
        }

        return $supprimes;
    }
}
