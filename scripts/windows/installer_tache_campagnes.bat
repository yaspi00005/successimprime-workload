@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "CAMPAGNES"
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_CAMPAGNES=C:\xampp\htdocs\successImprim\scripts\windows\campagnes.bat

echo Installation de la tache "campagnes" (toutes les 10 minutes)...
schtasks /create ^
    /tn "SuccessImprim_Campagnes" ^
    /tr "\"%SCRIPT_CAMPAGNES%\"" ^
    /sc minute ^
    /mo 10 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_Campagnes"

echo.
echo Pour tester tout de suite, sans attendre 10 minutes :
echo   schtasks /run /tn "SuccessImprim_Campagnes"
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xampp\htdocs\successImprim\var\log\campagnes.log
echo.
pause
