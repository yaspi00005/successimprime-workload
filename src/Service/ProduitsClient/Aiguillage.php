<?php

namespace App\Service\ProduitsClient;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\OrdreProduction;
use App\Entity\ProduitClient;
use App\Entity\User;
use App\Repository\OrdreProductionRepository;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\NotificationService;
use App\Service\Paiement\OrangeMoney;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Aiguillage automatique des lignes d'une commande (maquette MP06) :
 *  - fichier lié + taille validée : impression directe, la ligne part dans
 *    la file d'impression de sa machine, sans prépresse ni BAT ;
 *  - nouvelle taille, lien suspendu (nouvelle image) ou fichier modifié ou
 *    déplacé dans le dossier : prépresse (adapter le fichier, BAT au client) ;
 *  - ligne qui ne vient pas de « Mes produits » : le circuit habituel.
 *
 * pourLigne() et pourCommande() décrivent l'aiguillage sans rien changer :
 * ce sont les données que montrent le détail de la commande (admin) et le
 * suivi ; lancer() l'applique (ordres de production des lignes directes),
 * une seule fois par ligne. Au local seulement, le fichier du dossier est
 * revérifié (empreinte SHA-256) à chaque appel.
 */
final class Aiguillage
{
    public const DIRECT = 'direct';
    public const PREPRESSE = 'prepresse';
    public const STANDARD = 'standard';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiensFichiers $liens,
        private readonly OrdreProductionRepository $ordres,
        private readonly DelaisCommande $delais,
        private readonly NotificationService $notifications,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /**
     * L'aiguillage d'une ligne, réutilisable tel quel par les écrans.
     *
     * @return array{
     *     mode: string,
     *     alerte: bool,
     *     motif: string,
     *     taille: ?string,
     *     fichier: ?array{nom: string, empreinte: string, le: ?string, etat: string, chemin: ?string},
     *     destination: string,
     *     machine: ?string,
     *     operateur: ?string,
     *     ordre: ?array{id: int, numero: string, statut: string},
     *     produit: ?array{reference: ?string, nom: ?string}
     * }
     */
    public function pourLigne(CommandesDetails $ligne): array
    {
        $produit = $ligne->getProduitClient();
        $ordre = null !== $ligne->getId() ? $this->ordres->findOneBy(['commandeDetail' => $ligne]) : null;
        [$l, $h] = self::tailleMm($ligne);
        $base = [
            'alerte' => false,
            'taille' => null !== $l && null !== $h ? ProduitClient::libelleTaille($l, $h, (bool) $produit?->isRond()) : null,
            'fichier' => null,
            'machine' => ($ordre?->getMachine() ?? $ligne->getMachine() ?? $produit?->getMachine())?->getNom(),
            'operateur' => null !== $ordre?->getOperateur() ? Pointages::nom($ordre->getOperateur()) : null,
            'ordre' => null !== $ordre ? ['id' => (int) $ordre->getId(), 'numero' => $ordre->getNumero(), 'statut' => $ordre->getStatut()] : null,
            'produit' => null !== $produit ? ['reference' => $produit->getReference(), 'nom' => $produit->getNom()] : null,
        ];
        $machine = null !== $base['machine'] ? 'File d\'impression · '.$base['machine'] : 'File d\'impression';

        if (null === $produit || ($ligne->getCommande()?->getClients()?->getId() !== $produit->getClient()?->getId())) {
            return [
                'mode' => self::STANDARD,
                'motif' => $ligne->isPrePresseNecessaire() ? 'circuit habituel : prépresse puis BAT' : 'sans prépresse',
                'destination' => $ligne->isPrePresseNecessaire() ? 'Prépresse' : $machine,
            ] + $base;
        }

        $lien = null !== $l && null !== $h ? $produit->fichierPourTaille($l, $h) : null;
        if (null !== $lien) {
            $verif = $this->liens->verifier($produit, $lien, $this->enLigne);
            $fichier = ['nom' => (string) $lien['nom'], 'empreinte' => (string) $lien['empreinte'], 'le' => $lien['le'] ?? null, 'etat' => $verif['etat'], 'chemin' => $verif['chemin']];
            $date = self::date($lien['le'] ?? null);
            $retenu = $ligne->getFichierLie();
            $nouvelleVersion = null !== $retenu && ($retenu['empreinte'] ?? null) !== $lien['empreinte'];

            return match ($verif['etat']) {
                LiensFichiers::IDENTIQUE, LiensFichiers::HORS_ATELIER => [
                    'mode' => self::DIRECT,
                    'fichier' => $fichier,
                    'motif' => LiensFichiers::IDENTIQUE === $verif['etat']
                        ? ($nouvelleVersion ? 'nouvelle version liée le '.$date.' par le prépresse, empreinte identique' : 'empreinte identique au lien du '.$date)
                        : 'fichier lié le '.$date.' (revérifié par l\'ERP de l\'atelier)',
                    'destination' => $machine,
                ] + $base,
                LiensFichiers::MODIFIE => [
                    'mode' => self::PREPRESSE, 'alerte' => true, 'fichier' => $fichier,
                    'motif' => 'le fichier du dossier a été modifié depuis le lien du '.$date.' : l\'empreinte ne correspond plus',
                    'destination' => 'Prépresse · revérifier le fichier',
                ] + $base,
                default => [
                    'mode' => self::PREPRESSE, 'alerte' => true, 'fichier' => $fichier,
                    'motif' => 'fichier lié introuvable dans le dossier du client (déplacé ou renommé)',
                    'destination' => 'Prépresse · retrouver le fichier',
                ] + $base,
            };
        }

        // Produit enregistré par l'atelier avec un BAT validé (avant les fichiers liés) : son format, sans PAO.
        if (null !== $produit->getBatValide() && ProduitClient::LIEN_SUSPENDU !== $produit->getEtatLien() && null !== $l && null !== $h && $produit->estTailleValidee($l, $h)) {
            return [
                'mode' => self::DIRECT,
                'motif' => 'BAT validé'.(null !== $produit->getBatValide()->getValideLe() ? ' le '.$produit->getBatValide()->getValideLe()->format('d/m') : ''),
                'destination' => $machine,
            ] + $base;
        }

        return [
            'mode' => self::PREPRESSE,
            'motif' => match (true) {
                ProduitClient::LIEN_SUSPENDU === $produit->getEtatLien() => 'nouvelle image du client : lien suspendu',
                !$produit->estPret() => 'fichier du produit encore en préparation',
                default => 'taille non validée au moment de la commande',
            },
            'destination' => 'Prépresse · adapter puis BAT',
        ] + $base;
    }

