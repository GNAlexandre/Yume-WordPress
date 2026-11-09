#!/bin/sh
# Parcours de bout en bout « mise en page » sur le WordPress local : contenu de démonstration et
# chapitre de référence (tools/ci/mise-en-page.php), site servi par le serveur intégré de PHP
# (tools/localenv/router.php), tools/ci/mise-en-page.js (Playwright, Chromium), puis arrêt du
# serveur.
#
#   tools/ci/mise-en-page.sh [--port 8111] [--captures DOSSIER]
#
# Prérequis : . <dossier>/env.sh (YUME_WP_PATH, WP_CLI), YUME_ENV choisi (défaut : mise-en-page,
# base distincte créée au besoin), Node 18+ et Playwright (require('playwright') ; jamais
# « playwright install » si Chromium est déjà là). Met à jour home et siteurl de la base courante.
set -eu
RACINE=$(cd "$(dirname "$0")/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (. <dossier>/env.sh, voir tools/localenv/README.md)}"
: "${YUME_ENV:=mise-en-page}"
export YUME_ENV YUME_WP_PATH

PORT=8111
while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT=${2:?valeur manquante pour --port}; shift 2 ;;
		*) break ;;
	esac
done
case "$PORT" in
	'' | *[!0-9]*) echo "[mise-en-page] port invalide : $PORT" >&2; exit 2 ;;
esac
ADRESSE="http://127.0.0.1:$PORT"
TRAVAIL=$(mktemp -d "${TMPDIR:-/tmp}/yume-mise-en-page-XXXXXX")
JOURNAL="$TRAVAIL/serveur.log"

"$RACINE/tools/localenv/wp.sh" option update home "$ADRESSE" >/dev/null
"$RACINE/tools/localenv/wp.sh" option update siteurl "$ADRESSE" >/dev/null
PREPARATION=$("$RACINE/tools/localenv/wp.sh" eval-file "$RACINE/tools/ci/mise-en-page.php" | tail -n 1)
lire() {
	printf '%s' "$PREPARATION" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>process.stdout.write(String(JSON.parse(s)[process.argv[1]])))' "$1"
}
VUE=$(lire vue)
LECTURE=$(lire lecture)

"${PHP:-php}" -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php" >"$JOURNAL" 2>&1 &
SERVEUR=$!
trap 'kill "$SERVEUR" 2>/dev/null || true; rm -rf "$TRAVAIL"' EXIT INT TERM

i=0
until curl -fs -o /dev/null "$ADRESSE/"; do
	i=$((i + 1))
	if [ "$i" -ge 60 ] || ! kill -0 "$SERVEUR" 2>/dev/null; then
		echo "[mise-en-page] le site ne répond pas sur $ADRESSE (journal : $JOURNAL)" >&2
		cat "$JOURNAL" >&2 || true
		exit 2
	fi
	sleep 1
done
echo "[mise-en-page] $ADRESSE (base « $YUME_ENV ») — vue : $VUE"

statut=0
node "$RACINE/tools/ci/mise-en-page.js" --base "$ADRESSE" --vue "$VUE" --lecture "$LECTURE" "$@" || statut=$?
if [ "$statut" -ne 0 ]; then
	tail -n 20 "$JOURNAL" >&2 || true
fi
exit "$statut"
