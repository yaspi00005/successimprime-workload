<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\MessageClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Messages entre un client et l'atelier : ce qui reste commun en dehors des
 * conversations (docs/messagerie-clients.md, App\Service\Messagerie) — le
 * contrôle du texte et le fil brut d'un client (export « Mes données »).
 */
final class MessagesClient
{
    /** Messages montrés dans le fil. */
    public const FIL_MAX = 100;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Le fil du client, le plus ancien d'abord (sans les événements « X a
     * ajouté Y ») ; avec une commande, les seuls messages de cette commande.
     *
     * @return list<MessageClient>
     */
    public function fil(Clients $client, ?Commandes $commande = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('m')->from(MessageClient::class, 'm')
            ->where('m.client = :c')->andWhere('m.sens != :e')
            ->setParameter('c', $client)->setParameter('e', MessageClient::EVENEMENT)
            ->orderBy('m.creeLe', 'DESC')->addOrderBy('m.id', 'DESC')->setMaxResults(self::FIL_MAX);
        if (null !== $commande) {
            $qb->andWhere('m.commande = :co')->setParameter('co', $commande);
        }

        return array_reverse($qb->getQuery()->getResult());
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
