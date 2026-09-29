@echo off

:: ============================================================
:: EXECUTION DES RAPPELS DE PAIEMENT
::
:: Lance la commande Symfony qui envoie (SMS/email/WhatsApp) les
:: rappels de paiement dus pour les commandes non soldees, selon
:: les regles configurees dans Communications > Rappels de
:: paiement.
::
:: Ce script est concu pour etre appele chaque jour par une
:: tache planifiee Windows (voir
:: installer_tache_rappels_paiement.bat) : il ne fait rien de
:: visible si aucun rappel n'est du ce jour-la.
:: ============================================================

set PROJET=C:\xampp\htdocs\successImprim
set PHP=C:\xampp\php\php.exe
set LOG_FILE=%PROJET%\var\log\rappels_paiement.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

echo [%date% %time%] Debut execution rappels de paiement >> "%LOG_FILE%"

"%PHP%" "%PROJET%\bin\console" app:executer-rappels-paiement >> "%LOG_FILE%" 2>&1

echo [%date% %time%] Fin execution (code %ERRORLEVEL%) >> "%LOG_FILE%"
