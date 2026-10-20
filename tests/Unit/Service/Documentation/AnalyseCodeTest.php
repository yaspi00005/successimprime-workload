<?php

namespace App\Tests\Unit\Service\Documentation;

use App\Service\Documentation\AnalyseCode;
use PHPUnit\Framework\TestCase;

final class AnalyseCodeTest extends TestCase
{
    public function testJetonCsrfRepere(): void
    {
        self::assertTrue(AnalyseCode::verifieCsrf("if (!\$this->isCsrfTokenValid('x', \$t)) {"));
        self::assertTrue(AnalyseCode::verifieCsrf('$jeton = JetonJson::lire($request);'));
        self::assertTrue(AnalyseCode::verifieCsrf('$form = $this->createForm(DevisType::class, $devis);'));
        self::assertTrue(AnalyseCode::verifieCsrf('$f = $this->createFormBuilder();'));
        self::assertFalse(AnalyseCode::verifieCsrf('$this->em->flush(); return $this->json([]);'));
    }

    public function testRouteQuiModifie(): void
    {
        self::assertTrue(AnalyseCode::modifieDonnees(['POST'], 'return $this->json([]);'));
        self::assertTrue(AnalyseCode::modifieDonnees([], '$this->em->flush();'));
        self::assertFalse(AnalyseCode::modifieDonnees(['GET'], '$this->em->flush();'));
        self::assertFalse(AnalyseCode::modifieDonnees([], 'return $this->render("x.html.twig");'));
    }

    public function testCorpsInerte(): void
    {
        self::assertSame([], AnalyseCode::inerte('return $this->redirectToRoute("app_x");'));
        self::assertSame([], AnalyseCode::inerte('if (!$r->isXmlHttpRequest()) { throw $this->createAccessDeniedException(); } return $this->json([]);'));
        self::assertSame(['verifier'], AnalyseCode::inerte('$this->verifier($r); return $this->json([]);'));
        self::assertFalse(AnalyseCode::inerte('$this->em->flush(); return $this->json([]);'));
        self::assertFalse(AnalyseCode::inerte('$x = $this->service->faire(); return $this->json($x);'));
        self::assertFalse(AnalyseCode::inerte('$e = new Echeance(); return new JsonResponse([]);'));
        // Un commentaire qui parle de flush ne compte pas.
        self::assertSame([], AnalyseCode::inerte("// pas de ->flush() ici\nreturn \$this->json([]);"));
    }

    public function testCorpsSansLaSignature(): void
    {
        $corps = AnalyseCode::corpsDepuisSource('public function a(#[MapEntity(mapping: ["id" => "id"])] X $x): Response { return 1; }');
        self::assertSame(' return 1; }', $corps);
    }

    public function testRefusEtVerificationsDeDroits(): void
    {
        $corps = "\$this->denyAccessUnlessGranted('ROLE_X'); \$this->denyAccessUnlessGranted(Droits::FACTURE, \$f); if (\$this->isGranted('ROLE_Y')) {}";
        self::assertSame(['ROLE_X', 'Droits::FACTURE'], AnalyseCode::refusAcces($corps));
        self::assertSame(['ROLE_Y'], AnalyseCode::verificationsDroits($corps));
    }

    public function testOrigine(): void
    {
        self::assertTrue(AnalyseCode::verifieOrigine("\$origine = \$request->headers->get('Origin');"));
        self::assertFalse(AnalyseCode::verifieOrigine('$request->headers->get("Referer");'));
    }

    public function testDonneesSensibles(): void
    {
        self::assertSame(['montants'], AnalyseCode::sensibles('app_paiements_index', '/paiements'));
        // « paie » est un mot entier : « paiements » n'est pas un salaire.
        self::assertSame(['salaires'], AnalyseCode::sensibles('app_paie_payer', '/equipe/paie/{id}'));
        self::assertSame(['montants'], AnalyseCode::sensibles('app_orange_money_notification', '/om/notification/{o}'));
        self::assertContains('fichiers', AnalyseCode::sensibles('app_facture_pdf', '/factures/{id}/pdf'));
        self::assertSame([], AnalyseCode::sensibles('app_aide', '/aide'));
    }

    public function testResumeDocblock(): void
    {
        $doc = "/**\n * Première phrase, avec docs/x.md. Deuxième phrase.\n *\n * Autre paragraphe.\n * @param int \$x\n */";
        self::assertSame('Première phrase, avec docs/x.md.', AnalyseCode::resume($doc));
        self::assertSame('', AnalyseCode::resume(false));
        self::assertSame('abcd…', AnalyseCode::couper('abcdefgh', 5));
    }
}
