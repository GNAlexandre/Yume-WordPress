# WordEnd — formats et interfaces du moteur (v2)

Contrat interne du jeu caché WordEnd (module `wordend`, voir `docs/wordend.md`) : interfaces JavaScript
de `window.ynWordEndMoteur`, schémas JSON des univers, format de sauvegarde et banc d'essai.

- **Ce document est figé** pour les lots parallèles A à F de WordEnd v2. Un lot qui doit s'en écarter le note
  dans son compte rendu, **sans modifier** `assets/moteur/00-espace.js`, `assets/moteur/jeu.js`,
  `assets/univers/sukasuka/manifeste.json` ni ce document : l'intégrateur arbitre.
- `window.ynWordEndMoteur` est **privé** (non contractuel pour le site) ; l'API publique reste
  `window.ynWordEnd.ouvrir()`, `window.ynWordEndJeu`, l'événement `yn:wordend` (contrat §14).
- Chemins relatifs à `wp-content/plugins/yume-core/includes/wordend/` sauf mention contraire.
- Les signatures marquées **(lot 0)** sont celles livrées par les fondations ; « stub » = fonction
  présente qui lève `Error('non implémenté : <nom>')` (via `ynWE.nonImplemente(nom)`) ou ne fait rien,
  à remplacer par le lot indiqué.

## 1. Fichiers, ordre de chargement, propriétaires

`declencheur.js` (façade, `defer`) charge à la première ouverture la feuille `config.style`, puis chaque
URL de `config.scripts` **dans l'ordre** (`<script async=false>` insérés dans l'ordre : exécutés dans cet
ordre) ; le chargement est résolu quand `window.ynWordEndJeu` existe. L'ordre est la constante PHP
`SCRIPTS_MOTEUR` (`fonctions.php`) — le banc la recopie (`tools/wordend/banc/banc.js`, tableau `SCRIPTS`).

| Ordre | Fichier `assets/moteur/` | Expose | Propriétaire après le lot 0 |
| --- | --- | --- | --- |
| 1 | `00-espace.js` | `ynWE` (constantes, outils, événements, `creerMonde`, planches, `debug`) | intégrateur (figé) |
| 2 | `stockage.js` | `ynWE.stockage` | lot A |
| 3 | `ressources.js` | `ynWE.ressources` | lot A |
| 4 | `audio.js` | `ynWE.audio` | lot A |
| 5 | `rendu.js` | `ynWE.rendu` | lot A |
| 6 | `physique.js` | `ynWE.physique` | lot B |
| 7 | `competences.js` | `ynWE.competences` | lot B |
| 8 | `joueur.js` | `ynWE.joueur` | lot B |
| 9 | `ennemis.js` | `ynWE.ennemis` | lot C |
| 10 | `niveau.js` | `ynWE.niveau` | lot C |
| 11 | `entrees.js` | `ynWE.entrees` | lot D |
| 12 | `ecrans.js` | `ynWE.ecrans` | lot D |
| 13 | `modale.js` | `ynWE.modale` | lot D |
| 14 | `jeu.js` | `window.ynWordEndJeu = ynWE.modale` | intégrateur (figé) |

Chaque fichier est une IIFE ES2019 (`var`, pas de `class`, `async`/`await` ni module ES) qui lit
`var ynWE = window.ynWordEndMoteur;` et ajoute **sa** clé. Un module n'utilise un autre module qu'à
l'exécution (dans une fonction), jamais au chargement, sauf `00-espace.js` (toujours déjà là).

Univers (`assets/univers/<slug>/`) : `manifeste.json` (intégrateur), `personnages/*` (lot B), `ennemis/*`
et `niveaux/*` (lot C), `decors/*`, `musiques/*`. PHP : `univers.php`, `fonctions.php`, `facade.php`,
`module.php`, `declencheur.js`, `secret.css`, `tests/test-wordend.php` (lot E) ; `jeu.css` (lot D) ;
`tools/wordend/*.py`, `tools/wordend/source/`, `tools/wordend/README.md` (lot F) ;
`tools/wordend/banc/verifier-<X>.js` (lot X).

## 2. Configuration `window.ynWordEnd` (PHP → déclencheur → modale)

```js
window.ynWordEnd = {
  version: '2.1.4',                       // YUME_CORE_VERSION
  style: '…/assets/jeu.css?ver=…',
  scripts: [ '…/assets/moteur/00-espace.js?ver=…', …, '…/assets/moteur/jeu.js?ver=…' ], // SCRIPTS_MOTEUR
  univers: { sukasuka: { titre: 'WordEnd', oeuvre: 'sukasuka',
                         manifeste: '…/assets/univers/sukasuka/manifeste.json?ver=<version>', version: '<version>' } },
  universParDefaut: 'sukasuka',
  universPage: '',                        // lot E : slug de l'univers de la fiche d'œuvre courante (Batcache : dépend de l'URL seulement)
  depart: { personnage: '', niveau: '' }, // FACULTATIF, banc seulement : personnage et niveau lancés sans écran de sélection
  ouvrir: function ( univers? ) {}        // ajouté par declencheur.js
};
```

- `version` d'un univers = `YUME_CORE_VERSION . '.' . filemtime(manifeste.json)` (`version_univers()`) ;
  le moteur l'ajoute (`?ver=`) à chaque fichier de l'univers.
- `declencheur.js` : `ouvrir(univers?)` ; un clic sur `[data-yn-wordend-ouvrir]` passe
  `data-yn-wordend-univers` s'il existe (lot E : l'attribut sur le papillon).
