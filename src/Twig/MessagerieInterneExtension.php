<?php

namespace App\Twig;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Security\MessagerieInterneVoter;
use App\Security\MessagerieVoter;
use App\Service\MessagerieInterne\MessagesEquipe;
use App\Service\PastillesMenu;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Messagerie de l'équipe dans les gabarits (docs/messagerie-interne.md) :
 * pastille de l'icône de l'en-tête, texte d'un message avec ses @mentions
 * et ses liens, nom d'une personne.
 */
final class MessagerieInterneExtension
{
    public function __construct(
        private readonly MessagesEquipe $messages,
        private readonly Security $security,
        private readonly PastillesMenu $pastilles,
    ) {
    }

    /**
     * Onglets « Clients · 2 | Équipe · 6 » en haut des deux messageries :
     * null pour un onglet que la personne n'a pas.
     *
     * @return array{clients: ?int, equipe: ?int}
     */
    #[AsTwigFunction('bascule_messagerie')]
    public function bascule(bool $enLigne = false): array
    {
        $u = $this->security->getUser();
        $pastilles = $this->pastilles->parRoute();

        return [
            'clients' => $this->security->isGranted(MessagerieVoter::BOITE) ? (int) ($pastilles['app_messages_clients_index'] ?? 0) : null,
            'equipe' => !$enLigne && $u instanceof User && MessagerieInterneVoter::aAcces($u) ? $this->messages->filsNonLus($u) : null,
        ];
    }

    /** Nombre de fils avec un message non lu (icône de l'en-tête). */
    #[AsTwigFunction('mes_conversations_non_lues')]
    public function nonLues(): int
    {
        $u = $this->security->getUser();
        if (!$u instanceof User || !MessagerieInterneVoter::aAcces($u)) {
            return 0;
        }

        return $this->messages->filsNonLus($u);
    }

    #[AsTwigFilter('nom_equipe')]
    public function nom(?User $u): string
    {
        return Conversation::nomDe($u);
    }

    #[AsTwigFilter('pseudo_equipe')]
    public function pseudo(User $u): string
    {
        return MessagesEquipe::pseudo($u);
    }

    /** « OS » : initiales de l'avatar. */
    #[AsTwigFilter('initiales_equipe')]
    public function initiales(?User $u): string
    {
        $mots = preg_split('/\s+/u', trim(Conversation::nomDe($u))) ?: [];
        $i = '';
        foreach (\array_slice($mots, 0, 2) as $m) {
            $i .= mb_strtoupper(mb_substr($m, 0, 1));
        }

        return '' !== $i ? $i : '?';
    }

    /**
     * Texte d'un message, échappé : @mentions surlignées (seulement les
     * personnes citées), liens cliquables, retours à la ligne.
     *
     * @param array<int, User> $membres
     */
    #[AsTwigFilter('texte_equipe', isSafe: ['html'])]
    public function texte(Message $m, array $membres = []): string
    {
        $html = htmlspecialchars($m->getContenu(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $cites = [];
        foreach ($m->getMentions() as $id) {
            if (isset($membres[$id])) {
                $cites = [...$cites, ...MessagesEquipe::clesDe($membres[$id])];
            }
        }
        $html = (string) preg_replace_callback('/(?<![\p{L}\p{N}@])@([\p{L}][\p{L}\p{N}._-]{1,40})/u', static function (array $t) use ($cites): string {
            return \in_array(MessagesEquipe::cle($t[1]), $cites, true) ? '<mark class="mi-mention">'.$t[0].'</mark>' : $t[0];
        }, $html);
        $html = (string) preg_replace_callback('#https?://[^\s<>"]+#u', static fn (array $t): string => '<a href="'.$t[0].'" target="_blank" rel="noopener noreferrer">'.$t[0].'</a>', $html);

        return nl2br($html);
    }

    /** « 24 Mo », « 420 Ko ». */
    #[AsTwigFilter('taille_fichier')]
    public function taille(int $octets): string
    {
        if ($octets >= 1048576) {
            $mo = $octets / 1048576;

            return str_replace('.', ',', (string) ($mo >= 10 ? round($mo) : round($mo, 1))).' Mo';
        }

        return max(1, (int) round($octets / 1024)).' Ko';
    }
}
