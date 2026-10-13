# Site vitrine (maquettes 01 à 12)

Jalon J6. Le site public de Succès Imprim, servi par la même application que l'espace client.

## Adresses

| Page | Maquette | Adresse |
|---|---|---|
| Accueil | 01 | « / » en ligne pour un visiteur ; /site sur l'ERP local (aperçu) |
| Produits | 02 | /nos-produits |
| Calculateur | 03 | /calculateur |
| Réalisations | 04 | /realisations |
| Nos équipements | 05 | /equipements |
| Préparer son fichier | 06 | /guide-fichiers |
| Qui sommes-nous ? | 07 | /a-propos |
| Nos engagements | 08 | /nos-engagements |
| Questions fréquentes | 09 | /faq |
| Suivre ma commande | 10 | /suivre-ma-commande |
| Contact, demande de devis | 11 | /contact, /demander-un-devis |
| Conseils | 12 | /conseils |

Ces adresses sont publiques (`config/packages/security.yaml`). Une page pas encore construite n'apparaît ni dans le menu ni dans le pied de page : `site_lien()` renvoie null tant que sa route n'existe pas.

Sur « / », un membre du personnel connecté voit toujours le tableau de bord. Un visiteur voit l'accueil du site en ligne et la page de connexion sur l'ERP local.

## Gabarit

- `templates/site/_site.html.twig` : en-tête (menu, « Espace client », « Demander un devis »), pied de page, bouton WhatsApp flottant. Une page définit `title`, `description`, `contenu` et la variable `menu_actif`.
- `templates/site/_bandeau.html.twig` : bandeau bleu des pages intérieures (fil d'Ariane, titre, accroche).
- `public/css/site.css` : une seule feuille, sans police ni bibliothèque externe.
- `App\Twig\SiteExtension` : menu, pied de page, entreprise affichée (code `mdg_success`), lien WhatsApp.

## Entreprise affichée

Le pied de page affiche « Succès Imprim est un nom commercial de Madial Group SARL », le RCCM, le NIF, l'adresse et le téléphone de l'entreprise `mdg_success` (Réglages › Entreprises). Ces coordonnées publiques descendent au site par la synchronisation ; la banque, la numérotation, les logos et la signature restent au local.

## Rapidité (objectif : moins de 3 s en 3G)

- Logo en WebP (21 Ko au lieu de 107 Ko), illustration de l'accueil en SVG dans la page.
- Pages sans formulaire : `Cache-Control: public, max-age=300`. « / » : `no-cache` avec ETag (réponse 304 si rien n'a changé), car la même adresse sert le tableau de bord après connexion.
- Une page qui démarre la session (jeton CSRF d'un formulaire) reste privée : `SiteController::cache()` ne la rend pas publique.

## Ce que le site n'affiche jamais

Montants d'un client, noms de clients sans accord, coûts, marges, prix B2B. Pas de note Google inventée : la bande d'avis n'apparaîtra qu'avec le lien de la vraie fiche.
