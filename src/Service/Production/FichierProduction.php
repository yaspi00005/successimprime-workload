<?php

namespace App\Service\Production;

use App\Entity\AtelierMachine;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Service\Atelier\PosteMachiniste;
use App\Service\Parametres\Parametres;
use App\Service\Prepresse\DepotFichiers;
use App\Service\ProduitsClient\LiensFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le fichier de production d'un ordre (maquette PD01, docs/production-rouleau.md) :
 * son nom fabriqué par l'ERP (NomFichierProduction), retenu sur l'ordre, et
 * sa copie dans le dossier de la machine (<racine>/<CODE>/IN) quand la
 * tuile Réglages › Production en donne la racine.
 *
 * Le fichier du client n'est jamais déplacé ni renommé : on en copie un
 * exemplaire, jamais par-dessus un fichier existant. Rien n'est écrit sous
 * var/uploads (le dossier partagé relirait la copie comme un fichier reçu)
 * ni sous public/. ERP local seulement : en ligne, aucune copie.
 */
final class FichierProduction
{
    /** Dossier du traceur de découpe sous la racine (codeDossier de sa machine). */
    public const CODE_DECOUPE = 'DECOUPE';
    public const SOUS_DOSSIER = 'IN';

    public const COPIE = 'copie';
    public const NON_REGLE = 'non_regle';
    public const INTROUVABLE = 'introuvable';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly DepotFichiers $depot,
        private readonly LiensFichiers $liens,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /**
     * Calcule et retient sur l'ordre le nom du fichier de production (et
     * celui du fichier de découpe quand la ligne en a un). Appelé quand
     * l'ordre est créé ou que ses fichiers changent (envoi en production
     * du prépresse, Aiguillage::lancer, PosteMachiniste::fichierCorrige).
     * Sans disque ni base : statique. Ne flushe pas.
     */
    public static function preparer(OrdreProduction $o): void
    {
        $noms = self::noms($o);
        $o->nommerFichiers($noms['production'], $noms['decoupe']);
    }

    /**
     * @return array{production: ?string, decoupe: ?string}
     */
    public static function noms(OrdreProduction $o): array
    {
        $ligne = $o->getCommandeDetail();
        if (null === $ligne) {
            return ['production' => null, 'decoupe' => null];
        }
        $fichier = PosteMachiniste::fichier($o);
        $nomSource = $fichier?->getNomOriginal() ?? (string) ($ligne->getFichierLie()['nom'] ?? '');
        $production = NomFichierProduction::nom(
            (string) $ligne->getCommande()?->getNumero(),
            self::rangLigne($ligne),
            self::codeSupport($ligne),
            self::cm($ligne->getLargeur()),
            self::cm($ligne->getLongueur()),
            max(1, (int) ($o->getQuantite() ?? $ligne->getQuantite())),
            self::codesFinitions($ligne),
            NomFichierProduction::extension($nomSource),
        );
        $decoupe = PosteMachiniste::fichierDecoupe($o);
        $lienDecoupe = $ligne->getFichierLie()['decoupe'] ?? null;
        $nomDecoupe = null;
        if (null !== $decoupe || \is_array($lienDecoupe)) {
            $nomDecoupe = NomFichierProduction::nomDecoupe($production, NomFichierProduction::extension($decoupe?->getNomOriginal() ?? (string) ($lienDecoupe['nom'] ?? '')));
        }

        return ['production' => $production, 'decoupe' => $nomDecoupe];
    }

    /** Nom retenu sur l'ordre, sinon calculé. */
    public static function nomProduction(OrdreProduction $o): string
    {
        return $o->getNomFichierProduction() ?? (string) self::noms($o)['production'];
    }

    public static function nomDecoupe(OrdreProduction $o): ?string
    {
        return $o->getNomFichierDecoupe() ?? self::noms($o)['decoupe'];
    }

