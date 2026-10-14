<?php

namespace App\Controller\Api;

use App\Service\Sauvegarde\HorsSite;
use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\FichiersSynchro;
use App\Service\Synchro\Signature;
use App\Service\Synchro\Synchro;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le côté site de la synchronisation : seul l'agent de l'ERP local
 * l'appelle (docs/synchronisation.md).
 *
 * Aucune session ni compte : chaque requête est signée avec SYNC_CLE et
 * datée, et chaque réponse l'est aussi. Sur l'ERP local, ou sans clé,
 * ces adresses n'existent pas (404).
 */
#[Route('/api/synchro', name: 'api_synchro_', methods: ['POST'])]
final class SynchroApiController
{
    /** Taille maximale d'un envoi. */
    private const CORPS_MAX = 10 * 1024 * 1024;

    public function __construct(private readonly Synchro $synchro)
    {
    }

    #[Route('/ping', name: 'ping')]
    public function ping(Request $request, Connection $connexion): Response
    {
        return $this->traiter($request, function () use ($connexion): array {
            try {
                $schema = $connexion->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions');
            } catch (\Throwable) {
                $schema = null;
            }

            return ['ok' => true, 'heure' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'schema' => $schema ?: null, 'enAttente' => $this->synchro->enAttente()];
        });
    }

    #[Route('/envoyer', name: 'envoyer')]
    public function envoyer(Request $request): Response
    {
        return $this->traiter($request, function (array $donnees): array {
            $evenements = $donnees['evenements'] ?? null;
            if (!\is_array($evenements) || \count($evenements) > 500) {
                throw new \InvalidArgumentException('Lot invalide');
            }
            $resultat = $this->synchro->appliquer(array_values($evenements));
            if ($resultat['erreur'] !== null && !$resultat['erreur']['reprise']) {
                $this->synchro->noterEtat(AgentSynchro::ETAT_ERREUR, $resultat['erreur']['message']);
            }

            return $resultat;
        });
    }

    #[Route('/evenements', name: 'evenements')]
    public function evenements(Request $request): Response
    {
        return $this->traiter($request, fn (array $donnees): array => [
            'evenements' => $this->synchro->lot(max(0, (int) ($donnees['apres'] ?? 0)), min(500, max(1, (int) ($donnees['max'] ?? Synchro::LOT)))),
        ]);
    }

    /** Morceau d'un fichier de BAT (FichiersSynchro). */
    #[Route('/fichier', name: 'fichier')]
    public function fichier(Request $request, FichiersSynchro $fichiers): Response
    {
        return $this->traiter($request, fn (array $donnees): array => $fichiers->recevoir($donnees));
    }

    /** Morceau d'une copie chiffrée des sauvegardes du local (HorsSite), illisible ici. */
    /** Le local rapatrie un fichier envoyé par un client dans son espace. */
    #[Route('/fichier-client', name: 'fichier_client')]
    public function fichierClient(Request $request, FichiersSynchro $fichiers): Response
    {
        return $this->traiter($request, fn (array $donnees): array => $fichiers->lire($donnees));
    }

    #[Route('/sauvegarde', name: 'sauvegarde')]
    public function sauvegarde(Request $request, HorsSite $horsSite): Response
    {
        return $this->traiter($request, fn (array $donnees): array => $horsSite->recevoir($donnees));
    }

    #[Route('/accuser', name: 'accuser')]
    public function accuser(Request $request): Response
    {
        return $this->traiter($request, fn (array $donnees): array => [
            'marques' => $this->synchro->marquerEnvoyesJusqua(max(0, (int) ($donnees['jusqua'] ?? 0))),
        ]);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $action */
    private function traiter(Request $request, callable $action): Response
    {
        if (!$this->synchro->estEnLigne() || !$this->synchro->estConfiguree()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $corps = $request->getContent();
        if (\strlen($corps) > self::CORPS_MAX) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $chemin = $request->getPathInfo();
        $refus = Signature::motifRefus($this->synchro->cle(), $request->getMethod(), $chemin, (string) $request->headers->get(Signature::ENTETE_HEURE, ''), $corps, (string) $request->headers->get(Signature::ENTETE, ''), time());
        if ($refus !== null) {
            // Le motif et l'heure du site disent à l'agent quoi régler ; rien sur la clé ni sur les données.
            return new JsonResponse(['refus' => $refus, 'heure_site' => time()], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']);
        }
        $this->synchro->noterEtat(AgentSynchro::ETAT_CONTACT, (new \DateTimeImmutable())->format(\DATE_ATOM));

        try {
            $donnees = $corps === '' ? [] : json_decode($corps, true, 512, \JSON_THROW_ON_ERROR);
            $reponse = $action(\is_array($donnees) ? $donnees : []);
            $code = Response::HTTP_OK;
        } catch (\JsonException|\InvalidArgumentException $e) {
            $reponse = ['erreur' => $e->getMessage()];
            $code = Response::HTTP_BAD_REQUEST;
        }

        $json = json_encode($reponse, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $heure = time();

        return new JsonResponse($json, $code, [
            Signature::ENTETE_HEURE => (string) $heure,
            Signature::ENTETE => Signature::calculer($this->synchro->cle(), 'REPONSE', $chemin, $heure, $json),
            'Cache-Control' => 'no-store',
        ], true);
    }
}
