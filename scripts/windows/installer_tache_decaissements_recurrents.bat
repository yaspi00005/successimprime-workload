@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "DECAISSEMENTS AUTOMATIQUES"
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_DECAISSEMENTS=C:\xamppok\htdocs\successImprim\scripts\windows\decaissements_recurrents.bat

echo Installation de la tache "decaissements automatiques" (tous les jours a 06:00)...
schtasks /create ^
    /tn "SuccessImprim_DecaissementsRecurrents" ^
    /tr "\"%SCRIPT_DECAISSEMENTS%\"" ^
    /sc daily ^
    /st 06:00 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_DecaissementsRecurrents"

echo.
echo Pour tester tout de suite, sans attendre demain 06:00 :
echo   schtasks /run /tn "SuccessImprim_DecaissementsRecurrents"
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xamppok\htdocs\successImprim\var\log\decaissements_recurrents.log
echo.
pause
