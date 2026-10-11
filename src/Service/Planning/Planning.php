<?php

namespace App\Service\Planning;

use App\Entity\Echeance;
use App\Entity\JourFerie;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\TacheRepository;
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

    public function cocher(Tache $t, User $par, \DateTimeImmutable $maintenant): void
    {
        $t->estFaite() ? $t->decocher() : $t->cocher($par, $maintenant);
        $this->em->flush();
    }

    /**
     * Rappels dans l'application (commande planifiée) : 5 jours avant et la
     * veille au responsable, le jour même dès 8 h à la direction.
     */
    public function rappels(\DateTimeImmutable $maintenant): int
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $n = 0;
        foreach ($this->taches->entre($aujourdhui, $aujourdhui->modify('+5 days')) as $t) {
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
            ];
            foreach ($envois as $cle => $message) {
                if ($message === null || !$e->rappel($cle) || $t->rappelEnvoye($cle)) {
                    continue;
                }
                if ($cle === 'jour') {
                    $this->notifications->notifierRoles(['ROLE_ADMIN'], $message, 'app_planning_tache', ['id' => $t->getId()]);
                } elseif ($t->getResponsable() !== null) {
                    $this->notifications->notifierUtilisateur($t->getResponsable(), $message, 'app_planning_tache', ['id' => $t->getId()]);
                }
                $t->noterRappel($cle);
                ++$n;
            }
        }
        $this->em->flush();

        return $n;
    }
}
