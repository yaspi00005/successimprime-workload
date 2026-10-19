@echo off

:: ============================================================
:: DEVIS : EXPIRATION ET RELANCES WHATSAPP
::
:: Lance la commande Symfony qui passe en "Expire" les devis sans
:: reponse dont la validite est depassee, et relance sur WhatsApp
:: les devis envoyes depuis 3 jours (docs/devis.md).
::
:: Appele chaque heure par une tache planifiee Windows (voir
:: installer_tache_devis_relances.bat).
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\devis_relances.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

"%PHP%" "%PROJET%\bin\console" app:devis:relances >> "%LOG_FILE%" 2>&1

:: Prepresse (docs/prepresse.md) : relances des fichiers manquants
:: (J+1, J+3, J+7, J+15), meme tache, chaque heure.
"%PHP%" "%PROJET%\bin\console" app:prepresse:relances >> "%LOG_FILE%" 2>&1
