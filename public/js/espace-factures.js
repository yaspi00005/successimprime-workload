/*
 * Espace client › Factures et paiements (maquettes FP01 à FP05), sans
 * dépendance. Tout marche sans JavaScript (liens et formulaires) ; ici :
 *  - la barre « Payer X F par Orange Money » suit les cases cochées ;
 *  - « Payer » ouvre le récapitulatif dans une fenêtre, recalculé par le
 *    serveur quand l'avoir, « Tout payer » ou le montant changent ;
 *  - les onglets Factures, Paiements, Avoirs, Reçus sans recharger ;
 *  - le relevé de compte dans une fenêtre, « Partager » une facture ;
 *  - la page « Paiement en cours » se relit seule tant qu'Orange n'a pas
 *    répondu.
 * Les montants affichés ici ne sont qu'un aperçu : le serveur recalcule
 * tout avant Orange Money.
 */
(function () {
	document.documentElement.classList.add('ef-js');

	var ESPACE = ' ';

	function francs(n) {
		return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ESPACE) + ESPACE + 'F';
	}

	function ajouterParametre(url, cle, valeur) {
		return url + (url.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(cle) + '=' + encodeURIComponent(valeur);
	}

	function urlDuFormulaire(form) {
		var parametres = new URLSearchParams();
		new FormData(form).forEach(function (valeur, cle) { parametres.append(cle, valeur); });
		var action = form.getAttribute('action') || window.location.pathname;
		var requete = parametres.toString();

		return requete ? action + (action.indexOf('?') === -1 ? '?' : '&') + requete : action;
	}

	/* Barre de sélection de « À payer » */
	var selection = document.querySelector('form[data-ef-selection]');
	if (selection) {
		var barre = selection.querySelector('[data-ef-barre]');
		var avoirDisponible = parseInt(selection.getAttribute('data-avoir') || '0', 10) || 0;
		var majBarre = function () {
			if (!barre) { return; }
			var cases = selection.querySelectorAll('input[name="e[]"]:checked');
			var total = 0;
			cases.forEach(function (c) { total += parseInt(c.getAttribute('data-montant') || '0', 10) || 0; });
			var avoir = Math.min(avoirDisponible, total);
			barre.hidden = cases.length === 0;
			barre.querySelector('[data-ef-nombre]').textContent = cases.length + ' sélectionné' + (cases.length > 1 ? 's' : '');
			barre.querySelector('[data-ef-total]').textContent = francs(total);
			var champAvoir = barre.querySelector('[data-ef-avoir]');
			champAvoir.hidden = avoir <= 0;
			champAvoir.textContent = '(− avoir ' + francs(avoir) + ')';
			var bouton = barre.querySelector('button[type="submit"]');
			bouton.textContent = total - avoir > 0 ? 'Payer ' + francs(total - avoir) + ' par Orange Money' : 'Payer avec mon avoir';
		};
		selection.addEventListener('change', majBarre);
		selection.addEventListener('submit', function (e) {
			if (!selection.querySelector('input[name="e[]"]:checked')) {
				e.preventDefault();
				return;
			}
			if (ouvrirPaiement(urlDuFormulaire(selection))) { e.preventDefault(); }
		});
	}

	/* Récapitulatif de paiement : fenêtre de la vue d'ensemble, ou page */
	var fenetre = document.getElementById('ef-paiement');
	var tour = 0;

	function charger(zone, url, apres) {
		var numero = ++tour;
		zone.setAttribute('aria-busy', 'true');
		return fetch(ajouterParametre(url, 'fragment', zone === fenetre ? '1' : 'page'), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
			.then(function (r) {
				// Plus rien à payer, ou session finie : la page complète dit pourquoi.
				if (r.redirected || !r.ok) { window.location.href = r.redirected ? r.url : url; return null; }
				return r.text();
			})
			.then(function (html) {
				if (html === null || numero !== tour) { return; }
				zone.innerHTML = html;
				zone.removeAttribute('aria-busy');
				brancher(zone);
				if (apres) { apres(); }
			})
			.catch(function () { window.location.href = url; });
	}

	function ouvrirPaiement(url) {
		if (!fenetre || typeof fenetre.showModal !== 'function' || !window.fetch) { return false; }
		charger(fenetre, url, function () {
			if (!fenetre.open) { fenetre.showModal(); }
			var bouton = fenetre.querySelector('form[data-ef-envoi] button[type="submit"]');
			// Le bouton prend le focus sans faire défiler : on lit d'abord ce qui est payé, en haut.
			if (bouton && !bouton.disabled) { bouton.focus({preventScroll: true}); }
			fenetre.scrollTop = 0;
		});
		return true;
	}

	function brancher(zone) {
		var form = zone.querySelector('form[data-ef-recalcul]');
		if (form) {
			var attente = null;
			var recalculer = function (focus) {
				var actif = document.activeElement;
				var nom = focus || (actif && form.contains(actif) ? actif.getAttribute('name') : null);
				var curseur = actif && actif.name === 'montant' ? actif.selectionStart : null;
				charger(zone, urlDuFormulaire(form), function () {
					if (!nom) { return; }
					var champ = zone.querySelector('form[data-ef-recalcul] [name="' + nom + '"]' + (nom === 'mode' ? ':checked' : ''));
					if (champ) {
						champ.focus();
						if (curseur !== null && typeof champ.setSelectionRange === 'function') {
							try { champ.setSelectionRange(curseur, curseur); } catch (e) { /* champ sans curseur */ }
						}
					}
				});
			};
			form.addEventListener('change', function (e) {
				if (e.target.name === 'montant') { return; }
				if (e.target.name === 'mode') {
					zone.querySelectorAll('[data-ef-montant]').forEach(function (el) { el.classList.toggle('replie', e.target.value !== 'partiel'); });
					// « Autre montant » : le curseur va dans le montant.
					recalculer(e.target.value === 'partiel' ? 'montant' : null);
					return;
				}
				recalculer();
			});
			form.addEventListener('input', function (e) {
				if (e.target.name !== 'montant') { return; }
				clearTimeout(attente);
				attente = setTimeout(function () { recalculer('montant'); }, 650);
			});
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				clearTimeout(attente);
				recalculer();
			});
		}
		var envoi = zone.querySelector('form[data-ef-envoi]');
		if (envoi) {
			envoi.addEventListener('submit', function () {
				var bouton = envoi.querySelector('button[type="submit"]');
				// Un seul départ vers Orange Money ; le serveur refuse de toute façon un double règlement.
				setTimeout(function () { bouton.disabled = true; bouton.textContent = 'Un instant…'; }, 0);
			});
		}
	}

	var page = document.querySelector('[data-ef-zone="page"]');
	if (page && window.fetch) { brancher(page); }

	document.addEventListener('click', function (e) {
		var lien = e.target.closest('a[data-ef-payer]');
		if (lien && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
			if (ouvrirPaiement(lien.href)) { e.preventDefault(); }
			return;
		}
		var fermer = e.target.closest('[data-ef-fermer]');
		if (fermer) {
			var dialogue = fermer.closest('dialog');
			if (dialogue) { e.preventDefault(); dialogue.close(); }
			return;
		}
		var ouvrir = e.target.closest('[data-ef-ouvrir]');
		if (ouvrir) {
			var cible = document.getElementById(ouvrir.getAttribute('data-ef-ouvrir'));
			if (cible && typeof cible.showModal === 'function') { e.preventDefault(); cible.showModal(); }
			return;
		}
		// Un clic sur le fond d'une fenêtre la ferme.
		if (e.target.tagName === 'DIALOG' && e.target.open) {
			var r = e.target.getBoundingClientRect();
			if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) { e.target.close(); }
		}
	});

	if (fenetre) {
		fenetre.addEventListener('close', function () { ++tour; fenetre.innerHTML = ''; });
	}

	var releve = document.getElementById('ef-releve');
	if (releve) {
		releve.querySelector('form').addEventListener('submit', function () {
			setTimeout(function () { releve.close(); }, 0);
		});
	}

	/* Onglets Factures, Paiements, Avoirs, Reçus */
	var onglets = document.querySelectorAll('[data-ef-onglet]');
	onglets.forEach(function (lien) {
		lien.addEventListener('click', function (e) {
			var cle = lien.getAttribute('data-ef-onglet');
			var panneau = document.querySelector('[data-ef-panneau="' + cle + '"]');
			if (!panneau) { return; }
			e.preventDefault();
			onglets.forEach(function (o) {
				var actif = o === lien;
				o.classList.toggle('actif', actif);
				if (actif) { o.setAttribute('aria-current', 'true'); } else { o.removeAttribute('aria-current'); }
			});
			document.querySelectorAll('[data-ef-panneau]').forEach(function (p) { p.hidden = p !== panneau; });
			var champ = document.querySelector('[data-ef-onglet-champ]');
			if (champ) { champ.value = cle; }
			try { history.replaceState(null, '', lien.getAttribute('href')); } catch (err) { /* adresse inchangée */ }
		});
	});
	var annee = document.querySelector('[data-ef-annee]');
	if (annee) {
		annee.addEventListener('change', function () { annee.form.submit(); });
	}

	/* Partager une facture : le lien de la page (connexion nécessaire), sans montant */
	var partager = document.querySelector('[data-ef-partager]');
	if (partager) {
		var note = document.querySelector('[data-ef-partage-note]');
		var dire = function (texte) {
			if (!note) { return; }
			note.textContent = texte;
			note.hidden = false;
		};
		if (navigator.share || (navigator.clipboard && window.isSecureContext)) {
			partager.hidden = false;
		}
		partager.addEventListener('click', function () {
			var url = partager.getAttribute('data-url');
			if (navigator.share) {
				navigator.share({title: partager.getAttribute('data-titre'), text: partager.getAttribute('data-texte'), url: url}).catch(function () { /* partage annulé */ });
				return;
			}
			navigator.clipboard.writeText(url).then(function () {
				dire('Lien copié. Il s\'ouvre après connexion à l\'espace client, pour une personne qui a le droit de voir les factures.');
			}, function () { dire(url); });
		});
	}

	/* Paiement en cours chez Orange Money : la page se relit seule, un moment. */
	if (document.querySelector('[data-ef-actualiser]')) {
		var fois = parseInt(new URLSearchParams(window.location.search).get('n') || '0', 10) || 0;
		if (fois < 15) {
			setTimeout(function () {
				window.location.replace(window.location.pathname + '?n=' + (fois + 1));
			}, 8000);
		}
	}
})();
