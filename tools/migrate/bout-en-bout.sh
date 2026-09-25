#!/bin/sh
# Migration de bout en bout sur une base LOCALE (jamais sur le site réel) :
#
#   1. peuplement de l'ancien site depuis l'export (seed-local.php) et instantané de la base ;
#   2. simulation puis exécution (wp yume migrer) ;
#   3. contrôles : comptes (15 œuvres, 55 tomes, 81 chapitres…), URL /oeuvres/… et /lire/…,
#      pages publiques en HTTP 200, 20 anciennes URL en 301 vers leur nouvelle adresse ;
#   4. annulation (wp yume migrer --annuler) et comparaison avec l'instantané : la base doit
#      revenir exactement à son état d'origine, les anciennes URL répondre de nouveau 200.
#
#   YUME_WP_PATH=… YUME_ENV=… tools/migrate/bout-en-bout.sh [port] [dossier-export]
#
# Variables : WP_CLI (binaire WP-CLI), YUME_MEDIAS (dossier des médias de cette base, relatif
# à ABSPATH, ex. wp-content/uploads-migration2), GARDER=1 (ne pas annuler à la fin, pour
# relire le site migré dans le navigateur).
#
# Le serveur PHP intégré est démarré sur le port indiqué (8089 par défaut) s'il ne répond pas
# déjà, et les options home et siteurl de la base sont pointées vers lui.
set -eu

DIR=$(cd "$(dirname "$0")" && pwd)
RACINE=$(cd "$DIR/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (voir tools/localenv/README.md)}"
: "${YUME_ENV:?Définir YUME_ENV (base SQLite locale dédiée)}"
export YUME_WP_PATH YUME_ENV
PORT=${1:-8089}
EXPORT=${2:-$DIR/export}
ADRESSE="http://127.0.0.1:$PORT"
TRAVAIL=$(mktemp -d "${TMPDIR:-/tmp}/yume-bout-en-bout.XXXXXX")
SERVEUR=""

wp_strict() {
	if ! "$RACINE/tools/localenv/wp.sh" "$@" > "$TRAVAIL/sortie.txt" 2>&1; then
		grep -v -e '^Warning: wp_update_' "$TRAVAIL/sortie.txt" >&2
		echo "ÉCHEC : wp $*" >&2
		exit 1
	fi
	grep -v -e '^Warning: wp_update_' "$TRAVAIL/sortie.txt" || true
}

nettoyer() {
	if [ -n "$SERVEUR" ]; then kill "$SERVEUR" 2>/dev/null || true; fi
	rm -rf "$TRAVAIL"
}
trap nettoyer EXIT INT TERM

echo "== 1. Peuplement de la base locale « $YUME_ENV » depuis $EXPORT"
if [ -n "${YUME_MEDIAS:-}" ]; then
	wp_strict eval-file "$DIR/seed-local.php" "$EXPORT" "medias=$YUME_MEDIAS"
else
	wp_strict eval-file "$DIR/seed-local.php" "$EXPORT"
fi
wp_strict option update home "$ADRESSE" > /dev/null
wp_strict option update siteurl "$ADRESSE" > /dev/null
wp_strict rewrite flush > /dev/null
wp_strict eval-file "$DIR/verifier-local.php" instantane "$TRAVAIL/avant.json"

if ! curl -s -o /dev/null --max-time 5 "$ADRESSE/wp-login.php"; then
	echo "   serveur PHP sur $ADRESSE"
	php -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php" > "$TRAVAIL/serveur.log" 2>&1 &
	SERVEUR=$!
	i=0
	until curl -s -o /dev/null --max-time 2 "$ADRESSE/wp-login.php"; do
		i=$((i + 1)); [ "$i" -gt 50 ] && { echo "ÉCHEC : serveur injoignable" >&2; exit 1; }
		sleep 0.2
	done
fi

code() { curl -s -o /dev/null --max-time 30 -w '%{http_code}' "$1"; }
attendre_code() {
	c=$(code "$1")
	if [ "$c" != "$2" ]; then echo "ÉCHEC : $1 → HTTP $c (attendu $2)" >&2; exit 1; fi
	echo "  ok  $2 $1"
}

echo "== Avant migration : anciennes pages en ligne"
attendre_code "$ADRESSE/grimgar-of-fantasy-and-ash-ln/" 200
attendre_code "$ADRESSE/secrets-of-the-silent-witch-t-1-chapitre-1/" 200
attendre_code "$ADRESSE/oeuvres/grimgar-of-fantasy-and-ash/" 404

echo "== 2. Simulation et exécution"
wp_strict yume migrer --simuler | tail -n 4
wp_strict yume migrer --yes | tail -n 9

echo "== 3. Contrôles après exécution"
wp_strict eval-file "$DIR/verifier-local.php" execution
for url in /oeuvres/grimgar-of-fantasy-and-ash/ /oeuvres/grimgar-of-fantasy-and-ash/tome-9/ \
	/oeuvres/secrets-of-the-silent-witch/ /lire/secrets-of-the-silent-witch/arc-1/1/ \
	/lire/secrets-of-the-silent-witch/arc-7/8/ /bibliotheque/ /planning/ /mentions-legales/ /actualites/ /; do
	attendre_code "$ADRESSE$url" 200
done
wp_strict eval-file "$DIR/verifier-local.php" urls 20 "$TRAVAIL/urls.tsv"
n=0
while IFS="$(printf '\t')" read -r source cible; do
	[ -n "$source" ] || continue
	resultat=$(curl -s -o /dev/null --max-time 30 -w '%{http_code} %{redirect_url}' "$ADRESSE$source")
	if [ "$resultat" != "301 $cible" ]; then
		echo "ÉCHEC : $source → $resultat (attendu 301 $cible)" >&2
		exit 1
	fi
	echo "  ok  301 $source → $cible"
	n=$((n + 1))
done < "$TRAVAIL/urls.tsv"
[ "$n" -ge 20 ] || { echo "ÉCHEC : $n redirections testées (20 attendues)" >&2; exit 1; }

if [ "${GARDER:-0}" = "1" ]; then
	echo "== Site migré conservé (GARDER=1) : $ADRESSE"
	exit 0
fi

echo "== 4. Annulation et retour à l'état d'origine"
wp_strict yume migrer --annuler --yes | tail -n 3
wp_strict eval-file "$DIR/verifier-local.php" comparer "$TRAVAIL/avant.json"
attendre_code "$ADRESSE/grimgar-of-fantasy-and-ash-ln/" 200
attendre_code "$ADRESSE/secrets-of-the-silent-witch-t-1-chapitre-1/" 200
attendre_code "$ADRESSE/oeuvres/grimgar-of-fantasy-and-ash/" 404
echo "== Bout en bout réussi."
