<?php

namespace App\Service\Equipe;

use App\Entity\AppareilPointage;
use App\Entity\JournalActivite;
use App\Entity\User;
use App\Repository\AppareilPointageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Accès des employés à l'application (maquettes E02, E04 et E05).
 *
 * Un accès = un employé = un téléphone enregistré. L'accès s'ouvre par
 * un lien envoyé sur WhatsApp, valable 7 jours : la personne choisit un
 * code PIN à 4 chiffres et son téléphone est enregistré, pour se
 * connecter et pour pointer. Seule l'empreinte du lien est gardée.
 * Retirer un accès ne supprime ni le dossier ni l'historique.
 */
final class AccesApplication
{
    public const VALIDITE_INVITATION = '+7 days';

    /** Statuts affichés (maquette E04). */
    public const STATUTS = [
        'actif' => 'Actif',
        'invite' => 'Invitation envoyée',
        'expire' => 'Invitation expirée',
        'suspendu' => 'Suspendu',
        'sans' => 'Sans accès',
        'retire' => 'Accès retiré',
    ];

    /** Codes trop faciles à deviner. */
    private const PIN_INTERDITS = ['1234', '4321', '0123', '3210', '1212', '2580', '0852', '1010', '2000', '1122'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AppareilPointageRepository $appareils,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function statut(User $utilisateur, \DateTimeImmutable $maintenant): string
    {
        $fin = $utilisateur->getFinAccesLe();
        if (!$utilisateur->isActif() || ($fin !== null && $fin <= $maintenant->setTime(0, 0) && $utilisateur->getAcces() !== User::ACCES_SANS)) {
            return 'retire';
        }
        if ($utilisateur->getAcces() === User::ACCES_INVITE && $utilisateur->getInvitationExpireLe() !== null && $utilisateur->getInvitationExpireLe() < $maintenant) {
            return 'expire';
        }

        return $utilisateur->getAcces();
    }

    /** Message d'erreur, ou null si le code convient. */
    public static function controlePin(string $pin, string $confirmation): ?string
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            return 'Le code PIN fait 4 chiffres.';
        }
        if ($pin !== $confirmation) {
            return 'Les deux codes ne sont pas identiques.';
        }
        if (\count(array_unique(str_split($pin))) === 1 || \in_array($pin, self::PIN_INTERDITS, true)) {
            return 'Ce code est trop facile à deviner. Choisissez-en un autre.';
        }

