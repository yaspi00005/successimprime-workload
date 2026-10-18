<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\Pointage;
use App\Entity\ProduitConfiguration;
use App\Entity\RetourPrepresse;
use App\Entity\User;
use App\Repository\PointageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Démonstration du poste machiniste (docs/poste-machiniste.md) : la file
 * de demo.oumar aujourd'hui, une carte dans chaque colonne (OP01) et un
 * travail retourné au prépresse (OP04). Commandes CMD-PM…, relançable.
 * Lancée par app:demo après le chargement.
 */
#[AsCommand(name: 'app:poste-machiniste:demo', description: 'Charge la file du machiniste de démonstration (poste machiniste).')]
final class PosteMachinisteDemoCommand extends Command
{
    public const PREFIXE = 'CMD-PM';

    /** Enlève la démonstration : retours, ordres, contrôles, lignes et commandes CMD-PM…. */
    public const PURGE = [
        "DELETE r FROM retour_prepresse r JOIN ordre_production o ON o.id = r.ordre_id JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE f FROM ordre_production_fichier f JOIN ordre_production o ON o.id = f.ordre_production_id JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE o FROM ordre_production o JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE f FROM controle_pre_presse_fichier f JOIN controle_pre_presse p ON p.id = f.controle_pre_presse_id JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE p FROM controle_pre_presse p JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE f FROM commande_detail_fichier f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE f FROM commande_detail_finition f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE d FROM commandes_details d JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PM%'",
        "DELETE FROM commandes WHERE numero LIKE 'CMD-PM%'",
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PointageRepository $pointages,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève seulement la démonstration du poste machiniste.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $connexion = $this->em->getConnection();
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::PURGE as $sql) {
            $connexion->executeStatement($sql);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        if ($input->getOption('purger')) {
            $io->success('Démonstration du poste machiniste enlevée.');

            return Command::SUCCESS;
        }

        $users = $this->em->getRepository(User::class);
        $oumar = $users->findOneBy(['username' => 'demo.oumar']);
        $seydou = $users->findOneBy(['username' => 'demo.seydou']);
        $grand = $this->em->find(Machines::class, 900001);
        $petit = $this->em->find(Machines::class, 900002);
        if (!$oumar instanceof User || !$grand instanceof Machines || !$petit instanceof Machines) {
            $io->warning('Démonstration absente (demo.oumar, machines 900001 et 900002) : rien à faire.');

            return Command::SUCCESS;
        }

        $maintenant = new \DateTimeImmutable();
        $jour = $maintenant->setTime(0, 0);
        $h = static fn (string $decalage): \DateTimeImmutable => $maintenant->modify($decalage);

        // Une carte par colonne, comme la maquette OP01.
        $travaux = [
            ['n' => '0178', 'client' => 900012, 'config' => 900001, 'designation' => 'Bâche 440 g', 'l' => 4.0, 'h' => 2.0, 'q' => 2, 'finitions' => [900002, 900001], 'promis' => '+2 days', 'livraison' => true,
                'observation' => 'Le rouge du texte « Urgences » doit bien ressortir.', 'image' => 'bache', 'etat' => 'a_verifier', 'machine' => $grand],
            ['n' => '0183', 'client' => 900039, 'config' => 900005, 'designation' => 'Roll-up', 'l' => 0.85, 'h' => 2.0, 'q' => 2, 'finitions' => [], 'promis' => '+3 days', 'livraison' => false, 'etat' => 'a_verifier', 'machine' => null],
            ['n' => '0181', 'client' => 900007, 'config' => 900003, 'designation' => 'Autocollants vitrine', 'l' => 1.5, 'h' => 1.2, 'q' => 1, 'finitions' => [], 'promis' => '+3 days', 'livraison' => false, 'etat' => 'pret', 'machine' => $petit, 'estime' => 35],
            ['n' => '0180', 'client' => 900009, 'config' => 900002, 'designation' => 'Étiquettes + stickers', 'l' => 1.0, 'h' => 1.0, 'q' => 3, 'finitions' => [900006], 'promis' => '+3 days', 'livraison' => false, 'etat' => 'en_cours', 'machine' => $petit, 'estime' => 120, 'depuis' => '-42 minutes'],
            ['n' => '0171', 'client' => 900007, 'config' => 900002, 'designation' => 'Autocollants', 'l' => 1.0, 'h' => 1.0, 'q' => 2, 'finitions' => [], 'promis' => '+1 day', 'livraison' => true, 'etat' => 'termine', 'machine' => $petit, 'estime' => 30, 'depuis' => '-3 hours'],
            ['n' => '0173', 'client' => 900039, 'config' => 900006, 'designation' => 'Kakémonos', 'l' => 0.8, 'h' => 1.8, 'q' => 3, 'finitions' => [], 'promis' => '+2 days', 'livraison' => true, 'etat' => 'pret_livraison', 'machine' => $grand, 'estime' => 70, 'depuis' => '-5 hours', 'colis' => 3],
            ['n' => '0177', 'client' => 900015, 'config' => 900001, 'designation' => 'Banderole', 'l' => 3.0, 'h' => 1.0, 'q' => 1, 'finitions' => [900002], 'promis' => '+1 day', 'livraison' => false, 'etat' => 'pret_livraison', 'machine' => $grand, 'estime' => 40, 'depuis' => '-6 hours', 'colis' => 1],
            ['n' => '0182', 'client' => 900008, 'config' => 900006, 'designation' => 'Kakémonos', 'l' => 0.6, 'h' => 1.6, 'q' => 4, 'finitions' => [], 'promis' => '+4 days', 'livraison' => false, 'image' => 'kakemono', 'etat' => 'retourne', 'machine' => $grand],
        ];

        $n = 0;
        foreach ($travaux as $t) {
            $client = $this->em->find(Clients::class, $t['client']);
            $config = $this->em->find(ProduitConfiguration::class, $t['config']);
            if (!$client instanceof Clients || !$config instanceof ProduitConfiguration) {
                continue;
            }
            $ligne = (new CommandesDetails())
                ->setModeConfiguration('manuel')
                ->setProduitConfiguration($config)
                ->setProduit($config->getProduit())
                ->setTypeImpression($config->getTypeImpression())
                ->setSupport($config->getSupport())
                ->setDesignation($t['designation'])
                ->setModeCalcul('metre_carre')
                ->setQuantite($t['q'])
                ->setPrixUnitaire(6500)
                ->setTva(0)
                ->setLargeur((string) $t['l'])
                ->setLongueur((string) $t['h'])
                ->setMachine($t['machine'])
                ->setObservation($t['observation'] ?? null);
            $ligne->calculerSurface();
            foreach ($t['finitions'] as $idFinition) {
                $finition = $this->em->find(Finition::class, $idFinition);
                if ($finition instanceof Finition) {
                    $ligne->addFinition((new CommandeDetailFinition())->setFinition($finition)->setNomFinition((string) $finition->getNom())->setPrixApplique(1000)->setModeCalcul('unite')->setQuantite($t['q'])->setMontant(1000 * $t['q']));
                }
            }
            $ligne->calculerTotaux(true);
            $commande = (new Commandes())
                ->setNumero(self::PREFIXE.$t['n'])
                ->setClients($client)
                ->setAgents($oumar)
                ->setDateCommande($h('-1 day'))
                ->setDateLivraison($jour->modify($t['promis']))
                ->setStatut(true)
                ->setEtat(true)
                ->setModeLivraison($t['livraison'] ? Commandes::LIVRAISON_DOMICILE : Commandes::LIVRAISON_RETRAIT)
                ->setCreneauLivraison($t['livraison'] ? 'matin' : null);
            $commande->addCommandesDetail($ligne);
            $commande->recalculerTotaux();
            $commande->setTotalPaye($commande->getTotalTtc() ?? 0)->setResteAPayer(0);
            $this->em->persist($commande);

            $fichier = isset($t['image']) ? $this->image($t['image'], $t['n'], $ligne, $seydou, $h('-20 hours')) : null;
            $controle = (new ControlePrePresse())
                ->setCommandeDetail($ligne)
                ->setFichierDejaTraite(true)
                ->setDimensionsConformes(true)
                ->setMargesSecuriteConformes(true)
                ->setResolutionConforme(true)
                ->setQuantiteConforme(true)
                ->setObservation($t['etat'] === 'a_verifier' && isset($t['observation']) ? 'Numéro décalé de 4 cm vers l\'intérieur. Fichier prêt.' : null);
            if ($fichier !== null) {
                $this->em->persist($fichier);
                $controle->addFichier($fichier);
            }
            if ($seydou instanceof User) {
                $controle->enregistrerTraitement($seydou);
            }
            $controle->setStatut(ControlePrePresse::STATUT_ENVOYE_PRODUCTION);
            $ligne->addControlePrePresse($controle);
            $this->em->persist($controle);

            $ordre = (new OrdreProduction())
                ->setCommandeDetail($ligne)
                ->setControlePrePresse($controle)
                ->setCreePar($seydou)
                ->setQuantite($t['q'])
                ->setMachine($t['machine'])
                ->setDateLimite($jour->modify($t['promis']));
            if ($fichier !== null) {
                $ordre->addFichier($fichier);
            }
            $ordre->transmettre($h('-18 hours'));
            $ligne->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
            $this->em->persist($ordre);

            if (\in_array($t['etat'], ['pret', 'en_cours', 'termine', 'pret_livraison'], true)) {
                $debut = isset($t['depuis']) ? $h($t['depuis']) : $h('+40 minutes');
                $ordre->verifierFichier($oumar, ['dimensions' => true, 'securite' => true, 'resolution' => true, 'matiere' => true], min($debut->modify('-15 minutes'), $h('-10 minutes')));
                $ordre->estimer([['cle' => 'impression', 'libelle' => 'Impression', 'propose' => $t['estime'], 'estime' => $t['estime']]], null, $debut, $t['machine']);
            }
            if (\in_array($t['etat'], ['en_cours', 'termine', 'pret_livraison'], true)) {
                $ligne->demarrerProduction($oumar, $t['machine']);
                $ordre->demarrer($oumar, $t['machine']);
            }
            if (\in_array($t['etat'], ['termine', 'pret_livraison'], true)) {
                $ligne->terminerProduction($oumar, $t['q'], 0, null);
                $ordre->terminer($oumar, $t['q'], 0);
            }
            if ($t['etat'] === 'pret_livraison') {
                $ordre->etiqueterColis($t['colis'] ?? 1);
                $ordre->affecterEtiquette(\sprintf('ETQ-PM-%s', $t['n']), $oumar);
                $ordre->marquerPretLivraison($oumar);
                $ligne->marquerPreteLivraison(true);
            }
            $this->em->flush();

            // Heures de la maquette : démarré, terminé, étiqueté.
            if (isset($t['depuis'])) {
                $debut = $h($t['depuis']);
                $fin = $debut->modify('+'.max(1, (int) round($t['estime'] * 0.95)).' minutes');
                $connexion->executeStatement('UPDATE ordre_production SET demarre_le = ?, termine_le = IF(statut = ?, ?, NULL), etiquettes_imprimees_le = IF(etiquettes_imprimees_le IS NULL, NULL, ?), pret_livraison_le = IF(pret_livraison_le IS NULL, NULL, ?) WHERE id = ?', [
                    $debut->format('Y-m-d H:i:s'), OrdreProduction::STATUT_TERMINE, $fin->format('Y-m-d H:i:s'), $fin->modify('+10 minutes')->format('Y-m-d H:i:s'), $fin->modify('+12 minutes')->format('Y-m-d H:i:s'), $ordre->getId(),
                ]);
            }
            if ($t['etat'] === 'retourne') {
                $retour = new RetourPrepresse($ordre, $oumar, $seydou, ['fond_perdu', 'texte_bord'], 'Pas de fond perdu (fichier 600 × 1 600 pile). Le titre est à 3 mm du bord : il sera coupé. Ajouter 10 mm de fond perdu et décaler le titre.', [
                    ['type' => 'cercle', 'x1' => 0.08, 'y1' => 0.03, 'x2' => 0.92, 'y2' => 0.17],
                    ['type' => 'texte', 'x1' => 0.55, 'y1' => 0.02, 'x2' => 0.55, 'y2' => 0.02, 'texte' => 'texte à 3 mm du bord'],
                ], $h('-30 minutes'));
                $this->em->persist($retour);
                $controle->setCorrectionNecessaire(true)->setAnomalies('Retour du machiniste (Oumar Sangaré) : fond perdu manquant, texte trop près du bord.')->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE);
                $this->em->flush();
            }
            ++$n;
        }

