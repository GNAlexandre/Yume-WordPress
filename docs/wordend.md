# Easter egg WordEnd — Chtholly – Bats-toi contre ton destin

Mini-jeu de plateformes 2D caché sur le site, clin d'œil à *SukaSuka* (« WordEnd ») : Chtholly, Nephren
et Ithea repoussent les Timeres sur cinq niveaux et un mode arcade sans fin. Module `wordend` de
l'extension Yume Core (`wp-content/plugins/yume-core/includes/wordend/`). Le moteur est piloté par des
**univers** décrits en JSON (personnages, ennemis, niveaux, décors, musiques) : SukaSuka est le premier,
d'autres peuvent s'ajouter sans toucher au code. Formats et interfaces : `docs/wordend-formats.md`.

## Ouvrir le jeu

| Moyen | Où |
| --- | --- |
| Code Konami au clavier : ↑ ↑ ↓ ↓ ← → ← → B A | N'importe quelle page publique (hors champ de saisie) |
| Appui long (1,2 s) sur la bascule de thème | Écrans tactiles, en-tête du site |
| Papillon bleu discret après le titre | Fiche des œuvres déclencheuses (défaut : `sukasuka`) ; ouvre l'univers de l'œuvre |
| `window.ynWordEnd.ouvrir( univers? )` | Console du navigateur, tests |

Le jeu s'ouvre sur l'univers demandé, sinon celui de la fiche d'œuvre affichée, sinon l'univers par défaut
(`sukasuka`). Parcours : écran titre → choix de l'univers (seulement s'il y en a plusieurs et qu'on
n'est pas sur la fiche d'une œuvre) → personnage → niveau → partie → pause, fin ou victoire.

## Commandes

En partie :

| Action | Clavier | Tactile |
| --- | --- | --- |
| Marcher | ← → ou Q/D (A/D en QWERTY) | ◀ ▶ |
| Courir | Maj maintenue | « Courir » (bascule) |
| Sauter | ↑, Z (W en QWERTY) ou Espace ; maintenir pour sauter plus haut ; nouvel appui en l'air : double saut (Nephren) | « Saut » |
| Descendre d'une plateforme | ↓ + saut (plateformes fines seulement) | ▼ maintenu + « Saut » |
| Action principale | J ou X | « Épée » (Chtholly, Nephren), « Tir » (Ithea) |
| Compétence | K ou C (un appui bref suffit pour la parade et la ruée) ; charge magique : maintenir 0,55 s puis relâcher | « Charge », « Parade », « Ruée » (maintenu pour la charge) |
| Pause / reprise | P, ou bouton « Pause » | bouton « Pause » |
| Retour aux niveaux | R (en pause, à la fin, sur les écrans), ou bouton « Niveaux » | bouton « Niveaux » |
| Couper / remettre la musique | M, ou bouton « Couper la musique » | même bouton |
| Volume de la musique | curseur « Volume » (flèches quand il a le focus) | curseur « Volume » |
| Mettre en pause puis fermer | Échap en partie met en pause ; Échap de nouveau (ou hors partie) ferme | × |

Écrans titre, sélection, pause, fin et victoire :

| Action | Clavier | Tactile, souris |
| --- | --- | --- |
| Choisir | ← → (↑ ↓ : ligne de la grille des niveaux) ; listes « Univers », « Personnage », « Niveau » sous l'écran | ◀ ▶ (▼ : ligne suivante) ; toucher ou cliquer une carte |
| Valider, commencer | Entrée, Espace, J ou X ; bouton « Jouer » / « Valider » | second appui sur la carte choisie ; toucher l'écran titre ; tout autre bouton de manette |
| Après une défaite | Entrée : rejouer ; R : niveaux | bouton « Rejouer », « Niveaux » |
| Après une victoire | Entrée : niveau suivant ; R : niveaux | toucher l'écran ; « Rejouer », « Niveaux » |
| Fermer | Échap | × |

L'aide sous l'écran et les boutons tactiles suivent le personnage choisi. Les manettes tactiles
n'apparaissent que sur les écrans tactiles (`pointer: coarse`) : déplacements (◀ ▶ ▼ Courir) calés à
gauche, Saut et compétences calés à droite, sur une seule rangée dès 360 px de large (sinon deux rangées,
chacune de son côté), cibles de 44 px au moins, `touch-action: none` ; en paysage sur un téléphone,
l'écran de jeu et les manettes tiennent ensemble.

## Personnages et compétences

Hauteurs de saut à la gravité par défaut (900 px/s²). PV : points de vie (cœurs du HUD).

