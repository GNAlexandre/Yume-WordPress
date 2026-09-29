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
- Vague *n* : 3 + 2*n* Timeres, qui arrivent des deux côtés, de plus en plus vite. Types
  **provisoires** (dessinés en code, en attendant de vrais sprites) : rampant (2 PV, 10 points),
  sauteur (1 PV, 15 points), volant (dès la vague 3, 1 PV, 20 points). Bonus de 50 × *n* à la fin de
  chaque vague.
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
(`jeu.js`, `jeu.css`) et la planche (`chtholly.png`, `chtholly.json`) ne sont téléchargés qu'à la
première ouverture. La configuration `window.ynWordEnd` est identique pour tous les visiteurs (aucune
incidence sur Batcache).

## Filtres

| Filtre | Défaut | Effet |
| --- | --- | --- |
| `yume_wordend_actif` | `true` | `false` coupe tout : déclencheur non chargé, pas de papillon |
| `yume_wordend_oeuvres` | `['sukasuka']` | Slugs des œuvres dont la fiche affiche le papillon |

## Planche de Chtholly

Source : `tools/wordend/source/chtholly-planche-gemini.jpg`, planche générée par Gemini (fond
« transparent » dessiné en damier, titres par ligne). `tools/wordend/decouper-planche.py` la détoure et
écrit `includes/wordend/assets/chtholly.png` (256 couleurs) et `chtholly.json` :

```json
{ "version": 1, "echelle": 2, "planche": [808, 1050],
  "animations": { "marche": { "ips": 10, "boucle": true, "images": [[x, y, l, h, ancreX, ancreY], …] }, … } }
```

Une ligne par animation (repos 2 images, marche 6, course 5, attaque 4, charge 4, dégâts 1, mort 1) ;
l'ancre de chaque image est le milieu du buste au niveau des bottes, pour que l'épée ne décale pas le
personnage. `echelle` : la planche est dessinée pour un écran en 2× (Chtholly debout = 144 px dans la
planche, 72 px logiques sur l'écran de 480 × 270). `attaque.coup` liste les images où l'épée touche,
`charge.onde` l'image qui lance l'onde. Voir `tools/wordend/README.md` pour régénérer la planche.

## Tests

- `tools/localenv/test.sh wordend` : fichiers livrés, cohérence de la planche et du JSON, URLs
  versionnées, déclencheur en façade (defer, configuration avant le script), filtres, papillon.
- Parcours manuel : `tools/localenv/serve.sh`, code Konami sur l'accueil dans les trois thèmes ;
  Tab reste dans la modale ; Échap ferme et rend le focus ; onglet masqué → pause.

## Limites de la v1

- Timeres provisoires dessinés en code : remplacer `dessinerTimere()` de `jeu.js` par des sprites
  quand ils existeront (même principe que la planche de Chtholly).
- Pas de son.
- La planche vient d'une image générée : les bords extérieurs très clairs de l'onde de la charge
  magique sont légèrement rognés par le détourage.
