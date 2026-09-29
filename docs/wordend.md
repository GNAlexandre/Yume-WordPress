# Easter egg WordEnd — Chtholly contre les Timeres

Mini-jeu 2D caché sur le site, clin d'œil à *SukaSuka* (« WordEnd ») : Chtholly et son épée
Seniolis repoussent des vagues de Timeres. Module `wordend` de l'extension Yume Core
(`wp-content/plugins/yume-core/includes/wordend/`).

## Ouvrir le jeu

| Moyen | Où |
| --- | --- |
| Code Konami au clavier : ↑ ↑ ↓ ↓ ← → ← → B A | N'importe quelle page publique (hors champ de saisie) |
| Appui long (1,2 s) sur la bascule de thème | Écrans tactiles, en-tête du site |
| Papillon bleu discret après le titre | Fiche des œuvres listées par le filtre `yume_wordend_oeuvres` (défaut : `sukasuka`) |
| `window.ynWordEnd.ouvrir()` | Console du navigateur, tests |

## Commandes

| Action | Clavier | Tactile |
| --- | --- | --- |
| Marcher | ← → ou Q/D (A/D en QWERTY) | ◀ ▶ |
| Courir | Maj maintenue | « Courir » (bascule) |
| Coup d'épée | J ou X | « Épée » |
| Charge magique | K ou C maintenu (0,55 s) puis relâché : onde qui traverse les Timeres | « Charge » maintenu |
| Pause | P (ou bouton « Pause ») | bouton « Pause » |
| Commencer / rejouer | Entrée ou Espace | « Jouer » |
| Fermer | Échap ou × | × |

## Règles (v1)

- Chtholly a 5 PV. Un contact avec un Timere retire 1 PV, avec 1,2 s d'invincibilité ; 1 PV est
  rendu toutes les deux vagues terminées.
- Vague *n* : 3 + 2*n* Timeres, qui arrivent des deux côtés, de plus en plus vite. Ils s'approchent,
  puis mordent ou fouettent avec leur cou (la morsure et le fouet ne touchent que sur leurs images
  « coup ») ; bonus de 50 × *n* à la fin de chaque vague.

  | Type | Taille | PV | Attaques | Points |
  | --- | --- | --- | --- | --- |
  | Petit | 0,72 | 1 | morsure | 10 |
  | Normal | 0,95 | 2 | morsure, fouet | 15 |
  | Coureur (dès la vague 2) | 0,85 | 1 | charge en courant, morsure | 20 |
  | Grand (dès la vague 3, un à la fois) | 1,3 | 5 | fouet longue portée ; ne recule que sous l'onde | 40 |
- Coup d'épée : 1 dégât. Onde magique : 3 dégâts, traverse, recharge de 1,2 s.
- Meilleur score et nombre de parties : `localStorage['yn.wordend']` (appareil seulement).

## Accessibilité

- Modale `<dialog>` ouverte avec `showModal()` : le reste de la page est inerte, le focus reste dans
  le jeu, Échap ferme et le focus revient à l'élément d'origine.
- Vrais boutons (Jouer, Pause, Fermer, commandes tactiles), zone de jeu `role="img"` avec un libellé,
  annonces (vague, pause, fin de partie) dans une région `role="status"`.
- Mouvement réduit (`prefers-reduced-motion` ou option « Animations réduites » du lecteur,
  `html[data-yn-animations="reduites"]`) : ni secousse, ni clignotement, moins de particules.
- Pause automatique quand l'onglet est masqué ou que la fenêtre perd le focus.
- Couleurs du décor et de l'interface lues dans les variables du thème (Nuit, Papier, Sépia), mises à
  jour à l'événement `yn:theme`.

## Chargement et performances

Seul `assets/declencheur.js` (quelques Ko, `defer`) est chargé sur les pages publiques. Le jeu
(`jeu.js`, `jeu.css`) et les planches (`chtholly.png`/`.json`, `timere.png`/`.json`) ne sont
téléchargés qu'à la première ouverture. La configuration `window.ynWordEnd` est identique pour tous les visiteurs (aucune
incidence sur Batcache).

## Filtres

| Filtre | Défaut | Effet |
| --- | --- | --- |
| `yume_wordend_actif` | `true` | `false` coupe tout : déclencheur non chargé, pas de papillon |
| `yume_wordend_oeuvres` | `['sukasuka']` | Slugs des œuvres dont la fiche affiche le papillon |

## Planches de sprites

Les deux planches sont produites par `tools/wordend/decouper-planche.py` (voir
`tools/wordend/README.md`) et versionnées dans `includes/wordend/assets/` :

```json
{ "version": 1, "echelle": 2, "planche": [808, 1050],
  "animations": { "marche": { "ips": 10, "boucle": true, "images": [[x, y, l, h, ancreX, ancreY], …] }, … } }
```

`echelle` : la planche est dessinée pour un écran en 2× (px de planche par px logique de l'écran de
480 × 270). `coup` liste les images où une attaque touche, `charge.onde` l'image qui lance l'onde.

**Chtholly** (`chtholly.*`) : source `tools/wordend/source/chtholly-planche-gemini.jpg`, planche
générée par Gemini (fond « transparent » dessiné en damier, titres par ligne). Repos 2 images
(les deux vues de dos sont écartées), marche 6, course 5, attaque 4, charge 4, dégâts 1, mort 1 ;
Chtholly debout = 144 px dans la planche. L'ancre de chaque image est le milieu du buste au niveau
des bottes, pour que l'épée ne décale pas le personnage.

**Timere** (`timere.*`) : source `tools/wordend/source/timere-planche.webp` (fond noir uni). Les
sept animations (repos, marche, course, fouet, morsure, dégâts 7 images chacune, course 6) sont
redessinées en **pixel art vert** pour s'harmoniser avec la planche verte de référence : image
ramenée à la taille logique (56 px au repos), rampe de cinq verts selon la luminosité, contour sombre,
yeux jaunes, puis agrandissement au plus proche (1 pixel du dessin = 2 px de planche). Ancre : milieu
des pattes, au sol. Le jeu dessine le Timere à sa taille de type (0,72 à 1,3), sans lissage.

La planche verte de référence n'a pas pu être utilisée directement (pas de fichier disponible) : si
elle est fournie, ses lignes Repos, Marche, Attaque Fouet et Attaque Morsure pourront remplacer
celles de la planche restylée (les autres lignes de cette planche verte étaient incorrectes).

## Tests

- `tools/localenv/test.sh wordend` : fichiers livrés, cohérence des planches et des JSON, URLs
  versionnées, déclencheur en façade (defer, configuration avant le script), filtres, papillon.
- Parcours manuel : `tools/localenv/serve.sh`, code Konami sur l'accueil dans les trois thèmes ;
  Tab reste dans la modale ; Échap ferme et rend le focus ; onglet masqué → pause.

## Limites de la v1

- Pas de son.
- La planche vient d'une image générée : les bords extérieurs très clairs de l'onde de la charge
  magique sont légèrement rognés par le détourage.
