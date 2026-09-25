#!/bin/sh
# Installe depuis zéro l'environnement local de développement de Yume Novel :
#
#   <dossier>/wp/                      cœur WordPress (YUME_WP_PATH)
#   <dossier>/wp/wp-content/db.php     drop-in SQLite (aucun serveur MySQL nécessaire)
#   <dossier>/wp/wp-config.php         une base SQLite par YUME_ENV, WP_DEBUG, journal, cron désactivé
#   <dossier>/databases/<YUME_ENV>/    bases SQLite (supprimer un dossier = repartir de zéro)
#   <dossier>/sqlite-database-integration/   clone de WordPress/sqlite-database-integration
#   <dossier>/bin/wp                   WP-CLI (téléchargé s'il n'est pas déjà installé)
#   <dossier>/env.sh                   à sourcer : exporte YUME_WP_PATH et WP_CLI
#   wp-content/plugins/yume-core et wp-content/themes/yume : liens symboliques vers ce dépôt
#
# Usage : tools/localenv/setup.sh [options]
#
#   --dossier DIR      dossier d'installation (défaut : $YUME_LOCALENV_DIR, sinon
#                      ~/.local/share/yume-localenv)
#   --source S         composer (défaut : paquet johnpbloch/wordpress-core de Packagist)
#                      ou wp-cli (« wp core download », fichiers de langue inclus)
#   --version V        version de WordPress (défaut : la dernière)
#   --langue L         langue du site (défaut : fr_FR ; en_US pour l'anglais)
#   --sqlite-ref R     branche ou tag de sqlite-database-integration (défaut : branche principale)
#   --bloquer-http     WP_HTTP_BLOCK_EXTERNAL : aucune requête HTTP sortante depuis WordPress
#   --sans-liens       ne crée pas les liens vers le plugin et le thème (pour tester les zips)
#   --env E            base à préparer tout de suite (défaut : $YUME_ENV, sinon « default »)
#   --forcer           réinstalle le cœur, SQLite et wp-config.php (bases et médias conservés)
#   -h, --aide         cette aide
#
# Prérequis : PHP 8.1+ avec pdo_sqlite, git, curl ; Composer pour --source composer.
# Ensuite : . <dossier>/env.sh && tools/localenv/test.sh   (voir tools/localenv/README.md)
set -eu

REPO=$(cd "$(dirname "$0")/../.." && pwd)
BASE=${YUME_LOCALENV_DIR:-${HOME:-/tmp}/.local/share/yume-localenv}
SOURCE=composer
VERSION=""
LANGUE=fr_FR
SQLITE_REF=""
BLOQUER_HTTP=0
LIENS=1
ENV_INITIAL=${YUME_ENV:-default}
FORCER=0
PHP_BIN=${PHP:-php}
SQLITE_DEPOT=https://github.com/WordPress/sqlite-database-integration.git
WPCLI_PHAR=https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

info() {
	printf '[localenv] %s\n' "$*"
}
attention() {
	printf '[localenv] attention : %s\n' "$*" >&2
}
erreur() {
	printf '[localenv] erreur : %s\n' "$*" >&2
	exit 1
}
usage() {
	awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}
valeur() {
	[ $# -ge 2 ] && [ -n "$2" ] || erreur "valeur manquante pour $1"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--dossier) valeur "$@"; BASE=$2; shift 2 ;;
		--dossier=*) BASE=${1#*=}; shift ;;
		--source) valeur "$@"; SOURCE=$2; shift 2 ;;
		--source=*) SOURCE=${1#*=}; shift ;;
		--version) valeur "$@"; VERSION=$2; shift 2 ;;
		--version=*) VERSION=${1#*=}; shift ;;
		--langue) valeur "$@"; LANGUE=$2; shift 2 ;;
		--langue=*) LANGUE=${1#*=}; shift ;;
		--sqlite-ref) valeur "$@"; SQLITE_REF=$2; shift 2 ;;
		--sqlite-ref=*) SQLITE_REF=${1#*=}; shift ;;
		--env) valeur "$@"; ENV_INITIAL=$2; shift 2 ;;
		--env=*) ENV_INITIAL=${1#*=}; shift ;;
		--bloquer-http) BLOQUER_HTTP=1; shift ;;
		--sans-liens) LIENS=0; shift ;;
		--forcer) FORCER=1; shift ;;
		-h | --help | --aide) usage; exit 0 ;;
		*) erreur "option inconnue : $1 (voir --aide)" ;;
	esac
