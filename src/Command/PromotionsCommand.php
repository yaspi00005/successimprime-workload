<?php

namespace App\Command;

use App\Entity\AnniversaireClient;
use App\Entity\CategorieProduit;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\DevisDetails;
use App\Entity\ModeleAnniversaire;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\Promotions\AnniversairesClients;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\CodesPromo;
use App\Service\Promotions\ContexteLigne;
use App\Service\Promotions\Promotions;
use App\Service\Promotions\PromotionsSaisie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Promotions sur l'ERP de l'imprimerie (docs/promotions.md).
 *
 * - sans option (chaque matin) : anniversaires du jour (et des jours
 *   manqués), promotion du client et message sans montant, puis rappels
 *   avant la fin des promotions d'anniversaire pas encore utilisées ;
 *   recompte les utilisations des codes promo encore valables (une
 *   commande supprimée ou annulée rend la sienne) pour le site en ligne,
 *   et refait leur empreinte si la clé de synchronisation a changé.
 *   Relancée le même jour, elle ne refait rien ;
 * - --jour=AAAA-MM-JJ : rejoue un autre jour (essai, rattrapage) ;
 * - --demo : promotions, exceptions B2B et anniversaires de démonstration
 *   (refusé en production).
 */
#[AsCommand(name: 'app:promotions', description: 'Anniversaires clients du jour (promotion, SMS, rappels) ; promotions de démonstration.')]
final class PromotionsCommand extends Command
{
    /** Repère des promotions de démonstration (purgées par app:demo --purger). */
    private const NOTE_DEMO = 'Démo : ';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Promotions $promotions,
        private readonly PromotionsSaisie $saisie,
        private readonly AnniversairesClients $anniversaires,
        private readonly CodesPromo $codes,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jour', null, InputOption::VALUE_REQUIRED, 'Jour à traiter (AAAA-MM-JJ), aujourd\'hui par défaut.')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Promotions, exceptions et anniversaires de démonstration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->promotions->estEnLigne()) {
            $io->error('Les promotions se gèrent sur l\'ERP de l\'imprimerie : lancez la commande là-bas.');

            return Command::FAILURE;
        }
        $maintenant = new \DateTimeImmutable();

        if ($input->getOption('demo')) {
            return $this->demo($io, $maintenant);
        }

        $jour = $maintenant;
        if (null !== $input->getOption('jour')) {
            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('jour'));
            if (false === $jour) {
                $io->error('Indiquez --jour=AAAA-MM-JJ.');

                return Command::INVALID;
            }
        }

        $r = $this->anniversaires->passageDuJour($jour);
        $io->success(\sprintf(
            '%d anniversaire%s fêté%s (%d SMS, %d message%s WhatsApp à envoyer depuis la page Anniversaires, %d sans message), %d écarté%s, %d en attente de validation, %d rappel%s.',
            $r['fetes'], $r['fetes'] > 1 ? 's' : '', $r['fetes'] > 1 ? 's' : '',
            $r['sms'], $r['whatsapp'], $r['whatsapp'] > 1 ? 's' : '', $r['sans_message'],
            $r['ecartes'], $r['ecartes'] > 1 ? 's' : '', $r['attente'],
            $r['rappels'], $r['rappels'] > 1 ? 's' : '',
        ));
        $corriges = $this->codes->recompterTout($jour);
        if ($corriges > 0) {
            $io->note(\sprintf('Codes promo : %d remis à jour (utilisations, ou empreinte après un changement de clé de synchronisation).', $corriges));
        }

        return Command::SUCCESS;
    }

    /**
     * Démonstration rejouable : modèles d'anniversaire, promotions de tous
     * les états, lignes passées qui les portent (montants inchangés : le prix
     * unitaire est relevé d'autant), exceptions B2B, anniversaires décidés
     * et fêtés. Rien n'est ajouté deux fois.
     */
    private function demo(SymfonyStyle $io, \DateTimeImmutable $maintenant): int
    {
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $direction = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.kadiatou'])
            ?? $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        if (!$direction instanceof User) {
            $io->error('Aucun utilisateur pour signer les gestes de démonstration.');

            return Command::FAILURE;
        }
        $caisse = $this->em->getRepository(User::class)->findOneBy(['username' => 'demo.aminata']) ?? $direction;
        $aujourdhui = $maintenant->setTime(0, 0);

        $modeles = $this->modelesDemo($maintenant);
        $promos = $this->promotionsDemo($direction, $aujourdhui, $maintenant);
        $lignes = 0;
        foreach (['rentree', 'textile', 'ete'] as $cle) {
            if (isset($promos[$cle])) {
                $lignes += $this->historique($promos[$cle]);
            }
        }
        $this->em->flush();
        $exceptions = isset($promos['textile']) ? $this->exceptionsDemo($promos['textile'], $caisse, $direction, $maintenant) : 0;
        $fetes = $this->anniversairesDemo($direction, $aujourdhui, $maintenant);

        $io->success(\sprintf(
            'Promotions de démonstration prêtes : %d modèle%s d\'anniversaire, %d promotion%s, %d ligne%s passée%s avec promotion, %d exception%s B2B, %d anniversaire%s fêté%s.',
            $modeles, $modeles > 1 ? 's' : '', \count($promos), \count($promos) > 1 ? 's' : '',
            $lignes, $lignes > 1 ? 's' : '', $lignes > 1 ? 's' : '', $exceptions, $exceptions > 1 ? 's' : '',
            $fetes, $fetes > 1 ? 's' : '', $fetes > 1 ? 's' : '',
        ));

        return Command::SUCCESS;
    }

    /** Trois paliers d'anniversaire, seulement si l'imprimerie n'en a encore aucun. */
    private function modelesDemo(\DateTimeImmutable $maintenant): int
    {
        if ([] !== $this->anniversaires->modeles()) {
            return 0;
        }
        $paliers = [
            [1, 'Cadeau d\'anniversaire : vos cartes de visite', [$this->produit('Cartes de visite')], null, 20, 30],
            [3, 'Cadeau d\'anniversaire : flyers et affiches', [], $this->categorie('Impression numérique'), 15, 30],
            [5, 'Cinq ans ensemble : le grand format', [], $this->categorie('Grand format'), 10, 45],
        ];
        $n = 0;
        foreach ($paliers as [$annees, $nom, $produits, $categorie, $taux, $jours]) {
            $m = (new ModeleAnniversaire())->setAnnees($annees)->setNom($nom)->remplacerProduits(array_filter($produits))
                ->setCategorie($categorie)->setAvantage(Promotion::AVANTAGE_POURCENTAGE)->setValeur($taux)
                ->setValiditeJours($jours)->setTexteSms(ModeleAnniversaire::TEXTE_PAR_DEFAUT)->marquerModifie($maintenant);
            $this->em->persist($m);
            ++$n;
        }
        $this->em->flush();

        return $n;
    }

    /**
     * Une promotion de chaque état : en cours (dont une avec code et une au
     * prix fixe), terminée, à venir, suspendue.
     *
     * @return array<string, Promotion>
     */
    private function promotionsDemo(User $par, \DateTimeImmutable $aujourdhui, \DateTimeImmutable $maintenant): array
    {
        $existantes = [];
        foreach ($this->em->createQueryBuilder()->select('p')->from(Promotion::class, 'p')
            ->where('p.note LIKE :demo')->setParameter('demo', self::NOTE_DEMO.'%')
            ->getQuery()->getResult() as $p) {
            $existantes[(string) $p->getNote()] = $p;
        }
        $jour = static fn (int $decalage): \DateTimeImmutable => $aujourdhui->modify(($decalage >= 0 ? '+' : '').$decalage.' days');
        $modeles = [
            'rentree' => ['Rentrée : flyers et affiches', [$this->produit('Flyers A5'), $this->produit('Affiches A3')], null, Promotion::AVANTAGE_POURCENTAGE, 15, -25, 6, Promotion::SEGMENT_TOUS, [], 'en cours, pour tous les clients'],
            'textile' => ['Textile personnalisé dès 10 pièces', [], $this->categorie('Textile'), Promotion::AVANTAGE_POURCENTAGE, 25, -7, 21, Promotion::SEGMENT_TOUS, ['quantite' => 10], 'en cours, quantité minimale (exceptions B2B)'],
            'rollup' => ['Roll-up à prix entreprise', [$this->produit('Roll-up 85')], null, Promotion::AVANTAGE_PRIX_FIXE, 39000, -3, 27, 'entreprises', ['quantite' => 2], 'prix fixe pour les entreprises et institutions'],
            'bache' => ['Bâche : remise au m²', [$this->produit('Bâche imprimée')], null, Promotion::AVANTAGE_MONTANT, 500, 0, 30, Promotion::SEGMENT_FIDELITE, ['code' => 'BACHE500', 'utilisations' => 2], 'code promo, clients fidèles, deux fois par client'],
            'ete' => ['Été textile', [], $this->categorie('Textile'), Promotion::AVANTAGE_POURCENTAGE, 10, -130, -70, Promotion::SEGMENT_TOUS, [], 'terminée, tous les clients'],
            'fetes' => ['Fêtes de fin d\'année', [$this->produit('Cartes de visite'), $this->produit('Brochure A4')], null, Promotion::AVANTAGE_POURCENTAGE, 20, 45, 80, Promotion::SEGMENT_TOUS, ['montant' => 25000], 'à venir, montant minimal'],
            'kakemono' => ['Kakémono de lancement', [$this->produit('Kakémono')], null, Promotion::AVANTAGE_MONTANT, 5000, -2, 20, 'nouveaux', ['suspendue' => true], 'suspendue, nouveaux clients'],
        ];

        $promos = [];
        foreach ($modeles as $cle => [$nom, $produits, $categorie, $avantage, $valeur, $debut, $fin, $segment, $conditions, $note]) {
            $note = self::NOTE_DEMO.$note;
            if (isset($existantes[$note])) {
                $promos[$cle] = $existantes[$note];
                continue;
            }
            if ([] === array_filter($produits) && null === $categorie) {
                continue;
            }
            $p = (new Promotion())->setNom($nom)->remplacerProduits(array_filter($produits))->setCategorie($categorie)
                ->setAvantage($avantage)->setValeur($valeur)->setDebut($jour($debut))->setFin($jour($fin))->setSegment($segment)
                ->setQuantiteMin($conditions['quantite'] ?? null)->setMontantMin($conditions['montant'] ?? null)
                ->setCode($conditions['code'] ?? null)->setUtilisationsParClient($conditions['utilisations'] ?? null)
                ->setActive(!($conditions['suspendue'] ?? false))->setNote($note);
            $this->promotions->enregistrer($p, null, $par, $maintenant);
            $promos[$cle] = $p;
        }

        return $promos;
    }

    /**
     * Lignes des commandes de démonstration passées pendant la promotion,
     * pour des clients qui y avaient droit : elles la portent comme si elle
     * avait été appliquée à la saisie. Les totaux, factures et paiements ne
     * bougent pas : le prix unitaire est relevé du montant de la promotion.
     */
    private function historique(Promotion $p): int
    {
        if (!\in_array($p->getAvantage(), [Promotion::AVANTAGE_POURCENTAGE, Promotion::AVANTAGE_MONTANT], true)) {
            return 0;
        }
        $produits = array_map(static fn (Produits $x): int => (int) $x->getId(), $p->getProduits()->toArray());
        if (null !== $p->getCategorie()) {
            foreach ($this->em->getRepository(Produits::class)->findBy(['categorieProduit' => $p->getCategorie()]) as $x) {
                $produits[] = (int) $x->getId();
            }
        }
        if ([] === $produits) {
            return 0;
        }
        /** @var list<CommandesDetails> $lignes */
        $lignes = $this->em->createQueryBuilder()->select('d', 'k', 'c')->from(CommandesDetails::class, 'd')
            ->join('d.commande', 'k')->join('k.clients', 'c')
            ->where('k.dateCommande >= :debut')->andWhere('k.dateCommande < :fin')
            ->andWhere('d.produit IN (:produits)')->andWhere('d.promotion IS NULL')->andWhere('d.remise = 0')
            ->andWhere('d.statutProduction IS NULL OR d.statutProduction != :annulee')
            ->andWhere('k.deleted = false')->andWhere('c.code LIKE :demo')->andWhere('c.typeClient != :b2b')
            ->setParameter('debut', $p->getDebut())->setParameter('fin', $p->getFin()->modify('+1 day'))
            ->setParameter('produits', $produits)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->setParameter('demo', 'DEMO-%')->setParameter('b2b', 'B2B')
            ->orderBy('k.dateCommande', 'ASC')
            ->getQuery()->getResult();

        $n = 0;
        $segments = [];
        foreach ($lignes as $ligne) {
            $commande = $ligne->getCommande();
            $client = $commande?->getClients();
            $date = $commande?->getDateCommande();
            if (null === $client || null === $date || $ligne->getPrixUnitaire() <= 0) {
                continue;
            }
            $jour = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
            $cle = $client->getId().'-'.$jour->format('Ymd');
            $segments[$cle] ??= $this->promotions->segmentsDe($client, $commande->getId(), $jour);
            $ctx = new ContexteLigne($client, $ligne->getProduit(), $ligne->getPrixUnitaire(), 0, (int) $ligne->getQuantite(), $ligne->getTotalHt(), $jour, $segments[$cle]);
            if (null !== CalculPromotion::refus($p, $ctx)) {
                continue;
            }
            // Prix d'avant la promotion : la promotion le ramène au prix payé.
            $prix = $ligne->getPrixUnitaire();
            $avant = Promotion::AVANTAGE_MONTANT === $p->getAvantage() ? $prix + $p->getValeur() : $prix * 100 / (100 - min(90.0, $p->getValeur()));
            $avant = (float) round($avant);
            $remise = (int) round($avant - $prix);
            $ligne->setPrixUnitaire($avant);
            $ligne->reprendrePromotion($p, $p->libelleLigne(), $remise, (int) round($remise * $ligne->getUnitesFacturables()), false);
            ++$n;
        }

        return $n;
    }

    /**
     * Exceptions B2B sur le textile : une demandée par la caisse et pas
     * encore décidée, une acceptée par la direction.
     */
    private function exceptionsDemo(Promotion $textile, User $caisse, User $direction, \DateTimeImmutable $maintenant): int
    {
        if (null !== $this->em->getRepository(DemandeExceptionPromotion::class)->findOneBy(['promotion' => $textile])) {
            return 0;
        }
        $n = 0;
        $possibles = [];
        // Commandes et devis de revendeurs, pendant la promotion, que la promotion battrait.
        foreach ([Commandes::class => 'dateCommande', \App\Entity\Devis::class => 'dateDevis'] as $classe => $champ) {
            $documents = $this->em->createQueryBuilder()->select('x')->from($classe, 'x')->join('x.clients', 'c')
                ->where('x.'.$champ.' >= :debut')->andWhere('c.typeClient = :b2b')->andWhere('c.code LIKE :demo')
                ->setParameter('debut', $textile->getDebut())->setParameter('b2b', 'B2B')->setParameter('demo', 'DEMO-%')
                ->orderBy('x.'.$champ, 'DESC')->setMaxResults(30)->getQuery()->getResult();
            foreach ($documents as $document) {
                foreach ($this->saisie->possibilitesB2b($document, $maintenant) as $ligneId => $liste) {
                    foreach ($liste as $possible) {
                        if ($possible['promotion'] === $textile && null === $possible['demande']) {
                            $possibles[] = $this->em->find(Commandes::class === $classe ? CommandesDetails::class : DevisDetails::class, $ligneId);
                        }
                    }
                }
            }
        }
        $possibles = array_values(array_filter($possibles));

        foreach (\array_slice($possibles, 0, 2) as $i => $ligne) {
            try {
                $demande = $this->saisie->demanderException($ligne, $textile, $caisse, $maintenant->modify('-'.(2 - $i).' hours'));
                ++$n;
                if (1 === $i) {
                    $this->saisie->deciderException($demande, true, 'Gros volume, revendeur régulier', $direction, $maintenant->modify('-1 hour'));
                }
            } catch (\DomainException) {
            }
        }

        return $n;
    }

    /**
     * Anniversaires : ceux des derniers jours validés puis fêtés par le
     * passage du jour, un prochain exclu ; les autres attendent la
     * validation sur la page Anniversaires.
     */
    private function anniversairesDemo(User $par, \DateTimeImmutable $aujourdhui, \DateTimeImmutable $maintenant): int
    {
        $passes = array_values(array_filter(
            $this->anniversaires->entre($aujourdhui->modify('-5 days'), $aujourdhui->modify('-1 day')),
            static fn (array $e): bool => str_starts_with((string) $e['client']->getCode(), 'DEMO-') && null === $e['anniversaire'] && null !== $e['modele'],
        ));
        foreach (\array_slice($passes, 0, 4) as $e) {
            $this->anniversaires->decider($e['client'], $e['annees'], $e['date'], AnniversaireClient::VALIDE, null, $par, $maintenant->modify('-6 days'));
        }
        $prochains = array_values(array_filter(
            $this->anniversaires->entre($aujourdhui->modify('+3 days'), $aujourdhui->modify('+21 days')),
            static fn (array $e): bool => str_starts_with((string) $e['client']->getCode(), 'DEMO-') && null === $e['anniversaire'],
        ));
        if (isset($prochains[0])) {
            $e = $prochains[0];
            $this->anniversaires->decider($e['client'], $e['annees'], $e['date'], AnniversaireClient::EXCLU, null, $par, $maintenant);
        }

        return $this->anniversaires->passageDuJour($aujourdhui, $par)['fetes'];
    }

    private function produit(string $debut): ?Produits
    {
        return $this->em->createQueryBuilder()->select('p')->from(Produits::class, 'p')
            ->where('p.nom LIKE :n')->andWhere('p.nom LIKE :demo')
            ->setParameter('n', $debut.'%')->setParameter('demo', '%(démo)%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    private function categorie(string $debut): ?CategorieProduit
    {
        return $this->em->createQueryBuilder()->select('c')->from(CategorieProduit::class, 'c')
            ->where('c.nom LIKE :n')->andWhere('c.nom LIKE :demo')
            ->setParameter('n', $debut.'%')->setParameter('demo', '%(démo)%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
