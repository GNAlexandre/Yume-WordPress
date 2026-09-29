# Migration de yumenovel.fr — export, plan et exécution

Ce dossier contient l'**analyse** de l'ancien site (WordPress.com, site `238001312`), le **plan**
de migration vers les types Yume (contrat technique, docs/06-contrat-technique.md) et les outils
locaux qui vérifient son **exécution** de bout en bout. Le code vit dans
`wp-content/plugins/yume-core/includes/migration/` :

- partie 1 — analyse : classes PHP pures (Html, Blocks, Export_Loader, Legacy_*, Migration_Planner,
  Plan_Report) qui transforment un export en plan JSON ;
- partie 2 — exécution : Site_Source (lecture de l'ancien contenu dans la base du site),
  Migration_Runner / Migration_Executor / Migration_Rollback (exécution par lots, annulation),
  Redirections (301), page **Yume → Migrer**, API REST et commande **`wp yume migrer`** (§ 5 à 9).

> Rien n'est poussé sur yumenovel.fr sans le « Go » explicite de l'équipe (contrat §0bis).
> L'export se fait en lecture seule ; l'exécution n'a lieu qu'en local jusqu'au Go, puis en
> production depuis l'administration (Yume → Migrer), où il n'y a **pas** d'export JSON : le plan
> est recalculé depuis la base par Site_Source, identique à celui de l'export.

## Contenu

| Chemin | Rôle | Versionné |
| --- | --- | --- |
| `export/` | Export complet de l'ancien site (JSON) + `raw/` (réponses brutes du connecteur) + sorties du plan | **non** (`.gitignore`) |
| `fixtures/` | Extraits courts de l'export (jeu d'essai des tests) | oui |
| `assemble-export.php` | `export/raw/*.json` → `export/{pages,posts,categories,media,navigations,template-parts,site}.json` | oui |
| `build-fixtures.php` | `export/` → `fixtures/` (extraits courts) | oui |
| `plan.php` | `export/` → `export/plan.json`, `export/rapport.md`, `export/redirections.csv` | oui |
| `seed-local.php` + `lib/class-yume-seed-local.php` | Peuple une base **locale** avec l'ancien site (mêmes ID, mêmes fichiers de médias) | oui |
| `verifier-local.php` | Contrôles de bout en bout (instantané, comptes, URL, comparaison après annulation) | oui |
| `bout-en-bout.sh` | Seed → simulation → exécution → 20 anciennes URL en 301 (HTTP) → annulation → base identique | oui |

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
                   creer[]     { cle, post_title, post_name, parent (cle|null), post_status, post_content (bloc §11 ou texte de base), reglage (page_on_front|page_for_posts|null), url }

redirections[]     { source (chemin ancien), cible (nouvelle URL), code: 301,
                     type: oeuvre|tome|chapitre|hub|categorie, source_id, cle }

medias             references[] { id, url, existe, usages[] } ; manquants[]
                   (média absent sous son ID : url tirée du src de l'image ou de la couverture,
                   avertissement « attention », non bloquant ; l'exécution le retrouve par son
                   fichier — suffixe de _wp_attached_file, puis nom unique — sinon image vide)