done

case "$SOURCE" in
	composer | wp-cli) ;;
	*) erreur "--source doit valoir composer ou wp-cli" ;;
esac
echo "$LANGUE" | grep -Eq '^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$' || erreur "langue invalide : $LANGUE"
echo "$ENV_INITIAL" | grep -Eq '^[A-Za-z0-9_-]+$' || erreur "nom d'environnement invalide : $ENV_INITIAL"

# --- Prérequis -------------------------------------------------------------------------------

command -v "$PHP_BIN" >/dev/null 2>&1 || erreur "PHP introuvable ($PHP_BIN)"
"$PHP_BIN" -r 'exit( version_compare( PHP_VERSION, "8.1", ">=" ) ? 0 : 1 );' || erreur "PHP 8.1 ou plus est requis"
"$PHP_BIN" -r 'exit( extension_loaded( "pdo_sqlite" ) ? 0 : 1 );' || erreur "l'extension PHP pdo_sqlite est requise"
command -v git >/dev/null 2>&1 || erreur "git est requis"
if [ "$SOURCE" = composer ]; then
	command -v composer >/dev/null 2>&1 || erreur "Composer est requis pour --source composer (ou utiliser --source wp-cli)"
fi

mkdir -p "$BASE"
BASE=$(cd "$BASE" && pwd)
WP="$BASE/wp"
BIN="$BASE/bin"
DB="$BASE/databases"
SQLITE_SRC="$BASE/sqlite-database-integration"
mkdir -p "$BIN" "$DB"

