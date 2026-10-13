<?php

namespace App\Service\Finances;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Service\MouvementTresorerieService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Cycle d'une dépense (maquette F03) :
 * - saisie avec la photo du reçu, obligatoire ;
 * - au-delà du seuil d'approbation (Réglages › Finances), la dépense
 *   attend l'accord de la direction ; saisie par la direction, elle est
 *   approuvée d'office ;
 * - payée sur place (« Payé avec » rempli) : « Validée » ; sinon « À
 *   payer », puis « Payée » quand l'argent sort ;
 * - chaque paiement prépare un décaissement EN ATTENTE : il n'entre dans
 *   les soldes qu'après validation dans « À valider », jamais d'office.
 */
final class Depenses
{
    /** Types de reçus acceptés => extension enregistrée. */
    public const TYPES_JUSTIFICATIF = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    public const TAILLE_MAX = 8 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementTresorerieService $mouvements,
        private readonly ReglagesFinances $reglages,
        private readonly NotificationService $notifications,
        #[Autowire('%kernel.project_dir%/var/uploads/depenses')]
        private readonly string $dossier,
    ) {
    }

    /**
     * Statut d'une dépense à sa saisie : à approuver au-delà du seuil
     * (sauf saisie par la direction), sinon validée si elle est déjà
     * payée, à payer sinon.
     */
    public static function statutInitial(int $montant, int $seuil, bool $direction, bool $payeeSurPlace): string
    {
        if ($montant > $seuil && !$direction) {
            return Depense::A_APPROUVER;
        }

        return $payeeSurPlace ? Depense::VALIDEE : Depense::A_PAYER;
    }

    /**
     * Pastille affichée : le statut de la dépense, corrigé par l'état du
     * décaissement (en attente de validation, ou rejeté en trésorerie).
     *
     * @return array{libelle: string, couleur: string, note: ?string, a_repayer: bool}
     */
    public static function etat(Depense $d): array
    {
        $etat = ['libelle' => $d->libelleStatut(), 'couleur' => $d->couleurStatut(), 'note' => null, 'a_repayer' => false];
        $mouvement = $d->getMouvement();
        if ($d->estPayee() && null !== $mouvement) {
            if ($mouvement->isAnnule()) {
                return ['libelle' => 'Paiement rejeté', 'couleur' => 'rouge', 'note' => $mouvement->getMotifAnnulation(), 'a_repayer' => true];
            }
            if ($mouvement->isEnAttente()) {
                $etat['note'] = 'décaissement à valider';
            }
        }
        if (Depense::REFUSEE === $d->getStatut()) {
            $etat['note'] = $d->getMotifRefus();
        }

        return $etat;
    }

    /** Peut-on enregistrer le paiement de cette dépense ? */
    public static function payable(Depense $d): bool
    {
        return Depense::A_PAYER === $d->getStatut() || self::etat($d)['a_repayer'];
    }

    /**
     * Enregistre une dépense saisie : reçu rangé hors du dossier public,
     * statut selon le seuil, décaissement en attente si elle est payée.
     */
    public function enregistrer(Depense $d, UploadedFile $recu, User $par, bool $direction, \DateTimeImmutable $maintenant): void
    {
        $seuil = $this->reglages->seuilApprobation();
        $d->setJustificatif($this->stockerJustificatif($recu, $maintenant));
        $statut = self::statutInitial($d->getMontant(), $seuil, $direction, null !== $d->getCompte());
        $d->setStatut(Depense::VALIDEE === $statut ? Depense::A_PAYER : $statut);
        if ($direction && $d->getMontant() > $seuil) {
            $d->approuver($par, $maintenant);
        }
        $this->em->persist($d);
        if (Depense::VALIDEE === $statut && null !== $d->getCompte()) {
            $this->preparerPaiement($d, $d->getCompte(), $par, $maintenant, Depense::VALIDEE);
        }
        $this->em->flush();

        if (Depense::A_APPROUVER === $statut) {
            $this->notifications->notifierRoles(
                ['ROLE_ADMIN'],
                \sprintf('Dépense à approuver : %s · %s F', $d->getObjet(), number_format($d->getMontant(), 0, ',', ' ')),
                'app_finances_depenses_voir',
                ['id' => (int) $d->getId()],
                $par,
            );
            $this->em->flush();
        }
    }

    /** Accord de la direction : la dépense passe « À payer ». */
    public function approuver(Depense $d, User $direction, \DateTimeImmutable $maintenant): void
    {
        if (Depense::A_APPROUVER !== $d->getStatut()) {
            throw new \LogicException('Cette dépense n\'attend plus d\'approbation.');
        }
        $d->approuver($direction, $maintenant)->setStatut(Depense::A_PAYER);
        $this->prevenir($d, $direction, \sprintf('Dépense approuvée : %s · %s F, à payer.', $d->getObjet(), number_format($d->getMontant(), 0, ',', ' ')));
        $this->em->flush();
    }

    /** Refus motivé de la direction (avant tout paiement). */
    public function refuser(Depense $d, User $direction, string $motif, \DateTimeImmutable $maintenant): void
    {
        if (!\in_array($d->getStatut(), [Depense::A_APPROUVER, Depense::A_PAYER], true)) {
            throw new \LogicException('Une dépense déjà payée ne se refuse pas : annulez son décaissement en trésorerie.');
        }
        if ('' === trim($motif)) {
            throw new \InvalidArgumentException('Le motif du refus est obligatoire.');
        }
        $d->refuser($direction, $motif, $maintenant);
        $this->prevenir($d, $direction, \sprintf('Dépense refusée : %s · %s', $d->getObjet(), trim($motif)));
        $this->em->flush();
    }

    /** Paiement d'une dépense à payer : décaissement préparé, en attente. */
    public function payer(Depense $d, CompteTresorerie $compte, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!self::payable($d)) {
            throw new \LogicException('Cette dépense n\'est pas à payer.');
        }
        if (!$compte->isActif()) {
            throw new \InvalidArgumentException('Ce compte est désactivé.');
        }
        $this->preparerPaiement($d, $compte, $par, $maintenant, Depense::PAYEE);
        $this->em->flush();
    }

    /**
     * Dépenses du mois, les plus récentes d'abord ; seulement celles de la
     * personne quand elle ne voit pas la trésorerie.
     *
     * @return list<Depense>
     */
    public function duMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $seulement = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('d', 'f', 'c', 'm')
            ->from(Depense::class, 'd')
            ->leftJoin('d.fournisseur', 'f')
            ->leftJoin('d.compte', 'c')
            ->leftJoin('d.mouvement', 'm')
            ->andWhere('d.dateDepense >= :debut AND d.dateDepense < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('d.dateDepense', 'DESC')
            ->addOrderBy('d.id', 'DESC');
        if (null !== $seulement) {
            $qb->andWhere('d.creePar = :moi')->setParameter('moi', $seulement);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dépenses d'un statut, les plus anciennes d'abord (à approuver, à payer).
     *
     * @return list<Depense>
     */
    public function parStatut(string $statut): array
    {
        return $this->em->createQueryBuilder()
            ->select('d', 'f')
            ->from(Depense::class, 'd')
            ->leftJoin('d.fournisseur', 'f')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('d.creeLe', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pour décider d'un achat de matières : stock actuel de l'article,
     * prix unitaire de la dépense et dernier prix d'achat connu.
     *
     * @return array{article: string, stock: float, bas: bool, unite: string, prix: ?int, dernier: ?int, ecart: ?int}|null
     */
    public function infoAchat(Depense $d): ?array
    {
        $article = $d->getArticle();
        if (null === $article) {
            return null;
        }
        $dernier = $this->em->createQueryBuilder()
            ->select('ligne.prixUnitaire')
            ->from(AchatDetail::class, 'ligne')
            ->innerJoin('ligne.achat', 'achat')
            ->andWhere('ligne.article = :article')
            ->andWhere('achat.statut != :annule')
            ->andWhere('ligne.prixUnitaire > 0')
            ->setParameter('article', $article)
            ->setParameter('annule', Achats::STATUT_ANNULE)
            ->orderBy('achat.dateDemande', 'DESC')
            ->addOrderBy('ligne.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        $dernier = null !== $dernier ? (int) $dernier['prixUnitaire'] : $article->getPrixAchat();
        $prix = $d->getQuantite() > 0 ? (int) round($d->getMontant() / $d->getQuantite()) : null;

        return [
            'article' => (string) $article->getDesignation(),
            'stock' => $article->getStock(),
            'bas' => $article->getStockMin() > 0 && $article->getStock() <= $article->getStockMin(),
            'unite' => $article->getUniteCourte(),
            'prix' => $prix,
            'dernier' => $dernier,
            'ecart' => self::ecart($prix, $dernier),
        ];
    }

    /** Écart en % entre le prix payé et le dernier prix connu. */
    public static function ecart(?int $prix, ?int $dernier): ?int
    {
        return null === $prix || null === $dernier || $dernier <= 0 ? null : (int) round(($prix - $dernier) * 100 / $dernier);
    }

    /** Chemin du reçu sur le disque, après contrôle du nom enregistré. */
    public function cheminJustificatif(Depense $d): ?string
    {
        if (!preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp|pdf)$#', $d->getJustificatif())) {
            return null;
        }
        $chemin = $this->dossier.'/'.$d->getJustificatif();

        return is_file($chemin) ? $chemin : null;
    }

    public static function typeJustificatif(string $chemin): string
    {
        $type = array_search(strtolower(pathinfo($chemin, \PATHINFO_EXTENSION)), self::TYPES_JUSTIFICATIF, true);

        return false === $type ? 'application/octet-stream' : $type;
    }

    /**
     * Range le reçu dans var/uploads/depenses/AAAA/MM sous un nom
     * aléatoire, après contrôle du type réel et de la taille.
     */
    private function stockerJustificatif(UploadedFile $recu, \DateTimeImmutable $maintenant): string
    {
        $type = (string) $recu->getMimeType();
        if (!isset(self::TYPES_JUSTIFICATIF[$type])) {
            throw new \InvalidArgumentException('Le reçu doit être une photo (JPEG, PNG, WebP) ou un PDF.');
        }
        if ($recu->getSize() > self::TAILLE_MAX) {
            throw new \InvalidArgumentException('Le reçu dépasse 8 Mo.');
        }
        $relatif = $maintenant->format('Y/m').'/'.bin2hex(random_bytes(16)).'.'.self::TYPES_JUSTIFICATIF[$type];
        $chemin = $this->dossier.'/'.$relatif;
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0770, true);
        }
        $recu->move(\dirname($chemin), basename($chemin));

        return $relatif;
    }

    private function preparerPaiement(Depense $d, CompteTresorerie $compte, User $par, \DateTimeImmutable $maintenant, string $statut): void
    {
        $date = Depense::VALIDEE === $statut
            ? $d->getDateDepense()->setTime((int) $maintenant->format('H'), (int) $maintenant->format('i'))
            : $maintenant;
        $payeA = $d->getPayeA();
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie(Budgets::CATEGORIES[$d->getCategorie()]['mouvement'] ?? MouvementTresorerie::CATEGORIE_AUTRE_CHARGE)
            ->setCompteSource($compte)
            ->setMontant($d->getMontant())
            ->setLibelle(mb_substr('Dépense · '.$d->getObjet().('' !== $payeA ? ' · '.$payeA : ''), 0, 255))
            ->setDescription('Saisie dans Finances › Dépenses, reçu joint. À vérifier avant validation : montant et reçu.')
            ->setDateOperation($date)
            ->setAgent($par);
        $this->mouvements->preparerEnAttente($mouvement);
        $d->payer($statut, $par, $maintenant, $mouvement);
    }

    private function prevenir(Depense $d, User $auteur, string $message): void
    {
        $createur = $d->getCreePar();
        if (null !== $createur && $createur !== $auteur) {
            $this->notifications->notifierUtilisateur($createur, $message, 'app_finances_depenses_voir', ['id' => (int) $d->getId()]);
        }
    }
}
