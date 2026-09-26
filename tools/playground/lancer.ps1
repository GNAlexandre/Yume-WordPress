# Lance Yume Novel en local avec WordPress Playground (Windows, PowerShell), à partir de la
# copie locale du dépôt : le plugin et le thème du clone sont montés tels quels, avec un
# contenu de démonstration.
#
#   powershell -ExecutionPolicy Bypass -File tools\playground\lancer.ps1          # http://127.0.0.1:9400
#   powershell -ExecutionPolicy Bypass -File tools\playground\lancer.ps1 9500     # autre port
#
# Prérequis : Node.js 22 ou plus (npx). Aucun PHP, MySQL ni Docker n'est nécessaire.
# Le site est réinitialisé à chaque lancement. Arrêt : Ctrl+C.
# --mount-dir (chemin hôte et chemin virtuel séparés) : « C:\… » contient déjà un deux-points.
param([int]$Port = 9400)
$ErrorActionPreference = 'Stop'
$Racine = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
npx -y '@wp-playground/cli@latest' server `
	"--port=$Port" `
	--login `
	--mount-dir "$Racine\wp-content\plugins\yume-core" '/wordpress/wp-content/plugins/yume-core' `
	--mount-dir "$Racine\wp-content\themes\yume" '/wordpress/wp-content/themes/yume' `
	"--blueprint=$Racine\tools\playground\blueprint-local.json"
