# Design — Yume Novel v2

## Direction : « Crépuscule sakura »

La v2 reprend l'identité déjà présente sur Yume Novel : le logo (livre ouvert sous un cercle de
cerisier au coucher du soleil), la bannière « Un nouvel élan pour Yume » et les sommaires des tomes
(fond blanc, typographie géométrique légère). Le site est **sombre par défaut** (nuit violette,
pétales roses, touche pêche du soleil couchant) avec un thème de lecture **Papier** clair et un thème
**Sépia**.

La page d'accueil reste **légère** (retour de l'équipe) : bannière actuelle du site conservée en haut,
grille de couvertures « Dernières sorties » avec Lire / PDF / EPUB, deux cartes compactes (prochaines
sorties, actualités), bandeau reprise de lecture. La bibliothèque complète vit dans un **menu déroulant**
du header (LN, WN, Manga, en cours, terminées, licenciées, A → Z) et sur sa propre page.

Maquettes (canvas Design Claude) : *Yume Novel v2* — accueil, fiche œuvre, lecteur (bureau + mobile
avec panneau de paramètres), planning public, tableau de bord équipe, formulaire de publication,
compte lecteur. Sources dans `design/maquettes/`.

## Tokens (theme.json → `settings.color.palette`, `settings.typography.fontFamilies`)

| Token | Nuit (défaut) | Papier | Usage |
| --- | --- | --- | --- |
| `fond` | `#1b1231` | `#fdf8fa` | Fond de page |
| `bande` | `#241740` | `#f6eef3` | En-tête, barres, pied de page |
| `carte` | `#2d1f4f` | `#ffffff` | Cartes, champs, panneaux (plus clair que la page en nuit) |
| `filet` | `#4a3b6e` | `#e8d9e2` | Séparateurs |
| `bordure` | `#7f6aa8` | `#8a6f9e` | Bord d'un contrôle (3:1) |
| `texte-fort` | `#fff8fb` | `#2a1240` | Titres, valeurs |
| `texte` | `#ebe3f2` | `#2a1240` | Courant |
| `texte-faible` | `#b7a9cc` | `#5e4a73` | Métadonnées |
| `accent` (sakura) | `#f3a6c8` | `#c2437e` | Bouton primaire, badge « Nouveau », onglet actif |
| `accent-texte` | `#2a1240` | `#ffffff` | Texte sur l'accent |
| `accent-2` (pêche) | `#f7c59f` | `#b8642a` | Ko-fi, surtitres de la bannière, étoiles |
| `selection` | `#3a2a63` | `#f3e6ee` | Lavis de survol / sélection |
| `succes` / `avertissement` / `erreur` | `#8fd6a3` / `#f4c069` / `#ff8f7e` | `#2f7d5e` / `#8f5a1b` / `#a63328` | États du planning |
| `sepia-papier` / `sepia-encre` | `#f6e7ec` / `#3a2233` | — | Thème de lecture Sépia |
| Dégradé couverture de substitution | `160deg #5b3a7a → #2d1f4f → #c2437e` | — | En attendant la vraie couverture |

Typographie : **Outfit** 600/700/800 (titres, proche des sommaires des tomes), **Nunito Sans** 400/600/700
(interface), **IBM Plex Mono** 500 (étiquettes), **Literata** (lecture par défaut, remplaçable par le lecteur).
Espacements : 4 / 8 / 12 / 16 / 24 / 32 px. Rayons : 6 px (contrôles), 10 px (cartes et couvertures).

## Règles

