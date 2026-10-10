# Atelier 3D et animations du site

Demandé par Yaya le 10/10/2026, avec un prototype : `/mnt/project-files/maquettes/atelier-3d/atelier-3d-prototype.html` (three.js r128). Il est à intégrer au site public et à l'espace client, avec des animations sur tout le site, « encore mieux » que le prototype.

## Ce que le prototype fait déjà

- **Produit** : t-shirt, polo ou casquette, en 11 couleurs. Pour la casquette, la calotte et la visière ont chacune leur couleur.
- **Logo ou photo du client**, placé devant ou dos, avec la taille et la hauteur réglables. Une alerte prévient si l'image est trop petite pour l'impression.
- **Texte** : la police, la couleur et la place se règlent.
- **Vues** : rotation à la souris ou au doigt, zoom, boutons Devant, Côté, Dos et Rotation.
- **Prix** : quantités par taille, prix immédiat, remises à partir de 25 et 50 pièces.
- **« Valider mon modèle »** : crée les images de face et de dos qui servent de BAT, avec le récapitulatif.

Vérifié le 10/10/2026 avec three.js chargé en local : le rendu 3D s'affiche sur ordinateur et sur téléphone, sans erreur. Deux défauts sont à corriger à l'intégration :

1. **Couleur** : la couleur choisie est rendue plus claire que sa pastille. Par exemple, « Bleu Succès » foncé donne un t-shirt bleu clair. Il faut régler l'encodage des couleurs (sRGB) et l'éclairage.
2. **Mobile** : les deux pastilles du haut de l'aperçu se chevauchent (« Aperçu 3D en temps réel » et l'aide).

## Intégration

| Sujet | Dans l'application |
|---|---|
| Produits, couleurs, tailles | Ils viennent du catalogue (produits « textile » et leurs variantes), pas du code de la page. |
| Prix et remises par quantité | Calculés par le serveur avec la grille tarifaire et les promotions du client (voir [promotions](promotions.md)), jamais par la page seule. |
| Fichier du client | Envoyé au serveur, contrôlé : format, taille, résolution à la taille d'impression. |
| « Valider mon modèle » | Crée une demande de devis, ou une commande pour un client connecté. Les images face et dos sont jointes à la ligne comme BAT à valider par le graphiste. Les réglages (couleur, place, taille, texte) sont gardés pour refaire le même article. |
| Espace client | « Mes créations » : modèles enregistrés, à recommander. Ils rejoignent [Mes produits](mes-produits.md) une fois produits. |
| Admin | Les créations arrivent dans Ventes comme demandes à traiter. Le graphiste voit le rendu et le fichier d'origine. |

## Côté technique

- **Bibliothèque 3D** : three.js est copié dans l'application (comme Leaflet), sans dépendre d'un CDN. On passe à une version récente, chargée seulement sur la page Atelier.
- **Polices** : elles sont hébergées sur le serveur.
- **Vrais modèles** : t-shirt, polo et casquette avec plis et coutures, au format GLB, achetés ou faits par un modélisateur 3D. Le fonctionnement reste le même. Il faut prévoir le budget ou l'achat.
- **Téléphones modestes** : sans 3D possible, ou si la page est trop lente, on affiche des vues à plat (face et dos) avec les mêmes réglages.

## Animations du site

Celles du prototype sont reprises :
- les bandes du logo qui défilent ;
- les blocs qui glissent à l'ouverture ;
- l'entrée du modèle à chaque changement ;
- les boutons qui réagissent au survol ;
- la fenêtre du BAT et les messages qui apparaissent.

Pour aller plus loin :
- **accueil** : produits qui apparaissent au défilement de la page, réalisations avant/après à faire glisser, et le modèle 3D qui tourne dans le bandeau ;
- **produits et calculateur** : le prix qui défile jusqu'au nouveau montant, et une légère inclinaison des cartes produit au survol ;
- **suivi de commande** dans l'espace client : la frise des étapes qui se remplit, et une petite fête quand la commande est prête.

Règles pour toutes les animations :
- elles se coupent si la personne a choisi de réduire les animations sur son téléphone ou son ordinateur ;
- elles ne ralentissent jamais l'affichage : pas d'animation qui bloque un clic, et une page lisible en moins de 2 secondes sur un réseau 3G.

## Quand

Avec le site public, après l'espace client (J5). Le prototype sert de maquette de référence.
