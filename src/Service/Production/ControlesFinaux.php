<?php

namespace App\Service\Production;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControleFinal;
use App\Entity\JournalActivite;
use App\Entity\JournalMachine;
use App\Entity\OrdreProduction;
use App\Entity\StockSorties;
use App\Entity\User;
use App\Repository\ControleFinalRepository;
use App\Service\Atelier\ImagesAtelier;
use App\Service\Atelier\PosteMachiniste;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Livraisons\PreparationLivraison;
use App\Service\Livraisons\ProgrammationLivraison;
use App\Service\Livraisons\Tournee;
use App\Service\NotificationService;
use App\Service\Pointage\Pointages;
use App\Service\SuiviMetiers\FileMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contrôle final d'une commande (PD06, docs/production-colis.md) :
 * commandes à contrôler, comparaison au BAT et photo de la pièce, cases
 * par ligne, temps et matière consommée, « Refaire une pièce », puis la
 * carte « Prête pour la livraison » (tournée, message WhatsApp préparé).
 * Aucun montant, aucun coût. ERP local seulement.
 */
final class ControlesFinaux
{
    public const DOSSIER_PHOTOS = 'var/uploads/production/controles';
    public const TAILLE_MAX_PHOTO = 15 * 1024 * 1024;
    /** Nom d'une photo enregistrée : choisi par le serveur, jamais par le navigateur. */
    public const MOTIF_PHOTO = '/^\d{1,10}-[a-f0-9]{16}\.(png|jpg)$/';
    public const JOURNAL_WHATSAPP = 'whatsapp_prete';
    /** Créneau proposé quand le client n'a rien programmé. */
    public const CRENEAU_DEFAUT = '10-12';

    private const ETAPES_COURTES = ['impression' => 'Impression', 'sechage' => 'Séchage', 'finitions' => 'Finition', 'controle' => 'Contrôle'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ColisCommandes $colis,
        private readonly ControleFinalRepository $controles,
        private readonly PosteMachiniste $poste,
        private readonly EspaceClient $espace,
        private readonly NotificationService $notifications,
        private readonly FileMachine $file,
        private readonly PreparationLivraison $preparation,
        private readonly Tournee $tournee,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projet,
    ) {
    }

    /**
     * Commandes dont tous les travaux sont terminés et pas encore prêtes,
     * la livraison la plus proche d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function aControler(\DateTimeImmutable $maintenant): array
    {
        $parCommande = [];
        foreach ($this->colis->ordresTermines(null, $maintenant) as $o) {
            $c = $o->getCommandeDetail()?->getCommande();
            if (null !== $c) {
                $parCommande[(int) $c->getId()][] = $o;
            }
        }
        $liste = [];
        foreach ($parCommande as $ordres) {
            $commande = $ordres[0]->getCommandeDetail()->getCommande();
            if ($this->colis->lignesEnProduction($commande) > 0) {
                continue;
            }
            $etat = $this->colis->etat($commande);
            $liste[] = [
                'commande' => $commande,
                'client' => ColisCommandes::client($commande->getClients()),
                'resume' => ColisCommandes::resume(ColisCommandes::lignesPourColis($ordres)),
                'etat' => $etat,
                'controle' => $etat['controle']?->estValide() ?? false,
                'livraison' => $this->colis->livraison($commande),
            ];
        }
        usort($liste, static fn (array $a, array $b): int => [$a['livraison']['jour'] ?? new \DateTimeImmutable('2999-01-01'), $a['commande']->getId()] <=> [$b['livraison']['jour'] ?? new \DateTimeImmutable('2999-01-01'), $b['commande']->getId()]);

        return $liste;
    }

    /**
     * Commandes passées « Prête » aujourd'hui, la plus récente d'abord
     * (bas de la liste PD06) : livraison planifiée ou à planifier.
     *
     * @return list<array{commande: Commandes, client: array<string, mixed>, le: \DateTimeImmutable, livraison: array<string, mixed>}>
     */
    public function preteesDuJour(\DateTimeImmutable $maintenant): array
    {
        $rangs = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.commande) AS id', 'MAX(d.preteLivraisonLe) AS le')
            ->from(CommandesDetails::class, 'd')->join('d.commande', 'c')
            ->where('d.preteLivraisonLe >= :debut')->andWhere('c.deleted = false')
            ->setParameter('debut', $maintenant->setTime(0, 0))
            ->groupBy('d.commande')->orderBy('le', 'DESC')->setMaxResults(30)
            ->getQuery()->getArrayResult();
        $liste = [];
        foreach ($rangs as $r) {
            $commande = $this->em->find(Commandes::class, (int) $r['id']);
            if (!$commande instanceof Commandes || !ColisCommandes::estPrete($commande)) {
                continue;
            }
            $liste[] = [
                'commande' => $commande,
                'client' => ColisCommandes::client($commande->getClients()),
                'le' => new \DateTimeImmutable((string) $r['le']),
                'livraison' => $this->colis->livraison($commande),
            ];
        }

