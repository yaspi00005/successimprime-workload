#!/usr/bin/env python3
"""
Génère donnees-demo/demo.sql : une année d'activité fictive dans toutes les
tables de l'application, pour voir le comportement des écrans et des
statistiques. Toujours le même tirage (graine fixe).

    python3 donnees-demo/generer_demo.py

Repères :
  - chaque ligne de démo a un identifiant à partir de 900001 ;
  - clients DEMO-0001 à DEMO-0200, téléphones fictifs en 60 xx xx xx et
    recevoir_sms = 0 : aucun message ne peut partir vers un vrai abonné ;
  - utilisateurs demo.prenom, mot de passe demo1234 ;
  - les dates sont relatives au jour du chargement (CURDATE()).

Tables laissées telles quelles : doctrine_migration_versions,
messenger_messages (file technique), reglage (réglages de l'admin),
role_utilisateur et entreprise (créés par les migrations, réutilisés).
"""
import hashlib
import json
import random
import uuid
from collections import defaultdict
from pathlib import Path

R = random.Random(2026)
DEBUT_ID = 900001
JOURS = 365          # profondeur de l'historique
DETAIL = 120         # jours avec prépresse, production et livraisons détaillées
N_CLIENTS = 200
MDP = '$2y$12$c30FyXOimixRsyrurS/JpeilwMoB7LD2mWksmoH4s8PC9CE1MiuSm'  # demo1234


# ---------------------------------------------------------------- outils SQL
class S(str):
    """Expression SQL écrite telle quelle."""


def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, S):
        return str(v)
    if isinstance(v, bool):
        return '1' if v else '0'
    if isinstance(v, int):
        return str(v)
    if isinstance(v, float):
        return repr(round(v, 4))
    if isinstance(v, (list, dict)):
        v = json.dumps(v, ensure_ascii=False)
    v = str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n')
    return "'" + v + "'"


