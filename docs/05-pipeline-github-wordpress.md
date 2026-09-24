# 05 · Pipeline GitHub → WordPress

## 1. Principe

Le **code** (thème + plugin) est la seule chose versionnée. Il est déployé sur WordPress.com par la
fonctionnalité **GitHub Deployments** du plan Business (hébergement Atomic). Le **contenu** (œuvres,
chapitres, articles, comptes) vit dans la base WordPress et est manipulé par des scripts (WP-CLI/REST),
jamais par Git.

```
GitHub (GNAlexandre/Yume-WordPress)
   ├─ push sur develop ──▶ GitHub Actions (lint, tests, build) ──▶ WordPress.com Deployments ──▶ staging.yumenovel.fr (site de staging)
   └─ merge sur main   ──▶ GitHub Actions (lint, tests, build) ──▶ WordPress.com Deployments ──▶ yumenovel.fr (production)
```

## 2. Arborescence du dépôt

```
Yume-WordPress/
├── .github/workflows/
│   ├── ci.yml                 ← PHPCS (WordPress Coding Standards), PHPUnit, ESLint, build Vite, Playwright
│   └── wpcom.yml              ← workflow généré par WordPress.com (mode « avancé »), publie l'artefact wpcom
├── wp-content/
│   ├── plugins/yume-core/     ← plugin métier (PHP 8.2, composer, tests)
│   └── themes/yume/           ← thème bloc (theme.json, templates, parts, patterns, assets)
├── tools/
│   ├── migrate/               ← scripts d'analyse de l'existant (export REST → CSV de contrôle)
│   └── playground/            ← blueprint WordPress Playground pour tester sans serveur
├── docs/                      ← ce dossier
├── design/                    ← tokens, notes de design, export des maquettes
├── .wp-env.json               ← environnement local (Docker) : wp-env avec plugin + thème montés
├── composer.json · package.json
└── README.md
```

## 3. Mise en place (phase 0)

1. **Plan Business** puis transfert automatique vers Atomic (Outils → Hébergement). Pas d'interruption,
   domaine inchangé.
2. **Site de staging** : Tableau de bord → Staging site → Créer (copie de la production, URL
   `*.wpcomstaging.com`, indexation désactivée).
3. **GitHub Deployments** (Tableau de bord → Déploiements) :
   - Production : dépôt `GNAlexandre/Yume-WordPress`, branche `main`, répertoire cible `/wp-content`,
     mode avancé (workflow `wpcom.yml`), déploiement automatique.
   - Staging : même dépôt, branche `develop`.
   - Le workflow construit les assets (`npm ci && npm run build`), installe les dépendances PHP
     (`composer install --no-dev`) et publie l'artefact `wpcom` contenant uniquement
     `wp-content/plugins/yume-core` et `wp-content/themes/yume`.
4. **Secrets** : aucun dans le dépôt. Les webhooks Discord et jetons sont saisis dans
   *Réglages → Yume* (options WordPress chiffrées) sur chaque site.
5. **Protection de `main`** : PR obligatoire, CI verte, 1 relecture.

## 4. Environnement local

- `npm run env:start` (wp-env) monte le plugin et le thème dans un WordPress Docker local avec des
  données de démonstration (`tools/fixtures/`).
- `npm run playground` ouvre un WordPress Playground dans le navigateur (sans Docker) pour une revue rapide.
- `wp yume migrate --dry-run --source=export.xml` rejoue la migration sur un export de production.

## 5. Contenu : migration et synchronisation

| Besoin | Outil | Quand |
| --- | --- | --- |
| Rejouer la migration sur staging | `wp yume migrate --dry-run` via SSH (Business) | Phases 1 à 6 |
| Copier la production vers le staging | « Sync production → staging » (WordPress.com) | Avant chaque campagne de test |
| Bascule finale | GitHub Deployments (code) **puis** `wp yume migrate` en production (contenu) | Jour J |
| Publications quotidiennes | Formulaire `/equipe/publier/` ou `POST /yume/v1/publications` | Après la v2 |

On **n'utilise pas** « Sync staging → production » pour la base de données : cela écraserait les
commentaires, inscriptions et articles publiés entre-temps. Le script de migration, idempotent, joue le
même rôle sans perte.

## 6. Qualité

- CI : `phpcs` (WPCS), `phpstan` niveau 5, `phpunit` (convertisseurs, planning, rappels), `eslint`,
  `vitest` (lecteur), `playwright` (parcours : lire un chapitre, mettre à jour le planning, publier).
- Jeux d'essai : `tools/fixtures/grimgar-t7.docx` (anonymisé ou extrait de 2 chapitres) + un EPUB.
- Versionnage sémantique du plugin (`YUME_CORE_VERSION`), migrations de schéma via `dbDelta` à l'activation
  et à la montée de version.
