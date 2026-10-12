/*
 * Espace client : envoi d'un fichier par morceaux (jusqu'à 500 Mo).
 * Une coupure de réseau ne fait pas tout recommencer : le serveur dit
 * où reprendre. Utilisé par « Mes fichiers » et « Nouvelle commande ».
 */
(function () {
	'use strict';

	function attendre(ms) {
		return new Promise(function (r) { setTimeout(r, ms); });
	}

	async function appeler(url, jeton, corps, typeCorps) {
		const reponse = await fetch(url, {
			method: 'POST',
			headers: { 'X-CSRF-TOKEN': jeton, 'Content-Type': typeCorps, 'Accept': 'application/json' },
			body: corps,
			credentials: 'same-origin',
		});
		let donnees = {};
		try { donnees = await reponse.json(); } catch (e) { /* page d'erreur */ }
		if (!reponse.ok) {
			const erreur = new Error(donnees.erreur || (reponse.status === 403 ? 'La page a expiré : rechargez-la.' : 'Envoi interrompu.'));
			erreur.definitive = reponse.status === 422 || reponse.status === 403 || reponse.status === 404;
			throw erreur;
		}
		return donnees;
	}

	/**
	 * @param {File} fichier
	 * @param {{debut: string, jeton: string, logo?: boolean, largeur?: string, hauteur?: string, progres?: function(number)}} options
	 * @returns {Promise<{id: number, nom: string, controle: {etat: string, libelle: string, texte: string}}>}
	 */
	async function envoyer(fichier, options) {
		const debut = await appeler(options.debut, options.jeton, JSON.stringify({ nom: fichier.name, taille: fichier.size, logo: !!options.logo }), 'application/json');
		const taille = debut.morceau;
		let position = debut.recu || 0;
		let essais = 0;
		const parametres = new URLSearchParams();
		if (options.largeur) parametres.set('largeur', options.largeur);
		if (options.hauteur) parametres.set('hauteur', options.hauteur);
		for (;;) {
			parametres.set('position', String(position));
			try {
				const etat = await appeler(options.debut + '/' + debut.jeton + '?' + parametres, options.jeton, fichier.slice(position, position + taille), 'application/octet-stream');
				essais = 0;
				position = etat.recu;
				if (options.progres) options.progres(Math.round(100 * position / fichier.size));
				if (etat.termine) return etat.fichier;
			} catch (e) {
				if (e.definitive || ++essais > 5) throw e;
				await attendre(1000 * essais * essais); // réseau coupé : on réessaie
			}
		}
	}

	window.EspaceEnvoi = { envoyer: envoyer };
})();
