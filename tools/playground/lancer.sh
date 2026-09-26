#!/bin/sh
# Lance Yume Novel en local avec WordPress Playground, à partir de la copie locale du dépôt :
# le plugin et le thème du clone sont montés tels quels (une modification est visible au
# rechargement de la page), avec un contenu de démonstration.
#
#   tools/playground/lancer.sh            # http://127.0.0.1:9400
#   tools/playground/lancer.sh 9500       # autre port
#
# Prérequis : Node.js 22 ou plus (npx). Aucun PHP, MySQL ni Docker n'est nécessaire.
# Le site est réinitialisé à chaque lancement. Arrêt : Ctrl+C.
set -e
RACINE="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${1:-9400}"
exec npx -y @wp-playground/cli@latest server \
	--port="$PORT" \
	--login \
	--mount-dir "$RACINE/wp-content/plugins/yume-core" /wordpress/wp-content/plugins/yume-core \
	--mount-dir "$RACINE/wp-content/themes/yume" /wordpress/wp-content/themes/yume \
	--blueprint="$RACINE/tools/playground/blueprint-local.json"
