<?php

namespace App\Security;

/**
 * À qui part un code de connexion : le numéro (pour le SMS et les
 * limites d'envoi) et, s'il y en a une, l'adresse e-mail. Deux cas où
 * rien ne part : une fiche de démonstration (DEMO-) et un numéro inconnu
 * (la réponse reste la même, pour ne pas dire qui est client).
 */
final class DestinationCode
{
    public const NORMALE = 'normale';
    public const DEMONSTRATION = 'demonstration';
    public const INCONNUE = 'inconnue';

    /** Messageries courantes : leur nom ne dit rien de la personne, il reste lisible une fois masqué. */
    private const MESSAGERIES = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr',
        'live.com', 'live.fr', 'icloud.com', 'me.com', 'msn.com', 'aol.com', 'protonmail.com', 'proton.me', 'orange.fr', 'orangemali.com',
    ];

    public readonly ?string $email;

    private function __construct(
        public readonly string $telephone,
        ?string $email,
        public readonly string $type,
    ) {
        $this->email = self::emailValable($email);
    }

    /** Code à envoyer : par e-mail si l'adresse est valable, sinon par SMS. */
    public static function vers(string $telephone, ?string $email = null): self
    {
        return new self(trim($telephone), $email, self::NORMALE);
    }

    /** Fiche de démonstration : le code est préparé, jamais envoyé. */
    public static function demonstration(string $telephone): self
    {
        return new self(trim($telephone), null, self::DEMONSTRATION);
    }

    /** Numéro inconnu : rien ne part, mais la réponse est celle d'un envoi par SMS. */
    public static function inconnue(string $telephone): self
    {
        return new self(trim($telephone), null, self::INCONNUE);
    }

    /** Adresse en minuscules si elle est valable, sinon null. */
    public static function emailValable(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ('' === $email || mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * « y•••@gmail.com » ; le nom d'un domaine qui n'est pas une messagerie
     * courante est masqué aussi (« c•••@h•••.ml »), pour ne pas dire à qui
     * tape un numéro de quelle entreprise il est.
     */
    public static function masquerEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $arobase = mb_strrpos($email, '@');
        if (false === $arobase || 0 === $arobase) {
            return '—';
        }
        $nom = mb_substr($email, 0, $arobase);
        $domaine = mb_substr($email, $arobase + 1);
        if (!\in_array($domaine, self::MESSAGERIES, true)) {
            $point = mb_strrpos($domaine, '.');
            $domaine = false === $point || 0 === $point
                ? mb_substr($domaine, 0, 1).'•••'
                : mb_substr($domaine, 0, 1).'•••'.mb_substr($domaine, $point);
        }

        return mb_substr($nom, 0, 1).'•••@'.$domaine;
    }

    /** « +223 •• •• 42 12 » */
    public static function masquerTelephone(string $telephone): string
    {
        $chiffres = preg_replace('/\D/', '', $telephone) ?? '';
        if (\strlen($chiffres) < 4) {
            return '—';
        }
        if (8 === \strlen($chiffres)) {
            $chiffres = '223'.$chiffres;
        }

        return '+'.substr($chiffres, 0, 3).' •• •• '.substr($chiffres, -4, 2).' '.substr($chiffres, -2);
    }
}
