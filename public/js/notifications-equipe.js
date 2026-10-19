/*
 * Notifications de l'équipe (maquettes NT01, NT02, docs/notifications-equipe.md) :
 * interrogation légère toutes les 30 s, bannière critique, petites fenêtres
 * en bas (8 s, bouton direct), son court, cloche rechargée à l'ouverture.
 *
 * Le son : le navigateur refuse de jouer un son avant que la personne ait
 * touché la page. Le contexte audio est donc ouvert au premier clic ou à la
 * première touche ; un son demandé avant reste en attente et un petit
 * bouton « Activer le son » apparaît.
 */
(function () {
	var script = document.currentScript;
	if (!script || !document.querySelector('[data-si-cloche]')) { return; }
	var urls = {etat: script.dataset.etat, cloche: script.dataset.cloche, son: script.dataset.son, centre: script.dataset.centre};
	var jeton = script.dataset.jeton;
	var INTERVALLE = 30000;
	var DUREE_FENETRE = 8000;
	var audio = null;
	var sonEnAttente = null;
	var minuterie = null;

	// ---------- Son ----------
	function ouvrirAudio() {
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if (!Ctx) { return null; }
		if (!audio) { try { audio = new Ctx(); } catch (e) { return null; } }
		if (audio.state === 'suspended') { audio.resume().catch(function () {}); }
		return audio;
	}
	function bip(critique) {
		var ctx = audio;
		if (!ctx || ctx.state !== 'running') { sonEnAttente = critique; montrerActiverSon(); return; }
		var notes = critique ? [880, 660, 880] : [740, 988];
		var t = ctx.currentTime;
		notes.forEach(function (f, i) {
			var o = ctx.createOscillator();
			var g = ctx.createGain();
			o.type = 'sine';
			o.frequency.value = f;
			g.gain.setValueAtTime(0.0001, t + i * 0.16);
			g.gain.exponentialRampToValueAtTime(0.18, t + i * 0.16 + 0.02);
			g.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.16 + 0.14);
			o.connect(g); g.connect(ctx.destination);
			o.start(t + i * 0.16); o.stop(t + i * 0.16 + 0.15);
		});
	}
	function deverrouiller() {
		var ctx = ouvrirAudio();
		if (ctx && ctx.state === 'running') {
			var bouton = document.querySelector('[data-si-activer-son]');
			if (bouton) { bouton.remove(); }
			if (sonEnAttente !== null) { var c = sonEnAttente; sonEnAttente = null; bip(c); }
		}
	}
	['pointerdown', 'keydown'].forEach(function (ev) { document.addEventListener(ev, deverrouiller, {passive: true}); });
	function montrerActiverSon() {
		var zone = document.querySelector('[data-si-fenetres]');
		if (!zone || zone.querySelector('[data-si-activer-son]')) { return; }
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'si-son-a-activer';
		b.setAttribute('data-si-activer-son', '');
		b.innerHTML = '<i class="fas fa-volume-up" aria-hidden="true"></i> Activer le son des alertes';
		b.addEventListener('click', function () { deverrouiller(); });
		zone.appendChild(b);
	}

	// ---------- Affichage ----------
	function texte(el, t) { el.textContent = t || ''; return el; }
	function element(tag, classe) { var e = document.createElement(tag); if (classe) { e.className = classe; } return e; }

	function badge(total) {
		var b = document.querySelector('[data-si-cloche-nombre]');
		if (!b) { return; }
		b.hidden = !total;
		b.textContent = total > 99 ? '99+' : String(total || 0);
	}

	function banniere(critiques) {
		var zone = document.querySelector('[data-si-banniere]');
		if (!zone) { return; }
		zone.innerHTML = '';
		zone.hidden = !critiques.length;
		critiques.slice(0, 2).forEach(function (n) {
			var ligne = element('div', 'si-banniere-ligne');
			var i = element('i', 'fas fa-exclamation-triangle'); i.setAttribute('aria-hidden', 'true');
			ligne.appendChild(i);
			ligne.appendChild(texte(element('strong'), n.titre));
			if (n.detail) { ligne.appendChild(texte(element('span', 'si-banniere-detail'), n.detail)); }
			if (n.action) { var a = texte(element('a', 'si-banniere-bouton'), n.action); a.href = n.url; ligne.appendChild(a); }
			zone.appendChild(ligne);
		});
		if (critiques.length > 2) {
			var plus = texte(element('a', 'si-banniere-plus'), '+ ' + (critiques.length - 2) + ' autre(s) alerte(s) critique(s)');
			plus.href = urls.centre + '?filtre=urgentes';
			zone.appendChild(plus);
		}
	}

	function fenetre(n) {
		var zone = document.querySelector('[data-si-fenetres]');
		if (!zone || zone.querySelector('[data-id="' + n.id + '"]')) { return; }
		var f = element('div', 'si-fenetre si-niv-' + n.niveau);
		f.dataset.id = n.id;
		f.setAttribute('role', 'status');
		var ic = element('span', 'si-fenetre-icone'); var i = element('i', n.icone); i.setAttribute('aria-hidden', 'true'); ic.appendChild(i);
		var corps = element('span', 'si-fenetre-corps');
		corps.appendChild(texte(element('span', 'si-fenetre-titre'), n.titre + (n.compteur > 1 ? ' (' + n.compteur + ' fois)' : '')));
		if (n.detail) { corps.appendChild(texte(element('span', 'si-fenetre-detail'), n.detail)); }
		f.appendChild(ic); f.appendChild(corps);
		if (n.action) { var a = texte(element('a', 'si-fenetre-bouton'), n.action); a.href = n.url; f.appendChild(a); } else { f.appendChild(element('span')); }
		var x = texte(element('button', 'si-fenetre-fermer'), '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer');
		f.appendChild(x);
		var restant = DUREE_FENETRE, debut = Date.now(), chrono = null;
		function fermer() { clearTimeout(chrono); f.classList.add('sortie'); setTimeout(function () { f.remove(); }, 260); }
		function lancer() { debut = Date.now(); chrono = setTimeout(fermer, restant); }
		// Survolée ou avec le focus : elle attend (le temps de lire et de cliquer).
		f.addEventListener('mouseenter', function () { clearTimeout(chrono); restant -= Date.now() - debut; });
		f.addEventListener('mouseleave', lancer);
		f.addEventListener('focusin', function () { clearTimeout(chrono); });
		x.addEventListener('click', fermer);
		zone.insertBefore(f, zone.firstChild);
		lancer();
	}

	function interroger() {
		if (document.hidden) { return; }
		fetch(urls.etat, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (e) {
				if (!e || e.compteurs === undefined) { return; }
				badge(e.total);
				banniere(e.critiques || []);
				(e.fenetres || []).forEach(fenetre);
				if (e.sonner && e.son) { bip((e.critiques || []).length > 0 && !(e.fenetres || []).length); }
			})
			.catch(function () {});
	}

	// ---------- Cloche ----------
	var menu = document.querySelector('[data-si-cloche-menu]');
	function brancherCloche() {
		if (!menu) { return; }
		menu.querySelectorAll('[data-si-onglet]').forEach(function (b) {
			b.addEventListener('click', function (ev) {
				ev.preventDefault(); ev.stopPropagation();
				var choix = b.dataset.siOnglet;
				menu.querySelectorAll('[data-si-onglet]').forEach(function (o) { o.classList.toggle('actif', o === b); o.setAttribute('aria-selected', o === b ? 'true' : 'false'); });
				menu.querySelectorAll('.si-cloche-liste > li').forEach(function (li) {
					li.hidden = choix !== 'tout' && (choix === 'mention' ? !li.dataset.mention : li.dataset.groupe !== choix);
				});
			});
		});
		var tout = menu.querySelector('[data-si-tout-lu]');
		if (tout) {
			tout.addEventListener('submit', function (ev) {
				ev.preventDefault();
				fetch(tout.action, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: new FormData(tout)})
					.then(function () { recharger(); interroger(); });
			});
		}
	}
	function recharger() {
		if (!menu) { return; }
		fetch(urls.cloche, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then(function (r) { return r.ok ? r.text() : null; })
			.then(function (html) { if (html) { menu.innerHTML = html; brancherCloche(); } })
			.catch(function () {});
	}
	var lienCloche = document.querySelector('[data-si-cloche]');
	if (lienCloche) { lienCloche.addEventListener('show.bs.dropdown', recharger); lienCloche.addEventListener('click', function () { setTimeout(function () { if (menu && menu.classList.contains('show')) { recharger(); } }, 0); }); }
	brancherCloche();

	// ---------- Bouton « son » du centre ----------
	document.addEventListener('change', function (ev) {
		var c = ev.target.closest('[data-si-son-personne]');
		if (!c) { return; }
		var corps = new FormData(); corps.append('_token', jeton); corps.append('actif', c.checked ? '1' : '0');
		fetch(urls.son, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: corps}).then(function () { if (c.checked) { ouvrirAudio(); bip(false); } });
	});

	setTimeout(interroger, 1500);
	minuterie = setInterval(interroger, INTERVALLE);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { interroger(); } });
})();
