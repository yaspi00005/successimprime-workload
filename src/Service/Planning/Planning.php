<?php

namespace App\Service\Planning;

use App\Entity\Echeance;
use App\Entity\CompteTresorerie;
use App\Entity\JourFerie;
use App\Entity\MouvementTresorerie;
use App\Entity\RappelWhatsApp;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\TacheRepository;
use App\Service\MouvementTresorerieService;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Planning et tâches (docs/planning-taches.md, étape 1) : occurrences des
 * échéances sur 13 mois glissants, droits de lecture, rappels dans
 * l'application. Chacun voit ses tâches ; la direction voit tout ; les
 * catégories Fiscal et Paie ne s'ouvrent qu'à la direction, au comptable
 * (droit trésorerie) et au responsable désigné.
 */
final class Planning
{
    public const HORIZON = '+13 months';

    /** Catégorie de dépense proposée selon la catégorie de la tâche (étape 3). */
    public const DEPENSES = [
        'fiscal' => MouvementTresorerie::CATEGORIE_AUTRE_CHARGE,
        'fournisseurs' => MouvementTresorerie::CATEGORIE_ACHAT,
        'paie' => MouvementTresorerie::CATEGORIE_SALAIRE,
        'atelier' => MouvementTresorerie::CATEGORIE_ENTRETIEN,
        'stock' => MouvementTresorerie::CATEGORIE_ACHAT,
        'livraisons' => MouvementTresorerie::CATEGORIE_TRANSPORT,
        'commercial' => MouvementTresorerie::CATEGORIE_AUTRE_CHARGE,
        'entretien' => MouvementTresorerie::CATEGORIE_ENTRETIEN,
        'administratif' => MouvementTresorerie::CATEGORIE_AUTRE_CHARGE,
    ];

    /** Modèles prêts (T02). */
    public const MODELES = [
        'impots' => ['titre' => 'Déclaration et paiement des impôts', 'categorie' => 'fiscal', 'frequence' => 'mois', 'jourMois' => 25, 'etapes' => ['Rassembler les factures du mois et la TVA', 'Vérifier les écritures validées en local', 'Remplir la déclaration', 'Payer et joindre le reçu']],
        'fournisseur' => ['titre' => 'Payer un fournisseur', 'categorie' => 'fournisseurs', 'frequence' => 'mois', 'jourMois' => 1, 'etapes' => []],
        'loyer' => ['titre' => 'Loyer', 'categorie' => 'administratif', 'frequence' => 'mois', 'jourMois' => 5, 'etapes' => []],
        'salaires' => ['titre' => 'Salaires', 'categorie' => 'paie', 'frequence' => 'mois', 'jourMois' => 5, 'etapes' => ['Préparer les bulletins', 'Valider les bulletins', 'Payer']],
        'compteurs' => ['titre' => 'Relevés des compteurs machines', 'categorie' => 'atelier', 'frequence' => 'mois', 'jourMois' => 1, 'etapes' => []],
        'caisse' => ['titre' => 'Clôture de caisse', 'categorie' => 'administratif', 'frequence' => 'jour', 'heure' => '19:00', 'etapes' => []],
        'entretien' => ['titre' => 'Atelier · sol, poussière des machines', 'categorie' => 'entretien', 'frequence' => 'jour', 'heure' => '07:30', 'heureFin' => '08:00', 'decalage' => Calendrier::GARDER, 'etapes' => ['Balayer et laver le sol', 'Dépoussiérer les machines', 'Vider les poubelles']],
        'fermeture' => ['titre' => 'Fermeture · poubelles, sols, lumières', 'categorie' => 'entretien', 'frequence' => 'jour', 'heure' => '17:30', 'heureFin' => '18:00', 'decalage' => Calendrier::GARDER, 'fermeture' => true, 'etapes' => ['Vider les poubelles', 'Balayer la boutique', 'Balayer l\'atelier', 'Ranger les présentoirs', 'Éteindre les machines', 'Éteindre la climatisation', 'Éteindre les lumières', 'Fermer les fenêtres']],
        'tetes' => ['titre' => 'Nettoyage des têtes', 'categorie' => 'atelier', 'frequence' => 'jour', 'heure' => '08:00', 'etapes' => []],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TacheRepository $taches,
        private readonly Parametres $parametres,
        private readonly NotificationService $notifications,
        private readonly Security $security,
        private readonly MouvementTresorerieService $mouvements,
    ) {
    }

