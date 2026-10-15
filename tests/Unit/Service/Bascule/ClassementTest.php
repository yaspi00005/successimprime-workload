<?php

namespace App\Tests\Unit\Service\Bascule;

use App\Service\Bascule\Classement;
use App\Service\Bascule\Constat;
use App\Service\Bascule\Ecart;
use App\Service\Bascule\SondeDonnees;
use App\Service\Bascule\SondePessimiste;
use PHPUnit\Framework\TestCase;

final class ClassementTest extends TestCase
{
    private static function colonne(string $type, array $plus = []): array
    {
        return $plus + ['type' => $type, 'longueur' => null, 'precision' => null, 'echelle' => 0, 'nonSigne' => false, 'obligatoire' => true, 'defaut' => null];
    }

    /** @param array<string, mixed> $donnees */
    private static function sonde(array $donnees = []): SondeDonnees
    {
        return new class($donnees) implements SondeDonnees {
            public function __construct(private readonly array $d)
            {
            }

            public function lignes(string $table): int
            {
                return $this->d['lignes'][$table] ?? 0;
            }

            public function renseignees(string $table, string $colonne): int
            {
                return $this->d['renseignees'][$table.'.'.$colonne] ?? $this->lignes($table);
            }

            public function longueurMax(string $table, string $colonne): int
            {
                return $this->d['longueur'][$table.'.'.$colonne] ?? 0;
            }

            public function doublons(string $table, array $colonnes, bool $sansIdentifiantsVides = false): int
            {
                return $this->d[$sansIdentifiantsVides ? 'doublonsPleins' : 'doublons'][$table] ?? 0;
            }

            public function identifiantsVides(string $table, string $colonne): int
            {
                return $this->d['vides'][$table] ?? 0;
            }

            public function orphelins(string $table, array $colonnes, string $parente, array $colonnesParentes): int
            {
                return $this->d['orphelins'][$table] ?? 0;
            }

            public function valeurExiste(string $table, string $colonne, string $valeur): bool
            {
                return \in_array($table.'.'.$valeur, $this->d['valeurs'] ?? [], true);
            }
        };
    }

    private static function un(Ecart $e, array $donnees = [], bool $garderOrphelins = false): Constat
    {
        return Classement::classer([$e], self::sonde($donnees), $garderOrphelins)[0];
    }

    public function testTables(): void
    {
        self::assertSame(Constat::AJOUT, self::un(new Ecart(Ecart::TABLE_AJOUTEE, 'achats'))->niveau);
        self::assertSame(Constat::SANS_PERTE, self::un(new Ecart(Ecart::TABLE_EN_TROP, 'vieille'))->niveau);
        $c = self::un(new Ecart(Ecart::TABLE_EN_TROP, 'vieille'), ['lignes' => ['vieille' => 4]]);
        self::assertTrue($c->estBloquant());
        self::assertStringContainsString('4 ligne(s)', $c->message);
    }

