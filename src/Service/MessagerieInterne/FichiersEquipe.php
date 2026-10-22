<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Clients;
use App\Entity\PieceJointeMessage;
use App\Entity\User;
use App\Security\FichierEnvoye;
use App\Service\Atelier\ImagesAtelier;
use App\Service\EspaceClient\FichiersClient;
use App\Service\FichiersPrives;
use App\Service\PiecesJointesChat;
use App\Service\ProduitsClient\DossierClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers de la messagerie de l'équipe (docs/messagerie-interne.md, MI03).
 *
 * Envoi : plusieurs fichiers à la fois, chacun par morceaux de 4 Mo
 * envoyés bruts dans le corps de la requête (comme l'espace client : la
 * limite upload_max_filesize de PHP ne joue pas)
 * (reprise après une coupure : les morceaux déjà reçus ne sont pas
 * renvoyés), avec sa propre progression. Taille maximale réglable (50 Mo
 * par défaut). Le type est lu dans le contenu, dans une liste fermée ; une
 * photo est réencodée (sans EXIF ni position GPS) avec une vignette. Les
 * fichiers vont sous var/uploads/chat, jamais sous public/.
 *
 * Lien : un fichier déjà sur le serveur de l'atelier (dossier du client,
 * DossierClient) part comme lien, sans copie. Le chemin est contrôlé à
 * l'envoi et à chaque ouverture : jamais en dehors du dossier du client.
 */
class FichiersEquipe
{
    public const MORCEAU = 4 * 1024 * 1024;

    private const TEMPORAIRE = 'var/uploads/chat_tmp';

    /** Types lus dans le contenu => extension enregistrée : ceux du chat, plus les fichiers d'impression. */
    public const TYPES = FichierEnvoye::TYPES_CHAT + [
        'image/tiff' => 'tif',
        'image/vnd.adobe.photoshop' => 'psd',
        'application/x-photoshop' => 'psd',
        'application/postscript' => 'eps',
        'application/illustrator' => 'ai',
    ];

    public const REFUS = 'Fichier refusé : photos, PDF, PSD, TIFF, EPS, documents Word, Excel, PowerPoint, texte ou ZIP.';

    /** Profondeur des sous-dossiers du client parcourus (« 02 Fichiers reçus/maquettes/x.psd »). */
    private const PROFONDEUR = 3;

    private const LISTE_MAX = 400;

