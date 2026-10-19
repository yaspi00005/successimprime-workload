/*
 * Animations légères du site (docs/atelier-3d.md) : les blocs marqués
 * data-revele (ou la classe si-revele) glissent en place quand ils arrivent à l'écran, les cartes
 * data-incline s'inclinent un peu sous la souris, les photos avant / après
 * se comparent avec un curseur. Pas de mouvement si la personne a demandé
 * de réduire les animations (le curseur marche quand même) ; rien n'est
 * caché sans ce script, et aucun clic n'est bloqué. Les compteurs
 * data-compte (vrais chiffres de l'atelier, déjà écrits dans la page)
 * défilent de 0 à leur valeur quand ils arrivent à l'écran.
 */
(function () {
  'use strict';
  var reduit = !window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Avant / après : la photo « avant » est découpée à la position du curseur.
  Array.prototype.forEach.call(document.querySelectorAll('[data-avant-apres]'), function (fig) {
    var curseur = fig.querySelector('.si-aa-curseur');
    if (!curseur) return;
    var placer = function () { fig.style.setProperty('--coupe', curseur.value + '%'); };
    curseur.hidden = false;
    curseur.addEventListener('input', placer);
    placer();
    fig.classList.add('si-aa-pret');
    // Une fois à l'écran, le curseur glisse un peu pour montrer qu'il bouge.
    if (reduit || !('IntersectionObserver' in window)) return;
    var vu = new IntersectionObserver(function (entrees) {
      if (!entrees[0].isIntersecting) return;
      vu.disconnect();
      var t0 = 0;
      var pas = function (t) {
        if (!t0) t0 = t;
        var k = Math.min(1, (t - t0) / 1100);
        if (document.activeElement === curseur) return; // la personne a la main
        curseur.value = String(Math.round(50 - Math.sin(k * Math.PI * 2) * 14));
        placer();
        if (k < 1) window.requestAnimationFrame(pas);
      };
      setTimeout(function () { window.requestAnimationFrame(pas); }, 500);
    }, { threshold: 0.6 });
    vu.observe(fig);
  });

  if (reduit) return;

  var blocs = document.querySelectorAll('[data-revele], .si-revele');
  if (blocs.length && 'IntersectionObserver' in window) {
    var hauteur = window.innerHeight || 800;
    var observateur = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('si-vu');
          observateur.unobserve(e.target);
        }
      });
    }, { rootMargin: '0px 0px -6% 0px' });
    Array.prototype.forEach.call(blocs, function (b, i) {
      // Ce qui est déjà à l'écran reste en place : pas de clignotement au chargement.
      if (b.getBoundingClientRect().top < hauteur) {
        b.classList.add('si-vu');
        return;
      }
      b.style.setProperty('--si-delai', (i % 4) * 70 + 'ms');
      observateur.observe(b);
    });
    document.documentElement.classList.add('si-anime');
  }

  // Compteurs : le chiffre écrit dans la page reste la vérité ; on ne fait que le faire défiler.
  var compteurs = document.querySelectorAll('[data-compte]');
  if (compteurs.length && 'IntersectionObserver' in window) {
    var format = function (n, d) {
      return n.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/\u202f/g, '\u00a0');
    };
    var compter = new IntersectionObserver(function (entrees) {
      entrees.forEach(function (e) {
        if (!e.isIntersecting) return;
        compter.unobserve(e.target);
        var el = e.target;
        var cible = parseFloat(el.getAttribute('data-compte'));
        var d = parseInt(el.getAttribute('data-decimales') || '0', 10);
        if (isNaN(cible) || cible <= 0) return;
        var fin = el.textContent;
        var t0 = 0;
        var pas = function (t) {
          if (!t0) t0 = t;
          var k = Math.min(1, (t - t0) / 1200);
          var v = cible * (1 - Math.pow(1 - k, 3));
          el.textContent = k < 1 ? format(d ? v : Math.round(v), d) : fin;
          if (k < 1) window.requestAnimationFrame(pas);
        };
        window.requestAnimationFrame(pas);
      });
    }, { threshold: 0.5 });
    Array.prototype.forEach.call(compteurs, function (c) { compter.observe(c); });
  }

  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-incline]'), function (carte) {
      carte.addEventListener('pointermove', function (e) {
        var r = carte.getBoundingClientRect();
        var x = (e.clientX - r.left) / r.width - 0.5;
        var y = (e.clientY - r.top) / r.height - 0.5;
        carte.style.transform = 'perspective(700px) rotateX(' + (-y * 5).toFixed(2) + 'deg) rotateY(' + (x * 6).toFixed(2) + 'deg) translateY(-3px)';
      });
      carte.addEventListener('pointerleave', function () { carte.style.transform = ''; });
    });
  }
}());
