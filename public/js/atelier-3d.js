/*
 * Studio textile (docs/atelier-3d.md) : la page du site public et de
 * l'espace client (gabarit templates/atelier/_outil.html.twig).
 *
 * - Les vues à plat sont dessinées tout de suite : la page se lit et
 *   s'utilise sans 3D.
 * - La scène three.js (atelier-3d-scene.js) arrive après le premier
 *   affichage si le navigateur a WebGL et que l'appareil le permet (sinon
 *   le bouton « Voir en 3D »). Chaque vue fixe (face, dos, côté…) est
 *   rendue à la demande, seulement quand quelque chose change ; seule la
 *   vue 360° s'anime.
 * - Logo, texte et couleur valent pour toutes les rangées : chaque article
 *   montre le même marquage, à l'emplacement choisi pour sa famille
 *   (vêtement ou casquette).
 * - Le prix vient toujours du serveur (urls.prix) ; « Valider » n'envoie
 *   que les choix, l'image déposée (jeton) et deux vues de l'article
 *   choisi. « Télécharger la maquette » est préparée ici et ne part nulle
 *   part.
 * - Un SVG n'est lu que dans la page (comme une image, sans script) et
 *   envoyé en PNG : le serveur refuse toujours les SVG.
 */
(function () {
  'use strict';

  const racine = document.querySelector('.a3d');
  const source = document.getElementById('a3d-donnees');
  if (!racine || !source) return;
  const D = JSON.parse(source.textContent);
  if (!D.fiches || !D.fiches.length) return;

  const fenetre = document.querySelector('.a3d-fenetre');
  const formulaire = fenetre.querySelector('[data-formulaire]');
  const reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fr = new Intl.NumberFormat('fr-FR');
  const $ = (s, dans) => (dans || racine).querySelector(s);
  const $$ = (s, dans) => Array.prototype.slice.call((dans || racine).querySelectorAll(s));
  const texte = (cle) => $('[data-texte="' + cle + '"]');
  const champ = (nom) => $('[data-champ="' + nom + '"]');
  const montant = (n) => fr.format(Math.round(n)) + ' F';
  const borne = (v, a, b) => Math.max(a, Math.min(b, v));
  const FACES = ['devant', 'dos'];
  const TAILLES_DEFAUT = { S: 5, M: 10, L: 10, XL: 5, XXL: 0 };
  /** Corps du texte en part de la largeur de l'emplacement (le texte seul est plus grand). */
  const CORPS = { Montserrat: 0.1, 'Archivo Black': 0.1, 'Bebas Neue': 0.142, Pacifico: 0.092, Figtree: 0.1 };
  /** Unités du dessin par centimètre : les mêmes que la 3D dessinée (atelier-3d-scene.js). */
  const CM = { vetement: 0.0275, casquette: 0.1 };
  const FOND_CAPTURE = '#EEF2F8';
  const SVG_COTE = 2400;
  const SVG_POIDS_MAX = 2 * 1024 * 1024;

  function memoire(cle, valeur) {
    try {
      if (valeur === undefined) return window.localStorage.getItem(cle);
      window.localStorage.setItem(cle, valeur);
    } catch (e) { /* navigation privée */ }
    return null;
  }

  // ---------------------------------------------------------------- état
  const POLICE_DEFAUT = Object.keys(D.polices)[0] || 'Montserrat';
  const texteVide = () => ({ texte: '', police: POLICE_DEFAUT, gras: false, couleurTexte: 'Blanc', auto: true, placeTexte: 'dessous' });
  const premierePlace = (fam, face) => Object.keys(D.emplacements[fam][face])[0];

  const etat = {
    fiche: null,
    couleur: null,
    visiere: null,
    bords: null,
    /** Le logo déposé (ou celui d'exemple) : { dessin, vignette, nom, largeur, hauteur, jeton, source, exemple, envoi, erreur }. */
    logo: null,
    /** Images propres à une face (création rouverte qui en avait deux). */
    surcharges: { devant: null, dos: null },
    taille: 80,
    /** Emplacement retenu par face et par famille, et si le logo y est. */
    places: {
      vetement: { devant: { position: 'poitrine-gauche', logo: true }, dos: { position: 'dos-centre', logo: false } },
      casquette: { devant: { position: 'devant', logo: true }, dos: { position: 'arriere', logo: false } },
    },
    textes: { devant: texteVide(), dos: texteVide() },
    faceTexte: 'devant',
    tailles: {},
    quantite: 20,
    prix: null,
    rendu: 'plat',
  };

  const famille = (fiche) => (fiche.forme === 'casquette' ? 'casquette' : 'vetement');
  const couleurDans = (fiche, nom) => (nom ? fiche.couleurs.find((c) => c.nom === nom) || null : null);
  const couleurDefaut = (fiche) => couleurDans(fiche, 'Blanc') || fiche.couleurs[0];
  const imageDe = (face) => etat.surcharges[face] || etat.logo;
  const logoSur = (fam, face) => !!imageDe(face) && etat.places[fam][face].logo;
  const faceMarquee = (fam, face) => logoSur(fam, face) || !!etat.textes[face].texte.trim();
  const facesMarquees = (fam) => FACES.filter((f) => faceMarquee(fam || famille(etat.fiche), f));
  const libelleEmplacement = (fam, face) => D.emplacements[fam][face][etat.places[fam][face].position][0];

  function libelleFace(face, fiche) {
    if (face === 'devant') return 'Devant';
    if (famille(fiche || etat.fiche) === 'casquette') return etat.places.casquette.dos.position === 'cote' ? 'Côté' : 'Arrière';
    return 'Dos';
  }

  /** Taille imprimée la plus grande à cet emplacement, en cm (CreationAtelier::zoneImpression). */
  function zoneImpression(fiche, face, position) {
    const e = D.emplacements[famille(fiche)][face][position];
    const z = fiche.zone;
    return {
      l: e && e[1] !== null ? Math.min(e[1], z.largeur) : z.largeur,
      h: e && e[2] !== null ? Math.min(e[2], z.hauteur) : z.hauteur,
    };
  }

  /** Taille de l'image en % de l'emplacement, place du texte retirée (même règle que le serveur). */
  function tailleImage(face) {
    const t = borne(etat.taille | 0, 15, 100);
    return etat.textes[face].texte.trim() ? Math.round(t * D.reduction) : t;
  }

  function quantite() {
    if (!etat.fiche.tailles.length) return Math.max(0, etat.quantite | 0);
    return etat.fiche.tailles.reduce((s, t) => s + Math.max(0, etat.tailles[t] | 0), 0);
  }

  function clair(hex) {
    const n = parseInt(String(hex).slice(1), 16);
    const l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    return l > 0.62;
  }

  // ---------------------------------------------------------------- polices
  const polices = {};
  /** Charge une police de la page (copiée dans public/vendor, sous licence OFL). */
  function police(nom) {
    if (!polices[nom]) {
      const faces = D.polices[nom] || [];
      polices[nom] = 'FontFace' in window && faces.length
        ? Promise.all(faces.map((p) => new FontFace(nom, 'url(' + p.url + ') format("woff2")', { weight: p.poids }).load()
          .then((f) => { document.fonts.add(f); })
          .catch(() => {}))).then(() => {})
        : Promise.resolve();
    }
    return polices[nom];
  }

  /** Graisse à demander, et s'il faut épaissir le trait (police sans version grasse). */
  function graisse(nom, gras) {
    const poids = (D.polices[nom] || [{ poids: '400' }]).map((p) => parseInt(p.poids, 10) || 400);
    const max = Math.max.apply(null, poids);
    const min = Math.min.apply(null, poids);
    return { poids: gras ? max : min, epaissir: gras && max === min };
  }

  const fonte = (nom, poids, px) => poids + ' ' + Math.max(1, Math.round(px)) + 'px "' + nom + '", "Arial Black", sans-serif';

  // ---------------------------------------------------------------- dessin du marquage
  /**
   * Logo et texte d'une face dans un rectangle W × H (la boîte de
   * l'emplacement) ; « haut » les pose en haut de la boîte (devant ou dos
   * entier) au lieu du milieu.
   */
  function composer(g, W, H, face, avecLogo, haut) {
    const t = etat.textes[face];
    const mots = t.texte.trim();
    const img = avecLogo ? imageDe(face) : null;
    let iw = 0;
    let ih = 0;
    if (img) {
      const k = tailleImage(face) / 100;
      const r = img.largeur / Math.max(1, img.hauteur);
      iw = W * k;
      ih = iw / r;
      if (ih > H * k) { ih = H * k; iw = ih * r; }
    }
    const ecart = img && mots ? Math.min(W, H) * 0.04 : 0;
    let px = 0;
    let th = 0;
    const gr = graisse(t.police, t.gras);
    if (mots) {
      px = Math.min(W * (CORPS[t.police] || 0.1) * (img ? 1 : 1.7), Math.max(4, (img ? H - ih - ecart : H) / 1.22));
      g.font = fonte(t.police, gr.poids, px);
      const mesure = g.measureText(mots).width + (gr.epaissir ? px * 0.08 : 0);
      if (mesure > W * 0.96) {
        px *= (W * 0.96) / mesure;
        g.font = fonte(t.police, gr.poids, px);
      }
      th = px * 1.22;
    }
    const total = ih + ecart + th;
    let y = haut ? Math.min(H * 0.02, Math.max(0, H - total)) : Math.max(0, (H - total) / 2);
    const morceaux = t.placeTexte === 'dessus' ? ['texte', 'image'] : ['image', 'texte'];
    for (const m of morceaux) {
      if (m === 'image' && img) {
        g.drawImage(img.dessin, (W - iw) / 2, y, iw, ih);
        y += ih + ecart;
      } else if (m === 'texte' && mots) {
        const couleur = D.couleursTexte[t.couleurTexte] || '#ffffff';
        g.fillStyle = couleur;
        g.textAlign = 'center';
        g.textBaseline = 'middle';
        if (gr.epaissir) {
          g.strokeStyle = couleur;
          g.lineJoin = 'round';
          g.lineWidth = px * 0.07;
          g.strokeText(mots, W / 2, y + th / 2);
        }
        g.fillText(mots, W / 2, y + th / 2);
        y += th + ecart;
      }
    }
  }

  /**
   * Toile d'une face pour une rangée : aux proportions de la boîte de
   * l'emplacement (projetée sur le tissu), ou de toute la zone si le
   * modèle 3D a une pièce « zone_devant » / « zone_dos ».
   */
  function toileFace(r, face) {
    const fiche = r.fiche;
    const fam = famille(fiche);
    const place = etat.places[fam][face];
    const boite = zoneImpression(fiche, face, place.position);
    const uv = !!(r.zones && r.zones[face]);
    const cadre = uv ? { l: fiche.zone.largeur, h: fiche.zone.hauteur } : boite;
    const c = r.toiles[face];
    const W = 1024;
    const H = borne(Math.round(W * cadre.h / Math.max(1, cadre.l)), 160, 2048);
    if (c.width !== W) c.width = W;
    if (c.height !== H) c.height = H;
    const g = c.getContext('2d');
    g.clearRect(0, 0, W, H);
    const s = W / cadre.l;
    let x = 0;
    let y = 0;
    if (uv) {
      const decale = place.position === 'poitrine-gauche' ? 9 : place.position === 'poitrine-droite' ? -9 : 0;
      x = borne((cadre.l - boite.l) / 2 + decale, 0, Math.max(0, cadre.l - boite.l));
      y = /^poitrine/.test(place.position) ? Math.min(2, cadre.h - boite.h) : 0;
    }
    const haut = place.position === 'devant-centre' || place.position === 'dos-centre';
    g.save();
    g.translate(x * s, y * s);
    composer(g, boite.l * s, boite.h * s, face, logoSur(fam, face), haut);
    g.restore();
    return { canevas: c, boite, position: place.position, visible: faceMarquee(fam, face), mode: uv ? 'uv' : 'projection' };
  }

  // ---------------------------------------------------------------- vues à plat
  function nuance(hex, ecart) {
    const n = parseInt(hex.slice(1), 16);
    const canal = (v) => Math.max(0, Math.min(255, Math.round(v + ecart * 255)));
    return 'rgb(' + canal(n >> 16) + ',' + canal((n >> 8) & 255) + ',' + canal(n & 255) + ')';
  }

  function contourTshirt(g, polo, encolure) {
    const m = polo ? 0.22 : 0;
    g.beginPath();
    g.moveTo(-0.30, 1.0);
    g.lineTo(-0.64, 0.94);
    g.quadraticCurveTo(-0.95, 0.86, -1.18, 0.60);
    g.lineTo(-0.97 + m * 0.2, 0.30 + m * 0.3);
    g.quadraticCurveTo(-0.80, 0.40, -0.63, 0.46);
    g.quadraticCurveTo(-0.66, -0.2, -0.64, -1.0);
    g.quadraticCurveTo(0, -1.04, 0.64, -1.0);
    g.quadraticCurveTo(0.66, -0.2, 0.63, 0.46);
    g.quadraticCurveTo(0.80, 0.40, 0.97 - m * 0.2, 0.30 + m * 0.3);
    g.lineTo(1.18, 0.60);
    g.quadraticCurveTo(0.95, 0.86, 0.64, 0.94);
    g.lineTo(0.30, 1.0);
    g.quadraticCurveTo(0, encolure, -0.30, 1.0);
    g.closePath();
  }

  function ombrer(g, x0, x1) {
    const lat = g.createLinearGradient(x0, 0, x1, 0);
    lat.addColorStop(0, 'rgba(10,20,40,.18)');
    lat.addColorStop(0.22, 'rgba(10,20,40,0)');
    lat.addColorStop(0.78, 'rgba(10,20,40,0)');
    lat.addColorStop(1, 'rgba(10,20,40,.18)');
    g.fillStyle = lat;
    g.fillRect(x0, -2, x1 - x0, 4);
  }

  function solPlat(g, y, rayon) {
    g.save();
    g.scale(1, 0.18);
    const sol = g.createRadialGradient(0, y / 0.18, 0.02, 0, y / 0.18, rayon);
    sol.addColorStop(0, 'rgba(0,0,0,.32)');
    sol.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = sol;
    g.beginPath();
    g.arc(0, y / 0.18, rayon, 0, Math.PI * 2);
    g.fill();
    g.restore();
  }

  /** Boîte de l'emplacement dans le dessin à plat : mêmes repères que la 3D dessinée. */
  function boitePlat(r, face) {
    const fiche = r.fiche;
    const fam = famille(fiche);
    const pos = etat.places[fam][face].position;
    const b = zoneImpression(fiche, face, pos);
    const cm = CM[fam];
    const l = b.l * cm;
    const h = b.h * cm;
    if (fam === 'casquette') return { x: 0, y: 0, l, h };
    const poitrine = 0.5;
    if (face === 'devant') {
      if (pos === 'devant-centre') {
        const haut = poitrine + (fiche.forme === 'polo' ? -4 : 5) * cm;
        const k = Math.min(1, (haut - (-1 + 3 * cm)) / h);
        return { x: 0, y: haut - (h * k) / 2, l, h };
      }
      return { x: (pos === 'poitrine-droite' ? -9 : 9) * cm, y: poitrine + 2 * cm, l, h };
    }
    const haut = poitrine + (pos === 'dos-haut' ? 9.5 : 7.3) * cm;
    return { x: 0, y: haut - h / 2, l, h };
  }

  function poserDecal(g, r, face, b) {
    const reg = r.reglages[face];
    if (!reg || !reg.visible || reg.mode !== 'projection') return;
    g.save();
    g.scale(1, -1);
    g.drawImage(r.toiles[face], b.x - b.l / 2, -(b.y + b.h / 2), b.l, b.h);
    g.restore();
  }

  function vetementPlat(g, r, face, decal) {
    const c = couleursDe(r);
    const polo = r.fiche.forme === 'polo';
    const tissu = c.tissu;
    const bord = polo && c.secondeChoisie ? c.seconde : nuance(tissu, -0.12);
    const encolure = face === 'dos' ? (polo ? 0.95 : 0.92) : (polo ? 0.86 : 0.74);
    solPlat(g, -1.16, 1.1);
    contourTshirt(g, polo, encolure);
    g.fillStyle = tissu;
    g.fill();
    g.save();
    contourTshirt(g, polo, encolure);
    g.clip();
    ombrer(g, -1.2, 1.2);
    g.strokeStyle = 'rgba(10,20,40,.2)';
    g.lineWidth = 0.012;
    [-1, 1].forEach((s) => {
      g.beginPath();
      g.moveTo(s * 0.64, 0.94);
      g.quadraticCurveTo(s * 0.60, 0.70, s * 0.63, 0.46);
      g.stroke();
    });
    g.beginPath();
    g.moveTo(-0.64, -0.93);
    g.quadraticCurveTo(0, -0.97, 0.64, -0.93);
    g.stroke();
    if (polo && c.secondeChoisie) {
      // Bords des manches.
      g.strokeStyle = bord;
      g.lineWidth = 0.035;
      [-1, 1].forEach((s) => {
        g.beginPath();
        g.moveTo(s * 1.16, 0.58);
        g.lineTo(s * (0.97 - 0.044), 0.30 + 0.066);
        g.stroke();
      });
    }
    g.restore();
    g.strokeStyle = bord;
    g.lineCap = 'round';
    if (!polo) {
      g.lineWidth = 0.07;
      g.beginPath();
      g.moveTo(-0.30, 0.99);
      g.quadraticCurveTo(0, encolure + 0.02, 0.30, 0.99);
      g.stroke();
    } else if (face === 'devant') {
      g.fillStyle = bord;
      [-1, 1].forEach((m) => {
        g.beginPath();
        g.moveTo(m * 0.40, 1.04);
        g.lineTo(m * 0.30, 1.10);
        g.lineTo(0, 0.84);
        g.lineTo(m * 0.03, 0.70);
        g.lineTo(m * 0.20, 0.74);
        g.closePath();
        g.fill();
      });
      g.fillStyle = nuance(tissu, -0.05);
      g.fillRect(-0.065, 0.44, 0.13, 0.36);
      g.fillStyle = clair(tissu) ? '#d9d6cf' : '#f2f2f2';
      [0.70, 0.60, 0.50].forEach((y) => { g.beginPath(); g.arc(0, y, 0.02, 0, Math.PI * 2); g.fill(); });
    } else {
      g.lineWidth = 0.06;
      g.beginPath();
      g.moveTo(-0.33, 1.0);
      g.quadraticCurveTo(0, encolure + 0.03, 0.33, 1.0);
      g.stroke();
    }
    if (decal) poserDecal(g, r, face, boitePlat(r, face));
  }

  function casquettePlat(g, r, face, decal, deCote) {
    const c = couleursDe(r);
    const tissu = c.tissu;
    const visiere = c.seconde || tissu;
    const base = -0.45;
    const rx = 1.12;
    const ry = 0.95;
    solPlat(g, -0.98, 1.2);
    const calotte = () => {
      g.beginPath();
      g.ellipse(0, base, rx, ry, 0, 0, Math.PI);
      g.closePath();
    };
    const devant = face === 'devant' || deCote;
    if (devant) {
      g.fillStyle = nuance(visiere, -0.06);
      g.beginPath();
      g.ellipse(0, base - 0.02, rx * 1.08, 0.36, 0, Math.PI, Math.PI * 2);
      g.fill();
    }
    calotte();
    g.fillStyle = tissu;
    g.fill();
    g.save();
    calotte();
    g.clip();
    ombrer(g, -rx, rx);
    g.strokeStyle = nuance(tissu, -0.2);
    g.lineWidth = 0.012;
    [-0.62, 0, 0.62].forEach((x) => {
      g.beginPath();
      g.moveTo(0, base + ry);
      g.quadraticCurveTo(x * 1.05, base + ry * 0.55, x * 1.35, base);
      g.stroke();
    });
    if (!devant) {
      g.fillStyle = nuance(tissu, -0.35);
      g.beginPath();
      g.ellipse(0, base, 0.34, 0.26, 0, 0, Math.PI);
      g.fill();
      g.fillStyle = nuance(tissu, -0.12);
      g.fillRect(-0.36, base, 0.72, 0.06);
    }
    g.restore();
    g.fillStyle = nuance(tissu, -0.2);
    g.fillRect(-rx, base - 0.03, rx * 2, 0.05);
    g.fillStyle = tissu;
    g.beginPath();
    g.ellipse(0, base + ry + 0.02, 0.07, 0.05, 0, 0, Math.PI * 2);
    g.fill();
    if (devant) {
      g.fillStyle = visiere;
      g.beginPath();
      g.ellipse(0, base - 0.03, rx * 1.1, 0.2, 0, 0, Math.PI);
      g.closePath();
      g.fill();
    }
    if (!decal) return;
    // La calotte va de -0,45 à 0,5 : le marquage se pose sur le panneau avant ou au-dessus de l'ouverture.
    const b = boitePlat(r, face);
    const k = Math.min(1, 1.3 / b.l, 0.62 / b.h);
    poserDecal(g, r, face, { x: 0, y: base + (face === 'devant' ? 0.45 : 0.6), l: b.l * k, h: b.h * k });
  }

  /** Une vue à plat (clé de vue de la scène : face, dos, cote-gauche, angle, arriere…). */
  function peindrePlat(g, w, h, r, vue, zoom, fond) {
    g.save();
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.clearRect(0, 0, w, h);
    if (fond) {
      g.fillStyle = fond;
      g.fillRect(0, 0, w, h);
    }
    const s = (Math.min(w, h) / 2.75) * (zoom || 1);
    g.translate(w / 2, h / 2 + 0.04 * s);
    g.scale(s, -s);
    if (famille(r.fiche) === 'casquette') {
      g.translate(0, 0.2);
      const cote = etat.places.casquette.dos.position === 'cote';
      if (vue === 'angle') { g.scale(0.86, 1); casquettePlat(g, r, 'devant', true, false); }
      else if (vue === 'cote') { g.scale(0.62, 1); casquettePlat(g, r, cote ? 'dos' : 'devant', cote, true); }
      else if (vue === 'arriere') casquettePlat(g, r, 'dos', true, false);
      else casquettePlat(g, r, 'devant', true, false);
    } else if (vue === 'dos') {
      vetementPlat(g, r, 'dos', true);
    } else if (/^cote/.test(vue)) {
      g.scale(0.5, 1);
      vetementPlat(g, r, 'devant', false);
    } else {
      vetementPlat(g, r, 'devant', true);
    }
    g.restore();
  }

  // ---------------------------------------------------------------- rangées
  const rangees = D.fiches.map((fiche) => {
    const el = $('[data-rangee="' + fiche.id + '"]');
    const vues = {};
    $$('.a3d-vue', el).forEach((fig) => {
      vues[fig.dataset.vue] = { figure: fig, canvas: $('canvas', fig), libelle: $('[data-libelle-vue]', fig) };
    });
    const minis = {};
    $$('[data-miniature]', el).forEach((b) => { minis[b.dataset.miniature] = { bouton: b, canvas: $('canvas', b) }; });
    const outils = {};
    $$('[data-outil]', el).forEach((b) => { outils[b.dataset.outil] = b; });
    const carte = $('[data-article="' + fiche.id + '"] [data-miniature-article]');
    return {
      fiche, el, vues, minis, outils, carte,
      tour: $('[data-tour]', el),
      tourCanvas: $('[data-tour] canvas', el),
      credits: $('[data-credits]', el),
      toiles: { devant: document.createElement('canvas'), dos: document.createElement('canvas') },
      reglages: { devant: null, dos: null },
      zones: {},
      zoom: 1,
      pret: false,
      sales: new Set(),
      marquageSale: true,
      tourCtrl: null,
    };
  });
  const rangeeDe = (fiche) => rangees.find((r) => r.fiche === fiche);
  const rangeeActive = () => rangeeDe(etat.fiche) || rangees[0];

  /** Couleurs d'une rangée : celle choisie si l'article l'a, sinon la sienne. */
  function couleursDe(r) {
    const f = r.fiche;
    const tissu = couleurDans(f, etat.couleur) || couleurDans(f, r.couleurPropre) || couleurDefaut(f);
    r.couleurPropre = tissu.nom;
    let seconde = null;
    let secondeChoisie = false;
    if (f.forme === 'casquette') {
      const v = couleurDans(f, etat.visiere);
      seconde = (v || tissu).hex;
      secondeChoisie = !!v;
    } else if (f.forme === 'polo') {
      const b = couleurDans(f, etat.bords);
      seconde = (b || tissu).hex;
      secondeChoisie = !!b;
    }
    return { tissu: tissu.hex, seconde, secondeChoisie };
  }

  /** Vue de la scène derrière une vue de la rangée (le côté montre le logo ; la casquette marquée derrière montre l'arrière). */
  function vueScene(r, cle) {
    if (cle !== 'cote') return cle;
    if (famille(r.fiche) === 'vetement') {
      const p = etat.places.vetement.devant;
      return p.position === 'poitrine-droite' && faceMarquee('vetement', 'devant') ? 'cote-droit' : 'cote-gauche';
    }
    return etat.places.casquette.dos.position === 'arriere' && faceMarquee('casquette', 'dos') ? 'arriere' : 'cote';
  }

  /** Vue envoyée comme « dos » (casquette : l'arrière, ou le côté marqué). */
  function vueDos(r) {
    if (famille(r.fiche) === 'vetement') return 'dos';
    return etat.places.casquette.dos.position === 'cote' ? 'cote' : 'arriere';
  }

  function majLibellesVues() {
    rangees.forEach((r) => {
      if (famille(r.fiche) !== 'casquette' || !r.vues.cote) return;
      const libelle = vueScene(r, 'cote') === 'arriere' ? 'Arrière' : 'Côté';
      if (r.vues.cote.libelle.textContent !== libelle) {
        r.vues.cote.libelle.textContent = libelle;
        r.vues.cote.canvas.setAttribute('aria-label', r.fiche.libelleForme + ' : vue ' + libelle.toLowerCase());
        if (r.minis.cote) r.minis.cote.bouton.setAttribute('aria-label', 'Vue ' + libelle.toLowerCase());
      }
    });
  }

  // ---------------------------------------------------------------- rendu à la demande
  let studio = null;
  let prevu = false;
  let minuteurAutres = 0;

  function planifier() {
    if (prevu) return;
    prevu = true;
    requestAnimationFrame(rendreFile);
  }

  function salir(r, cles) {
    (cles || Object.keys(r.vues)).forEach((k) => r.sales.add(k));
    planifier();
  }

  /** Tout est à redessiner : la rangée choisie tout de suite, les autres un peu après (frappe au clavier). */
  function toutSalir(marquage) {
    const active = rangeeActive();
    if (marquage) active.marquageSale = true;
    salir(active);
    window.clearTimeout(minuteurAutres);
    minuteurAutres = window.setTimeout(() => {
      rangees.forEach((r) => {
        if (r === active) return;
        if (marquage) r.marquageSale = true;
        salir(r);
      });
    }, 180);
  }

  function majMarquage(r) {
    r.marquageSale = false;
    FACES.forEach((face) => {
      const reg = toileFace(r, face);
      r.reglages[face] = reg;
      if (studio && r.pret) studio.marquage(r.fiche.id, face, reg);
    });
    if (r.tourCtrl) r.tourCtrl.rafraichir();
  }

  function taillePhysique(c, max) {
    const rect = c.getBoundingClientRect();
    if (rect.width < 4 || rect.height < 4) return null;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    let w = Math.round(rect.width * dpr);
    let h = Math.round(rect.height * dpr);
    const k = Math.min(1, max / Math.max(w, h));
    w = Math.round(w * k);
    h = Math.round(h * k);
    if (c.width !== w || c.height !== h) { c.width = w; c.height = h; }
    return { w, h };
  }

  /** Recopie une vue dans une miniature (cadrage « couvrir »). */
  function copier(depuis, vers, l, h) {
    if (!vers) return;
    const w = l || vers.width;
    const hh = h || vers.height;
    if (vers.width !== w) vers.width = w;
    if (vers.height !== hh) vers.height = hh;
    const g = vers.getContext('2d');
    g.clearRect(0, 0, w, hh);
    const k = Math.max(w / depuis.width, hh / depuis.height) * 1.08;
    const dw = depuis.width * k;
    const dh = depuis.height * k;
    g.drawImage(depuis, (w - dw) / 2, (hh - dh) / 2, dw, dh);
  }

  function rendreVue(r, cle) {
    const v = r.vues[cle];
    const t = taillePhysique(v.canvas, 1100);
    if (!t) return;
    const vue = vueScene(r, cle);
    if (etat.rendu === '3d' && studio && r.pret) studio.rendre(r.fiche.id, vue, v.canvas, r.zoom);
    else peindrePlat(v.canvas.getContext('2d'), t.w, t.h, r, vue, r.zoom, null);
    const m = r.minis[cle];
    if (m && m.canvas.getBoundingClientRect().width > 0) copier(v.canvas, m.canvas, 132, 132);
    if (cle === 'face' && r.carte) {
      copier(v.canvas, r.carte, 256, 208);
      r.carte.hidden = false;
    }
  }

  function rendreFile() {
    prevu = false;
    const t0 = performance.now();
    const active = rangeeActive();
    const ordre = [active].concat(rangees.filter((r) => r !== active));
    let fait = 0;
    for (const r of ordre) {
      if (r.marquageSale && (fait === 0 || performance.now() - t0 < 12)) {
        majMarquage(r);
        fait++;
      }
      for (const cle of Array.from(r.sales)) {
        if (fait > 0 && performance.now() - t0 > 12) { planifier(); return; }
        if (r.marquageSale) { planifier(); return; }
        r.sales.delete(cle);
        rendreVue(r, cle);
        fait++;
      }
    }
  }

  rangees.forEach((r) => {
    if (!('ResizeObserver' in window)) return;
    const obs = new ResizeObserver((entrees) => {
      entrees.forEach((e) => {
        const fig = e.target.closest('[data-vue]');
        if (fig) salir(r, [fig.dataset.vue]);
      });
    });
    Object.values(r.vues).forEach((v) => obs.observe(v.canvas));
  });

  // ---------------------------------------------------------------- 3D
  let chargement3d = false;
  let echec3d = false;
  const bascule = $('[data-action="bascule"]');

  /** Essaie un contexte WebGL, puis le rend aussitôt (le navigateur en limite le nombre). */
  function essaiWebgl(attributs) {
    try {
      const c = document.createElement('canvas');
      const gl = c.getContext('webgl2', attributs) || c.getContext('webgl', attributs);
      if (!gl) return false;
      const perte = gl.getExtension('WEBGL_lose_context');
      if (perte) perte.loseContext();
      return true;
    } catch (e) {
      return false;
    }
  }

  const peut3d = !!(window.HTMLScriptElement && HTMLScriptElement.supports && HTMLScriptElement.supports('importmap')) && essaiWebgl({});

  /** Appareil modeste, rendu 3D logiciel ou économie de données : la 3D attend qu'on la demande. */
  function appareilModeste() {
    const n = navigator;
    if (n.connection && n.connection.saveData) return true;
    // En 2G, la 3D (environ 200 Ko compressés) n'est pas chargée d'office : le bouton « Voir en 3D » reste là.
    if (n.connection && /(^|-)2g$/.test(n.connection.effectiveType || '')) return true;
    if (n.deviceMemory && n.deviceMemory < 2) return true;
    if (n.hardwareConcurrency && n.hardwareConcurrency < 3) return true;
    return !essaiWebgl({ failIfMajorPerformanceCaveat: true });
  }

  function majEtat3d() {
    racine.dataset.rendu = etat.rendu;
    texte('mode').textContent = chargement3d ? 'Chargement de la 3D…' : (etat.rendu === '3d' ? 'Aperçu 3D' : 'Vue à plat');
    bascule.hidden = !peut3d || etat.rendu === '3d' || chargement3d;
    bascule.textContent = echec3d ? 'Réessayer la 3D' : 'Voir en 3D';
    rangees.forEach((r) => {
      r.outils.tour.disabled = !peut3d;
      r.outils.tour.title = peut3d ? 'Rotation libre à 360°' : 'La rotation à 360° demande un navigateur avec la 3D (WebGL)';
    });
  }

  function perte3d() {
    studio = null;
    echec3d = true;
    etat.rendu = 'plat';
    rangees.forEach((r) => {
      if (r.tourCtrl) arreterTour(r);
      r.pret = false;
      r.zones = {};
      r.marquageSale = true;
      if (r.credits) r.credits.hidden = true;
      salir(r);
    });
    majEtat3d();
    toast('La 3D s\'est arrêtée : la vue à plat prend le relais.');
  }

  let apres3d = null;
  async function charger3d(auto) {
    if (chargement3d || studio) return;
    chargement3d = true;
    majEtat3d();
    try {
      const module = await import(D.urls.scene);
      studio = module.creerStudio({ reduit, surErreur: perte3d });
      const active = rangeeActive();
      const ordre = [active].concat(rangees.filter((r) => r !== active));
      for (const r of ordre) {
        const info = await studio.article(r.fiche.id, r.fiche);
        if (!studio) return;
        r.pret = true;
        r.zones = info.zones || {};
        if (r.credits) r.credits.hidden = !info.glb;
        studio.couleurs(r.fiche.id, couleursScene(r));
        r.marquageSale = true;
        if (etat.rendu !== '3d') {
          etat.rendu = '3d';
          chargement3d = false;
          majEtat3d();
        }
        salir(r);
        if (apres3d && apres3d.r === r) {
          const suite = apres3d;
          apres3d = null;
          demarrerTour(r, suite.vue);
        }
      }
    } catch (e) {
      if (window.console) console.warn('Studio textile : vue à plat', e);
      if (studio) { try { studio.detruire(); } catch (_) { /* déjà perdu */ } }
      studio = null;
      echec3d = true;
      etat.rendu = 'plat';
      rangees.forEach((r) => { r.pret = false; r.zones = {}; r.marquageSale = true; salir(r); });
      if (!auto) toast('La 3D ne peut pas s\'afficher ici : la vue à plat reste disponible.');
    } finally {
      chargement3d = false;
      apres3d = null;
      majEtat3d();
    }
  }

  bascule.addEventListener('click', () => {
    memoire('atelier3d-vue', '3d');
    echec3d = false;
    charger3d(false);
  });

  function couleursScene(r) {
    const c = couleursDe(r);
    return { tissu: c.tissu, seconde: c.seconde };
  }

  function majCouleurs() {
    let texteChange = false;
    FACES.forEach((f) => {
      const t = etat.textes[f];
      if (!t.auto) return;
      const c = couleurDans(etat.fiche, etat.couleur) || couleurDefaut(etat.fiche);
      const voulue = clair(c.hex) ? 'Noir' : 'Blanc';
      if (t.couleurTexte !== voulue) { t.couleurTexte = voulue; texteChange = true; }
    });
    rangees.forEach((r) => {
      if (studio && r.pret) studio.couleurs(r.fiche.id, couleursScene(r));
    });
    if (texteChange) majTexte();
    toutSalir(texteChange);
  }

  // ---------------------------------------------------------------- 360°, zoom, plein écran
  function demarrerTour(r, vue) {
    rangees.forEach((x) => { if (x !== r && x.tourCtrl) arreterTour(x); });
    r.el.setAttribute('data-tour-actif', '');
    r.tour.hidden = false;
    const angles = studio.angles(r.fiche.id, vue ? vueScene(r, vue) : 'face');
    r.tourCtrl = studio.tour(r.fiche.id, r.tourCanvas, { az: angles[0], el: angles[1], zoom: r.zoom, auto: !vue });
    r.outils.tour.setAttribute('aria-pressed', String(!vue));
    Object.keys(r.minis).forEach((k) => r.minis[k].bouton.setAttribute('aria-pressed', String(k === (vue || ''))));
  }

  function arreterTour(r) {
    if (r.tourCtrl) r.tourCtrl.arreter();
    r.tourCtrl = null;
    r.el.removeAttribute('data-tour-actif');
    r.tour.hidden = true;
    r.outils.tour.setAttribute('aria-pressed', 'false');
    Object.keys(r.minis).forEach((k) => r.minis[k].bouton.setAttribute('aria-pressed', String(k === 'face')));
    salir(r);
  }

  /** 360° ou vue en grand (miniature) : demande la 3D si elle n'est pas encore là. */
  function voirEnGrand(r, vue) {
    if (studio && r.pret) {
      if (r.tourCtrl && !vue) {
        // 360° pressé : on s'arrête ; vue en grand (miniature) : on se remet à tourner.
        if (r.outils.tour.getAttribute('aria-pressed') === 'true') { arreterTour(r); return; }
        r.tourCtrl.tourner(true);
        r.outils.tour.setAttribute('aria-pressed', 'true');
        Object.keys(r.minis).forEach((k) => r.minis[k].bouton.setAttribute('aria-pressed', 'false'));
        return;
      }
      if (r.tourCtrl && vue) {
        const a = studio.angles(r.fiche.id, vueScene(r, vue));
        r.tourCtrl.aller(a[0], a[1]);
        r.outils.tour.setAttribute('aria-pressed', 'false');
        Object.keys(r.minis).forEach((k) => r.minis[k].bouton.setAttribute('aria-pressed', String(k === vue)));
        return;
      }
      demarrerTour(r, vue);
      return;
    }
    if (!peut3d) {
      toast('La rotation à 360° demande un navigateur avec la 3D (WebGL).');
      return;
    }
    apres3d = { r, vue };
    echec3d = false;
    memoire('atelier3d-vue', '3d');
    charger3d(false);
  }

  function zoomer(r, f) {
    if (r.tourCtrl) { r.tourCtrl.zoomer(f); return; }
    r.zoom = borne(r.zoom * f, 0.8, 2.4);
    salir(r);
  }

  function pleinEcran(r) {
    if (document.fullscreenElement === r.el) { document.exitFullscreen().catch(() => {}); return; }
    if (r.el.classList.contains('a3d-plein')) { sortirPlein(r); return; }
    if (r.el.requestFullscreen && document.fullscreenEnabled) r.el.requestFullscreen().catch(() => entrerPlein(r));
    else entrerPlein(r);
  }

  function entrerPlein(r) {
    r.el.classList.add('a3d-plein');
    document.documentElement.style.overflow = 'hidden';
    r.outils.plein.setAttribute('aria-pressed', 'true');
    salir(r);
  }

  function sortirPlein(r) {
    r.el.classList.remove('a3d-plein');
    document.documentElement.style.overflow = '';
    r.outils.plein.setAttribute('aria-pressed', 'false');
    salir(r);
  }

  document.addEventListener('fullscreenchange', () => {
    rangees.forEach((r) => {
      r.outils.plein.setAttribute('aria-pressed', String(document.fullscreenElement === r.el));
      salir(r);
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    rangees.forEach((r) => { if (r.el.classList.contains('a3d-plein')) sortirPlein(r); });
  });

  rangees.forEach((r) => {
    r.outils.tour.addEventListener('click', () => voirEnGrand(r, null));
    r.outils.plus.addEventListener('click', () => zoomer(r, 1.18));
    r.outils.moins.addEventListener('click', () => zoomer(r, 1 / 1.18));
    r.outils.plein.addEventListener('click', () => pleinEcran(r));
    Object.keys(r.minis).forEach((k) => r.minis[k].bouton.addEventListener('click', () => voirEnGrand(r, k)));
    $('[data-choisir]', r.el).addEventListener('click', () => choisirFiche(r.fiche, null));
    // Toucher une vue choisit l'article (sans gêner le glissement des vues sur téléphone).
    Object.values(r.vues).forEach((v) => v.canvas.addEventListener('click', () => {
      if (etat.fiche !== r.fiche) choisirFiche(r.fiche, null);
    }));
  });

  // ---------------------------------------------------------------- pastilles
  function pastilles(zone, liste, choisie, choisir, sans) {
    const conteneur = $('[data-pastilles="' + zone + '"]');
    conteneur.textContent = '';
    const ajouter = (nom, hex, libelle) => {
      const b = document.createElement('button');
      b.type = 'button';
      if (hex) b.style.background = hex;
      else b.className = 'a3d-sans';
      b.title = libelle;
      b.setAttribute('aria-label', libelle);
      b.setAttribute('aria-pressed', String(nom === choisie));
      b.addEventListener('click', () => {
        $$('button', conteneur).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        choisir(nom);
      });
      conteneur.appendChild(b);
    };
    if (sans) ajouter(null, null, sans);
    liste.forEach((c) => ajouter(c.nom, c.hex, c.nom));
  }

  function choisirCouleur(nom) {
    etat.couleur = nom;
    rangeeActive().couleurPropre = nom;
    texte('couleur').textContent = nom;
    majCouleurs();
  }

  function choisirVisiere(nom) {
    etat.visiere = nom;
    texte('visiere').textContent = nom;
    majCouleurs();
  }

  function choisirBords(nom) {
    etat.bords = nom;
    texte('bords').textContent = nom || 'comme le polo';
    majCouleurs();
  }

  // ---------------------------------------------------------------- article
  function choisirFiche(fiche, reprise) {
    const avant = etat.fiche;
    etat.fiche = fiche;
    $$('[data-article]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.article === fiche.id)));
    $$('[data-onglet]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.onglet === fiche.forme)));
    rangees.forEach((r) => r.el.classList.toggle('a3d-rangee-active', r.fiche === fiche));

    const noms = fiche.couleurs.map((c) => c.nom);
    if (reprise && noms.includes(reprise.couleur)) etat.couleur = reprise.couleur;
    else if (!noms.includes(etat.couleur)) etat.couleur = (couleurDans(fiche, rangeeDe(fiche).couleurPropre) || couleurDefaut(fiche)).nom;
    if (fiche.forme === 'casquette') {
      if (reprise && noms.includes(reprise.visiere)) etat.visiere = reprise.visiere;
      else if (!noms.includes(etat.visiere)) etat.visiere = noms.includes('Rouge') ? 'Rouge' : etat.couleur;
    }
    if (fiche.forme === 'polo') {
      if (reprise) etat.bords = noms.includes(reprise.visiere) ? reprise.visiere : null;
      else if (!noms.includes(etat.bords)) etat.bords = null;
    }
    pastilles('tissu', fiche.couleurs, etat.couleur, choisirCouleur);
    texte('couleur').textContent = etat.couleur;
    $('[data-bloc="visiere"]').hidden = fiche.forme !== 'casquette';
    $('[data-bloc="bords"]').hidden = fiche.forme !== 'polo';
    if (fiche.forme === 'casquette') {
      pastilles('visiere', fiche.couleurs, etat.visiere, choisirVisiere);
      texte('visiere').textContent = etat.visiere;
    }
    if (fiche.forme === 'polo') {
      pastilles('bords', fiche.couleurs, etat.bords, choisirBords, 'Comme le polo');
      texte('bords').textContent = etat.bords || 'comme le polo';
    }
    $$('[data-positions]').forEach((g) => { g.hidden = g.dataset.positions !== famille(fiche); });

    // Quantités.
    const grille = $('[data-tailles]');
    grille.textContent = '';
    fiche.tailles.forEach((t) => {
      if (!(t in etat.tailles)) etat.tailles[t] = reprise ? 0 : (TAILLES_DEFAUT[t] || 0);
      const l = document.createElement('label');
      l.textContent = t;
      const i = document.createElement('input');
      i.type = 'number';
      i.min = '0';
      i.max = String(D.quantiteMax);
      i.inputMode = 'numeric';
      i.value = String(etat.tailles[t]);
      i.addEventListener('input', () => {
        etat.tailles[t] = borne(parseInt(i.value, 10) || 0, 0, D.quantiteMax);
        majQuantite();
      });
      l.appendChild(i);
      grille.appendChild(l);
    });
    if (reprise) {
      if (fiche.tailles.length) Object.keys(reprise.tailles || {}).forEach((t) => { etat.tailles[t] = reprise.tailles[t]; });
      else etat.quantite = reprise.quantite;
      $$('input', grille).forEach((i, k) => { i.value = String(etat.tailles[fiche.tailles[k]] || 0); });
    }
    if (!fiche.tailles.length && etat.quantite < fiche.min) etat.quantite = Math.max(fiche.min, 20);
    champ('quantite').value = String(etat.quantite);
    champ('quantite').min = String(fiche.min);
    $('[data-bloc="tailles"]').hidden = !fiche.tailles.length;
    $('[data-bloc="quantite"]').hidden = !!fiche.tailles.length;

    majPositions();
    majTexte();
    majLogo();
    majQuantite();
    if (avant !== fiche) majCouleurs();
  }

  $$('[data-article]').forEach((b) => b.addEventListener('click', () => {
    const fiche = D.fiches.find((f) => f.id === b.dataset.article);
    if (fiche && fiche !== etat.fiche) choisirFiche(fiche, null);
  }));
  $$('[data-onglet]').forEach((b) => b.addEventListener('click', () => {
    if (etat.fiche.forme === b.dataset.onglet) return;
    const fiche = D.fiches.find((f) => f.forme === b.dataset.onglet);
    if (!fiche) return;
    choisirFiche(fiche, null);
    // Sur téléphone, la rangée de l'article choisi vient à l'écran si les étapes sont déjà passées.
    const r = rangeeDe(fiche);
    const empile = $('.a3d-scene').getBoundingClientRect().top >= $('.a3d-panneau').getBoundingClientRect().bottom - 1;
    if (empile && r.el.getBoundingClientRect().top < 0) r.el.scrollIntoView({ behavior: reduit ? 'auto' : 'smooth', block: 'start' });
  }));

  // ---------------------------------------------------------------- positions
  function majPositions() {
    const fam = famille(etat.fiche);
    $$('[data-positions="' + fam + '"] [data-position]').forEach((b) => {
      const face = b.dataset.face;
      const place = etat.places[fam][face];
      const ici = place.position === b.dataset.position;
      b.setAttribute('aria-pressed', String(ici && place.logo));
      b.classList.toggle('a3d-texte-ici', ici && !place.logo && !!etat.textes[face].texte.trim());
    });
    $$('[data-libelle-dos]').concat($$('[data-libelle-rendu-dos]', fenetre)).forEach((e) => { e.textContent = libelleFace('dos'); });
    majLibellesVues();
  }

  $$('[data-position]').forEach((b) => b.addEventListener('click', () => {
    const fam = famille(etat.fiche);
    const face = b.dataset.face;
    const place = etat.places[fam][face];
    const avant = facesMarquees().length;
    if (place.position === b.dataset.position && place.logo) {
      place.logo = false;
    } else {
      place.position = b.dataset.position;
      place.logo = true;
    }
    majPositions();
    majAlerte();
    toutSalir(true);
    if (avant !== facesMarquees().length) demanderPrix();
  }));

  champ('taille').addEventListener('input', (e) => {
    etat.taille = borne(parseInt(e.target.value, 10) || 80, 15, 100);
    texte('taille').textContent = etat.taille + ' %';
    majAlerte();
    toutSalir(true);
  });

  // ---------------------------------------------------------------- texte
  function majTexte() {
    const f = etat.faceTexte;
    const t = etat.textes[f];
    $$('[data-face-texte]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.faceTexte === f)));
    FACES.forEach((n) => { $('[data-marque="' + n + '"]').hidden = !faceMarquee(famille(etat.fiche), n); });
    if (champ('texte').value !== t.texte) champ('texte').value = t.texte;
    champ('police').value = t.police;
    champ('police').style.fontFamily = '"' + t.police + '", inherit';
    champ('gras').setAttribute('aria-pressed', String(t.gras));
    champ('place').value = t.placeTexte;
    pastilles('texte', Object.keys(D.couleursTexte).map((n) => ({ nom: n, hex: D.couleursTexte[n] })), t.couleurTexte, (n) => {
      const x = etat.textes[etat.faceTexte];
      x.couleurTexte = n;
      x.auto = false;
      toutSalir(true);
    });
  }

  function reglerTexte(cle, valeur) {
    const fam = famille(etat.fiche);
    const avant = facesMarquees(fam).length;
    etat.textes[etat.faceTexte][cle] = valeur;
    FACES.forEach((n) => { $('[data-marque="' + n + '"]').hidden = !faceMarquee(fam, n); });
    if (cle === 'texte') {
      majPositions();
      majAlerte();
    }
    toutSalir(true);
    if (avant !== facesMarquees(fam).length) demanderPrix();
  }

  $$('[data-face-texte]').forEach((b) => b.addEventListener('click', () => {
    etat.faceTexte = b.dataset.faceTexte;
    majTexte();
  }));
  champ('texte').addEventListener('input', (e) => reglerTexte('texte', e.target.value));
  champ('police').addEventListener('change', (e) => {
    const nom = e.target.value;
    champ('police').style.fontFamily = '"' + nom + '", inherit';
    reglerTexte('police', nom);
    police(nom).then(() => toutSalir(true));
  });
  champ('gras').addEventListener('click', (e) => {
    const oui = e.currentTarget.getAttribute('aria-pressed') !== 'true';
    e.currentTarget.setAttribute('aria-pressed', String(oui));
    reglerTexte('gras', oui);
  });
  champ('place').addEventListener('change', (e) => reglerTexte('placeTexte', e.target.value));

  // ---------------------------------------------------------------- logo
  function majLogo() {
    const img = etat.logo;
    const reel = img && !img.exemple;
    $('[data-depot="vide"]').hidden = !!reel;
    $('[data-depot="plein"]').hidden = !reel;
    if (reel) {
      $('[data-vignette]').src = img.vignette;
      texte('fichier').textContent = img.nom;
      texte('fichier-aide').textContent = img.envoi === 'encours'
        ? 'Envoi en cours…'
        : img.envoi === 'erreur' ? 'Non envoyé' : img.largeur + ' × ' + img.hauteur + ' px' + (img.svg ? ' · SVG converti en PNG' : '') + ' · touchez pour changer';
    }
    $('[data-zone="depot"]').classList.toggle('a3d-envoi', !!(img && img.envoi === 'encours'));
    const retirer = $('[data-action="retirer"]');
    retirer.hidden = !img && !etat.surcharges.devant && !etat.surcharges.dos;
    retirer.textContent = img && img.exemple ? 'Retirer le logo d\'exemple' : 'Retirer le logo';
    majAlerte();
  }

  /** Résolution de l'image à sa taille d'impression : même calcul que le serveur (ImagesAtelier::controleResolution). */
  function controle(face) {
    const img = imageDe(face);
    const fam = famille(etat.fiche);
    const z = zoneImpression(etat.fiche, face, etat.places[fam][face].position);
    const t = Math.max(5, tailleImage(face)) / 100;
    const echelle = Math.min(z.l * t / Math.max(1, img.largeur), z.h * t / Math.max(1, img.hauteur));
    const cm = img.largeur * echelle;
    const dpi = Math.floor(img.largeur / Math.max(0.1, cm / 2.54));
    const conseil = Math.ceil(cm / 2.54 * D.dpi.conseille);
    const cmTexte = (Math.round(cm * 10) / 10).toString().replace('.', ',');
    const ou = ' (' + libelleEmplacement(fam, face).toLowerCase() + ')';
    if (dpi >= D.dpi.conseille) return null;
    if (dpi >= D.dpi.minimum) return { etat: 'attention', message: 'Image un peu juste (' + img.largeur + ' px de large) pour ' + cmTexte + ' cm' + ou + ' : préférez au moins ' + fr.format(conseil) + ' px, ou réduisez la taille.' };
    return { etat: 'erreur', message: 'Image en basse résolution (' + img.largeur + ' px de large) : risque de flou à l\'impression sur ' + cmTexte + ' cm' + ou + '. Préférez au moins ' + fr.format(conseil) + ' px.' };
  }

  function majAlerte() {
    const a = $('[data-alerte]');
    const fam = famille(etat.fiche);
    let m = null;
    const img = etat.logo;
    if (img && img.exemple) m = { etat: 'info', message: 'Logo d\'exemple : chargez le vôtre, ou retirez-le, avant de valider.' };
    else if (img && img.envoi === 'erreur') m = { etat: 'erreur', message: img.erreur };
    else {
      for (const face of FACES) {
        if (!logoSur(fam, face) || imageDe(face).exemple) continue;
        const c = controle(face);
        if (c && (!m || c.etat === 'erreur')) m = c;
      }
    }
    if (!m && imageDe('devant') && !logoSur(fam, 'devant') && !logoSur(fam, 'dos')) m = { etat: 'info', message: 'Choisissez une position pour y mettre le logo.' };
    a.hidden = !m;
    if (m) {
      a.textContent = m.message;
      a.dataset.etat = m.etat;
    }
  }

  /** Image décodée, et une copie réduite pour dessiner vite (1 200 px au plus). */
  function preparer(img) {
    const l = img.naturalWidth;
    const h = img.naturalHeight;
    const r = Math.min(1, 1200 / Math.max(l, h));
    let dessin = img;
    if (r < 1) {
      dessin = document.createElement('canvas');
      dessin.width = Math.round(l * r);
      dessin.height = Math.round(h * r);
      dessin.getContext('2d').drawImage(img, 0, 0, dessin.width, dessin.height);
    }
    return { dessin, largeur: l, hauteur: h };
  }

  function charger(url) {
    return new Promise((ok, ko) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = () => ok(img);
      img.onerror = ko;
      img.src = url;
    });
  }

  /**
   * SVG lu dans la page seulement : chargé comme une image (aucun script ni
   * ressource extérieure n'y tourne), dessiné à 2 400 px de côté puis
   * envoyé en PNG. Le serveur, lui, refuse toujours les SVG.
   */
  async function rasteriser(fichier) {
    if (fichier.size > SVG_POIDS_MAX) throw new Error('SVG trop lourd (2 Mo au plus) : exportez-le en PNG.');
    const brut = await fichier.text();
    const doc = new DOMParser().parseFromString(brut, 'image/svg+xml');
    const svg = doc.documentElement;
    if (!svg || svg.nodeName.toLowerCase() !== 'svg' || doc.getElementsByTagName('parsererror').length) {
      throw new Error('Ce SVG est illisible : exportez-le en PNG.');
    }
    let l = 0;
    let h = 0;
    const vb = (svg.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);
    if (vb.length === 4 && vb[2] > 0 && vb[3] > 0) {
      l = vb[2];
      h = vb[3];
    } else {
      l = parseFloat(svg.getAttribute('width')) || 300;
      h = parseFloat(svg.getAttribute('height')) || 150;
      svg.setAttribute('viewBox', '0 0 ' + l + ' ' + h);
    }
    const k = SVG_COTE / Math.max(l, h);
    const W = Math.max(1, Math.round(l * k));
    const H = Math.max(1, Math.round(h * k));
    svg.setAttribute('width', String(W));
    svg.setAttribute('height', String(H));
    const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }));
    try {
      const img = await charger(url).catch(() => { throw new Error('Ce SVG ne s\'affiche pas ici : exportez-le en PNG.'); });
      const c = document.createElement('canvas');
      c.width = W;
      c.height = H;
      c.getContext('2d').drawImage(img, 0, 0, W, H);
      const png = await new Promise((ok, ko) => {
        try {
          c.toBlob((b) => (b ? ok(b) : ko(new Error('Ce SVG n\'a pas pu être converti : exportez-le en PNG.'))), 'image/png');
        } catch (e) {
          ko(new Error('Ce SVG n\'a pas pu être converti : exportez-le en PNG.'));
        }
      });
      const nom = (fichier.name || 'logo.svg').replace(/\.svg$/i, '') + '.png';
      return new File([png], nom, { type: 'image/png' });
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  async function deposer(fichier) {
    if (!fichier) return;
    const svg = /svg/i.test(fichier.type) || /\.svg$/i.test(fichier.name || '');
    let envoi = fichier;
    if (svg) {
      try {
        envoi = await rasteriser(fichier);
      } catch (e) {
        toast(e.message || 'Ce SVG ne peut pas être lu : exportez-le en PNG.');
        return;
      }
    } else if (!/^image\/(png|jpeg|webp)$/.test(fichier.type)) {
      toast('Choisissez une image PNG, JPG, SVG ou WebP.');
      return;
    }
    if (envoi.size > D.envoiMax) { toast('Image trop lourde : ' + Math.floor(D.envoiMax / 1048576) + ' Mo au plus.'); return; }
    const url = URL.createObjectURL(envoi);
    let img;
    try {
      img = await charger(url);
    } catch (e) {
      toast('Image illisible : essayez un PNG ou un JPG.');
      return;
    }
    const p = preparer(img);
    const image = { dessin: p.dessin, vignette: url, nom: fichier.name || envoi.name, largeur: p.largeur, hauteur: p.hauteur, jeton: null, source: null, exemple: false, envoi: 'encours', erreur: null, svg };
    const fam = famille(etat.fiche);
    const avant = facesMarquees(fam).length;
    etat.logo = image;
    etat.surcharges = { devant: null, dos: null };
    if (!etat.places[fam].devant.logo && !etat.places[fam].dos.logo) etat.places[fam].devant.logo = true;
    majLogo();
    majPositions();
    majTexte();
    toutSalir(true);
    if (avant !== facesMarquees(fam).length) demanderPrix();

    const corps = new FormData();
    corps.append('image', envoi, envoi.name || 'logo.png');
    try {
      const r = await fetch(D.urls.image, { method: 'POST', body: corps, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': D.csrf, Accept: 'application/json' } });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.jeton) throw new Error(j.erreur || (r.status === 413 ? 'Image trop lourde pour être envoyée.' : 'L\'image n\'est pas arrivée : réessayez.'));
      image.jeton = j.jeton;
      image.nom = svg ? image.nom : j.nom;
      image.largeur = j.largeur;
      image.hauteur = j.hauteur;
      image.envoi = 'ok';
    } catch (e) {
      image.envoi = 'erreur';
      image.erreur = e && e.message && !/fetch|network/i.test(e.message) ? e.message : 'L\'image n\'est pas arrivée (connexion ?) : choisissez-la de nouveau.';
    }
    if (etat.logo === image) majLogo();
  }

  const depot = $('[data-zone="depot"]');
  champ('fichier').addEventListener('change', (e) => { deposer(e.target.files[0]); e.target.value = ''; });
  ['dragenter', 'dragover'].forEach((ev) => depot.addEventListener(ev, (e) => { e.preventDefault(); depot.classList.add('a3d-survol'); }));
  ['dragleave', 'drop'].forEach((ev) => depot.addEventListener(ev, (e) => { e.preventDefault(); depot.classList.remove('a3d-survol'); }));
  depot.addEventListener('drop', (e) => deposer(e.dataTransfer.files[0]));
  $('[data-action="retirer"]').addEventListener('click', () => {
    const fam = famille(etat.fiche);
    const avant = facesMarquees(fam).length;
    etat.logo = null;
    etat.surcharges = { devant: null, dos: null };
    majLogo();
    majPositions();
    majTexte();
    toutSalir(true);
    if (avant !== facesMarquees(fam).length) demanderPrix();
  });

  /** Logo d'exemple, dessiné ici : montré, jamais envoyé. */
  function logoExemple() {
    const c = document.createElement('canvas');
    c.width = 900;
    c.height = 640;
    const g = c.getContext('2d');
    g.translate(450, 300);
    g.fillStyle = '#1B6CF2';
    g.beginPath(); g.arc(0, -40, 190, 0, Math.PI * 2); g.fill();
    g.fillStyle = '#FF7A1F';
    g.beginPath(); g.moveTo(-120, 40); g.lineTo(0, -170); g.lineTo(120, 40); g.closePath(); g.fill();
    g.fillStyle = '#ffffff';
    g.beginPath(); g.arc(0, -30, 52, 0, Math.PI * 2); g.fill();
    g.fillStyle = '#14213D';
    g.font = '900 104px "Arial Black", "Helvetica Neue", sans-serif';
    g.textAlign = 'center';
    g.textBaseline = 'alphabetic';
    g.lineWidth = 16;
    g.strokeStyle = '#ffffff';
    g.lineJoin = 'round';
    g.strokeText('VOTRE LOGO', 0, 300);
    g.fillText('VOTRE LOGO', 0, 300);
    return { dessin: c, vignette: c.toDataURL('image/png'), nom: 'Logo d\'exemple', largeur: 900, hauteur: 640, jeton: null, source: null, exemple: true, envoi: 'ok', erreur: null };
  }

  // ---------------------------------------------------------------- quantités et prix
  function majQuantite() {
    const n = quantite();
    texte('pieces').textContent = n + ' pièce' + (n > 1 ? 's' : '');
    demanderPrix();
  }

  champ('quantite').addEventListener('input', (e) => {
    etat.quantite = borne(parseInt(e.target.value, 10) || 0, 0, D.quantiteMax);
    majQuantite();
  });

  let minuteurPrix = 0;
  let numeroPrix = 0;
  let affiche = 0;
  function demanderPrix() {
    window.clearTimeout(minuteurPrix);
    $('[data-prix]').classList.add('a3d-attente');
    minuteurPrix = window.setTimeout(chargerPrix, 250);
  }

  async function chargerPrix() {
    const n = ++numeroPrix;
    const parametres = new URLSearchParams({ modele: etat.fiche.id, quantite: String(quantite()), faces: String(Math.max(1, facesMarquees().length)) });
    let d;
    try {
      const r = await fetch(D.urls.prix + '?' + parametres, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      d = await r.json();
    } catch (e) {
      d = { erreur: 'Prix indisponible pour le moment : vérifiez la connexion.' };
    }
    if (n !== numeroPrix) return;
    etat.prix = d;
    afficherPrix(d);
  }

  function compter(el, vers) {
    const depart = affiche;
    affiche = vers;
    if (reduit || !depart || depart === vers) { el.textContent = montant(vers); return; }
    const t0 = performance.now();
    const pas = (t) => {
      const k = Math.min(1, (t - t0) / 450);
      const e = 1 - Math.pow(1 - k, 3);
      el.textContent = montant(depart + (vers - depart) * e);
      if (k < 1 && affiche === vers) requestAnimationFrame(pas);
    };
    requestAnimationFrame(pas);
  }

  function libelleFaces() {
    const f = facesMarquees();
    if (f.length === 2) return 'devant + ' + libelleFace('dos').toLowerCase();
    if (f.length === 1) return libelleFace(f[0]).toLowerCase();
    return 'sans marquage';
  }

  function afficherPrix(d) {
    const zone = $('[data-prix]');
    zone.classList.remove('a3d-attente');
    const detail = texte('detail');
    const total = texte('total');
    const promo = texte('promotion');
    const prochain = texte('prochain');
    const q = quantite();
    zone.classList.toggle('a3d-prix-erreur', !!d.erreur);
    if (d.erreur) {
      detail.textContent = d.erreur;
      if (total) { total.textContent = '—'; affiche = 0; }
      promo.hidden = true;
      prochain.hidden = true;
      return;
    }
    const morceaux = [q + ' pièce' + (q > 1 ? 's' : ''), libelleFaces()];
    if (d.montants && d.prixPiece !== undefined) morceaux.push(montant(d.prixPiece) + ' / pièce');
    if (d.pourcentage) morceaux.push('remise ' + d.pourcentage + ' %');
    if (d.b2b) morceaux.push('prix revendeur');
    detail.textContent = morceaux.join(' · ');
    if (total && d.total !== undefined) compter(total, d.total);
    promo.hidden = !d.promotion;
    if (d.promotion) promo.textContent = 'Promotion « ' + d.promotion.libelle + ' » : −' + montant(d.promotion.montant) + ', déduite par l\'atelier en confirmant la commande.';
    prochain.hidden = !d.prochain;
    if (d.prochain) prochain.textContent = 'Plus que ' + d.prochain.manque + ' pièce' + (d.prochain.manque > 1 ? 's' : '') + ' pour −' + d.prochain.pourcentage + ' % sur chaque pièce.';
  }

  // ---------------------------------------------------------------- vues pour l'atelier et maquette
  async function policesPretes() {
    await Promise.all(FACES.map((n) => police(etat.textes[n].police)));
    rangees.forEach((r) => { r.marquageSale = true; });
  }

  /** Image carrée d'une vue de la rangée, en 3D si elle est là, sinon à plat. */
  function capture(r, vue, cote, fond) {
    if (r.marquageSale) majMarquage(r);
    if (etat.rendu === '3d' && studio && r.pret) return studio.capturer(r.fiche.id, vue, cote, fond);
    const c = document.createElement('canvas');
    c.width = c.height = cote;
    peindrePlat(c.getContext('2d'), cote, cote, r, vue, 1, fond);
    return c;
  }

  const enPng = (c) => new Promise((ok, ko) => c.toBlob((b) => (b ? ok(b) : ko(new Error('vue'))), 'image/png'));

  function chargerLogoSite() {
    return charger(D.urls.logo).catch(() => null);
  }

  /** Maquette PNG : les trois vues de l'article choisi, le nom du site ; ni prix ni nom du client. Rien ne part au serveur. */
  async function telecharger() {
    const bouton = $('[data-action="telecharger"]');
    bouton.disabled = true;
    try {
      await Promise.all([policesPretes(), police('Montserrat')]);
      const r = rangeeActive();
      const cles = Object.keys(r.vues);
      const cote = 960;
      const marge = 56;
      const tete = 190;
      const pied = 120;
      const W = marge * 2 + cles.length * cote + (cles.length - 1) * 28;
      const H = tete + cote + 64 + pied;
      const c = document.createElement('canvas');
      c.width = W;
      c.height = H;
      const g = c.getContext('2d');
      g.fillStyle = '#F4F6FA';
      g.fillRect(0, 0, W, H);
      const logo = await chargerLogoSite();
      let x = marge;
      if (logo) {
        const lh = 96;
        const lw = lh * logo.naturalWidth / Math.max(1, logo.naturalHeight);
        g.drawImage(logo, marge, 48, lw, lh);
        x = marge + lw + 36;
      }
      g.fillStyle = '#14213D';
      g.textBaseline = 'alphabetic';
      g.textAlign = 'left';
      g.font = '800 54px "Montserrat", "Segoe UI", Arial, sans-serif';
      g.fillText('STUDIO TEXTILE', x, 100);
      g.fillStyle = '#5D6782';
      g.font = '600 30px "Montserrat", "Segoe UI", Arial, sans-serif';
      const c2 = couleursDe(r);
      let ligne = r.fiche.nom + ' · ' + (couleurDans(r.fiche, etat.couleur) || couleurDefaut(r.fiche)).nom;
      if (r.fiche.forme === 'casquette' && etat.visiere) ligne += ' · visière ' + etat.visiere;
      if (r.fiche.forme === 'polo' && c2.secondeChoisie) ligne += ' · bords ' + etat.bords;
      g.fillText(ligne, x, 146);
      g.textAlign = 'right';
      g.fillStyle = '#FF7A1F';
      g.font = '700 26px "Montserrat", "Segoe UI", Arial, sans-serif';
      g.fillText('Maquette', W - marge, 100);
      cles.forEach((cle, i) => {
        const vue = vueScene(r, cle);
        const image = capture(r, vue, cote, '#EAEFF7');
        const vx = marge + i * (cote + 28);
        g.save();
        g.beginPath();
        if (g.roundRect) g.roundRect(vx, tete, cote, cote, 28);
        else g.rect(vx, tete, cote, cote);
        g.clip();
        g.drawImage(image, vx, tete, cote, cote);
        g.restore();
        g.fillStyle = '#14213D';
        g.textAlign = 'center';
        g.font = '700 30px "Montserrat", "Segoe UI", Arial, sans-serif';
        g.fillText(r.vues[cle].libelle.textContent, vx + cote / 2, tete + cote + 48);
      });
      g.fillStyle = '#DFE4EE';
      g.fillRect(marge, H - pied + 12, W - marge * 2, 2);
      g.textAlign = 'left';
      g.fillStyle = '#14213D';
      g.font = '800 30px "Montserrat", "Segoe UI", Arial, sans-serif';
      g.fillText('Succès Imprim', marge, H - 48);
      g.fillStyle = '#5D6782';
      g.font = '500 24px "Montserrat", "Segoe UI", Arial, sans-serif';
      g.textAlign = 'right';
      g.fillText('Aperçu indicatif : couleurs et proportions confirmées sur le BAT.', W - marge, H - 48);
      const blob = await enPng(c);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      const propre = (s) => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      a.href = url;
      a.download = 'maquette-' + propre(r.fiche.libelleForme) + '-' + propre(etat.couleur || '') + '.png';
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 30000);
      toast('Maquette enregistrée sur votre appareil.');
    } catch (e) {
      if (window.console) console.warn(e);
      toast('La maquette n\'a pas pu être préparée : réessayez.');
    } finally {
      bouton.disabled = false;
    }
  }
  $('[data-action="telecharger"]').addEventListener('click', telecharger);

  // ---------------------------------------------------------------- valider
  function probleme() {
    const q = quantite();
    const fam = famille(etat.fiche);
    if (q < 1) return 'Indiquez au moins une pièce.';
    if (q < etat.fiche.min) return 'Minimum ' + etat.fiche.min + ' pièces pour cet article.';
    if (etat.fiche.max && q > etat.fiche.max) return 'Au plus ' + etat.fiche.max + ' pièces en ligne : demandez un devis pour davantage.';
    if (!facesMarquees(fam).length) return 'Ajoutez votre logo ou un texte sur au moins une face.';
    for (const n of FACES) {
      if (!logoSur(fam, n)) continue;
      const img = imageDe(n);
      if (img.exemple) return 'Le logo affiché est un exemple : chargez le vôtre, ou retirez-le.';
      if (img.envoi === 'encours') return 'Un instant : votre logo finit d\'arriver.';
      if (img.envoi === 'erreur') return 'Votre logo n\'est pas arrivé : choisissez-le de nouveau ou retirez-le.';
    }
    if (etat.prix && etat.prix.erreur) return etat.prix.erreur;
    return null;
  }

  let rendus = null;
  const urlsRendus = [];

  function ligne(table, libelle, valeur) {
    const tr = document.createElement('tr');
    const a = document.createElement('td');
    const b = document.createElement('td');
    a.textContent = libelle;
    b.textContent = valeur;
    tr.append(a, b);
    table.appendChild(tr);
  }

  function resumeFace(n) {
    const fam = famille(etat.fiche);
    if (!faceMarquee(fam, n)) return '—';
    const t = etat.textes[n];
    const m = [];
    if (logoSur(fam, n)) m.push('logo ' + imageDe(n).nom);
    if (t.texte.trim()) m.push('« ' + t.texte.trim() + ' » (' + t.police + (t.gras ? ' gras' : '') + ', ' + t.couleurTexte.toLowerCase() + ')');
    return libelleEmplacement(fam, n) + ' : ' + m.join(' + ');
  }

  async function ouvrirRecap() {
    const p = probleme();
    if (p) { toast(p); return; }
    const bouton = $('[data-action="valider"]');
    bouton.disabled = true;
    const r = rangeeActive();
    try {
      await policesPretes();
      const devant = await enPng(capture(r, 'face', 1024, FOND_CAPTURE));
      const dos = await enPng(capture(r, vueDos(r), 1024, FOND_CAPTURE));
      rendus = { devant, dos };
    } catch (e) {
      bouton.disabled = false;
      toast('Les vues de votre modèle n\'ont pas pu être préparées : réessayez.');
      return;
    }
    bouton.disabled = false;
    salir(r);
    urlsRendus.splice(0).forEach((u) => URL.revokeObjectURL(u));
    FACES.forEach((n) => {
      const u = URL.createObjectURL(rendus[n]);
      urlsRendus.push(u);
      fenetre.querySelector('[data-rendu="' + n + '"]').src = u;
    });
    fenetre.querySelector('[data-libelle-rendu-dos]').textContent = libelleFace('dos');
    const table = fenetre.querySelector('[data-recap]');
    table.textContent = '';
    const f = etat.fiche;
    const q = quantite();
    const c = couleursDe(r);
    ligne(table, 'Article', f.nom);
    ligne(table, 'Couleur', etat.couleur + (f.forme === 'casquette' ? ' · visière ' + etat.visiere : '') + (f.forme === 'polo' && c.secondeChoisie ? ' · bords ' + etat.bords : ''));
    ligne(table, f.tailles.length ? 'Tailles' : 'Quantité', f.tailles.length ? f.tailles.filter((t) => etat.tailles[t] > 0).map((t) => t + ' × ' + etat.tailles[t]).join(', ') : q + ' pièce' + (q > 1 ? 's' : ''));
    FACES.forEach((n) => ligne(table, libelleFace(n), resumeFace(n)));
    const d = etat.prix || {};
    if (D.montants && d.total !== undefined && !d.erreur) {
      ligne(table, 'Quantité', q + ' × ' + montant(d.prixPiece));
      if (d.finitions) ligne(table, 'Finitions', montant(d.finitions));
      ligne(table, 'Total TTC indicatif', montant(d.total));
      if (d.promotion) ligne(table, 'Promotion', '−' + montant(d.promotion.montant) + ', déduite en confirmant');
    } else if (!D.montants) {
      ligne(table, 'Prix', 'confirmé par votre valideur');
    }
    effacerErreurs();
    if (typeof fenetre.showModal === 'function') fenetre.showModal();
    else fenetre.setAttribute('open', '');
  }

  $('[data-action="valider"]').addEventListener('click', ouvrirRecap);
  fenetre.querySelector('[data-action="fermer"]').addEventListener('click', () => fenetre.close());
  fenetre.addEventListener('click', (e) => { if (e.target === fenetre) fenetre.close(); });

  function effacerErreurs() {
    const e = fenetre.querySelector('[data-erreur]');
    e.hidden = true;
    e.textContent = '';
    fenetre.querySelectorAll('[data-erreur-pour]').forEach((c) => {
      c.classList.remove('a3d-champ-erreur');
      const p = c.querySelector('.a3d-erreur');
      p.hidden = true;
      c.querySelector('input,textarea').removeAttribute('aria-invalid');
    });
  }

  function montrerErreurs(j) {
    const e = fenetre.querySelector('[data-erreur]');
    e.textContent = j.erreur || 'L\'envoi n\'a pas abouti : vérifiez la connexion et réessayez.';
    e.hidden = false;
    let premier = null;
    Object.keys(j.erreurs || {}).forEach((nom) => {
      const c = fenetre.querySelector('[data-erreur-pour="' + nom + '"]');
      if (!c) return;
      c.classList.add('a3d-champ-erreur');
      const p = c.querySelector('.a3d-erreur');
      p.textContent = j.erreurs[nom];
      p.hidden = false;
      const champErreur = c.querySelector('input,textarea');
      champErreur.setAttribute('aria-invalid', 'true');
      premier = premier || champErreur;
    });
    if (premier) premier.focus();
  }

  formulaire.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!rendus) return;
    effacerErreurs();
    const envoyer = fenetre.querySelector('[data-action="envoyer"]');
    const libelle = envoyer.textContent;
    envoyer.disabled = true;
    envoyer.textContent = 'Envoi…';
    const fam = famille(etat.fiche);
    const corps = new FormData(formulaire);
    corps.append('modele', etat.fiche.id);
    corps.append('couleur', etat.couleur);
    if (etat.fiche.forme === 'casquette') corps.append('visiere', etat.visiere);
    if (etat.fiche.forme === 'polo' && etat.bords) corps.append('bords', etat.bords);
    if (etat.fiche.tailles.length) etat.fiche.tailles.forEach((t) => corps.append('tailles[' + t + ']', String(etat.tailles[t] || 0)));
    else corps.append('quantite', String(etat.quantite));
    const faces = {};
    FACES.forEach((n) => {
      if (!faceMarquee(fam, n)) return;
      const t = etat.textes[n];
      const img = logoSur(fam, n) ? imageDe(n) : null;
      faces[n] = {
        image: img ? (img.jeton || img.source) : null,
        position: etat.places[fam][n].position,
        taille: etat.taille,
        hauteur: 0,
        texte: t.texte.trim(),
        police: t.police,
        gras: t.gras,
        couleurTexte: t.couleurTexte,
        placeTexte: t.placeTexte,
      };
    });
    corps.append('faces', JSON.stringify(faces));
    corps.append('rendu_devant', rendus.devant, 'devant.png');
    corps.append('rendu_dos', rendus.dos, 'dos.png');
    let j = {};
    let ok = false;
    try {
      const r = await fetch(D.urls.valider, { method: 'POST', body: corps, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      j = await r.json().catch(() => ({ erreur: r.status === 413 ? 'Envoi trop lourd : réduisez vos images.' : null }));
      ok = r.ok && !!j.redirection;
    } catch (err) {
      j = {};
    }
    if (ok) {
      envoyer.textContent = 'Envoyé ✓';
      window.location.assign(j.redirection);
      return;
    }
    envoyer.disabled = false;
    envoyer.textContent = libelle;
    montrerErreurs(j);
  });

  // ---------------------------------------------------------------- message
  let minuteurToast = 0;
  function toast(m) {
    document.querySelectorAll('.a3d-toast').forEach((x) => x.remove());
    const d = document.createElement('div');
    d.className = 'a3d-toast';
    d.setAttribute('role', 'status');
    d.textContent = m;
    document.body.appendChild(d);
    window.clearTimeout(minuteurToast);
    minuteurToast = window.setTimeout(() => d.remove(), 3600);
  }

  // ---------------------------------------------------------------- démarrage
  async function reprendre(r) {
    const fiche = D.fiches.find((f) => f.id === r.modele);
    const fam = fiche ? famille(fiche) : 'vetement';
    let taille = null;
    FACES.forEach((n) => {
      const x = (r.faces || {})[n];
      if (!x) {
        etat.places[fam][n].logo = false;
        return;
      }
      etat.textes[n] = {
        texte: x.texte || '',
        police: D.polices[x.police] ? x.police : POLICE_DEFAUT,
        gras: !!x.gras,
        couleurTexte: D.couleursTexte[x.couleurTexte] ? x.couleurTexte : 'Blanc',
        auto: false,
        placeTexte: x.placeTexte === 'dessus' ? 'dessus' : 'dessous',
      };
      // Création d'avant le studio : pas d'emplacement, le premier de la face (centre du devant, du dos ; arrière).
      etat.places[fam][n].position = x.position && D.emplacements[fam][n][x.position] ? x.position : premierePlace(fam, n);
      etat.places[fam][n].logo = !!x.image;
      if (taille === null && x.image) taille = x.taille;
    });
    etat.taille = borne(parseInt(taille, 10) || 80, 15, 100);
    champ('taille').value = String(etat.taille);
    texte('taille').textContent = etat.taille + ' %';
    if (!fiche) toast('L\'article de la création ' + r.numero + ' n\'est plus proposé : choisissez-en un autre.');
    choisirFiche(fiche || D.fiches[0], fiche ? r : null);
    const images = {};
    await Promise.all(FACES.map(async (n) => {
      const x = (r.faces || {})[n];
      if (!x || !x.url || !x.source) return;
      try {
        const img = await charger(x.url);
        const p = preparer(img);
        images[n] = { dessin: p.dessin, vignette: x.url, nom: x.nomImage || 'Image', largeur: p.largeur, hauteur: p.hauteur, jeton: null, source: x.source, exemple: false, envoi: 'ok', erreur: null };
      } catch (e) {
        toast('Une image de la création n\'a pas pu être relue : choisissez-la de nouveau.');
      }
    }));
    // Une image pour tout l'article ; celle du dos reste à part si la création en avait deux.
    etat.logo = images.devant || images.dos || null;
    etat.surcharges = { devant: null, dos: images.devant && images.dos ? images.dos : null };
    majLogo();
    majPositions();
    majTexte();
    toutSalir(true);
    demanderPrix();
  }

  majEtat3d();
  // Visière rouge d'office, dès la première image (comme quand on choisit la casquette).
  const casquette = D.fiches.find((f) => f.forme === 'casquette');
  if (casquette && casquette.couleurs.some((c) => c.nom === 'Rouge')) etat.visiere = 'Rouge';
  if (D.reprise) {
    reprendre(D.reprise);
  } else {
    etat.logo = logoExemple();
    choisirFiche(D.fiches[0], null);
  }

  // Polices, puis la 3D, après le premier affichage.
  const ensuite = window.requestIdleCallback || ((f) => window.setTimeout(f, 200));
  const demarrer = () => ensuite(() => {
    Promise.all(FACES.map((n) => police(etat.textes[n].police))).then(() => toutSalir(true));
    const choix = memoire('atelier3d-vue');
    if (!peut3d) return;
    if (choix === '3d' || !appareilModeste()) charger3d(choix !== '3d');
  }, { timeout: 1500 });
  if (document.readyState === 'complete') demarrer();
  else window.addEventListener('load', demarrer, { once: true });
}());
