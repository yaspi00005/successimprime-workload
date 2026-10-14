/*
 * Atelier 3D : la scène three.js (docs/atelier-3d.md).
 *
 * Chargée par public/js/atelier-3d.js après le premier affichage, et
 * seulement si le navigateur a WebGL : sinon la page garde ses vues à
 * plat. three.js est copié dans public/vendor/three (aucun CDN).
 *
 * Couleur : three.js gère les couleurs en sRGB (la pastille est lue en
 * sRGB, l'image sort en sRGB) et l'éclairage totalise π de face, si bien
 * qu'un tissu vu de face a la couleur de sa pastille. Le prototype
 * (three r128, sans gestion des couleurs) la rendait plus claire.
 *
 * Modèles : t-shirt, polo et casquette dessinés ici. Un vrai modèle GLB
 * déposé dans public/modeles-3d/<forme>.glb les remplace (voir la doc pour
 * le nom des pièces : tissu, visiere, zone_devant, zone_dos).
 */
import * as THREE from 'three';

/** Unités de la scène par centimètre de tissu. */
const CM = 0.0275;
const PROFONDEUR = 0.34;
const BISEAU = 0.11;
const FZ = PROFONDEUR / 2 + BISEAU;
const RECUL = 6.4;
const FOND_VUE = 0xeef2f8;
const COTE_VUE = 1024;

function nuance(hex, ecart) {
  const c = new THREE.Color(hex);
  const h = {};
  c.getHSL(h);
  c.setHSL(h.h, h.s, Math.max(0, Math.min(1, h.l + ecart)));
  return c;
}

function textureTricot() {
  const c = document.createElement('canvas');
  c.width = c.height = 128;
  const g = c.getContext('2d');
  const d = g.createImageData(128, 128);
  for (let y = 0; y < 128; y++) {
    for (let x = 0; x < 128; x++) {
      const v = 128 + (y % 4 < 2 ? 18 : -18) + (Math.random() * 40 - 20);
      const i = (y * 128 + x) * 4;
      d.data[i] = d.data[i + 1] = d.data[i + 2] = v;
      d.data[i + 3] = 255;
    }
  }
  g.putImageData(d, 0, 0);
  const t = new THREE.CanvasTexture(c);
  t.wrapS = t.wrapT = THREE.RepeatWrapping;
  t.repeat.set(5, 5);
  return t;
}

