# Fichiers

Pages : docs/securite.md (« Fichiers envoyés »), docs/fichiers-lourds.md, docs/dossiers-clients.md, docs/depot-prive.md, docs/sauvegardes.md.

## Où sont rangés les fichiers

| Dossier | Contenu | Public ? |
|---|---|---|
| `var/uploads/commandes` | fichiers des commandes (dépôt de la prépresse, envois des clients) | non |
| `var/uploads/chat`, `prepresse`, `etiquettes`, `reclamations`, `employes`, `entreprises`, `fichiers` | anciens dossiers de `public/uploads`, devenus privés (`FichiersPrives::DOSSIERS`) : pièces jointes du chat, fichiers de la prépresse, étiquettes, réclamations, photos du personnel, logos et cachet des entreprises | non |
| `var/uploads/publies` | copies publiées pour un client (« Envoyer au client » des dossiers clients) | non |
| `var/uploads/depenses`, `livraisons`, `entretien`, `vocales`, `atelier`, `imports` | justificatifs de dépenses, preuves de livraison, entretien des machines, notes vocales, atelier, imports | non |
| `var/uploads/dossier_partage` | le dossier partagé des fichiers lourds, importé chaque minute (`Prepresse\DossierPartage`, tâche `app:dossier-partage:importer`) | non |
| `var/uploads/site/originaux` | originaux des images du site vitrine | non |
| `var/pointage` | photos de pointage | non |
| `public/uploads/site`, `public/uploads/tv` | images du site vitrine et médias de l'écran TV (`FichiersPrives::PUBLICS`) | **oui**, par décision |
| `var/storage`, `var/espace-employe`, `var/ecran-tv` | factures PDF, documents de l'espace employé, écran TV | non |
| `var/sauvegardes` (ou `SAUVEGARDE_DIR`), recopiées vers `SAUVEGARDE_COPIE_DIR` | sauvegardes de la base et archives des fichiers | non |

Tout `var/` est hors de la racine web : un fichier n'en sort que par un contrôleur.

## À l'arrivée

- **Nom et type** : `App\Security\FichierEnvoye` choisit le nom sur le serveur et l'extension d'après le contenu du fichier, dans une liste fermée, jamais d'après ce que le navigateur annonce. Les types qui pourraient s'exécuter ne vont jamais dans un dossier public.
- **Taille** : les fichiers lourds partent en morceaux de 5 Mo et reprennent après une coupure (`FichierUploadController`, limite `TAILLE_MAX` de 750 Mo par fichier depuis la page commande et le contrôle prépresse). Les autres façons de faire entrer un fichier lourd (lien WeTransfer ou Drive téléchargé par le serveur, dossier partagé) sont dans docs/fichiers-lourds.md. Le chat a sa propre limite (`FichierEnvoye::TAILLE_MAX_CHAT`, 10 Mo).
- **Dossier partagé** : un fichier nommé d'après sa commande (`CMD-000378_5,5x7.pdf`) ou son travail (`123_nom.pdf`) est déplacé vers les fichiers de la commande, et la commande passe « À contrôler ». Les autres restent dans le dossier et s'affichent dans « Fichiers à classer ».
- **Images du site** : lues par GD, redressées, réduites et réencodées à l'envoi (`Site\ImagesSite`) ; l'original reste dans `var/uploads/site/originaux`.

## Au départ

- Un fichier privé sort par `FichiersPrivesController`. `FichiersPrivesVoter` décide avec les mêmes droits que la page qui montre le fichier (attributs dans `droits.voteurs`).
- `FichierEnvoye::reponse()` sert le fichier avec un type lu dans son contenu : une image ou un PDF s'ouvre dans le navigateur (`FichierEnvoye::AFFICHABLES`, jamais SVG ni HTML), le reste se télécharge, et rien ne s'exécute. La politique de sécurité des réponses de fichier est plus stricte que celle des pages (`EntetesSecurite::POLITIQUE_FICHIER`, dans `securite.en_tetes`).
- Un client ne voit que ses propres fichiers dans l'espace client. Chaque téléchargement est inscrit au journal d'activité.
- Le guide signale les routes qui servent des fichiers par `sensibles` = `fichiers` (mots du nom ou de l'adresse : fichier, télécharger, pdf, export, photo, justificatif…).

## Les dossiers des clients de l'atelier

L'ERP lit le dossier des clients sur le disque de l'atelier, mais il ne déplace, ne renomme et ne supprime jamais un fichier de l'atelier (docs/dossiers-clients.md). Il n'y a que deux exceptions : créer le dossier d'un nouveau client, et poser une **copie** renommée d'un fichier « à classer ». Dans ces écrans, « Supprimer » retire de l'ERP, pas du disque. Les droits sont donnés par `DossiersClientsVoter`.

## Conservation et effacement

- **Fichiers de commande** (`Fichiers\ConservationFichiers`) : effacés du serveur quelques mois après l'impression de leur ligne (réglage « Fichiers gardés après impression », 4 mois au départ, `MOIS_DEFAUT`), ou 12 mois si quelqu'un a demandé « Garder ce fichier 1 an » (`GARDE_DEFAUT`). Ne sont jamais effacés : un fichier de « Mes produits », un logo, un fichier sans ligne, une ligne pas encore imprimée. Seuls les octets partent : la fiche reste, avec la date d'effacement. Chaque serveur efface ses propres copies par `app:nettoyer-fichiers-production`, une fois par nuit.
- **Corbeille** : ce qui est supprimé dans l'application y reste 30 jours (durée réglable), puis `app:corbeille:purger` le supprime pour de bon après la sauvegarde de la nuit (docs/corbeille.md).
- **Photos de pointage** : supprimées au-delà de leur durée de conservation par `app:pointage:entretien` (docs/pointage.md).
- **Sauvegardes** (docs/sauvegardes.md) : tout ce qui a moins de 48 h est gardé, puis une par jour pendant 14 jours, une par semaine pendant 8 semaines et une par mois pendant 12 mois ; la plus récente n'est jamais supprimée. Une copie chiffrée à l'imprimerie part chaque jour vers le serveur en ligne et se relit par `app:sauvegarde-dechiffrer`.

## Ce qu'un auditeur regarde

- qu'aucun fichier envoyé ne finit sous `public/` en dehors de `public/uploads/site` et `public/uploads/tv` ;
- que chaque route qui sert un fichier privé passe par `FichiersPrivesVoter` ou un contrôle de droits équivalent (routes `sensibles` = `fichiers` dans le guide) ;
- que l'extension est toujours choisie d'après le contenu (`FichierEnvoye`) ;
- que `app:fichiers:deplacer` est passé sur chaque machine (anciens fichiers de `public/uploads` déplacés sous `var/uploads`).
