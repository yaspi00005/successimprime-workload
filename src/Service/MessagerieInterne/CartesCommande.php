<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\SuiviCommandeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Commande citée dans un message de l'équipe (maquette MI01) : « CM-0182 »
 * ou « CMD-PM0182 » devient une carte avec le client, l'article, le statut
 * et l'échéance.
 *
 * La carte ne montre que ce que le lecteur a le droit de voir : le statut
 * pour tous ; le total et le reste à payer seulement avec le droit
 * « Paiements » (ROLE_PAIEMENT_VOIR) ; le lien vers la fiche seulement avec
 * le droit « Commandes ».
 */
class CartesCommande
{
    /** « CM-0182 », « CMD-PM0182 », « CMD-D00769 ». */
    public const MOTIF = '/(?<![A-Za-z0-9-])(CMD?-[A-Z]{0,3}\d{2,6})(?![A-Za-z0-9])/u';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuiviCommandeService $suivi,
        private readonly Security $security,
    ) {
    }

    /** @return list<string> références trouvées dans le texte, sans doublon */
    public static function references(string $texte): array
    {
        preg_match_all(self::MOTIF, strtoupper($texte), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Commandes citées : le numéro exact, sinon « CM-0182 » retrouve
     * « CMD-PM0182 » (même nombre à la fin, la plus récente).
     *
     * @return list<Commandes>
     */
    public function trouver(string $texte): array
    {
        $trouvees = [];
        foreach (\array_slice(self::references($texte), 0, 5) as $reference) {
            $commande = $this->em->getRepository(Commandes::class)->findOneBy(['numero' => $reference, 'deleted' => false]);
            if (null === $commande && preg_match('/(\d{2,6})$/', $reference, $n)) {
                $candidates = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
                    ->where('c.numero LIKE :fin')->andWhere('c.deleted = false')
                    ->setParameter('fin', 'C%'.$n[1])->orderBy('c.id', 'DESC')->setMaxResults(10)->getQuery()->getResult();
                foreach ($candidates as $c) {
                    if ($c instanceof Commandes && self::memeNombre((string) $c->getNumero(), $n[1])) {
                        $commande = $c;
                        break;
                    }
                }
            }
            if ($commande instanceof Commandes) {
                $trouvees[$commande->getId()] = $commande;
            }
        }

        return array_values($trouvees);
    }

    /** « CMD-PM0182 » finit bien par le nombre « 0182 » (pas « 10182 »). */
    public static function memeNombre(string $numero, string $nombre): bool
    {
        return 1 === preg_match('/(?<!\d)'.preg_quote($nombre, '/').'$/', $numero);
    }

    /**
     * Données de la carte pour le lecteur connecté.
     *
     * @return array<string, mixed>
     */
    public function carte(Commandes $commande): array
    {
        return self::carteDe(
            $commande,
            $this->statut($commande),
            $this->security->isGranted('ROLE_PAIEMENT_VOIR'),
            $this->security->isGranted('ROLE_COMMANDE'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function carteDe(Commandes $commande, array $statut, bool $montants, bool $lien): array
    {
        $lignes = array_values(array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
        $article = null;
        if ([] !== $lignes) {
            $premiere = $lignes[0];
            $article = trim((string) ($premiere->getDesignation() ?: $premiere->getProduit()?->getNom())).' × '.$premiere->getQuantite();
            if (\count($lignes) > 1) {
                $article .= \sprintf(' (+%d)', \count($lignes) - 1);
            }
        }
        $client = $commande->getClients();

        return [
            'id' => $commande->getId(),
            'numero' => $commande->getNumero(),
            'client' => null === $client ? null : ($client->getRaisonSociale() ?: $client->getNomComplet()),
            'article' => $article,
            'statut' => $statut['libelle'],
            'ton' => $statut['ton'],
            'echeance' => $commande->getDateLivraison(),
            // Montants : seulement avec le droit « Paiements ».
            'total' => $montants ? $commande->getTotalTtc() : null,
            'reste' => $montants ? $commande->getResteAPayer() : null,
            'lien' => $lien,
        ];
    }

    /**
     * Statut lisible : livrée, annulée, ou l'étape en cours de la fiche
     * commande (fichier / BAT, impression…).
     *
     * @return array{libelle: string, ton: string}
     */
    public function statut(Commandes $commande): array
    {
        $travaux = $commande->getStatutTravaux();
        if ($commande->isDeleted() || 'annulee' === $travaux) {
            return ['libelle' => 'Annulée', 'ton' => 'gris'];
        }
        if ('livree' === $travaux) {
            return ['libelle' => $commande->estALivrer() ? 'Livrée' : 'Retirée', 'ton' => 'vert'];
        }
        if (!$commande->isStatut()) {
            return ['libelle' => 'À confirmer', 'ton' => 'orange'];
        }
        foreach ($this->suivi->etapes($commande) as $etape) {
            if ('en_cours' === $etape['etat']) {
                return match ($etape['libelle']) {
                    'Fichier / BAT' => ['libelle' => 'Au prépresse', 'ton' => 'rose'],
                    'Acompte' => ['libelle' => 'Acompte attendu', 'ton' => 'orange'],
                    'Impression' => ['libelle' => 'En impression', 'ton' => 'bleu'],
                    'Finition' => ['libelle' => 'En finition', 'ton' => 'bleu'],
                    'Prête' => ['libelle' => 'En production', 'ton' => 'bleu'],
                    'Livrée', 'Retirée' => ['libelle' => 'Prête', 'ton' => 'vert'],
                    default => ['libelle' => $etape['libelle'], 'ton' => 'bleu'],
                };
            }
        }

        return ['libelle' => 'En cours', 'ton' => 'bleu'];
    }
}
