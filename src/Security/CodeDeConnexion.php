<?php

namespace App\Security;

use App\Service\Messagerie\JournalEnvois;
use App\Service\OrangeSmsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Code à 6 chiffres gardé en session (seulement son empreinte) : valable
 * 5 minutes, 5 essais, renvoi après 60 secondes. Il part par e-mail quand
 * la personne a une adresse et que l'e-mail est réglé sur le serveur
 * (MAILER_DSN, MAILER_FROM_ADDRESS), sinon par SMS ; si l'e-mail échoue,
 * le SMS prend le relais. Chaque envoi compte dans les limites par
 * destinataire (numéro et adresse) et par adresse IP, quel que soit le
 * moyen. Le message donne le code, sa durée et l'avertissement de ne le
 * donner à personne : jamais de montant ni de lien qui connecte.
 *
 * Sert au code de sécurité de l'administration en ligne (CX03) et à la
 * connexion de l'espace client (CX01).
 */
final class CodeDeConnexion
{
    public const VALIDITE_SECONDES = 300;
    public const DELAI_RENVOI_SECONDES = 60;
    public const ESSAIS_MAX = 5;

    public const EMAIL = 'email';
    public const SMS = 'sms';

    /** Usage du code : espace client ou administration en ligne (personnel). */
    public const ESPACE = 'espace';
    public const PERSONNEL = 'personnel';
    /** Essais d'un code d'accès donné par la boutique sur WhatsApp (EspaceClientComptes::ouvrirParAcces). */
    public const ACCES = 'acces';

    public const TROP_DE_CODES = 'Trop de codes demandés. Réessayez dans quelques minutes.';

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        private readonly OrangeSmsService $sms,
        // Envoi direct, sans file d'attente : le code doit partir tout de suite (et un échec
        // se voir tout de suite, pour le SMS). Paresseux : rien ne casse sans e-mail configuré.
        #[Autowire(service: 'mailer.transports', lazy: true)] private readonly TransportInterface $mailer,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
        #[Autowire(service: 'limiter.code_connexion_destinataire')] private readonly RateLimiterFactoryInterface $parDestinataire,
        #[Autowire(service: 'limiter.code_connexion_ip')] private readonly RateLimiterFactoryInterface $parAdresseIp,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** L'e-mail peut-il partir de ce serveur ? Sinon les codes partent par SMS. */
    public function emailPret(): bool
    {
        return '' !== trim($this->expediteur) && '' !== trim($this->mailerDsn) && !str_starts_with(trim($this->mailerDsn), 'null://');
    }

    /** Moyen choisi pour cette destination : e-mail, SMS, ou null (ni adresse ni numéro). */
    public function canal(DestinationCode $destination): ?string
    {
        if (null !== $destination->email && $this->emailPret()) {
            return self::EMAIL;
        }

        return '' !== $destination->telephone ? self::SMS : null;
    }

