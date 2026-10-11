-- Enlève les clients fictifs chargés par clients-demo.sql, et tout ce qui
-- y est rattaché (contacts, commandes, paiements, notes, journal, devis
-- faits pendant les essais). Ne touche à aucune autre donnée.
--
-- Utilisation : mysql -u UTILISATEUR -p BASE < donnees-demo/clients-demo-purge.sql

SET NAMES utf8mb4;
START TRANSACTION;
-- DEBUT
-- Enlever une ancienne démo (clients DEMO-xxxx, commandes CMD-DK-xxxxx).
DELETE f FROM factures f JOIN commandes k ON k.id = f.commande_id JOIN clients c ON c.id = k.clients_id WHERE c.code LIKE 'DEMO-%';
DELETE d FROM devis d JOIN clients c ON c.id = d.clients_id WHERE c.code LIKE 'DEMO-%';
DELETE p FROM produit_client p JOIN clients c ON c.id = p.client_id WHERE c.code LIKE 'DEMO-%';
DELETE k FROM commandes k JOIN clients c ON c.id = k.clients_id WHERE c.code LIKE 'DEMO-%';
DELETE FROM commandes WHERE numero LIKE 'CMD-DK-%';
DELETE FROM clients WHERE code LIKE 'DEMO-%';
DELETE FROM prospect WHERE telephone LIKE '+223 60 %';
-- FIN
COMMIT;
