<?php

namespace App\Service\EspaceClient;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\Paiements;
use App\Service\Paiement\OrangeMoney;
use App\Service\SuiviCommandeService;
use App\Twig\ReglagesExtension;

/**
 * Page d'une commande dans l'espace client (maquettes CO05 et CO06) :
 * « Où en est ma commande ? » étape par étape, et la carte de chaque BAT.
 *
 * Tout vient des données de la commande : paiements, contrôle du fichier,
 * BAT, production (SuiviCommandeService::etapesClient), bon de livraison.
 * Une étape sans donnée reste « à venir » : rien n'est inventé. Des
 * employés, le client ne voit que le prénom et le métier (« Seydou
 * (graphiste PAO) », Employes::getNomPublic). Les paiements et les
 * montants ne sont que pour qui paie (CompteClient::DROIT_PAYEUR).
 */
final class SuiviCommandeClient
{
    public function __construct(
        private readonly EspaceClient $espace,
        private readonly SuiviCommandeService $suivi,
    ) {
    }

    /**
     * Le suivi et les BAT de la commande, selon les droits de la personne.
     *
     * @return array{etapes: list<array{cle: string, libelle: string, etat: string, detail: ?string}>, bats: list<array<string, mixed>>, actionRequise: bool}
     */
    public function suivi(Commandes $c, CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $fichiers = [];
        $cartes = [];
        foreach (self::lignesActives($c) as $ligne) {
            $ctl = self::dernierControle($ligne);
            if ($ligne->isPrePresseNecessaire() || null !== $ctl) {
                $publics = null !== $ctl ? ($this->espace->conception($ctl)?->getEvenements() ?? []) : [];
                $controle = array_values(array_filter($publics, static fn (array $e): bool => 'controle' === $e['type']))[0] ?? null;
                // Un BAT déjà envoyé suppose le fichier vu, même sans contrôle daté (création par le
                // graphiste sans fichier du client, contrôle d'avant la synchronisation de controleLe).
                $montre = null !== $ctl && $this->batMontre($ctl);
                $fichiers[] = ['statut' => $ctl?->getStatut(), 'fait' => ($ctl?->isFichierControle() ?? false) || $montre, 'le' => $ctl?->getControleLe() ?? self::date($controle['le'] ?? null), 'par' => $controle['par'] ?? null];
            }
            if (null !== $ctl && $this->batMontre($ctl)) {
                $cartes[] = $this->carteBat($ctl);
            }
        }
        // Les BAT qui attendent une réponse d'abord.
        usort($cartes, static fn (array $a, array $b): int => ('attente' !== $a['etat']) <=> ('attente' !== $b['etat']));

        $etapes = self::etapes(
            $c,
            $this->suivi->etapesClient($c),
            $fichiers,
            $cartes,
            $this->espace->livraison($c),
            $compte->peut(CompteClient::DROIT_PAYEUR),
            $compte->estLectureSeule(),
            $maintenant,
        );

        return ['etapes' => $etapes, 'bats' => $cartes, 'actionRequise' => \in_array('action', array_column($etapes, 'etat'), true)];
    }

    /** Un BAT se montre dès qu'il est envoyé, validé ou déjà présenté (comme EspaceClient::bats). */
    public function batMontre(ControlePrePresse $ctl): bool
    {
        return ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut()
            || $ctl->isBatValide()
            || ($ctl->isBatNecessaire() && null !== $this->espace->conception($ctl)?->getDernierBatLe());
    }

