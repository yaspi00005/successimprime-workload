<?php

namespace App\Service\DossiersClients;

use App\Entity\Clients;
use App\Entity\FichierDossier;
use App\Entity\FichierPublie;
use App\Entity\User;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * « Envoyer au client » (maquette DS01) : une copie du fichier est rangée
 * sous var/uploads/publies, part au site en ligne avec la synchronisation
 * (CatalogueSynchro « fichier_publie », FichiersSynchro) et s'affiche dans
 * l'espace client, onglet Fichiers. La version en ligne ne voit que ces
 * copies, jamais le serveur de l'atelier.
 *
 * Les fichiers sources (AI, PSD, INDD…) ne partent que si la personne le
 * choisit exprès. Retirer la publication cache la copie au client.
 */
final class PublicationFichiers
{
    public const TAILLE_MAX = 300 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesDossiers $reglages,
        private readonly DossierClient $dossiers,
        private readonly JournalDossiers $journal,
        #[Autowire('%kernel.project_dir%/var/uploads/publies')] private readonly string $stockage,
    ) {
    }

    /** Catégorie proposée selon le sous-dossier et le nom. */
    public static function categorieProposee(FichierDossier $f): string
    {
        $n = mb_strtolower($f->getNom());

        return match (true) {
            $f->estSource() => 'source',
            'bat' === $f->getSousDossier() || str_contains($n, 'bat') => 'bat',
            str_contains($n, 'facture') => 'facture',
            'documents' === $f->getSousDossier() => 'document',
            default => 'visuel',
        };
    }

    public function publier(FichierDossier $f, string $categorie, bool $sourceChoisie, User $par): FichierPublie
    {
        if (!$this->reglages->peut($par, 'ajouter', $f->getSousDossier()) || !$this->reglages->peut($par, 'voir', $f->getSousDossier())) {
            throw new \DomainException('Vous ne pouvez pas envoyer au client un fichier de ce sous-dossier.');
        }
        if ($f->estSource() && !$sourceChoisie) {
            throw new \DomainException('C\'est un fichier source (AI, PSD…) : il reste chez vous, sauf si vous cochez « envoyer quand même le fichier source ».');
        }
        if (!\array_key_exists($categorie, FichierPublie::CATEGORIES)) {
            $categorie = self::categorieProposee($f);
        }
        $dossier = $this->dossiers->dossierClient($f->getClient()) ?? throw new \DomainException('Le dossier du client est introuvable.');
        $chemin = ClassementFichiers::dans($dossier, $f->getChemin()) ?? throw new \DomainException('Le fichier n\'est plus dans le dossier du client.');
        $taille = (int) filesize($chemin);
        if ($taille <= 0 || $taille > self::TAILLE_MAX) {
            throw new \DomainException(\sprintf('Fichier vide ou trop lourd pour l\'espace client (%d Mo au plus).', self::TAILLE_MAX / 1048576));
        }
        if (!is_dir($this->stockage) && !@mkdir($this->stockage, 0770, true) && !is_dir($this->stockage)) {
            throw new \DomainException('Le dossier des fichiers publiés n\'a pas pu être créé.');
        }
        $empreinte = (string) hash_file('sha256', $chemin);
        $deja = $this->em->getRepository(FichierPublie::class)->findOneBy(['client' => $f->getClient(), 'empreinte' => $empreinte, 'retireLe' => null]);
        if (null !== $deja) {
            return $deja;
        }
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $f->getExtension()));
        $nomStockage = bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');
        if (!@copy($chemin, $this->stockage.'/'.$nomStockage)) {
            throw new \DomainException('La copie pour le client n\'a pas pu être faite.');
        }
        @chmod($this->stockage.'/'.$nomStockage, 0640);
        $type = (string) ((new \finfo(\FILEINFO_MIME_TYPE))->file($this->stockage.'/'.$nomStockage) ?: 'application/octet-stream');
        $p = (new FichierPublie($f->getClient(), $f->getNom(), $type, $taille, $empreinte, $nomStockage, $categorie, new \DateTimeImmutable()))
            ->setCommande($f->getCommande())
            ->setFichierDossier($f)
            ->setPubliePar($par);
        $this->em->persist($p);
        $this->journal->noter('publication', $f->getClient(), $par, ['fichier' => $f->getChemin(), 'categorie' => $p->getCategorieLibelle(), 'source' => $f->estSource()]);
        $this->em->flush();

        return $p;
    }

    public function retirer(FichierPublie $p, User $par): void
    {
        if (null !== $p->getRetireLe()) {
            return;
        }
        $p->setRetireLe(new \DateTimeImmutable());
        $this->journal->noter('retrait_publication', $p->getClient(), $par, ['fichier' => $p->getNom()]);
        $this->em->flush();
    }

    /** @return list<FichierPublie> fichiers publiés et visibles du client, les plus récents d'abord */
    public function pourClient(Clients $client): array
    {
        return $this->em->getRepository(FichierPublie::class)->findBy(['client' => $client, 'retireLe' => null], ['publieLe' => 'DESC'], 200);
    }

    /** La copie sur ce serveur, ou null (pas encore arrivée sur le site). */
    public function chemin(FichierPublie $p): ?string
    {
        $nom = basename($p->getNomStockage());
        if (!preg_match('/^[a-f0-9]{32}(\.[a-z0-9]{1,10})?$/', $nom)) {
            return null;
        }
        $chemin = $this->stockage.'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    public function stockage(): string
    {
        return $this->stockage;
    }
}
