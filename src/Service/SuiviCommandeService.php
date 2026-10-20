<?php

namespace App\Service;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Paiements;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiche d'une commande (maquette C02) : etapes de suivi, etat de chaque
 * ligne et historique, reconstruits a partir des donnees de la commande.
 */
final class SuiviCommandeService
{
    private const RANG_LIGNE = [
        'pao' => 0,
        'bat' => 1,
        'impression' => 2,
        'finition' => 3,
        'prete' => 4,
        'sans_production' => 4,
        'en_livraison' => 5,
        'livree' => 6,
    ];

    /** $em : pour savoir si le livreur est vraiment parti (étape « en route » du client) ; sans lui, comme avant. */
    public function __construct(private readonly ?EntityManagerInterface $em = null)
    {
    }

    /**
     * Les 7 étapes de la fiche admin (maquette DT02) : créée, acompte,
     * fichier / BAT, impression, finition, prête, livrée ; chacune
     * 'faite', 'en_cours' ou 'a_venir'. Avec $acompteAttendu (la production
     * attend l'acompte, SuiviCommercial), l'acompte devient l'étape en cours.
     *
     * @return list<array{libelle: string, detail: ?string, etat: string}>
     */
    public function etapes(Commandes $commande, bool $acompteAttendu = false): array
    {
        $lignes = $this->lignesActives($commande);
        $etapesLignes = array_map(fn (CommandesDetails $ligne): string => $this->etapeLigne($ligne), $lignes);
        // Une ligne sans production (conception, frais) ne retient pas la commande.
        $avecProduction = array_filter($etapesLignes, static fn (string $e): bool => 'sans_production' !== $e);
        $rangs = array_map(static fn (string $e): int => self::RANG_LIGNE[$e], [] !== $avecProduction ? $avecProduction : $etapesLignes);
        $rangMin = [] === $rangs ? 0 : min($rangs);

        $avecBat = array_values(array_filter($lignes, static fn (CommandesDetails $ligne): bool => $ligne->isPrePresseNecessaire()));
        $batValides = \count(array_filter($avecBat, fn (CommandesDetails $ligne): bool => $this->batValide($ligne)));
        $premierPaiement = $this->paiementsValides($commande)[0] ?? null;
        $retrait = !$commande->estALivrer();
        $prevue = $commande->getDateLivraison();

        // Une commande passée en ligne reste « à confirmer » tant que l'atelier
        // n'a pas vérifié le prix et le fichier (statut faux).
        $confirmee = $commande->isStatut();
        $faites = [
            $confirmee,
            null !== $premierPaiement && !$acompteAttendu,
            [] !== $lignes && $batValides === \count($avecBat) && $rangMin >= 2,
            [] !== $lignes && $rangMin >= 3,
            [] !== $lignes && $rangMin >= 4,
            [] !== $lignes && $rangMin >= 4,
            [] !== $lignes && $rangMin >= 6,
        ];

        $etapes = [
            ['libelle' => 'Créée', 'detail' => $confirmee ? $commande->getDateCommande()?->format('d/m') : 'à confirmer'],
            ['libelle' => 'Acompte', 'detail' => $acompteAttendu ? 'attendu' : $premierPaiement?->getDate()?->format('d/m')],
            ['libelle' => 'Fichier / BAT', 'detail' => [] === $avecBat ? 'sans BAT' : sprintf('%d / %d', $batValides, \count($avecBat))],
            ['libelle' => 'Impression', 'detail' => null],
            ['libelle' => 'Finition', 'detail' => null],
            ['libelle' => 'Prête', 'detail' => $rangMin < 4 && null !== $prevue ? 'pour le '.$prevue->format('d/m') : null],
            ['libelle' => $retrait ? 'Retirée' : 'Livrée', 'detail' => 5 === $rangMin ? 'en route' : null],
        ];

        return self::marquerEtapes($etapes, $faites, $acompteAttendu);
    }

