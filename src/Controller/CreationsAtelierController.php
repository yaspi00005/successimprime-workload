<?php

namespace App\Controller;

use App\Entity\CreationAtelier;
use App\Entity\DemandeDevis;
use App\Entity\JournalActivite;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\Atelier\ImagesAtelier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes › Créations Atelier 3D (docs/atelier-3d.md) : modèles validés
 * par les clients et les visiteurs, arrivés par la synchronisation avec
 * leur commande « à confirmer » ou leur demande de devis. Le graphiste y
 * voit les vues de face et de dos (maquette du client, pas un BAT) et
 * télécharge l'image d'origine de chaque face.
 *
 * Pour les personnes qui font les devis ou les commandes (le graphiste PAO
 * a ces droits), seulement sur l'ERP de la boutique. Aucun prix ici.
 */
#[Route('/ventes/creations-atelier', name: 'app_creations_atelier_')]
#[IsGranted(new Expression('is_granted("ROLE_DEVIS") or is_granted("ROLE_COMMANDE")'))]
final class CreationsAtelierController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccesEnLigne $acces,
        private readonly ImagesAtelier $images,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->acces->estEnLigne()) {
            return $this->render('creations_atelier/index.html.twig', ['enLigne' => true, 'liste' => [], 'demandes' => [], 'filtre' => 'toutes']);
        }
        $filtre = $request->query->getString('filtre', 'toutes');
        $qb = $this->em->getRepository(CreationAtelier::class)->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('c.commande', 'co')->addSelect('co')
            ->orderBy('c.id', 'DESC')->setMaxResults(200);
        if ('commandes' === $filtre) {
            $qb->andWhere('c.commande IS NOT NULL');
        } elseif ('devis' === $filtre) {
            $qb->andWhere('c.client IS NULL');
        }
        $liste = $qb->getQuery()->getResult();

        return $this->render('creations_atelier/index.html.twig', [
            'enLigne' => false,
            'liste' => $liste,
            'demandes' => $this->demandesDe($liste),
            'filtre' => $filtre,
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id): Response
    {
        $creation = $this->creation($id);
        $presents = [];
        foreach (['rendu-devant', 'rendu-dos', 'image-devant', 'image-dos'] as $quoi) {
            $f = $creation->fichier($quoi);
            $presents[$quoi] = null !== $f && null !== $this->images->chemin($f);
        }

        return $this->render('creations_atelier/voir.html.twig', [
            'c' => $creation,
            'demande' => $this->demandesDe([$creation])[$creation->getId()] ?? null,
            'presents' => $presents,
        ]);
    }

    /**
     * Vue rendue (affichée dans la page, déjà réencodée en PNG) ou image
     * d'origine (téléchargée, notée au journal).
     */
    #[Route('/{id}/fichier/{quoi}', name: 'fichier', requirements: ['id' => '\d+', 'quoi' => 'rendu-devant|rendu-dos|image-devant|image-dos'], methods: ['GET'])]
    public function fichier(int $id, string $quoi): Response
    {
        $creation = $this->creation($id);
        $f = $creation->fichier($quoi);
        $chemin = null !== $f && $f->isActif() ? $this->images->chemin($f) : null;
        if (null === $chemin) {
            throw $this->createNotFoundException('Le fichier n\'est pas encore arrivé du site.');
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'private, no-store');
        if (str_starts_with($quoi, 'rendu-')) {
            $reponse->headers->set('Content-Type', 'image/png');
            $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, \sprintf('%s-%s.png', $creation->getNumero() ?? 'creation', $quoi), 'rendu.png');

            return $reponse;
        }
        $moi = $this->getUser();
        $this->em->persist((new JournalActivite())
            ->setEntite('CreationAtelier')
            ->setEntiteId((int) $creation->getId())
            ->setAction('telechargement')
            ->setDonneesApres(['fichier' => $f->getNomOriginal(), 'face' => $quoi])
            ->setUtilisateur($moi instanceof User ? $moi : null));
        $this->em->flush();
        $reponse->headers->set('Content-Type', 'application/octet-stream');
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $f->getNomOriginal(), 'image');

        return $reponse;
    }

    private function creation(int $id): CreationAtelier
    {
        $creation = $this->acces->estEnLigne() ? null : $this->em->find(CreationAtelier::class, $id);
        if (!$creation instanceof CreationAtelier) {
            throw $this->createNotFoundException('Création introuvable.');
        }

        return $creation;
    }

    /**
     * Demande de devis de chaque création d'un visiteur.
     *
     * @param list<CreationAtelier> $creations
     *
     * @return array<int, DemandeDevis>
     */
    private function demandesDe(array $creations): array
    {
        if ([] === $creations) {
            return [];
        }
        $parCreation = [];
        foreach ($this->em->getRepository(DemandeDevis::class)->findBy(['creation' => $creations]) as $d) {
            $parCreation[(int) $d->getCreation()?->getId()] = $d;
        }

        return $parCreation;
    }
}
