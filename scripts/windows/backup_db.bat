@echo off
setlocal enabledelayedexpansion

:: ============================================================
:: CONFIGURATION
:: ============================================================
set DB_USER=root
set DB_PASS=
set DB_NAME=success_imprim
set BACKUP_DIR=C:\xamppok\htdocs\successImprim\backups
set MYSQLDUMP=C:\xamppok\mysql\bin\mysqldump.exe
set MYSQLADMIN=C:\xamppok\mysql\bin\mysqladmin.exe
set LOG_FILE=%BACKUP_DIR%\backup.log
set JOURS_A_CONSERVER=30

:: ============================================================
:: DOSSIER DE SAUVEGARDE
:: ============================================================
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

:: ============================================================
:: ATTENTE DE MYSQL
::
:: Utile surtout au demarrage de la machine : MySQL (XAMPP) peut
:: mettre quelques secondes a etre pret. On tente 10 fois, toutes
:: les 5 secondes, avant d'abandonner.
:: ============================================================
set TENTATIVES=0

:ATTENTE_MYSQL
"%MYSQLADMIN%" -u %DB_USER% ping >nul 2>&1
if %ERRORLEVEL% equ 0 goto MYSQL_PRET

set /a TENTATIVES+=1
if %TENTATIVES% geq 10 (
    echo [%date% %time%] MySQL indisponible apres 10 tentatives, sauvegarde annulee. >> "%LOG_FILE%"
    exit /b 1
)

timeout /t 5 /nobreak >nul
goto ATTENTE_MYSQL

:MYSQL_PRET

:: ============================================================
:: HORODATAGE
::
:: Via PowerShell plutot que %date%/%time% : independant de la
:: langue/region configuree sur la machine Windows.
:: ============================================================
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set TIMESTAMP=%%i

set FICHIER=%BACKUP_DIR%\%DB_NAME%_%TIMESTAMP%.sql

:: ============================================================
:: SAUVEGARDE
:: ============================================================
echo [%date% %time%] Debut de la sauvegarde... >> "%LOG_FILE%"

if "%DB_PASS%"=="" (
    "%MYSQLDUMP%" -u %DB_USER% %DB_NAME% > "%FICHIER%" 2>> "%LOG_FILE%"
) else (
    "%MYSQLDUMP%" -u %DB_USER% -p%DB_PASS% %DB_NAME% > "%FICHIER%" 2>> "%LOG_FILE%"
)

if %ERRORLEVEL% neq 0 (
    echo [%date% %time%] ECHEC de la sauvegarde ^(code %ERRORLEVEL%^) >> "%LOG_FILE%"
    del "%FICHIER%" 2>nul
    exit /b 1
)

echo [%date% %time%] Sauvegarde reussie : %FICHIER% >> "%LOG_FILE%"

:: ============================================================
:: PURGE DES SAUVEGARDES DE PLUS DE %JOURS_A_CONSERVER% JOURS
::
:: Evite de remplir le disque : a une sauvegarde toutes les 6h,
:: on accumule vite plusieurs centaines de fichiers.
:: ============================================================
forfiles /p "%BACKUP_DIR%" /m "%DB_NAME%_*.sql" /d -%JOURS_A_CONSERVER% /c "cmd /c del @path" 2>nul

exit /b 0
