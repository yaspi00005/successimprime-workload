<?php

namespace App\Service\Finances;

use App\Controller\UtilisateursController;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\LigneReleveOrangeMoney;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\User;
use App\Repository\ParametresPaiementRepository;
use App\Service\Creances;
use App\Service\Paiement\FraisOrangeMoney;
use App\Service\OperationsAValider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encaissements et rapprochement Orange Money (maquette F02).
 *
 * Le relevé du compte marchand (CSV exporté d'Orange Money) est lu ligne
 * par ligne ; seules les transactions reçues et réussies sont gardées.
 * Une transaction dont la référence est déjà sur un paiement saisi est
 * « rapprochée » : rien n'est créé ni validé. Pour les autres, l'écran
 * PROPOSE des commandes ou des paiements avec un score ; c'est une
 * personne qui clique pour associer. L'association crée un paiement
 * Orange Money en attente, validé ensuite dans À valider (ERP local).
 *
 * Le relevé montre ce que le client a envoyé, frais Orange Money compris
 * (fonds de soutien et frais de retrait) : un paiement saisi se compare
 * donc par son total encaissé, et une transaction qui dépasse le reste dû
 * de ces seuls frais est découpée en montant et frais.
 */
final class Encaissements
{
    /** Taille maximale du relevé importé (le fichier n'est pas conservé). */
    public const TAILLE_MAX = 2 * 1024 * 1024;

    /** En dessous, une piste n'est pas proposée. */
    public const SCORE_MINIMUM = 25;

    /** À partir de là, la ligne affiche « Suggestion ». */
    public const SCORE_SUGGESTION = 60;

    /** Écart toléré entre la transaction et un paiement saisi en caisse. */
    public const JOURS_PAIEMENT = 3;

    /** En-têtes reconnus (sans accents, en minuscules) pour chaque colonne utile. */
    public const COLONNES = [
        'date' => ['date', 'date heure', 'date et heure', 'date transaction', 'date de transaction', 'date operation', 'transaction date', 'datetime'],
        'heure' => ['heure', 'time', 'heure transaction'],
        'reference' => ['reference', 'ref', 'id transaction', 'transaction id', 'id', 'numero transaction', 'reference transaction', 'txn id', 'n transaction', 'no transaction'],
        'montant' => ['montant', 'amount', 'credit', 'montant recu'],
        'expediteur' => ['expediteur', 'numero', 'telephone', 'msisdn', 'emetteur', 'sender', 'numero expediteur', 'de', 'from', 'compte source', 'numero client'],
        'nom' => ['nom', 'nom expediteur', 'sender name', 'nom emetteur', 'nom client', 'client'],
        'type' => ['type', 'operation', 'type operation', 'nature', 'sens', 'type de transaction', 'service'],
        'statut' => ['statut', 'status', 'etat'],
        'libelle' => ['libelle', 'motif', 'description', 'commentaire', 'message', 'details', 'objet'],
    ];

    private const TYPES_ENTREE = ['recu', 'reception', 'depot', 'cash in', 'credit', 'entrant', 'paiement marchand', 'encaissement'];
    private const TYPES_SORTIE = ['envoi', 'envoye', 'retrait', 'debit', 'cash out', 'sortant', 'achat', 'paiement facture', 'transfert vers'];
    private const STATUTS_ECHEC = ['echec', 'echoue', 'failed', 'annul', 'rejet', 'expire', 'refuse', 'en cours', 'pending', 'en attente'];
    private const MOTS_VIDES = ['ets', 'sarl', 'sa', 'ste', 'societe', 'les', 'des', 'chez', 'mme', 'mr', 'm'];

    /** @var list<array<string, mixed>>|null */
    private ?array $creancesOuvertes = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
        private readonly ?ParametresPaiementRepository $parametresPaiement = null,
    ) {
    }

    // ------------------------------------------------------------------
    // Lecture du relevé
    // ------------------------------------------------------------------

    /**
     * Lit un relevé CSV (séparateur « ; », « , » ou tabulation, ligne
     * d'en-tête n'importe où dans les 10 premières lignes).
     *
     * @return array{lignes: list<array{reference: string, recu_le: \DateTimeImmutable, montant: int, expediteur: ?string, nom: ?string, libelle: ?string}>, ignorees: int, erreurs: list<string>}
     */
    public static function lireReleve(string $contenu): array
    {
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu;
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $lignes = preg_split('/\r\n|\r|\n/', trim($contenu)) ?: [];
        if ([] === $lignes || '' === trim($lignes[0])) {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }

        $separateur = self::separateur(\array_slice($lignes, 0, 10));
        $colonnes = null;
        $debut = 0;
        foreach (\array_slice($lignes, 0, 10) as $i => $ligne) {
            $trouvees = self::colonnes(str_getcsv($ligne, $separateur, '"', ''));
            if (isset($trouvees['reference'], $trouvees['montant'], $trouvees['date'])) {
                $colonnes = $trouvees;
                $debut = $i + 1;
                break;
            }
        }
        if (null === $colonnes) {
            throw new \InvalidArgumentException('En-têtes introuvables : le relevé doit avoir au moins les colonnes Date, Référence et Montant.');
        }

        $resultat = ['lignes' => [], 'ignorees' => 0, 'erreurs' => []];
        foreach (\array_slice($lignes, $debut, null, true) as $i => $ligne) {
            if ('' === trim($ligne)) {
                continue;
            }
            $cellules = str_getcsv($ligne, $separateur, '"', '');
            $valeur = static fn (string $cle): string => isset($colonnes[$cle]) ? trim((string) ($cellules[$colonnes[$cle]] ?? '')) : '';

            $statut = self::normaliser($valeur('statut'));
            $type = self::normaliser($valeur('type'));
            $montant = self::montant($valeur('montant'));
            $sortie = '' !== $type && !self::contient($type, self::TYPES_ENTREE) && self::contient($type, self::TYPES_SORTIE);
            if (('' !== $statut && self::contient($statut, self::STATUTS_ECHEC)) || $sortie || (null !== $montant && $montant <= 0)) {
                ++$resultat['ignorees'];
                continue;
            }

            $numero = $i + 1;
            $reference = mb_substr($valeur('reference'), 0, 60);
            $date = self::date(trim($valeur('date').' '.$valeur('heure')));
            if ('' === $reference || null === $montant || null === $date) {
                $resultat['erreurs'][] = \sprintf('Ligne %d : %s illisible.', $numero, '' === $reference ? 'référence' : (null === $montant ? 'montant' : 'date'));
                continue;
            }
            $resultat['lignes'][] = [
                'reference' => $reference,
                'recu_le' => $date,
                'montant' => $montant,
                'expediteur' => '' !== $valeur('expediteur') ? $valeur('expediteur') : null,
                'nom' => '' !== $valeur('nom') ? $valeur('nom') : null,
                'libelle' => '' !== $valeur('libelle') ? $valeur('libelle') : null,
            ];
        }

        return $resultat;
    }

    /** « 42 000 », « 42 000,00 », « 42.000 », « 42000 FCFA », « -5 000 » → entier ; null si illisible. */
    public static function montant(string $texte): ?int
    {
        $texte = str_replace(["\u{00A0}", "\u{202F}", ' '], '', mb_strtoupper(trim($texte)));
        $texte = (string) preg_replace('/(FCFA|XOF|CFA|F)$/', '', $texte);
        $signe = str_starts_with($texte, '-') ? -1 : 1;
        $texte = ltrim($texte, '+-');
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $texte)) {
            $nombre = (float) str_replace('.', '', $texte);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $texte)) {
            $nombre = (float) str_replace(',', '', $texte);
        } elseif (preg_match('/^\d+([.,]\d{1,2})?$/', $texte)) {
            $nombre = (float) str_replace(',', '.', $texte);
        } else {
            return null;
        }

        return $signe * (int) round($nombre);
    }

    /** Date et heure du relevé, aux formats courants (jour d'abord). */
    public static function date(string $texte): ?\DateTimeImmutable
    {
        $texte = trim((string) preg_replace('/\s+/', ' ', $texte));
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd/m/y H:i:s', 'd/m/y H:i', 'd/m/y', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $texte);
            $erreurs = \DateTimeImmutable::getLastErrors();
            // « 15/10/26 » se lirait l'an 26 avec Y : l'année doit être plausible.
            if (false !== $date && (false === $erreurs || (0 === $erreurs['warning_count'] && 0 === $erreurs['error_count'])) && (int) $date->format('Y') >= 2000) {
                return $date;
            }
        }

        return null;
    }

    /** Les 8 derniers chiffres d'un numéro malien (+223, 00223 ou rien devant). */
    public static function numeroCourt(?string $telephone): ?string
    {
        $chiffres = (string) preg_replace('/\D+/', '', (string) $telephone);

        return \strlen($chiffres) >= 8 ? substr($chiffres, -8) : null;
    }

    // ------------------------------------------------------------------
    // Suggestions
    // ------------------------------------------------------------------

    /**
     * Score d'une créance pour une transaction : montant (identique 55,
     * acompte 20), même numéro que la fiche client 30, nom proche 15,
     * numéro du document dans le message 40, créance échue 5, relancée
     * dans le mois 5. Jamais 100 : ce n'est qu'une proposition.
     *
     * « reste_frais » (facultatif) : le reste dû plus les frais Orange Money,
     * ce que le client envoie quand il paie tout par Orange Money.
     *
     * @param array{montant: int, expediteur: ?string, nom: ?string, libelle: ?string, recu_le: \DateTimeImmutable} $ligne
     * @param array{reste: int, reste_frais?: int, documents: list<string>, client: string, telephone: ?string, jours: int, relance: ?\DateTimeImmutable} $creance
     *
     * @return array{score: int, raisons: list<string>}
     */
    public static function scoreCreance(array $ligne, array $creance): array
    {
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ').' F';
        $avecFrais = ($creance['reste_frais'] ?? 0) > $creance['reste'] && $ligne['montant'] === $creance['reste_frais'];
        if ((!$avecFrais && $ligne['montant'] > $creance['reste']) || $creance['reste'] <= 0) {
            return ['score' => 0, 'raisons' => []];
        }
        $score = 0;
        $raisons = [$f($creance['reste']).($creance['jours'] > 0 ? ' échus' : ' dus')];
        if ($ligne['montant'] === $creance['reste'] || $avecFrais) {
            $score += 55;
            $raisons[] = $avecFrais ? 'montant identique, frais Orange Money compris' : 'montant identique';
        } else {
            $score += 20;
            $raisons[] = 'acompte';
        }
        $numero = self::numeroCourt($ligne['expediteur']);
        if (null !== $numero && $numero === self::numeroCourt($creance['telephone'])) {
            $score += 30;
            $raisons[] = 'même numéro que la fiche';
        }
        if (self::nomsProches((string) $ligne['nom'], $creance['client'])) {
            $score += 15;
            $raisons[] = 'nom proche';
        }
        $message = self::compacter((string) $ligne['libelle']);
        foreach ($creance['documents'] as $document) {
            $document = self::compacter($document);
            if (\strlen($document) >= 4 && '' !== $message && str_contains($message, $document)) {
                $score += 40;
                $raisons[] = 'numéro du document dans le message';
                break;
            }
        }
        if ($creance['jours'] > 0) {
            $score += 5;
        }
        $relance = $creance['relance'];
        if (null !== $relance && $relance <= $ligne['recu_le'] && $relance >= $ligne['recu_le']->modify('-30 days')) {
            $score += 5;
            $raisons[] = 'relancée le '.$relance->format('d/m');
        }

        return ['score' => min(99, $score), 'raisons' => $raisons];
    }

    /**
     * Score d'un paiement Orange Money déjà saisi (en caisse) sans
     * référence de relevé : même montant obligatoire, puis proximité de la
     * date, numéro du client et référence approchante.
     *
     * @param array{montant: int, expediteur: ?string, recu_le: \DateTimeImmutable, reference: string} $ligne
     * @param array{montant: int, date: \DateTimeImmutable, telephone: ?string, reference: ?string} $paiement
     *
     * @return array{score: int, raisons: list<string>}
     */
    public static function scorePaiement(array $ligne, array $paiement): array
    {
        if ($ligne['montant'] !== $paiement['montant']) {
            return ['score' => 0, 'raisons' => []];
        }
        $score = 55;
        $raisons = ['déjà saisi', 'montant identique'];
        $jours = abs((int) $ligne['recu_le']->setTime(0, 0)->diff($paiement['date']->setTime(0, 0))->format('%r%a'));
        if ($jours > self::JOURS_PAIEMENT) {
            return ['score' => 0, 'raisons' => []];
        }
        $score += [0 => 30, 1 => 20][$jours] ?? 10;
        $raisons[] = 0 === $jours ? 'même jour' : \sprintf('%d jour%s d’écart', $jours, $jours > 1 ? 's' : '');
        $numero = self::numeroCourt($ligne['expediteur']);
        if (null !== $numero && $numero === self::numeroCourt($paiement['telephone'])) {
            $score += 15;
            $raisons[] = 'même numéro que la fiche';
        }
        $a = self::compacter($ligne['reference']);
        $b = self::compacter((string) $paiement['reference']);
        if (\strlen($b) >= 6 && (str_contains($a, $b) || str_contains($b, $a))) {
            $score += 40;
            $raisons[] = 'référence approchante';
        }

        return ['score' => min(99, $score), 'raisons' => $raisons];
    }

    // ------------------------------------------------------------------
    // Base de données
    // ------------------------------------------------------------------

    /**
     * Enregistre les transactions nouvelles du relevé. Une transaction dont
     * la référence est déjà sur un paiement Orange Money saisi est
     * rapprochée de ce paiement, sans rien valider.
     *
     * @return array{ajoutees: int, doublons: int, rapprochees: int, ignorees: int, erreurs: list<string>}
     */
    public function importer(string $contenu, User $par, \DateTimeImmutable $maintenant): array
    {
        $lu = self::lireReleve($contenu);
        $bilan = ['ajoutees' => 0, 'doublons' => 0, 'rapprochees' => 0, 'ignorees' => $lu['ignorees'], 'erreurs' => $lu['erreurs']];
        if ([] === $lu['lignes']) {
            return $bilan;
        }

        $connues = array_flip($this->em->createQueryBuilder()
            ->select('l.reference')
            ->from(LigneReleveOrangeMoney::class, 'l')
            ->andWhere('l.reference IN (:references)')
            ->setParameter('references', array_column($lu['lignes'], 'reference'))
            ->getQuery()
            ->getSingleColumnResult());

        $dates = array_column($lu['lignes'], 'recu_le');
        $paiements = [];
        foreach ($this->paiementsOrangeMoney(min($dates)->modify('-7 days'), max($dates)->modify('+7 days'), true) as $p) {
            $cle = OperationsAValider::normaliserReference($p->getReference());
            if (null !== $cle) {
                $paiements[$cle] = $p;
            }
        }

        foreach ($lu['lignes'] as $l) {
            if (isset($connues[$l['reference']])) {
                ++$bilan['doublons'];
                continue;
            }
            $connues[$l['reference']] = true;
            $ligne = (new LigneReleveOrangeMoney($l['reference'], $l['recu_le'], $l['montant'], $par, $maintenant))->decrire($l['expediteur'], $l['nom'], $l['libelle']);
            $paiement = $paiements[OperationsAValider::normaliserReference($l['reference'])] ?? null;
            // Le relevé a reçu le montant et les frais Orange Money du paiement.
            if (null !== $paiement && $paiement->getMontantTotalEncaisse() === $l['montant']) {
                $ligne->rapprocher($paiement, $maintenant);
                unset($paiements[OperationsAValider::normaliserReference($l['reference'])]);
                ++$bilan['rapprochees'];
            }
            $this->em->persist($ligne);
            ++$bilan['ajoutees'];
        }
        $this->em->flush();

        return $bilan;
    }

    /**
     * Bilan de l'import pour la personne : « 19 transactions nouvelles,
     * dont 15 rapprochées… ».
     *
     * @param array{ajoutees: int, doublons: int, rapprochees: int, ignorees: int} $bilan
     */
    public static function messageImport(array $bilan): string
    {
        $pluriel = static fn (int $n, string $mot): string => $n.' '.$mot.($n > 1 ? 's' : '');
        $message = 0 === $bilan['ajoutees']
            ? 'Rien de nouveau dans ce relevé'
            : 'Relevé importé : '.$pluriel($bilan['ajoutees'], 'transaction').' nouvelle'.($bilan['ajoutees'] > 1 ? 's' : '')
                .($bilan['rapprochees'] > 0 ? ', dont '.$bilan['rapprochees'].' rapprochée'.($bilan['rapprochees'] > 1 ? 's' : '').' d’un paiement déjà saisi (même référence)' : '');
        if ($bilan['doublons'] > 0) {
            $message .= ' ; '.$bilan['doublons'].' déjà importée'.($bilan['doublons'] > 1 ? 's' : '');
        }
        if ($bilan['ignorees'] > 0) {
            $message .= ' ; '.$pluriel($bilan['ignorees'], 'ligne').' écartée'.($bilan['ignorees'] > 1 ? 's' : '').' (envois, retraits ou échecs)';
        }

        return $message.'.';
    }

    /**
     * Transactions reçues pas encore rattachées à un paiement valable.
     *
     * @return list<LigneReleveOrangeMoney>
     */
    public function aAssocier(): array
    {
        return $this->em->createQueryBuilder()
            ->select('l', 'p')
            ->from(LigneReleveOrangeMoney::class, 'l')
            ->leftJoin('l.paiement', 'p')
            ->andWhere('l.statut = :a_associer OR (l.statut IN (:traitees) AND (p.id IS NULL OR p.statut IN (:perdus)))')
            ->setParameter('a_associer', LigneReleveOrangeMoney::A_ASSOCIER)
            ->setParameter('traitees', [LigneReleveOrangeMoney::ASSOCIEE, LigneReleveOrangeMoney::RAPPROCHEE])
            ->setParameter('perdus', [Paiements::STATUT_REJETE, Paiements::STATUT_ANNULE])
            ->orderBy('l.recuLe', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les meilleures pistes pour une transaction : paiements déjà saisis,
     * puis créances ouvertes, par score.
     *
     * @return list<array{choix: string, titre: string, client: string, detail: string, score: int, reste: ?int}>
     */
    public function suggestions(LigneReleveOrangeMoney $ligne, \DateTimeImmutable $maintenant, int $nombre = 3): array
    {
        $l = ['montant' => $ligne->getMontant(), 'expediteur' => $ligne->getExpediteur(), 'nom' => $ligne->getNomExpediteur(), 'libelle' => $ligne->getLibelle(), 'recu_le' => $ligne->getRecuLe(), 'reference' => $ligne->getReference()];
        $pistes = [];

        $du = $ligne->getRecuLe()->setTime(0, 0)->modify('-'.self::JOURS_PAIEMENT.' days');
        foreach ($this->paiementsOrangeMoney($du, $du->modify('+'.(2 * self::JOURS_PAIEMENT + 1).' days'), false) as $p) {
            $client = $p->getCommande()?->getClients();
            $s = self::scorePaiement($l, ['montant' => $p->getMontantTotalEncaisse(), 'date' => $p->getDate() ?? $maintenant, 'telephone' => $client?->getTelephone(), 'reference' => $p->getReference()]);
            if ($s['score'] >= self::SCORE_MINIMUM) {
                $pistes[] = [
                    'choix' => 'paiement-'.$p->getId(),
                    'titre' => \sprintf('%s · paiement du %s', $p->getCommande()?->getNumero() ?? 'Paiement', ($p->getDate() ?? $maintenant)->format('d/m H:i')),
                    'client' => $client?->getNomComplet() ?? '—',
                    'detail' => implode(' · ', $s['raisons']),
                    'score' => $s['score'],
                    'reste' => null,
                ];
            }
        }

        foreach ($this->creancesOuvertes($maintenant) as $c) {
            $s = self::scoreCreance($l, $c);
            if ($s['score'] >= self::SCORE_MINIMUM) {
                $pistes[] = ['choix' => 'commande-'.$c['commande_id'], 'titre' => $c['document'], 'client' => $c['client'], 'detail' => implode(' · ', $s['raisons']), 'score' => $s['score'], 'reste' => $c['reste']];
            }
        }
        usort($pistes, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return \array_slice($pistes, 0, $nombre);
    }

    /**
     * Toutes les créances ouvertes, pour « Autre facture ».
     *
     * @return list<array<string, mixed>>
     */
    public function creancesOuvertes(\DateTimeImmutable $maintenant): array
    {
        if (null !== $this->creancesOuvertes) {
            return $this->creancesOuvertes;
        }
        $lignes = $this->creances->donnees($maintenant)['lignes'];
        $attente = $this->enAttenteParCommande(array_map(static fn (array $l): int => (int) $l['commande']->getId(), $lignes));
        $parametres = $this->parametresPaiement();
        $ouvertes = [];
        foreach ($lignes as $l) {
            $commande = $l['commande'];
            $reste = $l['reste'] - ($attente[(int) $commande->getId()] ?? 0);
            if ($reste <= 0) {
                continue;
            }
            $ouvertes[] = [
                'commande_id' => (int) $commande->getId(),
                'document' => $l['document'],
                'documents' => array_values(array_unique(array_filter([$l['document'], $commande->getNumero()]))),
                'client' => $l['client'],
                'telephone' => $l['telephone'],
                'reste' => $reste,
                // Tout payé par Orange Money : le reste et les frais (fonds de soutien, retrait).
                'reste_frais' => FraisOrangeMoney::calculer($reste, $parametres)->total(),
                'jours' => $l['jours'],
                'relance' => $l['relance']['date'] ?? null,
            ];
        }
        usort($ouvertes, static fn (array $a, array $b): int => [$a['client'], $a['document']] <=> [$b['client'], $b['document']]);

        return $this->creancesOuvertes = $ouvertes;
    }

    /**
     * Associe la transaction : à un paiement déjà saisi (« paiement-ID »),
     * ou à une commande (« commande-ID ») en créant un paiement Orange Money
     * EN ATTENTE, à valider dans À valider. Jamais validé ici.
     */
    public function associer(LigneReleveOrangeMoney $ligne, string $choix, ?int $score, User $par, \DateTimeImmutable $maintenant): Paiements
    {
        if (!$ligne->estAAssocier()) {
            throw new \InvalidArgumentException('Cette transaction est déjà rattachée à un paiement.');
        }
        if (!preg_match('/^(commande|paiement)-(\d+)$/', $choix, $m)) {
            throw new \InvalidArgumentException('Choisissez la facture ou la commande payée.');
        }

        if ('paiement' === $m[1]) {
            $paiement = $this->em->find(Paiements::class, (int) $m[2]);
            if (null === $paiement || !\in_array($paiement->getStatut(), [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE], true) || $paiement->getMontantTotalEncaisse() !== $ligne->getMontant()) {
                throw new \InvalidArgumentException('Ce paiement ne correspond pas à la transaction.');
            }
            if (null !== $this->em->getRepository(LigneReleveOrangeMoney::class)->findOneBy(['paiement' => $paiement])) {
                throw new \InvalidArgumentException('Ce paiement est déjà rattaché à une autre transaction du relevé.');
            }
            $ligne->associer($paiement, $score, $par, $maintenant);
            $this->em->flush();

            return $paiement;
        }

        $commande = $this->em->find(Commandes::class, (int) $m[2]);
        if (null === $commande || 'annulee' === $commande->getStatutTravaux()) {
            throw new \InvalidArgumentException('Commande introuvable ou annulée.');
        }
        $attente = $this->enAttenteParCommande([(int) $commande->getId()])[(int) $commande->getId()] ?? 0;
        $dispo = (int) $commande->getTotalTtc() - $commande->getTotalPaye() - $attente;
        // Le client a pu envoyer le reste dû plus les frais Orange Money : ils vont sur le paiement, pas sur la commande.
        $frais = $ligne->getMontant() > $dispo ? FraisOrangeMoney::decomposer($ligne->getMontant(), $this->parametresPaiement()) : null;
        if (null !== $frais && ($frais->montant > $dispo || 0 === $frais->frais())) {
            $frais = null;
        }
        $montant = $frais?->montant ?? $ligne->getMontant();
        if ($montant > $dispo) {
            throw new \InvalidArgumentException(\sprintf('Le montant dépasse ce qui reste à payer sur %s (%s F, paiements en attente compris).', $commande->getNumero(), number_format(max(0, $dispo), 0, ',', ' ')));
        }

        $qui = $ligne->getNomExpediteur() ?? $ligne->getExpediteur();
        $paiement = (new Paiements())
            ->setMontant($montant)
            ->setFondsSoutienInclus(($frais?->fondsSoutien ?? 0) > 0)
            ->setMontantFondsSoutien($frais?->fondsSoutien ?? 0)
            ->setFraisRetraitInclus(($frais?->fraisRetrait ?? 0) > 0)
            ->setMontantFraisRetrait($frais?->fraisRetrait ?? 0)
            ->setMode(Paiements::MODE_ORANGE_MONEY)
            ->setDate($ligne->getRecuLe())
            ->setReference($ligne->getReference())
            ->setEncaissePar($par)
            ->setObservation(mb_substr('Relevé Orange Money'.(null !== $qui ? ' · '.$qui : ''), 0, 255));
        // Un seul numéro Orange Money de la boutique : il est proposé (le compte des paiements en ligne n'en est pas un).
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['type' => CompteTresorerie::TYPE_ORANGE_MONEY, 'actif' => true, 'orangeMoneyEnLigne' => false]);
        if (1 === \count($comptes)) {
            $paiement->setCompteTresorerie($comptes[0]);
        }
        $commande->addPaiement($paiement);
        $this->em->persist($paiement);
        $ligne->associer($paiement, $score, $par, $maintenant);
        $this->em->flush();

        return $paiement;
    }

    public function ignorer(LigneReleveOrangeMoney $ligne, string $motif, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$ligne->estAAssocier()) {
            throw new \InvalidArgumentException('Cette transaction est déjà rattachée à un paiement.');
        }
        if ('' === trim($motif)) {
            throw new \InvalidArgumentException('Indiquez pourquoi ce n’est pas un paiement client.');
        }
        $ligne->ignorer($motif, $par, $maintenant);
        $this->em->flush();
    }

    /**
     * Lien WhatsApp (wa.me) pour envoyer l'accusé de réception au client :
     * la personne l'ouvre et envoie elle-même, rien ne part tout seul.
     */
    public static function lienRecu(Paiements $paiement, LigneReleveOrangeMoney $ligne): ?string
    {
        $commande = $paiement->getCommande();
        $client = $commande?->getClients();
        // Le numéro de la fiche client, sinon celui qui a payé.
        $telephone = $client?->getTelephone() ?: $ligne->getExpediteur();
        if (null === $commande || null === $telephone || null === self::numeroCourt($telephone)) {
            return null;
        }
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ');
        $reste = max(0, (int) $commande->getTotalTtc() - $commande->getTotalPaye() - ($paiement->estValide() ? 0 : $paiement->getMontant()));
        $message = \sprintf(
            'Bonjour %s, nous avons bien reçu votre paiement Orange Money de %s F (réf. %s) pour la commande %s. %s Merci, Succès Imprim.',
            $client?->getPrenom() ?: ($client?->getNomComplet() ?: 'cher client'),
            $f($paiement->getMontant()),
            $ligne->getReference(),
            $commande->getNumero(),
            $reste > 0 ? 'Reste à payer : '.$f($reste).' F.' : 'Votre commande est entièrement payée.',
        );

        return 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($message);
    }

    /**
     * Chiffres du haut de l'écran.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(\DateTimeImmutable $maintenant, int $aAssocier, int $montantAAssocier): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $jour = $this->em->createQueryBuilder()
            ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant', 'SUM(CASE WHEN p.statut = :attente THEN 1 ELSE 0 END) AS en_attente')
            ->from(Paiements::class, 'p')
            ->andWhere('p.statut IN (:statuts)')
            ->andWhere('p.mode NOT IN (:solde)')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
            ->setParameter('solde', Paiements::MODES_SANS_ENCAISSEMENT)
            ->setParameter('debut', $aujourdhui)
            ->setParameter('fin', $aujourdhui->modify('+1 day'))
            ->getQuery()
            ->getSingleResult();

        [$debut, $fin] = Periode::bornes($aujourdhui);
        $releve = $this->em->createQueryBuilder()
            ->select('COUNT(l.id) AS nombre', 'COALESCE(SUM(l.montant), 0) AS montant', 'SUM(CASE WHEN l.statut = :rapprochee THEN 1 ELSE 0 END) AS auto', 'SUM(CASE WHEN l.statut = :associee THEN 1 ELSE 0 END) AS associees', 'MAX(l.importeeLe) AS import')
            ->from(LigneReleveOrangeMoney::class, 'l')
            ->andWhere('l.statut != :ignoree')
            ->andWhere('l.recuLe >= :debut AND l.recuLe < :fin')
            ->setParameter('rapprochee', LigneReleveOrangeMoney::RAPPROCHEE)
            ->setParameter('associee', LigneReleveOrangeMoney::ASSOCIEE)
            ->setParameter('ignoree', LigneReleveOrangeMoney::IGNOREE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();
        $om = null;
        if (0 === (int) $releve['nombre']) {
            $om = $this->em->createQueryBuilder()
                ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant')
                ->from(Paiements::class, 'p')
                ->andWhere('p.mode = :om')
                ->andWhere('p.statut IN (:statuts)')
                ->andWhere('p.date >= :debut AND p.date < :fin')
                ->setParameter('om', Paiements::MODE_ORANGE_MONEY)
                ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
                ->setParameter('debut', $debut)
                ->setParameter('fin', $fin)
                ->getQuery()
                ->getSingleResult();
        }

        return [
            'jour' => ['montant' => (int) $jour['montant'], 'nombre' => (int) $jour['nombre'], 'en_attente' => (int) $jour['en_attente']],
            'om' => null === $om
                ? ['montant' => (int) $releve['montant'], 'nombre' => (int) $releve['nombre'], 'releve' => true, 'import' => null !== $releve['import'] ? new \DateTimeImmutable((string) $releve['import']) : null]
                : ['montant' => (int) $om['montant'], 'nombre' => (int) $om['nombre'], 'releve' => false, 'import' => null],
            'rapprochees' => ['auto' => (int) $releve['auto'], 'associees' => (int) $releve['associees'], 'total' => (int) $releve['nombre']],
            'a_associer' => ['nombre' => $aAssocier, 'montant' => $montantAAssocier],
        ];
    }

    /**
     * Derniers paiements, tous moyens confondus, avec leur rattachement au
     * relevé Orange Money.
     *
     * @return list<array{paiement: Paiements, releve: bool}>
     */
    public function derniersPaiements(int $nombre): array
    {
        /** @var list<Paiements> $paiements */
        $paiements = $this->em->createQueryBuilder()
            ->select('p', 'c', 'client', 'compte', 'facture')
            ->from(Paiements::class, 'p')
            ->leftJoin('p.commande', 'c')
            ->leftJoin('c.clients', 'client')
            ->leftJoin('p.compteTresorerie', 'compte')
            ->leftJoin('p.facture', 'facture')
            ->andWhere('p.mode NOT IN (:solde)')
            ->setParameter('solde', Paiements::MODES_SANS_ENCAISSEMENT)
            ->orderBy('p.date', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($nombre)
            ->getQuery()
            ->getResult();
        $lies = [] === $paiements ? [] : array_flip(array_map('intval', $this->em->createQueryBuilder()
            ->select('IDENTITY(l.paiement)')
            ->from(LigneReleveOrangeMoney::class, 'l')
            ->andWhere('l.paiement IN (:paiements)')
            ->setParameter('paiements', $paiements)
            ->getQuery()
            ->getSingleColumnResult()));

        return array_map(static fn (Paiements $p): array => ['paiement' => $p, 'releve' => isset($lies[(int) $p->getId()])], $paiements);
    }

    /** Taux des frais Orange Money (1 % et 1 % tant que rien n'est réglé). */
    private function parametresPaiement(): ParametresPaiement
    {
        return $this->parametresPaiement?->recuperer() ?? new ParametresPaiement();
    }

    /**
     * Paiements Orange Money valables d'une période ; sans les paiements déjà
     * rattachés à une transaction du relevé, sauf si $avecReference (import :
     * on cherche les références).
     *
     * @return list<Paiements>
     */
    private function paiementsOrangeMoney(\DateTimeImmutable $du, \DateTimeImmutable $au, bool $avecReference): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p', 'c', 'client')
            ->from(Paiements::class, 'p')
            ->leftJoin('p.commande', 'c')
            ->leftJoin('c.clients', 'client')
            ->leftJoin(LigneReleveOrangeMoney::class, 'l', 'WITH', 'l.paiement = p')
            ->andWhere('p.mode = :om')
            ->andWhere('p.statut IN (:statuts)')
            ->andWhere('p.date >= :du AND p.date < :au')
            ->andWhere('l.id IS NULL')
            ->setParameter('om', Paiements::MODE_ORANGE_MONEY)
            ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
            ->setParameter('du', $du)
            ->setParameter('au', $au);
        if ($avecReference) {
            $qb->andWhere('p.reference IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Paiements en attente de validation, par commande.
     *
     * @param list<int> $commandes
     *
     * @return array<int, int>
     */
    private function enAttenteParCommande(array $commandes): array
    {
        if ([] === $commandes) {
            return [];
        }
        $attente = [];
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(p.commande) AS commande', 'SUM(p.montant) AS total')
            ->from(Paiements::class, 'p')
            ->andWhere('p.commande IN (:commandes)')
            ->andWhere('p.statut = :attente')
            ->setParameter('commandes', $commandes)
            ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->groupBy('p.commande')
            ->getQuery()
            ->getArrayResult() as $l) {
            $attente[(int) $l['commande']] = (int) $l['total'];
        }

        return $attente;
    }

    /**
     * Repère les colonnes utiles : égalité exacte d'abord, puis début
     * d'en-tête (« Montant (FCFA) »).
     *
     * @param list<string|null> $entetes
     *
     * @return array<string, int>
     */
    private static function colonnes(array $entetes): array
    {
        $normalises = array_map(static fn (?string $e): string => self::normaliser((string) $e), $entetes);
        $trouvees = [];
        foreach ([true, false] as $exact) {
            foreach (self::COLONNES as $cle => $synonymes) {
                if (isset($trouvees[$cle])) {
                    continue;
                }
                foreach ($normalises as $i => $entete) {
                    if ('' === $entete || \in_array($i, $trouvees, true)) {
                        continue;
                    }
                    foreach ($synonymes as $s) {
                        if ($entete === $s || (!$exact && str_starts_with($entete, $s.' '))) {
                            $trouvees[$cle] = $i;
                            continue 3;
                        }
                    }
                }
            }
        }

        return $trouvees;
    }

    /** @param list<string> $lignes */
    private static function separateur(array $lignes): string
    {
        $comptes = [';' => 0, ',' => 0, "\t" => 0];
        foreach ($lignes as $ligne) {
            foreach ($comptes as $s => $n) {
                $comptes[$s] = max($n, substr_count($ligne, $s));
            }
        }
        arsort($comptes);

        return (string) array_key_first($comptes);
    }

    /** Minuscules, sans accents ni ponctuation : « N° Transaction » → « n transaction ». */
    private static function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));
        $texte = strtr($texte, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '°' => ' ']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $texte)));
    }

    /** @param list<string> $mots */
    private static function contient(string $texte, array $mots): bool
    {
        foreach ($mots as $mot) {
            if (str_contains($texte, $mot)) {
                return true;
            }
        }

        return false;
    }

    /** « F-SI 2026/0398 » → « FSI20260398 ». */
    private static function compacter(string $texte): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(self::normaliser($texte)));
    }

    private static function nomsProches(string $a, string $b): bool
    {
        $mots = static fn (string $t): array => array_values(array_diff(array_filter(explode(' ', self::normaliser($t)), static fn (string $m): bool => \strlen($m) >= 3), self::MOTS_VIDES));

        return [] !== array_intersect($mots($a), $mots($b));
    }
}
