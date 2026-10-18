<?php

namespace App\Service\Promotions;

use App\Entity\AnniversaireClient;
use App\Entity\ModeleAnniversaire;

/**
 * Règles des anniversaires de clients (docs/promotions.md), sans base de
 * données : date de départ, prochain anniversaire, modèle du palier, texte
 * du SMS et ce que la tâche du jour doit faire.
 */
final class CalculAnniversaire
{
    /** Jours pendant lesquels un anniversaire validé en retard est encore fêté. */
    public const RATTRAPAGE_JOURS = 7;

    /** Rappel, sans montant ni pourcentage. */
    public const TEXTE_RAPPEL = 'Succès Imprim : {prénom}, votre cadeau d\'anniversaire vous attend encore à l\'imprimerie jusqu\'au {fin}. À bientôt !';

    /**
     * Création de la fiche, ou première commande si elle est plus ancienne
     * (clients repris de l'ancien système).
     */
    public static function dateDepart(?\DateTimeInterface $creation, ?\DateTimeInterface $premiereCommande): ?\DateTimeImmutable
    {
        $dates = array_filter([$creation, $premiereCommande]);
        if ([] === $dates) {
            return null;
        }
        $plusAncienne = min(array_map(static fn (\DateTimeInterface $d): string => $d->format('Y-m-d'), $dates));

        return new \DateTimeImmutable($plusAncienne);
    }

    /** Le jour J des N années ; un 29 février se fête le 28 les autres années. */
    public static function dateAnniversaire(\DateTimeImmutable $depart, int $annees): \DateTimeImmutable
    {
        $annee = (int) $depart->format('Y') + $annees;
        $mois = (int) $depart->format('n');
        $jour = (int) $depart->format('j');
        if (2 === $mois && 29 === $jour && !checkdate(2, 29, $annee)) {
            $jour = 28;
        }

        return (new \DateTimeImmutable())->setDate($annee, $mois, $jour)->setTime(0, 0);
    }

    /**
     * Prochain anniversaire à partir de $jour (compris) : au moins un an.
     *
     * @return array{date: \DateTimeImmutable, annees: int}
     */
    public static function prochain(\DateTimeImmutable $depart, \DateTimeImmutable $jour): array
    {
        $jour = $jour->setTime(0, 0);
        $annees = max(1, (int) $jour->format('Y') - (int) $depart->format('Y'));
        while (self::dateAnniversaire($depart, $annees) < $jour) {
            ++$annees;
        }
        // Un départ en fin d'année peut donner un anniversaire de l'an prochain plus proche que celui calculé.
        while ($annees > 1 && self::dateAnniversaire($depart, $annees - 1) >= $jour) {
            --$annees;
        }

        return ['date' => self::dateAnniversaire($depart, $annees), 'annees' => $annees];
    }

    /**
     * Modèle du palier : celui du plus grand nombre d'années atteint
     * (le modèle « 3 ans » vaut aussi pour 4 ans s'il n'y a pas de « 4 ans »).
     *
     * @param iterable<ModeleAnniversaire> $modeles
     */
    public static function modelePour(iterable $modeles, int $annees): ?ModeleAnniversaire
    {
        $retenu = null;
        foreach ($modeles as $m) {
            if ($m->isActif() && $m->getAnnees() <= $annees && (null === $retenu || $m->getAnnees() > $retenu->getAnnees())) {
                $retenu = $m;
            }
        }

        return $retenu;
    }

    public static function libelleAnnees(int $annees): string
    {
        return $annees.' an'.($annees > 1 ? 's' : '');
    }

    /** Texte envoyé : {prénom}, {années} et {fin} remplacés. */
    public static function texte(string $modele, ?string $prenom, ?string $nom, int $annees, \DateTimeInterface $fin): string
    {
        $prenom = trim((string) $prenom);
        $nom = trim((string) $nom);
        $qui = '' !== $prenom ? $prenom : ('' !== $nom ? $nom : 'cher client');

        return strtr($modele, [
            '{prénom}' => $qui,
            '{prenom}' => $qui,
            '{années}' => self::libelleAnnees($annees),
            '{annees}' => self::libelleAnnees($annees),
            '{fin}' => $fin->format('d/m/Y'),
        ]);
    }

    /**
     * Le texte parle-t-il d'argent ? Les messages aux clients ne donnent
     * jamais de montant ni de pourcentage : le cadeau se découvre à l'imprimerie
     * ou dans l'espace client.
     */
    public static function contientMontant(string $texte): bool
    {
        return 1 === preg_match('/%|\bf\s*cfa\b|\bfcfa\b|\bfrancs?\b|\bxof\b|\d[\d\s.,]*\s*f(?!\p{L})|[−-]\d/iu', $texte);
    }

    /**
     * Ce que la tâche du jour fait de cet anniversaire :
     * - pas_encore : le jour J n'est pas arrivé ;
     * - deja_traite : déjà fait (la tâche relancée ne refait rien) ;
     * - exclu : l'administrateur l'a écarté ;
     * - trop_tard : jour J passé depuis plus de RATTRAPAGE_JOURS sans être fêté ;
     * - attente_validation : validation demandée et pas encore donnée ;
     * - creer : promotion à créer et message à envoyer.
     */
    public static function actionDuJour(?AnniversaireClient $a, \DateTimeImmutable $jourJ, \DateTimeImmutable $aujourdhui, bool $validationRequise): string
    {
        $j = $jourJ->format('Y-m-d');
        $auj = $aujourdhui->format('Y-m-d');

        return match (true) {
            $auj < $j => 'pas_encore',
            null !== $a && $a->estTraite() => 'deja_traite',
            null !== $a && $a->estExclu() => 'exclu',
            $auj > $jourJ->modify('+'.self::RATTRAPAGE_JOURS.' days')->format('Y-m-d') => 'trop_tard',
            $validationRequise && (null === $a || !$a->estValide()) => 'attente_validation',
            default => 'creer',
        };
    }

    /**
     * Le rappel part une fois, $joursAvant jours avant la fin (ou plus tard
     * si la tâche n'a pas tourné ce jour-là), si la promotion n'a pas servi.
     */
    public static function rappelDu(AnniversaireClient $a, \DateTimeImmutable $fin, \DateTimeImmutable $aujourdhui, int $joursAvant, bool $utilisee): bool
    {
        if ($joursAvant <= 0 || $utilisee || null !== $a->getRappelLe() || null === $a->getPromotion() || null === $a->getEnvoyeLe()) {
            return false;
        }
        $auj = $aujourdhui->format('Y-m-d');
        $depuis = $fin->modify('-'.$joursAvant.' days')->format('Y-m-d');

        // Pas de rappel pour une validité trop courte : il partirait avec le premier message.
        return $auj >= $depuis && $auj <= $fin->format('Y-m-d') && $depuis > $a->getEnvoyeLe()->format('Y-m-d');
    }
}
