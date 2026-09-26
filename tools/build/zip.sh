#!/bin/sh
# Construit les archives installables du plugin et du thème :
#
#   dist/yume-core.zip   dossier racine yume-core/ (sans tests/ ni fichiers de développement)
#   dist/yume.zip        dossier racine yume/
#   dist/SHA256SUMS      empreintes des deux archives
#
# Ce sont exactement les fichiers attachés aux releases GitHub (.github/workflows/release.yml),
# que le site télécharge pour se mettre à jour, et ceux à téléverser pour la première installation.
#
#   tools/build/zip.sh                            versions lues dans les en-têtes
#   tools/build/zip.sh --version-attendue 2.1.0   échoue si un en-tête porte une autre version
#                                                 (le « v » initial d'un tag est accepté : v2.1.0)
#   tools/build/zip.sh --sortie /chemin/dist      autre dossier de sortie (défaut : <dépôt>/dist)
#
# Contrôles : version du plugin identique dans l'en-tête et YUME_CORE_VERSION, format de
# version valide, php -l sur chaque fichier PHP copié, fichiers indispensables présents.
# Dépendances : sh, tar, find, php ; zip (sinon repli sur l'extension PHP zip).
set -eu

RACINE=$(cd "$(dirname "$0")/../.." && pwd)
PLUGIN_SRC="$RACINE/wp-content/plugins/yume-core"
THEME_SRC="$RACINE/wp-content/themes/yume"
SORTIE="$RACINE/dist"
ATTENDUE=""
PHP_BIN=${PHP:-php}

usage() {
	awk 'NR == 1 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

erreur() {
	echo "zip : $*" >&2
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
		--version-attendue)
			[ $# -ge 2 ] || erreur "valeur manquante pour --version-attendue"
			ATTENDUE=$2
			shift 2
			;;
		--version-attendue=*)
			ATTENDUE=${1#*=}
			shift
			;;
		--sortie)
			[ $# -ge 2 ] || erreur "valeur manquante pour --sortie"
			SORTIE=$2
			shift 2
			;;
		--sortie=*)
			SORTIE=${1#*=}
			shift
			;;
		-h | --help | --aide)
			usage
			exit 0
			;;
		*)
			erreur "option inconnue : $1 (voir --aide)"
			;;
	esac
