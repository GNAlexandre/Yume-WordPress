# 03 · Modèle de données

> Document antérieur au contrat technique : en cas de divergence, `06-contrat-technique.md` fait
> foi (métadonnées §4, rôles §5, tables §13, REST §12). Les noms ci-dessous ont été alignés sur le
> code pour les écarts les plus visibles ; le contrat reste la référence complète.

Tout est déclaré dans le plugin `yume-core` (`register_post_type`, `register_taxonomy`,
`register_post_meta` avec `show_in_rest`), jamais via un plugin de champs personnalisés.

## Types de contenu

```
yume_oeuvre ──1..n──▶ yume_tome ──1..n──▶ yume_chapitre
     │                    │
     │ favoris, notes     │ planning (étapes, %, responsables, dates)
     ▼                    ▼
   lecteur           journal d'avancement
```

### `yume_oeuvre` — l'œuvre (URL `/oeuvres/{slug}/`)

| Champ | Type | Notes |
| --- | --- | --- |
| `post_title`, `post_content` | — | Titre principal, synopsis |
| `post_thumbnail` | image | Couverture / visuel de la série |
| `yume_titres_alt` | string[] | FR, EN, romaji, japonais |
| `yume_auteur`, `yume_illustrateur`, `yume_editeur_vo` | string | |
| `yume_nb_tomes_vo`, `yume_statut_vo` | int, enum (`en_cours`, `termine`) | |
| `yume_jours_sortie` | enum[] | mercredi, samedi, dimanche… (planning) |
| `yume_liens` | {label,url}[] | Novel-Index, MangaDex, éditeur |
| `yume_note_moyenne`, `yume_nb_notes`, `yume_nb_favoris` | cache | recalculés à chaque vote |

Toutes les métadonnées portent le préfixe `yume_` (contrat §4) ; les tableaux des tomes et des
chapitres ci-dessous omettent ce préfixe pour rester lisibles.

Taxonomies : `yume_type` (light-novel, web-novel, manga) · `yume_statut` (en-cours, terminee,
en-pause, licenciee, abandonnee) · `yume_genre` (fantasy, romance, tranche-de-vie, …).

### `yume_tome` — tome, arc ou volume (URL `/oeuvres/{oeuvre}/tome-{n}/`)

| Champ | Type | Notes |
| --- | --- | --- |
| `oeuvre_id` | int | parent |
| `numero`, `titre`, `nature` | int, string, enum (`tome`, `arc`, `ex`, `bonus`, `chapitres`) | `chapitres` : sortie chapitre par chapitre, sans tome |
| `post_thumbnail` | image | Couverture du tome |
| `lien_pdf`, `lien_epub` | url | **Liens externes de téléchargement saisis par l'équipe ; les fichiers ne sont jamais hébergés sur le site** |
| `date_publication` | datetime | |
| `equivalence` | string | ex. « cet arc équivaut au tome 3 du LN » |
| `illustrations` | int[] | galerie (pages liminaires du DOCX) |
| **Planning** | | |
| `etape` | enum | `a_faire`, `traduction`, `relecture`, `edition`, `publie` |
| `avancement` | {traduction:%, relecture:%, edition:%} | |
| `responsables` | {traduction:user_id, relecture:user_id, edition:user_id} | |
| `date_cible` | date | |
| `derniere_maj`, `maj_par` | datetime, user_id | pour les rappels |
| `note_equipe` | text | visible équipe seulement |

### `yume_chapitre` — un chapitre = une page de lecture (URL `/lire/{oeuvre}/{tome}/{n}/`)

| Champ | Type | Notes |
| --- | --- | --- |
| `tome_id`, `numero`, `sous_titre` | | |
| `post_content` | HTML normalisé | classes `yn-dialogue`, `yn-thought`, `yn-center`, `yn-illustration` |
| `credits` | {traduction:string, relecture:string, edition:string} | |
| `nb_mots`, `temps_lecture` | cache | |
| `source` | {format:`docx`|`epub`, hash, importe_le} | traçabilité de l'import |

Commentaires WordPress activés sur `yume_tome` et `yume_chapitre`.

## Tables dédiées (performances et RGPD)

Créées à l'activation (`dbDelta`), préfixe `{$wpdb->prefix}yume_` :

| Table | Colonnes | Usage |
| --- | --- | --- |
| `favoris` | user_id, oeuvre_id, frequence (`immediat`/`hebdo`/`jamais`), created_at | Favoris + préférence d'alerte |
| `notes` | user_id, oeuvre_id, note (1–5), updated_at | Notation |
| `progression` | user_id, oeuvre_id, tome_id, chapitre_id, paragraphe, pourcentage, updated_at | Marque-page (une ligne par œuvre et par utilisateur) |
| `planning_journal` | id, tome_id, user_id, champ, ancien, nouveau, public, created_at | Historique public/équipe |
| `notifications` | id, destinataire, user_id, sujet, html, contexte, statut, tentatives, created_at, envoye_le | File d'envoi (WP-Cron, file maison) |

### Tables ajoutées depuis

Créées par les modules social et glossaire après la rédaction de ce document ; colonnes et règles
dans le contrat §13.

| Table | Usage |
| --- | --- |
| `listes`, `listes_oeuvres` | Listes de lecture des lecteurs (listes système À lire / En cours / Terminé, listes personnelles, page publique à jeton) |
| `notifications_lecteur` | Centre de notifications (cloche) : sorties des œuvres en favori, réponses aux commentaires |
| `push` | Abonnements Web Push (un par appareil, liés à la session WordPress) |
| `glossaire`, `glossaire_versions` | Entrées du glossaire par œuvre et historique des versions YAML (5 dernières) |

Réglages de lecture : `user_meta yume_reglages` (JSON : police, taille, interligne, opacité, thème,
largeur) ; pour les visiteurs, `localStorage` uniquement.

## Rôles et capacités

| Rôle | Capacités clés |
| --- | --- |
| `subscriber`, affiché « Lecteur » | `read` seulement : favoris, notes, commentaires et progression sont ouverts à tout compte connecté (pas de capacités `yume_favoris` / `yume_noter`) |
| `yume_traducteur`, `yume_relecteur`, `yume_graphiste` | + `yume_maj_planning` (ses tomes), `upload_files` |
| `yume_editeur` | + `yume_publier`, `edit_yume_*`, `moderate_comments`, `yume_maj_planning_tous` |
| `yume_gerant` | + `yume_gerer_equipe`, `yume_reglages`, `edit_others_yume_*` |
| `administrator` | tout (1 ou 2 comptes techniques) |

## API REST `yume/v1` (extraits)

| Méthode + route | Qui | Rôle |
| --- | --- | --- |
| `GET /planning` | public | Planning (JSON, aussi consommé par le bot Discord) |
| `PATCH /tomes/{id}/planning` | équipe | Mise à jour étape / % / date / note |
| `POST /publications` | éditeur, jeton d'application | Création tome + chapitres depuis DOCX/EPUB (multipart) |
| `POST /publications/{id}/publier` | éditeur | Publication + notifications |
| `GET/PUT /moi/reglages` | lecteur | Réglages de lecture |
| `PUT /moi/progression` | lecteur | Marque-page |
| `POST/DELETE /moi/favoris/{oeuvre}` | lecteur | Favoris |
| `PUT /moi/notes/{oeuvre}` | lecteur | Note |

Authentification : cookie + nonce WordPress pour le front ; **mots de passe d'application** pour les
intégrations (Yume-Trad, bot Discord).
