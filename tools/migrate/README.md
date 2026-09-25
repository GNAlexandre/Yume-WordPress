# Migration de yumenovel.fr — partie 1 : export et plan

Ce dossier contient l'**analyse** de l'ancien site (WordPress.com, site `238001312`) et le **plan**
de migration vers les types Yume (contrat technique, docs/06-contrat-technique.md). Rien ici
n'écrit sur le site distant ni dans la base locale : l'export est obtenu en **lecture seule**,
le plan est calculé par des classes PHP pures (`wp-content/plugins/yume-core/includes/migration/`).

> Rien n'est poussé sur yumenovel.fr sans le « Go » explicite de l'équipe (contrat §0bis). La
> partie 2 (exécution du plan) lit `plan.json` ; elle ne doit jamais relancer l'analyse sur le
> site distant.

## Contenu

| Chemin | Rôle | Versionné |
| --- | --- | --- |
| `export/` | Export complet de l'ancien site (JSON) + `raw/` (réponses brutes du connecteur) + sorties du plan | **non** (`.gitignore`) |
| `fixtures/` | Extraits courts de l'export (jeu d'essai des tests) | oui |
| `assemble-export.php` | `export/raw/*.json` → `export/{pages,posts,categories,media,navigations,template-parts,site}.json` | oui |
| `build-fixtures.php` | `export/` → `fixtures/` (extraits courts) | oui |
| `plan.php` | `export/` → `export/plan.json`, `export/rapport.md`, `export/redirections.csv` | oui |

## 1. Régénérer l'export (lecture seule)

L'export se fait avec le connecteur **WordPress.com** (outils `wpcom-mcp-content-authoring` et
`wpcom-mcp-site-editing`, action `execute`), **uniquement** avec des opérations `list` / `get`
(jamais `create`, `update`, `delete`… ni `user_confirmed`). Chaque réponse est enregistrée dans
`export/raw/<opération>__<clé>.json` :

```json
{ "operation": "pages.get", "tool": "wpcom-mcp-content-authoring", "params": { … }, "response": { "data": … } }
```

(`<clé>` = l'ID pour un `get`, sinon `p<page>-<empreinte des paramètres>` ou une empreinte.)

| Opération | Paramètres utilisés |
| --- | --- |
| `pages.list` | `per_page: 100, page: 1, status: [publish, draft, private, pending, future], orderby: id, order: asc, include_fields: [id, slug, status, date, modified, link, author, parent, menu_order, featured_media, title]` |
| `pages.get` (une par page) | `id, context: edit, include_fields: [id, slug, status, date, modified, link, parent, menu_order, template, featured_media, title, content, excerpt]` → contenu **brut** (commentaires de blocs) |
| `posts.list` (pages 1 à 2) | mêmes statuts, `include_fields: [id, slug, status, date, modified, link, author, categories, tags, featured_media, title, excerpt]` |
| `posts.get` (un par article) | `id, context: edit, include_fields: [id, content]` |
| `categories.list` | `per_page: 100` |
| `media.list` (pages 1 à 5) | `per_page: 100, orderby: id, order: asc, include_fields: [id, date, mime_type, source_url, title, post, media_details, alt_text]` |
| `navigation.list` (site-editing) | `per_page: 100` |
| `template-parts.list` (site-editing) | — |

`pages.list` / `posts.list` ne renvoient pas le contenu : il faut un `get` par élément. Les
réponses volumineuses (chapitres de ~15 ko) sont enregistrées telles quelles.

Puis :

```sh
php tools/migrate/assemble-export.php          # export/raw → export/*.json
```

Le script fusionne `list` et `get`, décode les titres, normalise les dates et écrit
`export/site.json` (domaine `yumenovel.fr`, alias `yumenovel.wordpress.com`, comptes, éléments
dont le contenu manque). Un élément sans contenu est signalé en erreur par le plan.

Export du 25/09/2026 : 98 pages (96 publiées, 2 brouillons), 183 articles (180 publiés,
3 brouillons), 3 catégories, 438 médias, 5 navigations, 5 parties de modèle.

## 2. Calculer le plan

```sh
export YUME_WP_PATH=… WP_CLI=… YUME_ENV=migration YUME_ONLY_MODULES=core,migration
tools/localenv/wp.sh eval-file tools/migrate/plan.php [dossier-export]
```

Écrit dans le dossier de l'export : `plan.json` (ci-dessous), `rapport.md` (comptes, œuvres,
tomes, chapitres, articles, pages, redirections, anomalies par niveau) et `redirections.csv`
(format d'import de l'extension Redirection : `source,target,regex,code`). Aucune écriture en
base. Le plan est déterministe pour un export et des options donnés.

