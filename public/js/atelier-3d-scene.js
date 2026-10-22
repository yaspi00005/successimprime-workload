/*
 * Studio textile : la scène three.js (docs/atelier-3d.md).
 *
 * Chargée par public/js/atelier-3d.js après le premier affichage, et
 * seulement si le navigateur a WebGL : sinon la page garde ses vues à
 * plat. three.js est copié dans public/vendor/three (aucun CDN).
 *
 * Un seul moteur de rendu, hors de la page : chaque vue fixe (face, dos,
 * côté…) est rendue à la demande puis recopiée dans son canevas. Rien ne
 * tourne en boucle, sauf la vue 360° qu'on fait tourner.
 *
 * Modèles : t-shirt, polo et casquette dessinés ici (silhouettes, manches,
 * col, patte et boutons, bords de couleur, panneaux, visière, coutures,
 * tricot en relief). Un vrai modèle GLB déposé dans
 * public/modeles-3d/<forme>.glb les remplace : pièces « tissu »
 * (couleur choisie), « visiere », « bords » (bords d'un polo) ; le
 * marquage y est projeté (DecalGeometry) aux repères de
 * public/modeles-3d/modeles.json, ou, si le modèle a des pièces
 * « zone_devant » / « zone_dos », posé sur leurs coordonnées de texture.
 *
 * Couleur : three.js gère les couleurs en sRGB (la pastille est lue en
 * sRGB, l'image sort en sRGB), sans compensation de tons, et l'éclairage
 * de face totalise environ π : un tissu vu de face garde la couleur de sa
 * pastille.
 */
import * as THREE from 'three';
import { DecalGeometry } from 'three/addons/geometries/DecalGeometry.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

/** Unités de la scène par centimètre : t-shirt et polo dessinés (73 cm de haut), casquette dessinée (20 cm de large). */
const CM_VETEMENT = 0.0275;
const CM_CASQUETTE = 0.1;

/** Lumières du studio (intensités de three.js, unités physiques). */
const L = { environnement: 0.18, ciel: 0.8, principale: 2.3, appoint: 0.55, contre: 1.3 };

/** Caméras fixes : [rotation du modèle, élévation] en radians. */
const VUES = {
  vetement: { face: [0, 0.04], dos: [Math.PI, 0.04], 'cote-gauche': [-1.2, 0.05], 'cote-droit': [1.2, 0.05] },
  casquette: { face: [0, 0.1], angle: [-0.7, 0.2], cote: [-1.5, 0.06], arriere: [Math.PI, 0.22] },
};

const lisse = (a, b, x) => {
  const t = Math.min(1, Math.max(0, (x - a) / (b - a)));
  return t * t * (3 - 2 * t);
};
const spow = (v, p) => Math.sign(v) * Math.pow(Math.abs(v), p);

/** Interpolation cubique d'une colonne d'un tableau trié sur sa première colonne. */
function interp(table, col, y) {
  const n = table.length;
  if (y <= table[0][0]) return table[0][col];
  if (y >= table[n - 1][0]) return table[n - 1][col];
  let k = 0;
  while (y > table[k + 1][0]) k++;
  const pente = (i) => {
    const a = table[Math.max(0, i - 1)];
    const b = table[Math.min(n - 1, i + 1)];
    return (b[col] - a[col]) / (b[0] - a[0]);
  };
  const h = table[k + 1][0] - table[k][0];
  const t = (y - table[k][0]) / h;
  const t2 = t * t;
  const t3 = t2 * t;
  return (2 * t3 - 3 * t2 + 1) * table[k][col] + (t3 - 2 * t2 + t) * pente(k) * h + (-2 * t3 + 3 * t2) * table[k + 1][col] + (t3 - t2) * pente(k + 1) * h;
}

/** Générateur pseudo-aléatoire reproductible (mêmes textures à chaque chargement). */
function hasard(graine) {
  let s = graine >>> 0;
  return () => {
    s = (s + 0x6D2B79F5) >>> 0;
    let t = s;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Surface en grille (u en colonnes, v en lignes, de 0 à 1) : positions,
 * coordonnées de texture, normales ; « fermeU » recoud la colonne 0 et la
 * dernière (pas de trait à la jonction), « teinte » donne une ombre
 * portée douce par sommet (aisselles, intérieur du col).
 */
function surface(nu, nv, fn, opt = {}) {
  const cols = nu + 1;
  const rows = nv + 1;
  const pos = new Float32Array(cols * rows * 3);
  const uv = new Float32Array(cols * rows * 2);
  const couleur = opt.teinte ? new Float32Array(cols * rows * 3) : null;
  const p = new THREE.Vector3();
  for (let j = 0; j < rows; j++) {
    for (let i = 0; i < cols; i++) {
      const u = i / nu;
      const v = j / nv;
      fn(u, v, p);
      const k = j * cols + i;
      pos[k * 3] = p.x;
      pos[k * 3 + 1] = p.y;
      pos[k * 3 + 2] = p.z;
      uv[k * 2] = u;
      uv[k * 2 + 1] = v;
      if (couleur) {
        const c = opt.teinte(p, u, v);
        couleur[k * 3] = couleur[k * 3 + 1] = couleur[k * 3 + 2] = c;
      }
    }
  }
  const idx = [];
  for (let j = 0; j < nv; j++) {
    for (let i = 0; i < nu; i++) {
      const a = j * cols + i;
      const b = a + 1;
      const c = a + cols;
      const d = c + 1;
      if (opt.inverser) idx.push(a, d, b, a, c, d);
      else idx.push(a, b, d, a, d, c);
    }
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
  if (couleur) g.setAttribute('color', new THREE.BufferAttribute(couleur, 3));
  g.setIndex(idx);
  g.computeVertexNormals();
  if (opt.fermeU) {
    const n = g.attributes.normal;
    const m = new THREE.Vector3();
    for (let j = 0; j < rows; j++) {
      const a = j * cols;
      const b = a + nu;
      m.set(n.getX(a) + n.getX(b), n.getY(a) + n.getY(b), n.getZ(a) + n.getZ(b)).normalize();
      n.setXYZ(a, m.x, m.y, m.z);
      n.setXYZ(b, m.x, m.y, m.z);
    }
    n.needsUpdate = true;
  }
  return g;
}

// ------------------------------------------------------------------ textures procédurales

/** Carte de normales répétable à partir d'un relief h(x, y) entre 0 et 1. */
function carteNormales(taille, relief, force, repetition) {
  const h = new Float32Array(taille * taille);
  for (let y = 0; y < taille; y++) for (let x = 0; x < taille; x++) h[y * taille + x] = relief(x, y);
  const c = document.createElement('canvas');
  c.width = c.height = taille;
  const g = c.getContext('2d');
  const img = g.createImageData(taille, taille);
  const at = (x, y) => h[((y + taille) % taille) * taille + ((x + taille) % taille)];
  for (let y = 0; y < taille; y++) {
    for (let x = 0; x < taille; x++) {
      let nx = (at(x - 1, y) - at(x + 1, y)) * force;
      let ny = (at(x, y + 1) - at(x, y - 1)) * force;
      let nz = 1;
      const l = Math.hypot(nx, ny, nz);
      nx /= l; ny /= l; nz /= l;
      const i = (y * taille + x) * 4;
      img.data[i] = Math.round((nx * 0.5 + 0.5) * 255);
      img.data[i + 1] = Math.round((ny * 0.5 + 0.5) * 255);
      img.data[i + 2] = Math.round((nz * 0.5 + 0.5) * 255);
      img.data[i + 3] = 255;
    }
  }
  g.putImageData(img, 0, 0);
  const t = new THREE.CanvasTexture(c);
  t.wrapS = t.wrapT = THREE.RepeatWrapping;
  t.colorSpace = THREE.NoColorSpace;
  t.repeat.set(repetition[0], repetition[1]);
  return t;
}

/** Jersey (t-shirt) : mailles en V, en colonnes, avec un léger grain. */
function tricotJersey(repetition) {
  const r = hasard(7);
  const grain = Float32Array.from({ length: 256 * 256 }, () => r());
  return carteNormales(256, (x, y) => {
    const cx = x / 16;
    const cy = y / (256 / 24);
    const u = cx - Math.floor(cx);
    const v = cy - Math.floor(cy);
    const d = Math.min(Math.abs(u - (0.12 + 0.36 * v)), Math.abs(u - (0.88 - 0.36 * v)));
    let h = Math.max(0, 1 - d / 0.26);
    h = h * h * (3 - 2 * h);
    return h * (0.8 + 0.2 * Math.sin(Math.PI * v)) + grain[y * 256 + x] * 0.12;
  }, 2.2, repetition);
}

/** Piqué (polo) : petites alvéoles en quinconce. */
function tricotPique(repetition) {
  const r = hasard(11);
  const grain = Float32Array.from({ length: 256 * 256 }, () => r());
  return carteNormales(256, (x, y) => {
    const decale = Math.floor(y / 8) % 2 ? 4 : 0;
    const a = Math.cos(2 * Math.PI * (x + decale) / 8);
    const b = Math.cos(2 * Math.PI * y / 8);
    return 0.5 + 0.5 * a * b * 0.9 + grain[y * 256 + x] * 0.1;
  }, 2.6, repetition);
}

/** Côtes (col, bord-côtes) : sillons verticaux. */
function cotes(repetition) {
  return carteNormales(64, (x) => 0.5 + 0.5 * Math.cos(2 * Math.PI * x / 8), 3.2, repetition);
}

/** Toile de casquette (sergé) : fines diagonales. */
function serge(repetition) {
  const r = hasard(5);
  const grain = Float32Array.from({ length: 128 * 128 }, () => r());
  return carteNormales(128, (x, y) => 0.5 + 0.5 * Math.sin(2 * Math.PI * (x + y) / 8) + grain[y * 128 + x] * 0.15, 2.0, repetition);
}

/** Détails dessinés (coutures surpiquées, œillets) sur fond blanc : multipliés par la couleur du tissu. */
function carteDetails(largeur, hauteur, dessin) {
  const c = document.createElement('canvas');
  c.width = largeur;
  c.height = hauteur;
  const g = c.getContext('2d');
  g.fillStyle = '#fff';
  g.fillRect(0, 0, largeur, hauteur);
  dessin(g, largeur, hauteur);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = 4;
  return t;
}

/** Surpiqûre : ligne en pointillés, et le léger creux de la couture. */
function piqure(g, x0, y0, x1, y1, epaisseur = 2, creux = true) {
  if (creux) {
    g.setLineDash([]);
    g.strokeStyle = 'rgba(0,0,0,.10)';
    g.lineWidth = epaisseur * 2.2;
    g.beginPath(); g.moveTo(x0, y0); g.lineTo(x1, y1); g.stroke();
  }
  g.setLineDash([epaisseur * 3, epaisseur * 2]);
  g.strokeStyle = 'rgba(0,0,0,.26)';
  g.lineWidth = epaisseur;
  g.beginPath(); g.moveTo(x0, y0); g.lineTo(x1, y1); g.stroke();
  g.setLineDash([]);
}

function textureOmbre() {
  const c = document.createElement('canvas');
  c.width = c.height = 256;
  const g = c.getContext('2d');
  const gr = g.createRadialGradient(128, 128, 6, 128, 128, 128);
  gr.addColorStop(0, 'rgba(0,0,0,.55)');
  gr.addColorStop(0.45, 'rgba(0,0,0,.22)');
  gr.addColorStop(1, 'rgba(0,0,0,0)');
  g.fillStyle = gr;
  g.fillRect(0, 0, 256, 256);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

/**
 * Broderie simulée (casquette) : relief en points lancés diagonaux sous le
 * dessin, plus marqué sur ses bords.
 */
function normalesBroderie(source, cible) {
  const l = Math.min(512, source.width);
  const h = Math.max(8, Math.round(source.height * l / source.width));
  const c = cible || document.createElement('canvas');
  c.width = l;
  c.height = h;
  const g = c.getContext('2d', { willReadFrequently: true });
  g.clearRect(0, 0, l, h);
  g.drawImage(source, 0, 0, l, h);
  const a = g.getImageData(0, 0, l, h);
  const alpha = new Float32Array(l * h);
  for (let i = 0; i < l * h; i++) alpha[i] = a.data[i * 4 + 3] / 255;
  const haut = new Float32Array(l * h);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < l; x++) {
      const al = alpha[y * l + x];
      haut[y * l + x] = al * (0.62 + 0.38 * Math.abs(Math.sin((x + y * 0.6) * 0.9)));
    }
  }
  const at = (x, y) => haut[Math.min(h - 1, Math.max(0, y)) * l + Math.min(l - 1, Math.max(0, x))];
  const out = g.createImageData(l, h);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < l; x++) {
      let nx = (at(x - 1, y) - at(x + 1, y)) * 2.4;
      let ny = (at(x, y + 1) - at(x, y - 1)) * 2.4;
      const n = Math.hypot(nx, ny, 1);
      nx /= n; ny /= n;
      const i = (y * l + x) * 4;
      out.data[i] = Math.round((nx * 0.5 + 0.5) * 255);
      out.data[i + 1] = Math.round((ny * 0.5 + 0.5) * 255);
      out.data[i + 2] = Math.round((1 / n * 0.5 + 0.5) * 255);
      out.data[i + 3] = 255;
    }
  }
  g.putImageData(out, 0, 0);
  return c;
}

