# ============================================================
# Installation de « succes-fichier » sur un poste Windows
# (docs/dossiers-clients.md). Pas besoin d'etre administrateur :
# le lien est enregistre pour l'utilisateur connecte.
#
#   Clic droit sur installer.ps1 > « Executer avec PowerShell »
#
# Demande : la cle des postes (Reglages > Dossiers clients,
# section « Postes Windows », visible par la Direction) et le
# dossier des clients vu de ce poste (ex. \\SERVEUR\Clients).
# ============================================================
$ErrorActionPreference = 'Stop'
$dossier = Join-Path $env:ProgramData 'SuccesFichier'
New-Item -ItemType Directory -Force -Path $dossier | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'succes-fichier.ps1') -Destination (Join-Path $dossier 'succes-fichier.ps1') -Force

$cle = Read-Host 'Cle des postes (64 caracteres, copiee depuis l''ERP)'
if ($cle -notmatch '^[0-9a-f]{64}$') { Write-Host 'Cle invalide : 64 caracteres 0-9 a-f.' -ForegroundColor Red; Read-Host 'Entree pour fermer'; exit 1 }
$racine = Read-Host 'Dossier des clients vu de ce poste (ex. \\SERVEUR\Clients)'
if (-not (Test-Path -LiteralPath $racine)) { Write-Host "Attention : $racine n'est pas accessible depuis ce poste pour l'instant." -ForegroundColor Yellow }
@{ cle = $cle; racine = $racine } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $dossier 'config.json') -Encoding UTF8

$commande = "powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$dossier\succes-fichier.ps1`" `"%1`""
$base = 'HKCU:\Software\Classes\succes-fichier'
New-Item -Path "$base\shell\open\command" -Force | Out-Null
Set-ItemProperty -Path $base -Name '(Default)' -Value 'URL:Succes Imprim fichiers'
Set-ItemProperty -Path $base -Name 'URL Protocol' -Value ''
Set-ItemProperty -Path "$base\shell\open\command" -Name '(Default)' -Value $commande

Write-Host 'Installe. Dans l''ERP, « Ouvrir avec Illustrator » ouvre maintenant le fichier sur ce poste.' -ForegroundColor Green
Write-Host 'La premiere fois, le navigateur demande d''autoriser « succes-fichier » : cochez « toujours autoriser ».'
Read-Host 'Entree pour fermer'
