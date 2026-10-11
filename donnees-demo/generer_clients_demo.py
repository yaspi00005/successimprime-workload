#!/usr/bin/env python3
"""
Génère donnees-demo/clients-demo.sql : clients fictifs pour essayer les
écrans Clients (K01 à K06). Toujours le même tirage (graine fixe).

    python3 donnees-demo/generer_clients_demo.py
"""
import random
from pathlib import Path

random.seed(2026)
N_CLIENTS = 200

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
SECTEURS_ENT = ['Hôtellerie', 'Restauration', 'Santé', 'Commerce', 'BTP', 'Banque et assurance', 'Transport', 'Télécoms', 'Événementiel', 'Agriculture', 'Mode', 'Immobilier']
ACTIVITES = [('Hôtel', 'Hôtellerie'), ('Restaurant', 'Restauration'), ('Pharmacie', 'Santé'), ('Clinique', 'Santé'), ('Boutique', 'Commerce'), ('Quincaillerie', 'BTP'), ('Garage', 'Transport'), ('Agence', 'Événementiel'), ('Cabinet', 'Banque et assurance'), ('Supermarché', 'Commerce'), ('Boulangerie', 'Restauration'), ('Salon', 'Mode'), ('Auto-école', 'Transport'), ('Immobilière', 'Immobilier'), ('Transports', 'Transport'), ('Tissus', 'Mode')]
ONGS = ['ONG Santé Plus', 'ONG Sahel Vert', 'Association Femmes et Développement', 'ONG Eau pour Tous', 'Fondation Éducation Mali', 'ONG Jeunesse Active', 'Association des Handicapés du Mandé', 'ONG Nutrition Enfance', 'Croix des Volontaires', 'ONG Agri-Sahel', 'Association Culture et Patrimoine', 'ONG Micro-Crédit Solidaire', 'Réseau des Femmes Rurales', 'ONG Paix et Dialogue', 'ONG Énergie Solaire Villages']
ADMINS = ['Mairie de la Commune {c}', 'Lycée {l}', 'Centre de santé de référence de la Commune {c}', 'Direction régionale de la santé', 'École fondamentale de {q}', 'Tribunal de grande instance de la Commune {c}', 'Institut de formation {l}', 'Direction des impôts de la Commune {c}', 'Centre culturel {l}', 'Office du tourisme']
FONCTIONS_ENT = ['Gérant', 'Directrice', 'Directeur', 'Comptable', 'Responsable achats', 'Assistante de direction', 'Chargée de communication', 'Intendant', 'Propriétaire', 'Responsable marketing']
FONCTIONS_ONG = ['Chargée de communication', 'Coordinateur', 'Responsable logistique', 'Comptable', 'Chef de projet', 'Assistante administrative']
FONCTIONS_ADM = ['Proviseur adjoint', 'Secrétaire général', 'Chef du personnel', 'Intendant', 'Régisseur', 'Directrice']
ORIGINES = ['site', 'whatsapp', 'boutique', 'telephone', 'recommandation', 'autre']
TRAVAUX = ['Bâche 400 × 150 cm', 'cartes de visite', 'roll-up', 't-shirts DTF', 'flyers A5', 'enseigne lumineuse', 'calendriers', 'kakémonos', 'autocollants', 'banderoles', 'catalogue 16 pages', 'chemises à rabat', 'badges', 'casquettes brodées', 'affiches A2']
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

def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"

numeros = set()
def telephone():
    # Numéros fictifs en 60 : aucun SMS ne doit partir vers un vrai abonné.
    while True:
        n = '60' + ''.join(random.choice('0123456789') for _ in range(6))
        if n not in numeros:
            numeros.add(n)
            return n

def personne():
    f = random.random() < 0.5
    return random.choice(PRENOMS_F if f else PRENOMS_H), random.choice(NOMS)

types = ['entreprise'] * 90 + ['ong'] * 20 + ['administration'] * 20 + ['particulier'] * 70
random.shuffle(types)
noms_pris = set()

