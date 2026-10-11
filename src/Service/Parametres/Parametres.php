<?php

namespace App\Service\Parametres;

use App\Entity\AppareilAutorise;
use App\Entity\Articles;
use App\Entity\DecaissementRecurrent;
use App\Entity\Entreprise;
use App\Entity\Finition;
use App\Entity\JournalActivite;
use App\Entity\Machines;
use App\Entity\ModeleMessage;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Reglage;
use App\Entity\RegleRappelPaiement;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\EntrepriseRepository;
use App\Repository\ParametresPaiementRepository;
use App\Repository\ReglageRepository;
use App\Security\AccesEnLigne;
use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\CatalogueStock;
use App\Service\EcranTv;
use App\Service\NumerotationFactures;
use App\Service\ProgrammeEcranTv;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Écran Paramètres : quelles tuiles l'utilisateur voit, leur état, le
 * panneau d'édition rapide, l'enregistrement (ou la proposition depuis
 * l'admin en ligne) et le journal des changements.
 */
final class Parametres
{
    public const ENTITE_JOURNAL = 'Parametres';

    /** @var array<string, Reglage>|null */
    private ?array $reglages = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglageRepository $depot,
        private readonly EntrepriseRepository $entreprises,
        private readonly ParametresPaiementRepository $parametresPaiement,
        private readonly AuthorizationCheckerInterface $autorisations,
        private readonly AccesEnLigne $accesEnLigne,
        private readonly EcranTv $ecranTv,
        private readonly CatalogueStock $catalogueStock,
    ) {
    }

    /**
     * Clés des tuiles que l'utilisateur peut voir (un des rôles suffit).
     *
     * @return list<string>
     */
    public function visibles(): array
    {
        $visibles = [];

        foreach (CatalogueParametres::tuiles() as $cle => $tuile) {
            foreach ($tuile['roles'] as $role) {
                if ($this->autorisations->isGranted($role)) {
                    $visibles[] = $cle;
                    break;
                }
            }
        }

        return $visibles;
    }

    public function peutVoir(string $tuile): bool
    {
        return \in_array($tuile, $this->visibles(), true);
    }

    /**
     * Familles de tuiles visibles, avec état et badge de chaque tuile.
     *
     * @return array<string, array{libelle: string, couleur: string, tuiles: list<array<string, mixed>>}>
     */
    public function familles(): array
    {
        $catalogue = CatalogueParametres::tuiles();
        $familles = [];

        foreach ($this->visibles() as $cle) {
            $tuile = $catalogue[$cle];
            $familles[$tuile['famille']] ??= CatalogueParametres::FAMILLES[$tuile['famille']] + ['tuiles' => []];
            $familles[$tuile['famille']]['tuiles'][] = ['cle' => $cle] + $tuile + $this->etat($cle);
        }

        return array_intersect_key(array_replace(CatalogueParametres::FAMILLES, $familles), $familles);
    }

    /**
     * Entrées de la recherche directe : une ligne par contexte (nom, état,
     * libellés des champs, mots-clés, modèles de messages).
     *
     * @param array<string, array<string, mixed>> $familles
     *
     * @return list<array{tuile: string, libelle: string, famille: string, couleur: string, icone: string, textes: list<string>}>
     */
    public function indexRecherche(array $familles): array
    {
        $index = [];

        foreach ($familles as $famille) {
            foreach ($famille['tuiles'] as $tuile) {
                $textes = $tuile['motsCles'];
                foreach ($tuile['champs'] as $champ) {
                    $textes[] = $champ['libelle'].(isset($champ['aide']) ? ' · '.$champ['aide'] : '');
                }
                if ($tuile['cle'] === 'messages') {
                    foreach ($this->em->getRepository(ModeleMessage::class)->findBy([], ['nom' => 'ASC']) as $modele) {
                        $textes[] = 'Modèle « '.$modele->getNom().' »';
                    }
                }
                $textes[] = $tuile['etat'];

                $index[] = [
                    'tuile' => $tuile['cle'],
                    'libelle' => $tuile['libelle'],
                    'famille' => $famille['libelle'],
                    'couleur' => $famille['couleur'],
                    'icone' => $tuile['icone'],
                    'textes' => array_values(array_unique($textes)),
                ];
            }
        }

        return $index;
    }

    /**
     * @return array{etat: string, badge: ?array{texte: string, type: string}}
     */
    public function etat(string $cle): array
    {
        $v = $this->valeursStockees($cle);
        $compter = fn (string $classe, array $critere = []): int => $this->em->getRepository($classe)->count($critere);
        $badge = null;

        $etat = match ($cle) {
            'entites' => implode(' · ', array_map(static fn (Entreprise $e): string => $e->getNomCommercial() ?: $e->getRaisonSociale(), $this->entreprisesActives())) ?: 'aucune entité',
            'modeles' => 'devis, facture, BL, bon de commande',
            'numerotation' => $this->exemplesNumeros(),
            'taxes' => 'TVA '.$v['taux_tva'].' % · '.($v['tva_par_defaut'] ? 'par défaut' : 'au choix par facture'),
            'grille' => self::nb($compter(ProduitConfiguration::class), 'configuration').' · '.self::nb($compter(Produits::class), 'produit'),
            'produits' => self::nb($compter(Produits::class), 'produit').' · '.self::nb($compter(Finition::class), 'finition'),
            'paiements' => implode(', ', array_map(static fn (string $m): string => Paiements::MODES_LABELS[$m], $this->modesPaiementActifs())) ?: 'aucun mode actif',
            'relances' => $v['delai_heures'].' h de travail · +'.$v['points_a_temps'].' / −'.$v['points_retard'],
            'machines' => self::nb($compter(Machines::class), 'machine').' · amortissement '.($v['amortissement'] === 'usage' ? 'à l\'usage' : 'linéaire'),
            'stock' => $this->etatStock($badge),
            'montage' => $v['formats'].' · marge '.$v['marge_mm'].' mm',
            'utilisateurs' => self::nb($compter(User::class, ['actif' => true]), 'compte').' · '.self::nb($compter(RoleUtilisateur::class), 'rôle'),
            'pointage' => ($v['photo'] ? 'QR + photo' : 'QR').' · tolérance '.$v['tolerance_minutes'].' min',
            'horaires' => str_replace('-', ' – ', $v['jours']).' · '.self::heure($v['ouverture']).' – '.self::heure($v['fermeture']),
            'bareme' => '1 point = '.number_format((int) $v['valeur_point'], 0, ',', ' ').' F · prime dès '.$v['seuil_prime'].' pts',
            'avantages' => 'frais médicaux '.$v['frais_medicaux'].' %',
            'paie' => 'paie le '.$v['jour_paie'].($v['inps'] || $v['its'] ? ' · '.implode(' et ', array_keys(array_filter(['INPS' => $v['inps'], 'ITS' => $v['its']]))) : ''),
            'espace_client' => $v['code_sms'] ? 'connexion par code SMS' : 'connexion par mot de passe',
            'messages' => self::nb($compter(ModeleMessage::class), 'modèle'),
            'ecran_tv' => 'playlist de '.ProgrammeEcranTv::dureeBoucle($this->ecranTv->programme()['scenes']).' s',
            'appareils' => $this->etatAppareils($badge),
            'synchronisation' => 'pas encore active',
            'sauvegardes' => 'chaque nuit à '.self::heure($v['heure']),
            'taches' => self::nb($compter(DecaissementRecurrent::class, ['actif' => true]), 'dépense récurrente', 'dépenses récurrentes'),
            default => '',
        };

        $reglage = $this->reglage($cle);
        if ($reglage?->getProposition() !== null) {
            $badge = $this->accesEnLigne->estEnLigne()
                ? ['texte' => 'en attente', 'type' => 'approuver']
                : ['texte' => '1 à approuver', 'type' => 'approuver'];
        }

        return ['etat' => $etat, 'badge' => $badge];
    }

    /**
     * Ce que le panneau d'édition rapide affiche.
     *
     * @return array<string, mixed>
     */
    public function panneau(string $cle): array
    {
        $tuile = CatalogueParametres::tuiles()[$cle];
        $valeurs = $this->valeursCompletes($cle);
        $reglage = $this->reglage($cle);
        $proposition = $reglage?->getProposition();

        $propositionLisible = [];
        foreach ($proposition ?? [] as $champ => $valeur) {
            $definition = CatalogueParametres::champ($cle, $champ);
            if ($definition !== null) {
                $propositionLisible[] = ['libelle' => $definition['libelle'], 'avant' => CatalogueParametres::afficher($definition, $valeurs[$champ] ?? null), 'apres' => CatalogueParametres::afficher($definition, $valeur)];
            }
        }

        return [
            'cle' => $cle,
            'tuile' => $tuile,
            'famille' => CatalogueParametres::FAMILLES[$tuile['famille']],
            'valeurs' => $valeurs,
            'details' => $this->details($cle),
            'proposition' => $propositionLisible,
            'propose_par' => $reglage?->getProposePar(),
            'propose_le' => $reglage?->getProposeLe(),
            'modifie_par' => $reglage?->getModifiePar(),
            'modifie_le' => $reglage?->getModifieLe(),
            'en_ligne' => $this->accesEnLigne->estEnLigne(),
            'peut_valider' => !$this->accesEnLigne->estEnLigne() && $this->autorisations->isGranted('ROLE_ADMIN'),
        ] + $this->etat($cle);
    }

    /**
     * Applique la saisie, ou la met en attente depuis l'admin en ligne.
     * Renvoie 'applique', 'propose' ou 'inchange'.
     *
     * @param array<string, mixed> $valeurs valeurs déjà contrôlées
     */
    public function enregistrer(string $cle, array $valeurs, ?User $par): string
    {
        $diff = CatalogueParametres::differences($this->valeursCompletes($cle), $valeurs);
        if ($diff['apres'] === []) {
            return 'inchange';
        }

        $maintenant = new \DateTimeImmutable();
        $reglage = $this->reglageOuNouveau($cle);

        if ($this->accesEnLigne->estEnLigne()) {
            $reglage->proposer($diff['apres'], $par, $maintenant);
            $this->journaliser($reglage, $cle, 'proposition', $diff, $par);
            $this->em->flush();

            return 'propose';
        }

        $this->appliquer($reglage, $cle, $diff['apres'], $par, $maintenant);
        $this->journaliser($reglage, $cle, JournalActivite::ACTION_MODIFICATION, $diff, $par);
        $this->em->flush();

        return 'applique';
    }

    /**
     * Sur l'ERP local : applique le changement proposé depuis l'admin en ligne.
     */
    public function valider(string $cle, ?User $par): bool
    {
        $reglage = $this->reglage($cle);
        $proposition = $reglage?->getProposition();
        if ($reglage === null || $proposition === null || $this->accesEnLigne->estEnLigne()) {
            return false;
        }

        $diff = CatalogueParametres::differences($this->valeursCompletes($cle), $proposition);
        $this->appliquer($reglage, $cle, $proposition, $par, new \DateTimeImmutable());
        $reglage->retirerProposition();
        $this->journaliser($reglage, $cle, 'validation', $diff, $par);
        $this->em->flush();

        return true;
    }

    public function refuser(string $cle, ?User $par): bool
    {
        $reglage = $this->reglage($cle);
        $proposition = $reglage?->getProposition();
        if ($reglage === null || $proposition === null || $this->accesEnLigne->estEnLigne()) {
            return false;
        }

        $reglage->retirerProposition();
        $this->journaliser($reglage, $cle, 'refus', ['avant' => [], 'apres' => $proposition], $par);
        $this->em->flush();

        return true;
    }

    /**
     * Changements visibles par l'utilisateur, les plus récents d'abord.
     *
     * @return list<array{le: \DateTimeImmutable, par: ?User, tuile: string, libelle: string, action: string, changements: list<array{libelle: string, avant: string, apres: string}>}>
     */
    public function journal(int $nombre = 100): array
    {
        $visibles = $this->visibles();
        $catalogue = CatalogueParametres::tuiles();
        $lignes = [];

        $entrees = $this->em->getRepository(JournalActivite::class)->findBy(['entite' => self::ENTITE_JOURNAL], ['createdAt' => 'DESC', 'id' => 'DESC'], 500);

        foreach ($entrees as $entree) {
            $apres = $entree->getDonneesApres() ?? [];
            $avant = $entree->getDonneesAvant() ?? [];
            $tuile = (string) ($apres['_tuile'] ?? '');
            if (!\in_array($tuile, $visibles, true)) {
                continue;
            }

            $changements = [];
            foreach ($apres as $champ => $valeur) {
                $definition = CatalogueParametres::champ($tuile, (string) $champ);
                if ($definition !== null) {
                    $changements[] = [
                        'libelle' => $definition['libelle'],
                        'avant' => \array_key_exists($champ, $avant) ? CatalogueParametres::afficher($definition, $avant[$champ]) : '',
                        'apres' => CatalogueParametres::afficher($definition, $valeur),
                    ];
                }
            }

            $lignes[] = [
                'le' => $entree->getCreatedAt(),
                'par' => $entree->getUtilisateur(),
                'tuile' => $tuile,
                'libelle' => $catalogue[$tuile]['libelle'],
                'action' => $entree->getAction(),
                'changements' => $changements,
            ];

            if (\count($lignes) >= $nombre) {
                break;
            }
        }

        return $lignes;
    }

    /**
     * Valeur d'un réglage pour les modules qui le lisent.
     */
    public function valeur(string $tuile, string $cle): mixed
    {
        return $this->valeursCompletes($tuile)[$cle] ?? null;
    }

    /**
     * Heures de travail de l'atelier (tuiles Horaires et Pointage) : délais
     * des actions de caisse, jours ouvrés des absences et congés.
     */
    public function heuresTravail(): HeuresTravail
    {
        return new HeuresTravail(
            (string) $this->valeur('horaires', 'jours'),
            (string) $this->valeur('horaires', 'ouverture'),
            (string) $this->valeur('horaires', 'fermeture'),
            (string) $this->valeur('pointage', 'pause_debut'),
            (string) $this->valeur('pointage', 'pause_fin'),
            (string) $this->valeur('horaires', 'feries'),
        );
    }

    /** Champ de la tuile Paiements => mode de paiement. */
    public const MODES_PAIEMENT = [
        'especes' => Paiements::MODE_ESPECES,
        'orange_money' => Paiements::MODE_ORANGE_MONEY,
        'wave' => Paiements::MODE_WAVE,
        'virement' => Paiements::MODE_VIREMENT,
        'cheque' => Paiements::MODE_CHEQUE,
        'carte' => Paiements::MODE_CARTE,
    ];

    /**
     * Modes de paiement proposés à l'encaissement (tuile Paiements).
     *
     * @return list<string>
     */
    public function modesPaiementActifs(): array
    {
        $valeurs = $this->valeursStockees('paiements');

        return array_values(array_filter(self::MODES_PAIEMENT, static fn (string $mode, string $champ): bool => (bool) $valeurs[$champ], \ARRAY_FILTER_USE_BOTH));
    }

    /**
     * TVA d'une nouvelle ligne de devis ou de commande (tuile Taxes) :
     * le taux si « TVA proposée par défaut », sinon 0. Chaque ligne
     * reste modifiable.
     */
    public function tvaParDefaut(): int
    {
        $valeurs = $this->valeursStockees('taxes');

        return $valeurs['tva_par_defaut'] ? (int) $valeurs['taux_tva'] : 0;
    }

    /* ---------- Interne ---------- */

    /**
     * @return array<string, mixed>
     */
    private function valeursStockees(string $cle): array
    {
        return CatalogueParametres::avecDefauts(CatalogueParametres::tuiles()[$cle]['champs'] ?? [], $this->reglage($cle)?->getValeurs() ?? []);
    }

    /**
     * Valeurs stockées + valeurs lues à leur source.
     *
     * @return array<string, mixed>
     */
    private function valeursCompletes(string $cle): array
    {
        $valeurs = $this->valeursStockees($cle);

        foreach (CatalogueParametres::tuiles()[$cle]['champs'] ?? [] as $champ) {
            $valeurs[$champ['cle']] = match ($champ['source'] ?? null) {
                'entreprise' => $this->lireEntreprise($champ['cle']),
                'paiement' => $this->parametresPaiement->recuperer()->getTauxFraisRetrait(),
                default => $valeurs[$champ['cle']],
            };
        }

        return $valeurs;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function appliquer(Reglage $reglage, string $cle, array $changes, ?User $par, \DateTimeImmutable $le): void
    {
        $stockees = $reglage->getValeurs();

        foreach ($changes as $champ => $valeur) {
            $definition = CatalogueParametres::champ($cle, $champ);
            match ($definition['source'] ?? null) {
                'entreprise' => $this->ecrireEntreprise($champ, (bool) $valeur),
                'paiement' => $this->ecrirePaiement((float) $valeur),
                default => $stockees[$champ] = $valeur,
            };
        }

        $reglage->modifier($stockees, $par, $le);
    }

    /**
     * @param array{avant: array<string, mixed>, apres: array<string, mixed>} $diff
     */
    private function journaliser(Reglage $reglage, string $cle, string $action, array $diff, ?User $par): void
    {
        if ($reglage->getId() === null) {
            $this->em->flush();
        }

        $this->em->persist((new JournalActivite())
            ->setEntite(self::ENTITE_JOURNAL)
            ->setEntiteId((int) $reglage->getId())
            ->setAction($action)
            ->setDonneesAvant($diff['avant'])
            ->setDonneesApres(['_tuile' => $cle] + $diff['apres'])
            ->setUtilisateur($par));
    }

    private function reglage(string $cle): ?Reglage
    {
        $this->reglages ??= $this->depot->parCle();

        return $this->reglages[$cle] ?? null;
    }

    private function reglageOuNouveau(string $cle): Reglage
    {
        $reglage = $this->reglage($cle);
        if ($reglage === null) {
            $reglage = new Reglage($cle);
            $this->em->persist($reglage);
            $this->reglages[$cle] = $reglage;
        }

        return $reglage;
    }

    /**
     * @return list<Entreprise>
     */
    private function entreprisesActives(): array
    {
        return array_values(array_filter($this->entreprises->dansLOrdre(), static fn (Entreprise $e): bool => $e->isActif()));
    }

    private function entreprisePrincipale(): ?Entreprise
    {
        return $this->entreprisesActives()[0] ?? null;
    }

    private function lireEntreprise(string $champ): bool
    {
        $e = $this->entreprisePrincipale();

        return match ($champ) {
            'qrPaiement' => (bool) $e?->isQrPaiement(),
            'qrSuivi' => (bool) $e?->isQrSuivi(),
            'montantEnLettres' => (bool) $e?->isMontantEnLettres(),
            'afficherLogoPied' => (bool) $e?->isAfficherLogoPied(),
            default => false,
        };
    }

    private function ecrireEntreprise(string $champ, bool $valeur): void
    {
        $e = $this->entreprisePrincipale();
        if ($e === null) {
            return;
        }

        match ($champ) {
            'qrPaiement' => $e->setQrPaiement($valeur),
            'qrSuivi' => $e->setQrSuivi($valeur),
            'montantEnLettres' => $e->setMontantEnLettres($valeur),
            'afficherLogoPied' => $e->setAfficherLogoPied($valeur),
            default => null,
        };
    }

    private function ecrirePaiement(float $taux): void
    {
        $parametres = $this->parametresPaiement->recuperer();
        $parametres->setTauxFraisRetrait($taux);
        $this->em->persist($parametres);
    }

    private function exemplesNumeros(): string
    {
        $maintenant = new \DateTimeImmutable();
        $exemples = [];

        foreach ($this->entreprisesActives() as $e) {
            if ($e->getFormatNumero()) {
                $exemples[] = NumerotationFactures::formater($e->getFormatNumero(), $e->getCompteur() + 1, $maintenant);
            }
        }

        return $exemples ? implode(' · ', $exemples) : 'format par défaut';
    }

    /**
     * @param array{texte: string, type: string}|null $badge
     */
    private function etatStock(?array &$badge): string
    {
        $seuils = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')->from(Articles::class, 'a')
            ->where('a.stockMin > 0')
            ->getQuery()->getSingleScalarResult();

        // Même calcul que l'écran Stock (disponible = stock − réservations).
        $sous = CatalogueStock::indicateurs($this->catalogueStock->lignes($this->em->getRepository(Articles::class)->findAll(), new \DateTimeImmutable()))['sousSeuil'];

        if ($sous > 0) {
            $badge = ['texte' => $sous.' alerte'.($sous > 1 ? 's' : ''), 'type' => 'alerte'];
        }

        return self::nb($seuils, 'seuil').' · '.$sous.' sous le minimum';
    }

    /**
     * @param array{texte: string, type: string}|null $badge
     */
    private function etatAppareils(?array &$badge): string
    {
        $depot = $this->em->getRepository(AppareilAutorise::class);
        $attente = $depot->count(['etat' => AppareilAutorise::ETAT_EN_ATTENTE]);
        if ($attente > 0) {
            $badge = ['texte' => $attente.' à approuver', 'type' => 'approuver'];
        }

        return self::nb($depot->count(['etat' => AppareilAutorise::ETAT_AUTORISE]), 'appareil autorisé', 'appareils autorisés');
    }

    /**
     * Informations en lecture seule du panneau, pour les tuiles dont le
     * réglage se fait sur une page dédiée.
     *
     * @return list<array{libelle: string, valeur: string}>
     */
    private function details(string $cle): array
    {
        return match ($cle) {
            'entites', 'numerotation' => array_map(fn (Entreprise $e): array => [
                'libelle' => $e->getNomCommercial() ?: $e->getRaisonSociale(),
                'valeur' => $e->getFormatNumero() ? 'prochain n° '.NumerotationFactures::formater($e->getFormatNumero(), $e->getCompteur() + 1, new \DateTimeImmutable()) : 'numérotation par défaut',
            ], $this->entreprisesActives()),
            'appareils' => array_map(static fn (AppareilAutorise $a): array => [
                'libelle' => $a->getNom() ?: 'Appareil',
                'valeur' => 'demandé par '.$a->getNomUtilisateur().' · à approuver',
            ], $this->em->getRepository(AppareilAutorise::class)->findBy(['etat' => AppareilAutorise::ETAT_EN_ATTENTE], ['id' => 'DESC'], 5)),
            'relances' => array_map(static fn (RegleRappelPaiement $r): array => [
                'libelle' => $r->getNom(),
                'valeur' => ($r->isRetrait() ? 'commande prête non retirée' : 'paiement') . ' · tous les '.$r->getDelaiJours().' j',
            ], $this->em->getRepository(RegleRappelPaiement::class)->findBy(['actif' => true])),
            default => [],
        };
    }

    private static function nb(int $nombre, string $singulier, ?string $pluriel = null): string
    {
        return $nombre.' '.($nombre > 1 ? ($pluriel ?? $singulier.'s') : $singulier);
    }

    private static function heure(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => '0']);

        return $h.' h'.($m > 0 ? ' '.str_pad((string) $m, 2, '0', \STR_PAD_LEFT) : '');
    }
}
