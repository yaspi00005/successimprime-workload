<?php

namespace App\Tests\Unit\Security;

use App\Entity\CompteClient;
use App\Entity\Conversation;
use App\Entity\User;
use App\Security\MessagerieInterneVoter;
use App\Service\MessagerieInterne\ReglesMessagerieInterne;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Messagerie de l'équipe (docs/messagerie-interne.md) : un client jamais,
 * un non-membre jamais, la Direction lit les groupes (selon le réglage)
 * mais jamais les messages privés, les annonces pour les responsables.
 */
final class MessagerieInterneVoterTest extends TestCase
{
    private User $direction;
    private User $oumar;
    private User $seydou;
    private User $bakary;
    private Conversation $atelier;
    private Conversation $prive;

    protected function setUp(): void
    {
        $this->direction = self::utilisateur(1, ['ROLE_ADMIN']);
        $this->oumar = self::utilisateur(2, ['ROLE_PRODUCTION', 'ROLE_MESSAGERIE_INTERNE']);
        $this->seydou = self::utilisateur(3, ['ROLE_GRAPHISTE', 'ROLE_MESSAGERIE_INTERNE']);
        $this->bakary = self::utilisateur(4, ['ROLE_LIVREUR', 'ROLE_MESSAGERIE_INTERNE']);
        $this->atelier = (new Conversation())->setType(Conversation::PERMANENT)->setNom('Atelier')
            ->ajouterParticipant($this->oumar, true)->ajouterParticipant($this->seydou);
        $this->prive = (new Conversation())->setType(Conversation::PRIVE)->ajouterParticipant($this->oumar)->ajouterParticipant($this->seydou);
    }

    /**
     * Rôle de base dans la colonne roles, droits cochés sur la fiche (Réglages › Rôles et droits).
     *
     * @param list<string> $roles
     */
    private static function utilisateur(int $id, array $roles): User
    {
        $droits = array_values(array_filter($roles, static fn (string $r): bool => str_starts_with($r, 'ROLE_MESSAGERIE_')));
        $u = (new User())->setRoles(array_values(array_diff($roles, $droits)))->definirDroits($droits);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private static function voter(bool $directionLitGroupes = true): MessagerieInterneVoter
    {
        return new MessagerieInterneVoter(new class($directionLitGroupes) extends ReglesMessagerieInterne {
            public function __construct(private readonly bool $lit)
            {
            }

            public function directionLitGroupes(): bool
            {
                return $this->lit;
            }
        });
    }

    private function vote(?object $qui, mixed $sujet, string $attribut, bool $directionLitGroupes = true): int
    {
        $jeton = match (true) {
            $qui instanceof User => new UsernamePasswordToken($qui, 'main', $qui->getRoles()),
            $qui instanceof CompteClient => new UsernamePasswordToken($qui, 'espace', $qui->getRoles()),
            default => new NullToken(),
        };

        return self::voter($directionLitGroupes)->vote($jeton, $sujet, [$attribut]);
    }

    public function testUnClientNeVoitJamaisLaMessagerie(): void
    {
        $client = new CompteClient();
        foreach ([MessagerieInterneVoter::ACCES, MessagerieInterneVoter::CREER_GROUPE] as $attribut) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($client, null, $attribut));
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, null, $attribut));
        }
        foreach ([MessagerieInterneVoter::VOIR, MessagerieInterneVoter::ECRIRE, MessagerieInterneVoter::FICHIERS] as $attribut) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($client, $this->atelier, $attribut));
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($client, $this->prive, $attribut));
        }
    }

    public function testUnNonMembreNeLitNiNEcrit(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->bakary, null, MessagerieInterneVoter::ACCES));
        foreach ([MessagerieInterneVoter::VOIR, MessagerieInterneVoter::ECRIRE, MessagerieInterneVoter::FICHIERS, MessagerieInterneVoter::GERER] as $attribut) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->bakary, $this->atelier, $attribut), $attribut);
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->bakary, $this->prive, $attribut), $attribut);
        }
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->seydou, $this->atelier, MessagerieInterneVoter::VOIR));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->seydou, $this->prive, MessagerieInterneVoter::ECRIRE));
    }

    public function testSansLeDroitPasDeMessagerie(): void
    {
        $sansDroit = self::utilisateur(9, ['ROLE_PRODUCTION']);
        $this->atelier->ajouterParticipant($sansDroit);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($sansDroit, null, MessagerieInterneVoter::ACCES));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($sansDroit, $this->atelier, MessagerieInterneVoter::VOIR));
        $inactif = self::utilisateur(10, ['ROLE_PRODUCTION', 'ROLE_MESSAGERIE_INTERNE'])->setActif(false);
        $this->atelier->ajouterParticipant($inactif);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($inactif, $this->atelier, MessagerieInterneVoter::VOIR));
    }

    public function testLaDirectionLitLesGroupesJamaisLesMessagesPrives(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::VOIR));
        self::assertTrue(MessagerieInterneVoter::lectureDirection($this->direction, $this->atelier));
        // Lire n'est pas écrire : elle n'est pas membre implicite.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::ECRIRE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::GERER));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->direction, $this->prive, MessagerieInterneVoter::VOIR));
        self::assertFalse(MessagerieInterneVoter::lectureDirection($this->direction, $this->prive));
    }

    public function testLeReglageCoupeLaLectureDeLaDirection(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::VOIR, false));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->direction, $this->prive, MessagerieInterneVoter::VOIR, false));
        // Membre du groupe, elle le lit comme tout le monde.
        $this->atelier->ajouterParticipant($this->direction);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::VOIR, false));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->direction, $this->atelier, MessagerieInterneVoter::ECRIRE, false));
    }

    public function testAnnoncesFichiersEtGroupeFerme(): void
    {
        $annonces = (new Conversation())->setType(Conversation::ANNONCES)->setNom('Annonces')->ajouterParticipant($this->oumar, true)->ajouterParticipant($this->seydou);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->oumar, $annonces, MessagerieInterneVoter::ECRIRE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->seydou, $annonces, MessagerieInterneVoter::ECRIRE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->seydou, $annonces, MessagerieInterneVoter::VOIR));

        $this->atelier->setFichiersPourTous(false);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->oumar, $this->atelier, MessagerieInterneVoter::FICHIERS));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->seydou, $this->atelier, MessagerieInterneVoter::FICHIERS));

        $this->atelier->fermer(new \DateTimeImmutable(), 'commande livrée');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->seydou, $this->atelier, MessagerieInterneVoter::VOIR));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->oumar, $this->atelier, MessagerieInterneVoter::ECRIRE));
    }

    public function testCreerDesGroupes(): void
    {
        $chef = self::utilisateur(5, ['ROLE_PRODUCTION', 'ROLE_MESSAGERIE_INTERNE', 'ROLE_MESSAGERIE_GROUPES']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($chef, null, MessagerieInterneVoter::CREER_GROUPE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->direction, null, MessagerieInterneVoter::CREER_GROUPE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->oumar, null, MessagerieInterneVoter::CREER_GROUPE));
    }
}
