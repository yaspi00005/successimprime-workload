<?php

namespace App\Command;

use App\Entity\RapportHebdomadaire;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Objectifs;
use App\Service\Statistiques\RapportSemaine;
use App\Service\Statistiques\ReglagesRapport;
use App\Service\Statistiques\SemaineIso;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Rapport de la semaine (docs/statistiques.md), à lancer chaque heure par
 * le cron de l'ERP local : au jour et à l'heure des Réglages (lundi 8 h
 * par défaut), chaque destinataire reçoit une notification dans
 * l'application qui mène à sa version du rapport de la semaine passée —
 * la Direction les chiffres de l'entreprise, un agent les siens, selon
 * ses droits. Une seule fois par semaine : la ligne rapport_hebdomadaire
 * est posée avant les notifications. Le message ne contient aucun
 * montant ; le PDF par e-mail seulement si l'envoi d'e-mails est
 * configuré et coché dans les Réglages. Jamais de SMS ni de WhatsApp.
 */
#[AsCommand(name: 'app:rapport-hebdomadaire', description: 'Envoie le rapport de la semaine passée (notification et, si configuré, e-mail), une fois par semaine.')]
final class RapportHebdomadaireCommand extends Command
{
    public function __construct(
        private readonly RapportSemaine $rapport,
        private readonly ReglagesRapport $reglages,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
        private readonly RoleHierarchyInterface $hierarchie,
        private readonly AccesEnLigne $acces,
        private readonly ExportPdf $pdf,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('semaine', null, InputOption::VALUE_REQUIRED, 'Semaine ISO à envoyer (ex. 2026-W41), sans attendre le jour et l\'heure des Réglages')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les destinataires sans rien envoyer ni enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();
        $essai = (bool) $input->getOption('dry-run');

        if ($this->acces->estEnLigne()) {
            $io->error('Le rapport de la semaine se calcule sur l\'ERP local, pas sur le serveur en ligne.');

            return Command::FAILURE;
        }

        $demandee = $input->getOption('semaine');
        if (null !== $demandee) {
            $semaine = SemaineIso::lire((string) $demandee);
            if (null === $semaine) {
                $io->error(\sprintf('Semaine « %s » invalide : format attendu 2026-W41.', $demandee));

                return Command::INVALID;
            }
            if ($semaine->fin() > SemaineIso::contenant($maintenant)->debut) {
                $io->error(\sprintf('La semaine %s n\'est pas terminée.', $semaine->code()));

                return Command::INVALID;
            }
        } else {
            $semaine = SemaineIso::precedente($maintenant);
            if (!$this->reglages->actif()) {
                $io->writeln('Rapport de la semaine désactivé dans les Réglages.');

                return Command::SUCCESS;
            }
            $moment = ReglagesRapport::momentEnvoi(SemaineIso::contenant($maintenant)->debut, $this->reglages->jour(), $this->reglages->heure());
            if ($maintenant < $moment) {
                $io->writeln(\sprintf('Envoi prévu le %s : rien à faire maintenant.', $moment->format('d/m/Y à H:i')));

                return Command::SUCCESS;
            }
        }

        $deja = $this->em->getRepository(RapportHebdomadaire::class)->findOneBy(['semaine' => $semaine->code()]);
        if (null !== $deja) {
            $io->writeln(\sprintf('Rapport de la %s déjà envoyé le %s.', mb_strtolower($semaine->titre()), $deja->getEnvoyeLe()->format('d/m/Y à H:i')));

            return Command::SUCCESS;
        }

        $destinataires = $this->destinataires($semaine, $maintenant);
        $courriel = $this->courrielPossible();
        $io->writeln(\sprintf('%s (%s) : %d destinataire%s%s.', $semaine->titre(), $semaine->libelle(), \count($destinataires), \count($destinataires) > 1 ? 's' : '', $courriel ? ', PDF par e-mail' : ''));

        if ($essai) {
            $io->table(['Personne', 'Version', 'E-mail'], array_map(fn (array $d): array => [
                Objectifs::nomAgent($d['utilisateur']),
                self::version($d['droits']),
                $courriel && null !== self::adresse($d['utilisateur']) ? self::adresse($d['utilisateur']) : '—',
            ], $destinataires));
            $io->note('Essai (--dry-run) : rien n\'a été envoyé ni enregistré.');

            return Command::SUCCESS;
        }

        // La trace d'abord : une tâche lancée en même temps bute sur la contrainte unique.
        $trace = new RapportHebdomadaire($semaine->code(), $semaine->debut, $maintenant);
        try {
            $this->em->persist($trace);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $io->writeln('Rapport déjà en cours d\'envoi par une autre tâche.');

            return Command::SUCCESS;
        }

        $message = \sprintf('Rapport de la %s (%s) disponible', mb_strtolower($semaine->titre()), $semaine->libelle());
        foreach ($destinataires as $d) {
            $this->notifications->notifierUtilisateur($d['utilisateur'], $message, 'app_rapport_semaine', ['semaine' => $semaine->code()]);
        }
        $this->em->flush();

        $envoyes = 0;
        if ($courriel) {
            foreach ($destinataires as $d) {
                $envoyes += $this->envoyerCourriel($d['utilisateur'], $d['droits'], $semaine, $maintenant) ? 1 : 0;
            }
        }

        $trace->terminer(\count($destinataires), $envoyes);
        $this->em->flush();
        $io->success(\sprintf('%d notification%s envoyée%s, %d e-mail%s.', \count($destinataires), \count($destinataires) > 1 ? 's' : '', \count($destinataires) > 1 ? 's' : '', $envoyes, $envoyes > 1 ? 's' : ''));

        return Command::SUCCESS;
    }

