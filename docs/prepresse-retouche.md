# Retouche d'une image en prépresse (redimensionner, contour, fichier final)

Maquettes de Yaya du 18/10/2026 (PP01 à PP05). Pour les infographistes : adapter l'image d'un client au format commandé, ajouter un contour, vérifier que l'impression sera nette, puis ranger le fichier final dans la commande, ou l'envoyer comme BAT.

## Où le trouver

- **Fiche prépresse d'un travail** (Atelier › Prépresse et BAT › un travail) : bouton « Redimensionner » en haut, à côté de « Monter », et une icône de recadrage sur chaque image de « Fichiers contrôlés ».
- **Page d'une commande** : pastille « Redimensionner » sur la ligne qui a une image (seulement pour qui a le droit Prépresse).
- Adresse : `/pre-presse/travail/<ligne>/retouche` (option `?fichier=<id>` pour choisir l'image).

**Droits** : comme le reste de la prépresse, droit « Prépresse » (graphistes) et Direction. En « Voir » (Montage PAO en lecture seule), l'écran s'ouvre mais les boutons de génération sont cachés et le serveur refuse l'envoi.

L'outil prend les images **JPG, PNG, WebP et GIF**. Un PDF de la ligne se **convertit en image** d'abord (section suivante), puis se retouche. Un TIFF ou un PSD se prépare dans le logiciel du graphiste ou dans l'outil de montage (`docs/montage-impression.md`).

## L'original n'est jamais modifié

L'image du client est seulement lue. Le fichier final est un **nouveau fichier** de la ligne, en version suivante (v2, v3…), origine « interne », état « traité », relié à l'original. Le serveur vérifie, avant d'enregistrer, que l'original n'a pas changé (empreinte) et que le fichier écrit se relit à la bonne taille.

## Les réglages (PP01 à PP03)

L'aperçu se calcule dans le navigateur, en direct ; à la génération, le serveur refait tout à partir de l'original, avec les mêmes règles.

**Format**
- Dimensions finales en mm, cm ou px (px = à la résolution de sortie). Par défaut : la taille de la ligne (largeur × longueur, sinon son format), sinon la taille de l'image à 300 dpi.
- Cadenas « Conserver les proportions » (activé par défaut) : garde le rapport largeur / hauteur des dimensions finales.
- Formats rapides : les formats publiés (Réglages › Formats), plus « Bâche 3 × 1 m ».
- **Fichier reçu à l'échelle** (demande de Yaya du 18/10/2026) : un grand format arrive souvent au 1/10 (12 × 20 cm pour une bâche de 120 × 200 cm). L'écran donne la taille du fichier reçu (pixels ÷ résolution déclarée, 72 dpi s'il n'en déclare pas) et des boutons « Taille réelle », « 1/2 → ×2 », « 1/4 → ×4 », « 1/5 → ×5 », « 1/10 → ×10 » qui multiplient cette taille. Si le fichier fois une de ces échelles donne la taille commandée (à 4 % près, dans un sens ou tourné), c'est dit : « Fichier reçu au 1/10 : agrandi ×10 au format commandé ». Quand l'agrandissement rend l'image trop peu nette, l'écran rappelle qu'un fichier au 1/10 doit être fait à 10 fois la résolution (720 dpi pour 72 dpi en bâche) et qu'un fichier vectoriel s'agrandit sans perte dans l'outil de montage. L'analyse automatique reconnaissait déjà les PDF au 1/10 (`AnalyseFichierImpression`).
- Mode d'ajustement :
  - **Adapter** : image entière, avec des marges de la couleur choisie (blanc par défaut ; transparent pour un PNG détouré) ;
  - **Recadrer** (par défaut) : remplit le format, le surplus est coupé au centre ; les zones coupées sont hachurées en rouge dans l'aperçu ;
  - **Étirer** : remplit en déformant, volontairement.
- Résolution pour l'impression : les **dpi réels**, c'est-à-dire les pixels de l'original posés sur le papier. Agrandir à la sortie n'ajoute pas de détail.
- Sortie à X dpi et taille de sortie en px. Conseillé : 300 dpi en petit format, 150 jusqu'à 2 m, 100 au-delà (suit les dimensions tant qu'on ne la change pas).
- Fond perdu : ajoute 5 mm autour. En recadrer et étirer, l'image remplit aussi le fond perdu ; le format fini est noté dans le PDF (TrimBox).