// ------------------------------------------------------------------ modèles dessinés

/**
 * Buste d'un t-shirt ou d'un polo, porté par un mannequin invisible :
 * sections arrondies (super-ellipses) de l'ourlet aux épaules, épaules en
 * pente vers l'encolure, encolure plus basse devant, et quelques plis
 * doux (ourlet, bas des aisselles).
 */
function buste(polo) {
  const anneaux = [
    [-1.00, 0.615, 0.25], [-0.94, 0.616, 0.252], [-0.60, 0.610, 0.252], [-0.20, 0.603, 0.256],
    [0.20, 0.616, 0.272], [0.45, 0.628, 0.285], [0.62, 0.628, 0.283], [0.78, 0.618, 0.27],
    [0.86, 0.598, 0.255], [0.90, 0.555, 0.242], [0.93, 0.478, 0.228], [0.96, 0.398, 0.214],
    [0.985, 0.322, 0.198], [1.00, polo ? 0.27 : 0.285, polo ? 0.17 : 0.18],
  ];
  const Y0 = 0.62;
  const creuxAvant = polo ? 0.13 : 0.21;
  const creuxArriere = polo ? 0.02 : 0.035;
  const yHaut = (th) => {
    const s = Math.sin(th);
    return 1.0 - creuxAvant * Math.pow(Math.max(0, s), 2.2) - creuxArriere * Math.pow(Math.max(0, -s), 2);
  };
  const yBase = (v) => (v < 0.72 ? -1 + (v / 0.72) * 1.8 : 0.8 + ((v - 0.72) / 0.28) * 0.2);
  const plis = (th, y, x) => {
    const s = Math.sin(th);
    let f = 0.006 * Math.sin(5 * th + 1.3) * lisse(-0.7, -1.0, y);
    // Tombé : plis verticaux doux, plus marqués vers le bas et sur les côtés.
    f += 0.009 * Math.sin(7 * th + 0.6 + 0.8 * Math.sin(y * 2.1)) * lisse(0.35, -0.9, y) * (0.35 + 0.65 * Math.abs(Math.cos(th)));
    // Plis en biais sous les aisselles, devant et dos.
    for (const cote of [1, -1]) {
      const d = Math.abs((x * cote - 0.58) * 0.82 + (y - 0.32) * 0.57);
      const long = lisse(-0.35, 0.05, y) * (1 - lisse(0.30, 0.42, y));
      f -= 0.011 * Math.exp(-(d * d) / 0.0012) * long * Math.min(1, Math.abs(s) * 2.5);
    }
    return f;
  };
  function point(th, yb, cible) {
    const yt = yHaut(th);
    const y = yb <= Y0 ? yb : Y0 + (yb - Y0) * (yt - Y0) / (1.0 - Y0);
    const s = Math.sin(th);
    const w = interp(anneaux, 1, yb);
    const d = interp(anneaux, 2, yb) * (s > 0 ? 1.035 : 0.97);
    const n = 2.25 - 0.3 * lisse(0.86, 1.0, yb);
    let x = w * spow(Math.cos(th), 2 / n);
    let z = d * spow(s, 2 / n);
    const f = 1 + plis(th, y, x);
    x *= f;
    z *= f;
    return cible.set(x, y, z);
  }
  // u : tour du buste depuis le milieu du dos (côté gauche du porteur à u = 0,25, devant à 0,5).
  const angle = (u) => -Math.PI / 2 + u * Math.PI * 2;
  const geo = surface(128, 96, (u, v, p) => point(angle(u), yBase(v), p), {
    fermeU: true,
    inverser: true,
    teinte: (p) => {
      let c = 1;
      for (const cote of [1, -1]) {
        const dx = p.x - cote * 0.62;
        const dy = p.y - 0.42;
        c -= 0.22 * Math.exp(-(dx * dx + dy * dy * 1.6) / 0.012);
      }
      return Math.max(0.6, c);
    },
  });
  return { geo, point, angle, yHaut, yBase };
}