def T(d, h=9, m=0, s=0):
    """Date et heure : d jours avant le chargement (négatif : dans le futur)."""
    h, m = int(h), int(m)
    h, m = min(23, h + m // 60), m % 60
    return S(f"TIMESTAMP(CURDATE() - INTERVAL {d} DAY, '{h:02d}:{m:02d}:{int(s):02d}')")


def J(d):
    return S(f"(CURDATE() - INTERVAL {d} DAY)")


def M1(k):
    """Premier jour du mois, k mois avant le chargement."""
    return S(f"DATE_FORMAT(CURDATE() - INTERVAL {k} MONTH, '%Y-%m-01')")


def uid():
    return S('UNHEX(\'' + uuid.UUID(int=R.getrandbits(128), version=4).hex + '\')')


# Identifiants des bons de livraison : tirage à part, pour ne pas décaler le reste des données.
R_BL = random.Random(1311)


def uid_bl():
    return S('UNHEX(\'' + uuid.UUID(int=R_BL.getrandbits(128), version=4).hex + '\')')


# Identifiants des règles et des rappels de paiement (envoyés au site) : tirage à part, lui aussi.
R_RAPPEL = random.Random(2610)


def uid_rappel():
    return S('UNHEX(\'' + uuid.UUID(int=R_RAPPEL.getrandbits(128), version=4).hex + '\')')


def hexa(n=64):
    return ''.join(R.choice('0123456789abcdef') for _ in range(n))


ROWS = defaultdict(list)
ORDRE = []
COMPTEURS = defaultdict(lambda: DEBUT_ID)


def nid(table):
    i = COMPTEURS[table]
    COMPTEURS[table] += 1
    return i


def add(table, row, avec_id=True):
    if table not in ROWS:
        ORDRE.append(table)
    if avec_id and 'id' not in row:
        row = {'id': nid(table), **row}
    ROWS[table].append(row)
    return row.get('id')


def heure():
    return R.randint(8, 17), R.randint(0, 59)


# ------------------------------------------------------------------ données
PRENOMS_H = ['Moussa', 'Ibrahim', 'Seydou', 'Bakary', 'Mamadou', 'Oumar', 'Modibo', 'Souleymane', 'Adama', 'Drissa', 'Boubacar', 'Cheick', 'Lassina', 'Yacouba', 'Abdoulaye', 'Sidiki', 'Amadou', 'Siaka', 'Mahamadou', 'Issa']
PRENOMS_F = ['Aminata', 'Fatoumata', 'Mariam', 'Awa', 'Kadiatou', 'Oumou', 'Assétou', 'Djénéba', 'Rokia', 'Salimata', 'Hawa', 'Kadidia', 'Fanta', 'Bintou', 'Nana', 'Mariétou', 'Aïssata', 'Coumba', 'Safiatou', 'Ramata']
NOMS = ['Traoré', 'Keïta', 'Coulibaly', 'Diarra', 'Konaté', 'Maïga', 'Touré', 'Sangaré', 'Cissé', 'Dembélé', 'Sidibé', 'Diallo', 'Kanté', 'Sow', 'Doumbia', 'Camara', 'Koné', 'Bagayoko', 'Sacko', 'Haïdara', 'Ballo', 'Guindo', 'Dicko', 'Samaké', 'Fofana']
LIEUX = ['Djoliba', 'Niger', 'Sahel', 'Fleuve', 'Liberté', 'Espoir', 'Baobab', 'Kénédougou', 'Mandé', 'Badala', 'Soundiata', 'Wassoulou', 'Kayes', 'Ségou', 'Sikasso', 'Tombouctou', 'Bélédougou', 'Delta', 'Horizon', 'Amitié', 'Étoile', 'Lumière', 'Faso', 'Bani', 'Kati']
COMMUNES = {
    'Commune I': ['Korofina', 'Banconi', 'Boulkassoumbougou', 'Djélibougou', 'Sotuba'],
    'Commune II': ['Médina Coura', 'Bagadadji', 'Niarela', 'Bozola', 'Hippodrome'],
    'Commune III': ['Bamako Coura', 'Dravéla', 'Darsalam', 'Quartier du Fleuve', 'Badialan'],
    'Commune IV': ['Hamdallaye', 'Lafiabougou', 'Djikoroni Para', 'Sébénikoro', 'Lassa'],
    'Commune V': ['Badalabougou', 'Kalaban Coura', 'Daoudabougou', 'Torokorobougou', 'Baco Djicoroni'],
    'Commune VI': ['Faladié', 'Magnambougou', 'Sogoniko', 'Niamakoro', 'Missabougou'],
}
REPERES = ['près de la station Total', 'en face de la pharmacie', 'derrière le marché', 'à côté de la mosquée', 'portail bleu', 'près du rond-point', 'en face de l\'école', 'immeuble jaune, 2e étage', 'à côté de la boulangerie', 'près du terrain de foot']
ACTIVITES = [('Hôtel', 'Hôtellerie'), ('Restaurant', 'Restauration'), ('Pharmacie', 'Santé'), ('Clinique', 'Santé'), ('Boutique', 'Commerce'), ('Quincaillerie', 'BTP'), ('Garage', 'Transport'), ('Agence', 'Événementiel'), ('Cabinet', 'Banque et assurance'), ('Supermarché', 'Commerce'), ('Boulangerie', 'Restauration'), ('Salon', 'Mode'), ('Auto-école', 'Transport'), ('Immobilière', 'Immobilier'), ('Transports', 'Transport'), ('Tissus', 'Mode')]
ONGS = ['ONG Santé Plus', 'ONG Sahel Vert', 'Association Femmes et Développement', 'ONG Eau pour Tous', 'Fondation Éducation Mali', 'ONG Jeunesse Active', 'Association des Handicapés du Mandé', 'ONG Nutrition Enfance', 'Croix des Volontaires', 'ONG Agri-Sahel', 'Association Culture et Patrimoine', 'ONG Micro-Crédit Solidaire', 'Réseau des Femmes Rurales', 'ONG Paix et Dialogue', 'ONG Énergie Solaire Villages']
ADMINS = ['Mairie de la Commune {c}', 'Lycée {l}', 'Centre de santé de référence de la Commune {c}', 'Direction régionale de la santé', 'École fondamentale de {q}', 'Tribunal de grande instance de la Commune {c}', 'Institut de formation {l}', 'Direction des impôts de la Commune {c}', 'Centre culturel {l}', 'Office du tourisme']
FONCTIONS = {'entreprise': ['Gérant', 'Directrice', 'Directeur', 'Comptable', 'Responsable achats', 'Assistante de direction', 'Chargée de communication', 'Propriétaire'],
             'ong': ['Chargée de communication', 'Coordinateur', 'Responsable logistique', 'Comptable', 'Chef de projet'],
             'administration': ['Proviseur adjoint', 'Secrétaire général', 'Chef du personnel', 'Intendant', 'Régisseur']}
ORIGINES = ['site', 'whatsapp', 'boutique', 'telephone', 'recommandation', 'autre']
DELAIS = {'comptant': 'Comptant', '15_jours': '15 jours', '30_jours': '30 jours', '30_jours_fdm': '30 jours fin de mois', '45_jours': '45 jours', '60_jours': '60 jours'}
NOTES = [
    ('appel', 'Appel : promesse de paiement pour la fin de semaine.'),
    ('appel', 'Appel : souhaite un devis pour {t}.'),
    ('appel', 'Pas de réponse, rappeler demain matin.'),
    ('note', 'Livrer par l\'entrée de service avant 10 h.'),
    ('note', 'Préfère être contacté sur WhatsApp.'),
    ('note', 'Factures au nom de la direction, pas du gérant.'),
    ('visite', 'Visite : présenté le catalogue, intéressé par {t}.'),
    ('visite', 'Visite : besoin de {t} pour un événement le mois prochain.'),
    ('appel', 'Appel : satisfait de la dernière commande, en redemande.'),
    ('note', 'Toujours demander le BAT par WhatsApp au comptable.'),
]

numeros = set()


def telephone():
    # Numéros fictifs en 60 : aucun SMS ne doit partir vers un vrai abonné.
    while True:
        n = '60' + ''.join(R.choice('0123456789') for _ in range(6))
        if n not in numeros:
            numeros.add(n)
            return n


def personne():
    return R.choice(PRENOMS_F if R.random() < 0.5 else PRENOMS_H), R.choice(NOMS)


def fmt_tel(t):
    return ' '.join(t[i:i + 2] for i in range(0, 8, 2))


# ------------------------------------------------------- personnel et comptes
EQUIPE = [
    # clé, prénom, nom, fonction, profil, ajoutés, retirés, salaire, objectif
    ('kadiatou', 'Kadiatou', 'Diallo', 'Directrice adjointe', 'ROLE_ADMIN', [], [], 450000, None),
    ('fanta', 'Fanta', 'Sidibé', 'Comptable et RH', 'ROLE_CAISSE_COMMANDE', ['ROLE_RH', 'ROLE_TRESORERIE_VOIR', 'ROLE_PAIEMENT_VOIR', 'ROLE_STATS_GLOBAL'], [], 300000, None),
    ('aminata', 'Aminata', 'Keïta', 'Caisse et commercial', 'ROLE_CAISSE_COMMANDE', [], [], 180000, 9000000),
    ('mariam', 'Mariam', 'Touré', 'Commerciale', 'ROLE_CAISSE_COMMANDE', [], [], 160000, 7000000),
    ('moussa', 'Moussa', 'Cissé', 'Commercial terrain', 'ROLE_CAISSE_COMMANDE', [], ['ROLE_TRESORERIE_TRANSFERER'], 150000, 6000000),
    ('seydou', 'Seydou', 'Diarra', 'Graphiste PAO', 'ROLE_GRAPHISTE', [], [], 175000, None),
    ('aissata', 'Aïssata', 'Konaté', 'Graphiste PAO', 'ROLE_GRAPHISTE', [], [], 150000, None),
    ('oumar', 'Oumar', 'Sangaré', 'Opérateur production', 'ROLE_PRODUCTION', [], [], 140000, None),
    ('ibrahim', 'Ibrahim', 'Traoré', 'Opérateur production', 'ROLE_PRODUCTION', [], [], 130000, None),
    ('bakary', 'Bakary', 'Coulibaly', 'Livreur', 'ROLE_LIVREUR', [], [], 95000, None),
    ('sekou', 'Sékou', 'Kanté', 'Livreur', 'ROLE_LIVREUR', [], [], 90000, None),
    ('awa', 'Awa', 'Dembélé', 'Agent d\'entretien', 'ROLE_PRODUCTION', ['ROLE_ENTRETIEN'], ['ROLE_PRODUCTION_GERER', 'ROLE_PRODUCTION_VOIR'], 75000, None),
]
# Dossier (maquettes E01 à E03) : contrat, paie, horaires. Sans tirage au
# sort, pour ne pas changer le reste de la démo.
DOSSIER = {
    'aissata': ('essai', 'salaire', 'orange_money', None),
    'ibrahim': ('journalier', 'journalier', 'especes', None),
    'awa': ('cdd', 'salaire', 'especes', -75),
    'sekou': ('cdd', 'salaire', 'orange_money', -160),
}
U = {}
TEL = {}
EMBAUCHE = {}
for cle, prenom, nom, fonction, profil, plus, moins, salaire, objectif in EQUIPE:
    contrat, remuneration, paiement, fin = DOSSIER.get(cle, ('cdi', 'salaire', 'virement' if salaire >= 250000 else 'orange_money', None))
    TEL[cle] = telephone()
    e = add('employes', {'nom': nom, 'prenom': prenom, 'fonction': fonction, 'telephone': TEL[cle], 'email': f'{cle}@demo.successimprim.ml',
                         'salaires': salaire, 'date_naissances': J(R.randint(22, 50) * 365 + R.randint(0, 300)), 'matricules': f'MAT-D{len(U) + 1:03d}',
                         'date_embauches': J(R.randint(400, 2500)), 'adresses': f'{R.choice(list(COMMUNES))}, Bamako', 'cin': f'CIN-D{R.randint(100000, 999999)}',
                         'contrat': contrat, 'fin_prevue': J(fin) if fin else None, 'type_remuneration': remuneration, 'mode_paiement': paiement,
                         'heure_debut': None, 'heure_fin': None, 'jours_travail': None, 'contact_urgence': None, 'responsable_id': None})
    U[cle] = add('user', {'username': f'demo.{cle}', 'roles': [profil], 'password': MDP, 'actif': 1, 'date_add': T(R.randint(380, 700)), 'date_update': T(R.randint(1, 30)),
                          'must_change_password': 0, 'password_changed_at': T(R.randint(30, 300)), 'last_login_at': T(R.randint(0, 3), *heure()), 'employe_id': e,
                          'droits_ajoutes': plus, 'droits_retires': moins, 'profil_id': S(f"(SELECT r.id FROM role_utilisateur r WHERE r.code = '{profil}')"),
                          'objectif_ventes_mois': objectif, 'acces': 'actif', 'pin': None, 'pin_essais': 0, 'pin_bloque_jusqua': None,
                          'invitation_empreinte': None, 'invitation_envoyee_le': None, 'invitation_expire_le': None, 'fin_acces_le': J(fin) if fin else None})
COMMERCIAUX = [U['aminata'], U['mariam'], U['moussa'], U['aminata'], U['kadiatou']]
GRAPHISTES = [U['seydou'], U['aissata']]
LIVREURS = [U['bakary'], U['sekou']]
PRENOM = {U[k]: p for k, p, *_ in EQUIPE}
# Nom montré au client dans la carte « Votre conseiller(ère) » (clients.conseiller).
NOM_COMPLET = {U[k]: f'{p} {n}' for k, p, n, *_ in EQUIPE}

COMPTES = {}
for code, nom, typ, portee, banque in [('D-CAISSE', 'Caisse boutique', 'caisse', 'partagee', None), ('D-OM', 'Orange Money marchand', 'orange_money', 'partagee', None),
                                       ('D-WAVE', 'Wave', 'wave', 'admin', None), ('D-BDM', 'Banque BDM', 'banque', 'admin', 'BDM-SA')]:
    COMPTES[typ] = add('compte_tresorerie', {'code': code, 'nom': nom, 'type': typ, 'portee': portee, 'nom_banque': banque,
                                              'numero_compte': f'ML0{R.randint(10, 99)} 0{R.randint(1000, 9999)} {R.randint(10000000, 99999999)}' if banque else None,
                                              'titulaire_compte': 'Succès Imprim (démo)' if banque else None, 'solde_initial': 0, 'solde_actuel': 0, 'devise': 'XOF',
                                              'autoriser_decouvert': 0, 'actif': 1, 'description': 'Compte de démonstration', 'date_creation': T(JOURS + 20), 'date_modification': T(1),
                                              'proprietaire_id': None})

# Mouvements : gardés de côté pour calculer les soldes à la fin.
MOUVEMENTS = []


def mouvement(typ, categorie, montant, libelle, d, h, m, source=None, dest=None, mode=None, paiement=None, agent=None, statut='valide', confidentiel=False):
    i = nid('mouvement_tresorerie')
    row = {'id': i, 'reference': f'MVT-D{i - DEBUT_ID + 1:06d}', 'type': typ, 'categorie': categorie,
           'impact_resultat': 0 if categorie in ('transfert_interne', 'ajustement') else 1, 'confidentiel': int(confidentiel),
           'montant': montant, 'devise': 'XOF', 'mode_paiement': mode, 'libelle': libelle, 'statut': statut, 'date_operation': T(d, h, m),
           'date_creation': T(d, h, m), 'date_validation': T(d, h, m + 1) if statut == 'valide' else None,
           'verifie': 1 if d > 20 else 0, 'date_verification': T(max(0, d - 2), 18) if d > 20 else None,
           'compte_source_id': source, 'compte_destination_id': dest, 'verifie_par_id': U['fanta'] if d > 20 else None,
           'paiement_id': paiement, 'agent_id': agent or U['fanta']}
    add('mouvement_tresorerie', row)
    MOUVEMENTS.append((d, h * 60 + m, typ, montant, source, dest, statut, i))
    return i


# ------------------------------------------------------------------- catalogue
CATEGORIES = {}
for k, (nom, desc) in enumerate([('Grand format', 'Bâches, vinyles, panneaux'), ('Impression numérique', 'Flyers, cartes, affiches, brochures'),
                                  ('Textile', 'T-shirts, polos, casquettes'), ('Signalétique', 'Roll-up, kakémonos, enseignes')]):
    CATEGORIES[nom] = add('categorie_produit', {'nom': nom + ' (démo)', 'description': desc, 'publie': 1, 'ordre': k + 1, 'public_id': uid()})

TYPES = {}
for k, (nom, desc) in enumerate([('Solvant grand format', 'Extérieur, encres solvant'), ('DTF textile', 'Transfert sur textile'),
                                  ('Laser couleur', 'Petit format, feuilles SRA3'), ('Éco-solvant intérieur', 'Intérieur, haute définition')]):
    TYPES[nom] = add('types_impression', {'nom': nom, 'description': desc, 'publie': 1, 'ordre': k + 1, 'public_id': uid()})

SUPPORTS = {}
for k, nom in enumerate(['Bâche 510 g frontlit', 'Bâche 440 g roll-up', 'Vinyle adhésif blanc', 'Micro-perforé', 'Akylux 3 mm', 'Papier couché 135 g',
                         'Papier couché 350 g', 'T-shirt coton', 'Polo piqué', 'Casquette 6 panneaux']):
    SUPPORTS[nom] = add('supports', {'nom': nom, 'description': '', 'publie': 1, 'ordre': k + 1, 'public_id': uid()})

FORMATS = {}
for k, (nom, l, h, u) in enumerate([('A5', 14.8, 21, 'cm'), ('A4', 21, 29.7, 'cm'), ('A3', 29.7, 42, 'cm'), ('Carte 85 × 55', 8.5, 5.5, 'cm'),
                                     ('Roll-up 85 × 200', 85, 200, 'cm'), ('Kakémono 80 × 180', 80, 180, 'cm'), ('Sur mesure', 0, 0, 'cm'), ('Taille unique', 0, 0, 'cm')]):
    FORMATS[nom] = add('format', {'nom': nom, 'largeur': l, 'hauteur': h, 'unite': u, 'description': '', 'publie': 1, 'ordre': k + 1, 'public_id': uid()})

FINITIONS = {}
for k, (nom, cout) in enumerate([('Œillets tous les 50 cm', 150), ('Ourlet', 300), ('Fourreau', 500), ('Lamination', 1500), ('Pelliculage mat', 40),
                                  ('Découpe à la forme', 25), ('Pliage', 15)]):
    FINITIONS[nom] = add('finition', {'nom': nom, 'publie': 1, 'ordre': k + 1, 'description': '', 'public_id': uid(), 'cout_unitaire': cout})

MACHINES = {}
for k, (nom, marque, modele, typ, larg, tetes, mode, prix, vit, cout_h, encre, ip) in enumerate([
        ('Solvant 3,20 m', 'Infiniti', 'FY-3208', 'Solvant grand format', '3.20', 8, 'metre_carre', 18000000, 45, 6000, 650, '10.90.0.11'),
        ('Solvant 1,80 m', 'Mimaki', 'JV33-160', 'Éco-solvant', '1.80', 4, 'metre_carre', 9500000, 18, 4500, 800, '10.90.0.12'),
        ('DTF XP600', 'Epson', 'XP600 DTF', 'DTF', '0.60', 2, 'metre_carre', 4500000, 6, 2500, 1200, '10.90.0.13'),
        ('Laser SRA3', 'Konica Minolta', 'C3070', 'Laser couleur', '0.32', 1, 'feuille', 12000000, None, 3500, None, '10.90.0.14')]):
    MACHINES[nom] = add('machines', {'nom': nom, 'marque': marque, 'modeles': modele, 'numero_serie': f'SN-D{R.randint(100000, 999999)}', 'type_machine': typ,
                                     'largeur_impression': larg, 'nb_tetes': tetes, 'date_achat': J(R.randint(700, 1500)), 'date_mise_service': J(R.randint(500, 690)),
                                     'compteur_m2': 0.0, 'compteur_heures': R.randint(3000, 9000), 'compteur_feuilles': 0, 'etat': 'actif', 'numero_machine': f'D-M{k + 1}',
                                     'adresse_ip': ip, 'mode_facturation': mode, 'prix_achat': prix, 'duree_amortissement_mois': 60, 'revenu_avant_suivi': 0,
                                     'date_debut_suivi': J(JOURS), 'vitesse_m2_heure': vit, 'cout_horaire': cout_h, 'cout_encre_m2': encre,
                                     'plan_preventif': [{'tache': 'Nettoyage des têtes', 'frequence': 'jour'}, {'tache': 'Graissage des rails', 'frequence': 'mois'}],
                                     'marge_montage_mm': 5.0, 'formats_montage': 'SRA3' if mode == 'feuille' else None, 'capacite_usage': 60000.0 if mode == 'metre_carre' else 1500000.0,
                                     'valeur_residuelle': prix // 10})
M_COMPTEUR = defaultdict(float)

FOURNISSEURS = {}
for nom, adr in [('Sahel Supplies (démo)', 'Zone industrielle, Bamako'), ('Dakar Signs (démo)', 'Dakar, Sénégal'), ('Encres Pro (démo)', 'Abidjan, Côte d\'Ivoire'),
                 ('Textiles du Mandé (démo)', 'Marché Rose, Bamako'), ('Papeterie Niger (démo)', 'Bamako Coura')]:
    FOURNISSEURS[nom] = add('fournisseurs', {'nom': nom, 'telephone': int('60' + str(R.randint(100000, 999999))), 'email': None, 'adresse': adr, 'actif': 1})
F = list(FOURNISSEURS.values())

ARTICLES = {}
ARTICLE_INFO = {}
for ref, des, fam, unite, pa, pv, fourn, smin, machine in [
        ('D-MAT-001', 'Bâche 510 g frontlit · laize 3,20 m', 'Supports', 'm2', 650, None, F[0], 300, None),
        ('D-MAT-002', 'Bâche 440 g · laize 1,60 m', 'Supports', 'm2', 550, None, F[0], 150, None),
        ('D-MAT-003', 'Vinyle blanc brillant · 1,52 m', 'Supports', 'm2', 1400, None, F[1], 100, None),
        ('D-MAT-004', 'Micro-perforé · 1,37 m', 'Supports', 'm2', 2600, None, F[1], 40, None),
        ('D-MAT-005', 'Plaque Akylux 3 mm', 'Supports', 'm2', 5200, None, F[1], 20, None),
        ('D-ENC-001', 'Encre solvant cyan', 'Encres', 'litre', 28000, None, F[2], 4, 'Solvant 3,20 m'),
        ('D-ENC-002', 'Encre solvant magenta', 'Encres', 'litre', 28000, None, F[2], 4, 'Solvant 3,20 m'),
        ('D-ENC-003', 'Encre solvant jaune', 'Encres', 'litre', 28000, None, F[2], 4, 'Solvant 3,20 m'),
        ('D-ENC-004', 'Encre solvant noir', 'Encres', 'litre', 26000, None, F[2], 4, 'Solvant 3,20 m'),
        ('D-DTF-001', 'Film DTF · 60 cm', 'DTF', 'metre', 1800, None, F[2], 50, 'DTF XP600'),
        ('D-DTF-002', 'Poudre DTF 1 kg', 'DTF', 'kg', 9500, None, F[2], 3, 'DTF XP600'),
        ('D-TEX-001', 'T-shirt coton blanc', 'Textile', 'unite', 1600, 2500, F[3], 100, None),
        ('D-TEX-002', 'Polo piqué', 'Textile', 'unite', 3500, 5500, F[3], 40, None),
        ('D-TEX-003', 'Casquette 6 panneaux', 'Textile', 'unite', 1200, 2000, F[3], 50, None),
        ('D-FIN-001', 'Structure roll-up 85 × 200', 'Finition', 'unite', 18000, 25000, F[1], 10, None),
        ('D-FIN-002', 'Œillets laiton (boîte de 500)', 'Finition', 'boite', 6000, None, F[0], 3, None),
        ('D-PAP-001', 'Papier couché 350 g · SRA3', 'Papier', 'feuille', 95, None, F[4], 1000, None),
        ('D-PAP-002', 'Papier couché 135 g · SRA3', 'Papier', 'feuille', 45, None, F[4], 2000, None),
        ('D-PAP-003', 'Toner couleur C3070 (jeu)', 'Papier', 'unite', 185000, None, F[4], 1, 'Laser SRA3')]:
    a = add('articles', {'reference': ref, 'designation': des, 'categorie': fam, 'unite': unite, 'stock': 0, 'stock_min': smin, 'prix_achat': pa, 'prix_vente': pv,
                         'description': None, 'actif': 1, 'vendable': 1 if pv else 0, 'consommable_production': 1, 'fournisseur_id': fourn,
                         'unite_achat': 'rouleau' if unite == 'm2' else unite, 'quantite_par_unite_achat': 50.0 if unite == 'm2' else 1.0, 'longueur_unite_achat': None, 'laize': None,
                         'unite_vente': unite, 'stock_max': smin * 6, 'emplacement': R.choice(['Magasin A', 'Magasin B', 'Atelier']), 'cout_moyen': pa,
                         'famille_id': S(f"(SELECT f.id FROM famille_article f WHERE f.nom = '{fam}' LIMIT 1)"), 'machine_id': MACHINES.get(machine)})
    ARTICLES[ref] = a
    ARTICLE_INFO[a] = {'pa': pa, 'fourn': fourn, 'unite': unite, 'stock': 0.0, 'smin': smin}
    add('article_fournisseur', {'prix_achat': pa, 'delai_jours': R.choice([2, 5, 10, 21]), 'principal': 1, 'reference_fournisseur': f'REF-{R.randint(1000, 9999)}', 'article_id': a, 'fournisseur_id': fourn})
    autre = R.choice([f for f in F if f != fourn])
    if R.random() < 0.4:
        add('article_fournisseur', {'prix_achat': int(pa * R.uniform(1.03, 1.15)), 'delai_jours': 14, 'principal': 0, 'reference_fournisseur': None, 'article_id': a, 'fournisseur_id': autre})

# Produits : code, nom, catégorie, mode, prix, prix B2B, type, support, format, machine, finitions, article, coût/unité
PRODUITS = [
    ('D-BACHE', 'Bâche imprimée', 'Grand format', 'metre_carre', 3500, 3000, 'Solvant grand format', 'Bâche 510 g frontlit', 'Sur mesure', 'Solvant 3,20 m', ['Œillets tous les 50 cm', 'Ourlet', 'Fourreau'], 'D-MAT-001', 1300),
    ('D-VINYLE', 'Vinyle adhésif', 'Grand format', 'metre_carre', 6500, 5500, 'Éco-solvant intérieur', 'Vinyle adhésif blanc', 'Sur mesure', 'Solvant 1,80 m', ['Lamination', 'Découpe à la forme'], 'D-MAT-003', 2600),
    ('D-MICRO', 'Micro-perforé vitrine', 'Grand format', 'metre_carre', 9000, 8000, 'Éco-solvant intérieur', 'Micro-perforé', 'Sur mesure', 'Solvant 1,80 m', [], 'D-MAT-004', 4000),
    ('D-AKYLUX', 'Panneau Akylux', 'Signalétique', 'metre_carre', 15000, 13000, 'Éco-solvant intérieur', 'Akylux 3 mm', 'Sur mesure', 'Solvant 1,80 m', ['Découpe à la forme'], 'D-MAT-005', 8000),
    ('D-ROLLUP', 'Roll-up 85 × 200 cm', 'Signalétique', 'unite', 45000, 40000, 'Éco-solvant intérieur', 'Bâche 440 g roll-up', 'Roll-up 85 × 200', 'Solvant 1,80 m', [], 'D-FIN-001', 24000),
    ('D-KAKEMONO', 'Kakémono 80 × 180 cm', 'Signalétique', 'unite', 30000, 27000, 'Éco-solvant intérieur', 'Bâche 440 g roll-up', 'Kakémono 80 × 180', 'Solvant 1,80 m', ['Fourreau'], 'D-MAT-002', 9000),
    ('D-FLYER', 'Flyers A5', 'Impression numérique', 'unite', 75, 60, 'Laser couleur', 'Papier couché 135 g', 'A5', 'Laser SRA3', ['Pelliculage mat'], 'D-PAP-002', 30),
    ('D-CARTE', 'Cartes de visite', 'Impression numérique', 'unite', 50, 40, 'Laser couleur', 'Papier couché 350 g', 'Carte 85 × 55', 'Laser SRA3', ['Pelliculage mat', 'Découpe à la forme'], 'D-PAP-001', 18),
    ('D-AFFICHE', 'Affiches A3', 'Impression numérique', 'unite', 750, 650, 'Laser couleur', 'Papier couché 135 g', 'A3', 'Laser SRA3', [], 'D-PAP-002', 260),
    ('D-BROCHURE', 'Brochure A4 8 pages', 'Impression numérique', 'unite', 1800, 1500, 'Laser couleur', 'Papier couché 135 g', 'A4', 'Laser SRA3', ['Pliage'], 'D-PAP-002', 700),
    ('D-TSHIRT', 'T-shirt personnalisé DTF', 'Textile', 'unite', 5000, 4200, 'DTF textile', 'T-shirt coton', 'Taille unique', 'DTF XP600', [], 'D-TEX-001', 2600),
    ('D-POLO', 'Polo personnalisé', 'Textile', 'unite', 9000, 8000, 'DTF textile', 'Polo piqué', 'Taille unique', 'DTF XP600', [], 'D-TEX-002', 4800),
    ('D-CASQUETTE', 'Casquette personnalisée', 'Textile', 'unite', 3500, 3000, 'DTF textile', 'Casquette 6 panneaux', 'Taille unique', 'DTF XP600', [], 'D-TEX-003', 1800),
]
P = {}
for k, (code, nom, cat, mode, prix, b2b, typ, sup, fmt, machine, fins, art, cout) in enumerate(PRODUITS):
    pid = add('produits', {'nom': nom + ' (démo)', 'description': f'{nom} — produit de démonstration', 'publie': 1, 'ordre': k + 1, 'code': code, 'prix_base': float(prix),
                           'prix_b2_b': float(b2b), 'personnalisable': 1, 'actif': 1, 'mode_calcul': mode, 'gestion_stock': 0, 'categorie_produit_id': CATEGORIES[cat],
                           'article_stock_id': ARTICLES[art], 'public_id': uid()})
    conf = add('produit_configuration', {'active': 1, 'ordre': 1, 'description': None, 'prix_base': prix, 'mode_calcul': mode, 'mode_dimension': 'mesure' if fmt == 'Sur mesure' else 'format',
                                          'largeur_defaut': None, 'longueur_defaut': None, 'quantite_minimale': 1, 'quantite_maximale': None, 'prix_b2_b': b2b,
                                          'produit_id': pid, 'type_impression_id': TYPES[typ], 'support_id': SUPPORTS[sup], 'format_id': FORMATS[fmt], 'public_id': uid()})
    conf_fins = {}
    for o, f in enumerate(fins):
        conf_fins[f] = add('produit_configuration_finition', {'obligatoire': 0, 'selectionnee_par_defaut': int(o == 0 and mode == 'metre_carre'), 'payante': 1, 'active': 1,
                                                               'prix': {'Œillets tous les 50 cm': 300, 'Ourlet': 500, 'Fourreau': 1000, 'Lamination': 2500, 'Pelliculage mat': 25, 'Découpe à la forme': 30, 'Pliage': 50}[f],
                                                               'mode_calcul': 'metre_carre' if mode == 'metre_carre' else 'unite', 'quantite_minimale': 1, 'quantite_maximale': None,
                                                               'ordre': o + 1, 'description': None, 'produit_configuration_id': conf, 'finition_id': FINITIONS[f]})
        add('produits_finition', {'produits_id': pid, 'finition_id': FINITIONS[f]}, False)
    add('produits_types_impression', {'produits_id': pid, 'types_impression_id': TYPES[typ]}, False)
    add('produits_supports', {'produits_id': pid, 'supports_id': SUPPORTS[sup]}, False)
    add('produits_format', {'produits_id': pid, 'format_id': FORMATS[fmt]}, False)
    add('produit_article_stock', {'coefficient': 1.0, 'mode_calcul': 'surface' if mode == 'metre_carre' else 'quantite', 'actif': 1, 'obligatoire': 1, 'observation': None, 'ordre': 10,
                                  'produit_id': pid, 'article_id': ARTICLES[art]})
    P[code] = dict(id=pid, conf=conf, nom=nom, mode=mode, prix=prix, b2b=b2b, typ=TYPES[typ], sup=SUPPORTS[sup], fmt=FORMATS[fmt], machine=MACHINES[machine],
                   machine_nom=machine, fins=conf_fins, art=ARTICLES[art], cout=cout)
liens = set()
for code, nom, cat, mode, prix, b2b, typ, sup, fmt, machine, fins, art, cout in PRODUITS:
    for table, a, b in [('support_types_impression', SUPPORTS[sup], TYPES[typ]), ('support_format', SUPPORTS[sup], FORMATS[fmt]), ('format_types_impression', FORMATS[fmt], TYPES[typ])]:
        if (table, a, b) not in liens:
            liens.add((table, a, b))
            cols = {'support_types_impression': ('support_id', 'types_impression_id'), 'support_format': ('support_id', 'format_id'), 'format_types_impression': ('format_id', 'types_impression_id')}[table]
            add(table, {cols[0]: a, cols[1]: b}, False)
    for f in fins:
        for table, a, b, cols in [('support_finition', SUPPORTS[sup], FINITIONS[f], ('support_id', 'finition_id')), ('finition_types_impression', FINITIONS[f], TYPES[typ], ('finition_id', 'types_impression_id'))]:
            if (table, a, b) not in liens:
                liens.add((table, a, b))
                add(table, {cols[0]: a, cols[1]: b}, False)
for code in ['D-BACHE', 'D-FLYER', 'D-TSHIRT', 'D-ROLLUP']:
    for k in range(R.randint(1, 3)):
        ancien = int(P[code]['prix'] * R.uniform(0.85, 0.95))
        add('prix_historique', {'cible': 'produit', 'cible_id': P[code]['id'], 'libelle': P[code]['nom'], 'champ': 'prix_base', 'ancien': str(ancien), 'nouveau': str(P[code]['prix']),
                                'change_le': T(R.randint(30, 300), 10), 'par_id': U['kadiatou']})

# Modèles de messages et règles de rappel.
MODELES = {}
for nom, canal, contenu in [('Relance facture (démo)', 'sms', 'Bonjour {{nom}}, la commande {{numeroCommande}} attend un reste à payer de {{reste}} F. Merci de passer à la boutique ou de payer par Orange Money.'),
                            ('Promotion du mois (démo)', 'whatsapp', 'Bonjour {{nom}}, ce mois-ci -10 % sur les bâches et roll-up chez Succès Imprim !'),
                            ('Commande prête (démo)', 'sms', 'Bonjour {{nom}}, votre commande {{numeroCommande}} est prête. Nous vous attendons.'),
                            ('Anniversaire client (démo)', 'sms', 'Joyeux anniversaire de la part de Succès Imprim ! Un cadeau vous attend à la boutique.')]:
    MODELES[nom] = add('modele_message', {'nom': nom, 'canal': canal, 'sujet': None, 'contenu': contenu, 'actif': 1, 'date_creation': T(300)})
REGLES = [add('regle_rappel_paiement', {'nom': 'Relance à 7 jours (démo)', 'delai_jours': 7, 'actif': 1, 'date_creation': T(300), 'modele_message_id': MODELES['Relance facture (démo)'], 'type': 'paiement', 'public_id': uid_rappel()}),
          add('regle_rappel_paiement', {'nom': 'Relance à 30 jours (démo)', 'delai_jours': 30, 'actif': 1, 'date_creation': T(300), 'modele_message_id': MODELES['Relance facture (démo)'], 'type': 'paiement', 'public_id': uid_rappel()}),
          add('regle_rappel_paiement', {'nom': 'Commande prête depuis 3 jours (démo)', 'delai_jours': 3, 'actif': 1, 'date_creation': T(300), 'modele_message_id': MODELES['Commande prête (démo)'], 'type': 'retrait', 'public_id': uid_rappel()})]

# --------------------------------------------------------------------- clients
types = ['entreprise'] * 90 + ['ong'] * 20 + ['administration'] * 20 + ['particulier'] * 70
R.shuffle(types)
CLIENTS = []
noms_pris = set()
for i in range(1, N_CLIENTS + 1):
    t = types[i - 1]
    commune = R.choice(list(COMMUNES))
    quartier = R.choice(COMMUNES[commune])
    suivi = R.choice(COMMERCIAUX[:3])
    cree = R.randint(5, 30) if i <= 15 else R.randint(40, 900)
    contacts, secteur, rs = [], None, None
    if t == 'entreprise':
        while True:
            act, secteur = R.choice(ACTIVITES)
            rs = f'{act} {R.choice(LIEUX)}' if R.random() < 0.7 else f'{act} {R.choice(NOMS)} et Fils'
            if rs not in noms_pris:
                break
        for k in range(R.choice([1, 1, 2, 2, 3])):
            p, n = personne()
            contacts.append((f'{p} {n}', R.choice(FONCTIONS['entreprise']), telephone(), R.random() < 0.8, ['principal', 'factures', 'bat'][k]))
    elif t == 'ong':
        rs = ONGS[(i * 7) % len(ONGS)]
        while rs in noms_pris:
            rs = f'{rs} {R.choice(LIEUX)}'
        secteur = 'Humanitaire'
        for k in range(R.choice([1, 2])):
            p, n = personne()
            contacts.append((f'{p} {n}', R.choice(FONCTIONS['ong']), telephone(), True, ['principal', 'factures'][k]))
    elif t == 'administration':
        rs = R.choice(ADMINS).format(c=R.choice(['I', 'II', 'III', 'IV', 'V', 'VI']), l=R.choice(LIEUX), q=quartier)
        while rs in noms_pris:
            rs = f'{rs} {R.choice(LIEUX)}'
        secteur = 'Éducation' if any(x in rs for x in ('Lycée', 'École', 'Institut')) else 'Administration publique'
        p, n = personne()
        contacts.append((f'{p} {n}', R.choice(FONCTIONS['administration']), telephone(), R.random() < 0.6, 'principal'))
    if rs:
        noms_pris.add(rs)
    if t == 'particulier':
        prenom, nom = personne()
        tel = telephone()
    else:
        prenom, nom = contacts[0][0].split(' ', 1)
        tel = contacts[0][2]
    b2b = t != 'particulier' and R.random() < 0.45
    delai = R.choice(['comptant', '15_jours', '30_jours', '30_jours_fdm', '45_jours']) if t != 'particulier' else R.choice([None, 'comptant'])
    if t == 'administration':
        delai = R.choice(['30_jours', '45_jours', '60_jours'])
    plafond = R.choice([100000, 200000, 300000, 500000]) if t != 'particulier' else R.choice([None, 50000, 100000])
    moyen = R.choice(['orange_money', 'orange_money', 'especes', 'wave']) if t == 'particulier' else R.choice(['orange_money', 'orange_money', 'especes', 'virement', 'cheque', 'wave'])
    etiquettes = ([secteur] if secteur and R.random() < 0.6 else []) + ([R.choice(['Fidèle', 'VIP', 'Signalétique', 'Textile', 'Grand format', 'Événementiel', 'Payeur lent'])] if R.random() < 0.3 else [])
    gps = R.random() < 0.6
    email = None
    if t != 'particulier' and R.random() < 0.6:
        email = ('contact@' + ''.join(ch for ch in rs.lower().replace(' ', '-') if ch.isalnum() or ch == '-')[:30].strip('-') + '.ml').encode('ascii', 'ignore').decode()
    marketing = R.random() < 0.55
    cid = add('clients', {'code': f'DEMO-{i:04d}', 'raison_sociale': rs, 'nom': nom[:50], 'prenom': prenom[:50], 'telephone': tel, 'telephone2': None, 'email': email,
                          'adresse': f'{quartier}, {R.choice(REPERES)}' if R.random() < 0.5 else None, 'ville': 'Bamako',
                          'nif': ('0' + ''.join(R.choice('0123456789') for _ in range(8)) + R.choice('ABCDEFGH')) if t in ('entreprise', 'ong') and R.random() < 0.6 else None,
                          'rccm': f'MA.BKO.{R.randint(2012, 2025)}.{R.choice("ABM")}.{R.randint(1000, 9999)}' if t == 'entreprise' and R.random() < 0.5 else None,
                          'type_client': 'B2B' if b2b else 'B2C', 'type_compte': t, 'plafond_credit': plafond, 'solde_credit': 0,
                          'observation': R.choice([None, None, None, 'Toujours demander un BAT avant impression.', 'Paie souvent en retard, demander l\'acompte.', 'Contact privilégié : le comptable.']),
                          'created_at': T(cree, *heure()), 'updated_at': T(R.randint(0, cree), *heure()), 'statut': 0 if R.random() < 0.03 else 1, 'recevoir_sms': 0, 'public_id': uid(),
                          'latitude': round(12.60 + R.uniform(-0.06, 0.06), 7) if gps else None, 'longitude': round(-7.99 + R.uniform(-0.08, 0.08), 7) if gps else None,
                          'repere': R.choice(REPERES) if gps else None, 'localise_le': T(R.randint(1, cree)) if gps else None,
                          'consentement_marketing_le': T(R.randint(1, cree)) if marketing else None, 'secteur': secteur, 'origine': R.choice(ORIGINES), 'delai_paiement': delai,
                          'remise_habituelle': float(R.choice([3, 5, 5, 7.5, 10])) if b2b and R.random() < 0.6 else None,
                          'acompte': R.choice([None, 30, 50, 50, 100]) if t != 'administration' else None, 'moyen_prefere': moyen, 'entite_facturation': 'mdg_success',
                          'commune': commune, 'quartier': quartier, 'etiquettes': etiquettes, 'besoin': None, 'suivi_par_id': suivi,
                          'conseiller': NOM_COMPLET.get(suivi)})
    for (cn, fonction, ctel, wa, role) in contacts:
        add('contact_client', {'nom': cn, 'fonction': fonction, 'telephone': ctel, 'whatsapp': int(wa), 'email': None, 'role': role, 'client_id': cid})
    profil = R.choices(['actif', 'inactif', 'retard', 'nouveau'], weights=[55, 18, 15, 12])[0] if i > 15 else 'nouveau_actif'
    poids = {'entreprise': 5, 'ong': 4, 'administration': 4, 'particulier': 1.5}[t] * {'actif': 1.3, 'inactif': 0.6, 'retard': 1.0, 'nouveau': 0.0, 'nouveau_actif': 1.0}[profil]
    CLIENTS.append(dict(id=cid, t=t, rs=rs, nom=f'{prenom} {nom}', tel=tel, suivi=suivi, cree=cree, b2b=b2b, moyen=moyen, delai=delai, contacts=contacts,
                        profil=profil, poids=poids, marketing=marketing, plafond=plafond))

# --------------------------------------------------------- commandes et ventes
def nom_client(c):
    return c['rs'] or c['nom']


MODES_PAIEMENT = {'orange_money': ('orange_money', 'orange_money'), 'especes': ('especes', 'caisse'), 'wave': ('wave', 'wave'),
                  'virement': ('virement_bancaire', 'banque'), 'cheque': ('cheque', 'banque')}
PANIERS = {
    'entreprise': ['D-BACHE', 'D-BACHE', 'D-VINYLE', 'D-ROLLUP', 'D-KAKEMONO', 'D-FLYER', 'D-CARTE', 'D-TSHIRT', 'D-POLO', 'D-AKYLUX', 'D-MICRO', 'D-BROCHURE'],
    'ong': ['D-BACHE', 'D-ROLLUP', 'D-KAKEMONO', 'D-TSHIRT', 'D-CASQUETTE', 'D-FLYER', 'D-BROCHURE', 'D-AFFICHE'],
    'administration': ['D-BACHE', 'D-AFFICHE', 'D-FLYER', 'D-BROCHURE', 'D-CARTE', 'D-ROLLUP', 'D-AKYLUX'],
    'particulier': ['D-TSHIRT', 'D-TSHIRT', 'D-CARTE', 'D-FLYER', 'D-BACHE', 'D-CASQUETTE', 'D-AFFICHE'],
}


def ligne_vente(c, code):
    p = P[code]
    prix = p['b2b'] if c['b2b'] else p['prix']
    if p['mode'] == 'metre_carre':
        l = R.choice([1.0, 1.5, 2.0, 2.5, 3.0, 3.2, 4.0, 5.0, 6.0]) if code == 'D-BACHE' else R.choice([0.5, 0.8, 1.0, 1.2, 1.5])
        L = R.choice([1.0, 1.5, 2.0, 3.0]) if code == 'D-BACHE' else R.choice([0.5, 0.7, 1.0, 1.5])
        qte = R.choice([1, 1, 1, 2, 3, 5])
        surface = round(l * L, 4)
        base = surface * qte
    else:
        l = L = surface = None
        qte = {'D-FLYER': [250, 500, 1000, 2000, 5000], 'D-CARTE': [100, 200, 500, 1000], 'D-AFFICHE': [20, 50, 100, 200], 'D-BROCHURE': [50, 100, 200, 500],
               'D-TSHIRT': [5, 10, 20, 50, 100], 'D-POLO': [10, 20, 30, 50], 'D-CASQUETTE': [10, 20, 50, 100], 'D-ROLLUP': [1, 1, 2, 3], 'D-KAKEMONO': [1, 2, 4]}[code]
        qte = R.choice(qte if c['t'] != 'particulier' else qte[:2])
        base = qte
    fins = []
    for f, conf_f in p['fins'].items():
        if R.random() < (0.6 if f == 'Œillets tous les 50 cm' else 0.25):
            prix_f = {'Œillets tous les 50 cm': 300, 'Ourlet': 500, 'Fourreau': 1000, 'Lamination': 2500, 'Pelliculage mat': 25, 'Découpe à la forme': 30, 'Pliage': 50}[f]
            fins.append((f, conf_f, prix_f, int(round(prix_f * base))))
    ht = int(round(prix * base)) + sum(x[3] for x in fins)
    return dict(code=code, p=p, l=l, L=L, surface=surface, qte=qte, base=base, prix=prix, ht=ht, fins=fins, cout=int(p['cout'] * base))


def taux(d):
    # activité en hausse sur l'année, creux en saison des pluies (d ≈ 60 à 120)
    t = 1.3 + 1.6 * (JOURS - d) / JOURS
    if 60 <= d <= 120:
        t *= 0.75
    if 280 <= d <= 320:
        t *= 1.35   # fêtes de fin d'année
    return t


COMMANDES = []
ncmd = 0
for d in range(JOURS, -1, -1):
    n = sum(1 for _ in range(int(taux(d) * 2)) if R.random() < 0.5) + (1 if R.random() < taux(d) % 1 / 2 else 0)
    eligibles = [c for c in CLIENTS if c['cree'] >= d and c['poids'] > 0 and not (c['profil'] == 'inactif' and d < 75)]
    if not eligibles:
        continue
    for _ in range(n):
        c = R.choices(eligibles, weights=[x['poids'] for x in eligibles])[0]
        ncmd += 1
        h, m = heure()
        lignes = [ligne_vente(c, code) for code in R.sample(PANIERS[c['t']], k=R.choice([1, 1, 1, 2, 2, 3]))]
        if R.random() < 0.15:
            lignes.append(dict(code=None, p=None, libre='Conception graphique', ht=R.choice([5000, 10000, 15000, 25000]), qte=1, base=1, prix=None, fins=[], cout=0))
        COMMANDES.append(dict(n=ncmd, d=d, h=h, m=m, c=c, lignes=lignes, agent=c['suivi'] if R.random() < 0.7 else R.choice(COMMERCIAUX),
                              livraison=R.random() < (0.35 if c['t'] != 'particulier' else 0.1), canal=R.choice(['comptoir', 'comptoir', 'comptoir', 'en_ligne'])))

PAIEMENTS_PAR_CMD = defaultdict(list)
FACTURE_DE = {}
for k in COMMANDES:
    c, d = k['c'], k['d']
    kid = nid('commandes')
    k['id'] = kid
    k['numero'] = f'CMD-D{k["n"]:05d}'
    remise = int(round(sum(l['ht'] for l in k['lignes']) * (R.choice([0, 0, 0, 3, 5]) / 100), -2)) if c['b2b'] else 0
    total = sum(l['ht'] for l in k['lignes'])
    a_payer = total - remise
    k['total'], k['a_payer'] = total, a_payer
    # Paiements selon l'âge de la commande et le profil du client.
    if d > 30:
        tirage = R.random()
        if c['profil'] == 'retard' and tirage < 0.35:
            payes = [] if R.random() < 0.5 else [int(round(a_payer * 0.5, -2))]
        elif tirage < 0.05:
            payes = [int(round(a_payer * 0.5, -2))]
        elif tirage < 0.07:
            payes = []
        else:
            payes = [a_payer] if R.random() < 0.55 else [int(round(a_payer * 0.5, -2)), a_payer - int(round(a_payer * 0.5, -2))]
    else:
        tirage = R.random()
        payes = [a_payer] if tirage < 0.5 else ([int(round(a_payer * R.choice([0.3, 0.5]), -2))] if tirage < 0.85 else [])
        if d > 6 and len(payes) == 1 and payes[0] < a_payer and R.random() < 0.6:
            payes.append(a_payer - payes[0])
    k['paye'] = sum(payes)
    k['payes'] = payes
    statut_p = 'payee' if k['paye'] >= a_payer else ('partielle' if k['paye'] > 0 else 'impayee')
    k['statut_p'] = statut_p
    # Production des lignes selon l'âge.
    if d > 7:
        etat_lignes = 'livree'
    elif d > 3:
        etat_lignes = R.choice(['prete_livraison', 'livree', 'livree', 'en_livraison' if k['livraison'] else 'terminee'])
    elif d > 1:
        etat_lignes = R.choice(['en_production', 'terminee', 'prete_livraison'])
    else:
        etat_lignes = R.choice(['a_produire', 'a_produire', 'en_production'])
    k['etat'] = etat_lignes
    livre_le = d - R.randint(1, 4) if etat_lignes == 'livree' else None
    if livre_le is not None and livre_le < 0:
        livre_le = 0
    k['livre_le'] = livre_le
    add('commandes', {'id': kid, 'numero': k['numero'], 'date_commande': T(d, k['h'], k['m']), 'date_livraison': T(max(-5, d - R.randint(2, 5)), 17), 'recupere_par_client': int(etat_lignes == 'livree' and not k['livraison']),
                      'modifie_le': T(livre_le, 16) if livre_le is not None else None, 'remise': remise, 'tva': 0, 'total_ht': total, 'total_ttc': a_payer, 'montant_apayer': a_payer,
                      'observation': None, 'deleted': 0, 'statut': 1, 'etat': 1, 'statut_paiement': statut_p, 'reste_apayer': a_payer - k['paye'], 'total_paye': k['paye'], 'public_id': uid(),
                      'bonus_plafond_applique': 0, 'montant_bonus_plafond': 0, 'clients_id': c['id'], 'agents_id': k['agent'], 'modifie_par_id': k['agent'] if livre_le is not None else None,
                      'canal': k['canal'], 'mode_livraison': 'livraison' if k['livraison'] else 'retrait',
                      'adresse_livraison': f'{R.choice(sum(COMMUNES.values(), []))}, Bamako' if k['livraison'] else None})
    for l in k['lignes']:
        lid = nid('commandes_details')
        l['id'] = lid
        p = l['p']
        prod = p is not None
        st = etat_lignes if prod else 'non_requise'
        l['statut'] = st
        h_deb = R.randint(8, 14)
        debut = None if st == 'a_produire' or not prod else max(0, d - R.randint(0, 2))
        fin = None if st in ('a_produire', 'en_production') or not prod else max(0, (debut if debut is not None else d) - R.randint(0, 1))
        l['debut'], l['fin'] = debut, fin
        add('commandes_details', {'id': lid, 'mode_configuration': 'automatique' if prod else 'manuel', 'designation': p['nom'] if prod else l['libre'],
                                  'largeur': l.get('l'), 'longueur': l.get('L'), 'surface': l.get('surface'), 'quantite': l['qte'],
                                  'prix_unitaire': float(l['prix'] if prod else l['ht']), 'cout_revient': l['cout'], 'remise': 0, 'tva': 0, 'total_ht': l['ht'], 'total_ttc': l['ht'],
                                  'profil_couleurs': 'CMJN' if prod else None, 'resolution': '150 dpi' if prod and p['mode'] == 'metre_carre' else ('300 dpi' if prod else None), 'grammage': None, 'epaisseur': None,
                                  'recto_verso': int(prod and l['code'] in ('D-FLYER', 'D-CARTE') and R.random() < 0.5), 'nombre_faces': 1, 'laminage': None,
                                  'oeillets': int(any(f[0].startswith('Œillets') for f in l['fins'])), 'decoupe': 0, 'pliage': None, 'emballage': 0, 'bat_valide': int(prod and st != 'a_produire'), 'etat': 1,
                                  'priorite': R.choice(['normale', 'normale', 'normale', 'haute', 'urgente']), 'temps_estime': None, 'temps_reel': None, 'fichier': None, 'observation': None,
                                  'statut_production': st, 'production_debute_le': T(debut, h_deb) if debut is not None else None,
                                  'production_terminee_le': T(fin, min(18, h_deb + R.randint(1, 4))) if fin is not None else None,
                                  'quantite_produite': l['qte'] if fin is not None else 0, 'quantite_rebut': 0, 'quantite_livree': l['qte'] if st == 'livree' else 0,
                                  'mode_saisie': 'automatique', 'mode_calcul': p['mode'] if prod else 'forfait', 'pre_presse_necessaire': int(prod), 'production_necessaire': int(prod),
                                  'type_ligne': 'produit' if prod else 'libre', 'commande_id': kid, 'produit_id': p['id'] if prod else None, 'produit_configuration_id': p['conf'] if prod else None,
                                  'type_impression_id': p['typ'] if prod else None, 'support_id': p['sup'] if prod else None, 'machine_id': p['machine'] if prod else None,
                                  'format_id': p['fmt'] if prod else None, 'production_debutee_par_id': (U['oumar'] if p['machine_nom'].startswith('Solvant') else U['ibrahim']) if debut is not None else None,
                                  'production_terminee_par_id': (U['oumar'] if p['machine_nom'].startswith('Solvant') else U['ibrahim']) if fin is not None else None,
                                  'article_id': None, 'public_id': uid(), 'quantite_demandee': None, 'unite_demandee': None, 'produit_client_id': None})
        for f, conf_f, prix_f, montant in l['fins']:
            add('commande_detail_finition', {'nom_finition': f, 'obligatoire': 0, 'prix_applique': prix_f, 'mode_calcul': 'metre_carre' if p['mode'] == 'metre_carre' else 'unite',
                                             'quantite': max(1, int(l['base'])), 'montant': montant, 'commande_detail_id': lid, 'configuration_finition_id': conf_f, 'finition_id': FINITIONS[f]})
    # Paiements et mouvements de trésorerie.
    for n_p, montant in enumerate(payes):
        mode, compte = MODES_PAIEMENT[c['moyen'] if R.random() < 0.75 else R.choice(['especes', 'orange_money', 'orange_money', 'wave'])]
        quand = d if n_p == 0 else max(0, (livre_le if livre_le is not None else d - 2))
        h, m = (k['h'], k['m'] + 5) if n_p == 0 else heure()
        attente = mode == 'cheque' and quand < 3
        pid = nid('paiements')
        PAIEMENTS_PAR_CMD[kid].append(pid)
        add('paiements', {'id': pid, 'montant': montant, 'mode': mode, 'statut': 'en_attente' if attente else 'valide', 'date': T(quand, h, m),
                          'date_validation': None if attente else T(quand, h, m + 2), 'date_annulation': None,
                          'reference': f'OM{R.randint(10 ** 9, 10 ** 10 - 1)}' if mode == 'orange_money' else (f'WV{R.randint(10 ** 8, 10 ** 9 - 1)}' if mode == 'wave' else None),
                          'numero_cheque': f'{R.randint(1000000, 9999999)}' if mode == 'cheque' else None, 'banque_emettrice': R.choice(['BDM', 'BNDA', 'Ecobank', 'BOA']) if mode == 'cheque' else None,
                          'titulaire_cheque': nom_client(c) if mode == 'cheque' else None, 'date_emission_cheque': J(quand) if mode == 'cheque' else None,
                          'date_encaissement_prevue': J(quand - 2) if mode == 'cheque' else None, 'justificatif': None, 'observation': 'Acompte' if n_p == 0 and len(payes) > 1 or montant < a_payer else None,
                          'motif_rejet': None, 'motif_annulation': None, 'frais_retrait_inclus': 0, 'fonds_soutien_inclus': 0, 'montant_frais_retrait': 0, 'montant_fonds_soutien': 0,
                          'commande_id': kid, 'compte_tresorerie_id': COMPTES[compte], 'encaisse_par_id': k['agent'] if U['kadiatou'] != k['agent'] else U['aminata'],
                          'valide_par_id': None if attente else U['aminata'], 'annule_par_id': None, 'facture_id': None, 'public_id': uid()})
        if not attente:
            mouvement('encaissement', 'vente', montant, f'Paiement commande {k["numero"]} · {nom_client(c)}', quand, h, m + 2, dest=COMPTES[compte], mode=mode, paiement=pid, agent=U['aminata'])

CODES_SUIVI = set()


def code_suivi():
    """Code du QR de suivi, de la forme 7K4P-2Q comme Factures::genererCodeSuivi (route /f/{code})."""
    while True:
        # 9 tirages comme avant, pour ne pas décaler le reste de la démo.
        c = ''.join(R.choice('ABCDEFGHJKLMNPQRSTUVWXYZ23456789') for _ in range(9))
        c = c[:4] + '-' + c[4:6]
        if c not in CODES_SUIVI:
            CODES_SUIVI.add(c)
            return c


# Factures (une par commande de plus de 2 jours, 75 %), liées aux paiements.
nfac = 0
for k in COMMANDES:
    if k['d'] < 2 or R.random() > 0.75:
        continue
    nfac += 1
    c = k['c']
    typ = 'facture' if c['t'] != 'particulier' and R.random() < 0.8 else 'proforma'
    jours_ech = {'comptant': 0, '15_jours': 15, '30_jours': 30, '30_jours_fdm': 40, '45_jours': 45, '60_jours': 60}.get(c['delai'] or 'comptant', 0)
    fid = add('factures', {'numero': f'F-D{nfac:05d}', 'type_document': typ, 'comptabilisee': int(typ == 'facture'), 'emetteur': R.choice(['mdg_success'] * 8 + ['mdg', 'drepa']),
                           'pdf_fichier': None, 'pdf_genere_le': None, 'pdf_hash': None, 'date_facture': T(k['d'], k['h'], k['m'] + 10), 'date_echeance': J(k['d'] - jours_ech),
                           'total_ht': k['total'], 'remise': k['total'] - k['a_payer'], 'tva': 0, 'montant_tva': 0, 'surfacturation': 0, 'taux_surfacturation': None, 'montant_surfacturation': 0,
                           'motif_surfacturation': None, 'total_ttc': k['a_payer'], 'montant_paye': k['paye'], 'reste_apayer': k['a_payer'] - k['paye'], 'statut_paiement': k['statut_p'],
                           'etat': 'emise', 'observation': None, 'token_authenticite': hexa(64), 'facturer_aun_tiers': 0, 'nom_facturation': None, 'adresse_facturation': None,
                           'telephone_facturation': None, 'email_facturation': None, 'created_at': T(k['d'], k['h'], k['m'] + 10), 'updated_at': T(max(0, k['d'] - 3), 12),
                           'commande_id': k['id'], 'public_id': uid(), 'code_suivi': code_suivi()})
    FACTURE_DE[k['id']] = fid
    for row in ROWS['paiements']:
        if row['commande_id'] == k['id']:
            row['facture_id'] = fid

# Devis : convertis (liés à une commande) et autres.
ndev = 0


def devis(c, d, lignes, statut, commande=None):
    global ndev
    ndev += 1
    total = sum(l['ht'] for l in lignes)
    did = add('devis', {'numero': f'DV-D{ndev:05d}', 'emetteur': 'mdg_success', 'date_devis': T(d, *heure()), 'remise': 0, 'tva': 0, 'total_ht': total, 'total_ttc': total,
                        'montant_apayer': total, 'observation': None, 'deleted': 0, 'statut': statut, 'etat': 1, 'token_authenticite': hexa(64), 'facturer_aun_tiers': 0,
                        'nom_facturation': None, 'adresse_facturation': None, 'telephone_facturation': None, 'email_facturation': None, 'clients_id': c['id'],
                        'agents_id': c['suivi'], 'commande_id': commande, 'public_id': uid()})
    for l in lignes:
        p = l['p']
        prod = p is not None
        ddid = add('devis_details', {'mode_configuration': 'automatique' if prod else 'manuel', 'designation': p['nom'] if prod else l['libre'], 'largeur': l.get('l'), 'longueur': l.get('L'),
                                     'surface': l.get('surface'), 'quantite': l['qte'], 'prix_unitaire': float(l['prix'] if prod else l['ht']), 'cout_revient': l['cout'], 'remise': 0, 'tva': 0,
                                     'total_ht': l['ht'], 'total_ttc': l['ht'], 'type_ligne': 'produit' if prod else 'libre', 'mode_saisie': 'automatique', 'bat_valide': 0, 'etat': 1,
                                     'priorite': 'normale', 'mode_calcul': p['mode'] if prod else 'forfait', 'devis_id': did, 'produit_id': p['id'] if prod else None,
                                     'produit_configuration_id': p['conf'] if prod else None, 'type_impression_id': p['typ'] if prod else None, 'support_id': p['sup'] if prod else None,
                                     'format_id': p['fmt'] if prod else None, 'article_id': None, 'public_id': uid()})
        for f, conf_f, prix_f, montant in l['fins']:
            add('devis_detail_finition', {'nom_finition': f, 'obligatoire': 0, 'prix_applique': prix_f, 'mode_calcul': 'metre_carre' if p['mode'] == 'metre_carre' else 'unite',
                                          'quantite': max(1, int(l['base'])), 'montant': montant, 'devis_detail_id': ddid, 'configuration_finition_id': conf_f, 'finition_id': FINITIONS[f]})
    return did


DEVIS_OUVERTS = []
for k in COMMANDES:
    if k['c']['t'] != 'particulier' and R.random() < 0.55:
        devis(k['c'], k['d'] + R.randint(1, 6), k['lignes'], 'converti', k['id'])
for _ in range(260):
    c = R.choice([x for x in CLIENTS if x['t'] != 'particulier'] + CLIENTS[:20])
    d = R.randint(0, min(c['cree'], JOURS))
    statut = 'envoye' if d < 20 and R.random() < 0.7 else R.choice(['refuse', 'expire', 'expire', 'refuse', 'accepte', 'brouillon', 'annule'])
    if d < 3:
        statut = R.choice(['brouillon', 'envoye'])
    did = devis(c, d, [ligne_vente(c, code) for code in R.sample(PANIERS[c['t']], k=R.choice([1, 2]))], statut)
    if statut == 'envoye':
        DEVIS_OUVERTS.append((did, c, d))

# ---------------------------------------- prépresse, production et livraisons
GACHE = defaultdict(float)
nop = 0
nbl = 0
DETAILS_RECENTS = []
for k in COMMANDES:
    if k['d'] > DETAIL:
        continue
    for l in k['lignes']:
        if l['p'] is None:
            continue
        DETAILS_RECENTS.append((k, l))

for k, l in DETAILS_RECENTS:
    d, p = k['d'], l['p']
    st = l['statut']
    graphiste = R.choice(GRAPHISTES)
    fich = add('commande_detail_fichier', {'jeton_upload': hexa(64), 'morceaux_recus': 1, 'nombre_morceaux': 1, 'termine_le': T(d, k['h'], k['m'] + 15),
                                           'nom_original': f'{l["code"][2:].lower()}-{nom_client(k["c"])[:20].lower().replace(" ", "-")}.pdf', 'nom_stockage': f'demo-{hexa(16)}.pdf',
                                           'chemin': None, 'type_mime': 'application/pdf', 'taille': R.randint(800000, 90000000), 'statut': 'TERMINE', 'origine': R.choice(['client', 'client', 'interne']),
                                           'etat': 'pret_impression' if st != 'a_produire' else 'a_traiter', 'deja_traite': 0, 'fichier_production': int(st != 'a_produire'), 'actif': 1, 'version': 1,
                                           'quantite_aproduire': l['qte'], 'face': None, 'designation': l['p']['nom'], 'groupe_fichier': None, 'observation': None, 'cree_le': T(d, k['h'], k['m'] + 15),
                                           'commande_detail_id': l['id'], 'fichier_source_id': None, 'ajoute_par_id': k['agent'], 'public_id': uid(), 'montage': None})
    envoye = st not in ('a_produire',)
    if envoye:
        cst = 'envoye_production'
    else:
        cst = R.choice(['a_controler', 'en_cours', 'en_attente_bat', 'correction_requise'])
    h0 = k['h']
    ok = int(envoye)
    ctl = add('controle_pre_presse', {'statut': cst, 'format_conforme': ok, 'dimensions_conformes': ok, 'resolution_conforme': ok, 'profil_couleurs_conforme': ok, 'fonds_perdus_conformes': ok,
                                      'marges_securite_conformes': ok, 'polices_conformes': ok, 'orthographe_verifiee': ok, 'orientation_conforme': ok, 'nombre_pages_conforme': ok,
                                      'recto_verso_conforme': ok, 'support_conforme': ok, 'quantite_conforme': ok, 'fichier_deja_traite': 0, 'correction_necessaire': int(cst == 'correction_requise'),
                                      'bat_necessaire': 1, 'bat_valide': ok, 'anomalies': 'Fonds perdus absents' if cst == 'correction_requise' else None, 'corrections_effectuees': None,
                                      'observation': None, 'pre_presse_necessaire': 1, 'cree_le': T(d, h0, k['m'] + 20), 'controle_le': T(d, h0 + 1) if cst != 'a_controler' else None,
                                      'traite_le': T(d, h0 + 1, 30) if envoye else None, 'valide_le': T(d, h0 + 2) if envoye else None, 'envoye_production_le': T(d, h0 + 2, 10) if envoye else None,
                                      'commande_detail_id': l['id'], 'controle_par_id': graphiste if cst != 'a_controler' else None, 'traite_par_id': graphiste if envoye else None,
                                      'valide_par_id': graphiste if envoye else None, 'envoye_production_par_id': graphiste if envoye else None, 'public_id': uid()})
    add('controle_pre_presse_fichier', {'controle_pre_presse_id': ctl, 'fichier_id': fich}, False)
    # Suivi de conception (W01/W02).
    diff = R.choice(['simple', 'simple', 'moyen', 'complexe'])
    coef = {'simple': 1, 'moyen': 1.5, 'complexe': 2}[diff]
    prevu = {'simple': 45, 'moyen': 90, 'complexe': 180}[diff]
    versions = R.choice([1, 1, 1, 2, 2, 3]) if envoye else (1 if cst in ('en_attente_bat', 'correction_requise') else 0)
    erreur = int(versions > 1 and R.random() < 0.3)
    a_temps = R.random() < 0.85
    faits = {}
    evts = [{'le': None, 'type': 'brief', 'titre': 'Brief reçu', 'detail': None}]
    points = possibles = None
    if versions:
        evts.append({'le': None, 'type': 'prise', 'titre': 'Prise en charge', 'detail': f'{PRENOM[graphiste]} démarre le chronomètre'})
        for v in range(1, versions + 1):
            evts.append({'le': None, 'type': 'bat', 'titre': f'BAT V{v} envoyé', 'detail': 'par WhatsApp et espace client'})
            if v < versions:
                evts.append({'le': None, 'type': 'erreur' if erreur and v == 1 else 'retour', 'titre': 'Erreur à corriger' if erreur and v == 1 else 'Retour client',
                             'detail': 'erreur du graphiste' if erreur and v == 1 else 'demande du client'})
    if envoye:
        faits = {'g_bat_a_temps': 1} if a_temps else {'g_retard_30': R.randint(1, 3)}
        faits.update({'g_erreur': 1} if erreur else {'g_sans_erreur': 1})
        if versions > 1 and not erreur:
            faits['g_avis_client'] = versions - 1
        brut = (10 if a_temps else -2 * faits.get('g_retard_30', 0)) + (-10 if erreur else 5)
        points = int(brut * coef) if brut > 0 else brut
        possibles = int(15 * coef)
        evts.append({'le': None, 'type': 'valide', 'titre': 'BAT validé', 'detail': 'par le client'})
        evts.append({'le': None, 'type': 'production', 'titre': 'Envoyé en production', 'detail': f'fichier transmis à la file {p["machine_nom"]}'})
    for n_e, e in enumerate(evts):
        mins = k['m'] + 20 + n_e * R.randint(10, 35)
        e['le'] = f'@{d}:{h0 * 60 + mins}'  # date ISO calculée au chargement
    add('conception', {'difficulte': diff, 'echeance_bat': T(d, h0 + 4), 'temps_prevu': prevu, 'demarre_le': T(d, h0, k['m'] + 30) if cst == 'en_cours' else None,
                       'secondes': int(prevu * 60 * R.uniform(0.6, 1.4)) if versions else 0, 'versions': versions, 'erreurs': erreur, 'changements_client': max(0, versions - 1 - erreur),
                       'premier_bat_le': T(d, h0 + 1, 10) if versions else None, 'dernier_bat_le': T(d, h0 + 1, 50) if versions else None, 'valide_le': T(d, h0 + 2) if envoye else None,
                       'points': points, 'points_possibles': possibles, 'faits': faits, 'evenements': evts, 'cree_le': T(d, h0, k['m'] + 20), 'controle_id': ctl,
                       'graphiste_id': graphiste if versions or cst == 'en_cours' else None})
    if not envoye:
        if R.random() < 0.5:
            add('stock_reservation', {'quantite': round(l['base'], 3), 'statut': 'active', 'date_reservation': T(d, h0 + 1), 'date_liberation': None, 'date_consommation': None,
                                      'observation': None, 'article_id': p['art'], 'commande_detail_id': l['id']})
        continue
    # Ordre de production.
    nop += 1
    machine = p['machine']
    operateur = U['oumar'] if p['machine_nom'].startswith('Solvant') else U['ibrahim']
    m2 = round((l['surface'] or 0) * l['qte'], 2) if p['mode'] == 'metre_carre' else None
    duree = max(15, int((m2 or 0) / 20 * 60) if m2 else int(l['qte'] / 60) + 20)
    fini = st not in ('en_production',)
    debut = l['debut'] if l['debut'] is not None else d
    h_deb = R.randint(8, 15)
    fin_prev = h_deb * 60 + duree
    termine_min = fin_prev + R.randint(-20, 40)
    op = add('ordre_production', {'numero': f'OP-D{nop:05d}', 'numero_etiquette': None, 'etiquette_affectee_le': None, 'statut': 'termine' if fini else R.choice(['en_cours', 'en_pause', 'a_produire']),
                                  'priorite': R.choice(['normale', 'normale', 'haute', 'urgente', 'basse']), 'quantite': l['qte'], 'quantite_produite': l['qte'] if fini else 0, 'quantite_rebut': 0,
                                  'instructions': None, 'observation_fin': None, 'cree_le': T(d, h0 + 2, 15), 'date_limite': T(max(-3, d - 2), 17),
                                  'demarre_le': T(debut, h_deb) if fini or R.random() < 0.6 else None, 'termine_le': T(debut, 0, termine_min) if fini else None, 'transmis_le': T(d, h0 + 2, 15),
                                  'debut_prevu_le': T(debut, h_deb), 'fin_prevue_le': T(debut, 0, fin_prev), 'duree_estimee_minutes': duree, 'duree_pause_minutes': 0, 'pause_debute_le': None,
                                  'etiquette_affectee_par_id': None, 'commande_detail_id': l['id'], 'controle_pre_presse_id': ctl, 'machine_id': machine, 'cree_par_id': GRAPHISTES[0],
                                  'operateur_id': operateur, 'termine_par_id': operateur if fini else None})
    add('ordre_production_fichier', {'ordre_production_id': op, 'commande_detail_fichier_id': fich}, False)
    if fini:
        conso = round(l['base'], 3)
        add('stock_sorties', {'quantite': conso, 'date': T(debut, 0, termine_min), 'origine': 'production', 'reference_origine': f'OP-D{nop:05d}', 'article_id': p['art'], 'commande_detail_id': l['id']})
        ARTICLE_INFO[p['art']]['stock'] -= conso
        if m2:
            M_COMPTEUR[machine] += m2
            if R.random() < 0.3:
                GACHE[(machine, debut)] += round(m2 * R.uniform(0.01, 0.06), 1)
        prod = add('production', {'date_debut': T(debut, h_deb), 'date_fin': T(debut, 0, termine_min), 'temps': duree, 'm2_imprimes': int(m2 or 0), 'etat': 1, 'gestion_stock': 1,
                                  'commande_details_id': l['id'], 'machine_id': machine})
        if p['machine_nom'].startswith('Solvant') and m2:
            for encre in range(1, 5):
                ml = int(m2 * R.uniform(5, 9))
                add('consommation_encres', {'encre': encre, 'quantite': ml, 'cout': int(ml * 28), 'production_id': prod})

# Journal des machines : têtes, gâche, incidents, réimpressions.
for d in range(60, -1, -1):
    for nom, mid in MACHINES.items():
        op = U['oumar'] if nom.startswith('Solvant') else U['ibrahim']
        if nom.startswith('Solvant') and R.random() < 0.9:
            add('journal_machine', {'type': 'tetes', 'quantite': None, 'note': 'Nettoyage des têtes', 'le': T(d, 8, R.randint(0, 50)), 'machine_id': mid, 'operateur_id': op, 'ordre_id': None})
        if GACHE.get((mid, d)):
            add('journal_machine', {'type': 'gache', 'quantite': GACHE[(mid, d)], 'note': R.choice(['Bande sur l\'impression', 'Mauvais calage', 'Tête bouchée']), 'le': T(d, R.randint(9, 16), R.randint(0, 59)),
                                    'machine_id': mid, 'operateur_id': op, 'ordre_id': None})
        if R.random() < 0.04:
            add('journal_machine', {'type': 'incident', 'quantite': None, 'note': R.choice(['Coupure de courant', 'Bourrage du média', 'Erreur de chauffe']), 'le': T(d, R.randint(9, 16), R.randint(0, 59)),
                                    'machine_id': mid, 'operateur_id': op, 'ordre_id': None})
        if R.random() < 0.01:
            add('journal_machine', {'type': 'reimpression', 'quantite': 2.0, 'note': 'Couleur trop claire', 'le': T(d, 15), 'machine_id': mid, 'operateur_id': op, 'ordre_id': None})
for row in ROWS['machines']:
    row['compteur_m2'] = round(M_COMPTEUR[row['id']] + R.randint(5000, 20000), 1)
    row['compteur_feuilles'] = R.randint(100000, 400000) if row['mode_facturation'] == 'feuille' else 0
for nom, mid in MACHINES.items():
    for _ in range(R.randint(3, 5)):
        prev = R.random() < 0.6
        d = R.randint(5, JOURS)
        cout = R.choice([15000, 35000, 60000]) if prev else R.choice([85000, 150000, 320000])
        add('maintenance', {'date': T(d, 9), 'type': 'Préventive' if prev else 'Corrective', 'description': R.choice(['Graissage des rails', 'Changement des amortisseurs', 'Nettoyage complet']) if prev else R.choice(['Remplacement d\'une tête', 'Carte mère', 'Courroie de chariot']),
                            'cout': str(cout), 'technicien': R.choice(['Technicien Infiniti', 'Atelier Diallo', 'Oumar Sangaré']), 'machine_id': mid, 'duree_arret_minutes': R.choice([30, 60, 120]) if prev else R.choice([240, 480, 1440])})
        mouvement('decaissement', 'entretien', cout, f'Maintenance {nom}', d, 11, 0, source=COMPTES['caisse'] if cout < 100000 else COMPTES['banque'], mode='especes' if cout < 100000 else 'virement_bancaire', agent=U['fanta'])

# Livraisons (W04) et étiquettes.
for k in COMMANDES:
    if not k['livraison'] or k['d'] > DETAIL or k['etat'] not in ('livree', 'en_livraison', 'prete_livraison'):
        continue
    nbl += 1
    livreur = R.choice(LIVREURS)
    jour = k['livre_le'] if k['livre_le'] is not None else 0
    debut_creneau = R.choice([9, 11, 14, 16])
    livre = k['etat'] == 'livree'
    en_route = k['etat'] == 'en_livraison'
    retard = R.random() < 0.15
    arrive = debut_creneau * 60 + (R.randint(130, 200) if retard else R.randint(10, 100))
    bl = add('bon_livraison', {'numero': f'BL-D{nbl:05d}', 'cree_le': T(jour, 8), 'valide_le': T(jour, 8, 30), 'livre_le': T(jour, 0, arrive + 8) if livre else None,
                               'statut': 'livre' if livre else 'valide', 'nom_receptionnaire': k['c']['contacts'][0][0] if k['c']['contacts'] else k['c']['nom'], 'telephone_receptionnaire': k['c']['tel'],
                               'adresse_livraison': f'{R.choice(sum(COMMUNES.values(), []))}, Bamako', 'observation': None, 'gestion_stock': 0, 'coefficient_stock': 1.0, 'commande_id': k['id'],
                               'cree_par_id': k['agent'], 'valide_par_id': k['agent'], 'livre_par_id': livreur if livre else None, 'article_stock_id': None, 'prevu_le': J(jour),
                               'creneau': f'{debut_creneau:02d}:00-{debut_creneau + 2:02d}:00', 'ordre_tournee': R.randint(1, 5), 'charge_le': T(jour, debut_creneau - 1, 30) if livre or en_route else None,
                               'en_route_le': T(jour, debut_creneau - 1, 45) if livre or en_route else None, 'arrive_le': T(jour, 0, arrive) if livre else None,
                               'photos': [f'demo-livraison-{hexa(8)}.jpg'] if livre else None, 'signature': f'demo-signature-{hexa(8)}.png' if livre else None,
                               'signataire': (k['c']['contacts'][0][0] if k['c']['contacts'] else k['c']['nom']) if livre else None, 'encaisse_montant': None, 'encaisse_mode': None, 'encaisse_le': None,
                               'livreur_id': livreur, 'paiement_livraison_id': None, 'remise_latitude': round(12.6 + R.uniform(-0.05, 0.05), 6) if livre and R.random() < 0.9 else None,
                               'remise_longitude': round(-7.99 + R.uniform(-0.07, 0.07), 6) if livre and R.random() < 0.9 else None,
                               'retard_justifie': 'Embouteillage au pont des Martyrs' if retard and R.random() < 0.5 else None, 'erreur_livraison': 'Colis abîmé' if livre and R.random() < 0.02 else None, 'public_id': uid_bl()})
    for l in k['lignes']:
        add('bon_livraison_ligne', {'designation': l['p']['nom'] if l['p'] else l['libre'], 'quantite_commandee': l['qte'], 'quantite_livree': l['qte'] if livre else 0, 'unite': 'unité',
                                    'observation': None, 'bon_livraison_id': bl, 'commande_detail_id': l['id']})

for lot_n, (age, qte) in enumerate([(100, 150), (25, 150)]):
    lot = add('lot_etiquette', {'numero': f'LOT-D{lot_n + 1:03d}', 'quantite': qte, 'prefixe': f'SI{lot_n + 1}', 'dossier': f'var/etiquettes/demo-lot-{lot_n + 1}', 'fichier_zip': None,
                                'statut': 'pret', 'cree_le': T(age, 10), 'termine_le': T(age, 10, 5), 'genere_par_id': U['kadiatou']})
    cibles = [k for k in COMMANDES if age - 30 < k['d'] <= age][:qte - 20]
    for n in range(qte):
        k = cibles[n] if n < len(cibles) else None
        statut = 'disponible' if k is None else ({'livree': 'livree', 'en_livraison': 'en_livraison', 'prete_livraison': 'prete_livraison'}.get(k['etat'], 'associee'))
        add('etiquette', {'numero': f'SI{lot_n + 1}-{n + 1:05d}', 'token': hexa(64), 'fichier_qr': f'demo-lot-{lot_n + 1}/{n + 1:05d}.png', 'statut': statut, 'cree_le': T(age, 10),
                          'imprime_le': T(age - 1, 9), 'associe_le': T(k['d'], 15) if k else None, 'lot_id': lot, 'commande_id': k['id'] if k else None,
                          'commande_detail_id': k['lignes'][0]['id'] if k else None, 'associe_par_id': U['oumar'] if k else None})

# Relances, rappels et retraits.
for k in COMMANDES:
    reste = k['a_payer'] - k['paye']
    if reste > 0 and k['d'] > 7:
        for n in range(R.randint(1, 3)):
            add('relance_creance', {'canal': R.choice(['whatsapp', 'sms', 'appel']), 'envoyee_le': T(max(0, k['d'] - 7 - n * 10), 10), 'commande_id': k['id'], 'par_id': k['agent']})
        add('rappel_paiement_envoye', {'canal': 'sms', 'date_envoi': T(max(0, k['d'] - 7), 8), 'commande_id': k['id'], 'regle_id': REGLES[0], 'public_id': uid_rappel()})
        if k['d'] > 30:
            add('rappel_paiement_envoye', {'canal': 'sms', 'date_envoi': T(k['d'] - 30, 8), 'commande_id': k['id'], 'regle_id': REGLES[1], 'public_id': uid_rappel()})
    if k['etat'] in ('terminee', 'prete_livraison') and not k['livraison']:
        add('relance_retrait', {'canal': R.choice(['whatsapp', 'sms']), 'envoyee_le': T(max(0, k['d'] - 3), 9), 'commande_id': k['id'], 'par_id': k['agent'], 'regle_id': REGLES[2]})

# ------------------------------------------------- CRM : notes, journal, etc.
TRAVAUX = ['bâche 400 × 150 cm', 'cartes de visite', 'roll-up', 't-shirts DTF', 'flyers A5', 'calendriers', 'kakémonos', 'autocollants', 'banderoles', 'casquettes', 'affiches A2']
for c in CLIENTS:
    if R.random() < 0.35:
        for _ in range(R.randint(1, 3)):
            typ, texte = R.choice(NOTES)
            le = R.randint(0, min(c['cree'], 120))
            add('note_client', {'le': T(le, *heure()), 'type': typ, 'texte': texte.format(t=R.choice(TRAVAUX)),
                                'rappel_le': T(-R.randint(1, 10), 9) if 'rappeler' in texte.lower() else None, 'client_id': c['id'], 'auteur_id': c['suivi']})
    add('modification_client', {'le': T(c['cree'], 9), 'auteur': f'demo.{[k for k, v in U.items() if v == c["suivi"]][0]}', 'champ': 'Fiche créée', 'categorie': 'identite', 'avant': None,
                                'apres': f'{nom_client(c)} · {c["t"].capitalize()}', 'depuis': 'erp_local', 'client_id': c['id'], 'par_id': c['suivi']})
    if R.random() < 0.4:
        chg = []
        if c['delai']:
            chg.append(('Délai de paiement', 'conditions', 'Comptant', DELAIS[c['delai']], 'erp_local'))
        if c['plafond']:
            chg.append(('Plafond de crédit', 'conditions', f'{c["plafond"] // 2:,} F'.replace(',', ' '), f'{c["plafond"]:,} F'.replace(',', ' '), 'erp_local'))
        chg.append(('Téléphone', 'identite', '60 ' + fmt_tel('60' + str(R.randint(100000, 999999)))[3:], fmt_tel(c['tel']), R.choice(['espace_client', 'admin_en_ligne'])))
        if c['b2b']:
            chg.append(('Grille tarifaire', 'conditions', 'Standard', 'Entreprise', 'admin_en_ligne'))
        for champ, cat, av, ap, depuis in R.sample(chg, k=min(len(chg), R.randint(1, 3))):
            add('modification_client', {'le': T(R.randint(0, c['cree'] - 1), *heure()), 'auteur': c['nom'] if depuis == 'espace_client' else 'demo.aminata', 'champ': champ, 'categorie': cat,
                                        'avant': av, 'apres': ap, 'depuis': depuis, 'client_id': c['id'], 'par_id': None if depuis == 'espace_client' else U['aminata']})

PROSPECTS = []
for n in range(40):
    act, _ = R.choice(ACTIVITES)
    etape = R.choices(['nouveau', 'devis_envoye', 'negociation', 'gagne', 'perdu'], weights=[30, 25, 20, 15, 10])[0]
    cree = R.randint(0, 90)
    gagne = R.choice(CLIENTS[:15]) if etape == 'gagne' else None
    pr = add('prospect', {'nom': gagne['rs'] or gagne['nom'] if gagne else f'{act} {R.choice(LIEUX)} {R.choice(["Plus", "Express", "Center", "Services"])}',
                          'telephone': '+223 ' + fmt_tel(gagne['tel'] if gagne else telephone()), 'besoin': R.choice(TRAVAUX).capitalize(),
                          'montant_estime': R.choice([50000, 120000, 250000, 450000, 900000]), 'source': R.choice(ORIGINES), 'etape': etape,
                          'motif_perte': R.choice(['Prix trop élevé', 'Délai trop long', 'Parti chez un concurrent']) if etape == 'perdu' else None,
                          'prochaine_relance': J(-R.randint(0, 7)) if etape not in ('perdu', 'gagne') else None, 'cree_le': T(cree, *heure()), 'mis_a_jour_le': T(max(0, cree - R.randint(0, 5)), 11),
                          'cloture_le': T(max(0, cree - 3), 12) if etape in ('perdu', 'gagne') else None, 'client_id': gagne['id'] if gagne else None, 'agent_id': R.choice(COMMERCIAUX[:3])})
    PROSPECTS.append((pr, etape, cree))

# Comptes de l'espace client : téléphone enregistré comme le fait CompteClient (223 + 8 chiffres),
# mot de passe demo1234 (les clients DEMO- ne reçoivent jamais de code SMS).
for c in R.sample([c for c in CLIENTS if c['t'] != 'particulier'], 45):
    add('compte_client', {'telephone': '223' + c['tel'], 'mot_de_passe': MDP, 'actif': 1, 'cree_le': T(R.randint(5, 120)), 'derniere_connexion': T(R.randint(0, 20), *heure()), 'client_id': c['id'],
                           'droits': '["valideur","payeur","administrateur"]'})
nref = 0
for c in R.sample([c for c in CLIENTS if c['t'] != 'particulier'], 30):
    for code in R.sample(['D-BACHE', 'D-ROLLUP', 'D-FLYER', 'D-CARTE', 'D-TSHIRT', 'D-KAKEMONO'], R.randint(1, 2)):
        nref += 1
        p = P[code]
        pc = add('produit_client', {'reference': f'PC-D{nref:04d}', 'nom': f'{p["nom"]} {nom_client(c)[:40]}', 'largeur': 3.0 if code == 'D-BACHE' else None, 'hauteur': 1.0 if code == 'D-BACHE' else None,
                                    'unite_vente': 'lot' if code in ('D-FLYER', 'D-CARTE') else 'piece',
                                    # Prix du lot de 500 pour les flyers et cartes, de la bâche de 3 m² pour la bâche.
                                    'prix_unitaire': p['b2b'] * 500 if code in ('D-FLYER', 'D-CARTE') else p['b2b'] * 3 if code == 'D-BACHE' else p['b2b'],
                                    'quantite_minimum': 1, 'taille_lot': 500 if code in ('D-FLYER', 'D-CARTE') else None, 'poses_par_feuille': 8 if code in ('D-FLYER', 'D-CARTE') else None,
                                    'arrondi_superieur_impose': 0, 'actif': 1, 'cree_le': T(R.randint(10, 200)), 'public_id': uid(), 'client_id': c['id'], 'produit_id': p['id'], 'support_id': p['sup'],
                                    'machine_id': p['machine'], 'fichier_id': None, 'bat_valide_id': None, 'ligne_origine_id': None, 'remplace_id': None})
        if p['fins'] and R.random() < 0.5:
            add('produit_client_finition', {'produit_client_id': pc, 'finition_id': FINITIONS[list(p['fins'])[0]]}, False)

# « Mes produits » (maquettes P01 à P03) : la gamme complète d'un client à compte en ligne (DEMO-0002),
# avec un tirage à part pour ne rien décaler dans le reste de la démonstration.
R3 = random.Random(1310)
for nom, code, unite, prix, larg, haut, lot in [
    ('Bâche façade', 'D-BACHE', 'piece_ou_m2', 29000, 4.0, 1.5, None),
    ('Vinyle vitrine micro-perforé', 'D-MICRO', 'm2', 7500, 1.37, None, None),
    ('Roll-up boutique', 'D-ROLLUP', 'piece', 25000, 0.85, 2.0, None),
    ('T-shirt logo (DTF)', 'D-TSHIRT', 'piece', 3500, None, None, None),
    ('Flyers A5', 'D-FLYER', 'lot', 30000, None, None, 500),
    ('Cartes de visite', 'D-CARTE', 'lot', 20000, None, None, 500),
]:
    nref += 1
    p = P[code]
    add('produit_client', {'reference': f'PC-D{nref:04d}', 'nom': nom, 'largeur': larg, 'hauteur': haut, 'unite_vente': unite, 'prix_unitaire': prix,
                           'quantite_minimum': 1, 'taille_lot': lot, 'poses_par_feuille': 8 if lot else None, 'arrondi_superieur_impose': 0, 'actif': 1,
                           'cree_le': T(R3.randint(20, 120)), 'public_id': S('UNHEX(\'' + uuid.UUID(int=R3.getrandbits(128), version=4).hex + '\')'),
                           'client_id': CLIENTS[1]['id'], 'produit_id': p['id'], 'support_id': p['sup'], 'machine_id': p['machine'],
                           'fichier_id': None, 'bat_valide_id': None, 'ligne_origine_id': None, 'remplace_id': None})

# Campagnes marketing.
for nom, canal, segment, age, modele in [('Promo bâches saison sèche', 'sms', 'entreprises', 200, 'Promotion du mois (démo)'), ('Rentrée scolaire', 'whatsapp', 'administrations', 120, 'Promotion du mois (démo)'),
                                         ('Fêtes de fin d\'année', 'sms', 'tous', 300, 'Promotion du mois (démo)'), ('Clients endormis', 'whatsapp', 'inactifs', 10, 'Promotion du mois (démo)')]:
    cibles = [c for c in CLIENTS if c['marketing']][:R.randint(40, 90)]
    camp = add('campagne', {'nom': nom, 'statut': 'en_cours' if age < 15 else 'terminee', 'sender_name': 'SuccesImpr', 'nombre_total': len(cibles), 'date_creation': T(age + 1, 10),
                            'modele_message_id': MODELES[modele], 'cree_par_id': U['kadiatou'], 'canal': canal, 'message': f'{nom} : profitez de nos offres chez Succès Imprim.',
                            'segment': segment, 'envoi_prevu_le': T(age, 9)})
    for c in cibles:
        st = 'en_attente' if age < 15 and R.random() < 0.3 else R.choice(['envoye'] * 8 + ['echec', 'ignore'])
        add('campagne_destinataire', {'statut': st, 'erreur': 'Numéro injoignable' if st == 'echec' else None, 'date_envoi': T(age, 9, R.randint(0, 59)) if st == 'envoye' else None,
                                      'campagne_id': camp, 'client_id': c['id']})

# Actions de caisse (AC01-AC04).
for _ in range(320):
    typ = R.choice(['facture', 'facture', 'verifier', 'devis', 'commande', 'prospect', 'inactif', 'avis', 'manuelle'])
    c = R.choice(CLIENTS)
    d = R.randint(0, 90)
    faite = d > 1 and R.random() < 0.8
    a_temps = R.random() < 0.8
    pour = R.choice(COMMERCIAUX[:3])
    resultat = R.choice(['promesse', 'paye', 'pas_de_reponse', 'a_rappeler']) if typ in ('facture', 'verifier') else R.choice(['accord', 'pas_de_reponse', 'a_rappeler', 'refus', 'fait'])
    add('action_caisse', {'type': typ, 'cause': {'facture': 'Facture échue', 'verifier': 'Paiement à vérifier', 'devis': 'Devis sans réponse', 'commande': 'Commande prête', 'prospect': 'Nouveau prospect',
                                                 'inactif': 'Client sans commande depuis 60 jours', 'avis': 'Commande livrée', 'manuelle': 'Rappel d\'une note'}[typ],
                          'libelle': nom_client(c), 'detail': None, 'cible_type': None, 'cible_id': None, 'telephone': c['tel'], 'montant': R.choice([25000, 60000, 150000]) if typ == 'facture' else None,
                          'cree_le': T(d, 8), 'depart_le': T(d, 8), 'echeance': T(d, 12), 'etat': 'faite' if faite else ('sans_objet' if R.random() < 0.05 else 'a_faire'),
                          'faite_le': T(d, 9 if a_temps else 15, R.randint(0, 59)) if faite else None, 'resultat': resultat if faite else None, 'note': None,
                          'promesse_le': J(d - 5) if faite and resultat == 'promesse' else None, 'promesse_moyen': 'Orange Money' if faite and resultat == 'promesse' else None,
                          'contact_le': T(d, 9) if faite else None, 'contact_canal': R.choice(['appel', 'whatsapp']) if faite else None, 'points': (10 if a_temps else 0) if faite else 0,
                          'penalite': 0 if not faite or a_temps else 15, 'controle_le': None, 'points_annules': 0, 'motif_controle': None, 'client_id': c['id'], 'prospect_id': None,
                          'assigne_a_id': pour, 'cree_par_id': None, 'faite_par_id': pour if faite else None, 'controle_par_id': None})
for did, c, d in DEVIS_OUVERTS[:20]:
    add('action_caisse', {'type': 'devis', 'cause': 'Devis sans réponse', 'libelle': nom_client(c), 'detail': None, 'cible_type': 'devis', 'cible_id': did, 'telephone': c['tel'], 'montant': None,
                          'cree_le': T(0, 8), 'depart_le': T(0, 8), 'echeance': T(0, 12), 'etat': 'a_faire', 'faite_le': None, 'resultat': None, 'note': None, 'promesse_le': None, 'promesse_moyen': None,
                          'contact_le': None, 'contact_canal': None, 'points': 0, 'penalite': 0, 'controle_le': None, 'points_annules': 0, 'motif_controle': None, 'client_id': c['id'], 'prospect_id': None,
                          'assigne_a_id': c['suivi'], 'cree_par_id': None, 'faite_par_id': None, 'controle_par_id': None})

# ---------------------------------------------- stock : achats et entrées
nach = 0
for mois in range(12, -1, -1):
    for _ in range(R.randint(2, 4)):
        nach += 1
        d = max(1, mois * 30 + R.randint(-12, 12))
        fourn = R.choice(F)
        arts = [a for a, info in ARTICLE_INFO.items() if info['fourn'] == fourn] or list(ARTICLE_INFO)[:3]
        recu = d > 3
        ach = nid('achats')
        total = 0
        details = []
        for a in R.sample(arts, k=min(len(arts), R.randint(1, 3))):
            info = ARTICLE_INFO[a]
            qte = {'m2': R.choice([200, 300, 500]), 'litre': R.choice([5, 10]), 'metre': 100, 'kg': 5, 'unite': R.choice([50, 100, 200]), 'boite': 2, 'feuille': R.choice([2000, 5000])}[info['unite']]
            if info['pa'] > 100000:
                qte = 1
            pu = int(info['pa'] * R.uniform(0.95, 1.08))
            details.append((a, qte, pu))
            total += qte * pu
        add('achats', {'id': ach, 'numero': f'ACH-D{nach:04d}', 'statut': 'recu' if recu else 'demande', 'date_demande': T(d + 5, 10), 'date_reception': T(d, 11) if recu else None,
                       'observation': None, 'total_ht': total, 'tva': 0, 'total_ttc': total, 'fournisseur_id': fourn})
        for a, qte, pu in details:
            add('achat_detail', {'quantite': qte, 'prix_unitaire': pu, 'total_ht': qte * pu, 'achat_id': ach, 'article_id': a})
            if recu:
                add('stock_entrees', {'quantites': float(qte), 'prix': pu, 'date': T(d, 11), 'article_id': a})
                ARTICLE_INFO[a]['stock'] += qte
        if recu:
            mouvement('decaissement', 'achat', total, f'Achat ACH-D{nach:04d} · fournisseur', d, 12, 0, source=COMPTES['banque'] if total > 300000 else COMPTES['caisse'],
                      mode='virement_bancaire' if total > 300000 else 'especes', agent=U['fanta'])
for a, info in ARTICLE_INFO.items():
    if info['stock'] < info['smin'] * 0.5:
        manque = info['smin'] * 2 - info['stock']
        add('stock_entrees', {'quantites': float(round(manque, 3)), 'prix': info['pa'], 'date': T(JOURS, 9), 'article_id': a})
        info['stock'] += manque
    if R.random() < 0.15:
        add('stock_sorties', {'quantite': float(round(info['smin'] * 0.8, 3)), 'date': T(R.randint(1, 20), 15), 'origine': 'manuelle', 'reference_origine': 'Inventaire', 'article_id': a, 'commande_detail_id': None})
        info['stock'] -= info['smin'] * 0.8
for row in ROWS['articles']:
    row['stock'] = round(ARTICLE_INFO[row['id']]['stock'], 3)

# ---------------------------------------------------- personnel : RH et paie
point = add('point_pointage', {'code': 'PT-99', 'nom': 'Boutique (démo)', 'secret': hexa(32), 'latitude': 12.6392, 'longitude': -8.0029, 'rayon_metres': 80, 'actif': 1, 'renouvele_le': T(30, 7)})
APPAREILS = {}
for cle, uid_ in U.items():
    APPAREILS[uid_] = add('appareil_pointage', {'empreinte': hexa(64), 'nom': 'Safari · iPhone' if len(APPAREILS) % 4 == 1 else 'Chrome · Android', 'etat': 'autorise', 'cree_le': T(200, 8), 'decide_le': T(199, 9),
                                                 'utilisateur_id': uid_, 'decide_par_id': U['fanta']})
add('appareil_pointage', {'empreinte': hexa(64), 'nom': 'Nouveau téléphone (Sékou)', 'etat': 'en_attente', 'cree_le': T(1, 8), 'decide_le': None, 'utilisateur_id': U['sekou'], 'decide_par_id': None})
for nom, role, etat in [('Poste caisse boutique', 'encaissement', 'autorise'), ('PC direction', 'admin', 'autorise'), ('Tablette accueil', 'lecture', 'autorise'), ('PC inconnu', 'lecture', 'en_attente')]:
    add('appareil_autorise', {'nom': nom, 'role_en_ligne': role, 'etat': etat, 'empreinte': hexa(64), 'cree_le': T(150), 'expire_le': None, 'autorise_le': T(149) if etat == 'autorise' else None,
                              'derniere_connexion': T(R.randint(0, 3), 8), 'ip': f'10.90.0.{R.randint(50, 90)}', 'navigateur': 'Chrome 129', 'identifiant_tente': None,
                              'utilisateur_id': U['aminata'] if role == 'encaissement' else U['kadiatou'], 'cree_par_id': U['kadiatou']})

ABSENCES = set()
for cle, uid_ in U.items():
    for _ in range(R.randint(0, 3)):
        d = R.randint(1, 60)
        ABSENCES.add((uid_, d))
for d in range(60, -1, -1):
    if d % 7 == 3:   # un jour de repos par semaine
        continue
    for cle, uid_ in U.items():
        if (uid_, d) in ABSENCES:
            continue
        arr = R.randint(7 * 60 + 40, 8 * 60 + 15) if R.random() < 0.85 else R.randint(8 * 60 + 16, 9 * 60 + 10)
        dep = R.randint(17 * 60 + 30, 18 * 60 + 40)
        pos = {'latitude': round(12.6392 + R.uniform(-0.0004, 0.0004), 6), 'longitude': round(-8.0029 + R.uniform(-0.0004, 0.0004), 6), 'distance': R.randint(3, 60)}
        add('pointage', {'arrivee_le': T(d, 0, arr), 'depart_le': T(d, 0, dep) if d > 0 else None, 'photo_arrivee': None, 'photo_depart': None,
                         'position_arrivee': pos, 'position_depart': pos if d > 0 else None, 'heures_sup_validees': int(dep > 18 * 60 + 30 and R.random() < 0.5), 'ajustement_points': 0,
                         'motif_ajustement': None, 'motif_correction': None, 'corrige': 0, 'utilisateur_id': uid_, 'point_arrivee_id': point, 'point_depart_id': point if d > 0 else None,
                         'appareil_arrivee_id': APPAREILS[uid_], 'appareil_depart_id': APPAREILS[uid_] if d > 0 else None})

for cle, uid_ in U.items():
    add('conge_employe', {'actif': 1, 'solde': float(R.choice([12, 15, 18, 22.5, 25])), 'plage_debut': J(-R.randint(20, 90)) if R.random() < 0.3 else None, 'plage_fin': None, 'plage_jours': None, 'utilisateur_id': uid_})
for uid_, d in sorted(ABSENCES)[:30]:
    typ = R.choice(['absence', 'absence', 'conge'])
    etat = R.choice(['acceptee', 'acceptee', 'refusee']) if d > 3 else 'en_attente'
    add('demande_employe', {'type': typ, 'categorie': None, 'cree_le': T(d + 2, 9), 'du': J(d), 'au': J(d), 'demi_journee': 0, 'jours': 1.0, 'motif': R.choice(['Maladie', 'Raison familiale', 'Démarche administrative', 'Baptême']),
                            'montant': None, 'montant_accorde': None, 'justificatif': None, 'etat': etat, 'commentaire': None, 'decide_le': T(d + 1, 11) if etat != 'en_attente' else None,
                            'utilisateur_id': uid_, 'decide_par_id': U['fanta'] if etat != 'en_attente' else None, 'mouvement_id': None})
for _ in range(14):
    uid_ = R.choice(list(U.values()))
    d = R.randint(0, 150)
    typ = R.choice(['reclamation', 'frais_medicaux'])
    montant = R.choice([10000, 15000, 25000, 40000])
    etat = R.choice(['payee', 'payee', 'acceptee', 'refusee']) if d > 5 else 'en_attente'
    mvt = mouvement('decaissement', 'salaire', montant, f'Remboursement {typ.replace("_", " ")} · {PRENOM[uid_]}', max(0, d - 3), 15, 0, source=COMPTES['caisse'], mode='especes',
                    agent=U['fanta'], confidentiel=True) if etat == 'payee' else None
    add('demande_employe', {'type': typ, 'categorie': R.choice(['heures_sup', 'frais_avances', 'prime']) if typ == 'reclamation' else None, 'cree_le': T(d, 10), 'du': J(d), 'au': None,
                            'demi_journee': 0, 'jours': None, 'motif': 'Frais de pharmacie' if typ == 'frais_medicaux' else 'Taxi pour livraison urgente', 'montant': montant,
                            'montant_accorde': montant if etat in ('payee', 'acceptee') else None, 'justificatif': None, 'etat': etat, 'commentaire': None,
                            'decide_le': T(max(0, d - 2), 11) if etat != 'en_attente' else None, 'utilisateur_id': uid_, 'decide_par_id': U['fanta'] if etat != 'en_attente' else None, 'mouvement_id': mvt})

for mois in range(12, -1, -1):
    d_paie = max(0, mois * 30 - 28)
    total_mois = 0
    for cle, prenom, nom, fonction, profil, plus, moins, salaire, objectif in EQUIPE:
        uid_ = U[cle]
        if mois <= 5:
            lignes = []
            if R.random() < 0.4:
                lignes.append({'type': 'prime', 'libelle': 'Prime de rendement', 'montant': R.choice([10000, 15000, 25000])})
            if R.random() < 0.15:
                lignes.append({'type': 'avance', 'libelle': 'Avance sur salaire', 'montant': -R.choice([20000, 30000])})
            lignes.append({'type': 'inps', 'libelle': 'Cotisation INPS', 'montant': -int(salaire * 0.036)})
            lignes.append({'type': 'its', 'libelle': 'ITS', 'montant': -int(salaire * 0.05)})
            net = salaire + sum(x['montant'] for x in lignes)
            etat = 'paye' if mois >= 1 else 'brouillon'
            mvt = None
            if etat == 'paye':
                mvt = mouvement('decaissement', 'salaire', net, f'Salaire {prenom} {nom}', d_paie, 10, 0, source=COMPTES['banque'], mode='virement_bancaire', agent=U['fanta'], confidentiel=True)
            add('bulletin_paie', {'mois': M1(mois), 'salaire_base': salaire, 'lignes': lignes, 'etat': etat, 'cree_le': T(d_paie + 2, 9), 'valide_le': T(d_paie + 1, 9) if etat == 'paye' else None,
                                  'utilisateur_id': uid_, 'valide_par_id': U['kadiatou'] if etat == 'paye' else None, 'mouvement_id': mvt})
        else:
            total_mois += int(salaire * 0.92)
    if total_mois:
        mouvement('decaissement', 'salaire', total_mois, 'Salaires du mois', d_paie, 10, 0, source=COMPTES['banque'], mode='virement_bancaire', agent=U['fanta'], confidentiel=True)

nrec = 0
for _ in range(18):
    nrec += 1
    agent = R.choice(COMMERCIAUX[:3] + LIVREURS)
    d = R.randint(0, 200)
    statut = R.choice(['payee', 'payee', 'validee', 'refusee']) if d > 4 else 'en_attente'
    montant = R.choice([3000, 5000, 7500, 12000, 20000])
    mvt = mouvement('decaissement', 'transport', montant, f'Réclamation RCL-D{nrec:04d}', max(0, d - 2), 16, 0, source=COMPTES['caisse'], mode='especes', agent=U['fanta']) if statut == 'payee' else None
    add('reclamations', {'reference': f'RCL-D{nrec:04d}', 'motif': R.choice(['Taxi pour livraison', 'Carburant moto', 'Achat de scotch en urgence', 'Crédit téléphone clients']), 'montant': montant,
                         'justificatif': None, 'statut': statut, 'date_creation': T(d, 10), 'date_validation': T(max(0, d - 1), 11) if statut in ('validee', 'payee') else None,
                         'motif_refus': 'Pas de justificatif' if statut == 'refusee' else None, 'date_refus': T(max(0, d - 1), 11) if statut == 'refusee' else None,
                         'date_paiement': T(max(0, d - 2), 16) if statut == 'payee' else None, 'notification_lue': 1, 'agent_id': agent,
                         'valide_par_id': U['fanta'] if statut in ('validee', 'payee') else None, 'refuse_par_id': U['fanta'] if statut == 'refusee' else None,
                         'paye_par_id': U['fanta'] if statut == 'payee' else None, 'mouvement_tresorerie_id': mvt, 'public_id': uid()})

# ----------------------------------- employés sans accès (maquettes E01 à E05)
# Ajoutés à part, après le reste : ils pointent sur la tablette de l'entrée.
# Rokia est invitée (lien pas encore ouvert), Lassana est parti.
R2 = random.Random(77)
AUTRES = {}
for cle, prenom, nom, fonction, contrat, rem, salaire, paiement, horaires, acces, profil in [
    ('adama', 'Adama', 'Keïta', 'Gardien de nuit', 'cdi', 'salaire', 95000, 'especes', ('19:00', '07:00', '1,2,3,4,5,6,7'), 'sans', None),
    ('nana', 'Nana', 'Togo', 'Aide atelier', 'journalier', 'journalier', 3500, 'especes', ('08:00', '17:00', None), 'sans', None),
    ('rokia', 'Rokia', 'Sissoko', 'Stagiaire PAO', 'stage', 'indemnite', 50000, 'orange_money', (None, None, None), 'invite', 'ROLE_GRAPHISTE'),
    ('lassana', 'Lassana', 'Diakité', 'Livreur', 'cdd', 'salaire', 85000, 'especes', (None, None, None), 'actif', 'ROLE_LIVREUR'),
]:
    e = add('employes', {'nom': nom, 'prenom': prenom, 'fonction': fonction, 'telephone': telephone(), 'email': None,
                         'salaires': salaire, 'date_naissances': J(R2.randint(20, 45) * 365), 'matricules': f'MAT-D{len(U) + len(AUTRES) + 1:03d}',
                         'date_embauches': J(R2.randint(200, 700) if cle != 'rokia' else 0), 'adresses': f'{R2.choice(list(COMMUNES))}, Bamako', 'cin': None,
                         'contrat': contrat, 'fin_prevue': J(-90) if cle == 'rokia' else (J(104) if cle == 'lassana' else None), 'type_remuneration': rem, 'mode_paiement': paiement,
                         'heure_debut': horaires[0], 'heure_fin': horaires[1], 'jours_travail': horaires[2], 'contact_urgence': 'Mère · +223 60 00 00 00' if cle == 'adama' else None,
                         'responsable_id': U['seydou'] if cle in ('rokia', 'nana') else U['fanta']})
    AUTRES[cle] = add('user', {'username': f'demo.{cle}', 'roles': [profil] if profil else [], 'password': MDP, 'actif': 0 if cle == 'lassana' else 1,
                               'date_add': T(R2.randint(100, 600)), 'date_update': T(R2.randint(1, 30)), 'must_change_password': 0, 'password_changed_at': None,
                               'last_login_at': T(105, 8, 12) if cle == 'lassana' else None, 'employe_id': e, 'droits_ajoutes': [], 'droits_retires': [],
                               'profil_id': S(f"(SELECT r.id FROM role_utilisateur r WHERE r.code = '{profil}')") if profil else None, 'objectif_ventes_mois': None,
                               'acces': acces, 'pin': None, 'pin_essais': 0, 'pin_bloque_jusqua': None,
                               # Empreinte d'un lien qui n'existe pas : l'invitation de démo ne peut pas s'ouvrir.
                               'invitation_empreinte': hexa(64) if acces == 'invite' else None, 'invitation_envoyee_le': T(1, 16, 10) if acces == 'invite' else None,
                               'invitation_expire_le': T(-6, 16, 10) if acces == 'invite' else None, 'fin_acces_le': J(-90) if cle == 'rokia' else (J(104) if cle == 'lassana' else None)})
for row in ROWS['user']:
    row.setdefault('disabled_at', None)
    row.setdefault('motif_desactivation', None)
for row in ROWS['user']:
    if row['id'] == AUTRES['lassana']:
        row['disabled_at'] = T(104, 18)
        row['motif_desactivation'] = 'Fin de contrat'
for cle in ('adama', 'nana'):
    add('conge_employe', {'actif': 1, 'solde': 11.0 if cle == 'adama' else 0.0, 'plage_debut': None, 'plage_fin': None, 'plage_jours': None, 'utilisateur_id': AUTRES[cle]})
# Pointages sur la tablette (sans téléphone ni position). Adama : de nuit.
for d in range(40, 0, -1):
    for cle in ('adama', 'nana'):
        if cle == 'adama':
            arr, dep, dd = R2.randint(18 * 60 + 45, 18 * 60 + 58), R2.randint(7 * 60, 7 * 60 + 12), d - 1
        else:
            if d % 7 == 2:
                continue
            arr, dep, dd = R2.randint(7 * 60 + 45, 8 * 60 + 10), R2.randint(17 * 60, 17 * 60 + 20), d
        fini = dd >= 0 and d > 0
        add('pointage', {'arrivee_le': T(d, 0, arr), 'depart_le': T(dd, 0, dep) if fini else None, 'photo_arrivee': None, 'photo_depart': None,
                         'position_arrivee': None, 'position_depart': None, 'heures_sup_validees': 0, 'ajustement_points': 0,
                         'motif_ajustement': None, 'motif_correction': None, 'corrige': 0, 'utilisateur_id': AUTRES[cle], 'point_arrivee_id': point, 'point_depart_id': point if fini else None,
                         'appareil_arrivee_id': None, 'appareil_depart_id': None})

# --------------------------------------------- charges, transferts, banque
for libelle, cat, montant, jour, freq, mois_an, compte in [('Loyer boutique et atelier', 'loyer', 450000, 5, 'mensuelle', None, 'banque'), ('Électricité EDM', 'electricite', 185000, 12, 'mensuelle', None, 'banque'),
                                                           ('Internet Orange Pro', 'autre_charge', 45000, 8, 'mensuelle', None, 'orange_money'), ('Gardiennage', 'autre_charge', 90000, 1, 'mensuelle', None, 'caisse'),
                                                           ('Eau SOMAGEP', 'autre_charge', 25000, 15, 'mensuelle', None, 'caisse'), ('Assurance multirisque', 'autre_charge', 650000, 10, 'annuelle', 1, 'banque')]:
    add('decaissement_recurrent', {'libelle': libelle, 'categorie': cat, 'montant': montant, 'frequence': freq, 'jour_du_mois': jour, 'mois_de_lannee': mois_an, 'actif': 1, 'date_debut': J(JOURS + 30),
                                   'prochaine_date_execution': J(-R.randint(3, 28)), 'derniere_date_execution': J(R.randint(2, 28)), 'date_creation': T(JOURS + 30), 'notes': None,
                                   'compte_source_id': COMPTES[compte], 'cree_par_id': U['kadiatou']})
    for mois in range(12, -1, -1):
        if freq == 'annuelle' and mois not in (4,):
            continue
        d = mois * 30 + 15 - jour
        if d < 0:
            continue
        mouvement('decaissement', cat, int(montant * (R.uniform(0.9, 1.15) if cat == 'electricite' else 1)), libelle, d, 10, 30, source=COMPTES[compte],
                  mode='virement_bancaire' if compte == 'banque' else ('orange_money' if compte == 'orange_money' else 'especes'), agent=U['fanta'])
for mois in range(12, 0, -1):
    mouvement('decaissement', 'remboursement_credit', 1500000, 'Échéance du crédit machine Solvant 3,20 m', mois * 30 - 10, 9, 0, source=COMPTES['banque'], mode='virement_bancaire')
for d in range(JOURS, -1, -1):
    if d % 7 == 2:
        mouvement('decaissement', 'carburant', R.choice([15000, 20000, 25000, 30000]), 'Carburant motos de livraison', d, 8, 15, source=COMPTES['caisse'], mode='especes', agent=U['aminata'])
    if d % 30 == 20:
        mouvement('decaissement', 'frais_bancaire', R.choice([7500, 9000, 12500]), 'Frais de tenue de compte', d, 7, 0, source=COMPTES['banque'], mode='virement_bancaire')

# Versements caisse → banque et Orange Money → banque, d'après les soldes.


def solde(compte, jusqu_a):
    s = 0
    for d, t, typ, montant, src, dst, statut, _ in MOUVEMENTS:
        if statut != 'valide' or (d, -t) < jusqu_a:
            continue
        if dst == compte:
            s += montant
        if src == compte:
            s -= montant
    return s


for d in range(JOURS, -1, -1):
    if d % 7 == 5:
        s = solde(COMPTES['caisse'], (d, -16 * 60))
        if s > 400000:
            m = int((s - 200000) // 10000 * 10000)
            mouvement('transfert', 'transfert_interne', m, 'Versement caisse en banque', d, 16, 0, source=COMPTES['caisse'], dest=COMPTES['banque'], mode='especes', agent=U['fanta'])
    if d % 14 == 6:
        for typ in ('orange_money', 'wave'):
            s = solde(COMPTES[typ], (d, -16 * 60 - 30))
            if s > 300000:
                m = int((s - 100000) // 10000 * 10000)
                mouvement('transfert', 'transfert_interne', m, f'Virement {typ.replace("_", " ").title()} vers la banque', d, 16, 30, source=COMPTES[typ], dest=COMPTES['banque'], mode=typ, agent=U['fanta'])

# Soldes : le solde initial couvre le point le plus bas de l'année.
for row in ROWS['compte_tresorerie']:
    cpt = row['id']
    s, bas = 0, 0
    for d, t, typ, montant, src, dst, statut, _ in sorted(MOUVEMENTS, key=lambda x: (-x[0], x[1])):
        if statut != 'valide':
            continue
        s += montant if dst == cpt else 0
        s -= montant if src == cpt else 0
        bas = min(bas, s)
    initial = int(-bas // 50000 * 50000 + 100000) if bas < 0 else 100000
    row['solde_initial'] = initial
    row['solde_actuel'] = initial + s

# Clôtures de caisse (soir) sur 45 jours.
init_caisse = next(r['solde_initial'] for r in ROWS['compte_tresorerie'] if r['id'] == COMPTES['caisse'])
for d in range(45, 0, -1):
    if d % 7 == 3:
        continue
    theorique = init_caisse + solde(COMPTES['caisse'], (d, -18 * 60 - 59))
    ecart = R.choice([0] * 8 + [-500, -2000, 1000])
    add('cloture_caisse', {'cloture_le': T(d, 19), 'montant_theorique': theorique, 'montant_compte': theorique + ecart, 'observation': 'Écart de monnaie' if ecart else None,
                           'compte_id': COMPTES['caisse'], 'cloture_par_id': U['aminata']})

# Rapprochements bancaires mensuels.
init_banque = next(r['solde_initial'] for r in ROWS['compte_tresorerie'] if r['id'] == COMPTES['banque'])
for mois in range(6, 0, -1):
    d_fin, d_debut = (mois - 1) * 30 + 1, mois * 30
    ouverture = init_banque + solde(COMPTES['banque'], (d_debut + 1, 0))
    cloture = init_banque + solde(COMPTES['banque'], (d_fin, -23 * 60))
    statut = 'cloture' if mois > 1 else 'brouillon'
    rb = add('rapprochement_bancaire', {'reference': f'RB-D{7 - mois:03d}', 'date_debut': J(d_debut), 'date_fin': J(d_fin), 'solde_ouverture_releve': ouverture, 'solde_cloture_releve': cloture,
                                        'solde_comptable': cloture, 'ecart': 0, 'statut': statut, 'observation': None, 'date_creation': T(d_fin, 9), 'date_validation': T(d_fin, 10) if statut == 'cloture' else None,
                                        'date_cloture': T(d_fin, 10) if statut == 'cloture' else None, 'compte_tresorerie_id': COMPTES['banque'], 'cree_par_id': U['fanta'],
                                        'valide_par_id': U['kadiatou'] if statut == 'cloture' else None})
    for d, t, typ, montant, src, dst, st, mid in MOUVEMENTS:
        if d_fin <= d <= d_debut and COMPTES['banque'] in (src, dst) and st == 'valide':
            add('ligne_rapprochement_bancaire', {'date_releve': J(d), 'reference_bancaire': f'RLV{R.randint(100000, 999999)}', 'montant_releve': montant if dst == COMPTES['banque'] else -montant,
                                                 'pointee': int(statut == 'cloture' or R.random() < 0.6), 'observation': None, 'date_creation': T(d_fin, 9), 'rapprochement_bancaire_id': rb, 'mouvement_tresorerie_id': mid})

# ------------------------------------------------ planning et entretien
ECHEANCES = [
    ('Déclaration TVA et IBIC', 'fiscal', 'mois', 15, [], '09:00', None, ['Préparer le relevé des ventes', 'Remplir la déclaration', 'Payer à la DGI'], 350000, 'fanta', False),
    ('Paiement du loyer', 'fournisseurs', 'mois', 5, [], '10:00', None, ['Virement au bailleur', 'Classer le reçu'], 450000, 'fanta', False),
    ('Préparation de la paie', 'paie', 'mois', 25, [], '09:00', None, ['Vérifier les pointages', 'Saisir primes et retenues', 'Valider les bulletins'], None, 'fanta', False),
    ('Nettoyage des têtes Solvant', 'atelier', 'jour', None, [1, 2, 3, 4, 5, 6], '08:00', '08:30', ['Purge', 'Test de buses', 'Nettoyage du capot'], None, 'oumar', False),
    ('Inventaire des supports', 'stock', 'semaine', None, [6], '16:00', None, ['Compter les rouleaux', 'Saisir les écarts'], None, 'ibrahim', False),
    ('Tournée commerciale', 'commercial', 'semaine', None, [2, 4], '10:00', None, ['Visiter 5 clients', 'Noter les besoins'], None, 'moussa', False),
    ('Ménage de la boutique', 'entretien', 'jour', None, [1, 2, 3, 4, 5, 6], '07:30', '08:00', ['Balayer', 'Laver le sol', 'Vider les poubelles'], None, 'awa', False),
    ('Nettoyage de l\'atelier', 'entretien', 'jour', None, [1, 2, 3, 4, 5, 6], '13:00', '13:45', ['Ramasser les chutes', 'Dépoussiérer les machines'], None, 'awa', False),
    ('Sanitaires', 'entretien', 'jour', None, [1, 2, 3, 4, 5, 6], '15:00', '15:30', ['Laver', 'Réapprovisionner savon et papier'], None, 'awa', False),
    ('Fermeture', 'entretien', 'jour', None, [1, 2, 3, 4, 5, 6], '18:30', '19:00', ['Éteindre les machines', 'Couper la climatisation', 'Fermer les fenêtres', 'Fermer le portail'], None, 'awa', True),
    ('Vidange du groupe électrogène', 'atelier', 'trimestre', 10, [], '08:00', None, ['Vidange', 'Changer le filtre'], 60000, 'oumar', False),
]
for titre, cat, freq, jour_mois, jours_sem, h, hf, etapes, montant, resp, fermeture in ECHEANCES:
    ech = add('echeance', {'titre': titre, 'categorie': cat, 'frequence': freq, 'jour_mois': jour_mois, 'jours_semaine': jours_sem, 'heure': h, 'decalage': 'avant', 'debut': J(200), 'fin_le': None,
                           'fin_nombre': None, 'etapes': etapes, 'rappels': {'j5': True, 'veille': True, 'jour': True, 'retard': True}, 'montant_estime': montant, 'actif': 1, 'cree_le': T(200), 'responsable_id': U[resp],
                           'copie_id': U['kadiatou'] if cat in ('fiscal', 'paie') else None, 'entreprise_id': None, 'cree_par_id': U['kadiatou'], 'heure_fin': hf, 'fermeture': int(fermeture),
                           'compte_id': (COMPTES['banque'] if montant >= 100000 else COMPTES['caisse']) if montant else None,
                           'categorie_depense': {'Paiement du loyer': 'loyer'}.get(titre)})
    if freq == 'jour':
        jours = [d for d in range(30, -8, -1) if d % 7 != 3]
    elif freq == 'semaine':
        jours = [d for d in range(30, -8, -1) if d % 7 in (1, 4)][::2 if len(jours_sem) == 1 else 1]
    elif freq == 'mois':
        jours = [60 - jour_mois, 30 - jour_mois if 30 - jour_mois >= 0 else -jour_mois]
    else:
        jours = [40]
    for d in jours:
        hh, mm = map(int, h.split(':'))
        fait = d > 0 or (d == 0 and R.random() < 0.5)
        retard = fait and R.random() < 0.1
        photo = cat == 'entretien' and fait
        ctrl = R.choice(['conforme', 'conforme', 'conforme', 'non_conforme']) if photo and R.random() < 0.3 else None
        envoyes = ['j5', 'veille', 'jour'] if d >= 0 else (['j5', 'veille'] if d == -1 else (['j5'] if d >= -5 else []))
        tache = add('tache', {'titre': titre, 'categorie': cat, 'prevue_le': J(d), 'date_origine': J(d), 'raison_decalage': None, 'heure': h, 'statut': 'fait' if fait else 'a_faire',
                      'etapes': [{'libelle': e, 'fait': fait} for e in etapes], 'montant_estime': montant, 'note': None, 'fait_le': T(d, hh + (1 if retard else 0), mm + R.randint(5, 25)) if fait else None,
                      'rappels_envoyes': envoyes if freq != 'jour' else (['veille', 'jour'] if d >= 0 else []), 'cree_le': T(max(d, 0) + 7, 6), 'echeance_id': ech, 'responsable_id': U[resp],
                      'copie_id': U['kadiatou'] if cat in ('fiscal', 'paie') else None, 'entreprise_id': None, 'fait_par_id': U[resp] if fait else None, 'heure_fin': hf, 'fermeture': int(fermeture),
                      'commence_le': T(d, hh, mm) if fait else None, 'photo': f'demo-{hexa(16)}.jpg' if photo else None, 'controle': ctrl, 'controle_le': T(d, 17) if ctrl else None,
                      'controle_par_id': U['fanta'] if ctrl else None})
        # Rappels WhatsApp du planning (étape 2) : envoyés par la direction pour les jours passés,
        # à envoyer pour ceux de la semaine qui vient.
        if freq != 'jour' and -5 <= d <= 10:
            prenom = next(e[1] for e in EQUIPE if e[0] == resp)
            for cle in envoyes:
                jours_avant = {'j5': 5, 'veille': 1, 'jour': 0}[cle]
                quand = d + jours_avant
                date = f"', DATE_FORMAT(CURDATE() - INTERVAL {d} DAY, '%d/%m/%Y'), '"
                quoi = {'j5': f'« {titre} » est prévu le {{D}}, dans 5 jours.', 'veille': f'« {titre} » est prévu demain, {{D}}.', 'jour': f'« {titre} » est prévu aujourd\'hui.'}[cle]
                texte = f"Bonjour {prenom},\nRappel du planning Succès Imprim : {quoi}\nMerci de le cocher dans l'application une fois fait."
                message = S('CONCAT(' + q(texte).replace('{D}', date) + ')')
                envoye = quand >= 1
                add('rappel_whatsapp', {'cle': cle, 'telephone': TEL[resp], 'message': message,
                                        'cree_le': T(max(quand, 0), 8), 'envoye_le': T(quand, 8, 20) if envoye else None, 'tache_id': tache, 'destinataire_id': U[resp],
                                        'envoye_par_id': U['kadiatou'] if envoye else None})
# Échéances de l'année (planning annuel T03) : leurs tâches viennent de la commande app:planning:rappels.
for titre, cat, freq, mois, montant, resp in [('Approvisionnement bâches et vinyle', 'stock', 'trimestre', 1, 1400000, 'ibrahim'),
                                              ('Approvisionnement encres', 'stock', 'trimestre', 2, 300000, 'oumar'),
                                              ('Maintenance annuelle des machines', 'atelier', 'annee', 2, 180000, 'oumar'),
                                              ('Renouvellement assurances et contrats', 'administratif', 'annee', 3, 250000, 'kadiatou'),
                                              ('Stock textile rentrée', 'stock', 'annee', 7, 2600000, 'ibrahim'),
                                              ('Campagne de fin d\'année', 'commercial', 'annee', 11, None, 'moussa'),
                                              ('Inventaire annuel et clôture des comptes', 'stock', 'annee', 12, None, 'fanta')]:
    add('echeance', {'titre': titre, 'categorie': cat, 'frequence': freq, 'jour_mois': 10, 'jours_semaine': [], 'heure': '09:00', 'decalage': 'avant',
                     'debut': S(f"MAKEDATE(YEAR(CURDATE()), 1) + INTERVAL {mois - 1} MONTH + INTERVAL 9 DAY"), 'fin_le': None, 'fin_nombre': None, 'etapes': [],
                     'rappels': {'j5': True, 'veille': True, 'jour': True, 'retard': True}, 'montant_estime': montant, 'actif': 1, 'cree_le': T(200),
                     'responsable_id': U[resp], 'copie_id': None, 'entreprise_id': None, 'cree_par_id': U['kadiatou'], 'heure_fin': None, 'fermeture': 0,
                     'compte_id': (COMPTES['banque'] if montant >= 100000 else COMPTES['caisse']) if montant else None, 'categorie_depense': 'achat' if cat == 'stock' and montant else None})
for titre, cat, d, resp in [('Renouveler la patente', 'fiscal', -20, 'fanta'), ('Commander l\'encre solvant', 'stock', -3, 'oumar'), ('Rendez-vous banque', 'administratif', -6, 'kadiatou'),
                            ('Relancer la mairie pour le paiement', 'commercial', 2, 'aminata')]:
    add('tache', {'titre': titre, 'categorie': cat, 'prevue_le': J(d), 'date_origine': None, 'raison_decalage': None, 'heure': '10:00', 'statut': 'a_faire' if d <= 0 else 'en_cours',
                  'etapes': [], 'montant_estime': None, 'note': None, 'fait_le': None, 'rappels_envoyes': [], 'cree_le': T(max(d, 0) + 10, 9), 'echeance_id': None, 'responsable_id': U[resp],
                  'copie_id': None, 'entreprise_id': None, 'fait_par_id': None, 'heure_fin': None, 'fermeture': 0, 'commence_le': None, 'photo': None, 'controle': None, 'controle_le': None, 'controle_par_id': None})
for jour, libelle in [('01-01', 'Jour de l\'an'), ('01-20', 'Fête de l\'armée'), ('03-26', 'Journée des martyrs'), ('05-01', 'Fête du travail'), ('05-25', 'Journée de l\'Afrique'),
                      ('09-22', 'Fête de l\'indépendance'), ('12-25', 'Noël'), ('03-20', 'Korité (fin du Ramadan)'), ('05-27', 'Tabaski'), ('08-26', 'Maouloud')]:
    add('jour_ferie', {'jour': S(f"CONCAT(YEAR(CURDATE()), '-{jour}')"), 'libelle': libelle})

# ------------------------------- patrimoine, amortissements et primes (M01 à N04)
# Tirage à part, pour ne pas décaler le reste des données. Codes IMM-D001… :
# le « D » des identifiants de démo, jamais en conflit avec les vrais IMM-0001.
R_IMM = random.Random(1410)
# Unité d'usage des machines : m² (solvant), mètre de film (DTF), page A4 (laser).
for row in ROWS['machines']:
    row.update({'unite_usage': None, 'compteur_unites': 0, 'releve_compteurs': None, 'cout_page_couleur': None, 'cout_page_noir': None})
    if row['id'] == MACHINES['DTF XP600']:
        row.update({'unite_usage': 'metre', 'capacite_usage': 25000.0})
    if row['id'] == MACHINES['Laser SRA3']:
        row.update({'cout_page_couleur': 42.0, 'cout_page_noir': 8.0})
MODELE_MACHINE = {c: v for c, v in ROWS['machines'][0].items() if c != 'id'}
for nom, marque, modele, typ, larg, mode, prix, residuelle, capacite, unite, releve, couleur, noir, achat, ip in [
        ('Copieur Canon C3226i', 'Canon', 'imageRUNNER ADVANCE C3226i', 'Copieur A4 / A3', '0.297', 'feuille', 6500000, 500000, 1200000.0, 'page', 'a4a3', 38.0, 7.0, 620, '10.90.0.15'),
        ('Traceur HP T830', 'HP', 'DesignJet T830', 'Traceur de plans', '0.91', 'metre_carre', 3200000, 200000, 20000.0, None, 'simple', None, None, 950, '10.90.0.16'),
        ('Imprimante PVC Evolis', 'Evolis', 'Primacy 2', 'Cartes PVC', '0.09', 'feuille', 2400000, 150000, 150000.0, 'carte', None, None, None, 430, '10.90.0.17'),
        ('Sublimation SC-F500', 'Epson', 'SureColor SC-F500', 'Sublimation', '0.61', 'feuille', 3800000, 300000, 60000.0, 'transfert', None, None, None, 310, '10.90.0.18')]:
    MACHINES[nom] = add('machines', {**MODELE_MACHINE, 'nom': nom, 'marque': marque, 'modeles': modele, 'numero_serie': f'SN-D{R_IMM.randint(100000, 999999)}', 'type_machine': typ,
                                     'largeur_impression': larg, 'nb_tetes': 1, 'date_achat': J(achat), 'date_mise_service': J(achat - 10), 'compteur_m2': 0.0,
                                     'compteur_heures': R_IMM.randint(800, 4000), 'compteur_feuilles': 0, 'numero_machine': f'D-M{len(MACHINES) + 1}', 'adresse_ip': ip,
                                     'mode_facturation': mode, 'prix_achat': prix, 'vitesse_m2_heure': None, 'cout_horaire': 2000, 'cout_encre_m2': None,
                                     'plan_preventif': [{'tache': 'Nettoyage', 'frequence': 'semaine'}], 'formats_montage': 'A3' if releve == 'a4a3' else None,
                                     'capacite_usage': capacite, 'valeur_residuelle': residuelle, 'unite_usage': unite, 'compteur_unites': 0, 'releve_compteurs': releve,
                                     'cout_page_couleur': couleur, 'cout_page_noir': noir})
# Cartes de visite sur l'imprimante PVC, polos sur la sublimation : ordres terminés comptés à la pièce.
VERS = {}
for k, l in DETAILS_RECENTS:
    if l['p'] is P['D-POLO']:
        VERS[l['id']] = MACHINES['Sublimation SC-F500']
    elif l['p'] is P['D-CARTE'] and l['id'] % 2 == 0:
        VERS[l['id']] = MACHINES['Imprimante PVC Evolis']
# Zone imprimée des t-shirts et casquettes DTF : le film se compte en mètres (surface ÷ laize 60 cm).
ZONES_DTF = {l['id']: ((0.28, 0.35) if l['p'] is P['D-TSHIRT'] else (0.10, 0.06)) for k, l in DETAILS_RECENTS if l['p'] is P['D-TSHIRT'] or l['p'] is P['D-CASQUETTE']}
for row in ROWS['commandes_details']:
    if row['id'] in ZONES_DTF and not row.get('largeur'):
        row['largeur'], row['longueur'] = ZONES_DTF[row['id']]
PIECES = defaultdict(int)
for row in ROWS['ordre_production']:
    if row['commande_detail_id'] in VERS:
        row['machine_id'] = VERS[row['commande_detail_id']]
        if row['statut'] == 'termine':
            PIECES[row['machine_id']] += row['quantite_produite']
for row in ROWS['production']:
    if row['commande_details_id'] in VERS:
        row['machine_id'] = VERS[row['commande_details_id']]
# Relevés mensuels : Canon (A4 / A3, couleur et noir) sur 12 mois ; traceur sans le relevé du mois dernier.
CANON, TRACEUR = MACHINES['Copieur Canon C3226i'], MACHINES['Traceur HP T830']
c4, n4, c3, n3, metres = 182000, 264000, 21500, 9200, 3150.0
for k in range(12, 0, -1):
    c4 += R_IMM.randint(5200, 8200)
    n4 += R_IMM.randint(6800, 10400)
    c3 += R_IMM.randint(550, 950)
    n3 += R_IMM.randint(180, 420)
    saisi = S(f"TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL {k - 1} MONTH, '%Y-%m-02'), '09:{R_IMM.randint(5, 50):02d}:00')")
    add('releve_compteur', {'mois': M1(k), 'a4_couleur': c4, 'a4_noir': n4, 'a3_couleur': c3, 'a3_noir': n3, 'compteur': None, 'photo': None,
                            'saisi_le': saisi, 'machine_id': CANON, 'saisi_par_id': U['fanta']})
    if k > 1:
        metres += R_IMM.randint(35, 95)
        add('releve_compteur', {'mois': M1(k), 'a4_couleur': None, 'a4_noir': None, 'a3_couleur': None, 'a3_noir': None, 'compteur': round(metres, 1), 'photo': None,
                                'saisi_le': saisi, 'machine_id': TRACEUR, 'saisi_par_id': U['oumar']})
for row in ROWS['machines']:
    if row['id'] == CANON:
        row['compteur_feuilles'] = c4 + n4 + 2 * (c3 + n3)
    elif row['id'] == TRACEUR:
        row['compteur_m2'] = round(metres, 1)
    elif row['id'] == MACHINES['Imprimante PVC Evolis']:
        row['compteur_unites'] = 21400 + PIECES[row['id']]
    elif row['id'] == MACHINES['Sublimation SC-F500']:
        row['compteur_unites'] = 6400 + PIECES[row['id']]
for nom in ('Copieur Canon C3226i', 'Traceur HP T830', 'Imprimante PVC Evolis', 'Sublimation SC-F500'):
    for d in (25, 115, 205, 295) if nom.startswith('Copieur') else (60, 240):
        add('maintenance', {'date': T(d, 10), 'type': 'Préventive', 'description': 'Révision et nettoyage' if nom.startswith('Copieur') else 'Nettoyage complet',
                            'cout': str(R_IMM.choice([45000, 55000, 65000]) if nom.startswith('Copieur') else R_IMM.choice([15000, 25000])),
                            'technicien': 'Canon Mali (démo)' if nom.startswith('Copieur') else 'Atelier Diallo', 'machine_id': MACHINES[nom], 'duree_arret_minutes': 90})

# Registre des immobilisations (M03, M04) : machines d'abord, puis le reste.
NIMM = 0


def immobilisation(designation, categorie, lieu, achat, valeur, residuelle, duree, quantite=1, etat='bon', etat_note=None, fournisseur=None, confie=None,
                   controle=None, sortie=None, machine=None):
    global NIMM
    NIMM += 1
    return add('immobilisation', {'code': f'IMM-D{NIMM:03d}', 'designation': designation, 'categorie': categorie, 'lieu': lieu, 'date_achat': achat if isinstance(achat, S) else J(achat),
                                  'date_mise_service': None, 'valeur_achat': valeur, 'valeur_residuelle': residuelle, 'duree_annees': duree, 'quantite': quantite, 'etat': etat,
                                  'etat_note': etat_note, 'fournisseur': fournisseur,
                                  'numero_serie': f'SN-{R_IMM.randint(10000, 99999)}' if categorie in ('informatique', 'vehicule', 'energie') else None,
                                  'note': None, 'controle_le': J(controle) if controle is not None else None, 'sortie_le': J(sortie[0]) if sortie else None,
                                  'sortie_motif': sortie[1] if sortie else None, 'prix_cession': sortie[2] if sortie else None, 'cree_le': T(R_IMM.randint(30, 200), 10),
                                  'modifie_le': T(R_IMM.randint(1, 29), 11), 'machine_id': machine, 'confie_a_id': U[confie] if confie else None})


for row in list(ROWS['machines']):
    immobilisation(row['nom'], 'machine', 'Boutique et accueil' if row['nom'].startswith('Copieur') else 'Atelier', row['date_achat'], row['prix_achat'], row['valeur_residuelle'] or 0, 5,
                   etat='revision' if row['nom'] == 'Solvant 1,80 m' else 'bon', etat_note='tête à changer' if row['nom'] == 'Solvant 1,80 m' else None,
                   controle=R_IMM.randint(20, 200), machine=row['id'])
for designation, categorie, lieu, achat, valeur, residuelle, duree, quantite, etat, etat_note, fournisseur, confie, controle, sortie in [
        ('iMac 27" PAO', 'informatique', 'Atelier', 700, 1450000, 0, 3, 1, 'bon', None, 'Bamako Informatique (démo)', 'seydou', 120, None),
        ('PC PAO Dell OptiPlex', 'informatique', 'Atelier', 420, 950000, 0, 3, 1, 'bon', None, 'Bamako Informatique (démo)', 'aissata', 120, None),
        ('PC de caisse HP', 'informatique', 'Boutique et accueil', 560, 450000, 0, 3, 1, 'bon', None, 'Bamako Informatique (démo)', None, 120, None),
        ('Tablette caisse Samsung', 'informatique', 'Boutique et accueil', 260, 280000, 0, 3, 1, 'bon', None, 'Orange Boutique (démo)', 'aminata', 120, None),
        ('PC portable comptabilité', 'informatique', 'Bureau direction', 380, 520000, 0, 3, 1, 'bon', None, 'Bamako Informatique (démo)', 'fanta', 120, None),
        ('PC atelier (file d\'impression)', 'informatique', 'Atelier', 820, 380000, 0, 3, 1, 'abime', 'écran fendu', 'Bamako Informatique (démo)', 'oumar', 420, None),
        ('Serveur NAS sauvegardes', 'informatique', 'Bureau direction', 500, 650000, 0, 3, 1, 'bon', None, 'Bamako Informatique (démo)', None, 120, None),
        ('Ancien PC de caisse', 'informatique', 'Boutique et accueil', 1500, 380000, 0, 3, 1, 'hors_service', None, None, None, 400, (75, 'vente', 40000)),
        ('Chaises de bureau', 'chaise', 'Boutique et accueil', 640, 360000, 0, 5, 8, 'bon', None, 'Meubles du Sahel (démo)', None, 120, None),
        ('Chaises d\'atelier', 'chaise', 'Atelier', 900, 150000, 0, 5, 6, 'abime', '2 à regarnir', 'Meubles du Sahel (démo)', None, 450, None),
        ('Comptoir d\'accueil', 'table', 'Boutique et accueil', 980, 650000, 0, 10, 1, 'bon', None, 'Menuiserie Kati (démo)', None, 120, None),
        ('Table de découpe 3 m', 'table', 'Atelier', 1100, 420000, 0, 10, 1, 'bon', None, 'Menuiserie Kati (démo)', None, 450, None),
        ('Table de réunion', 'table', 'Bureau direction', 760, 380000, 0, 10, 1, 'bon', None, 'Meubles du Sahel (démo)', None, 120, None),
        ('Moto Yamaha Crypton', 'vehicule', 'Livraison', 520, 950000, 100000, 4, 1, 'bon', None, 'Yamaha Mali (démo)', 'bakary', 60, None),
        ('Moto Jakarta', 'vehicule', 'Livraison', 300, 750000, 80000, 4, 1, 'revision', 'vidange à faire', 'Moto Plus (démo)', 'sekou', 60, None),
        ('Tricycle de livraison', 'vehicule', 'Livraison', 150, 1800000, 200000, 4, 1, 'neuf', None, 'Moto Plus (démo)', None, 60, None),
        ('Climatiseur boutique 2 CV', 'climatiseur', 'Boutique et accueil', 600, 425000, 0, 5, 1, 'bon', None, 'Froid du Mali (démo)', None, 120, None),
        ('Climatiseur atelier 2,5 CV', 'climatiseur', 'Atelier', 880, 525000, 0, 5, 1, 'revision', 'gaz à recharger', 'Froid du Mali (démo)', None, 120, None),
        ('Climatiseur bureau 1,5 CV', 'climatiseur', 'Bureau direction', 450, 380000, 0, 5, 1, 'bon', None, 'Froid du Mali (démo)', None, 120, None),
        ('Groupe électrogène 15 kVA', 'energie', 'Cour', 1200, 4200000, 400000, 5, 1, 'bon', None, 'Énergie Services (démo)', None, 90, None),
        ('Kit solaire et batteries', 'energie', 'Cour', 400, 2800000, 0, 5, 1, 'bon', None, 'Soleil du Sahel (démo)', None, 90, None),
        ('Écran TV boutique 55"', 'audiovisuel', 'Boutique et accueil', 330, 350000, 0, 5, 1, 'bon', None, 'Orange Boutique (démo)', None, 120, None),
        ('Vidéoprojecteur', 'audiovisuel', 'Bureau direction', 700, 280000, 0, 5, 1, 'bon', None, 'Bamako Informatique (démo)', None, 420, None),
        ('Licences Adobe Creative Cloud (3 ans)', 'logiciel', 'Atelier', 270, 1100000, 0, 3, 1, 'bon', None, 'Adobe (démo)', None, None, None),
        ('Logiciel RIP Onyx', 'logiciel', 'Atelier', 650, 1500000, 0, 3, 1, 'bon', None, 'Dakar Signs (démo)', None, None, None)]:
    immobilisation(designation, categorie, lieu, achat, valeur, residuelle, duree, quantite, etat, etat_note, fournisseur, confie, controle, sortie)

# Primes : Fanta tient la comptabilité (droit Comptabilité et patrimoine) ; Seydou encadre Aïssata.
for row in ROWS['user']:
    if row['username'] == 'demo.fanta':
        row['droits_ajoutes'] = row['droits_ajoutes'] + ['ROLE_COMPTABILITE']
AISSATA_EMPLOYE = next(row['employe_id'] for row in ROWS['user'] if row['username'] == 'demo.aissata')
for row in ROWS['employes']:
    if row['id'] == AISSATA_EMPLOYE:
        row['responsable_id'] = U['seydou']

# ------------------------------------- messagerie, notifications et journal
paires = [('aminata', 'seydou'), ('aminata', 'oumar'), ('kadiatou', 'fanta'), ('mariam', 'aissata'), ('moussa', 'bakary'), ('seydou', 'oumar')]
PHRASES = ['Le BAT de la pharmacie est parti ?', 'Oui, envoyé ce matin.', 'Le client passe à 15 h pour la bâche.', 'La machine est libre après 11 h.', 'Il manque le logo en vectoriel.',
           'Je l\'ai demandé au client par WhatsApp.', 'C\'est livré, signature reçue.', 'Merci !', 'On a assez de bâche 510 g pour demain ?', 'Oui, 2 rouleaux en stock.']
for a, b in paires:
    d0 = R.randint(1, 40)
    conv = add('conversations', {'date_creation': T(d0 + 5, 9), 'date_dernier_message': T(d0, 16), 'jeton': hexa(32)})
    add('conversation_participants', {'date_derniere_lecture': T(d0, 16), 'conversation_id': conv, 'utilisateur_id': U[a]})
    add('conversation_participants', {'date_derniere_lecture': T(d0 + 1, 10), 'conversation_id': conv, 'utilisateur_id': U[b]})
    for n in range(R.randint(6, 14)):
        add('messages_chat', {'contenu': R.choice(PHRASES), 'date_envoi': T(d0 + 5 - n // 3, 9 + n % 8, R.randint(0, 59)), 'piece_jointe_fichier': None, 'piece_jointe_nom_original': None,
                              'conversation_id': conv, 'auteur_id': U[a] if n % 2 == 0 else U[b]})
for k in COMMANDES[-150:]:
    add('notifications', {'message': f'Nouvelle commande {k["numero"]} · {nom_client(k["c"])}', 'route': None, 'route_parametres': None, 'lue': int(k['d'] > 2), 'date_creation': T(k['d'], k['h'], k['m'] + 1),
                          'destinataire_id': R.choice([U['kadiatou'], U['seydou'], U['aissata']])})
for k in COMMANDES[-600:]:
    add('journal_activite', {'entite': 'Commandes', 'entite_id': k['id'], 'action': 'creation', 'donnees_avant': None,
                             'donnees_apres': json.dumps({'numero': k['numero'], 'total': k['a_payer']}), 'created_at': T(k['d'], k['h'], k['m']), 'utilisateur_id': k['agent']})
    if k['paye']:
        add('journal_activite', {'entite': 'Paiements', 'entite_id': PAIEMENTS_PAR_CMD[k['id']][0], 'action': 'creation', 'donnees_avant': None,
                                 'donnees_apres': json.dumps({'commande': k['numero'], 'montant': k['payes'][0]}), 'created_at': T(k['d'], k['h'], k['m'] + 5), 'utilisateur_id': k['agent']})

add('parametres_paiement', {'taux_frais_retrait': 1.0, 'taux_fonds_soutien': 1.0})

# ------------------------------- espace client (maquettes EC01 à EC05)
# Salon Espoir (DEMO-0002, client à compte en ligne) : de quoi remplir le
# tableau de bord, la cloche, les notifications, Livraisons et Messages :
# un BAT à valider, des t-shirts en impression avec leur livraison prévue,
# un roll-up en route, des kakémonos livrés, une facture à payer aujourd'hui,
# une facture échue, un devis envoyé, un échange de messages, deux adresses
# et les personnes du compte (dont un compte « Lecture · suivi »).
# Tirage à part (R4) : rien ne bouge dans le reste de la démonstration.
R4 = random.Random(1410)
EC = CLIENTS[1]


def uid4():
    return S('UNHEX(\'' + uuid.UUID(int=R4.getrandbits(128), version=4).hex + '\')')


def hexa4(n=64):
    return ''.join(R4.choice('0123456789abcdef') for _ in range(n))


def commande_ec(code, designation, qte, d, h, m, statut_ligne, livraison_dans, larg=None, haut=None, bat_valide=True):
    """Commande d'une ligne pour DEMO-0002 ; livraison_dans : jours (négatif : déjà passée)."""
    global ncmd
    p = P[code]
    ncmd += 1
    prix = p['b2b'] if EC['b2b'] else p['prix']
    surface = round(larg * haut, 4) if larg and haut else None
    base = surface * qte if surface else qte
    total = int(round(prix * base, -2))
    numero = f'CMD-D{ncmd:05d}'
    kid = add('commandes', {'numero': numero, 'date_commande': T(d, h, m), 'date_livraison': T(-livraison_dans, 17), 'recupere_par_client': 0,
                            'modifie_le': None, 'remise': 0, 'tva': 0, 'total_ht': total, 'total_ttc': total, 'montant_apayer': total, 'observation': None, 'deleted': 0,
                            'statut': 1, 'etat': 1, 'statut_paiement': 'impayee', 'reste_apayer': total, 'total_paye': 0, 'public_id': uid4(), 'bonus_plafond_applique': 0,
                            'montant_bonus_plafond': 0, 'clients_id': EC['id'], 'agents_id': EC['suivi'], 'modifie_par_id': None, 'canal': 'comptoir',
                            'mode_livraison': 'livraison', 'adresse_livraison': 'Entrée de service, Quartier du Fleuve, Bamako'})
    debut = None if statut_ligne == 'a_produire' else max(0, d - 1)
    fini = statut_ligne in ('terminee', 'prete_livraison', 'en_livraison', 'livree')
    lid = add('commandes_details', {'mode_configuration': 'automatique', 'designation': designation, 'largeur': larg, 'longueur': haut, 'surface': surface, 'quantite': qte,
                                    'prix_unitaire': float(prix), 'cout_revient': int(p['cout'] * base), 'remise': 0, 'tva': 0, 'total_ht': total, 'total_ttc': total,
                                    'profil_couleurs': 'CMJN', 'resolution': '300 dpi', 'grammage': None, 'epaisseur': None, 'recto_verso': 0, 'nombre_faces': 1, 'laminage': None,
                                    'oeillets': 0, 'decoupe': 0, 'pliage': None, 'emballage': 0, 'bat_valide': int(bat_valide), 'etat': 1, 'priorite': 'normale', 'temps_estime': None,
                                    'temps_reel': None, 'fichier': None, 'observation': None, 'statut_production': statut_ligne,
                                    'production_debute_le': T(debut, 10) if debut is not None else None, 'production_terminee_le': T(max(0, debut - 1), 15) if fini and debut is not None else None,
                                    'quantite_produite': qte if fini else 0, 'quantite_rebut': 0, 'quantite_livree': qte if statut_ligne == 'livree' else 0, 'mode_saisie': 'automatique',
                                    'mode_calcul': p['mode'], 'pre_presse_necessaire': 1, 'production_necessaire': 1, 'type_ligne': 'produit', 'commande_id': kid, 'produit_id': p['id'],
                                    'produit_configuration_id': p['conf'], 'type_impression_id': p['typ'], 'support_id': p['sup'], 'machine_id': p['machine'], 'format_id': p['fmt'],
                                    'production_debutee_par_id': U['oumar'] if debut is not None else None, 'production_terminee_par_id': U['oumar'] if fini else None,
                                    'article_id': None, 'public_id': uid4(), 'quantite_demandee': None, 'unite_demandee': None, 'produit_client_id': None})
    return dict(id=kid, numero=numero, lid=lid, total=total, qte=qte, designation=designation, d=d)


def bat_ec(k, statut, minutes_bat):
    """Contrôle et suivi de conception : BAT V1 envoyé à minutes_bat (minutes depuis minuit, jour k['d'] - 1 au plus tard)."""
    jour = max(0, k['d'] - 1) if statut == 'envoye_production' else 0
    envoye = statut == 'envoye_production'
    fich = add('commande_detail_fichier', {'jeton_upload': hexa4(64), 'morceaux_recus': 1, 'nombre_morceaux': 1, 'termine_le': T(k['d'], 9, 5),
                                           'nom_original': f'{k["designation"][:24].lower().replace(" ", "-")}-salon-espoir.pdf', 'nom_stockage': f'demo-{hexa4(16)}.pdf',
                                           'chemin': None, 'type_mime': 'application/pdf', 'taille': R4.randint(900000, 9000000), 'statut': 'TERMINE', 'origine': 'client',
                                           'etat': 'pret_impression' if envoye else 'a_traiter', 'deja_traite': 0, 'fichier_production': int(envoye), 'actif': 1, 'version': 1,
                                           'quantite_aproduire': k['qte'], 'face': None, 'designation': k['designation'], 'groupe_fichier': None, 'observation': None,
                                           'cree_le': T(k['d'], 9, 5), 'commande_detail_id': k['lid'], 'fichier_source_id': None, 'ajoute_par_id': EC['suivi'], 'public_id': uid4(), 'montage': None})
    ok = int(envoye)
    ctl = add('controle_pre_presse', {'statut': statut, 'format_conforme': 1, 'dimensions_conformes': 1, 'resolution_conforme': 1, 'profil_couleurs_conforme': 1, 'fonds_perdus_conformes': 1,
                                      'marges_securite_conformes': 1, 'polices_conformes': 1, 'orthographe_verifiee': 1, 'orientation_conforme': 1, 'nombre_pages_conforme': 1,
                                      'recto_verso_conforme': 1, 'support_conforme': 1, 'quantite_conforme': 1, 'fichier_deja_traite': 0, 'correction_necessaire': 0,
                                      'bat_necessaire': 1, 'bat_valide': ok, 'anomalies': None, 'corrections_effectuees': None, 'observation': None, 'pre_presse_necessaire': 1,
                                      'cree_le': T(k['d'], 9, 20), 'controle_le': T(k['d'], 10), 'traite_le': T(jour, 0, minutes_bat), 'valide_le': T(jour, 0, minutes_bat + 40) if envoye else None,
                                      'envoye_production_le': T(jour, 0, minutes_bat + 50) if envoye else None, 'commande_detail_id': k['lid'], 'controle_par_id': GRAPHISTES[0],
                                      'traite_par_id': GRAPHISTES[0], 'valide_par_id': GRAPHISTES[0] if envoye else None, 'envoye_production_par_id': GRAPHISTES[0] if envoye else None,
                                      'public_id': uid4()})
    add('controle_pre_presse_fichier', {'controle_pre_presse_id': ctl, 'fichier_id': fich}, False)
    evts = [{'le': f'@{k["d"]}:{9 * 60 + 20}', 'type': 'brief', 'titre': 'Brief reçu', 'detail': None},
            {'le': f'@{jour}:{minutes_bat - 50}', 'type': 'prise', 'titre': 'Prise en charge', 'detail': f'{PRENOM[GRAPHISTES[0]]} démarre le chronomètre'},
            {'le': f'@{jour}:{minutes_bat}', 'type': 'bat', 'titre': 'BAT V1 envoyé', 'detail': 'par WhatsApp et espace client'}]
    if envoye:
        evts.append({'le': f'@{jour}:{minutes_bat + 40}', 'type': 'valide', 'titre': 'BAT validé', 'detail': 'par le client'})
        evts.append({'le': f'@{jour}:{minutes_bat + 50}', 'type': 'production', 'titre': 'Envoyé en production', 'detail': 'fichier transmis à la file Solvant'})
    add('conception', {'difficulte': 'simple', 'echeance_bat': T(jour, 17), 'temps_prevu': 45, 'demarre_le': None, 'secondes': 2400, 'versions': 1, 'erreurs': 0,
                       'changements_client': 0, 'premier_bat_le': T(jour, 0, minutes_bat), 'dernier_bat_le': T(jour, 0, minutes_bat),
                       'valide_le': T(jour, 0, minutes_bat + 40) if envoye else None, 'points': 10 if envoye else None, 'points_possibles': 15 if envoye else None,
                       'faits': {'g_bat_a_temps': 1, 'g_sans_erreur': 1} if envoye else {}, 'evenements': evts, 'cree_le': T(k['d'], 9, 20), 'controle_id': ctl,
                       'graphiste_id': GRAPHISTES[0]})


def livraison_ec(k, jour, creneau, etat):
    """Bon de livraison : etat prevue, route (livreur parti aujourd'hui) ou livree (jour dans le passé)."""
    global nbl
    nbl += 1
    livre, route = etat == 'livree', etat in ('route', 'livree')
    debut = int(creneau[:2])
    bl = add('bon_livraison', {'numero': f'BL-D{nbl:05d}', 'cree_le': T(max(jour, 0), 8), 'valide_le': T(max(jour, 0), 8, 30), 'livre_le': T(jour, debut, 25) if livre else None,
                               'statut': 'livre' if livre else 'valide', 'nom_receptionnaire': 'Awa Coulibaly', 'telephone_receptionnaire': EC['tel'],
                               'adresse_livraison': 'Entrée de service, Quartier du Fleuve, Bamako', 'observation': None, 'gestion_stock': 0, 'coefficient_stock': 1.0, 'commande_id': k['id'],
                               'cree_par_id': EC['suivi'], 'valide_par_id': EC['suivi'], 'livre_par_id': LIVREURS[0] if livre else None, 'article_stock_id': None, 'prevu_le': J(jour),
                               'creneau': creneau, 'ordre_tournee': 1, 'charge_le': T(jour, debut - 1, 30) if route else None, 'en_route_le': T(jour, debut - 1, 45) if route else None,
                               'arrive_le': T(jour, debut, 20) if livre else None, 'photos': [f'demo-livraison-{hexa4(8)}.jpg'] if livre else None,
                               'signature': f'demo-signature-{hexa4(8)}.png' if livre else None, 'signataire': 'Awa Coulibaly' if livre else None, 'encaisse_montant': None,
                               'encaisse_mode': None, 'encaisse_le': None, 'livreur_id': LIVREURS[0], 'paiement_livraison_id': None, 'remise_latitude': None, 'remise_longitude': None,
                               'retard_justifie': None, 'erreur_livraison': None, 'public_id': uid4()})
    add('bon_livraison_ligne', {'designation': k['designation'], 'quantite_commandee': k['qte'], 'quantite_livree': k['qte'] if livre else 0, 'unite': 'unité',
                                'observation': None, 'bon_livraison_id': bl, 'commande_detail_id': k['lid']})


def facture_ec(k, d, echeance_dans):
    """Facture émise d jours avant le chargement, échéance dans echeance_dans jours (négatif : échue)."""
    global nfac
    nfac += 1
    fid = add('factures', {'numero': f'F-D{nfac:05d}', 'type_document': 'facture', 'comptabilisee': 1, 'emetteur': 'mdg_success', 'pdf_fichier': None, 'pdf_genere_le': None,
                           'pdf_hash': None, 'date_facture': T(d, 9, 10), 'date_echeance': J(-echeance_dans), 'total_ht': k['total'], 'remise': 0, 'tva': 0, 'montant_tva': 0,
                           'surfacturation': 0, 'taux_surfacturation': None, 'montant_surfacturation': 0, 'motif_surfacturation': None, 'total_ttc': k['total'], 'montant_paye': 0,
                           'reste_apayer': k['total'], 'statut_paiement': 'impayee', 'etat': 'emise', 'observation': None, 'token_authenticite': hexa4(64), 'facturer_aun_tiers': 0,
                           'nom_facturation': None, 'adresse_facturation': None, 'telephone_facturation': None, 'email_facturation': None, 'created_at': T(d, 9, 10),
                           'updated_at': T(d, 9, 10), 'commande_id': k['id'], 'public_id': uid4(),
                           'code_suivi': code_suivi_ec()})
    FACTURE_DE[k['id']] = fid


def code_suivi_ec():
    """Code du QR (7K4P-2Q) des factures de l'espace client : mêmes 9 tirages qu'avant, pour ne rien décaler."""
    c = ''.join(R4.choice('ABCDEFGHJKLMNPQRSTUVWXYZ23456789') for _ in range(9))
    c = c[:4] + '-' + c[4:6]
    if c in CODES_SUIVI:
        raise SystemExit('Code de suivi en double : ' + c)
    CODES_SUIVI.add(c)
    return c


EC_PLAQUES = commande_ec('D-AKYLUX', 'Plaques des chambres 20 × 10 cm', 40, 1, 15, 10, 'a_produire', 6, larg=0.2, haut=0.1, bat_valide=False)
bat_ec(EC_PLAQUES, 'en_attente_bat', 9 * 60 + 40)
EC_TSHIRTS = commande_ec('D-TSHIRT', 'T-shirts DTF du personnel', 30, 3, 11, 0, 'en_production', 2)
bat_ec(EC_TSHIRTS, 'envoye_production', 10 * 60 + 15)
livraison_ec(EC_TSHIRTS, -2, '09:00-10:00', 'prevue')
facture_ec(EC_TSHIRTS, 3, 0)
EC_ROLLUP = commande_ec('D-ROLLUP', 'Roll-up accueil 85 × 200 cm', 1, 6, 10, 30, 'en_livraison', 0)
livraison_ec(EC_ROLLUP, 0, '11:00-12:00', 'route')
EC_KAKEMONOS = commande_ec('D-KAKEMONO', 'Kakémonos du hall', 2, 18, 9, 45, 'livree', -4)
livraison_ec(EC_KAKEMONOS, 4, '09:00-11:00', 'livree')
facture_ec(EC_KAKEMONOS, 16, -9)

# Devis envoyé hier (à accepter par un valideur).
ndev += 1
EC_BACHE = dict(p=P['D-BACHE'], l=6.0, L=3.0)
prix_bache = EC_BACHE['p']['b2b'] if EC['b2b'] else EC_BACHE['p']['prix']
total_bache = int(round(prix_bache * 18, -2))
did = add('devis', {'numero': f'DV-D{ndev:05d}', 'emetteur': 'mdg_success', 'date_devis': T(1, 16, 10), 'remise': 0, 'tva': 0, 'total_ht': total_bache, 'total_ttc': total_bache,
                    'montant_apayer': total_bache, 'observation': None, 'deleted': 0, 'statut': 'envoye', 'etat': 1, 'token_authenticite': hexa4(64), 'facturer_aun_tiers': 0,
                    'nom_facturation': None, 'adresse_facturation': None, 'telephone_facturation': None, 'email_facturation': None, 'clients_id': EC['id'],
                    'agents_id': EC['suivi'], 'commande_id': None, 'public_id': uid4()})
add('devis_details', {'mode_configuration': 'automatique', 'designation': 'Bâche façade 6 × 3 m', 'largeur': 6.0, 'longueur': 3.0, 'surface': 18.0, 'quantite': 1,
                      'prix_unitaire': float(prix_bache), 'cout_revient': int(EC_BACHE['p']['cout'] * 18), 'remise': 0, 'tva': 0, 'total_ht': total_bache, 'total_ttc': total_bache,
                      'type_ligne': 'produit', 'mode_saisie': 'automatique', 'bat_valide': 0, 'etat': 1, 'priorite': 'normale', 'mode_calcul': EC_BACHE['p']['mode'], 'devis_id': did,
                      'produit_id': EC_BACHE['p']['id'], 'produit_configuration_id': EC_BACHE['p']['conf'], 'type_impression_id': EC_BACHE['p']['typ'],
                      'support_id': EC_BACHE['p']['sup'], 'format_id': EC_BACHE['p']['fmt'], 'article_id': None, 'public_id': uid4()})

# Adresses de livraison et messages avec l'atelier.
add('adresse_livraison', {'libelle': 'Entrée de service', 'adresse': 'Quartier du Fleuve, Bamako', 'repere': 'avant 10 h', 'contact': 'Awa Coulibaly', 'telephone': None,
                          'par_defaut': 1, 'actif': 1, 'client_id': EC['id']})
add('adresse_livraison', {'libelle': 'Annexe · salle des fêtes', 'adresse': 'Quartier du Fleuve, Bamako', 'repere': 'même quartier, portail vert', 'contact': None, 'telephone': None,
                          'par_defaut': 0, 'actif': 1, 'client_id': EC['id']})
add('message_client', {'sens': 'client', 'auteur': 'Awa Coulibaly', 'texte': 'Bonjour, pouvez-vous livrer les t-shirts avant jeudi ? Le personnel en a besoin pour la réception de samedi.',
                       'cree_le': T(1, 10, 5), 'traite_le': T(0, 8, 20), 'public_id': uid4(), 'client_id': EC['id'], 'commande_id': EC_TSHIRTS['id'], 'ecrit_par_id': None})
add('message_client', {'sens': 'atelier', 'auteur': 'Succès Imprim', 'texte': 'Bonjour Awa, oui : livraison prévue après-demain entre 9 h et 10 h, à l\'entrée de service.',
                       'cree_le': T(0, 8, 20), 'traite_le': None, 'public_id': uid4(), 'client_id': EC['id'], 'commande_id': EC_TSHIRTS['id'], 'ecrit_par_id': EC['suivi']})

# Les personnes du compte en ligne (le titulaire existe peut-être déjà parmi les comptes tirés plus haut).
# Un compte par type de droits sur la même entreprise, pour essayer l'espace client avec demo1234.
ligne_titulaire = next((r for r in ROWS['compte_client'] if r['client_id'] == EC['id']), None)
if ligne_titulaire is None:
    titulaire = add('compte_client', {'telephone': '223' + EC['tel'], 'mot_de_passe': MDP, 'actif': 1, 'cree_le': T(60), 'derniere_connexion': T(0, 8, 2),
                                      'client_id': EC['id'], 'droits': '["valideur","payeur","administrateur"]'})
    ligne_titulaire = ROWS['compte_client'][-1]
titulaire = ligne_titulaire['id']
ligne_titulaire.update({'nom': EC['nom'], 'fonction': 'Gérant'})
COMPTES_DEMO = [('Awa Coulibaly', 'Intendante', '["valideur"]', '60445101'), ('Ibrahim Touré', 'Comptabilité', '["payeur"]', '60445102'),
                ('Mariam Dembélé', 'Assistante', '[]', '60445103'), ('Réception', 'Accueil', '["lecture"]', '60445104')]
for nom, fonction, droits, tel in COMPTES_DEMO:
    assert tel not in numeros, tel
    add('compte_client', {'telephone': '223' + tel, 'mot_de_passe': MDP, 'actif': 1, 'cree_le': T(30), 'derniere_connexion': T(R4.randint(0, 6), 9), 'client_id': EC['id'],
                          'droits': droits, 'nom': nom, 'fonction': fonction, 'ajoute_par_id': titulaire})

# Un particulier (grille B2C : fidélité visible), celui qui a le plus de commandes.
ACTIFS = {r['id'] for r in ROWS['clients'] if r['statut'] == 1}
PARTICULIER = max((c for c in CLIENTS if c['t'] == 'particulier' and c['id'] in ACTIFS), key=lambda c: (sum(1 for k in COMMANDES if k['c'] is c), -c['id']))
add('compte_client', {'telephone': '223' + PARTICULIER['tel'], 'mot_de_passe': MDP, 'actif': 1, 'cree_le': T(90), 'derniere_connexion': T(2, 19, 40),
                      'client_id': PARTICULIER['id'], 'droits': '["valideur","payeur","administrateur"]', 'nom': PARTICULIER['nom']})
print(f"Espace client : titulaire {EC['nom']} 223{EC['tel']} ({EC['rs']}), particulier {PARTICULIER['nom']} 223{PARTICULIER['tel']}")

# ------------------------------------------- demandes de conception (DC01 à DC05)
# Ce que l'atelier voit après la synchronisation : une demande au graphiste
# qui attend (acompte Orange Money à valider par la caisse), une en cours,
# une terminée, et une affiche faite par l'IA dont l'impression est commandée.
# Tirage à part, placé en dernier (après l'espace client) : rien ne bouge dans le reste de la démo.
R5 = random.Random(1410)
# Colonnes que le reste de la démo ne remplit pas : posées après coup, pour ne pas changer toutes les lignes du fichier.
APRES = []


def uid5():
    return S('UNHEX(\'' + uuid.UUID(int=R5.getrandbits(128), version=4).hex + '\')')


def hexa5(n):
    return ''.join(R5.choice('0123456789abcdef') for _ in range(n))


def commande_dc(c, numero, d, h, m, lignes, confirmee, payes):
    """Commande en ligne d'une demande de conception ; payes : [(montant, validé, d, h, m)]."""
    kid = nid('commandes')
    total = sum(l['prix'] * l['qte'] for l in lignes)
    paye = sum(p[0] for p in payes if p[1])
    add('commandes', {'id': kid, 'numero': numero, 'date_commande': T(d, h, m), 'date_livraison': None, 'recupere_par_client': 0, 'modifie_le': None,
                      'remise': 0, 'tva': 0, 'total_ht': total, 'total_ttc': total, 'montant_apayer': total, 'observation': None, 'deleted': 0,
                      'statut': int(confirmee), 'etat': 1, 'statut_paiement': 'payee' if total and paye >= total else ('partielle' if paye else 'impayee'),
                      'reste_apayer': total - paye, 'total_paye': paye, 'public_id': uid5(), 'bonus_plafond_applique': 0, 'montant_bonus_plafond': 0,
                      'clients_id': c['id'], 'agents_id': None, 'modifie_par_id': None, 'canal': 'en_ligne', 'mode_livraison': 'retrait', 'adresse_livraison': None})
    ids = []
    for l in lignes:
        lid = nid('commandes_details')
        ids.append(lid)
        add('commandes_details', {'id': lid, 'mode_configuration': 'manuel', 'designation': l['nom'], 'largeur': None, 'longueur': None, 'surface': None,
                                  'quantite': l['qte'], 'prix_unitaire': float(l['prix']), 'cout_revient': 0, 'remise': 0, 'tva': 0,
                                  'total_ht': l['prix'] * l['qte'], 'total_ttc': l['prix'] * l['qte'], 'profil_couleurs': None, 'resolution': None, 'grammage': None,
                                  'epaisseur': None, 'recto_verso': 0, 'nombre_faces': 1, 'laminage': None, 'oeillets': 0, 'decoupe': 0, 'pliage': None, 'emballage': 0,
                                  'bat_valide': 0, 'etat': 1, 'priorite': 'normale', 'temps_estime': None, 'temps_reel': None, 'fichier': None, 'observation': None,
                                  'statut_production': l.get('statut', 'non_requise'), 'production_debute_le': None, 'production_terminee_le': None,
                                  'quantite_produite': 0, 'quantite_rebut': 0, 'quantite_livree': 0, 'mode_saisie': 'manuelle', 'mode_calcul': l.get('mode', 'forfait'),
                                  'pre_presse_necessaire': int(l.get('prepresse', False)), 'production_necessaire': int(l.get('production', False)), 'type_ligne': 'libre',
                                  'commande_id': kid, 'produit_id': None, 'produit_configuration_id': None, 'type_impression_id': None, 'support_id': None,
                                  'machine_id': None, 'format_id': None, 'production_debutee_par_id': None, 'production_terminee_par_id': None, 'article_id': None,
                                  'public_id': uid5(), 'quantite_demandee': None, 'unite_demandee': None, 'produit_client_id': None})
        if l.get('brief'):
            APRES.append(f"UPDATE `commandes_details` SET `demande_client` = {q(l['brief'])} WHERE id = {lid};")
    pids = []
    for montant, valide, dp, hp, mp in payes:
        pid = nid('paiements')
        pids.append(pid)
        add('paiements', {'id': pid, 'montant': montant, 'mode': 'orange_money', 'statut': 'valide' if valide else 'en_attente', 'date': T(dp, hp, mp),
                          'date_validation': T(dp, hp, mp + 20) if valide else None, 'date_annulation': None, 'reference': f'MP{R5.randint(10 ** 9, 10 ** 10 - 1)}',
                          'numero_cheque': None, 'banque_emettrice': None, 'titulaire_cheque': None, 'date_emission_cheque': None, 'date_encaissement_prevue': None,
                          'justificatif': None, 'observation': 'Payé en ligne (Orange Money)' if valide else 'Payé en ligne (Orange Money), à vérifier par la caisse',
                          'motif_rejet': None, 'motif_annulation': None, 'frais_retrait_inclus': 0, 'fonds_soutien_inclus': 0, 'montant_frais_retrait': 0,
                          'montant_fonds_soutien': 0, 'commande_id': kid, 'compte_tresorerie_id': COMPTES['orange_money'], 'encaisse_par_id': None,
                          'valide_par_id': U['aminata'] if valide else None, 'annule_par_id': None, 'facture_id': None, 'public_id': uid5()})
        if valide:
            mouvement('encaissement', 'vente', montant, f'Paiement commande {numero} · {nom_client(c)}', dp, hp, mp + 20, dest=COMPTES['orange_money'], mode='orange_money', paiement=pid, agent=U['aminata'])
    return kid, ids, pids


SUPPORTS_DC = {'kakemono': ('Kakémono / roll-up', 'Kakémono', '85 × 200 cm', 85, 200, 10000), 'flyer': ('Flyer', 'Flyer', 'A5 · A4', 14.8, 21, 10000),
               'carte_visite': ('Carte de visite', 'Carte', '85 × 55 mm', 8.5, 5.5, 5000), 'affiche': ('Affiche', 'Affiche', 'A3 · A2', 29.7, 42, 10000)}
COULEURS_DC = {'#13285C': 'bleu nuit', '#F2C230': 'or', '#FFFFFF': 'blanc', '#E2468F': 'rose', '#E0102B': 'rouge', '#16A34A': 'vert', '#FF7900': 'orange', '#111111': 'noir'}
STYLES_DC = {'elegant': 'Élégant', 'moderne': 'Moderne', 'festif': 'Festif', 'institutionnel': 'Institutionnel'}


def demande_dc(n, c, code, choix, statut, d, contenu, couleurs, styles, quantite, pour_quand, auteur, prise=None, terminee=None, finalisation=False, images=0):
    nom, court, fmt, larg, haut, forfait = SUPPORTS_DC[code]
    numero = f'DC-D{n:03d}'
    brief = [f'Demande de conception {numero}'] + [f'{k} : {v}' for k, v in contenu.items() if v]
    brief += [f'Couleurs : {", ".join(COULEURS_DC[x] for x in couleurs)}', f'Style : {", ".join(STYLES_DC[x] for x in styles)}', f'Quantité à imprimer ensuite : {quantite}']
    row = {'numero': numero, 'auteur': auteur, 'support_nom': nom, 'support_format': fmt, 'largeur_cm': larg, 'hauteur_cm': haut, 'forfait': forfait,
           'acompte_pourcent': 50, 'corrections_incluses': 2, 'delai_bat_heures': 24, 'titre': contenu.get('Titre'), 'sous_titre': contenu.get('Sous-titre'),
           'date_heure': contenu.get('Date et heure'), 'coordonnees': contenu.get('Coordonnées'), 'consignes': contenu.get('Consignes'), 'couleurs': couleurs,
           'styles': styles, 'quantite': quantite, 'pour_quand': J(pour_quand), 'exemple_lien': None, 'choix': choix, 'statut': statut, 'etape': 4,
           'finalisation_graphiste': int(finalisation), 'nombre_images': images, 'cree_le': T(d + 1, 18, 30),
           'modifie_le': T(terminee if terminee is not None else (prise if prise is not None else d), 16), 'envoyee_le': T(d, 9, 12),
           'prise_en_charge_le': T(prise, 8, 40) if prise is not None else None, 'prise_par': 'Seydou' if prise is not None else None,
           'terminee_le': T(terminee, 15, 5) if terminee is not None else None, 'public_id': uid5(), 'client_id': c['id'], 'compte_id': None,
           'support_id': S(f"(SELECT id FROM support_conception WHERE code = '{code}')"), 'commande_id': None, 'paiement_acompte_id': None, 'image_choisie_id': None}
    return row, '\n'.join(brief), f'{court} {fmt}'


DC_CLIENTS = (CLIENTS[1], CLIENTS[28], CLIENTS[6])  # DEMO-0002 (Salon Espoir), DEMO-0029, DEMO-0007

# 1. Kakémono au graphiste : acompte payé ce matin, à vérifier par la caisse, aucun graphiste encore.
dc, brief, titre = demande_dc(1, DC_CLIENTS[0], 'kakemono', 'graphiste', 'recue', 0,
                              {'Titre': 'Salon Espoir', 'Sous-titre': 'Coiffure · tresses · soins', 'Coordonnées': '60 69 78 04 · Bagadadji, près de la station Total',
                               'Consignes': 'Mettre la photo de la vitrine et les prix des tresses. Le nom bien visible de loin.'},
                              ['#E2468F', '#111111'], ['moderne', 'elegant'], 1, -6, 'Oumar Guindo')
kid, _, pids = commande_dc(DC_CLIENTS[0], 'CMD-DC0001', 0, 9, 12, [{'nom': f'Conception · {titre}', 'qte': 1, 'prix': 10000, 'prepresse': True, 'brief': brief}], True, [(5000, False, 0, 9, 14)])
add('demande_conception', {**dc, 'commande_id': kid, 'paiement_acompte_id': pids[0]})

# 2. Flyer au graphiste, pris en charge hier par Seydou (acompte validé par la caisse).
dc, brief, titre = demande_dc(2, DC_CLIENTS[1], 'flyer', 'graphiste', 'en_cours', 2,
                              {'Titre': 'Portes ouvertes', 'Sous-titre': 'Découvrez nos formations', 'Date et heure': 'Samedi 9 h – 13 h',
                               'Consignes': 'Recto seul. Ajouter le logo et le plan d\'accès.'},
                              ['#13285C', '#F2C230'], ['institutionnel'], 500, -10, None, prise=1)
kid, _, pids = commande_dc(DC_CLIENTS[1], 'CMD-DC0002', 2, 10, 5, [{'nom': f'Conception · {titre}', 'qte': 1, 'prix': 10000, 'prepresse': True, 'brief': brief}], True, [(5000, True, 2, 10, 6)])
add('demande_conception', {**dc, 'commande_id': kid, 'paiement_acompte_id': pids[0]})

# 3. Cartes de visite : BAT validé, conception terminée et soldée.
dc, brief, titre = demande_dc(3, DC_CLIENTS[2], 'carte_visite', 'graphiste', 'terminee', 20,
                              {'Titre': nom_client(DC_CLIENTS[2])[:60], 'Coordonnées': fmt_tel(DC_CLIENTS[2]['tel'])},
                              ['#111111', '#FFFFFF'], ['elegant'], 500, 14, None, prise=19, terminee=17)
kid, _, pids = commande_dc(DC_CLIENTS[2], 'CMD-DC0003', 20, 9, 12, [{'nom': f'Conception · {titre}', 'qte': 1, 'prix': 5000, 'prepresse': True, 'brief': brief}], True,
                           [(2500, True, 20, 9, 14), (2500, True, 17, 15, 0)])
add('demande_conception', {**dc, 'commande_id': kid, 'paiement_acompte_id': pids[0]})

# 4. Affiche par l'IA : pack de 3 images payé, 2 images générées, impression
#    commandée avec la finalisation par un graphiste (à confirmer par l'atelier).
kid, _, pids = commande_dc(DC_CLIENTS[0], 'CMD-DC0004', 2, 18, 2, [{'nom': 'Crédit IA · 3 images', 'qte': 1, 'prix': 3000}], True, [(3000, True, 2, 18, 3)])
add('mouvement_credit_ia', {'type': 'achat', 'montant': 3000, 'images': 3, 'libelle': 'Crédit IA · 3 images · Orange Money', 'auteur': 'Oumar Guindo', 'etat': 'acquis',
                            'cle': f'achat-DEMO{hexa5(12)}', 'cree_le': T(2, 18, 3), 'public_id': uid5(), 'client_id': DC_CLIENTS[0]['id'],
                            'commande_id': kid, 'paiement_id': pids[0]})
dc, brief, titre = demande_dc(4, DC_CLIENTS[0], 'affiche', 'ia', 'commandee', 1,
                              {'Titre': 'Soirée jazz', 'Sous-titre': 'au bord du fleuve', 'Date et heure': 'Samedi 20 h · Salon Espoir',
                               'Consignes': 'Ambiance chaude, saxophone, lumière du soir sur le fleuve.'},
                              ['#13285C', '#F2C230'], ['elegant', 'festif'], 50, -4, 'Oumar Guindo', finalisation=True, images=2)
for i, (h, m) in enumerate([(14, 10), (14, 21)], 1):
    add('mouvement_credit_ia', {'type': 'debit', 'montant': -1000, 'images': -1, 'libelle': f'Image {i} · DC-D004', 'auteur': 'Oumar Guindo', 'etat': 'acquis', 'cle': None,
                                'cree_le': T(1, h, m), 'public_id': uid5(), 'client_id': DC_CLIENTS[0]['id'], 'commande_id': None, 'paiement_id': None})
add('credit_ia', {'solde': 1000, 'client_id': DC_CLIENTS[0]['id']})
note = f"Visuel IA de la demande DC-D004 (image 2). Remplacement des textes par le graphiste avant impression.\n{brief}"
kid, lids, _ = commande_dc(DC_CLIENTS[0], 'CMD-DC0005', 1, 14, 30,
                           [{'nom': f'Impression · {titre} (prix confirmé par l\'atelier)', 'qte': 50, 'prix': 0, 'mode': 'unite', 'prepresse': True, 'production': True,
                             'statut': 'a_produire', 'brief': note},
                            {'nom': 'Finalisation graphiste · visuel IA', 'qte': 1, 'prix': 5000, 'prepresse': True,
                             'brief': 'Reprendre le visuel IA choisi : textes, logo et photos du client, fichier prêt pour l\'impression.'}], False, [])
fich = add('commande_detail_fichier', {'jeton_upload': hexa5(64), 'morceaux_recus': 1, 'nombre_morceaux': 1, 'termine_le': T(1, 14, 30),
                                       'nom_original': 'visuel-ia-dc-d004-2.png', 'nom_stockage': f'demo-{hexa5(16)}.png', 'chemin': None, 'type_mime': 'image/png',
                                       'taille': 1460000, 'statut': 'TERMINE', 'origine': 'client', 'etat': 'a_traiter', 'deja_traite': 0, 'fichier_production': 0,
                                       'actif': 1, 'version': 1, 'quantite_aproduire': 50, 'face': None, 'designation': titre, 'groupe_fichier': None,
                                       'observation': None, 'cree_le': T(1, 14, 30), 'commande_detail_id': lids[0], 'fichier_source_id': None, 'ajoute_par_id': None,
                                       'public_id': uid5(), 'montage': None})
APRES.append(f"UPDATE `commande_detail_fichier` SET `client_id` = {DC_CLIENTS[0]['id']} WHERE id = {fich};")
add('demande_conception', {**dc, 'commande_id': kid, 'image_choisie_id': fich})

# ---------------------------------------------------------------- écriture SQL
TABLES_SANS_ID = {'produits_finition', 'produits_types_impression', 'produits_supports', 'produits_format', 'support_types_impression', 'support_format', 'format_types_impression',
                  'support_finition', 'finition_types_impression', 'controle_pre_presse_fichier', 'ordre_production_fichier', 'produit_client_finition'}
IGNORE = {'jour_ferie'}
SEULEMENT_SI_VIDE = {'parametres_paiement'}


def valeur(table, col, v):
    if table == 'conception' and col == 'evenements':
        # Les dates des événements dépendent du jour du chargement : JSON construit en SQL.
        parts = []
        for e in v:
            d, mins = map(int, e['le'][1:].split(':'))
            date = f"DATE_FORMAT(TIMESTAMP(CURDATE() - INTERVAL {d} DAY, '00:00:00') + INTERVAL {mins} MINUTE, '%Y-%m-%dT%H:%i:00+00:00')"
            parts.append(f"JSON_OBJECT('le', {date}, 'type', {q(e['type'])}, 'titre', {q(e['titre'])}, 'detail', {q(e['detail'])})")
        return 'JSON_ARRAY(' + ', '.join(parts) + ')'
    return q(v)


sortie = []
w = sortie.append
total = sum(len(v) for v in ROWS.values())
w(f"""-- Démonstration complète de Succès Imprim : une année d'activité fictive
-- dans toutes les tables. Généré par donnees-demo/generer_demo.py, ne pas
-- modifier à la main.
--
-- {total} lignes. Identifiants de démo à partir de {DEBUT_ID} ; dates
-- relatives au jour du chargement ; clients DEMO-xxxx avec des numéros en
-- 60 xx xx xx et recevoir_sms = 0 ; utilisateurs demo.xxx (mot de passe
-- demo1234).
--
-- Chargement : php bin/console app:demo  (ou l'onglet Importer de phpMyAdmin)
-- Pour tout enlever : php bin/console app:demo --purger

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
""")
w('-- Enlever une démo déjà chargée (ce fichier peut être relancé).')
PURGE = []
PURGE.append("DELETE k FROM commandes k JOIN clients c ON c.id = k.clients_id WHERE c.code LIKE 'DEMO-%' AND c.id < 900000;")
PURGE.append("DELETE FROM commandes WHERE numero LIKE 'CMD-DK-%';")
PURGE.append("DELETE FROM clients WHERE code LIKE 'DEMO-%' AND id < 900000;")
PURGE.append("DELETE FROM prospect WHERE telephone LIKE '+223 60 %' AND id < 900000;")
for table in reversed(ORDRE):
    if table in TABLES_SANS_ID:
        cols = list(ROWS[table][0])
        PURGE.append(f'DELETE FROM `{table}` WHERE `{cols[0]}` >= 900000;')
    else:
        PURGE.append(f'DELETE FROM `{table}` WHERE id >= 900000;')
for p in PURGE:
    w(p)
w('')
for table in ORDRE:
    rows = ROWS[table]
    cols = list(rows[0])
    for r in rows:
        for c in r:
            if c not in cols:
                cols.append(c)
    w(f'-- {table} : {len(rows)}')
    for i in range(0, len(rows), 200):
        bloc = rows[i:i + 200]
        if table in SEULEMENT_SI_VIDE:
            r = bloc[0]
            w(f"INSERT INTO `{table}` ({', '.join('`' + c + '`' for c in cols)}) SELECT {', '.join(valeur(table, c, r.get(c)) for c in cols)} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `{table}`);")
            continue
        verbe = 'INSERT IGNORE' if table in IGNORE else 'INSERT'
        lignes = [('(' + ', '.join(valeur(table, c, r[c]) if c in r else 'DEFAULT' for c in cols) + ')') for r in bloc]
        w(f"{verbe} INTO `{table}` ({', '.join('`' + c + '`' for c in cols)}) VALUES")
        w(',\n'.join(lignes) + ';')
if APRES:
    w('-- compléments des demandes de conception')
    w('\n'.join(APRES))
w('\nSET FOREIGN_KEY_CHECKS = 1;')

dossier = Path(__file__).parent
(dossier / 'demo.sql').write_text('\n'.join(sortie) + '\n')
(dossier / 'demo-purge.sql').write_text(
    "-- Enlève toute la démonstration (identifiants à partir de 900001).\n"
    "-- Attention : enlève aussi ce qui a été saisi dans ces tables après le chargement.\n"
    "SET FOREIGN_KEY_CHECKS = 0;\n" + '\n'.join(PURGE) + "\nSET FOREIGN_KEY_CHECKS = 1;\n")
compte = {t: len(v) for t, v in ROWS.items()}
print(f'{total} lignes dans {len(compte)} tables')
for t in sorted(compte, key=compte.get, reverse=True):
    print(f'  {t}: {compte[t]}')
