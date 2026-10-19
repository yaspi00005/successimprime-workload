<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Machines;
use App\Entity\ProduitConfiguration;
use App\Entity\RelanceFichier;
use App\Entity\User;
use App\Service\Prepresse\EtapesRelance;
use App\Service\Prepresse\RelancesFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Démonstration de la prépresse (maquettes PQ01 à PQ04,
 * docs/prepresse.md) : la commande d'étiquettes de Fofana (6 formats,
 * 2 fichiers reçus sur 6, relancée J+1 et J+3), Sacko qui « passe
 * samedi », une commande sans numéro de client, Diallo depuis 2 jours, un
 * kakémono à contrôler à livrer aujourd'hui, un BAT chez Samaké, un
 * vinyle de Coulibaly prêt pour la production. Commandes CMD-PQ…,
 * relançable : la démonstration précédente part d'abord. Lancée par
 * app:demo après le chargement.
 */
#[AsCommand(name: 'app:prepresse:demo', description: 'Charge la démonstration de la prépresse (commandes CMD-PQ…).')]
final class PrepresseDemoCommand extends Command
{
    public const PREFIXE = 'CMD-PQ';

    /** Client de démonstration sans numéro de téléphone (« client non renseigné »). */
    public const CLIENT_SANS_NUMERO = 909700;

    /** Enlève la démonstration : relances, appels, messages, contrôles, fichiers, réservations de stock, lignes et commandes CMD-PQ…. */
    public const PURGE = [
        "DELETE r FROM relance_fichier r JOIN commandes c ON c.id = r.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE a FROM action_caisse a JOIN commandes c ON a.cause = CONCAT('fichier:', c.id) WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE m FROM message_client m JOIN commandes c ON c.id = m.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE v FROM conversation_client v JOIN commandes c ON c.id = v.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE f FROM controle_pre_presse_fichier f JOIN controle_pre_presse p ON p.id = f.controle_pre_presse_id JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE p FROM controle_pre_presse p JOIN commandes_details d ON d.id = p.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE f FROM commande_detail_fichier f JOIN commandes_details d ON d.id = f.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE s FROM stock_reservation s JOIN commandes_details d ON d.id = s.commande_detail_id JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE d FROM commandes_details d JOIN commandes c ON c.id = d.commande_id WHERE c.numero LIKE 'CMD-PQ%'",
        "DELETE FROM commandes WHERE numero LIKE 'CMD-PQ%'",
        'DELETE FROM clients WHERE id = 909700',
    ];

    private const DOSSIER = 'var/uploads/prepresse/demo-prepresse';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RelancesFichiers $relances,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève seulement la démonstration de la prépresse.');
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
        foreach (glob($this->projet.'/'.self::DOSSIER.'/*') ?: [] as $ancien) {
            @unlink($ancien);
        }
        if ($input->getOption('purger')) {
            $io->success('Démonstration de la prépresse enlevée.');

            return Command::SUCCESS;
        }

        $users = $this->em->getRepository(User::class);
        $agent = $users->findOneBy(['username' => 'demo.kadiatou']);
        $seydou = $users->findOneBy(['username' => 'demo.seydou']) ?? $agent;
        if (!$agent instanceof User || null === $this->em->find(Clients::class, 900011)) {
            $io->warning('Démonstration absente (demo.kadiatou, client 900011) : rien à faire.');

            return Command::SUCCESS;
        }
        $sansNumero = $this->clientSansNumero();

        $maintenant = new \DateTimeImmutable();
        $jour = $maintenant->setTime(0, 0);
        $h = static fn (string $decalage): \DateTimeImmutable => $maintenant->modify($decalage);

        // Fofana : 6 formats d'étiquettes vinyle, 2 fichiers reçus (maquettes PQ01 et PQ02).
        $etiquettes = [[7.5, 8], [7.5, 7.5], [5.5, 7], [5.5, 5], [5.5, 4], [5.5, 5.5]];
        $fofana = $this->commande('0378', 900011, $agent, $h('-12 days')->setTime(9, 40), $jour->modify('+2 days'));
        foreach ($etiquettes as $i => [$l, $ht]) {
            $ligne = $this->ligne($fofana, 900002, 'Étiquettes vinyle', $l / 100, $ht / 100, 25, 150);
            if ($i < 2) {
                $nom = \sprintf('fofana_%sx%s.png', str_replace('.', ',', (string) $l), str_replace('.', ',', (string) $ht));
                $this->fichier($ligne, $nom, (int) round($l / 2.54 * 300), (int) round($ht / 2.54 * 300), [31, 157, 85], 'FOFANA', $h('-'.(9 - $i).' days'), $agent);
            }
        }

        // Sacko : 2 lignes sans fichier, il « passe samedi ».
        $sacko = $this->commande('0377', 900056, $agent, $h('-12 days')->setTime(11, 5), $jour->modify('+1 day'));
        $this->ligne($sacko, 900009, 'Papier couleur A4', 0.21, 0.297, 50, 250);
        $this->ligne($sacko, 900009, 'Papier couleur A4 (verso)', 0.21, 0.297, 50, 250);

