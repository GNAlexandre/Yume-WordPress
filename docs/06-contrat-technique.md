# 06 · Contrat technique (référence commune des développeurs)

Ce document fige les **noms, formats et interfaces partagés** entre les modules du plugin
`yume-core` et le thème `yume`. Toute évolution d'un élément listé ici se fait ici d'abord.
Les documents 02 à 05 décrivent le *pourquoi* ; celui-ci décrit le *quoi exactement*.

## 0. Principes

- PHP ≥ 8.1, WordPress ≥ 6.6 (testé sur 7.1). Aucune dépendance Composer à l'exécution, sauf la
  bibliothèque Plugin Update Checker **vendorisée** dans `yume-core/lib/plugin-update-checker/`.
- Site **mono-langue français** : chaînes sources directement en français,
  `__( 'Texte', 'yume-core' )` (plugin) et `__( 'Texte', 'yume' )` (thème).
- Espaces de noms PHP : `Yume\Core\<Module>` (classes, fonctions internes). Fonctions publiques
  inter-modules préfixées `yume_` dans l'espace global, déclarées dans `includes/<module>/api.php`
  et protégées par `if ( ! function_exists( … ) )` seulement si c'est utile ; jamais redéclarées ailleurs.
- **Un module = un dossier** `includes/<module>/` avec un point d'entrée `module.php` chargé par
  `yume-core.php`. Au chargement du fichier, un module **n'appelle aucune fonction d'un autre
  module** : il accroche des hooks. Les appels inter-modules se font dans des callbacks (`init` ou
  plus tard) et testent `function_exists()` quand le module appelé est optionnel.
- Sécurité : toute route REST a un `permission_callback` réel ; toute écriture front passe par la
  REST (nonce `wp_rest` envoyé en `X-WP-Nonce`) ou par un formulaire avec `wp_nonce_field`. Échapper
  à la sortie (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), assainir à l'entrée.
- Accessibilité : vrais `<button>`/`<a href>`/`<label>`, focus visible, `aria-*` sur les contrôles
  icône seuls, contrastes ≥ 4,5:1. Tout le front fonctionne sans JavaScript sauf les outils de
  lecture (réglages, marque-page) et les formulaires dynamiques de l'équipe.
- Pas de build obligatoire : JS « vanilla » ES2019 et scripts WordPress globaux (`wp.element`,
  `wp.apiFetch`…). CSS natif avec variables.

## 0 bis. Règle de livraison (décision de l'équipe)

**Le site yumenovel.fr est en production sur la refonte depuis le 29 septembre 2026** (release
v2.0.0, bascule du §8 de `02-plan-refonte.md` effectuée). Le code arrive sur le site uniquement par
les releases GitHub (`05-pipeline-github-wordpress.md` §7). Règle d'après-lancement :

- chaque version stable est taguée `vX.Y.Z` depuis `main` après un **Go explicite de l'équipe**,
  pour chaque version ; aucun tag `v*` sans ce Go (une release est installée sur le site dans les
  12 heures si les mises à jour automatiques sont cochées) ;
- les préversions `vX.Y.Z-beta.N` sont possibles sans Go : `release.yml` les publie en
  « pre-release », le site ne les installe pas automatiquement ;
- tout le travail se fait et se vérifie sur GitHub (branche de travail, pull request, CI verte,
  revue) ; le job de release attend l'approbation de l'environnement `release` ;
