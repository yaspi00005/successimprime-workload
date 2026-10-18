<?php

namespace App\Service\Messagerie;

use App\Entity\AgentMessagerie;
use App\Entity\User;
use App\Service\FichiersPrives;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'employé tel que le client le voit dans Messages (maquette MS01) : nom,
 * métier et vignette de la photo de sa fiche (sinon ses initiales). Fait au
 * local à chaque réponse ; la fiche AgentMessagerie descend au site.
 */
final class AgentsMessagerie
{
    /** Côté de la vignette envoyée au site. */
    public const COTE = 96;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FichiersPrives $fichiers,
    ) {
    }

    public function pour(User $u, \DateTimeImmutable $maintenant): AgentMessagerie
    {
        $agent = $this->em->getRepository(AgentMessagerie::class)->findOneBy(['utilisateur' => $u]);
        if (!$agent instanceof AgentMessagerie) {
            $agent = AgentMessagerie::pour($u);
            $this->em->persist($agent);
        }
        $employe = $u->getEmploye();
        $nom = null !== $employe && '' !== trim($employe->getNomComplet()) ? $employe->getNomComplet() : (string) $u->getUsername();
        $metier = $employe?->getFonction();
        $photo = null;
        if (null !== $employe && null !== $employe->getPhotos()) {
            $photo = self::vignette((string) @file_get_contents((string) $this->fichiers->cheminDans('employes', $employe->getPhotos())));
        }
        $agent->mettreAJour($nom, $metier, $photo, $maintenant);

        return $agent;
    }

    /**
     * Vignette carrée (centre de la photo), JPEG sans métadonnées, en base64 ;
     * null si la photo est illisible ou si GD manque.
     */
    public static function vignette(string $octets): ?string
    {
        if ('' === $octets || !\function_exists('imagecreatefromstring')) {
            return null;
        }
        $source = @imagecreatefromstring($octets);
        if (false === $source) {
            return null;
        }
        $l = imagesx($source);
        $h = imagesy($source);
        $cote = min($l, $h);
        $cible = imagecreatetruecolor(self::COTE, self::COTE);
        imagefill($cible, 0, 0, (int) imagecolorallocate($cible, 255, 255, 255));
        imagecopyresampled($cible, $source, 0, 0, intdiv($l - $cote, 2), intdiv($h - $cote, 2), self::COTE, self::COTE, $cote, $cote);
        ob_start();
        $ok = \function_exists('imagejpeg') && imagejpeg($cible, null, 80);
        $jpeg = (string) ob_get_clean();

        return $ok && '' !== $jpeg ? base64_encode($jpeg) : null;
    }
}
