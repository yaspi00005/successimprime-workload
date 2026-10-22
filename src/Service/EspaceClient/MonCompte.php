<?php

namespace App\Service\EspaceClient;

use App\Entity\AdresseLivraison;
use App\Entity\AppareilClient;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\PropositionClient;
use App\Entity\ReclamationClient;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mon compte (maquette EC04) : carte Entreprise (raison sociale et NIF
 * proposés à l'atelier, jamais appliqués en ligne), « Facturé par »,
 * conditions commerciales, et « Télécharger mes données ».
 *
 * L'export ne contient que ce que la personne voit déjà dans son espace :
 * rien d'un autre client, aucun champ interne (coûts, notes, agents), pas
 * de facture ni de montant pour qui ne paie pas.
 */
final class MonCompte
{
    /** Une proposition refusée se montre encore ce nombre de jours. */
    public const REFUS_VISIBLE_JOURS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntrepriseRepository $entreprises,
        private readonly EspaceClient $espace,
        private readonly FichiersClient $fichiers,
        private readonly MessagesClient $messages,
        private readonly CompteEntreprise $compteEntreprise,
    ) {
    }

    /** Nom de l'entité qui facture le client (nom commercial d'abord). */
    public function facturePar(Clients $client): ?string
    {
        $entite = $this->entreprises->parCode($client->getEntiteFacturation()) ?? ($this->entreprises->dansLOrdre()[0] ?? null);
        if (null === $entite) {
            return null;
        }

        return trim((string) $entite->getNomCommercial()) ?: $entite->getRaisonSociale();
    }

    /** « 30 jours fin de mois · remise 5 % », ou null si rien n'est fixé. */
    public static function conditions(Clients $client): ?string
    {
        $parts = [];
        if (null !== $client->getDelaiPaiementLabel()) {
            $parts[] = $client->getDelaiPaiementLabel();
        }
        $remise = $client->getRemiseHabituelle();
        if (null !== $remise && $remise > 0) {
            $parts[] = 'remise '.rtrim(rtrim(number_format($remise, 2, ',', ' '), '0'), ',').' %';
        }

        return [] === $parts ? null : implode(' · ', $parts);
    }

    /** Conditions commerciales : à la personne qui gère le compte et à qui paie. */
    public static function voitConditions(CompteClient $compte): bool
    {
        return $compte->peut(CompteClient::DROIT_ADMINISTRATEUR) || $compte->peut(CompteClient::DROIT_PAYEUR);
    }

    /** « code SMS au +223 7X XX XX XX » : le premier chiffre seulement. */
    public static function telephoneMasque(string $telephone): string
    {
        $chiffres = preg_replace('/\D/', '', $telephone) ?? '';
        $local = str_starts_with($chiffres, '223') && \strlen($chiffres) > 8 ? substr($chiffres, 3) : $chiffres;
        if ('' === $local) {
            return '';
        }
        $masque = $local[0].str_repeat('X', max(0, \strlen($local) - 1));

        return '+223 '.implode(' ', str_split($masque, 2));
    }

    /** Dernière proposition à montrer : en attente, ou refusée il y a peu. */
    public function proposition(Clients $client, \DateTimeImmutable $maintenant): ?PropositionClient
    {
        /** @var ?PropositionClient $derniere */
        $derniere = $this->em->getRepository(PropositionClient::class)->findOneBy(['client' => $client], ['creeLe' => 'DESC', 'id' => 'DESC']);
        if (null === $derniere || $derniere->estEnAttente()) {
            return $derniere;
        }
        $limite = $maintenant->modify('-'.self::REFUS_VISIBLE_JOURS.' days');

        return PropositionClient::REFUSEE === $derniere->getStatut() && ($derniere->getTraiteLe() ?? $derniere->getCreeLe()) >= $limite ? $derniere : null;
    }

    /**
     * Raison sociale et NIF changés dans Mon compte : une proposition part à
     * l'atelier, la fiche ne change pas avant sa validation.
     *
     * @param array<string, mixed> $saisie
     *
     * @throws \DomainException message à montrer
     */
    public function proposer(CompteClient $compte, array $saisie, \DateTimeImmutable $maintenant): PropositionClient
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans fiche client.');
        if (!$compte->peut(CompteClient::DROIT_ADMINISTRATEUR) || !CompteClient::clientAPlusieursComptes($client)) {
            throw new \DomainException('Seule une personne qui gère le compte de l\'entreprise peut demander ce changement.');
        }
        $champs = self::changements($client, $saisie);
        if ([] === $champs) {
            throw new \DomainException('Rien n\'a changé.');
        }
        $enAttente = $this->proposition($client, $maintenant);
        if (null !== $enAttente && $enAttente->estEnAttente()) {
            throw new \DomainException('Une demande de changement attend déjà l\'atelier. Elle sera traitée avant la suivante.');
        }

        $proposition = PropositionClient::nouvelle($client, $champs, $compte->getNomAffiche(), $maintenant);
        $this->em->persist($proposition);
        $this->em->flush();

        return $proposition;
    }

    /**
     * Ce qui change par rapport à la fiche, champ par champ (texte nettoyé,
     * longueur bornée). La raison sociale ne peut pas être vidée.
     *
     * @param array<string, mixed> $saisie
     *
     * @return array<string, array{avant: ?string, apres: ?string}>
     *
     * @throws \DomainException
     */
    public static function changements(Clients $client, array $saisie): array
    {
        $champs = [];
        foreach (PropositionClient::CHAMPS as $champ => [$libelle, $longueur]) {
            if (!\array_key_exists($champ, $saisie)) {
                continue;
            }
            $apres = trim((string) preg_replace('/\s+/u', ' ', (string) $saisie[$champ]));
            if (mb_strlen($apres) > $longueur) {
                throw new \DomainException(\sprintf('%s : %d caractères au plus.', $libelle, $longueur));
            }
            $apres = '' === $apres ? null : $apres;
            $avant = 'raisonSociale' === $champ ? $client->getRaisonSociale() : $client->getNif();
            if ('raisonSociale' === $champ && null === $apres) {
                throw new \DomainException('Indiquez la raison sociale.');
            }
            if ($apres !== (null === $avant || '' === trim($avant) ? null : trim($avant))) {
                $champs[$champ] = ['avant' => $avant, 'apres' => $apres];
            }
        }

        return $champs;
    }

    /**
     * « Télécharger mes données » : ce que la personne voit dans son espace,
     * selon ses droits.
     *
     * @return array<string, mixed>
     */
    public function exporter(CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $client = $compte->getClient() ?? throw new \LogicException('Compte sans fiche client.');
        $lecture = $compte->estLectureSeule();
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR);

        return self::construire($compte, [
            'appareils' => $this->em->getRepository(AppareilClient::class)->findBy(['compte' => $compte], ['derniereActivite' => 'DESC']),
            'adresses' => $this->compteEntreprise->adresses($client),
            'commandes' => $this->espace->commandes($client, 500),
            'devis' => $lecture ? [] : $this->espace->devis($client),
            'factures' => $payeur ? $this->espace->factures($client) : [],
            'fichiers' => $this->fichiers->liste($client, false, 500),
            'messages' => $lecture ? [] : $this->messages->fil($client),
            'reclamations' => $lecture ? [] : $this->em->getRepository(ReclamationClient::class)->findBy(['client' => $client], ['creeLe' => 'DESC']),
        ], $maintenant);
    }

    /**
     * Mise en forme de l'export, liste blanche champ par champ. Les droits
     * sont revérifiés ici, quelles que soient les sources reçues.
     *
     * @param array{appareils?: iterable<AppareilClient>, adresses?: iterable<AdresseLivraison>, commandes?: iterable<Commandes>, devis?: iterable<Devis>, factures?: iterable<Factures>, fichiers?: iterable<CommandeDetailFichier>, messages?: iterable<MessageClient>, reclamations?: iterable<ReclamationClient>} $sources
     *
     * @return array<string, mixed>
     */
    public static function construire(CompteClient $compte, array $sources, \DateTimeImmutable $maintenant): array
    {
        $client = $compte->getClient() ?? throw new \LogicException('Compte sans fiche client.');
        $lecture = $compte->estLectureSeule();
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR);
        $voitDevis = !$lecture;
        // Prix des devis : la règle de l'espace (valideur ou payeur) ; le total des commandes reste au payeur.
        $montantsDevis = $compte->montantsVisibles();
        $entreprise = CompteClient::clientAPlusieursComptes($client);
        $date = static fn (?\DateTimeInterface $d): ?string => $d?->format('c');
        // Ce qui appartient à un autre client ne sort jamais, même s'il a été passé par erreur.
        $aLui = static fn (?Clients $c): bool => null !== $c && $c === $client;

        $donnees = [
            'export' => [
                'le' => $maintenant->format('c'),
                'source' => 'Succès Imprim · espace client',
                'contenu' => 'Vos données personnelles et ce que vous voyez dans votre espace, selon vos droits.',
            ],
            'personne' => [
                'nom' => $compte->getNomAffiche(),
                'telephone' => '+'.$compte->getTelephone(),
                'fonction' => $compte->getFonction(),
                'droits' => $entreprise ? $compte->getLibelleDroits() : 'Titulaire du compte',
                'creeLe' => $date($compte->getCreeLe()),
                'derniereConnexion' => $date($compte->getDerniereConnexion()),
                'motDePasse' => $compte->aUnMotDePasse() ? 'oui' : 'non',
                'notifications' => PreferencesNotifications::de($compte),
                'appareils' => array_map(static fn (AppareilClient $a): array => [
                    'appareil' => $a->getLibelle(),
                    'premiereFois' => $date($a->getCreeLe()),
                    'derniereActivite' => $date($a->getDerniereActivite()),
                ], array_values(array_filter(self::liste($sources['appareils'] ?? []), static fn (AppareilClient $a): bool => $a->getCompte() === $compte))),
            ],
            'client' => array_filter([
                'code' => $client->getCode(),
                'raisonSociale' => $entreprise ? $client->getRaisonSociale() : null,
                'nom' => trim($client->getPrenom().' '.$client->getNom()),
                'telephone' => $client->getTelephone(),
                'email' => $client->getEmail(),
                'adresse' => $client->getAdresse(),
                'commune' => $client->getCommune(),
                'quartier' => $client->getQuartier(),
                'ville' => $client->getVille(),
                'nif' => $entreprise ? $client->getNif() : null,
                'rccm' => $entreprise ? $client->getRccm() : null,
                'recevoirSms' => $client->isRecevoirSms(),
                'accordOffres' => $date($client->getConsentementMarketingLe()),
                'conditions' => self::voitConditions($compte) ? self::conditions($client) : null,
            ], static fn (mixed $v): bool => null !== $v && '' !== $v),
            'adressesLivraison' => array_map(static fn (AdresseLivraison $a): array => [
                'libelle' => $a->getLibelle(),
                'adresse' => $a->getAdresse(),
                'repere' => $a->getRepere(),
                'contact' => $a->getContact(),
                'telephone' => $a->getTelephone(),
                'parDefaut' => $a->isParDefaut(),
            ], array_values(array_filter(self::liste($sources['adresses'] ?? []), static fn (AdresseLivraison $a): bool => $aLui($a->getClient()) && $a->isActif()))),
            'commandes' => array_map(static function (Commandes $c) use ($payeur, $date): array {
                $commande = [
                    'numero' => $c->getNumero(),
                    'date' => $date($c->getDateCommande()),
                    'livraisonPrevue' => $date($c->getDateLivraison()),
                    'etat' => $c->getStatutTravaux(),
                    'demandeePar' => $c->getDemandeePar(),
                    'valideePar' => $c->getValideePar(),
                    'lignes' => array_map(static fn ($l): array => [
                        'designation' => EspaceClient::titreLigne($l),
                        'quantite' => $l->getQuantite(),
                    ], array_values(array_filter(iterator_to_array($c->getCommandesDetails()), static fn ($l): bool => $l->isEtat()))),
                ];
                if ($payeur) {
                    $commande['totalTtc'] = $c->getTotalTtc();
                    $commande['resteAPayer'] = $c->getResteAPayer();
                }

                return $commande;
            }, array_values(array_filter(self::liste($sources['commandes'] ?? []), static fn (Commandes $c): bool => $aLui($c->getClients())))),
            'devis' => $voitDevis ? array_map(static function (Devis $d) use ($montantsDevis, $date): array {
                $devis = ['numero' => $d->getNumero(), 'date' => $date($d->getDateDevis()), 'statut' => $d->getStatut()];
                if ($montantsDevis) {
                    $devis['totalTtc'] = $d->getTotalTtc();
                }

                return $devis;
            }, array_values(array_filter(self::liste($sources['devis'] ?? []), static fn (Devis $d): bool => $aLui($d->getClients())))) : [],
            'factures' => $payeur ? array_map(static fn (Factures $f): array => [
                'numero' => $f->getNumero(),
                'type' => $f->getTypeDocumentLabel(),
                'date' => $date($f->getDateFacture()),
                'echeance' => $date($f->getDateEcheance()),
                'commande' => $f->getCommande()?->getNumero(),
                'totalTtc' => $f->getTotalTtc(),
                'paye' => $f->getMontantPaye(),
                'resteAPayer' => $f->getResteAPayer(),
                'statut' => $f->getStatutPaiementLabel(),
            ], array_values(array_filter(self::liste($sources['factures'] ?? []), static fn (Factures $f): bool => $aLui($f->getCommande()?->getClients())))) : [],
            'fichiers' => array_map(static fn (CommandeDetailFichier $f): array => [
                'nom' => $f->getNomOriginal(),
                'envoyeLe' => $date($f->getCreeLe()),
                'commande' => $f->getCommandeDetail()?->getCommande()?->getNumero(),
            ], array_values(array_filter(self::liste($sources['fichiers'] ?? []), static fn (CommandeDetailFichier $f): bool => $aLui($f->getClient())))),
            'messages' => $lecture ? [] : array_map(static fn (MessageClient $m): array => [
                'le' => $date($m->getCreeLe()),
                'de' => $m->estDeLAtelier() ? 'Succès Imprim' : ($m->getAuteur() ?? 'Vous'),
                'commande' => $m->getCommande()?->getNumero(),
                'texte' => $m->getTexte(),
            ], array_values(array_filter(self::liste($sources['messages'] ?? []), static fn (MessageClient $m): bool => $aLui($m->getClient())))),
            'reclamations' => $lecture ? [] : array_map(static fn (ReclamationClient $r): array => [
                'numero' => $r->getNumero(),
                'le' => $date($r->getCreeLe()),
                'objet' => $r->getObjet(),
                'description' => $r->getDescription(),
                'statut' => $r->getLibelleStatut(),
                'reponse' => $r->getReponse(),
            ], array_values(array_filter(self::liste($sources['reclamations'] ?? []), static fn (ReclamationClient $r): bool => $aLui($r->getClient())))),
        ];
        if (!$payeur) {
            unset($donnees['factures']);
        }
        if (!$voitDevis) {
            unset($donnees['devis']);
        }
        if ($lecture) {
            unset($donnees['messages'], $donnees['reclamations']);
        }

        return $donnees;
    }

    /**
     * @template T
     *
     * @param iterable<T> $elements
     *
     * @return list<T>
     */
    private static function liste(iterable $elements): array
    {
        return \is_array($elements) ? array_values($elements) : iterator_to_array($elements, false);
    }
}
