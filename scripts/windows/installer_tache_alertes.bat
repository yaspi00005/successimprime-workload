@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "ALERTES SYSTEME" (toutes les 5 minutes)
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_ALERTES=C:\xamppok\htdocs\successImprim\scripts\windows\alertes.bat

echo Installation de la tache "alertes systeme" (toutes les 5 minutes)...
schtasks /create ^
    /tn "SuccessImprim_Alertes" ^
    /tr "\"%SCRIPT_ALERTES%\"" ^
    /sc minute ^
    /mo 5 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_Alertes"

echo.
echo Pour tester tout de suite :
echo   schtasks /run /tn "SuccessImprim_Alertes"
echo.
echo Les erreurs eventuelles sont journalisees dans :
echo   C:\xamppok\htdocs\successImprim\var\log\alertes.log
echo.
pause
