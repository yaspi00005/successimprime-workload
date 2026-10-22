/*
 * Recherche des listes du catalogue (supports, types d'impression) :
 * garde les lignes dont la désignation ou le nom commercial contient le
 * texte, sans tenir compte des accents ni des majuscules
 * (docs/noms-commerciaux.md). La case porte data-recherche-liste (le
 * sélecteur de la liste) ; chaque ligne porte data-recherche, la clé
 * calculée par le serveur (NomCommercial::cle).
 */
(function () {
    'use strict';

    // Même clé que App\Service\Catalogue\NomCommercial::cle (sans accents, ni ponctuation, ni pluriel).
    function cle(texte) {
        return String(texte || '').toLowerCase()
            .replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/²/g, '2')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, ' ').trim()
            // Au singulier, comme NomCommercial::singulier : « autocollants » trouve « autocollant ».
            .replace(/([a-z]{3})[sx](?=\s|$)/g, '$1');
    }

    document.querySelectorAll('input[data-recherche-liste]').forEach(function (champ) {
        var liste = document.querySelector(champ.dataset.rechercheListe);
        if (!liste) {
            return;
        }
        champ.addEventListener('input', function () {
            var mot = cle(champ.value);
            liste.querySelectorAll('[data-recherche]').forEach(function (ligne) {
                ligne.hidden = mot !== '' && ligne.dataset.recherche.indexOf(mot) === -1;
            });
        });
        champ.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    });
}());
