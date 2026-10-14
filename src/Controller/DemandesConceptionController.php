<?php

namespace App\Controller;

use App\Entity\DemandeConception;
use App\Entity\MouvementCreditIa;
use App\Entity\User;
use App\Service\Conception\DemandesConception;
use App\Service\Conception\ReglagesConception;
use App\Service\Conception\TarifConception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Atelier › Demandes de conception (docs/demande-conception.md) : les
 * demandes envoyées depuis l'espace client. Le graphiste la prend en
 * charge, puis le travail suit le parcours habituel (pré-presse, BAT).
 * Les décisions se prennent au local ; en ligne, la page se consulte.
 */
#[Route('/atelier/demandes-conception', name: 'app_demandes_conception_')]
final class DemandesConceptionController extends AbstractController
{
    private const CSRF = 'demandes-conception';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, DemandesConception $demandes, ReglagesConception $reglages): Response
    {
        $this->autoriser();
        $toutes = $request->query->getBoolean('toutes');
        $requete = $this->em->getRepository(DemandeConception::class)->createQueryBuilder('d')
            ->where('d.statut NOT IN (:ouvertes)')->setParameter('ouvertes', DemandeConception::OUVERTES)
            ->orderBy('d.envoyeeLe', 'DESC')->addOrderBy('d.id', 'DESC')->setMaxResults(150);
        if (!$toutes) {
            $requete->andWhere('d.statut NOT IN (:finies)')->setParameter('finies', [DemandeConception::TERMINEE, DemandeConception::ANNULEE]);
        }
        $liste = $requete->getQuery()->getResult();
        $this->suivreLesBat($liste, $demandes);

        return $this->render('demandes_conception/index.html.twig', [
            'liste' => $liste,
            'toutes' => $toutes,
            'maintenant' => new \DateTimeImmutable(),
            // Coût des images IA du mois : la direction seulement, jamais au client.
            'coutMois' => $this->isGranted('ROLE_ADMIN') ? $this->coutDuMois($reglages) : null,
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id, DemandesConception $demandes): Response
    {
        $this->autoriser();
        $demande = $this->trouver($id);
        $this->suivreLesBat([$demande], $demandes);
        $ligne = null;
        foreach ($demande->getCommande()?->getCommandesDetails() ?? [] as $l) {
            if ($l->isPrePresseNecessaire()) {
                $ligne ??= $l;
            }
        }

        return $this->render('demandes_conception/voir.html.twig', [
            'd' => $demande,
            'ligne' => $ligne,
            'controle' => DemandesConception::dernierControle($demande),
            'acompte' => TarifConception::acompte($demande->getForfait(), $demande->getAcomptePourcent()),
            'enLigne' => $this->enLigne,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/prendre', name: 'prendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prendre(int $id, Request $request): Response
    {
        $this->verifierAction($request);
        $demande = $this->trouver($id);
        if (\in_array($demande->getStatut(), [DemandeConception::TERMINEE, DemandeConception::ANNULEE], true) || $demande->estOuverte()) {
            $this->addFlash('error', 'Cette demande ne se prend plus en charge.');
        } else {
            $demande->prendreEnCharge($this->moi(), new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('Demande %s prise en charge : le client le voit dans son espace.', $demande->getNumero()));
        }

        return $this->redirectToRoute('app_demandes_conception_voir', ['id' => $demande->getId()]);
    }

    #[Route('/{id}/terminer', name: 'terminer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function terminer(int $id, Request $request): Response
    {
        $this->verifierAction($request);
        $demande = $this->trouver($id);
        $statut = DemandeConception::ANNULEE === $request->request->getString('statut') ? DemandeConception::ANNULEE : DemandeConception::TERMINEE;
        if (\in_array($demande->getStatut(), [DemandeConception::TERMINEE, DemandeConception::ANNULEE], true) || $demande->estOuverte()) {
            $this->addFlash('error', 'Cette demande est déjà close.');
        } else {
            $demande->terminer($statut, new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', DemandeConception::ANNULEE === $statut
                ? \sprintf('Demande %s annulée. Pensez à prévenir le client et à traiter l\'acompte éventuel à la caisse.', $demande->getNumero())
                : \sprintf('Demande %s terminée.', $demande->getNumero()));
        }

        return $this->redirectToRoute('app_demandes_conception_voir', ['id' => $demande->getId()]);
    }

    /** @param iterable<DemandeConception> $liste */
    private function suivreLesBat(iterable $liste, DemandesConception $demandes): void
    {
        if ($this->enLigne) {
            return;
        }
        $change = false;
        foreach ($liste as $d) {
            $change = $demandes->suivreBat($d, new \DateTimeImmutable()) || $change;
        }
        if ($change) {
            $this->em->flush();
        }
    }

    /**
     * Images IA du mois (générées moins remboursées) et leur coût estimé
     * (réglage « coût d'une image »).
     *
     * @return array{images: int, cout: int}
     */
    private function coutDuMois(ReglagesConception $reglages): array
    {
        $images = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(m.images), 0)')->from(MouvementCreditIa::class, 'm')
            ->where('m.type IN (:types) AND m.creeLe >= :debut')
            ->setParameter('types', [MouvementCreditIa::DEBIT, MouvementCreditIa::REMBOURSEMENT])
            ->setParameter('debut', new \DateTimeImmutable('first day of this month midnight'))
            ->getQuery()->getSingleScalarResult();
        $images = max(0, -$images);

        return ['images' => $images, 'cout' => $images * $reglages->coutImage()];
    }

    /** Lue après le contrôle des droits : sans droit, on ne sait pas si la demande existe. */
    private function trouver(int $id): DemandeConception
    {
        $demande = $this->em->find(DemandeConception::class, $id);
        if (!$demande instanceof DemandeConception || $demande->estOuverte()) {
            throw $this->createNotFoundException();
        }

        return $demande;
    }

    private function autoriser(): void
    {
        if (!$this->isGranted('ROLE_PREPRESSE') && !$this->isGranted('ROLE_COMMANDE')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function verifierAction(Request $request): void
    {
        $this->autoriser();
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($this->enLigne) {
            // La demande se suit au local : en ligne, un changement ferait conflit à la synchronisation.
            throw $this->createAccessDeniedException('À faire depuis le serveur de l\'atelier.');
        }
    }

    private function moi(): string
    {
        $moi = $this->getUser();

        return $moi instanceof User ? (string) ($moi->getEmploye()?->getPrenom() ?? $moi->getUsername()) : 'Atelier';
    }
}
