// Raccourci Ctrl+K (ou Cmd+K) : ouvre « Aller a… », sinon place le curseur
// dans la recherche globale.
document.addEventListener('keydown', function (evenement) {
    if ((evenement.ctrlKey || evenement.metaKey) && evenement.key.toLowerCase() === 'k') {
        if (AllerA.ouvrir()) {
            evenement.preventDefault();
            return;
        }
        var champ = document.querySelector('[data-raccourci-recherche]');
        if (champ) {
            evenement.preventDefault();
            champ.focus();
            champ.select();
        }
    }
});

// « + Nouveau > Client » arrive sur la liste des clients avec ?nouveau=1 :
// on ouvre directement la fenetre d'ajout.
function ouvrirAjoutDemande() {
    if (new URLSearchParams(window.location.search).get('nouveau') !== '1') {
        return;
    }
    var bouton = document.querySelector('[data-bs-target="#modalAjoutClient"]');
    // load et turbo:load peuvent arriver tous les deux sur la meme page.
    if (bouton && !bouton.dataset.ouvertAuto) {
        bouton.dataset.ouvertAuto = '1';
        bouton.click();
    }
}
window.addEventListener('load', ouvrirAjoutDemande);
document.addEventListener('turbo:load', ouvrirAjoutDemande);

// « Plus » de la barre mobile : ouvre le menu lateral comme le bouton hamburger.
document.addEventListener('click', function (evenement) {
    if (evenement.target.closest('[data-ouvrir-menu]')) {
        var hamburger = document.querySelector('.nav-control');
        if (hamburger) {
            hamburger.click();
        }
    }
});

// Une ligne de liste cliquable ouvre la fiche (data-lien sur le <tr>).
document.addEventListener('click', function (evenement) {
    var ligne = evenement.target.closest('tr[data-lien]');
    if (ligne && !evenement.target.closest('a, button, input')) {
        window.location.href = ligne.dataset.lien;
    }
});

// Formulaires sensibles (annuler, restaurer, livrer...) : confirmation avant envoi.
document.addEventListener('submit', function (evenement) {
    var formulaire = evenement.target.closest('form[data-confirmer]');
    if (formulaire && !window.confirm(formulaire.dataset.confirmer)) {
        evenement.preventDefault();
    }
});

// ---------- Menu selon le role (maquettes R01 a R03) ----------

// Le menu est-il en icones (replie sur ordinateur, ou tablette) ?
function menuEnIcones() {
    var enveloppe = document.getElementById('main-wrapper');
    var style = document.body.getAttribute('data-sidebar-style');
    return style === 'mini' || (style === 'full' && enveloppe && enveloppe.classList.contains('menu-toggle'));
}

// Volets : un seul ouvert a la fois. En icones, le lien mene au premier ecran.
document.addEventListener('click', function (evenement) {
    var lien = evenement.target.closest('[data-volet]');
    if (!lien || menuEnIcones()) {
        return;
    }
    evenement.preventDefault();
    var module = lien.closest('.si-menu-module');
    var ouvrir = !module.classList.contains('ouvert');
    module.parentElement.querySelectorAll(':scope > .si-menu-module.ouvert').forEach(function (autre) {
        autre.classList.remove('ouvert');
        var bouton = autre.querySelector(':scope > [data-volet]');
        if (bouton) {
            bouton.setAttribute('aria-expanded', 'false');
        }
    });
    module.classList.toggle('ouvert', ouvrir);
    lien.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
});

// « Replier le menu » : meme effet que le bouton hamburger, choix memorise
// par style de menu sur cet appareil.
document.addEventListener('click', function (evenement) {
    if (!evenement.target.closest('[data-replier-menu]')) {
        return;
    }
    var hamburger = document.querySelector('.nav-control');
    if (hamburger) {
        hamburger.click();
    }
    var barre = document.querySelector('[data-style-menu]');
    var enveloppe = document.getElementById('main-wrapper');
    if (barre && enveloppe && window.innerWidth >= 1024) {
        try {
            window.localStorage.setItem('si-menu-replie-' + barre.dataset.styleMenu, enveloppe.classList.contains('menu-toggle') ? '1' : '0');
        } catch (e) {}
    }
});

// Le bouton hamburger memorise aussi le choix.
document.addEventListener('click', function (evenement) {
    if (!evenement.target.closest('.nav-control') || evenement.isTrusted === false) {
        return;
    }
    window.setTimeout(function () {
        var barre = document.querySelector('[data-style-menu]');
        var enveloppe = document.getElementById('main-wrapper');
        if (barre && enveloppe && window.innerWidth >= 1024) {
            try {
                window.localStorage.setItem('si-menu-replie-' + barre.dataset.styleMenu, enveloppe.classList.contains('menu-toggle') ? '1' : '0');
            } catch (e) {}
        }
    }, 0);
});

