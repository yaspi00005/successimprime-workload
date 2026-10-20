<?php

namespace App\Command;

use App\Entity\Articles;
use App\Entity\Clients;
use App\Entity\ColisProduction;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControleFinal;
use App\Entity\ControlePrePresse;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\ProduitConfiguration;
use App\Entity\StockSorties;
use App\Entity\User;
use App\Repository\ControleFinalRepository;
use App\Service\Livraisons\ProgrammationLivraison;
use App\Service\Production\ColisCommandes;
use App\Service\Production\ControlesFinaux;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Démonstration des colis et du contrôle final (PD05, PD06,
 * docs/production-colis.md) : quatre commandes terminées sans étiquette
 * (Awa Diarra 2 bâches, Koita 1 kakémono, Clinique Pasteur (Mariam Traoré) 2 kakémonos,
 * Awa Diarra 1 000 flyers), une commande étiquetée avec 1 colis sur 2
 * scanné et son contrôle à faire (Pharmacie du Fleuve), trois commandes
 * prêtes dans la tournée de demo.bakary demain de 10 h à 12 h. Clients
 * DEMO-PC…, commandes CMD-PC…, identifiants >= 900001, relançable.
 * Les commandes prêtes passent par les vrais services (étiquettes, scan,
 * contrôle, tournée). Lancée par app:demo.
 */
#[AsCommand(name: 'app:production-colis:demo', description: 'Charge la démonstration des étiquettes de colis et du contrôle final.')]
final class ProductionColisDemoCommand extends Command
{
    public const PREFIXE = 'CMD-PC';

    /** Tables nouvelles de cette démonstration : identifiants >= 900001 (recette des accès). */
    private const TABLES = ['colis_production', 'controle_final'];

    /** Enlève la démonstration : bons, colis, contrôles, ordres, sorties de stock, lignes et commandes CMD-PC…. */
    public const PURGE = [
        "DELETE l FROM bon_livraison_ligne l JOIN bon_livraison b ON b.id = l.bon_livraison_id JOIN commandes c ON c.id = b.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE b FROM bon_livraison b JOIN commandes c ON c.id = b.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE x FROM colis_production x JOIN commandes c ON c.id = x.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE x FROM controle_final x JOIN commandes c ON c.id = x.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE s FROM stock_sorties s JOIN commandes_details d ON d.id = s.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE j FROM journal_machine j JOIN ordre_production o ON o.id = j.ordre_id JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE f FROM ordre_production_fichier f JOIN ordre_production o ON o.id = f.ordre_production_id JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE o FROM ordre_production o JOIN commandes_details d ON d.id = o.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE f FROM controle_pre_presse_fichier f JOIN controle_pre_presse p ON p.id = f.controle_pre_presse_id JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE p FROM controle_pre_presse p JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE f FROM commande_detail_fichier f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE f FROM commande_detail_finition f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE r FROM stock_reservation r JOIN commandes_details d ON d.id = r.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE d FROM commandes_details d JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PC%'",
        "DELETE j FROM journal_activite j JOIN commandes c ON c.id = j.entite_id WHERE j.entite = 'Commandes' AND c.numero LIKE 'CMD-PC%'",
        "DELETE FROM notifications WHERE message LIKE '%CMD-PC%' OR detail LIKE '%CMD-PC%'",
        "DELETE FROM commandes WHERE numero LIKE 'CMD-PC%'",
    ];

    /** Les clients de la maquette (codes DEMO-PC… : jamais de SMS, d'e-mail ni de WhatsApp). */
    private const CLIENTS = [
        'DEMO-PC01' => ['prenom' => 'Awa', 'nom' => 'Diarra', 'societe' => 'Boutique Awa Mode', 'quartier' => 'Hamdallaye ACI', 'commune' => 'Commune IV', 'telephone' => '60000183'],
        'DEMO-PC02' => ['prenom' => 'Moussa', 'nom' => 'Koita', 'societe' => null, 'quartier' => 'Badalabougou', 'commune' => 'Commune V', 'telephone' => '60000430'],
        'DEMO-PC03' => ['prenom' => 'Mariam', 'nom' => 'Traoré', 'societe' => 'Clinique Pasteur', 'quartier' => 'Hippodrome', 'commune' => 'Commune II', 'telephone' => '60000186'],
        'DEMO-PC04' => ['prenom' => 'Fanta', 'nom' => 'Keïta', 'societe' => 'Pharmacie du Fleuve', 'quartier' => 'Quartier du Fleuve', 'commune' => 'Commune III', 'telephone' => '60000179'],
    ];

