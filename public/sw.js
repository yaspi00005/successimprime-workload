/*
 * Succès Imprim : notifications sur le téléphone (écran verrouillé).
 *
 * Le même fichier sert au personnel (portée « / ») et à l'espace client
 * (portée « /espace », application de l'écran d'accueil). Pour l'espace
 * client seulement, une page « Pas de connexion » quand le réseau manque
 * (docs/espace-client.md) : les pages passent toujours par le réseau, rien
 * de l'espace n'est gardé dans le téléphone, seule cette page sans aucune
 * donnée l'est. Le personnel garde le comportement d'avant (pas d'écoute
 * des requêtes).
 */
var HORS_LIGNE = '/hors-ligne-espace.html';
var CACHE = 'si-espace-hors-ligne-1';
var ESPACE = new URL(self.registration.scope).pathname.indexOf('/espace') === 0;

if (ESPACE) {
	// Une page « Pas de connexion » introuvable n'empêche pas les notifications : le navigateur montrera la sienne.
	self.addEventListener('install', function (e) {
		e.waitUntil(caches.open(CACHE).then(function (c) { return c.add(new Request(HORS_LIGNE, {cache: 'reload'})); })
			.catch(function () {})
			.then(function () { return self.skipWaiting(); }));
	});

	self.addEventListener('activate', function (e) {
		e.waitUntil(caches.keys().then(function (noms) {
			return Promise.all(noms.filter(function (n) { return n.indexOf('si-espace-hors-ligne-') === 0 && n !== CACHE; })
				.map(function (n) { return caches.delete(n); }));
		}));
	});

	// Ouverture d'une page de l'espace : le réseau ; sans réseau, la page « Pas de connexion ».
	// Les cadres (aperçu d'un BAT en PDF…) et les envois de formulaire vont droit au réseau.
	self.addEventListener('fetch', function (e) {
		var r = e.request;
		if (r.mode !== 'navigate' || r.method !== 'GET' || (r.destination && r.destination !== 'document')) { return; }
		e.respondWith(fetch(r).catch(function () {
			return caches.match(HORS_LIGNE).then(function (page) { return page || Response.error(); });
		}));
	});
}

self.addEventListener('push', function (e) {
	var d = {};
	try { d = e.data ? e.data.json() : {}; } catch (x) { d = {texte: e.data ? e.data.text() : ''}; }
	e.waitUntil(self.registration.showNotification(d.titre || 'Succès Imprim', {
		body: d.texte || '',
		icon: '/images/icone-192.png',
		badge: '/images/icone-192.png',
		tag: d.tag,
		data: {url: d.url || '/'}
	}));
});

self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = new URL((e.notification.data && e.notification.data.url) || '/', self.location.origin).href;
	e.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (liste) {
		for (var i = 0; i < liste.length; i++) {
			if ('navigate' in liste[i]) { return liste[i].navigate(url).then(function (c) { return c && c.focus(); }); }
		}
		return clients.openWindow(url);
	}));
});
