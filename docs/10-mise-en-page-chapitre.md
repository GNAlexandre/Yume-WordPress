# Mettre en page un chapitre sur le site — cahier des charges (2.9.0)

## Constat

La lecture en ligne est générée depuis le Word (`docs/04` §2.1) : récit (Corps de texte, sans
classe), dialogue (`yn-dialogue`, paragraphe de liste à puce tiret ou tiret tapé), pensée
(`yn-thought`, style Word « Pensée »), centré (`yn-center`), séparateur de scène (`yn-scene-break`).
Quand un paragraphe est mal stylé dans le Word (récit resté en paragraphe de liste, pensée en corps
de texte), la seule correction possible est de reprendre le Word et de redéposer le fichier, ou
d'ouvrir l'éditeur WordPress, peu adapté. Exemple : tome 2 de *Miss Medic's Diary at War* (2 853
paragraphes, 1 604 en paragraphe de liste lus comme dialogues, 22 pensées, 18 tirets tapés dans du
corps de texte).

Trois lots :

| Lot | Sujet | Modules |
| --- | --- | --- |
| E | Vue d'équipe « Mettre en page » : styles Récit / Dialogue / Pensée / Centré / Séparateur / Intertitre d'un clic, correction du texte en place, enregistrement sans annonce avec révision | nouveau module `mise-en-page`, liens depuis `planning` (fiche du tome) et `corrections` |
| F | Import : styles Word nommés reconnus en plus (Récit, Narration, Dialogue, Centré) et rapport des paragraphes douteux | `import`, `publication` (rapport) |
| G | Maquettes (canvas) de la vue, bureau et mobile | `design/maquettes` |

Règles communes : contrat technique (`docs/06-contrat-technique.md`) relu et mis à jour ; conventions
du guide développeur (§4) ; aucune notice PHP ; PHPCS propre ; toute nouvelle route REST reçoit son
attente dans `tests/test-securite-rest.php` ; tout écran de l'espace équipe met à jour
`docs/guide-equipe/procedures.md` et régénère le DOCX du guide. Le Word du tome 2 de Miss Medic sert
d'exemple local et **n'est jamais versionné** ; les fixtures sont synthétiques.

Hypothèses retenues (à défaut de réponse de l'équipe) : l'écran couvre les trois types d'erreur
(récit affiché en dialogue, pensée non reconnue, paragraphe centré ou séparateur manqué) ; la
correction du texte en place est incluse ; accès réservé aux **éditeurs et gérants** (capacité
`yume_publier`), avec un filtre `yume_mise_en_page_peut( bool, int $chapitre_id, int $user_id )`
pour l'ouvrir plus tard aux traducteurs de leurs tomes.

---

## Lot E — Vue « Mettre en page un chapitre »

### Modèle

Le contenu d'un chapitre reste une suite de blocs Gutenberg standard (`core/paragraph` avec
`className`, `core/separator.yn-scene-break`, `core/heading`, `core/image`, notes…), produits par
`Import\Blocks`. L'écran travaille sur une **liste d'unités** dérivée de `parse_blocks()` :

```
{ "index": 12, "type": "paragraphe", "style": "recit|dialogue|pensee|centre", "html": "Texte <em>inline</em>" }
{ "index": 13, "type": "separateur" }
{ "index": 14, "type": "intertitre", "niveau": 3, "html": "…" }
{ "index": 15, "type": "autre", "apercu": "Image : …" }        // image, galerie, notes : intouchable, affiché tel quel
```

- `style` : `recit` (aucune classe), `dialogue` (`yn-dialogue`, tiret cadratin + espace insécable
  posés par `Texte::normaliser_dialogue( $html, true )` ; retirés avec `false` quand on quitte le
  style), `pensee` (`yn-thought`), `centre` (`yn-center`). Un paragraphe peut être « centré » en plus
  d'un autre style ? Non : un seul style par paragraphe, « Centré » est un style à part (titres de
  lettres, dates, mentions), comme à l'import.
