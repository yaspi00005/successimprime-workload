<?php

namespace App\Command;

use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Service\Devis\SuiviDevis;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Suivi des devis de démonstration (docs/devis.md, docs/donnees-demo.md) :
 * date d'envoi, validité, conditions, version notée, ouvertures, motifs de
 * refus et relances, pour que la liste (GC03), la fiche (DT03) et l'espace
 * client (DT01) montrent un vrai suivi. Le devis en attente le plus récent
 * de Salon Espoir (DEMO-0002) passe en version 2 avec la demande du client
 * et le message de l'atelier.
 *
 * Lancée par app:demo après le chargement. Relancée : rien de plus si les
 * devis fictifs ont déjà leur suivi. Refusé en production et sur le site en
 * ligne (les devis y arrivent par la synchronisation).
 */
#[AsCommand(name: 'app:devis:demo', description: 'Suivi des devis fictifs : envoi, validité, conditions, versions, ouvertures, refus et relances.')]
final class DevisDemoCommand extends Command
{
    private const DELAIS = ['5 jours ouvrés', '3 jours ouvrés', '7 jours ouvrés', '48 heures', '10 jours ouvrés'];
    private const MOTIFS = ['prix', 'delai', 'prix', 'ailleurs', 'abandon'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de devis fictifs en production.');

            return Command::FAILURE;
        }
        if ($this->enLigne) {
            $io->note('Site en ligne : le suivi des devis arrive par la synchronisation.');

            return Command::SUCCESS;
        }
        $maintenant = new \DateTimeImmutable();

        /** @var list<Devis> $devis */
        $devis = $this->em->createQueryBuilder()->select('d', 'c', 'a')->from(Devis::class, 'd')
            ->leftJoin('d.clients', 'c')->leftJoin('d.agents', 'a')
            ->where('d.id >= 900000')->andWhere('d.numero LIKE :demo')->andWhere('d.deleted = false')
            ->andWhere('d.statut NOT IN (:hors)')
            ->setParameter('demo', 'DV-D%')->setParameter('hors', [Devis::STATUT_BROUILLON, Devis::STATUT_ANNULE])
            ->orderBy('d.dateDevis', 'ASC')
            ->getQuery()->getResult();
        if ([] === $devis || array_filter($devis, static fn (Devis $d): bool => [] !== $d->getVersions()) !== []) {
            $io->text('Suivi des devis fictifs : déjà en place.');

            return Command::SUCCESS;
        }

        $vus = $refus = $relances = 0;
        foreach ($devis as $d) {
            $id = (int) $d->getId();
            $envoi = \DateTimeImmutable::createFromInterface($d->getDateDevis() ?? $maintenant)->modify('+25 minutes');
            if ($envoi > $maintenant) {
                $envoi = $maintenant->modify('-5 minutes');
            }
            $client = $d->getClients();
            $nomClient = trim((string) ($d->getInterlocuteur() ?: $client?->getNomComplet())) ?: 'le client';

            $d->setValiditeJours($envoi < $maintenant->modify('-14 days') && $d->estEnAttente() ? 30 : 15)
                ->setAcomptePourcentage(0 === $id % 4 ? 30 : 50)
                ->setDelaiRealisation(self::DELAIS[$id % \count(self::DELAIS)])
                ->setConditionSolde('à la livraison')
                ->setConditionFichier(0 === $id % 3 ? 'création graphique incluse, 2 corrections' : 'fichier fourni par le client')
                ->setCanauxEnvoi(0 === $id % 3 ? [Devis::CANAL_ESPACE, Devis::CANAL_WHATSAPP, Devis::CANAL_EMAIL] : [Devis::CANAL_ESPACE, Devis::CANAL_WHATSAPP])
                ->setEnvoyeLe($envoi)
                ->setEnvoyePar(SuiviDevis::nomPublic($d->getAgents()))
                ->setDateValidite($envoi->modify(\sprintf('+%d days', $d->getValiditeJours())));
            if (Devis::STATUT_EXPIRE === $d->getStatut() && $d->getDateValidite()?->format('Y-m-d') >= $maintenant->format('Y-m-d')) {
                // Expiré : sa validité est passée.
                $d->setValiditeJours(7)->setDateValidite(min($envoi->modify('+7 days'), $maintenant->modify('-1 day')));
            }
            $d->noterVersion(SuiviDevis::instantane($d, $envoi));

            // Ouvertures : le client a lu les devis auxquels il a répondu, et une partie des autres.
            $ouvert = !\in_array($d->getStatut(), [Devis::STATUT_ENVOYE, Devis::STATUT_EXPIRE], true) || 0 === $id % 3;
            if ($ouvert) {
                $premiere = $envoi->modify(\sprintf('+%d minutes', 40 + ($id % 7) * 55));
                $fois = 1 + $id % 3;
                $derniere = $fois > 1 ? $premiere->modify('+20 hours') : $premiere;
                if ($derniere < $maintenant) {
                    $d->setNombreVues($fois)->setPremiereVueLe($premiere)->setDerniereVueLe($derniere)
                        ->setVuPar(0 === $id % 2 ? 'lien WhatsApp' : $nomClient);
                    if (Devis::STATUT_ENVOYE === $d->getStatut()) {
                        $d->setStatut(Devis::STATUT_VU);
                        ++$vus;
                    }
                }
            }

            // Réponse du client.
            if ($d->estAccepte() || Devis::STATUT_REFUSE === $d->getStatut()) {
                $reponse = null !== $d->getCommande()?->getDateCommande()
                    ? \DateTimeImmutable::createFromInterface($d->getCommande()->getDateCommande())
                    : $envoi->modify('+1 day');
                $d->setReponseLe(max($reponse, $d->getDerniereVueLe() ?? $envoi))->setReponduPar($nomClient);
            }
            if (Devis::STATUT_REFUSE === $d->getStatut()) {
                SuiviDevis::refuser($d, self::MOTIFS[$id % \count(self::MOTIFS)], 0 === $id % 4 ? 'trop cher pour nous cette fois' : null, $nomClient, $d->getReponseLe() ?? $envoi);
                ++$refus;
            }

            // Relance faite à la main pour une partie des devis sans réponse depuis plus de 3 jours.
            if ($d->estEnAttente() && $envoi < $maintenant->modify('-4 days') && 0 === $id % 2) {
                $d->noterRelance($envoi->modify('+3 days')->setTime(9, 30), Devis::CANAL_WHATSAPP, false, SuiviDevis::nomPublic($d->getAgents()));
                ++$relances;
            }
        }