        return $liste;
    }

    /**
     * Tout ce que montre la page de contrôle d'une commande.
     *
     * @return array<string, mixed>
     */
    public function page(Commandes $commande, User $moi, bool $peutPlanifier, \DateTimeImmutable $maintenant): array
    {
        $etat = $this->colis->etat($commande);
        $prete = $etat['prete'];
        $ordres = $this->ordres($commande);
        $aControler = array_values(array_filter($ordres, static fn (OrdreProduction $o): bool => $o->estTermine() && !$o->estPretLivraison()));
        $montres = $prete ? array_values(array_filter($ordres, static fn (OrdreProduction $o): bool => $o->estPretLivraison())) : $aControler;
        $lignes = array_map(function (OrdreProduction $o) use ($etat): array {
            $ligne = $this->ligne($o, $etat['controle']);

            return $ligne + ['points' => self::points($ligne)];
        }, $montres);

        return [
            'commande' => $commande,
            'client' => ColisCommandes::client($commande->getClients()),
            'lignes' => $lignes,
            'aRefaire' => array_values(array_filter($ordres, static fn (OrdreProduction $o): bool => null !== $o->getPiecesARefaire())),
            'enProduction' => $this->colis->lignesEnProduction($commande),
            'etat' => $etat,
            'controle' => $etat['controle'],
            'prete' => $prete,
            'peutValider' => !$prete && [] !== $aControler && 0 === $this->colis->lignesEnProduction($commande),
            'temps' => $this->temps($montres),
            'consomme' => $this->consomme($commande),
            'livraison' => $this->colis->livraison($commande),
            'apres' => $prete ? $this->apresPrete($commande, $moi, $peutPlanifier, $maintenant) : null,
        ];
    }

    /**
     * Une ligne à contrôler : BAT, taille attendue, couleur à surveiller,
     * finitions (œillets comptés), cases déjà cochées.
     *
     * @return array<string, mixed>
     */
    private function ligne(OrdreProduction $o, ?ControleFinal $controle): array
    {
        $d = $o->getCommandeDetail();
        $produit = NomsProduits::reconnaitre($d?->getDesignation(), $d?->getProduit()?->getNom(), $d?->getProduit()?->getNomCommercial());
        $l = round((float) ($d?->getLargeur() ?? 0) * 100, 1);
        $h = round((float) ($d?->getLongueur() ?? 0) * 100, 1);
        $ctl = $this->poste->dernierControle($o);
        $bat = null !== $ctl ? ($this->espace->imagesBat($ctl)[0] ?? null) : null;
        $fichier = $bat ?? PosteMachiniste::fichier($o);
        $finitions = [];
        $oeillets = null;
        foreach ($d?->getFinitions() ?? [] as $f) {
            $nom = (string) $f->getNomFinition();
            $espacement = ReglesControleFinal::espacementOeillets($nom);
            if (null !== $espacement && $l > 0 && $h > 0) {
                $oeillets = ReglesControleFinal::oeilletsParPiece($l, $h, $espacement);
            } elseif ('' !== trim($nom)) {
                $finitions[] = ColisCommandes::finitionCourte($nom);
            }
        }
        $piece = $produit['singulier'] ?? 'pièce';
        $texteFinitions = implode(' · ', array_filter([
            null !== $oeillets ? \sprintf('Œillets : %d par %s, bien sertis', $oeillets, $piece) : null,
            [] !== $finitions ? ucfirst(implode(', ', array_unique($finitions))).' conforme'.(\count($finitions) > 1 ? 's' : '') : null,
        ]));
        $couleur = ReglesControleFinal::couleurSensible($d?->getObservation(), $d?->getDemandeClient(), $ctl?->getObservation(), $d?->getCommande()?->getObservation(), $o->getInstructions());
        $nombre = static fn (float $v): string => QuantiteLisible::nombre($v, 1);

        return [
            'ordre' => $o,
            'detail' => $d,
            'id' => (int) $d?->getId(),
            'nom' => null !== $d ? ColisCommandes::nomCourt($d, $produit) : (string) $o->getNumero(),
            'quantite' => (int) ($d?->getQuantite() ?? 0),
            'bat' => [
                'fichier' => $fichier,
                'image' => null !== $fichier && 'image' === PosteMachiniste::typeApercu($fichier),
                'version' => $fichier?->getVersion(),
                'valide' => null !== $ctl && \in_array($ctl->getStatut(), ['valide', 'envoye_production'], true) || null !== $ctl?->getValideLe(),
            ],
            'taille' => $l > 0 && $h > 0 ? ['l' => $l, 'h' => $h, 'texte' => $nombre($l).' × '.$nombre($h).' cm'] : null,
            'format' => $d?->getFormat()?->getNom(),
            'couleur' => $couleur,
            'finitions' => $texteFinitions,
            'verif' => $controle?->verificationsLigne((int) $d?->getId()) ?? [],
        ];
    }

    /** @return list<OrdreProduction> les travaux non annulés de la commande */
    public function ordres(Commandes $commande): array
    {
        return $this->em->createQueryBuilder()->select('o', 'd')->from(OrdreProduction::class, 'o')->join('o.commandeDetail', 'd')
            ->where('d.commande = :c')->andWhere('o.statut <> :annule')
            ->setParameter('c', $commande)->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->orderBy('d.id', 'ASC')->addOrderBy('o.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Temps estimé par étape (OP03) et temps réel total sans les pauses, par
     * ligne et pour la commande. Bloc à part : le temps réel par étape
     * viendra s'y ajouter.
     *
     * @param list<OrdreProduction> $ordres
     *
     * @return array{lignes: list<array<string, mixed>>, estime: ?int, reel: ?int}
     */
    public function temps(array $ordres): array
    {
        $lignes = [];
        foreach ($ordres as $o) {
            $etapes = [];
            foreach ($o->getEstimation()['etapes'] ?? [] as $e) {
                $minutes = (int) ($e['estime'] ?? $e['propose'] ?? 0);
                if ($minutes > 0) {
                    $etapes[] = ['libelle' => self::ETAPES_COURTES[$e['cle'] ?? ''] ?? (string) ($e['libelle'] ?? 'Étape'), 'estime' => $minutes];
                }
            }
            $estime = [] !== $etapes ? array_sum(array_column($etapes, 'estime')) : $o->getDureeEstimeeMinutes();
            $lignes[] = ['nom' => null !== $o->getCommandeDetail() ? PosteMachiniste::titre($o->getCommandeDetail()) : (string) $o->getNumero(), 'etapes' => $etapes, 'estime' => $estime, 'reel' => $o->getDureeReelleMinutes()];
        }
        $somme = static fn (string $cle): ?int => [] === $lignes || \in_array(null, array_column($lignes, $cle), true) ? null : array_sum(array_column($lignes, $cle));

        return ['lignes' => $lignes, 'estime' => $somme('estime'), 'reel' => $somme('reel')];
    }

    /**
     * Ce qui est sorti du stock pour les lignes de la commande à « Terminé »
     * (reprises comprises), par article, en unités lisibles. Jamais de prix.
     *
     * @return list<array{article: string, quantite: string}>
     */
    public function consomme(Commandes $commande): array
    {
        $rangs = $this->em->createQueryBuilder()
            ->select('a.id AS id', 'a.designation AS designation', 'a.unite AS unite', 'SUM(s.quantite) AS quantite')
            ->from(StockSorties::class, 's')->join('s.article', 'a')->join('s.commandeDetail', 'd')
            ->where('d.commande = :c')->andWhere('s.origine = :origine')
            ->setParameter('c', $commande)->setParameter('origine', StockSorties::ORIGINE_PRODUCTION)
            ->groupBy('a.id', 'a.designation', 'a.unite')->orderBy('a.designation', 'ASC')
            ->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $r): array => ['article' => (string) $r['designation'], 'quantite' => QuantiteLisible::texte((float) $r['quantite'], (string) $r['unite'])], array_filter($rangs, static fn (array $r): bool => (float) $r['quantite'] > 0)));
    }

    /**
     * Enregistre les cases cochées ; le contrôle est validé quand tout est
     * coché (et un écart de taille confirmé). La commande passe « Prête »
     * si ses colis sont déjà tous scannés.
     *
     * @param array<string, mixed> $saisie par ligne : cases, mesures, écart confirmé
     *
     * @return array{manque: list<string>, prete: bool}
     */
    public function valider(Commandes $commande, array $saisie, User $par, \DateTimeImmutable $le): array
    {
        if ($this->colis->etat($commande)['prete']) {
            throw new \LogicException('Cette commande est déjà prête.');
        }
        if ($this->colis->lignesEnProduction($commande) > 0) {
            throw new \LogicException('Une pièce est encore en production : contrôlez la commande quand elle est terminée.');
        }
        $ordres = array_values(array_filter($this->ordres($commande), static fn (OrdreProduction $o): bool => $o->estTermine() && !$o->estPretLivraison()));
        if ([] === $ordres) {
            throw new \LogicException('Aucun travail terminé à contrôler pour cette commande.');
        }
        $manque = [];
        $verifications = [];
        foreach ($ordres as $o) {
            $ligne = $this->ligne($o, null);
            $s = \is_array($saisie[(string) $ligne['id']] ?? null) ? $saisie[(string) $ligne['id']] : [];
            $v = [];
            foreach (self::points($ligne) as $point => $libelle) {
                $v[$point] = '1' === (string) ($s[$point] ?? '');
                if (!$v[$point]) {
                    $manque[] = $ligne['nom'].' : '.$libelle;
                }
            }
            if (null !== $ligne['taille']) {
                $mesure = ['l' => self::cm($s['mesure_l'] ?? null) ?? $ligne['taille']['l'], 'h' => self::cm($s['mesure_h'] ?? null) ?? $ligne['taille']['h']];
                $ecart = ReglesControleFinal::tailleHorsTolerance($ligne['taille']['l'], $mesure['l']) || ReglesControleFinal::tailleHorsTolerance($ligne['taille']['h'], $mesure['h']);
                $v['mesure'] = $mesure;
                $v['ecart'] = $ecart;
                if ($ecart && '1' !== (string) ($s['ecart_confirme'] ?? '')) {
                    $manque[] = \sprintf('%s : la taille mesurée (%s × %s cm) s\'écarte de la commande, confirmez-la', $ligne['nom'], QuantiteLisible::nombre($mesure['l'], 1), QuantiteLisible::nombre($mesure['h'], 1));
                }
            }
            $verifications[(string) $ligne['id']] = $v;
        }
        if ([] !== $manque) {
            return ['manque' => $manque, 'prete' => false];
        }
        $controle = $this->controles->deLaCommande($commande);
        if (null === $controle) {
            $controle = new ControleFinal($commande);
            $this->em->persist($controle);
        }
        $controle->valider($verifications, $par, $le);
        $this->em->flush();

        return ['manque' => [], 'prete' => $this->colis->passerPrete($commande, $par, $le)];
    }

    /**
     * Cases d'une ligne : textes, couleurs, taille (ou format), finitions
     * s'il y en a, traces.
     *
     * @param array<string, mixed> $ligne
     *
     * @return array<string, string>
     */
    public static function points(array $ligne): array
    {
        $points = [
            'textes' => 'Textes et numéro identiques au BAT',
            'couleurs' => null !== $ligne['couleur'] ? 'Couleurs : '.$ligne['couleur'].' conforme' : 'Couleurs conformes au BAT',
            'taille' => null !== $ligne['taille'] ? 'Taille mesurée : '.$ligne['taille']['texte'] : 'Format '.($ligne['format'] ?? 'commandé').' respecté',
        ];
        if ('' !== $ligne['finitions']) {
            $points['finitions'] = $ligne['finitions'];
        }
        $points['traces'] = 'Aucune trace, rayure ou bande';

        return $points;
    }

    /** « 300 », « 299,5 » → cm ; null si vide ou faux. */
    public static function cm(mixed $saisie): ?float
    {
        $texte = str_replace([',', ' '], ['.', ''], trim((string) $saisie));
        if ('' === $texte || !is_numeric($texte) || (float) $texte <= 0 || (float) $texte > 10000) {
            return null;
        }

        return round((float) $texte, 1);
    }

    /**
     * Photo de la pièce imprimée : contrôlée, réencodée sans métadonnées
     * (EXIF, position), rangée hors de public/ sous un nom choisi ici.
     */
    public function photographier(Commandes $commande, UploadedFile $fichier, User $par, \DateTimeImmutable $le): void
    {
        if (!$fichier->isValid() || $fichier->getSize() > self::TAILLE_MAX_PHOTO) {
            throw new \DomainException('Photo refusée : 15 Mo au plus, en PNG, JPG ou WebP.');
        }
        $image = ImagesAtelier::reencoder((string) file_get_contents($fichier->getPathname()));
        $dossier = $this->projet.'/'.self::DOSSIER_PHOTOS;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Le dossier des photos du contrôle final ne peut pas être créé.');
        }
        $nom = \sprintf('%d-%s.%s', (int) $commande->getId(), bin2hex(random_bytes(8)), 'png' === $image['extension'] ? 'png' : 'jpg');
        if (false === file_put_contents($dossier.'/'.$nom, $image['octets'])) {
            throw new \RuntimeException('La photo n\'a pas pu être enregistrée.');
        }
        $controle = $this->controles->deLaCommande($commande);
        if (null === $controle) {
            $controle = new ControleFinal($commande);
            $this->em->persist($controle);
        }
        $ancien = $this->cheminPhoto($controle);
        $controle->photographier($nom, $par, $le);
        $this->em->flush();
        if (null !== $ancien) {
            @unlink($ancien);
        }
    }

    /** Chemin de la photo sur le disque, ou null (nom inattendu, fichier absent). */
    public function cheminPhoto(?ControleFinal $controle): ?string
    {
        $nom = (string) $controle?->getPhoto();
        if (preg_match(self::MOTIF_PHOTO, $nom) !== 1) {
            return null;
        }
        $chemin = $this->projet.'/'.self::DOSSIER_PHOTOS.'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * « Refaire une pièce » : le travail repart en production pour $pieces
     * pièces (il revient dans Ma file, colonne « Prêt »), la ligne aussi ;
     * le contrôle final est à refaire ; la réimpression est notée au journal
     * de la machine (due à l'opérateur qui l'a imprimée) et le chef
     * d'atelier est prévenu. Les colis gardent leurs étiquettes.
     */
    public function refaire(OrdreProduction $ordre, int $pieces, string $motif, User $par, \DateTimeImmutable $le): void
    {
        $ligne = $ordre->getCommandeDetail() ?? throw new \LogicException('Ce travail n\'est rattaché à aucune ligne de commande.');
        $commande = $ligne->getCommande() ?? throw new \LogicException('Ce travail n\'est rattaché à aucune commande.');
        $motif = trim(mb_substr(preg_replace('/\s+/u', ' ', $motif) ?? '', 0, 200));
        if ('' === $motif) {
            throw new \InvalidArgumentException('Dites en quelques mots ce qui ne va pas (le chef d\'atelier le lit).');
        }
        if ($this->colis->etat($commande)['prete']) {
            throw new \LogicException('Cette commande est déjà prête : elle ne se reprend plus ici.');
        }
        $ordre->refaire($pieces, $le);
        $ligne->rouvrirPourRefaire($pieces);
        $this->controles->deLaCommande($commande)?->rouvrir($le);

        $nom = PosteMachiniste::titre($ligne);
        if (null !== $ordre->getMachine()) {
            $surface = (float) ($ligne->getLargeur() ?? 0) * (float) ($ligne->getLongueur() ?? 0);
            $this->em->persist(new JournalMachine($ordre->getMachine(), $ordre->getTerminePar() ?? $ordre->getOperateur() ?? $par, JournalMachine::REIMPRESSION, $le, $ordre, $surface > 0 ? round($surface * $pieces, 2) : null, \sprintf('Contrôle final : %d pièce%s à refaire. %s', $pieces, $pieces > 1 ? 's' : '', $motif)));
        }
        $this->notifications->notifierRoles(
            ['ROLE_ATELIER_CHEF'],
            \sprintf('%s : %d pièce%s à refaire (%s). %s', $commande->getNumero(), $pieces, $pieces > 1 ? 's' : '', $nom, $motif),
            'app_ma_file_travail',
            ['id' => $ordre->getId()],
            $par,
        );
        $this->em->flush();
    }

    /**
     * Après « Prête » : livreur, jour et créneau du bon, la tournée du jour,
     * le message WhatsApp préparé (ou pourquoi il n'y en a pas) ; pour le
     * bureau, le formulaire « Ajouter à la tournée ».
     *
     * @return array<string, mixed>
     */
    public function apresPrete(Commandes $commande, User $moi, bool $peutPlanifier, \DateTimeImmutable $maintenant): array
    {
        $livraison = $this->colis->livraison($commande);
        $bon = $livraison['bon'];
        $planifie = null !== $bon && BonLivraison::STATUT_VALIDE === $bon->getStatut() && null !== $bon->getLivreur() && null !== $bon->getPrevuLe();
        $livreurDuBon = null !== $bon && $bon->getLivreur() === $moi;
        $tournee = null;
        if ($planifie) {
            $arrets = \count($this->tournee->duJour($bon->getPrevuLe(), $bon->getLivreur()));
            $quand = MessagePrete::jour($bon->getPrevuLe(), $maintenant) ?? '';
            $heure = (int) substr((string) preg_replace('/\D+/', ' ', (string) $bon->getCreneau()), 0, 3);
            $moment = '' === trim((string) $bon->getCreneau()) ? '' : ($heure > 0 && $heure < 12 ? ' matin' : ($heure >= 12 ? ' après-midi' : ''));
            $tournee = [
                'livreur' => Pointages::nom($bon->getLivreur()),
                'prenom' => $bon->getLivreur()->getEmploye()?->getPrenom() ?: Pointages::nom($bon->getLivreur()),
                'jourCourt' => ProgrammationLivraison::jourCourt($bon->getPrevuLe()),
                'creneau' => $bon->getCreneau(),
                'arrets' => $arrets,
                'quand' => ('le ' === mb_substr($quand, 0, 3) ? mb_substr($quand, 3) : $quand).$moment,
            ];
        }

        // Message « commande prête » : jamais pour une fiche de démonstration ni pour le livreur de ce bon (code de réception).
        $client = ColisCommandes::client($commande->getClients());
        $articles = array_map(static fn (array $l): array => ['nom' => $l['produit'], 'quantite' => $l['quantite']], ColisCommandes::lignesPourColis(array_values(array_filter($this->ordres($commande), static fn (OrdreProduction $o): bool => $o->estPretLivraison()))));
        $texte = MessagePrete::texte($articles, (string) $commande->getNumero(), !$livraison['retrait'], $livraison['jour'], $livraison['creneau'], $planifie ? $bon->getCodeReception() : null, $maintenant);
        $whatsapp = [
            'demo' => $client['demo'],
            'cache' => $livreurDuBon,
            'texte' => $livreurDuBon ? null : $texte,
            'possible' => !$client['demo'] && !$livreurDuBon && null !== MessagePrete::lien($client['telephone'], $texte, false),
            'journal' => $this->dernierMessage($commande),
            'prenomClient' => trim((string) $commande->getClients()?->getPrenom()) ?: $client['nom'],
        ];

        $p = $commande->getProgrammationLivraison() ?? [];
        $jourDefaut = $livraison['jour'] ?? null;
        if (null === $jourDefaut || $jourDefaut < $maintenant->setTime(0, 0)) {
            $jourDefaut = self::prochainJourOuvre($maintenant);
        }

        return [
            'livraison' => $livraison,
            'bon' => $bon,
            'planifie' => $planifie,
            'tournee' => $tournee,
            'whatsapp' => $whatsapp,
            'peutPlanifier' => $peutPlanifier && !$livraison['retrait'] && !$planifie && (null === $bon || \in_array($bon->getStatut(), [BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_VALIDE], true)),
            'livreurs' => $peutPlanifier ? $this->tournee->livreurs() : [],
            'defaut' => [
                'livreur' => $bon?->getLivreur()?->getId(),
                'jour' => $jourDefaut,
                'creneau' => ProgrammationLivraison::CRENEAUX[$p['creneau'] ?? ''] ?? ($bon?->getCreneau() ?: ProgrammationLivraison::CRENEAUX[self::CRENEAU_DEFAUT]),
            ],
            'creneaux' => ProgrammationLivraison::CRENEAUX,
        ];
    }

    /** @return list<User> les livreurs actifs (Tournee::livreurs) */
    public function livreurs(): array
    {
        return $this->tournee->livreurs();
    }

    /** Lendemain, sans le dimanche. */
    public static function prochainJourOuvre(\DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        $jour = $maintenant->setTime(0, 0)->modify('+1 day');

        return '7' === $jour->format('N') ? $jour->modify('+1 day') : $jour;
    }

    /**
     * Un bouton pour le bureau : bon de livraison des lignes prêtes créé
     * (ou repris), validé, planifié dans la tournée du livreur au dernier
     * rang du jour ; le livreur est prévenu.
     */
    public function ajouterATournee(Commandes $commande, User $par, User $livreur, \DateTimeImmutable $jour, string $creneau): BonLivraison
    {
        if (!ColisCommandes::estPrete($commande)) {
            throw new \LogicException('La commande n\'est pas encore prête.');
        }
        if (!$commande->estALivrer()) {
            throw new \LogicException('Cette commande est à retirer à l\'imprimerie : pas de tournée.');
        }
        if (!\in_array($creneau, ProgrammationLivraison::CRENEAUX, true)) {
            throw new \InvalidArgumentException('Choisissez un créneau.');
        }
        $bon = $this->colis->dernierBon($commande);
        if (null !== $bon && !\in_array($bon->getStatut(), [BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_VALIDE], true)) {
            $bon = null;
        }
        $bon ??= $this->preparation->creerDepuisCommande($commande, $par)['bon'];
        if ($bon->estBrouillon()) {
            $this->preparation->valider($bon, $par);
        }
        $rang = \count(array_filter($this->tournee->duJour($jour, $livreur), static fn (BonLivraison $b): bool => $b !== $bon)) + 1;
        $this->preparation->planifier($bon, $livreur, $jour, $creneau, $rang);
        $this->notifications->notifierUtilisateur($livreur, \sprintf('%s ajoutée à votre tournée du %s (%s).', $commande->getNumero(), ProgrammationLivraison::jourCourt($jour), $creneau), 'app_livraisons_tournee');
        $this->em->flush();

        return $bon;
    }

    /**
     * Note au journal de la commande « message préparé à 11:04 par Oumar »,
     * sans le texte (il porte le code de réception).
     */
    public function noterMessage(Commandes $commande, User $par, \DateTimeImmutable $le): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite('Commandes')
            ->setEntiteId((int) $commande->getId())
            ->setAction(self::JOURNAL_WHATSAPP)
            ->setDonneesApres(['commande' => $commande->getNumero(), 'message' => 'commande prête, préparé sur WhatsApp', 'le' => $le->format('d/m/Y H:i')])
            ->setUtilisateur($par)
            ->setCreatedAt($le));
        $this->em->flush();
    }

    /** @return array{le: \DateTimeImmutable, par: string}|null */
    public function dernierMessage(Commandes $commande): ?array
    {
        $j = $this->em->getRepository(JournalActivite::class)->findOneBy(['entite' => 'Commandes', 'entiteId' => (int) $commande->getId(), 'action' => self::JOURNAL_WHATSAPP], ['id' => 'DESC']);
        if (!$j instanceof JournalActivite) {
            return null;
        }
        $u = $j->getUtilisateur();

        return ['le' => $j->getCreatedAt(), 'par' => null !== $u ? ($u->getEmploye()?->getPrenom() ?: Pointages::nom($u)) : 'quelqu\'un'];
    }

    /** Texte et lien wa.me du message, ou null (démo, pas de téléphone, livreur du bon). */
    public function lienMessage(Commandes $commande, User $moi, \DateTimeImmutable $maintenant): ?string
    {
        $apres = $this->apresPrete($commande, $moi, false, $maintenant);
        $client = ColisCommandes::client($commande->getClients());

        return null === $apres['whatsapp']['texte'] ? null : MessagePrete::lien($client['telephone'], $apres['whatsapp']['texte'], $client['demo']);
    }
}
