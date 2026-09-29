@echo off

:: ============================================================
:: EXECUTION DES CAMPAGNES
::
:: Lance la commande Symfony qui envoie un petit lot de messages
:: de campagne en attente (SMS/email/WhatsApp).
::
:: Ce script est concu pour etre appele toutes les 10 minutes par
:: une tache planifiee Windows (voir installer_tache_campagnes.bat) :
:: il ne fait rien de visible si aucun message n'est en attente.
:: ============================================================

set PROJET=C:\xampp\htdocs\successImprim
set PHP=C:\xampp\php\php.exe
set LOG_FILE=%PROJET%\var\log\campagnes.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

echo [%date% %time%] Debut execution campagnes >> "%LOG_FILE%"

"%PHP%" "%PROJET%\bin\console" app:envoyer-campagnes >> "%LOG_FILE%" 2>&1

echo [%date% %time%] Fin execution (code %ERRORLEVEL%) >> "%LOG_FILE%"
