<?php

namespace App\Service\Site;

use App\Entity\ReglageSite;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * « Nous soutenir » (demande de Yaya du 17/10/2026) : inviter les clients
 * à laisser un avis sur la fiche Google de l'imprimerie, sur le site public
 * (bloc de l'accueil, lien du pied de page) et dans l'espace client (carte
 * du tableau de bord, invitation sur une commande livrée ou retirée).
 *
 * Réglé dans Réglages › Site vitrine › Nous soutenir, rangé dans un
 * ReglageSite (clé « soutien ») qui descend au site par la synchronisation
 * (type site_reglage), comme le référencement. Lien vide : tout disparaît.
 *
 * Le QR code est fait ici, par la bibliothèque des QR des factures
 * (endroid/qr-code) : ni appel extérieur, ni script de mesure. Il est
 * proposé à tous, quelle que soit leur note (Google interdit de ne
 * solliciter que les clients contents).
 */
final class SoutienSite implements ResetInterface
{
    public const CLE_REGLAGE = 'soutien';

    /** Lien « Laisser un avis » de la fiche Google, envoyé par Yaya le 17/10/2026. */
    public const LIEN_DEFAUT = 'https://g.page/r/Cb0JM2s-39CFECE/review';

    public const DEFAUTS = ['lien' => self::LIEN_DEFAUT, 'qr' => true];

    /** @var array{lien: string, qr: bool}|null */
    private ?array $reglages = null;

    private ?string $qr = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array{lien: string, qr: bool} */
    public function reglages(): array
    {
        if (null === $this->reglages) {
            $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);
            // Ce qui arrive par la synchronisation est relu comme une saisie.
            $this->reglages = null === $reglage ? self::DEFAUTS : self::lire($reglage->getValeurs())['valeurs'];
        }

        return $this->reglages;
    }

    /** Lien « Laisser un avis », ou null quand la partie est coupée. */
    public function lien(): ?string
    {
        $lien = $this->reglages()['lien'];

        return '' === $lien ? null : $lien;
    }

    /** QR code proposé à côté du bouton (sur ordinateur). */
    public function avecQr(): bool
    {
        return null !== $this->lien() && $this->reglages()['qr'];
    }

    /**
     * QR code du lien, en SVG (un seul tracé, quelques Ko), ou null sans
     * lien ou sans QR.
     */
    public function qrSvg(): ?string
    {
        if (!$this->avecQr()) {
            return null;
        }

        return $this->qr ??= self::svg((string) $this->lien());
    }

    public static function svg(string $contenu): string
    {
        return (new SvgWriter())->write(new QrCode(
            data: $contenu,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 240,
            margin: 8,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->getString();
    }

    /**
     * Enregistre la saisie de l'onglet. Un lien refusé n'est pas
     * enregistré (l'ancien reste) et son message revient.
     *
     * @param array<string, mixed> $donnees
     *
     * @return list<string> messages des valeurs refusées
     */
    public function enregistrer(array $donnees): array
    {
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);
        if (null === $reglage) {
            $reglage = new ReglageSite(self::CLE_REGLAGE);
            $this->em->persist($reglage);
        }
        $anciennes = null === $reglage->getId() ? self::DEFAUTS : self::lire($reglage->getValeurs())['valeurs'];
        $lu = self::lire($donnees);
        $valeurs = $lu['valeurs'];
        foreach (array_keys($lu['erreurs']) as $cle) {
            $valeurs[$cle] = $anciennes[$cle];
        }
        $reglage->setValeurs($valeurs);
        $this->em->flush();
        $this->reset();

        return array_values($lu['erreurs']);
    }

    /**
     * Lit une saisie (ou un réglage reçu) : le lien est un lien https de
     * Google (g.page, google.com, goo.gl…), vide pour tout cacher.
     *
     * @param array<string, mixed> $saisie
     *
     * @return array{valeurs: array{lien: string, qr: bool}, erreurs: array<string, string>}
     */
    public static function lire(array $saisie): array
    {
        $valeurs = self::DEFAUTS;
        $erreurs = [];
        if (\array_key_exists('lien', $saisie)) {
            $texte = trim(\is_scalar($saisie['lien']) ? (string) $saisie['lien'] : '');
            if ('' === $texte) {
                $valeurs['lien'] = '';
            } elseif (null === $lien = ReferencementSite::lien($texte, ReferencementSite::DOMAINES_FICHE_GOOGLE)) {
                $erreurs['lien'] = 'Lien non reconnu : collez le lien « Demander des avis » de la fiche Google (https://g.page/r/…/review), ou laissez vide pour cacher « Nous soutenir ».';
            } else {
                $valeurs['lien'] = $lien;
            }
        }
        if (\array_key_exists('qr', $saisie)) {
            $valeurs['qr'] = \in_array($saisie['qr'], [true, 1, '1', 'on'], true);
        } elseif (\array_key_exists('lien', $saisie)) {
            // Case décochée d'un formulaire : absente de l'envoi.
            $valeurs['qr'] = false;
        }

        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }

    public function reset(): void
    {
        $this->reglages = null;
        $this->qr = null;
    }
}
