/*
 * Carte « Nous soutenir » du tableau de bord (templates/espace/app/_tableau_soutien.html.twig) :
 * « × » la ferme sans recharger la page. Sans ce script, le formulaire est
 * envoyé normalement et la page revient sans la carte.
 */
(function () {
  'use strict';
  var formulaire = document.querySelector('[data-soutien-fermer]');
  var carte = document.querySelector('[data-soutien-carte]');
  if (!formulaire || !carte || !window.fetch) {
    return;
  }
  formulaire.addEventListener('submit', function (evenement) {
    evenement.preventDefault();
    var bouton = formulaire.querySelector('button');
    if (bouton) {
      bouton.disabled = true;
    }
    fetch(formulaire.action, {
      method: 'POST',
      body: new FormData(formulaire),
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    }).then(function (reponse) {
      if (!reponse.ok) {
        throw new Error(String(reponse.status));
      }
      carte.remove();
    }).catch(function () {
      // Hors ligne ou page expirée : l'envoi normal recharge la page et dit ce qui ne va pas.
      formulaire.submit();
    });
  });
})();
