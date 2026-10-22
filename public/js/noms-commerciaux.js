/*
 * Réglages › Noms commerciaux (docs/noms-commerciaux.md).
 * - Chercher : garde les lignes dont la désignation ou le nom commercial
 *   contient le texte, sans tenir compte des accents ni des majuscules.
 * - Aperçu : ce que verra le client, mis à jour pendant la saisie.
 * - Enregistrer : seuls les noms modifiés partent (les autres champs sont
 *   désactivés juste avant l'envoi), pour ne pas dépasser le nombre de
 *   champs qu'accepte le serveur avec un grand catalogue.
 * Sans ce script, la page marche : tout part, seuls les changements comptent.
 */
(function () {
    'use strict';

    var formulaire = document.getElementById('si-nc');
    if (!formulaire) {
        return;
    }
    var recherche = document.getElementById('si-nc-recherche');
    var etat = document.getElementById('si-nc-etat');
    var champs = Array.prototype.slice.call(formulaire.querySelectorAll('input[name^="noms["]'));
    var envoi = false;

    // Même clé que App\Service\Catalogue\NomCommercial::cle : minuscules, sans accents, ni ponctuation, ni pluriel.
    function cle(texte) {
        return String(texte || '').toLowerCase()
            .replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/²/g, '2')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, ' ').trim()
            // Au singulier, comme NomCommercial::singulier : « autocollants » trouve « autocollant ».
            .replace(/([a-z]{3})[sx](?=\s|$)/g, '$1');
    }

    function propre(texte) {
        return String(texte || '').replace(/\s+/g, ' ').trim();
    }

    function modifie(champ) {
        return propre(champ.value) !== propre(champ.dataset.initial);
    }

    function apercu(champ) {
        var zone = champ.parentNode.querySelector('.si-nc-apercu');
        if (!zone) {
            return;
        }
        var nom = propre(champ.value);
        var technique = champ.dataset.technique || '';
        zone.textContent = '';
        if (nom === '' || cle(nom) === cle(technique)) {
            zone.textContent = technique;
            return;
        }
        var principal = document.createElement('span');
        principal.className = 'nc-nom';
        principal.textContent = nom;
        var petit = document.createElement('small');
        petit.className = 'nc-technique';
        petit.textContent = technique;
        zone.appendChild(principal);
        zone.appendChild(document.createTextNode(' '));
        zone.appendChild(petit);
    }

    function compter() {
        var changes = champs.filter(modifie).length;
        etat.textContent = changes === 0
            ? 'Aucun changement pour l\'instant.'
            : (changes === 1 ? '1 nom à enregistrer.' : changes + ' noms à enregistrer.');
        etat.classList.toggle('si-nc-a-enregistrer', changes > 0);
        // La barre « Enregistrer » flotte en bas de l'écran dès qu'un nom a changé.
        formulaire.classList.toggle('si-nc-modifie', changes > 0);
        formulaire.querySelectorAll('[data-rubrique]').forEach(function (rubrique) {
            var siens = Array.prototype.slice.call(rubrique.querySelectorAll('input[name^="noms["]'));
            var compte = formulaire.querySelector('[data-compte="' + rubrique.dataset.rubrique + '"]');
            if (compte) {
                compte.textContent = siens.filter(function (c) { return propre(c.value) !== ''; }).length + '/' + siens.length;
            }
        });

        return changes;
    }

    function filtrer() {
        var mot = cle(recherche.value);
        formulaire.querySelectorAll('[data-rubrique]').forEach(function (rubrique) {
            var visibles = 0;
            rubrique.querySelectorAll('.si-nc-ligne').forEach(function (ligne) {
                var champ = ligne.querySelector('input');
                var texte = ligne.dataset.recherche + ' ' + cle(champ ? champ.value : '');
                var garde = mot === '' || texte.indexOf(mot) !== -1;
                ligne.hidden = !garde;
                visibles += garde ? 1 : 0;
            });
            var vide = rubrique.querySelector('.si-nc-vide');
            if (vide) {
                vide.hidden = visibles > 0;
            }
            var tete = rubrique.querySelector('.si-nc-tete');
            if (tete) {
                tete.hidden = visibles === 0;
            }
        });
    }

    champs.forEach(function (champ) {
        champ.addEventListener('input', function () {
            apercu(champ);
            compter();
        });
    });
    recherche.addEventListener('input', filtrer);
    recherche.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });

    formulaire.addEventListener('submit', function () {
        envoi = true;
        champs.forEach(function (champ) {
            champ.disabled = !modifie(champ);
        });
    });
    // Retour arrière vers la page : les champs désactivés pour l'envoi redeviennent saisissables.
    window.addEventListener('pageshow', function () {
        envoi = false;
        champs.forEach(function (champ) { champ.disabled = false; });
    });
    window.addEventListener('beforeunload', function (e) {
        if (!envoi && champs.some(modifie)) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    compter();
}());
