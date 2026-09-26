# Bibliothèques vendorisées

Code tiers embarqué tel quel dans le plugin (contrat §0 : aucune dépendance Composer à l'exécution).
Ce fichier n'est pas livré dans `yume-core.zip` (voir `tools/build/zip.sh`).

## plugin-update-checker

- Projet : [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
- Version : **5.6** (tag `v5.6`, commit `a2db687`)
- Licence : MIT (`plugin-update-checker/license.txt`)
- Utilisée par : `includes/updater/module.php` (mises à jour du plugin et du thème depuis les
  releases GitHub)

Fichiers conservés : ceux nécessaires à l'exécution (`plugin-update-checker.php`, `load-v5p6.php`,
`Puc/`, `vendor/` — Parsedown et l'analyseur de readme —, `css/` et `js/` du panneau Debug Bar) et
les traductions françaises compilées (`languages/*-fr_FR.mo`, `*-fr_CA.mo`). Retirés : `.git`,
exemples, scripts de build, fichiers `.po`/`.pot`, `composer.json`, `README.md`, `phpcs.xml`.

Mettre à jour :

```sh
git clone --depth 1 --branch v5.X https://github.com/YahnisElsts/plugin-update-checker.git /tmp/puc
cd wp-content/plugins/yume-core/lib/plugin-update-checker
rm -rf Puc vendor css js languages ./*.php license.txt
cp -R /tmp/puc/Puc /tmp/puc/vendor /tmp/puc/css /tmp/puc/js /tmp/puc/license.txt /tmp/puc/plugin-update-checker.php /tmp/puc/load-v5p*.php .
mkdir languages && cp /tmp/puc/languages/plugin-update-checker-fr_*.mo languages/
```

Puis mettre à jour ce fichier, vérifier que la classe `YahnisElsts\PluginUpdateChecker\v5\PucFactory`
existe toujours et lancer `tools/localenv/test.sh updater`.