# Chaîne PHP entre apostrophes (pour wp-config.php) et chaîne shell entre apostrophes (env.sh).
php_chaine() {
	printf "'%s'" "$(printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g")"
}
sh_chaine() {
	printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

# --- WP-CLI ----------------------------------------------------------------------------------

WPCLI=""
if [ -n "${WP_CLI:-}" ] && [ -x "$WP_CLI" ]; then
	WPCLI=$WP_CLI
elif [ -x "$BIN/wp" ]; then
	WPCLI="$BIN/wp"
elif command -v wp >/dev/null 2>&1; then
	WPCLI=$(command -v wp)
else
	info "téléchargement de WP-CLI…"
	if command -v curl >/dev/null 2>&1 && curl -fsSL --retry 2 -o "$BIN/wp-cli.phar.tmp" "$WPCLI_PHAR" &&
		"$PHP_BIN" "$BIN/wp-cli.phar.tmp" --allow-root --version >/dev/null 2>&1; then
		mv "$BIN/wp-cli.phar.tmp" "$BIN/wp-cli.phar"
		cat >"$BIN/wp" <<EOF
#!/bin/sh
exec $(sh_chaine "$PHP_BIN") -d memory_limit=512M $(sh_chaine "$BIN/wp-cli.phar") "\$@"
EOF
	elif command -v composer >/dev/null 2>&1; then
		rm -f "$BIN/wp-cli.phar.tmp"
		attention "phar indisponible, installation de WP-CLI par Composer (wp-cli/wp-cli-bundle)…"
		COMPOSER_ALLOW_SUPERUSER=1 composer create-project --no-interaction --quiet --no-dev \
			wp-cli/wp-cli-bundle "$BASE/wp-cli" || erreur "installation de WP-CLI impossible"
		cat >"$BIN/wp" <<EOF
#!/bin/sh
exec $(sh_chaine "$PHP_BIN") -d memory_limit=512M $(sh_chaine "$BASE/wp-cli/vendor/wp-cli/wp-cli/bin/wp") "\$@"
EOF
	else
		erreur "WP-CLI introuvable et impossible à télécharger (installer wp ou définir WP_CLI)"
	fi
	chmod +x "$BIN/wp"
	WPCLI="$BIN/wp"
fi
"$WPCLI" --allow-root --version >/dev/null 2>&1 || erreur "WP-CLI ne fonctionne pas : $WPCLI"
info "WP-CLI : $("$WPCLI" --allow-root --version) ($WPCLI)"

wpcli() {
	"$WPCLI" --allow-root --path="$WP" "$@"
}

# --- Cœur WordPress --------------------------------------------------------------------------

if [ "$FORCER" = 1 ] && [ -d "$WP" ]; then
	info "réinstallation du cœur (wp-content/uploads et debug.log conservés)…"
	CONSERVE="$BASE/.conserve.$$"
	mkdir -p "$CONSERVE"
	[ -d "$WP/wp-content/uploads" ] && mv "$WP/wp-content/uploads" "$CONSERVE/uploads"
	[ -f "$WP/wp-content/debug.log" ] && mv "$WP/wp-content/debug.log" "$CONSERVE/debug.log"
	rm -rf "$WP"
fi

if [ ! -f "$WP/wp-includes/version.php" ]; then
	if [ "$SOURCE" = composer ]; then
		info "téléchargement de WordPress ${VERSION:-(dernière version)} par Composer…"
		# Installation dans un dossier neuf (Composer l'exige), puis copie dans $WP.
		NOUVEAU="$BASE/.wordpress.$$"
		rm -rf "$NOUVEAU"
		COMPOSER_ALLOW_SUPERUSER=1 composer create-project --no-interaction --no-dev --no-scripts --quiet \
			johnpbloch/wordpress-core "$NOUVEAU" ${VERSION:+"$VERSION"} || erreur "téléchargement de WordPress impossible"
		mkdir -p "$WP"
		(cd "$NOUVEAU" && tar -cf - .) | (cd "$WP" && tar -xf -)
		rm -rf "$NOUVEAU"
	else
		info "téléchargement de WordPress ${VERSION:-(dernière version)} ($LANGUE) par WP-CLI…"
		mkdir -p "$WP"
		wpcli core download --locale="$LANGUE" ${VERSION:+"--version=$VERSION"} --force ||
			erreur "téléchargement de WordPress impossible"
	fi
fi
if [ -n "${CONSERVE:-}" ]; then
	[ -d "$CONSERVE/uploads" ] && mv "$CONSERVE/uploads" "$WP/wp-content/uploads"
	[ -f "$CONSERVE/debug.log" ] && mv "$CONSERVE/debug.log" "$WP/wp-content/debug.log"
	rmdir "$CONSERVE" 2>/dev/null || true
fi
mkdir -p "$WP/wp-content/plugins" "$WP/wp-content/themes" "$WP/wp-content/mu-plugins" "$WP/wp-content/uploads"
WP_VERSION=$(sed -n "s/^\$wp_version = '\([^']*\)';/\1/p" "$WP/wp-includes/version.php")
info "WordPress $WP_VERSION : $WP"

# --- Intégration SQLite ----------------------------------------------------------------------

if [ "$FORCER" = 1 ]; then
	rm -rf "$SQLITE_SRC"
fi
if [ ! -d "$SQLITE_SRC/.git" ]; then
	info "clonage de sqlite-database-integration${SQLITE_REF:+ ($SQLITE_REF)}…"
	rm -rf "$SQLITE_SRC"
	git clone --quiet --depth 1 ${SQLITE_REF:+--branch "$SQLITE_REF"} "$SQLITE_DEPOT" "$SQLITE_SRC" ||
		erreur "clonage de $SQLITE_DEPOT impossible"
fi

SQLITE_PLUGIN="$WP/wp-content/plugins/sqlite-database-integration"
rm -rf "$SQLITE_PLUGIN"
if [ -d "$SQLITE_SRC/packages/plugin-sqlite-database-integration" ]; then
	# Monodépôt : le plugin pointe vers le pilote par un lien symbolique
	# (wp-includes/database → ../../mysql-on-sqlite/src) ; cp -L le remplace par une vraie copie.
	cp -RL "$SQLITE_SRC/packages/plugin-sqlite-database-integration" "$SQLITE_PLUGIN"
else
	# Ancienne organisation : la racine du dépôt est le plugin.
	mkdir -p "$SQLITE_PLUGIN"
	(cd "$SQLITE_SRC" && tar -chf - --exclude=./.git .) | (cd "$SQLITE_PLUGIN" && tar -xf -)
fi
[ -f "$SQLITE_PLUGIN/wp-includes/sqlite/db.php" ] || erreur "intégration SQLite incomplète : wp-includes/sqlite/db.php absent"
[ -f "$SQLITE_PLUGIN/db.copy" ] || erreur "intégration SQLite incomplète : db.copy absent"
if [ -d "$SQLITE_SRC/packages/mysql-on-sqlite" ] && [ ! -f "$SQLITE_PLUGIN/wp-includes/database/load.php" ]; then
	erreur "intégration SQLite incomplète : wp-includes/database/load.php absent (lien non résolu)"
fi

# Drop-in db.php : chemin absolu de l'implémentation et nom du plugin.
chemin_sed=$(printf '%s' "$SQLITE_PLUGIN" | sed 's/[|&\\]/\\&/g')
sed -e "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$chemin_sed|g" \
	-e "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" \
	"$SQLITE_PLUGIN/db.copy" >"$WP/wp-content/db.php"
info "SQLite : $(sed -n "s/.*define( *'SQLITE_DB_DROPIN_VERSION', *'\([^']*\)'.*/\1/p" "$WP/wp-content/db.php" | head -n 1) ($SQLITE_SRC)"

