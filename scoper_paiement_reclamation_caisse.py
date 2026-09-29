#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Un utilisateur ROLE_CAISSE_COMMANDE hérite déjà de ROLE_TRESORERIE_SAISIR
(voir role_hierarchy dans security.yaml) : il pouvait donc déjà accéder
à l'écran de paiement d'une réclamation. Mais le formulaire de paiement
proposait TOUS les comptes de trésorerie actifs, sans distinction --
une caisse pouvait donc payer une réclamation depuis la caisse
personnelle d'un autre agent, voire un compte réservé à
l'administration.

Ce script applique la même règle déjà utilisée ailleurs dans
l'application (paiement d'une commande) : un non-admin ne voit que sa
propre caisse personnelle et les comptes partagés (Orange Money, Wave)
-- jamais la caisse d'un collègue, jamais les comptes admin. Un
administrateur continue de voir tous les comptes actifs.

Modifie 2 fichiers :
  1) src/Form/ReclamationPaiementType.php
  2) src/Controller/ReclamationController.php

Usage:
    python3 scoper_paiement_reclamation_caisse.py /chemin/vers/successImprim
"""

import os
import sys
import shutil
import subprocess


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


PHP_LINT_DISPONIBLE = shutil.which("php") is not None


def lint_php_si_possible(chemin_absolu):
    if not PHP_LINT_DISPONIBLE:
        return
    try:
        resultat = subprocess.run(
            ["php", "-l", chemin_absolu],
            capture_output=True, text=True, timeout=10,
        )
        sortie = (resultat.stdout + resultat.stderr).strip()
        if resultat.returncode == 0:
            print("  php -l : OK")
        else:
            print("  [ATTENTION] php -l a signale un probleme :")
            print("  " + sortie.replace("\n", "\n  "))
    except Exception as exc:
        print("  (php -l ignore : " + repr(exc) + ")")


def appliquer_blocs_verifie(racine, chemin_relatif, marqueur, blocs):
    chemin_absolu = os.path.join(racine, chemin_relatif)

    if not os.path.isfile(chemin_absolu):
        print("[ABSENT] " + chemin_relatif + " n'existe pas du tout sur le disque.")
        return False

    with open(chemin_absolu, "r", encoding="utf-8") as f:
        contenu_original = f.read()

    if marqueur in contenu_original:
        print("[SKIP] " + chemin_relatif + " contient deja '" + marqueur + "' (deja applique).")
        return True

    contenu = contenu_original
    for idx, (ancien, nouveau) in enumerate(blocs, start=1):
        occurrences = contenu.count(ancien)
        if occurrences != 1:
            print("[ECHEC] " + chemin_relatif + " : bloc " + str(idx) + "/" + str(len(blocs)) +
                  " trouve " + str(occurrences) + " fois au lieu de 1 -> abandon (rien ecrit sur ce fichier).")
            print("  Extrait attendu (debut) : " + repr(ancien[:150]))
            return False
        contenu = contenu.replace(ancien, nouveau, 1)

    with open(chemin_absolu, "w", encoding="utf-8", newline="") as f:
        f.write(contenu)
        f.flush()
        os.fsync(f.fileno())

    with open(chemin_absolu, "r", encoding="utf-8", newline="") as f:
        relu = f.read()

    if relu != contenu:
        print("[ECHEC VERIFICATION] " + chemin_relatif + " : le contenu relu ne correspond pas.")
        return False

    print("[OK VERIFIE] " + chemin_relatif + " (" + str(len(blocs)) + " bloc(s) applique(s))")
    print("  Chemin reel : " + os.path.realpath(chemin_absolu))
    lint_php_si_possible(chemin_absolu)
    return True


# ============================================================
# 1) src/Form/ReclamationPaiementType.php
# ============================================================

FORM_BLOC_1_ANCIEN = """<?php

namespace App\\Form;

use App\\Entity\\CompteTresorerie;
use App\\Entity\\MouvementTresorerie;
use App\\Repository\\CompteTresorerieRepository;
use Doctrine\\ORM\\QueryBuilder;
use Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType;
use Symfony\\Component\\Form\\AbstractType;
use Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;
use Symfony\\Component\\Form\\FormBuilderInterface;
use Symfony\\Component\\Validator\\Constraints as Assert;

/**
 * Formulaire (non lié à une entité) utilisé par la caisse pour payer
 * une réclamation validée : choix du compte à débiter et de la
 * catégorie financière du décaissement qui sera créé.
 */
