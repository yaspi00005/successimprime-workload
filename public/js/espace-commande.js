/*
 * Espace client, nouvelle commande (maquettes CO02 à CO04, téléphone
 * CO06) : une étape à la fois, prix et délais recalculés par le serveur à
 * chaque changement, aperçu à l'échelle, envoi et contrôle du fichier.
 * Sans ce script, les trois étapes se suivent sur la page et le
 * formulaire s'envoie tel quel : le serveur refait tous les calculs.
 */
(function () {
	'use strict';

	const form = document.getElementById('co-form');
	const source = document.getElementById('co-donnees');
	if (!form || !source) return;
	const D = JSON.parse(source.textContent);
	const doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	const NOMS = { 2: 'options', 3: 'fichier', 4: 'livraison et paiement' };
	let etape = D.etape;
	let derniere = null;
	let numero = 0;
	let minuteur = null;
	let fichierCourant = null;
	let tailleControlee = '';

	document.documentElement.classList.add('co-js');

	function $(s, racine) { return (racine || form).querySelector(s); }
	function $$(s, racine) { return Array.from((racine || form).querySelectorAll(s)); }
	function coche(nom) { const el = form.querySelector('input[name="' + nom + '"]:checked'); return el ? el.value : ''; }
	function f(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
	function nombre(v) {
		const n = parseFloat(String(v || '').replace(/\s/g, '').replace(',', '.'));
		return isFinite(n) && n > 0 ? n : null;
	}
	function texte(cle, valeur) {
		$$('[data-co-r="' + cle + '"]', document).forEach(function (el) { el.textContent = valeur; });
	}
	function matiere() {
		const r = form.querySelector('input[name=produit]:checked') || form.querySelector('[name=produit]');
		return D.matieres.find(function (m) { return r && String(m.id) === r.value; }) || D.matieres[0];
	}
	function finitions() {
		return $$('input[name="finitions[]"]:checked:not(:disabled)').map(function (i) { return +i.value; });
	}
	function cm(champ) {
		const n = nombre(form.elements[champ] && form.elements[champ].value);
		return n ? String(Math.round(n * 1000) / 10) : '';
	}
	function oeillets() {
		const i = $$('input[name="finitions[]"]:checked:not(:disabled)').find(function (x) { return x.dataset.coOeillets; });
		return i ? i.dataset.coOeillets : '';
	}

	/* ---------- Étapes ---------- */

	function montrer(n, defiler) {
		etape = n;
		form.dataset.etape = String(n);
		$$('[data-co-panneau]').forEach(function (p) { p.hidden = +p.dataset.coPanneau !== n; });
		$$('[data-co-onglet]', document).forEach(function (li) {
			const k = +li.dataset.coOnglet;
			li.classList.toggle('faite', k < n);
			li.classList.toggle('active', k === n);
			if (k === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
		});
		$$('[data-co-seulement]', document).forEach(function (el) { el.hidden = +el.dataset.coSeulement !== n; });
		$$('[data-co-reception]', document).forEach(function (el) { el.hidden = n < 4; });
		$$('[data-co-titre-tel]', document).forEach(function (el) { el.textContent = D.nomProduit + ' · ' + NOMS[n]; });
		const suivant = $('[data-co-suivant]');
		if (suivant) suivant.textContent = n < 4 ? 'Continuer ›' : $('[data-co-envoyer]').textContent;
		try {
			const url = new URL(window.location.href);
			url.searchParams.set('etape', String(n));
			history.replaceState(null, '', url);
		} catch (e) { /* ancien navigateur */ }
		if (n === 3) controlerFichierCourant();
		if (defiler) {
			window.scrollTo({ top: 0, behavior: doux ? 'smooth' : 'auto' });
			const titre = $('#co-t' + n);
			if (titre) { titre.setAttribute('tabindex', '-1'); titre.focus({ preventScroll: true }); }
		}
	}

	function valide(n) {
		if (n === 2 && derniere && derniere.erreur) {
			texte('erreur', derniere.erreur.replace(' en cm', ''));
			return false;
		}
		if (n === 3 && coche('fichier_mode') === 'creation' && !coche('creation')) {
			texte('erreur', 'Choisissez une de vos créations.');
			return false;
		}
		return true;
	}

	function aller(n) {
		for (let k = etape; k < n; k++) {
			if (!valide(k)) { montrer(k, true); return; }
		}
		montrer(n, true);
	}

	document.addEventListener('click', function (e) {
		const bouton = e.target.closest('[data-co-aller]');
		if (bouton && form.contains(bouton) || bouton && bouton.closest('.co-etapes')) {
			e.preventDefault();
			aller(+bouton.dataset.coAller);
		}
	});
	const retourTel = document.querySelector('[data-co-retour]');
	if (retourTel) {
		retourTel.addEventListener('click', function (e) {
			if (etape > 2) { e.preventDefault(); montrer(etape - 1, true); }
		});
	}
	const suivant = $('[data-co-suivant]');
	if (suivant) {
		suivant.addEventListener('click', function () {
			if (etape < 4) aller(etape + 1);
			else if (form.requestSubmit) form.requestSubmit($('[data-co-envoyer]'));
			else $('[data-co-envoyer]').click();
		});
	}
	// Entrée dans un champ des étapes 2 et 3 : étape suivante, pas d'envoi.
	form.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.tagName === 'INPUT' && etape < 4) {
			e.preventDefault();
			aller(etape + 1);
		}
	});

	/* ---------- Prix, délais, créneaux (serveur) ---------- */

	function demande() {
		return {
			produit: matiere().id,
			quantite: form.elements.quantite.value,
			largeur: form.elements.largeur ? form.elements.largeur.value : '',
			hauteur: form.elements.hauteur ? form.elements.hauteur.value : '',
			unite: 'm',
			finitions: finitions(),
			delai: coche('delai') || 'standard',
			reception: coche('reception') || 'retrait',
		};
	}

	async function estimer() {
		const n = ++numero;
		try {
			const r = await fetch(D.estimer, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': D.jeton, 'Accept': 'application/json' },
				body: JSON.stringify(demande()),
				credentials: 'same-origin',
			});
			const d = await r.json();
			if (n === numero) afficher(d);
		} catch (e) {
			if (n === numero) texte('erreur', 'Prix indisponible pour le moment : vérifiez votre connexion.');
		}
	}

	function plusTard() {
		clearTimeout(minuteur);
		minuteur = setTimeout(estimer, 250);
	}

	function afficher(d) {
		derniere = d;
		texte('erreur', (d.erreur || '').replace(' en cm', ''));
		if (d.pret) {
			texte('pret', d.pret.jour);
			texte('quand', d.pret.quand);
		}
		const r = d.recap;
		if (r) {
			texte('titre', r.titre);
			texte('sousTitre', r.sousTitre);
			const dl = $('[data-co-r="lignes"]', document);
			if (dl) {
				dl.textContent = '';
				r.lignes.forEach(function (l) {
					const div = document.createElement('div');
					const dt = document.createElement('dt');
					const dd = document.createElement('dd');
					dt.textContent = l.libelle;
					dd.textContent = l.valeur;
					div.append(dt, dd);
					if (l.reception) { div.dataset.coReception = ''; div.hidden = etape < 4; }
					dl.append(div);
				});
			}
			texte('barreInfo', r.quantite + ' · prête ' + (d.pret ? d.pret.jour : ''));
			if (r.totalTexte) {
				texte('total', r.totalTexte);
				texte('barreTotal', r.totalTexte);
				$$('[data-co-tout]').forEach(function (el) { el.textContent = r.totalTexte; });
			}
			if (r.acompte) {
				$$('[data-co-acompte]').forEach(function (el) { el.textContent = f(r.acompte.maintenant) + ' F'; });
				texte('acompteMaintenant', f(r.acompte.maintenant) + ' F');
				texte('acompteEnsuite', f(r.acompte.ensuite) + ' F');
			}
		} else if (D.voitMontants) {
			texte('total', '—');
			texte('barreTotal', '—');
		}
		const choix = $('[data-co-creneau]');
		if (choix && d.creneaux) {
			const avant = choix.value;
			choix.textContent = '';
			d.creneaux.forEach(function (c) {
				const o = document.createElement('option');
				o.value = c.valeur;
				o.textContent = c.libelle;
				o.selected = c.valeur === avant;
				choix.append(o);
			});
		}
		const frais = $('[data-co-frais-om]');
		if (frais) {
			const om = d.orangeMoney;
			frais.textContent = om ? 'Frais Orange Money en plus (fonds de soutien et retrait) : vous payez ' + (om.acompte ? f(om.acompte) + ' F pour l\'acompte, ' : '') + f(om.tout) + ' F pour le tout. Détaillés avant de payer.' : '';
		}
		libelleEnvoyer();
	}

	function libelleEnvoyer() {
		const bouton = $('[data-co-envoyer]');
		if (!bouton || !D.paiementEnLigne) return;
		const p = coche('paiement');
		const r = derniere && derniere.recap;
		if (p === 'acompte' && r && r.acompte) bouton.textContent = 'Payer ' + f(r.acompte.maintenant) + ' F et commander';
		else if (p === 'tout' && r && r.totalTexte) bouton.textContent = 'Payer ' + r.totalTexte + ' et commander';
		else bouton.textContent = 'Commander';
		bouton.classList.toggle('orange', p !== 'boutique');
		const barre = $('[data-co-suivant]');
		if (barre && etape === 4) barre.textContent = bouton.textContent;
	}

	/* ---------- Étape 2 : matière, taille, aperçu ---------- */

	function majMatiere() {
		const m = matiere();
		$$('[data-co-finitions]').forEach(function (bloc) {
			const actif = bloc.dataset.coFinitions === String(m.id);
			bloc.hidden = !actif;
			$$('input', bloc).forEach(function (i) { i.disabled = !actif; });
		});
		$$('[data-co-taille]').forEach(function (el) { el.hidden = !m.taille; });
		const q = form.elements.quantite;
		if (q && (+q.value || 0) < m.min) q.value = m.min;
		majFormats();
		apercu();
	}

	function majFormats() {
		const l = nombre(form.elements.largeur && form.elements.largeur.value);
		const h = nombre(form.elements.hauteur && form.elements.hauteur.value);
		$$('[data-co-format]').forEach(function (b) {
			const t = b.dataset.coFormat.split('x');
			b.classList.toggle('actif', l === +t[0] && h === +t[1]);
		});
	}

	$$('[data-co-format]').forEach(function (b) {
		b.addEventListener('click', function () {
			const t = b.dataset.coFormat.split('x');
			form.elements.largeur.value = (+t[0]).toFixed(2).replace('.', ',');
			form.elements.hauteur.value = (+t[1]).toFixed(2).replace('.', ',');
			changer();
		});
	});

	function apercu() {
		const zone = $('[data-co-apercu]');
		if (!zone) return;
		const m = matiere();
		const l = nombre(form.elements.largeur && form.elements.largeur.value);
		const h = nombre(form.elements.hauteur && form.elements.hauteur.value);
		zone.hidden = !m.taille || !l || !h;
		if (zone.hidden) return;
		const svg = $('svg', zone);
		const ns = 'http://www.w3.org/2000/svg';
		const largeurMax = 296, hauteurMax = 96;
		const echelle = Math.min(largeurMax / l, hauteurMax / h);
		const w = Math.max(8, l * echelle), hh = Math.max(8, h * echelle);
		const x0 = (300 - w) / 2, y0 = (100 - hh) / 2;
		svg.textContent = '';
		const fond = document.createElementNS(ns, 'rect');
		fond.setAttribute('x', x0); fond.setAttribute('y', y0); fond.setAttribute('width', w); fond.setAttribute('height', hh); fond.setAttribute('rx', 3);
		fond.setAttribute('class', 'co-apercu-fond');
		svg.append(fond);
		const nom = oeillets();
		let nombreOeillets = 0;
		if (nom) {
			const ecart = (nom.match(/(\d{2,3})\s*cm/) || [0, 50])[1] / 100;
			const nx = Math.ceil(l / ecart), ny = Math.ceil(h / ecart);
			const points = [];
			for (let i = 0; i <= nx; i++) { points.push([i / nx, 0]); points.push([i / nx, 1]); }
			for (let j = 1; j < ny; j++) { points.push([0, j / ny]); points.push([1, j / ny]); }
			nombreOeillets = 2 * nx + 2 * ny;
			const marge = Math.min(4, w / 20, hh / 6);
			points.forEach(function (p) {
				const c = document.createElementNS(ns, 'circle');
				c.setAttribute('cx', x0 + marge + p[0] * (w - 2 * marge));
				c.setAttribute('cy', y0 + marge + p[1] * (hh - 2 * marge));
				c.setAttribute('r', 1.8);
				c.setAttribute('class', 'co-apercu-oeillet');
				svg.append(c);
			});
		}
		const t = $('[data-co-apercu-texte]', zone);
		const metres = function (v) { return String(Math.round(v * 100) / 100).replace('.', ','); };
		t.textContent = metres(l) + ' m × ' + metres(h) + ' m' + (nombreOeillets ? ', ' + nombreOeillets + ' œillets par pièce' : '') + '. Votre fichier devra faire ' + f(l * 100) + ' × ' + f(h * 100) + ' cm.';
		const vignette = document.querySelector('.co-vignette');
		if (vignette) vignette.style.aspectRatio = String(Math.max(.3, Math.min(6, l / h)));
	}

	function changer() {
		majFormats();
		apercu();
		plusTard();
	}

	form.addEventListener('change', function (e) {
		const nom = e.target.name;
		if (nom === 'produit') majMatiere();
		if (nom === 'fichier_mode') majMode();
		if (nom === 'reception') majReception();
		if (nom === 'paiement') libelleEnvoyer();
		if (nom === 'adresse') majAdresse();
		if (nom === 'fichiers[]' && e.target.checked) controler(+e.target.value, null);
		if (['produit', 'quantite', 'largeur', 'hauteur', 'finitions[]', 'delai', 'reception'].indexOf(nom) >= 0) changer();
	});
	form.addEventListener('input', function (e) {
		if (['quantite', 'largeur', 'hauteur'].indexOf(e.target.name) >= 0) changer();
	});

	/* ---------- Étape 3 : fichier ---------- */

	function majMode() {
		const v = coche('fichier_mode') || 'fichier';
		$$('[data-co-mode]').forEach(function (el) { el.hidden = el.dataset.coMode !== v; });
		texte('erreur', '');
	}

	function etiquetteEtat(etat) {
		return { ok: 'OK', attention: 'Attention', erreur: 'À refaire', a_verifier: 'À vérifier' }[etat] || '';
	}

	async function controler(id, vue) {
		fichierCourant = { id: id, vue: vue || (fichierCourant && fichierCourant.id === id ? fichierCourant.vue : null) };
		tailleControlee = cm('largeur') + 'x' + cm('hauteur') + oeillets();
		const liste = $('[data-co-controles]');
		liste.textContent = '';
		const attente = document.createElement('li');
		attente.className = 'co-controle-vide';
		attente.textContent = 'Contrôle en cours…';
		liste.append(attente);
		try {
			const r = await fetch(D.controle.replace(/0$/, String(id)), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': D.jeton, 'Accept': 'application/json' },
				body: JSON.stringify({ largeur: form.elements.largeur ? form.elements.largeur.value : '', hauteur: form.elements.hauteur ? form.elements.hauteur.value : '', unite: 'm', oeillets: !!oeillets() }),
				credentials: 'same-origin',
			});
			if (!r.ok) throw new Error();
			const d = await r.json();
			liste.textContent = '';
			d.controles.forEach(function (c) {
				const li = document.createElement('li');
				li.className = 'co-controle-ligne ' + c.etat;
				const icone = document.createElement('span');
				icone.className = 'co-etat';
				icone.setAttribute('aria-label', etiquetteEtat(c.etat));
				icone.textContent = c.etat === 'ok' ? '✓' : (c.etat === 'a_verifier' ? '?' : '!');
				const corps = document.createElement('div');
				const b = document.createElement('b');
				b.textContent = c.libelle;
				const p = document.createElement('small');
				p.textContent = c.valeur;
				corps.append(b, p);
				li.append(icone, corps);
				liste.append(li);
			});
			montrerFichier(d, fichierCourant.vue);
		} catch (e) {
			liste.textContent = '';
			const li = document.createElement('li');
			li.className = 'co-controle-vide';
			li.textContent = 'Contrôle indisponible : le graphiste vérifiera votre fichier.';
			liste.append(li);
		}
	}

	function controlerFichierCourant() {
		if (fichierCourant && tailleControlee !== cm('largeur') + 'x' + cm('hauteur') + oeillets()) controler(fichierCourant.id, null);
	}

	function montrerFichier(d, vue) {
		const bloc = $('[data-co-vue]');
		const depot = $('[data-co-depot]');
		if (!bloc) return;
		bloc.hidden = false;
		if (depot) depot.hidden = true;
		const l = cm('largeur'), h = cm('hauteur');
		$('[data-co-vue-nom]').textContent = d.nom + (l && h && matiere().taille ? ' · ' + f(+l) + ' × ' + f(+h) + ' cm' : '') + ' · ' + d.taille;
		const image = $('[data-co-vue-image]');
		const icone = $('[data-co-vue-icone]');
		const adresse = vue && vue.image ? vue.image : null;
		image.hidden = !adresse;
		icone.hidden = !!adresse;
		if (adresse) image.src = adresse;
		icone.textContent = (d.nom.split('.').pop() || 'fichier').toUpperCase().slice(0, 4);
		const cadre = $('[data-co-vue-cadre]');
		const lm = nombre(form.elements.largeur && form.elements.largeur.value), hm = nombre(form.elements.hauteur && form.elements.hauteur.value);
		if (lm && hm && matiere().taille) {
			cadre.style.aspectRatio = String(Math.max(.3, Math.min(6, lm / hm)));
			const marge = Math.max(lm, hm) >= 1 ? 4 : (Math.max(lm, hm) >= .3 ? 2 : .4);
			const s = $('.co-securite', cadre);
			s.style.left = s.style.right = (100 * marge / (lm * 100)) + '%';
			s.style.top = s.style.bottom = (100 * marge / (hm * 100)) + '%';
		}
		const zone = $('[data-co-zone-oeillets]');
		if (zone) zone.hidden = !oeillets();
	}

	$$('[data-co-envoi]').forEach(function (champ) {
		champ.addEventListener('change', async function () {
			const fichier = this.files[0];
			if (!fichier) return;
			const etat = $('[data-co-envoi-etat]');
			const depot = $('[data-co-depot]');
			const barre = $('.ec-progres', depot);
			depot.hidden = false;
			$('[data-co-vue]').hidden = true;
			barre.hidden = false;
			etat.textContent = 'Envoi de ' + fichier.name + '…';
			try {
				const recu = await window.EspaceEnvoi.envoyer(fichier, {
					debut: D.envoi, jeton: D.jeton, largeur: matiere().taille ? cm('largeur') : '', hauteur: matiere().taille ? cm('hauteur') : '',
					progres: function (p) { barre.firstElementChild.style.width = p + '%'; },
				});
				// Le fichier envoyé remplace le précédent de cette commande.
				const zone = $('[data-co-fichiers-envoyes]');
				zone.textContent = '';
				$$('input[name="fichiers[]"]').forEach(function (i) { i.checked = false; });
				const cache = document.createElement('input');
				cache.type = 'hidden';
				cache.name = 'fichiers[]';
				cache.value = recu.id;
				zone.append(cache);
				etat.textContent = '';
				barre.hidden = true;
				const local = /^image\/(jpeg|png)$/.test(fichier.type) ? URL.createObjectURL(fichier) : null;
				controler(recu.id, { image: local });
			} catch (e) {
				etat.textContent = fichier.name + ' : ' + e.message;
				barre.hidden = true;
			}
			this.value = '';
		});
	});

	/* ---------- Étape 4 : réception et paiement ---------- */

	function majReception() {
		const livraison = coche('reception') === 'livraison';
		const bloc = $('[data-co-livraison]');
		if (bloc) bloc.hidden = !livraison;
		$$('[data-co-reste-quand]').forEach(function (el) { el.textContent = 'le reste ' + (livraison ? 'à la livraison' : 'au retrait'); });
		texte('ensuiteQuand', livraison ? 'À la livraison' : 'Au retrait');
		majAdresse();
	}

	function majAdresse() {
		const choix = $('[data-co-adresse]');
		const autre = $('[data-co-adresse-texte]');
		if (choix && autre) autre.hidden = choix.value !== '0';
	}

	form.addEventListener('submit', function (e) {
		const message = function (t, champ) {
			e.preventDefault();
			montrer(4, false);
			texte('erreur', t);
			const zone = $('.co-bas-final');
			let p = $('.co-erreur-envoi', zone);
			if (!p) { p = document.createElement('p'); p.className = 'co-erreur co-erreur-envoi'; p.setAttribute('role', 'alert'); zone.prepend(p); }
			p.textContent = t;
			if (champ) champ.focus();
		};
		if (derniere && derniere.erreur) { montrer(2, true); texte('erreur', derniere.erreur.replace(' en cm', '')); e.preventDefault(); return; }
		if (coche('reception') === 'livraison') {
			const choix = $('[data-co-adresse]');
			const autre = $('[data-co-adresse-texte]');
			if ((!choix || choix.value === '0') && autre && autre.value.trim() === '') return message('Indiquez l\'adresse de livraison à Bamako.', autre);
		}
		if (!form.elements.conditions.checked) return message('Cochez « J\'accepte les conditions de vente » pour envoyer la commande.', form.elements.conditions);
		$$('[data-co-envoyer], [data-co-suivant]').forEach(function (b) { b.disabled = true; });
	});

	/* ---------- Départ ---------- */

	majMatiere();
	majMode();
	majReception();
	montrer(etape, false);
	estimer();
})();