    /**
     * Suivi vu par le client (maquettes 13 et 15) : reçue, BAT, impression,
     * finition, prête, livrée ou retirée, avec la date de chaque étape faite.
     * Pas de montants ni de noms d'employés.
     *
     * @return list<array{cle: string, libelle: string, court: string, date: ?\DateTimeInterface, detail: ?string, etat: string}>
     */
    public function etapesClient(Commandes $commande): array
    {
        $lignes = $this->lignesActives($commande);
        $rangs = array_map(fn (CommandesDetails $ligne): int => self::RANG_LIGNE[$this->etapeLigne($ligne)], $lignes);
        $rangMin = [] === $rangs ? 0 : min($rangs);
        $avecBat = array_values(array_filter($lignes, static fn (CommandesDetails $ligne): bool => $ligne->isPrePresseNecessaire()));
        $valides = array_filter($avecBat, fn (CommandesDetails $ligne): bool => $this->batValide($ligne));
        $dates = static fn (iterable $valeurs): array => array_values(array_filter(\is_array($valeurs) ? $valeurs : iterator_to_array($valeurs)));
        $batLe = $dates(array_map(fn (CommandesDetails $l): ?\DateTimeInterface => $this->dernierControle($l)?->getValideLe(), $valides));
        $debuts = $dates(array_map(static fn (CommandesDetails $l): ?\DateTimeInterface => $l->getProductionDebuteLe(), $lignes));
        $fins = $dates(array_map(static fn (CommandesDetails $l): ?\DateTimeInterface => $l->getProductionTermineeLe(), $lignes));
        // « Prête » : contrôle final fait et colis scannés (PD06) ; avant cette date, la fin d'impression.
        $pretes = $dates(array_map(static fn (CommandesDetails $l): ?\DateTimeInterface => $l->getPreteLivraisonLe() ?? $l->getProductionTermineeLe(), $lignes));
        $retrait = !$commande->estALivrer();
        $prevue = $commande->getDateLivraison();

        $etapes = [
            ['cle' => 'recue', 'libelle' => 'Commande reçue', 'court' => 'Reçue', 'date' => $commande->getDateCommande(), 'detail' => null, 'faite' => true],
            ['cle' => 'bat', 'libelle' => [] === $avecBat ? 'Sans BAT' : (\count($valides) === \count($avecBat) ? 'BAT validé par vous' : 'BAT'), 'court' => 'BAT', 'date' => [] !== $batLe ? max($batLe) : null,
                'detail' => [] !== $avecBat && \count($valides) < \count($avecBat) ? \sprintf('%d sur %d validé%s', \count($valides), \count($avecBat), \count($valides) > 1 ? 's' : '') : null,
                'faite' => [] !== $lignes && \count($valides) === \count($avecBat) && $rangMin >= 2],
            ['cle' => 'impression', 'libelle' => 'Impression', 'court' => 'Impression', 'date' => [] !== $debuts ? min($debuts) : null, 'detail' => null, 'faite' => [] !== $lignes && $rangMin >= 3],
            ['cle' => 'finition', 'libelle' => 'Finition', 'court' => 'Finition', 'date' => [] !== $fins ? max($fins) : null, 'detail' => null, 'faite' => [] !== $lignes && $rangMin >= 4],
            ['cle' => 'prete', 'libelle' => $retrait ? 'Prête à retirer' : 'Prête à livrer', 'court' => 'Prête', 'date' => [] !== $pretes && $rangMin >= 4 ? max($pretes) : null, 'detail' => null, 'faite' => [] !== $lignes && $rangMin >= 4],
            ['cle' => 'livree', 'libelle' => $retrait ? 'Retirée' : 'Livrée', 'court' => $retrait ? 'Retirée' : 'Livrée', 'date' => null, 'detail' => null, 'faite' => [] !== $lignes && $rangMin >= 6],
        ];
        if ($rangMin === 5) {
            $etapes[5]['detail'] = self::detailEnLivraison($this->dernierBon($commande), $prevue);
        }

        $courante = null;
        foreach ($etapes as $i => &$etape) {
            if (!$etape['faite'] && null === $courante) {
                $courante = $i;
                $etape['etat'] = 'en_cours';
                if (\in_array($etape['cle'], ['prete', 'livree'], true) && null !== $prevue && null === $etape['detail']) {
                    $etape['detail'] = 'prévue le '.$prevue->format('d/m');
                }
            } else {
                // Après l'étape en cours, rien n'est montré comme fait.
                $etape['etat'] = $etape['faite'] && null === $courante ? 'faite' : 'a_venir';
                if (null !== $courante) {
                    $etape['date'] = null;
                }
            }
            if ('a_venir' === $etape['etat'] && \in_array($etape['cle'], ['prete', 'livree'], true) && null !== $prevue && null === $etape['detail'] && 'prete' === $etape['cle']) {
                $etape['detail'] = 'prévue le '.$prevue->format('d/m');
            }
            unset($etape['faite']);
        }
        unset($etape);

        return $etapes;
    }

