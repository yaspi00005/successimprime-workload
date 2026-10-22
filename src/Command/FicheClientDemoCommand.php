<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\ContactClient;
use App\Entity\NoteClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Complète la fiche de Salon Espoir (DEMO-0002) pour montrer la fiche client
 * des maquettes FC01 à FC04 (docs/clients.md) : étiquette VIP, factures
 * envoyées par WhatsApp et e-mail à un contact « Factures », charte du
 * client et quelques notes internes de l'équipe.
 *
 * Lancée par app:demo après les conversations. Relancée : rien de plus si
 * les notes sont déjà là. app:demo --purger enlève tout (id >= 900000).
 * Refusée en production ; rien n'est créé sur le site en ligne (les notes
 * internes n'y vont jamais, le reste arrive par la synchronisation).
 */
#[AsCommand(name: 'app:fiche-client:demo', description: 'Conditions, charte, contact « Factures » et notes internes fictifs sur la fiche de Salon Espoir (DEMO-0002).')]
final class FicheClientDemoCommand extends Command
{
    public const CLIENT = 'DEMO-0002';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de fiche client fictive en production.');

            return Command::FAILURE;
        }
        if ($this->enLigne) {
            $io->note('Site en ligne : la fiche client n\'existe que dans l\'ERP local.');

            return Command::SUCCESS;
        }
        $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => self::CLIENT]);
        if (!$client instanceof Clients) {
            $io->warning(\sprintf('Client %s introuvable : chargez d\'abord la démonstration (app:demo).', self::CLIENT));

            return Command::SUCCESS;
        }
        if (null !== $this->em->getRepository(NoteClient::class)->findOneBy(['client' => $client, 'type' => 'note'])) {
            $io->note('Fiche client de démonstration déjà en place.');

            return Command::SUCCESS;
        }
        $direction = $this->utilisateur('demo.kadiatou');
        $caisse = $this->utilisateur('demo.aminata');
        $graphiste = $this->utilisateur('demo.seydou');

        $client->setEtiquettes(array_merge(['VIP'], $client->getEtiquettes()));
        $client->setFacturesCanaux(['whatsapp', 'email']);
        $client->setCharteCouleurs(['#7A1F5C', '#F2B8C6', '#1E1E1E']);
        $client->setCharteTexte('Logo toujours sur fond clair, police Montserrat, pas de photo de cliente sans accord.');

        $factures = (new ContactClient($client, 'Mariam Coulibaly'))
            ->setFonction('Comptable')
            ->setTelephone('70000002')
            ->setWhatsapp(true)
            ->setEmail('compta@salon-espoir.example')
            ->setRole('factures');
        $this->em->persist($factures);

        $maintenant = new \DateTimeImmutable();
        $notes = [
            [$direction, '-40 days', 'Client fidèle depuis l\'ouverture du salon. Accord de la direction : plafond de crédit à 100 000 F, paiement à 45 jours par virement.'],
            [$caisse, '-12 days', 'Préfère être appelée après 15 h. Les factures partent à la comptable (Mariam) sur WhatsApp, copie par e-mail.'],
            [$graphiste, '-3 days', 'Nouvelle charte depuis septembre : bien reprendre le prune (#7A1F5C) sur la bâche façade.'],
        ];
        foreach ($notes as [$auteur, $quand, $texte]) {
            $this->em->persist(new NoteClient($client, $texte, 'note', $auteur, $maintenant->modify($quand)));
        }
        $this->em->flush();

        $io->success('Fiche client de démonstration : Salon Espoir (VIP, factures par WhatsApp et e-mail, charte, 3 notes internes).');

        return Command::SUCCESS;
    }

    private function utilisateur(string $identifiant): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => $identifiant]);
    }
}