    /**
     * @return array{lignes: list<array{ligne: CommandesDetails, aiguillage: array<string, mixed>}>, directes: int, prepresse: int, alertes: int, depuisMesProduits: bool, acompteRegle: bool, lancee: bool}
     */
    public function pourCommande(Commandes $commande): array
    {
        $lignes = [];
        $compte = [self::DIRECT => 0, self::PREPRESSE => 0, self::STANDARD => 0];
        $alertes = 0;
        $lancee = true;
        foreach ($commande->getCommandesDetails() as $ligne) {
            $a = $this->pourLigne($ligne);
            ++$compte[$a['mode']];
            $alertes += $a['alerte'] ? 1 : 0;
            $lancee = $lancee && (self::DIRECT !== $a['mode'] || null !== $a['ordre']);
            $lignes[] = ['ligne' => $ligne, 'aiguillage' => $a];
        }

        return [
            'lignes' => $lignes,
            'directes' => $compte[self::DIRECT],
            'prepresse' => $compte[self::PREPRESSE],
            'alertes' => $alertes,
            'depuisMesProduits' => self::depuisMesProduits($commande),
            'acompteRegle' => $this->acompteRegle($commande),
            'lancee' => $lancee,
        ];
    }

    /** Toutes les lignes viennent de « Mes produits » (le panier). */
    public static function depuisMesProduits(Commandes $commande): bool
    {
        $lignes = $commande->getCommandesDetails();
        if (0 === $lignes->count()) {
            return false;
        }
        foreach ($lignes as $l) {
            if (null === $l->getProduitClient()) {
                return false;
            }
        }

        return true;
    }

