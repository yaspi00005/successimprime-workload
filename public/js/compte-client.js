/*
 * Compte du client (fiche client, caisse) : la fenêtre de dépôt ne propose
 * que les comptes du mode choisi, la référence hors espèces, les frais
 * Orange Money ou Wave pour ces deux modes. À la caisse : la monnaie à
 * rendre d'après le montant remis, et la case « Garder la monnaie sur le
 * compte du client ». Le serveur refait tous les contrôles.
 */
(function () {
	document.querySelectorAll('[data-si-cc-form]').forEach(function (form) {
		var comptes = form.querySelector('[data-si-cc-comptes]');
		var reference = form.querySelector('[data-si-cc-reference]');
		var frais = form.querySelector('[data-si-cc-frais]');
		var modes = form.querySelectorAll('input[name="mode"]');
		if (!comptes || !modes.length) { return; }
		var suivre = function () {
			var choisi = form.querySelector('input[name="mode"]:checked');
			if (!choisi) { return; }
			var type = choisi.dataset.type;
			var premier = null;
			Array.prototype.forEach.call(comptes.options, function (o) {
				o.hidden = o.dataset.type !== type;
				o.disabled = o.hidden;
				if (!o.hidden && !premier) { premier = o; }
			});
			var actuel = comptes.options[comptes.selectedIndex];
			if (!actuel || actuel.hidden) { comptes.value = premier ? premier.value : ''; }
			if (reference) { reference.hidden = choisi.value === 'especes'; }
			if (frais) { frais.hidden = choisi.value === 'especes'; }
		};
		modes.forEach(function (m) { m.addEventListener('change', suivre); });
		suivre();
	});

	var monnaie = document.querySelector('[data-si-cc-monnaie]');
	var caisse = document.getElementById('form-caisse');
	if (monnaie && caisse) {
		var montant = caisse.querySelector('input[name$="[montant]"]');
		var mode = caisse.querySelector('select[name$="[mode]"]');
		var remis = monnaie.querySelector('input[name="montant_remis"]');
		var garder = monnaie.querySelector('input[name="garder_monnaie"]');
		var rendu = monnaie.querySelector('[data-si-cc-rendu]');
		var francs = function (n) { return new Intl.NumberFormat('fr-FR').format(n).replace(/ /g, ' ') + ' F'; };
		var nombre = function (v) { return parseInt(String(v).replace(/\D/g, ''), 10) || 0; };
		var calculer = function () {
			monnaie.hidden = mode.value !== 'especes';
			if (monnaie.hidden) { garder.checked = false; }
			var difference = nombre(remis.value) - nombre(montant.value);
			garder.disabled = difference <= 0;
			if (difference <= 0) { garder.checked = false; }
			rendu.textContent = difference > 0
				? (garder.checked ? francs(difference) + ' gardés sur le compte du client' : 'À rendre : ' + francs(difference))
				: (remis.value ? 'Rien à rendre' : '');
		};
		[remis, montant].forEach(function (c) { c.addEventListener('input', calculer); });
		garder.addEventListener('change', calculer);
		caisse.querySelectorAll('.si-caisse-mode').forEach(function (b) { b.addEventListener('click', function () { setTimeout(calculer, 0); }); });
		calculer();
	}
})();
