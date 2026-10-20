<?php

namespace App\Service\Achats;

use App\Entity\Achats;
use App\Entity\Articles;
use App\Entity\Fournisseurs;
use App\Entity\StockEntrees;
use App\Entity\StockSorties;
use App\Entity\User;
use App\Controller\UtilisateursController;
use App\Entity\Conversation;
use App\Repository\UserRepository;
use App\Service\NotificationsEquipe\Alerte;
use App\Service\NotificationsEquipe\NotificationsEquipe;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réapprovisionnement et alertes d'achat (maquettes SU06 et SU07,
 * docs/achats.md) : le tableau des articles (stock, consommation, jours
 * restants, délai et marge, intervalle habituel, date de commande), la
 * courbe du stock, les quatre règles d'alerte (stock bas, hausse de prix,
 * fournisseur en retard, commande oubliée), la commande proposée par
 * fournisseur et son brouillon.
 *
 * Les alertes partent en notifications de l'équipe (application et
 * téléphone) par app:achats:alertes ; l'écran les recalcule à chaque
 * ouverture. Les calculs sont dans CalculsAlertes (testés sans base).
 */
final class AlertesAchats
{
    /*
     * Qui reçoit les alertes : la direction, seule à avoir les écrans
     * Achats (access_control, /achats).
     */
    public const ROLE_DESTINATAIRES = 'ROLE_ADMIN';

    /*
     * Sorte de notification de chaque règle (TypesNotification::TYPES).
     */
    public const TYPES = [
        CalculsAlertes::STOCK_BAS => 'achat_stock_bas',
        CalculsAlertes::PRIX => 'achat_prix_hausse',
        CalculsAlertes::RETARD => 'achat_fournisseur_retard',
        CalculsAlertes::OUBLI => 'achat_commande_oubliee',
    ];

    /*
     * Libellés des règles (écran et Réglages).
     */
    public const LIBELLES = [
        CalculsAlertes::STOCK_BAS => 'Stock bas',
        CalculsAlertes::PRIX => 'Hausse de prix',
        CalculsAlertes::RETARD => 'Fournisseur en retard',
        CalculsAlertes::OUBLI => 'Commande oubliée',
    ];

    /*
     * Champ de la tuile « Achats et fournisseurs » qui allume chaque règle.
     */
    public const CHAMPS = [
        CalculsAlertes::STOCK_BAS => 'alerte_stock_bas',
        CalculsAlertes::PRIX => 'alerte_prix',
        CalculsAlertes::RETARD => 'alerte_retard',
        CalculsAlertes::OUBLI => 'alerte_oubli',
    ];

    /*
     * Jours de stock montrés avant et après aujourd'hui sur la courbe.
     */
    public const JOURS_COURBE = 30;

