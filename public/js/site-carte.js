/*
 * Carte de la boutique sur la page Contact (docs/site-vitrine.md,
 * « Carte de la boutique »).
 *
 * Chargé par la page seulement quand le bloc arrive à l'écran : ajoute
 * Leaflet (public/vendor/leaflet), pose la carte (fonds OpenStreetMap) sur
 * le plan dessiné, avec le repère de la boutique. Le plan dessiné revient si
 * Leaflet ou les fonds de carte ne se chargent pas (hors ligne, réseau
 * coupé). Pas de piège au défilement : la molette ne zoome qu'après un clic
 * sur la carte, et au téléphone un doigt fait défiler la page tant que la
 * carte n'a pas été touchée (deux doigts zooment).
 */
(function () {
	'use strict';

	var bloc = document.querySelector('[data-carte-boutique]');
	if (!bloc || bloc.getAttribute('data-etat')) {
		return;
	}
	bloc.setAttribute('data-etat', 'chargement');

	var reduit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var tactile = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	var attendus = 2;

	function pret() {
		attendus -= 1;
		if (0 === attendus) {
			demarrer();
		}
	}

	function abandonner() {
		attendus = -1;
		bloc.classList.remove('si-carte-active', 'si-carte-libre');
		[].forEach.call(bloc.querySelectorAll('.si-carte-zone, .si-carte-astuce'), function (element) {
			element.parentNode.removeChild(element);
		});
		bloc.setAttribute('data-etat', 'plan');
	}

	var feuille = document.createElement('link');
	feuille.rel = 'stylesheet';
	feuille.href = bloc.getAttribute('data-leaflet-css');
	feuille.onload = pret;
	feuille.onerror = abandonner;
	document.head.appendChild(feuille);

	var script = document.createElement('script');
	script.src = bloc.getAttribute('data-leaflet-js');
	script.onload = pret;
	script.onerror = abandonner;
	document.head.appendChild(script);

	function demarrer() {
		var L = window.L;
		var position = [parseFloat(bloc.getAttribute('data-lat')), parseFloat(bloc.getAttribute('data-lng'))];
		var nom = bloc.getAttribute('data-nom') || '';
		if (!L || isNaN(position[0]) || isNaN(position[1])) {
			abandonner();
			return;
		}

		var zone = document.createElement('div');
		zone.className = 'si-carte-zone';
		zone.setAttribute('role', 'region');
		zone.setAttribute('aria-label', 'Carte : ' + nom + '. Flèches pour déplacer la carte, touches + et − pour zoomer.');
		bloc.insertBefore(zone, bloc.firstChild);

		var astuce = document.createElement('p');
		astuce.className = 'si-carte-astuce';
		astuce.setAttribute('aria-hidden', 'true');
		astuce.textContent = tactile ? 'Touchez la carte pour la déplacer' : 'Cliquez sur la carte pour zoomer à la molette';
		bloc.appendChild(astuce);

		var carte = L.map(zone, {
			center: position,
			zoom: 17,
			minZoom: 4,
			maxZoom: 19,
			scrollWheelZoom: false,
			dragging: !tactile,
			keyboard: true,
			zoomAnimation: !reduit,
			fadeAnimation: !reduit,
			markerZoomAnimation: !reduit,
			inertia: !reduit
		});
		if (reduit) {
			// Flèches du clavier et bulle qui se recadre : déplacement sans glissé.
			var deplacer = carte.panBy;
			carte.panBy = function (decalage, options) {
				return deplacer.call(this, decalage, L.extend({}, options, { animate: false }));
			};
		}

		var chargees = 0;
		var erreurs = 0;
		var fonds = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
		});
		fonds.on('tileload', function () {
			chargees += 1;
		});
		fonds.on('tileerror', function () {
			erreurs += 1;
			if (0 === chargees && erreurs >= 3) {
				carte.remove();
				abandonner();
			}
		});
		fonds.addTo(carte);
		window.setTimeout(function () {
			if (0 === chargees && 'carte' === bloc.getAttribute('data-etat')) {
				carte.remove();
				abandonner();
			}
		}, 12000);

		// Repère rouge du plan dessiné ; la bulle est écrite en texte (rien d'interprété).
		var repere = L.marker(position, {
			title: nom,
			keyboard: true,
			icon: L.divIcon({
				className: 'si-carte-repere',
				html: '<svg viewBox="-20 -12 40 60" aria-hidden="true"><ellipse cx="0" cy="44" rx="10" ry="3.5" fill="rgba(0,0,0,.2)"/><path d="M0 44C-4 34-18 22-18 8a18 18 0 0 1 36 0c0 14-14 26-18 36Z" fill="#DE1028" stroke="#fff" stroke-width="2"/><circle cx="0" cy="8" r="6" fill="#fff"/></svg>',
				iconSize: [36, 54],
				iconAnchor: [18, 50],
				popupAnchor: [0, -46]
			})
		}).addTo(carte);
		var bulle = document.createElement('div');
		bulle.className = 'si-carte-bulle';
		var titre = document.createElement('strong');
		titre.textContent = nom;
		bulle.appendChild(titre);
		bulle.appendChild(document.createTextNode(bloc.getAttribute('data-adresse') || ''));
		// La bulle se recadre plus bas que le bouton Itinéraire (en haut à droite) : rien ne la cache.
		repere.bindPopup(bulle, { autoPanPaddingTopLeft: L.point(12, 64), autoPanPaddingBottomRight: L.point(12, 24) });

		function liberer() {
			carte.scrollWheelZoom.enable();
			carte.dragging.enable();
			bloc.classList.add('si-carte-libre');
		}
		function retenir() {
			carte.scrollWheelZoom.disable();
			if (tactile) {
				carte.dragging.disable();
			}
			bloc.classList.remove('si-carte-libre');
		}
		carte.on('click', liberer);
		repere.on('click', liberer);
		zone.addEventListener('mouseleave', retenir);
		// Au téléphone, faire défiler la page (hors de la carte) la bloque de nouveau.
		window.addEventListener('scroll', function () {
			if (tactile && bloc.classList.contains('si-carte-libre')) {
				retenir();
			}
		}, { passive: true });

		bloc.classList.add('si-carte-active');
		bloc.setAttribute('data-etat', 'carte');
	}
})();
