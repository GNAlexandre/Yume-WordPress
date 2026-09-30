# Outillage WordEnd (planches de sprites, validation, banc d'essai)

Outils ponctuels du jeu caché WordEnd (`docs/wordend.md`, formats : `docs/wordend-formats.md`). La CI
ne les exécute pas ; les PNG et JSON produits sont versionnés dans
`wp-content/plugins/yume-core/includes/wordend/assets/univers/<univers>/`.

| Outil | Rôle |
| --- | --- |
| `decouper-planche.py` | découpe une planche générée (Gemini…) en planche de jeu, d'après une description JSON |
| `valider.py` | valide un univers (manifeste, personnages, ennemis, niveaux, planches) sans WordPress |
| `banc/` | banc d'essai du moteur, visionneuse de planches, aperçu de niveau (`docs/wordend-formats.md` §7) |
| `source/` | images sources et descriptions `<nom>.planche.json` |

Prérequis : Python 3.9+ avec `pip install pillow numpy scipy`.

## Découper une planche

```sh
python3 tools/wordend/decouper-planche.py tools/wordend/source/chtholly.planche.json --sortie /tmp/planches
python3 tools/wordend/decouper-planche.py tools/wordend/source/*.planche.json --sortie /tmp/planches
```

Écrit `<sortie>/<nom>.png` (256 couleurs) et `<sortie>/<nom>.planche.json` (format v1,
`docs/wordend-formats.md` §4.5). `--sortie` est obligatoire : on produit d'abord ailleurs, on vérifie
(visionneuse du banc : `…/banc/?planche=personnages/chtholly`), puis on copie dans le dossier de
l'univers (`personnages/` ou `ennemis/`).

Les descriptions versionnées reproduisent **à l'identique** les planches du dépôt (JSON identique
octet pour octet, PNG sans différence de pixel) :

```sh
python3 tools/wordend/decouper-planche.py tools/wordend/source/chtholly.planche.json tools/wordend/source/timere.planche.json --sortie /tmp/x
cmp /tmp/x/chtholly.planche.json wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka/personnages/chtholly.planche.json
cmp /tmp/x/timere.planche.json wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka/ennemis/timere.planche.json
```

### Fichier de description `source/<nom>.planche.json`

```json
{
  "sortie": "chtholly",
  "echelle": 2,
  "hauteur": 144,
  "reference": ["repos", "marche"],
  "ancre": "buste",
  "sources": [
    { "fichier": "chtholly-planche-gemini.jpg", "fond": "damier",
      "damier": { "saturation": 14, "gris": [176, 214], "blanc": 232, "poche": 40, "clair": { "saturation": 18, "min": 165 }, "tailleMin": 120 },
      "decoupe": "composantes",
      "reglages": { "tailleImage": 2500, "marges": [12, 12, 12, 4], "satellites": { "saturation": 25, "taille": 60 } },
      "sombre": 75,
      "bandes": {
        "repos": { "zone": [80, 320, 0, 1411], "garder": [0, 1] },
        "mort": { "zone": [1730, 1990, 440, 900], "marges": [40, 90, 40, 4], "ancre": "centre", "sol": "sombre" } } } ],
  "rythmes": { "repos": { "ips": 2, "boucle": true }, "attaque": { "ips": 14, "boucle": false, "coup": [1, 2, 3] } },
  "ordre": ["repos", "marche", "course", "attaque", "charge", "degats", "mort"],
  "alignement": { "course": "tete" },
  "bordsNets": ["course", "degats", "mort"],
  "variantes": { "nephren": { "teinte": 0, "saturation": 0.25, "luminosite": 1.15, "plage": [170, 260], "saturationMin": 40 } }
}
```

Premier niveau :

