<?php

namespace App\Security;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Repository\AppareilAutoriseRepository;
use App\Service\NotificationService;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Règles d'accès à l'administration en ligne (maquettes 38 et CX03) :
 * appareil autorisé + mot de passe + code reçu par SMS, session fermée
 * après 15 minutes sans activité. Rien de tout ça ne s'applique sur le
 * serveur local de l'atelier (APP_EN_LIGNE=0).
 */
final class AccesEnLigne
{
    public const COOKIE = 'si_appareil';
    public const SESSION_CODE = 'si_code_securite';
    public const SESSION_ACTIVITE = 'si_derniere_activite';
    public const SESSION_APPAREIL = 'si_appareil_id';
    public const INACTIVITE_SECONDES = 900;
    public const VALIDITE_CODE_SECONDES = 300;
    public const VALIDITE_INVITATION_SECONDES = 300;
    public const DELAI_RENVOI_SECONDES = 60;
    public const ESSAIS_MAX = 5;

    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        private readonly AppareilAutoriseRepository $appareils,
        private readonly EntityManagerInterface $em,
        private readonly OrangeSmsService $sms,
        private readonly NotificationService $notifications,
    ) {
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /* ---------- Appareil ---------- */

    public function appareilDe(Request $request): ?AppareilAutorise
    {
        $jeton = (string) $request->cookies->get(self::COOKIE, '');
        if (\strlen($jeton) < 32) {
            return null;
        }
        $appareil = $this->appareils->parEmpreinte(AppareilAutorise::empreinte($jeton));

        return $appareil && $appareil->estAutorise() ? $appareil : null;
    }

    public function appareilAutorisePour(User $utilisateur, Request $request): ?AppareilAutorise
    {
        $appareil = $this->appareilDe($request);

        return $appareil && $appareil->getUtilisateur() === $utilisateur ? $appareil : null;
    }

    /** Note une tentative depuis un appareil inconnu et prévient les admins une fois par jour et par appareil (sauf adresse bloquée). */
    public function noterRefus(string $identifiant, Request $request): void
    {
        $ip = (string) $request->getClientIp();
        $navigateur = (string) $request->headers->get('User-Agent', '');
        $existant = $this->appareils->refusDuJour($ip, $navigateur, new \DateTimeImmutable('today'));

        if ($existant) {
            $existant->setDerniereConnexion(new \DateTimeImmutable())->setIdentifiantTente($identifiant);
            $this->em->flush();

            return;
        }

        $bloque = $this->appareils->estBloque($ip);
        $refus = (new AppareilAutorise())
            ->setNom('Appareil inconnu')
            ->setEtat($bloque ? AppareilAutorise::ETAT_BLOQUE : AppareilAutorise::ETAT_REFUSE)
            ->setIp($ip)
            ->setNavigateur($navigateur)
            ->setIdentifiantTente($identifiant)
            ->setDerniereConnexion(new \DateTimeImmutable());
        $this->em->persist($refus);
        $this->em->flush();

        // Adresse bloquée par un administrateur : on note sans le déranger à nouveau.
        if ($bloque) {
            return;
        }
        $this->notifications->notifierRoles(
            ['ROLE_ADMIN'],
            \sprintf('Connexion en ligne refusée : appareil inconnu (identifiant « %s »).', $identifiant),
            'app_appareils_index',
        );
    }

    /**
     * Invitation affichée en QR dans les réglages, valable 5 minutes.
     *
     * @return string le jeton à mettre dans le lien
     */
    public function creerInvitation(User $utilisateur, string $role, string $nom, ?User $creePar): string
    {
        $jeton = bin2hex(random_bytes(24));
        $invitation = (new AppareilAutorise())
            ->setUtilisateur($utilisateur)
            ->setRoleEnLigne($role)
            ->setNom($nom)
            ->setCreePar($creePar)
            ->setEmpreinte(AppareilAutorise::empreinte($jeton))
            ->setExpireLe(new \DateTimeImmutable('+'.self::VALIDITE_INVITATION_SECONDES.' seconds'));
        $this->em->persist($invitation);
        $this->em->flush();

        return $jeton;
    }

    public function invitation(string $jeton): ?AppareilAutorise
    {
        $invitation = $this->appareils->parEmpreinte(AppareilAutorise::empreinte($jeton));

        return $invitation && $invitation->invitationValide(new \DateTimeImmutable()) ? $invitation : null;
    }

    /** L'appareil qui a ouvert le lien devient autorisé ; renvoie le cookie à poser. */
    public function accepterInvitation(AppareilAutorise $invitation, Request $request): Cookie
    {
        $jeton = bin2hex(random_bytes(32));
        $invitation
            ->setEmpreinte(AppareilAutorise::empreinte($jeton))
            ->setEtat(AppareilAutorise::ETAT_AUTORISE)
            ->setAutoriseLe(new \DateTimeImmutable())
            ->setExpireLe(null)
            ->setIp($request->getClientIp())
            ->setNavigateur((string) $request->headers->get('User-Agent', ''));
        $this->em->flush();

        return Cookie::create(self::COOKIE, $jeton)
            ->withExpires(new \DateTimeImmutable('+2 years'))
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /* ---------- Code de sécurité ---------- */

    /**
     * Envoie un nouveau code par SMS au téléphone de la fiche employé.
     *
     * @return array{envoye: bool, telephone: string, erreur: ?string, codeDev: ?string}
     */
    public function envoyerCode(User $utilisateur, SessionInterface $session): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $session->set(self::SESSION_CODE, [
            'empreinte' => $this->empreinteCode($code),
            'expire' => time() + self::VALIDITE_CODE_SECONDES,
            'essais' => 0,
            'envoye' => time(),
        ]);

        $telephone = (string) $utilisateur->getEmploye()?->getTelephone();
        $resultat = '' === $telephone
            ? ['succes' => false, 'erreur' => 'Aucun téléphone sur la fiche employé.']
            : $this->sms->envoyerSms($telephone, \sprintf('Succès Imprim : votre code de sécurité est %s. Valable 5 minutes. Ne le donnez à personne.', $code));

        $envoye = (bool) ($resultat['succes'] ?? false);

        return [
            'envoye' => $envoye,
            'telephone' => self::masquer($telephone),
            'erreur' => $envoye ? null : ($resultat['erreur'] ?? 'Envoi impossible.'),
            // Hors production seulement, pour tester sans contrat SMS.
            'codeDev' => !$envoye && 'prod' !== $this->environnement ? $code : null,
        ];
    }

    public function codeEnAttente(SessionInterface $session): bool
    {
        return \is_array($session->get(self::SESSION_CODE));
    }

    public function secondesAvantRenvoi(SessionInterface $session): int
    {
        $etat = $session->get(self::SESSION_CODE);

        return \is_array($etat) ? max(0, $etat['envoye'] + self::DELAI_RENVOI_SECONDES - time()) : 0;
    }

    /** @return 'ok'|'faux'|'expire'|'trop_essais' */
    public function verifierCode(SessionInterface $session, string $saisi): string
    {
        $etat = $session->get(self::SESSION_CODE);
        if (!\is_array($etat) || $etat['expire'] < time()) {
            return 'expire';
        }
        if ($etat['essais'] >= self::ESSAIS_MAX) {
            return 'trop_essais';
        }
        if (hash_equals($etat['empreinte'], $this->empreinteCode(preg_replace('/\D/', '', $saisi) ?? ''))) {
            $session->remove(self::SESSION_CODE);
            $session->set(self::SESSION_ACTIVITE, time());

            return 'ok';
        }
        ++$etat['essais'];
        $session->set(self::SESSION_CODE, $etat);

        return $etat['essais'] >= self::ESSAIS_MAX ? 'trop_essais' : 'faux';
    }

    private function empreinteCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
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
}
