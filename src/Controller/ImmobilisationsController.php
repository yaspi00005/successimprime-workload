<?php

namespace App\Controller;

use App\Entity\Immobilisation;
use App\Entity\Machines;
use App\Entity\ReleveCompteur;
use App\Entity\User;
use App\Repository\ImmobilisationRepository;
use App\Repository\MachinesRepository;
use App\Repository\ReleveCompteurRepository;
use App\Service\DocumentsEntreprise;
use App\Service\Immobilisations\CalculAmortissement;
use App\Service\Immobilisations\Patrimoine;
use App\Service\Immobilisations\UsageMachines;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Immobilisations (maquettes M01 à M04,
 * docs/immobilisations-primes.md) : patrimoine, registre, machines
 * amorties à l'usage, fiche machine avec relevés de compteurs, export pour
 * le cabinet comptable.
 *
 * Valeurs d'achat, cumuls et valeurs nettes : admin et droit
 * « Comptabilité et patrimoine » seulement. Tout le personnel ouvre le
 * registre, mais n'y voit que les biens qui lui sont confiés, sans
 * montant. Le relevé de compteurs se saisit aussi avec le droit
 * « Statuts de production ». ERP local seulement
 * (PatrimoinePrimesHorsLigneSubscriber).
 */
#[Route('/finances/immobilisations', name: 'app_immobilisations_')]
#[IsGranted('ROLE_USER')]
final class ImmobilisationsController extends AbstractController
{
    private const PHOTO_MAX_OCTETS = 6_000_000;

    public function __construct(
        private readonly Patrimoine $patrimoine,
        private readonly ImmobilisationRepository $biens,
        private readonly MachinesRepository $machines,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/releves')] private readonly string $dossierPhotos,
    ) {
    }

    /** Patrimoine de l'entreprise (M03). */
    #[Route('', name: 'patrimoine', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function patrimoine(): Response
    {
        return $this->render('immobilisations/patrimoine.html.twig', [
            'synthese' => $this->patrimoine->synthese(new \DateTimeImmutable()),
        ]);
    }

    /**
     * Registre des immobilisations (M04). Sans le droit comptable : les
     * seuls biens confiés à la personne, sans aucun montant.
     */
    #[Route('/registre', name: 'registre', methods: ['GET'])]
    public function registre(Request $request): Response
    {
        $comptable = $this->isGranted('ROLE_COMPTABILITE');
        $categorie = (string) $request->query->get('categorie');
        $categorie = isset(Immobilisation::CATEGORIES[$categorie]) ? $categorie : null;
        $sortis = $comptable && $request->query->getBoolean('sortis');
        $lignes = $this->patrimoine->registre($categorie, $sortis, $comptable ? null : $this->moi(), new \DateTimeImmutable());

        return $this->render('immobilisations/registre.html.twig', [
            'lignes' => $comptable ? $lignes : self::sansMontants($lignes),
            'comptable' => $comptable,
            'categorie' => $categorie,
            'sortis' => $sortis,
        ]);
    }

