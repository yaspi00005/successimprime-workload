<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\InvitationSondage;
use App\Entity\NoteArticleSondage;
use App\Entity\ReponseSondage;
use App\Entity\Sondage;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Sondages\Questions;
use App\Service\Sondages\SondageCommande;
use App\Service\Sondages\Sondages;
use App\Service\Sondages\Themes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Avis fictifs pour voir les écrans de docs/sondages.md : une année
 * d'avis sur les commandes livrées des clients de démonstration (code
 * DEMO-), un sondage global et un sondage libre. Les avis passent en ligne
 * par la synchronisation comme les vrais. Refusé en production.
 */
#[AsCommand(name: 'app:sondages:demo', description: 'Avis fictifs sur les commandes livrées des clients de démonstration (DEMO-), ou les enlève avec --purger.')]
final class SondagesDemoCommand extends Command
{
    private const GLOBAL = 'Vos besoins pour 2027';
    private const LIBRE = 'Journée portes ouvertes';
    private const PAR = 'Démonstration';

    /** Propositions fictives, de chaque thème. */
    private const PROPOSITIONS = [
        'Livrer un peu plus vite les petites commandes, nous attendons parfois deux jours de plus que prévu.',
        'Respecter l\'heure annoncée, le retard nous a gênés pour notre événement.',
        'Des prix dégressifs pour les grandes quantités de flyers.',
        'Le tarif des bâches a augmenté, une remise pour les clients fidèles serait la bienvenue.',
        'Les couleurs étaient un peu plus ternes que sur le BAT.',
        'Très belle qualité d\'impression, papier épais. Continuez !',
        'Accueil très chaleureux, merci à l\'équipe de l\'imprimerie.',
        'Répondre plus vite sur WhatsApp, on attend parfois longtemps.',
        'Le livreur était en retard et n\'a pas appelé avant de venir.',
        'Proposer la livraison à Kati et à Koulikoro.',
        'Ajouter des modèles de cartes de visite à choisir dans l\'espace client.',
        'Ouvrir le samedi après-midi.',
    ];

    private const NOMS = ['Aïssata Coulibaly', 'Moussa Sidibé', 'Fatoumata Konaté', 'Ibrahim Touré', 'Mariam Sangaré', 'Oumar Maïga', 'Kadidia Dembélé', 'Seydou Keïta'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève les avis fictifs au lieu de les créer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement || $this->enLigne) {
            $io->error('Pas d\'avis fictifs en production ni sur le site : lancez la commande à l\'ERP local de démonstration.');

            return Command::FAILURE;
        }
        if ($input->getOption('purger')) {
            $n = $this->purger();
            $io->success(\sprintf('%d avis fictifs enlevés (au local ; la copie en ligne reste jusqu\'à sa remise à zéro).', $n));

            return Command::SUCCESS;
        }
        if ($this->em->getRepository(Sondage::class)->count(['titre' => self::GLOBAL]) > 0) {
            $io->warning('Les avis fictifs sont déjà là : --purger d\'abord pour les recréer.');

            return Command::SUCCESS;
        }

        mt_srand(2026);
        $maintenant = new \DateTimeImmutable();
        $commandes = $this->commandes($maintenant);
        $avis = 0;
        $invitees = array_flip(array_map('intval', $this->em->createQueryBuilder()->select('IDENTITY(i.commande)')->from(InvitationSondage::class, 'i')->getQuery()->getSingleColumnResult()));
        foreach ($commandes as $i => $c) {
            if (null !== $this->em->getRepository(ReponseSondage::class)->findOneBy(['commande' => $c])) {
                continue;
            }
            $reference = Sondages::dateDeReference($c);
            if (!isset($invitees[$c->getId()])) {
                $this->em->persist(InvitationSondage::pour($c, $reference->modify('+2 hours')));
            }
            // Une commande sur deux environ reçoit son avis, un à trois jours après.
            if (mt_rand(1, 100) <= 55) {
                $this->avisCommande($c, $reference->modify(\sprintf('+%d hours', mt_rand(5, 72))), $maintenant);
                ++$avis;
            }
            if (0 === $i % 100) {
                $this->em->flush();
            }
        }
        $this->em->flush();

        $clients = [];
        foreach ($commandes as $c) {
            $clients[$c->getClients()->getId()] = $c->getClients();
        }
        $clients = array_values($clients);
        $global = $this->sondageGlobal($clients, $maintenant);
        $libre = $this->sondageLibre($maintenant);
        $this->em->flush();

        $io->success(\sprintf('%d commandes livrées invitées, %d avis de commande, %d réponses au sondage « %s », %d au sondage « %s ».', \count($commandes), $avis, $global, self::GLOBAL, $libre, self::LIBRE));

