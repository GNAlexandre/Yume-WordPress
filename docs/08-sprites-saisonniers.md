# Sprites saisonniers — cahier des charges des images et plan d'intégration

Complément de [docs/07-themes-saisonniers.md](07-themes-saisonniers.md). Les variantes saisonnières
changent les couleurs, le logo et quelques particules dans l'en-tête ; pour donner plus de matière au
site, un **arbre décoratif** (celui du logo de chaque saison) est posé en fond, sur le côté de la page.

Les images sont générées par un modèle d'image (GPT) à partir des six logos, avec les consignes du §2,
puis intégrées dans le thème selon le §3. **Intégré en 2.7.0** (lot D) avec cinq arbres sur six :
l'arbre d'automne reste à déposer (§3, « Reste à faire ») ; tant qu'il manque, aucun arbre n'est
affiché en automne.

## 1. Principe retenu

| Choix | Décision | Pourquoi |
| --- | --- | --- |
| Emplacement | **Côté droit**, calé en bas de la fenêtre (`position: fixed; right: 0; bottom: 0`), derrière le contenu | Le logo place l'arbre à droite ; une seule image par saison (le côté gauche, s'il est voulu plus tard, est un miroir CSS `scaleX(-1)`). |
| Une image par saison | 6 fichiers, **fond transparent**, même style aquarelle que les logos | Pas de variante Nuit / Papier : la couleur de la page passe derrière l'arbre grâce à la transparence ; un léger voile CSS adapte l'intensité. |
| Taille affichée | hauteur `min(78vh, 880px)`, boîte de 0,67 × hauteur (l'arbre rogné fait ≈ 0,62 × hauteur), soit **≈ 340 à 550 px de large** | Assez présent pour se voir, assez étroit pour ne pas manger la colonne de lecture. |
| Fondu | `mask-image` : fondu vers la gauche (vers le contenu) ; **filigrane à 30 %** sous 1 900 px de large, **0,9** au-delà, la boîte étant alors limitée à la marge libre à droite de la grille large (l'arbre est rogné à gauche plutôt que de passer sous le texte) | La grille large fait 1344 px : sur un écran de 1440 px, l'arbre passe forcément sous le contenu ; il devient alors un filigrane qui ne gêne pas la lecture. Le haut de l'arbre se termine par des branches : pas de fondu vertical. |
| Écrans étroits | **aucun sprite sous 1024 px** (`@media`) : l'image n'est même pas téléchargée | Bande passante mobile et lisibilité. |
| Pages de lecture | **aucun sprite sur un chapitre** (`single-yume_chapitre`, lecteur MangaDex compris), sur la page « Illustrations » d'un tome, ni dans l'espace équipe (pages `equipe`, `publier`, `membres`) | Confort de lecture ; cohérent avec le thème Sépia inchangé (l'arbre est aussi retiré sous Sépia, quelle que soit la page). |
| Accessibilité | élément décoratif pur (`aria-hidden`, `pointer-events: none`), aucun mouvement (image fixe, les particules restent dans l'en-tête) ; retiré avec `prefers-contrast: more`, avec le contraste renforcé du lecteur (`data-yn-contraste`), en couleurs forcées et à l'impression | Un fond illustré sous du texte baisse le contraste perçu : on le retire pour qui demande plus de contraste. |
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

## 3. Intégration (réalisée en 2.7.0, lot D)

| Étape | Réalisé | Fichiers | Vérification |
| --- | --- | --- | --- |
| 1. Conversion | Marges transparentes rognées (alpha > 4), deux tailles : hauteur 900 px (1x) et 1 400 px (2x) ; WebP (méthode 6, alpha qualité 40 à 60, qualité 70 à 82 selon le poids) et AVIF (qualité 55, servi en premier). Poids 1x : WebP 96 à 124 Ko, AVIF 65 à 99 Ko ; 2x : WebP 180 à 245 Ko, AVIF 120 à 171 Ko | `wp-content/themes/yume/assets/img/sprites/arbre-<slug>[-2x].{webp,avif}` (embarqués par `tools/build/zip.sh`) | poids, transparence (contrôle sur fond Nuit et Papier) |
| 2. Réglage | Case **Arbre décoratif** (`saison_sprite`, défaut vrai) dans *Réglages → Thème saisonnier*, après **Décors animés** | `includes/saisons/reglages.php`, contrat §6 | `test-saisons` |
| 3. Thème | `<div class="yn-sprite" aria-hidden="true">` imprimé sur `wp_body_open` (canevas des thèmes blocs) avec deux variables CSS dans son attribut `style` : `--yn-sprite-1x` (WebP 1x, repli) et `--yn-sprite` (`image-set()` AVIF puis WebP, 1x et 2x, avec `type()`), URL versionnées (`?v=`, `yume_theme_version_fichier()`). Rien n'est imprimé sans saison, si `saison_sprite` est faux, sur une page exclue ou si le fichier WebP 1x de la saison manque (`file_exists` mémorisé pour la requête : pas de 404). `background-image` déclaré seulement dans `@media (min-width: 1024px)` ; opacité 0,3 sous 1 900 px, 0,9 au-delà (boîte limitée à la marge libre) ; retiré par `prefers-contrast: more`, `html[data-yn-contraste]`, `forced-colors` et l'impression. Pas de JavaScript | `inc/saisons.php`, `assets/css/yume.css` (section 1 bis) | captures 1024 / 1440 / 1920 (printemps et Halloween en Nuit, Noël et hiver en Papier), chapitre à 1440, accueil à 390 sans requête vers `assets/img/sprites` ; aucun débordement horizontal ; contraste mesuré (ci-dessous) |
| 4. Contenu au-dessus | `body.yn-sprite-actif .wp-site-blocks { position: relative; z-index: 1 }`, seulement au-delà de 1 024 px et seulement quand l'arbre est affiché : l'empilement interne (en-tête `z-index: 50`, sous-menus, menu mobile, suggestions de recherche) est inchangé, les pages sans arbre aussi | `assets/css/yume.css` | sous-menu ouvert à 1440, menu mobile ouvert à 1024 : identiques ; rendu CI inchangé sans saison |
| 5. Documentation | Guide de l'équipe (case **Arbre décoratif**), contrat §6 et §15, design/README, ce document | `docs/*`, `design/README.md` | DOCX régénéré |
| 6. Version | à faire par l'intégrateur (`2.7.0-dev.N`, CHANGELOG) | `tools/build/version.php` | CI |

**Contraste.** Le texte courant (`texte` sur `fond`) garde au moins 4,5:1 sur le **pire pixel** des cinq
arbres livrés, composés à l'opacité du filigrane sur le fond de la saison : 4,97:1 au plus bas (Noël
Nuit, neige), 6,4:1 en Papier. À 0,35 (valeur prévue), Noël, hiver et été en Nuit tombaient à 4,2-4,3:1 :
l'opacité a été ramenée à **0,3**. Les liens (`accent`, 5:1 seulement sur `fond` en Papier) et le texte
secondaire (`texte-faible`) qui passent sur une branche descendent nettement plus bas (jusqu'à 2,4:1
pour un lien en Papier) : c'est la limite du filigrane sous la colonne de contenu ; la case de réglage
permet de couper l'arbre sans release. Au-delà de 1 900 px, l'arbre ne passe plus sous le texte.

**Reste à faire.** Déposer `arbre-automne` : convertir le PNG reçu avec les mêmes réglages (rognage,
900 et 1 400 px de haut, WebP et AVIF) dans `assets/img/sprites/` ; aucun code à changer.

## 4. Décisions (2026-10-30)

1. Arbre **à droite** (comme dans le logo), calé en bas de la fenêtre, derrière le contenu.
2. **Filigrane** sous 1 900 px de large (prévu à 35 %, ramené à 30 % pour le contraste du texte),
   **90 %** au-delà ; rien sous 1 024 px (image non téléchargée).
3. **Aucun arbre** sur les pages de lecture (chapitres, lecteur MangaDex, illustrations d'un tome),
   dans l'espace équipe et le formulaire de publication, ni dans l'administration.
4. Retiré pour qui demande plus de contraste (`prefers-contrast: more`, contraste renforcé du lecteur).
5. Case **Arbre décoratif** (`saison_sprite`, défaut vrai) après **Décors animés**.
