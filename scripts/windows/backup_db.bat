@echo off
setlocal

:: ============================================================
:: SAUVEGARDE AUTOMATIQUE (docs/sauvegardes.md)
::
:: Lancee chaque heure et au demarrage par la tache planifiee
:: (installer_taches_planifiees.bat). C'est l'application qui
:: decide quoi faire, selon la tuile Reglages > Sauvegardes :
::   - base + fichiers une fois par jour, apres l'heure reglee ;
::   - base seule toutes les N heures ;
::   - rien si la derniere sauvegarde est recente.
:: Rotation, verification et copie sur le second disque
:: (SAUVEGARDE_COPIE_DIR dans .env.local) sont faites par
:: la commande app:sauvegarde.
:: ============================================================

set PROJET=C:\xamppok\htdocs\successImprim
set PHP=C:\xamppok\php\php.exe
set MYSQLADMIN=C:\xamppok\mysql\bin\mysqladmin.exe
set LOG_FILE=%PROJET%\var\log\sauvegarde.log

if not exist "%PROJET%\var\log" mkdir "%PROJET%\var\log"

:: ------------------------------------------------------------
:: Attente de MySQL : au demarrage de la machine, XAMPP peut
:: mettre quelques secondes a etre pret (10 essais, 5 s).
:: ------------------------------------------------------------
set TENTATIVES=0

:ATTENTE_MYSQL
"%MYSQLADMIN%" -u root ping >nul 2>&1
if %ERRORLEVEL% equ 0 goto MYSQL_PRET

set /a TENTATIVES+=1
if %TENTATIVES% geq 10 (
    echo [%date% %time%] MySQL indisponible apres 10 tentatives, sauvegarde reportee. >> "%LOG_FILE%"
    exit /b 1
)

timeout /t 5 /nobreak >nul
goto ATTENTE_MYSQL

:MYSQL_PRET

"%PHP%" "%PROJET%\bin\console" app:sauvegarde --auto --no-interaction >> "%LOG_FILE%" 2>&1
set CODE=%ERRORLEVEL%

if %CODE% neq 0 echo [%date% %time%] ECHEC de la sauvegarde (code %CODE%), voir Reglages ^> Securite ^> Sauvegardes >> "%LOG_FILE%"

exit /b %CODE%