        // Oumar est pointé à l'atelier ce matin : il peut démarrer et terminer depuis son téléphone.
        $dernier = $this->pointages->dernier($oumar);
        if ($dernier === null || $dernier->getArriveeLe() < $jour) {
            $this->em->persist(new Pointage($oumar, $maintenant > $jour->setTime(7, 52) ? $jour->setTime(7, 52) : $h('-5 minutes')));
            $this->em->flush();
        }

        $io->success(\sprintf('Poste machiniste : %d travaux dans la file de demo.oumar (commandes %s…).', $n, self::PREFIXE));

        return Command::SUCCESS;
    }

    /** Fichier d'impression fictif (image dessinée), rangé comme un fichier de commande. */
    private function image(string $modele, string $numero, CommandesDetails $ligne, ?User $par, \DateTimeImmutable $le): ?CommandeDetailFichier
    {
        if (!\function_exists('imagecreatetruecolor')) {
            return null;
        }
        $police = $this->projet.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        $nom = \sprintf('demo-pm-%s.png', $numero);
        $relatif = 'uploads/prepresse/demo-poste-machiniste/'.$nom;
        $dossier = $this->projet.'/var/uploads/prepresse/demo-poste-machiniste';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        if ($modele === 'bache') {
            $im = imagecreatetruecolor(1200, 600);
            $blanc = imagecolorallocate($im, 255, 255, 255);
            $bleu = imagecolorallocate($im, 37, 137, 214);
            $nuit = imagecolorallocate($im, 15, 69, 144);
            $rouge = imagecolorallocate($im, 222, 16, 40);
            imagefilledrectangle($im, 0, 0, 1200, 600, $blanc);
            imagefilledpolygon($im, [780, 0, 1200, 0, 1200, 600, 520, 600], $bleu);
            imagefilledellipse($im, 230, 300, 300, 300, $bleu);
            imagefilledrectangle($im, 200, 200, 260, 400, $blanc);
            imagefilledrectangle($im, 130, 270, 330, 330, $blanc);
            if (is_file($police)) {
                imagettftext($im, 46, 0, 430, 250, $nuit, $police, 'CLINIQUE');
                imagettftext($im, 46, 0, 430, 315, $nuit, $police, 'KAYES');
                imagettftext($im, 22, 0, 432, 360, $rouge, $police, 'URGENCES 24 h / 24');
                imagettftext($im, 18, 0, 880, 520, $blanc, $police, '+223 60 00 00 00');
                imagettftext($im, 18, 0, 900, 550, $blanc, $police, 'Bamako Coura');
            }
        } else {
            $im = imagecreatetruecolor(300, 800);
            $haut = imagecolorallocate($im, 214, 158, 18);
            $bas = imagecolorallocate($im, 160, 104, 8);
            imagefilledrectangle($im, 0, 0, 300, 800, $haut);
            imagefilledrectangle($im, 0, 420, 300, 800, $bas);
            imagefilledellipse($im, 150, 330, 160, 160, imagecolorallocatealpha($im, 255, 255, 255, 80));
            if (is_file($police)) {
                $blanc = imagecolorallocate($im, 255, 255, 255);
                imagettftext($im, 24, 0, 6, 40, $blanc, $police, 'ÉCOLE');
                imagettftext($im, 24, 0, 6, 72, $blanc, $police, 'MISSABOUGOU');
                imagettftext($im, 12, 0, 70, 760, $blanc, $police, 'Inscriptions ouvertes');
            }
        }
        imagepng($im, $dossier.'/'.$nom);
        imagedestroy($im);

        return (new CommandeDetailFichier())
            ->setCommandeDetail($ligne)
            ->setNomOriginal($modele === 'bache' ? 'bache-clinique_4000x2000_v2.png' : 'kakemono-ecole_600x1600_v1.png')
            ->setJetonUpload(bin2hex(random_bytes(16)))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setNomStockage($nom)
            ->setChemin($relatif)
            ->setTypeMime('image/png')
            ->setTaille((int) filesize($dossier.'/'.$nom))
            ->setStatut(CommandeDetailFichier::STATUT_TERMINE)
            ->setTermineLe($le)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_PRET_IMPRESSION)
            ->setDejaTraite(true)
            ->setFichierProduction(true)
            ->setAjoutePar($par)
            ->setCreeLe($le);
    }
}
