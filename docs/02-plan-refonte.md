# 02 · Plan de refonte — Yume Novel v2

> Objectif : une **frappe chirurgicale**. Tout est construit et validé sur un site de préproduction,
> le contenu existant est migré par script, puis la bascule en production se fait en une seule
> opération planifiée, réversible par sauvegarde.

## 1. Vision

Yume Novel v2 est une **bibliothèque de light novels traduits**, pas un blog :

- l'**œuvre** est l'unité centrale (fiche, tomes, chapitres, planning, favoris) ;
- la **lecture en ligne** devient un vrai lecteur (mode nuit, réglages, marque-page, navigation) ;
- l'**équipe** publie depuis un formulaire unique et met à jour son planning sans toucher à WordPress ;
- les **lecteurs** ont un compte (favoris, notes, commentaires, reprise de lecture, alertes) ;
- le **code vit sur GitHub** et se déploie automatiquement sur WordPress.

Direction visuelle : « **Nocturne** » (bleu nuit, teal, titres Bricolage Grotesque), dérivée du système
visuel Angelith déjà utilisé par l'outillage de traduction Yume, avec un thème de lecture « Papier »
en clair. Voir `design/README.md` et les maquettes.

## 2. Plateforme : on reste sur le plan actuel

Décision de l'équipe : **aucun changement de plan**. Depuis avril 2026, tous les plans payants de
WordPress.com (Personal compris) permettent d'installer des extensions du répertoire **et de
téléverser un plugin ou un thème maison** (Extensions → Ajouter → Téléverser). La première
installation déclenche le transfert automatique du site vers l'hébergement managé « Atomic »,
inclus dans le plan, sans changement d'URL ni interruption.

| Ce que le plan actuel permet | Ce qu'il ne permet pas (Business) | Comment on contourne |
| --- | --- | --- |
| Extensions du répertoire, plugin et thème maison en zip, PHP, cron WordPress, API REST, mots de passe d'application | GitHub Deployments, SFTP/SSH, WP-CLI | Le plugin et le thème se **mettent à jour eux-mêmes depuis les releases GitHub** (bibliothèque Plugin Update Checker) ; la migration est une page d'administration du plugin, pas une commande |
| 6 Go de stockage (344 Mo utilisés) | Site de staging WordPress.com | Préproduction sur **WordPress Playground / wp-env** avec un export du site, puis répétition générale sur une copie locale |
| Jetpack Stats, Newsletter, Forms, Akismet (extension gratuite) | Jetpack Backup temps réel | Sauvegarde par export WordPress (XML) + médiathèque avant la bascule, plugin UpdraftPlus (gratuit) pour les sauvegardes régulières |

Tout le fonctionnel reste développé dans **notre propre plugin** : aucun add-on payant n'est
nécessaire. Les extensions premium de la marketplace (Yoast Premium, Elementor, Gravity Forms,
Astra Pro, WP-Manga, MemberPress…) sont **à éviter** : elles dupliqueraient ou contrediraient
l'architecture ci-dessous. Les add-ons « d'optimisation » utiles sont tous gratuits (§5).

## 3. Architecture cible

```
yumenovel.fr (WordPress.com Business / Atomic)
├── wp-content/themes/yume            ← thème bloc (FSE) « Yume », déployé depuis GitHub
│   ├── theme.json                    ← tokens Nocturne / Papier, typographie, espacements
│   ├── templates/                    ← front-page, single-yume_oeuvre, single-yume_tome,
│   │                                    single-yume_chapitre (lecteur), page-planning, page-equipe…
│   ├── parts/                        ← header, footer, reader-toolbar, reader-settings
│   └── patterns/                     ← grille d'œuvres, carte tome, bloc téléchargement
├── wp-content/plugins/yume-core      ← plugin métier, déployé depuis GitHub
│   ├── includes/post-types.php       ← CPT oeuvre / tome / chapitre + taxonomies
│   ├── includes/roles.php            ← rôles équipe + lecteur, capacités
│   ├── includes/rest/                ← API /yume/v1/* (publication, planning, progression, favoris…)
│   ├── includes/import/              ← convertisseurs DOCX → chapitres, EPUB → chapitres
│   ├── includes/planning/            ← modèle d'avancement, rappels (WP-Cron + Action Scheduler)
│   ├── includes/notifications/       ← e-mail (wp_mail), webhook Discord, file d'envoi
│   ├── includes/reader/              ← progression, réglages, marque-pages
│   ├── includes/social/              ← favoris, notes, modération commentaires
│   ├── includes/migration/           ← page d'administration « Migrer » (pages → CPT, redirections, simulation)
│   ├── includes/updater/             ← mise à jour automatique du plugin depuis les releases GitHub
│   ├── blocks/                       ← blocs Gutenberg : planning, grille œuvres, bouton téléchargement
│   └── assets/                       ← JS/CSS du lecteur, de l'espace équipe (build Vite)
└── (contenu : base de données WordPress, jamais dans Git)
```

