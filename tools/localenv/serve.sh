#!/bin/sh
# Sert le WordPress local avec le serveur intégré de PHP (pas d'Apache ni de Nginx).
#
#   tools/localenv/serve.sh            http://127.0.0.1:8080, base $YUME_ENV
#   tools/localenv/serve.sh 8083       autre port
#   YUME_HOTE=0.0.0.0 tools/localenv/serve.sh    accessible depuis le réseau local
#
# Met à jour les options home et siteurl de la base courante sur l'adresse servie.
# Identifiants créés par tools/localenv/wp.sh : admin / admin.
set -eu
DIR=$(cd "$(dirname "$0")" && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (. <dossier>/env.sh, voir tools/localenv/README.md)}"
: "${YUME_ENV:=default}"
export YUME_ENV YUME_WP_PATH

PORT=${1:-8080}
HOTE=${YUME_HOTE:-127.0.0.1}
case "$PORT" in
	'' | *[!0-9]*) echo "Port invalide : $PORT" >&2; exit 2 ;;
esac
ADRESSE="http://127.0.0.1:$PORT"
[ "$HOTE" = 127.0.0.1 ] || ADRESSE="http://$HOTE:$PORT"

"$DIR/wp.sh" option update home "$ADRESSE" >/dev/null
"$DIR/wp.sh" option update siteurl "$ADRESSE" >/dev/null
echo "[localenv] $ADRESSE (base « $YUME_ENV », admin / admin) — Ctrl+C pour arrêter."
exec "${PHP:-php}" -d memory_limit=512M -S "$HOTE:$PORT" -t "$YUME_WP_PATH" "$DIR/router.php"
