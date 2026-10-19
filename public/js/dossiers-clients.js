/*
 * Dossiers clients (docs/dossiers-clients.md) : détail d'un fichier sans
 * recharger la page, vue miniatures/liste, copie du chemin, volets,
 * liste des clients proposés. Tout marche aussi sans JavaScript (liens
 * et formulaires classiques).
 */
(function () {
	'use strict';

	function message(texte) {
		var m = document.createElement('div');
		m.className = 'dc-copie-ok';
		m.setAttribute('role', 'status');
		m.textContent = texte;
		document.body.appendChild(m);
		setTimeout(function () { m.remove(); }, 2600);
	}

	// « Chemin » : copie dans le presse-papiers (repli : sélection manuelle).
	function copier(texte) {
		function repli() {
			var t = document.createElement('textarea');
			t.value = texte;
			t.setAttribute('readonly', '');
			t.style.position = 'fixed';
			t.style.opacity = '0';
			document.body.appendChild(t);
			t.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			t.remove();
			if (ok) { message('Chemin copié : ' + texte); } else { window.prompt('Copiez ce chemin :', texte); }
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(texte).then(function () { message('Chemin copié : ' + texte); }, repli);
		} else {
			repli();
		}
	}

	document.addEventListener('click', function (e) {
		var c = e.target.closest('[data-dc-copier]');
		if (c) { e.preventDefault(); copier(c.getAttribute('data-dc-copier')); return; }

		var b = e.target.closest('[data-dc-basculer]');
		if (b) {
			var cible = document.getElementById(b.getAttribute('data-dc-basculer'));
			if (cible) {
				cible.hidden = !cible.hidden;
				b.setAttribute('aria-expanded', cible.hidden ? 'false' : 'true');
				if (!cible.hidden) {
					var champ = cible.querySelector('input:not([type=hidden]), select');
					if (champ) { champ.focus(); }
				}
			}
			return;
		}

		var f = e.target.closest('[data-dc-fermer]');
		if (f) {
			var d = document.getElementById('dc-detail');
			if (d) { d.classList.remove('dc-ouvert'); }
		}
	});

	// Confirmation avant un geste qui se voit chez le client.
	document.addEventListener('submit', function (e) {
		var q = e.target.getAttribute && e.target.getAttribute('data-dc-confirmer');
		if (q && !window.confirm(q)) { e.preventDefault(); }
	});

	// Tri : envoie le formulaire au changement.
	document.querySelectorAll('[data-dc-auto]').forEach(function (s) {
		s.addEventListener('change', function () { s.form.submit(); });
	});

	// Vue miniatures / liste (retenue sur ce poste).
	var liste = document.querySelector('[data-dc-liste]');
	var boutonsVue = document.querySelectorAll('[data-dc-vue]');
	function vue(v) {
		if (liste) { liste.classList.toggle('dc-en-liste', v === 'liste'); }
		boutonsVue.forEach(function (b) {
			var actif = b.getAttribute('data-dc-vue') === v;
			b.classList.toggle('actif', actif);
			b.setAttribute('aria-pressed', actif ? 'true' : 'false');
		});
		try { localStorage.setItem('dc-vue', v); } catch (e) { /* stockage indisponible */ }
	}
	boutonsVue.forEach(function (b) { b.addEventListener('click', function () { vue(b.getAttribute('data-dc-vue')); }); });
	try { if (localStorage.getItem('dc-vue') === 'liste') { vue('liste'); } } catch (e) { /* stockage indisponible */ }

	// Détail d'un fichier : chargé dans le volet de droite sans recharger.
	var racine = document.getElementById('dc-explorateur');
	var volet = document.getElementById('dc-detail');
	if (racine && volet) {
		var modeleUrl = racine.getAttribute('data-dc-detail');
		var base = racine.getAttribute('data-dc-base');
		var petit = window.matchMedia('(max-width: 767.98px)');
		if (petit.matches && volet.querySelector('.dc-detail')) { volet.classList.add('dc-ouvert'); }
		racine.addEventListener('click', function (e) {
			var a = e.target.closest('a[data-dc-fichier]');
			if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) { return; }
			e.preventDefault();
			var id = a.getAttribute('data-dc-fichier');
			volet.setAttribute('aria-busy', 'true');
			fetch(modeleUrl.replace('__ID__', id) + '?base=' + encodeURIComponent(base), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); })
				.then(function (html) {
					volet.innerHTML = html;
					volet.removeAttribute('aria-busy');
					racine.querySelectorAll('.dc-tuile.actif').forEach(function (t) { t.classList.remove('actif'); t.removeAttribute('aria-current'); });
					var tuile = racine.querySelector('.dc-tuile[data-dc-fichier="' + id + '"]');
					if (tuile) { tuile.classList.add('actif'); tuile.setAttribute('aria-current', 'true'); }
					if (petit.matches) { volet.classList.add('dc-ouvert'); } else { volet.scrollTop = 0; }
					if (history.replaceState) { history.replaceState(null, '', a.getAttribute('href')); }
				})
				.catch(function () { window.location.href = a.getAttribute('href'); });
		});
	}

	// Champ « Client » : propositions (code · nom) au fil de la frappe.
	var datalist = document.getElementById('dc-clients');
	if (datalist) {
		var url = datalist.getAttribute('data-dc-url');
		var minuteur = null;
		document.querySelectorAll('[data-dc-chercher-client]').forEach(function (champ) {
			champ.addEventListener('input', function () {
				clearTimeout(minuteur);
				var q = champ.value.trim();
				if (q.length < 2 || q.indexOf(' · ') > 0) { return; }
				minuteur = setTimeout(function () {
					fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
						.then(function (r) { return r.ok ? r.json() : []; })
						.then(function (clients) {
							datalist.innerHTML = '';
							clients.forEach(function (c) {
								var o = document.createElement('option');
								o.value = c.texte;
								datalist.appendChild(o);
							});
						})
						.catch(function () { /* liste indisponible : le code tapé suffit */ });
				}, 200);
			});
		});
	}
})();
