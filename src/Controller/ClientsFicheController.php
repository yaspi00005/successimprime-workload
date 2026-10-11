<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\ModificationClient;
use App\Entity\NoteClient;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\EntrepriseRepository;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\Clients\EditionClient;
use App\Service\Clients\ListeClients;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients : nouvelle fiche et modification (K02, ajout terrain K05),
 * journal des modifications (K04) et export de la liste (K01).
 * L'accès à /clients demande déjà ROLE_CLIENT.
 */
#[Route('/clients')]
final class ClientsFicheController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditionClient $edition,
        private readonly EntrepriseRepository $entreprises,
        private readonly ActionsCaisse $actions,
    ) {
    }

    #[Route('/nouveau', name: 'app_clients_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $client = new Clients();
        $terrain = 'terrain' === $request->query->get('mode');
        $prospect = (int) $request->query->get('prospect') > 0 ? $this->em->find(Prospect::class, (int) $request->query->get('prospect')) : null;
        if (null !== $prospect && null !== $prospect->getClient()) {
            return $this->redirectToRoute('app_clients_show', ['publicId' => $prospect->getClient()->getPublicId()]);
        }

        if ($request->isMethod('POST')) {
            return $this->enregistrer($request, $client, $prospect, $terrain);
        }

        $valeurs = ['type' => Clients::TYPE_COMPTE_ENTREPRISE, 'grille' => 'B2C', 'plafond' => 100000, 'suivi' => $this->getUser() instanceof User ? $this->getUser()->getId() : null, 'contacts' => [], 'marketing' => false];
        if (null !== $prospect) {
            $valeurs = array_merge($valeurs, [
                'raisonSociale' => $prospect->getNom(),
                'contacts' => [['id' => 0, 'nom' => '', 'fonction' => null, 'telephone' => $prospect->getTelephone(), 'whatsapp' => true, 'role' => 'principal']],
                'origine' => $prospect->getSource(),
                'suivi' => $prospect->getAgent()?->getId() ?? $valeurs['suivi'],
                'besoin' => $prospect->getBesoin(),
                'observation' => $prospect->getBesoin(),
            ]);
        } elseif ('' !== trim((string) $request->query->get('nom'))) {
            $valeurs['raisonSociale'] = trim((string) $request->query->get('nom'));
        }

        return $this->formulaire($client, $valeurs, [], $prospect, $terrain);
    }

    #[Route('/fiche/{publicId}/modifier', name: 'app_clients_modifier', methods: ['GET', 'POST'])]
    public function modifier(Request $request, string $publicId): Response
    {
        $client = $this->client($publicId);
        if ($request->isMethod('POST')) {
            return $this->enregistrer($request, $client, null, false);
        }

        return $this->formulaire($client, $this->edition->valeurs($client), [], null, false);
    }

    /** Doublon possible pendant la saisie du téléphone (bandeau K02). */
    #[Route('/doublons', name: 'app_clients_doublons', methods: ['GET'])]
    public function doublons(Request $request): JsonResponse
    {
        $sauf = '' !== (string) $request->query->get('sauf') ? $this->em->getRepository(Clients::class)->findOneBy(['publicId' => $request->query->get('sauf')]) : null;
        $liste = [];
        foreach ($this->edition->doublons((string) $request->query->get('tel'), $sauf) as $d) {
            $liste[] = $d + [
                'fiche' => null !== $d['publicId'] ? $this->generateUrl('app_clients_show', ['publicId' => $d['publicId']]) : null,
                'transformer' => null !== $d['prospectId'] ? $this->generateUrl('app_clients_nouveau', ['prospect' => $d['prospectId']]) : null,
                'prospect' => null !== $d['prospectId'] ? $this->generateUrl('app_prospects_index') : null,
            ];
        }

        return $this->json($liste);
    }

    /** Note ou compte rendu d'appel depuis l'historique (K03), avec rappel éventuel. */
    #[Route('/fiche/{publicId}/note', name: 'app_clients_note', methods: ['POST'])]
    public function note(Request $request, string $publicId): Response
    {
        $client = $this->client($publicId);
        if (!$this->isCsrfTokenValid('note-client', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Formulaire expiré, rechargez la page.');
        }
        $texte = trim((string) $request->request->get('texte'));
        $utilisateur = $this->getUser() instanceof User ? $this->getUser() : null;
        if ('' === $texte || null === $utilisateur) {
            $this->addFlash('warning', 'Écrivez la note avant de l’ajouter.');

            return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()]);
        }
        $maintenant = new \DateTimeImmutable();
        $note = new NoteClient($client, $texte, (string) $request->request->get('type', 'note'), $utilisateur, $maintenant);
        $rappel = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('rappel'));
        if (false !== $rappel && $rappel >= $maintenant->setTime(0, 0)) {
            $note->setRappelLe($rappel);
        }
        $this->em->persist($note);
        $this->em->flush();
        if (null !== $note->getRappelLe()) {
            // Le rappel arrive dans les actions de la personne, à la date choisie.
            $depart = max($note->getRappelLe()->setTime(8, 0), $maintenant);
            $this->actions->nouvelle('manuelle', 'note:'.$note->getId(), 'Rappeler '.$client->getNomComplet(), $depart, $maintenant, $utilisateur)
                ->lier($client)->setCreePar($utilisateur)->setDetail(mb_substr($texte, 0, 255));
            $this->em->flush();
        }
        $this->addFlash('success', null !== $note->getRappelLe() ? 'Note ajoutée, rappel le '.$note->getRappelLe()->format('d/m').'.' : 'Note ajoutée.');

        return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId(), '_fragment' => 'historique']);
    }

    #[Route('/fiche/{publicId}/journal', name: 'app_clients_journal', methods: ['GET'])]
    public function journal(Request $request, string $publicId): Response
    {
        $client = $this->client($publicId);
        $filtre = (string) $request->query->get('filtre', '');
        $qb = $this->em->getRepository(ModificationClient::class)->createQueryBuilder('m')
            ->leftJoin('m.par', 'u')->addSelect('u')
            ->where('m.client = :c')->setParameter('c', $client)
            ->orderBy('m.le', 'DESC')->addOrderBy('m.id', 'DESC');
        if (\in_array($filtre, ['conditions', 'contacts'], true)) {
            $qb->andWhere('m.categorie = :cat')->setParameter('cat', $filtre);
        } elseif ('en_ligne' === $filtre) {
            $qb->andWhere('m.depuis IN (:d)')->setParameter('d', ['admin_en_ligne', 'espace_client']);
        } else {
            $filtre = '';
        }
        $lignes = $qb->getQuery()->getResult();

        if ('csv' === $request->query->get('format')) {
            return $this->csv(
                'journal-'.$client->getCode().'.csv',
                ['Date', 'Par', 'Champ', 'Avant', 'Après', 'Depuis'],
                array_map(fn (ModificationClient $m) => [$m->getLe()->format('d/m/Y H:i'), $m->getAuteur() ?? 'Système', $m->getChamp(), $m->getAvant(), $m->getApres(), $m->getDepuisLabel()], $lignes),
            );
        }

        return $this->render('clients/journal.html.twig', ['client' => $client, 'lignes' => $lignes, 'filtre' => $filtre]);
    }

    /** Liste filtrée en CSV (bouton Export de K01), réservée à l'administrateur. */
    #[Route('/export', name: 'app_clients_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request, ListeClients $liste): Response
    {
        $filtres = [
            'q' => trim((string) $request->query->get('q', '')),
            'type' => (string) $request->query->get('type', ''),
            'statut' => (string) $request->query->get('statut', ''),
            'suivi' => (int) $request->query->get('suivi', 0),
            'tri' => (string) $request->query->get('tri', 'nom'),
            'page' => 1,
            'tout' => true,
        ];
        $voirCa = $this->isGranted('ROLE_STATS_VENTES');
        $voirSoldes = $this->isGranted('ROLE_PAIEMENT_VOIR');
        $donnees = $liste->donnees($filtres, new \DateTimeImmutable(), $voirCa, $voirSoldes);
        $entetes = ['Code', 'Client', 'Type', 'Téléphone', 'Contact principal', 'Dernière commande', 'Statut', 'Suivi par'];
        if ($voirCa) {
            $entetes[] = 'CA 12 mois';
        }
        if ($voirSoldes) {
            $entetes[] = 'Solde dû';
            $entetes[] = 'Dont échu';
        }
        $lignes = [];
        foreach ($donnees['lignes'] as $l) {
            $c = $l['client'];
            $ligne = [$c->getCode(), $c->getNomComplet(), $c->getTypeCompteLabel(), $c->getTelephone(), $l['contact']?->getNom(), $l['derniere']?->format('d/m/Y'), ListeClients::STATUTS[$l['statut']], null !== $l['suivi'] ? \App\Service\Pointage\Pointages::nom($l['suivi']) : null];
            if ($voirCa) {
                $ligne[] = $l['ca'];
            }
            if ($voirSoldes) {
                $ligne[] = $l['du'];
                $ligne[] = $l['echu'];
            }
            $lignes[] = $ligne;
        }

        return $this->csv('clients-'.date('Y-m-d').'.csv', $entetes, $lignes);
    }

    private function enregistrer(Request $request, Clients $client, ?Prospect $prospect, bool $terrain): Response
    {
        if (!$this->isCsrfTokenValid('client', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Formulaire expiré, rechargez la page.');
        }
        $creation = null === $client->getId();
        $d = $brut = $request->request->all('c');
        if ($terrain) {
            $d = self::depuisTerrain($d);
        }
        $utilisateur = $this->getUser() instanceof User ? $this->getUser() : null;
        $maintenant = new \DateTimeImmutable();
        $resultat = $this->edition->appliquer($client, $d, $utilisateur, $this->isGranted('ROLE_ADMIN'), $maintenant);

        if ([] === $resultat['erreurs']) {
            try {
                $this->em->flush();
                if ($creation) {
                    $this->edition->terminerCreation($client, $prospect, $maintenant);
                    $this->em->flush();
                    if ($terrain && null !== $utilisateur) {
                        // Prospection du jour : ligne faite, points pour le commercial.
                        $ligne = $this->actions->nouvelle('prospect', 'terrain:'.$client->getId(), 'Prospection terrain · '.$client->getNomComplet(), $maintenant, $maintenant, $utilisateur);
                        $ligne->lier($client)->setCreePar($utilisateur)->setDetail($client->getBesoin());
                        $this->actions->faire($ligne, 'fait', 'Nouveau client ajouté sur le terrain', $utilisateur, $maintenant);
                    }
                }
            } catch (UniqueConstraintViolationException) {
                $resultat['erreurs']['telephone'] = 'Ce numéro est déjà utilisé par un autre client.';
            }
        }
        if ([] !== $resultat['erreurs']) {
            if ($terrain) {
                return $this->formulaire($client, $brut, $resultat['erreurs'], $prospect, true, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $d['contacts'] = array_values((array) ($d['contacts'] ?? []));

            return $this->formulaire($client, $d + ($creation ? [] : $this->edition->valeurs($client)), $resultat['erreurs'], $prospect, $terrain, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', $creation ? sprintf('Client %s ajouté.', $client->getNomComplet()) : 'Fiche enregistrée.');
        if ([] !== $resultat['demandes']) {
            $this->addFlash('warning', 'Envoyé à la direction pour validation : '.implode(', ', $resultat['demandes']).'.');
        }
        if ('devis' === $request->request->get('apres') && $this->isGranted('ROLE_DEVIS')) {
            return $this->redirectToRoute('app_devis_new', ['client' => $client->getId()]);
        }

        return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()] + ($request->request->getBoolean('inviter') ? ['inviter' => 1] : []));
    }

    /**
     * Ajout terrain (K05) : nom, contact « Nom · fonction », un téléphone.
     *
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed>
     */
    public static function depuisTerrain(array $t): array
    {
        $type = (string) ($t['type'] ?? Clients::TYPE_COMPTE_ENTREPRISE);
        $nom = trim((string) ($t['nomTerrain'] ?? ''));
        $d = [
            'type' => $type,
            'telephone' => (string) ($t['telephone'] ?? ''),
            'besoin' => $t['besoin'] ?? null,
            'latitude' => $t['latitude'] ?? null,
            'longitude' => $t['longitude'] ?? null,
            'suivi' => $t['suivi'] ?? null,
        ];
        if (Clients::TYPE_COMPTE_PARTICULIER === $type) {
            return $d + ['nomComplet' => $nom];
        }
        $parts = array_map('trim', preg_split('/[·,]/u', (string) ($t['contactTerrain'] ?? ''), 2) ?: []);

        return $d + [
            'raisonSociale' => $nom,
            'contacts' => [[
                'id' => 0, 'nom' => '' !== ($parts[0] ?? '') ? $parts[0] : $nom, 'fonction' => $parts[1] ?? null,
                'telephone' => (string) ($t['telephone'] ?? ''), 'whatsapp' => !empty($t['whatsapp']), 'role' => 'principal',
            ]],
        ];
    }

    /**
     * @param array<string, mixed>  $valeurs
     * @param array<string, string> $erreurs
     */
    private function formulaire(Clients $client, array $valeurs, array $erreurs, ?Prospect $prospect, bool $terrain, int $statut = Response::HTTP_OK): Response
    {
        $utilisateurs = array_filter(
            $this->em->getRepository(User::class)->findBy([], ['username' => 'ASC']),
            fn (User $u) => $u->isActif(),
        );

        return $this->render($terrain ? 'clients/terrain.html.twig' : 'clients/formulaire.html.twig', [
            'client' => $client,
            'v' => $valeurs,
            'erreurs' => $erreurs,
            'prospect' => $prospect,
            'entreprises' => $this->entreprises->findBy([], ['id' => 'ASC']),
            'utilisateurs' => array_values($utilisateurs),
            'direction' => $this->isGranted('ROLE_ADMIN'),
        ], new Response(null, $statut));
    }

    private function client(string $publicId): Clients
    {
        $client = preg_match('/^[0-9a-f-]{36}$/', $publicId) ? $this->em->getRepository(Clients::class)->findOneBy(['publicId' => $publicId]) : null;
        if (!$client instanceof Clients) {
            throw $this->createNotFoundException('Client introuvable.');
        }

        return $client;
    }

    /**
     * @param list<string>             $entetes
     * @param list<list<scalar|null>> $lignes
     */
    private function csv(string $nom, array $entetes, array $lignes): StreamedResponse
    {
        $reponse = new StreamedResponse(function () use ($entetes, $lignes): void {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $entetes, ';', '"', '');
            foreach ($lignes as $l) {
                fputcsv($f, $l, ';', '"', '');
            }
            fclose($f);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="'.$nom.'"');

        return $reponse;
    }
}