    private string $police = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ColisCommandes $colis,
        private readonly ControlesFinaux $controles,
        private readonly ControleFinalRepository $depotControles,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève seulement cette démonstration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $connexion = $this->em->getConnection();
        $anciennes = $connexion->fetchFirstColumn("SELECT id FROM commandes WHERE numero LIKE 'CMD-PC%'");
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::PURGE as $sql) {
            $connexion->executeStatement($sql);
        }
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach ($anciennes as $id) {
            foreach (glob($this->projet.'/'.ControlesFinaux::DOSSIER_PHOTOS.'/'.(int) $id.'-*') ?: [] as $photo) {
                @unlink($photo);
            }
        }
        if ($input->getOption('purger')) {
            $io->success('Démonstration des colis et du contrôle final enlevée.');

            return Command::SUCCESS;
        }
        foreach (self::TABLES as $table) {
            if ((int) $connexion->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$table) < 900000) {
                $connexion->executeStatement('ALTER TABLE '.$table.' AUTO_INCREMENT = 900001');
            }
        }

        $users = $this->em->getRepository(User::class);
        $oumar = $users->findOneBy(['username' => 'demo.oumar']);
        $seydou = $users->findOneBy(['username' => 'demo.seydou']);
        $kadiatou = $users->findOneBy(['username' => 'demo.kadiatou']);
        $bakary = $users->findOneBy(['username' => 'demo.bakary']);
        $grand = $this->em->find(Machines::class, 900001);
        $petit = $this->em->find(Machines::class, 900002);
        if (!$oumar instanceof User || !$kadiatou instanceof User || !$bakary instanceof User || !$grand instanceof Machines || !$petit instanceof Machines) {
            $io->warning('Démonstration absente (demo.oumar, demo.kadiatou, demo.bakary, machines 900001 et 900002) : rien à faire.');

            return Command::SUCCESS;
        }
        $this->police = \function_exists('imagettftext') && is_file($this->projet.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf') ? $this->projet.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf' : '';

        $clients = [];
        foreach (self::CLIENTS as $code => $c) {
            $clients[$code] = $this->client($code, $c);
        }
        $this->em->flush();

        $maintenant = new \DateTimeImmutable();
        $h = static fn (string $decalage): \DateTimeImmutable => $maintenant->modify($decalage);
        $demain = $maintenant->setTime(0, 0)->modify('+1 day');
        $apresDemain = $maintenant->setTime(0, 0)->modify('+2 days');
        $programmation = static fn (Clients $c, \DateTimeImmutable $jour, string $creneau): array => [
            'mode' => ProgrammationLivraison::MODE_LIVRAISON, 'libelle' => 'Boutique', 'adresse' => $c->getQuartier().', Bamako', 'repere' => null,
            'latitude' => null, 'longitude' => null, 'jour' => $jour->format('Y-m-d'), 'creneau' => $creneau,
            'nom' => trim($c->getPrenom().' '.$c->getNom()), 'telephone' => $c->getTelephone(), 'siAbsent' => null, 'paiement' => null, 'le' => $maintenant->format(\DATE_ATOM),
        ];

        // PD05 : travaux terminés, sans étiquette (maquette : 2 colis, 1, 1, 2 cartons).
        $this->commande('0183', $clients['DEMO-PC01'], $oumar, $seydou, $grand, [
            ['config' => 900001, 'designation' => 'Bâche 440 g', 'l' => 3.0, 'h' => 1.0, 'q' => 2, 'finitions' => [900002, 900001], 'image' => 'awa', 'observation' => 'Le rouge Awa Mode doit être identique au BAT.'],
        ], $demain, $programmation($clients['DEMO-PC01'], $demain, '10-12'), $h('-3 hours'), 'termine');
        $this->commande('0430', $clients['DEMO-PC02'], $oumar, $seydou, $grand, [
            ['config' => 900006, 'designation' => 'Kakémono', 'l' => 0.8, 'h' => 1.8, 'q' => 1, 'finitions' => []],
        ], $demain, null, $h('-2 hours'), 'termine');
        $this->commande('0186', $clients['DEMO-PC03'], $oumar, $seydou, $grand, [
            ['config' => 900006, 'designation' => 'Kakémonos', 'l' => 0.8, 'h' => 1.8, 'q' => 2, 'finitions' => []],
        ], $apresDemain, $programmation($clients['DEMO-PC03'], $apresDemain, '08-10'), $h('-150 minutes'), 'termine');
        $this->commande('0181', $clients['DEMO-PC01'], $oumar, $seydou, $petit, [
            ['config' => 900007, 'designation' => 'Flyers A5', 'q' => 1000, 'finitions' => []],
        ], $apresDemain, $programmation($clients['DEMO-PC01'], $apresDemain, '14-16'), $h('-4 hours'), 'termine');

        // PD06 : étiquettes collées, 1 colis sur 2 scanné, photo prise, contrôle à faire.
        $pharmacie = $this->commande('0179', $clients['DEMO-PC04'], $oumar, $seydou, $grand, [
            ['config' => 900001, 'designation' => 'Banderole', 'l' => 4.0, 'h' => 0.8, 'q' => 2, 'finitions' => [900002, 900001], 'image' => 'pharmacie', 'observation' => 'Le vert de la croix doit être identique au BAT.'],
        ], $demain, $programmation($clients['DEMO-PC04'], $demain, '14-16'), $h('-3 hours'), 'termine', ['impression' => 28, 'sechage' => 45, 'finitions' => 40, 'controle' => 10], 113);
        $colis = $this->colis->imprimer($pharmacie, 2, $oumar, $h('-50 minutes'));
        $this->colis->scanner($colis[0], $oumar, $h('-25 minutes'));
        $this->photo($pharmacie, 'pharmacie', $oumar, $h('-30 minutes'));
        $this->sorties($pharmacie, [[900001, 6.6], [900006, 0.078], [900009, 0.052]], $h('-67 minutes'));

        // Prêtes, dans la tournée de Bakary demain de 10 h à 12 h : le vrai chemin (étiquettes, scan, contrôle, tournée).
        $pretes = [
            ['0177', $clients['DEMO-PC01'], [['config' => 900001, 'designation' => 'Bâche 440 g', 'l' => 3.0, 'h' => 1.0, 'q' => 2, 'finitions' => [900002, 900001], 'image' => 'awa', 'observation' => 'Le rouge Awa Mode doit être identique au BAT.']], 2],
            ['0175', $this->em->find(Clients::class, 900039), [['config' => 900001, 'designation' => 'Banderole', 'l' => 3.0, 'h' => 0.8, 'q' => 1, 'finitions' => [900001]]], 1],
            ['0174', $this->em->find(Clients::class, 900002), [['config' => 900008, 'designation' => 'Cartes de visite', 'q' => 500, 'finitions' => []]], 1],
        ];
        $n = 0;
        foreach ($pretes as $i => [$numero, $client, $lignes, $nombreColis]) {
            if (!$client instanceof Clients) {
                continue;
            }
            $commande = $this->commande($numero, $client, $oumar, $seydou, 900008 === $lignes[0]['config'] ? $petit : $grand, $lignes, $demain, $programmation($client, $demain, '10-12'), $h('-'.(7 - $i).' hours'), 'termine', ['impression' => 26, 'sechage' => 45, 'finitions' => 52, 'controle' => 10], 113);
            $etiquetees = $this->colis->imprimer($commande, $nombreColis, $oumar, $h('-'.(6 - $i).' hours'));
            if ('0177' === $numero) {
                $this->photo($commande, 'awa', $oumar, $h('-'.(6 - $i).' hours')->modify('+8 minutes'));
                $this->sorties($commande, [[900001, 6.6], [900006, 0.078], [900009, 0.041]], $h('-'.(6 - $i).' hours'));
            }
            foreach ($etiquetees as $k => $c) {
                $this->colis->scanner($c, $oumar, $h('-'.(6 - $i).' hours')->modify('+'.(12 + $k).' minutes'));
            }
            $saisie = [];
            foreach ($commande->getCommandesDetails() as $d) {
                $saisie[(string) $d->getId()] = array_fill_keys(ControleFinal::POINTS, '1');
            }
            $this->controles->valider($commande, $saisie, $oumar, $h('-'.(6 - $i).' hours')->modify('+15 minutes'));
            $this->controles->ajouterATournee($commande, $kadiatou, $bakary, $demain, ProgrammationLivraison::CRENEAUX['10-12']);
            ++$n;
        }

        $io->success(\sprintf('Colis et contrôle final : 4 commandes à étiqueter, 1 à contrôler, %d prêtes dans la tournée de demo.bakary demain (commandes %s…).', $n, self::PREFIXE));

        return Command::SUCCESS;
    }

    /** @param array<string, ?string> $c */
    private function client(string $code, array $c): Clients
    {
        $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $code]) ?? new Clients();
        $client->setCode($code)->setPrenom($c['prenom'])->setNom($c['nom'])->setRaisonSociale($c['societe'])
            ->setQuartier($c['quartier'])->setCommune($c['commune'])->setVille('Bamako')->setTelephone($c['telephone'])
            ->setObservation('Client de démonstration (étiquettes de colis, docs/production-colis.md).')->setStatut(true);
        $this->em->persist($client);

        return $client;
    }

    /**
     * Une commande et ses travaux, jusqu'à « Terminé » ($etat), heures de la
     * maquette.
     *
     * @param list<array<string, mixed>> $lignes
     * @param array<string, mixed>|null  $programmation
     * @param array<string, int>          $etapes          minutes estimées par étape
     */
    private function commande(string $numero, Clients $client, User $oumar, ?User $seydou, Machines $machine, array $lignes, \DateTimeImmutable $livraison, ?array $programmation, \DateTimeImmutable $debut, string $etat, array $etapes = ['impression' => 30, 'controle' => 10], ?int $reel = null): Commandes
    {
        $commande = (new Commandes())
            ->setNumero(self::PREFIXE.$numero)
            ->setClients($client)
            ->setAgents($oumar)
            ->setDateCommande($debut->modify('-1 day'))
            ->setDateLivraison($livraison)
            ->setStatut(true)
            ->setEtat(true)
            ->setModeLivraison(null !== $programmation ? Commandes::LIVRAISON_DOMICILE : Commandes::LIVRAISON_RETRAIT)
            ->setProgrammationLivraison($programmation);
        if (null !== $programmation) {
            ProgrammationLivraison::appliquer($commande, null);
        }
        $ordres = [];
        foreach ($lignes as $t) {
            $config = $this->em->find(ProduitConfiguration::class, $t['config']);
            if (!$config instanceof ProduitConfiguration) {
                continue;
            }
            $ligne = (new CommandesDetails())
                ->setModeConfiguration('manuel')
                ->setProduitConfiguration($config)
                ->setProduit($config->getProduit())
                ->setTypeImpression($config->getTypeImpression())
                ->setSupport($config->getSupport())
                ->setDesignation($t['designation'])
                ->setQuantite($t['q'])
                ->setPrixUnitaire(isset($t['l']) ? 6500 : 25)
                ->setTva(0)
                ->setMachine($machine)
                ->setObservation($t['observation'] ?? null);
            if (isset($t['l'])) {
                $ligne->setModeCalcul('metre_carre')->setLargeur((string) $t['l'])->setLongueur((string) $t['h']);
                $ligne->calculerSurface();
            } else {
                $ligne->setModeCalcul('unite')->setFormat($config->getFormat());
            }
            foreach ($t['finitions'] as $idFinition) {
                $finition = $this->em->find(Finition::class, $idFinition);
                if ($finition instanceof Finition) {
                    $ligne->addFinition((new CommandeDetailFinition())->setFinition($finition)->setNomFinition((string) $finition->getNom())->setPrixApplique(1000)->setModeCalcul('unite')->setQuantite($t['q'])->setMontant(1000 * $t['q']));
                }
            }
            $ligne->calculerTotaux(true);
            $commande->addCommandesDetail($ligne);

            $fichier = isset($t['image']) ? $this->image($t['image'], $numero, $ligne, $seydou, $debut->modify('-20 hours')) : null;
            $controle = (new ControlePrePresse())
                ->setCommandeDetail($ligne)->setFichierDejaTraite(true)->setDimensionsConformes(true)->setMargesSecuriteConformes(true)
                ->setResolutionConforme(true)->setQuantiteConforme(true);
            if (null !== $fichier) {
                $this->em->persist($fichier);
                $controle->addFichier($fichier);
            }
            if ($seydou instanceof User) {
                $controle->enregistrerTraitement($seydou);
            }
            $controle->setStatut(ControlePrePresse::STATUT_ENVOYE_PRODUCTION);
            $ligne->addControlePrePresse($controle);
            $this->em->persist($controle);

            $ordre = (new OrdreProduction())->setCommandeDetail($ligne)->setControlePrePresse($controle)->setCreePar($seydou)
                ->setQuantite($t['q'])->setMachine($machine)->setDateLimite($livraison);
            if (null !== $fichier) {
                $ordre->addFichier($fichier);
            }
            $ordre->transmettre($debut->modify('-18 hours'));
            $ligne->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
            $this->em->persist($ordre);
            $ordre->verifierFichier($oumar, ['dimensions' => true, 'securite' => true, 'resolution' => true, 'matiere' => true], $debut->modify('-15 minutes'));
            $ordre->estimer(array_map(static fn (string $cle, int $minutes): array => ['cle' => $cle, 'libelle' => ucfirst($cle), 'propose' => $minutes, 'estime' => $minutes], array_keys($etapes), $etapes), null, $debut, $machine);
            if ('termine' === $etat) {
                $ligne->demarrerProduction($oumar, $machine);
                $ordre->demarrer($oumar, $machine);
                $ligne->terminerProduction($oumar, $t['q'], 0, null);
                $ordre->terminer($oumar, $t['q'], 0);
            }
            $ordres[] = $ordre;
        }
        $commande->recalculerTotaux();
        $commande->setTotalPaye($commande->getTotalTtc() ?? 0)->setResteAPayer(0);
        $this->em->persist($commande);
        $this->em->flush();

        // Heures de la maquette : démarré, terminé (temps réel sans pause).
        foreach ($ordres as $ordre) {
            $fin = $debut->modify('+'.($reel ?? max(1, (int) round(array_sum($etapes) * 0.95))).' minutes');
            $this->em->getConnection()->executeStatement('UPDATE ordre_production SET demarre_le = ?, termine_le = ? WHERE id = ?', [$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), $ordre->getId()]);
            $this->em->getConnection()->executeStatement('UPDATE commandes_details SET production_terminee_le = ? WHERE id = ?', [$fin->format('Y-m-d H:i:s'), $ordre->getCommandeDetail()?->getId()]);
            $this->em->refresh($ordre);
        }

        return $commande;
    }

    /**
     * Matière sortie du stock à « Terminé » (bloc « Consommé »), comme le fait
     * la recette du produit.
     *
     * @param list<array{0: int, 1: float}> $articles identifiant de l'article, quantité dans son unité de stock
     */
    private function sorties(Commandes $commande, array $articles, \DateTimeImmutable $le): void
    {
        $ligne = $commande->getCommandesDetails()->first();
        $ordre = $this->controles->ordres($commande)[0] ?? null;
        if (!$ligne instanceof CommandesDetails || null === $ordre) {
            return;
        }
        foreach ($articles as [$article, $quantite]) {
            $a = $this->em->find(Articles::class, $article);
            if ($a instanceof Articles) {
                $this->em->persist((new StockSorties())->setArticle($a)->setCommandeDetail($ligne)->setQuantite($quantite)->setDate($le)
                    ->setOrigine(StockSorties::ORIGINE_PRODUCTION)->setReferenceOrigine(OrdreProduction::referenceStockDe((int) $ordre->getId(), 0)));
            }
        }
        $this->em->flush();
    }

    /** Photo de la pièce imprimée (image dessinée, PNG), comme si le machiniste l'avait prise. */
    private function photo(Commandes $commande, string $modele, User $par, \DateTimeImmutable $le): void
    {
        $im = $this->dessin($modele, true);
        if (null === $im) {
            return;
        }
        $dossier = $this->projet.'/'.ControlesFinaux::DOSSIER_PHOTOS;
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        $nom = \sprintf('%d-%s.png', (int) $commande->getId(), bin2hex(random_bytes(8)));
        imagepng($im, $dossier.'/'.$nom);
        imagedestroy($im);
        $controle = $this->depotControles->deLaCommande($commande);
        if (null === $controle) {
            $controle = new ControleFinal($commande);
            $this->em->persist($controle);
        }
        $controle->photographier($nom, $par, $le);
        $this->em->flush();
    }

    /** Fichier du BAT (image dessinée), rangé comme un fichier de commande. */
    private function image(string $modele, string $numero, CommandesDetails $ligne, ?User $par, \DateTimeImmutable $le): ?CommandeDetailFichier
    {
        $im = $this->dessin($modele, false);
        if (null === $im) {
            return null;
        }
        $nom = \sprintf('demo-pc-%s.png', $numero);
        $dossier = $this->projet.'/var/uploads/prepresse/demo-production-colis';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        imagepng($im, $dossier.'/'.$nom);
        imagedestroy($im);

        return (new CommandeDetailFichier())
            ->setCommandeDetail($ligne)
            ->setNomOriginal('awa' === $modele ? 'bache-awa-mode_3000x1000_bat.png' : 'banderole-pharmacie_4000x800_bat.png')
            ->setJetonUpload(bin2hex(random_bytes(16)))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setNomStockage($nom)
            ->setChemin('uploads/prepresse/demo-production-colis/'.$nom)
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

    /**
     * Bâche « AWA MODE » (bleu nuit, pan rouge, œillets) ou banderole de la
     * pharmacie (croix verte) ; en photo : un peu plus sombre, sur la table.
     */
    private function dessin(string $modele, bool $photo): ?\GdImage
    {
        if (!\function_exists('imagecreatetruecolor')) {
            return null;
        }
        [$l, $hauteur] = 'awa' === $modele ? [1200, 400] : [1200, 240];
        $marge = $photo ? 60 : 0;
        $im = imagecreatetruecolor($l + 2 * $marge, $hauteur + 2 * $marge);
        imagefilledrectangle($im, 0, 0, $l + 2 * $marge, $hauteur + 2 * $marge, imagecolorallocate($im, 214, 219, 226));
        $assombrir = static fn (int $v): int => $photo ? (int) round($v * 0.9) : $v;
        $couleur = static fn (int $r, int $g, int $b): int => imagecolorallocate($im, $assombrir($r), $assombrir($g), $assombrir($b));
        $x = $marge;
        $y = $marge;
        if ('awa' === $modele) {
            imagefilledrectangle($im, $x, $y, $x + $l, $y + $hauteur, $couleur(15, 33, 80));
            imagefilledpolygon($im, [$x + 700, $y, $x + $l, $y, $x + $l, $y + $hauteur, $x + 560, $y + $hauteur], $couleur(222, 16, 40));
            $this->ecrire($im, 'AWA MODE', $x + 70, $y + 140, 9, $couleur(255, 255, 255));
            $this->ecrire($im, 'Hamdallaye ACI', $x + 76, $y + 300, 4, $couleur(255, 255, 255));
        } else {
            imagefilledrectangle($im, $x, $y, $x + $l, $y + $hauteur, $couleur(255, 255, 255));
            imagefilledrectangle($im, $x + 70, $y + 50, $x + 210, $y + 190, $couleur(31, 157, 85));
            imagefilledrectangle($im, $x + 115, $y + 60, $x + 165, $y + 180, $couleur(255, 255, 255));
            imagefilledrectangle($im, $x + 80, $y + 95, $x + 200, $y + 145, $couleur(255, 255, 255));
            $this->ecrire($im, 'PHARMACIE', $x + 260, $y + 50, 7, $couleur(19, 112, 60));
            $this->ecrire($im, 'DU FLEUVE', $x + 260, $y + 140, 6, $couleur(20, 33, 61));
        }
        // Œillets aux coins et tous les 50 cm environ.
        $oeillet = $couleur(190, 196, 204);
        for ($i = 0; $i <= 6; ++$i) {
            $px = $x + 18 + (int) round($i * ($l - 36) / 6);
            imagefilledellipse($im, $px, $y + 16, 14, 14, $oeillet);
            imagefilledellipse($im, $px, $y + $hauteur - 16, 14, 14, $oeillet);
        }

        return $im;
    }

    /** Texte en grand, avec la police intégrée de GD agrandie quand FreeType manque. */
    private function ecrire(\GdImage $im, string $texte, int $x, int $y, int $echelle, int $couleur): void
    {
        if ('' !== $this->police) {
            imagettftext($im, 9 * $echelle, 0, $x, $y + 12 * $echelle, $couleur, $this->police, $texte);

            return;
        }
        $petit = imagecreatetruecolor(9 * \strlen($texte), 15);
        $fond = imagecolorallocate($petit, 1, 2, 3);
        imagefilledrectangle($petit, 0, 0, 9 * \strlen($texte), 15, $fond);
        imagecolortransparent($petit, $fond);
        imagestring($petit, 5, 0, 0, $texte, imagecolorsforindex($im, $couleur)['red'] << 16 | imagecolorsforindex($im, $couleur)['green'] << 8 | imagecolorsforindex($im, $couleur)['blue']);
        imagecopyresized($im, $petit, $x, $y, 0, 0, intdiv(9 * \strlen($texte) * $echelle, 2), intdiv(15 * $echelle, 2), 9 * \strlen($texte), 15);
        imagedestroy($petit);
    }
}
