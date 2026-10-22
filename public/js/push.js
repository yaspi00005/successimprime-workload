/*
 * Bouton « Notifications sur ce téléphone » : demande l'autorisation,
 * abonne le navigateur et envoie l'abonnement au serveur.
 * Sur iPhone : iOS 16.4 ou plus, application ajoutée à l'écran d'accueil.
 */
(function () {
	var bloc = document.querySelector('[data-si-push]');
	if (!bloc) { return; }
	var bouton = bloc.querySelector('button');
	var etat = bloc.querySelector('[data-si-push-etat]');
	var cle = bloc.dataset.cle;

	function dire(texte, actif, libelle) {
		etat.textContent = texte;
		bouton.hidden = libelle === null;
		if (libelle) { bouton.textContent = libelle; }
		bouton.dataset.actif = actif ? '1' : '';
	}
	function octets(b64) {
		var s = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
		var t = new Uint8Array(s.length);
		for (var i = 0; i < s.length; i++) { t[i] = s.charCodeAt(i); }
		return t;
	}
	function envoyer(url, corps) {
		return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': bloc.dataset.jeton}, body: JSON.stringify(corps), credentials: 'same-origin'})
			.then(function (r) { if (!r.ok) { throw new Error('serveur'); } });
	}

	if (!cle) { dire('Pas encore disponible : les clés de notification ne sont pas installées sur le serveur.', false, null); return; }
	if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext) {
		var ios = /iPhone|iPad/.test(navigator.userAgent);
		dire(ios ? 'Sur iPhone : ouvrez le site dans Safari, touchez Partager puis « Sur l\'écran d\'accueil », et ouvrez l\'application depuis cette icône.' : 'Ce navigateur ne reçoit pas les notifications (il faut une adresse https).', false, null);
		return;
	}
	if (Notification.permission === 'denied') { dire('Notifications bloquées : autorisez-les dans les réglages du navigateur pour ce site.', false, null); return; }

	// Espace client : l'application installée couvre /espace seulement.
	navigator.serviceWorker.register('/sw.js', bloc.dataset.portee ? {scope: bloc.dataset.portee} : undefined).then(function (reg) {
		return reg.pushManager.getSubscription().then(function (abo) {
			if (abo) {
				// Toujours renvoyer l'abonnement : il a pu changer, ou ce téléphone a changé de personne.
				envoyer(bloc.dataset.abonner, abo.toJSON()).catch(function () {});
				dire('Activées sur ce téléphone.', true, 'Désactiver');
			} else {
				dire('Recevez les rappels et les alertes même téléphone verrouillé.', false, 'Activer');
			}
			bouton.addEventListener('click', function () {
				bouton.disabled = true;
				var suite = bouton.dataset.actif
					? reg.pushManager.getSubscription().then(function (a) { return a ? envoyer(bloc.dataset.desabonner, {endpoint: a.endpoint}).then(function () { return a.unsubscribe(); }) : null; })
						.then(function () { dire('Désactivées sur ce téléphone.', false, 'Activer'); })
					: Notification.requestPermission().then(function (p) {
						if (p !== 'granted') { throw new Error('refus'); }
						return reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: octets(cle)});
					}).then(function (a) { return envoyer(bloc.dataset.abonner, a.toJSON()); })
						.then(function () { dire('Activées sur ce téléphone.', true, 'Désactiver'); });
				suite.catch(function (e) {
					dire(e && e.message === 'refus' ? 'Vous avez refusé les notifications.' : 'Activation impossible pour le moment. Réessayez.', false, 'Activer');
				}).finally(function () { bouton.disabled = false; });
			});
		});
	}).catch(function () { dire('Activation impossible sur ce navigateur.', false, null); });
})();
