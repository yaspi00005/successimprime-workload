/*
 * Saisie d'un devis (maquette GC04, docs/devis.md), en plus de
 * saisie-commande.js (présentation des lignes) et du script du
 * formulaire (prix) :
 *  - « + Ligne libre » : une ligne en saisie libre (pose, déplacement) ;
 *  - l'acompte choisi dans les conditions et son montant ;
 *  - la date de fin de validité ;
 *  - le numéro WhatsApp du client sous « Envoyer par » ;
 *  - la rentabilité « Interne » (coût estimé, marge, alerte), demandée
 *    au serveur, seulement si la carte est là (droit « Marges et coûts ») ;
 *  - le calculateur grand format : prix au m² des configurations du
 *    catalogue, au tarif du client (public ou B2B).
 */
(function () {
    'use strict';

    function demarrer() {
        const page = document.querySelector('.si-saisie-devis');
        const form = page ? page.closest('form') : null;
        const collection = document.getElementById('collection-details');
        if (!page || !form || !collection) {
            return;
        }

        const nombre = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});
        function montant(v) {
            return nombre.format(Math.round(v || 0)).replace(/ | /g, ' ') + ' F';
        }
        function valeur(champ) {
            const v = champ ? parseFloat(String(champ.value || '').replace(',', '.')) : 0;

            return Number.isFinite(v) ? v : 0;
        }
        function lignes() {
            return Array.from(collection.querySelectorAll(':scope > .detail-devis'));
        }
        function client() {
            const select = form.querySelector('.js-client-saisie');

            return select && select.value ? select.options[select.selectedIndex] : null;
        }

        /* ---------- Ligne libre ---------- */

        page.querySelectorAll('.js-ligne-libre').forEach(function (bouton) {
            bouton.addEventListener('click', function () {
                const ajout = document.getElementById('add-detail');
                if (!ajout) {
                    return;
                }
                ajout.click();
                const nouvelle = lignes().pop();
                const libre = nouvelle ? nouvelle.querySelector('.js-type-ligne input[value="libre"]') : null;
                if (libre) {
                    libre.checked = true;
                    libre.dispatchEvent(new Event('change', {bubbles: true}));
                    const designation = nouvelle.querySelector('[data-detail-field="designation"]');
                    if (designation) {
                        window.setTimeout(function () { designation.focus(); }, 80);
                    }
                }
            });
        });

        /* ---------- Acompte, validité, WhatsApp ---------- */

        const choixAcompte = form.querySelector('.js-acompte-pourcentage');
        const acompte = page.querySelector('.js-acompte');
        const texteAcompte = page.querySelector('.js-acompte-texte');
        function afficherAcompte() {
            if (!choixAcompte || !acompte) {
                return;
            }
            const pourcentage = Number(choixAcompte.value) || 0;
            acompte.dataset.pourcentage = String(pourcentage);
            if (pourcentage === 0) {
                acompte.textContent = '0 F';
            }
            if (texteAcompte) {
                texteAcompte.textContent = pourcentage > 0 ? acompte.textContent : '';
            }
        }
        if (choixAcompte) {
            choixAcompte.addEventListener('change', afficherAcompte);
        }

        const validite = form.querySelector('.js-validite');
        const dateValidite = page.querySelector('.js-validite-date');
        function afficherValidite() {
            if (!validite || !dateValidite) {
                return;
            }
            const depuis = new Date((dateValidite.dataset.depuis || '') + 'T12:00:00');
            if (Number.isNaN(depuis.getTime())) {
                return;
            }
            depuis.setDate(depuis.getDate() + (Number(validite.value) || 15));
            dateValidite.textContent = 'jusqu\'au ' + depuis.toLocaleDateString('fr-FR', {day: '2-digit', month: '2-digit', year: 'numeric'});
        }
        if (validite) {
            validite.addEventListener('change', afficherValidite);
        }

        const telephone = page.querySelector('.js-canal-telephone');
        function afficherTelephone() {
            const c = client();
            if (telephone) {
                telephone.textContent = c && c.dataset.telephone ? c.dataset.telephone : 'numéro du client';
            }
        }

        /* ---------- Rentabilité (interne) ---------- */

        const carte = page.querySelector('.js-dv-rentabilite');
        let derniere = '';
        let attente = null;
        function texte(selecteur, contenu) {
            const el = carte.querySelector(selecteur);
            if (el) {
                el.textContent = contenu;
            }
        }
        function finitions(detail) {
            const ids = [];
            detail.querySelectorAll('.js-finition-selectionnee:checked').forEach(function (c) { ids.push(c.value); });
            detail.querySelectorAll('.js-finition-manuelle select').forEach(function (s) { if (s.value) { ids.push(s.value); } });

            return ids;
        }
        function champ(detail, nom) {
            return detail.querySelector('[data-detail-field="' + nom + '"]');
        }
        function demanderRentabilite() {
            if (!carte) {
                return;
            }
            const donnees = {
                remise: valeur(form.querySelector('.js-remise-saisie')),
                lignes: lignes().map(function (d) {
                    const type = (d.querySelector('.js-type-ligne input:checked') || {}).value || 'produit';

                    return {
                        produit: type === 'produit' ? ((champ(d, 'produit') || {}).value || null) : null,
                        largeur: valeur(champ(d, 'largeur')),
                        longueur: valeur(champ(d, 'longueur')),
                        quantite: Math.max(1, valeur(champ(d, 'quantite')) || 1),
                        nombreFaces: Math.max(1, valeur(champ(d, 'nombreFaces')) || 1),
                        finitions: finitions(d),
                        prixHt: Math.round(valeur(champ(d, 'totalHt'))),
                    };
                }),
            };
            const corps = JSON.stringify(donnees);
            if (corps === derniere) {
                return;
            }
            derniere = corps;
            fetch(carte.dataset.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': carte.dataset.token},
                body: corps,
            }).then(function (r) {
                return r.ok ? r.json() : null;
            }).then(function (r) {
                if (!r || corps !== derniere) {
                    return;
                }
                texte('.js-rent-cout', r.cout > 0 ? montant(r.cout) : '—');
                texte('.js-rent-marge', r.ht > 0 ? montant(r.marge) : '—');
                const taux = carte.querySelector('.js-rent-taux');
                taux.textContent = r.taux === null ? '—' : String(r.taux).replace('.', ',') + ' %';
                taux.classList.toggle('si-p-ok', r.taux !== null && !r.alerte);
                taux.classList.toggle('si-p-alerte', !!r.alerte);
                carte.querySelector('.js-rent-alerte').hidden = !r.alerte;
                const manquants = carte.querySelector('.js-rent-manquants');
                manquants.hidden = !r.incomplet;
                manquants.textContent = r.incomplet ? 'Coût incomplet : ' + r.manquants.join(', ') + '.' : '';
            }).catch(function () {});
        }
        function plusTard() {
            window.clearTimeout(attente);
            attente = window.setTimeout(function () {
                afficherAcompte();
                demanderRentabilite();
            }, 600);
        }

        /* ---------- Calculateur grand format ---------- */

        const calc = page.querySelector('.js-dv-calc');
        const matiere = calc ? calc.querySelector('.js-calc-matiere') : null;
        function remplirMatieres() {
            if (!matiere) {
                return;
            }
            // Configurations au m² du catalogue, lues dans le modèle de ligne.
            const modele = document.createElement('div');
            modele.innerHTML = collection.dataset.prototype || '';
            const vues = new Set();
            modele.querySelectorAll('[data-detail-field="configuration"] option').forEach(function (o) {
                const mode = o.dataset.modeCalcul;
                if ((mode === 'metre_carre' || mode === 'surface') && Number(o.dataset.prixBase) > 0 && !vues.has(o.value)) {
                    vues.add(o.value);
                    const option = document.createElement('option');
                    option.value = o.value;
                    option.textContent = o.textContent.trim();
                    option.dataset.prix = o.dataset.prixBase;
                    option.dataset.prixB2b = o.dataset.prixB2b || '0';
                    option.dataset.produit = o.dataset.produitId || '';
                    matiere.appendChild(option);
                }
            });
            calc.hidden = matiere.options.length === 0;
        }
        function calculer() {
            if (!calc || calc.hidden) {
                return;
            }
            const largeur = valeur(calc.querySelector('.js-calc-largeur'));
            const hauteur = valeur(calc.querySelector('.js-calc-hauteur'));
            const o = matiere.value ? matiere.options[matiere.selectedIndex] : null;
            const prixEl = calc.querySelector('.js-calc-prix');
            if (!o || largeur <= 0 || hauteur <= 0) {
                prixEl.textContent = '';
                calc.querySelector('.js-calc-ajouter').hidden = true;

                return;
            }
            const c = client();
            const b2b = c && String(c.dataset.typeClient || '').toUpperCase() === 'B2B' && Number(o.dataset.prixB2b) > 0;
            const prix = Number(b2b ? o.dataset.prixB2b : o.dataset.prix) * largeur * hauteur;
            prixEl.textContent = 'prix proposé ' + montant(prix) + (b2b ? ' (B2B)' : '');
            calc.querySelector('.js-calc-ajouter').hidden = false;
        }
        if (calc) {
            calc.addEventListener('input', calculer);
            calc.addEventListener('change', calculer);
            // « Ajouter la ligne » : une ligne du catalogue avec cette configuration et ces dimensions (en cm).
            calc.querySelector('.js-calc-ajouter').addEventListener('click', function () {
                const o = matiere.options[matiere.selectedIndex];
                const largeur = valeur(calc.querySelector('.js-calc-largeur'));
                const hauteur = valeur(calc.querySelector('.js-calc-hauteur'));
                document.getElementById('add-detail').click();
                const d = lignes().pop();
                if (!d || !o) {
                    return;
                }
                const produit = d.querySelector('.js-type-ligne input[value="produit"]');
                if (produit) {
                    produit.checked = true;
                    produit.dispatchEvent(new Event('change', {bubbles: true}));
                }
                function choisir(nom, v) {
                    const s = champ(d, nom);
                    if (!s) {
                        return;
                    }
                    s.value = v;
                    if (window.jQuery) {
                        window.jQuery(s).trigger('change');
                    } else {
                        s.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                }
                window.setTimeout(function () {
                    choisir('produit', o.dataset.produit);
                    window.setTimeout(function () {
                        choisir('configuration', o.value);
                        window.setTimeout(function () {
                            ['largeur', 'longueur'].forEach(function (nom, i) {
                                const champDim = champ(d, nom);
                                if (champDim) {
                                    champDim.value = String(Math.round((i === 0 ? largeur : hauteur) * 100));
                                    champDim.dispatchEvent(new Event('input', {bubbles: true}));
                                    champDim.dispatchEvent(new Event('change', {bubbles: true}));
                                }
                            });
                        }, 250);
                    }, 250);
                }, 50);
            });
        }

        /* ---------- Envoi ---------- */

        page.querySelectorAll('.js-dv-envoyer').forEach(function (b) {
            b.addEventListener('click', function (e) {
                if (!form.querySelector('[name$="[canauxEnvoi][]"]:checked')) {
                    e.preventDefault();
                    window.alert('Cochez au moins un moyen d\'envoi (espace client, WhatsApp ou e-mail).');
                }
            });
        });

        form.addEventListener('input', plusTard);
        form.addEventListener('change', plusTard);
        const selectClient = form.querySelector('.js-client-saisie');
        if (selectClient && window.jQuery) {
            window.jQuery(selectClient).on('change', function () { afficherTelephone(); calculer(); });
        }
        if (selectClient) {
            selectClient.addEventListener('change', function () { afficherTelephone(); calculer(); });
        }
        // Le script du formulaire met des prix à jour sans événement.
        window.setInterval(function () {
            afficherAcompte();
            demanderRentabilite();
        }, 2000);

        remplirMatieres();
        afficherValidite();
        afficherTelephone();
        afficherAcompte();
        demanderRentabilite();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(demarrer, 50);
        });
    } else {
        window.setTimeout(demarrer, 50);
    }
})();
