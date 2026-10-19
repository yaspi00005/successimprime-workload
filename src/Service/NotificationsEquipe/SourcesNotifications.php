<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\ActionCaisse;
use App\Entity\Articles;
use App\Entity\ClotureCaisse;
use App\Entity\CompteTresorerie;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\ConversationClient;
use App\Entity\IncidentMachine;
use App\Entity\Paiements;
use App\Service\CatalogueStock;
use App\Service\Messagerie\DelaisReponse;
use App\Service\Pointage\CalculPointage;
use App\Service\Pointage\Pointages;
use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\Synchro;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sources branchées sur les notifications de l'équipe : chacune dit ce
 * qui est en cours maintenant, à partir des données existantes ; ce qui
 * n'y est plus est résolu tout seul (NotificationsEquipe::synchroniser).
 *
 * | Sorte                | Source existante                                       |
 * |----------------------|--------------------------------------------------------|
 * | acompte_recu         | paiements « en attente » (à valider, caisse)           |
 * | message_client       | conversations des clients qui attendent une réponse     |
 * | client_sans_reponse  | les mêmes, délai dépassé (DelaisReponse, règles MS03)  |
 * | bat_retard           | conception PAO : échéance du BAT passée, BAT pas envoyé |
 * | bat_valide           | BAT validé par le client, pas encore en production      |
 * | devis_relancer       | lignes « Relance devis » de Mes actions (AC01)          |
 * | facture_echue        | lignes « Relance facture » de Mes actions               |
 * | stock_bas            | articles « à commander » (CatalogueStock)               |
 * | machine_panne        | incidents « machine arrêtée » non résolus               |
 * | synchro_coupee       | Synchro::alerte, opérations en attente d'envoi          |
 * | caisse_non_cloturee  | caisse avec encaissements du jour sans clôture à 19 h   |
 * | retard_pointage      | retards du jour (Pointages::jour)                       |
 *
 * Pas de source automatique (créées au moment du geste) : commande prête
 * (fin de production), plafond de crédit (accord demandé), mention.
 */
final class SourcesNotifications
{
    private const RECUL_JOURS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DelaisReponse $delais,
        private readonly CatalogueStock $stock,
        private readonly Synchro $synchro,
        private readonly Pointages $pointages,
        private readonly ReglagesNotifications $reglages,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ce que chaque source constate maintenant (sorte => alertes).
     * Une source en erreur est passée (ses notifications restent telles quelles).
     *
     * @return array<string, list<Alerte>>
     */
    public function constater(\DateTimeImmutable $maintenant): array
    {
        $resultat = [];
        foreach ([
            'acompte_recu' => $this->acomptes(...),
            'conversations' => $this->conversations(...),
            'bat_retard' => $this->batsEnRetard(...),
            'bat_valide' => $this->batsValides(...),
            'actions' => $this->actionsCaisse(...),
            'stock_bas' => $this->stockBas(...),
            'machine_panne' => $this->machines(...),
            'synchro_coupee' => $this->synchroCoupee(...),
            'caisse_non_cloturee' => $this->caisses(...),
            'retard_pointage' => $this->retardsPointage(...),
        ] as $source => $lire) {
            try {
                foreach ($lire($maintenant) as $type => $alertes) {
                    $resultat[$type] = $alertes;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Notifications de l\'équipe : source {source} en erreur ({classe} : {message}).', ['source' => $source, 'classe' => $e::class, 'message' => $e->getMessage()]);
            }
        }

        return $resultat;
    }

    /** @return array<string, list<Alerte>> */
    public function acomptes(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Paiements> $paiements */
        $paiements = $this->em->createQuery('SELECT p FROM '.Paiements::class.' p WHERE p.statut = :s AND p.date >= :recul ORDER BY p.id ASC')
            ->setParameter('s', Paiements::STATUT_EN_ATTENTE)->setParameter('recul', $maintenant->modify('-60 days'))->setMaxResults(100)->getResult();
        $alertes = [];
        foreach ($paiements as $p) {
            $commande = $p->getCommande();
            $deja = null !== $commande && [] !== array_filter($commande->getPaiements()->toArray(), static fn (Paiements $autre): bool => $autre !== $p && Paiements::STATUT_VALIDE === $autre->getStatut());
            $alertes[] = new Alerte(
                'acompte_recu',
                'paiement:'.$p->getId(),
                $deja ? 'Paiement reçu' : 'Acompte reçu',
                ['ROLE_PAIEMENT_ENCAISSER'],
                implode(' · ', array_filter([$commande?->getClients()?->getNomComplet(), $commande?->getNumero(), $p->getModeLabel().' à valider'])),
                'Valider',
                'app_paiements_show',
                ['id' => $p->getId()],
                $p->getMontant(),
                'ROLE_PAIEMENT_VOIR',
            );
        }

        return ['acompte_recu' => $alertes];
    }

