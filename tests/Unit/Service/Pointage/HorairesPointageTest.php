<?php

namespace App\Tests\Unit\Service\Pointage;

use App\Entity\Employes;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Service\Pointage\HoraireType;
use App\Service\Pointage\Pointages;
use PHPUnit\Framework\TestCase;

/**
 * Horaire de chaque employé pour le calcul des points (docs/pointage.md,
 * « Calcul des points ») et date de début du nouveau barème.
 */
final class HorairesPointageTest extends TestCase
{
    private const HEURES = [
        'accueil_debut' => '09:00', 'accueil_fin' => '17:00', 'atelier_debut' => '09:00', 'atelier_fin' => '18:00',
        'points_heures_faites' => 10, 'points_retard' => 15, 'points_heure_en_plus' => 5, 'longue_heures' => 20, 'points_longue' => 50,
        'pause_deduite' => false, 'valider_en_plus' => true, 'debut' => '2026-10-18',
    ];

    private const REGLES = [
        'ouverture' => '08:00', 'fermeture' => '18:00', 'jours' => 'lun-sam', 'alerte_depart' => '20:00', 'heures' => self::HEURES,
    ];

    private static function employe(string $poste, ?string $role = null): User
    {
        $e = (new Employes())->setFonction($poste);
        $u = new User();
        if ($role !== null) {
            $u->setProfil((new RoleUtilisateur())->setCode($role)->setNom($role));
        }
        $e->setUser($u);

        return $u;
    }

    public function testHoraireTypeDApresLePosteOuLeRole(): void
    {
        self::assertSame(HoraireType::ACCUEIL, HoraireType::parDefaut('Caissière', null));
        self::assertSame(HoraireType::ACCUEIL, HoraireType::parDefaut('Caisse et commercial', 'ROLE_CAISSE_COMMANDE'));
        self::assertSame(HoraireType::ACCUEIL, HoraireType::parDefaut('Assistante de direction', null));
        self::assertSame(HoraireType::ACCUEIL, HoraireType::parDefaut('Secrétaire', null));
        self::assertSame(HoraireType::ATELIER, HoraireType::parDefaut('Opérateur production', null));
        self::assertSame(HoraireType::ATELIER, HoraireType::parDefaut('Commerciale', 'ROLE_CAISSE_COMMANDE'), 'le poste dit déjà autre chose que la caisse');
        // Poste vide ou seulement le contrat : le rôle décide.
        self::assertSame(HoraireType::ACCUEIL, HoraireType::parDefaut('CDI', 'ROLE_CAISSE_COMMANDE'));
        self::assertSame(HoraireType::ATELIER, HoraireType::parDefaut('', 'ROLE_GRAPHISTE'));
        self::assertSame(HoraireType::ATELIER, HoraireType::parDefaut(null, null));
    }

    public function testLaFicheRemplaceLePoste(): void
    {
        $u = self::employe('Graphiste PAO');
        self::assertSame(HoraireType::ATELIER, HoraireType::de($u->getEmploye()));

        $u->getEmploye()->setHoraireType(HoraireType::ACCUEIL);
        self::assertSame(HoraireType::ACCUEIL, HoraireType::de($u->getEmploye()));

        // Une valeur inconnue revient à « d'après le poste ».
        $u->getEmploye()->setHoraireType('nuit');
        self::assertNull($u->getEmploye()->getHoraireType());
    }

    public function testHeuresDues(): void
    {
        self::assertSame(480, HoraireType::minutesDues('09:00', '17:00'));
        self::assertSame(540, HoraireType::minutesDues('09:00', '18:00'));
        self::assertSame(480, HoraireType::minutesDues('09:00', '18:00', '13:00', '14:00'));
        self::assertSame(720, HoraireType::minutesDues('19:00', '07:00', '13:00', '14:00'), 'poste de nuit : la pause de midi ne le touche pas');
    }

    public function testNouveauCalculAPartirDeLaDateDeDebut(): void
    {
        self::assertFalse(Pointages::nouveauCalcul(self::REGLES, new \DateTimeImmutable('2026-10-17 23:59')));
        self::assertTrue(Pointages::nouveauCalcul(self::REGLES, new \DateTimeImmutable('2026-10-18 00:00')));
        // Sans date : toutes les journées.
        self::assertTrue(Pointages::nouveauCalcul(['heures' => ['debut' => null] + self::HEURES], new \DateTimeImmutable('2020-01-01')));
        self::assertFalse(Pointages::nouveauCalcul(['ouverture' => '08:00'], new \DateTimeImmutable('2026-10-20')));
    }

    public function testReglesDUneJournee(): void
    {
        $caisse = self::employe('Caissière');

        // Avant la date de début : ancien barème, heures de l'atelier.
        $avant = Pointages::reglesDe($caisse, self::REGLES, new \DateTimeImmutable('2026-10-17 09:00'));
        self::assertArrayNotHasKey('points', $avant);
        self::assertArrayNotHasKey('heures', $avant);
        self::assertSame('08:00', $avant['ouverture']);

        // Après : horaire Accueil et caisse, barème « heures faites ».
        $apres = Pointages::reglesDe($caisse, self::REGLES, new \DateTimeImmutable('2026-10-19 09:00'));
        self::assertSame(['09:00', '17:00'], [$apres['ouverture'], $apres['fermeture']]);
        self::assertSame(15, $apres['points']['points_retard']);

        // Horaires propres (gardien de nuit) : ils passent avant l'horaire type.
        $gardien = self::employe('Gardien de nuit');
        $gardien->getEmploye()->setHoraires('19:00', '07:00', [1, 2, 3, 4, 5, 6, 7]);
        $nuit = Pointages::reglesDe($gardien, self::REGLES, new \DateTimeImmutable('2026-10-19 19:00'));
        self::assertSame(['19:00', '07:00', '09:00'], [$nuit['ouverture'], $nuit['fermeture'], $nuit['alerte_depart']]);
        self::assertSame('1,2,3,4,5,6,7', $nuit['jours']);
        self::assertSame('Horaires propres', Pointages::horaireDe($gardien, self::HEURES)['libelle']);
        self::assertSame('Atelier et autres', Pointages::horaireDe(self::employe('Livreur'), self::HEURES)['libelle']);

        // Les réglages de la tuile changent l'horaire type.
        $huit = Pointages::reglesDe($caisse, ['heures' => ['accueil_debut' => '08:00'] + self::HEURES] + self::REGLES, new \DateTimeImmutable('2026-10-19 09:00'));
        self::assertSame('08:00', $huit['ouverture']);
    }
}
