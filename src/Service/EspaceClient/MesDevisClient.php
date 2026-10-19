<?php

namespace App\Service\EspaceClient;

use App\Entity\CompteClient;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Service\Devis\SuiviDevis;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Mes devis » (maquette DB01, docs/devis.md) : chaque devis avec son
 * état (à accepter, en préparation, modifié, accepté, expiré), sa validité
 * et le bouton du moment ; les demandes envoyées dont le devis se prépare
 * encore ; quatre chiffres en haut.
 *
 * Un devis encore en brouillon au local n'est jamais montré : la demande
 * du client tient sa place, sans prix.
 */
final class MesDevisClient
{
    public const ONGLETS = ['tous' => 'Tous', 'a_accepter' => 'À accepter', 'preparation' => 'En préparation', 'acceptes' => 'Acceptés', 'expires' => 'Expirés'];

    /** Réponse promise à une demande : 4 h, entre 8 h et 18 h, jamais le dimanche. */
    private const HEURES_REPONSE = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
    ) {
    }

    /**
     * @return array{lignes: list<array<string, mixed>>, compte: array<string, int>, chiffres: array<string, mixed>}
     */
    public function page(CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $client = $compte->getClient();
        $lignes = [];
        foreach ($this->espace->devis($client) as $d) {
            $lignes[] = self::ligneDevis($d, $maintenant);
        }
        $demandes = $this->demandes($compte);
        foreach ($demandes as $demande) {
            $devis = $demande->getDevis();
            // Devis déjà envoyé : il est dans la liste ; en brouillon, la demande tient sa place.
            if (null !== $devis && \in_array($devis->getStatut(), EspaceClient::DEVIS_VISIBLES, true)) {
                continue;
            }
            if (null !== $devis && Devis::STATUT_BROUILLON !== $devis->getStatut()) {
                continue;
            }
            if ($demande->getCreeLe() < $maintenant->modify('-30 days')) {
                continue;
            }
            $lignes[] = self::ligneDemande($demande);
        }
        usort($lignes, static fn (array $a, array $b): int => [$a['rang'], $b['le']] <=> [$b['rang'], $a['le']]);

        $compteParOnglet = array_fill_keys(array_keys(self::ONGLETS), 0);
        foreach ($lignes as $l) {
            ++$compteParOnglet['tous'];
            if (null !== $l['onglet']) {
                ++$compteParOnglet[$l['onglet']];
            }
        }

        return ['lignes' => $lignes, 'compte' => $compteParOnglet, 'chiffres' => self::chiffres($lignes, $demandes, $maintenant)];
    }

    /** La demande du client dont ce devis est né, ou null. */
    public function demandeDe(Devis $devis): ?DemandeDevis
    {
        return $this->em->getRepository(DemandeDevis::class)->findOneBy(['devis' => $devis]);
    }

    /** Une demande de devis de ce compte, ou null. */
    public function demande(CompteClient $compte, int $id): ?DemandeDevis
    {
        foreach ($this->demandes($compte) as $d) {
            if ($d->getId() === $id) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Demandes de devis du client : reliées à sa fiche au local, ou
     * envoyées depuis un de ses numéros avant que le local les relie.
     *
     * @return list<DemandeDevis>
     */
    private function demandes(CompteClient $compte): array
    {
        $client = $compte->getClient();
        $numeros = array_values(array_filter(array_map(
            static fn (?string $t): ?string => null === $t ? null : CompteClient::normaliserTelephone($t),
            [$client?->getTelephone(), $client?->getTelephone2(), $compte->getTelephone()],
        )));

        $qb = $this->em->getRepository(DemandeDevis::class)->createQueryBuilder('d')
            ->where('d.nature = :n')->setParameter('n', DemandeDevis::NATURE_DEVIS)
            ->orderBy('d.id', 'DESC')->setMaxResults(60);
        if ([] === $numeros) {
            $qb->andWhere('d.client = :cl');
        } else {
            $qb->andWhere('d.client = :cl OR (d.client IS NULL AND d.telephone IN (:tels))')->setParameter('tels', $numeros);
        }

        return $qb->setParameter('cl', $client)->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    public static function ligneDevis(Devis $d, \DateTimeImmutable $maintenant): array
    {
        $expire = Devis::STATUT_EXPIRE === $d->getStatut() || ($d->estEnAttente() && SuiviDevis::estAExpirer($d, $maintenant));
        $validite = $d->getDateValidite();
        $jours = null !== $validite ? (int) $maintenant->setTime(0, 0)->diff($validite->setTime(0, 0))->format('%r%a') : null;
        $etat = match (true) {
            $d->estAccepte() => 'accepte',
            Devis::STATUT_REFUSE === $d->getStatut() => 'refuse',
            $expire => 'expire',
            $d->attendUneModification() => 'preparation',
            $d->getVersion() > 1 => 'modifie',
            default => 'accepter',
        };
        $quand = match ($etat) {
            'accepter' => null !== $validite ? \sprintf('valable jusqu\'au %s · %s', $validite->format('d/m'), 0 === $jours ? 'dernier jour' : $jours.' jour'.($jours > 1 ? 's' : '')) : null,
            'modifie' => null !== $d->getDemandeModification() ? 'vous aviez demandé : '.self::court($d->getDemandeModification(), 50) : 'v'.$d->getVersion().' envoyée le '.$d->getEnvoyeLe()?->format('d/m'),
            'preparation' => 'nouvelle version promise '.self::jourHeure(self::promesse($d->getDemandeModificationLe() ?? $maintenant)),
            'accepte' => null !== $d->getCommande() ? 'devenu la commande '.$d->getCommande()->getNumero() : 'accepté',
            'expire' => null !== $validite ? 'expiré le '.$validite->modify('+1 day')->format('d/m') : 'expiré',
            default => null !== $d->getReponseLe() ? 'refusé le '.$d->getReponseLe()->format('d/m') : 'refusé',
        };
        $sous = match ($etat) {
            'accepte' => 'Accepté le '.($d->getReponseLe() ?? $d->getEnvoyeLe())?->format('d/m'),
            'modifie' => 'v'.$d->getVersion().' envoyée suite à votre demande',
            'preparation' => 'Modification demandée le '.$d->getDemandeModificationLe()?->format('d/m'),
            'expire', 'refuse' => 'Non accepté',
            default => self::resume($d),
        };

        return [
            'sorte' => 'devis',
            'devis' => $d,
            'etat' => $etat,
            'onglet' => match ($etat) { 'accepter', 'modifie' => 'a_accepter', 'preparation' => 'preparation', 'accepte' => 'acceptes', 'expire' => 'expires', default => null },
            'titre' => $d->getObjet() ?: self::resume($d),
            'numero' => $d->getNumero(),
            'sous' => $sous,
            'quand' => $quand,
            'urgent' => 'accepter' === $etat && null !== $jours && $jours <= 3,
            'montant' => $d->getTotalTtc(),
            'expireDans' => 'accepter' === $etat || 'modifie' === $etat ? $jours : null,
            'le' => $d->getEnvoyeLe() ?? \DateTimeImmutable::createFromInterface($d->getDateDevis() ?? $maintenant),
            // À accepter d'abord, puis en préparation, puis le reste du plus récent au plus ancien.
            'rang' => match ($etat) { 'accepter', 'modifie' => 0, 'preparation' => 1, default => 2 },
        ];
    }

    /** @return array<string, mixed> */
    public static function ligneDemande(DemandeDevis $demande): array
    {
        $titre = $demande->getLibelleObjet() ?: ($demande->getDesignation() ?: null);
        if (null === $titre && $demande->estPanier()) {
            $noms = array_map(static fn ($l): string => (string) $l->getDesignation(), $demande->getLignes()->toArray());
            $titre = implode(', ', \array_slice(array_filter($noms), 0, 2)).(\count($noms) > 2 ? ' + '.(\count($noms) - 2) : '');
        }
        if (null !== $demande->getQuantite() && null !== $titre && !str_contains($titre, '×')) {
            $titre .= ' × '.$demande->getQuantite();
        }

        return [
            'sorte' => 'demande',
            'demande' => $demande,
            'etat' => 'preparation',
            'onglet' => 'preparation',
            'titre' => $titre ?: 'Votre demande de devis',
            'numero' => $demande->getNumero() ?? 'Demande',
            'sous' => 'Demande envoyée le '.$demande->getCreeLe()->format('d/m'),
            'quand' => 'réponse promise '.self::jourHeure(self::promesse($demande->getCreeLe())),
            'urgent' => false,
            'montant' => null,
            'expireDans' => null,
            'le' => $demande->getCreeLe(),
            'rang' => 1,
        ];
    }

    /**
     * Quand l'imprimerie promet de répondre : 4 heures plus tard, dans les
     * heures d'ouverture (8 h à 18 h), sinon le jour suivant à 12 h ; le
     * dimanche passe au lundi.
     */
    public static function promesse(\DateTimeImmutable $depuis): \DateTimeImmutable
    {
        $p = $depuis->modify('+'.self::HEURES_REPONSE.' hours');
        if ((int) $depuis->format('G') < 8) {
            $p = $depuis->setTime(12, 0);
        } elseif ((int) $p->format('G') >= 18 || $p->format('Y-m-d') !== $depuis->format('Y-m-d')) {
            $p = $depuis->modify('+1 day')->setTime(12, 0);
        }
        if ('7' === $p->format('N')) {
            $p = $p->modify('+1 day');
        }

        return $p;
    }

    /** « lun. 20/10 avant 12 h » */
    public static function jourHeure(\DateTimeImmutable $d): string
    {
        $jours = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $minutes = (int) $d->format('i');

        return \sprintf('%s %s avant %d h%s', $jours[(int) $d->format('N')], $d->format('d/m'), (int) $d->format('G'), $minutes > 0 ? \sprintf(' %02d', $minutes) : '');
    }

    /** Délai entre la demande et l'envoi du devis : « 2 h 05 », « 3 h », « 2 j ». */
    public static function duree(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => max(1, $minutes).' min',
            $minutes < 24 * 60 => intdiv($minutes, 60).' h'.(0 !== $minutes % 60 && $minutes < 10 * 60 ? ' '.\sprintf('%02d', $minutes % 60) : ''),
            default => (int) round($minutes / 1440).' j',
        };
    }

    /** « Bâche, 1 000 flyers, 200 cartes » : les premières lignes du devis. */
    public static function resume(Devis $d): string
    {
        $noms = [];
        foreach ($d->getDevisDetails() as $l) {
            $nom = trim((string) preg_replace('/\s*\(.*\)$/u', '', (string) $l->getDesignation()));
            if ('' !== $nom) {
                $noms[] = $l->getQuantite() > 1 ? number_format($l->getQuantite(), 0, ',', ' ').' '.mb_strtolower($nom) : $nom;
            }
        }

        return self::court(implode(', ', \array_slice($noms, 0, 3)).(\count($noms) > 3 ? '…' : ''), 70) ?: 'Devis';
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @param list<DemandeDevis>         $demandes
     *
     * @return array<string, mixed>
     */
    private static function chiffres(array $lignes, array $demandes, \DateTimeImmutable $maintenant): array
    {
        $aAccepter = array_values(array_filter($lignes, static fn (array $l): bool => 'a_accepter' === $l['onglet']));
        $preparation = array_values(array_filter($lignes, static fn (array $l): bool => 'preparation' === $l['onglet']));
        $expirent = array_filter(array_column($aAccepter, 'expireDans'), static fn ($j): bool => null !== $j);
        $annee = (int) $maintenant->format('Y');
        $acceptes = \count(array_filter($lignes, static fn (array $l): bool => 'accepte' === $l['etat'] && (int) (($l['devis']->getReponseLe() ?? $l['le'])->format('Y')) === $annee));
        $promesses = array_map(static fn (array $l): string => $l['quand'], $preparation);

        // Délai de réponse : de la demande au devis envoyé, sur les 12 derniers mois.
        $delais = [];
        foreach ($demandes as $d) {
            $envoye = $d->getDevis()?->getEnvoyeLe();
            if (null !== $envoye && $envoye >= $d->getCreeLe() && $d->getCreeLe() >= $maintenant->modify('-12 months')) {
                $delais[] = (int) (($envoye->getTimestamp() - $d->getCreeLe()->getTimestamp()) / 60);
            }
        }

        return [
            'aAccepter' => \count($aAccepter),
            'montantAAccepter' => array_sum(array_map(static fn (array $l): int => (int) $l['montant'], $aAccepter)),
            'expireDans' => [] !== $expirent ? min($expirent) : null,
            'preparation' => \count($preparation),
            'promesse' => $promesses[0] ?? null,
            'annee' => $annee,
            'acceptes' => $acceptes,
            'delai' => [] !== $delais ? self::duree((int) round(array_sum($delais) / \count($delais))) : null,
        ];
    }

    private static function court(string $texte, int $max): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        return mb_strlen($texte) > $max ? rtrim(mb_substr($texte, 0, $max - 1)).'…' : $texte;
    }
}
