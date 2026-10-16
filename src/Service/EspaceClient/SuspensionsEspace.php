<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\ContactClient;
use App\Entity\ModificationClient;
use App\Entity\SuspensionEspace;
use App\Entity\User;
use App\Repository\SuspensionEspaceRepository;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Suspendre ou rétablir l'accès à l'espace client (demande de Yaya du
 * 15/10/2026).
 *
 * À l'atelier (fiche client, droit Clients) : suspendre tout le compte ou
 * une personne, avec un motif ; rétablir. Chaque geste est noté au journal
 * de la fiche (qui, quand, pourquoi). Rien n'est effacé.
 *
 * Sur le site (la suspension y descend par la synchronisation) : une
 * personne suspendue ne se connecte plus (EspaceClientChecker), sa session
 * ouverte tombe à la requête suivante (EspaceClientSubscriber), aucun code
 * de connexion ne lui part (EspaceClientComptes) et elle ne reçoit ni
 * notification, ni SMS, ni e-mail de l'espace client (NotificationsClient,
 * CompteEntreprise, DemandesConception).
 */
final class SuspensionsEspace implements ResetInterface
{
    /** Ce que lit la personne suspendue, et rien de plus (le motif reste à l'atelier). */
    public const MESSAGE = 'Votre accès à l\'espace client est suspendu. Contactez Succès Imprim.';

    public const FERME_EN_LIGNE = 'L\'accès à l\'espace client se suspend et se rétablit depuis l\'ERP de la boutique.';

    public const MOTIF_MIN = 3;

    /** @var array<string, bool> réponse déjà cherchée pendant cette requête, par fiche et numéro */
    private array $deja = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuspensionEspaceRepository $suspensions,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** Entre deux requêtes d'un même processus (synchronisation en boucle, messages) : rien de gardé. */
    public function reset(): void
    {
        $this->deja = [];
    }

    /* ---------- Sur le site : qui est suspendu ---------- */

    /** Ce compte (cette personne) est-il suspendu, lui ou tout le compte de sa fiche ? */
    public function compteSuspendu(CompteClient $compte): bool
    {
        $client = $compte->getClient();

        return null !== $client && $this->numeroSuspendu($client, $compte->getTelephone());
    }

    /** Ce numéro de connexion est-il suspendu sur cette fiche ? */
    public function numeroSuspendu(Clients $client, string $telephone): bool
    {
        $numero = CompteClient::normaliserTelephone($telephone);
        if (null === $numero || null === $client->getId()) {
            return false;
        }

        return $this->deja[$client->getId().'|'.$numero] ??= null !== $this->suspensions->pour($client, $numero);
    }

    /**
     * Les comptes qui ne sont pas suspendus (destinataires d'un SMS, d'une
     * notification ou d'un e-mail de l'espace client).
     *
     * @template T of CompteClient
     *
     * @param list<T> $comptes
     *
     * @return list<T>
     */
    public function sansSuspendus(array $comptes): array
    {
        return array_values(array_filter($comptes, fn (CompteClient $c): bool => !$this->compteSuspendu($c)));
    }

    /* ---------- À l'atelier : suspendre, rétablir ---------- */

    /** Pourquoi on ne peut pas suspendre ici, ou null. */
    public function empechement(): ?string
    {
        return $this->enLigne ? self::FERME_EN_LIGNE : null;
    }

    /** @return list<SuspensionEspace> */
    public function actives(Clients $client): array
    {
        return $this->suspensions->actives($client);
    }

    /** @return list<SuspensionEspace> */
    public function historique(Clients $client): array
    {
        return $this->suspensions->historique($client);
    }

