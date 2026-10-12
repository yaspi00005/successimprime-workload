@echo off
setlocal

:: ============================================================
:: SYNCHRONISATION AVEC LE SITE EN LIGNE (docs/synchronisation.md)
::
:: Lancee chaque minute par la tache planifiee
:: (installer_tache_synchro.bat). Envoie au site ce qui a change
:: dans l'ERP local, puis recoit ce qui a ete cree en ligne.
:: Sans SYNC_URL et SYNC_CLE dans .env.local, elle ne fait rien.
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set LOG_FILE=%PROJET%\var\log\synchro.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

"%PHP%" "%PROJET%\bin\console" app:synchro --no-interaction >> "%LOG_FILE%" 2>&1

exit /b %ERRORLEVEL%
