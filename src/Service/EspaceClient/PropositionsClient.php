<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\ModificationClient;
use App\Entity\PropositionClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Changements de raison sociale ou de NIF demandés en ligne (Mon compte,
 * maquette EC04), traités au local depuis la fiche client : appliqués
 * (la fiche change, c'est noté dans son journal et la fiche redescend) ou
 * refusés avec un motif que le client lit. Jamais sur le site en ligne,
 * où la fiche du local fait foi.
 */
final class PropositionsClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /** @return list<PropositionClient> les demandes en attente pour ce client */
    public function enAttente(Clients $client): array
    {
        return $this->em->getRepository(PropositionClient::class)->findBy(['client' => $client, 'statut' => PropositionClient::EN_ATTENTE], ['creeLe' => 'ASC']);
    }

    /** @throws \DomainException */
    public function appliquer(PropositionClient $proposition, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->verifier($proposition);
        $client = $proposition->getClient();
        foreach (self::champsValables($proposition) as $champ => [$avant, $apres]) {
            match ($champ) {
                'raisonSociale' => $client->setRaisonSociale($apres),
                'nif' => $client->setNif($apres),
            };
            $this->em->persist(new ModificationClient($client, PropositionClient::CHAMPS[$champ][0], 'identite', $avant, $apres, $maintenant, $par, 'espace_client', $proposition->getAuteur()));
        }
        $proposition->traiter(PropositionClient::APPLIQUEE, $par, null, $maintenant);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function refuser(PropositionClient $proposition, User $par, ?string $motif, \DateTimeImmutable $maintenant): void
    {
        $this->verifier($proposition);
        $proposition->traiter(PropositionClient::REFUSEE, $par, $motif, $maintenant);
        $this->em->flush();
    }

    /**
     * Ce qui peut s'appliquer : champs connus seulement, texte nettoyé et
     * borné, raison sociale jamais vide (la demande vient du site en ligne :
     * elle est relue ici).
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function champsValables(PropositionClient $proposition): array
    {
        $client = $proposition->getClient();
        $valables = [];
        foreach ($proposition->getChamps() as $champ => $valeurs) {
            if (!isset(PropositionClient::CHAMPS[$champ]) || !\is_array($valeurs)) {
                continue;
            }
            $apres = trim((string) preg_replace('/\s+/u', ' ', (string) ($valeurs['apres'] ?? '')));
            $apres = '' === $apres ? null : mb_substr($apres, 0, PropositionClient::CHAMPS[$champ][1]);
            if ('raisonSociale' === $champ && null === $apres) {
                continue;
            }
            $avant = 'raisonSociale' === $champ ? $client?->getRaisonSociale() : $client?->getNif();
            if ($apres !== $avant) {
                $valables[$champ] = [$avant, $apres];
            }
        }

        return $valables;
    }

    private function verifier(PropositionClient $proposition): void
    {
        if ($this->enLigne) {
            throw new \DomainException('Les demandes des clients se traitent depuis l\'ERP de l\'imprimerie.');
        }
        if (!$proposition->estEnAttente()) {
            throw new \DomainException('Cette demande a déjà été traitée.');
        }
    }
}
