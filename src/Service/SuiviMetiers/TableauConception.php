<?php

namespace App\Service\SuiviMetiers;

use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\User;
use App\Repository\ConceptionRepository;
use App\Service\Parametres\Parametres;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tableau de conception du graphiste (W01) et évolution d'un travail
 * (W02). Chaque contrôle prépresse a son suivi de conception ; l'étape
 * suit le statut prépresse, le suivi garde le chronomètre, la frise et
 * les points.
 */
final class TableauConception
{
    /** Sous ce délai avant l'échéance, la pastille passe à l'orange. */
    private const BIENTOT_MINUTES = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConceptionRepository $conceptions,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * Crée le suivi des nouveaux contrôles et reprend ce qui a été fait
     * depuis l'écran prépresse (validation, envoi en production).
     */
    public function synchroniser(\DateTimeImmutable $maintenant): void
    {
        $sans = $this->em->createQueryBuilder()
            ->select('ctl')->from(ControlePrePresse::class, 'ctl')
            ->leftJoin(Conception::class, 'c', 'WITH', 'c.controle = ctl')
            ->where('c.id IS NULL')
            ->getQuery()->getResult();
        $heures = $this->parametres->heuresTravail();
        $delai = (int) $this->parametres->valeur('bareme', 'delai_bat_heures') ?: 4;
        foreach ($sans as $controle) {
            \assert($controle instanceof ControlePrePresse);
            $cree = $controle->getCreeLe();
            $fini = \in_array($controle->getStatut(), [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true);
            $this->em->persist(new Conception($controle, $cree, $fini ? null : $heures->ajouter(max($cree, $maintenant->modify('-30 days')), $delai * 60)));
        }

        foreach ($this->conceptions->pourTableau($maintenant->modify('-2 days')) as $c) {
            $controle = $c->getControle();
            $statut = $controle->getStatut();
            if ($c->getValideLe() === null && $c->getGraphiste() !== null && \in_array($statut, [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true)) {
                $c->valider($this->parametres->bareme(), $controle->getValideLe() ?? $maintenant, 'depuis l\'écran prépresse');
            }
            if ($statut === ControlePrePresse::STATUT_ENVOYE_PRODUCTION && !$c->aEvenement('production') && $c->getGraphiste() !== null) {
                $machine = $controle->getCommandeDetail()?->getMachine()?->getNom();
                $c->envoyeEnProduction($controle->getEnvoyeProductionLe() ?? $maintenant, $machine !== null ? 'fichier transmis à la file '.$machine : null);
            }
        }
        $this->em->flush();
    }

    /**
     * Colonnes du tableau. Sans $tous : les travaux de la personne et ceux
     * que personne n'a encore pris.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function colonnes(User $moi, bool $tous, \DateTimeImmutable $maintenant): array
    {
        $colonnes = array_fill_keys(array_keys(Conception::ETAPES), []);
        foreach ($this->conceptions->pourTableau($maintenant->modify('-1 day')->setTime(0, 0)) as $c) {
            if (!$tous && $c->getGraphiste() !== null && $c->getGraphiste() !== $moi) {
                continue;
            }
            $colonnes[$c->etape()][] = $this->carte($c, $maintenant);
        }

        return $colonnes;
    }

    /** @return array<string, mixed> */
    public function carte(Conception $c, \DateTimeImmutable $maintenant): array
    {
        $detail = $c->getControle()->getCommandeDetail();
        $commande = $detail?->getCommande();
        $client = $commande?->getClients();
        $bareme = $this->parametres->bareme();
        $etape = $c->etape();

        return [
            'c' => $c,
            'etape' => $etape,
            'numero' => $commande?->getNumero(),
            'titre' => $detail !== null ? TableauProduction::titre($detail) : 'Travail',
            'client' => $client !== null ? ($client->getRaisonSociale() ?: $client->getNomComplet()) : null,
            'coef' => $bareme->coefficient($c->getDifficulte()),
            'delai' => $this->delai($c, $maintenant),
            'possibles' => $c->getPoints() ?? $bareme->possibles(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1], $c->getDifficulte()),
            'acquis' => $c->getPoints() !== null,
            'secondes' => $c->secondesA($maintenant),
        ];
    }

    /**
     * Pastille de délai : vert (dans les temps), orange (bientôt), rouge
     * (dépassée), bleu (le client a la main).
     *
     * @return array{couleur: string, texte: string}
     */
    public function delai(Conception $c, \DateTimeImmutable $maintenant): array
    {
        $echeance = $c->getEcheanceBat();
        $etape = $c->etape();
        if ($etape === Conception::VALIDE) {
            $a = $c->dansLesDelais();

            return $a === false ? ['couleur' => 'orange', 'texte' => 'BAT en retard'] : ['couleur' => 'vert', 'texte' => 'dans les délais'];
        }
        if ($etape === Conception::BAT_ENVOYE) {
            $envoye = $c->getDernierBatLe();

            return ['couleur' => 'bleu', 'texte' => $envoye !== null ? 'attente client '.Conception::duree($maintenant->getTimestamp() - $envoye->getTimestamp()) : 'attente du client'];
        }
        if ($echeance === null) {
            return ['couleur' => 'gris', 'texte' => 'sans échéance'];
        }
        $reste = $echeance->getTimestamp() - $maintenant->getTimestamp();
        $prefixe = $etape === Conception::CORRECTIONS ? 'V'.($c->getVersions() + 1).' avant '.$echeance->format('H:i').' · ' : '';
        if ($reste < 0) {
            return ['couleur' => 'rouge', 'texte' => $prefixe.'dépassée de '.Conception::duree(-$reste)];
        }

        return [
            'couleur' => $reste <= self::BIENTOT_MINUTES * 60 ? 'orange' : 'vert',
            'texte' => $prefixe.($etape === Conception::A_FAIRE ? 'dans ' : 'reste ').Conception::duree($reste),
        ];
    }

    /**
     * Chiffres du haut du tableau, pour la personne.
     *
     * @param array<string, list<array<string, mixed>>> $colonnes
     *
     * @return array<string, mixed>
     */
    public function chiffres(User $moi, array $colonnes, \DateTimeImmutable $maintenant): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $mois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $travaux = $this->conceptions->duGraphisteEntre($moi, $mois, $maintenant->modify('+1 second'));
        $avecDelai = array_filter($travaux, static fn (Conception $c): bool => $c->dansLesDelais() !== null);
        $valides = array_filter($travaux, static fn (Conception $c): bool => $c->getValideLe() !== null);
        $points = static fn (iterable $liste, \DateTimeImmutable $depuis): int => array_sum(array_map(static fn (Conception $c): int => $c->getValideLe() >= $depuis ? (int) $c->getPoints() : 0, [...$liste]));

        return [
            'travaux' => array_sum(array_map(static fn (array $col): int => \count(array_filter($col, static fn (array $x): bool => $x['c']->getGraphiste() === $moi || $x['c']->getGraphiste() === null)), array_diff_key($colonnes, [Conception::VALIDE => 1]))),
            'validesAujourdhui' => \count(array_filter($valides, static fn (Conception $c): bool => $c->getValideLe() >= $aujourdhui)),
            'enCours' => \count(array_filter($colonnes[Conception::EN_CONCEPTION], static fn (array $x): bool => $x['c']->enCours())),
            'delais' => ['ok' => \count(array_filter($avecDelai, static fn (Conception $c): bool => (bool) $c->dansLesDelais())), 'sur' => \count($avecDelai)],
            'premierEnvoi' => ['ok' => \count(array_filter($valides, static fn (Conception $c): bool => $c->valideAuPremierEnvoi())), 'sur' => \count($valides)],
            'pointsJour' => $points($valides, $aujourdhui),
            'pointsMois' => $points($valides, $mois),
        ];
    }