sql = []
w = sql.append
w("""-- Clients fictifs pour essayer les écrans Clients (K01 à K06).
-- Généré par donnees-demo/generer_clients_demo.py : ne pas modifier à la main.
--
-- Contenu : 200 clients (90 entreprises, 20 ONG, 20 administrations,
-- 70 particuliers) avec contacts, commandes, paiements, prospects,
-- notes et journal des modifications. Les dates sont relatives au jour
-- du chargement.
--
-- Repères pour tout retrouver (et tout enlever, voir clients-demo-purge.sql) :
--   clients : code DEMO-0001 à DEMO-0200 ; commandes : CMD-DK-xxxxx ;
--   téléphones fictifs en 60 xx xx xx ; recevoir_sms = 0 sur ces fiches.
--
-- Chargement : mysql -u UTILISATEUR -p BASE < donnees-demo/clients-demo.sql
-- (ou l'onglet Importer de phpMyAdmin). Le fichier commence par enlever
-- une ancienne démo : on peut le relancer autant de fois qu'on veut.

SET NAMES utf8mb4;
START TRANSACTION;
""")
w(Path(__file__).with_name('clients-demo-purge.sql').read_text().split('-- DEBUT')[1].split('-- FIN')[0].strip())
w("""
-- Commerciaux : les utilisateurs actifs existants, quels qu'ils soient.
SET @u1 = (SELECT id FROM `user` WHERE actif = 1 ORDER BY id LIMIT 1);
SET @u2 = COALESCE((SELECT id FROM `user` WHERE actif = 1 ORDER BY id LIMIT 1 OFFSET 1), @u1);
SET @u3 = COALESCE((SELECT id FROM `user` WHERE actif = 1 ORDER BY id LIMIT 1 OFFSET 2), @u1);
SET @u4 = COALESCE((SELECT id FROM `user` WHERE actif = 1 ORDER BY id LIMIT 1 OFFSET 3), @u2);
SET @ent = (SELECT code FROM entreprise ORDER BY id LIMIT 1);
""")

ncmd = 0
stats = dict(clients=0, contacts=0, commandes=0, paiements=0, prospects=0, notes=0, modifications=0)
contacts_existants = []

