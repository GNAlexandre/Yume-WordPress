#!/bin/sh
# Construit la PRÉPRODUCTION locale de Yume Novel, de zéro, comme au jour J (voir README.md) :
#
#   1. base neuve (MariaDB par défaut, comme la production ; SQLite possible) ;
#   2. ancien site peuplé depuis l'export (tools/migrate/seed-local.php, mêmes ID) ;
#   3. migration simulée puis exécutée (wp yume migrer) ;
#   4. réglages (webhooks Discord vides), images de substitution (bannière du site, couvertures) ;
#   5. comptes : un par rôle de l'équipe et deux lecteurs (mot de passe connu, local uniquement) ;
#   6. publication réelle du tome 7 de Grimgar depuis le DOCX de référence (service de
#      publication ; le tome migré est réutilisé) ;
#   7. planning de démonstration, activité des lecteurs (favoris, notes, progression…).
#
#   export YUME_WP_PATH=… WP_CLI=…            (voir tools/localenv/README.md)
#   tools/preprod/construire.sh                 base « preprod » sous MariaDB, http://127.0.0.1:8090
#
# Variables (toutes facultatives) :
#   YUME_ENV=preprod            nom de la base (MariaDB : yume_<YUME_ENV>)
#   YUME_DB_ENGINE=mysql        moteur (mysql, ou vide pour SQLite)
#   YUME_PREPROD_PORT=8090      port du serveur local (home et siteurl)
#   YUME_MEDIAS=wp-content/uploads-preprod     dossier des médias de cette base (sous YUME_WP_PATH)
#   YUME_EXPORT=tools/migrate/export           export de l'ancien site (non versionné)
#   YUME_DOCX_GRIMGAR=tools/fixtures/private/grimgar-t7.docx   DOCX de référence (non versionné)
#   YUME_PREPROD_MDP=preprod    mot de passe des comptes de l'équipe et des lecteurs
#
# DESTRUCTIF pour la base YUME_ENV seulement (supprimée puis recréée). Jamais sur le site réel :
# le seed et les étapes refusent de tourner hors environnement local.
set -eu

DIR=$(cd "$(dirname "$0")" && pwd)
RACINE=$(cd "$DIR/../.." && pwd)
: "${YUME_WP_PATH:?Définir YUME_WP_PATH (voir tools/localenv/README.md)}"
YUME_ENV=${YUME_ENV:-preprod}
YUME_DB_ENGINE=${YUME_DB_ENGINE-mysql}
PORT=${YUME_PREPROD_PORT:-8090}
MEDIAS=${YUME_MEDIAS:-wp-content/uploads-preprod}
EXPORT=${YUME_EXPORT:-$RACINE/tools/migrate/export}
DOCX=${YUME_DOCX_GRIMGAR:-$RACINE/tools/fixtures/private/grimgar-t7.docx}
ADRESSE="http://127.0.0.1:$PORT"
export YUME_WP_PATH YUME_ENV YUME_DB_ENGINE
[ -n "${YUME_PREPROD_MDP:-}" ] && export YUME_PREPROD_MDP

WP="$RACINE/tools/localenv/wp.sh"
etape() { printf '\n== %s\n' "$*"; }
preprod() { "$WP" eval-file "$DIR/preprod.php" "$@"; }

[ -f "$EXPORT/pages.json" ] || { echo "Export introuvable : $EXPORT (voir tools/migrate/README.md)" >&2; exit 1; }

etape "1. Base neuve « $YUME_ENV » (${YUME_DB_ENGINE:-sqlite})"
if [ "$YUME_DB_ENGINE" = mysql ]; then
	BASE="yume_$(printf '%s' "$YUME_ENV" | tr 'A-Z' 'a-z' | sed 's/[^a-z0-9_]/_/g')"
	if command -v mariadb >/dev/null 2>&1; then CLIENT=mariadb; else CLIENT=mysql; fi
	"$CLIENT" -e "DROP DATABASE IF EXISTS \`$BASE\`; CREATE DATABASE \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" \
		|| "$CLIENT" -uwp -pwp -e "DROP DATABASE IF EXISTS \`$BASE\`; CREATE DATABASE \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
else
	if "$WP" core is-installed >/dev/null 2>&1; then
		FICHIER=$("$WP" eval 'echo defined( "FQDB" ) ? FQDB : ( defined( "DB_DIR" ) ? DB_DIR . "/.ht.sqlite" : "" );')
		[ -n "$FICHIER" ] && rm -f "$FICHIER"
	fi
fi
# Première commande : installation de WordPress (admin / admin), extension et thème activés.
"$WP" option update home "$ADRESSE" >/dev/null
"$WP" option update siteurl "$ADRESSE" >/dev/null
"$WP" option update blogname "Yume Novel" >/dev/null
echo "WordPress installé : $("$WP" core version), extension yume-core $("$WP" plugin get yume-core --field=version), thème $("$WP" theme list --status=active --field=name)."
# Site en français, comme la production. Sans paquet de langue fr_FR (WordPress local téléchargé
# sans traductions), un extrait de la traduction du cœur est installé pour la façade.
LANGUES="$YUME_WP_PATH/wp-content/languages"
if [ -f "$LANGUES/fr_FR.mo" ] || { [ -f "$LANGUES/fr_FR.l10n.php" ] && ! grep -q x-yume-preprod "$LANGUES/fr_FR.l10n.php"; }; then
	echo "Paquet de langue fr_FR du cœur présent."
else
	mkdir -p "$LANGUES"
	cp "$DIR/langues/fr_FR.l10n.php" "$LANGUES/fr_FR.l10n.php"
	echo "Extrait de traduction fr_FR du cœur installé ($LANGUES/fr_FR.l10n.php)."
fi
"$WP" option update WPLANG fr_FR >/dev/null

etape "2. Ancien site (seed de l'export, médias dans $MEDIAS)"
"$WP" eval-file "$RACINE/tools/migrate/seed-local.php" "$EXPORT" "medias=$MEDIAS"
"$WP" option update home "$ADRESSE" >/dev/null
"$WP" option update siteurl "$ADRESSE" >/dev/null

etape "3. Migration (simulation puis exécution, comme au jour J)"
"$WP" yume migrer --simuler | tail -n 6
"$WP" yume migrer --yes | tail -n 12
"$WP" yume migrer --etat | head -n 5

etape "4. Réglages et images de substitution"
preprod reglages
preprod medias

etape "5. Comptes (mot de passe : ${YUME_PREPROD_MDP:-preprod})"
preprod comptes
preprod complements

etape "6. Publication du tome 7 de Grimgar (DOCX de référence)"
preprod grimgar "$DOCX"

etape "7. Planning de démonstration et activité des lecteurs"
preprod planning
preprod lecteurs

etape "8. Finitions"
"$WP" rewrite flush >/dev/null
"$WP" cache flush >/dev/null 2>&1 || true
preprod bilan
printf '\nServeur : YUME_ENV=%s YUME_DB_ENGINE=%s php -S 127.0.0.1:%s -t "%s" %s\n' \
	"$YUME_ENV" "$YUME_DB_ENGINE" "$PORT" "$YUME_WP_PATH" "$RACINE/tools/localenv/router.php"