    public function calendrier(): Calendrier
    {
        $fetes = [];
        foreach ($this->em->getRepository(JourFerie::class)->findAll() as $f) {
            $fetes[$f->getJour()->format('Y-m-d')] = $f->getLibelle();
        }
        $activite = match ((string) $this->parametres->valeur('horaires', 'jours')) {
            'lun-ven' => [1, 2, 3, 4, 5],
            'lun-dim' => [1, 2, 3, 4, 5, 6, 7],
            default => [1, 2, 3, 4, 5, 6],
        };

        return new Calendrier($fetes, [1, 2, 3, 4, 5], $activite);
    }

    /**
     * Crée les occurrences manquantes d'une échéance jusqu'à l'horizon.
     * Après une modification, les occurrences futures intactes sont
     * réécrites ; celles déjà commencées ou faites restent.
     */
    public function generer(Echeance $e, \DateTimeImmutable $maintenant, bool $reecrire = false): int
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $existantes = [];
        foreach ($this->taches->findBy(['echeance' => $e]) as $t) {
            if ($reecrire && $t->intacte() && $t->getPrevueLe() >= $aujourdhui) {
                $this->em->remove($t);
                continue;
            }
            $existantes[$t->getDateOrigine()?->format('Y-m-d')] = true;
        }
        if ($reecrire) {
            $this->em->flush();
        }
        if (!$e->isActif()) {
            return 0;
        }
        $du = $e->getFrequence() === Calendrier::UNE_FOIS ? $e->getDebut()->modify('-1 month') : $aujourdhui;
        $crees = 0;
        foreach ($this->calendrier()->occurrences($e->regle(), $du, $aujourdhui->modify(self::HORIZON)) as $o) {
            if (isset($existantes[$o['origine']->format('Y-m-d')])) {
                continue;
            }
            $this->em->persist((new Tache($o['date'], $maintenant))->reprendre($e)->setDateOrigine($o['origine'])->setRaisonDecalage($o['raison']));
            ++$crees;
        }
        $this->em->flush();

