<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\JournalActivite;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\Produits;
use App\Entity\RegleFidelite;
use App\Entity\User;
use App\Service\Fidelite\Fidelite;
use App\Service\Fidelite\ReveilClients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fidélité sur l'ERP de la boutique (docs/fidelite.md).
 *
 * - sans option (chaque nuit) : écrit les points expirés, remet à jour le
 *   résumé envoyé au site et rattrape les paiements des 3 derniers jours ;
 * - --rattraper=N : recompte les paiements des N derniers jours (après un
 *   incident journalisé) ;
 * - --recalculer --depuis=AAAA-MM-JJ [--client=CODE] : refait les gains
 *   depuis une date (début du programme) ;
 * - --demo : relevé des clients de démonstration (refusé en production).
 */
#[AsCommand(name: 'app:fidelite', description: 'Points de fidélité : expiration du jour, rattrapage, recalcul, démonstration.')]
final class FideliteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fidelite $fidelite,
        private readonly ReveilClients $reveil,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rattraper', null, InputOption::VALUE_REQUIRED, 'Recompte les paiements des N derniers jours.')
            ->addOption('recalculer', null, InputOption::VALUE_NONE, 'Refait les gains depuis --depuis.')
            ->addOption('depuis', null, InputOption::VALUE_REQUIRED, 'Date de départ du recalcul (AAAA-MM-JJ).')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Code du seul client à recalculer.')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Relevé des clients de démonstration (DEMO-, CL-DEMO).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->fidelite->estEnLigne()) {
            $io->error('Le site en ligne reçoit le relevé par la synchronisation : lancez la commande sur l\'ERP de la boutique.');

            return Command::FAILURE;
        }
        $maintenant = new \DateTimeImmutable();

        if ($input->getOption('demo')) {
            return $this->demo($io, $maintenant);
        }

        if ($input->getOption('recalculer')) {
            $depuis = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('depuis'));
            if (false === $depuis) {
                $io->error('Indiquez --depuis=AAAA-MM-JJ.');

                return Command::INVALID;
            }
            $client = null;
            if (null !== $input->getOption('client')) {
                $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $input->getOption('client')]);
                if (null === $client) {
                    $io->error('Client introuvable.');

                    return Command::FAILURE;
                }
            }
            $r = $this->fidelite->recalculer($depuis, $maintenant, $client);
            $io->success(\sprintf('%d paiements rejoués, %d clients mis à jour.', $r['paiements'], $r['clients']));

            return Command::SUCCESS;
        }

        $jours = null !== $input->getOption('rattraper') ? max(1, (int) $input->getOption('rattraper')) : 3;
        $r = $this->fidelite->rattraper($maintenant->modify('-'.$jours.' days')->setTime(0, 0), $maintenant);
        $p = $this->fidelite->passageDuJour($maintenant);
        $io->success(\sprintf('%d paiements vérifiés (%d corrigés), %d clients à jour, %d lots expirés.', $r['paiements'], $r['corriges'], $p['clients'], $p['expires']));

        return Command::SUCCESS;
    }

    /**
     * Rejoue l'année de démonstration pour les particuliers de démo, puis
     * quelques gestes de la boutique : points offerts, points utilisés,
     * valeurs propres d'un gros client, promo de réveil. Rejouable : rien
     * n'est ajouté deux fois.
     */
    private function demo(SymfonyStyle $io, \DateTimeImmutable $maintenant): int
    {
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $agent = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.kadiatou'])
            ?? $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        if (!$agent instanceof User) {
            $io->error('Aucun utilisateur pour signer les gestes de démonstration.');

            return Command::FAILURE;
        }

        $clients = $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
            ->where('c.code LIKE :d OR c.code LIKE :cl')->andWhere('c.typeClient != :b2b')
            ->setParameter('d', 'DEMO-%')->setParameter('cl', 'CL-DEMO%')->setParameter('b2b', 'B2B')
            ->orderBy('c.id', 'ASC')->getQuery()->getResult();
        $regles = $this->reglesDemo($agent, $maintenant);

        // Les plus gros acheteurs (paiements validés), toujours les mêmes d'un passage à l'autre.
        $classement = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS id', 'SUM(p.montant) AS paye')->from(Paiements::class, 'p')->join('p.commande', 'c')
            ->where('c.clients IN (:c)')->andWhere('p.statut = :v')->andWhere('p.mode != :points')
            ->setParameter('c', $clients)->setParameter('v', Paiements::STATUT_VALIDE)->setParameter('points', Paiements::MODE_POINTS_FIDELITE)
            ->groupBy('c.clients')->orderBy('paye', 'DESC')->addOrderBy('id', 'ASC')
            ->getQuery()->getArrayResult();
        $parId = [];
        foreach ($clients as $c) {
            $parId[$c->getId()] = $c;
        }
        $fideles = array_values(array_filter(array_map(
            static fn (array $l): ?Clients => isset($parId[(int) $l['id']]) && !str_starts_with((string) $parId[(int) $l['id']]->getCode(), 'CL-') ? $parId[(int) $l['id']] : null,
            $classement,
        )));
        $gestes = 0;

        // Un gros client aux valeurs propres, avant le rejeu : tout son relevé en tient compte.
        $gros = $fideles[1] ?? null;
        if (null !== $gros && !$this->fidelite->compte($gros)->aDesValeursPropres()) {
            $this->fidelite->personnaliser($gros, ['pointsParM2' => 150.0, 'tauxAutre' => 3.0, 'seuilM2' => null, 'seuilMontant' => null], 'Démo : gros client, conditions négociées', $agent, $maintenant);
            ++$gestes;
        }

        $depuis = $maintenant->modify('-14 months')->setTime(0, 0);
        $paiements = 0;
        foreach ($clients as $client) {
            $paiements += $this->fidelite->recalculer($depuis, $maintenant, $client)['paiements'];
        }
        $io->writeln(\sprintf('%d règles d\'article ajoutées, %d particuliers de démonstration, %d paiements rejoués.', $regles, \count($clients), $paiements));

        // Gestes de la boutique, chacun une seule fois (repéré par son motif).
        $vitrine = $this->em->getRepository(Clients::class)->findOneBy(['code' => 'CL-DEMO1']);
        $ajustements = [
            [$vitrine, 800, 'Démo : reprise de l\'ancienne carte de fidélité', 350],
            [$vitrine, 2400, 'Démo : bienvenue dans le programme', 150],
            [$vitrine, 1500, 'Démo : geste commercial (réclamation traitée)', 20],
            [$fideles[0] ?? null, 2000, 'Démo : geste commercial (réclamation traitée)', 29],
            [$fideles[3] ?? null, -500, 'Démo : correction d\'un double comptage', 12],
        ];
        foreach ($ajustements as [$client, $points, $motif, $jours]) {
            if (null !== $client && !$this->dejaFait($client, $motif)) {
                try {
                    $this->fidelite->ajuster($client, $points, $motif, $agent, $maintenant->modify('-'.$jours.' days'));
                    ++$gestes;
                } catch (\DomainException $e) {
                    $io->note($client->getCode().' : '.$e->getMessage());
                }
            }
        }

        // Points utilisés sur une commande ouverte d'un gros acheteur.
        foreach (\array_slice($fideles, 0, 8) as $client) {
            if ($this->dejaUtilise($client)) {
                break;
            }
            $commande = $this->commandeOuverte($client);
            if (null !== $commande && $this->fidelite->etat($client, $maintenant)['disponible'] >= 3000) {
                $this->fidelite->utiliser($commande, 3000, $agent, $maintenant);
                ++$gestes;
                break;
            }
        }

        // Une promo de réveil pour un dormeur, une autre pour la vitrine de l'espace client.
        $reglages = $this->reveil->reglages();
        $dormeurs = array_values(array_filter($this->reveil->dormants($reglages['jours'], $maintenant, 60), static fn (array $d): bool => str_starts_with((string) $d['client']->getCode(), 'DEMO-')));
        foreach (array_filter([$dormeurs[0]['client'] ?? null, $vitrine]) as $client) {
            if (!$this->promoDemoDonnee($client)) {
                $this->reveil->offrir($client, $reglages['taux'], $reglages['duree'], $agent, $maintenant);
                ++$gestes;
            }
        }

        foreach ($clients as $client) {
            $this->fidelite->expirer($client, $maintenant);
            $this->em->flush();
            $this->fidelite->actualiser($client, $maintenant);
        }
        $this->em->flush();
        $io->success(\sprintf('Relevés de démonstration prêts (%d gestes de la boutique ajoutés).', $gestes));

        return Command::SUCCESS;
    }

    /** Trois règles d'article de démonstration, sur les produits « (démo) ». */
    private function reglesDemo(User $agent, \DateTimeImmutable $maintenant): int
    {
        $annee = (int) $maintenant->format('Y');
        $modeles = [
            ['nom' => 'Roll-up 85', 'actif' => true, 'mode' => RegleFidelite::MODE_UNITE, 'valeur' => 500, 'point' => 1, 'seuil' => 2, 'portee' => RegleFidelite::PORTEE_PRODUIT, 'debut' => $maintenant->modify('-3 months'), 'fin' => new \DateTimeImmutable($annee.'-12-31'), 'note' => 'Roll-up de la rentrée : 500 points par roll-up dès le 3e'],
            ['nom' => 'Cartes de visite', 'actif' => false, 'mode' => RegleFidelite::MODE_UNITE, 'valeur' => 0, 'point' => 1, 'seuil' => 0, 'portee' => RegleFidelite::PORTEE_PRODUIT, 'debut' => $maintenant->modify('-10 months'), 'fin' => null, 'note' => 'Marge trop faible : pas de points'],
            ['nom' => 'Vinyle adh', 'actif' => true, 'mode' => RegleFidelite::MODE_UNITE, 'valeur' => 150, 'point' => 2, 'seuil' => 0, 'portee' => RegleFidelite::PORTEE_PRODUIT, 'debut' => $maintenant->modify('-2 months'), 'fin' => null, 'note' => 'Lancement du vinyle : dès le 1er m², point à 2 F'],
        ];
        $n = 0;
        foreach ($modeles as $m) {
            $produit = $this->em->createQueryBuilder()->select('p')->from(Produits::class, 'p')
                ->where('p.nom LIKE :n')->andWhere('p.nom LIKE :demo')
                ->setParameter('n', $m['nom'].'%')->setParameter('demo', '%(démo)%')
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if (null === $produit || null !== $this->em->getRepository(RegleFidelite::class)->findOneBy(['produit' => $produit])) {
                continue;
            }
            $regle = (new RegleFidelite())->setProduit($produit)->setActif($m['actif'])->setMode($m['mode'])
                ->setValeur($m['valeur'])->setValeurPoint($m['point'])->setSeuil($m['seuil'])->setPortee($m['portee'])
                ->setDebut($m['debut'])->setFin($m['fin'])->setNote($m['note'])
                ->marquerModifiee($agent, $maintenant);
            $this->em->persist($regle);
            $this->em->flush();
            $this->em->persist((new JournalActivite())->setEntite('RegleFidelite')->setEntiteId((int) $regle->getId())
                ->setAction('creation')->setDonneesApres($regle->instantane())->setUtilisateur($agent));
            $this->em->flush();
            ++$n;
        }

        return $n;
    }

    private function dejaFait(Clients $client, string $motif): bool
    {
        return null !== $this->em->createQueryBuilder()->select('m.id')->from(MouvementFidelite::class, 'm')
            ->where('m.client = :c')->andWhere('m.type = :t')->andWhere('m.motif = :p')
            ->setParameter('c', $client)->setParameter('t', MouvementFidelite::AJUSTEMENT)->setParameter('p', $motif)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function promoDemoDonnee(Clients $client): bool
    {
        return null !== $this->em->getRepository(\App\Entity\PromoReveil::class)->findOneBy(['client' => $client]);
    }

    private function dejaUtilise(Clients $client): bool
    {
        return null !== $this->em->createQueryBuilder()->select('m.id')->from(MouvementFidelite::class, 'm')
            ->where('m.client = :c')->andWhere('m.type = :t')
            ->setParameter('c', $client)->setParameter('t', MouvementFidelite::UTILISATION)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function commandeOuverte(Clients $client): ?Commandes
    {
        foreach ($this->em->getRepository(Commandes::class)->findBy(['clients' => $client, 'deleted' => false], ['dateCommande' => 'DESC'], 10) as $c) {
            if ($c->isStatut() && !Fidelite::commandeAnnulee($c) && $c->getTotalTtc() - $c->getTotalPaye() >= 1000
                && 0 === \count(array_filter($c->getPaiements()->toArray(), static fn (Paiements $p): bool => Paiements::MODE_POINTS_FIDELITE === $p->getMode()))) {
                return $c;
            }
        }

        return null;
    }
}
