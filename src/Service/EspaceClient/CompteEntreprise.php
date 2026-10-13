<?php

namespace App\Service\EspaceClient;

use App\Entity\AdresseLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Comptes d'une entreprise dans l'espace client (maquette 19) : plusieurs
 * personnes sur la même fiche, chacune avec ses droits. Un demandeur
 * commande, mais sa commande attend qu'un valideur de l'entreprise
 * l'accepte avant de partir à l'atelier. Les adresses de livraison et le
 * numéro de bon de commande vivent ici aussi.
 */
final class CompteEntreprise
{
    /** Comptes au plus par entreprise. */
    public const UTILISATEURS_MAX = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompteClientRepository $comptes,
        private readonly OrangeSmsService $sms,
    ) {
    }

    /**
     * Les comptes de la fiche, le titulaire d'abord.
     *
     * @return list<CompteClient>
     */
    public function utilisateurs(Clients $client): array
    {
        return $this->comptes->createQueryBuilder('c')
            ->where('c.client = :cl')->setParameter('cl', $client)
            ->orderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Ajoute une personne au compte de l'entreprise. Elle se connectera
     * avec son propre numéro, par code SMS comme tout le monde.
     *
     * @param list<string> $droits
     *
     * @throws \DomainException message à montrer
     */
    public function ajouter(CompteClient $par, string $telephone, string $nom, array $droits): CompteClient
    {
        $client = $par->getClient() ?? throw new \DomainException('Compte sans fiche client.');
        if (!$par->peut(CompteClient::DROIT_ADMINISTRATEUR)) {
            throw new \DomainException('Seule une personne qui gère le compte peut ajouter quelqu\'un.');
        }
        if (!CompteClient::clientAPlusieursComptes($client)) {
            throw new \DomainException('Les comptes à plusieurs personnes sont réservés aux entreprises.');
        }
        if ('' === trim($nom)) {
            throw new \DomainException('Indiquez le nom de la personne.');
        }
        $normalise = CompteClient::normaliserTelephone($telephone);
        if (null === $normalise) {
            throw new \DomainException('Numéro de téléphone invalide (8 chiffres).');
        }
        if (\count($this->utilisateurs($client)) >= self::UTILISATEURS_MAX) {
            throw new \DomainException('Vous avez atteint le nombre maximum d\'utilisateurs.');
        }
        $existant = $this->comptes->parTelephone($normalise);
        if (null !== $existant) {
            throw new \DomainException($existant->getClient() === $client
                ? 'Ce numéro fait déjà partie du compte.'
                : 'Ce numéro a déjà un compte client. Demandez-nous de le rattacher à votre entreprise.');
        }

        $compte = (new CompteClient())
            ->setClient($client)
            ->setTelephone($normalise)
            ->setNom($nom)
            ->setDroits($droits)
            ->setAjoutePar($par);
        $this->em->persist($compte);
        $this->em->flush();

        $this->sms->envoyerSms($normalise, \sprintf(
            'Succès Imprim : %s vous a ajouté au compte %s. Connectez-vous sur notre espace client avec ce numéro, un code vous sera envoyé.',
            $par->getNomAffiche(),
            $client->getRaisonSociale() ?: $client->getNomComplet(),
        ));

        return $compte;
    }

    /**
     * @param list<string> $droits
     *
     * @throws \DomainException
     */
    public function changerDroits(CompteClient $par, CompteClient $compte, array $droits): void
    {
        $this->verifierMemeEntreprise($par, $compte);
        if ($par === $compte) {
            throw new \DomainException('Vous ne pouvez pas changer vos propres droits. Demandez à une autre personne qui gère le compte.');
        }
        $compte->setDroits($droits);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function retirer(CompteClient $par, CompteClient $compte): void
    {
        $this->verifierMemeEntreprise($par, $compte);
        if ($par === $compte) {
            throw new \DomainException('Vous ne pouvez pas vous retirer vous-même.');
        }
        if (null === $compte->getAjoutePar()) {
            throw new \DomainException('Le compte principal de l\'entreprise ne peut pas être retiré ici. Écrivez-nous.');
        }
        $compte->setActif(false);
        $this->em->flush();
    }

    /* ---------- Validation interne ---------- */

    /** La commande de cette personne doit-elle être validée dans l'entreprise ? */
    public function demandeValidation(CompteClient $compte): bool
    {
        $client = $compte->getClient();

        return null !== $client
            && CompteClient::clientAPlusieursComptes($client)
            && $compte->estDemandeur()
            && [] !== $this->valideurs($client);
    }

    /**
     * Commandes qui attendent la validation de cette personne.
     *
     * @return list<Commandes>
     */
    public function aValider(CompteClient $compte): array
    {
        $client = $compte->getClient();
        if (null === $client || !$compte->peut(CompteClient::DROIT_VALIDEUR) || !CompteClient::clientAPlusieursComptes($client)) {
            return [];
        }

        return $this->em->getRepository(Commandes::class)->createQueryBuilder('c')
            ->where('c.clients = :cl')->andWhere('c.deleted = false')
            ->andWhere('c.validationEntreprise = :etat')
            ->setParameter('cl', $client)->setParameter('etat', Commandes::VALIDATION_A_FAIRE)
            ->orderBy('c.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Accepte la commande : elle part alors à l'atelier comme une
     * commande en ligne ordinaire, à confirmer.
     *
     * @throws \DomainException
     */
    public function valider(CompteClient $par, Commandes $commande, \DateTimeImmutable $maintenant): void
    {
        $this->verifierValideur($par, $commande);
        $commande
            ->setValidationEntreprise(Commandes::VALIDATION_ACCEPTEE)
            ->setValideePar($par->getNomAffiche())
            ->setMotifRefusEntreprise(null)
            ->setDateCommande($maintenant);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function refuser(CompteClient $par, Commandes $commande, ?string $motif): void
    {
        $this->verifierValideur($par, $commande);
        $commande
            ->setValidationEntreprise(Commandes::VALIDATION_REFUSEE)
            ->setValideePar($par->getNomAffiche())
            ->setMotifRefusEntreprise($motif)
            ->setDeleted(true);
        $this->em->flush();
    }

    /* ---------- Adresses de livraison ---------- */

    /** @return list<AdresseLivraison> */
    public function adresses(Clients $client): array
    {
        return $this->em->getRepository(AdresseLivraison::class)->createQueryBuilder('a')
            ->where('a.client = :cl')->andWhere('a.actif = true')->setParameter('cl', $client)
            ->orderBy('a.parDefaut', 'DESC')->addOrderBy('a.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function adresse(Clients $client, int $id): ?AdresseLivraison
    {
        foreach ($this->adresses($client) as $a) {
            if ($a->getId() === $id) {
                return $a;
            }
        }

        return null;
    }

    /**
     * @param array{libelle: string, adresse: string, repere?: ?string, contact?: ?string, telephone?: ?string, parDefaut?: bool} $champs
     *
     * @throws \DomainException
     */
    public function enregistrerAdresse(Clients $client, ?AdresseLivraison $adresse, array $champs): AdresseLivraison
    {
        if ('' === trim($champs['libelle']) || '' === trim($champs['adresse'])) {
            throw new \DomainException('Donnez un nom court et l\'adresse.');
        }
        if (null === $adresse && \count($this->adresses($client)) >= AdresseLivraison::MAX_PAR_CLIENT) {
            throw new \DomainException('Vous avez déjà le nombre maximum d\'adresses.');
        }

        $adresse ??= (new AdresseLivraison())->setClient($client);
        $adresse
            ->setLibelle($champs['libelle'])
            ->setAdresse($champs['adresse'])
            ->setRepere($champs['repere'] ?? null)
            ->setContact($champs['contact'] ?? null)
            ->setTelephone($champs['telephone'] ?? null);

        if ($champs['parDefaut'] ?? false) {
            foreach ($this->adresses($client) as $autre) {
                $autre->setParDefaut(false);
            }
            $adresse->setParDefaut(true);
        }

        $this->em->persist($adresse);
        $this->em->flush();

        return $adresse;
    }

    public function supprimerAdresse(AdresseLivraison $adresse): void
    {
        $adresse->setActif(false);
        $this->em->flush();
    }

    /**
     * Valideurs actifs de l'entreprise.
     *
     * @return list<CompteClient>
     */
    public function valideurs(Clients $client): array
    {
        return array_values(array_filter(
            $this->utilisateurs($client),
            static fn (CompteClient $c): bool => $c->isActif() && $c->peut(CompteClient::DROIT_VALIDEUR),
        ));
    }

    /** Prévient les valideurs qu'une commande attend leur accord. */
    public function prevenirValideurs(Commandes $commande, CompteClient $demandeur): void
    {
        $client = $demandeur->getClient();
        if (null === $client) {
            return;
        }
        foreach ($this->valideurs($client) as $valideur) {
            $this->sms->envoyerSms($valideur->getTelephone(), \sprintf(
                'Succès Imprim : %s a fait une demande de commande de %s F. À valider dans votre espace client.',
                $demandeur->getNomAffiche(),
                number_format((float) $commande->getTotalTtc(), 0, ',', ' '),
            ));
        }
    }

    /** @throws \DomainException */
    private function verifierValideur(CompteClient $par, Commandes $commande): void
    {
        if (!$par->peut(CompteClient::DROIT_VALIDEUR) || $commande->getClients() !== $par->getClient()) {
            throw new \DomainException('Vous ne pouvez pas valider cette commande.');
        }
        if (!$commande->attendValidationEntreprise()) {
            throw new \DomainException('Cette commande n\'attend plus de validation.');
        }
    }

    /** @throws \DomainException */
    private function verifierMemeEntreprise(CompteClient $par, CompteClient $compte): void
    {
        if (!$par->peut(CompteClient::DROIT_ADMINISTRATEUR) || $par->getClient() !== $compte->getClient()) {
            throw new \DomainException('Seule une personne qui gère le compte peut faire ça.');
        }
    }
}
