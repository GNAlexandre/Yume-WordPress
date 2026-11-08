#!/bin/sh
# Test de bout en bout du lecteur manga (doubles pages et zoom, lot A 2.8.0) sur le WordPress
# local : fabrique le chapitre MangaDex d'essai (tools/ci/manga-zoom.php), sert le site avec le
# serveur intégré de PHP (tools/localenv/router.php), lance tools/ci/manga-zoom.js (Playwright,
# Chromium ; images du proxy interceptées, aucune requête vers MangaDex), puis arrête le serveur.
#
#   tools/ci/manga-zoom.sh [--port 8101]
#
# Prérequis : . <dossier>/env.sh (YUME_WP_PATH, WP_CLI), YUME_ENV choisi, Node 18+ et Playwright
# (npm ci --prefix tools/ci, ou Playwright déjà installé). Met à jour home et siteurl de la base
# courante sur l'adresse servie (comme serve.sh). YUME_MANGA_CAPTURES=<dossier> : captures.
set -eu
RACINE=$(cd "$(dirname "$0")/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (. <dossier>/env.sh, voir tools/localenv/README.md)}"
: "${YUME_ENV:=default}"
export YUME_ENV YUME_WP_PATH

PORT=8101
while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT=${2:?valeur manquante pour --port}; shift 2 ;;
		*) echo "[manga-zoom] option inconnue : $1" >&2; exit 2 ;;
	esac
done
case "$PORT" in
	'' | *[!0-9]*) echo "[manga-zoom] port invalide : $PORT" >&2; exit 2 ;;
esac
ADRESSE="http://127.0.0.1:$PORT"
JOURNAL=${YUME_MANGA_JOURNAL:-${TMPDIR:-/tmp}/yume-manga-zoom-$PORT.log}

"$RACINE/tools/localenv/wp.sh" option update home "$ADRESSE" >/dev/null
"$RACINE/tools/localenv/wp.sh" option update siteurl "$ADRESSE" >/dev/null
URL=$("$RACINE/tools/localenv/wp.sh" eval-file "$RACINE/tools/ci/manga-zoom.php" | tail -n 1)
case "$URL" in
	http*) ;;
	*) echo "[manga-zoom] chapitre d'essai non créé : $URL" >&2; exit 2 ;;
esac

"${PHP:-php}" -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php" >"$JOURNAL" 2>&1 &
SERVEUR=$!
trap 'kill "$SERVEUR" 2>/dev/null || true' EXIT INT TERM

i=0
until curl -fs -o /dev/null "$URL"; do
	i=$((i + 1))
	if [ "$i" -ge 60 ] || ! kill -0 "$SERVEUR" 2>/dev/null; then
		echo "[manga-zoom] le site ne répond pas sur $URL (journal : $JOURNAL)" >&2
		cat "$JOURNAL" >&2 || true
		exit 2
	fi
	sleep 1
done
echo "[manga-zoom] $URL (base « $YUME_ENV », journal du serveur : $JOURNAL)"

node "$RACINE/tools/ci/manga-zoom.js" --url "$URL"
