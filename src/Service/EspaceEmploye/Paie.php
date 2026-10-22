<?php

namespace App\Service\EspaceEmploye;

use App\Entity\BulletinPaie;
use App\Entity\CompteTresorerie;
use App\Entity\DemandeEmploye;
use App\Entity\JournalActivite;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Repository\BulletinPaieRepository;
use App\Service\MouvementTresorerieService;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paie du mois (tuile Paie) : bulletins préparés depuis le salaire de la
 * fiche employé, complétés par la RH, validés puis payés en trésorerie
 * (décaissement salaire, confidentiel). ITS et INPS sont des lignes
 * saisies, proposées seulement quand l'option est active dans la tuile.
 */
final class Paie
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BulletinPaieRepository $bulletins,
        private readonly Pointages $pointages,
        private readonly Parametres $parametres,
        private readonly NotificationService $notifications,
        private readonly MouvementTresorerieService $tresorerie,
    ) {
    }

    /** Types de ligne proposés : ITS et INPS seulement si l'option est active. @return array<string, string> */
    public function typesLignes(): array
    {
        $types = [];
        foreach (BulletinPaie::LIGNES as $type => [$libelle]) {
            if (\in_array($type, ['its', 'inps'], true) && !$this->parametres->valeur('paie', $type)) {
                continue;
            }
            $types[$type] = $libelle;
        }

        return $types;
    }

    /** Date de versement de la paie d'un mois : le jour de paie du mois suivant. */
    public function dateVersement(\DateTimeImmutable $mois): \DateTimeImmutable
    {
        $suivant = BulletinPaie::premierDuMois($mois)->modify('+1 month');
        $jour = min(max(1, (int) $this->parametres->valeur('paie', 'jour_paie')), (int) $suivant->format('t'));

        return $suivant->setDate((int) $suivant->format('Y'), (int) $suivant->format('m'), $jour);
    }

    /**
     * Crée les bulletins manquants du mois pour le personnel actif qui a
     * un salaire sur sa fiche.
     *
     * @return array{crees: int, sansSalaire: list<string>}
     */
    public function preparer(\DateTimeImmutable $mois, User $par, \DateTimeImmutable $maintenant): array
    {
        $existants = [];
        foreach ($this->bulletins->duMois($mois) as $b) {
            $existants[$b->getUtilisateur()->getId()] = true;
        }
        $crees = 0;
        $sansSalaire = [];
        foreach ($this->pointages->personnel() as $u) {
            if (isset($existants[$u->getId()])) {
                continue;
            }
            $salaire = (int) $u->getEmploye()?->getSalaires();
            if ($salaire <= 0) {
                $sansSalaire[] = Pointages::nom($u);
                continue;
            }
            $this->em->persist(new BulletinPaie($u, $mois, $salaire, $maintenant));
            ++$crees;
        }
        if ($crees > 0) {
            $this->tracer(null, $par, 'preparer', ['mois' => BulletinPaie::premierDuMois($mois)->format('Y-m'), 'crees' => $crees]);
        }
        $this->em->flush();

        return ['crees' => $crees, 'sansSalaire' => $sansSalaire];
    }

    /** Jours d'absence acceptés dans le mois (pour décider d'une retenue). */
    public function joursAbsence(User $u, \DateTimeImmutable $mois): float
    {
        $debut = BulletinPaie::premierDuMois($mois);
        $total = 0.0;
        $absences = $this->em->getRepository(DemandeEmploye::class)->createQueryBuilder('d')
            ->where('d.utilisateur = :u')->andWhere('d.type = :t')->andWhere('d.etat IN (:etats)')
            ->andWhere('d.du >= :debut')->andWhere('d.du < :fin')
            ->setParameter('u', $u)->setParameter('t', DemandeEmploye::ABSENCE)
            ->setParameter('etats', [DemandeEmploye::ACCEPTEE, DemandeEmploye::PAYEE])
            ->setParameter('debut', $debut)->setParameter('fin', $debut->modify('+1 month'))
            ->getQuery()->getResult();
        foreach ($absences as $d) {
            $total += (float) $d->getJours();
        }

        return $total;
    }

    public function ajouterLigne(BulletinPaie $b, string $type, string $libelle, int $montant, User $par): ?string
    {
        if (!isset($this->typesLignes()[$type])) {
            return 'Cette ligne n\'est pas active : voir la tuile Paie.';
        }
        $erreur = $b->ajouterLigne($type, $libelle, $montant);
        if ($erreur === null) {
            $this->tracer($b, $par, 'ligne', ['type' => $type]);
            $this->em->flush();
        }

        return $erreur;
    }

    public function valider(BulletinPaie $b, User $par, \DateTimeImmutable $maintenant): ?string
    {
        $erreur = $b->valider($par, $maintenant);
        if ($erreur === null) {
            $this->notifications->notifierUtilisateur($b->getUtilisateur(), \sprintf('Votre bulletin de paie de %s est disponible.', self::mois($b->getMois())), 'app_mon_espace_bulletin', ['id' => $b->getId()]);
            $this->tracer($b, $par, 'valider', ['net' => $b->net()]);
            $this->em->flush();
        }

        return $erreur;
    }

    public function payer(BulletinPaie $b, CompteTresorerie $compte, User $par): ?string
    {
        if ($b->getEtat() !== BulletinPaie::VALIDE) {
            return 'Validez le bulletin avant de le payer.';
        }
        $modes = ['orange_money' => 'Orange Money', 'virement' => 'virement', 'especes' => 'espèces'];
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_SALAIRE)
            ->setCompteSource($compte)
            ->setMontant($b->net())
            ->setLibelle(\sprintf('Salaire %s · %s', self::mois($b->getMois()), Pointages::nom($b->getUtilisateur())))
            ->setDescription('Paie par '.($modes[(string) $this->parametres->valeur('paie', 'mode')] ?? 'Orange Money'))
            ->setConfidentiel(true);
        try {
            $this->tresorerie->enregistrer($mouvement);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $b->payer($mouvement);
        $this->notifications->notifierUtilisateur($b->getUtilisateur(), \sprintf('Salaire de %s payé.', self::mois($b->getMois())), 'app_mon_espace_bulletin', ['id' => $b->getId()]);
        $this->tracer($b, $par, 'payer', ['mouvement' => $mouvement->getReference()]);
        $this->em->flush();

        return null;
    }

    /** « octobre 2026 ». */
    public static function mois(\DateTimeImmutable $mois): string
    {
        $noms = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $noms[(int) $mois->format('n')].' '.$mois->format('Y');
    }

    /** @param array<string, mixed> $apres */
    private function tracer(?BulletinPaie $b, User $par, string $action, array $apres): void
    {
        $this->em->persist((new JournalActivite())
            ->setUtilisateur($par)
            ->setAction($action === 'preparer' ? JournalActivite::ACTION_CREATION : JournalActivite::ACTION_MODIFICATION)
            ->setEntite('BulletinPaie')
            ->setEntiteId((int) $b?->getId())
            ->setDonneesApres(['_action' => $action] + $apres));
    }
}
