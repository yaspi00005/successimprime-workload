<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\Clients;
use App\Entity\Finition;
use App\Entity\Paiements;
use App\Service\Synchro\CatalogueSynchro;
use App\Service\Synchro\JournalSynchro;
use App\Service\Synchro\Synchro;
use PHPUnit\Framework\TestCase;

final class CatalogueSynchroTest extends TestCase
{
    public function testLesDonneesSensiblesNePartentPas(): void
    {
        $types = CatalogueSynchro::types();
        self::assertNotContains('coutUnitaire', $types['finition']['champs'], 'coût de revient');
        foreach (['observation', 'remiseHabituelle', 'suiviPar', 'etiquettes', 'besoin', 'origine', 'secteur'] as $champ) {
            self::assertNotContains($champ, $types['client']['champs'], $champ);
            self::assertArrayNotHasKey($champ, $types['client']['relations'], $champ);
        }
        foreach ($types as $cle => $t) {
            foreach ($t['champs'] as $champ) {
                self::assertDoesNotMatchRegularExpression('/cout|marge|salaire|password|motDePasse/i', $champ, $cle.'.'.$champ);
            }
        }
    }

    public function testLesDocumentsGardentLeursSecretsAuLocal(): void
    {
        $types = CatalogueSynchro::types();
        $interdits = [
            'commande' => ['observation', 'agents', 'bonusPlafond'],
            'commande_ligne' => ['coutRevient', 'temps', 'machine', 'rebut', 'observations', 'fichier'],
            'devis_ligne' => ['coutRevient', 'temps', 'observation', 'fichier'],
            'facture' => ['comptabilisee', 'surfacturation', 'tauxSurfacturation', 'montantSurfacturation', 'motifSurfacturation', 'pdfFichier', 'pdfHash', 'observation'],
            'paiement' => ['compteTresorerie', 'mouvementTresorerie', 'justificatif', 'observation', 'encaissePar', 'validePar'],
        ];
        foreach ($interdits as $type => $champs) {
            foreach ($champs as $champ) {
                self::assertNotContains($champ, $types[$type]['champs'], $type.'.'.$champ);
                self::assertArrayNotHasKey($champ, $types[$type]['relations'], $type.'.'.$champ);
            }
        }
        // Une facture ne se crée jamais en ligne.
        self::assertFalse(CatalogueSynchro::creationAcceptee('facture', false));
    }