/** Manche : tube elliptique de l'emmanchure au poignet, un peu évasé, plis doux sous le bras. */
function manche(cote, polo) {
  const A = new THREE.Vector3(cote * 0.505, 0.69, 0.012);
  const a = new THREE.Vector3(cote * 0.83, -0.56, 0.07).normalize();
  const w = new THREE.Vector3(0, 0, 1);
  const u = new THREE.Vector3().crossVectors(w, a).normalize();
  if (u.y < 0) u.negate();
  const L = polo ? 0.46 : 0.52;
  const s0 = -0.05;
  const rayons = (s) => {
    const t = (s - s0) / (L - s0);
    return [0.19 - 0.035 * t + 0.006 * lisse(0.9, 1, t), 0.165 - 0.03 * t];
  };
  const p = new THREE.Vector3();
  const fn = (uu, vv, cible) => {
    const s = s0 + vv * (L - s0);
    const phi = uu * Math.PI * 2;
    const [ru, rw] = rayons(s);
    const bas = Math.max(0, -Math.cos(phi));
    const pli = 1 - 0.05 * bas * Math.max(0, Math.sin(phi * 3 + s * 9)) * (1 - lisse(0.1, 0.35, s));
    const c = spow(Math.cos(phi), 2 / 2.2) * ru * pli;
    const d = spow(Math.sin(phi), 2 / 2.2) * rw * pli;
    return cible.copy(A).addScaledVector(a, s).addScaledVector(u, c).addScaledVector(w, d);
  };
  const geo = surface(48, 24, fn, {
    fermeU: true,
    inverser: cote < 0,
    teinte: (q, uu, vv) => 1 - 0.18 * Math.max(0, -Math.cos(uu * Math.PI * 2)) * (1 - lisse(0, 0.35, vv)),
  });
  return { geo, A, a, u, w, L, s0, rayons, fn, p };
}

/** Matière du tissu : coton mat, léger velours sur les bords, tricot en relief. */
function matiereTissu(couleur, normales, details, force = 0.35) {
  return new THREE.MeshPhysicalMaterial({
    color: couleur, roughness: 0.9, metalness: 0, specularIntensity: 0.22,
    sheen: 0.3, sheenRoughness: 0.75, sheenColor: new THREE.Color(couleur),
    normalMap: normales, normalScale: new THREE.Vector2(force, force),
    map: details || null, vertexColors: false,
  });
}

function matiereInterieur(couleur) {
  return new THREE.MeshStandardMaterial({ color: couleur, roughness: 1, metalness: 0, side: THREE.BackSide });
}

/** T-shirt ou polo dessiné. */
function vetementDessine(polo) {
  const groupe = new THREE.Group();
  const tricot = polo ? tricotPique([30, 22]) : tricotJersey([26, 20]);
  const b = buste(polo);
  const yv = (y) => (y < 0.8 ? ((y + 1) / 1.8) * 0.72 : 0.72 + ((y - 0.8) / 0.2) * 0.28);
  const detailsBuste = carteDetails(1024, 1024, (g, L, H) => {
    const ligne = (v) => (1 - v) * H;
    // Ourlet piqué à 2 cm, coutures de côté et d'épaule.
    piqure(g, 0, ligne(yv(-1 + 2 * CM_VETEMENT)), L, ligne(yv(-1 + 2 * CM_VETEMENT)), 2.2, false);
    for (const u of [0.25, 0.75]) {
      piqure(g, u * L, ligne(0), u * L, ligne(yv(0.36)), 1.6);
      piqure(g, u * L, ligne(yv(0.87)), u * L, ligne(1), 1.6);
    }
  });
  const m = {
    tissu: [], seconde: [], interieur: [], boutons: [],
  };
  const tissu = matiereTissu('#ffffff', tricot, detailsBuste);
  tissu.vertexColors = true;
  m.tissu.push(tissu);
  const corps = new THREE.Mesh(b.geo, tissu);
  corps.name = 'tissu';
  groupe.add(corps);
  const dedans = matiereInterieur('#cccccc');
  m.interieur.push(dedans);
  groupe.add(new THREE.Mesh(b.geo, dedans));

  // Manches, avec ourlet ou bord-côte, et leur couture d'emmanchure.
  const tissuManche = matiereTissu('#ffffff', tricot, carteDetails(256, 256, (g, L, H) => {
    piqure(g, 0, H * (polo ? 0.13 : 0.11), L, H * (polo ? 0.13 : 0.11), 2, false);
  }));
  tissuManche.vertexColors = true;
  m.tissu.push(tissuManche);
  const cotesTex = cotes([60, 1]);
  const tissuCotes = matiereTissu('#ffffff', cotesTex, null, 0.28);
  m.tissu.push(tissuCotes);
  const couture = new THREE.MeshStandardMaterial({ color: '#888', roughness: 1 });
  m.coutures = [couture];
  const pTorse = new THREE.Vector3();
  for (const cote of [1, -1]) {
    const mh = manche(cote, polo);
    const mesh = new THREE.Mesh(mh.geo, tissuManche);
    mesh.name = 'tissu_manche';
    groupe.add(mesh, new THREE.Mesh(mh.geo, dedans));
    // Couture : là où la manche sort du buste (recherche par dichotomie, par angle).
    const points = [];
    const dans = (q) => {
      const yb = Math.min(0.995, Math.max(-1, q.y));
      const th = Math.atan2(q.z / 0.28, q.x / 0.628);
      b.point(th, yb, pTorse);
      const rq = Math.hypot(q.x / 0.628, q.z / 0.28);
      const rt = Math.hypot(pTorse.x / 0.628, pTorse.z / 0.28);
      return rq < rt;
    };
    for (let k = 0; k <= 64; k++) {
      const uu = k / 64;
      let lo = 0;
      let hi = 0.6;
      const q = new THREE.Vector3();
      for (let it = 0; it < 18; it++) {
        const mid = (lo + hi) / 2;
        mh.fn(uu, mid, q);
        if (dans(q)) lo = mid; else hi = mid;
      }
      mh.fn(uu, hi + 0.004, q);
      points.push(q.clone());
    }
    const courbe = new THREE.CatmullRomCurve3(points, true);
    groupe.add(new THREE.Mesh(new THREE.TubeGeometry(courbe, 96, 0.0055, 6, true), couture));
    // Bout de manche : ourlet (t-shirt) ou bord-côte rayé (polo).
    const bout = (s0, s1, r0, mat) => {
      const g = surface(48, 3, (uu, vv, cible) => {
        const s = s0 + vv * (s1 - s0);
        const phi = uu * Math.PI * 2;
        const [ru, rw] = mh.rayons(s);
        return cible.copy(mh.A).addScaledVector(mh.a, s).addScaledVector(mh.u, spow(Math.cos(phi), 2 / 2.2) * (ru + r0)).addScaledVector(mh.w, spow(Math.sin(phi), 2 / 2.2) * (rw + r0));
      }, { fermeU: true, inverser: cote < 0 });
      const me = new THREE.Mesh(g, mat);
      groupe.add(me);
      return me;
    };
    if (polo) {
      bout(mh.L - 0.085, mh.L, 0.004, tissuCotes).name = 'tissu_poignet';
      const bord = new THREE.MeshStandardMaterial({ color: '#E95B35', roughness: 0.85 });
      m.seconde.push(bord);
      bout(mh.L - 0.052, mh.L - 0.040, 0.0062, bord);
      bout(mh.L - 0.030, mh.L - 0.018, 0.0062, bord);
    } else {
      bout(mh.L - 0.006, mh.L, 0.002, tissuCotes);
    }
  }

  // Encolure : bord-côte du t-shirt, ou col et patte boutonnée du polo.
  const pt = new THREE.Vector3();
  if (!polo) {
    const g = surface(128, 4, (uu, vv, cible) => {
      const th = b.angle(uu);
      const yb = 0.985 + vv * 0.015;
      b.point(th, yb, cible);
      const r = Math.hypot(cible.x, cible.z) || 1;
      const lev = 0.006 + 0.004 * Math.sin(vv * Math.PI);
      cible.x += (cible.x / r) * lev;
      cible.z += (cible.z / r) * lev;
      cible.y += vv * 0.012;
      return cible;
    }, { fermeU: true, inverser: true });
    const encolure = new THREE.Mesh(g, tissuCotes);
    encolure.name = 'tissu_encolure';
    groupe.add(encolure);
    const bordure = [];
    for (let k = 0; k <= 96; k++) {
      b.point(b.angle(k / 96), 1.0, pt);
      bordure.push(new THREE.Vector3(pt.x * 1.02, pt.y + 0.012, pt.z * 1.02));
    }
    groupe.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(bordure, true), 128, 0.011, 8, true), tissuCotes));
  } else {
    poloCol(groupe, b, m, tissuCotes, dedans, couture);
  }

  const reperes = {
    poitrine: new THREE.Vector3(0, 0.5, 0.25),
    dos: new THREE.Vector3(0, 0.5, -0.23),
    devant: new THREE.Vector3(0, 0, 1),
  };
  return {
    groupe, matieres: m, cibles: [corps], cm: CM_VETEMENT, reperes, famille: 'vetement',
    hautDevant: polo ? -4 : 5,
  };
}

