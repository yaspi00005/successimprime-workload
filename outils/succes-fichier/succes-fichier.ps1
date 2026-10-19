# ============================================================
# succes-fichier : petit programme du poste Windows
#
# Recoit les liens « succes-fichier://ouvrir?chemin=...&exp=...&sig=... »
# des boutons « Ouvrir avec Illustrator » et « Afficher dans le dossier »
# de l'ERP (docs/dossiers-clients.md), et :
#   - verifie la signature HMAC-SHA256 avec la cle des postes ;
#   - refuse un lien perime (10 minutes) ;
#   - refuse un chemin hors du dossier des clients (racine) ;
#   - refuse d'ouvrir un programme (.exe, .bat, .ps1, .lnk...) ;
#   - ouvrir   : ouvre le fichier avec son logiciel (Illustrator...) ;
#     afficher : ouvre l'Explorateur sur le fichier selectionne.
#
# Il ne supprime, ne deplace et ne modifie aucun fichier.
# Configuration : C:\ProgramData\SuccesFichier\config.json
#   { "cle": "<cle des postes>", "racine": "\\\\SERVEUR\\Clients" }
# ============================================================
param([Parameter(Position = 0)][string]$Lien = '')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms | Out-Null

function Refuser([string]$raison) {
    [System.Windows.Forms.MessageBox]::Show("Lien refuse : $raison", 'Succes Imprim - fichiers', 'OK', 'Warning') | Out-Null
    exit 1
}

$config = Join-Path $env:ProgramData 'SuccesFichier\config.json'
if (-not (Test-Path -LiteralPath $config)) { Refuser "configuration absente ($config). Relancez installer.ps1." }
$c = Get-Content -LiteralPath $config -Raw -Encoding UTF8 | ConvertFrom-Json
if (-not $c.cle -or -not $c.racine) { Refuser 'configuration incomplete (cle ou racine).' }

# succes-fichier://ouvrir?... (certains navigateurs ajoutent « / » apres l'action)
if ($Lien -notmatch '^succes-fichier:(//)?(ouvrir|afficher)/?\?(.+)$') { Refuser 'adresse inconnue.' }
$action = $Matches[2]
$parametres = @{}
foreach ($morceau in $Matches[3].Split('&')) {
    $kv = $morceau.Split('=', 2)
    if ($kv.Count -eq 2) { $parametres[$kv[0]] = [System.Uri]::UnescapeDataString($kv[1].Replace('+', '%20')) }
}
$chemin = [string]$parametres['chemin']
$exp = 0
if (-not [long]::TryParse([string]$parametres['exp'], [ref]$exp)) { Refuser 'date limite illisible.' }
$sig = [string]$parametres['sig']
if (-not $chemin -or -not $sig) { Refuser 'lien incomplet.' }

# Date limite : 10 minutes (plus une minute d'ecart d'horloge).
$maintenant = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
if ($exp -lt $maintenant -or $exp -gt ($maintenant + 660)) { Refuser 'lien perime : recliquez sur le bouton dans l''ERP.' }

# Signature : HMAC-SHA256 de « action\nchemin\nexp » (comme LienProtocole.php).
$hmac = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key = [System.Text.Encoding]::UTF8.GetBytes([string]$c.cle)
$attendu = -join ($hmac.ComputeHash([System.Text.Encoding]::UTF8.GetBytes("$action`n$chemin`n$exp")) | ForEach-Object { $_.ToString('x2') })
$egal = $attendu.Length -eq $sig.Length
for ($i = 0; $i -lt [Math]::Min($attendu.Length, $sig.Length); $i++) { if ($attendu[$i] -ne $sig[$i]) { $egal = $false } }
if (-not $egal) { Refuser 'signature invalide (cle des postes differente de celle de l''ERP ?).' }

# Le chemin doit rester dans le dossier des clients (« .. » ramenes).
try {
    $complet = [System.IO.Path]::GetFullPath($chemin)
    $racine = [System.IO.Path]::GetFullPath([string]$c.racine).TrimEnd('\')
} catch { Refuser 'chemin illisible.' }
if (-not $complet.StartsWith($racine + '\', [System.StringComparison]::OrdinalIgnoreCase)) { Refuser "le fichier n'est pas dans $racine." }
if (-not (Test-Path -LiteralPath $complet)) { Refuser "introuvable : $complet" }

$programmes = '.exe', '.com', '.bat', '.cmd', '.ps1', '.psm1', '.vbs', '.vbe', '.js', '.jse', '.wsf', '.wsh', '.msi', '.msp', '.scr', '.hta', '.lnk', '.url', '.reg', '.cpl', '.jar', '.pif', '.appref-ms', '.application', '.dll', '.sys'
$extension = [System.IO.Path]::GetExtension($complet).ToLowerInvariant()

if ($action -eq 'afficher') {
    if (Test-Path -LiteralPath $complet -PathType Container) {
        Start-Process -FilePath 'explorer.exe' -ArgumentList "`"$complet`""
    } else {
        Start-Process -FilePath 'explorer.exe' -ArgumentList "/select,`"$complet`""
    }
    exit 0
}
if ((Test-Path -LiteralPath $complet -PathType Leaf) -and ($programmes -contains $extension)) { Refuser "ce fichier est un programme ($extension) : il n'est jamais ouvert depuis l'ERP." }
Start-Process -FilePath $complet
exit 0
