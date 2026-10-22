<?php

namespace App\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;

/**
 * Écran Analyse de l'assistant : questions les plus posées, questions
 * restées sans réponse (ou jugées pas utiles), sujets, chiffres. Pur :
 * travaille sur les messages déjà lus.
 */
final class AnalyseAssistant
{
    /**
     * @param list<AssistantConversation> $conversations
     * @param list<AssistantMessage>      $messages
     *
     * @return array{conversations: int, questions: int, agent: int, utile: int, pasUtile: int, sansReponse: int, depense: float, site: int, espace: int}
     */
    public static function chiffres(array $conversations, array $messages): array
    {
        $chiffres = ['conversations' => \count($conversations), 'questions' => 0, 'agent' => 0, 'utile' => 0, 'pasUtile' => 0, 'sansReponse' => 0, 'depense' => 0.0, 'site' => 0, 'espace' => 0];
        foreach ($conversations as $c) {
            ++$chiffres[$c->estEspace() ? 'espace' : 'site'];
            if (null !== $c->getPassageAgentLe()) {
                ++$chiffres['agent'];
            }
        }
        foreach ($messages as $m) {
            if ($m->estDuClient()) {
                ++$chiffres['questions'];
                continue;
            }
            $chiffres['depense'] += $m->getDepenseFcfa();
            if (AssistantMessage::NOTE_UTILE === $m->getNote()) {
                ++$chiffres['utile'];
            } elseif (AssistantMessage::NOTE_PAS_UTILE === $m->getNote()) {
                ++$chiffres['pasUtile'];
            }
            if (!$m->estRepondue()) {
                ++$chiffres['sansReponse'];
            }
        }

        return $chiffres;
    }

    /**
     * Questions regroupées quand elles se ressemblent (mêmes mots, sans
     * accents ni ponctuation), les plus fréquentes d'abord.
     *
     * @param list<AssistantMessage> $messages dans l'ordre
     *
     * @return list<array{texte: string, nombre: int, conversation: ?AssistantConversation}>
     */
    public static function questionsFrequentes(array $messages, int $nombre = 15): array
    {
        $groupes = [];
        foreach ($messages as $m) {
            if (!$m->estDuClient()) {
                continue;
            }
            $cle = self::cle($m->getTexte());
            if ('' === $cle) {
                continue;
            }
            $groupes[$cle] ??= ['texte' => '', 'nombre' => 0, 'conversation' => null];
            ++$groupes[$cle]['nombre'];
            // La formulation la plus récente.
            $groupes[$cle]['texte'] = mb_substr($m->getTexte(), 0, 200);
            $groupes[$cle]['conversation'] = $m->getConversation();
        }
        $liste = array_values($groupes);
        usort($liste, static fn (array $a, array $b): int => $b['nombre'] <=> $a['nombre']);

        return \array_slice($liste, 0, $nombre);
    }

    /** Clé de regroupement d'une question : mots utiles, triés. */
    public static function cle(string $question): string
    {
        $mots = OutilsAssistant::mots($question);
        sort($mots);

        return implode(' ', $mots);
    }

    /**
     * Questions que l'assistant n'a pas réglées : sans réponse (refus,
     * erreur, plafond, limite), passées à un conseiller, ou jugées « pas
     * utiles ». Les plus récentes d'abord.
     *
     * @param list<AssistantMessage> $messages dans l'ordre
     *
     * @return list<array{question: string, reponse: AssistantMessage}>
     */
    public static function sansReponse(array $messages, int $nombre = 20): array
    {
        $liste = [];
        $questions = [];
        foreach ($messages as $m) {
            $conversation = spl_object_id($m->getConversation() ?? $m);
            if ($m->estDuClient()) {
                $questions[$conversation] = $m->getTexte();
                continue;
            }
            if (!$m->estRepondue() || AssistantMessage::ISSUE_AGENT === $m->getIssue() || AssistantMessage::NOTE_PAS_UTILE === $m->getNote()) {
                $liste[] = ['question' => $questions[$conversation] ?? '', 'reponse' => $m];
            }
        }

        return \array_slice(array_reverse($liste), 0, $nombre);
    }

    /**
     * Sujets : les outils appelés, du plus demandé au moins demandé.
     *
     * @param list<AssistantMessage> $messages
     *
     * @return list<array{outil: string, libelle: string, nombre: int}>
     */
    public static function sujets(array $messages): array
    {
        $compte = [];
        foreach ($messages as $m) {
            foreach (array_unique($m->getListeOutils()) as $outil) {
                $compte[$outil] = ($compte[$outil] ?? 0) + 1;
            }
        }
        arsort($compte);

        return array_map(static fn (string $outil, int $n): array => ['outil' => $outil, 'libelle' => OutilsAssistant::LIBELLES[$outil] ?? $outil, 'nombre' => $n], array_keys($compte), $compte);
    }
}
