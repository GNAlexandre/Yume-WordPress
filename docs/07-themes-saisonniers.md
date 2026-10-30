# Thèmes saisonniers — cahier des charges (2.7.0)

Le site change d'habillage selon la période de l'année : **Printemps**, **Été**, **Automne**,
**Hiver**, et deux fêtes qui l'emportent sur la saison : **Halloween** (octobre) et **Noël**
(décembre). Un seul thème WordPress (`yume`), une seule feuille de style : le thème saisonnier
est une **variante** du thème « Crépuscule sakura », programmée et pilotée depuis les réglages de
l'espace équipe. Ce document est le contrat partagé des trois lots (plugin, thème, documentation).

## 1. Décision : un seul thème, une variante par saison

| Option | Verdict |
| --- | --- |
| Un thème WordPress par saison, activé par cron (`switch_theme`) | **Rejeté** : six copies du thème à maintenir et à publier, perte des réglages du personnalisateur (logo, menus) à chaque bascule, mise à jour par release impossible (le site n'installe qu'un `yume.zip`), cache de page et service worker à gérer six fois. |
| Un thème, une variante CSS choisie par le serveur | **Retenu** : la variante est un attribut `data-yn-saison` sur `<html>`, posé par PHP ; les palettes vivent dans `yume.css` à côté des thèmes Papier et Sépia ; le logo et les décors sont des fichiers du thème ; la programmation est un réglage (`yume_reglages`) affiché dans *Yume → Réglages* et dans `/equipe/?vue=reglages`. |

La saison est une **deuxième dimension**, indépendante du thème de couleurs choisi par le lecteur :

| | Nuit (défaut) | Papier | Sépia |
| --- | --- | --- | --- |
| Base (aucune saison) | palette actuelle | palette actuelle | palette actuelle |
| Saison `s` | palette `s` sombre | palette `s` claire | **inchangé** (thème de confort de lecture) |

Printemps **est** la palette actuelle (« Crépuscule sakura » : cerisiers au couchant) : seule
l'identité visuelle (logo) change ; aucune variable n'est redéfinie.

## 2. Vocabulaire et identifiants (figés)

| Slug | Nom affiché | Type | Logo (thème, `assets/img/saisons/`) |
| --- | --- | --- | --- |
| `printemps` | Printemps | saison | `printemps.webp` (logo actuel du site) |
| `ete` | Été | saison | `ete.webp` |
| `automne` | Automne | saison | `automne.webp` |
| `hiver` | Hiver | saison | `hiver.webp` |
| `halloween` | Halloween | fête | `halloween.webp` |
| `noel` | Noël | fête | `noel.webp` |
| `''` (vide) | Thème de base | — | logo du personnalisateur (`custom_logo`) |

Attribut HTML : `<html data-yn-theme="nuit|papier|sepia" data-yn-saison="<slug>">` ; sans saison
active, l'attribut `data-yn-saison` est **absent**. Classe de `body` : `yn-saison-<slug>`.

## 3. Calendrier par défaut et règle de résolution

| Ligne (ordre de priorité) | Début | Fin | Active |
| --- | --- | --- | --- |
| Noël (`noel`) | 01/12 | 31/12 | oui |
| Halloween (`halloween`) | 01/10 | 31/10 | oui |
| Printemps (`printemps`) | 20/03 | 20/06 | oui |
| Été (`ete`) | 21/06 | 21/09 | oui |
| Automne (`automne`) | 22/09 | 20/12 | oui |
| Hiver (`hiver`) | 21/12 | 19/03 | oui |

- Dates « JJ/MM » sans année, bornes incluses, fuseau du site (`wp_timezone()`, Europe/Paris).
- Une période qui **chevauche le Nouvel An** (début > fin, ex. Hiver 21/12 → 19/03) est valide.
- Résolution : la **première ligne active** dont la période contient la date gagne (les fêtes sont
  avant les saisons) ; aucune ligne → thème de base.
- Les six lignes sont fixes (ni ajout ni suppression) : on modifie leurs dates ou on les désactive.

## 4. Réglages (option `yume_reglages`, section `saisons` « Thème saisonnier »)

Déclarés par le module `saisons` via les filtres `yume_reglages_sections` et `yume_reglages_champs`
(contrat §6). Capacité : `yume_reglages` (gérants et administrateurs), comme toute la section.

| Clé | Type | Défaut | Rôle |
| --- | --- | --- | --- |
| `saison_mode` | `select` : `auto` « Selon le calendrier », `manuel` « Thème choisi ci-dessous », `aucun` « Thème de base toute l'année » | `auto` | Mode de pilotage |
| `saison_manuelle` | `select` : `''` « Thème de base » + les six slugs | `''` | Thème forcé quand `saison_mode` = `manuel` |
| `saison_calendrier` | **nouveau type de champ `saisons`** : tableau de six lignes `{theme, debut, fin, actif}` | tableau du §3 | Programmation |
| `saison_decor` | `checkbox` | `true` | Décors animés légers (pétales, feuilles, flocons…) dans l'en-tête ; jamais si `prefers-reduced-motion` ou `yn.a11y.animations = 'reduites'` |