    /** Libellé de la version reçue, pour l'essai. */
    public static function version(array $droits): string
    {
        $sections = RapportSemaine::sections($droits);

        return match (true) {
            $sections['montants'] => 'Direction (entreprise et montants)',
            $sections['entreprise'] => 'Entreprise sans montants de vente',
            default => 'Ses propres chiffres',
        };
    }

    /**
     * Comptes actifs qui reçoivent le rapport, avec leurs droits calculés
     * comme à la connexion (hiérarchie des rôles comprise).
     *
     * @return list<array{utilisateur: User, droits: array<string, bool>}>
     */
    private function destinataires(SemaineIso $semaine, \DateTimeImmutable $maintenant): array
    {
        $pourDirection = $this->reglages->pourDirection();
        $pourAgents = $this->reglages->pourAgents();
        $liste = [];
        foreach ($this->em->getRepository(User::class)->findBy(['actif' => true], ['id' => 'ASC']) as $u) {
            $droits = DroitsStatistiques::depuisRoles($this->hierarchie->getReachableRoleNames($u->getRoles()));
            if ($this->rapport->estDestinataire($u, $droits, $semaine, $pourDirection, $pourAgents, $maintenant)) {
                $liste[] = ['utilisateur' => $u, 'droits' => $droits];
            }
        }

        return $liste;
    }

    /** E-mail coché dans les Réglages et réellement configuré (MAILER_DSN, expéditeur). */
    private function courrielPossible(): bool
    {
        return $this->reglages->parCourriel() && '' !== trim($this->expediteur) && '' !== trim($this->mailerDsn) && !str_starts_with($this->mailerDsn, 'null://');
    }

    private static function adresse(User $u): ?string
    {
        $email = trim((string) $u->getEmploye()?->getEmail());

        return false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Sa version en PDF ; le texte du message ne donne aucun chiffre. */
    private function envoyerCourriel(User $u, array $droits, SemaineIso $semaine, \DateTimeImmutable $maintenant): bool
    {
        $adresse = self::adresse($u);
        if (null === $adresse) {
            return false;
        }
        try {
            $pdf = $this->pdf->rendre('statistiques/export/rapport_semaine_pdf.html.twig', [
                'r' => $this->rapport->donnees($u, $droits, $semaine, $maintenant),
                'semaine' => $semaine,
            ], $maintenant, 'envoi automatique');
            $this->mailer->send((new Email())
                ->from($this->expediteur)
                ->to($adresse)
                ->subject(\sprintf('Rapport de la %s · Succès Imprim', mb_strtolower($semaine->titre())))
                ->text(\sprintf("Bonjour,\n\nVotre rapport de la %s (%s) est joint à ce message et disponible dans l'application (Accueil › Rapport de la semaine).\n\nDocument interne, confidentiel.\n\nSuccès Imprim", mb_strtolower($semaine->titre()), $semaine->libelle()))
                ->attach($pdf, \sprintf('rapport-semaine-%s.pdf', strtolower($semaine->code())), 'application/pdf'));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Rapport de la semaine non envoyé par e-mail : {erreur}', ['erreur' => $e->getMessage()]);

            return false;
        }
    }
}
