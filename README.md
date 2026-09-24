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

## Décision préalable

Le site est hébergé sur **WordPress.com « Simple »** (plan Personal) : aucun plugin ni code n'y est
possible. Le plan Business (hébergement Atomic, GitHub Deployments, staging, SSH) est le prérequis de
tout le programme. Voir `docs/02-plan-refonte.md`, §2.

## Arborescence cible

```
wp-content/plugins/yume-core   plugin métier (types de contenu, planning, import DOCX/EPUB, API, comptes)
wp-content/themes/yume         thème bloc « Nocturne / Papier »
tools/                         scripts de migration et fixtures
docs/                          documentation
design/                        tokens et maquettes
```
