<?php

namespace App\Service;

/**
 * Programme de l'écran TV : scènes de la zone principale, zones fixes,
 * avis validés, messages du bandeau, horaires d'ouverture et alertes
 * sonores (AlertesEcranTv).
 *
 * Fonctions pures : le programme est un tableau, lu et écrit par
 * EcranTv. Chaque entrée de l'admin passe par normaliser(), l'écran
 * ne reçoit donc jamais une valeur hors des bornes.
 */
final class ProgrammeEcranTv
{
    public const TYPE_CHIFFRES = 'chiffres';
    public const TYPE_PHOTOS = 'photos';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AVIS = 'avis';
    public const TYPE_PROMO = 'promo';
    public const TYPE_TEXTE = 'texte';
    public const TYPE_PARTENAIRES = 'partenaires';

    public const TYPES = [
        self::TYPE_CHIFFRES => 'Chiffres',
        self::TYPE_PHOTOS => 'Photos',
        self::TYPE_VIDEO => 'Vidéo',
        self::TYPE_AVIS => 'Avis',
        self::TYPE_PROMO => 'Promo',
        self::TYPE_TEXTE => 'Texte',
        self::TYPE_PARTENAIRES => 'Partenaires',
    ];

    public const FONDS = [
        'bleu' => 'Bleu',
        'rouge' => 'Rouge',
        'jaune' => 'Jaune',
        'sombre' => 'Sombre',
    ];

    public const HORS_HORAIRES_FERME = 'ferme';
    public const HORS_HORAIRES_ETEINT = 'eteint';

    public const DUREE_MIN = 4;
    public const DUREE_MAX = 120;

    private const JOURS_COURTS = [1 => 'Lun.', 2 => 'Mar.', 3 => 'Mer.', 4 => 'Jeu.', 5 => 'Ven.', 6 => 'Sam.', 7 => 'Dim.'];
    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    private const DUREES_PAR_DEFAUT = [
        self::TYPE_CHIFFRES => 10,
        self::TYPE_PHOTOS => 12,
        self::TYPE_VIDEO => 24,
        self::TYPE_AVIS => 9,
        self::TYPE_PROMO => 9,
        self::TYPE_TEXTE => 6,
        self::TYPE_PARTENAIRES => 10,
    ];

    private const MESSAGES_PAR_DEFAUT = [
        '[NOUVEAU] Textile DTF dès 1 pièce',
        'Payez par [Orange Money]',
        'Commandez sur [succesimprim.com]',
    ];

    /**
     * Programme de départ : les scènes de la démo de Yaya, à compléter.
     *
     * @return array<string, mixed>
     */
    public static function defaut(): array
    {
        return self::normaliser([
            'scenes' => [
                ['id' => 'chiffres', 'type' => self::TYPE_CHIFFRES, 'titre' => 'Merci pour votre confiance', 'surtitre' => 'Notre croissance'],
                ['id' => 'photos', 'type' => self::TYPE_PHOTOS, 'titre' => 'Dernières réalisations'],
                ['id' => 'video', 'type' => self::TYPE_VIDEO, 'titre' => 'Impression grand format jusqu\'à 3,20 m', 'surtitre' => 'Dans notre atelier', 'heureDebut' => '08:00', 'heureFin' => '19:00'],
                ['id' => 'avis', 'type' => self::TYPE_AVIS, 'titre' => 'Avis clients', 'surtitre' => 'Ils parlent de nous'],
                ['id' => 'partenaires', 'type' => self::TYPE_PARTENAIRES, 'titre' => 'Ils nous font confiance', 'surtitre' => 'Nos partenaires'],
                ['id' => 'promo', 'type' => self::TYPE_PROMO, 'titre' => 'T-shirts [DTF] dès 1 pièce', 'surtitre' => 'Nouveau', 'texte' => 'Votre logo en couleurs, sur coton ou polyester.', 'pastille' => '−10 % dès 25 pièces', 'actif' => false],
            ],
            'horaires' => [
                1 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
                2 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
                3 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
                4 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
                5 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
                6 => ['ouverture' => '08:00', 'fermeture' => '19:00'],
            ],
        ]);
    }

