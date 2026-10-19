<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Service\Facturation\DocumentsFacturation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Documents de facturation (maquettes FD06 et FD01) : la liste de tous
 * les documents du mois avec les quatre chiffres, l'export pour la
 * comptabilité et la fenêtre « Nouveau document ».
 */
#[Route('/factures')]
final class DocumentsFacturationController extends AbstractController
{
    public function __construct(
        private readonly DocumentsFacturation $documents,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/', name: 'app_factures_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $mois = $this->mois($request);
        $permis = $this->typesPermis();
        $type = $request->query->getString('type');
        $types = isset($permis[$type]) ? [$type] : array_keys($permis);
        $q = trim($request->query->getString('q'));

        return $this->render('facturation/index.html.twig', [
            'documents' => $this->documents->documents($mois, $types, $q),
            'chiffres' => $this->documents->chiffres($mois, $this->isGranted('ROLE_PAIEMENT_VOIR')),
            'onglets' => $permis,
            'type' => isset($permis[$type]) ? $type : '',
            'q' => $q,
            'mois' => $mois,
            'moisChoix' => $this->moisChoix(),
            'voirEncaisse' => $this->isGranted('ROLE_PAIEMENT_VOIR'),
            'nouveau' => $this->choixNouveau(),
        ]);
    }

    /** Export pour la comptabilité : les documents du mois, en CSV (Excel). */
    #[Route('/export', name: 'app_factures_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $mois = $this->mois($request);
        $permis = $this->typesPermis();
        $type = $request->query->getString('type');
        $lignes = $this->documents->documents($mois, isset($permis[$type]) ? [$type] : array_keys($permis));
        $reponse = new StreamedResponse(static function () use ($lignes): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Type', 'Numéro', 'Date', 'Client', 'Montant (F CFA)', 'Statut', 'Commande'], ';', '"', '');
            foreach ($lignes as $l) {
                fputcsv($out, [$l['libelle'], $l['numero'], $l['date']->format('d/m/Y'), $l['client'], $l['montant'] ?? $l['montantTexte'], $l['statut'], $l['commande']], ';', '"', '');
            }
            fclose($out);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="documents-facturation-%s.csv"', $mois->format('Y-m')));

        return $reponse;
    }

