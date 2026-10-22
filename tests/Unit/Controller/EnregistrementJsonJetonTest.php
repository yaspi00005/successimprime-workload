<?php

namespace App\Tests\Unit\Controller;

use App\Controller\FinitionController;
use App\Controller\TypesImpressionController;
use App\Entity\Finition;
use App\Entity\TypesImpression;
use App\Repository\FinitionRepository;
use App\Repository\TypesImpressionRepository;
use App\Security\JetonJson;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

/**
 * Audit D6 : la modification d'une finition ou d'un type d'impression
 * (JSON) demande le jeton de la page, en en-tête X-CSRF-TOKEN ou dans
 * « _token ». Sans lui : 403 en JSON, rien d'enregistré.
 */
final class EnregistrementJsonJetonTest extends TestCase
{
    private CsrfTokenManager $jetons;
    private Container $conteneur;
    private RequestStack $requetes;
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requetes = new RequestStack();
        $this->jetons = new CsrfTokenManager(null, new SessionTokenStorage($this->requetes));
        $this->conteneur = new Container();
        $this->conteneur->set('security.csrf.token_manager', $this->jetons);
        // Le jeton de la page est créé pendant l'affichage de la page, avec la même session.
        $this->requetes->push($this->requete([]));
    }

    public function testLireLeJetonDansLEnteteOuLeCorps(): void
    {
        self::assertSame('abc', JetonJson::lire($this->requete(['_token' => 'xyz'], ['HTTP_X_CSRF_TOKEN' => 'abc']), ['_token' => 'xyz']));
        self::assertSame('xyz', JetonJson::lire($this->requete([]), ['_token' => 'xyz']));
        self::assertSame('', JetonJson::lire($this->requete([]), ['_token' => ['xyz']]));
        self::assertSame('', JetonJson::lire($this->requete([]), null));
        self::assertSame('', JetonJson::lire($this->requete([]), 'texte'));
    }

    public function testFinitionSansJetonOuAvecUnMauvaisJeton(): void
    {
        $donnees = ['nom' => 'Pelliculage piégé', 'description' => 'x', 'publie' => true, 'typesImpression' => [1]];
        $autreRoute = $this->jetons->getToken('update_type_impression')->getValue();

        foreach ([[], ['HTTP_X_CSRF_TOKEN' => 'faux'], ['HTTP_X_CSRF_TOKEN' => $autreRoute]] as $entetes) {
            $finition = (new Finition())->setNom('Pelliculage');
            $reponse = $this->modifierFinition($finition, $donnees, $entetes, false);

            self::assertSame(403, $reponse->getStatusCode());
            self::assertSame(['success' => false, 'message' => JetonJson::MESSAGE], json_decode((string) $reponse->getContent(), true));
            self::assertSame('Pelliculage', $finition->getNom());
        }
        // Corps illisible et pas de jeton : 403 aussi, avant tout le reste.
        self::assertSame(403, $this->modifierFinition(new Finition(), null, [], false)->getStatusCode());
    }

    public function testFinitionAvecLeJetonDeLaPage(): void
    {
        $jeton = $this->jetons->getToken('update_finition')->getValue();

        $finition = (new Finition())->setNom('Pelliculage');
        $reponse = $this->modifierFinition($finition, ['nom' => 'Pelliculage mat', 'description' => 'Film mat', 'publie' => true, 'typesImpression' => [1]], ['HTTP_X_CSRF_TOKEN' => $jeton], true);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('Pelliculage mat', $finition->getNom());

        // Même jeton dans le corps, comme les autres fenêtres du catalogue.
        $finition = (new Finition())->setNom('Oeillets');
        $reponse = $this->modifierFinition($finition, ['nom' => 'Œillets', 'description' => 'Tous les 50 cm', 'typesImpression' => [1], '_token' => $jeton], [], true);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('Œillets', $finition->getNom());
    }

    public function testTypeImpressionSansJetonOuAvecUnMauvaisJeton(): void
    {
        $autreRoute = $this->jetons->getToken('update_finition')->getValue();

        foreach ([[], ['HTTP_X_CSRF_TOKEN' => 'faux'], ['HTTP_X_CSRF_TOKEN' => $autreRoute]] as $entetes) {
            $type = (new TypesImpression())->setNom('Numérique');
            $reponse = $this->modifierType($type, ['nom' => 'Piégé', 'actif' => true], $entetes, false);

            self::assertSame(403, $reponse->getStatusCode());
            self::assertSame(JetonJson::MESSAGE, json_decode((string) $reponse->getContent(), true)['message']);
            self::assertSame('Numérique', $type->getNom());
        }
    }

    public function testTypeImpressionAvecLeJetonDeLaPage(): void
    {
        $jeton = $this->jetons->getToken('update_type_impression')->getValue();

        $type = (new TypesImpression())->setNom('Numérique');
        $reponse = $this->modifierType($type, ['nom' => 'Numérique grand format', 'description' => 'Bâches et vinyles', 'actif' => true], ['HTTP_X_CSRF_TOKEN' => $jeton], true);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('Numérique grand format', $type->getNom());

        $type = (new TypesImpression())->setNom('Offset');
        $reponse = $this->modifierType($type, ['nom' => 'Offset 4 couleurs', 'description' => 'Grandes séries', '_token' => $jeton], [], true);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('Offset 4 couleurs', $type->getNom());
    }

    /**
     * @param array<string, mixed>|null $donnees
     * @param array<string, string>     $entetes
     */
    private function modifierFinition(Finition $finition, ?array $donnees, array $entetes, bool $enregistre): JsonResponse
    {
        $controleur = new FinitionController();
        $controleur->setContainer($this->conteneur);
        $finitions = $this->createStub(FinitionRepository::class);
        $finitions->method('findOneBy')->willReturn(null);
        $types = $this->createStub(TypesImpressionRepository::class);
        $types->method('findBy')->willReturn([(new TypesImpression())->setNom('Numérique')]);

        return $this->avecRequete($this->requete($donnees, $entetes), fn (Request $r) => $controleur->updateFinitionAjax($finition, $r, $this->gestionnaire($enregistre), $finitions, $types));
    }

    /**
     * @param array<string, mixed>  $donnees
     * @param array<string, string> $entetes
     */
    private function modifierType(TypesImpression $type, array $donnees, array $entetes, bool $enregistre): JsonResponse
    {
        $controleur = new TypesImpressionController();
        $controleur->setContainer($this->conteneur);
        $types = $this->createStub(TypesImpressionRepository::class);
        $types->method('findOneBy')->willReturn(null);
        $finitions = $this->createStub(FinitionRepository::class);
        $finitions->method('findBy')->willReturn([]);

        return $this->avecRequete($this->requete($donnees, $entetes), fn (Request $r) => $controleur->updateAjax($type, $r, $this->gestionnaire($enregistre), $types, $finitions));
    }

    private function gestionnaire(bool $enregistre): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($enregistre ? self::once() : self::never())->method('flush');

        return $em;
    }

    /** @param callable(Request): JsonResponse $action */
    private function avecRequete(Request $requete, callable $action): JsonResponse
    {
        $this->requetes->push($requete);
        try {
            return $action($requete);
        } finally {
            $this->requetes->pop();
        }
    }

    /**
     * @param array<string, mixed>|null $donnees
     * @param array<string, string>     $entetes
     */
    private function requete(?array $donnees, array $entetes = []): Request
    {
        $requete = Request::create('/finition/1/update/ajax', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $entetes, null === $donnees ? '{pas du json' : json_encode($donnees, \JSON_THROW_ON_ERROR));
        $requete->setSession($this->session);

        return $requete;
    }
}
