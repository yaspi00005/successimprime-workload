<?php

namespace App\Service\ActionsCaisse;

use App\Entity\ActionCaisse;
use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\JournalActivite;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ActionCaisseRepository;
use App\Repository\PointageRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\Pointages;
use App\Service\RelanceRetraitService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lignes d'action de la caisse (maquettes AC01 à AC04) : création
 * automatique selon Paramètres › Relances et délais, attribution à la
 * personne de caisse en poste, délai en heures de travail, points.
 */
final class ActionsCaisse
{
    /** Au-delà, une source ancienne ne crée plus de ligne (pas de rattrapage massif). */
    private const RECUL_FACTURES_JOURS = 90;
    private const RECUL_JOURS = 7;
    private const RAPPEL_MINUTES = 120;
    /** Repère de la seconde relance d'un devis dans le détail de la ligne (message WhatsApp adapté). */
    public const SECONDE_RELANCE = '2e relance';

    private ?HeuresTravail $heures = null;
    /** @var array<int, int>|null lignes ouvertes par personne, tenu à jour pendant une génération */
    private ?array $charge = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActionCaisseRepository $actions,
        private readonly UserRepository $utilisateurs,
        private readonly PointageRepository $pointages,
        private readonly Parametres $parametres,
        private readonly RelanceRetraitService $retraits,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function regles(): array
    {
        $regles = [];
        foreach (['delai_heures', 'points_a_temps', 'points_retard', 'source_facture', 'facture_intervalle_jours', 'source_devis', 'devis_heures', 'devis_seconde_jours', 'source_commande', 'source_prospect', 'source_inactif', 'inactif_jours', 'source_avis', 'source_manuelle'] as $cle) {
            $regles[$cle] = $this->parametres->valeur('relances', $cle);
        }
        foreach (['jours', 'ouverture', 'fermeture', 'feries'] as $cle) {
            $regles[$cle] = $this->parametres->valeur('horaires', $cle);
        }
        $regles['pause_debut'] = $this->parametres->valeur('pointage', 'pause_debut');
        $regles['pause_fin'] = $this->parametres->valeur('pointage', 'pause_fin');

        return $regles;
    }

    public function heures(): HeuresTravail
    {
        $this->heures ??= $this->parametres->heuresTravail();

        return $this->heures;
    }

    public function echeance(\DateTimeImmutable $depart): \DateTimeImmutable
    {
        return $this->heures()->ajouter($depart, (int) $this->regles()['delai_heures'] * 60);
    }

    /**
     * Personnes de caisse : droit d'encaisser, hors administrateurs.
     *
     * @return list<User>
     */
    public function caissiers(): array
    {
        return array_values(array_filter(
            $this->utilisateurs->findBy(['actif' => true]),
            static fn (User $u): bool => \in_array('ROLE_PAIEMENT_ENCAISSER', $u->getRoles(), true) && !\in_array('ROLE_ADMIN', $u->getRoles(), true),
        ));
    }

    /**
     * Personnes de caisse en poste : arrivées pointées aujourd'hui. Sans
     * aucun pointage de caisse ce jour, toutes sont considérées présentes.
     *
     * @return array{personnes: list<User>, parPointage: bool}
     */
    public function enPoste(\DateTimeImmutable $maintenant): array
    {
        $caissiers = $this->caissiers();
        $arrives = [];
        foreach ($this->pointages->entre($maintenant->setTime(0, 0), $maintenant->setTime(0, 0)->modify('+1 day')) as $p) {
            if ($p->getDepartLe() === null) {
                $arrives[$p->getUtilisateur()->getId()] = true;
            }
        }
        $presents = array_values(array_filter($caissiers, static fn (User $u): bool => isset($arrives[$u->getId()])));

        return $presents !== [] ? ['personnes' => $presents, 'parPointage' => true] : ['personnes' => $caissiers, 'parPointage' => false];
    }

