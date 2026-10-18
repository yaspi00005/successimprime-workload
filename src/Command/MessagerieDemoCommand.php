<?php

namespace App\Command;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ControlePrePresse;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\User;
use App\Entity\MessageClient;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\NotesVocales;
use App\Service\Messagerie\ReglesReponse;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Conversations fictives des clients de démonstration (docs/messagerie-clients.md,
 * maquettes MS01 à MS05), lancée par app:demo après le chargement : les
 * t-shirts du Salon Espoir avec un BAT, un collègue ajouté, une relance en
 * retard et une note interne ; un devis répondu en retard ; une demande
 * clôturée et notée ; une clôture proposée ; une question de paiement non
 * assignée ; pour Amadou Sow, une question sans réponse et une ancienne
 * demande clôturée ; des notes vocales (client, réponse et note interne
 * sur les t-shirts, une d'Amadou Sow). Les messages passent par le vrai service : délais,
 * retards et points retirés sont ceux que l'application calcule.
 */
#[AsCommand(name: 'app:messagerie:demo', description: 'Conversations fictives des clients de démonstration, ou les enlève avec --purger.')]
final class MessagerieDemoCommand extends Command
{
    /** Ce que la démonstration crée ; aussi dans la purge d'app:demo. */
    public const PURGE = [
        "DELETE n FROM note_conversation n JOIN conversation_client c ON c.id = n.conversation_id JOIN clients cl ON cl.id = c.client_id WHERE cl.code LIKE 'DEMO-%' OR cl.id >= 900000",
        "DELETE m FROM message_client m JOIN clients cl ON cl.id = m.client_id WHERE cl.code LIKE 'DEMO-%' OR cl.id >= 900000",
        "UPDATE conversation_client c JOIN clients cl ON cl.id = c.client_id SET c.precedente_id = NULL WHERE cl.code LIKE 'DEMO-%' OR cl.id >= 900000",
        "DELETE c FROM conversation_client c JOIN clients cl ON cl.id = c.client_id WHERE cl.code LIKE 'DEMO-%' OR cl.id >= 900000",
        'DELETE FROM agent_messagerie WHERE utilisateur_id >= 900000',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connexion,
        private readonly Conversations $conversations,
        private readonly ReglesReponse $regles,
        private readonly NotesVocales $notesVocales,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève les conversations fictives au lieu de les créer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement || $this->enLigne) {
            $io->error('Démonstration refusée en production et sur le site en ligne.');

            return Command::FAILURE;
        }
        // Fichiers des notes vocales de la démonstration, avant leurs lignes.
        foreach ($this->connexion->fetchFirstColumn("SELECT n.vocale_nom FROM note_conversation n JOIN conversation_client c ON c.id = n.conversation_id JOIN clients cl ON cl.id = c.client_id WHERE n.vocale_nom IS NOT NULL AND (cl.code LIKE 'DEMO-%' OR cl.id >= 900000) UNION SELECT m.vocale_nom FROM message_client m JOIN clients cl ON cl.id = m.client_id WHERE m.vocale_nom IS NOT NULL AND (cl.code LIKE 'DEMO-%' OR cl.id >= 900000)") as $nom) {
            $this->notesVocales->retirer(['nom' => (string) $nom]);
        }
        foreach (self::PURGE as $sql) {
            $this->connexion->executeStatement($sql);
        }
        if ($input->getOption('purger')) {
            $io->success('Conversations fictives enlevées.');

            return Command::SUCCESS;
        }
        $salon = $this->em->find(Clients::class, 900002);
        $amadou = $this->em->find(Clients::class, 900195);
        if (null === $salon || null === $amadou) {
            $io->warning('Clients de démonstration absents : lancez d\'abord app:demo.');

            return Command::SUCCESS;
        }
        $mariam = $this->user(900004);
        $aminata = $this->user(900003);
        $seydou = $this->user(900006);
        $oumar = $this->compte($salon, 'Oumar Guindo');
        $dembele = $this->compte($salon, 'Mariam Dembélé');
        $ibrahim = $this->compte($salon, 'Ibrahim Touré');
        $sow = $this->compte($amadou, 'Amadou Sow');
        $n = 0;

        // 1. T-shirts du personnel (MS01, MS03) : BAT envoyé par la PAO ajoutée, validé, puis une relance en retard.
        $tshirts = $this->em->find(Commandes::class, 900769);
        if (null !== $tshirts && null !== $oumar) {
            $jour = \DateTimeImmutable::createFromInterface($tshirts->getDateCommande())->setTime(0, 0);
            $c = $this->conversations->ouvrir($oumar, $tshirts, null, null, null, 'Bonjour, c\'est pour les 40 t-shirts du personnel : quand est-ce que je reçois le BAT ? On les porte samedi pour l\'inauguration.', $jour->setTime(11, 20));
            $this->conversations->repondre($c, $mariam, 'Bonjour M. Guindo, bien reçu ! Le fichier est chez notre graphiste, vous avez le BAT demain matin.', null, null, $jour->setTime(11, 31));
            $lendemain = $jour->modify('+1 day');
            $this->conversations->ajouterParticipant($c, $seydou, $mariam, $lendemain->setTime(9, 38));
            $bat = $this->em->find(ControlePrePresse::class, 900512);
            $this->conversations->repondre($c, $seydou, 'Bonjour, voici le BAT : logo en blanc sur le cœur, nom de l\'hôtel au dos. Vérifiez bien les tailles avant de valider.', $bat, null, $lendemain->setTime(9, 44));
            $this->conversations->ecrireClient($oumar, $c, 'C\'est parfait, je viens de valider le BAT dans l\'espace. Merci !', $lendemain->setTime(10, 50));
            $this->conversations->repondre($c, $mariam, 'Merci ! L\'impression est lancée, livraison prévue vendredi matin.', null, null, $lendemain->setTime(11, 2));
            $c->setLuClientLe($lendemain->setTime(11, 30));
            // Notes vocales (docs/messagerie-clients.md) : le client explique sa demande à voix haute, puis l'écrit ;
            // la caisse en garde une pour l'équipe. Le dernier message reste la relance écrite.
            $vocale = $this->vocale();
            if (null !== $vocale) {
                $this->conversations->ecrireClient($oumar, $c, MessageClient::libelleVocale($vocale['duree']), $this->ilYA(97), $vocale);
            }
            $this->conversations->ecrireClient($oumar, $c, 'Bonjour, est-ce qu\'on peut ajouter 5 t-shirts taille XL ? Deux nouveaux serveurs arrivent lundi.', $this->ilYA(95));
            $this->conversations->noteInterne($c, $aminata, '@Mariam le client relance pour les 5 XL : il reste du stock en XL blanc, je peux préparer l\'ajout si tu veux.', $this->ilYA(12));
            $vocale = $this->vocale();
            if (null !== $vocale) {
                $this->conversations->noteInterne($c, $aminata, MessageClient::libelleVocale($vocale['duree']), $this->ilYA(10), $vocale);
            }
            ++$n;
        }

        // 2. Devis des plaques (DT01) : question d'une demandeuse, réponse en retard (montant masqué pour elle).
        $devis = $this->em->find(Devis::class, 900646);
        if (null !== $devis && null !== $dembele) {
            $hier = (new \DateTimeImmutable('yesterday'))->setTime(15, 2);
            $c = $this->conversations->ouvrir($dembele, null, $devis, null, null, 'Bonjour, le devis pour les plaques des chambres comprend-il la pose ?', $hier);
            $this->conversations->repondre($c, $mariam, 'Bonjour Mme Dembélé, oui, la pose est comprise dans le devis (16 000 F pour les 20 plaques). Il reste à le faire accepter par M. Guindo.', null, null, $hier->setTime(15, 45));
            $c->setLuClientLe($hier->setTime(16, 10));
            ++$n;
        }

        // 3. Roll-up de l'accueil (MS02) : clôture proposée, confirmée par le client, 5 étoiles.
        $rollup = $this->em->find(Commandes::class, 900770);
        if (null !== $rollup && null !== $oumar) {
            $j = (new \DateTimeImmutable('-4 days'))->setTime(9, 10);
            $c = $this->conversations->ouvrir($oumar, $rollup, null, null, null, 'Bonjour, le roll-up de l\'accueil est-il prêt ? Je peux passer à midi.', $j);
            $this->conversations->repondre($c, $mariam, 'Bonjour, oui il est prêt, emballé à l\'accueil. À tout à l\'heure !', null, null, $j->setTime(9, 18));
            $this->conversations->ecrireClient($oumar, $c, 'Récupéré, il est très beau. Merci à toute l\'équipe.', $j->setTime(12, 40));
            $this->conversations->repondre($c, $mariam, 'Avec plaisir ! Je clôture la demande ; vous pouvez la rouvrir si besoin.', null, null, $j->setTime(12, 52));
            $this->conversations->proposerCloture($c, $mariam, $j->setTime(12, 53));
            $this->conversations->repondreCloture($oumar, $c, true, $j->setTime(13, 30));
            $this->conversations->noter($oumar, $c, 5, $j->setTime(13, 31));
            ++$n;
        }

        // 4. Vitrine micro-perforée : la caisse propose la clôture, le client n'a pas encore répondu (MS02).
        $vitrine = $this->em->find(Commandes::class, 900739);
        if (null !== $vitrine && null !== $oumar) {
            $hier = (new \DateTimeImmutable('yesterday'))->setTime(10, 5);
            $c = $this->conversations->ouvrir($oumar, $vitrine, null, null, null, 'Bonjour, le micro-perforé est posé sur la vitrine, c\'est très réussi. Le roll-up aussi est bien arrivé.', $hier);
            $this->conversations->repondre($c, $aminata, 'Merci beaucoup M. Guindo ! Tout est en ordre de notre côté : votre facture est disponible dans l\'espace.', null, null, $hier->setTime(10, 21));
            $this->conversations->proposerCloture($c, $aminata, $hier->setTime(10, 22));
            ++$n;
        }

        // 5. Question générale de paiement (MS03 « Non assignées ») : le payeur demande une facture.
        if (null !== $ibrahim) {
            $this->conversations->ouvrir($ibrahim, null, null, 'Facture de septembre', ConversationClient::SUJET_PAIEMENT, 'Bonjour, pouvez-vous m\'envoyer la facture de septembre ? Notre comptable la demande pour la clôture du mois.', $this->ilYA(22));
            ++$n;
        }

        // 6. Amadou Sow : une ancienne demande clôturée (plus rouvrable), une commande répondue, une question générale en retard.
        $ancienne = $this->em->find(Commandes::class, 900501);
        $recente = $this->em->find(Commandes::class, 900632);
        if (null !== $sow && null !== $ancienne && null !== $recente) {
            $j = (new \DateTimeImmutable('-20 days'))->setTime(16, 0);
            $c = $this->conversations->ouvrir($sow, $ancienne, null, null, null, 'Bonjour, les cartes de visite sont-elles prêtes ?', $j);
            $this->conversations->repondre($c, $mariam, 'Bonjour M. Sow, oui, elles vous attendent à l\'accueil.', null, null, $j->setTime(16, 9));
            $this->conversations->proposerCloture($c, $mariam, $j->setTime(16, 10));
            $this->conversations->repondreCloture($sow, $c, true, $j->setTime(17, 0));
            $this->conversations->noter($sow, $c, 4, $j->setTime(17, 1));

            $j = (new \DateTimeImmutable('-2 days'))->setTime(9, 10);
            $c = $this->conversations->ouvrir($sow, $recente, null, null, null, 'Bonjour, je peux passer récupérer mon t-shirt demain matin ?', $j);
            $this->conversations->repondre($c, $mariam, 'Bonjour, oui sans problème : il est prêt, l\'accueil ouvre à 8 h.', null, null, $j->setTime(9, 14));
            $c->setLuClientLe($j->setTime(9, 30));
            $vocale = $this->vocale();
            if (null !== $vocale) {
                $this->conversations->repondre($c, $mariam, MessageClient::libelleVocale($vocale['duree']), null, null, $j->setTime(9, 16), $vocale);
            }

            $this->conversations->ouvrir($sow, null, null, 'Mugs personnalisés', ConversationClient::SUJET_AUTRE, 'Bonsoir, vous faites aussi les mugs avec photo ? Il m\'en faudrait 10 pour un anniversaire.', $this->ilYA(41));
            $n += 3;
        }
        $this->em->flush();
        // Les avis et clôtures n'attendent plus de réponse ; les compteurs restent ceux des réponses écrites.
        $io->success(\sprintf('%d conversations fictives créées (1re réponse sous %s, suivantes sous %s).', $n, ReglesReponse::duree($this->regles->premiere()), ReglesReponse::duree($this->regles->suivantes())));

        return Command::SUCCESS;
    }

    /** L'instant où le client a écrit, N minutes ouvrées avant maintenant (le chronomètre s'arrête hors des heures d'ouverture). */
    private function ilYA(int $minutesOuvrees): \DateTimeImmutable
    {
        $maintenant = new \DateTimeImmutable();
        $heures = $this->regles->heures();
        $t = $maintenant;
        for ($i = 0; $i < 30 * 24 * 12 && $heures->ecoule($t, $maintenant) < $minutesOuvrees; ++$i) {
            $t = $t->modify('-5 minutes');
        }

        return $t;
    }

    /**
     * Une note vocale de démonstration (donnees-demo/note-vocale-demo.ogg),
     * rangée comme un vrai envoi.
     *
     * @return array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null
     */
    private function vocale(): ?array
    {
        $contenu = @file_get_contents($this->projet.'/donnees-demo/note-vocale-demo.ogg');

        return false === $contenu ? null : $this->notesVocales->ranger($contenu);
    }

    private function user(int $id): User
    {
        return $this->em->find(User::class, $id) ?? throw new \RuntimeException('Utilisateur de démonstration '.$id.' absent.');
    }

    private function compte(Clients $client, string $nom): ?CompteClient
    {
        return $this->em->getRepository(CompteClient::class)->findOneBy(['client' => $client, 'nom' => $nom]);
    }
}
