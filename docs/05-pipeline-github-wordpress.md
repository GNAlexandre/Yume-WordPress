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
│   ├── ci.yml                 ← php -l (PHP 8.1 à 8.4), PHPCS (non bloquant), tests WordPress (SQLite), archives zip
│   └── release.yml            ← sur tag v* : vérifie les versions, construit les zips, crée la release GitHub
├── wp-content/
│   ├── plugins/yume-core/     ← plugin métier (modules includes/<module>/, tests/, lib/plugin-update-checker)
│   └── themes/yume/           ← thème bloc (theme.json, templates, parts, patterns, assets)
├── tools/
│   ├── build/                 ← zip.sh (dist/yume-core.zip, dist/yume.zip), lint.sh
│   ├── localenv/              ← setup.sh, wp.sh, test.sh, serve.sh : WordPress local SQLite + WP-CLI
│   ├── playground/            ← blueprints WordPress Playground (release ou branche) et contenu de démo
│   ├── docx2chapters/         ← convertisseur DOCX → chapitres en ligne de commande + client REST
│   ├── migrate/               ← export (lecture seule), plan de migration, seed local
│   └── fixtures/              ← fichiers DOCX/EPUB synthétiques (private/ ignoré par git)
├── docs/ · design/
├── phpcs.xml.dist
└── README.md
```

## 3. Mise en place (phase 0, après le Go)

1. **Transfert Atomic** : installer la première extension (UpdraftPlus) depuis *Extensions → Ajouter* ;
   WordPress.com transfère le site automatiquement (quelques minutes, URL inchangée).
2. **Sauvegardes** : UpdraftPlus vers Google Drive + export XML *Outils → Exporter* conservé hors ligne.
3. **Première installation** : *Extensions → Ajouter → Téléverser* `yume-core.zip`, puis
   *Apparence → Thèmes → Téléverser* `yume.zip` (activé seulement au jour J).
4. **Mots de passe d'application** : un par intégration (Yume-Trad, bot Discord, GitHub Actions),
   créé depuis le profil d'un compte technique au rôle `yume_editeur`.
5. **Secrets** : aucun dans le dépôt. Webhooks Discord saisis dans *Yume → Réglages*.
6. **Protection de `main`** : PR obligatoire, CI verte, 1 relecture ; les releases sont créées par
   le workflow, jamais à la main.

## 4. Environnement local et préproduction

- `tools/localenv/setup.sh` installe un WordPress complet sans MySQL ni Docker (SQLite, WP-CLI,
  plugin et thème du dépôt liés). `YUME_ENV=<nom>` choisit une base isolée ;
  `tools/localenv/test.sh [module]` lance les tests ; `tools/localenv/serve.sh` sert le site.
  Détails : `tools/localenv/README.md`.
- Préproduction : `tools/migrate/seed-local.php` reconstitue l'ancien site dans une base locale à partir
  de l'export, puis *Yume → Migrer → Simuler / Exécuter* rejoue la migration sur les vraies données.
- `tools/playground/lancer.sh` (ou `lancer.ps1`) lance le site en local avec WordPress Playground, sur le
  clone, avec seulement Node.js ; `blueprint-branche.json` ouvre une démo en ligne sans release.
  Guide : `docs/tester-en-local.md`.

## 5. Contenu : migration et publications

| Besoin | Outil | Quand |
| --- | --- | --- |
| Rejouer la migration | *Yume → Migrer → Simuler* (préproduction locale) | Avant le Go |
| Bascule finale | Release `v2.0.0` (code) **puis** *Yume → Migrer → Exécuter* en production (contenu) | Jour J, après le Go |
| Publication d'un tome | Formulaire `/equipe/publier/` (DOCX déposé) | Après la v2 |
| Publication sans clic | `tools/docx2chapters` vers l'API REST (mot de passe d'application) | Option |

Le DOCX déposé n'est jamais conservé : il est analysé, les chapitres et les illustrations sont écrits,
puis le fichier temporaire est supprimé. Les PDF et EPUB ne transitent jamais par le site.

## 6. Qualité

- Tests : mini-framework sans dépendance (`wp-content/plugins/yume-core/tests/`, `yume_test()`),
  exécuté par WP-CLI dans une transaction annulée ; un fichier par module.
- CI (`.github/workflows/ci.yml`) : `php -l` sur PHP 8.1 à 8.4, PHPCS WordPress-Extra (non bloquant
  tant que les écarts restants ne sont pas corrigés), tests dans un WordPress fr_FR sur SQLite,
  construction des archives.
- Versionnage sémantique (`YUME_CORE_VERSION`, en-tête du thème) ; migrations de schéma via `dbDelta`
  à l'activation et à chaque montée de version.
