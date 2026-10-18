/*
 * Confirmation avant une suppression ou une action sensible (docs/corbeille.md).
 * Une seule fenêtre dans la page pour toute l'application (ERP, espace
 * client, tablette) : jamais la boîte native du navigateur.
 *
 * Déclaratif, sur un <form> ou sur ce qu'on clique (bouton, lien) :
 *   data-confirmer="Supprimer le devis DEV-2026-0042 ?"   question (obligatoire)
 *   data-confirmer-detail="Il restera 30 jours dans la corbeille."
 *   data-confirmer-bouton="Supprimer"                      (défaut : « Confirmer »)
 *   data-confirmer-danger                                  bouton rouge
 * Côté Twig, confirmer_suppression('le devis ' ~ devis.numero) écrit les
 * quatre d'un coup pour une suppression.
 *
 * Le clic ou l'envoi est arrêté avant tout autre script (phase de capture),
 * puis rejoué tel quel après « Supprimer » : les suppressions en AJAX
 * gardent leur propre code, il suffit de mettre l'attribut sur leur bouton.
 *
 * En JavaScript : SiConfirmation.demander({message, detail, bouton, danger})
 * rend une promesse (true si confirmé, false si annulé).
 *
 * Clavier : le focus reste dans la fenêtre (Tab, Maj+Tab), Échap annule,
 * « Annuler » a le focus à l'ouverture. Sur téléphone, la fenêtre monte du
 * bas avec de grands boutons.
 */
