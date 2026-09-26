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
| `<a:blip>` EMF/WMF | **ignoré** (ornements Word non convertibles) ; consigné dans le rapport d'import | |
| Images avant le premier `Titre1` | Galerie du tome (`illustrations`), pas un chapitre | couverture, pages couleur |
| En-têtes / pieds de page / sections | ignorés | |

Autres règles : espaces insécables français conservés (`&nbsp;` avant `: ; ? ! »`), guillemets
typographiques inchangés, entités HTML échappées, aucun style inline conservé (tout passe par les
classes ci-dessus).

### 2.2 Résultat sur Grimgar T.7

19 chapitres + « PostFace », 1 453 dialogues, 307 pensées, 10 illustrations importées, 6 EMF ignorés,
2 sauts de page ignorés. Un rapport d'import est joint à la publication (nombre de mots par chapitre,
avertissements) et l'éditeur peut **prévisualiser chaque chapitre** avant de publier.

## 3. Conversion EPUB → chapitres

1. Lire `META-INF/container.xml` → OPF → `manifest` + `spine`.
2. Chaque entrée de `spine` devient un chapitre ; si un fichier XHTML contient plusieurs `<h1>`, découper.
3. Titre du chapitre : `<h1>` (ou entrée du `toc.ncx` / `nav.xhtml`), sous-titre : `<h2>` suivant.
4. Nettoyage HTML (liste blanche : `p, h2, h3, em, strong, br, figure, img, blockquote, hr, sup, ul, ol, li`),
   mapping des classes connues (`dialogue`, `pensee`, `center`) et heuristique : un paragraphe commençant
   par « — » → `yn-dialogue`, un paragraphe entièrement en italique → `yn-thought`.
5. Images importées dans la médiathèque, `src` réécrits.
6. Pages liminaires (couverture, colophon, table des matières) exclues via le `guide`/`landmarks`.

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

### 4.2 Paramètres de lecture (variables CSS)

| Réglage | Variable | Défaut | Plage |
| --- | --- | --- | --- |
| Taille | `--yn-size` | 18 px | 14–26 |
| Interligne | `--yn-lh` | 1,6 | 1,3–2,1 (« compact → large ») |
| Opacité du fond | `--yn-bg-alpha` | 0,93 | 0,6–1 (« transparent → opaque », l'illustration/bandeau de l'œuvre en arrière-plan) |
| Police | `--yn-font` | Literata (défaut « Avenir » remplacé par une police libre équivalente : Nunito Sans) | 12 choix : Avenir/Nunito Sans, Merriweather, Arial, Roboto, Calibri/Carlito, Times New Roman, Verdana, Georgia, Garamond/EB Garamond, Trebuchet MS, Courier New, Literata |
| Thème | `data-yn-theme` | suit le site | nocturne · papier · sépia |
| Largeur | `--yn-width` | 68 ch | 56–80 |

Persistance : `localStorage['yn.reglages']` pour tous ; `PUT /yume/v1/moi/reglages` pour les membres.
Boutons « Valider » (applique et enregistre) et « Réinitialiser par défaut ».

### 4.3 Marque-page

- Un `IntersectionObserver` suit le paragraphe visible le plus haut ; toutes les 10 s (ou au départ
  de la page) on enregistre `{chapitre_id, paragraphe, pourcentage}`.
- Visiteur : `localStorage` ; membre : table `progression` (une ligne par œuvre).
- À l'ouverture d'un chapitre déjà entamé : bandeau « Reprendre au paragraphe 42 ».
- Fiche œuvre / accueil / compte : carte « Reprendre la lecture — Tome 7, chapitre 3, 41 % ».

### 4.4 CSS du lecteur (extrait de `themes/yume/assets/reader.css`)

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
