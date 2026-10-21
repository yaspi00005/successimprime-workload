<?php

namespace App\Command;

use App\Entity\Articles;
use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\ProduitConfiguration;
use App\Entity\Supports;
use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Service\AnalyseFichierImpression;
use App\Service\Atelier\AtelierProduction;
use App\Service\Production\EnvoiRip;
use App\Service\Production\FichierProduction;
use App\Service\Production\TiragesRouleau;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Démonstration de la production au rouleau (docs/production-rouleau.md,
 * maquettes PD01 à PD03) : dossiers et profils des machines, un traceur de
 * découpe, la bâche 440 g en laize 3,20 m, trois commandes (Koita,
 * Clinique Pasteur, Awa Diarra) regroupées sur le rouleau R-MMJJ-03 déjà
 * envoyé au RIP, le jaune à 18 % qui bloque « Démarrer », et une bâche
 * 510 g qui attend un autre rouleau. Commandes CMD-PR…, clients DEMO-PR…,
 * relançable. Lancée par app:demo après le poste machiniste.
 */
#[AsCommand(name: 'app:production-rouleau:demo', description: 'Charge la démonstration de la production au rouleau (commandes CMD-PR…).')]
final class ProductionRouleauDemoCommand extends Command
{
    public const PREFIXE = 'CMD-PR';
    public const ARTICLE = 'Bâche 440 g frontlit · laize 3,20 m';
    public const TRACEUR = 'Traceur découpe 1,30 m';

    /** Enlève la démonstration : rouleaux des machines fictives, commandes CMD-PR…. */
    public const PURGE = [
        'DELETE p FROM piece_tirage p JOIN tirage_rouleau t ON t.id = p.tirage_id WHERE t.machine_id >= 900000',
        'UPDATE ordre_production o JOIN tirage_rouleau t ON t.id = o.tirage_id SET o.tirage_id = NULL WHERE t.machine_id >= 900000',
        'DELETE FROM tirage_rouleau WHERE machine_id >= 900000',
        "DELETE f FROM ordre_production_fichier f JOIN ordre_production o ON o.id = f.ordre_production_id JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE o FROM ordre_production o JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE f FROM controle_pre_presse_fichier f JOIN controle_pre_presse p ON p.id = f.controle_pre_presse_id JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE p FROM controle_pre_presse p JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE f FROM commande_detail_fichier f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE f FROM commande_detail_finition f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE d FROM commandes_details d JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PR%'",
        "DELETE FROM commandes WHERE numero LIKE 'CMD-PR%'",
    ];

    /** Tables remplies ici : identifiants de démonstration à partir de 900001. */
    private const TABLES = ['clients', 'commandes', 'commandes_details', 'commande_detail_finition', 'commande_detail_fichier', 'controle_pre_presse', 'ordre_production', 'tirage_rouleau', 'piece_tirage', 'machines', 'articles', 'journal_machine'];

