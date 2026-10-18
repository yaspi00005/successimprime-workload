<?php

namespace App\Controller;

use App\Entity\EnvoiRapport;
use App\Entity\User;
use App\Service\Parametres\Parametres;
use App\Service\Rapports\CourrielRapport;
use App\Service\Rapports\DestinatairesRapports;
use App\Service\Rapports\DroitsRapport;
use App\Service\Rapports\EnvoiRapports;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\ReglagesRapportsMail;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Objectifs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil › Rapports par e-mail (docs/rapports-mail.md) : quand partent
 * les rapports du jour, de la semaine et du mois, l'aperçu de sa propre
 * version (exactement l'e-mail reçu), « M'envoyer un test », et pour la
 * Direction la liste des destinataires et l'historique des envois.
 * Droit : « Chiffre d'affaires global » (celui qui permet de recevoir les
 * rapports). ERP local seulement (StatistiquesHorsLigneSubscriber).
 */
#[Route('/rapports-mail', name: 'app_rapports_mail')]
#[IsGranted(DroitsRapport::DROIT)]
final class RapportsMailController extends AbstractController
{
    use ExporterStatistiques;

    public const CSRF = 'rapports_mail';

    /** Périodes consultables en arrière dans l'aperçu (deux ans). */
    private const ANS_EN_ARRIERE = 2;

