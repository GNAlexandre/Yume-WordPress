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

**Rien n'est poussé sur le site yumenovel.fr sans le « Go » explicite de l'équipe.** Tout le travail
se fait et se vérifie sur GitHub (branche de travail, puis revue). Concrètement : aucune écriture
sur le site via le connecteur WordPress.com (lecture seule autorisée pour l'analyse), aucun tag
`v*` ni release GitHub (une release déclencherait la mise à jour automatique du plugin une fois
installé), aucun téléversement de zip. La bascule du §8 de `02-plan-refonte.md` n'a lieu qu'après le Go.

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
| `wp-content/themes/yume/` | **thème** |

Chaque module range ses fichiers statiques dans `includes/<module>/assets/` et ses blocs dans
`includes/<module>/blocks/<nom>/`.

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
caches en lecture seule pour l'API : `yume_note_moyenne` (number) · `yume_nb_notes` (integer) ·
`yume_nb_favoris` (integer) · `yume_derniere_sortie` (string, date `Y-m-d H:i:s` GMT du dernier tome/chapitre publié).

**`yume_tome`** : `yume_oeuvre_id` (integer, obligatoire) · `yume_numero` (number, ex. 9, 26.5) ·
`yume_nature` (string enum `tome`,`arc`,`ex`,`bonus`,`chapitres`) · `yume_lien_pdf` (string url) ·
`yume_lien_epub` (string url) · `yume_equivalence` (string) · `yume_illustrations` (array<integer>) ·
`yume_credits` (object{traduction,relecture,edition}) · **planning** : `yume_etape` (string enum
`a_faire`,`traduction`,`relecture`,`edition`,`publie`) · `yume_avancement`
(object{traduction:int 0-100, relecture:int, edition:int}) · `yume_responsables`
(object{traduction:int user_id, relecture:int, edition:int}) · `yume_date_cible` (string `Y-m-d`) ·
`yume_bloque` (boolean) · `yume_bloque_raison` (string) · `yume_derniere_maj` (string `Y-m-d H:i:s` GMT) ·
`yume_maj_par` (integer) · `yume_note_equipe` (string, **jamais exposé publiquement** :
`auth_callback` = capacité `yume_maj_planning`) · `yume_nb_chapitres` (integer, cache).

**`yume_chapitre`** : `yume_tome_id` (integer) · `yume_oeuvre_id` (integer, dénormalisé) ·
`yume_numero` (number ; 0 pour prologue) · `yume_sous_titre` (string) · `yume_nature` (string enum
`chapitre`,`prologue`,`interlude`,`epilogue`,`postface`,`bonus`,`illustrations`) ·
`yume_credits` (object{traduction,relecture,edition}) · `yume_nb_mots` (integer) ·
`yume_temps_lecture` (integer, minutes, 230 mots/min) · `yume_source` (object{format:string,hash:string,importe_le:string}) — `format` ∈ `docx`, `epub`, `migration`.

Les clés `yume_*` sont protégées (absentes de la boîte « Champs personnalisés »). Les caches
(`yume_note_moyenne`, `yume_nb_notes`, `yume_nb_favoris`, `yume_derniere_sortie`, `yume_nb_chapitres`)
sont en lecture seule via `/wp/v2` : les modules les écrivent avec `update_post_meta`.

## 5. Rôles et capacités (module core, à l'installation)

Capacités propres : `yume_maj_planning` (ses tomes), `yume_maj_planning_tous`, `yume_publier`,
`yume_gerer_equipe`, `yume_reglages`, `yume_voir_equipe` (accès à /equipe/).
Capacités de types : `edit_yume_oeuvres`, `edit_others_yume_oeuvres`, `publish_yume_oeuvres`,
`delete_yume_oeuvres`, … (idem `yume_tomes`, `yume_chapitres`).

| Rôle | Nom affiché | Capacités |
| --- | --- | --- |
| `subscriber` | Lecteur (renommé) | `read` |
| `yume_traducteur`, `yume_relecteur`, `yume_graphiste` | Traducteur, Relecteur, Graphiste | `read`, `upload_files`, `yume_voir_equipe`, `yume_maj_planning`, `edit_yume_tomes` |
| `yume_editeur` | Éditeur Yume | les précédentes + `yume_publier`, `yume_maj_planning_tous`, toutes les capacités des 3 types (y compris others/publish/delete), `edit_posts`, `publish_posts`, `edit_published_posts`, `moderate_comments`, `manage_categories` |
| `yume_gerant` | Gérant | `yume_editeur` + `yume_gerer_equipe`, `yume_reglages`, `edit_others_posts`, `delete_posts`, `delete_published_posts`, `delete_others_posts`, `list_users`, `create_users`, `edit_users`, `promote_users` (limitées, voir ci-dessous) |
| `administrator` | — | tout, y compris toutes les capacités `yume_*` |

Gestion des membres par un gérant (tout compte sans `manage_options`) : `create_users`, `edit_users`
et `promote_users` ne portent que sur le Lecteur et les rôles de l'équipe (`roles_gerables()` :
`subscriber`, `yume_traducteur`, `yume_relecteur`, `yume_graphiste`, `yume_editeur`). Le filtre
`editable_roles` limite les rôles attribuables à cette liste (user-new.php, user-edit.php, users.php,
REST `/wp/v2/users`) ; `map_meta_cap` refuse `edit_user`, `promote_user`, `remove_user` et
`delete_user` sur tout compte ayant un autre rôle (administrateurs, autres gérants, rôles WordPress
éditoriaux) ou super administrateur, et `promote_user` sur son propre compte. En façade, la page
« Membres et rôles » (`yume/team-members`, capacité `yume_gerer_equipe`) applique les mêmes règles,
y compris pour un administrateur : rôles attribuables = rôles de l'équipe de `roles_gerables()`, retrait
= retour à `subscriber`, contrôle `current_user_can( 'promote_user', $id )` à chaque action.

