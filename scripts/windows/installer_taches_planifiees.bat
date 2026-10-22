@echo off

:: ============================================================
:: INSTALLATION DES TACHES PLANIFIEES DE SAUVEGARDE
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur" (necessaire pour creer
:: des taches qui s'executent meme sans utilisateur connecte).
::
:: Reexecuter ce script est sans risque : /f remplace les taches
:: existantes du meme nom au lieu d'en creer des doublons.
:: ============================================================

set SCRIPT_SAUVEGARDE=C:\xamppok\htdocs\successImprim\scripts\windows\backup_db.bat

echo Installation de la tache "sauvegarde au demarrage"...
schtasks /create ^
    /tn "SuccessImprim_Sauvegarde_Demarrage" ^
    /tr "\"%SCRIPT_SAUVEGARDE%\"" ^
    /sc onstart ^
    /ru SYSTEM ^
    /f

echo.
echo Installation de la tache "sauvegarde chaque heure"...
echo (l'application decide : complete une fois par jour, base toutes les N heures, ou rien)
schtasks /delete /tn "SuccessImprim_Sauvegarde_6h" /f >nul 2>&1
schtasks /create ^
    /tn "SuccessImprim_Sauvegarde" ^
    /tr "\"%SCRIPT_SAUVEGARDE%\"" ^
    /sc hourly ^
    /mo 1 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_Sauvegarde_Demarrage"
schtasks /query /tn "SuccessImprim_Sauvegarde"

echo.
echo Pour tester une sauvegarde immediatement :
echo   schtasks /run /tn "SuccessImprim_Sauvegarde"
echo.
pause
