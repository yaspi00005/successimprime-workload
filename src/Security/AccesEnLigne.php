<?php

namespace App\Security;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Repository\AppareilAutoriseRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Règles d'accès à l'administration en ligne (maquettes 38 et CX03) :
 * appareil autorisé + mot de passe + code reçu par e-mail (à l'adresse
 * de la fiche employé, par SMS sans adresse), session fermée
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
    public const VALIDITE_INVITATION_SECONDES = 300;
    public const ESSAIS_MAX = CodeDeConnexion::ESSAIS_MAX;

    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        private readonly AppareilAutoriseRepository $appareils,
        private readonly EntityManagerInterface $em,
        private readonly CodeDeConnexion $codes,
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
     * Envoie un nouveau code à l'e-mail de la fiche employé, ou par SMS à
     * son téléphone s'il n'a pas d'adresse (ou si l'e-mail n'est pas réglé
     * sur le serveur).
     *
     * @return array{envoye: bool, canal: ?string, destination: string, erreur: ?string, codeDev: ?string, limite: bool}
     */
    public function envoyerCode(User $utilisateur, SessionInterface $session, string $ip): array
    {
        $employe = $utilisateur->getEmploye();
        $envoi = $this->codes->envoyer(
            $session,
            self::SESSION_CODE,
            DestinationCode::vers((string) $employe?->getTelephone(), $employe?->getEmail()),
            CodeDeConnexion::PERSONNEL,
            $ip,
        );
        // Trop de codes : sans code en attente, la page du code renverrait à
        // l'accueil, qui en redemanderait un. Elle s'affiche avec l'erreur.
        if ($envoi['limite'] && !$this->codes->enAttente($session, self::SESSION_CODE)) {
            $this->codes->sansEnvoi($session, self::SESSION_CODE);
        }

        return $envoi;
    }

    public function codeEnAttente(SessionInterface $session): bool
    {
        return $this->codes->enAttente($session, self::SESSION_CODE);
    }

    public function secondesAvantRenvoi(SessionInterface $session): int
    {
        return $this->codes->secondesAvantRenvoi($session, self::SESSION_CODE);
    }

    /** @return 'ok'|'faux'|'expire'|'trop_essais' */
    public function verifierCode(SessionInterface $session, string $saisi): string
    {
        $resultat = $this->codes->verifier($session, self::SESSION_CODE, $saisi);
        if ('ok' === $resultat) {
            $session->set(self::SESSION_ACTIVITE, time());
        }

        return $resultat;
    }

    public static function masquer(string $telephone): string
    {
        return DestinationCode::masquerTelephone($telephone);
    }
}
