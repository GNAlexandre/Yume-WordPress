# Environnement local (tools/localenv)

WordPress complet sur votre machine, **sans MySQL ni Docker** : base SQLite (intégration officielle
[sqlite-database-integration](https://github.com/WordPress/sqlite-database-integration)), WP-CLI, le
plugin `yume-core` et le thème `yume` du dépôt montés par liens symboliques. Une modification dans
le dépôt est visible immédiatement, sans copie ni build.

| Script | Rôle |
| --- | --- |
| `setup.sh` | Installe tout depuis zéro (une fois) et écrit `env.sh` |
| `wp.sh` | WP-CLI sur la base choisie par `YUME_ENV` (installe WordPress à la première utilisation) |
| `test.sh` | Lance les tests du plugin (`tests/runner.php`) |
| `serve.sh` | Sert le site avec le serveur intégré de PHP (`router.php`) |

## Prérequis

- PHP 8.1 ou plus en ligne de commande, avec les extensions `pdo_sqlite`, `zip`, `mbstring`, `intl`, `gd`, `dom` ;
- `git` et `curl` ;
- [Composer](https://getcomposer.org/) (source par défaut du cœur WordPress), ou un accès à wordpress.org
  pour `--source wp-cli`.

## Installation

```sh
tools/localenv/setup.sh
. ~/.local/share/yume-localenv/env.sh
tools/localenv/test.sh
```

`setup.sh` crée, dans `~/.local/share/yume-localenv/` (ou le dossier passé à `--dossier`, ou
`$YUME_LOCALENV_DIR`) :

| Chemin | Contenu |
| --- | --- |
| `wp/` | Cœur WordPress (`YUME_WP_PATH`), drop-in `wp-content/db.php`, `wp-config.php` |
| `wp/wp-content/plugins/yume-core`, `wp/wp-content/themes/yume` | Liens symboliques vers le dépôt |
| `wp/wp-content/plugins/sqlite-database-integration` | Plugin SQLite (lien `wp-includes/database` résolu en vraie copie) |
| `wp/wp-content/mu-plugins/yume-localenv.php` | Sans réseau : réponses « aucune mise à jour » de WordPress.org (pas d'avertissement dans `debug.log`) |
| `databases/<YUME_ENV>/` | Une base SQLite par environnement |
| `sqlite-database-integration/` | Clone du dépôt de l'intégration SQLite |
| `bin/wp` | WP-CLI, téléchargé seulement s'il n'est pas déjà installé |
| `env.sh` | `export YUME_WP_PATH=… WP_CLI=… YUME_ENV=…` |

Options utiles :

| Option | Effet |
| --- | --- |
| `--dossier DIR` | Autre dossier d'installation |
| `--source wp-cli` | Télécharge WordPress avec `wp core download --locale=fr_FR` (fichiers de langue inclus) au lieu de Composer |
| `--version 7.1.2` | Version précise de WordPress |
| `--langue en_US` | Site en anglais (défaut : `fr_FR`, défini par `WPLANG` dans `wp-config.php`) |
| `--bloquer-http` | `WP_HTTP_BLOCK_EXTERNAL` : WordPress ne fait aucune requête sortante |
| `--sans-liens` | Ni plugin ni thème liés : pour tester les archives de `tools/build/zip.sh` |
| `--env nom` | Base préparée tout de suite (défaut : `$YUME_ENV` ou `default`) |
| `--sqlite-ref v2.2.3` | Branche ou tag de l'intégration SQLite |
| `--forcer` | Réinstalle le cœur, SQLite et `wp-config.php` (bases et médias conservés) |

Relancer `setup.sh` est sans danger : le cœur et `wp-config.php` existants sont conservés, l'intégration
SQLite et les liens sont remis à jour.

La CI utilise exactement ce script : `setup.sh --source wp-cli --langue fr_FR --bloquer-http`
(voir `.github/workflows/ci.yml`).

## Utilisation

```sh
. ~/.local/share/yume-localenv/env.sh      # une fois par terminal

tools/localenv/wp.sh plugin list           # n'importe quelle commande WP-CLI
tools/localenv/test.sh                     # tous les tests
tools/localenv/test.sh updater planning    # seulement tests/test-updater.php et tests/test-planning.php
tools/localenv/serve.sh                    # http://127.0.0.1:8080, compte admin / admin
tools/localenv/serve.sh 8083               # autre port
```

### Plusieurs bases (`YUME_ENV`)

Chaque valeur de `YUME_ENV` a sa propre base dans `databases/<YUME_ENV>/`, installée automatiquement
par `wp.sh` à la première utilisation (plugin `yume-core` et thème `yume` activés, compte `admin` /
`admin`). Pratique pour garder une base de démonstration à côté de la base de tests :

```sh
YUME_ENV=demo tools/localenv/wp.sh eval-file tools/playground/demo.php   # contenu de démonstration
YUME_ENV=demo tools/localenv/serve.sh
rm -rf ~/.local/share/yume-localenv/databases/demo                        # repartir de zéro
```

### MySQL / MariaDB (moteur de la production)

Avec `YUME_DB_ENGINE=mysql`, le `wp-config.php` généré par `setup.sh` utilise MySQL/MariaDB au lieu
de SQLite : base `yume_<YUME_ENV>` créée au besoin sur `YUME_DB_HOST` (défaut `localhost`) avec
`YUME_DB_USER` / `YUME_DB_PASSWORD` (défaut `wp` / `wp`). La CI lance ainsi les tests sur les deux
moteurs (contrat §13). Un `wp-config.php` antérieur doit être régénéré (`setup.sh --forcer`).

```sh
YUME_DB_ENGINE=mysql YUME_ENV=tests tools/localenv/test.sh
```

### Ne charger que certains modules

`YUME_ONLY_MODULES` (pris en compte seulement quand `YUME_DEV` est vrai, ce que fait `wp-config.php`
local) limite les modules chargés par `yume-core.php`, utile quand un module en chantier casse le
chargement :

```sh
YUME_ONLY_MODULES=core,planning tools/localenv/test.sh planning
```

### Tâches planifiées, courriels, réseau

- WP-Cron est désactivé (`DISABLE_WP_CRON`) : déclenchez les tâches à la main,
  `tools/localenv/wp.sh cron event list` puis `tools/localenv/wp.sh cron event run --due-now`.
- Les tests court-circuitent `wp_mail()` ; hors tests, sans serveur de courrier, les envois échouent
  silencieusement.
- Avec `--bloquer-http`, toute requête sortante échoue (GitHub, Discord…) ; les tests simulent les API
  avec le filtre `pre_http_request`.

### Journal PHP

`wp/wp-content/debug.log` (`WP_DEBUG_LOG`, jamais affiché à l'écran). Le contrat (§16) demande
qu'aucune notice ne vienne de nos fichiers pendant les tests :

```sh
: > "$YUME_WP_PATH/wp-content/debug.log"; tools/localenv/test.sh; grep -i yume "$YUME_WP_PATH/wp-content/debug.log"
```

### Tester les archives d'installation

```sh
tools/build/zip.sh                                        # dist/yume-core.zip et dist/yume.zip
tools/localenv/setup.sh --dossier /tmp/yume-zip --sans-liens --bloquer-http --env zip
. /tmp/yume-zip/env.sh
tools/localenv/wp.sh plugin install dist/yume-core.zip --activate
tools/localenv/wp.sh theme install dist/yume.zip --activate
tools/localenv/wp.sh eval-file wp-content/plugins/yume-core/tests/runner.php   # tests du dépôt sur le plugin installé
```

## Dépannage

| Symptôme | Solution |
| --- | --- |
| `Définir YUME_WP_PATH` | Sourcer `env.sh` : `. ~/.local/share/yume-localenv/env.sh` |
| `activation de yume-core impossible` | Lire `debug.log` ; isoler le module fautif avec `YUME_ONLY_MODULES=core` |
| Le site affiche « Erreur lors de la connexion à la base » | Le drop-in `wp-content/db.php` manque : relancer `setup.sh` |
| Pages en 404 après ajout d'un type de contenu | `tools/localenv/wp.sh rewrite flush` |
| Téléchargement de WordPress impossible | Composer : vérifier l'accès à Packagist ; `--source wp-cli` : vérifier l'accès à wordpress.org |
| Interface de WordPress en anglais | Fichiers de langue absents (hors ligne) : `tools/localenv/wp.sh language core install fr_FR`, ou `--source wp-cli` |
| Base corrompue ou incohérente | `rm -rf <dossier>/databases/<YUME_ENV>` puis relancer la commande |
| Tout réinstaller | `rm -rf ~/.local/share/yume-localenv` puis `setup.sh` |

Voir aussi `docs/guide-developpeur.md`.
