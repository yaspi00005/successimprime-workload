<?php

namespace App\Service\EspaceClient;

use App\Entity\AppareilClient;
use App\Entity\CompteClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Appareils connectés à l'espace d'une personne (Mon compte › Sécurité,
 * maquette EC04) : un cookie aléatoire par navigateur, dont seule
 * l'empreinte est gardée, le type d'appareil lu dans le navigateur et la
 * dernière activité. Ni adresse IP, ni position. « Déconnecter les autres
 * appareils » augmente la version de session du compte : les autres
 * sessions sont fermées à leur prochaine page (EspaceClientSubscriber).
 */
final class AppareilsClient
{
    public const COOKIE = 'si_espace_appareil';

    /** Clé de session : version de session du compte à la connexion. */
    public const SESSION_VERSION = 'espace_version_session';

    /** L'activité est notée au plus toutes les 5 minutes. */
    private const PAS_SECONDES = 300;

    /** Un appareil sans activité depuis 90 jours est oublié. */
    private const OUBLI_JOURS = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** « iPhone », « Android », « PC Windows »… lu dans l'en-tête du navigateur. */
    public static function libelle(string $navigateur): string
    {
        return match (true) {
            str_contains($navigateur, 'iPhone') => 'iPhone',
            str_contains($navigateur, 'iPad') => 'iPad',
            str_contains($navigateur, 'Android') && str_contains($navigateur, 'Mobile') => 'Android',
            str_contains($navigateur, 'Android') => 'Tablette Android',
            str_contains($navigateur, 'CrOS') => 'Chromebook',
            str_contains($navigateur, 'Windows') => 'PC Windows',
            str_contains($navigateur, 'Macintosh') || str_contains($navigateur, 'Mac OS') => 'Mac',
            str_contains($navigateur, 'Linux') => 'PC Linux',
            default => 'Navigateur',
        };
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * Note l'activité de cet appareil. Renvoie le cookie à poser quand le
     * navigateur n'en a pas encore.
     */
    public function vu(CompteClient $compte, Request $requete, \DateTimeImmutable $maintenant): ?Cookie
    {
        $jeton = (string) $requete->cookies->get(self::COOKIE, '');
        $nouveau = null;
        if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
            $jeton = bin2hex(random_bytes(32));
            $nouveau = Cookie::create(self::COOKIE, $jeton, $maintenant->modify('+1 year'), '/espace', null, $requete->isSecure(), true, false, Cookie::SAMESITE_LAX);
        }
        $libelle = self::libelle((string) $requete->headers->get('User-Agent'));
        $appareil = $this->em->getRepository(AppareilClient::class)->findOneBy(['compte' => $compte, 'empreinte' => self::empreinte($jeton)]);
        if (null === $appareil) {
            $this->em->createQuery('DELETE FROM '.AppareilClient::class.' a WHERE a.compte = :c AND a.derniereActivite < :avant')
                ->setParameter('c', $compte)->setParameter('avant', $maintenant->modify(\sprintf('-%d days', self::OUBLI_JOURS)))->execute();
            $this->em->persist(new AppareilClient($compte, self::empreinte($jeton), $libelle, $maintenant));
            $this->em->flush();
        } elseif ($maintenant->getTimestamp() - $appareil->getDerniereActivite()->getTimestamp() >= self::PAS_SECONDES) {
            $appareil->vu($libelle, $maintenant);
            $this->em->flush();
        }

        return $nouveau;
    }

    /**
     * Les appareils de la personne, le plus récent d'abord ; « ce téléphone »
     * est celui de la requête.
     *
     * @return list<array{libelle: string, derniereActivite: \DateTimeImmutable, courant: bool}>
     */
    public function liste(CompteClient $compte, Request $requete): array
    {
        $courant = self::empreinte((string) $requete->cookies->get(self::COOKIE, ''));

        return array_map(
            static fn (AppareilClient $a): array => ['libelle' => $a->getLibelle(), 'derniereActivite' => $a->getDerniereActivite(), 'courant' => $a->getEmpreinte() === $courant],
            $this->em->getRepository(AppareilClient::class)->findBy(['compte' => $compte], ['derniereActivite' => 'DESC'], 10),
        );
    }

    /**
     * Pour Mon compte : un appareil par type, celui-ci d'abord, avec le
     * nombre quand le même type revient (« Navigateur (3) »).
     *
     * @param list<array{libelle: string, derniereActivite: \DateTimeImmutable, courant: bool}> $liste le plus récent d'abord
     *
     * @return list<array{libelle: string, courant: bool, nombre: int}>
     */
    public static function regrouper(array $liste): array
    {
        $groupes = [];
        foreach ($liste as $a) {
            $cle = $a['courant'] ? "\0courant" : $a['libelle'];
            $groupes[$cle] ??= ['libelle' => $a['libelle'], 'courant' => $a['courant'], 'nombre' => 0];
            ++$groupes[$cle]['nombre'];
        }
        uasort($groupes, static fn (array $x, array $y): int => $y['courant'] <=> $x['courant']);

        return array_values($groupes);
    }

    /**
     * « Déconnecter les autres appareils » : nouvelle version de session
     * (les autres sessions et cookies « rester connecté » tombent), cet
     * appareil reste connecté.
     */
    public function deconnecterLesAutres(CompteClient $compte, Request $requete): void
    {
        $version = $compte->nouvelleVersionSession();
        if ($requete->hasSession()) {
            $requete->getSession()->set(self::SESSION_VERSION, $version);
        }
        $this->em->createQuery('DELETE FROM '.AppareilClient::class.' a WHERE a.compte = :c AND a.empreinte != :e')
            ->setParameter('c', $compte)->setParameter('e', self::empreinte((string) $requete->cookies->get(self::COOKIE, '')))->execute();
        $this->em->flush();
    }

    /** La session a-t-elle été ouverte avant « Déconnecter les autres appareils » ? */
    public static function sessionPerimee(CompteClient $compte, int $versionDeLaSession): bool
    {
        return $versionDeLaSession < $compte->getVersionSession();
    }
}
