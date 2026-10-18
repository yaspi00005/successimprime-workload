@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE "DEVIS : RELANCES"
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
:: Reexecuter ce script est sans risque : /f remplace la tache.
:: ============================================================

set SCRIPT_DEVIS=C:\xamppok\htdocs\successImprim\scripts\windows\devis_relances.bat

echo Installation de la tache "devis : relances" (chaque heure)...
schtasks /create ^
    /tn "SuccessImprim_DevisRelances" ^
    /tr "\"%SCRIPT_DEVIS%\"" ^
    /sc hourly ^
    /mo 1 ^
    /ru SYSTEM ^
    /f

echo.
schtasks /query /tn "SuccessImprim_DevisRelances"
echo.
echo Pour tester tout de suite :
echo   schtasks /run /tn "SuccessImprim_DevisRelances"
echo Journal : C:\xamppok\htdocs\successImprim\var\log\devis_relances.log
echo.
pause
