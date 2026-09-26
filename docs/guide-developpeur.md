# Guide du développeur — Yume Novel v2

Ce guide explique comment travailler sur le dépôt : installer l'environnement local, lancer les
tests, respecter les conventions, publier une version et l'installer sur yumenovel.fr. Les décisions
de fond sont dans `docs/02` à `docs/05` ; **les noms et interfaces partagés sont figés dans
[`docs/06-contrat-technique.md`](06-contrat-technique.md)**, la référence à relire avant toute
modification.

## 1. Le dépôt en bref

```
wp-content/plugins/yume-core/     plugin métier
  yume-core.php                   en-tête, version, chargement des modules (socle)
  includes/<module>/module.php    un dossier par module : core, import, publication, planning,
                                  reader, social, library, migration, updater
  includes/blocks-support.php     enregistrement des blocs dynamiques (socle)
  lib/plugin-update-checker/      bibliothèque de mises à jour vendorisée (MIT, voir lib/README.md)
  tests/                          mini-framework de test + tests/test-<module>.php (non livrés)
wp-content/themes/yume/           thème bloc « Crépuscule sakura »
tools/localenv/                   WordPress local SQLite (setup.sh, wp.sh, test.sh, serve.sh)
tools/build/                      lint.sh (php -l), zip.sh (archives installables)
tools/playground/                 blueprints WordPress Playground + contenu de démonstration
tools/docx2chapters/, tools/migrate/   outils en ligne de commande (import DOCX, migration)
tools/preprod/                    préproduction locale (MariaDB) construite de zéro + parcours Playwright
tools/ci/                         contrôle de rendu de la CI (rendu.sh, rendu.js, rendu-attendus.js)
.github/workflows/                ci.yml (intégration continue), release.yml (publication)
phpcs.xml.dist                    normes de code
docs/, design/                    documentation, maquettes validées (design/maquettes/*.dc.html)
```

Chaque module est la propriété d'une équipe (contrat §1) ; on ne modifie pas le dossier d'un autre
module sans passer par elle, et une évolution d'interface commence par le contrat.

## 2. Environnement local

Détails et options : [`tools/localenv/README.md`](../tools/localenv/README.md).

```sh
tools/localenv/setup.sh                       # une fois : WordPress + SQLite + WP-CLI + liens
. ~/.local/share/yume-localenv/env.sh         # dans chaque terminal
tools/localenv/wp.sh plugin list              # WP-CLI sur la base $YUME_ENV
tools/localenv/serve.sh                       # http://127.0.0.1:8080 (admin / admin)
```

- **Pas de MySQL ni de Docker** : SQLite via le drop-in officiel. Le SQL écrit dans le plugin doit
  rester compatible MySQL/MariaDB (production) **et** SQLite (contrat §13) : pas de fonctions
  propres à un moteur, `$wpdb->prepare()` partout, `dbDelta()` pour les tables.
- **Une base par `YUME_ENV`** (`default`, `demo`, `tests`…), créée à la première commande ;
  `rm -rf <dossier>/databases/<nom>` pour repartir de zéro.
- `wp-config.php` local : `WP_DEBUG` + `WP_DEBUG_LOG` (journal `wp-content/debug.log`),
  `YUME_DEV` (active `YUME_ONLY_MODULES` et le chargement tolérant des modules),
  `DISABLE_WP_CRON` (tâches lancées par `wp cron event run --due-now`), `WPLANG=fr_FR`.
- Contenu de démonstration : `YUME_ENV=demo tools/localenv/wp.sh eval-file tools/playground/demo.php`.
- Relecture par l'équipe sans installation : blueprints Playground
  ([`tools/playground/README.md`](../tools/playground/README.md)).

Le plugin et le thème étant des **liens symboliques vers le dépôt**, le module updater considère
l'installation comme une copie de développement : aucune mise à jour automatique, et toute mise à
jour par l'administration est refusée (elle effacerait votre copie de travail).

## 3. Tests

```sh
tools/localenv/test.sh              # tous les tests
tools/localenv/test.sh updater      # tests/test-updater.php seulement
```

`tests/runner.php` (exécuté par `wp eval-file`) charge `tests/test-*.php` ; chaque test est déclaré
avec `yume_test( 'libellé', function () { … } )` et utilise les assertions de `tests/helpers.php`
(`yume_assert_same`, `yume_assert_true`, `yume_assert_contains`…) et les fabriques
(`yume_factory_post`, `yume_factory_user`, `yume_rest`, `yume_render_block`).

