<?php

namespace App\Service;

use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Repository\EntrepriseRepository;
use App\Repository\FacturesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce qu'il faut au modèle de facture (maquettes I01, I02) : l'entreprise
 * émettrice, ses images, les deux QR et le montant en lettres.
 */
final class DocumentsEntreprise
{
    /**
     * Logos, logo du pied et cachet-signature déposés dans les réglages :
     * chemin enregistré « uploads/entreprises/… », relatif à var/ (hors de
     * public/, FichiersPrives). Jamais servis par une adresse : ils sont
     * copiés dans les documents (dataUri).
     */
    public const DOSSIER_IMAGES = 'uploads/entreprises';

    public function __construct(
        private readonly EntrepriseRepository $entreprises,
        private readonly FacturesRepository $factures,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $dossierPublic,
        private readonly FichiersPrives $fichiersPrives,
    ) {
    }

    /**
     * L'entreprise du code, ou à défaut une entreprise provisoire au nom
     * de l'émetteur : une facture sort toujours.
     */
    public function entreprise(string $code, string $libelle): Entreprise
    {
        return $this->entreprises->parCode($code)
            ?? (new Entreprise())->setCode($code)->setRaisonSociale($libelle);
    }

    /**
     * @return array{logo: ?string, logoPied: ?string, signature: ?string}
     */
    public function images(Entreprise $entreprise): array
    {
        return [
            'logo' => $this->dataUri($entreprise->getLogo()),
            'logoPied' => $entreprise->isAfficherLogoPied() ? $this->dataUri($entreprise->getLogoPied()) : null,
            'signature' => $this->dataUri($entreprise->getSignature()),
        ];
    }

    /**
     * Donne un code de suivi à la facture si elle n'en a pas encore.
     */
    public function codeSuivi(Factures $facture): string
    {
        if ($facture->getCodeSuivi() !== null) {
            return $facture->getCodeSuivi();
        }

        // 31^6 codes possibles : une collision est très rare, on retire au besoin.
        for ($essai = 0; $essai < 5; ++$essai) {
            $facture->genererCodeSuivi(true);

            if ($this->factures->findOneBy(['codeSuivi' => $facture->getCodeSuivi()]) === null) {
                break;
            }
        }

        $this->em->flush();

        return (string) $facture->getCodeSuivi();
    }

    /**
     * @return array{entreprise: Entreprise, images: array<string, ?string>, urlSuivi: ?string, qrSuivi: ?string, qrPaiement: ?string, montantLettres: ?string}
     */
    public function vueFacture(Factures $facture): array
    {
        $entreprise = $this->entreprise($facture->getEmetteur(), $facture->getEmetteurLabel());
        $urlSuivi = null;

        if ($entreprise->isQrSuivi() || $entreprise->isQrPaiement()) {
            $urlSuivi = $this->urls->generate('app_facture_suivi', ['code' => $this->codeSuivi($facture)], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $payable = !$facture->estProforma() && !$facture->estAnnulee() && $facture->getResteAPayer() > 0;

        return [
            'entreprise' => $entreprise,
            'images' => $this->images($entreprise),
            'urlSuivi' => $urlSuivi,
            'qrSuivi' => $entreprise->isQrSuivi() && $urlSuivi !== null ? self::qr($urlSuivi) : null,
            'qrPaiement' => $entreprise->isQrPaiement() && $payable && $urlSuivi !== null ? self::qr($urlSuivi.'#payer') : null,
            'montantLettres' => $entreprise->isMontantEnLettres() ? MontantEnLettres::phraseFacture($facture->getTotalTtc(), $facture->getMontantTva() > 0) : null,
        ];
    }

    public static function qr(string $contenu): string
    {
        // PNG avec GD, sinon SVG : Dompdf et les navigateurs lisent les deux.
        $writer = \extension_loaded('gd') ? new PngWriter() : new SvgWriter();

        return $writer->write(new QrCode(
            data: $contenu,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 240,
            margin: 6,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->getDataUri();
    }

    public function dataUri(?string $chemin): ?string
    {
        if ($chemin === null) {
            return null;
        }

        if (str_starts_with(ltrim($chemin, '/'), self::DOSSIER_IMAGES.'/')) {
            // Image déposée dans les réglages : sous var/ (encore sous public/ avant app:fichiers:deplacer).
            $reel = $this->fichiersPrives->chemin($chemin) ?? false;
        } else {
            // Image livrée avec l'application (public/images/…) : jamais un chemin qui sort de public/.
            $reel = realpath($this->dossierPublic.'/'.ltrim($chemin, '/'));
            if ($reel !== false && !str_starts_with($reel, (string) realpath($this->dossierPublic).\DIRECTORY_SEPARATOR)) {
                $reel = false;
            }
        }

        if ($reel === false || !is_readable($reel)) {
            return null;
        }

        $contenu = file_get_contents($reel);

        return $contenu === false ? null : sprintf('data:%s;base64,%s', mime_content_type($reel) ?: 'image/png', base64_encode($contenu));
    }
}