    /** Registre imprimable (bouton « Registre PDF » de M03). */
    #[Route('/registre.pdf', name: 'registre_pdf', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function registrePdf(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $html = $this->renderView('immobilisations/registre_pdf.html.twig', [
            'lignes' => $this->patrimoine->registre(null, false, null, $maintenant),
            'synthese' => $this->patrimoine->synthese($maintenant),
            'edite' => $maintenant,
        ]);

        return $this->pdf($html, 'registre-immobilisations-'.$maintenant->format('Y-m-d').'.pdf', 'landscape');
    }

    /** Étiquettes QR à coller sur les biens : le QR ouvre la fiche du bien. */
    #[Route('/etiquettes', name: 'etiquettes', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function etiquettes(Request $request): Response
    {
        $categorie = (string) $request->query->get('categorie');
        $biens = $this->biens->registre(isset(Immobilisation::CATEGORIES[$categorie]) ? $categorie : null);
        $etiquettes = array_map(fn (Immobilisation $i): array => [
            'bien' => $i,
            'qr' => DocumentsEntreprise::qr($this->generateUrl('app_immobilisations_bien', ['id' => $i->getId()], UrlGeneratorInterface::ABSOLUTE_URL)),
        ], $biens);

        return $this->pdf($this->renderView('immobilisations/etiquettes_pdf.html.twig', ['etiquettes' => $etiquettes]), 'etiquettes-biens.pdf');
    }

    /**
     * Export pour le cabinet : registre à date et dotations de l'année, en
     * CSV (séparateur « ; », lisible par Excel).
     */
    #[Route('/export-cabinet', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function export(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $annee = preg_match('/^\d{4}$/', (string) $request->query->get('annee')) ? (int) $request->query->get('annee') : (int) $maintenant->format('Y');
        $lignes = $this->patrimoine->registre(null, true, null, $maintenant);
        $dotations = $this->patrimoine->dotationsEntre(new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31'));
        $parBien = [];
        foreach ($dotations as $d) {
            $parBien[(int) $d->getImmobilisation()->getId()][(int) $d->getMois()->format('n')] = $d->getMontant();
        }
        $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        $reponse = new StreamedResponse(static function () use ($lignes, $parBien, $mois, $annee): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Code', 'Bien', 'Catégorie', 'Compte immobilisation', 'Compte amortissement', 'Achat', 'Mise en service', 'Valeur d\'achat', 'Valeur résiduelle', 'Méthode', 'Amortissement cumulé', 'Valeur nette', 'Lieu', 'Confié à', 'État', 'Sortie', 'Prix de cession', ...array_map(static fn (string $m): string => 'Dotation '.$m.' '.$annee, $mois), 'Total dotations '.$annee], ';', '"', '');
            foreach ($lignes as ['bien' => $i, 'calcul' => $c]) {
                $dots = [];
                for ($n = 1; $n <= 12; ++$n) {
                    $dots[] = $parBien[(int) $i->getId()][$n] ?? '';
                }
                fputcsv($sortie, [
                    $i->getCode(), $i->getDesignation(), $i->libelleCategorie(), $i->compteImmobilisation(), $i->compteAmortissement(),
                    $i->getDateAchat()?->format('d/m/Y') ?? '', $i->getDateMiseService()?->format('d/m/Y') ?? '',
                    $c['valeurAchat'], $i->getValeurResiduelle(), $c['libelleMethode'], $c['cumul'], $c['valeurNette'],
                    $i->getLieu() ?? '', null !== $i->getConfieA() ? Pointages::nom($i->getConfieA()) : '', $i->libelleEtat(),
                    null !== $i->getSortieLe() ? Immobilisation::SORTIES[$i->getSortieMotif()].' '.$i->getSortieLe()->format('d/m/Y') : '', $i->getPrixCession() ?? '',
                    ...$dots, array_sum(array_map('intval', $dots)),
                ], ';', '"', '');
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="immobilisations-%d.csv"', $annee));

        return $reponse;
    }

    /** Passer les dotations d'un mois terminé (la commande le fait chaque matin). */
    #[Route('/dotations', name: 'dotations', methods: ['POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function dotations(Request $request): Response
    {
        $this->verifierJeton($request, 'dotations');
        $mois = self::mois((string) $request->request->get('mois'), (new \DateTimeImmutable())->modify('first day of last month'));
        try {
            $r = $this->patrimoine->doter($mois, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', sprintf('%d dotation%s passée%s pour %s : %s F (journal OD, comptes 681 / 28x).', $r['creees'], $r['creees'] > 1 ? 's' : '', $r['creees'] > 1 ? 's' : '', $mois->format('m/Y'), number_format($r['montant'], 0, ',', ' ')));
            if ([] !== $r['attente']) {
                $this->addFlash('warning', 'En attente du relevé de compteurs : '.implode(', ', $r['attente']).'.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_immobilisations_registre');
    }

    /** Inventaire annuel : chaque bien contrôlé sur place, lieu par lieu. */
    #[Route('/inventaire', name: 'inventaire', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function inventaire(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'inventaire');
            $vus = array_map('intval', (array) $request->request->all('vus'));
            $maintenant = new \DateTimeImmutable();
            $n = 0;
            foreach ($this->biens->findBy(['id' => $vus ?: [0]]) as $i) {
                $i->controler($maintenant->setTime(0, 0))->touche($maintenant);
                ++$n;
            }
            $this->em->flush();
            $this->addFlash('success', sprintf('%d bien%s contrôlé%s le %s.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : '', $maintenant->format('d/m/Y')));

            return $this->redirectToRoute('app_immobilisations_inventaire');
        }
        $parLieu = [];
        foreach ($this->biens->registre() as $i) {
            $parLieu[$i->getLieu() ?? 'Non précisé'][] = $i;
        }
        ksort($parLieu);

        return $this->render('immobilisations/inventaire.html.twig', [
            'par_lieu' => $parLieu,
            'synthese' => $this->patrimoine->synthese(new \DateTimeImmutable()),
        ]);
    }

    /** Fiche d'un bien : celui à qui il est confié la voit, sans montant. */
    #[Route('/bien/{id}', name: 'bien', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function bien(Immobilisation $bien): Response
    {
        $comptable = $this->isGranted('ROLE_COMPTABILITE');
        if (!$comptable && $bien->getConfieA() !== $this->moi()) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $calcul = $comptable ? $this->patrimoine->calculs([$bien], $maintenant)[(int) $bien->getId()] : null;
        $tableau = [];
        if ($comptable) {
            foreach ($this->patrimoine->dotationsEntre(new \DateTimeImmutable('2000-01-01'), $maintenant) as $d) {
                if ($d->getImmobilisation() === $bien) {
                    $tableau[] = $d;
                }
            }
        }

        return $this->render('immobilisations/bien.html.twig', [
            'bien' => $bien,
            'calcul' => $calcul,
            'comptable' => $comptable,
            'dotations' => array_reverse($tableau),
            'cession' => $comptable && $bien->estSorti() && null !== $calcul ? CalculAmortissement::resultatCession((int) $bien->getPrixCession(), $calcul['valeurNette']) : null,
        ]);
    }

    #[Route('/bien/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function nouveau(Request $request): Response
    {
        $machine = $request->query->getInt('machine') > 0 ? $this->machines->find($request->query->getInt('machine')) : null;
        $bien = new Immobilisation($this->biens->prochainCode(), (string) ($machine?->getNom() ?? ''), null !== $machine ? 'machine' : (string) $request->query->get('categorie', 'informatique'));
        if (null !== $machine) {
            $bien->setMachine($machine)->setLieu('Atelier');
        }

        return $this->formulaire($request, $bien, true);
    }

    #[Route('/bien/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function modifier(Request $request, Immobilisation $bien): Response
    {
        return $this->formulaire($request, $bien, false);
    }

    /** Sortie d'un bien : vente, mise au rebut ou vol, avec la plus ou moins-value. */
    #[Route('/bien/{id}/sortie', name: 'sortie', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function sortie(Request $request, Immobilisation $bien): Response
    {
        $this->verifierJeton($request, 'sortie-'.$bien->getId());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('date'));
        $motif = (string) $request->request->get('motif');
        if (false === $date || !isset(Immobilisation::SORTIES[$motif]) || $bien->estSorti()) {
            $this->addFlash('error', 'Indiquez la date et le motif de sortie.');

            return $this->redirectToRoute('app_immobilisations_bien', ['id' => $bien->getId()]);
        }
        $bien->sortir($date, $motif, self::entier($request->request->get('prix')) ?? 0)->touche(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', $bien->getCode().' sorti du patrimoine ('.mb_strtolower(Immobilisation::SORTIES[$motif]).').');

        return $this->redirectToRoute('app_immobilisations_bien', ['id' => $bien->getId()]);
    }

    /** Machines et amortissement à l'usage (M01). */
    #[Route('/machines', name: 'machines', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function machines(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $maintenant);

        return $this->render('immobilisations/machines.html.twig', $this->patrimoine->machines($mois, $maintenant) + [
            'methode' => $this->patrimoine->methodeMachines(),
            'courant' => $mois->format('Y-m') === $maintenant->format('Y-m'),
        ]);
    }

    /**
     * Tableau d'amortissement des machines : usage, dotation, cumul et
     * valeur nette mois par mois sur 12 mois, en CSV.
     */
    #[Route('/machines/tableau', name: 'tableau', methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function tableau(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $moisListe = [];
        for ($k = 11; $k >= 0; --$k) {
            $moisListe[] = $maintenant->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', $k));
        }
        $parMois = array_map(fn (\DateTimeImmutable $m): array => $this->patrimoine->machines($m, $maintenant), $moisListe);

        $reponse = new StreamedResponse(static function () use ($parMois): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Mois', 'Code', 'Machine', 'Méthode', 'Unité', 'Usage du mois', 'Usage cumulé', 'Coût par unité', 'Dotation du mois', 'Amortissement cumulé', 'Valeur nette'], ';', '"', '');
            foreach ($parMois as $donnees) {
                foreach ($donnees['lignes'] as $l) {
                    $c = $l['calcul'];
                    fputcsv($sortie, [
                        $donnees['mois']->format('m/Y'), $l['bien']?->getCode() ?? '', $l['machine']->getNom(),
                        CalculAmortissement::USAGE === $c['methode'] ? 'à l\'usage' : 'linéaire', $l['machine']->uniteUsage(),
                        null !== $c['usageMois'] ? round($c['usageMois'], 1) : '', round($l['usageCumule'], 1),
                        null !== $c['coutUnitaire'] ? round($c['coutUnitaire'], 2) : '', $c['dotation'], $c['cumul'], $c['valeurNette'],
                    ], ';', '"', '');
                }
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="tableau-amortissement-machines-%s.csv"', $maintenant->format('Y-m')));

        return $reponse;
    }

    /** Une machine sans fiche au registre y entre avec son code IMM. */
    #[Route('/machines/{id}/registre', name: 'machine_registre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function machineAuRegistre(Request $request, Machines $machine): Response
    {
        $this->verifierJeton($request, 'machine-registre-'.$machine->getId());
        if (null === $this->biens->findOneBy(['machine' => $machine])) {
            $bien = (new Immobilisation($this->biens->prochainCode(), (string) $machine->getNom(), 'machine'))->setMachine($machine)->setLieu('Atelier')
                ->setDateAchat(null !== $machine->getDateAchat() ? \DateTimeImmutable::createFromInterface($machine->getDateAchat()) : null)
                ->setNumeroSerie($machine->getNumeroSerie());
            $this->em->persist($bien);
            $this->em->flush();
            $this->addFlash('success', $machine->getNom().' entre au registre sous le code '.$bien->getCode().'.');
        }

        return $this->redirectToRoute('app_immobilisations_machines');
    }

    /** Fiche machine : compteurs, valeur et coût complet par page (M02). */
    #[Route('/machines/{id}', name: 'machine', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_COMPTABILITE')]
    public function machine(Request $request, Machines $machine): Response
    {
        $maintenant = new \DateTimeImmutable();
        $defaut = UsageMachines::suitParReleve($machine) ? $maintenant->modify('first day of last month') : $maintenant;
        $mois = self::mois((string) $request->query->get('mois'), $defaut);

        return $this->render('immobilisations/machine.html.twig', $this->patrimoine->ficheMachine($machine, $mois, $maintenant) + [
            'compteurs' => ReleveCompteur::COMPTEURS,
            'courant' => $mois->format('Y-m') === $maintenant->format('Y-m'),
        ]);
    }

    /**
     * Saisir le relevé de compteurs d'un mois, avec la photo du compteur.
     * Pas de montant ici : l'opérateur qui relève ne voit que les compteurs.
     */
    #[Route('/machines/{id}/releve', name: 'releve', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function releve(Request $request, Machines $machine, UsageMachines $usages, ReleveCompteurRepository $releves): Response
    {
        $this->autoriserReleve();
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) ($request->request->get('mois') ?? $request->query->get('mois')), $maintenant->modify('first day of last month'));
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'releve-'.$machine->getId());
            if ($mois > $maintenant) {
                $this->addFlash('error', 'Un relevé ne se saisit pas pour un mois à venir.');

                return $this->redirectToRoute('app_immobilisations_releve', ['id' => $machine->getId()]);
            }
            $a4a3 = null;
            $compteur = null;
            if ($machine->releveA4A3()) {
                $a4a3 = [];
                foreach (array_keys(ReleveCompteur::COMPTEURS) as $cle) {
                    $a4a3[$cle] = self::entier($request->request->get($cle));
                }
                if ([] === array_filter($a4a3, static fn (?int $v): bool => null !== $v)) {
                    $this->addFlash('error', 'Saisissez au moins un compteur.');

                    return $this->redirectToRoute('app_immobilisations_releve', ['id' => $machine->getId(), 'mois' => $mois->format('Y-m')]);
                }
            } else {
                $saisie = str_replace([' ', ','], ['', '.'], (string) $request->request->get('compteur'));
                $compteur = is_numeric($saisie) ? (float) $saisie : null;
                if (null === $compteur) {
                    $this->addFlash('error', 'Saisissez la valeur du compteur.');

                    return $this->redirectToRoute('app_immobilisations_releve', ['id' => $machine->getId(), 'mois' => $mois->format('Y-m')]);
                }
            }
            $photo = $request->files->get('photo');
            $chemin = $photo instanceof UploadedFile ? $this->enregistrerPhoto($photo, $machine, $mois) : null;
            if ($photo instanceof UploadedFile && null === $chemin) {
                $this->addFlash('warning', 'La photo n\'a pas été gardée : JPEG ou PNG de moins de 6 Mo.');
            }
            $usages->enregistrerReleve($machine, $mois, $a4a3, $compteur, $chemin, $this->moi(), $maintenant);
            $this->addFlash('success', sprintf('Relevé de %s enregistré pour %s.', $machine->getNom(), $mois->format('m/Y')));

            return $this->redirectToRoute($this->isGranted('ROLE_COMPTABILITE') ? 'app_immobilisations_machine' : 'app_immobilisations_releves', ['id' => $machine->getId(), 'mois' => $mois->format('Y-m')]);
        }

        $tous = $releves->deMachine($machine);

        return $this->render('immobilisations/releve.html.twig', [
            'machine' => $machine,
            'mois' => $mois,
            'releve' => $tous[$mois->format('Y-m')] ?? null,
            'precedent' => $tous[$mois->modify('-1 month')->format('Y-m')] ?? null,
            'compteurs' => ReleveCompteur::COMPTEURS,
        ]);
    }

    /** Historique des relevés d'une machine. */
    #[Route('/machines/{id}/releves', name: 'releves', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function releves(Machines $machine, ReleveCompteurRepository $releves): Response
    {
        $this->autoriserReleve();
        $tous = array_reverse($releves->deMachine($machine), true);
        $lignes = [];
        foreach ($tous as $cle => $r) {
            $lignes[] = ['releve' => $r, 'pages' => UsageMachines::pagesDuMois($tous, $r->getMois())];
        }

        return $this->render('immobilisations/releves.html.twig', [
            'machine' => $machine,
            'lignes' => $lignes,
            'compteurs' => ReleveCompteur::COMPTEURS,
        ]);
    }

    #[Route('/releve/{id}/photo', name: 'releve_photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photo(ReleveCompteur $releve): Response
    {
        $this->autoriserReleve();
        $relatif = (string) $releve->getPhoto();
        $chemin = $this->dossierPhotos.'/'.$relatif;
        if (!preg_match('#^\d{4}/[0-9a-f]{32}\.(jpg|png)$#', $relatif) || !is_file($chemin)) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Cache-Control', 'private, max-age=3600');

        return $reponse;
    }

    private function formulaire(Request $request, Immobilisation $bien, bool $nouveau): Response
    {
        $erreurs = [];
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request, 'bien-'.($bien->getId() ?? 'nouveau'));
            $r = $request->request;
            $designation = trim((string) $r->get('designation'));
            $bien->setDesignation($designation)
                ->setCategorie((string) $r->get('categorie'))
                ->setLieu((string) $r->get('lieu'))
                ->setDateAchat(self::date((string) $r->get('date_achat')))
                ->setDateMiseService(self::date((string) $r->get('date_mise_service')))
                ->setValeurAchat(self::entier($r->get('valeur_achat')) ?? 0)
                ->setValeurResiduelle(self::entier($r->get('valeur_residuelle')) ?? 0)
                ->setDureeAnnees(self::entier($r->get('duree')))
                ->setQuantite(self::entier($r->get('quantite')) ?? 1)
                ->setEtat((string) $r->get('etat'), (string) $r->get('etat_note'))
                ->setFournisseur((string) $r->get('fournisseur'))
                ->setNumeroSerie((string) $r->get('numero_serie'))
                ->setNote((string) $r->get('note'))
                ->setConfieA($r->getInt('confie_a') > 0 ? $this->em->getRepository(User::class)->find($r->getInt('confie_a')) : null)
                ->touche(new \DateTimeImmutable());
            if ('machine' === $bien->getCategorie()) {
                $machine = $r->getInt('machine') > 0 ? $this->machines->find($r->getInt('machine')) : null;
                $autre = null !== $machine ? $this->biens->findOneBy(['machine' => $machine]) : null;
                if (null !== $autre && $autre !== $bien) {
                    $erreurs[] = $machine->getNom().' est déjà au registre ('.$autre->getCode().').';
                } else {
                    $bien->setMachine($machine);
                }
            } else {
                $bien->setMachine(null);
            }
            if ('' === $designation) {
                $erreurs[] = 'Indiquez le bien.';
            }
            if (null === $bien->getMachine() && $bien->getValeurAchat() <= 0) {
                $erreurs[] = 'Indiquez la valeur d\'achat.';
            }
            if (null === $bien->getDateMiseService()) {
                $erreurs[] = 'Indiquez la date d\'achat ou de mise en service.';
            }
            if ([] === $erreurs) {
                $this->em->persist($bien);
                $this->em->flush();
                $this->addFlash('success', $bien->getCode().' '.($nouveau ? 'ajouté au registre.' : 'enregistré.'));

                return $this->redirectToRoute('app_immobilisations_bien', ['id' => $bien->getId()]);
            }
        }
        $libres = array_filter($this->machines->findBy([], ['nom' => 'ASC']), fn (Machines $m): bool => null === ($b = $this->biens->findOneBy(['machine' => $m])) || $b === $bien);

        return $this->render('immobilisations/bien_form.html.twig', [
            'bien' => $bien,
            'nouveau' => $nouveau,
            'erreurs' => $erreurs,
            'machines' => array_values($libres),
            'personnel' => $this->em->getRepository(User::class)->createQueryBuilder('u')->innerJoin('u.employe', 'e')->andWhere('u.actif = true')->orderBy('e.prenom', 'ASC')->getQuery()->getResult(),
        ]);
    }

    /**
     * Retire tout montant d'une ligne du registre (agent sans droit
     * comptable) : il ne reste que le code, le bien, le lieu et l'état.
     *
     * @param list<array{bien: Immobilisation, calcul: array<string, mixed>}> $lignes
     *
     * @return list<array{bien: Immobilisation, calcul: array<string, mixed>}>
     */
    public static function sansMontants(array $lignes): array
    {
        return array_map(static fn (array $l): array => ['bien' => $l['bien'], 'calcul' => ['libelleMethode' => $l['calcul']['libelleMethode']]], $lignes);
    }

    private function autoriserReleve(): void
    {
        if (!$this->isGranted('ROLE_COMPTABILITE') && !$this->isGranted('ROLE_PRODUCTION_GERER')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function enregistrerPhoto(UploadedFile $photo, Machines $machine, \DateTimeImmutable $mois): ?string
    {
        $type = (string) $photo->getMimeType();
        if (!$photo->isValid() || $photo->getSize() > self::PHOTO_MAX_OCTETS || !\in_array($type, ['image/jpeg', 'image/png'], true)) {
            return null;
        }
        $relatif = $mois->format('Y').'/'.bin2hex(random_bytes(16)).('image/png' === $type ? '.png' : '.jpg');
        $dossier = $this->dossierPhotos.'/'.$mois->format('Y');
        if (!is_dir($dossier) && !mkdir($dossier, 0o770, true) && !is_dir($dossier)) {
            return null;
        }
        $photo->move($dossier, basename($relatif));

        return $relatif;
    }

    private function pdf(string $html, string $nom, string $orientation = 'portrait'): Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nom),
        ]);
    }

    private function moi(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private static function mois(string $saisie, \DateTimeImmutable $defaut): \DateTimeImmutable
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $saisie) ? new \DateTimeImmutable($saisie.'-01') : $defaut->modify('first day of this month')->setTime(0, 0);
    }

    private static function date(string $saisie): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        return false === $date ? null : $date;
    }

    private static function entier(mixed $saisie): ?int
    {
        $chiffres = preg_replace('/\D/', '', (string) $saisie);

        return '' !== $chiffres ? (int) $chiffres : null;
    }
}
