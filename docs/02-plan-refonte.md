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

## 2. Décision plateforme (prérequis absolu)

| Option | Plugins / code | GitHub → WordPress | Préprod | Coût | Verdict |
| --- | --- | --- | --- | --- | --- |
| Rester en « Simple » (Personal) | ✗ | ✗ | ✗ | 48 €/an | **Impossible** pour le cahier des charges. |
| Premium (transfert Atomic possible) | ✓ plugins | ✗ (pas de SSH/Git) | ✗ | 96 €/an | Insuffisant : déploiement manuel par zip. |
| **Business (Atomic)** | ✓ plugins + PHP + SFTP/SSH/WP-CLI | ✓ **GitHub Deployments** | ✓ **site de staging** + sync | 300 €/an | **Recommandé.** |
| Auto-hébergement (o2switch, OVH…) | ✓ | ✓ (Actions + SSH) | à monter soi-même | ~60–100 €/an + temps | Possible, mais on perd Jetpack Backup, le CDN, la sécurité managée et le transfert sans friction. |

**Recommandation : passer à WordPress.com Business** (le transfert Simple → Atomic est automatique,
sans changement de domaine ni d'URL). Aucun module premium n'est nécessaire au-delà du plan : tout
le fonctionnel est développé dans notre propre plugin. Les « add-ons premium » de la marketplace
(thèmes payants, WP-Manga, MemberPress, Elementor…) sont **à éviter** : ils dupliqueraient ou
contrediraient l'architecture ci-dessous.

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
│   ├── includes/migration/           ← commande WP-CLI `wp yume migrate` (pages → CPT, redirections)
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
4. Liens ClicTune **PDF** et **EPUB** (saisis par l'utilisateur, jamais hébergés) ·
5. **Zone drag & drop** : fichier **DOCX et/ou EPUB** (source de la lecture en ligne) ; le PDF n'est
   qu'un lien.

À la validation, le plugin :

- crée le **tome** (brouillon) et ses **chapitres** convertis (voir F4), avec un aperçu ;
- met le planning à jour (étape *Publié*, 100 %) ;
- génère l'**article d'annonce** « Tome N de X disponible ! » (modèle éditable) ;
- au clic « Publier » : publie tout, envoie les notifications (Discord, e-mail aux lecteurs ayant
  l'œuvre en favori, newsletter Jetpack si activée), invalide le cache.

L'API `POST /yume/v1/publications` (jeton d'application) permet aussi à l'outil de traduction
(Yume-Trad / Angelith) de publier **sans passer par le formulaire**.

### F4 · Lecture en ligne — conversion DOCX/EPUB en chapitres

- Convertisseur PHP maison (pas de dépendance lourde) : lit `word/document.xml`, découpe sur `Titre1`,
  conserve `Titre2`, dialogues (« — »), pensées (italique + retrait), gras/italique, centrages,
  illustrations (extraites et versées dans la médiathèque). Détails : `04-import-docx-epub-lecteur.md`.
- Convertisseur EPUB : lit l'OPF, une entrée de `spine` = un chapitre (ou découpe sur `<h1>`),
  conserve les classes CSS, importe les images.
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

Commande `wp yume migrate` (idempotente, mode `--dry-run`) :

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

## 5. Plugins à installer (après passage Atomic)

| Plugin | Rôle | Statut |
| --- | --- | --- |
| **Jetpack** (inclus) | Stats, Backup (VaultPress), SSO/2FA, Newsletter, Boost, Forms (contact) | Déjà présent, à configurer |
| **Akismet** (inclus Business) | Anti-spam commentaires et inscriptions | À activer |
| **Redirection** | Redirections 301 de l'ancienne arborescence, journal des 404 | À installer (gratuit) |
| **Rank Math SEO** (ou Yoast) | SEO, schema, sitemap | À installer (gratuit) |
| **yume-core** | Tout le métier | Développé dans ce dépôt |
| **Thème yume** | Design | Développé dans ce dépôt |

À **ne pas** installer : constructeurs de pages (Elementor, Divi), plugins « manga/novel » (Madara,
WP-Manga), membership (MemberPress, BuddyPress), ACF (les métadonnées sont déclarées dans le
plugin), plugins de « reading progress » ou de mode sombre (couverts par le thème). Un plugin de plus
= une surface d'attaque et une dépendance de plus.

## 6. Pipeline GitHub → WordPress

Résumé (détails dans `05-pipeline-github-wordpress.md`) :

- Dépôt `GNAlexandre/Yume-WordPress` : `main` = production, `develop` = staging.
- **WordPress.com GitHub Deployments** connecte chaque branche à un site (production / staging) et
  déploie `wp-content/plugins/yume-core` et `wp-content/themes/yume` à chaque push, après un build
  GitHub Actions (lint PHP, tests, build Vite).
- Le contenu (articles, œuvres, comptes) reste en base : il n'est jamais versionné.

## 7. Phasage (10 semaines, 1 développeur + relecture équipe)

| Phase | Semaine | Livrables | Jalon |
| --- | --- | --- | --- |
| 0 · Fondations | S1 | Plan Business, transfert Atomic, staging, GitHub Deployments, squelette thème + plugin, CI | Déploiement automatique d'un « hello world » sur staging |
| 1 · Modèle | S2–S3 | CPT/taxonomies/rôles, API REST de base, `wp yume migrate --dry-run`, rapport de migration | Bibliothèque et fiches générées sur staging avec les vraies données |
| 2 · Lecteur | S4–S5 | Convertisseurs DOCX/EPUB, modèle chapitre, panneau réglages, marque-page | Grimgar T.7 et Silent Witch lisibles en ligne sur staging |
| 3 · Planning + équipe | S6 | Planning public, espace équipe, rappels, journal | L'équipe met à jour son planning sans WordPress |
| 4 · Publication | S7 | Formulaire drag & drop, annonce auto, notifications, webhook Discord, API pour Yume-Trad | Une sortie publiée de bout en bout en < 5 minutes |
| 5 · Lecteurs | S8 | Inscription, favoris, notes, commentaires, alertes, page compte | Bêta ouverte à quelques lecteurs Discord |
| 6 · Finitions | S9 | SEO, redirections, perfs, textes FR, accessibilité, doc équipe, recette complète | Check-list de bascule validée |
| 7 · Bascule | S10 | Jour J (ci-dessous), surveillance 7 jours | v2 en production |

## 8. Jour J — procédure de bascule « one shot »

1. **J-2** : gel des publications (annonce Discord). Dernier `wp yume migrate --dry-run` sur staging
   avec un export frais de la production ; validation du rapport.
2. **J-1** : sauvegarde complète (Jetpack Backup, point de restauration nommé `avant-v2`).
3. **J, H0** : mode maintenance (page « Yume fait peau neuve », 1 h prévue).
4. **H0+5** : merge `develop → main` → GitHub Deployments déploie thème + plugin en production.
5. **H0+10** : `wp yume migrate` en production (idempotent), activation du thème, réglages
   (page d'accueil, permaliens, menus générés), import des redirections.
6. **H0+30** : recette de production (check-list : accueil, 3 fiches, 1 chapitre, planning, connexion
   équipe, formulaire en brouillon, inscription lecteur, 10 anciennes URL redirigées).
7. **H0+45** : fin de maintenance, annonce Discord/X, article « Un nouveau site pour Yume ».
8. **Rollback** (si nécessaire) : restauration `avant-v2` en un clic, retour au thème précédent.

## 9. Risques et décisions à prendre

| Sujet | Décision attendue | Recommandation |
| --- | --- | --- |
| Plan WordPress.com | Passer à Business (300 €/an) | Oui, prérequis de tout le reste |
| Source de la lecture en ligne | DOCX, EPUB ou les deux | Accepter les deux ; **EPUB en priorité** (déjà produit, images et styles inclus). Le PDF reste un lien. |
| Monétisation ClicTune | Conserver pour PDF/EPUB | Oui ; la lecture en ligne est gratuite et sans raccourcisseur |
| Manga | Lecteur d'images en v2 ? | Non en v2 : fiches + liens MangaDex ; lecteur manga en v2.1 |
| Commentaires | Natifs WordPress ou Discord | Natifs (SEO, comptes lecteurs), lien vers le fil Discord |
| Connexion Discord | OAuth en v2 ? | v2.1 (nécessite une application Discord) |
| Notifications push web | v2 ? | v2.1 ; e-mail suffisant au lancement |
| Conversation Claude partagée | Non consultable (lien privé) | À coller dans une issue si elle contient des décisions |
