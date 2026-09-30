# WordEnd — formats et interfaces du moteur (v2)

Contrat interne du jeu caché WordEnd (module `wordend`, voir `docs/wordend.md`) : configuration passée par
PHP, interfaces JavaScript de `window.ynWordEndMoteur`, schémas JSON des univers, règles de validation,
format de sauvegarde et banc d'essai.

- `window.ynWordEndMoteur` (alias interne `ynWE`) est **privé** : non contractuel pour le site. L'API
  publique reste `window.ynWordEnd.ouvrir( univers? )`, `window.ynWordEndJeu`, l'événement `yn:wordend`
  et les filtres PHP (contrat §14).
- Toute évolution d'une interface ou d'un schéma décrit ici met à jour ce document dans le même commit
  (et le test PHP et `tools/wordend/valider.py` pour les schémas). `assets/moteur/00-espace.js` et
  `assets/moteur/jeu.js` sont les fondations : on n'y touche qu'avec prudence (tous les modules en
  dépendent).
- Chemins relatifs à `wp-content/plugins/yume-core/includes/wordend/` sauf mention contraire. Les
  valeurs entre parenthèses après un champ sont ses valeurs par défaut.

## 1. Fichiers et ordre de chargement

`declencheur.js` (façade, `defer`) charge à la première ouverture la feuille `config.style`, puis toutes
les URL de `config.scripts`, insérées ensemble dans l'ordre (`<script async=false>` : téléchargées en
parallèle, exécutées dans l'ordre). Le chargement est résolu quand `window.ynWordEndJeu` existe ; le
premier échec (réseau, 404) le rejette, retire les scripts de cette tentative et permet de réessayer à
l'ouverture suivante. L'ordre est la constante PHP `SCRIPTS_MOTEUR` (`fonctions.php`) ; le banc la
recopie (`tools/wordend/banc/banc.js`, tableau `SCRIPTS`).

