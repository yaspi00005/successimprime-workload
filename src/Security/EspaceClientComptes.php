<?php

namespace App\Security;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Connexion de l'espace client par code (CX01) : envoi du code et
 * ouverture du compte une fois le numéro prouvé par le code.
 */
final class EspaceClientComptes
{
    public const SESSION_CODE = 'espace_code';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EntityManagerInterface $em,
        private readonly CodeParSms $codes,
    ) {
    }

    /** Le numéro a-t-il déjà un compte ou une fiche client ? */
    public function estConnu(string $telephone): bool
    {
        return null !== $this->comptes->parTelephone($telephone) || null !== $this->comptes->clientDuTelephone($telephone);
    }

    /**
     * Envoie le code. Pour un numéro inconnu sans demande de création,
     * rien ne part (pas de SMS payé pour rien) mais la réponse est la même,
     * pour ne pas dire à n'importe qui qui est client.
     *
     * @param array{nom?: string, prenom?: string} $creation
     *
     * @return array{envoye: bool, telephone: string, erreur: ?string, codeDev: ?string}
     */
    public function envoyerCode(SessionInterface $session, string $telephone, array $creation = []): array
    {
        $donnees = ['telephone' => $telephone] + $creation;
        if ([] === $creation && !$this->estConnu($telephone)) {
            // Code jamais envoyé : la saisie sera toujours fausse.
            $this->codes->envoyer($session, self::SESSION_CODE, '', '%s', $donnees);

            return ['envoye' => true, 'telephone' => CodeParSms::masquer($telephone), 'erreur' => null, 'codeDev' => null];
        }

        return $this->codes->envoyer(
            $session,
            self::SESSION_CODE,
            $telephone,
            'Succès Imprim : votre code de connexion est %s. Valable 5 minutes. Ne le donnez à personne.',
            $donnees,
        );
    }

    /**
     * Compte du numéro prouvé par le code : existant, ou créé à partir de
     * la fiche client du même numéro, ou d'une nouvelle fiche si la
     * personne a rempli « Créer un compte ». null si rien de tout ça.
     *
     * @param array{telephone: string, nom?: string, prenom?: string} $donnees
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
            $client = $this->creerClient($telephone, $donnees['nom'], $donnees['prenom'] ?? '');
        }
        if (null === $client) {
            return null;
        }

        $compte = (new CompteClient())->setClient($client)->setTelephone($telephone);
        $this->em->persist($compte);
        $this->em->flush();

        return $compte;
    }

    private function creerClient(string $telephone, string $nom, string $prenom): Clients
    {
        $client = (new Clients())
            ->setCode('TMP-'.bin2hex(random_bytes(12)))
            ->setNom(mb_substr($nom, 0, 50))
            ->setPrenom(mb_substr($prenom, 0, 50))
            ->setTelephone(substr((string) CompteClient::normaliserTelephone($telephone), 3))
            ->setObservation('Compte créé par le client depuis l\'espace client.')
            ->setStatut(true);
        $this->em->persist($client);
        $this->em->flush();
        $client->genererCodeDepuisId();

        return $client;
    }
}
