<?php

namespace App\Tests\Unit\Security;

use App\Entity\CommandeDetailFichier;
use App\Entity\CompteClient;
use App\Entity\Conversation;
use App\Entity\Employes;
use App\Entity\Etiquette;
use App\Entity\LotEtiquette;
use App\Entity\Message;
use App\Entity\Reclamation;
use App\Entity\User;
use App\Security\FichiersPrivesVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Yaml\Yaml;

/**
 * Fichiers privés (chat, pré-presse, étiquettes, justificatifs, photos du
 * personnel) : les mêmes droits que la page qui les montre, jamais pour un
 * visiteur ni un compte client (docs/securite.md).
 */
final class FichiersPrivesVoterTest extends TestCase
{
    private FichiersPrivesVoter $voter;
    private User $admin;
    private User $caisse;
    private User $graphiste;
    private User $operateur;
    private User $livreur;

    protected function setUp(): void
    {
        // Hiérarchie réelle de security.yaml : ROLE_ADMIN comprend la prépresse.
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $decisions = new AccessDecisionManager([new RoleHierarchyVoter(new RoleHierarchy($config['security']['role_hierarchy']))]);
        $this->voter = new FichiersPrivesVoter($decisions);

        $this->admin = (new User())->setRoles(['ROLE_ADMIN']);
        // Droits cochés sur la fiche, comme dans Réglages › Rôles et droits.
        $this->caisse = (new User())->setRoles(['ROLE_CAISSE_COMMANDE'])->definirDroits(['ROLE_COMMANDE', 'ROLE_CLIENT', 'ROLE_PAIEMENT_ENCAISSER']);
        $this->graphiste = (new User())->setRoles(['ROLE_GRAPHISTE'])->definirDroits(['ROLE_PREPRESSE']);
        $this->operateur = (new User())->setRoles(['ROLE_PRODUCTION'])->definirDroits(['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER']);
        $this->livreur = (new User())->setRoles(['ROLE_LIVREUR'])->definirDroits(['ROLE_LIVRAISON']);
    }

    private function vote(?object $qui, mixed $sujet, string $attribut): int
    {
        $jeton = match (true) {
            $qui instanceof User => new UsernamePasswordToken($qui, 'main', $qui->getRoles()),
            $qui instanceof CompteClient => new UsernamePasswordToken($qui, 'espace', $qui->getRoles()),
            default => new NullToken(),
        };
        \assert($jeton instanceof TokenInterface);

        return $this->voter->vote($jeton, $sujet, [$attribut]);
    }

    public function testChatSeulementPourLesParticipants(): void
    {
        $conversation = (new Conversation())->ajouterParticipant($this->caisse)->ajouterParticipant($this->graphiste);
        $message = (new Message())->setConversation($conversation);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->caisse, $message, FichiersPrivesVoter::CHAT));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->graphiste, $message, FichiersPrivesVoter::CHAT));
        // Même la direction n'ouvre pas la conversation des autres.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->admin, $message, FichiersPrivesVoter::CHAT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->livreur, $message, FichiersPrivesVoter::CHAT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, $message, FichiersPrivesVoter::CHAT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new Message(), new Message(), FichiersPrivesVoter::CHAT), 'message sans conversation');
        // Compte désactivé : plus rien.
        $this->caisse->setActif(false);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->caisse, $message, FichiersPrivesVoter::CHAT));
    }

    public function testPrePressePourLaPrepresseEtLaProduction(): void
    {
        $fichier = new CommandeDetailFichier();

        foreach ([$this->admin, $this->graphiste, $this->operateur] as $qui) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($qui, $fichier, FichiersPrivesVoter::PREPRESSE), implode(',', $qui->getRoles()));
        }
        foreach ([$this->caisse, $this->livreur, null, new CompteClient()] as $qui) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($qui, $fichier, FichiersPrivesVoter::PREPRESSE));
        }
    }

    public function testEtiquettesALaDirection(): void
    {
        foreach ([new Etiquette(), new LotEtiquette()] as $sujet) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin, $sujet, FichiersPrivesVoter::ETIQUETTE));
            foreach ([$this->caisse, $this->graphiste, $this->operateur, null, new CompteClient()] as $qui) {
                self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($qui, $sujet, FichiersPrivesVoter::ETIQUETTE));
            }
        }
    }

    public function testJustificatifALAgentALaCaisseEtALaDirection(): void
    {
        $demande = (new Reclamation())->setAgent($this->livreur);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->livreur, $demande, FichiersPrivesVoter::JUSTIFICATIF), 'sa propre demande');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->caisse, $demande, FichiersPrivesVoter::JUSTIFICATIF), 'la caisse qui rembourse');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->admin, $demande, FichiersPrivesVoter::JUSTIFICATIF));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->graphiste, $demande, FichiersPrivesVoter::JUSTIFICATIF), 'un collègue');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, $demande, FichiersPrivesVoter::JUSTIFICATIF));
    }

    public function testPhotosDuPersonnelPourLePersonnelSeulement(): void
    {
        $employe = new Employes();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->livreur, $employe, FichiersPrivesVoter::PHOTO_EMPLOYE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, $employe, FichiersPrivesVoter::PHOTO_EMPLOYE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CompteClient(), $employe, FichiersPrivesVoter::PHOTO_EMPLOYE));
    }

    public function testSujetsEtAttributsInconnusIgnores(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->admin, new Etiquette(), FichiersPrivesVoter::CHAT));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->admin, null, FichiersPrivesVoter::PHOTO_EMPLOYE));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->admin, new Message(), 'ROLE_ADMIN'));
    }
}
