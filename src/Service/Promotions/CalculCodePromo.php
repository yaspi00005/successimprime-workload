<?php

namespace App\Service\Promotions;

use App\Entity\CodePromo;
use App\Entity\Promotion;

/**
 * Règles des codes promo (docs/promotions.md, « Codes promo »), sans base
 * de données : forme d'un code, génération, empreinte pour le site,
 * validité d'un code pour un document, avantage sur la commande et sa
 * répartition sur les lignes, messages.
 *
 * Messages : au client en ligne, jamais rien qui dise si d'autres codes
 * existent (« Ce code n'est pas valable pour cette commande. ») ; la raison
 * précise seulement quand elle tient à sa propre commande (code expiré,
 * minimum non atteint, déjà utilisé par lui). L'équipe, elle, voit la
 * raison, sauf pour un code inconnu.
 */
final class CalculCodePromo
{
    /** Ni 0/O, ni 1/I/L : rien à confondre en recopiant le code (comme les accès à l'espace client). */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** SI-XXXX-XX : six caractères tirés au hasard. */
    public const PREFIXE_LOT = 'SI';
    public const LONGUEUR_LOT = 6;

    /** Lot de codes uniques : au plus, d'un coup. */
    public const LOT_MAX = 500;

    /** Code partagé tapé par l'équipe : lettres et chiffres. */
    public const CODE_MIN = 4;
    public const CODE_MAX = 20;

    /** Pourcentage de la commande, au plus. */
    public const POURCENTAGE_MAX = 90;

    public const GENERIQUE = 'Ce code n\'est pas valable pour cette commande.';

    /** Raisons d'un refus, dans l'ordre des vérifications, et ce que l'équipe lit. */
    public const REFUS_EQUIPE = [
        'inconnu' => self::GENERIQUE,
        'client' => 'Choisissez d\'abord le client.',
        'desactive' => 'Code désactivé, ou promotion suspendue.',
        'pas_encore' => 'Code pas encore valable à la date de la commande.',
        'expire' => 'Code expiré à la date de la commande.',
        'b2b' => 'Client au prix B2B : pas de code promo.',
        'segment' => 'Client hors du segment de la promotion.',
        'epuise' => 'Code épuisé : toutes ses utilisations sont prises.',
        'deja_utilise' => 'Ce client a déjà utilisé ce code autant de fois que permis.',
        'promotion_utilisee' => 'Ce client a déjà profité de cette promotion autant de fois que permis (commandes par client).',
        'minimum' => 'Montant minimal de la commande non atteint.',
        'sans_effet' => 'Aucun article de la commande n\'en profite (ou ils sont déjà en promotion).',
    ];

    /** Ce que le client lit après la phrase générale : seulement ce qui tient à sa commande. */
    public const REFUS_CLIENT = [
        'expire' => 'Ce code a expiré.',
        'deja_utilise' => 'Vous avez déjà utilisé ce code.',
        'promotion_utilisee' => 'Vous avez déjà profité de cette offre.',
        'b2b' => 'Les prix revendeur ne se cumulent pas avec un code promo.',
        'minimum' => 'Le montant minimal de commande n\'est pas atteint.',
        'sans_effet' => 'Aucun article de cette commande n\'en profite.',
    ];

    /** Lettres et chiffres en majuscules, sans espaces, tirets ni accents : « si-7k4p 2q » donne SI7K4P2Q, « Rentrée25 » RENTREE25. */
    public static function canonique(?string $saisi): string
    {
        $texte = strtr(mb_strtoupper((string) $saisi), [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        ]);

        return substr((string) preg_replace('/[^A-Z0-9]/', '', $texte), 0, 30);
    }

