/*
 * Fiche client (docs/clients.md) : onglets (adresse #apercu, #commandes,
 * #factures…), sous-onglets de « Factures et paiements », filtres de
 * l'historique, formulaires de note à ouvrir.
 * Anciennes adresses gardées : #compte (compte du client), #fidelite
 * (relevé des points), #historique.
 */
(function () {
	'use strict';

	function onglets(conteneur, surChoix) {
		var liste = Array.prototype.slice.call(conteneur.querySelectorAll(':scope > [role="tab"]'));
		function choisir(onglet, garderAdresse) {
			liste.forEach(function (o) {
				var actif = o === onglet;
				o.setAttribute('aria-selected', actif ? 'true' : 'false');
				o.tabIndex = actif ? 0 : -1;
				var panneau = document.getElementById(o.getAttribute('aria-controls'));
				if (panneau) { panneau.hidden = !actif; }
			});
			if (surChoix && !garderAdresse) { surChoix(onglet); }
		}
		liste.forEach(function (o, i) {
			o.addEventListener('click', function () { choisir(o); });
			o.addEventListener('keydown', function (e) {
				if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
				var suivant = liste[(i + (e.key === 'ArrowRight' ? 1 : liste.length - 1)) % liste.length];
				choisir(suivant); suivant.focus();
			});
		});
		return { liste: liste, choisir: choisir };
	}

	var principal = document.querySelector('.fc-onglets');
	if (!principal) { return; }
	var tabs = onglets(principal, function (o) {
		var cle = o.id.replace('tab-', '');
		if (history.replaceState) { history.replaceState(null, '', cle === 'apercu' ? location.pathname + location.search : '#' + cle); }
	});
	var sous = document.querySelector('.fc-sous-onglets');
	var sousTabs = sous ? onglets(sous) : null;

	function ouvrir(cle) {
		var cible = cle;
		if (cle === 'compte') { cible = 'factures'; }
		if (cle === 'fidelite' || cle === 'notes-internes') { cible = 'apercu'; }
		var onglet = document.getElementById('tab-' + cible);
		if (!onglet) { return false; }
		tabs.choisir(onglet, true);
		if (cle === 'compte' && sousTabs) {
			var s = document.getElementById('sous-compte');
			if (s) { sousTabs.choisir(s); }
		}
		if (cle === 'fidelite') {
			var d = document.getElementById('fidelite');
			if (d) { d.open = true; setTimeout(function () { d.scrollIntoView({ block: 'start' }); }, 50); }
		}
		return true;
	}

	if (location.hash) { ouvrir(location.hash.slice(1)); }
	window.addEventListener('hashchange', function () { if (location.hash) { ouvrir(location.hash.slice(1)); } });

	// Liens internes (« Tout voir », « Message », « Relevé ») : changent d'onglet sans recharger.
	document.querySelectorAll('[data-onglet], [data-ouvrir]').forEach(function (a) {
		a.addEventListener('click', function (e) {
			var cle = a.getAttribute('data-onglet') || a.getAttribute('data-ouvrir');
			if (ouvrir(cle)) {
				e.preventDefault();
				if (history.replaceState) { history.replaceState(null, '', '#' + cle); }
				if (a.hasAttribute('data-onglet')) { principal.scrollIntoView({ block: 'nearest' }); }
			}
		});
	});

	// « + Note », « + Noter un appel » : le formulaire s'ouvre et prend le curseur.
	document.querySelectorAll('[data-basculer]').forEach(function (b) {
		b.addEventListener('click', function () {
			var f = document.getElementById(b.getAttribute('data-basculer'));
			if (!f) { return; }
			f.hidden = !f.hidden;
			if (!f.hidden) { var champ = f.querySelector('textarea, input[name="texte"]'); if (champ) { champ.focus(); } }
		});
	});

	// Un seul menu ou formulaire « Commander pour lui » ouvert à la fois ; un clic ailleurs le ferme.
	document.addEventListener('click', function (e) {
		document.querySelectorAll('.fc-menu[open], .fc-commander[open]').forEach(function (d) {
			if (!d.contains(e.target)) { d.open = false; }
		});
	});

	// Historique : filtres et « Voir tout ».
	var liste = document.querySelector('.fc-ligne-temps');
	if (liste) {
		var tout = document.querySelector('[data-voir-tout]');
		var vide = document.querySelector('.fc-histo-vide');
		var filtre = '';
		var appliquer = function () {
			var n = 0;
			liste.querySelectorAll('li[data-categorie]').forEach(function (li) {
				var ok = !filtre || li.dataset.categorie === filtre;
				if (ok) { n++; }
				li.hidden = !ok || (n > 25 && !liste.classList.contains('fc-ouverte'));
			});
			if (vide) { vide.hidden = n > 0 || !filtre; }
			if (tout) { tout.hidden = n <= 25 || liste.classList.contains('fc-ouverte'); }
		};
		document.querySelectorAll('.fc-filtres [data-filtre]').forEach(function (b) {
			b.addEventListener('click', function () {
				document.querySelectorAll('.fc-filtres [data-filtre]').forEach(function (x) { x.classList.toggle('active', x === b); });
				filtre = b.dataset.filtre;
				appliquer();
			});
		});
		if (tout) { tout.addEventListener('click', function () { liste.classList.add('fc-ouverte'); appliquer(); }); }
	}
})();
