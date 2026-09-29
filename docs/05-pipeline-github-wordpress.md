# 05 · Pipeline GitHub → WordPress (plan actuel, sans GitHub Deployments)

## 1. Principe

Le **code** (thème + plugin) est la seule chose versionnée. Le plan actuel n'offre ni SSH ni GitHub
Deployments (réservés au plan Business), mais il permet de téléverser un plugin et un thème maison.
Le pipeline repose donc sur deux mécanismes standards de WordPress :

1. **Releases GitHub → mises à jour WordPress.** Le plugin embarque la bibliothèque
   [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (licence MIT) pointée
   sur le dépôt. Chaque release taguée `v2.x.y` avec ses zips attachés apparaît dans
   *Extensions → Mises à jour* comme n'importe quelle mise à jour ; l'auto-mise à jour peut être
   activée. Même chose pour le thème. Avant toute installation (manuelle ou automatique), le plugin
   vérifie l'empreinte SHA-256 de l'archive contre le `SHA256SUMS` de la même release (§7).
2. **API REST → contenu.** Le convertisseur DOCX existe aussi en ligne de commande
   (`tools/docx2chapters`, PHP) et publie via `POST /yume/v1/publications` avec un mot de passe
   d'application. Il peut tourner dans GitHub Actions (dépôt privé de traductions) ou dans Yume-Trad.

```
GitHub (GNAlexandre/Yume-WordPress)
   ├─ push sur develop ──▶ GitHub Actions : lint, tests, build ─▶ artefacts de test (zips) ─▶ préprod locale
   └─ tag v2.x.y sur main ─▶ GitHub Actions : CI, approbation (env. release), build
                                  ─▶ Release GitHub (yume-core.zip, yume.zip, SHA256SUMS)
                                                          │
                       yumenovel.fr ◀── vérification toutes les 12 h ── Plugin Update Checker
                                        (installation seulement si SHA-256 = SHA256SUMS)
```

## 2. Arborescence du dépôt

```
Yume-WordPress/
├── .github/workflows/
│   ├── ci.yml                 ← php -l (PHP 8.1 à 8.4), PHPCS (non bloquant), tests WordPress (SQLite), archives zip
│   └── release.yml            ← sur tag v* : vérifie les versions, CI, approbation (env. release), zips + SHA256SUMS, release
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
   le workflow, jamais à la main. Liste complète des réglages GitHub : §7.

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

## 7. Sécurité de la chaîne de publication (SEC-01)

Une release publiée sur le dépôt est installée sur yumenovel.fr dans les 12 heures, automatiquement
si *Mises à jour automatiques* est coché : pouvoir publier une release, c'est pouvoir exécuter du
code sur le site. Deux protections se complètent.

### 7.1 Côté site : vérification d'intégrité (yume-core)

`includes/updater/integrite.php` s'accroche à `upgrader_pre_download` et ne concerne que le plugin
`yume-core` et le thème `yume` (les autres extensions et thèmes ne sont pas touchés). Pour chaque
mise à jour, manuelle ou automatique (même `WP_Upgrader`) :

1. l'URL de l'archive doit être un asset d'une release du dépôt configuré (`YUME_GITHUB_REPO` ou
   réglage `github_repo`), nommé exactement `yume-core.zip` ou `yume.zip` ;
2. le tag de la release doit valoir `v` + la version proposée ;
3. le plugin télécharge lui-même `SHA256SUMS` puis l'archive de **la même release**, en HTTPS, en
   suivant les redirections une à une : seuls `github.com`, `api.github.com`,
   `objects.githubusercontent.com` et `release-assets.githubusercontent.com` sont acceptés ; le
   jeton `YUME_GITHUB_TOKEN` n'est envoyé qu'à `api.github.com` ;
4. l'empreinte SHA-256 de l'archive doit être celle listée dans `SHA256SUMS`.

Sinon la mise à jour est refusée (message « Mise à jour … refusée : … » dans *Mises à jour*, ligne
`[yume-core]` dans le journal PHP, action `yume_updater_refus`). Cas refusés : `SHA256SUMS` absent
ou illisible, archive absente de `SHA256SUMS`, empreinte différente, tag différent de la version,
autre dépôt, redirection vers un autre hôte, échec de téléchargement.

Désactivation de secours seulement (déconseillée) : `define( 'YUME_EXIGER_EMPREINTE', false );` dans
`wp-config.php`, ou filtre `yume_updater_exiger_empreinte`. Défaut : vérification exigée.

Limite : `SHA256SUMS` est publié par le même workflow que les archives ; il protège contre une
archive altérée ou remplacée après coup, pas contre un workflow compromis. D'où les réglages
GitHub ci-dessous (et, plus tard, une signature minisign/ed25519 de `SHA256SUMS` avec la clé
publique embarquée dans le plugin).

### 7.2 Côté GitHub : réglages à appliquer par le propriétaire du dépôt

Ce sont des réglages du dépôt (pas du code) : à cocher une fois, puis à vérifier à chaque
changement d'équipe.

- [ ] **2FA obligatoire** pour tous les comptes ayant un accès en écriture (propriétaire,
      collaborateurs ; *Settings → Authentication security* d'une organisation : « Require
      two-factor authentication »). Clés de sécurité ou application TOTP, pas de SMS.
- [ ] **Environnement `release`** (*Settings → Environments → New environment* « release ») :
      *Required reviewers* = au moins une personne habilitée à donner le Go (idéalement autre que
      l'auteur du tag, « Prevent self-review ») ; *Deployment branches and tags* = « Selected »,
      règle de tag `v*`. Le job « Release GitHub » de `release.yml` attend cette approbation.
- [ ] **Ruleset de tags** (*Settings → Rules → Rulesets → New tag ruleset*) ciblant `v*`, actif :
      *Restrict creations*, *Restrict updates*, *Restrict deletions*, *Block force pushes* ; liste
      de contournement limitée au(x) mainteneur(s) qui publient.
- [ ] **Protection de la branche par défaut** (`main`, ruleset de branche) : PR obligatoire,
      1 relecture approuvée, relecture invalidée par un nouveau push, checks CI requis
      (Syntaxe, Tests), pas de force push ni de suppression, historique linéaire conseillé.
- [ ] **Actions** (*Settings → Actions → General*) : *Workflow permissions* = « Read repository
      contents » (lecture seule par défaut ; `release.yml` n'accorde `contents: write` qu'au job
      `publier`), « Allow GitHub Actions to create and approve pull requests » décoché ; actions
      autorisées limitées à GitHub et aux créateurs vérifiés (ou liste explicite).
- [ ] **Releases** : ne jamais créer ni modifier une release ou ses assets à la main ; pas de
      jeton personnel à droits d'écriture stocké en secret (le `GITHUB_TOKEN` du workflow suffit).
- [ ] **Sécurité du code** (*Settings → Code security*) : Dependabot alerts et security updates,
      secret scanning et push protection activés.
- [ ] **Côté site** : `YUME_GITHUB_REPO` figé dans `wp-config.php` en production ;
      `YUME_GITHUB_TOKEN` (si dépôt privé) en lecture seule (fine-grained, *Contents: read*,
      ce seul dépôt).
