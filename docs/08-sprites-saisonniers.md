# Sprites saisonniers — cahier des charges des images et plan d'intégration

Complément de [docs/07-themes-saisonniers.md](07-themes-saisonniers.md). Les variantes saisonnières
changent les couleurs, le logo et quelques particules dans l'en-tête ; pour donner plus de matière au
site, un **arbre décoratif** (celui du logo de chaque saison) est posé en fond, sur le côté de la page.

Les images sont générées par un modèle d'image (GPT) à partir des six logos, avec les consignes du §2,
puis intégrées dans le thème selon le §3. Rien n'est implémenté tant que les six images ne sont pas
validées.

## 1. Principe retenu

| Choix | Décision | Pourquoi |
| --- | --- | --- |
| Emplacement | **Côté droit**, calé en bas de la fenêtre (`position: fixed; right: 0; bottom: 0`), derrière le contenu | Le logo place l'arbre à droite ; une seule image par saison (le côté gauche, s'il est voulu plus tard, est un miroir CSS `scaleX(-1)`). |
| Une image par saison | 6 fichiers, **fond transparent**, même style aquarelle que les logos | Pas de variante Nuit / Papier : la couleur de la page passe derrière l'arbre grâce à la transparence ; un léger voile CSS adapte l'intensité. |
| Taille affichée | hauteur `min(78vh, 880px)`, largeur ≈ 0,55 × hauteur, soit **≈ 320 à 480 px de large** | Assez présent pour se voir, assez étroit pour ne pas manger la colonne de lecture. |
| Fondu | `mask-image` : fondu vers la gauche (vers le contenu) et vers le haut ; opacité 0,9 quand la marge libre dépasse 300 px, 0,35 (filigrane) sinon | La grille large fait 1344 px : sur un écran de 1440 px, l'arbre passe forcément sous le contenu ; il devient alors un filigrane qui ne gêne pas la lecture. |
| Écrans étroits | **aucun sprite sous 1024 px** (`@media`) : l'image n'est même pas téléchargée | Bande passante mobile et lisibilité. |
| Pages de lecture | **aucun sprite sur un chapitre** (`single-yume_chapitre`) ni dans l'espace équipe | Confort de lecture ; cohérent avec le thème Sépia inchangé. |
| Accessibilité | élément décoratif pur (`aria-hidden`, `pointer-events: none`), aucun mouvement (image fixe, les particules restent dans l'en-tête) ; retiré avec `prefers-contrast: more` et avec le contraste renforcé du lecteur (`data-yn-contraste`) | Un fond illustré sous du texte baisse le contraste perçu : on le retire pour qui demande plus de contraste. |
| Réglage | case **Arbre décoratif** dans *Réglages → Thème saisonnier* (`saison_sprite`, défaut vrai), à côté de **Décors animés** | Même logique que les particules : l'équipe peut couper sans release. |
| Cache | fichiers versionnés par empreinte (`?v=`), comme les logos | Un visuel remplacé est vu immédiatement. |

Alternatives écartées : arbre dans la bannière d'accueil seulement (trop discret, absent des autres
pages) ; image de fond de `body` répétée (lourd et illisible) ; sprite animé (mouvement permanent
dans le champ de vision pendant la lecture).

## 2. Cahier des charges des images (à donner au générateur)

### 2.1 Spécifications communes aux six fichiers

