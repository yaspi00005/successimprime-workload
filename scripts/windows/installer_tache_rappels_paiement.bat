@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "RAPPELS DE PAIEMENT"
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_RAPPELS=C:\xampp\htdocs\successImprim\scripts\windows\rappels_paiement.bat

echo Installation de la tache "rappels de paiement" (tous les jours a 07:00)...
schtasks /create ^
    /tn "SuccessImprim_RappelsPaiement" ^
    /tr "\"%SCRIPT_RAPPELS%\"" ^
    /sc daily ^
    /st 07:00 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_RappelsPaiement"

echo.
echo Pour tester tout de suite, sans attendre demain 07:00 :
echo   schtasks /run /tn "SuccessImprim_RappelsPaiement"
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xampp\htdocs\successImprim\var\log\rappels_paiement.log
echo.
pause
