<?php

namespace App\Service\Conception;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\ControlePrePresse;
use App\Entity\DemandeConception;
use App\Entity\ImageIa;
use App\Entity\ProduitConfiguration;
use App\Entity\SupportConception;
use App\Entity\TransactionOrangeMoney;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\SuspensionsEspace;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parcours de la demande de conception (maquettes DC01 à DC05,
 * docs/demande-conception.md) : brouillon tenu entre les étapes, envoi à
 * l'atelier, demande de paiement à un payeur de l'entreprise, commande
 * d'impression d'un visuel IA, suivi pour le fil du client.
 */
final class DemandesConception
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglagesConception $reglages,
        private readonly CommandesConception $commandes,
        private readonly CompteEntreprise $compteEntreprise,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly OrangeSmsService $sms,
        // Accès suspendu par l'imprimerie : pas de SMS (SuspensionsEspace).
        private readonly ?SuspensionsEspace $suspensions = null,
    ) {
    }

    /** @return list<SupportConception> */
    public function supports(bool $actifsSeulement = true): array
    {
        return array_values($this->em->getRepository(SupportConception::class)->findBy($actifsSeulement ? ['actif' => true] : [], ['ordre' => 'ASC', 'id' => 'ASC']));
    }

    public function support(int $id): ?SupportConception
    {
        $s = $this->em->find(SupportConception::class, $id);

        return $s instanceof SupportConception && $s->isActif() ? $s : null;
    }

    /** @return list<DemandeConception> les demandes du client, les plus récentes d'abord */
    public function demandes(Clients $client, int $nombre = 60): array
    {
        return array_values($this->em->getRepository(DemandeConception::class)->findBy(['client' => $client], ['id' => 'DESC'], $nombre));
    }

    /** Une demande du client, ou null si elle est à un autre. */
    public function trouver(Clients $client, string $publicId): ?DemandeConception
    {
        foreach ($this->em->getRepository(DemandeConception::class)->findBy(['client' => $client], ['id' => 'DESC'], 200) as $d) {
            if ((string) $d->getPublicId() === $publicId) {
                return $d;
            }
        }

        return null;
    }

    /** « Mes demandes · 1 en cours » : ni brouillon, ni terminée, ni annulée. */
    public function enCours(Clients $client): int
    {
        return \count(array_filter($this->demandes($client), static fn (DemandeConception $d): bool => !\in_array($d->getStatut(), [DemandeConception::BROUILLON, DemandeConception::TERMINEE, DemandeConception::ANNULEE], true)));
    }

    /**
     * Nouvelle demande pour ce support (étape 1).
     *
     * @throws \DomainException
     */
    public function commencer(CompteClient $compte, SupportConception $support, \DateTimeImmutable $le): DemandeConception
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans client.');
        $ouvertes = $this->em->getRepository(DemandeConception::class)->count(['client' => $client, 'statut' => DemandeConception::OUVERTES]);
        if ($ouvertes >= DemandeConception::OUVERTES_MAX) {
            throw new \DomainException(\sprintf('Vous avez déjà %d demandes en préparation : terminez-en ou supprimez-en une.', $ouvertes));
        }
        $demande = DemandeConception::nouvelle($client, $compte, $support, $le);
        $demande->setEtape(2);
        $this->em->persist($demande);
        $this->em->flush();
        $demande->setNumero(\sprintf('DC-%s-%03d', $le->format('Y'), $demande->getId()));
        $this->em->flush();

        return $demande;
    }

    /**
     * Enregistre les informations (étape 2, gardées entre les étapes).
     *
     * @param array<string, mixed>        $saisie
     * @param list<CommandeDetailFichier> $elements fichiers du client, déjà vérifiés
     *
     * @return array<string, string> erreurs par champ
     */
    public function enregistrer(DemandeConception $demande, array $saisie, array $elements, \DateTimeImmutable $le): array
    {
        $erreurs = [];
        $texte = static fn (string $cle): ?string => \is_scalar($saisie[$cle] ?? null) ? (string) $saisie[$cle] : null;
        $demande->ecrireContenu($texte('titre'), $texte('sous_titre'), $texte('date_heure'), $texte('coordonnees'), $texte('consignes'));
        $demande->remplacerElements($elements);
        $couleurs = \is_array($saisie['couleurs'] ?? null) ? array_values(array_filter($saisie['couleurs'], 'is_string')) : [];
        $demande->setCouleurs($couleurs);
        $demande->setStyles(\is_array($saisie['styles'] ?? null) ? array_values(array_filter($saisie['styles'], 'is_string')) : []);
        $quantite = preg_replace('/\D+/', '', (string) $texte('quantite'));
        $demande->setQuantite('' !== $quantite ? (int) mb_substr($quantite, 0, 6) : null);
        $date = trim((string) $texte('pour_quand'));
        if ('' === $date) {
            $demande->setPourQuand(null);
        } elseif (false !== ($jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) && $jour >= $le->setTime(0, 0) && $jour <= $le->modify('+2 years')) {
            $demande->setPourQuand($jour);
        } else {
            $erreurs['pour_quand'] = 'Date à venir attendue.';
        }
        try {
            $demande->setExempleLien($texte('exemple'));
        } catch (\DomainException $e) {
            $erreurs['exemple'] = $e->getMessage();
        }
        if (\in_array($saisie['choix'] ?? null, [DemandeConception::GRAPHISTE, DemandeConception::IA], true) && DemandeConception::A_PAYER !== $demande->getStatut()) {
            $demande->setChoix((string) $saisie['choix']);
        }
        $demande->setEtape(max($demande->getEtape(), null !== $demande->getChoix() ? 3 : 2));
        $demande->toucher($le);
        $this->em->flush();

        return $erreurs;
    }

    /**
     * Ce qu'il manque pour partir à l'atelier ou générer.
     *
     * @throws \DomainException
     */
    public static function verifierComplete(DemandeConception $demande): void
    {
        if (null === $demande->getTitre() && null === $demande->getConsignes()) {
            throw new \DomainException('Écrivez au moins le titre principal ou vos consignes.');
        }
    }

    /**
     * Sans paiement en ligne (clés Orange Money absentes, ou acompte à 0) :
     * la demande part à l'atelier, l'acompte se règle au numéro marchand.
     *
     * @throws \DomainException
     */
    public function envoyerSansPaiementEnLigne(DemandeConception $demande, CompteClient $compte, \DateTimeImmutable $le): Commandes
    {
        self::verifierComplete($demande);
        if (!$demande->estOuverte()) {
            throw new \DomainException('Cette demande est déjà partie à l\'atelier.');
        }

        return $this->commandes->envoyerAuGraphiste($demande, $compte, $le);
    }

    /**
     * Un demandeur prépare, un payeur paie : la demande attend, et les
     * payeurs sont prévenus par SMS (sans montant, jamais pour une fiche
     * de démonstration).
     *
     * @return list<string> noms des personnes qui peuvent payer
     *
     * @throws \DomainException
     */
    public function demanderPaiement(DemandeConception $demande, CompteClient $demandeur, \DateTimeImmutable $le): array
    {
        self::verifierComplete($demande);
        $client = $demandeur->getClient() ?? throw new \DomainException('Compte sans client.');
        $payeurs = $this->payeurs($client);
        if ([] === $payeurs) {
            throw new \DomainException('Personne ne peut payer pour votre entreprise : demandez à l\'administrateur du compte.');
        }
        $deja = DemandeConception::A_PAYER === $demande->getStatut();
        $demande->setChoix($demande->getChoix() ?? DemandeConception::GRAPHISTE)->setStatut(DemandeConception::A_PAYER)->toucher($le);
        $this->em->flush();
        if (!$deja && !NotificationsClient::estDemo($client)) {
            foreach (null !== $this->suspensions ? $this->suspensions->sansSuspendus($payeurs) : $payeurs as $p) {
                $this->sms->envoyerSms($p->getTelephone(), \sprintf('Succès Imprim : %s a préparé la demande de conception %s. À payer dans votre espace client.', $demandeur->getNomAffiche(), $demande->getNumero()), type: 'conception');
            }
        }

        return array_map(static fn (CompteClient $c): string => $c->getNomAffiche(), $payeurs);
    }

    /**
     * Comptes actifs qui peuvent payer pour l'entreprise.
     *
     * @return list<CompteClient>
     */
    public function payeurs(Clients $client): array
    {
        return array_values(array_filter(
            $this->compteEntreprise->utilisateurs($client),
            static fn (CompteClient $c): bool => DroitsConception::peutPayer($c),
        ));
    }

    /** Brouillon retiré par le client (avant l'envoi seulement). */
    public function supprimer(DemandeConception $demande): void
    {
        if (!$demande->estOuverte()) {
            throw new \DomainException('Cette demande est à l\'atelier : elle ne se supprime plus.');
        }
        // Un paiement Orange Money en cours ou reçu garde la demande : l'argent doit retrouver sa demande.
        foreach ($this->em->getRepository(TransactionOrangeMoney::class)->findBy(['demandeConception' => $demande]) as $t) {
            if (TransactionOrangeMoney::REUSSIE === $t->getStatut() || (TransactionOrangeMoney::INITIEE === $t->getStatut() && $t->getCreeLe() > new \DateTimeImmutable('-30 minutes'))) {
                throw new \DomainException('Un paiement Orange Money est en cours pour cette demande : elle ne se supprime pas.');
            }
        }
        $this->em->remove($demande);
        $this->em->flush();
    }

    /** Produit du catalogue qui imprime le support (le premier publié de sa liste). */
    public function configurationImpression(DemandeConception $demande): ?ProduitConfiguration
    {
        foreach ($demande->getSupport()?->getConfigurations() ?? [] as $c) {
            if (self::commandableEnLigne($c)) {
                return $c;
            }
        }

        return null;
    }

    /** Mêmes conditions que le catalogue de la commande en ligne (NouvelleCommande::produits). */
    public static function commandableEnLigne(ProduitConfiguration $c): bool
    {
        $produit = $c->getProduit();
        $categorie = $produit?->getCategorieProduit();

        return $c->isActive() && null !== $produit && $produit->estEnEspaceClient()
            && (null === $categorie || true === $categorie->isPublie()) && ($c->getPrixBase() ?? 0) > 0;
    }

    /**
     * Prix indicatif de l'impression (même calcul que la commande en ligne).
     *
     * @return array{libelle: string, total: int}|null
     */
    public function estimationImpression(DemandeConception $demande, bool $b2b): ?array
    {
        $config = $this->configurationImpression($demande);
        if (null === $config) {
            return null;
        }
        $quantite = max($demande->getQuantite() ?? 1, max(1, $config->getQuantiteMinimale()));
        try {
            $ligne = NouvelleCommande::construireLigne($config, $quantite, $demande->getLargeurCm(), $demande->getHauteurCm(), [], $b2b);
        } catch (\DomainException) {
            return null;
        }

        return ['libelle' => \sprintf('Impression %d %s', $quantite, mb_strtolower($demande->getSupportNom()).($quantite > 1 && !str_ends_with($demande->getSupportNom(), 's') ? 's' : '')), 'total' => $ligne->getTotalTtc()];
    }

    /**
     * « Choisir et commander l'impression » (maquette DC04) : commande en
     * ligne « à confirmer » par l'atelier, prix recalculé ici, image IA
     * choisie en fichier du client, finalisation par un graphiste en
     * option au prix des réglages.
     *
     * @throws \DomainException
     */
    public function commanderImpression(DemandeConception $demande, CompteClient $compte, ImageIa $image, CommandeDetailFichier $fichier, bool $finalisation, \DateTimeImmutable $le): Commandes
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans client.');
        if (!$demande->estOuverte() || $image->getDemande() !== $demande || !$image->estPrete()) {
            throw new \DomainException('Cette image ne peut plus être commandée.');
        }
        $note = \sprintf("Visuel IA de la demande %s (image %d). Remplacement des textes par le graphiste avant impression.\n%s", $demande->getNumero(), $image->getNumero(), CommandesConception::brief($demande));
        $config = $this->configurationImpression($demande);
        $quantite = max(1, $demande->getQuantite() ?? 1);
        if (null !== $config) {
            $quantite = max($quantite, max(1, $config->getQuantiteMinimale()));
            $commande = $this->nouvelleCommande->commander($client, $config, $quantite, $demande->getLargeurCm(), $demande->getHauteurCm(), [], [$fichier], $note, $le);
        } else {
            // Support sans produit du catalogue : l'atelier met le prix en confirmant.
            $this->nouvelleCommande->verifierEnAttente($client);
            $ligne = (new CommandesDetails())
                ->setTypeLigne(CommandesDetails::TYPE_LIBRE)
                ->setDesignation(mb_substr('Impression · '.$demande->titreSupport().' (prix confirmé par l\'atelier)', 0, 255))
                ->setModeCalcul('unite')->setQuantite($quantite)->setPrixUnitaire(0)->setTva(0)
                ->setDemandeClient($note)->setPrePresseNecessaire(true);
            $ligne->calculerTotaux();
            $commande = (new Commandes())->setClients($client)->setDateCommande($le)->setCanal(Commandes::CANAL_EN_LIGNE)->setStatut(false)->setEtat(true);
            $commande->addCommandesDetail($ligne);
            $commande->recalculerTotaux();
            $this->em->persist($commande);
            $this->em->flush();
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $le->format('m-Y')));
            $fichier->setCommandeDetail($ligne);
        }
        if ($finalisation && $this->reglages->finalisation() > 0) {
            $ligne = (new CommandesDetails())
                ->setTypeLigne(CommandesDetails::TYPE_LIBRE)
                ->setDesignation('Finalisation graphiste · visuel IA')
                ->setModeCalcul('forfait')->setQuantite(1)->setPrixUnitaire($this->reglages->finalisation())->setTva(0)
                ->setDemandeClient('Reprendre le visuel IA choisi : textes, logo et photos du client, fichier prêt pour l\'impression.')
                ->setPrePresseNecessaire(true)->setProductionNecessaire(false);
            $ligne->calculerTotaux($client->isB2B());
            $commande->addCommandesDetail($ligne);
            $this->em->persist($ligne);
            $commande->recalculerTotaux();
        }
        $this->compteEntreprise->preparerCommande($compte, $commande, null, null);
        $demande->setChoix(DemandeConception::IA)->setCommande($commande)->setImageChoisie($fichier)->setFinalisationGraphiste($finalisation);
        $demande->envoyer(DemandeConception::COMMANDEE, $le);
        $this->em->flush();

        return $commande;
    }

    /**
     * Au local : le statut suit le BAT de la commande (envoyé, validé).
     */
    public function suivreBat(DemandeConception $demande, \DateTimeImmutable $le): bool
    {
        return $demande->suivreBat(self::dernierControle($demande)?->getStatut(), $le);
    }

    /**
     * Événements du fil du client (EspaceClient::notifications) : prise en
     * charge, terminée, annulée. Sans montant.
     *
     * @return list<array<string, mixed>> au format d'EspaceClient::evenement
     */
    public static function fil(EntityManagerInterface $em, Clients $client): array
    {
        $fil = [];
        foreach ($em->getRepository(DemandeConception::class)->findBy(['client' => $client], ['id' => 'DESC'], 20) as $d) {
            $lien = ['app_espace_conception_demande', ['id' => (string) $d->getPublicId()]];
            $plus = ['cible' => ['demande' => (string) $d->getPublicId(), 'commande' => null !== $d->getCommande() ? (string) $d->getCommande()->getPublicId() : null]];
            if (null !== $d->getPriseEnChargeLe()) {
                $fil[] = EspaceClient::evenement('conception', 'Demande de conception prise en charge', \sprintf('%s · un graphiste s\'en occupe', $d->titreSupport()), $d->getPriseEnChargeLe(), $lien, $d->getNumero(), $plus);
            }
            if (null !== $d->getTermineeLe()) {
                $annulee = DemandeConception::ANNULEE === $d->getStatut();
                $fil[] = EspaceClient::evenement('conception', $annulee ? 'Demande de conception annulée' : 'Conception terminée', \sprintf('%s · %s', $d->titreSupport(), $annulee ? 'annulée par l\'atelier' : 'BAT validé'), $d->getTermineeLe(), $lien, $d->getNumero(), $plus);
            }
        }

        return $fil;
    }

    /**
     * Étapes montrées au client (maquette DC01) : faite, en cours, à venir.
     *
     * @return list<array{numero: int, libelle: string, etat: string}>
     */
    public static function etapes(int $courante): array
    {
        $etapes = [];
        foreach ([1 => 'Support', 2 => 'Informations', 3 => 'Graphiste ou IA', 4 => 'Paiement'] as $n => $libelle) {
            $etapes[] = ['numero' => $n, 'libelle' => $libelle, 'etat' => $n < $courante ? 'faite' : ($n === $courante ? 'en_cours' : 'a_venir')];
        }

        return $etapes;
    }

    /** Dernier BAT de la commande, pour le suivi au local. */
    public static function dernierControle(DemandeConception $demande): ?ControlePrePresse
    {
        $dernier = null;
        foreach ($demande->getCommande()?->getCommandesDetails() ?? [] as $ligne) {
            foreach ($ligne->getControlesPrePresse() as $ctl) {
                if (null === $dernier || $ctl->getId() > $dernier->getId()) {
                    $dernier = $ctl;
                }
            }
        }

        return $dernier;
    }
}
