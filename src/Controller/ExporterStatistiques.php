<?php

namespace App\Controller;

use App\Entity\JournalActivite;
use App\Entity\User;
use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\Export\Export;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Objectifs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu « Exporter » des écrans de statistiques : le fichier reprend les
 * données que l'écran affiche, calculées par la même méthode du
 * contrôleur avec les mêmes droits. Chaque export est noté dans le
 * journal d'activité (qui, quel écran, quelle période, quel format).
 */
trait ExporterStatistiques
{
    /**
     * @return array{global: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, perso: bool, admin: bool}
     */
    private function droitsStatistiques(): array
    {
        return DroitsStatistiques::calculer(fn (string $role): bool => $this->isGranted($role));
    }

    private function reponseExport(Export $export, string $format, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $maintenant = new \DateTimeImmutable();
        $utilisateur = $this->getUser();
        $par = $utilisateur instanceof User ? Objectifs::nomAgent($utilisateur) : '';
        $this->journaliserExport($em, $export->titre, $export->periode, $format, array_map(static fn ($t): string => $t->titre, $export->tableaux));

        return 'pdf' === $format
            ? ExportPdf::reponse($pdf->export($export, $maintenant, $par), $export->nomFichier('pdf'))
            : $excel->reponse($export, $pdf->entreprise()->getNomAffiche(), $maintenant, $par);
    }

    /**
     * @param list<string> $tableaux
     */
    private function journaliserExport(EntityManagerInterface $em, string $ecran, string $periode, string $format, array $tableaux): void
    {
        $utilisateur = $this->getUser();
        $em->persist((new JournalActivite())
            ->setEntite('Statistiques')
            ->setAction('export')
            ->setDonneesApres(['ecran' => $ecran, 'periode' => $periode, 'format' => $format, 'tableaux' => $tableaux])
            ->setUtilisateur($utilisateur instanceof User ? $utilisateur : null));
        $em->flush();
    }
}
