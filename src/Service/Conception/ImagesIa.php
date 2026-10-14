<?php

namespace App\Service\Conception;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Service\Conception\Generateur\GenerateurDemo;
use App\Service\Conception\Generateur\GenerateurImages;
use App\Service\Conception\Generateur\ImageGeneree;
use App\Service\EspaceClient\FichiersClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Génération d'images par l'IA pour une demande de conception (maquette
 * DC04). Chaque image :
 *  - se réserve dans le plafond du jour (tout le site) et la limite par
 *    client et par heure ;
 *  - débite le crédit IA du client avant l'appel (jamais au-delà du solde) ;
 *  - est rangée hors de public/ et servie à son seul client ;
 *  - est remboursée si le fournisseur échoue.
 */
final class ImagesIa
{
    /** Une génération plus ancienne est considérée comme perdue. */
    private const EN_COURS_MINUTES = 3;

    /** Côté le plus long des images montrées au client (pages légères en 3G). */
    public const TAILLES = ['v' => 360, 'g' => 900];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesConception $reglages,
        private readonly CreditsIa $credits,
        private readonly GenerateurImages $generateur,
        private readonly FichiersClient $fichiers,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** @return list<ImageIa> images réussies de la demande, dans l'ordre */
    public function images(DemandeConception $demande): array
    {
        return array_values($this->em->getRepository(ImageIa::class)->findBy(['demande' => $demande, 'statut' => ImageIa::PRETE], ['numero' => 'ASC']));
    }

    /** Une image du client, ou null si elle est à un autre. */
    public function trouver(Clients $client, int $id): ?ImageIa
    {
        $image = $this->em->find(ImageIa::class, $id);

        return $image instanceof ImageIa && $image->getClient()->getId() === $client->getId() && $image->estPrete() ? $image : null;
    }

