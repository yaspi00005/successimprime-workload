/*
 * Pièces au m² (docs/saisie-devis-commandes.md) : combien de pièces d'une
 * taille donnée tiennent sur 1 m × 1 m, avec un espacement en largeur et en
 * hauteur (réglage, 1 mm par défaut), et la quantité en pièces ou en m².
 *
 * Même calcul que App\Service\PiecesParMetreCarre, qui fait foi : la page
 * montre le résultat tout de suite, le serveur le refait à l'envoi.
 *
 * Deux usages :
 * - un bloc [data-pieces-m2] (site, espace client) relié aux champs de la
 *   page par des sélecteurs : data-largeur, data-hauteur, data-quantite,
 *   data-unite-cote (cm par défaut), data-espacement. Il contient les
 *   boutons radio [data-pm2-unite] (piece / m2), le champ [data-pm2-m2]
 *   dans [data-pm2-zone-m2] et la phrase [data-pm2-resultat]. Une page qui
 *   montre ou cache le bloc appelle ensuite SiPiecesM2.rafraichir(bloc).
 * - une ligne de devis ou de commande de l'ERP (bloc .js-pm2-ligne) : le
 *   calcul du formulaire appelle SiPiecesM2.ligne(detail, infos), qui
 *   renvoie la surface facturée (ou null : calcul au m² habituel).
 *
 * Aucun prix ici.
 */
