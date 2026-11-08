# 04 · Import DOCX / EPUB et lecteur en ligne

Spécification du convertisseur `yume-core/includes/import/` et du rendu du lecteur, établie à partir
du document de référence *Grimgar of Fantasy and Ash T.7* (`.docx`, 3 526 paragraphes).

## 1. Pourquoi DOCX et EPUB, pas PDF

Le PDF fige une mise en page A4 : on ne peut pas en extraire proprement des paragraphes, des styles
ni un découpage en chapitres. Le DOCX, lui, porte la **structure** (styles de paragraphe, titres,
images). Le formulaire de publication attend donc le **DOCX** du tome (source de référence) ; un EPUB
est accepté en dépannage selon les règles du §3.

Les PDF et EPUB **ne sont jamais hébergés sur le site** : ils restent des liens externes de
téléchargement saisis dans le formulaire. Le DOCX déposé est analysé puis supprimé du serveur.

## 2. Conversion DOCX → chapitres

Implémentation PHP native (`ZipArchive` + `DOMDocument`/`XMLReader`), sans PHPWord.

### 2.1 Table de correspondance des styles

| Détection (document.xml) | Rendu HTML | Classe / balise |
| --- | --- | --- |
| `w:pStyle="Titre1"` (« Chapitre N ») | **Frontière de chapitre** : ouvre un nouveau `yume_chapitre` | numéro extrait par regex `Chapitre\s*(\d+)` |
| `w:pStyle="Titre2"` immédiatement après un Titre1 | Sous-titre du chapitre (méta `sous_titre`) + `<h2 class="yn-subtitle">` | filet inférieur en CSS |
| `w:pStyle="Titre2"` isolé (« PostFace ») | Chapitre spécial sans numéro (`nature = postface`) | |
| `w:pStyle="Paragraphedeliste"` avec `numId` → puce « — » | `<p class="yn-dialogue">— …</p>` | tiret cadratin + espace insécable, retrait suspendu |
| `w:pStyle="Pense"` (Pensée) | `<p class="yn-thought">…</p>` | italique, retrait gauche |
| `w:jc="center"` | `class="yn-center"` | |
| Paragraphe vide | ignoré (max 1 conservé entre deux blocs) | |
| Run `<w:i/>` / `<w:b/>` / `<w:u/>` | `<em>` / `<strong>` / `<u>` | fusion des runs contigus |
| `<w:br/>` | `<br>` | |
| `w:type="page"` (saut de page) | ignoré | |
| Note de bas de page | `<sup class="yn-note">n</sup>` + liste en fin de chapitre | (absentes dans Grimgar, présentes ailleurs) |
| `<a:blip r:embed>` JPG/PNG/WebP | `<figure class="yn-illustration"><img …></figure>` ; fichier versé dans la médiathèque, rattaché au chapitre | redimension max 1600 px, WebP |
| `<a:blip>` EMF/WMF/EMZ/WMZ | **converti en PNG** quand le métafichier porte une image bitmap (`Metafichier` : plus grand bitmap de `EMR_STRETCHDIBITS`, `EMR_BITBLT`, `EMR_STRETCHBLT`, `EMR_SETDIBITSTODEVICE`… ou `META_STRETCHDIB`, `META_DIBSTRETCHBLT`, `META_DIBBITBLT` en WMF ; EMZ/WMZ décompressés) puis traité comme une image PNG (galerie ou position dans le chapitre, WebP) ; dessin purement vectoriel ou fichier invalide : **ignoré** avec sa raison dans le rapport d'import | DIB 1/4/8/16/24/32 bits `BI_RGB`/`BI_BITFIELDS` via GD ; `BI_JPEG`/`BI_PNG` extraits tels quels ; limites : 12 000 px de côté, 60 Mpx, largeur × hauteur × 4 ≤ 256 Mo, tailles déclarées confrontées à la taille réelle |
| `wp:docPr descr` / `title` (texte de remplacement Word) ou `alt` (EPUB) | Texte alternatif de l'image (`alt`) ; **vide** si c'est une description générée par Word (« Une image contenant… », « Le contenu généré par l'IA peut être incorrect », « Description générée automatiquement », équivalents anglais : `Texte::alt_automatique()`, motifs dans `Texte::ALT_AUTOMATIQUES`), cas comptés (`stats.alt_automatiques`) et signalés une fois dans le rapport | à la publication, le **titre** du média est toujours « Œuvre, Tome N, Chapitre N — illustration » ; son `alt` est celui du document s'il en a un, sinon ce libellé |
| Images avant le premier `Titre1` | Galerie du tome (`illustrations`), pas un chapitre | couverture, pages couleur |
| En-têtes / pieds de page / sections | ignorés | |

Autres règles : espaces insécables français conservés (`&nbsp;` avant `: ; ? ! »`), guillemets
typographiques inchangés, entités HTML échappées, aucun style inline conservé (tout passe par les
classes ci-dessus).

### 2.2 Résultat sur Grimgar T.7

19 chapitres + « PostFace », 1 453 dialogues, 307 pensées, 16 illustrations importées (dont 6 EMF
convertis en PNG : 2 dans la galerie, 4 dans les chapitres 11 et 19 ; avant la version 2.0.1 ces 6 EMF
étaient ignorés), 2 sauts de page ignorés. Un rapport d'import est joint à la publication (nombre de mots par chapitre,
avertissements) et l'éditeur peut **prévisualiser chaque chapitre** avant de publier.

