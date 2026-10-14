<?php

namespace App\Security;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Connexion à l'espace client (maquette CX01) : numéro de téléphone et
 * mot de passe ; ou, pour la première connexion et le mot de passe
 * oublié, numéro et code reçu par e-mail (par SMS sans adresse) ; ou
 * numéro et code d'accès donné par la boutique sur WhatsApp (page
 * « Première connexion »). Après un code, la personne passe par
 * « Sécuriser mon compte » (mot de passe, e-mail) avant d'entrer.
 */
final class EspaceClientAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    /** Même réponse que le code soit faux, expiré, déjà servi ou remplacé. */
    public const ACCES_REFUSE = 'Code d\'accès incorrect ou expiré. Vérifiez le message reçu, ou demandez un nouvel accès à Succès Imprim.';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly CodeDeConnexion $codes,
        private readonly EspaceClientComptes $comptes,
        private readonly CompteClientRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && 'app_espace_connexion' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $badges = [new CsrfTokenBadge('espace-connexion', (string) $request->request->get('_csrf_token'))];
        if ($request->request->getBoolean('_remember_me')) {
            $badges[] = new RememberMeBadge();
        }

        if ('mdp' === $request->request->get('mode')) {
            $telephone = CompteClient::normaliserTelephone((string) $request->request->get('telephone'));
            $session->set(SecurityRequestAttributes::LAST_USERNAME, (string) $request->request->get('telephone'));
            if (null === $telephone) {
                throw new CustomUserMessageAuthenticationException('Numéro de téléphone invalide.');
            }

            return new Passport(
                new UserBadge($telephone),
                new PasswordCredentials((string) $request->request->get('password')),
                $badges,
            );
        }

        if ('acces' === $request->request->get('mode')) {
            // Code d'accès donné par la boutique sur WhatsApp (page « Première connexion »).
            $session->set(SecurityRequestAttributes::LAST_USERNAME, (string) $request->request->get('telephone'));
            $telephone = CompteClient::normaliserTelephone((string) $request->request->get('telephone'));
            if (null === $telephone) {
                throw new CustomUserMessageAuthenticationException('Numéro de téléphone invalide.');
            }
            // Le jeton d'abord : un code ne se consomme jamais depuis un autre site.
            if (!$this->csrf->isTokenValid(new CsrfToken('espace-connexion', (string) $request->request->get('_csrf_token')))) {
                throw new InvalidCsrfTokenException('Invalid CSRF token.');
            }
            if (!$this->codes->essaiPermis($telephone, (string) $request->getClientIp())) {
                throw new CustomUserMessageAuthenticationException('Trop d\'essais. Réessayez dans quelques minutes.');
            }
            $compte = $this->comptes->ouvrirParAcces($telephone, (string) $request->request->get('code'), new \DateTimeImmutable());
            if (null === $compte) {
                throw new CustomUserMessageAuthenticationException(self::ACCES_REFUSE);
            }

            return new SelfValidatingPassport(new UserBadge($compte->getTelephone(), fn () => $compte), [$badges[0]]);
        }

        $donnees = $this->codes->donnees($session, EspaceClientComptes::SESSION_CODE);
        if (!isset($donnees['telephone'])) {
            throw new CustomUserMessageAuthenticationException('Demandez d\'abord un code.');
        }
        $saisi = $request->request->all()['code'] ?? '';
        $resultat = $this->codes->verifier($session, EspaceClientComptes::SESSION_CODE, \is_array($saisi) ? implode('', $saisi) : (string) $saisi);
        if ('ok' !== $resultat) {
            throw new CustomUserMessageAuthenticationException(CodeDeConnexion::messageErreur($resultat));
        }

        $compte = $this->comptes->ouvrir($donnees);
        if (null === $compte) {
            throw new CustomUserMessageAuthenticationException('Aucun compte client pour ce numéro. Créez votre compte.');
        }

        return new SelfValidatingPassport(new UserBadge($compte->getTelephone(), fn () => $compte), $badges);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $session = $request->getSession();
        $par = match ($request->request->get('mode')) {
            'mdp' => null,
            'acces' => EspaceClientComptes::PAR_ACCES,
            default => EspaceClientComptes::PAR_CODE,
        };
        null === $par ? $session->remove(EspaceClientComptes::SESSION_A_FINIR) : $session->set(EspaceClientComptes::SESSION_A_FINIR, $par);

        $compte = $token->getUser();
        if ($compte instanceof CompteClient) {
            $this->repository->find($compte->getId())?->setDerniereConnexion(new \DateTimeImmutable());
            $this->em->flush();
            // Mot de passe à choisir, e-mail à donner : avant d'entrer.
            $etapes = EspaceClientComptes::etapes($compte, $par);
            if (null !== $etapes['motDePasse'] || null !== $etapes['email']) {
                return new RedirectResponse($this->urls->generate('app_espace_securiser'));
            }
        }

        return new RedirectResponse($this->getTargetPath($session, $firewallName) ?? $this->urls->generate('app_espace_accueil'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        if ('acces' === $request->request->get('mode')) {
            $numero = CompteClient::normaliserTelephone((string) $request->request->get('telephone'));

            return new RedirectResponse($this->urls->generate('app_espace_premiere_connexion', null === $numero ? [] : ['tel' => substr($numero, 3)]));
        }
        $mode = 'mdp' === $request->request->get('mode') ? 'mdp' : 'code';

        return new RedirectResponse($this->urls->generate('app_espace_connexion', ['mode' => $mode]));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('app_espace_connexion'));
    }
}
