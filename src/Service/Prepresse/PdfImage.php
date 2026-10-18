<?php

namespace App\Service\Prepresse;

/**
 * PDF d'une seule page qui porte une image à sa taille physique
 * (docs/prepresse-retouche.md) : MediaBox au format du fichier, TrimBox au
 * format fini et BleedBox au fond perdu, pour que le RIP imprime à la bonne
 * taille sans rien recalculer.
 *
 * Écrit à la main plutôt qu'avec dompdf (déjà dans le projet) : dompdf
 * passe par du HTML et recharge l'image en mémoire, ce qui ne tient pas
 * pour une bâche de 100 millions de pixels. Ici, le JPEG est recopié tel
 * quel dans le PDF (DCTDecode), sans le décompresser.
 *
 * Une image avec transparence (logo détouré) garde sa transparence
 * (SMask) jusqu'à TRANSPARENCE_MAX_PX ; au-delà, elle est posée sur du blanc.
 */
final class PdfImage
{
    public const TRANSPARENCE_MAX_PX = 12_000_000;
    private const PT_PAR_MM = 72 / 25.4;

    /**
     * @return bool true si la transparence a été gardée
     */
    public static function ecrire(string $chemin, \GdImage $image, float $largeurMm, float $hauteurMm, float $fondPerduMm, bool $transparence, string $titre): bool
    {
        $l = imagesx($image);
        $h = imagesy($image);
        $garderAlpha = $transparence && $l * $h <= self::TRANSPARENCE_MAX_PX;

        $f = fopen($chemin, 'wb');
        if (false === $f) {
            throw new \RuntimeException('Impossible d\'écrire le PDF.');
        }
        $positions = [];
        $ecrit = 0;
        $sortir = static function (string $texte) use ($f, &$ecrit): void {
            fwrite($f, $texte);
            $ecrit += \strlen($texte);
        };
        $objet = static function (int $n, string $corps) use (&$positions, &$ecrit, $sortir): void {
            $positions[$n] = $ecrit;
            $sortir($n." 0 obj\n".$corps."\nendobj\n");
        };

        $sortir("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $W = self::pt($largeurMm);
        $H = self::pt($hauteurMm);
        $b = self::pt($fondPerduMm);
        $boites = \sprintf('/MediaBox [0 0 %s %s]', $W, $H);
        if ($fondPerduMm > 0) {
            $boites .= \sprintf(' /BleedBox [0 0 %s %s] /TrimBox [%s %s %s %s]', $W, $H, $b, $b, self::pt($largeurMm - $fondPerduMm), self::pt($hauteurMm - $fondPerduMm));
        } else {
            $boites .= \sprintf(' /TrimBox [0 0 %s %s]', $W, $H);
        }

        $objet(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $objet(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        $objet(3, '<< /Type /Page /Parent 2 0 R '.$boites.' /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>');

        if ($garderAlpha) {
            [$rvb, $alpha] = self::canaux($image);
            $rvb = gzcompress($rvb, 6);
            $alpha = gzcompress($alpha, 6);
            $positions[4] = $ecrit;
            $sortir(\sprintf("4 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask 7 0 R /Length %d >>\nstream\n", $l, $h, \strlen($rvb)));
            $sortir($rvb);
            $sortir("\nendstream\nendobj\n");
            unset($rvb);
        } else {
            $jpeg = tempnam(sys_get_temp_dir(), 'pdfimg');
            $aplat = self::surBlanc($image);
            imagejpeg($aplat, $jpeg, 92);
            if ($aplat !== $image) {
                imagedestroy($aplat);
            }
            $taille = (int) filesize($jpeg);
            $positions[4] = $ecrit;
            $sortir(\sprintf("4 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $l, $h, $taille));
            $src = fopen($jpeg, 'rb');
            $ecrit += (int) stream_copy_to_stream($src, $f);
            fclose($src);
            unlink($jpeg);
            $sortir("\nendstream\nendobj\n");
        }

        $contenu = \sprintf('q %s 0 0 %s 0 0 cm /Im1 Do Q', $W, $H);
        $objet(5, \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($contenu), $contenu));
        $objet(6, \sprintf('<< /Title (%s) /Producer (Succes Imprim - retouche prepresse) /CreationDate (D:%s) >>', self::texte($titre), date('YmdHis')));
        if ($garderAlpha) {
            $positions[7] = $ecrit;
            $sortir(\sprintf("7 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n", $l, $h, \strlen($alpha)));
            $sortir($alpha);
            $sortir("\nendstream\nendobj\n");
        }

        ksort($positions);
        $debutXref = $ecrit;
        $n = max(array_keys($positions)) + 1;
        $xref = "xref\n0 ".$n."\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; ++$i) {
            $xref .= \sprintf("%010d 00000 n \n", $positions[$i] ?? 0);
        }
        $sortir($xref);
        $sortir(\sprintf("trailer\n<< /Size %d /Root 1 0 R /Info 6 0 R >>\nstartxref\n%d\n%%%%EOF\n", $n, $debutXref));
        fclose($f);

        return $garderAlpha;
    }

    /**
     * Lit la taille de la page d'un PDF écrit par ecrire() (contrôle
     * « fichier lisible »), en mm, ou null.
     *
     * @return array{largeurMm: float, hauteurMm: float}|null
     */
    public static function lireTaille(string $chemin): ?array
    {
        $f = @fopen($chemin, 'rb');
        if (false === $f) {
            return null;
        }
        $debut = (string) fread($f, 1024);
        fseek($f, -64, \SEEK_END);
        $fin = (string) fread($f, 64);
        fclose($f);
        if (!str_starts_with($debut, '%PDF-') || !str_contains($fin, '%%EOF') || 1 !== preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $debut, $m)) {
            return null;
        }

        return ['largeurMm' => round((float) $m[1] / self::PT_PAR_MM, 1), 'hauteurMm' => round((float) $m[2] / self::PT_PAR_MM, 1)];
    }

    /** Image posée sur du blanc (sans transparence), ou l'image elle-même si rien n'est transparent à poser. */
    public static function surBlanc(\GdImage $image): \GdImage
    {
        $aplat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($aplat, 0, 0, imagecolorallocate($aplat, 255, 255, 255));
        imagealphablending($aplat, true);
        imagecopy($aplat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $aplat;
    }

    /** @return array{0: string, 1: string} octets RVB et alpha (0 = transparent), ligne par ligne */
    private static function canaux(\GdImage $image): array
    {
        $l = imagesx($image);
        $h = imagesy($image);
        $rvb = '';
        $alpha = '';
        for ($y = 0; $y < $h; ++$y) {
            $ligneRvb = '';
            $ligneA = '';
            for ($x = 0; $x < $l; ++$x) {
                $c = imagecolorat($image, $x, $y);
                $ligneRvb .= \chr(($c >> 16) & 0xFF).\chr(($c >> 8) & 0xFF).\chr($c & 0xFF);
                $ligneA .= \chr((int) round((127 - (($c >> 24) & 0x7F)) * 255 / 127));
            }
            $rvb .= $ligneRvb;
            $alpha .= $ligneA;
        }

        return [$rvb, $alpha];
    }

    private static function pt(float $mm): string
    {
        return rtrim(rtrim(number_format($mm * self::PT_PAR_MM, 3, '.', ''), '0'), '.');
    }

    private static function texte(string $t): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);

        return strtr(false !== $ascii ? $ascii : '', ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }
}