navigation         { remarque, liens[] { menu, libelle, url, cible, wpcom } }
reglages           { banniere_id }  (image de l'en-tête actuel → réglage banniere_id)
avertissements[]   { niveau: erreur|attention|info, categorie, message, source_id }
comptes            pages, articles, oeuvres, tomes, chapitres, liens, redirections, pages_plan,
                   medias, avertissements
```

`pages.creer[]` porte aussi `reglage` (`page_on_front` pour « accueil », `page_for_posts` pour
« actualites », sinon `null`). Les onze pages créées : les sept du §11 (`bibliotheque`, `planning`,
`equipe`, `publier` et `membres` sous `equipe`, `compte`, `connexion`), `actualites` (page des articles),
`mentions-legales` (texte de base factuel : éditeur bénévole, hébergeur WordPress.com /
Automattic, droits et retrait sur demande, données personnelles, cookies et stockage du
navigateur), `rejoindre` (`rejoindre-l-equipe`, bloc `yume/recrutement`) et `accueil` (page d'accueil
statique, le thème fournit `front-page.html`).

Liens des tomes : un lien PDF / EPUB absent de la fiche n'est repris d'un article que si
l'article annonce la sortie de ce tome (`type_sortie` tome ou tome_relie) — jamais le lien d'un
chapitre —, jamais pour une œuvre licenciée ni pour un tome que la fiche ne propose qu'à l'achat
(« ==> Acheter <== », champ `achat_fiche` du tome) : avertissement « attention » à la place.
Les liens des noms d'auteur, d'illustrateur, de mangaka et d'éditeur (AniList, Nautiljon, site de
l'éditeur) rejoignent `yume_liens` de l'œuvre (« Auteur : SUNSUNSUN (AniList) »). Le rapport et la
page Migrer affichent les comptes en libellés français (Brouillon, Abandonnée, Light novel…).

Une redirection ne vise jamais un contenu non publié : l'ancienne page d'un arc planifié (brouillon,
ex. arc 8 de Silent Witch) est redirigée vers l'œuvre (information dans les avertissements).

## 5. Exécution (partie 2)

Principe : migration **sur place**, sur le site existant. Les pièces jointes gardent leur ID ; les
anciennes pages remplacées passent en **brouillon** (jamais supprimées). Tout est **réversible tant
que le site n'a pas été utilisé** ; après la mise en service, l'annulation conserve les œuvres
utilisées (voir 3).

1. **Simuler** (Yume → Migrer, ou `wp yume migrer --simuler`) : Site_Source lit l'ancien contenu
   dans la base, Migration_Planner calcule le plan, qui est enregistré (option `yume_migration_plan`,
   compressée). Rien d'autre n'est écrit. Le rapport affiche comptes, avertissements, **statuts à
   valider** (hub et fiche contradictoires : Gimai, Roshidere, Otonari, Mikadono, Chiramune, Raven,
   SukaMoka) avec un choix par œuvre (option `yume_migration_choix`, appliquée à l'exécution), les
   œuvres, pages et redirections ; téléchargements Markdown, JSON et CSV.
2. **Exécuter** (confirmation tapée `MIGRER`) : le plan est recalculé depuis la base (un
   avertissement signale un contenu modifié depuis la simulation), les problèmes bloquants sont
   vérifiés (erreurs du plan, œuvre déjà présente sans avoir été créée par la migration), puis les
   étapes s'enchaînent **par lots** de 2 s (page) ou 30 s (WP-CLI), avec reprise à l'élément
   interrompu :

   | Étape | Effet |
   | --- | --- |
   | préparation | sauvegarde des options (`show_on_front`, `page_on_front`, `page_for_posts`, `default_category`, `yume_pages`, `yume_reglages`, `yume_redirections`, `yume_redirections_ids`, `users_can_register`, `default_role`, `posts_per_page`), des statuts des anciennes pages, des catégories et œuvres liées des articles, des catégories ; empreinte des contenus touchés ; correspondance des médias (par ID, puis par suffixe de `_wp_attached_file`, puis par nom de fichier unique) |
   | œuvres, tomes, chapitres | création (ou mise à jour) avec les champs et slugs du plan ; `yume_oeuvre_id` sur les tomes, `yume_tome_id` sur les chapitres (le cœur dérive le reste : œuvre du chapitre, mots, caches, terme `yume_oeuvre_liee`) ; images mises en avant et bannières par ID de pièce jointe ; taxonomies type et statut |
   | catégories, articles | « Yume News » → « Sorties », description d'« Actualités », `default_category` → Actualités ; chaque article reçoit sa catégorie cible et le terme `yume_oeuvre_liee` de son œuvre (jamais créé à la main) ; brouillons d'essai ignorés |
   | pages | création des 11 pages (ou reprise d'une page existante à la même adresse), option `yume_pages` |
   | anciennes pages | les 90 pages remplacées passent en brouillon (statut seul, contenu intact) — **seulement si un contenu créé les remplace** : la page d'un élément ignoré ou en erreur reste en ligne, sans redirection |
   | pages conservées | couleurs de fond et de texte en ligne retirées (illisibles dans les thèmes Nuit / Papier), texte alternatif ajouté aux liens dont le seul contenu est une image (« Rejoindre le serveur Discord de Yume Novel ») ; contenu d'origine gardé au journal pour l'annulation |
   | réglages | `show_on_front = page`, `page_on_front` = Accueil, `page_for_posts` = Actualités, `banniere_id` (réglage Yume) si vide |
   | redirections | table 301 (option `yume_redirections`), cibles recalculées sur les contenus créés ; adresses courtes `/?page_id=N` des pages remplacées (option `yume_redirections_ids`) |
   | nettoyage | suppression de « Non classé » (articles déjà reclassés) |

   Pendant chaque lot : `add_filter( 'yume_core_notifier', '__return_false' )`, `pre_wp_mail`
   court-circuité, appels HTTP vers Discord bloqués, filtrage kses suspendu (contenus du site
   lui-même) ; aucun événement `yume_tome_publie` / `yume_chapitre_publie` n'est émis. En fin
   d'exécution, chaque contenu créé reçoit une empreinte (méta `_yume_migration_empreinte` : texte,
   statut, métadonnées écrites par la migration, taxonomies) qui permet de reconnaître plus tard
   ceux que l'équipe a modifiés. Si des éléments ont été ignorés, l'état final est « Migré avec N
   éléments ignorés » (avertissement, pas un succès) ; ignorer un tome ignore d'un coup ses
   chapitres, ignorer une œuvre ses tomes.
3. **Annuler la migration** (case à cocher + confirmation, ou `wp yume migrer --annuler`) : retire
   les redirections ajoutées, recrée « Non classé » sous son ID d'origine, rend aux catégories leurs
   noms et slugs, restaure catégories et œuvres liées des articles, les options, les statuts (et
   dates de modification) des anciennes pages et le contenu des pages conservées, supprime pages,
   chapitres, tomes et œuvres créés (méta `_yume_migration_cle`), puis compare l'empreinte : la page
   affiche « contenus revenus à leur état d'origine » ou la liste des différences.

   **Site utilisé depuis la migration** : avant de démarrer, l'annulation cherche ce qu'elle
   détruirait — contenus ajoutés et rattachés à une œuvre ou un tome migré (chapitres importés,
   tomes planifiés), favoris, notes et progressions des lecteurs, commentaires, contenus migrés
   modifiés depuis (empreinte). S'il y en a, elle est **refusée** (liste affichée ; REST : 412) tant
   que l'équipe ne confirme pas la conservation (case « Je comprends… », `conserver=true`,
   `wp yume migrer --annuler --conserver`) : les œuvres concernées sont alors gardées avec tous
   leurs tomes et chapitres (mêmes ID ; une nouvelle exécution les reprend sans doublon), le reste
   est annulé.

   **Journal perdu** (option `yume_migration_journal` effacée) : l'annulation est refusée, sauf
   confirmation (case « Reconstruire la sauvegarde », `reconstruire=true`,
   `wp yume migrer --annuler --forcer`) ; la sauvegarde est alors reconstruite depuis le plan
   (anciennes pages remises en ligne, noms et slugs des catégories, « Non classé » et catégorie par
   défaut, catégories des articles). Les réglages de lecture, d'inscription et les options Yume ne
   sont pas connus : « Contrôle impossible » est affiché, à vérifier à la main.

**Idempotence** : chaque contenu créé porte `_yume_migration_cle` (« oeuvre:grimgar-of-fantasy-and-ash »,
« tome:…/tome-9 », « chapitre:…/arc-4/1 », « page:bibliotheque »), `_yume_source_id` (page ou article
d'origine) et `_yume_migration_run` ; le journal (option `yume_migration_journal`) garde les
correspondances source → cible. Une relance (`wp yume migrer --forcer`) met à jour sans doublon, même
si les correspondances du journal sont perdues (contenus retrouvés par leur méta). **Attention** :
la relance réécrit d'après le plan les contenus créés restés tels quels ; ceux que l'équipe a
modifiés depuis (chapitre traduit et publié, étape, lien) sont reconnus à leur empreinte et gardés
tels quels (avertissement « modifié depuis la migration : conservé tel quel »), comme les articles
reclassés, catégories renommées et réglages changés depuis. Si le journal entier est perdu
(sauvegarde de l'ancien site absente), la relance est **refusée** : elle effacerait la dernière
chance d'annuler.

**Verrou** : une seule exécution à la fois (option `yume_migration_verrou`, insertion atomique ;
repris après 3 minutes d'inactivité). **Erreur** : l'élément fautif est retenté à la reprise ; on
peut l'ignorer (« Ignorer l'élément en erreur et continuer », `--ignorer`).

## 6. Redirections 301

Table `yume_redirections` : chemin source normalisé (relatif à l'accueil, minuscules, barre finale)
→ cible relative. Servie sur `template_redirect` priorité 1 (avant la redirection canonique du cœur,
celle de WordPress et le modèle 404), en GET/HEAD, avec la chaîne de requête conservée si la cible
n'en a pas ; `…/page/N/`, `…/feed/` et `…/amp/` d'une source suivent la même redirection. Filtre
`yume_redirections` pour compléter la table. Export CSV au format d'import de l'extension
Redirection (`source,target,regex,code`) : bouton « Télécharger les redirections » de la page
Migrer (table active) ou `redirections.csv` du plan.

## 7. Interfaces

- **Yume → Migrer** (`admin.php?page=yume-migrer`, capacité `manage_options`) : état, simulation et
  rapport, exécution avec barre de progression et journal annoncé aux lecteurs d'écran, reprise,
  annulation, redirections actives. Sans JavaScript, chaque envoi du formulaire traite un lot (20 s).
- **REST** (`manage_options`, nonce `wp_rest`) : `GET /yume/v1/migration` (état),
  `POST /yume/v1/migration/executer` (`confirmation=MIGRER` pour démarrer, `ignorer`),
  `POST /yume/v1/migration/annuler` (`confirmation=ANNULER` pour démarrer, `ignorer`, `conserver`,
  `reconstruire` ; 412 si le site a été utilisé ou le journal perdu sans ces confirmations).
- **WP-CLI** : `wp yume migrer [--simuler [--rapport=<f>] [--plan=<f>]] [--annuler [--conserver] [--forcer]] [--etat] [--forcer] [--ignorer] [--yes]`.
- Filtres : `yume_migration_source_options` (options de Site_Source), `yume_migration_domaines`,
  `yume_migration_problemes` (problèmes bloquants), `yume_migration_budget` (secondes par lot),
  `yume_redirections`, `yume_migration_tables_dependantes` (tables de données des lecteurs vérifiées
  avant l'annulation). Actions : `yume_migration_terminee( $journal, $plan )`,
  `yume_migration_annulee( $journal )`.

## 8. Source « base » (production) et source « export » (local)

`Site_Source::export( $options )` produit exactement l'entrée d'`Export_Loader` depuis la base :
pages et articles (statuts publish, future, draft, pending, private ; contenu brut ; extrait rendu
comme l'API REST ; catégories dans l'ordre de l'API), catégories, pièces jointes (`file` =
`_wp_attached_file`), navigations, parties de modèle personnalisées ; les contenus créés par la
migration sont exclus. Sur une base peuplée par `seed-local.php`, **plan(base) == plan(export)**
(test automatique sur les fixtures, et sur l'export complet avec les options ci-dessous), à
l'exception des champs documentés : `genere_le`, `source.exporte_le`, `source.site_id`, et selon les
options `domaine`, `domaines_alias`, `home` (base des liens, ex. `https://yumenovel.fr`) et
`url_medias` (ex. `https://yumenovel.wordpress.com/wp-content/uploads/`).

## 9. Outils locaux et bout en bout

```sh
export YUME_WP_PATH=… WP_CLI=… YUME_ENV=migration-locale
# Ancien site dans une base locale (DESTRUCTIF pour cette base ; refusé hors environnement local)
tools/localenv/wp.sh eval-file tools/migrate/seed-local.php [dossier-export] [medias=wp-content/uploads-locale] [sans-images]
# Revue par l'équipe : Yume → Migrer sur http://127.0.0.1:8089 (admin / admin)
tools/localenv/serve.sh 8089
# Bout en bout : seed, simulation, exécution, contrôles, 20 anciennes URL en 301 via HTTP, annulation
YUME_MEDIAS=wp-content/uploads-locale tools/migrate/bout-en-bout.sh 8089   # GARDER=1 : ne pas annuler
```

Le seed insère pages, articles et navigations **aux mêmes ID** (`import_id`), les catégories aux
mêmes ID de terme, des pièces jointes factices aux mêmes ID et au même `_wp_attached_file` avec une
image de substitution générée (GD, proportions d'origine), les parties de modèle personnalisées ; il
règle le fuseau Europe/Paris et les permaliens `/%year%/%monthnum%/%day%/%postname%/`. L'extrait
rendu d'un article (export) est posé comme extrait manuel.

Tests : `tools/localenv/test.sh migration` (analyse, source base contre export, exécution,
idempotence, médias, redirections, annulation exacte, REST, reprise et verrou, administration,
neutralisation des notifications, bout en bout sur l'export complet s'il est présent).

### Jour J (production, après le Go)

1. Sauvegarde complète ; mise à jour de l'extension et du thème.
2. Yume → Migrer → **Simuler** ; relire le rapport, régler les statuts à valider.
3. **Exécuter** (taper MIGRER) ; garder la page ouverte jusqu'à « Migré ».
4. Activer le thème Yume ; recette (10 anciennes URL en 301, fiches, un chapitre, planning).
5. En cas de problème : **Annuler la migration** (la page affiche le contrôle d'état d'origine).
