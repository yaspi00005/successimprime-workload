#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Remplace la popup de confirmation native du navigateur (window.confirm,
la boite grise/moche "127.0.0.1:8000 indique...") par une confirmation
SweetAlert2, deja utilisee ailleurs dans l'application (ex. liste des
commandes) et deja chargee globalement (templates/base.html.twig).

Concerne le formulaire "Demarrer la production" (et tout autre
formulaire utilisant la meme classe .js-confirm-form) sur la fiche
d'un ordre de production.

Usage:
    python3 remplacer_confirm_natif_production.py /chemin/vers/successImprim
"""

import os
import sys


def erreur_fatale(message):
    print("\n[ERREUR FATALE] " + message)
    sys.exit(1)


def verifier_racine(racine):
    print("=" * 70)
    print("DIAGNOSTIC DE L'EMPLACEMENT")
    print("=" * 70)
    print("Repertoire courant (pwd)      : " + os.getcwd())
    print("Racine passee en argument     : " + racine)
    print("Racine resolue (chemin absolu): " + os.path.realpath(racine))

    composer_json = os.path.join(racine, "composer.json")
    if not os.path.isfile(composer_json):
        erreur_fatale(
            "Aucun 'composer.json' trouve dans " + os.path.realpath(racine) + "\n"
            "  => Relancez le script en pointant vers la racine du projet."
        )
    print("[OK] composer.json trouve : c'est bien la racine du projet.")
    print()


ANCIEN_BLOC = """const forms = document.querySelectorAll('.js-confirm-form');

forms.forEach(function (form) {
form.addEventListener('submit', function (event) {
const message = form.dataset.message || 'Voulez-vous continuer ?';

if (!window.confirm(message)) {
event.preventDefault();
}
});
});"""

NOUVEAU_BLOC = """const forms = document.querySelectorAll('.js-confirm-form');

forms.forEach(function (form) {
form.addEventListener('submit', function (event) {
event.preventDefault();

const message = form.dataset.message || 'Voulez-vous continuer ?';

if (typeof window.Swal === 'undefined') {
if (window.confirm(message)) {
form.submit();
}

return;
}

window.Swal.fire({
icon: 'question',
title: 'Confirmation',
text: message,
showCancelButton: true,
confirmButtonText: 'Oui, continuer',
cancelButtonText: 'Annuler'
}).then(function (resultat) {
if (resultat.isConfirmed) {
form.submit();
}
});
});
});"""

MARQUEUR = "typeof window.Swal === 'undefined'"


def corriger_fichier(racine, chemin_relatif):
    chemin_absolu = os.path.join(racine, chemin_relatif)

    if not os.path.isfile(chemin_absolu):
        print("[ABSENT] " + chemin_relatif + " n'existe pas du tout sur le disque.")
        return False

    with open(chemin_absolu, "r", encoding="utf-8") as f:
        contenu = f.read()

    if MARQUEUR in contenu:
        print("[SKIP] " + chemin_relatif + " contient deja '" + MARQUEUR + "' (deja applique).")
        return True

    if ANCIEN_BLOC not in contenu:
        print("[ECHEC] " + chemin_relatif + " : bloc introuvable -> abandon (rien ecrit).")
        print("  Votre fichier reel differe probablement du brouillon a cet endroit.")
        print("  Copiez-moi le resultat de :")
        print("    grep -n -B3 -A10 \"js-confirm-form\" " + chemin_relatif)
        return False

    contenu_corrige = contenu.replace(ANCIEN_BLOC, NOUVEAU_BLOC, 1)

    with open(chemin_absolu, "w", encoding="utf-8", newline="") as f:
        f.write(contenu_corrige)
        f.flush()
        os.fsync(f.fileno())

    with open(chemin_absolu, "r", encoding="utf-8", newline="") as f:
        relu = f.read()

    if relu != contenu_corrige:
        print("[ECHEC VERIFICATION] " + chemin_relatif + " : le contenu relu ne correspond pas.")
        return False

    print("[OK VERIFIE] " + chemin_relatif)
    print("  Chemin reel : " + os.path.realpath(chemin_absolu))
    return True


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    chemin_relatif = "templates/production/show.html.twig"

    print("-" * 70)
    print(chemin_relatif)
    print("-" * 70)

    resultat = corriger_fichier(racine, chemin_relatif)
    print()

    print("=" * 70)
    print("RESUME")
    print("=" * 70)

    if resultat:
        print("Tout est en place. Lancez maintenant :")
        print("  php bin/console cache:clear")
        print()
        print("Puis videz le cache de votre navigateur (Cmd+Maj+R). Le bouton")
        print("'Demarrer' (et tout autre bouton avec la meme confirmation)")
        print("affiche maintenant une jolie boite de confirmation au lieu de")
        print("la popup grise du navigateur.")
    else:
        print("Le fichier n'a pas pu etre modifie (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi TOUT ce resume, je corrige avant de vous renvoyer le script.")


if __name__ == "__main__":
    main()