    /**
     * Génère une image pour la demande, payée par le crédit IA.
     *
     * @throws \DomainException message à montrer au client
     */
    public function generer(DemandeConception $demande, CompteClient $compte, ?string $consigne, \DateTimeImmutable $maintenant): ImageIa
    {
        if (!$this->reglages->iaDisponible()) {
            throw new \DomainException('La génération par IA arrive bientôt.');
        }
        if (!$demande->estOuverte() || DemandeConception::A_PAYER === $demande->getStatut()) {
            throw new \DomainException('Cette demande est déjà partie à l\'atelier.');
        }
        DemandesConception::verifierComplete($demande);
        $client = $demande->getClient() ?? throw new \DomainException('Demande sans client.');
        $this->credits->rapprocher($client, $maintenant);
        $this->verifierRythme($client, $maintenant);

        $numero = $this->numeroSuivant($demande);
        $prompt = PromptConception::construire($demande, $consigne, $demande->getSupport()?->getCode(), $demande->getElements()->count());
        $prix = $this->reglages->prixImage();
        $jour = $maintenant->setTime(0, 0);
        if (!$this->credits->reserverJour($jour, $this->reglages->plafondJour())) {
            throw new \DomainException('Beaucoup d\'images ont été demandées aujourd\'hui : la génération reprend demain. Un graphiste reste disponible.');
        }
        try {
            $debit = $this->credits->debiter($client, $prix, \sprintf('Image %d · %s', $numero, $demande->getNumero()), $compte->getNomAffiche(), $maintenant);
        } catch (\DomainException $e) {
            $this->credits->libererJour($jour);
            throw $e;
        }

        $image = new ImageIa($demande, $numero, PromptConception::verifierConsigne($consigne), $prompt, $this->generateur->nom(), $this->generateur->modele(), $prix, $maintenant);
        $this->em->persist($image);
        $demande->setChoix(DemandeConception::IA)->setStatut(DemandeConception::EN_IA)->setEtape(4)->compterImage()->toucher($maintenant);
        $this->em->flush();

        try {
            @set_time_limit(180);
            $genere = $this->generateur->generer($prompt, PromptConception::orientation($demande->getLargeurCm(), $demande->getHauteurCm()), self::textes($demande, $numero));
            $nom = $this->ranger($client, $genere);
            $image->reussir($nom, $genere->largeur, $genere->hauteur, new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Image IA non générée pour {demande} : {erreur}', ['demande' => $demande->getNumero(), 'erreur' => $e->getMessage()]);
            $image->echouer($e->getMessage(), new \DateTimeImmutable());
            $this->em->flush();
            $this->credits->rembourser($debit, new \DateTimeImmutable());
            $this->credits->libererJour($jour);

            throw new \DomainException('La génération n\'a pas abouti : votre crédit est remboursé. Réessayez dans un instant.');
        }

        return $image;
    }

    /**
     * Fichier de l'image (hors de public/). Une image de démonstration
     * absente (données de démo) est redessinée.
     */
    public function chemin(ImageIa $image): ?string
    {
        $nom = basename((string) $image->getFichier());
        if ('' === $nom || '.' === $nom) {
            return null;
        }
        $chemin = $this->dossier($image->getClient()).'/'.$nom;
        if (!is_file($chemin) && 'demo' === $image->getFournisseur()) {
            $demo = new GenerateurDemo($this->projet.'/vendor/dompdf/dompdf/lib/fonts');
            $genere = $demo->generer($image->getPrompt(), PromptConception::orientation($image->getDemande()->getLargeurCm(), $image->getDemande()->getHauteurCm()), self::textes($image->getDemande(), $image->getNumero()));
            file_put_contents($chemin, $genere->contenu, \LOCK_EX);
        }

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Copie réduite pour l'écran ('v' vignette, 'g' grande), gardée à côté
     * de l'image.
     *
     * @return array{chemin: string, type: string}|null
     */
    public function apercu(ImageIa $image, string $taille): ?array
    {
        $source = $this->chemin($image);
        $cote = self::TAILLES[$taille] ?? null;
        if (null === $source || null === $cote) {
            return null;
        }
        $jpeg = \function_exists('imagejpeg') && (imagetypes() & \IMG_JPG);
        $cible = \sprintf('%s/%s-%s.%s', \dirname($source), pathinfo($source, \PATHINFO_FILENAME), $taille, $jpeg ? 'jpg' : 'png');
        if (is_file($cible)) {
            return ['chemin' => $cible, 'type' => $jpeg ? 'image/jpeg' : 'image/png'];
        }
        $gd = @imagecreatefromstring((string) file_get_contents($source));
        if (false === $gd) {
            return null;
        }
        $l = imagesx($gd);
        $h = imagesy($gd);
        $ratio = min(1, $cote / max($l, $h));
        $petite = imagescale($gd, max(1, (int) round($l * $ratio)), max(1, (int) round($h * $ratio)), \IMG_BICUBIC);
        if (false === $petite) {
            return null;
        }
        if ($jpeg) {
            imagejpeg($petite, $cible, 80);
        } else {
            imagepng($petite, $cible, 9);
        }

        return ['chemin' => $cible, 'type' => $jpeg ? 'image/jpeg' : 'image/png'];
    }

    /**
     * L'image choisie devient un fichier du client (Mes fichiers), qui
     * part au local avec la commande d'impression.
     */
    public function enFichierClient(ImageIa $image, DemandeConception $demande): CommandeDetailFichier
    {
        $source = $this->chemin($image) ?? throw new \DomainException('Image introuvable : générez-la de nouveau.');
        $extension = strtolower(pathinfo($source, \PATHINFO_EXTENSION)) ?: 'png';
        $jeton = bin2hex(random_bytes(32));
        $dossier = $this->projet.'/var/uploads/commandes';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des fichiers impossible à créer');
        }
        $cible = $dossier.'/'.$jeton.'.'.$extension;
        copy($source, $cible);
        $fichier = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal(\sprintf('visuel-ia-%s-%d.%s', strtolower(str_replace('W-', '', (string) $demande->getNumero())), $image->getNumero(), $extension))
            ->setNomStockage($jeton.'.'.$extension)
            ->setTypeMime(FichiersClient::MIME[$extension] ?? 'image/png')
            ->setTaille((int) filesize($cible))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setOrigine(CommandeDetailFichier::ORIGINE_CLIENT)
            ->setClient($image->getClient())
            ->setLogo(false)
            ->setEmpreinte((string) hash_file('sha256', $cible))
            ->marquerUploadTermine();
        $this->em->persist($fichier);
        // Contrôlé à la taille du support : l'atelier voit tout de suite l'agrandissement à faire.
        $this->fichiers->recontroler($fichier, $demande->getLargeurCm(), $demande->getHauteurCm());

        return $fichier;
    }

    /**
     * Textes du client pour le générateur de démonstration (l'en-tête est
     * la première citation « … » des consignes).
     *
     * @return array<string, string>
     */
    public static function textes(DemandeConception $demande, int $numero): array
    {
        $entete = preg_match('/[«"]\s*([^»"]{2,60})\s*[»"]/u', (string) $demande->getConsignes(), $m) ? $m[1] : '';
        $couleurs = $demande->getCouleurs();

        return [
            'entete' => PromptConception::neutraliser($entete),
            'titre' => PromptConception::neutraliser($demande->getTitre()),
            'sousTitre' => PromptConception::neutraliser($demande->getSousTitre()),
            'date' => PromptConception::neutraliser($demande->getDateHeure()),
            'contact' => PromptConception::neutraliser($demande->getCoordonnees()),
            'couleur1' => $couleurs[0] ?? '',
            'couleur2' => $couleurs[1] ?? '',
            'graine' => $demande->getPublicId()->toRfc4122().'-'.$numero,
        ];
    }

    /**
     * Une génération à la fois par client, et pas plus que la limite par heure.
     *
     * @throws \DomainException
     */
    private function verifierRythme(Clients $client, \DateTimeImmutable $maintenant): void
    {
        $depuis = $maintenant->modify('-1 hour');
        $recentes = array_filter(
            $this->em->getRepository(ImageIa::class)->findBy(['client' => $client], ['id' => 'DESC'], 100),
            static fn (ImageIa $i): bool => $i->getCreeLe() > $depuis,
        );
        foreach ($recentes as $r) {
            if (ImageIa::EN_COURS === $r->getStatut() && $r->getCreeLe() > $maintenant->modify(\sprintf('-%d minutes', self::EN_COURS_MINUTES))) {
                throw new \DomainException('Une image est déjà en cours de génération : patientez un instant.');
            }
        }
        $comptees = \count(array_filter($recentes, static fn (ImageIa $r): bool => ImageIa::ECHOUEE !== $r->getStatut()));
        if ($comptees >= $this->reglages->limiteHeure()) {
            throw new \DomainException('Vous avez généré beaucoup d\'images cette heure-ci : réessayez un peu plus tard.');
        }
    }

    private function numeroSuivant(DemandeConception $demande): int
    {
        $derniere = $this->em->getRepository(ImageIa::class)->findOneBy(['demande' => $demande], ['numero' => 'DESC']);

        return 1 + ($derniere instanceof ImageIa ? $derniere->getNumero() : 0);
    }

    private function ranger(Clients $client, ImageGeneree $image): string
    {
        $nom = bin2hex(random_bytes(16)).'.'.$image->extension();
        $chemin = $this->dossier($client).'/'.$nom;
        if (false === file_put_contents($chemin, $image->contenu, \LOCK_EX)) {
            throw new \RuntimeException('Image impossible à enregistrer.');
        }
        @chmod($chemin, 0640);

        return $nom;
    }

    private function dossier(Clients $client): string
    {
        $dossier = $this->projet.'/var/uploads/ia/'.(int) $client->getId();
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des images IA impossible à créer');
        }

        return $dossier;
    }
}
