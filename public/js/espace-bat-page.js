/*
 * Page d'un BAT dans l'espace client (templates/espace/app/bat.html.twig,
 * maquettes DB05, DB06 et DB12) :
 *  - « Je valide » ne s'active qu'une fois les 4 vérifications cochées (le
 *    serveur le revérifie) ;
 *  - « Annoter » / « Demander une modification » passe en mode annotation :
 *    toucher l'image pose un repère numéroté (10 au plus) avec ce qu'il faut
 *    changer ; « Envoyer mes N demandes » part avec les repères ;
 *  - zoom − / + sur l'image.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-bat-page]');
  if (!form) return;
  var MAX = 10;

  var valider = form.querySelector('[data-valider]');
  var aide = form.querySelector('[data-valider-aide]');
  var cases = form.querySelectorAll('[data-verif]');
  function majValider() {
    if (!valider) return;
    var faites = Array.prototype.filter.call(cases, function (c) { return c.checked; }).length;
    valider.disabled = faites < cases.length;
    if (aide) aide.hidden = faites === cases.length;
  }
  cases.forEach(function (c) { c.addEventListener('change', majValider); });
  majValider();

  // Mode annotation (DB06).
  var liste = form.querySelector('[data-reperes-saisie]');
  var champ = form.querySelector('[data-reperes]');
  var vide = form.querySelector('[data-reperes-vide]');
  var nombre = document.querySelectorAll('[data-nombre-demandes]');
  var envoyer = form.querySelector('[data-envoyer-demandes]');
  var remarque = form.querySelector('[data-remarque]');
  var images = form.querySelectorAll('.ec-bat-image');
  var reperes = [];

  function annoter(oui) {
    document.body.classList.toggle('db-annotation', oui);
    if (oui && images.length) {
      var reduit = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      images[0].scrollIntoView({ behavior: reduit ? 'auto' : 'smooth', block: 'center' });
    }
  }
  document.querySelectorAll('[data-annoter]').forEach(function (b) { b.addEventListener('click', function () { annoter(true); }); });
  document.querySelectorAll('[data-annoter-fin]').forEach(function (b) { b.addEventListener('click', function () { annoter(false); }); });

  function remplis() { return reperes.filter(function (r) { return r.t.trim() !== ''; }); }
  function majEnvoyer() {
    var n = remplis().length + (remarque && remarque.value.trim() !== '' ? 1 : 0);
    nombre.forEach(function (e) { e.textContent = reperes.length; });
    if (vide) vide.hidden = reperes.length > 0;
    if (envoyer) {
      envoyer.disabled = n === 0;
      envoyer.textContent = n > 1 ? 'Envoyer mes ' + n + ' demandes' : 'Envoyer ma demande';
    }
  }

  function dessiner() {
    form.querySelectorAll('.ec-repere.nouveau').forEach(function (p) { p.remove(); });
    if (!liste) return;
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
      texte.addEventListener('input', function () { r.t = texte.value; majEnvoyer(); });
      texte.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
      var retirer = document.createElement('button');
      retirer.type = 'button';
      retirer.className = 'ec-retirer';
      retirer.setAttribute('aria-label', 'Retirer le repère ' + (n + 1));
      retirer.textContent = '×';
      retirer.addEventListener('click', function () { reperes.splice(n, 1); dessiner(); });
      li.append(num, texte, retirer);
      liste.appendChild(li);
    });
    majEnvoyer();
  }

  images.forEach(function (cadre, k) {
    cadre.addEventListener('click', function (ev) {
      if (!document.body.classList.contains('db-annotation')) return;
      if (reperes.length >= MAX) { if (vide) { vide.hidden = false; vide.textContent = MAX + ' repères au plus : précisez le reste dans la remarque.'; } return; }
      var zone = cadre.querySelector('img').getBoundingClientRect();
      var x = (ev.clientX - zone.left) / zone.width;
      var y = (ev.clientY - zone.top) / zone.height;
      if (x < 0 || x > 1 || y < 0 || y > 1) return;
      reperes.push({ cadre: k, i: +cadre.dataset.rang, x: Math.round(x * 10000) / 10000, y: Math.round(y * 10000) / 10000, t: '' });
      dessiner();
      var champs = liste ? liste.querySelectorAll('input') : [];
      if (champs.length) champs[champs.length - 1].focus({ preventScroll: true });
    });
  });
  if (remarque) remarque.addEventListener('input', majEnvoyer);
  form.addEventListener('submit', function () {
    if (champ) champ.value = JSON.stringify(remplis().map(function (r) { return { i: r.i, x: r.x, y: r.y, t: r.t }; }));
  });
  majEnvoyer();

  // Zoom.
  var zoom = form.querySelector('[data-zoom]');
  var niveau = form.querySelector('[data-zoom-niveau]');
  var facteur = 1;
  function zoomer(d) {
    facteur = Math.min(3, Math.max(1, Math.round((facteur + d) * 100) / 100));
    if (zoom) zoom.style.width = (facteur * 100) + '%';
    if (niveau) niveau.textContent = Math.round(facteur * 100) + ' %';
  }
  var moins = form.querySelector('[data-zoom-moins]');
  var plus = form.querySelector('[data-zoom-plus]');
  if (moins) moins.addEventListener('click', function () { zoomer(-0.25); });
  if (plus) plus.addEventListener('click', function () { zoomer(0.25); });
})();