| Personnage | PV | Marche / course | Saut | Action principale | Compétence | Débloqué |
| --- | --- | --- | --- | --- | --- | --- |
| Chtholly (Seniolis) | 5 | 72 / 138 px/s | 60,5 px | Coup d'épée : 1 dégât | Charge magique : maintenir 0,55 s, relâcher ; onde de 3 dégâts qui traverse les ennemis ; recharge 1,2 s | d'emblée |
| Nephren (Insania) | 5 | 66 / 128 px/s | 53 px, **double saut** | Coup d'Insania : 2 dégâts | Parade (0,6 s, recharge 2 s) : coups absorbés, attaquant repoussé, projectiles renvoyés | en finissant « La plage » |
| Ithea (Valgulious) | 4 | 78 / 150 px/s | 64 px | Dague lancée : 1 dégât, 300 px/s, toutes les 0,35 s | Ruée : 90 px en 0,2 s, invulnérable, recharge 1,5 s | en finissant « La falaise » |

Règles communes : après un coup reçu, 1,2 s d'invincibilité (le personnage clignote ; 2,5 s après un coup
du Timere géant) ; saut possible 0,08 s après avoir quitté un bord ; les compétences se lancent aussi en
l'air ; en l'air ou sur une plateforme, le coup d'épée porte aussi 32 px plus bas (un Timere au sol juste
sous une corniche basse reste frappable) ; la jauge sous les cœurs
montre la recharge de la compétence. Le dernier personnage choisi est mémorisé.

## Niveaux et objectifs

| Niveau | Terrain | Objectif | Étoiles (au-delà de la 1ʳᵉ, gagnée en finissant) |
| --- | --- | --- | --- |
| Arcade (mode libre) | un écran, sans plateforme | vagues sans fin (règles v1, ci-dessous) ; seul le record compte | — |
| 1. La plage | un écran, deux plateformes | 3 vagues de petits et normaux | 400 points ; 4 PV restants ou 75 s |
| 2. Les dunes | **deux écrans** (la caméra suit le joueur), trois plateformes | 4 vagues : coureurs, Timeres ailés, un grand | 800 points ; 3 PV ou 120 s |
| 3. La falaise | un écran, cinq corniches étagées | 3 vagues ; des cracheurs tirent depuis les corniches | 520 points ; 3 PV ou 100 s |
| 4. La nuit | un écran sous un voile nocturne | **survie** 75 s face à un flot croissant (cuirassés surtout) ; bonus 200 | 450 points ; 3 PV |
| 5. Le Timere géant | un écran, deux corniches | vaincre le **boss** (barre de vie en haut de l'écran) | 600 points ; 3 PV ou 90 s |

- Vagues : les ennemis arrivent juste hors de l'écran, du côté indiqué par le niveau (ou apparaissent sur
  une corniche) ; bonus de 50 × *n* à la fin de la vague *n*, 1 PV rendu entre deux vagues ; victoire
  une seconde après la dernière vague.