        // Sans numéro de client : on ne peut pas le relancer.
        $comptoir = $this->commande('0379', $sansNumero, $agent, $h('-11 days')->setTime(15, 20), $jour->modify('+3 days'));
        $this->ligne($comptoir, 900002, 'Vinyle', 0.73, 3.46, 16, 6500);

        // Diallo : depuis 2 jours, relancé J+1, il a lu.
        $diallo = $this->commande('0424', 900121, $agent, $h('-2 days')->setTime(10, 0), $jour->modify('+4 days'));
        $this->ligne($diallo, 900011, 'Impression DTF A4', 0.21, 0.297, 9, 3500);

        // Kakémono à contrôler, à livrer aujourd'hui.
        $koita = $this->commande('0430', 900037, $agent, $h('-1 day')->setTime(16, 30), $jour->setTime(14, 0));
        $ligneKoita = $this->ligne($koita, 900006, 'Kakémono VIP', 1.2, 2.0, 1, 25000);
        $this->fichier($ligneKoita, 'kakemono-vip_120x200.png', 360, 600, [15, 69, 144], 'VIP', $h('-2 hours -10 minutes'), $agent);

        // BAT chez Samaké, envoyé ce matin.
        $samake = $this->commande('0426', 900003, $agent, $h('-3 days')->setTime(9, 0), $jour->modify('+2 days'));
        $ligneSamake = $this->ligne($samake, 900011, 'Impression DTF A4', 0.21, 0.297, 12, 3500);
        $fichierSamake = $this->fichier($ligneSamake, 'dtf-samake_A4_v2.png', 248, 351, [214, 51, 132], 'DTF', $h('-1 day'), $agent);

        // Vinyle de Coulibaly : BAT validé, prêt pour la machine.
        $coulibaly = $this->commande('0428', 900064, $agent, $h('-4 days')->setTime(14, 0), $jour->modify('+1 day'));
        $ligneCoulibaly = $this->ligne($coulibaly, 900002, 'Vinyle découpe', 1.0, 1.0, 1, 12000);
        $ligneCoulibaly->setMachine($this->em->find(Machines::class, 900004));
        $fichierCoulibaly = $this->fichier($ligneCoulibaly, 'vinyle-coulibaly_100x100.png', 400, 400, [31, 157, 85], 'VINYL', $h('-2 days'), $agent);

        $this->em->flush();