    /**
     * Messages des clients : « à faire » à l'arrivée, « urgent » quand le
     * délai de réponse est dépassé (même règle que la supervision MS04).
     *
     * @return array<string, list<Alerte>>
     */
    public function conversations(\DateTimeImmutable $maintenant): array
    {
        /** @var list<ConversationClient> $enAttente */
        $enAttente = $this->em->createQuery('SELECT c, cl FROM '.ConversationClient::class.' c JOIN c.client cl WHERE c.statut = :o AND c.attenteDepuis IS NOT NULL ORDER BY c.attenteDepuis ASC')
            ->setParameter('o', ConversationClient::OUVERTE)->setMaxResults(200)->getResult();
        $nouveaux = [];
        $retards = [];
        foreach ($enAttente as $c) {
            $chrono = $this->delais->chrono($c, $maintenant);
            if (null === $chrono) {
                continue;
            }
            $episode = $c->getId().':'.$c->getAttenteDepuis()?->getTimestamp();
            $pour = null !== $c->getAssigneA() ? [$c->getAssigneA()] : ['ROLE_PAIEMENT_ENCAISSER'];
            $client = $c->getClient()?->getNomComplet() ?? 'Un client';
            $parametres = ['publicId' => (string) $c->getPublicId()];
            if ($chrono['retard'] > 0) {
                $impute = null !== $chrono['impute'] ? ' · imputé à '.self::prenom($chrono['impute']) : (null === $c->getAssigneA() ? ' · non assignée' : '');
                $retards[] = new Alerte('client_sans_reponse', 'conversation-retard:'.$episode, 'Client sans réponse depuis '.NotificationsEquipe::duree($chrono['attente']), $pour, $client.($c->getCommande() ? ' · '.$c->getCommande()->getNumero() : '').$impute, 'Ouvrir', 'app_messages_clients_voir', $parametres);
            } else {
                $nouveaux[] = new Alerte('message_client', 'conversation:'.$episode, 'Message de '.$client, $pour, $c->getTitre().' · réponse attendue dans '.NotificationsEquipe::duree($chrono['reste']), 'Répondre', 'app_messages_clients_voir', $parametres);
            }
        }

        return ['message_client' => $nouveaux, 'client_sans_reponse' => $retards];
    }

    /** @return array<string, list<Alerte>> */
    public function batsEnRetard(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Conception> $conceptions */
        $conceptions = $this->em->createQuery('SELECT c, ctl FROM '.Conception::class.' c JOIN c.controle ctl WHERE c.valideLe IS NULL AND c.echeanceBat < :m AND c.echeanceBat >= :recul AND ctl.statut NOT IN (:finis)')
            ->setParameter('m', $maintenant)->setParameter('recul', $maintenant->modify('-'.self::RECUL_JOURS.' days'))
            ->setParameter('finis', [ControlePrePresse::STATUT_EN_ATTENTE_BAT, ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION, ControlePrePresse::STATUT_REJETE])
            ->getResult();
        $alertes = [];
        foreach ($conceptions as $c) {
            $ligne = $c->getControle()->getCommandeDetail();
            if (null === $ligne || Conception::BAT_ENVOYE === $c->etape()) {
                continue;
            }
            $retard = intdiv($maintenant->getTimestamp() - (int) $c->getEcheanceBat()?->getTimestamp(), 60);
            $alertes[] = new Alerte('bat_retard', 'bat-retard:'.$c->getId(), 'BAT en retard : '.TableauProduction::titre($ligne), null !== $c->getGraphiste() ? [$c->getGraphiste()] : ['ROLE_PREPRESSE'], implode(' · ', array_filter([$ligne->getCommande()?->getClients()?->getNomComplet(), $ligne->getCommande()?->getNumero(), 'échéance dépassée de '.NotificationsEquipe::duree($retard)])), 'Ouvrir', 'app_conception_poste', ['id' => $c->getId()]);
        }

        return ['bat_retard' => $alertes];
    }

    /** @return array<string, list<Alerte>> */
    public function batsValides(\DateTimeImmutable $maintenant): array
    {
        /** @var list<ControlePrePresse> $controles */
        $controles = $this->em->createQuery('SELECT ctl FROM '.ControlePrePresse::class.' ctl WHERE ctl.batValide = true AND ctl.statut NOT IN (:finis) AND ctl.envoyeProductionLe IS NULL AND ctl.creeLe >= :recul')
            ->setParameter('finis', [ControlePrePresse::STATUT_ENVOYE_PRODUCTION, ControlePrePresse::STATUT_REJETE])
            ->setParameter('recul', $maintenant->modify('-'.self::RECUL_JOURS.' days'))->setMaxResults(100)->getResult();
        $alertes = [];
        foreach ($controles as $ctl) {
            $ligne = $ctl->getCommandeDetail();
            if (null === $ligne) {
                continue;
            }
            $alertes[] = new Alerte('bat_valide', 'bat-valide:'.$ctl->getId(), 'BAT validé par le client', ['ROLE_PREPRESSE'], implode(' · ', array_filter([$ligne->getCommande()?->getClients()?->getNomComplet(), $ligne->getCommande()?->getNumero()])).' → lancer l\'impression', 'Lancer', 'app_controle_pre_presse_controler', ['id' => $ligne->getId()]);
        }

        return ['bat_valide' => $alertes];
    }

