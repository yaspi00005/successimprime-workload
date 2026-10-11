<?php

namespace App\Service;

use App\Entity\BonLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Production en Kanban (maquette 23) : chaque ligne de commande en cours
 * est rangée dans l'étape où elle se trouve, du PAO jusqu'à la livraison
 * du jour. Aucun montant n'est montré.
 */
final class TableauProduction
{
    public const COLONNES = [
        'pao' => 'PAO',
        'bat' => 'BAT envoyé',
        'impression' => 'Impression',
        'finition' => 'Finition',
        'prete' => 'Prête',
        'livree' => 'Livrée (jour)',
    ];

    private const STATUTS_EXCLUS = [
        CommandesDetails::PRODUCTION_ANNULEE,
        CommandesDetails::PRODUCTION_NON_REQUISE,
        CommandesDetails::PRODUCTION_LIVREE,
    ];

    private const COULEURS = ['bleu', 'rose', 'orange', 'vert', 'violet', 'cyan'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{colonnes: array<string, array{libelle: string, cartes: list<array<string, mixed>>}>, machines: list<Machines>, retards: int}
     */
    public function donnees(\DateTimeImmutable $maintenant, ?int $machineId = null, string $recherche = ''): array
    {
        $debutJour = $maintenant->setTime(0, 0);
        $livreesDuJour = $this->em->createQueryBuilder()
            ->select('IDENTITY(ligne.commandeDetail)')
            ->from(BonLivraisonLigne::class, 'ligne')
            ->innerJoin('ligne.bonLivraison', 'bon')
            ->andWhere('bon.livreLe >= :debutJour')
            ->andWhere('bon.statut != :annule')
            ->setParameter('debutJour', $debutJour)
            ->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->getQuery()
            ->getSingleColumnResult();

        $qb = $this->em->createQueryBuilder()
            ->select('detail', 'commande', 'client', 'produit', 'machine', 'typeImpression', 'controle')
            ->from(CommandesDetails::class, 'detail')
            ->innerJoin('detail.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->leftJoin('detail.produit', 'produit')
            ->leftJoin('detail.machine', 'machine')
            ->leftJoin('detail.typeImpression', 'typeImpression')
            ->leftJoin('detail.controlesPrePresse', 'controle')
            ->andWhere('commande.statut = true')
            ->andWhere('detail.etat = true')
            ->orderBy('commande.dateLivraison', 'ASC')
            ->addOrderBy('detail.id', 'ASC');

        $actives = $qb->expr()->notIn('detail.statutProduction', ':exclus');
        $qb->setParameter('exclus', self::STATUTS_EXCLUS);
        if ([] !== $livreesDuJour) {
            $qb->andWhere($qb->expr()->orX($actives, 'detail.id IN (:livrees)'))
                ->setParameter('livrees', array_map('intval', $livreesDuJour));
        } else {
            $qb->andWhere($actives);
        }

        /** @var list<CommandesDetails> $lignes */
        $lignes = $qb->getQuery()->getResult();
        $ordres = $this->ordresActifs($lignes);
        $livrees = array_flip(array_map('intval', $livreesDuJour));

        $colonnes = [];
        foreach (self::COLONNES as $cle => $libelle) {
            $colonnes[$cle] = ['libelle' => $libelle, 'cartes' => []];
        }

        $machines = [];
        $retards = 0;
        $recherche = mb_strtolower(trim($recherche));
        foreach ($lignes as $ligne) {
            $controle = self::dernierControle($ligne);
            $colonne = self::colonne($ligne->getStatutProduction(), $controle?->getStatut(), isset($livrees[$ligne->getId()]));
            if (null === $colonne) {
                continue;
            }

            $ordre = $ordres[$ligne->getId()] ?? null;
            $machine = $ordre?->getMachine() ?? $ligne->getMachine();
            if (null !== $machine) {
                $machines[$machine->getId()] = $machine;
            }
            if (null !== $machineId && $machine?->getId() !== $machineId) {
                continue;
            }

            $commande = $ligne->getCommande();
            $client = $commande?->getClients()?->getNomComplet() ?? '';
            $titre = self::titre($ligne);
            if ('' !== $recherche && !str_contains(mb_strtolower($titre.' '.$client.' '.$commande?->getNumero()), $recherche)) {
                continue;
            }

            $retard = 'livree' === $colonne ? null : self::retard($commande?->getDateLivraison(), $maintenant);
            if (null !== $retard) {
                ++$retards;
            }

            $colonnes[$colonne]['cartes'][] = [
                'ligne' => $ligne,
                'titre' => $titre,
                'client' => $client,
                'numero' => $commande?->getNumero(),
                'machine' => self::libelleMachine($machine, $ligne),
                'couleur' => self::couleur($machine, $ligne),
                'retard' => $retard,
                'note' => self::note($ligne->getStatutProduction(), $controle?->getStatut(), $ordre),
                'ordre' => $ordre,
                'controle' => $controle,
                'urgent' => 'urgente' === ($ordre?->getPriorite() ?? $ligne->getPriorite()),
            ];
        }

        uasort($machines, static fn (Machines $a, Machines $b): int => strcasecmp((string) $a->getNom(), (string) $b->getNom()));

        return ['colonnes' => $colonnes, 'machines' => array_values($machines), 'retards' => $retards];
    }

    /**
     * Étape d'une ligne. Le prépresse décide tant que l'impression n'a pas
     * commencé ; ensuite c'est l'état de production de la ligne.
     */
    public static function colonne(string $statutProduction, ?string $statutControle, bool $livreeAujourdhui): ?string
    {
        return match ($statutProduction) {
            CommandesDetails::PRODUCTION_LIVREE => $livreeAujourdhui ? 'livree' : null,
            CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_EN_LIVRAISON => 'prete',
            CommandesDetails::PRODUCTION_TERMINEE => 'finition',
            CommandesDetails::PRODUCTION_EN_COURS => 'impression',
            CommandesDetails::PRODUCTION_A_PRODUIRE => match ($statutControle) {
                ControlePrePresse::STATUT_EN_ATTENTE_BAT => 'bat',
                ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => 'impression',
                default => 'pao',
            },
            default => null,
        };
    }

    /** Retard en texte (« 4 h », « 2 j ») quand la date de livraison est passée. */
    public static function retard(?\DateTimeInterface $dateLivraison, \DateTimeImmutable $maintenant): ?string
    {
        if (null === $dateLivraison) {
            return null;
        }
        $echeance = \DateTimeImmutable::createFromInterface($dateLivraison);
        // Une date sans heure vaut jusqu'à la fin de la journée.
        if ('00:00:00' === $echeance->format('H:i:s')) {
            $echeance = $echeance->setTime(23, 59, 59);
        }
        $secondes = $maintenant->getTimestamp() - $echeance->getTimestamp();
        if ($secondes <= 0) {
            return null;
        }
        $heures = intdiv($secondes, 3600);

        return $heures < 24 ? max(1, $heures).' h' : intdiv($heures, 24).' j';
    }

    private static function note(string $statutProduction, ?string $statutControle, ?OrdreProduction $ordre): ?string
    {
        if (CommandesDetails::PRODUCTION_EN_LIVRAISON === $statutProduction) {
            return 'En livraison';
        }
        if (OrdreProduction::STATUT_EN_PAUSE === $ordre?->getStatut()) {
            return 'En pause';
        }
        if (CommandesDetails::PRODUCTION_A_PRODUIRE === $statutProduction) {
            return match ($statutControle) {
                ControlePrePresse::STATUT_CORRECTION_REQUISE => 'Correction demandée',
                ControlePrePresse::STATUT_REJETE => 'Fichier rejeté',
                null => 'Fichier à recevoir',
                ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => 'En file',
                default => null,
            };
        }

        return null;
    }

    private static function titre(CommandesDetails $ligne): string
    {
        $nom = trim((string) ($ligne->getDesignation() ?: $ligne->getProduit()?->getNom())) ?: 'Travail';

        return $nom.' × '.number_format($ligne->getQuantite(), 0, ',', ' ');
    }

    private static function libelleMachine(?Machines $machine, CommandesDetails $ligne): ?string
    {
        return $machine?->getNom() ?? $ligne->getTypeImpression()?->getNom();
    }

    /** Même couleur pour les machines d'un même type (solvant, DTF…). */
    private static function couleur(?Machines $machine, CommandesDetails $ligne): string
    {
        $cle = $machine?->getTypeMachine() ?: $machine?->getNom() ?: $ligne->getTypeImpression()?->getNom() ?: '';

        return self::COULEURS[crc32(mb_strtolower($cle)) % \count(self::COULEURS)];
    }

    private static function dernierControle(CommandesDetails $ligne): ?ControlePrePresse
    {
        $dernier = null;
        foreach ($ligne->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier;
    }

    /**
     * Ordre de production en cours de chaque ligne (le plus récent).
     *
     * @param list<CommandesDetails> $lignes
     *
     * @return array<int, OrdreProduction>
     */
    private function ordresActifs(array $lignes): array
    {
        if ([] === $lignes) {
            return [];
        }
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()
            ->select('ordre', 'machine')
            ->from(OrdreProduction::class, 'ordre')
            ->leftJoin('ordre.machine', 'machine')
            ->andWhere('ordre.commandeDetail IN (:lignes)')
            ->andWhere('ordre.statut != :annule')
            ->setParameter('lignes', $lignes)
            ->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->orderBy('ordre.id', 'ASC')
            ->getQuery()
            ->getResult();

        $parLigne = [];
        foreach ($ordres as $ordre) {
            $parLigne[$ordre->getCommandeDetail()?->getId()] = $ordre;
        }

        return $parLigne;
    }
}