Le type `saisons` est rendu dans `Core\afficher_champ()` (administration) et dans
`Planning\champ_reglage_equipe()` (espace équipe) : une table, une ligne par thème, colonnes
« Thème », « Du (JJ/MM) », « Au (JJ/MM) », « Actif », et un lien « Aperçu » par ligne. Sous la
table : « Aujourd'hui : *Automne* (calendrier) — prochain changement : *Noël* le 01/12 ».
Assainissement `Saisons\assainir_calendrier()` : date invalide → valeur précédente de la ligne et
avertissement `add_settings_error`, ligne inconnue ignorée, ordre de priorité figé.

**Aperçu sans changer le site** : `?yn-saison=<slug|aucun>` sur n'importe quelle page de façade,
pour un compte ayant `yume_reglages` (sinon paramètre ignoré). Quand le paramètre est honoré, la
page n'entre jamais dans le cache de page (`batcache_cancel()` appelé explicitement).

## 5. API PHP partagée (module `saisons`, `includes/saisons/api.php`, contrat §7)

```php
yume_saisons(): array                 // slug => ['nom' => 'Automne', 'type' => 'saison'|'fete', 'logo' => 'automne.webp']
yume_saison_active(): string          // slug actif pour la requête courante ('' = base) ; filtre final yume_saison_active
yume_saison_calendrier(): array       // les six lignes résolues (réglage fusionné avec les défauts)
yume_saison_pour_date( DateTimeInterface $date, ?array $calendrier = null ): string
yume_saison_nom( string $slug ): string
```

`yume_saison_active()` : aperçu `?yn-saison` (capacité) > `saison_mode` (`aucun` → `''`, `manuel`
→ `saison_manuelle`) > calendrier à la date courante. Résultat mémorisé pour la requête.

Événement : **`yume_saison_changee( string $nouvelle, string $ancienne )`**, émis quand la saison
calculée diffère de l'option `yume_saison_courante` (vérifié à l'enregistrement des réglages et par
la tâche cron quotidienne `yume_saisons_quotidien`, 00:05 heure du site). Le module y accroche la
purge des caches (§7) ; le module planning pourra y accrocher plus tard une annonce Discord.

## 6. Thème (`wp-content/themes/yume`)

