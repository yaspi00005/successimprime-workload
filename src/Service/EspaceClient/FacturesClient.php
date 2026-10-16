<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Factures;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use App\Entity\RegleRappelPaiement;
use App\Service\Paiement\OrangeMoney;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Factures et paiements » de l'espace client (maquettes FP01 à FP05),
 * toujours pour la fiche du client connecté et seulement pour qui paie
 * (EspaceFacturesController) : ce qui est à payer, les tuiles, les onglets
 * Factures, Paiements, Avoirs et Reçus, le détail d'une facture avec ses
 * rappels, la page « Paiement reçu » et le relevé de compte.
 *
 * Tout se calcule ici, à partir des données reçues du local : rien n'est
 * inventé (pas de reçu, de points ni de rappel qui n'existe pas).
 */
final class FacturesClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * À payer : les éléments, leur total, la part échue et ce qui viendra
     * plus tard (reste d'une commande à régler à la livraison ou sur facture).
     *
     * @return array{elements: list<ElementAPayer>, total: int, echu: int, plusTard: int}
     */
    public function aPayer(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $commandes = array_values(array_filter($this->espace->commandes($client, 300, true), self::payable(...)));
        $elements = self::elementsDe($commandes, $this->facturesParCommande($client), $this->acompteDefaut(), $client->getAcompte(), $maintenant);
        $total = array_sum(array_map(static fn (ElementAPayer $e): int => $e->montant, $elements));

        return [
            'elements' => $elements,
            'total' => $total,
            'echu' => array_sum(array_map(static fn (ElementAPayer $e): int => ElementAPayer::ECHUE === $e->etat ? $e->montant : 0, $elements)),
            'plusTard' => max(0, array_sum(array_map(OrangeMoney::aPayer(...), $commandes)) - $total),
        ];
    }

    /** Commande confirmée par la boutique, ni annulée ni en attente de l'entreprise du client. */
    public static function payable(Commandes $c): bool
    {
        return $c->isStatut() && !$c->isDeleted() && 'annulee' !== $c->getStatutTravaux()
            && !$c->attendValidationEntreprise() && !$c->estRefuseeParEntreprise();
    }

    /**
     * Ce qui est à payer maintenant pour chaque commande, la plus ancienne
     * échéance d'abord :
     *  - la facture émise : son reste, à son échéance ;
     *  - sans facture, tant que l'atelier prépare : l'acompte (pourcentage du
     *    client, sinon celui des réglages) ;
     *  - sans facture, une fois prête ou livrée : le reste ;
     *  - le solde d'ouverture : son reste.
     * Ce qui attend déjà la validation de la boutique n'est plus demandé.
     *
     * @param iterable<Commandes>  $commandes
     * @param array<int, Factures> $factures facture émise de chaque commande, par id de commande
     *
     * @return list<ElementAPayer>
     */
    public static function elementsDe(iterable $commandes, array $factures, int $acompteDefaut, ?int $acompteClient, \DateTimeImmutable $maintenant): array
    {
        $jour = $maintenant->setTime(0, 0);
        $elements = [];
        foreach ($commandes as $c) {
            if (!self::payable($c)) {
                continue;
            }
            $aPayer = OrangeMoney::aPayer($c);
            if ($aPayer <= 0) {
                continue;
            }
            $date = null !== $c->getDateCommande() ? \DateTimeImmutable::createFromInterface($c->getDateCommande())->setTime(0, 0) : $jour;
            $f = $factures[(int) $c->getId()] ?? null;
            if ($c->estSoldeOuverture()) {
                $elements[] = new ElementAPayer($c, null, ElementAPayer::SOLDE, $aPayer, $aPayer, $date, ElementAPayer::A_PAYER);
                continue;
            }
            if (null !== $f) {
                $echeance = $f->getDateEcheance()?->setTime(0, 0) ?? $f->getDateFacture()?->setTime(0, 0) ?? $date;
                $etat = match (true) {
                    $echeance < $jour => ElementAPayer::ECHUE,
                    $echeance == $jour => ElementAPayer::AUJOURDHUI,
                    null !== $f->getDateEcheance() => ElementAPayer::A_VENIR,
                    default => ElementAPayer::A_PAYER,
                };
                $elements[] = new ElementAPayer($c, $f, ElementAPayer::FACTURE, $aPayer, $aPayer, $echeance, $etat);
                continue;
            }
            $enPreparation = \in_array($c->getStatutTravaux(), ['vide', 'preparation'], true);
            $pourcentage = max(0, min(100, $acompteClient ?? $acompteDefaut));
            if ($enPreparation && $pourcentage > 0) {
                $acompte = (int) ceil($c->getTotalTtc() * $pourcentage / 100);
                $couvert = $c->getTotalTtc() - $aPayer;
                $montant = min($aPayer, $acompte - $couvert);
                if ($montant > 0) {
                    $elements[] = new ElementAPayer($c, null, ElementAPayer::ACOMPTE, $montant, $aPayer, $date, ElementAPayer::A_PAYER, $pourcentage, self::productionLancee($c));
                }
                continue;
            }
            if (!$enPreparation) {
                $pret = null !== $c->getDateLivraison() ? \DateTimeImmutable::createFromInterface($c->getDateLivraison())->setTime(0, 0) : $date;
                $elements[] = new ElementAPayer($c, null, ElementAPayer::COMMANDE, $aPayer, $aPayer, min($pret, $jour), ElementAPayer::A_PAYER);
            }
        }
        usort($elements, static fn (ElementAPayer $a, ElementAPayer $b): int => [$a->echeance, (int) $a->commande->getId()] <=> [$b->echeance, (int) $b->commande->getId()]);

        return $elements;
    }

    /** L'atelier a commencé une ligne : l'acompte ne « lance » plus la production. */
    public static function productionLancee(Commandes $c): bool
    {
        foreach ($c->getCommandesDetails() as $l) {
            if (null !== $l->getProductionDebuteLe() || !\in_array($l->getStatutProduction(), [CommandesDetails::PRODUCTION_A_PRODUIRE, CommandesDetails::PRODUCTION_NON_REQUISE, CommandesDetails::PRODUCTION_ANNULEE], true)) {
                return true;
            }
        }

        return false;
    }

    /** Acompte des réglages (Réglages › Paiements), 50 % sans réglage. */
    public function acompteDefaut(): int
    {
        $v = $this->parametres->valeur('paiements', 'acompte');

        return is_numeric($v) ? (int) $v : 50;
    }

    /**
     * Factures émises du client (proformas comprises), la plus récente d'abord.
     *
     * @return list<Factures>
     */
    public function factures(Clients $client): array
    {
        return $this->em->getRepository(Factures::class)->createQueryBuilder('f')
            ->join('f.commande', 'c')
            ->where('c.clients = :cl')->andWhere('f.etat = :e')
            ->setParameter('cl', $client)->setParameter('e', Factures::ETAT_EMISE)
            ->orderBy('f.dateFacture', 'DESC')->addOrderBy('f.id', 'DESC')->setMaxResults(500)->getQuery()->getResult();
    }

    /**
     * La facture à payer de chaque commande : la dernière facture émise qui
     * n'est pas une proforma.
     *
     * @return array<int, Factures>
     */
    public function facturesParCommande(Clients $client): array
    {
        $parCommande = [];
        foreach ($this->factures($client) as $f) {
            $id = (int) $f->getCommande()?->getId();
            if (!$f->estProforma() && (!isset($parCommande[$id]) || $f->getId() > $parCommande[$id]->getId())) {
                $parCommande[$id] = $f;
            }
        }

        return $parCommande;
    }

    /** La facture émise du client, ou null si elle est à un autre (ou pas émise). */
    public function facture(Clients $client, string $publicId): ?Factures
    {
        if (!self::uuidValable($publicId)) {
            return null;
        }
        foreach ($this->factures($client) as $f) {
            if ((string) $f->getPublicId() === $publicId) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Paiements des commandes du client : validés, en validation, refusés
     * (pas les annulés), le plus récent d'abord.
     *
     * @return list<Paiements>
     */
    public function paiements(Clients $client): array
    {
        return $this->em->getRepository(Paiements::class)->createQueryBuilder('p')
            ->join('p.commande', 'c')
            ->where('c.clients = :cl')->andWhere('p.statut IN (:s)')
            ->setParameter('cl', $client)->setParameter('s', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE, Paiements::STATUT_REJETE])
            ->orderBy('p.date', 'DESC')->addOrderBy('p.id', 'DESC')->setMaxResults(500)->getQuery()->getResult();
    }

    /** Le paiement du client, ou null s'il est à un autre. */
    public function paiement(Clients $client, string $publicId): ?Paiements
    {
        if (!self::uuidValable($publicId)) {
            return null;
        }
        foreach ($this->paiements($client) as $p) {
            if ((string) $p->getPublicId() === $publicId) {
                return $p;
            }
        }

        return null;
    }

    /** Le règlement en ligne du client, ou null s'il est à un autre. */
    public function reglement(Clients $client, string $reference): ?ReglementEnLigne
    {
        $r = $this->em->getRepository(ReglementEnLigne::class)->findOneBy(['reference' => mb_substr($reference, 0, 40)]);

        return null !== $r && ($r->getClient() === $client || (null !== $client->getId() && $r->getClient()->getId() === $client->getId())) ? $r : null;
    }

    /**
     * Payé dans l'année : les paiements validés par la boutique seulement.
     *
     * @param list<Paiements> $paiements
     *
     * @return array{montant: int, nombre: int}
     */
    public static function payeEn(array $paiements, int $annee): array
    {
        $valides = array_filter($paiements, static fn (Paiements $p): bool => $p->estValide() && (int) $p->getDate()?->format('Y') === $annee);

        return ['montant' => array_sum(array_map(static fn (Paiements $p): int => $p->getMontant(), $valides)), 'nombre' => \count($valides)];
    }

    /**
     * Prochaine échéance : la première facture à payer qui n'est pas encore échue.
     *
     * @param list<ElementAPayer> $elements
     */
    public static function prochaineEcheance(array $elements): ?ElementAPayer
    {
        foreach ($elements as $e) {
            if ($e->estFacture() && \in_array($e->etat, [ElementAPayer::AUJOURDHUI, ElementAPayer::A_VENIR], true)) {
                return $e;
            }
        }

        return null;
    }

    /** Ce qui attend la validation de la boutique sur la commande (Orange Money, avoir). */
    public static function enValidation(Commandes $c): int
    {
        return OrangeMoney::enValidation($c) + OrangeMoney::avoirEnValidation($c);
    }

    /**
     * Pastille d'une facture dans l'onglet Factures.
     *
     * @return array{libelle: string, couleur: string}
     */
    public static function etatFacture(Factures $f, \DateTimeImmutable $maintenant): array
    {
        $reste = $f->getResteAPayer();
        $enValidation = null !== $f->getCommande() ? self::enValidation($f->getCommande()) : 0;
        $echeance = $f->getDateEcheance();

        return match (true) {
            $f->estProforma() => ['libelle' => 'Proforma', 'couleur' => 'gris'],
            $reste <= 0 => ['libelle' => 'Payée', 'couleur' => 'vert'],
            $enValidation >= $reste => ['libelle' => 'En validation', 'couleur' => 'ambre'],
            null !== $echeance && $echeance->format('Y-m-d') < $maintenant->format('Y-m-d') => ['libelle' => 'Échue', 'couleur' => 'rouge'],
            null !== $echeance => ['libelle' => 'À payer · '.$echeance->format('d/m'), 'couleur' => 'bleu'],
            default => ['libelle' => 'À payer', 'couleur' => 'ambre'],
        };
    }

    /**
     * Pastille d'un paiement : « reçu, en validation » tant que la boutique
     * ne l'a pas validé.
     *
     * @return array{libelle: string, couleur: string}
     */
    public static function etatPaiement(Paiements $p): array
    {
        return match ($p->getStatut()) {
            Paiements::STATUT_VALIDE => ['libelle' => 'Validé', 'couleur' => 'vert'],
            Paiements::STATUT_REJETE => ['libelle' => 'Refusé par la boutique', 'couleur' => 'rouge'],
            Paiements::STATUT_ANNULE => ['libelle' => 'Annulé', 'couleur' => 'gris'],
            default => ['libelle' => 'Reçu · en validation', 'couleur' => 'ambre'],
        };
    }

    /** Le moyen de paiement, dit pour le client. */
    public static function moyen(Paiements $p): string
    {
        return match ($p->getMode()) {
            Paiements::MODE_ORANGE_MONEY => 'Orange Money',
            Paiements::MODE_ESPECES => 'Espèces · boutique',
            Paiements::MODE_SOLDE_CLIENT => 'Avoir',
            Paiements::MODE_POINTS_FIDELITE => 'Points fidélité',
            Paiements::MODE_VIREMENT => 'Virement',
            Paiements::MODE_CHEQUE => 'Chèque',
            Paiements::MODE_CARTE => 'Carte bancaire',
            Paiements::MODE_WAVE => 'Wave',
            default => $p->getModeLabel(),
        };
    }

    /**
     * Rappels d'une commande : ceux envoyés par la boutique (règles de
     * rappel, reçus du local) et ceux de l'échéance de sa facture dans
     * l'espace, puis le prochain si rien ne change.
     *
     * @return array{envoyes: list<array{date: \DateTimeImmutable, libelle: string}>, prochain: ?\DateTimeImmutable}
     */
    public function rappels(Commandes $c, ?Factures $f, \DateTimeImmutable $maintenant): array
    {
        $envoyes = [];
        foreach ($this->em->getRepository(RappelPaiementEnvoye::class)->findBy(['commande' => $c], ['dateEnvoi' => 'ASC']) as $r) {
            if (null !== $r->getDateEnvoi() && !$r->getRegle()?->isRetrait()) {
                $envoyes[] = ['date' => $r->getDateEnvoi(), 'libelle' => 'rappel de paiement'.self::canal($r->getCanal())];
            }
        }
        if (null !== $f) {
            foreach (EspaceClient::rappelsEcheance($f, $maintenant) as $e) {
                $envoyes[] = ['date' => \DateTimeImmutable::createFromInterface($e['date']), 'libelle' => 'Facture échue' === $e['titre'] ? 'rappel échéance dépassée' : 'rappel du jour de l\'échéance'];
            }
        }
        usort($envoyes, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $reste = OrangeMoney::aPayer($c);
        $delais = array_map(
            static fn (RegleRappelPaiement $r): int => $r->getDelaiJours(),
            array_filter($this->em->getRepository(RegleRappelPaiement::class)->findBy(['actif' => true]), static fn (RegleRappelPaiement $r): bool => !$r->isRetrait()),
        );

        return ['envoyes' => $envoyes, 'prochain' => $reste > 0 ? self::prochainRappel($c->getDateCommande(), array_values($delais), $f?->getDateEcheance(), $maintenant) : null];
    }

    /**
     * Prochain rappel, après aujourd'hui : une règle « tous les N jours »
     * depuis la commande (J+N, J+2N… comme RappelPaiementService), ou le
     * rappel de l'échéance de la facture (le jour même, puis une semaine
     * après).
     *
     * @param list<int> $delais
     */
    public static function prochainRappel(?\DateTimeInterface $dateCommande, array $delais, ?\DateTimeInterface $echeance, \DateTimeImmutable $maintenant): ?\DateTimeImmutable
    {
        $demain = $maintenant->setTime(0, 0)->modify('+1 day');
        $dates = [];
        if (null !== $dateCommande) {
            $depart = \DateTimeImmutable::createFromInterface($dateCommande)->setTime(0, 0);
            foreach ($delais as $n) {
                if ($n <= 0) {
                    continue;
                }
                $age = (int) $depart->diff($demain)->format('%r%a');
                $k = max(1, (int) ceil($age / $n));
                $dates[] = $depart->modify(\sprintf('+%d days', $k * $n));
            }
        }
        if (null !== $echeance) {
            $e = \DateTimeImmutable::createFromInterface($echeance)->setTime(0, 0);
            foreach ([$e, $e->modify(\sprintf('+%d days', EspaceClient::RELANCE_ECHEANCE_JOURS))] as $d) {
                if ($d >= $demain) {
                    $dates[] = $d;
                }
            }
        }

        return [] === $dates ? null : min($dates);
    }

    private static function canal(string $canal): string
    {
        return match ($canal) {
            'sms' => ' par SMS',
            'whatsapp' => ' par WhatsApp',
            'email' => ' par e-mail',
            default => '',
        };
    }

    /**
     * État d'une part d'un règlement, pour la page « Paiement reçu » :
     * « reçu, en validation » tant que la boutique n'a pas validé, puis
     * « soldée » quand la commande n'a plus rien à payer.
     *
     * @return array{libelle: string, couleur: string}
     */
    public static function etatPart(ReglementEnLignePart $part): array
    {
        $p = $part->getPaiementOm() ?? $part->getPaiementAvoir();
        if (null === $p) {
            return ReglementEnLigne::ABANDONNE === $part->getReglement()->getStatut()
                ? ['libelle' => 'non payé', 'couleur' => 'gris']
                : ['libelle' => 'en cours chez Orange Money', 'couleur' => 'gris'];
        }
        $paiements = array_filter([$part->getPaiementOm(), $part->getPaiementAvoir()]);
        if (array_filter($paiements, static fn (Paiements $x): bool => $x->estRejete()) !== []) {
            return ['libelle' => 'refusé par la boutique', 'couleur' => 'rouge'];
        }
        if (array_filter($paiements, static fn (Paiements $x): bool => !$x->estValide()) !== []) {
            return ['libelle' => 'reçu · en validation', 'couleur' => 'ambre'];
        }
        if ($part->getCommande()->getResteAPayer() <= 0) {
            return ['libelle' => 'soldée', 'couleur' => 'vert'];
        }

        return ['libelle' => ReglementEnLignePart::ACOMPTE === $part->getNature() ? 'acompte validé' : 'validé', 'couleur' => 'vert'];
    }

    /**
     * État de l'avoir utilisé par un règlement.
     *
     * @return array{libelle: string, couleur: string}
     */
    public static function etatAvoir(ReglementEnLigne $r): array
    {
        $paiements = array_values(array_filter(array_map(static fn (ReglementEnLignePart $p): ?Paiements => $p->getPaiementAvoir(), $r->getParts())));

        return match (true) {
            $r->estEnCours() => ['libelle' => 'réservé', 'couleur' => 'gris'],
            [] === $paiements => ['libelle' => 'non utilisé', 'couleur' => 'gris'],
            array_filter($paiements, static fn (Paiements $p): bool => $p->estRejete()) !== [] => ['libelle' => 'refusé par la boutique', 'couleur' => 'rouge'],
            array_filter($paiements, static fn (Paiements $p): bool => !$p->estValide()) !== [] => ['libelle' => 'en validation', 'couleur' => 'ambre'],
            default => ['libelle' => 'utilisé', 'couleur' => 'vert'],
        };
    }

    /**
     * « Et maintenant » (maquette FP04), d'après les données : le BAT ou la
     * production des commandes dont on a payé l'acompte, la prochaine
     * facture à payer et son rappel, et les points fidélité seulement s'ils
     * ont été donnés.
     *
     * @param list<ElementAPayer> $elements ce qui reste à payer
     *
     * @return list<array{titre: string, texte: string, lien: ?array{0: string, 1: array<string, mixed>}}>
     */
    public function etMaintenant(ReglementEnLigne $r, array $elements, bool $fidelite, \DateTimeImmutable $maintenant): array
    {
        $etapes = [];
        $commandes = [];
        foreach ($r->getParts() as $part) {
            $c = $part->getCommande();
            $commandes[(int) $c->getId()] = $c;
            if (ReglementEnLignePart::ACOMPTE !== $part->getNature()) {
                continue;
            }
            $carte = $this->espace->carte($c);
            $texte = match (true) {
                $carte['batEnAttente'] => 'Votre BAT attend votre accord dans « Fichiers et BAT ».',
                self::productionLancee($c) => 'Production lancée par l\'atelier.',
                default => 'L\'atelier lance la production quand la boutique a validé votre acompte.',
            };
            $etapes[] = ['titre' => \sprintf('%s · %s', EspaceClient::titre($c), $c->getNumero()), 'texte' => $texte, 'lien' => ['app_espace_commande', ['id' => (string) $c->getPublicId()]]];
        }

        $payes = array_map(static fn (ReglementEnLignePart $p): int => (int) $p->getCommande()->getId(), $r->getParts());
        foreach ($elements as $e) {
            if (\in_array((int) $e->commande->getId(), $payes, true)) {
                continue;
            }
            $rappel = $this->rappels($e->commande, $e->facture, $maintenant)['prochain'];
            $etapes[] = [
                'titre' => \sprintf('%s · %s', EspaceClient::titre($e->commande), $e->facture?->getNumero() ?? $e->commande->getNumero()),
                'texte' => \sprintf(
                    '%s F à payer %s%s',
                    number_format($e->montant, 0, ',', ' '),
                    $e->quand(),
                    null !== $rappel ? ' · prochain rappel le '.$rappel->format('d/m') : '',
                ),
                'lien' => null !== $e->facture ? ['app_espace_facture_detail', ['id' => (string) $e->facture->getPublicId()]] : null,
            ];
            break;
        }

        if ($fidelite) {
            $points = 0;
            foreach ($this->em->getRepository(MouvementFidelite::class)->findBy(['client' => $r->getClient(), 'type' => MouvementFidelite::GAIN, 'commande' => array_values($commandes)]) as $m) {
                $points += $m->getDate() >= $r->getCreeLe() ? $m->getPoints() : 0;
            }
            $etapes[] = $points > 0
                ? ['titre' => 'Points fidélité', 'texte' => \sprintf('+%d points ajoutés pour ces commandes.', $points), 'lien' => ['app_espace_fidelite', []]]
                : ['titre' => 'Points fidélité', 'texte' => 'Vos points sont ajoutés quand la boutique valide le paiement.', 'lien' => ['app_espace_fidelite', []]];
        }

        return $etapes;
    }

    /**
     * Relevé de compte du client entre deux dates : solde d'ouverture,
     * factures émises et soldes d'ouverture (débit), paiements validés
     * (crédit), solde après chaque ligne. Les paiements encore en
     * validation sont à part.
     *
     * @return array{ouverture: int, lignes: list<array{date: \DateTimeImmutable, libelle: string, debit: int, credit: int, solde: int, paiement?: Paiements}>, cloture: int, debits: int, credits: int, enValidation: int}
     */
    public function releve(Clients $client, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $mouvements = [];
        foreach ($this->factures($client) as $f) {
            if (!$f->estProforma() && null !== $f->getDateFacture()) {
                $mouvements[] = ['date' => $f->getDateFacture(), 'libelle' => \sprintf('Facture %s · commande %s', $f->getNumero(), $f->getCommande()?->getNumero()), 'debit' => $f->getTotalTtc(), 'credit' => 0];
            }
        }
        foreach ($this->espace->commandes($client, 300, true) as $c) {
            if ($c->estSoldeOuverture() && null !== $c->getDateCommande()) {
                $mouvements[] = ['date' => \DateTimeImmutable::createFromInterface($c->getDateCommande()), 'libelle' => 'Solde d\'ouverture du compte', 'debit' => $c->getTotalTtc(), 'credit' => 0];
            }
        }
        $enValidation = 0;
        foreach ($this->paiements($client) as $p) {
            if ($p->estEnAttente()) {
                $enValidation += $p->getDate() <= $au->setTime(23, 59, 59) ? $p->getMontant() : 0;
            } elseif ($p->estValide() && null !== $p->getDate()) {
                // Le numéro du reçu est ajouté par le gabarit (filtre numero_recu : celui de la caisse, en ligne comme au local).
                $mouvements[] = ['date' => $p->getDate(), 'libelle' => \sprintf('%s · commande %s', self::moyen($p), $p->getCommande()?->getNumero()), 'debit' => 0, 'credit' => $p->getMontant(), 'paiement' => $p];
            }
        }

        return self::calculerReleve($mouvements, $du, $au) + ['enValidation' => $enValidation];
    }

    /**
     * @param list<array{date: \DateTimeImmutable, libelle: string, debit: int, credit: int}> $mouvements
     *
     * @return array{ouverture: int, lignes: list<array{date: \DateTimeImmutable, libelle: string, debit: int, credit: int, solde: int}>, cloture: int, debits: int, credits: int}
     */
    public static function calculerReleve(array $mouvements, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $debut = $du->setTime(0, 0);
        $fin = $au->setTime(23, 59, 59);
        usort($mouvements, static fn (array $a, array $b): int => [$a['date'], $a['credit']] <=> [$b['date'], $b['credit']]);
        $ouverture = 0;
        $lignes = [];
        $solde = 0;
        $debits = 0;
        $credits = 0;
        foreach ($mouvements as $m) {
            if ($m['date'] < $debut) {
                $ouverture += $m['debit'] - $m['credit'];
                $solde = $ouverture;
                continue;
            }
            if ($m['date'] > $fin) {
                continue;
            }
            $solde += $m['debit'] - $m['credit'];
            $debits += $m['debit'];
            $credits += $m['credit'];
            $lignes[] = $m + ['solde' => $solde];
        }

        return ['ouverture' => $ouverture, 'lignes' => $lignes, 'cloture' => $ouverture + $debits - $credits, 'debits' => $debits, 'credits' => $credits];
    }

    private static function uuidValable(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }
}
