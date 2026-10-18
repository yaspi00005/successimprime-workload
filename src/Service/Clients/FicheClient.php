<?php

namespace App\Service\Clients;

use App\Entity\ActionCaisse;
use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ContactClient;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\ModeleMessage;
use App\Entity\ModificationClient;
use App\Entity\NoteClient;
use App\Entity\Paiements;
use App\Entity\ProduitClient;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RegleRappelPaiement;
use App\Entity\RelanceCreance;
use App\Entity\ReponseSondage;
use App\Entity\User;
use App\Service\Commandes\SuiviCommercial;
use App\Service\Creances;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\DelaisReponse;
use App\Service\Pointage\Pointages;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiche client (maquettes FC01 à FC04 du 18/10/2026, docs/clients.md) :
 * tout ce que montrent les onglets Aperçu, Commandes, Devis, Factures et
 * paiements, Produits, Fichiers, Messages et Historique, lu dans les
 * données existantes (créances, suivi des commandes, produits du client,
 * conversations, sondages, journal de la fiche).
 *
 * Les paiements, le solde et ce qui en découle ne sont rendus qu'avec
 * $voirPaiements ; le gabarit masque en plus le chiffre d'affaires et le
 * panier moyen sans le droit des statistiques de ventes.
 */
final class FicheClient
{
    /** Filtres de l'historique (FC04). « Paiements » demande le droit Paiements. */
    public const CATEGORIES = [
        'commandes' => 'Commandes',
        'paiements' => 'Paiements',
        'messages' => 'Messages',
        'echanges' => 'Appels et notes',
        'fichiers' => 'Fichiers et BAT',
        'modifications' => 'Modifications',
    ];

    public const STATUTS_DEVIS = [
        Devis::STATUT_BROUILLON => 'Brouillon',
        Devis::STATUT_ENVOYE => 'Envoyé',
        Devis::STATUT_VU => 'Vu',
        Devis::STATUT_ACCEPTE => 'Accepté',
        Devis::STATUT_REFUSE => 'Refusé',
        Devis::STATUT_EXPIRE => 'Expiré',
        Devis::STATUT_CONVERTI => 'Converti en commande',
        Devis::STATUT_ANNULE => 'Annulé',
    ];

    /** Couleur de la pastille de chaque statut de devis. */
    public const COULEURS_DEVIS = [
        Devis::STATUT_BROUILLON => 'gris',
        Devis::STATUT_ENVOYE => 'bleu',
        Devis::STATUT_VU => 'violet',
        Devis::STATUT_ACCEPTE => 'vert',
        Devis::STATUT_REFUSE => 'rouge',
        Devis::STATUT_EXPIRE => 'gris',
        Devis::STATUT_CONVERTI => 'vert',
        Devis::STATUT_ANNULE => 'gris',
    ];

    /** Devis encore « en attente » d'une réponse du client. */
    public const DEVIS_EN_ATTENTE = [Devis::STATUT_BROUILLON, Devis::STATUT_ENVOYE, Devis::STATUT_VU];