    /** L1, L2… : rang de la ligne dans sa commande (ids triés). */
    public static function rangLigne(CommandesDetails $ligne): int
    {
        $lignes = $ligne->getCommande()?->getCommandesDetails()->toArray() ?? [];
        $ids = array_values(array_filter(array_map(static fn (CommandesDetails $l): ?int => $l->getId(), $lignes)));
        if (null !== $ligne->getId()) {
            return NomFichierProduction::rang($ids, $ligne->getId());
        }
        // Ligne pas encore enregistrée : sa place dans la commande.
        $position = array_search($ligne, $lignes, true);

        return false === $position ? 1 : $position + 1;
    }

    /** Code du support : celui du support de la ligne, sinon de l'article, sinon du produit. */
    public static function codeSupport(CommandesDetails $ligne): ?string
    {
        if (null !== $ligne->getSupport()) {
            return $ligne->getSupport()->codePourFichier();
        }
        $nom = (string) ($ligne->getArticle()?->getDesignation() ?? '');
        if ('' === trim($nom)) {
            return null;
        }
        $code = NomFichierProduction::code($nom);

        return '' !== $code ? $code : null;
    }

    /** Libellé du support d'une ligne (pour les écrans). */
    public static function libelleSupport(CommandesDetails $ligne): string
    {
        return trim((string) ($ligne->getSupport()?->getNom() ?? $ligne->getArticle()?->getDesignation() ?? ''));
    }

