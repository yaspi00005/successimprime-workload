<?php

namespace App\Service\Messagerie;

use App\Entity\EnvoiMessage;
use App\Repository\EnvoiMessageRepository;
use App\Service\OrangeSmsService;
use App\Service\Parametres\Parametres;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crédits SMS et e-mail (Clients › Crédits SMS et e-mail,
 * docs/messagerie-marketing.md) : ce qu'il reste à envoyer et jusqu'à quand.
 *
 * SMS : avec les clés Orange, le solde (unités restantes, fin du pack) et
 * les achats de packs sont lus chez Orange, gardés une heure (bouton
 * « Actualiser » pour relire) ; sans les clés, la direction saisit le solde
 * et la fin du pack, et les SMS partis depuis la saisie en sont retirés.
 *
 * E-mail : les envois comptés dans l'historique, comparés aux limites de
 * l'hébergeur et à la fin de l'abonnement saisies dans la tuile.
 */
final class CreditsMessages
{
    public const TUILE = 'credits_messages';

    public function __construct(
        private readonly OrangeSmsService $sms,
        private readonly SoldeOrange $orange,
        private readonly EnvoiMessageRepository $envois,
        private readonly Parametres $parametres,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
    ) {
    }

    /**
     * État du crédit SMS.
     *
     * @return array{configure: bool, source: string, unites: ?int, expiration: ?\DateTimeImmutable, luLe: ?\DateTimeImmutable, erreur: ?string, contrats: list<array<string, mixed>>, achats: list<array<string, mixed>>, saisi: ?int, saisiLe: ?\DateTimeImmutable, consommesDepuis: int, aujourdhui: int, mois: int, unitesMois: int, echecsJour: int}
     */
    public function sms(\DateTimeImmutable $maintenant, bool $actualiser = false): array
    {
        $configure = $this->sms->estConfigure();
        $orange = $configure ? $this->orange->lire($maintenant, $actualiser) : null;
        $reglage = $this->reglages();

        $saisi = null;
        $consommes = 0;
        $saisiLe = self::date((string) $reglage['sms_saisi_le'], 'Y-m-d H:i:s');
        if (null !== $saisiLe) {
            $saisi = (int) $reglage['sms_solde_manuel'];
            $consommes = $this->envois->unitesDepuis(EnvoiMessage::SMS, $saisiLe);
        }

        $etat = [
            'configure' => $configure, 'source' => 'aucune', 'unites' => null, 'expiration' => null, 'luLe' => null,
            'erreur' => $orange['erreur'] ?? null, 'contrats' => [], 'achats' => [],
            'saisi' => $saisi, 'saisiLe' => $saisiLe, 'consommesDepuis' => $consommes,
            'aujourdhui' => $this->envois->compterEnvoyes(EnvoiMessage::SMS, $maintenant->setTime(0, 0)),
            'mois' => $this->envois->compterEnvoyes(EnvoiMessage::SMS, $maintenant->modify('first day of this month')->setTime(0, 0)),
            'unitesMois' => $this->envois->unitesDepuis(EnvoiMessage::SMS, $maintenant->modify('first day of this month')->setTime(0, 0)),
            'echecsJour' => $this->envois->compterEchecs(EnvoiMessage::SMS, $maintenant->setTime(0, 0)),
        ];

        if (null !== $orange && null !== $orange['contrats']) {
            $resume = SoldeOrange::resumerContrats($orange['contrats']);

            return ['source' => 'orange', 'unites' => $resume['unites'], 'expiration' => $resume['expiration'], 'luLe' => $orange['luLe'], 'contrats' => $orange['contrats'], 'achats' => $orange['achats']] + $etat;
        }
        if (null !== $saisi) {
            $expiration = self::finDuJour((string) $reglage['sms_expiration_manuelle']);

            return ['source' => 'manuel', 'unites' => max(0, $saisi - $consommes), 'expiration' => $expiration, 'luLe' => $saisiLe] + $etat;
        }

        return $etat;
    }

