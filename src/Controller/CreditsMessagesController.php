<?php

namespace App\Controller;

use App\Entity\EnvoiMessage;
use App\Entity\User;
use App\Repository\EnvoiMessageRepository;
use App\Security\AccesEnLigne;
use App\Service\Messagerie\AlertesMessages;
use App\Service\Messagerie\CreditsMessages;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Crédits SMS et e-mail (docs/messagerie-marketing.md, demande
 * de Yaya du 16/10/2026) : SMS disponibles et fin du pack, envois e-mail
 * face aux limites de l'hébergeur, historique des envois.
 *
 * Direction et droit « Crédits SMS et e-mail ». Le destinataire complet
 * (numéro, adresse), l'objet des e-mails (un modèle peut y mettre le nom
 * du client) et la recherche demandent en plus le droit Clients ; les prix
 * des packs, le droit « Trésorerie et soldes ».
 */
#[Route('/communications/credits', name: 'app_credits_messages_')]
#[IsGranted('ROLE_CREDITS_MESSAGES')]
final class CreditsMessagesController extends AbstractController
{
    public const PAR_PAGE = 50;
    public const EXPORT_MAX = 20000;
    public const DERNIERS = 8;
    private const CSRF = 'credits_messages';

    public function __construct(
        private readonly CreditsMessages $credits,
        private readonly AlertesMessages $alertes,
        private readonly EnvoiMessageRepository $envois,
        private readonly Parametres $parametres,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('credits_messages/index.html.twig', [
            'sms' => $this->credits->sms($maintenant),
            'email' => $this->credits->email($maintenant),
            'seuils' => $this->credits->seuils(),
            'derniers' => $this->envois->rechercher([], 1, self::DERNIERS)['lignes'],
            'maintenant' => $maintenant,
            'enLigne' => $this->acces->estEnLigne(),
        ] + $this->droitsDeLaPage());
    }

    /** Relit le solde et les achats chez Orange (au plus une fois par minute), puis vérifie les alertes. */
    #[Route('/actualiser', name: 'actualiser', methods: ['POST'])]
    public function actualiser(Request $request): Response
    {
        $this->verifierJeton($request);
        $maintenant = new \DateTimeImmutable();
        $sms = $this->credits->sms($maintenant, true);
        if (!$sms['configure']) {
            $this->addFlash('warning', 'Clés Orange SMS non configurées : le solde se saisit à la main.');
        } elseif (null !== $sms['erreur']) {
            $this->addFlash('danger', 'Lecture chez Orange impossible : '.$sms['erreur']);
        } else {
            $this->addFlash('success', 'Solde lu chez Orange.');
        }
        $this->verifierAlertes($maintenant);

        return $this->redirectToRoute('app_credits_messages_index');
    }

    /**
     * Saisie à la main : solde et fin du pack SMS (sans les clés Orange),
     * fin de l'abonnement e-mail. Rangée dans la tuile « Crédits SMS et
     * e-mail » (journal des réglages ; depuis l'admin en ligne, proposée
     * au local).
     */
    #[Route('/saisie', name: 'saisie', methods: ['POST'])]
    public function saisie(Request $request): Response
    {
        $this->verifierJeton($request);
        $quoi = (string) $request->request->get('quoi');
        $cles = match ($quoi) {
            'sms' => ['sms_solde_manuel', 'sms_expiration_manuelle'],
            'email' => ['email_expiration'],
            default => throw $this->createNotFoundException(),
        };
        $champs = array_values(array_filter(CatalogueParametres::tuiles()[CreditsMessages::TUILE]['champs'], static fn (array $c): bool => \in_array($c['cle'], $cles, true)));
        $lecture = CatalogueParametres::lireSaisie($champs, $request->request->all());
        if ([] !== $lecture['erreurs']) {
            foreach ($lecture['erreurs'] as $cle => $erreur) {
                $this->addFlash('danger', (CatalogueParametres::champ(CreditsMessages::TUILE, $cle)['libelle'] ?? $cle).' : '.$erreur);
            }

            return $this->redirectToRoute('app_credits_messages_index');
        }
        $maintenant = new \DateTimeImmutable();
        $valeurs = $lecture['valeurs'];
        if ('sms' === $quoi) {
            // Les SMS partis après cette heure seront retirés du solde saisi.
            $valeurs['sms_saisi_le'] = $maintenant->format('Y-m-d H:i:s');
        }
        $par = $this->getUser();
        $resultat = $this->parametres->enregistrer(CreditsMessages::TUILE, $valeurs, $par instanceof User ? $par : null);
        match ($resultat) {
            'applique' => $this->addFlash('success', 'sms' === $quoi ? 'Solde SMS enregistré.' : 'Fin de l\'abonnement e-mail enregistrée.'),
            'propose' => $this->addFlash('warning', 'Changement envoyé : il sera appliqué après validation sur l\'ERP local.'),
            default => $this->addFlash('info', 'Rien n\'a changé.'),
        };
        if ('applique' === $resultat) {
            $this->verifierAlertes($maintenant);
        }

        return $this->redirectToRoute('app_credits_messages_index');
    }