    /**
     * La personne en poste qui a le moins de lignes ouvertes.
     *
     * @param list<User> $personnes
     */
    private function choisir(array $personnes, \DateTimeImmutable $maintenant): ?User
    {
        if ($personnes === []) {
            return null;
        }
        if ($this->charge === null) {
            $this->charge = [];
            foreach ($this->actions->ouvertes($maintenant->modify('+1 year')) as $a) {
                if ($a->getAssigneA() !== null) {
                    $this->charge[$a->getAssigneA()->getId()] = ($this->charge[$a->getAssigneA()->getId()] ?? 0) + 1;
                }
            }
        }
        $charge = $this->charge;
        usort($personnes, static fn (User $a, User $b): int => [$charge[$a->getId()] ?? 0, $a->getId()] <=> [$charge[$b->getId()] ?? 0, $b->getId()]);
        $this->charge[$personnes[0]->getId()] = ($charge[$personnes[0]->getId()] ?? 0) + 1;

        return $personnes[0];
    }

    public function nouvelle(string $type, string $cause, string $libelle, \DateTimeImmutable $depart, \DateTimeImmutable $maintenant, ?User $pour = null): ActionCaisse
    {
        // Le délai part de la création, à la minute.
        $depart = $depart->setTime((int) $depart->format('H'), (int) $depart->format('i'));
        $action = new ActionCaisse($type, $cause, $libelle, $depart, $this->echeance($depart));
        $action->attribuer($pour ?? $this->choisir($this->enPoste($maintenant)['personnes'], $maintenant), $depart, $this->echeance($depart));
        $this->em->persist($action);

        return $action;
    }

