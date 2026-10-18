<?php

namespace App\Service\Recette;

use App\Controller\RapportsMailController;
use App\Entity\Clients;
use App\Entity\EnvoiRapport;
use App\Entity\User;
use App\Service\Rapports\DestinatairesRapports;
use App\Service\Rapports\DroitsRapport;
use App\Service\Statistiques\Objectifs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Recette des rapports par e-mail (docs/rapports-mail.md,
 * docs/recette-par-role.md), en plus de la matrice des pages :
 *  - seule la Direction choisit les destinataires et renvoie un rapport,
 *    même avec un jeton de formulaire valide ;
 *  - sans le droit « Chiffre d'affaires global », pas d'essai envoyé ;
 *  - hors Direction, « ?pour= » ne montre jamais la version d'un autre ;
 *  - un compte avec le seul droit du rapport (droits retirés le temps de
 *    la recette) ne voit dans son e-mail ni montant, ni nom de client,
 *    ni les parties d'argent, de l'équipe ou du stock.
 * Dans la transaction de la recette : rien n'est gardé, aucun e-mail ne part.
 */
final class RecetteRapportsMail
{
    /** Droits retirés au compte d'essai : il ne garde que celui du rapport. */
    private const RETIRES = ['ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_TRESORERIE_VOIR', 'ROLE_COUT_MARGE', 'ROLE_CLIENT', 'ROLE_RH'];