    /**
     * Carte d'un BAT (maquette CO05, à droite) : version, qui l'a envoyé et
     * quand, fichiers, repères, points à vérifier, corrections gratuites.
     *
     * @return array<string, mixed>
     */
    public function carteBat(ControlePrePresse $ctl): array
    {
        $conception = $this->espace->conception($ctl);
        $ligne = $ctl->getCommandeDetail();
        $evenements = $conception?->getEvenements() ?? [];
        $envois = array_values(array_filter($evenements, static fn (array $e): bool => 'bat' === $e['type']));
        $dernierEnvoi = [] !== $envois ? $envois[\count($envois) - 1] : null;
        $retours = array_values(array_filter($evenements, static fn (array $e): bool => 'retour' === $e['type'] && (null === $dernierEnvoi || $e['le'] >= $dernierEnvoi['le'])));
        $validation = array_values(array_filter($evenements, static fn (array $e): bool => 'valide' === $e['type']));
        $fichiers = $this->espace->fichiersBat($ctl);
        $commande = $ligne?->getCommande();
        $prevue = $commande?->getDateLivraison();

        return [
            'controle' => $ctl,
            'ligne' => $ligne,
            'titre' => null !== $ligne ? EspaceClient::titreLigne($ligne) : 'Travail',
            'etat' => self::etatBat($ctl),
            'version' => max(1, $conception?->getVersions() ?? 0, \count($envois)),
            'envoyeLe' => $conception?->getDernierBatLe() ?? self::date($dernierEnvoi['le'] ?? null),
            'par' => $dernierEnvoi['par'] ?? null,
            'correctionLe' => [] !== $retours ? self::date($retours[\count($retours) - 1]['le']) : null,
            'valideLe' => $conception?->getValideLe() ?? ([] !== $validation ? self::date($validation[0]['le']) : null),
            'verifiees' => [] !== $validation ? ($validation[0]['verifications'] ?? []) : [],
            'fichiers' => $fichiers,
            // Rang de chaque image du BAT : les repères s'y rapportent.
            'rangs' => array_flip(array_map(static fn ($f): int => (int) $f->getId(), $this->espace->imagesBat($ctl))),
            'reperes' => $conception?->derniersReperes(),
            'verifications' => EspaceClient::verificationsBat($ligne),
            'corrections' => $this->espace->correctionsBat($ctl),
            'note' => null !== $ligne ? self::noteLigne($ligne) : null,
            'historique' => self::historique($conception),
            // Date prévue par l'atelier (« Validez vite pour garder la livraison prévue samedi 17/10 »).
            'prevue' => null !== $prevue && $prevue >= new \DateTimeImmutable('today') ? ['jour' => self::jour($prevue), 'livrer' => $commande->estALivrer()] : null,
        ];
    }

