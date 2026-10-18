<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Entity\User;
use App\Security\DroitsEspace;
use App\Security\MessagerieVoter;
use App\Service\Messagerie\AnalyseAudio;
use App\Service\Messagerie\NotesVocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Notes vocales de la messagerie (docs/messagerie-clients.md) : contrôle
 * du fichier (type lu dans le contenu, taille, durée) et droits d'écoute.
 *
 * Les petits fichiers de audio/ imitent ceux des navigateurs : WebM sans
 * durée annoncée (Chrome, Android), Ogg Opus (Firefox), MP4 fragmenté
 * (Safari, iPhone) et MP4 simple.
 */
final class NotesVocalesTest extends TestCase
{
    private const DOSSIER = __DIR__.'/audio/';

    /** @return iterable<string, array{string, string}> */
    public static function enregistrements(): iterable
    {
        yield 'Chrome (WebM sans durée)' => ['chrome.webm', 'audio/webm'];
        yield 'Firefox (Ogg Opus)' => ['firefox.ogg', 'audio/ogg'];
        yield 'Safari (MP4 fragmenté)' => ['safari.m4a', 'audio/mp4'];
        yield 'MP4 simple' => ['simple.m4a', 'audio/mp4'];
    }

    #[DataProvider('enregistrements')]
    public function testFormatsDesNavigateursAcceptes(string $fichier, string $type): void
    {
        $contenu = (string) file_get_contents(self::DOSSIER.$fichier);
        $vocale = NotesVocales::verifier($contenu);

        self::assertSame($type, $vocale['type'], 'type lu dans le contenu');
        self::assertSame(2, $vocale['duree'], 'durée lue dans le fichier (2 s)');
        self::assertSame(\strlen($contenu), $vocale['taille']);
        self::assertSame(hash('sha256', $contenu), $vocale['empreinte']);
        self::assertSame(AnalyseAudio::TYPES[$type], match ($type) { 'audio/webm' => 'webm', 'audio/ogg' => 'ogg', default => 'm4a' });
    }

    public function testNoteDeDemonstrationAcceptee(): void
    {
        $vocale = NotesVocales::verifier((string) file_get_contents(\dirname(__DIR__, 4).'/donnees-demo/note-vocale-demo.ogg'));
        self::assertSame('audio/ogg', $vocale['type']);
        self::assertSame(7, $vocale['duree']);
    }

    /** @return iterable<string, array{string}> */
    public static function deguises(): iterable
    {
        yield 'script PHP' => ['<?php system($_GET["c"]); ?>'.str_repeat(' ', 100)];
        yield 'page HTML' => ['<!doctype html><html><script>alert(1)</script></html>'.str_repeat(' ', 100)];
        yield 'image PNG' => ["\x89PNG\r\n\x1A\n".str_repeat("\0", 100)];
        yield 'fichier vide' => [''];
    }

    #[DataProvider('deguises')]
    public function testFichierQuiNEstPasUneNoteVocaleRefuse(string $contenu): void
    {
        $this->expectException(\DomainException::class);
        NotesVocales::verifier($contenu);
    }

    public function testVideoRefusee(): void
    {
        $this->expectExceptionMessage('vidéo refusée');
        NotesVocales::verifier((string) file_get_contents(self::DOSSIER.'video.webm'));
    }

    public function testTropLourdeRefusee(): void
    {
        $this->expectExceptionMessage('trop lourde');
        NotesVocales::verifier('OggS'.str_repeat("\0", NotesVocales::TAILLE_MAX));
    }

