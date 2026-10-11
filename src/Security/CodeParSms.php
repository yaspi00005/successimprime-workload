<?php

namespace App\Security;

use App\Service\OrangeSmsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Code à 6 chiffres envoyé par SMS et gardé en session (seulement son
 * empreinte) : valable 5 minutes, 5 essais, renvoi après 60 secondes.
 * Sert au code de sécurité de l'administration en ligne (CX03) et à la
 * connexion de l'espace client (CX01).
 */
final class CodeParSms
{
    public const VALIDITE_SECONDES = 300;
    public const DELAI_RENVOI_SECONDES = 60;
    public const ESSAIS_MAX = 5;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        private readonly OrangeSmsService $sms,
    ) {
    }

    /**
     * @param string $message texte du SMS, %s est remplacé par le code
     * @param array<string, mixed> $donnees gardées avec le code (numéro, nom…)
     *
     * @return array{envoye: bool, telephone: string, erreur: ?string, codeDev: ?string}
     */
    public function envoyer(SessionInterface $session, string $cle, string $telephone, string $message, array $donnees = []): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $session->set($cle, [
            'empreinte' => $this->empreinte($code),
            'expire' => time() + self::VALIDITE_SECONDES,
            'essais' => 0,
            'envoye' => time(),
            'donnees' => $donnees,
        ]);

        $resultat = '' === $telephone
            ? ['succes' => false, 'erreur' => 'Aucun numéro de téléphone.']
            : $this->sms->envoyerSms($telephone, \sprintf($message, $code));
        $envoye = (bool) ($resultat['succes'] ?? false);

        return [
            'envoye' => $envoye,
            'telephone' => self::masquer($telephone),
            'erreur' => $envoye ? null : ($resultat['erreur'] ?? 'Envoi impossible.'),
            // Hors production seulement, pour tester sans contrat SMS.
            'codeDev' => !$envoye && 'prod' !== $this->environnement ? $code : null,
        ];
    }

    public function enAttente(SessionInterface $session, string $cle): bool
    {
        return \is_array($session->get($cle));
    }

    /** @return array<string, mixed> */
    public function donnees(SessionInterface $session, string $cle): array
    {
        $etat = $session->get($cle);

        return \is_array($etat) ? (array) ($etat['donnees'] ?? []) : [];
    }

    public function secondesAvantRenvoi(SessionInterface $session, string $cle): int
    {
        $etat = $session->get($cle);

        return \is_array($etat) ? max(0, $etat['envoye'] + self::DELAI_RENVOI_SECONDES - time()) : 0;
    }

    /**
     * Un code juste est consommé : il ne sert qu'une fois.
     *
     * @return 'ok'|'faux'|'expire'|'trop_essais'
     */
    public function verifier(SessionInterface $session, string $cle, string $saisi): string
    {
        $etat = $session->get($cle);
        if (!\is_array($etat) || $etat['expire'] < time()) {
            return 'expire';
        }
        if ($etat['essais'] >= self::ESSAIS_MAX) {
            return 'trop_essais';
        }
        if (hash_equals($etat['empreinte'], $this->empreinte(preg_replace('/\D/', '', $saisi) ?? ''))) {
            $session->remove($cle);

            return 'ok';
        }
        ++$etat['essais'];
        $session->set($cle, $etat);

        return $etat['essais'] >= self::ESSAIS_MAX ? 'trop_essais' : 'faux';
    }

    public static function messageErreur(string $resultat): string
    {
        return match ($resultat) {
            'faux' => 'Code incorrect. Vérifiez le SMS et réessayez.',
            'expire' => 'Ce code a expiré. Demandez-en un nouveau.',
            default => 'Trop d\'essais. Demandez un nouveau code.',
        };
    }

    public static function masquer(string $telephone): string
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

    private function empreinte(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
