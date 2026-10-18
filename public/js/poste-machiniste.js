/* Poste machiniste (docs/poste-machiniste.md) : fenêtre « Retourner au prépresse »
   avec annotation sur l'aperçu, chronomètre du travail en cours, étiquettes. */
(() => {
	'use strict';

	// --- Fenêtre « Retourner au prépresse » (OP04) ---
	const fenetre = document.getElementById('retour');
	if (fenetre) {
		const ouvrir = (e) => { e?.preventDefault(); fenetre.classList.add('ouverte'); fenetre.querySelector('textarea')?.focus(); };
		const fermer = (e) => { e?.preventDefault(); fenetre.classList.remove('ouverte'); if (location.hash === '#retour') history.replaceState(null, '', location.pathname); };
		document.querySelectorAll('[data-si-pm-ouvrir]').forEach((a) => a.addEventListener('click', ouvrir));
		fenetre.querySelectorAll('[data-si-pm-fermer]').forEach((a) => a.addEventListener('click', fermer));
		fenetre.addEventListener('click', (e) => { if (e.target === fenetre) fermer(e); });
		document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && fenetre.classList.contains('ouverte')) fermer(e); });
		if (location.hash === '#retour') ouvrir();

		const feuille = fenetre.querySelector('[data-si-pm-dessin]');
		const formulaire = fenetre.querySelector('[data-si-pm-retour]');
		const champ = formulaire.querySelector('input[name="annotation"]');
		const ns = 'http://www.w3.org/2000/svg';
		const formes = [];
		let outil = 'cercle';
		let enCours = null;

		const svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 100 100');
		svg.setAttribute('preserveAspectRatio', 'none');
		feuille.appendChild(svg);
		const textes = document.createElement('div');
		feuille.appendChild(textes);

		const point = (e) => {
			const r = feuille.getBoundingClientRect();
			return { x: Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), y: Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)) };
		};
		const dessiner = () => {
			svg.innerHTML = '';
			textes.innerHTML = '';
			[...formes, ...(enCours ? [enCours] : [])].forEach((f) => {
				if (f.type === 'cercle') {
					const el = document.createElementNS(ns, 'ellipse');
					el.setAttribute('cx', (f.x1 + f.x2) * 50); el.setAttribute('cy', (f.y1 + f.y2) * 50);
					el.setAttribute('rx', Math.abs(f.x2 - f.x1) * 50); el.setAttribute('ry', Math.abs(f.y2 - f.y1) * 50);
					el.setAttribute('fill', 'none'); el.setAttribute('stroke', '#DE1028'); el.setAttribute('stroke-width', '2.5'); el.setAttribute('vector-effect', 'non-scaling-stroke');
					svg.appendChild(el);
				} else if (f.type === 'fleche') {
					const el = document.createElementNS(ns, 'line');
					el.setAttribute('x1', f.x1 * 100); el.setAttribute('y1', f.y1 * 100); el.setAttribute('x2', f.x2 * 100); el.setAttribute('y2', f.y2 * 100);
					el.setAttribute('stroke', '#DE1028'); el.setAttribute('stroke-width', '3'); el.setAttribute('vector-effect', 'non-scaling-stroke');
					svg.appendChild(el);
					const bout = document.createElementNS(ns, 'circle');
					bout.setAttribute('cx', f.x2 * 100); bout.setAttribute('cy', f.y2 * 100); bout.setAttribute('r', '1.6'); bout.setAttribute('fill', '#DE1028');
					svg.appendChild(bout);
				} else if (f.type === 'texte') {
					const s = document.createElement('span');
					s.className = 'si-pm-annot-texte';
					s.style.left = (f.x1 * 100) + '%'; s.style.top = (f.y1 * 100) + '%';
					s.textContent = f.texte;
					textes.appendChild(s);
				}
			});
			champ.value = JSON.stringify(formes);
		};

		fenetre.querySelectorAll('[data-outil]').forEach((b) => b.addEventListener('click', () => {
			outil = b.dataset.outil;
			fenetre.querySelectorAll('[data-outil]').forEach((x) => x.classList.toggle('actif', x === b));
		}));
		fenetre.querySelector('[data-annuler]')?.addEventListener('click', () => { formes.pop(); dessiner(); });

		feuille.addEventListener('pointerdown', (e) => {
			e.preventDefault();
			const p = point(e);
			if (outil === 'texte') {
				const texte = (window.prompt('Texte à poser sur le fichier :') || '').trim().slice(0, 80);
				if (texte) { formes.push({ type: 'texte', x1: p.x, y1: p.y, x2: p.x, y2: p.y, texte }); dessiner(); }
				return;
			}
			enCours = { type: outil, x1: p.x, y1: p.y, x2: p.x, y2: p.y };
			feuille.setPointerCapture(e.pointerId);
		});
		feuille.addEventListener('pointermove', (e) => {
			if (!enCours) return;
			const p = point(e);
			enCours.x2 = p.x; enCours.y2 = p.y;
			dessiner();
		});
		const finir = () => {
			if (!enCours) return;
			if (Math.abs(enCours.x2 - enCours.x1) + Math.abs(enCours.y2 - enCours.y1) > 0.02) {
				if (enCours.type === 'cercle') {
					enCours = { ...enCours, x1: Math.min(enCours.x1, enCours.x2), x2: Math.max(enCours.x1, enCours.x2), y1: Math.min(enCours.y1, enCours.y2), y2: Math.max(enCours.y1, enCours.y2) };
				}
				formes.push(enCours);
			}
			enCours = null;
			dessiner();
		};
		feuille.addEventListener('pointerup', finir);
		feuille.addEventListener('pointercancel', finir);

		formulaire.addEventListener('submit', (e) => {
			const motifs = formulaire.querySelectorAll('input[name="motifs[]"]:checked');
			if (motifs.length === 0) { e.preventDefault(); window.alert('Choisissez au moins un motif.'); return; }
			const autre = [...motifs].some((m) => m.value === 'autre');
			if (autre && !formulaire.querySelector('textarea').value.trim()) { e.preventDefault(); window.alert('Pour « Autre », expliquez le problème dans le commentaire.'); }
		});
	}

	// --- Chronomètre et avancement du travail en cours (OP06) ---
	const chrono = document.querySelector('[data-si-pm-chrono]');
	if (chrono) {
		const depart = Date.now() - Number(chrono.dataset.siPmChrono) * 1000;
		const duree = Number(chrono.dataset.duree || 0) * 60;
		const texte = chrono.querySelector('[data-texte]');
		const barre = chrono.querySelector('[data-barre]');
		const deux = (n) => String(n).padStart(2, '0');
		setInterval(() => {
			const s = Math.floor((Date.now() - depart) / 1000);
			if (texte) texte.textContent = deux(Math.floor(s / 3600)) + ':' + deux(Math.floor(s / 60) % 60) + ':' + deux(s % 60);
			if (barre && duree > 0) barre.style.width = Math.min(99, Math.floor(s * 100 / duree)) + '%';
		}, 1000);
	}

	// --- Étiquettes (OP05) : l'impression s'ouvre dans un onglet, la page se met à jour ---
	const impression = document.querySelector('[data-si-pm-imprimer]');
	if (impression) {
		impression.addEventListener('submit', () => setTimeout(() => location.reload(), 1500));
	}
	const colis = document.querySelector('[data-si-pm-colis]');
	if (colis) {
		colis.addEventListener('change', () => {
			const n = Math.max(1, Math.min(99, parseInt(colis.value, 10) || 1));
			const url = new URL(location.href);
			url.searchParams.set('colis', n);
			location.href = url.toString();
		});
	}
	// --- Estimation (OP03) : total et heure de fin recalculés pendant la saisie ---
	const estimer = document.querySelector('[data-si-pm-estimer]');
	if (estimer) {
		const lire = (v) => {
			const s = String(v || '').trim().toLowerCase();
			if (!s) return null;
			let m = s.match(/^(\d{1,2})\s*(?:h|:)\s*(\d{1,2})?\s*(?:min|mn)?$/);
			if (m) return Number(m[1]) * 60 + Number(m[2] || 0);
			m = s.match(/^(\d{1,4})\s*(?:min|mn|m)?$/);
			return m ? Number(m[1]) : null;
		};
		const duree = (n) => n < 60 ? n + ' min' : Math.floor(n / 60) + ' h ' + String(n % 60).padStart(2, '0');
		const deux = (n) => String(n).padStart(2, '0');
		const debut = Number(estimer.dataset.debut) * 1000;
		const total = estimer.querySelector('[data-si-pm-total]');
		const fin = estimer.querySelector('[data-si-pm-fin]');
		const delai = estimer.querySelector('[data-si-pm-delai]');
		const recalculer = () => {
			let somme = 0;
			estimer.querySelectorAll('[data-si-pm-etape]').forEach((c) => {
				const n = lire(c.value);
				c.classList.toggle('is-invalid', c.value.trim() !== '' && n === null);
				somme += n || 0;
			});
			if (total) total.textContent = duree(somme);
			const f = new Date(debut + somme * 60000);
			const auj = new Date();
			if (fin) fin.textContent = (f.toDateString() === auj.toDateString() ? '' : deux(f.getDate()) + '/' + deux(f.getMonth() + 1) + ' ') + deux(f.getHours()) + ':' + deux(f.getMinutes());
			if (delai) delai.textContent = f.getTime() <= Number(delai.dataset.promis) * 1000 ? '✓ dans les temps' : '⚠ en retard sur la date promise';
		};
		estimer.addEventListener('input', (e) => { if (e.target.matches('[data-si-pm-etape]')) recalculer(); });
	}
})();