/** Col du polo (pied et tombant, pointes devant, liserés de couleur) et patte à trois boutons. */
function poloCol(groupe, b, m, tissuCotes, dedans, couture) {
  // Le col fait le tour de l'encolure sauf devant (la patte) : pied droit, pli,
  // puis le tombant qui redescend sur le buste en suivant sa pente (épaules,
  // dos), plus long aux pointes, qui se rapprochent du milieu du devant.
  const ecart = 0.2;
  const t0 = Math.PI / 2 + ecart;
  const t1 = Math.PI / 2 + Math.PI * 2 - ecart;
  const NU = 120;
  const NV = 28;
  const cols = NU + 1;
  const grille = [];
  const tmp = new THREE.Vector3();
  const prec = new THREE.Vector3();
  const quad = (a, c, e, t) => a.clone().multiplyScalar((1 - t) * (1 - t)).addScaledVector(c, 2 * t * (1 - t)).addScaledVector(e, t * t);
  for (let i = 0; i < cols; i++) {
    const uu = i / NU;
    const th = t0 + uu * (t1 - t0);
    const bout = 1 - lisse(0, 0.2, Math.min(uu, 1 - uu));
    const n0 = b.point(th, 1.0, new THREE.Vector3());
    const rl = Math.hypot(n0.x, n0.z) || 1;
    const r = new THREE.Vector3(n0.x / rl, 0, n0.z / rl);
    const tang = new THREE.Vector3(-r.z, 0, r.x);
    // Bas du tombant : on descend le long du buste sur la longueur du col (7 cm, 9 aux pointes).
    const longueur = 0.19 + 0.08 * bout;
    let parcouru = 0;
    let yb = 1.0;
    prec.copy(n0);
    while (parcouru < longueur && yb > 0.4) {
      yb -= 0.0025;
      b.point(th, yb, tmp);
      parcouru += tmp.distanceTo(prec);
      prec.copy(tmp);
    }
    const e = prec.clone();
    const el = Math.hypot(e.x, e.z) || 1;
    e.x += (e.x / el) * 0.016;
    e.z += (e.z / el) * 0.016;
    e.y += 0.006;
    e.addScaledVector(tang, bout * 0.045 * (uu < 0.5 ? -1 : 1));
    const pied = n0.clone().addScaledVector(r, -0.006);
    pied.y += 0.04;
    const haut = n0.clone().addScaledVector(r, -0.003);
    haut.y += 0.074;
    const pli = n0.clone().addScaledVector(r, 0.02);
    pli.y += 0.088;
    const ctrl = pli.clone().lerp(e, 0.5).addScaledVector(r, 0.028);
    ctrl.y += 0.03;
    const ligne = [n0, pied, haut, pli];
    for (let k = 1; k <= 12; k++) ligne.push(quad(pli, ctrl, e, k / 12));
    // Rééchantillonnage régulier le long du profil.
    const cumul = [0];
    for (let k = 1; k < ligne.length; k++) cumul.push(cumul[k - 1] + ligne[k].distanceTo(ligne[k - 1]));
    const total = cumul[cumul.length - 1];
    const colonne = [];
    let k = 1;
    for (let j = 0; j <= NV; j++) {
      const cible = (total * j) / NV;
      while (k < ligne.length - 1 && cumul[k] < cible) k++;
      const f = (cible - cumul[k - 1]) / Math.max(1e-6, cumul[k] - cumul[k - 1]);
      colonne.push(ligne[k - 1].clone().lerp(ligne[k], Math.min(1, Math.max(0, f))));
    }
    grille.push(colonne);
  }
  const P = (i, j) => grille[Math.min(NU, Math.max(0, i))][Math.min(NV, Math.max(0, j))];
  // Normales du côté visible (extérieur du tombant), pour poser les liserés dessus.
  const normale = (i, j) => {
    const du = P(i + 1, j).clone().sub(P(i - 1, j));
    const dv = P(i, j + 1).clone().sub(P(i, j - 1));
    return du.cross(dv).normalize();
  };
  const point = (decalage) => (uu, vv, cible) => {
    const i = Math.round(uu * NU);
    const x = vv * NV;
    const j = Math.min(NV - 1, Math.floor(x));
    const f = x - j;
    cible.copy(P(i, j)).lerp(P(i, j + 1), f);
    if (decalage) cible.addScaledVector(normale(i, j).lerp(normale(i, j + 1), f).normalize(), decalage);
    return cible;
  };
  const geo = surface(NU, NV, point(0), { inverser: false });
  const tissuCol = m.tissu[2];
  const col = new THREE.Mesh(geo, tissuCol);
  col.name = 'tissu_col';
  groupe.add(col, new THREE.Mesh(geo, dedans));
  // Liserés : deux bandes près du bord du col.
  const bord = new THREE.MeshStandardMaterial({ color: '#E95B35', roughness: 0.85, side: THREE.DoubleSide });
  m.seconde.push(bord);
  for (const [a, z] of [[0.82, 0.86], [0.9, 0.94]]) {
    const g = surface(NU, 2, (uu, vv, cible) => point(0.0022)(uu, a + vv * (z - a), cible), {});
    groupe.add(new THREE.Mesh(g, bord));
  }
  // Patte boutonnée : posée sur le devant, du col vers la poitrine.
  const yHautDevant = b.yHaut(Math.PI / 2);
  const hauteur = 0.42;
  const largeur = 0.048;
  const p = new THREE.Vector3();
  const surDevant = (x, y, cible) => {
    // Le point du buste à cette largeur et cette hauteur (près du milieu du devant).
    let lo = Math.PI / 2 - 0.5;
    let hi = Math.PI / 2 + 0.5;
    const yb = y <= 0.62 ? y : 0.62 + (y - 0.62) * (1.0 - 0.62) / (yHautDevant - 0.62);
    for (let it = 0; it < 22; it++) {
      const mid = (lo + hi) / 2;
      b.point(mid, Math.min(1, yb), p);
      if (p.x > x) lo = mid; else hi = mid;
    }
    return cible.copy(b.point((lo + hi) / 2, Math.min(1, yb), p));
  };
  const detailsPatte = carteDetails(128, 512, (g, L, H) => {
    g.setLineDash([6, 4]);
    g.strokeStyle = 'rgba(0,0,0,.28)';
    g.lineWidth = 2;
    g.strokeRect(7, 7, L - 14, H - 14);
    g.setLineDash([]);
    g.strokeStyle = 'rgba(0,0,0,.18)';
    g.beginPath(); g.moveTo(L / 2, 0); g.lineTo(L / 2, H); g.stroke();
  });
  const tissuPatte = matiereTissu('#ffffff', m.tissu[0].normalMap, detailsPatte);
  m.tissu.push(tissuPatte);
  const patte = surface(6, 24, (uu, vv, cible) => {
    surDevant((uu - 0.5) * 2 * largeur, yHautDevant - 0.004 - (1 - vv) * hauteur, cible);
    cible.z += 0.007;
    return cible;
  }, { inverser: true });
  const mPatte = new THREE.Mesh(patte, tissuPatte);
  mPatte.name = 'tissu_patte';
  groupe.add(mPatte);
  const geoBouton = new THREE.CylinderGeometry(0.016, 0.017, 0.007, 24);
  geoBouton.rotateX(Math.PI / 2);
  const bouton = new THREE.MeshStandardMaterial({ color: '#f4f1ea', roughness: 0.35, metalness: 0 });
  m.boutons.push(bouton);
  const trou = new THREE.MeshBasicMaterial({ color: '#555' });
  m.trous = trou;
  for (const dy of [0.07, 0.19, 0.31]) {
    const q = surDevant(0, yHautDevant - dy, new THREE.Vector3());
    const bt = new THREE.Mesh(geoBouton, bouton);
    bt.position.set(q.x, q.y, q.z + 0.013);
    groupe.add(bt);
    for (const [ox, oy] of [[-0.004, -0.004], [0.004, -0.004], [-0.004, 0.004], [0.004, 0.004]]) {
      const t = new THREE.Mesh(new THREE.CircleGeometry(0.0022, 8), trou);
      t.position.set(q.x + ox, q.y + oy, q.z + 0.0168);
      groupe.add(t);
    }
  }
  void couture;
}

