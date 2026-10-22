<?php

namespace App\Command;

use App\Service\Site\AtelierDirect;
use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\CauseSynchro;
use App\Service\Synchro\Signature;
use App\Service\Synchro\Synchro;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agent de synchronisation de l'ERP local (docs/synchronisation.md).
 *
 * La tâche planifiée le lance chaque minute. --boucle le garde en marche
 * et refait un cycle toutes les 30 secondes (service sur le serveur local).
 */
#[AsCommand(name: 'app:synchro', description: 'Synchronise l\'ERP local avec le site en ligne (envoi puis réception).')]
final class SynchroCommand extends Command
{
    public function __construct(
        private readonly AgentSynchro $agent,
        private readonly Synchro $synchro,
        private readonly ?AtelierDirect $atelierDirect = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('initialiser', null, InputOption::VALUE_OPTIONAL, 'Met en file tout ce qui part vers le site : catalogue, clients, devis, commandes, factures, paiements (premier chargement). Avec un type (ex. --initialiser=configuration) : ce type seulement', false)
            ->addOption('nouveau-site', null, InputOption::VALUE_NONE, 'Le site repart d\'une base vide : remet à zéro le curseur de réception et renvoie tout (comme --initialiser)')
            ->addOption('boucle', null, InputOption::VALUE_NONE, 'Tourne sans s\'arrêter, un cycle toutes les 30 s')
            ->addOption('nouvelle-cle', null, InputOption::VALUE_NONE, 'Affiche une clé SYNC_CLE à copier dans .env.local des deux machines')
            ->addOption('empreinte', null, InputOption::VALUE_NONE, 'Affiche l\'empreinte de SYNC_CLE et l\'heure de cette machine, à comparer avec l\'autre machine (la clé ne s\'affiche pas)')
            ->addOption('diagnostic', null, InputOption::VALUE_NONE, 'Dit pourquoi la file n\'avance pas : réglage, internet, adresse et certificat du site, signature, version du site, file d\'attente, agent')
            ->addOption('relancer-bloques', null, InputOption::VALUE_NONE, 'Redonne 10 essais aux opérations bloquées (après avoir réglé la cause), puis fait un cycle');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('nouvelle-cle')) {
            $io->writeln('SYNC_CLE='.bin2hex(random_bytes(32)));
            $io->note('À mettre dans .env.local de l\'ERP local ET du site. Ne jamais l\'envoyer par message ni la mettre dans le dépôt.');

            return Command::SUCCESS;
        }
        if ($input->getOption('empreinte')) {
            // Sur les deux machines : même empreinte et même longueur, heures à moins de 5 minutes.
            $cle = $this->synchro->cle();
            $io->writeln(\sprintf('Empreinte de SYNC_CLE : %s · longueur %d · heure de cette machine : %s UTC', $cle === '' ? 'aucune clé' : Signature::empreinte($cle), \strlen($cle), gmdate('H:i:s')));

            return Command::SUCCESS;
        }
        if ($this->synchro->estEnLigne()) {
            $io->error('Cette commande tourne sur l\'ERP local : c\'est lui qui appelle le site.');

            return Command::FAILURE;
        }
        if ($input->getOption('diagnostic')) {
            return $this->diagnostic($io);
        }
        if ($input->getOption('relancer-bloques')) {
            $io->note($this->synchro->relancerBloques().' opération(s) bloquée(s) remise(s) en file.');
        }
        if ($input->getOption('nouveau-site')) {
            // Base du site recréée : ses événements repartent de 1, l'ancien curseur les sauterait tous.
            $this->synchro->noterEtat(AgentSynchro::ETAT_CURSEUR, '0');
            $this->synchro->noterEtat(AgentSynchro::ETAT_SCHEMA, null);
            $io->note('Curseur de réception remis à zéro : le site est traité comme neuf.');
            $input->setOption('initialiser', null);
        }
        if (false !== ($type = $input->getOption('initialiser'))) {
            $io->success($this->synchro->initialiser($type ?: null).' données mises en file pour le site.');
        }

