/*
 * Application Succès Imprim sur l'écran d'accueil (docs/espace-client.md),
 * en attendant les applications Android et iPhone :
 *  - la carte du tableau de bord ([data-installer-carte]), montrée la
 *    première fois sur cet appareil ; « Plus tard » la cache, elle revient
 *    une seule fois sept jours après, puis plus jamais ;
 *  - le bloc permanent de Mon compte ([data-installer]).
 *
 * Android, Chrome, Edge, Samsung Internet : le bouton ouvre la fenêtre
 * d'installation du navigateur (invitation « beforeinstallprompt », gardée
 * par _app.html.twig dans window.siInvitation). iPhone et iPad : pas de
 * bouton possible, on montre les gestes (Partager, puis Sur l'écran
 * d'accueil), dans Safari. Ailleurs (Firefox sur ordinateur…), ou dans
 * l'application déjà installée : rien.
 *
 * La mémoire est celle de l'appareil (localStorage), pas du compte : c'est
 * l'écran d'accueil de ce téléphone qui compte. Sans mémoire possible
 * (données du site bloquées), la carte ne s'affiche pas : on ne pourrait
 * pas retenir « Plus tard ».
 */
(function () {
	var CLE = 'si-espace-installer';
	var SEPT_JOURS = 7 * 24 * 3600 * 1000;
	var ua = navigator.userAgent;
	var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
	// Chrome, Firefox, Edge… sur iPhone et les navigateurs intégrés (WhatsApp, Facebook…) : pas Safari.
	var horsSafari = ios && (!/Safari\//.test(ua) || /CriOS|FxiOS|EdgiOS|OPiOS|OPT\/|GSA\/|YaBrowser|DuckDuckGo|FBAN|FBAV|FB_IAB|Instagram|WhatsApp|Line\/|Snapchat|Twitter|LinkedInApp|MicroMessenger|TikTok|musical_ly/.test(ua));
	var telephone = ios || /Android|Mobile/.test(ua);
	var installee = navigator.standalone === true || ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'].some(function (m) {
		return window.matchMedia && window.matchMedia('(display-mode: ' + m + ')').matches;
	});

	var carte = document.querySelector('[data-installer-carte]');
	var bloc = document.querySelector('[data-installer]');
	var annonce = document.querySelector('[data-installer-annonce]');

	// ---------- Mémoire de l'appareil ----------
	// null : pas de mémoire possible (navigation privée stricte, données bloquées).
	function lire() {
		var brut;
		try { brut = window.localStorage.getItem(CLE); } catch (e) { return null; }
		try {
			var v = JSON.parse(brut || '{}');
			return v && typeof v === 'object' ? v : {};
		} catch (e) { return {}; }
	}
	function ecrire(etat) {
		try { window.localStorage.setItem(CLE, JSON.stringify(etat)); return true; } catch (e) { return false; }
	}
	function noter(champs) {
		var etat = lire();
		if (etat === null) { return; }
		for (var k in champs) { if (Object.prototype.hasOwnProperty.call(champs, k)) { etat[k] = champs[k]; } }
		ecrire(etat);
	}
	// Jamais vue : oui. « Plus tard » une fois : de nouveau sept jours après. Deux fois, ou installée : plus jamais.
	function aProposer(etat, maintenant) {
		if (!etat || etat.installee) { return false; }
		var fois = etat.plusTard || 0;
		return fois === 0 || (fois === 1 && maintenant - (etat.le || 0) >= SEPT_JOURS);
	}

	if (installee) {
		// Ouverte depuis l'écran d'accueil : sur Android, la mémoire est partagée avec Chrome.
		noter({installee: true});
		return;
	}
	// Installée par le menu du navigateur, sur n'importe quelle page qui charge ce script.
	window.addEventListener('appinstalled', function () { noter({installee: true}); });

	function dire(texte) {
		if (!annonce) { return; }
		annonce.textContent = '';
		setTimeout(function () { annonce.textContent = texte; }, 60);
	}

	// Fenêtre d'installation du navigateur (une seule fois par invitation).
	function inviter(siOui, siNon) {
		var invitation = window.siInvitation;
		if (!invitation) { return false; }
		window.siInvitation = null;
		try { invitation.prompt(); } catch (e) { return false; }
		invitation.userChoice.then(function (choix) { (choix && choix.outcome === 'accepted' ? siOui : siNon)(); }, siNon);
		return true;
	}

	// Gestes iPhone : l'avis « ouvrez dans Safari » et le bouton « Copier l'adresse ».
	function preparerIos(zone) {
		var avis = zone.querySelector('[data-installer-safari]');
		if (!avis || !horsSafari) { return; }
		avis.hidden = false;
		var champ = avis.querySelector('[data-installer-adresse]');
		var etat = avis.querySelector('[data-installer-copie]');
		function copie() { etat.textContent = 'Adresse copiée. Ouvrez Safari, touchez la barre d\'adresse et collez.'; }
		function selection() {
			champ.focus();
			champ.select();
			try { if (document.execCommand('copy')) { copie(); return; } } catch (e) { /* rien */ }
			etat.textContent = 'Touchez l\'adresse, choisissez « Copier », puis collez-la dans Safari.';
		}
		avis.querySelector('[data-installer-copier]').addEventListener('click', function () {
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(champ.value).then(copie, selection);
			} else {
				selection();
			}
		});
	}

	// ---------- Carte du tableau de bord ----------
	function brancherCarte() {
		var etat = lire();
		if (!aProposer(etat, Date.now()) || !ecrire(etat)) { return; }
		if (!ios && !('onbeforeinstallprompt' in window) && !window.siInvitation) { return; }

		var etapes = carte.querySelector('[data-installer-etapes]');
		var merci = carte.querySelector('[data-installer-merci]');
		var ajouter = carte.querySelector('[data-installer-ajouter]');
		var fait = carte.querySelector('[data-installer-fait]');
		var plusTard = carte.querySelector('[data-installer-plus-tard]');
		var fermer = carte.querySelector('[data-installer-fermer]');
		var page = document.documentElement;
		var mesure = null;
		var fermee = false; // une invitation qui revient sur la même page ne la rouvre pas

		if (!telephone) {
			carte.querySelectorAll('[data-ordinateur]').forEach(function (el) { el.textContent = el.dataset.ordinateur; });
		}

		// La page s'allonge de la hauteur de la carte : rien ne reste caché dessous.
		function mesurer() { page.style.setProperty('--ec-inviter-h', carte.offsetHeight + 'px'); }

		function montrer() {
			if (!carte.hidden || fermee) { return; }
			carte.hidden = false;
			document.body.classList.add('ec-inviter-ouvert');
			mesurer();
			if (window.ResizeObserver) { mesure = new ResizeObserver(mesurer); mesure.observe(carte); }
			dire(carte.querySelector('h2').textContent + '. ' + carte.querySelector('.ec-petit').textContent);
		}
		function cacher() {
			var focus = carte.contains(document.activeElement);
			fermee = true;
			carte.hidden = true;
			document.body.classList.remove('ec-inviter-ouvert');
			page.style.removeProperty('--ec-inviter-h');
			if (mesure) { mesure.disconnect(); }
			if (focus) {
				var suite = document.querySelector('.ec-contenu') || document.body;
				suite.setAttribute('tabindex', '-1');
				suite.focus({preventScroll: true});
			}
		}
		function remercier() {
			noter({installee: true});
			if (carte.hidden || !merci.hidden) { return; }
			var focus = carte.contains(document.activeElement);
			etapes.hidden = true;
			ajouter.hidden = fait.hidden = plusTard.hidden = true;
			merci.hidden = fermer.hidden = false;
			if (focus) { merci.focus(); }
			dire(merci.textContent);
			setTimeout(function () { if (!carte.contains(document.activeElement)) { cacher(); } }, 8000);
		}
		function remettre() {
			var e = lire() || {};
			noter({plusTard: (e.plusTard || 0) + 1, le: Date.now()});
			cacher();
		}

		ajouter.addEventListener('click', function () {
			if (ios) {
				// Les gestes s'ouvrent dans la carte ; le bouton disparaît, le focus va aux gestes.
				// Hors de Safari, l'ajout se fera dans Safari : pas de « C'est fait » ici.
				etapes.hidden = false;
				ajouter.setAttribute('aria-expanded', 'true');
				ajouter.hidden = true;
				fait.hidden = horsSafari;
				etapes.focus();
				return;
			}
			if (!inviter(remercier, remettre)) { cacher(); }
		});
		fait.addEventListener('click', remercier);
		plusTard.addEventListener('click', remettre);
		fermer.addEventListener('click', cacher);
		carte.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { if (merci.hidden) { remettre(); } else { cacher(); } }
		});
		window.addEventListener('appinstalled', remercier);

		if (ios) {
			preparerIos(carte);
			ajouter.setAttribute('aria-expanded', 'false');
			montrer();
		} else {
			if (window.siInvitation) { montrer(); }
			document.addEventListener('si-invitation', montrer);
		}
	}

	// ---------- Bloc de Mon compte (toujours là tant que l'application n'est pas installée) ----------
	function brancherBloc() {
		var bouton = bloc.querySelector('[data-installer-ajouter]');
		var etapes = bloc.querySelector('[data-installer-etapes]');
		if (ios) {
			preparerIos(bloc);
			bouton.setAttribute('aria-expanded', 'false');
			bouton.addEventListener('click', function () {
				etapes.hidden = !etapes.hidden;
				bouton.setAttribute('aria-expanded', etapes.hidden ? 'false' : 'true');
				bouton.textContent = etapes.hidden ? 'Installer' : 'Masquer';
			});
			bloc.hidden = false;
			return;
		}
		function montrer() { bloc.hidden = false; }
		if (window.siInvitation) { montrer(); }
		document.addEventListener('si-invitation', montrer);
		bouton.addEventListener('click', function () {
			inviter(function () { noter({installee: true}); bloc.hidden = true; }, function () { bloc.hidden = true; });
		});
		window.addEventListener('appinstalled', function () { bloc.hidden = true; });
	}

	if (carte) { brancherCarte(); }
	if (bloc) { brancherBloc(); }
})();