    /** @var array<string, mixed>|null */
    private ?array $analyse = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuiviAchats $suivi,
        private readonly PreparationAchat $preparation,
        private readonly Parametres $parametres,
        private readonly UserRepository $utilisateurs,
        private readonly NotificationsEquipe $equipe,
    ) {
    }

    /**
     * Les quatre règles : allumée ou non, et leur seuil.
     *
     * @return array<string, array{actif: bool, libelle: string, seuil: ?int}>
     */
    public function regles(): array
    {
        $valeur = fn (string $cle, mixed $defaut): mixed => $this->parametres->valeur('achats', $cle) ?? $defaut;

        return [
            CalculsAlertes::STOCK_BAS => ['actif' => (bool) $valeur('alerte_stock_bas', true), 'libelle' => self::LIBELLES[CalculsAlertes::STOCK_BAS], 'seuil' => $this->suivi->margeSecurite()],
            CalculsAlertes::PRIX => ['actif' => (bool) $valeur('alerte_prix', true), 'libelle' => self::LIBELLES[CalculsAlertes::PRIX], 'seuil' => (int) $valeur('hausse_prix_pourcent', CalculsAlertes::HAUSSE_PRIX_DEFAUT)],
            CalculsAlertes::RETARD => ['actif' => (bool) $valeur('alerte_retard', true), 'libelle' => self::LIBELLES[CalculsAlertes::RETARD], 'seuil' => 1],
            CalculsAlertes::OUBLI => ['actif' => (bool) $valeur('alerte_oubli', true), 'libelle' => self::LIBELLES[CalculsAlertes::OUBLI], 'seuil' => (int) $valeur('depassement_oubli_pourcent', CalculsAlertes::DEPASSEMENT_OUBLI_DEFAUT)],
        ];
    }

    /**
     * Allume ou éteint une règle (tuile « Achats et fournisseurs ») :
     * appliqué au local, proposé depuis le site en ligne.
     */
    public function basculer(string $regle, bool $actif, ?User $par): string
    {
        if (!isset(self::CHAMPS[$regle])) {
            throw new \InvalidArgumentException('Règle inconnue : '.$regle);
        }

        return $this->parametres->enregistrer('achats', [self::CHAMPS[$regle] => $actif], $par);
    }

    /**
     * Qui est prévenu : les comptes actifs de la direction, avec leur
     * prénom et leur numéro WhatsApp (fiche employé).
     *
     * @return list<array{utilisateur: User, nom: string, prenom: ?string, whatsapp: ?string}>
     */
    public function destinataires(): array
    {
        $liste = [];
        foreach ($this->utilisateurs->findBy(['actif' => true]) as $u) {
            if (!\in_array(self::ROLE_DESTINATAIRES, $u->getRoles(), true) || \in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true)) {
                continue;
            }
            $telephone = (string) $u->getEmploye()?->getTelephone();
            $liste[] = [
                'utilisateur' => $u,
                'nom' => Conversation::nomDe($u),
                'prenom' => $u->getEmploye()?->getPrenom(),
                'whatsapp' => trim($telephone) !== '' ? UtilisateursController::numeroWhatsApp($telephone) : null,
            ];
        }

        return $liste;
    }

    /**
     * Le tableau de l'écran (une ligne par article qui a un fournisseur,
     * le plus pressé d'abord), les alertes en cours et les commandes
     * proposées par fournisseur.
     *
     * @return array{lignes: list<array<string, mixed>>, alertes: list<array<string, mixed>>, propositions: array<int, array<string, mixed>>, sansConso: int}
     */
    public function analyser(\DateTimeImmutable $aujourdhui): array
    {
        if ($this->analyse !== null) {
            return $this->analyse;
        }

        $aujourdhui = $aujourdhui->setTime(0, 0);
        $regles = $this->regles();
        $reappro = $this->suivi->reappro($aujourdhui);
        $historique = $this->historiqueCommandes($aujourdhui);
        $tendances = $this->tendances(array_map(static fn (array $l): Articles => $l['article'], array_values($reappro)), $aujourdhui);
        $limiteRegroupement = $aujourdhui->modify(sprintf('+%d days', $this->suivi->joursRegroupement()));

        $lignes = [];
        $alertes = [];
        $sansConso = 0;

        foreach ($reappro as $id => $r) {
            /** @var Articles $article */
            $article = $r['article'];
            /** @var Fournisseurs $fournisseur */
            $fournisseur = $r['fournisseur'];
            $h = $historique['articles'][$id] ?? ['dates' => [], 'enCours' => [], 'brouillons' => []];
            $intervalle = CalculsAlertes::intervalleHabituel($h['dates']);
            $derniere = $h['dates'] !== [] ? max($h['dates'])->setTime(0, 0) : null;
            $nom = SuiviAchats::nomCourt($article);
            $enCours = $h['enCours'][0] ?? null;
            $brouillon = $h['brouillons'][0] ?? null;

            $ligne = $r + [
                'nom' => $nom,
                'intervalle' => $intervalle,
                'dates' => $h['dates'],
                'derniere' => $derniere,
                'enCours' => $enCours,
                'arrivee' => $enCours !== null ? $this->suivi->livraisonPrevue($enCours, $aujourdhui) : null,
                'brouillon' => $brouillon,
                'tendance' => $tendances[$id] ?? null,
                'quantite' => CalculsAlertes::quantiteSuggeree($r['consoHebdo'], $r['disponible'], $article->getStockMin(), $article->getStockMax(), $r['delai'], $r['marge'], $intervalle, $article->getFacteurConversion()),
                'alertes' => [],
                'aProposer' => false,
                'regroupe' => false,
            ];
            $ligne['texteQuantite'] = number_format($ligne['quantite'], 0, ',', ' ').' '.CalculsAchat::pluriel($article->getUniteAchat() ?: $article->getUniteCourte(), $ligne['quantite']);

            // Stock bas : sous le minimum, ou plus assez de jours pour le délai et la marge, sans commande en cours.
            if ($regles[CalculsAlertes::STOCK_BAS]['actif'] && $r['bientot'] && $enCours === null) {
                $ligne['alertes'][] = CalculsAlertes::STOCK_BAS;
                $ligne['aProposer'] = true;
                $alertes[] = [
                    'regle' => CalculsAlertes::STOCK_BAS,
                    'empreinte' => 'achats:stock:'.$id,
                    'gravite' => CalculsAlertes::graviteStock($r['urgence']),
                    'titre' => CalculsAlertes::titreStock($nom, $r['urgence'], $r['date']),
                    'detail' => CalculsAlertes::detailStock($r['disponible'], $article->getUniteCourte(), $r['joursRestants'], $fournisseur->getNomCourt(), $r['delai']),
                    'article' => $article,
                    'fournisseur' => $fournisseur,
                    'achat' => $brouillon,
                    'tri' => [CalculsAlertes::graviteStock($r['urgence']) === CalculsAlertes::ROUGE ? 0 : 1, $r['joursRestants'] ?? 0],
                ];
            }

            // Commande oubliée : l'intervalle habituel dépassé de 20 %, rien en cours ni déjà signalé en stock bas.
            $joursDepuis = $derniere !== null ? CalculsAchat::retardJours($derniere, $aujourdhui) : 0;
            if ($regles[CalculsAlertes::OUBLI]['actif'] && $enCours === null && $ligne['alertes'] === [] && $derniere !== null
                && CalculsAlertes::estOubliee($intervalle, $joursDepuis, (int) $regles[CalculsAlertes::OUBLI]['seuil'])) {
                $ligne['alertes'][] = CalculsAlertes::OUBLI;
                $ligne['aProposer'] = true;
                $alertes[] = [
                    'regle' => CalculsAlertes::OUBLI,
                    'empreinte' => 'achats:oubli:'.$id.':'.$derniere->format('Ymd'),
                    'gravite' => CalculsAlertes::JAUNE,
                    'titre' => CalculsAlertes::titreOubli($nom),
                    'detail' => CalculsAlertes::detailOubli((float) $intervalle, $joursDepuis, $derniere, $fournisseur->getNomCourt()),
                    'article' => $article,
                    'fournisseur' => $fournisseur,
                    'achat' => $brouillon,
                    'tri' => [2, -$joursDepuis],
                ];
            }

            if ($r['consoHebdo'] <= 0 && $ligne['alertes'] === []) {
                ++$sansConso;
                continue;
            }

            $lignes[$id] = $ligne;
        }

        // Les autres articles du même fournisseur à commander dans les jours de regroupement partagent le transport.
        $fournisseursAProposer = [];
        foreach ($lignes as $l) {
            if ($l['aProposer']) {
                $fournisseursAProposer[(int) $l['fournisseur']->getId()] = true;
            }
        }
        foreach ($lignes as $id => $l) {
            if (!$l['aProposer'] && $l['enCours'] === null && isset($fournisseursAProposer[(int) $l['fournisseur']->getId()]) && ($l['bientot'] || ($l['date'] !== null && $l['date'] <= $limiteRegroupement))) {
                $lignes[$id]['aProposer'] = true;
                $lignes[$id]['regroupe'] = true;
            }
        }

        // Hausse de prix : le dernier prix payé au fournisseur contre le précédent.
        if ($regles[CalculsAlertes::PRIX]['actif']) {
            foreach ($historique['prix'] as $p) {
                if (CalculsAchat::retardJours($p['date']->setTime(0, 0), $aujourdhui) > CalculsAlertes::JOURS_ALERTE_PRIX
                    || !CalculsAlertes::estHausse($p['precedent'], $p['prix'], (int) $regles[CalculsAlertes::PRIX]['seuil'])) {
                    continue;
                }
                $hausse = (float) CalculsAlertes::hausse($p['precedent'], $p['prix']);
                /** @var Articles $article */
                $article = $p['article'];
                $alertes[] = [
                    'regle' => CalculsAlertes::PRIX,
                    'empreinte' => 'achats:prix:'.$article->getId().':'.$p['achat']->getId(),
                    'gravite' => CalculsAlertes::JAUNE,
                    'titre' => CalculsAlertes::titrePrix(SuiviAchats::nomCourt($article), $hausse),
                    'detail' => CalculsAlertes::detailPrix($p['prix'], (int) $p['precedent'], $article->getUniteAchat(), $p['achat']->getFournisseur()->getNomCourt()),
                    'detailCourt' => sprintf('%s · %s', $p['achat']->getFournisseur()->getNomCourt(), $p['achat']->getNumero()),
                    'article' => $article,
                    'fournisseur' => $p['achat']->getFournisseur(),
                    'achat' => $p['achat'],
                    'hausse' => $hausse,
                    'tri' => [2, -$hausse],
                ];
                if (isset($lignes[(int) $article->getId()])) {
                    $lignes[(int) $article->getId()]['alertes'][] = CalculsAlertes::PRIX;
                }
            }
        }

        // Fournisseur en retard : commande partie, date promise passée, pas reçue.
        if ($regles[CalculsAlertes::RETARD]['actif']) {
            foreach ($historique['enCours'] as $achat) {
                $jours = CalculsAlertes::joursRetard($achat->getDatePromise(), $aujourdhui);
                if ($jours < 1 || $achat->getDatePromise() === null) {
                    continue;
                }
                $fournisseur = $achat->getFournisseur();
                $alertes[] = [
                    'regle' => CalculsAlertes::RETARD,
                    'empreinte' => 'achats:retard:'.$achat->getId(),
                    'gravite' => CalculsAlertes::graviteRetard($jours),
                    'titre' => CalculsAlertes::titreRetard($fournisseur->getNomCourt(), $achat->getNumero()),
                    'detail' => CalculsAlertes::detailRetard($achat->getDatePromise(), $jours).' · '.self::articlesDe($achat),
                    'article' => null,
                    'fournisseur' => $fournisseur,
                    'achat' => $achat,
                    'relance' => CalculsReception::lienWhatsApp(
                        $fournisseur->getContactTelephone() !== null ? UtilisateursController::numeroWhatsApp($fournisseur->getContactTelephone()) : null,
                        CalculsAlertes::messageRelance($fournisseur->getContactNom(), $achat->getNumero(), $achat->getDatePromise()),
                    ),
                    'tri' => [CalculsAlertes::graviteRetard($jours) === CalculsAlertes::ROUGE ? 0 : 1, -$jours],
                ];
            }
        }

        usort($alertes, static fn (array $a, array $b): int => $a['tri'] <=> $b['tri']);

        // Le plus pressé d'abord : sous le minimum, puis par date de commande.
        $rang = [CalculsAchat::URGENCE_SOUS_MINIMUM => 0, CalculsAchat::URGENCE_MAINTENANT => 1, CalculsAchat::URGENCE_BIENTOT => 2, CalculsAchat::URGENCE_PLUS_TARD => 3];
        $lignes = array_values($lignes);
        usort($lignes, static fn (array $a, array $b): int => [$a['enCours'] !== null ? 1 : 0, $rang[$a['urgence']] ?? 4, $a['date']?->getTimestamp() ?? PHP_INT_MAX, $a['nom']]
            <=> [$b['enCours'] !== null ? 1 : 0, $rang[$b['urgence']] ?? 4, $b['date']?->getTimestamp() ?? PHP_INT_MAX, $b['nom']]);

        $propositions = [];
        foreach ($lignes as $l) {
            if ($l['aProposer']) {
                $propositions[(int) $l['fournisseur']->getId()]['fournisseur'] = $l['fournisseur'];
                $propositions[(int) $l['fournisseur']->getId()]['lignes'][] = $l;
            }
        }

        return $this->analyse = ['lignes' => $lignes, 'alertes' => $alertes, 'propositions' => $propositions, 'sansConso' => $sansConso];
    }

    /**
     * La commande proposée à un fournisseur (maquette SU07) : les articles
     * en alerte et ceux à regrouper, aux quantités suggérées, avec le
     * transport et le transit de sa dernière commande. Pas enregistrée.
     *
     * @return array{achat: Achats, detail: array<string, mixed>, lignes: list<array<string, mixed>>, economie: int}|null
     */
    public function proposition(Fournisseurs $fournisseur, \DateTimeImmutable $aujourdhui): ?array
    {
        $proposition = $this->analyser($aujourdhui)['propositions'][(int) $fournisseur->getId()] ?? null;
        if ($proposition === null) {
            return null;
        }

        $achat = (new Achats())->setFournisseur($fournisseur);
        $achat->setAcomptePourcentage($fournisseur->getAcomptePourcentage());
        $achat->setOrigineAlerte($proposition['lignes'][0]['article']);
        foreach ($proposition['lignes'] as $l) {
            PreparationAchat::ajouterLigne($achat, $l['article'], $l['quantite']);
        }
        $this->preparation->preremplirFrais($achat);
        $achat->recalculerTotaux();

        $detail = SuiviAchats::detailCommande($achat);
        $economie = 0;
        if (\count($proposition['lignes']) > 1) {
            $part = array_sum(\array_slice(array_column($detail['lignes'], 'partTransport'), 1));
            $economie = CalculsAchat::economieRegroupement($this->suivi->statistiquesDe($fournisseur, $aujourdhui)['transports'], $part);
        }

        return ['achat' => $achat, 'detail' => $detail, 'lignes' => $proposition['lignes'], 'economie' => $economie];
    }

    /**
     * Enregistre la commande proposée en brouillon (numéro, préparée par),
     * à vérifier puis envoyer depuis l'écran de la commande.
     */
    public function creerBrouillon(Fournisseurs $fournisseur, \DateTimeImmutable $aujourdhui, ?User $par): ?Achats
    {
        $proposition = $this->proposition($fournisseur, $aujourdhui);
        if ($proposition === null) {
            return null;
        }

        $achat = $proposition['achat'];
        $achat->setCreePar($par);
        $achat->setNumero($this->suivi->prochainNumero(new \DateTimeImmutable()));
        SuiviAchats::figerCouts($achat);
        $this->em->persist($achat);
        $this->em->flush();
        $this->analyse = null;

        return $achat;
    }

    /**
     * Courbe du stock d'un article : les 30 derniers jours, la prévision,
     * le jour de commande et l'arrivée (maquette SU06).
     *
     * @param array<string, mixed> $ligne une ligne du tableau
     *
     * @return array<string, mixed>
     */
    public function courbe(array $ligne, \DateTimeImmutable $aujourdhui): array
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        /** @var Articles $article */
        $article = $ligne['article'];
        $depuis = $aujourdhui->modify(sprintf('-%d days', self::JOURS_COURBE - 1));
        $mouvements = [];

        foreach ([[StockEntrees::class, 'quantites', 1], [StockSorties::class, 'quantite', -1]] as [$classe, $champ, $signe]) {
            $lignes = $this->em->createQueryBuilder()
                ->select(sprintf('m.date AS date, m.%s AS q', $champ))
                ->from($classe, 'm')
                ->where('m.article = :a')
                ->andWhere('m.date >= :depuis')
                ->setParameter('a', $article)
                ->setParameter('depuis', $depuis)
                ->getQuery()
                ->getArrayResult();
            foreach ($lignes as $m) {
                $jour = CalculsAchat::retardJours($aujourdhui, $m['date']);
                if ($jour <= 0) {
                    $mouvements[$jour] = ($mouvements[$jour] ?? 0.0) + $signe * (float) $m['q'];
                }
            }
        }

        $jourCommande = $ligne['date'] !== null ? max(0, CalculsAchat::retardJours($aujourdhui, $ligne['date'])) : null;
        if ($ligne['enCours'] !== null) {
            $jourCommande = null;
        }
        $points = CalculsAlertes::courbe(
            CalculsAlertes::historique($ligne['disponible'], $mouvements, self::JOURS_COURBE),
            $ligne['disponible'],
            $ligne['consoHebdo'],
            $article->getStockMin(),
            $jourCommande,
            $ligne['delai'],
            self::JOURS_COURBE,
            self::JOURS_COURBE,
        );

        $dates = [];
        foreach ($points['x'] as $jour => $x) {
            $dates[] = ['x' => $x, 'date' => $aujourdhui->modify(sprintf('%+d days', $jour))];
        }

        return $points + [
            'dates' => $dates,
            'jourCommande' => $jourCommande,
            'dateCommande' => $jourCommande !== null ? $aujourdhui->modify(sprintf('+%d days', $jourCommande)) : null,
            'dateArrivee' => $jourCommande !== null ? $aujourdhui->modify(sprintf('+%d days', $jourCommande + $ligne['delai'])) : null,
        ];
    }

    /**
     * Met les notifications de l'équipe au niveau des alertes : nouvelles
     * créées (application et téléphone de la direction), celles qui ont
     * cessé résolues. Une règle éteinte retire ses notifications.
     *
     * @return array<string, array{creees: int, resolues: int}>
     */
    public function notifier(\DateTimeImmutable $maintenant): array
    {
        $this->analyse = null;
        $parRegle = array_fill_keys(CalculsAlertes::REGLES, []);

        foreach ($this->analyser($maintenant)['alertes'] as $a) {
            $parRegle[$a['regle']][] = new Alerte(
                self::TYPES[$a['regle']],
                $a['empreinte'],
                $a['titre'],
                [self::ROLE_DESTINATAIRES],
                $a['detail'],
                ...self::action($a),
            );
        }

        $bilan = [];
        foreach ($parRegle as $regle => $vivantes) {
            $bilan[$regle] = $this->equipe->synchroniser(self::TYPES[$regle], $vivantes, $maintenant);
        }
        $this->em->flush();

        return $bilan;
    }

    /**
     * Bouton de la notification : la commande proposée, la commande d'achat
     * ou l'article sur l'écran du réapprovisionnement.
     *
     * @param array<string, mixed> $alerte
     *
     * @return array{action: string, route: string, parametres: array<string, int>}
     */
    public static function action(array $alerte): array
    {
        return match ($alerte['regle']) {
            CalculsAlertes::STOCK_BAS, CalculsAlertes::OUBLI => $alerte['achat'] !== null
                ? ['action' => 'Voir le brouillon', 'route' => 'app_achat_edit', 'parametres' => ['id' => (int) $alerte['achat']->getId()]]
                : ['action' => 'Commander', 'route' => 'app_reappro_proposition', 'parametres' => ['fournisseur' => (int) $alerte['fournisseur']->getId()]],
            default => ['action' => 'Voir la commande', 'route' => 'app_achat_show', 'parametres' => ['id' => (int) $alerte['achat']->getId()]],
        };
    }

    /**
     * Commandes passées par article sur 12 mois (dates), commandes en
     * cours et brouillons qui le contiennent, et dernier prix payé par
     * article et fournisseur avec le précédent.
     *
     * @return array{articles: array<int, array{dates: list<\DateTimeImmutable>, enCours: list<Achats>, brouillons: list<Achats>}>, prix: list<array{article: Articles, achat: Achats, date: \DateTimeImmutable, prix: int, precedent: ?int}>, enCours: list<Achats>}
     */
    private function historiqueCommandes(\DateTimeImmutable $aujourdhui): array
    {
        $depuis = $aujourdhui->modify('-12 months');
        $articles = [];
        $prix = [];
        $enCours = [];

        $achats = $this->suivi->achats();
        // Du plus récent au plus ancien, par date de commande.
        usort($achats, static fn (Achats $a, Achats $b): int => [SuiviAchats::dateCommande($b), $b->getId()] <=> [SuiviAchats::dateCommande($a), $a->getId()]);

        foreach ($achats as $achat) {
            $passee = SuiviAchats::estPassee($achat);
            $date = SuiviAchats::dateCommande($achat);
            if ($achat->estEnCours()) {
                $enCours[] = $achat;
            }

            foreach ($achat->getLignes() as $ligne) {
                $article = $ligne->getArticle();
                if ($article === null) {
                    continue;
                }
                $id = (int) $article->getId();
                $articles[$id] ??= ['dates' => [], 'enCours' => [], 'brouillons' => []];

                if ($achat->estEnCours() || $achat->estAValider()) {
                    $articles[$id]['enCours'][] = $achat;
                } elseif ($achat->estBrouillon()) {
                    $articles[$id]['brouillons'][] = $achat;
                }
                if ($passee && $date >= $depuis) {
                    $articles[$id]['dates'][] = $date;
                }

                // Le prix : la commande la plus récente, puis la précédente chez le même fournisseur.
                if ($passee && $ligne->getPrixUnitaire() > 0) {
                    $cle = $id.':'.$achat->getFournisseur()?->getId();
                    if (!isset($prix[$cle])) {
                        $prix[$cle] = ['article' => $article, 'achat' => $achat, 'date' => $date, 'prix' => $ligne->getPrixUnitaire(), 'precedent' => null];
                    } elseif ($prix[$cle]['precedent'] === null && $prix[$cle]['achat'] !== $achat) {
                        $prix[$cle]['precedent'] = $ligne->getPrixUnitaire();
                    }
                }
            }
        }

        return ['articles' => $articles, 'prix' => array_values($prix), 'enCours' => $enCours];
    }

    /**
     * Sorties des 4 et des 12 dernières semaines : tendance en %.
     *
     * @param list<Articles> $articles
     *
     * @return array<int, ?int>
     */
    private function tendances(array $articles, \DateTimeImmutable $aujourdhui): array
    {
        if ($articles === []) {
            return [];
        }

        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.article) AS article')
            ->addSelect('COALESCE(SUM(CASE WHEN s.date >= :quatre THEN s.quantite ELSE 0 END), 0) AS recentes')
            ->addSelect('COALESCE(SUM(s.quantite), 0) AS total')
            ->from(StockSorties::class, 's')
            ->where('s.article IN (:articles)')
            ->andWhere('s.date >= :douze')
            ->groupBy('s.article')
            ->setParameter('articles', $articles)
            ->setParameter('quatre', $aujourdhui->modify('-28 days'))
            ->setParameter('douze', $aujourdhui->modify(sprintf('-%d days', CalculsAchat::JOURS_CONSOMMATION)))
            ->getQuery()
            ->getArrayResult();

        $tendances = [];
        foreach ($lignes as $l) {
            $tendances[(int) $l['article']] = CalculsAlertes::tendance((float) $l['recentes'], (float) $l['total']);
        }

        return $tendances;
    }

    /**
     * « vinyle blanc, micro-perforé ».
     */
    private static function articlesDe(Achats $achat): string
    {
        $noms = [];
        foreach ($achat->getLignes() as $ligne) {
            if ($ligne->getArticle() !== null) {
                $noms[] = mb_strtolower(SuiviAchats::nomCourt($ligne->getArticle()));
            }
        }

        return $noms !== [] ? implode(', ', array_unique($noms)) : 'commande';
    }
}