    /**
     * Crée les lignes des sources actives. Renvoie le nombre de lignes créées.
     */
    public function generer(\DateTimeImmutable $maintenant): int
    {
        $r = $this->regles();
        $candidats = [];
        $aujourdhui = $maintenant->setTime(0, 0);

        if ($r['source_facture']) {
            $factures = $this->em->createQueryBuilder()->select('f')->from(Factures::class, 'f')
                ->where('f.typeDocument = :type AND f.etat = :emise AND f.statutPaiement <> :payee')
                ->andWhere('f.dateEcheance < :aujourdhui AND f.dateEcheance >= :recul')
                ->setParameter('type', Factures::TYPE_FACTURE)->setParameter('emise', Factures::ETAT_EMISE)->setParameter('payee', Factures::STATUT_PAYEE)
                ->setParameter('aujourdhui', $aujourdhui)->setParameter('recul', $aujourdhui->modify('-'.self::RECUL_FACTURES_JOURS.' days'))
                ->getQuery()->getResult();
            foreach ($factures as $f) {
                /** @var Factures $f */
                $jours = (int) $aujourdhui->diff($f->getDateEcheance()->setTime(0, 0))->days;
                $candidats['facture:'.$f->getId()] = ['type' => 'facture', 'repete' => (int) $r['facture_intervalle_jours'], 'creer' => fn (string $cause): ActionCaisse => $this->nouvelle('facture', $cause, $this->nomClient($f->getCommande()?->getClients()), $maintenant, $maintenant)
                    ->lier($f->getCommande()?->getClients(), null, 'facture', $f->getId())
                    ->setMontant($f->getResteAPayer())
                    ->setDetail(\sprintf('Facture %s · échue depuis %d jour%s', $f->getNumero(), $jours, $jours > 1 ? 's' : '')), ];
            }
        }

        if ($r['source_devis']) {
            $devis = $this->em->createQueryBuilder()->select('d')->from(Devis::class, 'd')
                ->where('d.statut = :envoye AND d.deleted = false')
                ->andWhere('d.dateDevis <= :limite AND d.dateDevis >= :recul')
                ->setParameter('envoye', Devis::STATUT_ENVOYE)
                ->setParameter('limite', $maintenant->modify('-'.(int) $r['devis_heures'].' hours'))
                ->setParameter('recul', $maintenant->modify('-30 days'))
                ->getQuery()->getResult();
            foreach ($devis as $d) {
                /** @var Devis $d */
                $heures = (int) floor(($maintenant->getTimestamp() - $d->getDateDevis()->getTimestamp()) / 3600);
                $relance = self::relanceDevis((int) $d->getId(), $heures, (int) $r['devis_heures'], (int) $r['devis_seconde_jours']);
                if ($relance === null) {
                    continue;
                }
                $candidats[$relance['cause']] = ['type' => 'devis', 'apres' => $relance['apres'], 'creer' => function (string $cause) use ($d, $heures, $relance, $maintenant): ActionCaisse {
                    $detail = $relance['rang'] === 1
                        ? \sprintf('Devis %s · sans réponse depuis %d h', $d->getNumero(), $heures)
                        : \sprintf('Devis %s · %s, sans réponse depuis %d jours', $d->getNumero(), self::SECONDE_RELANCE, intdiv($heures, 24));
                    if ($relance['rang'] === 2 && $d->getAgents() !== null) {
                        // L'agent du devis sait que le client est relancé une seconde fois.
                        $this->notifications->notifierUtilisateur($d->getAgents(), \sprintf('Devis %s (%s) sans réponse depuis %d jours : seconde relance confiée à la caisse.', $d->getNumero(), $this->nomClient($d->getClients()), intdiv($heures, 24)), 'app_devis_show', ['id' => $d->getId()]);
                    }

                    return $this->nouvelle('devis', $cause, $this->nomClient($d->getClients()), $maintenant, $maintenant)
                        ->lier($d->getClients(), null, 'devis', $d->getId())
                        ->setDetail($detail);
                }, ];
            }
        }

        if ($r['source_commande']) {
            foreach ($this->retraits->commandesPretes($maintenant) as $l) {
                /** @var Commandes $c */
                $c = $l['commande'];
                if ($l['preteDepuis'] < $aujourdhui->modify('-'.self::RECUL_JOURS.' days')) {
                    continue;
                }
                $candidats['commande:'.$c->getId()] = ['type' => 'commande', 'creer' => fn (string $cause): ActionCaisse => $this->nouvelle('commande', $cause, $this->nomClient($c->getClients()), $maintenant, $maintenant)
                    ->lier($c->getClients(), null, 'commande', $c->getId())
                    ->setDetail(\sprintf('Commande %s prête · prévenir et fixer %s', $c->getNumero(), $c->getModeLivraison() === Commandes::LIVRAISON_DOMICILE ? 'la livraison' : 'le retrait')), ];
            }
        }

        if ($r['source_prospect']) {
            $prospects = $this->em->getRepository(Prospect::class)->createQueryBuilder('p')
                ->where('p.etape = :nouveau AND p.creeLe >= :recul')
                ->setParameter('nouveau', Prospect::ETAPE_NOUVEAU)->setParameter('recul', $aujourdhui->modify('-'.self::RECUL_JOURS.' days'))
                ->getQuery()->getResult();
            foreach ($prospects as $p) {
                /** @var Prospect $p */
                $candidats['prospect:'.$p->getId()] = ['type' => 'prospect', 'creer' => fn (string $cause): ActionCaisse => $this->nouvelle('prospect', $cause, $p->getNom(), $maintenant, $maintenant)
                    ->lier($p->getClient(), $p, 'prospect', $p->getId())
                    ->setDetail('Nouveau prospect'.($p->getBesoin() ? ' · '.$p->getBesoin() : '')), ];
            }
        }

        if ($r['source_inactif']) {
            $jours = (int) $r['inactif_jours'];
            $lignes = $this->em->createQueryBuilder()->select('IDENTITY(c.clients) AS client, MAX(c.dateCommande) AS derniere')->from(Commandes::class, 'c')
                ->where('c.deleted = false AND c.soldeOuverture = false AND c.clients IS NOT NULL')
                ->groupBy('c.clients')
                ->having('MAX(c.dateCommande) <= :limite AND MAX(c.dateCommande) > :recul')
                ->setParameter('limite', $aujourdhui->modify('-'.$jours.' days'))
                ->setParameter('recul', $aujourdhui->modify('-'.($jours + self::RECUL_JOURS).' days'))
                ->getQuery()->getArrayResult();
            foreach ($lignes as $l) {
                $client = $this->em->find(\App\Entity\Clients::class, (int) $l['client']);
                if ($client === null) {
                    continue;
                }
                $derniere = new \DateTimeImmutable((string) $l['derniere']);
                $candidats['inactif:'.$client->getId().':'.$derniere->format('Ymd')] = ['type' => 'inactif', 'creer' => fn (string $cause): ActionCaisse => $this->nouvelle('inactif', $cause, $this->nomClient($client), $maintenant, $maintenant)
                    ->lier($client)
                    ->setDetail(\sprintf('Aucune commande depuis %d jours', $jours)), ];
            }
        }

        if ($r['source_avis']) {
            $bons = $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
                ->where('b.statut = :livre AND b.livreLe < :aujourdhui AND b.livreLe >= :recul')
                ->setParameter('livre', BonLivraison::STATUT_LIVRE)->setParameter('aujourdhui', $aujourdhui)->setParameter('recul', $aujourdhui->modify('-'.self::RECUL_JOURS.' days'))
                ->getQuery()->getResult();
            foreach ($bons as $b) {
                /** @var BonLivraison $b */
                $client = $b->getCommande()?->getClients();
                $candidats['avis:'.$b->getId()] = ['type' => 'avis', 'creer' => fn (string $cause): ActionCaisse => $this->nouvelle('avis', $cause, $this->nomClient($client), $maintenant, $maintenant)
                    ->lier($client, null, 'commande', $b->getCommande()?->getId())
                    ->setDetail(\sprintf('Avis après livraison · %s', $b->getNumero())), ];
            }
        }

        $etat = $this->actions->etatDesCauses([...array_keys($candidats), ...array_filter(array_column($candidats, 'apres'))]);
        $crees = 0;
        foreach ($candidats as $cause => $candidat) {
            $deja = $etat[$cause] ?? null;
            if ($deja !== null && ($deja['ouverte'] || !isset($candidat['repete']) || $deja['derniere'] > $maintenant->modify('-'.$candidat['repete'].' days'))) {
                continue;
            }
            // Une seconde relance attend que la première soit faite.
            if (isset($candidat['apres']) && ($etat[$candidat['apres']]['ouverte'] ?? false)) {
                continue;
            }
            ($candidat['creer'])($cause);
            ++$crees;
        }
        $this->em->flush();

        return $crees;
    }

