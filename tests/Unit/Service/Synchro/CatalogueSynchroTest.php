<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\BonLivraison;
use App\Entity\Entreprise;
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
            'fichier' => ['chemin', 'observation', 'montage', 'ajoutePar', 'jetonUpload', 'fichierSource'],
            'controle' => ['anomalies', 'observation', 'correctionsEffectuees', 'controlePar', 'traitePar', 'validePar', 'envoyeProductionPar'],
            'paiement' => ['compteTresorerie', 'mouvementTresorerie', 'justificatif', 'observation', 'encaissePar', 'validePar'],
            'livraison' => ['livreur', 'creePar', 'validePar', 'livrePar', 'nomReceptionnaire', 'telephoneReceptionnaire', 'adresseLivraison', 'observation', 'photos', 'signature',
                'signataire', 'encaisseMontant', 'encaisseMode', 'encaisseLe', 'paiementLivraison', 'remiseLatitude', 'remiseLongitude', 'retardJustifie', 'erreurLivraison', 'articleStock', 'ordreTournee', 'echecPar', 'echecMotif'],
            'entreprise' => ['banque', 'compteBancaire', 'prefixeFacture', 'compteur', 'logo', 'logoPied', 'signature', 'cachet'],
        ];
        foreach ($interdits as $type => $champs) {
            foreach ($champs as $champ) {
                self::assertNotContains($champ, $types[$type]['champs'], $type.'.'.$champ);
                self::assertArrayNotHasKey($champ, $types[$type]['relations'], $type.'.'.$champ);
            }
        }
        // Une facture ne se crée jamais en ligne.
        self::assertFalse(CatalogueSynchro::creationAcceptee('facture', false));
        // Le bon de livraison ne part que dans un sens, et ses lignes sans observation.
        self::assertFalse(CatalogueSynchro::accepte('livraison', false));
        self::assertNotContains('observation', $types['livraison']['enfants']['lignes']['champs']);
        self::assertSame('livraison', CatalogueSynchro::typeDe(new BonLivraison()));
        // Les coordonnées de l'entreprise ne se modifient pas en ligne.
        self::assertFalse(CatalogueSynchro::accepte('entreprise', false));
        self::assertSame('entreprise', CatalogueSynchro::typeDe(new Entreprise()));
    }

    public function testFraisDeLivraisonEtObjetDesDocuments(): void
    {
        $types = CatalogueSynchro::types();
        foreach (['commande', 'devis', 'facture'] as $type) {
            self::assertContains('fraisLivraison', $types[$type]['champs'], $type);
            self::assertContains('objet', $types[$type]['champs'], $type);
            self::assertArrayNotHasKey('fraisLivraison', $types[$type]['remontees'] ?? [], $type.' : le site ne change pas les frais');
        }
        // Le client ne choisit pas les frais : une commande passée en ligne arrive sans frais, la boutique les fixe.
        self::assertSame(0, $types['commande']['imposeAuLocal']['fraisLivraison']);
        // Le bloc « NB » des factures descend avec l'entreprise.
        self::assertContains('conditionsPaiement', $types['entreprise']['champs']);
        // Les paliers et les objets suggérés restent au local.
        self::assertArrayNotHasKey(\App\Service\Parametres\ReglagesSaisie::TUILE, CatalogueSynchro::REGLAGES_PUBLICS);
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
        // Décision du valideur de l'entreprise : reprise une seule fois, depuis « à valider ».
        self::assertTrue(CatalogueSynchro::remonteePermise('commande', 'validationEntreprise', 'a_valider', 'validee'));
        self::assertTrue(CatalogueSynchro::remonteePermise('commande', 'validationEntreprise', 'a_valider', 'refusee'));
        self::assertFalse(CatalogueSynchro::remonteePermise('commande', 'validationEntreprise', 'refusee', 'validee'));
        self::assertFalse(CatalogueSynchro::remonteePermise('commande', 'validationEntreprise', null, 'validee'));
        self::assertTrue(CatalogueSynchro::remonteePermise('commande', 'valideePar', null, 'Awa'));
        self::assertFalse(CatalogueSynchro::remonteePermise('commande', 'valideePar', 'Awa', 'Moussa'));
        self::assertFalse(CatalogueSynchro::remonteePermise('commande', 'deleted', '0', '1'));
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
        // Suivi du BAT : ni graphiste, ni temps, ni points.
        $conception = CatalogueSynchro::types()['controle']['enfants']['conception'];
        foreach (['graphiste', 'secondes', 'tempsPrevu', 'erreurs', 'points', 'pointsPossibles', 'faits'] as $champ) {
            self::assertNotContains($champ, $conception['champs'], $champ);
        }
        self::assertArrayHasKey('evenements', $conception['filtres']);
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
            // Les liens « facultatifs » peuvent viser une donnée chargée plus loin.
            $relations = array_diff_key($t['relations'], array_flip($t['facultatives'] ?? []));
            foreach ([...array_values($relations), ...array_values($t['listes']), ...$enfants] as $cible) {
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

    public function testLeSiteNeRecoitQueLesReglagesPublics(): void
    {
        foreach (CatalogueSynchro::REGLAGES_PUBLICS as $tuile => $champs) {
            $connus = array_column(\App\Service\Parametres\CatalogueParametres::tuiles()[$tuile]['champs'] ?? [], 'cle');
            foreach ($champs as $champ) {
                self::assertContains($champ, $connus, $tuile.'.'.$champ);
            }
        }
        self::assertArrayNotHasKey('paie', CatalogueSynchro::REGLAGES_PUBLICS);
        self::assertArrayNotHasKey('synchronisation', CatalogueSynchro::REGLAGES_PUBLICS);

        // Valeurs par défaut pour ce que le local n'a jamais changé ; les autres champs restent au local.
        $paiements = CatalogueSynchro::reglagesPublics('paiements', ['orange_money' => false, 'acompte' => 30, 'virement_seuil' => 1]);
        self::assertSame(['orange_money' => false], $paiements);
        $horaires = CatalogueSynchro::reglagesPublics('horaires', ['feries' => '01/01']);
        self::assertSame(['jours' => 'lun-sam', 'ouverture' => '08:00', 'fermeture' => '18:00'], $horaires);
        self::assertSame([], CatalogueSynchro::reglagesPublics('paie', ['mode' => 'mensuel']));
    }

    public function testTauxDesFraisOrangeMoneyDescendent(): void
    {
        // Tenus dans ParametresPaiement : Synchro::noterReglages les ajoute aux valeurs de la tuile.
        $envoyes = CatalogueSynchro::reglagesPublics('paiements', ['orange_money' => true, 'acompte' => 30, 'tauxFondsSoutien' => 1.5, 'tauxFraisRetrait' => 1.0]);
        self::assertSame(['orange_money' => true, 'tauxFondsSoutien' => 1.5, 'tauxFraisRetrait' => 1.0], $envoyes);

        // À l'arrivée : les taux vont dans ParametresPaiement, le reste dans la tuile.
        [$tuile, $taux] = CatalogueSynchro::separerSourcePaiement('paiements', $envoyes);
        self::assertSame(['orange_money' => true], $tuile);
        self::assertSame(['tauxFondsSoutien' => 1.5, 'tauxFraisRetrait' => 1.0], $taux);

        // Contrôlés comme une saisie : un taux hors limites fait refuser l'envoi.
        $lire = new \ReflectionMethod(\App\Service\Synchro\Synchro::class, 'lireReglages');
        $publics = CatalogueSynchro::REGLAGES_PUBLICS['paiements'];
        self::assertSame($envoyes, $lire->invoke(null, 'paiements', $envoyes, $publics));
        self::assertNull($lire->invoke(null, 'paiements', ['tauxFondsSoutien' => 50] + $envoyes, $publics));
        // Un autre champ de la tuile (seuil de virement) ne passe jamais.
        self::assertSame(['orange_money' => true], $lire->invoke(null, 'paiements', ['orange_money' => true, 'virement_seuil' => 1], $publics));
    }

    public function testEspaceClientListeBlanche(): void
    {
        $types = CatalogueSynchro::types();
        // Carte « Votre conseiller(ère) » : le nom seulement, jamais l'employé ni la remise.
        self::assertContains('conseiller', $types['client']['champs']);
        self::assertNotContains('remiseHabituelle', $types['client']['champs']);
        // Offres cochées dans l'espace : l'accord remonte, rien d'autre.
        self::assertTrue(CatalogueSynchro::remonteePermise('client', 'consentementMarketingLe', null, '2026-10-14 10:00:00'));
        self::assertFalse(CatalogueSynchro::remonteePermise('client', 'raisonSociale', 'Hôtel Niger', 'Hôtel Niger SA'));
        // E-mail donné par le titulaire dans son espace : seulement sur une fiche du local qui n'en a pas.
        self::assertTrue(CatalogueSynchro::remonteePermise('client', 'email', null, 'yaya@gmail.com'));
        self::assertFalse(CatalogueSynchro::remonteePermise('client', 'email', 'contact@hotel-niger.ml', 'yaya@gmail.com'));
        self::assertFalse(CatalogueSynchro::remonteePermise('client', 'email', null, null));

        // Messages client ⇄ atelier : le texte, sans qui a traité ni l'employé qui a répondu.
        self::assertSame(['sens', 'auteur', 'texte', 'creeLe'], $types['message_client']['champs']);
        self::assertSame(['client' => 'client', 'commande' => 'commande'], $types['message_client']['relations']);
        self::assertSame(\App\Entity\MessageClient::CLIENT, $types['message_client']['imposeAuLocal']['sens'], 'un message écrit en ligne est toujours du client');
        self::assertTrue(CatalogueSynchro::creationAcceptee('message_client', false));

        // Changement de raison sociale ou NIF : arrive au local en attente, jamais appliqué tout seul.
        self::assertSame(\App\Entity\PropositionClient::EN_ATTENTE, $types['proposition_client']['imposeAuLocal']['statut']);
        self::assertArrayNotHasKey('traitePar', $types['proposition_client']['relations']);
        self::assertTrue(CatalogueSynchro::creationAcceptee('proposition_client', false));

        foreach (['message_client', 'proposition_client'] as $type) {
            foreach (['ecritPar', 'traitePar', 'observation', 'suiviPar'] as $interdit) {
                self::assertNotContains($interdit, $types[$type]['champs'], $type.'.'.$interdit);
                self::assertArrayNotHasKey($interdit, $types[$type]['relations'], $type.'.'.$interdit);
            }
        }
        self::assertNotContains('traiteLe', $types['message_client']['champs'], 'le classement par l\'atelier reste au local');
    }

    public function testLAccesALEspaceClientNeDescendQuEnEmpreinte(): void
    {
        $acces = CatalogueSynchro::types()['acces_espace'];
        // Jamais le code : son empreinte et son échéance seulement.
        self::assertSame(['empreinte', 'expireLe'], $acces['champs']);
        self::assertSame(['client' => 'client'], $acces['relations']);
        foreach (['code', 'creePar', 'creeLe', 'empreinteUtilisee', 'utiliseLe'] as $interdit) {
            self::assertNotContains($interdit, $acces['champs'], $interdit);
            self::assertArrayNotHasKey($interdit, $acces['relations'], $interdit);
        }
        // Donné au local seulement : le site ne renvoie rien (son utilisation reste en ligne).
        self::assertSame(CatalogueSynchro::DESCENDANT, $acces['sens']);
        self::assertTrue(CatalogueSynchro::envoye('acces_espace', false));
        self::assertFalse(CatalogueSynchro::envoye('acces_espace', true));
        self::assertFalse(CatalogueSynchro::accepte('acces_espace', false));
        self::assertSame(['client'], $acces['uniqueSur'] ?? null, 'un accès par client');
        self::assertSame(['empreinte', 'expireLe', 'client'], CatalogueSynchro::champsSuivis('acces_espace'), 'un nouveau code repart, son utilisation non');
        self::assertSame('acces_espace', CatalogueSynchro::typeDe(new \App\Entity\AccesEspaceClient()));
    }

    public function testLesAnnoncesDescendentAuSite(): void
    {
        $annonce = CatalogueSynchro::types()['site_annonce'];
        self::assertSame(\App\Entity\Annonce::class, $annonce['classe']);
        self::assertSame(CatalogueSynchro::DESCENDANT, $annonce['sens'], 'saisies à l\'atelier seulement');
        self::assertSame(['texte', 'lien', 'libelleLien', 'style', 'cible', 'debut', 'fin', 'publie', 'ordre', 'modifieLe'], $annonce['champs']);
        self::assertTrue(CatalogueSynchro::envoye('site_annonce', false));
        self::assertFalse(CatalogueSynchro::envoye('site_annonce', true));
        self::assertFalse(CatalogueSynchro::accepte('site_annonce', false));
        self::assertSame('site_annonce', CatalogueSynchro::typeDe(new \App\Entity\Annonce()));
    }

    public function testLEquipeDuSiteDescendSansRienDePrive(): void
    {
        $equipe = CatalogueSynchro::types()['site_equipe'];
        self::assertSame(\App\Entity\MembreEquipe::class, $equipe['classe']);
        self::assertSame(CatalogueSynchro::DESCENDANT, $equipe['sens'], 'saisie à l\'atelier seulement');
        self::assertSame(['nom', 'poste', 'ordre', 'visible', 'accordPersonne'], $equipe['champs'], 'ni téléphone, ni e-mail, ni salaire, ni compte');
        self::assertSame(['photo' => 'site_image'], $equipe['relations'], 'aucun lien vers le personnel ou un utilisateur');
        self::assertTrue(CatalogueSynchro::envoye('site_equipe', false));
        self::assertFalse(CatalogueSynchro::envoye('site_equipe', true));
        self::assertFalse(CatalogueSynchro::accepte('site_equipe', false));
        self::assertSame('site_equipe', CatalogueSynchro::typeDe(new \App\Entity\MembreEquipe()));
        // La photo (image du site) part avant la personne qui la cite.
        $ordre = array_keys(CatalogueSynchro::types());
        self::assertLessThan(array_search('site_equipe', $ordre, true), array_search('site_image', $ordre, true));

        // Masquer la personne, retirer son accord ou changer sa photo la renvoie au site.
        foreach (['visible', 'accordPersonne', 'photo', 'nom', 'poste', 'ordre'] as $champ) {
            self::assertContains($champ, CatalogueSynchro::champsSuivis('site_equipe'), $champ);
        }

        // Sans son accord, son nom et son poste partent vides.
        $membre = (new \App\Entity\MembreEquipe())->setNom('Awa')->setPoste('Graphiste PAO')->setVisible(true);
        foreach (['nom' => 'Awa', 'poste' => 'Graphiste PAO'] as $champ => $valeur) {
            self::assertSame('', ($equipe['filtres'][$champ])($valeur, $membre), $champ);
        }
        $membre->setAccordPersonne(true);
        self::assertSame('Awa', ($equipe['filtres']['nom'])('Awa', $membre));
        self::assertSame('Graphiste PAO', ($equipe['filtres']['poste'])('Graphiste PAO', $membre));
    }

    public function testLaSuspensionDeLEspaceClientDescendSansSonMotif(): void
    {
        $suspension = CatalogueSynchro::types()['suspension_espace'];
        // La fiche, le numéro (vide : tout le compte), le début et la levée : rien de plus.
        self::assertSame(['telephone', 'suspenduLe', 'leveeLe'], $suspension['champs']);
        self::assertSame(['client' => 'client'], $suspension['relations']);
        foreach (['motif', 'nom', 'suspenduPar', 'leveePar', 'noteLevee'] as $interdit) {
            self::assertNotContains($interdit, $suspension['champs'], $interdit);
            self::assertArrayNotHasKey($interdit, $suspension['relations'], $interdit);
        }
        // Décidée à la boutique : le site ne renvoie rien.
        self::assertSame(CatalogueSynchro::DESCENDANT, $suspension['sens']);
        self::assertTrue(CatalogueSynchro::envoye('suspension_espace', false));
        self::assertFalse(CatalogueSynchro::envoye('suspension_espace', true));
        self::assertFalse(CatalogueSynchro::accepte('suspension_espace', false));
        self::assertSame('suspension_espace', CatalogueSynchro::typeDe(new \App\Entity\SuspensionEspace()));

        // La personne à qui s'adresse un devis le suit au site.
        self::assertContains('interlocuteur', CatalogueSynchro::types()['devis']['champs']);
    }

    public function testFicheUniqueParRelationRemplaceeSeulementDuLocalVersLeSite(): void
    {
        $types = CatalogueSynchro::types();
        self::assertSame(['client'], $types['fidelite_compte']['uniqueSur'] ?? null, 'un compte fidélité recréé au local ne bloque plus la file');
        foreach ($types as $type => $def) {
            foreach ($def['uniqueSur'] ?? [] as $champ) {
                // Le site ne remplace une fiche que si le local est maître du type.
                self::assertSame(CatalogueSynchro::DESCENDANT, $def['sens'], $type);
                self::assertArrayHasKey($champ, $def['relations'], $type);
            }
        }
    }

    public function testImportDesDonneesDeDemarrage(): void
    {
        $types = CatalogueSynchro::types();
        // Le solde d'ouverture part avec sa commande : l'espace client le compte dans le solde, pas dans la liste.
        self::assertContains('soldeOuverture', $types['commande']['champs']);
        self::assertArrayNotHasKey('soldeOuverture', $types['commande']['remontees'] ?? [], 'seul le local crée un solde d\'ouverture');
        self::assertFalse($types['commande']['imposeAuLocal']['soldeOuverture'] ?? null);
        // Clients et produits importés partent par la synchronisation habituelle, avec leur liste blanche.
        self::assertSame(\App\Entity\Produits::class, $types['produit']['classe']);
        self::assertContains('prixB2B', $types['produit']['champs']);
        foreach (['import', 'importDonnees', 'empreinte'] as $champ) {
            self::assertNotContains($champ, $types['client']['champs'], $champ);
        }
        // Le journal des imports, les soldes d'ouverture et les articles de stock restent au local.
        $classes = array_column($types, 'classe');
        foreach ([\App\Entity\ImportDonnees::class, \App\Entity\SoldeOuverture::class, \App\Entity\Articles::class, \App\Entity\Depense::class] as $classe) {
            self::assertNotContains($classe, $classes, $classe);
        }
    }
}
