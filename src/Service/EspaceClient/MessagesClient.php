<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\MessageClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Messages entre un client et l'atelier (Messages, maquette EC01), sur le
 * modèle des réclamations : le client écrit dans son espace en ligne, le
 * message monte au local par la synchronisation ; l'équipe (droit Clients)
 * répond depuis Clients › Messages, la réponse redescend et le client en
 * est prévenu (NotificationsClient, sans montant). Texte seulement. Un
 * message peut porter une commande : la page de la commande montre ce fil.
 */
final class MessagesClient
{
    /** Messages montrés dans le fil. */
    public const FIL_MAX = 100;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Le fil du client, le plus ancien d'abord ; avec une commande, les
     * seuls messages de cette commande (« Messages de la commande »).
     *
     * @return list<MessageClient>
     */
    public function fil(Clients $client, ?Commandes $commande = null): array
    {
        $critere = ['client' => $client] + (null !== $commande ? ['commande' => $commande] : []);

        return array_reverse($this->em->getRepository(MessageClient::class)->findBy($critere, ['creeLe' => 'DESC', 'id' => 'DESC'], self::FIL_MAX));
    }

    /**
     * Le client écrit (espace en ligne).
     *
     * @throws \DomainException message à montrer
     */
    public function ecrire(CompteClient $compte, string $texte, ?Commandes $commande, \DateTimeImmutable $maintenant): MessageClient
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans fiche client.');
        $message = MessageClient::nouveau($client, MessageClient::CLIENT, self::verifier($texte), $compte->getNomAffiche(), $commande, $maintenant);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * L'atelier répond (ERP local) : les messages du client sont traités.
     *
     * @throws \DomainException
     */
    public function repondre(Clients $client, string $texte, User $par, ?Commandes $commande, \DateTimeImmutable $maintenant): MessageClient
    {
        // Le client voit le prénom et le métier de qui répond (« Seydou (graphiste PAO) »), jamais plus.
        $auteur = $par->getEmploye()?->getNomPublic() ?? 'Succès Imprim';
        $message = MessageClient::nouveau($client, MessageClient::ATELIER, self::verifier($texte), $auteur, $commande, $maintenant);
        $message->setEcritPar($par);
        $this->em->persist($message);
        $this->marquerTraites($client, $maintenant);
        $this->em->flush();

        return $message;
    }

    /** « Classer » sans répondre : les messages du client ne comptent plus dans la pastille. */
    public function marquerTraites(Clients $client, \DateTimeImmutable $maintenant): void
    {
        foreach ($this->em->getRepository(MessageClient::class)->findBy(['client' => $client, 'sens' => MessageClient::CLIENT, 'traiteLe' => null]) as $m) {
            $m->traiter($maintenant);
        }
        $this->em->flush();
    }

    /** Messages de clients qui attendent l'équipe (pastille du menu Clients › Messages). */
    public function enAttente(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.MessageClient::class.' m WHERE m.sens = :s AND m.traiteLe IS NULL')
            ->setParameter('s', MessageClient::CLIENT)->getSingleScalarResult();
    }

    /**
     * Conversations pour l'équipe : un client par ligne, celles qui
     * attendent une réponse d'abord, puis la plus récente.
     *
     * @return list<array{client: Clients, dernier: MessageClient, attente: int}>
     */
    public function conversations(int $nombre = 100): array
    {
        /** @var list<MessageClient> $messages */
        $messages = $this->em->createQuery('SELECT m, c FROM '.MessageClient::class.' m JOIN m.client c ORDER BY m.creeLe DESC, m.id DESC')
            ->setMaxResults(1000)->getResult();
        $parClient = [];
        foreach ($messages as $m) {
            $id = $m->getClient()->getId();
            $parClient[$id] ??= ['client' => $m->getClient(), 'dernier' => $m, 'attente' => 0];
            if (!$m->estDeLAtelier() && null === $m->getTraiteLe()) {
                ++$parClient[$id]['attente'];
            }
        }
        $liste = array_values($parClient);
        usort($liste, static fn (array $a, array $b): int => [$b['attente'] > 0, $b['dernier']->getCreeLe()] <=> [$a['attente'] > 0, $a['dernier']->getCreeLe()]);

        return \array_slice($liste, 0, $nombre);
    }

    /**
     * Texte d'un message : pas vide, 1 000 caractères au plus, espaces
     * superflus retirés.
     *
     * @throws \DomainException
     */
    public static function verifier(string $texte): string
    {
        $texte = trim((string) preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $texte)));
        $texte = (string) preg_replace("/\n{3,}/u", "\n\n", $texte);
        if ('' === $texte) {
            throw new \DomainException('Écrivez votre message.');
        }
        if (mb_strlen($texte) > MessageClient::TEXTE_MAX) {
            throw new \DomainException(\sprintf('Votre message dépasse %d caractères. Raccourcissez-le.', MessageClient::TEXTE_MAX));
        }

        return $texte;
    }
}