Principes :

- **Un seul plugin, un seul thème**, versionnés, testés (PHPUnit + Playwright) et déployés par GitHub.
- **Aucune donnée saisie dans le thème** : les pages hubs, fiches, listes de chapitres et planning sont
  **générés** depuis les types de contenu. Fini les éditions manuelles.
- **Progressive enhancement** : le lecteur fonctionne sans JavaScript (texte + liens) ; le JS ajoute
  réglages, marque-page, raccourcis clavier.
- **Compatibilité** : le contenu existant est migré, les anciennes URL sont redirigées (301).

## 4. Fonctionnalités détaillées

### F1 · Planning public des avancées

- Page `/planning/` générée automatiquement : une ligne par **tome / arc en cours**, avec les étapes
  *Traduction → Relecture → Édition → Publication*, le pourcentage de chaque étape, le responsable
  (pseudo), la date cible et un indicateur d'état (**à l'heure / en retard / bloqué / publié**).
- Bloc « Prochaines sorties » (calendrier des jours de sortie : mercredi, samedi, dimanche, fériés)
  réutilisable sur l'accueil et sur chaque fiche œuvre.
- Filtre par œuvre, par type (LN / WN / Manga), par état. Flux RSS / JSON du planning pour le bot Discord.
- Historique : chaque mise à jour est journalisée (qui, quand, quoi) → transparence pour les lecteurs.

### F2 · Espace Team + authentification + rappels

- Rôles WordPress dédiés : `yume_gerant`, `yume_editeur` (publie), `yume_traducteur`, `yume_relecteur`,
  `yume_graphiste`. Les 4 administrateurs actuels sont réduits à 1 ou 2 administrateurs techniques.
- Connexion : identifiants WordPress (page de connexion habillée) + **SSO WordPress.com / 2FA** via
  Jetpack pour l'équipe. Option ultérieure : connexion Discord (OAuth) puisque l'équipe y vit déjà.
- Tableau de bord `/equipe/` (front-end, pas le wp-admin) :
  - « Mes tâches » : tomes/arcs dont je suis responsable, curseur d'avancement, changement d'étape,
    note libre, en 1 clic ;
  - « Retards » : ce qui dépasse la date cible ;
  - « Publier » : le formulaire F3 ;
  - « Journal » : dernières actions de l'équipe.
