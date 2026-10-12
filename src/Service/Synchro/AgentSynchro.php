<?php

namespace App\Service\Synchro;

use App\Entity\SynchroEchange;
use App\Service\Sauvegarde\HorsSite;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * L'agent de l'ERP local (maquette 39). La boutique n'a pas d'adresse fixe :
 * c'est toujours lui qui appelle le site, chaque minute.
 *
 * Un cycle : vérifier que le site répond, envoyer la file du local, puis
 * recevoir ce qui a été créé en ligne et l'accuser. Une coupure ne perd
 * rien : ce qui n'est pas accusé repart au cycle suivant, et chaque
 * élément n'est appliqué qu'une fois de l'autre côté.
 */
final class AgentSynchro
{
    public const ETAT_REUSSITE = 'derniere_reussite';
    public const ETAT_CYCLE = 'dernier_cycle';
    public const ETAT_MISE_EN_ROUTE = 'mise_en_route';
    public const ETAT_CURSEUR = 'curseur_reception';
    public const ETAT_LATENCE = 'latence_ms';
    public const ETAT_ERREUR = 'derniere_erreur';
    public const ETAT_ALERTE = 'alerte_envoyee';
    public const ETAT_PURGE = 'derniere_purge';
    public const ETAT_SCHEMA = 'schema_site';
    public const ETAT_CONTACT = 'dernier_contact_agent';

    /** Données déjà notées pendant ce cycle : une fiche modifiée trois fois n'a qu'une ligne. */
    private array $notees = [];