        $version2 = $this->version2($devis, $maintenant);
        $this->em->flush();

        $io->text(\sprintf('Suivi des devis fictifs : %d devis, %d ouverts sans réponse, %d refus avec motif, %d relances%s.', \count($devis), $vus, $refus, $relances, $version2 ? ', version 2 de '.$version2 : ''));

        return Command::SUCCESS;
    }

    /**
     * Le devis en attente le plus récent de Salon Espoir passe en version 2 :
     * la version 1 avait une ligne de moins (ou une quantité de moins), le
     * client a demandé le changement dans ses messages, l'atelier a répondu.
     *
     * @param list<Devis> $devis
     */
    private function version2(array $devis, \DateTimeImmutable $maintenant): ?string
    {
        $choix = null;
        foreach ($devis as $d) {
            if ('DEMO-0002' === $d->getClients()?->getCode() && $d->estEnAttente() && $d->getEnvoyeLe() < $maintenant->modify('-2 days')) {
                $choix = $d;
            }
        }
        if (null === $choix || null === $choix->getClients()) {
            return null;
        }
        $v2 = $choix->getVersionNumero(1);
        $lignes = $v2['lignes'] ?? [];
        if ([] === $lignes) {
            return null;
        }
        $envoi2 = $choix->getEnvoyeLe();
        $envoi1 = $envoi2->modify('-3 days');
        if (\count($lignes) > 1) {
            $retiree = array_pop($lignes);
            $demande = 'Pouvez-vous ajouter '.mb_strtolower($retiree['designation']).' ?';
            $reponse = 'voici la version 2, avec '.mb_strtolower($retiree['designation']).' en plus.';
        } else {
            $q = max(1.0, $lignes[0]['quantite']);
            $lignes[0]['quantite'] = max(1.0, $q - 1);
            $lignes[0]['total'] = (int) round($lignes[0]['total'] * $lignes[0]['quantite'] / $q);
            $demande = $q > 1 ? \sprintf('Il nous en faudrait %s au lieu de %s.', rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ','), rtrim(rtrim(number_format($q - 1, 2, ',', ''), '0'), ',')) : 'Pouvez-vous revoir le prix ?';
            $reponse = 'voici la version 2 mise à jour selon votre demande.';
            if (1.0 === $q) {
                $lignes[0]['total'] = (int) round($lignes[0]['total'] * 1.12);
            }
        }
        $v1 = [
            'numero' => 1,
            'le' => $envoi1->format(\DATE_ATOM),
            'total' => array_sum(array_column($lignes, 'total')),
            'resume' => SuiviDevis::resume($lignes),
            'lignes' => $lignes,
            'commentaire' => null,
        ];
        $v2 = ['numero' => 2, 'commentaire' => $demande] + $v2;

        // noterVersion remplace la version de même numéro : la 1 devient celle d'avant la demande.
        $choix->noterVersion($v1);
        $choix->noterVersion($v2);
        $choix->setVersion(2)
            ->setDemandeModification($demande)->setDemandeModificationLe($envoi1->modify('+1 day'))->setReponduPar('Oumar Guindo')
            ->setStatut(Devis::STATUT_VU)->setNombreVues(2)->setPremiereVueLe($envoi2->modify('+2 hours'))->setDerniereVueLe($envoi2->modify('+1 day'))->setVuPar('Oumar Guindo')
            ->setDemandeModification(null)->setDemandeModificationLe(null);
        if (null === $choix->getObjet()) {
            $choix->setObjet('Supports pour le salon');
        }

        $client = $choix->getClients();
        $this->em->persist(MessageClient::nouveau($client, MessageClient::CLIENT, \sprintf('Devis %s : %s', $choix->getNumero(), $demande), 'Oumar Guindo', null, $envoi1->modify('+1 day')));
        $this->em->persist(MessageClient::nouveau($client, MessageClient::ATELIER, \sprintf('Devis %s : %s', $choix->getNumero(), $reponse), $choix->getEnvoyePar(), null, $envoi2));

        return (string) $choix->getNumero();
    }
}
