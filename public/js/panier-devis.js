/*
 * Panier de devis du site (docs/site-vitrine.md) : ajouter, retirer et
 * régler jusqu'à 10 produits, chacun avec son fichier, et montrer le prix
 * indicatif de chaque ligne et leur total, « à confirmer par l'imprimerie ».
 *
 * La page marche sans ce script pour une ligne (et « Ajouter un produit »
 * renvoie alors la page). Le prix vient de app_site_devis_estimation (tarif
 * public, celui du devis brouillon) ; le serveur refait tous les contrôles.
 *
 * Le panier en cours (produits, tailles, quantités, finitions, sans nom,
 * téléphone ni fichier) est gardé dans l'onglet (sessionStorage) : « Ajouter
 * au devis » du calculateur le retrouve. Il est oublié à l'envoi.
 */
(function () {
    'use strict';

    var form = document.getElementById('formulaire-devis');
    if (!form) {
        return;
    }
    var CLE_BROUILLON = 'si-panier-devis';
    var fiches = {};
    try {
        fiches = JSON.parse(document.getElementById('fiches-produits').textContent || '{}');
    } catch (e) {
        fiches = {};
    }
    var liste = document.getElementById('panier-lignes');
    var modele = document.getElementById('modele-ligne');
    var ajouter = document.getElementById('ajouter-ligne');
    var totalBloc = document.getElementById('panier-total');
    var facultatif = document.getElementById('message-facultatif');
    var lignesMax = Number(form.dataset.lignesMax) || 10;
    var fichiersMax = Number(form.dataset.fichiersMax) || 10;
    var tailleMax = Number(form.dataset.max) || 0;
    var totalMax = Number(form.dataset.totalMax) || 0;
    var prochaine = 0;
    var argent = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});

    function lignes() {
        return Array.prototype.slice.call(liste.querySelectorAll(':scope > .si-ligne'));
    }

    function champ(ligne, nom) {
        return ligne.querySelector('[data-champ="' + nom + '"]');
    }

    function bloc(ligne, nom) {
        return ligne.querySelector('[data-bloc="' + nom + '"]');
    }

    function fcfa(n) {
        return argent.format(n).replace(/\s/g, ' ') + ' FCFA';
    }

    function mo(octets) {
        return (octets / 1048576).toFixed(1).replace('.', ',');
    }

    /* ---------- Produit choisi : champs, finitions, pièces au m² ---------- */

    function appliquer(ligne, premiere) {
        var f = fiches[champ(ligne, 'produit').value] || null;
        var avecTaille = !!(f && f.taille);
        bloc(ligne, 'taille').hidden = !avecTaille;
        bloc(ligne, 'aide-taille').hidden = !avecTaille;
        bloc(ligne, 'description').hidden = !!f;
        var quantite = champ(ligne, 'quantite');
        if (f) {
            quantite.placeholder = f.min;
            quantite.min = f.min;
            if (f.largeur) { champ(ligne, 'largeur').placeholder = Math.round(f.largeur); }
            if (f.hauteur) { champ(ligne, 'hauteur').placeholder = Math.round(f.hauteur); }
        } else {
            quantite.placeholder = '1';
            quantite.min = 1;
        }
        var pm2 = ligne.querySelector('[data-pieces-m2]');
        if (pm2) {
            pm2.hidden = !(f && f.m2);
            if (window.SiPiecesM2) {
                window.SiPiecesM2.brancher(pm2);
                window.SiPiecesM2.rafraichir(pm2);
            }
        }
        if (!premiere) {
            var listeFinitions = ligne.querySelector('[data-liste-finitions]');
            var cochees = {};
            listeFinitions.querySelectorAll('input:checked').forEach(function (c) { cochees[c.value] = true; });
            listeFinitions.textContent = '';
            var nom = 'lignes[' + ligne.dataset.ligne + '][finitions][]';
            (f ? f.finitions : []).forEach(function (fi) {
                var etiquette = document.createElement('label');
                etiquette.className = 'si-puce';
                var caseFinition = document.createElement('input');
                caseFinition.type = 'checkbox';
                caseFinition.name = nom;
                caseFinition.value = fi.id;
                caseFinition.checked = fi.obligatoire || fi.parDefaut || !!cochees[fi.id];
                caseFinition.disabled = fi.obligatoire;
                var texte = document.createElement('span');
                texte.textContent = fi.nom + (fi.obligatoire ? ' (inclus)' : '');
                etiquette.appendChild(caseFinition);
                etiquette.appendChild(texte);
                listeFinitions.appendChild(etiquette);
            });
            bloc(ligne, 'finitions').hidden = !(f && f.finitions.length);
        }
        majMessage();
    }

    function majMessage() {
        var unProduit = lignes().some(function (l) { return !!fiches[champ(l, 'produit').value]; });
        facultatif.textContent = unProduit ? '(facultatif)' : '(décrivez votre projet ici ou dans un produit « Autre projet »)';
    }

    /* ---------- Prix indicatif d'une ligne ---------- */

    function parametres(ligne) {
        var p = new URLSearchParams();
        p.set('produit', champ(ligne, 'produit').value);
        ['largeur', 'hauteur', 'quantite'].forEach(function (nom) {
            var c = champ(ligne, nom);
            if (c && !c.closest('[hidden]') && c.value !== '') { p.set(nom, c.value); }
        });
        var pm2 = ligne.querySelector('[data-pieces-m2]');
        if (pm2 && !pm2.hidden) {
            var unite = pm2.querySelector('[data-pm2-unite]:checked');
            p.set('unite_quantite', unite ? unite.value : 'piece');
            var m2 = pm2.querySelector('[data-pm2-m2]');
            if (m2 && m2.value !== '') { p.set('quantite_m2', m2.value); }
        }
        ligne.querySelectorAll('[data-liste-finitions] input:checked:not(:disabled)').forEach(function (c) {
            p.append('finitions[]', c.value);
        });

        return p;
    }

    function montrerPrix(ligne, texte, montant, attente) {
        var zone = ligne.querySelector('[data-prix]');
        ligne.dataset.prix = montant > 0 ? String(montant) : '';
        zone.hidden = !texte;
        zone.classList.toggle('si-ligne-prix-attente', !!attente);
        zone.classList.toggle('si-ligne-prix-montant', montant > 0);
        zone.textContent = '';
        if (montant > 0) {
            zone.appendChild(document.createTextNode('Prix indicatif '));
            var fort = document.createElement('strong');
            fort.textContent = texte;
            zone.appendChild(fort);
            var petit = document.createElement('small');
            petit.textContent = ' · à confirmer par l\'imprimerie';
            zone.appendChild(petit);
        } else {
            zone.textContent = texte || '';
        }
        majTotal();
    }

    function estimer(ligne) {
        clearTimeout(ligne._minuteur);
        var produit = champ(ligne, 'produit').value;
        if (!fiches[produit]) {
            if (ligne._requete) { ligne._requete.abort(); }
            montrerPrix(ligne, champ(ligne, 'description').value.trim() !== '' || lignes().length > 1 ? 'Autre projet : prix donné par l\'imprimerie avec le devis.' : '', 0, false);
            return;
        }
        ligne._minuteur = setTimeout(function () {
            if (ligne._requete) { ligne._requete.abort(); }
            var controle = window.AbortController ? new AbortController() : null;
            ligne._requete = controle;
            montrerPrix(ligne, 'Calcul du prix indicatif…', 0, true);
            fetch(form.dataset.estimation + '?' + parametres(ligne).toString(), {headers: {Accept: 'application/json'}, signal: controle ? controle.signal : undefined})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.total > 0) {
                        montrerPrix(ligne, d.texte || fcfa(d.total), d.total, false);
                    } else {
                        montrerPrix(ligne, d && d.erreur ? d.erreur : 'Prix donné par l\'imprimerie avec le devis.', 0, false);
                    }
                })
                .catch(function (e) {
                    if (!e || e.name !== 'AbortError') {
                        montrerPrix(ligne, 'Prix indicatif indisponible : l\'imprimerie le donne avec le devis.', 0, false);
                    }
                });
        }, 350);
    }

    function majTotal() {
        var toutes = lignes();
        var chiffrees = toutes.filter(function (l) { return Number(l.dataset.prix) > 0; });
        var total = chiffrees.reduce(function (s, l) { return s + Number(l.dataset.prix); }, 0);
        totalBloc.hidden = chiffrees.length === 0;
        if (chiffrees.length === 0) {
            return;
        }
        totalBloc.querySelector('[data-total]').textContent = fcfa(total);
        var n = toutes.length;
        totalBloc.querySelector('[data-total-detail]').textContent = chiffrees.length === n
            ? '(' + n + ' produit' + (n > 1 ? 's' : '') + ')'
            : '(' + chiffrees.length + ' produit' + (chiffrees.length > 1 ? 's' : '') + ' sur ' + n + ', le reste chiffré par l\'imprimerie)';
    }

    /* ---------- Fichiers : un par ligne, poids par fichier et en tout ---------- */

    function fichiersChoisis(sauf) {
        var total = 0;
        var nombre = 0;
        lignes().forEach(function (l) {
            var c = champ(l, 'fichier');
            if (c !== sauf && c.files && c.files[0]) {
                total += c.files[0].size;
                nombre += 1;
            }
        });

        return {total: total, nombre: nombre};
    }

    function erreurFichier(ligne, texte) {
        var e = ligne.querySelector('[data-erreur-fichier]');
        e.textContent = texte || '';
        e.hidden = !texte;
        ligne.querySelector('.si-fichier').classList.toggle('si-champ-erreur', !!texte);
        champ(ligne, 'fichier').setAttribute('aria-invalid', texte ? 'true' : 'false');
    }

    function choisirFichier(ligne) {
        var c = champ(ligne, 'fichier');
        var nom = ligne.querySelector('[data-nom-fichier]');
        if (!nom.dataset.texte) { nom.dataset.texte = nom.textContent; }
        nom.textContent = nom.dataset.texte;
        ligne.querySelector('.si-depot').classList.remove('si-depot-plein');
        erreurFichier(ligne, '');
        var f = c.files && c.files[0];
        if (!f) {
            return;
        }
        var autres = fichiersChoisis(c);
        var refus = null;
        if (tailleMax && f.size > tailleMax) {
            refus = 'Ce fichier fait ' + mo(f.size) + ' Mo, ' + form.dataset.maxMo + ' Mo au plus : envoyez-le par WhatsApp ou collez un lien dans le message.';
        } else if (totalMax && autres.total + f.size > totalMax) {
            refus = 'Avec ce fichier, vos fichiers feraient ' + mo(autres.total + f.size) + ' Mo en tout, ' + form.dataset.totalMo + ' Mo au plus : envoyez-le par WhatsApp ou collez un lien dans le message.';
        } else if (autres.nombre + 1 > fichiersMax) {
            refus = fichiersMax + ' fichiers au plus par demande : envoyez celui-ci par WhatsApp ou collez un lien dans le message.';
        }
        if (refus) {
            c.value = '';
            erreurFichier(ligne, refus);
            return;
        }
        nom.textContent = f.name + ' · ' + mo(f.size) + ' Mo';
        ligne.querySelector('.si-depot').classList.add('si-depot-plein');
    }

    /* ---------- Lignes : brancher, ajouter, retirer, numéroter ---------- */

    function numeroter() {
        var toutes = lignes();
        toutes.forEach(function (l, i) {
            var n = i + 1;
            l.querySelector('[data-ligne-num]').textContent = n;
            l.querySelector('[data-ligne-nom]').textContent = 'Produit ' + n;
            var retirer = l.querySelector('[data-retirer]');
            retirer.hidden = toutes.length < 2;
            retirer.setAttribute('aria-label', 'Retirer le produit ' + n);
        });
        ajouter.disabled = toutes.length >= lignesMax;
        document.getElementById('panier-vide').hidden = toutes.length > 0;
        majTotal();
        majMessage();
    }

    function brancher(ligne) {
        var cle = Number(ligne.dataset.ligne);
        if (!isNaN(cle)) { prochaine = Math.max(prochaine, cle + 1); }
        champ(ligne, 'produit').addEventListener('change', function () {
            appliquer(ligne, false);
            estimer(ligne);
            garder();
        });
        ligne.addEventListener('input', function (e) {
            if (e.target.type === 'file') { return; }
            estimer(ligne);
            garder();
        });
        ligne.addEventListener('change', function (e) {
            if (e.target.type === 'file') {
                choisirFichier(ligne);
                return;
            }
            if (e.target.matches('[data-champ="produit"]')) { return; }
            estimer(ligne);
            garder();
        });
        ligne.querySelector('[data-retirer]').addEventListener('click', function (e) {
            e.preventDefault();
            if (lignes().length < 2) { return; }
            var suivante = ligne.nextElementSibling || ligne.previousElementSibling;
            ligne.remove();
            numeroter();
            garder();
            if (suivante) { champ(suivante, 'produit').focus(); }
        });
    }

    function nouvelleLigne() {
        var cle = prochaine++;
        var html = modele.innerHTML.replace(/__i__/g, String(cle)).replace(/__n__/g, String(lignes().length + 1));
        var gabarit = document.createElement('template');
        gabarit.innerHTML = html.trim();
        var ligne = gabarit.content.firstElementChild;
        ligne.classList.add('si-ligne-nouvelle');
        liste.appendChild(ligne);
        brancher(ligne);
        appliquer(ligne, false);
        numeroter();

        return ligne;
    }

    ajouter.addEventListener('click', function (e) {
        e.preventDefault();
        if (lignes().length >= lignesMax) { return; }
        var ligne = nouvelleLigne();
        estimer(ligne);
        garder();
        champ(ligne, 'produit').focus();
    });

    /* ---------- Panier gardé dans l'onglet ---------- */

    function lireLigne(ligne) {
        var pm2 = ligne.querySelector('[data-pieces-m2]');
        var unite = pm2 ? pm2.querySelector('[data-pm2-unite]:checked') : null;
        var m2 = pm2 ? pm2.querySelector('[data-pm2-m2]') : null;

        return {
            produit: champ(ligne, 'produit').value,
            largeur: champ(ligne, 'largeur').value,
            hauteur: champ(ligne, 'hauteur').value,
            quantite: champ(ligne, 'quantite').value,
            unite: unite ? unite.value : 'piece',
            m2: m2 ? m2.value : '',
            description: champ(ligne, 'description').value,
            finitions: Array.prototype.map.call(ligne.querySelectorAll('[data-liste-finitions] input:checked:not(:disabled)'), function (c) { return c.value; }),
        };
    }

    function ecrireLigne(ligne, d) {
        champ(ligne, 'produit').value = fiches[d.produit] ? d.produit : '';
        appliquer(ligne, false);
        champ(ligne, 'largeur').value = d.largeur || '';
        champ(ligne, 'hauteur').value = d.hauteur || '';
        champ(ligne, 'quantite').value = d.quantite || '';
        champ(ligne, 'description').value = d.description || '';
        ligne.querySelectorAll('[data-liste-finitions] input:not(:disabled)').forEach(function (c) {
            c.checked = (d.finitions || []).indexOf(c.value) !== -1;
        });
        var pm2 = ligne.querySelector('[data-pieces-m2]');
        if (pm2) {
            var radio = pm2.querySelector('[data-pm2-unite][value="' + (d.unite === 'm2' ? 'm2' : 'piece') + '"]');
            if (radio) { radio.checked = true; }
            var m2 = pm2.querySelector('[data-pm2-m2]');
            if (m2) { m2.value = d.m2 || ''; }
            if (window.SiPiecesM2) { window.SiPiecesM2.rafraichir(pm2); }
        }
    }

    function utile(d) {
        return !!(d.produit || (d.description || '').trim());
    }

    function garder() {
        try {
            var contenu = lignes().map(lireLigne).filter(utile);
            if (contenu.length) {
                window.sessionStorage.setItem(CLE_BROUILLON, JSON.stringify(contenu.slice(0, lignesMax)));
            } else {
                window.sessionStorage.removeItem(CLE_BROUILLON);
            }
        } catch (e) { /* stockage refusé : le panier reste dans la page */ }
    }

    function brouillon() {
        try {
            var d = JSON.parse(window.sessionStorage.getItem(CLE_BROUILLON) || '[]');
            return Array.isArray(d) ? d.filter(function (l) { return l && typeof l === 'object' && utile(l); }) : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Arrivée sur la page : le panier en cours revient (« Ajouter au devis »
     * du calculateur : ses produits d'abord, celui du calculateur à la fin ;
     * retour sur la page : tel qu'il était). Jamais après un envoi refusé :
     * la page montre alors ce qui a été envoyé.
     */
    function reprendre() {
        var adresse = new URL(window.location.href);
        var ajout = adresse.searchParams.get('ajouter') === '1';
        if (ajout && window.history.replaceState) {
            adresse.searchParams.delete('ajouter');
            window.history.replaceState(null, '', adresse.pathname + (adresse.search || ''));
        }
        if (form.dataset.reprise !== '1') {
            return;
        }
        var anciennes = brouillon();
        var premiere = lignes()[0];
        var vide = lignes().length === 1 && premiere && !utile(lireLigne(premiere));
        if (!anciennes.length || (!ajout && !vide)) {
            return;
        }
        anciennes = anciennes.slice(0, lignesMax - (vide ? 0 : 1));
        anciennes.forEach(function (d, i) {
            var ligne = vide && i === 0 ? premiere : nouvelleLigne();
            ecrireLigne(ligne, d);
            if (!(vide && i === 0)) { ligne.classList.remove('si-ligne-nouvelle'); }
        });
        if (!vide && premiere) {
            // Le produit du calculateur passe en dernier.
            liste.appendChild(premiere);
            var texte = form.querySelector('[data-repris-texte]');
            if (texte) {
                texte.textContent = 'Ajouté à votre devis en cours (' + lignes().length + ' produits) : vérifiez, puis ajoutez vos coordonnées.';
            }
        }
        numeroter();
    }

    /* ---------- Envoi ---------- */

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.getAttribute('formnovalidate') !== null) {
            return; // Ajouter / Retirer sans script : géré plus haut.
        }
        var choisis = fichiersChoisis(null);
        if ((totalMax && choisis.total > totalMax) || choisis.nombre > fichiersMax) {
            e.preventDefault();
            var derniere = lignes().filter(function (l) { var c = champ(l, 'fichier'); return c.files && c.files[0]; }).pop();
            if (derniere) {
                erreurFichier(derniere, 'Vos fichiers font ' + mo(choisis.total) + ' Mo en tout, ' + form.dataset.totalMo + ' Mo au plus : envoyez les plus lourds par WhatsApp ou collez un lien dans le message.');
                champ(derniere, 'fichier').focus();
            }
            return;
        }
        try { window.sessionStorage.removeItem(CLE_BROUILLON); } catch (err) { /* rien */ }
        var bouton = document.getElementById('envoyer-devis');
        setTimeout(function () { bouton.disabled = true; bouton.textContent = 'Envoi en cours…'; }, 0);
    });

    lignes().forEach(function (l) {
        brancher(l);
        appliquer(l, true);
    });
    reprendre();
    numeroter();
    lignes().forEach(estimer);
})();