Règles :

- **Chaque test tourne dans une transaction annulée** : la base est intacte après coup. Ce qui vit
  hors base (état en mémoire d'un objet, filtres ajoutés) doit être remis en place dans un
  `finally`.
- **Aucune requête réseau réelle** : simulez les API avec le filtre `pre_http_request` (voir
  `tests/test-updater.php`, qui simule l'API GitHub). En CI, les requêtes sortantes sont bloquées.
- **Aucune notice PHP** : le contrat (§16) interdit toute notice venant de nos fichiers dans
  `debug.log` pendant les tests ; la CI échoue si le journal contient un message lié à Yume.
- Les tests doivent passer avec l'interface en français (CI : WordPress fr_FR avec ses traductions)
  comme sans fichiers de langue (environnement local hors ligne) : n'écrivez pas d'assertion sur un
  texte traduit par WordPress.

## 4. Conventions

Rappel du contrat (§0) :

- PHP ≥ 8.1, WordPress ≥ 6.6 (testé sur 7.1) ; aucune dépendance Composer à l'exécution.
- Site mono-langue : chaînes sources **en français**, `__( 'Texte', 'yume-core' )` dans le plugin,
  `'yume'` dans le thème. Commentaires en français.
- Espaces de noms `Yume\Core\<Module>` ; fonctions publiques `yume_*` dans `includes/<module>/api.php`.
  Au chargement, un module n'appelle aucune fonction d'un autre : il accroche des hooks.
- Sécurité : capacité vérifiée pour toute action, `permission_callback` réel sur chaque route REST,
  nonce `wp_rest` (en-tête `X-WP-Nonce`) ou `wp_nonce_field`, entrées assainies, sorties échappées
  (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), requêtes préparées.
- Accessibilité : vrais `<button>`, `<a href>`, `<label>` ; focus visible ; `aria-*` sur les
  contrôles icône seuls ; contrastes ≥ 4,5:1 ; tout fonctionne sans JavaScript sauf les outils de
  lecture et les formulaires dynamiques de l'équipe.
- Pas de build : JavaScript ES2019 « vanilla » avec les globaux WordPress (`wp.element`,
  `wp.apiFetch`…), CSS natif avec les variables de `theme.json` (contrat §15).

### Syntaxe et normes de code

```sh
tools/build/lint.sh          # php -l sur tout le code (échoue aussi sur une dépréciation)
PHP=php8.1 tools/build/lint.sh
phpcs                        # normes : WordPress-Extra + WordPress-Docs + phpcs.xml.dist
phpcbf wp-content/plugins/yume-core/includes/mon-module   # corrections automatiques
```

Installer PHPCS une fois :

```sh
composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require wp-coding-standards/wpcs:"^3.1" phpcompatibility/phpcompatibility-wp:"^2.1"
```

`phpcs.xml.dist` impose les préfixes `yume`/`Yume`, les domaines `yume-core`/`yume`, PHP 8.1+,
déclare les capacités Yume (contrat §5) et exclut `lib/`, `vendor/`, `tools/fixtures/` et `tools/preprod/langues/`. Les tests
et les outils en ligne de commande ont des exceptions ciblées (sortie console, requêtes directes).

**PHPCS est bloquant** : `phpcs` ne signale plus aucune erreur ni aucun avertissement sur le dépôt,
et le job « Normes de code » de `ci.yml` échoue au premier écart (aussi annoté sur la pull request).
Lancer `phpcs` (ou `phpcbf` pour les corrections automatiques) avant de pousser. Une exception
justifiée se note sur la ligne concernée (`// phpcs:ignore Règle -- raison`).

## 5. Le contrat technique

[`docs/06-contrat-technique.md`](06-contrat-technique.md) fige : types de contenu, métadonnées, rôles
et capacités, réglages (`yume_setting()`), API PHP partagée, événements, blocs et leurs classes
racines, routes REST, tables, clés `localStorage`, design tokens. Pour changer l'un de ces éléments :
proposer la modification du contrat dans une PR (avec les modules concernés en relecteurs), puis
l'implémenter. Ne jamais redéclarer une fonction `yume_*` d'un autre module.

## 6. Intégration continue

`.github/workflows/ci.yml` tourne sur chaque push de branche et chaque pull request :

| Job | Contenu |
| --- | --- |
| Syntaxe PHP 8.1 / 8.2 / 8.3 / 8.4 | `tools/build/lint.sh` ; blueprints Playground à jour (`construire.php --verifier`) |
| Normes de code | PHPCS, annotations sur la PR (bloquant, voir §4) |
| Tests WordPress (6.6 sous PHP 8.1, dernière version sous PHP 8.4 ; chacune sur SQLite et sur MariaDB 10.11) | `tools/localenv/setup.sh --source wp-cli --langue fr_FR --bloquer-http [--version 6.6]` (avec `YUME_DB_ENGINE=mysql` et un service `mariadb:10.11` pour MariaDB), puis `wp eval-file wp-content/plugins/yume-core/tests/runner.php` ; contrôle de `debug.log` |
| Rendu WordPress (6.6 sous PHP 8.1, dernière version sous PHP 8.4 ; SQLite) | même installation que les tests, démo `tools/playground/demo.php`, puis `tools/ci/rendu.sh` : styles calculés des blocs vérifiés dans Chromium (voir ci-dessous) ; artefact `rendu-mesures-<version>` |
| Rendu identique sur WordPress 6.6 et la dernière | `node tools/ci/rendu.js --comparer` sur les deux artefacts `rendu-mesures-*` |
| Archives | `tools/build/zip.sh`, artefact `yume-archives-<version>-<n°>` (à décompresser : il contient `yume-core.zip`, `yume.zip`, `SHA256SUMS`) |

Protection de la branche principale recommandée (docs/05 §3) : PR obligatoire, jobs *Syntaxe*,
*Tests*, *Rendu* et *Archives* verts, une relecture.

### Contrôle de rendu (job « Rendu »)

Sous WordPress 6.6.0, les styles globaux du cœur (titres `h1`-`h6`, liens, boutons) ont une
spécificité supérieure ou égale à un sélecteur de bloc d'une seule classe et l'écrasent : le titre du
panneau de lecture passait en 28px/700 au lieu de 20px/800, le lien de retour du lecteur en rose accent
au lieu de `texte-fort` (revue RC-1). Règle : **dans le CSS d'un bloc, préfixer les sélecteurs par la
classe du bloc** (`.yn-reader-panel .yn-reader-panel__titre`, `.yn-toc .yn-toc__lien`…). La
régression n'apparaît plus à partir de 6.6.1 : la CI teste donc 6.6 exactement (6.6.0).

Le job « Rendu » l'empêche de revenir :

1. `tools/localenv/setup.sh --source wp-cli --langue fr_FR --bloquer-http [--version 6.6]` (SQLite),
   puis `tools/localenv/wp.sh eval-file tools/playground/demo.php` (œuvre *Lanternes de brume haute*) ;
2. `npm install` dans `tools/ci/` (Playwright, version figée dans `tools/ci/package.json`) et
   `npx playwright install --with-deps chromium` ;
3. `tools/ci/rendu.sh` sert le site avec `php -S` et `tools/localenv/router.php`, puis
   `tools/ci/rendu.js` ouvre dans Chromium (fenêtre 1280×900) la page de l'œuvre, celle du tome 1 et
   le chapitre 1 avec le panneau *Paramètres de lecture* ouvert, et compare `font-size`,
   `font-weight` et `color` des éléments listés dans `tools/ci/rendu-attendus.js` ;
4. le job « Rendu identique… » compare ensuite les mesures des deux versions de WordPress.

En cas d'échec, le journal liste chaque écart (aussi en annotation) :

```
[rendu] ÉCHEC : 3 écart(s) de rendu (WordPress 6.6) :
  - chapitre (panneau Paramètres ouvert) : .yn-reader-tools__retour { color } = rgb(243, 166, 200), attendu rgb(255, 248, 251) (preset:texte-fort)
  - chapitre (panneau Paramètres ouvert) : .yn-reader-panel__titre { font-size } = 28px, attendu 20px
```

Dans `rendu-attendus.js`, une couleur s'écrit `preset:<slug>` (palette de `theme.json`, résolue dans le
contexte de l'élément) ; les autres valeurs sont les valeurs calculées exactes. Un changement de design
voulu se reporte dans ce tableau. Pour ajouter un contrôle : une entrée `{ selecteur, attendu }` dans la
page concernée (le premier élément correspondant doit exister et être visible).

En local :

```sh
. ~/.local/share/yume-localenv/env.sh
export YUME_ENV=rendu
tools/localenv/wp.sh eval-file tools/playground/demo.php
npm install --prefix tools/ci && (cd tools/ci && npx playwright install chromium)
tools/ci/rendu.sh                        # port 8090 ; --port N, --sortie mesures.json, --libelle "WP 6.6"
node tools/ci/rendu.js --comparer a.json b.json
```

Pour WordPress 6.6 : `tools/localenv/setup.sh --dossier /tmp/yume-66 --version 6.6`, puis
`. /tmp/yume-66/env.sh` et les mêmes commandes.

## 7. Publier une version

> **Règle de l'équipe (contrat §0 bis)** : rien n'est poussé sur yumenovel.fr sans le « Go »
> explicite de l'équipe. Un tag `v*` crée une release, et une release est installée
> automatiquement par le site : **pas de tag sans Go**.

1. **Numéro de version** (versionnage sémantique : correctif `2.0.1`, fonctionnalité `2.1.0`,
   rupture `3.0.0`). Mettre le même numéro à trois endroits :
   - `wp-content/plugins/yume-core/yume-core.php` : en-tête `Version:` **et** constante
     `YUME_CORE_VERSION` ;
   - `wp-content/themes/yume/style.css` : en-tête `Version:`.
   Changer `YUME_CORE_VERSION` relance l'installation des modules (`yume_core_install` : tables via
   `dbDelta`, rôles, options) au premier chargement : c'est ainsi que les migrations de schéma sont
   appliquées sur le site.
2. **Vérifier** : `tools/build/zip.sh --version-attendue 2.1.0` (échoue si une version diffère),
   tests verts, relecture sur Playground (`blueprint-branche.json`) si besoin.
3. **Fusionner** la PR dans la branche principale.
4. **Taguer** le commit fusionné, après le Go :
   ```sh
   git tag -a v2.1.0 -m "Yume Novel 2.1.0"
   git push origin v2.1.0
   ```
5. **`release.yml`** vérifie le tag (format `vX.Y.Z`, versions identiques au tag, commit présent sur
   la branche par défaut), rejoue toute la CI, construit les archives et crée la release GitHub
   « Yume Novel v2.1.0 » avec `yume-core.zip`, `yume.zip`, `SHA256SUMS` et des notes de version
   générées (modifiables ensuite sur GitHub : elles s'affichent dans « Voir les détails » de la mise
   à jour).
6. **Sur le site** : le plugin interroge la dernière release **toutes les 12 heures** (ou tout de
   suite avec le lien « Vérifier les mises à jour » sous Yume Core dans *Extensions*). La nouvelle
   version apparaît dans *Tableau de bord → Mises à jour* ; si *Yume → Réglages → Mises à jour
   automatiques* est coché (défaut), WordPress l'installe seul lors de son passage de mises à jour
   automatiques suivant. Le thème suit le même chemin avec `yume.zip`.

Préversion : un tag `v2.1.0-beta.1` (autorisé depuis n'importe quelle branche) crée une release
marquée « pre-release » que les sites **ignorent** ; pratique pour faire tester l'archive. Seules les
releases publiées (ni brouillon ni préversion) portant exactement `yume-core.zip` / `yume.zip` sont
prises en compte : jamais l'archive source du dépôt, jamais une branche.

Revenir en arrière : publier un correctif (`v2.1.1`) est la voie normale. En urgence, téléverser
l'archive d'une release précédente (*Extensions → Ajouter → Téléverser*, puis « Remplacer la version
actuelle par la version téléversée ») et décocher temporairement les mises à jour automatiques.

## 8. Première installation sur WordPress.com

Une seule fois (docs/05 §3, plan de bascule docs/02 §8) :

1. Sauvegarde : UpdraftPlus + export XML (*Outils → Exporter*).
2. Récupérer `yume-core.zip` et `yume.zip` : assets de la release sur GitHub, ou artefact de la CI
   pour une recette, ou `tools/build/zip.sh` en local.
3. *Extensions → Ajouter → Téléverser une extension* : `yume-core.zip`, puis **Activer**. (La
   première extension téléversée déclenche le transfert du site vers l'hébergement Atomic, inclus
   dans le plan : quelques minutes, même adresse.)
4. *Apparence → Thèmes → Ajouter → Téléverser* : `yume.zip`. **Ne pas l'activer avant le jour J.**
5. *Yume → Réglages*, section *Mises à jour* : dépôt `GNAlexandre/Yume-WordPress`, mises à jour
   automatiques cochées. Sous *Extensions*, le lien « Vérifier les mises à jour » de Yume Core doit
   répondre sans erreur.
6. Continuer avec la migration (*Yume → Migrer → Simuler*, puis *Exécuter* le jour J).

Dépôt privé : les mises à jour exigent un jeton GitHub en lecture seule (*fine-grained token*,
permission *Contents : read* sur ce dépôt) déclaré dans `wp-config.php` :
`define( 'YUME_GITHUB_TOKEN', 'github_pat_…' );`. Le plan actuel ne donne pas accès à
`wp-config.php` (ni SFTP ni SSH) : **le dépôt doit donc rester public** pour que yumenovel.fr se
mette à jour.

Seuls les comptes qui peuvent déjà installer des mises à jour (`update_plugins` : les
administrateurs) voient et modifient *Dépôt GitHub* et *Mises à jour automatiques* : un gérant ne
peut pas désigner la source du code installé. Pour figer la source, déclarer dans `wp-config.php`
`define( 'YUME_GITHUB_REPO', 'GNAlexandre/Yume-WordPress' );` : la constante prime sur le réglage,
alors affiché en lecture seule.

Réglages du module updater pour les développeurs : filtres `yume_updater_actif` (désactiver
complètement la recherche de mises à jour), `yume_updater_depot` (autre dépôt « propriétaire/dépôt »)
et `yume_updater_copie_de_developpement` (forcer ou annuler la détection de copie de travail).

## 9. Dépannage

| Problème | Piste |
| --- | --- |
| La mise à jour n'apparaît pas sur le site | Release publiée (pas brouillon ni préversion) ? Assets nommés exactement `yume-core.zip` / `yume.zip` ? Version du tag supérieure à celle installée ? Cliquer « Vérifier les mises à jour » sous Yume Core dans *Extensions*. |
| « Impossible de vérifier » / erreurs d'API | Limite de 60 requêtes/heure de l'API GitHub sans jeton (partagée par l'adresse IP de l'hébergeur) : réessayer plus tard. Dépôt renommé ou privé : vérifier *Yume → Réglages → Dépôt GitHub*. |
| La mise à jour est proposée mais jamais installée seule | Mises à jour automatiques décochées dans *Yume → Réglages*, WP-Cron inactif, ou mises à jour automatiques désactivées par l'hébergeur (`AUTOMATIC_UPDATER_DISABLED`) : l'installer d'un clic dans *Tableau de bord → Mises à jour*. |
| Mise à jour refusée : « copie de développement » | Le plugin ou le thème installé est un lien symbolique ou un dépôt Git : le mettre à jour avec Git. |
| La mise à jour installe un dossier au mauvais nom | L'archive doit contenir un seul dossier racine `yume-core/` (ou `yume/`) : toujours la construire avec `tools/build/zip.sh`. |
| `release.yml` échoue sur « Tag et versions » | Le tag et les trois versions (en-tête et constante du plugin, en-tête du thème) doivent être identiques ; une version stable doit être taguée sur la branche par défaut. Supprimer le tag (`git push --delete origin vX.Y.Z`), corriger, retaguer. |
| Un module casse le chargement en local | `debug.log`, puis `YUME_ONLY_MODULES=core,<module>` pour isoler. |
| Tests verts en local, rouges en CI | Texte traduit par WordPress (CI en français), requête réseau non simulée, notice dans `debug.log`, ou SQL propre à SQLite/MySQL. |
| Pages en 404 | `tools/localenv/wp.sh rewrite flush`. |
| Tâche planifiée qui ne part pas | `tools/localenv/wp.sh cron event list` puis `wp cron event run <hook>` ; en production, l'extension WP Crontrol. |
| Job « Rendu » rouge sur 6.6 seulement | Un sélecteur de bloc non préfixé est écrasé par les styles globaux du cœur : le préfixer par la classe du bloc (§6, « Contrôle de rendu »). |
| `serve.sh` : CSS absent | Vérifier que le serveur tourne avec `tools/localenv/router.php` (lancer via `serve.sh`). |
