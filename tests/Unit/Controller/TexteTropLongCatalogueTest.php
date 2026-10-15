<?php

namespace App\Tests\Unit\Controller;

use App\Controller\FinitionController;
use App\Controller\FormatController;
use App\Controller\SupportsController;
use App\Controller\TypesImpressionController;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Repository\FinitionRepository;
use App\Repository\FormatRepository;
use App\Repository\SupportsRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

/**
 * Réglages › Finitions, Formats, Supports, Types d'impression : un nom (ou
 * la description d'un type d'impression) plus long que sa colonne donne un
 * message clair (400) au lieu de l'erreur de la base (500), à l'ajout comme
 * à la modification, et rien n'est enregistré. À la limite exacte, ça passe.
 */
final class TexteTropLongCatalogueTest extends TestCase
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
        $this->requetes->push($this->requete([]));
    }

    public function testFinition(): void
    {
        $donnees = fn (string $nom, string $jeton) => ['nom' => $nom, 'description' => 'Film mat', 'publie' => true, 'typesImpression' => [1], '_token' => $jeton];
        $ajouter = fn (array $d, EntityManagerInterface $em) => $this->controleur(new FinitionController())->createFinitionAjax($this->requete($d), $em, $this->stub(FinitionRepository::class), $this->stubTypes());
        $finition = (new Finition())->setNom('Pelliculage');
        $modifier = fn (array $d, EntityManagerInterface $em) => $this->controleur(new FinitionController())->updateFinitionAjax($finition, $this->requete($d), $em, $this->stub(FinitionRepository::class), $this->stubTypes());

        $this->verifier($ajouter, $donnees(str_repeat('é', 256), $this->jeton('create_finition')), 'Nom de la finition : 255 caractères au plus.');
        $this->verifier($modifier, $donnees(str_repeat('é', 256), $this->jeton('update_finition')), 'Nom de la finition : 255 caractères au plus.');
        self::assertSame('Pelliculage', $finition->getNom());

        self::assertSame(201, $this->appeler($ajouter, $donnees(str_repeat('é', 255), $this->jeton('create_finition')), true)->getStatusCode());
        self::assertSame(200, $this->appeler($modifier, $donnees(str_repeat('é', 255), $this->jeton('update_finition')), true)->getStatusCode());
        self::assertSame(str_repeat('é', 255), $finition->getNom());
    }

    public function testFormat(): void
    {
        $donnees = fn (string $nom, string $jeton) => ['nom' => $nom, 'largeur' => 210, 'hauteur' => 297, 'unite' => 'mm', 'description' => 'Format standard', 'publie' => true, 'typesImpressions' => [1], '_token' => $jeton];
        $ajouter = fn (array $d, EntityManagerInterface $em) => $this->controleur(new FormatController())->createAjax($this->requete($d), $em, $this->stub(FormatRepository::class), $this->stubTypes());
        $format = (new Format())->setNom('A4');
        $modifier = fn (array $d, EntityManagerInterface $em) => $this->controleur(new FormatController())->updateAjax($format, $this->requete($d), $em, $this->stub(FormatRepository::class), $this->stubTypes());

        $this->verifier($ajouter, $donnees(str_repeat('a', 151), $this->jeton('create_format')), 'Nom du format : 150 caractères au plus.');
        $this->verifier($modifier, $donnees(str_repeat('a', 151), $this->jeton('update_format_')), 'Nom du format : 150 caractères au plus.');
        self::assertSame('A4', $format->getNom());

        self::assertSame(201, $this->appeler($ajouter, $donnees(str_repeat('é', 150), $this->jeton('create_format')), true)->getStatusCode());
        self::assertSame(200, $this->appeler($modifier, $donnees(str_repeat('é', 150), $this->jeton('update_format_')), true)->getStatusCode());
    }

    public function testSupport(): void
    {
        $donnees = fn (string $nom, string $jeton) => ['nom' => $nom, 'description' => 'Couché mat', 'publie' => true, 'typesImpressions' => [1], 'formats' => [1], '_token' => $jeton];
        $formats = $this->createStub(FormatRepository::class);
        $formats->method('findBy')->willReturn([(new Format())->setNom('A4')]);
        $ajouter = fn (array $d, EntityManagerInterface $em) => $this->controleur(new SupportsController())->createAjax($this->requete($d), $em, $this->stub(SupportsRepository::class), $this->stubTypes(), $formats, $this->stub(FinitionRepository::class));
        $support = (new Supports())->setNom('Papier 300 g');
        $modifier = fn (array $d, EntityManagerInterface $em) => $this->controleur(new SupportsController())->updateAjax($support, $this->requete($d), $em, $this->stub(SupportsRepository::class), $this->stubTypes(), $formats, $this->stub(FinitionRepository::class));

        $this->verifier($ajouter, $donnees(str_repeat('x', 256), $this->jeton('create_support')), 'Nom du support : 255 caractères au plus.');
        $this->verifier($modifier, $donnees(str_repeat('x', 256), $this->jeton('update_support_')), 'Nom du support : 255 caractères au plus.');
        self::assertSame('Papier 300 g', $support->getNom());

        self::assertSame(201, $this->appeler($ajouter, $donnees(str_repeat('é', 255), $this->jeton('create_support')), true)->getStatusCode());
        self::assertSame(200, $this->appeler($modifier, $donnees(str_repeat('é', 255), $this->jeton('update_support_')), true)->getStatusCode());
    }

    public function testTypeImpression(): void
    {
        $ajouter = fn (array $d, EntityManagerInterface $em) => $this->controleur(new TypesImpressionController())->createAjax($this->requete($d), $em, $this->stub(TypesImpressionRepository::class), $this->stub(FinitionRepository::class));
        $type = (new TypesImpression())->setNom('Numérique')->setDescription('Bâches');
        $modifier = fn (array $d, EntityManagerInterface $em) => $this->controleur(new TypesImpressionController())->updateAjax($type, $this->requete($d), $em, $this->stub(TypesImpressionRepository::class), $this->stub(FinitionRepository::class));
        $creer = $this->jeton('create_type_impression');
        $changer = $this->jeton('update_type_impression');

        $this->verifier($ajouter, ['nom' => str_repeat('n', 256), 'description' => 'x', '_token' => $creer], 'Nom du type d’impression : 255 caractères au plus.');
        $this->verifier($ajouter, ['nom' => 'DTF', 'description' => str_repeat('d', 256), '_token' => $creer], 'Description : 255 caractères au plus.');
        $this->verifier($modifier, ['nom' => str_repeat('n', 256), 'description' => 'x', '_token' => $changer], 'Nom du type d’impression : 255 caractères au plus.');
        $this->verifier($modifier, ['nom' => 'Numérique', 'description' => str_repeat('d', 256), '_token' => $changer], 'Description : 255 caractères au plus.');
        self::assertSame('Bâches', $type->getDescription());

        self::assertSame(201, $this->appeler($ajouter, ['nom' => str_repeat('é', 255), 'description' => str_repeat('é', 255), 'actif' => true, '_token' => $creer], true)->getStatusCode());
        self::assertSame(200, $this->appeler($modifier, ['nom' => str_repeat('é', 255), 'description' => str_repeat('é', 255), '_token' => $changer], true)->getStatusCode());
        self::assertSame(str_repeat('é', 255), $type->getDescription());
    }

    /**
     * @param callable(array<string, mixed>, EntityManagerInterface): JsonResponse $action
     * @param array<string, mixed>                                                $donnees
     */
    private function verifier(callable $action, array $donnees, string $message): void
    {
        $reponse = $this->appeler($action, $donnees, false);

        self::assertSame(400, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame(['success' => false, 'message' => $message], json_decode((string) $reponse->getContent(), true));
    }

    /**
     * @param callable(array<string, mixed>, EntityManagerInterface): JsonResponse $action
     * @param array<string, mixed>                                                $donnees
     */
    private function appeler(callable $action, array $donnees, bool $enregistre): JsonResponse
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($enregistre ? self::once() : self::never())->method('flush');

        return $action($donnees, $em);
    }

    private function jeton(string $nom): string
    {
        return $this->jetons->getToken($nom)->getValue();
    }

    /**
     * @template T of AbstractController
     *
     * @param T $controleur
     *
     * @return T
     */
    private function controleur(AbstractController $controleur): AbstractController
    {
        $controleur->setContainer($this->conteneur);

        return $controleur;
    }

    /**
     * Dépôt sans doublon de nom ni dernier élément (premier ordre : 10).
     *
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function stub(string $classe): object
    {
        $depot = $this->createStub($classe);
        $depot->method('findOneBy')->willReturn(null);
        $depot->method('findBy')->willReturn([]);

        return $depot;
    }

    private function stubTypes(): TypesImpressionRepository
    {
        $types = $this->createStub(TypesImpressionRepository::class);
        $types->method('findBy')->willReturn([(new TypesImpression())->setNom('Numérique')]);
        $types->method('findOneBy')->willReturn(null);

        return $types;
    }

    /** @param array<string, mixed> $donnees */
    private function requete(array $donnees): Request
    {
        $requete = Request::create('/reglages/ajax', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($donnees, \JSON_THROW_ON_ERROR));
        $requete->setSession($this->session);

        return $requete;
    }
}