    public const STATUTS_TRAVAUX = [
        'preparation' => 'en préparation',
        'livraison' => 'prête ou en livraison',
        'livree' => 'livrée',
        'annulee' => 'annulée',
        'vide' => 'sans ligne',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
        private readonly SuiviCommercial $suivi,
        private readonly Conversations $conversations,
        private readonly DossierClient $dossiers,
        private readonly EditionClient $edition,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(Clients $client, \DateTimeImmutable $maintenant, bool $voirPaiements): array
    {
        /** @var list<Commandes> $toutes */
        $toutes = $this->em->createQueryBuilder()
            ->select('commande', 'paiement')
            ->from(Commandes::class, 'commande')
            ->leftJoin('commande.paiements', 'paiement')
            ->andWhere('commande.clients = :client')
            ->andWhere('commande.deleted = false')
            ->setParameter('client', $client)
            ->orderBy('commande.dateCommande', 'DESC')
            ->addOrderBy('commande.id', 'DESC')
            ->getQuery()
            ->getResult();
        // Le solde d'ouverture (docs/import-donnees.md) compte dans le solde dû, pas dans le chiffre d'affaires.
        $soldesOuverture = array_values(array_filter($toutes, static fn (Commandes $c): bool => $c->estSoldeOuverture()));
        $commandes = array_values(array_filter($toutes, static fn (Commandes $c): bool => !$c->estSoldeOuverture()));
        $actives = array_values(array_filter($commandes, static fn (Commandes $c): bool => 'annulee' !== $c->getStatutTravaux()));
        /** @var list<Devis> $devis */
        $devis = $this->em->getRepository(Devis::class)->findBy(['clients' => $client, 'deleted' => false], ['dateDevis' => 'DESC', 'id' => 'DESC']);

        $suivi = [];
        foreach ($this->suivi->lignes($commandes, $maintenant) as $ligne) {
            $suivi[$ligne['commande']->getId()] = $ligne;
        }

        $creances = $this->creancesDuClient($client, $maintenant);
        $kpi = $this->kpi($actives, $devis, $creances, $maintenant);
        $echeancier = $voirPaiements ? $this->echeancier([...$soldesOuverture, ...$actives], $creances, $maintenant) : [];
        $conversations = $this->conversations->duClient($client);
        $derniers = $this->conversations->derniers($conversations);
        $produits = $this->produits($client);
        $historique = $this->historique($client, $commandes, $devis, $voirPaiements, $soldesOuverture, $conversations);

        return [
            'kpi' => $kpi,
            'ca_mois' => CalculsFicheClient::caParMois(array_map(static fn (Commandes $c): array => ['date' => $c->getDateCommande() ?? $maintenant, 'montant' => (int) $c->getTotalTtc()], $actives), (int) $maintenant->format('Y')),
            'en_cours' => $this->enCours($actives, $devis, $suivi),
            'plafond' => CalculsFicheClient::plafondUtilise($kpi['solde_du'], $client->getPlafondCredit()),
            'contacts' => $this->edition->contacts($client),
            'contact_factures' => $this->contactFactures($client),
            'notes' => $this->em->getRepository(NoteClient::class)->findBy(['client' => $client, 'type' => 'note'], ['le' => 'DESC'], 6),
            'commandes' => $commandes,
            'suivi' => $suivi,
            'soldes_ouverture' => $voirPaiements ? $soldesOuverture : [],
            'devis' => $devis,
            'factures' => $this->factures($commandes),
            'echeancier' => $echeancier,
            'finances' => $voirPaiements ? $this->finances($client, [...$soldesOuverture, ...$actives], $echeancier, $kpi, $maintenant) : null,
            'produits' => $produits,
            'dossier' => $this->dossier($client, $produits['liste'], $historique),
            'fichiers' => $this->fichiers($commandes),
            'conversations' => array_map(static fn (ConversationClient $c): array => [
                'conversation' => $c,
                'dernier' => isset($derniers[$c->getId()]) ? \App\Service\Messagerie\ResumeConversation::message($derniers[$c->getId()], !$voirPaiements) : null,
            ], $conversations),
            'historique' => $historique,
            'activite' => CalculsFicheClient::activiteParSemaine(array_map(static fn (array $e): \DateTimeInterface => $e['date'], array_filter($historique, static fn (array $e): bool => \in_array($e['categorie'], ['commandes', 'paiements', 'messages'], true))), $maintenant),
            'satisfaction' => $this->satisfaction($client),
            'reponses' => $this->reponses($conversations, $maintenant),
        ];
    }

    /**
     * Lignes des créances ouvertes du client (Creances : même calcul que
     * l'écran Finances › Créances).
     *
     * @return list<array<string, mixed>>
     */
    private function creancesDuClient(Clients $client, \DateTimeImmutable $maintenant): array
    {
        return array_values(array_filter($this->creances->donnees($maintenant)['lignes'], static fn (array $l): bool => $l['commande']->getClients() === $client));
    }

    /**
     * Chiffres clés (FC01).
     *
     * @param list<Commandes>            $actives
     * @param list<Devis>                $devis
     * @param list<array<string, mixed>> $creances
     *
     * @return array<string, mixed>
     */
    private function kpi(array $actives, array $devis, array $creances, \DateTimeImmutable $maintenant): array
    {
        $annee = (int) $maintenant->format('Y');
        $cetteAnnee = [];
        $anneePassee = [];
        foreach ($actives as $c) {
            $a = (int) ($c->getDateCommande()?->format('Y') ?? 0);
            if ($a === $annee) {
                $cetteAnnee[] = (int) $c->getTotalTtc();
            } elseif ($a === $annee - 1) {
                $anneePassee[] = (int) $c->getTotalTtc();
            }
        }
        $soldeDu = 0;
        $echu = 0;
        $echues = 0;
        $plusAncienne = null;
        foreach ($creances as $l) {
            $soldeDu += $l['reste'];
            if ($l['jours'] > 0) {
                $echu += $l['reste'];
                ++$echues;
                if (null === $plusAncienne || $l['jours'] > $plusAncienne['jours']) {
                    $plusAncienne = ['document' => $l['document'], 'jours' => $l['jours'], 'reste' => $l['reste']];
                }
            }
        }
        $enAttente = array_values(array_filter($devis, static fn (Devis $d): bool => \in_array($d->getStatut(), self::DEVIS_EN_ATTENTE, true)));
        $derniere = $actives[0] ?? null;
        $panier = CalculsFicheClient::panierMoyen($cetteAnnee);
        $panierPasse = CalculsFicheClient::panierMoyen($anneePassee);

        return [
            'annee' => $annee,
            'ca' => array_sum($cetteAnnee),
            'ca_precedent' => array_sum($anneePassee),
            'commandes_annee' => \count($cetteAnnee),
            'commandes' => \count($actives),
            'panier_moyen' => $panier,
            'panier_evolution' => [] !== $cetteAnnee ? CalculsFicheClient::evolution($panier, $panierPasse) : null,
            'solde_du' => $soldeDu,
            'echu' => $echu,
            'echues' => $echues,
            'ouvertes' => \count($creances),
            'plus_ancienne_echue' => $plusAncienne,
            'devis_attente' => array_sum(array_map(static fn (Devis $d): int => (int) $d->getTotalTtc(), $enAttente)),
            'devis_attente_nombre' => \count($enAttente),
            'derniere' => $derniere,
            'derniere_jours' => null === $derniere?->getDateCommande() ? null : (int) \DateTimeImmutable::createFromInterface($derniere->getDateCommande())->setTime(0, 0)->diff($maintenant->setTime(0, 0))->days,
        ];
    }

    /**
     * « En cours » (FC01) : commandes ouvertes puis devis en attente.
     *
     * @param list<Commandes>                   $actives
     * @param list<Devis>                       $devis
     * @param array<int, array<string, mixed>>  $suivi
     *
     * @return list<array<string, mixed>>
     */
    private function enCours(array $actives, array $devis, array $suivi): array
    {
        $lignes = [];
        foreach ($actives as $c) {
            $s = $suivi[$c->getId()] ?? null;
            if (null === $s || !$s['ouverte']) {
                continue;
            }
            $lignes[] = ['type' => 'commande', 'objet' => $c, 'numero' => $c->getNumero(), 'titre' => $s['produit'].($s['autres'] > 0 ? ' + '.$s['autres'] : ''), 'montant' => (int) $c->getTotalTtc(), 'statut' => $s['libelle'], 'couleur' => $s['couleur'], 'date' => $s['pourLe'] ?? $c->getDateCommande()];
        }
        foreach ($devis as $d) {
            if (\in_array($d->getStatut(), self::DEVIS_EN_ATTENTE, true)) {
                $lignes[] = ['type' => 'devis', 'objet' => $d, 'numero' => $d->getNumero() ?? '#'.$d->getId(), 'titre' => $d->getObjet() ?: (self::lignesDevis($d) ?? ($d->getInterlocuteur() ? 'pour '.$d->getInterlocuteur() : 'Devis')), 'montant' => (int) $d->getTotalTtc(), 'statut' => self::STATUTS_DEVIS[$d->getStatut()], 'couleur' => self::COULEURS_DEVIS[$d->getStatut()], 'date' => $d->getDateDevis()];
            }
        }

        return \array_slice($lignes, 0, 8);
    }

    /** Le contact qui reçoit les factures : rôle « Factures », sinon le principal. */
    private function contactFactures(Clients $client): ?ContactClient
    {
        $contacts = $this->edition->contacts($client);
        foreach ($contacts as $k) {
            if ('factures' === $k->getRole()) {
                return $k;
            }
        }

        return $contacts[0] ?? null;
    }

    /**
     * Échéancier du client (FC02, sous-onglet « Factures ») : une ligne par
     * commande facturée ou à payer, avec la facture quand il y en a une.
     *
     * @param list<Commandes>            $commandes
     * @param list<array<string, mixed>> $creances
     *
     * @return list<array<string, mixed>>
     */
    private function echeancier(array $commandes, array $creances, \DateTimeImmutable $maintenant): array
    {
        $ouvertes = [];
        foreach ($creances as $l) {
            $ouvertes[$l['commande']->getId()] = $l;
        }
        $lignes = [];
        foreach ($commandes as $c) {
            $total = (int) $c->getTotalTtc();
            // Une commande pas encore confirmée n'est pas une dette (même règle que la caisse et les créances).
            if ($total <= 0 || !$c->isStatut()) {
                continue;
            }
            $o = $ouvertes[$c->getId()] ?? null;
            $facture = $o['facture'] ?? self::factureDe($c);
            $paye = $c->getTotalPaye();
            $reste = null !== $o ? (int) $o['reste'] : max(0, $total - $paye);
            $echeance = $o['echeance'] ?? Creances::echeance($facture, $c);
            $jours = $o['jours'] ?? (null === $echeance ? 0 : (int) $echeance->setTime(0, 0)->diff($maintenant->setTime(0, 0))->format('%r%a'));
            $lignes[] = [
                'commande' => $c,
                'facture' => $facture,
                'document' => $facture?->getNumero() ?? $c->getLibelle(),
                'date' => $facture?->getDateFacture() ?? $c->getDateCommande(),
                // Le numéro de commande seulement s'il n'est pas déjà dans la première colonne.
                'objet' => $c->estSoldeOuverture() ? 'Repris à l\'import' : trim((null !== $facture ? ($c->getNumero() ?? '').' · ' : '').($c->getObjet() ?: EspaceClient::titre($c)), ' ·'),
                'montant' => $total,
                'paye' => min($total, $paye),
                'reste' => $reste,
                'echeance' => $echeance,
                'jours' => $jours,
                'statut' => $reste <= 0 ? ['cle' => 'payee', 'libelle' => 'Payée'] : ($paye > 0 && $jours <= 0 ? ['cle' => 'partielle', 'libelle' => 'Partielle'] : Creances::statut($reste, $jours)),
            ];
        }
        // Ce qui reste à payer d'abord (le plus en retard en tête), puis le reste, du plus récent au plus ancien.
        usort($lignes, static fn (array $a, array $b): int => [$b['reste'] > 0, $b['reste'] > 0 ? $b['jours'] : 0, $b['date']] <=> [$a['reste'] > 0, $a['reste'] > 0 ? $a['jours'] : 0, $a['date']]);

        return $lignes;
    }

    private static function factureDe(Commandes $commande): ?Factures
    {
        $retenue = null;
        foreach ($commande->getFactures() as $f) {
            if ($f->estFacture() && !$f->estBrouillon() && !$f->estAnnulee()) {
                $retenue = $f;
            }
        }

        return $retenue;
    }

    /**
     * Onglet « Factures et paiements » (FC02), pour qui voit les paiements.
     *
     * @param list<Commandes>            $commandes
     * @param list<array<string, mixed>> $echeancier
     * @param array<string, mixed>       $kpi
     *
     * @return array<string, mixed>
     */
    private function finances(Clients $client, array $commandes, array $echeancier, array $kpi, \DateTimeImmutable $maintenant): array
    {
        $paiements = [];
        $soldes = [];
        foreach ($commandes as $c) {
            $valides = [];
            foreach ($c->getPaiements() as $p) {
                $paiements[] = $p;
                if ($p->estValide() && null !== $p->getDate()) {
                    $valides[] = ['date' => $p->getDate(), 'montant' => (int) $p->getMontant()];
                }
            }
            $facture = self::factureDe($c);
            $solde = CalculsFicheClient::dateSolde((int) $c->getTotalTtc(), $valides);
            $debut = $facture?->getDateFacture() ?? $c->getDateCommande();
            if (null !== $solde && null !== $debut && !$c->estSoldeOuverture()) {
                $soldes[] = ['debut' => $debut, 'solde' => $solde];
            }
        }
        usort($paiements, static fn (Paiements $a, Paiements $b): int => [$b->getDate(), $b->getId()] <=> [$a->getDate(), $a->getId()]);
        $annee = (int) $maintenant->format('Y');
        $payesAnnee = array_filter($paiements, static fn (Paiements $p): bool => $p->estValide() && (int) $p->getDate()?->format('Y') === $annee);

        return [
            'paiements' => $paiements,
            'paye_annee' => array_sum(array_map(static fn (Paiements $p): int => (int) $p->getMontant(), $payesAnnee)),
            'paiements_annee' => \count($payesAnnee),
            'delai_moyen' => CalculsFicheClient::delaiPaiementMoyen($soldes),
            'jours_accordes' => CalculsFicheClient::joursAccordes($client->getDelaiPaiement()),
            'relances' => $this->relances($commandes, $echeancier, $maintenant),
        ];
    }

    /**
     * Relances envoyées (à la main ou automatiques) et à venir (règles
     * actives de Communications › Rappels de paiement), la plus récente d'abord.
     *
     * @param list<Commandes>            $commandes
     * @param list<array<string, mixed>> $echeancier
     *
     * @return list<array{date: \DateTimeInterface, numero: ?string, canal: string, par: ?string, automatique: bool, prevue: bool}>
     */
    private function relances(array $commandes, array $echeancier, \DateTimeImmutable $maintenant): array
    {
        if ([] === $commandes) {
            return [];
        }
        $liste = [];
        $envoyees = [];
        foreach ($this->em->getRepository(RelanceCreance::class)->findBy(['commande' => $commandes]) as $r) {
            $liste[] = ['date' => $r->getEnvoyeeLe(), 'numero' => $r->getCommande()?->getNumero(), 'canal' => ModeleMessage::CANAUX_LABELS[$r->getCanal()] ?? $r->getCanal(), 'par' => null !== $r->getPar() ? Pointages::nom($r->getPar()) : null, 'automatique' => false, 'prevue' => false];
        }
        foreach ($this->em->getRepository(RappelPaiementEnvoye::class)->findBy(['commande' => $commandes]) as $r) {
            if (null === $r->getDateEnvoi()) {
                continue;
            }
            $envoyees[$r->getCommande()?->getId().'-'.$r->getRegle()?->getId()] = true;
            $liste[] = ['date' => $r->getDateEnvoi(), 'numero' => $r->getCommande()?->getNumero(), 'canal' => ModeleMessage::CANAUX_LABELS[$r->getCanal()] ?? $r->getCanal(), 'par' => null, 'automatique' => true, 'prevue' => false];
        }
        // À venir : chaque règle active pas encore envoyée, à l'échéance + son délai.
        $regles = $this->em->getRepository(RegleRappelPaiement::class)->findBy(['actif' => true, 'type' => RegleRappelPaiement::TYPE_PAIEMENT]);
        foreach ($echeancier as $l) {
            if ($l['reste'] <= 0 || null === $l['echeance']) {
                continue;
            }
            foreach ($regles as $regle) {
                $date = $l['echeance']->modify('+'.$regle->getDelaiJours().' days')->setTime(9, 0);
                if ($date > $maintenant && !isset($envoyees[$l['commande']->getId().'-'.$regle->getId()])) {
                    $liste[] = ['date' => $date, 'numero' => $l['commande']->getNumero(), 'canal' => ModeleMessage::CANAUX_LABELS[$regle->getModeleMessage()?->getCanal() ?? ''] ?? 'Message', 'par' => $regle->getNom(), 'automatique' => true, 'prevue' => true];
                }
            }
        }
        usort($liste, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $liste;
    }

    /**
     * Produits enregistrés du client (FC03), avec le nombre de commandes.
     *
     * @return array{liste: list<ProduitClient>, commandes: array<int, int>}
     */
    private function produits(Clients $client): array
    {
        /** @var list<ProduitClient> $liste */
        $liste = $this->em->getRepository(ProduitClient::class)->findBy(['client' => $client, 'actif' => true], ['nom' => 'ASC']);
        $commandes = [];
        if ([] !== $liste) {
            $lignes = $this->em->createQueryBuilder()
                ->select('IDENTITY(l.produitClient) AS produit, COUNT(DISTINCT l.commande) AS nombre')
                ->from(CommandesDetails::class, 'l')
                ->join('l.commande', 'c')
                ->where('l.produitClient IN (:produits)')
                ->andWhere('c.deleted = false')
                ->setParameter('produits', $liste)
                ->groupBy('l.produitClient')
                ->getQuery()
                ->getArrayResult();
            $commandes = array_map('intval', array_column($lignes, 'nombre', 'produit'));
        }

        return ['liste' => $liste, 'commandes' => $commandes];
    }

    /**
     * Dossier du client sur le serveur de l'atelier (FC03) : son chemin et
     * ce qu'il contient, les fichiers liés aux produits, les BAT envoyés.
     *
     * @param list<ProduitClient>        $produits
     * @param list<array<string, mixed>> $historique
     *
     * @return array<string, mixed>
     */
    private function dossier(Clients $client, array $produits, array $historique): array
    {
        $chemin = $this->dossiers->dossier($client);
        $contenu = null !== $chemin ? $this->dossiers->liste($client) : [];

        return [
            'chemin' => $chemin,
            'attendu' => $this->dossiers->cheminAttendu($client),
            'regle' => '' !== $this->dossiers->racine(),
            'fichiers' => \count(array_filter($contenu, static fn (array $f): bool => !$f['dossier'])),
            'sous_dossiers' => array_values(array_map(static fn (array $f): string => $f['nom'], array_filter($contenu, static fn (array $f): bool => $f['dossier']))),
            'lies' => array_sum(array_map(static fn (ProduitClient $p): int => \count($p->getFichiersLies()), $produits)),
            'bat' => \count(array_filter($historique, static fn (array $e): bool => 'bat' === ($e['type'] ?? null))),
        ];
    }

    /**
     * Tout ce qui s'est passé avec le client (FC04), le plus récent d'abord.
     *
     * @param list<Commandes>          $commandes
     * @param list<Devis>              $devis
     * @param list<Commandes>          $soldesOuverture
     * @param list<ConversationClient> $conversations
     *
     * @return list<array{date: \DateTimeInterface, categorie: string, type?: string, icone: string, titre: string, qui: ?string, detail: ?string, montant: ?int, paiement: bool, lien: ?array{route: string, parametres: array<string, mixed>, droit: string}}>
     */
    private function historique(Clients $client, array $commandes, array $devis, bool $voirPaiements, array $soldesOuverture, array $conversations): array
    {
        $ev = [];
        $qui = static fn (?User $u): ?string => null !== $u ? Pointages::nom($u) : null;
        $ajouter = static function (array $e) use (&$ev): void {
            $ev[] = $e + ['type' => null, 'qui' => null, 'detail' => null, 'montant' => null, 'paiement' => false, 'lien' => null];
        };
        $lienCommande = static fn (Commandes $c): array => ['route' => 'app_commandes_show', 'parametres' => ['id' => $c->getId()], 'droit' => 'ROLE_COMMANDE'];

        foreach ($voirPaiements ? $soldesOuverture : [] as $s) {
            $ajouter(['date' => $s->getDateCommande(), 'categorie' => 'paiements', 'icone' => 'fa-balance-scale', 'titre' => 'Solde d\'ouverture repris à l\'import', 'montant' => (int) $s->getTotalTtc(), 'paiement' => true, 'lien' => $lienCommande($s)]);
        }
        foreach ($devis as $d) {
            if (null === $d->getDateDevis()) {
                continue;
            }
            $ajouter(['date' => $d->getDateDevis(), 'categorie' => 'commandes', 'icone' => 'fa-file-signature', 'titre' => \sprintf('Devis %s · %s', $d->getNumero() ?? '#'.$d->getId(), mb_strtolower(self::STATUTS_DEVIS[$d->getStatut()] ?? $d->getStatut())), 'qui' => $qui($d->getAgents()), 'montant' => (int) $d->getTotalTtc(), 'lien' => ['route' => 'app_devis_show', 'parametres' => ['id' => $d->getId()], 'droit' => 'ROLE_DEVIS']]);
        }
        foreach ([...$commandes, ...($voirPaiements ? $soldesOuverture : [])] as $c) {
            if (!$c->estSoldeOuverture() && null !== $c->getDateCommande()) {
                $ajouter(['date' => $c->getDateCommande(), 'categorie' => 'commandes', 'icone' => 'fa-box', 'titre' => \sprintf('Commande %s créée · %s', $c->getNumero(), $c->getObjet() ?: EspaceClient::titre($c)), 'qui' => $qui($c->getAgents()), 'montant' => (int) $c->getTotalTtc(), 'lien' => $lienCommande($c)]);
            }
            foreach ($voirPaiements ? $c->getPaiements() : [] as $p) {
                if (null === $p->getDate() || $p->estAnnule() || $p->estRejete()) {
                    continue;
                }
                $ajouter(['date' => $p->getDate(), 'categorie' => 'paiements', 'icone' => 'fa-money-bill-wave', 'titre' => \sprintf('Paiement reçu · %s · %s%s', $p->getModeLabel(), $c->getLibelle(), $p->estValide() ? '' : ' (à valider)'), 'qui' => $qui($p->getEncaissePar()), 'montant' => (int) $p->getMontant(), 'paiement' => true, 'lien' => $p->getId() ? ['route' => 'app_paiements_show', 'parametres' => ['id' => $p->getId()], 'droit' => 'ROLE_PAIEMENT_VOIR'] : null]);
            }
        }
        $avecSoldes = [...$commandes, ...$soldesOuverture];
        if ([] !== $avecSoldes) {
            // Relances : un rappel de paiement parle d'argent, il suit le droit Paiements.
            foreach ($voirPaiements ? $this->em->getRepository(RelanceCreance::class)->findBy(['commande' => $avecSoldes]) : [] as $r) {
                $ajouter(['date' => $r->getEnvoyeeLe(), 'categorie' => 'paiements', 'icone' => 'fa-bell', 'titre' => \sprintf('Relance %s · %s', $r->getCommande()?->getNumero(), ModeleMessage::CANAUX_LABELS[$r->getCanal()] ?? $r->getCanal()), 'qui' => $qui($r->getPar())]);
            }
            foreach ($voirPaiements ? $this->em->getRepository(RappelPaiementEnvoye::class)->findBy(['commande' => $avecSoldes]) : [] as $r) {
                if (null !== $r->getDateEnvoi()) {
                    $ajouter(['date' => $r->getDateEnvoi(), 'categorie' => 'paiements', 'icone' => 'fa-bell', 'titre' => \sprintf('Rappel automatique %s · %s', $r->getCommande()?->getNumero(), ModeleMessage::CANAUX_LABELS[$r->getCanal()] ?? $r->getCanal())]);
                }
            }
        }
        // Notes, appels, visites : internes, jamais montrés au client.
        foreach ($this->em->getRepository(NoteClient::class)->findBy(['client' => $client]) as $n) {
            $ajouter(['date' => $n->getLe(), 'categorie' => 'echanges', 'icone' => match ($n->getType()) { 'appel' => 'fa-phone', 'visite' => 'fa-walking', default => 'fa-sticky-note' }, 'titre' => ('appel' === $n->getType() ? 'Appel : ' : ('visite' === $n->getType() ? 'Visite : ' : 'Note : ')).$n->getTexte(), 'qui' => $qui($n->getAuteur()), 'detail' => null !== $n->getRappelLe() ? 'rappel le '.$n->getRappelLe()->format('d/m') : null]);
        }
        foreach ($this->em->getRepository(ActionCaisse::class)->findBy(['client' => $client, 'etat' => ActionCaisse::FAITE]) as $a) {
            if (null !== $a->getFaiteLe()) {
                $ajouter(['date' => $a->getFaiteLe(), 'categorie' => 'echanges', 'icone' => 'fa-phone', 'titre' => $a->getLibelle().(null !== $a->getResultat() ? ' · '.mb_strtolower(ActionCaisse::RESULTATS[$a->getResultat()] ?? $a->getResultat()) : ''), 'qui' => $qui($a->getFaitePar()), 'detail' => $a->getNote()]);
            }
        }
        // Messages du client et de l'atelier (le dernier de chaque conversation, et chaque message du client).
        if ([] !== $conversations) {
            /** @var list<MessageClient> $messages */
            $messages = $this->em->createQuery('SELECT m FROM '.MessageClient::class.' m WHERE m.conversation IN (:c) AND m.sens != :e ORDER BY m.creeLe DESC')
                ->setParameter('c', $conversations)->setParameter('e', MessageClient::EVENEMENT)->setMaxResults(80)->getResult();
            foreach ($messages as $m) {
                $resume = \App\Service\Messagerie\ResumeConversation::message($m, !$voirPaiements);
                $ajouter(['date' => $m->getCreeLe(), 'categorie' => 'messages', 'icone' => $m->estDeLAtelier() ? 'fa-reply' : 'fa-comment', 'titre' => ($m->estDeLAtelier() ? 'Réponse : ' : 'Message : ').'« '.mb_strimwidth($resume['texte'], 0, 90, '…').' »', 'qui' => $resume['auteur'], 'detail' => $m->getConversation()?->getTitre(), 'lien' => null !== $m->getConversation() ? ['route' => 'app_messages_clients_voir', 'parametres' => ['publicId' => (string) $m->getConversation()->getPublicId()], 'droit' => 'MESSAGERIE'] : null]);
            }
        }
        if ([] !== $commandes) {
            $conceptions = $this->em->createQueryBuilder()->select('k', 'ctl', 'det')->from(Conception::class, 'k')
                ->join('k.controle', 'ctl')->join('ctl.commandeDetail', 'det')
                ->where('det.commande IN (:c)')->setParameter('c', $commandes)
                ->getQuery()->getResult();
            foreach ($conceptions as $k) {
                $commande = $k->getControle()->getCommandeDetail()?->getCommande();
                foreach ($k->getEvenements() as $e) {
                    $titre = match ($e['type']) {
                        'bat' => $e['titre'],
                        'retour' => 'Corrections demandées sur le BAT',
                        'valide' => 'BAT validé',
                        default => null,
                    };
                    if (null !== $titre) {
                        $ajouter(['date' => $e['le'], 'categorie' => 'fichiers', 'type' => $e['type'], 'icone' => 'valide' === $e['type'] ? 'fa-check' : 'fa-image', 'titre' => $titre.(null !== $commande ? ' · '.$commande->getNumero() : ''), 'qui' => $qui($k->getGraphiste()), 'detail' => $e['detail'], 'lien' => null !== $commande ? $lienCommande($commande) : null]);
                    }
                }
            }
            foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $commandes, 'statut' => BonLivraison::STATUT_LIVRE]) as $b) {
                if (null !== $b->getLivreLe()) {
                    $ajouter(['date' => $b->getLivreLe(), 'categorie' => 'commandes', 'icone' => 'fa-truck', 'titre' => \sprintf('Commande %s livrée%s', $b->getCommande()?->getNumero(), $b->getNomReceptionnaire() ? ' et remise à '.$b->getNomReceptionnaire() : ''), 'qui' => $qui($b->getLivrePar() ?? $b->getLivreur())]);
                }
            }
            foreach ($this->fichiers($commandes) as $f) {
                $ajouter(['date' => $f->getTermineLe(), 'categorie' => 'fichiers', 'icone' => 'fa-file-upload', 'titre' => \sprintf('Fichier reçu · %s', $f->getNomOriginal() ?: 'fichier '.$f->getId()), 'detail' => $f->getCommandeDetail()?->getCommande()?->getNumero(), 'lien' => ['route' => 'app_fichier_visualiser', 'parametres' => ['id' => $f->getId()], 'droit' => 'ROLE_USER']]);
            }
        }
        foreach ($this->em->getRepository(ModificationClient::class)->findBy(['client' => $client]) as $m) {
            // Solde du compte et plafond de crédit : des montants d'argent, avec le droit Paiements seulement.
            if (!$voirPaiements && preg_match('/solde|plafond|bonus/iu', $m->getChamp())) {
                continue;
            }
            $ajouter(['date' => $m->getLe(), 'categorie' => 'modifications', 'icone' => 'fa-pen', 'titre' => match (true) {
                null === $m->getAvant() => \sprintf('%s : %s', $m->getChamp(), $m->getApres() ?? '—'),
                null === $m->getApres() => \sprintf('%s : %s', $m->getChamp(), $m->getAvant()),
                'contacts' === $m->getCategorie() => \sprintf('%s : %s → %s', $m->getChamp(), $m->getAvant(), $m->getApres()),
                default => \sprintf('%s modifié : %s → %s', $m->getChamp(), $m->getAvant(), $m->getApres()),
            }, 'qui' => $m->getAuteur(), 'detail' => $m->getDepuisLabel(), 'lien' => ['route' => 'app_clients_journal', 'parametres' => ['publicId' => $client->getPublicId()], 'droit' => 'ROLE_CLIENT']]);
        }

