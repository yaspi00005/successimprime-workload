<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Commandes;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\ControleCreditClientService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fenêtre bloquante « Plafond de crédit dépassé » (maquette NT02) : à la
 * confirmation d'une commande d'un client qui dépasse son plafond, la
 * personne demande un acompte ou l'accord de la direction. L'accord est
 * une notification « Urgent » à la direction (bouton « Décider ») ; la
 * décision la résout (accord ou refus) pour tout le monde et prévient qui
 * l'a demandée. L'accord vaut pour cette commande seulement
 * (ControleCreditClientService::accordee).
 */
final class AccordsPlafond
{
    public function __construct(
        private readonly ControleCreditClientService $credit,
        private readonly NotificationsEquipe $equipe,
        private readonly NotificationRepository $depot,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> l'analyse du plafond (accord compris) */
    public function analyser(Commandes $commande): array
    {
        return $this->credit->analyser($commande);
    }

    public function bloquee(Commandes $commande): bool
    {
        return null !== $commande->getClients() && true !== $this->credit->analyser($commande)['autorise'];
    }

    public function demandeEnCours(Commandes $commande): bool
    {
        foreach ($this->depot->parEmpreinte(self::empreinte($commande)) as $n) {
            if (!$n->estResolue()) {
                return true;
            }
        }

        return false;
    }

    /** Demande l'accord à la direction. N'appelle pas flush(). */
    public function demander(Commandes $commande, User $par, \DateTimeImmutable $maintenant): void
    {
        $analyse = $this->credit->analyser($commande);
        $this->equipe->notifier(
            type: 'plafond_credit',
            titre: 'Accord demandé : '.($commande->getClients()?->getNomComplet() ?? 'client').' dépasse son plafond',
            destinataires: ['ROLE_ADMIN'],
            detail: \sprintf('%s · demandé par %s', $commande->getNumero(), self::nom($par)),
            action: 'Décider',
            route: 'app_plafond_decider',
            parametres: ['id' => $commande->getId(), 'par' => $par->getId()],
            empreinte: self::empreinte($commande),
            montant: (int) ($analyse['encoursAnterieur'] ?? 0) + (int) ($analyse['totalCommande'] ?? 0),
            droitMontant: 'ROLE_PAIEMENT_VOIR',
            maintenant: $maintenant,
        );
    }

    /** La direction décide (accord ou refus). N'appelle pas flush(). */
    public function decider(Commandes $commande, bool $accord, User $par, ?User $demandeur, \DateTimeImmutable $maintenant): void
    {
        $empreinte = self::empreinte($commande);
        $resolues = $this->equipe->resoudre($empreinte, $accord ? Notification::RESOLUE_ACCORD : Notification::RESOLUE_REFUS, $maintenant);
        if (0 === $resolues) {
            // Accord donné tout de suite par la direction : une trace résolue, qui vaut accord.
            $trace = (new Notification())
                ->setDestinataire($par)->setType('plafond_credit')->setNiveau(Notification::URGENT)
                ->setMessage('Plafond dépassé : '.($accord ? 'accord donné' : 'refusé').' pour '.$commande->getNumero())
                ->setDetail($commande->getClients()?->getNomComplet())
                ->setEmpreinte($empreinte)->setSilencieuse(true)
                ->resoudre($accord ? Notification::RESOLUE_ACCORD : Notification::RESOLUE_REFUS, $maintenant);
            $this->depotPersist($trace);
        }
        if (null !== $demandeur && $demandeur !== $par) {
            $this->notifications->notifierUtilisateur($demandeur, $accord
                ? \sprintf('Accord de la direction pour %s (%s) : vous pouvez confirmer la commande.', $commande->getNumero(), $commande->getClients()?->getNomComplet())
                : \sprintf('La direction refuse de dépasser le plafond pour %s : demandez un acompte au client.', $commande->getNumero()), 'app_commandes_show', ['id' => $commande->getId()]);
        }
    }

    /**
     * Texte de la fenêtre : les chiffres seulement pour qui voit les
     * paiements (comme la jauge de la fiche client).
     *
     * @param array<string, mixed> $analyse
     *
     * @return array{phrase: string, chiffres: list<array{0: string, 1: int}>}
     */
    public static function texte(array $analyse, string $client, bool $montantsVisibles): array
    {
        if (!$montantsVisibles) {
            return ['phrase' => $client.' dépasse son plafond de crédit. Demandez un acompte ou l\'accord de la direction.', 'chiffres' => []];
        }
        $f = static fn (int $m): string => number_format($m, 0, ',', "\u{202F}").' F';
        $encours = (int) ($analyse['encoursAnterieur'] ?? 0);
        $commande = (int) ($analyse['totalCommande'] ?? 0);
        $plafond = (int) ($analyse['plafondCredit'] ?? 0);

        return [
            'phrase' => \sprintf('%s : %s + %s > %s. Demandez un acompte ou l\'accord de la direction.', $client, $f($encours), $f($commande), $f($plafond)),
            'chiffres' => [['Déjà dû (commandes précédentes)', $encours], ['Cette commande', $commande], ['Plafond de crédit', $plafond]],
        ];
    }

    public static function empreinte(Commandes $commande): string
    {
        return ControleCreditClientService::EMPREINTE_ACCORD.$commande->getId();
    }

    private function depotPersist(Notification $n): void
    {
        $this->em->persist($n);
    }

    private static function nom(User $u): string
    {
        $e = $u->getEmploye();

        return trim(($e?->getPrenom() ?? '').' '.($e?->getNom() ?? '')) ?: (string) $u->getUsername();
    }
}
