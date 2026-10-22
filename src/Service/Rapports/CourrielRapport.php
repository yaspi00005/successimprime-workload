<?php

namespace App\Service\Rapports;

use App\Entity\User;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Objectifs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Met en forme le rapport d'une personne (docs/rapports-mail.md) : e-mail
 * HTML lisible sur un téléphone, la même chose en texte simple, et le PDF
 * joint. L'aperçu de l'écran montre exactement le même HTML.
 *
 * Le sujet ne donne aucun chiffre : il s'affiche dans les notifications
 * des téléphones.
 */
final class CourrielRapport
{
    public const MODELE_HTML = 'rapports_mail/courriel.html.twig';
    public const MODELE_TEXTE = 'rapports_mail/courriel.txt.twig';
    public const MODELE_PDF = 'rapports_mail/pdf.html.twig';

    public function __construct(
        private readonly RapportPeriodique $rapport,
        private readonly ReglagesRapportsMail $reglages,
        private readonly ExportPdf $pdf,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requetes,
    ) {
    }

    /**
     * Ce que les modèles reçoivent pour une personne et une période.
     *
     * @param array<string, bool> $droits DroitsRapport
     * @param list<string>        $roles
     *
     * @return array<string, mixed>
     */
    public function contexte(User $pour, array $droits, array $roles, PeriodeRapport $p, \DateTimeImmutable $maintenant, bool $essai = false): array
    {
        $entreprise = $this->pdf->entreprise();

        return [
            'r' => $this->rapport->donnees($pour, $droits, $roles, $p, $maintenant),
            'p' => $p,
            'prenom' => self::prenom($pour),
            'version' => DroitsRapport::version($droits),
            'contenu' => DroitsRapport::contenu($droits),
            'marque' => ['nom' => $entreprise->getNomAffiche(), 'couleurs' => $entreprise->getCouleurs()],
            'lien' => $this->lien($p),
            'genere_le' => $maintenant,
            'essai' => $essai,
        ];
    }

    /** @param array<string, mixed> $contexte */
    public function html(array $contexte): string
    {
        return $this->twig->render(self::MODELE_HTML, $contexte);
    }

    /** @param array<string, mixed> $contexte */
    public function texte(array $contexte): string
    {
        return $this->twig->render(self::MODELE_TEXTE, $contexte);
    }

    /** @param array<string, mixed> $contexte */
    public function pdf(array $contexte, \DateTimeImmutable $editeLe, string $editePar): string
    {
        return $this->pdf->rendre(self::MODELE_PDF, $contexte, $editeLe, $editePar);
    }

    /**
     * L'e-mail prêt à partir.
     *
     * @param array<string, mixed> $contexte
     */
    public function email(Address $de, string $a, array $contexte, bool $pieceJointe): Email
    {
        /** @var PeriodeRapport $p */
        $p = $contexte['p'];
        $email = (new Email())
            ->from($de)
            ->to($a)
            ->subject(self::sujet($p, (bool) ($contexte['essai'] ?? false)))
            ->text($this->texte($contexte))
            ->html($this->html($contexte));
        // Message automatique : pas de réponse d'absence en retour.
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        if ($pieceJointe) {
            $email->attach($this->pdf($contexte, $contexte['genere_le'], 'envoi automatique'), self::nomPdf($p), 'application/pdf');
        }

        return $email;
    }

    /** Oublie les chiffres déjà calculés, à la fin d'un envoi. */
    public function oublier(): void
    {
        $this->rapport->oublier();
    }

    /** « Rapport du jour · samedi 17 octobre 2026 · Succès Imprim », sans aucun chiffre. */
    public static function sujet(PeriodeRapport $p, bool $essai = false): string
    {
        return ($essai ? '[Essai] ' : '').$p->sujet().' · Succès Imprim';
    }

    /** « rapport-jour-2026-10-17.pdf ». */
    public static function nomPdf(PeriodeRapport $p): string
    {
        return 'rapport-'.$p->type.'-'.strtolower($p->code()).'.pdf';
    }

    private static function prenom(User $u): string
    {
        $prenom = trim((string) $u->getEmploye()?->getPrenom());

        return '' !== $prenom ? $prenom : Objectifs::nomAgent($u);
    }

    /**
     * Lien vers l'aperçu de cette période dans l'ERP : l'adresse des
     * Réglages, sinon celle d'où l'essai a été demandé ; aucun lien pour un
     * envoi automatique sans adresse réglée (« localhost » ne mènerait
     * nulle part depuis un téléphone).
     */
    private function lien(PeriodeRapport $p): ?string
    {
        $chemin = $this->urls->generate('app_rapports_mail_apercu', ['type' => $p->type, 'periode' => $p->code()]);
        $base = $this->urls->getContext()->getBaseUrl();
        $adresse = $this->reglages->adresseErp();
        if (null !== $adresse) {
            return $adresse.('' !== $base && str_starts_with($chemin, $base) ? substr($chemin, \strlen($base)) : $chemin);
        }
        $requete = $this->requetes->getMainRequest();

        return null === $requete ? null : $requete->getSchemeAndHttpHost().$chemin;
    }
}