        usort($ev, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $ev;
    }

    /**
     * Factures et proformas émises (pas les brouillons), la plus récente d'abord.
     *
     * @param list<Commandes> $commandes
     *
     * @return list<Factures>
     */
    private function factures(array $commandes): array
    {
        $factures = [];
        foreach ($commandes as $commande) {
            foreach ($commande->getFactures() as $facture) {
                if (!$facture->estBrouillon()) {
                    $factures[] = $facture;
                }
            }
        }
        usort($factures, static fn ($a, $b): int => $b->getDateFacture() <=> $a->getDateFacture());

        return $factures;
    }

    /**
     * Fichiers reçus sur les commandes du client, versions corrigées comprises.
     *
     * @param list<Commandes> $commandes
     *
     * @return list<CommandeDetailFichier>
     */
    private function fichiers(array $commandes): array
    {
        if ([] === $commandes) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('fichier', 'detail', 'commande')
            ->from(CommandeDetailFichier::class, 'fichier')
            ->innerJoin('fichier.commandeDetail', 'detail')
            ->innerJoin('detail.commande', 'commande')
            ->andWhere('commande IN (:commandes)')
            ->andWhere('fichier.termineLe IS NOT NULL')
            ->setParameter('commandes', $commandes)
            ->orderBy('fichier.termineLe', 'DESC')
            ->setMaxResults(60)
            ->getQuery()
            ->getResult();
    }