    public function __construct(
        private readonly ReglagesRapportsMail $reglages,
        private readonly DestinatairesRapports $destinataires,
        private readonly EnvoiRapports $envois,
        private readonly CourrielRapport $courriel,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $moi = $this->utilisateur();
        $maintenant = new \DateTimeImmutable();
        $direction = $this->isGranted('ROLE_ADMIN');

        $rapports = [];
        foreach (PeriodeRapport::TYPES as $type => $titre) {
            $dernier = $this->em->getRepository(EnvoiRapport::class)->findOneBy(['type' => $type, 'declencheur' => [EnvoiRapport::AUTO, EnvoiRapport::COMMANDE]], ['demarreLe' => 'DESC', 'id' => 'DESC']);
            $rapports[$type] = [
                'titre' => $titre,
                'actif' => $this->reglages->actif($type),
                'libelle' => $this->reglages->libelle($type),
                'prochain' => $this->reglages->actif($type) ? $this->reglages->prochainEnvoi($type, $maintenant) : null,
                'dernier' => $dernier,
            ];
        }

        $liste = $this->destinataires->liste();
        $historique = $this->envois->historique(40);
        if (!$direction) {
            // Hors direction : seulement ses propres lignes.
            $historique = array_values(array_filter($historique, static fn (EnvoiRapport $e): bool => [] !== array_filter($e->getLignes(), static fn (array $l): bool => $l['utilisateur'] === $moi->getId())));
        }

        return $this->render('rapports_mail/index.html.twig', [
            'rapports' => $rapports,
            'configure' => $this->reglages->courrielConfigure(),
            'factice' => $this->reglages->transportFactice(),
            'expediteur' => $this->reglages->expediteur()?->toString(),
            'piece_jointe' => $this->reglages->pieceJointe(),
            'adresse_erp' => $this->reglages->adresseErp(),
            'choisis' => null !== $this->reglages->destinatairesChoisis(),
            'candidats' => $direction ? $this->destinataires->candidats() : [],
            'moi' => [
                'recoit' => [] !== array_filter($liste, static fn (array $d): bool => $d['utilisateur']->getId() === $moi->getId()),
                'adresse' => DestinatairesRapports::adresse($moi),
                'version' => DroitsRapport::version($this->destinataires->droits($moi)),
            ],
            'historique' => $historique,
            'direction' => $direction,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/apercu/{type}', name: '_apercu', requirements: ['type' => 'jour|semaine|mois'], methods: ['GET'])]
    public function apercu(string $type, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $p = self::periodeDemandee($type, $request->query->getString('periode'), $maintenant);
        $pour = $this->personneVue($request);
        $roles = $this->destinataires->roles($pour);
        $droits = DroitsRapport::depuisRoles($roles);
        $contexte = $this->courriel->contexte($pour, $droits, $roles, $p, $maintenant);
        $moi = $this->utilisateur();

        return $this->render('rapports_mail/apercu.html.twig', $contexte + [
            'type' => $type,
            'texte' => $this->courriel->texte($contexte),
            'sujet' => CourrielRapport::sujet($p),
            'precedente' => $p->debut > $maintenant->modify(\sprintf('-%d years', self::ANS_EN_ARRIERE)) ? $p->avant() : null,
            'suivante' => $p->fin <= $maintenant ? $p->apres() : null,
            'pour' => $pour,
            'soi' => $pour->getId() === $moi->getId(),
            'personnes' => $this->isGranted('ROLE_ADMIN') ? array_values(array_filter($this->destinataires->candidats(), static fn (array $c): bool => $c['droit'])) : [],
            'mon_adresse' => DestinatairesRapports::adresse($moi),
            'configure' => $this->reglages->courrielConfigure(),
            'envoi' => $this->envois->envoiDe($p),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/apercu/{type}/pdf', name: '_pdf', requirements: ['type' => 'jour|semaine|mois'], methods: ['GET'])]
    public function pdf(string $type, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $p = self::periodeDemandee($type, $request->query->getString('periode'), $maintenant);
        $pour = $this->personneVue($request);
        $roles = $this->destinataires->roles($pour);
        $droits = DroitsRapport::depuisRoles($roles);
        $this->journaliserExport($this->em, $p->titre().' (e-mail)', $p->code(), 'pdf', array_keys(array_filter($droits)));

        return ExportPdf::reponse(
            $this->courriel->pdf($this->courriel->contexte($pour, $droits, $roles, $p, $maintenant), $maintenant, Objectifs::nomAgent($this->utilisateur())),
            CourrielRapport::nomPdf($p),
        );
    }

    /** « M'envoyer un test » : sa propre version, à sa propre adresse. */
    #[Route('/essai/{type}', name: '_essai', requirements: ['type' => 'jour|semaine|mois'], methods: ['POST'])]
    public function essai(string $type, Request $request): Response
    {
        $this->verifierJeton($request);
        $moi = $this->utilisateur();
        $maintenant = new \DateTimeImmutable();
        $p = self::periodeDemandee($type, $request->request->getString('periode'), $maintenant);
        $retour = $this->redirectToRoute('app_rapports_mail_apercu', ['type' => $type, 'periode' => $p->code()]);

        if (!$this->reglages->courrielConfigure()) {
            $this->addFlash('danger', 'L’envoi d’e-mails n’est pas réglé sur cette machine (MAILER_DSN et MAILER_FROM_ADDRESS) : rien n’est parti.');

            return $retour;
        }
        $adresse = DestinatairesRapports::adresse($moi);
        if (null === $adresse) {
            $this->addFlash('danger', 'Votre fiche employé n’a pas d’adresse e-mail valable : rien n’est parti.');

            return $retour;
        }
        if ($this->envois->essaisRecents($moi, $maintenant) >= EnvoiRapports::ESSAIS_PAR_HEURE) {
            $this->addFlash('warning', \sprintf('Déjà %d essais dans l’heure : réessayez un peu plus tard.', EnvoiRapports::ESSAIS_PAR_HEURE));

            return $retour;
        }

        $envoi = $this->envois->essai($moi, $p, $maintenant);
        $ligne = $envoi->getLignes()[0];
        if (EnvoiRapport::LIGNE_ENVOYE === $ligne['statut']) {
            $this->addFlash('success', \sprintf('Essai envoyé à %s%s.', $adresse, $this->reglages->transportFactice() ? ' (transport de test : rien n’arrive vraiment)' : ''));
        } else {
            $this->addFlash('danger', 'L’essai n’est pas parti : '.($ligne['erreur'] ?? 'erreur inconnue').'.');
        }

        return $retour;
    }

    /** La direction choisit qui reçoit les rapports. */
    #[Route('/destinataires', name: '_destinataires', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function destinataires(Request $request, Parametres $parametres): Response
    {
        $this->verifierJeton($request);
        $parDefaut = 'defaut' === $request->request->getString('mode');
        $demandes = array_map('intval', array_filter((array) $request->request->all('destinataires'), 'is_numeric'));
        $ids = $parDefaut ? [] : $this->destinataires->filtrer(array_values($demandes));

        $resultat = $parametres->enregistrer(ReglagesRapportsMail::TUILE, [
            'destinataires_choisis' => !$parDefaut,
            'destinataires' => ReglagesRapportsMail::ecrireIds($ids),
        ], $this->utilisateur());

        $refuses = \count(array_unique($demandes)) - \count($ids);
        match ($resultat) {
            'applique' => $this->addFlash('success', $parDefaut
                ? 'Destinataires : la direction (comptes avec une adresse e-mail). Le changement est gardé dans le journal.'
                : \sprintf('%d destinataire%s enregistré%s. Le changement est gardé dans le journal.', \count($ids), \count($ids) > 1 ? 's' : '', \count($ids) > 1 ? 's' : '')),
            'propose' => $this->addFlash('warning', 'Changement envoyé : il sera appliqué après validation sur l’ERP local.'),
            default => $this->addFlash('info', 'Rien n’a changé.'),
        };
        if (!$parDefaut && $refuses > 0) {
            $this->addFlash('warning', \sprintf('%d compte%s écarté%s : désactivé ou sans le droit « Chiffre d’affaires global ».', $refuses, $refuses > 1 ? 's' : '', $refuses > 1 ? 's' : ''));
        }

        return $this->redirectToRoute('app_rapports_mail', ['_fragment' => 'destinataires']);
    }

    /** Renvoie un rapport aux seuls destinataires en échec. */
    #[Route('/envoi/{id}/reprendre', name: '_reprendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reprendre(EnvoiRapport $envoi, Request $request): Response
    {
        $this->verifierJeton($request);
        if ($envoi->isEssai() || [] === $envoi->aReprendre()) {
            $this->addFlash('info', 'Aucun destinataire à reprendre pour cet envoi.');
        } elseif (!$this->reglages->courrielConfigure()) {
            $this->addFlash('danger', 'L’envoi d’e-mails n’est pas réglé sur cette machine : rien n’est parti.');
        } else {
            $avant = $envoi->nombre(EnvoiRapport::LIGNE_ENVOYE);
            $this->envois->reprendre($envoi, new \DateTimeImmutable());
            $partis = $envoi->nombre(EnvoiRapport::LIGNE_ENVOYE) - $avant;
            $this->addFlash($partis > 0 ? 'success' : 'danger', \sprintf('%d e-mail%s renvoyé%s, %d encore en échec.', $partis, $partis > 1 ? 's' : '', $partis > 1 ? 's' : '', $envoi->nombre(EnvoiRapport::LIGNE_ECHEC)));
        }

        return $this->redirectToRoute('app_rapports_mail', ['_fragment' => 'historique']);
    }

    /**
     * La période demandée si elle a commencé et date de moins de deux ans ;
     * sinon : aujourd'hui pour le rapport du jour, la semaine ou le mois
     * passé pour les autres (ce que le dernier e-mail a montré).
     */
    public static function periodeDemandee(string $type, string $code, \DateTimeImmutable $maintenant): PeriodeRapport
    {
        $p = '' === $code ? null : PeriodeRapport::lire($type, $code);
        if (null !== $p && $p->debut <= $maintenant && $p->fin > $maintenant->modify(\sprintf('-%d years', self::ANS_EN_ARRIERE))) {
            return $p;
        }
        $courante = PeriodeRapport::contenant($type, $maintenant);

        return PeriodeRapport::JOUR === $type ? $courante : $courante->avant();
    }

    /**
     * La version montrée : la sienne, ou pour la direction celle d'un
     * autre destinataire possible (?pour=12).
     */
    private function personneVue(Request $request): User
    {
        $moi = $this->utilisateur();
        $id = $request->query->getInt('pour');
        if ($id <= 0 || $id === $moi->getId() || !$this->isGranted('ROLE_ADMIN')) {
            return $moi;
        }
        $autre = $this->em->find(User::class, $id);
        if (null === $autre || !$autre->isActif() || !$this->destinataires->droits($autre)['rapport']) {
            throw $this->createNotFoundException('Pas de rapport pour ce compte.');
        }

        return $autre;
    }

    private function utilisateur(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
