<?php

namespace App\Service\Atelier;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\CreationAtelier;
use App\Entity\DemandeDevis;
use App\Entity\ModeleAtelier;
use App\Entity\ProduitConfiguration;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Site\DemandesSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * « Valider mon modèle » dans l'Atelier 3D (docs/atelier-3d.md).
 *
 * La page envoie l'article, les couleurs, les quantités, le marquage de
 * chaque face (jeton de l'image déposée, taille, hauteur, texte, police)
 * et les deux vues rendues. Tout est relu ici : rien de ce qui vient de la
 * page ne fixe un prix. Le modèle devient une CreationAtelier, puis :
 *  - pour un client connecté, une commande « à confirmer » par l'atelier,
 *    comme Nouvelle commande (validation de l'entreprise comprise) ;
 *  - pour un visiteur, une demande de devis sans compte (prospect « Site »
 *    et devis brouillon au local).
 * Les vues et les images d'origine sont jointes à la ligne : maquette du
 * client pour le graphiste, qui prépare le vrai BAT.
 */
final class CreationsAtelier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImagesAtelier $images,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly TarifAtelier $tarif,
        private readonly DemandesSite $demandesSite,
    ) {
    }

    /**
     * Relit l'envoi de la page pour cet article.
     *
     * @param array<string, mixed> $donnees champs du formulaire (faces en JSON)
     *
     * @return array{modele: ModeleAtelier, couleur: array{nom: string, hex: string}, visiere: ?array{nom: string, hex: string}, tailles: array<string, int>, quantite: int, faces: array<string, array{reglages: array<string, mixed>, image: mixed}>}
     *
     * @throws \DomainException
     */
    public static function lire(ModeleAtelier $modele, array $donnees): array
    {
        $couleur = $modele->couleur(\is_string($donnees['couleur'] ?? null) ? $donnees['couleur'] : null)
            ?? throw new \DomainException('Choisissez une couleur de la liste.');
        $visiere = null;
        if (ModeleAtelier::CASQUETTE === $modele->getForme()) {
            $visiere = $modele->couleur(\is_string($donnees['visiere'] ?? null) ? $donnees['visiere'] : null) ?? $couleur;
        }

        if ($modele->aDesTailles()) {
            $tailles = CreationAtelier::quantitesParTaille(\is_array($donnees['tailles'] ?? null) ? $donnees['tailles'] : [], $modele->getTailles());
            $quantite = array_sum($tailles);
        } else {
            $tailles = [];
            $quantite = max(0, min(CreationAtelier::QUANTITE_TAILLE_MAX, (int) ($donnees['quantite'] ?? 0)));
        }
        if ($quantite < 1) {
            throw new \DomainException('Indiquez au moins une pièce.');
        }

        $brut = json_decode(\is_string($donnees['faces'] ?? null) ? $donnees['faces'] : '{}', true);
        $brut = \is_array($brut) ? $brut : [];
        $faces = [];
        foreach (CreationAtelier::FACES as $face) {
            $f = \is_array($brut[$face] ?? null) ? $brut[$face] : [];
            $image = $f['image'] ?? null;
            $reglages = CreationAtelier::reglagesFace($f, null !== $image && '' !== $image, null);
            if (null !== $reglages) {
                $faces[$face] = ['reglages' => $reglages, 'image' => $image];
            }
        }
        if ([] === $faces) {
            throw new \DomainException('Ajoutez une image ou un texte sur au moins une face.');
        }

        return ['modele' => $modele, 'couleur' => $couleur, 'visiere' => $visiere, 'tailles' => $tailles, 'quantite' => $quantite, 'faces' => $faces];
    }

    /**
     * Commande d'un client connecté, « à confirmer » par l'atelier.
     *
     * @param array{modele: ModeleAtelier, couleur: array{nom: string, hex: string}, visiere: ?array{nom: string, hex: string}, tailles: array<string, int>, quantite: int, faces: array<string, array{reglages: array<string, mixed>, image: mixed}>} $lu
     * @param array<string, string>                                                                                                                                                                                                                   $rendus face => octets
     *
     * @throws \DomainException
     */
    public function commander(CompteClient $compte, array $lu, array $rendus, ?string $note, SessionInterface $session, \DateTimeImmutable $le): CreationAtelier
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans client.');
        $config = $lu['modele']->getConfiguration() ?? throw new \DomainException('Article indisponible.');
        // Avant de ranger quoi que ce soit : quantités permises, commandes en attente.
        NouvelleCommande::construireLigne($config, $lu['quantite'], null, null, [], $client->isB2B());
        $this->nouvelleCommande->verifierEnAttente($client);

        $ecrits = [];
        try {
            return $this->em->wrapInTransaction(function () use ($compte, $client, $config, $lu, $rendus, $note, $session, $le, &$ecrits): CreationAtelier {
                $creation = $this->creer($lu, $rendus, $client, $compte->getNom() ?? $client->getNomComplet(), $session, $le, $ecrits);
                $reglages = $this->tarif->reglages();
                $faces = \count($creation->facesMarquees());
                $b2b = $client->isB2B();
                $designation = $creation->getDesignation();
                $texte = $creation->note().(null !== $note && '' !== trim($note) ? "\n".trim($note) : '');
                $commande = $this->nouvelleCommande->commander($client, $config, $lu['quantite'], null, null, [], self::fichiers($creation), mb_substr($texte, 0, 2000), $le,
                    static function (CommandesDetails $ligne) use ($faces, $reglages, $b2b, $designation): void {
                        TarifAtelier::appliquer($ligne, $faces, $reglages, $b2b);
                        $ligne->setDesignation($designation);
                    });
                $creation->noterCommande($commande, $le);
                $this->em->flush();

                return $creation;
            });
        } catch (\Throwable $e) {
            foreach ($ecrits as $chemin) {
                @unlink($chemin);
            }
            throw $e;
        }
    }

    /**
     * Demande de devis d'un visiteur (FormulaireDemande::valider a contrôlé
     * son nom, son numéro et son message).
     *
     * @param array{modele: ModeleAtelier, couleur: array{nom: string, hex: string}, visiere: ?array{nom: string, hex: string}, tailles: array<string, int>, quantite: int, faces: array<string, array{reglages: array<string, mixed>, image: mixed}>} $lu
     * @param array<string, string>                                                                                                                                                                                                                   $rendus
     * @param array<string, mixed>                                                                                                                                                                                                                    $valeurs
     *
     * @throws \DomainException
     */
    public function demanderDevis(array $lu, array $rendus, array $valeurs, SessionInterface $session, \DateTimeImmutable $le): DemandeDevis
    {
        $config = $lu['modele']->getConfiguration() ?? throw new \DomainException('Article indisponible.');
        NouvelleCommande::construireLigne($config, $lu['quantite'], null, null, [], false);

        $ecrits = [];
        try {
            $creation = $this->em->wrapInTransaction(fn (): CreationAtelier => $this->creer($lu, $rendus, null, (string) $valeurs['nom'], $session, $le, $ecrits));
        } catch (\Throwable $e) {
            foreach ($ecrits as $chemin) {
                @unlink($chemin);
            }
            throw $e;
        }
        $message = trim((string) ($valeurs['message'] ?? ''));
        $valeurs['message'] = mb_substr(('' !== $message ? $message."\n\n" : '').$creation->note(), 0, DemandeDevis::MESSAGE_MAX);
        $valeurs['produit'] = $config;
        $valeurs['quantite'] = $lu['quantite'];
        $valeurs['largeur'] = $valeurs['hauteur'] = null;
        $valeurs['finitions'] = [];

        return $this->demandesSite->enregistrer(DemandeDevis::NATURE_DEVIS, $valeurs, null, $le, $creation);
    }

    /**
     * « Commander à nouveau » depuis Mes créations : mêmes réglages, mêmes
     * quantités, prix du jour ; chaque commande a sa copie des fichiers.
     *
     * @throws \DomainException
     */
    public function recommander(CompteClient $compte, CreationAtelier $creation, ?string $note, \DateTimeImmutable $le): Commandes
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans client.');
        if ($creation->getClient()?->getId() !== $client->getId()) {
            throw new \DomainException('Cette création n\'est pas à vous.');
        }
        $config = $creation->getConfiguration();
        $modele = null !== $config ? $this->em->getRepository(ModeleAtelier::class)->findOneBy(['configuration' => $config, 'actif' => true]) : null;
        if (null === $config || null === $modele || !\in_array($config, $this->nouvelleCommande->produits(), true)) {
            throw new \DomainException('Cet article n\'est plus proposé : ouvrez la création dans l\'atelier pour en choisir un autre.');
        }
        NouvelleCommande::construireLigne($config, $creation->getQuantite(), null, null, [], $client->isB2B());

        return $this->em->wrapInTransaction(function () use ($creation, $client, $config, $note, $le): Commandes {
            $copies = [];
            foreach (self::fichiers($creation) as $f) {
                if (null !== ($copie = $this->images->copier($f))) {
                    $copies[] = $copie;
                }
            }
            $reglages = $this->tarif->reglages();
            $faces = \count($creation->facesMarquees());
            $b2b = $client->isB2B();
            $designation = $creation->getDesignation();
            $texte = 'Commandé à nouveau. '.$creation->note().(null !== $note && '' !== trim($note) ? "\n".trim($note) : '');
            $commande = $this->nouvelleCommande->commander($client, $config, $creation->getQuantite(), null, null, [], $copies, mb_substr($texte, 0, 2000), $le,
                static function (CommandesDetails $ligne) use ($faces, $reglages, $b2b, $designation): void {
                    TarifAtelier::appliquer($ligne, $faces, $reglages, $b2b);
                    $ligne->setDesignation($designation);
                });
            $creation->noterCommande($commande, $le);
            $this->em->flush();

            return $commande;
        });
    }

    /** @return list<CreationAtelier> les créations du client, les plus récentes d'abord */
    public function duClient(Clients $client, int $nombre = 60): array
    {
        return $this->em->getRepository(CreationAtelier::class)->findBy(['client' => $client], ['id' => 'DESC'], $nombre);
    }

    /** Une création du client, ou null si elle est à un autre. */
    public function duClientParId(Clients $client, string $publicId): ?CreationAtelier
    {
        foreach ($this->duClient($client, 500) as $c) {
            if ($c->getPublicId()->toRfc4122() === $publicId) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Fichiers à joindre à la ligne : vues puis images d'origine.
     *
     * @return list<CommandeDetailFichier>
     */
    public static function fichiers(CreationAtelier $c): array
    {
        return array_values(array_filter([$c->getRenduDevant(), $c->getRenduDos(), $c->getImageDevant(), $c->getImageDos()]));
    }

    /**
     * Réglages d'une création pour la rouvrir dans l'atelier (sans les
     * images, que la page demande à part).
     *
     * @return array<string, mixed>
     */
    public static function pourLaPage(CreationAtelier $c): array
    {
        $faces = [];
        foreach ($c->getReglages() as $face => $r) {
            $faces[$face] = $r + ['source' => ($r['image'] ?? false) && null !== $c->fichier('image-'.$face) ? 'creation:'.$c->getPublicId()->toRfc4122().':'.$face : null];
        }

        return [
            'id' => $c->getPublicId()->toRfc4122(),
            'numero' => $c->getNumero(),
            'couleur' => $c->getCouleur()['nom'],
            'visiere' => $c->getCouleurVisiere()['nom'] ?? null,
            'tailles' => $c->getTailles(),
            'quantite' => $c->getQuantite(),
            'faces' => $faces,
        ];
    }

    /**
     * Crée la création, ses images et ses vues (déjà dans une transaction).
     *
     * @param list<string> $ecrits fichiers écrits, à effacer si la suite échoue
     */
    private function creer(array $lu, array $rendus, ?Clients $client, ?string $auteur, SessionInterface $session, \DateTimeImmutable $le, array &$ecrits): CreationAtelier
    {
        /** @var ModeleAtelier $modele */
        $modele = $lu['modele'];
        $config = $modele->getConfiguration();
        $creation = (new CreationAtelier())
            ->setClient($client)
            ->setAuteur($auteur)
            ->setConfiguration($config)
            ->setForme($modele->getForme())
            ->setCouleur($lu['couleur'])
            ->setCouleurVisiere($lu['visiere'])
            ->setTailles($lu['tailles'])
            ->setQuantite($lu['quantite'])
            ->setCreeLe($le);
        $creation->setDesignation(self::designation($config).' · '.$creation->libelleCouleur());

        $reglages = [];
        $jetons = [];
        foreach ($lu['faces'] as $face => $f) {
            $r = $f['reglages'];
            if ($r['image']) {
                [$fichier, $nom] = $this->imageDeLaFace($f['image'], $face, CreationAtelier::tailleImage($r), $modele, $client, $session, $ecrits, $jetons);
                $r['nomImage'] = mb_substr($nom, 0, 120);
                CreationAtelier::DEVANT === $face ? $creation->setImageDevant($fichier) : $creation->setImageDos($fichier);
            }
            $reglages[$face] = $r;
        }
        $creation->setReglages($reglages);

        foreach (CreationAtelier::FACES as $face) {
            if (!isset($rendus[$face])) {
                throw new \DomainException('Vues du modèle manquantes : réessayez.');
            }
            $rendu = ImagesAtelier::lireRendu($rendus[$face]);
            $libelle = $creation->libelleFace($face);
            $fichier = $this->images->ranger($rendu['octets'], 'png', 'image/png', 'atelier-3d-'.$face.'.png', $client, $face, 'Maquette du client · '.mb_strtolower($libelle).' (Atelier 3D)', null);
            $ecrits[] = (string) $this->images->chemin($fichier);
            CreationAtelier::DEVANT === $face ? $creation->setRenduDevant($fichier) : $creation->setRenduDos($fichier);
        }

        $this->em->persist($creation);
        $this->em->flush();
        // Le numéro vient de l'id ; en ligne il reçoit le préfixe W-.
        $creation->setNumero(\sprintf('CR-%04d', $creation->getId()));
        $this->em->flush();
        foreach ($jetons as $jeton) {
            $this->images->oublier($session, $jeton);
        }

        return $creation;
    }

    /**
     * L'image d'une face : un dépôt de cette session, ou celle d'une
     * création du même client (création rouverte dans l'atelier).
     *
     * @param list<string> $ecrits
     * @param list<string> $jetons
     *
     * @return array{0: CommandeDetailFichier, 1: string} le fichier rangé et le nom donné par le client
     */
    private function imageDeLaFace(mixed $source, string $face, int $taille, ModeleAtelier $modele, ?Clients $client, SessionInterface $session, array &$ecrits, array &$jetons): array
    {
        $libelle = 'Image du client · '.('dos' === $face ? (ModeleAtelier::CASQUETTE === $modele->getForme() ? 'arrière' : 'dos') : 'devant').' (Atelier 3D)';
        if (\is_string($source) && preg_match('/^creation:([0-9a-f-]{36}):(devant|dos)$/', $source, $m)) {
            $ancienne = null !== $client ? $this->duClientParId($client, $m[1]) : null;
            $original = $ancienne?->fichier('image-'.$m[2]);
            $copie = null !== $original ? $this->images->copier($original) : null;
            if (null === $copie) {
                throw new \DomainException('L\'image de la création d\'origine n\'est plus disponible : choisissez-la de nouveau.');
            }
            $ecrits[] = (string) $this->images->chemin($copie);
            $copie->setFace($face)->setDesignation($libelle);
            [$largeur, $hauteur] = @getimagesize((string) $this->images->chemin($copie)) ?: [0, 0];
            $copie->setAnalyse(self::analyse((int) $largeur, (int) $hauteur, $modele, $taille));

            return [$copie, (string) $original->getNomOriginal()];
        }

        $depot = $this->images->depot($session, $source);
        if (null === $depot) {
            throw new \DomainException('Une image n\'est plus disponible (page restée ouverte trop longtemps) : choisissez-la de nouveau.');
        }
        $octets = (string) file_get_contents($depot['chemin']);
        $fichier = $this->images->ranger($octets, $depot['extension'], $depot['mime'], $depot['nom'], $client, $face, $libelle, self::analyse($depot['largeur'], $depot['hauteur'], $modele, $taille));
        $ecrits[] = (string) $this->images->chemin($fichier);
        $jetons[] = (string) $source;

        return [$fichier, $depot['nom']];
    }

    /**
     * « T-shirt personnalisé · T-shirt coton » : le produit et son support,
     * sans le format du catalogue (les tailles sont dans la création).
     */
    public static function designation(?ProduitConfiguration $config): string
    {
        if (null === $config) {
            return 'Article de l\'Atelier 3D';
        }
        $nom = (string) $config->getProduit()?->getNom();
        $support = $config->getSupport()?->getNom();

        return mb_substr(null !== $support && '' !== $support && !str_contains(mb_strtolower($nom), mb_strtolower($support)) ? $nom.' · '.$support : $nom, 0, 200);
    }

    /**
     * Contrôle de l'image à sa taille d'impression, au format de
     * « Mes fichiers » (AnalyseFichierImpression).
     *
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public static function analyse(int $largeurPx, int $hauteurPx, ModeleAtelier $modele, int $taille): array
    {
        if ($largeurPx < 1 || $hauteurPx < 1) {
            return [];
        }
        $c = ImagesAtelier::controleResolution($largeurPx, $hauteurPx, $modele->getZoneLargeurCm(), $modele->getZoneHauteurCm(), $taille);

        return [
            ['libelle' => 'Dimensions', 'valeur' => \sprintf('%d × %d px', $largeurPx, $hauteurPx), 'etat' => 'ok'],
            [
                'libelle' => \sprintf('Résolution à %s × %s cm (Atelier 3D)', str_replace('.', ',', (string) $c['largeurCm']), str_replace('.', ',', (string) $c['hauteurCm'])),
                'valeur' => \sprintf('%d dpi (conseillé : %d)', $c['dpi'], ImagesAtelier::DPI_CONSEILLE),
                'etat' => $c['etat'],
            ],
        ];
    }
}