| Élément | Valeur |
| --- | --- |
| Sujet | **Uniquement l'arbre** du logo de la saison, avec son sol immédiat (herbe, neige, feuilles au pied). **Sans** le cercle, **sans** le livre ouvert, **sans** le texte « Yume Novel », sans ciel ni paysage derrière. |
| Cadrage | Format **portrait**, l'arbre occupe toute la hauteur ; **le tronc est collé au bord droit** de l'image (ou le déborde légèrement), les branches s'étendent vers la gauche. Le bas de l'image est le sol : il doit pouvoir toucher le bas de l'écran sans ligne de coupe visible. Le haut se termine par les branches, pas par une coupe nette. |
| Fond | **Transparent** (alpha). Aucune ombre portée sur le fond, aucun halo, aucun dégradé de fond. |
| Style | Même style que les logos : **aquarelle numérique** aux contours doux, couleurs de la saison, légère texture papier dans les aplats ; pas de trait noir épais, pas de rendu 3D, pas de photo. |
| Dimensions demandées | **1024 × 1536 px** (portrait) ; ou 2048 × 3072 si le modèle le permet. |
| Format de sortie | **PNG 32 bits avec transparence**. (Le dépôt convertit ensuite en WebP et AVIF, ≤ 120 Ko affichés.) |
| Nom de fichier attendu | `arbre-<slug>.png` : `arbre-printemps.png`, `arbre-ete.png`, `arbre-automne.png`, `arbre-halloween.png`, `arbre-hiver.png`, `arbre-noel.png`. |
| Cohérence | Les six arbres ont la **même silhouette générale** (même essence, même implantation du tronc, même hauteur), seuls le feuillage, les couleurs et les accessoires changent : on doit reconnaître « l'arbre de Yume » d'une saison à l'autre. |
| Interdits | Aucun texte, aucun logo, aucun personnage, aucun cadre, aucune signature ; pas de bord blanc ou de liseré autour des formes (vérifier la transparence réelle, pas un blanc simulé). |

### 2.2 Prompt de base (à coller tel quel, puis compléter par la ligne de la saison)

> Illustration en aquarelle numérique, fond entièrement transparent (PNG alpha), format portrait
> 1024 × 1536. Sujet : un seul arbre décoratif inspiré de l'image jointe (le logo Yume Novel), **sans**
> le cercle, **sans** le livre, **sans** aucun texte, **sans** ciel ni paysage. Le tronc est collé au
> bord droit de l'image et les branches s'étendent vers la gauche ; l'arbre occupe toute la hauteur ;
> le pied de l'arbre repose sur un petit sol (herbe, neige ou feuilles) qui touche le bas de l'image
> sans ligne de coupe ; le haut se termine naturellement par les branches. Contours doux, légère
> texture papier, pas de trait noir, pas de rendu 3D, pas d'ombre portée sur le fond, pas de halo ni
> de liseré clair autour des formes. Palette et ambiance de la saison : [LIGNE DE LA SAISON].

### 2.3 Ligne de chaque saison (image jointe : le logo de la saison)

| Slug | Image jointe | Ligne à ajouter au prompt |
| --- | --- | --- |
| `printemps` | logo printemps (cerisier, ciel violet et rose) | Cerisier en pleine floraison, fleurs rose pâle à rose vif, quelques pétales qui se détachent et tombent vers la gauche, branches sombres (violet très foncé #2a1240), sol d'herbe tendre avec quelques pétales. |
| `ete` | logo été (arbre vert, mer et ciel bleu) | Arbre feuillu vert vif et vert profond, feuillage dense et lumineux, quelques feuilles emportées par le vent vers la gauche, tronc brun chaud, sol d'herbe et de buissons verts ; lumière d'été franche. |
| `automne` | logo automne (érable rouge et orange, cascade) | Érable du Japon aux feuilles rouges, orange et or, feuilles qui tombent vers la gauche, tronc brun rouge, sol couvert de feuilles mortes orangées ; ambiance chaude. |
| `halloween` | logo Halloween (arbre nu, citrouilles, chauves-souris) | Arbre nu et tordu, branches noires anguleuses, une toile d'araignée entre deux branches, deux ou trois citrouilles éclairées au pied, quelques feuilles orange au sol, trois petites chauves-souris près des branches ; ambiance violet foncé et orange, douce et non effrayante (public adolescent). |
| `hiver` | logo hiver (arbre enneigé et sapins) | Grand arbre aux branches nues couvertes de neige, un petit sapin enneigé à sa gauche, sol de neige avec quelques brins d'herbe sèche, flocons discrets ; palette blanc, bleu glacier, brun froid. |
| `noel` | logo Noël (arbre enneigé décoré, sapin avec guirlande, houx) | Même arbre enneigé que l'hiver avec quelques boules rouges et dorées accrochées aux branches, un petit sapin décoré d'une guirlande lumineuse dorée et d'une étoile à sa gauche, une branche de houx avec baies rouges au pied ; palette blanc, vert sapin, rouge et or. |

Pour `noel`, demander explicitement de **reprendre l'arbre généré pour `hiver`** (joindre les deux
images : logo Noël et `arbre-hiver.png`) afin que la silhouette soit identique.