(function () {
    'use strict';

    const COTE_MM = 1000;
    const EPSILON = 1e-9;

    const entier = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0});
    const decimal = new Intl.NumberFormat('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    /* ---------- Calcul (comme PiecesParMetreCarre) ---------- */

    function parCote(plaque, cote, espacement) {
        return Math.floor((plaque + espacement) / (cote + espacement) + EPSILON);
    }

    /**
     * Pièces de largeur × hauteur (mm) sur 1 m × 1 m, espacées de e mm ;
     * la pièce tournée d'un quart de tour est essayée aussi.
     */
    function calculer(largeurMm, hauteurMm, espacementMm) {
        const e = Math.max(0, Number(espacementMm) || 0);
        if (!(largeurMm > 0) || !(hauteurMm > 0)) {
            return null;
        }
        const droite = [parCote(COTE_MM, largeurMm, e), parCote(COTE_MM, hauteurMm, e)];
        const tournee = [parCote(COTE_MM, hauteurMm, e), parCote(COTE_MM, largeurMm, e)];
        const tourner = tournee[0] * tournee[1] > droite[0] * droite[1];
        const retenu = tourner ? tournee : droite;
        const parM2 = retenu[0] * retenu[1];

        return {parM2: parM2, colonnes: retenu[0], rangees: retenu[1], tournee: tourner, tropGrande: parM2 === 0, espacement: e};
    }

    /** Surface facturée : pièces ÷ pièces au m², au centième de m² supérieur. */
    function surface(pieces, parM2) {
        return Math.ceil(Math.max(0, pieces) / parM2 * 100 - EPSILON) / 100;
    }

    /** Pièces pour une surface : m² × pièces au m², arrondi au-dessous. */
    function pieces(metresCarres, parM2) {
        return metresCarres > 0 ? Math.floor(metresCarres * parM2 + EPSILON) : 0;
    }

    function espacementTexte(e) {
        return String(Math.round(e * 100) / 100).replace('.', ',');
    }

    /** « 361 pièces au m² (19 × 19, espacement 1 mm) » */
    function libelle(c) {
        if (c.tropGrande) {
            return 'pièce plus grande que 1 m';
        }

        return entier.format(c.parM2) + ' pièce' + (c.parM2 > 1 ? 's' : '') + ' au m² ('
            + c.colonnes + ' × ' + c.rangees + ', espacement ' + espacementTexte(c.espacement) + ' mm)';
    }

    function nombrePieces(n) {
        return entier.format(n) + ' pièce' + (n > 1 ? 's' : '');
    }

    function m2Texte(valeur) {
        return decimal.format(valeur) + ' m²';
    }

    function lireNombre(champ) {
        if (!champ) {
            return 0;
        }
        const valeur = parseFloat(String(champ.value || '').replace(/[\s  ]/g, '').replace(',', '.'));

        return Number.isFinite(valeur) ? valeur : 0;
    }

    /** Côté en mm, au dixième (comme le serveur). */
    function enMm(valeur, unite) {
        const facteur = unite === 'mm' ? 1 : (unite === 'm' ? 1000 : 10);

        return Math.round(valeur * facteur * 10) / 10;
    }

    /** Change la valeur d'un champ et prévient les autres scripts de la page. */
    function ecrire(champ, valeur) {
        if (!champ || champ.value === String(valeur)) {
            return;
        }
        champ.value = String(valeur);
        champ.dispatchEvent(new Event('input', {bubbles: true}));
    }

    /**
     * Quantité calculée depuis la surface : en lecture seule, et marquée.
     * Seul un verrou posé ici est retiré (une ligne verrouillée par la page
     * le reste).
     */
    function verrouiller(champ, oui) {
        if (!champ) {
            return;
        }
        if (oui) {
            if (!champ.readOnly) {
                champ.readOnly = true;
                champ.dataset.pm2Verrou = '1';
            }
        } else if (champ.dataset.pm2Verrou === '1') {
            champ.readOnly = false;
            delete champ.dataset.pm2Verrou;
        }
        champ.classList.toggle('si-pm2-calculee', oui);
    }

    /* ---------- Bloc [data-pieces-m2] (site, espace client) ---------- */

    function champDe(bloc, nom) {
        const selecteur = bloc.dataset[nom];

        return selecteur ? document.querySelector(selecteur) : null;
    }

    function uniteChoisie(bloc) {
        const coche = bloc.querySelector('[data-pm2-unite]:checked');

        return coche ? coche.value : 'piece';
    }

    function rafraichir(bloc) {
        const quantite = champDe(bloc, 'quantite');
        const resultat = bloc.querySelector('[data-pm2-resultat]');
        const zoneM2 = bloc.querySelector('[data-pm2-zone-m2]');
        const champM2 = bloc.querySelector('[data-pm2-m2]');
        const radioM2 = bloc.querySelector('[data-pm2-unite][value="m2"]');
        const radioPiece = bloc.querySelector('[data-pm2-unite][value="piece"]');
        const dire = function (texte) {
            if (resultat) {
                resultat.textContent = texte;
            }
        };

        // Bloc caché (produit sans m²) : la quantité redevient libre.
        if (bloc.hidden) {
            verrouiller(quantite, false);

            return;
        }

        const unite = bloc.dataset.uniteCote || 'cm';
        const c = calculer(enMm(lireNombre(champDe(bloc, 'largeur')), unite), enMm(lireNombre(champDe(bloc, 'hauteur')), unite), parseFloat(bloc.dataset.espacement || '1'));

        if (radioM2) {
            radioM2.disabled = Boolean(c && c.tropGrande);
            if (radioM2.disabled && radioM2.checked && radioPiece) {
                radioPiece.checked = true;
            }
        }
        const enM2 = uniteChoisie(bloc) === 'm2';
        if (zoneM2) {
            zoneM2.hidden = !enM2;
        }
        verrouiller(quantite, enM2 && Boolean(c));

        if (!c) {
            dire('Indiquez la largeur et la hauteur : nous comptons les pièces qui tiennent sur 1 m².');

            return;
        }
        if (c.tropGrande) {
            dire('Pièce plus grande que 1 m : quantité en pièces seulement.');

            return;
        }

        if (enM2) {
            const m2 = lireNombre(champM2);
            if (!(m2 > 0)) {
                ecrire(quantite, '');
                dire('Indiquez la surface en m² : ' + libelle(c) + '.');

                return;
            }
            const n = pieces(m2, c.parM2);
            ecrire(quantite, n >= 1 ? n : '');
            dire(n >= 1
                ? m2Texte(m2) + ' = ' + nombrePieces(n) + ' · ' + libelle(c)
                : 'Moins d\'une pièce sur ' + m2Texte(m2) + ' : augmentez la surface.');

            return;
        }

        const q = Math.floor(lireNombre(quantite));
        dire(q >= 1
            ? nombrePieces(q) + ' = ' + m2Texte(surface(q, c.parM2)) + ' · ' + libelle(c)
            : libelle(c).charAt(0).toUpperCase() + libelle(c).slice(1) + '.');
    }

    function brancher(bloc) {
        if (!bloc || bloc.dataset.pm2Branche === '1') {
            return;
        }
        bloc.dataset.pm2Branche = '1';
        const relancer = function () {
            rafraichir(bloc);
        };
        ['largeur', 'hauteur', 'quantite'].forEach(function (nom) {
            const champ = champDe(bloc, nom);
            if (champ) {
                champ.addEventListener('input', relancer);
            }
        });
        bloc.addEventListener('input', relancer);
        bloc.addEventListener('change', relancer);
        relancer();
    }

    /* ---------- Ligne de devis ou de commande (ERP) ---------- */

    function brancherLigne(bloc, detail) {
        if (bloc.dataset.pm2Branche === '1') {
            return;
        }
        bloc.dataset.pm2Branche = '1';

        const choix = bloc.querySelector('.js-pm2-choix');
        const caseCochee = bloc.querySelector('.js-pm2-case');
        const quantite = detail.querySelector('[data-detail-field="quantite"]');
        const recalculer = function () {
            // Le calcul du formulaire écoute la quantité : il rappelle SiPiecesM2.ligne().
            if (quantite) {
                quantite.dispatchEvent(new Event('input', {bubbles: true}));
            }
        };

        if (caseCochee && choix) {
            caseCochee.checked = choix.value === '1';
            caseCochee.addEventListener('change', function () {
                choix.value = caseCochee.checked ? '1' : '0';
                if (!caseCochee.checked) {
                    bloc.dataset.unite = 'piece';
                }
                recalculer();
            });
        }

        bloc.querySelectorAll('.js-pm2-unite').forEach(function (bouton) {
            bouton.addEventListener('click', function () {
                bloc.dataset.unite = bouton.dataset.unite;
                recalculer();
                if (bouton.dataset.unite === 'm2') {
                    const champM2 = bloc.querySelector('.js-pm2-m2');
                    if (champM2) {
                        champM2.focus();
                    }
                }
            });
        });

        const champM2 = bloc.querySelector('.js-pm2-m2');
        if (champM2) {
            champM2.addEventListener('input', function () {
                const c = bloc.pm2Calcul;
                const n = c && !c.tropGrande ? pieces(lireNombre(champM2), c.parM2) : 0;
                if (n >= 1 && quantite && quantite.value !== String(n)) {
                    ecrire(quantite, n);
                } else {
                    // Même nombre de pièces (ou aucune) : la phrase se refait quand même.
                    recalculer();
                }
            });
        }
    }

    /**
     * Appelé par le calcul de la ligne. infos : {mode, typeLigne, largeur,
     * longueur (en mètres), quantite}. Renvoie la surface facturée en pièces
     * au m², ou null (calcul au m² habituel).
     */
    function ligne(detail, infos) {
        const bloc = detail ? detail.querySelector('.js-pm2-ligne') : null;
        if (!bloc) {
            return null;
        }
        brancherLigne(bloc, detail);

        const choix = bloc.querySelector('.js-pm2-choix');
        const caseCochee = bloc.querySelector('.js-pm2-case');
        const info = bloc.querySelector('.js-pm2-info');
        const saisie = bloc.querySelector('.js-pm2-saisie');
        const zoneM2 = bloc.querySelector('.js-pm2-zone-m2');
        const champM2 = bloc.querySelector('.js-pm2-m2');
        const resultat = bloc.querySelector('.js-pm2-resultat');
        const quantite = detail.querySelector('[data-detail-field="quantite"]');
        const ecrireTexte = function (element, texte) {
            if (element) {
                element.textContent = texte;
            }
        };
        const enPieces = function (actif) {
            if (saisie) {
                saisie.hidden = !actif;
            }
            verrouiller(quantite, actif && bloc.dataset.unite === 'm2');
        };

        delete bloc.dataset.surfaceFacturee;
        const applicable = infos.typeLigne === 'produit' && infos.mode === 'metre_carre';
        // Ligne d'une commande verrouillée (production commencée) : on montre, on ne change rien.
        const figee = detail.matches('[data-detail-verrouille="1"]') || Boolean(detail.querySelector('[data-detail-verrouille="1"]'));
        bloc.querySelectorAll('.js-pm2-unite, .js-pm2-m2').forEach(function (el) {
            el.disabled = figee;
        });
        bloc.hidden = !applicable;
        bloc.pm2Calcul = null;
        if (!applicable) {
            enPieces(false);

            return null;
        }

        const c = calculer(enMm(infos.largeur, 'm'), enMm(infos.longueur, 'm'), parseFloat(bloc.dataset.espacement || '1'));
        bloc.pm2Calcul = c;
        if (caseCochee) {
            caseCochee.disabled = figee || !c || c.tropGrande;
        }
        if (!c || c.tropGrande) {
            enPieces(false);
            ecrireTexte(info, c ? 'Pièce plus grande que 1 m : calcul au m² habituel.' : 'Indiquez la largeur et la longueur pour compter les pièces au m².');
            ecrireTexte(resultat, '');

            return null;
        }

        ecrireTexte(info, libelle(c));
        const q = Math.max(1, Math.floor(infos.quantite || 1));
        const facturee = surface(q, c.parM2);

        if (!choix || choix.value !== '1') {
            enPieces(false);
            const habituelle = Math.round(infos.largeur * infos.longueur * q * 100) / 100;
            ecrireTexte(resultat, facturee > habituelle
                ? 'Calcul habituel : ' + m2Texte(habituelle) + '. Cochez pour facturer ' + m2Texte(facturee) + ', espacement et chutes compris.'
                : '');

            return null;
        }

        const unite = bloc.dataset.unite === 'm2' ? 'm2' : 'piece';
        bloc.querySelectorAll('.js-pm2-unite').forEach(function (bouton) {
            const actif = bouton.dataset.unite === unite;
            bouton.classList.toggle('active', actif);
            bouton.setAttribute('aria-pressed', actif ? 'true' : 'false');
        });
        if (zoneM2) {
            zoneM2.hidden = unite !== 'm2';
        }
        enPieces(true);
        // Lue par le récapitulatif de la ligne (saisie-commande.js).
        bloc.dataset.surfaceFacturee = String(facturee);

        if (unite === 'm2' && champM2) {
            const m2 = lireNombre(champM2);
            const n = pieces(m2, c.parM2);
            if (m2 > 0 && n < 1) {
                ecrireTexte(resultat, 'Moins d\'une pièce sur ' + m2Texte(m2) + ' : augmentez la surface.');

                return facturee;
            }
            if (n >= 1 && n !== q) {
                // Taille changée : les pièces suivent la surface (après ce calcul-ci).
                window.setTimeout(function () {
                    ecrire(quantite, n);
                }, 0);
            }
            if (m2 > 0) {
                ecrireTexte(resultat, m2Texte(m2) + ' = ' + nombrePieces(n) + ', facturées ' + m2Texte(surface(n, c.parM2)) + '.');

                return facturee;
            }
        }

        ecrireTexte(resultat, nombrePieces(q) + ' = ' + m2Texte(facturee) + ' facturés.');

        return facturee;
    }

    function demarrer() {
        document.querySelectorAll('[data-pieces-m2]').forEach(brancher);
    }

    window.SiPiecesM2 = {
        calculer: calculer,
        surface: surface,
        pieces: pieces,
        libelle: libelle,
        brancher: brancher,
        rafraichir: rafraichir,
        ligne: ligne,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', demarrer);
    } else {
        demarrer();
    }
})();
