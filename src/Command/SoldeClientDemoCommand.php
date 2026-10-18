<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\DepotSoldeClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Entity\User;
use App\Repository\ParametresPaiementRepository;
use App\Service\Paiement\FraisOrangeMoney;
use App\Service\Paiement\SoldeClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Comptes clients fictifs pour voir docs/finances.md (« Compte du client »)
 * et la page « Mon solde » de l'espace client, passés par le vrai service
 * (relevé, trésorerie « avance client », journal de la fiche) :
 *  - Coumba Bagayoko (DEMO-0122) : monnaie gardée à la caisse, dépôt en
 *    espèces, une partie d'une commande payée avec le solde ;
 *  - Amadou Sow (DEMO-0195, particulier de l'espace) : dépôt en espèces et
 *    un dépôt Orange Money fait en ligne, reçu, à valider à l'imprimerie ;
 *  - Salon Espoir (DEMO-0002) : dépôt Orange Money à l'imprimerie, argent
 *    rendu en partie par la direction, et un dépôt en ligne fait par son
 *    payeur, à valider.
 *
 * Lancée par app:demo juste après le chargement. Relancée : rien de plus
 * si ces comptes ont déjà un relevé. app:demo --purger enlève tout. Refusé
 * en production. Sur le site en ligne, rien n'est créé : le relevé et les
 * dépôts y arrivent par la synchronisation (créés des deux côtés, ils
 * seraient en double sur « Mon solde » et la référence d'un dépôt se
 * heurterait à sa copie).
 */
#[AsCommand(name: 'app:solde-client:demo', description: 'Comptes clients fictifs (monnaie gardée, dépôts, argent rendu, dépôts en ligne à valider) sur les clients DEMO-.')]
final class SoldeClientDemoCommand extends Command
{
    /** Codes des clients de démonstration utilisés. */
    public const CLIENTS = ['DEMO-0122', 'DEMO-0195', 'DEMO-0002'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SoldeClient $soldes,
        private readonly ParametresPaiementRepository $parametres,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de comptes clients fictifs en production.');

            return Command::FAILURE;
        }
        if ($this->enLigne) {
            $io->note('Site en ligne : les comptes clients de démonstration arrivent par la synchronisation depuis l\'ERP local.');

            return Command::SUCCESS;
        }
        $clients = [];
        foreach (self::CLIENTS as $code) {
            $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $code]);
            if (!$client instanceof Clients) {
                $io->warning(\sprintf('Client %s introuvable : chargez d\'abord la démonstration (app:demo).', $code));

                return Command::SUCCESS;
            }
            $clients[$code] = $client;
        }
        if (null !== $this->em->getRepository(MouvementSoldeClient::class)->findOneBy(['client' => array_values($clients)])) {
            $io->note('Comptes clients de démonstration déjà en place.');

            return Command::SUCCESS;
        }
        $caissiere = $this->utilisateur('demo.aminata');
        $direction = $this->utilisateur('demo.kadiatou');
        $caisse = $this->compte(CompteTresorerie::TYPE_CAISSE);
        $orange = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY);
        if (null === $caissiere || null === $direction || null === $caisse || null === $orange) {
            $io->warning('Employés ou comptes de trésorerie de la démonstration introuvables : rien n\'est créé.');

            return Command::SUCCESS;
        }

        try {
            // Coumba Bagayoko : monnaie gardée, dépôt, puis une partie d'une commande payée avec le solde.
            $coumba = $clients['DEMO-0122'];
            $paiement = $this->dernierPaiementEspeces($coumba, $caisse);
            if (null !== $paiement) {
                $this->soldes->garderMonnaie($paiement, 2500, $caissiere);
            }
            $this->soldes->deposer($coumba, 20000, Paiements::MODE_ESPECES, $caisse, null, 0, $caissiere, 'Avance pour les prochains flyers.');
            $commande = $this->commandeAPayer($coumba);
            if (null !== $commande) {
                $this->soldes->utiliser($commande, min(10000, $commande->getResteAPayer()), $caissiere);
            }

            // Amadou Sow : dépôt en espèces, puis un dépôt Orange Money depuis son espace, à valider.
            $amadou = $clients['DEMO-0195'];
            $this->soldes->deposer($amadou, 15000, Paiements::MODE_ESPECES, $caisse, null, 0, $caissiere);
            $this->depotEnLigne($amadou, 5000, 'Amadou Sow', 'SIDEMO000195', 'MP260001DEMO195');

            // Salon Espoir : dépôt Orange Money à l'imprimerie, une partie rendue, un dépôt en ligne du payeur.
            $salon = $clients['DEMO-0002'];
            $this->soldes->deposer($salon, 50000, Paiements::MODE_ORANGE_MONEY, $orange, 'MP260001DEMO002', 0, $caissiere, 'Avance sur les commandes du trimestre.');
            $this->soldes->rembourser($salon, 10000, $caisse, 'Le gérant a repris une partie de l\'avance.', $direction);
            $this->depotEnLigne($salon, 25000, 'Ibrahim Touré', 'SIDEMO000002', 'MP260002DEMO002');
        } catch (\DomainException|\LogicException $e) {
            $io->error('Comptes clients de démonstration : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Comptes clients de démonstration : %s.',
            implode(', ', array_map(static fn (Clients $c): string => \sprintf('%s %s F', $c->getNomComplet(), number_format($c->getSoldeCredit(), 0, ',', ' ')), $clients)),
        ));

        return Command::SUCCESS;
    }

    /** Dépôt fait depuis l'espace client, déjà reçu par Orange : il attend la caisse. */
    private function depotEnLigne(Clients $client, int $montant, string $auteur, string $reference, string $txnid): void
    {
        $frais = FraisOrangeMoney::calculer($montant, $this->parametres->recuperer());
        $maintenant = new \DateTimeImmutable();
        $depot = DepotSoldeClient::nouveau($client, $montant, $frais->fondsSoutien, $frais->fraisRetrait, $reference, $auteur, $maintenant->modify('-2 hours'));
        $depot->recevoir($txnid, $maintenant->modify('-115 minutes'));
        $this->em->persist($depot);
        $this->em->flush();
    }

    private function dernierPaiementEspeces(Clients $client, CompteTresorerie $caisse): ?Paiements
    {
        return $this->em->createQueryBuilder()->select('p')->from(Paiements::class, 'p')->join('p.commande', 'c')
            ->where('c.clients = :client')->andWhere('p.mode = :especes')->andWhere('p.statut = :valide')->andWhere('p.compteTresorerie = :caisse')
            ->setParameter('client', $client)->setParameter('especes', Paiements::MODE_ESPECES)->setParameter('valide', Paiements::STATUT_VALIDE)->setParameter('caisse', $caisse)
            ->orderBy('p.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function commandeAPayer(Clients $client): ?Commandes
    {
        return $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.clients = :client')->andWhere('c.resteAPayer > 0')->andWhere('c.etat = true')
            ->setParameter('client', $client)
            ->orderBy('c.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function utilisateur(string $identifiant): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => $identifiant]);
    }

    private function compte(string $type): ?CompteTresorerie
    {
        return $this->em->getRepository(CompteTresorerie::class)->findOneBy(['type' => $type, 'actif' => true, 'orangeMoneyEnLigne' => false], ['id' => 'ASC']);
    }
}
