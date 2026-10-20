<?php

namespace App\Service\Production;

use App\Controller\UtilisateursController;
use App\Service\Livraisons\ProgrammationLivraison;
use App\Service\Messagerie\Conversations;

/**
 * Le message WhatsApp « commande prête » (PD06, docs/production-colis.md),
 * préparé ici puis ouvert par la personne qui clique (lien wa.me, aucune
 * API) : « Vos 2 bâches sont prêtes. Livraison demain entre 10 h et 12 h.
 * Votre code de réception : 4827. » Jamais de montant (filet :
 * Conversations::sansMontants). Pur, sans base.
 */
final class MessagePrete
{
    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /**
     * @param list<array{nom: array{singulier: string, pluriel: string, feminin: bool, famille: string}|null, quantite: int}> $articles
     */
    public static function texte(array $articles, string $numeroCommande, bool $livraison, ?\DateTimeInterface $jour, ?string $creneau, ?string $code, \DateTimeInterface $aujourdhui): string
    {
        [$sujet, $pronom] = self::sujet($articles, $numeroCommande);
        $phrases = [$sujet.'.'];

        if ($livraison) {
            $quand = self::jour($jour, $aujourdhui);
            $heures = self::creneau($creneau);
            $phrases[] = $quand === null
                ? 'Nous vous appelons pour fixer l\'heure de la livraison.'
                : 'Livraison '.$quand.($heures !== null ? ' '.$heures : '').'.';
            $code = preg_replace('/\D/', '', (string) $code);
            if ($code !== null && $code !== '') {
                $phrases[] = 'Votre code de réception : '.$code.'.';
            }
        } else {
            $phrases[] = 'Vous pouvez '.$pronom.' retirer à l\'imprimerie.';
        }

        return Conversations::sansMontants(implode(' ', $phrases));
    }

    /**
     * Lien wa.me prêt à ouvrir ; null sans téléphone ou pour une fiche de
     * démonstration (DEMO-) : aucune personne réelle ne doit le recevoir.
     */
    public static function lien(?string $telephone, string $texte, bool $demo): ?string
    {
        $numero = UtilisateursController::numeroWhatsApp((string) $telephone);
        if ($demo || $numero === '' || \strlen($numero) < 8) {
            return null;
        }

        return 'https://wa.me/'.$numero.'?text='.rawurlencode(Conversations::sansMontants($texte));
    }

    /**
     * « Vos 2 bâches sont prêtes », « Votre kakémono est prêt », sinon
     * « Votre commande CMD-000183 est prête » ; et le pronom (le, la, les).
     *
     * @param list<array{nom: array{singulier: string, pluriel: string, feminin: bool, famille: string}|null, quantite: int}> $articles
     *
     * @return array{0: string, 1: string}
     */
    public static function sujet(array $articles, string $numeroCommande): array
    {
        $articles = array_values(array_filter($articles, static fn (array $a): bool => (int) $a['quantite'] > 0));
        $connus = array_filter($articles, static fn (array $a): bool => $a['nom'] !== null);
        if ($articles === [] || \count($connus) !== \count($articles) || \count($articles) > 3) {
            return [trim('Votre commande '.$numeroCommande).' est prête', 'la'];
        }
        if (\count($articles) === 1 && (int) $articles[0]['quantite'] === 1) {
            $nom = $articles[0]['nom'];

            return ['Votre '.$nom['singulier'].' est prêt'.($nom['feminin'] ? 'e' : ''), $nom['feminin'] ? 'la' : 'le'];
        }
        $morceaux = array_map(static fn (array $a): string => NomsProduits::compter((int) $a['quantite'], $a['nom']), $articles);
        $dernier = array_pop($morceaux);
        $liste = $morceaux === [] ? $dernier : implode(', ', $morceaux).' et '.$dernier;
        $feminin = array_reduce($articles, static fn (bool $f, array $a): bool => $f && $a['nom']['feminin'], true);

        return ['Vos '.$liste.' sont prêt'.($feminin ? 'es' : 's'), 'les'];
    }

    /** « aujourd'hui », « demain », « le mercredi 21/10 ». */
    public static function jour(?\DateTimeInterface $jour, \DateTimeInterface $aujourdhui): ?string
    {
        if ($jour === null) {
            return null;
        }
        $ecart = (int) \DateTimeImmutable::createFromInterface($aujourdhui)->setTime(0, 0)
            ->diff(\DateTimeImmutable::createFromInterface($jour)->setTime(0, 0))->format('%r%a');

        return match ($ecart) {
            0 => 'aujourd\'hui',
            1 => 'demain',
            default => 'le '.self::JOURS[(int) $jour->format('N')].' '.$jour->format('d/m'),
        };
    }

    /**
     * « entre 10 h et 12 h » depuis « 10-12 » (clé du client), « 09:00-11:00 »
     * (démo, tournée) ou « 10 h – 12 h » ; le texte tel quel sinon.
     */
    public static function creneau(?string $creneau): ?string
    {
        $creneau = trim((string) $creneau);
        if ($creneau === '') {
            return null;
        }
        $creneau = ProgrammationLivraison::CRENEAUX[$creneau] ?? $creneau;
        $heure = '(\d{1,2})\s*(?:[h:]\s*(\d{2})?)?\s*h?';
        if (preg_match('/^'.$heure.'\s*(?:-|–|—|à)\s*'.$heure.'$/u', $creneau, $m) === 1) {
            $f = static fn (string $h, ?string $min): string => (int) $h.' h'.($min !== null && $min !== '' && $min !== '00' ? ' '.$min : '');

            return 'entre '.$f($m[1], $m[2] ?? null).' et '.$f($m[3], $m[4] ?? null);
        }

        return '('.$creneau.')';
    }
}
