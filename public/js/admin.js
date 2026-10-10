// Raccourci Ctrl+K (ou Cmd+K) : place le curseur dans la recherche globale.
document.addEventListener('keydown', function (evenement) {
    if ((evenement.ctrlKey || evenement.metaKey) && evenement.key.toLowerCase() === 'k') {
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
