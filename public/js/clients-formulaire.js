/*
 * Formulaire client (K02 et ajout terrain K05) : type de client, lignes de
 * contact, position GPS et alerte de doublon pendant la saisie du numéro.
 */
(function () {
	const form = document.querySelector('[data-doublons]');
	if (!form) return;

	// Type : on affiche et on envoie seulement les champs du type choisi.
	function appliquerType() {
		const type = (form.querySelector('[name="c[type]"]:checked') || {}).value;
		const orga = type !== 'particulier';
		form.querySelectorAll('[data-orga]').forEach(b => { b.hidden = !orga; b.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = !orga; }); });
		form.querySelectorAll('[data-particulier]').forEach(b => { b.hidden = orga; b.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = orga; }); });
	}
	form.querySelectorAll('[name="c[type]"]').forEach(r => r.addEventListener('change', appliquerType));
	appliquerType();

	// Contacts.
	const liste = form.querySelector('[data-contacts]');
	const modele = form.querySelector('[data-modele-contact]');
	const vide = form.querySelector('[data-contacts-vide]');
	let suivant = liste ? liste.children.length + 100 : 0;
	function majVide() { if (vide) vide.hidden = liste.children.length > 0; }
	form.querySelector('[data-ajouter-contact]')?.addEventListener('click', () => {
		const html = modele.innerHTML.replaceAll('__i__', String(suivant++));
		liste.insertAdjacentHTML('beforeend', html);
		const ligne = liste.lastElementChild;
		if (liste.children.length === 1) ligne.querySelector('select').value = 'principal';
		ligne.querySelector('input:not([type=hidden])').focus();
		majVide();
	});
	liste?.addEventListener('click', e => {
		const b = e.target.closest('[data-retirer-contact]');
		if (b) { b.closest('[data-contact]').remove(); majVide(); }
	});

	// Position GPS depuis le téléphone ou le navigateur.
	form.querySelector('[data-gps]')?.addEventListener('click', e => {
		const texte = form.querySelector('[data-gps-texte]');
		if (!navigator.geolocation) { texte.textContent = 'Position indisponible sur cet appareil.'; return; }
		texte.textContent = 'Recherche de la position…';
		navigator.geolocation.getCurrentPosition(p => {
			form.querySelector('[data-lat]').value = p.coords.latitude.toFixed(7);
			form.querySelector('[data-lng]').value = p.coords.longitude.toFixed(7);
			texte.textContent = 'Position enregistrée · précision ' + Math.round(p.coords.accuracy) + ' m';
		}, () => { texte.textContent = 'Position refusée ou introuvable.'; }, { enableHighAccuracy: true, timeout: 15000 });
	});
	if (form.dataset.gpsAuto !== undefined && !form.querySelector('[data-lat]').value) {
		form.querySelector('[data-gps]')?.click();
	}

	// Doublon possible : vérifié quand un numéro complet est saisi.
	const bandeau = document.getElementById('si-cf-doublon');
	const deja = new Set();
	const echapper = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	async function verifier(input) {
		let tel = input.value.replace(/\D/g, '');
		if (tel.length === 11 && tel.startsWith('223')) tel = tel.slice(3);
		if (tel.length !== 8 || deja.has(tel)) return;
		deja.add(tel);
		const r = await fetch(form.dataset.doublons + '?tel=' + tel + '&sauf=' + encodeURIComponent(form.dataset.sauf || ''), { headers: { Accept: 'application/json' } });
		if (!r.ok) return;
		const liste = await r.json();
		if (!liste.length) return;
		const d = liste[0];
		const num = '+223 ' + tel.replace(/(\d{2})(?=\d)/g, '$1 ');
		bandeau.innerHTML = '<b><i class="fas fa-exclamation-triangle me-1"></i>Doublon possible</b>'
			+ '<span>Le numéro ' + num + ' est déjà utilisé par <b>' + echapper(d.nom) + '</b> (' + echapper(d.detail) + ').</span>'
			+ (d.fiche ? '<a class="btn btn-light btn-sm" href="' + d.fiche + '">Ouvrir la fiche</a>' : '')
			+ (d.transformer ? '<a class="btn btn-light btn-sm" href="' + d.prospect + '">Voir les prospects</a><a class="btn btn-light btn-sm" href="' + d.transformer + '">Transformer ce prospect en client</a>' : '');
		bandeau.hidden = false;
	}
	form.addEventListener('change', e => { if (e.target.matches('[data-tel]')) verifier(e.target); });
	form.addEventListener('input', e => { if (e.target.matches('[data-tel]') && e.target.value.replace(/\D/g, '').length >= 8) verifier(e.target); });
})();