    /**
     * L'acompte est-il réglé ? Client dispensé d'acompte, acompte à 0 %, ou
     * paiements validés ou reçus d'Orange Money (en validation à la caisse)
     * qui couvrent l'acompte.
     */
    public function acompteRegle(Commandes $commande): bool
    {
        $client = $commande->getClients();
        if (null === $client) {
            return false;
        }
        $pourcentage = $this->delais->pourcentageAcompte($client);
        if ($pourcentage <= 0) {
            return true;
        }
        $total = (int) $commande->getTotalTtc();
        $couvert = $total - OrangeMoney::aPayer($commande);

        return $couvert >= DelaisCommande::acompte($total, $pourcentage);
    }

    /**
     * Applique l'aiguillage au local : ordre de production (transmis) pour
     * chaque ligne directe qui n'en a pas, retour au prépresse pour les
     * autres lignes de « Mes produits ». Ne flushe pas. Rend le nombre
     * d'ordres créés.
     */
    public function lancer(Commandes $commande, ?User $par): int
    {
        if ($this->enLigne || !$commande->isStatut() || $commande->isDeleted()) {
            return 0;
        }
        $crees = 0;
        $prepresse = 0;
        foreach ($commande->getCommandesDetails() as $ligne) {
            if (\in_array($ligne->getStatutProduction(), [CommandesDetails::PRODUCTION_ANNULEE, CommandesDetails::PRODUCTION_NON_REQUISE, CommandesDetails::PRODUCTION_LIVREE], true)) {
                continue;
            }
            $a = $this->pourLigne($ligne);
            if (self::PREPRESSE === $a['mode']) {
                if (!$ligne->isPrePresseNecessaire()) {
                    ++$prepresse;
                }
                $ligne->setPrePresseNecessaire(true)->setBatValide(false);
                continue;
            }
            if (self::DIRECT !== $a['mode'] || null !== $a['ordre']) {
                continue;
            }
            // L'ordre s'appuie toujours sur un contrôle prépresse : ici celui fait au moment du lien
            // (ou le BAT validé du produit), enregistré comme « envoyé en production » sans PAO.
            $controle = self::controleDirect($ligne, $a);
            $this->em->persist($controle);
            $ordre = (new OrdreProduction())
                ->setCommandeDetail($ligne)
                ->setControlePrePresse($controle)
                ->setCreePar($par)
                ->setPriorite($ligne->getPriorite())
                ->setQuantite($ligne->getQuantite())
                ->setMachine($ligne->getMachine() ?? $ligne->getProduitClient()?->getMachine())
                ->setInstructions(self::instructions($a));
            if (null !== $commande->getDateLivraison()) {
                $ordre->setDateLimite(\DateTimeImmutable::createFromInterface($commande->getDateLivraison()));
            }
            $ordre->transmettre();
            $ligne->setPrePresseNecessaire(false)->setBatValide(true)->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
            if (null !== $a['fichier']) {
                $ligne->setFichierLie(array_intersect_key($a['fichier'], array_flip(['nom', 'empreinte', 'le'])) + ['l' => self::tailleMm($ligne)[0], 'h' => self::tailleMm($ligne)[1]]);
            }
            $this->em->persist($ordre);
            ++$crees;
        }
        if ($crees > 0) {
            $this->notifications->notifierRoles(['ROLE_PRODUCTION_VOIR'], \sprintf('Commande %s : %d ligne%s en impression directe (fichier lié), dans la file d\'impression.', $commande->getNumero(), $crees, $crees > 1 ? 's' : ''), 'app_production_index', []);
        }
        if ($prepresse > 0) {
            $this->notifications->notifierRoles(['ROLE_PREPRESSE'], \sprintf('Commande %s : %d ligne%s de « Mes produits » à adapter au prépresse (nouvelle taille ou fichier à revérifier), puis BAT au client.', $commande->getNumero(), $prepresse, $prepresse > 1 ? 's' : ''), 'app_controle_pre_presse_index', []);
        }

        return $crees;
    }