    /** attente (le client a la main), correction (l'atelier refait), valide, preparation. */
    public static function etatBat(ControlePrePresse $ctl): string
    {
        return match (true) {
            ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl->getStatut() => 'attente',
            $ctl->isBatValide(), \in_array($ctl->getStatut(), [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true) => 'valide',
            $ctl->isCorrectionNecessaire(), \in_array($ctl->getStatut(), [ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_REJETE], true) => 'correction',
            default => 'preparation',
        };
    }

    /** Sous l'image du BAT : « 20 × 10 cm · PVC 3 mm · Œillets · 40 exemplaires ». */
    public static function noteLigne(CommandesDetails $l): string
    {
        $finitions = [];
        foreach ($l->getFinitions() as $f) {
            $finitions[] = $f->getNomFinition();
        }
        $quantite = (float) $l->getQuantite();

        return implode(' · ', array_filter([
            EspaceClient::taille($l),
            $l->getSupport()?->getNom(),
            ...$finitions,
            $quantite > 0 ? number_format($quantite, floor($quantite) == $quantite ? 0 : 1, ',', ' ').' exemplaire'.($quantite > 1 ? 's' : '') : null,
        ], static fn (?string $t): bool => null !== $t && '' !== trim($t)));
    }

    /**
     * Frise du BAT montrée au client : fichier contrôlé, versions envoyées,
     * ses demandes (ses propres mots), validation et ce qu'il a vérifié.
     * Rien de la vie interne de l'atelier (Conception::evenementsPublics).
     *
     * @return list<array{le: ?\DateTimeImmutable, titre: string, detail: ?string, par?: ?string, reperes?: list<string>, verifications?: list<string>}>
     */
    public static function historique(?Conception $c): array
    {
        $frise = [];
        foreach ($c?->getEvenements() ?? [] as $e) {
            $frise[] = match ($e['type']) {
                'controle' => ['le' => self::date($e['le']), 'titre' => 'Fichier contrôlé', 'detail' => null, 'par' => $e['par'] ?? null],
                'bat' => ['le' => self::date($e['le']), 'titre' => $e['titre'], 'detail' => null, 'par' => $e['par'] ?? null],
                'retour' => ['le' => self::date($e['le']), 'titre' => 'Votre demande de correction', 'detail' => preg_match('/« (.+?) »/u', (string) $e['detail'], $m) ? $m[1] : null, 'reperes' => array_column($e['reperes'] ?? [], 't')],
                'valide' => ['le' => self::date($e['le']), 'titre' => 'BAT validé', 'detail' => null, 'verifications' => $e['verifications'] ?? []],
                default => null,
            };
        }

        return array_reverse(array_values(array_filter($frise)));
    }

    /**
     * Étapes de « Où en est ma commande ? », chacune faite, action (le
     * client a la main), en_cours ou a_venir, avec son détail en clair.
     *
     * @param list<array{cle: string, libelle: string, date: ?\DateTimeInterface, detail: ?string, etat: string}> $production SuiviCommandeService::etapesClient
     * @param list<array{statut: ?string, fait: bool, le: ?\DateTimeInterface, par: ?string}>                                 $fichiers   un par ligne qui passe en prépresse
     * @param list<array<string, mixed>>                                                                          $bats       cartes des BAT (carteBat)
     *
     * @return list<array{cle: string, libelle: string, etat: string, detail: ?string, court?: string}> court : le détail sur téléphone (maquette CO06)
     */
    public static function etapes(Commandes $c, array $production, array $fichiers, array $bats, ?BonLivraison $livraison, bool $paiements, bool $lecture, \DateTimeImmutable $maintenant): array
    {
        $prod = array_column($production, null, 'cle');
        $jour = $maintenant->format('Y-m-d');
        // Aujourd'hui « 10:22 » (« à 10:22 »), un autre jour « 15/10 · 10:22 » (« le 15/10 à 10:22 »).
        $quand = static fn (\DateTimeInterface $d, bool $a = false): string => $d->format('Y-m-d') === $jour
            ? ($a ? 'à ' : '').$d->format('H:i')
            : ($a ? 'le '.$d->format('d/m').' à '.$d->format('H:i') : $d->format('d/m · H:i'));
        $confirmee = $c->isStatut();
        $etapes = [];

        $recue = $c->getDateCommande();
        $etapes[] = ['cle' => 'recue', 'libelle' => 'Commande reçue', 'etat' => 'faite', 'detail' => self::joindre([
            null !== $recue ? $recue->format('d/m · H:i') : null,
            $confirmee ? null : 'à confirmer par l\'atelier',
        ])];

        if ($paiements) {
            $etapes[] = self::etapePaiement($c, $quand);
        }

        // Fichier contrôlé : chaque ligne qui passe en prépresse.
        $avance = 'faite' === ($prod['impression']['etat'] ?? null) || 'en_cours' === ($prod['impression']['etat'] ?? null) && 'faite' === ($prod['bat']['etat'] ?? null);
        $avecDonnees = array_filter($fichiers, static fn (array $f): bool => null !== $f['statut']);
        if ([] !== $fichiers && ([] !== $avecDonnees || !$avance)) {
            $etapes[] = self::etapeFichier($fichiers, $confirmee, $quand);
        }

        // BAT : ceux envoyés au client, sinon l'état de la production ; pas de « BAT validé » deviné
        // pour une commande déjà imprimée dont l'atelier n'a aucun contrôle enregistré.
        if ([] !== $bats || ([] !== $fichiers && 'Sans BAT' !== ($prod['bat']['libelle'] ?? 'Sans BAT') && ([] !== $avecDonnees || !$avance))) {
            $etapes[] = self::etapeBat($bats, $prod['bat'] ?? null, $lecture, $quand);
        }

        $etapes[] = self::etapeImpression($prod, $bats, $quand);

        $prete = $prod['prete'] ?? null;
        $prevue = $c->getDateLivraison();
        $etapes[] = ['cle' => 'prete', 'libelle' => $c->estALivrer() ? 'Prête à livrer' : 'Prête à retirer', 'etat' => 'faite' === ($prete['etat'] ?? null) ? 'faite' : 'a_venir', 'detail' => match (true) {
            'faite' === ($prete['etat'] ?? null) => null !== ($prete['date'] ?? null) ? $quand($prete['date']) : null,
            null !== $prevue && $confirmee => 'prévue '.self::jour($prevue),
            default => null,
        }];

        $etapes[] = self::etapeRemise($c, $prod['livree'] ?? null, $livraison, $paiements, $quand);

        return $etapes;
    }

    /** @param callable(\DateTimeInterface, bool=): string $quand */
    private static function etapePaiement(Commandes $c, callable $quand): array
    {
        $valides = array_values(array_filter($c->getPaiements()->toArray(), static fn (Paiements $p): bool => $p->estValide()));
        usort($valides, static fn (Paiements $a, Paiements $b): int => $a->getDate() <=> $b->getDate());
        $enValidation = OrangeMoney::enValidation($c);
        $paye = $c->getTotalPaye();
        if ([] !== $valides) {
            $dernier = $valides[\count($valides) - 1];
            $reference = 1 === \count($valides) ? trim((string) $dernier->getReference()) : '';

            return ['cle' => 'paiement', 'libelle' => $paye >= (int) $c->getTotalTtc() && $paye > 0 ? 'Commande payée' : 'Acompte payé', 'etat' => 'faite', 'detail' => self::joindre([
                self::francs($paye),
                null !== $dernier->getDate() ? $quand($dernier->getDate()) : null,
                1 === \count($valides) ? $dernier->getModeLabel() : \count($valides).' paiements',
                '' !== $reference ? 'réf. '.$reference : null,
                $enValidation > 0 ? self::francs($enValidation).' en validation' : null,
            ]), 'court' => self::francs($paye)];
        }
        if ($enValidation > 0) {
            return ['cle' => 'paiement', 'libelle' => 'Paiement en validation', 'etat' => 'en_cours', 'detail' => self::francs($enValidation).' payés par Orange Money · la caisse vérifie'];
        }

        return ['cle' => 'paiement', 'libelle' => 'Acompte', 'etat' => 'a_venir', 'detail' => $c->isStatut() && $c->getResteAPayer() > 0 ? 'pas encore de paiement' : null];
    }

    /**
     * @param list<array{statut: ?string, fait: bool, le: ?\DateTimeInterface, par: ?string}> $fichiers
     * @param callable(\DateTimeInterface, bool=): string                                $quand
     */
    private static function etapeFichier(array $fichiers, bool $confirmee, callable $quand): array
    {
        $statuts = array_column($fichiers, 'statut');
        if (\in_array(ControlePrePresse::STATUT_REJETE, $statuts, true)) {
            return ['cle' => 'fichier', 'libelle' => 'Fichier à revoir', 'etat' => 'en_cours', 'detail' => 'l\'atelier vous contacte'];
        }
        $faits = array_filter($fichiers, static fn (array $f): bool => $f['fait']);
        if (\count($faits) === \count($fichiers)) {
            // La date et le prénom du dernier contrôle daté ; sans date, l'étape est faite sans détail.
            $dates = array_values(array_filter($faits, static fn (array $f): bool => null !== $f['le']));
            usort($dates, static fn (array $a, array $b): int => $a['le'] <=> $b['le']);
            $dernier = [] !== $dates ? $dates[\count($dates) - 1] : null;

            return ['cle' => 'fichier', 'libelle' => 'Fichier contrôlé', 'etat' => 'faite', 'detail' => null !== $dernier ? self::joindre([null !== $dernier['par'] ? 'par '.$dernier['par'] : null, $quand($dernier['le'])]) : null]
                + (null !== $dernier ? ['court' => $quand($dernier['le'])] : []);
        }

        return ['cle' => 'fichier', 'libelle' => 'Fichier contrôlé', 'etat' => $confirmee ? 'en_cours' : 'a_venir', 'detail' => $confirmee ? 'l\'atelier vérifie votre fichier' : null];
    }

    /**
     * @param list<array<string, mixed>>                $bats
     * @param array<string, mixed>|null                 $production étape « bat » de SuiviCommandeService::etapesClient
     * @param callable(\DateTimeInterface, bool=): string      $quand
     */
    private static function etapeBat(array $bats, ?array $production, bool $lecture, callable $quand): array
    {
        $attente = array_values(array_filter($bats, static fn (array $b): bool => 'attente' === $b['etat']));
        if ([] !== $attente) {
            $b = $attente[0];

            return ['cle' => 'bat', 'libelle' => \count($attente) > 1 ? \count($attente).' BAT à valider' : 'BAT à valider', 'etat' => $lecture ? 'en_cours' : 'action', 'detail' => self::joindre([
                null !== $b['envoyeLe'] ? 'envoyé '.$quand($b['envoyeLe'], true) : null,
                $lecture ? 'en attente de votre entreprise' : 'en attente de vous',
            ]), 'court' => 'en attente'];
        }
        $corrections = array_values(array_filter($bats, static fn (array $b): bool => 'correction' === $b['etat']));
        if ([] !== $corrections) {
            $b = $corrections[0];

            return ['cle' => 'bat', 'libelle' => 'Correction du BAT', 'etat' => 'en_cours', 'detail' => self::joindre([
                null !== $b['correctionLe'] ? 'demandée '.$quand($b['correctionLe'], true) : null,
                'version '.($b['version'] + 1).' en préparation',
            ])];
        }
        $valides = array_values(array_filter($bats, static fn (array $b): bool => 'valide' === $b['etat']));
        if ([] !== $bats && \count($valides) === \count($bats) && 'a_venir' !== ($production['etat'] ?? 'faite')) {
            $dates = array_filter(array_column($valides, 'valideLe'));

            return ['cle' => 'bat', 'libelle' => 'BAT validé', 'etat' => 'faite', 'detail' => [] !== $dates ? $quand(max($dates)) : null];
        }
        if ('faite' === ($production['etat'] ?? null)) {
            return ['cle' => 'bat', 'libelle' => 'BAT validé', 'etat' => 'faite', 'detail' => null !== ($production['date'] ?? null) ? $quand($production['date']) : null];
        }

        return ['cle' => 'bat', 'libelle' => 'BAT à valider', 'etat' => 'a_venir', 'detail' => null];
    }

    /**
     * Impression et finition : « En impression », « En finition », puis « Imprimée ».
     *
     * @param array<string, array<string, mixed>> $prod
     * @param list<array<string, mixed>>          $bats
     * @param callable(\DateTimeInterface, bool=): string $quand
     */
    private static function etapeImpression(array $prod, array $bats, callable $quand): array
    {
        $impression = $prod['impression'] ?? [];
        $finition = $prod['finition'] ?? [];
        if ('faite' === ($finition['etat'] ?? null)) {
            return ['cle' => 'impression', 'libelle' => 'Imprimée', 'etat' => 'faite', 'detail' => null !== ($finition['date'] ?? null) ? $quand($finition['date']) : null];
        }
        if ('en_cours' === ($finition['etat'] ?? null)) {
            return ['cle' => 'impression', 'libelle' => 'En finition', 'etat' => 'en_cours', 'detail' => null !== ($impression['date'] ?? null) ? 'impression lancée '.$quand($impression['date'], true) : null];
        }
        if ('en_cours' === ($impression['etat'] ?? null)) {
            return ['cle' => 'impression', 'libelle' => 'En impression', 'etat' => 'en_cours', 'detail' => null !== ($impression['date'] ?? null) ? 'lancée '.$quand($impression['date'], true) : 'dans la file d\'impression'];
        }
        $batAttendu = [] !== $bats && [] !== array_filter($bats, static fn (array $b): bool => 'valide' !== $b['etat']);

        return ['cle' => 'impression', 'libelle' => 'En impression', 'etat' => 'a_venir', 'detail' => match (true) {
            $batAttendu => 'après validation du BAT',
            [] !== $bats => 'l\'atelier lance l\'impression',
            default => null,
        }];
    }

    /**
     * Livrée ou retirée ; « En route » avec le créneau ; le reste à payer
     * au livreur (ou au retrait) pour qui paie seulement.
     *
     * @param array<string, mixed>|null           $production étape « livree » de SuiviCommandeService::etapesClient
     * @param callable(\DateTimeInterface, bool=): string $quand
     */
    private static function etapeRemise(Commandes $c, ?array $production, ?BonLivraison $livraison, bool $paiements, callable $quand): array
    {
        $livrer = $c->estALivrer();
        if ('faite' === ($production['etat'] ?? null) || null !== $livraison?->getLivreLe()) {
            return ['cle' => 'livree', 'libelle' => $livrer ? 'Livrée' : 'Retirée', 'etat' => 'faite', 'detail' => null !== $livraison?->getLivreLe() ? $quand($livraison->getLivreLe()) : null];
        }
        // Échec de livraison (docs/documents-livraisons.md) : ni la raison ni le livreur ne passent au site.
        if (null !== $livraison && $livraison->isEchecEnCours()) {
            $le = $livraison->getEchecLe() ?? $livraison->getPrevuLe();

            return ['cle' => 'livree', 'libelle' => 'Livraison reportée', 'etat' => 'en_cours', 'detail' => self::joindre([
                null !== $le ? 'pas pu être livrée le '.$le->format('d/m') : null,
                'l\'atelier vous recontacte pour une nouvelle date',
            ])];
        }
        if (null !== $livraison?->getEnRouteLe()) {
            return ['cle' => 'livree', 'libelle' => 'En route', 'etat' => 'en_cours', 'detail' => self::joindre([
                'livreur parti '.$quand($livraison->getEnRouteLe(), true),
                null !== $livraison->getCreneau() ? 'créneau '.$livraison->getCreneau() : null,
            ])];
        }
        $reste = $paiements && $c->isStatut() ? OrangeMoney::aPayer($c) : 0;

        return ['cle' => 'livree', 'libelle' => $livrer ? 'Livrée' : 'Retirée', 'etat' => 'a_venir', 'detail' => self::joindre([
            null !== $livraison?->getPrevuLe() ? 'prévue '.self::jour($livraison->getPrevuLe()).(null !== $livraison->getCreneau() ? ' · '.$livraison->getCreneau() : '') : null,
            $reste > 0 ? 'reste '.self::francs($reste).' à payer '.($livrer ? 'au livreur' : 'au retrait') : null,
        ])];
    }

    /** Date d'un événement du suivi (texte ATOM) ; null si absente ou illisible. */
    private static function date(mixed $le): ?\DateTimeImmutable
    {
        if ($le instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($le);
        }
        try {
            return \is_string($le) && '' !== $le ? new \DateTimeImmutable($le) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** « samedi 17/10 ». */
    public static function jour(\DateTimeInterface $d): string
    {
        return ReglagesExtension::dateFr($d, 'EEEE dd/MM');
    }

    public static function francs(int|float $montant): string
    {
        return number_format((float) $montant, 0, ',', ' ').' F';
    }

    /** @param list<?string> $parts */
    private static function joindre(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $p): bool => null !== $p && '' !== $p));

        return [] === $parts ? null : implode(' · ', $parts);
    }

    /** @return list<CommandesDetails> */
    private static function lignesActives(Commandes $c): array
    {
        return array_values(array_filter($c->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction()));
    }

    private static function dernierControle(CommandesDetails $l): ?ControlePrePresse
    {
        $dernier = null;
        foreach ($l->getControlesPrePresse() as $ctl) {
            $dernier = null === $dernier || $ctl->getId() > $dernier->getId() ? $ctl : $dernier;
        }

        return $dernier;
    }
}