class ReclamationPaiementType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('compteSource', EntityType::class, [
                'label' => 'Compte à débiter',
                'class' => CompteTresorerie::class,
                'query_builder' => static function (
                    CompteTresorerieRepository $repository
                ): QueryBuilder {
                    return $repository->createQueryBuilder('compte')
                        ->andWhere('compte.actif = :actif')
                        ->setParameter('actif', true)
                        ->orderBy('compte.type', 'ASC')
                        ->addOrderBy('compte.nom', 'ASC');
                },"""

FORM_BLOC_1_NOUVEAU = """<?php

namespace App\\Form;

use App\\Entity\\CompteTresorerie;
use App\\Entity\\MouvementTresorerie;
use App\\Entity\\User;
use App\\Repository\\CompteTresorerieRepository;
use Doctrine\\ORM\\QueryBuilder;
use Symfony\\Bridge\\Doctrine\\Form\\Type\\EntityType;
use Symfony\\Component\\Form\\AbstractType;
use Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType;
use Symfony\\Component\\Form\\FormBuilderInterface;
use Symfony\\Component\\OptionsResolver\\OptionsResolver;
use Symfony\\Component\\Validator\\Constraints as Assert;

/**
 * Formulaire (non lié à une entité) utilisé par la caisse pour payer
 * une réclamation validée : choix du compte à débiter et de la
 * catégorie financière du décaissement qui sera créé.
 */
class ReclamationPaiementType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $estAdmin = (bool) $options['est_admin'];
        $utilisateur = $options['utilisateur'];

        $builder
            ->add('compteSource', EntityType::class, [
                'label' => 'Compte à débiter',
                'class' => CompteTresorerie::class,
                'query_builder' => static function (
                    CompteTresorerieRepository $repository
                ) use ($estAdmin, $utilisateur): QueryBuilder {
                    $qb = $repository->createQueryBuilder('compte')
                        ->andWhere('compte.actif = :actif')
                        ->setParameter('actif', true)
                        ->orderBy('compte.type', 'ASC')
                        ->addOrderBy('compte.nom', 'ASC');

                    /*
                     * Un admin peut débiter n'importe quel compte
                     * actif. Une caisse (ROLE_CAISSE_COMMANDE) ne
                     * doit pouvoir payer que depuis sa propre caisse
                     * ou un compte partagé -- jamais la caisse
                     * personnelle d'un autre agent, ni un compte
                     * réservé à l'administration.
                     */
                    if (!$estAdmin) {
                        $qb
                            ->andWhere(
                                '
                                compte.portee = :partagee
                                OR
                                (
                                    compte.portee = :personnelle
                                    AND
                                    compte.proprietaire = :utilisateur
                                )
                                '
                            )
                            ->setParameter('partagee', CompteTresorerie::PORTEE_PARTAGEE)
                            ->setParameter('personnelle', CompteTresorerie::PORTEE_PERSONNELLE)
                            ->setParameter('utilisateur', $utilisateur);
                    }

                    return $qb;
                },"""

FORM_BLOC_2_ANCIEN = """                'attr' => ['class' => 'form-select'],
            ])
        ;
    }
}"""

FORM_BLOC_2_NOUVEAU = """                'attr' => ['class' => 'form-select'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'est_admin' => false,
            'utilisateur' => null,
        ]);

        $resolver->setAllowedTypes('est_admin', 'bool');
        $resolver->setAllowedTypes('utilisateur', [User::class, 'null']);
    }
}"""

FORM_MARQUEUR = "compte.proprietaire = :utilisateur"


# ============================================================
# 2) src/Controller/ReclamationController.php
# ============================================================

CTRL_ANCIEN = """        $form = $this->createForm(ReclamationPaiementType::class);
        $form->handleRequest($request);"""

CTRL_NOUVEAU = """        $form = $this->createForm(ReclamationPaiementType::class, null, [
            'est_admin' => $this->isGranted('ROLE_ADMIN'),
            'utilisateur' => $this->utilisateurConnecte(),
        ]);
        $form->handleRequest($request);"""

CTRL_MARQUEUR = "'utilisateur' => $this->utilisateurConnecte(),"


def main():
    racine = sys.argv[1] if len(sys.argv) >= 2 else "."
    verifier_racine(racine)

    if not PHP_LINT_DISPONIBLE:
        print("(info : commande 'php' introuvable ici, le controle 'php -l' sera saute)")
        print()

    resultats = []

    print("-" * 70)
    print("1) src/Form/ReclamationPaiementType.php")
    print("-" * 70)
    resultats.append(appliquer_blocs_verifie(
        racine,
        "src/Form/ReclamationPaiementType.php",
        FORM_MARQUEUR,
        [
            (FORM_BLOC_1_ANCIEN, FORM_BLOC_1_NOUVEAU),
            (FORM_BLOC_2_ANCIEN, FORM_BLOC_2_NOUVEAU),
        ]
    ))
    print()

    print("-" * 70)
    print("2) src/Controller/ReclamationController.php")
    print("-" * 70)
    resultats.append(appliquer_blocs_verifie(
        racine,
        "src/Controller/ReclamationController.php",
        CTRL_MARQUEUR,
        [
            (CTRL_ANCIEN, CTRL_NOUVEAU),
        ]
    ))
    print()

    print("=" * 70)
    print("RESUME")
    print("=" * 70)

    if all(resultats):
        print("Tout est en place. Lancez maintenant :")
        print("  php bin/console cache:clear")
        print()
        print("Un compte ROLE_CAISSE_COMMANDE pouvait deja payer une reclamation")
        print("(le role inclut deja ROLE_TRESORERIE_SAISIR) -- desormais, le choix")
        print("du compte a debiter ne propose plus que sa propre caisse et les")
        print("comptes partages (Orange Money, Wave), jamais la caisse d'un autre")
        print("agent ni un compte reserve a l'administration.")
    else:
        print("Au moins un fichier n'a pas pu etre modifie (voir [ECHEC] ci-dessus).")
        print("Recopiez-moi TOUT ce resume, je corrige avant de vous renvoyer le script.")


if __name__ == "__main__":
    main()
