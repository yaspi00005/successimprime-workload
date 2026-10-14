<?php

namespace App\Service\Assistant;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Réponses de démonstration au format de l'API, sans appel ni clé
 * (ASSISTANT_DEMO=1, en développement seulement : ReglagesAssistant).
 *
 * Choisit un outil d'après des mots de la question, puis rédige la réponse
 * à partir du seul résultat de l'outil : elle suit donc les mêmes règles
 * (rien d'inventé, droits du compte respectés par les outils). Sert aux
 * essais et aux captures d'écran.
 */
final class RepondeurDemo implements ClientInterface
{
    /** Mots de la question => outil appelé, dans l'ordre. */
    private const ROUTES = [
        'passer_a_un_agent' => ['conseiller', 'humain', 'agent', 'rembours', 'remise', 'annuler', 'annulation', 'réclamation', 'reclamation', 'quelqu\'un'],
        'reste_a_payer' => ['payer', 'reste', 'solde', 'facture', 'dois'],
        'mes_commandes' => ['commande', 'où en est', 'ou en est', 'suivi', 'livr', 'prête', 'prete'],
        'mes_produits' => ['mes produits'],
        'promotions_en_cours' => ['promo', 'réduction', 'reduction'],
        'infos_boutique' => ['horaire', 'ouvert', 'adresse', 'où êtes', 'ou etes', 'fichier', 'format', 'délai', 'delai'],
        'chercher_produits' => ['prix', 'combien', 'tarif', 'bâche', 'bache', 'banderole', 'roll', 'carte', 'flyer', 't-shirt', 'produit'],
    ];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $corps = json_decode((string) $request->getBody(), true);
        $requete = \is_array($corps) ? $corps : [];
        $outils = array_column(\is_array($requete['tools'] ?? null) ? $requete['tools'] : [], 'name');
        $messages = \is_array($requete['messages'] ?? null) ? $requete['messages'] : [];
        $dernier = end($messages) ?: [];
        $blocs = \is_array($dernier['content'] ?? null) ? $dernier['content'] : [];

        $resultat = null;
        foreach ($blocs as $b) {
            if ('tool_result' === ($b['type'] ?? null)) {
                $resultat = $b;
            }
        }

        if (null !== $resultat) {
            $outil = self::outilAppele($messages, (string) ($resultat['tool_use_id'] ?? ''));
            $donnees = json_decode(self::texteResultat($resultat), true);
            $contenu = [self::reflexion(), ['type' => 'text', 'text' => self::rediger($outil, \is_array($donnees) ? $donnees : [])]];
            $arret = 'end_turn';
        } else {
            $question = '';
            foreach ($blocs as $b) {
                if ('text' === ($b['type'] ?? null) && !str_starts_with((string) $b['text'], '<contexte>')) {
                    $question = (string) $b['text'];
                }
            }
            [$outil, $entree] = self::choisir($question, $outils);
            if (null === $outil) {
                $contenu = [self::reflexion(), ['type' => 'text', 'text' => 'Je suis l\'assistant de démonstration. Demandez-moi un prix, nos horaires, ou où en est votre commande.']];
                $arret = 'end_turn';
            } else {
                $contenu = [self::reflexion(), ['type' => 'tool_use', 'id' => 'toolu_demo_'.substr(hash('sha256', $question.\count($messages)), 0, 16), 'name' => $outil, 'input' => [] === $entree ? new \stdClass() : $entree]];
                $arret = 'tool_use';
            }
        }

        $taille = \strlen((string) $request->getBody());
        $reponse = [
            'id' => 'msg_demo_'.substr(hash('sha256', (string) $request->getBody()), 0, 20),
            'type' => 'message',
            'role' => 'assistant',
            'model' => (string) ($requete['model'] ?? ReglagesAssistant::MODELE_DEFAUT),
            'content' => $contenu,
            'stop_reason' => $arret,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 40,
                'output_tokens' => 120,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => intdiv($taille, 4),
            ],
        ];
        $fabrique = new Psr17Factory();

        return $fabrique->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('request-id', 'req_demo')
            ->withBody($fabrique->createStream((string) json_encode($reponse, \JSON_UNESCAPED_UNICODE)));
    }

    /**
     * @param list<string> $outils outils proposés dans cette requête
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public static function choisir(string $question, array $outils): array
    {
        $q = mb_strtolower($question);
        foreach (self::ROUTES as $outil => $mots) {
            if (!\in_array($outil, $outils, true)) {
                continue;
            }
            foreach ($mots as $mot) {
                if (str_contains($q, $mot)) {
                    return [$outil, self::entree($outil, $question)];
                }
            }
        }

        return [null, []];
    }

    /** @return array<string, mixed> */
    private static function entree(string $outil, string $question): array
    {
        return match ($outil) {
            'passer_a_un_agent' => ['motif' => 'demande_du_client', 'resume' => mb_substr(trim($question), 0, 300)],
            'mes_commandes' => ['numero' => preg_match('/\b(W-)?CMD-?\d+/i', $question, $m) ? strtoupper($m[0]) : ''],
            'chercher_produits' => ['texte' => trim((string) preg_replace('/\b(quel|quelle|est|le|la|les|un|une|des|du|de|prix|combien|coûte|coute|pour|d\'une|d\'un)\b/iu', ' ', $question))],
            default => [],
        };
    }

    /** @param list<array<string, mixed>> $messages */
    private static function outilAppele(array $messages, string $id): string
    {
        foreach (array_reverse($messages) as $m) {
            foreach (\is_array($m['content'] ?? null) ? $m['content'] : [] as $b) {
                if ('tool_use' === ($b['type'] ?? null) && ($b['id'] ?? null) === $id) {
                    return (string) $b['name'];
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $resultat */
    private static function texteResultat(array $resultat): string
    {
        $contenu = $resultat['content'] ?? '';
        if (\is_array($contenu)) {
            return implode('', array_map(static fn ($b): string => \is_array($b) ? (string) ($b['text'] ?? '') : '', $contenu));
        }

        return (string) $contenu;
    }

    /** @return array{type: string, thinking: string, signature: string} */
    private static function reflexion(): array
    {
        return ['type' => 'thinking', 'thinking' => '', 'signature' => 'demo'];
    }

    /** @param array<string, mixed> $d résultat de l'outil */
    public static function rediger(string $outil, array $d): string
    {
        if (isset($d['erreur'])) {
            return (string) $d['erreur'];
        }

        return match ($outil) {
            'mes_commandes' => self::commandes($d),
            'reste_a_payer' => \sprintf("Il reste %s à payer sur vos commandes en cours%s.", $d['reste_a_payer'] ?? '0 FCFA', ($d['commandes'] ?? []) !== [] ? ' ('.implode(', ', array_map(static fn (array $c): string => $c['numero'].' : '.$c['reste'], $d['commandes'])).')' : '')
                .(!empty($d['en_validation']) ? "\nUn paiement Orange Money de ".$d['en_validation'].' attend la confirmation de la caisse.' : ''),
            'infos_boutique' => \sprintf("Nous sommes ouverts %s (%s).\nAdresse : %s.\nFichiers acceptés : %s.\nDélais : %s", $d['horaires'] ?? '', mb_strtolower((string) ($d['ouvert_maintenant'] ?? '')), $d['adresse'] ?? '', $d['formats_fichiers'] ?? '', $d['delais'] ?? ''),
            'promotions_en_cours' => ($d['promotions'] ?? []) === [] ? 'Aucune promotion en cours en ce moment.' : "Promotions en cours :\n".implode("\n", array_map(static fn (array $p): string => '- '.$p['avantage'].' sur '.$p['articles'].' (jusqu\'au '.$p['fin'].')', $d['promotions'])),
            'chercher_produits' => ($d['produits'] ?? []) === [] ? 'Je ne trouve pas ce produit dans notre catalogue. Un conseiller peut vous répondre sur WhatsApp.' : "Voici ce que je trouve au tarif public :\n".implode("\n", array_map(static fn (array $p): string => '- '.$p['nom'].' : '.$p['prix_depart'], \array_slice($d['produits'], 0, 4)))."\nDites-moi la taille et la quantité pour une estimation.",
            'mes_produits' => ($d['produits'] ?? []) === [] ? 'Vous n\'avez pas encore de produit enregistré.' : "Vos produits :\n".implode("\n", array_map(static fn (array $p): string => '- '.$p['nom'].(isset($p['prix']) ? ' : '.$p['prix'] : ''), $d['produits'])),
            'passer_a_un_agent' => 'Je vous mets en relation avec un conseiller : touchez le bouton WhatsApp ci-dessous, le résumé de votre demande est déjà écrit.',
            default => 'C\'est noté.',
        };
    }

    /** @param array<string, mixed> $d */
    private static function commandes(array $d): string
    {
        $liste = $d['commandes'] ?? [];
        if ([] === $liste) {
            return 'Je ne trouve pas de commande en cours sur votre compte.';
        }
        if (1 === \count($liste)) {
            $c = $liste[0];
            $texte = \sprintf('Votre commande %s (%s) : %s.', $c['numero'], $c['titre'], $c['etape_actuelle']);
            if (!empty($c['livraison_prevue'])) {
                $texte .= ' Livraison prévue le '.$c['livraison_prevue'].'.';
            }
            if (!empty($c['bat_a_valider'])) {
                $texte .= "\nUn BAT attend votre validation.";
            }
            if (isset($c['reste_a_payer'])) {
                $texte .= "\nReste à payer : ".$c['reste_a_payer'].'.';
            }

            return $texte;
        }
        $lignes = array_map(static fn (array $c): string => '- **'.$c['numero'].'** ('.$c['titre'].') : '.$c['etape_actuelle']
            .(!empty($c['livraison_prevue']) ? ', livraison prévue le '.$c['livraison_prevue'] : '')
            .(!empty($c['bat_a_valider']) ? ', BAT à valider' : '')
            .(isset($c['reste_a_payer']) && '0' !== preg_replace('/\D/', '', (string) $c['reste_a_payer']) ? ', reste '.$c['reste_a_payer'] : ''), $liste);

        return "Vos commandes récentes :\n".implode("\n", $lignes)."\n\nDonnez-moi un numéro pour le détail.";
    }
}
