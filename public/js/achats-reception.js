/*
 * Réception d'une commande d'achat (maquette SU05, docs/achats.md) :
 * recalcule à la saisie le badge d'écart de chaque ligne, la réclamation
 * au fournisseur, l'écart des frais réels, le coût de revient mis à
 * jour, l'entrée en stock, l'avertissement du contrôle à trois points et
 * la ligne de livraison. Mêmes règles que CalculsReception côté serveur,
 * qui refait tout à la validation. Montre aussi la photo choisie.
 */
(function () {
	'use strict';

	var page = document.querySelector('[data-ac-reception]');
	if (!page) {
		return;
	}

	// Après la validation : la photo choisie part tout de suite sur la réclamation ouverte.
	var auto = document.querySelector('[data-ac-photo-auto]');
	if (auto) {
		auto.addEventListener('change', function () {
			if (auto.files.length) { auto.form.submit(); }
		});
	}

	// WhatsApp : le lien wa.me s'ouvre, et l'envoi est noté sur la réclamation.
	Array.prototype.forEach.call(document.querySelectorAll('a[data-ac-whatsapp]'), function (lien) {
		lien.addEventListener('click', function () {
			var donnees = new FormData();
			donnees.append('_token', lien.dataset.jeton);
			fetch(lien.dataset.acWhatsapp, { method: 'POST', body: donnees, credentials: 'same-origin', keepalive: true, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function () { window.setTimeout(function () { window.location.reload(); }, 800); })
				.catch(function () {});
		});
	});

	var formulaire = document.getElementById('reception');
	if (!formulaire) {
		return;
	}

	var finances = page.dataset.finances === '1';
	var entier = function (v) { return parseInt(String(v || '').replace(/\D+/g, ''), 10) || 0; };
	var nombre = function (n, decimales) {
		var arrondi = Math.round(n * Math.pow(10, decimales || 0)) / Math.pow(10, decimales || 0);
		return arrondi.toLocaleString('fr-FR', { maximumFractionDigits: decimales || 0 }).replace(/[  ]/g, ' ');
	};
	var quantite = function (n) { return nombre(n, Math.abs(n - Math.round(n)) < 0.001 ? 0 : 1); };
	var francs = function (n) { return nombre(n) + ' F'; };
	var pluriel = function (mot, n) {
		if (Math.abs(n) < 2) { return mot; }
		if (/eau$/.test(mot)) { return mot + 'x'; }
		return /[sx]$/.test(mot) ? mot : mot + 's';
	};
	var feminines = ['plaque', 'unité', 'boîte', 'bobine', 'feuille', 'pièce', 'cartouche', 'palette', 'caisse'];
	var feminin = function (unite) { return feminines.indexOf(String(unite || '').toLowerCase()) !== -1; };
	var accorder = function (adjectif, n, fem) {
		if (fem && /[éèt]$/.test(adjectif)) { adjectif += 'e'; }
		return n > 1 && !/[sx]$/.test(adjectif) ? adjectif + 's' : adjectif;
	};
	var minuscule = function (t) { return t.charAt(0).toLowerCase() + t.slice(1); };

	// Parts de frais par ligne, comme CalculsAchat::repartirFrais.
	var repartir = function (lignes, frais) {
		var unites = lignes.map(function (l) { return String(l.unite).toLowerCase(); });
		var memeUnite = unites.every(function (u) { return u === unites[0]; });
		var poids = lignes.map(function (l) { return Math.max(0, memeUnite ? l.recueStock : l.montantRecu); });
		var total = poids.reduce(function (a, b) { return a + b; }, 0);
		if (frais <= 0 || total <= 0) { return lignes.map(function () { return 0; }); }
		var reparti = 0;
		return poids.map(function (p, i) {
			var part = i === poids.length - 1 ? frais - reparti : Math.round(frais * p / total);
			reparti += part;
			return part;
		});
	};

	// Badge d'écart, comme CalculsReception::ecart.
	var ecart = function (l) {
		var abimees = Math.max(0, l.recue - l.bon);
		var parts = [];
		if (abimees > 0) {
			parts.push(abimees + ' ' + pluriel(l.uniteAchat || 'unité', abimees) + ' ' + accorder(l.motif || 'abîmé', abimees, feminin(l.uniteAchat)));
		}
		if (l.recue < l.commande) { parts.push('manque ' + (l.commande - l.recue)); }
		else if (l.recue > l.commande) { parts.push('en trop ' + (l.recue - l.commande)); }
		if (!parts.length) { return { libelle: 'conforme', classe: 'ok' }; }
		return { libelle: parts.join(' · '), classe: abimees > 0 || l.recue < l.commande ? 'retard' : 'attente' };
	};

	// Raison du blocage, comme CalculsReception::motifBlocage.
	var motifBlocage = function (lignes) {
		var phrases = [];
		lignes.forEach(function (l) {
			if (l.facturee - l.bon <= 0) { return; }
			var fem = feminin(l.uniteAchat);
			var abimees = Math.max(0, l.recue - l.bon);
			var raison;
			if (abimees > 0 && l.bon + abimees >= l.facturee) {
				raison = abimees === 1 ? (fem ? 'qu’une est abîmée' : 'qu’un est abîmé') : 'que ' + abimees + ' sont abîmé' + (fem ? 'e' : '') + 's';
			} else if (l.bon === 0) {
				raison = fem ? 'qu’aucune n’est arrivée en bon état' : 'qu’aucun n’est arrivé en bon état';
			} else {
				raison = 'que seulement ' + l.bon + ' ' + (l.bon > 1 ? 'sont arrivé' + (fem ? 'es' : 's') : 'est arrivé' + (fem ? 'e' : '')) + ' en bon état';
			}
			phrases.push(l.facturee + ' ' + pluriel(l.uniteAchat || 'unité', l.facturee) + ' de ' + minuscule(l.nom) + ' alors ' + raison);
		});
		return phrases.length ? 'La facture compte ' + phrases.join(' ; ') + ' : paiement du solde bloqué jusqu’à l’avoir du fournisseur.' : null;
	};

	var lignes = Array.prototype.map.call(formulaire.querySelectorAll('[data-ac-r-ligne]'), function (tr) {
		return {
			tr: tr,
			commande: entier(tr.dataset.commande),
			prix: entier(tr.dataset.prix),
			facteur: parseFloat(tr.dataset.facteur) || 1,
			unite: tr.dataset.unite,
			uniteAchat: tr.dataset.uniteAchat,
			nom: tr.dataset.nom,
			stock: tr.dataset.stock === '' ? null : parseFloat(tr.dataset.stock),
			coutPrevu: tr.dataset.coutPrevu === '' ? null : entier(tr.dataset.coutPrevu),
			champs: {
				recue: tr.querySelector('[data-ac-r-recue]'),
				bon: tr.querySelector('[data-ac-r-bon]'),
				facturee: tr.querySelector('[data-ac-r-facturee]'),
				motif: tr.querySelector('[data-ac-r-motif]')
			}
		};
	});

	var fraisPrevus = { transport: entier(page.dataset.transportPrevu), transit: entier(page.dataset.transitPrevu) };

	var recalculer = function () {
		var reclamations = [];

		lignes.forEach(function (l, i) {
			l.recue = l.champs.recue.value.trim() === '' ? l.commande : entier(l.champs.recue.value);
			l.bon = l.champs.bon.value.trim() === '' ? l.recue : Math.min(l.recue, entier(l.champs.bon.value));
			l.facturee = l.champs.facturee.value.trim() === '' ? l.commande : entier(l.champs.facturee.value);
			l.motif = l.champs.motif ? l.champs.motif.value.trim() : '';
			l.recueStock = Math.round(l.recue * l.facteur * 1000) / 1000;
			l.bonStock = Math.round(l.bon * l.facteur * 1000) / 1000;
			l.montantRecu = l.recue * l.prix;

			var badge = ecart(l);
			var el = l.tr.querySelector('[data-ac-r-ecart]');
			el.textContent = badge.libelle;
			el.className = 'ac-badge ' + badge.classe;
			l.champs.bon.classList.toggle('ac-rouge', l.bon < l.recue);
			if (l.champs.motif) { l.champs.motif.hidden = !(l.bon < l.recue); }

			var reclamer = Math.max(0, l.facturee - l.bon);
			if (reclamer > 0) {
				reclamations.push(reclamer + ' ' + pluriel(l.uniteAchat || 'unité', reclamer) + ' de ' + minuscule(l.nom) + ' à remplacer ou à déduire' + (finances ? ' (' + francs(reclamer * l.prix) + ')' : ''));
			}

			// Entrée en stock : seules les unités en bon état.
			var bloc = page.querySelector('[data-ac-r-entree="' + i + '"]');
			if (bloc) {
				bloc.querySelector('[data-ac-r-plus]').textContent = '+ ' + quantite(l.bonStock) + ' ' + l.unite;
				if (l.stock !== null) {
					bloc.querySelector('[data-ac-r-avant-apres]').textContent = quantite(l.stock) + ' → ' + quantite(l.stock + l.bonStock) + ' ' + l.unite;
				}
			}
		});

		// Frais réels : l'écart avec le prévu, puis le coût de revient sur les unités en bon état.
		var reels = {};
		['transport', 'transit'].forEach(function (cle) {
			var champ = formulaire.querySelector('[data-ac-r-frais="' + cle + '"]');
			reels[cle] = champ && champ.value.trim() !== '' ? entier(champ.value) : fraisPrevus[cle];
			var diff = reels[cle] - fraisPrevus[cle];
			var cellule = page.querySelector('[data-ac-r-ecart-frais="' + cle + '"]');
			if (cellule) {
				cellule.textContent = diff === 0 ? '0' : (diff > 0 ? '+ ' : '− ') + francs(Math.abs(diff));
				cellule.className = 'num fw-bold ' + (diff > 0 ? 'ac-rouge' : 'ac-vert');
			}
		});
		var transport = repartir(lignes, reels.transport);
		var transit = repartir(lignes, reels.transit);
		var phrases = [];
		lignes.forEach(function (l, i) {
			var cout = l.bonStock > 0 ? Math.round((l.bon * l.prix + transport[i] + transit[i]) / l.bonStock) : null;
			if (cout !== null && l.coutPrevu !== null && cout !== l.coutPrevu) {
				phrases.push('Coût de revient mis à jour · ' + l.nom.toLowerCase() + ' : <b>' + nombre(l.coutPrevu) + ' → ' + nombre(cout) + ' F/' + l.unite + '</b>.');
			}
		});
		var cout = page.querySelector('[data-ac-r-cout]');
		if (cout) {
			cout.innerHTML = phrases.length ? phrases.join(' ') : 'Les frais réels remplacent les frais prévus : le coût de revient se recalcule, réparti sur les unités arrivées en bon état.';
		}

		// Casse et réclamation.
		var casse = page.querySelector('[data-ac-casse]');
		if (casse) {
			casse.hidden = reclamations.length === 0;
			page.querySelector('[data-ac-reclamation-texte]').textContent = reclamations.join(' ; ');
		}
		var legende = page.querySelector('[data-ac-photo-legende]');
		var abimee = lignes.filter(function (l) { return l.bon < l.recue; })[0];
		if (legende && abimee) { legende.textContent = (abimee.uniteAchat || '') + ' ' + (abimee.motif || 'abîmé'); }

		// Contrôle à trois points : la facture compte plus que le bon état.
		var blocage = page.querySelector('[data-ac-blocage]');
		var motif = motifBlocage(lignes);
		if (blocage) {
			blocage.hidden = motif === null;
			blocage.textContent = motif || '';
		}
		var cocheFacture = page.querySelector('[data-ac-coche-facture]');
		var numeroFacture = formulaire.querySelector('[data-ac-r-facture]');
		if (cocheFacture) {
			var rempli = numeroFacture && numeroFacture.value.trim() !== '';
			var etat = !rempli ? 'vide' : (motif ? 'alerte' : 'ok');
			cocheFacture.className = 'ac-coche ' + etat;
			cocheFacture.innerHTML = '<i class="fas ' + (etat === 'vide' ? 'fa-minus' : (etat === 'alerte' ? 'fa-exclamation' : 'fa-check')) + '" aria-hidden="true"></i>';
		}
		var cocheBl = page.querySelector('[data-ac-coche-bl]');
		var numeroBl = formulaire.querySelector('[data-ac-r-bl]');
		if (cocheBl && numeroBl) {
			var bl = numeroBl.value.trim() !== '';
			cocheBl.className = 'ac-coche ' + (bl ? 'ok' : 'vide');
			cocheBl.innerHTML = '<i class="fas ' + (bl ? 'fa-check' : 'fa-minus') + '" aria-hidden="true"></i>';
		}

		// Livraison, comme CalculsReception::texteLivraison.
		var date = formulaire.querySelector('[data-ac-r-date]');
		var livraison = page.querySelector('[data-ac-r-livraison]');
		if (date && livraison && date.value) {
			var arrivee = new Date(date.value + 'T00:00:00');
			var texte = 'Livraison : ' + date.value.slice(8, 10) + '/' + date.value.slice(5, 7);
			if (page.dataset.promise) {
				var retard = Math.round((arrivee - new Date(page.dataset.promise + 'T00:00:00')) / 86400000);
				texte += retard === 0 ? ', le jour promis' : (retard > 0 ? ', ' + retard + ' jour' + (retard > 1 ? 's' : '') + ' après la date promise' : ', ' + (-retard) + ' jour' + (retard < -1 ? 's' : '') + ' avant la date promise');
			}
			livraison.textContent = texte + '. Compté dans la performance de ' + page.dataset.fournisseur + '.';
		}
	};

	formulaire.addEventListener('input', recalculer);
	formulaire.addEventListener('change', recalculer);

	// Aperçu de la photo choisie (le serveur vérifie type et taille).
	var photo = formulaire.querySelector('[data-ac-photo]');
	if (photo) {
		photo.addEventListener('change', function () {
			var fichier = photo.files[0];
			var apercu = page.querySelector('[data-ac-photo-apercu]');
			var vide = page.querySelector('[data-ac-photo-vide]');
			var legende = page.querySelector('[data-ac-photo-legende]');
			if (!fichier) { return; }
			if (fichier.size > (parseInt(photo.dataset.taille, 10) || Infinity)) {
				window.alert('Photo trop lourde : ' + Math.round(parseInt(photo.dataset.taille, 10) / 1048576) + ' Mo au plus.');
				photo.value = '';
				return;
			}
			apercu.src = URL.createObjectURL(fichier);
			apercu.hidden = false;
			vide.hidden = true;
			legende.hidden = false;
		});
	}

	recalculer();
}());