    /** Code d'un lot : SI suivi de six caractères tirés avec random_int (sûr pour la cryptographie). */
    public static function genererCode(): string
    {
        $code = self::PREFIXE_LOT;
        $max = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LONGUEUR_LOT; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Un code tapé par l'équipe pour un code partagé : null s'il convient, sinon le message. */
    public static function erreurCodePartage(string $canonique): ?string
    {
        $n = \strlen($canonique);
        if ($n < self::CODE_MIN || $n > self::CODE_MAX) {
            return \sprintf('Le code fait de %d à %d lettres ou chiffres.', self::CODE_MIN, self::CODE_MAX);
        }

        return null;
    }

    /**
     * Ce que le site garde d'un code : HMAC-SHA256 avec la clé de
     * synchronisation (la même des deux côtés, jamais en base). Sans la
     * clé, une copie de la base du site ne permet pas de retrouver les codes.
     */
    public static function empreinte(string $canonique, string $cle): string
    {
        return hash_hmac('sha256', 'code-promo|'.$canonique, $cle);
    }

    /**
     * null si le code peut servir sur ce document, sinon la clé de REFUS_EQUIPE.
     *
     * @param int  $autresUtilisations         devis et commandes vivants qui le portent, sans celui-ci
     * @param int  $autresUtilisationsDuClient les mêmes, pour ce client
     * @param bool $dejaSurLeDocument          le document le porte déjà : désactivé depuis, il le garde
     * @param list<string> $segments           segments du client au jour du document
     * @param int  $commandesAvecLaPromotion    autres commandes du client qui ont profité de la promotion (une ligne ou un de ses codes)
     */
    public static function refus(CodePromo $code, ?bool $clientB2b, \DateTimeImmutable $jour, int $autresUtilisations, int $autresUtilisationsDuClient, bool $dejaSurLeDocument, array $segments, bool $dansLaListe = false, int $commandesAvecLaPromotion = 0): ?string
    {
        $promotion = $code->getPromotion();
        if (null === $promotion) {
            return 'inconnu';
        }
        if (null === $clientB2b) {
            return 'client';
        }
        if (!$dejaSurLeDocument && (!$code->isActive() || !$promotion->isActive())) {
            return 'desactive';
        }
        $j = $jour->format('Y-m-d');
        if ($j < $code->getDebutEffectif()->format('Y-m-d')) {
            return 'pas_encore';
        }
        if ($j > $code->getFinEffective()->format('Y-m-d')) {
            return 'expire';
        }
        if ($clientB2b) {
            return 'b2b';
        }
        $segment = $promotion->getSegment();
        if (Promotion::SEGMENT_TOUS !== $segment && (Promotion::SEGMENT_LISTE === $segment ? !$dansLaListe : !\in_array($segment, $segments, true))) {
            return 'segment';
        }
        if ($autresUtilisations >= $code->getUtilisationsMax()) {
            return 'epuise';
        }
        if ($autresUtilisationsDuClient >= $code->getUtilisationsParClient()) {
            return 'deja_utilise';
        }
        // « Commandes par client » de la promotion : pour tous ses codes ensemble (deux coupons d'un lot, par exemple).
        if (null !== $promotion->getUtilisationsParClient() && $commandesAvecLaPromotion >= $promotion->getUtilisationsParClient()) {
            return 'promotion_utilisee';
        }

        return null;
    }

    /**
     * Avantage de la promotion sur une commande, hors TVA.
     *
     * @param int $base montant des lignes qui peuvent le recevoir (sans promotion de ligne), après leurs remises
     */
    public static function montantCommande(Promotion $p, int $base): int
    {
        $base = max(0, $base);
        if (!$p->aUnAvantageCommande() || 0 === $base) {
            return 0;
        }
        if (Promotion::COMMANDE_MONTANT === $p->getAvantageCommande()) {
            return min($base, (int) round($p->getValeurCommande()));
        }
        $montant = (int) round($base * min((float) self::POURCENTAGE_MAX, $p->getValeurCommande()) / 100);
        if (null !== $p->getPlafondCommande()) {
            $montant = min($montant, $p->getPlafondCommande());
        }

        return min($base, $montant);
    }

    /** Le minimum de la promotion est-il atteint par la commande (hors TVA, après les remises des lignes) ? */
    public static function minimumAtteint(Promotion $p, int $totalCommande): bool
    {
        return null === $p->getMinimumCommande() || $totalCommande >= $p->getMinimumCommande();
    }

    /**
     * Répartit un montant sur les lignes au prorata de leur base, en
     * francs entiers : la somme des parts fait exactement le montant, et
     * aucune part ne dépasse sa base (plus forts restes).
     *
     * @template K of array-key
     *
     * @param array<K, int> $bases
     *
     * @return array<K, int>
     */
    public static function repartir(int $montant, array $bases): array
    {
        $parts = array_map(static fn (): int => 0, $bases);
        $total = array_sum(array_map(static fn (int $b): int => max(0, $b), $bases));
        $montant = min(max(0, $montant), $total);
        if (0 === $montant) {
            return $parts;
        }
        $restes = [];
        $donne = 0;
        foreach ($bases as $cle => $base) {
            $base = max(0, $base);
            $exact = $montant * $base / $total;
            $parts[$cle] = (int) floor($exact);
            $donne += $parts[$cle];
            $restes[$cle] = $exact - $parts[$cle];
        }
        // Les francs qui restent vont aux plus forts restes (à égalité, dans l'ordre des lignes).
        arsort($restes, \SORT_NUMERIC);
        foreach (array_keys($restes) as $cle) {
            if ($donne >= $montant) {
                break;
            }
            if ($parts[$cle] < max(0, $bases[$cle])) {
                ++$parts[$cle];
                ++$donne;
            }
        }

        return $parts;
    }

    /** La raison que lit l'équipe, seule (sous le champ du code, sur la fiche de la commande). */
    public static function raison(string $refus): string
    {
        return self::REFUS_EQUIPE[$refus] ?? self::GENERIQUE;
    }

    /** Message de refus : pour l'équipe (la raison) ou pour le client (phrase générale, et la raison si elle tient à sa commande). */
    public static function message(string $refus, bool $equipe): string
    {
        if ($equipe) {
            return 'Code promo : '.self::raison($refus);
        }
        $precision = self::REFUS_CLIENT[$refus] ?? null;

        return null === $precision ? self::GENERIQUE : self::GENERIQUE.' '.$precision;
    }
}
