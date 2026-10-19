@echo off

:: ============================================================
:: DOSSIERS CLIENTS : ANALYSE DES DOSSIERS DES CLIENTS
::
:: Lit les dossiers des clients et le dossier "A classer"
:: (fichiers, miniatures, versions). Analyse rapide a chaque
:: passage, analyse complete une fois par nuit a l'heure reglee
:: (Reglages > Dossiers clients, 02:00 par defaut).
:: L'ERP ne deplace et ne supprime jamais un fichier.
:: Voir docs/dossiers-clients.md.
::
:: Appele toutes les 5 minutes par une tache planifiee Windows
:: (voir installer_tache_dossiers_clients.bat).
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\dossiers_clients.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

"%PHP%" "%PROJET%\bin\console" app:dossiers-clients:analyser >> "%LOG_FILE%" 2>&1