done
ATTENDUE=${ATTENDUE#v}

[ -f "$PLUGIN_SRC/yume-core.php" ] || erreur "plugin introuvable : $PLUGIN_SRC/yume-core.php"
[ -f "$THEME_SRC/style.css" ] || erreur "thème introuvable : $THEME_SRC/style.css"

# Valeur d'un en-tête WordPress (« Version: 2.1.0 ») dans les 8 premiers Kio d'un fichier.
entete() {
	head -c 8192 "$2" | tr -d '\r' | sed -n "s|^[[:space:]/*#@]*$1:[[:space:]]*||p" | head -n 1 | sed 's/[[:space:]]*$//'
}

VERSION_PLUGIN=$(entete Version "$PLUGIN_SRC/yume-core.php")
VERSION_CONSTANTE=$(sed -n "s/.*define([[:space:]]*'YUME_CORE_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$PLUGIN_SRC/yume-core.php" | head -n 1)
VERSION_THEME=$(entete Version "$THEME_SRC/style.css")

MOTIF_VERSION='^[0-9]\{1,\}\.[0-9]\{1,\}\.[0-9]\{1,\}\(-[0-9A-Za-z.-]\{1,\}\)\{0,1\}$'
for couple in "plugin:$VERSION_PLUGIN" "thème:$VERSION_THEME"; do
	nom=${couple%%:*}
	valeur=${couple#*:}
	[ -n "$valeur" ] || erreur "en-tête « Version » absent ($nom)"
	echo "$valeur" | grep -q "$MOTIF_VERSION" || erreur "version invalide pour le $nom : « $valeur » (attendu X.Y.Z ou X.Y.Z-suffixe)"
done
[ "$VERSION_PLUGIN" = "$VERSION_CONSTANTE" ] ||
	erreur "yume-core.php : en-tête Version « $VERSION_PLUGIN » ≠ YUME_CORE_VERSION « $VERSION_CONSTANTE »"

if [ -n "$ATTENDUE" ]; then
	[ "$VERSION_PLUGIN" = "$ATTENDUE" ] || erreur "le plugin est en version « $VERSION_PLUGIN », « $ATTENDUE » attendue"
	[ "$VERSION_THEME" = "$ATTENDUE" ] || erreur "le thème est en version « $VERSION_THEME », « $ATTENDUE » attendue"
elif [ "$VERSION_PLUGIN" != "$VERSION_THEME" ]; then
	echo "zip : attention, versions différentes (plugin $VERSION_PLUGIN, thème $VERSION_THEME)." >&2
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT INT TERM

# Copie d'un dossier (liens symboliques résolus ; tar est disponible partout, rsync non), puis
# suppression des fichiers de développement : tests/, fichiers cachés, Markdown, configurations
# d'outils, dépendances de développement, journaux et bases locales.
copier() {
	mkdir -p "$2"
	(cd "$1" && tar -chf - .) | (cd "$2" && tar -xf -)
	rm -rf "$2/tests"
	find "$2" \( -name '.?*' -o -name node_modules -o -name '*.md' -o -name 'phpcs.xml*' \
		-o -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name composer.json -o -name composer.lock \
		-o -name package.json -o -name package-lock.json -o -name '*.map' -o -name '*.log' \
		-o -name '*.zip' -o -name '*.sqlite' -o -name Thumbs.db \) -prune -exec rm -rf {} +
}

copier "$PLUGIN_SRC" "$TMP/yume-core"
copier "$THEME_SRC" "$TMP/yume"

# Fichiers indispensables.
for requis in \
	yume-core/yume-core.php \
	yume-core/includes/blocks-support.php \
	yume-core/lib/plugin-update-checker/plugin-update-checker.php \
	yume-core/lib/plugin-update-checker/license.txt \
	yume/style.css \
	yume/theme.json; do
	[ -f "$TMP/$requis" ] || erreur "fichier indispensable absent de l'archive : $requis"
done
[ ! -e "$TMP/yume-core/tests" ] || erreur "le dossier tests/ ne doit pas être livré"

PHP="$PHP_BIN" "$RACINE/tools/build/lint.sh" "$TMP/yume-core" "$TMP/yume" || erreur "erreur de syntaxe PHP : archives non construites"

mkdir -p "$SORTIE"
SORTIE=$(cd "$SORTIE" && pwd)
rm -f "$SORTIE/yume-core.zip" "$SORTIE/yume.zip" "$SORTIE/SHA256SUMS"

archiver() {
	if command -v zip >/dev/null 2>&1; then
		(cd "$TMP" && zip -rqX "$SORTIE/$1.zip" "$1")
	else
		# shellcheck disable=SC2016 # code PHP entre apostrophes, volontairement non interprété.
		"$PHP_BIN" -r '
			$source = $argv[1]; $dossier = $argv[2]; $cible = $argv[3];
			if ( ! class_exists( "ZipArchive" ) ) { fwrite( STDERR, "ni zip ni extension PHP zip\n" ); exit( 1 ); }
			$zip = new ZipArchive();
			if ( true !== $zip->open( $cible, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { exit( 1 ); }
			$zip->addEmptyDir( $dossier );
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . "/" . $dossier, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				$rel = $dossier . "/" . substr( $f->getPathname(), strlen( $source . "/" . $dossier ) + 1 );
				$f->isDir() ? $zip->addEmptyDir( $rel ) : $zip->addFile( $f->getPathname(), $rel );
			}
			exit( $zip->close() ? 0 : 1 );
		' "$TMP" "$1" "$SORTIE/$1.zip"
	fi
}

archiver yume-core
archiver yume

# Contrôle : chaque archive s'ouvre et a un seul dossier racine du bon nom.
# shellcheck disable=SC2016 # code PHP entre apostrophes, volontairement non interprété.
"$PHP_BIN" -r '
	foreach ( array( "yume-core", "yume" ) as $nom ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $argv[1] . "/" . $nom . ".zip", ZipArchive::CHECKCONS ) ) { fwrite( STDERR, "archive illisible : $nom.zip\n" ); exit( 1 ); }
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entree = $zip->getNameIndex( $i );
			if ( 0 !== strpos( $entree, $nom . "/" ) || false !== strpos( $entree, "../" ) ) { fwrite( STDERR, "entrée inattendue dans $nom.zip : $entree\n" ); exit( 1 ); }
		}
		$zip->close();
	}
' "$SORTIE" || erreur "contrôle des archives en échec"

(
	cd "$SORTIE"
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum yume-core.zip yume.zip >SHA256SUMS
	else
		shasum -a 256 yume-core.zip yume.zip >SHA256SUMS
	fi
)

taille() {
	wc -c <"$1" | tr -d ' '
}
echo "zip : $SORTIE/yume-core.zip  version $VERSION_PLUGIN  ($(taille "$SORTIE/yume-core.zip") octets)"
echo "zip : $SORTIE/yume.zip       version $VERSION_THEME  ($(taille "$SORTIE/yume.zip") octets)"

# En CI, expose les versions aux étapes suivantes.
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "version_plugin=$VERSION_PLUGIN"
		echo "version_theme=$VERSION_THEME"
	} >>"$GITHUB_OUTPUT"
fi
