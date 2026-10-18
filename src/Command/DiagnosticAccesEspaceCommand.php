<?php

namespace App\Command;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Repository\AccesEspaceClientRepository;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\EspaceClient\SuspensionsEspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * « Première connexion » refusée sur le site (docs/espace-client.md) : dit
 * quel contrôle de EspaceClientComptes::ouvrirParAcces échoue pour ce
 * numéro, sans rien changer et sans afficher de code ni d'empreinte.
 * À lancer sur le serveur en ligne, là où le code est vérifié.
 */
#[AsCommand(name: 'app:espace:diagnostic-acces', description: 'Dit pourquoi un code d\'accès à l\'espace client est refusé pour ce numéro (lecture seule).')]
final class DiagnosticAccesEspaceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccesEspaceClientRepository $acces,
        private readonly CompteClientRepository $comptes,
        private readonly ?SuspensionsEspace $suspensions = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('telephone', InputArgument::REQUIRED, 'Numéro tapé par le client')
            ->addArgument('code', InputArgument::OPTIONAL, 'Code reçu sur WhatsApp (facultatif : vérifie qu\'il correspond)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();
        $numero = CompteClient::normaliserTelephone((string) $input->getArgument('telephone'));
        if (null === $numero) {
            $io->error('Numéro invalide : il faut 8 chiffres (ou 223 + 8 chiffres).');

            return Command::FAILURE;
        }
        $huit = substr($numero, -8);

        /** @var list<Clients> $fiches */
        $fiches = $this->em->getRepository(Clients::class)->createQueryBuilder('c')
            ->where('c.telephone LIKE :t')->setParameter('t', '%'.substr($huit, 0, 2).'%'.substr($huit, 2, 2).'%'.substr($huit, 4, 2).'%'.substr($huit, 6, 2).'%')
            ->setMaxResults(20)->getQuery()->getResult();
        $fiches = array_values(array_filter($fiches, static fn (Clients $c): bool => str_contains(preg_replace('/\D/', '', (string) $c->getTelephone()) ?? '', $huit)));

        $io->title('Accès à l\'espace client pour '.$numero);
        if ([] === $fiches) {
            $io->error('Aucune fiche client sur ce site avec ce numéro : la fiche n\'est pas encore arrivée par la synchronisation.');

            return Command::FAILURE;
        }

        $probleme = false;
        foreach ($fiches as $client) {
            $io->section(\sprintf('Fiche %s (%s)', (string) $client->getCode(), $client->getNomComplet()));
            $lignes = [];
            $telFiche = CompteClient::normaliserTelephone((string) $client->getTelephone());
            $lignes[] = ['Numéro de la fiche', null === $telFiche
                ? '✗ « '.$client->getTelephone().' » ne se lit pas comme un seul numéro (deux numéros dans le champ ?)'
                : ($telFiche === $numero ? '✓ identique' : '✗ '.$telFiche.' : différent du numéro tapé')];
            $demo = NotificationsClient::estDemo($client);
            $lignes[] = ['Fiche de démonstration', $demo ? '✗ oui : toujours refusée en ligne' : '✓ non'];

            $acces = $this->acces->duClient($client);
            if (null === $acces) {
                $lignes[] = ['Code d\'accès', '✗ aucun sur ce site : la synchronisation ne l\'a pas apporté'];
            } else {
                $lignes[] = ['Code d\'accès', \sprintf('reçu, valable jusqu\'au %s', $acces->getExpireLe()->format('d/m/Y H:i'))];
                $lignes[] = ['Expiré', $acces->getExpireLe() < $maintenant ? '✗ oui' : '✓ non'];
                $lignes[] = ['Déjà utilisé', $acces->estUtilise() ? '✗ oui, le '.$acces->getUtiliseLe()?->format('d/m/Y H:i').' : demandez un nouveau code' : '✓ non'];
                $code = (string) $input->getArgument('code');
                if ('' !== $code) {
                    $bon = hash_equals($acces->getEmpreinte(), AccesEspaceClient::empreinteDe($numero, $code));
                    $lignes[] = ['Code tapé', $bon ? '✓ correspond au dernier code arrivé' : '✗ ne correspond pas : code ancien (remplacé), mal recopié, ou le nouveau n\'est pas encore arrivé'];
                    $probleme = $probleme || !$bon;
                }
                $probleme = $probleme || $acces->getExpireLe() < $maintenant || $acces->estUtilise();
            }

            $compte = $this->comptes->parTelephone($numero);
            if (null === $compte) {
                $lignes[] = ['Compte en ligne', '✓ aucun encore : il sera créé à la première connexion'];
            } elseif ($compte->getClient()?->getId() !== $client->getId()) {
                $lignes[] = ['Compte en ligne', '✗ ce numéro a déjà un compte rattaché à une autre fiche ('.(string) $compte->getClient()?->getCode().')'];
                $probleme = true;
            } elseif (!$compte->isActif()) {
                $lignes[] = ['Compte en ligne', '✗ compte fermé'];
                $probleme = true;
            } else {
                $lignes[] = ['Compte en ligne', '✓ existe déjà sur cette fiche (le client peut aussi entrer avec son mot de passe)'];
            }
            if (true === $this->suspensions?->numeroSuspendu($client, $numero)) {
                $lignes[] = ['Suspension', '✗ accès suspendu par la boutique'];
                $probleme = true;
            }
            $probleme = $probleme || $demo || $telFiche !== $numero || null === $acces;
            $io->table(['Contrôle', 'Résultat'], $lignes);
        }

        $probleme ? $io->warning('Au moins un contrôle échoue (✗) : voir ci-dessus.') : $io->success('Tout est bon pour ce numéro. Si le site dit « Trop d\'essais », attendre 15 minutes ou vider cache.rate_limiter.');

        return Command::SUCCESS;
    }
}