    /**
     * L'etape « en cours » est la premiere non faite ; celles d'avant sont
     * faites meme si une etape facultative (l'acompte) manque, sauf si
     * l'acompte bloque la production.
     *
     * @param list<array{libelle: string, detail: ?string}> $etapes
     * @param list<bool>                                     $faites
     *
     * @return list<array{libelle: string, detail: ?string, etat: string}>
     */
    public static function marquerEtapes(array $etapes, array $faites, bool $acompteBloquant = false): array
    {
        $courante = null;

        foreach ($faites as $rang => $faite) {
            if (!$faite && (1 !== $rang || $acompteBloquant)) {
                $courante = $rang;

                break;
            }
        }

        foreach ($etapes as $rang => &$etape) {
            $etape['etat'] = match (true) {
                $faites[$rang] => 'faite',
                $rang === $courante => 'en_cours',
                default => 'a_venir',
            };
        }

        return $etapes;
    }

    /**
     * Etape d'une ligne pour la fiche et ses etiquettes.
     *
     * @return array{etape: string, libelle: string, bat: ?string}
     */
    public function ligne(CommandesDetails $ligne): array
    {
        $etape = $this->etapeLigne($ligne);
        $controle = $this->dernierControle($ligne);

        $bat = null;

        if ($ligne->isPrePresseNecessaire()) {
            $bat = match (true) {
                $this->batValide($ligne) => 'BAT validé'.($controle?->getValideLe() ? ' '.$controle->getValideLe()->format('d/m H:i') : ''),
                ControlePrePresse::STATUT_EN_ATTENTE_BAT === $controle?->getStatut() => 'BAT envoyé',
                ControlePrePresse::STATUT_CORRECTION_REQUISE === $controle?->getStatut() => 'Correction demandée',
                default => 'BAT à envoyer',
            };
        }

        return [
            'etape' => $etape,
            'libelle' => match ($etape) {
                'pao' => 'PAO',
                'bat' => 'BAT envoyé',
                'impression' => CommandesDetails::PRODUCTION_EN_COURS === $ligne->getStatutProduction() ? 'Impression' : 'À imprimer',
                'finition' => 'Finition',
                'prete', 'sans_production' => 'Prête',
                'en_livraison' => 'En livraison',
                'livree' => 'Livrée',
                default => 'Annulée',
            },
            'bat' => $bat,
        ];
    }