- Arcade (v1 à l'identique) : vague *n* de 3 + 2*n* Timeres, des deux côtés, de plus en plus vite
  (coureurs dès la vague 2, un grand à la fois dès la vague 3, normaux plus résistants dès la vague 6) ;
  bonus de 50 × *n* ; 1 PV rendu toutes les deux vagues.
- Plateformes fines (on y saute par-dessous, ↓ + saut pour en descendre) ; le moteur gère aussi des
  plateformes pleines (`type: "solide"`), inutilisées par les niveaux SukaSuka.
- La victoire affiche les étoiles gagnées ; Entrée lance le niveau suivant.

## Ennemis

Tous sont des Timeres (`ennemis/timere.json`), de huit types :

| Type | Taille | PV | Points | Comportement |
| --- | --- | --- | --- | --- |
| Petit | 0,8 | 1 | 10 | s'approche, mord |
| Normal | 1 | 2 | 15 | s'approche, mord ou fouette de son cou |
| Coureur | 0,9 | 1 | 20 | charge en courant, mord |
| Grand | 1,3 | 5 | 40 | fouet longue portée ; ne recule que sous un coup de plus d'un point (onde, Insania) |
| Timere ailé (volant) | 0,6 | 1 | 25 | ondule au-dessus du sol, plonge sur le joueur puis remonte |
| Timere cracheur (tireur) | 0,9 | 2 | 30 | garde ses distances, crache des projectiles ; mord au contact |
| Timere cuirassé (bouclier) | 1,1 | 3 | 35 | pare les coups de face ; le frapper dans le dos (il se retourne lentement) ou avec l'onde |
| Timere géant (boss) | 2 | 40 | 500 | ne recule jamais ; coups espacés (3–5 s) annoncés par 0,45 s de clignotement, 2,5 s de répit après un coup reçu ; charge traversante toutes les 9 s (0,8 s de clignotement) ; à mi-vie, accélère et appelle des petits Timeres |

Les morsures et coups de fouet ne touchent que sur les images « coup » de leur animation ; un ennemi
n'attaque pas depuis l'extérieur de l'écran.

## Étoiles, déblocages et sauvegarde

- Arcade toujours ouvert ; un niveau s'ouvre quand le précédent est fini ; Nephren et Ithea se débloquent
  en finissant « La plage » et « La falaise ». Les éléments verrouillés sont signalés (cadenas, carte en
  tirets, « verrouillé » dans les listes) et annoncés, jamais lancés. L'écran de victoire affiche et annonce
  ce qu'elle débloque (« Nouveau personnage : Nephren ! · Niveau débloqué : Les dunes »).
- Une étoile pour finir un niveau, une pour le score, une pour les PV restants ou le temps (critères du
  JSON du niveau) ; on garde le meilleur score et le maximum d'étoiles.
- Sauvegarde : `localStorage['yn.wordend']` (appareil seulement), format v2 par univers : parties, record
  arcade, record, étoiles et « fini » de chaque niveau, dernier personnage, volume et muet
  (`docs/wordend-formats.md` §6). L'ancien format v1 (record et parties de la v2.1.4) est migré à la
  lecture, sans perte : le record v1 devient le record arcade de SukaSuka.

## Accessibilité

- Modale `<dialog>` ouverte avec `showModal()` : le reste de la page est inerte, Tab reste dans le jeu.
  Échap met une partie en cours en pause (rien n'est perdu par mégarde), un second Échap ferme ; à la
  fermeture, le focus revient à l'élément d'origine.
- Les écrans de sélection dessinés dans le canvas sont doublés par de vrais contrôles : listes `<select>`
  « Univers », « Personnage » et « Niveau » sous l'écran (synchronisées, entrées verrouillées désactivées,
  listes désactivées en partie) et bouton « Niveaux ». Vrais boutons (Jouer, Pause, Niveaux, Fermer,
  musique, commandes tactiles), zone de jeu `role="img"` avec un libellé.
- Annonces dans une région `role="status"` : écran courant et consigne, carte choisie (verrou,
  description, étoiles, record), vague, secondes restantes en survie, rage du boss, pause, fin de partie et
  victoire (avec les déblocages) ; la vie du boss est aussi un `<progress>` visuellement caché.
- Mouvement réduit (`prefers-reduced-motion` ou option « Animations réduites » du lecteur,
  `html[data-yn-animations="reduites"]`) : ni secousse, ni clignotement, pas de parallaxe ni de lissage de
  caméra, étoiles et bannières sans animation, moins de particules.
- Pause automatique quand l'onglet est masqué ou que la fenêtre perd le focus.
- Couleurs de l'interface (textes, jauges, plateformes, cartes) lues dans les variables du thème
  (Nuit, Papier, Sépia), mises à jour à l'événement `yn:theme` ; le décor est une image peinte, identique
  dans les trois thèmes. Les cartes verrouillées restent opaques, libellés en `texte-faible` (≥ 4,5:1).
  Le HUD (cœurs, score, record, vague, boss), dessiné sur le décor, garde les mêmes couleurs dans les trois
  thèmes : texte clair et cœurs cerclés d'un contour sombre, tailles relevées pour les téléphones.
- Musique jamais lancée sans action du joueur, coupable à tout moment (bouton à `aria-pressed`, touche
  M), volume réglable par un vrai curseur étiqueté ; réglages gardés sur l'appareil.

## Décor et musique

- **Décor** : `univers/sukasuka/decors/dunes.webp`, coucher de soleil sur des dunes, 960 × 540 (l'écran en
  2×), tiré de `tools/wordend/source/decor-source.webp`. Il défile au quart de la vitesse de la caméra
  (`parallaxe: 0.25`) et se répète en miroir dans les niveaux larges ; « La nuit » le couvre d'un voile
  bleu nuit. S'il ne se charge pas, le jeu dessine un ciel dégradé aux couleurs du thème. Les plateformes
  sont dessinées en code, aux couleurs du thème.
- **Musique** : `univers/sukasuka/musiques/scarborough.mp3` (« Scarborough Fair », 2 min 32, 192 kbit/s,
  3,6 Mo ; crédit affiché sur l'écran titre), musique de chaque niveau, en boucle, **seulement pendant une
  partie** : elle s'arrête en pause, à la fin de la partie, quand l'onglet est masqué ou la fenêtre du jeu
  fermée. Volume par défaut 50 %.

## Chargement et poids

- Sur chaque page publique : seulement `assets/declencheur.js` (8 Ko, 3 Ko compressé, `defer`) et sa
  configuration `window.ynWordEnd`, qui ne dépend que de l'URL (identique pour tous les visiteurs :
  aucune incidence sur Batcache).
- À la première ouverture : `jeu.css` et les 14 scripts du moteur (`assets/moteur/`, 198 Ko, 53 Ko
  compressés, insérés ensemble et exécutés dans l'ordre), le manifeste, Chtholly, le Timere, le niveau
  arcade et le décor : environ 530 Ko en tout.
- Aux écrans de sélection : les autres personnages (Nephren, Ithea : 300 Ko) et les JSON des niveaux.
- Au premier lancement d'une partie : la musique (3,6 Mo), pas du tout si elle est coupée. Un niveau
  ne charge que son propre décor.
- Un script qui échoue (réseau) annule le chargement ; la prochaine ouverture réessaie.

## Filtres et univers

| Filtre | Défaut | Effet |
| --- | --- | --- |
| `yume_wordend_actif` | `true` | `false` coupe tout : déclencheur non chargé, pas de papillon |
| `yume_wordend_univers` | `sukasuka` (`UNIVERS_INTEGRES`) | Registre des univers : `slug => {titre, oeuvre, dossier \| manifeste, version?}` ; vide : ni déclencheur ni papillon |
| `yume_wordend_oeuvres` | œuvres des univers | Œuvres supplémentaires dont la fiche affiche le papillon (qui ouvre alors l'univers par défaut) ; les œuvres des univers gardent toujours le leur |

Une entrée du registre est assainie (`docs/wordend-formats.md` §2) : slug `sanitize_title`, `titre`
(« WordEnd » s'il est vide), `oeuvre` (slug de l'œuvre dont la fiche ouvre cet univers), puis **soit**
`dossier` (univers livré dans `includes/wordend/assets/univers/<dossier>/`, un seul nom, manifeste
lisible), **soit** `manifeste` (URL http(s) du `manifeste.json` d'un univers fourni par une autre
extension) avec `version` facultative (ajoutée en `?ver=` à tous ses fichiers : la changer à chaque mise à
jour). Une entrée invalide est retirée.

Ajouter un univers depuis une autre extension :

```php
add_filter(
	'yume_wordend_univers',
	function ( array $univers ): array {
		$univers['grimgar'] = array(
			'titre'     => 'Grimgar',
			'oeuvre'    => 'grimgar-of-fantasy-and-ash',
			'manifeste' => plugins_url( 'wordend/grimgar/manifeste.json', __FILE__ ),
			'version'   => '1.0.0',
		);
		return $univers;
	}
);
```

Le dossier du manifeste contient ses `personnages/`, `ennemis/`, `niveaux/`, `decors/` et `musiques/`
(schémas : `docs/wordend-formats.md` §4) ; on le valide avec `tools/wordend/valider.py <dossier>`. Le
papillon de la fiche `grimgar-of-fantasy-and-ash` ouvre alors Grimgar ; ailleurs, l'écran « Univers »
propose les deux univers. Un univers intégré au dépôt s'ajoute à `UNIVERS_INTEGRES` (`univers.php`) avec
`dossier` : ses fichiers sont alors contrôlés par le test PHP et livrés dans l'archive.

## Planches de sprites et outillage

Outils dans `tools/wordend/` (détails : `tools/wordend/README.md`) :

- `decouper-planche.py` : découpe une planche générée (Gemini…) en planche de jeu (`<nom>.png` +
  `<nom>.planche.json`, format v1) d'après une description `tools/wordend/source/<nom>.planche.json` ;
  `--sortie` obligatoire ; `--variante <nom>` / `--toutes-variantes` produisent des planches recolorées de
  même géométrie. Les descriptions versionnées reproduisent à l'identique les planches du dépôt.
- `valider.py <dossier d'univers> [--strict]` : mêmes règles que le test PHP, sans WordPress.
- `banc/` : banc d'essai du moteur, visionneuse de planches, aperçu de niveau.

Planches de SukaSuka :

- **Chtholly** (`personnages/chtholly.*`) : source `chtholly-planche-gemini.jpg` (fond « transparent »
  dessiné en damier, titres par ligne). Repos 2 images, marche 6, course 5, attaque 4, charge 4, dégâts 1,
  mort 1 ; Chtholly debout = 144 px dans la planche. Ancre : milieu du buste au niveau des bottes, pour que
  l'épée ne décale pas le personnage. Pas d'animation de saut : poses figées tirées de la course.
- **Nephren**, **Ithea** : variantes `nephren` (cheveux gris-bleu) et `ithea` (cheveux blonds) de la
  description de Chtholly (seuls les bleus changent : cheveux, lame).
- **Timere** (`ennemis/timere.*`) : deux planches en pixel art, `timere-planche-verte.webp` (lignes repos 5,
  marche 4, fouet 4, morsure 4, gardées telles quelles : Timere au repos = 100 px, 50 px logiques à la
  taille 1) et `timere-planche-complement.webp` (course 6, dégâts 5, mort 6, ramenées à la même échelle,
  bords nets, titres retirés ; course alignée sur le bout de la tête). Ancre : milieu des pattes. Le jeu
  dessine chaque type à sa taille (±6 %), sans lissage, recoloré par `teinte` pour les quatre types v2.

Consignes pour de nouvelles planches générées : PNG à vraie transparence (pas de damier dessiné), profil
tourné vers la droite, une ligne par animation, images séparées d'au moins 20 px de vide, aucun titre
dans la grille, personnage de même hauteur sur toutes les lignes, pieds au sol ; style de la planche de
Chtholly (peint) ou du Timere (pixel art vert). Une animation `saut`, `chute` ou `parade` ajoutée à une
planche remplace la pose figée correspondante sans toucher au code.

## Tests

- `tools/localenv/test.sh wordend` : module et déclencheur en façade, fichiers livrés (moteur, univers
  intégrés, planches), cohérence des univers (manifeste, personnages, ennemis, niveaux), registre
  `yume_wordend_univers` (assainissement, œuvre ↔ univers), configuration (14 scripts dans l'ordre, URL
  versionnées), `universPage` selon la requête, coupure par `yume_wordend_actif` ou registre vide,
  papillon avec son univers.
- `python3 tools/wordend/valider.py wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka --strict`.
- Banc d'essai et vérificateurs Playwright (un serveur par script, depuis la racine du dépôt) :

  ```sh
  php -S 127.0.0.1:8081 -t . &   # puis http://127.0.0.1:8081/tools/wordend/banc/?niveau=01-plage&personnage=nephren
  node tools/wordend/banc/verifier-A.js [captures]                    # ressources, rendu, audio, sauvegarde (8081)
  php -S 127.0.0.1:8082 -t . & node tools/wordend/banc/verifier-B.js  # physique, saut, compétences, personnages
  php -S 127.0.0.1:8083 -t . & node tools/wordend/banc/verifier-C.js  # ennemis, projectiles, objectifs, niveaux
  php -S 127.0.0.1:8084 -t . & node tools/wordend/banc/verifier-D.js  # écrans, listes, manettes, Échap, axe-core, thèmes
  ```

  Arguments facultatifs : adresse et dossier des captures (`verifier-B.js`, `verifier-C.js`),
  `--port=` et `--captures=` (`verifier-D.js`) ; voir `docs/wordend-formats.md` §7.
- Parcours manuel : `tools/localenv/serve.sh`, code Konami sur l'accueil dans les trois thèmes ; papillon
  de la fiche `sukasuka` ; Tab reste dans la modale ; Échap en partie → pause, Échap de nouveau →
  fermeture et focus rendu ; onglet masqué → pause ; manettes en émulation tactile.

## Limites et éléments provisoires

- Planches **provisoires** : Nephren et Ithea sont Chtholly recolorée (même silhouette et mêmes
  animations : pas d'épée à deux mains ni de dague visible, parade et saut en poses figées) ; les Timeres
  ailé, cracheur, cuirassé et géant sont le Timere teinté à d'autres tailles (projectile et bouclier
  dessinés en code). À remplacer par de vraies planches (mêmes noms de fichiers, aucun code à changer).
- Un seul décor pour tous les niveaux (voile pour la nuit) ; plateformes dessinées en code, sans tuiles.
- Pas d'effets sonores (prévus en v2.2, `audio.effet` est prêt).
- **Droits de la musique** : la mélodie de « Scarborough Fair » est traditionnelle, mais l'enregistrement
  utilisé a un ayant droit : droits à confirmer avant toute diffusion large (sinon, le remplacer par une
  piste libre créditée dans `musiques.<nom>.credit`). 3,6 Mo : un réencodage à 128 kbit/s réduirait le
  poids d'un tiers.
- La planche de Chtholly vient d'une image générée : les bords extérieurs très clairs de l'onde de la
  charge magique sont légèrement rognés par le détourage.
