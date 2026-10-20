<?php

namespace App\Controller;

use App\Entity\AtelierMachine;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\Supports;
use App\Security\Droits;
use App\Service\Atelier\AtelierProduction;
use App\Service\Production\FichierProduction;
use App\Service\Production\NomFichierProduction;
use App\Service\Production\ProfilsImpression;
use App\Service\Production\TiragesRouleau;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Production : « Machines et dossiers » (dossier de dépôt, tête, usages,
 * miroir et profils d'impression de chaque machine) et « Règles de
 * nommage » (codes courts des supports et des finitions dans le nom des
 * fichiers). docs/production-rouleau.md.
 *
 * Voir : « Production ». Modifier : « Statuts de production » et « Chef
 * d'atelier » (la Direction l'a d'office). ERP local seulement
 * (AtelierHorsLigneSubscriber : 404 en ligne).
 */
#[Route('/atelier/production', name: 'app_atelier_production_')]
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class ProductionMachinesController extends AbstractController
{
    private const CSRF = 'production-rouleau';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TiragesRouleau $tirages,
        private readonly FichierProduction $fichiers,
    ) {
    }

    #[Route('/machines', name: 'machines', methods: ['GET'])]
    public function machines(): Response
    {
        $liste = [];
        foreach ($this->tirages->machines() as $l) {
            $code = $l['atelier']?->getCodeDossier();
            $liste[] = $l + [
                'dossier' => $this->fichiers->dossierAffiche($code),
                'rouleau' => $this->tirages->estMachineRouleau($l['machine'], $l['atelier']),
            ];
        }

        return $this->render('production_rouleau/machines.html.twig', [
            'machines' => $liste,
            'racine' => null !== $this->fichiers->racine(),
            'racineSaisie' => $this->fichiers->racineSaisie(),
            'racineAffichee' => $this->fichiers->dossierAffiche('MACHINE'),
            'peutModifier' => $this->peutModifier(),
            'profilsMax' => ProfilsImpression::MAX,
        ]);
    }

    #[Route('/machines/{id}', name: 'machine', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enregistrerMachine(Machines $machine, Request $request, AtelierProduction $atelierProduction): Response
    {
        $this->exigerModification($request);
        $atelier = $atelierProduction->atelier($machine);
        $r = $request->request;
        $code = AtelierMachine::nettoyerCodeDossier((string) $r->get('codeDossier'));
        if (null !== $code) {
            $autre = $this->em->getRepository(AtelierMachine::class)->findOneBy(['codeDossier' => $code]);
            if (null !== $autre && $autre !== $atelier) {
                $this->addFlash('danger', \sprintf('Le dossier %s est déjà celui de %s : un dossier par machine.', $code, $autre->getMachine()->getNom()));

                return $this->redirectToRoute('app_atelier_production_machines', ['_fragment' => 'machine-'.$machine->getId()]);
            }
        }
        $atelier->setCodeDossier($code);
        $atelier->setTeteImpression(mb_substr((string) $r->get('teteImpression'), 0, 200));
        $atelier->setUsages(mb_substr((string) $r->get('usages'), 0, 400));
        $atelier->setMiroir($r->getBoolean('miroir'));
        $profils = [];
        foreach ((array) $r->all('profils') as $p) {
            if (\is_array($p) && empty($p['retirer'])) {
                $profils[] = $p;
            }
        }
        $atelier->setProfils($profils);
        $this->em->flush();
        $this->addFlash('success', \sprintf('%s : dossier, tête et profils enregistrés.', $machine->getNom()));

        return $this->redirectToRoute('app_atelier_production_machines', ['_fragment' => 'machine-'.$machine->getId()]);
    }

    #[Route('/nommage', name: 'nommage', methods: ['GET'])]
    public function nommage(): Response
    {
        $exemple = NomFichierProduction::nom('CMD-000183', 1, 'BACHE440', 300, 100, 2, ['OURLET', 'OEIL50'], 'pdf');

        return $this->render('production_rouleau/nommage.html.twig', [
            'exemple' => $exemple,
            'parties' => NomFichierProduction::parties($exemple),
            'decoupe' => NomFichierProduction::nomDecoupe(NomFichierProduction::nom('CMD-000430', 2, 'BACHEFL440', 120, 200, 1, ['FOURREAU'], 'png'), 'svg'),
            'supports' => $this->em->getRepository(Supports::class)->findBy([], ['nom' => 'ASC'], 300),
            'finitions' => $this->em->getRepository(Finition::class)->findBy([], ['nom' => 'ASC'], 300),
            'peutModifier' => $this->peutModifier(),
            'longueurMax' => NomFichierProduction::LONGUEUR_MAX,
        ]);
    }

    #[Route('/nommage', name: 'nommage_enregistrer', methods: ['POST'])]
    public function enregistrerNommage(Request $request): Response
    {
        $this->exigerModification($request);
        $codes = (array) $request->request->all('codes');
        $changes = 0;
        foreach (['support' => Supports::class, 'finition' => Finition::class] as $type => $classe) {
            $saisis = \is_array($codes[$type] ?? null) ? $codes[$type] : [];
            if ([] === $saisis) {
                continue;
            }
            $ids = array_values(array_filter(array_map('intval', array_keys($saisis)), static fn (int $id): bool => $id > 0));
            foreach ($this->em->getRepository($classe)->findBy(['id' => \array_slice($ids, 0, 500)]) as $entite) {
                \assert($entite instanceof Supports || $entite instanceof Finition);
                $avant = $entite->getCodeProduction();
                $entite->setCodeProduction(mb_substr((string) $saisis[$entite->getId()], 0, 60));
                if ($avant !== $entite->getCodeProduction()) {
                    ++$changes;
                }
            }
        }
        $this->em->flush();
        $this->addFlash('success', 0 === $changes ? 'Aucun code changé.' : \sprintf('%d code%s enregistré%s : les prochains fichiers les utilisent, les fichiers déjà nommés gardent leur nom.', $changes, $changes > 1 ? 's' : '', $changes > 1 ? 's' : ''));

        return $this->redirectToRoute('app_atelier_production_nommage');
    }

    private function peutModifier(): bool
    {
        return $this->isGranted('ROLE_PRODUCTION_GERER') && $this->isGranted(Droits::ATELIER_CHEF);
    }

    private function exigerModification(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if (!$this->peutModifier()) {
            throw $this->createAccessDeniedException('Seul le chef d\'atelier (ou la Direction) règle les machines et les codes des fichiers.');
        }
    }
}
