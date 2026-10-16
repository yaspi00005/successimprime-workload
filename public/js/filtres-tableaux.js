/*
 * Filtres des tableaux (docs/filtres-tableaux.md).
 *
 * Une petite flèche dans l'en-tête de chaque colonne ouvre un menu comme
 * dans Excel : tri, plage min/max pour les montants et les dates,
 * recherche, valeurs à cocher avec leur nombre, « Effacer ». Les filtres de
 * plusieurs colonnes se combinent. Un en-tête filtré est marqué, et une
 * ligne « N lignes sur M · Effacer les filtres » apparaît au-dessus du
 * tableau.
 *
 * Deux façons de filtrer :
 *  - dans la page (par défaut) : le filtre agit sur les lignes affichées,
 *    rien n'est demandé au serveur. Le choix est gardé par page sur cet
 *    appareil (localStorage) ;
 *  - par le serveur (<table data-filtres="serveur">), pour les listes
 *    coupées en pages : chaque colonne dit quel paramètre de l'adresse elle
 *    règle (data-filtre-param + data-filtre-choix, data-filtre-recherche,
 *    data-filtre-tri-asc / -desc, data-filtre-min / -max) et le choix
 *    recharge la liste. Le serveur continue de n'envoyer que ce que la
 *    personne a le droit de voir.
 *
 * admin.js charge ce fichier seulement si la page a une liste. Toute liste
 * de l'administration (un <thead> d'une ligne, au moins deux lignes de
 * données, aucune case de saisie) est équipée ; data-sans-filtres sur le
 * tableau (ou un parent) l'en dispense, data-sans-filtre sur un <th> retire
 * la flèche de cette colonne. Les colonnes d'actions et de cases à cocher
 * n'en ont pas. data-filtre-valeur sur une cellule donne la valeur à
 * filtrer quand le texte affiché ne convient pas.
 */
