# Yume-WordPress

Refonte du site **[yumenovel.fr](https://yumenovel.fr)** (traductions françaises de light novels) :
thème, plugin métier, scripts de migration et documentation, déployés sur WordPress.com depuis ce dépôt.

## État

**En production sur yumenovel.fr depuis le 29 septembre 2026** (release v2.0.0, migration de
l'ancien contenu le même jour). La version courante est la première entrée de
[CHANGELOG.md](CHANGELOG.md). Le thème `yume` et le plugin `yume-core` sont complets
(bibliothèque, lecteur en ligne généré depuis le DOCX, planning public, espace équipe, publication
par glisser-déposer, comptes lecteurs, migration de l'ancien site), testés sur SQLite et MariaDB.
Le site se met à jour depuis les **releases GitHub** : chaque version stable est taguée `vX.Y.Z`
depuis `main` après le « Go » explicite de l'équipe, pour **chaque** version ; aucun tag `v*`
sans ce Go. Décisions et jalons : [docs/journal-des-decisions.md](docs/journal-des-decisions.md).

**Tester en local :** [docs/tester-en-local.md](docs/tester-en-local.md) — le plus simple :
`tools/playground/lancer.sh` (ou `lancer.ps1` sous Windows) avec Node.js, puis <http://127.0.0.1:9400>.

## Documentation

| Document | Contenu |
| --- | --- |
| [docs/01-audit-existant.md](docs/01-audit-existant.md) | Inventaire du site actuel, contraintes de plateforme, analyse du DOCX de référence |
| [docs/02-plan-refonte.md](docs/02-plan-refonte.md) | Vision, décision d'hébergement, architecture, fonctionnalités F1–F8, plugins, phasage, jour J, décisions |
| [docs/03-modele-de-donnees.md](docs/03-modele-de-donnees.md) | Types de contenu, tables, rôles, API REST |
| [docs/04-import-docx-epub-lecteur.md](docs/04-import-docx-epub-lecteur.md) | Conversion DOCX/EPUB → chapitres, rendu et réglages du lecteur, marque-page |
| [docs/05-pipeline-github-wordpress.md](docs/05-pipeline-github-wordpress.md) | Releases GitHub → mises à jour WordPress, environnement local, CI, migration |
| [docs/06-contrat-technique.md](docs/06-contrat-technique.md) | Contrat technique partagé : types, métadonnées, blocs, routes, rôles, pages |
| [docs/mise-en-production.md](docs/mise-en-production.md) | Liste de contrôle de mise en production : comptes et 2FA, Jetpack, sauvegardes, `wp-config.php`, réglages GitHub |
| [docs/tester-en-local.md](docs/tester-en-local.md) | Lancer le site sur son ordinateur (Playground, environnement PHP, préproduction) |
| [docs/guide-equipe.md](docs/guide-equipe.md) | Guide de l'équipe : se connecter, espace équipe, planning et rappels, publier un tome, corriger un chapitre, œuvres et glossaires, modération des commentaires, indicateurs et santé du site, réglages, questions fréquentes |
| [docs/guide-equipe/Guide-equipe-Yume-Novel.docx](docs/guide-equipe/Guide-equipe-Yume-Novel.docx) | Guide de l'équipe en Word, à distribuer : procédures pas à pas avec captures (planning, œuvres, nouveau tome, fichier Word, ajouter des chapitres, terminer et modifier un tome, état des tomes et des œuvres, lecteurs, questions fréquentes). Source : [docs/guide-equipe/procedures.md](docs/guide-equipe/procedures.md), mise à jour : [tools/guide/README.md](tools/guide/README.md) |
| [docs/guide-developpeur.md](docs/guide-developpeur.md) | Guide développeur : modules, tests, CI, release |
| [docs/journal-des-decisions.md](docs/journal-des-decisions.md) | Journal daté des décisions et des jalons (plan, Go, releases) et décisions en attente |
| [docs/glossaire.md](docs/glossaire.md) | Glossaire des œuvres : format YAML de Yume-Trad, page publique, espace équipe, envoi direct par l'application (API) |
| [docs/wordend.md](docs/wordend.md) | Easter egg WordEnd : mini-jeu caché (« Chtholly – Bats-toi contre ton destin »), commandes, planche de sprites |
| [design/README.md](design/README.md) | Direction visuelle, tokens, liste des maquettes |
| `tools/*/README.md` | Outils : [localenv](tools/localenv/README.md) (WordPress local SQLite + WP-CLI), [playground](tools/playground/README.md) (démo dans le navigateur), [preprod](tools/preprod/README.md) (copie locale du site migré), [migrate](tools/migrate/README.md) (export, plan et exécution de la migration), [docx2chapters](tools/docx2chapters/README.md) (DOCX → chapitres en ligne de commande), [wordend](tools/wordend/README.md) (planches de sprites de l'easter egg) |
| [docs/plan-refonte.html](docs/plan-refonte.html) | Version présentable du plan (page HTML autonome) |

## Plateforme

Le site reste sur son **plan WordPress.com actuel**. Tous les plans payants permettent d'installer des
extensions et de téléverser un plugin ou un thème maison (transfert automatique vers l'hébergement
Atomic à la première installation). Sans GitHub Deployments ni SSH, le code se met à jour depuis les
**releases GitHub** et la migration est une page d'administration du plugin. Les PDF et EPUB ne sont
jamais hébergés sur le site ; la lecture en ligne est générée depuis le DOCX. Voir
`docs/02-plan-refonte.md` §2 et `docs/05-pipeline-github-wordpress.md`.

## Arborescence

```
wp-content/plugins/yume-core   plugin métier (types de contenu, planning, import DOCX/EPUB, API, comptes)
wp-content/themes/yume         thème bloc « Nuit sakura / Papier »
tools/                         scripts de migration, fixtures, découpe de la planche WordEnd
docs/                          documentation
design/                        tokens et maquettes
```