- Modale : univers ouvert = `univers || config.universPage || config.universParDefaut || première clé`.

## 3. Interfaces JavaScript (`ynWE = window.ynWordEndMoteur`)

### 3.1 `00-espace.js` (lot 0, figé)

```js
ynWE.LARGEUR = 480; ynWE.HAUTEUR = 270; ynWE.DENSITE = 2; ynWE.DT = 1/60;
ynWE.hasard(min, max) → nombre dans [min, max[
ynWE.chevauche(a, b) → bool             // boîtes {x, y, l, h} (x, y = coin haut gauche)
ynWE.limiter(v, min, max) → nombre
ynWE.nonImplemente(nom) → function qui lève Error('non implémenté : ' + nom)
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

Événements (`detail`) :

| Nom | detail | Émis par |
| --- | --- | --- |
| `annonce` | `{texte}` | tous (via `ynWE.annoncer`) |
| `score` | `{delta}` | `ynWE.ajouterScore` |
| `secousse` | `{duree}` | `ynWE.secouer` |
| `joueur:blesse` | `{pv}` | joueur |
| `joueur:mort` | `{}` | joueur |
| `ennemi:mort` | `{ennemi, points}` | ennemis |
| `vague:debut` | `{numero, total}` (`total` : `null` en arcade) | niveau |
| `niveau:fin` | `{resultat: 'gagne'\|'perdu', score, etoiles, temps, pv, niveau: slug}` | partie (une seule fois) |
| `partie:etat` | `{etat}` | écrans (`ec.aller`) |
| `son:changement` | `{volume, muet}` | audio (`regler`) |

Monde (données seulement, partagé par tous les modules) :

```js
monde = {
  univers, niveau /* JSON */, personnage /* chargé */, joueur: null, ennemis: [], projectiles: [], ondes: [], particules: [],
  camera: {x: 0, y: 0}, temps: 0 /* s de partie */, score: 0, secousse: 0 /* s */, mouvementReduit: false,
  palette: {} /* = r.palette, posé par la modale */, prochainId: 1,
  sol: niveau.sol ?? 238, largeur: niveau.largeur ?? 480, gravite: niveau.gravite ?? 900, plateformes: niveau.plateformes ?? [] }
```

`palette` et `mouvementReduit` sont recopiés du rendu par la modale après `niveau.demarrer` et à chaque
`yn:theme`. Ne jamais lire le DOM depuis joueur/ennemis/niveau : passer par `monde`.

### 3.2 `stockage.js` (lot A)

```js
ynWE.stockage = { CLE: 'yn.wordend', lire() → objet brut, fusionner(champs), reglagesSon() → {volume 0…1 (0,5), muet},
  enregistrerSon({volume, muet}),
  progression(universSlug) → {parties, arcade: {meilleur}, niveaux: {slug: {meilleur, etoiles, fini}}, personnage, debloques: {niveaux: [], personnages: []}},
  enregistrerResultat(universSlug, niveauSlug, {score, etoiles, fini}), choisirPersonnage(universSlug, slug), migrer() }
```

**Lot 0** : format v1 conservé (`{meilleur, parties, maj, volume, muet}`) ; `progression` reconstruit
`{parties, arcade: {meilleur}}` (reste vide) ; `enregistrerResultat` n'écrit que pour `niveauSlug === 'arcade'`
(meilleur = max, parties + 1, maj) ; `choisirPersonnage` et `migrer` sans effet. Lot A : format v2 (§6),
`migrer()` idempotent appelé à la lecture, `enregistrerResultat` garde le meilleur score et le maximum
d'étoiles, `fini` monotone. Toutes les lectures et écritures en `try/catch`.

### 3.3 `ressources.js` (lot A)

```js
ynWE.ressources = { chargerImage(url) → Promise<Image>, chargerJson(url) → Promise<objet>,
  chargerUnivers({manifeste: url, version, slug?}) → Promise<univers>,
  chargerPersonnage(univers, slug) → Promise<personnage>,     // mis en cache dans univers.personnages
  chargerNiveau(univers, slug) → Promise<niveau>,             // mis en cache dans univers.niveaux (lot 0, ajout au plan)
  chargerNiveaux(univers) → Promise<univers.niveaux>,         // tous ; absent ⇒ console.warn('[WordEnd] …') et retiré de ordre.niveaux
  urlRelative(univers, chemin) → univers.base + chemin + '?ver=' + univers.ver }

univers = { slug, manifeste /* JSON */, base /* URL du dossier du manifeste, avec « / » final */, ver,
  personnages: {slug: {...json, planche: {nom, image, meta}}}, ennemis: {slug: {...json, planche: {nom, image, meta}}},
  niveaux: {slug: json} /* chargés seulement */, decors: {nom: Image|null}, musiques: {nom: {url, credit}},
  ordre: { personnages: [slugs dans l'ordre du manifeste], niveaux: [slugs dans l'ordre du manifeste] },
  chemins: { personnages: {slug: chemin relatif}, niveaux: {slug: chemin relatif} } }