    public function __construct(
        private readonly FichiersPrives $fichiers,
        private readonly DossierClient $dossiers,
        private readonly ReglesMessagerieInterne $regles,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    // ------------------------------------------------------------------
    // Envoi par morceaux
    // ------------------------------------------------------------------

    /**
     * Ouvre l'envoi d'un fichier : rend son jeton.
     *
     * @throws \DomainException
     */
    public function debut(User $moi, string $nom, int $taille): array
    {
        $nom = FichiersClient::nomPropre($nom);
        $max = $this->regles->tailleMax();
        if ($taille <= 0) {
            throw new \DomainException('Fichier vide.');
        }
        if ($taille > $max) {
            throw new \DomainException(\sprintf('« %s » dépasse %d Mo : partagez-le en lien depuis le dossier du client, sans copie.', $nom, intdiv($max, 1048576)));
        }
        $jeton = bin2hex(random_bytes(20));
        $dossier = $this->dossierEnvoi($jeton, true);
        $meta = ['utilisateur' => $moi->getId(), 'nom' => $nom, 'taille' => $taille, 'morceaux' => (int) ceil($taille / self::MORCEAU), 'le' => time()];
        file_put_contents($dossier.'/meta.json', json_encode($meta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
        $this->nettoyer();

        return ['jeton' => $jeton, 'morceau' => self::MORCEAU, 'morceaux' => $meta['morceaux'], 'recus' => []];
    }

    /**
     * Reçoit un morceau ; un morceau déjà reçu n'est pas réécrit (reprise).
     *
     * @return array{recus: list<int>, morceaux: int, termine: bool}
     *
     * @throws \DomainException
     */
    public function morceau(User $moi, string $jeton, int $index, string $morceau): array
    {
        $meta = $this->meta($moi, $jeton);
        $dossier = $this->dossierEnvoi($jeton);
        if ($index < 0 || $index >= $meta['morceaux']) {
            throw new \DomainException('Morceau inattendu.');
        }
        $attendu = $index === $meta['morceaux'] - 1 ? $meta['taille'] - $index * self::MORCEAU : self::MORCEAU;
        if (\strlen($morceau) !== $attendu) {
            throw new \DomainException('Le morceau n\'est pas arrivé en entier : l\'envoi reprend.');
        }
        $chemin = \sprintf('%s/%06d.part', $dossier, $index);
        if (!is_file($chemin) && false === file_put_contents($chemin.'.tmp', $morceau, \LOCK_EX)) {
            throw new \DomainException('Le morceau n\'a pas pu être enregistré : l\'envoi reprend.');
        }
        if (!is_file($chemin)) {
            rename($chemin.'.tmp', $chemin);
        }
        $recus = $this->recus($jeton);

        return ['recus' => $recus, 'morceaux' => $meta['morceaux'], 'termine' => \count($recus) === $meta['morceaux']];
    }

    /** @return array{recus: list<int>, morceaux: int, termine: bool, nom: string, taille: int} */
    public function etat(User $moi, string $jeton): array
    {
        $meta = $this->meta($moi, $jeton);
        $recus = $this->recus($jeton);

        return ['recus' => $recus, 'morceaux' => $meta['morceaux'], 'termine' => \count($recus) === $meta['morceaux'], 'nom' => $meta['nom'], 'taille' => $meta['taille']];
    }

    /**
     * Assemble l'envoi terminé, contrôle son type et le range : la pièce
     * jointe à rattacher au message (pas encore persistée).
     *
     * @throws \DomainException
     */
    public function rangerEnvoi(User $moi, string $jeton): PieceJointeMessage
    {
        $meta = $this->meta($moi, $jeton);
        $dossier = $this->dossierEnvoi($jeton);
        if (\count($this->recus($jeton)) !== $meta['morceaux']) {
            throw new \DomainException(\sprintf('« %s » n\'est pas encore entièrement envoyé.', $meta['nom']));
        }
        $assemble = $dossier.'/fichier';
        $sortie = fopen($assemble, 'wb');
        if (false === $sortie) {
            throw new \RuntimeException('Assemblage impossible.');
        }
        for ($i = 0; $i < $meta['morceaux']; ++$i) {
            $entree = fopen(\sprintf('%s/%06d.part', $dossier, $i), 'rb');
            if (false === $entree) {
                fclose($sortie);
                throw new \DomainException('Un morceau manque : renvoyez le fichier.');
            }
            stream_copy_to_stream($entree, $sortie);
            fclose($entree);
        }
        fclose($sortie);
        if (filesize($assemble) !== $meta['taille']) {
            $this->oublier($jeton);
            throw new \DomainException(\sprintf('« %s » est arrivé incomplet : renvoyez-le.', $meta['nom']));
        }
        try {
            return $this->rangerFichier($assemble, $meta['nom']);
        } finally {
            // Les morceaux restent jusqu'à l'enregistrement du message (terminer) : un envoi refusé se renvoie sans tout recharger.
            @unlink($assemble);
        }
    }

    /**
     * Le message est enregistré : les envois temporaires partent.
     *
     * @param list<string> $jetons
     */
    public function terminer(User $moi, array $jetons): void
    {
        foreach ($jetons as $jeton) {
            try {
                $this->meta($moi, $jeton);
                $this->oublier($jeton);
            } catch (\DomainException) {
            }
        }
    }

    /** Le message n'a pas été enregistré : retire le fichier rangé (pas un lien, jamais le dossier du client). */
    public function retirer(PieceJointeMessage $piece): void
    {
        if ($piece->estLien()) {
            return;
        }
        foreach ([$piece->getStockage(), $piece->getMiniature()] as $nom) {
            $chemin = $this->fichiers->cheminDans(PiecesJointesChat::DOSSIER, $nom);
            if (null !== $chemin) {
                @unlink($chemin);
            }
        }
    }

    /**
     * Range un fichier du disque (envoi assemblé, photo de démonstration) :
     * type lu dans le contenu, photo réencodée avec sa vignette.
     *
     * @throws \DomainException
     */
    public function rangerFichier(string $source, string $nomOriginal): PieceJointeMessage
    {
        $type = strtolower(trim(explode(';', (string) @mime_content_type($source))[0]));
        $extension = self::TYPES[$type] ?? null;
        $nomOriginal = FichiersClient::nomPropre($nomOriginal);
        // Un fichier Illustrator est souvent un PDF : il garde son nom « .ai ».
        if ('pdf' === $extension && 'ai' === strtolower(pathinfo($nomOriginal, \PATHINFO_EXTENSION))) {
            $extension = 'ai';
        }
        if (null === $extension) {
            throw new \DomainException(\sprintf('« %s » : %s', $nomOriginal, self::REFUS));
        }
        $dossier = $this->fichiers->dossier(PiecesJointesChat::DOSSIER);
        $base = 'chat_'.bin2hex(random_bytes(12));
        $piece = (new PieceJointeMessage())->setNom($nomOriginal);

        if (\in_array($extension, ['jpg', 'png', 'webp'], true)) {
            $image = ImagesAtelier::reencoder((string) file_get_contents($source));
            $nom = $base.'.'.$image['extension'];
            if (false === file_put_contents($dossier.'/'.$nom, $image['octets'], \LOCK_EX)) {
                throw new \DomainException('La photo n\'a pas pu être enregistrée : réessayez.');
            }
            $attendues = 'jpg' === $image['extension'] ? ['jpg', 'jpeg'] : [$image['extension']];
            if (!\in_array(strtolower(pathinfo($nomOriginal, \PATHINFO_EXTENSION)), $attendues, true)) {
                $piece->setNom(pathinfo($nomOriginal, \PATHINFO_FILENAME).'.'.$image['extension']);
            }

            return $piece->setGenre(PieceJointeMessage::PHOTO)->setStockage($nom)->setExtension($image['extension'])
                ->setOctets(\strlen($image['octets']))->setMiniature($this->miniature($image['octets'], $dossier, $base));
        }

        $nom = $base.'.'.$extension;
        if (!@copy($source, $dossier.'/'.$nom)) {
            throw new \DomainException('Le fichier n\'a pas pu être enregistré : réessayez.');
        }
        @chmod($dossier.'/'.$nom, 0640);

        return $piece->setGenre('gif' === $extension ? PieceJointeMessage::PHOTO : PieceJointeMessage::FICHIER)
            ->setStockage($nom)->setExtension($extension)->setOctets((int) filesize($dossier.'/'.$nom));
    }

    /** Fichier rangé d'une pièce (ou sa vignette), ou null. */
    public function chemin(PieceJointeMessage $piece, bool $miniature = false): ?string
    {
        if ($piece->estLien()) {
            return $miniature ? null : $this->cheminLien($piece);
        }

        return $this->fichiers->cheminDans(PiecesJointesChat::DOSSIER, $miniature ? ($piece->getMiniature() ?? $piece->getStockage()) : $piece->getStockage());
    }

    /** Retire les fichiers d'envois commencés il y a plus d'un jour et jamais rattachés à un message. */
    public function nettoyer(): void
    {
        $racine = $this->projet.'/'.self::TEMPORAIRE;
        foreach (glob($racine.'/*', \GLOB_ONLYDIR) ?: [] as $dossier) {
            if (filemtime($dossier) < time() - 86400 && preg_match('/^[0-9a-f]{40}$/', basename($dossier))) {
                $this->oublier(basename($dossier));
            }
        }
    }

    // ------------------------------------------------------------------
    // Fichiers déjà sur le serveur (dossier du client)
    // ------------------------------------------------------------------

    public function dossiersDisponibles(): bool
    {
        $racine = $this->dossiers->racine();

        return '' !== $racine && is_dir($racine);
    }

    /**
     * Fichiers du dossier du client (et de ses sous-dossiers), les plus
     * récents d'abord : chemin relatif, nom, taille, date.
     *
     * @return list<array{chemin: string, nom: string, dossier: string, octets: int, modifie: \DateTimeImmutable, extension: string}>
     */
    public function listeDossier(Clients $client): array
    {
        $base = $this->dossiers->dossierDuClient($client);
        if (null === $base) {
            return [];
        }
        $liste = [];
        $parcourir = function (string $dossier, string $relatif, int $niveau) use (&$parcourir, &$liste): void {
            foreach (@scandir($dossier) ?: [] as $nom) {
                if ('.' === $nom[0] || \count($liste) >= self::LISTE_MAX) {
                    continue;
                }
                $chemin = $dossier.\DIRECTORY_SEPARATOR.$nom;
                if (is_link($chemin)) {
                    continue;
                }
                if (is_dir($chemin)) {
                    if ($niveau < self::PROFONDEUR) {
                        $parcourir($chemin, '' === $relatif ? $nom : $relatif.'/'.$nom, $niveau + 1);
                    }
                    continue;
                }
                $liste[] = [
                    'chemin' => '' === $relatif ? $nom : $relatif.'/'.$nom,
                    'nom' => $nom,
                    'dossier' => $relatif,
                    'octets' => (int) @filesize($chemin),
                    'modifie' => (new \DateTimeImmutable())->setTimestamp((int) @filemtime($chemin)),
                    'extension' => strtolower(pathinfo($nom, \PATHINFO_EXTENSION)),
                ];
            }
        };
        $parcourir($base, '', 1);
        usort($liste, static fn (array $a, array $b): int => $b['modifie'] <=> $a['modifie']);

        return $liste;
    }

    /**
     * Pièce « lien » vers un fichier du dossier du client, sans copie.
     *
     * @throws \DomainException chemin refusé (hors du dossier du client, introuvable)
     */
    public function lienServeur(Clients $client, string $relatif): PieceJointeMessage
    {
        $chemin = $this->resoudre($client, $relatif) ?? throw new \DomainException('Fichier introuvable dans le dossier du client (ou en dehors de ce dossier).');
        $relatif = self::normaliser($relatif) ?? '';

        return (new PieceJointeMessage())
            ->setGenre(PieceJointeMessage::LIEN)
            ->setClient($client)
            ->setCheminServeur($relatif)
            ->setNom(basename($relatif))
            ->setExtension(FichierEnvoye::extensionPublique(pathinfo($relatif, \PATHINFO_EXTENSION)))
            ->setOctets((int) filesize($chemin))
            ->setModifieLe((new \DateTimeImmutable())->setTimestamp((int) filemtime($chemin)));
    }

    /** Le fichier d'un lien sur le disque, après contrôle (jamais hors du dossier du client), ou null. */
    public function cheminLien(PieceJointeMessage $piece): ?string
    {
        $client = $piece->getClient();

        return null === $client ? null : $this->resoudre($client, (string) $piece->getCheminServeur());
    }

    /** Le fichier a changé depuis l'envoi du lien (date différente). */
    public function lienModifie(PieceJointeMessage $piece, string $chemin): bool
    {
        return null !== $piece->getModifieLe() && abs((int) filemtime($chemin) - $piece->getModifieLe()->getTimestamp()) > 1;
    }

    /** Chemin complet d'un fichier du dossier du client, ou null (hors dossier, « .. », lien symbolique, absent). */
    public function resoudre(Clients $client, string $relatif): ?string
    {
        $relatif = self::normaliser($relatif);
        $base = $this->dossiers->dossierDuClient($client);
        if (null === $relatif || null === $base) {
            return null;
        }
        $baseReelle = realpath($base);
        $reel = realpath($base.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relatif));
        if (false === $baseReelle || false === $reel || !is_file($reel) || !str_starts_with($reel, $baseReelle.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $reel;
    }

    /** « 02 Fichiers reçus/bache.psd » propre, ou null (absolu, « .. », vide, trop profond). */
    public static function normaliser(string $relatif): ?string
    {
        $relatif = str_replace('\\', '/', trim($relatif));
        if ('' === $relatif || str_contains($relatif, "\0") || str_starts_with($relatif, '/') || preg_match('#^[A-Za-z]:#', $relatif)) {
            return null;
        }
        $morceaux = explode('/', $relatif);
        if (\count($morceaux) > self::PROFONDEUR) {
            return null;
        }
        foreach ($morceaux as $m) {
            if ('' === $m || '.' === $m || '..' === $m || str_starts_with($m, '.')) {
                return null;
            }
        }

        return implode('/', $morceaux);
    }

    // ------------------------------------------------------------------

    private function dossierEnvoi(string $jeton, bool $creer = false): string
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $jeton)) {
            throw new \DomainException('Envoi introuvable.');
        }
        $dossier = $this->projet.'/'.self::TEMPORAIRE.'/'.$jeton;
        if ($creer && !is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier d\'envoi impossible à créer.');
        }
        if (!is_dir($dossier)) {
            throw new \DomainException('Envoi introuvable ou expiré : renvoyez le fichier.');
        }

        return $dossier;
    }

