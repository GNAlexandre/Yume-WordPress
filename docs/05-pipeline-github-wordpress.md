# 05 · Pipeline GitHub → WordPress (plan actuel, sans GitHub Deployments)

## 1. Principe

Le **code** (thème + plugin) est la seule chose versionnée. Le plan actuel n'offre ni SSH ni GitHub
Deployments (réservés au plan Business), mais il permet de téléverser un plugin et un thème maison.
Le pipeline repose donc sur deux mécanismes standards de WordPress :

1. **Releases GitHub → mises à jour WordPress.** Le plugin embarque la bibliothèque
   [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (licence MIT) pointée
   sur le dépôt. Chaque release taguée `v2.x.y` avec ses zips attachés apparaît dans
   *Extensions → Mises à jour* comme n'importe quelle mise à jour ; l'auto-mise à jour peut être
   activée. Même chose pour le thème.
2. **API REST → contenu.** Le convertisseur DOCX existe aussi en ligne de commande
   (`tools/docx2chapters`, PHP) et publie via `POST /yume/v1/publications` avec un mot de passe
   d'application. Il peut tourner dans GitHub Actions (dépôt privé de traductions) ou dans Yume-Trad.

```
GitHub (GNAlexandre/Yume-WordPress)
   ├─ push sur develop ──▶ GitHub Actions : lint, tests, build ─▶ artefacts de test (zips) ─▶ préprod locale
   └─ tag v2.x.y sur main ─▶ GitHub Actions : build ─▶ Release GitHub (yume-core.zip, yume.zip)
                                                          │
                       yumenovel.fr ◀── vérification toutes les 12 h ── Plugin Update Checker
```

## 2. Arborescence du dépôt

```
Yume-WordPress/
├── .github/workflows/
│   ├── ci.yml                 ← PHPCS (WordPress Coding Standards), PHPUnit, ESLint, build Vite, Playwright
│   └── release.yml            ← sur tag v* : build, zips, release GitHub avec les deux archives
├── wp-content/
│   ├── plugins/yume-core/     ← plugin métier (PHP 8.2, composer, tests, updater GitHub)
│   └── themes/yume/           ← thème bloc (theme.json, templates, parts, patterns, assets, updater GitHub)
├── tools/
│   ├── docx2chapters/         ← convertisseur en ligne de commande + client REST
│   ├── migrate/               ← scripts d'analyse de l'existant (export REST → CSV de contrôle)
│   └── playground/            ← blueprint WordPress Playground (préprod dans le navigateur)
├── docs/ · design/
├── .wp-env.json               ← préprod locale Docker avec plugin + thème montés
├── composer.json · package.json
└── README.md
```

## 3. Mise en place (phase 0)

1. **Transfert Atomic** : installer la première extension (UpdraftPlus) depuis *Extensions → Ajouter* ;
   WordPress.com transfère le site automatiquement (quelques minutes, URL inchangée).
2. **Sauvegardes** : UpdraftPlus vers Google Drive (hebdomadaire + avant chaque mise à jour) et
   export XML *Outils → Exporter* conservé hors ligne.
3. **Première installation** : *Extensions → Ajouter → Téléverser* `yume-core.zip`, puis
   *Apparence → Thèmes → Téléverser* `yume.zip` (sans l'activer avant le jour J).
4. **Mots de passe d'application** : un par intégration (Yume-Trad, bot Discord, GitHub Actions),
   créé depuis le profil d'un compte technique au rôle `yume_editeur`.
5. **Secrets** : aucun dans le dépôt. Webhook Discord et jetons saisis dans *Réglages → Yume*.
6. **Protection de `main`** : PR obligatoire, CI verte, 1 relecture ; les releases sont créées par
   le workflow, jamais à la main.

## 4. Préproduction sans site de staging

- `npm run env:start` (wp-env) monte le plugin et le thème dans un WordPress Docker local ; le
  script `npm run env:seed` importe l'export XML de production et la médiathèque pour rejouer la
  migration sur les vraies données.
- `npm run playground` ouvre un WordPress Playground dans le navigateur (sans Docker) pour une revue
  rapide par l'équipe : le blueprint installe les zips de la dernière release de test.
- La page *Yume → Migrer* en mode **Simuler** produit le rapport sans rien écrire ; on la rejoue
  jusqu'à un rapport propre avant le jour J.

## 5. Contenu : migration et publications

| Besoin | Outil | Quand |
| --- | --- | --- |
| Rejouer la migration | *Yume → Migrer → Simuler* (préprod locale) | Phases 1 à 6 |
| Bascule finale | Release `v2.0.0` (code) **puis** *Yume → Migrer → Exécuter* en production (contenu) | Jour J |
| Publication d'un tome | Formulaire `/equipe/publier/` (DOCX déposé) | Après la v2 |
| Publication sans clic | `tools/docx2chapters publish grimgar-t10.docx --oeuvre=grimgar --tome=10` depuis GitHub Actions ou Yume-Trad | Option |

Le DOCX déposé n'est jamais conservé : il est analysé en mémoire, les chapitres et les illustrations
sont écrits, puis le fichier temporaire est supprimé. Les PDF et EPUB ne transitent jamais par le site.

## 6. Qualité

- CI : `phpcs` (WPCS), `phpstan` niveau 5, `phpunit` (convertisseur, planning, rappels, migration),
  `eslint`, `vitest` (lecteur), `playwright` (parcours : lire un chapitre, mettre à jour le planning,
  publier un tome).
- Jeux d'essai : deux chapitres anonymisés de `grimgar-t7.docx` dans `tools/fixtures/`.
- Versionnage sémantique du plugin (`YUME_CORE_VERSION`), migrations de schéma via `dbDelta` à
  l'activation et à chaque montée de version.