    public function testColonneAjoutee(): void
    {
        $remplie = ['lignes' => ['paiements' => 10, 'devis' => 3, 'clients' => 3]];

        // Facultative, ou obligatoire avec une valeur par défaut : sans risque.
        self::assertSame(Constat::AJOUT, self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'paiements', 'observation', [], self::colonne('text', ['obligatoire' => false])), $remplie)->niveau);
        $defaut = self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'devis', 'emetteur', [], self::colonne('string', ['longueur' => 30, 'defaut' => 'mdg_success'])), $remplie);
        self::assertSame(Constat::AJOUT, $defaut->niveau);
        self::assertStringContainsString('« mdg_success »', $defaut->message);

        // Obligatoire sans défaut : sans risque sur une table vide…
        self::assertSame(Constat::AJOUT, self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'factures', 'date_facture', [], self::colonne('datetime')), $remplie)->niveau);
        // … bloquant sur une table remplie, sauf valeur de reprise connue.
        self::assertTrue(self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'devis', 'date_devis', [], self::colonne('datetime')), $remplie)->estBloquant());

        $statut = self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'paiements', 'statut', [], self::colonne('string', ['longueur' => 20])), $remplie);
        self::assertSame(Constat::AJOUT, $statut->niveau);
        self::assertSame("'valide'", $statut->reprise);
        self::assertSame(Constat::SANS_VALEUR, $statut->repriseLignes);

        $uuid = self::un(new Ecart(Ecart::COLONNE_AJOUTEE, 'clients', 'public_id', [], self::colonne('binary', ['longueur' => 16])), $remplie);
        self::assertSame(Constat::AJOUT, $uuid->niveau);
        self::assertStringContainsString('UUID()', (string) $uuid->reprise);
    }

    public function testColonneEnTrop(): void
    {
        $e = new Ecart(Ecart::COLONNE_EN_TROP, 'devis', 'date_creation', self::colonne('datetime'));
        self::assertSame(Constat::SANS_PERTE, self::un($e)->niveau, 'table vide');
        self::assertSame(Constat::SANS_PERTE, self::un($e, ['lignes' => ['devis' => 5], 'renseignees' => ['devis.date_creation' => 0]])->niveau, 'colonne vide');
        $c = self::un($e, ['lignes' => ['devis' => 5], 'renseignees' => ['devis.date_creation' => 5]]);
        self::assertTrue($c->estBloquant());
        self::assertStringContainsString('perdrait', $c->message);
    }

    public function testColonneModifiee(): void
    {
        $mode = new Ecart(Ecart::COLONNE_MODIFIEE, 'paiements', 'mode', self::colonne('string', ['longueur' => 100]), self::colonne('string', ['longueur' => 30]));
        $c = self::un($mode, ['lignes' => ['paiements' => 10], 'longueur' => ['paiements.mode' => 12]]);
        self::assertSame(Constat::SANS_PERTE, $c->niveau);
        self::assertStringContainsString('12 caractère(s)', $c->message);
        self::assertTrue(self::un($mode, ['lignes' => ['paiements' => 10], 'longueur' => ['paiements.mode' => 40]])->estBloquant());

        $prix = new Ecart(Ecart::COLONNE_MODIFIEE, 'commandes_details', 'prix_unitaire', self::colonne('integer', ['defaut' => '0']), self::colonne('decimal', ['precision' => 15, 'echelle' => 4, 'defaut' => '0.0000']));
        self::assertSame(Constat::SANS_PERTE, self::un($prix, ['lignes' => ['commandes_details' => 5]])->niveau);

        // Changement de sens (0/1 → « brouillon ») : bloquant dès qu'il y a des valeurs.
        $etat = new Ecart(Ecart::COLONNE_MODIFIEE, 'factures', 'etat', self::colonne('boolean'), self::colonne('string', ['longueur' => 20, 'defaut' => 'brouillon']));
        self::assertTrue(self::un($etat, ['lignes' => ['factures' => 2]])->estBloquant());
        self::assertSame(Constat::SANS_PERTE, self::un($etat)->niveau, 'table vide');
        self::assertSame(Constat::SANS_PERTE, self::un($etat, ['lignes' => ['factures' => 2], 'renseignees' => ['factures.etat' => 0]])->niveau, 'colonne vide');

        // Devenir obligatoire avec des lignes vides : bloquant.
        $nom = new Ecart(Ecart::COLONNE_MODIFIEE, 'clients', 'nom', self::colonne('string', ['longueur' => 80, 'obligatoire' => false]), self::colonne('string', ['longueur' => 80]));
        self::assertTrue(self::un($nom, ['lignes' => ['clients' => 3], 'renseignees' => ['clients.nom' => 2]])->estBloquant());
        $plein = self::un($nom, ['lignes' => ['clients' => 3]]);
        self::assertSame(Constat::SANS_PERTE, $plein->niveau);
        self::assertStringContainsString('même type', $plein->message);

        // Sauf un identifiant public : un tirage pour chaque ligne sans valeur.
        $id = new Ecart(Ecart::COLONNE_MODIFIEE, 'commandes', 'public_id', self::colonne('binary', ['longueur' => 16, 'obligatoire' => false]), self::colonne('binary', ['longueur' => 16]));
        $c = self::un($id, ['lignes' => ['commandes' => 3], 'renseignees' => ['commandes.public_id' => 1]]);
        self::assertSame(Constat::SANS_PERTE, $c->niveau);
        self::assertSame(Constat::IDENTIFIANT_VIDE, $c->repriseLignes);
    }

    public function testElargissements(): void
    {
        $c = self::colonne(...);
        self::assertTrue(Classement::sansPerte($c('decimal', ['precision' => 10, 'echelle' => 2]), $c('decimal', ['precision' => 12, 'echelle' => 4])));
        self::assertFalse(Classement::sansPerte($c('decimal', ['precision' => 12, 'echelle' => 4]), $c('decimal', ['precision' => 10, 'echelle' => 2])));
        self::assertFalse(Classement::sansPerte($c('decimal', ['precision' => 10, 'echelle' => 2]), $c('decimal', ['precision' => 10, 'echelle' => 4])), 'moins de chiffres avant la virgule');
        self::assertTrue(Classement::sansPerte($c('integer'), $c('bigint')));
        self::assertFalse(Classement::sansPerte($c('bigint'), $c('integer')));
        self::assertTrue(Classement::sansPerte($c('smallint'), $c('integer')));
        self::assertTrue(Classement::sansPerte($c('boolean'), $c('smallint')));
        self::assertFalse(Classement::sansPerte($c('integer', ['nonSigne' => true]), $c('integer')));
        self::assertTrue(Classement::sansPerte($c('integer'), $c('float')));
        self::assertFalse(Classement::sansPerte($c('bigint'), $c('float')));
        self::assertTrue(Classement::sansPerte($c('integer'), $c('decimal', ['precision' => 15, 'echelle' => 4])));
        self::assertFalse(Classement::sansPerte($c('integer'), $c('decimal', ['precision' => 10, 'echelle' => 2])));
        self::assertTrue(Classement::sansPerte($c('string', ['longueur' => 100]), $c('string', ['longueur' => 255])));
        self::assertFalse(Classement::sansPerte($c('string', ['longueur' => 255]), $c('string', ['longueur' => 100])));
        self::assertTrue(Classement::sansPerte($c('string', ['longueur' => 255]), $c('text')));
        self::assertFalse(Classement::sansPerte($c('text'), $c('string', ['longueur' => 255])));
        self::assertTrue(Classement::sansPerte($c('date'), $c('datetime')));
        self::assertTrue(Classement::sansPerte($c('json'), $c('text')));
        self::assertFalse(Classement::sansPerte($c('text'), $c('json')));
        self::assertFalse(Classement::sansPerte($c('integer'), $c('string', ['longueur' => 50])), 'changement de type : jamais deviné');
        self::assertSame('decimal(15,4) obligatoire, par défaut « 0 »', Classement::decrire($c('decimal', ['precision' => 15, 'echelle' => 4, 'defaut' => '0'])));
        self::assertSame('string(30) facultatif', Classement::decrire($c('string', ['longueur' => 30, 'obligatoire' => false])));
    }

    public function testIndexUnique(): void
    {
        $unique = new Ecart(Ecart::INDEX_AJOUTE, 'factures', 'uniq_facture_numero', [], ['colonnes' => ['numero'], 'unique' => true]);
        self::assertSame(Constat::AJOUT, self::un($unique, ['lignes' => ['factures' => 9]])->niveau);
        self::assertTrue(self::un($unique, ['lignes' => ['factures' => 9], 'doublons' => ['factures' => 2]])->estBloquant());
        self::assertSame(Constat::AJOUT, self::un(new Ecart(Ecart::INDEX_AJOUTE, 'factures', 'idx', [], ['colonnes' => ['numero'], 'unique' => false]), ['lignes' => ['factures' => 9], 'doublons' => ['factures' => 2]])->niveau);

        // Identifiants publics restés à zéro (colonne ajoutée sans être remplie) : retirés au hasard.
        $public = new Ecart(Ecart::INDEX_AJOUTE, 'commandes', 'UNIQ_35D4282CB5B48B91', [], ['colonnes' => ['public_id'], 'unique' => true]);
        $c = self::un($public, ['lignes' => ['commandes' => 3], 'doublons' => ['commandes' => 1], 'doublonsPleins' => ['commandes' => 0], 'vides' => ['commandes' => 3]]);
        self::assertSame(Constat::AJOUT, $c->niveau);
        self::assertSame(Constat::IDENTIFIANT_VIDE, $c->repriseLignes);
        // De vrais doublons : bloquant.
        self::assertTrue(self::un($public, ['lignes' => ['commandes' => 3], 'doublons' => ['commandes' => 1], 'doublonsPleins' => ['commandes' => 1]])->estBloquant());
    }

    public function testIndexUniqueSurUneNouvelleColonne(): void
    {
        $donnees = ['lignes' => ['clients' => 3]];
        $public = [
            new Ecart(Ecart::COLONNE_AJOUTEE, 'clients', 'public_id', [], self::colonne('binary', ['longueur' => 16])),
            new Ecart(Ecart::INDEX_AJOUTE, 'clients', 'UNIQ_C82E74B5B48B91', [], ['colonnes' => ['public_id'], 'unique' => true]),
        ];
        self::assertSame(Constat::AJOUT, Classement::classer($public, self::sonde($donnees))[1]->niveau);

        $code = [
            new Ecart(Ecart::COLONNE_AJOUTEE, 'clients', 'code', [], self::colonne('string', ['longueur' => 10, 'defaut' => 'X'])),
            new Ecart(Ecart::INDEX_AJOUTE, 'clients', 'uniq_code', [], ['colonnes' => ['code'], 'unique' => true]),
        ];
        self::assertTrue(Classement::classer($code, self::sonde($donnees))[1]->estBloquant(), 'même valeur par défaut sur trois lignes');
    }

    public function testLiens(): void
    {
        $lien = new Ecart(Ecart::CLE_AJOUTEE, 'paiements', 'FK_E1B02E1282EA2E54', [], ['colonnes' => ['commande_id'], 'parente' => 'commandes', 'colonnesParentes' => ['id']]);
        self::assertSame(Constat::AJOUT, self::un($lien, ['lignes' => ['paiements' => 10]])->niveau);
        $c = self::un($lien, ['lignes' => ['paiements' => 10], 'orphelins' => ['paiements' => 6]]);
        self::assertTrue($c->estBloquant());
        self::assertStringContainsString('--garder-orphelins', $c->message);

        $garde = self::un($lien, ['lignes' => ['paiements' => 10], 'orphelins' => ['paiements' => 6]], true);
        self::assertSame(Constat::SANS_PERTE, $garde->niveau);
        self::assertTrue($garde->lienNonVerifie);

        // Vers une table qui va être créée : seulement si la colonne est vide.
        $versNouvelle = [new Ecart(Ecart::TABLE_AJOUTEE, 'compte_tresorerie'), new Ecart(Ecart::CLE_AJOUTEE, 'paiements', 'FK_X', [], ['colonnes' => ['compte_tresorerie_id'], 'parente' => 'compte_tresorerie', 'colonnesParentes' => ['id']])];
        self::assertSame(Constat::AJOUT, Classement::classer($versNouvelle, self::sonde(['lignes' => ['paiements' => 10], 'renseignees' => ['paiements.compte_tresorerie_id' => 0]]))[1]->niveau);
        self::assertTrue(Classement::classer($versNouvelle, self::sonde(['lignes' => ['paiements' => 10]]))[1]->estBloquant());

        // Sur une nouvelle colonne : vide, ou valeur par défaut présente dans la table parente.
        $societe = static fn (array $colonne): array => [
            new Ecart(Ecart::COLONNE_AJOUTEE, 'achats', 'societe_id', [], $colonne),
            new Ecart(Ecart::CLE_AJOUTEE, 'achats', 'FK_S', [], ['colonnes' => ['societe_id'], 'parente' => 'societe', 'colonnesParentes' => ['id']]),
        ];
        $donnees = ['lignes' => ['achats' => 4]];
        self::assertSame(Constat::AJOUT, Classement::classer($societe(self::colonne('integer', ['obligatoire' => false])), self::sonde($donnees))[1]->niveau);
        self::assertSame(Constat::AJOUT, Classement::classer($societe(self::colonne('integer', ['defaut' => '1'])), self::sonde($donnees + ['valeurs' => ['societe.1']]))[1]->niveau);
        self::assertTrue(Classement::classer($societe(self::colonne('integer', ['defaut' => '1'])), self::sonde($donnees))[1]->estBloquant());
    }

    public function testSansRisquePourLesRetraits(): void
    {
        foreach ([Ecart::INDEX_RETIRE, Ecart::INDEX_RENOMME, Ecart::CLE_RETIREE] as $genre) {
            self::assertSame(Constat::SANS_PERTE, self::un(new Ecart($genre, 'devis', 'x', [], ['nom' => 'y']), ['lignes' => ['devis' => 3]])->niveau);
        }
    }

    public function testPireCasPourUneCopieSansDonnees(): void
    {
        // Sur une copie de la structure, ce qui dépend des données ressort comme bloquant.
        $ecarts = [
            new Ecart(Ecart::TABLE_AJOUTEE, 'achats'),
            new Ecart(Ecart::COLONNE_EN_TROP, 'devis', 'date_creation', self::colonne('datetime')),
            new Ecart(Ecart::COLONNE_AJOUTEE, 'paiements', 'observation', [], self::colonne('text', ['obligatoire' => false])),
            new Ecart(Ecart::COLONNE_AJOUTEE, 'devis', 'date_devis', [], self::colonne('datetime')),
            new Ecart(Ecart::INDEX_RETIRE, 'devis', 'idx'),
        ];
        $bloquants = array_map(static fn (Constat $c): string => $c->ecart->cible(), array_filter(Classement::classer($ecarts, new SondePessimiste()), static fn (Constat $c): bool => $c->estBloquant()));
        self::assertSame(['devis.date_creation', 'devis.date_devis'], array_values($bloquants));
    }
}
