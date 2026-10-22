<?php

namespace App\Security;

use App\Entity\JetonApi;
use App\Repository\JetonApiRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * API (docs/api.md) : « Authorization: Bearer si_… ». Le jeton agit au
 * nom de la personne qui le porte, avec ses droits du moment ; une
 * personne désactivée ou à l'accès fermé n'entre pas (UserChecker).
 */
final class JetonApiAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly JetonApiRepository $jetons,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $texte = trim(substr((string) $request->headers->get('Authorization'), 7));
        $maintenant = new \DateTimeImmutable();
        $jeton = $this->jetons->parTexte($texte);
        if (null === $jeton || !$jeton->estValide($maintenant)) {
            throw new CustomUserMessageAuthenticationException('Jeton inconnu, expiré ou révoqué.');
        }
        if ($jeton->noterUtilisation($maintenant, $request->getClientIp())) {
            $this->em->flush();
        }
        $utilisateur = $jeton->getUtilisateur();

        return new SelfValidatingPassport(new UserBadge($utilisateur->getUserIdentifier(), static fn () => $utilisateur));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return self::refus($exception->getMessageKey());
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::refus('Jeton d\'accès requis : en-tête « Authorization: Bearer si_… ».');
    }

    private static function refus(string $message): JsonResponse
    {
        return new JsonResponse(['title' => 'Non authentifié', 'status' => 401, 'detail' => $message], 401, [
            'Content-Type' => 'application/problem+json',
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
