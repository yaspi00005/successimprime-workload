/*
 * Imposition au rouleau (maquette PD02, docs/production-rouleau.md) :
 * placement manuel (glisser, flèches, R pour tourner ; le serveur recontrôle)
 * et « Envoyer au RIP » : un seul PDF monté ici avec pdf-lib (pièces à leur
 * place à l'échelle 1:1, repères de coupe, nom de la commande dans la
 * marge), envoyé par morceaux. Sans bibliothèque externe autre que pdf-lib.
 */
(function () {
	'use strict';

	var configEl = document.getElementById('si-fr-config');
	if (!configEl) { return; }
	var C = JSON.parse(configEl.textContent);
	var plan = document.querySelector('[data-fr-plan]');
	var svg = plan ? plan.querySelector('svg') : null;
	var barre = document.querySelector('[data-fr-barre]');
	var message = document.querySelector('[data-fr-message]');
	var manuel = false;
	var modifie = false;

	/* Fenêtre de confirmation de l'application (public/js/confirmation.js), jamais la boîte du navigateur. */
	function confirmer(question) {
		return window.SiConfirmation ? window.SiConfirmation.demander(question) : Promise.resolve(true);
	}

	/* ---------------- Placement manuel ---------------- */

	function pieces() { return svg ? Array.prototype.slice.call(svg.querySelectorAll('g.piece')) : []; }

	function position(g) {
		var m = /translate\(([-\d.]+)[ ,]+([-\d.]+)\)/.exec(g.getAttribute('transform') || '');
		return {x: m ? parseFloat(m[1]) : 0, y: m ? parseFloat(m[2]) : 0, l: parseFloat(g.dataset.l), h: parseFloat(g.dataset.h)};
	}

	function placer(g, x, y) {
		var p = position(g);
		x = Math.round(Math.min(Math.max(x, C.marge), C.laize - C.marge - p.l));
		y = Math.round(Math.max(0, y));
		g.setAttribute('transform', 'translate(' + x + ' ' + y + ')');
		modifie = true;
		verifier();
	}

	function verifier() {
		var liste = pieces().map(function (g) { return {g: g, p: position(g)}; });
		liste.forEach(function (a) { a.g.classList.remove('conflit'); });
		var conflits = 0;
		for (var i = 0; i < liste.length; i++) {
			for (var j = i + 1; j < liste.length; j++) {
				var a = liste[i].p, b = liste[j].p;
				if (a.x < b.x + b.l - 0.01 && b.x < a.x + a.l - 0.01 && a.y < b.y + b.h - 0.01 && b.y < a.y + a.h - 0.01) {
					liste[i].g.classList.add('conflit');
					liste[j].g.classList.add('conflit');
					conflits++;
				}
			}
		}
		if (message) { message.textContent = conflits ? conflits + ' chevauchement' + (conflits > 1 ? 's' : '') + ' : écartez les pièces en rouge.' : ''; }
		var max = 0;
		liste.forEach(function (a) { max = Math.max(max, a.p.y + a.p.h); });
		var vb = svg.viewBox.baseVal;
		if (max + 40 > vb.height) { svg.setAttribute('viewBox', '-2 -2 ' + (C.laize + 4) + ' ' + (max + 44)); }
	}

	function tourner(g) {
		var p = position(g);
		g.dataset.l = String(p.h);
		g.dataset.h = String(p.l);
		g.dataset.tourne = g.dataset.tourne === '1' ? '0' : '1';
		var rects = g.querySelectorAll('rect');
		rects.forEach(function (r) { r.setAttribute('width', p.h); r.setAttribute('height', p.l); });
		// Textes recentrés comme dans le gabarit : la taille du premier sert de base.
		var textes = g.querySelectorAll('text');
		var fs = textes.length ? parseFloat(textes[0].getAttribute('font-size')) : 0;
		textes.forEach(function (t, i) {
			t.setAttribute('x', p.h / 2);
			t.setAttribute('y', p.l / 2 + (i === 0 ? -0.3 : 0.9) * fs);
		});
		placer(g, p.x, p.y);
	}

	function pointSvg(evt) {
		var pt = svg.createSVGPoint();
		pt.x = evt.clientX;
		pt.y = evt.clientY;
		return pt.matrixTransform(svg.getScreenCTM().inverse());
	}

	function activerManuel() {
		manuel = true;
		plan.classList.add('manuel');
		if (barre) { barre.classList.add('visible'); }
		pieces().forEach(function (g) { g.setAttribute('tabindex', '0'); });
	}

	if (svg) {
		var glisse = null;
		svg.addEventListener('pointerdown', function (evt) {
			if (!manuel) { return; }
			var g = evt.target.closest('g.piece');
			if (!g) { return; }
			evt.preventDefault();
			g.focus({preventScroll: true});
			var p = position(g), s = pointSvg(evt);
			glisse = {g: g, dx: s.x - p.x, dy: s.y - p.y};
			svg.setPointerCapture(evt.pointerId);
		});
		svg.addEventListener('pointermove', function (evt) {
			if (!glisse) { return; }
			var s = pointSvg(evt);
			placer(glisse.g, s.x - glisse.dx, s.y - glisse.dy);
		});
		['pointerup', 'pointercancel'].forEach(function (nom) {
			svg.addEventListener(nom, function () { glisse = null; });
		});
		svg.addEventListener('keydown', function (evt) {
			if (!manuel) { return; }
			var g = evt.target.closest('g.piece');
			if (!g) { return; }
			var pas = evt.shiftKey ? 10 : 1, p = position(g);
			var d = {ArrowLeft: [-pas, 0], ArrowRight: [pas, 0], ArrowUp: [0, -pas], ArrowDown: [0, pas]}[evt.key];
			if (d) {
				evt.preventDefault();
				placer(g, p.x + d[0], p.y + d[1]);
			} else if (evt.key === 'r' || evt.key === 'R') {
				evt.preventDefault();
				tourner(g);
			}
		});
	}

	document.querySelectorAll('[data-fr-mode]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (b.dataset.frMode === 'manuel') {
				document.querySelectorAll('[data-fr-mode]').forEach(function (x) { x.classList.toggle('actif', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				activerManuel();
			} else if (manuel || C.mode === 'manuel') {
				var revenir = function () { modifie = false; document.querySelector('[data-fr-auto]').submit(); };
				if (!modifie) { revenir(); return; }
				confirmer({message: 'Revenir au placement automatique ?', detail: 'Les déplacements non enregistrés sont perdus.', bouton: 'Revenir à l\'automatique'}).then(function (ok) { if (ok) { revenir(); } });
			}
		});
	});
	if (C.mode === 'manuel') { activerManuel(); }

	var enregistrer = document.querySelector('[data-fr-enregistrer]');
	if (enregistrer) {
		enregistrer.addEventListener('click', function () {
			var positions = {};
			pieces().forEach(function (g) {
				var p = position(g);
				positions[g.dataset.cle] = {x: p.x, y: p.y, tourne: g.dataset.tourne === '1'};
			});
			enregistrer.disabled = true;
			fetch(C.manuel, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.jeton}, body: JSON.stringify({positions: positions}), credentials: 'same-origin'})
				.then(function (r) { return r.json().then(function (d) { return {ok: r.ok, d: d}; }); })
				.then(function (res) {
					if (res.ok && res.d.url) { modifie = false; window.location.href = res.d.url; return; }
					if (message) { message.textContent = res.d.message || 'Placement refusé.'; }
					enregistrer.disabled = false;
				})
				.catch(function () { if (message) { message.textContent = 'Le serveur ne répond pas : réessayez.'; } enregistrer.disabled = false; });
		});
	}
	window.addEventListener('beforeunload', function (evt) { if (modifie) { evt.preventDefault(); evt.returnValue = ''; } });

	/* ---------------- Envoyer au RIP ---------------- */

	var CM = 72 / 2.54; // points PDF par cm
	var PAGE_MAX = 500; // cm : un PDF ne dépasse pas 508 cm de côté
	var boutonRip = document.querySelector('[data-fr-rip]');
	var boiteEnvoi = document.querySelector('[data-fr-envoi]');
	var etape = document.querySelector('[data-fr-etape]');
	var progression = document.querySelector('[data-fr-barre-progression]');

	function dire(texte, pourcent) {
		if (boiteEnvoi) { boiteEnvoi.hidden = false; }
		if (etape) { etape.textContent = texte; }
		if (progression && typeof pourcent === 'number') { progression.style.width = Math.max(0, Math.min(100, pourcent)) + '%'; }
	}

	function proposerSepares(raison) {
		dire(raison + ' Le RIP ne peut pas recevoir un seul fichier : envoyez les fichiers séparés.', 0);
		confirmer({message: 'Envoyer les fichiers séparés ?', detail: raison + ' Chaque fichier part sous son nom de production, avec une feuille de placement.', bouton: 'Envoyer les fichiers séparés'}).then(function (ok) {
			if (ok) { document.querySelector('[data-fr-separes]').submit(); }
		});
	}

	/* Découpe en pages de 500 cm au plus, entre deux rangées de pièces. */
	function pages(liste, longueur) {
		var coupes = [0], debut = 0;
		while (longueur - debut > PAGE_MAX) {
			var meilleure = null;
			liste.forEach(function (p) {
				var c = p.y;
				if (c <= debut || c - debut > PAGE_MAX) { return; }
				var traverse = liste.some(function (q) { return q.y < c - 0.01 && q.y + q.h > c + 0.01; });
				if (!traverse && (meilleure === null || c > meilleure)) { meilleure = c; }
			});
			if (meilleure === null) { return null; }
			coupes.push(meilleure);
			debut = meilleure;
		}
		coupes.push(longueur);
		var resultat = [];
		for (var i = 0; i < coupes.length - 1; i++) { resultat.push([coupes[i], coupes[i + 1]]); }
		return resultat;
	}

	/* Texte en ASCII pour la police standard du PDF. */
	function ascii(t) {
		return String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/·/g, '-').replace(/[^\x20-\x7E]/g, '?');
	}

	async function monter(D) {
		var P = window.PDFLib;
		var doc = await P.PDFDocument.create();
		doc.setTitle(D.nom);
		doc.setCreator('Succès Imprim · imposition au rouleau');
		var police = await doc.embedFont(P.StandardFonts.Helvetica);
		var noir = P.cmyk(0, 0, 0, 1);
		var cache = {};
		var decoupe = pages(D.pieces, D.longueur);
		if (!decoupe) { throw new Error('PIECE_LONGUE'); }
		var n = 0;
		for (var k = 0; k < decoupe.length; k++) {
			var y0 = decoupe[k][0], y1 = decoupe[k][1], H = y1 - y0;
			var page = doc.addPage([D.laize * CM, H * CM]);
			var etagere = {};
			for (var i = 0; i < D.pieces.length; i++) {
				var p = D.pieces[i];
				if (p.y < y0 - 0.01 || p.y >= y1 - 0.01) { continue; }
				n++;
				dire('Montage de la pièce ' + n + ' sur ' + D.pieces.length + '…', 5 + 45 * n / D.pieces.length);
				if (!cache[p.url]) {
					var octets = await (await fetch(p.url, {credentials: 'same-origin'})).arrayBuffer();
					if (p.type === 'pdf') { cache[p.url] = {pdf: true, o: (await doc.embedPdf(octets, [0]))[0]}; }
					else if (p.type === 'png') { cache[p.url] = {o: await doc.embedPng(octets)}; }
					else { cache[p.url] = {o: await doc.embedJpg(octets)}; }
				}
				var e = cache[p.url];
				var gauche = p.x * CM, bas = (H - (p.y - y0) - p.h) * CM;
				var opts = p.tourne
					? {x: gauche + p.l * CM, y: bas, width: p.lo * CM, height: p.ho * CM, rotate: P.degrees(90)}
					: {x: gauche, y: bas, width: p.l * CM, height: p.h * CM};
				if (e.pdf) { page.drawPage(e.o, opts); } else { page.drawImage(e.o, opts); }
				reperes(page, p, y0, H, noir);
				// Nom de la commande dans la marge de gauche, une colonne par pièce de la même rangée.
				if (D.marge >= 2) {
					var rang = etagere[p.y] = (etagere[p.y] || 0) + 1;
					var xTexte = (D.marge - 1 - (rang - 1) * 0.8) * CM;
					if (xTexte > 0.3 * CM) {
						page.drawText(ascii(p.marque), {x: xTexte, y: bas + 0.5 * CM, size: 9, font: police, color: noir, rotate: P.degrees(90), maxWidth: Math.max(p.h - 1, 2) * CM});
					}
				}
			}
		}
		return {octets: await doc.save(), pages: doc.getPageCount()};
	}

	/* Repères de coupe : à chaque coin, deux traits de 1 cm à 3 mm de la pièce. */
	function reperes(page, p, y0, H, couleur) {
		var e = 0.3, l = 1.0;
		var x1 = p.x, x2 = p.x + p.l, yh = p.y - y0, yb = p.y - y0 + p.h;
		var traits = [
			[x1 - e - l, yh, x1 - e, yh], [x1, yh - e - l, x1, yh - e],
			[x2 + e, yh, x2 + e + l, yh], [x2, yh - e - l, x2, yh - e],
			[x1 - e - l, yb, x1 - e, yb], [x1, yb + e, x1, yb + e + l],
			[x2 + e, yb, x2 + e + l, yb], [x2, yb + e, x2, yb + e + l]
		];
		traits.forEach(function (t) {
			if (t[1] < 0 || t[3] < 0 || t[1] > H || t[3] > H) { return; }
			page.drawLine({start: {x: t[0] * CM, y: (H - t[1]) * CM}, end: {x: t[2] * CM, y: (H - t[3]) * CM}, thickness: 0.5, color: couleur});
		});
	}

	async function envoyer(octets, pages) {
		// Taille des morceaux donnée par le serveur (limite d'envoi de PHP).
		var taille = C.tailleMorceau || 1024 * 1024;
		var morceaux = Math.ceil(octets.length / taille);
		var r = await fetch(C.initialiser, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.jeton}, credentials: 'same-origin', body: JSON.stringify({taille: octets.length, morceaux: morceaux})});
		var init = await r.json();
		if (!r.ok) { throw new Error(init.message || 'Envoi refusé.'); }
		for (var i = 0; i < morceaux; i++) {
			var fd = new FormData();
			fd.append('jeton', init.jeton);
			fd.append('index', String(i));
			fd.append('morceau', new Blob([octets.subarray(i * taille, Math.min(octets.length, (i + 1) * taille))]), 'morceau.part');
			var m = await fetch(C.morceau, {method: 'POST', headers: {'X-CSRF-TOKEN': C.jeton}, credentials: 'same-origin', body: fd});
			if (!m.ok) { throw new Error((await m.json().catch(function () { return {}; })).message || 'Un morceau n\'est pas passé.'); }
			dire('Envoi au serveur : ' + (i + 1) + ' / ' + morceaux, 50 + 45 * (i + 1) / morceaux);
		}
		var fin = await fetch(C.terminer, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': C.jeton}, credentials: 'same-origin', body: JSON.stringify({jeton: init.jeton, pages: pages})});
		var res = await fin.json();
		if (!fin.ok) { throw new Error(res.message || 'Le serveur a refusé le PDF.'); }
		return res;
	}

	if (boutonRip) {
		boutonRip.addEventListener('click', async function () {
			if (modifie) { dire('Enregistrez d\'abord le placement manuel (« Enregistrer le placement »).', 0); return; }
			boutonRip.disabled = true;
			try {
				dire('Préparation du PDF du rouleau…', 2);
				var D = await (await fetch(C.donnees, {credentials: 'same-origin'})).json();
				var manque = D.pieces.filter(function (p) { return !p.type; });
				if (manque.length) { proposerSepares(manque[0].marque + ' : ' + manque[0].raison + '.'); boutonRip.disabled = false; return; }
				if (D.taille > D.tailleMax) { proposerSepares('Les fichiers pèsent plus de ' + Math.round(D.tailleMax / 1048576) + ' Mo.'); boutonRip.disabled = false; return; }
				if (!window.PDFLib) { proposerSepares('L\'outil PDF n\'a pas pu être chargé.'); boutonRip.disabled = false; return; }
				var pdf;
				try { pdf = await monter(D); } catch (err) {
					proposerSepares(err && err.message === 'PIECE_LONGUE' ? 'Une pièce dépasse 5 m de long : un PDF ne peut pas la contenir sur une seule page.' : 'Un fichier ne se monte pas dans le PDF (' + (err && err.message ? err.message : 'format') + ').');
					boutonRip.disabled = false;
					return;
				}
				dire('PDF prêt (' + pdf.pages + ' page' + (pdf.pages > 1 ? 's' : '') + ', ' + (pdf.octets.length / 1048576).toFixed(1).replace('.', ',') + ' Mo). Envoi…', 50);
				var res = await envoyer(pdf.octets, pdf.pages);
				dire('Envoyé.', 100);
				window.location.href = res.url;
			} catch (err) {
				dire((err && err.message) || 'L\'envoi a échoué.', 0);
				boutonRip.disabled = false;
			}
		});
	}
})();
