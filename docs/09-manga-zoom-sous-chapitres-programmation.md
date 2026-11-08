# Lecteur manga (largeur, zoom), sous-chapitres et programmation des sorties — cahier des charges (2.8.0)

Trois évolutions demandées par l'équipe après la lecture du chapitre 2 d'*Aldnoah.Zero Gaiden — Twin
Gemini* et la préparation du tome 2 de *Miss Medic's Diary at War* :

| Lot | Sujet | Modules touchés |
| --- | --- | --- |
| A | Lecteur manga : pages paysage plus larges, zoom au choix du lecteur | `mangadex` (lecteur.php, manga.css, manga.js), docs |
| B | Sous-chapitres : nature « Sous-chapitre » à l'import et à la publication, sommaire groupé, en-tête de chapitre | `import`, `core` (libellés), `library` (sommaire), `publication` (découpage, comparaison), thème (en-tête), docs, guide |
| C | Programmation des sorties : dates modifiables une à une, répartition en N publications par nombre de mots, interface dédiée | `publication` (service, REST, formulaire), `planning` (vue « Programmer les sorties »), docs, guide |
| D | Maquettes (canvas) des nouveaux écrans | `design/maquettes` |

Règles communes : contrat technique (`docs/06-contrat-technique.md`) relu avant toute modification et
mis à jour avec elle ; conventions du guide développeur (§4 : français, `yume_*`, sécurité, accessibilité,
pas de build) ; aucune notice PHP ; tests dans `tests/test-<module>*.php` ; toute nouvelle route REST
reçoit son attente dans `tests/test-securite-rest.php` ; tout écran de l'espace équipe modifié met à
jour `docs/guide-equipe/procedures.md` et régénère le DOCX du guide (`node tools/guide/construire.js`).
Le fichier Word du tome 2 de Miss Medic sert d'exemple de travail local : **il n'est jamais versionné**
(traduction protégée) ; les fixtures de test sont synthétiques (même structure, texte neutre).

---

## Lot A — Lecteur manga : largeur des pages paysage et zoom

### Constat

