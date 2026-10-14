<?php

namespace App\Tests\Unit\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/**
 * Ce que l'assistant échange entre le local et le site : les réglages
 * publics descendent, les conversations montent, sans rien de privé.
 */
final class SynchroAssistantTest extends TestCase
{
    public function testReglagesQuiDescendent(): void
    {
        $tuile = CatalogueParametres::tuiles()['assistant'];
        self::assertContains('ROLE_ASSISTANT', $tuile['roles']);
        $publics = CatalogueSynchro::REGLAGES_PUBLICS['assistant'];
        self::assertSame(['actif', 'message_accueil', 'limite_jour', 'whatsapp', 'delais', 'modele', 'effort', 'taux_usd', 'prix_entree', 'prix_sortie', 'prix_cache_lecture', 'prix_cache_ecriture'], $publics);
        // La clé de l'API n'est pas un réglage : elle reste dans .env.local du serveur en ligne.
        foreach (array_column($tuile['champs'], 'cle') as $cle) {
            self::assertDoesNotMatchRegularExpression('/cle|key|secret|token|jeton/i', $cle);
        }

        $envoyes = CatalogueSynchro::reglagesPublics('assistant', ['actif' => true, 'limite_jour' => 2000, 'inconnu' => 'x']);
        self::assertSame($publics, array_keys($envoyes));
        self::assertTrue($envoyes['actif']);
        self::assertSame(2000, $envoyes['limite_jour']);
        self::assertSame('claude-opus-5-5', $envoyes['modele']);
        self::assertSame('low', $envoyes['effort']);
    }

    public function testConversationsQuiMontent(): void
    {
        $types = CatalogueSynchro::types();
        foreach (['assistant_conversation', 'assistant_message'] as $type) {
            self::assertSame(CatalogueSynchro::ASCENDANT, $types[$type]['sens']);
            self::assertTrue(CatalogueSynchro::envoye($type, true), 'le site envoie '.$type);
            self::assertFalse(CatalogueSynchro::envoye($type, false), 'le local n\'envoie pas '.$type);
            self::assertTrue(CatalogueSynchro::accepte($type, false), 'le local reçoit '.$type);
            self::assertFalse(CatalogueSynchro::accepte($type, true), 'le site ne reçoit pas '.$type);
            self::assertTrue(CatalogueSynchro::creationAcceptee($type, false));
        }
        self::assertSame('assistant_conversation', CatalogueSynchro::typeDe(new AssistantConversation()));
        self::assertSame('assistant_message', CatalogueSynchro::typeDe(new AssistantMessage()));

        // Liste blanche : ni jeton du visiteur, ni compte, ni échanges exacts avec l'API, ni adresse IP.
        $conversation = $types['assistant_conversation'];
        self::assertSame(['numero', 'contexte', 'interlocuteur', 'page', 'modele', 'debutLe', 'derniereLe', 'nombreQuestions', 'passageAgentLe'], $conversation['champs']);
        self::assertSame(['client' => 'client'], $conversation['relations']);
        $message = $types['assistant_message'];
        self::assertSame(['conversation' => 'assistant_conversation'], $message['relations']);
        foreach (['jeton', 'compte', 'contenuApi', 'actions', 'ip', 'id'] as $interdit) {
            self::assertNotContains($interdit, $conversation['champs'], 'conversation.'.$interdit);
            self::assertNotContains($interdit, $message['champs'], 'message.'.$interdit);
            self::assertArrayNotHasKey($interdit, $conversation['relations']);
        }

        // Ce qui change en ligne après la création remonte ; le reste non.
        self::assertTrue(CatalogueSynchro::remonteePermise('assistant_message', 'note', null, '1'));
        self::assertFalse(CatalogueSynchro::remonteePermise('assistant_message', 'texte', 'a', 'b'));
        self::assertTrue(CatalogueSynchro::remonteePermise('assistant_conversation', 'nombreQuestions', '1', '2'));
        self::assertFalse(CatalogueSynchro::remonteePermise('assistant_conversation', 'contexte', 'site', 'espace'));
    }
}