        return Command::SUCCESS;
    }

    /** @return list<Commandes> commandes livrées des clients de démonstration, sur un an */
    private function commandes(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Commandes> $liste */
        $liste = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')->join('c.clients', 'cl')
            ->where('cl.code LIKE :demo')->andWhere('c.statut = true')->andWhere('c.deleted = false')->andWhere('c.dateCommande >= :an')
            ->setParameter('demo', 'DEMO-%')->setParameter('an', $maintenant->modify('-12 months'))
            ->orderBy('c.id')->getQuery()->getResult();

        return array_values(array_filter($liste, static fn (Commandes $c): bool => Sondages::estLivree($c) && Sondages::dateDeReference($c) < $maintenant));
    }

    private function avisCommande(Commandes $c, \DateTimeImmutable $le, \DateTimeImmutable $maintenant): void
    {
        $le = min($le, $maintenant);
        $globale = self::note();
        $saisie = [
            SondageCommande::GLOBALE => $globale,
            SondageCommande::DELAI => mt_rand(1, 100) <= 80 ? self::autour($globale) : null,
            SondageCommande::ACCUEIL => mt_rand(1, 100) <= 75 ? self::autour($globale + 1) : null,
            SondageCommande::NPS => mt_rand(1, 100) <= 70 ? self::nps($globale) : null,
            SondageCommande::PROPOSITION => mt_rand(1, 100) <= 30 ? self::PROPOSITIONS[mt_rand(0, \count(self::PROPOSITIONS) - 1)] : null,
            'articles' => [],
        ];
        $lignes = [];
        foreach (Sondages::articles($c) as $l) {
            $lignes[(string) $l->getPublicId()] = $l;
            if (mt_rand(1, 100) <= 70) {
                $saisie['articles'][(string) $l->getPublicId()] = self::autour($globale);
            }
        }
        $valide = SondageCommande::valider(array_keys($lignes), $saisie);
        $r = $valide['reponses'];
        $reponse = ReponseSondage::nouvelle(ReponseSondage::COMMANDE, $r, mt_rand(1, 100) <= 40 ? ReponseSondage::CANAL_ESPACE : ReponseSondage::CANAL_LIEN, $le)
            ->setCommande($c)->setClient($c->getClients())
            ->setNotes($r[SondageCommande::GLOBALE] ?? null, $r[SondageCommande::NPS] ?? null, $r[SondageCommande::DELAI] ?? null, $r[SondageCommande::ACCUEIL] ?? null)
            ->setProposition($r[SondageCommande::PROPOSITION] ?? null);
        foreach ($valide['articles'] as $id => $note) {
            $this->em->persist(NoteArticleSondage::pour($reponse, $lignes[$id], EspaceClient::titreLigne($lignes[$id]), $note));
        }
        $this->suites($reponse, $le, $maintenant);
        $this->em->persist($reponse);
    }

    /** Les anciennes alertes sont traitées, les anciennes propositions classées. */
    private function suites(ReponseSondage $r, \DateTimeImmutable $le, \DateTimeImmutable $maintenant): void
    {
        $r->analyser();
        $ancienne = $le < $maintenant->modify('-10 days');
        if ($ancienne && $r->alerteOuverte()) {
            mt_rand(1, 100) <= 80
                ? $r->traiterAlerte(ReponseSondage::ALERTE_RAPPELE, 'Client rappelé, geste commercial sur la prochaine commande.', self::PAR, $le->modify('+1 day'))
                : $r->traiterAlerte(ReponseSondage::ALERTE_SANS_SUITE, null, self::PAR, $le->modify('+1 day'));
        }
        if ($ancienne && null !== $r->getTheme() && mt_rand(1, 100) <= 50) {
            $r->classer($r->getTheme(), mt_rand(1, 100) <= 60 ? Themes::RETENUE : Themes::ECARTEE);
        }
    }

    /** @param list<Clients> $clients */
    private function sondageGlobal(array $clients, \DateTimeImmutable $maintenant): int
    {
        $s = (new Sondage())->setType(Sondage::GLOBAL)->setTitre(self::GLOBAL)->setSegment('tous')->setCreePar(self::PAR)
            ->setIntroduction('Quatre questions pour préparer l\'année prochaine avec vous.')
            ->setQuestions(Questions::normaliser([
                ['type' => Questions::ETOILES, 'libelle' => 'Dans l\'ensemble, êtes-vous satisfait de Succès Imprim ?', 'obligatoire' => true],
                ['type' => Questions::CHOIX_MULTIPLE, 'libelle' => 'Quels services aimeriez-vous trouver chez nous ?', 'choix' => "Impression grand format\nObjets publicitaires\nLivraison le jour même\nCréation de logo\nPaiement en plusieurs fois"],
                ['type' => Questions::OUI_NON, 'libelle' => 'Avez-vous un projet d\'impression dans les trois mois ?'],
                ['type' => Questions::NPS, 'libelle' => 'Recommanderiez-vous Succès Imprim à un proche ?'],
                ['type' => Questions::TEXTE, 'libelle' => Questions::PROPOSITION],
            ]));
        $ouvert = $maintenant->modify('-20 days');
        $s->ouvrir($ouvert, \count($clients));
        $this->em->persist($s);
        $choix = $s->getQuestions()[1]['choix'];
        $n = 0;
        foreach ($clients as $client) {
            if (mt_rand(1, 100) > 35) {
                continue;
            }
            $note = self::note();
            $r = Questions::valider($s->getQuestions(), [
                'q1' => $note,
                'q2' => array_values(array_filter($choix, static fn (): bool => mt_rand(1, 100) <= 35)),
                'q3' => mt_rand(0, 1) ? 'oui' : 'non',
                'q4' => self::nps($note),
                'q5' => mt_rand(1, 100) <= 40 ? self::PROPOSITIONS[mt_rand(0, \count(self::PROPOSITIONS) - 1)] : null,
            ]);
            $le = $ouvert->modify(\sprintf('+%d hours', mt_rand(2, 19 * 24)));
            $reponse = ReponseSondage::nouvelle(Sondage::GLOBAL, $r, ReponseSondage::CANAL_ESPACE, $le)
                ->setSondage($s)->setClient($client)->setNotes($r['q1'], $r['q4'] ?? null)->setProposition($r['q5'] ?? null);
            $this->suites($reponse, $le, $maintenant);
            $this->em->persist($reponse);
            ++$n;
        }

        return $n;
    }

    private function sondageLibre(\DateTimeImmutable $maintenant): int
    {
        $s = (new Sondage())->setType(Sondage::LIBRE)->setTitre(self::LIBRE)->setSegment('tous')->setCreePar(self::PAR)->setAnonymeAutorise(true)
            ->setIntroduction('Merci de votre visite ! Votre avis sur la journée, en trente secondes.')
            ->setQuestions(Questions::normaliser([
                ['type' => Questions::ETOILES, 'libelle' => 'Votre impression sur la journée', 'obligatoire' => true],
                ['type' => Questions::CHOIX_UNIQUE, 'libelle' => 'Comment nous avez-vous connus ?', 'choix' => "Bouche-à-oreille\nFacebook\nPassage devant l'imprimerie\nUn client nous a recommandés\nAutre"],
                ['type' => Questions::OUI_NON, 'libelle' => 'Souhaitez-vous recevoir nos offres par SMS ?'],
                ['type' => Questions::TEXTE, 'libelle' => Questions::PROPOSITION],
            ]));
        $ouvert = $maintenant->modify('-12 days');
        $s->ouvrir($ouvert, null);
        $this->em->persist($s);
        $choix = $s->getQuestions()[1]['choix'];
        for ($i = 0; $i < 26; ++$i) {
            $note = self::note();
            $r = Questions::valider($s->getQuestions(), [
                'q1' => $note,
                'q2' => $choix[mt_rand(0, \count($choix) - 1)],
                'q3' => mt_rand(1, 100) <= 60 ? 'oui' : 'non',
                'q4' => mt_rand(1, 100) <= 35 ? self::PROPOSITIONS[mt_rand(0, \count(self::PROPOSITIONS) - 1)] : null,
            ]);
            $le = $ouvert->modify(\sprintf('+%d minutes', mt_rand(30, 9 * 60)));
            $reponse = ReponseSondage::nouvelle(Sondage::LIBRE, $r, mt_rand(1, 100) <= 75 ? ReponseSondage::CANAL_QR : ReponseSondage::CANAL_LIEN, $le)
                ->setSondage($s)->setNotes($r['q1'], null)->setProposition($r['q4'] ?? null);
            if (mt_rand(1, 100) <= 40) {
                $reponse->setIdentite(self::NOMS[mt_rand(0, \count(self::NOMS) - 1)], '2237'.mt_rand(1000000, 9999999));
            }
            $this->suites($reponse, $le, $maintenant);
            $this->em->persist($reponse);
        }

        return 26;
    }

    private function purger(): int
    {
        $sondages = $this->em->getRepository(Sondage::class)->findBy(['titre' => [self::GLOBAL, self::LIBRE], 'creePar' => self::PAR]);
        $n = (int) $this->em->createQueryBuilder()->delete(ReponseSondage::class, 'r')
            ->where('r.client IN (SELECT cl.id FROM '.Clients::class.' cl WHERE cl.code LIKE :demo)')->orWhere('r.sondage IN (:s)')
            ->setParameter('demo', 'DEMO-%')->setParameter('s', $sondages ?: [0])->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(InvitationSondage::class, 'i')
            ->where('i.client IN (SELECT cl.id FROM '.Clients::class.' cl WHERE cl.code LIKE :demo)')
            ->setParameter('demo', 'DEMO-%')->getQuery()->execute();
        foreach ($sondages as $s) {
            $this->em->remove($s);
        }
        $this->em->flush();

        return $n;
    }

    /** Note sur 5, surtout bonne : 45 % de 5, 33 % de 4, 13 % de 3, 9 % de 1 ou 2. */
    private static function note(): int
    {
        $t = mt_rand(1, 100);

        return match (true) {
            $t <= 45 => 5,
            $t <= 78 => 4,
            $t <= 91 => 3,
            $t <= 96 => 2,
            default => 1,
        };
    }

    private static function autour(int $note): int
    {
        return max(1, min(5, $note + mt_rand(-1, 1) * (mt_rand(1, 100) <= 35 ? 1 : 0)));
    }

    private static function nps(int $note): int
    {
        return match ($note) {
            5 => mt_rand(8, 10),
            4 => mt_rand(7, 9),
            3 => mt_rand(5, 8),
            default => mt_rand(0, 5),
        };
    }
}
