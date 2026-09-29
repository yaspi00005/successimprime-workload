@echo off

:: ============================================================
:: DEMARRAGE DU SERVEUR SYMFONY
::
:: Volontairement separe de backup_db.bat : la sauvegarde tourne
:: desormais toutes les 6h via une tache planifiee (voir
:: installer_taches_planifiees.bat), et ne doit jamais relancer
:: le serveur au passage (ca couperait les connexions en cours).
:: ============================================================

cd /d C:\xamppok\htdocs\successImprim
symfony server:start --listen-ip=0.0.0.0 --port=8000 --no-tls
