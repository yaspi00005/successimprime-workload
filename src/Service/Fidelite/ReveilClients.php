<?php

namespace App\Service\Fidelite;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\PromoReveil;
use App\Entity\User;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clients dormeurs (docs/fidelite.md) : les particuliers sans commande
 * depuis N jours, une « promo de réveil » (une remise en %, valable
 * quelques jours ou aujourd'hui seulement) que la direction leur offre,
 * que le client voit dans son espace et que l'atelier applique sur sa
 * prochaine commande. Rien n'est envoyé : un lien WhatsApp à ouvrir,
 * sans montant, jamais pour une fiche de démonstration.
 */
final class ReveilClients
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly Fidelite $fidelite,
    ) {
    }

    /** @return array{jours: int, taux: float, duree: int} réglages de la tuile Fidélité */
    public function reglages(): array
    {
        return [
            'jours' => max(1, (int) ($this->parametres->valeur('fidelite', 'dormant_jours') ?? 90)),
            'taux' => (float) ($this->parametres->valeur('fidelite', 'reveil_taux') ?? 10),
            'duree' => max(0, (int) ($this->parametres->valeur('fidelite', 'reveil_jours') ?? 15)),
        ];
    }

    /**
     * Les particuliers dont la dernière commande date de plus de $jours,
     * du plus récemment endormi au plus ancien.
     *
     * @return list<array{client: Clients, derniere: \DateTimeImmutable, jours: int, commandes: int, promo: ?PromoReveil, whatsapp: ?string}>
     */
    public function dormants(int $jours, \DateTimeImmutable $maintenant, int $max = 100): array
    {
        $limite = $maintenant->setTime(0, 0)->modify('-'.$jours.' days');
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'MAX(c.dateCommande) AS derniere', 'COUNT(c.id) AS nombre')
            ->from(Commandes::class, 'c')
            ->join('c.clients', 'cl')
            ->where('c.deleted = false')->andWhere('cl.typeClient != :b2b')
            ->andWhere('cl.statut = true OR cl.statut IS NULL')
            ->groupBy('c.clients')
            ->having('MAX(c.dateCommande) < :limite')
            ->orderBy('derniere', 'DESC')
            ->setParameter('b2b', 'B2B')->setParameter('limite', $limite)
            ->setMaxResults($max)
            ->getQuery()->getArrayResult();
        if ([] === $lignes) {
            return [];
        }

        $ids = array_map(static fn (array $l): int => (int) $l['client'], $lignes);
        $clients = [];
        foreach ($this->em->getRepository(Clients::class)->findBy(['id' => $ids]) as $c) {
            $clients[$c->getId()] = $c;
        }
        $promos = [];
        foreach ($this->promosActives($ids, $maintenant) as $p) {
            $promos[$p->getClient()->getId()] ??= $p;
        }

        $liste = [];
        foreach ($lignes as $l) {
            $client = $clients[(int) $l['client']] ?? null;
            if (null === $client) {
                continue;
            }
            $derniere = new \DateTimeImmutable((string) $l['derniere']);
            $liste[] = [
                'client' => $client,
                'derniere' => $derniere,
                'jours' => (int) $derniere->setTime(0, 0)->diff($maintenant->setTime(0, 0))->days,
                'commandes' => (int) $l['nombre'],
                'promo' => $promos[$client->getId()] ?? null,
                'whatsapp' => $this->lienWhatsApp($client, $promos[$client->getId()] ?? null),
            ];
        }

        return $liste;
    }

    /** La promo encore valable aujourd'hui, s'il y en a une. */
    public function active(Clients $client, \DateTimeImmutable $maintenant): ?PromoReveil
    {
        return $this->promosActives([(int) $client->getId()], $maintenant)[0] ?? null;
    }

    /**
     * @param list<int> $clients
     *
     * @return list<PromoReveil>
     */
    private function promosActives(array $clients, \DateTimeImmutable $maintenant): array
    {
        $jour = $maintenant->setTime(0, 0);

        return $this->em->createQueryBuilder()->select('p')->from(PromoReveil::class, 'p')
            ->where('p.client IN (:c)')->andWhere('p.utiliseeLe IS NULL')->andWhere('p.annuleeLe IS NULL')
            ->andWhere('p.debut <= :jour')->andWhere('p.fin >= :jour')
            ->setParameter('c', $clients)->setParameter('jour', $jour, 'date_immutable')
            ->orderBy('p.fin', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Offre une promo de réveil. $duree en jours ; 0 = aujourd'hui seulement.
     *
     * @throws \DomainException
     */
    public function offrir(Clients $client, float $taux, int $duree, User $par, \DateTimeImmutable $maintenant): PromoReveil
    {
        $this->fidelite->surLaBoutique();
        if ($client->isB2B()) {
            throw new \DomainException('Client au prix B2B : hors programme de fidélité.');
        }
        if ($taux <= 0 || $taux > 50) {
            throw new \DomainException('La remise va de 1 à 50 %.');
        }
        if ($duree < 0 || $duree > 90) {
            throw new \DomainException('La durée va de 0 (aujourd\'hui seulement) à 90 jours.');
        }
        if (null !== $this->active($client, $maintenant)) {
            throw new \DomainException('Ce client a déjà une promo de réveil en cours.');
        }
        $derniere = $this->em->createQueryBuilder()->select('MAX(c.dateCommande)')->from(Commandes::class, 'c')
            ->where('c.clients = :cl')->andWhere('c.deleted = false')->setParameter('cl', $client)
            ->getQuery()->getSingleScalarResult();
        $jour = $maintenant->setTime(0, 0);

        $promo = (new PromoReveil())
            ->setClient($client)
            ->setTaux($taux)
            ->setDebut($jour)
            ->setFin($duree <= 1 ? $jour : $jour->modify('+'.($duree - 1).' days'))
            ->setJoursSansCommande(null === $derniere ? null : (int) (new \DateTimeImmutable((string) $derniere))->setTime(0, 0)->diff($jour)->days)
            ->setCreePar($par);
        $this->em->persist($promo);
        $this->fidelite->journaliser($client, 'promo_reveil', null, ['taux' => $taux, 'du' => $promo->getDebut()->format('Y-m-d'), 'au' => $promo->getFin()->format('Y-m-d')], $par);
        $this->em->flush();

        return $promo;
    }

    public function annuler(PromoReveil $promo, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->fidelite->surLaBoutique();
        if (null !== $promo->getUtiliseeLe() || null !== $promo->getAnnuleeLe()) {
            throw new \DomainException('Cette promo est déjà utilisée ou retirée.');
        }
        $promo->annuler($maintenant);
        $this->fidelite->journaliser($promo->getClient(), 'promo_reveil_retiree', ['taux' => $promo->getTaux(), 'au' => $promo->getFin()->format('Y-m-d')], null, $par);
        $this->em->flush();
    }

    /**
     * Applique la promo sur une commande : une remise par unité sur chaque
     * article (prix unitaire × taux), comme une remise saisie à la main.
     *
     * @return int le montant retiré (F TTC)
     *
     * @throws \DomainException
     */
    public function appliquer(PromoReveil $promo, Commandes $commande, User $par, \DateTimeImmutable $maintenant): int
    {
        $this->fidelite->surLaBoutique();
        $client = $commande->getClients();
        if (null === $client || $promo->getClient()?->getId() !== $client->getId()) {
            throw new \DomainException('Cette promo est celle d\'un autre client.');
        }
        if (!$promo->estValableLe($maintenant)) {
            throw new \DomainException('Cette promo n\'est plus valable.');
        }
        if (Fidelite::commandeAnnulee($commande)) {
            throw new \DomainException('Cette commande est annulée.');
        }
        foreach ($commande->getFactures() as $f) {
            if ($f->estFacture() && !$f->estAnnulee()) {
                throw new \DomainException('La commande est déjà facturée : la remise ne peut plus y être ajoutée.');
            }
        }

        $avant = $commande->getTotalTtc();
        $retire = 0;
        foreach ($commande->getCommandesDetails() as $ligne) {
            if (CommandesDetails::PRODUCTION_ANNULEE === $ligne->getStatutProduction() || $ligne->getPrixUnitaire() <= 0) {
                continue;
            }
            // Une seule promotion par ligne : celle qui porte déjà une promotion client la garde (docs/promotions.md).
            if ($ligne->getRemisePromotion() > 0) {
                continue;
            }
            $ancien = $ligne->getTotalTtc();
            $ligne->setRemise($ligne->getRemise() + (int) round($ligne->getPrixUnitaire() * $promo->getTaux() / 100));
            $ligne->calculerTotaux($client->isB2B());
            $retire += max(0, $ancien - $ligne->getTotalTtc());
        }
        if ($retire <= 0) {
            throw new \DomainException('Aucun article à remiser sur cette commande.');
        }
        $commande->recalculerTotaux();
        $promo->utiliser($commande, max(0, $avant - $commande->getTotalTtc()), $maintenant);
        $this->fidelite->journaliser($client, 'promo_reveil_utilisee', null, ['commande' => $commande->getNumero(), 'taux' => $promo->getTaux()], $par);
        $this->em->flush();

        return $promo->getMontantRemise() ?? $retire;
    }

    /**
     * Lien WhatsApp vers le client, message sans montant ni taux. Jamais
     * pour une fiche de démonstration (code DEMO-).
     */
    public function lienWhatsApp(Clients $client, ?PromoReveil $promo): ?string
    {
        if (NotificationsClient::estDemo($client)) {
            return null;
        }
        $numero = preg_replace('/\D+/', '', (string) $client->getTelephone());
        if (8 === \strlen($numero)) {
            $numero = '223'.$numero;
        }
        if (\strlen($numero) < 11) {
            return null;
        }
        $nom = trim((string) ($client->getPrenom() ?: $client->getNomComplet()));
        $texte = null !== $promo
            ? \sprintf("Bonjour %s, cela fait un moment ! Une remise vous attend chez Succès Imprim %s : elle est dans votre espace client, rubrique Fidélité. À bientôt !", $nom, $promo->estDuJourSeulement() ? 'aujourd\'hui seulement' : 'jusqu\'au '.$promo->getFin()->format('d/m'))
            : \sprintf('Bonjour %s, cela fait un moment ! Avez-vous un projet d\'impression ? Toute l\'équipe de Succès Imprim est à votre écoute.', $nom);

        return 'https://wa.me/'.$numero.'?text='.rawurlencode($texte);
    }
}