    /**
     * Lignes sans objet, pénalités de retard, réattribution des lignes
     * d'une personne absente. Renvoie les pénalités appliquées.
     */
    public function actualiser(\DateTimeImmutable $maintenant): int
    {
        $r = $this->regles();
        $ouvertes = $this->actions->ouvertes($maintenant);
        $penalites = 0;

        foreach ($ouvertes as $a) {
            if ($this->causeDisparue($a)) {
                $a->sansObjet($maintenant);
                continue;
            }
            if ($a->estEnRetard($maintenant) && $a->penaliser((int) $r['points_retard'])) {
                ++$penalites;
            }
        }

        // Absence : les lignes passent à une personne en poste, délai repris à zéro.
        $poste = $this->enPoste($maintenant);
        $ouverture = $maintenant->setTime(...array_map('intval', explode(':', (string) $r['ouverture'])))->modify('+1 hour');
        if ($poste['parPointage'] && $maintenant >= $ouverture) {
            $presents = array_map(static fn (User $u): ?int => $u->getId(), $poste['personnes']);
            foreach ($ouvertes as $a) {
                if ($a->getEtat() !== ActionCaisse::A_FAIRE || $a->getAssigneA() === null || \in_array($a->getAssigneA()->getId(), $presents, true)) {
                    continue;
                }
                $pour = $this->choisir($poste['personnes'], $maintenant);
                if ($a->getPenalite() > 0) {
                    // La pénalité reste à la première personne : sa ligne se ferme, une copie part à l'autre.
                    $a->sansObjet($maintenant);
                    $copie = $this->nouvelle($a->getType(), $a->getCause(), $a->getLibelle(), $maintenant, $maintenant, $pour)
                        ->lier($a->getClient(), $a->getProspect(), $a->getCibleType(), $a->getCibleId())
                        ->setMontant($a->getMontant())->setDetail($a->getDetail());
                    $copie->setCreePar($a->getCreePar());
                } else {
                    $a->attribuer($pour, $maintenant, $this->echeance($maintenant));
                }
            }
        }
        $this->em->flush();

        return $penalites;
    }