    /**
     * @param array<mixed> $brut
     *
     * @return array{scenes: list<array<string, mixed>>, zones: array{colonneLive: bool, bandeau: bool, horlogeLogo: bool, son: bool}, avis: list<array{texte: string, note: int, signature: string}>, messages: list<string>, noteGoogle: ?float, avisGoogle: ?int, horaires: array<int, ?array{ouverture: string, fermeture: string}>, horsHoraires: string, alertes: array{son: bool, voix: bool, volume: int, silenceDebut: ?string, silenceFin: ?string, evenements: list<string>}}
     */
    public static function normaliser(array $brut): array
    {
        $scenes = [];

        foreach (\is_array($brut['scenes'] ?? null) ? $brut['scenes'] : [] as $scene) {
            if (\is_array($scene) && ($propre = self::scene($scene)) !== null) {
                $scenes[] = $propre;
            }
        }

        $zones = \is_array($brut['zones'] ?? null) ? $brut['zones'] : [];
        $avis = [];

        foreach (\is_array($brut['avis'] ?? null) ? $brut['avis'] : [] as $un) {
            $texte = \is_array($un) ? self::texte($un['texte'] ?? '', 200) : '';

            if ($texte !== '') {
                $avis[] = [
                    'texte' => $texte,
                    'note' => max(1, min(5, (int) ($un['note'] ?? 5))),
                    'signature' => self::texte($un['signature'] ?? '', 60) ?: 'Avis Google · client vérifié',
                ];
            }
        }

        $horaires = [];
        $horairesBruts = \is_array($brut['horaires'] ?? null) ? $brut['horaires'] : [];

        foreach (array_keys(self::JOURS) as $jour) {
            $plage = $horairesBruts[$jour] ?? null;
            $ouverture = \is_array($plage) ? self::heure($plage['ouverture'] ?? null) : null;
            $fermeture = \is_array($plage) ? self::heure($plage['fermeture'] ?? null) : null;
            $horaires[$jour] = $ouverture !== null && $fermeture !== null && $ouverture < $fermeture
                ? ['ouverture' => $ouverture, 'fermeture' => $fermeture]
                : null;
        }

        $note = $brut['noteGoogle'] ?? null;
        $nombreAvis = $brut['avisGoogle'] ?? null;
        $messages = \is_array($brut['messages'] ?? null) ? $brut['messages'] : self::MESSAGES_PAR_DEFAUT;

        return [
            'scenes' => $scenes,
            'zones' => [
                'colonneLive' => (bool) ($zones['colonneLive'] ?? true),
                'bandeau' => (bool) ($zones['bandeau'] ?? true),
                'horlogeLogo' => (bool) ($zones['horlogeLogo'] ?? true),
                'son' => (bool) ($zones['son'] ?? false),
            ],
            'avis' => $avis,
            'messages' => array_values(array_filter(array_map(static fn ($m) => self::texte($m, 120), $messages))),
            'noteGoogle' => is_numeric($note) ? max(0.0, min(5.0, round((float) $note, 1))) : null,
            'avisGoogle' => is_numeric($nombreAvis) && (int) $nombreAvis >= 0 ? (int) $nombreAvis : null,
            'horaires' => $horaires,
            'horsHoraires' => ($brut['horsHoraires'] ?? null) === self::HORS_HORAIRES_ETEINT ? self::HORS_HORAIRES_ETEINT : self::HORS_HORAIRES_FERME,
            'alertes' => self::alertes(\is_array($brut['alertes'] ?? null) ? $brut['alertes'] : []),
        ];
    }

