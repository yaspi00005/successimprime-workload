<?php

namespace App\Service\Site;

use App\Entity\ImageSite;
use App\Entity\MembreEquipe;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * « Notre équipe » de la démonstration (app:demo) : six personnes fictives,
 * identifiants à partir de 900001. Les photos sont des avatars dessinés
 * ici (aucune photo d'une vraie personne) ; deux personnes n'ont pas de
 * photo (initiales) et la dernière n'a pas donné son accord : « Visible »
 * est coché, mais elle reste hors du site. La première est la gérante :
 * son mot paraît avant l'équipe, et elle n'est pas répétée dans la grille.
 *
 * Écrit par la connexion de app:demo, dans sa transaction, sans passer
 * par la file de synchronisation (comme le reste de la démonstration).
 */
final class EquipeDemo
{
    public const PREMIER_ID = 900001;

    /** Couleurs du site : fond pâle, couleur du haut, teinte de peau, coiffure. */
    private const TEINTES = [
        'bleu' => ['fond' => [229, 238, 250], 'haut' => [15, 69, 144]],
        'rouge' => ['fond' => [253, 228, 231], 'haut' => [222, 16, 40]],
        'orange' => ['fond' => [253, 235, 228], 'haut' => [233, 91, 53]],
        'magenta' => ['fond' => [252, 231, 241], 'haut' => [232, 57, 138]],
    ];

    /**
     * Les personnes, dans l'ordre d'affichage.
     *
     * « coiffure » : foulard, courte, lunettes (cheveux courts et lunettes)
     * ou rasee.
     *
     * @return list<array{nom: string, poste: string, avatar: ?array{teinte: string, peau: list<int>, coiffure: string}, visible: bool, accord: bool, mot?: string}>
     */
    public static function personnes(): array
    {
        return [
            // La gérante : son mot (fictif, sans montant) paraît avant « Notre équipe ».
            ['nom' => 'Kadiatou T.', 'poste' => 'Gérante', 'avatar' => ['teinte' => 'bleu', 'peau' => [122, 78, 48], 'coiffure' => 'foulard'], 'visible' => true, 'accord' => true, 'mot' => self::MOT],
            ['nom' => 'Seydou K.', 'poste' => 'Graphiste PAO', 'avatar' => ['teinte' => 'rouge', 'peau' => [94, 60, 38], 'coiffure' => 'lunettes'], 'visible' => true, 'accord' => true],
            ['nom' => 'Aïssata D.', 'poste' => 'Graphiste PAO', 'avatar' => null, 'visible' => true, 'accord' => true],
            ['nom' => 'Oumar G.', 'poste' => 'Opérateur grand format', 'avatar' => ['teinte' => 'orange', 'peau' => [108, 70, 44], 'coiffure' => 'rasee'], 'visible' => true, 'accord' => true],
            ['nom' => 'Aminata S.', 'poste' => 'Accueil et caisse', 'avatar' => null, 'visible' => true, 'accord' => true],
            // Visible coché, mais sans l'accord de la personne : elle ne doit apparaître nulle part sur le site.
            ['nom' => 'Bakary C.', 'poste' => 'Livraison', 'avatar' => null, 'visible' => true, 'accord' => false],
        ];
    }

    /** Mot de la gérante de démonstration. */
    public const MOT = "Bienvenue chez Succès Imprim. Depuis notre atelier de Bamako, nous imprimons chaque jour les banderoles, enseignes, roll-up et textiles de commerçants, d'entreprises et d'associations.\nNotre engagement est simple : un BAT validé avant chaque impression, des délais annoncés et tenus, et une équipe qui vous répond, à l'imprimerie comme sur WhatsApp.\nPassez nous voir : nous serons heureux de préparer votre prochain projet avec vous.";

    /** Instructions de la purge (aussi jouées avant chaque chargement). */
    public const PURGE = [
        "DELETE i FROM site_image i JOIN site_equipe e ON e.photo_id = i.id WHERE e.id >= 900000 AND i.usage_image = 'portrait'",
        'DELETE FROM site_equipe WHERE id >= 900000',
    ];

    /** Insère l'équipe de démonstration ; renvoie le nombre de personnes. */
    public static function charger(Connection $connexion, ?\DateTimeImmutable $maintenant = null): int
    {
        $maintenant ??= new \DateTimeImmutable();
        $id = self::PREMIER_ID;
        foreach (self::personnes() as $rang => $personne) {
            $membre = (new MembreEquipe())->setNom($personne['nom'])->setPoste($personne['poste'])->setMotGerant($personne['mot'] ?? null);
            $photo = null;
            // Pas de photo sans accord (ContenuSite) : la démonstration suit la même règle.
            if (null !== $personne['avatar'] && $personne['accord']) {
                $image = self::image($personne['avatar']);
                $image->setTexteAlternatif($membre->getTexteAlternatif());
                $connexion->insert('site_image', [
                    'id' => $id,
                    'contenu' => base64_encode($image->getOctets()),
                    'type' => $image->getType(),
                    'largeur' => $image->getLargeur(),
                    'hauteur' => $image->getHauteur(),
                    'empreinte' => $image->getEmpreinte(),
                    'usage_image' => $image->getUsage(),
                    'texte_alternatif' => $image->getTexteAlternatif(),
                    'ordre' => 0,
                    'cree_le' => $maintenant->format('Y-m-d H:i:s'),
                    'public_id' => $image->getPublicId()->toBinary(),
                ]);
                $photo = $id;
            }
            $connexion->insert('site_equipe', [
                'id' => $id,
                'nom' => $membre->getNom(),
                'poste' => $membre->getPoste(),
                'ordre' => ($rang + 1) * 10,
                'visible' => (int) $personne['visible'],
                'accord_personne' => (int) $personne['accord'],
                'gerant' => (int) isset($personne['mot']),
                'mot_gerant' => $membre->getMotGerant(),
                'aussi_dans_equipe' => 0,
                'public_id' => Uuid::v7()->toBinary(),
                'photo_id' => $photo,
            ]);
            ++$id;
        }

        return \count(self::personnes());
    }

    /**
     * Avatar dessiné (buste aux couleurs du site), passé par le même
     * réencodage que les photos envoyées.
     *
     * @param array{teinte: string, peau: list<int>, coiffure: string} $avatar
     */
    public static function image(array $avatar): ImageSite
    {
        $dessin = self::dessiner($avatar, 960);
        $image = imagecreatetruecolor(480, 480);
        imagecopyresampled($image, $dessin, 0, 0, 0, 0, 480, 480, 960, 960);
        [$octets, $type] = ImagesSite::encoder($image);

        return ImageSite::depuis($octets, $type, imagesx($image), imagesy($image), ImageSite::USAGE_PORTRAIT);
    }

    /**
     * Dessin à grande taille (réduit ensuite, ce qui adoucit les bords) :
     * fond pâle, rubans aux couleurs du site, buste, visage simple.
     *
     * @param array{teinte: string, peau: list<int>, coiffure: string} $avatar
     */
    private static function dessiner(array $avatar, int $c): \GdImage
    {
        $teinte = self::TEINTES[$avatar['teinte']] ?? self::TEINTES['bleu'];
        $im = imagecreatetruecolor($c, $c);
        $couleur = static fn (array $rvb): int => (int) imagecolorallocate($im, $rvb[0], $rvb[1], $rvb[2]);
        $assombrir = static fn (array $rvb, float $f): array => array_map(static fn (int $v): int => (int) round($v * $f), $rvb);
        $u = $c / 100; // une unité = 1 % du côté

        imagefill($im, 0, 0, $couleur($teinte['fond']));
        // Rubans du site (bleu, rouge, jaune) en bas à droite, comme les bandeaux des pages.
        foreach ([[[47, 112, 192], 0], [[233, 91, 53], 9], [[253, 200, 42], 18]] as [$rvb, $decalage]) {
            imagefilledpolygon($im, [(int) (60 * $u), (int) (100 * $u), (int) (100 * $u), (int) ((52 + $decalage) * $u), (int) (100 * $u), (int) ((62 + $decalage) * $u), (int) ((74 + $decalage / 2) * $u), (int) (100 * $u)], $couleur($rvb));
        }

        $peau = $avatar['peau'];
        $haut = $teinte['haut'];
        // Épaules et haut (polo aux couleurs), col blanc.
        imagefilledellipse($im, (int) (50 * $u), (int) (100 * $u), (int) (86 * $u), (int) (64 * $u), $couleur($haut));
        imagefilledpolygon($im, [(int) (41 * $u), (int) (69 * $u), (int) (59 * $u), (int) (69 * $u), (int) (50 * $u), (int) (80 * $u)], $couleur([255, 255, 255]));
        // Cou.
        imagefilledrectangle($im, (int) (44 * $u), (int) (58 * $u), (int) (56 * $u), (int) (71 * $u), $couleur($assombrir($peau, .88)));
        imagefilledellipse($im, (int) (50 * $u), (int) (71 * $u), (int) (12 * $u), (int) (7 * $u), $couleur($assombrir($peau, .88)));

        $foulard = 'foulard' === $avatar['coiffure'];
        $cheveux = $couleur([33, 26, 22]);
        if ($foulard) {
            // Foulard noué, jaune du site.
            imagefilledellipse($im, (int) (50 * $u), (int) (36 * $u), (int) (42 * $u), (int) (40 * $u), $couleur([253, 200, 42]));
            imagefilledellipse($im, (int) (70 * $u), (int) (23 * $u), (int) (13 * $u), (int) (11 * $u), $couleur([233, 170, 20]));
        } elseif ('rasee' === $avatar['coiffure']) {
            imagefilledellipse($im, (int) (50 * $u), (int) (41 * $u), (int) (32 * $u), (int) (34 * $u), $couleur($assombrir($peau, .55)));
        } else {
            imagefilledellipse($im, (int) (50 * $u), (int) (38 * $u), (int) (35 * $u), (int) (37 * $u), $cheveux);
        }
        // Visage, et oreilles quand rien ne les couvre.
        if (!$foulard) {
            imagefilledellipse($im, (int) (34.5 * $u), (int) (46 * $u), (int) (6 * $u), (int) (8 * $u), $couleur($peau));
            imagefilledellipse($im, (int) (65.5 * $u), (int) (46 * $u), (int) (6 * $u), (int) (8 * $u), $couleur($peau));
        }
        imagefilledellipse($im, (int) (50 * $u), (int) (($foulard ? 47 : 46.5) * $u), (int) (31 * $u), (int) (33 * $u), $couleur($peau));
        // Yeux et sourire.
        $sombre = $couleur([30, 22, 18]);
        imagefilledellipse($im, (int) (44 * $u), (int) (45 * $u), (int) (3 * $u), (int) (3.6 * $u), $sombre);
        imagefilledellipse($im, (int) (56 * $u), (int) (45 * $u), (int) (3 * $u), (int) (3.6 * $u), $sombre);
        imagesetthickness($im, max(1, (int) round(1.2 * $u)));
        imagearc($im, (int) (50 * $u), (int) (51 * $u), (int) (11 * $u), (int) (7 * $u), 25, 155, $sombre);
        if ('lunettes' === $avatar['coiffure']) {
            // Lunettes rondes du graphiste.
            imagearc($im, (int) (44 * $u), (int) (45 * $u), (int) (9 * $u), (int) (9 * $u), 0, 360, $sombre);
            imagearc($im, (int) (56 * $u), (int) (45 * $u), (int) (9 * $u), (int) (9 * $u), 0, 360, $sombre);
            imageline($im, (int) (48.5 * $u), (int) (45 * $u), (int) (51.5 * $u), (int) (45 * $u), $sombre);
        }
        imagesetthickness($im, 1);

        return $im;
    }
}