    public function testTropLongueRefusee(): void
    {
        // Ogg : la position de la dernière page dit 3 min 20 s.
        $ogg = (string) file_get_contents(self::DOSSIER.'firefox.ogg');
        $derniere = strrpos($ogg, 'OggS');
        $longue = substr_replace($ogg, pack('P', 200 * 48000 + 312), $derniere + 6, 8);
        self::assertGreaterThan(195, AnalyseAudio::analyser($longue)['duree']);
        try {
            NotesVocales::verifier($longue);
            self::fail('Une note de 3 min 20 s doit être refusée.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('trop longue', $e->getMessage());
        }

        // MP4 : la durée de l'en-tête (mvhd) dit 4 minutes.
        $mp4 = (string) file_get_contents(self::DOSSIER.'simple.m4a');
        $mvhd = strpos($mp4, 'mvhd');
        $echelle = unpack('N', substr($mp4, $mvhd + 4 + 12, 4))[1];
        $longue = substr_replace($mp4, pack('N', 240 * $echelle), $mvhd + 4 + 16, 4);
        $this->expectExceptionMessage('trop longue');
        NotesVocales::verifier($longue);
    }

    public function testTroisMinutesPileAcceptees(): void
    {
        $ogg = (string) file_get_contents(self::DOSSIER.'firefox.ogg');
        $derniere = strrpos($ogg, 'OggS');
        $preSkip = unpack('v', substr($ogg, strpos($ogg, 'OpusHead') + 10, 2))[1];
        // L'enregistreur s'arrête un peu après 3:00 : la marge est acceptée, la durée notée reste 3:00.
        $vocale = NotesVocales::verifier(substr_replace($ogg, pack('P', 182 * 48000 + $preSkip), $derniere + 6, 8));
        self::assertSame(NotesVocales::DUREE_MAX, $vocale['duree']);
    }

    public function testDureeIllisibleRefusee(): void
    {
        // WebM coupé avant le premier bloc de son : rien ne dit sa durée.
        $webm = (string) file_get_contents(self::DOSSIER.'chrome.webm');
        $coupe = substr($webm, 0, strpos($webm, "\x1F\x43\xB6\x75"));
        $this->expectExceptionMessage('Durée de la note vocale illisible');
        NotesVocales::verifier($coupe);
    }

    public function testEnregistrementCoupeGardeCeQuiEstLu(): void
    {
        // Chrome envoie par morceaux : un fichier coupé à la fin reste lisible jusque-là.
        $webm = (string) file_get_contents(self::DOSSIER.'chrome.webm');
        $duree = AnalyseAudio::analyser(substr($webm, 0, (int) (\strlen($webm) * 0.7)))['duree'];
        self::assertNotNull($duree);
        self::assertGreaterThan(0.5, $duree);
        self::assertLessThan(2.0, $duree);
    }

    public function testLibelleEtDuree(): void
    {
        self::assertSame('0:07', MessageClient::dureeVocale(7));
        self::assertSame('3:00', MessageClient::dureeVocale(180));
        self::assertSame('Note vocale · 1:05', MessageClient::libelleVocale(65));

        $m = MessageClient::nouveau(new Clients(), MessageClient::CLIENT, MessageClient::libelleVocale(42), 'Awa', null, new \DateTimeImmutable());
        self::assertFalse($m->aUneNoteVocale());
        $m->joindreNoteVocale(self::vocale(42));
        self::assertTrue($m->aUneNoteVocale());
        self::assertTrue($m->texteEstLibelleVocale(), 'pas de texte à montrer sous le lecteur');
        self::assertSame('0:42', $m->getVocaleDureeTexte());
    }

    public function testNomDeStockageDuServeurSeulement(): void
    {
        self::assertMatchesRegularExpression(NotesVocales::NOM, '0b2f4c1e-7d3a-4f6b-9a1c-2e3d4f5a6b7c.webm');
        foreach (['../../.env.local', 'note.php', '0b2f4c1e-7d3a-4f6b-9a1c-2e3d4f5a6b7c.webm.php', '0b2f4c1e-7d3a-4f6b-9a1c-2e3d4f5a6b7c.html', '/etc/passwd'] as $nom) {
            self::assertDoesNotMatchRegularExpression(NotesVocales::NOM, $nom);
        }
        $m = (new MessageClient())->joindreNoteVocale(['nom' => '../../../.env.local'] + self::vocale(3));
        self::assertNull((new NotesVocales(sys_get_temp_dir()))->chemin($m), 'un nom venu d\'ailleurs ne sort jamais un autre fichier');
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    public function testLeClientEcouteSeulementLesNotesDeSaConversation(): void
    {
        $client = new Clients();
        $autre = new Clients();
        $le = new \DateTimeImmutable('2026-10-18 10:00');
        $c = ConversationClient::nouvelle($client, new Commandes(), null, null, ConversationClient::SUJET_COMMANDE, $le);
        $cAutre = ConversationClient::nouvelle($autre, new Commandes(), null, null, ConversationClient::SUJET_COMMANDE, $le);
        $compte = (new CompteClient())->setClient($client)->setDroits([]);
        $compteAutre = (new CompteClient())->setClient($autre)->setDroits([]);
        $lecture = (new CompteClient())->setClient($client)->setDroits([CompteClient::DROIT_LECTURE]);

        $reponse = MessageClient::nouveau($client, MessageClient::ATELIER, MessageClient::libelleVocale(12), 'Mariam', null, $le)->setConversation($c)->joindreNoteVocale(self::vocale(12));
        $sienne = MessageClient::nouveau($client, MessageClient::CLIENT, MessageClient::libelleVocale(9), 'Awa', null, $le)->setConversation($c)->joindreNoteVocale(self::vocale(9));
        $ecrite = MessageClient::nouveau($client, MessageClient::CLIENT, 'Bonjour', 'Awa', null, $le)->setConversation($c);
        $evenement = MessageClient::nouveau($client, MessageClient::EVENEMENT, 'Mariam a ajouté Seydou', null, null, $le)->setConversation($c)->joindreNoteVocale(self::vocale(3));
        $chezLAutre = MessageClient::nouveau($autre, MessageClient::CLIENT, MessageClient::libelleVocale(5), 'Fanta', null, $le)->setConversation($cAutre)->joindreNoteVocale(self::vocale(5));

        self::assertTrue(NotesVocales::clientPeutEcouter($compte, $c, $reponse), 'réponse de l\'atelier');
        self::assertTrue(NotesVocales::clientPeutEcouter($compte, $c, $sienne), 'sa propre note');
        self::assertFalse(NotesVocales::clientPeutEcouter($compte, $c, $ecrite), 'message sans note vocale');
        self::assertFalse(NotesVocales::clientPeutEcouter($compte, $c, $evenement));
        self::assertFalse(NotesVocales::clientPeutEcouter($compteAutre, $c, $reponse), 'jamais la note d\'un autre client');
        self::assertFalse(NotesVocales::clientPeutEcouter($compteAutre, $cAutre, $reponse), 'ni en glissant sa propre conversation dans l\'adresse');
        self::assertFalse(NotesVocales::clientPeutEcouter($compte, $c, $chezLAutre), 'ni un message d\'une autre conversation');
        self::assertFalse(NotesVocales::clientPeutEcouter($lecture, $c, $reponse), '« Lecture · suivi » n\'a pas Messages');
        self::assertFalse(DroitsEspace::permis($lecture, 'GET', 'app_espace_message_vocale_ecouter'));
        self::assertFalse(DroitsEspace::permis($lecture, 'POST', 'app_espace_message_vocale'));
        self::assertTrue(DroitsEspace::permis($compte, 'POST', 'app_espace_message_vocale'));
    }

    public function testNoteInterneVocaleSeulementDansSaConversationEtPourLEquipe(): void
    {
        $client = new Clients();
        $le = new \DateTimeImmutable('2026-10-18 10:00');
        $caisse = self::avec(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_COMMANDE', 'ROLE_CLIENT']);
        $pao = self::avec(['ROLE_PREPRESSE']);
        $livreur = self::avec(['ROLE_LIVRAISON']);
        $c = ConversationClient::nouvelle($client, new Commandes(), null, null, ConversationClient::SUJET_COMMANDE, $le)->setAssigneA($caisse);
        $autre = ConversationClient::nouvelle($client, new Commandes(), null, null, ConversationClient::SUJET_COMMANDE, $le);
        $note = (new NoteConversation($c, $caisse, NoteConversation::libelleVocale(20), [], $le))->joindreNoteVocale(self::vocale(20));

        self::assertTrue(NotesVocales::appartient($note, $c));
        self::assertFalse(NotesVocales::appartient($note, $autre), 'l\'adresse ne mélange pas deux conversations');
        self::assertFalse(NotesVocales::appartient(new NoteConversation($c, $caisse, 'Texte', [], $le), $c), 'note écrite : rien à écouter');
        // Mêmes droits que la conversation (MessagerieVoter) : la PAO seulement si on l'a ajoutée, jamais le livreur.
        self::assertTrue(MessagerieVoter::peutVoir($caisse, $c));
        self::assertFalse(MessagerieVoter::peutVoir($pao, $c));
        self::assertFalse(MessagerieVoter::peutVoir($livreur, $c));
        (new \ReflectionProperty(User::class, 'id'))->setValue($pao, 41);
        $c->ajouterParticipant($pao);
        self::assertTrue(MessagerieVoter::peutVoir($pao, $c), 'ajoutée par un collègue');
    }

    /** @param list<string> $droits */
    private static function avec(array $droits): User
    {
        return new class($droits) extends User {
            /** @param list<string> $droits */
            public function __construct(private readonly array $droits)
            {
                parent::__construct();
            }

            public function getRoles(): array
            {
                return [...$this->droits, 'ROLE_USER'];
            }
        };
    }

    /** @return array{nom: string, type: string, taille: int, duree: int, empreinte: string} */
    private static function vocale(int $duree): array
    {
        return ['nom' => '0b2f4c1e-7d3a-4f6b-9a1c-2e3d4f5a6b7c.webm', 'type' => 'audio/webm', 'taille' => 4000, 'duree' => $duree, 'empreinte' => str_repeat('a', 64)];
    }
}