    /**
     * Enregistre le résultat. Une promesse crée la vérification au jour
     * dit ; « pas de réponse » ou « à rappeler » crée un rappel.
     */
    public function faire(ActionCaisse $a, string $resultat, ?string $note, User $par, \DateTimeImmutable $maintenant, ?\DateTimeImmutable $date = null, ?string $moyen = null): ?ActionCaisse
    {
        $r = $this->regles();
        $a->attribuer($par, $a->getDepartLe(), $a->getEcheance());
        $a->faire($resultat, $note, $par, $maintenant, (int) $r['points_a_temps']);

        $suite = null;
        $ouverture = static fn (\DateTimeImmutable $j): \DateTimeImmutable => $j->setTime(...array_map('intval', explode(':', (string) $r['ouverture'])));
        if ($resultat === 'promesse' && $date !== null) {
            $a->promettre($date, $moyen);
            $suite = $this->nouvelle('verifier', $a->getCause(), $a->getLibelle(), max($ouverture($date), $maintenant), $maintenant, $par)
                ->setDetail(\sprintf('Vérifier le paiement promis le %s%s', $date->format('d/m'), $moyen ? ' · '.$moyen : ''));
        } elseif (\in_array($resultat, ['pas_de_reponse', 'a_rappeler'], true)) {
            // Sans date, le rappel apparaît 2 h de travail plus tard (pas de rappel enchaîné tout de suite).
            $depart = $date !== null ? max($ouverture($date), $maintenant) : $this->heures()->ajouter($maintenant, self::RAPPEL_MINUTES);
            $suite = $this->nouvelle($a->getType(), $a->getCause(), $a->getLibelle(), $depart, $maintenant, $par)
                ->setDetail('Rappel · '.preg_replace('/^Rappel · /', '', (string) $a->getDetail()));
        }
        if ($suite !== null) {
            $suite->lier($a->getClient(), $a->getProspect(), $a->getCibleType(), $a->getCibleId())->setMontant($a->getMontant());
            $suite->setCreePar($par);
        }
        $this->em->flush();

        return $suite;
    }

    public function controler(ActionCaisse $a, User $par, bool $annuler, ?string $motif, \DateTimeImmutable $maintenant): void
    {
        $avant = $a->total();
        $a->controler($par, $maintenant, $annuler, $motif);
        $this->em->persist((new JournalActivite())
            ->setEntite('ActionCaisse')
            ->setEntiteId((int) $a->getId())
            ->setAction(JournalActivite::ACTION_MODIFICATION)
            ->setDonneesAvant(['points' => $avant])
            ->setDonneesApres(['points' => $a->total(), 'controle' => $annuler ? 'points annulés' : 'vérifiée', 'motif' => $motif])
            ->setUtilisateur($par));
        $this->em->flush();
    }