## 3. Tests

```sh
tools/localenv/test.sh migration     # tests/test-migration.php
php tools/migrate/build-fixtures.php  # régénère fixtures/ après un nouvel export
```

Les tests couvrent les utilitaires, chaque analyseur et le planificateur sur `fixtures/`
(hub Yume LN, fiches Grimgar et Silent Witch, arcs 4 et 7, chapitres 1548 — slug incohérent —,
2417 et 2558 réduits à quelques paragraphes, 5 articles). Le dernier test porte sur l'export
complet s'il est présent (15 œuvres, 63 chapitres migrés, Grimgar 9 tomes / 18 liens ClicTune,
aucune erreur) et ne fait rien sinon.

## 4. Structure de `plan.json` (version 1)

Toutes les dates sont au format `Y-m-d H:i:s` (heure du site). Chaque élément garde l'ID de sa
source (`source.id`, `source_id`). Les liens entre éléments passent par des **clés** : l'exécution
crée les posts puis résout `oeuvre` → `yume_oeuvre_id`, `tome` → `yume_tome_id` (ces deux métas
ne figurent pas dans `meta`).

```text
version            1
genere_le          "2026-09-25T14:36:20Z"
source             { domaine, domaines_alias[], exporte_le, site_id }

oeuvres[]          cle (= slug cible)
                   source   { type: "fiche", id, slug, url, titre }
                   post     { post_type: yume_oeuvre, post_title, post_name, post_status, post_date,
                              post_excerpt, post_content (blocs : synopsis + édition française),
                              comment_status }
                   thumbnail_id (couverture de la fiche) ; url "/oeuvres/{cle}/"
                   termes   { yume_type[1], yume_statut[1], yume_genre[] }
                   meta     { yume_titres_alt[], yume_auteur, yume_illustrateur, yume_editeur_vo,
                              yume_nb_tomes_vo (int|null), yume_statut_vo (en_cours|termine|""),
                              yume_liens [{label,url}], yume_source_traduction,
                              yume_banniere_id (image du hub), yume_equipe {traduction,relecture,edition} }
                   tomes[]  (clés, dans l'ordre de menu_order)
                   infos    { type_indice, statut_hub, statut_fiche, avancement_fiche, infos_vf,
                              autres_infos, synopsis }  (contrôle, non migré)
                   avertissements[]

tomes[]            cle "{oeuvre}/{tome-9|arc-7|tome-ex}" ; oeuvre (clé)
                   source   { type: fiche|arc|article, id, bloc?, slug?, url, texte }
                   post     { post_type: yume_tome, post_title (« X — Tome 9 », « X — Arc 7 : Titre »),
                              post_name (tome-9, arc-7, tome-ex), post_status (publish | draft =
                              planifié), post_date, post_excerpt, post_content (synopsis de l'arc),
                              menu_order (numéro × 10, EX = 1000), comment_status }
                   thumbnail_id ; url "/oeuvres/{oeuvre}/{slug-tome}/"
                   meta     { yume_numero, yume_nature (tome|arc|ex), yume_lien_pdf, yume_lien_epub,
                              yume_equivalence, yume_illustrations[], yume_credits{…},
                              yume_etape (a_faire|traduction|relecture|edition|publie),
                              yume_avancement {traduction,relecture,edition : 0-100},
                              yume_nb_chapitres }
                   date_source ("article:ID", "chapitre", "couverture:ID", "fiche") ; liens[{type,url,texte}]
                   chapitres[] (clés, dans l'ordre) ; avertissements[]
                   + selon la source : couverture_url, sommaire[], chapitres_plage{de,a}, titre_arc,
                     nb_annonces, nb_traduits

chapitres[]        cle "{tome}/{numero}" (numero_url : "12", "12.5") ; oeuvre ; tome (clés)
                   source   { type: page, id, slug, url, titre }   chapitre migré
                          | { type: annonce, id (page ARC), ligne } chapitre planifié (brouillon vide)
                   post     { post_type: yume_chapitre, post_title (« Chapitre 1 — Sous-titre »),
                              post_name (chapitre-1, chapitre-12-5), post_status, post_date,
                              post_content (blocs contrat §9), menu_order (ordre dans le tome),
                              comment_status }
                   meta     { yume_numero, yume_sous_titre, yume_nature, yume_credits{…},
                              yume_nb_mots, yume_temps_lecture (230 mots/min),
                              yume_source {format: "migration", hash (sha1 du contenu source), importe_le} }
                   illustrations[] (IDs de pièces jointes) ; stats{…} ; navigation_supprimee[] ;
                   url "/lire/{oeuvre}/{slug-tome}/{numero}/" ; avertissements[]

articles[]         source_id, slug, titre, date, status, url
                   action (reclasser | ignorer = brouillon d'essai laissé tel quel)
                   classement (sortie|actualite), type_sortie (chapitres|tome|tome_relie|autre|null)
                   categories_actuelles[] (IDs), categories_slugs[], categorie_cible (sorties|actualites)
                   oeuvre (clé → terme yume_oeuvre_liee), oeuvre_source (titre|extrait|lien), oeuvre_alias
                   tome (clé|null), chapitres[] (clés), chapitres_annonces[] (numéros)
                   liens { pdf, epub, lecture[], internes[] (chemins), autres[] } ; contenu_disponible

categories[]       { action: renommer, id, slug_actuel, nom_actuel, slug, nom, description }  (Yume News → Sorties)
                   { action: conserver, … }                                              (Actualités)
                   { action: supprimer, id, slug_actuel, nom_actuel, condition }         (Non classé)

pages              conserver[] { id, slug, titre, url, remarques[] }
                   remplacer[] { id, slug, titre, famille (hub|fiche|arc|chapitre|categorie), action: depublier, url, cible (clé) }
                   ignorer[]   { id, statut, titre, raison }
                   creer[]     { cle, post_title, post_name, parent (cle|""), post_status, post_content (bloc §11), url }

redirections[]     { source (chemin ancien), cible (nouvelle URL), code: 301,
                     type: oeuvre|tome|chapitre|hub|categorie, source_id, cle }

medias             references[] { id, url, existe, usages[] } ; manquants[]
navigation         { remarque, liens[] { menu, libelle, url, cible, wpcom } }
reglages           { banniere_id }  (image de l'en-tête actuel → réglage banniere_id)
avertissements[]   { niveau: erreur|attention|info, categorie, message, source_id }
comptes            pages, articles, oeuvres, tomes, chapitres, liens, redirections, pages_plan,
                   medias, avertissements
```

### Ordre d'exécution conseillé (partie 2)

1. Vérifier `comptes.avertissements.erreur` = 0 et faire valider les points « attention ».
2. Médias : les pièces jointes existent déjà (même site) ; rien à importer.
3. Œuvres (`post_name` = `cle`), puis tomes (`yume_oeuvre_id`), puis chapitres
   (`yume_tome_id`, `yume_oeuvre_id`, `menu_order`) ; rendre l'exécution idempotente avec
   `yume_source.hash` et les IDs source.
4. Articles : catégorie cible, terme `yume_oeuvre_liee` ; catégories (renommage, puis
   `default_category` → Actualités, puis suppression de « Non classé »).
5. Pages §11 à créer, pages remplacées dépubliées, redirections 301 (`redirections.csv`).
