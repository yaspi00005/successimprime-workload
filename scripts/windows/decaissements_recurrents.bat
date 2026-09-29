@echo off

:: ============================================================
:: EXECUTION DES DECAISSEMENTS AUTOMATIQUES
::
:: Lance la commande Symfony qui cree les mouvements de
:: tresorerie des charges recurrentes arrivees a echeance
:: (frais bancaires, remboursement de credit...).
::
:: Ce script est concu pour etre appele chaque jour par une
:: tache planifiee Windows (voir
:: installer_tache_decaissements_recurrents.bat) : il ne fait
:: rien de visible si aucune charge n'est due ce jour-la.
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\decaissements_recurrents.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

echo [%date% %time%] Debut execution decaissements recurrents >> "%LOG_FILE%"

"%PHP%" "%PROJET%\bin\console" app:executer-decaissements-recurrents >> "%LOG_FILE%" 2>&1

echo [%date% %time%] Fin execution (code %ERRORLEVEL%) >> "%LOG_FILE%"
