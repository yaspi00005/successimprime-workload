/*
 * Saisie d'une commande (maquettes C01, C03, C04) ou d'un devis (22).
 *
 * Le calcul des prix, le catalogue, les fichiers et le brouillon
 * restent dans le script du formulaire (commandes/_form.html.twig ou
 * devis/_form.html.twig). La page indique ses blocs par data-ligne
 * (bloc d'une ligne) et data-travail (bloc où le script du formulaire
 * pose la liste des finitions permises).
 * Ce script ne fait que la présentation : une ligne ouverte à la
 * fois, le tableau des lignes, la carte du client, le délai, le
 * récapitulatif et, sur téléphone, les 3 étapes.
 */
(function () {
    'use strict';

    function demarrer() {
        const page = document.querySelector('.si-saisie');
        const form = page ? page.closest('form') : null;
        const collection = document.getElementById('collection-details');

        if (!page || !form || !collection) {
            return;
        }

        const selecteurLigne = page.dataset.ligne || '.detail-commande';
        const classeLigne = selecteurLigne.replace(/^\./, '');
        const selecteurTravail = page.dataset.travail || '.js-commande-detail';

        const zoneConfig = page.querySelector('.js-zone-config');
        const corpsLignes = page.querySelector('.js-lignes-resume');
        const verrouillee = form.dataset.circuitVerrouille === '1';
        let active = null;

        const nombre = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});
        const decimal = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 2});

        function montant(valeur) {
            return nombre.format(Math.round(valeur || 0)).replace(/ /g, ' ');
        }

        function nombreChamp(champ) {
            if (!champ) {
                return 0;
            }
            const valeur = parseFloat(String(champ.value || '').replace(',', '.'));

            return Number.isFinite(valeur) ? valeur : 0;
        }

        function champ(detail, nom) {
            return detail.querySelector('[data-detail-field="' + nom + '"]');
        }

        function texteChoisi(select) {
            if (!select || !select.value || !select.options) {
                return '';
            }
            const option = select.options[select.selectedIndex];

            return option ? option.textContent.trim() : '';
        }

        function details() {
            return Array.from(collection.querySelectorAll(':scope > ' + selecteurLigne));
        }

        function typeLigne(detail) {
            const coche = detail.querySelector('.js-type-ligne input:checked');

            return coche ? coche.value : 'produit';
        }

        function echapper(texte) {
            const div = document.createElement('div');
            div.textContent = texte;

            return div.innerHTML;
        }

        /* ---------- Une ligne ouverte à la fois ---------- */

        /* Nouvelle ligne : « Produit / prestation » coché d'office (champ obligatoire). */
        function typeParDefaut(detail) {
            if (!detail || detail.querySelector('.js-type-ligne input:checked')) {
                return;
            }
            const produit = detail.querySelector('.js-type-ligne input[value="produit"]');
            if (produit) {
                produit.checked = true;
                produit.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }

        function activer(detail) {
            typeParDefaut(detail);
            active = detail || null;
            details().forEach(function (d) {
                d.classList.toggle('est-active', d === active);
            });
            zoneConfig.classList.toggle('est-vide', !active);
            rafraichir();
        }

        function infosLigne(detail, index) {
            const type = typeLigne(detail);
            const designation = (champ(detail, 'designation') || {}).value || '';
            const produit = texteChoisi(champ(detail, 'produit'));
            const article = texteChoisi(champ(detail, 'article'));
            const nom = designation.trim() || (type === 'article' ? article : produit) || 'Nouvelle ligne';

            const caracteristiques = [];
            const largeur = nombreChamp(champ(detail, 'largeur'));
            const longueur = nombreChamp(champ(detail, 'longueur'));
            if (type === 'produit' && largeur > 0 && longueur > 0) {
                caracteristiques.push(decimal.format(largeur) + ' × ' + decimal.format(longueur) + ' cm');
            }
            const support = texteChoisi(champ(detail, 'support'));
            if (support && support !== nom) {
                caracteristiques.push(support);
            }
            detail.querySelectorAll('.finitions-box input:checked').forEach(function (coche) {
                const libelle = detail.querySelector('label[for="' + coche.id + '"]');
                if (libelle) {
                    caracteristiques.push(libelle.textContent.trim().replace(/\s*\(.*\)$/, ''));
                }
            });

            const quantite = Math.max(1, nombreChamp(champ(detail, 'quantite')) || 1);
            const total = nombreChamp(champ(detail, 'totalTtc'));
            const jetons = (detail.querySelector('.js-jetons-fichiers') || {}).value || '';
            const fichiers = jetons.split(',').filter(Boolean).length
                + detail.querySelectorAll('.js-liste-uploads > .d-flex').length;

            return {
                index: index,
                type: type,
                nom: nom,
                caracteristiques: caracteristiques.join(' · '),
                machine: type === 'produit' ? texteChoisi(champ(detail, 'typeImpression')) : '',
                quantite: quantite,
                total: total,
                fichiers: fichiers,
                surface: largeur * longueur / 10000 * quantite,
            };
        }

        /* ---------- Tableau des lignes ---------- */

        function rafraichirTableau(lignes) {
            corpsLignes.innerHTML = lignes.map(function (l) {
                let fichier = '<span class="text-muted">—</span>';
                if (l.type === 'produit') {
                    fichier = l.fichiers > 0
                        ? '<span class="si-pastille si-pastille-vert">✓ OK</span>'
                        : '<span class="si-pastille si-pastille-orange">À recevoir</span>';
                }
                const actif = details()[l.index] === active ? ' class="est-active"' : '';

                return '<tr data-index="' + l.index + '"' + actif + '>'
                    + '<td class="si-col-vignette"><span class="si-carre si-carre-' + (l.index % 5) + '"></span></td>'
                    + '<td><span class="si-ligne-nom">' + echapper(l.nom) + '<span class="d-md-none"> × ' + nombre.format(l.quantite) + '</span></span>'
                    + (l.caracteristiques ? '<span class="si-ligne-carac">' + echapper(l.caracteristiques) + '</span>' : '') + '</td>'
                    + '<td class="si-col-machine">' + (l.machine ? '<span class="si-machine">' + echapper(l.machine) + '</span>' : '') + '</td>'
                    + '<td class="si-col-qte text-end">' + nombre.format(l.quantite) + '</td>'
                    + '<td class="si-col-pu text-end">' + montant(l.total / l.quantite) + '</td>'
                    + '<td class="text-end fw-bold">' + montant(l.total) + '</td>'
                    + '<td class="si-col-fichier">' + fichier + '</td>'
                    + '<td class="text-end">' + (verrouillee ? '' : '<button type="button" class="btn btn-sm btn-link text-muted js-retirer-ligne" aria-label="Retirer la ligne"><i class="fas fa-trash-alt"></i></button>') + '</td>'
                    + '</tr>';
            }).join('');

            const compteur = page.querySelector('.js-nombre-lignes');
            if (compteur) {
                compteur.textContent = lignes.length ? '(' + lignes.length + ')' : '';
            }
        }

        corpsLignes.addEventListener('click', function (event) {
            const ligne = event.target.closest('tr[data-index]');
            if (!ligne) {
                return;
            }
            const detail = details()[Number(ligne.dataset.index)];

            if (event.target.closest('.js-retirer-ligne')) {
                const bouton = detail && detail.querySelector('.supprimer-detail');
                if (bouton && window.confirm('Retirer cette ligne ?')) {
                    bouton.click();
                }

                return;
            }

            activer(detail);
            allerA('ligne');
            zoneConfig.scrollIntoView({behavior: 'smooth', block: 'start'});
        });

        /* ---------- Ligne ouverte : titre et résumé ---------- */

        function rafraichirLigneActive(lignes) {
            if (!active) {
                return;
            }
            rafraichirFinitions(active);
            const infos = lignes[details().indexOf(active)];
            if (!infos) {
                return;
            }
            const titre = active.querySelector('.detail-title');
            if (titre) {
                titre.textContent = 'Configurer la ligne ' + (infos.index + 1) + (infos.nom !== 'Nouvelle ligne' ? ' · ' + infos.nom : '');
            }
            const resume = active.querySelector('.js-resume-ligne');
            if (resume) {
                const morceaux = [];
                if (infos.surface > 0) {
                    morceaux.push('Surface ' + decimal.format(infos.surface) + ' m²');
                }
                morceaux.push('prix de la ligne <strong>' + montant(infos.total) + ' F</strong>');
                resume.innerHTML = morceaux.join(' · ');
            }
            const prix = page.querySelector('.js-prix-ligne-active');
            if (prix) {
                prix.textContent = montant(infos.total) + ' F';
            }
            if (page.dataset.etape === 'ligne') {
                page.querySelector('.js-titre-etape').textContent = 'Ligne ' + (infos.index + 1) + (infos.nom !== 'Nouvelle ligne' ? ' · ' + infos.nom : '');
            }
        }

        /* ---------- Finitions en pastilles (C01) ---------- */

        const optionsFinitions = {};

        function finitionsPossibles(zone) {
            const cle = zone.dataset.prototype || '';
            if (!optionsFinitions[cle]) {
                const modele = document.createElement('template');
                modele.innerHTML = cle.replace(/__finition_index__/g, '0');
                const select = modele.content.querySelector('select');
                optionsFinitions[cle] = select ? Array.from(select.options).filter(function (o) {
                    return o.value;
                }).map(function (o) {
                    return {id: o.value, nom: o.textContent.trim()};
                }) : [];
            }

            return optionsFinitions[cle];
        }

        function rafraichirFinitions(detail) {
            const zone = detail.querySelector('.js-zone-finitions-manuelles');
            if (!zone || zone.style.display === 'none') {
                return;
            }
            // Liste posée par le script du formulaire sur le bloc de travail.
            const travail = detail.querySelector(selecteurTravail) || detail;
            const autorisees = travail.dataset.finitionsAutorisees;
            const ids = autorisees === undefined ? null : autorisees.split(',').filter(Boolean);
            const choisies = Array.from(zone.querySelectorAll('.js-finition-manuelle select')).map(function (s) {
                return s.value;
            });
            const possibles = finitionsPossibles(zone).filter(function (f) {
                return ids === null || ids.indexOf(f.id) !== -1;
            });

            let pastilles = zone.querySelector('.si-finitions-pastilles');
            if (!pastilles) {
                pastilles = document.createElement('div');
                pastilles.className = 'si-choix-pilules si-finitions-pastilles';
                zone.prepend(pastilles);
                pastilles.addEventListener('click', function (event) {
                    const bouton = event.target.closest('[data-finition]');
                    if (bouton && !verrouillee) {
                        basculerFinition(detail, zone, bouton.dataset.finition);
                    }
                });
            }

            const cle = possibles.map(function (f) {
                return f.id + (choisies.indexOf(f.id) !== -1 ? '+' : '');
            }).join(',');
            if (pastilles.dataset.cle === cle) {
                return;
            }
            pastilles.dataset.cle = cle;
            pastilles.innerHTML = possibles.length ? possibles.map(function (f) {
                const choisie = choisies.indexOf(f.id) !== -1;

                return '<button type="button" class="si-pilule-bouton' + (choisie ? ' est-choisie' : '') + '" aria-pressed="' + choisie + '" data-finition="' + f.id + '">' + (choisie ? '✓ ' : '') + echapper(f.nom) + '</button>';
            }).join('') : '<small class="text-muted">Aucune finition pour ce produit.</small>';
        }

        function basculerFinition(detail, zone, id) {
            const existante = Array.from(zone.querySelectorAll('.js-finition-manuelle')).find(function (ligne) {
                const s = ligne.querySelector('select');

                return s && s.value === id;
            });

            if (existante) {
                const retirer = existante.querySelector('.js-supprimer-finition');
                if (retirer) {
                    retirer.click();
                }
            } else {
                const ajouter = zone.querySelector('.js-ajouter-finition');
                if (!ajouter) {
                    return;
                }
                ajouter.click();
                const lignes = zone.querySelectorAll('.js-finition-manuelle');
                const select = lignes.length ? lignes[lignes.length - 1].querySelector('select') : null;
                if (select) {
                    select.value = id;
                    if (window.jQuery) {
                        window.jQuery(select).trigger('change');
                    }
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                    const prix = lignes[lignes.length - 1].querySelector('.js-finition-prix');
                    if (prix) {
                        prix.focus();
                    }
                }
            }
            rafraichir();
        }

        /* ---------- Récapitulatif ---------- */

        function rafraichirRecap(lignes) {
            let ht = 0;
            let ttc = 0;
            const taux = new Set();

            details().forEach(function (detail) {
                ht += nombreChamp(champ(detail, 'totalHt'));
                ttc += nombreChamp(champ(detail, 'totalTtc'));
                taux.add(nombreChamp(champ(detail, 'tva')));
            });

            const remise = nombreChamp(form.querySelector('.js-remise-saisie'));
            const aPayer = Math.max(0, Math.round(ttc - remise));

            page.querySelector('.js-recap-ht').textContent = montant(ht);
            page.querySelector('.js-recap-tva').textContent = montant(ttc - ht);
            page.querySelector('.js-recap-tva-libelle').textContent = taux.size === 1 && !taux.has(0) ? 'TVA ' + Array.from(taux)[0] + ' %' : 'TVA';
            page.querySelector('.js-recap-lignes').textContent = lignes.length > 0 ? 'Sous-total HT (' + lignes.length + (lignes.length > 1 ? ' lignes)' : ' ligne)') : 'Sous-total HT';
            page.querySelectorAll('.js-total-affiche').forEach(function (el) {
                el.textContent = montant(aPayer) + ' F';
            });
            const acompte = page.querySelector('.js-acompte');
            if (acompte) {
                acompte.textContent = montant(Math.ceil(aPayer / 2)) + ' F';
            }
        }

        function rafraichir() {
            const lignes = details().map(infosLigne);
            rafraichirTableau(lignes);
            rafraichirLigneActive(lignes);
            rafraichirRecap(lignes);
        }

        /* Le calcul des prix met à jour les champs sans événement. */
        let attente = null;
        function plusTard() {
            if (attente) {
                return;
            }
            attente = window.setTimeout(function () {
                attente = null;
                rafraichir();
            }, 60);
        }
        form.addEventListener('input', plusTard);
        form.addEventListener('change', plusTard);
        window.setInterval(rafraichir, 1500);

        /* Ligne ajoutée : on l'ouvre. Ligne retirée : on ouvre la dernière. */
        new MutationObserver(function (mutations) {
            let ajoutee = null;
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType === 1 && n.classList.contains(classeLigne)) {
                        ajoutee = n;
                    }
                });
            });
            if (ajoutee) {
                activer(ajoutee);
                allerA('ligne');
            } else if (!active || !active.isConnected) {
                const restants = details();
                activer(restants[restants.length - 1] || null);
            } else {
                rafraichir();
            }
        }).observe(collection, {childList: true});

        /* ---------- Client ---------- */

        const select = form.querySelector('.js-client-saisie');
        const carte = page.querySelector('.js-client-carte');
        const choix = page.querySelector('.js-client-choix');
        const adresse = form.querySelector('[name$="[adresseLivraison]"]');

        function initiales(nom) {
            return nom.split(/\s+/).filter(Boolean).slice(0, 2).map(function (m) {
                return m.charAt(0).toUpperCase();
            }).join('');
        }

        function afficherClient() {
            const option = select && select.value ? select.options[select.selectedIndex] : null;
            if (!option) {
                carte.hidden = true;
                choix.hidden = false;

                return;
            }
            const d = option.dataset;
            const nom = d.nom || option.textContent.trim();
            carte.querySelector('.js-client-initiales').textContent = initiales(nom);
            carte.querySelector('.js-client-nom').textContent = nom;
            carte.querySelector('.js-client-compte').textContent = d.compte || '';
            carte.querySelector('.js-client-tarif').textContent = (d.typeClient || 'B2C').toUpperCase() === 'B2B' ? 'Tarif B2B' : 'Prix public';
            const contact = [d.telephone];
            if (d.credit !== undefined && Number(d.credit) > 0) {
                contact.push('crédit autorisé ' + montant(Number(d.credit)) + ' F');
            }
            carte.querySelector('.js-client-contact').textContent = contact.filter(Boolean).join(' · ');
            carte.hidden = false;
            choix.hidden = true;

            if (adresse && !adresse.value && d.adresse) {
                adresse.value = d.adresse;
            }
        }

        if (select) {
            select.addEventListener('change', afficherClient);
            if (window.jQuery) {
                window.jQuery(select).on('change', afficherClient);
            }
        }

        const changer = page.querySelector('.js-client-changer');
        if (changer) {
            changer.addEventListener('click', function () {
                carte.hidden = true;
                choix.hidden = false;
                if (window.jQuery && window.jQuery(select).data('select2')) {
                    window.jQuery(select).select2('open');
                } else if (select) {
                    select.focus();
                }
            });
        }

        /* ---------- Livraison et délai ---------- */

        const zoneAdresse = page.querySelector('.js-zone-adresse');
        function afficherAdresse() {
            if (!zoneAdresse) {
                return;
            }
            const mode = page.querySelector('.js-mode-livraison input:checked');
            zoneAdresse.hidden = !mode || mode.value !== 'livraison';
        }
        page.querySelectorAll('.js-mode-livraison input').forEach(function (r) {
            r.addEventListener('change', afficherAdresse);
        });

        const dateSouhaitee = page.querySelector('.js-date-souhaitee');
        const delai = page.querySelector('.js-delai');
        function afficherDelai() {
            const possible = page.dataset.delaiPossible;
            const libelle = page.dataset.delaiLibelle;
            if (!delai || !possible) {
                return;
            }
            const souhaitee = dateSouhaitee && dateSouhaitee.value ? dateSouhaitee.value.slice(0, 10) : '';
            delai.classList.remove('ok', 'tard');
            if (!souhaitee) {
                delai.textContent = 'Au plus tôt ' + libelle;
            } else if (souhaitee >= possible) {
                delai.classList.add('ok');
                delai.textContent = 'Possible ' + libelle;
            } else {
                delai.classList.add('tard');
                delai.textContent = 'Trop tôt : au plus tôt ' + libelle;
            }
        }
        if (dateSouhaitee) {
            dateSouhaitee.addEventListener('change', afficherDelai);
            dateSouhaitee.addEventListener('input', afficherDelai);
        }

        /* ---------- Téléphone : 3 étapes ---------- */

        const etapes = {
            lignes: ['', 'Étape 1/3 · client et lignes'],
            ligne: ['Ligne', 'Configurer le produit'],
            recap: ['Récapitulatif', 'Étape 3/3 · livraison et acompte'],
        };
        const titreNouvelle = page.querySelector('.js-titre-etape').textContent;

        function allerA(etape) {
            if (page.dataset.etape === etape) {
                return;
            }
            page.dataset.etape = etape;
            page.querySelector('.js-titre-etape').textContent = etapes[etape][0] || titreNouvelle;
            page.querySelector('.js-sous-titre-etape').textContent = etapes[etape][1];
            rafraichir();
            if (window.matchMedia('(max-width: 767.98px)').matches) {
                window.scrollTo({top: 0});
            }
        }

        page.querySelector('.js-etape-retour').addEventListener('click', function () {
            if (page.dataset.etape === 'lignes') {
                window.history.back();
            } else {
                allerA('lignes');
            }
        });

        page.querySelector('.js-valider-ligne').addEventListener('click', function () {
            allerA('lignes');
        });

        page.querySelector('.js-etape-suivante').addEventListener('click', function () {
            if (!select || !select.value) {
                window.alert('Choisissez d\'abord le client.');

                return;
            }
            if (details().length === 0) {
                window.alert('Ajoutez au moins une ligne.');

                return;
            }
            allerA('recap');
        });

        /* Champ obligatoire vide dans une ligne fermée : on ouvre cette ligne. */
        form.addEventListener('invalid', function (event) {
            const detail = event.target.closest(selecteurLigne);
            if (detail && detail !== active) {
                activer(detail);
                allerA('ligne');
            }
        }, true);

        /* ---------- Démarrage ---------- */

        afficherClient();
        afficherAdresse();
        afficherDelai();
        activer(details()[0] || null);
    }

    /* Après le script du formulaire, qui démarre lui aussi au chargement. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(demarrer, 0);
        });
    } else {
        window.setTimeout(demarrer, 0);
    }
})();
