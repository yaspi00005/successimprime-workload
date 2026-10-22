<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Conversation;
use App\Entity\Devis;
use App\Entity\Message;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Security\MessagerieInterneVoter;
use App\Service\Sondages\Sondages;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Groupes et messages privés de l'équipe (docs/messagerie-interne.md, MI02).
 *
 * - Groupe permanent, de projet (lié à un client, un devis ou une commande,
 *   fermé tout seul à la livraison de la commande) ou d'annonces (seuls les
 *   responsables du groupe écrivent).
 * - Membres un par un ou par rôle (« tout l'atelier ») : les personnes d'un
 *   rôle ajouté entrent aussi quand elles arrivent plus tard, sauf si on les
 *   a retirées du groupe.
 * - Groupes par défaut : Général, Atelier, Prépresse, Caisse et finances.
 */
class Groupes
{
    /**
     * Groupes créés par l'application (app:messagerie-interne:groupes et la
     * migration) : clé => [nom, description, couleur, rôles membres].
     */
    public const PAR_DEFAUT = [
        'general' => ['Général', 'toute l\'imprimerie', 'bleu', ['ROLE_ADMIN', 'ROLE_CAISSE_COMMANDE', 'ROLE_GRAPHISTE', 'ROLE_PRODUCTION', 'ROLE_LIVREUR']],
        'atelier' => ['Atelier', 'groupe de travail', 'vert', ['ROLE_PRODUCTION', 'ROLE_GRAPHISTE', 'ROLE_LIVREUR']],
        'prepresse' => ['Prépresse', 'fichiers, BAT, corrections', 'rose', ['ROLE_GRAPHISTE', 'ROLE_PRODUCTION']],
        'caisse' => ['Caisse et finances', 'caisse, encaissements, clôtures', 'ocre', ['ROLE_ADMIN', 'ROLE_CAISSE_COMMANDE']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
    ) {
    }

    /**
     * Crée un groupe (n'appelle pas flush pour les membres seulement : tout est enregistré ici).
     *
     * @param array{nom: string, type: string, description?: ?string, couleur?: ?string, client?: ?Clients, devis?: ?Devis, commande?: ?Commandes, fermetureAuto?: bool, fichiersPourTous?: bool, rangerDossierClient?: bool, roles?: list<string>, membres?: list<User>} $donnees
     *
     * @throws \DomainException
     */
    public function creer(User $createur, array $donnees): Conversation
    {
        $nom = trim($donnees['nom']);
        if ('' === $nom) {
            throw new \DomainException('Donnez un nom au groupe.');
        }
        $groupe = (new Conversation())
            ->setType($donnees['type'])
            ->setNom($nom)
            ->setDescription($donnees['description'] ?? null)
            ->setCouleur($donnees['couleur'] ?? ($donnees['type'] === Conversation::PROJET ? 'marine' : 'bleu'))
            ->setFichiersPourTous($donnees['fichiersPourTous'] ?? true)
            ->setRolesMembres($donnees['roles'] ?? [])
            ->setCreePar($createur);
        if ($groupe->estPrive()) {
            throw new \DomainException('Type de groupe inconnu.');
        }
        if ($groupe->estProjet()) {
            $commande = $donnees['commande'] ?? null;
            $devis = $donnees['devis'] ?? null;
            $client = $donnees['client'] ?? $commande?->getClients() ?? $devis?->getClients();
            $groupe->setClient($client)->setCommande($commande)->setDevis($devis)
                ->setFermetureAuto(($donnees['fermetureAuto'] ?? true) && (null !== $commande || null !== $devis))
                ->setRangerDossierClient(($donnees['rangerDossierClient'] ?? false) && null !== $client);
        }
        $groupe->ajouterParticipant($createur, true);
        foreach ($donnees['membres'] ?? [] as $membre) {
            if ($membre->isActif() && MessagerieInterneVoter::aAcces($membre)) {
                $groupe->ajouterParticipant($membre);
            }
        }
        $this->em->persist($groupe);
        $this->ajouterParRoles($groupe);
        $this->systeme($groupe, $createur, \sprintf('%s a créé le groupe « %s ».', Conversation::nomDe($createur), $groupe->getNom()));
        $this->em->flush();

        return $groupe;
    }

    /** Ajoute au groupe les personnes actives des rôles du groupe (sauf celles qui en ont été retirées). */
    public function ajouterParRoles(Conversation $groupe): int
    {
        $roles = $groupe->getRolesMembres();
        if ([] === $roles || $groupe->estPrive()) {
            return 0;
        }
        $n = 0;
        foreach ($this->em->getRepository(User::class)->findBy(['actif' => true]) as $u) {
            if ($u instanceof User && self::aUnRole($u, $roles) && !$groupe->estExclu($u) && !$groupe->aPourParticipant($u) && MessagerieInterneVoter::aAcces($u)) {
                $groupe->ajouterParticipant($u);
                ++$n;
            }
        }

        return $n;
    }

    /**
     * À l'ouverture de la messagerie : la personne entre dans les groupes de
     * son rôle qu'elle n'a pas encore (arrivée après leur création).
     */
    public function rejoindreGroupesDeRole(User $moi): void
    {
        if (!MessagerieInterneVoter::aAcces($moi)) {
            return;
        }
        $change = false;
        foreach ($this->conversations->findGroupesOuverts() as $groupe) {
            if ([] !== $groupe->getRolesMembres() && self::aUnRole($moi, $groupe->getRolesMembres()) && !$groupe->estExclu($moi) && !$groupe->aPourParticipant($moi)) {
                $groupe->ajouterParticipant($moi);
                $change = true;
            }
        }
        if ($change) {
            $this->em->flush();
        }
    }

    /** Rôle de base (Caisse, Graphiste…) ou Direction : la colonne « roles » de la personne. */
    public static function aUnRole(User $u, array $roles): bool
    {
        return [] !== array_intersect($roles, $u->getRoles());
    }

    /** Message privé entre deux personnes : le fil existant, sinon un nouveau. */
    public function prive(User $moi, User $autre): Conversation
    {
        if ($moi->getId() === $autre->getId()) {
            throw new \DomainException('Choisissez une autre personne.');
        }
        if (!$autre->isActif() || !MessagerieInterneVoter::aAcces($autre)) {
            throw new \DomainException('Cette personne n\'a pas accès à la messagerie de l\'équipe.');
        }
        $existant = $this->conversations->trouverConversationDirecte($moi, $autre);
        if (null !== $existant) {
            return $existant;
        }
        $c = (new Conversation())->setType(Conversation::PRIVE)->ajouterParticipant($moi)->ajouterParticipant($autre);
        $this->em->persist($c);
        $this->em->flush();

        return $c;
    }

    public function ajouterMembre(Conversation $groupe, User $par, User $membre): void
    {
        if (!MessagerieInterneVoter::aAcces($membre) || $groupe->aPourParticipant($membre)) {
            return;
        }
        $groupe->reintegrer($membre)->ajouterParticipant($membre);
        $this->systeme($groupe, $par, \sprintf('%s a ajouté %s.', Conversation::nomDe($par), Conversation::nomDe($membre)));
        $this->em->flush();
    }

    /** @param list<string> $roles */
    public function ajouterRoles(Conversation $groupe, User $par, array $roles): int
    {
        $groupe->setRolesMembres([...$groupe->getRolesMembres(), ...$roles]);
        $n = $this->ajouterParRoles($groupe);
        if ($n > 0) {
            $this->systeme($groupe, $par, \sprintf('%s a ajouté %d personne%s par rôle.', Conversation::nomDe($par), $n, $n > 1 ? 's' : ''));
        }
        $this->em->flush();

        return $n;
    }

    /** @throws \DomainException */
    public function retirerMembre(Conversation $groupe, User $par, User $membre): void
    {
        if (!$groupe->aPourParticipant($membre)) {
            return;
        }
        $admins = array_filter($groupe->getParticipants()->toArray(), static fn ($p): bool => $p->isAdmin());
        if ($groupe->estAdmin($membre) && \count($admins) <= 1) {
            throw new \DomainException('Le groupe garde au moins un responsable.');
        }
        $groupe->retirerParticipant($membre)->exclure($membre);
        $texte = $par->getId() === $membre->getId() ? '%s a quitté le groupe.' : '%2$s a été retiré par %1$s.';
        $this->systeme($groupe, $par, \sprintf($texte, Conversation::nomDe($par), Conversation::nomDe($membre)));
        $this->em->flush();
    }

    public function fermer(Conversation $groupe, User $par, ?string $motif = null): void
    {
        if ($groupe->estFerme()) {
            return;
        }
        $groupe->fermer(new \DateTimeImmutable(), $motif ?? 'fermé par '.Conversation::nomDe($par));
        $this->systeme($groupe, $par, 'Groupe fermé : '.$groupe->getFermeMotif().'. Il reste lisible.');
        $this->em->flush();
    }

    public function rouvrir(Conversation $groupe, User $par): void
    {
        if (!$groupe->estFerme()) {
            return;
        }
        $groupe->rouvrir();
        $this->systeme($groupe, $par, Conversation::nomDe($par).' a rouvert le groupe.');
        $this->em->flush();
    }

    /**
     * Ferme les groupes de projet dont la commande suivie est livrée
     * (fermeture automatique à la livraison). Rend le nombre fermé.
     *
     * @param list<int>|null $commandes ids des commandes à regarder (null : toutes)
     */
    public function fermerLivres(?array $commandes = null, ?\DateTimeImmutable $maintenant = null): int
    {
        $maintenant ??= new \DateTimeImmutable();
        $n = 0;
        foreach ($this->conversations->findProjetsAFermer($commandes) as $groupe) {
            $commande = $groupe->getCommandeSuivie();
            if (null === $commande || !self::estLivree($commande)) {
                continue;
            }
            $groupe->fermer($maintenant, \sprintf('commande %s livrée', $commande->getNumero()));
            $auteur = $groupe->getCreePar() ?? $groupe->getMembres()[0] ?? null;
            if (null !== $auteur) {
                $this->systeme($groupe, $auteur, \sprintf('Groupe fermé tout seul : la commande %s est livrée. Il reste lisible.', $commande->getNumero()), $maintenant);
            }
            ++$n;
        }
        if ($n > 0) {
            $this->em->flush();
        }

        return $n;
    }

    public static function estLivree(Commandes $c): bool
    {
        return Sondages::estLivree($c);
    }

    /**
     * Crée les groupes par défaut qui manquent (Général, Atelier, Prépresse,
     * Caisse et finances) avec leurs membres par rôle. Relancer ne crée rien
     * en double. Rend les noms créés.
     *
     * @return list<string>
     */
    public function creerParDefaut(?User $createur = null): array
    {
        $crees = [];
        $rolesExistants = array_map(static fn (RoleUtilisateur $r): string => $r->getCode(), $this->em->getRepository(RoleUtilisateur::class)->findAll());
        foreach (self::PAR_DEFAUT as $cle => [$nom, $description, $couleur, $roles]) {
            $groupe = $this->conversations->findOneBy(['parDefaut' => $cle]);
            if (null !== $groupe) {
                continue;
            }
            $groupe = (new Conversation())->setType(Conversation::PERMANENT)->setNom($nom)->setDescription($description)
                ->setCouleur($couleur)->setParDefaut($cle)->setCreePar($createur)
                ->setRolesMembres(array_values(array_filter($roles, static fn (string $r): bool => 'ROLE_ADMIN' === $r || \in_array($r, $rolesExistants, true) || [] === $rolesExistants)));
            $this->em->persist($groupe);
            $this->ajouterParRoles($groupe);
            // Les responsables : la Direction membre, sinon personne (la Direction gère les groupes depuis la supervision).
            foreach ($groupe->getParticipants() as $p) {
                if (null !== $p->getUtilisateur() && \in_array(User::ROLE_ADMIN, $p->getUtilisateur()->getRoles(), true)) {
                    $p->setAdmin(true);
                }
            }
            $crees[] = $nom;
        }
        $this->em->flush();

        return $crees;
    }

    /** Ligne « X a créé le groupe » dans le fil (pas un message de quelqu'un). */
    public function systeme(Conversation $c, User $auteur, string $texte, ?\DateTimeImmutable $le = null): Message
    {
        $m = (new Message())->setConversation($c)->setAuteur($auteur)->setGenre(Message::SYSTEME)->setContenu($texte);
        if (null !== $le) {
            $m->setDateEnvoi($le);
        }
        $c->getMessages()->add($m);
        $c->setDateDernierMessage($le ?? new \DateTimeImmutable());
        $this->em->persist($m);

        return $m;
    }
}