    /**
     * Personnes de la fiche qu'on peut suspendre une à une : le numéro de
     * la fiche (titulaire), le second numéro, puis les contacts qui ont un
     * numéro. Un autre numéro (personne ajoutée en ligne par l'entreprise)
     * se tape à la main.
     *
     * @param list<ContactClient> $contacts
     *
     * @return list<array{telephone: string, nom: string, libelle: string}>
     */
    public static function personnes(Clients $client, array $contacts): array
    {
        $personnes = [];
        $ajouter = static function (?string $numero, string $nom, string $precision) use (&$personnes): void {
            $normalise = CompteClient::normaliserTelephone((string) $numero);
            if (null === $normalise || isset($personnes[$normalise])) {
                return;
            }
            $personnes[$normalise] = [
                'telephone' => $normalise,
                'nom' => $nom,
                'libelle' => trim($nom.('' !== $precision ? ' · '.$precision : '')).' · '.implode(' ', str_split(substr($normalise, 3), 2)),
            ];
        };
        $principal = null;
        foreach ($contacts as $k) {
            if ('principal' === $k->getRole() && null !== $k->getTelephone()
                && ContactClient::chiffres($k->getTelephone()) === ContactClient::chiffres((string) $client->getTelephone())) {
                $principal = $k;
            }
        }
        $ajouter($client->getTelephone(), $principal?->getNom() ?? (trim((string) $client->getPrenom().' '.$client->getNom()) ?: (string) $client->getNomComplet()), 'numéro de la fiche');
        foreach ($contacts as $k) {
            $ajouter($k->getTelephone(), $k->getNom(), (string) $k->getFonction());
        }
        $ajouter($client->getTelephone2(), 'Second numéro de la fiche', '');

        return array_values($personnes);
    }

    /**
     * Suspend tout le compte ($telephone null) ou une personne. Noté au
     * journal de la fiche ; descend au site à la prochaine synchronisation.
     *
     * @throws \DomainException message pour l'écran
     */
    public function suspendre(Clients $client, ?string $telephone, ?string $nom, string $motif, ?User $par, \DateTimeImmutable $le): SuspensionEspace
    {
        if (null !== $raison = $this->empechement()) {
            throw new \DomainException($raison);
        }
        $suspension = (new SuspensionEspace())->setClient($client)->setMotif($motif)->setNom($nom)->setSuspenduLe($le)->setSuspenduPar($par);
        if (mb_strlen($suspension->getMotif()) < self::MOTIF_MIN) {
            throw new \DomainException('Indiquez le motif de la suspension : il reste à l\'atelier, le client ne le voit pas.');
        }
        try {
            $suspension->setTelephone($telephone);
        } catch (\InvalidArgumentException) {
            throw new \DomainException('Numéro de la personne invalide : 8 chiffres, numéro malien.');
        }
        foreach ($this->suspensions->actives($client) as $deja) {
            if ($deja->estToutLeCompte()) {
                throw new \DomainException('Tout le compte est déjà suspendu.');
            }
            if ($deja->getTelephone() === $suspension->getTelephone()) {
                throw new \DomainException('L\'accès de cette personne est déjà suspendu.');
            }
        }

        $this->em->persist($suspension);
        $this->em->persist(new ModificationClient(
            $client,
            'Accès à l\'espace client',
            'contacts',
            'Ouvert',
            mb_substr('Suspendu, '.self::qui($suspension).' : '.$suspension->getMotif(), 0, 255),
            $le,
            $par,
            'erp_local',
            null !== $par ? Pointages::nom($par) : null,
        ));
        $this->em->flush();
        $this->deja = [];

        return $suspension;
    }

    /**
     * Rétablit l'accès : la suspension est levée (jamais effacée), noté
     * au journal de la fiche.
     *
     * @throws \DomainException
     */
    public function retablir(SuspensionEspace $suspension, ?User $par, ?string $note, \DateTimeImmutable $le): void
    {
        if (null !== $raison = $this->empechement()) {
            throw new \DomainException($raison);
        }
        if (!$suspension->estActive()) {
            throw new \DomainException('Cet accès est déjà rétabli.');
        }
        $suspension->lever($le, $par, $note);
        $client = $suspension->getClient() ?? throw new \DomainException('Suspension sans fiche client.');
        $this->em->persist(new ModificationClient(
            $client,
            'Accès à l\'espace client',
            'contacts',
            mb_substr('Suspendu, '.self::qui($suspension), 0, 255),
            mb_substr('Rétabli'.(null !== $suspension->getNoteLevee() ? ' : '.$suspension->getNoteLevee() : ''), 0, 255),
            $le,
            $par,
            'erp_local',
            null !== $par ? Pointages::nom($par) : null,
        ));
        $this->em->flush();
        $this->deja = [];
    }

    /** « tout le compte » ou « Mariam Dembélé (60 44 51 03) ». */
    public static function qui(SuspensionEspace $s): string
    {
        if ($s->estToutLeCompte()) {
            return 'tout le compte';
        }

        return null !== $s->getNom() ? \sprintf('%s (%s)', $s->getNom(), $s->getTelephoneAffiche()) : 'le '.$s->getTelephoneAffiche();
    }
}