### 2.3 Fichier comparé à un tome existant (ajout de chapitres)

Pour la publication chapitre par chapitre (formulaire « Ajouter des chapitres à un tome », contrat
§8), le fichier peut ne contenir qu'un chapitre, ou le tome avec ses chapitres déjà parus. Chaque
chapitre converti est rapproché du tome par nature + numéro (le prologue vaut 0) et comparé au
chapitre en ligne sur son **texte** : titre et contenu, illustrations, balises, commentaires de
blocs et entités retirés, toutes les espaces (insécables comprises) réduites à une
(`Service::texte_normalise()`). Une illustration changée ne rend donc pas un chapitre « modifié » ;
une phrase corrigée, si. L'empreinte de chaque chapitre est notée à sa création
(`_yume_empreinte_texte`) et recalculée depuis `post_content` pour les chapitres plus anciens ou
retouchés dans l'éditeur.

Un sous-chapitre (§2.4) est rapproché par nature, numéro du chapitre parent et rang de la partie
(clé `sous-chapitre:2-1#1`).

### 2.4 Sous-chapitres (chapitres publiés en parties, 2.8.0)

Certains romans (ex. *Miss Medic's Diary at War*, tome 2) ont des chapitres au style **Titre 1**
faits de parties au style **Titre 2** (entrées de journal datées), certains chapitres n'ayant
**aucun texte propre** (seulement leur titre, parfois une illustration).

- **Détection automatique inchangée** : un Titre 2 reste un intertitre (ou le sous-titre du chapitre
  s'il suit directement le Titre 1). Publier les parties séparément est un choix de l'équipe.
- **Découpage manuel** : nature « Sous-chapitre » dans le menu Nature de chaque ligne, et bouton
  rapide **« Couper aux Titre 2 (sous-chapitres) »** (affiché si le fichier a des Titre 2) : les
  titres de niveau 1 deviennent des chapitres (ou gardent leur nature : prologue, postface…), les
  Titre 2 des sous-chapitres, titres vides (ceux du fichier sont repris). Découpages rapides
  `Result::$decoupages['titres']` et `['sous_chapitres']` ; le Titre 2 lu comme sous-titre a lui
  aussi un repère (élément du document).
- **Marqueur** dans le document : paragraphe seul `[sous-chapitre] Titre` (aussi `[sous chapitre]`,
  `[souschapitre]`).
- **Rattachement** (`Chapter_Builder::numeroter()` et `terminer()`) : un sous-chapitre est une
  partie du dernier chapitre de nature `chapitre` qui le précède (ou d'un autre sous-chapitre du même
  parent) ; il en porte le numéro (`numero`), son rang (`sous_numero` : 1, 2…) et le titre
  (`chapitre_parent`) ; libellé « Chapitre 2, partie 1 ». Le parent **sans texte** (rien, ou
  seulement des illustrations) n'est pas un chapitre : il est retiré, son titre passe dans
  `chapitre_parent` de ses parties et ses illustrations au début de la première ; son repère reste
  coché dans le découpage. Le parent avec du texte avant sa première partie reste un chapitre
  ordinaire (N), suivi de ses parties (N, 1…n). Sous-chapitre placé au début, après un prologue, un
  interlude, un épilogue… : avertissement « Sous-chapitre « … » sans chapitre parent : traité comme
  un chapitre » et nature `chapitre`. Les numéros manquants ne sont pas signalés pour un parent
  retiré (sa première partie tient sa place).
- **Publication** : chaque partie est un `yume_chapitre` (page, date de sortie, annonce, compte dans
  `yume_nb_chapitres`) avec `yume_nature` = `sous-chapitre`, `yume_numero` = numéro du parent,
  `yume_sous_numero`, `yume_sous_titre`, `yume_chapitre_parent` ; titre « Chapitre 2, partie 1 —
  10 septembre, soir », adresse `…/chapitre-2-partie-1/` (jamais le numéro seul, réservé au
  chapitre), `menu_order` séquentiel dans l'ordre du fichier.
- **Façade** : sommaire du tome groupé (listes imbriquées : la ligne du chapitre parent, ou un simple
  intitulé « Chapitre 2 — titre du parent » quand il n'a pas de page, puis « Partie 1 · sous-titre ·
  durée ») ; en-tête de la partie : surtitre « Chapitre 2 — titre du parent », « Partie 1 » (h1) et
  son sous-titre ; précédent / suivant dans l'ordre de lecture.

Résultat sur le tome 2 de Miss Medic (55 432 mots, 18 Titre 1, 27 Titre 2), « Couper aux Titre 2 » :
37 chapitres publiés — 9 chapitres « Année 1938, été … », 27 parties de 8 chapitres sans texte
propre (« Bataille défensive de Mashdale », « Évacuation de Mashdale 1 »…) et la postface —, aucun
avertissement.

## 3. Conversion EPUB → chapitres

1. Lire `META-INF/container.xml` → OPF → `manifest` + `spine`.
2. Chaque entrée de `spine` devient un chapitre ; si un fichier XHTML contient plusieurs `<h1>`, découper.
3. Titre du chapitre : `<h1>` (ou entrée du `toc.ncx` / `nav.xhtml`), sous-titre : `<h2>` suivant.
4. Nettoyage HTML (liste blanche : `p, h2, h3, em, strong, br, figure, img, blockquote, hr, sup, ul, ol, li`),
   mapping des classes connues (`dialogue`, `pensee`, `center`) et heuristique : un paragraphe commençant
   par un tiret (`—`, `–`, `-`…, même précédé d'espaces insécables, cadratins, caractères invisibles,
   d'une ancre `<a id>`, placé dans un `<span>`, ou puce de liste Word convertie par Calibre :
   `<div class="block_7"><span class="bullet_">— </span>…</div>`) → `yn-dialogue` ; un paragraphe entièrement en
   italique, ou déclaré en italique par son style → `yn-thought` (italique inversé) ; italique partiel
   → `<em>`. Les guillemets « » en tête de paragraphe ne font pas une réplique (même règle que le DOCX).
5. **Feuilles de style lues** (`Epub_Css`) : fichiers liés par `<link rel="stylesheet">` (et leurs
   `@import`) et éléments `<style>` du document. Les EPUB de Calibre, Sigil ou InDesign portent
   l'italique dans des classes génériques (`.calibre5`, `p.p1`, `span.char-style-override-3`) : on en
   extrait `font-style` (italic, oblique, normal), `font-weight`, le raccourci `font` et
   `text-align: center`, pour les sélecteurs simples (`.classe`, `p.classe`, `span.a.b`, `em`, `i`,
   listes séparées par des virgules) ; cascade par spécificité puis ordre, style en ligne prioritaire,
   héritage des conteneurs (`div`, `section`…). Commentaires retirés, `@media`/`@supports` aplatis
   (sauf `@media print`), autres règles `@` et sélecteurs complexes (descendants, attributs,
   pseudo-classes, `*`) ignorés ; feuilles externes (http) jamais chargées ; 1 Mo par feuille (au-delà :
   feuille ignorée avec un avertissement) et 20 000 règles au plus par document.
6. Images importées dans la médiathèque, `src` réécrits.
7. Pages liminaires (couverture, colophon, table des matières) exclues via le `guide`/`landmarks`.

## 4. Rendu du lecteur

### 4.1 Gabarit `/lire/{oeuvre}/{tome}/{n}/`

```
[Barre : ◀ Tome · Œuvre › Tome 7 › Chapitre 1     Σ Sommaire  ⚙ Paramètres  ☾ Thème  🔖 ]
[Barre de progression du chapitre]
        Chapitre 1                          ← h1, centré, Outfit
        La Crête Brumeuse                   ← h2 sous-titre, filet inférieur
        Traduction – X · Relecture – Y      ← crédits (méta)
        Texte justifié, colonne 68 car., interligne 1,6 par défaut
        — Dialogue…                         ← .yn-dialogue
            Pensée en italique…             ← .yn-thought
        [illustration pleine largeur]
[Fin : ◀ Chapitre précédent · Sommaire · Chapitre suivant ▶ | ★ Noter · 💬 Commenter]
```

Sans JavaScript, tout ce qui précède s'affiche. Le JS ajoute : panneau de réglages, marque-page,
raccourcis clavier (←/→ chapitres, `s` réglages), bascule de thème, sauvegarde de progression.

### 4.1 bis Page « Illustrations » `/lire/{oeuvre}/{tome}/illustrations/`

Les images placées **avant le premier chapitre** du DOCX / EPUB (`front_images`) forment la galerie du tome
(`yume_illustrations`, affichée sur la page du tome). Elles ont aussi leur page de lecture, avant le
chapitre 1, comme les planches couleur d'un light novel imprimé :

```
[Barre : ◀ Œuvre · Tome 7   Illustrations · 3 planches     Σ Sommaire  ☾ Thème  ⚙ Paramètres ]
        Œuvre › Tome 7 › Illustrations
        Illustrations                       ← h1
        Tome 7                              ← sous-titre
        [planche 1 pleine largeur]          ← taille large, clic : image en grand
        légende (s'il y en a une)
        [planche 2] …
[Fin :            Sommaire du tome · Commencer la lecture · Chapitre 1 ▶ ]
```

Page virtuelle (aucun contenu créé) : voir le contrat §3 (routage, visibilité, canonique, `noindex`),
§10 (blocs) et §14 (aucune position enregistrée). Le chapitre 1 a « ◀ Illustrations » comme précédent, le
sommaire du tome liste « Illustrations » en premier et « Commencer la lecture » y mène tant que le lecteur
n'a pas de position dans le tome. Un vrai chapitre de nature « Illustrations » (titre « Illustrations » ou
« Galerie » dans le DOCX) garde l'adresse : la page virtuelle n'est alors pas créée. Les réglages de lecture
(thème Nuit / Papier / Sépia, largeur de colonne, opacité du fond) s'appliquent comme sur un chapitre.

### 4.2 Paramètres de lecture (variables CSS)

| Réglage | Variable | Défaut | Plage |
| --- | --- | --- | --- |
| Taille | `--yn-size` | 18 px | 14–26 |
| Interligne | `--yn-lh` | 1,6 | 1,3–2,1 (« compact → large ») |
| Opacité du fond | `--yn-bg-alpha` | 0,93 | 0,6–1 (« transparent → opaque », l'illustration/bandeau de l'œuvre en arrière-plan) |
| Police | `--yn-font` | Literata (défaut « Avenir » remplacé par une police libre équivalente : Nunito Sans) | 13 choix : Avenir/Nunito Sans, Merriweather, Arial, Roboto, Calibri/Carlito, Times New Roman, Verdana, Georgia, Garamond/EB Garamond, Trebuchet MS, Courier New, Literata, OpenDyslexic (dyslexie ; aussi par la case « Police adaptée à la dyslexie » du groupe Accessibilité) |
| Thème | `data-yn-theme` | suit le site | nuit · papier · sépia |
| Largeur | `--yn-width` | 68 ch | 56–80 |

Persistance : `localStorage['yn.reglages']` pour tous ; `PUT /yume/v1/moi/reglages` pour les membres.
Boutons « Valider » (applique et enregistre) et « Réinitialiser par défaut ».

### 4.3 Marque-page

- Un `IntersectionObserver` suit le paragraphe visible le plus haut ; toutes les 10 s (ou au départ
  de la page) on enregistre `{chapitre_id, paragraphe, pourcentage}`.
- Visiteur : `localStorage` ; membre : table `progression` (une ligne par œuvre).
- À l'ouverture d'un chapitre déjà entamé : bandeau « Reprendre au paragraphe 42 ».
- Fiche œuvre / accueil / compte : carte « Reprendre la lecture — Tome 7, chapitre 3, 41 % ».

### 4.4 CSS du lecteur (extrait de `themes/yume/assets/css/reader.css`)

```css
.yn-reader{max-width:var(--yn-width,68ch);margin-inline:auto;font:var(--yn-size,18px)/var(--yn-lh,1.6) var(--yn-font,Literata,Georgia,serif);text-align:justify;hyphens:auto;-webkit-hyphens:auto;text-wrap:pretty;color:var(--yn-ink);background:color-mix(in srgb,var(--yn-paper) calc(var(--yn-bg-alpha,.93)*100%),transparent)}
.yn-reader h1{font-family:"Outfit",sans-serif;font-weight:800;text-align:center;font-size:2.2em;margin:0}
.yn-reader h2.yn-subtitle{text-align:center;font-weight:700;font-size:1.25em;border-bottom:1px solid var(--yn-rule);padding-bottom:.4em;margin:.4em 0 1.6em}
.yn-reader p{margin:0 0 .8em}
.yn-reader .yn-dialogue{padding-left:1.4em;text-indent:-1.4em}
.yn-reader .yn-thought{font-style:italic;padding-left:2.2em}
.yn-reader .yn-center{text-align:center}
.yn-reader figure.yn-illustration{margin:2em 0}
.yn-reader figure.yn-illustration img{width:100%;height:auto;border-radius:8px}
```
