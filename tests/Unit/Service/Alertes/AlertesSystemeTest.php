<?php

namespace App\Tests\Unit\Service\Alertes;

use App\Entity\AlerteSysteme;
use App\Entity\User;
use App\EventSubscriber\AlertesExceptionSubscriber;
use App\Service\Alertes\AlertesMonologHandler;
use App\Service\Alertes\AlertesSysteme;
use App\Service\Alertes\NettoyageAlerte;
use App\Service\Alertes\ReglagesAlertes;
use App\Service\Alertes\VerificationsSysteme;
use App\Service\Synchro\CatalogueSynchro;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AlertesSystemeTest extends TestCase
{
    public function testLeTexteNeGardeAucunSecret(): void
    {
        $texte = NettoyageAlerte::texte('Échec mysql://si:Secret123@127.0.0.1/base password=abc token: xyz Authorization: Bearer eyJhbGciOi.abc.def lien /f/AB?code=1234 client 76 00 00 99 a.diallo@gmail.com 25 000 F cle 0123456789abcdef0123456789abcdef');

        foreach (['Secret123', 'abc ', 'xyz', 'eyJhbGciOi', 'code=1234', '76 00 00 99', 'a.diallo@gmail.com', '25 000', '0123456789abcdef0123456789abcdef'] as $secret) {
            self::assertStringNotContainsString($secret, $texte, $secret);
        }
        self::assertStringContainsString('mysql://***:***@127.0.0.1', $texte);
        // Les mots ordinaires restent : « code 500 » n'est pas un secret.
        self::assertSame('Erreur code 500 sur la page', NettoyageAlerte::texte('Erreur code 500 sur la page'));
    }

    public function testDetailSansValeursPasseesNiCheminDuServeur(): void
    {
        $erreur = new \RuntimeException('Mot de passe refusé : password=topsecret');
        $detail = NettoyageAlerte::detailErreur($erreur, \dirname(__DIR__, 4), ['Page' => 'GET /espace/factures?code=ABCD']);

        self::assertStringContainsString('Erreur : RuntimeException', $detail);
        self::assertStringContainsString('tests/Unit/Service/Alertes/AlertesSystemeTest.php:', $detail);
        self::assertStringNotContainsString('topsecret', $detail);
        self::assertStringNotContainsString('ABCD', $detail);
        self::assertStringNotContainsString(\dirname(__DIR__, 4), $detail);
    }

    public function testMemePanneMemeEmpreinte(): void
    {
        self::assertSame(NettoyageAlerte::empreinte('erreur_serveur', 'TypeError', 'src/A.php', '12'), NettoyageAlerte::empreinte('erreur_serveur', 'TypeError', 'src/A.php', '12'));
        self::assertNotSame(NettoyageAlerte::empreinte('erreur_serveur', 'TypeError', 'src/A.php', '12'), NettoyageAlerte::empreinte('erreur_serveur', 'TypeError', 'src/A.php', '13'));
        // Un message qui change par commande garde la même forme.
        self::assertSame(NettoyageAlerte::forme('Argent reçu sans commande pour SI0A1B2C3D4E5F'), NettoyageAlerte::forme('Argent reçu sans commande pour SI99FF00AA11BB'));
    }

    public function testZones(): void
    {
        self::assertSame('Espace client', NettoyageAlerte::zone('/espace/commandes/1'));
        self::assertSame('Site public', NettoyageAlerte::zone('/contact'));
        self::assertSame('Site public', NettoyageAlerte::zone('/'));
        self::assertSame('Synchronisation', NettoyageAlerte::zone('/api/synchro/envoyer'));
        self::assertSame('Administration', NettoyageAlerte::zone('/commandes'));
    }

    public function testSeulesLesErreurs500SontDesPannes(): void
    {
        self::assertTrue(AlertesExceptionSubscriber::estUnePanne(new \TypeError('x')));
        self::assertTrue(AlertesExceptionSubscriber::estUnePanne(new ServiceUnavailableHttpException()));
        self::assertFalse(AlertesExceptionSubscriber::estUnePanne(new NotFoundHttpException()));
        self::assertFalse(AlertesExceptionSubscriber::estUnePanne(new AccessDeniedException()));
    }

    public function testJournalClasse(): void
    {
        self::assertSame([AlerteSysteme::PAIEMENT, AlerteSysteme::AVERTISSEMENT], AlertesMonologHandler::classer('app', Level::Warning, 'Orange Money : notification refusée pour {order}.'));
        self::assertSame([AlerteSysteme::PAIEMENT, AlerteSysteme::CRITIQUE], AlertesMonologHandler::classer('app', Level::Error, 'Orange Money : argent reçu sans commande pour {order}.'));
        self::assertSame([AlerteSysteme::ENVOI, AlerteSysteme::CRITIQUE], AlertesMonologHandler::classer('app', Level::Error, 'SMS Orange : échec de l’envoi.'));
        self::assertSame([AlerteSysteme::TACHE, AlerteSysteme::CRITIQUE], AlertesMonologHandler::classer('console', Level::Error, 'Error thrown while running command'));
        self::assertNull(AlertesMonologHandler::classer('app', Level::Warning, 'Autre avertissement'));
        // Les 500 des pages viennent de l'abonné, les alertes elles-mêmes ne bouclent pas.
        self::assertNull(AlertesMonologHandler::classer('request', Level::Critical, 'Uncaught PHP Exception'));
        self::assertNull(AlertesMonologHandler::classer('app', Level::Error, 'Alertes système : e-mail non parti.'));
        self::assertSame('Orange Money : argent reçu sans commande', AlertesMonologHandler::titre(AlerteSysteme::PAIEMENT, 'app', 'Orange Money : argent reçu sans commande pour {order}.', []));
        self::assertSame('Tâche planifiée en erreur · app:rapports-mail', AlertesMonologHandler::titre(AlerteSysteme::TACHE, 'console', 'x', ['command' => 'app:rapports-mail -q']));
    }

    public function testReglagesParDefautEtDelai(): void
    {
        $defaut = ReglagesAlertes::lire([]);
        self::assertSame(15, $defaut['delai']);
        self::assertTrue($defaut['types'][AlerteSysteme::ERREUR_SERVEUR]['email']);
        // Au local, la synchronisation sonne déjà dans la cloche : e-mail seulement.
        self::assertFalse($defaut['types'][AlerteSysteme::SYNCHRO]['app']);
        self::assertTrue(ReglagesAlertes::lire([], true)['types'][AlerteSysteme::SYNCHRO]['app']);
        // Formulaire : une case décochée est absente.
        $saisie = ReglagesAlertes::lire(['formulaire' => 1, 'delai' => '0', 'autres' => "a@b.ml\npas-une-adresse\nA@B.ml", 'types' => ['paiement' => ['email' => '1', 'destinataires' => ['3', 'x']]]]);
        self::assertSame(1, $saisie['delai']);
        self::assertSame(['a@b.ml'], $saisie['autres']);
        self::assertSame(['email' => true, 'app' => false, 'destinataires' => [3]], $saisie['types']['paiement']);

        $maintenant = new \DateTimeImmutable('2026-10-18 10:00:00');
        self::assertTrue(ReglagesAlertes::peutEnvoyer(null, 15, $maintenant));
        self::assertFalse(ReglagesAlertes::peutEnvoyer('2026-10-18T09:50:00+00:00', 15, $maintenant));
        self::assertTrue(ReglagesAlertes::peutEnvoyer('2026-10-18T09:45:00+00:00', 15, $maintenant));
    }

    public function testCycleDeVieEtReference(): void
    {
        $alerte = new AlerteSysteme();
        self::assertTrue($alerte->isAPrevenir());
        $u = new User();
        $alerte->prendre($u, new \DateTimeImmutable());
        self::assertSame(AlerteSysteme::PRISE, $alerte->getEtat());
        $alerte->resoudre($u, ' redémarré ', new \DateTimeImmutable());
        self::assertTrue($alerte->estResolue());
        self::assertSame('redémarré', $alerte->getResolution());
        self::assertFalse($alerte->isAPrevenir());
        $alerte->rouvrir();
        self::assertSame(AlerteSysteme::OUVERTE, $alerte->getEtat());
        self::assertNull($alerte->getPrisePar());
        self::assertTrue($alerte->isAPrevenir());

        self::assertSame('AL-000042', AlertesSysteme::reference(42, false));
        self::assertSame('W-AL-000042', AlertesSysteme::reference(42, true));
    }

    public function testMonteAuLocalSeulement(): void
    {
        self::assertTrue(CatalogueSynchro::envoye('alerte_systeme', true));
        self::assertFalse(CatalogueSynchro::envoye('alerte_systeme', false));
        self::assertTrue(CatalogueSynchro::accepte('alerte_systeme', false));
        self::assertFalse(CatalogueSynchro::accepte('alerte_systeme', true));
    }

    public function testVerifications(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 10:00:00');
        self::assertNull(VerificationsSysteme::silenceAgent(null, $maintenant));
        self::assertNull(VerificationsSysteme::silenceAgent($maintenant->modify('-10 minutes'), $maintenant));
        self::assertStringContainsString('45 min', (string) VerificationsSysteme::silenceAgent($maintenant->modify('-45 minutes'), $maintenant));
        self::assertNull(VerificationsSysteme::disqueBas(5 * 1073741824, 100 * 1073741824, 2.0));
        self::assertStringContainsString('1,0 Go', (string) VerificationsSysteme::disqueBas(1073741824, 100 * 1073741824, 2.0));
        self::assertSame('echec', VerificationsSysteme::sorteSauvegarde('La dernière sauvegarde a échoué : disque plein')[0]);
        self::assertSame('retard', VerificationsSysteme::sorteSauvegarde('Pas de sauvegarde depuis 30 heures.')[0]);
    }
}
