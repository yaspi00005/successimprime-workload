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
