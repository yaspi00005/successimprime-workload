@echo off
setlocal

:: ============================================================
:: RAPPORTS DU JOUR, DE LA SEMAINE ET DU MOIS PAR E-MAIL
:: (docs/rapports-mail.md)
::
:: Lancee toutes les 15 minutes par la tache planifiee
:: (installer_tache_rapports_mail.bat). Chaque rapport part a
:: son heure des Reglages, une seule fois par periode ; elle ne
:: fait rien le reste du temps. Sans MAILER_DSN et
:: MAILER_FROM_ADDRESS dans .env.local, rien ne part.
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\rapports_mail.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

"%PHP%" "%PROJET%\bin\console" app:rapports-mail --no-interaction >> "%LOG_FILE%" 2>&1

exit /b %ERRORLEVEL%