- Rappels automatiques (tâche planifiée quotidienne 9 h) :
  - date cible dépassée → e-mail au responsable + message Discord (webhook) ;
  - aucune mise à jour depuis 14 jours → rappel « mets ton planning à jour » ;
  - digest hebdomadaire aux gérants (lundi) : état global, retards, sorties de la semaine.
  - Les délais (14 jours, heure d'envoi, canal) sont réglables dans *Réglages → Yume*.

### F3 · Publication en un formulaire

Formulaire `/equipe/publier/` en 4 champs + zone de dépôt :

1. Œuvre (liste) · 2. Numéro et titre du tome / arc · 3. Couverture (dépôt) ·
4. Liens de téléchargement **PDF** et **EPUB** (liens externes saisis par l'équipe : **les fichiers
   ne sont jamais hébergés sur le site**) ·
5. **Zone drag & drop** : le **DOCX** du tome, source unique de la lecture en ligne (le fichier est
   analysé puis supprimé du serveur ; seuls les chapitres HTML et les illustrations restent).

À la validation, le plugin :

- crée le **tome** (brouillon) et ses **chapitres** convertis (voir F4), avec un aperçu ;
- met le planning à jour (étape *Publié*, 100 %) ;
- génère l'**article d'annonce** « Tome N de X disponible ! » (modèle éditable) ;
- au clic « Publier » : publie tout, envoie les notifications (Discord, e-mail aux lecteurs ayant
  l'œuvre en favori, newsletter Jetpack si activée), invalide le cache.

L'API `POST /yume/v1/publications` (jeton d'application) permet aussi à l'outil de traduction
(Yume-Trad / Angelith) de publier **sans passer par le formulaire**.

### F4 · Lecture en ligne — conversion du DOCX en chapitres

- Convertisseur PHP maison (pas de dépendance lourde) : lit `word/document.xml`, découpe sur `Titre1`,
  conserve `Titre2`, dialogues (« — »), pensées (italique + retrait), gras/italique, centrages,
  illustrations (extraites et versées dans la médiathèque). Détails : `04-import-docx-epub-lecteur.md`.
- Le même convertisseur existe en ligne de commande dans `tools/docx2chapters/` : il peut tourner
  dans GitHub Actions ou dans Yume-Trad et pousser les chapitres via l'API REST, sans passer par le
  formulaire.
- L'EPUB reste accepté en entrée (mêmes règles), mais le DOCX est la source de référence.
- Une **page par chapitre** (`/lire/{oeuvre}/{tome}/{n}/`), sommaire du tome, chapitre précédent /
  suivant, fil d'Ariane, barre de progression, temps de lecture estimé.
- Panneau **Paramètres de lecture** (conforme à la capture fournie) : taille, interligne, opacité du
  fond, police (Avenir/Literata/Merriweather/Arial/Roboto/Calibri/Times/Verdana/Georgia/Garamond/
  Trebuchet/Courier), thème (Nocturne / Papier / Sépia), largeur de colonne, « Valider » /
  « Réinitialiser ». Sauvegardé en local pour les visiteurs, synchronisé sur le compte pour les
  membres.
- Mise en page fidèle au Word : colonne ~68 caractères, texte justifié avec césure `hyphens: auto`
  (fr), tiret cadratin insécable, illustrations pleine largeur avec légende facultative.

### F5 · Comptes lecteurs

- Inscription e-mail + mot de passe (vérification e-mail, honeypot + Akismet + limitation de débit),
  rôle `lecteur`. Aucune donnée superflue (RGPD : pseudo, e-mail, préférences).
- **Favoris** (cœur sur chaque fiche), **note** 1–5 par œuvre (moyenne affichée), **commentaires**
  (natifs WordPress, sous chaque chapitre et tome, modération par l'équipe, réponses imbriquées).
- **Marque-page automatique** : la position (chapitre + paragraphe) est enregistrée pendant la lecture ;
  bouton « Reprendre » sur la fiche œuvre, l'accueil et la page compte.
- **Réglages de lecture** synchronisés (F4).
- **Alertes** : e-mail (et plus tard notification push web) à la publication d'un tome/chapitre d'une
  œuvre favorite ; fréquence réglable (immédiat / hebdo / jamais).
- Page `/compte/` : mes favoris avec état d'avancement, ma lecture en cours, mes notes, mes alertes,
  suppression du compte (RGPD).

### F6 · Refonte graphique et navigation

- Menu principal : **Bibliothèque** (LN · WN · Manga) · **Planning** · **Actualités** · **Lire**
  (reprendre) · **L'équipe** · **Soutenir** (Ko-fi) · recherche · bascule clair/sombre · compte.
- Accueil : « Reprendre la lecture » (membres), dernières sorties (cartes tomes avec boutons Lire /
  PDF / EPUB), planning en cours (3 lignes), œuvres à la une, actualités, partenaires, Ko-fi.
- Bibliothèque : grille de couvertures filtrable (type, statut, genre, alphabétique), tri par dernière
  sortie.
- Fiche œuvre : bandeau couverture + métadonnées structurées (schema.org `BookSeries`), boutons
  favori/note/reprendre, planning de l'œuvre, liste des tomes (couverture, Lire en ligne, PDF, EPUB,
  date), commentaires.
- Textes des modèles intégralement en français ; dates au format `j F Y`.

### F7 · Migration et redirections

Page d'administration *Yume → Migrer* (idempotente, bouton « Simuler » puis « Exécuter », journal
téléchargeable) :