    /** Le travail dont le chronomètre tourne pour la personne. */
    public function enCours(User $moi): ?Conception
    {
        return $this->em->createQueryBuilder()->select('c')->from(Conception::class, 'c')
            ->where('c.graphiste = :g AND c.demarreLe IS NOT NULL')->setParameter('g', $moi)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Le prochain travail à faire : le sien ou un libre, par échéance. */
    public function suivant(User $moi, \DateTimeImmutable $maintenant): ?Conception
    {
        foreach ($this->colonnes($moi, false, $maintenant) as $etape => $cartes) {
            if (\in_array($etape, [Conception::CORRECTIONS, Conception::A_FAIRE], true)) {
                foreach ($cartes as $carte) {
                    return $carte['c'];
                }
            }
        }

        return null;
    }

    public function demarrer(Conception $c, User $moi, \DateTimeImmutable $maintenant): void
    {
        $autre = $this->enCours($moi);
        if ($autre !== null && $autre !== $c) {
            $autre->arreter($maintenant);
        }
        $c->demarrer($moi, $maintenant);
        $controle = $c->getControle();
        if (\in_array($controle->getStatut(), [ControlePrePresse::STATUT_A_CONTROLER, ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_REJETE], true)) {
            $controle->getFichiers()->isEmpty() ? $controle->setStatut(ControlePrePresse::STATUT_EN_COURS) : $controle->commencerControle($moi);
        }
        $this->em->flush();
    }

    public function pause(Conception $c, \DateTimeImmutable $maintenant): void
    {
        $c->arreter($maintenant);
        $this->em->flush();
    }

    /** Le BAT part chez le client ; le chronomètre s'arrête pendant l'attente. */
    public function envoyerBat(Conception $c, User $moi, \DateTimeImmutable $maintenant, ?string $canal = 'par WhatsApp et espace client'): void
    {
        $c->setGraphiste($c->getGraphiste() ?? $moi);
        $c->envoyerBat($maintenant, $canal);
        $c->getControle()
            ->setBatNecessaire(true)
            ->setBatValide(false)
            ->setCorrectionNecessaire(false)
            ->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT);
        $this->em->flush();
    }

