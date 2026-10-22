<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ControlePrePresse;
use App\Service\Paiement\OrangeMoney;
use App\Service\Site\CatalogueSite;

/**
 * « Mes commandes » (maquette CD01) : pour chaque commande, ses six étapes
 * (Commande, Acompte, BAT, Production, Prête, Livrée), ce qui est payé et
 * ce qui reste, et le bouton du moment (valider le BAT, payer l'acompte,
 * suivre, payer le reste). Les montants seulement pour qui les voit
 * (CompteClient::montantsVisibles) ; payé, reste et boutons de paiement
 * pour le payeur seul.
 */
final class MesCommandesClient
{
    public const ONGLETS = ['toutes' => 'Toutes', 'action' => 'Mon action', 'en_cours' => 'En cours', 'a_payer' => 'À payer', 'livrees' => 'Livrées'];

    public function __construct(
        private readonly EspaceClient $espace,
        private readonly FacturesClient $factures,
    ) {
    }

    /**
     * @return array{lignes: list<array<string, mixed>>, annees: list<int>}
     */
    public function liste(CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        $client = $compte->getClient();
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR);
        // Ce qui est demandé maintenant (acompte, facture, reste), par commande.
        $aPayer = [];
        if ($payeur) {
            foreach ($this->factures->aPayer($client, $maintenant)['elements'] as $e) {
                $aPayer[(int) $e->commande->getId()] = $e;
            }
        }
        $lignes = [];
        $annees = [];
        foreach ($this->espace->commandes($client, 300) as $c) {
            $lignes[] = self::ligne($this->espace->carte($c), $aPayer[(int) $c->getId()] ?? null, $compte, $maintenant);
            if (null !== $c->getDateCommande()) {
                $annees[(int) $c->getDateCommande()->format('Y')] = true;
            }
        }
        krsort($annees);