    /** Relances de devis et de factures de Mes actions (AC01) : celles du jour, pour la personne qui les a. */
    public function actionsCaisse(\DateTimeImmutable $maintenant): array
    {
        /** @var list<ActionCaisse> $actions */
        $actions = $this->em->createQuery('SELECT a FROM '.ActionCaisse::class.' a WHERE a.etat = :e AND a.type IN (:types) AND a.creeLe <= :m ORDER BY a.id ASC')
            ->setParameter('e', ActionCaisse::A_FAIRE)->setParameter('types', ['devis', 'facture'])->setParameter('m', $maintenant)->setMaxResults(300)->getResult();
        $devis = [];
        $factures = [];
        foreach ($actions as $a) {
            $pour = null !== $a->getAssigneA() ? [$a->getAssigneA()] : ['ROLE_PAIEMENT_ENCAISSER'];
            if ('devis' === $a->getType()) {
                $numero = preg_match('/Devis (\S+)/u', (string) $a->getDetail(), $m) ? $m[1] : null;
                $devis[] = new Alerte('devis_relancer', 'action-caisse:'.$a->getId(), 'Relancer '.($numero ?? 'le devis').' · '.$a->getLibelle(), $pour, $a->getDetail(), 'Relancer', 'app_actions_voir', ['id' => $a->getId()]);
            } else {
                $factures[] = new Alerte('facture_echue', 'action-caisse:'.$a->getId(), 'Facture échue · '.$a->getLibelle(), $pour, $a->getDetail(), 'Relancer', 'app_actions_voir', ['id' => $a->getId()], $a->getMontant(), 'ROLE_PAIEMENT_VOIR');
            }
        }

        return ['devis_relancer' => $devis, 'facture_echue' => $factures];
    }

    /** @return array<string, list<Alerte>> */
    public function stockBas(\DateTimeImmutable $maintenant): array
    {
        $articles = array_values(array_filter($this->em->getRepository(Articles::class)->findAll(), static fn (Articles $a): bool => $a->isActif()));
        $alertes = [];
        foreach ($this->stock->lignes($articles, $maintenant) as $l) {
            if (CatalogueStock::ETAT_COMMANDER !== $l['etat'] || $l['article']->getStockMin() <= 0) {
                continue;
            }
            $a = $l['article'];
            $alertes[] = new Alerte('stock_bas', 'stock:'.$a->getId(), $a->getDesignation().' sous le seuil', ['ROLE_ADMIN'], \sprintf('Reste %s %s · seuil %s', self::quantite($l['disponible']), $a->getUniteCourte(), self::quantite($a->getStockMin())), 'Commander', 'app_stock_articles_a_commander');
        }

        return ['stock_bas' => $alertes];
    }

    /** @return array<string, list<Alerte>> */
    public function machines(\DateTimeImmutable $maintenant): array
    {
        /** @var list<IncidentMachine> $incidents */
        $incidents = $this->em->createQuery('SELECT i, m FROM '.IncidentMachine::class.' i JOIN i.machine m WHERE i.arret = true AND i.etat <> :r ORDER BY i.id ASC')
            ->setParameter('r', IncidentMachine::RESOLU)->getResult();
        $alertes = [];
        foreach ($incidents as $i) {
            $par = null !== $i->getSignalePar() ? ' · signalée par '.self::prenom($i->getSignalePar()) : '';
            $alertes[] = new Alerte('machine_panne', 'incident:'.$i->getId(), $i->getMachine()->getNom().' en panne', ['ROLE_PRODUCTION_VOIR', 'ROLE_ATELIER_CHEF'], $i->getTitre().$par.($i->getSuite() ? ' · '.$i->getSuite() : ''), 'Voir l\'atelier', 'app_atelier_maintenance');
        }

        return ['machine_panne' => $alertes];
    }

