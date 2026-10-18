@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "CORBEILLE" (suppression definitive de la nuit)
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_CORBEILLE=C:\xamppok\htdocs\successImprim\scripts\windows\corbeille.bat

echo Installation de la tache "corbeille" (tous les jours a 03:30, apres la sauvegarde)...
schtasks /create ^
    /tn "SuccessImprim_Corbeille" ^
    /tr "\"%SCRIPT_CORBEILLE%\"" ^
    /sc daily ^
    /st 03:30 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_Corbeille"

echo.
echo Pour tester tout de suite, sans attendre 03:30 :
echo   schtasks /run /tn "SuccessImprim_Corbeille"
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xamppok\htdocs\successImprim\var\log\corbeille.log
echo.
pause
