<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Security\AccesEnLigne;
use App\Service\Assistant\AnalyseAssistant;
use App\Service\Assistant\ReglagesAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Assistant du site (docs/assistant-client.md) : conversations
 * des visiteurs et des clients avec l'assistant, et leur analyse.
 *
 * Ce sont des données personnelles : seulement avec le droit « Assistant
 * du site », et seulement sur l'ERP de la boutique, où elles arrivent par
 * la synchronisation. En ligne, rien n'est listé.
 */
#[Route('/assistant-ia', name: 'app_assistant_')]
#[IsGranted('ROLE_ASSISTANT')]
final class AssistantAdminController extends AbstractController
{
    public const PERIODES = [7 => '7 jours', 30 => '30 jours', 90 => '3 mois'];

    public function __construct(
        private readonly AssistantConversationRepository $conversations,
        private readonly AssistantMessageRepository $messages,
        private readonly ReglagesAssistant $reglages,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'conversations', methods: ['GET'])]
    public function conversations(Request $request): Response
    {
        $periode = self::periode($request);
        $contexte = (string) $request->query->get('contexte', '');
        $filtre = (string) $request->query->get('filtre', '');
        $recherche = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $filtres = [
            'periode' => $periode,
            'contexte' => isset(AssistantConversation::CONTEXTES[$contexte]) ? $contexte : '',
            'filtre' => isset(AssistantConversationRepository::FILTRES[$filtre]) ? $filtre : '',
            'q' => $recherche,
        ];
        if ($this->acces->estEnLigne()) {
            return $this->render('assistant/conversations.html.twig', ['enLigne' => true, 'lignes' => [], 'filtres' => $filtres] + $this->choix());
        }

        $depuis = (new \DateTimeImmutable('today'))->modify('-'.($periode - 1).' days');
        $liste = $this->conversations->liste($depuis, $filtres['contexte'], $filtres['filtre'], $recherche);
        $parConversation = [];
        foreach ($this->messages->depuis($depuis, array_values(array_filter(array_map(static fn (AssistantConversation $c): ?int => $c->getId(), $liste)))) as $m) {
            $parConversation[$m->getConversation()?->getId()][] = $m;
        }
        $lignes = array_map(static fn (AssistantConversation $c): array => self::ligne($c, $parConversation[$c->getId()] ?? []), $liste);

        return $this->render('assistant/conversations.html.twig', ['enLigne' => false, 'lignes' => $lignes, 'filtres' => $filtres] + $this->choix());
    }

    #[Route('/conversations/{id}', name: 'conversation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function conversation(int $id): Response
    {
        $conversation = $this->acces->estEnLigne() ? null : $this->conversations->find($id);
        if (null === $conversation) {
            throw $this->createNotFoundException();
        }

        return $this->render('assistant/conversation.html.twig', [
            'c' => $conversation,
            'messages' => $this->messages->deLaConversation($conversation),
        ]);
    }

    #[Route('/analyse', name: 'analyse', methods: ['GET'])]
    public function analyse(Request $request): Response
    {
        $periode = self::periode($request);
        if ($this->acces->estEnLigne()) {
            return $this->render('assistant/analyse.html.twig', ['enLigne' => true, 'periode' => $periode, 'periodes' => self::PERIODES]);
        }
        $depuis = (new \DateTimeImmutable('today'))->modify('-'.($periode - 1).' days');
        $conversations = $this->conversations->liste($depuis, '', '', '', 5000);
        $messages = $this->messages->depuis($depuis);

        return $this->render('assistant/analyse.html.twig', [
            'enLigne' => false,
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'chiffres' => AnalyseAssistant::chiffres($conversations, $messages),
            'frequentes' => AnalyseAssistant::questionsFrequentes($messages),
            'sansReponse' => AnalyseAssistant::sansReponse($messages),
            'sujets' => AnalyseAssistant::sujets($messages),
            'aujourdhui' => $this->messages->depenseDepuis(new \DateTimeImmutable('today')),
            'limite' => $this->reglages->limiteJour(),
            'actif' => $this->reglages->disponible(),
        ]);
    }

    /**
     * Ligne de la liste : première question, pouces, issues, dépense.
     *
     * @param list<AssistantMessage> $messages
     *
     * @return array<string, mixed>
     */
    private static function ligne(AssistantConversation $c, array $messages): array
    {
        $ligne = ['c' => $c, 'question' => null, 'utile' => 0, 'pasUtile' => 0, 'sansReponse' => 0, 'depense' => 0.0];
        foreach ($messages as $m) {
            if ($m->estDuClient()) {
                $ligne['question'] ??= $m->getTexte();
                continue;
            }
            $ligne['depense'] += $m->getDepenseFcfa();
            $ligne['utile'] += AssistantMessage::NOTE_UTILE === $m->getNote() ? 1 : 0;
            $ligne['pasUtile'] += AssistantMessage::NOTE_PAS_UTILE === $m->getNote() ? 1 : 0;
            $ligne['sansReponse'] += $m->estRepondue() ? 0 : 1;
        }

        return $ligne;
    }

    private static function periode(Request $request): int
    {
        $periode = $request->query->getInt('periode', 30);

        return isset(self::PERIODES[$periode]) ? $periode : 30;
    }

    /** @return array{periodes: array<int, string>, contextes: array<string, string>, choixFiltres: array<string, string>} */
    private function choix(): array
    {
        return ['periodes' => self::PERIODES, 'contextes' => AssistantConversation::CONTEXTES, 'choixFiltres' => AssistantConversationRepository::FILTRES];
    }
}