    /**
     * Commande passée depuis le panier, arrivée au local : confirmée et
     * aiguillée dès que l'acompte est réglé (ou que le client en est
     * dispensé). Rien pour une demande d'entreprise pas encore validée.
     * Ne flushe pas ; rend vrai si elle a été lancée.
     */
    public function lancerSiPrete(Commandes $commande): bool
    {
        if ($this->enLigne || $commande->isDeleted() || Commandes::CANAL_EN_LIGNE !== $commande->getCanal()
            || $commande->attendValidationEntreprise() || $commande->estRefuseeParEntreprise()
            || !self::depuisMesProduits($commande) || !$this->acompteRegle($commande)) {
            return false;
        }
        $commande->setStatut(true);
        $this->lancer($commande, null);

        return true;
    }

    /** Largeur et hauteur de la ligne en mm (les lignes sont en mètres). */
    public static function tailleMm(CommandesDetails $ligne): array
    {
        $l = $ligne->getLargeur();
        $h = $ligne->getLongueur();

        return [null !== $l && (float) $l > 0 ? round((float) $l * 1000, 1) : null, null !== $h && (float) $h > 0 ? round((float) $h * 1000, 1) : null];
    }

    /** Contrôle prépresse d'une ligne directe : les points vérifiés au lien, sans BAT. */
    private static function controleDirect(CommandesDetails $ligne, array $a): ControlePrePresse
    {
        $controles = [];
        $produit = $ligne->getProduitClient();
        if (null !== $a['fichier'] && null !== $produit) {
            [$l, $h] = self::tailleMm($ligne);
            $lien = null !== $l && null !== $h ? $produit->fichierPourTaille($l, $h) : null;
            $controles = \is_array($lien['controles'] ?? null) ? $lien['controles'] : [];
        }
        $etat = static function (string $debut) use ($controles): bool {
            foreach ($controles as $c) {
                if (str_starts_with((string) ($c['libelle'] ?? ''), $debut)) {
                    return ControleFichierLie::OK === ($c['etat'] ?? null);
                }
            }

            return false;
        };

        return (new ControlePrePresse())
            ->setCommandeDetail($ligne)
            ->setPrePresseNecessaire(false)
            ->setFichierDejaTraite(true)
            ->setBatNecessaire(false)
            ->setDimensionsConformes($etat('Dimensions'))
            ->setFondsPerdusConformes($etat('Dimensions'))
            ->setProfilCouleursConforme($etat('Couleurs'))
            ->setQuantiteConforme(true)
            ->setObservation(self::instructions($a))
            ->setStatut(ControlePrePresse::STATUT_ENVOYE_PRODUCTION);
    }

    private static function instructions(array $a): string
    {
        if (null === $a['fichier']) {
            return 'Impression directe : '.$a['motif'].'.';
        }

        return \sprintf("Impression directe, fichier lié : %s\nEmpreinte SHA-256 : %s (%s).\nImprimer depuis un poste qui a accès au dossier des clients.", $a['fichier']['chemin'] ?? $a['fichier']['nom'], substr($a['fichier']['empreinte'], 0, 16).'…', $a['motif']);
    }

    private static function date(?string $le): string
    {
        $d = null !== $le ? \DateTimeImmutable::createFromFormat('Y-m-d H:i', $le) : false;

        return false !== $d ? $d->format('d/m') : '?';
    }
}
