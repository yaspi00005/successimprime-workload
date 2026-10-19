/*
 * Devis dans l'espace client (templates/espace/app/devis_detail.html.twig,
 * maquettes DB02, DB03 et DB12) :
 *  - choisir une quantité refait le total, l'acompte et le reste (même
 *    calcul que TotauxDocument : remise sur le HT, TVA au prorata) ; le
 *    serveur refait tout à l'acceptation ;
 *  - Accepter, Demander une modification et Refuser ouvrent leur fenêtre ;
 *  - l'acompte à l'imprimerie change le bouton « Accepter et payer ».
 */
(function () {
  'use strict';
  var page = document.querySelector('[data-db-devis]');
  if (!page) return;
  var calcul = JSON.parse(page.getAttribute('data-calcul') || '{}');
  var francs = function (n) { return new Intl.NumberFormat('fr-FR').format(n).replace(/[  ]/g, ' ') + ' F'; };
  var ecrire = function (attr, texte) { document.querySelectorAll('[' + attr + ']').forEach(function (e) { e.textContent = texte; }); };

  function choisie(pid) {
    var r = document.querySelector('input[data-option][name="options[' + pid + ']"]:checked');
    return r ? r.value : null;
  }

  function refaire() {
    var ht = calcul.frais || 0, tva = 0;
    Object.keys(calcul.lignes || {}).forEach(function (pid) {
      var l = calcul.lignes[pid];
      var q = choisie(pid);
      var v = q !== null && l.choix[q] ? l.choix[q] : l;
      ht += v.ht; tva += v.tva;
      if (q !== null && l.choix[q]) {
        var ttc = l.choix[q].ht + l.choix[q].tva;
        var tr = document.querySelector('tr[data-ligne="' + pid + '"] [data-total-ligne]');
        if (tr) tr.textContent = francs(ttc);
        var recap = document.querySelector('[data-recap="' + pid + '"]');
        if (recap) {
          recap.querySelector('[data-recap-total]').textContent = francs(ttc);
          var rq = recap.querySelector('[data-recap-q]');
          if (rq) rq.textContent = new Intl.NumberFormat('fr-FR').format(+q).replace(/[  ]/g, ' ');
        }
      }
    });
    var remise = Math.min(Math.max(0, calcul.remise || 0), ht);
    var totalHt = ht - remise;
    var total = totalHt + (ht > 0 ? Math.round(tva * totalHt / ht) : 0);
    var acompte = Math.min(total, Math.ceil(total * (calcul.pourcentage || 0) / 100));
    ecrire('data-db-total', francs(total));
    ecrire('data-db-acompte', francs(acompte));
    ecrire('data-db-reste', francs(total - acompte));
    document.querySelectorAll('[data-choix-tel]').forEach(function (l) {
      var r = document.getElementById(l.getAttribute('data-choix-tel'));
      l.classList.toggle('choisi', !!(r && r.checked));
    });
  }
  document.querySelectorAll('input[data-option]').forEach(function (r) { r.addEventListener('change', refaire); });

  // Fenêtres : accepter (DB03), modifier, refuser.
  document.querySelectorAll('[data-db-ouvrir]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById(b.getAttribute('data-db-ouvrir'));
      if (d && d.showModal) d.showModal();
    });
  });
  document.querySelectorAll('dialog.db-dialogue').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    d.querySelectorAll('[data-db-fermer]').forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
  });
  document.querySelectorAll('[data-db-cgv]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); var c = document.getElementById('db-cgv'); if (c) c.open = !c.open; });
  });

  // Acompte à l'imprimerie : on accepte sans payer maintenant.
  var valider = document.querySelector('[data-db-valider]');
  if (valider) {
    var montant = valider.querySelector('[data-db-acompte]');
    document.querySelectorAll('[data-db-mode]').forEach(function (r) {
      r.addEventListener('change', function () {
        var imprimerie = r.checked && r.value === 'imprimerie';
        valider.firstChild.textContent = (imprimerie ? valider.getAttribute('data-texte-imprimerie') : valider.getAttribute('data-texte-om')) + (imprimerie ? '' : ' ');
        if (montant) montant.hidden = imprimerie;
      });
    });
  }
})();
