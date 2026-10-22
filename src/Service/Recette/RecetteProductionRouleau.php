<?php

namespace App\Service\Recette;

use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Security\Droits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Recette de la production au rouleau (docs/production-rouleau.md) :
 *
 * - le coût matière + encre de l'imposition seulement avec « Coûts et
 *   marges » (les autres pages passent déjà par la recherche des montants) ;
 * - les gestes de l'atelier (cocher, scanner, buses, Démarrer, impression
 *   en cours) suivent la règle de l'atelier : « Statuts de production »,
 *   puis chef d'atelier, opérateur affecté à la machine, ou pointé
 *   aujourd'hui si la machine n'a pas d'opérateur ;
 * - machines, dossiers et codes des fichiers : chef d'atelier (ou
 *   Direction) seulement ;
 * - sur le site en ligne, ces écrans n'existent pas (404).
 *
 * Les envois ne changent rien : un point inconnu, aucun code saisi,
 * « Reprendre » sur un rouleau qui n'est pas en pause ; la
 * modification d'une machine n'est essayée que là où elle doit être
 * refusée. La recette annule de toute façon ses écritures.
 */
final class RecetteProductionRouleau
{
    public const JETON = 'production-rouleau';

    private const REFUS = [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION];

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param callable(string): bool $accorde le compte a-t-il ce droit ?
     */
    public function verifierCompte(User $compte, callable $accorde, BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_atelier_production_imposition')) {
            return;
        }
        $tirage = $this->em->getConnection()->fetchAssociative('SELECT id, machine_id FROM tirage_rouleau WHERE id >= ? ORDER BY id LIMIT 1', [ParametresRoute::PREMIER_ID_DEMO]);
        $identifiant = $compte->getUserIdentifier();
        $jeton = 'recette-'.bin2hex(random_bytes(8));
        $session = $this->navigateur->session($compte, 'main', ['_csrf/'.self::JETON => $jeton]);
        $gerer = $accorde('ROLE_PRODUCTION_GERER');
        $chef = $gerer && $accorde(Droits::ATELIER_CHEF);

        if (false !== $tirage) {
            $id = (int) $tirage['id'];

            // Coût du rouleau (imposition) : avec « Coûts et marges » seulement.
            $chemin = $this->routeur->generate('app_atelier_production_imposition', ['id' => $id]);
            $reponse = $this->navigateur->get($chemin, $session);
            if (Reponse::OUVERT === $reponse->etat()) {
                $cout = str_contains($reponse->contenu, 'Coût matière + encre');
                $voulu = $accorde('ROLE_COUT_MARGE');
                $bilan->controler(\sprintf('%s : coût du rouleau sur l\'imposition %s', $identifiant, $voulu ? 'affiché (Coûts et marges)' : 'caché (sans Coûts et marges)'), $cout === $voulu, $chemin.' → '.($cout ? 'coût affiché' : 'pas de coût'));
                if ($cout && !$voulu) {
                    $bilan->constater(BilanRecette::FUITE, 'cout_rouleau', 'app_atelier_production_imposition', $chemin, $identifiant, 'coût matière + encre du rouleau sans le droit « Coûts et marges »');
                }
            }

            // Geste de l'atelier sur la machine du rouleau (point inconnu : rien n'est coché).
            $permis = $gerer && ($chef || ($this->operateur((int) $tirage['machine_id'], $compte) ?? $this->pointeAujourdhui($compte)));
            $chemin = $this->routeur->generate('app_atelier_production_cocher', ['id' => $id]);
            $etat = $this->navigateur->post($chemin, ['_token' => $jeton, 'cle' => 'recette', 'fait' => '1'], $session)->etat();
            $this->envoi($bilan, $identifiant, 'app_atelier_production_cocher', $chemin, $etat, $permis, 'cocher un point de la fiche de production', 'règle de l\'atelier : Statuts de production, puis chef d\'atelier, opérateur de la machine ou pointé');
        }

        // Impression en cours (PD04, PD07) : « Reprendre » sur un rouleau qui n'est pas en pause ne change rien.
        $calme = $this->em->getConnection()->fetchAssociative('SELECT id, machine_id FROM tirage_rouleau WHERE id >= ? AND statut <> ? ORDER BY id LIMIT 1', [ParametresRoute::PREMIER_ID_DEMO, TirageRouleau::STATUT_EN_PAUSE]);
        if (false !== $calme) {
            $permis = $gerer && ($chef || ($this->operateur((int) $calme['machine_id'], $compte) ?? $this->pointeAujourdhui($compte)));
            $chemin = $this->routeur->generate('app_atelier_production_en_cours_action', ['id' => (int) $calme['id'], 'action' => 'reprendre']);
            $etat = $this->navigateur->post($chemin, ['_token' => $jeton], $session)->etat();
            $this->envoi($bilan, $identifiant, 'app_atelier_production_en_cours_action', $chemin, $etat, $permis, 'un geste de l\'impression en cours', 'règle de l\'atelier : Statuts de production, puis chef d\'atelier, opérateur de la machine ou pointé');
        }

