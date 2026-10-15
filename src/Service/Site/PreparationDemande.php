<?php

namespace App\Service\Site;

use App\Entity\Clients;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\DevisDetailFinition;
use App\Entity\DevisDetails;
use App\Entity\ProduitConfiguration;
use App\Entity\Prospect;
use App\Service\Atelier\TarifAtelier;
use App\Service\EspaceClient\NouvelleCommande;

/**
 * Ce que devient au local une demande du site (maquette 11) : le client
 * du même numéro, sinon un prospect « Site » avec sa fiche, et un devis
 * brouillon au tarif public. Rien n'est envoyé ni confirmé : l'équipe
 * vérifie le devis avant de le donner au client.
 *
 * Construit les objets sans les enregistrer (DemandesSite s'en charge),
 * pour être testé sans base.
 */
final class PreparationDemande
{
    /**
     * @param array{paliers: list<array{quantite: int, pourcentage: float}>, face_en_plus: int, paliers_b2b: bool}|null $tarifAtelier réglages de l'Atelier 3D (TarifAtelier::reglages)
     *
     * @return array{client: ?Clients, nouveauClient: bool, prospect: ?Prospect, devis: ?Devis, aChiffrer: ?string}
     */
    public static function construire(DemandeDevis $demande, ?Clients $existant, \DateTimeImmutable $le, ?array $tarifAtelier = null): array
    {
        $resultat = ['client' => $existant, 'nouveauClient' => false, 'prospect' => null, 'devis' => null, 'aChiffrer' => null];

        if (!$demande->estDevis()) {
            // Un message : rattaché au client connu ; une demande de devis d'un inconnu devient un prospect.
            if (null === $existant && 'devis' === $demande->getObjet()) {
                $resultat['prospect'] = self::prospect($demande, null, $le, $demande->getMessage());
            }

            return $resultat;
        }

        // Le devis exige une fiche client : celle du même numéro, sinon une fiche « Site » à compléter.
        $client = $existant ?? self::nouveauClient($demande);
        $devis = (new Devis())
            ->setClients($client)
            ->setDateDevis($le)
            ->setStatut(Devis::STATUT_BROUILLON)
            ->setObservation(self::note($demande));

        if (null !== $demande->getConfiguration()) {
            try {
                $ligne = self::ligneDevis($demande->getConfiguration(), (int) ($demande->getQuantite() ?? 1), $demande->getLargeurCm(), $demande->getHauteurCm(), self::finitionsChoisies($demande));
                $creation = $demande->getCreation();
                if (null !== $creation && null !== $tarifAtelier) {
                    // Modèle de l'Atelier 3D : seconde face et palier de quantité, comme le visiteur les a vus (prix public).
                    TarifAtelier::appliquer($ligne, \count($creation->facesMarquees()), $tarifAtelier, false);
                    $ligne->setDesignation(mb_substr($creation->getDesignation(), 0, 255));
                }
                $devis->addDevisDetail($ligne);
            } catch (\DomainException $e) {
                // Au-delà du maximum, taille hors limites... : l'équipe chiffre elle-même.
                $resultat['aChiffrer'] = $e->getMessage();
                $devis->setObservation($devis->getObservation()."\nÀ chiffrer : ".$e->getMessage());
            }
        }
        self::totaliser($devis);

        $resultat['client'] = $client;
        $resultat['nouveauClient'] = null === $existant;
        $resultat['devis'] = $devis;
        if (null === $existant) {
            $resultat['prospect'] = self::prospect($demande, $client, $le, $demande->getResume() ?: $demande->getMessage())
                ->setMontantEstime($devis->getTotalTtc() > 0 ? $devis->getTotalTtc() : null);
        }

        return $resultat;
    }

