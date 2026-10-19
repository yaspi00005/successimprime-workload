<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\FichierDossier;
use App\Entity\User;
use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\PublicationFichiers;
use App\Service\DossiersClients\ReglagesDossiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dossiers clients de démonstration (docs/dossiers-clients.md) : un faux
 * serveur de l'atelier sous var/dossiers-demo, avec
 *
 * - « DEMO-0002 Salon Espoir » : lié tout seul (code dans le nom), des
 *   fichiers dans chaque sous-dossier (AI en trois versions, PSD, INDD,
 *   BAT, photos de pose, devis) ;
 * - « PHARMA DJOLIBA », « AWA MODE - Awa Diarra », « Ecole Missabougou
 *   2025 », « Hotel Lumiere » : noms proches, proposés à valider ;
 * - « ANCIEN CLIENT Kouyate » : dossier sans client ;
 * - un dossier « À classer » (WhatsApp, scanner, téléchargements, bureau).
 *
 * Les réglages « dossier racine » et « À classer » sont remplis s'ils sont
 * vides, puis l'analyse complète tourne. Identifiants à partir de 900001
 * (recette des accès). Refusée en production et sur le site en ligne.
 * --purger enlève les lignes et le dossier de démonstration.
 */
#[AsCommand(name: 'app:dossiers-clients:demo', description: 'Faux dossiers clients sous var/dossiers-demo (liés, proposés, à classer), puis analyse.')]
final class DossiersClientsDemoCommand extends Command
{
    public const PURGE = [
        'DELETE FROM fichier_publie WHERE id >= 900000 OR client_id >= 900000',
        'DELETE FROM fichier_dossier WHERE id >= 900000 OR client_id >= 900000',
        'DELETE FROM fichier_a_classer WHERE id >= 900000',
        'DELETE FROM lien_dossier_client WHERE id >= 900000 OR client_id >= 900000',
    ];

    private const TABLES = ['lien_dossier_client', 'fichier_dossier', 'fichier_a_classer', 'fichier_publie'];

