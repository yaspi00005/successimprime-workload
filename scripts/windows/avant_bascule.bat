@echo off
setlocal

:: ============================================================
:: AVANT LA BASCULE (docs/mise-en-production.md)
::
:: A lancer d'un double-clic sur l'ordinateur de l'ERP, avant
:: d'installer la nouvelle version. Rien a modifier.
::
:: 1. Sauvegarde COMPLETE de la base (structure + donnees), dans
::    C:\xamppok\sauvegardes-bascule (hors du site web). Elle
::    reste a l'imprimerie : ne jamais l'envoyer.
:: 2. Copie de la STRUCTURE SEULE (aucune ligne, aucune donnee
::    client), sur le Bureau : c'est ce fichier qu'on envoie.
::
:: La base est trouvee comme le fait l'ancien backup_db.bat
:: (lignes "set DB_NAME=", "set DB_USER=", "set DB_PASS="). Si
:: ce n'est pas la bonne, le script cherche la base qui contient
:: les tables clients et commandes. On peut aussi donner le nom :
::     avant_bascule.bat nom_de_la_base
:: ============================================================

:: ------------------------------------------------------------
:: XAMPP, MySQL et dossier de l'application
:: ------------------------------------------------------------
set "XAMPP="
if exist "C:\xamppok\mysql\bin\mysqldump.exe" set "XAMPP=C:\xamppok"
if not defined XAMPP if exist "C:\xampp\mysql\bin\mysqldump.exe" set "XAMPP=C:\xampp"
if not defined XAMPP goto SANS_XAMPP

set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"
set "MYSQLDUMP=%XAMPP%\mysql\bin\mysqldump.exe"
set "MYSQLADMIN=%XAMPP%\mysql\bin\mysqladmin.exe"

set "PROJET=%XAMPP%\htdocs\successImprim"
if exist "%~dp0..\..\bin\console" set "PROJET=%~dp0..\.."

:: ------------------------------------------------------------
:: Base, utilisateur et mot de passe, lus dans backup_db.bat
:: ------------------------------------------------------------
set "DB_USER=root"
set "DB_PASS="
set "DB_NAME="
set "ANCIEN=%PROJET%\scripts\windows\backup_db.bat"
if not exist "%ANCIEN%" goto CONFIG_LUE
for /f "usebackq tokens=1,* delims==" %%a in (`findstr /b /i /c:"set DB_NAME=" /c:"set DB_USER=" /c:"set DB_PASS=" "%ANCIEN%"`) do (
    if /i "%%a"=="set DB_NAME" set "DB_NAME=%%b"
    if /i "%%a"=="set DB_USER" set "DB_USER=%%b"
    if /i "%%a"=="set DB_PASS" set "DB_PASS=%%b"
)
:CONFIG_LUE
if not "%~1"=="" set "DB_NAME=%~1"

:: Le mot de passe passe par MYSQL_PWD, jamais sur la ligne de commande.
set "MYSQL_PWD="
if defined DB_PASS set "MYSQL_PWD=%DB_PASS%"

:: ------------------------------------------------------------
:: MySQL doit etre demarre (panneau XAMPP)
:: ------------------------------------------------------------
"%MYSQLADMIN%" -u %DB_USER% ping >nul 2>&1
if errorlevel 1 goto SANS_MYSQL

:: ------------------------------------------------------------
:: La base doit contenir la table commandes ; sinon on cherche
:: ------------------------------------------------------------
set "LISTE=%TEMP%\avant_bascule_bases.txt"
if not defined DB_NAME goto CHERCHER_BASE
call :COMPTER_COMMANDES
if "%TROUVE%"=="1" goto BASE_TROUVEE

echo La base "%DB_NAME%" indiquee dans backup_db.bat n'a pas de table commandes.
:CHERCHER_BASE
"%MYSQL%" -u %DB_USER% -N -B -e "SELECT table_schema FROM information_schema.tables WHERE table_name IN ('clients','commandes') GROUP BY table_schema HAVING COUNT(DISTINCT table_name) = 2" > "%LISTE%" 2>nul
set "NB=0"
for /f %%n in ('find /c /v "" ^< "%LISTE%"') do set "NB=%%n"
if "%NB%"=="0" goto SANS_BASE
if "%NB%"=="1" (
    for /f "usebackq delims=" %%b in ("%LISTE%") do set "DB_NAME=%%b"
    goto BASE_TROUVEE
)

echo.
echo Plusieurs bases ressemblent a celle de l'ERP :
type "%LISTE%"
echo.
set "DB_NAME="
set /p DB_NAME=Tapez le nom de la base de l'ERP utilisee tous les jours, puis Entree :
if not defined DB_NAME goto SANS_BASE
call :COMPTER_COMMANDES
if not "%TROUVE%"=="1" goto SANS_BASE

