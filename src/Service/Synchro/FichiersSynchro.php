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
 * Vers le site, seuls les fichiers montrés au client partent : ceux que
 * l'atelier a préparés pour un BAT ; les fichiers de production restent
 * au local. Du site, le local rapatrie les fichiers que le client y a
 * envoyés (espace client). Par morceaux, repris là où ça s'est arrêté, et
 * vérifié par empreinte SHA-256 avant d'être rangé.
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

    /** @return list<CommandeDetailFichier> fichiers envoyés par des clients en ligne, pas encore ici */
    public function aRecuperer(int $nombre = 200): array
    {
        $candidats = $this->em->createQueryBuilder()
            ->select('f')->from(CommandeDetailFichier::class, 'f')
            ->where('f.origine = :client')->setParameter('client', CommandeDetailFichier::ORIGINE_CLIENT)
            ->andWhere('f.empreinte IS NOT NULL')->andWhere('f.actif = true')
            ->andWhere('f.statut = :termine')->setParameter('termine', CommandeDetailFichier::STATUT_TERMINE)
            ->andWhere('f.creeLe > :depuis')->setParameter('depuis', new \DateTimeImmutable('-60 days'))
            // Ceux d'une commande d'abord : l'atelier en a besoin.
            ->addSelect('CASE WHEN f.commandeDetail IS NULL THEN 1 ELSE 0 END AS HIDDEN sansCommande')
            ->orderBy('sansCommande', 'ASC')->addOrderBy('f.id', 'ASC')
            ->setMaxResults($nombre)
            ->getQuery()->getResult();

        return array_values(array_filter($candidats, fn (CommandeDetailFichier $f): bool => $this->espaceClient->cheminSurDisque($f) === null));
    }

    /**
     * Rapatrie du site les fichiers envoyés par les clients, dans la
     * limite du budget du cycle.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler appel signé au site
     *
     * @return array{recus: int, octets: int}
     */
    public function recuperer(callable $appeler, int $budget = self::BUDGET_CYCLE): array
    {
        $bilan = ['recus' => 0, 'octets' => 0];
        $dossier = $this->projet.'/var/uploads/commandes';
        foreach ($this->aRecuperer() as $fichier) {
            if ($bilan['octets'] >= $budget) {
                break;
            }
            $nom = basename(trim((string) $fichier->getNomStockage()));
            $empreinte = (string) $fichier->getEmpreinte();
            $taille = (int) $fichier->getTaille();
            $etat = self::ecrire($dossier, $nom, $empreinte, $taille, -1, null);
            while (!$etat['termine'] && $bilan['octets'] < $budget) {
                $reponse = $appeler('/api/synchro/fichier-client', ['id' => $fichier->getPublicId()->toRfc4122(), 'position' => $etat['recu']]);
                $morceau = base64_decode((string) ($reponse['morceau'] ?? ''), true);
                if ($morceau === false || $morceau === '') {
                    throw new \RuntimeException('Le site ne donne pas le fichier '.$fichier->getNomOriginal());
                }
                $etat = self::ecrire($dossier, $nom, $empreinte, $taille, $etat['recu'], $morceau);
                $bilan['octets'] += \strlen($morceau);
            }
            if ($etat['termine']) {
                ++$bilan['recus'];
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

    /**
     * Côté site : un morceau d'un fichier envoyé par un client.
     *
     * @param array<string, mixed> $donnees id, position
     *
     * @return array{morceau: string, taille: int}
     */
    public function lire(array $donnees): array
    {
        $fichier = $this->serialiseur->trouver('fichier', (string) ($donnees['id'] ?? ''));
        if (!$fichier instanceof CommandeDetailFichier || $fichier->getOrigine() !== CommandeDetailFichier::ORIGINE_CLIENT || !$fichier->estUploadTermine()) {
            throw new \InvalidArgumentException('Fichier inconnu');
        }
        $chemin = $this->espaceClient->cheminSurDisque($fichier);
        $position = (int) ($donnees['position'] ?? -1);
        if ($chemin === null || $position < 0 || $position >= (int) filesize($chemin)) {
            throw new \InvalidArgumentException('Fichier absent ou position invalide');
        }
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new \InvalidArgumentException('Fichier illisible');
        }
        try {
            fseek($flux, $position);
            $morceau = (string) fread($flux, self::MORCEAU);
        } finally {
            fclose($flux);
        }

        return ['morceau' => base64_encode($morceau), 'taille' => (int) filesize($chemin)];
    }
}