        $bat = (new ControlePrePresse())->setCommandeDetail($ligneSamake)->setFichierDejaTraite(true)->setBatNecessaire(true);
        $bat->addFichier($fichierSamake);
        $bat->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT);
        $ligneSamake->addControlePrePresse($bat);
        $this->em->persist($bat);
        $valide = (new ControlePrePresse())->setCommandeDetail($ligneCoulibaly)->setFichierDejaTraite(true)
            ->setDimensionsConformes(true)->setMargesSecuriteConformes(true)->setResolutionConforme(true)->setQuantiteConforme(true);
        $valide->addFichier($fichierCoulibaly);
        if ($seydou instanceof User) {
            $valide->enregistrerTraitement($seydou);
        }
        $valide->setStatut(ControlePrePresse::STATUT_VALIDE);
        $ligneCoulibaly->addControlePrePresse($valide);
        $this->em->persist($valide);

        // Relances déjà faites (maquette PQ04).
        $this->relance($fofana, EtapesRelance::J1, $h('-11 days')->setTime(9, 0), $agent);
        $this->relance($fofana, EtapesRelance::J3, $h('-9 days')->setTime(9, 0), $agent);
        $this->relance($sacko, EtapesRelance::J1, $h('-11 days')->setTime(9, 5), $agent);
        $this->relance($sacko, EtapesRelance::J3, $h('-9 days')->setTime(9, 5), $agent, 'je passe samedi');
        $this->relance($diallo, EtapesRelance::J1, $h('-1 day')->setTime(10, 0), $agent);
        $this->em->flush();

        // Dates de la maquette : BAT envoyé ce matin, validé à 10 h 40.
        $connexion->executeStatement('UPDATE controle_pre_presse SET cree_le = ?, traite_le = ? WHERE id = ?', [$h('-1 day')->format('Y-m-d H:i:s'), $jour->setTime(9, 12)->format('Y-m-d H:i:s'), $bat->getId()]);
        $connexion->executeStatement('UPDATE controle_pre_presse SET cree_le = ?, traite_le = ?, valide_le = ? WHERE id = ?', [$h('-2 days')->format('Y-m-d H:i:s'), $jour->setTime(10, 30)->format('Y-m-d H:i:s'), min($maintenant, $jour->setTime(10, 40))->format('Y-m-d H:i:s'), $valide->getId()]);

        $io->success('Prépresse : 7 commandes de démonstration (CMD-PQ…), dont Fofana avec 2 fichiers sur 6.');

        return Command::SUCCESS;
    }

    private function commande(string $numero, int $client, User $agent, \DateTimeImmutable $le, \DateTimeImmutable $livraison): Commandes
    {
        $commande = (new Commandes())
            ->setNumero(self::PREFIXE.$numero)
            ->setClients($this->em->find(Clients::class, $client))
            ->setAgents($agent)
            ->setDateCommande($le)
            ->setDateLivraison($livraison)
            ->setStatut(true)
            ->setEtat(true)
            ->setModeLivraison(Commandes::LIVRAISON_RETRAIT);
        $this->em->persist($commande);

        return $commande;
    }

    private function ligne(Commandes $commande, int $configuration, string $designation, float $largeur, float $hauteur, int $quantite, int $prix): CommandesDetails
    {
        $config = $this->em->find(ProduitConfiguration::class, $configuration);
        $ligne = (new CommandesDetails())
            ->setModeConfiguration('manuel')
            ->setProduitConfiguration($config)
            ->setProduit($config?->getProduit())
            ->setTypeImpression($config?->getTypeImpression())
            ->setSupport($config?->getSupport())
            ->setDesignation($designation)
            ->setModeCalcul('unite')
            ->setQuantite($quantite)
            ->setPrixUnitaire($prix)
            ->setTva(0)
            ->setLargeur((string) $largeur)
            ->setLongueur((string) $hauteur)
            ->setPrePresseNecessaire(true);
        $ligne->calculerSurface();
        $ligne->calculerTotaux(true);
        $ligne->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
        $commande->addCommandesDetail($ligne);
        $commande->recalculerTotaux();

        return $ligne;
    }

    /**
     * Fichier fictif (image dessinée à 300 dpi), rangé comme un fichier de commande.
     *
     * @param array{int, int, int} $couleur
     */
    private function fichier(CommandesDetails $ligne, string $nomOriginal, int $largeur, int $hauteur, array $couleur, string $texte, \DateTimeImmutable $le, User $par): CommandeDetailFichier
    {
        $dossier = $this->projet.'/'.self::DOSSIER;
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        $stockage = bin2hex(random_bytes(12)).'.png';
        if (\function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($largeur, $hauteur);
            imagefilledrectangle($im, 0, 0, $largeur, $hauteur, (int) imagecolorallocate($im, ...$couleur));
            imagefilledellipse($im, intdiv($largeur, 2), intdiv($hauteur, 2), intdiv($largeur * 2, 3), intdiv($hauteur * 2, 3), (int) imagecolorallocatealpha($im, 255, 255, 255, 70));
            imagestring($im, 5, 8, 8, $texte, (int) imagecolorallocate($im, 255, 255, 255));
            imagepng($im, $dossier.'/'.$stockage);
            imagedestroy($im);
        } else {
            file_put_contents($dossier.'/'.$stockage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', true));
        }
        $fichier = (new CommandeDetailFichier())
            ->setCommandeDetail($ligne)
            ->setNomOriginal($nomOriginal)
            ->setJetonUpload(bin2hex(random_bytes(16)))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setNomStockage($stockage)
            ->setChemin('uploads/prepresse/demo-prepresse/'.$stockage)
            ->setTypeMime('image/png')
            ->setTaille((int) filesize($dossier.'/'.$stockage))
            ->setStatut(CommandeDetailFichier::STATUT_TERMINE)
            ->setTermineLe($le)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_ORIGINAL)
            ->setAjoutePar($par)
            ->setCreeLe($le);
        $ligne->addFichier($fichier);
        $this->em->persist($fichier);

        return $fichier;
    }

    private function relance(Commandes $commande, string $etape, \DateTimeImmutable $le, ?User $par, ?string $reponse = null, string $canal = RelanceFichier::WHATSAPP): void
    {
        $manquants = RelancesFichiers::manquants($commande);
        $message = $this->relances->message($commande, $manquants);
        $relance = (new RelanceFichier($commande, $etape, $canal, $le, $par))
            ->preparer(\count($manquants), $message, RelanceFichier::WHATSAPP === $canal ? RelancesFichiers::whatsapp($commande->getClients(), $message) : null)
            ->envoyer($par, $le);
        if (null !== $reponse) {
            $relance->noterReponse($reponse, $le->modify('+3 hours'));
        }
        $this->em->persist($relance);
    }

    /** Copie d'un client fictif, sans téléphone : la commande « client non renseigné ». */
    private function clientSansNumero(): int
    {
        $connexion = $this->em->getConnection();
        $modele = $connexion->fetchAssociative('SELECT * FROM clients WHERE id = 900011');
        if (false !== $modele) {
            $connexion->insert('clients', array_merge($modele, array_intersect_key([
                'id' => self::CLIENT_SANS_NUMERO, 'code' => 'DEMO-PQ01', 'nom' => 'Comptoir', 'prenom' => 'Client',
                'raison_sociale' => null, 'telephone' => '', 'telephone2' => null, 'email' => null,
                'public_id' => Uuid::v4()->toBinary(),
            ], $modele)));
        }

        return self::CLIENT_SANS_NUMERO;
    }
}