```

- Slug d'un personnage, d'un ennemi ou d'un niveau = **nom du fichier sans `.json`** ; il doit être égal au
  champ `slug` du JSON.
- `chargerUnivers` charge : le manifeste ; le **premier personnage** (obligatoire ; les autres à la demande) ;
  **tous les ennemis** (obligatoires) ; le **niveau par défaut** (`arcade` s'il est listé, sinon le premier ;
  obligatoire ; les autres à la demande — aucun 404 à l'ouverture si des fichiers listés manquent) ; les
  décors (échec toléré → `null`) ; l'URL des musiques (le fichier n'est lu qu'à la lecture).
- Planche d'une entité : `planche: "chtholly"` → `<dossier du JSON>/chtholly.png` + `chtholly.planche.json` ;
  après chargement `json.planche = {nom: 'chtholly', image, meta}`.
- Lot A : `teinte` (filtre CSS appliqué une fois sur un canvas hors écran → `planche.image` remplacée) ;
  planche propre à un type d'ennemi (`types.<t>.planche`) ; décor du seul niveau lancé.

### 3.4 `audio.js` (lot A)

```js
ynWE.audio = { creer() → audio }
audio.reglages() → {volume, muet} ; audio.regler({volume?, muet?})   // mémorise (stockage.enregistrerSon), émet 'son:changement'
audio.relire()                       // relit les réglages mémorisés (ouverture de la modale) — ajout lot 0
audio.musique(url)                   // choisit la piste (nouvel Audio en boucle si l'URL change) et la joue sauf muet/volume nul
audio.musique(null)                  // pause (position conservée)
audio.pauser() ; audio.reprendre() ; audio.effet(nom) /* sans effet en v2.1 */
```

L'élément `Audio` n'est créé qu'à la première lecture effective ; lecture refusée par le navigateur = silence.
La modale appelle `audio.musique(ouvert && etat === 'jeu' ? url de la musique du niveau : null)` à chaque
changement d'état ou de réglage.

### 3.5 `rendu.js` (lot A)

```js
ynWE.rendu = { creer(canvas) → r, ajouterParticule(monde, x, y, vx, vy, vie, couleur, taille, flotte), mettreAJourParticules(monde, dt) }
r.canvas ; r.ctx ; r.palette {fond, bande, carte, filet, texteFort, texte, texteFaible, accent, accent2, erreur} ; r.police ; r.mouvementReduit
r.lirePalette() → palette            // jetons --wp--preset--color--* + police des titres + mouvement réduit
r.camera(monde)                      // camera.x → limiter(joueur.x − 240, 0, largeur − 480), lissage 0,15 (1 en mouvement réduit)
r.commencer(monde|null)              // setTransform(DENSITE), lissage d'image, secousse (±2 px), translate(−camera.x) : coordonnées du MONDE
r.finir()                            // retour aux coordonnées de l'ÉCRAN (même décalage de secousse, comme la v1)
r.decor(image|null, camera, parallaxe, secours {sol})   // image 960×540 dessinée en 480×270 ; sans image : ciel dégradé + sol du thème
r.plateformes(monde)                 // lot 0 : bande carte/filet de 6 px ; lot A : style final
r.sprite(planche {image, meta}, nom, i, x, y, dir, echelleSup = 1, lissage = true, alpha = 1, flash = 0)
                                     // ancre en (x, y), retourné si dir < 0, échelle = meta.echelle / echelleSup ; flash > 0 : éclat blanc « lighter »
r.ombre(x, y, rayonX, rayonY, opacite)  // ellipse au sol (ajout lot 0)
r.jauge(x, y, l, h, part 0…1, couleur)  // fond filet + remplissage (ajout lot 0)
r.texte(contenu, x, y, taille, alignement, couleur, graisse)   // contour fond, remplissage texteFort par défaut
r.voile() ; r.coeur(x, y, plein) ; r.particules(monde) ; r.ajouterParticule(…) ; r.mettreAJourParticules(monde, dt)
```

Plafond de 300 particules ; gravité des particules 260 px/s² (8 si `flotte`). `r.texte`, `r.voile`,
`r.coeur`, le HUD et les surcouches s'utilisent **après** `r.finir()` (coordonnées de l'écran).

### 3.6 `physique.js` (lot B)

```js
ynWE.physique = { BORD: 18, corps(obj) → obj complété {x, y, vx: 0, vy: 0, l, h, auSol: false, traverse: 0},
  appliquer(corps, dt, monde, options {gravite: true, plateformes: true, bords: true}),
  boite(corps) → {x: x − l/2, y: y − h, l, h}, chevauche }
```

- `corps(obj)` complète et **renvoie le même objet** : le joueur et les ennemis sont leur propre corps
  (`j.corps === j`). `(x, y)` = ancre au sol, au milieu des pieds.
- **Lot 0** : sol plat sans gravité : `x += vx·dt`, bornes `[BORD, largeur − BORD]` si `bords`, `y = monde.sol`,
  `vy = 0`, `auSol = true`. Lot B : gravité `monde.gravite` (vy ≤ 600), plateformes traversables (collision
  seulement en descente, pieds au-dessus au pas précédent), `bas + saut` ⇒ `traverse = 0,25 s`, `type: "solide"`.
- Les ennemis ont `l`/`h` à la taille 1 (la boîte réelle est multipliée par `e.taille`, voir 3.9).

### 3.7 `competences.js` (lot B)

```js
ynWE.competences = { melee, onde, projectile, ruee, parade, enregistrer(nom, impl),
  frapper(monde, boite, degats, dejaTouches /* ids */, source? {type, dir}),   // frappe chaque ennemi vivant une fois ; sens du recul = côté de la boîte
  mettreAJourOndes(monde, dt), dessinerOndes(r, monde) }
