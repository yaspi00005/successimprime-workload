<?php

namespace App\Twig;

use App\Service\Site\ContactSite;
use App\Service\Site\PhotosSite;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Venir à l'imprimerie (vraies photos du local, docs/site-vitrine.md) :
 * bandeau « Venez nous voir » de l'accueil (adresse, horaires du réglage
 * Horaires, Itinéraire, WhatsApp) et galerie « Notre local » de la page
 * Contact. Rien de personnel : la page reste en cache comme les autres.
 */
final class SiteVisiteExtension extends AbstractExtension
{
    /** @var array<string, mixed>|null */
    private ?array $visite = null;

    public function __construct(
        private readonly ContactSite $contact,
        private readonly SiteExtension $site,
        private readonly PhotosSite $photos,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_visite', $this->visite(...)),
            new TwigFunction('site_photos_local', $this->photos->local(...)),
        ];
    }

    /**
     * Coordonnées publiques de l'imprimerie, lues une fois par page (bandeau
     * du haut, « Venez nous voir », pied de page, Contact) : nom, adresse et
     * repère (tuile « Site : contact et accueil »), horaires avec les jours
     * fermés et l'état en ce moment, téléphone (lien tel:), e-mail public
     * de l'entité (null s'il n'est pas rempli : rien d'inventé), itinéraire,
     * WhatsApp.
     *
     * @return array{nom: string, adresse: string, repere: ?string, ville: string, horaires: array{texte: string, fermes: ?string, court: string, ouvert: bool, etat: string}, itineraire: string, whatsapp: ?string, telephone: ?string, tel: ?string, email: ?string, delaiReponse: ?string}
     */
    public function visite(): array
    {
        if (null !== $this->visite) {
            return $this->visite;
        }
        $entreprise = $this->site->entreprise();
        $nom = $entreprise?->getNomAffiche() ?: 'Succès Imprim';
        $adresse = trim((string) $entreprise?->getAdresse()) ?: 'Bamako, Mali';
        $telephone = trim((string) $entreprise?->getTelephone());
        $email = trim((string) $entreprise?->getEmail());

        return $this->visite = [
            'nom' => $nom,
            'adresse' => $adresse,
            'repere' => $this->contact->repereAdresse(),
            // « Sotuba ACI, Bamako » pour le bandeau du haut : l'adresse remise dans l'ordre quartier, ville.
            'ville' => self::adresseCourte($adresse),
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
            'itineraire' => $this->contact->itineraire($nom, $adresse),
            'whatsapp' => $this->site->whatsapp('Bonjour '.$nom.', je voudrais passer à l\'imprimerie : '),
            'telephone' => '' === $telephone ? null : $telephone,
            'tel' => '' === $telephone ? null : 'tel:'.preg_replace('/[^\d+]/', '', $telephone),
            'email' => false === filter_var($email, \FILTER_VALIDATE_EMAIL) ? null : $email,
            'delaiReponse' => $this->contact->delaiReponse(),
        ];
    }

    /** « Bamako, Sotuba ACI » devient « Sotuba ACI, Bamako » ; une autre adresse reste telle quelle. */
    public static function adresseCourte(string $adresse): string
    {
        if (preg_match('/^\s*Bamako\s*[,\-–]\s*(.+)$/iu', $adresse, $m)) {
            return trim($m[1]).', Bamako';
        }

        return $adresse;
    }
}