- aucune écriture directe sur le site par le connecteur WordPress.com (lecture seule pour l'analyse),
  aucun téléversement de zip hors release.

Historique des Go et des releases : `journal-des-decisions.md`.

## 1. Arborescence et propriétaires

| Chemin | Propriétaire (module) |
| --- | --- |
| `wp-content/plugins/yume-core/yume-core.php`, `includes/blocks-support.php`, `assets/editor/`, `tests/runner.php`, `tests/helpers.php` | socle (déjà écrit, ne pas modifier sans nécessité) |
| `includes/core/`, `tests/test-core.php`, `uninstall.php` | **core** |
| `includes/import/`, `includes/publication/`, `tests/test-import.php`, `tests/test-publication.php`, `tools/docx2chapters/`, `tools/fixtures/*.docx` (synthétiques) | **import + publication** |
| `includes/planning/`, `tests/test-planning.php` | **planning** (planning, équipe, rappels, notifications) |
| `includes/reader/`, `includes/social/`, `tests/test-reader.php`, `tests/test-social.php` | **lecture + lecteurs** |
| `includes/library/`, `tests/test-library.php` | **bibliothèque** (blocs d'affichage) |
| `includes/migration/`, `tests/test-migration.php`, `tools/migrate/` | **migration** |
| `includes/updater/`, `lib/`, `tests/test-updater.php`, `.github/`, `tools/localenv/` (hors wp.sh/test.sh), `tools/playground/`, `phpcs.xml.dist`, `docs/guide-equipe.md`, `docs/guide-developpeur.md` | **outillage** |
| `includes/mangadex/`, `tests/test-mangadex.php`, `tools/mangadex-prototype/` | **mangadex** (lecture des mangas publiés par l'équipe sur MangaDex : client de l'API, puis synchronisation, proxy d'images et lecteur ; aucune image gardée par le site) |
| `includes/wordend/`, `tests/test-wordend.php`, `tools/wordend/` | **wordend** (easter egg SukaSuka : mini-jeu 2D, planches de Chtholly et du Timere) |
| `includes/statistiques/`, `tests/test-statistiques*.php` | **statistiques** (vue « Statistiques » de l'espace équipe : trafic Jetpack, compteurs par œuvre, historique quotidien, listes nominatives des favoris, exports CSV ; §8 ter) |
| `includes/corrections/`, `tests/test-corrections.php` | **corrections** (lot L1 : signalement des coquilles par les lecteurs, file `?vue=corrections` de l'espace équipe ; §8 quater) |
| `includes/discord/`, `tests/test-discord.php` | **discord** (lot L2 : connexion et création de compte avec Discord, liaison depuis Mon compte ; §8 quinquies) |
| `includes/recommandations/`, `tests/test-recommandations.php` | **recommandations** (lot L3 : « Si vous avez aimé… » sur la fiche d'une œuvre et rubrique « Pour vous » ; §8 sexies) |
| `includes/rattrapage/`, `tests/test-rattrapage.php` | **rattrapage** (lot L5 : « Nouveaux chapitres depuis votre dernière lecture », accueil et Mon compte ; §8 sexies) |
| `wp-content/themes/yume/` | **thème** |

Chaque module range ses fichiers statiques dans `includes/<module>/assets/` et ses blocs dans
`includes/<module>/blocks/<nom>/`.

Chantier « lecteurs » (2.5.0) : les quatre modules `corrections`, `discord`, `recommandations`,
`rattrapage` sont déclarés dans `YUME_CORE_MODULES` dès le lot 0, après `statistiques` (espaces de
noms `Yume\Core\Corrections`, `Yume\Core\Discord`, `Yume\Core\Recommandations`,
`Yume\Core\Rattrapage`) ; jusqu'à la livraison de leur lot, leur `module.php` ne charge rien. Ils
n'appellent les autres modules (`Social\*`, `Reader\*`, `Planning\*`, `Library\*`) que dans des
callbacks, gardés par `function_exists`. Le socle des comptes (`includes/social/creation-compte.php`,
§7 « Comptes lecteurs ») et les points d'extension de la page compte appartiennent au module social.

## 2. Hooks du socle

| Hook | Type | Rôle |
| --- | --- | --- |
| `yume_core_register_content` | action | Déclenché à l'activation avant l'installation ; **core** y enregistre aussi ses types de contenu (en plus de `init`) pour que le flush des règles les connaisse. |
| `yume_core_install` | action | Création/mise à jour des tables (`dbDelta`), rôles, options par défaut. Idempotent. Appelé à l'activation et quand `YUME_CORE_VERSION` change. |
| `yume_core_deactivate` | action | Nettoyage des tâches planifiées (`wp_clear_scheduled_hook`). |

## 3. Types de contenu, taxonomies, URL (module core)

| Type | Libellé | URL publique | `supports` | Statuts utilisés |
| --- | --- | --- | --- | --- |
| `yume_oeuvre` | Œuvre / Œuvres | `/oeuvres/{slug}/` ; archive `/oeuvres/` | title, editor, excerpt, thumbnail, comments, custom-fields, revisions | publish, draft |
| `yume_tome` | Tome / Tomes | `/oeuvres/{oeuvre}/{slug-tome}/` (slug du tome ex. `tome-9`, `arc-7`) | title, editor, excerpt, thumbnail, comments, custom-fields, page-attributes | **draft = planifié non sorti**, future = programmé, publish = sorti |
| `yume_chapitre` | Chapitre / Chapitres | `/lire/{oeuvre}/{slug-tome}/{numero}/` ; spéciaux `…/{slug}/` (ex. `postface`) | title, editor, comments, custom-fields, page-attributes (menu_order = ordre dans le tome) | draft, future, publish |

Tous `show_in_rest => true`, `rest_base` = `oeuvres`, `tomes`, `chapitres`. `capability_type`
`array( 'yume_oeuvre', 'yume_oeuvres' )` (idem tome/chapitre) avec `map_meta_cap => true`.
Le titre d'un tome est lisible seul (« Grimgar of Fantasy and Ash — Tome 9 ») ; celui d'un chapitre
aussi (« Chapitre 1 — La Crête Brumeuse »).

**Sous-pages d'une œuvre** (`includes/core/routing.php`) : `/oeuvres/{oeuvre}/{onglet}/`, déclarées
par le filtre `yume_sous_pages_oeuvre` (liste de slugs, ni numériques ni réservés), règle placée
avant celles des tomes ; un tome ne peut pas prendre l'un de ces slugs (`-2` ajouté). La requête est
celle de la fiche (`is_singular( 'yume_oeuvre' )`) avec la variable publique `yume_onglet` ;
`onglet_oeuvre(): string` (onglet affiché ou `''`), `url_onglet_oeuvre( int, string ): string` ;
gabarit `single-yume_oeuvre-{onglet}` du thème en tête de hiérarchie. Le module qui déclare un
onglet décide lui-même d'une 404 (œuvre sans contenu pour cet onglet).

**Onglets de la fiche d'une œuvre** (`includes/library/onglets.php`, PAGE-01) :
`\Yume\Core\Library\onglets_oeuvre( int $oeuvre_id ): array` = slug => `array{ libelle: string, url: string }`,
filtre `apply_filters( 'yume_onglets_oeuvre', array $onglets, int $oeuvre_id )` ; la clé `fiche`
(« Présentation », permalien) est toujours présente et en premier (un filtre ne peut ni la retirer ni
la déplacer), les entrées sans libellé ou sans adresse sont ignorées. Un module qui déclare une
sous-page ajoute son onglet par ce filtre **seulement quand l'œuvre a du contenu** pour lui, place
`<!-- wp:yume/oeuvre-onglets /-->` dans son gabarit `single-yume_oeuvre-{onglet}.html` et renvoie
lui-même une 404 sinon. Référencement commun à toutes les sous-pages (rien à faire côté module) :
titre du document « {Libellé de l'onglet} — {Œuvre} » (+ « Page N » au-delà de la première), adresse
canonique = celle de la sous-page (filtre natif `get_canonical_url`, donc aussi `og:url` ; `?pg=N`
compris), `og:title` identique et `og:type` `website` (filtre `yume_open_graph`) ; pagination par le
paramètre `pg` : le module déclare son nombre de pages par le filtre
`apply_filters( 'yume_pages_onglet_oeuvre', 1, int $oeuvre_id, string $onglet )`, ce qui émet les
`<link rel="prev|next">` (`wp_head`, priorité 9). La fiche garde sa canonique et son titre.
`seo.php` n'est pas modifié : le JSON-LD de l'œuvre (BookSeries) reste émis sur ses sous-pages.

**Actualités d'une œuvre** (`includes/library/actualites.php`, PAGE-01) : sous-page
`/oeuvres/{oeuvre}/actualites/` (`yume_sous_pages_oeuvre`), articles `post` publiés sans mot de passe
liés par la taxonomie `yume_oeuvre_liee` (terme `_yume_terme_lie` de l'œuvre), annonces de sortie
comprises (catégorie « Sorties », `includes/publication/class-annonce.php`), du plus récent au plus
ancien, 10 par page (`?pg=N`). Onglet « Actualités » seulement si l'œuvre a au moins un article
publié lié ; sinon, ou au-delà de la dernière page, **404** (`template_redirect`, priorité 8).
Gabarit du thème `templates/single-yume_oeuvre-actualites.html` : en-tête de la fiche,
`yume/oeuvre-onglets`, `yume/oeuvre-news`. Fonctions : `actualites_oeuvre( int $oeuvre_id, int $nombre = 10,
int $page = 1 ): array{ids:int[],total:int}`, `a_des_actualites( int ): bool`.

**Glossaire d'une œuvre** (module `glossaire`, `includes/glossaire/`, PAGE-05 ; mode d'emploi et
format : `docs/glossaire.md`) : sous-page `/oeuvres/{oeuvre}/glossaire/` (`yume_sous_pages_oeuvre`),
gabarit du thème `templates/single-yume_oeuvre-glossaire.html` (en-tête de la fiche,
`yume/oeuvre-onglets`, `yume/glossaire`). Onglet « Glossaire » (`yume_onglets_oeuvre`, priorité 30) et
page seulement si `glossaire_visible( int $oeuvre_id )` : au moins une entrée publique ou un
anglicisme, ou (équipe, `yume_voir_equipe`) au moins une entrée ; sinon **404**
(`template_redirect`, priorité 5). Balises : titre « Glossaire — {Œuvre} », canonique = adresse du
glossaire (`get_canonical_url`), description et `og:*` (`yume_open_graph`), **noindex, follow** sous
`SEUIL_INDEXATION` (5) entrées publiques + anglicismes (`wp_robots`). Fonctions
(`Yume\Core\Glossaire\`) : `analyser_glossaire( string $yaml ): array|WP_Error` (aucune écriture),
`importer( int $oeuvre_id, string $yaml, array $args ): array|WP_Error` (`user_id`, `source`
`api|televersement|restauration`, `note`, `simulation`), `restaurer_version( int $version_id, int
$user_id )`, `etat_glossaire( int ): array{version,entrees,publiques,anglicismes,maj}`,
`lire_entrees( int ): array`, `versions( int ): array`, `url_glossaire( int ): string`. Import :
remplacement des entrées de l'œuvre + nouvelle version dans une transaction (MariaDB/MySQL :
`START TRANSACTION`, ou `SAVEPOINT` si une transaction est déjà ouverte ; SQLite : suppression puis
insertion), rien d'écrit si l'empreinte SHA-256 est celle de la version en ligne (statut
`inchange`), 5 versions gardées par œuvre, journal de l'équipe (champ `glossaire`, `tome_id` 0,
jamais public). Méta privée de l'œuvre `_yume_glossaire` (état ci-dessus, cache de l'onglet). Filtres :
`yume_glossaire_envois_par_heure` (20), `yume_glossaire_fichier_local` (tests). Vue d'équipe
`?vue=glossaire` (`yume_vues_equipe`, capacité `yume_glossaire`) ; admin-post `yume_glossaire`
(nonce `yume_glossaire` : `verifier`, `publier`, `publier_brouillon`, `annuler`, `restaurer` ;
brouillon vérifié : ligne de `glossaire_versions` de source `brouillon` (YAML jusqu'à 4 Mo, trop gros
pour un transient sous Memcached), le transient `yume_glossaire_brouillon_{user}` (30 min) ne gardant
que `{id, sha256, oeuvre, fichier, bilan}` ; un brouillon par compte, supprimé à la publication, à
l'annulation, à la vérification suivante ou après 30 min (`purger_brouillons()`), jamais compté dans
l'historique ni dans les 5 versions gardées, jamais servi par `version()`, `versions()`, le
téléchargement ou `GET /oeuvres/{o}/glossaire`) et `yume_glossaire_yaml`
(téléchargement, nonce `yume_glossaire_yaml_{version}`).

**Contributeurs** (`includes/social/profil-public.php`, PAGE-04) : `/contributeurs/` (liste des profils
publics) et `/contributeurs/{slug}/` (profil), règles de réécriture propres (`regles_contributeurs()`,
variables publiques `yume_contributeurs` et `yume_contributeur`, vidage des règles quand leur signature
change : option `yume_regles_contributeurs`, md5 des règles + `YUME_CORE_VERSION`). Jamais `/equipe/…`
(espace équipe) ni l'identifiant de connexion : `user_nicename` étant dérivé de `user_login` par
WordPress, l'adresse utilise la méta `yume_profil_slug` tirée du pseudo (`generer_slug_profil()`,
suffixe `-2`… si déjà pris). La requête principale n'est ni l'accueil ni une archive (aucun article
chargé, `posts_pre_query`) ; `pre_handle_404` rend **404** pour un compte sans consentement, hors de
l'équipe (`yume_voir_equipe`) ou dont le pseudo redevient l'identifiant, 200 sinon ; modèle du thème
`yume-contributeur` en tête de la hiérarchie `index`. Référencement : titre « {Pseudo}, contributeur » /
« Contributeurs », `<link rel="canonical">`, `og:type` `profile` et description = présentation
(filtre `yume_open_graph`), **noindex, follow** pour un profil sans présentation ni tome publié et pour
la liste vide ; plan du site : fournisseur `contributeurs` (`wp-sitemap-contributeurs-1.xml` : la liste
et les profils indexables). Fonctions : `profil_public_actif( int ): bool`, `url_profil_public( int ): string`,
`url_contributeurs(): string`, `contributeurs_publics(): WP_User[]`, `contributions_profil( int ): array`
(tomes **publiés** d'œuvres publiées dont le compte est responsable d'une étape, lus dans la méta privée
`yume_responsables` seulement pour un profil public), `enregistrer_profil_public( int, array ): string[]`.

Taxonomies (sur `yume_oeuvre`, `show_in_rest`, hiérarchiques pour type/statut) :

| Taxonomie | Termes créés à l'installation (slug : nom) |
| --- | --- |
| `yume_type` | `light-novel` : Light novel · `web-novel` : Web novel · `manga` : Manga |
| `yume_statut` | `en-cours` : En cours · `terminee` : Terminée · `en-pause` : En pause · `licenciee` : Licenciée · `abandonnee` : Abandonnée |
| `yume_genre` | (libre, non hiérarchique) |

Les taxonomies `yume_type`, `yume_statut`, `yume_genre` ne sont **pas interrogeables en façade** (pas
d'archives de termes, pas de modèle `taxonomy-*`) : leurs liens mènent à `/bibliotheque/?type=…`,
`?statut=…`, `?genre=…`. Redirections 301 supplémentaires : `/lire/{oeuvre}/{tome}/` → tome,
`/lire/{oeuvre}/` → œuvre. Slugs : un slug de tome numérique devient `tome-N`, un chapitre
`chapitre-N` (`chapitre-12-5` pour 12.5), les spéciaux prennent leur libellé (`postface`) ; les mots
réservés (`feed`, `embed`, `page`, `comment-page-N`) reçoivent `-2`.

**Page « Illustrations » d'un tome** (`includes/core/illustrations.php`) : `/lire/{oeuvre}/{slug-tome}/illustrations/`,
page de lecture virtuelle (aucun contenu créé) placée avant le chapitre 1, comme les planches couleur d'un
light novel imprimé. Elle existe quand le tome a une galerie (`yume_illustrations`, images placées avant le
premier chapitre du DOCX / EPUB ; pièces jointes absentes ou non images ignorées) **et** qu'aucun chapitre du
tome (statut actif) n'occupe le segment `illustrations` : un vrai chapitre (nature `illustrations`) garde
toujours l'adresse. Résolution : la règle des chapitres ; si aucun chapitre ne correspond,
`resoudre_illustrations()` renvoie le **tome** (`p` + `post_type=yume_tome`) avec la variable privée
`yume_page_illustrations=1`, mêmes règles de visibilité que la page du tome (`est_consultable()`, hiérarchie) ;
404 sans galerie, tome ou œuvre masqués, et pour `…/illustrations/feed|embed|trackback|N|comment-page-N/`.
Variante non canonique (`/Illustrations/`, ancien slug) : 301. Balises : titre « Illustrations · {tome} »,
`rel=canonical` = son adresse, **`noindex, follow`** et absente du plan du site (page sans texte dont les
images sont déjà indexées sur la page du tome), pas de lien court ni de JSON-LD. Le thème l'affiche avec le
modèle `templates/yume-illustrations.html` (filtre `single_template_hierarchy`, classes `yume-lecture
yume-illustrations`) : `yume/reader-tools`, puis dans `article.yn-reader` `yume/chapter-header`,
`yume/tome-illustrations`, `yume/chapter-nav`.

Taxonomie `yume_oeuvre_liee` (non hiérarchique, sur `post`) : slug = slug de l'œuvre ; relie les
articles d'actualité à une œuvre. Termes créés/synchronisés automatiquement avec les œuvres.

## 4. Métadonnées (toutes `register_post_meta`, `show_in_rest`, `single => true`)

Préfixe `yume_`. Types JSON Schema entre parenthèses. Les objets et tableaux sont déclarés avec
un `schema` REST complet.

**`yume_oeuvre`** : `yume_titres_alt` (array<string>) · `yume_auteur` (string) ·
`yume_illustrateur` (string) · `yume_editeur_vo` (string) · `yume_nb_tomes_vo` (integer) ·
`yume_statut_vo` (string enum `en_cours`,`termine`) · `yume_jours_sortie` (array<string> parmi
`lundi`…`dimanche`) · `yume_liens` (array<object{label:string,url:string}>) ·
`yume_source_traduction` (string, ex. « Édition anglaise officielle (J-Novel Club) ») ·
`yume_banniere_id` (integer, pièce jointe) · `yume_equipe` (object{traduction:string,relecture:string,edition:string}) ·
**série à venir** : `yume_serie_a_venir` (boolean ; absente ou faux = titre public) · `yume_libelle_a_venir`
(string, nom affiché au public ; vide = « Nouvelle série à venir ») — section « Annonce au planning »
des formulaires « Nouvelle œuvre » et « Modifier l'œuvre » ; lues par `yume_oeuvre_a_venir()` et
`yume_titre_public_oeuvre()`, règle de masquage et de révélation au §7 ·
caches en lecture seule pour l'API : `yume_note_moyenne` (number) · `yume_nb_notes` (integer) ·
`yume_nb_favoris` (integer) · `yume_derniere_sortie` (string, date `Y-m-d H:i:s` GMT du dernier tome/chapitre publié) ·
**MangaDex** (module mangadex, depuis 2.3.0) : `yume_mdx_serie` (string, identifiant de la série MangaDex ;
assaini par `san_uuid()`, qui garde le premier UUID d'une saisie ou d'une adresse mangadex.org, en
minuscules ; champ « Lien MangaDex de la série » du formulaire équipe des œuvres, et boîte « MangaDex »
de l'écran de l'œuvre dans l'administration).

**`yume_tome`** : `yume_oeuvre_id` (integer, obligatoire) · `yume_numero` (number, ex. 9, 26.5) ·
`yume_nature` (string enum `tome`,`arc`,`ex`,`bonus`,`chapitres`) · `yume_lien_pdf` (string url) ·
`yume_lien_epub` (string url) · `yume_equivalence` (string) · `yume_illustrations` (array<integer>) ·
`yume_credits` (object{traduction,relecture,edition}) · **planning** : `yume_etape` (string enum
`a_faire`,`traduction`,`relecture`,`edition`,`publie`) · `yume_avancement`
(object{traduction:int 0-100, relecture:int, edition:int}) · `yume_responsables`
(object{traduction:int user_id, relecture:int, edition:int}) · `yume_date_cible` (string `Y-m-d`) ·
`yume_heure_cible` (string `HH:MM`, heure du site, `wp_timezone()` ; vide = non précisée ; champ « Heure de sortie »
à côté de « Date cible du tome », toujours enregistré quel que soit le rythme ; 18:00 proposé à la
création, jamais imposé ; paramètre `heure_cible` des formulaires et de la REST, §10 et §12 ; un tome
programmé garde sa date et son heure de publication, qui priment) ·
`yume_bloque` (boolean) · `yume_bloque_raison` (string) · `yume_derniere_maj` (string `Y-m-d H:i:s` GMT) ·
`yume_maj_par` (integer) · **parution** : `yume_parution` (string enum `''`, `planifie`, `en_cours`,
`complet` ; vide : règle historique ; lue par `yume_parution_tome()` → `a_paraitre` | `en_cours` |
`complet`, `planifie` → `a_paraitre` quel que soit le statut ; libellés lecteurs `yume_parutions()`
« À paraître », « En cours », « Publié » ; libellés équipe `yume_etats_tome()` « Planifié », « En
cours de publication », « Publié » ; `planifie` et `en_cours` masquent les liens PDF / EPUB aux
lecteurs : `yume_liens_telechargement()` les renvoie vides, `yume_liens_tome_masques()`, liens gardés
en base) · `yume_chapitres_prevus` (integer) · `yume_rythme` (object{jour, heure} ; `heure` reprend
`yume_heure_cible` : le rythme, jour ou « Libre », n'a plus de saisie d'heure propre ; les anciens
paramètres `rythme_heure` / `rythme[heure]` restent acceptés et alimentent l'heure de sortie) ·
`yume_note_equipe` (string, **jamais exposé publiquement** :
`auth_callback` = capacité `yume_maj_planning`) · `yume_pause` (object{depuis:string `Y-m-d H:i:s`
GMT, par:int user_id} ; absente = tome pas en pause ; `prive` : lue en REST par l'équipe seulement,
lecture seule via `/wp/v2` ; écrite par `Planning\basculer_pause()` — « Mettre en pause » /
« Reprendre » du planning complet : un tome en pause n'est jamais `en_retard` (filtre
`yume_planning_etat`), ne reçoit ni rappel ni signalement, figure hors des retards du récapitulatif ;
badge « En pause » dans l'espace équipe seulement ; journal `pause` jamais public ; la reprise date
`yume_derniere_maj`) · `yume_nb_chapitres` (integer, cache : chapitres
**publiés** du tome, prologues et postfaces compris ; recalculé par core, `includes/core/cache.php`, sur
`wp_after_insert_post` d'un chapitre ou du tome — publication, programmation échue, mise à jour, corbeille —,
au changement de `yume_tome_id` d'un chapitre (ancien et nouveau tome), à la suppression définitive d'un
chapitre et sur `yume_tome_publie` ; lu par la bibliothèque via `Library\nb_chapitres_tome()`, avec repli
sur le calcul quand la méta est absente, tome migré).

**`yume_chapitre`** : `yume_tome_id` (integer) · `yume_oeuvre_id` (integer, dénormalisé) ·
`yume_numero` (number ; 0 pour prologue) · `yume_sous_titre` (string) · `yume_nature` (string enum
`chapitre`,`prologue`,`interlude`,`epilogue`,`postface`,`bonus`,`illustrations`) ·
`yume_credits` (object{traduction,relecture,edition}) · `yume_nb_mots` (integer) ·
`yume_temps_lecture` (integer, minutes, 230 mots/min) · `yume_source` (object{format:string,hash:string,importe_le:string}) — `format` ∈ `docx`, `epub`, `migration` ·
**chapitre hébergé sur MangaDex** (module mangadex, depuis 2.3.0 ; aucun contenu importé, aucune image
gardée) : `yume_mdx_chapitre` (string, identifiant du chapitre MangaDex, `san_uuid()` ; vide pour un
chapitre importé) · `yume_mdx_pages` (integer) · `yume_mdx_version` (string, 8 caractères hexadécimaux,
change quand MangaDex met le chapitre à jour ; entre dans l'adresse des pages relayées) ·
`yume_mdx_groupes` (array<string>, groupes crédités). Un volume MangaDex est un tome de l'œuvre
(`yume_numero` = volume, nature `tome`) ; les chapitres sans volume vont dans un tome de nature `chapitres`.

Statut de chapitre interne `yume_remplacement` (module publication, `Remplacement::STATUT`, libellé
« Version en attente ») : version en attente d'un remplacement de lecture en ligne (§8). Non public,
`protected` (aperçu réservé à qui peut modifier le chapitre, 404 sinon), `exclude_from_search`, absent
des listes de l'administration ; hors de `statuts_actifs()`, donc de `yume_get_chapitres()`, des
sommaires, compteurs, recherches et adresses du tome. Méta internes d'une version :
`_yume_remplacement_de` (integer : chapitre en ligne remplacé, 0 = chapitre nouveau),
`_yume_remplacement_action` (`cree` | `maj` | `inchange`), `_yume_remplacement_medias` (array<integer> :
images utilisées). Méta interne du tome : `_yume_remplacement` (`{par, cree (horodatage), fichier,
resume, medias (images versées pour la préparation), galerie, couverture, retirer_absents, absents}`).

Les clés `yume_*` sont protégées (absentes de la boîte « Champs personnalisés »). Les caches
(`yume_note_moyenne`, `yume_nb_notes`, `yume_nb_favoris`, `yume_derniere_sortie`, `yume_nb_chapitres`)
sont en lecture seule via `/wp/v2` : les modules les écrivent avec `update_post_meta`.

Méta internes (préfixe `_`, non exposées) : tome `_yume_planning_depublie` (date GMT : tome
dépublié, sa sortie est rétablie à son retour en ligne), `_yume_planning_sortie_partielle` (date
GMT : tome en ligne dont des chapitres restent à sortir) et `_yume_parution_manuelle`
(`{etat: planifie|en_cours|complet, date: GMT, par: ID}`, lue par `yume_parution_manuelle()` : état
choisi à la main dans « Modifier le tome » ; effacée, avec une ligne de journal `parution` `auto`,
quand une publication de l'équipe change ensuite `yume_parution` — chapitre publié dans un tome
`planifie` en ligne → `en_cours`, tome `planifie` publié hors formulaire → méta vide, « Ajouter des
chapitres », « Tome complet » ; suivi par `Planning\suivre_parution_automatique()` sur
`added|updated|deleted_post_meta`) ; article d'annonce `_yume_annonce_retiree`
(`{statut: publish|future, date, date_gmt}` : annonce remise en brouillon avec son tome dépublié ou
reprogrammé, republiée à son retour).

Méta de commentaire (module social, `includes/social/moderation.php`) : `_yume_signalements`
(array ID du compte => `{motif:string ≤ 200, date:string GMT, ignore:bool}`) : signalements des
lecteurs (AMEL-10) ; « Ignorer les signalements » ou l'approbation du commentaire (façade ou
administration, `transition_comment_status`) les marque `ignore` ; un compte ne signale jamais deux
fois le même commentaire.

## 5. Rôles et capacités (module core, à l'installation)

Capacités propres : `yume_maj_planning` (ses tomes), `yume_maj_planning_tous`, `yume_publier`,
`yume_gerer_equipe`, `yume_reglages`, `yume_voir_equipe` (accès à /equipe/).
`yume_voir_statistiques` (vue « Statistiques » : trafic et agrégats ; tous les rôles de l'équipe et
administrateur) et `yume_voir_lecteurs` (listes nominatives des favoris et des suiveurs d'une œuvre et
leur export ; Gérant et administrateur), depuis 2.4.0 (§8 ter).
`yume_glossaire` (glossaires des œuvres : import, restauration, envoi par Yume-Trad ; Éditeur Yume,
Gérant, administrateur).
Capacités de types : `edit_yume_oeuvres`, `edit_others_yume_oeuvres`, `publish_yume_oeuvres`,
`delete_yume_oeuvres`, … (idem `yume_tomes`, `yume_chapitres`).

| Rôle | Nom affiché | Capacités |
| --- | --- | --- |
| `subscriber` | Lecteur (renommé) | `read` |
| `yume_traducteur`, `yume_relecteur`, `yume_graphiste` | Traducteur, Relecteur, Graphiste | `read`, `upload_files`, `yume_voir_equipe`, `yume_maj_planning`, `edit_yume_tomes`, `yume_voir_statistiques` |
| `yume_editeur` | Éditeur Yume | les précédentes + `yume_publier`, `yume_maj_planning_tous`, `yume_glossaire`, toutes les capacités des 3 types (y compris others/publish/delete), `edit_posts`, `publish_posts`, `edit_published_posts`, `moderate_comments`, `manage_categories` |
| `yume_gerant` | Gérant | `yume_editeur` + `yume_gerer_equipe`, `yume_reglages`, `yume_voir_lecteurs`, `edit_others_posts`, `delete_posts`, `delete_published_posts`, `delete_others_posts`, `list_users`, `promote_users` (limitée, voir ci-dessous) ; **ni** `create_users` **ni** `edit_users` (SEC-03) |
| `administrator` | — | tout, y compris toutes les capacités `yume_*` |

Gestion des membres par un gérant (tout compte sans `manage_options`) : il ne fait que changer des
rôles (`promote_users`, `list_users`) ; il ne crée pas de compte et ne modifie jamais le profil d'un
autre compte (mot de passe, e-mail : `edit_user` refusé, donc REST `POST /wp/v2/users/{id}` avec
`password`/`email` → 403 et `user-edit.php` inaccessible ; SEC-03). Seul son propre profil reste
modifiable. Un mot de passe perdu se réinitialise par « Mot de passe oublié ? » ou par un
administrateur ; les comptes naissent de l'inscription des lecteurs. `promote_users` ne porte que sur
le Lecteur et les rôles de l'équipe (`roles_gerables()` : `subscriber`, `yume_traducteur`,
`yume_relecteur`, `yume_graphiste`, `yume_editeur`). Le filtre `editable_roles` limite les rôles
attribuables à cette liste (users.php, REST `/wp/v2/users` avec `roles` seul) ; `map_meta_cap` refuse
`edit_user`, `promote_user`, `remove_user` et
`delete_user` sur tout compte ayant un autre rôle (administrateurs, autres gérants, rôles WordPress
éditoriaux) ou super administrateur, et `promote_user` sur son propre compte. En façade, la page
« Membres et rôles » (`yume/team-members`, capacité `yume_gerer_equipe`) applique les mêmes règles,
y compris pour un administrateur : rôles attribuables = rôles de l'équipe de `roles_gerables()`, retrait
= retour à `subscriber`, contrôle `current_user_can( 'promote_user', $id )` à chaque action.

Les rôles sont stockés en base : `installer_roles()` ajoute les capacités manquantes et retire celles
de `capacites_retirees()` (`yume_gerant` : `create_users`, `edit_users`) ; `verifier_roles()` (sur
`init`) relance l'installation dès que la signature des définitions et des retraits change (option
`yume_core_roles`), donc à la mise à jour de l'extension sur un site existant.

Fonction utilitaire : `yume_user_can_edit_planning( int $tome_id, int $user_id = 0 ): bool`
(vrai si `yume_maj_planning_tous`, ou `yume_maj_planning` et l'utilisateur est un des responsables).

Règles d'écriture du planning (espace équipe, REST et méta-boîte ; `includes/planning/service.php`) :
avancer à `relecture` ou `edition` exige que les étapes précédentes soient à 100 % (sinon 400
`yume_etape_prematuree`) ; revenir en arrière reste permis. Un administrateur (`manage_options`) ou un
gérant (`yume_gerer_equipe`) peut forcer ce passage : `Yume\Core\Planning\peut_forcer_etape( int $user_id ): bool`,
filtre `yume_planning_peut_forcer_etape( bool $peut, int $user_id )` ; le passage est journalisé
`etape_forcee` (privé) et ne permet jamais « publié » pour un tome non publié. Sans
`yume_maj_planning_tous`, un membre ne modifie que l'avancement des étapes dont il est responsable (403
`yume_avancement_interdit` ; une valeur inchangée est acceptée).

## 6. Réglages (module core)

Option unique `yume_reglages` (tableau), page *Yume → Réglages* (capacité `yume_reglages`),
lecture via `yume_setting( string $key, $default = null )`. Clés et défauts :

| Clé | Défaut | Utilisée par |
| --- | --- | --- |
| `discord_webhook_sorties` | `''` | planning (annonce des sorties de tomes ; chapitres selon `discord_chapitres`) |
| `discord_chapitres` | `'sorties'` | planning : salon des annonces de chapitres (canal `chapitres`) — `sorties` (webhook des sorties), `dedie` (`discord_webhook_chapitres`, ou celui des sorties s'il est vide), `aucun` (pas d'annonce) |
| `discord_webhook_chapitres` | `''` | planning (annonce des chapitres avec `discord_chapitres` = `dedie` ; seules les URL de webhook Discord sont acceptées) |
| `discord_webhook_equipe` | `''` | planning (rappels) |
| `rappel_jours_sans_maj` | `14` | planning |
| `rappel_heure` | `9` | planning (heure locale Europe/Paris) |
| `digest_jour` | `1` (lundi, 0 = dimanche) | planning |
| `emails_lecteurs` | `true` | social |
| `banniere_id` | `0` | bibliothèque (bloc bannière), thème |
| `kofi_url` | `https://ko-fi.com/ynovel` | thème |
| `discord_invite` | `https://discord.gg/SMBZqhgUv8` | thème |
| `twitter_url` | `https://x.com/YumeNovel` | thème |
| `jours_sortie` | `['mercredi','samedi','dimanche']` | planning |
| `modele_annonce` | `Le {nature} {numero} de {oeuvre} est disponible !` | publication |
| `github_repo` | `GNAlexandre/Yume-WordPress` | updater |
| `maj_auto` | `true` | updater |
| `partenaires` | les 4 partenaires de l'ancien site (`partenaires_par_defaut()`) | bibliothèque (`yume/partenaires`) |
| `pwa_hors_ligne` | `true` | lecture (manifeste web et service worker, §14 ; champ ajouté par `yume_reglages_champs`, section « Site et réseaux ») |
| `notifications_navigateur` | `true` | lecteurs (Web Push, AMEL-06, §13 ; champ ajouté par `yume_reglages_champs`, section « Notifications » ; filtre `yume_push_actif`) |
| `recherche_chapitres` | `false` | bibliothèque (groupe « Dans les chapitres » de `yume/recherche` ; champ ajouté par `yume_reglages_champs`, section « Site et réseaux » ; filtre `yume_recherche_chapitres_active`) |
| `recrutement_intro` | texte d'accueil de l'équipe | planning (`yume/recrutement`, section « Recrutement » ajoutée par `yume_reglages_sections`) |
| `recrutement_postes` | Traducteur EN→FR, Relecteur, Graphiste (clean et typeset), ouverts | planning : texte, une ligne par poste « Intitulé \| Description courte \| ouvert\|fermé » (12 au plus, assaini par `assainir_postes_recrutement()`) ; seuls les postes ouverts sont affichés |
| `recrutement_test_url` | `''` | planning : lien du test de traduction (http(s), facultatif) |
| `recrutement_consigne` | ticket dans le salon « tickets » du Discord | planning : texte au-dessus du bouton « Postuler sur le Discord » (lien : `discord_invite`) |
| `mangadex_groupe` | `4bbecb8b-41bb-48a8-8ddb-0c9243e4c344` (groupe Yume Novel) | mangadex : groupe dont le site lit les chapitres en français (section « Mangas (MangaDex) » ajoutée par `yume_reglages_sections` ; identifiant ou adresse https://mangadex.org/group/…, une saisie invalide revient au défaut) |
| `mangadex_synchro` | `false` | mangadex : synchronisation horaire des chapitres du groupe (§8 bis) ; coupée aussi quand le lecteur l'est (`yume_mangadex_lecteur_pret`, vrai depuis 2.3.0) ; filtre final `yume_mangadex_synchro_active` |
| `coquilles_actives` | `true` | corrections (lot L1) : signalement des coquilles ouvert (section « Lecteurs » `lecteurs`, ajoutée par L1 via `yume_reglages_sections` ; filtre final `yume_coquilles_actives`) |
| `coquilles_anonymes` | `false` | corrections : signalement sans compte (jeton horodaté + pot de miel, 5 par heure et par IP) |
| `coquilles_discord` | `true` | corrections : message Discord au canal `equipe` à chaque signalement (étouffé, §8 quater ; filtre `yume_coquilles_discord`) |
| `discord_connexion` | `false` | discord (lot L2) : bouton « Se connecter avec Discord » (section « Connexion Discord » `discord_connexion`, ajoutée par L2) |
| `discord_inscription` | `true` | discord : création d'un compte au premier passage (sous réserve de `inscriptions_ouvertes()`) |
| `discord_client_id` | `''` | discord : *Client ID* de l'application Discord (type `text`, verrouillé par la constante `YUME_DISCORD_CLIENT_ID`) |
| `discord_client_secret` | `''` | discord : *Client Secret* (type **`secret`** ; verrouillé et ignoré si la constante `YUME_DISCORD_CLIENT_SECRET` existe — recommandé : constante dans `wp-config.php`, le réglage n'est qu'un repli) |

`yume_setting( $key, $default )` : un `$default` explicite l'emporte quand la clé n'est pas enregistrée.
Les modules peuvent ajouter des champs à la page via le filtre
`yume_reglages_champs` (types en plus : `checkboxes`, `secret` ; clés facultatives `default`, `min`, `max`,
`step`, `placeholder`, `sanitize`) et des sections via `yume_reglages_sections` (tableau de `array( 'key', 'label', 'type' => text|url|number|checkbox|select|media|textarea, 'section', 'options', 'description' )`).

**Type de champ `secret`** (depuis 2.5.0, lot 0) : pour une clé d'API ou un secret OAuth. Affiché
`<input type="password" value="" autocomplete="new-password">` avec l'aide « Une valeur est
enregistrée. / Aucune valeur enregistrée. Laisser vide pour conserver la valeur enregistrée. »
(`Core\aide_secret()`) dans *Yume → Réglages* et dans la vue « Réglages » de l'espace équipe
(`Planning\champ_reglage_equipe()`) ; la valeur n'est **jamais réaffichée**. Assainissement
(`assainir_champ()`) : saisie vide (ou espaces, ou non scalaire) → valeur enregistrée conservée, avant
même un callback `sanitize` ; sinon texte (`san_texte()`, espaces de début et de fin retirées). Champ
`verrouille` : champ mot de passe désactivé, vide, avec le message du verrou (« Défini par la
constante … »), jamais la valeur. L'option `yume_reglages` n'est pas exposée en REST
(`show_in_rest` faux) ; un secret se lit par `yume_setting()` côté serveur seulement et ne doit
jamais être écrit dans une page, un attribut `data-*`, une réponse REST ou un journal. Aucun moyen
d'effacer un secret depuis le formulaire : passer par une constante ou par programme.

`partenaires` (section *Partenaires*, type de champ `partenaires`) : liste ordonnée d'au plus 8
(`MAX_PARTENAIRES`) `{nom, url, description, logo: int (pièce jointe) | string (URL http(s)) | '',
fichier?: string}` ; `fichier` (nom du fichier du logo attendu) n'existe que sur les lignes par
défaut. Assainissement `assainir_partenaires()` : ligne sans nom ni lien retirée, ligne sans lien
http(s) ignorée avec un avertissement. Filtres `yume_partenaires` (liste affichée par le bloc) et
`yume_reglages_partenaires_formulaire` (lignes du formulaire : la bibliothèque y remplace le logo des
lignes par défaut par la pièce jointe trouvée par nom de fichier, transient `yume_partenaires_logos`).

## 6 bis. Administration

Menu de premier niveau **Yume** (slug `yume`, icône `dashicons-book-alt`, capacité `edit_yume_tomes`)
créé par **core**. Les types `yume_oeuvre`, `yume_tome`, `yume_chapitre` y apparaissent
(`show_in_menu => 'yume'`). Sous-menus : *Réglages* (core, `yume-reglages`), *Migrer* (migration,
`yume-migrer`, capacité `manage_options`), *Publier un tome* (publication, lien vers la page
`/equipe/publier/`). Les autres modules ajoutent leurs sous-pages avec `add_submenu_page( 'yume', … )`
sur `admin_menu` priorité ≥ 20.

- **Accès à wp-admin** (`includes/core/admin/acces.php`) : un membre de l'équipe sans droit de
  rédaction (traducteur, relecteur, graphiste : `yume_voir_equipe` sans `edit_others_yume_tomes`,
  `edit_posts` ni `manage_options`) est renvoyé vers la page `equipe` sur `admin_init`, sauf
  `profile.php`, `admin-post.php`, `admin-ajax.php`, `async-upload.php`, REST et cron ; menus Yume,
  Tableau de bord et Médias masqués, « Tableau de bord » de la barre d'administration → espace équipe.
- **Méta-boîte Planning** d'un tome : mêmes règles que le §5 (étape forcée journalisée) ; les champs
  refusés sont mémorisés 10 min (transient `yume_refus_planning_{user_id}`) et affichés en avis
  (éditeur classique) ou dans l'éditeur de blocs (action AJAX `yume_refus_planning`, nonce).
- **Pages manquantes** (`includes/core/admin/notices.php`, capacité `manage_options` ou
  `yume_reglages`) : avis listant les pages du §11 absentes, à la corbeille, non publiées ou
  détachées de leur parent, bouton « Recréer les pages manquantes » (admin-post
  `yume_recreer_pages`) qui appelle `recreer_pages_yume(): array` (clé → ID ; mêmes slugs, parents
  et contenus que la migration, réglages de lecture reportés ; utilisée aussi par la démo).

- **Prérequis de mise en production** (`includes/core/admin/notices.php`, administrateurs) : avis
  listant les réglages non encore appliqués (`etat_prerequis(): array`, `prerequis_manquants(): array`,
  filtre `yume_prerequis_etat`) ; « Masquer » par compte (méta utilisateur `yume_prerequis_masques`,
  admin-post `yume_prerequis`), lien « Détail dans la santé du site » vers `?vue=sante#yn-sante-prerequis` ;
  pas affiché sur *Yume → Santé*. Liste complète : `docs/mise-en-production.md`.

- **Santé** (`includes/core/admin/sante.php`, capacité `yume_reglages`) : données affichées par la vue
  `?vue=sante` de l'espace équipe (voir `yume/team-dashboard`) ; le sous-menu *Yume → Santé* (slug
  `yume-sante`) redirige vers cette vue au chargement (`load-…`, comme « Publier un tome » ;
  `url_sante()`, repli sur *Outils → Santé du site* sans module planning), et l'avis des prérequis
  n'y est pas affiché. Contenu : tâches planifiées Yume (hooks `yume_*` programmés et tâches connues, filtre
  `yume_taches_cron` hook => libellé) avec dernière exécution — option `yume_cron_derniers`
  (hook => horodatage, non chargée d'office) écrite au début de chaque tâche par un écouteur
  générique (`noter_execution_cron()`, priorité `PHP_INT_MIN`, accroché sur `init`) — et prochaine
  exécution ; file d'e-mails (en attente, abandonnés 7 jours, derniers échecs) ; webhooks Discord
  (hôte seulement, jamais l'adresse) et bouton « Envoyer un test » (admin-post `yume_sante_webhook`,
  nonce, `yume_reglages` ou `manage_options` (sinon 403), envoi par `Planning\envoyer_test_discord()` donc
  `envoyer_discord()` ; message `message_test_webhook()` mémorisé par `retour_formulaire()` puis
  retour sur `?vue=sante#yn-sante-retour`, `retour_test_webhook()`) ; version installée et dernière release lue dans l'option
  `external_updates-yume-core` de Plugin Update Checker (sinon transient `update_plugins`), sans
  requête réseau ; prérequis de mise en production (`etat_prerequis()`, administrateurs).
  Tests *Outils → Santé du site* (`site_status_tests`, directs) : `yume_taches_cron` (critique si
  rappels, récapitulatif ou envoi non programmés, recommandé si une tâche a plus d'une heure de
  retard), `yume_emails` (abandon depuis 7 jours ou attente de plus d'une heure), `yume_webhooks`
  (canal non configuré), `yume_version` (release plus récente connue), `yume_inscriptions`
  (depuis 2.5.0 : « recommandé » quand `Social\inscriptions_ouvertes()` est faux — réglage
  WordPress `users_can_register`, seule source de vérité, et filtre `yume_inscriptions_ouvertes` ;
  « Les inscriptions des lecteurs sont fermées (Réglages → Général) : le formulaire « Créer un
  compte » et la connexion Discord ne créent aucun compte. » ; `Core\test_sante_inscriptions()`,
  `Core\inscriptions_lecteurs_ouvertes()`). La vue `?vue=sante` ne reprend pas ces tests.

## 6 ter. Sécurité (module core, `includes/core/securite.php` ; module updater, `integrite.php`)

- **REST utilisateurs** : `/wp/v2/users` et `/wp/v2/users/{id}` réservés aux comptes
  `list_users` (filtre `yume_acces_utilisateurs_rest`) ; `rest_prepare_user` retire `slug` et `link`
  (pas d'identifiant de connexion exposé). Métas d'équipe `yume_responsables` et `yume_maj_par`
  marquées `prive` : jamais exposées hors contexte `edit`.
- **En-têtes** (`send_headers`, `admin_init`, `login_init` ; filtre `yume_entetes_securite`) :
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy`, `X-Frame-Options: SAMEORIGIN` et
  `Content-Security-Policy: frame-ancestors 'self' https://wordpress.com https://*.wordpress.com`
  (aperçu du tableau de bord WordPress.com), `Content-Security-Policy-Report-Only:
  object-src 'none'; base-uri 'self'`. Sur `/embed/` : ni X-Frame-Options ni CSP. Balise
  `generator` retirée.
- **XML-RPC** : `system.multicall`, `pingback.*` et méthodes de lecture des utilisateurs retirées,
  authentification par XML-RPC refusée, en-tête `X-Pingback` retiré ; exception pour les requêtes
  signées de Jetpack (`requete_jetpack()`, filtre `yume_requete_jetpack`).
- **Comptes** : inscription et « mot de passe oublié » limités en débit, réponse identique que le
  compte existe ou non ; changement d'adresse : avis à l'ancienne adresse et sessions fermées.
- **Connexion en façade sans wp-login.php** (`includes/social/connexion.php`) : le formulaire du
  bloc `yume/account` (page `connexion`, sinon `compte`) poste vers `admin-post.php`, action
  `yume_connexion` (`admin_post_nopriv_` et `admin_post_`), nonce `yume_connexion` (champ
  `_yn_nonce`), champs `yn_identifiant` (pseudo ou e-mail, `autocomplete="username"`),
  `yn_mot_de_passe` (`current-password`), `yn_se_souvenir`, `yn_origine` (page du formulaire) et
  `redirect_to` (`wp_validate_redirect()`, même site ; défaut : filtre `login_redirect`, donc
  `redirection_connexion()` : Mon compte pour un lecteur, espace équipe pour un traducteur…).
  `traiter_connexion()` : `wp_authenticate()` (chaîne `authenticate` complète : Jetpack Protect et
  les autres protections s'appliquent, `wp_login_failed` en cas d'échec), puis, comme
  `wp_signon()`, `wp_set_auth_cookie()` (filtre `secure_signon_cookie`), `wp_set_current_user()` et
  action `wp_login`. **Administrateurs** (`manage_options`, super-administrateur en multisite ;
  filtre `yume_connexion_reservee_wordpress( bool, WP_User )`) : refusés même avec le bon mot de
  passe, vérifiés après `wp_authenticate()` et avant toute session → message « Les administrateurs
  se connectent par la page de connexion WordPress » + lien `url_connexion_administrateur()`
  (`wp_login_url()`, WordPress.com / Jetpack SSO et sa validation en deux étapes) ; mauvais mot de
  passe d'un administrateur : message générique (le statut n'est pas révélé). Échec : retour sur la
  page du formulaire (jamais wp-login.php ni l'administration) avec `yn-msg=connexion-echec`
  (« Identifiant ou mot de passe incorrect. »), `yn-identifiant` pré-rempli (jamais le mot de passe)
  et ancre `#yn-connexion-message` ; message `role="alert"` `data-yn-focus` focalisé par
  `blocks/account/view.js`. **Limitation propre** (Jetpack Protect ne voit plus ces tentatives
  sur wp-login.php) : échecs comptés par adresse IP et par compte (compte existant par son ID,
  sinon la saisie), clés `yume_cnx_{hmac}` (transients, HMAC `wp_salt( 'nonce' )`) ; au 5e échec en
  15 min, blocage 15 min (`yn-msg=connexion-bloquee&yn-minutes=N` : « Trop de tentatives, réessayez
  dans N minutes. », `wp_login_failed` déclenchée aussi pendant le blocage) ; compteurs effacés au
  succès ; seuils : filtre `yume_connexion_limites` (`tentatives`, `periode`, `blocage` en
  secondes). **Liens** : filtre `login_url` (priorité 99, `filtre_url_connexion()`) → page de
  connexion en façade (+ `redirect_to`) partout sauf dans l'administration (`auth_redirect()`),
  sur wp-login.php (hors `action=rp|resetpass`) et sans page Yume ; `admin-post.php` et
  `admin-ajax.php` sont redirigés vers la façade. L'ancien retour en façade après un échec sur
  wp-login.php (`yn_origine` + `wp_login_failed`) est retiré ; inscription et mot de passe oublié
  restent en admin-post (`yume_inscription`, `yume_oubli`, erreurs toujours en façade).
- **Intégrité des mises à jour** : `upgrader_pre_download` (priorité 999) télécharge le paquet
  depuis un hôte autorisé (github.com, objects/release-assets.githubusercontent.com,
  api.github.com), compare son SHA-256 au fichier `SHA256SUMS` de la release et refuse sinon
  (`WP_Error` `yume_maj_*`, action `yume_updater_refus`). Sans `SHA256SUMS` : refus si
  `YUME_EXIGER_EMPREINTE` (ou filtre `yume_updater_exiger_empreinte`) est vrai, sinon avertissement.

## 7. API PHP partagée (signatures figées)

**core** (`includes/core/api.php`) :

```php
yume_setting( string $key, $default = null );
yume_get_tomes( int $oeuvre_id, array $args = array() ): array;      // WP_Post[] ; args: status ('publish'|'any'), order ('ASC'|'DESC' sur yume_numero), nature
yume_get_chapitres( int $tome_id, array $args = array() ): array;    // WP_Post[] triés par menu_order puis yume_numero ; args: status
yume_get_oeuvre_id( int $post_id ): int;                              // œuvre d'un tome ou chapitre (ou elle-même)
yume_get_tome_id( int $chapitre_id ): int;
yume_chapitre_voisin( int $chapitre_id, string $sens ): ?WP_Post;     // 'prev'|'next', traverse les tomes publiés de l'œuvre
yume_get_cover_id( int $post_id ): int;                               // couverture du tome, sinon de l'œuvre, sinon 0
yume_libelle_tome( int $tome_id, bool $court = false ): string;       // « Tome 9 », « Arc 7 », court : « T.9 »
yume_libelle_chapitre( int $chapitre_id ): string;                    // « Chapitre 3 », « Postface »
yume_types(): array; yume_statuts(): array; yume_natures_tome(): array; yume_etapes(): array; // slug => libellé
yume_liens_telechargement( int $tome_id ): array;                     // ['pdf'=>url|'' , 'epub'=>url|'']
yume_user_can_edit_planning( int $tome_id, int $user_id = 0 ): bool;
yume_illustrations_tome( int $tome_id ): array;                       // int[] images de la galerie (yume_illustrations) valables, ordre de lecture
yume_url_illustrations( int $tome_id ): string;                       // /lire/{o}/{tome}/illustrations/ ou '' (pas de page : §3)
yume_est_page_illustrations(): bool;                                  // requête principale = page Illustrations (objet de la requête : le tome)
yume_url_illustrations_avant( int $chapitre_id ): string;             // page Illustrations qui précède ce chapitre (premier publié du tome) ou ''
yume_url_page( string $cle ): string;                                 // 'bibliotheque','planning','equipe','publier','membres','compte','connexion','actualites','mentions-legales','rejoindre','accueil' → URL de la page (option yume_pages) ; filtre yume_url_page
yume_rest_arg_vide_absent( array $schema ): array;                    // argument REST facultatif : '' (champ de formulaire vide) lu comme absent (null), sinon contrôlé selon le schéma
```

**mangadex** (`includes/mangadex/api.php`, depuis 2.3.0) :

```php
yume_mangadex_chapitre( int $chapitre_id ): ?array;          // {uuid, pages, version, groupes: string[], url} ; null pour un chapitre importé ou un autre contenu
yume_mangadex_serie( int $oeuvre_id ): string;               // série MangaDex de l'œuvre ou ''
yume_mangadex_oeuvre_par_serie( string $serie ): int;        // première œuvre liée (hors corbeille), 0 sinon ; accepte une adresse mangadex.org
yume_mangadex_chapitre_par_uuid( string $uuid ): int;        // chapitre du site lié à ce chapitre MangaDex (hors corbeille), 0 sinon
yume_mangadex_url_page( int $chapitre_id, int $page, string $qualite = 'data-saver' ): string; // adresse relayée d'une page (§8 bis), '' hors du chapitre ; qualité data-saver | data
```

**planning** (`includes/planning/api.php`) :

```php
yume_planning_etat( int $tome_id ): string;         // 'publie'|'bloque'|'en_retard'|'a_lheure' (en_retard : date_cible < aujourd'hui, ou derniere_maj > rappel_jours_sans_maj jours)
yume_get_planning( array $args = array() ): array;  // lignes : ['tome_id','oeuvre_id','oeuvre','tome','etape','avancement','responsables'=>[etape=>['id','nom']], 'date_cible','etat','derniere_maj','url_oeuvre', …, 'programme' (bool, statut future),'date_programmee' (Y-m-d Paris ou '')]
                                                    // args: 'oeuvre_id', 'type' (slug yume_type), 'etat', 'a_venir' (bool, exclut publie), 'limit' (0 = tout), 'inclure_publies_depuis' (jours, défaut 14), 'publies_du_jour' (bool, défaut faux : tomes publiés seulement depuis minuit heure de Paris, plus les tomes publiés en cours de publication — page Planning),
                                                    //       'responsable' (user_id), 'gestion' (bool : tous les tomes vivants, filtrables par 'statut' WordPress)
yume_journal_planning( int $tome_id, int $user_id, string $champ, $ancien, $nouveau ): void;
yume_queue_email( $destinataire, string $sujet, string $html, string $contexte = '' ): void; // user_id ou e-mail ; envoi par lot (cron), gabarit HTML Yume
yume_discord( string $canal, string $texte, array $embeds = array() ): bool;                 // canal 'sorties'|'chapitres'|'equipe' ('chapitres' : voir discord_chapitres)
```

`yume_url_page()` ne renvoie la page enregistrée que si elle est publiée ainsi que tous ses parents ;
sinon l'adresse du slug du §11 (ex. `/equipe/publier/`, `/` pour `accueil`). Un tome programmé
(statut `future`) est toujours `a_lheure`, libellé « Programmé le … », et sa `date_cible` suit la date
programmée. `Yume\Core\Planning\retirer_tome( int $tome_id, int $user_id ): true|WP_Error` : tome à la
corbeille, journal `retire` (public) ; exige `yume_maj_planning_tous` et `delete_post` (403
`yume_retrait_interdit`), refusé (409 `yume_retrait_impossible`) pour un tome publié, programmé,
privé ou ayant un chapitre publié.

**planning, vitrine publique** (`includes/planning/vitrine.php`, namespace `Yume\Core\Planning`, depuis
2.1.6) : données et rendu partagés par la section Planning de l'accueil (`yume/planning-accueil`) et la
page Planning (`yume/planning`). Styles : `includes/planning/assets/vitrine.css`, chargés avec ces blocs.

```php
prochain_tome(): ?array;                                   // tome « À la une : prochain tome », null s'il n'y en a pas
file_chapitres( array $args = array() ): array;            // ['prochains' => …, 'publies' => …, 'tomes_en_cours' => …]
                                                           // args : 'prochains' (3), 'publies' (4), 'jours_publies' (14), 'oeuvre_id' (0 = toutes)
tomes_en_preparation( int $exclure_tome = 0, int $limite = 4 ): array; // tomes à venir, sans le tome à la une
rendu_a_la_une(): string;                                  // bandeau du prochain tome ('' sans tome à la une)
rendu_file_chapitres(): string;                            // file « Chapitres en lecture »
rendu_tomes_preparation(): string;                         // liste « Tomes en préparation » + légende
```

Règles de sélection (aucune saisie propre à la vitrine : tout vient du planning, des chapitres et des
méta `yume_chapitres_prevus` / `yume_rythme` du tome) :

- **Prochain tome à la une** : parmi les tomes du planning public (œuvre publiée), le tome non publié,
  non bloqué, dont la date de sortie est la plus proche : date programmée (statut `future`) sinon
  date cible. Un tome en cours de publication chapitre par chapitre (`yume_parution_tome()` =
  `en_cours`) n'y figure jamais : il est dans la file des chapitres. Le bandeau montre la couverture,
  l'œuvre, le tome, la date, le compte à rebours (« J-2 »), les barres Traduction / Relecture /
  Édition et les boutons « Suivre l'œuvre » et « Voir la fiche ».
- **File des chapitres** : œuvres publiées seulement, jamais un chapitre retiré (`_yume_retire`).
  `prochains` = chapitres programmés (statut `future`), les plus proches d'abord ; `publies` =
  chapitres publiés depuis `jours_publies` jours, les plus récents d'abord (badge « Nouveau » de
  moins de 48 h) ; `tomes_en_cours` = tomes en cours de publication, avec « N / M » (chapitres en
  ligne sur `yume_chapitres_prevus`) et leur rythme. Rendu en frise : « Prochains · programmés »
  (bouton « Me prévenir »), repère « Aujourd'hui », « Publiés récemment » (bouton « Lire »), lien
  « Tous les chapitres publiés → ».
- **Tomes en préparation** : tomes à venir du planning public, hors tome à la une (`$exclure_tome`)
  et hors tomes en cours de publication, triés par date (programmée ou cible), les tomes sans date
  (« à venir ») ensuite ; étape, pourcentage, date cible ou « à venir », pastille d'état
  (`yume_planning_etat()`), légende.

Heure de sortie : quand `yume_heure_cible` est connue, la date cible s'affiche avec elle
(« sam. 10 oct. · 20 h ») sur la page Planning, dans le bandeau « À la une » (compte à rebours
« Aujourd'hui à 20 h » le jour même) et dans les fiches de l'équipe ; sans heure, la date seule.
Un tome programmé (statut `future`) affiche sa date et son heure de publication.

**Série à venir** (titre caché au public) :

```php
yume_oeuvre_a_venir( int $oeuvre_id ): bool;         // vrai si l'œuvre est marquée yume_serie_a_venir ET pas encore publiée (brouillon, en attente, programmée)
yume_titre_public_oeuvre( int $oeuvre_id ): string;  // série à venir : yume_libelle_a_venir, sinon « Nouvelle série à venir » ; autrement : le titre
```

- **Visibilité** : une œuvre série à venir peut rester en brouillon ; ses tomes (le Tome 1) figurent
  quand même au planning public. Les autres œuvres non publiées restent exclues du planning public,
  leurs tomes compris.
- **Masquage public** (accueil, page Planning, `yume/calendrier`, `GET /planning.ics`, flux RSS et
  JSON du planning, journal public) : le titre de l'œuvre est remplacé par
  `yume_titre_public_oeuvre()`, sans sous-titre de tome, sans lien (ni fiche, ni tome, ni `URL`
  ICS), sans couverture et sans bouton « Suivre l'œuvre ». Date, heure, étape et avancement
  restent publics.
- **Équipe** : le vrai titre partout dans l'espace équipe, avec la pastille « Titre caché au
  public ».
- **Révélation** : en décochant la case (« Modifier l'œuvre »), ou automatiquement à la première
  publication de l'œuvre ou d'un de ses tomes ou chapitres : la méta est retirée et le
  changement est noté au journal de l'équipe (jamais public).

**social** (`includes/social/api.php`) :

```php
yume_get_progression( int $user_id, int $oeuvre_id = 0 ): array;   // oeuvre_id=0 : toutes, triées par updated_at desc ; ligne : ['oeuvre_id','chapitre_id','tome_id','paragraphe','pourcentage','updated_at']
yume_is_favori( int $user_id, int $oeuvre_id ): bool;
yume_get_abonnes( int $oeuvre_id, string $frequence = 'immediat' ): array; // user_id[]
yume_get_note( int $user_id, int $oeuvre_id ): int;                        // 0 si aucune
```

**social — comptes lecteurs** (`includes/social/creation-compte.php`, espace de noms
`Yume\Core\Social`, depuis 2.5.0, lot 0 du chantier « lecteurs ») : **tout** compte lecteur créé hors
de wp-admin (inscription en façade, Discord, équipe) passe par `creer_compte_lecteur()`.

```php
creer_compte_lecteur( array $donnees ): int|\WP_Error;
//  $donnees : pseudo (obligatoire), email (obligatoire), identifiant ('' = generer_identifiant()),
//             mot_de_passe ('' = aléatoire, inconnu du titulaire), source ('inscription' (défaut) | 'discord' | 'equipe' ;
//             autre valeur → 'inscription'), notifier (bool, défaut vrai), meta (array clé => valeur, posées à la création)
//  erreurs (code, statut) : yume_inscriptions_fermees (403 ; sources inscription et discord si ! inscriptions_ouvertes()),
//             yume_pseudo_invalide (400), yume_pseudo_pris (409), yume_email_invalide (400), yume_email_pris (409),
//             yume_identifiant_invalide (400 : validate_username() faux, plus de 60 caractères, identifiant interdit),
//             yume_identifiant_pris (409), yume_creation_echec (500 ; WP_Error de wp_insert_user() en donnée 'erreur')
valider_pseudo( string $pseudo, int $user_id = 0 ): true|\WP_Error;   // yume_pseudo_invalide (donnée 'raison') | yume_pseudo_pris
generer_identifiant(): string;                                       // 'yn-' . 8 caractères [a-z0-9], libre (username_exists et pseudo_pris)
pseudo_pris( string $pseudo, int $user_id = 0 ): bool;               // display_name OU user_login d'un autre compte, casse ignorée
compte_sans_mot_de_passe( int $user_id ): bool;                      // méta _yume_compte_sans_mdp (META_COMPTE_SANS_MDP)
pseudos_reserves(): array;                                           // filtre yume_pseudos_reserves
```

- **Création** : `wp_insert_user()` (jamais `register_new_user()` : ni `registration_errors` ni l'action
  `register_new_user` ; les protections anti-abus — pot de miel, jeton horodaté, limites par IP — restent
  à la charge de l'appelant), rôle `subscriber`, `display_name` = `nickname` = pseudo (espaces de début
  et de fin retirées), `meta_input` = `meta`. Mot de passe aléatoire : méta `default_password_nag`
  comme `register_new_user()`. Contrôles dans l'ordre : inscriptions, pseudo, e-mail, identifiant.
- **E-mails** : `wp_send_new_user_notifications( $id, notifier ? 'both' : 'admin' )` ; l'e-mail à
  l'administrateur suit toujours le filtre `yume_notifier_admin_inscription( bool $notifier = false,
  int $user_id, string $source )` (deux arguments ajoutés en 2.5.0, compatibles).
- **Action** `yume_lecteur_inscrit( int $user_id, string $source )` (2ᵉ argument ajouté en 2.5.0,
  compatible) émise en dernier, rôle, métas et e-mails en place.
- **`valider_pseudo()`** (pseudo comparé après `trim`) : `raison` = `vide` (rien ou que des espaces),
  `longueur` (2 à 40 caractères, `mb_strlen`), `balise` (balise HTML, `<`, `>` ou caractère de contrôle,
  tabulation comprise), `email` (contient `@` ou `is_email()`), `reserve` (égal, une fois réduit par
  `forme_pseudo()` — minuscules, sans accents, espaces, points, tirets et tirets bas confondus — à un
  pseudo de `pseudos_reserves()` : `admin`, `administrateur`, `yume`, `yume novel`, `équipe`, filtre
  `yume_pseudos_reserves`) ; puis `pseudo_pris( $pseudo, $user_id )` → `yume_pseudo_pris`. Un pseudo ne peut
  donc pas reprendre l'identifiant d'un ancien compte.
- **Inscription en façade** (`Social\traiter_inscription()`, inchangée pour le visiteur jusqu'au lot
  L6) : pseudo `sanitize_user( …, true )` de 3 à 40 caractères, puis `creer_compte_lecteur( [ 'pseudo' =>
  $pseudo, 'email' => $email, 'identifiant' => $pseudo, 'source' => 'inscription' ] )` ; codes traduits
  en `yn-msg` : `yume_inscriptions_fermees` → `inscriptions-fermees`, `yume_pseudo_invalide` et
  `yume_identifiant_invalide` → `pseudo-invalide`, `yume_pseudo_pris` et `yume_identifiant_pris` →
  `pseudo-pris`, `yume_email_invalide` → `email-invalide`, `yume_email_pris` → réponse neutre
  `inscription-ok` + `prevenir_adresse_prise()`, autre → `inscription-refusee`. Comme `valider_pseudo()`
  s'applique, un pseudo égal au nom affiché d'un autre compte, contenant `@` ou réservé est désormais
  refusé à l'inscription.
- **`_yume_compte_sans_mdp`** : posée par l'appelant (`meta`) pour un compte dont le titulaire ne
  connaît pas de mot de passe (Discord) ; retirée par le lot L6 (`comptes.php`) sur
  `after_password_reset`, `wp_set_password` et `profile_update` avec changement de mot de passe.

**social — page compte** (`includes/social/compte.php`) : points d'extension du bloc `yume/account`.

```php
apply_filters( 'yume_compte_rubriques', array $rubriques /* ancre => libellé, dans l'ordre */, int $user_id );
apply_filters( 'yume_compte_sections_html', array $sections /* ancre => HTML, dans l'ordre */, \WP_User $user );
apply_filters( 'yume_compte_html_connexion_extra', string $html /* '' */, string $demandee );  // après formulaire_connexion(), avant « Mot de passe oublié ? »
apply_filters( 'yume_compte_html_inscription_extra', string $html /* '' */, string $ici );    // sous le formulaire d'inscription, ou sous « Les inscriptions sont fermées »
```

- Ancres existantes, dans l'ordre : `yn-lecture`, `yn-stats`, `yn-favoris`, `yn-listes`,
  `yn-notifications`, `yn-notes`, `yn-reglages`, `yn-alertes`, `yn-profil`, `yn-profil-public`
  (équipe seulement : rubrique ajoutée par `rubriques_profil_public()` avant le filtre, section vide
  sinon), `yn-donnees`. Un module insère sa rubrique **et** sa section **après une ancre connue** (même
  ancre dans les deux filtres), en reconstruisant le tableau dans l'ordre ; à défaut d'ancre trouvée, il
  l'ajoute à la fin. Ancres prévues : `yn-rattrapage` (L5, « À rattraper », après `yn-lecture`),
  `yn-pour-vous` (L3, « Pour vous ») ; L2 ajoute la rubrique « Connexion Discord ».
- Une section commence par `Social\debut_section( $ancre, $titre, $aide = '' )` (`<section
  class="yn-account__panneau" id data-yn-panneau tabindex="-1">` + `h2`) et se ferme par `</section>` ;
  les onglets (`view.js`) suivent `data-yn-onglet` / `data-yn-panneau` : aucun JavaScript propre au module.
  `Social\sections_compte( \WP_User $user, array $favoris ): array` construit le tableau et applique le
  filtre (valeurs non chaînes ignorées) ; les rubriques dont l'ancre ou le libellé n'est pas une chaîne
  sont ignorées.
- Les filtres HTML visiteur renvoient du HTML **déjà échappé** par le module ; `$demandee` = `redirect_to`
  validé (`wp_validate_redirect`) ou `''`, `$ici` = `url_courante()`.

**social — RGPD** (`includes/social/rgpd.php`) :

```php
apply_filters( 'yume_donnees_personnelles', array $donnees, int $user_id );  // fin de donnees_personnelles() : export JSON GET /moi/export
do_action( 'yume_effacer_donnees', int $user_id );                            // fin de effacer_donnees(), après les suppressions du module social
```

Un module **ajoute** sa clé à l'export (jamais de secret ni de jeton ; clés réservées :
`signalements_coquilles` L1, `discord` L2) sans modifier les clés existantes (l'exportateur WordPress
`yume-core-lecteur` les lit) ; une valeur filtrée non tableau est ignorée. `yume_effacer_donnees` est
émise par `effacer_donnees()` (jamais pour `$user_id` ≤ 0), donc par l'effaceur WordPress
`yume-core-lecteur` et par `supprimer_compte()` (après `yume_compte_suppression( $user_id )` et
l'anonymisation des commentaires, avant `wp_delete_user()` qui émet `deleted_user`). Un module garde en
plus ses propres exportateur et effaceur WordPress (`yume-core-coquilles`, `yume-core-discord`) et
écoute `deleted_user` pour une suppression faite dans wp-admin.

**import** : `Yume\Core\Import\Docx_Converter::convert_file( string $path, array $options = array() ): Yume\Core\Import\Result`
et `Yume\Core\Import\Epub_Converter::convert_file(...)` (même résultat) ; ces classes n'utilisent
**aucune** fonction WordPress (réutilisées par l'outil en ligne de commande). Voir §9.

## 8. Événements métier (actions)

| Action | Arguments | Émise par | Écoutée par |
| --- | --- | --- | --- |
| `yume_tome_publie` | `int $tome_id` | publication (et core sur `transition_post_status` d'un tome vers `publish`, une seule fois par tome : meta `_yume_publie_notifie`) | planning (étape `publie`, 100 %, journal, Discord ; si des chapitres restent à sortir, étape gardée et journal `publie` partiel, « publié » au dernier chapitre ; tome en cours de parution, `yume_parution = en_cours` : toujours sortie partielle, avancement selon les chapitres, Discord « Tome 2 : Prologue disponible ! »), social (e-mails aux abonnés), core (cache `yume_derniere_sortie`) |
| `yume_chapitre_publie` | `int $chapitre_id` | core (`transition_post_status` d'un chapitre publié isolément, hors publication de tome) | social (abonnés), planning (Discord ; avancement d'un tome en cours de parution) |
| `yume_planning_mis_a_jour` | `int $tome_id, array $changements, int $user_id` | planning | planning (vide le cache du calendrier ICS, transient `yume_planning_ics`) |
| `yume_publication_preparee` | `int $tome_id, array $rapport` | publication | — |
| `yume_tome_complet` | `int $tome_id, bool $annoncer` | publication (`Service::marquer_complet()` : case « Le tome est complet », tout de suite ou à la sortie du dernier chapitre programmé ; seulement pour un tome en ligne qui n'était pas complet) | planning (`appliquer_sortie()` : étape `publie`, 100 %, journal `publie` complet ; Discord « … est complet : PDF et EPUB disponibles » si `$annoncer`) |
| `yume_planning_pause` | `int $tome_id, bool $pause, int $user_id` | planning (`basculer_pause()`) | planning (vide le cache ICS `yume_planning_ics` et les indicateurs `yume_kpi_*`, comme `yume_planning_mis_a_jour`, qui n'est pas émise : ses écouteurs attendent des changements de champs, la pause n'en est pas un) |
| `yume_commentaire_signale` | `int $comment_id, int $user_id, int $actifs, bool $attente` | social (`signaler_commentaire()`) | — |
| `yume_lecteur_inscrit` | `int $user_id, string $source` (`inscription`, `discord`, `equipe` ; 2ᵉ argument depuis 2.5.0) | social (`creer_compte_lecteur()`, §7 « Comptes lecteurs ») | — |
| `yume_compte_suppression` | `int $user_id` | social (`supprimer_compte()`, données encore présentes) | modules qui gardent des données liées au compte |
| `yume_effacer_donnees` | `int $user_id` | social (`effacer_donnees()` : effaceur WordPress, suppression du compte ; depuis 2.5.0) | corrections (anonymisation), discord (métas) |
| `yume_coquille_signalee` | `int $id, int $chapitre_id, int $user_id` | corrections (`signaler_coquille()`, §8 quater) | corrections (Discord équipe) |
| `yume_coquille_traitee` | `int $id, string $statut, int $user_id` | corrections (`changer_statut()`) | corrections (notification du lecteur à « corrigé ») |
| `yume_discord_lie` / `yume_discord_delie` / `yume_discord_compte_cree` | `int $user_id, string $discord_id` / `int $user_id` / `int $user_id` | discord (§8 quinquies) | — |

Filtres du lot planning/modération : `yume_rappels_plafond` (int, semaines de retard au-delà
desquelles un tome ne reçoit plus de rappel, défaut 8, 0 = sans plafond : il reste dans le
récapitulatif, avec la mention « Plus de rappel automatique ») ; `yume_planning_delai_rappel`
(jours, défaut 3 : délai de la relance ; ensuite au plus un rappel par semaine, compté depuis la
dernière mise à jour du tome) ; `yume_signalements_seuil` (int, défaut 3 : comptes différents qui
renvoient un commentaire publié en attente de modération ; seuls comptent les comptes inscrits depuis
au moins `yume_signalement_anciennete` jours (int, défaut 7, 0 = tous), et un commentaire d'un membre
de l'équipe (`yume_voir_equipe`) ne passe jamais automatiquement en attente : il reste publié et
signalé dans la vue de modération) ; `yume_signalements_debit` (int, défaut
10 signalements par compte et par heure, `limite_atteinte( 'signalement', … )`).

Émission : core note la transition sur `transition_post_status` mais émet sur `wp_after_insert_post`
(les méta arrivent après le statut en REST). Aucune émission par core pendant
`did_action( 'yume_publication_en_cours' )` (publication émet elle-même `yume_tome_publie` après avoir
tout publié), avec `WP_IMPORTING`, pour un contenu daté de plus de 2 jours, ou si le filtre
`yume_core_notifier` renvoie faux (la migration l'utilise). `yume_chapitre_publie` exige que le tome
ait été publié au moins 15 minutes avant (`yume_delai_publication_groupee`). Une sortie programmée
(`future`) est émise par core au passage à `publish`.

Pour ne pas notifier chaque chapitre d'un tome publié en bloc, publication définit la constante
d'exécution `yume_publication_en_cours` via `did_action`/drapeau statique ; core n'émet
`yume_chapitre_publie` que si le tome parent était déjà publié avant.

Dépublication d'un tome (`publish` → autre statut, hors corbeille) : planning « publié » → `edition`
(avancements gardés), journal `depublie`, `yume_derniere_sortie` recalculée, annonce remise en
brouillon. Son retour en ligne rétablit la sortie (journal `publie` avec `retour`) **sans** réémettre
`yume_tome_publie` (ni annonce Discord ni e-mails).

**Ajout au catalogue (sans annonce)** — `Service::publier( $tome_id, $quand, array( 'sans_annonce' => true ) )`
(`Service::ajouter_au_catalogue()`), pour mettre en lecture en ligne un tome déjà paru (PDF/EPUB
seuls) : chapitres publiés ou programmés normalement, mais **aucun** `yume_tome_publie` ni
`yume_chapitre_publie` pour l'opération (filtre `yume_core_notifier` coupé le temps de l'opération,
comme la migration), aucun article d'annonce créé, mis à jour ou publié (déjà à la préparation :
`preparer()` avec `sans_annonce`), donc ni Discord, ni e-mail, ni récapitulatif hebdomadaire. Les
chapitres et le tome jamais annoncé (`_yume_publie_notifie` vide ou `ignore`) reçoivent
`_yume_publie_notifie = catalogue` (`Service::NOTIFIE_CATALOGUE`) : core n'émet rien, même quand le
cron publie une sortie programmée, et une sortie ultérieure de nouveaux chapitres est annoncée par
`yume_chapitre_publie` (jamais `yume_tome_publie` pour le contenu ancien). Tome déjà paru,
publication immédiate : les chapitres prennent la date du tome (`yume_derniere_sortie` inchangée).
Tome pas encore en ligne : étape planning `publie` sans ligne `publie` du journal public. Journal :
champ `lecture_ajoutee` (`{chapitres, programme}`, jamais public). Défaut (formulaire, REST) :
`Service::sans_annonce_par_defaut()` = vrai pour un tome au statut `publish` dont la parution
(`yume_parution_tome()`) n'est pas `en_cours` (tome paru et complet, ou tome antérieur à la méta
`yume_parution`), faux sinon (nouveau tome, brouillon, programmé, tome en cours de parution : ses
nouveaux chapitres sont annoncés) ; le service seul vaut faux par défaut.

**Remplacement de la lecture en ligne** d'un tome paru qui a déjà des chapitres publiés (vue équipe
`?vue=tomes` → formulaire `?tome=ID`), **en deux temps** (`includes/publication/class-remplacement.php`) :

1. **Préparation, rien ne change en ligne** : pour un tome `publish` qui a au moins un chapitre
   publié (`Remplacement::mode()`), `preparer()` avec un fichier (boutons « Vérifier (sans rien
   changer en ligne) » = étape `verifier`, « Enregistrer en brouillon », « Prévisualiser ») crée une
   **version en attente** par chapitre du fichier (statut `yume_remplacement`, §4), rapprochée du
   chapitre existant par nature + numéro (`_yume_remplacement_de`). Aucun chapitre, aucune galerie
   ni couverture du tome n'est modifié ; les images nouvelles sont versées sans rattachement et
   suivies (`_yume_remplacement.medias`) ; les absents sont seulement signalés. Rapport :
   `remplacement` = `Remplacement::etat()` (`texte`, `bilan`, `message`, `chapitres[{id, libelle,
   titre, action, etat, remplace, apercu, lien}]`, `remplaces`, `nouveaux`, `inchanges`, `absents`,
   `retirer_absents`, `auteur`, `le`, `expire_le`) ; lignes de `chapitres` = versions en attente
   (`statut` `yume_remplacement`, `apercu` = lien d'aperçu, `edition` vide). Aperçu : `noindex,
   nofollow` (`wp_robots`) et `nocache_headers()`. Une nouvelle préparation du même membre remplace
   la précédente (images de même empreinte réutilisées, celles que plus rien n'utilise supprimées).
2. **Application** : `publier()` (« Remplacer la lecture en ligne maintenant » = étape `remplacer`,
   ou « Publier maintenant ») appelle d'abord `Remplacement::appliquer()` : chaque version est
   recopiée **en place** dans son chapitre (titre, contenu, ordre, méta ; mêmes ID, adresses, dates
   et commentaires ; rien n'est réécrit si le contenu est identique) puis supprimée ; une version
   sans chapitre devient un chapitre brouillon du tome, publié ensuite (date du tome en mode
   catalogue) ; absents mis en brouillon + `_yume_retire` seulement avec `retirer_absents` ; images
   rattachées à leur chapitre, galerie et couverture de l'EPUB posées, `_yume_publication.fichier`
   mis à jour. Résultat de `publier()` : `remplacement_applique` (`{remplaces, inchanges, nouveaux,
   retires}` ou null) ; en mode catalogue `remplacement` = true et `en_ligne` ; message « lecture en
   ligne remplacée (N chapitres en ligne ; M nouveaux), sans annonce : ni article, ni Discord, ni
   e-mail. La date de sortie du tome ne change pas. » La date du tome n'est jamais modifiée.
3. **Annulation** (`Remplacement::annuler()`, étape `annuler_remplacement` ou
   `DELETE /publications/{id}/remplacement`) : versions et images nouvelles supprimées, rien ne change.

Un seul remplacement en attente par tome : `preparer()` d'un autre membre renvoie 409
`yume_remplacement_en_attente` (il peut appliquer ou annuler celui qui attend). Préparation
abandonnée supprimée après `yume_publication_remplacement_duree` (filtre, 7 jours) : tâche cron
unique `yume_publication_nettoyer_remplacement` (argument : tome), et au prochain envoi, affichage du
formulaire ou `publier()` (une préparation expirée n'est jamais appliquée). Tome brouillon, programmé
ou publié sans chapitre en ligne : pas de préparation séparée, `preparer()` crée ou met à jour les
chapitres directement (comportement inchangé). Le remplacement en deux temps est réservé au
**remplacement explicite** (étapes `verifier` / `remplacer` du formulaire, `mode=remplacement`)
et aux appels sans `mode` (API, `docx2chapters`) ; l'ajout de chapitres (ci-dessous) n'y passe
jamais, sauf pour les chapitres en ligne que l'équipe choisit de mettre à jour.

**Ajout de chapitres à un tome** (publication chapitre par chapitre ; formulaire « Ajouter des
chapitres à un tome », champ caché `mode=chapitres`, `Service::MODE_CHAPITRES` ; REST et
`docx2chapters --tome ID --chapitres`) :

- **Le tome d'abord** : le tome est choisi (`tome_id`, prioritaire sur nature + numéro, y compris
  sans `mode`) ; le service ne réécrit **jamais** la nature ni le numéro d'un tome existant (posés
  seulement s'ils manquent) ; son titre n'est réécrit qu'avec un `titre` explicite, d'après son
  propre libellé. Tome d'une autre œuvre : 400 `yume_tome_autre_oeuvre` ; tome inconnu en mode
  chapitres : 404 `yume_tome_invalide`. Sans tome choisi, le tome peut encore être créé (nature,
  numéro). Lien « + Nouveau tome » : `Formulaire::url_nouveau_tome()` (vue
  `Formulaire::VUE_NOUVEAU_TOME` de l'espace équipe, filtre `yume_url_nouveau_tome`).
- **Comparaison** (`Service::comparer()`, rapport `comparaison` de l'analyse et de la préparation :
  `lignes[{index, cle, numero, nature, libelle, sous_titre, titre, nb_mots, existant{id, statut,
  lien, date, date_libelle}|null, etat, etat_libelle, action, choix}]`, `nouveaux`, `identiques`,
  `modifies`, `a_mettre_a_jour`, `programmes`, `brouillons`) : chaque chapitre du fichier est
  rapproché par nature + numéro + rang (`cle`, ex. `chapitre:3#1`) ; état `nouveau` (créé en
  brouillon, placé après les chapitres du tome), `identique` (en ligne, même texte : rien n'est
  touché), `modifie` (en ligne, texte différent : `choix` `garder` par défaut, rien n'est touché ;
  `maj` = « Mettre à jour (sans annonce) »), `programme` / `brouillon` (pas encore visibles : mis
  à jour en place, date et ordre gardés). Rien n'est jamais retiré ni signalé absent. Texte
  comparé : titre + contenu normalisés (`Service::texte_normalise()` : illustrations, balises et
  commentaires retirés, entités décodées, espaces réduites), empreinte `Service::empreinte_texte()`
  ; méta interne d'un chapitre `_yume_empreinte_texte` (`Service::META_EMPREINTE`,
  `{contenu: md5 titre + contenu enregistrés, texte: empreinte}`), posée à la création, à la mise à
  jour et au remplacement, recalculée à la volée si elle manque ou si le chapitre a été retouché.
  Paramètre `choix` (objet ou JSON, clé => `garder` | `maj`, `Service::choix()`, 400
  `yume_choix_invalide`). L'analyse renvoie aussi `tome` (`Service::infos_tome()` : parution,
  chapitres en ligne et leur libellé, programmés, prévus, rythme en clair, `dates_rythme` des
  chapitres à sortir, `sans_annonce` par défaut).
- **Mises à jour choisies** : chaque chapitre `maj` devient une version en attente
  (`Remplacement`, état `_yume_remplacement.mode = chapitres`, mêmes règles : un seul remplacement
  par tome, 409 si un autre membre — ou un remplacement complet — attend), appliquée en place
  par `publier()` (mêmes ID, adresses, dates, ordre, commentaires ; jamais annoncée). Une nouvelle
  préparation du même membre sans mise à jour annule sa précédente préparation en mode chapitres. Un remplacement complet en
  attente n'est jamais appliqué par une sortie en mode chapitres.
- **Sortie** (`publier()` avec `mode=chapitres`, options `sortie`, `intervalle`, `complet`,
  `liens_dernier`, `liens`) : les brouillons du tome (hors chapitres retirés) sortent `maintenant` (ensemble, une
  seule annonce : `yume_tome_publie` à la première sortie, sinon `annoncer_groupe()`), `rythme`
  (un par un : dates successives de `yume_prochaine_sortie_rythme()` après le dernier chapitre
  déjà programmé et après maintenant ; sans rythme, `quand` (ou maintenant) puis tous les
  `intervalle` jours, défaut 7 ; annoncés par core à leur sortie, `yume_chapitre_publie`) ou
  `date` (ensemble à `quand`, sortie groupée programmée s'ils sont plusieurs, 400
  `yume_date_manquante` sans date) ; les chapitres déjà programmés gardent leur date (calendrier :
  `Service::calendrier()`). Avec `complet`, `sortie` est forcée à `maintenant` : tous les
  chapitres en attente du tome sortent, **programmés compris** (`future`), et
  `annuler_programmations()` annule d'abord la sortie groupée et le passage « complet »
  programmés. Réponse : `calendrier[{id, libelle, titre, statut, date,
  date_libelle, lien}]`, `mode`, `sortie`, `parution`, `complet` (`fait` | `programme` | vide),
  `complet_le`, `article_complet`, `remplacement_applique`, `en_ligne`, et en REST `message`
  (`Formulaire::message_chapitres()`).
- **Parution** : première sortie d'un tome sans « Tome complet » → `yume_parution = en_cours` ;
  le tome sort (ou est programmé) avec ses premiers chapitres ; article d'annonce « SukaMoka,
  Tome 2 : Prologue disponible ! » (`Annonce::titre()` avec `$sortie = {parution: en_cours,
  chapitres}`, `Annonce::titre_chapitres()` : un chapitre numéroté s'écrit en minuscule,
  « chapitres 1 à 3 disponibles ! ») ; Discord « Nouvelle sortie : **SukaMoka** — Tome 2 :
  Prologue disponible ! » (`Planning\annonce_tome()`, variante selon `yume_parution_tome()`).
  Tome complet publié d'un coup (« Tome complet » et une seule date de sortie) :
  `yume_parution = complet`, liens posés, sortie de tome habituelle (modèle `modele_annonce`,
  planning « publié » 100 %), sans annonce « complet » en plus.
- **Liens avec le dernier chapitre** (`liens_dernier`, case « Publier les liens avec le dernier
  chapitre », ignoré avec `complet`, gardé dans `_yume_publication.liens_dernier` / `.liens` entre
  la préparation et la sortie, remis à faux après) : le passage « complet » est programmé à la
  date du dernier chapitre programmé du tome (nouveaux ou déjà programmés,
  `Service::dernier_programme()`), comme ci-dessous ; rien de programmé → `marquer_complet()`
  tout de suite ; première sortie d'un tome dont tous les chapitres sortent ensemble (une seule
  date, rien d'autre de programmé) → tome complet publié d'un coup (paragraphe précédent), à
  cette date. Les liens restent masqués (`yume_liens_tome_masques()`) jusqu'au passage.
- **Chapitres prévus** (`chapitres_prevus`, entier 0 à 999, 400 `yume_chapitres_prevus_invalide`)
  : `Service::ajuster_chapitres_prevus( int $tome_id, ?int $saisi, bool $relever )` à la
  préparation (hors remplacement en deux temps) : valeur saisie, sinon la méta actuelle ; si elle
  est > 0, relevée au nombre de chapitres du tome (tous statuts actifs, hors retirés) quand il est
  plus grand ; changement journalisé (`chapitres_prevus`, privé à l'équipe). Réponse de la
  préparation : `chapitres_prevus {avant, apres, auto}`.
- **Tome complet** d'un tome en cours (case « Le tome est complet : tout publier maintenant »,
  `Service::marquer_complet( int $tome_id, array $liens, bool $annoncer )`, réutilisable) : liens
  `lien_pdf` / `lien_epub` posés (en mode chapitres, les liens ne sont posés qu'à ce moment ; gardés
  dans `_yume_publication.complet` / `.liens` entre la préparation et la sortie),
  `yume_parution = complet`, puis, pour un tome en ligne qui n'était pas complet, action
  `yume_tome_complet( $tome_id, $annoncer )` (`$annoncer` vrai seulement s'il était en cours de
  parution et que l'annonce est demandée) : planning `appliquer_sortie()` (étape « publié »,
  100 %, journal `publie` complet), Discord « Tome complet : **SukaMoka** — Tome 2 est complet : PDF
  et EPUB disponibles ! » et article publié « Le tome 2 de SukaMoka est complet : PDF et EPUB
  disponibles » (`Annonce::complet()`, méta `_yume_annonce_complet` = tome, un seul par tome,
  retiré et republié avec le tome comme l'annonce de sortie). Avec des chapitres programmés, le
  passage est programmé à la sortie du dernier (méta du tome `_yume_complet_programme`
  `{ts, annoncer, liens}`, tâche cron unique `yume_publication_tome_complet` (argument : tome),
  `Service::complet_programme()` : publie d'abord les chapitres échus, se repousse si des
  chapitres sont programmés plus tard, abandonnée si le tome a quitté le site).
- **Planning** d'un tome `en_cours` : `appliquer_sortie()` ne le passe jamais « publié »
  (sortie partielle et journal `publie` partiel, même sans chapitre en attente ;
  `verifier_fin_sortie()` attend « Tome complet ») ; l'avancement suit les chapitres
  (`Planning\avancement_chapitres()` : avec `yume_chapitres_prevus` > 0, chaque étape de travail
  vaut au moins la part des chapitres en ligne ; sinon inchangé), à la sortie du tome, de chaque
  chapitre annoncé (`yume_chapitre_publie`) et après une sortie sans annonce
  (`Planning\avancer_selon_chapitres()`).
- **Sans annonce** (`sans_annonce`, ou `annoncer=0`) : comme l'ajout au catalogue (chapitres et
  tome marqués `catalogue`, aucun événement ni article) ; tome paru et complet, sortie immédiate :
  chapitres datés de la sortie du tome ; tome en cours : datés de leur sortie.

`yume_glossaire_importe( int $oeuvre_id, int $version_id )` (module glossaire) : un glossaire vient
d'être importé (nouvelle version en ligne) ; pas émis pour une simulation ni un envoi « inchangé ».

## 8 bis. MangaDex : synchronisation et proxy des pages (module mangadex, depuis 2.3.0)

`Yume\Core\MangaDex\Synchro` (`includes/mangadex/class-synchro.php`) ; planification et page
d'administration dans `includes/mangadex/synchro.php`.

- **Activation** : `Synchro::active()` = réglage `mangadex_synchro` ET `lecteur_pret()` (filtre
  `yume_mangadex_lecteur_pret`, vrai ; faux coupe lecteur, proxy et synchronisation), puis filtre
  `yume_mangadex_synchro_active`. Active : tâche WP-Cron `yume_mangadex_synchro` toutes les heures
  (planifiée sur `init`, retirée quand la synchronisation est coupée et à la désactivation de
  l'extension).
- **Flux** : `Client::chapitres_du_groupe( mangadex_groupe, 'fr' )` (toutes les pages). Chapitres
  externes (`externalUrl`) comptés et ignorés ; série sans œuvre liée (`yume_mdx_serie`) :
  `series_non_liees` ; œuvre liée non publiée : `oeuvres_non_publiees`, rien n'est créé.
- **Tomes** : un volume MangaDex = un tome de nature `tome` (`yume_numero` = volume, slug
  `tome-N`, titre « {Œuvre} — Tome N ») ; sans volume : un tome de nature `chapitres` (slug
  `chapitres`). Le tome existant de même nature et numéro est repris (par exemple créé au
  planning par « Nouveau tome ») ; sinon créé en brouillon (`yume_parution = en_cours`, méta
  `_yume_mdx_tome`). Un tome jamais publié (sans `_yume_publie_notifie`), ou mis en brouillon
  par la synchronisation parce qu'il était vide (`_yume_mdx_tome_range`, effacée à la
  republication), est publié après ses chapitres, daté de son premier chapitre,
  `yume_parution` passée à `en_cours` si besoin ; un tome déjà paru puis retiré par l'équipe
  reste hors ligne (alerte du rapport). Tome créé par la synchronisation et vidé :
  remis en brouillon (`tomes_vides`).
- **Chapitres** : retrouvés par `yume_mdx_chapitre`. Nouveau : publié, daté de `readableAt`, sans
  contenu, titre « Chapitre N » ou « Chapitre N — titre » (oneshot : nature `bonus`, « Oneshot »),
  slug `chapitre-N`, métas `yume_mdx_*`, `yume_sous_titre`, `yume_numero`,
  `yume_credits.traduction` (groupes). Connu : mis à jour en place (titre, métas, tome si le volume
  a changé ; date et adresse gardées) **seulement si `yume_mdx_version` a changé** (la version suit
  `updatedAt` ; le titre stocké n'est jamais comparé, WordPress pouvant le filtrer à l'écriture).
  Disparu du flux ou devenu externe : brouillon + méta `_yume_mdx_retire` (date GMT) ; remis en
  ligne s'il revient (`remis_en_ligne`).
- **Doublons** (`Synchro::doublons()`) : chapitres non externes d'une série de même numéro
  (`numero()`, « 3 » = « 3,0 ») et de volumes compatibles (égaux, ou l'un sans volume ; deux
  volumes différents ne sont pas des doublons). Gardés : tous ceux déjà sur le site (rien n'est
  supprimé), sinon le premier du flux (ordre de mise en lecture) ; les autres ne sont ni créés ni
  mis à jour, ni comptés dans `oeuvres[].chapitres`. Chapitres sans numéro (oneshots) jamais
  concernés. Chapitre gardé disparu de MangaDex : retiré, le doublon est créé à sa place dans la
  même synchronisation. Rapport `doublons[] {oeuvre, titre, numero, garde, ignores[]}`.
- **Annonces** : première synchronisation d'une œuvre (méta `_yume_mdx_synchronisee` absente) =
  ajout au catalogue : `_yume_publie_notifie = catalogue` sur tomes et chapitres et
  `yume_core_notifier` coupé pendant le traitement. Ensuite, circuit de core (§8) : nouveau tome
  annoncé par `yume_tome_publie`, nouveau chapitre d'un tome en ligne par `yume_chapitre_publie` ;
  une mise à jour n'est jamais annoncée.
- **Garde-fous** : erreur de lecture du flux → aucune écriture, rapport `ok = false` ; aucun
  retrait si le flux est vide ou si plus de 50 % (`PART_MAX_RETRAITS`) des chapitres MangaDex du
  site devaient partir (alerte) ; verrou option `yume_mdx_synchro_verrou` (repris après 15 min) ;
  simulation (`Synchro::executer( true )`) sans aucune écriture.
- **Rapport** : option `yume_mangadex_synchro` (non autochargée, `Synchro::dernier_rapport()`) :
  `date, simulation, ok, erreur, groupe, chapitres_mangadex, tomes_crees, crees, maj,
  maj_hors_ligne (chapitres hors ligne de l'équipe mis à jour, restés hors ligne),
  remis_en_ligne, retires, externes, tomes_vides, oeuvres[], series_non_liees[],
  oeuvres_non_publiees[], oeuvres_licenciees[], doublons[], alertes[]`.
- **Administration** : Yume → Mangas (MangaDex) (`yume-mangadex`, capacité `yume_reglages`) :
  dernier rapport, « Simuler (sans rien changer) » (résultat en transient
  `yume_mdx_simulation_{utilisateur}`) et « Synchroniser maintenant » (si active) ;
  `admin-post.php?action=yume_mangadex_synchro`, jeton requis.

### Lien d'une œuvre à MangaDex (formulaire équipe, `includes/planning/oeuvres-equipe.php`)

- Champ « Lien MangaDex de la série (manga) » (`name="mangadex"`) des formulaires « Nouvelle
  œuvre » et « Modifier l'œuvre », présent si le module mangadex est chargé
  (`mangadex_disponible()`). Facultatif. Affiché par le script de la vue pour le type « Manga »
  ou s'il est rempli ; toujours visible sans JavaScript.
- Saisie acceptée (`serie_du_lien_mangadex()`) : identifiant seul ou
  `https://mangadex.org/title/{id}/…` ; refus 400 `yume_oeuvre_mangadex` (adresse de chapitre,
  de groupe ou d'un autre site), 400 `yume_oeuvre_mangadex_type` (type autre que `manga`),
  409 `yume_oeuvre_mangadex_liee` (série liée à une autre œuvre). Saisie reprise après un refus.
- Enregistrement dans `yume_mdx_serie` ; champ vidé : méta supprimée (œuvre déliée, ses
  chapitres restent) ; champ absent de l'envoi (`saisie['mangadex']` null) : lien inchangé.
- Liste « Œuvres » : pastille « MangaDex » (œuvre liée) ou « Lien MangaDex manquant » (type
  `manga` sans lien).

### Règles (étape 6)

- **Œuvre « Licenciée »** (terme `licenciee` de `yume_statut`, `Synchro::ETAT_LICENCIEE`) :
  ignorée entièrement par la synchronisation (ni création, ni mise à jour, ni remise en ligne,
  ni retrait), rapport `oeuvres_licenciees[] {oeuvre, titre, chapitres}`. Le retrait de la
  lecture et sa restauration à la sortie sont faits par `changer_etat_oeuvre()` (planning) ; le
  proxy refuse les chapitres en brouillon (404).
- **Brouillon de l'équipe** : un chapitre mis en brouillon à la main n'est jamais remis en ligne
  par la synchronisation (mise à jour du titre et des métas seulement) ; seuls les chapitres
  retirés par elle (`_yume_mdx_retire`) reviennent en ligne.
- **Rappel** `rappel_publication()` (« un chapitre publié sur MangaDex y est public tout de
  suite… ») : page Yume → Mangas (MangaDex), boîte « MangaDex » de l'œuvre, aide du champ « Lien
  MangaDex » du formulaire équipe.
- Procédures de l'équipe (liaison, retrait, coupure d'urgence) : `docs/mangadex.md`.

### Proxy des pages (`includes/mangadex/class-proxy.php`, `Yume\Core\MangaDex\Proxy`)

- **Adresse** : `/?yume_mdx_image={chapitre}/{version}/{qualite}/{page}` (servie sur `init`
  priorité 20, avant le thème ; `Proxy::url()` / `yume_mangadex_url_page()`). `version` =
  `yume_mdx_version` (8 caractères) : un chapitre mis à jour change d'adresse. Qualité
  `data-saver` (économie de données, défaut) ou `data` (originale).
- **Refus sans appel à MangaDex** : adresse mal formée → 400 ; lecteur non livré
  (`lecteur_pret()` faux), chapitre absent de la base, chapitre / tome / œuvre non publiés (une œuvre
  « Licenciée » a ses chapitres en brouillon), mot de passe → 404 ; version périmée → 404
  « rechargez la page » ; page au-delà de `yume_mdx_pages` → 404 ; pause en cours → 503.
- **Téléchargement** : serveur MD@Home du chapitre (`Client::at_home()`, transient 10 min),
  `wp_remote_get` en mémoire (20 s, 15 Mo au plus, jamais `stream` ni fichier) ; échec du nœud →
  un seul autre nœud (`at_home( …, true )`) puis 502 ; type lu dans les premiers octets (JPEG,
  PNG, GIF, WebP ; tout le reste → 502). 429/403 de MangaDex → transient `yume_mdx_pause`
  (60 s) : plus aucun appel, 503 `Retry-After: 60`.
- **Réponse 200** : `Content-Type` détecté, `Cache-Control: public, max-age=31536000, immutable`,
  `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-site`,
  `Content-Security-Policy: default-src 'none'`, `X-Robots-Tag: noindex`, sans cookie. Erreurs :
  texte brut, `Cache-Control: no-store`.
- **Rien n'est gardé** : ni fichier, ni média, ni écriture en base par image (seuls les transients
  ci-dessus, par chapitre ou en cas d'incident).
- **Signalement** (`https://api.mangadex.network/report`, exigé pour les nœuds hors
  `mangadex.org`) : `{url, success, cached, bytes, duration}` en JSON, mis en file pendant la
  requête, envoyé **après** la réponse au lecteur (`fastcgi_finish_request()`), délai 3 s.
  Coupe-circuit : un échec (réseau, délai, 5xx) pose le transient `yume_mdx_signalement_coupe`
  (15 min), pendant lequel rien n'est envoyé ; un succès n'écrit rien. Le point de signalement
  ne répondait pas le 16/10/2026 (522, puis délai dépassé).
- **Cache WordPress.com (mesuré en production le 18/10/2026, 2.3.0)** : les réponses 200 sont
  gardées par le cache de bord d'Atomic (`x-ac: … MISS` à la 1re demande, `HIT` ensuite, environ
  0,2 à 0,5 s au lieu de 1,8 à 2,2 s), par centre de données. Une requête avec un cookie de
  connexion WordPress (`wordpress_logged_in_*`) contourne ce cache (`x-ac: … BYPASS`) : la page
  passe par PHP et MangaDex, puis reste dans le cache du navigateur (un an). Les erreurs
  (`no-store`) ne sont jamais gardées. Aucune image dans la médiathèque. Détail et commandes de
  contrôle : `docs/mangadex.md`, « Vérifications en production ».

### Lecteur (`includes/mangadex/lecteur.php`, `assets/manga.js`, `assets/manga.css`)

- **Rendu** : filtre `the_content` (priorité 20) sur la page d'un chapitre MangaDex
  (`chapitre_affiche()` : requête principale, `lecteur_pret()`) ; le lecteur `.yn-manga` est
  placé dans `.wp-block-post-content` du modèle « Chapitre » (inchangé). Feuille et script
  (poignée `yume-manga`) chargés seulement sur ces pages.
- **HTML identique pour tous les visiteurs** (Batcache) : `figure.yn-manga__page` par page,
  `img` avec `src` = qualité `data-saver`, `data-yn-originale` = qualité `data`,
  `alt` « Page N sur M », `loading="eager"` pour les 2 premières (`fetchpriority="high"` sur la
  1re), `lazy` ensuite. Outils (`.yn-manga__outils`) et navigation page par page
  (`.yn-manga__navigation`) en `hidden`, affichés par le script : sans JavaScript, défilement.
- **Outils** : « N pages », mode Défilement / Page par page, qualité « Économie de données » /
  « Originale (≈ X Mo) » (X = pages × 1,6 Mo, `MO_PAR_PAGE_ORIGINALE`) ; choix dans
  `localStorage yn.manga` (§14).
- **Page par page** : moitié droite de la page, → ou Page suivante = page suivante ; moitié
  gauche, ← ou Page précédente = page précédente, **quel que soit le sens de lecture** (décision
  de l'équipe, 16/10/2026). Touches captées avant la barre de lecture ; au bout du chapitre,
  ← / → reviennent à la barre (chapitre voisin) et le bouton devient « Chapitre suivant → ». Page
  alignée sous la barre, entière à l'écran (cadre d'une page pas encore chargée au format 7/10,
  largeur bornée par la hauteur disponible) ; deux pages préchargées. Ouverture d'un chapitre
  en mode page, en haut de la page sans ancre : la page est alignée sous la barre (en-tête et
  outils au-dessus) ; une ancre ou une position restaurée par le navigateur est respectée.
- **Suivi de lecture** (`yume/reader-tools`) : chaque `.yn-manga__page` compte comme un
  paragraphe (marque-page, `#yn-p-N`, « Reprendre à la page N ») ; en mode page, position et
  pourcentage suivent `data-yn-courante` (événement `yn:manga-page`), la reprise passe par
  `yn:manga-aller` (`detail.index`).
- **Crédits et repli** : crédits du chapitre dans l'en-tête (`yume_credits.traduction` = groupes),
  « Images hébergées par MangaDex · Lire ce chapitre sur MangaDex » sous les pages ; page en
  échec → « Page N indisponible. Lire sur MangaDex » ; `yume_mdx_pages` = 0 → message et lien.
  Lecteur coupé (`yume_mangadex_lecteur_pret` faux) : `.yn-manga--coupe`, « La lecture de ce
  chapitre sur le site est momentanément indisponible » et bouton « Lire ce chapitre sur
  MangaDex » (ni page, ni adresse du proxy, ni feuille ni script du lecteur).

## 8 ter. Statistiques d'audience (module statistiques, depuis 2.4.0)

Module `includes/statistiques/` (espace de noms `Yume\Core\Statistiques`), chargé après `library`
dans `YUME_CORE_MODULES` ; il lit les tables des modules social et reader et appelle planning (aides
de vue) dans des callbacks seulement, gardés par `function_exists`. Fichiers : `fonctions.php`
(constantes, périodes, cache), `install.php` (table, tâche), `rest.php`, `trafic.php` (adaptateur
Jetpack), `trafic-simule.php` (simulation hors production), `oeuvres.php` (compteurs, classements,
fiche, résumé des lecteurs), `historique.php` (calcul quotidien, séries), `lecteurs.php` (listes
nominatives, journal des exports), `export.php` (CSV), `equipe.php` (vue), `graphiques.php` (SVG).

**Capacités** (§5) : `yume_voir_statistiques` (`CAPACITE_STATS` : trafic, chiffres par œuvre,
classements, chiffres des lecteurs, export du classement — tous les rôles de l'équipe et
administrateur) ; `yume_voir_lecteurs` (`CAPACITE_LECTEURS` : liste nominative et son export —
Gérant et administrateur ; l'ouvrir à `yume_editeur` est une décision en attente, une ligne de
`definitions_roles()`). Définitions : **favori** = ligne de `favoris` (« suivre » une œuvre = la mettre
en favori) ; **suiveur alerté** = favori dont `frequence` ≠ `jamais` ; **lecteur** = compte avec une
ligne `progression` ; **lecteur actif** = `progression.updated_at` dans la fenêtre ; **chapitres lus**
(estimation) = chapitres publiés qui précèdent la position dans l'ordre de lecture
(`Reader\plan_de_lecture()`), + le chapitre courant à 90 % (`Reader\SEUIL_CHAPITRE_LU`).

**Vue** `?vue=statistiques` (filtre `yume_vues_equipe` priorité 5, groupe `site`, capacité
`yume_voir_statistiques`) : rendu serveur sans JavaScript (formulaires GET), paramètres `periode` (7,
30 défaut, 90, 365), `onglet` (`apercu` défaut, `trafic`, `oeuvres`, `lecteurs`), `tri`, `pg`,
`oeuvre` (fiche d'une œuvre) avec `liste` (`favoris` | `suiveurs`), `q`, `tri` (`date` | `pseudo`) pour
la liste nominative (affichée si `peut_voir_lecteurs()`, sinon encart avec le seul chiffre).
`url_statistiques( array $args ): string` (omet la période et l'onglet par défaut). Graphiques SVG
inline (`svg_courbe()`, `svg_barres_h()`, `svg_sparkline()`, `variation_html()`) : `role="img"` +
`aria-label`, tableau des valeurs dans un `<details>` (`tableau_kpi()`). Style `yume-statistiques`
(`assets/statistiques.css`, dépend de `yume-kpi`). Filtre `yume_statistiques_vue_donnees( $pre,
$fonction, $args )` (tests, démonstrations : `false` = fonction absente). Ligne « Source du trafic …
— dernière erreur … » de l'onglet Trafic réservée à `manage_options`.

**Trafic** (`trafic.php`) : `source_trafic()` = `jetpack` (classe
`Automattic\Jetpack\Stats\WPCOM_Stats`, identifiant WordPress.com du site et connexion active),
`simule` (Jetpack indisponible et `wp_get_environment_type()` ≠ `production` : données déterministes
de `trafic-simule.php`, bandeau « Démonstration ») ou `aucune` (production sans Jetpack : fonctions
`trafic_*()` → `null`, « Trafic indisponible ») ; filtre `yume_statistiques_source_trafic( $source,
$jetpack, $environnement )` (jamais `simule` en production, valeur inconnue → `aucune`). Toutes les
lectures passent par `requete_stats( $ressource, $args )` : cache transient
`yume_stats_trafic_<md5>` 15 min (erreur : 2 min), filtre `yume_statistiques_requete_stats( null,
$ressource, $args )` (charge utile brute ou `WP_Error` : court-circuite Jetpack), puis la méthode
`WPCOM_Stats` (`get_visits`, `get_top_posts`, `get_referrers`, `get_search_terms`,
`get_views_by_country`, `get_post_views`, `get_stats_summary`) enveloppée (`is_wp_error`, `try/catch
\Throwable`). Fonctions : `trafic_resume()`, `trafic_contenus()` (regroupement par œuvre via
`yume_get_oeuvre_id()`), `trafic_referents()`, `trafic_recherches()`, `trafic_pays()` (code pays,
jamais `location-views` ni ville), `trafic_post()`, `trafic_vues_par_oeuvre_jour()`,
`trafic_visites_jour()`, `derniere_erreur_trafic()` (message technique, administrateurs seulement).
Paramètres de l'API encore **à vérifier en production** : en-tête de `trafic.php` et
`docs/mise-en-production.md` §11.

**Compteurs et historique** : `compteurs_oeuvres()`, `classement()`, `fiche_oeuvre()`,
`resume_lecteurs()` (requêtes agrégées, cache 5 min `yume_stats_*`, vidé par `cache_vider()` sur
`yume_favori_ajoute`, `yume_favori_retire`, `yume_tome_publie`, `yume_chapitre_publie`). Tâche
quotidienne `yume_statistiques_jour` (`HOOK_JOUR`, 03:10 heure du site, recalée sur `init` 99,
libellée « Statistiques quotidiennes (historique) » dans Santé du site via `yume_taches_cron`, retirée
sur `yume_core_deactivate`) : seul callback `lancer_tache_jour()` → `tache_quotidienne()` : calcule la
veille et rattrape jusqu'à 7 jours depuis `yume_statistiques_dernier_jour`, `calculer_jour( 'Y-m-d' )`
(REPLACE de la ligne site et d'une ligne par œuvre publiée ; vues du jour par `top-posts`), puis purge
au-delà de 400 jours (filtre `yume_statistiques_retention`). L'historique commence à l'installation ;
`reconstituer_favoris( $jours )` (estimation de la colonne `favoris` d'après `created_at`, retraits
non datés, table vide seulement) existe mais n'est branchée à aucun écran. Œuvre supprimée
définitivement (`deleted_post`) : ses lignes aussi.

**Listes nominatives et exports** : `lecteurs_oeuvre( $oeuvre_id, $args )` (INNER JOIN `users` :
compte supprimé absent ; champs exactement `pseudo` = `display_name`, `depuis`, `frequence`,
`equipe`). Export CSV `admin-post.php?action=yume_statistiques_export` + nonce
`yume_statistiques_export` (`url_export( $type, $args )`) : `type=oeuvres` (`periode`, `tri` ;
`yume_voir_statistiques`) ou `type=lecteurs` (`oeuvre`, `liste`, `q` ; `yume_voir_lecteurs`) ; UTF-8
avec BOM, `;`, cellules neutralisées (`Planning\cellule_csv()`) ; 401 anonyme, 403 sans la capacité
ou nonce expiré. Chaque export nominatif est journalisé (`journaliser_export()`) dans l'option
`yume_statistiques_exports` (100 dernières entrées `{quand, user_id, oeuvre_id, type, nb}`, non
chargée automatiquement ; aucun écran ne l'affiche, `exports_recents()`).

**Options** : `yume_statistiques_schema` (version de la table), `yume_statistiques_dernier_jour`,
`yume_statistiques_exports`, `yume_statistiques_cache_cles` (clés de cache quand un cache d'objets
persistant est actif) ; transients `yume_stats_*`. Filtres : `yume_statistiques_maintenant` (tests),
`yume_statistiques_retention`, `yume_statistiques_source_trafic`, `yume_statistiques_requete_stats`,
`yume_statistiques_vue_donnees`. Désinstallation : table `stats_jour` dans `uninstall.php`, options
et transients `yume_*` déjà couverts.

## 8 quater. Corrections : coquilles signalées par les lecteurs (module corrections, lot L1, 2.5.0)

Contrat figé par le lot 0 ; implémentation : lot L1 (`includes/corrections/{module,fonctions,install,
rest,lecteur,equipe,notifications,rgpd}.php`, `assets/{coquilles.js,coquilles.css,corrections.css}`,
`tests/test-corrections.php` ; `uninstall.php` reçoit la table `coquilles`). Espace de noms
`Yume\Core\Corrections`.

**Lecteur** : chapitres texte publiés d'un tome et d'une œuvre publiés (jamais MangaDex :
`yume_mangadex_chapitre()` non nul ; jamais la page Illustrations ; pas de mot de passe). Sélection de
3 à 300 caractères (espaces normalisées) dans `.yn-reader__texte` → bouton flottant « Signaler une
coquille » ; lien clavier « Signaler une coquille dans ce chapitre » après `yume/chapter-nav` (filtre
`render_block_yume/chapter-nav`, aucune modification du gabarit). `<dialog>` modal : passage (lecture
seule), « Paragraphe N », commentaire facultatif (500 caractères), « Envoyer » / « Annuler » ; envoi
`POST /yume/v1/chapitres/{id}/coquilles` (`X-WP-Nonce`) ; « Merci ! L'équipe a reçu votre
signalement. », doublon : « Vous avez déjà signalé ce passage. ». Visiteur : le bouton mène à la
connexion (`url_connexion( permalien#yn-p-N )`). Sans JavaScript : rien. Script `yume-coquilles`
(`wp_enqueue_scripts` si `Reader\page_de_lecture()` et chapitre signalable, `in_footer`, sans
dépendance) ; numérotation identique au lecteur et à la recherche (§9 : enfant direct de
`.wp-block-post-content` hors script/style, `closest('.wp-block-post-content > *')`, ou
`data-yn-paragraphe` s'il est présent ; ancre `#yn-p-N`, N = index + 1) ; dialogue imprimé sur
`wp_footer` avec `data-yn-coquilles='{"rest":…,"nonce":…,"chapitre":…,"connexion":…}'`.

**Données** (table `coquilles`, §13) : chapitre, tome, œuvre (dénormalisés), `paragraphe` (index 0),
`extrait` (≤ 300), `contexte` (texte du paragraphe, ≤ 600), `empreinte` (md5 du texte normalisé du
paragraphe : badge « Paragraphe modifié depuis » quand l'empreinte actuelle diffère ; aucune clôture
automatique), `commentaire` (≤ 500, `sanitize_textarea_field`, URL retirées sauf filtre
`yume_coquilles_liens`), `user_id`, `statut` `nouveau | corrige | rejete`, `ip_hash` (HMAC, mode anonyme
seulement, 30 jours), `cree_le`, `traite_le`, `traite_par`.

**Anti-abus** : connecté par défaut ; 20 signalements par heure et par compte
(`Social\limite_atteinte( 'coquille', 20, HOUR_IN_SECONDS, 'u'.$id )`, filtre `yume_coquilles_debit`) ;
un signalement « nouveau » par (compte, chapitre, paragraphe) → 409 ; 200 « nouveau » au plus par
chapitre → 429 `yume_coquilles_chapitre_sature` ; mode anonyme (`coquilles_anonymes`) : jeton horodaté et
pot de miel (`Social\jeton_formulaire()` / `verifier_jeton()` via `function_exists`), 5 par heure et par IP.

**Équipe** : vue `?vue=corrections` (`yume_vues_equipe` : clé `corrections`, groupe `catalogue`, capacité
`yume_voir_equipe`, libellé « Corrections (N) », N = nouveaux) ; actions réservées à `edit_post` sur le
chapitre (traducteurs et relecteurs lisent seulement : « Seul un éditeur peut traiter ce
signalement »). Filtres GET `oeuvre`, `tome`, `statut` (`nouveau` défaut | `corrige` | `rejete` |
`tous`), `pg` (50 par page) ; nouveaux groupés par chapitre ; par signalement : « Œuvre › Tome ›
Chapitre », « ¶ N », extrait en `<mark>` dans son contexte, commentaire, pseudo et date, pastille
« Paragraphe modifié depuis », « Voir le paragraphe » (`permalien#yn-p-N`, nouvel onglet), « Corriger dans
l'éditeur » (`get_edit_post_link`, si `edit_post`), boutons Corrigé / Rejeté / Rouvrir. Admin-post
`yume_correction` (champs `coquille`, `op` = `corrige|rejete|rouvrir`, nonce `yume_correction_{id}`) et
`yume_correction_chapitre` (`chapitre`, `op`, nonce `yume_correction_chapitre_{chapitre}`), retour par
`Planning\retour_formulaire()` / `rediriger_retour()` (cible `yn-corrections-retour`).

**Notification** : Discord canal `equipe` immédiat (« Coquille signalée — **{Œuvre}** · {Tome} ·
{Chapitre}, ¶ {N} : « {extrait} » — par {pseudo} → {lien vue} »), un message par chapitre et par
10 minutes au plus (transient ; le suivant dit « +N autres signalements ») ; réglage
`coquilles_discord`, filtre `yume_coquilles_discord`. Pas d'e-mail. À « Corrigé », notification
lecteur (cloche, type `coquille`) « Votre signalement sur {Chapitre} a été corrigé, merci ! » via
`Social\creer_notifications()` si elle existe.

**RGPD** : export `/moi/export` clé `signalements_coquilles` (filtre `yume_donnees_personnelles`),
exportateur WordPress `yume-core-coquilles` ; suppression du compte (`yume_effacer_donnees`,
`deleted_user`, effaceur WordPress) → `user_id` mis à 0 ; purge quotidienne des signalements traités
depuis plus de 180 jours (cron `yume_corrections_purge`, libellé « Purge des signalements de coquilles
traités (180 jours) » par `yume_taches_cron`, retiré sur `yume_core_deactivate` ; filtre
`yume_coquilles_retention`) ; chapitre supprimé définitivement → ses signalements aussi. Option de
schéma `yume_corrections_schema` (`'1'`), `verifier_schema()` sur `init` 98.

```php
const STATUTS = array( 'nouveau', 'corrige', 'rejete' );
function table_coquilles(): string;
function coquilles_actives(): bool;                 // réglage coquilles_actives && filtre yume_coquilles_actives
function chapitre_signalable( int $chapitre_id ): bool;   // publié, œuvre et tome publiés, pas MangaDex, pas de mot de passe
function signaler_coquille( int $chapitre_id, int $user_id, array $donnees ): array|\WP_Error;  // {id, message} ; donnees : paragraphe, extrait, contexte, commentaire, ip_hash
function empreinte_paragraphe( string $texte ): string;    // md5 du texte normalisé (balises retirées, espaces réduites, apostrophes unifiées)
function texte_paragraphe( int $chapitre_id, int $index ): string;  // texte du i-ème bloc de premier niveau (numérotation du lecteur et de la recherche), '' sinon
function lister_coquilles( array $args ): array;    // {lignes: array, total: int} ; args : statut ('nouveau'|'corrige'|'rejete'|'tous'), oeuvre, tome, chapitre, user_id, page, limite (50)
function compter_nouvelles( int $oeuvre_id = 0 ): int;
function changer_statut( int $id, string $statut, int $user_id ): true|\WP_Error;   // exige edit_post du chapitre ; action yume_coquille_traitee
function traiter_chapitre( int $chapitre_id, string $statut, int $user_id ): int;
function anonymiser_coquilles( int $user_id ): int;        // user_id → 0 (RGPD)
function purger_coquilles( int $jours = 180 ): int;
```

Actions `yume_coquille_signalee( int $id, int $chapitre_id, int $user_id )`, `yume_coquille_traitee(
int $id, string $statut, int $user_id )` ; filtres `yume_coquilles_actives`, `yume_coquilles_debit` (20),
`yume_coquilles_retention` (180), `yume_coquilles_discord` (bool), `yume_coquilles_liens` (faux).

## 8 quinquies. Connexion avec Discord (module discord, lot L2, 2.5.0)

Contrat figé par le lot 0 ; implémentation : lot L2 (`includes/discord/{module,fonctions,client,
connexion,compte,reglages,rgpd}.php`, `assets/discord.css`, `tests/test-discord.php`). Espace de noms
`Yume\Core\Discord`. Aucune dépendance : `wp_safe_remote_*` seulement (User-Agent `YumeNovel/{version}
(+home_url)`, comme `includes/mangadex/class-client.php`). Tant que `discord_configure()` est faux
(connexion activée + client_id + secret), le site est strictement inchangé : aucun bouton, la route de
retour répond 404 `yume_discord_inactif`, la rubrique du compte dit « non configuré » (équipe) ou
n'apparaît pas (lecteur).

**Flux** (OAuth2 *Authorization Code* + PKCE S256, scopes `identify email`) :

1. Bouton « Se connecter avec Discord » / « Créer un compte avec Discord » (filtres
   `yume_compte_html_connexion_extra` et `yume_compte_html_inscription_extra`, §7) : formulaire POST
   admin-post `yume_discord_depart` (nopriv et priv), champs `redirect_to`, `yn_origine`, `mode` =
   `connexion` | `liaison`, nonce `yume_discord_depart` pour un membre connecté seulement → `state`
   (32 octets), `code_verifier` ; transient `yume_discord_etat_{hash(state)}` (10 min : `{verifier,
   mode, user_id, redirect_to, origine, cree}`) **et** cookie `yume_discord_etat` (valeur = state,
   `HttpOnly`, `Secure` si https, `SameSite=Lax`, 10 min) → 302 vers
   `https://discord.com/oauth2/authorize?client_id=…&response_type=code&redirect_uri=…&scope=identify%20email&state=…&code_challenge=…&code_challenge_method=S256&prompt=consent`.
2. Retour `GET /wp-json/yume/v1/discord/retour?code=…&state=…` (route publique servie par
   `rest_pre_serve_request` comme `/planning.ics`, redirection puis sortie, `nocache_headers()`) : state
   du cookie = state reçu = transient existant, sinon `expire` ; échange du code
   (`https://discord.com/api/v10/oauth2/token`, `client_id`, `client_secret`,
   `grant_type=authorization_code`, `code`, `redirect_uri`, `code_verifier`, 10 s) ; `GET
   https://discord.com/api/v10/users/@me` (`Authorization: Bearer`) → `id`, `username`, `global_name`,
   `email`, `verified` ; jeton **jamais stocké** (révocation tentée, échec ignoré). 10 retours par 15 min
   et par IP (filtre `yume_discord_limites`).
3. Résolution : compte lié (méta `yume_discord_id`) → session ouverte comme `Social\traiter_connexion()`
   (`wp_set_auth_cookie`, `wp_set_current_user`, `do_action( 'wp_login' )`, `login_redirect`), sauf si
   `connexion_autorisee()` est faux (défaut : `! Social\connexion_reservee_wordpress()`, donc
   administrateurs exclus ; filtre `yume_discord_connexion_autorisee( bool, WP_User )`) → `reserve` ;
   mode `liaison` : `id` déjà lié à un autre compte → `deja-lie`, sinon métas posées → `lie` ; aucun
   compte lié : `verified !== true` → `non-verifie` ; `email_exists()` → `email-existant` (**pas de
   liaison automatique** ; filtre `yume_discord_lier_par_email_verifie`, faux) ; sinon, si
   `discord_inscription` et `inscriptions_ouvertes()` → `Social\creer_compte_lecteur( [ 'pseudo' =>
   global_name ou username (suffixe « -2 », « -3 »… si pris), 'email', 'source' => 'discord', 'notifier'
   => false, 'meta' => [ métas Discord, '_yume_compte_sans_mdp' => 1 ] ] )` puis session ; sinon
   `inscriptions-fermees`.
4. Mon compte, rubrique « Connexion Discord » (filtres `yume_compte_rubriques` /
   `yume_compte_sections_html`) : état (« Lié à @pseudo depuis le … » / « Non lié »), « Lier mon compte
   Discord » (départ, mode `liaison`), « Délier » (admin-post `yume_discord_delier`, nonce
   `yume_discord_delier`, mot de passe actuel exigé ; refusé si `Social\compte_sans_mot_de_passe()` :
   « Définissez d'abord un mot de passe (Mot de passe oublié ?) pour ne pas perdre l'accès »).

Messages : `Social\messages()` n'a pas de filtre ; le module affiche les siens dans
`yume_compte_html_connexion_extra` en lisant `?yn-discord=<code>` (`expire`, `refuse`, `non-verifie`,
`email-existant`, `inscriptions-fermees`, `lie`, `delie`, `deja-lie`, `mdp`, `reserve`, `erreur`).

**Réglages** (section `discord_connexion` « Connexion Discord », §6) : `discord_connexion`,
`discord_inscription`, `discord_client_id` (constante `YUME_DISCORD_CLIENT_ID`), `discord_client_secret`
(type `secret`, constante `YUME_DISCORD_CLIENT_SECRET` prioritaire). Test *Santé du site* `yume_discord`
(activé sans secret ; secret en base plutôt qu'en constante). Redirect URI à déclarer **exactement**
sur le portail Discord : `https://yumenovel.fr/wp-json/yume/v1/discord/retour` (`url_retour()`).

**RGPD** : métas `yume_discord_id`, `yume_discord_pseudo` (username à la liaison), `yume_discord_lie_le`
; export clé `discord`, effacées avec le compte (`yume_effacer_donnees`, `yume_compte_suppression`,
`deleted_user`, effaceur WordPress `yume-core-discord`) ; avatar jamais téléchargé.

```php
const META_ID = 'yume_discord_id'; const META_PSEUDO = 'yume_discord_pseudo'; const META_LIE_LE = 'yume_discord_lie_le';
function discord_configure(): bool;
function client_id(): string;  function client_secret(): string;   // constante > réglage, jamais affichés
function url_retour(): string;                                     // rest_url( 'yume/v1/discord/retour' )
function url_autorisation( string $state, string $challenge ): string;
function demarrer( string $mode, int $user_id, string $redirect_to, string $origine ): string;   // state/verifier (transient + cookie), URL Discord
function echanger_code( string $code, string $verifier ): array|\WP_Error;    // {access_token, …} ; pre_http_request dans les tests
function profil_discord( string $jeton ): array|\WP_Error;                    // {id, username, global_name, email, verified}
function utilisateur_par_discord( string $discord_id ): int;                  // 0 sinon
function lier( int $user_id, array $profil ): true|\WP_Error;                 // yume_discord_deja_lie
function delier( int $user_id ): bool;
function connexion_autorisee( \WP_User $user ): bool;                         // filtre yume_discord_connexion_autorisee
function ouvrir_session( \WP_User $user, string $redirect_to ): string;       // même séquence que Social\traiter_connexion()
function traiter_retour( array $get, array $cookie ): array;                  // {url: string} : destination avec yn-discord ; pure (testable)
function bouton_html( string $mode, string $redirect_to, string $origine ): string;
```

Actions `yume_discord_lie( int $user_id, string $discord_id )`, `yume_discord_delie( int $user_id )`,
`yume_discord_compte_cree( int $user_id )` ; filtres `yume_discord_pkce` (vrai),
`yume_discord_lier_par_email_verifie` (faux), `yume_discord_limites` (10 / 900 s),
`yume_discord_connexion_autorisee`.

## 8 sexies. Recommandations et rattrapage (modules recommandations et rattrapage, lots L3 et L5, 2.5.0)

Contrats figés par le lot 0. **Cache de page** (Batcache) : une requête porteuse d'un cookie
`wordpress_logged_in_*` n'est jamais servie depuis le cache ; tout contenu personnel est rendu **côté
serveur pour les membres**, et le HTML d'une page anonyme est **identique pour tous les visiteurs**
(jamais de donnée personnelle).

**Recommandations « Si vous avez aimé… »** (lot L3, `includes/recommandations/{module,fonctions,rest,
compte}.php`, `blocks/recommandations/`, `tests/test-recommandations.php` ; espace de noms
`Yume\Core\Recommandations`) :

- Candidates : œuvres publiées, hors l'œuvre de départ, hors statuts exclus (filtre
  `yume_recommandations_statuts_exclus`, défaut `['licenciee']`), hors `yume_oeuvre_a_venir()` ; filtre
  `yume_recommandations_candidates( int[] $ids, int $oeuvre_id )`.
- Score (filtre `yume_recommandations_poids`, défaut `{genres: 3, type: 1, cofavoris: 4}`) : `3 ×
  Jaccard(genres)` + `1 × [même yume_type]` + `4 × cofav`, `cofav = n(A∩B) / sqrt(n(A) × n(B))` sur
  `favoris`, compté seulement si `n(A∩B) ≥ SEUIL_COFAVORIS` (3, k-anonymat). Égalités : `yume_nb_favoris`
  desc puis `yume_derniere_sortie` desc. Résultat vide si moins de 2 œuvres ont un score > 0 (filtre
  `yume_recommandations_minimum`).
- Bloc `yume/recommandations` (§10) dans `templates/single-yume_oeuvre.html` après `yume/oeuvre-news` ;
  visiteur : HTML identique pour tous ; membre : œuvres en favori ou commencées retirées. Compte :
  rubrique « Pour vous » (`#yn-pour-vous`, filtres du §7) : agrégat des recommandations de ses favoris
  et lectures en cours, 6 au plus, « parce que vous suivez {X} » ; sans favori ni lecture : « Ajoutez
  des favoris pour recevoir des suggestions ».
- Cache : transient `yume_reco_{oeuvre}_{version}` (6 h, filtre `yume_recommandations_cache`) ; option
  `yume_reco_version` incrémentée sur `yume_favori_ajoute`, `yume_favori_retire`, `save_post` /
  `deleted_post` d'une œuvre, `set_object_terms` d'une œuvre, `yume_oeuvre_etat_change`,
  `yume_tome_publie` (une fois par minute au plus : transient `yume_reco_tampon`). SQL des co-favoris :
  `SELECT f2.oeuvre_id, COUNT(*) n FROM favoris f1 INNER JOIN favoris f2 ON f2.user_id = f1.user_id AND
  f2.oeuvre_id <> f1.oeuvre_id WHERE f1.oeuvre_id = %d GROUP BY f2.oeuvre_id HAVING COUNT(*) >= %d`.
- Filtre `yume_recommandations_actives` (vrai). Aucune requête externe.

```php
const SEUIL_COFAVORIS = 3;
function recommandations_oeuvre( int $oeuvre_id, int $limite = 4, int $user_id = 0 ): array;   // [{id, titre, url, image, type, genres_communs, cofavoris, score}] ; user_id > 0 : exclut favoris et lectures
function recommandations_membre( int $user_id, int $limite = 6 ): array;                       // [{…, parce_que: [oeuvre_id…]}]
function cofavoris( int $oeuvre_id ): array;        // oeuvre_id => n (n ≥ SEUIL_COFAVORIS)
function similarite_genres( int $a, int $b ): float;
function version(): string;  function invalider(): void;
function rendu_cartes( array $recommandations, array $options ): string;   // partagé bloc / compte
```

**Rattrapage « Nouveaux chapitres depuis votre dernière lecture »** (lot L5,
`includes/rattrapage/{module,fonctions,rest,compte}.php`, `blocks/rattrapage/`,
`tests/test-rattrapage.php` ; espace de noms `Yume\Core\Rattrapage`) :

- `rattrapage( int $user_id, int $limite = 6 ): array` : pour chaque ligne de `progression` (œuvre
  publiée, chapitre encore lisible), ordre de lecture par `Reader\plan_de_lecture()` ; **restants** =
  chapitres publiés après la position (le chapitre courant compte s'il est lu à moins de
  `Reader\SEUIL_CHAPITRE_LU` = 90 %) ; œuvre à jour omise ; tri par date du plus récent chapitre publié
  parmi les restants ; `prochain` = premier restant, `reprise` = `url_reprise` de la position,
  `nouveaux_depuis` = restants publiés après `updated_at` (« 3 nouveaux depuis votre dernière lecture »,
  sinon « 12 chapitres à lire »), `tome` du prochain (« Tome 3 commence »). Seulement les lectures
  commencées (pas les favoris jamais ouverts).
- Bloc `yume/rattrapage` (§10) : membre → rendu serveur ; visiteur → chaîne vide (jamais de gabarit
  caché). Accueil (`front-page.html`) : section pleine largeur entre « Dernières sorties » et le
  Planning, lien « Toutes mes lectures » (`compte#yn-lecture`) ; rien à rattraper : la section
  disparaît. Compte : rubrique « À rattraper » (`#yn-rattrapage`, après `yn-lecture`), 12 au plus,
  « Vous êtes à jour sur toutes vos séries. ».
- Cache : transient `yume_rattrapage_{user}` 15 min (filtre `yume_rattrapage_cache`, 900 s), effacé
  sur `yume_progression_enregistree` (ce membre) ; version `yume_rattrapage_version` incrémentée sur
  `yume_chapitre_publie`, `yume_tome_publie` et la dépublication d'un chapitre (`transition_post_status`
  quittant `publish`). Filtre `yume_rattrapage_actif` (vrai).

```php
function rattrapage( int $user_id, int $limite = 6 ): array;   // même tableau que GET /moi/rattrapage (§12)
function invalider_membre( int $user_id ): void;  function invalider_tous(): void;
function rendu_cartes( array $lignes, string $layout ): string;
```

## 9. Contenu d'un chapitre (import → stockage → rendu)

Le contenu est stocké en **blocs Gutenberg** (modifiable dans l'éditeur) avec ces classes :

| Élément source | Bloc | Classe |
| --- | --- | --- |
| Paragraphe narratif | `core/paragraph` | (aucune) |
| Dialogue (puce « — » Word, ou paragraphe commençant par « — ») | `core/paragraph` | `yn-dialogue` (texte commençant par « — » + espace insécable) |
| Pensée (style « Pensée ») | `core/paragraph` | `yn-thought` |
| Paragraphe centré | `core/paragraph` avec `"align":"center"` | `yn-center` |
| Séparateur de scène (`***`, `* * *`, `◇`, ligne vide multiple) | `core/separator` | `yn-scene-break` |
| Illustration | `core/image` (`sizeSlug` large, id de pièce jointe) | `yn-illustration` |
| Sous-titre du chapitre | **métadonnée** `yume_sous_titre`, pas dans le contenu | — |
| Note de bas de page | appel `<sup class="yn-note"><a href="#yn-note-n" id="yn-ref-n">n</a></sup>` + `core/list` final classe `yn-notes` | |

Le titre (« Chapitre 1 ») et le sous-titre sont rendus par le bloc `yume/chapter-header`, pas par
le contenu. Résultat d'import (`Result`) : `chapters` (liste de
`['numero'=>?float,'nature'=>string,'titre'=>string,'sous_titre'=>string,'blocks'=>string (markup
de blocs, images référencées par un jeton {{yume-image:<cle>}}),'nb_mots'=>int]`), `front_images`
(images avant le premier chapitre), `images` (`cle => ['nom'=>…,'mime'=>…,'chemin_zip'=>…]`),
`warnings` (string[]), `stats` (array), `candidats` et `decoupages` (découpage manuel, ci-dessous ;
dans `rapport()`, pas dans `to_array()`).

**Découpage manuel** (`Chapter_Builder`, commun au DOCX et à l'EPUB) :

- chaque élément de contenu reçoit une **ancre** stable `e{rang}-{6 hex}` (rang dans le document,
  CRC32 du texte normalisé sans les appels de note ; image : `image:<clé>`), identique d'une
  conversion à l'autre du même fichier, avec ou sans découpage (les requêtes du convertisseur au
  constructeur répondent toujours comme la détection automatique) ;
- `Result::$candidats` (3 000 au plus, `stats['candidats_tronques']`) : `['ancre', 'rang', 'type',
  'raisons' => marqueur|titre|image|saut_page|separateur|gras|centre|ligne_courte (≤ 12 mots, hors
  dialogue)|debut, 'extrait' (80 car.), 'nature', 'titre' (proposés), 'auto' (un chapitre commence
  ici), 'avant' (avant le premier chapitre)]` ; `Result::$decoupages` : `['images' => ancres (première
  d'une suite d'illustrations), 'sauts' => ancres (saut de page DOCX `w:br type=page`,
  `pageBreakBefore`, fin de section non continue ; début de chaque document de la spine EPUB)]` ;
- option `plan` des convertisseurs : `['debuts' => [['ancre', 'nature' (Texte::LIBELLES), 'titre',
  'numero'?], …], 'garder_avant' => bool]`. Le plan est la **seule** source des débuts : un titre
  détecté hors plan devient un intertitre `core/heading` ; sur un début, il donne le titre par
  défaut et n'est pas répété (de même qu'une ligne courte reconnue comme titre ou identique au titre
  choisi) ; aucune page liminaire écartée ; `garder_avant` : le texte d'ouverture rejoint le premier
  chapitre ; numérotation `Chapter_Builder::numeroter()` (chapitres à la suite, un numéro saisi fixe
  la suite ; prologue 0 ; spéciaux de même nature numérotés s'ils sont plusieurs) ; notes numérotées
  dans le chapitre qui reçoit leur appel ; ancre absente : avertissement « Début de chapitre manuel
  introuvable : … » ;
- sans plan, un paragraphe **marqueur** `[chapitre]`, `[chapitre] Titre`, `[prologue]`,
  `[interlude] …`, `[bonus] …`, `[épilogue] …`, `[postface] …` (`Texte::marqueur()`, casse et accents
  indifférents) force un début de chapitre de cette nature (jamais écarté comme page liminaire) et
  n'est pas publié ; un titre qui le suit aussitôt le complète.

Côté publication, `Service::plan()` contrôle strictement le découpage reçu (JSON ≤ 1 Mo ou tableau ;
liste de 1 à 3 000 débuts ; ancres au motif `Chapter_Builder::MOTIF_ANCRE` ; nature connue ; titre
`sanitize_text_field` ≤ 200 caractères ; numéro facultatif ≥ 0 ; ancre en double ou clé nature +
numéro en double refusées) → 400 `yume_plan_invalide`. Le fichier source n'étant jamais conservé,
le découpage est envoyé **avec** le fichier (`analyser()`, `preparer()`, donc aussi la version en
attente d'un remplacement) et n'est pas enregistré sur le tome.

## 10. Blocs dynamiques (nom, propriétaire, attributs, classe racine)

Enregistrés avec `yume_register_dynamic_block()` ; catégorie `yume` ; `supports.html=false`,
`supports.align` si pertinent. Le rendu renvoie une chaîne vide (ou un message discret en éditeur)
quand le contexte manque. La classe racine est toujours présente pour que le thème et les tests
s'y accrochent. Contexte courant : `get_queried_object_id()` ou `$block->context['postId']`.

| Bloc | Module | Attributs | Racine | Contenu |
| --- | --- | --- | --- | --- |
| `yume/library-menu` | bibliothèque | — | `.yn-library-menu` | `<details class="yn-library-menu">` dont le `<summary>` porte aussi `wp-block-navigation-item__content` (placé dans `core/navigation`, enveloppé d'un `<li>`) ; panneau en absolu sous le summary au-dessus de 1360 px, en ligne dans le menu mobile (`.is-menu-open`) : types et statuts avec compteurs, « Toutes les œuvres A → Z », « Reprendre ma lecture » |
| `yume/banner` | bibliothèque | `height` (number, 240) | `.yn-banner` | Image `banniere_id` pleine largeur (bannière actuelle du site) |
| `yume/latest-releases` | bibliothèque | `count` (number, 6) | `.yn-releases` | Sans carte propre ; `.yn-grid-covers` + `.yn-cover`. Grille de couvertures des derniers tomes/chapitres publiés : badge « Nouveau » (< 7 j), titre, libellé, date, boutons Lire / PDF / EPUB. Tome en cours (arc, web novel, ou light novel publié chapitre par chapitre) : « Tome 2 · ch. 3 », daté de son dernier chapitre, Lire vers ce chapitre, pastille « Tome en cours » |
| `yume/library-grid` | bibliothèque | `perPage` (24), `showFilters` (true) | `.yn-library` | Filtres (GET `type`, `statut`, `tri` = recent/az) + grille de couvertures avec badge de statut |
| `yume/oeuvre-header` | bibliothèque | — | `.yn-oeuvre-header` | Couverture, badges, titres alternatifs, fiche (auteur, illustrateur, éditeur VO, traduit), synopsis ; fond d'en-tête `.yn-fiche-banniere--ambiance` : la couverture de l'œuvre (celle du tome pour `yume/tome-header`, taille `medium`) très floutée (`blur(48px)`), plus `yume_banniere_id` ; même ambiance derrière la liseuse (`.yn-reader-tools__fond`, couverture du tome, `blur(60px)`, réglage « Opacité du fond ») |
| `yume/oeuvre-infos` | bibliothèque | — | `.yn-oeuvre-infos` | Cartes « Équipe de traduction » (`yume_equipe`, `yume_source_traduction`) et « Liens » (`yume_liens`) de la maquette Oeuvre |
| `yume/tome-list` | bibliothèque | — | `.yn-tome-list` | Tomes publiés de l'œuvre (couverture, libellé, nb chapitres, date, Lire / PDF / EPUB). Tome en cours (`yume_parution_tome()` = `en_cours`) : pastille « ● En cours », « 3 chapitres sur 12 » si `yume_chapitres_prevus` est connu, « prochain chapitre `<time>`samedi 11 oct.`</time>` » (prochain chapitre programmé), « en ligne depuis le », et sans lien PDF ni EPUB montré (aucun, ou liens masqués d'un tome `en_cours` rouvert : `yume_liens_telechargement()`) la mention `.yn-tome-list__telechargement` « PDF et EPUB quand le tome sera complet » ; les tomes à paraître (brouillons, programmés) n'y figurent pas (pas de contenu non publié) : `yume/oeuvre-planning` annonce déjà publiquement le prochain tome |
| `yume/tome-header` | bibliothèque | — | `.yn-tome-header` | Couverture, libellé, crédits, équivalence, « Commencer la lecture », boutons PDF / EPUB, galerie d'illustrations (inchangée). « Commencer la lecture » (et « Lire en ligne » des lignes de `yume/tome-list`) ouvre la page Illustrations quand le tome en a une et que le lecteur n'a pas de position dans ce tome (membre : table `progression` ; visiteur : attributs `data-yn-debut-chapitre|tome|oeuvre` et script `yume-debut-lecture` qui remet le premier chapitre si `yn.progression[oeuvre].tome_id` = ce tome) ; les « Reprendre » gardent chapitre et ancre. Tome en cours : pastille `.yn-tome-header__parution` « ● Tome en cours · 3 sur 12 » (« ▲ À paraître » pour l'aperçu d'un tome non publié), ligne `.yn-tome-header__rythme` « Un nouveau chapitre chaque samedi à 18 h » (`yume_rythme`), « en ligne depuis le », et sans lien PDF ni EPUB `.yn-tome-header__telechargement` « PDF et EPUB seront proposés quand les 12 chapitres seront en ligne. » (« quand le tome sera complet » sans nombre prévu) |
| `yume/tome-toc` | bibliothèque | — | `.yn-toc` | Sommaire du tome (chapitres + temps de lecture) ; entrée « Illustrations · N planches » en tête (`.yn-toc__item--illustrations`) quand le tome a une page Illustrations. Tome en cours : chapitres programmés, en brouillon ou en attente listés sans lien (titre inchangé), programmés datés dans une pastille `.yn-toc__programme` (`<time>` « samedi 11 oct., 18 h »), ligne `.yn-toc__item--prevus` « Chapitres 5 à 10 · à venir » pour les chapitres prévus pas encore créés (après le dernier chapitre ordinaire), résumé « … · 9 à venir » |
| `yume/chapter-header` | bibliothèque | — | `.yn-chapter-header` | Fil d'Ariane, « Chapitre N », sous-titre, crédits, temps de lecture. Page Illustrations : `.yn-chapter-header--illustrations`, fil œuvre › tome › Illustrations, h1 « Illustrations », tome en sous-titre, nombre d'illustrations |
| `yume/chapter-nav` | bibliothèque | — | `.yn-chapter-nav` | Précédent · Sommaire · Suivant (liens `rel=prev/next`). Premier chapitre publié d'un tome qui a une page Illustrations : « Précédent » = « Illustrations » (aussi `<link rel=prev>` et ← du lecteur). Page Illustrations : `.yn-chapter-nav--illustrations`, Sommaire du tome · « Commencer la lecture · Chapitre 1 » (`rel=next`, premier chapitre publié) |
| `yume/tome-illustrations` | bibliothèque | — | `.yn-tome-illustrations` | Planches de la galerie du tome, l'une sous l'autre, pleine largeur de la colonne (`--yn-width`), taille `large` (première `eager`/`fetchpriority=high`, suivantes `loading=lazy`), `figure.yn-illustration` + `figcaption` seulement s'il y a une légende, alt de la galerie (« Illustration N — Œuvre, Tome 9 » à défaut), lien vers l'image en grand ; rien sans galerie |
| `yume/glossaire` | glossaire | — | `.yn-glossaire` | Glossaire de l'œuvre du contexte : sommaire des catégories (ancres, compteurs), une section `h2` par catégorie, cartes (`h3` nom, termes VO avec `lang="ja"` si japonais, genre et pluriel, rôle, description ; « Nom conservé » pour `traduire: false` ; spoiler dans `<details>` « Révéler (spoiler, tome N) »), anglicismes en tableau VO → FR ; recherche instantanée et filtre par catégorie (`view.js`, attribut `data-yn-recherche` normalisé, compteur `role=status`) ; jamais de champ interne pour un visiteur ; équipe : notes de traduction (`?notes=1` ou bascule) et entrées « À définir » ; rien sans glossaire |
| `yume/oeuvre-onglets` | bibliothèque | — | `.yn-onglets-oeuvre` | `<nav aria-label="Sections de l’œuvre">` + `ul` de liens vers les onglets de `onglets_oeuvre()` (Présentation, Actualités, Glossaire…), `aria-current="page"` sur l'onglet affiché (Présentation sur la fiche) ; rien si l'œuvre n'a que sa fiche. Dessin de l'intitulé de `yume/tome-list` ; sur mobile la rangée défile horizontalement sans barre visible. Placé sous l'en-tête dans `single-yume_oeuvre.html` et dans chaque gabarit de sous-page |
| `yume/oeuvre-news` | bibliothèque | `limite` (number, 0) | `.yn-oeuvre-news` | Actualités de l'œuvre (articles liés, annonces de sortie comprises) en cartes `.yn-card.yn-carte-article` de la page Actualités (image 16:9 ou dégradé, catégories, titre `h3`, extrait, date). `limite` = 0 : liste paginée de la sous-page (`h2.yn-visually-hidden` « Actualités de {Œuvre} · page N sur M », l'onglet servant d'intitulé visible ; 10 par page, `?pg=N`, `nav.yn-pagination`) ; `limite` > 0 (au plus 12) : encart `.yn-oeuvre-news--encart` « Dernières actualités » + lien « Toutes les actualités », **rien sans article** (fiche : `{"limite":3}` après `yume/tome-list`) |
| `yume/upcoming` | planning | `count` (3) | `.yn-upcoming` | Sans carte propre (le thème fournit la carte). Prochaines sorties compactes (date, œuvre, libellé, pastille d'état). Toujours disponible, mais plus sur l'accueil depuis 2.1.6 (remplacé par `yume/planning-accueil`) |
| `yume/planning-accueil` | planning | — | `.yn-planning-accueil` | Section « Planning » pleine largeur de l'accueil (`front-page.html`, sous `yume/latest-releases`, depuis 2.1.6) : titre et lien « Planning complet », bandeau « À la une : prochain tome » (`rendu_a_la_une()`), puis deux colonnes de même hauteur : « Chapitres en lecture » (`rendu_file_chapitres()`, 14 derniers jours) et « Tomes en préparation » (`rendu_tomes_preparation()`). Sur mobile, onglets « Chapitres » / « Tomes ». Règles de sélection : §7 (vitrine publique) |
| `yume/planning` | planning | `showFilters` (true) | `.yn-planning` | Tomes : à paraître, publiés le jour même (depuis minuit, Paris) et publiés en cours de publication (`publies_du_jour`, depuis 2.1.8). Intro, puis bandeau « À la une : prochain tome » (`rendu_a_la_une()`, depuis 2.1.6) ; « En bref » ; tableau public du planning + légende + journal public récent ; boutons Flux RSS, JSON et « S’abonner au calendrier (ICS) » (`webcal://…/planning.ics`, `?oeuvre=` si filtré) ; onglets « Tableau » / « Chapitres » / « Calendrier » (`?vue=chapitres` : le tableau est remplacé par la file des chapitres groupée par jour, `rendu_file_chapitres()`, avec « Les sorties de tomes sont aussi dans le calendrier ICS » (le flux ICS ne contient que les tomes) ; `?vue=calendrier` : le tableau est remplacé par `yume/calendrier` ; filtres et navigation gardent l’onglet). Tableau : barres d'avancement avec % ; tome publié chapitre par chapitre : « En cours · N chapitres sur M », mini-barre, « Prochain : ch. … date heure », pastille « En cours de publication » ; sur mobile, une carte par tome. Légende : entrées « En cours de publication » et « Chapitre programmé » en plus |
| `yume/calendrier` | planning | — | `.yn-calendrier` | Calendrier mensuel des sorties (`evenements_calendrier()` : `yume_get_planning()` public, tomes parus depuis 365 jours compris ; filtre `yume_planning_evenements`) : `?mois=AAAA-MM` (sinon mois courant, Paris), liens mois précédent/suivant (`rel=prev/next`, sans JS), `<table>` avec `caption` et en-têtes de jours (`abbr`), aujourd’hui `aria-current="date"` ; nature d’une sortie par icône + texte masqué + bordure (`--programme` ◷ fond plein, `--prevu` ◌ pointillés, `--sorti` ✓) ; sous 600 px, liste des jours ayant des sorties (`.yn-calendrier__liste`) à la place de la grille ; légende, liens d’abonnement ICS (webcal) et de téléchargement. Respecte les filtres GET `type`, `etat`, `oeuvre` du planning |
| `yume/oeuvre-planning` | planning | — | `.yn-oeuvre-planning` | Carte « Planning de l'œuvre » (tome en cours, étapes, état) |
| `yume/team-dashboard` | planning | — | `.yn-team` | Espace équipe (connexion requise, capacité `yume_voir_equipe`) : Mes tâches, retards, rappels, journal. Vues de la même page : `?vue=planning` (planning complet modifiable : tous les tomes, filtres œuvre / état / statut / responsable, tri `tri=retard` « En retard d’abord » (retards du plus ancien au plus récent, puis bloqués, puis l'ordre du planning), « Retirer du planning » en admin-post `yume_planning_retrait`, « Mettre en pause » / « Reprendre » (`yume_maj_planning_tous`) en admin-post `yume_planning_pause` (nonce `yume_planning_pause_{id}`, champ `pause` 1/0), lien « Exporter en CSV » → `admin-post.php?action=yume_planning_export&_wpnonce=…` + filtres courants (nonce `yume_planning_export`, capacité `yume_voir_equipe` ; `text/csv; charset=utf-8` en pièce jointe `planning-yume-AAAA-MM-JJ.csv`, BOM UTF-8, séparateur `;`, colonnes Œuvre, Tome, Étape, Statut, Responsables, Date cible, Date programmée, Dernière mise à jour (Paris), Retard ; cellules commençant par `= + - @`, tabulation ou retour chariot préfixées de `'` ; `lignes_vue_planning()` / `csv_planning()`)) `?vue=journal` (journal complet paginé, filtres œuvre / tome) `?vue=lecture` (« Lecture à compléter », capacité `yume_publier` : tomes publiés sans aucun chapitre publié, groupés par œuvre, progression « X tomes sur Y ont la lecture en ligne », filtre `oeuvre`, bouton « Ajouter le DOCX » → `yume_url_page( 'publier' )?tome=ID` ; voir `includes/planning/lecture-a-completer.php`), `?vue=tomes` (« Tous les tomes », capacité `yume_publier`, entrée de navigation juste après « Lecture à compléter » : tous les tomes vivants — publiés, programmés, brouillons — groupés par œuvre, filtres GET `oeuvre`, `statut` (état : `a_paraitre` « Planifiés », `en_cours` « En cours de publication », `complet` « Publiés » ; `programme` — tome ou chapitre programmé — et `brouillon` ; pastilles `yume_etats_tome()`) et `recherche` (titre du tome, `search_columns` = `post_title`), pagination `pg` (60 tomes par page) ; par tome : couverture, statut et date, chapitres en ligne, actions « Voir » (publié), « Lecture en ligne : ajouter le DOCX/EPUB » (aucun chapitre en ligne) ou « Remplacer la lecture en ligne » → `yume_url_page( 'publier' )?tome=ID`, « Ajouter des chapitres » (`yume_url_page( 'publier' )?tome=ID`), « Modifier » (`?vue=tomes&modifier=ID`, jamais wp-admin) ; sous-vues `?vue=tomes&nouveau=1` (« Nouveau tome », `yume_maj_planning_tous`, paramètres `oeuvre` et `depuis` = `tomes`, `planning`, `oeuvres`, `tableau` ou `publier`, admin-post `yume_planning_ajout` étendu à `chapitres_prevus`, `heure_cible` (« Heure de sortie », `HH:MM`, 18:00 proposé, vide accepté → `yume_heure_cible`) et `rythme[jour]` (`rythme_heure` / `rythme[heure]` encore acceptés, repris comme `heure_cible` quand celle-ci est absente), tome existant de même œuvre, nature et numéro : renvoi vers sa fiche ; remplace le formulaire « Ajouter un tome au planning » du tableau de bord, ancre `#yn-ajouter-tome-section` conservée) et `?vue=tomes&modifier=ID` (« Modifier le tome », `yume_publier` + `edit_post` : admin-post `yume_tome_modifier`, nonce `yume_tome_modifier_{id}`, champs du tome (étape, avancement, date cible par `Planning\mettre_a_jour()`, `heure_cible` → `yume_heure_cible`, reprise par le rythme), crédits, couverture et cadrage ; **état du tome** `etat` = `a_paraitre` | `en_cours` | `complet` (segments radio `.yn-etat__choix`, encadrés `.yn-etat__encadre--{etat}` Planifié / En cours de publication / Publié, case `annoncer` décochée par défaut, sans JavaScript, `:has()` pour la mise en avant) appliqué après les champs par `Planning\changer_etat_tome( $id, $etat, {confirmer, comprendre, annoncer}, $user_id )` : droits `yume_publier` + `edit_post`, `publish_yume_tomes` dès que les lecteurs sont touchés, `publish_yume_chapitres` pour retirer des chapitres ; confirmation (erreur 409 `yume_confirmation`, donnée `confirmation`) → redirection `?vue=tomes&modifier=ID&etat=…#yn-tome-etat-confirmation`, écran de confirmation en admin-post `yume_tome_etat` (nonce `yume_tome_etat_{id}`, `confirmer=1`, `annoncer`, `comprendre`) : `publier` (Planifié → Publié : tome non publié marqué `complet` puis `Service::publier( $id, 'maintenant', {confirmer_vide: true, sans_annonce: !annoncer} )`), `complet_incomplet` (En cours → Publié avec moins de chapitres en ligne que `yume_chapitres_prevus` ; sinon direct : `Service::marquer_complet( $id, $liens, $annoncer )`), `rouvrir` (Publié → En cours : `en_cours`, liens gardés mais masqués, étape `publie` → `edition`, `_yume_planning_sortie_partielle`), `retrait` / `retrait_publie` (→ Planifié d'un tome lisible ou programmé, case `comprendre` obligatoire depuis Publié : `Planning\retirer_lecture_tome()` — `Service::annuler_programmations()` (sortie groupée, passage complet), chapitres en ligne et programmés en brouillon + `_yume_retire`, tome en brouillon (annonce remise en brouillon, e-mails et notifications en attente retirés par les modules publication et social), `_yume_publie_notifie` effacée sauf `catalogue` et `_yume_planning_depublie` effacée : la sortie suivante est annoncée) ; Planifié → En cours sans chapitre en ligne : rien ne change (message et lien « Ajouter des chapitres ») ; choix noté dans `_yume_parution_manuelle`, journal `parution` (privé) ; chapitres : admin-post `yume_tome_chapitre`, `op` = `publier`, `date` ou `retirer` (confirmation `&retirer=ID`), nonce `yume_tome_chapitre_{id}`, `edit_post` + `publish_yume_chapitres` ; action `yume_tome_modifie_equipe`) ; voir `includes/planning/tome-fiche-equipe.php` ; la section du tableau de bord `#yn-tous-les-tomes` s'intitule désormais « Tomes en préparation » (planning à venir seulement) ; voir `includes/planning/tomes-equipe.php`), `?vue=taches` (« Mes tâches », capacité `yume_voir_equipe`, cible de l'entrée « Mes tâches » de `navigation_equipe()` sur toutes les pages — pastille des retards conservée — et du bouton des rappels de retard envoyés au responsable (`?vue=taches#yn-tache-{id}` ; le signalement « tome bloqué sans responsable » aux gérants mène à `#yn-tome-{id}` du tableau de bord) : `taches()` du membre connecté, en retard d'abord, cartes `carte_tache()` (formulaire admin-post `yume_planning_maj` + nonce, retour sur la vue via `_wp_http_referer`, message dans la carte ou en tête `#yn-taches-retour` si la tâche n'est plus la sienne), état vide explicite ; voir `includes/planning/mes-taches.php`), `?vue=oeuvres` (« Œuvres », capacité `edit_yume_oeuvres`, première entrée du menu « Catalogue » ; `?vue=oeuvres&modifier=ID` : formulaire de modification (`edit_post`), admin-post `yume_oeuvre_modifier`, champ `oeuvre_id`, nonce `yume_oeuvre_modifier_{id}`, statut inchangé, `post_content` réécrit seulement si l'empreinte du synopsis (`synopsis_origine`, md5 du texte affiché) change ; champs de la « Fiche détaillée » communs aux deux formulaires : `statut_vo`, `nb_tomes_vo`, `source_traduction`, `jours_sortie[]`, `equipe[traduction|relecture|edition]`, `liens[i][label|url]` (valeur vide : méta supprimée) ; action `yume_oeuvre_modifiee_equipe( $id, $saisie, $user_id )` ; section « Genres » (admin-post `yume_genre_equipe`, nonce `yume_genre_equipe`, `op` = `ajouter` (champ `nom`, virgules, `edit_terms`) ou `supprimer` (champ `genre`, `delete_terms`)) ; genres proposés créés une fois à l'installation (`Core\installer_genres()`, option `yume_genres_proposes`) ; toutes les œuvres vivantes — publiées, brouillons — triées par titre, filtres GET `statut` (`publie`, `brouillon`), `etat` (slug `yume_statut`, libellés `yume_etats_oeuvre()`) et `recherche` ; par œuvre : couverture, statut, type, VO (`yume_nb_tomes_vo`, `yume_statut_vo`), tomes et leur état (`yume_parution_tome()` + `yume_etats_tome()`, tous les tomes de la liste lus en une requête par `tomes_des_oeuvres()`, regroupés quand ils se suivent avec même nature et même état : « T1 à T6 ✓ Publiés »), **état de l'œuvre** modifiable (liste déroulante + « Changer », admin-post `yume_oeuvre_etat`, champs `oeuvre_id`, `etat`, `confirmer`, `restaurer_present`, `restaurer`, nonce `yume_oeuvre_etat_{id}`, `edit_post` de l'œuvre ; sans `edit_post` : pastille), suggestion « Tous les tomes de la VO sont publiés : passer à « Terminée » » (`yume_nb_tomes_vo` > 0, `yume_statut_vo` = `termine` et au moins autant de tomes de nature `tome` « Publié » ; lien vers la confirmation, jamais automatique), légende des cinq états ; changement d'état par la fonction unique `Planning\changer_etat_oeuvre( int $oeuvre_id, string $etat, int $user_id, array $options )` (`confirmer`, `restaurer` vrai par défaut ; `WP_Error` 403, 400 `yume_oeuvre_etat`, 409 `yume_oeuvre_etat_confirmation` avec l'aperçu) : entrer dans « Licenciée » ou en sortir exige l'écran de confirmation `?vue=oeuvres&changer=ID&vers=ETAT` (`#yn-oeuvre-etat`, effets en clair, aussi ouvert par la suggestion) ; « Licenciée » : chapitres `publish`/`future` des tomes de l'œuvre en brouillon, marqués `_yume_retire_licence` (date GMT) et `_yume_retire`, e-mails d'alerte en attente de ces chapitres annulés, liens `yume_lien_pdf`/`yume_lien_epub` déplacés dans la méta privée du tome `_yume_liens_licence` (`{pdf, epub, le}`), fiche et tomes inchangés ; sortie de « Licenciée » avec la case « Remettre en ligne la lecture et les liens retirés à la licence » (cochée par défaut) : chapitres marqués encore en brouillon republiés à leur date (reprogrammés si elle est à venir) avec `yume_core_notifier` coupé (ni annonce, ni Discord, ni e-mail), liens remis s'ils n'ont pas été remplacés, marques effacées ; sans la case, tout reste de côté. En pause, terminée, abandonnée, licenciée (`ETATS_OEUVRE_SANS_RAPPELS`, même règle que `yume_oeuvre_sans_rappels()`, lue par le cache des termes : `oeuvre_sans_rappels()`, `tome_sans_rappels()`) : tomes jamais `en_retard` (filtre `yume_planning_etat`, comme `yume_pause`), ni rappel, ni signalement « tome bloqué » aux gérants, ni ligne de retard, de blocage ou de sortie prévue dans le récapitulatif, pastille « Œuvre en pause » (« Œuvre terminée »…) dans l'espace équipe et l'export CSV ; journal de l'équipe `etat_oeuvre` (`tome_id` 0, jamais public), action `yume_oeuvre_etat_change( $oeuvre_id, $ancien, $nouveau, $user_id )` (vide le cache de la bibliothèque, l'ICS, les indicateurs et purge les pages du planning, de l'œuvre, de ses tomes et de la bibliothèque dans Batcache) ; voir `includes/planning/oeuvres-etat.php` ; actions « Voir » (publiée) ou « Publier » (brouillon, admin-post `yume_oeuvre_publier`, champ `oeuvre_id`, nonce `yume_oeuvre_publier_{id}`, `publish_post` + `edit_post`), « Compléter la fiche » (`get_edit_post_link`), « Modifier » (`?vue=oeuvres&modifier=ID`), « Ajouter un tome au planning » (tableau de bord `?oeuvre_ajout=ID#yn-ajouter-tome-section`, œuvre présélectionnée) et « Publier un tome » (`yume_url_page( 'publier' )?oeuvre=ID`, `yume_publier`) ; formulaire « Nouvelle œuvre » en admin-post `yume_oeuvre_creer` (multipart, nonce `yume_oeuvre_creer`) : `titre` (obligatoire, titre déjà pris refusé, casse ignorée), `titres_alt` (une ligne par titre → `yume_titres_alt`), `type` et `avancement` (slugs de `yume_type` / `yume_statut` ; champ « État de l'œuvre », libellés `yume_etats_oeuvre()` ; en modification, l'état passe par `changer_etat_oeuvre()` : un état à confirmer n'est pas appliqué, la fiche est enregistrée puis l'écran de confirmation s'ouvre ; état vide : inchangé), `genres[]` (slugs existants de `yume_genre`) et `nouveaux_genres` (virgules, créés si `edit_terms` de la taxonomie, sinon ignorés avec un avertissement), `auteur`, `illustrateur`, `editeur_vo`, `synopsis` (texte, paragraphes séparés par une ligne vide → blocs `core/paragraph` dans `post_content`, `strong`/`b`/`em`/`i` conservés, 5 000 caractères), `couverture` (`Publication\Fichiers::couverture()` puis `Medias::couverture()`, miniature de l'œuvre), `cadrage_x` / `cadrage_y` (0 à 100 ; méta `yume_cadrage` {x, y} de la pièce jointe affichée par `yume_get_cover_id()`, supprimée au centre 50/50 ; `yume_cadrage_couverture()`, `yume_enregistrer_cadrage()`, et `yume_image_couverture()` qui rend une couverture cadrée en taille non rognée `medium_large` avec `object-position`, utilisée par `Library\couverture()`, la reprise de lecture et le compte), `publier` (0 : brouillon ; 1 : publiée si `publish_yume_oeuvres`) ; action `yume_oeuvre_creee_equipe( $id, $saisie, $user_id )` ; voir `includes/planning/oeuvres-equipe.php`) et `?vue=kpi` (« Indicateurs », capacité `yume_reglages` — gérants et administrateurs, `yume_maj_planning_tous` étant aussi donnée aux éditeurs — : sorties par mois sur 12 mois (graphique en barres CSS `role="img"` + tableau), délai moyen par étape tiré du journal (lignes `creation`/`etape`), charge par membre, retards en cours, lecteurs actifs (table `progression`), favoris et lecteurs par œuvre (tables `favoris`, `progression`), e-mails envoyés/abandonnés/en attente (table `notifications`, 30 jours conservés) ; filtre `periode` = 30, 90 ou 365 jours ; agrégats seulement ; lien « Voir les statistiques d’audience » vers `?vue=statistiques` (même période, `Statistiques\url_statistiques()` gardé par `function_exists`) ; transient `yume_kpi_{jours}` de 5 min effacé sur `yume_planning_mis_a_jour` ; style `yume-kpi` (`includes/planning/assets/kpi.css`) ; voir `includes/planning/kpi.php`), `?vue=sante` (« Santé du site », capacité `yume_reglages`, dernière vue ajoutée — filtre à la priorité 100 —, donc juste avant « Réglages » ; rendu seul dans `includes/planning/sante-equipe.php`, données de `includes/core/admin/sante.php` : tâches planifiées, e-mails, webhooks Discord et bouton « Envoyer un test », version, prérequis de mise en production pour `manage_options` ; tableaux empilés en fiches sous 700 px (`data-libelle`) ; dates en français forcé par `format_fr()` (jeton `G` ajouté) ; style `yume-sante` (`includes/planning/assets/sante.css`, dépend de `yume-kpi`)) et `?vue=reglages` (capacité `yume_reglages` : tous les champs de `sections_reglages()` / `champs_reglages()` visibles pour l'utilisateur — mêmes règles `capability` / `verrouille` que Yume → Réglages —, enregistrés en admin-post `yume_reglages_equipe` avec nonce puis `update_option()`, donc `assainir_reglages()` ; images par ID ou adresse, sans `wp.media` ; voir `includes/planning/reglages-equipe.php`). Navigation (`navigation_equipe()`) : « Tableau de bord » et « Mes tâches », puis des menus repliables `<details>` (`groupes_navigation_equipe()` : `catalogue` — Œuvres, Tous les tomes, Ajouter des chapitres, Lecture à compléter —, `planning` — Planning complet, Journal —, `equipe` — Membres et rôles —, `site` — Réglages en dernier), celui de la page affichée ouvert. Autres vues : filtre `yume_vues_equipe` (clé => `libelle`, `capacite`, `groupe` — menu, `site` par défaut —, `rendu` callable qui rend toute la vue, navigation `navigation_equipe( <clé> )` comprise), entrée à la fin de son menu (avant « Réglages » dans « Site »), vue ignorée sans la capacité ; Glossaires dans `catalogue`, Commentaires dans `equipe`, Statistiques (module statistiques, filtre à la priorité 5, donc en tête), Indicateurs et Santé du site dans `site`. Vue ajoutée par social : `?vue=commentaires` (« Commentaires (N) », capacité `moderate_comments`, entrée présente seulement s'il y a des commentaires à modérer ou sur la vue elle-même ; `includes/social/moderation.php`) : commentaires publiés signalés (les plus signalés d'abord, motifs et comptes) puis en attente, 50 par liste ; actions Approuver / Ignorer les signalements / Indésirable / Corbeille en admin-post `yume_moderation` (champs `commentaire`, `op` = `approuver`, `ignorer`, `indesirable`, `corbeille` ; nonce `yume_moderation_{id}` ; `moderate_comments` + `edit_comment`, sinon boutons absents et message) || `yume/publish-form` | publication | — | `.yn-publish` | Formulaire « Ajouter des chapitres à un tome » (capacité `yume_publier`) : menu de l'espace équipe (`navigation_equipe()`) ; champ caché `mode=chapitres` ; **1 · le tome** : œuvre, puis liste « Tome » de tous les tomes modifiables (champ `tome_planning`, options `data-parution`, `data-en-ligne`, `data-prevus`, `data-rythme`, `data-catalogue`… ; libellé « Tome 2 · en cours de publication · 3 chapitres en ligne » (`yume_etats_tome()`) ; `?tome=ID` présélectionne), fiche du tome (`data-yn-fiche-tome`), lien « + Nouveau tome » (`Formulaire::url_nouveau_tome()`, filtre `yume_url_nouveau_tome`), création du tome ici seulement sans tome choisi (`data-yn-creation` : nature, numéro, titre) ; **2 · le fichier** : dépôts, comparaison avec le tome après l'analyse (`data-yn-comparaison` : état de chaque chapitre, choix `choix[clé]` « Garder la version en ligne » / « Mettre à jour (sans annonce) »), découpage manuel ; **3 · la sortie** : `sortie` (maintenant, un par un au rythme ou tous les `intervalle` jours, à une date `date_sortie`), « Annoncer les nouveaux chapitres » (`annoncer`, champ caché `0` + case `1`) ou, pour un tome paru et complet, « Ajout au catalogue » (`sans_annonce`, cochée d'office ; le bloc masqué est désactivé), « Le tome est complet avec ces chapitres » (`complet`, liens `lien_pdf` / `lien_epub` activés seulement alors) ; récapitulatif « Ce qui va se passer » (`data-yn-recap`, recalculé par le script : chapitres et dates, mises à jour, annonces, planning, tome complet, chapitres inchangés) ; boutons « Publier / Programmer N chapitres » (`publier`), « Prévisualiser » (`apercu`), « Enregistrer en brouillon » ; confirmation d'un tome vide (`confirmer_vide`) ; note « le fichier leur est comparé » (`data-yn-note-tome`) quand le tome a déjà des chapitres ; sous le formulaire, tome publié (`?tome=ID`) qui a des chapitres en ligne : encadré « Remplacer la lecture en ligne (N chapitres actuels) » (`data-yn-mode-remplacement`, remplacement explicite en deux temps, case `retirer_absents` liée au formulaire) qui détaille le remplacement en place, les chapitres absents et l'absence d'annonce, avec le bouton « Vérifier (sans rien changer en ligne) » (étape `verifier`, attribut `form="yn-publish-formulaire"`) et, quand une version de remplacement attend (`data-yn-attente`), son bilan, l'aperçu de chaque chapitre et les boutons « Remplacer la lecture en ligne maintenant » (`remplacer`) et « Annuler le remplacement » (`annuler_remplacement`), avec ou sans JavaScript |
| `yume/team-members` | planning | — | `.yn-team` | Espace équipe, « Membres et rôles » (capacité `yume_gerer_equipe`) : membres et rôle, changer le rôle, ajouter un compte existant, retirer de l'équipe (envoi à `admin-post.php`, action `yume_equipe_membres`, nonce) ; avertissement sur un membre responsable de tomes en cours, lien « Modifier dans l'administration » (administrateur) pour les comptes non modifiables ici |
| `yume/partenaires` | bibliothèque | `title` (string, « Nos partenaires »), `variante` (`cartes` \| `en-ligne`, `cartes`) | `.yn-partenaires`, `.yn-partenaires-en-ligne` | Section de l'accueil : logo (initiales à défaut), nom, description, lien en nouvel onglet ; variante `en-ligne` : paragraphe « Partenaires : A · B » (rien sans partenaire), rendu aussi par `partenaires_en_ligne()` dans la mention du pied de page du thème (paragraphe `yn-copyright`) ; réglage `partenaires` (§6) |
| `yume/recherche` | bibliothèque | `perPage` (20 : un seul groupe affiché), `apercu` (5 : par groupe quand tous sont affichés), `showFilters` (true) | `.yn-search` | Page de résultats de `/?s=` (modèle `search.html` du thème, à la place de la boucle de requête ; AMEL-04, `includes/library/recherche.php` + `recherche-rendu.php`). Groupes `oeuvres` (titre, `yume_titres_alt`, `yume_auteur`, `yume_illustrateur`, `yume_editeur_vo`), `tomes` (titre ; publiés, œuvre publiée), `actualites` (articles publiés sans mot de passe : titre, extrait, texte ; préfiltre SQL limité à 300, filtre `yume_recherche_actualites_max`) et, si `recherche_chapitres` est actif, `chapitres` (texte et titre des chapitres publiés d'un tome et d'une œuvre publiés, œuvre ni `licenciee` ni statut du filtre `yume_recherche_statuts_exclus_chapitres` ; terme ≥ 3 caractères ; 60 chapitres lus au plus, les plus récents, filtre `yume_recherche_chapitres_max`, compteur « N+ » au-delà ; extrait de 200 caractères autour de la première occurrence, lien `#yn-p-N` vers le bloc de premier niveau). GET : `s`, `contenu` (un groupe), `statut` (liste de slugs, groupes de la bibliothèque), `genre`, `tri` (`pertinence` par défaut, `recent`, `az`), `pg_{groupe}` (pagination propre à chaque groupe, ancre `#yn-search-{groupe}`) ; compteurs par groupe ; tout mot doit figurer (ET) ; comparaison sur texte « plié » (`plier()` : minuscules, sans accents ni ligatures, apostrophe typographique) identique sur SQLite et MariaDB : SQL ne fait qu'un préfiltre `LIKE` large (lettres accentuables en `_` sauf collation `*_ci` MySQL, `œ`/`æ` et `< > & "` en `%`, filtre `yume_recherche_like_insensible`), PHP décide. Surlignage `<mark class="yn-search__marque">` dans un texte toujours échappé (le terme n'est jamais débarrassé de ses balises, il est échappé). Aucun résultat : œuvres au titre proche (distance d'édition) et lien Bibliothèque. Index (œuvres, tomes) dans le cache de la bibliothèque (`en_cache( 'recherche' )`, renouvelé aussi au changement des métas ci-dessus et de `_thumbnail_id`) |
| `yume/reader-tools` | lecture | — | `.yn-reader-tools` | Barre de lecture, repère `<header aria-label="Barre de lecture">` (les gabarits de lecture n'ont pas d'en-tête du site) : progression, sommaire, marque-page, thème, panneau Paramètres. Page Illustrations : même barre (retour et Sommaire vers le tome, réglages, thème, compte) sans marque-page, configuration `chapitre: 0` (aucun suivi, position jamais écrite), `next` = chapitre 1 |
| `yume/oeuvre-actions` | lecteurs | — | `.yn-oeuvre-actions` | Reprendre, Favori (compteur), Note (moyenne), Alerte ; membre connecté : menu « Ajouter à une liste » (`details[data-yn-menu="listes"]`, inséré par `render.php` via `inserer_menu_listes()`, `listes.php`) : une case par liste (PUT/DELETE REST à chaque case), création rapide (POST puis PUT), sans JavaScript formulaire `admin-post.php?action=yume_listes_oeuvre` (nonce `yume_social_{oeuvre}`, messages `?yn-lmsg=`) |
| `yume/resume-reading` | lecteurs | `layout` (enum `bandeau`,`carte`) | `.yn-resume` | Reprendre la lecture (membre : serveur ; visiteur : `localStorage`). En `bandeau`, rend seulement son contenu (surtitre `.yn-label`, titre, bouton `.yn-btn--primary` « Continuer ») : le thème fournit le bandeau. Rien à reprendre : aucune sortie, ou `.yn-resume[hidden]` tant que le JS visiteur n'a rien trouvé |
| `yume/account` | lecteurs | — | `.yn-account` | Page compte : lecture en cours, favoris et alertes, notes, réglages, données (export/suppression) ; visiteur : connexion en façade (`#yn-connexion`, admin-post `yume_connexion`, §6 ter), petit lien « Connexion administrateur » (wp-login.php), mot de passe oublié, inscription |
| `yume/auth-links` | lecteurs | — | `.yn-auth` | « Connexion » (la page connexion propose aussi l'inscription) ou « Mon compte » (+ « Espace équipe » si capacité) et « Se déconnecter » ; placé dans `core/navigation`. Membre connecté : cloche des notifications en tête (`.yn-cloche`, `inserer_cloche()`, `notifications-lecteur.php`) — pastille du nombre de non lues (rendu serveur), bouton `aria-expanded`/`aria-controls` qui ouvre le panneau `#yn-cloche-panneau` (8 dernières, « Tout marquer comme lu », lien `compte#yn-notifications`), lien simple sans JavaScript ; script `yume-cloche` (`blocks/auth-links/view.js`) chargé pour les membres seulement : rafraîchi à l'ouverture et toutes les 5 min si l'onglet est visible ; aucun script ni requête pour un visiteur |
| `yume/liste-publique` | lecteurs | — | `.yn-liste-publique` | Page d'une liste de lecture (`/listes/{id}-{slug}/`, gabarit injecté par `listes.php`, hors inserteur) : « Liste de lecture », titre en `<h1>`, « par {nom affiché} » (jamais l'identifiant de connexion), nombre d'œuvres, date de mise à jour, description, grille de couvertures des œuvres publiées ; propriétaire : état Publique/Privée et lien « Gérer mes listes » |
| `yume/recrutement` | planning | — | `.yn-recrutement` | Page « Rejoindre l'équipe » : introduction (`recrutement_intro`), `h2` « Postes ouverts » + cartes `.yn-recrutement__poste` (`h3`, description, pastille « Recrutement ouvert ») ou « Aucun poste ouvert pour le moment », carte « Postuler » : consigne, bouton « Postuler sur le Discord » (`discord_invite`, nouvel onglet) et « Télécharger le test de traduction » si `recrutement_test_url`. **Aucun formulaire** : le site ne recueille aucune candidature |
| `yume/profil-contributeur` | lecteurs | `mode` (`auto` \| `liste`, `auto`), `titre` (string, « Profils des contributeurs ») | `.yn-contributeur`, `.yn-contributeurs` | `auto` : profil sur `/contributeurs/{slug}/` (fil d'Ariane, avatar à initiales, `h1` pseudo, rôle Yume, « Dans l'équipe depuis », présentation, liens `rel="me nofollow noopener"`, contributions par œuvre), liste avec `h1` « Contributeurs » sur `/contributeurs/`. `liste` (ou hors de `/contributeurs/`) : `h2` + cartes (initiales, pseudo lié, rôle, nombre de tomes publiés), **rien** sans profil public. Ajouté automatiquement sous le contenu de la page « L'équipe » (slug `lequipe`, filtre `render_block_core/post-content`) avec un bouton « Rejoindre l'équipe » ; les mentions « Poste à pourvoir » de cette page mènent à la page de recrutement |
| `yume/recommandations` | recommandations (L3, 2.5.0) | `count` (number, 4) | `.yn-recommandations` | Fiche d'une œuvre (après `yume/oeuvre-news`) : « Si vous avez aimé {Œuvre} », cartes couverture, titre, type, genres communs en pastilles, « N lecteurs la suivent aussi » (co-favoris ≥ 3) ; HTML identique pour tous les visiteurs, favoris et lectures du membre retirés (§8 sexies) |
| `yume/rattrapage` | rattrapage (L5, 2.5.0) | `count` (number, 6), `layout` (`section` \| `carte`, `section`) | `.yn-rattrapage` | « Nouveaux chapitres depuis votre dernière lecture » : membre connecté seulement (rendu serveur), chaîne vide pour un visiteur ou sans rien à rattraper ; cartes couverture, œuvre, « Tome 2 · 3 nouveaux chapitres », « Reprendre » et « Chapitre suivant » (§8 sexies) |
| `yume/theme-toggle` | **thème** | — | `.yn-theme-toggle` | Bascule Nuit ↔ Papier (`aria-pressed`, `[data-yn-theme-toggle]`) |

Les blocs posés dans le modèle `page-large` (planning, compte, équipe, publication) commencent leurs
titres au `<h2>` : le modèle affiche déjà le titre de la page en `<h1>`. `yume/library-grid` accepte
les paramètres GET `type`, `statut` (`slug[,slug…]`), `genre`, `tri` (`recent` par défaut, `az`) et `pg`
(pagination) ; filtre `yume_bibliotheque_groupes_statuts`. `yume/latest-releases` affiche une carte par
tome, datée par ses chapitres pour un arc, un web novel ou un tome dont `yume_parution` est posée
(`sortie_par_chapitres()`). Filtre `yume_bibliotheque_ligne_tome` : le
module lecteurs y ajoute la progression personnelle sur les lignes de `yume/tome-list`.
Parution : les statistiques d'un tome (`stats_tome()`) portent `parution` (= `yume_parution_tome()`,
seule source de vérité), `en_cours` (parution `en_cours`) et `prochain` (date du prochain chapitre
programmé) ; `yume_parution`, `yume_chapitres_prevus` et `yume_rythme` renouvellent la version du cache.
Performance (`library/donnees.php`) : les statistiques des tomes d'une œuvre (`stats_oeuvre()`, en
transient versionné) lisent les chapitres de tous les tomes en **une** requête
(`chapitres_des_tomes()`, même ordre que `yume_get_chapitres()`) et sautent les tomes dont
`yume_nb_chapitres` vaut 0 hors sortie chapitre par chapitre ; `amorcer_caches()` charge en un appel les contenus,
leurs méta, leurs couvertures et, pour les chapitres de la liste, les segments d'URL de leurs tomes
(`Core\amorcer_segments()`, `includes/core/routing.php`) : `yume/tome-list` et `yume/latest-releases` ne
font plus une requête par tome pour les permaliens des chapitres. Les pages de `yume_pages` sont
chargées en une requête sur `template_redirect` (`amorcer_pages_yume()`) avant le rendu des liens.

**Référencement** (`library/seo.php`, `wp_head`) : JSON-LD (filtre `yume_bibliotheque_jsonld`) et
`rel=prev|next` des œuvres, tomes et chapitres publiés ; à la priorité 5, `<meta name="description">`,
Open Graph (`og:site_name`, `og:locale` = `fr_FR`, `og:type` = `website` pour l'accueil et les autres
pages, `book` pour une œuvre ou un tome, `article` pour un chapitre ou un article avec
`article:published_time`/`modified_time`, `og:title`, `og:description` = `description_seo()`, `og:url`
canonique, `og:image` + `:width`/`:height`/`:alt` = couverture de l'œuvre ou du tome (celle du tome pour
un chapitre), image mise en avant d'un article (sinon couverture de l'œuvre liée), sinon image par défaut :
bannière (`banniere_id`), logo, icône du site) et Twitter Card (`twitter:card` = `summary_large_image`
avec une image, `summary` sans ; `twitter:site` = `@YumeNovel`). Rien sur les 404, la recherche, la page
Illustrations (noindex), un contenu non publié ou protégé par mot de passe, les pages privées (`compte`,
`connexion`, `equipe` et ses sous-pages de `yume_pages`, ou page contenant `yume/account`,
`yume/team-dashboard`, `yume/team-members`, `yume/publish-form`) ; description et `og:description`
omises si vides. Filtre `yume_open_graph( array $balises, int $post_id )` (clé = propriété `og:*`,
`article:*`, `twitter:*` ou `description` ; tableau vide : rien n'est émis). L'Open Graph de Jetpack est
coupé (`jetpack_enable_open_graph` → faux) pour éviter les doublons.
`yume/planning` montre à un membre connecté (`yume_voir_equipe`) « Modifier dans l'espace équipe »
(en tête et sous chaque tome, vers `?vue=planning`). Thème : avec `comment_registration` = 1, le
formulaire de commentaire est remplacé par une invitation à se connecter ou à créer un compte ; le
lien « Contact » de l'en-tête (classe `yn-lien-discord`) ouvre l'invitation Discord (réglage `discord_invite`).

## 11. Pages créées par la migration (option `yume_pages` : clé → ID)

| Clé | Slug | Contenu |
| --- | --- | --- |
| `bibliotheque` | `bibliotheque` | `yume/library-grid` |
| `planning` | `planning` | `yume/planning` |
| `equipe` | `equipe` | `yume/team-dashboard` |
| `publier` | `equipe/publier` (page enfant) | `yume/publish-form` |
| `membres` | `equipe/membres` (page enfant) | `yume/team-members` |
| `compte` | `compte` | `yume/account` |
| `connexion` | `connexion` | formulaire de connexion (admin-post `yume_connexion`, §6 ter) et d'inscription rendu par `yume/account` quand déconnecté ; cible de `wp_login_url()` hors administration |
| `actualites` | `actualites` | page des articles (`page_for_posts`) |
| `mentions-legales` | `mentions-legales` | texte de base à compléter par l'équipe |
| `rejoindre` | `rejoindre-l-equipe` | `yume/recrutement` (lien « Rejoindre l'équipe » du sous-menu « Yume Novel » de l'en-tête, classe `yn-lien-rejoindre` ; `yume_url_page( 'rejoindre' )` → `/rejoindre-l-equipe/` sans page enregistrée) |
| `accueil` | `accueil` | page d'accueil statique (`page_on_front`), rendue par `front-page.html` : bannière, « Dernières sorties » (`yume/latest-releases`), section « Planning » (`yume/planning-accueil`, depuis 2.1.6 ; remplace la carte « Prochaines sorties »), actualités, bandeau de reprise de lecture ou Discord, partenaires |

La page `mentions-legales` est visée par le pied de page. Réglages de lecture : `show_on_front = page`,
`page_on_front` = la page `accueil` (le thème fournit `front-page.html`), `page_for_posts` = la page `actualites`.
Le format du plan de migration (`plan.json` v1) est décrit dans `tools/migrate/README.md` : c'est
l'interface entre l'analyse et l'exécution. Pendant l'exécution : `add_filter( 'yume_core_notifier',
'__return_false' )`, ne pas créer les termes `yume_oeuvre_liee` à la main (ils naissent avec les œuvres).
La migration pose aussi `comment_registration = 1` (commentaires réservés aux comptes) ; une page dont
le titre ou le slug fait 3 caractères au plus, ou au contenu quasi vide, et vers laquelle aucun lien
de l'export ne mène, est classée « ignorée » (SCAN-22). L'annulation restaure les options
sauvegardées (dont `comment_registration`) telles quelles, sans les filtres `sanitize_option_{option}`.

## 12. REST `yume/v1`

| Méthode et route | Module | Permission |
| --- | --- | --- |
| `GET /planning` | planning | public (champs publics uniquement) |
| `PATCH /tomes/(?P<id>\d+)/planning` | planning | `yume_user_can_edit_planning` (règles du §5 : 400 `yume_etape_prematuree`, 403 `yume_avancement_interdit`) ; `heure_cible` (`HH:MM` ou vide) à côté de `date_cible` → `yume_heure_cible` |
| `DELETE /tomes/(?P<id>\d+)/planning` | planning | `yume_maj_planning_tous` — retire le tome du planning (`retirer_tome()`, §7) |
| `GET /planning/journal` | planning | public (sans notes d'équipe) |
| `POST /publications/analyse` | publication | `yume_publier` — multipart `source` (DOCX/EPUB) → rapport sans rien créer, avec `candidats` et `decoupages` (§9) ; `plan` (JSON ou objet, §9) : découpage manuel essayé sur ce fichier ; 400 `rest_invalid_param` si le découpage est invalide ; `tome_id` (tome choisi, sinon œuvre + nature + numéro), `choix` → `comparaison` (état de chaque chapitre par rapport au tome) et `tome` (parution, rythme, dates au rythme), voir l'ajout de chapitres (§8) |
| `POST /publications` | publication | `yume_publier` — crée le tome (brouillon) + chapitres (brouillons) ; `plan` (JSON ou objet, §9) : découpage manuel appliqué au fichier `source` de la même requête (ignoré et signalé sans fichier) ; `sans_annonce` (booléen) : aucun article d'annonce préparé ; réponse `sans_annonce` (valeur retenue) ; tome paru avec lecture en ligne : versions en attente, rien ne change en ligne, réponse `remplacement` (§8), 409 `yume_remplacement_en_attente` si un autre membre en a déjà un ; `tome_id` : tome choisi, prioritaire, jamais renommé ; `mode` (`chapitres` : ajout de chapitres sans remplacement en deux temps, `remplacement` : explicite, absent : comportement historique), `choix`, `complet` ou `liens_dernier` (+ `lien_pdf`, `lien_epub` gardés pour la sortie), `chapitres_prevus` (0 à 999, relevé d'office, §8), `annoncer` (inverse de `sans_annonce`) ; réponse `mode`, `comparaison`, `parution`, `chapitres_prevus` et, en mode chapitres, `message` (§8) |
| `POST /publications/(?P<id>\d+)/publier` | publication | `yume_publier` — `quand` = `maintenant` ou date ISO → publie/programme tome + chapitres ; tome sans chapitre ni lien PDF/EPUB : 409 `yume_tome_vide` sauf `confirmer_vide=true` ; `sans_annonce` (booléen) : ajout au catalogue sans annonce (§8) ; **absent : vrai si le tome est déjà publié (`publish`) et complet, faux sinon (tome en cours de parution compris)** (même règle pour `POST /publications`) ; réponse `sans_annonce` ; en mode catalogue sur un tome qui avait déjà des chapitres en ligne : `remplacement` (true) et `en_ligne` (chapitres publiés) ; applique d'abord un remplacement de lecture en ligne en attente (§8), réponse `remplacement_applique` ; `mode=chapitres` : `sortie` (`maintenant` | `rythme` | `date`), `intervalle` (1 à 60 jours), `complet` (absent : repris de la préparation ; vrai : tout sort maintenant, programmés compris), `liens_dernier` (absent : repris de la préparation), `lien_pdf`, `lien_epub`, `annoncer` → réponse `calendrier`, `sortie`, `parution`, `complet`, `complet_le`, `article_complet`, `message` ; seules les mises à jour choisies en mode chapitres sont appliquées (§8) |
| `DELETE /publications/(?P<id>\d+)/remplacement` | publication | `yume_publier` + droit de modifier le tome — annule le remplacement de lecture en ligne en attente (versions et images supprimées, rien ne change en ligne) → `{annule, message}` ; 404 `yume_remplacement_absent` s'il n'y en a pas |
| `GET /moi` | lecteurs | connecté |
| `GET, PUT /moi/reglages` | lecture | connecté |
| `GET, PUT /moi/progression` | lecture | connecté |
| `POST, DELETE /moi/favoris/(?P<oeuvre>\d+)` | lecteurs | connecté |
| `PUT /moi/notes/(?P<oeuvre>\d+)` | lecteurs | connecté ; `note` 1–5, 0 = retirer |
| `PUT /moi/alertes/(?P<oeuvre>\d+)` | lecteurs | connecté ; `frequence` immediat/hebdo/jamais |
| `GET /moi/export`, `DELETE /moi` | lecteurs | connecté (RGPD) ; `DELETE` exige `confirmation=SUPPRIMER` et `mot_de_passe`, refusé pour l'équipe et les administrateurs |
| `GET /moi/listes` | lecteurs | connecté — listes du membre (les trois listes système `a_lire`, `en_cours`, `termine` créées au besoin, puis les personnelles) : `{listes: [{id, nom, slug, description, publique, systeme (clé ou null), nb, oeuvres (IDs publiés), url, cree_le, maj_le, contient?}], auto, max, reste}` ; `?oeuvre=` ajoute `contient` |
| `POST /moi/listes` | lecteurs | connecté — `nom` (1–60), `description` (280), `publique` ; 201 ; 409 `yume_listes_limite` au-delà de 20 listes personnelles, 400 `yume_liste_nom` |
| `PATCH, DELETE /moi/listes/(?P<id>\d+)` | lecteurs | connecté — liste du membre seulement (sinon 404 `yume_liste_introuvable`) ; listes système : ni renommées ni supprimées (400 `yume_liste_systeme`), `publique` et `description` modifiables |
| `PUT, DELETE /moi/listes/(?P<id>\d+)/oeuvres/(?P<oeuvre>\d+)` | lecteurs | connecté — ajoute (201, 200 si déjà présente) ou retire une œuvre publiée (404 sinon) ; 409 `yume_liste_pleine` au-delà de 500 œuvres ; une liste système retire l'œuvre des deux autres ; réponse `{oeuvre, listes: [IDs des listes du membre qui la contiennent]}` |
| `GET /moi/notifications` | lecteurs | connecté — `page`, `limite` (1–50, 20), `non_lues` ; `{notifications: [{id, type (sortie\|reponse), objet_id, titre, url, cree_le, lu, lu_le}], non_lues, total, page, pages}`, en-têtes `X-WP-Total`, `X-WP-TotalPages`, `Cache-Control: no-store, private`. Le service worker s'y authentifie sans nonce par les en-têtes `X-Yume-Push-Endpoint` et `X-Yume-Push-Auth` de l'abonnement (`authentifier_push()`, filtre `rest_authentication_errors` priorité 150, cette route en GET seulement) |
| `POST /moi/notifications/lues` | lecteurs | connecté — `ids` (200 au plus, celles du membre seulement) ou toutes si absent ; `{marquees, non_lues}` |
| `POST, DELETE /moi/push` | lecteurs | connecté — abonnement Web Push de l'appareil : `endpoint` (https d'un service push connu, sinon 400 `yume_push_hote`), `p256dh` et `auth` (base64url, 65 et 16 octets) ou `keys` au format `PushSubscription.toJSON()` ; 201 ; 403 `yume_push_inactif` si désactivé ; `DELETE` : `endpoint` |
| `GET /push/cle` | lecteurs | public — `{actif, cle}` : clé publique VAPID (point P-256 non compressé, base64url), `null` si les notifications navigateur sont désactivées |
| `POST /commentaires/(?P<id>\d+)/signalement` | lecteurs | connecté (nonce REST) — signale un commentaire publié d'un contenu public (`motif` facultatif, 200 caractères au plus) : 404 `yume_commentaire_introuvable`, 400 `yume_signalement_propre` (son propre commentaire), 409 `yume_deja_signale`, 429 `yume_trop_de_signalements` (débit) ; réponse `{signale, attente, message}`, `attente` vrai quand le seuil renvoie le commentaire en modération. Bouton « Signaler » du thème (`inc/commentaires.php`, script `assets/js/commentaires.js` chargé seulement sur les pages qui l'affichent) ; badge « Équipe » (auteur ayant `yume_voir_equipe`) sur `core/comment-author-name` |
| `POST /planning/tomes` | planning | `yume_maj_planning_tous` — ajoute un tome brouillon au planning (œuvre, nature, numéro, titre, responsables, date cible, `heure_cible` `HH:MM` ou vide, chapitres prévus, rythme ; `rythme[heure]` encore accepté, repris comme `heure_cible`) ; 409 si doublon |
| `GET /planning/journal?format=rss` | planning | public — flux RSS du journal |
| `GET /planning.ics` | planning | public — calendrier iCalendar (RFC 5545, `text/calendar; charset=utf-8`, servi brut par `rest_pre_serve_request`) : un `VEVENT` par tome daté du planning public (mêmes événements que `yume/calendrier`), `UID:tome-<id>@<hôte>`, `DTSTAMP` = dernière mise à jour ; prévu : `STATUS:TENTATIVE`, « (prévision) » dans `SUMMARY`, `DTSTART` UTC horodaté à la date cible et à `yume_heure_cible` (heure de Paris) quand l'heure est connue, sinon journée entière `DTSTART;VALUE=DATE` ; programmé et paru : `DTSTART` UTC, `STATUS:CONFIRMED` ; `SUMMARY` « Œuvre T.N », `DESCRIPTION` avec l’état, `URL` du tome paru sinon de l’œuvre ; série à venir (§7) : `SUMMARY` = `yume_titre_public_oeuvre()`, sans titre de tome ni `URL` ; lignes pliées à 75 octets, CRLF. `?oeuvre=<id\|slug>` (œuvre publiée, sinon 404 `yume_oeuvre_inconnue`). Cache : transient `yume_planning_ics` (1 h), vidé sur `yume_planning_mis_a_jour`, `yume_tome_publie`, `save_post`/`deleted_post` d’un tome ou d’une œuvre. `<link rel="alternate" type="text/calendar">` dans le `<head>` des pages contenant `yume/planning` ou `yume/calendrier` |
| `GET /migration`, `POST /migration/executer`, `POST /migration/annuler` | migration | `manage_options` ; `confirmation=MIGRER` / `ANNULER`, exécution par lots, reprise (`ignorer`) |
| `POST /oeuvres/(?P<oeuvre>[\w-]+)/glossaire` | glossaire | `yume_glossaire` (mot de passe d'application ou cookie + nonce) — envoi ponctuel d'un glossaire YAML (corps brut `application/yaml`, JSON `{yaml, note}` ou multipart `glossaire`) ; `simulation=1` : bilan sans écriture ; réponse `{statut: importe\|inchange\|simulation, oeuvre, nb_entrees, total, anglicismes, publiques, avertissements, sha256, version}` ; 400/413/415 (`yume_glossaire_*`), 404 `yume_oeuvre_introuvable`, 429 `yume_glossaire_limite` (20 envois/h/compte). `docs/glossaire.md` |
| `GET /oeuvres/(?P<oeuvre>[\w-]+)/glossaire` | glossaire | `yume_glossaire` — métadonnées de la version en ligne (`version: {id, cree_le, sha256, nb_entrees, source, auteur, note}` ou `null`), sans le contenu |
| `GET /suggestions?q=` | bibliothèque | public (`includes/library/recherche-rest.php`) — suggestions instantanées de la recherche : `q` obligatoire (200 caractères au plus) ; moins de 2 caractères : liste vide ; 8 au plus (`suggestions()`) : œuvres publiées par titre et titres alternatifs, puis tomes publiés ; réponse `{q, total, suggestions: [{type: oeuvre\|tome, id, titre, detail, url, image (miniature ou '')}], recherche (URL /?s=)}` ; cache transient 5 min (clé versionnée par le cache de la bibliothèque) ; débit : 60 demandes par minute et par IP hachée (HMAC, transient `yume_sugg_*`, filtre `yume_suggestions_limite`, 0 = sans limite), au-delà 429 `yume_trop_de_requetes`. Utilisée par le champ de recherche de l'en-tête du thème (`inc/recherche.php` étend le filtre `render_block_core/search` sur le bloc de classe `yn-nav__recherche` : champ `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded`, `aria-controls` → `ul[role=listbox]`, `aria-activedescendant` géré par `assets/js/suggestions.js`, annonce `aria-live` du nombre ; filtre de thème `yume_theme_suggestions_recherche`) |
| `GET /statistiques/resume?periode=` | statistiques | `yume_voir_statistiques` — aperçu : `{periode, bornes, calcule, trafic (trafic_resume() ou null), source_trafic (jetpack\|simule\|aucune), lecteurs (resume_lecteurs()), top_suivies, top_vues}` (§8 ter) |
| `GET /statistiques/trafic?periode=&section=` | statistiques | `yume_voir_statistiques` — `section` = `contenus` (défaut), `referents`, `recherches`, `pays` (autre valeur : 400) → sortie de `trafic_<section>()` ; sans données : `{source: 'aucune', message}` si le trafic n'a pas de source, sinon (erreur de l'API) `{source, erreur: true, message}` + `detail` (`derniere_erreur_trafic()`) pour `manage_options` seulement |
| `GET /statistiques/oeuvres?periode=&tri=&page=&limite=` | statistiques | `yume_voir_statistiques` — `classement()` ; `tri` ∈ `favoris`, `suiveurs`, `lecteurs`, `vues`, `nouveaux`, `croissance` ; `limite` 1–100 (20) |
| `GET /statistiques/oeuvres/(?P<oeuvre>\d+)?periode=` | statistiques | `yume_voir_statistiques` — `fiche_oeuvre()` ; 404 `yume_oeuvre_introuvable` |
| `GET /statistiques/oeuvres/(?P<oeuvre>\d+)/lecteurs?type=&q=&page=&limite=&tri=&ordre=` | statistiques | **`yume_voir_lecteurs`** — `lecteurs_oeuvre()` : `type` `favoris` (défaut) \| `suiveurs`, `q` (pseudo, 2 caractères au moins, sinon ignorée), `limite` 1–100 (50), `tri` `date` \| `pseudo`, `ordre` `desc` \| `asc` → `{oeuvre, type, total, page, pages, limite, q, lecteurs: [{pseudo, depuis (ISO 8601 UTC), frequence, equipe}]}`, jamais `user_login` ni `user_email` |
| `POST /chapitres/(?P<chapitre>\d+)/coquilles` | corrections (L1, 2.5.0) | connecté (attente `connectes` ; mode anonyme `coquilles_anonymes` : la permission accepte l'anonyme, la matrice de sécurité force le réglage à faux) — corps `{paragraphe: int ≥ 0, extrait: string 3–300, contexte: string ≤ 600, commentaire?: string ≤ 500}` → 201 `{id, message}` ; 404 `yume_chapitre_introuvable`, 400 `yume_coquille_extrait`, 409 `yume_coquille_doublon`, 429 `yume_coquilles_debit` / `yume_coquilles_chapitre_sature`, 403 `yume_coquilles_inactives` (§8 quater) |
| `GET /discord/retour` | discord (L2, 2.5.0) | public (`__return_true` ; servi par `rest_pre_serve_request` : redirection, `nocache_headers()`) — paramètres `code`, `state`, `error` ; 404 `yume_discord_inactif` si non configuré (§8 quinquies) |
| `GET /recommandations/(?P<oeuvre>\d+)` | recommandations (L3, 2.5.0) | public — `{oeuvre, recommandations: [{id, titre, url, image, type, genres_communs: string[], cofavoris: int\|null, score}]}`, `Cache-Control: public, max-age=3600` ; 404 `yume_oeuvre_introuvable` (œuvre non publiée) |
| `GET /moi/recommandations` | recommandations (L3, 2.5.0) | connecté — `recommandations_membre()`, `Cache-Control: private, no-store` |
| `GET /moi/rattrapage?limite=` | rattrapage (L5, 2.5.0) | connecté — `limite` 1–50 (6) → `{total, oeuvres: [{oeuvre_id, titre, url, image, restants, nouveaux_depuis, tome: {id, libelle}, prochain: {id, titre, url}, reprise: {url, titre, pourcentage}, dernier_publie_le}]}`, `Cache-Control: private, no-store` |

Les routes `/statistiques/*` sont toutes en lecture : 401 anonyme, 403 sans la capacité, `Cache-Control:
private, no-store` sur toutes les réponses, erreurs comprises (`rest_request_after_callbacks`) ; 503
`yume_statistiques_indisponible` si la fonction de données du module manque ; période hors de 7, 30,
90, 365 → 30.

Attentes de sécurité des routes du chantier « lecteurs » (`tests/test-securite-rest.php`, seul fichier
partagé) : chaque lot ajoute **à la fin** de `yume_tsrest_attentes()` un bloc commenté `// L1 —
Corrections (includes/corrections/rest.php)`, `// L2 — Discord …`, `// L3 — Recommandations …`, `// L5 —
Rattrapage …`, ordre de fusion L1 → L2 → L3 → L5, motifs copiés exactement comme déclarés.

**Flux RSS d’une œuvre** (`includes/library/flux.php`) : `/oeuvres/{o}/feed/` (et `rss2`, `rss`, `atom`, `rdf`) sert en RSS 2.0 les tomes et chapitres publiés de l’œuvre (cache de la bibliothèque) et les articles liés (`yume_oeuvre_liee`), du plus récent au plus ancien, 50 au plus (filtres `yume_flux_oeuvre_max`, `yume_flux_oeuvre_elements`) ; l’ancien flux natif des commentaires de l’œuvre reste servi avec `?commentaires=1`. La fiche d’une œuvre publiée annonce ce flux par `<link rel="alternate" type="application/rss+xml">` (le lien natif des commentaires y est retiré).

Précisions : `PUT /moi/alertes/{oeuvre}` répond 409 si l'œuvre n'est pas en favori ; `PUT /moi/reglages`
accepte `reinitialiser` ; `GET /moi/progression?oeuvre=` renvoie `url`, `url_reprise` (`#yn-p-N`) et `titre`.

## 13. Tables (préfixe `{$wpdb->prefix}yume_`)

| Table | Colonnes | Module |
| --- | --- | --- |
| `favoris` | `user_id` BIGINT, `oeuvre_id` BIGINT, `frequence` VARCHAR(10) DEFAULT 'immediat', `created_at` DATETIME ; PK (user_id, oeuvre_id), KEY oeuvre_id | social |
| `notes` | `user_id`, `oeuvre_id`, `note` TINYINT, `updated_at` ; PK (user_id, oeuvre_id) | social |
| `progression` | `user_id`, `oeuvre_id`, `tome_id`, `chapitre_id`, `paragraphe` INT, `pourcentage` TINYINT, `updated_at` ; PK (user_id, oeuvre_id) | lecture |
| `planning_journal` | `id` BIGINT AI, `tome_id`, `user_id`, `champ` VARCHAR(40), `ancien` TEXT, `nouveau` TEXT, `public` TINYINT, `created_at` ; KEY tome_id, KEY created_at | planning |
| `notifications` | `id` AI, `destinataire` VARCHAR(190), `user_id`, `sujet` VARCHAR(255), `html` LONGTEXT, `contexte` VARCHAR(60), `statut` VARCHAR(10) DEFAULT 'attente', `tentatives` TINYINT, `created_at`, `envoye_le` ; KEY statut | planning |
| `glossaire` | `id` AI, `oeuvre_id`, `categorie` VARCHAR(40), `ordre` INT, `nom` VARCHAR(255) (nom français, sinon premier terme source ; anglicismes : `fr`), `termes_source` TEXT (un par ligne), `recherche` TEXT (nom, variantes, termes source, description : minuscules sans accents), `donnees` LONGTEXT (JSON de l'entrée normalisée, `public` compris) ; KEY (oeuvre_id, categorie) | glossaire |
| `listes` | `id` AI, `user_id`, `nom` VARCHAR(80), `slug` VARCHAR(100), `jeton` VARCHAR(16) (jeton public aléatoire : une lettre puis 15 caractères [a-z0-9]), `description` VARCHAR(300), `publique` TINYINT, `systeme` VARCHAR(20) (`a_lire`, `en_cours`, `termine` ou ''), `cree_le`, `maj_le` ; KEY user_id, KEY jeton | social (listes de lecture, PAGE-07) |
| `listes_oeuvres` | `liste_id`, `oeuvre_id`, `ajoute_le`, `ordre` INT ; PK (liste_id, oeuvre_id), KEY oeuvre_id | social |
| `notifications_lecteur` | `id` AI, `user_id`, `type` VARCHAR(20) (`sortie`, `reponse`), `objet_id` (tome, chapitre ou commentaire), `titre` VARCHAR(255) (texte brut), `url` VARCHAR(500), `cree_le`, `lu_le` (NULL = non lue) ; KEY (user_id, lu_le), KEY cree_le | social (centre de notifications, AMEL-11) |
| `push` | `id` AI, `user_id`, `endpoint` VARCHAR(1000), `empreinte` CHAR(64) (SHA-256 de l'endpoint, UNIQUE), `session` CHAR(64) (SHA-256 du jeton de la session WordPress qui a créé l'abonnement), `p256dh` VARCHAR(200), `auth` VARCHAR(100), `cree_le`, `dernier_envoi` (NULL possible), `echecs` SMALLINT ; KEY user_id | social (Web Push, AMEL-06) |
| `stats_jour` | `jour` DATE, `oeuvre_id` BIGINT DEFAULT 0 (0 = ligne « site »), `vues` INT NULL, `visiteurs` INT NULL (NULL = trafic indisponible ce jour ; `visiteurs` toujours NULL sur une ligne d'œuvre), `favoris`, `suiveurs`, `listes`, `lecteurs`, `lecteurs_30j`, `chapitres_lus`, `comptes`, `inscriptions` (INT, 0 par défaut ; les deux derniers sur la ligne site seulement) ; PK (jour, oeuvre_id), KEY oeuvre_id ; aucune donnée personnelle ; écrite par `$wpdb->replace()` | statistiques (historique quotidien, §8 ter) |
| `glossaire_versions` | `id` AI, `oeuvre_id`, `user_id`, `source` VARCHAR(20) (`api`, `televersement`, `restauration` ; `brouillon` : glossaire vérifié non publié, hors historique et rétention), `cree_le` DATETIME (GMT), `sha256` CHAR(64), `nb_entrees` INT (hors anglicismes), `yaml` LONGTEXT, `note` VARCHAR(255) ; KEY oeuvre_id ; 5 dernières par œuvre | glossaire |
| `coquilles` | `id` BIGINT AI, `chapitre_id` BIGINT, `tome_id` BIGINT DEFAULT 0, `oeuvre_id` BIGINT DEFAULT 0, `user_id` BIGINT DEFAULT 0 (0 : anonyme ou compte supprimé), `paragraphe` INT UNSIGNED DEFAULT 0, `extrait` VARCHAR(300), `contexte` TEXT, `empreinte` CHAR(32), `commentaire` VARCHAR(500), `statut` VARCHAR(10) DEFAULT 'nouveau' (`nouveau`, `corrige`, `rejete`), `ip_hash` CHAR(64) DEFAULT '', `cree_le` DATETIME, `traite_le` DATETIME NULL, `traite_par` BIGINT DEFAULT 0 ; KEY chapitre_statut (chapitre_id, statut), KEY oeuvre_statut (oeuvre_id, statut), KEY user_id, KEY cree_le ; option de schéma `yume_corrections_schema` (`'1'`) | corrections (L1, 2.5.0, §8 quater) |

`planning_journal.champ` contient aussi des événements (`creation`, `publie`, `depublie`,
`chapitre_publie`, `retire`, `etape_forcee`, `rappel`, `signalement`, `digest` ; `tome_id` 0 pour le
digest). `publie` porte `{chapitres, total?, partiel?|retour?|complet?}` (sortie partielle, retour en
ligne, dernier chapitre). Jamais publics : `note_equipe`, `etape_forcee`, `signalement`, `digest`. `notifications.statut` ∈ `attente`,
`envoi` (transitoire), `envoye`, `echec`.

Méta utilisateur : `yume_reglages` (`{size, lh, font, width, bgAlpha, theme}`) et `yume_alertes`
(`{sorties, hebdo, commentaires}` booléens) ; `_yume_jeton_desabonnement` (jeton secret des liens de
désabonnement, effacé avec les données du membre). Méta internes : `_yume_alerte_envoyee` (tome ou chapitre
notifié aux lecteurs), `_yume_migration_cle`, `_yume_source_id`, `_yume_migration_run` ; options
`yume_redirections`, `yume_migration_*` ; actions `yume_migration_terminee`, `yume_migration_annulee`.
Métas utilisateur du chantier « lecteurs » (2.5.0) : `_yume_compte_sans_mdp` (compte sans mot de passe
connu de son titulaire, `Social\compte_sans_mot_de_passe()`, §7), `yume_discord_id`,
`yume_discord_pseudo`, `yume_discord_lie_le` (L2, §8 quinquies), `yume_avis_pseudo_vu` (L6 : avis
« choisissez un pseudo différent de votre identifiant » fermé). Toutes couvertes par la désinstallation
(`LIKE 'yume_%'` / `'_yume_%'`). Les lots L2, L3 et L5 n'ont pas de table (métas, transients et options
de version).

Méta utilisateur du profil public (`includes/social/profil-public.php`, PAGE-04, membres de l'équipe
seulement, rubrique « Profil public » de la page compte, formulaire `admin-post.php?action=yume_compte_profil_public`
avec nonce) : `yume_profil_public` (booléen, **faux par défaut** : consentement explicite ; décoché =
profil retiré immédiatement et `yume_profil_slug` effacé), `yume_profil_bio` (texte brut, 300 caractères),
`yume_profil_liens` (`{discord: pseudo 2-37 car., x: https://x.com/{pseudo} (depuis @pseudo ou une
adresse x.com/twitter.com), site: URL http(s)}` ; une saisie invalide est ignorée), `yume_profil_arrivee`
(« AAAA-MM », facultatif), `yume_profil_slug` (adresse publique, suit le pseudo : `profile_update`). Un
pseudo identique à l'identifiant de connexion empêche l'activation. Exporteur de données personnelles
WordPress `yume-profil-public`.

Désabonnement des e-mails d'alerte (`includes/social/desabonnement.php`) : chaque e-mail porte un lien
signé `?yn-desabo={user_id}&yn-portee=oeuvre|commentaires|tout&yn-oeuvre={id}&yn-sig=…` (HMAC du membre,
de la portée, de l'œuvre et du jeton ; changer le jeton invalide les anciens liens). GET : confirmation
seulement (page compte) ; POST du bouton : appliqué ; POST « One-Click » RFC 8058 : appliqué
directement. En-têtes `List-Unsubscribe` et `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
ajoutés sur `wp_mail`. Action `yume_desabonnement( int $user_id, string $portee, int $oeuvre_id )`.

Statistiques de lecture (PAGE-06, `includes/reader/statistiques.php`, rubrique « Mes statistiques »
`#yn-stats` de la page compte) : `Reader\statistiques_lecture( int $user_id ): array` (`tomes_termines`,
`chapitres_lus`, `minutes`, `series_en_cours`, `series_a_jour`, `series[]`) déduit de la table
`progression` (une position par œuvre) : les chapitres publiés qui précèdent la position dans l'ordre de
lecture sont lus, le chapitre courant aussi à 90 % (`SEUIL_CHAPITRE_LU`) ; minutes = `yume_temps_lecture`,
sinon `yume_nb_mots` / 230 ; « À jour » = tous les chapitres publiés lus. Deux requêtes agrégées
(`plan_de_lecture()` : tomes puis chapitres publiés de toutes les œuvres, méta jointes), membre connecté seulement.

Listes, notifications et Web Push (lot P3-D, `includes/social/listes.php`, `notifications-lecteur.php`,
`push.php`) : schéma distinct (option `yume_social_schema_lecteur` = `VERSION_SCHEMA_LECTEUR`, créé par
`installer_tables_lecteur()` depuis `installer_tables()` et `verifier_schema()`). Version 2 : colonnes
`listes.jeton` et `push.session` ; `migrer_schema_lecteur()` attribue un jeton aux listes existantes et
supprime les abonnements push sans session (le navigateur se réabonne depuis le compte).

- **Listes (PAGE-07)** : 20 listes personnelles et 500 œuvres par liste au plus ; les listes système
  s'excluent. Remplissage automatique (méta utilisateur `yume_listes_auto` = `'0'` pour le couper,
  filtre `yume_listes_auto( bool, $user_id, $oeuvre_id )`) sur `yume_progression_enregistree` : « En
  cours » dès la première position, « Terminé » quand le dernier chapitre publié est lu à 90 % ; une
  œuvre « Terminé » n'en sort que pour un chapitre publié après son classement. Action
  `yume_liste_oeuvre_ajoutee( int $oeuvre_id, array $liste )`. Page publique `/listes/{jeton}-{slug}/`
  (jeton aléatoire de la colonne `jeton`, créé avec la liste et régénéré quand elle repasse de privée à
  publique : les listes ne sont pas énumérables ; variable de requête `yume_liste` = jeton, ou
  `?yume_liste={jeton}` sans permaliens ; règle vidée une fois par `verifier_regles_listes()` avec
  l'option `yume_listes_regles`, adresse non canonique → 301 ; l'ancienne forme numérique
  `/listes/{id}-{slug}/` est reconnue pour répondre 404) : liste privée, inconnue ou d'un compte
  supprimé → 404 avec `nocache_headers()` (sauf pour son propriétaire) ; passage en privé ou
  suppression d'une liste publique → `batcache_clear_url()` de son adresse si la fonction existe
  (`purger_cache_url()`, aussi utilisée au retrait du consentement d'un profil public et au changement
  de pseudo : profil et `/contributeurs/`) ; gabarit : modèle de thème `yume-liste` s'il existe, sinon en-tête +
  `yume/liste-publique` + pied (filtre `yume_liste_gabarit`) ; `noindex, follow` par défaut (filtre
  `yume_listes_indexables`). Rubrique « Mes listes » `#yn-listes` du compte (formulaires `admin-post`
  `yume_liste_creer|modifier|supprimer|retirer`, `yume_listes_auto`, nonce `yume_listes`).
- **Centre de notifications (AMEL-11)** : alimenté par `yume_tome_publie` / `yume_chapitre_publie`
  (priorité 21 : membres dont l'œuvre est en favori hors alerte « jamais », titre = objet de l'e-mail
  d'alerte, verrou `_yume_notif_lecteur`, retirées si le contenu est dépublié) et par les réponses
  approuvées à un commentaire d'un membre (`wp_insert_comment` et `transition_comment_status`, verrou
  de commentaire `_yume_notif_reponse`). Action `yume_notifications_creees( int[] $user_ids, string $type,
  int $objet_id )`. Purge quotidienne (`yume_notifications_lecteur_purge`) au-delà de 90 jours (filtre
  `yume_notifications_duree`). Rubrique « Notifications » `#yn-notifications` du compte
  (`admin-post.php?action=yume_notifications_lues`).
- **Web Push (AMEL-06)** : option `yume_vapid` (non chargée automatiquement : `public` base64url,
  `prive` PEM — jamais exposée —, `cree_le`), générée par OpenSSL (`prime256v1`) à la première
  demande. Jeton VAPID JWT ES256 (`aud` = origine du service push, `exp` + 12 h, `sub` = `mailto:` de
  l'administration, filtre `yume_push_sujet`) signé par `openssl_sign` (DER → R||S). Push **sans charge
  utile** : `wp_safe_remote_post` avec `Authorization: vapid t=…, k=…`, `TTL: 86400`, `Urgency:
  normal`, corps vide. Envoi en tâche cron `yume_push_envoyer` (planifiée 10 s après
  `yume_notifications_creees` si l'un des membres a un abonnement ; lots de 50, lot suivant planifié s'il
  en reste) : un abonnement reçoit un push si son membre a une notification non lue plus récente que
  `dernier_envoi`. 404/410 → abonnement supprimé ; autre échec → `echecs` + 1, suppression à 5. Hôtes
  acceptés (https, port 443) : `fcm.googleapis.com`, `updates.push.services.mozilla.com`,
  `*.notify.windows.com`, `web.push.apple.com`. 10 appareils par membre au plus. Désactivation :
  réglage `notifications_navigateur` ou filtre `yume_push_actif`.
  **Révocation** : chaque abonnement porte l'empreinte (`hash( 'sha256', wp_get_session_token() )`,
  colonne `session`) de la session qui l'a créé ; sans session WordPress (mot de passe d'application),
  `POST /moi/push` répond 403 `yume_push_session`. `authentifier_push()` et l'envoi exigent que cette
  session soit encore active (`session_push_valide()` : clé présente et non expirée dans la méta
  `session_tokens` du stockage par défaut `WP_User_Meta_Session_Tokens` ; autre stockage via
  `session_token_manager` : filtre `yume_push_session_valide( ?bool $valide, string $session, int
  $user_id )`, sans réponse la session est tenue pour invalide) ; sinon l'abonnement est supprimé.
  Suppression : `wp_logout` (abonnements de la session qui se déconnecte), `updated_user_meta` /
  `deleted_user_meta` de `session_tokens` (session détruite ; toutes les sessions détruites → tous les
  abonnements du membre, ou de tous les membres pour `destroy_all_for_all_users()`),
  `after_password_reset`, `wp_set_password`, `profile_update` avec changement de mot de passe.
  Côté navigateur, un clic sur un lien de déconnexion (`action=logout`) résilie aussi l'abonnement
  (`pushManager.getSubscription()` puis `unsubscribe()`, 800 ms d'attente au plus).

Listes, notifications et abonnements sont dans l'export RGPD (`/moi/export` : clés `listes`,
`notifications`, `notifications_push` sans le secret `auth` ; exporteur WordPress, groupe `yume-listes`)
et effacés avec les données du membre ou son compte (`deleted_user`).

`dbDelta` : deux espaces après `PRIMARY KEY`, une colonne par ligne. Le SQL doit fonctionner
sous MySQL/MariaDB (production) **et** sous l'intégration SQLite (développement local). Les tests tournent sur les deux moteurs
(`YUME_DB_ENGINE=mysql` en local pour MariaDB).

## 14. Stockage navigateur (clés `localStorage`)

| Clé | Contenu | Propriétaire |
| --- | --- | --- |
| `yn.theme` | `nuit` \| `papier` \| `sepia` | thème (bascule d'en-tête) et lecture (panneau) |
| `yn.reglages` | `{size, lh, font, width, bgAlpha}` | lecture |
| `yn.progression` | `{ [oeuvre_id]: {chapitre_id, tome_id, paragraphe, pourcentage, url, titre, updated_at} }` | lecture (écrit), lecteurs (bloc reprise) |
| `yn.a11y` | `{contraste: bool, animations: 'systeme'\|'reduites'}` (appareil seulement, jamais envoyé au compte) | lecture (panneau Paramètres, script d'initialisation) |
| `yn.manga` | `{mode: 'defilement'\|'page', qualite: 'data-saver'\|'data'}` (appareil seulement ; défaut défilement, économie de données) | lecteur des chapitres MangaDex (`includes/mangadex/assets/manga.js`, §8 bis) |
| `yn.wordend` | `{meilleur: int, parties: int, maj: 'YYYY-MM-DD', volume: 0…1, muet: bool}` (meilleur score et réglages du son du jeu caché, appareil seulement) | wordend |

Stockage Cache Storage du service worker (lecture hors ligne) : caches `yume-lecture-{version}`,
`yume-statique-{version}`, `yume-secours-{version}` (version = `YUME_CORE_VERSION` ; ceux des versions
précédentes sont supprimés à l'activation).

API du thème pour les autres scripts : `window.ynTheme.set( 'nuit'|'papier'|'sepia' )` si elle existe
(sinon poser l'attribut et `localStorage['yn.theme']`) ; événement `document` `yn:theme`
(`detail.theme`) à chaque changement ; classe `html.yn-js` quand JavaScript est actif.

Easter egg WordEnd (module wordend, détails dans `docs/wordend.md`) : script `yume-wordend-declencheur`
en façade (jamais en administration, flux ni embed) ; configuration `window.ynWordEnd` (URLs `jeu`,
`style`, `planche`, `meta`, `timere`, `timereMeta`, `decor`, `musique`, identique pour tous les visiteurs) et API `window.ynWordEnd.ouvrir()` ; le
jeu, chargé à la demande, expose `window.ynWordEndJeu` ; filtres `yume_wordend_actif` (bool) et
`yume_wordend_oeuvres` (slugs dont la fiche affiche le papillon, défaut `sukasuka`) ; classe racine
`.yn-wordend` ; événement `document` `yn:wordend` (`detail.etat` = `ouvert`\|`ferme`).

La page Illustrations d'un tome n'écrit jamais `yn.progression` ni `PUT /moi/progression` (pas de
chapitre ; la REST refuse un ID de tome) : elle ne compte pas dans les pourcentages et ne remplace pas une
position plus avancée. Le script `yume-debut-lecture` (bibliothèque) lit seulement `yn.progression`.

Ancre de reprise : `#yn-p-N` (paragraphe numéroté à partir de 1) dans l'URL d'un chapitre ; le lecteur y
défile directement. Elle est produite par la REST, la page compte, `yume/oeuvre-actions`,
`yume/resume-reading` et les lignes de tome.

Options d'accessibilité du lecteur (AMEL-08), posées avant le premier rendu par le script d'initialisation
d'après `yn.a11y` : `html[data-yn-contraste="renforce"]` (le bloc `yume/reader-tools` redéfinit alors les
couleurs `--wp--preset--color--*` : texte blanc/noir, filets appuyés, colonne opaque, liens soulignés) et
`html[data-yn-animations="reduites"]` (transitions et animations coupées, défilement non animé ; la
préférence système `prefers-reduced-motion` s'applique toujours et verrouille la case). Police adaptée à la
dyslexie : slug `opendyslexic` de `polices()` (13ᵉ police, OpenDyslexic OFL auto-hébergée dans
`includes/reader/assets/polices/opendyslexic/`, `@font-face` du bloc ; repli Atkinson Hyperlegible puis
Verdana), enregistrée comme toute police dans `yn.reglages` et `yume_reglages`. Raccourcis clavier du
lecteur, listés dans le panneau : ← / → chapitre précédent / suivant, S paramètres, Échap fermer ; inactifs
dans un champ de saisie (`input`, `textarea`, `select`, `contenteditable`, rôles `textbox`/`combobox`/`slider`)
et panneau ouvert.

**Lecture hors ligne (AMEL-07, `includes/reader/pwa.php`)** : adresses servies à la racine du site par
paramètre (aucune règle de réécriture) sur `init` (priorité 99), GET seulement :
`/?yume_manifest=1` (manifeste `application/manifest+json` : `name` « Yume Novel », `display`
`standalone`, `start_url`/`scope` = racine, couleurs `fond`/`bande` de `theme.json`, icônes de l'icône du
site 192/512 sinon `includes/reader/assets/icone.svg` ; filtre `yume_pwa_manifeste`),
`/?yume_sw=1` (service worker : `self.YUME_SW_CONFIG` puis `includes/reader/assets/sw.js` ; en-têtes
`Service-Worker-Allowed: /` (racine du site), `Cache-Control: no-cache, no-store`, `batcache_cancel()` si
présent) et `/?yume_hors_ligne=1` (page de repli autonome, `noindex`, liste des chapitres en cache).
`<link rel="manifest">` dans `<head>` de toutes les pages publiques ; script `yume-pwa` en pied de page
(pas sur l'administration ni sur les pages exclues) qui enregistre le service worker (portée racine) et,
sur une page de lecture, lui envoie `{type:'yume-memoriser', pages:[page, link[rel=next]], ressources}` ;
`html[data-yn-hors-ligne="pret"]` quand c'est fait. Stratégie : pages `/lire/…` sans paramètre en réseau
d'abord, cache en repli, 30 pages au plus (LRU, filtre `yume_pwa_max_chapitres`) ; ressources du thème,
du plugin et de `wp-includes` en cache d'abord (120 au plus) ; autres navigations en réseau seul avec page
de repli. Jamais interceptés : `wp-admin`, `wp-login.php`, REST (`/wp-json/`, `?rest_route=`),
`admin-ajax.php`, requêtes autres que GET ; jamais mis en cache : pages `equipe`, `publier`, `membres`,
`compte`, `connexion` (filtre `yume_pwa_exclus`). **Données personnelles** : seul le HTML public est
gardé — le serveur n'écrit `<meta name="yume-hors-ligne" content="lecture">` que sur une page de lecture
d'un visiteur non connecté, et le service worker n'enregistre qu'une réponse qui le porte ; pour un membre,
il télécharge une copie anonyme (`credentials: 'omit'`). Aucun nonce, pseudo ni position n'entre donc
dans le cache et rien n'est à vider à la déconnexion. Désactivation : réglage `pwa_hors_ligne` (§6) ou
filtre `yume_pwa_actif` (bool) ; `/?yume_sw=1` sert alors un service worker qui vide les caches `yume-*`
et se désinscrit, et les pages désinscrivent l'ancien.

**Notifications navigateur dans le service worker (AMEL-06)** : `sw.js` gère aussi `push` et
`notificationclick`. `self.YUME_SW_CONFIG` porte `horsLigneActif`, `notifications` (URL de
`GET /yume/v1/moi/notifications?non_lues=1&limite=1`), `site` et `icone`. À un push (sans charge utile),
le service worker demande cette URL (`credentials: 'include'`, `cache: 'no-store'`, en-têtes
`X-Yume-Push-Endpoint` et `X-Yume-Push-Auth` tirés de `pushManager.getSubscription()`), affiche la
notification (titre = nom du site, corps = titre de la notification, `tag` `yume-{id}` ; texte générique
si la requête échoue) ; un clic ouvre son adresse si elle est de même origine, sinon l'accueil. Rien n'est
mis en cache. Lecture hors ligne désactivée mais notifications actives : `/?yume_sw=1` sert le même
fichier avec `horsLigneActif: false` (aucune interception, caches `yume-*` vidés) et les pages ne
désinscrivent plus le service worker. L'abonnement est demandé depuis la rubrique « Notifications » du
compte (`blocks/auth-links/view.js`, attribut `data-yn-push` : clé, URL et portée du service worker,
empreintes des abonnements du membre).

Attribut `html[data-yn-theme]` = `nuit` (défaut) \| `papier` \| `sepia`, posé avant le premier rendu
par un script en ligne du thème (pas de flash). Variables du lecteur, posées sur `.yn-reader` :
`--yn-size` (px), `--yn-lh`, `--yn-font`, `--yn-width` (ch), `--yn-bg-alpha`.

## 15. Design tokens (thème, `theme.json`)

Couleurs (slug → CSS `var(--wp--preset--color--<slug>)`), valeurs du thème Nuit ; les thèmes
`papier` et `sepia` redéfinissent ces variables sous `html[data-yn-theme="…"]` :

`fond` #1b1231 · `bande` #241740 · `carte` #2d1f4f · `filet` #4a3b6e · `bordure` #7f6aa8 ·
`texte-fort` #fff8fb · `texte` #ebe3f2 · `texte-faible` #b7a9cc · `accent` #f3a6c8 ·
`accent-texte` #2a1240 · `accent-2` #f7c59f · `selection` #3a2a63 · `succes` #8fd6a3 ·
`avertissement` #f4c069 · `erreur` #ff8f7e.
Papier : `fond` #fdf8fa · `bande` #f6eef3 · `carte` #ffffff · `filet` #e8d9e2 · `bordure` #8a6f9e ·
`texte-fort` #2a1240 · `texte` #2a1240 · `texte-faible` #5e4a73 · `accent` #b23a71 · `accent-texte` #ffffff ·
`accent-2` #9d5524 · `selection` #f3e6ee · `succes` #2c7457 · `avertissement` #8f5a1b · `erreur` #a63328
(valeurs assombries pour tenir 4,5:1).
Sépia : comme Papier avec `fond` #f4ead9, `bande` #efe2cc, `carte` #fbf4e6, `filet` #e2d3b8, `texte` et `texte-fort` #3a2233, `texte-faible` #6b5443, `accent` #a8356a, `accent-2` #93501f, `succes` #286c51, `avertissement` #85541a.
Variables de thème supplémentaires : `--yn-accent-survol`, `--yn-degrade-couverture`, `--yn-voile-image`.
Feuilles : `themes/yume/assets/css/yume.css` et `assets/css/reader.css` (chargées en façade et dans l'éditeur).

Familles (slug → `var(--wp--preset--font-family--<slug>)`) : `titres` Outfit · `corps` Nunito Sans ·
`mono` IBM Plex Mono · `lecture` Literata. Polices **auto-hébergées** dans le thème (woff2).
Espacements : `--wp--preset--spacing--10…60` = 4, 8, 12, 16, 24, 32 px (slugs 10,20,30,40,50,60).
Rayons : variables `--yn-radius` 6px, `--yn-radius-card` 10px (déclarées par le thème).
Classes utilitaires partagées définies par le **thème** et utilisables par les blocs :
`.yn-btn`, `.yn-btn--primary`, `.yn-btn--sm`, `.yn-chip`, `.yn-chip--ok|--warn|--err|--info|--new`,
`.yn-card`, `.yn-cover` (couverture 2/3 avec dégradé de substitution), `.yn-label` (surtitre mono),
`.yn-muted`, `.yn-grid-covers`, `.yn-visually-hidden`, `.yn-bar` (barre de progression, `<span style="--v:62%">`).
Les blocs peuvent embarquer leur propre CSS **structurelle** (grille, disposition) dans leur
`style.css`, en n'utilisant que ces variables et classes pour les couleurs.

## 16. Tests et environnement local

- `tools/localenv/wp.sh` et `tools/localenv/test.sh` : WP-CLI et tests sur le WordPress local
  (`YUME_WP_PATH`, `YUME_ENV` = base SQLite isolée). Chaque module écrit `tests/test-<module>.php`
  avec `yume_test()` et les assertions de `tests/helpers.php` ; chaque test tourne dans une
  transaction annulée.
- Variable `YUME_ONLY_MODULES=core,planning` (local uniquement) pour ne charger que certains modules.
- `php -l` sur chaque fichier modifié ; aucune notice PHP dans `wp-content/debug.log` pendant les tests.
