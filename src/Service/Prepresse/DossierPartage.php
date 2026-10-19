<?php

namespace App\Service\Prepresse;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le dossier partagé des fichiers lourds (var/uploads/dossier_partage,
 * docs/fichiers-lourds.md) : une zone de dépôt. Un fichier qu'on y pose
 * en sort quand il est rattaché à une commande (déplacé, pas copié) ; un
 * fichier non reconnu reste où il est.
 *
 * - « 123_nom.pdf » : la ligne 123 ;
 * - « CMD-000378_5,5x7.pdf » : la commande, puis le format (s'il n'y a
 *   qu'un format sans fichier, c'est lui).
 *
 * Le dossier se lit à plat, sans sous-dossier ni lien : aucun chemin ne
 * peut en sortir. Un fichier encore en cours de copie (taille qui bouge,
 * modifié depuis moins de 30 s, extension .part, .crdownload…) attend le
 * passage suivant. Relancer l'import ne refait rien : un fichier rattaché
 * n'est plus dans le dossier.
 */
final class DossierPartage
{
    public const DOSSIER = 'var/uploads/dossier_partage';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepotFichiers $depot,
        private readonly NotificationService $notifications,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        private readonly ?string $dossierImpose = null,
    ) {
    }

    public function dossier(): string
    {
        $dossier = $this->dossierImpose ?? $this->projet.'/'.self::DOSSIER;
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }

        return $dossier;
    }

    /**
     * Les fichiers du dossier, les plus anciens d'abord, sans les cachés ni
     * les copies en cours.
     *
     * @return list<array{nom: string, taille: int, modifieLe: \DateTimeImmutable, pret: bool, lecture: array{type: ?string, ligne: ?int, numero: ?string, reste: string}}>
     */
    public function fichiers(?int $maintenant = null): array
    {
        $maintenant ??= time();
        $base = realpath($this->dossier());
        if (false === $base) {
            return [];
        }
        $liste = [];
        foreach (@scandir($base) ?: [] as $nom) {
            if (!NomFichierPartage::nomSur($nom) || NomFichierPartage::estIgnore($nom)) {
                continue;
            }
            $chemin = $base.\DIRECTORY_SEPARATOR.$nom;
            // Pas de lien symbolique : il pourrait pointer hors du dossier.
            if (is_link($chemin) || !is_file($chemin)) {
                continue;
            }
            clearstatcache(true, $chemin);
            $taille = (int) @filesize($chemin);
            $modifie = (int) @filemtime($chemin);
            $liste[] = [
                'nom' => $nom,
                'taille' => $taille,
                'modifieLe' => (new \DateTimeImmutable())->setTimestamp($modifie),
                'pret' => !NomFichierPartage::enCoursDeCopie($taille, $taille, $modifie, $maintenant),
                'lecture' => NomFichierPartage::lire($nom),
            ];
        }
        usort($liste, static fn (array $a, array $b): int => $a['modifieLe'] <=> $b['modifieLe']);

        return $liste;
    }

    /** Le chemin d'un fichier du dossier, ou null (nom dangereux, absent, lien). */
    public function chemin(string $nom): ?string
    {
        if (!NomFichierPartage::nomSur($nom) || NomFichierPartage::estIgnore($nom)) {
            return null;
        }
        $base = realpath($this->dossier());
        $chemin = false !== $base ? $base.\DIRECTORY_SEPARATOR.$nom : null;
        if (null === $chemin || is_link($chemin)) {
            return null;
        }
        $reel = realpath($chemin);

        return false !== $reel && \dirname($reel) === $base && is_file($reel) ? $reel : null;
    }

    /**
     * La ligne que désigne le nom du fichier, ou null : la ligne « 123_ »,
     * ou la commande « CMD-… » puis le format écrit dans le nom (sinon son
     * seul format encore sans fichier).
     *
     * @param array{type: ?string, ligne: ?int, numero: ?string, reste: string} $lecture
     */
    public function ligneDe(array $lecture): ?CommandesDetails
    {
        if ('ligne' === $lecture['type']) {
            $ligne = $this->em->find(CommandesDetails::class, (int) $lecture['ligne']);

            // Seulement une ligne encore en prépresse d'une commande ouverte.
            return $ligne instanceof CommandesDetails && null !== $ligne->getCommande() && self::ouverte($ligne->getCommande())
                && \in_array($ligne, DepotFichiers::lignes($ligne->getCommande()), true) ? $ligne : null;
        }
        if ('commande' !== $lecture['type']) {
            return null;
        }
        $commande = $this->commandeParNumero((string) $lecture['numero']);
        if (null === $commande) {
            return null;
        }
        $lignes = DepotFichiers::lignes($commande);
        $formats = DepotFichiers::formats($commande);
        $resultat = AssociationFormats::associer($formats, [['cle' => 'f', 'nom' => $lecture['reste']]])['fichiers']['f'];
        if (AssociationFormats::NOM === $resultat['methode']) {
            foreach ($lignes as $l) {
                if ($l->getId() === $resultat['format']) {
                    return $l;
                }
            }
        }
        $sansFichier = array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => !DepotFichiers::aFichier($l)));
        if (1 === \count($lignes)) {
            return $lignes[0];
        }

        return 1 === \count($sansFichier) ? $sansFichier[0] : null;
    }

    /** CMD-000378 trouve CMD-000378-10-2026. */
    public function commandeParNumero(string $numero): ?Commandes
    {
        $numero = strtoupper(trim($numero));
        if ('' === $numero) {
            return null;
        }
        $commande = $this->em->getRepository(Commandes::class)->findOneBy(['numero' => $numero]);
        if (null === $commande) {
            $trouvees = $this->em->createQuery('SELECT c FROM '.Commandes::class.' c WHERE c.numero LIKE :n ORDER BY c.id DESC')
                ->setParameter('n', addcslashes($numero, '%_').'-%')->setMaxResults(2)->getResult();
            // Deux commandes possibles : on ne choisit pas au hasard.
            $commande = 1 === \count($trouvees) ? $trouvees[0] : null;
        }

        return null !== $commande && self::ouverte($commande) ? $commande : null;
    }

    /**
     * Les fichiers du dossier qui semblent être pour cette commande (onglet
     * « Dossier partagé » de PQ02) : son numéro, une de ses lignes, le nom
     * du client, ou un de ses formats écrit dans le nom.
     *
     * @return list<array{nom: string, taille: int, modifieLe: \DateTimeImmutable, pret: bool, raison: string, ligne: ?int}>
     */
    public function pourCommande(Commandes $commande): array
    {
        $numero = strtoupper((string) $commande->getNumero());
        $court = (string) preg_replace('/-\d{2}-\d{4}$/', '', $numero);
        $lignes = DepotFichiers::lignes($commande);
        $ids = array_map(static fn (CommandesDetails $l): int => (int) $l->getId(), $lignes);
        $client = $commande->getClients();
        $mots = array_filter(array_map(NomFichierPartage::simplifier(...), [(string) $client?->getNom(), (string) $client?->getRaisonSociale()]), static fn (string $m): bool => mb_strlen($m) >= 3);
        $formats = DepotFichiers::formats($commande);
        $liste = [];
        foreach ($this->fichiers() as $f) {
            $l = $f['lecture'];
            $raison = null;
            $ligne = null;
            if ('commande' === $l['type'] && \in_array($l['numero'], [$numero, $court], true)) {
                $raison = 'numéro de commande';
            } elseif ('ligne' === $l['type'] && \in_array($l['ligne'], $ids, true)) {
                $raison = 'référence du travail';
                $ligne = $l['ligne'];
            } elseif ('commande' !== $l['type']) {
                $simple = ' '.NomFichierPartage::simplifier($f['nom']).' ';
                foreach ($mots as $mot) {
                    if (str_contains($simple, ' '.$mot.' ') || str_contains($simple, ' '.$mot)) {
                        $raison = 'nom du client';
                        break;
                    }
                }
                if (null === $raison && [] !== $formats && AssociationFormats::NOM === AssociationFormats::associer($formats, [['cle' => 'f', 'nom' => $f['nom']]])['fichiers']['f']['methode']) {
                    $raison = 'format';
                }
            }
            if (null !== $raison) {
                $liste[] = ['nom' => $f['nom'], 'taille' => $f['taille'], 'modifieLe' => $f['modifieLe'], 'pret' => $f['pret'], 'raison' => $raison, 'ligne' => $ligne];
            }
        }

        return $liste;
    }

    /**
     * Les fichiers que le nom ne permet pas de rattacher : listés dans
     * « Fichiers à classer », laissés en place.
     *
     * @return list<array{nom: string, taille: int, modifieLe: \DateTimeImmutable, pret: bool, raison: string}>
     */
    public function nonReconnus(): array
    {
        $liste = [];
        foreach ($this->fichiers() as $f) {
            $raison = match ($f['lecture']['type']) {
                'ligne' => null === $this->ligneDe($f['lecture']) ? 'aucun travail en prépresse #'.$f['lecture']['ligne'] : null,
                'commande' => null === $this->commandeParNumero((string) $f['lecture']['numero']) ? 'commande '.$f['lecture']['numero'].' introuvable ou terminée' : (null === $this->ligneDe($f['lecture']) ? 'format à choisir dans la commande '.$f['lecture']['numero'] : null),
                default => 'ni numéro de commande ni référence de travail',
            };
            if (null !== $raison) {
                $liste[] = ['nom' => $f['nom'], 'taille' => $f['taille'], 'modifieLe' => $f['modifieLe'], 'pret' => $f['pret'], 'raison' => $raison];
            }
        }

        return $liste;
    }

    /**
     * Import automatique (app:dossier-partage:importer, chaque minute).
     *
     * @return array{importes: list<string>, ignores: list<string>, enCours: int}
     */
    public function importer(\DateTimeImmutable $maintenant, ?User $par = null): array
    {
        $bilan = ['importes' => [], 'ignores' => [], 'enCours' => 0];
        $commandes = [];
        foreach ($this->fichiers($maintenant->getTimestamp()) as $f) {
            if (!$f['pret']) {
                ++$bilan['enCours'];
                continue;
            }
            $chemin = $this->chemin($f['nom']);
            if (null === $chemin) {
                continue;
            }
            // Relu juste avant : la taille ne doit plus bouger.
            clearstatcache(true, $chemin);
            if (NomFichierPartage::enCoursDeCopie($f['taille'], (int) @filesize($chemin), (int) @filemtime($chemin), $maintenant->getTimestamp())) {
                ++$bilan['enCours'];
                continue;
            }
            $ligne = $this->ligneDe($f['lecture']);
            if (null === $ligne) {
                $bilan['ignores'][] = $f['nom'];
                continue;
            }
            try {
                $this->depot->rattacherPartage($chemin, $ligne, $par, $maintenant, 'import automatique');
                $this->em->flush();
                $bilan['importes'][] = $f['nom'].' → '.$ligne->getCommande()?->getNumero().' · '.DepotFichiers::libelleFormat($ligne);
                $commandes[(string) $ligne->getCommande()?->getNumero()] = true;
            } catch (\DomainException $e) {
                $bilan['ignores'][] = $f['nom'].' ('.$e->getMessage().')';
            }
        }
        if ([] !== $commandes) {
            $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('Fichier arrivé par le dossier partagé : %s, à contrôler.', implode(', ', array_keys($commandes))), 'app_controle_pre_presse_index', ['onglet' => EtatPrepresse::A_CONTROLER]);
            $this->em->flush();
        }

        return $bilan;
    }

    /** Commande encore à faire : ni supprimée, ni brouillon, avec une ligne en prépresse. */
    public static function ouverte(Commandes $commande): bool
    {
        return !$commande->isDeleted() && $commande->isStatut() && [] !== DepotFichiers::lignes($commande);
    }
}