        do {
            // « En direct de l'atelier » de l'accueil : instantané publié avant l'envoi (AtelierDirect).
            try {
                $this->atelierDirect?->publier(new \DateTimeImmutable());
            } catch (\Throwable $e) {
                $io->warning('Atelier en direct non publié : '.$e->getMessage());
            }
            $r = $this->agent->cycle();
            $reste = $this->synchro->enAttente();
            $ligne = \sprintf('[%s] %s : %d envoyées, %d reçues%s%s%s%s', date('H:i:s'), $r['statut'], $r['envoyes'], $r['recus'], $r['conflits'] > 0 ? ', '.$r['conflits'].' conflit(s)' : '', $reste > 0 ? ' · '.$reste.' en attente' : '', $r['message'] ? ' · '.$r['message'] : '', 'ok' === $r['statut'] && $reste > 0 && 0 === $r['envoyes'] ? ' ·'.CauseSynchro::pourquoiRienNePart($reste, 0, \count($this->synchro->bloques()), $r['attente'] ?? null) : (isset($r['attente']) ? ' · '.$r['attente'] : ''));
            // Un cycle sans rien à faire ne remplit pas le journal de la tâche planifiée ; une file qui n'avance pas, si.
            $rien = $r['statut'] === 'ok' && $r['envoyes'] === 0 && $r['recus'] === 0 && $reste === 0;
            $io->writeln($ligne, $rien ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
            if ($input->getOption('boucle')) {
                sleep(30);
            }
        } while ($input->getOption('boucle'));

        return \in_array($r['statut'], ['ok', 'occupe', 'non_configuree'], true) ? Command::SUCCESS : Command::FAILURE;
    }

