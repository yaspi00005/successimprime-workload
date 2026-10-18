<?php

namespace App\Service\Devis;

use App\Entity\Devis;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * PDF d'un devis (gabarit devis/pdf.html.twig) : le même pour l'admin,
 * le lien public envoyé au client et la pièce jointe de l'e-mail. Le
 * jeton d'authenticité (QR code de vérification) est créé s'il manque :
 * l'appelant enregistre.
 */
final class PdfDevis
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function contenu(Devis $devis): string
    {
        if ('' === trim((string) $devis->getTokenAuthenticite())) {
            $devis->genererTokenAuthenticite();
        }
        $qrCode = $this->qrCode($devis);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        // Logos et signatures locales.
        $options->set('chroot', $this->projectDir);
        $dompdf = new Dompdf($options);

        $images = $this->projectDir.'/public/assets/images/documents/';
        $html = $this->twig->render('devis/pdf.html.twig', [
            'devis' => $devis,
            'client' => $devis->getClients(),
            'commande' => $devis->getCommande(),
            'documents' => [
                'drepa' => [
                    'logo' => self::dataUri($images.'drepa-logo.png'),
                    'signature' => self::dataUri($images.'drepa-signature-cachet.png'),
                ],
                'mdg' => [
                    'logo' => self::dataUri($images.'mdg-logo.png'),
                    'success_logo' => self::dataUri($images.'success-imprim-logo.png'),
                    'signature' => self::dataUri($images.'mdg-signature-cachet.png'),
                ],
            ],
            'qrCodeDataUri' => $qrCode['dataUri'],
            'verificationUrl' => $qrCode['url'],
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** « DEV-000123-10-2026 » sans caractère gênant, pour le nom du fichier. */
    public static function nomFichier(Devis $devis): string
    {
        $numero = $devis->getNumero() ?: \sprintf('DEV-%05d', $devis->getId());

        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '-', $numero);
    }

    /** @return array{url: string, dataUri: string} */
    private function qrCode(Devis $devis): array
    {
        $url = $this->urls->generate('app_devis_verifier', ['token' => $devis->getTokenAuthenticite()], UrlGeneratorInterface::ABSOLUTE_URL);
        $resultat = (new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 220,
            margin: 10,
        ))->build();

        return ['url' => $url, 'dataUri' => $resultat->getDataUri()];
    }

    private static function dataUri(string $chemin): ?string
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            return null;
        }
        $contenu = file_get_contents($chemin);
        if (false === $contenu) {
            return null;
        }

        return \sprintf('data:%s;base64,%s', mime_content_type($chemin) ?: 'image/png', base64_encode($contenu));
    }
}