    /** Appelé quand le BAT part depuis l'écran prépresse. */
    public function batEnvoyeDepuisPrepresse(ControlePrePresse $controle, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->synchroniser($maintenant);
        $c = $this->conceptions->findOneBy(['controle' => $controle]);
        if ($c !== null) {
            $c->setGraphiste($c->getGraphiste() ?? $par);
            $c->envoyerBat($maintenant, 'depuis l\'écran prépresse');
            $this->em->flush();
        }
    }

    /** Le PDF du montage est rangé dans la commande : noté dans la frise. */
    public function noterMontage(CommandesDetails $ligne, ?string $detail, \DateTimeImmutable $maintenant): void
    {
        $c = $this->em->createQueryBuilder()->select('c')->from(Conception::class, 'c')
            ->join('c.controle', 'ctl')->where('ctl.commandeDetail = :l')->setParameter('l', $ligne)
            ->orderBy('c.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($c instanceof Conception) {
            $c->noterMontage($maintenant, $detail !== '' ? $detail : null);
            $this->em->flush();
        }
    }

    public function retour(Conception $c, string $raison, ?string $texte, \DateTimeImmutable $maintenant): void
    {
        $c->retour($raison === Conception::RETOUR_ERREUR ? Conception::RETOUR_ERREUR : Conception::RETOUR_CLIENT, $texte, $maintenant);
        $c->getControle()->setCorrectionNecessaire(true)->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE);
        $this->em->flush();
    }

    /**
     * Le client valide le BAT : points du barème, puis validation
     * prépresse si les fichiers sont prêts. Renvoie la raison si la
     * validation prépresse reste à faire.
     */
    public function valider(Conception $c, User $par, \DateTimeImmutable $maintenant): ?string
    {
        $controle = $c->getControle();
        $controle->setBatValide(true)->setCorrectionNecessaire(false);
        $c->valider($this->parametres->bareme(), $maintenant, 'par le client · enregistré par '.($par->getEmploye()?->getPrenom() ?? $par->getUsername()));
        $reste = null;
        try {
            $controle->valider($par);
        } catch (\LogicException $e) {
            $reste = $e->getMessage();
        }
        $this->em->flush();

        return $reste;
    }

    public function message(Conception $c): ?array
    {
        $detail = $c->getControle()->getCommandeDetail();
        $commande = $detail?->getCommande();
        $telephone = trim((string) $commande?->getClients()?->getTelephone());
        if ($detail === null || $telephone === '') {
            return null;
        }

        return [
            'telephone' => $telephone,
            'texte' => \sprintf("Bonjour, le BAT (version %d) de votre %s, commande %s, est prêt chez Succès Imprim. Merci de nous dire s'il est bon à imprimer ou ce qu'il faut corriger.", $c->getVersions(), TableauProduction::titre($detail), $commande->getNumero()),
        ];
    }
}
