/*
 * Panneau de l'assistant (docs/assistant-client.md), chargé au premier clic
 * sur le bouton (templates/assistant/_widget.html.twig), sans dépendance :
 *  - la conversation se retrouve d'une page à l'autre par son jeton, gardé
 *    dans ce navigateur (rien d'autre n'y est gardé) ;
 *  - le texte des réponses est inséré comme du texte, jamais comme du HTML ;
 *    les boutons ne suivent que des liens du site ou de WhatsApp ;
 *  - un pouce par réponse ; Échap ferme ; le focus revient au bouton.
 */
(function () {
	var bouton = document.getElementById('as-bouton');
	if (!bouton || window.SIAssistant) { return; }

	var contexte = bouton.getAttribute('data-contexte') || 'site';
	var urls = {
		message: bouton.getAttribute('data-url-message'),
		note: bouton.getAttribute('data-url-note'),
		historique: bouton.getAttribute('data-url-historique')
	};
	var csrf = bouton.getAttribute('data-csrf');
	var accueil = bouton.getAttribute('data-accueil') || '';
	var suggestions = [];
	try { suggestions = JSON.parse(bouton.getAttribute('data-suggestions') || '[]'); } catch (e) { suggestions = []; }
	var cle = 'si-assistant-' + contexte;
	var QUESTION_MAX = 800;
	var SVG = 'http://www.w3.org/2000/svg';
	var ICONES = {
		fermer: ['M18 6 6 18', 'M6 6l12 12'],
		nouvelle: ['M12 5v14', 'M5 12h14'],
		envoyer: ['M22 2 11 13', 'M22 2l-7 20-4-9-9-4 20-7z'],
		utile: ['M7 10v12', 'M15 5.9 14 10h5.8a2 2 0 0 1 2 2.3l-1.4 8A2 2 0 0 1 18.4 22H7V10l4.3-8a3 3 0 0 1 3.7 3.9z'],
		pasUtile: ['M17 14V2', 'M9 18.1 10 14H4.2a2 2 0 0 1-2-2.3l1.4-8A2 2 0 0 1 5.6 2H17v12l-4.3 8a3 3 0 0 1-3.7-3.9z']
	};

	var panneau, journal, saisie, envoyer, compteur, jeton = lireJeton(), occupe = false, historiqueLu = false;

	function lireJeton() {
		try { return window.localStorage.getItem(cle); } catch (e) { return null; }
	}
	function garderJeton(valeur) {
		jeton = valeur;
		try {
			if (valeur) { window.localStorage.setItem(cle, valeur); } else { window.localStorage.removeItem(cle); }
		} catch (e) { /* navigation privée : la conversation tient le temps de la page */ }
	}

	function el(nom, classe, texte) {
		var n = document.createElement(nom);
		if (classe) { n.className = classe; }
		if (texte !== undefined && texte !== null) { n.textContent = texte; }
		return n;
	}
	function icone(nom) {
		var svg = document.createElementNS(SVG, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		ICONES[nom].forEach(function (d) {
			var p = document.createElementNS(SVG, 'path');
			p.setAttribute('d', d);
			svg.appendChild(p);
		});
		return svg;
	}

	function construire() {
		panneau = el('div', 'as-panneau');
		panneau.id = 'as-panneau';
		panneau.hidden = true;
		panneau.setAttribute('role', 'dialog');
		panneau.setAttribute('aria-modal', 'false');
		panneau.setAttribute('aria-labelledby', 'as-titre');

		var tete = el('div', 'as-tete');
		var texteTete = el('div', 'as-tete-texte');
		var titre = el('h2', null, 'Assistant Succès Imprim');
		titre.id = 'as-titre';
		texteTete.appendChild(titre);
		texteTete.appendChild(el('p', null, contexte === 'espace' ? 'Commandes, produits, questions' : 'Produits, prix, horaires, fichiers'));
		var nouvelle = el('button', 'as-icone');
		nouvelle.type = 'button';
		nouvelle.title = 'Nouvelle conversation';
		nouvelle.setAttribute('aria-label', 'Nouvelle conversation');
		nouvelle.appendChild(icone('nouvelle'));
		nouvelle.addEventListener('click', recommencer);
		var croix = el('button', 'as-icone');
		croix.type = 'button';
		croix.title = 'Fermer';
		croix.setAttribute('aria-label', 'Fermer l\'assistant');
		croix.appendChild(icone('fermer'));
		croix.addEventListener('click', fermer);
		tete.appendChild(texteTete);
		tete.appendChild(nouvelle);
		tete.appendChild(croix);

		journal = el('div', 'as-journal');
		journal.setAttribute('role', 'log');
		journal.setAttribute('aria-live', 'polite');
		journal.setAttribute('aria-label', 'Conversation');
		journal.tabIndex = 0;

		var formulaire = el('form', 'as-saisie');
		formulaire.noValidate = true;
		var etiquette = el('label', 'as-cache', 'Votre question');
		etiquette.htmlFor = 'as-question';
		saisie = el('textarea');
		saisie.id = 'as-question';
		saisie.rows = 1;
		saisie.maxLength = QUESTION_MAX;
		saisie.placeholder = 'Écrivez votre question…';
		saisie.setAttribute('aria-describedby', 'as-mention');
		saisie.setAttribute('enterkeyhint', 'send');
		envoyer = el('button', 'as-envoyer');
		envoyer.type = 'submit';
		envoyer.setAttribute('aria-label', 'Envoyer');
		envoyer.appendChild(icone('envoyer'));
		formulaire.appendChild(etiquette);
		formulaire.appendChild(saisie);
		formulaire.appendChild(envoyer);
		formulaire.addEventListener('submit', function (e) {
			e.preventDefault();
			poser(saisie.value);
		});
		saisie.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				poser(saisie.value);
			}
		});
		saisie.addEventListener('input', ajuster);

		var mention = el('p', 'as-mention', 'Assistant automatique : il peut se tromper. Pour une commande ou un paiement, un conseiller vous répond sur WhatsApp.');
		mention.id = 'as-mention';
		compteur = el('span', 'as-cache');
		compteur.setAttribute('aria-live', 'polite');

		panneau.appendChild(tete);
		panneau.appendChild(journal);
		panneau.appendChild(formulaire);
		panneau.appendChild(mention);
		panneau.appendChild(compteur);
		panneau.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				e.stopPropagation();
				fermer();
			}
		});
		document.body.appendChild(panneau);
	}

	function ajuster() {
		saisie.style.height = 'auto';
		saisie.style.height = Math.min(saisie.scrollHeight + 2, 120) + 'px';
	}

	function defiler() {
		journal.scrollTop = journal.scrollHeight;
	}

	/* Une réponse longue se lit depuis le début : la question reste en haut. */
	function montrer(question) {
		journal.scrollTop = Math.max(0, question.offsetTop - journal.offsetTop - 8);
	}

	/* Texte d'une réponse : paragraphes, listes « - », **gras**. */
	function enLigne(parent, texte) {
		var morceaux = texte.split(/(\*\*[^*]+\*\*)/);
		morceaux.forEach(function (m) {
			if (/^\*\*[^*]+\*\*$/.test(m)) {
				parent.appendChild(el('strong', null, m.slice(2, -2)));
			} else if (m) {
				parent.appendChild(document.createTextNode(m));
			}
		});
	}
	function rediger(bulle, texte) {
		texte.replace(/\r/g, '').split(/\n{2,}/).forEach(function (bloc) {
			var lignes = bloc.split('\n').filter(function (l) { return l.trim() !== ''; });
			if (!lignes.length) { return; }
			var liste = null;
			var para = null;
			lignes.forEach(function (ligne) {
				var puce = /^\s*(?:[-*•]|\d+[.)])\s+(.*)$/.exec(ligne);
				if (puce) {
					if (!liste) { liste = el('ul'); bulle.appendChild(liste); para = null; }
					var li = el('li');
					enLigne(li, puce[1]);
					liste.appendChild(li);
					return;
				}
				liste = null;
				if (para) { para.appendChild(el('br')); } else { para = el('p'); bulle.appendChild(para); }
				enLigne(para, ligne.replace(/^#+\s*/, ''));
			});
		});
	}

	/* Seuls les liens du site (chemin « /… »), de WhatsApp et le plan (https) sont suivis. */
	function lienSur(action) {
		var url = String(action.url || '');
		if (action.type === 'whatsapp') {
			return /^https:\/\/(wa\.me|api\.whatsapp\.com)\//.test(url) ? url : null;
		}
		if (action.type === 'externe') {
			return /^https:\/\/[^\s]+$/.test(url) ? url : null;
		}
		return /^\/(?!\/)/.test(url) ? url : null;
	}

	function bulleClient(texte) {
		var b = el('div', 'as-bulle as-client', texte);
		journal.appendChild(b);
		return b;
	}

	function bulleAssistant(reponse, avecAvis) {
		var b = el('div', 'as-bulle as-assistant');
		if (reponse.issue && ['refus', 'erreur', 'plafond', 'limite'].indexOf(reponse.issue) !== -1) {
			b.className += ' as-alerte';
		}
		rediger(b, reponse.texte || '');
		var actions = (reponse.actions || []).map(function (a) { return {action: a, url: lienSur(a)}; }).filter(function (x) { return x.url; });
		if (actions.length) {
			var zone = el('div', 'as-actions');
			actions.forEach(function (x) {
				var lien = el('a', 'as-action' + (x.action.type === 'whatsapp' ? ' as-action-whatsapp' : ''), x.action.libelle);
				lien.href = x.url;
				if (x.action.type === 'whatsapp' || x.action.type === 'externe') {
					lien.target = '_blank';
					lien.rel = 'noopener';
					lien.appendChild(el('span', 'as-cache', x.action.type === 'whatsapp' ? ' (ouvre WhatsApp)' : ' (nouvel onglet)'));
				}
				zone.appendChild(lien);
			});
			b.appendChild(zone);
		}
		journal.appendChild(b);
		if (avecAvis && reponse.id) {
			journal.appendChild(avis(reponse.id, reponse.note === undefined ? null : reponse.note));
		}
		return b;
	}

	function avis(id, note) {
		var zone = el('div', 'as-avis');
		zone.appendChild(el('span', null, 'Utile ?'));
		var pouces = [
			{valeur: 1, classe: 'as-utile', icone: 'utile', libelle: 'Réponse utile'},
			{valeur: -1, classe: 'as-pas-utile', icone: 'pasUtile', libelle: 'Réponse pas utile'}
		].map(function (p) {
			var b = el('button', 'as-pouce ' + p.classe);
			b.type = 'button';
			b.title = p.libelle;
			b.setAttribute('aria-label', p.libelle);
			b.setAttribute('aria-pressed', note === p.valeur ? 'true' : 'false');
			b.appendChild(icone(p.icone));
			b.addEventListener('click', function () {
				var actif = b.getAttribute('aria-pressed') === 'true';
				var nouvelle = actif ? null : p.valeur;
				pouces.forEach(function (autre) { autre.setAttribute('aria-pressed', 'false'); });
				if (!actif) { b.setAttribute('aria-pressed', 'true'); }
				compteur.textContent = nouvelle === null ? 'Avis retiré.' : 'Merci pour votre avis.';
				appeler(urls.note, {conversation: jeton, message: id, note: nouvelle}).catch(function () { /* l'avis n'est pas essentiel */ });
			});
			zone.appendChild(b);
			return b;
		});
		return zone;
	}

	function bulleAlerte(texte) {
		return bulleAssistant({texte: texte, issue: 'erreur', actions: []}, false);
	}

	function afficherSuggestions() {
		if (!suggestions.length) { return; }
		var zone = el('div', 'as-suggestions');
		zone.setAttribute('aria-label', 'Questions fréquentes');
		zone.setAttribute('role', 'group');
		suggestions.forEach(function (s) {
			var b = el('button', 'as-suggestion', s);
			b.type = 'button';
			b.addEventListener('click', function () { poser(s); });
			zone.appendChild(b);
		});
		journal.appendChild(zone);
	}

	function accueillir() {
		journal.textContent = '';
		if (accueil) { bulleAssistant({texte: accueil, actions: []}, false); }
		afficherSuggestions();
	}

	function enCours(oui) {
		occupe = oui;
		envoyer.disabled = oui;
		journal.setAttribute('aria-busy', oui ? 'true' : 'false');
		var ecrit = journal.querySelector('.as-ecrit');
		if (oui && !ecrit) {
			ecrit = el('div', 'as-ecrit');
			ecrit.appendChild(el('span', 'as-cache', 'L\'assistant écrit…'));
			for (var i = 0; i < 3; i++) { ecrit.appendChild(el('i')); }
			journal.appendChild(ecrit);
			defiler();
		} else if (!oui && ecrit) {
			ecrit.remove();
		}
	}

	function appeler(url, donnees) {
		var entetes = {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Assistant': '1'};
		if (csrf) { entetes['X-CSRF-TOKEN'] = csrf; }
		return fetch(url, {method: 'POST', credentials: 'same-origin', headers: entetes, body: JSON.stringify(donnees)}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (corps) { return {statut: r.status, corps: corps || {}}; });
		});
	}

	function poser(texte) {
		texte = String(texte || '').trim();
		if (occupe || texte === '') { return; }
		if (texte.length > QUESTION_MAX) { texte = texte.slice(0, QUESTION_MAX); }
		var choix = journal.querySelector('.as-suggestions');
		if (choix) { choix.remove(); }
		var question = bulleClient(texte);
		saisie.value = '';
		ajuster();
		enCours(true);
		appeler(urls.message, {texte: texte, conversation: jeton, page: window.location.pathname}).then(function (r) {
			enCours(false);
			if (r.statut === 200 && r.corps.reponse) {
				if (r.corps.conversation) { garderJeton(r.corps.conversation); }
				bulleAssistant(r.corps.reponse, true);
			} else if (r.statut === 403) {
				bulleAlerte('Votre session a expiré : rechargez la page puis reposez votre question.');
			} else {
				bulleAlerte(r.corps.erreur || 'L\'assistant ne répond pas pour le moment. Réessayez dans un instant.');
			}
			montrer(question);
		}).catch(function () {
			enCours(false);
			bulleAlerte('Pas de connexion : vérifiez votre réseau puis réessayez.');
			montrer(question);
		});
	}

	function chargerHistorique() {
		historiqueLu = true;
		if (!jeton) { accueillir(); return; }
		enCours(true);
		appeler(urls.historique, {conversation: jeton}).then(function (r) {
			enCours(false);
			var messages = r.statut === 200 && r.corps.messages ? r.corps.messages : null;
			if (r.statut === 404) { garderJeton(null); }
			if (!messages || !messages.length) { accueillir(); return; }
			journal.textContent = '';
			if (accueil) { bulleAssistant({texte: accueil, actions: []}, false); }
			messages.forEach(function (m) {
				if (m.role === 'client') { bulleClient(m.texte); } else { bulleAssistant(m, true); }
			});
			defiler();
		}).catch(function () {
			enCours(false);
			accueillir();
		});
	}

	function recommencer() {
		if (occupe) { return; }
		garderJeton(null);
		accueillir();
		saisie.focus();
	}

	function ouvrir() {
		if (!panneau) { construire(); }
		if (!panneau.hidden) { return; }
		panneau.hidden = false;
		document.documentElement.classList.add('as-ouvert');
		bouton.setAttribute('aria-expanded', 'true');
		if (!historiqueLu) { chargerHistorique(); }
		defiler();
		saisie.focus();
	}

	function fermer() {
		if (!panneau || panneau.hidden) { return; }
		panneau.hidden = true;
		document.documentElement.classList.remove('as-ouvert');
		bouton.setAttribute('aria-expanded', 'false');
		bouton.focus();
	}

	window.SIAssistant = {
		ouvrir: ouvrir,
		fermer: fermer,
		basculer: function () {
			if (panneau && !panneau.hidden) { fermer(); } else { ouvrir(); }
		}
	};
})();
