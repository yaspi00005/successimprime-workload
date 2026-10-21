<?php

namespace App\Service\Recette;

use App\Entity\AssistantConversation;
use App\Entity\Campagne;
use App\Entity\Clients;
use App\Entity\Conversation;
use App\Entity\ConversationClient;
use App\Entity\DemandeConception;
use App\Entity\DemandeDevis;
use App\Entity\JournalActivite;
use App\Entity\Message;
use App\Entity\MessageClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\NoteConversation;
use App\Entity\PageSite;
use App\Entity\PieceJointeMessage;
use App\Service\Guide\CatalogueGuide;
use App\Service\Parametres\CatalogueParametres;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Route;
use Symfony\Component\Uid\AbstractUid;

/**
 * Remplit les paramètres d'une adresse de l'admin avec des fiches de la
 * démonstration (identifiants à partir de 900001), pour demander la page
 * comme un vrai lien : « /commandes/{id} » devient « /commandes/900001 ».
 *
 * L'entité vient du type de l'argument du contrôleur (Commandes $commande) ;
 * quelques routes qui prennent un nombre et cherchent elles-mêmes sont
 * listées dans MANUELS.
 */
final class ParametresRoute
{
    public const PREMIER_ID_DEMO = 900001;

    /** Paramètre « nombre » d'un contrôleur qui cherche lui-même sa fiche : l'entité à prendre. */
    private const MANUELS = [
        'app_assistant_conversation' => ['id' => AssistantConversation::class],
        'app_campagne_show' => ['id' => Campagne::class],
        'app_clients_modifier' => ['publicId' => Clients::class],
        'app_clients_journal' => ['publicId' => Clients::class],
        'app_demandes_conception_voir' => ['id' => DemandeConception::class],
        'app_demandes_site_voir' => ['id' => DemandeDevis::class],
        'app_demandes_site_fichier' => ['id' => DemandeDevis::class],
        'app_journal_activite_show' => ['id' => JournalActivite::class],
        'app_messages_clients_voir' => ['publicId' => ConversationClient::class],
        'app_site_contenu_fiche' => ['id' => PageSite::class],
    ];