        return ['lignes' => $lignes, 'annees' => array_keys($annees)];
    }

    /**
     * Une commande de la liste.
     *
     * @param array<string, mixed> $carte EspaceClient::carte
     *
     * @return array<string, mixed>
     */
    public static function ligne(array $carte, ?ElementAPayer $element, CompteClient $compte, \DateTimeImmutable $maintenant): array
    {
        /** @var Commandes $c */
        $c = $carte['commande'];
        $montants = $compte->montantsVisibles();
        $payeur = $compte->peut(CompteClient::DROIT_PAYEUR) && $montants;
        $etat = array_column($carte['etapes'], 'etat', 'cle');
        $confirmee = !$carte['aConfirmer'] && !$carte['aValiderEntreprise'] && !$carte['refuseeEntreprise'];
        $acompteDu = null !== $element && ElementAPayer::ACOMPTE === $element->nature && !$element->productionLancee;
        $production = match (true) {
            'faite' === ($etat['finition'] ?? null) => 'faite',
            'en_cours' === ($etat['impression'] ?? null) || 'en_cours' === ($etat['finition'] ?? null) => 'en_cours',
            default => 'a_venir',
        };
        $six = [
            ['libelle' => 'Commande', 'etat' => $confirmee ? 'faite' : 'en_cours'],
            ['libelle' => 'Acompte', 'etat' => !$confirmee ? 'a_venir' : ($acompteDu ? 'en_cours' : 'faite')],
            ['libelle' => 'BAT', 'etat' => $etat['bat'] ?? 'a_venir'],
            ['libelle' => 'Production', 'etat' => $production],
            ['libelle' => 'Prête', 'etat' => $etat['prete'] ?? 'a_venir'],
            ['libelle' => $c->estALivrer() ? 'Livrée' : 'Retirée', 'etat' => $etat['livree'] ?? 'a_venir'],
        ];
        // Une seule étape en cours : la première qui n'est pas faite ; rien de fait après elle.
        $courante = null;
        foreach ($six as $i => $e) {
            if (null !== $courante) {
                $six[$i]['etat'] = 'a_venir';
            } elseif ('faite' !== $e['etat']) {
                $courante = $i;
                $six[$i]['etat'] = 'en_cours';
            }
        }
        if (!$confirmee) {
            $courante = 0;
        }
        if ($carte['annulee'] || $carte['refuseeEntreprise']) {
            $six = array_map(static fn (array $e): array => ['etat' => 'a_venir'] + $e, $six);
        }

        $total = $c->getTotalTtc();
        $reste = OrangeMoney::aPayer($c);
        $enValidation = FacturesClient::enValidation($c);
        $livree = 'faite' === ($etat['livree'] ?? null) || null === $carte['courante'];
        $echue = null !== $element && ElementAPayer::ECHUE === $element->etat;
        $bat = $carte['batEnAttente'] ? self::controleEnAttente($c) : null;

        // Où en est-elle, en clair, et ce qu'on peut faire.
        [$titre, $detail, $ton] = match (true) {
            $carte['refuseeEntreprise'] => ['Refusée par l\'entreprise', null, 'gris'],
            $carte['aValiderEntreprise'] => ['À valider', 'par une personne qui valide pour votre entreprise', 'ambre'],
            $carte['annulee'] => ['Annulée', null, 'gris'],
            $carte['aConfirmer'] => ['Reçue · à confirmer', 'l\'atelier vérifie le prix et le fichier', 'ambre'],
            null !== $bat => ['BAT à valider', 'votre accord avant impression', 'ambre'],
            $acompteDu => ['Acompte à payer', $payeur ? self::f($element->montant).' F pour lancer la production' : 'pour lancer la production', 'orange'],
            $livree && $reste > 0 && $echue => [($c->estALivrer() ? 'Livrée' : 'Retirée').' · reste échu', 'échu depuis le '.$element->echeance->format('d/m'), 'rouge'],
            $livree && $reste > 0 => [($c->estALivrer() ? 'Livrée' : 'Retirée').self::le($c), null !== $element && ElementAPayer::A_VENIR === $element->etat ? 'reste à payer avant le '.$element->echeance->format('d/m') : 'reste à payer', 'orange'],
            $livree => [($c->estALivrer() ? 'Livrée' : 'Retirée').self::le($c), null, 'vert'],
            'faite' === ($etat['prete'] ?? null) || 'en_cours' === ($etat['livree'] ?? null) => ['Prête', $c->estALivrer() ? 'livraison '.(null !== $c->getDateLivraison() ? 'le '.$c->getDateLivraison()->format('d/m') : 'à venir') : 'à retirer à l\'imprimerie', 'vert'],
            'en_cours' === $production => ['En impression', null !== $c->getDateLivraison() ? 'prête le '.self::jour($c->getDateLivraison()) : null, 'bleu'],
            default => ['En préparation', 'l\'atelier prépare votre fichier', 'bleu'],
        };
        $action = match (true) {
            null !== $bat && $compte->peutEcrire() => ['libelle' => 'Valider le BAT', 'route' => 'app_espace_bat', 'params' => ['id' => $bat->getId()], 'ton' => 'vert'],
            $payeur && null !== $element && $element->montant > 0 && ($acompteDu || $livree) => ['libelle' => 'Payer '.self::f($element->montant).' F', 'route' => 'app_espace_payer_selection', 'params' => ['e' => [$element->cle()]], 'ton' => $echue ? 'rouge' : 'orange'],
            default => ['libelle' => 'Suivre', 'route' => 'app_espace_commande', 'params' => ['id' => (string) $c->getPublicId()], 'ton' => 'blanc'],
        };

        // « Flyers A5 × 1 000 », « Bâche × 2 + 1 autre » : le produit, sans la matière ni la taille.
        $produits = array_values(array_filter($c->getCommandesDetails()->toArray(), static fn ($l): bool => null !== $l->getProduitConfiguration()));
        $premiere = $produits[0] ?? null;
        $nom = (string) ($premiere?->getProduitConfiguration()?->getProduit()?->getNom() ?? '');
        $court = '' === $nom ? $carte['titre'] : CatalogueSite::nomAffiche($nom).' × '.self::f((int) $premiere->getQuantite()).(\count($produits) > 1 ? \sprintf(' + %d autre%s', \count($produits) - 1, \count($produits) > 2 ? 's' : '') : '');

        $onglets = ['toutes'];
        $monAction = null !== $bat || $acompteDu || $carte['aValiderEntreprise'] && $compte->peut(CompteClient::DROIT_VALIDEUR) || ($payeur && $livree && $reste > 0);
        if ($monAction) {
            $onglets[] = 'action';
        }
        if (!$livree && !$carte['annulee'] && !$carte['refuseeEntreprise']) {
            $onglets[] = 'en_cours';
        }
        if ($payeur && $reste > 0 && $confirmee && !$carte['annulee']) {
            $onglets[] = 'a_payer';
        }
        if ($livree && !$carte['annulee']) {
            $onglets[] = 'livrees';
        }

        return [
            'commande' => $c,
            'titre' => $court,
            'titreComplet' => $carte['titre'],
            'illustration' => CatalogueSite::illustration($nom, ''),
            'annee' => (int) $c->getDateCommande()?->format('Y'),
            'etapes' => $six,
            'etat' => ['titre' => $titre, 'detail' => $detail, 'ton' => $ton],
            'action' => $action,
            // Le total pour qui voit les montants ; payé, reste et paiements en validation pour le payeur seul.
            'montants' => $montants ? ['total' => $total, 'aConfirmer' => !$confirmee] + ($payeur
                ? ['paye' => max(0, $total - $reste - $enValidation), 'reste' => $reste, 'enValidation' => $enValidation]
                : ['paye' => null, 'reste' => null, 'enValidation' => null]) : null,
            'aConfirmer' => $carte['aConfirmer'],
            'onglets' => $onglets,
            'bat' => null !== $bat,
            'acompte' => $acompteDu,
            'enProduction' => 'en_cours' === $production,
            'echu' => $echue ? $element->montant : 0,
        ];
    }

    /**
     * Les quatre chiffres du haut et le nombre par onglet, pour une année
     * (ou toutes).
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, mixed>
     */
    public static function resume(array $lignes, ?int $annee, bool $montants): array
    {
        $compte = array_fill_keys(array_keys(self::ONGLETS), 0);
        foreach ($lignes as $l) {
            foreach ($l['onglets'] as $o) {
                ++$compte[$o];
            }
        }
        $enCours = array_filter($lignes, static fn (array $l): bool => \in_array('en_cours', $l['onglets'], true));
        $action = array_filter($lignes, static fn (array $l): bool => \in_array('action', $l['onglets'], true));
        $actives = array_filter($lignes, static fn (array $l): bool => !\in_array($l['etat']['titre'], ['Annulée', 'Refusée par l\'entreprise', 'Reçue · à confirmer', 'À valider'], true));

        return [
            'onglets' => $compte,
            'enCours' => \count($enCours),
            'enImpression' => \count(array_filter($enCours, static fn (array $l): bool => $l['enProduction'])),
            'aConfirmer' => \count(array_filter($enCours, static fn (array $l): bool => $l['aConfirmer'])),
            'batAValider' => \count(array_filter($lignes, static fn (array $l): bool => $l['bat'])),
            'action' => \count($action),
            'acomptes' => \count(array_filter($action, static fn (array $l): bool => $l['acompte'])),
            'reste' => $montants ? array_sum(array_map(static fn (array $l): int => \in_array('a_payer', $l['onglets'], true) ? $l['montants']['reste'] : 0, $lignes)) : null,
            'echu' => $montants ? array_sum(array_column($lignes, 'echu')) : null,
            'annee' => $annee,
            // Le nombre de commandes seulement : jamais le total dépensé à l'imprimerie.
            'commandees' => \count($actives),
        ];
    }

    private static function controleEnAttente(Commandes $c): ?ControlePrePresse
    {
        foreach ($c->getCommandesDetails() as $l) {
            $dernier = null;
            foreach ($l->getControlesPrePresse() as $ctl) {
                $dernier = null === $dernier || $ctl->getId() > $dernier->getId() ? $ctl : $dernier;
            }
            if (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $dernier?->getStatut()) {
                return $dernier;
            }
        }

        return null;
    }

    private static function le(Commandes $c): string
    {
        return null !== $c->getDateLivraison() ? ' le '.$c->getDateLivraison()->format('d/m') : '';
    }

    private static function jour(\DateTimeInterface $d): string
    {
        return ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'][(int) $d->format('w')].' '.$d->format('d/m');
    }

    private static function f(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    /** Plafond de crédit à montrer : client à terme (pas un particulier) avec un plafond. */
    public static function plafond(Clients $client): ?int
    {
        $pro = Clients::TYPE_COMPTE_PARTICULIER !== $client->getTypeCompte() || CompteClient::clientAPlusieursComptes($client);

        return $pro && (int) $client->getPlafondCredit() > 0 ? (int) $client->getPlafondCredit() : null;
    }

    /**
     * Plafond dépassé (maquette CD04) : le solde du compte plus ce que la
     * nouvelle commande laisse à crédit passe au-dessus du plafond. Null
     * sans plafond, sous le plafond, ou quand la commande est payée en entier.
     *
     * @return array{plafond: int, solde: int, apres: int, difference: int}|null
     */
    public static function depassement(?int $plafond, int $solde, int $aCredit): ?array
    {
        $apres = $solde + max(0, $aCredit);
        if (null === $plafond || $aCredit <= 0 || $apres <= $plafond) {
            return null;
        }

        return ['plafond' => $plafond, 'solde' => $solde, 'apres' => $apres, 'difference' => $apres - $plafond];
    }
}