        // Codes des fichiers (aucun code envoyé : rien ne change).
        $chemin = $this->routeur->generate('app_atelier_production_nommage_enregistrer');
        $etat = $this->navigateur->post($chemin, ['_token' => $jeton], $session)->etat();
        $this->envoi($bilan, $identifiant, 'app_atelier_production_nommage_enregistrer', $chemin, $etat, $chef, 'enregistrer les codes des fichiers', 'Statuts de production et Chef d\'atelier');

        // Dossier et profils d'une machine : essayé seulement là où il doit être refusé.
        $machine = $this->em->getConnection()->fetchOne('SELECT machine_id FROM atelier_machine WHERE machine_id >= ? ORDER BY machine_id LIMIT 1', [ParametresRoute::PREMIER_ID_DEMO]);
        if (!$chef && false !== $machine) {
            $chemin = $this->routeur->generate('app_atelier_production_machine', ['id' => (int) $machine]);
            $etat = $this->navigateur->post($chemin, ['_token' => $jeton, 'codeDossier' => 'RECETTE'], $session)->etat();
            $this->envoi($bilan, $identifiant, 'app_atelier_production_machine', $chemin, $etat, false, 'changer le dossier et les profils d\'une machine', 'Statuts de production et Chef d\'atelier');
        }
    }

    /**
     * Site en ligne : la production au rouleau, les machines et les
     * dossiers n'y existent pas (AtelierHorsLigneSubscriber).
     */
    public function verifierEnLigne(BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_atelier_production_parcours')) {
            return;
        }
        $reponses = [];
        foreach (['app_atelier_production_parcours', 'app_atelier_production_rouleaux', 'app_atelier_production_machines', 'app_atelier_production_nommage'] as $route) {
            $chemin = $this->routeur->generate($route);
            $reponses[] = $chemin.' → '.$this->navigateur->get($chemin)->statut;
        }
        foreach (['app_atelier_production_fiche', 'app_atelier_production_imposition', 'app_atelier_production_en_cours', 'app_atelier_production_en_cours_etat'] as $route) {
            $chemin = $this->routeur->generate($route, ['id' => ParametresRoute::PREMIER_ID_DEMO]);
            $reponses[] = $chemin.' → '.$this->navigateur->get($chemin)->statut;
        }
        $chemin = $this->routeur->generate('app_atelier_operateur_en_cours');
        $reponses[] = $chemin.' → '.$this->navigateur->get($chemin)->statut;
        foreach ([
            $this->routeur->generate('app_atelier_production_nommage_enregistrer'),
            $this->routeur->generate('app_atelier_production_en_cours_action', ['id' => ParametresRoute::PREMIER_ID_DEMO, 'action' => 'terminer']),
            $this->routeur->generate('app_atelier_production_finition', ['id' => ParametresRoute::PREMIER_ID_DEMO]),
        ] as $chemin) {
            $reponses[] = 'POST '.$chemin.' → '.$this->navigateur->post($chemin, [])->statut;
        }
        $ok = [] === array_filter($reponses, static fn (string $r): bool => !str_ends_with($r, '→ 404'));
        $bilan->controler('Site en ligne : production au rouleau, impression en cours, machines et dossiers introuvables', $ok, implode(', ', $reponses));
    }

    private function envoi(BilanRecette $bilan, string $identifiant, string $route, string $chemin, string $etat, bool $permis, string $libelle, string $regle): void
    {
        $ok = $permis ? Reponse::REDIRIGE === $etat : \in_array($etat, self::REFUS, true);
        $bilan->controler(\sprintf('%s : %s %s', $identifiant, $libelle, $permis ? 'permis' : 'refusé'), $ok, 'POST '.$chemin.' → '.$etat);
        if (!$ok) {
            $bilan->constater(Reponse::ERREUR === $etat || $permis ? BilanRecette::ERREUR : BilanRecette::FUITE, 'envoi_refuse', $route, $chemin, $identifiant, \sprintf('%s %s (%s) : l\'envoi répond « %s »', $libelle, $permis ? 'devrait passer' : 'accepté sans le droit', $regle, $etat));
        }
    }

    /** Opérateur affecté (true), machine avec d'autres opérateurs (false), machine sans opérateur (null). */
    private function operateur(int $machine, User $compte): ?bool
    {
        $c = $this->em->getConnection();
        $atelier = $c->fetchOne('SELECT id FROM atelier_machine WHERE machine_id = ?', [$machine]);
        if (false === $atelier) {
            return null;
        }
        $operateurs = array_map('intval', $c->fetchFirstColumn('SELECT user_id FROM atelier_machine_operateur WHERE atelier_machine_id = ?', [$atelier]));

        return [] === $operateurs ? null : \in_array((int) $compte->getId(), $operateurs, true);
    }

    private function pointeAujourdhui(User $compte): bool
    {
        $dernier = $this->em->getConnection()->fetchAssociative('SELECT arrivee_le, depart_le FROM pointage WHERE utilisateur_id = ? ORDER BY arrivee_le DESC, id DESC LIMIT 1', [$compte->getId()]);

        return false !== $dernier && null === $dernier['depart_le'] && substr((string) $dernier['arrivee_le'], 0, 10) === (new \DateTimeImmutable())->format('Y-m-d');
    }
}