    /** @var array<string, string|int|null> valeur déjà cherchée, par entité et champ */
    private array $cache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Jeux de paramètres pour cette route (un seul en général ; un par
     * tuile pour les panneaux des Réglages, un par fiche ou capture du
     * guide), ou la raison de l'impossibilité.
     *
     * @return list<array<string, string|int>>|string
     */
    public function remplir(string $nom, Route $route): array|string
    {
        $variables = $route->compile()->getPathVariables();
        if ([] === $variables) {
            return [[]];
        }
        if ('app_reglages_parametres_panneau' === $nom) {
            return array_map(static fn (string $tuile): array => ['tuile' => $tuile], array_keys(CatalogueParametres::tuiles()));
        }
        // Guide : chaque fiche et chaque capture, pour juger leurs droits un par un.
        if ('app_aide_fiche' === $nom) {
            return array_map(static fn (array $fiche): array => ['cle' => $fiche['cle']], CatalogueGuide::fiches());
        }
        if ('app_aide_capture' === $nom) {
            return array_map(static fn (string $capture): array => ['nom' => $capture], array_keys(CatalogueGuide::captures()));
        }

        // Reçu du compte du client : seuls un dépôt et l'argent rendu en ont un.
        if ('app_caisse_solde_recu' === $nom) {
            $ligne = $this->em->createQueryBuilder()->select('e.id')->from(MouvementSoldeClient::class, 'e')
                ->where('e.type IN (:types)')->setParameter('types', [MouvementSoldeClient::DEPOT, MouvementSoldeClient::REMBOURSEMENT])
                ->orderBy('e.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

            return null === $ligne ? 'aucun dépôt ni argent rendu sur un compte client' : [['id' => (int) $ligne['id']]];
        }

        // Contrôle final (docs/production-colis.md) : une commande qui a un contrôle, et sa photo.
        if (\in_array($nom, ['app_atelier_production_controle_commande', 'app_atelier_production_controle_photo_voir'], true)) {
            $photo = 'app_atelier_production_controle_photo_voir' === $nom;
            $id = $this->em->getConnection()->fetchOne('SELECT commande_id FROM controle_final WHERE commande_id >= 900001'.($photo ? ' AND photo IS NOT NULL' : '').' ORDER BY id LIMIT 1');
            if (false !== $id) {
                return [['id' => (int) $id]];
            }
        }

        // Note vocale de la messagerie (docs/messagerie-clients.md) : une note interne et un message de la démonstration.
        if ('app_messages_clients_vocale_ecouter' === $nom) {
            $jeux = [];
            foreach (['note' => NoteConversation::class, 'message' => MessageClient::class] as $genre => $classe) {
                $ligne = $this->em->createQuery('SELECT e.id, c.publicId FROM '.$classe.' e JOIN e.conversation c WHERE e.vocaleNom IS NOT NULL ORDER BY e.id ASC')->setMaxResults(1)->getOneOrNullResult();
                if (null !== $ligne) {
                    $jeux[] = ['publicId' => (string) $ligne['publicId'], 'genre' => $genre, 'id' => (int) $ligne['id']];
                }
            }

            return [] === $jeux ? 'aucune note vocale dans les conversations' : $jeux;
        }

        // Messagerie de l'équipe (docs/messagerie-interne.md) : un groupe et un message privé, chacun jugé avec ses membres.
        if (str_starts_with($nom, 'app_messagerie_interne_')) {
            $jeux = $this->messagerieInterne($nom, $variables);
            if (null !== $jeux) {
                return $jeux;
            }
        }

        $arguments = self::argumentsDuControleur($route);
        $valeurs = [];
        foreach ($variables as $variable) {
            $valeur = $this->valeur($nom, $route, $variable, $arguments);
            if (null === $valeur) {
                if (null !== $route->getDefault($variable) || $route->hasDefault($variable)) {
                    continue;
                }

                return \sprintf('paramètre « %s » sans valeur de démonstration', $variable);
            }
            $valeurs[$variable] = $valeur;
        }

        return [$valeurs];
    }

    /**
     * @param array<string, \ReflectionParameter> $arguments
     */
    private function valeur(string $nom, Route $route, string $variable, array $arguments): string|int|null
    {
        $exigence = (string) $route->getRequirement($variable);
        // « pdf|xlsx », « arrivee|depart » : la première possibilité.
        if (preg_match('/^[a-z_]+(\|[a-z_]+)+$/i', $exigence)) {
            return explode('|', $exigence)[0];
        }
        if (isset(self::MANUELS[$nom][$variable])) {
            return $this->premiere(self::MANUELS[$nom][$variable], 'id' === $variable ? 'id' : $variable);
        }
        [$classe, $champ] = $this->entiteDe($variable, $arguments);
        if (null !== $classe) {
            return $this->premiere($classe, $champ);
        }

        return match ($variable) {
            'annee' => (int) date('Y'),
            'mois' => (int) date('n'),
            'date', 'jour' => date('Y-m-d'),
            'page', 'version' => 1,
            default => null,
        };
    }

    /**
     * Entité et champ qui donnent la valeur d'une variable de l'adresse.
     *
     * @param array<string, \ReflectionParameter> $arguments
     *
     * @return array{0: class-string|null, 1: string}
     */
    private function entiteDe(string $variable, array $arguments): array
    {
        $entites = [];
        foreach ($arguments as $parametre) {
            $type = $parametre->getType();
            $classe = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if (null === $classe || !class_exists($classe) || $this->em->getMetadataFactory()->isTransient($classe)) {
                continue;
            }
            $entites[$parametre->getName()] = $classe;
            foreach ($parametre->getAttributes(MapEntity::class) as $attribut) {
                $carte = $attribut->newInstance();
                if ($carte->id === $variable) {
                    return [$classe, 'id'];
                }
                foreach ((array) ($carte->mapping ?? []) as $cle => $champ) {
                    if ($cle === $variable) {
                        return [$classe, \is_string($champ) ? $champ : $cle];
                    }
                }
            }
        }
        if (isset($entites[$variable])) {
            return [$entites[$variable], 'id'];
        }
        if (1 === \count($entites)) {
            $classe = reset($entites);
            $metadonnees = $this->em->getClassMetadata($classe);
            if ('id' === $variable || $metadonnees->hasField($variable)) {
                return [$classe, $variable];
            }
        }

        return [null, ''];
    }

    /**
     * Première fiche de la démonstration (sinon la première tout court).
     *
     * @param class-string $classe
     */
    public function premiere(string $classe, string $champ = 'id'): string|int|null
    {
        $cle = $classe.'::'.$champ;
        if (\array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }
        /** @var ClassMetadata<object> $metadonnees */
        $metadonnees = $this->em->getClassMetadata($classe);
        $identifiant = $metadonnees->getSingleIdentifierFieldName();
        $valeur = null;
        foreach ([true, false] as $demo) {
            $qb = $this->em->createQueryBuilder()->select('e.'.$champ)->from($classe, 'e')->orderBy('e.'.$identifiant, 'ASC')->setMaxResults(1);
            if ($demo) {
                $qb->where('e.'.$identifiant.' >= :demo')->setParameter('demo', self::PREMIER_ID_DEMO);
            }
            $ligne = $qb->getQuery()->getOneOrNullResult();
            if (null !== $ligne) {
                $valeur = $ligne[$champ];
                break;
            }
        }

        return $this->cache[$cle] = match (true) {
            $valeur instanceof AbstractUid => $valeur->toRfc4122(),
            \is_int($valeur), \is_string($valeur) => $valeur,
            null === $valeur => null,
            default => (string) $valeur,
        };
    }

    /**
     * @return array<string, \ReflectionParameter>
     */
    /**
     * @param list<string> $variables
     *
     * @return list<array<string, string|int>>|string|null null : les règles ordinaires
     */
    private function messagerieInterne(string $nom, array $variables): array|string|null
    {
        if ('app_messagerie_interne_fichier' === $nom) {
            $ligne = $this->em->createQuery('SELECT p.id, c.jeton FROM '.PieceJointeMessage::class.' p JOIN p.conversation c ORDER BY p.id ASC')->setMaxResults(1)->getOneOrNullResult();

            return null === $ligne ? 'aucun fichier dans la messagerie de l\'équipe' : [['jeton' => (string) $ligne['jeton'], 'id' => (int) $ligne['id']]];
        }
        if ('app_messagerie_interne_vocale_ecouter' === $nom) {
            $ligne = $this->em->createQuery('SELECT m.id, c.jeton FROM '.Message::class.' m JOIN m.conversation c WHERE m.vocaleNom IS NOT NULL ORDER BY m.id ASC')->setMaxResults(1)->getOneOrNullResult();

            return null === $ligne ? 'aucune note vocale dans la messagerie de l\'équipe' : [['jeton' => (string) $ligne['jeton'], 'id' => (int) $ligne['id']]];
        }
        if (\in_array('jeton', $variables, true) && 1 === \count($variables)) {
            if (str_starts_with($nom, 'app_messagerie_interne_envoi_')) {
                return [['jeton' => str_repeat('0', 40)]];
            }
            $jeux = [];
            foreach ([Conversation::PERMANENT, Conversation::PRIVE] as $type) {
                $jeton = $this->em->createQuery('SELECT c.jeton FROM '.Conversation::class.' c WHERE c.type = :t ORDER BY c.id ASC')->setParameter('t', $type)->setMaxResults(1)->getOneOrNullResult();
                if (null !== $jeton) {
                    $jeux[] = ['jeton' => (string) $jeton['jeton']];
                }
            }

            return [] === $jeux ? 'aucune conversation de l\'équipe' : $jeux;
        }

        return null;
    }

    private static function argumentsDuControleur(Route $route): array
    {
        $controleur = $route->getDefault('_controller');
        if (!\is_string($controleur) || !str_contains($controleur, '::')) {
            return [];
        }
        [$classe, $methode] = explode('::', $controleur, 2);
        if (!method_exists($classe, $methode)) {
            return [];
        }
        $arguments = [];
        foreach ((new \ReflectionMethod($classe, $methode))->getParameters() as $parametre) {
            $arguments[$parametre->getName()] = $parametre;
        }

        return $arguments;
    }
}
