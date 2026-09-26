#!/bin/sh
# Contrôle de rendu sur le WordPress local (job « Rendu » de la CI, utilisable tel quel en local) :
# sert le site avec le serveur intégré de PHP (tools/localenv/router.php), lance tools/ci/rendu.js
# (Playwright, Chromium) sur les pages de la démo, puis arrête le serveur.
#
#   tools/ci/rendu.sh [--port 8090] [--sortie mesures.json] [--libelle "WP 6.6"]
#
# Prérequis : . <dossier>/env.sh (YUME_WP_PATH, WP_CLI), YUME_ENV choisi, démo installée
# (tools/localenv/wp.sh eval-file tools/playground/demo.php), Node 18+ et Playwright :
#   npm install --prefix tools/ci && (cd tools/ci && npx playwright install chromium)
# Met à jour home et siteurl de la base courante sur l'adresse servie (comme serve.sh).
set -eu
RACINE=$(cd "$(dirname "$0")/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (. <dossier>/env.sh, voir tools/localenv/README.md)}"
: "${YUME_ENV:=default}"
export YUME_ENV YUME_WP_PATH

PORT=8090
while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT=${2:?valeur manquante pour --port}; shift 2 ;;
		*) break ;;
	esac
done
case "$PORT" in
	'' | *[!0-9]*) echo "[rendu] port invalide : $PORT" >&2; exit 2 ;;
esac
ADRESSE="http://127.0.0.1:$PORT"
JOURNAL=${YUME_RENDU_JOURNAL:-${TMPDIR:-/tmp}/yume-rendu-$PORT.log}

"$RACINE/tools/localenv/wp.sh" option update home "$ADRESSE" >/dev/null
"$RACINE/tools/localenv/wp.sh" option update siteurl "$ADRESSE" >/dev/null

"${PHP:-php}" -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php" >"$JOURNAL" 2>&1 &
SERVEUR=$!
trap 'kill "$SERVEUR" 2>/dev/null || true' EXIT INT TERM

# Attend que le site réponde (première requête lente : compilation, SQLite).
i=0
until curl -fs -o /dev/null "$ADRESSE/"; do
	i=$((i + 1))
	if [ "$i" -ge 60 ] || ! kill -0 "$SERVEUR" 2>/dev/null; then
		echo "[rendu] le site ne répond pas sur $ADRESSE (journal : $JOURNAL)" >&2
		cat "$JOURNAL" >&2 || true
		exit 2
	fi
	sleep 1
done
echo "[rendu] $ADRESSE (base « $YUME_ENV », journal du serveur : $JOURNAL)"

statut=0
node "$RACINE/tools/ci/rendu.js" --base "$ADRESSE" "$@" || statut=$?
exit "$statut"
