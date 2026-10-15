/*
 * Jeton CSRF des enregistrements en JSON (docs/securite.md, audit D6).
 *
 * Pour une page dont le script envoie ses modifications en JSON sans jeton
 * (Réglages › Finitions et Types d'impression : leurs scripts
 * public/assets/js/traitement_*.js ne sont pas dans le dépôt). Ajoute
 * l'en-tête X-CSRF-TOKEN aux requêtes POST vers le site dont l'adresse se
 * termine par data-fin, par fetch() comme par XMLHttpRequest (jQuery).
 * Les autres requêtes et un en-tête déjà posé ne changent pas.
 *
 *   <script src="/js/jeton-json.js" data-jeton="{{ csrf_token('…') }}" data-fin="/update/ajax"></script>
 */
(function () {
	'use strict';

	var script = document.currentScript;
	var jeton = script && script.getAttribute('data-jeton');
	var fin = script && script.getAttribute('data-fin');
	var ENTETE = 'X-CSRF-TOKEN';
	if (!jeton || !fin) {
		return;
	}

	function viseLaRoute(methode, adresse) {
		if (String(methode || 'GET').toUpperCase() !== 'POST') {
			return false;
		}
		try {
			var url = new URL(String(adresse), window.location.href);
			return url.origin === window.location.origin && url.pathname.slice(-fin.length) === fin;
		} catch (e) {
			return false;
		}
	}

	if (window.fetch) {
		var fetchDOrigine = window.fetch;
		window.fetch = function (ressource, options) {
			var requete = (typeof Request !== 'undefined' && ressource instanceof Request) ? ressource : null;
			var methode = (options && options.method) || (requete ? requete.method : 'GET');
			if (viseLaRoute(methode, requete ? requete.url : ressource)) {
				var entetes = new Headers((options && options.headers) || (requete ? requete.headers : undefined));
				if (!entetes.has(ENTETE)) {
					entetes.set(ENTETE, jeton);
					options = Object.assign({}, options, { headers: entetes });
				}
			}
			return fetchDOrigine.call(this, ressource, options);
		};
	}

	var ouvrir = XMLHttpRequest.prototype.open;
	var poserEntete = XMLHttpRequest.prototype.setRequestHeader;
	var envoyer = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (methode, adresse) {
		this.siJetonAPoser = viseLaRoute(methode, adresse);
		return ouvrir.apply(this, arguments);
	};
	XMLHttpRequest.prototype.setRequestHeader = function (nom) {
		if (String(nom).toLowerCase() === ENTETE.toLowerCase()) {
			this.siJetonAPoser = false;
		}
		return poserEntete.apply(this, arguments);
	};
	XMLHttpRequest.prototype.send = function () {
		if (this.siJetonAPoser) {
			this.siJetonAPoser = false;
			poserEntete.call(this, ENTETE, jeton);
		}
		return envoyer.apply(this, arguments);
	};
})();
