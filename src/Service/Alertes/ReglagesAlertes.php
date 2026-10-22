<?php

namespace App\Service\Alertes;

use App\Entity\AlerteSysteme;
use App\Entity\Reglage;
use App\Entity\User;
use App\Repository\ReglageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages des alertes système (Réglages › Alertes système › Réglages,
 * docs/alertes-systeme.md), dans la ligne « alertes_systeme » de la table
 * reglage (pas une tuile de Paramètres) : pour chaque sorte, e-mail oui ou
 * non, application (cloche et téléphone) oui ou non, et qui la reçoit ;
 * délai entre deux e-mails d'une même sorte ; seuil d'espace disque ;
 * adresses en plus.
 *
 * Chaque installation (ERP local, site en ligne) a les siens : les
 * personnes ne sont pas les mêmes des deux côtés, rien ne descend.
 */
final class ReglagesAlertes
{
    public const CLE = 'alertes_systeme';

    /** État des envois (dernier e-mail par sorte) : une autre ligne, que l'écran ne montre pas. */
    public const CLE_ENVOIS = 'alertes_systeme_envois';

    public const DELAI_DEFAUT = 15;
    public const DELAI_MIN = 1;
    public const DELAI_MAX = 1440;
    public const SEUIL_DISQUE_DEFAUT = 2.0;

    /** Adresses en plus : 10 au plus. */
    public const AUTRES_MAX = 10;

    /** @var array{delai: int, seuil_disque: float, autres: list<string>, types: array<string, array{email: bool, app: bool, destinataires: list<int>}>}|null */
    private ?array $valeurs = null;

    public function __construct(
        private readonly ReglageRepository $reglages,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    /** @return array{delai: int, seuil_disque: float, autres: list<string>, types: array<string, array{email: bool, app: bool, destinataires: list<int>}>} */
    public function valeurs(): array
    {
        return $this->valeurs ??= self::lire($this->reglages->findOneBy(['cle' => self::CLE])?->getValeurs() ?? [], $this->enLigne);
    }

    public function delaiMinutes(): int
    {
        return $this->valeurs()['delai'];
    }

    public function seuilDisqueGo(): float
    {
        return $this->valeurs()['seuil_disque'];
    }

    /** @return array{email: bool, app: bool, destinataires: list<int>} */
    public function pourType(string $type): array
    {
        return $this->valeurs()['types'][$type] ?? ['email' => true, 'app' => true, 'destinataires' => []];
    }

    /** @return list<string> */
    public function autresEmails(): array
    {
        return $this->valeurs()['autres'];
    }

    /**
     * Enregistre la saisie de l'écran. N'appelle pas flush().
     *
     * @param array<string, mixed> $saisie
     */
    public function enregistrer(array $saisie, ?User $par, \DateTimeImmutable $le): void
    {
        $valeurs = self::lire($saisie, $this->enLigne);
        $reglage = $this->reglages->findOneBy(['cle' => self::CLE]);
        if (null === $reglage) {
            $reglage = new Reglage(self::CLE);
            $this->em->persist($reglage);
        }
        $reglage->modifier($valeurs, $par, $le);
        $this->valeurs = $valeurs;
    }

    /**
     * Valeurs nettoyées : délai et seuil dans leurs bornes, adresses
     * valides, cases cochées. Jamais réglé : tout part par e-mail et dans
     * l'application, sauf la synchronisation au local, que la cloche des
     * administrateurs montre déjà (AgentSynchro) : e-mail seulement.
     *
     * @param array<string, mixed> $saisie
     *
     * @return array{delai: int, seuil_disque: float, autres: list<string>, types: array<string, array{email: bool, app: bool, destinataires: list<int>}>}
     */
    public static function lire(array $saisie, bool $enLigne = false): array
    {
        $delai = is_numeric($saisie['delai'] ?? null) ? (int) $saisie['delai'] : self::DELAI_DEFAUT;
        $seuil = is_numeric(str_replace(',', '.', (string) ($saisie['seuil_disque'] ?? ''))) ? (float) str_replace(',', '.', (string) $saisie['seuil_disque']) : self::SEUIL_DISQUE_DEFAUT;

        $autres = $saisie['autres'] ?? [];
        if (\is_string($autres)) {
            $autres = preg_split('/[\s,;]+/', $autres) ?: [];
        }
        $adresses = [];
        foreach (\is_array($autres) ? $autres : [] as $adresse) {
            $adresse = mb_strtolower(trim(\is_scalar($adresse) ? (string) $adresse : ''));
            if ('' !== $adresse && false !== filter_var($adresse, \FILTER_VALIDATE_EMAIL) && !\in_array($adresse, $adresses, true)) {
                $adresses[] = $adresse;
            }
        }

        $types = [];
        $saisieTypes = \is_array($saisie['types'] ?? null) ? $saisie['types'] : [];
        // Une case décochée n'est pas envoyée par le navigateur : le formulaire envoie « present ».
        $formulaire = isset($saisie['formulaire']);
        foreach (array_keys(AlerteSysteme::TYPES) as $type) {
            $t = \is_array($saisieTypes[$type] ?? null) ? $saisieTypes[$type] : [];
            $destinataires = array_values(array_unique(array_filter(array_map('intval', \is_array($t['destinataires'] ?? null) ? $t['destinataires'] : []), static fn (int $id): bool => $id > 0)));
            $types[$type] = [
                'email' => $formulaire || \array_key_exists('email', $t) ? (bool) ($t['email'] ?? false) : true,
                'app' => $formulaire || \array_key_exists('app', $t) ? (bool) ($t['app'] ?? false) : ($enLigne || AlerteSysteme::SYNCHRO !== $type),
                'destinataires' => $destinataires,
            ];
        }

        return [
            'delai' => max(self::DELAI_MIN, min(self::DELAI_MAX, $delai)),
            'seuil_disque' => round(max(0.1, min(1000.0, $seuil)), 1),
            'autres' => \array_slice($adresses, 0, self::AUTRES_MAX),
            'types' => $types,
        ];
    }

    /** @return array<string, string> sorte => date du dernier envoi (ATOM) */
    public function envois(): array
    {
        $valeurs = $this->reglages->findOneBy(['cle' => self::CLE_ENVOIS])?->getValeurs() ?? [];

        return array_filter($valeurs, 'is_string');
    }

    /** Note l'heure du dernier envoi d'une sorte. N'appelle pas flush(). */
    public function noterEnvoi(string $type, \DateTimeImmutable $le): void
    {
        $reglage = $this->reglages->findOneBy(['cle' => self::CLE_ENVOIS]);
        if (null === $reglage) {
            $reglage = new Reglage(self::CLE_ENVOIS);
            $this->em->persist($reglage);
        }
        $reglage->modifier([...$reglage->getValeurs(), $type => $le->format(\DATE_ATOM)], null, $le);
    }

    /**
     * La sorte peut-elle être envoyée maintenant ? Pas d'envoi pendant le
     * délai qui suit le précédent de la même sorte.
     */
    public static function peutEnvoyer(?string $dernier, int $delaiMinutes, \DateTimeImmutable $maintenant): bool
    {
        if (null === $dernier) {
            return true;
        }
        try {
            $le = new \DateTimeImmutable($dernier);
        } catch (\Exception) {
            return true;
        }

        return $maintenant->getTimestamp() - $le->getTimestamp() >= $delaiMinutes * 60;
    }
}
