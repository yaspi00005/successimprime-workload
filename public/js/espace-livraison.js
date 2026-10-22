/*
 * Livraisons de l'espace client (maquettes DB08 à DB13) :
 *  - cartes Leaflet : livreur (🛵), destination (📍), imprimerie ; sur la
 *    page de suivi, la position et l'heure d'arrivée sont relues toutes
 *    les 30 secondes, et la page se recharge quand le colis est remis ;
 *  - « À programmer » : retrait ou livraison change le bouton ;
 *  - Programmer : adresse (nouvelle ou enregistrée), position du téléphone,
 *    créneaux du jour choisi, récapitulatif, texte du bouton selon le paiement ;
 *  - note : le bouton d'envoi apparaît une fois une étoile choisie.
 */
(function () {
  'use strict';
  var json = function (el, attr) { try { return JSON.parse(el.getAttribute(attr) || 'null'); } catch (e) { return null; } };
  var icone = function (texte, classe) { return window.L.divIcon({ className: 'db-lv-pin ' + classe, html: '<span>' + texte + '</span>', iconSize: [34, 34], iconAnchor: [17, 17] }); };

  function carte(el) {
    if (!window.L) return null;
    var livreur = json(el, 'data-livreur'), destination = json(el, 'data-destination'), depart = json(el, 'data-depart');
    if (!livreur && !destination) return null;
    el.innerHTML = '';
    var m = window.L.map(el, { scrollWheelZoom: false, zoomControl: el.hasAttribute('data-grande'), attributionControl: el.hasAttribute('data-grande') });
    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(m);
    var points = [], moto = null;
    if (depart) { window.L.marker([depart.lat, depart.lng], { icon: icone('🏭', 'depart') }).addTo(m); }
    if (destination) { window.L.marker([destination.lat, destination.lng], { icon: icone('📍', 'destination') }).addTo(m); points.push([destination.lat, destination.lng]); }
    if (livreur) { moto = window.L.marker([livreur.lat, livreur.lng], { icon: icone('🛵', 'livreur') }).addTo(m); points.push([livreur.lat, livreur.lng]); }
    var trait = livreur && destination ? window.L.polyline(points, { color: '#0F4590', weight: 4, dashArray: '8 8' }).addTo(m) : null;
    if (points.length > 1) { m.fitBounds(window.L.latLngBounds(points).pad(0.35)); } else { m.setView(points[0], 15); }
    return { carte: m, moto: moto, trait: trait, destination: destination };
  }

  window.addEventListener('load', function () {
    document.querySelectorAll('[data-carte]').forEach(function (el) {
      var c = carte(el);
      var url = el.getAttribute('data-suivi');
      if (!url) return;
      setInterval(function () {
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (r) {
          if (r.livree) { window.location.reload(); return; }
          if (r.heure) {
            document.querySelectorAll('[data-lv-heure]').forEach(function (e) { e.textContent = r.heure; });
            document.querySelectorAll('[data-lv-detail]').forEach(function (e) { e.textContent = 'arrivée estimée · ' + String(r.km).replace('.', ',') + ' km'; });
          }
          if (!r.livreur) return;
          if (!c) { el.setAttribute('data-livreur', JSON.stringify(r.livreur)); c = carte(el); return; }
          var ll = [r.livreur.lat, r.livreur.lng];
          if (c.moto) { c.moto.setLatLng(ll); } else { c.moto = window.L.marker(ll, { icon: icone('🛵', 'livreur') }).addTo(c.carte); }
          if (c.trait && c.destination) { c.trait.setLatLngs([ll, [c.destination.lat, c.destination.lng]]); }
        }).catch(function () {});
      }, 30000);
    });
  });

  // DB08 : retrait ou livraison.
  document.querySelectorAll('[data-lv-choix]').forEach(function (bloc) {
    var livraison = bloc.querySelector('[data-lv-livraison]'), retrait = bloc.querySelector('[data-lv-retrait]');
    bloc.querySelectorAll('[data-lv-mode]').forEach(function (r) {
      r.addEventListener('change', function () {
        var estRetrait = r.checked && r.value === 'retrait';
        if (livraison) livraison.hidden = estRetrait;
        if (retrait) retrait.hidden = !estRetrait;
      });
    });
  });

  // DB09 : programmer.
  var form = document.querySelector('[data-lv-programmer]');
  if (form) {
    var nouvelle = form.querySelector('[data-lv-nouvelle]');
    var recapAdresse = form.querySelector('[data-lv-recap-adresse]');
    var recapCreneau = form.querySelector('[data-lv-recap-creneau]');
    var repere = form.querySelector('[name=repere]');
    var majAdresse = function () {
      var r = form.querySelector('input[name=adresse]:checked');
      var neuve = !r || r.value === 'nouvelle';
      if (nouvelle) nouvelle.hidden = !neuve;
      if (recapAdresse) recapAdresse.textContent = neuve ? (form.querySelector('[name=nouvelle_libelle]').value || 'Nouvelle adresse') : r.getAttribute('data-libelle');
    };
    form.querySelectorAll('input[name=adresse]').forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.value !== 'nouvelle' && repere && r.getAttribute('data-repere')) repere.value = r.getAttribute('data-repere');
        majAdresse();
      });
    });
    var libelleNouvelle = form.querySelector('[name=nouvelle_libelle]');
    if (libelleNouvelle) libelleNouvelle.addEventListener('input', majAdresse);
    majAdresse();

    var majJour = function () {
      var j = form.querySelector('input[name=jour]:checked');
      form.querySelectorAll('[data-lv-creneaux]').forEach(function (bloc) {
        var actif = j && bloc.getAttribute('data-lv-creneaux') === j.value;
        bloc.hidden = !actif;
        bloc.querySelectorAll('input').forEach(function (i) { if (!actif) i.checked = false; });
        if (actif && !bloc.querySelector('input:checked')) {
          var libre = bloc.querySelector('input:not([disabled])');
          if (libre) libre.checked = true;
        }
      });
      majCreneau();
    };
    var majCreneau = function () {
      var c = form.querySelector('input[name=creneau]:checked');
      if (recapCreneau) recapCreneau.textContent = c ? c.getAttribute('data-libelle') : '—';
    };
    form.querySelectorAll('input[name=jour]').forEach(function (r) { r.addEventListener('change', majJour); });
    form.querySelectorAll('input[name=creneau]').forEach(function (r) { r.addEventListener('change', majCreneau); });
    majJour();

    var valider = form.querySelector('[data-lv-valider]');
    form.querySelectorAll('[data-lv-paiement]').forEach(function (r) {
      r.addEventListener('change', function () { if (r.checked && valider) valider.textContent = r.value === 'orange_money' ? valider.getAttribute('data-texte-om') : valider.getAttribute('data-texte-livreur'); });
    });

    // Position du téléphone : le livreur trouve l'endroit, et l'heure d'arrivée se calcule.
    var choix = form.querySelector('[data-carte-choix]'), lat = form.querySelector('[data-lv-lat]'), lng = form.querySelector('[data-lv-lng]');
    var etat = form.querySelector('[data-lv-gps-etat]'), petite = null, pin = null;
    var montrer = function (p) {
      if (!window.L || !choix || !p) return;
      if (!petite) {
        choix.innerHTML = '';
        petite = window.L.map(choix, { zoomControl: false, attributionControl: false, scrollWheelZoom: false });
        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(petite);
      }
      petite.setView([p.lat, p.lng], 16);
      if (pin) { pin.setLatLng([p.lat, p.lng]); } else { pin = window.L.marker([p.lat, p.lng], { icon: icone('📍', 'destination') }).addTo(petite); }
    };
    window.addEventListener('load', function () { montrer(json(choix, 'data-position')); });
    var gps = form.querySelector('[data-lv-gps]');
    if (gps) {
      gps.addEventListener('click', function () {
        if (!navigator.geolocation) { etat.textContent = 'Ce téléphone ne donne pas sa position.'; return; }
        etat.textContent = 'Recherche de votre position…';
        navigator.geolocation.getCurrentPosition(function (p) {
          lat.value = p.coords.latitude.toFixed(5);
          lng.value = p.coords.longitude.toFixed(5);
          etat.textContent = 'Position notée (à ' + Math.round(p.coords.accuracy) + ' m près).';
          montrer({ lat: p.coords.latitude, lng: p.coords.longitude });
        }, function () { etat.textContent = 'Position refusée : le repère suffit, le livreur vous appellera au besoin.'; }, { enableHighAccuracy: true, timeout: 15000 });
      });
    }
  }

  // DB11 : la note.
  document.querySelectorAll('[data-lv-note]').forEach(function (f) {
    var b = f.querySelector('[data-lv-envoyer-note]');
    f.querySelectorAll('input[name=note]').forEach(function (r) { r.addEventListener('change', function () { if (b) b.hidden = false; }); });
  });
})();
