/* Succès Imprim : notifications sur le téléphone (écran verrouillé). */
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
