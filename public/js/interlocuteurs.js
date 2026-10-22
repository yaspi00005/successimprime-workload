/*
 * Saisie d'un devis ou d'une commande : propose les interlocuteurs du
 * client choisi (« À l'attention de », « Commandé par ») dans la liste du
 * champ. Le champ reste libre : on peut taper un autre nom.
 * Gabarit : templates/partials/_interlocuteur.html.twig.
 */
(function () {
    'use strict';

    function brancher(champ) {
        if (champ.dataset.branche) {
            return;
        }
        champ.dataset.branche = '1';
        var formulaire = champ.form || document;
        var select = formulaire.querySelector('.js-client-saisie');
        var liste = document.getElementById(champ.getAttribute('list'));
        if (!select || !liste) {
            return;
        }
        var demande = 0;

        function remplir() {
            var id = select.value;
            var numero = ++demande;
            liste.textContent = '';
            if (!/^\d+$/.test(id)) {
                return;
            }
            // Le code chiffré du client (data-code) dans l'URL : pas de numéro lisible (docs/ids-chiffres.md).
            var option = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            var code = option && option.value === id && option.dataset.code ? option.dataset.code : id;
            fetch(champ.dataset.url.replace('__id__', encodeURIComponent(code)), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) { return r.ok ? r.json() : {interlocuteurs: []}; })
                .then(function (donnees) {
                    if (numero !== demande) {
                        return;
                    }
                    (donnees.interlocuteurs || []).forEach(function (p) {
                        var option = document.createElement('option');
                        option.value = p.nom;
                        if (p.precision) {
                            option.label = p.nom + ' · ' + p.precision;
                        }
                        liste.appendChild(option);
                    });
                })
                .catch(function () { /* sans liste, le champ reste libre */ });
        }

        select.addEventListener('change', remplir);
        if (window.jQuery) {
            window.jQuery(select).on('change', remplir);
        }
        remplir();
    }

    function tout() {
        document.querySelectorAll('.js-interlocuteur').forEach(brancher);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tout);
    } else {
        tout();
    }
})();
