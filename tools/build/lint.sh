#!/bin/sh
# Vérifie la syntaxe PHP (php -l) du plugin (bibliothèque vendorisée comprise), du thème et des
# outils. Échoue au premier fichier en erreur, et aussi sur toute dépréciation signalée à la
# compilation (utile pour vérifier une nouvelle version de PHP).
#
#   tools/build/lint.sh                    tout le dépôt
#   tools/build/lint.sh chemin [chemin…]   seulement ces dossiers ou fichiers
#   PHP=php8.1 tools/build/lint.sh         avec un autre binaire PHP
#
# Utilisé par tools/build/zip.sh et par la CI (.github/workflows/ci.yml).
set -eu

RACINE=$(cd "$(dirname "$0")/../.." && pwd)
PHP_BIN=${PHP:-php}

if [ $# -eq 0 ]; then
	set -- "$RACINE/wp-content/plugins/yume-core" "$RACINE/wp-content/themes/yume" "$RACINE/tools"
fi

liste=$(mktemp)
sortie=$(mktemp)
trap 'rm -f "$liste" "$sortie"' EXIT INT TERM

# Dépendances Composer des outils et node_modules exclues ; lib/…/vendor (Parsedown) incluse.
for chemin in "$@"; do
	if [ -f "$chemin" ]; then
		printf '%s\n' "$chemin" >>"$liste"
	elif [ -d "$chemin" ]; then
		find "$chemin" \( -name node_modules -o -name .git -o \( -name vendor -a ! -path '*/lib/*' \) \) -prune \
			-o -type f -name '*.php' -print >>"$liste"
	else
		echo "lint : chemin introuvable : $chemin" >&2
		exit 2
	fi
done

total=$(wc -l <"$liste" | tr -d ' ')
if [ "$total" -eq 0 ]; then
	echo "lint : aucun fichier PHP trouvé."
	exit 0
fi

version=$("$PHP_BIN" -r 'echo PHP_VERSION;')
echo "lint : $total fichier(s) PHP avec PHP $version…"

# Un fichier par appel : « php -l » ne lit qu'un fichier avant PHP 8.3.
tr '\n' '\0' <"$liste" | xargs -0 -n 1 -P 4 "$PHP_BIN" -d error_reporting=-1 -d display_errors=1 -d log_errors=0 -l >"$sortie" 2>&1 || true

if grep -v '^No syntax errors detected in ' "$sortie" | grep -q '[^[:space:]]'; then
	grep -v '^No syntax errors detected in ' "$sortie" | grep '[^[:space:]]' >&2
	echo "lint : ÉCHEC (voir ci-dessus)." >&2
	exit 1
fi

ok=$(grep -c '^No syntax errors detected in ' "$sortie" || true)
if [ "$ok" -ne "$total" ]; then
	echo "lint : ÉCHEC, $ok fichier(s) vérifié(s) sur $total." >&2
	exit 1
fi
echo "lint : OK."
