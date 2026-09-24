# Design — Yume Novel v2

## Direction : « Nocturne » + « Papier »

La v2 reprend le système visuel **Angelith** (outillage de traduction Yume, dérivé de la charte
Infitex « Nocturne ») pour que le site public et les outils internes partagent une même identité :
bleu nuit, teal, titres Bricolage Grotesque. Le lecteur ajoute un thème **Papier** (clair) et **Sépia**.

Maquettes (canvas Design Claude) : *Yume Novel v2* — accueil, fiche œuvre, lecteur (bureau + mobile
avec panneau de paramètres), planning public, tableau de bord équipe, formulaire de publication,
compte lecteur.

## Tokens (theme.json → `settings.color.palette`, `settings.typography.fontFamilies`)

| Token | Nocturne (défaut) | Papier | Usage |
| --- | --- | --- | --- |
| `fond` | `#081721` | `#ffffff` | Fond de page |
| `bande` | `#0e2c3f` | `#f5f7f8` | En-tête, barres, pied de page |
| `fond-eleve` | `#133449` | `#ffffff` | Cartes, champs, panneaux (en nocturne : plus clair que la page) |
| `filet` | `#3e505b` | `#d2dade` | Séparateurs (1,3:1) |
| `bordure` | `#647d8d` | `#3b5364` | Bord d'un contrôle (3:1) |
| `texte-fort` | `#ffffff` | `#0a2f40` | Titres, valeurs |
| `texte` | `#dfe9ec` | `#0a2f40` | Courant |
| `texte-faible` | `#90a5b2` | `#3b5364` | Métadonnées |
| `surtitre` | `#7ba7ad` | `#3b5364` | Étiquettes mono en capitales |
| `accent` | `#7ba7ad` | `#0a2f40` | Bouton primaire, onglet actif (jamais de blanc sur teal) |
| `accent-texte` | `#0a2f40` | `#ffffff` | Texte sur l'accent |
| `selection` | `#1a3f56` | `#e8eff0` | Lavis de survol / sélection |
| `succes` / `avertissement` / `erreur` | `#7fca8d` / `#e5a94f` / `#f4796a` | `#2f7d5e` / `#8f5a1b` / `#a63328` | États du planning (à l'heure / en retard / bloqué) |
| `sepia-papier` / `sepia-encre` | `#f3ead8` / `#3b2f24` | — | Thème de lecture Sépia |

Typographie : **Bricolage Grotesque** 700/800 (titres), **IBM Plex Sans Condensed** 400/500/600 (interface),
**IBM Plex Mono** 500 (étiquettes), **Literata** (lecture par défaut, remplaçable par le lecteur).
Espacements : 4 / 8 / 12 / 16 / 24 / 32 px. Rayons : 4 px (contrôles), 8 px (cartes), 12 px (couvertures).

## Règles

- Le contraste se mesure (4,5:1 texte, 3:1 gros texte et contrôles) sur les trois surfaces `fond`, `bande`, `fond-eleve`.
- Un état ne se lit jamais à la seule couleur : pastille + libellé (« En retard ») + icône.
- L'accent teal n'occupe jamais plus de 10 % d'un écran ; les aplats sont réservés aux couvertures.
- Pas d'ombre au repos ; une carte se détache par son filet, une carte cliquable par un filet appuyé au survol.
