#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
A LANCER UNE SEULE FOIS, AVANT de pousser le projet sur GitHub pour
la premiere fois.

Probleme : le fichier .env contient actuellement de vraies valeurs
secretes (identifiants Orange SMS, mot de passe base de donnees,
etc.). Si .env est envoye tel quel sur GitHub, ces secrets restent
DEFINITIVEMENT dans l'historique git, meme si on les supprime plus
tard (il faudrait reecrire tout l'historique, ou pire, considerer
les secrets comme compromis et tous les regenerer).

Ce que fait ce script :
  1. Pour chaque ligne "CLE=valeur" de .env qui a une VRAIE valeur
     (non vide) et qui n'est pas une variable "sans risque" (comme
     APP_ENV), la valeur est deplacee vers .env.local (jamais
     envoye sur GitHub -- c'est la convention Symfony standard).
  2. La ligne correspondante dans .env est remise a vide
     ("CLE="), comme c'est la convention pour ce fichier
     (qui, lui, est bien envoye sur GitHub -- mais sans aucun
     secret dedans).
  3. .gitignore est cree/complete pour que .env.local (et les
     dossiers vendor/, var/, node_modules/) ne soient jamais
     envoyes sur GitHub.

L'application continue de fonctionner exactement comme avant : Symfony
lit .env PUIS .env.local, et .env.local a toujours la priorite. Rien
ne change cote fonctionnement, seule la repartition entre les 2
fichiers change.

Peut etre relance sans risque (idempotent) : une valeur deja dans
.env.local n'est jamais ecrasee.

Usage:
    python3 preparer_env_avant_git.py /chemin/vers/successImprim
"""

import os
import re
import sys

# Variables qu'on laisse dans .env avec leur valeur actuelle (elles
# ne sont pas sensibles -- ce sont des reglages, pas des secrets).
CLES_SANS_RISQUE = {
    'APP_ENV',
    'APP_DEBUG',
    'APP_SHARE_DIR',
    'TRUSTED_PROXIES',
    'TRUSTED_HOSTS',
    'DEFAULT_URI',
    'KERNEL_CLASS',
}

LIGNE_CLE_VALEUR = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)=(.*)$')


def erreur_fatale(message):
    print("\n[ERREUR FATALE] " + message)
    sys.exit(1)


def verifier_racine(racine):
    composer_json = os.path.join(racine, "composer.json")
    if not os.path.isfile(composer_json):
        erreur_fatale(
            "Aucun 'composer.json' trouve dans " + os.path.realpath(racine) + "\n"
            "  => Relancez le script en pointant vers la racine du projet."
        )
    print("[OK] composer.json trouve : c'est bien la racine du projet.")
    print()


def lire_cles_existantes(chemin_absolu):
    cles = set()

    if not os.path.isfile(chemin_absolu):
        return cles

    with open(chemin_absolu, "r", encoding="utf-8") as f:
        for ligne in f:
            correspondance = LIGNE_CLE_VALEUR.match(ligne.strip())
            if correspondance:
                cles.add(correspondance.group(1))

    return cles


def separer_env(racine):
    chemin_env = os.path.join(racine, ".env")
    chemin_env_local = os.path.join(racine, ".env.local")

    print("* Separation de .env / .env.local")

    if not os.path.isfile(chemin_env):
        print("  [ECHEC] .env introuvable.")
        return False

    with open(chemin_env, "r", encoding="utf-8") as f:
        lignes_env = f.readlines()

    cles_deja_dans_local = lire_cles_existantes(chemin_env_local)

    nouvelles_lignes_env = []
    lignes_a_ajouter_local = []
    nombre_deplacees = 0
    nombre_deja_faites = 0

    for ligne in lignes_env:
        ligne_sans_saut = ligne.rstrip("\n")
        correspondance = LIGNE_CLE_VALEUR.match(ligne_sans_saut.strip())

        if correspondance is None:
            # Commentaire, ligne vide, etc. : on garde tel quel.
            nouvelles_lignes_env.append(ligne)
            continue

        cle = correspondance.group(1)
        valeur = correspondance.group(2)

        # On respecte l'indentation/espaces d'origine avant la cle.
        prefixe = ligne_sans_saut[:len(ligne_sans_saut) - len(ligne_sans_saut.lstrip())]

        valeur_nettoyee = valeur.strip().strip("'").strip('"')

        if cle in CLES_SANS_RISQUE or valeur_nettoyee == '':
            nouvelles_lignes_env.append(ligne)
            continue

        if cle in cles_deja_dans_local:
            # Deja migre lors d'un run precedent : on vide quand meme
            # .env s'il contenait encore la vraie valeur.
            nouvelles_lignes_env.append(prefixe + cle + "=\n")
            nombre_deja_faites += 1
            continue

        # Vraie valeur sensible : part dans .env.local, .env est vide.
        lignes_a_ajouter_local.append(cle + "=" + valeur + "\n")
        nouvelles_lignes_env.append(prefixe + cle + "=\n")
        nombre_deplacees += 1

    if nombre_deplacees == 0 and nombre_deja_faites == 0:
        print("  [SKIP] rien a deplacer (deja fait, ou .env ne contient aucun secret).")
        return True

    # Ecrit .env.local (cree le fichier s'il n'existe pas, ajoute a la fin sinon).
    if lignes_a_ajouter_local:
        entete = (
            "\n# --- Valeurs deplacees automatiquement depuis .env ---\n"
            "# Ce fichier ne doit JAMAIS etre envoye sur GitHub (voir .gitignore).\n"
        )

        mode = "a" if os.path.isfile(chemin_env_local) else "w"

        with open(chemin_env_local, mode, encoding="utf-8", newline="") as f:
            if mode == "w":
                f.write("# Genere par preparer_env_avant_git.py\n")

            f.write(entete)
            f.writelines(lignes_a_ajouter_local)
            f.flush()
            os.fsync(f.fileno())

    # Reecrit .env avec les valeurs sensibles videes.
    with open(chemin_env, "w", encoding="utf-8", newline="") as f:
        f.writelines(nouvelles_lignes_env)
        f.flush()
        os.fsync(f.fileno())

    print(
        "  [OK] " + str(nombre_deplacees)
        + " valeur(s) deplacee(s) vers .env.local, "
        + str(nombre_deja_faites)
        + " deja migree(s) (valeur re-videe dans .env)."
    )

    return True


def assurer_gitignore(racine):
    chemin_gitignore = os.path.join(racine, ".gitignore")

    print("* Verification de .gitignore")

    lignes_requises = [
        "/vendor/",
        "/var/",
        "/node_modules/",
        "/public/bundles/",
        ".env.local",
        ".env.local.php",
        ".env.*.local",
        "/.phpunit.result.cache",
    ]

    contenu_actuel = ""
    if os.path.isfile(chemin_gitignore):
        with open(chemin_gitignore, "r", encoding="utf-8") as f:
            contenu_actuel = f.read()

    lignes_existantes = set(
        ligne.strip() for ligne in contenu_actuel.splitlines()
    )

    lignes_manquantes = [
        ligne for ligne in lignes_requises
        if ligne not in lignes_existantes
    ]

    if not lignes_manquantes:
        print("  [SKIP] deja complet.")
        return True

    with open(chemin_gitignore, "a", encoding="utf-8", newline="") as f:
        if contenu_actuel and not contenu_actuel.endswith("\n"):
            f.write("\n")

        f.write("\n# Ajoute automatiquement par preparer_env_avant_git.py\n")

        for ligne in lignes_manquantes:
            f.write(ligne + "\n")

        f.flush()
        os.fsync(f.fileno())

    print("  [OK] " + str(len(lignes_manquantes)) + " ligne(s) ajoutee(s).")
    return True


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    resultats = [
        separer_env(racine),
        assurer_gitignore(racine),
    ]

    print()
    if all(resultats):
        print("=" * 70)
        print("PRET POUR GIT : .env ne contient plus aucun secret.")
        print("=" * 70)
        print()
        print("Verifiez rapidement : ouvrez .env et confirmez que les lignes")
        print("sensibles (ORANGE_SMS_..., mot de passe base de donnees, etc.)")
        print("sont maintenant VIDES (juste 'CLE='). Les vraies valeurs sont")
        print("dans .env.local -- l'application continue de fonctionner")
        print("normalement, ce fichier reste lu par Symfony.")
        print()
        print("Vous pouvez maintenant suivre les instructions pour creer le")
        print("depot GitHub et y pousser le projet.")
    else:
        print("[ATTENTION] certaines etapes ont echoue (voir [ECHEC] ci-dessus).")


if __name__ == "__main__":
    main()
