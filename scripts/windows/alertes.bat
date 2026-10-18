@echo off

:: ============================================================
:: ALERTES SYSTEME : VERIFICATIONS ET ENVOIS
::
:: Lance la commande Symfony qui verifie la synchronisation, les
:: sauvegardes, l'espace disque et la file de messages, puis
:: envoie par e-mail et dans l'application les alertes en
:: attente (Reglages > Alertes systeme). Voir
:: docs/alertes-systeme.md.
::
:: Ce script est concu pour etre appele toutes les 5 minutes par
:: une tache planifiee Windows (voir installer_tache_alertes.bat).
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\alertes.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

"%PHP%" "%PROJET%\bin\console" app:alertes -q >> "%LOG_FILE%" 2>&1

if not "%ERRORLEVEL%"=="0" echo [%date% %time%] Fin en erreur (code %ERRORLEVEL%) >> "%LOG_FILE%"
