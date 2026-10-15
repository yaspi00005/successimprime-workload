# Modifier un devis ou une commande enregistré

Ce que fait l'enregistrement d'un devis ou d'une commande depuis la saisie du comptoir (`/devis/{id}/edit`, `/commandes/{id}/edit`).

## Enregistrer sans rien changer ne change rien

Ouvrir une commande et cliquer sur « Enregistrer les modifications » sans rien toucher garde, ligne par ligne :
- la largeur, la longueur et le format ;
- le prix unitaire et la remise ;
- les finitions et leur montant ;
- le total de la ligne, donc le total de la commande.

Le total du devis ou de la commande est refait par le serveur depuis ses lignes, jamais repris de la page (`Commandes::recalculerTotaux`, `Devis::recalculerTotaux`).

## Les anciennes lignes « automatiques »

Avant la simplification de la saisie, une ligne produit pouvait être « automatique » : le prix, les dimensions et les finitions venaient de la configuration du catalogue. Ce mode n'est plus proposé : toute ligne produit est « manuelle ».

Une ancienne ligne automatique devient manuelle au premier enregistrement, en gardant ce qui était enregistré. La configuration du catalogue n'est plus réappliquée, ni à l'enregistrement, ni au recalcul des totaux (promotion décidée, réveil d'un client). Avant, elle effaçait la largeur et la longueur d'une ligne au m² (refus « La largeur et la longueur sont obligatoires ») et vidait ses finitions.

Dans le code : `ModeConfigurationLigne` (le mode lu à l'envoi du formulaire), `MontantFinitionSaisie` (une finition enregistrée et inchangée garde son montant).

## Les erreurs

Toutes les erreurs s'affichent en haut du formulaire, celles des lignes comprises. Une erreur de dimensions dit quelle ligne est en cause, par sa place et sa désignation :

> Ligne 2 « Bâche imprimée » : la largeur et la longueur sont obligatoires pour un calcul au mètre carré.

## À trancher

- À l'ouverture de la page, une ligne manuelle reprend le prix et la désignation du produit dans le catalogue (comportement déjà là, décrit aussi dans `docs/donnees-demo.md` pour les promotions). Les dimensions et le format enregistrés, eux, restent.
- Finition au m² : le serveur compte prix × quantité, l'aperçu du devis compte prix × quantité × surface, et une commande en ligne prix × surface × exemplaires. Le serveur fait foi à l'enregistrement.