    /**
     * La fenêtre « Nouveau document » envoie ici le type et le point de
     * départ ; chaque document s'ouvre dans son écran, avec ses droits.
     */
    #[Route('/nouveau-document', name: 'app_factures_nouveau_document', methods: ['GET'])]
    public function nouveauDocument(Request $request): Response
    {
        $q = $request->query;
        $commande = $q->getInt('commande') > 0 ? $this->em->getRepository(Commandes::class)->find($q->getInt('commande')) : null;
        $type = $q->getString('type');
        $depart = $q->getString('depart', 'commande');

        if ('devis' === $type) {
            return $this->redirectToRoute('app_devis_new');
        }
        if ('avoir' === $type) {
            $facture = $this->em->getRepository(Factures::class)->find($q->getInt('facture'));
            if (null === $facture) {
                return $this->retour('Choisissez la facture validée à corriger.');
            }

            return $this->redirectToRoute('app_factures_avoir', ['id' => $facture->getId()]);
        }
        if (\in_array($type, ['facture', 'proforma'], true) && 'vide' === $depart) {
            $this->addFlash('info', 'Saisissez la vente au comptoir comme une commande ; sa facture se fait ensuite depuis la commande.');

            return $this->redirectToRoute('app_commandes_new');
        }
        if ('facture' === $type && 'devis' === $depart) {
            $devis = $this->em->getRepository(Devis::class)->find($q->getInt('devis'));
            if (null === $devis) {
                return $this->retour('Choisissez le devis accepté.');
            }
            if (null === $devis->getCommande()) {
                $this->addFlash('info', 'Ce devis n\'a pas encore de commande : transformez-le en commande, puis faites la facture.');

                return $this->redirectToRoute('app_devis_show', ['id' => $devis->getId()]);
            }
            $commande = $devis->getCommande();
        }
        if (null === $commande) {
            return $this->retour('Choisissez la commande.');
        }

        return match ($type) {
            'facture' => $this->redirectToRoute('app_factures_nouveau', ['id' => $commande->getId()]),
            'proforma' => $this->redirectToRoute('app_factures_nouveau', ['id' => $commande->getId(), 'type' => Factures::TYPE_PROFORMA]),
            'recu' => $this->redirectToRoute('app_commandes_paiement', ['id' => $commande->getId()]),
            'bl' => $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId(), '_fragment' => 'livraison']),
            default => $this->retour('Type de document inconnu.'),
        };
    }

    /** @return array<string, string> type => onglet, selon les droits */
    private function typesPermis(): array
    {
        $permis = [];
        foreach (DocumentsFacturation::TYPES as $type => $libelle) {
            $ok = match ($type) {
                DocumentsFacturation::RECU => $this->isGranted('ROLE_PAIEMENT_VOIR'),
                DocumentsFacturation::DEVIS => $this->isGranted('ROLE_DEVIS'),
                DocumentsFacturation::BL => $this->isGranted('ROLE_LIVRAISON') || $this->isGranted('ROLE_COMMANDE'),
                default => true,
            };
            if ($ok) {
                $permis[$type] = $libelle;
            }
        }

        return $permis;
    }

    /**
     * Ce que la fenêtre « Nouveau document » propose : les commandes à
     * facturer (livrées ou retirées d'abord), les devis acceptés, les
     * factures validées pour un avoir.
     *
     * @return array{commandes: list<array{id: int, libelle: string, livree: bool}>, devis: list<array{id: int, libelle: string}>, factures: list<array{id: int, libelle: string}>, droits: array<string, bool>}
     */
    private function choixNouveau(): array
    {
        $commandes = $this->em->createQueryBuilder()->select('c', 'cl')->from(Commandes::class, 'c')->leftJoin('c.clients', 'cl')
            ->where('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('NOT EXISTS (SELECT f.id FROM '.Factures::class.' f WHERE f.commande = c AND f.typeDocument = :fac AND f.etat <> :annulee)')
            ->setParameter('fac', Factures::TYPE_FACTURE)->setParameter('annulee', Factures::ETAT_ANNULEE)
            ->orderBy('c.id', 'DESC')->setMaxResults(80)->getQuery()->getResult();
        $livrees = [];
        if ([] !== $commandes) {
            foreach ($this->em->createQueryBuilder()->select('IDENTITY(b.commande) AS c')->from(BonLivraison::class, 'b')
                ->where('b.commande IN (:c) AND b.statut = :livre')->setParameter('c', $commandes)->setParameter('livre', BonLivraison::STATUT_LIVRE)
                ->getQuery()->getScalarResult() as $r) {
                $livrees[(int) $r['c']] = true;
            }
        }
        $choixCommandes = [];
        foreach ($commandes as $c) {
            /** @var Commandes $c */
            $livree = isset($livrees[$c->getId()]) || $c->isRecupereParClient();
            $choixCommandes[] = [
                'id' => (int) $c->getId(), 'livree' => $livree,
                'libelle' => \sprintf('%s · %s · %s F%s', $c->getNumero(), $c->getClients()?->getNomComplet() ?? 'Comptoir', number_format($c->getTotalTtc(), 0, ',', ' '), $livree ? ' · livrée' : ''),
            ];
        }
        usort($choixCommandes, static fn (array $a, array $b): int => [$b['livree'], $b['id']] <=> [$a['livree'], $a['id']]);

        $devis = [];
        if ($this->isGranted('ROLE_DEVIS')) {
            foreach ($this->em->getRepository(Devis::class)->findBy(['statut' => [Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI], 'deleted' => false], ['id' => 'DESC'], 40) as $d) {
                $devis[] = ['id' => (int) $d->getId(), 'libelle' => \sprintf('%s · %s · %s F', $d->getNumero(), $d->getClients()?->getNomComplet() ?? '—', number_format($d->getTotalTtc(), 0, ',', ' '))];
            }
        }

        $factures = [];
        foreach ($this->em->getRepository(Factures::class)->findBy(['typeDocument' => Factures::TYPE_FACTURE, 'etat' => Factures::ETAT_EMISE], ['id' => 'DESC'], 60) as $f) {
            $factures[] = ['id' => (int) $f->getId(), 'libelle' => \sprintf('%s · %s · %s F', $f->getNumero(), $f->getCommande()?->getClients()?->getNomComplet() ?? '—', number_format($f->getTotalTtc(), 0, ',', ' '))];
        }

        return [
            'commandes' => $choixCommandes, 'devis' => $devis, 'factures' => $factures,
            'droits' => [
                'devis' => $this->isGranted('ROLE_DEVIS'),
                'recu' => $this->isGranted('ROLE_PAIEMENT_ENCAISSER'),
                'bl' => $this->isGranted('ROLE_LIVRAISON') || $this->isGranted('ROLE_COMMANDE'),
                'avoir' => $this->isGranted('ROLE_FACTURE'),
            ],
        ];
    }

    private function mois(Request $request): \DateTimeImmutable
    {
        $m = $request->query->getString('mois');
        $d = 1 === preg_match('/^\d{4}-\d{2}$/', $m) ? \DateTimeImmutable::createFromFormat('!Y-m', $m) : false;

        return false !== $d ? $d : new \DateTimeImmutable('first day of this month midnight');
    }

    /** @return array<string, string> 2026-10 => Octobre 2026, douze derniers mois */
    private function moisChoix(): array
    {
        $noms = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $choix = [];
        $m = new \DateTimeImmutable('first day of this month');
        for ($i = 0; $i < 12; ++$i) {
            $choix[$m->format('Y-m')] = $noms[(int) $m->format('n')].' '.$m->format('Y');
            $m = $m->modify('-1 month');
        }

        return $choix;
    }

    private function retour(string $message): Response
    {
        $this->addFlash('warning', $message);

        return $this->redirectToRoute('app_factures_index', ['nouveau' => 1]);
    }
}
