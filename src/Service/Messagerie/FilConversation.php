<?php

namespace App\Service\Messagerie;

use App\Entity\ControlePrePresse;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Entity\Paiements;
use App\Twig\ReglagesExtension;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le fil d'une conversation tel qu'il s'affiche (maquettes MS01 à MS03) :
 * séparateurs de jour (« Jeudi 15 octobre · commande créée, acompte de
 * 11 500 F reçu »), bulles, événements (« BAT validé à 11:40 · impression
 * lancée », collègue ajouté) et, pour l'atelier seulement, notes internes.
 * Les montants sont masqués pour qui ne les voit pas.
 */
final class FilConversation
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<MessageClient>     $messages
     * @param list<NoteConversation> $notes    vide côté client
     *
     * @return list<array<string, mixed>>
     */
    public function construire(ConversationClient $c, array $messages, array $notes, bool $montants): array
    {
        $elements = [];
        $creation = null;
        $commande = $c->getCommande();
        if (null !== $commande && null !== $commande->getDateCommande()) {
            $texte = 'commande créée';
            $paiement = $this->em->getRepository(Paiements::class)->findOneBy(['commande' => $commande, 'statut' => Paiements::STATUT_VALIDE], ['date' => 'ASC', 'id' => 'ASC']);
            if ($paiement instanceof Paiements && $montants && $paiement->getMontant() > 0) {
                $texte .= ', acompte de '.number_format($paiement->getMontant(), 0, ',', ' ').' F reçu';
            }
            $creation = ['le' => \DateTimeImmutable::createFromInterface($commande->getDateCommande()), 'texte' => $texte];
        }
        if (null !== $commande) {
            /** @var list<ControlePrePresse> $bats */
            $bats = $this->em->createQuery('SELECT b FROM '.ControlePrePresse::class.' b JOIN b.commandeDetail d WHERE d.commande = :c AND b.batValide = true AND b.valideLe IS NOT NULL')
                ->setParameter('c', $commande)->getResult();
            foreach ($bats as $b) {
                $elements[] = ['type' => 'evenement', 'le' => $b->getValideLe(), 'texte' => 'BAT validé à '.$b->getValideLe()->format('H:i').' · impression lancée', 'vert' => true];
            }
        }
        foreach ($messages as $m) {
            if ($m->estUnEvenement()) {
                $elements[] = ['type' => 'evenement', 'le' => $m->getCreeLe(), 'texte' => $m->getTexte(), 'vert' => false];
                continue;
            }
            $elements[] = [
                'type' => 'message', 'le' => $m->getCreeLe(), 'm' => $m,
                'texte' => $montants ? $m->getTexte() : Conversations::sansMontants($m->getTexte()),
                'signature' => $m->getSignature(),
                // « Lu ✓✓ » : réponse lue par le client, message du client lu par l'atelier.
                'lu' => $m->estDeLAtelier() ? (null !== $c->getLuClientLe() && $c->getLuClientLe() >= $m->getCreeLe()) : (null !== $c->getLuAtelierLe() && $c->getLuAtelierLe() >= $m->getCreeLe()),
            ];
        }
        foreach ($notes as $n) {
            // « @Kadiatou » surligné ; le reste du texte est échappé.
            $html = (string) preg_replace('/@([\p{L}][\p{L}\-.]*)/u', '<mark>@$1</mark>', htmlspecialchars($n->getTexte(), \ENT_QUOTES));
            $elements[] = ['type' => 'note', 'le' => $n->getCreeLe(), 'note' => $n, 'html' => nl2br($html)];
        }
        usort($elements, static fn (array $a, array $b): int => $a['le'] <=> $b['le']);

        $fil = [];
        $jour = null;
        if (null !== $creation && ([] === $elements || $creation['le'] < $elements[0]['le'])) {
            $fil[] = ['type' => 'jour', 'le' => $creation['le'], 'texte' => self::jour($creation['le']).' · '.$creation['texte']];
            $jour = $creation['le']->format('Y-m-d');
        }
        foreach ($elements as $e) {
            if ($e['le']->format('Y-m-d') !== $jour) {
                $jour = $e['le']->format('Y-m-d');
                $fil[] = ['type' => 'jour', 'le' => $e['le'], 'texte' => self::jour($e['le'])];
            }
            $fil[] = $e;
        }

        return $fil;
    }

    /** « Jeudi 15 octobre » */
    public static function jour(\DateTimeInterface $d): string
    {
        return ucfirst(ReglagesExtension::dateFr($d, 'EEEE d MMMM'));
    }
}