### 2.4 Contrôle de réception (avant de me transmettre les fichiers)

- [ ] Fond réellement transparent : ouvert sur un fond noir puis sur un fond blanc, aucun rectangle ni
      liseré n'apparaît autour de l'arbre.
- [ ] Tronc au bord droit, branches vers la gauche, sol en bas sans coupe franche.
- [ ] Aucun texte, cercle, livre, personnage ou cadre.
- [ ] Les six silhouettes se ressemblent (même arbre).
- [ ] Fichier PNG, 1024 × 1536 au moins, nommé `arbre-<slug>.png`.

## 3. Plan d'intégration (après réception des images)

| Étape | Contenu | Fichiers | Vérification |
| --- | --- | --- | --- |
| 1. Conversion | Rognage des marges transparentes, deux tailles (hauteur 900 et 1 400 px pour les écrans haute densité), WebP qualité 82 et AVIF, cible ≤ 120 Ko par fichier affiché | `wp-content/themes/yume/assets/img/sprites/arbre-<slug>[-2x].{webp,avif}` | poids, transparence |
| 2. Réglage | Champ `saison_sprite` (checkbox, défaut vrai, libellé **Arbre décoratif**, section Thème saisonnier) | `includes/saisons/reglages.php`, contrat §6 | `test-saisons` |
| 3. Thème | Élément `<div class="yn-sprite" aria-hidden="true">` injecté par PHP après l'en-tête (pas de JavaScript : image chargée par `background-image` dans `@media (min-width: 1024px)`, `image-set()` WebP/AVIF et densité) ; `position: fixed; right: 0; bottom: 0; z-index: 0; pointer-events: none` ; `mask-image` (fondu gauche et haut) ; opacité 0,9 au-delà de 1 900 px de large, 0,35 en dessous ; absent sur `single-yume_chapitre`, l'espace équipe, l'administration, et si `data-yn-contraste` ou `prefers-contrast: more` | `inc/saisons.php`, `assets/css/yume.css` | captures 1024 / 1280 / 1440 / 1920 en Nuit et Papier, pour chaque saison ; aucun débordement horizontal ; contraste du texte mesuré sur une zone traversée par le filigrane (≥ 4,5:1 conservé grâce à l'opacité 0,35) |
| 4. Contenu au-dessus | `.wp-site-blocks > *` en `position: relative; z-index: 1` pour que tout le contenu passe au-dessus du sprite | `assets/css/yume.css` | rendu CI inchangé sans saison |
| 5. Documentation | Guide de l'équipe (case **Arbre décoratif**), contrat §15, design/README, ce document | `docs/*`, `design/README.md` | DOCX régénéré |
| 6. Version | `2.7.0-dev.N`, CHANGELOG | `tools/build/version.php` | CI |

Charge estimée : une demi-journée une fois les images reçues. Risque principal : lisibilité du texte
qui passe sur le filigrane ; parade prévue : opacité basse et fondu vers le contenu, et la case de
réglage pour couper sans release.

## 4. Points à valider

1. Côté **droit** (comme dans le logo) : d'accord, ou plutôt à gauche, ou les deux (miroir) ?
2. Sous 1 900 px de large, l'arbre devient un **filigrane** à 35 % derrière le contenu ; alternative :
   le masquer complètement tant que la marge libre est inférieure à sa largeur (il ne se verrait alors
   que sur les très grands écrans).
3. Pas de sprite sur les **chapitres** (lecture) : à confirmer.