/** Casquette 6 panneaux : calotte structurée, coutures, œillets, bouton, visière piquée, ouverture et patte au dos. */
function casquetteDessinee() {
  const groupe = new THREE.Group();
  const toile = serge([14, 10]);
  const m = { tissu: [], seconde: [], interieur: [], boutons: [] };
  const A = 1.0;
  const B = 1.06;
  const ZC = -0.03;
  const arche = 0.42;
  const coutures = [0, 1, 2, 3, 4, 5].map((k) => Math.PI / 2 + k * Math.PI / 3);
  const thetaU = (u) => -Math.PI / 2 + u * Math.PI * 2;
  const hauteur = (th) => 0.86 + 0.12 * Math.max(0, Math.sin(th));
  const v0 = (th) => {
    let d = Math.abs(((th + Math.PI / 2) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2));
    d = Math.min(d, Math.PI * 2 - d);
    return d < arche ? 0.3 * Math.sqrt(Math.cos((Math.PI / 2) * d / arche)) : 0;
  };
  const calotte = (th, v, cible, gonfle = 0) => {
    const phi = v * Math.PI / 2;
    const plein = 0.72 + 0.28 * (1 - Math.max(0, Math.sin(th)));
    const r = Math.pow(Math.cos(phi), plein) + gonfle;
    const y = hauteur(th) * Math.pow(Math.sin(phi), 1.05) + gonfle * 0.6;
    return cible.set(A * r * Math.cos(th), y, ZC + B * r * Math.sin(th));
  };
  const detailsCalotte = carteDetails(2048, 512, (g, L, H) => {
    for (const c of coutures) {
      const u = (((c + Math.PI / 2) / (Math.PI * 2)) % 1 + 1) % 1;
      for (const e of [-0.006, 0.006]) piqure(g, (u + e) * L, H * 0.04, (u + e) * L, H * 0.95, 2.2, false);
    }
    piqure(g, 0, H * 0.94, L, H * 0.94, 2.2, false);
    // Œillets brodés sur quatre panneaux.
    for (const k of [1, 2, 4, 5]) {
      const c = coutures[k] + Math.PI / 6;
      const u = (((c + Math.PI / 2) / (Math.PI * 2)) % 1 + 1) % 1;
      g.fillStyle = 'rgba(0,0,0,.5)';
      g.beginPath(); g.ellipse(u * L, H * 0.24, 9, 8, 0, 0, Math.PI * 2); g.fill();
      g.strokeStyle = 'rgba(0,0,0,.3)';
      g.lineWidth = 4;
      g.beginPath(); g.ellipse(u * L, H * 0.24, 13, 12, 0, 0, Math.PI * 2); g.stroke();
    }
  });
  const tissu = matiereTissu('#ffffff', toile, detailsCalotte, 0.45);
  tissu.vertexColors = true;
  m.tissu.push(tissu);
  const geo = surface(144, 40, (u, v, p) => {
    const th = thetaU(u);
    const vb = v0(th);
    return calotte(th, vb + v * (1 - vb), p);
  }, {
    fermeU: true,
    inverser: true,
    teinte: (p, u, v) => 1 - 0.12 * (1 - lisse(0, 0.25, v)),
  });
  const corps = new THREE.Mesh(geo, tissu);
  corps.name = 'tissu';
  groupe.add(corps);
  const dedans = matiereInterieur('#bbbbbb');
  m.interieur.push(dedans);
  groupe.add(new THREE.Mesh(geo, dedans));
  // Coutures en relief entre les panneaux, et le bouton du sommet.
  const relief = matiereTissu('#ffffff', toile, null, 0.3);
  m.tissu.push(relief);
  const q = new THREE.Vector3();
  for (const c of coutures.slice(1)) {
    const pts = [];
    for (let k = 0; k <= 30; k++) {
      const vb = v0(c);
      pts.push(calotte(c, vb + (k / 30) * (0.985 - vb), q, 0.004).clone());
    }
    groupe.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts), 40, 0.012, 6), relief));
  }
  const sommet = new THREE.Mesh(new THREE.SphereGeometry(0.075, 20, 12), relief);
  sommet.scale.y = 0.55;
  sommet.position.set(0, hauteur(0) + 0.005, ZC);
  groupe.add(sommet);
  // Bord de l'ouverture et patte réglable au dos.
  const pts = [];
  for (let k = 0; k <= 40; k++) {
    const th = -Math.PI / 2 - arche + (k / 40) * arche * 2;
    pts.push(calotte(th, v0(th), q, 0.006).clone());
  }
  groupe.add(new THREE.Mesh(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts), 48, 0.016, 6), relief));
  const patte = surface(24, 2, (uu, vv, cible) => {
    const th = -Math.PI / 2 - arche * 1.05 + uu * arche * 2.1;
    calotte(th, 0, cible, 0.012);
    cible.y += 0.02 + vv * 0.07;
    return cible;
  }, { inverser: true });
  groupe.add(new THREE.Mesh(patte, relief));
  const boucle = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.08, 0.02), new THREE.MeshStandardMaterial({ color: '#9aa0aa', metalness: 0.8, roughness: 0.35 }));
  boucle.position.copy(calotte(-Math.PI / 2 + 0.12, 0, q, 0.02));
  boucle.position.y += 0.055;
  groupe.add(boucle);

  // Visière en croissant : elle part de la base de la calotte, s'allonge au milieu
  // (7,5 cm), s'amincit jusqu'aux côtés, s'incline un peu et se courbe en arche.
  const phi0 = 1.2;
  const LONG = 0.75;
  const visiere = (dessous) => (uu, vv, cible) => {
    const phi = (uu - 0.5) * 2 * phi0;
    const th = Math.PI / 2 + phi;
    const xi = A * 0.985 * Math.cos(th);
    const zi = ZC + B * 0.985 * Math.sin(th);
    const nx = Math.cos(th) / A;
    const nz = Math.sin(th) / B;
    const nl = Math.hypot(nx, nz);
    const w = Math.pow(Math.max(0, Math.cos((Math.PI / 2) * phi / phi0)), 0.75) * LONG;
    const d = vv * w;
    const s = phi / phi0;
    const x = xi + (nx / nl) * d;
    const z = zi + (nz / nl) * d;
    const y = 0.03 - d * 0.16 - 0.34 * s * s * Math.pow(vv, 1.2) * (0.35 + 0.65 * w / LONG) - (dessous ? 0.026 : 0);
    return cible.set(x, y, z);
  };
  const detailsVisiere = carteDetails(1024, 256, (g, L, H) => {
    for (const t of [0.6, 0.7, 0.8, 0.9]) piqure(g, 0, (1 - t) * H, L, (1 - t) * H, 2.2, false);
  });
  const matVisiere = matiereTissu('#ffffff', toile, detailsVisiere, 0.4);
  const matDessous = matiereTissu('#ffffff', toile, null, 0.3);
  m.seconde.push(matVisiere, matDessous);
  const dessus = new THREE.Mesh(surface(64, 14, visiere(false), {}), matVisiere);
  dessus.name = 'visiere';
  groupe.add(dessus);
  groupe.add(new THREE.Mesh(surface(64, 14, visiere(true), { inverser: true }), matDessous));
  // Tranche de la visière, et le bord de la calotte au-dessus.
  const tranche = surface(64, 1, (uu, vv, cible) => visiere(vv > 0.5)(uu, 1, cible), { inverser: true });
  groupe.add(new THREE.Mesh(tranche, matDessous));

  groupe.position.y = -0.45;
  groupe.updateMatrixWorld(true);
  const reperes = {
    devant: new THREE.Vector3(0, 0, 1),
    face: calotte(Math.PI / 2, 0.42, new THREE.Vector3()).add(groupe.position),
    cote: calotte(-0.08, 0.36, new THREE.Vector3()).add(groupe.position),
    arriere: calotte(-Math.PI / 2, 0.56, new THREE.Vector3()).add(groupe.position),
  };
  return { groupe, matieres: m, cibles: [corps], cm: CM_CASQUETTE, reperes, famille: 'casquette', broderie: true };
}

// ------------------------------------------------------------------ studio

