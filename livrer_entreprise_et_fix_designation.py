#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deux corrections independantes livrees ensemble :

  1. Nom d'entreprise dans les listes/recherches de clients
     -----------------------------------------------------
     Le libelle du client (dans le formulaire d'ajout d'une commande,
     d'un devis, et dans le filtre "Client" de la liste des
     commandes) affiche desormais aussi la raison sociale quand elle
     est renseignee :
         "77 12 34 56 — Amadou Traore — SOTELMA SA"
     au lieu de juste :
         "77 12 34 56 — Amadou Traore"
     (rien n'affiche si le client n'a pas de raison sociale -- le
     format reste "telephone — prenom nom" comme avant).

  2. Designation videe en saisie libre (BUG)
     ----------------------------------------
     A la modification d'une commande contenant une ligne en
     "Saisie libre", le champ Designation apparaissait vide alors
     qu'une valeur etait bien enregistree. En cause : le script de
     la page videait systematiquement ce champ des qu'une ligne
     etait en mode "libre" -- y compris au simple chargement de la
     page, pas seulement quand l'utilisateur choisissait ce mode
     volontairement. Corrige : le champ n'est plus vide qu'en cas de
     changement reel effectue par l'utilisateur.

Fichiers concernes :
  - src/Form/CommandesType.php
  - src/Form/DevisType.php
  - templates/commandes/_form.html.twig
  - templates/commandes/index.html.twig

Usage:
    python3 livrer_entreprise_et_fix_designation.py /chemin/vers/successImprim
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


def appliquer_paire_texte_multi(racine, chemin_relatif, description, anciens_possibles, nouveau):
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

    for ancien in anciens_possibles:
        if contenu.count(ancien) == 1:
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

    print("  [ECHEC] bloc de reference introuvable (ou trouve plusieurs fois).")
    return False


# ============================================================
# src/Form/CommandesType.php
# ============================================================

ANCIEN_CTYPE_1 = """                $nom = trim(
                    (string) $client->getNom()
                );

                return trim(sprintf(
                    '%s — %s %s',
                    $telephone,
                    $prenom,
                    $nom
                ));"""

ANCIEN_CTYPE_2 = """                $nom = trim(
                    (string) $client->getNom()
                );

                 $raisonsSociales = trim(
                    (string) $client->getRaisonSociale()
                );

                return trim(sprintf(
                    '%s — %s %s',
                    $telephone,
                    $prenom,
                    $nom,
                    $raisonsSociales
                ));"""

NOUVEAU_CTYPE = """                $nom = trim(
                    (string) $client->getNom()
                );

                $raisonSociale = trim(
                    (string) $client->getRaisonSociale()
                );

                $libelle = trim(sprintf(
                    '%s — %s %s',
                    $telephone,
                    $prenom,
                    $nom
                ));

                if ($raisonSociale !== '') {
                    $libelle .= ' — ' . $raisonSociale;
                }

                return $libelle;"""


# ============================================================
# src/Form/DevisType.php
# ============================================================

ANCIEN_DTYPE = """                    $nom = trim(
                        (string) $client->getNom()
                    );

                    return trim(sprintf(
                        '%s — %s %s',
                        $telephone,
                        $prenom,
                        $nom
                    ));
                },"""

NOUVEAU_DTYPE = """                    $nom = trim(
                        (string) $client->getNom()
                    );

                    $raisonSociale = trim(
                        (string) $client->getRaisonSociale()
                    );

                    $libelle = trim(sprintf(
                        '%s — %s %s',
                        $telephone,
                        $prenom,
                        $nom
                    ));

                    if ($raisonSociale !== '') {
                        $libelle .= ' — ' . $raisonSociale;
                    }

                    return $libelle;
                },"""


# ============================================================
# templates/commandes/_form.html.twig
# ============================================================

ANCIEN_FORM_DESIGNATION = """if (libre) {

if (designation) {
designation.readOnly = false;
designation.value = '';
}"""

NOUVEAU_FORM_DESIGNATION = """if (libre) {

if (designation) {
designation.readOnly = false;

/*
 * On ne vide la designation que lors d'un vrai changement de
 * type par l'utilisateur (pas au chargement initial d'une ligne
 * existante en saisie libre, sinon la designation deja
 * enregistree disparait a chaque ouverture du formulaire de
 * modification).
 */
if (viaChangementUtilisateur) {
designation.value = '';
}
}"""


# ============================================================
# templates/commandes/index.html.twig
# ============================================================

ANCIEN_INDEX_CLIENT = """									<option value="{{ client.id }}" {{ filtres.client|default('') == client.id ? 'selected' : '' }}>
											{{ client }}
										</option>"""

NOUVEAU_INDEX_CLIENT = """									<option value="{{ client.id }}" {{ filtres.client|default('') == client.id ? 'selected' : '' }}>
											{{ client.telephone|default('Sans téléphone') }} — {{ (client.prenom|default('') ~ ' ' ~ client.nom|default(''))|trim }}{% if client.raisonSociale %} — {{ client.raisonSociale }}{% endif %}
										</option>"""


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    resultats = []

    print("--- Nom d'entreprise dans le libelle client ---")
    resultats.append(appliquer_paire_texte_multi(
        racine, "src/Form/CommandesType.php",
        "ajoute la raison sociale au libelle du client",
        [ANCIEN_CTYPE_1, ANCIEN_CTYPE_2], NOUVEAU_CTYPE
    ))
    resultats.append(appliquer_paire_texte_multi(
        racine, "src/Form/DevisType.php",
        "ajoute la raison sociale au libelle du client",
        [ANCIEN_DTYPE], NOUVEAU_DTYPE
    ))
    resultats.append(appliquer_paire_texte_multi(
        racine, "templates/commandes/index.html.twig",
        "ajoute telephone et raison sociale au filtre 'Client'",
        [ANCIEN_INDEX_CLIENT], NOUVEAU_INDEX_CLIENT
    ))

    print("\n--- Correction : designation videe en saisie libre ---")
    resultats.append(appliquer_paire_texte_multi(
        racine, "templates/commandes/_form.html.twig",
        "ne vide la designation que lors d'un vrai changement utilisateur",
        [ANCIEN_FORM_DESIGNATION], NOUVEAU_FORM_DESIGNATION
    ))

    print()
    if all(resultats):
        print("=" * 70)
        print("LIVRE AVEC SUCCES.")
        print("=" * 70)
        print()
        print("Aucune migration necessaire. Videz le cache :")
        print()
        print("    php bin/console cache:clear")
        print()
        print("Utilisation :")
        print("  1. Sur le formulaire d'ajout d'une commande, d'un devis, et dans")
        print("     le filtre 'Client' de la liste des commandes, le nom de")
        print("     l'entreprise apparait desormais a cote du telephone et du nom")
        print("     du client (quand elle est renseignee).")
        print("  2. En modification d'une commande, une ligne en 'Saisie libre'")
        print("     garde maintenant sa designation deja enregistree au lieu de")
        print("     l'afficher vide.")
    else:
        print("[ATTENTION] certaines etapes ont echoue (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi les lignes [ECHEC] pour que je puisse corriger le script.")


if __name__ == "__main__":
    main()