    #[Route('/envois', name: 'envois', methods: ['GET'])]
    public function envois(Request $request): Response
    {
        $filtres = $this->filtres($request);
        $page = max(1, $request->query->getInt('page', 1));
        $resultat = $this->envois->rechercher($filtres, $page, self::PAR_PAGE);

        return $this->render('credits_messages/envois.html.twig', [
            'lignes' => $resultat['lignes'],
            'total' => $resultat['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($resultat['total'] / self::PAR_PAGE)),
            'filtres' => $filtres,
            'parametres' => self::parametresAdresse($filtres),
        ] + $this->droitsDeLaPage());
    }

    /** Export CSV de l'historique filtré (mêmes droits et même masquage que l'écran). */
    #[Route('/envois/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $filtres = $this->filtres($request);
        $complet = $this->isGranted('ROLE_CLIENT');
        $personnel = $this->isGranted('ROLE_ADMIN');
        $lignes = $this->envois->pourExport($filtres, self::EXPORT_MAX);

        // En mémoire (20 000 lignes au plus, quelques Mo) : la recette des accès relit le fichier.
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, ['Date', 'Canal', 'Type', 'Objet', 'Destinataire', 'État', 'Erreur', 'Unités', 'Envoyé depuis'], ';', '"', '');
        foreach ($lignes as $e) {
            /** @var EnvoiMessage $e */
            fputcsv($f, [
                $e->getEnvoyeLe()->format('d/m/Y H:i'),
                $e->getLibelleCanal(),
                $e->getLibelleType(),
                $complet ? self::cellule((string) $e->getSujet()) : '',
                self::destinataireCsv($e->destinataireVisible($complet, $personnel)),
                $e->getLibelleStatut(),
                self::cellule((string) $e->getErreur()),
                $e->getUnites(),
                $e->getLibelleOrigine(),
            ], ';', '"', '');
        }
        rewind($f);
        $reponse = new Response((string) stream_get_contents($f));
        fclose($f);
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="envois-sms-email-'.(new \DateTimeImmutable())->format('Y-m-d').'.csv"');

        return $reponse;
    }

    /**
     * Tableur : « +223 76… » serait lu comme une formule ; l'indicatif
     * s'écrit « 00223 ». Une adresse qui commence par « + » reste telle
     * quelle, protégée comme le reste.
     */
    public static function destinataireCsv(string $destinataire): string
    {
        return 1 === preg_match('/^\+[\d •]+$/u', $destinataire) ? '00'.substr($destinataire, 1) : self::cellule($destinataire);
    }

    /** Un texte qui commence par = + - @ ne doit jamais devenir une formule dans le tableur. */
    public static function cellule(string $texte): string
    {
        return '' !== $texte && \in_array($texte[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$texte : $texte;
    }

    /**
     * Filtres lus dans l'adresse ; une valeur inconnue est ignorée. La
     * recherche (destinataire et objet) demande le droit Clients : sans
     * lui, ni l'un ni l'autre n'est affiché, et ne doit se deviner. De
     * même, le destinataire d'un message au personnel ne se cherche qu'avec
     * la direction.
     *
     * @return array{canal: string, statut: string, type: string, origine: string, du: ?\DateTimeImmutable, au: ?\DateTimeImmutable, q: string, parDestinataire: bool, personnel: bool}
     */
    private function filtres(Request $request): array
    {
        $choix = static function (string $cle, array $permis) use ($request): string {
            $valeur = (string) $request->query->get($cle, '');

            return isset($permis[$valeur]) ? $valeur : '';
        };
        $date = static function (string $cle) use ($request): ?\DateTimeImmutable {
            $texte = (string) $request->query->get($cle, '');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $texte);

            return false !== $d && $d->format('Y-m-d') === $texte ? $d : null;
        };

        return [
            'canal' => $choix('canal', EnvoiMessage::CANAUX),
            'statut' => $choix('statut', EnvoiMessage::STATUTS),
            'type' => $choix('type', EnvoiMessage::TYPES),
            'origine' => $choix('origine', EnvoiMessage::ORIGINES),
            'du' => $date('du'),
            'au' => $date('au'),
            'q' => $this->isGranted('ROLE_CLIENT') ? mb_substr(trim((string) $request->query->get('q', '')), 0, 80) : '',
            'parDestinataire' => $this->isGranted('ROLE_CLIENT'),
            'personnel' => $this->isGranted('ROLE_ADMIN'),
        ];
    }

    /**
     * @param array{canal: string, statut: string, type: string, origine: string, du: ?\DateTimeImmutable, au: ?\DateTimeImmutable, q: string, parDestinataire: bool, personnel: bool} $filtres
     *
     * @return array<string, string>
     */
    private static function parametresAdresse(array $filtres): array
    {
        return array_filter([
            'canal' => $filtres['canal'],
            'statut' => $filtres['statut'],
            'type' => $filtres['type'],
            'origine' => $filtres['origine'],
            'du' => $filtres['du']?->format('Y-m-d') ?? '',
            'au' => $filtres['au']?->format('Y-m-d') ?? '',
            'q' => $filtres['q'],
        ], static fn (string $v): bool => '' !== $v);
    }

    /**
     * voirDestinataire : numéro, adresse et objet en entier (droit Clients) ;
     * voirPersonnel : ceux d'un message au personnel (direction seulement).
     *
     * @return array{voirDestinataire: bool, voirPersonnel: bool, voirPrix: bool, peutRegler: bool}
     */
    private function droitsDeLaPage(): array
    {
        return [
            'voirDestinataire' => $this->isGranted('ROLE_CLIENT'),
            'voirPersonnel' => $this->isGranted('ROLE_ADMIN'),
            'voirPrix' => $this->isGranted('ROLE_TRESORERIE_VOIR'),
            'peutRegler' => $this->parametres->peutVoir(CreditsMessages::TUILE),
        ];
    }

    /** Alertes (cloche) : à l'ERP de la boutique, où est la direction. */
    private function verifierAlertes(\DateTimeImmutable $maintenant): void
    {
        if (!$this->acces->estEnLigne()) {
            $this->alertes->verifier($maintenant);
        }
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
