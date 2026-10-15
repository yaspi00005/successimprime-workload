<?php

namespace App\Security;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Repository\AccesEspaceClientRepository;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\NotificationsClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Connexion de l'espace client (CX01) hors mot de passe : le code de la
 * première connexion et du mot de passe oublié (par e-mail, par SMS sans
 * adresse), le code d'accès donné par la boutique sur WhatsApp,
 * l'ouverture du compte une fois le code juste, et les étapes à finir
 * avant d'entrer (e-mail, mot de passe).
 */
final class EspaceClientComptes
{
    public const SESSION_CODE = 'espace_code';

    /** Comment la personne vient d'entrer : « code » (e-mail ou SMS) ou « acces » (code de la boutique). */
    public const SESSION_A_FINIR = 'espace_a_finir';
    public const PAR_CODE = 'code';
    public const PAR_ACCES = 'acces';

    public const REQUIS = 'requis';
    public const PROPOSE = 'propose';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EntityManagerInterface $em,
        private readonly CodeDeConnexion $codes,
        private readonly AccesEspaceClientRepository $acces,
    ) {
    }

    /** Le numéro a-t-il déjà un compte ou une fiche client ? */
    public function estConnu(string $telephone): bool
    {
        return null !== $this->comptes->parTelephone($telephone) || null !== $this->comptes->clientDuTelephone($telephone);
    }

    /**
     * Où part le code demandé pour ce numéro :
     *  - un compte existe : l'e-mail de la personne, sinon (titulaire) celui
     *    de la fiche, sinon par SMS (CompteClient::emailDesCodes) ;
     *  - une fiche client a ce numéro : l'e-mail de la fiche, sinon par SMS ;
     *  - « Créer un compte » pour un numéro inconnu : l'e-mail saisi, sinon par SMS ;
     *  - numéro inconnu sans création, ou compte fermé : rien ne part.
     *
     * L'e-mail tapé dans « Créer un compte » ne sert jamais pour un numéro
     * déjà connu : avec seulement le numéro de quelqu'un, on ne reçoit pas
     * son code.
     */
    public function destination(string $telephone, bool $creation = false, ?string $emailSaisi = null): DestinationCode
    {
        $compte = $this->comptes->parTelephone($telephone);
        if (null !== $compte) {
            if (!$compte->isActif() || null === $compte->getClient()) {
                return DestinationCode::inconnue($telephone);
            }

            return NotificationsClient::estDemo($compte->getClient())
                ? DestinationCode::demonstration($telephone)
                : DestinationCode::vers($telephone, $compte->emailDesCodes());
        }

        $client = $this->comptes->clientDuTelephone($telephone);
        if (null !== $client) {
            return NotificationsClient::estDemo($client)
                ? DestinationCode::demonstration($telephone)
                : DestinationCode::vers($telephone, $client->getEmail());
        }

        return $creation ? DestinationCode::vers($telephone, $emailSaisi) : DestinationCode::inconnue($telephone);
    }

    /**
     * Envoie le code. Pour un numéro inconnu sans demande de création,
     * rien ne part (pas de message payé pour rien) mais la réponse est la
     * même, pour ne pas dire à n'importe qui qui est client.
     *
     * @param array{nom?: string, prenom?: string, email?: ?string} $creation
     *
     * @return array{envoye: bool, canal: ?string, destination: string, erreur: ?string, codeDev: ?string, limite: bool}
     */
    public function envoyerCode(SessionInterface $session, string $telephone, string $ip, array $creation = []): array
    {
        $destination = $this->destination($telephone, [] !== $creation, $creation['email'] ?? null);
        $donnees = ['telephone' => $telephone] + $creation;
        unset($donnees['email']);
        // Nouveau client : son e-mail ira sur sa nouvelle fiche, seulement s'il y a reçu le code.
        $nouveau = [] !== $creation && null !== $destination->email && !$this->estConnu($telephone);
        if ($nouveau) {
            $donnees['email'] = $destination->email;
        }

        $envoi = $this->codes->envoyer($session, self::SESSION_CODE, $destination, CodeDeConnexion::ESPACE, $ip, $donnees);
        if ($nouveau && CodeDeConnexion::EMAIL !== $envoi['canal']) {
            $this->codes->oublier($session, self::SESSION_CODE, 'email');
        }

        return $envoi;
    }

    /**
     * Compte du numéro prouvé par le code : existant, ou créé à partir de
     * la fiche client du même numéro, ou d'une nouvelle fiche si la
     * personne a rempli « Créer un compte ». null si rien de tout ça.
     *
     * @param array{telephone: string, nom?: string, prenom?: string, email?: string} $donnees
     */
    public function ouvrir(array $donnees): ?CompteClient
    {
        $telephone = $donnees['telephone'];
        $compte = $this->comptes->parTelephone($telephone);
        if (null !== $compte) {
            return $compte->isActif() ? $compte : null;
        }

        $client = $this->comptes->clientDuTelephone($telephone);
        if (null === $client && isset($donnees['nom'])) {
            $client = $this->creerClient($telephone, $donnees['nom'], $donnees['prenom'] ?? '', $donnees['email'] ?? null);
        }
        if (null === $client) {
            return null;
        }

        return $this->ouvrirPour($client, $telephone);
    }

    /**
     * Première connexion avec le code d'accès donné par la boutique sur
     * WhatsApp : le compte du numéro (créé sur la fiche s'il n'existe pas),
     * et le code est consommé. null si le code est faux, expiré, déjà servi,
     * remplacé par un plus récent, ou si le numéro n'est plus celui de la
     * fiche : une seule réponse pour tous ces cas.
     */
    public function ouvrirParAcces(string $telephone, string $code, \DateTimeImmutable $maintenant): ?CompteClient
    {
        $numero = CompteClient::normaliserTelephone($telephone);
        if (null === $numero || \strlen(AccesEspaceClient::normaliserCode($code)) !== AccesEspaceClient::LONGUEUR) {
            return null;
        }
        $empreinte = AccesEspaceClient::empreinteDe($numero, $code);
        $acces = $this->acces->parEmpreinte($empreinte);
        $client = $acces?->getClient();
        if (null === $acces || null === $client || !$acces->valide($empreinte, $maintenant)
            || NotificationsClient::estDemo($client) || CompteClient::normaliserTelephone((string) $client->getTelephone()) !== $numero) {
            return null;
        }

        $compte = $this->comptes->parTelephone($numero);
        if (null !== $compte && ($compte->getClient()?->getId() !== $client->getId() || !$compte->isActif())) {
            // Numéro déjà rattaché à une autre fiche, ou compte fermé : c'est à la boutique de voir.
            return null;
        }
        if (!$this->acces->consommer($acces, $maintenant)) {
            return null;
        }
        if (null === $compte) {
            return $this->ouvrirPour($client, $numero);
        }
        $this->em->flush();

        return $compte;
    }

    /** Nouveau compte pour ce numéro, sur cette fiche. */
    public function ouvrirPour(Clients $client, string $telephone): CompteClient
    {
        $compte = (new CompteClient())->setClient($client)->setTelephone($telephone);
        $this->em->persist($compte);
        $this->em->flush();

        return $compte;
    }

    /**
     * Ce qu'il reste à faire avant d'entrer (page « Sécuriser mon compte ») :
     *  - mot de passe : requis sans mot de passe et après un code d'accès
     *    de la boutique (mot de passe oublié passé par la boutique) ;
     *    proposé après un code par e-mail ou SMS (mot de passe oublié) ;
     *  - e-mail : proposé à qui n'en a pas encore quand il entre par un code
     *    (de la boutique aussi) ou sans mot de passe, jamais obligatoire : un
     *    client sans e-mail repasse par la boutique ou par SMS (Yaya, 15/10).
     *
     * @param ?string $par self::PAR_CODE, self::PAR_ACCES ou null (mot de passe, « rester connecté »)
     *
     * @return array{motDePasse: ?string, email: ?string, bloquant: bool}
     */
    public static function etapes(CompteClient $compte, ?string $par): array
    {
        $motDePasse = match (true) {
            !$compte->aUnMotDePasse(), self::PAR_ACCES === $par => self::REQUIS,
            self::PAR_CODE === $par => self::PROPOSE,
            default => null,
        };
        $email = match (true) {
            (null !== $par || !$compte->aUnMotDePasse()) && null === $compte->emailDesCodes() => self::PROPOSE,
            default => null,
        };

        return ['motDePasse' => $motDePasse, 'email' => $email, 'bloquant' => self::REQUIS === $motDePasse || self::REQUIS === $email];
    }

    /**
     * Enregistre l'e-mail donné par la personne. Le titulaire d'une fiche
     * sans e-mail le propose aussi à la fiche : il monte au local, qui ne le
     * prend que si sa fiche n'en a toujours pas.
     */
    public function noterEmail(CompteClient $compte, string $email): void
    {
        $compte->setEmail($email);
        $client = $compte->getClient();
        if (null !== $client && $compte->estTitulaire() && '' === trim((string) $client->getEmail()) && !NotificationsClient::estDemo($client)) {
            $client->setEmail($email);
        }
    }

    private function creerClient(string $telephone, string $nom, string $prenom, ?string $email): Clients
    {
        $client = (new Clients())
            ->setCode('TMP-'.bin2hex(random_bytes(12)))
            ->setNom(mb_substr($nom, 0, 50))
            ->setPrenom(mb_substr($prenom, 0, 50))
            ->setTelephone(substr((string) CompteClient::normaliserTelephone($telephone), 3))
            ->setEmail(DestinationCode::emailValable($email))
            ->setObservation('Compte créé par le client depuis l\'espace client.')
            ->setStatut(true);
        $this->em->persist($client);
        $this->em->flush();
        $client->genererCodeDepuisId();

        return $client;
    }
}
