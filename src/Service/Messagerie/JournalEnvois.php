<?php

namespace App\Service\Messagerie;

use App\Entity\EnvoiMessage;
use App\Entity\SynchroJournal;
use App\Service\Synchro\CatalogueSynchro;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit l'historique des envois (EnvoiMessage) : chaque SMS tenté par
 * OrangeSmsService, chaque e-mail vu par JournalEmails.
 *
 * La ligne est écrite tout de suite, directement en base : elle ne dépend
 * pas d'un enregistrement que l'appelant ferait (ou pas) plus tard, et
 * n'enregistre rien d'autre en passant. Sur le site en ligne, la file de
 * synchronisation reçoit la ligne dans la même transaction, pour qu'elle
 * monte au local.
 *
 * Jamais le texte du message. L'erreur et l'objet sont nettoyés : numéros
 * et adresses masqués, montants retirés. Une panne de l'historique
 * n'empêche jamais un envoi.
 */
final class JournalEnvois
{
    /**
     * En-tête posé par l'appelant sur un e-mail pour dire sa sorte
     * (code_connexion, campagne…) ; JournalEmails le retire avant l'envoi.
     */
    public const ENTETE_TYPE = 'X-Succes-Imprim-Type';

    public const TYPE_SYNCHRO = 'envoi_message';

    public function __construct(
        private readonly Connection $connexion,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** Donne sa sorte à un e-mail avant de l'envoyer. */
    public static function typer(Email $email, string $type): Email
    {
        $entetes = $email->getHeaders();
        $entetes->remove(self::ENTETE_TYPE);
        $entetes->addTextHeader(self::ENTETE_TYPE, $type);

        return $email;
    }

    public function noter(string $canal, string $type, string $destinataire, string $statut, ?string $erreur = null, int $unites = 0, ?string $sujet = null, ?\DateTimeImmutable $le = null): void
    {
        $envoi = EnvoiMessage::nouveau(
            $canal,
            $type,
            self::normaliser($canal, $destinataire),
            $statut,
            null === $erreur ? null : self::nettoyer($erreur),
            EnvoiMessage::ENVOYE === $statut ? $unites : 0,
            null === $sujet ? null : self::nettoyer($sujet),
            $le ?? new \DateTimeImmutable(),
            $this->enLigne ? EnvoiMessage::EN_LIGNE : EnvoiMessage::LOCAL,
        );

        try {
            $this->connexion->transactional(function (Connection $c) use ($envoi): void {
                $c->insert('envoi_message', [
                    'public_id' => $envoi->getPublicId()->toBinary(),
                    'canal' => $envoi->getCanal(),
                    'type' => $envoi->getType(),
                    'sujet' => $envoi->getSujet(),
                    'destinataire' => $envoi->getDestinataire(),
                    'statut' => $envoi->getStatut(),
                    'erreur' => $envoi->getErreur(),
                    'unites' => $envoi->getUnites(),
                    'envoye_le' => $envoi->getEnvoyeLe()->format('Y-m-d H:i:s'),
                    'origine' => $envoi->getOrigine(),
                ], ['public_id' => ParameterType::BINARY, 'unites' => ParameterType::INTEGER]);

                // Comme JournalSynchro pour une donnée enregistrée par Doctrine : la ligne part au local.
                if (CatalogueSynchro::envoye(self::TYPE_SYNCHRO, $this->enLigne)) {
                    $c->insert('synchro_journal', [
                        'evenement' => Uuid::v7()->toRfc4122(),
                        'type' => self::TYPE_SYNCHRO,
                        'public_id' => $envoi->getPublicId()->toRfc4122(),
                        'operation' => SynchroJournal::MAJ,
                        'cree_le' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'tentatives' => 0,
                        'ignore_manuel' => 0,
                    ], ['tentatives' => ParameterType::INTEGER, 'ignore_manuel' => ParameterType::INTEGER]);
                }
            });
        } catch (\Throwable $e) {
            // Ni destinataire ni message dans le journal du serveur.
            $this->logger->warning('Historique des envois : ligne non écrite ({classe}).', ['classe' => $e::class]);
        }
    }

    /**
     * Numéro en chiffres avec l'indicatif (« 22376000099 »), adresse e-mail
     * en minuscules.
     */
    public static function normaliser(string $canal, string $destinataire): string
    {
        if (EnvoiMessage::EMAIL === $canal) {
            return mb_strtolower(trim($destinataire));
        }
        $chiffres = preg_replace('/\D+/', '', $destinataire) ?? '';
        if (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }

        return 8 === \strlen($chiffres) ? '223'.$chiffres : $chiffres;
    }

    /**
     * Erreur ou objet d'e-mail sans donnée à cacher : adresses et numéros
     * masqués, montants remplacés par « … F » (ou « … » pour un nombre
     * écrit avec des espaces de milliers, comme le rend un modèle de
     * message : « Reste {reste} à payer »).
     */
    public static function nettoyer(string $texte): string
    {
        $texte = (string) preg_replace_callback('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+/u', static fn (array $m): string => EnvoiMessage::masquer(EnvoiMessage::EMAIL, $m[0]), $texte);
        $texte = (string) preg_replace('/\d[\d \x{00A0}\x{202F}.,]*[ \x{00A0}\x{202F}]?(?:F CFA|FCFA|XOF|F)(?![\p{L}\p{N}])/u', '… F', $texte);
        // Numéro de téléphone : 8 chiffres ou plus, avec ou sans « + », espaces, « tel: ».
        $texte = (string) preg_replace_callback('/(?<![\p{L}\p{N}])\+?\d(?:[ .\x{00A0}]?\d){7,14}(?![\p{N}])/u', static fn (array $m): string => EnvoiMessage::masquer(EnvoiMessage::SMS, $m[0]), $texte);
        // Montant sans « F » : « 25 000 », « 1 250 000 » (pas un numéro de commande ni une date).
        $texte = (string) preg_replace('/(?<![\p{L}\p{N}\/-])\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?![\p{N}])/u', '…', $texte);

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }
}