for i in range(1, N_CLIENTS + 1):
    t = types[i - 1]
    commune = random.choice(list(COMMUNES))
    quartier = random.choice(COMMUNES[commune])
    suivi = random.choice(['@u1', '@u2', '@u3', '@u4', '@u2', '@u3'])
    cree = random.randint(20, 900)
    contacts = []
    secteur = None
    if t == 'entreprise':
        while True:
            act, secteur = random.choice(ACTIVITES)
            rs = f'{act} {random.choice(LIEUX)}' if random.random() < 0.7 else f'{act} {random.choice(NOMS)} et Fils'
            if rs not in noms_pris:
                break
        nb = random.choice([1, 1, 2, 2, 3])
        for k in range(nb):
            p, n = personne()
            contacts.append((f'{p} {n}', random.choice(FONCTIONS_ENT), telephone(), random.random() < 0.8, ['principal', 'factures', 'bat'][k]))
    elif t == 'ong':
        rs = ONGS[(i * 7) % len(ONGS)]
        rs = rs if rs not in noms_pris else f'{rs} {random.choice(LIEUX)}'
        secteur = 'Humanitaire'
        for k in range(random.choice([1, 2])):
            p, n = personne()
            contacts.append((f'{p} {n}', random.choice(FONCTIONS_ONG), telephone(), True, ['principal', 'factures'][k]))
    elif t == 'administration':
        modele = random.choice(ADMINS)
        rs = modele.format(c=random.choice(['I', 'II', 'III', 'IV', 'V', 'VI']), l=random.choice(LIEUX), q=quartier)
        while rs in noms_pris:
            rs = f'{rs} {random.choice(LIEUX)}'
        secteur = 'Administration publique' if 'Lycée' not in rs and 'École' not in rs and 'Institut' not in rs else 'Éducation'
        p, n = personne()
        contacts.append((f'{p} {n}', random.choice(FONCTIONS_ADM), telephone(), random.random() < 0.6, 'principal'))
    else:
        rs = None
    if rs:
        noms_pris.add(rs)

    if t == 'particulier':
        prenom, nom = personne()
        tel = telephone()
    else:
        prenom, nom = contacts[0][0].split(' ', 1)
        tel = contacts[0][2]
    b2b = t != 'particulier' and random.random() < 0.45
    remise = random.choice([None, None, 3, 5, 5, 7.5, 10]) if b2b else (random.choice([None, None, None, 5]) if t != 'particulier' else None)
    delai = random.choice(['comptant', '15_jours', '30_jours', '30_jours_fdm', '45_jours']) if t != 'particulier' else random.choice([None, 'comptant'])
    if t == 'administration':
        delai = random.choice(['30_jours', '45_jours', '60_jours'])
    plafond = random.choice([100000, 200000, 300000, 500000]) if t != 'particulier' else random.choice([None, 50000, 100000])
    acompte = random.choice([None, 30, 50, 50, 100]) if t != 'administration' else None
    moyen = random.choice(['orange_money', 'orange_money', 'especes', 'virement', 'cheque', 'wave'])
    if t == 'particulier':
        moyen = random.choice(['orange_money', 'orange_money', 'especes', 'wave'])
    etiquettes = []
    if secteur and random.random() < 0.6:
        etiquettes.append(secteur)
    if random.random() < 0.3:
        etiquettes.append(random.choice(['Fidèle', 'VIP', 'Signalétique', 'Textile', 'Grand format', 'Événementiel', 'Payeur lent']))
    gps = random.random() < 0.6
    lat = round(12.60 + random.uniform(-0.06, 0.06), 7) if gps else None
    lng = round(-7.99 + random.uniform(-0.08, 0.08), 7) if gps else None
    email = None
    if t != 'particulier' and random.random() < 0.6:
        email = 'contact@' + ''.join(ch for ch in rs.lower().replace(' ', '-').replace("'", '') if ch.isalnum() or ch == '-')[:30].strip('-') + '.ml'
        email = email.encode('ascii', 'ignore').decode()
    actif = random.random() > 0.03
    marketing = random.random() < 0.55
    obs = random.choice([None, None, None, 'Client depuis l\'ouverture de la boutique.', 'Toujours demander un BAT avant impression.', 'Paie souvent en retard, demander l\'acompte.', 'Contact privilégié : le comptable.'])
    nif = ('0' + ''.join(random.choice('0123456789') for _ in range(8)) + random.choice('ABCDEFGH')) if t in ('entreprise', 'ong') and random.random() < 0.6 else None
    rccm = f'MA.BKO.{random.randint(2012, 2025)}.{random.choice("ABM")}.{random.randint(1000, 9999)}' if t == 'entreprise' and random.random() < 0.5 else None

    w(f"\n-- {i:03d} · {rs or prenom + ' ' + nom}")
    w("INSERT INTO clients (code, raison_sociale, nom, prenom, telephone, email, nif, rccm, type_client, type_compte, plafond_credit, solde_credit, observation, created_at, updated_at, statut, recevoir_sms, public_id, latitude, longitude, repere, localise_le, consentement_marketing_le, secteur, origine, delai_paiement, remise_habituelle, acompte, moyen_prefere, entite_facturation, commune, quartier, etiquettes, suivi_par_id) VALUES ("
      + ', '.join([
          q(f'DEMO-{i:04d}'), q(rs), q(nom[:50]), q(prenom[:50]), q(tel), q(email), q(nif), q(rccm),
          q('B2B' if b2b else 'B2C'), q(t), q(plafond), '0', q(obs),
          f'NOW() - INTERVAL {cree} DAY', f'NOW() - INTERVAL {random.randint(0, cree)} DAY', '1' if actif else '0', '0',
          "UNHEX(REPLACE(UUID(), '-', ''))", q(lat), q(lng), q(random.choice(REPERES) if random.random() < 0.5 else None),
          f'NOW() - INTERVAL {random.randint(1, cree)} DAY' if gps else 'NULL',
          f'NOW() - INTERVAL {random.randint(1, cree)} DAY' if marketing else 'NULL',
          q(secteur), q(random.choice(ORIGINES)), q(delai), q(remise), q(acompte), q(moyen), '@ent', q(commune), q(quartier),
          q('[' + ','.join('"' + e + '"' for e in etiquettes) + ']'), suivi,
      ]) + ");")
    w("SET @c = LAST_INSERT_ID();")
    stats['clients'] += 1

    for (cn, fonction, ctel, wa, role) in contacts:
        w(f"INSERT INTO contact_client (client_id, nom, fonction, telephone, whatsapp, email, role) VALUES (@c, {q(cn)}, {q(fonction)}, {q(ctel)}, {1 if wa else 0}, NULL, {q(role)});")
        contacts_existants.append((rs, ctel))
        stats['contacts'] += 1

    # Commandes : profil d'activité tiré au sort.
    profil = random.choices(['actif', 'inactif', 'nouveau', 'retard'], weights=[50, 22, 12, 16])[0]
    if profil == 'nouveau':
        nb_cmd = 0
    elif profil == 'inactif':
        nb_cmd = random.randint(1, 3)
    else:
        nb_cmd = random.randint(1, 4)
    jours = sorted((random.randint(61, min(cree, 700)) if profil == 'inactif' else random.randint(0, min(cree, 360))) for _ in range(nb_cmd)) if cree > 60 or profil != 'inactif' else []
    if profil == 'actif' and jours:
        jours[0] = random.randint(0, 50)
    for j, jour in enumerate(jours):
        ncmd += 1
        base = random.choice([15000, 25000, 45000, 60000, 85000, 120000, 180000, 250000, 400000, 650000])
        if t == 'particulier':
            base = random.choice([5000, 10000, 15000, 25000, 40000, 60000])
        total = int(round(base * random.uniform(0.8, 1.6), -3))
        livraison = jour - random.randint(1, 5)
        # Paiement : tout payé, sauf la plus récente des clients en retard (et quelques acomptes).
        if profil == 'retard' and j == 0:
            paye = random.choice([0, int(total * 0.5)])
            jour = max(jour, 0)
            livraison = jour - random.randint(1, 3) if jour > 35 else -random.randint(1, 2)
            if jour <= 35:
                jour = random.randint(36, 140)
                livraison = jour - 2
        elif j == 0 and jour < 6 and random.random() < 0.5:
            paye = int(total * (acompte or 50) / 100)
            livraison = jour - random.randint(3, 8)
        else:
            paye = total
        statut_p = 'payee' if paye >= total else ('partielle' if paye > 0 else 'impayee')
        numero = f'CMD-DK-{ncmd:05d}'
        w("INSERT INTO commandes (numero, date_commande, date_livraison, recupere_par_client, remise, tva, total_ht, total_ttc, montant_apayer, observation, deleted, statut, etat, statut_paiement, reste_apayer, total_paye, public_id, bonus_plafond_applique, montant_bonus_plafond, clients_id, agents_id, canal, mode_livraison) VALUES ("
          + ', '.join([q(numero), f'NOW() - INTERVAL {jour} DAY - INTERVAL {random.randint(0, 600)} MINUTE', f'NOW() - INTERVAL {livraison} DAY',
                       '1' if livraison >= 0 and random.random() < 0.7 else '0', '0', '0', str(total), str(total), str(total),
                       q(random.choice(TRAVAUX).capitalize()), '0', '1', '1', q(statut_p), str(total - paye), str(paye),
                       "UNHEX(REPLACE(UUID(), '-', ''))", '0', '0', '@c', suivi,
                       q(random.choice(['comptoir', 'comptoir', 'whatsapp', 'telephone'])), q(random.choice(['retrait', 'retrait', 'livraison']))]) + ");")
        stats['commandes'] += 1
        if paye > 0:
            w("SET @k = LAST_INSERT_ID();")
            parts = [paye] if paye < total or random.random() < 0.8 else [int(total * 0.5), total - int(total * 0.5)]
            for n_p, montant in enumerate(parts):
                mode = moyen if moyen in ('orange_money', 'especes', 'wave', 'cheque') else ('virement_bancaire' if moyen == 'virement' else 'especes')
                quand = max(0, jour - n_p * random.randint(1, 4))
                w(f"INSERT INTO paiements (montant, mode, statut, date, date_validation, frais_retrait_inclus, fonds_soutien_inclus, montant_frais_retrait, montant_fonds_soutien, commande_id, encaisse_par_id, valide_par_id, public_id) VALUES ({montant}, {q(mode)}, 'valide', NOW() - INTERVAL {quand} DAY, NOW() - INTERVAL {quand} DAY, 0, 0, 0, 0, @k, {suivi}, @u1, UNHEX(REPLACE(UUID(), '-', '')));")
                stats['paiements'] += 1

    # Notes et comptes rendus.
    if random.random() < 0.3:
        for _ in range(random.randint(1, 3)):
            typ, texte = random.choice(NOTES)
            le = random.randint(0, min(cree, 120))
            rappel = f'NOW() + INTERVAL {random.randint(1, 10)} DAY' if 'rappeler' in texte.lower() or (typ == 'appel' and random.random() < 0.3) else 'NULL'
            w(f"INSERT INTO note_client (client_id, auteur_id, le, type, texte, rappel_le) VALUES (@c, {suivi}, NOW() - INTERVAL {le} DAY - INTERVAL {random.randint(0, 600)} MINUTE, {q(typ)}, {q(texte.format(t=random.choice(TRAVAUX)))}, {rappel});")
            stats['notes'] += 1

    # Journal : création, puis quelques changements.
    w(f"INSERT INTO modification_client (client_id, le, par_id, auteur, champ, categorie, avant, apres, depuis) VALUES (@c, NOW() - INTERVAL {cree} DAY, {suivi}, (SELECT username FROM `user` WHERE id = {suivi}), 'Fiche créée', 'identite', NULL, {q((rs or prenom + ' ' + nom) + ' · ' + {'entreprise': 'Entreprise', 'ong': 'ONG', 'administration': 'Administration', 'particulier': 'Particulier'}[t])}, 'erp_local');")
    stats['modifications'] += 1
    if random.random() < 0.35:
        changements = []
        if delai:
            changements.append(('Délai de paiement', 'conditions', random.choice(['Comptant', '15 jours']), {'comptant': 'Comptant', '15_jours': '15 jours', '30_jours': '30 jours', '30_jours_fdm': '30 jours fin de mois', '45_jours': '45 jours', '60_jours': '60 jours'}[delai], 'erp_local'))
        if plafond:
            changements.append(('Plafond de crédit', 'conditions', f'{plafond // 2:,} F'.replace(',', ' '), f'{plafond:,} F'.replace(',', ' '), 'erp_local'))
        if contacts:
            changements.append(('Téléphone', 'identite', '60 ' + ' '.join(telephone()[2:][k:k + 2] for k in range(0, 6, 2)), ' '.join(tel[k:k + 2] for k in range(0, 8, 2)), random.choice(['espace_client', 'admin_en_ligne'])))
        if b2b:
            changements.append(('Grille tarifaire', 'conditions', 'Standard', 'Entreprise', 'admin_en_ligne'))
        if len(contacts) > 1:
            changements.append(('Contact ajouté', 'contacts', None, f'{contacts[1][0]} · {contacts[1][1]} · {contacts[1][4]}', 'erp_local'))
        for (champ, cat, av, ap, depuis) in random.sample(changements, k=min(len(changements), random.randint(1, 3))):
            le = random.randint(0, cree - 1)
            auteur = f"(SELECT username FROM `user` WHERE id = {suivi})" if depuis != 'espace_client' else q(prenom + ' ' + nom)
            par = suivi if depuis != 'espace_client' else 'NULL'
            w(f"INSERT INTO modification_client (client_id, le, par_id, auteur, champ, categorie, avant, apres, depuis) VALUES (@c, NOW() - INTERVAL {le} DAY - INTERVAL {random.randint(0, 600)} MINUTE, {par}, {auteur}, {q(champ)}, {q(cat)}, {q(av)}, {q(ap)}, {q(depuis)});")
            stats['modifications'] += 1