    /** Chaque point vérifié séparément, avec ce qu'il faut faire quand il ne va pas. */
    private function diagnostic(SymfonyStyle $io): int
    {
        $maintenant = new \DateTimeImmutable();
        $io->title('Diagnostic de la synchronisation');
        $ok = static fn (string $t) => $io->writeln(' <info>✔</info> '.$t);
        $ko = static fn (string $t, string $conseil = '') => $io->writeln(' <error>✘</error> '.$t.('' !== $conseil ? "\n     → ".$conseil : ''));

        if ($this->synchro->estEnLigne()) {
            $io->error('Cette machine est le site (APP_EN_LIGNE=1) : lancez le diagnostic sur l\'ERP local.');

            return Command::FAILURE;
        }
        if (!$this->synchro->estConfiguree()) {
            $ko('SYNC_URL ou SYNC_CLE manquant dans .env.local', 'voir docs/synchronisation.md « Mise en route »');

            return Command::FAILURE;
        }
        $url = $this->synchro->url();
        $hote = (string) parse_url($url, \PHP_URL_HOST);
        $ok('Adresse du site : '.$url);
        if (str_starts_with($hote, 'www.')) {
            $ko('L\'adresse commence par www', 'le site est succesimprim.com sans www : SYNC_URL=https://succesimprim.com');
        }
        if (!str_starts_with($url, 'https://')) {
            $ko('L\'adresse n\'est pas en https', 'SYNC_URL=https://…');
        }

        $internet = CauseSynchro::internetRepond();
        $internet ? $ok('Internet répond depuis cet ordinateur') : $ko('Internet ne répond pas depuis cet ordinateur', CauseSynchro::conseil(CauseSynchro::INTERNET));
        $ip = gethostbyname($hote);
        $ip !== $hote || false !== filter_var($hote, \FILTER_VALIDATE_IP) ? $ok(\sprintf('%s se résout en %s', $hote, $ip)) : $ko($hote.' ne se résout pas (DNS)', CauseSynchro::conseil(CauseSynchro::DNS, $hote));

        // Le cycle de l'agent commence par un ping signé : il dit si le certificat, la clé et l'heure vont.
        $libre = !$this->agent->cycleEnCours();
        $libre ? $ok('Aucun cycle en cours') : $io->writeln(' <comment>…</comment> Un cycle est en cours (tâche planifiée ou --boucle) : normal s\'il tourne, sinon un processus app:synchro est resté bloqué.');

        $cycle = $this->synchro->dateEtat(AgentSynchro::ETAT_CYCLE);
        $reussite = $this->synchro->dateEtat(AgentSynchro::ETAT_REUSSITE);
        if (null === $cycle || $maintenant->getTimestamp() - $cycle->getTimestamp() >= CauseSynchro::AGENT_ARRETE_MINUTES * 60) {
            $ko('Dernier passage de l\'agent : '.($cycle?->format('d/m H:i') ?? 'jamais'), CauseSynchro::conseil(CauseSynchro::AGENT_ARRETE));
        } else {
            $ok('L\'agent tourne (dernier passage '.$cycle->format('H:i').')');
        }
        $io->writeln('   Dernière synchro réussie : '.($reussite?->format('d/m H:i') ?? 'jamais'));
        if (null !== ($erreur = $this->synchro->etat(AgentSynchro::ETAT_ERREUR))) {
            $cause = $this->synchro->etat(AgentSynchro::ETAT_CAUSE);
            $ko('Dernière erreur : '.$erreur, CauseSynchro::conseil($cause, $hote));
        }

        $attente = $this->synchro->enAttente();
        $bloques = \count($this->synchro->bloques());
        $io->section(\sprintf('File d\'envoi : %d opération(s), dont %d bloquée(s) · %d par lot', $attente, $bloques, AgentSynchro::tailleLot($this->synchro->etat(AgentSynchro::ETAT_TAILLE_LOT))));
        if ($attente > 0) {
            $parType = $this->synchro->enAttenteParType();
            $io->writeln('   Par type : '.implode(', ', array_map(static fn (string $t, int $n): string => $t.' '.$n, array_keys($parType), $parType)));
        }
        if (null !== ($tete = $this->synchro->teteDeFile())) {
            $io->writeln(\sprintf('   Prochaine à partir : n° %d, %s (%s) du %s · %d essai(s)%s', $tete['id'], $tete['type'], $tete['operation'], $tete['creeLe']->format('d/m H:i'), $tete['tentatives'], null !== $tete['erreur'] ? ' · '.$tete['erreur'] : ''));
        }
        if ($bloques > 0) {
            $io->writeln('   → Bloquées : réglez la cause, puis « php bin/console app:synchro --relancer-bloques », ou écartez-les dans Réglages › Sécurité › Synchronisation.');
        }

        $io->section('Essai de cycle maintenant');
        $r = $this->agent->cycle();
        $reste = $this->synchro->enAttente();
        if ('ok' === $r['statut']) {
            $ok(\sprintf('Cycle réussi : %d envoyée(s), %d reçue(s), %d encore en file%s', $r['envoyes'], $r['recus'], $reste, isset($r['attente']) ? ' · '.$r['attente'] : ''));
            $schemaLocal = $this->synchro->schemaLocal();
            $schemaSite = $this->synchro->etat(AgentSynchro::ETAT_SCHEMA);
            if (null !== $schemaLocal && null !== $schemaSite && $schemaLocal !== $schemaSite) {
                $ko(\sprintf('Versions différentes : local %s, site %s', self::court($schemaLocal), self::court($schemaSite)), 'mettre le site à jour (même code, puis doctrine:migrations:migrate en ligne) : sinon des opérations peuvent être refusées');
            } elseif (null !== $schemaSite) {
                $ok('Même version des deux côtés ('.self::court($schemaSite).')');
            }
            if ($reste > 0) {
                $io->writeln('   La file se vide au rythme d\'un cycle par minute : relancez « php bin/console app:synchro » pour accélérer.');
            }
        } elseif ('occupe' === $r['statut']) {
            $io->writeln(' <comment>…</comment> Un cycle tourne déjà : relancez le diagnostic dans une minute.');
        } else {
            $ko((string) $r['message'], CauseSynchro::conseil($r['cause'] ?? null, $hote));
        }

        return 'erreur' === $r['statut'] ? Command::FAILURE : Command::SUCCESS;
    }

    private static function court(string $schema): string
    {
        return (string) preg_replace('/^.*Version/', '', $schema);
    }
}