    public function testUnPaiementSaisiEnLigneResteAValider(): void
    {
        $impose = CatalogueSynchro::types()['paiement']['imposeAuLocal'];
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $impose['statut']);
        self::assertArrayHasKey('dateValidation', $impose);
        self::assertTrue(CatalogueSynchro::accepte('paiement', false));
    }

    public function testDecisionsDuClientEnLigne(): void
    {
        // Accepter un devis envoyé : repris au local. Le reste : conflit.
        self::assertTrue(CatalogueSynchro::remonteePermise('devis', 'statut', 'envoye', 'accepte'));
        self::assertFalse(CatalogueSynchro::remonteePermise('devis', 'statut', 'brouillon', 'accepte'));
        self::assertFalse(CatalogueSynchro::remonteePermise('devis', 'statut', 'envoye', 'converti'));
        self::assertFalse(CatalogueSynchro::remonteePermise('devis', 'totalTtc', '1000', '1'));
        self::assertTrue(CatalogueSynchro::remonteePermise('client', 'recevoirSms', '1', '0'));
        self::assertFalse(CatalogueSynchro::remonteePermise('client', 'ville', 'Bamako', 'Kati'));
        self::assertFalse(CatalogueSynchro::remonteePermise('commande', 'totalTtc', '1', '0'));
        // Jeton du lien PDF créé en ligne : gardé s'il n'y en avait pas au local.
        self::assertTrue(CatalogueSynchro::remonteePermise('facture', 'tokenAuthenticite', null, 'abc'));
        self::assertFalse(CatalogueSynchro::remonteePermise('facture', 'tokenAuthenticite', 'xyz', 'abc'));
        self::assertFalse(CatalogueSynchro::remonteePermise('facture', 'tokenAuthenticite', null, null));

        // Le site envoie les devis (pour l'acceptation), mais un devis créé en ligne n'est pas repris.
        self::assertTrue(CatalogueSynchro::envoye('devis', true));
        self::assertFalse(CatalogueSynchro::envoye('format', true));
        self::assertFalse(CatalogueSynchro::creationAcceptee('devis', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('paiement', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('devis', true));
    }

    public function testFinitionsDesLignes(): void
    {
        $ligne = new \App\Entity\CommandesDetails();
        $finition = (new \App\Entity\CommandeDetailFinition())->setCommandeDetail($ligne);
        self::assertSame($ligne, CatalogueSynchro::parentDe($finition), 'la ligne repart avec ses finitions');
        self::assertNull(CatalogueSynchro::parentDe($ligne));
        foreach (['commande_ligne', 'devis_ligne'] as $type) {
            self::assertNotContains('coutUnitaire', CatalogueSynchro::types()[$type]['enfants']['finitions']['champs']);
        }
    }

    public function testNumeroDuSite(): void
    {
        self::assertTrue(JournalSynchro::aPrefixer('CMD-00012'));
        self::assertFalse(JournalSynchro::aPrefixer('W-CMD-00012'), 'déjà préfixé');
        self::assertFalse(JournalSynchro::aPrefixer('TMP-1'), 'numéro provisoire');
        self::assertFalse(JournalSynchro::aPrefixer(''));
        self::assertFalse(JournalSynchro::aPrefixer(null));
        foreach (CatalogueSynchro::types() as $cle => $t) {
            if (isset($t['numero'])) {
                self::assertContains($t['numero'], $t['champs'], $cle);
            }
        }
    }

    public function testUneDonneeVientApresCellesQuelleReference(): void
    {
        $vus = [];
        foreach (CatalogueSynchro::types() as $cle => $t) {
            $enfants = array_merge([], ...array_map(static fn (array $e): array => array_values($e['relations']), array_values($t['enfants'] ?? [])));
            foreach ([...array_values($t['relations']), ...array_values($t['listes']), ...$enfants] as $cible) {
                self::assertArrayHasKey($cible, $vus, $cle.' référence '.$cible.' qui doit venir avant');
            }
            $vus[$cle] = true;
            self::assertTrue(property_exists($t['classe'], 'publicId'), $cle.' a besoin d\'un identifiant public');
        }
    }

    public function testSens(): void
    {
        // Le local envoie tout, le site seulement les fiches à double sens.
        self::assertTrue(CatalogueSynchro::envoye('produit', false));
        self::assertFalse(CatalogueSynchro::envoye('produit', true));
        self::assertTrue(CatalogueSynchro::envoye('client', true));
        // Le site accepte tout, le local seulement les fiches à double sens.
        self::assertTrue(CatalogueSynchro::accepte('produit', true));
        self::assertFalse(CatalogueSynchro::accepte('produit', false));
        self::assertTrue(CatalogueSynchro::accepte('client', false));
        self::assertFalse(CatalogueSynchro::accepte('inconnu', true));

        self::assertSame('client', CatalogueSynchro::typeDe(new Clients()));
        self::assertSame('finition', CatalogueSynchro::typeDe(new Finition()));
        self::assertNull(CatalogueSynchro::typeDe(new \stdClass()));
    }

    public function testAlerteApres15Minutes(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-12 12:00');
        self::assertNull(Synchro::alerte(false, null, null, $maintenant, null), 'pas réglée : pas d\'alerte');
        self::assertNull(Synchro::alerte(true, null, null, $maintenant, null), 'l\'agent n\'a jamais tourné');
        self::assertNull(Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 11:50'), null, $maintenant, 'coupure'));
        self::assertSame('Pas de synchronisation réussie depuis 15 min : coupure', Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 11:45'), null, $maintenant, 'coupure'));
        self::assertSame('Pas de synchronisation réussie depuis 3 h.', Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 09:00'), null, $maintenant, null));
        self::assertStringStartsWith('La synchronisation n\'a jamais réussi', (string) Synchro::alerte(true, null, new \DateTimeImmutable('2026-10-12 11:00'), $maintenant, 'clé'));
    }
}
