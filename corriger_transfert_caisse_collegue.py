#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Corrige le transfert d'argent vers la caisse personnelle d'un
collegue (BUG).

Un correctif precedent (livrer_transfert_comptes_perso.py) avait
modifie le FORMULAIRE (MouvementTresorerieType.php) pour proposer
n'importe quelle caisse personnelle comme destination d'un
transfert -- y compris celle d'un collegue.

Mais le CONTROLEUR (MouvementTresorerieController.php) n'avait pas
ete mis a jour en meme temps : sa propre verification de securite
(utilisateurPeutTransfererVersCompte) refusait encore tout transfert
vers une caisse personnelle qui n'appartient ni a l'utilisateur, ni
a un Admin.

Resultat concret : le formulaire laissait choisir la caisse d'un
collegue, mais la validation finale du transfert echouait avec
l'erreur "Vous n'etes pas autorise a transferer de l'argent vers le
compte ...".

Ce script corrige uniquement le controleur, pour que la verification
corresponde enfin a ce que le formulaire propose deja. Rien d'autre
ne change : un agent ne peut toujours retirer de l'argent que de SA
PROPRE caisse (la source reste inchangee).

Fichier concerne :
  - src/Controller/MouvementTresorerieController.php

Usage:
    python3 corriger_transfert_caisse_collegue.py /chemin/vers/successImprim
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
# src/Controller/MouvementTresorerieController.php
# ============================================================

ANCIEN = """        /*
         * ========================================================
         * PERSONNEL
         * ========================================================
         *
         * Un transfert entrant vers une caisse personnelle
         * est possible :
         *
         * - par son propriétaire ;
         * - par l'Admin.
         *
         * Exemple :
         *
         * Caisse Administration
         * → Caisse Mariam
         *
         * approvisionnement de caisse.
         * ========================================================
         */

        if (
            $compte->estPersonnel()
        ) {
            return
                $compte
                    ->appartientA(
                        $user
                    )
                ||
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }"""

NOUVEAU = """        /*
         * ========================================================
         * PERSONNEL
         * ========================================================
         *
         * Un transfert entrant vers une caisse personnelle est
         * toujours possible, y compris vers la caisse d'un
         * collègue (remise en main propre entre deux agents) : le
         * formulaire (MouvementTresorerieType) propose déjà
         * n'importe quelle caisse personnelle comme destination, et
         * le droit général d'effectuer un transfert a été vérifié
         * plus haut (ROLE_TRESORERIE_TRANSFERER ou ROLE_ADMIN).
         *
         * Exemples :
         *
         * Caisse Administration → Caisse Mariam (approvisionnement)
         * Caisse Mariam → Caisse Ahmed (remise en main propre)
         * ========================================================
         */

        if (
            $compte->estPersonnel()
        ) {
            return true;
        }"""


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    resultats = []

    print("--- MouvementTresorerieController ---")
    resultats.append(appliquer_paire_texte(
        racine, "src/Controller/MouvementTresorerieController.php",
        "autorise le transfert vers la caisse personnelle d'un collegue",
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
        print("Un agent peut desormais reellement transferer de l'argent de SA")
        print("propre caisse vers la caisse personnelle d'un collegue -- le")
        print("formulaire le proposait deja, mais le controleur le refusait a")
        print("tort au moment de valider.")
    else:
        print("[ATTENTION] certaines etapes ont echoue (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi les lignes [ECHEC] pour que je puisse corriger le script.")


if __name__ == "__main__":
    main()