Fonction utilitaire : `yume_user_can_edit_planning( int $tome_id, int $user_id = 0 ): bool`
(vrai si `yume_maj_planning_tous`, ou `yume_maj_planning` et l'utilisateur est un des responsables).

## 6. Réglages (module core)

Option unique `yume_reglages` (tableau), page *Yume → Réglages* (capacité `yume_reglages`),
lecture via `yume_setting( string $key, $default = null )`. Clés et défauts :

| Clé | Défaut | Utilisée par |
| --- | --- | --- |
| `discord_webhook_sorties` | `''` | planning (annonce des sorties) |
| `discord_webhook_equipe` | `''` | planning (rappels) |
| `rappel_jours_sans_maj` | `14` | planning |
| `rappel_heure` | `9` | planning (heure locale Europe/Paris) |
| `digest_jour` | `1` (lundi, 0 = dimanche) | planning |
| `emails_lecteurs` | `true` | social |
| `banniere_id` | `0` | bibliothèque (bloc bannière), thème |
| `kofi_url` | `https://ko-fi.com/ynovel` | thème |
| `discord_invite` | `https://discord.gg/tuMB3rmmWB` | thème |
| `twitter_url` | `https://x.com/Roshidere_FR` | thème |
| `jours_sortie` | `['mercredi','samedi','dimanche']` | planning |
| `modele_annonce` | `Le {nature} {numero} de {oeuvre} est disponible !` | publication |
| `github_repo` | `GNAlexandre/Yume-WordPress` | updater |
| `maj_auto` | `true` | updater |

`yume_setting( $key, $default )` : un `$default` explicite l'emporte quand la clé n'est pas enregistrée.
Les modules peuvent ajouter des champs à la page via le filtre
`yume_reglages_champs` (types en plus : `checkboxes` ; clés facultatives `default`, `min`, `max`,
`step`, `placeholder`, `sanitize`) et des sections via `yume_reglages_sections` (tableau de `array( 'key', 'label', 'type' => text|url|number|checkbox|select|media|textarea, 'section', 'options', 'description' )`).

## 6 bis. Administration

Menu de premier niveau **Yume** (slug `yume`, icône `dashicons-book-alt`, capacité `edit_yume_tomes`)
créé par **core**. Les types `yume_oeuvre`, `yume_tome`, `yume_chapitre` y apparaissent
(`show_in_menu => 'yume'`). Sous-menus : *Réglages* (core, `yume-reglages`), *Migrer* (migration,
`yume-migrer`, capacité `manage_options`), *Publier un tome* (publication, lien vers la page
`/equipe/publier/`). Les autres modules ajoutent leurs sous-pages avec `add_submenu_page( 'yume', … )`
sur `admin_menu` priorité ≥ 20.

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
yume_url_page( string $cle ): string;                                 // 'bibliotheque','planning','equipe','publier','membres','compte','connexion' → URL de la page (option yume_pages)
```

**planning** (`includes/planning/api.php`) :

```php
yume_planning_etat( int $tome_id ): string;         // 'publie'|'bloque'|'en_retard'|'a_lheure' (en_retard : date_cible < aujourd'hui, ou derniere_maj > rappel_jours_sans_maj jours)
yume_get_planning( array $args = array() ): array;  // lignes : ['tome_id','oeuvre_id','oeuvre','tome','etape','avancement','responsables'=>[etape=>['id','nom']], 'date_cible','etat','derniere_maj','url_oeuvre']
                                                    // args: 'oeuvre_id', 'type' (slug yume_type), 'etat', 'a_venir' (bool, exclut publie), 'limit', 'inclure_publies_depuis' (jours, défaut 14)
yume_journal_planning( int $tome_id, int $user_id, string $champ, $ancien, $nouveau ): void;
yume_queue_email( $destinataire, string $sujet, string $html, string $contexte = '' ): void; // user_id ou e-mail ; envoi par lot (cron), gabarit HTML Yume
yume_discord( string $canal, string $texte, array $embeds = array() ): bool;                 // canal 'sorties'|'equipe'
```

**social** (`includes/social/api.php`) :

```php
yume_get_progression( int $user_id, int $oeuvre_id = 0 ): array;   // oeuvre_id=0 : toutes, triées par updated_at desc ; ligne : ['oeuvre_id','chapitre_id','tome_id','paragraphe','pourcentage','updated_at']
yume_is_favori( int $user_id, int $oeuvre_id ): bool;
yume_get_abonnes( int $oeuvre_id, string $frequence = 'immediat' ): array; // user_id[]
yume_get_note( int $user_id, int $oeuvre_id ): int;                        // 0 si aucune
```

**import** : `Yume\Core\Import\Docx_Converter::convert_file( string $path, array $options = array() ): Yume\Core\Import\Result`
et `Yume\Core\Import\Epub_Converter::convert_file(...)` (même résultat) ; ces classes n'utilisent
**aucune** fonction WordPress (réutilisées par l'outil en ligne de commande). Voir §9.

## 8. Événements métier (actions)

| Action | Arguments | Émise par | Écoutée par |
| --- | --- | --- | --- |
| `yume_tome_publie` | `int $tome_id` | publication (et core sur `transition_post_status` d'un tome vers `publish`, une seule fois par tome : meta `_yume_publie_notifie`) | planning (étape `publie`, 100 %, journal, Discord), social (e-mails aux abonnés), core (cache `yume_derniere_sortie`) |
| `yume_chapitre_publie` | `int $chapitre_id` | core (`transition_post_status` d'un chapitre publié isolément, hors publication de tome) | social (abonnés), planning (Discord) |
| `yume_planning_mis_a_jour` | `int $tome_id, array $changements, int $user_id` | planning | — |
| `yume_publication_preparee` | `int $tome_id, array $rapport` | publication | — |

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
`warnings` (string[]), `stats` (array).

## 10. Blocs dynamiques (nom, propriétaire, attributs, classe racine)

Enregistrés avec `yume_register_dynamic_block()` ; catégorie `yume` ; `supports.html=false`,
`supports.align` si pertinent. Le rendu renvoie une chaîne vide (ou un message discret en éditeur)
quand le contexte manque. La classe racine est toujours présente pour que le thème et les tests
s'y accrochent. Contexte courant : `get_queried_object_id()` ou `$block->context['postId']`.

| Bloc | Module | Attributs | Racine | Contenu |
| --- | --- | --- | --- | --- |
| `yume/library-menu` | bibliothèque | — | `.yn-library-menu` | `<details class="yn-library-menu">` dont le `<summary>` porte aussi `wp-block-navigation-item__content` (placé dans `core/navigation`, enveloppé d'un `<li>`) ; panneau en absolu sous le summary au-dessus de 1360 px, en ligne dans le menu mobile (`.is-menu-open`) : types et statuts avec compteurs, « Toutes les œuvres A → Z », « Reprendre ma lecture » |
| `yume/banner` | bibliothèque | `height` (number, 240) | `.yn-banner` | Image `banniere_id` pleine largeur (bannière actuelle du site) |
| `yume/latest-releases` | bibliothèque | `count` (number, 6) | `.yn-releases` | Sans carte propre ; `.yn-grid-covers` + `.yn-cover`. Grille de couvertures des derniers tomes/chapitres publiés : badge « Nouveau » (< 7 j), titre, libellé, date, boutons Lire / PDF / EPUB |
| `yume/library-grid` | bibliothèque | `perPage` (24), `showFilters` (true) | `.yn-library` | Filtres (GET `type`, `statut`, `tri` = recent/az) + grille de couvertures avec badge de statut |
| `yume/oeuvre-header` | bibliothèque | — | `.yn-oeuvre-header` | Couverture, badges, titres alternatifs, fiche (auteur, illustrateur, éditeur VO, traduit), synopsis |
| `yume/oeuvre-infos` | bibliothèque | — | `.yn-oeuvre-infos` | Cartes « Équipe de traduction » (`yume_equipe`, `yume_source_traduction`) et « Liens » (`yume_liens`) de la maquette Oeuvre |
| `yume/tome-list` | bibliothèque | — | `.yn-tome-list` | Tomes publiés de l'œuvre (couverture, libellé, nb chapitres, date, Lire / PDF / EPUB) |
| `yume/tome-header` | bibliothèque | — | `.yn-tome-header` | Couverture, libellé, crédits, équivalence, boutons PDF / EPUB, galerie d'illustrations |
| `yume/tome-toc` | bibliothèque | — | `.yn-toc` | Sommaire du tome (chapitres + temps de lecture) |
| `yume/chapter-header` | bibliothèque | — | `.yn-chapter-header` | Fil d'Ariane, « Chapitre N », sous-titre, crédits, temps de lecture |
| `yume/chapter-nav` | bibliothèque | — | `.yn-chapter-nav` | Précédent · Sommaire · Suivant (liens `rel=prev/next`) |
| `yume/upcoming` | planning | `count` (3) | `.yn-upcoming` | Sans carte propre (le thème fournit la carte). Prochaines sorties compactes (date, œuvre, libellé, pastille d'état) |
| `yume/planning` | planning | `showFilters` (true) | `.yn-planning` | Tableau public du planning + légende + journal public récent |
| `yume/oeuvre-planning` | planning | — | `.yn-oeuvre-planning` | Carte « Planning de l'œuvre » (tome en cours, étapes, état) |
| `yume/team-dashboard` | planning | — | `.yn-team` | Espace équipe (connexion requise, capacité `yume_voir_equipe`) : Mes tâches, retards, rappels, journal |
| `yume/publish-form` | publication | — | `.yn-publish` | Formulaire de publication (capacité `yume_publier`) |
| `yume/team-members` | planning | — | `.yn-team` | Espace équipe, « Membres et rôles » (capacité `yume_gerer_equipe`) : membres et rôle, changer le rôle, ajouter un compte existant, retirer de l'équipe (envoi à `admin-post.php`, action `yume_equipe_membres`, nonce) |
| `yume/reader-tools` | lecture | — | `.yn-reader-tools` | Barre de lecture : progression, sommaire, marque-page, thème, panneau Paramètres |
| `yume/oeuvre-actions` | lecteurs | — | `.yn-oeuvre-actions` | Reprendre, Favori (compteur), Note (moyenne), Alerte |
| `yume/resume-reading` | lecteurs | `layout` (enum `bandeau`,`carte`) | `.yn-resume` | Reprendre la lecture (membre : serveur ; visiteur : `localStorage`). En `bandeau`, rend seulement son contenu (surtitre `.yn-label`, titre, bouton `.yn-btn--primary` « Continuer ») : le thème fournit le bandeau. Rien à reprendre : aucune sortie, ou `.yn-resume[hidden]` tant que le JS visiteur n'a rien trouvé |
| `yume/account` | lecteurs | — | `.yn-account` | Page compte : lecture en cours, favoris et alertes, notes, réglages, données (export/suppression) |
| `yume/auth-links` | lecteurs | — | `.yn-auth` | « Connexion » (la page connexion propose aussi l'inscription) ou « Mon compte » (+ « Espace équipe » si capacité) ; placé dans `core/navigation` |
| `yume/theme-toggle` | **thème** | — | `.yn-theme-toggle` | Bascule Nuit ↔ Papier (`aria-pressed`, `[data-yn-theme-toggle]`) |

Les blocs posés dans le modèle `page-large` (planning, compte, équipe, publication) commencent leurs
titres au `<h2>` : le modèle affiche déjà le titre de la page en `<h1>`. `yume/library-grid` accepte
les paramètres GET `type`, `statut` (`slug[,slug…]`), `genre`, `tri` (`recent` par défaut, `az`) et `pg`
(pagination) ; filtre `yume_bibliotheque_groupes_statuts`. `yume/latest-releases` affiche une carte par
tome, datée par ses chapitres pour un arc ou un web novel. Filtre `yume_bibliotheque_ligne_tome` : le
module lecteurs y ajoute la progression personnelle sur les lignes de `yume/tome-list`.

## 11. Pages créées par la migration (option `yume_pages` : clé → ID)

| Clé | Slug | Contenu |
| --- | --- | --- |
| `bibliotheque` | `bibliotheque` | `yume/library-grid` |
| `planning` | `planning` | `yume/planning` |
| `equipe` | `equipe` | `yume/team-dashboard` |
| `publier` | `equipe/publier` (page enfant) | `yume/publish-form` |
| `membres` | `equipe/membres` (page enfant) | `yume/team-members` |
| `compte` | `compte` | `yume/account` |
| `connexion` | `connexion` | formulaire de connexion/inscription rendu par `yume/account` quand déconnecté |
| `actualites` | `actualites` | page des articles (`page_for_posts`) |
| `mentions-legales` | `mentions-legales` | texte de base à compléter par l'équipe |
| `accueil` | `accueil` | page d'accueil statique (`page_on_front`), rendue par `front-page.html` |

Pages supplémentaires : `actualites` (slug `actualites`, page des articles) et `mentions-legales`
(visée par le pied de page). Réglages de lecture : `show_on_front = page`, `page_on_front` = une page
« Accueil » (le thème fournit `front-page.html`), `page_for_posts` = la page `actualites`.
Le format du plan de migration (`plan.json` v1) est décrit dans `tools/migrate/README.md` : c'est
l'interface entre l'analyse et l'exécution. Pendant l'exécution : `add_filter( 'yume_core_notifier',
'__return_false' )`, ne pas créer les termes `yume_oeuvre_liee` à la main (ils naissent avec les œuvres).

## 12. REST `yume/v1`

| Méthode et route | Module | Permission |
| --- | --- | --- |
| `GET /planning` | planning | public (champs publics uniquement) |
| `PATCH /tomes/(?P<id>\d+)/planning` | planning | `yume_user_can_edit_planning` |
| `GET /planning/journal` | planning | public (sans notes d'équipe) |
| `POST /publications/analyse` | publication | `yume_publier` — multipart `source` (DOCX/EPUB) → rapport sans rien créer |
| `POST /publications` | publication | `yume_publier` — crée le tome (brouillon) + chapitres (brouillons) |
| `POST /publications/(?P<id>\d+)/publier` | publication | `yume_publier` — `quand` = `maintenant` ou date ISO → publie/programme tome + chapitres |
| `GET /moi` | lecteurs | connecté |
| `GET, PUT /moi/reglages` | lecture | connecté |
| `GET, PUT /moi/progression` | lecture | connecté |
| `POST, DELETE /moi/favoris/(?P<oeuvre>\d+)` | lecteurs | connecté |
| `PUT /moi/notes/(?P<oeuvre>\d+)` | lecteurs | connecté ; `note` 1–5, 0 = retirer |
| `PUT /moi/alertes/(?P<oeuvre>\d+)` | lecteurs | connecté ; `frequence` immediat/hebdo/jamais |
| `GET /moi/export`, `DELETE /moi` | lecteurs | connecté (RGPD) ; `DELETE` exige `confirmation=SUPPRIMER` et `mot_de_passe`, refusé pour l'équipe et les administrateurs |
| `POST /planning/tomes` | planning | `yume_maj_planning_tous` — ajoute un tome brouillon au planning (œuvre, nature, numéro, titre, responsables, date cible) ; 409 si doublon |
| `GET /planning/journal?format=rss` | planning | public — flux RSS du journal |
| `GET /migration`, `POST /migration/executer`, `POST /migration/annuler` | migration | `manage_options` ; `confirmation=MIGRER` / `ANNULER`, exécution par lots, reprise (`ignorer`) |

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

`planning_journal.champ` contient aussi des événements (`creation`, `publie`, `chapitre_publie`,
`rappel`, `signalement`, `digest` ; `tome_id` 0 pour le digest). `notifications.statut` ∈ `attente`,
`envoi` (transitoire), `envoye`, `echec`.

Méta utilisateur : `yume_reglages` (`{size, lh, font, width, bgAlpha, theme}`) et `yume_alertes`
(`{sorties, hebdo, commentaires}` booléens). Méta internes : `_yume_alerte_envoyee` (tome ou chapitre
notifié aux lecteurs), `_yume_migration_cle`, `_yume_source_id`, `_yume_migration_run` ; options
`yume_redirections`, `yume_migration_*` ; actions `yume_migration_terminee`, `yume_migration_annulee`.

`dbDelta` : deux espaces après `PRIMARY KEY`, une colonne par ligne. Le SQL doit fonctionner
sous MySQL/MariaDB (production) **et** sous l'intégration SQLite (développement local). Les tests tournent sur les deux moteurs
(`YUME_DB_ENGINE=mysql` en local pour MariaDB).

## 14. Stockage navigateur (clés `localStorage`)

| Clé | Contenu | Propriétaire |
| --- | --- | --- |
| `yn.theme` | `nuit` \| `papier` \| `sepia` | thème (bascule d'en-tête) et lecture (panneau) |
| `yn.reglages` | `{size, lh, font, width, bgAlpha}` | lecture |
| `yn.progression` | `{ [oeuvre_id]: {chapitre_id, tome_id, paragraphe, pourcentage, url, titre, updated_at} }` | lecture (écrit), lecteurs (bloc reprise) |

API du thème pour les autres scripts : `window.ynTheme.set( 'nuit'|'papier'|'sepia' )` si elle existe
(sinon poser l'attribut et `localStorage['yn.theme']`) ; événement `document` `yn:theme`
(`detail.theme`) à chaque changement ; classe `html.yn-js` quand JavaScript est actif.

Ancre de reprise : `#yn-p-N` (paragraphe numéroté à partir de 1) dans l'URL d'un chapitre ; le lecteur y
défile directement. Elle est produite par la REST, la page compte, `yume/oeuvre-actions`,
`yume/resume-reading` et les lignes de tome.

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
