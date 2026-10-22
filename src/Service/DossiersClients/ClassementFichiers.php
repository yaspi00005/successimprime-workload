<?php

namespace App\Service\DossiersClients;

use App\Entity\ActionCaisse;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\FichierAClasser;
use App\Entity\FichierDossier;
use App\Entity\User;
use App\Service\Pointage\Pointages;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classer un fichier déposé en vrac (maquette DS03) et déposer un fichier
 * depuis le navigateur (bouton « Déposer », DS01).
 *
 * Classer COPIE le fichier sous son nom propre dans le bon sous-dossier
 * du client ; l'original reste dans « À classer » (la liste le marque
 * classé). Un fichier déjà là n'est jamais écrasé : le nouveau prend un
 * « -2 ». Le dossier d'un nouveau client est créé avec les sous-dossiers
 * du modèle.
 */
final class ClassementFichiers
{
    /** Seuil de confiance pour « Classer les suggestions » en une fois. */
    public const CONFIANCE_MASSE = 70;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDossiers $reglages,
        private readonly DossierClient $dossiers,
        private readonly AnalyseDossiers $analyse,
        private readonly JournalDossiers $journal,
    ) {
    }

    /** Chemin complet d'un fichier « à classer », ou null s'il a disparu ou sort du dossier. */
    public function source(FichierAClasser $f): ?string
    {
        $racine = $this->reglages->dossierAClasser();

        return '' === $racine ? null : self::dans($racine, $f->getChemin());
    }

    public function classer(FichierAClasser $f, Clients $client, ?Commandes $commande, string $sousDossier, string $nom, User $par): FichierDossier
    {
        if (!$f->estAClasser()) {
            throw new \DomainException('Ce fichier est déjà classé ou retiré de la liste.');
        }
        if (null !== $commande && $commande->getClients()?->getId() !== $client->getId()) {
            throw new \DomainException('Cette commande n\'est pas celle de ce client.');
        }
        $source = $this->source($f) ?? throw new \DomainException('Le fichier n\'est plus dans le dossier « À classer ».');
        $nom = self::nomPropre($nom, $f->getExtension());
        $copie = $this->ranger($client, $sousDossier, $source, $nom, $par);
        $maintenant = new \DateTimeImmutable();
        $f->classer($copie->getChemin(), $par, $maintenant);
        $copie->lierCommande($commande, $par, $maintenant);
        // La ligne « Fichier à classer » de Mes actions n'a plus d'objet.
        foreach ($this->em->getRepository(ActionCaisse::class)->findBy(['cause' => 'classer:'.$f->getId(), 'etat' => ActionCaisse::A_FAIRE]) as $a) {
            $a->sansObjet($maintenant);
        }
        $this->journal->noter('classement', $client, $par, ['fichier' => $copie->getChemin(), 'depuis' => $f->getChemin(), 'commande' => $commande?->getNumero()]);
        $this->em->flush();

        return $copie;
    }

    /**
     * Classe d'un coup les propositions sûres que la personne peut ranger.
     *
     * @return array{classes: int, erreurs: list<string>}
     */
    public function classerSuggestions(User $par): array
    {
        $bilan = ['classes' => 0, 'erreurs' => []];
        foreach ($this->suggestionsSures($par) as $f) {
            try {
                $this->classer($f, $f->getClient(), $f->getCommande(), (string) $f->getSousDossier(), (string) $f->getNomPropose(), $par);
                ++$bilan['classes'];
            } catch (\DomainException $e) {
                $bilan['erreurs'][] = $f->getNom().' : '.$e->getMessage();
            }
        }

        return $bilan;
    }

    /** @return list<FichierAClasser> */
    public function suggestionsSures(User $par): array
    {
        return array_values(array_filter(
            $this->em->getRepository(FichierAClasser::class)->findBy(['etat' => FichierAClasser::A_CLASSER], ['apparuLe' => 'ASC']),
            fn (FichierAClasser $f): bool => null !== $f->getClient() && null !== $f->getSousDossier() && null !== $f->getNomPropose()
                && $f->getConfiance() >= self::CONFIANCE_MASSE && $this->reglages->peut($par, 'ajouter', (string) $f->getSousDossier()),
        ));
    }

    /**
     * « Déposer » (DS01) : un fichier envoyé depuis le navigateur, écrit
     * dans le sous-dossier choisi du client.
     */
    public function deposer(Clients $client, string $sousDossier, string $fichierTemporaire, string $nomOrigine, User $par): FichierDossier
    {
        $nom = self::nomPropre(pathinfo($nomOrigine, \PATHINFO_FILENAME), pathinfo($nomOrigine, \PATHINFO_EXTENSION));
        $copie = $this->ranger($client, $sousDossier, $fichierTemporaire, $nom, $par, true);
        $this->journal->noter('ajout', $client, $par, ['fichier' => $copie->getChemin()]);
        $this->em->flush();

        return $copie;
    }

    /**
     * Range une copie (ou le fichier envoyé) dans le sous-dossier, sans
     * rien écraser, puis la fait lire par l'analyse.
     */
    private function ranger(Clients $client, string $sousDossier, string $source, string $nom, User $par, bool $envoye = false): FichierDossier
    {
        if (!$this->reglages->peut($par, 'ajouter', $sousDossier) || ModeleSousDossiers::AUTRES === $sousDossier) {
            throw new \DomainException('Vous ne pouvez pas ajouter de fichier dans ce sous-dossier.');
        }
        $entree = $this->reglages->modele()->entree($sousDossier) ?? throw new \DomainException('Sous-dossier inconnu.');
        $dossier = $this->dossiers->dossierClient($client);
        if (null === $dossier) {
            // Nouveau client : son dossier est créé, avec les sous-dossiers du modèle.
            $this->analyse->creerDossier($client, $par);
            $dossier = $this->dossiers->dossierClient($client) ?? throw new \DomainException('Le dossier du client est introuvable.');
        }
        $sous = null;
        foreach (@scandir($dossier) ?: [] as $n) {
            if ('.' !== $n[0] && is_dir($dossier.\DIRECTORY_SEPARATOR.$n) && $this->reglages->modele()->reconnaitre($n) === $sousDossier) {
                $sous = $n;
                break;
            }
        }
        if (null === $sous) {
            $sous = $entree['dossier'];
            if (!is_dir($dossier.\DIRECTORY_SEPARATOR.$sous) && !@mkdir($dossier.\DIRECTORY_SEPARATOR.$sous, 0775)) {
                throw new \DomainException('Le sous-dossier n\'a pas pu être créé (droits d\'écriture).');
            }
        }
        $cible = $dossier.\DIRECTORY_SEPARATOR.$sous;
        $nom = NommageFichier::nomLibre($nom, static fn (string $n): bool => file_exists($cible.\DIRECTORY_SEPARATOR.$n));
        $destination = $cible.\DIRECTORY_SEPARATOR.$nom;
        $ok = $envoye ? @move_uploaded_file($source, $destination) || @copy($source, $destination) : @copy($source, $destination);
        if (!$ok || !is_file($destination)) {
            throw new \DomainException('Le fichier n\'a pas pu être écrit dans le dossier du client (droits d\'écriture).');
        }
        if (!$envoye) {
            @touch($destination, (int) filemtime($source));
        }
        $relatif = $sous.'/'.$nom;
        $this->analyse->analyserClient($client, false, new \DateTimeImmutable());
        $copie = $this->em->getRepository(FichierDossier::class)->findOneBy(['client' => $client, 'chemin' => $relatif])
            ?? throw new \DomainException('Le fichier a été écrit mais l\'analyse ne le retrouve pas.');
        $copie->setAjoutePar(Pointages::nom($par));

        return $copie;
    }

    /** Un nom de fichier sans caractère interdit par Windows, avec l'extension d'origine. */
    public static function nomPropre(string $nom, string $extension): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $extension));
        $base = (string) preg_replace('/\.'.preg_quote($extension, '/').'$/i', '', trim($nom));
        $base = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '-', $base), " .-");
        $base = mb_substr('' !== $base ? $base : 'fichier', 0, 150);

        return $base.('' !== $extension ? '.'.$extension : '');
    }

    /** Chemin complet de « relatif » dans « racine », ou null s'il n'existe pas ou en sort. */
    public static function dans(string $racine, string $relatif): ?string
    {
        if (str_contains($relatif, "\0") || str_contains($relatif, '..')) {
            return null;
        }
        $base = realpath($racine);
        $reel = realpath($racine.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $relatif));
        if (false === $base || false === $reel || !is_file($reel) || !str_starts_with($reel, $base.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $reel;
    }
}