    /**
     * Tableau de bord d'une personne (AC01).
     *
     * @return array<string, mixed>
     */
    public function tableau(User $moi, \DateTimeImmutable $maintenant): array
    {
        $heures = $this->heures();
        $lignes = array_map(fn (ActionCaisse $a): array => ['action' => $a] + $this->compteur($a, $maintenant), $this->actions->aFairePour($moi, $maintenant));
        $debutJour = $maintenant->setTime(0, 0);
        $faites = $this->actions->faitesEntre($debutJour, $debutJour->modify('+1 day'), $moi);
        $mois = $this->bilan($moi, $maintenant->modify('first day of this month')->setTime(0, 0), $maintenant);

        $penalitesJour = 0;
        foreach ($this->actions->findBy(['assigneA' => $moi]) as $a) {
            if ($a->getPenalite() > 0 && $a->getEcheance() >= $debutJour && $a->getEcheance() < $maintenant) {
                $penalitesJour += $a->getPenalite();
            }
        }
        $gagnes = array_sum(array_map(static fn (ActionCaisse $a): int => $a->isPointsAnnules() ? 0 : $a->getPoints(), $faites));

        return [
            'lignes' => $lignes,
            'retards' => array_values(array_filter($lignes, static fn (array $l): bool => $l['etat'] === 'retard')),
            'bientot' => \count(array_filter($lignes, static fn (array $l): bool => $l['etat'] === 'bientot')),
            'faites' => array_map(fn (ActionCaisse $a): array => ['action' => $a, 'ecoule' => $heures->ecoule($a->getDepartLe(), $a->getFaiteLe())], $faites),
            'faitesATemps' => \count(array_filter($faites, static fn (ActionCaisse $a): bool => $a->getPoints() > 0)),
            'gagnes' => $gagnes,
            'penalitesJour' => $penalitesJour,
            'mois' => $mois,
            'regles' => $this->regles(),
        ];
    }

    /**
     * Compte à rebours en heures de travail : vert, orange sous 2 h, rouge en retard.
     *
     * @return array{reste: int, etat: string}
     */
    public function compteur(ActionCaisse $a, \DateTimeImmutable $maintenant): array
    {
        if ($a->estEnRetard($maintenant)) {
            return ['reste' => 0, 'etat' => 'retard'];
        }
        $reste = $this->heures()->ecoule(max($maintenant, $a->getDepartLe()), $a->getEcheance());

        return ['reste' => $reste, 'etat' => $reste < 120 ? 'bientot' : 'ok'];
    }

    /**
     * Bilan d'une personne sur une période (AC04).
     *
     * @return array{lignes: int, aLHeure: int, enRetard: int, points: int, gagnes: int, retires: int, pourcentage: ?int}
     */
    public function bilan(User $personne, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $b = ['lignes' => 0, 'aLHeure' => 0, 'enRetard' => 0, 'points' => 0, 'gagnes' => 0, 'retires' => 0];
        foreach ($this->actions->duMois($debut, $fin) as $a) {
            if ($a->getAssigneA() !== $personne || ($a->getEtat() === ActionCaisse::SANS_OBJET && $a->getPenalite() === 0)) {
                continue;
            }
            ++$b['lignes'];
            $b['aLHeure'] += $a->getPoints() > 0 ? 1 : 0;
            $b['enRetard'] += $a->getPenalite() > 0 ? 1 : 0;
            $b['gagnes'] += $a->isPointsAnnules() ? 0 : $a->getPoints();
            $b['retires'] += $a->getPenalite();
        }
        $b['points'] = $b['gagnes'] - $b['retires'];

        return $b + ['pourcentage' => $b['lignes'] > 0 ? (int) round($b['aLHeure'] / $b['lignes'] * 100) : null];
    }

