@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "RAPPORTS PAR E-MAIL" (docs/rapports-mail.md)
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Reexecuter ce script est sans risque : /f remplace la tache
:: existante du meme nom au lieu d'en creer un doublon.
:: ============================================================

set SCRIPT_RAPPORTS=C:\xamppok\htdocs\successImprim\scripts\windows\rapports_mail.bat

echo Installation de la tache "rapports par e-mail" (toutes les 15 minutes)...
echo (l'application decide : rapport du jour, de la semaine, du mois, a leur heure, une seule fois)
schtasks /create ^
    /tn "SuccessImprim_RapportsMail" ^
    /tr "\"%SCRIPT_RAPPORTS%\"" ^
    /sc minute ^
    /mo 15 ^
    /ru SYSTEM ^
    /f

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_RapportsMail"

echo.
echo Pour voir ce qui partirait, sans rien envoyer :
echo   C:\xamppok\php\php.exe C:\xamppok\htdocs\successImprim\bin\console app:rapports-mail --dry-run
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xamppok\htdocs\successImprim\var\log\rapports_mail.log
echo.
pause
