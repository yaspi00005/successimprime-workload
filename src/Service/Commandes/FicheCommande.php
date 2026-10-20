<?php

namespace App\Service\Commandes;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ContactClient;
use App\Entity\ControlePrePresse;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\OrdreProduction;
use App\Service\Caisse;
use App\Service\Messagerie\ResumeConversation;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fiche d'une commande côté admin (maquette DT02, 18/10/2026) : devis
 * d'origine, fichiers et BAT, étapes de production avec la personne et
 * la durée, carte du client (contact, solde impayé), dernier message de
 * la commande (ResumeConversation) et événements du BAT pour l'historique. Lecture seule ;
 * les actions restent sur leurs écrans (prépresse, caisse, messages).
 */
final class FicheCommande
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Caisse $caisse,
        private readonly ResumeConversation $conversations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(Commandes $commande, \DateTimeImmutable $maintenant, bool $voirPaiements): array
    {
        $lignes = array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $d): bool => CommandesDetails::PRODUCTION_ANNULEE !== $d->getStatutProduction()
        ));
        $plusieurs = \count($lignes) > 1;
        $controles = [];
        foreach ($lignes as $ligne) {
            $controles[$ligne->getId()] = self::dernierControle($ligne);
        }
        $conceptions = $this->conceptions(array_values(array_filter($controles)));
        $ordres = $this->ordres($lignes);
        $bl = $this->em->getRepository(BonLivraison::class)->findOneBy(['commande' => $commande], ['id' => 'DESC']);
        if (BonLivraison::STATUT_ANNULE === $bl?->getStatut()) {
            $bl = null;
        }

        [$fichiers, $evenementsBat] = $this->fichiers($lignes, $controles, $conceptions, $plusieurs);

        return [
            'devis' => $this->em->getRepository(Devis::class)->findOneBy(['commande' => $commande], ['id' => 'DESC']),
            'fichiers' => $fichiers,
            'evenementsBat' => $evenementsBat,
            'ligneBat' => self::ligneBatAEnvoyer($lignes, $controles),
            'lignePrepresse' => array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => $l->isPrePresseNecessaire() || null !== $l->getControlesPrePresse()->first()))[0] ?? null,
            'production' => $this->production($commande, $lignes, $controles, $conceptions, $ordres, $bl, $plusieurs, $maintenant),
            'contact' => $this->contact($commande),
            'solde' => $voirPaiements ? $this->solde($commande, $maintenant) : null,
            // Conversation de la commande (docs/messagerie-clients.md) : montants masqués sans le droit de voir les paiements.
            'conversation' => $this->conversations->pour($commande, !$voirPaiements, $maintenant),
        ];
    }

    /**
     * Fichiers du client puis BAT envoyés (n°1, n°2…) avec leur statut :
     * refusé (et la demande du client), validé, en attente de réponse.
     *
     * @param list<CommandesDetails>               $lignes
     * @param array<int, ?ControlePrePresse>        $controles
     * @param array<int, Conception>                $conceptions
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{date: \DateTimeInterface, texte: string}>}
     */
    private function fichiers(array $lignes, array $controles, array $conceptions, bool $plusieurs): array
    {
        $liste = [];
        $evenements = [];
        foreach ($lignes as $ligne) {
            $suffixe = $plusieurs ? ' · '.TableauProduction::titre($ligne) : '';
            foreach ($ligne->getFichiers() as $fichier) {
                if (!$fichier->isActif() || !$fichier->estUploadTermine() || CommandeDetailFichier::ORIGINE_CLIENT !== $fichier->getOrigine()) {
                    continue;
                }
                $liste[] = [
                    'type' => 'client',
                    'nom' => $fichier->getNomOriginal(),
                    'detail' => ($fichier->estDecoupe() ? 'fichier de découpe' : 'fichier client').' · '.$fichier->getCreeLe()->format('d/m').$suffixe,
                    'statut' => null,
                    'fichier' => $fichier,
                ];
            }

            $ctl = $controles[$ligne->getId()] ?? null;
            if (null === $ctl) {
                continue;
            }
            $conception = $conceptions[$ctl->getId()] ?? null;
            $bats = [];
            foreach ($conception?->getEvenements() ?? [] as $e) {
                $le = $e['le'] instanceof \DateTimeImmutable ? $e['le'] : new \DateTimeImmutable((string) $e['le']);
                if ('bat' === $e['type']) {
                    $bats[] = ['le' => $le, 'statut' => 'attente', 'texte' => null];
                } elseif ([] !== $bats && \in_array($e['type'], ['retour', 'erreur'], true)) {
                    $n = \count($bats) - 1;
                    $bats[$n]['statut'] = 'refuse';
                    // Le détail est « « texte » · demande du client » : seul le texte du client compte.
                    $texte = explode(' · ', (string) ($e['detail'] ?? ''))[0];
                    $bats[$n]['texte'] = str_starts_with($texte, '«') ? $texte : null;
                    $bats[$n]['refuseLe'] = $le;
                    if ('retour' === $e['type']) {
                        $evenements[] = ['date' => $le, 'texte' => \sprintf('Le client a refusé le BAT n°%d%s%s', $n + 1, null !== $bats[$n]['texte'] ? ' : '.$bats[$n]['texte'] : '', $suffixe)];
                    }
                } elseif ([] !== $bats && 'valide' === $e['type']) {
                    $n = \count($bats) - 1;
                    $bats[$n]['statut'] = 'valide';
                    $bats[$n]['valideLe'] = $le;
                }
            }
            // BAT d'avant le suivi des conceptions : seulement l'état du contrôle.
            if ([] === $bats && (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut() || $ctl->isBatValide())) {
                $bats[] = ['le' => $ctl->getTraiteLe() ?? $ctl->getCreeLe(), 'statut' => $ctl->isBatValide() ? 'valide' : 'attente', 'texte' => null, 'valideLe' => $ctl->getValideLe()];
            }
            if ($ctl->isBatValide() && [] !== $bats) {
                $bats[\count($bats) - 1]['statut'] = 'valide';
                $bats[\count($bats) - 1]['valideLe'] ??= $ctl->getValideLe();
            }
            foreach ($bats as $i => $bat) {
                $detail = match ($bat['statut']) {
                    'refuse' => 'refusé'.(null !== $bat['texte'] ? ' : '.$bat['texte'] : ''),
                    'valide' => 'validé'.(null !== ($bat['valideLe'] ?? null) ? ' le '.$bat['valideLe']->format('d/m · H:i') : ''),
                    default => 'envoyé au client',
                };
                $liste[] = [
                    'type' => 'bat',
                    'nom' => \sprintf('BAT n°%d', $i + 1),
                    'detail' => $bat['le']->format('d/m · H:i').' · '.$detail.$suffixe,
                    'statut' => match ($bat['statut']) {
                        'refuse' => ['libelle' => 'Refusé', 'couleur' => 'rouge'],
                        'valide' => ['libelle' => 'Validé', 'couleur' => 'vert'],
                        default => ['libelle' => 'En attente', 'couleur' => 'orange'],
                    },
                    'fichier' => null,
                ];
            }
        }

        return [$liste, $evenements];
    }

    /**
     * Étapes de production : PAO et BAT, impression, finitions, puis
     * livraison ou retrait, chacune avec la personne et la durée ou la
     * date prévue ; « faite », « en_cours » ou « a_venir ».
     *
     * @param list<CommandesDetails>          $lignes
     * @param array<int, ?ControlePrePresse>   $controles
     * @param array<int, Conception>           $conceptions
     * @param array<int, OrdreProduction>      $ordres
     *
     * @return list<array<string, mixed>>
     */
    private function production(Commandes $commande, array $lignes, array $controles, array $conceptions, array $ordres, ?BonLivraison $bl, bool $plusieurs, \DateTimeImmutable $maintenant): array
    {
        $etapes = [];
        $sansProduction = static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_NON_REQUISE === $l->getStatutProduction() || !$l->isProductionNecessaire();
        foreach ($lignes as $ligne) {
            if ($sansProduction($ligne) && !$ligne->isPrePresseNecessaire()) {
                continue;
            }
            $titre = $plusieurs ? ' · '.TableauProduction::titre($ligne) : '';
            $statut = $ligne->getStatutProduction();
            $imprimee = \in_array($statut, [CommandesDetails::PRODUCTION_TERMINEE, CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_EN_LIVRAISON, CommandesDetails::PRODUCTION_LIVREE], true);

            if ($ligne->isPrePresseNecessaire()) {
                $ctl = $controles[$ligne->getId()] ?? null;
                $conception = null !== $ctl ? ($conceptions[$ctl->getId()] ?? null) : null;
                $valide = $ligne->isBatValide() || $ctl?->isBatValide() || \in_array($ctl?->getStatut(), [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true);
                $temps = null !== $conception && $conception->secondesA($maintenant) > 0 ? self::duree($conception->secondesA($maintenant)) : null;
                $detail = match (true) {
                    $valide || $imprimee || CommandesDetails::PRODUCTION_EN_COURS === $statut => 'BAT validé'.(null !== ($conception?->getValideLe() ?? $ctl?->getValideLe()) ? ' le '.($conception?->getValideLe() ?? $ctl->getValideLe())->format('d/m') : '').(null !== $temps ? ' · '.$temps : ''),
                    ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl?->getStatut() => 'BAT envoyé, réponse du client attendue'.(null !== $temps ? ' · '.$temps : ''),
                    null !== $conception && $conception->enCours() => 'en cours · '.($temps ?? 'démarré'),
                    \in_array($ctl?->getStatut(), [ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_REJETE], true) || $ctl?->isCorrectionNecessaire() => 'correction à faire'.(null !== $temps ? ' · '.$temps : ''),
                    null !== $conception?->getEcheanceBat() => 'à faire · BAT prévu '.$conception->getEcheanceBat()->format('d/m H:i'),
                    default => 'à faire',
                };
                $etapes[] = [
                    'titre' => 'PAO · BAT'.$titre,
                    'personne' => SuiviCommercial::personne($conception?->getGraphiste() ?? $ctl?->getTraitePar() ?? $ctl?->getControlePar()),
                    'detail' => $detail,
                    'etat' => $valide || $imprimee || CommandesDetails::PRODUCTION_EN_COURS === $statut ? 'faite' : (null !== $ctl ? 'en_cours' : 'a_venir'),
                ];
            }

            if ($sansProduction($ligne)) {
                continue;
            }
            $ordre = $ordres[$ligne->getId()] ?? null;
            $machine = $ordre?->getMachine()?->getNom() ?? $ligne->getMachine()?->getNom() ?? $ligne->getTypeImpression()?->getNom();
            $enCours = CommandesDetails::PRODUCTION_EN_COURS === $statut || (null !== $ordre && ($ordre->estEnCours() || $ordre->estEnPause()));
            $fin = $ordre?->getTermineLe() ?? $ligne->getProductionTermineeLe();
            $debut = $ordre?->getDemarreLe() ?? $ligne->getProductionDebuteLe();
            $detail = match (true) {
                $imprimee => 'terminée'.(null !== $fin ? ' le '.$fin->format('d/m · H:i') : '').(null !== $debut && null !== $fin && $fin > $debut ? ' · '.self::duree($fin->getTimestamp() - $debut->getTimestamp()) : ''),
                $enCours => 'en cours'.(null !== $ordre && ($ordre->getQuantite() ?? 0) > 0 ? ' · '.$ordre->getQuantiteProduite().'/'.$ordre->getQuantite().' faits' : '').(null !== $debut ? ' · depuis '.self::duree(max(0, $maintenant->getTimestamp() - $debut->getTimestamp())) : ''),
                null !== $ordre?->getDebutPrevuLe() => 'prévue '.$ordre->getDebutPrevuLe()->format('d/m · H:i'),
                default => 'à planifier',
            };
            $etapes[] = [
                'titre' => 'Impression'.(null !== $machine ? ' · '.$machine : '').$titre,
                'personne' => SuiviCommercial::personne($ordre?->getOperateur() ?? $ligne->getProductionDebuteePar()),
                'detail' => $detail,
                'etat' => $imprimee ? 'faite' : ($enCours ? 'en_cours' : 'a_venir'),
            ];

            $finitions = array_values(array_filter(array_map(static fn ($f): ?string => $f->getNomFinition(), $ligne->getFinitions()->toArray())));
            if ([] !== $finitions) {
                $etapes[] = [
                    'titre' => 'Finition · '.implode(', ', \array_slice($finitions, 0, 3)).(\count($finitions) > 3 ? '…' : ''),
                    'personne' => null,
                    'detail' => $imprimee ? 'faite' : 'après l\'impression',
                    'etat' => $imprimee ? 'faite' : 'a_venir',
                ];
            }
        }

        if ([] === $lignes) {
            return $etapes;
        }
        $livree = 'livree' === $commande->getStatutTravaux();
        $client = $commande->getClients()?->getNomComplet() ?? 'client';
        if ($commande->estALivrer() || null !== $bl) {
            $quand = $bl?->getLivreLe() ?? $bl?->getPrevuLe() ?? $commande->getDateLivraison();
            $etapes[] = [
                'titre' => 'Livraison · '.$client,
                'personne' => SuiviCommercial::personne($bl?->getLivreur() ?? $bl?->getLivrePar()),
                'detail' => match (true) {
                    $livree || BonLivraison::STATUT_LIVRE === $bl?->getStatut() => 'livrée'.(null !== $bl?->getLivreLe() ? ' le '.$bl->getLivreLe()->format('d/m · H:i') : ''),
                    null !== $bl?->getEnRouteLe() => 'partie à '.$bl->getEnRouteLe()->format('H:i'),
                    default => (null !== $quand ? 'prévue '.$quand->format('d/m') : 'à planifier').($bl?->getCreneau() ? ' · '.$bl->getCreneau() : ''),
                },
                'etat' => $livree || BonLivraison::STATUT_LIVRE === $bl?->getStatut() ? 'faite' : (null !== $bl?->getEnRouteLe() ? 'en_cours' : 'a_venir'),
            ];
        } else {
            $etapes[] = [
                'titre' => 'Retrait à l\'imprimerie',
                'personne' => null,
                'detail' => $livree ? 'retirée' : (null !== $commande->getDateLivraison() ? 'prévu le '.$commande->getDateLivraison()->format('d/m') : 'date à fixer'),
                'etat' => $livree ? 'faite' : 'a_venir',
            ];
        }

        return $etapes;
    }

    /**
     * Personne à joindre : celle qui a commandé, sinon le contact principal
     * de la fiche, sinon le client lui-même.
     *
     * @return array{nom: string, telephone: ?string, initiales: string}|null
     */
    private function contact(Commandes $commande): ?array
    {
        $client = $commande->getClients();
        if (null === $client) {
            return null;
        }
        $contacts = $this->em->getRepository(ContactClient::class)->findBy(['client' => $client], ['id' => 'ASC']);
        $principal = null;
        foreach ($contacts as $c) {
            if (null !== $commande->getDemandeePar() && mb_strtolower($c->getNom()) === mb_strtolower($commande->getDemandeePar())) {
                $principal = $c;
                break;
            }
            if (null === $principal && 'principal' === $c->getRole()) {
                $principal = $c;
            }
        }
        $principal ??= $contacts[0] ?? null;
        if (null !== $principal) {
            return ['nom' => $principal->getNom(), 'telephone' => $principal->getTelephoneAffiche() ?? $client->getTelephone(), 'initiales' => SuiviCommercial::initiales($principal->getNom())];
        }
        if (null !== $commande->getDemandeePar()) {
            return ['nom' => $commande->getDemandeePar(), 'telephone' => $client->getTelephone(), 'initiales' => SuiviCommercial::initiales($commande->getDemandeePar())];
        }

        // Un particulier sans autre contact : son numéro, sans répéter son nom.
        return null === $client->getTelephone() ? null : ['nom' => 'Téléphone', 'telephone' => $client->getTelephone(), 'initiales' => SuiviCommercial::initiales($client->getNomComplet())];
    }

    /**
     * Ce que le client doit sur toutes ses commandes (celle-ci comprise) et
     * la première facture échue. Seulement avec le droit Paiements.
     *
     * @return array{montant: int, commandes: int, echue: ?string}|null
     */
    private function solde(Commandes $commande, \DateTimeImmutable $maintenant): ?array
    {
        $client = $commande->getClients();
        if (null === $client) {
            return null;
        }
        $impayees = $this->caisse->commandesImpayees($client);
        $montant = array_sum(array_map(static fn (array $l): int => (int) $l['reste'], $impayees));
        if ($montant <= 0) {
            return null;
        }
        $echue = null;
        $aujourdhui = $maintenant->setTime(0, 0);
        foreach ($impayees as $l) {
            foreach ($l['commande']->getFactures() as $facture) {
                /** @var Factures $facture */
                if (!$facture->estAnnulee() && null !== $facture->getDateEcheance() && $facture->getDateEcheance() < $aujourdhui) {
                    $echue ??= $facture->getNumero();
                }
            }
        }

        return ['montant' => $montant, 'commandes' => \count($impayees), 'echue' => $echue];
    }

    /**
     * La ligne dont le BAT est à envoyer (prépresse faite ou à faire, pas
     * encore envoyé ni validé), pour le bouton « Envoyer le BAT ».
     *
     * @param list<CommandesDetails>        $lignes
     * @param array<int, ?ControlePrePresse> $controles
     */
    private static function ligneBatAEnvoyer(array $lignes, array $controles): ?CommandesDetails
    {
        foreach ($lignes as $ligne) {
            if (!$ligne->isPrePresseNecessaire() || $ligne->isBatValide() || \in_array($ligne->getStatutProduction(), [CommandesDetails::PRODUCTION_EN_COURS, CommandesDetails::PRODUCTION_TERMINEE, CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_EN_LIVRAISON, CommandesDetails::PRODUCTION_LIVREE], true)) {
                continue;
            }
            $ctl = $controles[$ligne->getId()] ?? null;
            if (null !== $ctl && (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut() || $ctl->isBatValide() || \in_array($ctl->getStatut(), [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true))) {
                continue;
            }

            return $ligne;
        }

        return null;
    }

    /**
     * @param list<ControlePrePresse> $controles
     *
     * @return array<int, Conception>
     */
    private function conceptions(array $controles): array
    {
        if ([] === $controles) {
            return [];
        }
        $parControle = [];
        foreach ($this->em->getRepository(Conception::class)->findBy(['controle' => $controles]) as $conception) {
            $parControle[$conception->getControle()->getId()] = $conception;
        }

        return $parControle;
    }

    /**
     * Dernier ordre de production non annulé de chaque ligne.
     *
     * @param list<CommandesDetails> $lignes
     *
     * @return array<int, OrdreProduction>
     */
    private function ordres(array $lignes): array
    {
        if ([] === $lignes) {
            return [];
        }
        $parLigne = [];
        foreach ($this->em->getRepository(OrdreProduction::class)->findBy(['commandeDetail' => $lignes], ['id' => 'ASC']) as $ordre) {
            if (!$ordre->estAnnule()) {
                $parLigne[$ordre->getCommandeDetail()->getId()] = $ordre;
            }
        }

        return $parLigne;
    }

    private static function dernierControle(CommandesDetails $ligne): ?ControlePrePresse
    {
        $dernier = null;
        foreach ($ligne->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier;
    }

    /** « 12 min », « 1 h 10 », « 3 j ». */
    public static function duree(int $secondes): string
    {
        $minutes = intdiv($secondes, 60);

        return match (true) {
            $minutes < 60 => max(1, $minutes).' min',
            $minutes < 48 * 60 => intdiv($minutes, 60).' h'.(0 !== $minutes % 60 && $minutes < 10 * 60 ? ' '.str_pad((string) ($minutes % 60), 2, '0', \STR_PAD_LEFT) : ''),
            default => intdiv($minutes, 1440).' j',
        };
    }
}
