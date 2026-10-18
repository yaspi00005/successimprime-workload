/*
 * Retouche d'une image client en prépresse (maquettes PP01 à PP05,
 * docs/prepresse-retouche.md). Aperçu en direct dans le navigateur ; les
 * calculs suivent ceux du serveur (src/Service/Prepresse/GeometrieRetouche.php
 * et GenerateurRetouche.php), qui refait le fichier final à partir de
 * l'original et revérifie les contrôles.
 */
(function () {
	'use strict';

	const racine = document.querySelector('[data-rt]');
	if (!racine || !racine.dataset.config) {
		return;
	}
	const C = JSON.parse(racine.dataset.config);
	const S = C.seuils;
	const $ = (sel, el) => (el || racine).querySelector(sel);
	const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));
	const nb = (v, d = 0) => Number(v).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ /g, ' ');
	const mm = (v) => nb(v, Math.abs(v - Math.round(v)) < 0.05 ? 0 : 1);
	const px = (valeurMm, dpi) => Math.max(1, Math.round(valeurMm / 25.4 * dpi));
	const MODES = { adapter: 'adapter', recadrer: 'recadrer', etirer: 'étirer' };
	const NOMS_COULEURS = { '#000000': 'noir', '#FFFFFF': 'blanc', '#0F4590': 'bleu', '#DE1028': 'rouge', '#FDC82A': 'jaune' };

	function etatInitial() {
		return {
			etape: 'reglages',
			onglet: 'format',
			unite: 'mm',
			l: C.defaut.largeurMm,
			h: C.defaut.hauteurMm,
			proportions: true,
			mode: C.defaut.mode,
			dpi: C.defaut.dpiSortie,
			dpiModifie: false,
			fondPerdu: false,
			fond: C.source.transparence ? 'transparent' : '#FFFFFF',
			contour: { actif: false, type: 'rectangle', couleur: '#000000', epaisseurMm: 2, retraitMm: 0, source: C.source.transparence ? 'alpha' : 'auto' },
			format: 'pdf',
			nom: '',
			nomModifie: false,
			forcer: false,
			raison: '',
			erreurForme: null,
			genere: null,
		};
	}
	let E = etatInitial();
	let image = null; // ImageBitmap en taille réelle
	let apercu = null; // version réduite pour l'aperçu
	let facteurApercu = 1;

	/* ------------------------------------------------------------------
	 * Calculs (mêmes règles que GeometrieRetouche)
	 * ------------------------------------------------------------------ */
	function placement() {
		const sl = C.source.largeur, sh = C.source.hauteur;
		const b = E.fondPerdu ? S.fondPerduMm : 0;
		const L = E.l + 2 * b, H = E.h + 2 * b;
		let source = { x: 0, y: 0, l: sl, h: sh }, dest;
		if (E.mode === 'adapter') {
			const e = Math.min(E.l / sl, E.h / sh);
			dest = { x: b + (E.l - sl * e) / 2, y: b + (E.h - sh * e) / 2, l: sl * e, h: sh * e };
		} else if (E.mode === 'etirer') {
			dest = { x: 0, y: 0, l: L, h: H };
		} else {
			const e = Math.max(L / sl, H / sh);
			const vl = L / e, vh = H / e;
			source = { x: (sl - vl) / 2, y: (sh - vh) / 2, l: vl, h: vh };
			dest = { x: 0, y: 0, l: L, h: H };
		}
		return { L, H, b, dest, source, sortiePx: { l: px(L, E.dpi), h: px(H, E.dpi) } };
	}
	function dpiEffectif(p) {
		return Math.round(Math.min(p.source.l / (p.dest.l / 25.4), p.source.h / (p.dest.h / 25.4)));
	}
	function deformation(p) {
		const r = (p.L / p.H) / (C.source.largeur / C.source.hauteur);
		return Math.round((r >= 1 ? r - 1 : -(1 / r - 1)) * 100);
	}
	const grandFormat = () => Math.max(E.l, E.h) > S.petitFormatMaxMm;
	// Sortie conseillée (GeometrieRetouche::dpiSortieConseille) : suit les dimensions tant qu'on ne l'a pas changée.
	function dpiConseille() {
		const g = Math.max(E.l, E.h);
		return g <= S.petitFormatMaxMm ? 300 : (g <= 2000 ? 150 : 100);
	}
	function suivreDpi() {
		if (!E.dpiModifie) { E.dpi = dpiConseille(); }
	}

	/* Fichier livré à l'échelle (1/10 pour un grand format) : taille reçue × échelle. */
	const R = C.recu;
	const cmTexte = (l, h) => `${mm(l / 10)} × ${mm(h / 10)} cm`;
	function echelleActive() {
		return [1, ...R.echelles].find((k) => Math.abs(R.largeurMm * k - E.l) <= Math.max(1, E.l * 0.005) && Math.abs(R.hauteurMm * k - E.h) <= Math.max(1, E.h * 0.005)) || null;
	}
	function majEchelle() {
		const active = echelleActive();
		$$('[data-rt-echelles] button', racine).forEach((b) => b.setAttribute('aria-pressed', +b.dataset.k === active ? 'true' : 'false'));
		const note = $('[data-rt-echelle-note]');
		const textes = [];
		if (R.echelle && E.l === C.defaut.largeurMm && E.h === C.defaut.hauteurMm) {
			textes.push(`Fichier reçu au 1/${R.echelle} : agrandi ×${R.echelle} au format commandé (${cmTexte(E.l, E.h)}).`);
		}
		if (active && active > 1) {
			const dpi = dpiEffectif(placement());
			const mini = grandFormat() ? S.dpiMinGrand : S.dpiMinPetit;
			if (dpi < mini) {
				textes.push(`À taille réelle, l'image ne donne que ${dpi} dpi. Un fichier au 1/${active} doit être fait à ${active} fois la résolution : ${mini * active} dpi pour ${mini} dpi à l'impression. Pour un fichier vectoriel (PDF, AI, EPS), passer par l'outil de montage, qui agrandit sans perte.`);
			}
		}
		note.textContent = textes.join(' ');
		note.hidden = textes.length === 0;
	}

	function controles() {
		const p = placement();
		const dpi = dpiEffectif(p);
		const taille = mm(E.l) + ' × ' + mm(E.h) + ' mm';
		const liste = [];
		let etat, valeur;
		if (grandFormat()) {
			etat = dpi >= S.dpiMinGrand ? 'ok' : 'bloquant';
			valeur = etat === 'ok'
				? `à ${taille}. Suffisant pour un grand format (minimum ${S.dpiMinGrand} dpi en grand format, ${S.dpiPetit} dpi en petit format).`
				: `à ${taille}. Trop faible : l'image sera floue, même de loin (minimum ${S.dpiMinGrand} dpi).`;
		} else {
			etat = dpi >= S.dpiPetit ? 'ok' : (dpi >= S.dpiMinPetit ? 'attention' : 'bloquant');
			valeur = {
				ok: `à ${taille}. Bon pour un petit format (${S.dpiPetit} dpi conseillés).`,
				attention: `à ${taille}. Un peu juste pour un petit format vu de près (${S.dpiPetit} dpi conseillés).`,
				bloquant: `à ${taille}. Trop faible : l'image sera floue (${S.dpiPetit} dpi conseillés en petit format).`,
			}[etat];
		}
		liste.push({ cle: 'resolution', libelle: 'Résolution', grand: dpi, unite: 'dpi', valeur, etat });
		if (E.mode === 'etirer') {
			const d = deformation(p);
			const bloque = Math.abs(d) > S.deformation * 100;
			liste.push({
				cle: 'deformation', libelle: 'Déformation', grand: (d > 0 ? '+' : '−') + Math.abs(d), unite: '%',
				valeur: bloque ? `${d > 0 ? 'largeur' : 'hauteur'} étirée par rapport à ${d > 0 ? 'la hauteur' : 'la largeur'}. Choix volontaire possible, mais le texte sera déformé.` : 'Proportions presque identiques : pas de déformation visible.',
				etat: bloque ? 'bloquant' : 'ok',
			});
		}
		if (p.sortiePx.l * p.sortiePx.h > S.sortieMaxPx) {
			liste.push({ cle: 'sortie', libelle: 'Taille du fichier', grand: nb(p.sortiePx.l * p.sortiePx.h / 1e6), unite: 'Mpx', valeur: 'Trop grand pour être généré : baissez la résolution de sortie.', etat: 'bloquant' });
		}
		return liste;
	}
	const bloquants = () => controles().filter((c) => c.etat === 'bloquant');
	const bloqueSansRemede = () => bloquants().some((c) => c.cle === 'sortie');
	const peutValider = () => !bloqueSansRemede() && (bloquants().length === 0 || (E.forcer && E.raison.trim().length >= S.raisonMin)) && !(E.contour.actif && E.contour.type === 'forme' && E.erreurForme);

	/* ------------------------------------------------------------------
	 * Dessin
	 * ------------------------------------------------------------------ */
	let motifHachures = null;
	function hachures(ctx) {
		if (!motifHachures) {
			const c = document.createElement('canvas');
			c.width = c.height = 10;
			const g = c.getContext('2d');
			g.fillStyle = 'rgba(222,16,40,.25)';
			g.fillRect(0, 0, 10, 10);
			g.strokeStyle = 'rgba(222,16,40,.95)';
			g.lineWidth = 2;
			g.beginPath();
			g.moveTo(-2, 12); g.lineTo(12, -2);
			g.moveTo(-2, 2); g.lineTo(2, -2);
			g.moveTo(8, 12); g.lineTo(12, 8);
			g.stroke();
			motifHachures = c;
		}
		return ctx.createPattern(motifHachures, 'repeat');
	}
	function damier(ctx, x, y, l, h, taille) {
		ctx.save();
		ctx.beginPath(); ctx.rect(x, y, l, h); ctx.clip();
		ctx.fillStyle = '#fff'; ctx.fillRect(x, y, l, h);
		ctx.fillStyle = '#e4e7ee';
		for (let j = 0; j * taille < h; j++) {
			for (let i = (j % 2); i * taille < l; i += 2) {
				ctx.fillRect(x + i * taille, y + j * taille, taille, taille);
			}
		}
		ctx.restore();
	}
	function preparerToile(canvas) {
		const boite = canvas.parentElement.getBoundingClientRect();
		const r = window.devicePixelRatio || 1;
		canvas.width = Math.max(1, Math.round(boite.width * r));
		canvas.height = Math.max(1, Math.round(boite.height * r));
		canvas.style.width = boite.width + 'px';
		canvas.style.height = boite.height + 'px';
		const ctx = canvas.getContext('2d');
		ctx.imageSmoothingQuality = 'high';
		return { ctx, l: canvas.width, h: canvas.height, r };
	}

	function dessinerOriginal() {
		const t = preparerToile($('[data-rt-canvas="original"]'));
		const marge = 24 * t.r;
		const e = Math.min((t.l - 2 * marge) / C.source.largeur, (t.h - 2 * marge) / C.source.hauteur);
		const l = C.source.largeur * e, h = C.source.hauteur * e;
		const x = (t.l - l) / 2, y = (t.h - h) / 2;
		if (C.source.transparence) {
			damier(t.ctx, x, y, l, h, 8 * t.r);
		}
		if (!C.source.transparence) {
			t.ctx.shadowColor = 'rgba(0,0,0,.35)'; t.ctx.shadowBlur = 18 * t.r;
		}
		t.ctx.drawImage(apercu, x, y, l, h);
		t.ctx.shadowBlur = 0;
		$('[data-rt-info-original]').textContent = `${nb(C.source.largeur)} × ${nb(C.source.hauteur)} px · ratio ${nb(C.source.largeur / C.source.hauteur, 2)}`;
	}

	/*
	 * Dessine le fichier de sortie (ou une partie, pour la loupe) dans ctx :
	 * vue = { echelle (px de toile par mm), ox, oy } ; src = image à utiliser.
	 */
	function dessinerSortie(ctx, vue, src, facteur, avecDebord) {
		const p = placement();
		const X = (v) => vue.ox + v * vue.echelle;
		const Y = (v) => vue.oy + v * vue.echelle;
		const s = p.source, d = p.dest;
		// Surplus coupé (recadrer) : l'image entière derrière, hachurée hors du format.
		if (avecDebord && E.mode === 'recadrer') {
			const e = d.l / s.l;
			const gx = X(-s.x * e), gy = Y(-s.y * e), gl = C.source.largeur * e * vue.echelle, gh = C.source.hauteur * e * vue.echelle;
			ctx.save();
			ctx.globalAlpha = 0.55;
			ctx.drawImage(src, gx, gy, gl, gh);
			ctx.globalAlpha = 1;
			ctx.fillStyle = hachures(ctx);
			ctx.fillRect(gx, gy, gl, gh);
			ctx.restore();
		}
		// Fond du format.
		const transparent = (E.mode === 'adapter' && E.fond === 'transparent') || (E.mode !== 'adapter' && C.source.transparence);
		ctx.save();
		ctx.beginPath(); ctx.rect(X(0), Y(0), p.L * vue.echelle, p.H * vue.echelle); ctx.clip();
		if (transparent || C.source.transparence) {
			damier(ctx, X(0), Y(0), p.L * vue.echelle, p.H * vue.echelle, Math.max(6, 8 * (window.devicePixelRatio || 1)));
		}
		if (E.mode === 'adapter' && E.fond !== 'transparent') {
			ctx.fillStyle = E.fond; ctx.fillRect(X(0), Y(0), p.L * vue.echelle, p.H * vue.echelle);
		}
		ctx.drawImage(src, s.x * facteur, s.y * facteur, s.l * facteur, s.h * facteur, X(d.x), Y(d.y), d.l * vue.echelle, d.h * vue.echelle);
		ctx.restore();
	}

	/* Contour de forme : masque, distances (chanfrein 5-7), trait vers l'intérieur. */
	let cacheForme = { cle: null, toile: null, erreur: null };
	function traitForme(l, h, echelle) {
		const p = placement();
		const cle = JSON.stringify([l, h, E.l, E.h, E.mode, E.fondPerdu, E.contour.couleur, E.contour.epaisseurMm, E.contour.source]);
		if (cacheForme.cle === cle) {
			return cacheForme;
		}
		const toile = document.createElement('canvas');
		toile.width = l; toile.height = h;
		const g = toile.getContext('2d', { willReadFrequently: true });
		g.drawImage(apercu, p.source.x * facteurApercu, p.source.y * facteurApercu, p.source.l * facteurApercu, p.source.h * facteurApercu, p.dest.x * echelle, p.dest.y * echelle, p.dest.l * echelle, p.dest.h * echelle);
		const donnees = g.getImageData(0, 0, l, h);
		const px = donnees.data;
		const n = l * h;
		const dedans = new Uint8Array(n);
		let compte = 0;
		let erreur = null;
		if (E.contour.source === 'alpha') {
			if (!C.source.transparence) {
				erreur = 'Pas de transparence dans ce fichier : la forme ne peut pas être suivie.';
			} else {
				for (let i = 0; i < n; i++) { if (px[i * 4 + 3] >= 128) { dedans[i] = 1; compte++; } }
				if (compte / n > 0.995) { erreur = 'Pas de transparence dans ce fichier : la forme ne peut pas être suivie.'; }
				if (compte / n < 0.005) { erreur = 'Image presque entièrement transparente : aucune forme à suivre.'; }
			}
		} else {
			// Couleur du fond aux quatre coins de l'image posée, puis remplissage depuis les bords.
			const zx = Math.max(0, Math.floor(p.dest.x * echelle)), zy = Math.max(0, Math.floor(p.dest.y * echelle));
			const zx2 = Math.min(l - 1, Math.ceil((p.dest.x + p.dest.l) * echelle) - 1), zy2 = Math.min(h - 1, Math.ceil((p.dest.y + p.dest.h) * echelle) - 1);
			const coin = (cx, cy) => {
				const m = [0, 0, 0]; let k = 0;
				for (let y = Math.max(zy, cy - 2); y <= Math.min(zy2, cy + 2); y++) {
					for (let x = Math.max(zx, cx - 2); x <= Math.min(zx2, cx + 2); x++) {
						const i = (y * l + x) * 4;
						if (px[i + 3] < 128) { m[0] += 255; m[1] += 255; m[2] += 255; } else { m[0] += px[i]; m[1] += px[i + 1]; m[2] += px[i + 2]; }
						k++;
					}
				}
				return m.map((v) => v / Math.max(1, k));
			};
			const coins = [coin(zx, zy), coin(zx2, zy), coin(zx, zy2), coin(zx2, zy2)];
			const ecart = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);
			if (coins.some((a) => coins.some((b) => ecart(a, b) > S.toleranceFond))) {
				erreur = 'Le fond n\'est pas uni (les coins n\'ont pas la même couleur) : le détourage automatique n\'est pas possible.';
			} else {
				const fond = [0, 1, 2].map((c) => coins.reduce((s, v) => s + v[c], 0) / 4);
				const estFond = new Uint8Array(n);
				const pile = [];
				for (let x = 0; x < l; x++) { pile.push(x, (h - 1) * l + x); }
				for (let y = 0; y < h; y++) { pile.push(y * l, y * l + l - 1); }
				while (pile.length) {
					const i = pile.pop();
					if (estFond[i]) { continue; }
					const j = i * 4;
					if (px[j + 3] >= 128 && ecart([px[j], px[j + 1], px[j + 2]], fond) > S.toleranceFond) { continue; }
					estFond[i] = 1;
					const x = i % l;
					if (x > 0 && !estFond[i - 1]) { pile.push(i - 1); }
					if (x < l - 1 && !estFond[i + 1]) { pile.push(i + 1); }
					if (i >= l && !estFond[i - l]) { pile.push(i - l); }
					if (i < n - l && !estFond[i + l]) { pile.push(i + l); }
				}
				for (let i = 0; i < n; i++) { if (!estFond[i]) { dedans[i] = 1; compte++; } }
				if (compte / n < 0.005 || compte / n > 0.97) { erreur = 'Aucune forme nette trouvée sur le fond : le détourage automatique n\'est pas possible.'; }
			}
		}
		if (erreur) {
			cacheForme = { cle, toile: null, erreur };
			return cacheForme;
		}
		const t = E.contour.epaisseurMm * echelle;
		const inf = (Math.ceil(t) + 3) * 5;
		const d = new Int32Array(n);
		for (let i = 0; i < n; i++) { d[i] = dedans[i] ? inf : 0; }
		for (let y = 0; y < h; y++) {
			for (let x = 0; x < l; x++) {
				const i = y * l + x;
				if (!d[i]) { continue; }
				let v = d[i];
				v = Math.min(v, x > 0 ? d[i - 1] + 5 : 5);
				v = y > 0 ? Math.min(v, d[i - l] + 5, x > 0 ? d[i - l - 1] + 7 : 5, x < l - 1 ? d[i - l + 1] + 7 : 5) : Math.min(v, 5);
				d[i] = v;
			}
		}
		for (let y = h - 1; y >= 0; y--) {
			for (let x = l - 1; x >= 0; x--) {
				const i = y * l + x;
				if (!d[i]) { continue; }
				let v = d[i];
				v = Math.min(v, x < l - 1 ? d[i + 1] + 5 : 5);
				v = y < h - 1 ? Math.min(v, d[i + l] + 5, x < l - 1 ? d[i + l + 1] + 7 : 5, x > 0 ? d[i + l - 1] + 7 : 5) : Math.min(v, 5);
				d[i] = v;
			}
		}
		const c = E.contour.couleur;
		const r = parseInt(c.slice(1, 3), 16), gg = parseInt(c.slice(3, 5), 16), b = parseInt(c.slice(5, 7), 16);
		const trait = g.createImageData(l, h);
		for (let i = 0; i < n; i++) {
			if (!d[i]) { continue; }
			const a = Math.max(0, Math.min(1, t - d[i] / 5 + 1));
			if (a > 0) {
				const j = i * 4;
				trait.data[j] = r; trait.data[j + 1] = gg; trait.data[j + 2] = b;
				// Ne déborde pas d'un bord semi-transparent : suit l'opacité du pixel.
				trait.data[j + 3] = Math.round(a * Math.max(px[j + 3], E.contour.source === 'auto' ? 255 : 0));
			}
		}
		g.clearRect(0, 0, l, h);
		g.putImageData(trait, 0, 0);
		cacheForme = { cle, toile, erreur: null };
		return cacheForme;
	}

	function dessinerContour(ctx, vue, l, h) {
		if (!E.contour.actif) {
			return;
		}
		const p = placement();
		if (E.contour.type === 'rectangle') {
			const e = E.contour.epaisseurMm * vue.echelle;
			const r = (p.b + E.contour.retraitMm) * vue.echelle;
			const x0 = vue.ox + r, y0 = vue.oy + r, L = p.L * vue.echelle - 2 * r, H = p.H * vue.echelle - 2 * r;
			if (L <= 0 || H <= 0) { return; }
			ctx.fillStyle = E.contour.couleur;
			ctx.fillRect(x0, y0, L, Math.min(e, H));
			ctx.fillRect(x0, y0 + H - Math.min(e, H), L, Math.min(e, H));
			ctx.fillRect(x0, y0, Math.min(e, L), H);
			ctx.fillRect(x0 + L - Math.min(e, L), y0, Math.min(e, L), H);
			return;
		}
		const res = traitForme(l, h, vue.echelle);
		if (res.toile) {
			ctx.drawImage(res.toile, vue.ox, vue.oy);
		}
	}

	function dessinerResultat() {
		const canvas = $('[data-rt-canvas="resultat"]');
		const t = preparerToile(canvas);
		const p = placement();
		// Boîte à montrer : le format, plus le surplus coupé en mode recadrer.
		let bx0 = 0, by0 = 0, bx1 = p.L, by1 = p.H;
		if (E.mode === 'recadrer' && E.etape === 'reglages') {
			const e = p.dest.l / p.source.l;
			bx0 = -p.source.x * e; by0 = -p.source.y * e; bx1 = p.L - bx0; by1 = p.H - by0;
		}
		const marge = 26 * t.r;
		const echelle = Math.min((t.l - 2 * marge) / (bx1 - bx0), (t.h - 2 * marge) / (by1 - by0));
		const ox = Math.round((t.l - (bx1 - bx0) * echelle) / 2 - bx0 * echelle);
		const oy = Math.round((t.h - (by1 - by0) * echelle) / 2 - by0 * echelle);
		const vue = { echelle, ox, oy };
		t.ctx.save();
		t.ctx.shadowColor = 'rgba(0,0,0,.4)'; t.ctx.shadowBlur = 18 * t.r;
		t.ctx.fillStyle = '#fff';
		t.ctx.fillRect(ox, oy, p.L * echelle, p.H * echelle);
		t.ctx.restore();
		dessinerSortie(t.ctx, vue, apercu, facteurApercu, E.etape === 'reglages');
		E.erreurForme = null;
		if (E.contour.actif) {
			const l = Math.max(1, Math.round(p.L * echelle)), h = Math.max(1, Math.round(p.H * echelle));
			t.ctx.save();
			t.ctx.translate(ox, oy);
			dessinerContour(t.ctx, { echelle, ox: 0, oy: 0 }, l, h);
			t.ctx.restore();
			if (E.contour.type === 'forme') {
				E.erreurForme = cacheForme.erreur;
			}
		}
		if (E.fondPerdu) {
			t.ctx.save();
			t.ctx.setLineDash([6 * t.r, 4 * t.r]);
			t.ctx.strokeStyle = '#2A93D5'; t.ctx.lineWidth = 1.5 * t.r;
			t.ctx.strokeRect(ox + p.b * echelle, oy + p.b * echelle, E.l * echelle, E.h * echelle);
			t.ctx.restore();
		}
		const legende = E.etape === 'valider' ? 'Final' : 'Résultat · ' + MODES[E.mode];
		$('[data-rt-legende-resultat]').textContent = E.contour.actif && E.etape === 'reglages' && E.onglet === 'contour' ? 'Après · contour ' + mm(E.contour.epaisseurMm) + ' mm' : legende;
		$('[data-rt-info-resultat]').textContent = `${mm(E.l)} × ${mm(E.h)} mm${E.fondPerdu ? ' + fond perdu' : ''} · ${E.contour.actif && E.etape === 'valider' ? 'contour ' + mm(E.contour.epaisseurMm) + ' mm' : 'ratio ' + nb(E.l / E.h, 3)}`;
		dessinerLoupe();
	}

	function dessinerLoupe() {
		const boite = $('[data-rt-loupe]');
		const montrer = E.contour.actif && E.contour.type === 'rectangle' && E.onglet === 'contour' && E.etape === 'reglages';
		boite.hidden = !montrer;
		if (!montrer) { return; }
		const canvas = $('[data-rt-canvas="loupe"]');
		const t = preparerToile(canvas);
		const p = placement();
		// Loupe × 12 par rapport à l'aperçu, plus si le trait reste trop fin pour être vu.
		let zoom = 12;
		const base = canvasEchelleResultat() * t.r;
		while (E.contour.epaisseurMm * base * zoom < 14 * t.r && zoom < 400) { zoom *= 2; }
		while ((p.b + E.contour.retraitMm + E.contour.epaisseurMm) * base * zoom > t.l * 0.6 && zoom > 1) { zoom /= 2; }
		const echelle = base * zoom;
		const vue = { echelle, ox: 0.18 * t.l, oy: 0.18 * t.h };
		t.ctx.fillStyle = '#2b303b'; t.ctx.fillRect(0, 0, t.l, t.h);
		dessinerSortie(t.ctx, vue, image, 1, false);
		dessinerContour(t.ctx, vue, 0, 0);
		// Repère de l'épaisseur.
		const r = (p.b + E.contour.retraitMm) * echelle;
		t.ctx.strokeStyle = '#FDC82A'; t.ctx.lineWidth = 1.5 * t.r; t.ctx.setLineDash([3 * t.r, 3 * t.r]);
		t.ctx.beginPath(); t.ctx.moveTo(vue.ox + r, 0); t.ctx.lineTo(vue.ox + r, t.h); t.ctx.stroke();
		$('[data-rt-loupe-texte]').textContent = `${mm(E.contour.epaisseurMm)} mm · loupe × ${nb(zoom)}`;
	}
	function canvasEchelleResultat() {
		const c = $('[data-rt-canvas="resultat"]');
		const p = placement();
		return Math.min(c.clientWidth / p.L, c.clientHeight / p.H);
	}

	/* ------------------------------------------------------------------
	 * Panneau de réglages
	 * ------------------------------------------------------------------ */
	const enUnite = (valeurMm) => E.unite === 'cm' ? Math.round(valeurMm) / 10 : (E.unite === 'px' ? px(valeurMm, E.dpi) : Math.round(valeurMm * 10) / 10);
	const depuisUnite = (v) => E.unite === 'cm' ? v * 10 : (E.unite === 'px' ? v / E.dpi * 25.4 : v);

	function majChamps() {
		const champ = (n) => $(`[data-rt-champ="${n}"]`);
		if (document.activeElement !== champ('largeur')) { champ('largeur').value = enUnite(E.l); }
		if (document.activeElement !== champ('hauteur')) { champ('hauteur').value = enUnite(E.h); }
		$$('[data-rt-unite-texte]', racine).forEach((el) => { el.textContent = E.unite; });
		champ('proportions').checked = E.proportions;
		$('[data-rt-cadenas]').setAttribute('aria-pressed', E.proportions ? 'true' : 'false');
		$('[data-rt-cadenas] i').className = 'fas fa-' + (E.proportions ? 'lock' : 'lock-open');
		if (document.activeElement !== champ('dpi')) { champ('dpi').value = E.dpi; }
		champ('fondPerdu').checked = E.fondPerdu;
		champ('contour').checked = E.contour.actif;
		if (document.activeElement !== champ('couleur')) { champ('couleur').value = E.contour.couleur; }
		if (document.activeElement !== champ('epaisseur')) { champ('epaisseur').value = E.contour.epaisseurMm; }
		champ('epaisseurCurseur').value = Math.min(10, Math.max(0.5, E.contour.epaisseurMm));
		if (document.activeElement !== champ('retrait')) { champ('retrait').value = E.contour.retraitMm; }
		if (document.activeElement !== champ('nom')) { champ('nom').value = E.genere ? E.genere.nom : nomFichier(); }
		champ('raison').value = E.raison;

		presse('[data-rt-unite]', E.unite);
		presse('[data-rt-modes]', E.mode);
		presse('[data-rt-type-contour]', E.contour.type);
		presse('[data-rt-format]', E.format);
		$$('[data-rt-format] button', racine).forEach((b) => { b.disabled = b.dataset.valeur === 'jpeg' && false; });
		$$('[data-rt-couleurs] .rt-pastille-couleur', racine).forEach((b) => b.setAttribute('aria-pressed', b.dataset.couleur === E.contour.couleur ? 'true' : 'false'));
		$$('[data-rt-fond] .rt-pastille-couleur', racine).forEach((b) => b.setAttribute('aria-pressed', b.dataset.couleur === E.fond ? 'true' : 'false'));
		$('[data-rt-couleur-nom]').textContent = NOMS_COULEURS[E.contour.couleur] || '';
		$$('[data-rt-source]', racine).forEach((r) => { r.checked = r.value === E.contour.source; });
		$('[data-rt-fond]').hidden = E.mode !== 'adapter';

		// Formats rapides.
		$$('[data-rt-formats] button', racine).forEach((b) => b.setAttribute('aria-pressed', Math.abs(+b.dataset.l - E.l) < 0.5 && Math.abs(+b.dataset.h - E.h) < 0.5 ? 'true' : 'false'));
		majEchelle();

		const p = placement();
		const dpi = dpiEffectif(p);
		const ctl = controles();
		const res = ctl[0];
		const boite = $('[data-rt-dpi]');
		boite.className = 'rt-dpi rt-' + res.etat;
		$('[data-rt-dpi-valeur]').textContent = dpi;
		$('[data-rt-dpi-texte]').textContent = res.valeur;
		$('[data-rt-taille-sortie]').textContent = `${nb(p.sortiePx.l)} × ${nb(p.sortiePx.h)}`;
		$('[data-rt-aide-sortie]').textContent = E.dpi > dpi
			? `Agrandir à ${E.dpi} dpi n'ajoute pas de détail : le contrôle se base sur les pixels d'origine (${dpi} dpi).`
			: `Sortie à ${E.dpi} dpi : le fichier garde ${E.dpi < dpi ? 'moins de pixels que l\'original, sans perte visible à cette taille' : 'les pixels de l\'original'}.`;

		// Contour.
		const c = E.contour;
		$('[data-rt-contour-etat]').textContent = c.actif ? 'Activé' : 'désactivé';
		$('[data-rt-contour-reglages]').classList.toggle('rt-eteint', !c.actif);
		$('[data-rt-forme]').hidden = c.type !== 'forme';
		$('[data-rt-position]').hidden = c.type !== 'rectangle';
		$('[data-rt-type-aide]').textContent = c.type === 'rectangle'
			? 'Trait qui suit les 4 bords de l\'image, à l\'intérieur.'
			: 'Le trait suit le bord du logo vers l\'intérieur, y compris les formes intérieures transparentes. Bords lissés.';
		$('[data-rt-epaisseur-px]').textContent = `≈ ${nb(c.epaisseurMm / 25.4 * dpi)} px à ${dpi} dpi · ${nb(c.epaisseurMm / 25.4 * E.dpi)} px en sortie`;
		const impossible = c.actif && c.type === 'forme' && E.erreurForme;
		$('[data-rt-forme-impossible]').hidden = c.type !== 'forme';
		$('[data-rt-forme-impossible]').classList.toggle('rt-alerte', !!impossible);
		$('[data-rt-forme-message]').textContent = impossible
			? E.erreurForme + ' Utilisez le rectangle intérieur, ou détourez dans votre logiciel et réimportez un PNG.'
			: 'Image sans transparence et fond chargé : l\'outil le signale et propose le rectangle intérieur. Le graphiste peut aussi détourer dans son logiciel et réimporter un PNG.';
		$('[data-rt-action="rectangle"]').hidden = !impossible;

		// Contrôles.
		const bl = ctl.filter((x) => x.etat === 'bloquant');
		const compte = $('[data-rt-compte]');
		compte.hidden = bl.length === 0 && !impossible;
		compte.textContent = bl.length + (impossible ? 1 : 0);
		$('[data-rt-controles-resume]').textContent = `${grandFormat() ? 'Grand format' : 'Petit format'} ${mm(E.l)} × ${mm(E.h)} mm · mode « ${MODES[E.mode]} »`;
		$('[data-rt-controles]').innerHTML = ctl.map((x) => `
			<h3>${x.libelle}</h3>
			<div class="rt-dpi rt-${x.etat}"><strong>${x.grand}<small>${x.unite}</small></strong><p>${x.valeur}</p></div>`).join('')
			+ (impossible ? `<h3>Contour de forme</h3><div class="rt-dpi rt-bloquant"><strong>!</strong><p>${E.erreurForme}</p></div>` : '')
			+ (ctl.every((x) => x.etat !== 'bloquant') && !impossible ? '<p class="rt-tout-bon"><i class="fas fa-check-circle"></i> Rien ne bloque : vous pouvez valider le résultat.</p>' : '');
		$('[data-rt-que-faire]').hidden = bl.length === 0 || bloqueSansRemede();
		$('[data-rt-solution="demander"]').hidden = !bl.some((x) => x.cle === 'resolution');
		$('[data-rt-solution="adapter"]').hidden = !bl.some((x) => x.cle === 'deformation');
		$('[data-rt-raison]').hidden = !E.forcer;

		// Boutons d'en-tête.
		const valider = peutValider();
		$$('[data-rt-action="valider"]').forEach((b) => { b.disabled = !valider; b.hidden = E.etape !== 'reglages'; b.title = valider ? '' : 'Résolvez les contrôles d\'abord (onglet Contrôles).'; });
		$$('[data-rt-action="adapter"]').forEach((b) => { if (!b.closest('[data-rt-que-faire]')) { b.hidden = !(E.etape === 'reglages' && bl.some((x) => x.cle === 'deformation')); } });
		$$('[data-rt-action="modifier"]').forEach((b) => { b.hidden = E.etape !== 'valider'; });
		// En-tête chargé (« Passer en adapter » ou « Modifier ») : « Ouvrir un autre fichier » reste dans la barre.
		const charge = E.etape === 'valider' || bl.some((x) => x.cle === 'deformation');
		$$('.rt-entete .rt-autres').forEach((el) => { el.hidden = charge; });

		// Note sous l'aperçu.
		const note = $('[data-rt-note]');
		note.className = 'rt-note-apercu';
		note.hidden = true;
		if (E.etape === 'reglages') {
			if (E.mode === 'recadrer') {
				const ch = Math.round(p.source.x), cv = Math.round(p.source.y);
				const pc = Math.round((1 - (p.source.l * p.source.h) / (C.source.largeur * C.source.hauteur)) * 100);
				if (ch > 0 || cv > 0) {
					note.hidden = false; note.classList.add('rt-rouge');
					note.textContent = `zones hachurées coupées : ${ch > 0 ? nb(ch) + ' px de chaque côté' : nb(cv) + ' px en haut et en bas'} (${pc} %)`;
				}
			} else if (E.mode === 'etirer' && Math.abs(deformation(p)) > S.deformation * 100) {
				const d = deformation(p);
				note.hidden = false; note.classList.add('rt-rouge');
				note.textContent = `image étirée de ${Math.abs(d)} % en ${d > 0 ? 'largeur' : 'hauteur'} · texte et formes déformés`;
			} else if (E.contour.actif && E.onglet === 'contour') {
				note.hidden = false;
				note.textContent = E.contour.type === 'rectangle' ? 'le trait part du bord et va vers l\'intérieur : rien n\'est ajouté à l\'extérieur' : 'suit la silhouette, vers l\'intérieur';
			}
		}

		// Titre selon l'étape.
		const titre = E.etape === 'valider' ? 'Valider et générer le fichier final'
			: E.onglet === 'contour' ? (E.contour.type === 'forme' ? (C.source.transparence ? 'Contour de forme · logo détouré' : 'Contour de forme') : 'Contour intérieur ' + (NOMS_COULEURS[E.contour.couleur] || ''))
			: E.onglet === 'controles' ? 'Contrôles avant validation' : 'Redimensionner l\'image';
		$$('[data-rt-titre]').forEach((el) => { el.textContent = titre.trim(); });

		majValidation();
	}

	function presse(sel, valeur) {
		$$(sel + ' button', racine).forEach((b) => b.setAttribute('aria-pressed', b.dataset.valeur === valeur ? 'true' : 'false'));
	}

	function nomFichier() {
		if (E.nomModifie && E.nom) { return E.nom; }
		const n = (v) => String(Math.round(v * 10) / 10);
		return `${(C.commande.numero || 'retouche').replace(/[^\w-]+/g, '-')}_${n(E.l)}x${n(E.h)}_v${C.version}.${E.format === 'jpeg' ? 'jpg' : E.format}`;
	}

	/* Étape « Valider et générer » (PP05). */
	function majValidation() {
		if (E.etape !== 'valider') { return; }
		const p = placement();
		const ctl = controles();
		const res = ctl[0];
		const deform = E.mode === 'etirer' ? deformation(p) : 0;
		const ok = (t) => `<i class="fas fa-${t === 'ok' ? 'check-circle rt-ok' : (t === 'attention' ? 'exclamation-circle rt-attention' : 'exclamation-triangle rt-bloquant')}"></i>`;
		const etatGen = E.genere ? 'ok' : 'attention';
		const lignes = [
			['ok', 'Dimensions appliquées', `${mm(E.l)} × ${mm(E.h)} mm${E.fondPerdu ? ' + 5 mm de fond perdu' : ''}`],
			E.mode === 'etirer' && Math.abs(deform) > S.deformation * 100
				? ['bloquant', 'Déformation volontaire', `${deform > 0 ? '+' : '−'}${Math.abs(deform)} % · validé avec une raison`]
				: ['ok', 'Proportions conservées', E.mode === 'recadrer' ? `recadrage ${Math.round((1 - (p.source.l * p.source.h) / (C.source.largeur * C.source.hauteur)) * 100)} %` : (E.mode === 'adapter' ? 'image entière, avec marges' : 'pas de déformation visible')],
		];
		if (E.contour.actif) {
			lignes.push(['ok', E.contour.type === 'rectangle' ? 'Contour intérieur visible et net' : 'Contour de forme', `${mm(E.contour.epaisseurMm)} mm · ${NOMS_COULEURS[E.contour.couleur] || E.contour.couleur} · à l'intérieur`]);
		}
		lignes.push([res.etat, 'Résolution', `${res.grand} dpi · ${res.etat === 'ok' ? 'OK pour ' + (grandFormat() ? 'ce grand format' : 'ce petit format') : (res.etat === 'attention' ? 'un peu juste' : 'trop faible · validé avec une raison')}`]);
		lignes.push([etatGen, 'Fichier lisible', E.genere ? `${E.format.toUpperCase()} ouvert et vérifié` : 'vérifié par le serveur à la génération']);
		lignes.push(['ok', 'Original conservé', C.source.nom]);
		$('[data-rt-liste-verifs]').innerHTML = lignes.map(([t, l, d]) => `<li>${ok(t)}<span><strong>${l}</strong><small>${echapper(d)}</small></span></li>`).join('');
		$('[data-rt-dossier-nom]').textContent = E.genere ? E.genere.nom : nomFichier();
		$('[data-rt-dossier-etat]').textContent = E.genere ? 'généré · rangé dans la commande' : 'à générer';

		$('[data-rt-format-aide]').textContent = {
			pdf: `PDF : taille physique ${mm(p.L)} × ${mm(p.H)} mm enregistrée dans le fichier${E.fondPerdu ? ' (format fini en TrimBox)' : ''}, prêt pour le RIP.`,
			png: `PNG : sans perte${C.source.transparence ? ', transparence gardée' : ''}, ${E.dpi} dpi enregistrés dans le fichier.`,
			jpeg: `JPEG : plus léger, qualité 92, ${E.dpi} dpi enregistrés ; la transparence devient blanche.`,
		}[E.format];
		const contour = E.contour.actif ? `${E.contour.type === 'rectangle' ? 'rectangle' : 'forme'} · ${NOMS_COULEURS[E.contour.couleur] || E.contour.couleur} · ${mm(E.contour.epaisseurMm)} mm` : 'aucun';
		$('[data-rt-recap]').innerHTML = [
			['Dimensions', `${mm(E.l)} × ${mm(E.h)} mm`],
			['Mode', MODES[E.mode]],
			['Sortie', `${E.dpi} dpi · ${nb(p.sortiePx.l)} × ${nb(p.sortiePx.h)} px`],
			['Contour', contour],
			['Couleurs', C.source.cmjn ? 'CMJN reçu, enregistré en RVB · à convertir au RIP' : 'RVB · à convertir au RIP'],
		].concat(E.forcer && bloquants().length ? [['Validé malgré', E.raison]] : []).map(([a, b]) => `<dt>${a}</dt><dd>${echapper(b)}</dd>`).join('');
	}

	function echapper(t) {
		return String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]));
	}

	/* ------------------------------------------------------------------
	 * Rendu général
	 * ------------------------------------------------------------------ */
	let prevu = false;
	function rendre() {
		if (prevu) { return; }
		prevu = true;
		requestAnimationFrame(() => {
			prevu = false;
			const grille = $('.rt-grille');
			grille.dataset.rtEtape = E.etape;
			$('[data-rt-reglages]').hidden = E.etape !== 'reglages';
			$('[data-rt-verifs]').hidden = E.etape !== 'valider';
			$('[data-rt-final]').hidden = E.etape !== 'valider';
			$$('[data-rt-onglet]', racine).forEach((b) => b.setAttribute('aria-selected', b.dataset.rtOnglet === E.onglet ? 'true' : 'false'));
			$$('[data-rt-volet]', racine).forEach((v) => { v.hidden = v.dataset.rtVolet !== E.onglet; });
			if (apercu) {
				dessinerOriginal();
				dessinerResultat();
			}
			majChamps();
		});
	}

	/* ------------------------------------------------------------------
	 * Événements
	 * ------------------------------------------------------------------ */
	function changerDimension(quoi, valeur) {
		const v = depuisUnite(parseFloat(String(valeur).replace(',', '.')));
		if (!(v > 0)) { return; }
		const ratio = E.l / E.h;
		if (quoi === 'largeur') {
			E.l = Math.round(v * 10) / 10;
			if (E.proportions) { E.h = Math.round(E.l / ratio * 10) / 10; }
		} else {
			E.h = Math.round(v * 10) / 10;
			if (E.proportions) { E.l = Math.round(E.h * ratio * 10) / 10; }
		}
		suivreDpi();
		rendre();
	}

	racine.addEventListener('input', (ev) => {
		const n = ev.target.dataset.rtChamp;
		if (!n) { return; }
		const v = ev.target.value;
		switch (n) {
			case 'largeur': case 'hauteur': changerDimension(n, v); return;
			case 'dpi': { const d = parseInt(v, 10); if (d >= 30 && d <= 1200) { E.dpi = d; E.dpiModifie = true; } break; }
			case 'epaisseur': { const e = parseFloat(v.replace(',', '.')); if (e >= 0.1 && e <= 50) { E.contour.epaisseurMm = Math.round(e * 100) / 100; } break; }
			case 'epaisseurCurseur': E.contour.epaisseurMm = parseFloat(v); break;
			case 'retrait': { const r = parseFloat(v.replace(',', '.')); if (r >= 0 && r <= 500) { E.contour.retraitMm = r; } break; }
			case 'couleur': if (/^#[0-9a-fA-F]{6}$/.test(v)) { E.contour.couleur = v.toUpperCase(); } break;
			case 'fond': E.fond = v.toUpperCase(); break;
			case 'raison': E.raison = v; break;
			case 'nom': E.nom = v; E.nomModifie = v.trim() !== ''; break;
			default: return;
		}
		rendre();
	});
	racine.addEventListener('change', (ev) => {
		const n = ev.target.dataset.rtChamp;
		if (n === 'proportions') { E.proportions = ev.target.checked; }
		else if (n === 'fondPerdu') { E.fondPerdu = ev.target.checked; }
		else if (n === 'contour') { E.contour.actif = ev.target.checked; }
		else if (ev.target.matches('[data-rt-source]')) { E.contour.source = ev.target.value; }
		else { return; }
		rendre();
	});

	document.addEventListener('click', (ev) => {
		const b = ev.target.closest('button, [data-rt-action]');
		if (!b || !(racine.contains(b) || b.closest('.rt-entete'))) { return; }
		const groupe = b.parentElement;
		if (groupe && groupe.matches('[data-rt-unite]')) { E.unite = b.dataset.valeur; return rendre(); }
		if (groupe && groupe.matches('[data-rt-modes]')) { E.mode = b.dataset.valeur; return rendre(); }
		if (groupe && groupe.matches('[data-rt-type-contour]')) { E.contour.type = b.dataset.valeur; E.contour.actif = true; return rendre(); }
		if (groupe && groupe.matches('[data-rt-format]')) { E.format = b.dataset.valeur; if (E.nomModifie) { E.nom = E.nom.replace(/\.(pdf|png|jpe?g)$/i, '') + '.' + (E.format === 'jpeg' ? 'jpg' : E.format); } return rendre(); }
		if (groupe && groupe.matches('[data-rt-formats]')) { E.l = +b.dataset.l; E.h = +b.dataset.h; suivreDpi(); return rendre(); }
		if (groupe && groupe.matches('[data-rt-echelles]')) { E.l = Math.round(R.largeurMm * +b.dataset.k * 10) / 10; E.h = Math.round(R.hauteurMm * +b.dataset.k * 10) / 10; suivreDpi(); return rendre(); }
		if (b.matches('[data-rt-onglet]')) { E.onglet = b.dataset.rtOnglet; return rendre(); }
		if (b.matches('[data-rt-cadenas]')) { E.proportions = !E.proportions; return rendre(); }
		if (b.closest('[data-rt-couleurs]') && b.dataset.couleur) { E.contour.couleur = b.dataset.couleur; return rendre(); }
		if (b.closest('[data-rt-fond]') && b.dataset.couleur) { E.fond = b.dataset.couleur; return rendre(); }
		switch (b.dataset.rtAction) {
			case 'reinitialiser': E = etatInitial(); cacheForme = { cle: null }; $('[data-rt-resultat]').hidden = true; return rendre();
			case 'adapter': E.mode = 'adapter'; return rendre();
			case 'rectangle': E.contour.type = 'rectangle'; return rendre();
			case 'forcer': E.forcer = !E.forcer; rendre(); setTimeout(() => { const t = $('[data-rt-champ="raison"]'); if (E.forcer && t) { t.focus(); } }, 50); return;
			case 'valider':
				if (!peutValider()) { E.onglet = 'controles'; return rendre(); }
				E.etape = 'valider'; return rendre();
			case 'modifier':
				E.etape = 'reglages';
				if (E.genere) {
					E.genere = null; E.nomModifie = false;
					$$('[data-rt-action="generer"], [data-rt-action="bat"]', racine).forEach((x) => { x.disabled = false; });
					$('[data-rt-resultat]').hidden = true;
					$('[data-rt-dossier-nouveau] em').textContent = 'v' + C.version;
					$('[data-rt-dossier-nouveau]').hidden = false;
					$$('[data-rt-action="bat"]', racine).forEach((x) => { x.textContent = 'Générer puis envoyer comme BAT n°' + C.bat; });
				}
				return rendre();
			case 'demander': return ouvrirDialogue();
			case 'envoyer-message': return envoyerMessage(b);
			case 'generer': return generer('associer', b);
			case 'bat': return generer('bat', b);
			default:
		}
	});

	/* ------------------------------------------------------------------
	 * Serveur
	 * ------------------------------------------------------------------ */
	async function poster(url, corps) {
		const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(Object.assign({ _token: C.jeton }, corps)), credentials: 'same-origin' });
		let d = {};
		try { d = await r.json(); } catch (e) { d = { message: 'Réponse inattendue du serveur (' + r.status + ').' }; }
		return { ok: r.ok, d };
	}

	function reglagesPourServeur() {
		return {
			largeurMm: E.l, hauteurMm: E.h, mode: E.mode, dpiSortie: E.dpi, fondPerdu: E.fondPerdu, fond: E.fond,
			contour: Object.assign({}, E.contour),
		};
	}

	async function generer(action, bouton) {
		const boutons = $$('[data-rt-action="generer"], [data-rt-action="bat"]', racine);
		boutons.forEach((x) => { x.disabled = true; });
		const texte = bouton.textContent;
		bouton.textContent = 'Génération en cours…';
		$('[data-rt-dossier-etat]').textContent = 'en cours de génération';
		const zone = $('[data-rt-resultat]');
		zone.hidden = true;
		try {
			const { ok, d } = await poster(C.urls.generer, { fichier: C.source.id, reglages: reglagesPourServeur(), format: E.format, nom: E.nomModifie ? E.nom : nomFichier(), raison: E.forcer ? E.raison : '', action });
			zone.hidden = false;
			if (!ok) {
				zone.className = 'rt-resultat rt-erreur';
				zone.textContent = d.message || 'La génération a échoué.';
				$('[data-rt-dossier-etat]').textContent = 'à générer';
				boutons.forEach((x) => { x.disabled = false; });
				return;
			}
			E.genere = d.fichier;
			// La version suivante (si on modifie et régénère) prend le numéro d'après.
			C.version = d.fichier.version + 1;
			if (action === 'bat') { C.bat += 1; }
			const li = document.createElement('li');
			li.innerHTML = `<span><strong>${echapper(d.fichier.nom)}</strong><small>${action === 'bat' ? 'BAT n°' + (C.bat - 1) + ' · envoyé' : 'généré à l\'instant'}</small></span><em>v${d.fichier.version}</em>`;
			$('[data-rt-dossier-nouveau]').before(li);
			$('[data-rt-dossier-nouveau]').hidden = true;
			zone.className = 'rt-resultat rt-succes';
			zone.innerHTML = `<p><i class="fas fa-check-circle"></i> ${echapper(d.message)}</p>
				<div><a class="btn btn-outline-success btn-sm" href="${d.fichier.url}">Télécharger</a>
				<a class="btn btn-outline-success btn-sm" href="${d.retour}">Voir dans la fiche prépresse</a>
				${d.whatsapp ? `<a class="btn btn-success btn-sm" href="${d.whatsapp}" target="_blank" rel="noopener"><i class="fab fa-whatsapp me-1"></i>Prévenir par WhatsApp</a>` : ''}</div>`;
			rendre();
		} catch (e) {
			zone.hidden = false;
			zone.className = 'rt-resultat rt-erreur';
			zone.textContent = 'Connexion perdue pendant la génération : vérifiez dans la fiche prépresse avant de recommencer.';
			boutons.forEach((x) => { x.disabled = false; });
		} finally {
			bouton.textContent = texte;
		}
	}

	function ouvrirDialogue() {
		const dlg = $('[data-rt-dialogue]');
		$('[data-rt-champ="message"]', dlg).value = C.messageClient;
		$('[data-rt-dialogue-erreur]', dlg).hidden = true;
		if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}
	async function envoyerMessage(bouton) {
		const dlg = $('[data-rt-dialogue]');
		bouton.disabled = true;
		const { ok, d } = await poster(C.urls.demander, { texte: $('[data-rt-champ="message"]', dlg).value });
		bouton.disabled = false;
		if (!ok) {
			const err = $('[data-rt-dialogue-erreur]', dlg);
			err.hidden = false; err.textContent = d.message || 'Envoi impossible.';
			return;
		}
		dlg.close ? dlg.close() : dlg.removeAttribute('open');
		toast(d.message);
	}
	function toast(texte) {
		const t = $('[data-rt-toast]');
		t.textContent = texte; t.hidden = false;
		clearTimeout(toast.minuteur);
		toast.minuteur = setTimeout(() => { t.hidden = true; }, 6000);
	}

	/* ------------------------------------------------------------------
	 * Démarrage
	 * ------------------------------------------------------------------ */
	$('[data-rt-formats]').innerHTML = C.formats.map((f) => `<button type="button" data-l="${f.largeurMm}" data-h="${f.hauteurMm}" aria-pressed="false">${echapper(f.libelle)}</button>`).join('');
	$('[data-rt-recu]').textContent = `Taille du fichier : ${cmTexte(R.largeurMm, R.hauteurMm)} ` + (R.declare ? `à ${R.dpi} dpi.` : `(pas de résolution dans le fichier : lue à ${R.dpi} dpi).`) + ' Agrandir selon l\'échelle du fichier :';
	$('[data-rt-echelles]').innerHTML = [1, ...R.echelles.slice().reverse()].map((k) => `<button type="button" data-k="${k}" aria-pressed="false" title="${cmTexte(R.largeurMm * k, R.hauteurMm * k)}">${k === 1 ? 'Taille réelle' : `1/${k} → ×${k}`}</button>`).join('');
	window.addEventListener('resize', () => rendre());

	(async function charger() {
		try {
			const r = await fetch(C.source.url, { credentials: 'same-origin' });
			if (!r.ok) { throw new Error(String(r.status)); }
			const blob = await r.blob();
			image = await createImageBitmap(blob, { imageOrientation: 'from-image' });
			const max = 1400;
			facteurApercu = Math.min(1, max / Math.max(image.width, image.height));
			apercu = facteurApercu < 1
				? await createImageBitmap(image, { resizeWidth: Math.round(image.width * facteurApercu), resizeHeight: Math.round(image.height * facteurApercu), resizeQuality: 'high' })
				: image;
			// Les dimensions lues par le serveur font foi ; l'aperçu suit leur rapport.
			facteurApercu = apercu.width / C.source.largeur;
			$('[data-rt-chargement]').hidden = true;
		} catch (e) {
			$('[data-rt-chargement]').textContent = 'Impossible d\'afficher l\'image dans le navigateur. La génération par le serveur reste possible.';
		}
		rendre();
	})();
	rendre();
})();
