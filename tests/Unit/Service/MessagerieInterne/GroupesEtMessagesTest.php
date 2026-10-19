<?php

namespace App\Tests\Unit\Service\MessagerieInterne;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conversation;
use App\Entity\Devis;
use App\Entity\Employes;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Service\MessagerieInterne\Groupes;
use App\Service\MessagerieInterne\MessagesEquipe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** Groupes de projet fermés à la livraison, @mentions des membres seulement. */
final class GroupesEtMessagesTest extends TestCase
{
    private static function personne(int $id, string $prenom, string $nom): User
    {
        $u = (new User())->setRoles(['ROLE_PRODUCTION'])->definirDroits(['ROLE_MESSAGERIE_INTERNE'])->setUsername(strtolower('demo.'.$prenom.$id))
            ->setEmploye((new Employes())->setPrenom($prenom)->setNom($nom));
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private static function commande(string $production): Commandes
    {
        $c = (new Commandes())->setNumero('CMD-PM0182')->setStatut(true);
        $c->addCommandesDetail((new CommandesDetails())->setDesignation('Kakémonos')->setQuantite(4)->setStatutProduction($production));

        return $c;
    }

    /** @param list<Conversation> $projets */
    private static function groupes(array $projets): Groupes
    {
        $depot = new class($projets) extends ConversationRepository {
            /** @param list<Conversation> $projets */
            public function __construct(private readonly array $projets)
            {
            }

            public function findProjetsAFermer(?array $commandes): array
            {
                return $this->projets;
            }
        };

        return new Groupes(self::createStub(EntityManagerInterface::class), $depot);
    }

    public function testLeGroupeDeProjetSeFermeALaLivraison(): void
    {
        $chef = self::personne(1, 'Ibrahim', 'Traoré');
        $livree = self::commande(CommandesDetails::PRODUCTION_LIVREE);
        $enCours = self::commande(CommandesDetails::PRODUCTION_EN_COURS);
        $projet = (new Conversation())->setType(Conversation::PROJET)->setNom('Projet Hôtel Niger')->setCommande($livree)->setFermetureAuto(true)->setCreePar($chef)->ajouterParticipant($chef, true);
        $autre = (new Conversation())->setType(Conversation::PROJET)->setNom('Projet en cours')->setCommande($enCours)->setFermetureAuto(true)->setCreePar($chef);
        // Lié à un devis : c'est la commande du devis qui compte.
        $parDevis = (new Conversation())->setType(Conversation::PROJET)->setNom('Projet devis')->setDevis((new Devis())->setCommande(self::commande(CommandesDetails::PRODUCTION_LIVREE)))->setFermetureAuto(true)->setCreePar($chef);

        $n = self::groupes([$projet, $autre, $parDevis])->fermerLivres(null, new \DateTimeImmutable('2026-10-20 11:31'));

        self::assertSame(2, $n);
        self::assertTrue($projet->estFerme());
        self::assertSame('commande CMD-PM0182 livrée', $projet->getFermeMotif());
        self::assertFalse($autre->estFerme());
        self::assertTrue($parDevis->estFerme());
        $derniere = $projet->getMessages()->last();
        self::assertInstanceOf(Message::class, $derniere);
        self::assertTrue($derniere->estSysteme());
        self::assertStringContainsString('reste lisible', $derniere->getContenu());
    }

    public function testMentionsDesMembresSeulement(): void
    {
        $oumar = self::personne(2, 'Oumar', 'Sangaré');
        $seydou = self::personne(3, 'Seydou', 'Diarra');
        $seydou2 = self::personne(4, 'Seydou', 'Konaté');
        $bakary = self::personne(5, 'Bakary', 'Coulibaly');
        $atelier = (new Conversation())->setType(Conversation::PERMANENT)->setNom('Atelier')->ajouterParticipant($oumar)->ajouterParticipant($seydou)->ajouterParticipant($bakary);
        $messages = (new \ReflectionClass(MessagesEquipe::class))->newInstanceWithoutConstructor();

        // @Seydou désigne un seul membre ; @Kadiatou n'est pas membre ; l'auteur ne se cite pas.
        self::assertSame([3], $messages->mentions($atelier, $oumar, '@Seydou le fichier, @Kadiatou et @Oumar', []));
        self::assertSame([5, 3], $messages->mentions($atelier, $oumar, '@Bakary @seydou.diarra', []));
        // Choisi dans l'autocomplétion mais effacé du texte : pas de mention.
        self::assertSame([], $messages->mentions($atelier, $oumar, 'merci', [3]));
        // Choix de l'autocomplétion pour un non-membre (Seydou Konaté) : ignoré.
        self::assertSame([3], $messages->mentions($atelier, $oumar, '@Seydou', [4]));
        // Deux Seydou dans le groupe : « @Seydou » ne suffit plus, le choix de l'autocomplétion tranche.
        $atelier->ajouterParticipant($seydou2);
        self::assertSame([], $messages->mentions($atelier, $oumar, 'bonjour @Seydou', []));
        self::assertSame([4], $messages->mentions($atelier, $oumar, 'bonjour @Seydou', [4]));
    }
}