impl = { demarrer(joueur, monde, def) → bool, mettreAJour(joueur, dt, monde, def, commandes {maintenu}),
         dessiner(r, joueur, monde, def) /* après le sprite */, animation(joueur, monde, def) → [nom, i] }
```

- `def` = `competences.principale|secondaire` du JSON du personnage ; `commandes.maintenu` = bouton de
  l'emplacement maintenu (`epee` pour principale, `competence` pour secondaire).
- Pendant une compétence : `joueur.emplacement` = `'principale'|'secondaire'` (clé de `joueur.recharges`),
  `joueur.phase` libre pour la compétence ; la compétence termine par `joueur.changerEtat('repos')`.
- **Lot 0** : `melee` (état `attaque` ; dégâts sur les images `coup` de `def.animation`, boîte `def.boite`
  relative à l'ancre, miroir si `dir < 0` ; une touche par ennemi et par coup) et `onde` (état `competence`,
  phases `concentration` → `onde` ; charge ≥ `chargeMin` puis relâcher ; onde `{x, y, dir, vie, t, touches,
  degats, vitesse, boite}` dans `monde.ondes`, départ `def.depart` = `{x: 40, y: −34}`, boîte `def.boiteOnde` =
  `{l: 28, h: 52}` ; recharge posée au lancer ; secousse 0,12 s ; image `anim.onde` pendant l'onde). Stubs :
  `projectile`, `ruee`, `parade` (lot B ; `parade` pose `j.pare = true`, lu par ennemis/projectiles).

### 3.8 `joueur.js` (lot B)

```js
ynWE.joueur = { creer(personnage, monde, entrees) → j, DUREE_MORT: 2.2, INVINCIBILITE: 1.2 }
j (= j.corps) : x, y, vx, vy, l, h, auSol, traverse, personnage, planche, entrees, dir ±1,
  etat 'repos'|'marche'|'course'|'saut'|'chute'|'attaque'|'competence'|'degats'|'mort', t (s dans l'état),
  pv, pvMax, invincible (s), recharges {principale, secondaire}, touches [], emplacement, phase
j.mettreAJour(dt, monde) ; j.boite() → {x − l/2, y − h − 2, l, h} (v1) ; j.blesser(sens, degats, monde) → bool
j.dessiner(r, monde) ; j.animationCourante() → [nom, i] ; j.changerEtat(etat) ; j.estFini() → mort depuis DUREE_MORT
```

Règles v1 reproduites (arcade) : marche 72, course 138 (valeurs du JSON), invincibilité 1,2 s, recul 150,
état `degats` 0,35 s (vx × 0,88), mort : vx × 0,9, 24 particules (6 en mouvement réduit), annonce
`textes.mort`, `joueur:mort`. `blesser` ignore le coup si mort ou invincible. Hors compétence, une impulsion
`epee` lance la principale, `competence` maintenue lance la secondaire (si sa recharge est nulle), sinon
déplacement. L'impulsion `epee` est lue (consommée) à chaque pas. Dessin : ombre au sol, clignotement pendant
l'invincibilité (opacité 0,6 fixe en mouvement réduit), `r.sprite`, puis `impl.dessiner`. Animation
`saut`/`chute` de la planche si elle existe, sinon `poses.saut`/`poses.chute` figées.

### 3.9 `ennemis.js` (lot C)

```js
ynWE.ennemis = { DUREE_FONDU: 0.6, creer(definition, typeNom, options {x, dir, taille?, pvBonus, vitesseFacteur}, monde) → e,
  mettreAJour(e, dt, monde), blesser(e, degats, sens, monde, source?), boite(e), boiteAttaque(e), dessiner(r, e, monde),
  comportements: { marcheur, coureur, volant, tireur, bouclier, boss }, enregistrerComportement(nom, impl),
  mettreAJourProjectiles(monde, dt), dessinerProjectiles(r, monde), separer(monde), retirerFinis(monde), changerEtat(e, etat) }
comportement = { entrer(e, monde), mettreAJour(e, dt, monde) → vx souhaité, attaquer?(e, monde) }
e (corps) : id, definition, planche, typeNom, type (données du type), comportement (nom), taille (type.taille × 0,94…1,06),
  x, y, l, h (taille 1), dir, etat 'marche'|'course'|'repos'|'attaque'|'degats'|'mort', t, attaque (nom), pv, pvMax, vitesse,
  points, recharge, flash, recul, touche, stoique
```

- `mettreAJour` (générique) : minuteries, recul × 0,86, mort (glisse de `recul`), `degats` (min(0,45 s,
  durée de l'animation)), `attaque` (touche le joueur une fois sur les images `coup` si `j.boite()` chevauche
  `boiteAttaque(e)`, dégâts `attaques.<nom>.degats` ou 1 ; puis recharge `hasard(0,8, 1,5) × type.rechargeFacteur`),
  sinon `vx = comportement.mettreAJour(…)` ; `x += (vx + recul)·dt`.
- `boite(e)` = `{x − l·taille/2, y − h·taille, l·taille, h·taille}` ; `boiteAttaque(e)` : portée et hauteur de
  `definition.attaques[e.attaque]` × taille, débutant 6 × taille devant l'ancre, 4 × taille au-dessus du sol.
- `blesser` : particules, `flash` 0,1 s ; mort ⇒ `ajouterScore(points)`, recul 90, 12 particules
  (`definition.couleursMort`, défaut Timere), `ennemi:mort` ; sinon recul 170 et état `degats` sauf
  `stoique` touché par ≤ 1 point. `source` : `{type: 'melee'|'onde'|'projectile'|…, dir}` (bouclier, lot C).
- **Lot 0** : `marcheur`/`coureur` (approche, attente, morsure/fouet selon la distance, fuite si le joueur est
  mort, aucune attaque hors de l'écran : `camera.x + 4 < x < camera.x + 476`, coureur : vitesse pleine au-delà
  de 50 px, sinon ≤ 46) ; `separer` et `retirerFinis` (mort + fondu 0,6 s, ou sorti de `[−140, largeur + 140]`)
  = v1. Stubs : `volant`, `tireur`, `bouclier`, `boss`, `mettreAJourProjectiles` (lève si un projectile existe).
- Dessin : ombre, `r.sprite(planche, anim, i, x, y, dir, taille, definition.lissage, alpha du fondu, flash)`.

### 3.10 `niveau.js` (lot C)

```js
ynWE.niveau = { demarrer(univers, niveauJson, personnage, entrees) → partie, objectifs: { arcade, vagues, survie, boss },
  enregistrerObjectif(nom, impl), calculerEtoiles(niveauJson, bilan) → 0…3, gabarit(texte, valeurs) /* « {n} » → valeur */ }
impl objectif = { demarrer(partie), mettreAJour(partie, dt), fini(partie) → 'gagne'|'perdu'|'' }
partie = { monde, niveau /* JSON */, etat 'enCours'|'gagne'|'perdu', objectif, vague? /* arcade : {numero, aFaire, minuterie, banniere, pause} */,
  boss? /* ennemi boss, lu par hud() */, mettreAJour(dt), hud() → {vague, total, objectif, boss: {pv, pvMax, nom}|null, temps, banniere} }
```

- `demarrer` : `creerMonde`, `joueur.creer`, `apparition.x`, objectif (`objectif.type`, défaut `arcade`).
- `partie.mettreAJour(dt)` (ordre v1) : temps, joueur, ennemis (`mettreAJour` de chacun, `separer`,
  `retirerFinis`), ondes, projectiles, objectif, secousse − dt, particules ; puis fin si `objectif.fini()` ou
  `joueur.estFini()` (⇒ `perdu`) : `etat` et **un seul** `niveau:fin`.
- Objectif `arcade` (lot 0, v1 exact) : première vague après 1,2 s ; vague n : `3 + 2n` ennemis
  (`objectif.ennemi`, défaut : premier ennemi de l'univers), types tirés dans `[petit, petit, normal, normal]`
  + `[coureur, normal]` dès n ≥ 2 + `grand` dès n ≥ 3 s'il n'y en a pas déjà un vivant ; apparition à −50 ou
  `largeur + 50` ; PV + 1 aux `normal` dès n ≥ 6 ; vitesse × `1 + min(0,5, 0,04n)` ; minuterie
  `max(0,5, 2,2 − 0,15n) × hasard(0,7, 1,2)` ; vague finie : bonus `50n`, pause 1,8 s, soin d'un PV toutes les
  2 vagues ; bannière 2 s ; annonce `textes.vague` (`{n}`, `{k}`). Jamais gagné. Stubs : `vagues`, `survie`, `boss`.
- `calculerEtoiles` : 0 si non gagné ; 1 pour finir, + 1 si `score ≥ etoiles.score`, + 1 si
  `pv ≥ etoiles.pvRestants` ou `temps ≤ etoiles.temps`.

### 3.11 `entrees.js` (lot D)

```js
ynWE.entrees = { creer(dialogue, ecran) → in, TOUCHES, SCHEMA_DEFAUT }
in.commande(nom) → bool (maintenu, clavier ou tactile) ; in.impulsion(nom) → bool (vraie une fois par appui : epee, competence, saut)
in.vider(quoi?)          // undefined : clavier + impulsions ; 'impulsions' ; 'tout' : + tactile
in.surTouche(e) → 'echap'|'pause'|'muet'|'valider'|'epee'|'gauche'|'droite'|'haut'|'bas'|''
in.relacher(e)           // keyup
in.actualiserManettes(schema [{nom, texte, libelle, bascule?}]) ; in.element (conteneur .yn-wordend__manettes) ;
in.surAppui = fn(nom)    // posé par la modale : appui tactile (démarre depuis titre/fin) ; in.courirTactile (bool)
```

`TOUCHES` (`e.code`) : `ArrowLeft`/`KeyA` gauche, `ArrowRight`/`KeyD` droite, `ArrowUp`/`KeyW`/`Space` saut,
`ArrowDown`/`KeyS` bas, `ShiftLeft`/`ShiftRight` courir, `KeyJ`/`KeyX` epee, `KeyK`/`KeyC` competence. M et P
sur `e.key` (AZERTY). `surTouche` : Échap ⇒ `preventDefault` ; Tab et modificateurs ignorés ; sur `INPUT`/`SELECT`
la touche garde son rôle (`stopPropagation` seulement) ; Entrée/Espace sur un bouton : le bouton agit ; sinon
`stopPropagation` (la page ne voit pas les touches du jeu). Entrée/Espace ⇒ `valider` (Espace pose aussi
l'impulsion `saut`). Commandes : `gauche`, `droite`, `saut`, `bas`, `courir`, `epee`, `competence`.

### 3.12 `ecrans.js` (lot D)

```js
ynWE.ecrans = { creer(contexte) → ec }
contexte = { r, stockage, config, univers() → univers|null, personnage() → personnage courant|null,
             ouvrirUnivers(slug), demarrerNiveau(universSlug, personnageSlug, niveauSlug), fermer() }
ec.etat 'chargement'|'titre'|'univers'|'personnage'|'niveaux'|'jeu'|'pause'|'fin'|'victoire'|'erreur' ; ec.temps ; ec.meilleur ; ec.bilan
ec.aller(etat, donnees?) /* émet 'partie:etat' ; 'fin'|'victoire' : donnees = bilan de niveau:fin */
ec.surAction(action)     /* actions de in.surTouche + 'jouer' (bouton Jouer/Rejouer) + 'tactile' */
ec.dessiner(monde|null, partie|null) ; ec.hud(monde, partie)
```

Ordre de dessin : `r.camera`, `r.commencer`, `r.decor` (décor du niveau, parallaxe du manifeste),
`r.plateformes`, ennemis, joueur (sauf titre), ondes, projectiles, particules, `r.finir`, HUD (sauf titre),
surcouche. **Lot 0** : titre, jeu (HUD v1 : cœurs `pvMax`, Score, Record, Vague, jauge de recharge de la
secondaire, bannière), pause, fin, erreur réels ; `univers`/`personnage`/`niveaux` traversés directement
vers `demarrerNiveau(univers, config.depart.personnage || premier, config.depart.niveau || 'arcade')` ;
`victoire` minimale.

### 3.13 `modale.js` (lot D) et `jeu.js` (figé)

```js
ynWE.modale = { ouvrir(config, universSlug?), fermer(), estOuvert() }   // jeu.js : window.ynWordEndJeu = ynWE.modale
```

`<dialog class="yn-wordend">` `showModal`, `html.yn-wordend-ouvert`, Échap = pause puis fermer (événement
`cancel` seulement si le `keydown` n'a pas atteint la modale), focus rendu, `yn:wordend` `{etat:
'ouvert'|'ferme', univers}`, pause à `visibilitychange`/`blur`, `yn:theme` ⇒ `r.lirePalette()`. Boucle à pas
fixe (`DT`, au plus 5 pas par image) : `jeu` ⇒ `partie.mettreAJour` ; `titre`/`fin`/`victoire` ⇒ particules
seulement ; dessin à chaque image ; `ynWE.debug.ips`. `niveau:fin` ⇒ `stockage.enregistrerResultat` puis
`ec.aller('fin'|'victoire', bilan)`. À l'ouverture : écran `chargement`, univers (mis en cache), niveau par
défaut, partie de fond (non animée) pour l'écran titre, annonce `textes.pret`.

## 4. Schémas JSON des univers

Tous les fichiers portent `"version": 2`. Chemins relatifs au dossier du manifeste ; le moteur ajoute
`?ver=<version de l'univers>`. Encodage UTF-8, chaînes en français.

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
| `titre`, `sousTitre` | écran titre (grand titre, sous-titre) ; `sousTitre` = titre `<h2>` de la modale |
| `oeuvre` | slug de l'œuvre du catalogue (papillon, `universPage`) |
| `personnages` | le premier est le personnage par défaut |
| `niveaux` | ordre de l'écran de sélection ; `arcade` = niveau par défaut s'il est listé |
| `decors.<nom>` | `image` (960 × 540, horizon à 476 px = sol 238), `parallaxe` 0…1 |
| `musiques.<nom>` | `fichier`, `credit` (affiché sur l'écran titre, lot D) |
| `textes` | `pret` (annonce à l'ouverture), `libelleEcran` (`aria-label` du canvas) |

Le manifeste sukasuka liste dès le lot 0 les fichiers des lots B (`nephren.json`, `ithea.json`) et C
(`01-plage` … `05-boss`) : **ils doivent être créés avec exactement ces noms**. Absents, ils sont tolérés
(chargement à la demande ; test PHP : liste `YUME_TWE_ATTENDUS_LOTS`, à vider à l'intégration).

### 4.2 Personnage `personnages/<slug>.json` (lot B)

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

- `planche` → `<planche>.png` + `<planche>.planche.json` dans le même dossier (format §4.5).
- `lissage` : `false` pour du pixel art. `boite` : corps touchable (px logiques, ancré au sol).
- `poses.<etat>` : `[animation, indice]` figé quand la planche n'a pas d'animation `<etat>` (si elle existe,
  elle a priorité) ; utilisées pour `saut`, `chute` (et `parade`, lot B).
- `competences.principale|secondaire.type` ∈ `melee`, `onde`, `projectile`, `ruee`, `parade` ; champs
  facultatifs communs : `animation`, `recharge` (s), `libelle` (manettes, aide).
  - `melee` : `degats`, `boite {x, y, l, h}` relative à l'ancre (côté droit ; miroir à gauche).
  - `onde` : `chargeMin`, `degats`, `vitesse`, `vie`, `duree`, `recharge` ; facultatifs `depart {x, y}`, `boiteOnde {l, h}`.
  - `projectile` : `vitesse`, `degats`, `recharge` (Ithea) ; `ruee` : `distance`, `duree`, `recharge` ;
    `parade` : `duree`, `recharge` (Nephren).
- `debloque` : `true` ou `{ "niveau": "03-falaise" }`.
- Nephren : `saut.sautsMax: 2`, principale `melee` `degats: 2`, secondaire `parade {duree: 0.6, recharge: 2}`.
  Ithea : `pv: 4`, principale `projectile {vitesse: 300, degats: 1, recharge: 0.35}`, secondaire
  `ruee {distance: 90, duree: 0.2, recharge: 1.5}`. Planches provisoires = Chtholly recolorée.

### 4.3 Ennemi `ennemis/<slug>.json` (lot C)

```json
{ "version": 2, "slug": "timere", "nom": "Timere", "planche": "timere", "lissage": false,
  "boite": { "l": 46, "h": 44 },
  "attaques": { "morsure": { "portee": 40, "hauteur": 34 }, "fouet": { "portee": 48, "hauteur": 36 } },
  "types": {
    "petit":    { "taille": 0.8, "pv": 1, "vitesse": 54,  "points": 10, "comportement": "marcheur", "attaques": ["morsure"] },
    "normal":   { "taille": 1,   "pv": 2, "vitesse": 38,  "points": 15, "comportement": "marcheur", "attaques": ["morsure", "fouet"] },
    "coureur":  { "taille": 0.9, "pv": 1, "vitesse": 112, "points": 20, "comportement": "coureur",  "attaques": ["morsure"] },
    "grand":    { "taille": 1.3, "pv": 5, "vitesse": 28,  "points": 40, "comportement": "marcheur", "attaques": ["fouet"], "stoique": true, "rechargeFacteur": 1.4 },
    "volant":   { "taille": 0.6, "pv": 1, "vitesse": 90,  "points": 25, "comportement": "volant", "attaques": ["morsure"], "altitude": 80, "amplitude": 14, "plongee": 260 },
    "tireur":   { "taille": 0.9, "pv": 2, "vitesse": 30,  "points": 30, "comportement": "tireur", "attaques": ["morsure"], "distance": 150, "projectile": { "vitesse": 170, "degats": 1, "hauteur": 30, "couleur": "#c9f26a" }, "recharge": 2.4 },
    "bouclier": { "taille": 1.1, "pv": 3, "vitesse": 32,  "points": 35, "comportement": "bouclier", "attaques": ["fouet"] },
    "boss":     { "taille": 2,   "pv": 40, "vitesse": 26, "points": 500, "comportement": "boss", "attaques": ["fouet", "morsure"], "stoique": true,
                  "phases": [ { "pvSous": 1, "vitesseFacteur": 1, "invocations": null },
                              { "pvSous": 0.5, "vitesseFacteur": 1.3, "invocations": { "ennemi": "timere", "type": "petit", "n": 2, "toutesLes": 8 } } ] } } }
```

- Lot 0 : seulement les 4 types v1 (`petit`, `normal`, `coureur`, `grand`) ; le lot C ajoute les 4 autres.
- `attaques.<nom>` : `portee`, `hauteur` (px à la taille 1), `degats` facultatif (1) ; le nom est aussi celui de
  l'animation de la planche. `types.<t>.attaques` : la première donne la portée d'approche.
- `rechargeFacteur` (ajout lot 0) : multiplie la recharge entre deux attaques (v1 : 1,4 pour le grand).
- `couleursMort` facultatif : 3 couleurs des particules de mort (défaut Timere `#eae26e`, `#36502a`, `#36502a`).
- Pas de planche dédiée en v2.1 : un type sans `planche` propre utilise celle de l'ennemi, à sa `taille`,
  avec `teinte` facultative (`"teinte": "hue-rotate(40deg)"`, lot A).
- `comportement` ∈ `marcheur`, `coureur`, `volant`, `tireur`, `bouclier`, `boss`.

### 4.4 Niveau `niveaux/<slug>.json` (lot C)

```json
{ "version": 2, "slug": "01-plage", "numero": 1, "titre": "La plage", "decor": "dunes", "musique": "principale",
  "largeur": 480, "sol": 238, "gravite": 900,
  "plateformes": [ { "x": 150, "y": 178, "l": 96 }, { "x": 320, "y": 140, "l": 64 } ],
  "apparition": { "x": 240 },
  "objectif": { "type": "vagues", "vagues": [
      { "delai": 1.2, "ennemis": [ { "ennemi": "timere", "type": "petit", "n": 3, "cote": "alterne", "intervalle": 1.6 } ] },
      { "delai": 1.8, "ennemis": [ { "ennemi": "timere", "type": "normal", "n": 3, "cote": "droite", "intervalle": 1.4 },
                                    { "ennemi": "timere", "type": "volant", "n": 2, "cote": "gauche", "intervalle": 2 } ] } ] },
  "soinEntreVagues": 1,
  "etoiles": { "score": 400, "pvRestants": 4, "temps": 90 },
  "textes": { "intro": "Défendez la plage." },
  "voile": "rgba(10,10,40,0.5)",
  "suivant": "02-dunes" }
```

- `decor`, `musique` : clés du manifeste. `largeur > 480` active la caméra. `plateformes[].type` :
  `"traversable"` (défaut) ou `"solide"` ; plateformes dans `[0, largeur]`.
- `objectif.type` ∈ `arcade` (générateur v1 ; `ennemi` facultatif), `vagues` (`vagues[]` : `delai`,
  `ennemis[]` : `ennemi`, `type`, `n`, `cote` `gauche|droite|alterne|aleatoire`, `intervalle`, `apparition {x, y}`
  facultative), `survie` (`duree`, `generateur {ennemis, intervalle}`), `boss` (`ennemi`, `type`, `x`).
- `textes` : `intro` (annonce au lancement), `vague` (arcade : `"Vague {n} : {k} Timeres."`).
- `voile` (facultatif, lot A dessine) : couleur posée sur le décor. `suivant` : slug du niveau suivant.
- Déblocage : niveau `n` débloqué quand `n − 1` est `fini` ; `arcade` toujours débloqué.
- `niveaux/arcade.json` (lot 0) : `numero: 0`, `largeur: 480`, `plateformes: []`, `objectif {type: "arcade", ennemi: "timere"}`.

### 4.5 Planche `<nom>.planche.json` + `<nom>.png` (format v1 inchangé)

```json
{ "version": 1, "echelle": 2, "planche": [809, 1048], "format": "images : [x, y, largeur, hauteur, ancre x, ancre y] en px de la planche",
  "animations": { "repos": { "ips": 2, "boucle": true, "images": [[0, 0, 168, 144, 49, 143], …] },
                  "attaque": { "ips": 14, "boucle": false, "coup": [1, 2, 3], "images": […] },
                  "charge": { "ips": 10, "boucle": false, "onde": 3, "images": […] } } }
```

`echelle` = pixels de planche par pixel logique ; ancre = pied (au sol) ; `coup` = images qui frappent ;
`onde` = image affichée pendant l'onde. Produit par `tools/wordend/decouper-planche.py` (lot F).
Animations attendues : personnage `repos`, `marche`, `course`, `attaque`, `charge`, `degats`, `mort`
(+ `saut`, `chute` facultatives) ; Timere `repos`, `marche`, `course`, `fouet`, `morsure`, `degats`, `mort`.

## 5. Règles de validation (test PHP `test-wordend.php`, `tools/wordend/valider.py`)

Manifeste en version 2 ; chaque fichier référencé lisible (sauf `YUME_TWE_ATTENDUS_LOTS` avant intégration) ;
planches : dimensions du PNG = `planche`, cadres dans l'image, ancres dans leur cadre, `coup` existants ;
`poses` → animations et indices existants ; `competences.*.type` ∈ {melee, onde, projectile, ruee, parade} ;
`types.*.comportement` ∈ {marcheur, coureur, volant, tireur, bouclier, boss} et `types.*.attaques` déclarées
et animées ; `objectif.type` ∈ {arcade, vagues, survie, boss} ; `decor`/`musique`/`suivant` résolus ;
plateformes dans `[0, largeur]`. PHP (`univers.php`) : `UNIVERS_INTEGRES`, `univers()`, `chemin_manifeste()`,
`version_univers()`, `fichiers_univers()` (manifeste, JSON référencés, planches des JSON lisibles, décors,
musiques) ; `fichiers_requis()` = déclencheur, feuilles, `SCRIPTS_MOTEUR`, `fichiers_univers()`.

## 6. Sauvegarde `localStorage['yn.wordend']`

v1 (lot 0, contrat §14 actuel) : `{meilleur, parties, maj: 'YYYY-MM-DD', volume: 0…1, muet}`.

v2 (lot A) :

```json
{ "version": 2, "volume": 0.5, "muet": false, "maj": "2026-09-30",
  "univers": { "sukasuka": { "parties": 12, "personnage": "chtholly", "arcade": { "meilleur": 1240 },
               "niveaux": { "01-plage": { "meilleur": 620, "etoiles": 2, "fini": true } } } } }
```

Migration (`stockage.migrer()`, à la lecture, idempotente) : sans `version` ⇒
`univers.sukasuka.arcade.meilleur = meilleur`, `parties` déplacé, `volume`/`muet` conservés, `version: 2`
écrit, `meilleur`/`parties` racine supprimés ; jamais de perte.

## 7. Banc d'essai `tools/wordend/banc/`

```sh
php -S 127.0.0.1:8081 -t /home/user/Yume-WordPress
# jeu :        http://127.0.0.1:8081/tools/wordend/banc/?univers=sukasuka&niveau=arcade&personnage=chtholly[&auto=1]
# visionneuse : …/banc/?planche=personnages/chtholly   (ou ennemis/timere)
# aperçu :     …/banc/?apercu=niveaux/01-plage
```

- Jeu : pose `window.ynWordEnd` comme WordPress (scripts de `assets/moteur/` dans l'ordre, univers
  `…/assets/univers/<slug>/manifeste.json`, version `banc-<horodatage>` : pas de cache), `depart`
  `{personnage, niveau}` d'après l'adresse, charge `declencheur.js` (Konami et `ynWordEnd.ouvrir()`
  fonctionnent), bouton « Ouvrir le jeu », ligne d'état (`ynWordEndMoteur.debug` : état, ips, score,
  joueur, ennemis, caméra). Styles : jetons de la palette Nuit et classes minimales du thème.
- Visionneuse : animations d'une planche, lecture / pas à pas / vitesse / taille, cadre (orange), ancre
  (rouge), boîte de jeu du JSON d'entité (vert), images `coup` et `onde` signalées.
- Aperçu de niveau : décor répété, écrans (pointillés), sol, plateformes, apparition, résumé.
- Tests Playwright par lot : `tools/wordend/banc/verifier-<X>.js`
  (`require('/opt/node22/lib/node_modules/playwright')`), port 8081 + rang du lot (A 8081 … F 8086).
  `ynWordEndMoteur.debug.etat()`, `.monde()`, `.partie()` servent aux assertions.
