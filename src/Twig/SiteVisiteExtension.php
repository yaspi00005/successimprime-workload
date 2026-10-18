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
     * @return array{nom: string, adresse: string, horaires: array{texte: string, ouvert: bool, etat: string}, itineraire: string, whatsapp: ?string}
     */
    public function visite(): array
    {
        $entreprise = $this->site->entreprise();
        $nom = $entreprise?->getNomAffiche() ?: 'Succès Imprim';
        $adresse = trim((string) $entreprise?->getAdresse()) ?: 'Bamako, Mali';

        return [
            'nom' => $nom,
            'adresse' => $adresse,
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
            'itineraire' => $this->contact->itineraire($nom, $adresse),
            'whatsapp' => $this->site->whatsapp('Bonjour '.$nom.', je voudrais passer à l\'imprimerie : '),
        ];
    }
}
