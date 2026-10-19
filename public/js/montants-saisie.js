/*
 * Montants lisibles pendant la saisie, dans toute l'application :
 * « 2000000 » s'affiche « 2 000 000 » au fil de la frappe.
 *
 * Sont formatés les champs marqués data-format-montant, et les champs dont
 * le nom ou l'id parle d'argent (montant, prix, acompte, remis, frais…),
 * sauf data-sans-format, les %, les quantités et les champs à motif
 * (pattern). Un champ qui contient une virgule ou un point reste tel quel.
 *
 * Les espaces ne partent jamais au serveur ni aux autres scripts :
 * - la propriété value du champ rend les chiffres seuls ;
 * - à l'envoi du formulaire (et pour new FormData(form)), l'événement
 *   formdata remplace la valeur par les chiffres seuls.
 */
(function () {
    'use strict';

    const ARGENT = /(montant|prix|acompte|somme|remis|rendu|plafond|frais|salaire|avance|budget|especes|^cout|_cout|cout_|recu(?!r)|versement|caution|loyer|tarif)/i;
    const PAS_ARGENT = /(remise|pct|taux|pourcent|quantite|qte|nombre|_id$|^id$|code|telephone|date|heure|jour|mois|annee|note|ref)/i;
    const ESPACE = ' ';
    const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    function chiffres(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[\s  ]/g, '');
    }

    function formater(v) {
        const brut = chiffres(v);
        if (!/^-?\d+$/.test(brut)) {
            return String(v === null || v === undefined ? '' : v);
        }
        const signe = brut.charAt(0) === '-' ? '-' : '';
        const n = signe ? brut.slice(1) : brut;
        return signe + n.replace(/\B(?=(\d{3})+(?!\d))/g, ESPACE);
    }

    function concerne(champ) {
        if (champ.dataset.montantFormate !== undefined || champ.hasAttribute('data-sans-format')) {
            return false;
        }
        const type = (champ.getAttribute('type') || 'text').toLowerCase();
        if (type !== 'text' && type !== 'number' && type !== 'tel') {
            return false;
        }
        if (champ.hasAttribute('data-format-montant')) {
            return true;
        }
        if (champ.hasAttribute('pattern') || champ.hasAttribute('list')) {
            return false;
        }
        // Un montant décimal (pas entier) ou un champ à pas décimal reste tel quel.
        const pas = champ.getAttribute('step');
        if (pas && pas !== 'any' && Number(pas) % 1 !== 0) {
            return false;
        }
        const nom = (champ.name || '') + ' ' + (champ.id || '');
        const cle = nom.replace(/\[/g, '_').replace(/\]/g, '');
        return ARGENT.test(cle) && !PAS_ARGENT.test(cle.replace(/montant_?remis/i, 'montant'));
    }

    function brancher(champ) {
        if (!concerne(champ)) {
            return;
        }
        const brutAvant = desc.get.call(champ);
        if (brutAvant !== '' && !/^-?[\d\s  ]+$/.test(brutAvant)) {
            return;
        }
        champ.dataset.montantFormate = '';
        if ((champ.getAttribute('type') || '').toLowerCase() === 'number') {
            // Un champ « number » ne peut pas montrer d'espaces : il devient texte, clavier chiffres.
            champ.setAttribute('type', 'text');
        }
        if (!champ.getAttribute('inputmode')) {
            champ.setAttribute('inputmode', 'numeric');
        }
        Object.defineProperty(champ, 'value', {
            configurable: true,
            get: function () {
                const v = desc.get.call(this);
                return /^-?[\d\s  ]*$/.test(v) ? chiffres(v) : v;
            },
            set: function (v) {
                desc.set.call(this, formater(v));
            }
        });
        desc.set.call(champ, formater(brutAvant));
        champ.addEventListener('input', function () {
            const v = desc.get.call(champ);
            if (!/^-?[\d\s  ]*$/.test(v)) {
                return;
            }
            // Garder le curseur après le même chiffre.
            const pos = champ.selectionStart === null ? v.length : champ.selectionStart;
            const avant = chiffres(v.slice(0, pos)).length;
            const f = formater(v);
            if (f === v) {
                return;
            }
            desc.set.call(champ, f);
            let i = 0, vus = 0;
            while (i < f.length && vus < avant) {
                if (/[\d-]/.test(f.charAt(i))) {
                    vus++;
                }
                i++;
            }
            try { champ.setSelectionRange(i, i); } catch (e) { /* champ caché */ }
        });
        if (champ.form && !champ.form.dataset.montantsNettoyes) {
            champ.form.dataset.montantsNettoyes = '1';
            champ.form.addEventListener('formdata', function (e) {
                const noms = new Set();
                champ.form.querySelectorAll('input[data-montant-formate]').forEach(function (c) {
                    if (c.name) {
                        noms.add(c.name);
                    }
                });
                // getAll puis append : l'ordre est gardé, même pour les noms en [] (lignes).
                noms.forEach(function (nom) {
                    const valeurs = e.formData.getAll(nom);
                    e.formData.delete(nom);
                    valeurs.forEach(function (v) {
                        e.formData.append(nom, typeof v === 'string' && /^-?[\d\s\u00A0\u202F]+$/.test(v) ? chiffres(v) : v);
                    });
                });
            });
        }
    }

    function parcourir(racine) {
        if (racine.matches && racine.matches('input')) {
            brancher(racine);
        }
        if (racine.querySelectorAll) {
            racine.querySelectorAll('input').forEach(brancher);
        }
    }

    function demarrer() {
        parcourir(document);
        // Lignes ajoutées après coup (devis, commandes, dépenses) : branchées à leur arrivée.
        new MutationObserver(function (changements) {
            changements.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1) {
                        parcourir(n);
                    }
                });
            });
        }).observe(document.body, {childList: true, subtree: true});
    }

    window.SiMontants = {formater: formater, chiffres: chiffres, brancher: brancher};
    // Chargé en bas de page : le corps est déjà là, les scripts de la page qui suivent lisent déjà les chiffres seuls.
    if (document.body) {
        demarrer();
    } else {
        document.addEventListener('DOMContentLoaded', demarrer);
    }
})();
