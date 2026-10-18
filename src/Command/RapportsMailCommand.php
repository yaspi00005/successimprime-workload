<?php

namespace App\Command;

use App\Entity\EnvoiRapport;
use App\Security\AccesEnLigne;
use App\Service\Rapports\DestinatairesRapports;
use App\Service\Rapports\DroitsRapport;
use App\Service\Rapports\EnvoiRapports;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\ReglagesRapportsMail;
use App\Service\Statistiques\Objectifs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rapports du jour, de la semaine et du mois par e-mail à l'administration
 * (docs/rapports-mail.md). À lancer chaque quart d'heure sur l'ERP local
 * (tâche planifiée Windows ou cron) : chaque rapport part à son heure des
 * Réglages, une seule fois par période, et un rapport manqué (ordinateur
 * éteint) part au redémarrage s'il n'est pas trop vieux. Les destinataires
 * en échec (réseau coupé) sont repris trois fois au plus, toutes les 30
 * minutes. Jamais de SMS ni de WhatsApp.
 */
#[AsCommand(name: 'app:rapports-mail', description: 'Envoie les rapports du jour, de la semaine et du mois par e-mail, une fois par période.')]
final class RapportsMailCommand extends Command
{
    public function __construct(
        private readonly EnvoiRapports $envois,
        private readonly DestinatairesRapports $destinataires,
        private readonly ReglagesRapportsMail $reglages,
        private readonly AccesEnLigne $acces,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'jour, semaine ou mois (sans : les trois, chacun à son heure)')
            ->addOption('periode', null, InputOption::VALUE_REQUIRED, 'Période terminée à envoyer tout de suite : 2026-10-17, 2026-W42 ou 2026-10')
            ->addOption('reprendre', null, InputOption::VALUE_NONE, 'Avec --periode : renvoie aux seuls destinataires en échec')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les destinataires et leur version, sans rien envoyer ni enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        if ($this->acces->estEnLigne()) {
            $io->error('Les rapports par e-mail partent de l\'ERP local, pas du serveur en ligne.');

            return Command::FAILURE;
        }

        $type = $input->getArgument('type');
        if (null !== $type && !PeriodeRapport::estType((string) $type)) {
            $io->error(\sprintf('Rapport « %s » inconnu : jour, semaine ou mois.', $type));

            return Command::INVALID;
        }
        $code = $input->getOption('periode');
        if (null !== $code && null === $type) {
            $io->error('--periode demande le rapport : app:rapports-mail jour --periode=2026-10-17.');

            return Command::INVALID;
        }
        $essai = (bool) $input->getOption('dry-run');

        if ($essai) {
            $this->afficherDestinataires($io);
        } elseif (!$this->reglages->courrielConfigure()) {
            // Pas une erreur : la tâche planifiée tourne avant que la messagerie soit réglée.
            $io->writeln('Envoi d\'e-mails pas réglé (MAILER_DSN et MAILER_FROM_ADDRESS dans .env.local) : aucun rapport envoyé.');

            return Command::SUCCESS;
        }

        // Une période précise, à la main.
        if (null !== $code) {
            $p = PeriodeRapport::lire((string) $type, (string) $code);
            if (null === $p) {
                $io->error(\sprintf('Période « %s » illisible pour le rapport %s : 2026-10-17, 2026-W42 ou 2026-10.', $code, PeriodeRapport::ADJECTIFS[$type]));

                return Command::INVALID;
            }
            if (!$p->terminee($maintenant)) {
                $io->error(\sprintf('La période %s n\'est pas terminée.', $p->libelle()));

                return Command::INVALID;
            }

            return $this->traiter($io, $p, $maintenant, $essai, (bool) $input->getOption('reprendre'), true) ? Command::SUCCESS : Command::FAILURE;
        }