function textureOmbre() {
  const c = document.createElement('canvas');
  c.width = c.height = 256;
  const g = c.getContext('2d');
  const gr = g.createRadialGradient(128, 128, 10, 128, 128, 128);
  gr.addColorStop(0, 'rgba(10,20,40,.32)');
  gr.addColorStop(1, 'rgba(10,20,40,0)');
  g.fillStyle = gr;
  g.fillRect(0, 0, 256, 256);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

/** Contour du t-shirt (et du polo, manches un peu plus courtes, col plus haut). */
function contourTshirt(polo) {
  const s = new THREE.Shape();
  const m = polo ? 0.22 : 0;
  s.moveTo(-0.30, 1.0);
  s.lineTo(-0.64, 0.94);
  s.quadraticCurveTo(-0.95, 0.86, -1.18, 0.60);
  s.lineTo(-0.97 + m * 0.2, 0.30 + m * 0.3);
  s.quadraticCurveTo(-0.80, 0.40, -0.63, 0.46);
  s.quadraticCurveTo(-0.66, -0.2, -0.64, -1.0);
  s.quadraticCurveTo(0, -1.04, 0.64, -1.0);
  s.quadraticCurveTo(0.66, -0.2, 0.63, 0.46);
  s.quadraticCurveTo(0.80, 0.40, 0.97 - m * 0.2, 0.30 + m * 0.3);
  s.lineTo(1.18, 0.60);
  s.quadraticCurveTo(0.95, 0.86, 0.64, 0.94);
  s.lineTo(0.30, 1.0);
  s.quadraticCurveTo(0, polo ? 0.86 : 0.74, -0.30, 1.0);
  return s;
}

/**
 * Taille et place de la zone d'impression sur le buste : sa hauteur
 * part de « haut », sans dépasser « largeurMax » ni descendre sous « bas ».
 */
function placerZone(zone, haut, bas, largeurMax) {
  let l = zone.largeur * CM;
  let h = zone.hauteur * CM;
  const r = Math.min(1, largeurMax / l, (haut - bas) / h);
  l *= r;
  h *= r;
  return { l, h, y: haut - h / 2 };
}

export function creerScene(canvas, options) {
  const reduit = !!options.reduit;
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  // Sortie en sRGB, sans tonalité ajoutée : la couleur reste celle de la pastille.
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.NoToneMapping;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
  camera.position.set(0, 0.15, RECUL);

  // Éclairage de face : 0,61 × 1,6 (ciel et sol) + 0,71 × 2,4 (principale) + 0,78 × 0,6 (appoint) ≈ π.
  scene.add(new THREE.HemisphereLight(0xffffff, 0x808080, 1.6));
  const principale = new THREE.DirectionalLight(0xffffff, 2.4);
  principale.position.set(3, 4, 5);
  scene.add(principale);
  const appoint = new THREE.DirectionalLight(0xffffff, 0.6);
  appoint.position.set(-3, -1, 4);
  scene.add(appoint);
  const contre = new THREE.DirectionalLight(0xffffff, 1.0);
  contre.position.set(-4, 2, -4);
  scene.add(contre);

  const ombre = new THREE.Mesh(new THREE.PlaneGeometry(3.2, 1.1), new THREE.MeshBasicMaterial({ map: textureOmbre(), transparent: true, depthWrite: false }));
  ombre.rotation.x = -Math.PI / 2;
  ombre.position.y = -1.32;
  scene.add(ombre);

  const tricot = textureTricot();
  const racine = new THREE.Group();
  scene.add(racine);

  // Décalques : les canevas dessinés par la page (mêmes que la vue à plat).
  const decalques = {};
  function texturesDecalques() {
    for (const face of ['devant', 'dos']) {
      decalques[face]?.texture.dispose();
      const t = new THREE.CanvasTexture(options.decalques[face]);
      t.colorSpace = THREE.SRGBColorSpace;
      t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
      if (decalques[face]) {
        decalques[face].matiere.map = t;
        decalques[face].matiere.needsUpdate = true;
        decalques[face].texture = t;
      } else {
        decalques[face] = {
          texture: t,
          matiere: new THREE.MeshPhysicalMaterial({ map: t, transparent: true, roughness: 0.8, metalness: 0, specularIntensity: 0.3, depthWrite: false, polygonOffset: true, polygonOffsetFactor: -4 }),
        };
      }
    }
  }
  texturesDecalques();

  let modele = null;
  let matieres = {};
  let couleurs = { tissu: '#0F4590', visiere: '#0F4590' };
  let forme = null;
  const glbCharges = new Map();

  /**
   * Tissu : reflet faible (un coton ne brille pas, et un reflet fort
   * éclaircit les couleurs sombres) et léger velouté sur les bords.
   */
  function matiereTissu(bosse, couleur) {
    return new THREE.MeshPhysicalMaterial({ color: couleur, roughness: 0.9, metalness: 0, specularIntensity: 0.25, sheen: 0.6, sheenRoughness: 0.8, sheenColor: couleur, bumpMap: tricot, bumpScale: bosse });
  }

  function tshirt(polo, zone) {
    const g = new THREE.Group();
    matieres.tissu = [matiereTissu(0.8, couleurs.tissu)];
    const corps = new THREE.ExtrudeGeometry(contourTshirt(polo), { depth: PROFONDEUR, bevelEnabled: true, bevelThickness: BISEAU, bevelSize: 0.07, bevelSegments: 8, curveSegments: 32 });
    corps.translate(0, 0, -PROFONDEUR / 2);
    corps.computeVertexNormals();
    g.add(new THREE.Mesh(corps, matieres.tissu[0]));
    const bord = new THREE.MeshStandardMaterial({ color: nuance(couleurs.tissu, -0.18), roughness: 0.9 });
    matieres.bord = bord;
    if (!polo) {
      for (const cote of [1, -1]) {
        const courbe = new THREE.QuadraticBezierCurve3(new THREE.Vector3(-0.30, 1.0, cote * (FZ - 0.02)), new THREE.Vector3(0, 0.74, cote * (FZ + 0.01)), new THREE.Vector3(0.30, 1.0, cote * (FZ - 0.02)));
        g.add(new THREE.Mesh(new THREE.TubeGeometry(courbe, 40, 0.035, 10, false), bord));
      }
    } else {
      const rabat = (m) => {
        const s = new THREE.Shape();
        s.moveTo(m * 0.40, 1.04);
        s.lineTo(m * 0.30, 1.10);
        s.lineTo(0, 0.84);
        s.lineTo(m * 0.03, 0.70);
        s.lineTo(m * 0.20, 0.74);
        s.closePath();
        const r = new THREE.Mesh(new THREE.ExtrudeGeometry(s, { depth: 0.03, bevelEnabled: true, bevelThickness: 0.015, bevelSize: 0.015, bevelSegments: 3 }), bord);
        r.position.z = FZ - 0.02;
        r.rotation.x = -0.12;
        return r;
      };
      g.add(rabat(-1), rabat(1));
      const patte = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.36, 0.02), bord);
      patte.position.set(0, 0.62, FZ + 0.005);
      g.add(patte);
      const bouton = new THREE.MeshStandardMaterial({ color: 0xf2f2f2, roughness: 0.4 });
      for (const y of [0.70, 0.54]) {
        const b = new THREE.Mesh(new THREE.SphereGeometry(0.022, 16, 12), bouton);
        b.position.set(0, y, FZ + 0.02);
        g.add(b);
      }
      const bande = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.06, 0.06), bord);
      bande.position.set(0, 1.0, -(FZ - 0.03));
      g.add(bande);
    }
    const devant = placerZone(zone, polo ? 0.40 : 0.64, -0.92, 1.0);
    const d = new THREE.Mesh(new THREE.PlaneGeometry(devant.l, devant.h), decalques.devant.matiere);
    d.position.set(0, devant.y, FZ + 0.004);
    g.add(d);
    const dos = placerZone(zone, polo ? 0.66 : 0.70, -0.92, 1.0);
    const b = new THREE.Mesh(new THREE.PlaneGeometry(dos.l, dos.h), decalques.dos.matiere);
    b.position.set(0, dos.y, -(FZ + 0.004));
    b.rotation.y = Math.PI;
    g.add(b);
    return g;
  }

  function casquette(zone) {
    const g = new THREE.Group();
    const R = 0.9;
    const SY = 0.78;
    const tissu = matiereTissu(0.5, couleurs.tissu);
    matieres.tissu = [tissu];
    const calotte = new THREE.Mesh(new THREE.SphereGeometry(R, 64, 32, 0, Math.PI * 2, 0, Math.PI / 2), tissu);
    calotte.scale.y = SY;
    g.add(calotte);
    const dedans = new THREE.Mesh(new THREE.SphereGeometry(R * 0.985, 48, 24, 0, Math.PI * 2, 0, Math.PI / 2), new THREE.MeshStandardMaterial({ color: 0xe9e4da, side: THREE.BackSide, roughness: 1 }));
    dedans.scale.y = SY;
    g.add(dedans);
    const couture = new THREE.LineBasicMaterial({ color: nuance(couleurs.tissu, -0.3) });
    matieres.couture = couture;
    for (let k = 0; k < 6; k++) {
      const phi = k * Math.PI / 3 + Math.PI / 6;
      const points = [];
      for (let i = 0; i <= 40; i++) {
        const th = i / 40 * Math.PI / 2;
        points.push(new THREE.Vector3(-R * 1.003 * Math.cos(phi) * Math.sin(th), R * 1.003 * Math.cos(th) * SY, R * 1.003 * Math.sin(phi) * Math.sin(th)));
      }
      g.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), couture));
    }
    matieres.visiere = [matiereTissu(0.4, couleurs.visiere)];
    const s = new THREE.Shape();
    const a0 = 0.2;
    const a1 = Math.PI - 0.2;
    s.moveTo(Math.cos(a0) * R * 0.98, Math.sin(a0) * R * 0.98);
    for (let i = 1; i <= 40; i++) {
      const a = a0 + (a1 - a0) * i / 40;
      s.lineTo(Math.cos(a) * R * 0.98, Math.sin(a) * R * 0.98);
    }
    for (let i = 40; i >= 0; i--) {
      const a = a0 + (a1 - a0) * i / 40;
      s.lineTo(Math.cos(a) * 1.0, Math.sin(a) * 1.72);
    }
    const visiere = new THREE.Mesh(new THREE.ExtrudeGeometry(s, { depth: 0.03, bevelEnabled: true, bevelThickness: 0.015, bevelSize: 0.02, bevelSegments: 3, curveSegments: 24 }), matieres.visiere[0]);
    visiere.rotation.x = Math.PI / 2 + 0.16;
    visiere.position.y = 0.02;
    g.add(visiere);
    const bouton = new THREE.Mesh(new THREE.SphereGeometry(0.06, 20, 14), tissu);
    bouton.position.y = R * SY + 0.01;
    g.add(bouton);
    const bande = new THREE.Mesh(new THREE.CylinderGeometry(R * 1.004, R * 1.004, 0.05, 64, 1, true), new THREE.MeshStandardMaterial({ color: nuance(couleurs.tissu, -0.2), roughness: 0.9, side: THREE.DoubleSide }));
    bande.position.y = 0.02;
    matieres.bande = bande.material;
    g.add(bande);
    // Panneaux marqués : la largeur suit les proportions de la zone d'impression.
    const theta0 = 0.5;
    const thetaL = 0.82;
    const hauteur = R * SY * thetaL;
    const phiL = Math.max(0.7, Math.min(1.9, (zone.largeur / zone.hauteur) * hauteur / (R * Math.sin(theta0 + thetaL / 2))));
    const panneau = (centre, matiere) => {
      const m = new THREE.Mesh(new THREE.SphereGeometry(R * 1.012, 48, 24, centre - phiL / 2, phiL, theta0, thetaL), matiere);
      m.scale.y = SY;
      return m;
    };
    g.add(panneau(Math.PI / 2, decalques.devant.matiere), panneau(Math.PI * 1.5, decalques.dos.matiere));
    g.position.y = -0.55;
    g.scale.setScalar(1.25);
    return g;
  }

  /** Modèle GLB : les pièces « tissu* », « visiere* », « zone_devant », « zone_dos ». */
  async function glb(url) {
    if (!glbCharges.has(url)) {
      glbCharges.set(url, import(options.urlGltf).then(({ GLTFLoader }) => new GLTFLoader().loadAsync(url)));
    }
    const gltf = await glbCharges.get(url);
    const objet = gltf.scene.clone(true);
    const boite = new THREE.Box3().setFromObject(objet);
    const taille = boite.getSize(new THREE.Vector3());
    const centre = boite.getCenter(new THREE.Vector3());
    const e = 2.2 / Math.max(taille.x, taille.y, 0.001);
    objet.scale.setScalar(e);
    objet.position.copy(centre.multiplyScalar(-e));
    const g = new THREE.Group();
    g.add(objet);
    matieres.tissu = [];
    matieres.visiere = [];
    objet.traverse((o) => {
      if (!o.isMesh) return;
      const nom = (o.name + ' ' + (o.material?.name || '')).toLowerCase();
      if (nom.includes('zone_devant')) {
        o.material = decalques.devant.matiere;
      } else if (nom.includes('zone_dos')) {
        o.material = decalques.dos.matiere;
      } else if (nom.includes('visiere')) {
        o.material = o.material.clone();
        matieres.visiere.push(o.material);
      } else if (nom.includes('tissu')) {
        o.material = o.material.clone();
        matieres.tissu.push(o.material);
      }
    });
    return g;
  }

  let entree = 1;
  function poser(nouveau) {
    if (modele) {
      racine.remove(modele);
      modele.traverse((o) => { if (o.geometry && !o.userData.garder) o.geometry.dispose(); });
    }
    modele = nouveau;
    racine.add(modele);
    appliquerCouleurs();
    entree = reduit ? 1 : 0;
    demander();
  }

  function appliquerCouleurs() {
    for (const m of matieres.tissu || []) { m.color.set(couleurs.tissu); m.sheenColor?.set(couleurs.tissu); }
    for (const m of matieres.visiere || []) { m.color.set(couleurs.visiere); m.sheenColor?.set(couleurs.visiere); }
    matieres.bord?.color.copy(nuance(couleurs.tissu, -0.18));
    matieres.couture?.color.copy(nuance(couleurs.tissu, -0.3));
    matieres.bande?.color.copy(nuance(couleurs.tissu, -0.2));
  }

  // ---- interaction ----
  let rotY = 0;
  let cibleY = 0;
  let rotX = 0;
  let cibleX = 0.05;
  let zoom = RECUL;
  let cibleZoom = RECUL;
  let tourne = false;
  let flotte = !reduit;
  let glisse = null;

  canvas.addEventListener('pointerdown', (e) => {
    glisse = { x: e.clientX, y: e.clientY, ry: cibleY, rx: cibleX, id: e.pointerId };
    options.surVue?.(null);
    tourne = false;
    options.surRotation?.(false);
    demander();
  });
  canvas.addEventListener('pointermove', (e) => {
    if (!glisse || glisse.id !== e.pointerId) return;
    if (!canvas.hasPointerCapture(e.pointerId)) {
      try { canvas.setPointerCapture(e.pointerId); } catch (_) { /* déjà relâché */ }
    }
    cibleY = glisse.ry + (e.clientX - glisse.x) * 0.012;
    if (e.pointerType === 'mouse') cibleX = Math.max(-0.5, Math.min(0.6, glisse.rx + (e.clientY - glisse.y) * 0.006));
    demander();
  });
  const lacher = () => { glisse = null; };
  canvas.addEventListener('pointerup', lacher);
  canvas.addEventListener('pointercancel', lacher);
  canvas.addEventListener('wheel', (e) => {
    // Ctrl + molette (ou pincement sur un pavé tactile) : la molette seule fait défiler la page.
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    cibleZoom = Math.max(4.2, Math.min(9, cibleZoom + e.deltaY * 0.01));
    demander();
  }, { passive: false });
  canvas.addEventListener('dblclick', () => { cibleZoom = RECUL; vue('devant'); options.surVue?.('devant'); });

  function vue(v) {
    if (!v) return;
    tourne = false;
    const base = Math.round(cibleY / (Math.PI * 2)) * Math.PI * 2;
    cibleY = base + ({ devant: 0, cote: -Math.PI / 2.4, dos: Math.PI })[v];
    cibleX = 0.05;
    demander();
  }

  // ---- boucle : tourne seulement quand quelque chose bouge ----
  let enCours = false;
  let visible = true;
  let t0 = 0;
  let mesures = null;
  function demander() {
    if (enCours || !visible) return;
    enCours = true;
    t0 = performance.now();
    requestAnimationFrame(image);
  }
  function image(maintenant) {
    const dt = Math.min(0.05, Math.max(0, (maintenant - t0) / 1000));
    if (mesures) mesures.push(maintenant - t0);
    t0 = maintenant;
    if (tourne) cibleY += dt * 0.7;
    const k = Math.min(1, dt * 7);
    rotY += (cibleY - rotY) * k;
    rotX += (cibleX - rotX) * k;
    zoom += (cibleZoom - zoom) * Math.min(1, dt * 6);
    if (entree < 1) entree = Math.min(1, entree + dt * 1.6);
    const e = 1 - Math.pow(1 - entree, 3);
    racine.rotation.y = rotY + (1 - e) * Math.PI * 1.2;
    racine.rotation.x = rotX;
    racine.scale.setScalar(0.6 + 0.4 * e);
    racine.position.y = flotte ? Math.sin(maintenant / 900) * 0.035 : 0;
    camera.position.z = zoom;
    camera.lookAt(0, 0, 0);
    renderer.render(scene, camera);
    const bouge = tourne || flotte || glisse || entree < 1 || mesures
      || Math.abs(cibleY - rotY) > 1e-4 || Math.abs(cibleX - rotX) > 1e-4 || Math.abs(cibleZoom - zoom) > 1e-3;
    if (bouge && visible) {
      requestAnimationFrame(image);
    } else {
      enCours = false;
    }
  }

  function taille() {
    const r = canvas.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return;
    renderer.setSize(r.width, r.height, false);
    camera.aspect = r.width / r.height;
    camera.updateProjectionMatrix();
    renderer.render(scene, camera);
    demander();
  }
  const observateur = new ResizeObserver(taille);
  observateur.observe(canvas);
  const surEcran = 'IntersectionObserver' in window ? new IntersectionObserver((entrees) => {
    visible = entrees[entrees.length - 1].isIntersecting;
    if (visible) demander();
  }) : null;
  surEcran?.observe(canvas);
  canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); options.surErreur?.('contexte'); });

  return {
    /** Article affiché : { forme, zone, glb }. */
    async article(fiche) {
      const cle = fiche.forme + '|' + fiche.zone.largeur + '|' + fiche.zone.hauteur + '|' + (fiche.glb || '');
      if (cle === forme) return;
      forme = cle;
      texturesDecalques();
      matieres = {};
      let objet = null;
      if (fiche.glb) {
        try {
          objet = await glb(fiche.glb);
        } catch (err) {
          console.warn('Modèle GLB illisible, modèle dessiné à la place', err);
        }
        if (forme !== cle) return;
      }
      if (!objet) {
        matieres = {};
        objet = fiche.forme === 'casquette' ? casquette(fiche.zone) : tshirt(fiche.forme === 'polo', fiche.zone);
      }
      poser(objet);
    },
    couleurs(c) {
      couleurs = { ...couleurs, ...c };
      appliquerCouleurs();
      demander();
    },
    decalque(face) {
      if (decalques[face]) decalques[face].texture.needsUpdate = true;
      demander();
    },
    vue,
    rotation(oui) { tourne = !!oui; demander(); },
    flottement(oui) { flotte = !!oui && !reduit; demander(); },
    /** Images par seconde, mesurées sur 1,5 s d'animation (les 5 premières images, compilation des shaders, ne comptent pas). */
    mesurer() {
      mesures = [];
      demander();
      return new Promise((fin) => {
        setTimeout(() => {
          const d = mesures.slice(5).sort((a, b) => a - b);
          mesures = null;
          demander();
          fin(d.length < 5 ? 0 : 1000 / d[Math.floor(d.length / 2)]);
        }, 1500);
      });
    },
    /** Vue de face ou de dos, carrée, sur fond clair : image PNG (adresse data:). */
    capturer(face) {
      const avant = { y: racine.rotation.y, x: racine.rotation.x, s: racine.scale.x, p: racine.position.y, z: camera.position.z };
      const ratio = renderer.getPixelRatio();
      const t = renderer.getSize(new THREE.Vector2());
      racine.rotation.set(0, face === 'dos' ? Math.PI : 0, 0);
      racine.scale.setScalar(1);
      racine.position.y = 0;
      camera.position.z = RECUL;
      camera.lookAt(0, 0, 0);
      renderer.setPixelRatio(1);
      renderer.setSize(COTE_VUE, COTE_VUE, false);
      camera.aspect = 1;
      camera.updateProjectionMatrix();
      scene.background = new THREE.Color(FOND_VUE);
      renderer.render(scene, camera);
      const url = canvas.toDataURL('image/png');
      scene.background = null;
      renderer.setPixelRatio(ratio);
      renderer.setSize(t.x, t.y, false);
      camera.aspect = t.x / t.y;
      camera.updateProjectionMatrix();
      racine.rotation.set(avant.x, avant.y, 0);
      racine.scale.setScalar(avant.s);
      racine.position.y = avant.p;
      camera.position.z = avant.z;
      renderer.render(scene, camera);
      return url;
    },
    detruire() {
      observateur.disconnect();
      surEcran?.disconnect();
      renderer.dispose();
    },
  };
}