    /**
     * Evenements de la commande, du plus recent au plus ancien. Les
     * paiements n'y figurent que pour qui a le droit de les voir.
     *
     * @return list<array{date: \DateTimeInterface, texte: string}>
     */
    public function historique(Commandes $commande, bool $avecPaiements = true): array
    {
        $evenements = [];

        if ($commande->getDateCommande()) {
            $evenements[] = ['date' => $commande->getDateCommande(), 'texte' => 'Commande créée'.self::par($commande->getAgents())];
        }

        foreach ($avecPaiements ? $commande->getPaiements() : [] as $paiement) {
            if (!$paiement->getDate() || $paiement->estAnnule()) {
                continue;
            }

            $evenements[] = [
                'date' => $paiement->getDate(),
                'texte' => sprintf(
                    '%s F reçus en %s%s',
                    number_format($paiement->getMontant(), 0, ',', ' '),
                    Paiements::MODES_LABELS[$paiement->getMode()] ?? $paiement->getMode(),
                    $paiement->estValide() ? ', validés' : ($paiement->estRejete() ? ', rejetés' : ', en attente de validation')
                ),
            ];
        }

        foreach ($commande->getCommandesDetails() as $ligne) {
            $nom = $ligne->getDesignation() ?: 'Travail';

            foreach ($ligne->getControlesPrePresse() as $controle) {
                if ($controle->getTraiteLe() && ControlePrePresse::STATUT_EN_ATTENTE_BAT === $controle->getStatut()) {
                    $evenements[] = ['date' => $controle->getTraiteLe(), 'texte' => sprintf('BAT envoyé au client : %s', $nom)];
                }

                if ($controle->getValideLe()) {
                    $evenements[] = ['date' => $controle->getValideLe(), 'texte' => sprintf('BAT validé : %s', $nom)];
                }
            }

            if ($ligne->getProductionDebuteLe()) {
                $evenements[] = ['date' => $ligne->getProductionDebuteLe(), 'texte' => sprintf('Impression lancée : %s%s', $nom, self::par($ligne->getProductionDebuteePar()))];
            }

            if ($ligne->getProductionTermineeLe()) {
                $evenements[] = ['date' => $ligne->getProductionTermineeLe(), 'texte' => sprintf('Production terminée : %s', $nom)];
            }
        }

        usort($evenements, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $evenements;
    }

    /**
     * @return list<Paiements>
     */
    public function paiementsValides(Commandes $commande): array
    {
        $paiements = array_values(array_filter(
            $commande->getPaiements()->toArray(),
            static fn (Paiements $paiement): bool => $paiement->estValide()
        ));

        usort($paiements, static fn (Paiements $a, Paiements $b): int => $a->getDate() <=> $b->getDate());

        return $paiements;
    }

    /**
     * @return list<CommandesDetails>
     */
    private function lignesActives(Commandes $commande): array
    {
        return array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $ligne): bool => CommandesDetails::PRODUCTION_ANNULEE !== $ligne->getStatutProduction()
        ));
    }

    private function etapeLigne(CommandesDetails $ligne): string
    {
        $statut = $ligne->getStatutProduction();

        return match (true) {
            CommandesDetails::PRODUCTION_ANNULEE === $statut => 'annulee',
            CommandesDetails::PRODUCTION_LIVREE === $statut => 'livree',
            CommandesDetails::PRODUCTION_EN_LIVRAISON === $statut => 'en_livraison',
            CommandesDetails::PRODUCTION_NON_REQUISE === $statut, !$ligne->isProductionNecessaire() => 'sans_production',
            default => TableauDeBordService::colonnePourLigne(
                $statut,
                $ligne->isPrePresseNecessaire(),
                $ligne->isBatValide(),
                $this->dernierControle($ligne)?->getStatut(),
            ),
        };
    }

    /**
     * Lignes parties en livraison : un bon validé la veille pour le
     * lendemain ne doit pas dire « en route » au client avant que le livreur
     * parte (enRouteLe) ; il dit « prévue le 21/10 ». Sans bon connu, comme avant.
     */
    public static function detailEnLivraison(?BonLivraison $bon, ?\DateTimeInterface $prevue): string
    {
        if (null === $bon || null !== $bon->getEnRouteLe() || null !== $bon->getArriveLe()) {
            return 'en route';
        }
        $jour = $bon->getPrevuLe() ?? $prevue;

        return null !== $jour ? 'prévue le '.$jour->format('d/m') : 'en préparation';
    }

    private function dernierBon(Commandes $commande): ?BonLivraison
    {
        if (null === $this->em || null === $commande->getId()) {
            return null;
        }
        foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $commande], ['id' => 'DESC'], 5) as $bon) {
            if (BonLivraison::STATUT_ANNULE !== $bon->getStatut()) {
                return $bon;
            }
        }

        return null;
    }

    private function batValide(CommandesDetails $ligne): bool
    {
        return $ligne->isBatValide() || \in_array(
            $this->dernierControle($ligne)?->getStatut(),
            [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION],
            true
        );
    }

    private function dernierControle(CommandesDetails $ligne): ?ControlePrePresse
    {
        $dernier = null;

        foreach ($ligne->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier;
    }

    private static function par(?User $utilisateur): string
    {
        if (null === $utilisateur) {
            return '';
        }

        return ' par '.($utilisateur->getEmploye()?->getPrenom() ?: $utilisateur->getUsername());
    }
}
