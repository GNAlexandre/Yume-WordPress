#!/bin/sh
# WP-CLI sur le WordPress local de développement.
#
#   YUME_WP_PATH=/chemin/vers/wordpress  (cœur WordPress avec le drop-in SQLite et wp-config.php)
#   YUME_ENV=nom                         (base SQLite isolée : une par développeur ou par agent)
#   WP_CLI=/chemin/vers/wp               (optionnel, sinon « wp » du PATH)
#
#   tools/localenv/wp.sh plugin list
#
# À la première utilisation d'un YUME_ENV, WordPress est installé, le plugin yume-core activé
# et le thème yume activé s'il existe. Voir tools/localenv/README.md.
set -e
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (voir tools/localenv/README.md)}"
: "${YUME_ENV:=default}"
export YUME_ENV
WP_BIN="${WP_CLI:-wp}"

run() { "$WP_BIN" --path="$YUME_WP_PATH" --allow-root "$@"; }

if ! run core is-installed >/dev/null 2>&1; then
	run core install --url=http://localhost:8080 --title="Yume Novel (local)" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
	run rewrite structure '/%year%/%monthnum%/%day%/%postname%/' >/dev/null 2>&1 || true
	run plugin activate yume-core >/dev/null 2>&1 || echo "[localenv] activation de yume-core impossible" >&2
	if run theme is-installed yume >/dev/null 2>&1; then run theme activate yume >/dev/null 2>&1 || true; fi
fi

run "$@"
