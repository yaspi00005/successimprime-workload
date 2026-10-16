# Factures : en-tête par entreprise, QR de suivi et espace client

Yaya a envoyé 5 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/factures-entetes-qr/`. Les montants, numéros et noms sont fictifs.

| Maquette | Contenu |
|---|---|
| **I01** | Le même modèle de facture pour Succès Imprim et pour MDG SARL. Seuls l'en-tête, les couleurs et les coordonnées changent. |
| **I02** | La facture avec deux QR : « Suivre cette facture en ligne » et « Payer par Orange Money ». Le montant est écrit en lettres. Le pied de page porte le code de vérification. |
| **I03** | Réglages › Entreprises et en-têtes des documents. Pour chaque entreprise : logo, raison sociale, mention, RCCM, NIF, banque, marchand Orange Money, numérotation, couleurs et options. Un aperçu montre le modèle. |
| **I04** | La page ouverte par le QR, sur mobile. Elle affiche le badge « Facture authentique », le total, ce qui est payé et ce qui reste, et les boutons « Payer », « Télécharger le PDF » et « Mon espace client ». |
| **I05** | Espace client › Factures et paiements. Total facturé, payé et reste à payer. Onglets Toutes, À payer, Payées, Devis, Avoirs. Le PDF, le reçu, le paiement et le relevé de compte. |

## Ce qui existe déjà

- `Factures.emetteur` a trois valeurs écrites en dur : `drepa` (DREPA TECHNOLOGIE), `mdg_success` (MADIAL GROUP SARL / SUCCESS IMPRIM) et `mdg` (MADIAL GROUP SARL). `Devis` a les mêmes.
- Les en-têtes, le RCCM, le NIF et les téléphones sont recopiés dans `templates/factures/pdf.html.twig` et dans le PDF du devis.
- Chaque facture a un jeton d'authenticité. Le PDF public est servi par `/factures/{id}/pdf-public/{token}`.
- Il n'y a pas encore d'espace client, ni de paiement Orange Money en ligne.

## Ce qu'on construit

### 1. Les entreprises émettrices (I03)

Une table `entreprise` remplace les valeurs en dur. Pour chaque entreprise :
- code, logo, raison sociale, nom commercial et mention (par exemple « Nom commercial de Madial Groupe (MDG SARL) ») ;
- adresse, téléphone, e-mail, site, RCCM, NIF ;
- banque et IBAN, marchand Orange Money ;
- préfixe et format de numérotation (`F-SI-{AAAA}-{0000}`), avec le prochain numéro ;
- les 5 couleurs du document ;
- options : QR de suivi, QR Orange Money, logo MDG en pied de page, montant en lettres.
- les conditions de paiement imprimées dans le bloc « NB » des factures et des proformas (500 caractères au plus ; vide : pas de bloc).

La migration crée les trois entreprises actuelles avec les coordonnées déjà dans les PDF, et relie les factures et devis existants. Un émetteur sans réglage n'empêche jamais de sortir une facture : les champs vides sont simplement absents.

C'est le socle de `docs/societes.md` : la future boutique de consommables sera une entreprise de plus.

### 2. Le modèle de facture (I01, I02)

Un seul gabarit PDF. L'en-tête, les couleurs, les mentions et les QR viennent de l'entreprise.

On y trouve :
- les lignes, le total HT, la TVA 18 % et le total TTC ;
- l'acompte reçu, avec sa date et son moyen, puis le reste à payer ;
- le montant en lettres : « Arrêtée la présente facture à la somme de deux cent trente-trois mille (233 000) francs CFA TTC ». Un service testé l'écrit en français.

Les deux QR :
- **QR de suivi :** il ouvre `/f/{code}`. Le code court (par exemple `7K4P-2Q`) est aussi imprimé en pied de page pour le vérifier à la main.
- **QR Orange Money :** tant que le paiement en ligne n'est pas branché, il ouvre la même page avec le code marchand et la référence à indiquer. Avec les clés Orange Money dans `.env.local`, il lance le paiement du reste à payer.

**Mise à jour du 15/10/2026, inspirée de l'ancienne facture :**
- **Objet.** « Objet : … » au-dessus des lignes, copié de la commande à la création de la facture (docs/documents-livraisons.md).
- **Colonnes.** N°, Désignation, Qté, PU, Remise et Total. La colonne Remise n'apparaît que si une ligne a une remise.
- **Livraison.** Les frais de livraison font une ligne « Livraison » à part, déjà comprise dans le total HT.
- **Totaux.** Total HT, TVA (s'il y en a), Total TTC, Déjà payé (même 0 F) et Reste à payer. Une proforma garde « Total TTC » et « Proforma valable 30 jours ».
- **Bloc « NB ».** Il reprend les conditions de paiement de l'entreprise émettrice. La direction les règle dans Réglages › Entités et en-têtes (Entreprises).
- **Texte par défaut.** La migration le met sur les entreprises existantes. Il reprend la mention écrite jusqu'ici dans le modèle : « Conditions de paiement : paiement à réception de la facture. Les frais de transaction mobile money (Orange Money, Wave) sont à la charge du client. » Il est à confirmer par Yaya.
- **PDF déjà archivés.** Ils ne changent pas.
- **Synchronisation.** Les conditions de paiement descendent sur le site avec l'entreprise : le PDF ouvert en ligne est le même.

### 3. La page du QR (I04)

Elle est publique, mais le code est difficile à deviner et la page n'est pas indexée.

Elle montre :
- « Facture authentique émise par Succès Imprim » (le nom de l'entreprise émettrice) ;
- le numéro, la date, le nom du client et la commande ;
- le total, ce qui est payé, le reste à payer et le statut ;
- les boutons « Payer », « Télécharger le PDF » et « Mon espace client ».

Le détail des lignes reste dans le PDF et dans l'espace client. Une facture annulée affiche « Facture annulée » au lieu du paiement.

### 4. L'espace client (I05)

Il arrive avec l'espace client (J5). Le client voit seulement ses propres factures, toutes entreprises confondues, avec l'émetteur. Les devis et les avoirs ont leurs onglets, et le relevé de compte est en PDF.

## Ordre

1. Les entreprises et leur page de réglages, avec la migration des trois émetteurs.
2. Le nouveau modèle de facture avec le montant en lettres et les deux QR.
3. La page `/f/{code}`.
4. Le même en-tête pour le devis, le bon de commande et le bon de livraison (D01, D03, D04).
5. Les factures dans l'espace client, en J5.