    /**
     * Relance d'un devis envoyé sans réponse depuis $heures : la première
     * après le délai du réglage (48 h par défaut), la seconde à J+7. La
     * seconde a sa propre cause et attend que la première soit faite.
     *
     * @return array{cause: string, rang: int, apres: ?string}|null
     */
    public static function relanceDevis(int $devisId, int $heures, int $heuresPremiere, int $joursSeconde): ?array
    {
        if ($heures < $heuresPremiere) {
            return null;
        }
        if ($joursSeconde > 0 && $heures >= $joursSeconde * 24 && $joursSeconde * 24 > $heuresPremiere) {
            return ['cause' => 'devis:'.$devisId.':2', 'rang' => 2, 'apres' => 'devis:'.$devisId];
        }

        return ['cause' => 'devis:'.$devisId, 'rang' => 1, 'apres' => null];
    }

    /**
     * Liens d'appel et de WhatsApp (message prêt), ou null sans numéro.
     *
     * @return array{tel: ?string, whatsapp: ?string}
     */
    public static function liens(ActionCaisse $a): array
    {
        $telephone = trim((string) $a->getTelephone());
        if ($telephone === '') {
            return ['tel' => null, 'whatsapp' => null];
        }
        $piece = preg_replace('/ · .*$/', '', (string) $a->getDetail());
        $message = match ($a->getType()) {
            'facture', 'verifier' => \sprintf('Bonjour, ici Succès Imprim. Petit rappel : %s reste à régler%s. Merci de nous dire quand le paiement est prévu.', mb_strtolower((string) $piece), $a->getMontant() ? \sprintf(' (%s F)', number_format($a->getMontant(), 0, ',', ' ')) : ''),
            'devis' => str_contains((string) $a->getDetail(), self::SECONDE_RELANCE)
                ? \sprintf('Bonjour, ici Succès Imprim. Notre %s est toujours valable. Souhaitez-vous le valider, ou que nous l\'ajustions avec vous ?', mb_strtolower((string) $piece))
                : \sprintf('Bonjour, ici Succès Imprim. Avez-vous pu regarder notre %s ? Nous restons disponibles pour l\'ajuster.', mb_strtolower((string) $piece)),
            'commande' => \sprintf('Bonjour, ici Succès Imprim. Votre %s. Quand souhaitez-vous la récupérer ?', mb_strtolower((string) $a->getDetail())),
            'avis' => 'Bonjour, ici Succès Imprim. Êtes-vous satisfait de votre commande ? Votre avis nous aide beaucoup.',
            'inactif' => 'Bonjour, ici Succès Imprim. Cela fait un moment ! Avez-vous des impressions à prévoir ? Nous pouvons vous faire un devis rapidement.',
            default => 'Bonjour, ici Succès Imprim.',
        };

        return [
            'tel' => 'tel:'.preg_replace('/[^\d+]/', '', $telephone),
            'whatsapp' => 'https://wa.me/'.\App\Controller\UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($message),
        ];
    }

    private function causeDisparue(ActionCaisse $a): bool
    {
        $id = $a->getCibleId();
        if ($id === null) {
            return false;
        }

        return match ($a->getCibleType()) {
            'facture' => ($f = $this->em->find(Factures::class, $id)) === null || $f->getStatutPaiement() === Factures::STATUT_PAYEE || $f->getEtat() === Factures::ETAT_ANNULEE,
            'devis' => ($d = $this->em->find(Devis::class, $id)) === null || $d->getStatut() !== Devis::STATUT_ENVOYE,
            'commande' => $a->getType() === 'commande' && (($c = $this->em->find(Commandes::class, $id)) === null || $c->isRecupereParClient()),
            default => false,
        };
    }

    private function nomClient(?\App\Entity\Clients $client): string
    {
        return $client !== null ? $client->getNomComplet() : 'Client';
    }

    public static function nom(?User $u): string
    {
        return $u !== null ? Pointages::nom($u) : 'Personne';
    }
}
