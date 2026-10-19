/*
 * Notes vocales de la messagerie (docs/messagerie-clients.md) : enregistrer
 * depuis le navigateur ou le téléphone (MediaRecorder), 3 minutes au plus,
 * réécouter, puis envoyer ou annuler.
 *
 * Le bouton micro se touche une fois pour démarrer et une fois pour arrêter,
 * ou se maintient appuyé pendant qu'on parle (relâcher arrête).
 *
 * Gabarit : messages_clients/_note_vocale.html.twig (data-url, data-jeton,
 * data-max ; radios « mode » quand l'atelier choisit entre le client et une
 * note interne). Le serveur revérifie tout (type, taille, durée).
 */
(function () {
	'use strict';

	var FORMATS = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm', 'audio/ogg'];
	var MAINTENU = 700; // ms : au-delà, relâcher le bouton arrête l'enregistrement

	function duree(s) {
		s = Math.max(0, Math.round(s));
		return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
	}

	function extension(type) {
		return type.indexOf('ogg') !== -1 ? 'ogg' : (type.indexOf('mp4') !== -1 ? 'm4a' : 'webm');
	}

	function preparer(el) {
		var max = parseInt(el.dataset.max, 10) || 180;
		var q = function (s) { return el.querySelector(s); };
		var ouvrir = q('.nv-ouvrir'), panneau = q('.nv-panneau'), etat = q('.nv-etat'), micro = q('.nv-micro'),
			chrono = q('.nv-chrono'), ecoute = q('.nv-ecoute'), audio = q('.nv-ecoute audio'), dureeTexte = q('.nv-ecoute .nv-duree'),
			envoyer = q('.nv-envoyer'), recommencer = q('.nv-recommencer'), annuler = q('.nv-annuler');
		var flux = null, rec = null, morceaux = [], debut = 0, minuteur = null, blob = null, url = null, secondes = 0, appui = 0, jeter = false, envoi = false;

		function dire(texte, erreur) {
			etat.textContent = texte;
			etat.classList.toggle('nv-erreur', !!erreur);
		}

		function enCours() {
			return rec !== null && rec.state === 'recording';
		}

		function remettre() {
			if (url) { URL.revokeObjectURL(url); url = null; }
			blob = null;
			secondes = 0;
			audio.removeAttribute('src');
			audio.load();
			ecoute.hidden = true;
			envoyer.hidden = true;
			recommencer.hidden = true;
			micro.hidden = false;
			chrono.textContent = '0:00 / ' + duree(max);
			el.classList.remove('nv-en-cours');
			dire(el.dataset.aide || 'Touchez le micro pour enregistrer, ou maintenez-le appuyé pendant que vous parlez.');
		}

		function arreter() {
			clearInterval(minuteur);
			if (enCours()) { rec.stop(); }
			el.classList.remove('nv-en-cours');
			micro.setAttribute('aria-pressed', 'false');
		}

		function demarrer() {
			remettre();
			dire('Autorisez le micro si le navigateur le demande…');
			navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}}).then(function (f) {
				flux = f;
				var type = '';
				for (var i = 0; i < FORMATS.length; i++) {
					if (window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(FORMATS[i])) { type = FORMATS[i]; break; }
				}
				rec = new MediaRecorder(flux, type ? {mimeType: type, audioBitsPerSecond: 32000} : {audioBitsPerSecond: 32000});
				morceaux = [];
				rec.ondataavailable = function (e) { if (e.data && e.data.size) { morceaux.push(e.data); } };
				rec.onstop = function () {
					flux.getTracks().forEach(function (t) { t.stop(); });
					if (jeter) { jeter = false; remettre(); return; }
					secondes = Math.min(max, (performance.now() - debut) / 1000);
					if (secondes < 1) { remettre(); dire('Trop court : maintenez le micro appuyé, ou touchez-le une fois puis encore pour arrêter.', true); return; }
					blob = new Blob(morceaux, {type: rec.mimeType || type || 'audio/webm'});
					url = URL.createObjectURL(blob);
					audio.src = url;
					dureeTexte.textContent = duree(secondes);
					ecoute.hidden = false;
					envoyer.hidden = false;
					recommencer.hidden = false;
					micro.hidden = true;
					dire('Réécoutez votre note (' + duree(secondes) + '), puis envoyez-la ou recommencez.');
				};
				rec.start(1000);
				debut = performance.now();
				el.classList.add('nv-en-cours');
				micro.setAttribute('aria-pressed', 'true');
				dire('Enregistrement… Touchez le micro (ou relâchez-le) pour arrêter.');
				minuteur = setInterval(function () {
					var s = (performance.now() - debut) / 1000;
					chrono.textContent = duree(s) + ' / ' + duree(max);
					if (s >= max) { arreter(); }
				}, 250);
			}).catch(function () {
				dire('Micro refusé ou introuvable : autorisez le micro pour ce site, puis réessayez.', true);
			});
		}

		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
			ouvrir.addEventListener('click', function () {
				panneau.hidden = !panneau.hidden;
				dire('Ce navigateur ne permet pas d\'enregistrer une note vocale (page non sécurisée ou navigateur trop ancien).', true);
			});
			micro.disabled = true;
			return;
		}

		ouvrir.addEventListener('click', function () {
			panneau.hidden = !panneau.hidden;
			ouvrir.setAttribute('aria-expanded', String(!panneau.hidden));
			if (panneau.hidden) { jeter = enCours(); arreter(); remettre(); } else { remettre(); micro.focus(); }
		});
		micro.addEventListener('pointerdown', function (e) {
			e.preventDefault();
			// Le doigt peut glisser hors du bouton : le relâcher est quand même reçu ici.
			try { micro.setPointerCapture(e.pointerId); } catch (ignore) { /* navigateur ancien */ }
			if (enCours()) { arreter(); return; }
			appui = Date.now();
			demarrer();
		});
		var relacher = function () {
			if (enCours() && Date.now() - appui > MAINTENU) { arreter(); }
		};
		micro.addEventListener('pointerup', relacher);
		micro.addEventListener('pointercancel', relacher);
		micro.addEventListener('contextmenu', function (e) { e.preventDefault(); });
		// Clavier (Entrée, Espace) : démarrer puis arrêter.
		micro.addEventListener('click', function (e) {
			if (e.detail !== 0) { return; }
			if (enCours()) { arreter(); } else { demarrer(); }
		});
		recommencer.addEventListener('click', function () { remettre(); micro.focus(); });
		annuler.addEventListener('click', function () {
			jeter = enCours();
			arreter();
			remettre();
			panneau.hidden = true;
			ouvrir.setAttribute('aria-expanded', 'false');
			ouvrir.focus();
		});
		envoyer.addEventListener('click', function () {
			if (!blob || envoi) { return; }
			envoi = true;
			envoyer.disabled = true;
			dire('Envoi de la note vocale…');
			var donnees = new FormData();
			donnees.append('_token', el.dataset.jeton);
			donnees.append('audio', blob, 'note-vocale.' + extension(blob.type));
			donnees.append('duree', secondes.toFixed(1));
			if (el.dataset.onglet) { donnees.append('onglet', el.dataset.onglet); }
			var mode = el.querySelector('input[name="nv-mode"]:checked');
			donnees.append('mode', mode ? mode.value : 'reponse');
			fetch(el.dataset.url, {method: 'POST', body: donnees, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) {
					return r.json().catch(function () { return {}; }).then(function (j) { return {ok: r.ok, statut: r.status, j: j}; });
				})
				.then(function (res) {
					if (!res.ok || !res.j.url) {
						throw new Error(res.j.erreur || res.j.detail || ('Envoi refusé par le serveur (erreur ' + res.statut + ') : rechargez la page, puis réessayez.'));
					}
					// Messagerie de l'équipe (data-evenement) : la page ajoute le message elle-même, sans recharger.
					if (el.dataset.evenement) {
						envoi = false;
						envoyer.disabled = false;
						remettre();
						panneau.hidden = true;
						ouvrir.setAttribute('aria-expanded', 'false');
						el.dispatchEvent(new CustomEvent(el.dataset.evenement, {bubbles: true, detail: res.j}));
						return;
					}
					var cible = new URL(res.j.url, window.location.href);
					if (cible.pathname + cible.search === window.location.pathname + window.location.search) {
						window.location.hash = cible.hash;
						window.location.reload();
					} else {
						window.location.href = cible.href;
					}
				})
				.catch(function (e) {
					envoi = false;
					envoyer.disabled = false;
					dire(e && e.message && !(e instanceof TypeError) ? e.message : 'Envoi impossible : vérifiez la connexion, puis réessayez.', true);
				});
		});
		remettre();
	}

	document.querySelectorAll('[data-note-vocale]').forEach(preparer);
})();