(function () {
    'use strict';

    if (window.SiFiltresTableaux) {
        return;
    }

    // Mémoire propre à chaque compte : sur un poste partagé, chacun retrouve ses filtres, pas ceux des autres.
    var COMPTE = (document.currentScript && document.currentScript.getAttribute('data-compte')) || '';
    var PREFIXE = 'si-filtres:' + COMPTE + ':';
    var VIDE = '(vide)';
    var MAX_VALEURS = 300;
    var MOBILE = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : { matches: false };
    var TACTILE = window.matchMedia ? window.matchMedia('(pointer: coarse)') : { matches: false };
    var collateur = window.Intl && Intl.Collator ? new Intl.Collator('fr', { numeric: true, sensitivity: 'base' }) : null;

    var SVG = {
        fleche: '<path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        entonnoir: '<path d="M2.5 3h11l-4.3 5.1v4.2l-2.4 1.2V8.1z" fill="currentColor"/>',
        haut: '<path d="M8 13V3M4.5 6.5L8 3l3.5 3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        bas: '<path d="M8 3v10M4.5 9.5L8 13l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
    };

    function icone(nom) {
        return '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">' + SVG[nom] + '</svg>';
    }

    function normaliser(texte) {
        return String(texte == null ? '' : texte).replace(/[\s  ]+/g, ' ').trim();
    }

    function comparerTextes(a, b) {
        return collateur ? collateur.compare(a, b) : (a < b ? -1 : (a > b ? 1 : 0));
    }

    function pluriel(n, mot) {
        return n.toLocaleString('fr-FR') + ' ' + mot + (n > 1 ? 's' : '');
    }

    function element(balise, classe, texte) {
        var el = document.createElement(balise);
        if (classe) {
            el.className = classe;
        }
        if (texte != null) {
            el.textContent = texte;
        }
        return el;
    }

    /* ---------- Mémoire par page (peut être absente : navigation privée) ---------- */

    function lire(cle) {
        try {
            var brut = window.localStorage.getItem(cle);
            return brut ? JSON.parse(brut) : null;
        } catch (e) {
            return null;
        }
    }

    function ecrire(cle, valeur) {
        try {
            if (valeur) {
                window.localStorage.setItem(cle, JSON.stringify(valeur));
            } else {
                window.localStorage.removeItem(cle);
            }
        } catch (e) {
            // Rien : le filtre marche, il ne sera simplement pas retenu.
        }
    }

    /* ---------- Valeur, nombre et date d'une cellule ---------- */

    // Ce qui ne compte pas dans la valeur d'une cellule : boutons, icônes, initiales d'avatar…
    var SAUTER = 'script, style, template, button, .btn, form, input, select, textarea, .visually-hidden, .sr-only, [aria-hidden="true"], [class*="avatar"]';
    // Petites lignes d'appoint sous la valeur (téléphone, nombre de lignes, « #12 »).
    var APPOINT = 'small, .small, .text-muted';

    function texteSans(el) {
        if (!el.querySelector(SAUTER)) {
            return normaliser(el.textContent);
        }
        var copie = el.cloneNode(true);
        Array.prototype.forEach.call(copie.querySelectorAll(SAUTER), function (x) { x.remove(); });
        return normaliser(copie.textContent);
    }

    function valeurDans(el, profondeur) {
        var morceaux = [];
        Array.prototype.forEach.call(el.childNodes, function (noeud) {
            var texte;
            if (noeud.nodeType === 3) {
                texte = normaliser(noeud.textContent);
                if (texte) {
                    morceaux.push({ texte: texte, appoint: false, noeud: null });
                }
            } else if (noeud.nodeType === 1 && !noeud.matches(SAUTER)) {
                texte = texteSans(noeud);
                if (texte) {
                    morceaux.push({ texte: texte, appoint: noeud.matches(APPOINT), noeud: noeud });
                }
            }
        });
        var principal = morceaux.filter(function (m) { return !m.appoint; })[0] || morceaux[0];
        if (!principal) {
            return '';
        }
        // Un bloc qui regroupe nom, avatar et petites lignes : on regarde dedans.
        var n = principal.noeud;
        if (n && profondeur < 4 && Array.prototype.some.call(n.children, function (x) {
            return x.matches(SAUTER + ', ' + APPOINT + ', div, p, br');
        })) {
            return valeurDans(n, profondeur + 1) || principal.texte;
        }
        return principal.texte;
    }

    /*
     * Valeur d'une cellule : data-filtre-valeur, sinon le premier morceau de
     * texte principal (lien, nom, badge…), sans les petites lignes d'appoint
     * (small, .text-muted), les boutons, les icônes ni les initiales d'avatar.
     */
    function valeurCellule(td) {
        if (td.hasAttribute('data-filtre-valeur')) {
            return normaliser(td.getAttribute('data-filtre-valeur')) || VIDE;
        }
        var valeur = valeurDans(td, 0);
        return (valeur === '' || valeur === '—' || valeur === '-' || valeur === '–') ? VIDE : valeur;
    }

    // « 12 500 FCFA », « 1 234,5 », « 12 % », « 3 feuilles » -> nombre.
    function enNombre(texte) {
        var m = /^([-+−]?\d[\d   .]*(?:,\d+)?)\s*([^\d]{0,15})$/.exec(texte);
        if (!m) {
            return null;
        }
        var chiffres = m[1].replace(/[   ]/g, '').replace('−', '-');
        if (chiffres.indexOf(',') >= 0) {
            chiffres = chiffres.replace(/\./g, '').replace(',', '.');
        } else if (/\.\d{3}(\.|$)/.test(chiffres)) {
            chiffres = chiffres.replace(/\./g, '');
        }
        var n = parseFloat(chiffres);
        return isNaN(n) ? null : n;
    }

    // « 14/10/2026 », « 14/10/26 », « 14/10/2026 10:30 », « 14/10/2026 à 10h30 » -> horodatage.
    function enDate(texte) {
        var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4}|\d{2})(?:\s+(?:à\s+)?(\d{1,2})[:h](\d{2}))?(?:\s|$)/.exec(texte);
        if (!m) {
            return null;
        }
        var annee = m[3].length === 2 ? 2000 + +m[3] : +m[3];
        var d = new Date(annee, +m[2] - 1, +m[1], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0);
        return isNaN(d.getTime()) ? null : d.getTime();
    }

    function typeColonne(valeurs) {
        var remplies = valeurs.filter(function (v) { return v !== VIDE; });
        if (!remplies.length) {
            return null;
        }
        if (remplies.every(function (v) { return enDate(v) !== null; })) {
            return 'date';
        }
        if (remplies.every(function (v) { return enNombre(v) !== null; })) {
            return 'nombre';
        }
        return 'texte';
    }

    function cle(type, valeur) {
        if (valeur === VIDE) {
            return null;
        }
        if (type === 'nombre') {
            return enNombre(valeur);
        }
        if (type === 'date') {
            return enDate(valeur);
        }
        return valeur;
    }

    function comparerCles(type, a, b) {
        return type === 'texte' ? comparerTextes(a, b) : a - b;
    }

    // Borne d'une plage saisie dans le menu (nombre, ou date jj/mm/aaaa du champ date).
    function borne(type, texte, fin) {
        texte = normaliser(texte);
        if (texte === '') {
            return null;
        }
        if (type === 'date') {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(texte);
            if (!m) {
                return null;
            }
            return fin
                ? new Date(+m[1], +m[2] - 1, +m[3], 23, 59, 59, 999).getTime()
                : new Date(+m[1], +m[2] - 1, +m[3]).getTime();
        }
        var n = parseFloat(texte.replace(/[   ]/g, '').replace(',', '.'));
        return isNaN(n) ? null : n;
    }

    function libellesTri(type) {
        if (type === 'nombre') {
            return ['Du plus petit au plus grand', 'Du plus grand au plus petit'];
        }
        if (type === 'date') {
            return ['Du plus ancien au plus récent', 'Du plus récent au plus ancien'];
        }
        return ['Trier de A à Z', 'Trier de Z à A'];
    }

    /* ---------- Quels tableaux, quelles colonnes ---------- */

    function libelleEntete(th) {
        return normaliser(th.getAttribute('data-filtre-libelle') || th.textContent);
    }

    function colonneExclue(th) {
        var libelle = libelleEntete(th);
        return th.hasAttribute('data-sans-filtre')
            || th.querySelector('input, select, button')
            || libelle === ''
            || /^(actions?|options?|#)$/i.test(libelle);
    }

    function estLigneDeDonnees(tr, nbColonnes) {
        if (tr.cells.length !== nbColonnes) {
            return false;
        }
        return !Array.prototype.some.call(tr.cells, function (td) {
            return td.colSpan > 1 || td.rowSpan > 1;
        });
    }

    /* Ce tableau est-il une liste à équiper ? Rend ses en-têtes et lignes, sinon null. */
    function examiner(table) {
        if (table.hasAttribute('data-filtres-pret')
            || table.closest('[data-sans-filtres], .modal, .dataTables_wrapper, .dropdown-menu')
            || table.classList.contains('dataTable')) {
            return null;
        }
        var serveur = table.getAttribute('data-filtres') === 'serveur';
        var thead = table.tHead;
        if (!thead || thead.rows.length !== 1 || table.tBodies.length !== 1) {
            return null;
        }
        var entetes = Array.prototype.slice.call(thead.rows[0].cells);
        if (entetes.length < 2 || entetes.some(function (th) { return th.colSpan > 1; })) {
            return null;
        }
        var tbody = table.tBodies[0];
        // Grille de saisie, liste qu'on réordonne à la main, tableau dans un tableau : on n'y touche pas.
        if (tbody.querySelector('input:not([type="checkbox"]):not([type="hidden"]):not([type="radio"]), select, textarea, [contenteditable="true"], [contenteditable=""], table')
            || /sortable/i.test(tbody.id) || tbody.hasAttribute('data-sortable') || tbody.querySelector('[class*="sortable"]')) {
            return null;
        }
        var lignes = Array.prototype.slice.call(tbody.rows);
        var donnees = lignes.filter(function (tr) { return estLigneDeDonnees(tr, entetes.length); });
        if (serveur) {
            return { serveur: true, entetes: entetes, tbody: tbody, lignes: donnees };
        }
        // Une ligne « Aucun résultat » d'une seule case sur toute la largeur est tolérée ;
        // d'autres lignes de détail mêlées aux lignes de la liste, non : trier les séparerait.
        var messages = lignes.filter(function (tr) {
            return tr.cells.length === 1 && tr.cells[0].colSpan >= entetes.length;
        });
        if (donnees.length < 2 || donnees.length + messages.length !== lignes.length) {
            return null;
        }
        return { serveur: false, entetes: entetes, tbody: tbody, lignes: donnees };
    }

    /* ---------- État d'un tableau ---------- */

    var tableaux = [];
    var ouvert = null; // { t, c, bouton }

    function filtreActif(c) {
        if (c.serveur) {
            return c.serveur.actif;
        }
        return !!(c.filtre || c.min !== null || c.max !== null);
    }

    function passe(c, ligne) {
        var v = ligne.valeurs[c.index];
        if (c.filtre) {
            if (c.filtre.inclure && !c.filtre.inclure.has(v)) {
                return false;
            }
            if (c.filtre.exclure && c.filtre.exclure.has(v)) {
                return false;
            }
        }
        if (c.min !== null || c.max !== null) {
            var k = ligne.cles[c.index];
            if (k === null || (c.min !== null && k < c.min) || (c.max !== null && k > c.max)) {
                return false;
            }
        }
        return true;
    }

    // Ligne cachée par un autre outil de la page (sa propre recherche, par exemple).
    function cacheeAilleurs(tr) {
        return tr.style.display === 'none' || tr.hidden;
    }

    function cleMemoire(t) {
        return PREFIXE + window.location.pathname + ':' + (t.table.id || t.numero);
    }

    // Filtres laissés sur cet appareil par un autre compte : effacés.
    function oublierAutresComptes() {
        try {
            Object.keys(window.localStorage).forEach(function (cle) {
                if (cle.indexOf('si-filtres:') === 0 && cle.indexOf(PREFIXE) !== 0) {
                    window.localStorage.removeItem(cle);
                }
            });
        } catch (e) {
            // Stockage indisponible : rien à effacer.
        }
    }

    function sauver(t) {
        if (t.serveur) {
            return;
        }
        var colonnes = {};
        var vide = true;
        t.colonnes.forEach(function (c) {
            if (!filtreActif(c)) {
                return;
            }
            vide = false;
            colonnes[c.libelle] = {
                i: c.filtre && c.filtre.inclure ? Array.from(c.filtre.inclure) : undefined,
                e: c.filtre && c.filtre.exclure ? Array.from(c.filtre.exclure) : undefined,
                min: c.minTexte || undefined,
                max: c.maxTexte || undefined
            };
        });
        if (t.tri) {
            vide = false;
        }
        ecrire(cleMemoire(t), vide ? null : {
            colonnes: colonnes,
            tri: t.tri ? { colonne: t.tri.c.libelle, sens: t.tri.sens } : undefined
        });
    }

    function restaurer(t) {
        var memoire = lire(cleMemoire(t));
        if (!memoire || typeof memoire !== 'object') {
            return;
        }
        t.colonnes.forEach(function (c) {
            var m = memoire.colonnes && memoire.colonnes[c.libelle];
            if (!m) {
                return;
            }
            if (Array.isArray(m.i)) {
                c.filtre = { inclure: new Set(m.i.map(String)) };
            } else if (Array.isArray(m.e)) {
                c.filtre = { exclure: new Set(m.e.map(String)) };
            }
            reglerPlage(c, m.min || '', m.max || '');
        });
        if (memoire.tri) {
            var c = t.colonnes.filter(function (x) { return x.libelle === memoire.tri.colonne; })[0];
            if (c) {
                t.tri = { c: c, sens: memoire.tri.sens === -1 ? -1 : 1 };
            }
        }
    }

    function reglerPlage(c, minTexte, maxTexte) {
        c.minTexte = normaliser(minTexte);
        c.maxTexte = normaliser(maxTexte);
        c.min = borne(c.type, c.minTexte, false);
        c.max = borne(c.type, c.maxTexte, true);
    }

    /* ---------- Appliquer : lignes, ordre, en-têtes, barre ---------- */

    /*
     * Si un autre script de la page a remplacé des lignes depuis le
     * démarrage, on relit le tableau avant de filtrer ou de trier : jamais
     * de vieille ligne remise dans la liste.
     */
    function relire(t) {
        var actuelles = Array.prototype.filter.call(t.tbody.rows, function (tr) {
            return estLigneDeDonnees(tr, t.nbColonnes);
        });
        var connues = new Set(t.lignes.map(function (l) { return l.tr; }));
        if (actuelles.length === t.lignes.length && actuelles.every(function (tr) { return connues.has(tr); })) {
            return;
        }
        t.lignes = actuelles.map(function (tr, ordre) {
            var l = { tr: tr, ordre: ordre, valeurs: {}, cles: {} };
            t.colonnes.forEach(function (c) {
                l.valeurs[c.index] = valeurCellule(tr.cells[c.index]);
                l.cles[c.index] = cle(c.type, l.valeurs[c.index]);
            });
            return l;
        });
    }

    function appliquer(t) {
        relire(t);
        var visibles = 0;
        t.lignes.forEach(function (l) {
            var ok = t.colonnes.every(function (c) { return passe(c, l); });
            l.tr.classList.toggle('si-ft-cache', !ok);
            if (ok && !cacheeAilleurs(l.tr)) {
                visibles++;
            }
        });
        ordonner(t);
        t.colonnes.forEach(function (c) { marquer(t, c); });
        majBarre(t, visibles);
        sauver(t);
    }

    function compterVisibles(t) {
        return t.lignes.filter(function (l) {
            return !l.tr.classList.contains('si-ft-cache') && !cacheeAilleurs(l.tr);
        }).length;
    }

    function ordonner(t) {
        var lignes = t.lignes.slice();
        if (t.tri) {
            var c = t.tri.c;
            var sens = t.tri.sens;
            lignes.sort(function (a, b) {
                var ka = a.cles[c.index];
                var kb = b.cles[c.index];
                if (ka === null || kb === null) {
                    // Les cases vides restent en bas, dans les deux sens.
                    return ka === kb ? a.ordre - b.ordre : (ka === null ? 1 : -1);
                }
                return (comparerCles(c.type, ka, kb) * sens) || (a.ordre - b.ordre);
            });
        } else {
            lignes.sort(function (a, b) { return a.ordre - b.ordre; });
        }
        var deja = lignes.every(function (l, i) { return t.tbody.rows[i] === l.tr; });
        if (deja) {
            return;
        }
        var fragment = document.createDocumentFragment();
        lignes.forEach(function (l) { fragment.appendChild(l.tr); });
        t.tbody.appendChild(fragment);
    }

    function sensTri(t, c) {
        if (c.serveur) {
            return c.serveur.tri;
        }
        return t.tri && t.tri.c === c ? t.tri.sens : 0;
    }

    function marquer(t, c) {
        var actif = filtreActif(c);
        var sens = sensTri(t, c);
        c.bouton.classList.toggle('si-ft-actif', actif);
        c.bouton.classList.toggle('si-ft-trie', sens !== 0);
        var iconeTri = sens === 1 ? icone('haut') : (sens === -1 ? icone('bas') : '');
        c.bouton.innerHTML = actif ? icone('entonnoir') + iconeTri : (iconeTri || icone('fleche'));
        var etat = [];
        if (actif) {
            etat.push('filtrée');
        }
        if (sens !== 0) {
            etat.push(sens === 1 ? 'triée en ordre croissant' : 'triée en ordre décroissant');
        }
        c.bouton.setAttribute('aria-label', 'Filtrer ou trier la colonne « ' + c.libelle + ' »' + (etat.length ? ' (' + etat.join(', ') + ')' : ''));
        c.bouton.title = actif ? 'Colonne filtrée : cliquez pour changer' : 'Filtrer ou trier';
        c.th.classList.toggle('si-ft-filtre', actif);
        if (sens !== 0) {
            c.th.setAttribute('aria-sort', sens === 1 ? 'ascending' : 'descending');
        } else {
            c.th.removeAttribute('aria-sort');
        }
    }

    function majBarre(t, visibles) {
        var actives = t.colonnes.filter(filtreActif);
        t.barre.hidden = actives.length === 0;
        if (!actives.length) {
            return;
        }
        var noms = actives.map(function (c) { return c.libelle; }).join(', ');
        if (t.serveur) {
            t.barreTexte.textContent = 'Filtré par ' + noms;
        } else {
            t.barreTexte.textContent = pluriel(visibles, 'ligne') + ' sur ' + t.lignes.length.toLocaleString('fr-FR') + ' · filtré par ' + noms;
        }
    }

    function effacerTout(t) {
        if (t.serveur) {
            var url = new URL(window.location.href);
            t.colonnes.forEach(function (c) {
                c.serveur.parametres.forEach(function (p) { url.searchParams.delete(p); });
                url.searchParams.delete(c.serveur.triParam);
            });
            url.searchParams.delete('page');
            window.location.assign(url.toString());
            return;
        }
        t.colonnes.forEach(function (c) {
            c.filtre = null;
            reglerPlage(c, '', '');
        });
        t.tri = null;
        appliquer(t);
    }

    /* ---------- Équiper un tableau ---------- */

    function equiper(table, examen, numero) {
        var t = {
            table: table,
            tbody: examen.tbody,
            serveur: examen.serveur,
            nbColonnes: examen.entetes.length,
            numero: numero,
            colonnes: [],
            lignes: [],
            tri: null
        };
        var parametres = new URLSearchParams(window.location.search);

        t.lignes = examen.lignes.map(function (tr, ordre) {
            return { tr: tr, ordre: ordre, valeurs: {}, cles: {} };
        });

        examen.entetes.forEach(function (th, index) {
            if (colonneExclue(th)) {
                return;
            }
            var c = { index: index, th: th, libelle: libelleEntete(th), filtre: null, min: null, max: null, minTexte: '', maxTexte: '' };
            if (t.serveur) {
                c.serveur = configServeur(table, th, parametres);
                if (!c.serveur) {
                    return;
                }
                c.type = th.getAttribute('data-filtre-type') || 'texte';
            } else {
                var valeurs = t.lignes.map(function (l) {
                    var v = valeurCellule(l.tr.cells[index]);
                    l.valeurs[index] = v;
                    return v;
                });
                c.type = th.getAttribute('data-filtre-type') || typeColonne(valeurs);
                if (!c.type) {
                    return; // Colonne sans texte (icônes, barres) : pas de flèche.
                }
                t.lignes.forEach(function (l) {
                    l.cles[index] = cle(c.type, l.valeurs[index]);
                });
            }
            t.colonnes.push(c);
        });

        if (!t.colonnes.length) {
            return null;
        }
        table.setAttribute('data-filtres-pret', '');

        t.colonnes.forEach(function (c) {
            var bouton = element('button', 'si-ft-fleche');
            bouton.type = 'button';
            bouton.setAttribute('aria-haspopup', 'dialog');
            bouton.setAttribute('aria-expanded', 'false');
            bouton.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (ouvert && ouvert.c === c) {
                    fermer(true);
                } else {
                    ouvrir(t, c);
                }
            });
            c.bouton = bouton;
            c.th.classList.add('si-ft-th');
            c.th.appendChild(bouton);
        });

        // Barre au-dessus du tableau (hors de la zone qui défile de côté).
        t.barre = element('div', 'si-ft-barre');
        t.barre.setAttribute('role', 'status');
        t.barre.hidden = true;
        t.barre.innerHTML = icone('entonnoir');
        t.barreTexte = element('span', 'si-ft-barre-texte');
        var effacer = element('button', 'si-ft-barre-effacer', 'Effacer les filtres');
        effacer.type = 'button';
        effacer.addEventListener('click', function () { effacerTout(t); });
        t.barre.appendChild(t.barreTexte);
        t.barre.appendChild(effacer);
        var ancre = table.closest('.table-responsive') || table;
        ancre.parentNode.insertBefore(t.barre, ancre);

        if (t.serveur) {
            t.colonnes.forEach(function (c) { marquer(t, c); });
            majBarre(t, 0);
        } else {
            restaurer(t);
            appliquer(t);
            // La recherche propre à la page cache des lignes : le compte suit.
            if (window.MutationObserver) {
                new MutationObserver(function () {
                    majBarre(t, compterVisibles(t));
                }).observe(t.tbody, { attributes: true, subtree: true, attributeFilter: ['style', 'hidden'] });
            }
        }
        return t;
    }

    function configServeur(table, th, parametres) {
        var lireAttr = function (nom) { return th.getAttribute(nom) || null; };
        var s = {
            param: lireAttr('data-filtre-param'),
            choix: [],
            recherche: lireAttr('data-filtre-recherche'),
            triParam: table.getAttribute('data-filtres-tri') || 'tri',
            triAsc: lireAttr('data-filtre-tri-asc'),
            triDesc: lireAttr('data-filtre-tri-desc'),
            min: lireAttr('data-filtre-min'),
            max: lireAttr('data-filtre-max'),
            parametres: []
        };
        if (!s.param && !s.recherche && !s.triAsc && !s.triDesc && !s.min && !s.max) {
            return null;
        }
        if (s.param) {
            // Choix : [[valeur, libellé], …] ou {valeur: libellé, …}.
            try {
                var brut = JSON.parse(th.getAttribute('data-filtre-choix') || '[]');
                if (!Array.isArray(brut) && brut && typeof brut === 'object') {
                    brut = Object.keys(brut).map(function (k) { return [k, brut[k]]; });
                }
                s.choix = (Array.isArray(brut) ? brut : []).filter(function (x) {
                    return Array.isArray(x) && x.length === 2;
                }).map(function (x) { return [String(x[0]), String(x[1])]; });
            } catch (e) {
                s.choix = [];
            }
        }
        [s.param, s.recherche, s.min, s.max].forEach(function (p) {
            if (p) {
                s.parametres.push(p);
            }
        });
        s.actif = s.parametres.some(function (p) { return normaliser(parametres.get(p)) !== ''; });
        var tri = parametres.get(s.triParam) || table.getAttribute('data-filtres-tri-defaut') || '';
        s.tri = tri !== '' && tri === s.triAsc ? 1 : (tri !== '' && tri === s.triDesc ? -1 : 0);
        return s;
    }

    /* ---------- Le menu ---------- */

    var menu = null;
    var fond = null;

    function creerMenu() {
        fond = element('div', 'si-ft-fond');
        fond.hidden = true;
        fond.addEventListener('click', function () { fermer(false); });
        menu = element('div', 'si-ft-menu');
        menu.hidden = true;
        menu.setAttribute('role', 'dialog');
        menu.tabIndex = -1;
        menu.addEventListener('keydown', clavierMenu);
        document.body.appendChild(fond);
        document.body.appendChild(menu);
    }

    function clavierMenu(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            fermer(true);
            return;
        }
        if (e.key !== 'Tab') {
            return;
        }
        // La touche Tab tourne dans le menu tant qu'il est ouvert.
        var focusables = Array.prototype.filter.call(
            menu.querySelectorAll('button, input, [tabindex="0"]'),
            function (el) { return !el.disabled && el.offsetParent !== null; }
        );
        if (!focusables.length) {
            return;
        }
        var premier = focusables[0];
        var dernier = focusables[focusables.length - 1];
        if (e.shiftKey && (document.activeElement === premier || document.activeElement === menu)) {
            e.preventDefault();
            dernier.focus();
        } else if (!e.shiftKey && document.activeElement === dernier) {
            e.preventDefault();
            premier.focus();
        }
    }

    function ouvrir(t, c) {
        if (!menu) {
            creerMenu();
        }
        if (ouvert) {
            fermer(false);
        }
        ouvert = { t: t, c: c };
        menu.innerHTML = '';
        menu.setAttribute('aria-label', 'Filtrer et trier : ' + c.libelle);
        var tete = element('div', 'si-ft-tete');
        tete.appendChild(element('b', null, c.libelle));
        var croix = element('button', 'si-ft-fermer');
        croix.type = 'button';
        croix.setAttribute('aria-label', 'Fermer');
        croix.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        croix.addEventListener('click', function () { fermer(true); });
        tete.appendChild(croix);
        menu.appendChild(tete);

        var corps = element('div', 'si-ft-corps');
        menu.appendChild(corps);
        var champ = c.serveur ? remplirServeur(t, c, corps) : remplirPage(t, c, corps);

        c.bouton.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
        fond.hidden = !MOBILE.matches;
        placer();
        // Sur téléphone, pas de clavier qui surgit : le menu prend le focus.
        if (champ && !TACTILE.matches) {
            champ.focus();
        } else {
            menu.focus();
        }
    }

    function fermer(rendreFocus) {
        if (!ouvert) {
            return;
        }
        var c = ouvert.c;
        ouvert = null;
        menu.hidden = true;
        fond.hidden = true;
        c.bouton.setAttribute('aria-expanded', 'false');
        if (rendreFocus) {
            c.bouton.focus();
        }
    }

    function placer() {
        if (!ouvert) {
            return;
        }
        if (MOBILE.matches) {
            menu.style.top = menu.style.left = menu.style.maxHeight = '';
            return;
        }
        var r = ouvert.c.bouton.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) {
            fermer(false);
            return;
        }
        var largeur = menu.offsetWidth;
        var gauche = Math.min(Math.max(8, r.right - largeur), window.innerWidth - largeur - 8);
        var dessous = window.innerHeight - r.bottom - 12;
        var dessus = r.top - 12;
        menu.style.left = Math.max(8, gauche) + 'px';
        if (dessous >= 400 || dessous >= dessus) {
            menu.style.top = (r.bottom + 4) + 'px';
            menu.style.maxHeight = Math.max(200, dessous) + 'px';
        } else {
            var hauteur = Math.min(menu.scrollHeight, dessus);
            menu.style.top = Math.max(8, r.top - 4 - hauteur) + 'px';
            menu.style.maxHeight = dessus + 'px';
        }
    }

    function section(corps, classe) {
        var s = element('div', 'si-ft-section' + (classe ? ' ' + classe : ''));
        corps.appendChild(s);
        return s;
    }

    function boutonTri(libelle, sens, actif, action) {
        var b = element('button', 'si-ft-tri');
        b.type = 'button';
        b.innerHTML = icone(sens === 1 ? 'haut' : 'bas');
        b.appendChild(document.createTextNode(libelle));
        b.setAttribute('aria-pressed', actif ? 'true' : 'false');
        b.addEventListener('click', action);
        return b;
    }

    function champPlage(c, libelleMin, libelleMax, valeurMin, valeurMax) {
        var bloc = element('div', 'si-ft-plage');
        var type = c.type === 'date' ? 'date' : 'number';
        var creer = function (libelle, valeur) {
            var etiquette = element('label', null);
            etiquette.appendChild(element('span', null, libelle));
            var input = element('input', 'si-ft-champ');
            input.type = type;
            if (type === 'number') {
                input.inputMode = 'decimal';
                input.step = 'any';
            }
            input.value = valeur || '';
            etiquette.appendChild(input);
            bloc.appendChild(etiquette);
            return input;
        };
        return { bloc: bloc, min: creer(libelleMin, valeurMin), max: creer(libelleMax, valeurMax) };
    }

    function piedMenu(corps, libelleOk, effacer, ok) {
        var pied = element('div', 'si-ft-pied');
        var bEffacer = element('button', 'si-ft-bouton', 'Effacer');
        bEffacer.type = 'button';
        bEffacer.addEventListener('click', effacer);
        var bOk = element('button', 'si-ft-bouton si-ft-bouton-principal', libelleOk);
        bOk.type = 'button';
        bOk.addEventListener('click', ok);
        pied.appendChild(bEffacer);
        pied.appendChild(bOk);
        menu.appendChild(pied);
    }

    /* Menu d'une colonne filtrée dans la page. Rend le champ à focaliser. */
    function remplirPage(t, c, corps) {
        relire(t);
        var libelles = libellesTri(c.type);
        var tris = section(corps, 'si-ft-tris');
        [1, -1].forEach(function (sens, i) {
            var actif = t.tri && t.tri.c === c && t.tri.sens === sens;
            tris.appendChild(boutonTri(libelles[i], sens, actif, function () {
                // Un second clic sur le même tri rend l'ordre d'origine.
                t.tri = actif ? null : { c: c, sens: sens };
                appliquer(t);
                fermer(true);
            }));
        });

        // Valeurs présentes dans les lignes que les autres colonnes laissent passer.
        var nombres = new Map();
        t.lignes.forEach(function (l) {
            if (cacheeAilleurs(l.tr) || !t.colonnes.every(function (o) { return o === c || passe(o, l); })) {
                return;
            }
            var v = l.valeurs[c.index];
            nombres.set(v, (nombres.get(v) || 0) + 1);
        });
        var valeurs = Array.from(nombres.keys()).sort(function (a, b) {
            var ka = cle(c.type, a);
            var kb = cle(c.type, b);
            if (ka === null || kb === null) {
                return ka === kb ? 0 : (ka === null ? 1 : -1);
            }
            return comparerCles(c.type, ka, kb);
        });
        var coche = new Map();
        valeurs.forEach(function (v) {
            coche.set(v, !c.filtre || (c.filtre.inclure ? c.filtre.inclure.has(v) : !c.filtre.exclure.has(v)));
        });

        var plage = null;
        if (c.type === 'nombre' || c.type === 'date') {
            var sPlage = section(corps);
            sPlage.appendChild(element('div', 'si-ft-titre', c.type === 'date' ? 'Période' : 'Entre'));
            plage = c.type === 'date'
                ? champPlage(c, 'Du', 'Au', c.minTexte, c.maxTexte)
                : champPlage(c, 'Minimum', 'Maximum', c.minTexte, c.maxTexte);
            sPlage.appendChild(plage.bloc);
            var minuterie = null;
            var surPlage = function () {
                clearTimeout(minuterie);
                minuterie = setTimeout(function () {
                    reglerPlage(c, plage.min.value, plage.max.value);
                    appliquer(t);
                }, 250);
            };
            plage.min.addEventListener('input', surPlage);
            plage.max.addEventListener('input', surPlage);
            [plage.min, plage.max].forEach(function (input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(minuterie);
                        reglerPlage(c, plage.min.value, plage.max.value);
                        appliquer(t);
                        fermer(true);
                    }
                });
            });
        }

        var sValeurs = section(corps, 'si-ft-valeurs');
        var recherche = element('input', 'si-ft-champ si-ft-recherche');
        recherche.type = 'search';
        recherche.placeholder = 'Rechercher…';
        recherche.setAttribute('aria-label', 'Rechercher dans les valeurs de « ' + c.libelle + ' »');
        recherche.autocomplete = 'off';
        sValeurs.appendChild(recherche);
        var liste = element('div', 'si-ft-liste');
        liste.setAttribute('role', 'group');
        liste.setAttribute('aria-label', 'Valeurs de « ' + c.libelle + ' »');
        sValeurs.appendChild(liste);

        var affichees = [];
        var tout = null;

        var enregistrer = function () {
            var oui = [];
            var non = [];
            valeurs.forEach(function (v) { (coche.get(v) ? oui : non).push(v); });
            if (!non.length) {
                c.filtre = null;
            } else if (oui.length <= non.length) {
                c.filtre = { inclure: new Set(oui) };
            } else {
                c.filtre = { exclure: new Set(non) };
            }
            appliquer(t);
        };

        var majTout = function () {
            if (!tout) {
                return;
            }
            var n = affichees.filter(function (v) { return coche.get(v); }).length;
            tout.checked = n === affichees.length && n > 0;
            tout.indeterminate = n > 0 && n < affichees.length;
        };

        var dessiner = function () {
            var mot = normaliser(recherche.value).toLowerCase();
            affichees = mot === '' ? valeurs : valeurs.filter(function (v) { return v.toLowerCase().indexOf(mot) >= 0; });
            liste.innerHTML = '';
            if (!affichees.length) {
                liste.appendChild(element('p', 'si-ft-aucune', 'Aucune valeur ne correspond.'));
                tout = null;
                return;
            }
            var ligneTout = element('label', 'si-ft-option si-ft-option-tout');
            tout = element('input');
            tout.type = 'checkbox';
            tout.addEventListener('change', function () {
                affichees.forEach(function (v) { coche.set(v, tout.checked); });
                dessiner();
                enregistrer();
            });
            ligneTout.appendChild(tout);
            ligneTout.appendChild(element('span', 'si-ft-option-texte', mot === '' ? 'Tout sélectionner' : 'Tous les résultats'));
            liste.appendChild(ligneTout);
            affichees.slice(0, MAX_VALEURS).forEach(function (v) {
                var option = element('label', 'si-ft-option');
                var caseV = element('input');
                caseV.type = 'checkbox';
                caseV.checked = coche.get(v);
                caseV.addEventListener('change', function () {
                    coche.set(v, caseV.checked);
                    majTout();
                    enregistrer();
                });
                option.appendChild(caseV);
                option.appendChild(element('span', 'si-ft-option-texte' + (v === VIDE ? ' si-ft-option-vide' : ''), v));
                option.appendChild(element('span', 'si-ft-nb', nombres.get(v).toLocaleString('fr-FR')));
                liste.appendChild(option);
            });
            if (affichees.length > MAX_VALEURS) {
                liste.appendChild(element('p', 'si-ft-aucune', (affichees.length - MAX_VALEURS).toLocaleString('fr-FR') + ' autres valeurs : précisez la recherche.'));
            }
            majTout();
        };
        recherche.addEventListener('input', dessiner);
        // Entrée : ne garder que les valeurs trouvées, comme dans Excel.
        recherche.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            if (normaliser(recherche.value) !== '' && affichees.length) {
                valeurs.forEach(function (v) { coche.set(v, affichees.indexOf(v) >= 0); });
                enregistrer();
            }
            fermer(true);
        });
        dessiner();

        var pagine = t.table.closest('.card, section, .content-body');
        pagine = pagine && pagine.querySelector('.pagination, .si-cl-pages, nav[aria-label="Pages"]');
        corps.appendChild(element('p', 'si-ft-note', pagine
            ? 'Agit seulement sur les ' + pluriel(t.lignes.length, 'ligne') + ' de cette page.'
            : 'Agit sur les ' + pluriel(t.lignes.length, 'ligne') + ' affichées.'));

        piedMenu(corps, 'OK', function () {
            c.filtre = null;
            reglerPlage(c, '', '');
            appliquer(t);
            fermer(true);
        }, function () {
            fermer(true);
        });

        return recherche;
    }

    /* Menu d'une colonne filtrée par le serveur : le choix recharge la liste. */
    function remplirServeur(t, c, corps) {
        var s = c.serveur;
        var parametres = new URLSearchParams(window.location.search);
        var aller = function (modifier) {
            var url = new URL(window.location.href);
            modifier(url.searchParams);
            url.searchParams.delete('page');
            window.location.assign(url.toString());
        };
        var regler = function (p, valeur) {
            return function (params) {
                if (normaliser(valeur) === '') {
                    params.delete(p);
                } else {
                    params.set(p, normaliser(valeur));
                }
            };
        };
        var focus = null;

        if (s.triAsc || s.triDesc) {
            var libelles = libellesTri(c.type);
            var tris = section(corps, 'si-ft-tris');
            [[1, s.triAsc], [-1, s.triDesc]].forEach(function (paire, i) {
                if (!paire[1]) {
                    return;
                }
                var actif = s.tri === paire[0];
                tris.appendChild(boutonTri(libelles[i], paire[0], actif, function () {
                    aller(regler(s.triParam, actif ? '' : paire[1]));
                }));
            });
        }

        var plage = null;
        if (s.min || s.max) {
            var sPlage = section(corps);
            sPlage.appendChild(element('div', 'si-ft-titre', c.type === 'date' ? 'Période' : 'Entre'));
            plage = c.type === 'date'
                ? champPlage(c, 'Du', 'Au', parametres.get(s.min), parametres.get(s.max))
                : champPlage(c, 'Minimum', 'Maximum', parametres.get(s.min), parametres.get(s.max));
            sPlage.appendChild(plage.bloc);
            focus = plage.min;
        }

        var recherche = null;
        if (s.recherche) {
            var sRecherche = section(corps);
            recherche = element('input', 'si-ft-champ si-ft-recherche');
            recherche.type = 'search';
            recherche.placeholder = 'Rechercher…';
            recherche.setAttribute('aria-label', 'Rechercher dans « ' + c.libelle + ' »');
            recherche.value = parametres.get(s.recherche) || '';
            sRecherche.appendChild(recherche);
            focus = recherche;
        }

        var appliquerSaisie = function () {
            aller(function (params) {
                if (recherche) {
                    regler(s.recherche, recherche.value)(params);
                }
                if (plage) {
                    regler(s.min, plage.min.value)(params);
                    regler(s.max, plage.max.value)(params);
                }
            });
        };
        [recherche, plage && plage.min, plage && plage.max].forEach(function (input) {
            if (input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        appliquerSaisie();
                    }
                });
            }
        });

        if (s.param && s.choix.length) {
            var sChoix = section(corps, 'si-ft-valeurs');
            var liste = element('div', 'si-ft-liste');
            liste.setAttribute('role', 'radiogroup');
            liste.setAttribute('aria-label', 'Valeurs de « ' + c.libelle + ' »');
            var actuel = parametres.get(s.param) || '';
            var nom = 'si-ft-' + c.index;
            [['', 'Tous']].concat(s.choix).forEach(function (paire) {
                var option = element('label', 'si-ft-option' + (paire[0] === '' ? ' si-ft-option-tout' : ''));
                var radio = element('input');
                radio.type = 'radio';
                radio.name = nom;
                radio.checked = actuel === paire[0];
                radio.addEventListener('change', function () {
                    aller(regler(s.param, paire[0]));
                });
                option.appendChild(radio);
                option.appendChild(element('span', 'si-ft-option-texte', paire[1]));
                liste.appendChild(option);
            });
            sChoix.appendChild(liste);
            focus = focus || liste.querySelector('input:checked') || liste.querySelector('input');
        }

        corps.appendChild(element('p', 'si-ft-note', 'Filtre toute la liste, pas seulement cette page.'));

        piedMenu(corps, recherche || plage ? 'Appliquer' : 'OK', function () {
            aller(function (params) {
                s.parametres.forEach(function (p) { params.delete(p); });
            });
        }, function () {
            if (recherche || plage) {
                appliquerSaisie();
            } else {
                fermer(true);
            }
        });

        return focus;
    }

    /* ---------- Démarrage ---------- */

    document.addEventListener('pointerdown', function (e) {
        if (ouvert && !menu.contains(e.target) && !ouvert.c.bouton.contains(e.target)) {
            fermer(false);
        }
    });
    window.addEventListener('resize', placer);
    window.addEventListener('scroll', function (e) {
        if (ouvert && !(e.target instanceof Node && menu.contains(e.target))) {
            placer();
        }
    }, true);

    function demarrer() {
        oublierAutresComptes();
        Array.prototype.forEach.call(document.querySelectorAll('table'), function (table) {
            var examen = examiner(table);
            if (examen) {
                var t = equiper(table, examen, tableaux.length);
                if (t) {
                    tableaux.push(t);
                }
            }
        });
    }

    window.SiFiltresTableaux = { demarrer: demarrer, valeurCellule: valeurCellule, enNombre: enNombre, enDate: enDate };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
