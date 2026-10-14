/*
 * Coque de l'espace client (maquettes EC02, EC03, EC05), sans dépendance :
 *  - la cloche ouvre le panneau des dernières notifications sur ordinateur
 *    (sans JavaScript, c'est un lien vers la page Notifications) ;
 *  - sur téléphone, glisser une notification vers la gauche la marque
 *    comme lue (le bouton « Marquer comme lu » reste là sinon).
 */
(function () {
	var cloche = document.querySelector('[data-cloche]');
	var panneau = document.querySelector('[data-cloche-panneau]');
	var large = window.matchMedia('(min-width: 900px)');

	function fermer() {
		if (panneau && !panneau.hidden) {
			panneau.hidden = true;
			cloche.setAttribute('aria-expanded', 'false');
		}
	}

	if (cloche && panneau) {
		cloche.addEventListener('click', function (e) {
			if (!large.matches) { return; }
			e.preventDefault();
			panneau.hidden = !panneau.hidden;
			cloche.setAttribute('aria-expanded', panneau.hidden ? 'false' : 'true');
		});
		document.addEventListener('click', function (e) {
			if (!panneau.hidden && !panneau.contains(e.target) && !cloche.contains(e.target)) { fermer(); }
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fermer(); } });
	}

	// Glisser à gauche pour marquer comme lu (page Notifications).
	document.querySelectorAll('[data-glisser]').forEach(function (carte) {
		var form = carte.querySelector('form[data-lu]');
		if (!form) { return; }
		var depart = null;
		var ecart = 0;
		carte.addEventListener('touchstart', function (e) {
			depart = {x: e.touches[0].clientX, y: e.touches[0].clientY};
			ecart = 0;
		}, {passive: true});
		carte.addEventListener('touchmove', function (e) {
			if (!depart) { return; }
			var dx = e.touches[0].clientX - depart.x;
			var dy = e.touches[0].clientY - depart.y;
			if (Math.abs(dy) > Math.abs(dx)) { return; }
			ecart = Math.min(0, dx);
			carte.style.transform = 'translateX(' + Math.max(ecart, -120) + 'px)';
		}, {passive: true});
		carte.addEventListener('touchend', function () {
			carte.style.transform = '';
			if (depart && ecart < -70) {
				fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-Requested-With': 'fetch'}})
					.then(function (r) {
						if (!r.ok) { return; }
						carte.classList.remove('nouveau');
						form.remove();
						var marque = carte.querySelector('.ec-nouveau');
						if (marque) { marque.remove(); }
					}).catch(function () {});
			}
			depart = null;
		});
	});
})();