    private string $police = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connexion,
        private readonly ReglagesDossiers $reglages,
        private readonly AnalyseDossiers $analyse,
        private readonly PublicationFichiers $publications,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève la démonstration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement || $this->reglages->enLigne()) {
            $io->error('Pas de dossiers de démonstration en production ni sur le site en ligne.');

            return Command::FAILURE;
        }
        $racineDemo = $this->projet.'/var/dossiers-demo';
        foreach (self::PURGE as $sql) {
            $this->connexion->executeStatement($sql);
        }
        if (is_dir($racineDemo)) {
            self::effacer($racineDemo);
        }
        if ($input->getOption('purger')) {
            if (str_starts_with((string) $this->reglages->valeur('dossier_racine'), $racineDemo)) {
                $this->reglages->enregistrer(['dossier_racine' => '', 'dossier_a_classer' => '', 'racine_postes' => ''], null);
            }
            $io->success('Dossiers clients de démonstration enlevés.');

            return Command::SUCCESS;
        }
        $salon = $this->em->getRepository(Clients::class)->findOneBy(['code' => 'DEMO-0002']);
        if (null === $salon) {
            $io->warning('Clients de démonstration absents : lancez d\'abord app:demo.');

            return Command::SUCCESS;
        }
        $this->police = is_file($this->projet.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf') ? $this->projet.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf' : '';

        // Les lignes de la démo prennent des identifiants >= 900001 (recette des accès, purge).
        foreach (self::TABLES as $table) {
            if ((int) $this->connexion->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$table) < 900000) {
                $this->connexion->executeStatement('ALTER TABLE '.$table.' AUTO_INCREMENT = 900001');
            }
        }

        $clients = $racineDemo.'/Clients';
        $aClasser = $racineDemo.'/A classer';
        $this->ecrireSalonEspoir($clients.'/DEMO-0002 Salon Espoir', $salon);
        $this->ecrireAutres($clients);
        $this->ecrireAClasser($aClasser, $salon);

        if ('' === (string) $this->reglages->valeur('dossier_racine')) {
            $this->reglages->enregistrer(['dossier_racine' => $clients, 'dossier_a_classer' => $aClasser, 'racine_postes' => '\\\\SERVEUR\\Clients'], null);
        }
        if (realpath((string) $this->reglages->valeur('dossier_racine')) !== realpath($clients)) {
            $io->note('Le dossier racine est déjà réglé ailleurs ('.$this->reglages->valeur('dossier_racine').') : la démo est écrite dans '.$clients.' sans changer le réglage.');

            return Command::SUCCESS;
        }
        $bilan = $this->analyse->analyser(true, null, new \DateTimeImmutable());

        // Le kakémono et ses BAT liés à la commande de conception du client (« Lier à une commande »).
        $salon = $this->em->getRepository(Clients::class)->findOneBy(['code' => 'DEMO-0002']);
        $commande = null === $salon ? null : $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'd')->where('c.clients = :c AND c.deleted = false AND d.designation LIKE :k')
            ->setParameter('c', $salon)->setParameter('k', '%akémono%')->orderBy('c.dateCommande', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $graphiste = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.seydou']);
        foreach (null === $salon ? [] : $this->em->getRepository(FichierDossier::class)->findBy(['client' => $salon]) as $f) {
            if ($commande instanceof Commandes && str_contains($f->getNom(), 'kakemono')) {
                $f->lierCommande($commande, $graphiste, new \DateTimeImmutable());
            }
            if ($graphiste instanceof User && \in_array($f->getSousDossier(), ['conceptions', 'bat', 'prets'], true)) {
                $f->setAjoutePar(\App\Service\Pointage\Pointages::nom($graphiste));
            }
        }
        $this->em->flush();

        // Deux fichiers publiés pour Salon Espoir (espace client, onglet Fichiers).
        $direction = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.kadiatou']);
        $salon = $this->em->getRepository(Clients::class)->findOneBy(['code' => 'DEMO-0002']);
        if ($direction instanceof User && null !== $salon) {
            foreach ($this->em->getRepository(FichierDossier::class)->findBy(['client' => $salon, 'sousDossier' => 'bat']) as $f) {
                try {
                    $this->publications->publier($f, 'bat', false, $direction);
                } catch (\DomainException) {
                }
            }
        }

        $io->success(\sprintf('Dossiers de démonstration dans %s : %d dossiers, %d liés, %d suggestions, %d fichiers lus, %d à classer.', $racineDemo, $bilan['dossiers'], $bilan['lies'], $bilan['suggeres'], $bilan['fichiers'], $bilan['a_classer']));

        return Command::SUCCESS;
    }

    private function ecrireSalonEspoir(string $d, Clients $client): void
    {
        $jours = static fn (int $n, string $h = '10:00'): int => (new \DateTimeImmutable('today '.$h))->modify('-'.$n.' days')->getTimestamp();
        $kakemono = fn (string $texte, string $fond): string => $this->image(400, 940, $fond, ['SOIRÉE', 'JAZZ', $texte], '#FACC15');
        // 01 Conceptions : trois versions du kakémono, la façade, le menu, un PSD et un INDD.
        $this->ecrire($d.'/01 Conceptions/kakemono-soiree-jazz_v1.ai', $this->ai(85, 200, $kakemono('v1', '#1E293B')), $jours(5));
        $this->ecrire($d.'/01 Conceptions/kakemono-soiree-jazz_v2.ai', $this->ai(85, 200, $kakemono('v2', '#172554')), $jours(4));
        $this->ecrire($d.'/01 Conceptions/kakemono-soiree-jazz_v3.ai', $this->ai(85, 200, $kakemono('19 OCT.', '#0F172A')), $jours(3, '09:32'));
        $this->ecrire($d.'/01 Conceptions/signaletique-hall.ai', $this->ai(120, 40, $this->image(900, 300, '#0F4590', ['SALON ESPOIR'], '#FFFFFF')), $jours(6));
        $this->ecrire($d.'/01 Conceptions/carte-direction_recto.ai', $this->ai(8.5, 5.5, $this->image(850, 550, '#111827', ['O. GUINDO', 'Directeur'], '#FACC15')), $jours(40));
        $this->ecrire($d.'/01 Conceptions/bache-facade_2026.psd', $this->psd($this->image(1200, 400, '#0F4590', ['SALON ESPOIR', 'coiffure · beauté'], '#FFFFFF'), 3000, 1000), $jours(2));
        $this->ecrire($d.'/01 Conceptions/roll-up-accueil.psd', $this->psd($this->image(300, 700, '#1D4ED8', ['BIENVENUE'], '#FDE68A'), 850, 2000), $jours(9));
        $this->ecrire($d.'/01 Conceptions/menu-A4_RV.indd', "\x06\x06\xED\xF5\xD8\x1D\x46\xE5\xBD\x31\xEF\xE7\xFE\x74\xB7\x1D".random_bytes(2048), $jours(30));
        $this->ecrire($d.'/01 Conceptions/logo-salon-espoir.ai', $this->ai(10, 10, $this->image(500, 500, '#FFFFFF', ['SE'], '#0F4590')), $jours(400));
        // 02 Fichiers reçus : ce que le client a envoyé.
        $this->ecrire($d.'/02 Fichiers reçus/logo client HD.png', $this->image(800, 800, '#FFFFFF', ['SE', 'logo'], '#BE185D', 'png'), $jours(45));
        $this->ecrire($d.'/02 Fichiers reçus/photo-equipe.jpg', $this->image(1200, 800, '#78350F', ['photo équipe'], '#FDE68A'), $jours(20));
        $this->ecrire($d.'/02 Fichiers reçus/textes menu.pdf', $this->pdf('Textes du menu', ['Coupe homme 2 000 F', 'Tresses 5 000 F', 'Soin visage 7 500 F']), $jours(31));
        // 03 BAT envoyés.
        $this->ecrire($d.'/03 BAT envoyés/BAT_kakemono-soiree-jazz_n1.pdf', $this->pdf('BAT n°1 · kakémono 85 × 200 cm', ['Version v2', 'À valider par le client'], $kakemono('v2', '#172554')), $jours(4, '16:00'));
        $this->ecrire($d.'/03 BAT envoyés/BAT_kakemono-soiree-jazz_n2.pdf', $this->pdf('BAT n°2 · kakémono 85 × 200 cm', ['Version v3', 'Validé le '.date('d/m', $jours(2))], $kakemono('19 OCT.', '#0F172A')), $jours(3, '11:00'));
        // 04 Prêts à imprimer.
        $this->ecrire($d.'/04 Prêts à imprimer/kakemono-soiree-jazz_FINAL.pdf', $this->pdf('kakémono 85 × 200 cm · FINAL', ['CMJN · fonds perdus 5 mm'], $kakemono('19 OCT.', '#0F172A')), $jours(2, '15:20'));
        $this->ecrire($d.'/04 Prêts à imprimer/bache-facade_300x100_HD.pdf', $this->pdf('Bâche façade 300 × 100 cm', ['œillets tous les 50 cm'], $this->image(1200, 400, '#0F4590', ['SALON ESPOIR'], '#FFFFFF')), $jours(1));
        // 05 Photos de pose.
        $this->ecrire($d.'/05 Photos de pose/pose-facade_2026-10-10.jpg', $this->image(1200, 900, '#64748B', ['pose façade'], '#FFFFFF'), $jours(9));
        $this->ecrire($d.'/05 Photos de pose/pose-hall.jpg', $this->image(900, 1200, '#475569', ['pose hall'], '#FFFFFF'), $jours(8));
        // 06 Documents.
        $this->ecrire($d.'/06 Documents/devis-signe.pdf', $this->pdf('Devis signé', ['Salon Espoir', 'Bon pour accord']), $jours(12));
        $this->ecrire($d.'/06 Documents/charte-couleurs.pdf', $this->pdf('Charte du client', ['Bleu #0F4590', 'Jaune #FACC15']), $jours(200));
        // Un dossier hors modèle (« Autres dossiers », visible par qui voit tout).
        $this->ecrire($d.'/Archives 2024/ancien-logo.jpg', $this->image(500, 500, '#E5E7EB', ['ancien logo'], '#334155'), $jours(500));
    }

    private function ecrireAutres(string $racine): void
    {
        $vieux = (new \DateTimeImmutable('-30 days'))->getTimestamp();
        $dossiers = [
            'PHARMA DJOLIBA' => ['Conceptions/enseigne-croix-verte.ai' => ['ai', '#16A34A', 'PHARMACIE'], 'Photos/facade.jpg' => ['jpg', '#065F46', 'façade']],
            'AWA MODE - Awa Diarra' => ['Conceptions/etiquette-prix.ai' => ['ai', '#DB2777', 'AWA MODE'], 'Fichiers reçus/logo.png' => ['png', '#FFFFFF', 'AWA']],
            'Ecole Missabougou 2025' => ['BAT/BAT_diplomes.pdf' => ['pdf', '#F59E0B', 'Diplômes'], 'Conceptions/banderole-fete.ai' => ['ai', '#B45309', 'FÊTE DE L\'ÉCOLE']],
            'Hotel Lumiere' => ['Conceptions/plaques-chambres.ai' => ['ai', '#0F172A', 'CHAMBRE 12'], 'Prêts à imprimer/plaques_FINAL.pdf' => ['pdf', '#0F172A', 'Plaques']],
            'ANCIEN CLIENT Kouyate' => ['divers/carte-2019.jpg' => ['jpg', '#9CA3AF', '2019']],
        ];
        foreach ($dossiers as $dossier => $fichiers) {
            foreach ($fichiers as $nom => [$type, $fond, $texte]) {
                $img = $this->image(600, 400, $fond, [$texte], '#FFFFFF' === $fond ? '#BE185D' : '#FFFFFF', 'png' === $type ? 'png' : 'jpg');
                $contenu = match ($type) {
                    'ai' => $this->ai(60, 40, $img),
                    'pdf' => $this->pdf($texte, [$dossier], $img),
                    default => $img,
                };
                $this->ecrire($racine.'/'.$dossier.'/'.$nom, $contenu, $vieux);
            }
        }
    }

    private function ecrireAClasser(string $d, Clients $salon): void
    {
        $maintenant = time();
        $commande = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.clients = :c AND c.deleted = false')->setParameter('c', $salon)
            ->orderBy('c.dateCommande', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $numero = $commande instanceof Commandes ? (string) $commande->getNumero() : 'DEMO-0002';
        $this->ecrire($d.'/WhatsApp Images/'.$numero.'/IMG-20261019-WA0012.jpg', $this->image(1200, 900, '#64748B', ['photo façade'], '#FFFFFF'), $maintenant - 3600);
        $this->ecrire($d.'/Bureau/logo pharmacie djoliba HD.png', $this->image(800, 800, '#FFFFFF', ['+', 'Djoliba'], '#16A34A', 'png'), $maintenant - 7200);
        $this->ecrire($d.'/Téléchargements/etiquette prix v2 FINAL Awa Diarra.pdf', $this->pdf('Étiquette prix', ['Awa Mode'], $this->image(600, 300, '#DB2777', ['AWA MODE'], '#FFFFFF')), $maintenant - 10800);
        $this->ecrire($d.'/Scanner/scan devis signé DEMO-0020.pdf', $this->pdf('Devis signé', ['Hôtel Lumière']), $maintenant - 20000);
        $this->ecrire($d.'/Bureau/Sans titre-1.psd', $this->psd($this->image(600, 600, '#E2E8F0', ['?'], '#334155'), 2000, 2000), $maintenant - 30 * 3600);
    }

    /* ---------- Fabrication des fichiers (légers) ---------- */

    private function ecrire(string $chemin, string $contenu, int $date): void
    {
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0775, true);
        }
        file_put_contents($chemin, $contenu);
        touch($chemin, $date);
    }

    /** @param list<string> $lignes */
    private function image(int $l, int $h, string $fond, array $lignes, string $encre, string $format = 'jpg'): string
    {
        $im = imagecreatetruecolor($l, $h);
        $c = static fn (string $hex): int => (int) imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        imagefilledrectangle($im, 0, 0, $l, $h, $c($fond));
        // Un bandeau et un rond, pour que la miniature ressemble à un visuel.
        imagefilledrectangle($im, 0, (int) ($h * .82), $l, $h, $c($encre));
        imagefilledellipse($im, (int) ($l * .78), (int) ($h * .16), (int) (min($l, $h) * .22), (int) (min($l, $h) * .22), $c($encre));
        $taille = max(14, (int) (min($l, $h) / (max(4, max(array_map('mb_strlen', $lignes)) * .75))));
        if ('' !== $this->police) {
            // Le texte le plus long tient dans 86 % de la largeur.
            foreach ($lignes as $texte) {
                while ($taille > 10 && ($b = imagettfbbox($taille, 0, $this->police, $texte)) && ($b[2] - $b[0]) > $l * .86) {
                    $taille -= 2;
                }
            }
        }
        $y = (int) ($h * .38);
        foreach ($lignes as $texte) {
            if ('' !== $this->police) {
                $boite = imagettfbbox($taille, 0, $this->police, $texte);
                imagettftext($im, $taille, 0, (int) (($l - ($boite[2] - $boite[0])) / 2), $y, $c($encre), $this->police, $texte);
            } else {
                imagestring($im, 5, 10, $y, $texte, $c($encre));
            }
            $y += (int) ($taille * 1.5);
        }
        ob_start();
        'png' === $format ? imagepng($im) : imagejpeg($im, null, 82);

        return (string) ob_get_clean();
    }

    /** Petit JPEG pour l'aperçu rangé dans un AI, PDF ou PSD. */
    private static function vignette(string $image, int $max = 256): string
    {
        $src = imagecreatefromstring($image);
        if (false === $src) {
            return $image;
        }
        $r = $max / max(imagesx($src), imagesy($src));
        $dst = imagescale($src, max(1, (int) (imagesx($src) * $r)), max(1, (int) (imagesy($src) * $r)));
        ob_start();
        imagejpeg(false !== $dst ? $dst : $src, null, 80);

        return (string) ob_get_clean();
    }

    private static function xmp(string $jpeg): string
    {
        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:xmpGImg="http://ns.adobe.com/xap/1.0/g/img/"><xmp:CreatorTool>Démonstration Succès Imprim</xmp:CreatorTool>'
            .'<xmp:Thumbnails><rdf:Alt><rdf:li rdf:parseType="Resource"><xmpGImg:format>JPEG</xmpGImg:format><xmpGImg:image>'.chunk_split(base64_encode($jpeg), 76, '&#xA;').'</xmpGImg:image></rdf:li></rdf:Alt></xmp:Thumbnails>'
            .'</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /** « AI » compatible PDF : une page au format demandé (cm) et l'aperçu XMP, comme Illustrator. */
    private function ai(float $largeurCm, float $hauteurCm, string $image): string
    {
        return $this->pdfBrut($largeurCm * 72 / 2.54, $hauteurCm * 72 / 2.54, "BT /F1 24 Tf 20 20 Td (Illustrator) Tj ET\n", self::vignette($image), '%AI24_ArtworkFormat'."\n");
    }

    /** @param list<string> $lignes */
    private function pdf(string $titre, array $lignes, ?string $image = null): string
    {
        $flux = 'BT /F1 18 Tf 50 780 Td ('.self::texte($titre).') Tj ET'."\n";
        $y = 750;
        foreach ($lignes as $l) {
            $flux .= 'BT /F1 12 Tf 50 '.$y.' Td ('.self::texte($l).') Tj ET'."\n";
            $y -= 20;
        }

        return $this->pdfBrut(595, 842, $flux, null !== $image ? self::vignette($image) : null);
    }

    private static function texte(string $t): string
    {
        $t = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $t);

        return strtr($t, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function pdfBrut(float $largeur, float $hauteur, string $flux, ?string $vignette, string $entete = ''): string
    {
        $objets = [
            '<< /Type /Catalog /Pages 2 0 R'.(null !== $vignette ? ' /Metadata 6 0 R' : '').' >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            \sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>', $largeur, $hauteur),
            '<< /Length '.\strlen($flux)." >>\nstream\n".$flux."endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        if (null !== $vignette) {
            $x = self::xmp($vignette);
            $objets[] = '<< /Type /Metadata /Subtype /XML /Length '.\strlen($x)." >>\nstream\n".$x."\nendstream";
        }
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n".$entete;
        $positions = [];
        foreach ($objets as $i => $o) {
            $positions[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$o."\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 ".(\count($objets) + 1)."\n0000000000 65535 f \n";
        foreach ($positions as $p) {
            $pdf .= \sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer << /Size '.(\count($objets) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** PSD minimal : en-tête, aperçu JPEG (ressource 1036), image vide. */
    private function psd(string $image, int $largeur, int $hauteur): string
    {
        $jpeg = self::vignette($image, 160);
        $src = imagecreatefromstring($jpeg);
        $l = false !== $src ? imagesx($src) : 160;
        $h = false !== $src ? imagesy($src) : 160;
        $donnees = pack('NNNNNNnn', 1, $l, $h, (int) (ceil($l * 24 / 32) * 4), (int) (ceil($l * 24 / 32) * 4) * $h, \strlen($jpeg), 24, 1).$jpeg;
        $ressource = '8BIM'.pack('n', 1036)."\x00\x00".pack('N', \strlen($donnees)).$donnees.(\strlen($donnees) % 2 ? "\x00" : '');

        return '8BPS'.pack('n', 1).str_repeat("\x00", 6).pack('nNNnn', 3, $hauteur, $largeur, 8, 3)
            .pack('N', 0)
            .pack('N', \strlen($ressource)).$ressource
            .pack('N', 0)
            .pack('n', 0);
    }

    private static function effacer(string $dossier): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dossier);
    }
}