- `inc/saisons.php` : attribut `data-yn-saison` (filtre `language_attributes`, après le thème de
  couleurs), classe de `body`, logo saisonnier (filtre `get_custom_logo` : même balise `<a><img>`
  que WordPress, `src` = fichier du thème versionné, `alt` = nom du site, dimensions 44 px comme le
  bloc `site-logo` de l'en-tête), couleur `theme-color` par saison. Tout derrière
  `function_exists( 'yume_saison_active' )` : sans le plugin, le thème est le thème de base.
- `assets/css/yume.css`, section « 1 bis. Thèmes saisonniers » : pour chaque saison sauf
  `printemps`, `html[data-yn-saison="s"][data-yn-theme="nuit"]:not([data-yn-contraste])` (Nuit) et
  `html[data-yn-saison="s"][data-yn-theme="papier"]:not([data-yn-contraste])` (le contraste renforcé du lecteur
  l'emporte ; Sépia jamais touché)
  (Papier) redéfinissent **seulement** `fond`, `bande`, `carte`, `filet`, `bordure`, `accent`,
  `accent-texte`, `accent-2`, `selection`, `--yn-accent-survol`, `--yn-degrade-couverture`. Les
  encres (`texte`, `texte-fort`, `texte-faible`) et les couleurs d'état ne changent pas. Contrastes
  mesurés : 4,5:1 pour `texte` sur `fond`, `bande` et `carte` ; 3:1 pour `bordure` et pour
  `accent` sur `fond` ; `accent-texte` 4,5:1 sur `accent`. Palettes de départ ; valeurs **finales** (trois couleurs Papier assombries :
  été `accent-2` #985f19, automne `accent` #ae4e19, halloween `accent` #b24a0b ; survol et dégradés par saison)
  dans `docs/06-contrat-technique.md` §15 :

| Saison | Nuit : fond / bande / carte / filet / bordure / accent / accent-texte / accent-2 / selection | Papier : fond / bande / carte / filet / bordure / accent / accent-texte / accent-2 / selection |
| --- | --- | --- |
| `ete` (ciel et mer) | #0f1f33 / #142a44 / #1b3556 / #2f4f78 / #6f93c2 / #7fd3ff / #0b1b2c / #ffd27a / #1f3f66 | #f3f9fd / #e6f2fb / #ffffff / #cfe3f2 / #5a86b0 / #0f6fb3 / #ffffff / #a0641a / #e3f0fa |
| `automne` (érables) | #221410 / #2d1a14 / #3a231b / #5a3a2c / #a8705a / #ff9c4a / #2a1208 / #ffd08a / #4a2b20 | #fdf6ef / #f8ebdd / #ffffff / #ecd7c3 / #9a6a4a / #b7521a / #ffffff / #8a5a14 / #f7e6d6 |
| `halloween` (nuit violette, citrouille) | #170f26 / #211537 / #2b1b47 / #4a3270 / #8a66b8 / #ff9a3c / #1b0f2a / #c9a3ff / #3a2360 | #fbf5ff / #f3e8fb / #ffffff / #e3d3f0 / #7b5aa3 / #c2500c / #ffffff / #6a3fb0 / #f1e4f9 |
| `hiver` (neige, glace) | #141c2b / #1b2537 / #243247 / #3b4d68 / #7f98b8 / #bfe3ff / #0f1b2a / #e8d9c4 / #2c3d57 | #f6f9fc / #e9f0f7 / #ffffff / #d6e1ec / #647e98 / #2f6ea5 / #ffffff / #7a6248 / #e6eef6 |
| `noel` (sapin, or, houx) | #10211a / #162b22 / #1e3a2e / #335a48 / #7fae93 / #f7c56b / #1b2a1f / #ff9c9c / #2a4d3b | #f6fbf7 / #e7f3ea / #ffffff / #cfe4d5 / #4f7f62 / #b32d2d / #ffffff / #8a6414 / #e4f1e8 |

- Décors (`assets/js/saisons.js`, `assets/css/yume.css`) : quelques particules SVG en CSS
  (pétales au printemps, feuilles en automne, flocons en hiver et à Noël, chauves-souris à
  Halloween, rien en été) dans la bande d'en-tête uniquement, `pointer-events: none`,
  `aria-hidden`, au plus 12 éléments, coupés par `@media (prefers-reduced-motion: reduce)`, par
  `yn.a11y.animations = 'reduites'` et par le réglage `saison_decor`. Sans JavaScript : rien.
- Logos : six fichiers WebP 320 px de large (`assets/img/saisons/<slug>.webp`) convertis depuis les
  visuels fournis ; `noel.webp` reçoit le visuel de Noël dès qu'il est déposé (en attendant, copie
  d'`hiver.webp`, signalée dans le CHANGELOG).
- `readme.txt` du thème et `design/README.md` : palettes documentées.

## 7. Cache : garantir que les visiteurs voient le changement

| Couche | Problème | Réponse |
| --- | --- | --- |
| Cache navigateur des CSS/JS | `?ver=` = version du thème seulement ; un correctif de CSS sans montée de version resterait invisible | `yume_theme_version_fichier()` ajoute **toujours** l'empreinte du fichier (`filemtime` en hexadécimal) à la version : `2.7.0-1a2b3c4d`. Toute modification du fichier change l'URL. Même règle pour les logos saisonniers (`?v=`). |
| Cache de page WordPress.com (Batcache) | La page en cache porte l'ancien `data-yn-saison` | Sur `yume_saison_changee` : `batcache_clear_url()` (si présent) sur l'accueil, la bibliothèque, le planning, les actualités, les pages `yume_pages` et les archives d'œuvres, puis `wp_cache_flush()`. Expiration naturelle (5 min) pour le reste. L'aperçu `?yn-saison` appelle `batcache_cancel()`. |
| Service worker (lecture hors ligne) | Les CSS sont servis « cache d'abord, mise à jour en arrière-plan » | Les palettes sont dans `yume.css` et changent de version à chaque release : `YUME_CORE_VERSION` change le nom des caches, les anciens sont supprimés à l'activation. Entre deux releases, la bascule de saison n'exige **aucun** nouveau fichier : l'attribut HTML suffit (les pages de lecture sont « réseau d'abord »). |
| Cache HTML du navigateur | — | Inchangé : WordPress n'envoie pas de `Cache-Control` long sur le HTML. |

## 8. Lots

| Lot | Périmètre | Fichiers | Tests |
| --- | --- | --- | --- |
| **A — Plugin** | Module `saisons` (réglages, type de champ `saisons`, API, résolution, cron, purge, aperçu), déclaration dans `yume-core.php` | `includes/saisons/*`, `includes/core/settings.php` (type `saisons`), `includes/planning/reglages-equipe.php` (type `saisons`), `tests/test-saisons.php` | `tools/localenv/test.sh saisons`, suite complète, PHPCS |
| **B — Thème** | Attribut, classes, logo, palettes, décors, versionnement des assets | `themes/yume/inc/saisons.php`, `assets/css/yume.css`, `assets/js/saisons.js`, `assets/img/saisons/*`, `inc/assets.php`, `functions.php`, `readme.txt` | lint, PHPCS, contrôle de contraste, `tools/ci/rendu.js` inchangé (base sans saison) |
| **C — Documentation** | Guide de l'équipe (`.md` et `procedures.md` + DOCX), contrat §6/§7/§8/§15, design, journal des décisions, README | `docs/*`, `design/README.md` | `node tools/guide/construire.js --verifier` |
| **Intégration** | Version 2.7.0-dev.N, CHANGELOG, suite complète, blueprints Playground | `tools/build/version.php` | CI |

## 9. Hors périmètre (décisions à prendre plus tard)

- Bannière d'accueil saisonnière (`banniere_id` par saison) : non demandé ; possible en ajoutant
  un champ `media` par ligne du calendrier.
- Annonce Discord au changement de saison : point d'accroche prêt (`yume_saison_changee`).
- Favicon saisonnier : non.