| Ordre | Fichier `assets/moteur/` | Expose |
| --- | --- | --- |
| 1 | `00-espace.js` | `ynWE` : constantes, outils, événements, `creerMonde`, lecture des planches, `debug` |
| 2 | `stockage.js` | `ynWE.stockage` (sauvegarde v2, migration v1) |
| 3 | `ressources.js` | `ynWE.ressources` (univers, personnages, ennemis, niveaux, décors, teintes) |
| 4 | `audio.js` | `ynWE.audio` (musique) |
| 5 | `rendu.js` | `ynWE.rendu` (canvas, caméra, décor, plateformes, sprites, particules) |
| 6 | `physique.js` | `ynWE.physique` (gravité, sol, plateformes) |
| 7 | `competences.js` | `ynWE.competences` (melee, onde, projectile, ruee, parade) |
| 8 | `joueur.js` | `ynWE.joueur` (joueur générique) |
| 9 | `ennemis.js` | `ynWE.ennemis` (ennemi générique, comportements, projectiles) |
| 10 | `niveau.js` | `ynWE.niveau` (partie, objectifs, étoiles) |
| 11 | `entrees.js` | `ynWE.entrees` (clavier, manettes tactiles) |
| 12 | `ecrans.js` | `ynWE.ecrans` (machine d'écrans, HUD) |
| 13 | `modale.js` | `ynWE.modale` (`<dialog>`, boucle, orchestration) |
| 14 | `jeu.js` | `window.ynWordEndJeu = ynWE.modale` |

Chaque fichier est une IIFE ES2019 (`var`, ni `class`, ni `async`/`await`, ni module ES) qui lit
`var ynWE = window.ynWordEndMoteur;` et ajoute **sa** clé. Un module n'en utilise un autre qu'à
l'exécution (dans une fonction), jamais au chargement, sauf `00-espace.js` (toujours déjà là).

Autres fichiers : univers `assets/univers/<slug>/` (`manifeste.json`, `personnages/`, `ennemis/`,
`niveaux/`, `decors/`, `musiques/`) ; PHP `module.php`, `fonctions.php`, `univers.php`, `facade.php` ;
`assets/declencheur.js`, `assets/secret.css` (papillon), `assets/jeu.css` ; test
`tests/test-wordend.php` ; outils `tools/wordend/` (`decouper-planche.py`, `valider.py`, `source/`,
`banc/`).

## 2. Configuration `window.ynWordEnd` (PHP → déclencheur → modale)

```js
window.ynWordEnd = {
  version: '<YUME_CORE_VERSION>',
  style: '…/assets/jeu.css?ver=<YUME_CORE_VERSION>.<filemtime>',
  scripts: [ '…/assets/moteur/00-espace.js?ver=…', …, '…/assets/moteur/jeu.js?ver=…' ], // 14 URL, ordre SCRIPTS_MOTEUR
  univers: { sukasuka: { titre: 'WordEnd', oeuvre: 'sukasuka',
                         manifeste: '…/assets/univers/sukasuka/manifeste.json?ver=<version>',
                         version: '<YUME_CORE_VERSION>.<filemtime(manifeste.json)>' } },
  universParDefaut: 'sukasuka',           // univers_par_defaut()
  universPage: '',                        // univers_de_la_page() : univers de la fiche d'œuvre courante
  depart: { personnage: '', niveau: '' }, // FACULTATIF, banc seulement (voir §3.12)
  ouvrir: function ( univers? ) {}        // ajouté par declencheur.js
};
```

Côté PHP (`namespace Yume\Core\WordEnd`) :

| Fonction | Rôle |
| --- | --- |
| `configuration()` | le tableau ci-dessus (sans `depart` ni `ouvrir`) ; ne dépend que de l'URL demandée (Batcache) |
| `univers()` | registre assaini : `apply_filters( 'yume_wordend_univers', UNIVERS_INTEGRES )` → `slug => {titre, oeuvre, manifeste, version}` |
| `assainir_univers( $entree )` | une entrée assainie, ou `null` si elle est rejetée |
| `dossier_valide( $dossier )` | un seul nom `^[a-z0-9][a-z0-9_-]*$` (insensible à la casse), jamais `..` ni `/` |
| `univers_par_defaut( $liste? )` | `UNIVERS_PAR_DEFAUT` (`sukasuka`) s'il est disponible, sinon le premier, sinon `''` |
| `univers_par_oeuvre( $slug )` | premier univers dont `oeuvre` vaut ce slug, sinon `''` |
| `univers_de_la_page()` | `univers_par_oeuvre( post_name )` si la requête principale est une fiche `yume_oeuvre`, sinon `''` |
| `oeuvres_declencheuses()` | œuvres des univers ∪ filtre `yume_wordend_oeuvres`, assainies, dédoublonnées |
| `chemin_manifeste()`, `version_univers()`, `fichiers_univers()` | univers intégrés : chemin, version (`YUME_CORE_VERSION.filemtime`), fichiers référencés |
| `fichiers_requis()` | déclencheur, feuilles, `SCRIPTS_MOTEUR`, `fichiers_univers()` des univers intégrés (test, archive) |

Assainissement d'une entrée du filtre `yume_wordend_univers` (clé = slug, `sanitize_title` ; slug vide
rejeté ; en cas de doublon après assainissement, le premier l'emporte) :

- `titre` : `sanitize_text_field`, « WordEnd » s'il est vide ; `oeuvre` : `sanitize_title` (facultatif) ;
- soit `dossier` (univers livré dans `assets/univers/`) : `dossier_valide()` et `manifeste.json` lisible,
  sinon l'entrée est retirée ; version = `version_univers()` ;
- soit `manifeste` (univers d'une autre extension) : URL absolue http(s) (`esc_url_raw`), avec `version`
  facultative (`sanitize_text_field`, défaut `YUME_CORE_VERSION`) ;
- `dossier` l'emporte sur `manifeste` ; la version est ajoutée au manifeste en `?ver=`, et le moteur
  l'ajoute à chaque fichier de l'univers.

Façade : aucun déclencheur ni papillon si `yume_wordend_actif` est faux ou si le registre est vide. Le
papillon (bouton `.yn-wordend-secret[data-yn-wordend-ouvrir]`, inséré après le `<h1>` de
`yume/oeuvre-header`) porte `data-yn-wordend-univers="<slug>"` quand l'œuvre a un univers (sinon
l'univers par défaut s'ouvre) et le libellé « Un papillon bleu s'est posé ici… (jeu caché <titre>) ».

`declencheur.js` : `ouvrir( univers? )` ; un clic sur `[data-yn-wordend-ouvrir]` passe
`data-yn-wordend-univers` ; un slug absent de `config.univers` est ignoré. Modale : univers ouvert =
`univers || config.universPage || config.universParDefaut || première clé de config.univers`.

## 3. Interfaces JavaScript (`ynWE = window.ynWordEndMoteur`)

### 3.1 `00-espace.js`

```js
ynWE.LARGEUR = 480; ynWE.HAUTEUR = 270; ynWE.DENSITE = 2; ynWE.DT = 1/60;
ynWE.hasard(min, max) → nombre dans [min, max[
ynWE.chevauche(a, b) → bool             // boîtes {x, y, l, h} (x, y = coin haut gauche)
ynWE.limiter(v, min, max) → nombre
ynWE.nonImplemente(nom) → fonction qui lève Error('non implémenté : ' + nom)   // pour un module en préparation ; inutilisée en v2.1
ynWE.evenements = { sur(nom, fn), retirer(nom, fn), emettre(nom, detail) }   // fn(detail, nom) ; bus interne, pas d'événement DOM
ynWE.annoncer(texte)          ≡ emettre('annonce', {texte})   // zone role="status" de la modale
ynWE.secouer(monde, duree)    // monde.secousse = max(…) sauf monde.mouvementReduit ; émet 'secousse'
ynWE.ajouterScore(monde, delta) // monde.score += delta ; émet 'score'
ynWE.creerMonde(univers, niveau, personnage) → monde
ynWE.animation(meta, nom) → {ips, boucle, coup?, onde?, images} | undefined
ynWE.imageCourante(meta, nom, t) → indice (en boucle, ou figé sur la dernière image)
ynWE.dureeAnimation(meta, nom) → images.length / ips
ynWE.debug = { source, etat() → état de l'écran | 'ferme', monde() → monde|null, partie() → partie|null, ips }
```

Événements du bus (`detail`) :

| Nom | detail | Émis par |
| --- | --- | --- |
| `annonce` | `{texte}` | tous (via `ynWE.annoncer`) |
| `score` | `{delta}` | `ynWE.ajouterScore` |
| `secousse` | `{duree}` | `ynWE.secouer` |
| `joueur:blesse` | `{pv}` | joueur |
| `joueur:mort` | `{}` | joueur |
| `ennemi:mort` | `{ennemi, points}` | ennemis |
| `vague:debut` | `{numero, total}` (`total` : `null` en arcade) | objectifs `arcade` et `vagues` |
| `niveau:fin` | `{resultat: 'gagne'\|'perdu', score, etoiles, temps, pv, niveau: slug}` | partie (une seule fois) |
| `partie:etat` | `{etat, precedent}` | écrans (`ec.aller`) |
| `son:changement` | `{volume, muet}` | audio (`regler`) |

Monde (données seulement, partagé par tous les modules) :

```js
monde = {
  univers, niveau /* JSON */, personnage /* chargé */, joueur: null, ennemis: [], projectiles: [], ondes: [], particules: [],
  camera: {x: 0, y: 0}, temps: 0 /* s de partie */, score: 0, secousse: 0 /* s */, mouvementReduit: false,
  palette: {} /* = r.palette, posé par la modale */, prochainId: 1,
  sol: niveau.sol ?? 238, largeur: niveau.largeur ?? 480, gravite: niveau.gravite ?? 900, plateformes: niveau.plateformes ?? [] }
```

`palette` et `mouvementReduit` sont recopiés du rendu par la modale au lancement de chaque partie et à
chaque `yn:theme`. Joueur, ennemis et niveau ne lisent jamais le DOM : ils passent par `monde`.

### 3.2 `stockage.js`

```js
ynWE.stockage = { CLE: 'yn.wordend', VERSION: 2,
  lire() → données v2 (migrées), fusionner(champs) /* racine */, reglagesSon() → {volume 0…1 (0,5), muet}, enregistrerSon({volume, muet}),
  progression(universSlug, univers?) → {parties, arcade: {meilleur}, niveaux: {slug: {meilleur, etoiles, fini}}, personnage, debloques: {niveaux: [], personnages: []}},
  enregistrerResultat(universSlug, niveauSlug, {score, etoiles, fini}), choisirPersonnage(universSlug, slug), migrer() → données v2 }
```

- Format : §6. Toutes les lectures et écritures sont en `try/catch` (stockage bloqué : valeurs par défaut).
- `lire()` passe par `migrer()` (idempotent ; n'écrit que si la clé existe et n'est pas encore en v2).
  Un format futur (`version > 2`) est laissé tel quel et n'est jamais réécrit par les résultats.
- `progression()` renvoie une copie toujours complète. `debloques` : sans `univers`, l'arcade et les niveaux
  finis ; avec l'univers chargé (`ressources`), `arcade` plus les niveaux hors arcade triés par `numero`
  puis ordre du manifeste, le premier toujours, le suivant quand le précédent est `fini` (ou s'il est fini
  lui-même) ; personnages : le premier, ceux chargés dont `debloque` vaut `true` (ou est absent), et ceux
  dont le niveau de `debloque: {niveau}` est fini.
- `enregistrerResultat` : `parties + 1` ; arcade : meilleur score ; niveau : meilleur score, maximum
  d'étoiles (≤ 3), `fini` ne redevient jamais faux ; `maj` = date du jour. La modale l'appelle à chaque
  `niveau:fin` avec `fini = resultat === 'gagne'`.

### 3.3 `ressources.js`

```js
ynWE.ressources = { chargerImage(url) → Promise<Image>, chargerJson(url) → Promise<objet>,
  chargerUnivers({manifeste: url, version, slug?}) → Promise<univers>,
  chargerPersonnage(univers, slug) → Promise<personnage>,     // mis en cache dans univers.personnages
  chargerPersonnages(univers) → Promise<univers.personnages>, // tous ; absent ⇒ avertissement, retiré de ordre.personnages
  chargerNiveau(univers, slug) → Promise<niveau>,             // JSON + son décor ; cache univers.niveaux
  chargerNiveaux(univers) → Promise<univers.niveaux>,         // tous, sans décor ; absent ⇒ avertissement, retiré de ordre.niveaux
  chargerDecor(univers, nom) → Promise<Image|null>,           // une fois ; échec ⇒ null (avertissement)
  teinter(image, filtre CSS) → canvas|image,
  urlRelative(univers, chemin) → univers.base + chemin + '?ver=' + univers.ver }

univers = { slug, manifeste /* JSON */, base /* URL du dossier du manifeste, « / » final */, ver,
  personnages: {slug: personnage}, ennemis: {slug: ennemi}, niveaux: {slug: json} /* chargés seulement */,
  decors: {nom: Image|null}, musiques: {nom: {url, credit}},
  ordre: { personnages: [slugs, ordre du manifeste], niveaux: [slugs, ordre du manifeste] },
  chemins: { personnages: {slug: chemin relatif}, niveaux: {slug: chemin relatif} } }
```

- Slug d'un personnage, d'un ennemi ou d'un niveau = **nom du fichier sans `.json`** ; il doit être égal au
  champ `slug` du JSON (écart : avertissement console `[WordEnd] …`).
- `chargerUnivers` charge : le manifeste ; le **premier personnage** (obligatoire) ; **tous les ennemis**
  avec leurs planches (obligatoires) ; le **niveau par défaut** (`arcade` s'il est listé, sinon le premier ;
  obligatoire) et son décor ; l'URL des musiques (le fichier n'est lu qu'à la lecture). Les autres
  personnages et niveaux sont chargés à la demande (écrans de sélection : `chargerPersonnages`,
  `chargerNiveaux`) : un fichier listé mais absent ne bloque rien. Les chargements simultanés d'un même
  fichier partagent une promesse.
- Planche d'une entité : `"planche": "chtholly"` → `<dossier du JSON>/chtholly.png` +
  `chtholly.planche.json` ; après chargement `json.planche = {nom, image, meta[, teinte]}`. Une `teinte`
  (filtre CSS) de l'entité remplace `image` par sa copie teintée.
- Ennemis : chaque `types.<t>.planche` devient `{nom, image, meta[, teinte]}` : planche propre au type si
  le JSON du type la nomme (introuvable : avertissement, planche de l'ennemi), sinon la planche de
  l'ennemi ; copie teintée si le type a `teinte`.
- `teinter` : canvas hors écran passé par `ctx.filter`, calculé une fois par couple (image, filtre) ;
  navigateur sans `ctx.filter` ou filtre invalide : image d'origine.

### 3.4 `audio.js`

```js
ynWE.audio = { creer() → audio }
audio.reglages() → {volume, muet} ; audio.regler({volume?, muet?})   // mémorise (stockage.enregistrerSon), émet 'son:changement'
audio.relire()                       // relit les réglages mémorisés (ouverture de la modale)
audio.musique(url)                   // choisit la piste (nouvel Audio en boucle si l'URL change, l'ancien est libéré) et la joue sauf muet/volume nul
audio.musique(null)                  // pause (position conservée)
audio.pauser() ; audio.reprendre()
audio.etat() → {url, voulue, joue}   // tests, banc
audio.effet(nom)                     // sans effet en v2.1 (effets sonores : v2.2)
```

L'élément `Audio` n'est créé qu'à la première lecture effective ; lecture refusée par le navigateur = silence.
La modale appelle `audio.musique( ouvert && etat === 'jeu' ? URL de la musique du niveau : null )` à chaque
changement d'état ou de réglage.

### 3.5 `rendu.js`

```js
ynWE.rendu = { creer(canvas) → r, ajouterParticule(monde, x, y, vx, vy, vie, couleur, taille, flotte), mettreAJourParticules(monde, dt) }
r.canvas ; r.ctx ; r.palette {fond, bande, carte, filet, texteFort, texte, texteFaible, accent, accent2, erreur} ; r.police ; r.mouvementReduit
r.lirePalette() → palette            // jetons --wp--preset--color--* + police des titres + mouvement réduit
r.camera(monde)                      // camera.x → limiter(joueur.x − 240, 0, largeur − 480), lissage 0,15 (1 en mouvement réduit)
r.commencer(monde|null)              // setTransform(DENSITE), secousse (±2 px), translate(−camera.x) : coordonnées du MONDE
r.finir()                            // retour aux coordonnées de l'ÉCRAN (même décalage de secousse)
r.decor(image|null, camera, parallaxe, secours {sol?, voile?})
r.plateformes(monde)
r.sprite(planche {image, meta}, nom, i, x, y, dir, echelleSup = 1, lissage = true, alpha = 1, flash = 0)
r.ombre(x, y, rayonX, rayonY, opacite) ; r.jauge(x, y, l, h, part 0…1, couleur)
r.texte(contenu, x, y, taille, alignement, couleur, graisse)   // contour couleur fond, remplissage texteFort par défaut
r.voile() ; r.coeur(x, y, plein) ; r.particules(monde) ; r.ajouterParticule(…) ; r.mettreAJourParticules(monde, dt)
```

- `r.decor` (coordonnées du monde) : image 960 × 540 dessinée en 480 × 270, **répétée en miroir** (une
  copie sur deux retournée : raccord sans couture) et décalée de `camera.x × parallaxe` (0 : fixe à
  l'écran, 1 : solidaire du monde ; **0 en mouvement réduit**). Sans image : ciel dégradé et sol aux
  couleurs du thème (`secours.sol`, 238). Puis le **voile** du niveau (`secours.voile`, sinon
  `monde.niveau.voile` du monde passé à `r.commencer`) : couleur CSS sur tout l'écran (couleur invalide
  ignorée).
- `r.plateformes` : seules les plateformes visibles. Traversable : dalle de 6 px aux coins arrondis
  (carte, contour filet, liseré accent, ombre portée). `solide` : bloc de hauteur `p.h`, à défaut jusqu'au
  sol, avec rainures de pierre.
- `r.sprite` : ancre en (x, y), retourné si `dir < 0`, échelle = `meta.echelle / echelleSup` ;
  `lissage === false` pour le pixel art ; `flash > 0` : éclat blanc en mode `lighter`.
- Plafond de 300 particules ; gravité des particules 260 px/s² (8 si `flotte`). `r.texte`, `r.voile`,
  `r.coeur`, le HUD et les surcouches s'utilisent **après** `r.finir()` (coordonnées de l'écran).

### 3.6 `physique.js`

```js
ynWE.physique = { BORD: 18, VITESSE_MAX: 600, EPAISSEUR: 6,
  corps(obj) → obj complété {x, y, vx: 0, vy: 0, l, h, auSol: false, traverse: 0, support: null},
  appliquer(corps, dt, monde, options {gravite, plateformes, bords} /* true par défaut ; false pour désactiver */),
  boite(corps) → {x: x − l/2, y: y − h, l, h}, surfaceSous(monde, x, y) → y de la surface, estSolide(p) → bool, chevauche }
```

- `corps(obj)` complète et **renvoie le même objet** : le joueur et les ennemis sont leur propre corps
  (`j.corps === j`). `(x, y)` = ancre au sol, au milieu des pieds. `support` : plateforme sous les pieds
  (`null` au sol ou en l'air).
- `appliquer` : `x += vx·dt`, côtés des plateformes solides, bornes `[BORD, largeur − BORD]` si `bords` ;
  gravité `monde.gravite` (px/s²), vitesse de chute ≤ `VITESSE_MAX`, intégration à vitesse moyenne sur le
  pas (hauteur d'un saut = impulsion² / (2 × gravité)) ; sol `monde.sol`.
- Plateformes `{x, y, l, type?, h?}` : **traversable** (défaut) : on s'y pose seulement en descente, quand
  les pieds étaient au-dessus au pas précédent et que le milieu des pieds est au-dessus ; ignorée tant que
  `traverse > 0` (le joueur la pose à ↓ + saut) ; la plus haute franchie pendant le pas l'emporte.
  **`type: "solide"`** : même atterrissage, jamais traversée vers le bas, bloque la tête par-dessous et les
  côtés, sur une épaisseur `p.h` (défaut `EPAISSEUR`, 6 px ; le rendu d'une solide sans `h` descend
  jusqu'au sol : donner `h` pour que collision et dessin coïncident).
- `options.gravite === false` : corps libre (`y += vy·dt`, ni sol ni plateformes, `auSol` faux).
- `surfaceSous` : sol ou plateforme la plus haute sous le point (ombres). Les ennemis ont `l`/`h` à la
  taille 1 (leur boîte réelle est multipliée par `e.taille`, §3.9).

### 3.7 `competences.js`

```js
ynWE.competences = { melee, onde, projectile, ruee, parade, enregistrer(nom, impl),
  frapper(monde, boite, degats, dejaTouches /* ids */, source? {type, dir}),   // chaque ennemi vivant une fois ; recul du côté opposé à la boîte
  mettreAJourOndes(monde, dt), dessinerOndes(r, monde) }
impl = { demarrer(joueur, monde, def) → bool, mettreAJour(joueur, dt, monde, def, commandes {maintenu}),
         dessiner(r, joueur, monde, def) /* après le sprite */, animation(joueur, monde, def) → [nom, i],
         encaisser?(joueur, sens, degats, monde, def) → bool /* vrai : le coup reçu est absorbé */ }
```

- `def` = `competences.principale|secondaire` du JSON du personnage ; `commandes.maintenu` = bouton de
  l'emplacement maintenu (`epee` pour la principale, `competence` pour la secondaire).
- Pendant une compétence : `joueur.emplacement` = `'principale'|'secondaire'` (clé de `joueur.recharges`),
  `joueur.phase` libre ; la compétence se termine par `joueur.changerEtat('repos')`. Une compétence ne
  démarre pas tant que la recharge de son emplacement est positive.
- `melee` : état `attaque` ; immobile au sol (en l'air, l'élan est gardé) ; `degats` (1) sur les images
  `coup` de `animation` (`attaque`), boîte `boite` (`{x: 4, y: −62, l: 60, h: 58}`) relative à l'ancre,
  miroir si `dir < 0` ; une touche par ennemi et par coup ; pas de recharge.
- `onde` : état `competence`, phase `concentration` tant que le bouton est maintenu (jauge au-dessus de la
  tête) ; relâché après `chargeMin` (0,55 s) : phase `onde`, onde `{x, y, dir, vie, t, touches, degats,
  vitesse, boite}` dans `monde.ondes` (départ `depart` `{x: 40, y: −34}`, boîte `boiteOnde` `{l: 28, h: 52}`,
  `degats` 3, `vitesse` 250, `vie` 1,1 s), recharge `recharge` posée au lancer, secousse 0,12 s, image
  `anim.onde` pendant `duree` (0,42 s) ; relâché trop tôt : annulée. L'onde traverse les ennemis.
- `projectile` : état `attaque` ; tir sur la première image `coup` de `animation` (image 0 sans `coup`),
  recharge posée au tir. Projectile du joueur dans `monde.projectiles` :
  `{id, camp: 'joueur', x, y, vx, vy: 0, dir, degats (1), vie (1,4 s), t, l: 14, h: 6, couleur ('#ffe28a'),
  touches, source {type: 'projectile', dir}, perce (false)}`, départ `depart` (`{x: 22, y: −30}`), vitesse
  `vitesse` (300). `projectile.avancer(p, dt, monde) → bool` (faux : à retirer ; un projectile qui touche
  disparaît sauf `perce`), `projectile.dessinerTir(r, p)`, `projectile.boite(p)` ; ils sont appelés par
  `ennemis.mettreAJourProjectiles` / `dessinerProjectiles`.
- `ruee` : état `competence` ; `distance` (90) px en `duree` (0,2 s), horizontale même en l'air ; recharge
  (1,5) posée au départ ; **invulnérable** (`encaisser` absorbe tout) ; animation `animation` (`course`)
  accélérée, image rémanente (sauf mouvement réduit).
- `parade` : état `competence`, `j.pare = true` pendant `duree` (0,6 s), recharge (2) posée au départ.
  `encaisser` : le coup est absorbé, l'ennemi vivant le plus proche du côté du coup (< 90 px) est repoussé
  (recul 220) et mis en `degats` s'il n'est pas stoïque ; étincelles, secousse 0,08 s. Les projectiles
  ennemis qui touchent un joueur qui pare sont renvoyés (§3.9). Animation : `animation` si la planche l'a,
  sinon `poses.parade`, sinon `degats` image 0 ; arc lumineux devant le personnage.

### 3.8 `joueur.js`

```js
ynWE.joueur = { creer(personnage, monde, entrees) → j, DUREE_MORT: 2.2, INVINCIBILITE: 1.2, COYOTE: 0.08 }
j (= j.corps) : x, y, vx, vy, l, h, auSol, traverse, support, personnage, planche, entrees, dir ±1,
  etat 'repos'|'marche'|'course'|'saut'|'chute'|'attaque'|'competence'|'degats'|'mort', t (s dans l'état),
  pv, pvMax, invincible (s), recharges {principale, secondaire}, touches [], emplacement, phase,
  pare, sautsFaits, enLair (s hors du sol), sautMaintenu
j.mettreAJour(dt, monde) ; j.boite() → {x − l/2, y − h − 2, l, h} ; j.blesser(sens, degats, monde) → bool
j.dessiner(r, monde) ; j.animationCourante() → [nom, i] ; j.changerEtat(etat) ; j.estFini() → mort depuis DUREE_MORT
```

- Commandes lues : `gauche`, `droite`, `courir` (ou `entrees.courirTactile`), `bas`, `saut` (impulsion ;
  maintenu : hauteur), `epee` (impulsion → principale), `competence` (maintenue → secondaire).
- États libres (`repos`, `marche`, `course`, `saut`, `chute`) : impulsion `epee` ⇒ principale, sinon
  `competence` maintenue ⇒ secondaire, sinon déplacement (`vitesseMarche`, `vitesseCourse`) et saut. Les
  compétences se lancent aussi en l'air. Après la physique, l'état libre suit le mouvement (`saut` si
  `vy < 0`, `chute` en l'air, `course`/`marche`/`repos` au sol).
- Saut (impulsion `saut`) : ↓ maintenu sur une plateforme traversable ⇒ descente (`traverse` 0,25 s) ;
  au sol, ou moins de `COYOTE` (0,08 s) après avoir quitté un bord sans sauter ⇒ saut (`vy =
  −saut.impulsion`) ; en l'air ⇒ saut supplémentaire tant que `sautsFaits < saut.sautsMax` (tomber d'un
  bord consomme le premier saut). Saut écourté : touche maintenue au départ puis relâchée pendant la
  montée ⇒ `vy × 0,5`.
- `blesser(sens, degats)` : ignoré (faux) si mort ou invincible ; une compétence en cours dont
  `encaisser` renvoie vrai absorbe le coup (faux) ; sinon PV − `degats` (1), invincibilité 1,2 s, recul
  `sens × 150`, secousse 0,25 s, `joueur:blesse` ; à 0 PV : état `mort`, annonce `textes.mort`, 24
  particules (6 en mouvement réduit), `joueur:mort` ; sinon état `degats` 0,35 s (`vx × 0,88`). Mort :
  `vx × 0,9`, `estFini()` après 2,2 s.
- Dessin : ombre sur la surface sous le joueur (plus petite en l'air), clignotement pendant
  l'invincibilité (opacité 0,6 fixe en mouvement réduit), `r.sprite`, puis `impl.dessiner`.
  `animationCourante` : compétence en cours ⇒ `impl.animation` ; sinon animation de la planche nommée
  comme l'état ; sinon `poses.<etat>` figée ; sinon `repos` image 0.
- Arcade = v1 exacte pour Chtholly (vitesses, PV, invincibilité, reculs, épée, onde).

### 3.9 `ennemis.js`

```js
ynWE.ennemis = { DUREE_FONDU: 0.6, creer(definition, typeNom, options {x, y?, dir, taille?, pvBonus, vitesseFacteur}, monde) → e,
  mettreAJour(e, dt, monde), blesser(e, degats, sens, monde, source?) → bool, boite(e), boiteAttaque(e), dessiner(r, e, monde),
  comportements: { marcheur, coureur, volant, tireur, bouclier, boss }, enregistrerComportement(nom, impl),
  mettreAJourProjectiles(monde, dt), dessinerProjectiles(r, monde), ajouterProjectile(monde, p) → p,
  separer(monde), retirerFinis(monde), changerEtat(e, etat) }
comportement = { entrer(e, monde), mettreAJour(e, dt, monde) → vx souhaité,
                 attaquer?(e, monde) → bool, avant?(e, dt, monde) }
e (corps) : id, definition, planche, typeNom, type (données du type), comportement (nom), nom (type.nom || definition.nom),
  taille (type.taille × 0,94…1,06), x, y, l, h (taille 1), dir, etat 'marche'|'course'|'repos'|'attaque'|'degats'|'mort', t,
  attaque (nom), pv, pvMax, vitesse, points, recharge, flash, recul, touche, stoique, vole, tir, invoquePar?
```

- `creer` : type inconnu ⇒ `Error` ; comportement inconnu ⇒ `marcheur` ; `y` = `options.y` (plateforme),
  sinon `monde.sol` ; `pv` = `type.pv + pvBonus` ; `vitesse` = `type.vitesse × vitesseFacteur` ; planche du
  type résolue par les ressources, sinon celle de l'ennemi ; puis `comportement.entrer`. N'ajoute pas
  l'ennemi au monde.
- `mettreAJour` : minuteries, recul × 0,86 ; mort : glisse de `recul` (un volant abattu tombe) ;
  `comportement.avant` à chaque pas (minuteries du comportement) ; `degats` : min(0,45 s, durée de
  l'animation) puis `repos` ; `attaque` : sur les images `coup`, tant que le coup n'a pas porté, **`attaquer` renvoie
  vrai si le comportement traite le coup lui-même** (tir du tireur), sinon le joueur est touché une fois si
  `j.boite()` chevauche `boiteAttaque(e)` (dégâts `attaques.<nom>.degats`, 1 ; un joueur qui pare absorbe le
  coup), puis `repos` et recharge `hasard(0,8, 1,5) × type.rechargeFacteur` ; sinon
  `vx = comportement.mettreAJour(…)`. Déplacement : ennemis au sol par `physique.appliquer` (gravité,
  plateformes, sans bords : un Timere tombe d'une plateforme mais n'y monte pas) ; volants : `x` direct.
- `boite(e)` = `{x − l·taille/2, y − h·taille, l·taille, h·taille}` ; `boiteAttaque(e)` : portée et hauteur de
  `definition.attaques[e.attaque]` × taille, débutant 6 × taille devant l'ancre, 4 × taille au-dessus du sol.
- `blesser` → vrai si le coup a porté. `source` : `{type: 'melee'|'onde'|'projectile'|…, dir}`. Un
  `bouclier` touché de face (sens du recul = −`e.dir`) par autre chose qu'une `onde` pare (étincelles, recul
  40, faux). Sinon : particules, `flash` 0,1 s ; mort ⇒ `ajouterScore(points)`, recul 90 (0 pour le boss),
  12 particules (`definition.couleursMort`, 4 en mouvement réduit), secousse 0,6 s pour le boss,
  `ennemi:mort` ; vivant ⇒ recul 170 et état `degats`, sauf le boss (jamais) et un `stoique` touché par ≤ 1
  point.
- Comportements :
  - `marcheur`, `coureur` (v1) : approche, attend à portée, attaque (fouet si le joueur est au-delà de la
    portée de la morsure et que le type l'a, sinon au hasard), aucune attaque hors de l'écran (ancre dans
    `[camera.x + 4, camera.x + 476]`), fuite quand le joueur est mort ; marche ≤ 46 px/s, coureur à pleine
    vitesse au-delà de 50 px.
  - `volant` (`e.vole`) : vole à `altitude` (80) au-dessus du sol (ou à `options.y`) en ondulant
    (`amplitude` 14), se place au-dessus du joueur, plonge à `plongee` (260) px/s quand il est aligné (< 28
    px), visible et rechargé, touche au contact, puis remonte (`remontee` 110 px/s ; recharge 1,2–2,2 s).
    Ni gravité ni plateformes.
  - `tireur` : garde `distance` (150 ± 24 px) sans quitter l'écran ni sa plateforme ; toutes les `recharge`
    (2,4 s ± 15 %) à portée (≤ distance + 120), geste `geste` (animation, défaut `fouet`, à défaut la première
    attaque) puis projectile vers le joueur (angle ≤ 35°) : `projectile {vitesse (170), degats (1), hauteur
    (30, × taille), couleur, vie (3), rayon (3)}` ; mord si le joueur le colle.
  - `bouclier` : marcheur qui pare les coups de face (sauf l'onde) ; met `retournement` (0,4 s) à faire
    demi-tour ; arc de bouclier dessiné.
  - `boss` : stoïque, ne recule jamais, immobile pendant `entree` (1,2 s). `phases[]` : la phase active est
    la dernière dont `pvSous` ≥ PV restants / PV max ; à chaque changement : secousse, annonce
    `phases[].texte` (défaut « <nom> entre en rage ! »), `vitesseFacteur`, `invocations {ennemi, type, n,
    toutesLes (8 s), max (6 en vie)}` aux bords de l'écran. Charge toutes les `charge.toutesLes` (6) s quand
    il est visible et à plus de 70 px : préparation `charge.preparation` (0,6 s, clignotement), puis ruée à
    `charge.vitesse` (230) px/s jusqu'à `charge.depassement` (90) px au-delà du joueur (3 s au plus), dégâts
    `charge.degats` (1) ; parée, elle s'arrête net.
- `separer` : les ennemis ne se superposent pas tout à fait (v1) ; un volant et un ennemi au sol ne se
  gênent pas, ni deux ennemis à plus de 20 px de hauteur l'un de l'autre ; le boss n'est pas déplacé.
  `retirerFinis` : mort + animation `mort` + fondu 0,6 s, ou sorti de `[−140, largeur + 140]`.
- Dessin : ombre (sur la surface sous un volant), `r.sprite(planche, anim, i, x, y, dir, taille,
  definition.lissage, alpha du fondu, flash)` ; clignotement pendant la préparation de la charge du boss.
- Projectiles (`monde.projectiles`, tous camps, 60 au plus) : `ajouterProjectile(monde, p)` complète
  `{camp: 'ennemi', vx: 0, vy: 0, t: 0, vie: 2, degats: 1, touches: []}` puis ajoute. Format géré ici :
  `{camp: 'ennemi'|'joueur', x, y, vx, vy, degats, vie (s), couleur?, rayon? | l?, h?, gravite?, percant?,
  touches?, type?, source?, mettreAJour?(p, dt, monde), dessiner?(r, p, monde)}`. Un projectile ennemi
  blesse le joueur (sauf invincible) puis s'éteint ; s'il pare, il est **renvoyé** (`camp: 'joueur'`,
  `vx × −1,2`, `vy` inversé, `renvoye: true`, vie ≥ 1,5 s). Un projectile du camp joueur frappe les ennemis
  (`competences.frapper`) et s'éteint sauf `percant`. Tout projectile s'éteint au sol. Les tirs du joueur
  (§3.7, non renvoyés) sont avancés et dessinés par `competences.projectile.avancer` / `dessinerTir`.

### 3.10 `niveau.js`

```js
ynWE.niveau = { demarrer(univers, niveauJson, personnage, entrees) → partie, objectifs: { arcade, vagues, survie, boss },
  enregistrerObjectif(nom, impl), calculerEtoiles(niveauJson, bilan) → 0…3, gabarit(texte, valeurs) /* « {n} » → valeur */,
  avancer(partie, secondes) → partie /* banc seulement : pas fixes DT, sans affichage */ }
impl objectif = { demarrer(partie), mettreAJour(partie, dt), fini(partie) → 'gagne'|'perdu'|'' }
partie = { monde, niveau /* JSON */, etat 'enCours'|'gagne'|'perdu', objectif, vague?, boss? /* ennemi */, survie?,
  mettreAJour(dt), hud() → {vague, total, objectif, boss: {pv, pvMax, nom}|null, temps, banniere, restant} }
```

- `demarrer` : `creerMonde`, `joueur.creer`, `apparition.x`, caméra centrée sur le joueur, objectif
  `objectif.type` (défaut `arcade` ; inconnu ⇒ `Error`).
- `partie.mettreAJour(dt)` : temps, joueur, ennemis (`mettreAJour` de chacun, `separer`, `retirerFinis`),
  ondes, projectiles, objectif, secousse − dt, particules ; puis fin si `objectif.fini()` ou
  `joueur.estFini()` (⇒ `perdu`) : `etat` et **un seul** `niveau:fin` (`{resultat, score, temps, pv, niveau,
  etoiles}`).
- `hud()` : `total` `null` hors objectif `vagues` ; `restant` : secondes à tenir en survie, sinon `null` ;
  `banniere` : s restantes de la bannière « Vague n ».
- Apparitions (`vagues`, `survie`) : groupe `{ennemi, type ('normal'), cote, apparition {x, y?}, pvBonus,
  vitesseFacteur}` ; par côté, **juste hors de l'écran** (`camera.x − 50` ou `camera.x + 530`, bornés à
  `[−50, largeur + 50]`) : `gauche`, `droite`, `alterne` (rang pair à gauche), sinon aléatoire ; au point
  `apparition` (`y` : hauteur d'une plateforme) avec une bouffée de particules, tourné vers le joueur.
- Objectif `arcade` (v1 exact) : première vague après 1,2 s ; vague n : `3 + 2n` ennemis (`objectif.ennemi`,
  défaut : premier ennemi de l'univers), types tirés dans `[petit, petit, normal, normal]` + `[coureur,
  normal]` dès n ≥ 2 + `grand` dès n ≥ 3 s'il n'y en a pas déjà un vivant ; apparition à −50 ou
  `largeur + 50` ; PV + 1 aux `normal` dès n ≥ 6 ; vitesse × `1 + min(0,5, 0,04n)` ; minuterie
  `max(0,5, 2,2 − 0,15n) × hasard(0,7, 1,2)` ; vague finie : bonus `50n`, pause 1,8 s, soin d'un PV toutes
  les 2 vagues ; annonce `textes.vague` (`{n}`, `{k}`). Jamais gagné.
- Objectif `vagues` : première vague après `vagues[0].delai` (1,2 s) ; dans une vague, le rang k d'un groupe
  apparaît à `k × intervalle` (1,5 s) ; bannière 2 s, annonce `textes.vague` (`{n}`, `{total}`, `{k}`),
  `vague:debut` ; vague finie (tout est apparu, plus aucun ennemi) : bonus `50n`, soin `soinEntreVagues`
  (0), pause `delai` de la suivante (1,8 s) ; après la dernière : annonce `textes.victoire`, gagné 1 s après.
- Objectif `survie` : tenir `duree` (60) s de partie ; générateur : premier ennemi après `delai` (1,5 s),
  groupe tiré selon `poids` (1), rien au-delà de `max` (8) ennemis en vie, intervalle `intervalle` (3 s) ×
  (1 − `acceleration` (0,4) × part écoulée) × hasard(0,8, 1,2) ; annonces à 30 et 10 s de la fin
  (`textes.restant`, `{s}`) ; à la fin : bonus `objectif.bonus` (200), annonce `textes.victoire`, gagné.
- Objectif `boss` : `ennemi`, `typeEnnemi` (`boss`), `x` (`largeur − 80`) ; le boss apparaît au lancement,
  tourné vers le joueur (`partie.boss`, barre de PV du HUD) ; mort : annonce `textes.victoire` (défaut
  « <nom> est vaincu ! »), gagné 1,6 s après.
- `calculerEtoiles` : 0 si non gagné ; 1 pour finir, + 1 si `score ≥ etoiles.score`, + 1 si
  `pv ≥ etoiles.pvRestants` ou `temps ≤ etoiles.temps`.

### 3.11 `entrees.js`

```js
ynWE.entrees = { creer() → in, TOUCHES, SCHEMA_DEFAUT }   // la modale passe (dialogue, ecran), ignorés
in.commande(nom) → bool (maintenu, clavier ou tactile) ; in.impulsion(nom) → bool (vraie une fois par appui : epee, competence, saut)
in.vider(quoi?)          // undefined : clavier + impulsions ; 'impulsions' ; 'tout' : + tactile
in.surTouche(e) → 'echap'|'pause'|'muet'|'niveaux'|'valider'|'epee'|'gauche'|'droite'|'haut'|'bas'|''
in.relacher(e)           // keyup
in.actualiserManettes(schema [{nom, texte, libelle, bascule?, groupe?: 'gauche'|'droite'}]) ; in.element (div.yn-wordend__manettes)
in.surAppui = fn(nom)    // posé par la modale (appui tactile) ; in.courirTactile (bool, bascule « Courir »)
```

- `TOUCHES` (`e.code`, disposition physique) : `ArrowLeft`/`KeyA` gauche, `ArrowRight`/`KeyD` droite,
  `ArrowUp`/`KeyW`/`Space` saut, `ArrowDown`/`KeyS` bas, `ShiftLeft`/`ShiftRight` courir, `KeyJ`/`KeyX` epee,
  `KeyK`/`KeyC` competence. M (`muet`), P (`pause`) et R (`niveaux`) sur `e.key` (lettre tapée, AZERTY
  compris), sans répétition.
- `surTouche` : Échap ⇒ `preventDefault`, `echap` ; Tab et modificateurs ignorés ; sur `INPUT`/`SELECT` la
  touche garde son rôle (`stopPropagation` seulement) ; Entrée/Espace sur un bouton : le bouton agit ;
  sinon `stopPropagation` (la page ne voit pas les touches du jeu). Entrée/Espace ⇒ `valider` (Espace pose
  aussi `saut`, maintenu et impulsion). Flèches et W/A/S/D renvoient aussi la navigation
  (`gauche`, `droite`, `haut`, `bas`), J/X renvoie `epee`.
- Manettes : `SCHEMA_DEFAUT` (◀ ▶ Courir | Saut Épée Charge) ; deux groupes (`groupe` absent : `gauche`
  pour gauche, droite, courir et bas, sinon `droite`) ; `pointerdown` avec capture ⇒ commande maintenue et
  impulsion, relâchée à `pointerup`/`pointercancel`/perte de capture ; Entrée/Espace sur un bouton focalisé
  ⇒ impulsion ; `bascule` : `aria-pressed`.

### 3.12 `ecrans.js`

```js
ynWE.ecrans = { creer(contexte) → ec }
contexte = { r, stockage, config, univers() → univers|null, personnage() → personnage|null, partie() → partie|null,
             ouvrirUnivers(slug, apres?), demarrerNiveau(universSlug, personnageSlug, niveauSlug), fermer(), surChoix?() }
ec.etat 'chargement'|'titre'|'univers'|'personnage'|'niveaux'|'jeu'|'pause'|'fin'|'victoire'|'erreur'
ec.temps ; ec.debut (ec.temps à l'entrée dans l'état) ; ec.meilleur (record du niveau) ; ec.bilan (niveau:fin + record)
ec.choix = {univers, personnage, niveau} ; ec.preparation ''|'encours'|'pret'
ec.aller(etat, donnees?)      // émet 'partie:etat' {etat, precedent} ; 'fin'|'victoire' : donnees = bilan de niveau:fin
ec.choisir(type 'univers'|'personnage'|'niveau', slug)   // depuis une liste <select> ; ignoré en jeu, chargement, erreur
ec.listes() → {univers[], personnages[], niveaux[], choix, avecUnivers}   // doublons DOM des écrans de sélection
ec.preparer() → Promise       // charge tous les niveaux et personnages de l'univers (une fois ; absents ignorés)
ec.surAction(action, nom?) ; ec.surPointeur(x, y) /* coordonnées logiques */ ; ec.dessiner(monde|null, partie|null) ; ec.hud(monde, partie)
```

- Parcours : `titre` → `univers` (seulement si `config.univers` a plusieurs entrées et pas d'`universPage`)
  → `personnage` (cartes, verrous) → `niveaux` (Arcade puis niveaux numérotés : étoiles, verrous, record ;
  grille de 3 colonnes) → `jeu` → `pause` | `fin` (perdu) | `victoire` (étoiles). `config.depart.niveau`
  (banc) : Entrée sur le titre lance directement ce niveau avec `config.depart.personnage`.
- Listes : `personnages[]` `{slug, nom, description, personnage, debloque, condition}` ; `niveaux[]`
  `{slug, titre, numero, arcade, etoiles, meilleur, fini, debloque}` (arcade toujours débloqué, un niveau
  quand le précédent est fini) ; le dernier personnage choisi est mémorisé (`stockage.choisirPersonnage`).
- Actions de `surAction` :

  | Action | Effet |
  | --- | --- |
  | `echap` | jeu ⇒ pause ; ailleurs ⇒ fermer |
  | `pause` | jeu ⇔ pause |
  | `niveaux` (R, bouton Niveaux) | écran des niveaux, sauf en jeu |
  | `valider`, `epee` | hors jeu : valider (titre → suite, choix, reprise, rejouer, niveau suivant) |
  | `jouer` (bouton) | jeu, pause, fin, victoire ⇒ rejouer le niveau ; sinon valider |
  | `tactile` (`nom` = commande) | sélection : ◀ ▶ déplacent, les autres valident ; titre, fin, victoire : valider |
  | `gauche`, `droite` / `haut`, `bas` | sélection : ±1 / ±1 (±3 sur la grille des niveaux) |

  `surPointeur` : titre, fin, victoire ⇒ valider ; sur une carte : la choisir, puis valider au second appui.
  Un personnage ou un niveau verrouillé est annoncé, jamais lancé.
- Ordre de dessin : `r.camera`, `r.commencer`, `r.decor` (décor du niveau, parallaxe du manifeste, voile),
  `r.plateformes`, puis hors menus ennemis, joueur, ondes, projectiles ; particules ; `r.finir` ; HUD (hors
  menus) ; surcouche (voile de l'interface, titre, cartes, pause, fin, victoire, chargement, erreur).
- HUD : cœurs (`pvMax`), Score, Record, `Vague n / total` ou `Survie m:ss`, jauge de recharge de la
  secondaire, nom et barre du boss, bannière de vague.

### 3.13 `modale.js` et `jeu.js`

```js
ynWE.modale = { ouvrir(config, universSlug?), fermer(), estOuvert() }   // jeu.js : window.ynWordEndJeu = ynWE.modale
```

- `<dialog class="yn-wordend">` ouvert par `showModal`, `html.yn-wordend-ouvert` ; Tab/Maj+Tab bouclent
  dans la modale ; Échap = pause puis fermer (l'événement `cancel` ne sert que si le `keydown` n'a pas
  atteint la modale) ; focus rendu à la fermeture ; `document` reçoit `yn:wordend` `{etat:
  'ouvert'|'ferme', univers}` ; pause à `visibilitychange`/`blur` ; `yn:theme` ⇒ `r.lirePalette()`.
- Contenu : titre `<h2>` (`sousTitre` du manifeste), canvas `role="img"` (`aria-label` = `textes.libelleEcran`),
  `<progress class="yn-visually-hidden yn-wordend__boss">` (vie du boss, `aria-valuetext`), listes
  `<select name="univers|personnage|niveau">` (`.yn-wordend__choix` ; Univers masquée s'il n'y a pas de
  choix ; entrées verrouillées désactivées ; listes désactivées en jeu et en pause), boutons Jouer
  (Valider / Rejouer), Pause (`aria-pressed`), Niveaux, son (bouton muet `aria-pressed`, curseur Volume ;
  masqués si l'univers n'a pas de musique), manettes tactiles, aide `#yn-wordend-aide`, zone
  `role="status"`.
- Aide et manettes suivent le personnage choisi : texte du bouton = `competences.*.bouton`, sinon d'après
  le type (`melee` Épée, `projectile` Tir, `onde` Charge, `ruee` Ruée, `parade` Parade) ; libellé =
  `libelle`.
- À l'ouverture : écran `chargement`, univers (mis en cache par slug), niveau par défaut
  (`config.depart.niveau` ou `arcade`), partie de fond non animée pour les écrans titre et de sélection,
  annonce `textes.pret`. Réouverture du même univers : titre sans recharger.
- Boucle à pas fixe (`DT`, au plus 5 pas par image) : `jeu` ⇒ `partie.mettreAJour` ; hors pause ⇒ particules
  seulement ; dessin à chaque image ; `ynWE.debug.ips`. `niveau:fin` ⇒ `stockage.enregistrerResultat` puis
  `ec.aller('victoire'|'fin', bilan)`.

## 4. Schémas JSON des univers

Tous les fichiers d'univers portent `"version": 2` (planches : `"version": 1`). Chemins relatifs au
dossier du manifeste ; le moteur ajoute `?ver=<version de l'univers>`. Encodage UTF-8, chaînes en
français. Les exemples sont ceux de l'univers `sukasuka`.

### 4.1 Manifeste `univers/<slug>/manifeste.json`

```json
{ "version": 2, "slug": "sukasuka", "titre": "WordEnd", "sousTitre": "Chtholly – Bats-toi contre ton destin",
  "oeuvre": "sukasuka",
  "personnages": ["personnages/chtholly.json", "personnages/nephren.json", "personnages/ithea.json"],
  "ennemis": ["ennemis/timere.json"],
  "niveaux": ["niveaux/arcade.json", "niveaux/01-plage.json", "niveaux/02-dunes.json", "niveaux/03-falaise.json", "niveaux/04-nuit.json", "niveaux/05-boss.json"],
  "decors": { "dunes": { "image": "decors/dunes.webp", "parallaxe": 0.25 } },
  "musiques": { "principale": { "fichier": "musiques/scarborough.mp3", "credit": "Scarborough Fair, trad." } },
  "textes": { "pret": "WordEnd est prêt. Appuyez sur Entrée ou sur Jouer pour commencer.",
              "libelleEcran": "Zone de jeu : Chtholly et son épée Seniolis face aux Timeres" } }
```

| Champ | Rôle |
| --- | --- |
| `slug` | nom du dossier de l'univers |
| `titre`, `sousTitre` | écran titre (grand titre, sous-titre) ; `sousTitre` = titre `<h2>` de la modale |
| `oeuvre` | slug de l'œuvre du catalogue |
| `personnages` | le premier est le personnage par défaut (toujours débloqué) |
| `ennemis` | tous chargés à l'ouverture |
| `niveaux` | `arcade` = niveau par défaut s'il est listé, sinon le premier ; les autres sont ordonnés par `numero` |
| `decors.<nom>` | `image` (960 × 540, horizon à 476 px = sol 238), `parallaxe` 0…1 |
| `musiques.<nom>` | `fichier`, `credit` (affiché en bas de l'écran titre) |
| `textes` | `pret` (annonce à l'ouverture), `libelleEcran` (`aria-label` du canvas) |

### 4.2 Personnage `personnages/<slug>.json`

```json
{ "version": 2, "slug": "chtholly", "nom": "Chtholly", "description": "Épée Seniolis : coup rapide et onde magique.",
  "planche": "chtholly", "lissage": true,
  "boite": { "l": 20, "h": 56 }, "pv": 5, "vitesseMarche": 72, "vitesseCourse": 138,
  "saut": { "impulsion": 330, "sautsMax": 1 },
  "poses": { "saut": ["course", 1], "chute": ["course", 3] },
  "textes": { "mort": "Chtholly est à terre." },
  "competences": {
    "principale": { "type": "melee", "animation": "attaque", "degats": 1, "boite": { "x": 4, "y": -62, "l": 60, "h": 58 }, "libelle": "Coup d’épée" },
    "secondaire": { "type": "onde", "animation": "charge", "chargeMin": 0.55, "degats": 3, "vitesse": 250, "vie": 1.1, "duree": 0.42, "recharge": 1.2, "libelle": "Charge magique" } },
  "debloque": true }
```

| Champ | Rôle |
| --- | --- |
| `nom`, `description` | cartes de sélection, annonces |
| `planche` | `<planche>.png` + `<planche>.planche.json` dans le même dossier (§4.5) |
| `teinte` | facultatif : filtre CSS appliqué une fois à la planche (§3.3) |
| `lissage` | `false` pour du pixel art |
| `boite` | corps touchable `{l, h}` (px logiques, ancré au sol) |
| `pv`, `vitesseMarche`, `vitesseCourse` | points de vie (5), vitesses en px/s (72, 138) |
| `saut` | `impulsion` (330 px/s ; hauteur = impulsion² / (2 × gravité)), `sautsMax` (1 ; 2 = double saut) |
| `poses.<etat>` | `[animation, indice]` figé quand la planche n'a pas d'animation `<etat>` (`saut`, `chute`, `parade`) |
| `textes.mort` | annonce à la mort (défaut « <nom> est à terre. ») |
| `competences` | `principale` (obligatoire, bouton Épée : J/X) et `secondaire` (facultative, K/C) |
| `debloque` | `true` (défaut) ou `{ "niveau": "<slug>" }` : débloqué quand ce niveau est fini |

Compétences (§3.7) : `type` ∈ `melee`, `onde`, `projectile`, `ruee`, `parade` ; champs communs facultatifs
`animation`, `recharge` (s), `libelle` (aide, manettes, annonces), `bouton` (texte du bouton tactile).

| Type | Champs (défauts) |
| --- | --- |
| `melee` | `degats` (1), `boite {x, y, l, h}` relative à l'ancre, côté droit (miroir à gauche) |
| `onde` | `chargeMin` (0,55), `degats` (3), `vitesse` (250), `vie` (1,1), `duree` (0,42), `recharge`, `depart {x, y}` (40, −34), `boiteOnde {l, h}` (28, 52) |
| `projectile` | `vitesse` (300), `degats` (1), `recharge`, `depart {x, y}` (22, −30), `vie` (1,4), `couleur` (`#ffe28a`), `perce` (faux) |
| `ruee` | `distance` (90), `duree` (0,2), `recharge` (1,5) |
| `parade` | `duree` (0,6), `recharge` (2) |

Personnages de `sukasuka` : Chtholly (ci-dessus) ; Nephren (`pv` 5, 66/128 px/s, `saut {310, sautsMax: 2}`,
`melee` `degats: 2`, `parade {0,6 s, recharge 2}`, `poses.parade`, `debloque {niveau: "01-plage"}`) ;
Ithea (`pv` 4, 78/150 px/s, `saut {340}`, `projectile {vitesse 300, degats 1, recharge 0,35}`,
`ruee {90 px, 0,2 s, recharge 1,5}`, `debloque {niveau: "03-falaise"}`).

### 4.3 Ennemi `ennemis/<slug>.json`

```json
{ "version": 2, "slug": "timere", "nom": "Timere", "planche": "timere", "lissage": false,
  "boite": { "l": 46, "h": 44 },
  "attaques": { "morsure": { "portee": 40, "hauteur": 34 }, "fouet": { "portee": 48, "hauteur": 36 } },
  "types": {
    "petit":    { "taille": 0.8, "pv": 1, "vitesse": 54,  "points": 10, "comportement": "marcheur", "attaques": ["morsure"] },
    "normal":   { "taille": 1,   "pv": 2, "vitesse": 38,  "points": 15, "comportement": "marcheur", "attaques": ["morsure", "fouet"] },
    "coureur":  { "taille": 0.9, "pv": 1, "vitesse": 112, "points": 20, "comportement": "coureur",  "attaques": ["morsure"] },
    "grand":    { "taille": 1.3, "pv": 5, "vitesse": 28,  "points": 40, "comportement": "marcheur", "attaques": ["fouet"], "stoique": true, "rechargeFacteur": 1.4 },
    "volant":   { "nom": "Timere ailé", "taille": 0.6, "pv": 1, "vitesse": 90, "points": 25, "comportement": "volant", "attaques": ["morsure"],
                  "altitude": 80, "amplitude": 14, "plongee": 260, "teinte": "hue-rotate(150deg) saturate(1.3) brightness(1.1)" },
    "tireur":   { "nom": "Timere cracheur", "taille": 0.9, "pv": 2, "vitesse": 30, "points": 30, "comportement": "tireur", "attaques": ["morsure"],
                  "distance": 150, "projectile": { "vitesse": 170, "degats": 1, "hauteur": 30, "couleur": "#c9f26a" }, "recharge": 2.4,
                  "teinte": "hue-rotate(-50deg) saturate(1.4)" },
    "bouclier": { "nom": "Timere cuirassé", "taille": 1.1, "pv": 3, "vitesse": 32, "points": 35, "comportement": "bouclier", "attaques": ["fouet"],
                  "teinte": "grayscale(0.55) brightness(0.9) contrast(1.1)" },
    "boss":     { "nom": "Timere géant", "taille": 2, "pv": 40, "vitesse": 26, "points": 500, "comportement": "boss", "attaques": ["fouet", "morsure"], "stoique": true,
                  "teinte": "hue-rotate(230deg) saturate(1.4) brightness(0.8)",
                  "charge": { "toutesLes": 6, "preparation": 0.6, "vitesse": 230, "degats": 1 },
                  "phases": [ { "pvSous": 1, "vitesseFacteur": 1, "invocations": null },
                              { "pvSous": 0.5, "vitesseFacteur": 1.3, "invocations": { "ennemi": "timere", "type": "petit", "n": 2, "toutesLes": 8 },
                                "texte": "Le Timere géant entre en rage et appelle des renforts !" } ] } } }
```

- Premier niveau : `nom`, `planche`, `teinte` (facultative, toute la planche), `lissage`, `boite` (corps à la
  taille 1), `attaques.<nom>` (`portee`, `hauteur` en px à la taille 1, `degats` facultatif, 1 ; le nom est
  aussi celui de l'animation de la planche), `couleursMort` (facultatif : 3 couleurs des particules de mort,
  défaut `#eae26e`, `#36502a`, `#36502a`), `types`.
- Type : `taille`, `pv`, `vitesse` (px/s), `points`, `comportement` ∈ `marcheur`, `coureur`, `volant`,
  `tireur`, `bouclier`, `boss`, `attaques` (liste ; la première donne la portée d'approche). Facultatifs :
  `nom` (HUD du boss, annonces), `stoique`, `rechargeFacteur` (multiplie la recharge entre deux attaques),
  `planche` (planche propre au type, même dossier), `teinte` (filtre CSS sur la planche du type), et par
  comportement : `volant` `altitude`, `amplitude`, `plongee`, `remontee` ; `tireur` `distance`, `recharge`,
  `geste` (animation du tir), `projectile {vitesse, degats, hauteur, couleur, vie, rayon}` ; `bouclier`
  `retournement` ; `boss` `entree`, `charge {toutesLes, preparation, vitesse, degats, depassement}`,
  `phases[] {pvSous, vitesseFacteur, invocations {ennemi, type, n, toutesLes, max} | null, texte}` (§3.9).
- En v2.1, les types sans planche propre partagent la planche du Timere, à leur `taille`, recolorés par
  `teinte`.

### 4.4 Niveau `niveaux/<slug>.json`

```json
{ "version": 2, "slug": "01-plage", "numero": 1, "titre": "La plage", "decor": "dunes", "musique": "principale",
  "largeur": 480, "sol": 238, "gravite": 900,
  "plateformes": [ { "x": 60, "y": 186, "l": 96 }, { "x": 324, "y": 186, "l": 96 } ],
  "apparition": { "x": 240 },
  "objectif": { "type": "vagues", "vagues": [
      { "delai": 1.2, "ennemis": [ { "ennemi": "timere", "type": "petit", "n": 3, "cote": "alterne", "intervalle": 1.6 } ] },
      { "delai": 1.8, "ennemis": [ { "ennemi": "timere", "type": "petit", "n": 2, "cote": "gauche", "intervalle": 1.8 },
                                    { "ennemi": "timere", "type": "normal", "n": 2, "cote": "droite", "intervalle": 2 } ] },
      … ] },
  "soinEntreVagues": 1,
  "etoiles": { "score": 400, "pvRestants": 4, "temps": 75 },
  "textes": { "intro": "La plage. Repoussez trois vagues de Timeres.", "vague": "Vague {n} sur {total} : {k} Timeres.",
              "victoire": "La plage est libérée !" },
  "suivant": "02-dunes" }
```

| Champ | Rôle |
| --- | --- |
| `numero`, `titre` | ordre et nom sur l'écran des niveaux (`arcade` : `numero` 0) |
| `decor`, `musique` | clés du manifeste |
| `largeur` (480), `sol` (238), `gravite` (900) | monde ; `largeur > 480` active la caméra |
| `plateformes[]` | `{x, y, l, type?, h?}` dans `[0, largeur]` ; `type` `traversable` (défaut) ou `solide`, `h` épaisseur (§3.6) |
| `apparition` | `{x}` du joueur (défaut 240) |
| `objectif` | voir ci-dessous |
| `soinEntreVagues` | PV rendus entre deux vagues (`vagues`) |
| `etoiles` | `{score, pvRestants, temps}` : critères des 2ᵉ et 3ᵉ étoiles (§3.10) |
| `textes` | `intro` (annonce au lancement, texte de la carte), `vague` (`{n}`, `{total}`, `{k}`), `restant` (survie, `{s}`), `victoire` |
| `voile` | facultatif : couleur CSS posée sur le décor (nuit, brume) |
| `suivant` | niveau lancé par Entrée sur l'écran de victoire (défaut : le suivant du manifeste) |

Objectifs (`objectif.type`, §3.10) :

| Type | Champs |
| --- | --- |
| `arcade` | `ennemi` (facultatif) |
| `vagues` | `vagues[] {delai, ennemis[] {ennemi, type, n, cote: gauche\|droite\|alterne\|aleatoire, intervalle, apparition {x, y?}, pvBonus, vitesseFacteur}}` |
| `survie` | `duree`, `bonus` (200), `generateur {ennemis[] {ennemi, type, poids, cote}, intervalle, delai, max, acceleration}` |
| `boss` | `ennemi`, `typeEnnemi` (`boss`), `x` |

Niveaux de `sukasuka` : `arcade` (480 px, sans plateforme, objectif `arcade`), `01-plage` (3 vagues),
`02-dunes` (960 px : caméra ; coureurs, volants, un grand), `03-falaise` (5 plateformes étagées, tireurs
posés sur les corniches par `apparition {x, y}`), `04-nuit` (survie 75 s, boucliers, voile
`rgba(8, 10, 38, 0.5)`), `05-boss` (Timere géant).

### 4.5 Planche `<nom>.planche.json` + `<nom>.png` (format v1)

```json
{ "version": 1, "echelle": 2, "planche": [809, 1048], "format": "images : [x, y, largeur, hauteur, ancre x, ancre y] en px de la planche",
  "animations": { "repos": { "ips": 2, "boucle": true, "images": [[0, 0, 168, 144, 49, 143], …] },
                  "attaque": { "ips": 14, "boucle": false, "coup": [1, 2, 3], "images": […] },
                  "charge": { "ips": 10, "boucle": false, "onde": 3, "images": […] } } }
```

`echelle` = pixels de planche par pixel logique ; ancre = pied (au sol) ; `coup` = images qui frappent ;
`onde` = image affichée pendant l'onde. Animations attendues : personnage `repos`, `marche`, `course`,
`attaque`, `charge`, `degats`, `mort` (+ `saut`, `chute`, `parade` facultatives : elles remplacent les
`poses`) ; ennemi `repos`, `marche`, `course`, `degats`, `mort` et une animation par attaque (Timere :
`fouet`, `morsure`). Produit par `tools/wordend/decouper-planche.py` d'après une description
`tools/wordend/source/<nom>.planche.json` (§4.6).

### 4.6 Description de planche `tools/wordend/source/<nom>.planche.json`

Entrée de `decouper-planche.py` (référence complète : `tools/wordend/README.md`) :

```json
{ "sortie": "chtholly", "echelle": 2, "hauteur": 144, "reference": ["repos", "marche"], "ancre": "buste",
  "sources": [ { "fichier": "chtholly-planche-gemini.jpg", "fond": "damier", "damier": { … }, "decoupe": "composantes",
                 "bandes": { "repos": { "zone": [80, 320, 0, 1411], "garder": [0, 1] }, … } } ],
  "rythmes": { "repos": { "ips": 2, "boucle": true }, "attaque": { "ips": 14, "boucle": false, "coup": [1, 2, 3] } },
  "ordre": ["repos", "marche", "course", "attaque", "charge", "degats", "mort"],
  "alignement": { "course": "tete" }, "bordsNets": ["course", "degats", "mort"],
  "variantes": { "nephren": { "teinte": 0, "saturation": 0.25, "luminosite": 1.15, "plage": [170, 260], "saturationMin": 40 } } }
```

- Par source : `fichier`, `fond` (`damier` ou `transparent`), seuils du détourage (`damier`, `seuilAlpha`,
  `titres`), `decoupe` (`composantes` ou `colonnes`) et `reglages`, ancre (`buste`, `pattes`, `centre`) et
  `sol`, `referenceHauteur`, `bandes.<animation> {zone, garder?, marges?, ancre?, sol?}`.
- `rythmes` est recopié tel quel dans la planche (`ips`, `boucle`, `coup`, `onde`).
- `variantes` : planches recolorées de même géométrie (`teinte`, `saturation`, `luminosite`, `plage`,
  `saturationMin`), produites avec `--variante <nom>` ou `--toutes-variantes`.
- Descriptions versionnées : `chtholly` (variantes `nephren`, `ithea`) et `timere` ; elles reproduisent à
  l'identique les planches du dépôt.

## 5. Règles de validation (test PHP `test-wordend.php`, `tools/wordend/valider.py`)

- Manifeste en version 2, `slug` = dossier ; listes `personnages`, `ennemis`, `niveaux` non vides ; images
  des décors et fichiers des musiques lisibles ; tout fichier référencé présent (la constante de test
  `YUME_TWE_ATTENDUS_LOTS` est vide ; `valider.py` ne signale qu'un avertissement pour un personnage ou un
  niveau secondaire absent, une erreur avec `--strict`).
- Chaque JSON en version 2, `slug` = nom du fichier. Planches : dimensions du PNG = `planche`, cadres dans
  l'image, ancres dans leur cadre, `coup` et `onde` existants.
- Personnages : `poses` → animations et indices existants ; `competences.principale` présente,
  `competences.*.type` ∈ {melee, onde, projectile, ruee, parade}, `animation` présente dans la planche ;
  `debloque` `true` ou `{niveau}` listé ; le premier personnage toujours débloqué (PHP).
- Ennemis : `types.*.comportement` ∈ {marcheur, coureur, volant, tireur, bouclier, boss} ;
  `types.*.attaques` déclarées dans `attaques` et animées dans la planche (du type s'il en a une) ;
  invocations résolues (`valider.py`).
- Niveaux : `objectif.type` ∈ {arcade, vagues, survie, boss} ; `decor`, `musique` et `suivant` résolus ;
  `largeur` ≥ 480 ; plateformes dans `[0, largeur]`, de type `traversable` ou `solide` ; ennemis et types des
  vagues, du générateur de survie (et du boss pour `valider.py`) déclarés.
- PHP seulement : registre des univers (assainissement, œuvre ↔ univers, défaut), configuration (14
  scripts dans l'ordre, URL versionnées), `universPage` selon la requête, déclencheur coupé par
  `yume_wordend_actif` ou un registre vide, papillon avec son univers.

## 6. Sauvegarde `localStorage['yn.wordend']`

```json
{ "version": 2, "volume": 0.5, "muet": false, "maj": "2026-09-30",
  "univers": { "sukasuka": { "parties": 12, "personnage": "chtholly", "arcade": { "meilleur": 1240 },
               "niveaux": { "01-plage": { "meilleur": 620, "etoiles": 2, "fini": true } } } } }
```

- `volume` 0…1 (0,5), `muet`, `maj` (date du dernier résultat) ; par univers : `parties` (toutes parties
  terminées), `personnage` (dernier choisi), `arcade.meilleur`, `niveaux.<slug>` `{meilleur, etoiles 0…3,
  fini}`. Valeurs normalisées à la lecture (entiers ≥ 0, étoiles ≤ 3).
- Migration v1 (`{meilleur, parties, maj, volume, muet}`) à la lecture, idempotente, sans perte :
  `meilleur` → `univers.sukasuka.arcade.meilleur` (maximum si déjà présent), `parties` ajoutées à
  `univers.sukasuka.parties`, `meilleur`/`parties` racine supprimés, autres champs conservés, `version: 2`
  écrit. Une clé `version > 2` n'est jamais modifiée.

## 7. Banc d'essai `tools/wordend/banc/`

```sh
php -S 127.0.0.1:8081 -t /home/user/Yume-WordPress
# jeu :        http://127.0.0.1:8081/tools/wordend/banc/?univers=sukasuka&niveau=arcade&personnage=chtholly[&auto=1]
# visionneuse : …/banc/?planche=personnages/chtholly   (ou ennemis/timere)
# aperçu :     …/banc/?apercu=niveaux/01-plage
```

- Jeu : pose `window.ynWordEnd` comme WordPress (scripts de `assets/moteur/` dans l'ordre, univers
  `…/assets/univers/<slug>/manifeste.json`, version `banc-<horodatage>` : pas de cache), `depart`
  `{personnage, niveau}` d'après l'adresse (listes et bouton « Appliquer »), charge `declencheur.js`
  (Konami et `ynWordEnd.ouvrir()` fonctionnent), bouton « Ouvrir le jeu » (`&auto=1` : ouverture
  immédiate), ligne d'état (`ynWordEndMoteur.debug` : état, ips, score, joueur, ennemis, caméra).
  Styles : jetons de la palette Nuit et classes minimales du thème.
- Visionneuse : animations d'une planche, lecture / pas à pas / vitesse / taille, cadre (orange), ancre
  (rouge), boîte de jeu du JSON d'entité (vert), images `coup` et `onde` signalées.
- Aperçu de niveau : décor répété, écrans (pointillés), sol, plateformes, apparition, résumé.
- Vérificateurs Playwright (`require('/opt/node22/lib/node_modules/playwright')`, code de sortie 1 en cas
  d'échec ou d'erreur console), chacun avec son serveur :

  | Script | Serveur | Commande | Couvre |
  | --- | --- | --- | --- |
  | `verifier-A.js` | 8081 | `node tools/wordend/banc/verifier-A.js [captures]` (`WORDEND_BANC` pour une autre adresse) | ressources, caméra, décor, audio, stockage v2 et migration |
  | `verifier-B.js` | 8082 | `node tools/wordend/banc/verifier-B.js [adresse du banc] [captures]` | physique, saut, plateformes, compétences, trois personnages |
  | `verifier-C.js` | 8083 | `node tools/wordend/banc/verifier-C.js [adresse du serveur] [captures]` | ennemis, projectiles, objectifs, six niveaux (`niveau.avancer`) |
  | `verifier-D.js` | 8084 | `node tools/wordend/banc/verifier-D.js [--port=8084] [--captures=<dossier>]` | parcours des écrans, listes, manettes, Tab, Échap, axe-core, thèmes |

  Serveur : `php -S 127.0.0.1:<port> -t <racine du dépôt>`. `ynWordEndMoteur.debug.etat()`, `.monde()`,
  `.partie()` et `ynWordEndMoteur.niveau.avancer()` servent aux assertions.
