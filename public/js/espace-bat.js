/*
 * Carte d'un BAT dans l'espace client (templates/espace/app/_bat_carte.html.twig,
 * maquettes CO05 et CO06), pour chaque carte de la page :
 *  - « Valider le BAT » ne s'active qu'une fois chaque point de « Avant de
 *    valider, vérifiez » coché (le serveur le vérifie aussi) ;
 *  - « Demander une correction » ouvert, toucher l'image pose un repère
 *    numéroté (10 au plus) avec ce qu'il faut changer (maquette 16).
 * Sans JavaScript, le formulaire marche aussi : cases obligatoires, texte.
 */
(function () {
  'use strict';
  var MAX = 10;

  document.querySelectorAll('[data-bat]').forEach(function (carte) {
    var valider = carte.querySelector('[data-valider]');
    var cases = carte.querySelectorAll('[data-verif]');
    if (valider && cases.length) {
      var majValider = function () {
        var toutes = Array.prototype.every.call(cases, function (c) { return c.checked; });
        valider.disabled = !toutes;
        valider.title = toutes ? '' : 'Cochez chaque point avant de valider';
      };
      cases.forEach(function (c) { c.addEventListener('change', majValider); });
      majValider();
    }

    var panneau = carte.querySelector('details.cs-corriger');
    var liste = carte.querySelector('[data-reperes-saisie]');
    var champ = carte.querySelector('[data-reperes]');
    var images = carte.querySelectorAll('.ec-bat-image');
    if (!panneau || !liste || !champ || !images.length) { return; }
    var reperes = [];

    function dessiner() {
      carte.querySelectorAll('.ec-repere.nouveau').forEach(function (p) { p.remove(); });
      liste.innerHTML = '';
      reperes.forEach(function (r, n) {
        var pastille = document.createElement('span');
        pastille.className = 'ec-repere nouveau';
        pastille.style.left = (r.x * 100) + '%';
        pastille.style.top = (r.y * 100) + '%';
        pastille.textContent = n + 1;
        images[r.cadre].appendChild(pastille);

        var li = document.createElement('li');
        var num = document.createElement('span');
        num.className = 'ec-num';
        num.textContent = n + 1;
        var texte = document.createElement('input');
        texte.className = 'ec-champ';
        texte.maxLength = 200;
        texte.placeholder = 'Que faut-il changer ici ?';
        texte.setAttribute('aria-label', 'Repère ' + (n + 1));
        texte.value = r.t;
        texte.addEventListener('input', function () { r.t = texte.value; });
        // Entrée dans un repère ne doit jamais envoyer le formulaire (le premier bouton est « Valider »).
        texte.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); } });
        var retirer = document.createElement('button');
        retirer.type = 'button';
        retirer.className = 'ec-retirer';
        retirer.setAttribute('aria-label', 'Retirer le repère ' + (n + 1));
        retirer.textContent = '×';
        retirer.addEventListener('click', function () { reperes.splice(n, 1); dessiner(); });
        li.append(num, texte, retirer);
        liste.appendChild(li);
      });
    }

    panneau.addEventListener('toggle', function () {
      carte.classList.toggle('cs-placer', panneau.open);
      if (panneau.open) {
        var reduit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        images[0].scrollIntoView({ behavior: reduit ? 'auto' : 'smooth', block: 'center' });
      }
    });
    images.forEach(function (cadre, k) {
      cadre.addEventListener('click', function (ev) {
        if (!panneau.open) { return; }
        if (reperes.length >= MAX) { alert(MAX + ' repères au plus : précisez le reste dans le texte.'); return; }
        var zone = cadre.querySelector('img').getBoundingClientRect();
        var x = (ev.clientX - zone.left) / zone.width;
        var y = (ev.clientY - zone.top) / zone.height;
        if (x < 0 || x > 1 || y < 0 || y > 1) { return; }
        reperes.push({ cadre: k, i: +cadre.dataset.rang, x: Math.round(x * 10000) / 10000, y: Math.round(y * 10000) / 10000, t: '' });
        dessiner();
        var champs = liste.querySelectorAll('input');
        champs[champs.length - 1].focus({ preventScroll: true });
      });
    });
    panneau.closest('form').addEventListener('submit', function () {
      champ.value = JSON.stringify(reperes.filter(function (r) { return r.t.trim() !== ''; }).map(function (r) { return { i: r.i, x: r.x, y: r.y, t: r.t }; }));
    });
  });
})();