// « Aller a… » (Ctrl K) : toutes les pages autorisees, filtrees au clavier.
var AllerA = (function () {
    function sansAccents(texte) {
        return texte.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function boite() {
        return document.getElementById('si-aller');
    }

    function liensVisibles() {
        var fenetre = boite();
        var liens = Array.prototype.slice.call(fenetre.querySelectorAll('[data-aller-liste] li:not([hidden]) a'));
        var recherche = fenetre.querySelector('[data-aller-recherche]');
        if (recherche && !recherche.hidden) {
            liens.push(recherche);
        }
        return liens;
    }

    function choisir(lien) {
        boite().querySelectorAll('.choisi').forEach(function (autre) {
            autre.classList.remove('choisi');
        });
        if (lien) {
            lien.classList.add('choisi');
            lien.scrollIntoView({ block: 'nearest' });
        }
    }

    function filtrer() {
        var fenetre = boite();
        var saisie = fenetre.querySelector('[data-aller-champ]').value.trim();
        var mots = sansAccents(saisie).split(/\s+/).filter(Boolean);
        fenetre.querySelectorAll('[data-aller-liste] a').forEach(function (lien) {
            var texte = sansAccents(lien.dataset.texte || lien.textContent);
            lien.parentElement.hidden = !mots.every(function (mot) {
                return texte.indexOf(mot) !== -1;
            });
        });
        var recherche = fenetre.querySelector('[data-aller-recherche]');
        if (recherche) {
            recherche.hidden = saisie === '';
            recherche.querySelector('span').textContent = saisie;
            var adresse = new URL(recherche.href, window.location.href);
            adresse.searchParams.set('q', saisie);
            recherche.href = adresse.toString();
        }
        choisir(liensVisibles()[0]);
    }

    function ouvrir() {
        var fenetre = boite();
        if (!fenetre) {
            return false;
        }
        var champ = fenetre.querySelector('[data-aller-champ]');
        fenetre.hidden = false;
        champ.value = '';
        filtrer();
        champ.focus();
        return true;
    }

    function fermer() {
        var fenetre = boite();
        if (fenetre) {
            fenetre.hidden = true;
        }
    }

    document.addEventListener('click', function (evenement) {
        if (evenement.target.closest('[data-aller-a]')) {
            ouvrir();
        } else if (evenement.target.id === 'si-aller') {
            fermer();
        }
    });

    document.addEventListener('input', function (evenement) {
        if (evenement.target.matches('[data-aller-champ]')) {
            filtrer();
        }
    });

    document.addEventListener('keydown', function (evenement) {
        var fenetre = boite();
        if (!fenetre || fenetre.hidden) {
            return;
        }
        var liens = liensVisibles();
        var rang = liens.indexOf(fenetre.querySelector('.choisi'));
        if (evenement.key === 'Escape') {
            evenement.preventDefault();
            fermer();
        } else if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
            evenement.preventDefault();
            if (liens.length) {
                rang = evenement.key === 'ArrowDown' ? (rang + 1) % liens.length : (rang - 1 + liens.length) % liens.length;
                choisir(liens[rang]);
            }
        } else if (evenement.key === 'Enter' && evenement.target.matches('[data-aller-champ]')) {
            evenement.preventDefault();
            var choisi = liens[rang] || liens[0];
            if (choisi) {
                window.location.href = choisi.href;
            }
        }
    });

    return { ouvrir: ouvrir };
})();

// Filtres des tableaux (petite fleche dans l'en-tete des listes,
// docs/filtres-tableaux.md) : le script et sa feuille de style ne se
// chargent que si la page a une liste.
(function () {
    var balise = document.currentScript;
    var script = balise && balise.getAttribute('data-filtres-js');
    var style = balise && balise.getAttribute('data-filtres-css');
    var compte = (balise && balise.getAttribute('data-filtres-compte')) || '';
    if (!script || !style) {
        return;
    }
    // A la deconnexion, les filtres retenus sur cet appareil sont oublies
    // (un poste partage : la personne suivante ne les retrouve pas).
    document.addEventListener('click', function (evenement) {
        if (!evenement.target.closest('a[href$="/logout"]')) {
            return;
        }
        try {
            Object.keys(window.localStorage).forEach(function (cle) {
                if (cle.indexOf('si-filtres:') === 0) {
                    window.localStorage.removeItem(cle);
                }
            });
        } catch (e) {
            // Stockage indisponible : rien a oublier.
        }
    });
    function aUneListe() {
        return Array.prototype.some.call(document.querySelectorAll('table'), function (table) {
            return table.tHead && table.tBodies.length === 1 && !table.closest('[data-sans-filtres]')
                && (table.getAttribute('data-filtres') === 'serveur' || table.tBodies[0].rows.length >= 2);
        });
    }
    function charger() {
        if (!aUneListe()) {
            return;
        }
        var lien = document.createElement('link');
        lien.rel = 'stylesheet';
        lien.href = style;
        // Le script attend sa feuille de style : pas de fleche sans mise en forme.
        lien.onload = lien.onerror = function () {
            var code = document.createElement('script');
            code.src = script;
            code.setAttribute('data-compte', compte);
            document.body.appendChild(code);
        };
        document.head.appendChild(lien);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', charger);
    } else {
        charger();
    }
})();
