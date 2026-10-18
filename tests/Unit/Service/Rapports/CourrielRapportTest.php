<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Service\Rapports\CourrielRapport;
use App\Service\Rapports\DroitsRapport;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\RapportPeriodique;
use App\Service\Statistiques\Objectifs;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * L'e-mail de chaque destinataire (docs/rapports-mail.md) : les modèles
 * HTML et texte rendus avec les parties que ses droits ouvrent. Sans le
 * droit, ni le montant ni le nom du client n'apparaissent, dans aucune
 * des deux parties.
 */
final class CourrielRapportTest extends TestCase
{
    private const DIRECTION = ['ROLE_ADMIN', 'ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR', 'ROLE_COUT_MARGE', 'ROLE_CLIENT', 'ROLE_RH'];

    /** Noms de clients et de personnes du jeu d'essai. */
    private const NOMS = ['Hôtel Lumière', 'Salon Espoir', 'Clinique Koné', 'Institut Kayes', 'Rokia Sissoko'];

    private static function twig(): Environment
    {
        return new Environment(new FilesystemLoader(\dirname(__DIR__, 4).'/templates'), ['strict_variables' => true]);
    }

    /**
     * Un rapport comme RapportPeriodique le calcule : chaque partie
     * seulement avec son droit.
     *
     * @param list<string> $roles
     *
     * @return array<string, mixed>
     */
    private static function contexte(array $roles, PeriodeRapport $p, ?string $lien = null): array
    {
        $droits = DroitsRapport::depuisRoles($roles);
        $o = RapportPeriodique::parties($droits, $p->type);
        $r = [
            'periode' => $p, 'comparaison' => $p->comparaison(), 'arrete_le' => $p->fin, 'en_cours' => false, 'droits' => $droits, 'parties' => $o,
            'activite' => [
                'commandes' => ['nombre' => 12, 'avant' => 10, 'objectif' => 15, 'mois' => null],
                'devis' => ['emis' => 8, 'signes' => 3, 'emis_avant' => 6, 'a_relancer' => 2],
                'surface' => ['valeur' => 45.5, 'avant' => 40.0, 'objectif' => null],
                'clients' => ['nombre' => 2, 'avant' => 1, 'objectif' => null, 'noms' => $o['noms_clients'] ? ['Hôtel Lumière', 'Salon Espoir'] : null],
                'livraisons' => ['livrees' => 5, 'echecs' => 1],
                'bat' => ['valides' => 4, 'en_attente' => 2, 'sans_reponse' => 1],
                'reclamations' => ['nouvelles' => 1, 'ouvertes' => 2, 'anciennes' => 1, 'liste' => $o['noms_clients'] ? [['numero' => 'R-12', 'objet' => 'Couleur pâle', 'client' => 'Clinique Koné']] : null],
                'en_retard' => 3,
            ],
            'ventes' => $o['ventes'] ? [
                'facture' => 1350000, 'avant' => 1200000, 'objectif' => 1500000, 'part' => 90, 'de_mois' => 'd’octobre 2026',
                'mois' => PeriodeRapport::MOIS === $p->type ? null : Objectifs::avancement(4361125, 9000000, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01'), $p->fin),
            ] : null,
            'encaissements' => $o['encaissements'] ? ['total' => 980000, 'avant' => 1000000, 'modes' => [['libelle' => 'Orange Money', 'montant' => 600000, 'nombre' => 7], ['libelle' => 'Espèces', 'montant' => 380000, 'nombre' => 9]], 'taux' => 72.6, 'taux_objectif' => 80, 'attente' => ['nombre' => 1, 'montant' => 25000]] : null,
            'creances' => $o['creances'] ? ['total' => 3400000, 'echues' => 1250000, 'nb_echues' => 4, 'plus_60' => 300000, 'debiteurs' => $o['noms_clients'] ? [['nom' => 'Institut Kayes', 'reste' => 900000]] : null] : null,
            'tresorerie' => $o['tresorerie'] ? ['comptes' => [['nom' => 'Banque BDM', 'type' => 'banque', 'solde' => 2900000]], 'total' => 2900000, 'seuil' => 2000000, 'depenses' => ['total' => 610000, 'avant' => 500000, 'categories' => [['libelle' => 'Loyer', 'montant' => 450000]], 'hors_salaires' => 450000, 'budget' => null]] : null,
            'marge' => $o['marge'] ? ['taux' => 41, 'avant' => 44] : null,
            'equipe' => $o['equipe'] ? ['effectif' => 6, 'presents' => 5, 'attendus' => 6, 'retards' => 2, 'absences_non_justifiees' => 1, 'lignes' => [['nom' => 'Rokia Sissoko', 'retards' => 2, 'minutes' => 35, 'absences' => 1, 'justifiees' => 0]]] : null,
            'stock' => $o['stock'] ? [['designation' => 'Encre solvant cyan', 'reste' => 1.5, 'unite' => 'litre']] : null,
            'taches' => ['nombre' => 1, 'liste' => [['titre' => 'Déclarer la TVA', 'prevue' => new \DateTimeImmutable('2026-10-15')]]],
        ];
        $r['alertes'] = RapportPeriodique::alertes($r, $droits);

        return [
            'r' => $r, 'p' => $p, 'prenom' => 'Awa',
            'version' => DroitsRapport::version($droits), 'contenu' => DroitsRapport::contenu($droits),
            'marque' => ['nom' => 'Succès Imprim', 'couleurs' => ['#0F4590', '#E30613']],
            'lien' => $lien, 'genere_le' => new \DateTimeImmutable('2026-11-01 07:00'), 'essai' => false,
        ];
    }

    /** @return array{0: string, 1: string} le HTML (sans balises) et le texte */
    private static function rendre(array $contexte): array
    {
        $twig = self::twig();
        $html = $twig->render(CourrielRapport::MODELE_HTML, $contexte);

        return [html_entity_decode(strip_tags($html)), $twig->render(CourrielRapport::MODELE_TEXTE, $contexte)];
    }

    /** Montants « 1 250 000 F » dans un texte. */
    private static function montants(string $texte): array
    {
        preg_match_all('/\d[\d  ]* F(?![\p{L}\d])/u', $texte, $m);

        return $m[0];
    }

    public function testLaDirectionRecoitTout(): void
    {
        foreach (self::rendre(self::contexte(self::DIRECTION, PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10'))) as $partie) {
            foreach (['Encaissements', 'Créances clients', 'Dépenses et trésorerie', 'Équipe', 'Stock sous le seuil', '1 350 000 F', '1 250 000 F', 'Banque BDM'] as $attendu) {
                self::assertStringContainsStringIgnoringCase($attendu, $partie);
            }
            foreach (self::NOMS as $nom) {
                self::assertStringContainsString($nom, $partie);
            }
        }
    }

    public function testActiviteSeuleNiMontantNiNom(): void
    {
        foreach (self::rendre(self::contexte(['ROLE_CAISSE_COMMANDE', 'ROLE_STATS_GLOBAL'], PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10'))) as $partie) {
            self::assertSame([], self::montants($partie));
            foreach (self::NOMS as $nom) {
                self::assertStringNotContainsString($nom, $partie);
            }
            // Titres des parties (HTML, puis texte en capitales) ; le pied de page dit seulement ce qui manque.
            foreach (['Encaissements', 'ENCAISSEMENTS', 'Créances clients', 'CRÉANCES CLIENTS', 'Dépenses et trésorerie', 'DÉPENSES ET TRÉSORERIE', 'Banque BDM', 'Taux de marge', 'Stock sous le seuil', 'STOCK SOUS LE SEUIL', 'Encre solvant'] as $absent) {
                self::assertStringNotContainsString($absent, $partie);
            }
            // L'activité, elle, y est : nombres seulement.
            self::assertStringContainsString('Nouveaux clients', $partie);
            self::assertStringContainsString('Commandes en retard', $partie);
        }
    }

    public function testEncaissementsSansLesNomsDesDebiteurs(): void
    {
        [$html, $texte] = self::rendre(self::contexte(['ROLE_CAISSE_COMMANDE', 'ROLE_STATS_GLOBAL', 'ROLE_PAIEMENT_VOIR'], PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42')));

        foreach ([$html, $texte] as $partie) {
            self::assertStringContainsStringIgnoringCase('Encaissements', $partie);
            self::assertStringContainsString('980 000 F', $partie);
            self::assertStringNotContainsString('Institut Kayes', $partie);
            self::assertStringNotContainsString('Hôtel Lumière', $partie);
            // Sans « Indicateurs commerciaux » : pas de CA facturé.
            self::assertStringNotContainsString('1 350 000 F', $partie);
            self::assertStringNotContainsString('Banque BDM', $partie);
        }
    }

    public function testLienSeulementSurLeReseauDeLAtelier(): void
    {
        $p = PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17');
        $html = self::twig()->render(CourrielRapport::MODELE_HTML, self::contexte(self::DIRECTION, $p, 'http://192.168.1.10/successImprim/public/rapports-mail/apercu/jour?periode=2026-10-17'));

        self::assertStringContainsString('href="http://192.168.1.10/successImprim/public/rapports-mail/apercu/jour?periode=2026-10-17"', $html);
        self::assertStringContainsString('Ce lien ne fonctionne que sur le réseau de l’atelier', $html);
        // Rapport du jour : l'avancement du mois, avec sa jauge.
        self::assertStringContainsString('CA d’octobre 2026 à ce jour', $html);
        self::assertStringContainsString('48 % de l’objectif', $html);

        $texte = self::twig()->render(CourrielRapport::MODELE_TEXTE, self::contexte(self::DIRECTION, $p, 'http://192.168.1.10/successImprim/public/rapports-mail/apercu/jour?periode=2026-10-17'));
        self::assertStringContainsString('Ouvrir dans l’ERP (réseau de l’atelier seulement) : http://192.168.1.10/', $texte);

        // Sans adresse réglée : pas de bouton, mais le pied dit où trouver le rapport, dans les deux parties.
        foreach (self::rendre(self::contexte(self::DIRECTION, $p)) as $sansLien) {
            self::assertStringNotContainsString('Ouvrir dans l’ERP', $sansLien);
            self::assertStringContainsString('sur le réseau de l’atelier seulement', $sansLien);
        }
    }

    public function testLisibleSurTelephone(): void
    {
        $html = self::twig()->render(CourrielRapport::MODELE_HTML, self::contexte(self::DIRECTION, PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17')));

        self::assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        self::assertStringContainsString('max-width: 600px', $html);
        self::assertStringContainsString('lang="fr"', $html);
        // Le texte d'aperçu des messageries ne donne aucun montant.
        preg_match('#<div style="display: none;[^>]*>(.*?)</div>#s', $html, $m);
        self::assertSame([], self::montants($m[1] ?? 'absent'));
        self::assertStringContainsString('12 commandes', $m[1]);
    }
}