**Contour**
- **Rectangle intérieur** : le trait part du bord du format fini (ou du retrait choisi) et va vers l'intérieur ; rien n'est ajouté à l'extérieur. Noir par défaut, palette et code hexadécimal, épaisseur en mm (avec l'équivalent en pixels), loupe sur le coin.
- **Forme (détourage)** : le trait suit la silhouette, vers l'intérieur, bords lissés.
  - source « Transparence du fichier » (PNG, recommandé) : la forme = les pixels opaques ; les trous transparents (la croix d'un logo) sont aussi entourés ;
  - source « Détourage automatique » (JPG sur fond uni, à vérifier) : la couleur du fond est lue aux 4 coins de l'image ; tout ce qui la touche depuis les bords, à une tolérance près, est du fond.
  - Si ce n'est pas possible (pas de transparence, fond pas uni, aucune forme nette), l'outil le dit et propose le rectangle intérieur, ou de détourer dans le logiciel et de réimporter un PNG.

## Contrôles avant validation (PP04)

| Contrôle | Règle | Bloque ? |
|---|---|---|
| Résolution, grand format (plus grand côté > 42 cm) | 72 dpi minimum | sous 72 dpi |
| Résolution, petit format (jusqu'à A3) | 300 dpi conseillés | sous 200 dpi (entre 200 et 300 : attention) |
| Déformation (mode étirer) | écart de proportions | au-delà de 2 % |
| Taille du fichier | 150 millions de pixels au plus | toujours (baisser la sortie) |

Tant qu'un contrôle bloque, « Valider le résultat » est grisé. « Que faire ? » :
1. **Demander un fichier plus grand au client** : un message prérempli (modifiable) part dans la conversation de la commande (Messages de l'espace client), signé du prénom et du métier de l'agent.
2. **Passer en « adapter »** (pour une déformation).
3. **Valider quand même** : raison obligatoire. Elle est gardée avec le fichier, notée sur le contrôle prépresse, et **montrée au client sous le BAT** (« Remarque de l'atelier : image en basse résolution, le rendu peut être flou. <raison> »).

Le serveur refait les contrôles : il refuse une génération bloquée sans raison.

## Valider et générer (PP05)

- Vérifications : dimensions, proportions, contour, résolution, fichier lisible (relu après écriture), original conservé.
- Format de sortie :
  - **PDF** (par défaut) : une page à la taille physique (MediaBox, TrimBox, BleedBox), l'image JPEG qualité 92 incluse telle quelle ; un logo transparent garde sa transparence (jusqu'à 12 millions de pixels) ;
  - **PNG** : sans perte, transparence gardée, dpi écrits dans le fichier ;
  - **JPEG** : plus léger, la transparence devient blanche.
- Nom par défaut : `<commande>_<largeur>x<hauteur>_v<version>.pdf` (ex. `CMD-D00764_850x2000_v3.pdf`), modifiable.
- Dossier prépresse de la ligne : original, versions et BAT.
- **Générer et associer à la commande** : range le fichier.
- **Générer puis envoyer comme BAT n°X** : range le fichier et l'envoie au client comme sur l'écran prépresse (BAT en attente, frise de la conception, lien WhatsApp prérempli).

## Convertir un PDF en image

Demande de Yaya du 18/10/2026 (« et aussi convertir un pdf en image »).

**Où** :
- écran de retouche : les PDF de la ligne (fichiers du client et versions) sont dans « Ouvrir un autre fichier », rubrique « PDF de ce travail », avec « Convertir en image » ; quand la ligne n'a aucune image, ils sont listés au milieu de l'écran ;
- fiche prépresse : sur chaque PDF de « Fichiers contrôlés », une icône d'image mène à la conversion (`/pre-presse/travail/<ligne>/retouche?pdf=<id>`), comme l'icône de recadrage mène à la retouche.

**Réglages** :
- **Page**, si le PDF en a plusieurs.
- **Taille de la page** : sa TrimBox (format fini), sinon sa MediaBox. Si le PDF a un fond perdu (BleedBox, sinon MediaBox plus grande que la TrimBox), « Garder le fond perdu » rend la page avec.
- **Échelle du PDF** : un PDF au 1/10 se reconnaît comme dans l'analyse automatique (`AnalyseFichierImpression` : grand côté de la page plus petit que le cinquième de la commande) ou comme dans la retouche (page × 2, 4, 5 ou 10 = taille commandée, à 4 % près). Boutons « Taille réelle », « 1/2 → ×2 »… pour corriger.
- **Résolution** en dpi à la taille d'impression ; par défaut celle conseillée pour cette taille (300 dpi en petit format, 150 jusqu'à 2 m, 100 au-delà, comme `GeometrieRetouche::dpiSortieConseille`). Un PDF au 1/10 à 150 dpi est donc rendu à 1 500 dpi sur sa page. La taille en pixels est affichée.
- **Limite du navigateur** : 16 000 px de côté et 120 millions de pixels au plus. Au-delà, la résolution baisse et l'écran le dit (« Résolution baissée à 98 dpi… »).
- **Format** : PNG (sans perte, par défaut jusqu'à 25 millions de pixels) ou JPEG qualité 92 (par défaut au-delà). La résolution est écrite dans le fichier (pHYs du PNG, en-tête JFIF du JPEG) : la retouche lit donc la bonne taille physique.
- **Nom** : par défaut celui du PDF, la page s'il y en a plusieurs et la résolution (`affiche_p2_150dpi.png`), modifiable.

**Deux boutons** :
- **Télécharger l'image** : l'image est enregistrée sur l'ordinateur, rien n'est rangé dans la commande.
- **Convertir et ouvrir dans la retouche** : l'image est envoyée au serveur, qui vérifie que c'est un vrai PNG ou JPEG (type lu dans le contenu avec `getimagesize`, pas celui du navigateur), dans les limites de pixels, de 300 Mo au plus (et sous la limite d'envoi de PHP du serveur, que l'écran connaît). Elle est rangée comme **nouvelle version** de la ligne : origine interne, état traité, reliée au PDF (fichier source), désignation « Conversion PDF », observation avec la page, la résolution, l'échelle et la taille. Le PDF n'est jamais modifié. L'outil de retouche s'ouvre ensuite sur l'image (`?fichier=<id>`).

**Droits** : ceux de la prépresse (droit « Prépresse » et Direction). En « Voir » (Montage PAO en lecture seule), les boutons de conversion sont cachés (écran de retouche et fiche prépresse) et le serveur refuse l'envoi (`LectureSeuleSubscriber`, préfixe `app_controle_pre_presse_`).

**Pourquoi dans le navigateur** : GD ne lit pas les PDF et Imagick n'est pas garanti sur les serveurs. pdf.js (rendu) et pdf-lib (lecture des boîtes TrimBox, BleedBox, MediaBox) sont déjà servis en local pour l'outil de montage (`public/vendor/montage/`) : aucune dépendance ajoutée.

**Limites** :
- l'image est en **RVB** (rendu du navigateur), comme le fichier final de la retouche ; un PDF en CMJN destiné à l'impression directe ne devrait pas passer par là ;
- les polices et la transparence sont rendues par pdf.js : vérifier l'aperçu ; un PDF protégé par mot de passe ou abîmé ne s'ouvre pas, l'écran le dit ;
- sur un téléphone, le navigateur tient moins de pixels qu'un ordinateur : s'il refuse, baisser la résolution ;
- un PDF vectoriel s'agrandit sans perte dans l'outil de montage : convertir en image seulement pour retoucher (contour, recadrage) ou pour un logiciel qui n'accepte pas le PDF.

## Couleurs

GD (la bibliothèque d'images de PHP, toujours installée) ne sait pas faire de CMJN, et Imagick n'est pas garanti sur les serveurs. Le fichier final est donc **en RVB**, et l'écran l'écrit : « RVB · à convertir au RIP ». L'outil n'affiche jamais « CMJN (profil atelier) ». Pour un trait noir net, demander au RIP un noir 100 % (K 100).

## Technique

- Écran : `RetoucheImageController`, `templates/controle_pre_presse/retouche.html.twig`, `public/js/retouche-image.js`, `public/css/retouche-image.css` ; conversion d'un PDF : `public/js/pdf-en-image.js`, `App\Service\Prepresse\ConversionPdf` (vérification de l'image reçue, nom, observation), route `app_controle_pre_presse_retouche_convertir_pdf`.
- Calculs : `App\Service\Prepresse\GeometrieRetouche` (les mêmes que le navigateur) ; génération : `GenerateurRetouche` (GD) ; PDF : `PdfImage`, écrit à la main (dompdf passe par du HTML et chargerait une bâche de 100 millions de pixels en mémoire). Aucune dépendance ajoutée.
- Fichier rangé dans `var/uploads/commandes` (non public), réglages dans `commande_detail_fichier.retouche` (au local), raison montrée au client dans `commande_detail_fichier.remarque_bat` (part en ligne avec la fiche du fichier, comme le BAT).
- Photo de téléphone tournée (EXIF) : remise droite, comme dans le navigateur.
- Tests : `tests/Unit/Service/Prepresse/RetoucheImageTest.php`, `tests/Unit/Service/Prepresse/ConversionPdfTest.php`.

## Reste à décider

- Les seuils (72 dpi en grand format, 200 / 300 dpi en petit format, 2 % de déformation) sont dans `GeometrieRetouche` ; ils pourront passer dans Réglages si l'atelier veut les changer lui-même.
- Recadrer coupe toujours au centre ; un choix du cadrage (haut, bas) peut venir ensuite.