    private const DOSSIER_IMAGES = 'var/uploads/prepresse/demo-production-rouleau';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TiragesRouleau $tirages,
        private readonly EnvoiRip $rip,
        private readonly AtelierProduction $atelier,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève seulement la démonstration de la production au rouleau.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $connexion = $this->em->getConnection();
        // PDF déjà rangés pour les rouleaux de démonstration.
        foreach ($connexion->fetchFirstColumn('SELECT envois FROM tirage_rouleau WHERE machine_id >= 900000 AND envois IS NOT NULL') as $json) {
            foreach ((array) json_decode((string) $json, true) as $envoi) {
                $chemin = $this->rip->chemin((string) ($envoi['chemin'] ?? ''));
                if (null !== $chemin) {
                    @unlink($chemin);
                }
            }
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::PURGE as $sql) {
            $connexion->executeStatement($sql);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        if ($input->getOption('purger')) {
            $io->success('Démonstration de la production au rouleau enlevée.');

            return Command::SUCCESS;
        }

        $users = $this->em->getRepository(User::class);
        $oumar = $users->findOneBy(['username' => 'demo.oumar']);
        $seydou = $users->findOneBy(['username' => 'demo.seydou']);
        $grand = $this->em->find(Machines::class, 900001);
        if (!$oumar instanceof User || !$seydou instanceof User || !$grand instanceof Machines) {
            $io->warning('Démonstration absente (demo.oumar, demo.seydou, machine 900001) : rien à faire.');

            return Command::SUCCESS;
        }
        foreach (self::TABLES as $table) {
            $connexion->executeStatement(\sprintf('ALTER TABLE `%s` AUTO_INCREMENT = 900001', $table));
        }

        $maintenant = new \DateTimeImmutable();
        $jour = $maintenant->setTime(0, 0);
        // Heures de la maquette, jamais dans le futur si la démo est chargée tôt.
        $a = static fn (int $h, int $m, string $auPlusTard): \DateTimeImmutable => min($jour->setTime($h, $m), $maintenant->modify($auPlusTard));

        $this->machines($oumar, $a(8, 10, '-70 minutes'));
        $article = $this->article();
        $this->em->flush();

        $support440 = $this->em->find(Supports::class, 900002);
        $configKakemono = $this->em->find(ProduitConfiguration::class, 900006);
        $configBache = $this->em->find(ProduitConfiguration::class, 900001);
        if (!$support440 instanceof Supports || !$configKakemono instanceof ProduitConfiguration || !$configBache instanceof ProduitConfiguration) {
            $io->warning('Catalogue de démonstration incomplet (support 900002, configurations 900001 et 900006).');

            return Command::SUCCESS;
        }
        $demain = $jour->modify('+1 day');
        $travaux = [
            ['n' => '0430', 'client' => ['DEMO-PR01', 'Koita', 'Moussa', null, Clients::TYPE_COMPTE_PARTICULIER], 'config' => $configKakemono, 'support' => $support440, 'designation' => 'Kakémono fourreau haut et bas', 'l' => 1.2, 'h' => 2.0, 'q' => 1, 'finitions' => [900003], 'promis' => $jour->setTime(14, 0), 'priorite' => OrdreProduction::PRIORITE_URGENTE, 'image' => 'koita'],
            ['n' => '0186', 'client' => ['DEMO-PR02', 'Traoré', 'Mariam', 'Clinique Pasteur', Clients::TYPE_COMPTE_ENTREPRISE], 'config' => $configKakemono, 'support' => $support440, 'designation' => 'Kakémono', 'l' => 0.85, 'h' => 2.0, 'q' => 2, 'finitions' => [900003], 'promis' => $demain->setTime(10, 0), 'image' => 'pasteur'],
            ['n' => '0183', 'client' => ['DEMO-PR03', 'Diarra', 'Awa', 'Awa Mode', Clients::TYPE_COMPTE_PARTICULIER], 'config' => $configBache, 'support' => $support440, 'designation' => 'Bâche', 'l' => 3.0, 'h' => 1.0, 'q' => 2, 'finitions' => [900002, 900001], 'promis' => $demain->setTime(10, 0), 'image' => 'awa',
                'observation' => 'Le rouge Awa Mode doit être fidèle au BAT imprimé.'],
            ['n' => '0187', 'client' => ['DEMO-PR04', 'Sow', 'Ibrahima', 'Pharmacie du Fleuve', Clients::TYPE_COMPTE_ENTREPRISE], 'config' => $configBache, 'support' => null, 'designation' => 'Bâche', 'l' => 4.0, 'h' => 1.5, 'q' => 1, 'finitions' => [900002, 900001], 'promis' => $demain->modify('+1 day')->setTime(12, 0), 'image' => 'pharmacie'],
        ];

        $ordres = [];
        foreach ($travaux as $i => $t) {
            $ordres[$t['n']] = $this->travail($t, $i, $oumar, $seydou, $grand, $a);
        }
        $this->em->flush();

        // Heures du prépresse : BAT validé, envoyé en production par Seydou à 09:48.
        foreach ($ordres as $n => $o) {
            $envoye = '0187' === $n ? $a(10, 5, '-15 minutes') : $a(9, 48, '-40 minutes');
            $connexion->executeStatement('UPDATE controle_pre_presse SET traite_le = ?, valide_le = ?, envoye_production_le = ? WHERE id = ?', [
                $envoye->modify('-50 minutes')->format('Y-m-d H:i:s'), $envoye->modify('-35 minutes')->format('Y-m-d H:i:s'), $envoye->format('Y-m-d H:i:s'), $o->getControlePrePresse()?->getId(),
            ]);
        }
        $this->em->clear();

        // Le rouleau R-MMJJ-03 : Koita, Clinique Pasteur, Awa Diarra sur la Solvant 3,20 m.
        $grand = $this->em->find(Machines::class, 900001);
        $oumar = $this->em->find(User::class, $oumar->getId());
        \assert($grand instanceof Machines && $oumar instanceof User);
        $surLeRouleau = [];
        foreach (['0430', '0186', '0183'] as $n) {
            $o = $this->em->find(OrdreProduction::class, $ordres[$n]->getId());
            \assert($o instanceof OrdreProduction);
            $surLeRouleau[] = $o;
        }
        $cree = $a(9, 52, '-30 minutes');
        $tirage = $this->tirages->creer($grand, (string) FichierProduction::codeSupport($surLeRouleau[0]->getCommandeDetail()), $surLeRouleau, $oumar, $cree);
        $this->em->flush();
        $numero = 'R-'.$jour->format('md').'-03';
        if (null === $this->em->getRepository(TirageRouleau::class)->findOneBy(['numero' => $numero])) {
            $connexion->executeStatement('UPDATE tirage_rouleau SET numero = ? WHERE id = ?', [$numero, $tirage->getId()]);
            $this->em->refresh($tirage);
        }

        // Contrôles déjà faits : rouleau lu, profil, mode, séchage, sens ; test de buses à 08:12.
        $tirage->confirmerRouleau($article->getReference(), $oumar, $a(9, 54, '-28 minutes'));
        foreach (['profil', 'mode', 'sechage', 'miroir'] as $cle) {
            $tirage->cocher($cle, true);
        }
        if (null === $this->tirages->busesDuJour($grand, $maintenant)) {
            $this->tirages->noterTestBuses($grand, $oumar, 0, $a(8, 12, '-65 minutes'));
        }
        $this->em->flush();

        // « Envoyer au RIP » : un seul PDF, envoyé par morceaux comme depuis le navigateur.
        $envoi = $this->envoyer($tirage, $oumar, $a(9, 56, '-26 minutes'), $io);
        $this->em->flush();

        $io->success(\sprintf('Production au rouleau : rouleau %s (%d pièces, %d %% utilisé%s), une bâche 510 g en attente, jaune à 18 %% sur la Solvant 3,20 m.', $tirage->getNumero(), \count($tirage->getPieces()), $tirage->getPourcent(), null !== $envoi ? ', envoyé au RIP' : ''));

        return Command::SUCCESS;
    }

