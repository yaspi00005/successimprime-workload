<?php

namespace App\Service\EspaceClient;

use App\Controller\UtilisateursController;
use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\ModificationClient;
use App\Entity\User;
use App\Repository\AccesEspaceClientRepository;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Envoyer l'accès à l'espace client » (fiche client, ERP local) : la
 * boutique donne au client un code d'accès à 8 caractères, valable 48 h,
 * qu'elle lui envoie elle-même sur WhatsApp avec son identifiant (son
 * numéro) et le lien de la page « Première connexion ». Le code n'est
 * jamais gardé ni écrit au journal : seule son empreinte descend sur le
 * site par la synchronisation.
 *
 * Pour une entreprise, l'accès va au numéro de la fiche (la personne
 * principale) ; elle invite ensuite les autres depuis Mon compte.
 */
final class AccesEspace
{
    public const SUSPENDU = 'Accès à l\'espace client suspendu pour ce numéro : rétablissez-le avant d\'envoyer un code.';

    public const SANS_NUMERO = 'Ajoutez d\'abord un numéro malien à 8 chiffres sur la fiche : c\'est l\'identifiant du client.';

    /** Consigne de la page « Accès prêt » (boutique) : comme le message, l'adresse e-mail du client n'est pas obligatoire. */
    public const CONSIGNE_BOUTIQUE = 'Ne le donnez qu\'au client, sur son numéro. À sa première connexion, il choisit son mot de passe et ajoute son adresse e-mail s\'il en a une (ce n\'est pas obligatoire). Ensuite il se connecte avec son numéro et ce mot de passe.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccesEspaceClientRepository $acces,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $urlSite,
        private readonly ?SuspensionsEspace $suspensions = null,
    ) {
    }

    /** Pourquoi cette fiche ne peut pas recevoir d'accès, ou null. */
    public function empechement(Clients $client): ?string
    {
        return match (true) {
            $this->enLigne => 'L\'accès à l\'espace client se donne depuis l\'ERP de la boutique.',
            NotificationsClient::estDemo($client) => 'Fiche de démonstration : pas d\'accès à l\'espace client.',
            null === CompteClient::normaliserTelephone((string) $client->getTelephone()) => self::SANS_NUMERO,
            true === $this->suspensions?->numeroSuspendu($client, (string) $client->getTelephone()) => self::SUSPENDU,
            default => null,
        };
    }

    /** Le lien mène au site en ligne seulement si son adresse est réglée (SYNC_URL). */
    public function siteRegle(): bool
    {
        return (bool) preg_match('#^https?://#', $this->urlSite);
    }

    public function dernier(Clients $client): ?AccesEspaceClient
    {
        return $this->acces->duClient($client);
    }

    /**
     * Nouveau code (l'ancien ne sert plus), noté au journal du client sans
     * le code. Rend le message WhatsApp et son lien wa.me.
     *
     * @return array{acces: AccesEspaceClient, message: string, whatsapp: string, lien: string}
     */
    public function donner(Clients $client, ?User $par, \DateTimeImmutable $maintenant): array
    {
        $raison = $this->empechement($client);
        if (null !== $raison) {
            throw new \DomainException($raison);
        }
        $telephone = (string) CompteClient::normaliserTelephone((string) $client->getTelephone());
        $acces = $this->acces->duClient($client);
        $remplace = null !== $acces && $acces->getExpireLe() >= $maintenant && !$acces->estUtilise();
        if (null === $acces) {
            $acces = (new AccesEspaceClient())->setClient($client);
            $this->em->persist($acces);
        }
        $code = $acces->nouveauCode($telephone, $maintenant, $par);

        // Qui a donné l'accès, à qui, quand : jamais le code.
        $this->em->persist(new ModificationClient(
            $client,
            'Accès à l\'espace client',
            'contacts',
            $remplace ? 'Code précédent remplacé' : null,
            \sprintf('Code envoyé par WhatsApp, valable jusqu\'au %s', $acces->getExpireLe()->format('d/m/Y à H:i')),
            $maintenant,
            $par,
            'erp_local',
            null !== $par ? Pointages::nom($par) : null,
        ));
        $this->em->flush();

        $lien = $this->lien($telephone);
        $message = self::message($telephone, $code, $acces->getExpireLe(), $lien);

        return [
            'acces' => $acces,
            'message' => $message,
            'whatsapp' => 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($message),
            'lien' => $lien,
        ];
    }

    /** Page « Première connexion » du site, numéro rempli. Jamais le code dans l'adresse. */
    public function lien(string $telephone): string
    {
        $parametres = ['tel' => substr((string) CompteClient::normaliserTelephone($telephone), 3)];
        if (!$this->enLigne && $this->siteRegle()) {
            return rtrim($this->urlSite, '/').$this->urls->generate('app_espace_premiere_connexion', $parametres);
        }

        return $this->urls->generate('app_espace_premiere_connexion', $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Message WhatsApp : identifiant, code, durée, lien, et ce qui vient ensuite. */
    public static function message(string $telephone, string $code, \DateTimeImmutable $expireLe, string $lien): string
    {
        $local = substr((string) CompteClient::normaliserTelephone($telephone), 3);

        return \sprintf(
            "Bonjour, voici votre accès à l'espace client Succès Imprim.\n\n"
            ."Identifiant (votre numéro) : %s\n"
            ."Code d'accès : %s\n"
            ."Valable %d heures (jusqu'au %s), pour une seule connexion.\n\n"
            ."Première connexion : %s\n"
            ."Vous choisirez ensuite votre mot de passe (et votre adresse e-mail si vous en avez une). Après, vous vous connecterez avec votre numéro et ce mot de passe.\n\n"
            ."Ne donnez ce code à personne.",
            implode(' ', str_split($local, 2)),
            AccesEspaceClient::afficherCode($code),
            AccesEspaceClient::VALIDITE_HEURES,
            $expireLe->format('d/m/Y à H:i'),
            $lien,
        );
    }
}