:BASE_TROUVEE
del "%LISTE%" 2>nul
echo.
echo Base de l'ERP : %DB_NAME%

:: ------------------------------------------------------------
:: Horodatage (PowerShell : independant de la langue de Windows)
:: et dossiers
:: ------------------------------------------------------------
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "TS=%%i"
if not defined TS set "TS=%RANDOM%"

set "SAUVEGARDES=%XAMPP%\sauvegardes-bascule"
if not exist "%SAUVEGARDES%" mkdir "%SAUVEGARDES%"

set "BUREAU="
for /f "usebackq delims=" %%d in (`powershell -NoProfile -Command "[Environment]::GetFolderPath('Desktop')"`) do set "BUREAU=%%d"
if not defined BUREAU set "BUREAU=%SAUVEGARDES%"
if not exist "%BUREAU%\" set "BUREAU=%SAUVEGARDES%"

set "COMPLETE=%SAUVEGARDES%\%DB_NAME%_complete_%TS%.sql"
set "STRUCTURE=%BUREAU%\structure-%DB_NAME%-%TS%.sql"

:: ------------------------------------------------------------
:: 1. Sauvegarde complete (reste a l'imprimerie)
:: ------------------------------------------------------------
echo.
echo 1/2 Sauvegarde complete en cours, patientez...
"%MYSQLDUMP%" -u %DB_USER% --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 "%DB_NAME%" > "%COMPLETE%"
if errorlevel 1 goto ECHEC_COMPLETE
findstr /c:"-- Dump completed" "%COMPLETE%" >nul
if errorlevel 1 goto ECHEC_COMPLETE

:: ------------------------------------------------------------
:: 2. Structure seule (a envoyer) : --no-data, jamais de lignes
:: ------------------------------------------------------------
echo 2/2 Copie de la structure seule...
"%MYSQLDUMP%" -u %DB_USER% --no-data --routines --triggers --default-character-set=utf8mb4 "%DB_NAME%" > "%STRUCTURE%"
if errorlevel 1 goto ECHEC_STRUCTURE
findstr /c:"CREATE TABLE" "%STRUCTURE%" >nul
if errorlevel 1 goto ECHEC_STRUCTURE
:: Par securite : aucune ligne de donnees ne doit s'y trouver.
findstr /b /i /c:"INSERT INTO" "%STRUCTURE%" >nul
if not errorlevel 1 goto DONNEES_DANS_STRUCTURE

echo.
echo ============================================================
echo  TERMINE.
echo.
echo  Fichier A ENVOYER (structure seule, aucune donnee client) :
echo    %STRUCTURE%
echo.
echo  Sauvegarde complete, A GARDER ICI (ne jamais l'envoyer) :
echo    %COMPLETE%
echo ============================================================
echo.
explorer /select,"%STRUCTURE%"
pause
exit /b 0

:: ------------------------------------------------------------
:: Sous-programme : TROUVE=1 si la base DB_NAME a une table commandes
:: ------------------------------------------------------------
:COMPTER_COMMANDES
set "TROUVE=0"
"%MYSQL%" -u %DB_USER% -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '%DB_NAME%' AND table_name = 'commandes'" > "%LISTE%" 2>nul
for /f "usebackq delims=" %%t in ("%LISTE%") do set "TROUVE=%%t"
exit /b 0

:: ------------------------------------------------------------
:: Erreurs : rien n'est envoye, rien n'est modifie dans la base
:: ------------------------------------------------------------
:SANS_XAMPP
echo XAMPP introuvable (ni C:\xamppok ni C:\xampp). Rien n'a ete fait.
goto FIN_ERREUR

:SANS_MYSQL
echo MySQL ne repond pas. Demarrez MySQL dans le panneau XAMPP, puis relancez ce fichier.
goto FIN_ERREUR

:SANS_BASE
del "%LISTE%" 2>nul
echo Base de l'ERP introuvable. Relancez en donnant son nom, par exemple :
echo     avant_bascule.bat success_imprim
goto FIN_ERREUR

:ECHEC_COMPLETE
del "%COMPLETE%" 2>nul
echo ECHEC de la sauvegarde complete. Rien n'a ete envoye ni modifie.
goto FIN_ERREUR

:ECHEC_STRUCTURE
del "%STRUCTURE%" 2>nul
echo ECHEC de la copie de la structure. La sauvegarde complete est faite :
echo    %COMPLETE%
goto FIN_ERREUR

:DONNEES_DANS_STRUCTURE
del "%STRUCTURE%" 2>nul
echo Le fichier de structure contenait des donnees : il a ete supprime.
echo Ne rien envoyer et nous prevenir.
goto FIN_ERREUR

:FIN_ERREUR
echo.
pause
exit /b 1