    /** Dossiers, têtes, usages et profils des machines ; encres de la Solvant 3,20 m (jaune à 18 %). */
    private function machines(User $oumar, \DateTimeImmutable $encresLe): void
    {
        $profil440 = ['support' => 'BACHE440', 'profilIcc' => 'Bache440_Solvant_8pass.icc', 'mode' => '8 passes · bidirectionnel', 'vitesseM2h' => 25, 'encreMlM2' => 12, 'chauffeC' => 45, 'sechageMin' => 45, 'miroir' => false];
        $reglages = [
            900001 => ['SOLVANT-320', '2 têtes i3200', 'bâches > 1,70 m, kakémonos, grands volumes', false, [
                $profil440,
                ['support' => 'BACHE510', 'profilIcc' => 'Bache510_Solvant_6pass.icc', 'mode' => '6 passes · bidirectionnel', 'vitesseM2h' => 32, 'encreMlM2' => 11, 'chauffeC' => 45, 'sechageMin' => 40, 'miroir' => false],
            ]],
            900002 => ['SOLVANT-180', '1 tête i3200', 'vinyle, micro-perforé, bâches jusqu\'à 1,70 m', false, [
                ['support' => 'VINYLE', 'profilIcc' => 'Vinyle_Solvant_6pass.icc', 'mode' => '6 passes · unidirectionnel', 'vitesseM2h' => 14, 'encreMlM2' => 10, 'chauffeC' => 40, 'sechageMin' => 30, 'miroir' => false],
                $profil440,
            ]],
            900003 => ['DTF-60', '2 têtes XP600', 'transferts textiles (film 60 cm)', true, [
                ['support' => 'FILM', 'profilIcc' => 'DTF_Film_CMJN_Blanc.icc', 'mode' => '6 passes · blanc + couleur', 'vitesseM2h' => 2.5, 'encreMlM2' => 18, 'chauffeC' => 110, 'sechageMin' => 5, 'miroir' => true],
            ]],
            900005 => ['CANON-A3', 'laser couleur', 'flyers, affiches A3, petites séries', false, []],
        ];
        foreach ($reglages as $id => [$code, $tete, $usages, $miroir, $profils]) {
            $machine = $this->em->find(Machines::class, $id);
            if (!$machine instanceof Machines) {
                continue;
            }
            $a = $this->atelier->atelier($machine);
            $a->setCodeDossier($code)->setTeteImpression($tete)->setUsages($usages)->setMiroir($miroir)->setProfils($profils);
            if (900001 === $id) {
                $a->saisirNiveauxEncre(['C' => 62, 'M' => 55, 'J' => 18, 'N' => 70], $oumar, $encresLe);
            }
        }

        // Le traceur de découpe : son dossier DECOUPE reçoit les fichiers « _decoupe ».
        $connexion = $this->em->getConnection();
        $traceur = $connexion->fetchOne('SELECT id FROM machines WHERE nom = ?', [self::TRACEUR]);
        if (false === $traceur) {
            $connexion->executeStatement(
                'INSERT INTO machines (nom, marque, modeles, numero_serie, type_machine, largeur_impression, nb_tetes, date_achat, date_mise_service, compteur_m2, compteur_heures, compteur_feuilles, etat, numero_machine, adresse_ip, mode_facturation, revenu_avant_suivi, compteur_unites, releve_compteurs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, 0, ?)',
                [self::TRACEUR, 'Graphtec', 'CE7000-130', 'SN-D509130', 'Plotter de découpe', '1.30', 1, '2025-02-10', '2025-02-17', 0, 0, 'actif', 'D-M9', '10.90.0.19', 'metre_carre', 'simple'],
            );
            $traceur = $connexion->lastInsertId();
        }
        $machine = $this->em->find(Machines::class, (int) $traceur);
        if ($machine instanceof Machines) {
            $this->atelier->atelier($machine)->setCodeDossier(FichierProduction::CODE_DECOUPE)->setTeteImpression('lame tangentielle')->setUsages('découpe à la forme, lettrage vinyle (fichiers « _decoupe »)');
        }
    }