    /** @return array{utilisateur: int, nom: string, taille: int, morceaux: int, le: int} */
    private function meta(User $moi, string $jeton): array
    {
        $dossier = $this->dossierEnvoi($jeton);
        $meta = json_decode((string) @file_get_contents($dossier.'/meta.json'), true);
        // L'envoi d'une autre personne : introuvable.
        if (!\is_array($meta) || (int) ($meta['utilisateur'] ?? 0) !== $moi->getId()) {
            throw new \DomainException('Envoi introuvable.');
        }

        return ['utilisateur' => (int) $meta['utilisateur'], 'nom' => (string) $meta['nom'], 'taille' => (int) $meta['taille'], 'morceaux' => (int) $meta['morceaux'], 'le' => (int) $meta['le']];
    }

    /** @return list<int> */
    private function recus(string $jeton): array
    {
        $recus = [];
        foreach (glob($this->dossierEnvoi($jeton).'/*.part') ?: [] as $f) {
            $recus[] = (int) basename($f, '.part');
        }
        sort($recus);

        return $recus;
    }

    private function oublier(string $jeton): void
    {
        $dossier = $this->projet.'/'.self::TEMPORAIRE.'/'.$jeton;
        foreach (glob($dossier.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
    }

    private function miniature(string $octets, string $dossier, string $base): ?string
    {
        $source = @imagecreatefromstring($octets);
        if (false === $source || !\function_exists('imagejpeg')) {
            return null;
        }
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $cote = 480;
        if ($largeur > $cote || $hauteur > $cote) {
            $ratio = min($cote / $largeur, $cote / $hauteur);
            $source = imagescale($source, max(1, (int) round($largeur * $ratio)), max(1, (int) round($hauteur * $ratio))) ?: $source;
        }
        $nom = $base.'_min.jpg';
        // Fond blanc sous une image transparente.
        $fond = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($fond, 0, 0, (int) imagecolorallocate($fond, 255, 255, 255));
        imagecopy($fond, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        return imagejpeg($fond, $dossier.'/'.$nom, 80) ? $nom : null;
    }
}
