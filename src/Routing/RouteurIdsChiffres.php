<?php

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le routeur de l'application, qui chiffre les numéros des fiches dans
 * chaque lien qu'il crée (path(), redirectToRoute(), generateUrl()…) :
 * aucun numéro lisible dans les URL (docs/ids-chiffres.md).
 */
#[AsDecorator(decorates: 'router')]
final class RouteurIdsChiffres implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly RouterInterface $routeur,
        private readonly CodeId $codes,
    ) {
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if (IdsChiffres::routeConcernee($name)) {
            foreach ($parameters as $cle => $valeur) {
                if (isset(IdsChiffres::NOMS[$cle]) && null !== ($numero = IdsChiffres::numero($valeur))) {
                    $parameters[$cle] = $this->codes->coder($numero);
                }
            }
        }

        return $this->routeur->generate($name, $parameters, $referenceType);
    }

    public function match(string $pathinfo): array
    {
        return $this->routeur->match($pathinfo);
    }

    public function matchRequest(Request $request): array
    {
        return $this->routeur instanceof RequestMatcherInterface ? $this->routeur->matchRequest($request) : $this->routeur->match($request->getPathInfo());
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routeur->getRouteCollection();
    }

    public function setContext(RequestContext $context): void
    {
        $this->routeur->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->routeur->getContext();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->routeur instanceof WarmableInterface ? $this->routeur->warmUp($cacheDir, $buildDir) : [];
    }
}