    public function __construct(
        private readonly Synchro $synchro,
        private readonly HttpClientInterface $http,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
        private readonly FichiersSynchro $fichiers,
        private readonly HorsSite $horsSite,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{statut: string, envoyes: int, recus: int, conflits: int, message: ?string}
     */
    public function cycle(?\DateTimeImmutable $maintenant = null): array
    {
        $maintenant ??= new \DateTimeImmutable();
        if ($this->synchro->estEnLigne()) {
            throw new \LogicException('L\'agent tourne sur l\'ERP local, pas sur le site.');
        }
        if (!$this->synchro->estConfiguree()) {
            return ['statut' => 'non_configuree', 'envoyes' => 0, 'recus' => 0, 'conflits' => 0, 'message' => 'SYNC_URL et SYNC_CLE à régler dans .env.local'];
        }

        @mkdir($this->projectDir.'/var', 0775, true);
        $verrou = fopen($this->projectDir.'/var/synchro.verrou', 'c');
        if ($verrou === false || !flock($verrou, \LOCK_EX | \LOCK_NB)) {
            return ['statut' => 'occupe', 'envoyes' => 0, 'recus' => 0, 'conflits' => 0, 'message' => 'Un cycle est déjà en cours.'];
        }

        $resume = ['statut' => 'ok', 'envoyes' => 0, 'recus' => 0, 'conflits' => 0, 'fichiers' => 0, 'message' => null];
        $this->notees = [];
        try {
            if ($this->synchro->etat(self::ETAT_MISE_EN_ROUTE) === null) {
                $this->synchro->noterEtat(self::ETAT_MISE_EN_ROUTE, $maintenant->format(\DATE_ATOM));
            }
            $this->synchro->noterEtat(self::ETAT_CYCLE, $maintenant->format(\DATE_ATOM));

            $debut = microtime(true);
            $ping = $this->appeler('/api/synchro/ping', []);
            $this->synchro->noterEtat(self::ETAT_LATENCE, (string) (int) round((microtime(true) - $debut) * 1000));
            $this->synchro->noterEtat(self::ETAT_SCHEMA, isset($ping['schema']) ? (string) $ping['schema'] : null);

            $this->envoyer($resume);
            if ($resume['statut'] === 'ok') {
                $this->recevoir($resume);
            }
            if ($resume['statut'] === 'ok') {
                // Après les fiches : le site doit déjà connaître le fichier.
                $bilan = $this->fichiers->envoyer(fn (string $chemin, array $donnees): array => $this->appeler($chemin, $donnees));
                $resume['fichiers'] = $bilan['envoyes'];
                if ($bilan['envoyes'] > 0) {
                    $this->synchro->noterEchange(SynchroEchange::VERS_EN_LIGNE, 'fichier', \sprintf('%d fichier%s de BAT (%s Mo)', $bilan['envoyes'], $bilan['envoyes'] > 1 ? 's' : '', number_format($bilan['octets'] / 1048576, 1, ',', ' ')), SynchroEchange::OK);
                }
                // Les fichiers envoyés par les clients en ligne arrivent à l'atelier.
                $recus = $this->fichiers->recuperer(fn (string $chemin, array $donnees): array => $this->appeler($chemin, $donnees), max(0, FichiersSynchro::BUDGET_CYCLE - $bilan['octets']));
                if ($recus['recus'] > 0) {
                    $this->synchro->noterEchange(SynchroEchange::VERS_LOCAL, 'fichier', \sprintf('%d fichier%s de client (%s Mo)', $recus['recus'], $recus['recus'] > 1 ? 's' : '', number_format($recus['octets'] / 1048576, 1, ',', ' ')), SynchroEchange::OK);
                }
                // Puis la copie chiffrée des sauvegardes, avec ce qui reste du budget du cycle.
                foreach ($this->horsSite->envoyer(fn (string $chemin, array $donnees): array => $this->appeler($chemin, $donnees), max(0, FichiersSynchro::BUDGET_CYCLE - $bilan['octets'] - $recus['octets']), $maintenant) as $copie) {
                    $this->synchro->noterEchange(SynchroEchange::VERS_EN_LIGNE, null, \sprintf('Sauvegarde %s chiffrée, %s (%s Mo)', $copie['nom'], $copie['partie'] === 'base' ? 'base' : 'fichiers', number_format($copie['octets'] / 1048576, 1, ',', ' ')), SynchroEchange::OK);
                }
            }
        } catch (\Throwable $e) {
            $resume['statut'] = 'erreur';
            $resume['message'] = self::messageLiaison($e);
            $this->noterIncident(SynchroEchange::SYSTEME, null, 'Liaison avec le site', SynchroEchange::ERREUR, $resume['message']);
        } finally {
            flock($verrou, \LOCK_UN);
            fclose($verrou);
        }

        if ($resume['statut'] === 'ok') {
            $this->synchro->noterEtat(self::ETAT_REUSSITE, $maintenant->format(\DATE_ATOM));
            $this->synchro->noterEtat(self::ETAT_ERREUR, null);
            $this->synchro->resoudreErreurs();
            if ($this->synchro->etat(self::ETAT_ALERTE) !== null) {
                $this->synchro->noterEtat(self::ETAT_ALERTE, null);
                $this->synchro->noterEchange(SynchroEchange::SYSTEME, null, 'Liaison rétablie', SynchroEchange::OK);
            }
        } else {
            $this->synchro->noterEtat(self::ETAT_ERREUR, $resume['message']);
            $this->alerterSiBesoin($maintenant, $resume['message']);
        }

        $purge = $this->synchro->dateEtat(self::ETAT_PURGE);
        if ($purge === null || $purge < $maintenant->modify('-1 day')) {
            $this->synchro->purger($maintenant);
            $this->synchro->noterEtat(self::ETAT_PURGE, $maintenant->format(\DATE_ATOM));
        }

        return $resume;
    }

    /** @param array<string, mixed> $resume */
    private function envoyer(array &$resume): void
    {
        $apres = 0;
        for ($tour = 0; $tour < 50; ++$tour) {
            $lot = $this->synchro->lot($apres);
            if ($lot === []) {
                return;
            }
            $reponse = $this->appeler('/api/synchro/envoyer', ['evenements' => $lot]);
            $faits = [];
            foreach ($reponse['resultats'] ?? [] as $r) {
                $faits[] = (int) $r['id'];
                $this->noterResultat(SynchroEchange::VERS_EN_LIGNE, $r, $resume);
            }
            $this->synchro->enregistrerEchanges();
            $this->synchro->marquerEnvoyes($faits);
            $resume['envoyes'] += \count($faits);

            if (($erreur = $reponse['erreur'] ?? null) !== null) {
                $this->synchro->noterEchecEnvoi((int) $erreur['id'], (string) $erreur['message']);
                $this->noterIncident(SynchroEchange::VERS_EN_LIGNE, $erreur['type'] ?? null, (string) ($erreur['titre'] ?? ''), $erreur['reprise'] ? SynchroEchange::REPRISE : SynchroEchange::ERREUR, (string) $erreur['message']);
                if (!$erreur['reprise']) {
                    $resume['statut'] = 'erreur';
                    $resume['message'] = (string) $erreur['message'];
                }

                return; // l'ordre compte : la suite attend le prochain cycle
            }
            $apres = (int) end($lot)['id'];
        }
    }

    /** @param array<string, mixed> $resume */
    private function recevoir(array &$resume): void
    {
        for ($tour = 0; $tour < 50; ++$tour) {
            $curseur = (int) ($this->synchro->etat(self::ETAT_CURSEUR) ?? 0);
            $reponse = $this->appeler('/api/synchro/evenements', ['apres' => $curseur, 'max' => Synchro::LOT]);
            $evenements = $reponse['evenements'] ?? [];
            if ($evenements === []) {
                return;
            }
            $application = $this->synchro->appliquer($evenements);
            $dernier = $curseur;
            foreach ($application['resultats'] as $r) {
                $dernier = max($dernier, $r['id']);
                $this->noterResultat(SynchroEchange::VERS_LOCAL, $r, $resume);
                ++$resume['recus'];
            }
            $this->synchro->enregistrerEchanges();
            if ($dernier > $curseur) {
                $this->appeler('/api/synchro/accuser', ['jusqua' => $dernier]);
                $this->synchro->noterEtat(self::ETAT_CURSEUR, (string) $dernier);
            }
            if (($erreur = $application['erreur']) !== null) {
                $this->noterIncident(SynchroEchange::VERS_LOCAL, $erreur['type'], $erreur['titre'], $erreur['reprise'] ? SynchroEchange::REPRISE : SynchroEchange::ERREUR, $erreur['message']);
                if (!$erreur['reprise']) {
                    $resume['statut'] = 'erreur';
                    $resume['message'] = $erreur['message'];
                }

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $resume
     */
    private function noterResultat(string $sens, array $r, array &$resume): void
    {
        $statut = match ($r['statut']) {
            Synchro::RESULTAT_CONFLIT => SynchroEchange::CONFLIT,
            Synchro::RESULTAT_DEJA => null, // déjà appliqué : rejeu sans effet, rien à montrer
            default => SynchroEchange::OK,
        };
        if ($statut === SynchroEchange::CONFLIT) {
            ++$resume['conflits'];
        }
        $cle = $sens.($r['type'] ?? '').($r['publicId'] ?? '').$statut;
        if ($statut !== null && !isset($this->notees[$cle])) {
            $this->notees[$cle] = true;
            $this->synchro->noterEchange($sens, $r['type'] ?? null, (string) ($r['titre'] ?? ''), $statut, $r['message'] ?? null, false);
        }
    }

    /** Un même incident répété chaque minute n'est noté qu'une fois. */
    private function noterIncident(string $sens, ?string $type, string $libelle, string $statut, string $message): void
    {
        $dernier = $this->synchro->echanges(1)[0] ?? null;
        if ($dernier !== null && !$dernier->isResolu() && $dernier->getMessage() === $message && $dernier->getLibelle() === mb_substr($libelle, 0, 255)) {
            return;
        }
        $this->synchro->noterEchange($sens, $type, $libelle, $statut, $message);
    }

    private function alerterSiBesoin(\DateTimeImmutable $maintenant, ?string $message): void
    {
        $alerte = Synchro::alerte(true, $this->synchro->dateEtat(self::ETAT_REUSSITE), $this->synchro->dateEtat(self::ETAT_MISE_EN_ROUTE), $maintenant, $message);
        if ($alerte === null || $this->synchro->etat(self::ETAT_ALERTE) !== null) {
            return;
        }
        // Une notification par panne (avec l'envoi sur le téléphone des administrateurs).
        $this->notifications->notifierRoles(['ROLE_ADMIN'], 'Synchronisation : '.$alerte, 'app_synchronisation_index');
        $this->em->flush();
        $this->synchro->noterEtat(self::ETAT_ALERTE, $maintenant->format(\DATE_ATOM));
    }

    /**
     * Appel signé au site ; la réponse doit être signée aussi.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private function appeler(string $chemin, array $donnees): array
    {
        $corps = json_encode($donnees, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $heure = time();
        $reponse = $this->http->request('POST', $this->synchro->url().$chemin, [
            'headers' => [
                'Content-Type' => 'application/json',
                Signature::ENTETE_HEURE => (string) $heure,
                Signature::ENTETE => Signature::calculer($this->synchro->cle(), 'POST', $chemin, $heure, $corps),
            ],
            'body' => $corps,
            'timeout' => 30,
            'max_duration' => 120,
        ]);
        $code = $reponse->getStatusCode();
        $contenu = $reponse->getContent(false);
        if ($code !== 200) {
            throw new \RuntimeException(match ($code) {
                401 => 'Le site refuse la signature : la clé SYNC_CLE n\'est pas la même des deux côtés, ou l\'heure d\'une des machines est fausse.',
                404 => 'Le site ne répond pas à la synchronisation (adresse fausse, ou SYNC_CLE absente sur le site).',
                default => 'Le site a répondu '.$code.'.',
            });
        }
        $entetes = $reponse->getHeaders(false);
        if (!Signature::verifier($this->synchro->cle(), 'REPONSE', $chemin, (string) ($entetes[strtolower(Signature::ENTETE_HEURE)][0] ?? ''), $contenu, (string) ($entetes[strtolower(Signature::ENTETE)][0] ?? ''), time())) {
            throw new \RuntimeException('Réponse du site non signée ou modifiée : elle est ignorée.');
        }

        return json_decode($contenu, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function messageLiaison(\Throwable $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return 'Site injoignable (connexion internet coupée ou site arrêté).';
        }

        return mb_substr($message, 0, 500);
    }
}