    /**
     * Alertes sonores : carillon, voix, volume (0 à 100), heures de
     * silence (vides : jamais) et événements annoncés. Un programme
     * enregistré avant les alertes prend les valeurs par défaut.
     *
     * @param array<mixed> $brut
     *
     * @return array{son: bool, voix: bool, volume: int, silenceDebut: ?string, silenceFin: ?string, evenements: list<string>}
     */
    private static function alertes(array $brut): array
    {
        $evenements = \is_array($brut['evenements'] ?? null)
            ? array_values(array_intersect(array_keys(AlertesEcranTv::TYPES), array_map('strval', array_filter($brut['evenements'], 'is_scalar'))))
            : AlertesEcranTv::evenementsParDefaut();

        return [
            'son' => (bool) ($brut['son'] ?? true),
            'voix' => (bool) ($brut['voix'] ?? true),
            'volume' => is_numeric($brut['volume'] ?? null) ? max(0, min(100, (int) $brut['volume'])) : AlertesEcranTv::VOLUME,
            'silenceDebut' => \array_key_exists('silenceDebut', $brut) ? self::heure($brut['silenceDebut']) : AlertesEcranTv::SILENCE_DEBUT,
            'silenceFin' => \array_key_exists('silenceFin', $brut) ? self::heure($brut['silenceFin']) : AlertesEcranTv::SILENCE_FIN,
            'evenements' => $evenements,
        ];
    }

    /**
     * Scènes actives à cet instant (interrupteur, dates, jours et heures).
     *
     * @param list<array<string, mixed>> $scenes
     *
     * @return list<array<string, mixed>>
     */
    public static function scenesDuMoment(array $scenes, \DateTimeImmutable $maintenant): array
    {
        return array_values(array_filter($scenes, static fn (array $s) => self::estProgrammee($s, $maintenant)));
    }

    /**
     * @param array<string, mixed> $scene
     */
    public static function estProgrammee(array $scene, \DateTimeImmutable $maintenant): bool
    {
        if (!$scene['actif']) {
            return false;
        }

        $jour = $maintenant->format('Y-m-d');
        $heure = $maintenant->format('H:i');

        return ($scene['dateDebut'] === null || $jour >= $scene['dateDebut'])
            && ($scene['dateFin'] === null || $jour <= $scene['dateFin'])
            && ($scene['jours'] === [] || \in_array((int) $maintenant->format('N'), $scene['jours'], true))
            && ($scene['heureDebut'] === null || $heure >= $scene['heureDebut'])
            && ($scene['heureFin'] === null || $heure < $scene['heureFin']);
    }

    /**
     * @param array<int, ?array{ouverture: string, fermeture: string}> $horaires
     */
    public static function estOuvert(array $horaires, \DateTimeImmutable $maintenant): bool
    {
        $plage = $horaires[(int) $maintenant->format('N')] ?? null;
        $heure = $maintenant->format('H:i');

        return $plage !== null && $heure >= $plage['ouverture'] && $heure < $plage['fermeture'];
    }

