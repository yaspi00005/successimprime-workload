/*
 * Mesure d'audience Google Analytics du site public (balise Google demandée
 * par Yaya, docs/referencement.md et docs/securite.md).
 *
 * Posé par site/_site.html.twig seulement sur les pages publiques du site en
 * ligne que MesureAudience retient, avec la politique de contenu qui
 * autorise Google sur ces pages-là :
 *
 *   <script src="/js/mesure-audience.js" data-mesure="G-…" defer></script>
 *
 * Même code que la balise donnée par Google, avec trois précautions :
 *  - aucune donnée personnelle : pas d'identifiant d'utilisateur ni de
 *    client ; l'adresse de la page part sans ses paramètres, sauf ceux d'une
 *    campagne (utm_…, gclid) ; une page précédente du site part réduite à
 *    l'adresse du site (elle pourrait porter un code : suivi de facture,
 *    sondage…) ;
 *  - ni Google Signals ni personnalisation des annonces ;
 *  - gtag.js (plus de 100 Ko) est demandé une fois la page chargée : en 3G
 *    lente, il ne retarde ni le texte, ni les images, ni les polices.
 */
(function () {
	'use strict';

	var script = document.currentScript;
	var id = script && script.getAttribute('data-mesure');
	if (!id || !/^G-[A-Z0-9]{4,20}$/.test(id)) {
		return;
	}

	function adresseSansDonnees() {
		var gardes = [];
		try {
			new URLSearchParams(window.location.search).forEach(function (valeur, nom) {
				if (/^utm_[a-z_]+$/.test(nom) || nom === 'gclid') {
					gardes.push(encodeURIComponent(nom) + '=' + encodeURIComponent(valeur));
				}
			});
		} catch (e) {
			gardes = [];
		}

		return window.location.origin + window.location.pathname + (gardes.length ? '?' + gardes.join('&') : '');
	}

	function referentSansDonnees() {
		var referent = document.referrer || '';
		try {
			if (referent && new URL(referent).origin === window.location.origin) {
				return window.location.origin + '/';
			}
		} catch (e) {
			return '';
		}

		return referent;
	}

	window.dataLayer = window.dataLayer || [];
	function gtag() { window.dataLayer.push(arguments); }
	window.gtag = window.gtag || gtag;

	gtag('js', new Date());
	gtag('config', id, {
		page_location: adresseSansDonnees(),
		page_referrer: referentSansDonnees(),
		allow_google_signals: false,
		allow_ad_personalization_signals: false
	});

	function charger() {
		var gtagJs = document.createElement('script');
		gtagJs.async = true;
		gtagJs.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
		document.head.appendChild(gtagJs);
	}

	if (document.readyState === 'complete') {
		charger();
	} else {
		window.addEventListener('load', charger);
	}
})();