    /** @return list<string> */
    public static function codesFinitions(CommandesDetails $ligne): array
    {
        $codes = [];
        foreach ($ligne->getFinitions() as $f) {
            $code = null !== $f->getFinition()
                ? $f->getFinition()->codePourFichier()
                : NomFichierProduction::code((string) $f->getNomFinition());
            if ('' !== $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private static function cm(?string $metres): ?float
    {
        $m = (float) $metres;

        return $m > 0 ? round($m * 100, 1) : null;
    }

    /**
     * Le fichier à imprimer (ou de découpe) sur le disque : celui transmis à
     * l'ordre, sinon le fichier lié de « Mes produits » quand il est
     * toujours identique.
     *
     * @return array{chemin: ?string, raison: ?string}
     */
    public function source(OrdreProduction $o, bool $decoupe = false): array
    {
        $fichier = $decoupe ? PosteMachiniste::fichierDecoupe($o) : PosteMachiniste::fichier($o);
        if ($fichier instanceof CommandeDetailFichier) {
            $chemin = $this->depot->chemin($fichier);

            return null !== $chemin ? ['chemin' => $chemin, 'raison' => null] : ['chemin' => null, 'raison' => 'Le fichier est introuvable sur le serveur.'];
        }
        $ligne = $o->getCommandeDetail();
        $lien = $ligne?->getFichierLie();
        if ($decoupe) {
            $lien = \is_array($lien['decoupe'] ?? null) ? $lien['decoupe'] : null;
        }
        if (null === $ligne?->getProduitClient() || !\is_array($lien)) {
            return ['chemin' => null, 'raison' => $decoupe ? 'Pas de fichier de découpe.' : 'Pas de fichier transmis à la production.'];
        }
        $verif = $this->liens->verifier($ligne->getProduitClient(), $lien, $this->enLigne);

        return match ($verif['etat']) {
            LiensFichiers::IDENTIQUE => ['chemin' => $verif['chemin'], 'raison' => null],
            LiensFichiers::MODIFIE => ['chemin' => null, 'raison' => 'Le fichier lié a changé dans le dossier du client depuis la commande : à revoir au prépresse.'],
            default => ['chemin' => null, 'raison' => 'Le fichier lié est introuvable dans le dossier du client.'],
        };
    }

    /** Racine réglée et présente sur ce serveur (jamais en ligne), sinon null. */
    public function racine(): ?string
    {
        if ($this->enLigne) {
            return null;
        }
        $racine = rtrim(trim((string) $this->parametres->valeur('production', 'racine_serveur')), '/\\');
        if ('' === $racine || !is_dir($racine)) {
            return null;
        }
        $reelle = realpath($racine);
        // Jamais dans l'application : public/ serait servi, var/uploads relu (dossier partagé).
        $projet = realpath($this->projet);
        if (false === $reelle || (false !== $projet && ($reelle === $projet || str_starts_with($reelle, $projet.\DIRECTORY_SEPARATOR)))) {
            return null;
        }

        return $reelle;
    }

    /** Pourquoi rien n'est copié dans le dossier de production ; null quand la racine est bonne. */
    public function alerteRacine(): ?string
    {
        if (null !== $this->racine()) {
            return null;
        }
        $saisie = rtrim(trim((string) $this->parametres->valeur('production', 'racine_serveur')), '/\\');
        if ($this->enLigne) {
            return 'Sur le site en ligne, rien n\'est copié dans les dossiers des machines : le fichier reste dans l\'ERP. Téléchargez-le sous son nom de production.';
        }
        if ('' === $saisie) {
            return 'Dossier de production non réglé (Réglages › Production) : le fichier reste dans l\'ERP. Téléchargez-le sous son nom de production.';
        }
        if (!is_dir($saisie)) {
            return \sprintf('Le dossier de production « %s » est introuvable sur ce poste : créez-le ou corrigez le chemin dans Réglages › Production.', $saisie);
        }

        return \sprintf('Le dossier « %s » est dans l\'application : choisissez un dossier en dehors.', $saisie);
    }

    /** Une racine est-elle saisie (même absente de ce serveur) ? */
    public function racineSaisie(): bool
    {
        return '' !== trim((string) $this->parametres->valeur('production', 'racine_serveur'));
    }

    /** \\ATELIER\Production\SOLVANT-320\IN, tel que l'équipe le voit. */
    public function dossierAffiche(?string $codeDossier): ?string
    {
        $code = AtelierMachine::nettoyerCodeDossier($codeDossier);
        if (null === $code) {
            return null;
        }
        $racine = rtrim(trim((string) ($this->parametres->valeur('production', 'racine_affichee') ?: '\\\\ATELIER\\Production')), '/\\');

        return $racine.'\\'.$code.'\\'.self::SOUS_DOSSIER;
    }

    public function codeDossier(?Machines $machine): ?string
    {
        if (null === $machine) {
            return null;
        }
        $atelier = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);

        return $atelier?->getCodeDossier();
    }

    /**
     * Copie un fichier dans <racine>/<code>/IN sous ce nom (un nom libre
     * s'il est déjà pris : « …-2.pdf »). Rend le chemin de la copie.
     *
     * @throws \DomainException message à montrer
     */
    public function copier(string $source, string $codeDossier, string $nom): string
    {
        $racine = $this->racine();
        if (null === $racine) {
            throw new \DomainException($this->racineSaisie() ? 'Le dossier de production réglé est introuvable sur ce serveur : le fichier reste dans l\'ERP.' : 'Dossier de la machine non réglé : le fichier reste dans l\'ERP.');
        }
        $code = AtelierMachine::nettoyerCodeDossier($codeDossier) ?? throw new \DomainException('La machine n\'a pas de dossier : à régler sur « Machines et dossiers ».');
        if (!is_file($source) || !is_readable($source)) {
            throw new \DomainException('Le fichier à copier est introuvable.');
        }
        $cible = $racine.\DIRECTORY_SEPARATOR.$code.\DIRECTORY_SEPARATOR.self::SOUS_DOSSIER;
        if (!is_dir($cible) && !@mkdir($cible, 0775, true) && !is_dir($cible)) {
            throw new \DomainException(\sprintf('Le dossier %s\\%s n\'a pas pu être créé.', $code, self::SOUS_DOSSIER));
        }
        $nom = self::nomPropre($nom);
        $base = pathinfo($nom, \PATHINFO_FILENAME);
        $extension = pathinfo($nom, \PATHINFO_EXTENSION);
        for ($i = 1; $i <= 200; ++$i) {
            $essai = 1 === $i ? $nom : \sprintf('%s-%d%s', $base, $i, '' !== $extension ? '.'.$extension : '');
            $chemin = $cible.\DIRECTORY_SEPARATOR.$essai;
            // « x » : création seulement, jamais d'écrasement, même si un autre poste écrit au même moment.
            $sortie = @fopen($chemin, 'x');
            if (false === $sortie) {
                continue;
            }
            $entree = @fopen($source, 'r');
            $ok = false !== $entree && false !== stream_copy_to_stream($entree, $sortie);
            if (false !== $entree) {
                fclose($entree);
            }
            fclose($sortie);
            clearstatcache(true, $chemin);
            if (!$ok || filesize($chemin) !== filesize($source)) {
                @unlink($chemin);
                throw new \DomainException('La copie dans le dossier de la machine a échoué.');
            }

            return $chemin;
        }

        throw new \DomainException('Trop de fichiers du même nom dans le dossier de la machine.');
    }

    /**
     * Copie le fichier de l'ordre dans le dossier de sa machine, et son
     * fichier de découpe dans celui du traceur. Retient qui et quand.
     * Ne flushe pas.
     *
     * @return array{etat: string, message: string, chemin: ?string, decoupe: ?string}
     */
    public function copierOrdre(OrdreProduction $o, ?User $par, \DateTimeImmutable $le, ?string $codeDossier = null): array
    {
        $code = $codeDossier ?? $this->codeDossier($o->getMachine());
        if (null === $this->racine()) {
            return ['etat' => self::NON_REGLE, 'message' => 'Dossier de la machine non réglé : le fichier reste dans l\'ERP.', 'chemin' => null, 'decoupe' => null];
        }
        if (null === AtelierMachine::nettoyerCodeDossier($code)) {
            return ['etat' => self::NON_REGLE, 'message' => 'La machine de l\'ordre n\'a pas de dossier : à régler sur « Machines et dossiers ».', 'chemin' => null, 'decoupe' => null];
        }
        $source = $this->source($o);
        if (null === $source['chemin']) {
            return ['etat' => self::INTROUVABLE, 'message' => (string) $source['raison'], 'chemin' => null, 'decoupe' => null];
        }
        if (null === $o->getNomFichierProduction()) {
            self::preparer($o);
        }
        $chemin = $this->copier($source['chemin'], (string) $code, self::nomProduction($o));
        $o->noterCopieMachine($chemin, $par, $le);

        return ['etat' => self::COPIE, 'message' => 'Fichier copié dans le dossier de la machine.', 'chemin' => $chemin, 'decoupe' => $this->copierDecoupe($o)];
    }

    /**
     * Fichier de découpe de l'ordre copié dans le dossier du traceur
     * (DECOUPE\IN), sauf « Découpe sans fichier séparé ». Rend le chemin de
     * la copie, ou null (pas de fichier, pas de dossier).
     */
    public function copierDecoupe(OrdreProduction $o): ?string
    {
        $ligne = $o->getCommandeDetail();
        if (null === $ligne || $ligne->isDecoupeSansFichier() || null === $this->racine()) {
            return null;
        }
        $source = $this->source($o, true);
        $nom = self::nomDecoupe($o);
        if (null === $source['chemin'] || null === $nom) {
            return null;
        }

        return $this->copier($source['chemin'], self::CODE_DECOUPE, $nom);
    }

    /** Nom sans chemin ni caractère interdit sous Windows. */
    private static function nomPropre(string $nom): string
    {
        $nom = basename(str_replace('\\', '/', $nom));
        $nom = trim((string) preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '_', $nom), ' .');

        return '' !== $nom ? $nom : 'fichier.pdf';
    }
}