    /**
     * « Réouverture demain à 8 h », « lundi à 8 h 30 »… null si l'imprimerie n'ouvre jamais.
     *
     * @param array<int, ?array{ouverture: string, fermeture: string}> $horaires
     */
    public static function prochaineOuverture(array $horaires, \DateTimeImmutable $maintenant): ?string
    {
        for ($i = 0; $i < 8; ++$i) {
            $jour = $maintenant->modify(sprintf('+%d days', $i));
            $plage = $horaires[(int) $jour->format('N')] ?? null;

            if ($plage === null || ($i === 0 && $maintenant->format('H:i') >= $plage['ouverture'])) {
                continue;
            }

            $quand = match ($i) {
                0 => 'aujourd\'hui',
                1 => 'demain',
                default => mb_strtolower(self::JOURS[(int) $jour->format('N')]),
            };

            return sprintf('Réouverture %s à %s', $quand, self::heureLisible($plage['ouverture']));
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $scenes
     */
    public static function dureeBoucle(array $scenes): int
    {
        return array_sum(array_map(static fn (array $s) => $s['actif'] ? $s['duree'] : 0, $scenes));
    }

    /**
     * « Toujours », « 8 h – 19 h », « 01/10 → 31/10 », « Sam. uniquement »…
     *
     * @param array<string, mixed> $scene
     */
    public static function resumeHoraire(array $scene): string
    {
        $parties = [];

        if ($scene['dateDebut'] !== null || $scene['dateFin'] !== null) {
            $parties[] = sprintf(
                '%s → %s',
                $scene['dateDebut'] !== null ? self::dateCourte($scene['dateDebut']) : '…',
                $scene['dateFin'] !== null ? self::dateCourte($scene['dateFin']) : '…'
            );
        }

        if ($scene['jours'] !== []) {
            $parties[] = implode(', ', array_map(static fn (int $j) => self::JOURS_COURTS[$j], $scene['jours'])).(\count($scene['jours']) === 1 ? ' uniquement' : '');
        }

        if ($scene['heureDebut'] !== null || $scene['heureFin'] !== null) {
            $parties[] = sprintf('%s – %s', self::heureLisible($scene['heureDebut'] ?? '00:00'), $scene['heureFin'] !== null ? self::heureLisible($scene['heureFin']) : 'fermeture');
        }

        return $parties === [] ? 'Toujours' : implode(' · ', $parties);
    }

    /**
     * « 08:00 » donne « 8 h », « 08:30 » donne « 8 h 30 ».
     */
    public static function heureLisible(string $heure): string
    {
        [$h, $m] = array_map('intval', explode(':', $heure));

        return $m === 0 ? sprintf('%d h', $h) : sprintf('%d h %02d', $h, $m);
    }

    public static function dureeParDefaut(string $type): int
    {
        return self::DUREES_PAR_DEFAUT[$type] ?? 8;
    }

    /**
     * @param array<mixed> $brut
     *
     * @return array<string, mixed>|null
     */
    private static function scene(array $brut): ?array
    {
        $type = (string) ($brut['type'] ?? '');

        if (!isset(self::TYPES[$type])) {
            return null;
        }

        $id = preg_match('/^[a-z0-9]{1,16}$/', (string) ($brut['id'] ?? '')) ? (string) $brut['id'] : bin2hex(random_bytes(4));
        $jours = array_values(array_unique(array_filter(
            array_map('intval', \is_array($brut['jours'] ?? null) ? $brut['jours'] : []),
            static fn (int $j) => isset(self::JOURS[$j])
        )));
        sort($jours);
        $dateDebut = self::date($brut['dateDebut'] ?? null);
        $dateFin = self::date($brut['dateFin'] ?? null);

        if ($dateDebut !== null && $dateFin !== null && $dateFin < $dateDebut) {
            [$dateDebut, $dateFin] = [$dateFin, $dateDebut];
        }

        $medias = array_values(array_filter(
            array_map('strval', \is_array($brut['medias'] ?? null) ? $brut['medias'] : []),
            static fn (string $f) => EcranTv::typeMedia($f) !== null && basename($f) === $f
        ));

        return [
            'id' => $id,
            'type' => $type,
            'titre' => self::texte($brut['titre'] ?? '', 80) ?: self::TYPES[$type],
            'surtitre' => self::texte($brut['surtitre'] ?? '', 40),
            'texte' => self::texte($brut['texte'] ?? '', 160),
            'pastille' => self::texte($brut['pastille'] ?? '', 40),
            'fond' => isset(self::FONDS[$brut['fond'] ?? '']) ? (string) $brut['fond'] : ($type === self::TYPE_PROMO ? 'rouge' : 'bleu'),
            'medias' => $type === self::TYPE_VIDEO ? \array_slice($medias, 0, 1) : $medias,
            'duree' => max(self::DUREE_MIN, min(self::DUREE_MAX, (int) ($brut['duree'] ?? self::dureeParDefaut($type)))),
            'actif' => (bool) ($brut['actif'] ?? true),
            'heureDebut' => self::heure($brut['heureDebut'] ?? null),
            'heureFin' => self::heure($brut['heureFin'] ?? null),
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
            'jours' => \count($jours) === 7 ? [] : $jours,
        ];
    }

    private static function texte(mixed $valeur, int $longueur): string
    {
        return \is_scalar($valeur) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $valeur)), 0, $longueur) : '';
    }

    private static function heure(mixed $valeur): ?string
    {
        return \is_string($valeur) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur) ? $valeur : null;
    }

    private static function date(mixed $valeur): ?string
    {
        if (!\is_string($valeur) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date !== false && $date->format('Y-m-d') === $valeur ? $valeur : null;
    }

    private static function dateCourte(string $date): string
    {
        return substr($date, 8, 2).'/'.substr($date, 5, 2);
    }
}
