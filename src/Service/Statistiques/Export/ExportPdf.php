<?php

namespace App\Service\Statistiques\Export;

use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Service\DocumentsEntreprise;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * PDF A4 d'un export de statistiques ou du rapport de la semaine, avec
 * l'en-tête de l'entreprise principale (code mdg_success, Réglages ›
 * Entreprises) et la période.
 */
final class ExportPdf
{
    public const CODE_ENTREPRISE = Factures::EMETTEUR_MDG_SUCCESS;

    public function __construct(
        private readonly Environment $twig,
        private readonly DocumentsEntreprise $documents,
    ) {
    }

    public function entreprise(): Entreprise
    {
        return $this->documents->entreprise(self::CODE_ENTREPRISE, Factures::EMETTEURS_LABELS[self::CODE_ENTREPRISE]);
    }

    /** Le PDF d'un export : tableaux à la suite, en paysage au-delà de 6 colonnes. */
    public function export(Export $export, \DateTimeImmutable $editeLe, string $editePar): string
    {
        $large = [] !== array_filter($export->tableaux, static fn (Tableau $t): bool => \count($t->colonnes) > 6);

        return $this->rendre('statistiques/export/pdf.html.twig', ['export' => $export], $editeLe, $editePar, $large);
    }

    /**
     * Un PDF depuis un modèle qui étend statistiques/export/_page_pdf.html.twig.
     *
     * @param array<string, mixed> $variables
     */
    public function rendre(string $modele, array $variables, \DateTimeImmutable $editeLe, string $editePar, bool $paysage = false): string
    {
        $entreprise = $this->entreprise();
        $html = $this->twig->render($modele, $variables + [
            'entreprise' => $entreprise,
            'images' => $this->documents->images($entreprise),
            'edite_le' => $editeLe,
            'edite_par' => $editePar,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $paysage ? 'landscape' : 'portrait');
        $dompdf->render();

        // « Page 1 / 3 » à droite du pied de page (le total n'est connu qu'ici).
        $canvas = $dompdf->getCanvas();
        $metriques = $dompdf->getFontMetrics();
        $police = $metriques->getFont('DejaVu Sans');
        if (null !== $police) {
            $taille = 5.1;
            $largeur = $metriques->getTextWidth('Page 00 / 00', $police, $taille);
            $canvas->page_text($canvas->get_width() - 39.7 - $largeur, $canvas->get_height() - 28.5, 'Page {PAGE_NUM} / {PAGE_COUNT}', $police, $taille, [0.42, 0.45, 0.5]);
        }

        return (string) $dompdf->output();
    }

    public static function reponse(string $pdf, string $nomFichier): Response
    {
        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $nomFichier),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
