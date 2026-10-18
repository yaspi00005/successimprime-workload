<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Entity\EnvoiMessage;
use App\Service\Messagerie\JournalEnvois;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/** Historique des envois : une ligne par message tenté, jamais son texte. */
final class JournalEnvoisTest extends TestCase
{
    public function testDestinataireRangeDUneSeuleFacon(): void
    {
        self::assertSame('22376000099', JournalEnvois::normaliser(EnvoiMessage::SMS, '76 00 00 99'));
        self::assertSame('22376000099', JournalEnvois::normaliser(EnvoiMessage::SMS, '00223 76 00 00 99'));
        self::assertSame('22376000099', JournalEnvois::normaliser(EnvoiMessage::SMS, 'tel:+22376000099'));
        self::assertSame('33612345678', JournalEnvois::normaliser(EnvoiMessage::SMS, '+33 6 12 34 56 78'));
        self::assertSame('awa.traore@gmail.com', JournalEnvois::normaliser(EnvoiMessage::EMAIL, ' Awa.Traore@Gmail.com '));
    }

    public function testErreurEtObjetSansDonneeACacher(): void
    {
        $nettoye = JournalEnvois::nettoyer('Adresse tel:+22376000099 refusée pour awa.traore@gmail.com ; reste 15 000 F à payer (12500 FCFA).');

        self::assertStringNotContainsString('76000099', $nettoye);
        self::assertStringNotContainsString('awa.traore', $nettoye);
        self::assertStringNotContainsString('15 000', $nettoye);
        self::assertStringNotContainsString('12500', $nettoye);
        self::assertStringContainsString('+223 •• •• 00 99', $nettoye);
        self::assertStringContainsString('@gmail.com', $nettoye);
        self::assertStringContainsString('… F', $nettoye);

        // Les codes d'erreur courts et les mots restent lisibles.
        self::assertSame('Réponse Orange inattendue (HTTP 401).', JournalEnvois::nettoyer('Réponse Orange inattendue (HTTP 401).'));
        self::assertSame('Votre commande CMD-2026-0042 est prête', JournalEnvois::nettoyer('Votre commande CMD-2026-0042 est prête'));
    }

    public function testTyperPoseUnSeulEntete(): void
    {
        $email = JournalEnvois::typer(JournalEnvois::typer(new Email(), 'campagne'), 'rappel_paiement');

        self::assertCount(1, iterator_to_array($email->getHeaders()->all(JournalEnvois::ENTETE_TYPE), false));
        self::assertSame('rappel_paiement', $email->getHeaders()->get(JournalEnvois::ENTETE_TYPE)?->getBodyAsString());
    }

    public function testLigneEcriteAuLocalSansFileDeSynchronisation(): void
    {
        [$connexion, $inserts] = $this->connexion();
        (new JournalEnvois($connexion, new NullLogger(), false))->noter(EnvoiMessage::SMS, 'relance_retrait', '76 00 00 99', EnvoiMessage::ENVOYE, null, 2, null, new \DateTimeImmutable('2026-10-18 09:15:00'));

        self::assertCount(1, $inserts);
        [$table, $ligne] = $inserts[0];
        self::assertSame('envoi_message', $table);
        self::assertSame('sms', $ligne['canal']);
        self::assertSame('relance_retrait', $ligne['type']);
        self::assertSame('22376000099', $ligne['destinataire']);
        self::assertSame('envoye', $ligne['statut']);
        self::assertSame(2, $ligne['unites']);
        self::assertSame('2026-10-18 09:15:00', $ligne['envoye_le']);
        self::assertSame('local', $ligne['origine']);
        self::assertSame(16, \strlen($ligne['public_id']));
        self::assertArrayNotHasKey('message', $ligne, 'jamais le texte');
    }

    public function testEnLigneLaLigneMonteAuLocal(): void
    {
        [$connexion, $inserts] = $this->connexion();
        (new JournalEnvois($connexion, new NullLogger(), true))->noter(EnvoiMessage::EMAIL, 'code_connexion', 'Client@Exemple.ml', EnvoiMessage::ECHEC, 'Boîte client@exemple.ml pleine', 1, 'Votre code de connexion');

        self::assertCount(2, $inserts);
        self::assertSame('envoi_message', $inserts[0][0]);
        self::assertSame('en_ligne', $inserts[0][1]['origine']);
        self::assertSame(0, $inserts[0][1]['unites'], 'un échec ne consomme rien');
        self::assertStringNotContainsString('client@', (string) $inserts[0][1]['erreur']);

        [$table, $file] = $inserts[1];
        self::assertSame('synchro_journal', $table);
        self::assertSame('envoi_message', $file['type']);
        self::assertSame('maj', $file['operation']);
        self::assertSame(Uuid::fromBinary($inserts[0][1]['public_id'])->toRfc4122(), $file['public_id']);
    }

    public function testUnePanneNEmpechePasLEnvoiEtNeDitRien(): void
    {
        $connexion = $this->createStub(Connection::class);
        $connexion->method('transactional')->willThrowException(new \RuntimeException('Table envoi_message absente pour 76000099'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lignes = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lignes[] = $message.' '.json_encode($context);
            }
        };

        (new JournalEnvois($connexion, $logger, false))->noter(EnvoiMessage::SMS, 'campagne', '76000099', EnvoiMessage::ENVOYE);

        self::assertCount(1, $logger->lignes);
        self::assertStringNotContainsString('76000099', $logger->lignes[0]);
    }

    /** @return array{0: Connection, 1: \ArrayObject<int, array{0: string, 1: array<string, mixed>}>} */
    private function connexion(): array
    {
        $inserts = new \ArrayObject();
        $connexion = $this->createStub(Connection::class);
        $connexion->method('transactional')->willReturnCallback(static fn (\Closure $f): mixed => $f($connexion));
        $connexion->method('insert')->willReturnCallback(static function (string $table, array $ligne) use ($inserts): int {
            $inserts[] = [$table, $ligne];

            return 1;
        });

        return [$connexion, $inserts];
    }
}