    /**
     * État de la messagerie e-mail : envois de l'heure (60 dernières
     * minutes), du jour et du mois, limites de l'hébergeur et ce qu'il en
     * reste, fin de l'abonnement.
     *
     * @return array{configure: bool, hebergeur: string, expiration: ?\DateTimeImmutable, compte: array{heure: int, jour: int, mois: int}, quotas: array{heure: int, jour: int, mois: int}, restants: array{heure: ?int, jour: ?int, mois: ?int}, echecsJour: int}
     */
    public function email(\DateTimeImmutable $maintenant): array
    {
        $reglage = $this->reglages();
        $compte = [
            'heure' => $this->envois->compterEnvoyes(EnvoiMessage::EMAIL, $maintenant->modify('-1 hour')),
            'jour' => $this->envois->compterEnvoyes(EnvoiMessage::EMAIL, $maintenant->setTime(0, 0)),
            'mois' => $this->envois->compterEnvoyes(EnvoiMessage::EMAIL, $maintenant->modify('first day of this month')->setTime(0, 0)),
        ];
        $quotas = [
            'heure' => (int) $reglage['email_quota_heure'],
            'jour' => (int) $reglage['email_quota_jour'],
            'mois' => (int) $reglage['email_quota_mois'],
        ];

        return [
            'configure' => '' !== trim($this->expediteur) && '' !== trim($this->mailerDsn) && !str_starts_with(trim($this->mailerDsn), 'null://'),
            'hebergeur' => trim((string) $reglage['email_hebergeur']),
            'expiration' => self::finDuJour((string) $reglage['email_expiration']),
            'compte' => $compte,
            'quotas' => $quotas,
            'restants' => self::restants($quotas, $compte),
            'echecsJour' => $this->envois->compterEchecs(EnvoiMessage::EMAIL, $maintenant->setTime(0, 0)),
        ];
    }

    /** Seuil d'alerte du solde SMS et délai avant l'expiration (tuile). */
    public function seuils(): array
    {
        $reglage = $this->reglages();

        return ['seuil_sms' => (int) $reglage['seuil_sms'], 'alerte_jours' => (int) $reglage['alerte_jours']];
    }

    /**
     * Les conditions d'alerte présentes : clé => [valeur, message]. La
     * valeur change quand la situation change (nouvelle date de fin) : une
     * nouvelle notification part alors. Sans montant.
     *
     * @param array{unites: ?int, expiration: ?\DateTimeImmutable} $sms
     * @param array{expiration: ?\DateTimeImmutable}              $email
     *
     * @return array<string, array{valeur: string, message: string}>
     */
    public static function alertes(array $sms, array $email, int $seuil, int $jours, \DateTimeImmutable $maintenant): array
    {
        $alertes = [];
        if (null !== $sms['unites'] && $sms['unites'] < $seuil) {
            $alertes['sms_seuil'] = ['valeur' => 'sous_'.$seuil, 'message' => \sprintf('Crédits SMS : il reste %s SMS, moins que le seuil de %s.', self::nombre($sms['unites']), self::nombre($seuil))];
        }
        $limite = $maintenant->modify('+'.$jours.' days');
        foreach (['sms_expiration' => [$sms['expiration'], 'Crédits SMS : le pack'], 'email_expiration' => [$email['expiration'], 'Messagerie e-mail : l\'abonnement']] as $cle => [$fin, $quoi]) {
            if (null !== $fin && $fin <= $limite) {
                $alertes[$cle] = ['valeur' => $fin->format('Y-m-d'), 'message' => \sprintf('%s %s le %s.', $quoi, $fin < $maintenant ? 'a expiré' : 'expire', $fin->format('d/m/Y'))];
            }
        }

        return $alertes;
    }

    /**
     * Ce qu'il reste de chaque limite (null : pas de limite connue).
     *
     * @param array{heure: int, jour: int, mois: int} $quotas
     * @param array{heure: int, jour: int, mois: int} $compte
     *
     * @return array{heure: ?int, jour: ?int, mois: ?int}
     */
    public static function restants(array $quotas, array $compte): array
    {
        $restants = [];
        foreach (['heure', 'jour', 'mois'] as $periode) {
            $restants[$periode] = $quotas[$periode] > 0 ? max(0, $quotas[$periode] - $compte[$periode]) : null;
        }

        return $restants;
    }

    /** @return array<string, mixed> */
    private function reglages(): array
    {
        $valeurs = [];
        foreach (['seuil_sms', 'alerte_jours', 'email_hebergeur', 'email_quota_heure', 'email_quota_jour', 'email_quota_mois', 'email_expiration', 'sms_solde_manuel', 'sms_expiration_manuelle', 'sms_saisi_le'] as $cle) {
            $valeurs[$cle] = $this->parametres->valeur(self::TUILE, $cle);
        }

        return $valeurs;
    }

    /** « 2026-11-30 » rangé par la tuile : valable jusqu'à 23 h 59 ce jour-là. Vide ou illisible : pas de date. */
    private static function finDuJour(string $jour): ?\DateTimeImmutable
    {
        return self::date($jour.' 23:59:59', 'Y-m-d H:i:s');
    }

    private static function date(string $texte, string $format): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, trim($texte));

        return false !== $date && $date->format($format) === trim($texte) ? $date : null;
    }

    private static function nombre(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