1. Fiches œuvres → `yume_oeuvre` (métadonnées extraites du bloc « Noms / Scénario / … », synopsis,
   couverture) ; les blocs *media-text* « Tome N » → `yume_tome` avec les liens ClicTune PDF/EPUB.
2. Pages ARC → `yume_tome` (type *arc*) ; pages chapitres Silent Witch → `yume_chapitre` (contenu
   nettoyé : crédits extraits en métadonnées, images de navigation supprimées).
3. Hubs « Yume LN / Yume Manga » → remplacés par la bibliothèque générée.
4. Articles : suppression de « Non classé », ajout d'une taxonomie *œuvre liée*, catégorie
   « Sorties » vs « Actualités ».
5. Table de redirections 301 (ancien slug → nouvelle URL) écrite dans le plugin Redirection.
6. Rapport de migration (CSV) pour relecture par l'équipe.

### F8 · SEO, performance, conformité

- Rank Math (gratuit) : sitemap, balises Open Graph, schema `Book` / `BookSeries` / `Chapter`.
- Jetpack Boost (CSS critique, lazy-load), images WebP, cache WordPress.com.
- Bannière cookies légère (pas de traceur tiers hors Jetpack Stats anonymisé), mentions légales,
  politique de confidentialité, export/suppression de compte.
- Accessibilité : contrastes mesurés (règle Angelith 4,5:1), navigation clavier du lecteur, `lang="fr"`.

## 5. Extensions à installer (toutes gratuites)

| Extension | Rôle | Quand |
| --- | --- | --- |
| **yume-core** (zip depuis ce dépôt) | Tout le métier, mises à jour automatiques depuis GitHub | Phase 0 |
| **Thème yume** (zip depuis ce dépôt) | Design Nocturne / Papier | Phase 0 |
| **Jetpack** (déjà présent) | Stats, SSO/2FA de l'équipe, Newsletter (catégories = œuvres), Forms (contact) | Configurer en phase 0 |
| **Jetpack Boost** | CSS critique, report du JS, lazy-load, cache de pages | Phase 6 |
| **Akismet Anti-spam** | Commentaires et inscriptions (clé gratuite pour un site non commercial) | Phase 5 |
| **Redirection** | Redirections 301 de l'ancienne arborescence, journal des 404 | Jour J |
| **Rank Math SEO** (gratuit ; Yoast Premium inutile) | Sitemap, Open Graph, schema Book/BookSeries | Phase 6 |
| **Site Kit by Google** | Search Console et Analytics sans code | Phase 6 |
| **UpdraftPlus** (gratuit) | Sauvegardes planifiées vers Google Drive/Dropbox, point de restauration avant la bascule | Phase 0 |
| **WP Crontrol** | Vérifier que les rappels planifiés tournent | Phase 3 |

À **ne pas** installer : constructeurs de pages (Elementor, Divi, Astra Pro), formulaires payants
(Gravity Forms : le formulaire de publication est dans yume-core), plugins « manga/novel »
(Madara, WP-Manga), membership (MemberPress, BuddyPress, Ultimate Member), ACF ou Pods (les
métadonnées sont déclarées dans le plugin), caches tiers (WP Rocket : le cache WordPress.com et
Boost suffisent), plugins de « reading progress » ou de mode sombre (couverts par le thème),
WooCommerce, MailPoet. Un plugin de plus = une surface d'attaque et une dépendance de plus.

## 6. Pipeline GitHub → WordPress

Résumé (détails dans `05-pipeline-github-wordpress.md`) :

- Dépôt `GNAlexandre/Yume-WordPress` : `main` = version publiée, `develop` = travail en cours.
- Un tag `v2.x.y` sur `main` → GitHub Actions construit `yume-core.zip` et `yume.zip` et les attache
  à une **release GitHub**. Le site vérifie les releases toutes les 12 h et propose (ou applique
  automatiquement) la mise à jour dans *Extensions → Mises à jour*, comme pour n'importe quel plugin.
- La première installation se fait une seule fois par téléversement du zip.
- Contenu : le convertisseur `tools/docx2chapters` peut publier des chapitres depuis GitHub Actions ou
  Yume-Trad via l'API REST (mot de passe d'application).