| Champ | Rôle |
| --- | --- |
| `sortie` | nom des fichiers produits (défaut : nom de la description) |
| `echelle` | px de planche par px logique, recopié dans le JSON (défaut 2 : le jeu dessine en 2×) |
| `reference` | animations dont la médiane des ancres (y) donne la hauteur du sujet debout |
| `hauteur` | hauteur voulue (px de planche) pour cette référence ; absente : la première source qui contient ces animations garde son échelle (1) et fixe la hauteur des autres |
| `ancre` | ancre par défaut des sources (voir plus bas) |
| `sources` | une ou plusieurs images sources (les animations de toutes les sources forment une planche) |
| `rythmes` | par animation : `ips`, `boucle`, `coup` (images qui frappent), `onde` (image pendant l'onde)… recopiés tels quels, dans cet ordre |
| `ordre` | ordre des lignes de la planche (défaut : ordre des bandes) |
| `alignement` | `{animation: "tete"}` : aligne le bout de la tête (colonne la plus à droite de la moitié haute) à distance constante de l'ancre — quand les pattes bougent trop pour servir d'ancre |
| `bordsNets` | animations dont l'alpha est seuillé (0 ou 255) après mise à l'échelle (pixel art) |
| `variantes` | planches recolorées de même géométrie (voir plus bas) |

Par source :

| Champ | Rôle |
| --- | --- |
| `fichier` | image, relative au dossier de la description |
| `fond` | `damier` (damier dessiné par le générateur) ou `transparent` (vraie transparence) |
| `damier` | seuils du détourage : `saturation` (écart max entre canaux d'un pixel neutre), `gris` [min, max] et `blanc` (min) des cases, `beige` {min, saturation} (traits parasites clairs, facultatif), `poche` (taille min d'une poche de damier enfermée : gris ET blanc ≥ 15 %), `clair` {saturation, min} (cases collées au sujet retirées si elles touchent le fond, facultatif), `tailleMin` (taches retirées) |
| `seuilAlpha`, `titres` | `transparent` : pixel visible si alpha > `seuilAlpha` (128) ; `titres: true` retire les composantes à plus de 60 % de noir neutre (titres des lignes) |
| `decoupe` | `composantes` (grandes composantes ≥ `tailleImage` = images, fusionnées si elles se chevauchent ; éléments voisins dans la boîte élargie de `marges` [gauche, haut, droite, bas] rattachés s'ils sont grands ou colorés : `satellites` {saturation, taille}) ou `colonnes` (images séparées par des colonnes vides de plus de `ecartColonnes` px ; ignorées sous `tailleImage` px) |
| `reglages` | réglages de la découpe (défauts ci-dessus) |
| `ancre`, `sol`, `sombre` | ancre des images : `buste` (x = médiane des pixels sombres < `sombre` entre 40 et 80 % de la hauteur : l'épée ne décale pas le sujet), `pattes` (x = médiane du quart inférieur), `centre` (milieu) ; `sol` : `sombre` (bas des pixels sombres, bottes ; défaut de `buste`) ou `bas` (bas de l'image ; défaut sinon) |
| `referenceHauteur` | `[animation, indice]` : image de cette source mise à la hauteur de référence (quand la source n'a pas les animations de `reference`) |
| `bandes` | par animation : `zone` [haut, bas] ou [haut, bas, gauche, droite] en px de la source ; `garder` (indices des images gardées) ; `marges`, `ancre`, `sol` propres à la bande |

Mise à l'échelle : Lanczos en alpha prémultiplié (pas de liseré sombre) ; les ancres suivent.

### Variantes recolorées

Une variante produit `<variante>.png` + `<variante>.planche.json` (même JSON : même géométrie) :

```sh
python3 tools/wordend/decouper-planche.py tools/wordend/source/chtholly.planche.json --sortie /tmp/x --variante nephren
python3 tools/wordend/decouper-planche.py tools/wordend/source/chtholly.planche.json --sortie /tmp/x --toutes-variantes
python3 tools/wordend/decouper-planche.py tools/wordend/source/timere.planche.json --sortie /tmp/x --variante timere-rouge=teinte:-90,plage:60/160
```

Réglages : `teinte` (rotation en degrés), `saturation` et `luminosite` (facteurs), `plage` [min, max]
(teintes d'origine touchées, en degrés ; intervalle circulaire si min > max ; défaut : toutes),
`saturationMin` (0…255 : les pixels peu saturés — peau, uniforme noir — ne bougent pas). Dans
`chtholly.planche.json`, `nephren` (cheveux gris-bleu) et `ithea` (cheveux blonds) ne touchent que les
bleus (cheveux, lame) : planches **provisoires** en attendant de vraies planches.

### Nouvelle planche

Mesurer les bandes (haut, bas, et gauche/droite si plusieurs animations partagent une ligne) dans un
éditeur d'image, écrire la description (partir de la plus proche : `chtholly` pour un personnage
peint sur damier, `timere` pour du pixel art), lancer le script, vérifier dans la visionneuse du
banc, ajuster `garder`, `marges`, `ancre`, seuils. Consignes aux générateurs d'images : voir le plan
WordEnd v2 (§7) : vraie transparence, profil vers la droite, une ligne par animation, pas de titre
dans la grille.

## Valider un univers

```sh
python3 tools/wordend/valider.py wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka
python3 tools/wordend/valider.py wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka --strict
```

Mêmes règles que le test PHP (`docs/wordend-formats.md` §5) : manifeste v2, slugs = noms de fichiers,
fichiers référencés (décors, musiques, planches) présents, planches (dimensions du PNG, cadres dans
l'image, ancres dans leur cadre, `coup`/`onde` existants), `poses` et animations des compétences,
types de compétences, comportements et objectifs connus, attaques des types déclarées et animées,
invocations résolues, `decor`/`musique`/`suivant` résolus, plateformes dans `[0, largeur]`, ennemis et
types des vagues, du générateur de survie et du boss résolus. Un personnage (autre que le premier)
ou un niveau (autre que celui par défaut) listé mais absent est un avertissement (toléré par le
moteur) ; `--strict` en fait une erreur. Code de sortie 1 en cas d'erreur, messages en français.