        $reussi = true;
        foreach (null === $type ? array_keys(PeriodeRapport::TYPES) : [(string) $type] as $t) {
            if (!$this->reglages->actif($t)) {
                $io->writeln(\sprintf('%s : désactivé dans les Réglages.', PeriodeRapport::TYPES[$t]));
                continue;
            }
            $p = $this->reglages->aEnvoyer($t, $maintenant);
            if (null === $p) {
                $io->writeln(\sprintf('%s : prochain envoi le %s.', PeriodeRapport::TYPES[$t], $this->reglages->prochainEnvoi($t, $maintenant)->format('d/m/Y à H:i')));
                continue;
            }
            $reussi = $this->traiter($io, $p, $maintenant, $essai, false, false) && $reussi;
        }

        return $reussi ? Command::SUCCESS : Command::FAILURE;
    }

    /** Envoie (ou reprend) le rapport d'une période ; faux si personne ne l'a reçu. */
    private function traiter(SymfonyStyle $io, PeriodeRapport $p, \DateTimeImmutable $maintenant, bool $essai, bool $reprendre, bool $aLaMain): bool
    {
        $deja = $this->envois->envoiDe($p);
        if ($essai) {
            $io->writeln(\sprintf('%s · %s : %s.', $p->titre(), $p->libelle(), null === $deja ? 'serait envoyé maintenant' : 'déjà envoyé le '.$deja->getDemarreLe()->format('d/m/Y à H:i')));

            return true;
        }

        if (null !== $deja) {
            $relance = $aLaMain ? $reprendre && [] !== $deja->aReprendre() : EnvoiRapports::aReprendre($deja, $maintenant);
            if (!$relance) {
                $io->writeln(\sprintf('%s · %s : déjà envoyé le %s (%s).', $p->titre(), $p->libelle(), $deja->getDemarreLe()->format('d/m/Y à H:i'), mb_strtolower($deja->getLibelleStatut())));

                return true;
            }
            $envoi = $this->envois->reprendre($deja, $maintenant);
            $io->writeln(\sprintf('%s · %s : reprise des destinataires en échec.', $p->titre(), $p->libelle()));
        } else {
            $envoi = $this->envois->envoyer($p, $aLaMain ? EnvoiRapport::COMMANDE : EnvoiRapport::AUTO, $maintenant);
            if (null === $envoi) {
                $io->writeln(\sprintf('%s · %s : déjà en cours d\'envoi par une autre tâche.', $p->titre(), $p->libelle()));

                return true;
            }
        }

        $envoyes = $envoi->nombre(EnvoiRapport::LIGNE_ENVOYE);
        $total = \count($envoi->getLignes());
        $message = \sprintf('%s · %s : %d e-mail%s envoyé%s sur %d destinataire%s.', $p->titre(), $p->libelle(), $envoyes, $envoyes > 1 ? 's' : '', $envoyes > 1 ? 's' : '', $total, $total > 1 ? 's' : '');
        if (EnvoiRapport::ENVOYE === $envoi->getStatut()) {
            $io->success($message);

            return true;
        }
        $io->warning($message.' Détail : Accueil › Rapports par e-mail.');

        return $envoyes > 0;
    }

    private function afficherDestinataires(SymfonyStyle $io): void
    {
        $liste = $this->destinataires->liste();
        $io->writeln(\sprintf('%d destinataire%s%s :', \count($liste), \count($liste) > 1 ? 's' : '', null === $this->reglages->destinatairesChoisis() ? ' (la direction, liste pas encore choisie)' : ''));
        $io->table(['Personne', 'Adresse', 'Version'], array_map(static fn (array $d): array => [
            Objectifs::nomAgent($d['utilisateur']),
            $d['adresse'] ?? 'pas d\'adresse e-mail',
            DroitsRapport::version($d['droits']),
        ], $liste));
        if (!$this->reglages->courrielConfigure()) {
            $io->note('Envoi d\'e-mails pas réglé (MAILER_DSN et MAILER_FROM_ADDRESS) : rien ne partirait.');
        }
        $io->note('Essai (--dry-run) : rien n\'est envoyé ni enregistré.');
    }
}
