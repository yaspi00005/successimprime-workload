/*
 * Équipe › Messagerie (docs/messagerie-interne.md, maquettes MI01 à MI05) :
 * fenêtres, rafraîchissement léger, envoi, @mentions, réponses, réactions,
 * commandes citées, envoi de plusieurs fichiers par morceaux (progression
 * par fichier, reprise), fichiers du dossier du client partagés en lien.
 */
(function () {
	'use strict';

	var racine = document.getElementById('mi');

	function el(tag, classe, texte) {
		var e = document.createElement(tag);
		if (classe) { e.className = classe; }
		if (texte !== undefined) { e.textContent = texte; }
		return e;
	}
	function taille(o) {
		if (o >= 1048576) { var mo = o / 1048576; return (mo >= 10 ? Math.round(mo) : Math.round(mo * 10) / 10).toString().replace('.', ',') + ' Mo'; }
		return Math.max(1, Math.round(o / 1024)) + ' Ko';
	}
	function json(r) {
		return r.json().catch(function () { return {}; }).then(function (j) {
			if (!r.ok) { throw new Error(j.erreur || ('Erreur ' + r.status + ' : rechargez la page, puis réessayez.')); }
			return j;
		});
	}
	function attendre(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
	function stocker(cle, valeur) { try { if (valeur === null) { sessionStorage.removeItem(cle); } else { sessionStorage.setItem(cle, valeur); } } catch (e) { /* stockage indisponible : pas de reprise après rechargement */ } }
	function lire(cle) { try { return sessionStorage.getItem(cle); } catch (e) { return null; } }

	// ---------------------------------------------------------------- fenêtres
	document.addEventListener('click', function (e) {
		var o = e.target.closest('[data-ouvrir]');
		if (o) {
			var d = document.getElementById(o.dataset.ouvrir);
			if (d && d.showModal) { d.showModal(); }
		}
		var f = e.target.closest('[data-fermer]');
		if (f && f.closest('dialog')) { f.closest('dialog').close(); }
	});
	document.querySelectorAll('dialog.mi-dialogue').forEach(function (d) {
		d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
	});

	// Recherche d'un client, d'un devis ou d'une commande (/liaisons).
	function rechercheLiaison(champ, liste, options, choisir) {
		var minuteur = null;
		champ.addEventListener('input', function () {
			clearTimeout(minuteur);
			var q = champ.value.trim();
			if (q.length < 2) { liste.hidden = true; return; }
			minuteur = setTimeout(function () {
				var url = new URL(racine ? racine.dataset.liaisonsUrl : champ.dataset.url, location.href);
				url.searchParams.set('q', q);
				if (options.clients) { url.searchParams.set('clients', '1'); }
				fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(json).then(function (j) {
					liste.textContent = '';
					var res = (j.resultats || []).filter(function (r) { return !options.genre || r.genre === options.genre; });
					res.forEach(function (r) {
						var li = el('li', '', r.libelle);
						li.addEventListener('click', function () { liste.hidden = true; choisir(r); });
						liste.appendChild(li);
					});
					if (!res.length) { liste.appendChild(el('li', 'mi-vide petit', options.vide || 'Rien trouvé.')); }
					liste.hidden = false;
				}).catch(function () { liste.hidden = true; });
			}, 250);
		});
	}

	// ---------------------------------------------------------------- nouveau groupe (MI02)
	var dlgGroupe = document.getElementById('mi-dlg-groupe');
	if (dlgGroupe) {
		var majType = function () {
			var t = dlgGroupe.querySelector('input[name="type"]:checked');
			dlgGroupe.querySelectorAll('[data-si-type]').forEach(function (b) { b.hidden = !t || t.value !== b.dataset.siType; });
		};
		dlgGroupe.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', majType); });
		majType();
		var q = dlgGroupe.querySelector('.mi-liaison-q');
		if (q && racine) {
			var choisie = dlgGroupe.querySelector('.mi-liaison-choisie');
			rechercheLiaison(q, dlgGroupe.querySelector('.mi-propositions'), {vide: 'Aucun client, devis ou commande (ou pas le droit de les voir).'}, function (r) {
				dlgGroupe.querySelector('.mi-liaison-valeur').value = r.valeur;
				choisie.textContent = '✓ ' + {client: 'Client', devis: 'Devis', commande: 'Commande'}[r.genre] + ' : ' + r.libelle;
				choisie.hidden = false;
				q.value = '';
				var nom = dlgGroupe.querySelector('input[name="nom"]');
				if (!nom.value) { nom.value = 'Projet ' + r.libelle.split(' · ')[0]; }
			});
		}
	}

	if (!racine || !racine.dataset.jeton) { return; }

	// ================================================================ fil ouvert
	var d = racine.dataset;
	var messages = document.getElementById('mi-messages');
	var composeur = document.getElementById('mi-composeur');
	var membres = [];
	try { membres = Object.values(JSON.parse(d.membres || '[]')); } catch (e) { membres = []; }

	function enBas() { messages.scrollTop = messages.scrollHeight; }
	function versLeMessage() {
		var cible = location.hash && document.querySelector(location.hash.replace(/[^#\w-]/g, ''));
		if (cible && cible.classList.contains('mi-msg')) { cible.scrollIntoView({block: 'center'}); cible.classList.add('surligne'); } else { enBas(); }
	}
	versLeMessage();
	window.addEventListener('hashchange', versLeMessage);

	// Ajoute des messages rendus par le serveur (sans doublon ni séparateur de jour répété).
	function ajouter(html, dernier) {
		if (!html) { return; }
		var bas = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
		var t = document.createElement('template');
		t.innerHTML = html;
		var jours = messages.querySelectorAll('.mi-jour');
		var dernierJour = jours.length ? jours[jours.length - 1].dataset.jour : null;
		Array.prototype.slice.call(t.content.children).forEach(function (n) {
			if (n.dataset.id && document.getElementById('m' + n.dataset.id)) { return; }
			if (n.classList.contains('mi-jour')) { if (n.dataset.jour === dernierJour) { return; } dernierJour = n.dataset.jour; }
			messages.appendChild(n);
		});
		var vide = messages.querySelector(':scope > .mi-vide');
		if (vide) { vide.remove(); }
		if (dernier && dernier > (parseInt(messages.dataset.dernier, 10) || 0)) { messages.dataset.dernier = dernier; }
		if (bas) { enBas(); }
	}

	// ---------------------------------------------------------------- rafraîchissement léger
	var enCours = false;
	function rafraichir() {
		if (enCours) { return; }
		enCours = true;
		var url = new URL(d.nouveauxUrl, location.href);
		url.searchParams.set('apres', messages.dataset.dernier || '0');
		fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(json).then(function (j) {
			ajouter(j.html, j.dernier);
			Object.keys(j.reactions || {}).forEach(function (id) {
				var ancien = messages.querySelector('[data-reactions="' + id + '"]');
				if (ancien && ancien.outerHTML !== j.reactions[id].trim()) { ancien.outerHTML = j.reactions[id]; }
			});
			document.querySelectorAll('.mi-fil-ligne[data-jeton]').forEach(function (a) {
				var b = a.querySelector('[data-non-lus]');
				var n = a.dataset.jeton === d.jeton ? 0 : ((j.nonLus || {})[a.dataset.jeton] || 0);
				b.textContent = n;
				b.hidden = n === 0;
			});
		}).catch(function () { /* hors ligne un instant : le prochain passage rattrape */ }).finally(function () { enCours = false; });
	}
	var intervalle = null;
	function programmer() {
		clearInterval(intervalle);
		intervalle = setInterval(rafraichir, document.hidden ? 30000 : 5000);
	}
	document.addEventListener('visibilitychange', function () { programmer(); if (!document.hidden) { rafraichir(); } });
	programmer();

	// ---------------------------------------------------------------- panneau droit
	document.querySelectorAll('[data-panneau]').forEach(function (b) {
		b.addEventListener('click', function () { racine.classList.toggle('panneau-ouvert'); });
	});
	document.querySelectorAll('.mi-cote [data-onglet]').forEach(function (b) {
		b.addEventListener('click', function () {
			document.querySelectorAll('.mi-cote [data-onglet]').forEach(function (x) { x.classList.toggle('actif', x === b); x.setAttribute('aria-selected', String(x === b)); });
			document.querySelectorAll('.mi-cote [data-contenu]').forEach(function (c) { c.hidden = c.dataset.contenu !== b.dataset.onglet; });
		});
	});

	// ---------------------------------------------------------------- réactions
	var palette = document.getElementById('mi-emojis');
	var paletteMessage = null;
	function reagir(id, emoji) {
		var corps = new FormData();
		corps.append('_token', d.csrf);
		corps.append('emoji', emoji);
		fetch(d.reactionUrl.replace(/\/0$/, '/' + id), {method: 'POST', body: corps, credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(json).then(function (j) {
			var ancien = messages.querySelector('[data-reactions="' + id + '"]');
			if (ancien) { ancien.outerHTML = j.html; }
		}).catch(function (e) { alert(e.message); });
	}
	messages.addEventListener('click', function (e) {
		var msg = e.target.closest('.mi-msg');
		if (!msg) { return; }
		var r = e.target.closest('[data-reagir]');
		if (r) { reagir(msg.dataset.id, r.dataset.reagir); return; }
		if (e.target.closest('[data-emojis]') && palette) {
			paletteMessage = msg.dataset.id;
			var b = e.target.closest('[data-emojis]').getBoundingClientRect(), f = racine.querySelector('.mi-fil').getBoundingClientRect();
			palette.hidden = false;
			palette.style.top = (b.bottom - f.top + 4) + 'px';
			palette.style.left = Math.max(8, Math.min(b.right - f.left - palette.offsetWidth, f.width - palette.offsetWidth - 8)) + 'px';
			return;
		}
		if (e.target.closest('[data-repondre]') && composeur) {
			composeur.querySelector('input[name="reponse"]').value = msg.dataset.id;
			var bloc = composeur.querySelector('.mi-reponse');
			bloc.querySelector('span').textContent = 'Réponse à ' + msg.dataset.auteur + ' : ' + msg.dataset.apercu;
			bloc.hidden = false;
			composeur.querySelector('textarea').focus();
		}
	});
	if (palette) {
		palette.addEventListener('click', function (e) {
			var b = e.target.closest('[data-emoji]');
			if (b && paletteMessage) { reagir(paletteMessage, b.dataset.emoji); }
			palette.hidden = true;
		});
		document.addEventListener('click', function (e) { if (!e.target.closest('#mi-emojis') && !e.target.closest('[data-emojis]')) { palette.hidden = true; } });
	}

	if (!composeur) { return; }

	// ================================================================ composeur
	var texte = composeur.querySelector('textarea');
	var erreur = composeur.querySelector('.mi-erreur');
	var choisis = {};
	function dire(zone, message) { zone.textContent = message || ''; zone.hidden = !message; }

	function envoyer(champs) {
		var corps = new FormData();
		corps.append('_token', d.csrf);
		corps.append('texte', champs.texte || '');
		corps.append('reponse', composeur.querySelector('input[name="reponse"]').value || '');
		Object.keys(choisis).forEach(function (id) { corps.append('mentions[]', id); });
		(champs.fichiers || []).forEach(function (j) { corps.append('fichiers[]', j); });
		(champs.liens || []).forEach(function (l) { corps.append('liens[]', l); });
		if (champs.ranger) { corps.append('ranger', '1'); }
		return fetch(d.envoyerUrl, {method: 'POST', body: corps, credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(json).then(function (j) {
			ajouter(j.html, j.dernier);
			enBas();
			choisis = {};
			composeur.querySelector('input[name="reponse"]').value = '';
			composeur.querySelector('.mi-reponse').hidden = true;
			if (j.avertissements && j.avertissements.length) { dire(erreur, j.avertissements.join(' ')); }
			return j;
		});
	}

	composeur.addEventListener('submit', function (e) {
		e.preventDefault();
		var t = texte.value.trim();
		if (!t) { texte.focus(); return; }
		var bouton = composeur.querySelector('.mi-envoyer');
		bouton.disabled = true;
		dire(erreur, '');
		envoyer({texte: t}).then(function () { texte.value = ''; texte.style.height = ''; }).catch(function (x) { dire(erreur, x.message); }).finally(function () { bouton.disabled = false; texte.focus(); });
	});
	texte.addEventListener('keydown', function (e) {
		if (!mentions.hidden && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].indexOf(e.key) >= 0) { naviguer(e); return; }
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(min-width: 768px)').matches) { e.preventDefault(); composeur.requestSubmit(); }
	});
	texte.addEventListener('input', function () {
		texte.style.height = 'auto';
		texte.style.height = Math.min(texte.scrollHeight, 160) + 'px';
		proposer();
	});
	composeur.querySelector('[data-annuler-reponse]').addEventListener('click', function () {
		composeur.querySelector('input[name="reponse"]').value = '';
		composeur.querySelector('.mi-reponse').hidden = true;
	});
	var plus = composeur.querySelector('[data-outils]');
	if (plus) { plus.addEventListener('click', function () { composeur.classList.toggle('outils-ouverts'); }); }

	// ---------------------------------------------------------------- @mentions
	var mentions = composeur.querySelector('.mi-mentions');
	var choix = [], position = 0;
	function cle(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
	function proposer() {
		var avant = texte.value.slice(0, texte.selectionStart);
		var m = avant.match(/(^|[^\p{L}\p{N}@])@([\p{L}\p{N}._-]{0,40})$/u);
		if (!m || !membres.length) { mentions.hidden = true; return; }
		var q = cle(m[2]);
		choix = membres.filter(function (p) { return cle(p.nom).split(/\s+/).some(function (mot) { return mot.indexOf(q) === 0; }) || cle(p.pseudo).indexOf('@' + q) === 0; }).slice(0, 8);
		if (!choix.length) { mentions.hidden = true; return; }
		position = 0;
		mentions.textContent = '';
		choix.forEach(function (p, i) {
			var li = el('li', '', p.nom);
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', String(i === 0));
			li.addEventListener('mousedown', function (e) { e.preventDefault(); inserer(p); });
			mentions.appendChild(li);
		});
		mentions.hidden = false;
	}
	function inserer(p) {
		var avant = texte.value.slice(0, texte.selectionStart).replace(/@([\p{L}\p{N}._-]{0,40})$/u, p.pseudo + ' ');
		texte.value = avant + texte.value.slice(texte.selectionStart);
		texte.setSelectionRange(avant.length, avant.length);
		choisis[p.id] = true;
		mentions.hidden = true;
		texte.focus();
	}
	function naviguer(e) {
		e.preventDefault();
		if (e.key === 'Escape') { mentions.hidden = true; return; }
		if (e.key === 'Enter' || e.key === 'Tab') { inserer(choix[position]); return; }
		position = (position + (e.key === 'ArrowDown' ? 1 : choix.length - 1)) % choix.length;
		mentions.querySelectorAll('li').forEach(function (li, i) { li.setAttribute('aria-selected', String(i === position)); });
	}
	texte.addEventListener('blur', function () { setTimeout(function () { mentions.hidden = true; }, 150); });

	// ---------------------------------------------------------------- commande citée
	var btnCommande = composeur.querySelector('[data-commande]');
	var blocCommande = composeur.querySelector('.mi-commande');
	if (btnCommande && blocCommande) {
		var champCmd = blocCommande.querySelector('input');
		btnCommande.addEventListener('click', function () { blocCommande.hidden = !blocCommande.hidden; if (!blocCommande.hidden) { champCmd.focus(); } });
		rechercheLiaison(champCmd, blocCommande.querySelector('.mi-propositions'), {genre: 'commande', vide: 'Aucune commande trouvée : tapez son numéro (CMD-…) dans le message.'}, function (r) {
			var debut = texte.selectionStart, ajout = (debut > 0 && !/\s$/.test(texte.value.slice(0, debut)) ? ' ' : '') + (r.numero || '') + ' ';
			texte.value = texte.value.slice(0, debut) + ajout + texte.value.slice(debut);
			blocCommande.hidden = true;
			champCmd.value = '';
			texte.focus();
		});
	}

	// ---------------------------------------------------------------- note vocale (public/js/note-vocale.js)
	composeur.addEventListener('mi:vocale', function (e) { ajouter(e.detail.html, e.detail.dernier); enBas(); });

	// ================================================================ envoi de fichiers (MI03)
	var dlg = document.getElementById('mi-dlg-envoi');
	if (!dlg) { return; }
	var liste = dlg.querySelector('.mi-envois');
	var erreurEnvoi = dlg.querySelector('.mi-erreur');
	var boutonEnvoi = dlg.querySelector('[data-envoyer-fichiers]');
	var morceau = parseInt(d.morceau, 10) || 5242880;
	var max = parseInt(d.tailleMax, 10) || 52428800;
	var elements = [];

	function majBouton() {
		var prets = elements.filter(function (x) { return x.etat === 'fini' || x.etat === 'lien'; }).length;
		var attente = elements.some(function (x) { return x.etat === 'envoi'; });
		boutonEnvoi.disabled = !prets || attente;
		boutonEnvoi.textContent = attente ? 'Envoi en cours…' : 'Envoyer ' + prets + ' fichier' + (prets > 1 ? 's' : '');
		dlg.querySelector('h2').textContent = elements.length ? 'Envoyer ' + elements.length + ' fichier' + (elements.length > 1 ? 's' : '') : 'Envoyer des fichiers';
	}
	function ligne(nom, octets, classe) {
		var li = el('li', 'mi-envoi ' + (classe || ''));
		var ext = (nom.split('.').pop() || '').toLowerCase();
		li.appendChild(el('span', 'mi-ext ext-' + ext, ext.toUpperCase().slice(0, 4) || '?'));
		var corps = el('span');
		corps.style.minWidth = '0';
		corps.style.display = 'flex';
		corps.style.flexDirection = 'column';
		corps.appendChild(el('b', '', nom));
		var info = el('small', '', taille(octets));
		corps.appendChild(info);
		li.appendChild(corps);
		var retirer = el('button', '', '×');
		retirer.type = 'button';
		retirer.setAttribute('aria-label', 'Retirer ' + nom);
		li.appendChild(retirer);
		var barre = el('span', 'mi-barre');
		barre.appendChild(el('i'));
		li.appendChild(barre);
		liste.appendChild(li);
		return {li: li, info: info, barre: barre.firstChild, retirer: retirer};
	}

	function envoyerFichier(item) {
		var f = item.fichier;
		var cleReprise = 'mi-envoi:' + f.name + ':' + f.size + ':' + f.lastModified;
		var entetes = {'X-CSRF-TOKEN': d.envoiJeton, Accept: 'application/json'};
		var departs = function () {
			var jeton = lire(cleReprise);
			if (jeton) {
				return fetch(d.envoiUrl + '/' + jeton, {credentials: 'same-origin', headers: entetes}).then(json).then(function (j) { return {jeton: jeton, recus: j.recus, morceaux: j.morceaux}; })
					.catch(function () { stocker(cleReprise, null); return departs(); });
			}
			return fetch(d.envoiUrl, {method: 'POST', credentials: 'same-origin', headers: Object.assign({'Content-Type': 'application/json'}, entetes), body: JSON.stringify({nom: f.name, taille: f.size})})
				.then(json).then(function (j) { stocker(cleReprise, j.jeton); return j; });
		};
		item.etat = 'envoi';
		majBouton();
		return departs().then(function (j) {
			item.jeton = j.jeton;
			var recus = {};
			(j.recus || []).forEach(function (i) { recus[i] = true; });
			var total = j.morceaux, faits = Object.keys(recus).length;
			var suivant = function (i) {
				if (item.annule) { throw new Error('annulé'); }
				if (i >= total) { return Promise.resolve(); }
				if (recus[i]) { return suivant(i + 1); }
				var essai = function (n) {
					var corps = f.slice(i * morceau, Math.min(f.size, (i + 1) * morceau));
					return fetch(d.envoiUrl + '/' + j.jeton + '/' + i, {method: 'POST', body: corps, credentials: 'same-origin', headers: Object.assign({'Content-Type': 'application/octet-stream'}, entetes)}).then(json).catch(function (e) {
						if (n >= 4) { throw e; }
						item.vue.info.textContent = 'Coupure : reprise…';
						return attendre(1500 * (n + 1)).then(function () { return essai(n + 1); });
					});
				};
				return essai(0).then(function () {
					faits += 1;
					item.vue.barre.style.width = Math.round(100 * faits / total) + '%';
					item.vue.info.textContent = taille(f.size) + ' · ' + Math.round(100 * faits / total) + ' %';
					return suivant(i + 1);
				});
			};
			item.vue.barre.style.width = Math.round(100 * faits / total) + '%';
			return suivant(0);
		}).then(function () {
			item.etat = 'fini';
			item.vue.li.classList.add('fini');
			item.vue.info.textContent = taille(f.size) + ' · prêt';
			stocker(cleReprise, null);
			majBouton();
		}).catch(function (e) {
			if (item.annule) { return; }
			item.etat = 'erreur';
			item.vue.li.classList.add('erreur');
			item.vue.info.textContent = e.message || 'Envoi impossible.';
			majBouton();
		});
	}

	function ajouterFichiers(fichiers) {
		if (!dlg.open) { dlg.showModal(); }
		dire(erreurEnvoi, '');
		Array.prototype.forEach.call(fichiers, function (f) {
			var item = {fichier: f, etat: 'attente'};
			item.vue = ligne(f.name, f.size);
			elements.push(item);
			item.vue.retirer.addEventListener('click', function () { item.annule = true; item.vue.li.remove(); elements.splice(elements.indexOf(item), 1); majBouton(); });
			if (f.size > max) {
				item.etat = 'erreur';
				item.vue.li.classList.add('erreur');
				item.vue.info.textContent = taille(f.size) + ' · dépasse ' + Math.round(max / 1048576) + ' Mo : partagez-le en lien depuis le dossier du client, sans copie.';
				return;
			}
			envoyerFichier(item);
		});
		majBouton();
	}

	document.querySelectorAll('input[data-fichiers]').forEach(function (input) {
		input.addEventListener('change', function () { if (input.files.length) { ajouterFichiers(input.files); } input.value = ''; composeur.classList.remove('outils-ouverts'); });
	});
	[dlg.querySelector('[data-depot]'), messages].forEach(function (zone) {
		if (!zone) { return; }
		zone.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { e.preventDefault(); zone.classList.add('survol'); } });
		zone.addEventListener('dragleave', function () { zone.classList.remove('survol'); });
		zone.addEventListener('drop', function (e) { zone.classList.remove('survol'); if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); ajouterFichiers(e.dataTransfer.files); } });
	});
	texte.addEventListener('paste', function (e) {
		var fichiers = e.clipboardData && e.clipboardData.files;
		if (fichiers && fichiers.length) { e.preventDefault(); ajouterFichiers(fichiers); }
	});

	// Fichiers du dossier du client : envoyés comme lien, sans copie.
	var dossier = dlg.querySelector('.mi-dossier');
	function chargerDossier(client) {
		var etat = dossier.querySelector('.mi-dossier-etat'), ul = dossier.querySelector('.mi-dossier-liste');
		dossier.hidden = false;
		etat.textContent = 'Lecture du dossier…';
		ul.textContent = '';
		var url = new URL(d.dossierUrl, location.href);
		if (client) { url.searchParams.set('client', client); }
		fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(json).then(function (j) {
			etat.textContent = j.erreur || ((j.client ? j.client.libelle + ' · ' : '') + j.fichiers.length + ' fichier' + (j.fichiers.length > 1 ? 's' : '') + ' · cochez pour partager en lien');
			var groupe = null;
			j.fichiers.slice().sort(function (a, b) { return a.dossier.localeCompare(b.dossier) || a.nom.localeCompare(b.nom); }).forEach(function (f) {
				if (f.dossier !== groupe) { groupe = f.dossier; ul.appendChild(el('li', 'dossier', '📁 ' + (groupe || 'Dossier du client'))); }
				var li = el('li'), lab = el('label'), cb = el('input');
				cb.type = 'checkbox';
				cb.addEventListener('change', function () {
					if (cb.checked) {
						var item = {etat: 'lien', lien: j.client.id + '|' + f.chemin, case: cb};
						item.vue = ligne(f.nom, f.octets, 'lien');
						item.vue.info.textContent = taille(f.octets) + ' · déjà sur le serveur → envoyé comme lien';
						item.vue.retirer.addEventListener('click', function () { cb.checked = false; cb.dispatchEvent(new Event('change')); });
						cb._item = item;
						elements.push(item);
					} else if (cb._item) {
						cb._item.vue.li.remove();
						elements.splice(elements.indexOf(cb._item), 1);
						cb._item = null;
					}
					majBouton();
				});
				lab.appendChild(cb);
				lab.appendChild(el('span', '', f.nom));
				lab.appendChild(el('small', '', taille(f.octets) + ' · ' + f.modifie));
				li.appendChild(lab);
				ul.appendChild(li);
			});
		}).catch(function (e) { etat.textContent = e.message; });
	}
	document.querySelectorAll('[data-dossier]').forEach(function (b) {
		b.addEventListener('click', function () { if (!dlg.open) { dlg.showModal(); } chargerDossier(d.client || ''); majBouton(); });
	});
	var autreClient = dlg.querySelector('.mi-dossier-client');
	if (autreClient) {
		rechercheLiaison(autreClient, dossier.querySelector('.mi-propositions'), {clients: true, genre: 'client'}, function (r) { autreClient.value = ''; chargerDossier(r.id); });
	}

	boutonEnvoi.addEventListener('click', function () {
		var prets = elements.filter(function (x) { return x.etat === 'fini' || x.etat === 'lien'; });
		boutonEnvoi.disabled = true;
		dire(erreurEnvoi, '');
		var ranger = dlg.querySelector('input[name="ranger"]');
		envoyer({
			texte: dlg.querySelector('.mi-envoi-texte').value.trim(),
			fichiers: prets.filter(function (x) { return x.etat === 'fini'; }).map(function (x) { return x.jeton; }),
			liens: prets.filter(function (x) { return x.etat === 'lien'; }).map(function (x) { return x.lien; }),
			ranger: ranger && ranger.checked && !ranger.disabled,
		}).then(function (j) {
			prets.forEach(function (x) { x.vue.li.remove(); if (x.case) { x.case.checked = false; } elements.splice(elements.indexOf(x), 1); });
			dlg.querySelector('.mi-envoi-texte').value = '';
			if (!elements.length && !(j.avertissements && j.avertissements.length)) { dlg.close(); }
			majBouton();
		}).catch(function (e) { dire(erreurEnvoi, e.message); majBouton(); });
	});
	majBouton();
})();
