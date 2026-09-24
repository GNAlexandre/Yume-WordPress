# Design — Yume Novel v2

## Direction : « Crépuscule sakura »

La v2 reprend l'identité déjà présente sur Yume Novel : le logo (livre ouvert sous un cercle de
cerisier au coucher du soleil), la bannière « Un nouvel élan pour Yume » et les sommaires des tomes
(fond blanc, typographie géométrique légère). Le site est **sombre par défaut** (nuit violette,
pétales roses, touche pêche du soleil couchant) avec un thème de lecture **Papier** clair et un thème
**Sépia**.

La page d'accueil est une **bibliothèque** (inspiration J-Garden) : bannière des sorties, grille de
couvertures « Dernières sorties » avec Lire / PDF / EPUB, bandeau reprise de lecture + planning, grille
de toutes les œuvres filtrable, actualités en cartes illustrées.

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