- Contraste mesuré : 4,5:1 pour le texte, 3:1 pour les gros titres et les contrôles, sur les trois surfaces `fond`, `bande`, `carte`.
- Un état ne se lit jamais à la seule couleur : pastille + libellé (« En retard ») + icône.
- Le rose sakura reste un accent (boutons, badges, barres) : jamais en aplat de fond ; les grandes surfaces colorées sont réservées aux couvertures et à la bannière.
- Pas d'ombre au repos ; une carte se détache par son filet, une couverture cliquable s'éclaircit légèrement au survol.
- Les illustrations (bannière, cartes d'actualité) sont assombries par un dégradé pour garder le texte lisible.

## Variantes saisonnières (depuis 2.7.0)

Le site change d'habillage selon la période de l'année : **Printemps**, **Été**, **Automne**,
**Hiver**, et deux fêtes qui passent avant la saison, **Halloween** (octobre) et **Noël** (décembre).
Ce n'est pas un autre thème : c'est une **variante** de « Crépuscule sakura », posée par le serveur
(`<html data-yn-saison="<slug>">`) et programmée par les gérants dans les réglages. Cahier des
charges : [docs/07-themes-saisonniers.md](../docs/07-themes-saisonniers.md) ; règles techniques :
`docs/06-contrat-technique.md` §15.

- **Deuxième dimension** : la saison s'ajoute au thème choisi par le lecteur. En Nuit, palette
  sombre de la saison ; en Papier, palette claire ; en **Sépia**, rien ne change (thème de confort de
  lecture).
- **Printemps = la palette actuelle** (cerisiers au couchant) : seul le logo change.
- Sans saison active (thème de base) : palette actuelle et logo du site réglé dans WordPress.

| Saison | Nuit : fond / bande / carte / filet / bordure / accent / accent-texte / accent-2 / selection | Papier : fond / bande / carte / filet / bordure / accent / accent-texte / accent-2 / selection |
| --- | --- | --- |
| `ete` (ciel et mer) | `#0f1f33` / `#142a44` / `#1b3556` / `#2f4f78` / `#6f93c2` / `#7fd3ff` / `#0b1b2c` / `#ffd27a` / `#1f3f66` | `#f3f9fd` / `#e6f2fb` / `#ffffff` / `#cfe3f2` / `#5a86b0` / `#0f6fb3` / `#ffffff` / `#985f19` / `#e3f0fa` |
| `automne` (érables) | `#221410` / `#2d1a14` / `#3a231b` / `#5a3a2c` / `#a8705a` / `#ff9c4a` / `#2a1208` / `#ffd08a` / `#4a2b20` | `#fdf6ef` / `#f8ebdd` / `#ffffff` / `#ecd7c3` / `#9a6a4a` / `#ae4e19` / `#ffffff` / `#8a5a14` / `#f7e6d6` |
| `halloween` (nuit violette, citrouille) | `#170f26` / `#211537` / `#2b1b47` / `#4a3270` / `#8a66b8` / `#ff9a3c` / `#1b0f2a` / `#c9a3ff` / `#3a2360` | `#fbf5ff` / `#f3e8fb` / `#ffffff` / `#e3d3f0` / `#7b5aa3` / `#b24a0b` / `#ffffff` / `#6a3fb0` / `#f1e4f9` |
| `hiver` (neige, glace) | `#141c2b` / `#1b2537` / `#243247` / `#3b4d68` / `#7f98b8` / `#bfe3ff` / `#0f1b2a` / `#e8d9c4` / `#2c3d57` | `#f6f9fc` / `#e9f0f7` / `#ffffff` / `#d6e1ec` / `#647e98` / `#2f6ea5` / `#ffffff` / `#7a6248` / `#e6eef6` |
| `noel` (sapin, or, houx) | `#10211a` / `#162b22` / `#1e3a2e` / `#335a48` / `#7fae93` / `#f7c56b` / `#1b2a1f` / `#ff9c9c` / `#2a4d3b` | `#f6fbf7` / `#e7f3ea` / `#ffffff` / `#cfe4d5` / `#4f7f62` / `#b32d2d` / `#ffffff` / `#8a6414` / `#e4f1e8` |

`--yn-accent-survol` et `--yn-degrade-couverture` sont aussi redéfinis par saison (valeurs dans
`assets/css/yume.css`, section « 1 bis. Thèmes saisonniers », et dans `docs/06-contrat-technique.md` §15).
Trois couleurs Papier ont été assombries par rapport au cahier des charges pour tenir 4,5:1 aussi comme
couleur de texte : été `accent-2` #985f19, automne `accent` #ae4e19, halloween `accent` #b24a0b.

**Logos** : un logo par thème, `wp-content/themes/yume/assets/img/saisons/<slug>.webp` (WebP, 320 px
de large, affiché à 44 px dans l'en-tête), converti depuis les visuels fournis ; `printemps.webp` est
le logo actuel du site. Un logo se remplace par une release du thème (ce n'est pas une image de la
médiathèque).

**Décors** : quelques particules légères dans la bande d'en-tête seulement : pétales au printemps,
feuilles en automne, flocons en hiver et à Noël, chauves-souris à Halloween, rien en été. Douze
éléments au plus, qui n'interceptent jamais un clic et sont ignorés des lecteurs d'écran ; coupés
par la préférence système « réduire les animations », par l'option **Réduire les animations** du
lecteur et par le réglage du site. Sans JavaScript : aucun décor.

**Arbre décoratif** : l'arbre du logo de chaque saison, seul (sans cercle, livre ni texte), en
aquarelle sur fond transparent, est posé en fond de page, calé en bas à droite de la fenêtre,
derrière le contenu, avec un fondu vers la gauche (`wp-content/themes/yume/assets/img/sprites/
arbre-<slug>[-2x].{avif,webp}`, 900 et 1 400 px de haut ; cahier des charges et intégration :
[docs/08-sprites-saisonniers.md](../docs/08-sprites-saisonniers.md)). Hauteur `min(78vh, 880px)`.
Sous 1 900 px de large, il passe sous la colonne de contenu en **filigrane à 30 %** (le texte courant
garde 4,5:1) ; au-delà, il est affiché à 90 % dans la marge libre, sans passer sous le texte. Jamais
sous 1 024 px (image non téléchargée), sur les pages de lecture, dans l'espace équipe, pour qui
demande plus de contraste, ni à l'impression ; case **Arbre décoratif** dans les réglages. L'arbre
d'automne reste à livrer : en attendant, l'automne n'a pas d'arbre.

Règles des variantes :

- **Contrastes** mesurés comme pour la base : 4,5:1 pour `texte` sur `fond`, `bande` et `carte` ;
  3:1 pour `bordure` et pour `accent` sur `fond` ; 4,5:1 pour `accent-texte` sur `accent`.
- **Encre inchangée** : seules les surfaces (`fond`, `bande`, `carte`, `filet`, `bordure`,
  `selection`), l'accent (`accent`, `accent-texte`, `accent-2`, `--yn-accent-survol`) et le dégradé
  de couverture changent ; `texte`, `texte-fort`, `texte-faible` et les couleurs d'état (succès,
  avertissement, erreur) restent ceux de la base.
- **L'accent reste un accent** : la couleur de la saison (citrouille, or, ciel…) suit la règle du rose
  sakura (boutons, badges, barres), jamais en aplat de fond.
- Une saison ne touche ni la mise en page, ni la typographie, ni le thème Sépia.