# --- wp-config.php ---------------------------------------------------------------------------

if [ ! -f "$WP/wp-config.php" ] || [ "$FORCER" = 1 ]; then
	info "écriture de wp-config.php…"
	# shellcheck disable=SC2016 # code PHP entre apostrophes, volontairement non interprété.
	SELS=$("$PHP_BIN" -r '
		foreach ( array( "AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT", "WP_CACHE_KEY_SALT" ) as $c ) {
			printf( "define( %s, %s );\n", var_export( $c, true ), var_export( bin2hex( random_bytes( 32 ) ), true ) );
		}
	')
	if [ "$BLOQUER_HTTP" = 1 ]; then
		HTTP="define( 'WP_HTTP_BLOCK_EXTERNAL', true );"
	else
		HTTP="// define( 'WP_HTTP_BLOCK_EXTERNAL', true ); // --bloquer-http : aucune requête sortante."
	fi
	if [ "$LANGUE" = en_US ]; then
		LANGUE_PHP="// Site en anglais (--langue en_US)."
	else
		LANGUE_PHP="define( 'WPLANG', $(php_chaine "$LANGUE") ); // Langue des bases créées par tools/localenv/wp.sh."
	fi
	cat >"$WP/wp-config.php" <<EOF
<?php
/**
 * Configuration de l'environnement local Yume Novel, générée par tools/localenv/setup.sh.
 *
 * Base de données : SQLite (drop-in wp-content/db.php). Chaque valeur de la variable
 * d'environnement YUME_ENV a sa propre base dans $(printf '%s' "$DB")/<YUME_ENV>/ :
 * supprimer ce dossier suffit pour repartir de zéro.
 *
 * @package Yume\\Core
 */

// Réglages MySQL inutilisés par SQLite, mais attendus par WordPress.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Une base SQLite par YUME_ENV (développeur, agent, CI…).
define( 'DB_DIR', $(php_chaine "$DB") . '/' . ( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) getenv( 'YUME_ENV' ) ) ) ?: 'default' ) );
if ( ! is_dir( DB_DIR ) ) {
	mkdir( DB_DIR, 0777, true );
}

$SELS

\$table_prefix = 'wp_';

// Développement : erreurs journalisées dans wp-content/debug.log, jamais affichées.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'YUME_DEV', true );

// Tâches planifiées déclenchées à la main (wp cron event run …), pas de mise à jour automatique.
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
$HTTP
$LANGUE_PHP

