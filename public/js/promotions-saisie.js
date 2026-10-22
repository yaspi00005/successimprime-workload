/*
 * Promotions à la saisie d'un devis ou d'une commande (docs/promotions.md).
 *
 * Aperçu seulement : à l'enregistrement, le serveur recalcule la promotion
 * de chaque ligne (PromotionsSaisie) et ne reprend du navigateur que le
 * code promo tapé et le choix de retirer la promotion d'une ligne.
 *
 * Le formulaire (commandes/_form.html.twig ou devis/_form.html.twig)
 * appelle SiPromotions.remise(ligne) dans son calcul pour ajouter la
 * remise de la promotion à la remise saisie, puis SiPromotions.calculee()
 * avec le total avant remise ; ce script demande alors au serveur la
 * promotion des lignes (une requête pour toutes, après une courte pause)
 * et l'affiche dans la zone .js-promo-ligne de chaque ligne.
 *
 * Code promo généré (docs/promotions.md, « Codes promo ») : la même
 * requête dit si le code tapé est valable et ce qu'il retire à la
 * commande ; le message s'affiche sous le champ et le récapitulatif
 * (saisie-commande.js) lit SiPromotions.codeCommande().
 */
(function () {
    'use strict';

    let reglages = null;
    let minuteur = null;
    let derniere = '';
    let numero = 0;
    let codeCommande = {ht: 0, ttc: 0};

    const nombre = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});

    function config() {
        if (reglages) {
            return reglages;
        }
        const el = document.querySelector('.js-promotions-saisie');
        if (!el || !el.dataset.url) {
            return null;
        }
        const form = el.closest('form');
        reglages = {
            url: el.dataset.url,
            csrf: el.dataset.csrf,
            document: {type: el.dataset.type, id: Number(el.dataset.id || 0) || null},
            verrouille: el.dataset.verrouille === '1',
            form: form,
            client: form ? form.querySelector('.js-client-saisie') : null,
            code: form ? form.querySelector('.js-code-promo') : null,
        };
        ecouter(reglages);

        return reglages;
    }

    function ecouter(r) {
        if (r.client) {
            r.client.addEventListener('change', planifier);
            if (window.jQuery) {
                window.jQuery(r.client).on('change', planifier);
            }
        }
        if (r.code) {
            r.code.addEventListener('input', planifier);
        }
        // Retirer ou remettre la promotion d'une ligne.
        document.addEventListener('click', function (event) {
            const bouton = event.target.closest('.js-promo-basculer');
            if (!bouton) {
                return;
            }
            const zone = bouton.closest('.js-promo-ligne');
            const travail = zone ? travailDe(zone) : null;
            const case_ = travail ? travail.querySelector('.js-promotion-retiree') : null;
            if (!case_) {
                return;
            }
            case_.checked = !case_.checked;
            afficher(zone);
            recalculer(zone);
        });
    }

    function travailDe(zone) {
        return zone.closest('.js-commande-detail, .js-devis-detail');
    }

    function champ(travail, nom) {
        return travail.querySelector('[data-detail-field="' + nom + '"]') || travail.querySelector('[name$="[' + nom + ']"]');
    }

    function valeur(travail, nom) {
        const c = champ(travail, nom);
        const v = c ? parseFloat(String(c.value || '').replace(',', '.')) : NaN;

        return Number.isFinite(v) ? v : 0;
    }

    function retiree(travail) {
        const c = travail ? travail.querySelector('.js-promotion-retiree') : null;

        return Boolean(c && c.checked);
    }

    function cle(travail) {
        const c = travail.querySelector('.js-promotion-retiree');
        const m = c ? /\[(\d+)\]\[promotionRetiree\]$/.exec(c.name || '') : null;

        return m ? m[1] : '';
    }

    /* La ligne se recalcule : le formulaire écoute « change » sur ses champs de calcul. */
    function recalculer(zone) {
        zone.dispatchEvent(new Event('change', {bubbles: true}));
    }

    /** Remise par unité de la promotion de la ligne, à ajouter à la remise saisie. */
    function remise(travail) {
        const zone = travail ? travail.querySelector('.js-promo-ligne') : null;
        if (!zone || retiree(travail) || !zone.dataset.libelle) {
            return 0;
        }

        return Math.max(0, Number(zone.dataset.remisePromotion || 0));
    }

    /** Appelé par le calcul de la ligne : total avant remise et unités facturables. */
    function calculee(travail, brut, facteur) {
        const zone = travail ? travail.querySelector('.js-promo-ligne') : null;
        if (!zone) {
            return;
        }
        const saisie = Math.max(0, valeur(travail, 'remise'));
        const promo = Math.max(0, Number(zone.dataset.remisePromotion || 0));
        const f = Math.max(0, facteur || 0);
        zone.dataset.brut = String(Math.round(brut || 0));
        zone.dataset.facteur = String(f);
        zone.dataset.economie = String(Math.max(0, Math.min(brut, (saisie + promo) * f) - Math.min(brut, saisie * f)));
        afficher(zone);
        planifier();
    }

    function afficher(zone) {
        const travail = travailDe(zone);
        const libelle = zone.dataset.libelle || '';
        const b2b = zone.dataset.b2b ? JSON.parse(zone.dataset.b2b) : [];
        const r = config();
        const boutons = !(r && r.verrouille);
        let html = '';

        if (libelle && retiree(travail)) {
            html = '<span class="si-pr-pastille si-pr-retiree"><i class="fas fa-tag"></i>' + echapper(libelle) + '</span>'
                + (boutons ? '<button type="button" class="js-promo-basculer">Remettre la promotion</button>' : '');
            zone.dataset.etiquette = '';
        } else if (libelle) {
            const economie = Math.round(Number(zone.dataset.economie || 0));
            html = '<span class="si-pr-pastille"><i class="fas fa-tag"></i>Promotion : ' + echapper(libelle)
                + (economie > 0 ? ' · −' + nombre.format(economie).replace(/\s/g, ' ') + ' F' : '') + '</span>'
                + (boutons ? '<button type="button" class="js-promo-basculer">Retirer</button>' : '');
            zone.dataset.etiquette = libelle;
        } else if (b2b.length > 0) {
            html = b2b.map(function (p) {
                const etat = p.demande === 'en_attente' ? ' · exception demandée' : (p.demande === 'refusee' ? ' · exception refusée' : '');

                return '<span class="si-pr-pastille si-pr-b2b"><i class="fas fa-store"></i>Promotion possible : ' + echapper(p.libelle) + etat + '</span>';
            }).join('') + '<small>Revendeur au prix B2B : la ligne garde ce prix. Après l\'enregistrement, demandez l\'exception depuis la fiche.</small>';
            zone.dataset.etiquette = '';
        } else {
            zone.dataset.etiquette = '';
        }
        if (zone.innerHTML !== html) {
            zone.innerHTML = html;
        }
    }

    function echapper(texte) {
        return String(texte).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function planifier() {
        if (minuteur) {
            window.clearTimeout(minuteur);
        }
        minuteur = window.setTimeout(demander, 350);
    }

    function lignes() {
        return Array.from(document.querySelectorAll('.js-promo-ligne')).map(function (zone) {
            const travail = travailDe(zone);
            if (!travail) {
                return null;
            }
            const type = travail.querySelector('[name$="[typeLigne]"]:checked');
            const produit = champ(travail, 'produit');

            return {
                zone: zone,
                donnees: {
                    cle: cle(travail),
                    id: Number(zone.dataset.ligne || 0) || null,
                    produit: type && type.value === 'article' ? null : (produit && produit.value ? Number(produit.value) : null),
                    prix: valeur(travail, 'prixUnitaire'),
                    remise: Math.round(Math.max(0, valeur(travail, 'remise'))),
                    quantite: Math.max(1, Math.round(valeur(travail, 'quantite')) || 1),
                    brut: Number(zone.dataset.brut || 0),
                    unites: Number(zone.dataset.facteur || 0),
                    tva: Math.max(0, Math.round(valeur(travail, 'tva'))),
                },
            };
        }).filter(function (l) {
            return l && l.donnees.cle !== '';
        });
    }

    function demander() {
        minuteur = null;
        const r = config();
        if (!r) {
            return;
        }
        const liste = lignes();
        const corps = {
            client: r.client && r.client.value ? Number(r.client.value) : null,
            code: r.code ? r.code.value.trim() : '',
            document: r.document,
            lignes: liste.map(function (l) {
                return l.donnees;
            }),
        };
        const signature = JSON.stringify(corps);
        // Rien n'a changé depuis la dernière demande : pas de nouvelle requête.
        if (signature === derniere) {
            return;
        }
        derniere = signature;
        const ce = ++numero;

        fetch(r.url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': r.csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: signature,
            credentials: 'same-origin',
        }).then(function (reponse) {
            return reponse.ok ? reponse.json() : null;
        }).then(function (resultat) {
            if (!resultat || ce !== numero) {
                return;
            }
            liste.forEach(function (l) {
                if (!l.zone.isConnected) {
                    return;
                }
                const res = resultat.lignes[l.donnees.cle] || {promotion: null, remise: 0, b2b: []};
                const avant = l.zone.dataset.libelle + '|' + l.zone.dataset.remisePromotion;
                l.zone.dataset.libelle = res.promotion ? res.promotion.libelle : '';
                l.zone.dataset.remisePromotion = String(res.promotion ? res.remise : 0);
                l.zone.dataset.b2b = JSON.stringify(res.b2b || []);
                afficher(l.zone);
                if (avant !== l.zone.dataset.libelle + '|' + l.zone.dataset.remisePromotion) {
                    recalculer(l.zone);
                }
            });
            afficherCode(r, resultat.code || null);
        }).catch(function (erreur) {
            derniere = '';
            console.warn('Promotions : aperçu indisponible', erreur);
        });
    }

    /** Message du code promo sous le champ, et ce qu'il retire à la commande. */
    function afficherCode(r, code) {
        if (!r.code) {
            return;
        }
        let el = r.code.parentNode.querySelector('.js-code-promo-resultat');
        if (!el) {
            el = document.createElement('small');
            el.className = 'si-cp-resultat js-code-promo-resultat';
            el.setAttribute('aria-live', 'polite');
            r.code.insertAdjacentElement('afterend', el);
        }
        const ok = code && code.etat === 'ok';
        codeCommande = ok ? {ht: Math.max(0, Number(code.remise || 0)), ttc: Math.max(0, Number(code.remiseTtc || 0))} : {ht: 0, ttc: 0};
        if (!code) {
            el.textContent = '';
            el.className = 'si-cp-resultat js-code-promo-resultat';
        } else if (ok) {
            el.textContent = (code.libelle || 'Code valable') + (codeCommande.ttc > 0 ? ' · −' + nombre.format(codeCommande.ttc).replace(/\s/g, ' ') + ' F TTC' : '');
            el.className = 'si-cp-resultat si-cp-ok js-code-promo-resultat';
        } else {
            el.textContent = code.message || '';
            el.className = 'si-cp-resultat ' + (code.etat === 'simple' ? 'si-cp-simple' : 'si-cp-refus') + ' js-code-promo-resultat';
        }
        if (r.form) {
            r.form.dispatchEvent(new CustomEvent('si:code-promo', {bubbles: true}));
        }
    }

    window.SiPromotions = {
        remise: remise,
        calculee: calculee,
        /** Ce que le code promo retire à la commande (F hors TVA et TTC), d'après le dernier aperçu. */
        codeCommande: function () {
            return codeCommande;
        },
    };
})();