# Prospects : nouveaux numéros (à transformer) et quelques doublons de contacts existants.
w("\n-- Prospects")
for k in range(30):
    if k < 6:
        nom_p, tel_p = random.choice(contacts_existants)
        nom_p = nom_p or 'Contact existant'
    else:
        act, _ = random.choice(ACTIVITES)
        nom_p = f'{act} {random.choice(LIEUX)} {random.choice(["Plus", "Express", "Center", "Services", "Distribution"])}'
        tel_p = telephone()
    etape = random.choices(['nouveau', 'devis_envoye', 'negociation', 'perdu'], weights=[45, 25, 20, 10])[0]
    cree = random.randint(0, 45)
    w("INSERT INTO prospect (nom, telephone, besoin, montant_estime, source, etape, motif_perte, prochaine_relance, cree_le, mis_a_jour_le, cloture_le, agent_id) VALUES ("
      + ', '.join([q(nom_p), q('+223 ' + ' '.join(tel_p[i:i + 2] for i in range(0, 8, 2))), q(random.choice(TRAVAUX).capitalize()), str(random.choice([50000, 120000, 250000, 450000, 900000])),
                   q(random.choice(ORIGINES)), q(etape), q('Prix trop élevé' if etape == 'perdu' else None),
                   f'CURDATE() + INTERVAL {random.randint(0, 7)} DAY' if etape != 'perdu' else 'NULL',
                   f'NOW() - INTERVAL {cree} DAY', f'NOW() - INTERVAL {max(0, cree - random.randint(0, 5))} DAY',
                   f'NOW() - INTERVAL {max(0, cree - 3)} DAY' if etape == 'perdu' else 'NULL', random.choice(['@u1', '@u2', '@u3'])]) + ");")
    stats['prospects'] += 1

w("\nCOMMIT;\n")
w('-- Total : ' + ', '.join(f'{v} {k}' for k, v in stats.items()))
out = Path(__file__).with_name('clients-demo.sql')
out.write_text('\n'.join(sql) + '\n')
print(out, stats, sum(stats.values()), 'insertions')
