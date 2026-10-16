# Modifier un devis ou une commande enregistré

Ce que fait l'enregistrement d'un devis ou d'une commande depuis la saisie du comptoir (`/devis/{id}/edit`, `/commandes/{id}/edit`).

## Enregistrer sans rien changer ne change rien

Ouvrir une commande et cliquer sur « Enregistrer les modifications » sans rien toucher garde, ligne par ligne :
- la largeur, la longueur et le format ;
- le prix unitaire et la remise ;
- les finitions et leur montant ;
- le total de la ligne, donc le total de la commande.

Le total du devis ou de la commande est refait par le serveur depuis ses lignes, jamais repris de la page (`Commandes::recalculerTotaux`, `Devis::recalculerTotaux`).

À l'ouverture de la page, une ligne enregistrée garde sa désignation, son prix, sa remise, son mode de calcul, son format et ses dimensions, même si le catalogue a changé depuis. Le catalogue ne s'applique que quand on choisit un autre produit pour la ligne, ou un autre client pour la commande (tarif B2B). Avant, la page reprenait le prix du catalogue et remettait la remise à 0 (ou à la remise B2B du produit) dès l'ouverture, et un simple « Enregistrer » les gardait.

## Les anciennes lignes « automatiques »

Avant la simplification de la saisie, une ligne produit pouvait être « automatique » : le prix, les dimensions et les finitions venaient de la configuration du catalogue. Ce mode n'est plus proposé : toute ligne produit est « manuelle ».

Une ancienne ligne automatique devient manuelle au premier enregistrement, en gardant ce qui était enregistré. La configuration du catalogue n'est plus réappliquée, ni à l'ouverture de la page, ni à l'enregistrement, ni au recalcul des totaux (promotion décidée, réveil d'un client). Avant, elle effaçait la largeur et la longueur d'une ligne au m² (refus « La largeur et la longueur sont obligatoires ») et vidait ses finitions ; ouverte par la Direction, la page remettait aussi le prix et la remise du catalogue.

Dans le code : `ModeConfigurationLigne` (le mode lu à l'envoi du formulaire), `MontantFinitionSaisie` (une finition enregistrée et inchangée garde son montant).

## Les erreurs

Toutes les erreurs s'affichent en haut du formulaire, celles des lignes comprises. Une erreur de dimensions dit quelle ligne est en cause, par sa place et sa désignation :

> Ligne 2 « Bâche imprimée » : la largeur et la longueur sont obligatoires pour un calcul au mètre carré.

## À trancher

- Finition au m² : le serveur compte prix × quantité, l'aperçu du devis compte prix × quantité × surface, et une commande en ligne prix × surface × exemplaires. Le serveur fait foi à l'enregistrement.