// WP-CLI sans --url : hôte par défaut pour les fonctions qui lisent HTTP_HOST / SERVER_NAME.
if ( defined( 'WP_CLI' ) && WP_CLI && empty( \$_SERVER['HTTP_HOST'] ) ) {
	\$_SERVER['HTTP_HOST']   = 'localhost:8080';
	\$_SERVER['SERVER_NAME'] = 'localhost';
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
EOF
	"$PHP_BIN" -l "$WP/wp-config.php" >/dev/null || erreur "wp-config.php généré invalide"
else
	info "wp-config.php existant conservé (--forcer pour le régénérer)."
fi

# Extension « must-use » locale : quand le réseau est bloqué (--bloquer-http), les vérifications
# de mises à jour de WordPress.org reçoivent une réponse « rien à mettre à jour » au lieu d'échouer,
# ce qui évite les avertissements wp_version_check() / wp_update_plugins() dans debug.log.
cat >"$WP/wp-content/mu-plugins/yume-localenv.php" <<'EOF'
<?php
/**
 * Plugin Name: Yume — environnement local
 * Description: Générée par tools/localenv/setup.sh. Si WP_HTTP_BLOCK_EXTERNAL est actif, les vérifications de mises à jour de WordPress.org répondent « aucune mise à jour » sans requête réseau (le module updater de yume-core n'est pas concerné).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) {
			if ( false !== $pre || 'api.wordpress.org' !== wp_parse_url( (string) $url, PHP_URL_HOST ) ) {
				return $pre;
			}
			$reponses = array(
				'#/core/version-check/#'   => array(
					'offers'       => array(),
					'translations' => array(),
				),
				'#/plugins/update-check/#' => array(
					'plugins'      => array(),
					'translations' => array(),
					'no_update'    => array(),
				),
				'#/themes/update-check/#'  => array(
					'themes'       => array(),
					'translations' => array(),
					'no_update'    => array(),
				),
			);
			$chemin   = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
			foreach ( $reponses as $motif => $corps ) {
				if ( preg_match( $motif, $chemin ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( $corps ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
			}
			return $pre;
		},
		1,
		3
	);
}
EOF

# --- Plugin et thème du dépôt ----------------------------------------------------------------

lier() {
	cible=$1
	lien=$2
	if [ -L "$lien" ]; then
		rm -f "$lien"
	elif [ -e "$lien" ]; then
		attention "$lien existe déjà (installation par zip ?) : lien non créé."
		return 0
	fi
	ln -s "$cible" "$lien"
}
if [ "$LIENS" = 1 ]; then
	lier "$REPO/wp-content/plugins/yume-core" "$WP/wp-content/plugins/yume-core"
	lier "$REPO/wp-content/themes/yume" "$WP/wp-content/themes/yume"
	info "plugin et thème liés au dépôt : $REPO"
else
	for lien in "$WP/wp-content/plugins/yume-core" "$WP/wp-content/themes/yume"; do
		[ -L "$lien" ] && rm -f "$lien"
	done
	info "sans liens : installer dist/yume-core.zip et dist/yume.zip avec « wp plugin install » / « wp theme install »."
fi

# --- env.sh ----------------------------------------------------------------------------------

# shellcheck disable=SC2094 # env.sh est seulement écrit ; son chemin est cité dans le commentaire.
cat >"$BASE/env.sh" <<EOF
# Environnement local Yume Novel (généré par tools/localenv/setup.sh). À sourcer :
#   . $(sh_chaine "$BASE/env.sh")
export YUME_WP_PATH=$(sh_chaine "$WP")
export WP_CLI=$(sh_chaine "$WPCLI")
export YUME_ENV="\${YUME_ENV:-$ENV_INITIAL}"
EOF

# --- Première base ---------------------------------------------------------------------------

info "préparation de la base YUME_ENV=$ENV_INITIAL…"
YUME_WP_PATH=$WP WP_CLI=$WPCLI YUME_ENV=$ENV_INITIAL "$REPO/tools/localenv/wp.sh" core is-installed ||
	erreur "installation de WordPress impossible (voir $WP/wp-content/debug.log)"

wpenv() {
	YUME_WP_PATH=$WP WP_CLI=$WPCLI YUME_ENV=$ENV_INITIAL "$REPO/tools/localenv/wp.sh" "$@"
}
MOTEUR=$(wpenv eval 'echo defined( "DB_ENGINE" ) ? DB_ENGINE : "mysql";' 2>/dev/null || true)
[ "$MOTEUR" = sqlite ] || erreur "le drop-in SQLite n'est pas actif (moteur : ${MOTEUR:-inconnu})"

if [ "$LANGUE" != en_US ] && [ ! -f "$WP/wp-content/languages/$LANGUE.mo" ]; then
	if [ "$BLOQUER_HTTP" = 0 ] && wpenv language core install "$LANGUE" >/dev/null 2>&1; then
		info "fichiers de langue $LANGUE installés."
	else
		attention "fichiers de langue $LANGUE indisponibles (réseau ?) : l'interface de WordPress reste en anglais, les textes Yume sont en français."
	fi
fi

if [ "$LIENS" = 1 ]; then
	ETAT=$(wpenv plugin get yume-core --field=status 2>/dev/null || echo "?")
	[ "$ETAT" = active ] || attention "yume-core n'est pas actif (statut : $ETAT) : voir $WP/wp-content/debug.log"
fi

cat <<EOF

[localenv] Environnement prêt.

  . $(sh_chaine "$BASE/env.sh")
  tools/localenv/wp.sh plugin list             WP-CLI sur la base \$YUME_ENV
  tools/localenv/test.sh [module]              tests du plugin
  tools/localenv/serve.sh [port]               site sur http://127.0.0.1:8080 (admin / admin)
  rm -rf $(sh_chaine "$DB")/<YUME_ENV>        repartir d'une base vide

EOF
