<?php

namespace App\Command;

use App\Service\FichiersPrives;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Range hors de public/ les fichiers envoyés avant la mise à jour du
 * 15/10/2026 (audit de sécurité D3, docs/securite.md) : chat, pré-presse,
 * étiquettes, justificatifs, photos du personnel, images des entreprises
 * passent de public/uploads/<dossier> à var/uploads/<dossier>, au même
 * chemin. Rien à changer en base : les chemins enregistrés se lisent
 * désormais sous var/.
 *
 * Sans --executer, compte seulement. Affiche des nombres, jamais le nom
 * ni le contenu d'un fichier. Se relance sans risque.
 */
#[AsCommand(name: 'app:fichiers:deplacer', description: 'Range hors de public/ les fichiers privés envoyés avant la mise à jour (chat, pré-presse, étiquettes…). Sans --executer : compte seulement.')]
final class DeplacerFichiersCommand extends Command
{
    private const LIBELLES = [
        'chat' => 'Chat (pièces jointes)',
        'prepresse' => 'Pré-presse (fichiers traités)',
        'etiquettes' => 'Étiquettes (QR codes, ZIP)',
        'reclamations' => 'Justificatifs de remboursement',
        'employes' => 'Photos du personnel',
        'entreprises' => 'Logos et cachets des entreprises',
        'fichiers' => 'Anciens fichiers divers',
    ];

    public function __construct(private readonly FichiersPrives $fichiers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('executer', null, InputOption::VALUE_NONE, 'Déplace vraiment (sinon : compte seulement)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $executer = (bool) $input->getOption('executer');
        $io->title($executer ? 'Fichiers privés : rangement hors de public/' : 'Fichiers privés : essai (rien n\'est déplacé)');

        $bilan = $this->fichiers->deplacer($executer);
        $total = ['fichiers' => 0, 'deplaces' => 0, 'identiques' => 0, 'conflits' => 0, 'erreurs' => 0, 'octets' => 0];
        $lignes = [];
        foreach ($bilan['dossiers'] as $dossier => $n) {
            foreach ($total as $cle => $valeur) {
                $total[$cle] = $valeur + $n[$cle];
            }
            $lignes[] = [self::LIBELLES[$dossier] ?? $dossier, $n['fichiers'], $n['deplaces'], $n['identiques'], $n['conflits'], $n['erreurs'], self::taille($n['octets'])];
        }
        $lignes[] = ['<options=bold>Total</>', $total['fichiers'], $total['deplaces'], $total['identiques'], $total['conflits'], $total['erreurs'], self::taille($total['octets'])];
        $io->table(['Dossier', 'Trouvés', $executer ? 'Déplacés' : 'À déplacer', 'Déjà en place', 'Conflits', 'Erreurs', 'Taille'], $lignes);

        foreach ($bilan['publics'] as $dossier => $nombre) {
            $io->writeln(\sprintf('  public/uploads/%s : %d fichier(s), restent publics (%s).', $dossier, $nombre, 'site' === $dossier ? 'images du site vitrine' : 'médias de l\'écran TV'));
        }
        foreach ($bilan['inconnus'] as $dossier => $nombre) {
            $io->writeln(\sprintf('  public/uploads/%s : %d fichier(s) d\'un dossier inconnu, laissés en place.', $dossier, $nombre));
        }

        if ($total['erreurs'] > 0 || $total['conflits'] > 0) {
            $io->error(\sprintf('%d conflit(s), %d erreur(s) : ces fichiers sont restés sous public/uploads. Envoyer cette sortie au développeur.', $total['conflits'], $total['erreurs']));

            return Command::FAILURE;
        }
        if (!$executer) {
            $io->note(0 === $total['deplaces'] ? 'Rien à déplacer.' : 'Rien n\'a été déplacé. Pour le faire : php bin/console app:fichiers:deplacer --executer');

            return Command::SUCCESS;
        }
        $io->success(0 === $total['deplaces'] + $total['identiques'] ? 'Rien à déplacer : tout est déjà rangé.' : 'Fichiers rangés hors de public/. Les anciennes adresses renvoient vers les pages qui contrôlent les droits.');

        return Command::SUCCESS;
    }

    private static function taille(int $octets): string
    {
        return $octets < 1048576
            ? number_format((int) ceil($octets / 1024), 0, ',', ' ').' Ko'
            : number_format($octets / 1048576, 1, ',', ' ').' Mo';
    }
}
