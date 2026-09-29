<?php

namespace App\Service;

use App\Entity\Etiquette;
use App\Entity\LotEtiquette;
use App\Entity\User;
use App\Repository\EtiquetteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class GenerateurEtiquetteService
{
    private const DOSSIER_RELATIF = 'uploads/etiquettes';
    private const QUANTITE_MAXIMALE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EtiquetteRepository $etiquetteRepository,
        private readonly RouterInterface $router,

        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDirectory,
    ) {
    }

    /**
     * Génère un lot d’étiquettes avec :
     * - les enregistrements en base ;
     * - les QR codes PNG ;
     * - un fichier ZIP contenant tous les PNG.
     */
    public function genererLot(
        int $quantite,
        User $utilisateur,
        string $prefixe = 'ETQ',
    ): LotEtiquette {
        $this->validerQuantite($quantite);

        $prefixe = $this->normaliserPrefixe($prefixe);

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'L’extension PHP ZIP n’est pas activée.'
            );
        }

        $lot = null;
        $dossierAbsolu = null;

        try {
            $this->entityManager->beginTransaction();

            /*
             * On crée d’abord le lot pour obtenir son identifiant.
             */
            $lot = (new LotEtiquette())
                ->setNumero($this->genererNumeroLotTemporaire())
                ->setQuantite($quantite)
                ->setPrefixe($prefixe)
                ->setDossier($this->genererDossierTemporaire())
                ->setGenerePar($utilisateur)
                ->setStatut(LotEtiquette::STATUT_EN_GENERATION);

            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            /*
             * Le numéro définitif utilise l’identifiant du lot.
             *
             * Exemple :
             * LOT-20260806-000001
             */
            $numeroLot = sprintf(
                'LOT-%s-%06d',
                (new \DateTimeImmutable())->format('Ymd'),
                $lot->getId()
            );

            $dossierRelatif = sprintf(
                '%s/%s',
                self::DOSSIER_RELATIF,
                $numeroLot
            );

            $dossierAbsolu = sprintf(
                '%s/%s',
                rtrim($this->publicDirectory, '/'),
                $dossierRelatif
            );

            $lot
                ->setNumero($numeroLot)
                ->setDossier($dossierRelatif);

            $this->creerDossier($dossierAbsolu);

            /*
             * Recherche du prochain compteur disponible.
             */
            $prochaineSequence = $this
                ->etiquetteRepository
                ->trouverProchaineSequence($prefixe);

            $writer = new PngWriter();
            $fichiersCrees = [];

            for ($position = 1; $position <= $quantite; ++$position) {
                $numeroEtiquette = sprintf(
                    '%s-%06d',
                    $prefixe,
                    $prochaineSequence
                );

                /*
                 * 32 octets aléatoires produisent un jeton hexadécimal
                 * imprévisible de 64 caractères.
                 */
                $token = bin2hex(random_bytes(32));

                /*
                 * Le QR contient uniquement l’URL publique avec le jeton.
                 * Aucune commande, aucun client et aucun ID interne.
                 */
                $urlSuivi = $this->router->generate(
                    'app_suivi_etiquette_public',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $nomFichier = sprintf('%s.png', $numeroEtiquette);

                $fichierRelatif = sprintf(
                    '%s/%s',
                    $dossierRelatif,
                    $nomFichier
                );

                $fichierAbsolu = sprintf(
                    '%s/%s',
                    rtrim($this->publicDirectory, '/'),
                    $fichierRelatif
                );

                $qrCode = new QrCode(
                    data: $urlSuivi,
                    encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                    errorCorrectionLevel: ErrorCorrectionLevel::High,
                    size: 800,
                    margin: 30,
                    roundBlockSizeMode: RoundBlockSizeMode::Margin
                );

                $resultat = $writer->write($qrCode);
                $resultat->saveToFile($fichierAbsolu);

                if (!is_file($fichierAbsolu)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Le QR code %s n’a pas été créé.',
                            $numeroEtiquette
                        )
                    );
                }

                $etiquette = (new Etiquette())
                    ->setNumero($numeroEtiquette)
                    ->setToken($token)
                    ->setFichierQr($fichierRelatif)
                    ->setStatut(Etiquette::STATUT_DISPONIBLE);

                $lot->addEtiquette($etiquette);

                $this->entityManager->persist($etiquette);

                $fichiersCrees[] = [
                    'chemin' => $fichierAbsolu,
                    'nom' => $nomFichier,
                ];

                ++$prochaineSequence;
            }

            $this->entityManager->flush();

            $nomZip = sprintf('%s.zip', $numeroLot);

            $fichierZipRelatif = sprintf(
                '%s/%s',
                $dossierRelatif,
                $nomZip
            );

            $fichierZipAbsolu = sprintf(
                '%s/%s',
                rtrim($this->publicDirectory, '/'),
                $fichierZipRelatif
            );

            $this->creerArchiveZip(
                $fichierZipAbsolu,
                $fichiersCrees
            );

            $lot->setFichierZip($fichierZipRelatif);
            $lot->marquerCommePret();

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $lot;
        } catch (\Throwable $exception) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if ($dossierAbsolu !== null) {
                $this->supprimerDossier($dossierAbsolu);
            }

            throw new \RuntimeException(
                sprintf(
                    'Impossible de générer le lot d’étiquettes : %s',
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    private function validerQuantite(int $quantite): void
    {
        if ($quantite < 1) {
            throw new \InvalidArgumentException(
                'La quantité doit être supérieure à zéro.'
            );
        }

        if ($quantite > self::QUANTITE_MAXIMALE) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La quantité maximale autorisée par lot est de %d.',
                    self::QUANTITE_MAXIMALE
                )
            );
        }
    }

    private function normaliserPrefixe(string $prefixe): string
    {
        $prefixe = strtoupper(trim($prefixe));

        /*
         * On conserve uniquement les lettres et chiffres.
         */
        $prefixe = preg_replace(
            '/[^A-Z0-9]/',
            '',
            $prefixe
        ) ?? '';

        if ($prefixe === '') {
            throw new \InvalidArgumentException(
                'Le préfixe des étiquettes est invalide.'
            );
        }

        if (strlen($prefixe) > 20) {
            throw new \InvalidArgumentException(
                'Le préfixe ne doit pas dépasser 20 caractères.'
            );
        }

        return $prefixe;
    }

    private function genererNumeroLotTemporaire(): string
    {
        return sprintf(
            'TMP-%s',
            strtoupper(bin2hex(random_bytes(8)))
        );
    }

    private function genererDossierTemporaire(): string
    {
        return sprintf(
            '%s/TMP-%s',
            self::DOSSIER_RELATIF,
            strtoupper(bin2hex(random_bytes(8)))
        );
    }

    private function creerDossier(string $dossier): void
    {
        if (is_dir($dossier)) {
            throw new \RuntimeException(
                sprintf('Le dossier %s existe déjà.', $dossier)
            );
        }

        if (!mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException(
                sprintf(
                    'Impossible de créer le dossier %s.',
                    $dossier
                )
            );
        }
    }

    /**
     * @param list<array{chemin: string, nom: string}> $fichiers
     */
    private function creerArchiveZip(
        string $cheminZip,
        array $fichiers,
    ): void {
        $archive = new \ZipArchive();

        $ouverture = $archive->open(
            $cheminZip,
            \ZipArchive::CREATE | \ZipArchive::OVERWRITE
        );

        if ($ouverture !== true) {
            throw new \RuntimeException(
                'Impossible de créer le fichier ZIP.'
            );
        }

        try {
            foreach ($fichiers as $fichier) {
                if (!is_file($fichier['chemin'])) {
                    throw new \RuntimeException(
                        sprintf(
                            'Le fichier %s est introuvable.',
                            $fichier['nom']
                        )
                    );
                }

                if (!$archive->addFile(
                    $fichier['chemin'],
                    $fichier['nom']
                )) {
                    throw new \RuntimeException(
                        sprintf(
                            'Impossible d’ajouter %s au ZIP.',
                            $fichier['nom']
                        )
                    );
                }
            }
        } finally {
            $archive->close();
        }

        if (!is_file($cheminZip)) {
            throw new \RuntimeException(
                'Le fichier ZIP n’a pas été créé.'
            );
        }
    }

    private function supprimerDossier(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }

        $elements = scandir($dossier);

        if ($elements === false) {
            return;
        }

        foreach ($elements as $element) {
            if ($element === '.' || $element === '..') {
                continue;
            }

            $chemin = $dossier . '/' . $element;

            if (is_dir($chemin)) {
                $this->supprimerDossier($chemin);
                continue;
            }

            @unlink($chemin);
        }

        @rmdir($dossier);
    }
}