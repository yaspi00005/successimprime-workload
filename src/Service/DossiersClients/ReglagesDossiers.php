<?php

namespace App\Service\DossiersClients;

use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\Parametres\Parametres;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Réglages des dossiers clients (tuile « Dossiers clients » de
 * Paramètres, écran DS04), lus pour les autres services.
 *
 * Sur le site en ligne, tout se comporte comme si aucun dossier n'était
 * réglé : aucun écran ne lit le disque (la version en ligne ne voit pas le
 * serveur de l'atelier).
 */
final class ReglagesDossiers
{
    public const TUILE = 'produits_clients';

    private ?ModeleSousDossiers $modele = null;
    private ?DroitsDossiers $droits = null;
    private ?bool $pdftotext = null;

    public function __construct(
        private readonly Parametres $parametres,
        private readonly AccesEnLigne $acces,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    public function enLigne(): bool
    {
        return $this->acces->estEnLigne();
    }

    /** Dossier racine vu du serveur de l'ERP ('' : pas réglé, ou site en ligne). */
    public function racine(): string
    {
        return $this->enLigne() ? '' : self::propre((string) $this->parametres->valeur(self::TUILE, 'dossier_racine'));
    }

    /** Le même dossier vu des postes Windows (« \\SERVEUR\Clients »). */
    public function racinePostes(): string
    {
        return $this->enLigne() ? '' : (self::propre((string) $this->parametres->valeur(self::TUILE, 'racine_postes')) ?: $this->racine());
    }

    public function dossierAClasser(): string
    {
        return $this->enLigne() ? '' : self::propre((string) $this->parametres->valeur(self::TUILE, 'dossier_a_classer'));
    }

    public function valeur(string $cle): mixed
    {
        return $this->parametres->valeur(self::TUILE, $cle);
    }

    public function modele(): ModeleSousDossiers
    {
        $brut = $this->parametres->valeur(self::TUILE, 'modele');

        return $this->modele ??= new ModeleSousDossiers(\is_array($brut) ? $brut : null);
    }

    public function droits(): DroitsDossiers
    {
        $brut = $this->parametres->valeur(self::TUILE, 'droits');

        return $this->droits ??= new DroitsDossiers(\is_array($brut) ? $brut : null, $this->modele()->cles());
    }

    /** @return array{voir: true|list<string>, ajouter: true|list<string>, supprimer: true|list<string>} */
    public function droitsDe(?User $u): array
    {
        return null === $u ? ['voir' => [], 'ajouter' => [], 'supprimer' => []] : $this->droits()->pour($u->getRoles());
    }

    public function peut(?User $u, string $action, string $cle): bool
    {
        return null !== $u && $this->droits()->peut($u->getRoles(), $action, $cle);
    }

    public function lienProtocole(): LienProtocole
    {
        return new LienProtocole($this->clePostes());
    }

    public function clePostes(): string
    {
        return LienProtocole::clePostes($this->secret);
    }

    /** L'outil pdftotext est-il installé sur ce serveur ? */
    public function pdftotextDisponible(): bool
    {
        return $this->pdftotext ??= null !== (new ExecutableFinder())->find('pdftotext');
    }

    public function rechercheTextePdf(): bool
    {
        return (bool) $this->valeur('recherche_pdf') && $this->pdftotextDisponible();
    }

    /** Dossier des miniatures et de l'état de l'analyse. */
    public function dossierTravail(): string
    {
        return $this->projet.'/var/dossiers-clients';
    }

    /**
     * Enregistre des réglages de la tuile (journalisés comme les autres
     * Paramètres). Renvoie 'applique', 'propose' ou 'inchange'.
     *
     * @param array<string, mixed> $valeurs
     */
    public function enregistrer(array $valeurs, ?User $par): string
    {
        $resultat = $this->parametres->enregistrer(self::TUILE, $valeurs, $par);
        $this->modele = null;
        $this->droits = null;

        return $resultat;
    }

    private static function propre(string $chemin): string
    {
        $chemin = trim($chemin);
        // « \\SERVEUR\ » garde ses deux barres de tête ; « D:\ » garde la sienne.
        if (preg_match('#^[A-Za-z]:[\\\\/]?$#', $chemin)) {
            return rtrim($chemin, '\\/').'\\';
        }

        return rtrim($chemin, '/\\');
    }
}
