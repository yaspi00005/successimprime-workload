/*
 * Convertir un PDF en image, sur l'écran de retouche prépresse
 * (docs/prepresse-retouche.md, « Convertir un PDF en image »).
 *
 * La page est rendue dans le navigateur avec pdf.js (déjà servi pour
 * l'outil de montage) : GD ne lit pas les PDF et Imagick n'est pas
 * garanti sur les serveurs. La taille de la page vient de sa TrimBox,
 * sinon de sa MediaBox (lues avec pdf-lib). L'image est soit téléchargée,
 * soit envoyée au serveur (RetoucheImageController::convertirPdf), qui la
 * vérifie et la range en nouvelle version de la ligne ; la retouche
 * s'ouvre alors sur elle. Le PDF n'est jamais modifié.
 */
(function () {
	'use strict';

	const dlg = document.querySelector('[data-pdf-image]');
	if (!dlg || !dlg.dataset.config) {
		return;
	}
	const C = JSON.parse(dlg.dataset.config);
	const S = C.seuils;
	const $ = (sel) => dlg.querySelector(sel);
	const nb = (v, d = 0) => Number(v).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
	const mm = (v) => nb(v, Math.abs(v - Math.round(v)) < 0.05 ? 0 : 1);
	const cm = (l, h) => `${mm(l / 10)} × ${mm(h / 10)} cm`;
	const PT_MM = 25.4 / 72;

	const E = {
		pdf: null, // fiche du PDF (id, nom, url…)
		doc: null, // document pdf.js
		lib: null, // document pdf-lib (boîtes de page), null s'il est illisible
		page: 1,
		pages: 1,
		boites: null, // { trim, bleed, mediaSeule } en points PDF
		fondPerdu: false,
		echelle: 1,
		echelleAuto: 1,
		dpi: 300,
		dpiModifie: false,
		format: 'png',
		formatModifie: false,
		nom: '',
		nomModifie: false,
		occupe: false,
		jetonApercu: 0,
	};

	/* ------------------------------------------------------------------
	 * Bibliothèques (chargées à la première ouverture)
	 * ------------------------------------------------------------------ */
	let pdfjsP = null;
	function pdfjs() {
		if (!pdfjsP) {
			pdfjsP = import(dlg.dataset.pdfjs).then((m) => { m.GlobalWorkerOptions.workerSrc = dlg.dataset.pdfjsWorker; return m; });
		}
		return pdfjsP;
	}
	let pdflibP = null;
	function pdflib() {
		if (!pdflibP) {
			pdflibP = window.PDFLib ? Promise.resolve(window.PDFLib) : new Promise((ok, ko) => {
				const s = document.createElement('script');
				s.src = dlg.dataset.pdflib;
				s.onload = () => ok(window.PDFLib);
				s.onerror = ko;
				document.head.appendChild(s);
			});
		}
		return pdflibP;
	}

	/* ------------------------------------------------------------------
	 * Calculs
	 * ------------------------------------------------------------------ */
	// Même règle que GeometrieRetouche::dpiSortieConseille.
	function dpiConseille(lMm, hMm) {
		const g = Math.max(lMm, hMm);
		return g <= S.petitFormatMaxMm ? 300 : (g <= 2000 ? 150 : 100);
	}

	// Même règle que GeometrieRetouche::echelleDetectee, puis celle d'AnalyseFichierImpression
	// (un PDF dont le grand côté fait moins du cinquième de la commande est au 1/10).
	function echelleDetectee(lMm, hMm) {
		const cl = C.commande.largeurMm, ch = C.commande.hauteurMm;
		if (!cl || !ch || lMm <= 0 || hMm <= 0) {
			return 1;
		}
		const proche = (a, b) => Math.abs(a - b) <= b * S.echelleToleree;
		for (const k of S.echelles) {
			if ((proche(lMm * k, cl) && proche(hMm * k, ch)) || (proche(lMm * k, ch) && proche(hMm * k, cl))) {
				return k;
			}
		}
		return Math.max(lMm, hMm) * 5 < Math.max(cl, ch) ? 10 : 1;
	}

	// Rectangle de la boîte choisie, en pixels, pour une échelle de rendu (pixels par point).
	function zone(pg, echelleRendu) {
		const vp = pg.getViewport({ scale: echelleRendu });
		const b = E.fondPerdu && E.boites.bleed ? E.boites.bleed : E.boites.trim;
		const r = b ? vp.convertToViewportRectangle([b.x, b.y, b.x + b.width, b.y + b.height]) : [0, 0, vp.width, vp.height];
		return { vp, x: Math.min(r[0], r[2]), y: Math.min(r[1], r[3]), l: Math.abs(r[2] - r[0]), h: Math.abs(r[3] - r[1]) };
	}

	// Taille de la page (boîte choisie) en mm, dans le sens d'affichage.
	let tailleMm = { l: 0, h: 0 };

	// Résolution réellement rendue (baissée si le navigateur ne tiendrait pas).
	function sortie() {
		const lPt = tailleMm.l / PT_MM, hPt = tailleMm.h / PT_MM;
		const voulu = E.dpi * E.echelle / 72; // pixels par point
		const max = Math.min(S.coteMaxPx / Math.max(lPt, hPt), Math.sqrt(S.pixelsMax / (lPt * hPt)));
		let dpi = E.dpi, limite = false;
		if (voulu > max) {
			dpi = Math.max(1, Math.floor(max * 72 / E.echelle));
			limite = true;
		}
		const r = dpi * E.echelle / 72;
		return { dpi, limite, echelleRendu: r, l: Math.max(1, Math.round(lPt * r)), h: Math.max(1, Math.round(hPt * r)) };
	}

	function nomParDefaut(dpi) {
		let base = (E.pdf.nom || '').trim().replace(/\.pdf$/i, '').replace(/[^\p{L}\p{N}_-]+/gu, '-').replace(/^[-_]+|[-_]+$/g, '');
		base = (base || 'conversion').slice(0, 150);
		return `${base}${E.pages > 1 ? '_p' + E.page : ''}_${dpi}dpi.${E.format === 'png' ? 'png' : 'jpg'}`;
	}

	/* ------------------------------------------------------------------
	 * Affichage
	 * ------------------------------------------------------------------ */
	function afficher() {
		const s = sortie();
		const imprime = { l: tailleMm.l * E.echelle, h: tailleMm.h * E.echelle };
		const boite = E.fondPerdu && E.boites.bleed ? 'avec le fond perdu' : (E.boites.trimDeclaree ? 'format fini (TrimBox)' : (E.lib ? 'page entière (MediaBox)' : 'page'));
		$('[data-pi-taille]').innerHTML = `<strong>${cm(tailleMm.l, tailleMm.h)}</strong> · ${boite}`
			+ (E.echelle > 1 ? `<small>PDF au 1/${E.echelle} : imprimé à ${cm(imprime.l, imprime.h)}${E.echelleAuto === E.echelle ? ' (reconnu d\'après la commande)' : ''}.</small>` : '')
			+ (C.commande.largeurMm ? `<small>Commande : ${cm(C.commande.largeurMm, C.commande.hauteurMm)}.</small>` : '');
		$('[data-pi-bloc-page]').hidden = E.pages < 2;
		$('[data-pi-champ="page"]').value = E.page;
		$('[data-pi-champ="page"]').max = E.pages;
		$('[data-pi-pages]').textContent = `sur ${E.pages}`;
		$('[data-pi-bloc-fond-perdu]').hidden = !E.boites.bleed;
		$('[data-pi-champ="fondPerdu"]').checked = E.fondPerdu;
		$('[data-pi-fond-perdu-texte]').textContent = E.boites.bleed ? `Page rendue à ${cm(E.boites.bleed.width * PT_MM, E.boites.bleed.height * PT_MM)} au lieu du format fini de ${cm(E.boites.trim.width * PT_MM, E.boites.trim.height * PT_MM)}.` : '';
		$('[data-pi-action="page-moins"]').disabled = E.occupe || E.page <= 1;
		$('[data-pi-action="page-plus"]').disabled = E.occupe || E.page >= E.pages;
		$('[data-pi-echelles]').innerHTML = [1, ...S.echelles.slice().reverse()].map((k) => `<button type="button" data-k="${k}" aria-pressed="${k === E.echelle}" title="${cm(tailleMm.l * k, tailleMm.h * k)}">${k === 1 ? 'Taille réelle' : `1/${k} → ×${k}`}</button>`).join('');
		$('[data-pi-bloc-echelle]').hidden = false;

		const dpiChamp = $('[data-pi-champ="dpi"]');
		if (document.activeElement !== dpiChamp) {
			dpiChamp.value = E.dpi;
		}
		dpiChamp.min = S.dpiMin;
		dpiChamp.max = S.dpiMax;
		$('[data-pi-pixels]').textContent = `${nb(s.l)} × ${nb(s.h)}`;
		const conseil = dpiConseille(imprime.l, imprime.h);
		$('[data-pi-dpi-aide]').textContent = `À la taille d'impression : ${nb(s.l * s.h / 1e6, 1)} millions de pixels. Conseillé pour ${cm(imprime.l, imprime.h)} : ${conseil} dpi`
			+ (E.echelle > 1 ? ` (soit ${conseil * E.echelle} dpi sur la page du PDF au 1/${E.echelle}).` : '.');
		const limite = $('[data-pi-limite]');
		limite.hidden = !s.limite;
		limite.textContent = s.limite ? `Résolution baissée à ${s.dpi} dpi : au-delà de ${nb(S.coteMaxPx)} px de côté ou ${nb(S.pixelsMax / 1e6)} millions de pixels, le navigateur ne tiendrait pas.` : '';

		for (const b of dlg.querySelectorAll('[data-pi-format] button')) {
			b.setAttribute('aria-pressed', String(b.dataset.valeur === E.format));
		}
		$('[data-pi-format-aide]').textContent = E.format === 'png'
			? 'Sans perte, conseillé pour un logo, du texte, un trait. Plus lourd.'
			: 'Plus léger, conseillé pour une photo ou une grande affiche (qualité 92).';
		const nom = $('[data-pi-champ="nom"]');
		if (!E.nomModifie) {
			E.nom = nomParDefaut(s.dpi);
			nom.value = E.nom;
		}
		for (const b of dlg.querySelectorAll('[data-pi-action="convertir"], [data-pi-action="telecharger"]')) {
			b.disabled = E.occupe || !E.doc;
		}
	}

	function etat(texte, succes) {
		const p = $('[data-pi-etat]');
		p.hidden = !texte;
		p.textContent = texte || '';
		p.classList.toggle('pi-succes', !!succes);
	}
	function erreur(texte) {
		const p = $('[data-pi-erreur]');
		p.hidden = !texte;
		p.textContent = texte || '';
	}

	async function apercu() {
		const jeton = ++E.jetonApercu;
		const canvas = $('[data-pi-apercu]');
		const pg = await E.doc.getPage(E.page);
		const z1 = zone(pg, 1);
		const r = Math.min(4, 900 / Math.max(z1.l, z1.h));
		const z = zone(pg, r);
		const c = document.createElement('canvas');
		c.width = Math.max(1, Math.round(z.l));
		c.height = Math.max(1, Math.round(z.h));
		await pg.render({ canvasContext: c.getContext('2d'), viewport: z.vp, transform: [1, 0, 0, 1, -z.x, -z.y], background: '#ffffff' }).promise;
		if (jeton !== E.jetonApercu) {
			return;
		}
		canvas.width = c.width;
		canvas.height = c.height;
		canvas.getContext('2d').drawImage(c, 0, 0);
		$('[data-pi-chargement]').hidden = true;
	}

	/* ------------------------------------------------------------------
	 * Ouvrir un PDF, choisir la page
	 * ------------------------------------------------------------------ */
	async function boitesDeLaPage() {
		E.boites = { trim: null, bleed: null, trimDeclaree: false };
		const pg = await E.doc.getPage(E.page);
		const [vx0, vy0, vx1, vy1] = pg.view;
		const vue = { x: vx0, y: vy0, width: vx1 - vx0, height: vy1 - vy0 };
		let trim = null, media = null, bleed = null;
		if (E.lib) {
			try {
				const p = E.lib.getPage(E.page - 1);
				media = p.getMediaBox();
				trim = p.node.TrimBox() ? p.getTrimBox() : null;
				bleed = p.node.BleedBox() ? p.getBleedBox() : media;
			} catch (e) {
				trim = null;
			}
		}
		E.boites.trimDeclaree = !!trim && trim.width > 0 && trim.height > 0;
		E.boites.trim = E.boites.trimDeclaree ? trim : (media && media.width > 0 ? media : vue);
		// Fond perdu : seulement quand la page déborde du format fini.
		if (E.boites.trimDeclaree && bleed && (bleed.width > trim.width + 0.5 || bleed.height > trim.height + 0.5)) {
			E.boites.bleed = bleed;
		}
		if (!E.boites.bleed) {
			E.fondPerdu = false;
		}
		const z = zone(pg, 1);
		tailleMm = { l: z.l * PT_MM, h: z.h * PT_MM };
	}

	async function choisirPage(n, garderEchelle) {
		E.page = Math.min(E.pages, Math.max(1, Math.round(n) || 1));
		await boitesDeLaPage();
		E.echelleAuto = echelleDetectee(tailleMm.l, tailleMm.h);
		if (!garderEchelle) {
			E.echelle = E.echelleAuto;
		}
		dpiParDefaut();
		afficher();
		await apercu();
	}

	function dpiParDefaut() {
		if (!E.dpiModifie) {
			E.dpi = dpiConseille(tailleMm.l * E.echelle, tailleMm.h * E.echelle);
		}
		if (!E.formatModifie) {
			const s = sortie();
			E.format = s.l * s.h <= 25e6 ? 'png' : 'jpeg';
		}
	}

	async function ouvrir(id) {
		const pdf = C.pdfs.find((p) => p.id === Number(id));
		if (!pdf) {
			return;
		}
		for (const d of document.querySelectorAll('details.rt-autres[open]')) {
			d.open = false;
		}
		Object.assign(E, { pdf, doc: null, lib: null, page: 1, pages: 1, fondPerdu: false, echelle: 1, dpiModifie: false, formatModifie: false, nomModifie: false, occupe: false });
		E.boites = { trim: null, bleed: null, trimDeclaree: false };
		tailleMm = { l: 0, h: 0 };
		$('[data-pi-nom]').textContent = `${pdf.nom} · ${pdf.client ? 'fichier client' : 'version ' + pdf.version}`;
		const canvas = $('[data-pi-apercu]');
		canvas.width = 0;
		canvas.height = 0;
		$('[data-pi-chargement]').hidden = false;
		$('[data-pi-chargement]').textContent = 'Lecture du PDF…';
		erreur('');
		etat('');
		for (const b of dlg.querySelectorAll('[data-pi-action="convertir"], [data-pi-action="telecharger"]')) {
			b.disabled = true;
		}
		if (!dlg.open) {
			dlg.showModal();
		}
		try {
			const r = await fetch(pdf.url, { credentials: 'same-origin' });
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			const octets = await r.arrayBuffer();
			const [lib, PDFLib] = await Promise.all([pdfjs(), pdflib().catch(() => null)]);
			if (E.pdf !== pdf) {
				return;
			}
			if (PDFLib) {
				try {
					E.lib = await PDFLib.PDFDocument.load(octets.slice(0), { ignoreEncryption: true, updateMetadata: false });
				} catch (e) {
					E.lib = null; // boîtes illisibles : la page entière vue par pdf.js
				}
			}
			E.doc = await lib.getDocument({ data: new Uint8Array(octets) }).promise;
			E.pages = E.doc.numPages;
			await choisirPage(1);
		} catch (e) {
			$('[data-pi-chargement]').textContent = '';
			erreur('Impossible de lire ce PDF dans le navigateur (fichier abîmé, protégé par un mot de passe, ou pas encore descendu sur ce serveur). Convertissez-le dans le logiciel du graphiste.');
		}
	}

	/* ------------------------------------------------------------------
	 * Rendu en taille réelle, résolution écrite dans le fichier
	 * ------------------------------------------------------------------ */
	async function rendre() {
		const s = sortie();
		etat(`Rendu de la page ${E.page} à ${s.dpi} dpi (${nb(s.l)} × ${nb(s.h)} px)… Cela peut prendre un moment pour un grand format.`);
		const pg = await E.doc.getPage(E.page);
		const z = zone(pg, s.echelleRendu);
		const c = document.createElement('canvas');
		c.width = s.l;
		c.height = s.h;
		const ctx = c.getContext('2d', { alpha: false });
		if (!ctx || c.width !== s.l || c.height !== s.h) {
			throw new Error('Le navigateur refuse une image de cette taille : baissez la résolution.');
		}
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, c.width, c.height);
		await pg.render({ canvasContext: ctx, viewport: z.vp, transform: [1, 0, 0, 1, -z.x, -z.y], background: '#ffffff' }).promise;
		etat(`Écriture du fichier ${E.format === 'png' ? 'PNG' : 'JPEG'}…`);
		const blob = await new Promise((ok) => c.toBlob(ok, E.format === 'png' ? 'image/png' : 'image/jpeg', 0.92));
		c.width = 0;
		c.height = 0;
		if (!blob) {
			throw new Error('Le navigateur n\'a pas pu écrire une image de cette taille : baissez la résolution ou choisissez JPEG.');
		}
		const octets = new Uint8Array(await blob.arrayBuffer());
		const avecDpi = E.format === 'png' ? pngAvecDpi(octets, s.dpi) : jpegAvecDpi(octets, s.dpi);
		return { blob: new Blob([avecDpi], { type: blob.type }), sortie: s };
	}

	const CRC = (() => {
		const t = new Uint32Array(256);
		for (let n = 0; n < 256; n++) {
			let c = n;
			for (let k = 0; k < 8; k++) {
				c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
			}
			t[n] = c >>> 0;
		}
		return t;
	})();
	function crc32(octets) {
		let c = 0xFFFFFFFF;
		for (let i = 0; i < octets.length; i++) {
			c = CRC[(c ^ octets[i]) & 0xFF] ^ (c >>> 8);
		}
		return (c ^ 0xFFFFFFFF) >>> 0;
	}

	// Ajoute (ou remplace) le bloc pHYs d'un PNG : la résolution, en points par mètre.
	function pngAvecDpi(octets, dpi) {
		const ppm = Math.round(dpi / 0.0254);
		const bloc = new Uint8Array(21);
		const v = new DataView(bloc.buffer);
		v.setUint32(0, 9);
		bloc.set([0x70, 0x48, 0x59, 0x73], 4); // « pHYs »
		v.setUint32(8, ppm);
		v.setUint32(12, ppm);
		bloc[16] = 1; // unité : le mètre
		v.setUint32(17, crc32(bloc.subarray(4, 17)));
		const lecture = new DataView(octets.buffer, octets.byteOffset, octets.byteLength);
		let pos = 8, apresIhdr = 0;
		while (pos + 8 <= octets.length) {
			const longueur = lecture.getUint32(pos);
			const type = String.fromCharCode(octets[pos + 4], octets[pos + 5], octets[pos + 6], octets[pos + 7]);
			if (type === 'IHDR') {
				apresIhdr = pos + 12 + longueur;
			}
			if (type === 'pHYs') {
				const sortie = new Uint8Array(octets.length - (12 + longueur) + 21);
				sortie.set(octets.subarray(0, pos));
				sortie.set(bloc, pos);
				sortie.set(octets.subarray(pos + 12 + longueur), pos + 21);
				return sortie;
			}
			if (type === 'IDAT') {
				break;
			}
			pos += 12 + longueur;
		}
		if (!apresIhdr) {
			return octets;
		}
		const sortie = new Uint8Array(octets.length + 21);
		sortie.set(octets.subarray(0, apresIhdr));
		sortie.set(bloc, apresIhdr);
		sortie.set(octets.subarray(apresIhdr), apresIhdr + 21);
		return sortie;
	}

	// Écrit la résolution dans l'en-tête JFIF d'un JPEG (celui que produisent les navigateurs).
	function jpegAvecDpi(octets, dpi) {
		const jfif = octets[0] === 0xFF && octets[1] === 0xD8 && octets[2] === 0xFF && octets[3] === 0xE0
			&& octets[6] === 0x4A && octets[7] === 0x46 && octets[8] === 0x49 && octets[9] === 0x46 && octets[10] === 0;
		if (jfif) {
			const d = Math.min(65535, dpi);
			octets[13] = 1; // unité : le pouce
			octets[14] = d >> 8; octets[15] = d & 0xFF;
			octets[16] = d >> 8; octets[17] = d & 0xFF;
		}
		return octets;
	}

	function nomFinal() {
		const ext = E.format === 'png' ? 'png' : 'jpg';
		const n = (E.nom || '').trim().replace(/\.(pdf|png|jpe?g)$/i, '');
		return (n || nomParDefaut(sortie().dpi).replace(/\.[a-z]+$/, '')) + '.' + ext;
	}

	async function telecharger() {
		occuper(true);
		erreur('');
		try {
			const { blob } = await rendre();
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = nomFinal();
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(() => URL.revokeObjectURL(url), 60000);
			etat(`Image téléchargée (${nb(blob.size / 1048576, 1)} Mo). Rien n'a été rangé dans la commande.`, true);
		} catch (e) {
			etat('');
			erreur(e.message || 'Conversion impossible dans ce navigateur : baissez la résolution.');
		}
		occuper(false);
	}

	async function convertir() {
		occuper(true);
		erreur('');
		try {
			const { blob, sortie: s } = await rendre();
			if (blob.size > S.tailleMax) {
				throw new Error(`Image trop lourde pour l'envoi (${nb(blob.size / 1048576, 1)} Mo, ${nb(S.tailleMax / 1048576)} Mo au plus sur ce serveur) : baissez la résolution ou choisissez JPEG. « Télécharger l'image » reste possible.`);
			}
			const f = new FormData();
			f.append('_token', C.jeton);
			f.append('pdf', String(E.pdf.id));
			f.append('page', String(E.page));
			f.append('pages', String(E.pages));
			f.append('dpi', String(s.dpi));
			f.append('echelle', String(E.echelle));
			f.append('fondPerdu', E.fondPerdu ? '1' : '0');
			f.append('largeurMm', tailleMm.l.toFixed(1));
			f.append('hauteurMm', tailleMm.h.toFixed(1));
			f.append('nom', nomFinal());
			f.append('image', blob, nomFinal());
			const d = await envoyer(f, (pourcent) => etat(`Envoi au serveur… ${pourcent} % (${nb(blob.size / 1048576, 1)} Mo)`));
			etat(d.message + ' Ouverture de la retouche…', true);
			window.location.href = d.retouche;
			return;
		} catch (e) {
			etat('');
			erreur(e.message || 'Conversion impossible.');
		}
		occuper(false);
	}

	// XMLHttpRequest plutôt que fetch : l'avancement de l'envoi se voit (connexions lentes).
	function envoyer(formulaire, avancement) {
		return new Promise((ok, ko) => {
			const x = new XMLHttpRequest();
			x.open('POST', C.urls.convertir);
			x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			x.setRequestHeader('Accept', 'application/json');
			x.upload.onprogress = (e) => { if (e.lengthComputable) { avancement(Math.round(e.loaded / e.total * 100)); } };
			x.onload = () => {
				let d = null;
				try { d = JSON.parse(x.responseText); } catch (e) { d = null; }
				if (x.status >= 200 && x.status < 300 && d && d.retouche) {
					ok(d);
				} else {
					ko(new Error((d && d.message) || (x.status === 413 ? 'Image trop lourde pour ce serveur : baissez la résolution ou choisissez JPEG.' : x.status === 403 ? 'Envoi refusé : vos droits sur la prépresse ne le permettent pas, ou la page a expiré.' : 'Erreur du serveur (' + x.status + ') : rien n\'a été enregistré.')));
				}
			};
			x.onerror = () => ko(new Error('Connexion interrompue pendant l\'envoi : rien n\'a été enregistré. Réessayez.'));
			x.send(formulaire);
		});
	}

	function occuper(oui) {
		E.occupe = oui;
		for (const el of dlg.querySelectorAll('input, [data-pi-format] button, [data-pi-echelles] button, [data-pi-action="page-moins"], [data-pi-action="page-plus"]')) {
			el.disabled = oui;
		}
		afficher();
	}

	/* ------------------------------------------------------------------
	 * Événements
	 * ------------------------------------------------------------------ */
	document.addEventListener('click', (e) => {
		const b = e.target.closest('[data-pdf-convertir]');
		if (b) {
			e.preventDefault();
			ouvrir(b.dataset.pdfConvertir);
		}
	});
	dlg.addEventListener('click', (e) => {
		const a = e.target.closest('[data-pi-action]');
		const k = e.target.closest('[data-pi-echelles] button');
		const f = e.target.closest('[data-pi-format] button');
		if (a) {
			const action = a.dataset.piAction;
			if (action === 'fermer' && !E.occupe) { dlg.close(); }
			if (!E.doc || E.occupe) { return; }
			if (action === 'page-moins') { choisirPage(E.page - 1, true); }
			if (action === 'page-plus') { choisirPage(E.page + 1, true); }
			if (action === 'telecharger') { telecharger(); }
			if (action === 'convertir') { convertir(); }
		} else if (k && E.doc && !E.occupe) {
			E.echelle = Number(k.dataset.k);
			dpiParDefaut();
			afficher();
		} else if (f && E.doc && !E.occupe) {
			E.format = f.dataset.valeur;
			E.formatModifie = true;
			afficher();
		}
	});
	dlg.addEventListener('cancel', (e) => { if (E.occupe) { e.preventDefault(); } });
	dlg.addEventListener('change', (e) => {
		const champ = e.target.dataset.piChamp;
		if (!E.doc) { return; }
		if (champ === 'page') { choisirPage(Number(e.target.value), true); }
		if (champ === 'fondPerdu') {
			E.fondPerdu = e.target.checked;
			choisirPage(E.page, true);
		}
		if (champ === 'dpi') {
			const v = Math.round(Number(e.target.value));
			E.dpi = Math.min(S.dpiMax, Math.max(S.dpiMin, v || E.dpi));
			E.dpiModifie = true;
			e.target.value = E.dpi;
			dpiParDefaut();
			afficher();
		}
	});
	dlg.addEventListener('input', (e) => {
		const champ = e.target.dataset.piChamp;
		if (champ === 'nom') {
			E.nom = e.target.value;
			E.nomModifie = E.nom.trim() !== '';
		}
		if (champ === 'dpi' && E.doc) {
			const v = Math.round(Number(e.target.value));
			if (v >= S.dpiMin && v <= S.dpiMax) {
				E.dpi = v;
				E.dpiModifie = true;
				afficher();
			}
		}
	});

	if (C.ouvrir) {
		ouvrir(C.ouvrir);
	}
})();