(function () {
    'use strict';

    if (window.SiConfirmation) {
        return;
    }

    var script = document.currentScript;
    if (script && script.dataset.css && !document.querySelector('link[data-si-confirmation]')) {
        var feuille = document.createElement('link');
        feuille.rel = 'stylesheet';
        feuille.href = script.dataset.css;
        feuille.setAttribute('data-si-confirmation', '');
        document.head.appendChild(feuille);
    }

    var CONFIRME = 'siConfirme';
    var fenetre = null;
    var enCours = null;

    function construire() {
        var fond = document.createElement('div');
        fond.className = 'si-confirmation';
        fond.hidden = true;
        fond.innerHTML =
            '<div class="si-confirmation__boite" role="alertdialog" aria-modal="true" aria-labelledby="si-confirmation-question" aria-describedby="si-confirmation-detail" tabindex="-1">' +
            '<p class="si-confirmation__question" id="si-confirmation-question"></p>' +
            '<p class="si-confirmation__detail" id="si-confirmation-detail"></p>' +
            '<div class="si-confirmation__actions">' +
            '<button type="button" class="si-confirmation__annuler">Annuler</button>' +
            '<button type="button" class="si-confirmation__ok">Confirmer</button>' +
            '</div></div>';
        document.body.appendChild(fond);

        var f = {
            fond: fond,
            boite: fond.querySelector('.si-confirmation__boite'),
            question: fond.querySelector('.si-confirmation__question'),
            detail: fond.querySelector('.si-confirmation__detail'),
            annuler: fond.querySelector('.si-confirmation__annuler'),
            ok: fond.querySelector('.si-confirmation__ok')
        };
        f.annuler.addEventListener('click', function () { fermer(false); });
        f.ok.addEventListener('click', function () { fermer(true); });
        // Un clic sur le fond annule, pas un clic dans la fenêtre.
        fond.addEventListener('mousedown', function (e) {
            if (e.target === fond) { fermer(false); }
        });
        fond.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                e.stopPropagation();
                fermer(false);
                return;
            }
            if (e.key === 'Tab') {
                // Seulement deux boutons : le focus passe de l'un à l'autre.
                var premier = f.annuler;
                var dernier = f.ok;
                if (e.shiftKey && (document.activeElement === premier || document.activeElement === f.boite)) {
                    e.preventDefault();
                    dernier.focus();
                } else if (!e.shiftKey && document.activeElement === dernier) {
                    e.preventDefault();
                    premier.focus();
                }
            }
        });
        // Le focus ne sort pas de la fenêtre (clic ailleurs, lecteur d'écran).
        document.addEventListener('focusin', function (e) {
            if (enCours && !f.boite.contains(e.target)) {
                f.annuler.focus();
            }
        });

        return f;
    }

    function fermer(reponse) {
        if (!enCours) {
            return;
        }
        var demande = enCours;
        enCours = null;
        fenetre.fond.hidden = true;
        document.documentElement.classList.remove('si-confirmation-ouverte');
        if (demande.retour && document.contains(demande.retour)) {
            try { demande.retour.focus({ preventScroll: true }); } catch (e) { /* élément non focalisable */ }
        }
        demande.resoudre(reponse);
    }

    /**
     * @param {{message: string, detail?: string, bouton?: string, danger?: boolean}} options
     * @returns {Promise<boolean>}
     */
    function demander(options) {
        options = options || {};
        fenetre = fenetre || construire();
        if (enCours) {
            fermer(false);
        }
        // Dans une fenêtre Bootstrap ouverte : la nôtre va dedans, sinon son piège à focus la reprendrait.
        var conteneur = document.querySelector('.modal.show') || document.body;
        if (fenetre.fond.parentNode !== conteneur) {
            conteneur.appendChild(fenetre.fond);
        }

        fenetre.question.textContent = options.message || 'Confirmer ?';
        fenetre.detail.textContent = options.detail || '';
        fenetre.detail.hidden = !options.detail;
        fenetre.boite.setAttribute('aria-describedby', options.detail ? 'si-confirmation-detail' : 'si-confirmation-question');
        fenetre.ok.textContent = options.bouton || 'Confirmer';
        fenetre.ok.classList.toggle('si-confirmation__ok--danger', !!options.danger);

        return new Promise(function (resoudre) {
            enCours = { resoudre: resoudre, retour: document.activeElement };
            document.documentElement.classList.add('si-confirmation-ouverte');
            fenetre.fond.hidden = false;
            fenetre.annuler.focus();
        });
    }

    function options(el) {
        return {
            message: el.getAttribute('data-confirmer'),
            detail: el.getAttribute('data-confirmer-detail') || '',
            bouton: el.getAttribute('data-confirmer-bouton') || '',
            danger: el.hasAttribute('data-confirmer-danger')
        };
    }

    // Bouton ou lien à confirmer (y compris un bouton de formulaire ou un bouton AJAX).
    window.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-confirmer]:not(form)') : null;
        if (!el || el.disabled) {
            return;
        }
        if (el.dataset[CONFIRME]) {
            delete el.dataset[CONFIRME];
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        demander(options(el)).then(function (ok) {
            if (!ok) {
                return;
            }
            el.dataset[CONFIRME] = '1';
            // Un bouton qui envoie un formulaire confirmé lui-même : pas de seconde question.
            var form = el.form || (el.getAttribute('type') !== 'button' && el.closest('form'));
            if (form && form.hasAttribute('data-confirmer')) {
                form.dataset[CONFIRME] = '1';
            }
            el.click();
            // Clic rejoué sans envoi (élément retiré, champ obligatoire vide) : la prochaine fois, on redemande.
            delete el.dataset[CONFIRME];
            if (form) {
                delete form.dataset[CONFIRME];
            }
        });
    }, true);

    // Formulaire à confirmer : arrêté avant les scripts qui l'envoient en AJAX.
    window.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-confirmer')) {
            return;
        }
        if (form.dataset[CONFIRME]) {
            delete form.dataset[CONFIRME];
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        var envoyeur = e.submitter || null;
        demander(options(form)).then(function (ok) {
            if (!ok) {
                return;
            }
            form.dataset[CONFIRME] = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(envoyeur && envoyeur.form === form ? envoyeur : undefined);
                // Envoi refusé par le navigateur (champ obligatoire vide) : la prochaine fois, on redemande.
                delete form.dataset[CONFIRME];
            } else {
                // Vieux navigateur : le bouton choisi est recopié avant l'envoi.
                if (envoyeur && envoyeur.name) {
                    var champ = document.createElement('input');
                    champ.type = 'hidden';
                    champ.name = envoyeur.name;
                    champ.value = envoyeur.value;
                    form.appendChild(champ);
                }
                delete form.dataset[CONFIRME];
                form.submit();
            }
        });
    }, true);

    window.SiConfirmation = { demander: demander };
})();