    /**
     * Prépare un nouveau code et l'envoie. Au-delà des limites, rien ne
     * change en session (le code déjà envoyé reste bon) et « limite » est vrai.
     *
     * @param self::ESPACE|self::PERSONNEL $usage
     * @param array<string, mixed>         $donnees gardées avec le code (numéro, nom…)
     *
     * @return array{envoye: bool, canal: ?string, destination: string, erreur: ?string, codeDev: ?string, limite: bool}
     */
    public function envoyer(SessionInterface $session, string $cle, DestinationCode $destination, string $usage, string $ip, array $donnees = []): array
    {
        $canal = $this->canal($destination);
        if (!$this->dansLesLimites($destination, $canal, $usage, $ip)) {
            return ['envoye' => false, 'canal' => null, 'destination' => '', 'erreur' => self::TROP_DE_CODES, 'codeDev' => null, 'limite' => true];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $session->set($cle, [
            'empreinte' => $this->empreinte($code),
            'expire' => time() + self::VALIDITE_SECONDES,
            'essais' => 0,
            'envoye' => time(),
            'donnees' => $donnees,
        ]);

        if (DestinationCode::INCONNUE === $destination->type) {
            // Numéro inconnu : rien ne part (pas de message payé pour rien), la réponse est celle d'un SMS.
            return ['envoye' => true, 'canal' => self::SMS, 'destination' => DestinationCode::masquerTelephone($destination->telephone), 'erreur' => null, 'codeDev' => null, 'limite' => false];
        }

        $erreur = match (true) {
            DestinationCode::DEMONSTRATION === $destination->type => 'Fiche de démonstration : aucun message n\'est envoyé.',
            null === $canal => 'Ni adresse e-mail ni numéro de téléphone.',
            default => null,
        };
        if (null === $erreur && self::EMAIL === $canal) {
            if ($this->parEmail((string) $destination->email, $code, $usage)) {
                return $this->resultat(true, self::EMAIL, DestinationCode::masquerEmail((string) $destination->email), null, $code);
            }
            // E-mail refusé par le serveur d'envoi : le SMS prend le relais s'il y a un numéro.
            $canal = '' !== $destination->telephone ? self::SMS : null;
            $erreur = null === $canal ? 'L\'e-mail n\'a pas pu partir.' : null;
        }
        if (null === $erreur) {
            $resultat = $this->sms->envoyerSms($destination->telephone, \sprintf('Succès Imprim : votre %s est %s. Valable 5 minutes. Ne le donnez à personne.', self::nom($usage), $code), type: 'code_connexion');
            $envoye = (bool) ($resultat['succes'] ?? false);
            $erreur = $envoye ? null : ($resultat['erreur'] ?? 'Envoi impossible.');
        }

        return $this->resultat(null === $erreur, $canal, self::EMAIL === $canal ? DestinationCode::masquerEmail((string) $destination->email) : DestinationCode::masquerTelephone($destination->telephone), $erreur, $code);
    }

    /**
     * Garde en session un code qui ne part jamais (la saisie sera toujours
     * fausse) : pour qu'une page qui attend un code s'affiche quand même.
     */
    public function sansEnvoi(SessionInterface $session, string $cle): void
    {
        $session->set($cle, [
            'empreinte' => $this->empreinte(bin2hex(random_bytes(8))),
            'expire' => time() + self::VALIDITE_SECONDES,
            'essais' => 0,
            'envoye' => time(),
            'donnees' => [],
        ]);
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

    /** Retire une donnée gardée avec le code (par exemple une adresse qui n'a pas reçu le code). */
    public function oublier(SessionInterface $session, string $cle, string $donnee): void
    {
        $etat = $session->get($cle);
        if (\is_array($etat) && \is_array($etat['donnees'] ?? null)) {
            unset($etat['donnees'][$donnee]);
            $session->set($cle, $etat);
        }
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
            'faux' => 'Code incorrect. Vérifiez le message reçu et réessayez.',
            'expire' => 'Ce code a expiré. Demandez-en un nouveau.',
            default => 'Trop d\'essais. Demandez un nouveau code.',
        };
    }

    /**
     * Un essai de code d'accès (première connexion) compte dans les mêmes
     * limites qu'un code envoyé : par numéro et par adresse IP.
     */
    public function essaiPermis(string $telephone, string $ip): bool
    {
        $numero = \App\Entity\CompteClient::normaliserTelephone($telephone) ?? $telephone;

        return $this->dansLesLimites(DestinationCode::vers($numero, null), self::SMS, self::ACCES, $ip);
    }

    /**
     * Limites de config/packages/rate_limiter.yaml : le numéro, l'adresse
     * e-mail si le code y part, et l'adresse IP. Comptées pour tout envoi,
     * même vers un numéro inconnu (même réponse pour tous).
     */
    private function dansLesLimites(DestinationCode $destination, ?string $canal, string $usage, string $ip): bool
    {
        $cles = [];
        $chiffres = preg_replace('/\D/', '', $destination->telephone) ?? '';
        if ('' !== $chiffres) {
            $cles[] = $usage.'|tel|'.$chiffres;
        }
        if (self::EMAIL === $canal) {
            $cles[] = $usage.'|mail|'.$destination->email;
        }
        foreach ($cles as $cle) {
            if (!$this->parDestinataire->create($cle)->consume()->isAccepted()) {
                return false;
            }
        }

        return $this->parAdresseIp->create($usage.'|'.$ip)->consume()->isAccepted();
    }

    private function parEmail(string $adresse, string $code, string $usage): bool
    {
        try {
            $expediteur = Address::create(trim($this->expediteur));
            if ('' === $expediteur->getName()) {
                $expediteur = new Address($expediteur->getAddress(), 'Succès Imprim');
            }
            $this->mailer->send(JournalEnvois::typer((new Email())
                ->from($expediteur)
                ->to($adresse)
                ->subject(\sprintf('Votre %s Succès Imprim', self::nom($usage)))
                ->text(self::texteEmail($code, $usage)), 'code_connexion'));

            return true;
        } catch (\Throwable $e) {
            // Jamais le code dans le journal.
            $this->logger->warning('Code de connexion par e-mail non envoyé : {erreur}', ['erreur' => $e->getMessage()]);

            return false;
        }
    }

    /** Texte de l'e-mail : le code, sa durée, l'avertissement. Ni montant ni lien. */
    public static function texteEmail(string $code, string $usage): string
    {
        $pour = self::PERSONNEL === $usage ? 'l\'administration en ligne de Succès Imprim' : 'votre espace client Succès Imprim';
        $inconnu = self::PERSONNEL === $usage
            ? 'Vous n\'essayez pas de vous connecter ? Prévenez la direction : quelqu\'un connaît votre mot de passe.'
            : 'Vous n\'avez rien demandé ? Ignorez ce message : sans ce code, personne ne peut entrer dans votre espace.';

        return \sprintf(
            "Bonjour,\n\nVoici votre %s pour %s :\n\n    %s\n\nIl est valable 5 minutes et ne sert qu'une fois.\n\nNe le donnez à personne, même à quelqu'un qui dit travailler chez Succès Imprim : nous ne vous le demanderons jamais.\n\n%s\n\nSuccès Imprim · Bamako\n",
            self::nom($usage),
            $pour,
            $code,
            $inconnu,
        );
    }

    private static function nom(string $usage): string
    {
        return self::PERSONNEL === $usage ? 'code de sécurité' : 'code de connexion';
    }

    /** @return array{envoye: bool, canal: ?string, destination: string, erreur: ?string, codeDev: ?string, limite: bool} */
    private function resultat(bool $envoye, ?string $canal, string $destination, ?string $erreur, string $code): array
    {
        return [
            'envoye' => $envoye,
            'canal' => $canal,
            'destination' => $destination,
            'erreur' => $erreur,
            // Hors production seulement, pour tester sans e-mail ni contrat SMS.
            'codeDev' => !$envoye && 'prod' !== $this->environnement ? $code : null,
            'limite' => false,
        ];
    }

    private function empreinte(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
