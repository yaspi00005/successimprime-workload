<?php

namespace App\Service\Pointage;

use App\Entity\Employes;

/**
 * Les deux horaires types du calcul des points (docs/pointage.md,
 * « Calcul des points ») : « Accueil et caisse » (assistantes,
 * caissières) et « Atelier et autres ». Leurs heures se règlent dans
 * Paramètres › Points du pointage.
 *
 * Chaque employé en a un : celui choisi sur sa fiche, sinon celui de
 * son poste (ou de son rôle si le poste ne dit rien). Des horaires
 * propres sur la fiche (gardien de nuit…) passent avant.
 */
final class HoraireType
{
    public const ACCUEIL = 'accueil';
    public const ATELIER = 'atelier';

    public const LIBELLES = [
        self::ACCUEIL => 'Accueil et caisse',
        self::ATELIER => 'Atelier et autres',
    ];

    /** Postes de l'accueil : caissière, assistante, secrétaire, réception, hôtesse. */
    private const POSTES_ACCUEIL = '/caiss|assistant|accueil|secr[ée]tai|r[ée]ception|h[ôo]tesse/iu';

    /** Rôle de base (Réglages › Rôles) des personnes de la caisse. */
    private const ROLE_CAISSE = 'ROLE_CAISSE_COMMANDE';

    /**
     * Horaire type d'après le poste ; un poste vide (ou seulement le
     * contrat, « CDI ») laisse décider le rôle.
     */
    public static function parDefaut(?string $poste, ?string $role): string
    {
        $poste = trim((string) $poste);
        if ($poste !== '' && !\in_array($poste, Employes::CONTRATS, true)) {
            return preg_match(self::POSTES_ACCUEIL, $poste) ? self::ACCUEIL : self::ATELIER;
        }

        return $role === self::ROLE_CAISSE ? self::ACCUEIL : self::ATELIER;
    }

    /** Horaire type de l'employé : celui de sa fiche, sinon celui de son poste. */
    public static function de(Employes $employe): string
    {
        return $employe->getHoraireType() ?? self::parDefaut($employe->getFonction(), $employe->getUser()?->getProfil()?->getCode());
    }

    /**
     * Minutes de travail dues entre deux heures « HH:MM » (fin avant le
     * début : le lendemain), sans la pause si elle est retirée.
     */
    public static function minutesDues(string $debut, string $fin, ?string $pauseDebut = null, ?string $pauseFin = null): int
    {
        $de = self::minutes($debut);
        $a = self::minutes($fin);
        if ($a <= $de) {
            $a += 24 * 60;
        }
        $dues = $a - $de;
        if ($pauseDebut !== null && $pauseFin !== null) {
            foreach ([0, 24 * 60] as $decalage) {
                $p1 = self::minutes($pauseDebut) + $decalage;
                $p2 = self::minutes($pauseFin) + $decalage;
                $dues -= max(0, min($a, $p2) - max($de, $p1));
            }
        }

        return max(0, $dues);
    }

    private static function minutes(string $heure): int
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => '0']);

        return $h * 60 + $m;
    }
}
