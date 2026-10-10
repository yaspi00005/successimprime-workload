# Site public : chiffres et partenaires

Demande de Yaya du 10/10/2026. Elle complète les maquettes 01 à 12 du site.

## Chiffres affichés

Sur l'accueil et sur la page « À propos » :
- nombre de commandes livrées ;
- nombre de clients servis ;
- nombre de projets réalisés.

Ce sont les mêmes calculs que l'écran TV (`App\Service\EcranTv`) : uniquement des volumes, jamais de montants ni de noms de clients. Les compteurs défilent jusqu'à leur valeur quand ils apparaissent à l'écran. Ils sont mis à jour une fois par heure.

« Projets réalisés » = commandes livrées comptées une seule fois par client et par mois. Une commande de 3 bâches pour un même événement compte donc pour un projet. La règle se règle dans l'admin si Yaya préfère compter chaque commande livrée.

## Partenaires

Une bande de logos « Ils nous font confiance » sur l'accueil, et une page Partenaires.
- **Gérés dans Réglages, comme les médias de l'écran TV** : nom, logo, lien (facultatif), ordre, visible ou non.
- On n'affiche un logo qu'avec l'accord du partenaire. Une case « accord obtenu » est obligatoire pour publier.
- Un partenaire peut aussi passer dans le cadre « Dernières réalisations » de l'écran TV.

## Quand

Avec le site public, après l'espace client (J5).