    /**
     * Ligne de devis au tarif public, calculée comme la commande en ligne
     * (NouvelleCommande::construireLigne, la même que NouvelleCommande::estimer).
     *
     * @param list<int> $finitions identifiants de ProduitConfigurationFinition
     *
     * @throws \DomainException
     */
    public static function ligneDevis(ProduitConfiguration $config, int $quantite, ?float $largeurCm, ?float $hauteurCm, array $finitions): DevisDetails
    {
        $commande = NouvelleCommande::construireLigne($config, $quantite, $largeurCm, $hauteurCm, $finitions, false);

        // Ligne « manuelle » comme au comptoir : le produit et son prix, à la taille demandée
        // (le mode automatique reprendrait la taille par défaut de la configuration).
        $ligne = (new DevisDetails())
            ->setTypeLigne(DevisDetails::TYPE_PRODUIT)
            ->setModeConfiguration('manuel')
            ->setProduit($commande->getProduit())
            ->setTypeImpression($commande->getTypeImpression())
            ->setSupport($commande->getSupport())
            ->setFormat($commande->getFormat())
            ->setDesignation($commande->getDesignation())
            ->setModeCalcul($commande->getModeCalcul())
            ->setQuantite($quantite)
            ->setPrixUnitaire($commande->getPrixUnitaire())
            ->setTva(0);
        if (null !== $commande->getLargeur() && null !== $commande->getLongueur()) {
            $ligne->setLargeur($commande->getLargeur())->setLongueur($commande->getLongueur());
        }
        foreach ($commande->getFinitions() as $f) {
            $cf = $f->getConfigurationFinition();
            $ligne->addFinition((new DevisDetailFinition())
                ->setFinition($cf?->getFinition())
                ->setObligatoire((bool) $cf?->isObligatoire())
                ->setPrixApplique((int) $cf?->getPrix())
                ->setModeCalcul((string) $cf?->getModeCalcul())
                ->setQuantite($f->getQuantite())
                ->setMontant($f->getMontant()));
        }

        return $ligne->calculerTotaux(false);
    }

    /**
     * Finitions de la demande (envoyées par leur finition du catalogue)
     * retrouvées dans celles que propose le produit.
     *
     * @return list<int>
     */
    public static function finitionsChoisies(DemandeDevis $demande): array
    {
        $ids = [];
        foreach ($demande->getConfiguration()?->getConfigurationFinitions() ?? [] as $cf) {
            if (null !== $cf->getId() && $demande->getFinitions()->contains($cf->getFinition())) {
                $ids[] = $cf->getId();
            }
        }

        return $ids;
    }

    /** Fiche minimale « Site » : nom, prénom, téléphone, e-mail ; le code vient de l'id. */
    public static function nouveauClient(DemandeDevis $demande): Clients
    {
        $mots = explode(' ', $demande->getNom());
        $nom = array_pop($mots);
        $prenom = implode(' ', $mots);

        return (new Clients())
            ->setCode('TMP-'.bin2hex(random_bytes(12)))
            ->setNom(mb_substr($nom, 0, 50))
            ->setPrenom('' === $prenom ? '-' : mb_substr($prenom, 0, 50))
            ->setTelephone($demande->getTelephoneLocal())
            ->setEmail($demande->getEmail())
            ->setTypeClient('B2C')
            ->setOrigine('site')
            ->setObservation(\sprintf('Fiche créée par la demande %s du site : nom et téléphone donnés par le visiteur, non vérifiés, à compléter.', $demande->getNumero() ?? ''))
            ->setStatut(true);
    }

    /** Observation du devis (reste au local) : la demande telle qu'écrite. */
    public static function note(DemandeDevis $demande): string
    {
        $lignes = [\sprintf('Demande %s du site, %s (%s, non vérifié).', $demande->getNumero() ?? '', $demande->getNom(), $demande->getTelephoneLocal())];
        if ('' !== $demande->getResume()) {
            $lignes[] = 'Demandé : '.$demande->getResume();
        }
        if ($demande->getFinitions()->count() > 0) {
            $lignes[] = 'Finitions : '.implode(', ', array_map(static fn ($f): string => (string) $f->getNom(), $demande->getFinitions()->toArray()));
        }
        if (null !== $demande->getMessage()) {
            $lignes[] = 'Message : '.$demande->getMessage();
        }
        if (null !== $demande->getFichier()) {
            $lignes[] = 'Fichier joint : '.$demande->getFichier()->getNomOriginal();
        }
        if (null !== $demande->getCreation()) {
            $lignes[] = \sprintf('Modèle du Studio textile %s : vues et images dans Ventes › Créations Studio textile.', $demande->getCreation()->getNumero() ?? '');
        }

        return implode("\n", $lignes);
    }

    public static function totaliser(Devis $devis): void
    {
        $ht = 0;
        $ttc = 0;
        foreach ($devis->getDevisDetails() as $l) {
            $ht += $l->getTotalHt();
            $ttc += $l->getTotalTtc();
        }
        $devis->setTotalHt($ht)->setTotalTtc($ttc)->setTva(0);
    }

    private static function prospect(DemandeDevis $demande, ?Clients $client, \DateTimeImmutable $le, ?string $besoin): Prospect
    {
        return (new Prospect())
            ->setNom($demande->getNom())
            ->setTelephone($demande->getTelephoneLocal())
            ->setBesoin($besoin)
            ->setSource('site')
            ->setClient($client)
            ->setCreeLe($le)
            // Un contact venu du site se rappelle le jour même.
            ->setProchaineRelance($le->setTime(0, 0));
    }
}
