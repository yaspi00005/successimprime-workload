/*
 * Commande d'achat (maquette SU03, docs/achats.md) : recalcule à la
 * saisie les m², les totaux, les frais par m², le coût de revient, le
 * total rendu Bamako et qui doit valider. Mêmes règles que
 * CalculsAchat côté serveur, qui refait le calcul à l'enregistrement.
 */
(function () {
	'use strict';

	var page = document.querySelector('[data-ac-commande]');
	var formulaire = document.getElementById('commande-achat');
	if (!page || !formulaire) {
		return;
	}

	var corps = formulaire.querySelector('[data-ac-lignes] tbody');
	var modele = formulaire.querySelector('template[data-ac-modele]');
	var seuil = parseInt(page.dataset.seuil, 10) || 0;
	var acompte = parseInt(page.dataset.acompte, 10) || 0;
	var compteur = corps.querySelectorAll('[data-ac-ligne]').length;

	var entier = function (v) { return parseInt(String(v || '').replace(/\D+/g, ''), 10) || 0; };
	var nombre = function (n, decimales) {
		var arrondi = Math.round(n * Math.pow(10, decimales || 0)) / Math.pow(10, decimales || 0);
		return arrondi.toLocaleString('fr-FR', { maximumFractionDigits: decimales || 0 }).replace(/[  ]/g, ' ');
	};
	var francs = function (n) { return nombre(n) + ' F'; };
	var pluriel = function (mot, n) {
		if (Math.abs(n) < 2) { return mot; }
		if (/eau$/.test(mot)) { return mot + 'x'; }
		return /[sx]$/.test(mot) ? mot : mot + 's';
	};
	var texte = function (selecteur, valeur) {
		var el = page.querySelector(selecteur);
		if (el) { el.textContent = valeur; }
	};

	// « 4 rouleaux ≈ 21 semaines », comme CalculsAchat::suggestion.
	var suggestion = function (quantite, uniteAchat, facteur, conso) {
		var debut = quantite + ' ' + pluriel(uniteAchat || 'unité', quantite);
		if (!(conso > 0)) { return debut + ' · pas de consommation récente'; }
		var semaines = quantite * facteur / conso;
		if (semaines < 1) { return debut + ' ≈ ' + Math.max(1, Math.round(semaines * 7)) + ' jours'; }
		semaines = Math.round(semaines);
		return debut + ' ≈ ' + semaines + ' semaine' + (semaines > 1 ? 's' : '');
	};

	// Parts de frais par ligne : au m² si même unité, sinon au prorata du montant.
	var repartir = function (lignes, frais, memeUnite) {
		var poids = lignes.map(function (l) { return Math.max(0, memeUnite ? l.quantite : l.montant); });
		var total = poids.reduce(function (a, b) { return a + b; }, 0);
		if (frais <= 0 || total <= 0) { return lignes.map(function () { return 0; }); }
		var reparti = 0;
		return poids.map(function (p, i) {
			var part = i === poids.length - 1 ? frais - reparti : Math.round(frais * p / total);
			reparti += part;
			return part;
		});
	};

	var recalculer = function () {
		var lignes = [];
		corps.querySelectorAll('[data-ac-ligne]').forEach(function (tr) {
			var option = tr.querySelector('[data-ac-article]').selectedOptions[0];
			var quantite = entier(tr.querySelector('[data-ac-qte]').value);
			var prix = entier(tr.querySelector('[data-ac-prix]').value);
			if (!option || !option.value) {
				tr.querySelector('[data-ac-m2]').textContent = '—';
				tr.querySelector('[data-ac-total]').textContent = '—';
				tr.querySelector('[data-ac-suggestion]').textContent = '';
				return;
			}
			var facteur = parseFloat(option.dataset.facteur) || 1;
			var quantiteStock = Math.round(quantite * facteur * 1000) / 1000;
			tr.querySelector('[data-ac-m2]').textContent = nombre(quantiteStock, 1);
			tr.querySelector('[data-ac-total]').textContent = francs(quantite * prix);
			tr.querySelector('[data-ac-suggestion]').textContent = quantite > 0 ? suggestion(quantite, option.dataset.uniteAchat, facteur, parseFloat(option.dataset.conso) || 0) : '';
			lignes.push({ nom: option.dataset.nom, unite: option.dataset.unite, quantite: quantiteStock, montant: quantite * prix });
		});

		var unites = lignes.map(function (l) { return l.unite; }).filter(function (u, i, t) { return t.indexOf(u) === i; });
		var memeUnite = unites.length <= 1;
		var unite = unites[0] || 'unité';
		var quantiteTotale = lignes.reduce(function (a, l) { return a + l.quantite; }, 0);
		var marchandise = lignes.reduce(function (a, l) { return a + l.montant; }, 0);
		var transport = entier(formulaire.querySelector('[data-ac-frais="transport"]').value);
		var transit = entier(formulaire.querySelector('[data-ac-frais="transit"]').value);
		var partsTransport = repartir(lignes, transport, memeUnite);
		var partsTransit = repartir(lignes, transit, memeUnite);
		var rendu = marchandise + transport + transit;

		texte('[data-ac-total-quantite]', memeUnite ? nombre(quantiteTotale, 1) : '—');
		texte('[data-ac-total-marchandise]', francs(marchandise));
		texte('[data-ac-libelle-par]', memeUnite ? 'Par ' + unite : 'Répartition');
		texte('[data-ac-par="transport"]', memeUnite ? (quantiteTotale > 0 ? francs(Math.round(transport / quantiteTotale)) : '—') : 'au prorata');
		texte('[data-ac-par="transit"]', memeUnite ? (quantiteTotale > 0 ? francs(Math.round(transit / quantiteTotale)) : '—') : 'au prorata');
		texte('[data-ac-total-rendu]', francs(rendu));
		texte('[data-ac-r-marchandise]', francs(marchandise));
		texte('[data-ac-r-transport]', francs(transport));
		texte('[data-ac-r-transit]', francs(transit));
		texte('[data-ac-r-acompte]', francs(Math.round(marchandise * acompte / 100)));

		var couts = page.querySelector('[data-ac-couts]');
		couts.innerHTML = '';
		if (lignes.length === 0) {
			couts.innerHTML = '<p class="ac-muet mb-0">Ajoutez un article.</p>';
		}
		lignes.forEach(function (l, i) {
			var cout = l.quantite > 0 ? Math.round((l.montant + partsTransport[i] + partsTransit[i]) / l.quantite) : null;
			var bloc = document.createElement('div');
			bloc.className = 'ac-cout';
			bloc.innerHTML = '<span><b class="fs-6"></b><small>achat + transport + transit</small></span><b></b>';
			bloc.querySelector('span b').textContent = l.nom;
			bloc.lastChild.textContent = cout === null ? '—' : francs(cout) + (memeUnite ? '' : ' /' + l.unite);
			couts.appendChild(bloc);
		});

		// Au-dessus du seuil, la direction valide ; en dessous, la commande part.
		var auDessus = rendu > seuil;
		var validation = page.querySelector('[data-ac-validation] b');
		if (validation) {
			validation.textContent = auDessus ? 'Direction · au-dessus de ' + francs(seuil) : 'aucune · sous ' + francs(seuil) + ', elle part dès l\'envoi';
		}
		var bouton = document.querySelector('[data-ac-envoyer]');
		if (bouton) {
			bouton.textContent = auDessus ? bouton.dataset.libelleValider : bouton.dataset.libellePasser;
		}
	};

	// Un article choisi : son prix chez ce fournisseur si le prix est vide.
	corps.addEventListener('change', function (e) {
		if (e.target.matches('[data-ac-article]')) {
			var option = e.target.selectedOptions[0];
			var prix = e.target.closest('tr').querySelector('[data-ac-prix]');
			if (option && option.dataset.prix && entier(prix.value) === 0) {
				prix.value = nombre(parseInt(option.dataset.prix, 10));
			}
		}
		recalculer();
	});
	formulaire.addEventListener('input', recalculer);

	corps.addEventListener('click', function (e) {
		var bouton = e.target.closest('[data-ac-retirer]');
		if (bouton) {
			bouton.closest('tr').remove();
			recalculer();
		}
	});

	formulaire.querySelector('[data-ac-ajouter]').addEventListener('click', function () {
		corps.insertAdjacentHTML('beforeend', modele.innerHTML.replace(/__i__/g, String(compteur++)));
		corps.lastElementChild.querySelector('select').focus();
	});

	recalculer();
})();