`manga.css` borne la colonne de lecture à 960 px (`.yn-reader:has(.yn-manga) { max-width: min(960px, 100%) }`)
et chaque page prend 100 % de cette colonne. Une double page (image paysage, largeur > hauteur) est
donc affichée à la même largeur qu'une page simple : deux fois plus petite, bulles illisibles
(exemple : <https://yumenovel.fr/lire/aldnoah-zero-gaiden-twin-gemini/tome-1/2/>). Le lecteur ne
dispose d'aucun zoom, alors que le lecteur de light novel offre taille de texte, largeur, interligne…

### A1 · Pages paysage

- À la fin du chargement d'une image (`load`), `manga.js` pose `is-paysage` sur la figure et l'image
  quand `naturalWidth > naturalHeight` (en plus de `is-chargee` / `is-longue`).
- Mode défilement : une page paysage **déborde de la colonne** : largeur cible = min(largeur de la
  fenêtre − 32 px, 2 × largeur de la colonne, 1920 px), centrée par rapport à la fenêtre (technique
  `position: relative; left: 50%; transform: translateX(-50%)` ou marges négatives). Elle ne doit
  jamais provoquer de défilement horizontal de la page quand le zoom est « auto ».
- Mode page par page : une page paysage tient **entière à l'écran** : largeur bornée par la fenêtre
  et hauteur par `100dvh − 140px` (règle existante), donc plus large qu'une page portrait.
- Pas encore chargée : le cadre 7/10 actuel reste (on ne connaît pas le format avant le chargement).
- L'arbre décoratif saisonnier (yume.js mesure la marge libre) peut se cacher : acceptable.

### A2 · Zoom

- Nouveau groupe d'outils dans `.yn-manga__outils`, `role="group"` « Zoom » : bouton « − »,
  valeur courante (`aria-live="polite"`, ex. « Auto », « 150 % »), bouton « + », bouton « Auto »
  (retour à l'ajustement automatique). Pas de 50 → 300 % par pas de 25 % ; « Auto » = comportement
  actuel (largeur de colonne / écran en mode page). Boutons `yn-btn yn-btn--sm`, libellés
  accessibles (`aria-label` « Agrandir », « Réduire »).
- Le zoom s'applique aux deux modes et aux deux formats : largeur de l'image = largeur « auto » × zoom
  (variable CSS `--yn-manga-zoom` posée sur `.yn-manga`, attribut `data-yn-zoom="auto|manuel"`).
  Quand l'image dépasse la fenêtre, **elle défile horizontalement dans sa figure** (`overflow-x: auto`
  sur la figure, image centrée tant qu'elle tient) ; la page du site ne déborde jamais.
- Raccourcis clavier (hors champ de saisie, sans modificateur) : `+` / `=` agrandir, `-` réduire,
  `0` retour à Auto. Ils passent par la même phase de capture que les flèches et ne gênent pas la
  barre de lecture.
- Double clic (ou double appui) sur une page : bascule Auto ↔ 200 % ; en mode page par page, le
  simple clic continue de tourner la page (le double clic ne doit pas tourner deux pages : attendre
  ~250 ms ou utiliser `dblclick` en annulant le second `click`). Si c'est trop fragile, livrer sans le
  double clic et le dire.
- Mémorisé sur l'appareil dans `localStorage` `yn.manga` : `{ mode, qualite, zoom }` (`zoom` : nombre
  de 50 à 300 ou absent = auto). Compatible avec les valeurs existantes.
- Sur mobile, le pincement natif du navigateur n'est pas bloqué (pas de `user-scalable=no`).

### Livrables du lot A

- `lecteur.php` (HTML des outils), `manga.css`, `manga.js`.
- Tests PHP (`tests/test-mangadex.php` ou nouveau `tests/test-mangadex-lecteur.php`) : outils de zoom
  présents dans le HTML, attributs, libellés ; aucune régression des tests existants.
- Test de bout en bout Playwright (dans `tools/preprod/` ou `tools/ci/`, sans dépendance nouvelle ;
  Chromium préinstallé, `require('playwright')` disponible) : site local servi par
  `tools/localenv/serve.sh`, chapitre MangaDex fabriqué (métas `yume_mdx_*`), images du proxy
  **interceptées** par `page.route` et remplacées par deux PNG générés (portrait 700×1000, paysage
  2000×1400) ; vérifications : la figure paysage est plus large que la colonne en défilement ; « + »
  agrandit (largeur rendue × 1,25) ; « Auto » revient ; le réglage survit à un rechargement ; en mode
  page, une paysage tient dans la fenêtre ; aucune barre de défilement horizontale sur `document`.
- Docs : `docs/mangadex.md` (nouvelle section « Lecture : doubles pages et zoom »), contrat §8 bis
  (lecteur) et §14 (`localStorage` `yn.manga`), ligne de CHANGELOG fournie dans le rapport (la version
  est posée au moment du commit, pas par le lot).

---

## Lot B — Sous-chapitres

### Constat

Certains light novels (ex. *Miss Medic's Diary at War*) ont des chapitres (style Word « Titre 1 »)
composés de sous-chapitres (« Titre 2 » : entrées de journal datées). Aujourd'hui, un Titre 2 n'est
qu'un intertitre dans le chapitre ; le découpage manuel ne propose que chapitre, prologue, interlude,
épilogue, postface, bonus, illustrations.

Structure du tome 2 de Miss Medic (55 432 mots, 18 Titre 1, 27 Titre 2 ; les Titre 1 « Bataille
défensive… », « Évacuation… », « Wynn… » n'ont **aucun texte propre**, seulement des Titre 2) :

```
Année 1938, été 6                        1011
Bataille défensive de Mashdale              0
  Journal… 10 septembre, soir             797
  10 septembre, nuit                      779
  10 septembre, tard dans la nuit        3838
  11 septembre, début de matinée          814
  11 septembre, matin                    1085
  11 septembre, midi                      502
Année 1938, été 7                         702
Bataille défensive de Mashdale 2            0
  11 septembre, soir                     1882
  12 septembre, midi                     1116
  12 septembre, soir                      600
  12 septembre, nuit                      661
  13 septembre, aube naissante           1401
  13 septembre, soir                     1320
  15 septembre, midi                     1089
  17 septembre, soir                     3421
Année 1938, été 8                         625
Évacuation de Mashdale 1                    0
  17 septembre, soir                     5301
Année 1938, été 9                         906
Évacuation de Mashdale 2                    0
  17 septembre                           8453
Année 1938, été 10                       1084
Évacuation de Mashdale 3                    0
  17 septembre, nuit                     3279
  18 septembre, midi                      922
  19 septembre                           1100
  20 septembre                            255
Année 1938, été 11                        618
Évacuation de Mashdale 4                    0
  20 septembre, midi                     1513
  20 septembre, aube naissante            674
  Compte rendu de combat — 153ᵉ division  118
  22 septembre, matin                     823
  Sans date                               388
Année 1938, été 12                        787
Wynn, la capitale 1                         0
  22 septembre, nuit                     1344
Année 1938, été 13                       1803
Wynn, la capitale 2                         0
  23 septembre, midi                     3058
Année 1938, été 14                        715
Postface                                  479
```

### Modèle

- Nouvelle nature de chapitre **`sous-chapitre`** (libellé « Sous-chapitre ») dans
  `Texte::LIBELLES`, `yume_natures_chapitre()` et l'énumération `yume_nature` du contrat (§4).
- Un sous-chapitre est un `yume_chapitre` à part entière (il a sa page, sa date de sortie, son
  annonce, il compte dans `yume_nb_chapitres`), avec :
  - `yume_numero` = numéro du **chapitre parent** (nombre, comme un chapitre) ;
  - `yume_sous_numero` (integer ≥ 1) = rang dans le chapitre, nouvelle méta publique en lecture ;
  - `yume_sous_titre` = son propre titre (« 10 septembre, soir ») ;
  - `yume_chapitre_parent` (string) = titre du chapitre parent (« Bataille défensive de Mashdale »),
    dénormalisé pour l'affichage ; vide si le parent n'a pas de titre.
- Le **chapitre parent** : s'il a du texte propre avant son premier sous-chapitre, il reste un
  chapitre ordinaire (nature `chapitre`, numéro N) suivi de ses sous-chapitres (N, 1…n). S'il n'a
  **aucun texte** (cas Miss Medic : seulement le titre, éventuellement des illustrations), il ne
  devient pas un chapitre vide : son titre va dans `yume_chapitre_parent` des enfants et ses
  illustrations rejoignent le premier sous-chapitre.
- Un sous-chapitre suit obligatoirement un chapitre de nature `chapitre` (ou un autre sous-chapitre
  du même parent). Placé après un prologue, un interlude, un épilogue… : avertissement du `Result`
  (« Sous-chapitre « … » sans chapitre parent : traité comme un chapitre ») et nature `chapitre`.
- Libellés (`yume_libelle_chapitre()`) : complet « Chapitre 2, partie 1 » ; nouvelle fonction
  `yume_libelle_partie( int $chapitre_id ): string` → « Partie 1 » (vide pour un autre chapitre).
  Clé de comparaison (`Service::cle_chapitre`) et slug : `sous-chapitre-2-1`, `chapitre-2-partie-1`
  (ou équivalent stable, documenté).
- Ordre : `menu_order` posé séquentiellement par l'import pour tous les chapitres du fichier (déjà le
  cas à vérifier ; sinon le poser), puis `yume_numero`, puis `yume_sous_numero` dans
  `yume_get_chapitres()`. Navigation précédent / suivant : ordre naturel des posts (parent, parties,
  chapitre suivant).

### Import et publication

- Détection automatique **inchangée** (un Titre 2 reste un intertitre) : les sous-chapitres sont un
  choix de l'équipe.
- Découpage manuel : « Sous-chapitre » dans le menu Nature de chaque ligne ; nouveau bouton rapide
  « Couper aux Titre 2 (sous-chapitres) » : les Titre 1 deviennent des chapitres, les Titre 2 des
  sous-chapitres. Numérotation : chapitres d'après « Premier numéro de chapitre », parties 1…n.
- Marqueur dans le document : « [sous-chapitre] Titre » (`docs/04`, aide du formulaire).
- `Chapter_Builder` : à une coupure de nature sous-chapitre, rattachement au dernier chapitre ; si ce
  chapitre n'a pas de contenu texte, il est retiré de la liste et son titre et ses images passent à
  l'enfant. `terminer()` numérote parents puis parties. `Result` : `nature`, `numero`, `sous_numero`,
  `chapitre_parent` par chapitre.
- `Service::enregistrer_chapitres` écrit les métas ; `resume_contenu`, `titres_chapitre`,
  `comparer` (clé et libellé « Chapitre 2, partie 1 · 10 septembre, soir ») les prennent en compte ;
  l'aperçu « Chapitres détectés » du formulaire les affiche indentés sous leur chapitre.
- Annonces (Discord, e-mail, article) : libellé « Chapitre 2, partie 1 — 10 septembre, soir ».

### Façade (thème et bibliothèque)

- Sommaire du tome (bloc / gabarit du module `library`, `single-yume_tome.html`) : les parties sont
  **groupées** sous une ligne de chapitre « Chapitre 2 — Bataille défensive de Mashdale » (lien vers
  le chapitre parent s'il existe, simple intitulé sinon), chaque partie indentée « Partie 1 ·
  10 septembre, soir · 3 min ». Sans JavaScript, HTML sémantique (`ol` imbriqués).
- En-tête du chapitre (`.yn-chapter-header`) : surtitre « Chapitre 2 — Bataille défensive de
  Mashdale », titre « Partie 1 — 10 septembre, soir » ; `document.title` et `<title>` cohérents.
- Bandeau « Reprendre la lecture », dernières sorties, planning : le libellé complet s'affiche
  partout où `yume_libelle_chapitre()` est utilisé (vérifier les emplois de `yume_sous_titre` qui
  concatènent).

### Livrables du lot B

- Code des modules `import`, `core`, `library`, `publication`, thème.
- Tests : `tests/test-import*.php` (fixture DOCX synthétique construite avec `ZipArchive` dans le
  test, même structure que Miss Medic : Titre 1 sans texte + Titre 2 ; Titre 1 avec texte + Titre 2 ;
  Titre 2 après un prologue → avertissement), `tests/test-publication*.php` (découpage REST avec
  nature sous-chapitre, comparaison, slugs), `tests/test-library.php` (sommaire groupé),
  `tests/test-core.php` (libellés), navigation du lecteur.
- Bout en bout (Playwright, sans dépendance nouvelle) : formulaire de publication avec un DOCX
  synthétique, « Couper aux Titre 2 », enregistrement, puis page du tome (sommaire groupé) et page
  d'une partie (en-tête, précédent / suivant).
- Docs : contrat §4 (métas, énumération), §7 (API), `docs/04` (marqueur, découpage),
  `docs/guide-equipe/procedures.md` (« Découper soi-même les chapitres » + « Publier un chapitre en
  plusieurs parties (sous-chapitres) »), DOCX du guide régénéré, ligne de CHANGELOG dans le rapport.

---

## Lot C — Programmation des sorties

### Constat

L'étape « 3 · La sortie des nouveaux chapitres » du formulaire propose : maintenant, un par un au
rythme (ou tous les N jours), à une date. Les dates calculées ne sont pas modifiables une à une, et
chaque chapitre fait une publication : impossible de dire « 10 publications pour ce tome, réparties
équitablement ».

### C1 · Calendrier sur mesure

- Quatrième choix de sortie **`calendrier`** (« Calendrier sur mesure ») dans `Service::SORTIES`,
  le formulaire (radio) et la REST. Il ouvre le panneau « Programmer les sorties » (C3).
- `Service::calendrier()` accepte des dates explicites (`$options['calendrier']`, voir format) ; les
  chapitres d'une même publication partagent la même date, donc une seule annonce (le regroupement
  par horodatage existe déjà : `programmer_sortie_groupee`, `creneaux`). Validation : chaque chapitre
  à sortir est affecté une fois, l'ordre des publications respecte l'ordre des chapitres (contiguïté),
  dates valides, passées = maintenant avec avertissement.
- Format envoyé par le formulaire (champ caché `calendrier`, JSON) :
  `{ "publications": [ { "date": "2026-11-15T18:00", "chapitres": ["chapitre-1", "sous-chapitre-2-1"] }, … ] }`
  — clés = clés de comparaison des chapitres du fichier (`cle_chapitre`) ou `id:123` pour un
  brouillon déjà présent. Sans JavaScript : champs `repartition` (N) et `date_sortie` /
  `intervalle` → répartition calculée côté serveur.

### C2 · Répartition en N publications

- `Service::repartir( array $mots, int $n ): array` (sans WordPress, testable à vide) : partition
  **contiguë** de la liste des unités (chapitres et sous-chapitres, dans l'ordre) en N groupes qui
  minimise le plus gros groupe en nombre de mots (partition linéaire, programmation dynamique ;
  n ≤ 400 unités, N ≤ 60). Jamais de coupure à l'intérieur d'une unité. Une unité à 0 mot (chapitre
  parent sans texte, page d'illustrations) ne forme jamais une publication seule : elle est collée à
  l'unité suivante. N > nombre d'unités → N = nombre d'unités. Retourne les tailles des groupes.
- Même algorithme en JavaScript dans le formulaire (interactif) ; un test PHP et un test JS (Node,
  `node --test`, sans dépendance) vérifient sur la liste des 45 unités de Miss Medic (ci-dessus, dans
  l'ordre, les Titre 1 à 0 mot compris) que les deux donnent les **mêmes** groupes pour N = 10, 5, 1
  et 45.
- Dates par défaut d'un calendrier : rythme du tome s'il en a un (`yume_prochaine_sortie_rythme`),
  sinon date de départ puis tous les `intervalle` jours — exactement `calendrier( …, 'rythme', … )`
  avec N dates. Bouton « Recalculer les dates » après modification de la première.

### C3 · Interface dédiée « Programmer les sorties »

- Dans le formulaire de publication : panneau pleine largeur (`.yn-card`, `data-yn-programmer`)
  affiché quand « Calendrier sur mesure » est choisi et que les chapitres à sortir sont connus
  (après l'analyse du fichier, ou brouillons du tome sans fichier : `infos_tome()` renvoie désormais
  `a_sortir` = `[ { id, cle, libelle, nature, mots } ]`).
  - En tête : « Nombre de publications » (nombre, 1 … n, défaut n) + bouton « Répartir » ;
    résumé « 10 publications · 5 500 mots en moyenne · de 4 800 à 6 300 ».
  - Liste des unités dans l'ordre, une ligne par unité : case « Nouvelle publication ici » (la première
    toujours cochée et désactivée), pastille de nature, libellé, mots. Les lignes d'une même
    publication sont visuellement groupées sous une ligne d'en-tête « Publication 3 · [date] ·
    5 612 mots » dont la date est un `datetime-local` modifiable. Cocher/décocher recompose les
    groupes et les dates (les dates déjà modifiées à la main sont conservées tant que la publication
    existe).
  - Boutons : « Recalculer les dates », « Tout en une publication », « Une par chapitre ».
  - Accessible : vrais `<input>`/`<label>`, tableau ou listes sémantiques, `aria-live` pour le résumé.
- Vue de l'espace équipe **« Programmer les sorties »** (`/equipe/programmer/?tome=ID`, module
  `planning`, capacité `yume_publier`) : même panneau, pour les chapitres **brouillons et programmés**
  d'un tome existant ; enregistrement par nouvelle route
  `POST /yume/v1/publications/(?P<id>\d+)/calendrier` (mêmes rôles que `/publier` ; attente ajoutée à
  `test-securite-rest.php`) qui programme les brouillons et **reprogramme** les chapitres déjà
  programmés (date changée, annonce et sortie groupée déplacées, journal `planning`). Liens vers cette
  vue depuis la fiche équipe du tome et depuis le formulaire (« Programmer plus tard dans la vue
  dédiée »).
- Le reste du formulaire (annonce, liens PDF/EPUB, tome complet, « liens avec le dernier chapitre »)
  fonctionne avec le calendrier comme avec le rythme.

### Livrables du lot C

- Code (`publication` : class-service, class-rest, class-formulaire/gabarit, view.js, style.css ;
  `planning` : vue, route, lien de la fiche).
- Tests : `repartir()` (PHP + JS, fixture Miss Medic), `calendrier()` avec dates explicites,
  publication avec `sortie=calendrier` (REST : chapitres programmés aux bonnes dates, une annonce par
  publication), repli sans JavaScript (`repartition=N`), route `/calendrier` (reprogrammation),
  attentes de sécurité, vue équipe (droits, rendu).
- Bout en bout (Playwright) : formulaire avec un DOCX synthétique de 45 unités, choix « Calendrier sur
  mesure », N = 10, « Répartir », modification d'une date, enregistrement ; vérification des dates
  des chapitres (`post_date`) et de la vue « Programmer les sorties ».
- Docs : contrat (§8 formulaire, §9 routes, §7 API), `docs/guide-equipe/procedures.md` (« Programmer
  les sorties : calendrier sur mesure et répartition en N publications »), DOCX du guide régénéré,
  ligne de CHANGELOG dans le rapport.

---

## Lot D — Maquettes (canvas)

Nouvelles planches dans `design/maquettes/` (mêmes conventions que les planches existantes : 1440 px
de large, tokens « Crépuscule sakura » de `design/README.md`, polices Outfit / Nunito Sans / IBM Plex
Mono, HTML autonome) et ajoutées à `design/maquettes/canvas.json` sous un titre de rangée
« 2.8.0 — Manga, sous-chapitres, programmation » :

1. `LecteurManga.dc.html` — lecteur manga bureau en défilement : page portrait dans la colonne, double
   page paysage débordant de la colonne, barre d'outils (mode, qualité, **zoom − / 100 % / + / Auto**).
2. `LecteurMangaMobile.dc.html` (390 × 844) — mode page par page, outils de zoom, page zoomée qui
   défile horizontalement.
3. `TeamDecoupage.dc.html` — formulaire de publication, cadre « Délimiter les chapitres moi-même » avec
   la nature « Sous-chapitre », le bouton « Couper aux Titre 2 », l'aperçu des chapitres détectés
   indenté (données Miss Medic).
4. `TeamProgrammer.dc.html` — panneau « Programmer les sorties » : N = 10, résumé, groupes avec dates
   modifiables, lignes d'unités (données Miss Medic : 45 unités, répartition réelle).
5. `TomeSousChapitres.dc.html` — page du tome : sommaire groupé (chapitre parent, parties indentées)
   et en-tête d'une partie.

Les mêmes planches sont publiées dans un canvas Design (artifact) pour relecture par l'équipe.
