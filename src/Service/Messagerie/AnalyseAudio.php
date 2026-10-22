<?php

namespace App\Service\Messagerie;

/**
 * Lit le contenu d'une note vocale (docs/messagerie-clients.md, « Notes
 * vocales ») : son type, d'après les octets et jamais d'après ce que le
 * navigateur annonce, et sa durée.
 *
 * Seuls les formats de l'enregistreur des navigateurs (MediaRecorder) sont
 * reçus : WebM (Chrome, Edge, Android), Ogg (Firefox) et MP4 audio
 * (Safari, iPhone). Un fichier avec une piste vidéo est refusé. La durée
 * se lit dans le fichier : dernière position d'une page Ogg, durée
 * annoncée ou derniers blocs d'un WebM (Chrome n'écrit pas la durée),
 * en-tête d'un MP4 ou somme de ses fragments (Safari).
 */
final class AnalyseAudio
{
    /** Type lu dans le contenu => extension enregistrée. */
    public const TYPES = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
    ];

    /**
     * @return array{type: string, extension: string, duree: ?float} durée en secondes, null si illisible
     *
     * @throws \DomainException format refusé
     */
    public static function analyser(string $contenu): array
    {
        if (\strlen($contenu) < 64) {
            throw new \DomainException('Note vocale vide ou incomplète.');
        }
        if (str_starts_with($contenu, 'OggS')) {
            return ['type' => 'audio/ogg', 'extension' => 'ogg', 'duree' => self::dureeOgg($contenu)];
        }
        if (str_starts_with($contenu, "\x1A\x45\xDF\xA3")) {
            return ['type' => 'audio/webm', 'extension' => 'webm', 'duree' => self::dureeWebm($contenu)];
        }
        if ('ftyp' === substr($contenu, 4, 4)) {
            return ['type' => 'audio/mp4', 'extension' => 'm4a', 'duree' => self::dureeMp4($contenu)];
        }

        throw new \DomainException('Ce fichier n\'est pas une note vocale (formats reçus : WebM, Ogg ou MP4 audio).');
    }

    // ------------------------------------------------------------------
    // Ogg (Opus ou Vorbis)
    // ------------------------------------------------------------------

    private static function dureeOgg(string $c): ?float
    {
        $taux = null;
        $preSkip = 0;
        if (false !== ($p = strpos($c, 'OpusHead')) && $p < 512) {
            $taux = 48000;
            $preSkip = unpack('v', substr($c, $p + 10, 2))[1];
        } elseif (false !== ($p = strpos($c, "\x01vorbis")) && $p < 512) {
            $taux = unpack('V', substr($c, $p + 12, 4))[1];
        } elseif (false !== strpos(substr($c, 0, 512), 'theora')) {
            throw new \DomainException('Une note vocale ne contient que du son (vidéo refusée).');
        }
        if (null === $taux || $taux <= 0) {
            throw new \DomainException('Note vocale Ogg illisible (Opus ou Vorbis attendu).');
        }
        // Dernière page : sa position (en échantillons) donne la durée.
        $p = strrpos($c, 'OggS');
        while (false !== $p) {
            if (\strlen($c) >= $p + 14) {
                $granule = unpack('P', substr($c, $p + 6, 8))[1];
                if ($granule > 0) {
                    return max(0.0, ($granule - $preSkip) / $taux);
                }
            }
            $p = $p > 0 ? strrpos(substr($c, 0, $p), 'OggS') : false;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // WebM (Matroska)
    // ------------------------------------------------------------------

    private const EBML_SEGMENT = 0x18538067;
    private const EBML_INFO = 0x1549A966;
    private const EBML_ECHELLE = 0x2AD7B1;
    private const EBML_DUREE = 0x4489;
    private const EBML_DOCTYPE = 0x4282;
    private const EBML_PISTES = 0x1654AE6B;
    private const EBML_PISTE = 0xAE;
    private const EBML_TYPE_PISTE = 0x83;
    private const EBML_GRAPPE = 0x1F43B675;
    private const EBML_TEMPS_GRAPPE = 0xE7;
    private const EBML_BLOC_SIMPLE = 0xA3;
    private const EBML_GROUPE = 0xA0;
    private const EBML_BLOC = 0xA1;
    private const EBML_ENTETE = 0x1A45DFA3;

    /** Éléments dont on lit l'intérieur (les autres sont sautés). */
    private const EBML_CONTENANTS = [self::EBML_ENTETE, self::EBML_SEGMENT, self::EBML_INFO, self::EBML_PISTES, self::EBML_PISTE, self::EBML_GRAPPE, self::EBML_GROUPE];

    private static function dureeWebm(string $c): ?float
    {
        $n = \strlen($c);
        $p = 0;
        $docType = null;
        $echelle = 1000000; // nanosecondes par unité (1 ms par défaut)
        $dureeAnnoncee = null;
        $grappe = 0;
        $dernier = null;
        $audio = false;
        $video = false;
        while ($p < $n) {
            $id = self::vint($c, $p, true);
            if (null === $id) {
                break;
            }
            $taille = self::vint($c, $id[1], false);
            if (null === $taille) {
                break;
            }
            [$debut, $longueur] = [$taille[1], $taille[0]];
            $inconnue = -1 === $longueur;
            if (\in_array($id[0], self::EBML_CONTENANTS, true)) {
                $p = $debut;
                continue;
            }
            if ($inconnue || $debut + $longueur > $n) {
                // Fin coupée (enregistrement interrompu) : on garde ce qui est lu.
                if ($inconnue) {
                    break;
                }
                $longueur = $n - $debut;
            }
            $valeur = substr($c, $debut, $longueur);
            match ($id[0]) {
                self::EBML_DOCTYPE => $docType = $valeur,
                self::EBML_ECHELLE => $echelle = self::entier($valeur) ?: 1000000,
                self::EBML_DUREE => $dureeAnnoncee = self::flottant($valeur),
                self::EBML_TYPE_PISTE => (1 === self::entier($valeur) ? $video = true : (2 === self::entier($valeur) ? $audio = true : null)),
                self::EBML_TEMPS_GRAPPE => $grappe = self::entier($valeur),
                self::EBML_BLOC_SIMPLE, self::EBML_BLOC => $dernier = max($dernier ?? 0, $grappe + self::tempsBloc($valeur)),
                default => null,
            };
            $p = $debut + $longueur;
        }
        if ('webm' !== $docType) {
            throw new \DomainException('Note vocale WebM illisible.');
        }
        if ($video || !$audio) {
            throw new \DomainException('Une note vocale ne contient que du son (vidéo refusée).');
        }
        $unites = max((float) ($dureeAnnoncee ?? 0), (float) ($dernier ?? 0));

        return $unites > 0 ? $unites * $echelle / 1e9 : null;
    }

    /**
     * Entier de taille variable d'EBML : [valeur, position suivante], null
     * en fin de fichier. Un identifiant garde son premier bit ; une taille
     * dont tous les bits valent 1 est « inconnue » (-1).
     *
     * @return array{0: int, 1: int}|null
     */
    private static function vint(string $c, int $p, bool $identifiant): ?array
    {
        if ($p >= \strlen($c)) {
            return null;
        }
        $premier = \ord($c[$p]);
        $longueur = 1;
        $masque = 0x80;
        while ($longueur <= 8 && 0 === ($premier & $masque)) {
            ++$longueur;
            $masque >>= 1;
        }
        if ($longueur > ($identifiant ? 4 : 8) || $p + $longueur > \strlen($c)) {
            return null;
        }
        $valeur = $identifiant ? $premier : ($premier & ($masque - 1));
        $uns = !$identifiant && ($valeur === $masque - 1);
        for ($i = 1; $i < $longueur; ++$i) {
            $octet = \ord($c[$p + $i]);
            $uns = $uns && 0xFF === $octet;
            $valeur = ($valeur << 8) | $octet;
        }

        return [$uns ? -1 : $valeur, $p + $longueur];
    }

    private static function entier(string $octets): int
    {
        $v = 0;
        foreach (str_split(substr($octets, 0, 8)) as $o) {
            $v = ($v << 8) | \ord($o);
        }

        return $v;
    }

    private static function flottant(string $octets): ?float
    {
        return match (\strlen($octets)) {
            4 => unpack('G', $octets)[1],
            8 => unpack('E', $octets)[1],
            default => null,
        };
    }

    /** Temps d'un bloc, relatif à sa grappe (après le numéro de piste). */
    private static function tempsBloc(string $bloc): int
    {
        $piste = self::vint($bloc, 0, false);
        if (null === $piste || \strlen($bloc) < $piste[1] + 2) {
            return 0;
        }

        return unpack('n', substr($bloc, $piste[1], 2))[1] << 48 >> 48; // entier signé de 16 bits
    }

    // ------------------------------------------------------------------
    // MP4 (Safari)
    // ------------------------------------------------------------------

    private const MP4_CONTENANTS = ['moov', 'trak', 'mdia', 'mvex', 'moof', 'traf', 'minf', 'stbl'];

    private static function dureeMp4(string $c): ?float
    {
        $etat = ['mvhd' => null, 'mehd' => null, 'echelleFilm' => null, 'echellePiste' => null, 'fragments' => 0, 'son' => false, 'video' => false, 'defaut' => 0];
        self::boites($c, 0, \strlen($c), $etat);
        if ($etat['video'] || !$etat['son']) {
            throw new \DomainException('Une note vocale ne contient que du son (vidéo refusée).');
        }
        if (null !== $etat['mvhd'] && $etat['mvhd'] > 0 && $etat['echelleFilm']) {
            return $etat['mvhd'] / $etat['echelleFilm'];
        }
        if (null !== $etat['mehd'] && $etat['mehd'] > 0 && $etat['echelleFilm']) {
            return $etat['mehd'] / $etat['echelleFilm'];
        }
        if ($etat['fragments'] > 0 && $etat['echellePiste']) {
            return $etat['fragments'] / $etat['echellePiste'];
        }

        return null;
    }

    /** @param array<string, mixed> $etat */
    private static function boites(string $c, int $debut, int $fin, array &$etat): void
    {
        $p = $debut;
        while ($p + 8 <= $fin) {
            $taille = unpack('N', substr($c, $p, 4))[1];
            $type = substr($c, $p + 4, 4);
            $entete = 8;
            if (1 === $taille && $p + 16 <= $fin) {
                $taille = unpack('J', substr($c, $p + 8, 8))[1];
                $entete = 16;
            } elseif (0 === $taille) {
                $taille = $fin - $p;
            }
            if ($taille < $entete || $p + $taille > $fin) {
                $taille = $fin - $p; // boîte coupée : on lit ce qu'il y a
                if ($taille < $entete) {
                    return;
                }
            }
            $corps = $p + $entete;
            $longueur = $taille - $entete;
            if (\in_array($type, self::MP4_CONTENANTS, true)) {
                self::boites($c, $corps, $corps + $longueur, $etat);
            } else {
                self::lireBoite($type, substr($c, $corps, $longueur), $etat);
            }
            $p += $taille;
        }
    }

    /** @param array<string, mixed> $etat */
    private static function lireBoite(string $type, string $b, array &$etat): void
    {
        $version = \strlen($b) > 0 ? \ord($b[0]) : 0;
        switch ($type) {
            case 'mvhd':
                if (1 === $version && \strlen($b) >= 32) {
                    $etat['echelleFilm'] = unpack('N', substr($b, 20, 4))[1];
                    $etat['mvhd'] = unpack('J', substr($b, 24, 8))[1];
                } elseif (\strlen($b) >= 20) {
                    $etat['echelleFilm'] = unpack('N', substr($b, 12, 4))[1];
                    $etat['mvhd'] = unpack('N', substr($b, 16, 4))[1];
                }
                break;
            case 'mehd':
                if (\strlen($b) >= 8) {
                    $etat['mehd'] = 1 === $version && \strlen($b) >= 12 ? unpack('J', substr($b, 4, 8))[1] : unpack('N', substr($b, 4, 4))[1];
                }
                break;
            case 'mdhd':
                if (null === $etat['echellePiste']) {
                    $etat['echellePiste'] = 1 === $version && \strlen($b) >= 24 ? unpack('N', substr($b, 20, 4))[1] : (\strlen($b) >= 16 ? unpack('N', substr($b, 12, 4))[1] : null);
                }
                break;
            case 'hdlr':
                $genre = substr($b, 8, 4);
                if ('soun' === $genre) {
                    $etat['son'] = true;
                } elseif ('vide' === $genre) {
                    $etat['video'] = true;
                }
                break;
            case 'tfhd':
                $drapeaux = \strlen($b) >= 4 ? unpack('N', $b)[1] & 0xFFFFFF : 0;
                $p = 8 + (($drapeaux & 0x01) ? 8 : 0) + (($drapeaux & 0x02) ? 4 : 0);
                $etat['defaut'] = ($drapeaux & 0x08) && \strlen($b) >= $p + 4 ? unpack('N', substr($b, $p, 4))[1] : 0;
                break;
            case 'trun':
                if (\strlen($b) < 8) {
                    break;
                }
                $drapeaux = unpack('N', $b)[1] & 0xFFFFFF;
                $nombre = unpack('N', substr($b, 4, 4))[1];
                $p = 8 + (($drapeaux & 0x01) ? 4 : 0) + (($drapeaux & 0x04) ? 4 : 0);
                $pas = (($drapeaux & 0x100) ? 4 : 0) + (($drapeaux & 0x200) ? 4 : 0) + (($drapeaux & 0x400) ? 4 : 0) + (($drapeaux & 0x800) ? 4 : 0);
                if (!($drapeaux & 0x100)) {
                    $etat['fragments'] += $nombre * $etat['defaut'];
                    break;
                }
                for ($i = 0; $i < $nombre && $p + 4 <= \strlen($b); ++$i, $p += $pas) {
                    $etat['fragments'] += unpack('N', substr($b, $p, 4))[1];
                }
                break;
        }
    }
}