- Le contenu (articles, œuvres, comptes) reste en base : il n'est jamais versionné.

## 7. Phasage (10 semaines, 1 développeur + relecture équipe)

| Phase | Semaine | Livrables | Jalon |
| --- | --- | --- | --- |
| 0 · Fondations | S1 | Transfert Atomic (première extension installée), UpdraftPlus + export XML, squelette thème + plugin avec auto-mise à jour GitHub, CI, préprod locale (wp-env / Playground) alimentée par l'export | Le plugin « hello world » s'installe par zip puis se met à jour depuis une release GitHub |
| 1 · Modèle | S2–S3 | CPT/taxonomies/rôles, API REST de base, page « Migrer » en simulation, rapport de migration | Bibliothèque et fiches générées en préprod avec les vraies données |
| 2 · Lecteur | S4–S5 | Convertisseur DOCX (PHP + CLI), modèle chapitre, panneau réglages, marque-page | Grimgar T.7 et Silent Witch lisibles en ligne en préprod |
| 3 · Planning + équipe | S6 | Planning public, espace équipe, rappels, journal | L'équipe met à jour son planning sans WordPress |
| 4 · Publication | S7 | Formulaire drag & drop, annonce auto, notifications, webhook Discord, API pour Yume-Trad | Une sortie publiée de bout en bout en < 5 minutes |
| 5 · Lecteurs | S8 | Inscription, favoris, notes, commentaires, alertes, page compte | Bêta ouverte à quelques lecteurs Discord |
| 6 · Finitions | S9 | SEO, redirections, perfs, textes FR, accessibilité, doc équipe, recette complète | Check-list de bascule validée |
| 7 · Bascule | S10 | Jour J (ci-dessous), surveillance 7 jours | v2 en production |

## 8. Jour J — procédure de bascule « one shot »

1. **J-2** : gel des publications (annonce Discord). Dernière migration simulée en préprod locale
   avec un export frais de la production ; validation du rapport.
2. **J-1** : sauvegarde complète (UpdraftPlus + export XML + copie de la médiathèque), nommée `avant-v2`.
3. **J, H0** : mode maintenance (page « Yume fait peau neuve », 1 h prévue).
4. **H0+5** : release `v2.0.0` sur GitHub → mise à jour du plugin et du thème depuis
   *Extensions → Mises à jour* (ou téléversement des deux zips la première fois).
5. **H0+10** : *Yume → Migrer → Exécuter* en production (idempotent), activation du thème, réglages
   (page d'accueil, permaliens, menus générés), import des redirections.
6. **H0+30** : recette de production (check-list : accueil, 3 fiches, 1 chapitre, planning, connexion
   équipe, formulaire en brouillon, inscription lecteur, 10 anciennes URL redirigées).
7. **H0+45** : fin de maintenance, annonce Discord/X, article « Un nouveau site pour Yume ».
8. **Rollback** (si nécessaire) : restauration `avant-v2` par UpdraftPlus, retour au thème précédent.

## 9. Risques et décisions à prendre

| Sujet | Décision attendue | Recommandation |
| --- | --- | --- |
| Plan WordPress.com | Rester sur le plan actuel | **Acté.** Transfert Atomic inclus ; pas de GitHub Deployments, remplacé par l'auto-mise à jour depuis les releases |
| Source de la lecture en ligne | DOCX | **Acté.** Le DOCX est converti en chapitres puis supprimé du serveur |
| PDF / EPUB | Jamais hébergés sur le site | **Acté.** Liens externes de téléchargement uniquement (ClicTune ou autre) |
| Manga | Lecteur d'images en v2 ? | Non en v2 : fiches + liens MangaDex ; lecteur manga en v2.1 |
| Commentaires | Natifs WordPress ou Discord | Natifs (SEO, comptes lecteurs), lien vers le fil Discord |
| Connexion Discord | OAuth en v2 ? | v2.1 (nécessite une application Discord) |
| Notifications push web | v2 ? | v2.1 ; e-mail suffisant au lancement |
| Conversation Claude partagée | Non consultable (lien privé) | À coller dans une issue si elle contient des décisions |
