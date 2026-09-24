# 03 · Modèle de données

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
| `titres_alternatifs` | string[] | FR, EN, romaji, japonais |
| `auteur`, `illustrateur`, `editeur_vo` | string | |
| `nb_tomes_vo`, `statut_vo` | int, enum (`en_cours`, `termine`) | |
| `jour_sortie` | enum[] | mercredi, samedi, dimanche… (planning) |
| `liens_externes` | {label,url}[] | Novel-Index, MangaDex, éditeur |
| `note_moyenne`, `nb_notes`, `nb_favoris` | cache | recalculés à chaque vote |

Taxonomies : `yume_type` (light-novel, web-novel, manga) · `yume_statut` (en-cours, terminee,
en-pause, licenciee, abandonnee) · `yume_genre` (fantasy, romance, tranche-de-vie, …).

### `yume_tome` — tome, arc ou volume (URL `/oeuvres/{oeuvre}/tome-{n}/`)

| Champ | Type | Notes |
| --- | --- | --- |
| `oeuvre_id` | int | parent |
| `numero`, `titre`, `nature` | int, string, enum (`tome`, `arc`, `ex`, `bonus`) | |
| `post_thumbnail` | image | Couverture du tome |
| `lien_pdf`, `lien_epub` | url | **Liens ClicTune saisis par l'équipe** |
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
| `post_content` | HTML normalisé | classes `dialogue`, `pensee`, `center`, `illustration` |
| `credits` | {traduction:string, relecture:string, edition:string} | |
| `nb_mots`, `temps_lecture` | cache | |
| `source` | {format:`docx`|`epub`, hash, importe_le} | traçabilité de l'import |

Commentaires WordPress activés sur `yume_tome` et `yume_chapitre`.

## Tables dédiées (performances et RGPD)

Créées à l'activation (`dbDelta`), préfixe `{$wpdb->prefix}yume_` :

| Table | Colonnes | Usage |
| --- | --- | --- |
| `favoris` | user_id, oeuvre_id, created_at, alerte (`immediat`/`hebdo`/`jamais`) | Favoris + préférence d'alerte |
| `notes` | user_id, oeuvre_id, note (1–5), updated_at | Notation |
| `progression` | user_id, oeuvre_id, chapitre_id, paragraphe, pourcentage, updated_at | Marque-page (une ligne par œuvre et par utilisateur) |
| `planning_journal` | tome_id, user_id, champ, ancienne_valeur, nouvelle_valeur, created_at | Historique public/équipe |
| `notifications` | id, user_id, canal, sujet, statut, envoye_le | File d'envoi (Action Scheduler) |

Réglages de lecture : `user_meta yume_reglages` (JSON : police, taille, interligne, opacité, thème,
largeur) ; pour les visiteurs, `localStorage` uniquement.

## Rôles et capacités

| Rôle | Capacités clés |
| --- | --- |
| `lecteur` (= subscriber) | `read`, `yume_favoris`, `yume_noter`, `yume_commenter`, `yume_progression` |
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
