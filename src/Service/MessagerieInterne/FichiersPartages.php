<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\PieceJointeMessage;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Security\MessagerieInterneVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Fichiers partagés dans l'équipe (maquette MI04) et recherche de la
 * messagerie : seulement dans les fils que la personne peut lire (ses
 * groupes et ses messages privés ; la Direction, aussi les groupes si le
 * réglage le permet ; jamais les messages privés des autres).
 */
class FichiersPartages
{
    public const ONGLETS = ['tous' => 'Tous', 'documents' => 'Documents', 'photos' => 'Photos', 'production' => 'Fichiers de production', 'epingles' => 'Épinglés'];

    private const EXTENSIONS_PRODUCTION = ['psd', 'ai', 'eps', 'tif', 'tiff'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly ReglesMessagerieInterne $regles,
    ) {
    }

    /** @return list<Conversation> fils lisibles par la personne */
    public function lisibles(User $moi): array
    {
        if (!MessagerieInterneVoter::aAcces($moi)) {
            return [];
        }
        $fils = $this->conversations->findPourUtilisateur($moi);
        if (\in_array(User::ROLE_ADMIN, $moi->getRoles(), true) && $this->regles->directionLitGroupes()) {
            $fils = [...$fils, ...$this->conversations->findGroupesSansMembre($moi)];
        }

        return $fils;
    }

    /**
     * @param array{q?: string, groupe?: int, personne?: int, commande?: int, onglet?: string} $filtres
     *
     * @return array{fichiers: list<PieceJointeMessage>, comptes: array<string, int>}
     */
    public function liste(User $moi, array $filtres, int $max = 300): array
    {
        $fils = $this->lisibles($moi);
        $comptes = array_fill_keys(array_keys(self::ONGLETS), 0);
        if ([] === $fils) {
            return ['fichiers' => [], 'comptes' => $comptes];
        }
        $base = function () use ($fils, $filtres): QueryBuilder {
            $qb = $this->em->createQueryBuilder()->from(PieceJointeMessage::class, 'p')
                ->join('p.message', 'm')->join('p.conversation', 'c')->join('m.auteur', 'a')->leftJoin('a.employe', 'e')->leftJoin('p.commande', 'cmd')
                ->where('p.conversation IN (:fils)')->setParameter('fils', $fils);
            $q = trim((string) ($filtres['q'] ?? ''));
            if ('' !== $q) {
                $qb->andWhere('p.nom LIKE :q OR c.nom LIKE :q OR e.prenom LIKE :q OR e.nom LIKE :q OR a.username LIKE :q OR cmd.numero LIKE :q')
                    ->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
            }
            if (($filtres['groupe'] ?? 0) > 0) {
                $qb->andWhere('c.id = :groupe')->setParameter('groupe', (int) $filtres['groupe']);
            }
            if (($filtres['personne'] ?? 0) > 0) {
                $qb->andWhere('a.id = :personne')->setParameter('personne', (int) $filtres['personne']);
            }
            if (($filtres['commande'] ?? 0) > 0) {
                $qb->andWhere('cmd.id = :commande')->setParameter('commande', (int) $filtres['commande']);
            }

            return $qb;
        };
        foreach (array_keys(self::ONGLETS) as $onglet) {
            $qb = $base()->select('COUNT(p.id)');
            self::filtrerOnglet($qb, $onglet);
            $comptes[$onglet] = (int) $qb->getQuery()->getSingleScalarResult();
        }
        $onglet = isset(self::ONGLETS[$filtres['onglet'] ?? '']) ? $filtres['onglet'] : 'tous';
        $qb = $base()->select('p', 'm', 'c', 'a')->orderBy('p.id', 'DESC')->setMaxResults($max);
        self::filtrerOnglet($qb, $onglet);

        return ['fichiers' => $qb->getQuery()->getResult(), 'comptes' => $comptes];
    }

    private static function filtrerOnglet(QueryBuilder $qb, string $onglet): void
    {
        match ($onglet) {
            'photos' => $qb->andWhere('p.genre = :photo')->setParameter('photo', PieceJointeMessage::PHOTO),
            'documents' => $qb->andWhere('p.genre = :fichier AND p.extension NOT IN (:prod)')->setParameter('fichier', PieceJointeMessage::FICHIER)->setParameter('prod', self::EXTENSIONS_PRODUCTION),
            'production' => $qb->andWhere('p.genre = :lien OR p.extension IN (:prod)')->setParameter('lien', PieceJointeMessage::LIEN)->setParameter('prod', self::EXTENSIONS_PRODUCTION),
            'epingles' => $qb->andWhere('m.epingleLe IS NOT NULL'),
            default => null,
        };
    }

    /**
     * Recherche dans les messages lisibles (texte), les plus récents d'abord.
     *
     * @return list<Message>
     */
    public function rechercherMessages(User $moi, string $q, int $max = 40): array
    {
        $q = trim($q);
        $fils = $this->lisibles($moi);
        if (mb_strlen($q) < 2 || [] === $fils) {
            return [];
        }

        return $this->em->createQueryBuilder()->select('m', 'a', 'c')->from(Message::class, 'm')->join('m.auteur', 'a')->join('m.conversation', 'c')
            ->where('m.conversation IN (:fils)')->andWhere('m.genre = :texte')->andWhere('m.contenu LIKE :q')
            ->setParameter('fils', $fils)->setParameter('texte', Message::TEXTE)->setParameter('q', '%'.addcslashes($q, '%_\\').'%')
            ->orderBy('m.id', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }
}