    /** La bâche 440 g en laize 3,20 m (rouleaux de 50 m). */
    private function article(): Articles
    {
        $connexion = $this->em->getConnection();
        $id = $connexion->fetchOne('SELECT id FROM articles WHERE designation = ?', [self::ARTICLE]);
        if (false === $id) {
            $connexion->executeStatement(
                'INSERT INTO articles (reference, designation, categorie, unite, stock, stock_min, prix_achat, actif, vendable, consommable_production, fournisseur_id, unite_achat, quantite_par_unite_achat, longueur_unite_achat, laize, unite_vente, stock_max, emplacement, cout_moyen, famille_id, societe_id) SELECT ?, ?, categorie, unite, 480, 64, 192000, 1, 0, 1, fournisseur_id, unite_achat, 160, 50, 3.2, unite_vente, 640, emplacement, 600, famille_id, societe_id FROM articles WHERE id = 900001',
                ['D-MAT-006', self::ARTICLE],
            );
            $id = $connexion->lastInsertId();
        }
        $article = $this->em->find(Articles::class, (int) $id);
        if (!$article instanceof Articles) {
            throw new \RuntimeException('Article de démonstration introuvable (articles 900001).');
        }

        return $article;
    }

    /**
     * Une commande, sa ligne, son fichier, le BAT validé et l'ordre
     * transmis à la production.
     *
     * @param array<string, mixed>                                $t
     * @param callable(int, int, string): \DateTimeImmutable      $a
     */
    private function travail(array $t, int $rang, User $oumar, User $seydou, Machines $machine, callable $a): OrdreProduction
    {
        $client = $this->client(...$t['client']);
        /** @var ProduitConfiguration $config */
        $config = $t['config'];
        $ligne = (new CommandesDetails())
            ->setModeConfiguration('manuel')
            ->setProduitConfiguration($config)
            ->setProduit($config->getProduit())
            ->setTypeImpression($config->getTypeImpression())
            ->setSupport($t['support'] ?? $config->getSupport())
            ->setDesignation($t['designation'])
            ->setModeCalcul('metre_carre')
            ->setQuantite($t['q'])
            ->setPrixUnitaire(6500)
            ->setTva(0)
            ->setLargeur((string) $t['l'])
            ->setLongueur((string) $t['h'])
            ->setMachine($machine)
            ->setObservation($t['observation'] ?? null);
        $ligne->calculerSurface();
        foreach ($t['finitions'] as $idFinition) {
            $finition = $this->em->find(Finition::class, $idFinition);
            if ($finition instanceof Finition) {
                $ligne->addFinition((new CommandeDetailFinition())->setFinition($finition)->setNomFinition((string) $finition->getNom())->setPrixApplique(1000)->setModeCalcul('unite')->setQuantite($t['q'])->setMontant(1000 * $t['q']));
            }
        }
        $ligne->calculerTotaux(true);
        /** @var \DateTimeImmutable $promis */
        $promis = $t['promis'];
        $commande = (new Commandes())
            ->setNumero(self::PREFIXE.$t['n'])
            ->setClients($client)
            ->setAgents($seydou)
            ->setDateCommande($a(8, 0, '-3 hours')->modify('-1 day'))
            ->setDateLivraison($promis)
            ->setStatut(true)
            ->setEtat(true)
            ->setModeLivraison(Commandes::LIVRAISON_DOMICILE)
            ->setCreneauLivraison('matin');
        $commande->addCommandesDetail($ligne);
        $commande->recalculerTotaux();
        $commande->setTotalPaye($commande->getTotalTtc() ?? 0)->setResteAPayer(0);
        $this->em->persist($commande);

        $fichier = $this->image((string) $t['image'], (string) $t['n'], $ligne, $seydou, $a(8, 30, '-2 hours'), (float) $t['l'] * 100, (float) $t['h'] * 100);
        $controle = (new ControlePrePresse())
            ->setCommandeDetail($ligne)
            ->setFichierDejaTraite(true)
            ->setDimensionsConformes(true)
            ->setMargesSecuriteConformes(true)
            ->setResolutionConforme(true)
            ->setQuantiteConforme(true)
            ->setBatNecessaire(true)
            ->setBatValide(true)
            ->setObservation(isset($t['observation']) ? 'BAT validé par la cliente. '.$t['observation'] : 'BAT validé par le client.');
        if (null !== $fichier) {
            $this->em->persist($fichier);
            $controle->addFichier($fichier);
            $controle->enregistrerTraitement($seydou);
            $controle->valider($seydou);
            $controle->envoyerEnProduction($seydou);
        } else {
            $controle->enregistrerTraitement($seydou);
            $controle->setStatut(ControlePrePresse::STATUT_ENVOYE_PRODUCTION);
        }
        $ligne->addControlePrePresse($controle);
        $this->em->persist($controle);

        $ordre = (new OrdreProduction())
            ->setCommandeDetail($ligne)
            ->setControlePrePresse($controle)
            ->setCreePar($seydou)
            ->setQuantite($t['q'])
            ->setMachine($machine)
            ->setPriorite($t['priorite'] ?? OrdreProduction::PRIORITE_NORMALE)
            ->setDateLimite($promis);
        if (null !== $fichier) {
            $ordre->addFichier($fichier);
        }
        $ordre->transmettre('0187' === $t['n'] ? $a(10, 5, '-15 minutes') : $a(9, 48, '-40 minutes')->modify('+'.$rang.' seconds'));
        $ligne->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
        FichierProduction::preparer($ordre);
        $this->em->persist($ordre);

        return $ordre;
    }

