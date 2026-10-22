/*
 * Panneau « Coût de revient et marge » et optimiseur de laize de la
 * saisie (maquette 22). Recalcule la ligne ouverte côté serveur, sans
 * rien enregistrer. Affiché seulement à qui a le droit de voir les marges.
 */
(function () {
    'use strict';

    const panneau = document.querySelector('.js-cout-ligne');
    if (!panneau) {
        return;
    }
    const form = panneau.closest('form');
    const page = panneau.closest('.si-saisie');
    const selecteurLigne = page.dataset.ligne || '.detail-commande';
    const choixMachine = panneau.querySelector('.js-cout-machine');
    const nombre = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});
    const decimal = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 2});
    let machineChoisieALaMain = false;
    let derniereDemande = '';
    let attente = null;

    function f(valeur) {
        return nombre.format(Math.round(valeur || 0)).replace(/ /g, ' ');
    }

    function valeur(detail, nom) {
        const champ = detail.querySelector('[data-detail-field="' + nom + '"]');
        const v = champ ? parseFloat(String(champ.value || '').replace(',', '.')) : 0;

        return Number.isFinite(v) ? v : 0;
    }

    function ligneActive() {
        return page.querySelector('#collection-details > ' + selecteurLigne + '.est-active');
    }

    function finitions(detail) {
        const ids = [];
        detail.querySelectorAll('.js-finition-selectionnee:checked').forEach(function (c) { ids.push(c.value); });
        detail.querySelectorAll('.js-finition-manuelle select').forEach(function (s) { if (s.value) { ids.push(s.value); } });

        return ids;
    }

    /* Machine proposée : celle qui consomme le moins de support (moins de chute). */
    function consommation(laize, largeur, hauteur, quantite) {
        let meilleure = null;
        [[largeur, hauteur], [hauteur, largeur]].forEach(function (sens) {
            if (sens[0] <= 0 || sens[0] > laize) {
                return;
            }
            const parRangee = Math.max(1, Math.floor(laize / sens[0] + 1e-9));
            const surface = Math.ceil(quantite / parRangee) * sens[1] * laize;
            if (meilleure === null || surface < meilleure) {
                meilleure = surface;
            }
        });

        return meilleure;
    }

    function proposerMachine(largeur, hauteur, quantite) {
        if (machineChoisieALaMain || !choixMachine || largeur <= 0 || hauteur <= 0) {
            return;
        }
        let choix = null;
        Array.from(choixMachine.options).forEach(function (o) {
            const laize = parseFloat(o.dataset.laize);
            const surface = Number.isFinite(laize) ? consommation(laize, largeur / 100, hauteur / 100, quantite) : null;
            if (surface !== null && (choix === null || surface < choix.surface)) {
                choix = {valeur: o.value, surface: surface};
            }
        });
        if (choix) {
            choixMachine.value = choix.valeur;
        }
    }

    function texte(selecteur, contenu) {
        const el = panneau.querySelector(selecteur);
        if (el) {
            el.textContent = contenu;
        }
    }

    function dessinerLaize(pose, largeur, hauteur, quantite) {
        const zone = panneau.querySelector('.js-laize');
        if (!pose) {
            zone.innerHTML = '<small class="text-muted">' + (largeur > 0 && hauteur > 0 ? 'La pièce ne tient pas sur la laize de cette machine (raccords).' : 'Indiquez largeur, hauteur et quantité.') + '</small>';

            return;
        }
        const laize = parseFloat(choixMachine.selectedOptions[0].dataset.laize) * 100;
        const travers = pose.travers * 100;
        const long = pose.long * 100;
        const hauteurTotale = pose.rangees * long;
        const marge = Math.max(laize, hauteurTotale) * 0.02;
        let pieces = '';
        let restantes = quantite;
        for (let r = 0; r < pose.rangees && restantes > 0; r++) {
            for (let c = 0; c < pose.parRangee && restantes > 0; c++, restantes--) {
                const x = c * travers;
                const y = r * long;
                pieces += '<rect x="' + (x + marge / 2) + '" y="' + (y + marge / 2) + '" width="' + (travers - marge) + '" height="' + (long - marge) + '" rx="' + marge + '"></rect>'
                    + '<text x="' + (x + marge * 2) + '" y="' + (y + marge * 2) + '" font-size="' + Math.max(laize, hauteurTotale) * 0.045 + '">' + decimal.format(largeur) + ' × ' + decimal.format(hauteur) + '</text>';
            }
        }
        // Rouleau vu de dessus : la laize en largeur, le métrage vers le bas (au plus 4 rangées montrées).
        const visible = Math.min(hauteurTotale, long * 4);
        zone.innerHTML = '<svg viewBox="0 0 ' + laize + ' ' + visible + '" role="img" aria-label="' + quantite + ' pièce(s) posées sur la laize">'
            + '<rect class="si-laize-rouleau" x="0" y="0" width="' + laize + '" height="' + visible + '"></rect>' + pieces + '</svg>';
    }

    function afficher(r, ligne) {
        texte('.js-cout-matieres', f(r.matieres));
        texte('.js-cout-surface', r.surfaceConsommee > 0 ? '(' + decimal.format(r.surfaceConsommee) + ' m²)' : '');
        texte('.js-cout-encre', f(r.encre));
        texte('.js-cout-machine-temps', f(r.machine));
        texte('.js-cout-finitions', f(r.finitions));
        texte('.js-cout-total', f(r.coutTotal));
        texte('.js-cout-prix', f(ligne.ttc) + ' F');
        const marge = panneau.querySelector('.js-cout-marge');
        marge.textContent = r.marge === null ? '—' : decimal.format(r.marge) + ' %';
        marge.className = 'js-cout-marge ' + (r.marge === null ? '' : (r.marge >= 30 ? 'text-success' : (r.marge >= 15 ? 'text-warning' : 'text-danger')));

        const manquants = panneau.querySelector('.js-cout-manquants');
        manquants.hidden = !r.manquants.length;
        manquants.textContent = r.manquants.length ? 'Coût incomplet, il manque : ' + r.manquants.join(', ') + '.' : '';

        texte('.js-laize-largeur', r.laize ? decimal.format(r.laize) + ' m' : '—');
        texte('.js-laize-metres', r.pose ? decimal.format(r.pose.rangees * r.pose.long) + ' m' : '—');
        texte('.js-laize-chute', r.laize ? decimal.format(r.chute) + ' %' : '—');
        dessinerLaize(r.pose, ligne.largeur, ligne.longueur, ligne.quantite);
    }

    function calculer() {
        const detail = ligneActive();
        if (!detail) {
            return;
        }
        const ligne = {
            produit: (detail.querySelector('[data-detail-field="produit"]') || {}).value || null,
            largeur: valeur(detail, 'largeur'),
            longueur: valeur(detail, 'longueur'),
            quantite: Math.max(1, valeur(detail, 'quantite') || 1),
            nombreFaces: Math.max(1, valeur(detail, 'nombreFaces') || 1),
            finitions: finitions(detail),
            prixHt: valeur(detail, 'totalHt'),
            ttc: valeur(detail, 'totalTtc'),
        };
        proposerMachine(ligne.largeur, ligne.longueur, ligne.quantite);
        ligne.machine = choixMachine ? choixMachine.value : null;

        const titre = detail.querySelector('.detail-title');
        texte('.js-cout-titre', titre ? '· ' + titre.textContent.replace(/^Configurer la /, '') : '');

        const demande = JSON.stringify(ligne);
        if (demande === derniereDemande) {
            return;
        }
        derniereDemande = demande;

        fetch(panneau.dataset.url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': panneau.dataset.token},
            body: demande,
        }).then(function (reponse) {
            return reponse.ok ? reponse.json() : null;
        }).then(function (r) {
            if (r && demande === derniereDemande) {
                afficher(r, ligne);
            }
        }).catch(function () {});
    }

    function plusTard() {
        window.clearTimeout(attente);
        attente = window.setTimeout(calculer, 400);
    }

    if (choixMachine) {
        choixMachine.addEventListener('change', function () {
            machineChoisieALaMain = true;
            calculer();
        });
    }
    form.addEventListener('input', plusTard);
    form.addEventListener('change', plusTard);
    // Les prix sont posés par le script du formulaire sans événement ; la ligne ouverte peut changer au clic.
    window.setInterval(calculer, 1500);
    window.setTimeout(calculer, 300);
})();
