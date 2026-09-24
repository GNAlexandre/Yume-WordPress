# Yume-WordPress

Refonte du site **[yumenovel.fr](https://yumenovel.fr)** (traductions françaises de light novels) :
thème, plugin métier, scripts de migration et documentation, déployés sur WordPress.com depuis ce dépôt.

## État

Phase **0 — cadrage**. Ce dépôt contient l'audit du site actuel, le plan de refonte, le modèle de
données, la spécification du lecteur en ligne et du pipeline de déploiement, ainsi que les notes de design.
Le code (thème `yume`, plugin `yume-core`) sera ajouté à partir de la phase 0 du plan.

## Documentation

| Document | Contenu |
| --- | --- |
| [docs/01-audit-existant.md](docs/01-audit-existant.md) | Inventaire du site actuel, contraintes de plateforme, analyse du DOCX de référence |
| [docs/02-plan-refonte.md](docs/02-plan-refonte.md) | Vision, décision d'hébergement, architecture, fonctionnalités F1–F8, plugins, phasage, jour J, décisions |
| [docs/03-modele-de-donnees.md](docs/03-modele-de-donnees.md) | Types de contenu, tables, rôles, API REST |
| [docs/04-import-docx-epub-lecteur.md](docs/04-import-docx-epub-lecteur.md) | Conversion DOCX/EPUB → chapitres, rendu et réglages du lecteur, marque-page |
| [docs/05-pipeline-github-wordpress.md](docs/05-pipeline-github-wordpress.md) | Arborescence, GitHub Deployments, staging, CI, migration |
| [design/README.md](design/README.md) | Direction visuelle, tokens, liste des maquettes |
| [docs/plan-refonte.html](docs/plan-refonte.html) | Version présentable du plan (page HTML autonome) |

## Plateforme

Le site reste sur son **plan WordPress.com actuel**. Tous les plans payants permettent d'installer des
extensions et de téléverser un plugin ou un thème maison (transfert automatique vers l'hébergement
Atomic à la première installation). Sans GitHub Deployments ni SSH, le code se met à jour depuis les
**releases GitHub** et la migration est une page d'administration du plugin. Les PDF et EPUB ne sont
jamais hébergés sur le site ; la lecture en ligne est générée depuis le DOCX. Voir
`docs/02-plan-refonte.md` §2 et `docs/05-pipeline-github-wordpress.md`.

## Arborescence cible

```
wp-content/plugins/yume-core   plugin métier (types de contenu, planning, import DOCX/EPUB, API, comptes)
wp-content/themes/yume         thème bloc « Nuit sakura / Papier »
tools/                         scripts de migration et fixtures
docs/                          documentation
design/                        tokens et maquettes
```
