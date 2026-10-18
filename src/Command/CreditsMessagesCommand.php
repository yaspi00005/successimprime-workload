<?php

namespace App\Command;

use App\Security\AccesEnLigne;
use App\Service\Messagerie\AlertesMessages;
use App\Service\Messagerie\CreditsMessages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * À lancer chaque heure sur l'ERP local (docs/messagerie-marketing.md) :
 * relit le solde SMS chez Orange quand la lecture gardée a plus d'une
 * heure, puis prévient la direction (cloche, jamais par SMS) quand il
 * reste moins de SMS que le seuil, ou que le pack SMS ou l'abonnement
 * e-mail finit bientôt. Une seule notification par situation.
 */
#[AsCommand(name: 'app:messagerie:credits', description: 'Crédits SMS et e-mail : relit le solde Orange et prévient la direction (seuil bas, fin proche).')]
final class CreditsMessagesCommand extends Command
{
    public function __construct(
        private readonly CreditsMessages $credits,
        private readonly AlertesMessages $alertes,
        private readonly AccesEnLigne $acces,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->acces->estEnLigne()) {
            $output->writeln('Sur le site en ligne, rien à faire : les alertes partent de l\'ERP local, où arrive l\'historique des envois.');

            return Command::SUCCESS;
        }
        $maintenant = new \DateTimeImmutable();
        $sms = $this->credits->sms($maintenant);
        $output->writeln(match ($sms['source']) {
            'orange' => \sprintf('SMS : %s disponibles (lu chez Orange le %s).', $sms['unites'] ?? '?', $sms['luLe']?->format('d/m/Y H:i')),
            'manuel' => \sprintf('SMS : environ %s disponibles (solde saisi à la main).', $sms['unites'] ?? '?'),
            default => 'SMS : solde inconnu (clés Orange absentes et aucun solde saisi).',
        });
        if (null !== $sms['erreur']) {
            $output->writeln('Lecture chez Orange impossible : '.$sms['erreur']);
        }

        $envoyees = $this->alertes->verifier($maintenant);
        $output->writeln([] === $envoyees ? 'Aucune nouvelle alerte.' : 'Alertes envoyées : '.implode(' ', $envoyees));

        return Command::SUCCESS;
    }
}
