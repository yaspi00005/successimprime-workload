<?php

namespace App\Service\Site;

use App\Entity\Clients;
use App\Entity\DemandeDevis;
use App\Entity\DemandeDevisLigne;
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
     * @param float|null $espacementPiecesM2 espacement des pièces au m² (réglage du local), pour un produit au m² dont le visiteur donne la taille
     *
     * @return array{client: ?Clients, nouveauClient: bool, prospect: ?Prospect, devis: ?Devis, aChiffrer: ?string, lignes: list<array{0: DemandeDevisLigne, 1: DevisDetails}>}
     */
    public static function construire(DemandeDevis $demande, ?Clients $existant, \DateTimeImmutable $le, ?array $tarifAtelier = null, ?float $espacementPiecesM2 = null): array
    {
        $resultat = ['client' => $existant, 'nouveauClient' => false, 'prospect' => null, 'devis' => null, 'aChiffrer' => null, 'lignes' => []];

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

        if ($demande->estPanier()) {
            // Panier de devis : une ligne de devis par ligne de la demande, dans le même ordre.
            foreach ($demande->getLignes() as $l) {
                $ligne = self::ligneDuPanier($l, $espacementPiecesM2);
                $devis->addDevisDetail($ligne);
                $resultat['lignes'][] = [$l, $ligne];
                if (DevisDetails::TYPE_LIBRE === $ligne->getTypeLigne()) {
                    $resultat['aChiffrer'] ??= 'Ligne '.$l->getPosition().' à chiffrer';
                }
            }
        } elseif (null !== $demande->getConfiguration()) {
            try {
                $ligne = self::ligneDevis($demande->getConfiguration(), (int) ($demande->getQuantite() ?? 1), $demande->getLargeurCm(), $demande->getHauteurCm(), self::finitionsChoisies($demande));
                if (null !== $espacementPiecesM2 && FormulaireDemande::piecesAuM2Possible($demande->getConfiguration())) {
                    // En ligne, quantité en pièces ou en m² : la ligne est facturée en pièces au m², espacement
                    // compris (réponse de Yaya du 18/10). Une pièce plus grande que 1 m reste au calcul habituel.
                    $ligne->choisirPiecesAuM2(true, $espacementPiecesM2)->calculerTotaux(false);
                }
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
     * Ligne du devis pour une ligne du panier : le produit au tarif public
     * (pièces au m² comprises), avec ses finitions ; un « Autre projet » ou
     * un produit impossible à chiffrer devient une ligne libre à 0, que
     * l'équipe chiffre (le devis ne peut pas être enregistré sans prix).
     * L'observation de la ligne dit d'où elle vient et nomme son fichier.
     */
    public static function ligneDuPanier(DemandeDevisLigne $l, ?float $espacementPiecesM2 = null): DevisDetails
    {
        $note = [\sprintf('Ligne %d de la demande %s du site.', $l->getPosition(), $l->getDemande()?->getNumero() ?? '')];
        if (null !== $l->getQuantiteM2()) {
            $note[] = \sprintf('Le visiteur a demandé %s m².', number_format($l->getQuantiteM2(), 2, ',', ' '));
        }
        if (null !== $l->getPrixIndicatif()) {
            $note[] = \sprintf('Prix indicatif montré sur le site : %s F.', number_format($l->getPrixIndicatif(), 0, ',', ' '));
        }
        if (null !== $l->getFichier()) {
            $note[] = 'Fichier joint : '.$l->getFichier()->getNomOriginal().' (Ventes › Demandes du site).';
        }

        $config = $l->getConfiguration();
        if (null !== $config) {
            try {
                $ligne = self::ligneDevis($config, (int) ($l->getQuantite() ?? max(1, $config->getQuantiteMinimale())), $l->getLargeurCm(), $l->getHauteurCm(), self::finitionsDeLigne($l));
                if (null !== $espacementPiecesM2 && FormulaireDemande::piecesAuM2Possible($config)) {
                    $ligne->choisirPiecesAuM2(true, $espacementPiecesM2)->calculerTotaux(false);
                }

                return $ligne->setObservation(implode("\n", $note));
            } catch (\DomainException $e) {
                $note[] = 'À chiffrer : '.$e->getMessage();
            }
        } else {
            $note[] = 'À chiffrer par l\'équipe.';
        }

        $designation = null !== $config ? $l->getResume() : 'Autre projet : '.($l->getDescription() ?? 'voir le message');
        if (null === $config && null !== $l->getNomsFinitions()) {
            $designation .= ' · '.$l->getNomsFinitions();
        }

        return (new DevisDetails())
            ->setTypeLigne(DevisDetails::TYPE_LIBRE)
            ->setModeConfiguration('manuel')
            ->setDesignation(mb_substr($designation, 0, 255))
            ->setQuantite(max(1, (int) ($l->getQuantite() ?? 1)))
            ->setPrixUnitaire(0)
            ->setTva(0)
            ->setObservation(implode("\n", $note))
            ->calculerTotaux(false);
    }

    /**
     * Finitions d'une ligne du panier (par l'identifiant public de leur
     * finition) retrouvées dans celles que propose le produit.
     *
     * @return list<int>
     */
    public static function finitionsDeLigne(DemandeDevisLigne $l): array
    {
        $ids = [];
        foreach ($l->getConfiguration()?->getConfigurationFinitions() ?? [] as $cf) {
            if (null !== $cf->getId() && null !== $cf->getFinition() && \in_array($cf->getFinition()->getPublicId()->toRfc4122(), $l->getFinitions(), true)) {
                $ids[] = $cf->getId();
            }
        }

        return $ids;
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
        if ($demande->estPanier()) {
            $prix = array_map(static fn (DemandeDevisLigne $l): ?int => $l->getPrixIndicatif(), $demande->getLignes()->toArray());
            $total = PanierDevis::total($prix);
            $lignes[] = \sprintf('Panier de %d lignes, %d fichier%s joint%s (un par ligne au plus).', \count($prix), $demande->nombreFichiers(), $demande->nombreFichiers() > 1 ? 's' : '', $demande->nombreFichiers() > 1 ? 's' : '');
            if (null !== $total['total']) {
                $lignes[] = \sprintf('Total indicatif montré sur le site : %s F%s, à confirmer.', number_format($total['total'], 0, ',', ' '), $total['complet'] ? '' : ' (sans les lignes à chiffrer)');
            }
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

    public static function prospect(DemandeDevis $demande, ?Clients $client, \DateTimeImmutable $le, ?string $besoin): Prospect
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
