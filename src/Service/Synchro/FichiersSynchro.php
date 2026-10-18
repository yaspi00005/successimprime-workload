<?php

namespace App\Service\Synchro;

use App\Entity\CommandeDetailFichier;
use App\Entity\ControlePrePresse;
use App\Entity\MessageClient;
use App\Entity\SynchroFichier;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Messagerie\NotesVocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Octets des fichiers entre l'ERP local et le site (docs/synchronisation.md).
 *
 * Vers le site, seuls les fichiers montrés au client partent : ceux que
 * l'atelier a préparés pour un BAT ; les fichiers de production restent
 * au local. Du site, le local rapatrie les fichiers que le client y a
 * envoyés (espace client). Par morceaux, repris là où ça s'est arrêté, et
 * vérifié par empreinte SHA-256 avant d'être rangé.
 *
 * Notes vocales de la messagerie (docs/messagerie-clients.md) : celles
 * que l'atelier envoie au client partent au site, celles que le client
 * enregistre en ligne arrivent au local (revérifiées à l'arrivée). Les
 * notes vocales internes ne quittent jamais le local.
 */
final class FichiersSynchro
{
    /** Taille d'un morceau (en base64 dans un envoi signé de 10 Mo au plus). */
    public const MORCEAU = 4 * 1024 * 1024;

    /** Volume envoyé au plus par cycle : la liaison reste disponible pour le reste. */
    public const BUDGET_CYCLE = 64 * 1024 * 1024;

    /** Un fichier de client que le site n'a pas donné est redemandé après ce délai. */
    public const REESSAI_ABSENT = '6 hours';
    private const ETAT_ABSENT = 'abs:'; // + identifiant (36) : 40 caractères, la taille de la clé

    private const NOM_SUR = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Serialiseur $serialiseur,
        private readonly EspaceClient $espaceClient,
        private readonly Synchro $synchro,
        private readonly NotesVocales $notesVocales,
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
            $this->pousser($appeler, '/api/synchro/fichier', $fichier->getPublicId()->toRfc4122(), $chemin, (string) $fichier->getNomOriginal(), $budget, $bilan);
        }

        return $bilan;
    }

    /**
     * Pousse un fichier vers le site s'il a changé depuis le dernier envoi
     * (SynchroFichier), par morceaux, dans la limite du budget.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler
     * @param array{envoyes: int, octets: int, manquants: int}           $bilan
     */
    private function pousser(callable $appeler, string $route, string $id, string $chemin, string $libelle, int $budget, array &$bilan): void
    {
        $signature = filesize($chemin).':'.filemtime($chemin);
        $suivi = $this->em->find(SynchroFichier::class, $id);
        if ($suivi?->getSignature() === $signature) {
            return;
        }
        $empreinte = (string) hash_file('sha256', $chemin);
        $taille = (int) filesize($chemin);
        if ($taille === 0) {
            return;
        }
        if ($suivi?->getEmpreinte() === $empreinte) {
            $suivi->noterEnvoi($signature, $empreinte); // seulement touché
            $this->em->flush();

            return;
        }

        $entete = ['id' => $id, 'empreinte' => $empreinte, 'taille' => $taille];
        $recu = (int) ($appeler($route, $entete)['recu'] ?? 0);
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            ++$bilan['manquants'];

            return;
        }
        try {
            while ($recu < $taille && $bilan['octets'] < $budget) {
                fseek($flux, $recu);
                $morceau = (string) fread($flux, self::MORCEAU);
                $reponse = $appeler($route, $entete + ['position' => $recu, 'morceau' => base64_encode($morceau)]);
                $bilan['octets'] += \strlen($morceau);
                $suivant = (int) ($reponse['recu'] ?? 0);
                if ($suivant <= $recu && $suivant !== 0) {
                    throw new \RuntimeException('Le site n\'avance plus sur '.$libelle);
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
     * Un fichier que le site ne donne pas (perdu en ligne) ne bloque pas
     * les autres : il est noté dans « absents » et redemandé seulement
     * après REESSAI_ABSENT.
     *
     * @return array{recus: int, octets: int, absents: list<string>}
     */
    public function recuperer(callable $appeler, int $budget = self::BUDGET_CYCLE, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $bilan = ['recus' => 0, 'octets' => 0, 'absents' => []];
        $dossier = $this->projet.'/var/uploads/commandes';
        foreach ($this->aRecuperer() as $fichier) {
            if ($bilan['octets'] >= $budget) {
                break;
            }
            $cleAbsent = self::ETAT_ABSENT.$fichier->getPublicId()->toRfc4122();
            $absentLe = $this->synchro->dateEtat($cleAbsent);
            if ($absentLe !== null && $absentLe > $maintenant->modify('-'.self::REESSAI_ABSENT)) {
                continue;
            }
            $nom = basename(trim((string) $fichier->getNomStockage()));
            $empreinte = (string) $fichier->getEmpreinte();
            $taille = (int) $fichier->getTaille();
            try {
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
            } catch (TransportExceptionInterface $e) {
                throw $e; // liaison coupée : tout le cycle s'arrête
            } catch (\RuntimeException|\InvalidArgumentException) {
                if ($absentLe === null) {
                    $bilan['absents'][] = (string) $fichier->getNomOriginal();
                }
                $this->synchro->noterEtat($cleAbsent, $maintenant->format(\DATE_ATOM));
                continue;
            }
            if ($etat['termine']) {
                ++$bilan['recus'];
                if ($absentLe !== null) {
                    $this->synchro->noterEtat($cleAbsent, null);
                }
            }
        }

        return $bilan;
    }

    /* ---------- Notes vocales de la messagerie ---------- */

    /** @return list<MessageClient> notes vocales de l'atelier au client, à tenir à jour sur le site */
    public function vocalesAEnvoyer(): array
    {
        return $this->em->createQueryBuilder()
            ->select('m')->from(MessageClient::class, 'm')
            ->where('m.sens = :atelier')->setParameter('atelier', MessageClient::ATELIER)
            ->andWhere('m.vocaleNom IS NOT NULL')
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(500)
            ->getQuery()->getResult();
    }

    /**
     * Envoie au site les notes vocales que l'atelier a envoyées au client
     * (jamais les notes internes), dans la limite du budget du cycle.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler appel signé au site
     *
     * @return array{envoyes: int, octets: int, manquants: int}
     */
    public function envoyerVocales(callable $appeler, int $budget = self::BUDGET_CYCLE): array
    {
        $bilan = ['envoyes' => 0, 'octets' => 0, 'manquants' => 0];
        foreach ($this->vocalesAEnvoyer() as $m) {
            if ($bilan['octets'] >= $budget) {
                break;
            }
            $chemin = $this->notesVocales->chemin($m);
            if ($chemin === null) {
                ++$bilan['manquants'];
                continue;
            }
            $this->pousser($appeler, '/api/synchro/vocale', $m->getPublicId()->toRfc4122(), $chemin, 'la note vocale '.$m->getVocaleNom(), $budget, $bilan);
        }

        return $bilan;
    }

    /** @return list<MessageClient> notes vocales enregistrées par des clients en ligne, pas encore ici */
    public function vocalesARecuperer(int $nombre = 200): array
    {
        $candidats = $this->em->createQueryBuilder()
            ->select('m')->from(MessageClient::class, 'm')
            ->where('m.sens = :client')->setParameter('client', MessageClient::CLIENT)
            ->andWhere('m.vocaleNom IS NOT NULL')->andWhere('m.vocaleEmpreinte IS NOT NULL')
            ->andWhere('m.creeLe > :depuis')->setParameter('depuis', new \DateTimeImmutable('-60 days'))
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($nombre)
            ->getQuery()->getResult();

        return array_values(array_filter($candidats, fn (MessageClient $m): bool => preg_match(NotesVocales::NOM, (string) $m->getVocaleNom()) === 1 && $this->notesVocales->chemin($m) === null));
    }

    /**
     * Rapatrie du site les notes vocales des clients, revérifiées à
     * l'arrivée (type lu dans le contenu, taille, durée) : une note
     * refusée est retirée et notée comme absente, redemandée plus tard.
     *
     * @param callable(string, array<string, mixed>): array<string, mixed> $appeler appel signé au site
     *
     * @return array{recus: int, octets: int, absents: list<string>}
     */
    public function recupererVocales(callable $appeler, int $budget = self::BUDGET_CYCLE, ?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        $bilan = ['recus' => 0, 'octets' => 0, 'absents' => []];
        $dossier = $this->notesVocales->dossier();
        foreach ($this->vocalesARecuperer() as $m) {
            if ($bilan['octets'] >= $budget) {
                break;
            }
            $id = $m->getPublicId()->toRfc4122();
            $cleAbsent = self::ETAT_ABSENT.$id;
            $absentLe = $this->synchro->dateEtat($cleAbsent);
            if ($absentLe !== null && $absentLe > $maintenant->modify('-'.self::REESSAI_ABSENT)) {
                continue;
            }
            $nom = (string) $m->getVocaleNom();
            $taille = (int) $m->getVocaleTaille();
            try {
                if ($taille <= 0 || $taille > NotesVocales::TAILLE_MAX) {
                    throw new \InvalidArgumentException('Taille de note vocale refusée');
                }
                $etat = self::ecrire($dossier, $nom, (string) $m->getVocaleEmpreinte(), $taille, -1, null);
                while (!$etat['termine'] && $bilan['octets'] < $budget) {
                    $reponse = $appeler('/api/synchro/vocale-client', ['id' => $id, 'position' => $etat['recu']]);
                    $morceau = base64_decode((string) ($reponse['morceau'] ?? ''), true);
                    if ($morceau === false || $morceau === '') {
                        throw new \RuntimeException('Le site ne donne pas la note vocale '.$nom);
                    }
                    $etat = self::ecrire($dossier, $nom, (string) $m->getVocaleEmpreinte(), $taille, $etat['recu'], $morceau);
                    $bilan['octets'] += \strlen($morceau);
                }
                if ($etat['termine']) {
                    try {
                        NotesVocales::verifier((string) file_get_contents($dossier.'/'.$nom));
                    } catch (\DomainException $e) {
                        @unlink($dossier.'/'.$nom);
                        throw new \InvalidArgumentException('Note vocale refusée à l\'arrivée : '.$e->getMessage());
                    }
                }
            } catch (TransportExceptionInterface $e) {
                throw $e; // liaison coupée : tout le cycle s'arrête
            } catch (\RuntimeException|\InvalidArgumentException) {
                if ($absentLe === null) {
                    $bilan['absents'][] = 'note vocale de '.($m->getAuteur() ?? 'un client');
                }
                $this->synchro->noterEtat($cleAbsent, $maintenant->format(\DATE_ATOM));
                continue;
            }
            if ($etat['termine']) {
                ++$bilan['recus'];
                if ($absentLe !== null) {
                    $this->synchro->noterEtat($cleAbsent, null);
                }
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
    /**
     * Côté site : reçoit un morceau d'une note vocale de l'atelier (ou,
     * sans morceau, dit où reprendre). Le message doit être arrivé, venir
     * de l'atelier et annoncer cette empreinte.
     *
     * @param array<string, mixed> $donnees id, empreinte, taille, position?, morceau? (base64)
     *
     * @return array{recu: int, termine: bool}
     */
    public function recevoirVocale(array $donnees): array
    {
        $m = $this->serialiseur->trouver('message_client', (string) ($donnees['id'] ?? ''));
        if (!$m instanceof MessageClient || !$m->estDeLAtelier() || !$m->aUneNoteVocale()) {
            throw new \InvalidArgumentException('Message de la note vocale pas encore reçu');
        }
        $nom = (string) $m->getVocaleNom();
        $empreinte = strtolower((string) ($donnees['empreinte'] ?? ''));
        $taille = (int) ($donnees['taille'] ?? -1);
        if (!preg_match(NotesVocales::NOM, $nom) || $empreinte !== $m->getVocaleEmpreinte() || $taille !== $m->getVocaleTaille() || $taille > NotesVocales::TAILLE_MAX) {
            throw new \InvalidArgumentException('Note vocale mal décrite');
        }
        $morceau = isset($donnees['morceau']) ? base64_decode((string) $donnees['morceau'], true) : null;
        if ($morceau === false) {
            throw new \InvalidArgumentException('Morceau invalide');
        }

        return self::ecrire($this->notesVocales->dossier(), $nom, $empreinte, $taille, (int) ($donnees['position'] ?? -1), $morceau);
    }

    /**
     * Côté site : un morceau d'une note vocale enregistrée par un client.
     *
     * @param array<string, mixed> $donnees id, position
     *
     * @return array{morceau: string, taille: int}
     */
    public function lireVocale(array $donnees): array
    {
        $m = $this->serialiseur->trouver('message_client', (string) ($donnees['id'] ?? ''));
        if (!$m instanceof MessageClient || !$m->estDuClient() || !$m->aUneNoteVocale()) {
            throw new \InvalidArgumentException('Note vocale inconnue');
        }
        $chemin = $this->notesVocales->chemin($m);
        $position = (int) ($donnees['position'] ?? -1);
        if ($chemin === null || $position < 0 || $position >= (int) filesize($chemin)) {
            throw new \InvalidArgumentException('Note vocale absente ou position invalide');
        }
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new \InvalidArgumentException('Note vocale illisible');
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