    /** Connexion coupée au local : l'ERP continue, les opérations attendent. */
    public function synchroCoupee(\DateTimeImmutable $maintenant): array
    {
        if (!$this->synchro->estConfiguree() || $this->synchro->estEnLigne()) {
            return ['synchro_coupee' => []];
        }
        $reussite = $this->synchro->dateEtat(AgentSynchro::ETAT_REUSSITE);
        $alerte = Synchro::alerte(true, $reussite, $this->synchro->dateEtat(AgentSynchro::ETAT_MISE_EN_ROUTE), $maintenant, null);
        if (null === $alerte) {
            return ['synchro_coupee' => []];
        }
        $attente = $this->synchro->enAttente();
        $depuis = null === $reussite ? '' : ' depuis '.($reussite->format('Y-m-d') === $maintenant->format('Y-m-d') ? $reussite->format('H:i') : 'le '.$reussite->format('d/m à H:i'));
        // La vraie cause (agent arrêté, adresse, certificat, opération refusée…), pas toujours « internet coupé ».
        $panne = $this->synchro->panne($maintenant);

        return ['synchro_coupee' => [new Alerte(
            'synchro_coupee',
            'synchro:'.($reussite?->getTimestamp() ?? 0),
            ($panne['titre'] ?? 'Synchronisation en panne').$depuis,
            ['ROLE_USER'],
            'L\'ERP local continue de fonctionner · '.(0 === $attente ? 'aucune opération en attente' : \sprintf('%d opération%s attend%s d\'être envoyée%s au site', $attente, $attente > 1 ? 's' : '', $attente > 1 ? 'ent' : '', $attente > 1 ? 's' : '')).(null !== $panne ? ' · '.$panne['conseil'] : ''),
            'Détails',
            'app_synchronisation_index',
        )]];
    }

    /** Caisse avec des encaissements du jour, pas clôturée après l'heure réglée (19 h). */
    public function caisses(\DateTimeImmutable $maintenant): array
    {
        $heure = $this->reglages->valeurs()['heure_caisse'];
        if ($maintenant->format('H:i') < $heure) {
            return ['caisse_non_cloturee' => []];
        }
        $debut = $maintenant->setTime(0, 0);
        /** @var list<CompteTresorerie> $comptes */
        $comptes = $this->em->createQuery('SELECT c FROM '.CompteTresorerie::class.' c WHERE c.type = :t AND c.actif = true')->setParameter('t', CompteTresorerie::TYPE_CAISSE)->getResult();
        $alertes = [];
        foreach ($comptes as $compte) {
            $encaisse = (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Paiements::class.' p WHERE p.compteTresorerie = :c AND p.statut = :v AND p.date >= :d')
                ->setParameter('c', $compte)->setParameter('v', Paiements::STATUT_VALIDE)->setParameter('d', $debut)->getSingleScalarResult();
            if (0 === $encaisse) {
                continue;
            }
            $cloturee = (int) $this->em->createQuery('SELECT COUNT(k.id) FROM '.ClotureCaisse::class.' k WHERE k.compte = :c AND k.clotureLe >= :d')
                ->setParameter('c', $compte)->setParameter('d', $debut)->getSingleScalarResult();
            if ($cloturee > 0) {
                continue;
            }
            $pour = ['ROLE_ADMIN'];
            if (null !== $compte->getProprietaire()) {
                $pour[] = $compte->getProprietaire();
            }
            $alertes[] = new Alerte('caisse_non_cloturee', 'caisse:'.$compte->getId().':'.$debut->format('Y-m-d'), 'Caisse non clôturée : '.$compte->getNom(), $pour, \sprintf('%d encaissement%s aujourd\'hui · clôture attendue à %s', $encaisse, $encaisse > 1 ? 's' : '', str_replace(':', ' h ', $heure)), 'Clôturer', 'app_caisse_index');
        }

        return ['caisse_non_cloturee' => $alertes];
    }

    /** @return array<string, list<Alerte>> */
    public function retardsPointage(\DateTimeImmutable $maintenant): array
    {
        $alertes = [];
        foreach ($this->pointages->jour($maintenant, $maintenant)['retards'] as $l) {
            if (null === $l['pointage'] || CalculPointage::STATUT_RETARD !== ($l['calcul']['statut'] ?? null)) {
                continue;
            }
            $alertes[] = new Alerte('retard_pointage', 'pointage:'.$l['pointage']->getId(), 'Retard de pointage : '.$l['nom'], ['ROLE_RH'], \sprintf('Arrivée à %s · %s de retard', $l['pointage']->getArriveeLe()->format('H:i'), NotificationsEquipe::duree((int) $l['calcul']['retard'])), 'Voir', 'app_pointage_jour');
        }

        return ['retard_pointage' => $alertes];
    }

    private static function prenom(\App\Entity\User $u): string
    {
        return trim((string) $u->getEmploye()?->getPrenom()) ?: (string) $u->getUsername();
    }

    private static function quantite(float $q): string
    {
        return rtrim(rtrim(number_format($q, 2, ',', ' '), '0'), ',');
    }
}