    /**
     * Satisfaction (FC04) : notes des sondages après commande du client.
     *
     * @return array{moyenne: ?float, avis: int, dernier: ?string}
     */
    private function satisfaction(Clients $client): array
    {
        /** @var list<ReponseSondage> $reponses */
        $reponses = $this->em->getRepository(ReponseSondage::class)->findBy(['client' => $client], ['creeLe' => 'DESC'], 50);
        $dernier = null;
        foreach ($reponses as $r) {
            if (null !== $r->getProposition() && '' !== trim($r->getProposition())) {
                $dernier = trim($r->getProposition());
                break;
            }
        }

        return CalculsFicheClient::satisfaction(array_map(static fn (ReponseSondage $r): ?int => $r->getNoteGlobale(), $reponses)) + ['dernier' => $dernier];
    }

    /**
     * Délais de réponse à ce client (FC04) : première réponse médiane (en
     * minutes ouvrées, comme la supervision) et réponses en retard ce mois.
     *
     * @param list<ConversationClient> $conversations
     *
     * @return array{mediane: ?int, retards: list<array{qui: ?string, minutes: int}>, conversations: int}
     */
    private function reponses(array $conversations, \DateTimeImmutable $maintenant): array
    {
        if ([] === $conversations) {
            return ['mediane' => null, 'retards' => [], 'conversations' => 0];
        }
        /** @var list<MessageClient> $messages */
        $messages = $this->em->createQuery('SELECT m FROM '.MessageClient::class.' m WHERE m.conversation IN (:c) AND m.sens = :a AND m.delaiMinutes IS NOT NULL')
            ->setParameter('c', $conversations)->setParameter('a', MessageClient::ATELIER)->getResult();
        $premieres = [];
        $retards = [];
        $mois = $maintenant->format('Y-m');
        foreach ($messages as $m) {
            if ($m->isPremiere()) {
                $premieres[] = (int) $m->getDelaiMinutes();
            }
            if ($m->estEnRetard() && $m->getCreeLe()->format('Y-m') === $mois) {
                $retards[] = ['qui' => null !== $m->getImputeA() ? Pointages::nom($m->getImputeA()) : null, 'minutes' => (int) $m->getRetardMinutes()];
            }
        }

        return ['mediane' => DelaisReponse::mediane($premieres), 'retards' => $retards, 'conversations' => \count($conversations)];
    }

    /** « Flyers A5 + 1 » : première ligne du devis et nombre d'autres lignes. */
    private static function lignesDevis(Devis $d): ?string
    {
        $lignes = $d->getDevisDetails();
        $premiere = $lignes->first();
        if (false === $premiere) {
            return null;
        }
        $nom = $premiere->getDesignation() ?: $premiere->getProduit()?->getNom();

        return null === $nom || '' === $nom ? null : $nom.(\count($lignes) > 1 ? ' + '.(\count($lignes) - 1) : '');
    }
}