        return $crees;
    }

    /** Prolonge l'horizon de toutes les échéances actives (commande planifiée). */
    public function prolonger(\DateTimeImmutable $maintenant): int
    {
        $n = 0;
        foreach ($this->em->getRepository(Echeance::class)->findBy(['actif' => true]) as $e) {
            $n += $this->generer($e, $maintenant);
        }

        return $n;
    }

    /** La direction : voit et gère tout le planning. */
    public function direction(): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }

    /** Voit les montants (encres, salaires, impôts). */
    public function voitMontants(): bool
    {
        return $this->security->isGranted('ROLE_TRESORERIE_VOIR');
    }

    public function peutVoir(Tache $t, User $u): bool
    {
        if ($this->direction() || $t->concerne($u)) {
            return true;
        }

        return \in_array($t->getCategorie(), Echeance::CONFIDENTIELLES, true) && $this->voitMontants();
    }

    public function peutModifier(Echeance $e, User $u): bool
    {
        return $this->direction() || $e->getResponsable() === $u || ($e->getResponsable() === null && $e->getCreePar() === $u);
    }

    /** @param list<Tache> $taches @return list<Tache> */
    public function visibles(array $taches, User $u): array
    {
        return array_values(array_filter($taches, fn (Tache $t): bool => $this->peutVoir($t, $u)));
    }

    /** @return list<Tache> */
    public function entre(\DateTimeImmutable $du, \DateTimeImmutable $au, User $u, ?array $categories = null): array
    {
        $liste = $this->visibles($this->taches->entre($du, $au), $u);
        if ($categories !== null && $categories !== []) {
            $liste = array_values(array_filter($liste, static fn (Tache $t): bool => \in_array($t->getCategorie(), $categories, true)));
        }

        return $liste;
    }

    /** @return list<Tache> */
    public function enRetard(User $u, \DateTimeImmutable $maintenant): array
    {
        return $this->visibles($this->taches->enRetard($maintenant), $u);
    }

    /**
     * Prochaines échéances importantes (« À ne pas manquer ») : celles qui
     * ont des rappels, hors tâches quotidiennes.
     *
     * @return list<Tache>
     */
    public function aNePasManquer(User $u, \DateTimeImmutable $maintenant, int $nombre = 4): array
    {
        $liste = array_filter(
            $this->entre($maintenant->modify('+1 day'), $maintenant->modify('+45 days'), $u),
            static fn (Tache $t): bool => !$t->estFaite() && $t->getEcheance()?->getFrequence() !== Calendrier::JOUR && $t->getEcheance()?->getFrequence() !== Calendrier::SEMAINE,
        );

        return \array_slice(array_values($liste), 0, $nombre);
    }

    /**
     * Coche ou décoche la tâche. Une tâche avec un montant prépare une
     * dépense à valider en trésorerie (jamais passée toute seule) ; la
     * décocher annule cette dépense tant qu'elle n'est pas validée.
     *
     * @return string|null message pour la personne
     */
    public function cocher(Tache $t, User $par, \DateTimeImmutable $maintenant): ?string
    {
        $message = null;
        if ($t->estFaite()) {
            $t->decocher();
            $m = $t->getMouvement();
            if ($m !== null && $m->isEnAttente()) {
                $this->mouvements->annulerEnAttente($m, 'Tâche du planning décochée');
                $t->setMouvement(null);
                $message = 'La dépense préparée est annulée.';
            } elseif ($m !== null && $m->getStatut() === MouvementTresorerie::STATUT_VALIDE) {
                $message = 'La dépense est déjà validée en trésorerie : elle y reste.';
            }
        } else {
            $t->cocher($par, $maintenant);
            if ((int) $t->getMontantEstime() > 0 && $t->getMouvement() === null && ($compte = $this->compteDepense($t)) !== null) {
                $t->setMouvement($this->mouvements->preparerEnAttente(self::depense($t, $compte, $par, $maintenant)));
                $message = $this->voitMontants()
                    ? \sprintf('Dépense de %s F préparée : elle attend la validation en trésorerie.', number_format((int) $t->getMontantEstime(), 0, ',', ' '))
                    : 'La dépense est préparée : elle attend la validation en trésorerie.';
            }
        }
        $this->em->flush();

        return $message;
    }

    /** Dépense préparée pour une tâche faite : en attente, avec le montant estimé. */
    public static function depense(Tache $t, CompteTresorerie $compte, User $par, \DateTimeImmutable $maintenant): MouvementTresorerie
    {
        return (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie($t->getEcheance()?->getCategorieDepense() ?? self::DEPENSES[$t->getCategorie()] ?? MouvementTresorerie::CATEGORIE_AUTRE_CHARGE)
            ->setCompteSource($compte)
            ->setMontant((int) $t->getMontantEstime())
            ->setLibelle(mb_substr(\sprintf('Planning · %s (%s)', $t->getTitre(), $t->getPrevueLe()->format('d/m/Y')), 0, 255))
            ->setDescription('Préparé automatiquement quand la tâche a été cochée faite. À vérifier avant validation : montant réel et reçu.')
            ->setDateOperation($maintenant)
            ->setAgent($par);
    }

    /** Compte choisi sur l'échéance, sinon la première caisse active. */
    private function compteDepense(Tache $t): ?CompteTresorerie
    {
        return $t->getEcheance()?->getCompte()
            ?? $this->em->getRepository(CompteTresorerie::class)->findOneBy(['type' => CompteTresorerie::TYPE_CAISSE, 'actif' => true], ['id' => 'ASC'])
            ?? $this->em->getRepository(CompteTresorerie::class)->findOneBy(['actif' => true], ['id' => 'ASC']);
    }

    /**
     * Tâches avec un montant, pas encore faites, entre deux dates : elles
     * comptent dans la trésorerie prévisionnelle à leur date.
     *
     * @return list<Tache>
     */
    public function depensesPrevues(\DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        return array_values(array_filter(
            $this->taches->entre($du, $au),
            static fn (Tache $t): bool => (int) $t->getMontantEstime() > 0 && !$t->estFaite() && $t->getMouvement() === null,
        ));
    }

    /**
     * Rappels (commande planifiée) : 5 jours avant et la veille au
     * responsable, le jour même dès 8 h à la direction, et une alerte au
     * responsable quand la tâche passe en retard. Chaque rappel au
     * responsable est aussi préparé pour WhatsApp (« Rappels à envoyer »)
     * quand son dossier a un téléphone, sauf pour les tâches de chaque jour.
     * Une tâche en retard depuis plus de 7 jours ne déclenche plus d'alerte.
     */
    public function rappels(\DateTimeImmutable $maintenant): int
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $n = 0;
        $taches = array_merge($this->taches->enRetard($maintenant), $this->taches->entre($aujourdhui, $aujourdhui->modify('+5 days')));
        foreach ($taches as $t) {
            $e = $t->getEcheance();
            if ($t->estFaite() || $e === null) {
                continue;
            }
            $jours = (int) $aujourdhui->diff($t->getPrevueLe())->format('%r%a');
            $date = $t->getPrevueLe()->format('d/m');
            $envois = [
                'j5' => $jours <= 5 && $jours > 1 ? \sprintf('Rappel : %s le %s (dans %d jours).', $t->getTitre(), $date, $jours) : null,
                'veille' => $jours === 1 ? \sprintf('Demain : %s.', $t->getTitre()) : null,
                'jour' => $jours === 0 && (int) $maintenant->format('G') >= 8 ? \sprintf('Aujourd\'hui : %s%s.', $t->getTitre(), $t->getEtapes() !== [] ? \sprintf(', %d étapes sur %d faites', $t->etapesFaites(), \count($t->getEtapes())) : '') : null,
                'retard' => $jours < 0 && $jours >= -7 ? \sprintf('En retard : %s, prévu le %s.', $t->getTitre(), $date) : null,
            ];
            foreach ($envois as $cle => $message) {
                if ($message === null || !$e->rappel($cle) || $t->rappelEnvoye($cle)) {
                    continue;
                }
                $responsable = $t->getResponsable();
                if ($cle === 'jour') {
                    $this->notifications->notifierRoles(['ROLE_ADMIN'], $message, 'app_planning_tache', ['id' => $t->getId()]);
                } elseif ($responsable !== null) {
                    $this->notifications->notifierUtilisateur($responsable, $message, 'app_planning_tache', ['id' => $t->getId()]);
                }
                $telephone = trim((string) $responsable?->getEmploye()?->getTelephone());
                if ($responsable !== null && $responsable->isActif() && $telephone !== '' && $e->getFrequence() !== Calendrier::JOUR) {
                    $prenom = $responsable->getEmploye()?->getPrenom() ?: $responsable->getUserIdentifier();
                    $this->em->persist(new RappelWhatsApp($t, $cle, $responsable, $telephone, self::messageWhatsApp($prenom, $cle, $t->getTitre(), $t->getPrevueLe(), $jours), $maintenant));
                }
                $t->noterRappel($cle);
                ++$n;
            }
        }
        $this->em->flush();

        return $n;
    }

    /**
     * Rappels WhatsApp de la semaine pas encore envoyés, sur des tâches
     * encore ouvertes.
     *
     * @return list<RappelWhatsApp>
     */
    public function rappelsAEnvoyer(\DateTimeImmutable $maintenant): array
    {
        return $this->em->getRepository(RappelWhatsApp::class)->createQueryBuilder('r')->addSelect('t')->innerJoin('r.tache', 't')
            ->where('r.envoyeLe IS NULL')->andWhere('r.creeLe >= :depuis')->andWhere('t.statut IN (:ouverts)')
            ->setParameter('depuis', $maintenant->modify('-7 days'))->setParameter('ouverts', [Tache::A_FAIRE, Tache::EN_COURS])
            ->orderBy('t.prevueLe', 'ASC')->getQuery()->getResult();
    }

    /** Message WhatsApp prêt à envoyer au responsable (jamais de montant). */
    public static function messageWhatsApp(string $prenom, string $cle, string $titre, \DateTimeImmutable $prevueLe, int $jours): string
    {
        $date = $prevueLe->format('d/m/Y');
        $quoi = match ($cle) {
            'j5' => \sprintf('« %s » est prévu le %s, dans %d jours.', $titre, $date, $jours),
            'veille' => \sprintf('« %s » est prévu demain, %s.', $titre, $date),
            'jour' => \sprintf('« %s » est prévu aujourd\'hui.', $titre),
            default => \sprintf('« %s », prévu le %s, n\'est pas encore fait.', $titre, $date),
        };

        return \sprintf("Bonjour %s,\nRappel du planning Succès Imprim : %s\nMerci de le cocher dans l'application une fois fait.", $prenom, $quoi);
    }
}
