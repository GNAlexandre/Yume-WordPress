#!/bin/sh
# Parcours de bout en bout « sous-chapitres » sur le WordPress local : prépare une œuvre, un tome
# et un DOCX synthétique (tools/ci/sous-chapitres.php), sert le site avec le serveur intégré de PHP
# (tools/localenv/router.php), lance tools/ci/sous-chapitres.js (Playwright, Chromium), puis
# arrête le serveur.
#
#   tools/ci/sous-chapitres.sh [--port 8102] [--captures DOSSIER]
#
# Prérequis : . <dossier>/env.sh (YUME_WP_PATH, WP_CLI), YUME_ENV choisi (défaut : sous-chapitres,
# base distincte créée au besoin), Node 18+ et Playwright (require('playwright') ; jamais
# « playwright install » si Chromium est déjà là). Met à jour home et siteurl de la base courante.
set -eu
RACINE=$(cd "$(dirname "$0")/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (. <dossier>/env.sh, voir tools/localenv/README.md)}"
: "${YUME_ENV:=sous-chapitres}"
export YUME_ENV YUME_WP_PATH

PORT=8102
while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT=${2:?valeur manquante pour --port}; shift 2 ;;
		*) break ;;
	esac
done
case "$PORT" in
	'' | *[!0-9]*) echo "[sous-chapitres] port invalide : $PORT" >&2; exit 2 ;;
esac
ADRESSE="http://127.0.0.1:$PORT"
TRAVAIL=$(mktemp -d "${TMPDIR:-/tmp}/yume-sous-chapitres-XXXXXX")
JOURNAL="$TRAVAIL/serveur.log"
DOCX="$TRAVAIL/journal-de-guerre-t2.docx"

"$RACINE/tools/localenv/wp.sh" option update home "$ADRESSE" >/dev/null
"$RACINE/tools/localenv/wp.sh" option update siteurl "$ADRESSE" >/dev/null
PREPARATION=$(YUME_E2E_DOCX="$DOCX" "$RACINE/tools/localenv/wp.sh" eval-file "$RACINE/tools/ci/sous-chapitres.php" | tail -n 1)
PUBLIER=$(printf '%s' "$PREPARATION" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>process.stdout.write(JSON.parse(s).publier))')

"${PHP:-php}" -d memory_limit=512M -d upload_max_filesize=64M -d post_max_size=66M -S "127.0.0.1:$PORT" -t "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php" >"$JOURNAL" 2>&1 &
SERVEUR=$!
trap 'kill "$SERVEUR" 2>/dev/null || true; rm -rf "$TRAVAIL"' EXIT INT TERM

i=0
until curl -fs -o /dev/null "$ADRESSE/"; do
	i=$((i + 1))
	if [ "$i" -ge 60 ] || ! kill -0 "$SERVEUR" 2>/dev/null; then
		echo "[sous-chapitres] le site ne répond pas sur $ADRESSE (journal : $JOURNAL)" >&2
		cat "$JOURNAL" >&2 || true
		exit 2
	fi
	sleep 1
done
echo "[sous-chapitres] $ADRESSE (base « $YUME_ENV ») — formulaire : $PUBLIER"

statut=0
node "$RACINE/tools/ci/sous-chapitres.js" --base "$ADRESSE" --publier "$PUBLIER" --docx "$DOCX" "$@" || statut=$?
if [ "$statut" -ne 0 ]; then
	tail -n 20 "$JOURNAL" >&2 || true
fi
exit "$statut"
