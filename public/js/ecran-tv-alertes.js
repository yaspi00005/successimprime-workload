/*
 * Alertes sonores de l'écran TV (docs/indicateurs.md, section 12) : un
 * carillon fabriqué par le navigateur (WebAudio, aucun fichier), puis une
 * voix française (speechSynthesis, fr-FR) dit ce qui vient d'arriver :
 * « Nouvelle commande en ligne disponible », « Deux nouvelles demandes de
 * devis »…
 *
 * Les phrases viennent du serveur (AlertesEcranTv) : jamais de nom, de
 * montant ni de numéro. Sans voix française sur l'appareil, seulement le
 * carillon.
 *
 * Utilisé par l'écran (tv_dashboard/index.html.twig : AlertesSonores.ecran)
 * et par « Tester le son » de Réglages › Écran TV (AlertesSonores.tester).
 *
 * Le navigateur bloque le son tant que personne n'a touché la page : l'écran
 * montre une fois un grand bouton « Activer le son », et s'en souvient
 * (stockage du navigateur). Chrome garde ensuite l'accord quand la page se
 * recharge ; s'il le perd, le bouton revient.
 */
(function () {
	'use strict';

	var CLE_SON = 'si-ecran-tv-son';
	var CLE_VUS = 'si-ecran-tv-alertes-vues';
	var GARDES_PAR_TYPE = 120;
	var contexte = null;

	function lire(cle) {
		try { return window.localStorage.getItem(cle); } catch (e) { return null; }
	}

	function ecrire(cle, valeur) {
		try { window.localStorage.setItem(cle, valeur); } catch (e) { /* navigation privée, stockage plein : l'écran continue sans */ }
	}

	function audio() {
		if (!contexte) {
			var Contexte = window.AudioContext || window.webkitAudioContext;
			if (!Contexte) { return null; }
			try { contexte = new Contexte(); } catch (e) { return null; }
		}
		return contexte;
	}

	/* ---------- Son permis par le navigateur ---------- */

	function attendreSon(ctx) {
		if (ctx.state === 'running') { return Promise.resolve(true); }
		return new Promise(function (resoudre) {
			var fini = false;
			function finir() { if (!fini) { fini = true; resoudre(ctx.state === 'running'); } }
			// Sans accord, resume() ne répond pas toujours : on regarde l'état peu après.
			try { ctx.resume().then(finir, finir); } catch (e) { finir(); }
			setTimeout(finir, 600);
		});
	}

	/** Sans geste : vrai si le navigateur laisse déjà partir le son (accord gardé). */
	function sonPermis() {
		var ctx = audio();
		return ctx ? attendreSon(ctx) : Promise.resolve(false);
	}

	/** À appeler pendant un toucher, un clic ou une touche : débloque le son et la voix. */
	function debloquer() {
		var ctx = audio();
		if ('speechSynthesis' in window) {
			try {
				var amorce = new SpeechSynthesisUtterance(' ');
				amorce.volume = 0;
				window.speechSynthesis.speak(amorce);
			} catch (e) { /* pas de voix : le carillon suffit */ }
		}
		return ctx ? attendreSon(ctx) : Promise.resolve('speechSynthesis' in window);
	}

	/* ---------- Carillon ---------- */

	function note(ctx, sortie, frequence, debut, duree) {
		// Fondamentale et deux harmoniques qui s'éteignent plus vite : un son de cloche doux.
		[[1, 0.6], [2, 0.16], [3, 0.05]].forEach(function (h) {
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			var fin = debut + duree / h[0];
			osc.type = 'sine';
			osc.frequency.value = frequence * h[0];
			gain.gain.setValueAtTime(0.0001, debut);
			gain.gain.exponentialRampToValueAtTime(h[1], debut + 0.015);
			gain.gain.exponentialRampToValueAtTime(0.0001, fin);
			osc.connect(gain);
			gain.connect(sortie);
			osc.start(debut);
			osc.stop(fin + 0.05);
		});
	}

	/** « Ding-dong » (mi puis do). Renvoie sa durée en millisecondes, 0 s'il n'a pas pu partir. */
	function carillon(volume) {
		var ctx = audio();
		if (!ctx || ctx.state !== 'running') { return 0; }
		var debut = ctx.currentTime + 0.05;
		var sortie = ctx.createGain();
		sortie.gain.value = Math.max(0, Math.min(1, volume)) * 0.55;
		sortie.connect(ctx.destination);
		note(ctx, sortie, 659.25, debut, 1.3);
		note(ctx, sortie, 523.25, debut + 0.45, 1.6);
		return 1150;
	}

	/* ---------- Voix ---------- */

	function voixFrancaise() {
		if (!('speechSynthesis' in window)) { return null; }
		var toutes = window.speechSynthesis.getVoices() || [];
		var francaises = toutes.filter(function (v) { return /^fr([-_]|$)/i.test(v.lang || ''); });
		var deFrance = francaises.filter(function (v) { return /^fr[-_]FR$/i.test(v.lang); });
		var locales = deFrance.filter(function (v) { return v.localService; });

		return locales[0] || deFrance[0] || francaises[0] || null;
	}

	/** Les voix arrivent un peu après l'ouverture de la page (Chrome) : on les attend au plus 1,5 s. */
	function voixPretes() {
		if (!('speechSynthesis' in window)) { return Promise.resolve(null); }
		if ((window.speechSynthesis.getVoices() || []).length) { return Promise.resolve(voixFrancaise()); }
		return new Promise(function (resoudre) {
			var fini = false;
			function finir() { if (!fini) { fini = true; resoudre(voixFrancaise()); } }
			window.speechSynthesis.addEventListener('voiceschanged', finir);
			setTimeout(finir, 1500);
		});
	}

	function parler(texte, volume) {
		var voix = voixFrancaise();
		if (!voix) { return false; }
		var synthese = window.speechSynthesis;
		var phrase = new SpeechSynthesisUtterance(texte);
		phrase.lang = 'fr-FR';
		phrase.voice = voix;
		phrase.volume = Math.max(0, Math.min(1, volume));
		phrase.rate = 0.95;
		synthese.cancel();
		synthese.speak(phrase);
		return true;
	}

	/**
	 * Carillon puis voix selon les réglages ({son, voix, volume}).
	 * Renvoie ce qui a pu partir : {carillon: bool, voix: bool}.
	 */
	function annoncer(texte, reglages) {
		var volume = (typeof reglages.volume === 'number' ? reglages.volume : 80) / 100;
		var attente = reglages.son ? carillon(volume) : 0;
		var avecVoix = !!reglages.voix && !!voixFrancaise();
		if (avecVoix) {
			setTimeout(function () { parler(texte, volume); }, attente);
		}
		return { carillon: attente > 0, voix: avecVoix };
	}

	if ('speechSynthesis' in window) {
		// Demander la liste tôt : Chrome la charge en arrière-plan.
		try { window.speechSynthesis.getVoices(); } catch (e) { /* rien */ }
	}

	/* ---------- Écran TV ---------- */

	/**
	 * options : reglages {son, voix, volume, evenements}, phrases {type: {"1".."10", plus}},
	 * etat {silence, jetons} (premier état, déjà dans la page), bouton (« Activer le son »),
	 * bandeau (l'alerte affichée). Renvoie {recevoir(etat)}, appelé à chaque rafraîchissement.
	 */
	function ecran(options) {
		var reglages = options.reglages || {};
		var phrases = options.phrases || {};
		var types = Array.isArray(reglages.evenements) ? reglages.evenements : [];
		var sonore = types.length > 0 && !!(reglages.son || reglages.voix);
		var bouton = options.bouton;
		var bandeau = options.bandeau;
		var actif = false;
		var enCours = false;
		var minuteur = null;
		var vus = null;

		try { vus = JSON.parse(lire(CLE_VUS) || 'null'); } catch (e) { vus = null; }

		function proposer() {
			if (!bouton) { return; }
			bouton.hidden = false;
			try { bouton.focus({ preventScroll: true }); } catch (e) { /* rien */ }
		}

		function activer() {
			if (actif || enCours) { return; }
			enCours = true;
			var confirmer = bouton && !bouton.hidden;
			debloquer().then(function (ok) {
				enCours = false;
				actif = ok;
				if (!ok) { return; }
				ecrire(CLE_SON, '1');
				if (bouton) { bouton.hidden = true; }
				if (confirmer) { annoncer('Le son des alertes est activé.', reglages); }
			});
		}

		// Premier état (celui de la page) : annoncé une fois qu'on sait si le son peut partir.
		var pret = Promise.resolve();
		if (sonore) {
			if (lire(CLE_SON) === '1') {
				pret = sonPermis().then(function (ok) { actif = ok; if (!ok) { proposer(); } });
			} else {
				proposer();
			}
			// Un toucher, un clic ou une touche de la télécommande, n'importe où, suffit.
			['pointerup', 'click', 'keydown'].forEach(function (nom) {
				document.addEventListener(nom, activer, true);
			});
		}

		function montrer(lignes) {
			if (!bandeau || document.body.classList.contains('eteint')) { return; }
			var liste = bandeau.querySelector('[data-lignes]');
			liste.textContent = '';
			lignes.forEach(function (texte) {
				var ligne = document.createElement('div');
				ligne.textContent = texte;
				liste.appendChild(ligne);
			});
			bandeau.hidden = false;
			void bandeau.offsetHeight;
			bandeau.classList.add('visible');
			clearTimeout(minuteur);
			minuteur = setTimeout(function () {
				bandeau.classList.remove('visible');
				minuteur = setTimeout(function () { bandeau.hidden = true; }, 600);
			}, 10000 + 3000 * (lignes.length - 1));
		}

		function recevoir(etat) {
			if (!etat || !etat.jetons || !types.length) { return; }
			var precedents = vus && typeof vus === 'object' ? vus : null;
			var nombres = {};
			var total = 0;
			vus = {};
			types.forEach(function (type) {
				var actuels = Array.isArray(etat.jetons[type]) ? etat.jetons[type] : [];
				var connus = precedents && Array.isArray(precedents[type]) ? precedents[type] : null;
				// Premier passage (ou événement qu'on vient d'ajouter) : rien n'est annoncé, tout est noté.
				if (connus) {
					var nouveaux = actuels.filter(function (j) { return connus.indexOf(j) === -1; }).length;
					if (nouveaux) { nombres[type] = nouveaux; total += nouveaux; }
				}
				// On garde aussi les anciens : une fiche qui revient dans la liste n'est pas annoncée deux fois.
				vus[type] = actuels.concat((connus || []).filter(function (j) { return actuels.indexOf(j) === -1; })).slice(0, GARDES_PAR_TYPE);
			});
			ecrire(CLE_VUS, JSON.stringify(vus));
			if (!total) { return; }

			var lignes = types.filter(function (t) { return nombres[t]; }).map(function (t) {
				return (phrases[t] || {})[nombres[t] > 10 ? 'plus' : String(nombres[t])];
			}).filter(Boolean);
			if (!lignes.length) { return; }
			montrer(lignes);
			// Heures de silence : l'alerte s'affiche, sans carillon ni voix.
			if (!etat.silence && actif) {
				annoncer(lignes.slice(0, 4).join('. ') + '.', reglages);
			}
		}

		pret.then(function () { recevoir(options.etat); });

		return { recevoir: recevoir };
	}

	/* ---------- Réglages : « Tester le son » ---------- */

	/** Après un clic : débloque, attend les voix, annonce un exemple. Résultat : {son, carillon, voix (nom ou null)}. */
	function tester(reglages, texte) {
		return debloquer().then(function (permis) {
			return voixPretes().then(function (voix) {
				var joue = permis ? annoncer(texte, reglages) : { carillon: false, voix: false };
				return { son: permis, carillon: joue.carillon, voix: voix ? voix.name : null, voixJouee: joue.voix };
			});
		});
	}

	window.AlertesSonores = {
		ecran: ecran,
		tester: tester,
		annoncer: annoncer,
		voixFrancaise: voixFrancaise
	};
})();
