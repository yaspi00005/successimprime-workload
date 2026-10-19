@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE PLANIFIEE
:: "DOSSIERS CLIENTS" (analyse toutes les 5 minutes)
::
:: A executer UNE SEULE FOIS, avec un clic droit
:: "Executer en tant qu'administrateur".
::
:: Si le dossier des clients est sur un PARTAGE RESEAU
:: (\\SERVEUR\Clients), la tache doit tourner avec un compte
:: Windows qui a acces au partage : le compte SYSTEM ne voit pas
:: les partages. Le script demande alors ce compte et son mot
:: de passe. Pour un disque local (D:\Clients), SYSTEM suffit.
::
:: Reexecuter ce script est sans risque : /f remplace la tache.
:: ============================================================

set SCRIPT=C:\xamppok\htdocs\successImprim\scripts\windows\dossiers_clients.bat

echo Le dossier des clients est-il sur un partage reseau (\\SERVEUR\...) ?
set /p RESEAU=Tapez O pour oui, N pour non :

if /i "%RESEAU%"=="O" (
    set /p COMPTE=Compte Windows qui voit le partage ^(ex. SERVEUR\erp-lecture^) :
    schtasks /create /tn "SuccessImprim_DossiersClients" /tr "\"%SCRIPT%\"" /sc minute /mo 5 /ru "%COMPTE%" /rp * /f
) else (
    schtasks /create /tn "SuccessImprim_DossiersClients" /tr "\"%SCRIPT%\"" /sc minute /mo 5 /ru SYSTEM /f
)

echo.
echo Termine. Verification :
schtasks /query /tn "SuccessImprim_DossiersClients"

echo.
echo Pour tester tout de suite :
echo   schtasks /run /tn "SuccessImprim_DossiersClients"
echo.
echo Le resultat de chaque execution est journalise dans :
echo   C:\xamppok\htdocs\successImprim\var\log\dossiers_clients.log
echo.
pause
