#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Sur un devis, renseigne automatiquement la Désignation des qu'un
PRODUIT est choisi (elle se remplissait deja pour un Article en
stock, mais pas pour un Produit).

Le champ <select> "produit" affiche deja le nom du produit comme
texte de chaque option (choice_label: 'nom' dans DevisDetailsType),
donc on peut le lire directement au moment du changement, sans appel
reseau supplementaire -- avant meme que le chargement des
configurations associees (chargerConfigurationsProduit) ne se
termine.

Usage:
    python3 renseigner_designation_produit_devis.py /chemin/vers/successImprim
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


ANCIEN_BLOC = """const produitChange = function () {

console.log('Produit changé :', produit.value);

chargerConfigurationsProduit(detail, produit.value);
};"""

NOUVEAU_BLOC = """const produitChange = function () {

console.log('Produit changé :', produit.value);

const optionProduit = produit.options[produit.selectedIndex];

const nomProduit = optionProduit && produit.value ? optionProduit.textContent.trim() : '';

definirValeurChamp(detail, 'designation', nomProduit, false);

chargerConfigurationsProduit(detail, produit.value);
};"""

MARQUEUR = "const nomProduit = optionProduit"


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
        print("    grep -n -B3 -A6 \"const produitChange = function\" " + chemin_relatif)
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

    chemin_relatif = "templates/devis/_form.html.twig"

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
        print("Puis videz le cache de votre navigateur (Cmd+Maj+R). Sur un")
        print("devis, la Désignation se remplit maintenant automatiquement")
        print("des qu'un Produit est choisi (comme c'etait deja le cas pour")
        print("un Article en stock).")
    else:
        print("Le fichier n'a pas pu etre modifie (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi TOUT ce resume, je corrige avant de vous renvoyer le script.")


if __name__ == "__main__":
    main()
