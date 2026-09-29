#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Permet a l'Admin de choisir SA PROPRE caisse personnelle comme
"Compte source" d'un mouvement de tresorerie (decaissement ou
transfert).

Avant ce correctif, la liste "Compte source" proposee a l'Admin
excluait TOUTES les caisses personnelles (y compris la sienne) --
la regle visait a l'origine a empecher l'Admin de retirer
directement l'argent de la caisse d'un AUTRE agent, mais elle
bloquait aussi, par effet de bord, l'usage de sa propre caisse.

Ce qui change : l'Admin voit desormais, en plus des comptes
administratifs et partages, SA PROPRE caisse personnelle dans la
liste "Compte source". Les caisses personnelles des AUTRES agents
restent invisibles pour lui comme source (regle inchangee : meme
l'Admin ne peut pas retirer directement l'argent d'une caisse qui ne
lui appartient pas -- cette regle est appliquee separement, au
niveau du controleur, et n'est pas touchee par ce script).

Fichier concerne :
  - src/Form/MouvementTresorerieType.php

Usage:
    python3 permettre_admin_source_perso.py /chemin/vers/successImprim
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


def appliquer_paire_texte(racine, chemin_relatif, description, ancien, nouveau):
    chemin_absolu = os.path.join(racine, chemin_relatif)

    print("* " + chemin_relatif + " : " + description)

    if not os.path.isfile(chemin_absolu):
        print("  [ECHEC] fichier introuvable.")
        return False

    with open(chemin_absolu, "r", encoding="utf-8") as f:
        contenu = f.read()

    if nouveau in contenu:
        print("  [SKIP] deja applique.")
        return True

    if contenu.count(ancien) == 0:
        print("  [ECHEC] bloc de reference introuvable.")
        return False

    if contenu.count(ancien) > 1:
        print("  [ECHEC] bloc trouve plusieurs fois, abandon par prudence.")
        return False

    contenu_corrige = contenu.replace(ancien, nouveau, 1)

    with open(chemin_absolu, "w", encoding="utf-8", newline="") as f:
        f.write(contenu_corrige)
        f.flush()
        os.fsync(f.fileno())

    with open(chemin_absolu, "r", encoding="utf-8", newline="") as f:
        relu = f.read()

    if relu != contenu_corrige:
        print("  [ECHEC VERIFICATION] le contenu relu ne correspond pas.")
        return False

    print("  [OK VERIFIE]")
    return True


# ============================================================
# src/Form/MouvementTresorerieType.php
# ============================================================

ANCIEN = """        /*
         * ====================================================
         * ADMIN
         * ====================================================
         *
         * - comptes administratifs
         * - comptes partagés
         *
         * On évite les caisses personnelles des agents.
         */
        if ($estAdmin) {
            return $qb
                ->andWhere(
                    'compte.portee IN (:portees)'
                )
                ->setParameter(
                    'portees',
                    [
                        CompteTresorerie::PORTEE_ADMIN,
                        CompteTresorerie::PORTEE_PARTAGEE,
                    ]
                );
        }"""

NOUVEAU = """        /*
         * ====================================================
         * ADMIN
         * ====================================================
         *
         * - comptes administratifs
         * - comptes partagés
         * - SA PROPRE caisse personnelle (l'Admin est aussi un
         *   agent et peut avoir sa propre caisse)
         *
         * On évite les caisses personnelles des AUTRES agents :
         * même Admin ne peut pas retirer directement l'argent
         * d'une caisse qui ne lui appartient pas.
         */
        if ($estAdmin) {
            return $qb
                ->andWhere(
                    '
                    compte.portee IN (:portees)

                    OR

                    (
                        compte.portee = :personnelle
                        AND
                        compte.proprietaire = :utilisateur
                    )
                    '
                )
                ->setParameter(
                    'portees',
                    [
                        CompteTresorerie::PORTEE_ADMIN,
                        CompteTresorerie::PORTEE_PARTAGEE,
                    ]
                )
                ->setParameter(
                    'personnelle',
                    CompteTresorerie::PORTEE_PERSONNELLE
                )
                ->setParameter(
                    'utilisateur',
                    $utilisateur
                );
        }"""


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    resultats = []

    print("--- MouvementTresorerieType ---")
    resultats.append(appliquer_paire_texte(
        racine, "src/Form/MouvementTresorerieType.php",
        "ajoute la propre caisse personnelle de l'Admin aux comptes source possibles",
        ANCIEN, NOUVEAU
    ))

    print()
    if all(resultats):
        print("=" * 70)
        print("CORRIGE AVEC SUCCES.")
        print("=" * 70)
        print()
        print("Aucune migration necessaire. Videz le cache :")
        print()
        print("    php bin/console cache:clear")
        print()
        print("L'Admin peut desormais choisir sa propre caisse personnelle comme")
        print("'Compte source' d'un decaissement ou d'un transfert -- les caisses")
        print("personnelles des autres agents restent invisibles comme source.")
    else:
        print("[ATTENTION] certaines etapes ont echoue (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi les lignes [ECHEC] pour que je puisse corriger le script.")


if __name__ == "__main__":
    main()
