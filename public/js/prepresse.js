/*
 * Prépresse (maquettes PQ01 à PQ04, docs/prepresse.md).
 *
 * - Liste : onglets, recherche, tri, commande dépliée, « + N autres »,
 *   alerte sonore à l'arrivée d'un travail à contrôler.
 * - Fenêtre « Déposer les fichiers » : envoi par morceaux de 5 Mo
 *   (FichierUploadController), puis association proposée par le serveur ;
 *   le serveur refait l'association à l'enregistrement.
 * - Relances : sélection, aperçu du message, liens WhatsApp ouverts un
 *   par un et notés envoyés.
 */
(function () {
	'use strict';

	const MORCEAU = 5 * 1024 * 1024;
	const VISIBLES = 10;
	const COULEURS = ['#0F4590', '#DE1028', '#1F9D55', '#DB2777', '#F2C230', '#7C3AED', '#E95B35', '#0891B2'];

	function echapper(texte) {
		const div = document.createElement('div');
		div.textContent = texte == null ? '' : String(texte);
		return div.innerHTML;
	}

	function taille(octets) {
		if (octets >= 1024 * 1024 * 1024) { return (octets / 1024 / 1024 / 1024).toFixed(1).replace('.', ',') + ' Go'; }
		if (octets >= 1024 * 1024) { return (octets / 1024 / 1024).toFixed(1).replace('.', ',') + ' Mo'; }
		return Math.max(1, Math.round(octets / 1024)) + ' Ko';
	}

	/* ------------------------------------------------------------------
	 * Liste (PQ01)
	 * ------------------------------------------------------------------ */
	const liste = document.querySelector('[data-pq-liste]');
	if (liste) {
		const conteneur = liste.querySelector('[data-pq-commandes]');
		const commandes = Array.from(liste.querySelectorAll('[data-pq-commande]'));
		const recherche = liste.querySelector('[data-pq-recherche]');
		const tri = liste.querySelector('[data-pq-tri]');
		const plus = liste.querySelector('[data-pq-plus]');
		const vide = liste.querySelector('[data-pq-vide]');
		const parametres = new URLSearchParams(window.location.search);
		let onglet = (liste.querySelector('.pq-onglets a.actif') || {}).dataset ? liste.querySelector('.pq-onglets a.actif').dataset.pqOnglet : 'tout';
		let toutVoir = false;

		if (recherche && parametres.get('q')) {
			recherche.value = parametres.get('q');
		}

		function appliquer() {
			const texte = recherche ? recherche.value.trim().toLowerCase() : '';
			const ordre = tri ? tri.value : 'anciens';
			commandes.sort(function (a, b) {
				if (ordre === 'recents') { return Number(b.dataset.date) - Number(a.dataset.date); }
				if (ordre === 'livraison') { return Number(a.dataset.livraison) - Number(b.dataset.livraison) || Number(a.dataset.date) - Number(b.dataset.date); }
				return Number(a.dataset.date) - Number(b.dataset.date);
			}).forEach(function (c) { conteneur.appendChild(c); });

			let visibles = 0;
			let caches = 0;
			commandes.forEach(function (c) {
				const dansOnglet = onglet === 'tout' || c.dataset.onglets.split(' ').indexOf(onglet) !== -1;
				const trouve = texte === '' || c.dataset.recherche.indexOf(texte) !== -1;
				if (dansOnglet && trouve) {
					visibles++;
					const montrer = toutVoir || visibles <= VISIBLES || c.classList.contains('ouverte');
					c.hidden = !montrer;
					if (!montrer) { caches++; }
				} else {
					c.hidden = true;
				}
			});
			if (vide) { vide.hidden = visibles > 0; }
			if (plus) {
				plus.hidden = caches === 0;
				const libelle = (liste.querySelector('.pq-onglets a[data-pq-onglet="' + onglet + '"]') || {}).textContent || '';
				plus.textContent = '+ ' + caches + ' autre' + (caches > 1 ? 's' : '') + ' commande' + (caches > 1 ? 's' : '') + (onglet === 'tout' ? '' : ' · ' + libelle.split('·')[0].trim().toLowerCase());
			}
		}

		function choisirOnglet(cle) {
			onglet = cle;
			toutVoir = false;
			liste.querySelectorAll('.pq-onglets a').forEach(function (a) { a.classList.toggle('actif', a.dataset.pqOnglet === cle); });
			liste.querySelectorAll('.pq-kpi[data-pq-onglet]').forEach(function (a) { a.classList.toggle('actif', a.dataset.pqOnglet === cle); });
			const url = new URL(window.location.href);
			url.searchParams.set('onglet', cle);
			window.history.replaceState(null, '', url);
			appliquer();
		}

		liste.querySelectorAll('[data-pq-onglet]').forEach(function (a) {
			a.addEventListener('click', function (e) {
				e.preventDefault();
				choisirOnglet(a.dataset.pqOnglet);
			});
		});
		if (recherche) {
			recherche.addEventListener('input', appliquer);
			recherche.form.addEventListener('submit', function (e) { e.preventDefault(); appliquer(); });
		}
		if (tri) { tri.addEventListener('change', appliquer); }
		if (plus) { plus.addEventListener('click', function () { toutVoir = true; appliquer(); }); }

		commandes.forEach(function (c) {
			const tete = c.querySelector('[data-pq-deplier]');
			const formats = c.querySelector('.pq-formats');
			const bouton = c.querySelector('.pq-chevron');
			tete.addEventListener('click', function (e) {
				if (e.target.closest('a, [data-pq-deposer]')) { return; }
				const ouvrir = formats.hidden;
				formats.hidden = !ouvrir;
				c.classList.toggle('ouverte', ouvrir);
				bouton.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
			});
			c.querySelectorAll('[data-pq-deposer]').forEach(function (b) {
				b.addEventListener('click', function (e) {
					e.stopPropagation();
					ouvrirDepot(c, b.dataset.pqLigne || null, []);
				});
			});
			// « glisser ici » sur un format : la fenêtre s'ouvre avec ce fichier, pour ce format.
			c.querySelectorAll('[data-pq-zone-ligne]').forEach(function (zone) {
				zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('survol'); });
				zone.addEventListener('dragleave', function () { zone.classList.remove('survol'); });
				zone.addEventListener('drop', function (e) {
					e.preventDefault();
					zone.classList.remove('survol');
					ouvrirDepot(c, zone.dataset.pqZoneLigne, Array.from(e.dataTransfer.files));
				});
				zone.addEventListener('click', function () { ouvrirDepot(c, zone.dataset.pqZoneLigne, [], true); });
				zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); zone.click(); } });
			});
		});

		const ouverte = liste.querySelector('.pq-cmd.ouverte');
		appliquer();
		if (ouverte) { ouverte.scrollIntoView({ block: 'center' }); }

		/* Alerte sonore : un nouveau travail à contrôler (comme l'ancienne file). */
		let connus = new Set((liste.dataset.idsAControler || '').split(',').filter(Boolean).map(Number));
		let contexte = null;
		function bip() {
			try {
				contexte = contexte || new (window.AudioContext || window.webkitAudioContext)();
				const o = contexte.createOscillator();
				const g = contexte.createGain();
				o.frequency.value = 880; g.gain.value = 0.2;
				o.connect(g); g.connect(contexte.destination);
				o.start(); o.stop(contexte.currentTime + 0.25);
			} catch (e) { /* Audio indisponible. */ }
		}
		setInterval(function () {
			fetch(liste.dataset.verifierUrl, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
				const ids = (d.ids || []).map(Number);
				if (ids.some(function (id) { return !connus.has(id); })) { bip(); }
				connus = new Set(ids);
			}).catch(function () { /* Réseau coupé : prochain passage. */ });
		}, 30000);
	}

	/* ------------------------------------------------------------------
	 * Fenêtre « Déposer les fichiers » (PQ02)
	 * ------------------------------------------------------------------ */
	const modale = document.getElementById('pq-depot');
	let etat = null;

	function urlId(modele, id) { return modele.replace('__ID__', id); }

	function ouvrirDepot(article, ligneForcee, fichiers, choisir) {
		if (!modale) { return; }
		etat = { commande: article.dataset.pqCommande, formats: [], fichiers: [], partage: [], forcee: ligneForcee, envois: 0 };
		modale.querySelector('[data-pq-depot-lignes]').innerHTML = '';
		modale.querySelector('[data-pq-depot-table]').hidden = true;
		modale.querySelector('[data-pq-depot-erreur]').hidden = true;
		modale.querySelector('[data-pq-resume]').innerHTML = '<span class="pq-muet">Aucun fichier pour l\'instant.</span>';
		modale.querySelector('[data-pq-enregistrer]').disabled = true;
		modale.querySelector('#pq-depot-titre').textContent = 'Déposer les fichiers';
		basculer('ordinateur');
		if (typeof modale.showModal === 'function') { modale.showModal(); } else { modale.setAttribute('open', ''); }
		fetch(article.dataset.depotUrl, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
			etat.formats = d.formats;
			modale.querySelector('#pq-depot-titre').textContent = 'Déposer les fichiers · ' + d.commande.numero + (d.commande.client ? ' · ' + d.commande.client : '');
			modale.querySelector('[data-pq-rangement]').innerHTML = d.dossierClient
				? 'Les fichiers sont aussi rangés dans <b>' + echapper(d.dossierClient) + '</b>'
				: 'Dossier des clients non réglé (Réglages › Dossiers clients) : les fichiers restent seulement dans l\'ERP.';
			afficherPartage(d.partage);
			if (fichiers && fichiers.length) { ajouterFichiers(fichiers); }
			if (choisir) { modale.querySelector('[data-pq-fichiers]').click(); }
		}).catch(function () { erreur('La commande n\'a pas pu être chargée. Rechargez la page.'); });
	}

	function basculer(source) {
		modale.querySelectorAll('[data-pq-source]').forEach(function (b) { b.classList.toggle('actif', b.dataset.pqSource === source); });
		modale.querySelectorAll('[data-pq-panneau]').forEach(function (p) { p.hidden = p.dataset.pqPanneau !== source; });
	}

	function erreur(texte) {
		const p = modale.querySelector('[data-pq-depot-erreur]');
		p.textContent = texte;
		p.hidden = !texte;
	}

	function afficherPartage(fichiers) {
		const boite = modale.querySelector('[data-pq-partage-liste]');
		modale.querySelector('[data-pq-partage-nombre]').textContent = fichiers.length ? '(' + fichiers.length + ')' : '';
		if (!fichiers.length) {
			boite.innerHTML = '<p class="pq-muet">Aucun fichier du dossier partagé ne semble être pour cette commande.</p>';
			return;
		}
		boite.innerHTML = fichiers.map(function (f, i) {
			return '<label class="d-flex align-items-center gap-2 mb-1"><input type="checkbox" value="' + echapper(f.nom) + '" data-pq-partage-choix' + (f.pret ? '' : ' disabled') + '> <b>' + echapper(f.nom) + '</b> <span class="pq-muet">' + taille(f.taille) + ' · ' + echapper(f.raison) + (f.pret ? '' : ' · copie en cours') + '</span></label>';
		}).join('');
		boite.querySelectorAll('[data-pq-partage-choix]').forEach(function (c) {
			c.addEventListener('change', function () {
				etat.partage = Array.from(boite.querySelectorAll('[data-pq-partage-choix]:checked')).map(function (x) { return x.value; });
				associer();
			});
		});
	}

	function ajouterFichiers(fichiers) {
		modale.querySelector('[data-pq-depot-table]').hidden = false;
		Array.from(fichiers).forEach(function (fichier) {
			const n = etat.fichiers.length;
			const entree = { nom: fichier.name, jeton: null, ligne: null, cle: null, forcee: etat.forcee };
			etat.fichiers.push(entree);
			const tr = document.createElement('tr');
			tr.dataset.pqEnvoi = String(n);
			tr.innerHTML = '<td><div class="pq-nom-fichier"><span class="pq-pastille" style="background:' + COULEURS[n % COULEURS.length] + '"></span><span>' + echapper(fichier.name) + '<span class="pq-envoi"><i></i></span><span class="pq-muet" data-pq-etat>envoi…</span></span></div></td>'
				+ '<td class="pq-fleche">→</td><td data-pq-format><span class="pq-muet">—</span></td><td data-pq-par></td><td class="pq-muet" data-pq-dim>' + taille(fichier.size) + '</td>';
			modale.querySelector('[data-pq-depot-lignes]').appendChild(tr);
			entree.ligne = tr;
			envoyer(fichier, entree);
		});
		majBouton();
	}

	async function envoyer(fichier, entree) {
		const barre = entree.ligne.querySelector('.pq-envoi i');
		const texte = entree.ligne.querySelector('[data-pq-etat]');
		etat.envois++;
		majBouton();
		try {
			const nombre = Math.max(1, Math.ceil(fichier.size / MORCEAU));
			const init = await fetch(modale.dataset.uploadInitialiser, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': modale.dataset.uploadCsrf },
				body: JSON.stringify({ nom: fichier.name, typeMime: fichier.type || 'application/octet-stream', taille: fichier.size, nombreMorceaux: nombre })
			});
			const d = await init.json();
			if (!init.ok || !d.jeton) { throw new Error(d.message || 'Envoi refusé (750 Mo au plus).'); }
			for (let i = 0; i < nombre; i++) {
				const corps = new FormData();
				corps.append('morceau', fichier.slice(i * MORCEAU, Math.min(fichier.size, (i + 1) * MORCEAU)), fichier.name);
				const r = await fetch(modale.dataset.uploadMorceau.replace('__JETON__', d.jeton).replace('__INDEX__', i), { method: 'POST', headers: { 'X-CSRF-TOKEN': modale.dataset.uploadCsrf }, body: corps });
				const m = await r.json();
				if (!r.ok) { throw new Error(m.message || 'Envoi interrompu.'); }
				barre.style.width = (typeof m.progression === 'number' ? m.progression : Math.round((i + 1) / nombre * 100)) + '%';
			}
			entree.jeton = d.jeton;
			entree.cle = 'j:' + d.jeton;
			texte.textContent = '';
			entree.ligne.querySelector('.pq-envoi').hidden = true;
		} catch (e) {
			texte.textContent = 'Échec : ' + e.message;
			texte.classList.add('pq-erreur');
		}
		etat.envois--;
		associer();
	}

	let enCours = null;
	function associer() {
		if (!etat || etat.envois > 0) { majBouton(); return; }
		const jetons = etat.fichiers.filter(function (f) { return f.jeton; }).map(function (f) { return f.jeton; });
		if (!jetons.length && !etat.partage.length) { majBouton(); return; }
		if (enCours) { enCours.abort(); }
		enCours = new AbortController();
		fetch(urlId(modale.dataset.associer, etat.commande), {
			method: 'POST', signal: enCours.signal,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': modale.dataset.csrf },
			body: JSON.stringify({ jetons: jetons, partage: etat.partage })
		}).then(function (r) { return r.json(); }).then(function (d) {
			afficherAssociation(d);
		}).catch(function (e) { if (e.name !== 'AbortError') { erreur('L\'association n\'a pas pu être calculée.'); } });
	}

	function optionsFormats(choisi) {
		return '<option value="">Choisir le format ▾</option>' + etat.formats.map(function (f) {
			return '<option value="' + f.id + '"' + (String(f.id) === String(choisi) ? ' selected' : '') + '>' + echapper(f.libelle) + (f.recu ? ' · déjà reçu' : '') + '</option>';
		}).join('');
	}

	function afficherAssociation(d) {
		const corps = modale.querySelector('[data-pq-depot-lignes]');
		modale.querySelector('[data-pq-depot-table]').hidden = false;
		// Les fichiers du dossier partagé ont leur propre ligne.
		corps.querySelectorAll('tr[data-pq-partage]').forEach(function (tr) { tr.remove(); });
		const resume = { sur: 0, verifier: 0, choisir: 0 };
		d.fichiers.forEach(function (f, i) {
			let tr;
			if (f.source === 'depot') {
				const entree = etat.fichiers.find(function (x) { return x.cle === f.cle; });
				if (!entree) { return; }
				tr = entree.ligne;
				if (entree.forcee) {
					// Déposé sur un format précis : ce format, choisi par la personne.
					f = Object.assign({}, f, { format: entree.forcee, certitude: 'sur', libelle: 'format choisi' });
				}
			} else {
				tr = document.createElement('tr');
				tr.dataset.pqPartage = f.cle;
				tr.innerHTML = '<td><div class="pq-nom-fichier"><span class="pq-pastille" style="background:#6B7280"></span><span>' + echapper(f.nom) + '<span class="pq-muet d-block">dossier partagé</span></span></div></td><td class="pq-fleche">→</td><td data-pq-format></td><td data-pq-par></td><td class="pq-muet" data-pq-dim></td>';
				corps.appendChild(tr);
			}
			tr.dataset.pqCle = f.cle;
			const select = document.createElement('select');
			select.className = 'pq-choix ' + f.certitude;
			select.dataset.pqChoix = f.cle;
			select.dataset.auto = f.format == null ? '' : String(f.format);
			select.innerHTML = optionsFormats(f.format);
			select.setAttribute('aria-label', 'Format pour ' + f.nom);
			select.addEventListener('change', function () {
				select.className = 'pq-choix ' + (select.value ? 'sur' : 'choisir');
				majResume();
			});
			const cellule = tr.querySelector('[data-pq-format]');
			cellule.innerHTML = '';
			cellule.appendChild(select);
			const badge = f.certitude === 'sur' ? 'vert' : (f.certitude === 'verifier' ? 'jaune' : 'rouge-clair');
			tr.querySelector('[data-pq-par]').innerHTML = '<span class="pq-badge ' + badge + '">' + echapper(f.libelle) + '</span>';
			tr.querySelector('[data-pq-dim]').textContent = f.dimensions;
			resume[f.certitude]++;
		});
		majResume();
		majBouton();
	}

	function majResume() {
		const r = { sur: 0, verifier: 0, choisir: 0 };
		modale.querySelectorAll('[data-pq-choix]').forEach(function (s) {
			if (!s.value) { r.choisir++; } else if (s.classList.contains('verifier') && s.value === s.dataset.auto) { r.verifier++; } else { r.sur++; }
		});
		const total = r.sur + r.verifier + r.choisir;
		modale.querySelector('[data-pq-resume]').innerHTML = total === 0 ? '<span class="pq-muet">Aucun fichier pour l\'instant.</span>'
			: '<span class="s">' + r.sur + ' sûr' + (r.sur > 1 ? 's' : '') + '</span> · <span class="v">' + r.verifier + ' à vérifier</span> · <span class="c">' + r.choisir + ' à choisir</span>'
			+ (r.choisir ? '<div class="pq-muet fw-normal">Un fichier sans format n\'est pas rattaché.</div>' : '');
		const n = etat.fichiers.length + etat.partage.length;
		modale.querySelector('[data-pq-depot-sous-titre]').textContent = n + ' fichier' + (n > 1 ? 's' : '') + ' · associé' + (n > 1 ? 's' : '') + ' automatiquement aux formats de la commande';
	}

	function majBouton() {
		if (!modale || !etat) { return; }
		const pret = etat.envois === 0 && modale.querySelectorAll('[data-pq-choix]').length > 0;
		modale.querySelector('[data-pq-enregistrer]').disabled = !pret;
	}

	if (modale) {
		const zone = modale.querySelector('[data-pq-zone]');
		const champ = modale.querySelector('[data-pq-fichiers]');
		champ.addEventListener('change', function () { ajouterFichiers(champ.files); champ.value = ''; });
		zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('survol'); });
		zone.addEventListener('dragleave', function () { zone.classList.remove('survol'); });
		zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('survol'); ajouterFichiers(e.dataTransfer.files); });
		modale.querySelectorAll('[data-pq-source]').forEach(function (b) { b.addEventListener('click', function () { basculer(b.dataset.pqSource); }); });
		// Fermée sans enregistrer : les fichiers déjà envoyés sont retirés (pas de fichier orphelin sur le serveur).
		modale.addEventListener('close', function () {
			if (!etat || etat.enregistre) { return; }
			etat.fichiers.forEach(function (f) {
				if (!f.jeton) { return; }
				fetch(modale.dataset.uploadSupprimer.replace('__JETON__', f.jeton), { method: 'POST', headers: { 'X-CSRF-TOKEN': modale.dataset.uploadCsrf } }).catch(function () {});
			});
			etat.fichiers = [];
		});
		modale.querySelector('[data-pq-enregistrer]').addEventListener('click', function () {
			const bouton = this;
			const choix = {};
			modale.querySelectorAll('[data-pq-choix]').forEach(function (s) {
				// Une correction (ou un format choisi) part telle quelle ; sinon le serveur décide.
				choix[s.dataset.pqChoix] = s.value === s.dataset.auto ? 'auto' : s.value;
			});
			bouton.disabled = true;
			bouton.textContent = 'Enregistrement…';
			fetch(urlId(modale.dataset.enregistrer, etat.commande), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': modale.dataset.csrf },
				body: JSON.stringify({ jetons: etat.fichiers.filter(function (f) { return f.jeton; }).map(function (f) { return f.jeton; }), partage: etat.partage, choix: choix })
			}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (x) {
				if (!x.ok) { throw new Error(x.d.message || 'Enregistrement refusé.'); }
				etat.enregistre = true;
				window.location.href = x.d.retour;
			}).catch(function (e) {
				erreur(e.message);
				bouton.disabled = false;
				bouton.textContent = 'Enregistrer · passer en « À contrôler »';
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Relances (PQ04)
	 * ------------------------------------------------------------------ */
	const relances = document.querySelector('[data-pq-relances]');
	if (relances) {
		const cases = Array.from(relances.querySelectorAll('[data-pq-relance-case]'));
		const bouton = relances.querySelector('[data-pq-relancer]');
		const apercu = relances.querySelector('[data-pq-apercu]');
		const liens = relances.querySelector('[data-pq-liens]');

		function choisies() { return cases.filter(function (c) { return c.checked; }); }
		function maj() {
			const n = choisies().length;
			bouton.disabled = n === 0;
			bouton.innerHTML = '<i class="fab fa-whatsapp"></i> Relancer la sélection (' + n + ')';
			const premiere = choisies()[0];
			if (premiere) { apercu.textContent = premiere.dataset.message; }
		}
		cases.forEach(function (c) {
			c.addEventListener('change', maj);
			c.closest('tr').addEventListener('click', function (e) {
				if (e.target.closest('a, input, button, form')) { return; }
				apercu.textContent = c.dataset.message;
			});
		});
		maj();

		function noter(ids) {
			const corps = new FormData();
			ids.forEach(function (id) { corps.append('commandes[]', id); });
			return fetch(relances.dataset.envoyee, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': relances.dataset.csrf }, body: corps }).then(function (r) { return r.json(); });
		}

		// Un lien wa.me ouvert depuis la page : la relance est notée envoyée.
		relances.addEventListener('click', function (e) {
			const lien = e.target.closest('[data-pq-wa]');
			if (!lien) { return; }
			noter([lien.dataset.pqWa]).then(function () {
				lien.classList.add('vert');
				lien.innerHTML = '<i class="fas fa-check"></i> ' + echapper(lien.dataset.numero) + ' · envoyé';
			});
		});

		bouton.addEventListener('click', function () {
			const selection = choisies();
			liens.innerHTML = '<p class="pq-muet mb-1">Ouvrez chaque lien : WhatsApp s\'ouvre avec le message prêt. Chaque lien ouvert est noté envoyé.</p>' + selection.map(function (c) {
				return '<a class="pq-btn" target="_blank" rel="noopener" href="' + echapper(c.dataset.wa) + '" data-pq-wa="' + c.value + '" data-numero="' + echapper(c.dataset.numero) + '"><i class="fab fa-whatsapp"></i> ' + echapper(c.dataset.numero) + ' · ' + echapper(c.dataset.client) + '</a>';
			}).join('');
			liens.hidden = false;
			// Le premier s'ouvre tout de suite (les navigateurs bloquent l'ouverture de plusieurs onglets d'un coup).
			const premier = liens.querySelector('[data-pq-wa]');
			if (premier) { premier.click(); }
		});
	}
})();
