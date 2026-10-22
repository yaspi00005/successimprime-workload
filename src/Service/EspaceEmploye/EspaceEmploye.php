<?php

namespace App\Service\EspaceEmploye;

use App\Entity\CompteTresorerie;
use App\Entity\CongeEmploye;
use App\Entity\DemandeEmploye;
use App\Entity\JournalActivite;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Repository\CongeEmployeRepository;
use App\Repository\DemandeEmployeRepository;
use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\MouvementTresorerieService;
use App\Service\NotificationService;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Espace employé (docs/espace-employe.md) : absences, congés, réclamations
 * de sommes dues et frais médicaux (tuile Avantages), décision de la RH
 * et paiement par la trésorerie.
 */
final class EspaceEmploye
{
    public const JUSTIFICATIF_MAX_OCTETS = 5 * 1024 * 1024;
    private const TYPES_JUSTIFICATIF = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    private ?HeuresTravail $heures = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandeEmployeRepository $demandes,
        private readonly CongeEmployeRepository $conges,
        private readonly Parametres $parametres,
        private readonly NotificationService $notifications,
        private readonly MouvementTresorerieService $tresorerie,
        #[Autowire('%kernel.project_dir%/var/espace-employe')]
        private readonly string $dossier,
    ) {
    }

    public function conge(User $utilisateur): CongeEmploye
    {
        return $this->conges->findOneBy(['utilisateur' => $utilisateur]) ?? new CongeEmploye($utilisateur);
    }

    /** Jours travaillés entre deux dates (incluses), fériés exclus. */
    public function joursOuvres(\DateTimeImmutable $du, \DateTimeImmutable $au, bool $demiJournee = false): float
    {
        $heures = $this->heures ??= $this->parametres->heuresTravail();
        if ($demiJournee) {
            return $heures->estOuvre($du) ? 0.5 : 0.0;
        }
        $jours = 0;
        for ($j = $du; $j <= $au && $jours < 400; $j = $j->modify('+1 day')) {
            $jours += $heures->estOuvre($j) ? 1 : 0;
        }

        return (float) $jours;
    }

    /**
     * Remboursement proposé pour des frais médicaux : taux de la tuile
     * Avantages, dans la limite du plafond annuel (0 = sans plafond).
     *
     * @return array{taux: int, plafond: int, deja: int, propose: int}
     */
    public function remboursement(User $utilisateur, int $montant, \DateTimeImmutable $le): array
    {
        $taux = (int) $this->parametres->valeur('avantages', 'frais_medicaux');
        $plafond = (int) $this->parametres->valeur('avantages', 'plafond_annuel');
        $deja = 0;
        foreach ($this->demandes->findBy(['utilisateur' => $utilisateur, 'type' => DemandeEmploye::FRAIS_MEDICAUX]) as $d) {
            if (\in_array($d->getEtat(), [DemandeEmploye::ACCEPTEE, DemandeEmploye::PAYEE], true) && $d->getDecideLe()?->format('Y') === $le->format('Y')) {
                $deja += (int) $d->getMontantAccorde();
            }
        }
        $propose = (int) round($montant * $taux / 100);
        if ($plafond > 0) {
            $propose = max(0, min($propose, $plafond - $deja));
        }

        return ['taux' => $taux, 'plafond' => $plafond, 'deja' => $deja, 'propose' => $propose];
    }

    /**
     * Dépose une demande. Renvoie la demande ou le message d'erreur.
     *
     * @param array<string, mixed> $saisie
     */
    public function deposer(User $utilisateur, string $type, array $saisie, ?UploadedFile $fichier, \DateTimeImmutable $maintenant): DemandeEmploye|string
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => \is_string($v) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null) : null;
        $du = $date($saisie['du'] ?? null);
        $au = $date($saisie['au'] ?? null) ?? $du;
        $motif = trim((string) ($saisie['motif'] ?? ''));
        $demi = !empty($saisie['demi']);
        $montant = (int) preg_replace('/\D+/', '', (string) ($saisie['montant'] ?? ''));

        if (!isset(DemandeEmploye::TYPES[$type])) {
            return 'Type de demande inconnu.';
        }
        if ($du === null) {
            return 'Indiquez la date.';
        }
        if ($motif === '' && $type !== DemandeEmploye::CONGE) {
            return 'Indiquez le motif.';
        }

        $demande = new DemandeEmploye($utilisateur, $type, $du, $motif !== '' ? $motif : 'Congé', $maintenant);
        switch ($type) {
            case DemandeEmploye::ABSENCE:
            case DemandeEmploye::CONGE:
                if ($au < $du) {
                    return 'La date de fin est avant la date de début.';
                }
                $jours = $this->joursOuvres($du, $demi ? $du : $au, $demi);
                if ($type === DemandeEmploye::CONGE) {
                    $refus = $this->conge($utilisateur)->refus($du, $au, $jours);
                    if ($refus !== null) {
                        return $refus;
                    }
                }
                $demande->periode($demi ? null : $au, $demi, $jours);
                break;

            default:
                if ($montant <= 0) {
                    return 'Indiquez le montant.';
                }
                if ($type === DemandeEmploye::RECLAMATION && !isset(DemandeEmploye::CATEGORIES[(string) ($saisie['categorie'] ?? '')])) {
                    return 'Choisissez ce qui est réclamé.';
                }
                if ($type === DemandeEmploye::FRAIS_MEDICAUX && $fichier === null) {
                    return 'Joignez la facture ou l\'ordonnance.';
                }
                $demande->somme($montant, $type === DemandeEmploye::RECLAMATION ? (string) $saisie['categorie'] : null);
        }

        if ($fichier !== null) {
            $chemin = $this->ranger($fichier, $maintenant);
            if ($chemin === null) {
                return 'Le justificatif doit être une photo (JPG, PNG) ou un PDF de 5 Mo au plus.';
            }
            $demande->setJustificatif($chemin);
        }

        $this->em->persist($demande);
        $this->notifications->notifierRoles(['ROLE_RH', 'ROLE_ADMIN'], \sprintf('%s : %s de %s à traiter.', $demande->libelleType(), $this->resume($demande), Pointages::nom($utilisateur)), 'app_demandes_index');
        $this->em->flush();

        return $demande;
    }

    /**
     * Décision de la RH. Un congé accepté est retiré du solde.
     */
    public function decider(DemandeEmploye $d, bool $accepter, ?int $montant, ?string $commentaire, User $par, \DateTimeImmutable $maintenant): ?string
    {
        if ($d->getEtat() !== DemandeEmploye::EN_ATTENTE) {
            return 'Cette demande a déjà reçu une réponse.';
        }
        if ($accepter && $d->estUneSomme() && ($montant === null || $montant <= 0 || $montant > (int) $d->getMontant())) {
            return \sprintf('Montant accordé entre 1 et %s F.', number_format((int) $d->getMontant(), 0, ',', ' '));
        }
        if (!$accepter && ($commentaire === null || trim($commentaire) === '')) {
            return 'Expliquez le refus : l\'employé verra le commentaire.';
        }
        if ($accepter && $d->getType() === DemandeEmploye::CONGE) {
            $conge = $this->conge($d->getUtilisateur());
            if ((float) $d->getJours() > $conge->getSolde()) {
                return 'Le solde de congés ne suffit plus pour cette période.';
            }
            $conge->debiter((float) $d->getJours());
        }

        $d->decider($accepter, $d->estUneSomme() ? $montant : null, $commentaire, $par, $maintenant);
        $this->notifications->notifierUtilisateur($d->getUtilisateur(), \sprintf('Demande « %s » %s : %s%s', $d->libelleType(), mb_strtolower($d->libelleEtat()), $this->resume($d), $d->getCommentaire() ? ' · '.$d->getCommentaire() : ''), 'app_mon_espace_index');
        $this->tracer($d, $par, ['decision' => $d->getEtat(), 'montantAccorde' => $d->getMontantAccorde(), 'commentaire' => $d->getCommentaire()]);
        $this->em->flush();

        return null;
    }

    /**
     * Paie une somme acceptée : décaissement en trésorerie, confidentiel.
     */
    public function payer(DemandeEmploye $d, CompteTresorerie $compte, User $par): ?string
    {
        if (!$d->estAPayer()) {
            return 'Cette demande n\'est pas à payer.';
        }
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_SALAIRE)
            ->setCompteSource($compte)
            ->setMontant((int) $d->getMontantAccorde())
            ->setLibelle(\sprintf('%s · %s', $d->getType() === DemandeEmploye::FRAIS_MEDICAUX ? 'Frais médicaux' : ($d->libelleCategorie() ?? 'Réclamation'), Pointages::nom($d->getUtilisateur())))
            ->setDescription($d->getMotif())
            ->setConfidentiel(true);
        try {
            $this->tresorerie->enregistrer($mouvement);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $d->payer($mouvement);
        $this->notifications->notifierUtilisateur($d->getUtilisateur(), \sprintf('Demande « %s » payée : %s F.', $d->libelleType(), number_format((int) $d->getMontantAccorde(), 0, ',', ' ')), 'app_mon_espace_index');
        $this->tracer($d, $par, ['payee' => $mouvement->getReference()]);
        $this->em->flush();

        return null;
    }

    public function cheminJustificatif(DemandeEmploye $d): ?string
    {
        $nom = $d->getJustificatif();
        if ($nom === null || !preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp|pdf)$#', $nom)) {
            return null;
        }
        $chemin = $this->dossier.'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    /** « 2 jours du 14/10 », « 25 000 F ». */
    public function resume(DemandeEmploye $d): string
    {
        if ($d->estUneSomme()) {
            return number_format((int) $d->getMontant(), 0, ',', ' ').' F';
        }
        $jours = CongeEmploye::nombre((float) $d->getJours());

        return $d->getAu() !== null && $d->getAu() != $d->getDu()
            ? \sprintf('%s j du %s au %s', $jours, $d->getDu()->format('d/m'), $d->getAu()->format('d/m'))
            : \sprintf('%s j le %s', $jours, $d->getDu()->format('d/m'));
    }

    private function ranger(UploadedFile $fichier, \DateTimeImmutable $maintenant): ?string
    {
        $type = $fichier->getMimeType();
        if (!$fichier->isValid() || $fichier->getSize() > self::JUSTIFICATIF_MAX_OCTETS || !isset(self::TYPES_JUSTIFICATIF[$type])) {
            return null;
        }
        $relatif = $maintenant->format('Y/m').'/'.bin2hex(random_bytes(16)).'.'.self::TYPES_JUSTIFICATIF[$type];
        $fichier->move(\dirname($this->dossier.'/'.$relatif), basename($relatif));

        return $relatif;
    }

    /**
     * @param array<string, mixed> $apres
     */
    private function tracer(DemandeEmploye $d, User $par, array $apres): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite('DemandeEmploye')
            ->setEntiteId((int) $d->getId())
            ->setAction(JournalActivite::ACTION_MODIFICATION)
            ->setDonneesAvant(['type' => $d->getType(), 'montant' => $d->getMontant(), 'jours' => $d->getJours()])
            ->setDonneesApres($apres)
            ->setUtilisateur($par));
    }
}