    /** Titres des parties réservées à d'autres droits, dans le corps de l'e-mail. */
    private const PARTIES_FERMEES = ['Chiffre d’affaires', 'Encaissements', 'Créances clients', 'Dépenses et trésorerie', 'Marge', 'Équipe', 'Stock sous le seuil', 'Plus gros restes à payer', 'Nouveaux clients :'];

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
        private readonly DestinatairesRapports $destinataires,
    ) {
    }

    /**
     * @param list<User> $comptes comptes de la recette
     */
    public function verifier(array $comptes, BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_rapports_mail')) {
            return;
        }
        $direction = null;
        foreach ($comptes as $c) {
            if (\in_array(User::ROLE_ADMIN, $c->getRoles(), true)) {
                $direction ??= $c;
            }
        }
        foreach ($comptes as $compte) {
            $this->verifierCompte($compte, $direction, $bilan);
        }
        $this->verifierVersionReduite($comptes, $bilan);
    }

    private function verifierCompte(User $compte, ?User $direction, BilanRecette $bilan): void
    {
        // Relu : un contrôle précédent (mode « Voir ») a pu changer ses droits dans la transaction.
        $compte = $this->em->getRepository(User::class)->find($compte->getId()) ?? $compte;
        $identifiant = $compte->getUserIdentifier();
        $jeton = 'recette-'.bin2hex(random_bytes(8));
        $session = $this->navigateur->session($compte, 'main', ['_csrf/'.RapportsMailController::CSRF => $jeton]);
        $droit = $this->destinataires->droits($compte)['rapport'];
        $admin = \in_array(User::ROLE_ADMIN, $compte->getRoles(), true);

        if (!$admin) {
            $essais = [['app_rapports_mail_destinataires', [], ['_token' => $jeton, 'mode' => 'choisis', 'destinataires' => [(int) $compte->getId()]], 'choisir les destinataires des rapports par e-mail']];
            $envoi = $this->em->getRepository(EnvoiRapport::class)->findOneBy([], ['id' => 'DESC']);
            if (null !== $envoi) {
                $essais[] = ['app_rapports_mail_reprendre', ['id' => $envoi->getId()], ['_token' => $jeton], 'renvoyer un rapport par e-mail'];
            }
            if (!$droit) {
                $essais[] = ['app_rapports_mail_essai', ['type' => 'jour'], ['_token' => $jeton], 'se faire envoyer un rapport par e-mail sans le droit'];
            }
            foreach ($essais as [$route, $parametres, $donnees, $libelle]) {
                $chemin = $this->routeur->generate($route, $parametres);
                $etat = $this->navigateur->post($chemin, $donnees, $session)->etat();
                $ok = \in_array($etat, [Reponse::REFUSE, Reponse::CONNEXION, Reponse::INTROUVABLE], true);
                $bilan->controler(\sprintf('%s : %s refusé', $identifiant, $libelle), $ok, $chemin.' → '.$etat);
                if (!$ok) {
                    $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'rapports_mail', $route, $chemin, $identifiant, $libelle.' : la page répond « '.$etat.' »');
                }
            }
        }

        // Hors Direction, « ?pour= » est ignoré : sa propre version, jamais celle d'un autre.
        if ($droit && !$admin && null !== $direction) {
            $chemin = $this->routeur->generate('app_rapports_mail_apercu', ['type' => 'mois', 'pour' => $direction->getId()]);
            $reponse = $this->navigateur->get($chemin, $session);
            $texte = $reponse->texte();
            $sienne = str_contains($texte, 'Bonjour '.self::prenom($compte).',');
            $autre = self::prenom($direction) !== self::prenom($compte) && str_contains($texte, 'Bonjour '.self::prenom($direction).',');
            $ok = Reponse::OUVERT === $reponse->etat() && $sienne && !$autre;
            $bilan->controler(\sprintf('%s : l\'aperçu du rapport par e-mail montre sa propre version (?pour= ignoré)', $identifiant), $ok, $chemin.' → '.$reponse->etat().($autre ? ' ; montre la version de '.self::prenom($direction) : ''));
            if (!$ok && Reponse::OUVERT === $reponse->etat()) {
                $bilan->constater(BilanRecette::FUITE, 'rapports_mail', 'app_rapports_mail_apercu', $chemin, $identifiant, 'version du rapport d\'une autre personne');
            }
        }
    }

    /**
     * Un compte caisse avec le seul droit du rapport : son e-mail n'a que
     * l'activité, en nombres.
     *
     * @param list<User> $comptes
     */
    private function verifierVersionReduite(array $comptes, BilanRecette $bilan): void
    {
        $compte = null;
        foreach ($comptes as $c) {
            if (!\in_array(User::ROLE_ADMIN, $c->getRoles(), true) && \in_array('ROLE_COMMANDE', $c->getRoles(), true)) {
                $compte = $c;
                break;
            }
        }
        if (null === $compte) {
            $bilan->controler('Rapports par e-mail : aucun compte caisse dans la démonstration pour la version réduite', false);

            return;
        }
        // Dans la transaction de la recette : annulé à la fin.
        $compte = $this->em->getRepository(User::class)->find($compte->getId()) ?? $compte;
        $avant = $compte->getDroitsEffectifs();
        $compte->definirDroits([...array_diff($avant, self::RETIRES), DroitsRapport::DROIT]);
        $this->em->flush();
        $libelle = $compte->getUserIdentifier().' (seul droit du rapport : « '.DroitsRapport::DROIT.' »)';

        try {
            $session = $this->navigateur->session($compte);
            $chemin = $this->routeur->generate('app_rapports_mail_apercu', ['type' => 'mois']);
            $reponse = $this->navigateur->get($chemin, $session);
            $corps = self::corpsDuCourriel($reponse->contenu);
            $montants = array_column(ContenuSensible::montants($corps), 'contexte');
            $parties = array_values(array_filter(self::PARTIES_FERMEES, static fn (string $titre): bool => str_contains($corps, $titre)));
            $noms = $this->nomsDeClients($corps);
            $ok = Reponse::OUVERT === $reponse->etat() && '' !== $corps && [] === $montants && [] === $parties && [] === $noms;
            $bilan->controler('Rapports par e-mail : '.$libelle.', ni montant, ni nom de client, ni partie réservée', $ok, $chemin.' → '.$reponse->etat()
                .([] !== $montants ? ' ; montants : '.implode(' ; ', \array_slice($montants, 0, 3)) : '')
                .([] !== $parties ? ' ; parties : '.implode(', ', $parties) : '')
                .([] !== $noms ? ' ; clients : '.implode(', ', \array_slice($noms, 0, 3)) : '')
                .('' === $corps ? ' ; corps de l\'e-mail introuvable' : ''));
            if (!$ok && Reponse::OUVERT === $reponse->etat() && '' !== $corps) {
                $bilan->constater(BilanRecette::FUITE, 'rapports_mail', 'app_rapports_mail_apercu', $chemin, $compte->getUserIdentifier(), 'rapport par e-mail : contenu sans le droit qui va avec');
            }

            // La liste des destinataires et l'historique des autres restent à la Direction.
            $index = $this->navigateur->get($this->routeur->generate('app_rapports_mail'), $session);
            $okIndex = Reponse::OUVERT === $index->etat() && !str_contains($index->contenu, 'name="destinataires[]"');
            $bilan->controler('Rapports par e-mail : '.$libelle.', pas de liste des destinataires', $okIndex, '/rapports-mail → '.$index->etat());
        } finally {
            $compte->definirDroits($avant);
            $this->em->flush();
        }
    }

    /** Le corps de l'e-mail dans l'aperçu, en texte (sans le menu ni l'en-tête de la page). */
    public static function corpsDuCourriel(string $html): string
    {
        $debut = strpos($html, 'class="si-rm-fond"');
        $fin = false === $debut ? false : strpos($html, 'class="si-rm-sous"', $debut);
        if (false === $debut || false === $fin) {
            return '';
        }

        return ContenuSensible::texteVisible('<div '.substr($html, $debut, $fin - $debut));
    }

    /**
     * Noms des clients de la démonstration trouvés dans un texte.
     *
     * @return list<string>
     */
    private function nomsDeClients(string $texte): array
    {
        $trouves = [];
        foreach ($this->em->getRepository(Clients::class)->createQueryBuilder('c')->where("c.code LIKE 'DEMO-%'")->getQuery()->getResult() as $client) {
            $nom = trim($client->getNomComplet());
            // Les noms trop courts (« Awa ») se confondraient avec d'autres mots.
            if (mb_strlen($nom) >= 8 && str_contains($texte, $nom)) {
                $trouves[] = $nom;
            }
        }

        return $trouves;
    }

    private static function prenom(User $u): string
    {
        $prenom = trim((string) $u->getEmploye()?->getPrenom());

        return '' !== $prenom ? $prenom : Objectifs::nomAgent($u);
    }
}