- `html` : texte avec balises inline autorisées seulement (`em`, `strong`, `u`, `br`, `sup.yn-note`
  et son lien d'appel de note) ; tout le reste est retiré (`wp_kses` avec cette liste).
- À l'enregistrement : `Texte::typographie()` sur chaque paragraphe (insécables avant `: ; ? ! »`,
  après `«`, apostrophe typographique), blocs reconstruits par `Import\Blocks` (`paragraphe()`,
  `separateur()`, `titre()`), blocs « autre » recopiés à l'identique, ordre conservé.
- Unités : scinder un paragraphe (Entrée dans le texte en place), fusionner avec le précédent
  (Retour arrière en début de paragraphe), supprimer un paragraphe vide, insérer un séparateur de
  scène avant une unité. Pas de déplacement d'images, pas d'ajout d'images (hors périmètre).

### REST (module `mise-en-page`, `includes/mise-en-page/rest.php`)

- `GET /yume/v1/chapitres/(?P<id>\d+)/mise-en-page` → `{ chapitre: { id, titre, libelle, url, tome, oeuvre, statut, modifie (post_modified_gmt), mots }, unites: [...], peut_enregistrer }`.
- `PUT /yume/v1/chapitres/(?P<id>\d+)/mise-en-page` corps `{ base: "<post_modified_gmt reçu>", unites: [...] }` :
  - 403 sans droit ; 409 `yume_mise_en_page_conflit` si `post_modified_gmt` a changé depuis `base`
    (quelqu'un d'autre a enregistré : la réponse renvoie l'état courant) ; 400 si une unité est
    invalide (index inconnu, type changé pour un bloc « autre », HTML refusé) ;
  - enregistre par `wp_update_post` (révision créée : le type supporte `revisions`), **sans toucher**
    au statut, à la date ni aux métas de publication ; aucune annonce, aucun e-mail, aucun Discord
    (vérifier que les hooks d'annonce ignorent une mise à jour de contenu, comme « Mettre à jour
    (sans annonce) ») ; `yume_nb_mots` et `yume_temps_lecture` recalculés ; empreinte de comparaison
    (`Service::noter_empreinte`) rafraîchie pour que le prochain dépôt du Word signale « En ligne,
    modifié » et non « identique » ;
  - ligne de journal `mise_en_page` (privée, jamais publique) : « Mise en page corrigée : 12
    paragraphes modifiés » ;
  - réponse : nouvel état (`GET`).
- Permissions : `permission_callback` réel = utilisateur connecté avec `yume_publier` et
  `edit_post` sur le chapitre, filtré par `yume_mise_en_page_peut`. Attentes à ajouter :
  `GET`/`PUT … /mise-en-page` → `array( 'editeur', 'gerant', 'administrateur' )`.
- Verrou souple : à l'ouverture, l'écran prévient si un autre membre a ouvert le même chapitre
  depuis moins de 10 minutes (transient `yume_mep_<id>` = `{user, depuis}` posé par le `GET`,
  rafraîchi toutes les 2 minutes par un `POST …/mise-en-page/presence`, attente `editeur, gerant,
  administrateur`). Information seulement : on peut quand même enregistrer, le 409 protège.

### Vue d'équipe (`includes/mise-en-page/vue.php`, `assets/editeur.js`, `assets/editeur.css`)

Adresse `/equipe/?vue=chapitre&id=ID`, déclarée par le filtre `yume_vues_equipe` (modèle :
`includes/planning/programmer.php`), **hors menu** (on y arrive par un chapitre) ; titre « Mettre en
page · Chapitre 2, partie 1 — 10 septembre, soir » et fil d'Ariane œuvre › tome › chapitre.

- **Colonne de lecture** identique au site : même `reader.css` et mêmes variables, classe
  `.yn-reader`, largeur bureau (68ch) ou mobile (390 px) au choix, thème Nuit / Papier / Sépia au
  choix (aperçu seulement, le réglage du membre n'est pas touché). Chaque unité est rendue comme sur
  le site, avec à gauche une étiquette discrète de son style (R, D, P, C, —) et un numéro.
- **Palette** fixée à droite (en bas sur mobile) : Récit (R), Dialogue (D), Pensée (P), Centré (C),
  Séparateur de scène (S : insère avant la sélection), Intertitre (T : transforme un paragraphe en
  `h3` et inversement). Clic sur un paragraphe = sélection ; Maj + clic = plage ; Ctrl/Cmd + clic =
  ajout ; glisser sur plusieurs paragraphes = plage ; clic sur un style de la palette ou touche =
  appliqué à la sélection. Flèches ↑ ↓ déplacent la sélection, Échap la vide.
- **Texte en place** : double clic (ou Entrée sur la sélection) ouvre le paragraphe en
  `contenteditable` ; Ctrl+I / Ctrl+B pour italique / gras, Entrée = scinder, Retour arrière en début
  = fusionner, Échap = annuler la saisie, clic ailleurs = valider. Le HTML saisi est nettoyé côté
  client (mêmes balises que le serveur) et le tiret de dialogue est géré par le style, pas par la
  frappe.
- **Actions groupées** (menu « Actions ») : « Lignes commençant par un tiret → Dialogue », « Toute la
  sélection → Récit », « Paragraphes entièrement en italique → Pensée », « Supprimer les paragraphes
  vides ». Annuler / Rétablir (Ctrl+Z / Ctrl+Maj+Z), pile en mémoire.
- **Enregistrer** (Ctrl+S) : bouton principal, compteur « 12 paragraphes modifiés », état
  « Enregistré à 18 h 02 » ; en cas de 409, message « Quelqu'un a enregistré ce chapitre entre-temps
  (Prénom, 18 h 01) : recharger pour voir sa version » avec bouton Recharger ; `beforeunload` si
  des modifications ne sont pas enregistrées.
- Liens : « Voir le chapitre », « Chapitre précédent / suivant » (dans le même tome), « Historique
  des révisions » (écran WordPress `revision.php`), « Édition avancée (administration WordPress) ».
- Points d'entrée : fiche équipe du tome (`planning/tome-fiche-equipe.php`) : bouton « Mettre en
  page » sur chaque ligne de chapitre ; vue Corrections (`corrections/equipe.php`) : « Mettre en
  page » à côté de « Corriger dans l'éditeur » ; menu « Actions » de la barre de lecture pour un
  membre qui a le droit, si la barre expose déjà des actions d'équipe (sinon, ne rien ajouter).
- Sans JavaScript : la vue affiche le chapitre en lecture seule et renvoie vers l'édition avancée.
- Accessibilité : vrais boutons, `aria-pressed` sur le style courant de la sélection, annonce
  `aria-live` des actions (« 3 paragraphes passés en Dialogue »), focus visible, raccourcis
  documentés dans un panneau « ? ».

### Livrables du lot E

- Module `includes/mise-en-page/` (module.php, rest.php, vue.php, class-unites.php, assets),
  enregistré dans le socle comme les autres modules ; liens dans `planning` et `corrections`.
- Tests `tests/test-mise-en-page.php` : découpage en unités et reconstruction à l'identique
  (aller-retour sans modification = contenu identique), changement de style avec tiret posé/retiré,
  typographie, nettoyage HTML (script, style inline, balises interdites), blocs « autre »
  intouchables, scinder / fusionner / séparateur, 409 de conflit, révision créée, métas et statut
  inchangés, aucune annonce déclenchée (compteur de hooks d'annonce / `pre_http_request`), journal,
  droits (lecteur, traducteur, éditeur, gérant), attentes de sécurité.
- Bout en bout Playwright (`tools/ci/mise-en-page.sh`, modèle `tools/ci/sous-chapitres.sh`) : ouvrir
  la vue sur un chapitre de la démo, sélectionner trois paragraphes, D, vérifier la classe et le
  tiret, double clic et correction d'un mot, Entrée pour scinder, enregistrer, recharger la page du
  chapitre côté lecteur et vérifier le rendu, conflit simulé (second enregistrement avec une base
  périmée → message).
- Docs : contrat (§4 pas de nouvelle méta ; §7 API `yume_mise_en_page_url()` ; §10 vue ; §12 routes ;
  §16 journal), `docs/04` §4 (renvoi), `docs/guide-equipe/procedures.md` : nouvelle procédure
  « Corriger la mise en page d'un chapitre sur le site » dans « Ajouter des chapitres à un tome »
  après « Corriger un chapitre déjà en ligne », astuce de cette dernière mise à jour ; DOCX régénéré.

---

## Lot F — Import : styles nommés et paragraphes douteux

- `Docx_Styles::ROLES` : ajouter `recit`, `narration`, `narratif`, `corpsdetexte` (Body Text) → rôle
  `recit` (force le récit même pour un paragraphe de liste ou un paragraphe en italique) ; `centre`,
  `centered`, `centrer` → `centre` ; vérifier que `dialogue` force bien le dialogue sans puce.
  Le rôle `recit` existe-t-il déjà implicitement (null) ? Le rendre explicite sans changer le
  comportement des documents existants : un style nommé Récit l'emporte sur la détection de puce.
- **Rapport des paragraphes douteux** (`Result::$douteux`, `stats['douteux']`) : pour chaque
  chapitre, les paragraphes qui ont été classés par déduction et non par style explicite :
  tiret tapé en corps de texte converti en dialogue ; paragraphe de liste **sans** puce tiret gardé
  en récit ; paragraphe entièrement en italique sans style Pensée ; paragraphe centré par `w:jc`
  seulement. Format : `{ chapitre (clé), rang, motif, extrait (60 caractères) }`, plafonné à 200.
  Le résumé de l'import compte « 12 paragraphes à vérifier » et le formulaire de publication les
  liste (repliés) avec, après la publication, un lien « Mettre en page » vers le chapitre.
- Tests (`tests/test-import*.php`, `tests/test-publication*.php`) : DOCX synthétiques avec styles
  Récit / Dialogue / Centré nommés, puce tiret sur un style Récit (reste récit), tiret tapé (douteux),
  italique entier (douteux) ; rapport dans l'analyse REST.
- Docs : `docs/04` §2.1 (table des styles), `docs/guide-equipe/procedures.md` « Préparer le fichier
  Word › Utiliser les bons styles » (noms de styles reconnus : Récit, Dialogue, Pensée, Centré,
  Séparateur), DOCX régénéré.

---

## Lot G — Maquettes (canvas)

Dans `design/maquettes/` (mêmes conventions que les planches existantes), ajoutées à `canvas.json`
sous une rangée « 2.9.0 — Mettre en page un chapitre » :

1. `TeamMiseEnPage.dc.html` (1440) : la vue avec le chapitre « Chapitre 2, partie 1 — 10 septembre,
   soir » de Miss Medic rendu comme sur le site (texte neutre, pas la traduction), trois paragraphes
   sélectionnés, palette à droite avec Dialogue enfoncé, étiquettes R/D/P dans la marge, bandeau
   « 3 paragraphes passés en Dialogue », bouton Enregistrer avec compteur, liens Voir / Révisions /
   Édition avancée, menu Actions ouvert.
2. `TeamMiseEnPageMobile.dc.html` (390 × 844) : palette en bas, un paragraphe en cours de saisie
   (contenteditable, barre italique / gras), aperçu Papier.
3. `TeamMiseEnPageDouteux.dc.html` (1440) : formulaire de publication après l'analyse, encart
   « 12 paragraphes à vérifier » replié/déplié avec motifs et extraits, lien « Mettre en page » par
   chapitre.

Publiées aussi dans un canvas Design (artifact) pour relecture.