export function creerStudio(options) {
  const reduit = !!options.reduit;
  const toile = document.createElement('canvas');
  const renderer = new THREE.WebGLRenderer({ canvas: toile, antialias: true, alpha: true, powerPreference: 'low-power' });
  renderer.setPixelRatio(1);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.NoToneMapping;
  renderer.setClearColor(0x000000, 0);
  let larg = 0;
  let haut = 0;
  function assurer(w, h) {
    if (w <= larg && h <= haut) return;
    larg = Math.max(larg, w);
    haut = Math.max(haut, h);
    renderer.setSize(larg, haut, false);
  }

  const scene = new THREE.Scene();
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  pmrem.dispose();
  scene.environmentIntensity = L.environnement;
  // Studio : lumière principale en haut à gauche, appoint à droite, contre-jour pour détacher le vêtement du fond sombre.
  // Un tissu vu de face reçoit en tout à peu près 1 (ciel et sol, principale, appoint, environnement) : il garde la couleur de sa pastille,
  // et ses côtés, moins éclairés, donnent le modelé.
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8a93a6, L.ciel));
  const principale = new THREE.DirectionalLight(0xffffff, L.principale);
  principale.position.set(-3.4, 3.0, 3.6);
  scene.add(principale);
  const appoint = new THREE.DirectionalLight(0xffffff, L.appoint);
  appoint.position.set(3.2, 0.8, 3.4);
  scene.add(appoint);
  const contre = new THREE.DirectionalLight(0xdfe8ff, L.contre);
  contre.position.set(0.5, 2.5, -4.5);
  scene.add(contre);
  const camera = new THREE.PerspectiveCamera(24, 1, 0.1, 100);
  const ombreTex = textureOmbre();

  const articles = new Map();

  function preparerMatieres(a) {
    for (const mat of a.matieres.tissu) {
      mat.color.set(a.couleurs.tissu);
      mat.sheenColor?.set(a.couleurs.tissu);
    }
    const seconde = a.couleurs.seconde || a.couleurs.tissu;
    for (const mat of a.matieres.seconde) {
      mat.color.set(seconde);
      mat.sheenColor?.set(seconde);
    }
    const sombre = new THREE.Color(a.couleurs.tissu).multiplyScalar(0.45);
    for (const mat of a.matieres.interieur) mat.color.copy(sombre);
    for (const mat of a.matieres.coutures || []) mat.color.copy(new THREE.Color(a.couleurs.tissu).multiplyScalar(0.55));
    for (const mat of a.matieres.boutons) mat.color.copy(new THREE.Color(a.couleurs.tissu).lerp(new THREE.Color('#ffffff'), 0.35));
    if (a.matieres.trous) a.matieres.trous.color.copy(new THREE.Color(a.couleurs.tissu).multiplyScalar(0.4));
  }

  /** Quelques centaines de points du modèle, pour cadrer chaque vue au plus juste. */
  function echantillon(groupe) {
    const pts = [];
    const v = new THREE.Vector3();
    groupe.updateMatrixWorld(true);
    groupe.traverse((o) => {
      if (!o.isMesh || !o.geometry?.attributes.position || o.userData.decal) return;
      const pos = o.geometry.attributes.position;
      const pas = Math.max(1, Math.floor(pos.count / 180));
      for (let i = 0; i < pos.count; i += pas) pts.push(v.fromBufferAttribute(pos, i).applyMatrix4(o.matrixWorld).clone());
    });
    return pts;
  }

  /** Modèle GLB : à l'échelle de la page, centré, l'avant vers la caméra. */
  async function depuisGlb(fiche) {
    const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');
    const gltf = await new GLTFLoader().loadAsync(fiche.glb);
    const objet = gltf.scene;
    const infos = fiche.modele3d || {};
    const rep = infos.reperes || {};
    const avant = rep.devant ? new THREE.Vector3().fromArray(rep.devant) : new THREE.Vector3(0, 0, 1);
    const tour = -Math.atan2(avant.x, avant.z);
    const enveloppe = new THREE.Group();
    enveloppe.add(objet);
    objet.rotation.y += tour;
    objet.updateMatrixWorld(true);
    const boite = new THREE.Box3().setFromObject(objet);
    const taille = boite.getSize(new THREE.Vector3());
    const centre = boite.getCenter(new THREE.Vector3());
    const e = 2.2 / Math.max(taille.x, taille.y, 0.001);
    objet.position.sub(centre);
    enveloppe.scale.setScalar(e);
    enveloppe.updateMatrixWorld(true);
    const versPage = (tableau) => {
      if (!tableau) return null;
      const p = new THREE.Vector3().fromArray(tableau).applyAxisAngle(new THREE.Vector3(0, 1, 0), tour).sub(centre).multiplyScalar(e);
      return p;
    };
    const casquette = fiche.forme === 'casquette';
    const reel = infos.tailleCm || null;
    // GLB en mètres : 1 unité = 100 cm, sauf taille réelle donnée par la fiche.
    const cm = reel ? (casquette ? taille.x : taille.y) * e / reel : e / 100;
    const m = { tissu: [], seconde: [], interieur: [], boutons: [] };
    const cibles = [];
    const zones = {};
    objet.traverse((o) => {
      if (!o.isMesh) return;
      const nom = (o.name + ' ' + (o.material?.name || '')).toLowerCase();
      if (nom.includes('zone_devant')) zones.devant = o;
      else if (nom.includes('zone_dos')) zones.dos = o;
      else if (nom.includes('visiere') || nom.includes('bords')) {
        o.material = o.material.clone();
        m.seconde.push(o.material);
      } else if (nom.includes('tissu')) {
        o.material = o.material.clone();
        o.material.side = THREE.DoubleSide;
        m.tissu.push(o.material);
        cibles.push(o);
      }
    });
    const reperes = {
      devant: new THREE.Vector3(0, 0, 1),
      poitrine: versPage(rep.poitrine),
      dos: versPage(rep.dos),
      face: versPage(rep.poitrine),
      cote: versPage(rep.cote),
      arriere: versPage(rep.arriere || (casquette ? rep.dos : null)),
    };
    return { groupe: enveloppe, matieres: m, cibles, cm, reperes, zones, famille: casquette ? 'casquette' : 'vetement', broderie: casquette, hautDevant: fiche.forme === 'polo' ? -4 : 5, glb: true };
  }

  const rayon = new THREE.Raycaster();
  /** Point du tissu touché par un rayon venant de l'extérieur (repère approché ou absent). */
  function toucher(a, depart, direction) {
    rayon.set(depart, direction);
    rayon.far = 20;
    const t = rayon.intersectObjects(a.cibles, false)[0];
    return t ? { point: t.point.clone(), normale: t.face ? t.face.normal.clone().transformDirection(t.object.matrixWorld) : direction.clone().negate() } : null;
  }

  /**
   * Projecteur du marquage : centre sur le tissu, direction (vers
   * l'extérieur) et taille (unités de la scène) pour cet emplacement.
   */
  function projecteur(a, face, position, boite) {
    const cm = a.cm;
    const l = boite.l * cm;
    const h = boite.h * cm;
    const b = a.boite;
    const H = b.max.y - b.min.y;
    let viser;
    let dir;
    let melange = 0;
    if (a.famille === 'casquette') {
      if (face === 'devant') {
        viser = a.reperes.face || new THREE.Vector3(0, b.min.y + H * 0.6, 0);
        dir = new THREE.Vector3(0, 0, 1);
        melange = 0.6;
      } else if (position === 'cote') {
        viser = a.reperes.cote || new THREE.Vector3(0, b.min.y + H * 0.52, -0.05);
        dir = new THREE.Vector3(1, 0, 0);
        melange = 0.5;
      } else {
        viser = a.reperes.arriere || new THREE.Vector3(0, b.min.y + H * 0.62, 0);
        dir = new THREE.Vector3(0, 0, -1);
        melange = 0.5;
      }
    } else {
      const poitrine = a.reperes.poitrine || new THREE.Vector3(0, b.max.y - H * 0.3, 0);
      const dos = a.reperes.dos || new THREE.Vector3(0, b.max.y - H * 0.3, 0);
      if (face === 'devant') {
        dir = new THREE.Vector3(0, 0, 1);
        if (position === 'devant-centre') {
          const hautZone = poitrine.y + a.hautDevant * cm;
          const bas = b.min.y + 3 * cm;
          const r = Math.min(1, (hautZone - bas) / h);
          viser = new THREE.Vector3(poitrine.x, hautZone - (h * r) / 2, poitrine.z);
        } else {
          viser = new THREE.Vector3(poitrine.x + (position === 'poitrine-droite' ? -9 : 9) * cm, poitrine.y + 2 * cm, poitrine.z);
        }
      } else {
        dir = new THREE.Vector3(0, 0, -1);
        const hautZone = dos.y + (position === 'dos-haut' ? 9.5 : 7.3) * cm;
        viser = new THREE.Vector3(dos.x, hautZone - h / 2, dos.z);
      }
    }
    // Le centre se pose sur le tissu, en venant de l'extérieur.
    const depart = viser.clone().addScaledVector(dir, 3);
    const t = toucher(a, depart, dir.clone().negate());
    const centre = t ? t.point : viser;
    let z = dir.clone();
    if (t && melange) z.lerp(t.normale, melange).normalize();
    const y = new THREE.Vector3(0, 1, 0).addScaledVector(z, -z.y).normalize();
    const x = new THREE.Vector3().crossVectors(y, z);
    const orientation = new THREE.Euler().setFromRotationMatrix(new THREE.Matrix4().makeBasis(x, y, z));
    const profondeur = (a.famille === 'casquette' ? 7 : 10) * cm;
    return { centre, orientation, taille: new THREE.Vector3(l, h, profondeur), z };
  }

  /** Projection du marquage sur le tissu (faces tournées vers l'extérieur seulement). */
  function projeter(a, face, position, boite) {
    // Le modèle au repos (la dernière vue rendue l'a peut-être tourné) : repères, rayon et projection en dépendent.
    const tourne = a.pivot.rotation.y;
    a.pivot.rotation.set(0, 0, 0);
    a.racine.updateMatrixWorld(true);
    const p = projecteur(a, face, position, boite);
    const pos = [];
    const nor = [];
    const uvs = [];
    const n = new THREE.Vector3();
    const e1 = new THREE.Vector3();
    const e2 = new THREE.Vector3();
    for (const cible of a.cibles) {
      const g = new DecalGeometry(cible, p.centre, p.orientation, p.taille);
      const P = g.attributes.position;
      const N = g.attributes.normal;
      const U = g.attributes.uv;
      if (!P) continue;
      for (let i = 0; i + 2 < P.count; i += 3) {
        e1.set(P.getX(i + 1) - P.getX(i), P.getY(i + 1) - P.getY(i), P.getZ(i + 1) - P.getZ(i));
        e2.set(P.getX(i + 2) - P.getX(i), P.getY(i + 2) - P.getY(i), P.getZ(i + 2) - P.getZ(i));
        n.crossVectors(e1, e2).normalize();
        let sens = n.dot(p.z);
        if (cible.material.side === THREE.DoubleSide && N) {
          // Tissu à double face : la normale du sommet dit quel côté est l'extérieur.
          sens = Math.sign(N.getX(i) * p.z.x + N.getY(i) * p.z.y + N.getZ(i) * p.z.z) * Math.abs(sens);
        }
        if (sens < 0.15) continue;
        for (let k = 0; k < 3; k++) {
          const j = i + k;
          const nx = N ? N.getX(j) : p.z.x;
          const ny = N ? N.getY(j) : p.z.y;
          const nz = N ? N.getZ(j) : p.z.z;
          pos.push(P.getX(j) + nx * 0.0015, P.getY(j) + ny * 0.0015, P.getZ(j) + nz * 0.0015);
          nor.push(nx, ny, nz);
          uvs.push(U.getX(j), U.getY(j));
        }
      }
      g.dispose();
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    geo.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
    geo.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    // Le marquage suit le modèle quand il tourne : ramené dans le repère du pivot.
    geo.applyMatrix4(new THREE.Matrix4().copy(a.pivot.matrixWorld).invert());
    a.pivot.rotation.set(0, tourne, 0);
    a.racine.updateMatrixWorld(true);
    return geo;
  }

  function matiereMarquage(a) {
    if (a.broderie) {
      return new THREE.MeshPhysicalMaterial({
        transparent: true, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -4,
        roughness: 0.42, metalness: 0, sheen: 0.8, sheenRoughness: 0.35, sheenColor: new THREE.Color('#ffffff'),
        normalScale: new THREE.Vector2(1.2, 1.2),
      });
    }
    return new THREE.MeshStandardMaterial({
      transparent: true, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -4,
      roughness: 0.62, metalness: 0, normalMap: a.tricot || null, normalScale: new THREE.Vector2(0.18, 0.18),
    });
  }

  function poser(id, objet, fiche) {
    const ancien = articles.get(id);
    if (ancien) {
      scene.remove(ancien.racine);
      ancien.racine.traverse((o) => { if (o.geometry) o.geometry.dispose(); });
    }
    const racine = new THREE.Group();
    const pivot = new THREE.Group();
    racine.add(pivot);
    pivot.add(objet.groupe);
    racine.visible = false;
    scene.add(racine);
    racine.updateMatrixWorld(true);
    const boite = new THREE.Box3().setFromObject(objet.groupe);
    const ombre = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: ombreTex, transparent: true, depthWrite: false }));
    ombre.rotation.x = -Math.PI / 2;
    const largeur = Math.max(boite.max.x - boite.min.x, boite.max.z - boite.min.z);
    ombre.scale.set(largeur * 1.05, largeur * 0.55, 1);
    ombre.position.y = boite.min.y - 0.05;
    racine.add(ombre);
    const a = {
      id, fiche, racine, pivot, ombre, boite, ...objet,
      tricot: objet.matieres.tissu[0]?.normalMap || null,
      couleurs: (ancien && ancien.couleurs) || { tissu: '#ffffff', seconde: null },
      points: echantillon(objet.groupe),
      marquages: {},
    };
    articles.set(id, a);
    preparerMatieres(a);
    // Les marquages déjà posés sur l'ancien modèle repartent sur le nouveau.
    if (ancien) for (const [face, mq] of Object.entries(ancien.marquages)) marquer(a, face, { ...mq.reglage, force: true });
    return a;
  }

  /** Pose ou met à jour le marquage d'une face : { canevas, position, boite (cm), visible, mode }. */
  function marquer(a, face, r) {
    let mq = a.marquages[face];
    const zone = a.zones && a.zones[face] && r.mode === 'uv' ? a.zones[face] : null;
    if (!mq) {
      const texture = new THREE.CanvasTexture(r.canevas);
      texture.colorSpace = THREE.SRGBColorSpace;
      texture.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
      mq = { texture, matiere: matiereMarquage(a), mesh: null, cle: null, reglage: r, relief: null, dims: r.canevas.width + 'x' + r.canevas.height, dimsRelief: null };
      mq.matiere.map = texture;
      a.marquages[face] = mq;
    }
    // Une toile qui change de taille demande une nouvelle texture (la mémoire du GPU est réservée à la première taille).
    const dims = r.canevas.width + 'x' + r.canevas.height;
    if (mq.texture.image !== r.canevas || mq.dims !== dims) {
      mq.texture.dispose();
      mq.texture = new THREE.CanvasTexture(r.canevas);
      mq.texture.colorSpace = THREE.SRGBColorSpace;
      mq.texture.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
      mq.matiere.map = mq.texture;
      mq.matiere.needsUpdate = true;
      mq.dims = dims;
    } else {
      mq.texture.needsUpdate = true;
    }
    mq.reglage = r;
    if (a.broderie && r.visible) {
      mq.relief = normalesBroderie(r.canevas, mq.relief);
      const dimsRelief = mq.relief.width + 'x' + mq.relief.height;
      if (!mq.matiere.normalMap || mq.matiere.normalMap.image !== mq.relief || mq.dimsRelief !== dimsRelief) {
        mq.matiere.normalMap?.dispose();
        mq.matiere.normalMap = new THREE.CanvasTexture(mq.relief);
        mq.matiere.normalMap.colorSpace = THREE.NoColorSpace;
        mq.matiere.needsUpdate = true;
        mq.dimsRelief = dimsRelief;
      } else {
        mq.matiere.normalMap.needsUpdate = true;
      }
    }
    if (zone) {
      // Ancien chemin : la pièce « zone_… » du modèle reçoit le dessin sur ses coordonnées de texture.
      zone.material = mq.matiere;
      zone.visible = !!r.visible;
      if (mq.mesh) mq.mesh.visible = false;
      return;
    }
    const cle = [r.position, r.boite.l, r.boite.h].join('|');
    if (r.visible && (cle !== mq.cle || r.force || !mq.mesh)) {
      const geo = projeter(a, face, r.position, r.boite);
      if (mq.mesh) {
        mq.mesh.geometry.dispose();
        mq.mesh.geometry = geo;
      } else {
        mq.mesh = new THREE.Mesh(geo, mq.matiere);
        mq.mesh.userData.decal = true;
        mq.mesh.renderOrder = 2;
        a.pivot.add(mq.mesh);
      }
      mq.cle = cle;
    }
    if (mq.mesh) mq.mesh.visible = !!r.visible;
  }

  /** Cadre la caméra sur le modèle tourné : ses points tiennent dans l'image, avec une marge. */
  function cadrer(a, az, el, aspect, zoom) {
    a.pivot.rotation.set(0, az, 0);
    const dir = new THREE.Vector3(0, Math.sin(el), Math.cos(el));
    const haut = new THREE.Vector3(0, Math.cos(el), -Math.sin(el));
    const droite = new THREE.Vector3(1, 0, 0);
    const q = new THREE.Vector3();
    let u0 = Infinity; let u1 = -Infinity; let v0 = Infinity; let v1 = -Infinity; let w1 = -Infinity;
    const c = Math.cos(az);
    const s = Math.sin(az);
    for (const p of a.points) {
      q.set(p.x * c + p.z * s, p.y, -p.x * s + p.z * c);
      const u = q.dot(droite);
      const v = q.dot(haut);
      const w = q.dot(dir);
      if (u < u0) u0 = u; if (u > u1) u1 = u;
      if (v < v0) v0 = v; if (v > v1) v1 = v;
      if (w > w1) w1 = w;
    }
    v0 -= 0.06;
    const tanV = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2));
    const demiH = (v1 - v0) / 2;
    const demiL = (u1 - u0) / 2;
    const d = (Math.max(demiH / tanV, demiL / (tanV * aspect)) * 1.12 + w1 * 0.6) / zoom;
    const centre = droite.clone().multiplyScalar((u0 + u1) / 2).addScaledVector(haut, (v0 + v1) / 2);
    camera.aspect = aspect;
    camera.position.copy(centre).addScaledVector(dir, d);
    camera.up.copy(haut);
    camera.lookAt(centre);
    camera.near = Math.max(0.05, d - 6);
    camera.far = d + 6;
    camera.updateProjectionMatrix();
  }

  function seul(a) {
    for (const b of articles.values()) b.racine.visible = b === a;
  }

  /** Rend une vue dans un canevas 2D (taille physique du canevas). */
  function rendreDans(a, az, el, zoom, canevas, fond) {
    const w = canevas.width;
    const h = canevas.height;
    if (w < 2 || h < 2) return;
    assurer(w, h);
    seul(a);
    cadrer(a, az, el, w / h, zoom);
    renderer.setViewport(0, 0, w, h);
    renderer.setScissor(0, 0, w, h);
    renderer.setScissorTest(true);
    renderer.clear();
    renderer.render(scene, camera);
    const g = canevas.getContext('2d');
    g.save();
    g.setTransform(1, 0, 0, 1, 0, 0);
    if (fond) {
      g.fillStyle = fond;
      g.fillRect(0, 0, w, h);
    } else {
      g.clearRect(0, 0, w, h);
    }
    g.drawImage(toile, 0, toile.height - h, w, h, 0, 0, w, h);
    g.restore();
  }

  function angles(a, vue) {
    const v = VUES[a.famille][vue] || VUES[a.famille].face;
    return v;
  }

  toile.addEventListener('webglcontextlost', (e) => {
    e.preventDefault();
    options.surErreur?.();
  });

  // ---- 360° : la seule vue qui s'anime ----
  let tourActif = null;
  function tour(id, canevas, depart) {
    if (tourActif) tourActif.arreter();
    const a = articles.get(id);
    if (!a) return null;
    let az = depart?.az ?? 0;
    let el = depart?.el ?? (a.famille === 'casquette' ? 0.3 : 0.08);
    let zoom = depart?.zoom ?? 1;
    let auto = !reduit && depart?.auto !== false;
    let glisse = null;
    const doigts = new Map();
    let pincement = null;
    let visible = true;
    let prevu = false;
    let t0 = 0;
    let fini = false;
    const taille = () => {
      const r = canevas.getBoundingClientRect();
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      const w = Math.max(2, Math.round(r.width * ratio));
      const h = Math.max(2, Math.round(r.height * ratio));
      if (canevas.width !== w || canevas.height !== h) { canevas.width = w; canevas.height = h; }
    };
    const image = (t) => {
      prevu = false;
      if (fini) return;
      const dt = t0 ? Math.min(0.05, (t - t0) / 1000) : 0;
      t0 = t;
      if (auto && !glisse) az -= dt * 0.55;
      const aa = articles.get(id);
      if (aa) rendreDans(aa, az, el, zoom, canevas, null);
      if (auto && visible) demander();
    };
    const demander = () => {
      if (prevu || fini || !visible) return;
      prevu = true;
      requestAnimationFrame(image);
    };
    const bas = (e) => {
      doigts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      auto = false;
      if (doigts.size === 2) {
        const [p, q] = [...doigts.values()];
        pincement = { d: Math.hypot(p.x - q.x, p.y - q.y), zoom };
        glisse = null;
      } else {
        glisse = { x: e.clientX, y: e.clientY, az, el, id: e.pointerId };
      }
    };
    const bouge = (e) => {
      if (!doigts.has(e.pointerId)) return;
      doigts.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pincement && doigts.size === 2) {
        const [p, q] = [...doigts.values()];
        zoom = Math.max(0.7, Math.min(3, pincement.zoom * Math.hypot(p.x - q.x, p.y - q.y) / Math.max(1, pincement.d)));
        demander();
        return;
      }
      if (!glisse || glisse.id !== e.pointerId) return;
      if (!canevas.hasPointerCapture(e.pointerId)) {
        try { canevas.setPointerCapture(e.pointerId); } catch (_) { /* déjà relâché */ }
      }
      az = glisse.az + (e.clientX - glisse.x) * 0.011;
      if (e.pointerType === 'mouse') el = Math.max(-0.35, Math.min(0.9, glisse.el + (e.clientY - glisse.y) * 0.006));
      demander();
    };
    const haut = (e) => {
      doigts.delete(e.pointerId);
      if (doigts.size < 2) pincement = null;
      if (glisse && glisse.id === e.pointerId) glisse = null;
    };
    const molette = (e) => {
      if (!e.ctrlKey && !e.metaKey) return;
      e.preventDefault();
      zoom = Math.max(0.7, Math.min(3, zoom * (e.deltaY > 0 ? 0.92 : 1.08)));
      demander();
    };
    canevas.addEventListener('pointerdown', bas);
    canevas.addEventListener('pointermove', bouge);
    canevas.addEventListener('pointerup', haut);
    canevas.addEventListener('pointercancel', haut);
    canevas.addEventListener('wheel', molette, { passive: false });
    const obs = new ResizeObserver(() => { taille(); demander(); });
    obs.observe(canevas);
    const io = 'IntersectionObserver' in window ? new IntersectionObserver((en) => {
      visible = en[en.length - 1].isIntersecting;
      if (visible) demander();
    }) : null;
    io?.observe(canevas);
    taille();
    demander();
    const ctrl = {
      zoomer(f) { zoom = Math.max(0.7, Math.min(3, zoom * f)); demander(); },
      /** Va à un angle (miniature choisie) et cesse de tourner seul. */
      aller(azimut, elevation) { az = azimut; el = elevation; auto = false; demander(); },
      /** Tourne seul de nouveau (ou s'arrête), sauf si la personne a demandé moins d'animations. */
      tourner(oui) { auto = !!oui && !reduit; t0 = 0; demander(); },
      rafraichir() { demander(); },
      arreter() {
        fini = true;
        canevas.removeEventListener('pointerdown', bas);
        canevas.removeEventListener('pointermove', bouge);
        canevas.removeEventListener('pointerup', haut);
        canevas.removeEventListener('pointercancel', haut);
        canevas.removeEventListener('wheel', molette);
        obs.disconnect();
        io?.disconnect();
        if (tourActif === ctrl) tourActif = null;
      },
      id,
    };
    tourActif = ctrl;
    return ctrl;
  }

  return {
    /** Article d'une rangée : modèle GLB s'il est déposé, sinon dessiné. Renvoie les faces à zone de texture (ancien chemin). */
    async article(id, fiche) {
      let objet = null;
      let glb = false;
      if (fiche.glb) {
        try {
          objet = await depuisGlb(fiche);
          glb = true;
        } catch (err) {
          console.warn('Modèle GLB illisible, modèle dessiné à la place', err);
        }
      }
      if (!objet) objet = fiche.forme === 'casquette' ? casquetteDessinee() : vetementDessine(fiche.forme === 'polo');
      const a = poser(id, objet, fiche);
      return { glb, zones: { devant: !!a.zones?.devant, dos: !!a.zones?.dos } };
    },
    couleurs(id, c) {
      const a = articles.get(id);
      if (!a) return;
      a.couleurs = { ...a.couleurs, ...c };
      preparerMatieres(a);
      tourActif?.id === id && tourActif.rafraichir();
    },
    marquage(id, face, reglage) {
      const a = articles.get(id);
      if (!a) return;
      marquer(a, face, reglage);
      tourActif?.id === id && tourActif.rafraichir();
    },
    /** Vue fixe dans un canevas 2D. */
    rendre(id, vue, canevas, zoom = 1) {
      const a = articles.get(id);
      if (!a) return false;
      const [az, el] = angles(a, vue);
      rendreDans(a, az, el, zoom, canevas, null);
      return true;
    },
    /** Image carrée d'une vue (vues envoyées à l'atelier, maquette téléchargée). */
    capturer(id, vue, cote, fond) {
      const c = document.createElement('canvas');
      c.width = c.height = cote;
      const a = articles.get(id);
      if (!a) return c;
      const [az, el] = angles(a, vue);
      rendreDans(a, az, el, 1, c, fond);
      return c;
    },
    angles(id, vue) {
      const a = articles.get(id);
      return a ? angles(a, vue) : [0, 0];
    },
    tour,
    detruire() {
      tourActif?.arreter();
      renderer.dispose();
    },
  };
}
