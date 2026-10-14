/*
 * Atelier 3D (docs/atelier-3d.md) : la page du site public et de l'espace
 * client (gabarit templates/atelier/_outil.html.twig).
 *
 * - Vues à plat dessinées tout de suite : la page est utilisable sans 3D.
 * - La scène three.js (atelier-3d-scene.js) se charge après le premier
 *   affichage, si le navigateur a WebGL et que l'appareil n'est pas trop
 *   modeste ; trop lente (moins de 20 images par seconde), la page revient
 *   aux vues à plat. Le bouton « Voir en 3D » / « Vue à plat » laisse le
 *   choix, gardé dans ce navigateur.
 * - Le prix vient toujours du serveur (urls.prix) ; « Valider » n'envoie
 *   que les choix, les images déposées (jetons) et les deux vues.
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
  const tactile = window.matchMedia('(pointer: coarse)').matches;
  const fr = new Intl.NumberFormat('fr-FR');
  const $ = (s, dans) => (dans || racine).querySelector(s);
  const $$ = (s, dans) => Array.prototype.slice.call((dans || racine).querySelectorAll(s));
  const texte = (cle) => $('[data-texte="' + cle + '"]');
  const montant = (n) => fr.format(Math.round(n)) + ' F';
  const FACES = ['devant', 'dos'];
  const TAILLES_DEFAUT = { S: 5, M: 10, L: 10, XL: 5, XXL: 0 };
  /** Taille du texte (en part de la largeur de la zone), comme le prototype. */
  const CORPS = { 'Bebas Neue': 0.146, Pacifico: 0.094, 'Archivo Black': 0.102, Figtree: 0.102 };
  const FOND_VUE = '#eef2f8';

  function memoire(cle, valeur) {
    try {
      if (valeur === undefined) return window.localStorage.getItem(cle);
      window.localStorage.setItem(cle, valeur);
    } catch (e) { /* navigation privée */ }
    return null;
  }

  // ---------------------------------------------------------------- état
  const etat = {
    fiche: null,
    couleur: null,
    visiere: null,
    face: 'devant',
    faces: {
      devant: { image: null, taille: 60, hauteur: 0, texte: 'TEAM SUCCÈS', police: 'Archivo Black', couleurTexte: 'Blanc', placeTexte: 'dessous' },
      dos: { image: null, taille: 45, hauteur: -10, texte: 'DIALLO 10', police: 'Bebas Neue', couleurTexte: 'Jaune', placeTexte: 'dessous' },
    },
    tailles: {},
    quantite: 20,
    vue: 'devant',
    rendu: 'plat',
    prix: null,
  };

  const estCasquette = () => etat.fiche.forme === 'casquette';
  const libelleFace = (f) => (f === 'dos' ? (estCasquette() ? 'Arrière' : 'Dos') : 'Devant');
  const couleurDe = (nom) => etat.fiche.couleurs.find((c) => c.nom === nom) || etat.fiche.couleurs[0];
  const faceMarquee = (f) => !!(etat.faces[f].image || etat.faces[f].texte.trim());
  const facesMarquees = () => FACES.filter(faceMarquee);

  function quantite() {
    if (!etat.fiche.tailles.length) return Math.max(0, etat.quantite | 0);
    return etat.fiche.tailles.reduce((s, t) => s + Math.max(0, etat.tailles[t] | 0), 0);
  }

  /** Taille de l'image en % de la zone, place du texte retirée (même règle que le serveur). */
  function tailleImage(f) {
    const t = Math.max(15, Math.min(100, f.taille | 0));
    return f.texte.trim() ? Math.round(t * D.reduction) : t;
  }

  // ---------------------------------------------------------------- polices
  const polices = {};
  function police(nom) {
    if (!polices[nom]) {
      const url = D.polices[nom];
      polices[nom] = url && 'FontFace' in window
        ? new FontFace(nom, 'url(' + url + ') format("woff2")', nom === 'Figtree' ? { weight: '700' } : {}).load()
          .then((f) => { document.fonts.add(f); })
          .catch(() => {})
        : Promise.resolve();
    }
    return polices[nom];
  }
  const fonte = (nom, px) => (nom === 'Figtree' ? '700 ' : '') + Math.round(px) + 'px "' + nom + '", "Arial Black", sans-serif';

  // ---------------------------------------------------------------- décalques
  // Un canevas par face, aux proportions de la zone d'impression : la vue à
  // plat et la 3D affichent le même dessin.
  const decalques = { devant: document.createElement('canvas'), dos: document.createElement('canvas') };

  function dimensionnerDecalques() {
    const z = etat.fiche.zone;
    const h = Math.max(400, Math.min(1600, Math.round(1024 * z.hauteur / Math.max(1, z.largeur))));
    FACES.forEach((f) => { decalques[f].width = 1024; decalques[f].height = h; });
  }

  function dessinerDecalque(nom) {
    const f = etat.faces[nom];
    const c = decalques[nom];
    const g = c.getContext('2d');
    const W = c.width;
    const H = c.height;
    g.clearRect(0, 0, W, H);
    const mots = f.texte.trim();
    const img = f.image && f.image.dessin;
    let iw = 0;
    let ih = 0;
    if (img) {
      const t = tailleImage(f) / 100;
      const r = f.image.largeur / Math.max(1, f.image.hauteur);
      iw = W * t;
      ih = iw / r;
      if (ih > H * t) { ih = H * t; iw = ih * r; }
    }
    let px = W * (CORPS[f.police] || 0.102);
    g.font = fonte(f.police, px);
    while (mots && g.measureText(mots).width > W * 0.94 && px > W * 0.03) {
      px -= W * 0.006;
      g.font = fonte(f.police, px);
    }
    const th = mots ? px * 1.15 : 0;
    const ecart = img && mots ? W * 0.03 : 0;
    const total = ih + ecart + th;
    let y = H / 2 - total / 2 + (f.hauteur / 100) * H * 0.6;
    y = Math.max(0, Math.min(H - total, y));
    const morceaux = f.placeTexte === 'dessus' ? ['texte', 'image'] : ['image', 'texte'];
    morceaux.forEach((m) => {
      if (m === 'image' && img) {
        g.drawImage(img, (W - iw) / 2, y, iw, ih);
        y += ih + ecart;
      } else if (m === 'texte' && mots) {
        g.fillStyle = D.couleursTexte[f.couleurTexte] || '#ffffff';
        g.textAlign = 'center';
        g.textBaseline = 'middle';
        g.fillText(mots, W / 2, y + th / 2);
        y += th + ecart;
      }
    });
    if (scene3d) scene3d.decalque(nom);
    dessinerPlat();
  }

  // ---------------------------------------------------------------- vues à plat
  const toilePlat = $('.a3d-plat');
  const toile3d = $('.a3d-3d');

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

  /** Même placement que la 3D (atelier-3d-scene.js, placerZone). */
  function zone(haut, bas, largeurMax) {
    const CM = 0.0275;
    let l = etat.fiche.zone.largeur * CM;
    let h = etat.fiche.zone.hauteur * CM;
    const r = Math.min(1, largeurMax / l, (haut - bas) / h);
    l *= r;
    h *= r;
    return { l, h, y: haut - h / 2 };
  }

  function ombrer(g, x0, x1) {
    const lat = g.createLinearGradient(x0, 0, x1, 0);
    lat.addColorStop(0, 'rgba(10,20,40,.16)');
    lat.addColorStop(0.22, 'rgba(10,20,40,0)');
    lat.addColorStop(0.78, 'rgba(10,20,40,0)');
    lat.addColorStop(1, 'rgba(10,20,40,.16)');
    g.fillStyle = lat;
    g.fillRect(x0, -2, x1 - x0, 4);
  }

  /** Une face du modèle à plat, dans un repère où le modèle tient entre -1,25 et 1,25. */
  function modelePlat(g, face) {
    const tissu = couleurDe(etat.couleur).hex;
    const bord = nuance(tissu, -0.12);
    if (estCasquette()) {
      casquettePlat(g, face, tissu, bord);
      return;
    }
    const polo = etat.fiche.forme === 'polo';
    const encolure = face === 'dos' ? (polo ? 0.95 : 0.92) : (polo ? 0.86 : 0.74);
    // ombre au sol
    g.save();
    g.scale(1, 0.18);
    const sol2 = g.createRadialGradient(0, -1.18 / 0.18, 0.02, 0, -1.18 / 0.18, 1.1);
    sol2.addColorStop(0, 'rgba(10,20,40,.22)');
    sol2.addColorStop(1, 'rgba(10,20,40,0)');
    g.fillStyle = sol2;
    g.beginPath();
    g.arc(0, -1.18 / 0.18, 1.1, 0, Math.PI * 2);
    g.fill();
    g.restore();

    contourTshirt(g, polo, encolure);
    g.fillStyle = tissu;
    g.fill();
    g.save();
    contourTshirt(g, polo, encolure);
    g.clip();
    ombrer(g, -1.2, 1.2);
    // coutures des manches et ourlets
    g.strokeStyle = 'rgba(10,20,40,.18)';
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
    g.restore();

    // col
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
      g.fillRect(-0.065, 0.44, 0.13, 0.36);
      g.fillStyle = '#f2f2f2';
      [0.70, 0.54].forEach((y) => { g.beginPath(); g.arc(0, y, 0.022, 0, Math.PI * 2); g.fill(); });
    } else {
      g.lineWidth = 0.06;
      g.beginPath();
      g.moveTo(-0.33, 1.0);
      g.quadraticCurveTo(0, encolure + 0.03, 0.33, 1.0);
      g.stroke();
    }

    const z = face === 'dos' ? zone(polo ? 0.66 : 0.70, -0.92, 1.0) : zone(polo ? 0.40 : 0.64, -0.92, 1.0);
    g.save();
    g.scale(1, -1);
    g.drawImage(decalques[face], -z.l / 2, -(z.y + z.h / 2), z.l, z.h);
    g.restore();
  }

  function casquettePlat(g, face, tissu, bord) {
    const visiere = couleurDe(etat.visiere).hex;
    const base = -0.45;
    const rx = 1.12;
    const ry = 0.95;
    g.save();
    g.scale(1, 0.2);
    const sol = g.createRadialGradient(0, -1.0 / 0.2, 0.02, 0, -1.0 / 0.2, 1.2);
    sol.addColorStop(0, 'rgba(10,20,40,.2)');
    sol.addColorStop(1, 'rgba(10,20,40,0)');
    g.fillStyle = sol;
    g.beginPath();
    g.arc(0, -1.0 / 0.2, 1.2, 0, Math.PI * 2);
    g.fill();
    g.restore();
    const calotte = () => {
      g.beginPath();
      g.ellipse(0, base, rx, ry, 0, 0, Math.PI);
      g.closePath();
    };
    if (face === 'devant') {
      // visière, sous la calotte
      g.fillStyle = nuance(visiere, -0.05);
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
    if (face === 'dos') {
      g.fillStyle = nuance(tissu, -0.35);
      g.beginPath();
      g.ellipse(0, base, 0.34, 0.26, 0, 0, Math.PI);
      g.fill();
      g.fillStyle = bord;
      g.fillRect(-0.36, base, 0.72, 0.06);
    }
    g.restore();
    g.fillStyle = nuance(tissu, -0.2);
    g.fillRect(-rx, base - 0.03, rx * 2, 0.05);
    g.fillStyle = tissu;
    g.beginPath();
    g.ellipse(0, base + ry + 0.02, 0.07, 0.05, 0, 0, Math.PI * 2);
    g.fill();
    if (face === 'devant') {
      g.fillStyle = visiere;
      g.beginPath();
      g.ellipse(0, base - 0.03, rx * 1.1, 0.2, 0, 0, Math.PI);
      g.closePath();
      g.fill();
    }
    // zone marquée sur le panneau avant ou arrière
    const zl = etat.fiche.zone.largeur;
    const zh = etat.fiche.zone.hauteur;
    let l = 1.0;
    let h = l * zh / zl;
    if (h > 0.55) { h = 0.55; l = h * zl / zh; }
    const centre = face === 'dos' ? base + 0.62 : base + 0.48;
    g.save();
    g.scale(1, -1);
    g.drawImage(decalques[face], -l / 2, -(centre + h / 2), l, h);
    g.restore();
  }

  function peindrePlat(g, cote, face, fond) {
    g.save();
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.clearRect(0, 0, g.canvas.width, g.canvas.height);
    if (fond) {
      g.fillStyle = fond;
      g.fillRect(0, 0, g.canvas.width, g.canvas.height);
    }
    const s = cote.s;
    g.translate(cote.x, cote.y);
    g.scale(s, -s);
    modelePlat(g, face);
    g.restore();
  }

  let entreePlat = 0;
  function dessinerPlat() {
    if (etat.rendu !== 'plat' || !etat.fiche) return;
    const r = toilePlat.getBoundingClientRect();
    if (r.width < 2) return;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const w = Math.round(r.width * ratio);
    const h = Math.round(r.height * ratio);
    if (toilePlat.width !== w || toilePlat.height !== h) { toilePlat.width = w; toilePlat.height = h; }
    const g = toilePlat.getContext('2d');
    const s = Math.min(w / 2.9, h / 2.9);
    peindrePlat(g, { x: w / 2, y: h / 2 - 0.02 * s, s }, etat.vue === 'dos' ? 'dos' : 'devant', null);
  }

  function animerPlat() {
    if (reduit) return;
    toilePlat.classList.remove('a3d-entree');
    void toilePlat.offsetWidth;
    toilePlat.classList.add('a3d-entree');
    window.clearTimeout(entreePlat);
    entreePlat = window.setTimeout(() => toilePlat.classList.remove('a3d-entree'), 700);
  }

  // ---------------------------------------------------------------- 3D
  let scene3d = null;
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

  let aideLue = false;
  let minuterieAide = 0;
  function lireAide() {
    aideLue = true;
    texte('aide').hidden = true;
  }
  toile3d.addEventListener('pointerdown', lireAide, { once: true });

  function majBascule() {
    racine.dataset.rendu = etat.rendu;
    if (!peut3d) { bascule.hidden = true; return; }
    bascule.hidden = false;
    bascule.disabled = chargement3d;
    bascule.textContent = chargement3d ? 'Chargement de la 3D…' : (etat.rendu === '3d' ? 'Vue à plat' : (echec3d ? 'Réessayer la 3D' : 'Voir en 3D'));
    texte('mode').textContent = etat.rendu === '3d' ? 'Aperçu 3D en temps réel' : 'Vue à plat';
    const aide = texte('aide');
    aide.hidden = etat.rendu !== '3d' || aideLue;
    aide.textContent = tactile ? 'Glissez pour tourner' : 'Glissez pour tourner · Ctrl + molette : zoom';
    // Sur téléphone, l'aide couvrirait le col : elle part au premier geste ou après quelques secondes.
    if (!aide.hidden && !minuterieAide) minuterieAide = setTimeout(lireAide, 6000);
    $('[data-vue="cote"]').hidden = etat.rendu !== '3d';
    $('[data-action="rotation"]').hidden = etat.rendu !== '3d';
    $('[data-bloc="flotte"]').hidden = etat.rendu !== '3d' || reduit;
  }

  function afficherRendu(r) {
    etat.rendu = r;
    toile3d.hidden = r !== '3d';
    toilePlat.hidden = r === '3d';
    if (r === 'plat') {
      if (etat.vue === 'cote') choisirVue('devant');
      rotation(false);
      dessinerPlat();
    } else if (scene3d) {
      scene3d.vue(etat.vue);
    }
    majBascule();
  }

  async function charger3d(auto) {
    if (chargement3d) return;
    if (scene3d) { afficherRendu('3d'); return; }
    chargement3d = true;
    majBascule();
    try {
      const module = await import(D.urls.scene);
      scene3d = module.creerScene(toile3d, {
        reduit,
        decalques,
        urlGltf: D.urls.gltf,
        surVue: (v) => { if (!v) marquerVue(null); },
        surRotation: (oui) => { $('[data-action="rotation"]').setAttribute('aria-pressed', String(oui)); },
        surErreur: () => {
          scene3d = null;
          echec3d = true;
          afficherRendu('plat');
          toast('La 3D s\'est arrêtée : la vue à plat prend le relais.');
        },
      });
      toile3d.hidden = false;
      toilePlat.hidden = true;
      await scene3d.article(etat.fiche);
      scene3d.couleurs({ tissu: couleurDe(etat.couleur).hex, visiere: couleurDe(etat.visiere).hex });
      scene3d.flottement($('[data-champ="flotte"]').checked);
      chargement3d = false;
      afficherRendu('3d');
      const ips = await scene3d.mesurer();
      if (auto && ips > 0 && ips < 20 && etat.rendu === '3d') {
        afficherRendu('plat');
        toast('Vue à plat : elle est plus fluide sur cet appareil. « Voir en 3D » remet la 3D.');
      }
    } catch (e) {
      if (window.console) console.warn('Atelier 3D : vue à plat', e);
      scene3d = null;
      echec3d = true;
      chargement3d = false;
      afficherRendu('plat');
      if (!auto) toast('La 3D ne peut pas s\'afficher ici : la vue à plat reste disponible.');
    }
    chargement3d = false;
    majBascule();
  }

  bascule.addEventListener('click', () => {
    if (etat.rendu === '3d') {
      memoire('atelier3d-vue', 'plat');
      afficherRendu('plat');
    } else {
      memoire('atelier3d-vue', '3d');
      charger3d(false);
    }
  });

  // ---------------------------------------------------------------- vues
  function marquerVue(v) {
    $$('[data-vue]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.vue === v)));
  }

  function choisirVue(v) {
    etat.vue = v;
    marquerVue(v);
    if (etat.rendu === '3d' && scene3d) {
      rotation(false);
      scene3d.vue(v);
    } else {
      dessinerPlat();
      animerPlat();
    }
  }

  function rotation(oui) {
    $('[data-action="rotation"]').setAttribute('aria-pressed', String(oui));
    if (scene3d) scene3d.rotation(oui);
    if (oui) marquerVue(null);
  }

  $$('[data-vue]').forEach((b) => b.addEventListener('click', () => choisirVue(b.dataset.vue)));
  $('[data-action="rotation"]').addEventListener('click', (e) => rotation(e.currentTarget.getAttribute('aria-pressed') !== 'true'));
  $('[data-champ="flotte"]').addEventListener('change', (e) => { if (scene3d) scene3d.flottement(e.target.checked); });

  // ---------------------------------------------------------------- pastilles
  function pastilles(zone, liste, choisie, choisir) {
    const conteneur = $('[data-pastilles="' + zone + '"]');
    conteneur.textContent = '';
    liste.forEach((c) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.style.background = c.hex;
      b.title = c.nom;
      b.setAttribute('aria-label', c.nom);
      b.setAttribute('aria-pressed', String(c.nom === choisie));
      b.addEventListener('click', () => {
        $$('button', conteneur).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        choisir(c.nom);
      });
      conteneur.appendChild(b);
    });
  }

  function choisirCouleur(nom) {
    etat.couleur = nom;
    texte('couleur').textContent = nom;
    if (scene3d) scene3d.couleurs({ tissu: couleurDe(nom).hex });
    dessinerPlat();
  }

  function choisirVisiere(nom) {
    etat.visiere = nom;
    texte('visiere').textContent = nom;
    if (scene3d) scene3d.couleurs({ visiere: couleurDe(nom).hex });
    dessinerPlat();
  }

  // ---------------------------------------------------------------- article
  function choisirArticle(fiche, reprise) {
    etat.fiche = fiche;
    $$('[data-article]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.article === fiche.id)));
    const noms = fiche.couleurs.map((c) => c.nom);
    if (reprise && noms.includes(reprise.couleur)) etat.couleur = reprise.couleur;
    else if (!noms.includes(etat.couleur)) etat.couleur = noms.includes('Bleu Succès') ? 'Bleu Succès' : noms[0];
    if (reprise && reprise.visiere && noms.includes(reprise.visiere)) etat.visiere = reprise.visiere;
    else if (!noms.includes(etat.visiere)) etat.visiere = noms.includes('Rouge') ? 'Rouge' : etat.couleur;
    pastilles('tissu', fiche.couleurs, etat.couleur, choisirCouleur);
    pastilles('visiere', fiche.couleurs, etat.visiere, choisirVisiere);
    texte('couleur').textContent = etat.couleur;
    texte('visiere').textContent = etat.visiere;
    $('[data-bloc="visiere"]').hidden = !estCasquette();
    $('#a3d-l-tissu').textContent = estCasquette() ? 'Calotte' : 'Couleur du tissu';
    $$('[data-libelle-dos]').concat($$('[data-libelle-dos]', fenetre)).forEach((e) => { e.textContent = libelleFace('dos'); });

    // quantités
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
        etat.tailles[t] = Math.max(0, Math.min(D.quantiteMax, parseInt(i.value, 10) || 0));
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
    $('[data-champ="quantite"]').value = String(etat.quantite);
    $('[data-champ="quantite"]').min = String(fiche.min);
    $('[data-bloc="tailles"]').hidden = !fiche.tailles.length;
    $('[data-bloc="quantite"]').hidden = !!fiche.tailles.length;

    dimensionnerDecalques();
    FACES.forEach(dessinerDecalque);
    majFace();
    majQuantite();
    if (scene3d) {
      scene3d.article(fiche).then(() => scene3d && scene3d.couleurs({ tissu: couleurDe(etat.couleur).hex, visiere: couleurDe(etat.visiere).hex }));
      if (etat.vue !== 'devant') choisirVue('devant');
    } else {
      etat.vue = 'devant';
      marquerVue('devant');
      dessinerPlat();
      animerPlat();
    }
  }

  $$('[data-article]').forEach((b) => b.addEventListener('click', () => {
    const fiche = D.fiches.find((f) => f.id === b.dataset.article);
    if (fiche && fiche !== etat.fiche) choisirArticle(fiche, null);
  }));

  // ---------------------------------------------------------------- marquage
  const champ = (nom) => $('[data-champ="' + nom + '"]');

  function majFace() {
    const f = etat.faces[etat.face];
    $$('[data-face]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.face === etat.face)));
    FACES.forEach((n) => { $('[data-marque="' + n + '"]').hidden = !faceMarquee(n); });
    champ('taille').value = String(f.taille);
    texte('taille').textContent = f.taille + ' %';
    champ('hauteur').value = String(f.hauteur);
    texte('hauteur').textContent = f.hauteur > 0 ? '+' + f.hauteur : String(f.hauteur);
    champ('texte').value = f.texte;
    texte('reste').textContent = f.texte.length + ' / ' + champ('texte').maxLength;
    champ('police').value = f.police;
    champ('place').value = f.placeTexte;
    texte('couleur-texte').textContent = f.couleurTexte;
    pastilles('texte', Object.keys(D.couleursTexte).map((n) => ({ nom: n, hex: D.couleursTexte[n] })), f.couleurTexte, (n) => {
      etat.faces[etat.face].couleurTexte = n;
      texte('couleur-texte').textContent = n;
      dessinerDecalque(etat.face);
    });
    const vignette = $('[data-vignette]');
    const img = f.image;
    vignette.hidden = !img;
    if (img) vignette.src = img.vignette;
    texte('fichier').textContent = img ? img.nom : 'Aucune image';
    texte('fichier-aide').textContent = img && img.envoi === 'encours'
      ? 'Envoi en cours…'
      : 'Glissez ou choisissez votre logo ou une photo : PNG, JPG ou WebP, ' + Math.floor(D.envoiMax / 1048576) + ' Mo au plus';
    $('[data-zone="depot"]').classList.toggle('a3d-envoi', !!(img && img.envoi === 'encours'));
    $('[data-action="retirer"]').hidden = !img;
    majAlerte();
  }

  /** Résolution de l'image à sa taille d'impression : même calcul que le serveur (ImagesAtelier). */
  function controle(f) {
    const z = etat.fiche.zone;
    const t = Math.max(5, tailleImage(f)) / 100;
    const echelle = Math.min(z.largeur * t / Math.max(1, f.image.largeur), z.hauteur * t / Math.max(1, f.image.hauteur));
    const cm = f.image.largeur * echelle;
    const dpi = Math.floor(f.image.largeur / Math.max(0.1, cm / 2.54));
    const conseil = Math.ceil(cm / 2.54 * D.dpi.conseille);
    const cmTexte = (Math.round(cm * 10) / 10).toString().replace('.', ',');
    if (dpi >= D.dpi.conseille) return null;
    if (dpi >= D.dpi.minimum) return { etat: 'attention', message: 'Image un peu juste (' + f.image.largeur + ' px de large) pour ' + cmTexte + ' cm : préférez au moins ' + fr.format(conseil) + ' px, ou réduisez la taille.' };
    return { etat: 'erreur', message: 'Image en basse résolution (' + f.image.largeur + ' px de large) : risque de flou à l\'impression sur ' + cmTexte + ' cm. Préférez au moins ' + fr.format(conseil) + ' px.' };
  }

  function majAlerte() {
    const f = etat.faces[etat.face];
    const a = $('[data-alerte]');
    let m = null;
    if (f.image && f.image.exemple) m = { etat: 'info', message: 'Logo d\'exemple : remplacez-le par le vôtre, ou retirez-le, avant de valider.' };
    else if (f.image && f.image.envoi === 'erreur') m = { etat: 'erreur', message: f.image.erreur };
    else if (f.image) m = controle(f);
    a.hidden = !m;
    if (m) {
      a.textContent = m.message;
      a.dataset.etat = m.etat;
    }
  }

  function changerFace(nom) {
    etat.face = nom;
    majFace();
    choisirVue(nom === 'dos' ? 'dos' : 'devant');
  }

  $$('[data-face]').forEach((b) => b.addEventListener('click', () => changerFace(b.dataset.face)));

  function regler(cle, valeur, prix) {
    etat.faces[etat.face][cle] = valeur;
    dessinerDecalque(etat.face);
    FACES.forEach((n) => { $('[data-marque="' + n + '"]').hidden = !faceMarquee(n); });
    if (prix) demanderPrix();
  }

  champ('taille').addEventListener('input', (e) => {
    texte('taille').textContent = e.target.value + ' %';
    regler('taille', parseInt(e.target.value, 10), false);
    majAlerte();
  });
  champ('hauteur').addEventListener('input', (e) => {
    const v = parseInt(e.target.value, 10);
    texte('hauteur').textContent = v > 0 ? '+' + v : String(v);
    regler('hauteur', v, false);
  });
  champ('texte').addEventListener('input', (e) => {
    const avant = faceMarquee(etat.face);
    texte('reste').textContent = e.target.value.length + ' / ' + e.target.maxLength;
    regler('texte', e.target.value, avant !== !!(etat.faces[etat.face].image || e.target.value.trim()));
    majAlerte();
  });
  champ('police').addEventListener('change', (e) => {
    const nom = e.target.value;
    const face = etat.face;
    etat.faces[face].police = nom;
    dessinerDecalque(face);
    police(nom).then(() => dessinerDecalque(face));
  });
  champ('place').addEventListener('change', (e) => regler('placeTexte', e.target.value, false));
  $('[data-action="retirer"]').addEventListener('click', () => {
    etat.faces[etat.face].image = null;
    dessinerDecalque(etat.face);
    majFace();
    demanderPrix();
  });

  // ---------------------------------------------------------------- images
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

  async function deposer(fichier) {
    const nom = etat.face;
    if (!fichier) return;
    if (!/^image\/(png|jpeg|webp)$/.test(fichier.type)) { toast('Choisissez une image PNG, JPG ou WebP.'); return; }
    if (fichier.size > D.envoiMax) { toast('Image trop lourde : ' + Math.floor(D.envoiMax / 1048576) + ' Mo au plus.'); return; }
    const url = URL.createObjectURL(fichier);
    let img;
    try {
      img = await charger(url);
    } catch (e) {
      toast('Image illisible : essayez un PNG ou un JPG.');
      return;
    }
    const p = preparer(img);
    const image = { dessin: p.dessin, vignette: url, nom: fichier.name, largeur: p.largeur, hauteur: p.hauteur, jeton: null, source: null, exemple: false, envoi: 'encours', erreur: null };
    const avant = faceMarquee(nom);
    etat.faces[nom].image = image;
    dessinerDecalque(nom);
    if (etat.face === nom) majFace();
    if (!avant) demanderPrix();
    toast('Image placée ' + (nom === 'dos' ? (estCasquette() ? 'à l\'arrière' : 'au dos') : 'devant') + '.');

    const corps = new FormData();
    corps.append('image', fichier);
    try {
      const r = await fetch(D.urls.image, { method: 'POST', body: corps, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': D.csrf, Accept: 'application/json' } });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.jeton) throw new Error(j.erreur || (r.status === 413 ? 'Image trop lourde pour être envoyée.' : 'L\'image n\'est pas arrivée : réessayez.'));
      image.jeton = j.jeton;
      image.nom = j.nom;
      image.largeur = j.largeur;
      image.hauteur = j.hauteur;
      image.envoi = 'ok';
    } catch (e) {
      image.envoi = 'erreur';
      image.erreur = e && e.message && !/fetch|network/i.test(e.message) ? e.message : 'L\'image n\'est pas arrivée (connexion ?) : choisissez-la de nouveau.';
    }
    if (etat.face === nom) majFace();
  }

  const depot = $('[data-zone="depot"]');
  champ('fichier').addEventListener('change', (e) => { deposer(e.target.files[0]); e.target.value = ''; });
  ['dragenter', 'dragover'].forEach((ev) => depot.addEventListener(ev, (e) => { e.preventDefault(); depot.classList.add('a3d-survol'); }));
  ['dragleave', 'drop'].forEach((ev) => depot.addEventListener(ev, (e) => { e.preventDefault(); depot.classList.remove('a3d-survol'); }));
  depot.addEventListener('drop', (e) => deposer(e.dataTransfer.files[0]));

  /** Logo d'exemple, dessiné ici : montré, jamais envoyé. */
  function logoExemple() {
    const c = document.createElement('canvas');
    c.width = c.height = 600;
    const g = c.getContext('2d');
    g.fillStyle = '#FDC82A';
    g.beginPath(); g.arc(300, 300, 270, 0, Math.PI * 2); g.fill();
    g.fillStyle = '#E8398A';
    g.beginPath(); g.arc(300, 300, 200, 0, Math.PI * 2); g.fill();
    g.fillStyle = '#fff';
    g.beginPath(); g.moveTo(300, 150); g.lineTo(430, 380); g.lineTo(170, 380); g.closePath(); g.fill();
    g.fillStyle = '#0F4590';
    g.font = '900 64px "Arial Black", sans-serif';
    g.textAlign = 'center';
    g.fillText('AWA', 300, 470);
    return { dessin: c, vignette: c.toDataURL('image/png'), nom: 'Logo d\'exemple', largeur: 600, hauteur: 600, jeton: null, source: null, exemple: true, envoi: 'ok', erreur: null };
  }

  // ---------------------------------------------------------------- quantités et prix
  function majQuantite() {
    const n = quantite();
    texte('pieces').textContent = n + ' pièce' + (n > 1 ? 's' : '');
    demanderPrix();
  }

  champ('quantite').addEventListener('input', (e) => {
    etat.quantite = Math.max(0, Math.min(D.quantiteMax, parseInt(e.target.value, 10) || 0));
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
    const q = quantite();
    const parametres = new URLSearchParams({ modele: etat.fiche.id, quantite: String(q), faces: String(Math.max(1, facesMarquees().length)) });
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

  // ---------------------------------------------------------------- valider
  function probleme() {
    const q = quantite();
    if (q < 1) return 'Indiquez au moins une pièce.';
    if (q < etat.fiche.min) return 'Minimum ' + etat.fiche.min + ' pièces pour cet article.';
    if (etat.fiche.max && q > etat.fiche.max) return 'Au plus ' + etat.fiche.max + ' pièces en ligne : demandez un devis pour davantage.';
    if (!facesMarquees().length) return 'Ajoutez une image ou un texte sur au moins une face.';
    for (const n of FACES) {
      const img = etat.faces[n].image;
      if (!img) continue;
      if (img.exemple) return 'Remplacez le logo d\'exemple (' + libelleFace(n).toLowerCase() + ') par le vôtre, ou retirez-le.';
      if (img.envoi === 'encours') return 'Un instant : votre image finit d\'arriver.';
      if (img.envoi === 'erreur') return 'L\'image (' + libelleFace(n).toLowerCase() + ') n\'est pas arrivée : choisissez-la de nouveau ou retirez-la.';
    }
    if (etat.prix && etat.prix.erreur) return etat.prix.erreur;
    return null;
  }

  function enBlob(dataUrl) {
    const [entete, b64] = dataUrl.split(',');
    const binaire = atob(b64);
    const octets = new Uint8Array(binaire.length);
    for (let i = 0; i < binaire.length; i++) octets[i] = binaire.charCodeAt(i);
    return new Blob([octets], { type: entete.split(':')[1].split(';')[0] });
  }

  function vuePlate(face) {
    const c = document.createElement('canvas');
    c.width = c.height = 1024;
    peindrePlat(c.getContext('2d'), { x: 512, y: 500, s: 1024 / 2.9 }, face, FOND_VUE);
    return new Promise((ok) => c.toBlob(ok, 'image/png'));
  }

  async function vuesRendues() {
    if (etat.rendu === '3d' && scene3d) {
      return { devant: enBlob(scene3d.capturer('devant')), dos: enBlob(scene3d.capturer('dos')) };
    }
    return { devant: await vuePlate('devant'), dos: await vuePlate('dos') };
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
    const f = etat.faces[n];
    const m = [];
    if (f.texte.trim()) m.push('« ' + f.texte.trim() + ' » (' + f.police + ', ' + f.couleurTexte.toLowerCase() + ')');
    if (f.image) m.push('image ' + f.image.nom);
    return m.length ? m.join(' + ') : '—';
  }

  async function ouvrirRecap() {
    const p = probleme();
    if (p) { toast(p); return; }
    const bouton = $('[data-action="valider"]');
    bouton.disabled = true;
    try {
      await Promise.all(FACES.map((n) => police(etat.faces[n].police)));
      FACES.forEach((n) => { if (scene3d) scene3d.decalque(n); });
      rendus = await vuesRendues();
    } catch (e) {
      bouton.disabled = false;
      toast('Les vues de votre modèle n\'ont pas pu être préparées : réessayez.');
      return;
    }
    bouton.disabled = false;
    urlsRendus.splice(0).forEach((u) => URL.revokeObjectURL(u));
    FACES.forEach((n) => {
      const u = URL.createObjectURL(rendus[n]);
      urlsRendus.push(u);
      fenetre.querySelector('[data-rendu="' + n + '"]').src = u;
    });
    const table = fenetre.querySelector('[data-recap]');
    table.textContent = '';
    const f = etat.fiche;
    const q = quantite();
    ligne(table, 'Article', f.nom);
    ligne(table, 'Couleur', etat.couleur + (estCasquette() ? ' · visière ' + etat.visiere : ''));
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
    const corps = new FormData(formulaire);
    corps.append('modele', etat.fiche.id);
    corps.append('couleur', etat.couleur);
    if (estCasquette()) corps.append('visiere', etat.visiere);
    if (etat.fiche.tailles.length) etat.fiche.tailles.forEach((t) => corps.append('tailles[' + t + ']', String(etat.tailles[t] || 0)));
    else corps.append('quantite', String(etat.quantite));
    const faces = {};
    FACES.forEach((n) => {
      const f = etat.faces[n];
      faces[n] = { image: f.image ? (f.image.jeton || f.image.source) : null, taille: f.taille, hauteur: f.hauteur, texte: f.texte.trim(), police: f.police, couleurTexte: f.couleurTexte, placeTexte: f.placeTexte };
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
    FACES.forEach((n) => {
      const x = (r.faces || {})[n];
      etat.faces[n] = x
        ? { image: null, taille: x.taille, hauteur: x.hauteur, texte: x.texte || '', police: x.police, couleurTexte: x.couleurTexte, placeTexte: x.placeTexte }
        : { image: null, taille: 60, hauteur: 0, texte: '', police: 'Archivo Black', couleurTexte: 'Blanc', placeTexte: 'dessous' };
    });
    const fiche = D.fiches.find((f) => f.id === r.modele);
    if (!fiche) toast('L\'article de la création ' + r.numero + ' n\'est plus proposé : choisissez-en un autre.');
    choisirArticle(fiche || D.fiches[0], fiche ? r : null);
    await Promise.all(FACES.map(async (n) => {
      const x = (r.faces || {})[n];
      if (!x || !x.url || !x.source) return;
      try {
        const img = await charger(x.url);
        const p = preparer(img);
        etat.faces[n].image = { dessin: p.dessin, vignette: x.url, nom: x.nomImage || 'Image', largeur: p.largeur, hauteur: p.hauteur, jeton: null, source: x.source, exemple: false, envoi: 'ok', erreur: null };
        dessinerDecalque(n);
      } catch (e) {
        toast('Une image de la création n\'a pas pu être relue : choisissez-la de nouveau.');
      }
    }));
    majFace();
    demanderPrix();
  }

  if (D.reprise) {
    reprendre(D.reprise);
  } else {
    etat.faces.devant.image = logoExemple();
    choisirArticle(D.fiches[0], null);
  }
  majBascule();
  new ResizeObserver(() => dessinerPlat()).observe(toilePlat);

  // Polices, puis la 3D, après le premier affichage.
  const ensuite = window.requestIdleCallback || ((f) => window.setTimeout(f, 200));
  const demarrer = () => ensuite(() => {
    Promise.all(FACES.map((n) => police(etat.faces[n].police))).then(() => FACES.forEach(dessinerDecalque));
    const choix = memoire('atelier3d-vue');
    if (!peut3d || choix === 'plat') return;
    if (choix === '3d' || !appareilModeste()) charger3d(choix !== '3d');
  }, { timeout: 1500 });
  if (document.readyState === 'complete') demarrer();
  else window.addEventListener('load', demarrer, { once: true });
}());
