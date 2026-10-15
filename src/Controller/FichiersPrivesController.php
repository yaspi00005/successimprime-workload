<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\Employes;
use App\Entity\Etiquette;
use App\Entity\LotEtiquette;
use App\Entity\Message;
use App\Entity\Reclamation;
use App\Security\FichierEnvoye;
use App\Security\FichiersPrivesVoter;
use App\Service\FichiersPrives;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fichiers privés (docs/securite.md, « Fichiers envoyés ») : photos du
 * personnel, et anciennes adresses /uploads/… des fichiers rangés
 * désormais hors de public/.
 *
 * Une ancienne adresse (lien gardé dans un favori, un message…) arrive
 * ici une fois le fichier déplacé par app:fichiers:deplacer : sans
 * connexion, la page de connexion (security.yaml) ; sans le droit,
 * « accès refusé » ; sinon, renvoi vers l'adresse qui contrôle les droits.
 */
final class FichiersPrivesController extends AbstractController
{
    public function __construct(
        private readonly FichiersPrives $fichiersPrives,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Photo d'un employé (avatar du chat, des listes de l'équipe) : tout le personnel connecté. */
    #[Route('/equipe/photo/{id}', name: 'app_photo_employe', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photoEmploye(Employes $employe): Response
    {
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::PHOTO_EMPLOYE, $employe);
        $chemin = $this->fichiersPrives->cheminDans('employes', $employe->getPhotos()) ?? throw $this->createNotFoundException();

        return FichierEnvoye::reponse($chemin, 'photo.'.pathinfo($chemin, \PATHINFO_EXTENSION));
    }

    #[Route('/uploads/chat/{nom}', name: 'app_fichiers_ancien_chat', requirements: ['nom' => '[^/]+'], methods: ['GET'])]
    public function ancienChat(string $nom): Response
    {
        $message = $this->em->getRepository(Message::class)->findOneBy(['pieceJointeFichier' => $nom]) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::CHAT, $message);

        return $this->redirectToRoute('app_chat_fichier', ['jeton' => $message->getConversation()?->getJeton(), 'id' => $message->getId()]);
    }

    #[Route('/uploads/prepresse/{chemin}', name: 'app_fichiers_ancien_prepresse', requirements: ['chemin' => '.+'], methods: ['GET'])]
    public function anciennePrepresse(string $chemin): Response
    {
        $fichier = $this->em->getRepository(CommandeDetailFichier::class)->findOneBy(['chemin' => 'uploads/prepresse/'.$chemin]) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::PREPRESSE, $fichier);

        return $this->redirectToRoute('app_controle_pre_presse_visualiser_fichier', ['id' => $fichier->getId()]);
    }

    #[Route('/uploads/etiquettes/{chemin}', name: 'app_fichiers_ancienne_etiquette', requirements: ['chemin' => '.+'], methods: ['GET'])]
    public function ancienneEtiquette(string $chemin): Response
    {
        $relatif = 'uploads/etiquettes/'.$chemin;
        $etiquette = $this->em->getRepository(Etiquette::class)->findOneBy(['fichierQr' => $relatif]);
        $lot = null === $etiquette ? $this->em->getRepository(LotEtiquette::class)->findOneBy(['fichierZip' => $relatif]) : null;
        $sujet = $etiquette ?? $lot ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::ETIQUETTE, $sujet);

        return null !== $etiquette
            ? $this->redirectToRoute('app_lot_etiquette_qr', ['id' => $etiquette->getId()])
            : $this->redirectToRoute('app_lot_etiquette_telecharger', ['id' => $lot?->getId()]);
    }

    #[Route('/uploads/reclamations/{nom}', name: 'app_fichiers_ancien_justificatif', requirements: ['nom' => '[^/]+'], methods: ['GET'])]
    public function ancienJustificatif(string $nom): Response
    {
        $reclamation = $this->em->getRepository(Reclamation::class)->findOneBy(['justificatif' => $nom]) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::JUSTIFICATIF, $reclamation);

        return $this->redirectToRoute('app_reclamation_justificatif', ['id' => $reclamation->getId()]);
    }

    #[Route('/uploads/employes/{nom}', name: 'app_fichiers_ancienne_photo', requirements: ['nom' => '[^/]+'], methods: ['GET'])]
    public function anciennePhoto(string $nom): Response
    {
        $employe = $this->em->getRepository(Employes::class)->findOneBy(['photos' => $nom]) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(FichiersPrivesVoter::PHOTO_EMPLOYE, $employe);

        return $this->redirectToRoute('app_photo_employe', ['id' => $employe->getId()]);
    }
}