        return null;
    }

    /**
     * Prépare le lien d'invitation (ou le renvoie : l'ancien lien ne
     * marche plus). Rend le jeton à mettre dans le lien WhatsApp.
     */
    public function inviter(User $utilisateur, ?User $par, \DateTimeImmutable $maintenant): string
    {
        $jeton = bin2hex(random_bytes(24));
        $avant = $this->photo($utilisateur);
        $utilisateur->inviter(self::empreinte($jeton), $maintenant, $maintenant->modify(self::VALIDITE_INVITATION));
        $this->tracer($utilisateur, $par, 'acces_invitation', $avant);
        $this->em->flush();

        return $jeton;
    }

    /** Employé invité par ce lien, s'il est encore valable. */
    public function invitationValable(string $jeton, \DateTimeImmutable $maintenant): ?User
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $jeton)) {
            return null;
        }
        $utilisateur = $this->users->findOneBy(['invitationEmpreinte' => self::empreinte($jeton)]);

        return $utilisateur !== null
            && $utilisateur->isActif()
            && $utilisateur->getAcces() === User::ACCES_INVITE
            && $utilisateur->getInvitationExpireLe() >= $maintenant
            ? $utilisateur : null;
    }

    /**
     * Activation par le lien : le PIN est choisi, ce téléphone est
     * enregistré (il remplace l'ancien).
     *
     * @return array{ok: bool, message?: string, utilisateur?: User}
     */
    public function activer(string $jeton, string $pin, string $confirmation, string $jetonAppareil, ?string $agent, \DateTimeImmutable $maintenant): array
    {
        $utilisateur = $this->invitationValable($jeton, $maintenant);
        if ($utilisateur === null) {
            return ['ok' => false, 'message' => 'Ce lien n\'est plus valable. Demandez un nouveau lien au responsable.'];
        }
        if (($erreur = self::controlePin($pin, $confirmation)) !== null) {
            return ['ok' => false, 'message' => $erreur];
        }

        $avant = $this->photo($utilisateur);
        $utilisateur->setPin($this->hashers->getPasswordHasher(User::class)->hash($pin));
        $utilisateur->setAcces(User::ACCES_ACTIF);
        $utilisateur->oublierInvitation();
        $utilisateur->setMustChangePassword(false);

        foreach ($this->appareils->findBy(['utilisateur' => $utilisateur]) as $ancien) {
            if ($ancien->estAutorise()) {
                $ancien->decider(false, null);
            }
        }
        $appareil = new AppareilPointage($utilisateur, $jetonAppareil, AppareilPointage::nomDepuis($agent));
        $appareil->decider(true, null);
        $this->em->persist($appareil);

        $this->tracer($utilisateur, $utilisateur, 'acces_active', $avant);
        $this->em->flush();

        return ['ok' => true, 'utilisateur' => $utilisateur];
    }

    /** Employé dont ce téléphone est enregistré (pour la connexion par PIN). */
    public function proprietaire(?string $jetonAppareil): ?User
    {
        if ($jetonAppareil === null || \strlen($jetonAppareil) < 32) {
            return null;
        }
        $appareil = $this->appareils->findOneBy(['empreinte' => AppareilPointage::empreinte($jetonAppareil)]);

        return $appareil !== null && $appareil->estAutorise() && $appareil->getUtilisateur()->getPin() !== null
            ? $appareil->getUtilisateur() : null;
    }

    /**
     * Connexion par PIN, sur le téléphone enregistré seulement. Cinq
     * erreurs bloquent le PIN 15 minutes.
     *
     * @return array{ok: bool, message?: string, utilisateur?: User}
     */
    public function connecterParPin(?string $jetonAppareil, string $pin, \DateTimeImmutable $maintenant): array
    {
        $utilisateur = $this->proprietaire($jetonAppareil);
        if ($utilisateur === null) {
            return ['ok' => false, 'message' => 'Ce téléphone n\'est pas enregistré. Utilisez le lien reçu sur WhatsApp.'];
        }
        if (!$utilisateur->peutSeConnecter($maintenant)) {
            return ['ok' => false, 'message' => 'Votre accès à l\'application est fermé. Voyez le responsable.'];
        }
        if ($utilisateur->getPinBloqueJusqua() !== null && $utilisateur->getPinBloqueJusqua() > $maintenant) {
            return ['ok' => false, 'message' => 'Trop d\'essais. Réessayez après '.$utilisateur->getPinBloqueJusqua()->format('H:i').'.'];
        }
        if (!$this->hashers->getPasswordHasher(User::class)->verify((string) $utilisateur->getPin(), $pin)) {
            $utilisateur->pinRate($maintenant);
            $this->em->flush();

            return ['ok' => false, 'message' => $utilisateur->getPinBloqueJusqua() !== null
                ? 'Code faux 5 fois : le PIN est bloqué 15 minutes.'
                : 'Code faux.'];
        }

        $utilisateur->pinReussi();
        $utilisateur->setLastLoginAt($maintenant);
        $this->em->flush();

        return ['ok' => true, 'utilisateur' => $utilisateur];
    }

    /** Nouveau lien pour choisir un autre PIN (téléphone changé, code oublié). */
    public function reinitialiserPin(User $utilisateur, ?User $par, \DateTimeImmutable $maintenant): string
    {
        $utilisateur->setPin(null);

        return $this->inviter($utilisateur, $par, $maintenant);
    }

    /** Téléphone perdu : bloque tout de suite. */
    public function suspendre(User $utilisateur, ?User $par): void
    {
        $this->changer($utilisateur, $par, 'acces_suspendu', User::ACCES_SUSPENDU);
    }

    public function reactiver(User $utilisateur, ?User $par): void
    {
        $this->changer($utilisateur, $par, 'acces_reactive', User::ACCES_ACTIF);
    }

    /** L'employé continue sans l'application (tablette de l'entrée). */
    public function retirer(User $utilisateur, ?User $par): void
    {
        $utilisateur->setPin(null);
        $utilisateur->oublierInvitation();
        foreach ($this->appareils->findBy(['utilisateur' => $utilisateur]) as $appareil) {
            if ($appareil->estAutorise()) {
                $appareil->decider(false, $par);
            }
        }
        $this->changer($utilisateur, $par, 'acces_retire', User::ACCES_SANS);
    }

    public function annulerInvitation(User $utilisateur, ?User $par): void
    {
        $utilisateur->oublierInvitation();
        $this->changer($utilisateur, $par, 'acces_invitation_annulee', $utilisateur->getPin() !== null ? User::ACCES_SUSPENDU : User::ACCES_SANS);
    }

    /** Téléphone enregistré de l'employé, s'il y en a un. */
    public function appareil(User $utilisateur): ?AppareilPointage
    {
        foreach ($this->appareils->findBy(['utilisateur' => $utilisateur], ['id' => 'DESC']) as $appareil) {
            if ($appareil->estAutorise()) {
                return $appareil;
            }
        }

        return null;
    }

    private function changer(User $utilisateur, ?User $par, string $action, string $acces): void
    {
        $avant = $this->photo($utilisateur);
        $utilisateur->setAcces($acces);
        $this->tracer($utilisateur, $par, $action, $avant);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function photo(User $utilisateur): array
    {
        return ['acces' => $utilisateur->getAcces(), 'pin' => $utilisateur->getPin() !== null];
    }

    /** @param array<string, mixed> $avant */
    private function tracer(User $utilisateur, ?User $par, string $action, array $avant): void
    {
        if ($utilisateur->getId() === null) {
            return;
        }
        $this->em->persist((new JournalActivite())
            ->setEntite('User')
            ->setEntiteId((int) $utilisateur->getId())
            ->setAction($action)
            ->setDonneesAvant($avant)
            ->setDonneesApres($this->photo($utilisateur))
            ->setUtilisateur($par));
    }
}
