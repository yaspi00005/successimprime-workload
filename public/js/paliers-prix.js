/*
 * Paliers de prix (docs/produits.md, « Paliers de prix et grilles ») :
 * le même calcul que App\Service\Catalogue\PaliersPrix et TarifClient,
 * pour les aperçus (simulateur de la fiche produit, saisie du comptoir).
 * Le serveur refait le calcul et fait foi.
 *
 * Règle : le meilleur palier « par volume » (quantité ou surface, jamais
 * les deux), puis la remise de la grille du client (Partenaire,
 * Association), puis le prix négocié s'il est plus bas. Un revendeur
 * (B2B) garde son prix revendeur : ni palier ni grille.
 */
(function () {
    'use strict';

    const TAUX_MAX = 90;

    function borne(t) {
        return Math.min(TAUX_MAX, Math.max(0, Number(t) || 0));
    }

    /* Arrondi au franc comme PHP round() (0,5 vers le haut, en valeur absolue). */
    function arrondi(n) {
        return n < 0 ? -Math.round(-n) : Math.round(n);
    }

    function tauxPalier(p, prixBase) {
        if (p.remise !== null && p.remise !== undefined) {
            return borne(p.remise);
        }
        if (!prixBase || p.prix === null || p.prix === undefined) {
            return 0;
        }
        return borne((prixBase - p.prix) / prixBase * 100);
    }

    /* cfg : {prixBase, mode, quantite: [{de, prix, remise}], surface: [{m2, prix, remise}]} */
    function volume(cfg, pieces, surfaceTotale) {
        const r = {taux: 0, source: null, seuil: null, prochain: null};
        if (!cfg) {
            return r;
        }
        let tauxQ = 0, seuilQ = null, tauxS = 0, seuilS = null;
        (cfg.quantite || []).forEach(function (p) {
            if (pieces >= p.de) {
                tauxQ = tauxPalier(p, cfg.prixBase);
                seuilQ = p.de;
            } else if (r.prochain === null) {
                r.prochain = {source: 'quantite', seuil: p.de, taux: Math.round(tauxPalier(p, cfg.prixBase) * 10) / 10};
            }
        });
        if (cfg.mode === 'metre_carre') {
            const liste = cfg.surface || [];
            for (let i = 0; i < liste.length; i++) {
                if (surfaceTotale + 0.0005 >= liste[i].m2) {
                    tauxS = tauxPalier(liste[i], cfg.prixBase);
                    seuilS = liste[i].m2;
                } else {
                    r.prochain = {source: 'surface', seuil: liste[i].m2, taux: Math.round(tauxPalier(liste[i], cfg.prixBase) * 10) / 10};
                    break;
                }
            }
        }
        if (tauxQ <= 0 && tauxS <= 0) {
            return r;
        }
        if (tauxS > tauxQ) {
            r.taux = tauxS; r.source = 'surface'; r.seuil = seuilS;
        } else {
            r.taux = tauxQ; r.source = 'quantite'; r.seuil = seuilQ;
        }
        return r;
    }

    function net(prix, tauxVolume, tauxGrille) {
        return Math.max(0, arrondi(Math.max(0, prix) * (1 - borne(tauxVolume) / 100) * (1 - borne(tauxGrille) / 100)));
    }

    function remiseParUnite(prix, tauxVolume, tauxGrille) {
        return Math.max(0, arrondi(prix) - net(prix, tauxVolume, tauxGrille));
    }

    /*
     * Remise par unité due au client (TarifClient::calculer).
     * o : {prix, cfg, pieces, surfaceTotale, b2b, grille (en %), prixSpecial}
     */
    function calculer(o) {
        let v = {taux: 0, source: null, seuil: null, prochain: null};
        let g = 0;
        if (!o.b2b) {
            v = volume(o.cfg, o.pieces, o.surfaceTotale || 0);
            g = Number(o.grille) || 0;
        }
        let remise = remiseParUnite(o.prix, v.taux, g);
        if (o.prixSpecial !== null && o.prixSpecial !== undefined) {
            remise = Math.max(remise, Math.max(0, arrondi(o.prix) - Math.max(0, o.prixSpecial)));
        }
        return {remise: remise, volume: v, grille: g};
    }

    function nombre(n, dec) {
        return (Math.round(n * Math.pow(10, dec)) / Math.pow(10, dec)).toLocaleString('fr-FR');
    }

    function libelle(v) {
        if (!v || !v.source) {
            return '';
        }
        return v.source === 'surface'
            ? 'Remise dès ' + nombre(v.seuil, 2) + ' m² : −' + nombre(v.taux, 1) + ' %'
            : 'Palier dès ' + v.seuil + ' pièces : −' + nombre(v.taux, 1) + ' %';
    }

    function prochain(v) {
        if (!v || !v.prochain) {
            return '';
        }
        const p = v.prochain;
        return p.source === 'surface'
            ? 'Dès ' + nombre(p.seuil, 2) + ' m² : −' + nombre(p.taux, 1) + ' %'
            : 'Dès ' + p.seuil + ' pièces : −' + nombre(p.taux, 1) + ' %';
    }

    /* Configuration du comptoir qui a ces type, support et format (même règle que TarifClient::configurationDe). */
    function trouver(liste, type, support, format) {
        const id = function (x) { return x === null || x === undefined || x === '' ? '' : String(x); };
        return (liste || []).find(function (c) {
            return id(c.type) === id(type) && id(c.support) === id(support) && id(c.format) === id(format);
        }) || null;
    }

    /*
     * Saisie du comptoir : pose la remise due au client dans le champ de
     * la ligne tant que l'agent ne l'a pas changée à la main (le serveur
     * garde de toute façon au moins cette remise).
     * o : {detail, champRemise, info (élément ou null), prix, pieces, surfaceTotale, b2b, grille, type, support, format}
     */
    function ligneComptoir(o) {
        const detail = o.detail;
        const champ = o.champRemise;
        if (!detail || !champ) {
            return;
        }
        let liste = [];
        try { liste = JSON.parse(detail.dataset.paliers || '[]'); } catch (e) { liste = []; }
        const cfg = trouver(liste, o.type, o.support, o.format);
        const actuelle = Math.max(0, Number(String(champ.value || '0').replace(',', '.')) || 0);
        let auto = 0, calcul = null;
        if (cfg || (o.grille && !o.b2b)) {
            calcul = calculer({prix: o.prix, cfg: cfg, pieces: o.pieces, surfaceTotale: o.surfaceTotale, b2b: o.b2b, grille: o.grille, prixSpecial: cfg ? cfg.prixSpecial : null});
            auto = calcul.remise;
        }
        const precedente = detail.dataset.remiseAuto;
        // Remise encore « automatique » (pas changée à la main) : elle suit le calcul, à la baisse comme à la hausse.
        const geree = precedente === undefined ? (actuelle === 0 || actuelle === auto) : String(actuelle) === precedente;
        // Sinon, comme le serveur : jamais moins que la remise due au client.
        if ((geree || auto > actuelle) && actuelle !== auto && (cfg || precedente !== undefined || auto > 0)) {
            champ.value = String(auto);
        }
        if (geree || auto > actuelle) {
            detail.dataset.remiseAuto = String(auto);
        }
        if (o.info) {
            const morceaux = [];
            if (calcul && calcul.volume.source) { morceaux.push(libelle(calcul.volume)); }
            if (calcul && calcul.grille > 0) { morceaux.push('grille −' + nombre(calcul.grille, 1) + ' %'); }
            if (cfg && cfg.prixSpecial !== null && cfg.prixSpecial !== undefined) { morceaux.push('prix négocié ' + nombre(cfg.prixSpecial, 0) + ' F'); }
            const suite = calcul ? prochain(calcul.volume) : '';
            o.info.textContent = morceaux.length || suite ? morceaux.join(' · ') + (suite ? (morceaux.length ? ' · ' : '') + 'prochain : ' + suite.toLowerCase() : '') : '';
            o.info.hidden = o.info.textContent === '';
        }
    }

    /* Nouveau produit ou nouveau client : la remise automatique repart de zéro. */
    function oublier(detail) {
        if (detail) {
            delete detail.dataset.remiseAuto;
        }
    }

    window.SiPaliers = {
        volume: volume,
        net: net,
        remiseParUnite: remiseParUnite,
        calculer: calculer,
        libelle: libelle,
        prochain: prochain,
        trouver: trouver,
        tauxPalier: tauxPalier,
        ligneComptoir: ligneComptoir,
        oublier: oublier
    };
})();
