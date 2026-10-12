@echo off

:: ============================================================
:: INSTALLATION DE LA TACHE DE SYNCHRONISATION
::
:: A executer UNE SEULE FOIS, clic droit "Executer en tant
:: qu'administrateur". Reexecuter est sans risque (/f).
:: ============================================================

set SCRIPT_SYNCHRO=C:\xamppok\htdocs\successImprim\scripts\windows\synchro.bat

echo Installation de la tache "synchronisation chaque minute"...
schtasks /create ^
    /tn "SuccessImprim_Synchro" ^
    /tr "\"%SCRIPT_SYNCHRO%\"" ^
    /sc minute ^
    /mo 1 ^
    /ru SYSTEM ^
    /f

echo.
schtasks /query /tn "SuccessImprim_Synchro"
echo.
echo Pour tester tout de suite :
echo   schtasks /run /tn "SuccessImprim_Synchro"
echo.
pause
