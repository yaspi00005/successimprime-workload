@echo off

:: ============================================================
:: CORBEILLE : SUPPRESSION DEFINITIVE DE LA NUIT
::
:: Lance la commande Symfony qui supprime pour de bon, avec
:: leurs fichiers, les elements de la corbeille plus vieux que
:: la duree reglee (Reglages > Parametres > Corbeille, 30 jours
:: par defaut). Voir docs/corbeille.md.
::
:: Ce script est concu pour etre appele chaque jour par une
:: tache planifiee Windows (voir installer_tache_corbeille.bat),
:: apres la sauvegarde de 02:00.
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\corbeille.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

echo [%date% %time%] Debut suppression definitive de la corbeille >> "%LOG_FILE%"

"%PHP%" "%PROJET%\bin\console" app:corbeille:purger >> "%LOG_FILE%" 2>&1

echo [%date% %time%] Fin execution (code %ERRORLEVEL%) >> "%LOG_FILE%"
