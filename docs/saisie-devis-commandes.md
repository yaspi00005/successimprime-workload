# Modifier un devis ou une commande enregistré

Ce que fait l'enregistrement d'un devis ou d'une commande depuis la saisie du comptoir (`/devis/{id}/edit`, `/commandes/{id}/edit`), et le calcul des pièces au m² des lignes au m².

Pour un devis, l'écran de saisie (maquette GC04), les versions, l'envoi au client et les relances sont dans [docs/devis.md](devis.md). Le statut ne se choisit plus dans le formulaire : « Enregistrer le brouillon » garde un brouillon, « Envoyer au client » l'envoie. Modifier un devis déjà envoyé prépare sa version suivante ; un devis accepté ne se modifie plus.

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

## Total d'origine conservé

Après la bascule de la base de la boutique, une ligne dont la remise a été convertie (pourcentage → F par unité) et dont le nouveau calcul ne redonne pas exactement le total enregistré garde ce total (décision « Garder le total », [docs/migration-de-base.md](migration-de-base.md#garder-le-total)). La fiche et la saisie l'indiquent discrètement : « Total d'origine conservé ».

- Enregistrer sans rien changer : le total enregistré reste, même si l'aperçu de la page affiche le nouveau calcul.
- Changer la quantité, le prix, la remise (y compris celle d'une promotion ou d'un code promo), la TVA, le mode de calcul, les dimensions ou les finitions : la ligne est recalculée et redevient une ligne ordinaire.
- Un devis accepté dont une ligne garde son total le passe à la ligne de la commande créée.
- Sur un devis ou une facture, la remise affichée de la ligne est ce qui a vraiment été retiré (brut − total), pour que brut − remise = total.

Dans le code : `TotalFigeTrait` (colonne `total_fige` de `commandes_details` et `devis_details`).

## Nom commercial du produit

Les listes de produits, de configurations, de supports, de types d'impression et d'articles montrent la désignation suivie du nom commercial (« Vinyle adhésif (Autocollant) ») ; leur recherche trouve l'un ou l'autre, sans tenir compte des accents, des majuscules ni du pluriel. Choisir un produit écrit sa désignation technique seule dans la ligne : le nom commercial s'ajoute devant à l'affichage pour le client (devis et facture PDF, espace client), docs/noms-commerciaux.md.

## Les erreurs

Toutes les erreurs s'affichent en haut du formulaire, celles des lignes comprises. Une erreur de dimensions dit quelle ligne est en cause, par sa place et sa désignation :

> Ligne 2 « Bâche imprimée » : la largeur et la longueur sont obligatoires pour un calcul au mètre carré.

## Pièces au m² (petites pièces)

Demande de Yaya du 16/10/2026 : pour les autocollants, étiquettes et autres petites pièces vendues au m², savoir combien de pièces tiennent sur 1 m × 1 m en comptant 1 mm d'espacement en largeur et en hauteur, et pouvoir donner la quantité en pièces ou en m², au comptoir comme en ligne.

**Le calcul**
- Sur une rangée de 1 000 mm, n pièces de L mm prennent n × L + (n − 1) × e (e : l'espacement). Donc n = plancher((1 000 + e) ÷ (L + e)) en largeur, pareil en hauteur, et pièces au m² = colonnes × rangées.
- La pièce tournée d'un quart de tour est essayée aussi, la meilleure est gardée (à égalité, le sens saisi).
- Une pièce plus grande que 1 m d'un côté donne 0 (« pièce plus grande que 1 m ») : la ligne reste au calcul au m² habituel.
- Exemples, espacement 1 mm : 5 × 5 cm → 19 × 19 = 361 pièces au m² ; carte 8,5 × 5,5 cm → 11 × 17 = 187 (autant dans l'autre sens) ; 100 × 100 cm → 1 ; 100,1 × 1 cm → 0.
- Quantité en pièces : surface facturée = pièces ÷ pièces au m², arrondie au centième de m² supérieur (1 000 autocollants de 5 × 5 cm : 2,78 m², contre 2,50 m² au calcul habituel).
- Quantité en m² : pièces = plancher(m² × pièces au m²) (2,5 m² de 5 × 5 cm : 902 pièces, facturées 2,50 m²).

**Le réglage** : Réglages › Paramètres › Ventes › « Pièces au m² » (Direction) : espacement en mm, de 0 à 20, au dixième, 1 mm par défaut. Il descend sur le site (liste blanche `pieces_m2`). Le changer ne touche pas une ligne déjà en pièces au m² : elle garde l'espacement avec lequel elle a été chiffrée.

**Au comptoir** (saisie d'un devis ou d'une commande)
- Sous une ligne produit au m² dont la largeur et la longueur sont saisies, l'encadré « Pièces au m² » dit combien de pièces tiennent sur 1 m² : « 361 pièces au m² (19 × 19, espacement 1 mm) ».
- Case décochée (par défaut) : rien ne change, calcul habituel (surface d'une pièce × quantité). L'encadré dit seulement ce que la case facturerait : « Calcul habituel : 2,50 m². Cochez pour facturer 2,78 m², espacement et chutes compris. »
- Case cochée : la ligne est facturée sur la surface en pièces au m², et l'on choisit « Quantité saisie en pièces » ou « en m² ». En m², on tape la surface voulue : la quantité (pièces) est calculée et ne se tape plus ; changer la taille refait les pièces depuis la même surface. Les deux valeurs s'affichent en direct (« 2,50 m² = 902 pièces, facturées 2,50 m² »), et le récapitulatif de la ligne dit « Surface facturée … m² (pièces au m²) ».
- La quantité de la ligne reste toujours le nombre de pièces : production, livraison et finitions comptées par pièce ne changent pas. Seul le nombre de m² facturés change : prix au m² × surface facturée, et une remise par m² suit la même surface.
- Le serveur refait tout à l'enregistrement : de la page, il ne reprend que la case (champ caché `piecesAuM2`, « 1 » ou « 0 » ; absent, rien ne change). L'espacement vient de la ligne si elle l'avait déjà, sinon du réglage. La case n'est retenue que pour une ligne produit au m² dont la pièce tient sur 1 m ; une ligne passée à un autre mode de calcul revient au calcul habituel.
- Fiche du devis, fiche de la commande : « 361 pièces au m² (19 × 19, espacement 1 mm) · 2,78 m² facturés ». PDF du devis et de la facture : « 2,78 m² · 361 pièces au m² ». Jamais de montant dans ces mentions.
- Devis accepté → commande : la ligne garde son mode de calcul et son espacement. Correction au passage : avant, une ligne au m² devenait « unité » dans la commande et se recalculait prix × quantité au premier enregistrement.
- Total d'origine conservé : cocher ou décocher la case change le calcul, la ligne est recalculée ; réenregistrée sans rien changer, elle garde son total.
- Ce qui lit la surface de la ligne (`getSurfaceTotale` : PDF, planning des machines, fidélité au m², statistiques par machine et rapports) compte la surface facturée. Les totaux de m² comptés directement en base (rapport de la semaine, objectifs, écran TV, parc des machines) gardent surface d'une pièce × quantité : l'écart est l'espacement.

**En ligne** : demande de devis sans compte, voir [docs/site-vitrine.md](site-vitrine.md), « Demander un devis » ; Nouvelle commande de l'espace client, voir [docs/espace-client.md](espace-client.md), section « Quantité en pièces ou en m² ».

**À ne pas confondre** avec la quantité de « Mes produits » (`ConversionQuantite`, docs/mes-produits.md) : elle passe des m² aux pièces avec la surface de la pièce seule, sans espacement, et ne change pas.

Dans le code : `PiecesParMetreCarre` (calcul et réglage, testé sans base), `PiecesAuM2Trait` (colonne `espacement_pieces_m2` de `commandes_details` et `devis_details`, migration `Version20261018161500`), `ChampPiecesAuM2` (case des formulaires), `PiecesM2Extension` (`pieces_m2_espacement()`, `pieces_m2_ligne()`), gabarits `partials/_pieces_m2_ligne.html.twig` (comptoir) et `partials/_pieces_m2.html.twig` (site, espace client), `public/js/pieces-m2.js` (même calcul dans la page, sans prix), `public/css/pieces-m2.css`.

## À trancher

- Finition au m² : le serveur compte prix × quantité, l'aperçu du devis compte prix × quantité × surface, et une commande en ligne prix × surface × exemplaires. Le serveur fait foi à l'enregistrement.
- Pièces au m² : la case se coche ligne par ligne, jamais d'office. La cocher d'office sous une certaine taille de pièce ? (Au-dessus, ce serait faux : une pièce de 100 × 70 cm tient une fois au m² et serait facturée 1 m² au lieu de 0,70.)
