<?php

namespace App\Service\Guide;

/**
 * Formation d'une demi-journée par rôle (docs/formation.md) : blocs de
 * 30 minutes, exercices sur les comptes de démonstration et résultat
 * attendu, exercice final. Affichée à la Direction par « Fiches du
 * formateur » (/aide/formation), à imprimer pour le formateur.
 *
 * Les clés des blocs « fiches » sont celles de CatalogueGuide.
 */
final class Formation
{
    /**
     * @return array<string, array{titre: string, compte: string, duree: string, objectif: string, blocs: list<array{heure: string, titre: string, fiches: list<string>, faire: string}>, exercices: list<array{titre: string, compte: string, consigne: list<string>, attendu: string}>, final: array{titre: string, consigne: list<string>, attendu: string}}>
     */
    public static function plans(): array
    {
        return [
            'caisse' => [
                'titre' => 'Caisse et commercial',
                'compte' => 'demo.aminata',
                'duree' => '4 h',
                'objectif' => 'Faire seul un devis, la commande, l\'encaissement et la facture d\'un nouveau client (jalon J7).',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Se connecter et se repérer',
                        'fiches' => ['se-connecter'],
                        'faire' => 'Connexion, menu court, « Aide », « Aller à… ».',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'Les clients',
                        'fiches' => ['client-trouver-creer', 'fiche-client'],
                        'faire' => 'Exercices 1 et 2.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Le devis',
                        'fiches' => ['devis-faire'],
                        'faire' => 'Exercice 3.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'La commande',
                        'fiches' => ['devis-en-commande', 'commande-directe', 'ventes-suivre', 'hors-imprimerie'],
                        'faire' => 'Exercice 4. Montrer la case « Hors imprimerie » d\'un devis de matériel.',
                    ],
                    [
                        'heure' => '10:00',
                        'titre' => 'L\'encaissement et la facture',
                        'fiches' => ['encaisser', 'facturer-envoyer', 'facture-paiement'],
                        'faire' => 'Exercices 5 à 7.',
                    ],
                    [
                        'heure' => '10:30',
                        'titre' => 'Pause, puis la liste des factures, l\'avoir et les dépenses',
                        'fiches' => ['factures-liste', 'avoir-faire', 'documents-lies', 'depense-saisir'],
                        'faire' => 'Exercices 8 et 9.',
                    ],
                    [
                        'heure' => '11:00',
                        'titre' => 'Le virement, le site, le client et le livreur',
                        'fiches' => ['caisse-virement', 'commande-en-ligne', 'acces-espace-client', 'encaissement-livreur', 'mes-actions'],
                        'faire' => 'Exercices 10 à 13.',
                    ],
                    [
                        'heure' => '11:30',
                        'titre' => 'Exercice final J7, puis clôture de la caisse',
                        'fiches' => ['caisse-cloture'],
                        'faire' => 'Exercice final, seul, sans aide. Puis la clôture, avec le formateur.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Retrouver un client',
                        'compte' => 'demo.aminata',
                        'consigne' => ['Menu « Clients ».', 'Cherchez « Salon Espoir » et ouvrez sa fiche.'],
                        'attendu' => 'La fiche s\'ouvre ; l\'employé lit le nombre de commandes et la dernière commande.',
                    ],
                    [
                        'titre' => 'Créer un client particulier',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            '« Nouveau client », type Particulier.',
                            'Prénom, Nom, un numéro fictif à 8 chiffres.',
                            '« Enregistrer et créer un devis ».',
                        ],
                        'attendu' => 'L\'écran « Nouveau devis » s\'ouvre avec ce client.',
                    ],
                    [
                        'titre' => 'Faire le devis',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Une ligne « Bâche imprimée (démo) », 300 × 100 cm, « Œillets tous les 50 cm ».',
                            '« Enregistrer le brouillon », puis « Aperçu PDF ».',
                        ],
                        'attendu' => 'Le devis DEV-… est en « Brouillon », pour 10 500 F, et le PDF s\'ouvre.',
                    ],
                    [
                        'titre' => 'Passer le devis en commande',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            '« Envoyer au client » (le devis passe en « Envoyé »).',
                            'Quand le client accepte : « Convertir en commande », puis confirmer.',
                        ],
                        'attendu' => 'La commande CMD-… s\'ouvre avec la frise, de « Confirmée » à « Livrée ».',
                    ],
                    [
                        'titre' => 'Encaisser un acompte',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Menu « Caisse » : le client, sa commande.',
                            '5 000 F, Espèces, compte « Caisse imprimerie ».',
                            '« Encaisser et imprimer le reçu ».',
                        ],
                        'attendu' => 'Message « Paiement de 5 000 FCFA enregistré », reçu R-…, et « Encaissé » de la caisse du jour augmente de 5 000 F.',
                    ],
                    [
                        'titre' => 'Faire la facture',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Sur la commande, « … » (autres actions) › « Facturer ».',
                            'Vérifiez le brouillon, puis « Valider et numéroter » et « Valider ».',
                        ],
                        'attendu' => 'La facture validée s\'ouvre avec son numéro définitif et « Validée » ; « Envoyer sur WhatsApp » et « Enregistrer un paiement » sont proposés.',
                    ],
                    [
                        'titre' => 'Encaisser le reste depuis la facture',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Sur la facture de l\'exercice 6, « Enregistrer un paiement ».',
                            'Orange Money, le reste prérempli, une référence fictive, « Reçu à distance : à confirmer par la trésorière » cochée.',
                            '« Enregistrer · générer le reçu ».',
                        ],
                        'attendu' => 'La facture revient avec « Paiement de … enregistré » et « à confirmer » ; le bouton « Envoyer le reçu par WhatsApp (en cours de validation) » est prêt (ne pas l\'envoyer : le numéro est fictif).',
                    ],
                    [
                        'titre' => 'Préparer un avoir',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Encaissements › onglet « Facturation » : retrouvez la facture de l\'exercice 6.',
                            '« Créer un avoir », « Erreur de prix », une quantité à créditer, « Garder pour une prochaine commande ».',
                            'Lisez « Effet sur le compte client », puis « Annuler ».',
                            'Ouvrez « Voir tous les documents liés ».',
                        ],
                        'attendu' => 'L\'employé dit le total de l\'avoir et le solde dû après, sans valider ; il sait qu\'une facture validée ne se modifie jamais. Les documents liés montrent devis, commande, facture et paiement.',
                    ],
                    [
                        'titre' => 'Saisir une dépense',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            '« Aller à… » › « Nouveau › Dépense ».',
                            'Photo d\'un reçu, objet « Carburant moto livraison », Divers, 2 000 F, payé avec « Caisse imprimerie ».',
                            '« Enregistrer la dépense ».',
                        ],
                        'attendu' => 'Message : le décaissement attend sa validation dans « À valider ». Le formateur (demo.kadiatou) la voit dans Finances › À valider.',
                    ],
                    [
                        'titre' => 'Verser la caisse à la banque',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Caisse › « Virement ».',
                            '50 000 F, de « Caisse imprimerie » vers « Banque BDM », un libellé.',
                            '« Enregistrer le mouvement », « Oui, enregistrer ».',
                        ],
                        'attendu' => 'Retour à la caisse avec « Virement de 50 000 F … enregistré ». Aucun solde n\'est affiché.',
                    ],
                    [
                        'titre' => 'Confirmer une commande du site',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Ouvrez la commande CMD-DC0005 (Salon Espoir).',
                            '« Vérifier et confirmer » : la ligne « Affiche A3 · A2 » est à 0 F, son prix est confirmé par l\'atelier. Saisissez 500 F dans « Prix unitaire ».',
                            '« Confirmer la commande ».',
                        ],
                        'attendu' => 'Message « La commande a été modifiée avec succès. » ; le bandeau « Commande passée par le client dans son espace » a disparu : la commande part à l\'atelier.',
                    ],
                    [
                        'titre' => 'Envoyer l\'accès à l\'espace client',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Fiche du client créé à l\'exercice 2 (pas une fiche DEMO-).',
                            '« Envoyer l\'accès à l\'espace client ».',
                        ],
                        'attendu' => '« Accès prêt pour … » avec l\'identifiant, le code et la durée de 48 h. Ne pas l\'envoyer : le numéro est fictif.',
                    ],
                    [
                        'titre' => 'Valider l\'argent d\'un livreur',
                        'compte' => 'demo.aminata',
                        'consigne' => [
                            'Le formateur fait une remise avec encaissement en espèces (demo.bakary, sur téléphone).',
                            'Planning des livraisons › la livraison remise › « Compte qui reçoit » › « Valider l\'encaissement ».',
                        ],
                        'attendu' => 'L\'encaissement n\'est plus provisoire ; « À encaisser à la livraison » le compte comme encaissé.',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final J7 : un client, de bout en bout',
                    'consigne' => [
                        'Seul, sans aide et sans le guide : un nouveau client particulier.',
                        'Un devis : bâche 200 × 100 cm avec œillets.',
                        'Le devis accepté, la commande créée.',
                        'Un acompte de 50 % en espèces, le reçu imprimé.',
                        'La facture, validée et numérotée.',
                    ],
                    'attendu' => 'En 20 minutes : client, devis envoyé puis converti, commande CMD-…, message « Paiement de … enregistré », reçu R-…, facture FAC-…. Le formateur vérifie le paiement dans « Encaissements ». Réussi : le jalon J7 est atteint pour cette personne.',
                ],
            ],
            'graphiste' => [
                'titre' => 'Graphiste PAO',
                'compte' => 'demo.seydou',
                'duree' => '4 h',
                'objectif' => 'Déposer et contrôler les fichiers d\'une commande, relancer ceux qui manquent, envoyer un BAT, monter une feuille, et faire un devis ou une commande.',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Se connecter et se repérer',
                        'fiches' => ['se-connecter'],
                        'faire' => 'Le menu replié : « Fichiers et BAT », « Mes travaux PAO », « Aide ».',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'La liste de la prépresse, le dépôt et le contrôle',
                        'fiches' => ['controle-fichiers', 'prepresse-depot'],
                        'faire' => 'Exercices 1 à 3.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Le tableau, les relances et les lignes retirées',
                        'fiches' => ['prepresse-tableau', 'prepresse-relances', 'prepresse-retirer'],
                        'faire' => 'Exercices 4 et 5.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'Mon tableau PAO et le BAT',
                        'fiches' => ['poste-bat'],
                        'faire' => 'Exercice 6.',
                    ],
                    [
                        'heure' => '10:00',
                        'titre' => 'Le montage',
                        'fiches' => ['montage'],
                        'faire' => 'Exercice 7.',
                    ],
                    [
                        'heure' => '10:30',
                        'titre' => 'Pause, puis demandes de conception et créations du site',
                        'fiches' => ['demandes-conception', 'creations-studio'],
                        'faire' => 'Exercice 8.',
                    ],
                    [
                        'heure' => '11:00',
                        'titre' => 'Devis, commandes et facture ; ce que le graphiste ne voit pas',
                        'fiches' => ['devis-faire', 'devis-en-commande', 'ventes-suivre', 'facturer-envoyer'],
                        'faire' => 'Exercice 9. Les paiements ne s\'affichent pas ; « Encaisser » seulement si la Direction ajoute « Encaissements » sur la fiche.',
                    ],
                    [
                        'heure' => '11:30',
                        'titre' => 'Exercice final',
                        'fiches' => [],
                        'faire' => 'Seul, sans aide.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Déposer les fichiers d\'une commande',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Fichiers et BAT », onglet « Sans fichier » : « Déposer » sur CMD-PQ0378 (Fofana, étiquettes).',
                            'Glissez les fichiers d\'exemple donnés par le formateur (le format est dans leur nom, par exemple « etiquette_5,5x5cm.pdf »).',
                            'Vérifiez « Associé par », puis « Enregistrer · passer en « À contrôler » ».',
                        ],
                        'attendu' => 'Chaque fichier est associé à un format ; la commande passe dans l\'onglet « À contrôler » et sa barre montre les fichiers reçus.',
                    ],
                    [
                        'titre' => 'Contrôler un fichier',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Fichiers et BAT » › onglet « Tout » : cherchez CMD-D00767 (T-shirt DTF), dépliez-la, « Contrôler ».',
                            'Cochez les points vérifiés, puis « Enregistrer ».',
                        ],
                        'attendu' => 'Le contrôle est gardé : l\'historique du travail montre demo.seydou.',
                    ],
                    [
                        'titre' => 'Envoyer en production',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            'Onglet « À contrôler » : « Contrôler » sur le kakémono à livrer aujourd\'hui.',
                            '« Tout cocher », puis « Valider et envoyer en production ».',
                        ],
                        'attendu' => 'Retour à la liste de la prépresse avec « Document transmis à la production (ordre …) » ; la commande a quitté « À contrôler ».',
                    ],
                    [
                        'titre' => 'Relancer un client',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Relances » : cochez CMD-PQ0378 (Fofana), « Relancer la sélection ».',
                            'Lisez le message, sans l\'envoyer.',
                        ],
                        'attendu' => 'WhatsApp s\'ouvre avec le message qui liste les formats manquants ; la relance est notée sur la ligne.',
                    ],
                    [
                        'titre' => 'Retirer une ligne et la remettre',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Liste », onglet « Sans fichier » : « Retirer » sur CMD-PQ0379 (Comptoir), puis « Retirer ».',
                            '« Retirées · 1 », puis « Remettre dans la liste ».',
                        ],
                        'attendu' => 'La commande quitte la liste, apparaît dans « Retirées » avec la date et le nom, puis revient ; elle n\'a été ni clôturée ni supprimée.',
                    ],
                    [
                        'titre' => 'Envoyer un BAT',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Mes travaux PAO » : le travail en cours.',
                            '« Pause », puis reprise ; « Envoyer le BAT ».',
                        ],
                        'attendu' => 'Le travail passe dans la colonne « BAT envoyé ».',
                    ],
                    [
                        'titre' => 'Monter une carte de visite',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            'Depuis un contrôle, « Monter » (ou l\'exemple « Exemple carte de visite »).',
                            'Comparez A4 et SRA3.',
                        ],
                        'attendu' => '« Faisable » ; 10 poses en A4, 25 en SRA3 ; « PDF du montage » se télécharge.',
                    ],
                    [
                        'titre' => 'Prendre une demande de conception',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            '« Voir › » dans le bandeau rouge.',
                            'Ouvrez DC-D001 (Salon Espoir), « Prendre en charge ».',
                        ],
                        'attendu' => 'La demande est « En cours » à votre nom.',
                    ],
                    [
                        'titre' => 'Faire un devis',
                        'compte' => 'demo.seydou',
                        'consigne' => [
                            'Devis et commandes › « Nouveau devis » pour Salon Espoir.',
                            'Un roll-up, « Enregistrer le brouillon ».',
                        ],
                        'attendu' => 'Le devis DEV-… est en « Brouillon ».',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final : un fichier prêt à imprimer',
                    'consigne' => [
                        'Déposer les fichiers d\'une commande sans fichier.',
                        'Contrôler un fichier et l\'envoyer en production.',
                        'Envoyer le BAT du travail en cours.',
                        'Monter le fichier et sortir le PDF du montage.',
                    ],
                    'attendu' => 'Les fichiers sont associés, le contrôle validé (« Document transmis à la production »), le BAT envoyé, le PDF du montage téléchargé. Sans ouvrir la caisse ni voir de paiement.',
                ],
            ],
            'production' => [
                'titre' => 'Opérateur production',
                'compte' => 'demo.oumar',
                'duree' => '4 h',
                'objectif' => 'Faire avancer les travaux de sa machine : démarrer, noter la gâche, terminer.',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Se connecter et se repérer',
                        'fiches' => ['se-connecter'],
                        'faire' => 'Le menu : « Production », « Ma file machine ».',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'Le tableau de production',
                        'fiches' => ['tableau-production'],
                        'faire' => 'Exercice 1.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Le planning des machines',
                        'fiches' => ['planning-machines'],
                        'faire' => 'Exercice 2.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'Ma file machine, au poste de la machine',
                        'fiches' => ['ma-file'],
                        'faire' => 'Exercice 3.',
                    ],
                    [
                        'heure' => '10:00',
                        'titre' => 'Gâche, incident, réimpression',
                        'fiches' => ['ma-file'],
                        'faire' => 'Exercice 4.',
                    ],
                    [
                        'heure' => '10:30',
                        'titre' => 'Pause, puis le pointage',
                        'fiches' => ['pointer'],
                        'faire' => 'Exercice 5.',
                    ],
                    [
                        'heure' => '11:00',
                        'titre' => 'Mon espace : absence, prime, statistiques',
                        'fiches' => ['absence-conge', 'ma-prime', 'mes-statistiques'],
                        'faire' => 'Lire sa prime estimée et ses points.',
                    ],
                    [
                        'heure' => '11:30',
                        'titre' => 'Exercice final',
                        'fiches' => [],
                        'faire' => 'Seul, au poste de la machine.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Trouver un travail',
                        'compte' => 'demo.oumar',
                        'consigne' => ['« Production » : cherchez CMD-D00764.'],
                        'attendu' => 'L\'employé dit dans quelle colonne il est (son étape).',
                    ],
                    [
                        'titre' => 'Lire la charge des machines',
                        'compte' => 'demo.oumar',
                        'consigne' => ['Menu « Atelier », onglet « Planning ».'],
                        'attendu' => 'L\'employé dit quelle machine est la plus occupée cette semaine.',
                    ],
                    [
                        'titre' => 'Démarrer et mettre en pause',
                        'compte' => 'demo.oumar',
                        'consigne' => [
                            'Au poste de la machine : « Ma file machine ».',
                            'Touchez un travail, « Démarrer », puis « Pause », puis « Reprendre ».',
                        ],
                        'attendu' => 'L\'écran dit « Poste de » la machine ; le travail passe en cours, en pause, puis en cours.',
                    ],
                    [
                        'titre' => 'Noter la gâche et terminer',
                        'compte' => 'demo.oumar',
                        'consigne' => [
                            '« + Gâche » sur le travail en cours.',
                            '« Terminer » : « Quantité bonne », « Rebut », « Confirmer la fin ».',
                        ],
                        'attendu' => 'Le travail quitte la file ; les m² du jour augmentent.',
                    ],
                    [
                        'titre' => 'Pointer',
                        'compte' => 'demo.oumar',
                        'consigne' => ['Sur le téléphone : « Pointer », l\'affiche, la photo.'],
                        'attendu' => '« Arrivée enregistrée » (après la validation du téléphone par la RH).',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final : un travail du début à la fin',
                    'consigne' => [
                        'Au poste de la machine, sans aide : démarrer le travail suivant.',
                        'Noter une gâche.',
                        'Le terminer avec la quantité bonne.',
                    ],
                    'attendu' => 'Le travail est terminé, la gâche est notée, et l\'employé sait dire ce qui change hors du poste (« Consultation seulement »).',
                ],
            ],
            'livreur' => [
                'titre' => 'Livreur',
                'compte' => 'demo.bakary',
                'duree' => '4 h',
                'objectif' => 'Faire sa tournée sur le téléphone et prouver chaque remise.',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Se connecter sur le téléphone',
                        'fiches' => ['se-connecter'],
                        'faire' => 'La barre du bas : Tournée, Livrer, Pointage, Moi.',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'Ma tournée du jour',
                        'fiches' => ['tournee'],
                        'faire' => 'Exercice 1.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Charger et partir',
                        'fiches' => ['tournee'],
                        'faire' => 'Exercice 2.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'Remettre la commande',
                        'fiches' => ['remise'],
                        'faire' => 'Exercice 3.',
                    ],
                    [
                        'heure' => '10:00',
                        'titre' => 'L\'argent encaissé à la livraison',
                        'fiches' => ['remise', 'encaissement-livreur'],
                        'faire' => 'Le livreur rend l\'argent à la caisse, qui le valide le jour même.',
                    ],
                    [
                        'heure' => '10:30',
                        'titre' => 'Pause, puis les commandes à retirer',
                        'fiches' => ['a-retirer'],
                        'faire' => 'Exercice 4.',
                    ],
                    [
                        'heure' => '11:00',
                        'titre' => 'Pointer et Mon espace',
                        'fiches' => ['pointer', 'absence-conge'],
                        'faire' => 'Exercice 5.',
                    ],
                    [
                        'heure' => '11:30',
                        'titre' => 'Exercice final',
                        'fiches' => [],
                        'faire' => 'Seul, sur le téléphone.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Lire sa tournée',
                        'compte' => 'demo.bakary',
                        'consigne' => ['« Tournée » : l\'arrêt 1.'],
                        'attendu' => 'L\'employé dit le client, le créneau et l\'adresse.',
                    ],
                    [
                        'titre' => 'Charger et partir',
                        'compte' => 'demo.bakary',
                        'consigne' => ['Sur l\'arrêt « Préparée » : « Chargée », puis « Je pars ».'],
                        'attendu' => 'L\'arrêt passe « En route ».',
                    ],
                    [
                        'titre' => 'Faire une remise',
                        'compte' => 'demo.bakary',
                        'consigne' => [
                            'Sur l\'arrêt « En route » : « Remise ».',
                            'Signature, Espèces, montant, « Valider la remise ».',
                        ],
                        'attendu' => 'La page de livraison terminée s\'affiche ; à l\'imprimerie, la caisse voit l\'encaissement à valider.',
                    ],
                    [
                        'titre' => 'Relancer un client',
                        'compte' => 'demo.bakary',
                        'consigne' => [
                            '« À livrer ou retirer » : une commande prête depuis 2 jours ou plus.',
                            '« WhatsApp » (ne pas envoyer pendant la formation).',
                        ],
                        'attendu' => 'WhatsApp s\'ouvre avec le message prêt.',
                    ],
                    [
                        'titre' => 'Pointer',
                        'compte' => 'demo.bakary',
                        'consigne' => ['« Pointage » en bas, l\'affiche, la photo.'],
                        'attendu' => '« Arrivée enregistrée » (après la validation du téléphone par la RH).',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final : une livraison complète',
                    'consigne' => [
                        'Seul : « Chargée », « Je pars », « Je suis arrivé ».',
                        '« Remise » avec signature et encaissement en espèces.',
                        'Retour à l\'imprimerie : la caisse valide l\'argent.',
                    ],
                    'attendu' => 'Livraison terminée, encaissement validé par la caisse avec « Valider l\'encaissement ».',
                ],
            ],
            'entretien' => [
                'titre' => 'Entretien',
                'compte' => 'demo.awa',
                'duree' => '2 h',
                'objectif' => 'Faire les tâches d\'entretien du jour et les valider par une photo.',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Se connecter sur le téléphone',
                        'fiches' => ['se-connecter'],
                        'faire' => 'La barre du bas : Accueil, Entretien, Pointage, Moi.',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'Mon planning d\'entretien',
                        'fiches' => ['entretien-taches'],
                        'faire' => 'Exercice 1.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Pointer et Mon espace',
                        'fiches' => ['pointer', 'absence-conge'],
                        'faire' => 'Exercice 2.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'Exercice final',
                        'fiches' => [],
                        'faire' => 'Seul, sur une vraie tâche de la journée.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Valider une tâche',
                        'compte' => 'demo.awa',
                        'consigne' => [
                            'Ouvrez la tâche « Ménage de l\'accueil ».',
                            'Cochez les étapes, « Prendre la photo et valider ».',
                        ],
                        'attendu' => 'La tâche est « fait à … · photo » dans le planning, avec ses points.',
                    ],
                    [
                        'titre' => 'Déclarer une absence',
                        'compte' => 'demo.awa',
                        'consigne' => ['« Moi » › « Déclarer une absence ».', 'Dates, motif, « Envoyer à la RH ».'],
                        'attendu' => 'La demande apparaît dans « Mes demandes ».',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final : une tâche réelle',
                    'consigne' => ['Seule : la tâche suivante du planning, ses étapes, la photo.'],
                    'attendu' => 'La tâche est validée à l\'heure, avec la photo.',
                ],
            ],
            'direction' => [
                'titre' => 'Direction',
                'compte' => 'demo.kadiatou',
                'duree' => '4 h',
                'objectif' => 'Suivre l\'activité, valider, gérer les accès, les réglages et les achats aux fournisseurs.',
                'blocs' => [
                    [
                        'heure' => '08:00',
                        'titre' => 'Le tableau de bord et les statistiques',
                        'fiches' => ['tableau-de-bord', 'statistiques-activite'],
                        'faire' => 'Exercices 1 et 2.',
                    ],
                    [
                        'heure' => '08:30',
                        'titre' => 'Les opérations à valider',
                        'fiches' => ['a-valider'],
                        'faire' => 'Exercice 3.',
                    ],
                    [
                        'heure' => '09:00',
                        'titre' => 'Utilisateurs et droits',
                        'fiches' => ['utilisateur-nouveau', 'droit-une-personne'],
                        'faire' => 'Exercices 4 et 5.',
                    ],
                    [
                        'heure' => '09:30',
                        'titre' => 'Les réglages',
                        'fiches' => ['parametres'],
                        'faire' => 'Exercice 6.',
                    ],
                    [
                        'heure' => '10:00',
                        'titre' => 'Les finances',
                        'fiches' => ['finances', 'encaissements-suivre', 'factures-liste', 'avoir-faire'],
                        'faire' => 'Lire le mois : soldes, facturé, encaissé, dépenses, marge ; la liste des factures et les avoirs à valider.',
                    ],
                    [
                        'heure' => '10:30',
                        'titre' => 'Pause, puis fournisseurs et achats',
                        'fiches' => ['fournisseurs', 'commande-achat', 'reception-achat', 'couts-marges'],
                        'faire' => 'Exercices 7 à 9.',
                    ],
                    [
                        'heure' => '11:00',
                        'titre' => 'Sauvegardes, synchronisation et import',
                        'fiches' => ['sauvegardes-synchro', 'import-donnees'],
                        'faire' => 'Exercices 10 et 11.',
                    ],
                    [
                        'heure' => '11:30',
                        'titre' => 'Exercice final',
                        'fiches' => [],
                        'faire' => 'Seul.',
                    ],
                ],
                'exercices' => [
                    [
                        'titre' => 'Lire le tableau de bord',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['« Accueil » › « Tableau de bord ».'],
                        'attendu' => 'La personne dit le CA facturé du mois et les créances clients.',
                    ],
                    [
                        'titre' => 'Comparer imprimerie et hors imprimerie',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Accueil › « Statistiques et rapports ».', 'Choisissez « Activité : Tout ».'],
                        'attendu' => 'La personne lit la carte « Imprimerie / Hors imprimerie / Total » et sait que les objectifs et les primes ne comptent que l\'imprimerie.',
                    ],
                    [
                        'titre' => 'Valider et rejeter',
                        'compte' => 'demo.kadiatou',
                        'consigne' => [
                            'Finances › « À valider ».',
                            'Cochez le chèque de Pharmacie Faso, « Valider la sélection ».',
                            'Cochez une autre ligne, « Rejeter » avec un motif.',
                        ],
                        'attendu' => 'Les deux lignes quittent la liste ; l\'auteur du rejet est prévenu.',
                    ],
                    [
                        'titre' => 'Créer un accès',
                        'compte' => 'demo.kadiatou',
                        'consigne' => [
                            'Réglages › « Utilisateurs et rôles » › « + Nouvel utilisateur ».',
                            'Rôle « Caisse et commercial », « Créer et envoyer l\'accès ».',
                        ],
                        'attendu' => 'Le mot de passe temporaire s\'affiche, avec le bouton WhatsApp.',
                    ],
                    [
                        'titre' => 'Ajouter un droit à une personne',
                        'compte' => 'demo.kadiatou',
                        'consigne' => [
                            'Fiche de demo.aissata (Graphiste PAO) : cochez « Encaissements », « Enregistrer ».',
                            'Vérifiez avec demo.aissata : « Caisse » s\'ouvre.',
                            'Décochez, « Enregistrer ».',
                        ],
                        'attendu' => '« Droits de … enregistrés. » ; la case est marquée « ajouté pour cette personne », puis revient à « non accordé ».',
                    ],
                    [
                        'titre' => 'Trouver un réglage',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Réglages : tapez « Orange Money » dans « Que voulez-vous régler ? ».'],
                        'attendu' => 'La tuile Paiements s\'ouvre.',
                    ],
                    [
                        'titre' => 'Lire un fournisseur',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Stock et achats › « Fournisseurs » › Dakar Signs.'],
                        'attendu' => 'La personne dit son délai réel moyen, sa part à l\'heure, ce qui lui est dû, et lit le « Conseil » sur le délai.',
                    ],
                    [
                        'titre' => 'Préparer une commande d\'achat',
                        'compte' => 'demo.kadiatou',
                        'consigne' => [
                            'Stock et achats › « Fournisseurs » : touchez une « Prochaine commande » en rouge.',
                            'Lisez le total rendu Bamako, le coût de revient et la livraison prévue, puis « Brouillon ».',
                        ],
                        'attendu' => 'La commande CA-… est enregistrée en brouillon chez le bon fournisseur, avec l\'article en alerte ; rien n\'est envoyé.',
                    ],
                    [
                        'titre' => 'Lire une réception avec casse',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Stock et achats › « Réceptions » : « Voir » sur la réception de Dakar Signs marquée « paiement bloqué ».'],
                        'attendu' => 'La personne lit le rouleau écrasé, la réclamation ouverte et « paiement du solde bloqué » ; elle sait qu\'il se débloque quand la réclamation est remplacée, déduite ou close.',
                    ],
                    [
                        'titre' => 'Vérifier les sauvegardes',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Tuile « Sauvegardes ».'],
                        'attendu' => 'La personne lit la dernière sauvegarde réussie et sait dire s\'il y a une copie hors de l\'imprimerie.',
                    ],
                    [
                        'titre' => 'Préparer l\'import des clients',
                        'compte' => 'demo.kadiatou',
                        'consigne' => ['Tuile « Import des données » › Clients › « Modèle ».'],
                        'attendu' => 'Le modèle Excel se télécharge ; rien n\'est importé sans l\'aperçu.',
                    ],
                ],
                'final' => [
                    'titre' => 'Exercice final : une journée de direction',
                    'consigne' => [
                        'Valider les opérations en attente de la journée.',
                        'Ajouter « Trésorerie et soldes » à une personne précise, vérifier, puis le retirer.',
                        'Dire ce qui est dû aux fournisseurs et quel paiement est bloqué.',
                        'Dire l\'état des sauvegardes et de la synchronisation.',
                    ],
                    'attendu' => 'Aucune opération en attente oubliée ; le droit ajouté puis retiré ; le paiement bloqué expliqué ; l\'état des sauvegardes connu.',
                ],
            ],
        ];
    }
}
