<?php

namespace App\Tests\Unit\Controller;

use App\Controller\TypesImpressionController;
use App\Entity\TypesImpression;
use App\Repository\FinitionRepository;
use App\Repository\TypesImpressionRepository;
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
 * Description d'un type d'impression (colonne obligatoire, NOT NULL) :
 * effacée à la modification, elle s'enregistre vide au lieu de casser la
 * page (500) ; à l'ajout, elle reste demandée (400).
 */
final class TypesImpressionDescriptionTest extends TestCase
{
    private CsrfTokenManager $jetons;
    private Container $conteneur;
    private RequestStack $requetes;
    private Session $session;
    private ?object $ajoute = null;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requetes = new RequestStack();
        $this->jetons = new CsrfTokenManager(null, new SessionTokenStorage($this->requetes));
        $this->conteneur = new Container();
        $this->conteneur->set('security.csrf.token_manager', $this->jetons);
        $this->requetes->push($this->requete([]));
    }

    public function testModifierAvecUneDescriptionEffacee(): void
    {
        $jeton = $this->jetons->getToken('update_type_impression')->getValue();

        foreach (['', '   '] as $vide) {
            $type = (new TypesImpression())->setNom('Numérique')->setDescription('Bâches et vinyles');
            $reponse = $this->appeler(fn (Request $r, EntityManagerInterface $em) => $this->controleur()->updateAjax($type, $r, $em, $this->types(), $this->finitions()), ['nom' => 'Numérique', 'description' => $vide, 'actif' => true, '_token' => $jeton], true);

            self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
            self::assertSame('', $type->getDescription());
        }

        // Sans champ description du tout : vide aussi.
        $type = (new TypesImpression())->setNom('Offset')->setDescription('Grandes séries');
        $reponse = $this->appeler(fn (Request $r, EntityManagerInterface $em) => $this->controleur()->updateAjax($type, $r, $em, $this->types(), $this->finitions()), ['nom' => 'Offset', '_token' => $jeton], true);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('', $type->getDescription());
    }

    public function testAjouterDemandeUneDescription(): void
    {
        $jeton = $this->jetons->getToken('create_type_impression')->getValue();

        $reponse = $this->appeler(fn (Request $r, EntityManagerInterface $em) => $this->controleur()->createAjax($r, $em, $this->types(), $this->finitions()), ['nom' => 'Sérigraphie', 'description' => ' ', '_token' => $jeton], false);
        self::assertSame(400, $reponse->getStatusCode());
        self::assertSame('La description est obligatoire.', json_decode((string) $reponse->getContent(), true)['message']);

        $reponse = $this->appeler(fn (Request $r, EntityManagerInterface $em) => $this->controleur()->createAjax($r, $em, $this->types(), $this->finitions()), ['nom' => 'Sérigraphie', 'description' => 'Textile, 1 à 4 couleurs', 'actif' => true, '_token' => $jeton], true);
        self::assertSame(201, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertInstanceOf(TypesImpression::class, $this->ajoute);
        self::assertSame('Textile, 1 à 4 couleurs', $this->ajoute->getDescription());
    }

    private function controleur(): TypesImpressionController
    {
        $controleur = new TypesImpressionController();
        $controleur->setContainer($this->conteneur);

        return $controleur;
    }

    private function types(): TypesImpressionRepository
    {
        $types = $this->createStub(TypesImpressionRepository::class);
        $types->method('findOneBy')->willReturn(null);

        return $types;
    }

    private function finitions(): FinitionRepository
    {
        $finitions = $this->createStub(FinitionRepository::class);
        $finitions->method('findBy')->willReturn([]);

        return $finitions;
    }

    /**
     * @param callable(Request, EntityManagerInterface): JsonResponse $action
     * @param array<string, mixed>                                    $donnees
     */
    private function appeler(callable $action, array $donnees, bool $enregistre): JsonResponse
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($enregistre ? self::once() : self::never())->method('flush');
        $em->method('persist')->willReturnCallback(function (object $entite): void {
            $this->ajoute = $entite;
        });
        $requete = $this->requete($donnees);
        $this->requetes->push($requete);
        try {
            return $action($requete, $em);
        } finally {
            $this->requetes->pop();
        }
    }

    /** @param array<string, mixed> $donnees */
    private function requete(array $donnees): Request
    {
        $requete = Request::create('/types/impression/ajax', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($donnees, \JSON_THROW_ON_ERROR));
        $requete->setSession($this->session);

        return $requete;
    }
}