    private function client(string $code, string $nom, string $prenom, ?string $raisonSociale, string $typeCompte): Clients
    {
        $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $code]);
        if ($client instanceof Clients) {
            return $client;
        }
        $this->em->getConnection()->executeStatement(
            'INSERT INTO clients (code, raison_sociale, nom, prenom, telephone, ville, type_client, type_compte, solde_credit, created_at, updated_at, statut, recevoir_sms, public_id, origine) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW(), 1, 0, UNHEX(REPLACE(UUID(), \'-\', \'\')), ?)',
            [$code, $raisonSociale, $nom, $prenom, '7099'.substr(preg_replace('/\D/', '', $code).'0000', 0, 4), 'Bamako', Clients::TYPE_COMPTE_PARTICULIER === $typeCompte ? 'B2C' : 'B2B', $typeCompte, 'recommandation'],
        );
        $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $code]);
        \assert($client instanceof Clients);

        return $client;
    }

    /**
     * Fichier d'impression fictif (PNG dessiné avec GD, sans police :
     * le texte est tracé avec la police intégrée puis agrandi).
     */
    private function image(string $modele, string $numero, CommandesDetails $ligne, User $par, \DateTimeImmutable $le, float $largeurCm, float $hauteurCm): ?CommandeDetailFichier
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            return null;
        }
        $dossier = $this->projet.'/'.self::DOSSIER_IMAGES;
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        // 5 px par cm : assez pour l'aperçu et le montage, léger pour la démo.
        $l = (int) round($largeurCm * 5);
        $h = (int) round($hauteurCm * 5);
        $im = imagecreatetruecolor($l, $h);
        $c = static fn (int $r, int $g, int $b): int => (int) imagecolorallocate($im, $r, $g, $b);
        $blanc = $c(255, 255, 255);
        switch ($modele) {
            case 'koita':
                imagefilledrectangle($im, 0, 0, $l, $h, $c(30, 79, 163));
                imagefilledpolygon($im, [0, (int) ($h * 0.62), $l, (int) ($h * 0.45), $l, $h, 0, $h], $c(222, 16, 40));
                $this->texte($im, 'KOITA', (int) ($l * 0.1), (int) ($h * 0.12), (int) ($l * 0.8), $blanc);
                $this->texte($im, 'SERVICES', (int) ($l * 0.18), (int) ($h * 0.24), (int) ($l * 0.64), $blanc);
                $this->texte($im, '+223 70 00 04 30', (int) ($l * 0.12), (int) ($h * 0.85), (int) ($l * 0.76), $blanc);
                break;
            case 'pasteur':
                imagefilledrectangle($im, 0, 0, $l, $h, $blanc);
                imagefilledrectangle($im, 0, 0, $l, (int) ($h * 0.4), $c(19, 120, 122));
                imagefilledrectangle($im, (int) ($l * 0.42), (int) ($h * 0.08), (int) ($l * 0.58), (int) ($h * 0.32), $blanc);
                imagefilledrectangle($im, (int) ($l * 0.3), (int) ($h * 0.16), (int) ($l * 0.7), (int) ($h * 0.24), $blanc);
                $this->texte($im, 'CLINIQUE', (int) ($l * 0.1), (int) ($h * 0.5), (int) ($l * 0.8), $c(19, 120, 122));
                $this->texte($im, 'PASTEUR', (int) ($l * 0.14), (int) ($h * 0.6), (int) ($l * 0.72), $c(19, 120, 122));
                $this->texte($im, 'URGENCES 24H/24', (int) ($l * 0.1), (int) ($h * 0.85), (int) ($l * 0.8), $c(222, 16, 40));
                break;
            case 'awa':
                imagefilledrectangle($im, 0, 0, $l, $h, $c(20, 33, 61));
                imagefilledpolygon($im, [(int) ($l * 0.62), 0, $l, 0, $l, $h, (int) ($l * 0.52), $h], $c(222, 16, 40));
                $this->texte($im, 'AWA MODE', (int) ($l * 0.06), (int) ($h * 0.28), (int) ($l * 0.42), $blanc);
                $this->texte($im, 'PRET-A-PORTER - HAMDALLAYE ACI', (int) ($l * 0.06), (int) ($h * 0.68), (int) ($l * 0.42), $c(255, 212, 0));
                break;
            default:
                imagefilledrectangle($im, 0, 0, $l, $h, $blanc);
                imagefilledrectangle($im, 0, 0, (int) ($l * 0.3), $h, $c(31, 157, 85));
                imagefilledrectangle($im, (int) ($l * 0.12), (int) ($h * 0.2), (int) ($l * 0.18), (int) ($h * 0.8), $blanc);
                imagefilledrectangle($im, (int) ($l * 0.05), (int) ($h * 0.44), (int) ($l * 0.25), (int) ($h * 0.56), $blanc);
                $this->texte($im, 'PHARMACIE DU FLEUVE', (int) ($l * 0.34), (int) ($h * 0.3), (int) ($l * 0.62), $c(31, 157, 85));
                $this->texte($im, 'OUVERT 7J/7', (int) ($l * 0.34), (int) ($h * 0.62), (int) ($l * 0.34), $c(20, 33, 61));
        }
        $nom = \sprintf('demo-pr-%s.png', $numero);
        imagepng($im, $dossier.'/'.$nom);
        imagedestroy($im);

        $fichier = (new CommandeDetailFichier())
            ->setCommandeDetail($ligne)
            ->setNomOriginal(\sprintf('%s_%dx%d_v1.png', $modele, (int) round($largeurCm), (int) round($hauteurCm)))
            ->setJetonUpload(bin2hex(random_bytes(16)))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setNomStockage($nom)
            ->setChemin('uploads/prepresse/demo-production-rouleau/'.$nom)
            ->setTypeMime('image/png')
            ->setTaille((int) filesize($dossier.'/'.$nom))
            ->setStatut(CommandeDetailFichier::STATUT_TERMINE)
            ->setTermineLe($le)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setDejaTraite(true)
            ->setAjoutePar($par)
            ->setCreeLe($le);
        $fichier->setAnalyse((new AnalyseFichierImpression())->analyser($dossier.'/'.$nom, $largeurCm, $hauteurCm));

        return $fichier;
    }

    /** Texte en capitales avec la police intégrée de GD, agrandi à la largeur voulue. */
    private function texte(\GdImage $im, string $texte, int $x, int $y, int $largeur, int $couleur): void
    {
        $petit = imagecreatetruecolor(max(1, \strlen($texte) * 9), 15);
        $fond = (int) imagecolorallocate($petit, 255, 0, 255);
        imagefilledrectangle($petit, 0, 0, \strlen($texte) * 9, 15, $fond);
        imagecolortransparent($petit, $fond);
        $rgb = imagecolorsforindex($im, $couleur);
        imagestring($petit, 5, 0, 0, $texte, (int) imagecolorallocate($petit, $rgb['red'], $rgb['green'], $rgb['blue']));
        $hauteur = (int) round($largeur * 15 / max(1, \strlen($texte) * 9));
        imagecopyresized($im, $petit, $x, $y, 0, 0, $largeur, $hauteur, \strlen($texte) * 9, 15);
        imagedestroy($petit);
    }

    /**
     * Le PDF du rouleau (Dompdf : pièces à leur place à l'échelle 1:1, nom
     * de la commande dans la marge), envoyé par morceaux comme le fait le
     * navigateur. Rend le message, ou null si le PDF n'a pas pu être fait.
     */
    private function envoyer(TirageRouleau $tirage, User $par, \DateTimeImmutable $le, SymfonyStyle $io): ?string
    {
        $pieces = [];
        foreach ($tirage->piecesTriees() as $p) {
            $f = $p->getOrdre()->getFichiers()->first();
            $chemin = $f instanceof CommandeDetailFichier ? $this->projet.'/var/'.$f->getChemin() : null;
            $pieces[] = [
                'x' => $p->getXCm(), 'y' => $p->getYCm(), 'l' => $p->getLargeurCm(), 'h' => $p->getHauteurCm(),
                'image' => null !== $chemin && is_file($chemin) ? $chemin : null,
                'marque' => FichierProduction::nomProduction($p->getOrdre()),
            ];
        }
        $laize = $tirage->getLaizeCm();
        $longueur = max(1.0, $tirage->getConsommeM() * 100);
        $html = '<html><head><style>@page{margin:0}body{margin:0;font-family:"DejaVu Sans",sans-serif}div,img{position:absolute}.m{font-size:7pt;color:#000;white-space:nowrap}</style></head><body>';
        $parRangee = [];
        foreach ($pieces as $p) {
            // Une ligne de marge par pièce de la même rangée.
            $k = $parRangee[(string) $p['y']] = ($parRangee[(string) $p['y']] ?? -1) + 1;
            $html .= \sprintf('<div style="left:%.2fcm;top:%.2fcm;width:%.2fcm;height:%.2fcm;border:0.5pt solid #000"></div>', $p['x'], $p['y'], $p['l'], $p['h']);
            if (null !== $p['image']) {
                $html .= \sprintf('<img src="%s" style="left:%.2fcm;top:%.2fcm;width:%.2fcm;height:%.2fcm">', htmlspecialchars($p['image']), $p['x'], $p['y'], $p['l'], $p['h']);
            }
            $html .= \sprintf('<div class="m" style="left:0.3cm;top:%.2fcm">%s</div>', $p['y'] + 0.5 + 0.6 * $k, htmlspecialchars($p['marque']));
        }
        $html .= '</body></html>';
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [$this->projet]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, $laize * 72 / 2.54, $longueur * 72 / 2.54]);
        $dompdf->render();
        $octets = (string) $dompdf->output();
        if (!str_starts_with($octets, '%PDF-')) {
            $io->warning('Le PDF du rouleau n\'a pas pu être monté : rouleau laissé « préparé ».');

            return null;
        }

        $session = new Session(new MockArraySessionStorage());
        $taille = \strlen($octets);
        $morceau = EnvoiRip::morceau();
        $morceaux = (int) ceil($taille / $morceau);
        $jeton = $this->rip->initialiser($tirage, $taille, $morceaux, $session);
        for ($i = 0; $i < $morceaux; ++$i) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'rip');
            file_put_contents($tmp, substr($octets, $i * $morceau, $morceau));
            $this->rip->recevoir($tirage, $jeton, $i, new UploadedFile($tmp, 'morceau.part', 'application/octet-stream', null, true), $session);
        }

        return $this->rip->terminer($tirage, $jeton, 1, $par, $le, $session)['message'];
    }
}
