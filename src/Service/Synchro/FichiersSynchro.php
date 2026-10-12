<?php

namespace App\Service\Synchro;

use App\Entity\CommandeDetailFichier;
use App\Entity\ControlePrePresse;
use App\Entity\SynchroFichier;
use App\Service\EspaceClient\EspaceClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Octets des fichiers entre l'ERP local et le site (docs/synchronisation.md).
 *
 * Seuls les fichiers montrés au client partent : ceux que l'atelier a
 * préparés pour un BAT. Les fichiers de production et ceux du client
 * restent au local. Envoi par morceaux, repris là où il s'est arrêté, et
 * vérifié par empreinte SHA-256 avant d'être rangé sur le site.
 */
final class FichiersSynchro
{
    /** Taille d'un morceau (en base64 dans un envoi signé de 10 Mo au plus). */
    public const MORCEAU = 4 * 1024 * 1024;

    /** Volume envoyé au plus par cycle : la liaison reste disponible pour le reste. */
    public const BUDGET_CYCLE = 64 * 1024 * 1024;

    private const NOM_SUR = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Serialiseur $serialiseur,
        private readonly EspaceClient $espaceClient,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /* ---------- Côté local ---------- */

    /** @return list<CommandeDetailFichier> les fichiers de BAT à tenir à jour sur le site */
    public function aEnvoyer(): array
    {
        return $this->em->createQueryBuilder()
            ->select('f')->from(CommandeDetailFichier::class, 'f')
            ->where('f.id IN (SELECT f2.id FROM '.ControlePrePresse::class.' c JOIN c.fichiers f2 WHERE c.batNecessaire = true)')
            ->andWhere('f.origine <> :client')->setParameter('client', CommandeDetailFichier::ORIGINE_CLIENT)
            ->andWhere('f.actif = true')
            ->andWhere('f.statut = :termine')->setParameter('termine', CommandeDetailFichier::STATUT_TERMINE)
            ->orderBy('f.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Envoie ce qui a changé, dans la limite du budget du cycle.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler appel signé au site
     *
     * @return array{envoyes: int, octets: int, manquants: int}
     */
    public function envoyer(callable $appeler, int $budget = self::BUDGET_CYCLE): array
    {
        $bilan = ['envoyes' => 0, 'octets' => 0, 'manquants' => 0];
        foreach ($this->aEnvoyer() as $fichier) {
            if ($bilan['octets'] >= $budget) {
                break;
            }
            $chemin = $this->espaceClient->cheminSurDisque($fichier);
            if ($chemin === null) {
                ++$bilan['manquants'];
                continue;
            }
            $id = $fichier->getPublicId()->toRfc4122();
            $signature = filesize($chemin).':'.filemtime($chemin);
            $suivi = $this->em->find(SynchroFichier::class, $id);
            if ($suivi?->getSignature() === $signature) {
                continue;
            }
            $empreinte = (string) hash_file('sha256', $chemin);
            $taille = (int) filesize($chemin);
            if ($taille === 0) {
                continue;
            }
            if ($suivi?->getEmpreinte() === $empreinte) {
                $suivi->noterEnvoi($signature, $empreinte); // seulement touché
                $this->em->flush();
                continue;
            }

            $entete = ['id' => $id, 'empreinte' => $empreinte, 'taille' => $taille];
            $recu = (int) ($appeler('/api/synchro/fichier', $entete)['recu'] ?? 0);
            $flux = fopen($chemin, 'rb');
            if ($flux === false) {
                ++$bilan['manquants'];
                continue;
            }
            try {
                while ($recu < $taille && $bilan['octets'] < $budget) {
                    fseek($flux, $recu);
                    $morceau = (string) fread($flux, self::MORCEAU);
                    $reponse = $appeler('/api/synchro/fichier', $entete + ['position' => $recu, 'morceau' => base64_encode($morceau)]);
                    $bilan['octets'] += \strlen($morceau);
                    $suivant = (int) ($reponse['recu'] ?? 0);
                    if ($suivant <= $recu && $suivant !== 0) {
                        throw new \RuntimeException('Le site n\'avance plus sur '.$fichier->getNomOriginal());
                    }
                    $recu = $suivant;
                }
            } finally {
                fclose($flux);
            }
            if ($recu >= $taille) {
                $suivi ??= new SynchroFichier($id, $signature, $empreinte);
                $suivi->noterEnvoi($signature, $empreinte);
                $this->em->persist($suivi);
                $this->em->flush();
                ++$bilan['envoyes'];
            }
        }

        return $bilan;
    }

    /* ---------- Côté site ---------- */

    /**
     * Reçoit un morceau (ou, sans morceau, dit où reprendre).
     *
     * @param array<string, mixed> $donnees id, empreinte, taille, position?, morceau? (base64)
     *
     * @return array{recu: int, termine: bool}
     */
    public function recevoir(array $donnees): array
    {
        $id = (string) ($donnees['id'] ?? '');
        $empreinte = strtolower((string) ($donnees['empreinte'] ?? ''));
        $taille = (int) ($donnees['taille'] ?? -1);
        if (!preg_match('/^[0-9a-f]{64}$/', $empreinte) || $taille < 0) {
            throw new \InvalidArgumentException('Fichier mal décrit');
        }
        $fichier = $this->serialiseur->trouver('fichier', $id);
        if (!$fichier instanceof CommandeDetailFichier) {
            throw new \InvalidArgumentException('Fiche du fichier pas encore reçue');
        }
        $nom = basename(trim((string) $fichier->getNomStockage()));
        if (!preg_match(self::NOM_SUR, $nom)) {
            throw new \InvalidArgumentException('Nom de stockage refusé');
        }
        $morceau = isset($donnees['morceau']) ? base64_decode((string) $donnees['morceau'], true) : null;
        if ($morceau === false) {
            throw new \InvalidArgumentException('Morceau invalide');
        }

        return self::ecrire($this->projet.'/var/uploads/commandes', $nom, $empreinte, $taille, (int) ($donnees['position'] ?? -1), $morceau);
    }

    /**
     * Écrit un morceau à la suite du fichier partiel ; rangé sous son nom
     * seulement quand il est complet et que l'empreinte correspond.
     * Sans morceau : dit où reprendre.
     *
     * @return array{recu: int, termine: bool}
     */
    public static function ecrire(string $dossier, string $nom, string $empreinte, int $taille, int $position, ?string $morceau): array
    {
        if (!preg_match(self::NOM_SUR, $nom) || !preg_match('/^[0-9a-f]{64}$/', $empreinte)) {
            throw new \InvalidArgumentException('Fichier mal décrit');
        }
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des fichiers impossible à créer');
        }
        $final = $dossier.'/'.$nom;
        $partiel = $dossier.'/.synchro-'.$empreinte.'.part';
        clearstatcache();

        if ($morceau === null) {
            if (is_file($final) && filesize($final) === $taille && hash_file('sha256', $final) === $empreinte) {
                return ['recu' => $taille, 'termine' => true];
            }

            return ['recu' => is_file($partiel) ? (int) filesize($partiel) : 0, 'termine' => false];
        }

        $deja = is_file($partiel) ? (int) filesize($partiel) : 0;
        if ($position !== $deja) {
            return ['recu' => $deja, 'termine' => false]; // l'agent reprend au bon endroit
        }
        if ($deja + \strlen($morceau) > $taille) {
            throw new \InvalidArgumentException('Morceau en trop');
        }
        file_put_contents($partiel, $morceau, \FILE_APPEND | \LOCK_EX);
        $recu = $deja + \strlen($morceau);
        if ($recu < $taille) {
            return ['recu' => $recu, 'termine' => false];
        }
        if (hash_file('sha256', $partiel) !== $empreinte) {
            @unlink($partiel);
            throw new \InvalidArgumentException('Empreinte différente : fichier refusé, il sera renvoyé');
        }
        chmod($partiel, 0640);
        rename($partiel, $final);

        return ['recu' => $recu, 'termine' => true];
    }
}
