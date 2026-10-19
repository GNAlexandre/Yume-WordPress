# Journal des versions

Une entrée par version ; une version par commit (tools/build/version.php, docs/guide-developpeur.md).

## 2.5.0-dev.5 — 2026-10-19

- Lecteurs, intégration : les tests de la page Mon compte comptent les rubriques du module social seulement (Pour vous et À rattraper coupées)

## 2.5.0-dev.4 — 2026-10-19

- Lecteurs, lot L3 : recommandations « Si vous avez aimé… » (fiche œuvre, rubrique Pour vous)

## 2.5.0-dev.3 — 2026-10-19

- Lecteurs, lot L5 : rattrapage des nouveaux chapitres (accueil et Mon compte, lecteurs connectés)

## 2.5.0-dev.2 — 2026-10-19

- Lecteurs, lot L6 : pseudo public distinct de l'identifiant (inscription par pseudo public et e-mail, identifiant généré, connexion par e-mail, avis aux anciens comptes, registration_errors rétabli)

## 2.5.0-dev.1 — 2026-10-19

- Lecteurs, lot 0 : socle des comptes (création de compte unique, points d'extension du compte et des formulaires, RGPD, réglage secret, alerte inscriptions fermées)

## 2.4.0 — 2026-10-19

- Yume Novel 2.4.0 : statistiques d'audience dans l'espace équipe (menu Site → Statistiques). Toute l'équipe voit le trafic Jetpack (vues, visiteurs, contenus, référents, recherches, pays), les chiffres par œuvre (favoris, suiveurs alertés, lecteurs, chapitres lus estimés, listes), les classements et les chiffres des lecteurs, sur 7, 30 ou 90 jours ou 12 mois ; les gérants et administrateurs voient aussi la liste des lecteurs qui suivent chaque œuvre (pseudo, date, alertes), avec export CSV journalisé. Historique quotidien à partir de l'installation. Guide de l'équipe : « Statistiques d'audience ».

## 2.4.0-dev.7 — 2026-10-19

- Statistiques : la liste nominative précise que le pseudo affiché est celui des commentaires (souvent aussi l'identifiant de connexion, le pseudo d'inscription servant aux deux)

## 2.4.0-dev.6 — 2026-10-19

- Statistiques : documentation, guide de l'équipe, démo Playground, lien depuis les Indicateurs

## 2.4.0-dev.5 — 2026-10-19

- Statistiques, lot D : page Statistiques de l'espace équipe (menu Site, onglets Aperçu, Trafic, Œuvres, Lecteurs, fiche d'une œuvre, graphiques SVG accessibles, sans JavaScript)

## 2.4.0-dev.4 — 2026-10-19

- Statistiques, lot C : liste nominative des favoris et suiveurs d'une œuvre (pseudo, date, alertes ; gérants et administrateurs), export CSV du classement et de la liste, exports nominatifs journalisés

## 2.4.0-dev.3 — 2026-10-19

- Statistiques, lot B : compteurs par œuvre (favoris, suiveurs alertés, listes, lecteurs, chapitres lus), classements, historique quotidien (cron, rattrapage, rétention 400 jours)

## 2.4.0-dev.2 — 2026-10-19

- Statistiques, lot A : trafic Jetpack (vues, visiteurs, contenus, référents, recherches, pays ; cache, repli et données simulées hors production)

## 2.4.0-dev.1 — 2026-10-19

- Statistiques, lot 0 : socle du module (capacités, table d'historique, cron, routes REST)

## 2.3.1-dev.1 — 2026-10-18

- Documentation MangaDex : vérifications en production de la 2.3.0 (cache des images, stockage, sécurité), journal des décisions et des releases

## 2.3.0 — 2026-10-18

- Yume Novel 2.3.0 : lecture sur le site des mangas que l'équipe publie sur MangaDex (groupe Yume Novel). Les pages sont relayées par le site au moment de la lecture, aucune image n'est gardée. Lecteur en défilement ou page par page, qualité économie de données (par défaut) ou originale. Une œuvre « Manga » se lie à sa série par le champ « Lien MangaDex de la série » du formulaire équipe ; la synchronisation horaire (coupée par défaut, Réglages → Mangas (MangaDex)) crée tomes et chapitres, sans annonce au premier import. Garde-fous : œuvre « Licenciée » ignorée, chapitre mis en brouillon par l'équipe jamais remis en ligne, chapitres en double ignorés, retraits massifs suspendus, simulation sans écriture. Rapport et procédures : Yume → Mangas (MangaDex), docs/mangadex.md, guide de l'équipe.

## 2.3.0-dev.14 — 2026-10-17

- MangaDex : garde-fou des chapitres en double (un seul chapitre par numéro, doublons au rapport)

## 2.3.0-dev.13 — 2026-10-17

- MangaDex, recette 2.3.0-dev.12 : tome créé au planning (« Nouveau tome », sans fichier) repris et publié par la synchronisation à son premier chapitre ; tome retiré par l'équipe laissé hors ligne (alerte) ; chapitres hors ligne mis à jour comptés à part ; textes du réglage et du guide (pas de tome ni de chapitre à créer pour un manga, « Annoncer un tome de manga au planning »)

## 2.3.0-dev.12 — 2026-10-17

- MangaDex, étapes 7 et 8 : guide de l'équipe (chapitre « Les mangas (MangaDex) » : lier un manga, publier, vérifier la synchronisation, retirer ; « Lire un manga »), guide en ligne, guide développeur, prototype marqué comme remplacé ; recette de bout en bout sur la vraie API

## 2.3.0-dev.11 — 2026-10-17

- MangaDex, étape 6 : règles — œuvre « Licenciée » ignorée par la synchronisation (rien de créé, modifié ni remis en ligne, rapport), chapitre mis en brouillon par l'équipe jamais remis en ligne, rappel « publié sur MangaDex = public tout de suite » (page Mangas, boîte de l'œuvre, formulaire équipe), procédures de retrait (docs/mangadex.md)

## 2.3.0-dev.10 — 2026-10-17

- CI, contrôle de rendu : code de sortie du serveur PHP et lignes du noyau affichés quand il s'arrête en cours de route (plantage sans message à diagnostiquer)

## 2.3.0-dev.9 — 2026-10-17

- Normes de code : alignement corrigé dans le formulaire équipe des œuvres (PHPCS)

## 2.3.0-dev.8 — 2026-10-17

- Formulaire équipe des œuvres : champ « Lien MangaDex de la série » pour un manga (facultatif ; adresse de série ou identifiant ; refusé pour une adresse de chapitre ou d'un autre site, un type autre que Manga ou une série déjà liée ; vidé = œuvre déliée), pastilles « MangaDex » et « Lien MangaDex manquant »

## 2.3.0-dev.7 — 2026-10-16

- Lecteur MangaDex (recette 2.3.0-dev.6) : cadre d'une page pas encore chargée borné à l'écran en mode page (proportion gardée), page alignée sous la barre à l'ouverture en mode page, message et lien « Lire ce chapitre sur MangaDex » quand le lecteur est coupé

## 2.3.0-dev.6 — 2026-10-16

- MangaDex, étape 5 : lecteur des chapitres MangaDex dans le modèle « Chapitre » (défilement par défaut ou page par page, moitié droite = page suivante quel que soit le sens, qualité économie de données ou originale avec son poids, choix gardés dans yn.manga, suivi de lecture par page, crédits et repli « Lire sur MangaDex ») ; lecteur, proxy et synchronisation activables (yume_mangadex_lecteur_pret vrai)

## 2.3.0-dev.5 — 2026-10-16

- Tests mangadex : heure de référence des flux simulés figée par test (relance « sans effet » instable si une seconde passait entre deux reconstructions du flux)

## 2.3.0-dev.4 — 2026-10-16

- MangaDex, étape 4 : proxy des pages (/?yume_mdx_image=…) limité aux chapitres publiés de la base, image relayée sans rien garder, type vérifié, cache d'un an, pause après 429/403, signalement à MangaDex après la réponse avec coupe-circuit ; fermé tant que le lecteur n'est pas livré

## 2.3.0-dev.3 — 2026-10-16

- MangaDex, étape 3 : synchronisation horaire des chapitres du groupe (tomes par volume, import initial sans annonce, mises à jour et retraits, garde-fous, simulation, page Yume → Mangas (MangaDex)) ; coupée tant que le lecteur n'est pas livré

## 2.3.0-dev.2 — 2026-10-16

- Mangas MangaDex, étape 2 : modèle de données. Une œuvre peut être liée à une série MangaDex (boîte « MangaDex » de l'œuvre, adresse ou identifiant) ; un chapitre peut être hébergé sur MangaDex (identifiant, pages, version, groupes ; informations en lecture seule dans l'administration). Un volume MangaDex devient un tome, les chapitres sans volume vont dans un tome « Chapitres ». Rien de visible pour les lecteurs.

## 2.3.0-dev.1 — 2026-10-16

- Mangas MangaDex, étape 1 : module mangadex, client de l'API MangaDex (chapitres en français du groupe, paginés ; serveur d'images d'un chapitre gardé 10 min ; User-Agent réel, jamais de nouvel essai après une limite) et réglage « Groupe MangaDex » (Yume Novel par défaut). Rien de visible pour les lecteurs.

## 2.2.2 — 2026-10-16

- Yume Novel 2.2.2 : intégration du jeu WordEnd au site annulée avant sa mise en ligne. La page /jeu/, le bloc « Jeu WordEnd » et le lien du menu sont retirés ; l'easter egg (code Konami, papillon de SukaSuka) reste inchangé.

## 2.2.1 — 2026-10-16

- Yume Novel 2.2.1 : prototype jetable de lecture des mangas hébergés sur MangaDex (tools/mangadex-prototype, non livré avec Yume Core) : plugin de test (images relayées par WordPress, seuls les chapitres des séries de la liste blanche, lecteur, mesures), protocole d’essai sur un PC puis, au besoin, essai bref et réversible en production.

## 2.2.0 — 2026-10-16

- Yume Novel 2.2.0 : page officielle du jeu WordEnd (/jeu/, lien « Jeu WordEnd » du sous-menu Yume Novel). Elle affiche le jeu Yume-WordEnd (Godot, servi sur jeu.yumenovel.fr) dans une iframe créée au clic sur « Lancer le jeu », avec plein écran, ouverture dans un nouvel onglet, version Windows, commandes, sauvegarde et crédits. Après la mise à jour : « Recréer les pages manquantes » crée la page. Easter egg : « Seniorious » (et non « Seniolis »).

## 2.1.9 — 2026-10-16

- Yume Novel 2.1.9 : correctif de la publication. « Paramètre(s) invalide(s) : chapitres_prevus » ne bloque plus l'envoi quand « Chapitres prévus » est laissé vide (formulaire de publication et ajout d'un tome au planning par l'API) ; même correction pour l'intervalle entre deux chapitres, la couverture et le tome laissés vides. Une valeur saisie invalide reste refusée.

## 2.1.8 — 2026-10-07

- Yume Novel 2.1.8 : recette planning et Discord. Page Planning : parmi les tomes publiés, seuls ceux parus aujourd'hui (depuis minuit, heure de Paris) et ceux en cours de publication restent affichés. Discord : les annonces de chapitres ont leur propre canal, réglable (salon des sorties par défaut, salon dédié avec son webhook, ou aucune), visible et testable dans « Santé du site ».

## 2.1.7 — 2026-10-06

- Yume Novel 2.1.7 : recette du planning. Heure de sortie enregistrée avec la date cible du tome (elle n'était gardée qu'avec un rythme et revenait à 18:00), reprise par le rythme des chapitres et affichée partout (« sam. 10 oct. · 20 h », compte à rebours, ICS horodaté). Option « Série à venir » des œuvres : le Tome 1 paraît au planning public sous « Nouvelle série à venir » (nom modifiable), sans titre, couverture ni lien, jusqu'à la première publication ; pastille « Titre caché au public » pour l'équipe. Guide de l'équipe mis à jour.

## 2.1.6 — 2026-10-02

- Yume Novel 2.1.6 : section Planning de l'accueil sous « Dernières sorties » (prochain tome à la une avec compte à rebours et avancement, chapitres en lecture programmés et publiés récemment, tomes en préparation), page Planning enrichie (bandeau du prochain tome, vue « Chapitres », barres d'avancement, tomes publiés chapitre par chapitre « En cours · N chapitres sur M » avec prochain chapitre, cartes sur mobile, légende complétée), guide de l'équipe mis à jour.

## 2.1.5 — 2026-10-02

- Yume Novel 2.1.5 : publication chapitre par chapitre (Nouveau tome, Ajouter des chapitres avec sortie maintenant, au rythme ou à une date, liens PDF/EPUB avec le dernier chapitre ou tout publier maintenant), Modifier le tome et Tous les tomes dans l'espace équipe, état des tomes (Planifié, En cours de publication, Publié) et des œuvres (Licenciée réversible, En pause sans rappels), affichage « En cours · 3 sur 12 » pour les lecteurs, descriptions d'image Word ignorées à l'import, guide Word de l'équipe.

## 2.1.5-dev.9 — 2026-10-02

- Fin d'un tome : « Le tome est complet » publie tout maintenant (chapitres programmés compris) ; option « Publier les liens avec le dernier chapitre » (tome « Publié » à la sortie du dernier chapitre programmé) ; « Chapitres prévus » relevé d'office quand le fichier apporte plus de chapitres et modifiable dans le formulaire de publication et dans « Modifier le tome » ; docx2chapters --liens-dernier, --chapitres-prevus ; guide Word mis à jour.

## 2.1.5-dev.8 — 2026-10-01

- État du tome choisi par l'équipe (Planifié, En cours de publication, Publié) avec confirmations ; retour à « Planifié » retire la lecture ; guide Word de l'équipe aligné sur les écrans.

## 2.1.5-dev.7 — 2026-10-01

- État des œuvres modifiable depuis la liste « Œuvres » ; « Licenciée » retire la lecture en ligne et les liens (réversible) ; œuvre en pause, terminée, abandonnée ou licenciée : plus de rappels ni d'alertes.

## 2.1.5-dev.6 — 2026-10-01

- Guide de l'équipe en Word (docs/guide-equipe) : procédures illustrées, généré depuis procedures.md par tools/guide, vérifié en CI.

## 2.1.5-dev.5 — 2026-10-01

- États : libellés équipe d’un tome (Planifié, En cours de publication, Publié) et d’une œuvre, œuvres sans rappels de planning (socle).

## 2.1.5-dev.4 — 2026-10-01

- Publication chapitre par chapitre : « Nouveau tome » (remplace « Ajouter un tome au planning »), « Modifier le tome » dans l'espace équipe (plus d'écran wp-admin sans thème), « Tous les tomes » par parution, formulaire « Ajouter des chapitres » (comparaison au tome, sortie maintenant / au rythme / à une date, annonces de chapitre, « Tome complet » avec PDF et EPUB), affichage public « En cours · 3 sur 12 ».

## 2.1.5-dev.3 — 2026-10-01

- Tomes : parution (à paraître, en cours, complet), chapitres prévus et rythme de sortie (socle de la publication chapitre par chapitre).

## 2.1.5-dev.2 — 2026-10-01

- Import : les descriptions d’image générées par Word (« Une image contenant… », « Le contenu généré par l’IA peut être incorrect ») ne sont plus reprises comme texte alternatif ni comme titre des illustrations ; avertissement à l’import.

## 2.1.5-dev.1 — 2026-10-01

- Documentation : état de production au 1er octobre 2026, journal des décisions, règle de livraison d'après-lancement, contrôles CI obligatoires alignés sur ci.yml, modèle de données et guides remis à jour.

## 2.1.4 — 2026-09-30

- Easter egg WordEnd : mini-jeu caché « Chtholly – Bats-toi contre ton destin » (code Konami, appui long sur la bascule de thème, papillon de la fiche SukaSuka) : Chtholly contre des vagues de Timeres, décor peint, musique de fond réglable.

## 2.1.4-dev.8 — 2026-09-30

- WordEnd : cases de damier collées à Chtholly retirées (pose de repos), touche M (musique) reconnue en AZERTY.

## 2.1.4-dev.7 — 2026-09-30

- WordEnd : titre « Chtholly – Bats-toi contre ton destin ».

## 2.1.4-dev.6 — 2026-09-30

- WordEnd : Échap met la partie en pause, un second Échap ferme le jeu.

## 2.1.4-dev.5 — 2026-09-30

- WordEnd : décor peint (coucher de soleil sur les dunes) et musique de fond « Scarborough Fair » pendant la partie, avec volume et bouton muet (touche M), réglages mémorisés.

## 2.1.4-dev.4 — 2026-09-30

- WordEnd : un Timere n'attaque plus depuis l'extérieur de l'écran (il avance jusqu'à être à moitié visible).

## 2.1.4-dev.3 — 2026-09-30

- WordEnd : course du Timere alignée sur la tête (plus d'à-coup), ancres et images de coup vérifiées par les tests.

## 2.1.4-dev.2 — 2026-09-30

- WordEnd : Timere d'après la planche verte (repos, marche, fouet, morsure) et une planche complémentaire (course, dégâts, mort), à la même échelle ; portées et boîtes des attaques ajustées.

## 2.1.4-dev.1 — 2026-09-29

- Easter egg WordEnd : mini-jeu caché (code Konami, appui long sur la bascule de thème, papillon de la fiche SukaSuka) où Chtholly repousse des vagues de Timeres ; planches de sprites découpées depuis les planches générées (tools/wordend), Timeres restylés en pixel art vert.

## 2.1.3 — 2026-09-29

- Version 2.1.3 : contenu de la 2.1.2 (planning à jour pour les visiteurs, commentaires pleine largeur, reprise de migration) et relance manuelle de la Release.

## 2.1.3-dev.1 — 2026-09-29

- Release : relance manuelle sur un tag (Run workflow) si la pose du tag n'a pas créé de run

## 2.1.2 — 2026-09-29

- Version 2.1.2 : planning à jour pour les visiteurs (cache WordPress.com), commentaires pleine largeur sur les fiches, reprise de migration fiabilisée.

## 2.1.2-dev.3 — 2026-09-29

- Commentaires des fiches œuvre et tome : pleine largeur de la carte, marges intérieures rétablies

## 2.1.2-dev.2 — 2026-09-29

- Planning : pages publiques 60 s au plus dans le cache de WordPress.com, purgées à chaque mise à jour

## 2.1.2-dev.1 — 2026-09-29

- Migration : journal des catégories réconcilié en fin d'exécution (reprise après un arrêt brutal)

## 2.1.1 — 2026-09-29

- Version 2.1.1 : œuvres (création, modification, genres, cadrage des couvertures), menu de l'équipe en rubriques, fonds tirés des couvertures, découpage manuel des chapitres à l'import.

## 2.1.1-dev.4 — 2026-09-29

- Import : découpage manuel des chapitres, étiquettes DOCX (Prologue, 1, Bonus…), ouvertures illustrées

## 2.1.1-dev.3 — 2026-09-29

- Couvertures : cadrage réglable à la création et sur les œuvres existantes.

## 2.1.1-dev.2 — 2026-09-29

- Vue Œuvres : mise en page de la section Genres, intitulés et bouton de fichier.

## 2.1.1-dev.1 — 2026-09-29

- Espace équipe : modifier les œuvres, genres, menu en rubriques ; fonds tirés des couvertures ; ligne « Tomes 1 à N » qui disparaît à l'ouverture.

## 2.1.0 — 2026-09-29

- Espace équipe : vue « Œuvres » et formulaire « Nouvelle œuvre ».

## 2.0.2 — 2026-09-29

- Version 2.0.2 : correctifs de la recette (menus de l'œuvre, règles de réécriture, CI unique).

## 2.0.2-dev.1 — 2026-09-29

- Menus de la fiche œuvre visibles sous la bannière ; règles de réécriture rétablies si un vidage les a effacées ; CI une seule fois par changement.

## 2.0.1 — 2026-09-29

- Yume Novel 2.0.1 : correctifs de la recette (Tous les tomes, Mes tâches, remplacement de la lecture en ligne en deux temps, connexion en façade, images EMF, EPUB, dialogues, commentaires, Ko-fi, formulaires).

## 2.0.1-dev.8 — 2026-09-29

- Remplacement de la lecture en ligne en deux temps : vérifier (versions en attente, aperçus réservés à l'équipe, rien ne change en ligne), puis remplacer ou annuler ; nettoyage après 7 jours.

## 2.0.1-dev.7 — 2026-09-29

- Connexion en façade : /connexion/ ne passe plus par wp-login.php (erreurs affichées sur la page, limitation propre, administrateurs renvoyés vers la connexion WordPress).

## 2.0.1-dev.6 — 2026-09-29

- Espace équipe : vues « Tous les tomes » (publiés compris, remplacer la lecture en ligne par un nouveau DOCX ou EPUB) et « Mes tâches » ; remplacement de lecture en ligne annoncé clairement et sans nouvelle annonce.

## 2.0.1-dev.5 — 2026-09-29

- Import : images EMF/WMF des DOCX converties (bitmap extrait), EPUB : italique, gras et centrage lus dans les feuilles de style (pensées détectées), tiret de dialogue reconnu après tout espace.

## 2.0.1-dev.4 — 2026-09-29

- Commentaires : formulaire Jetpack / WordPress.com (Verbum) désactivé, le formulaire du thème Yume reprend sa place.

## 2.0.1-dev.3 — 2026-09-29

- Lecture : première lettre des dialogues alignée exactement sur celle des pensées (tiret isolé au rendu dans une boîte suspendue, quelle que soit la police).

## 2.0.1-dev.2 — 2026-09-29

- Lecture : dialogues décalés comme les pensées (tiret cadratin en retrait suspendu).

## 2.0.1-dev.1 — 2026-09-29

- Recette : bouton Ko-fi de l'en-tête aligné (cœur et texte centrés), champs du formulaire « Ajouter un membre » alignés quand un libellé passe sur deux lignes.

## 2.0.0 — 2026-09-29

- Yume Novel 2.0.0 : nouveau site (thème Yume, extension Yume Core), migration de l'ancien site, espace équipe, planning, lecture en ligne, glossaires, comptes lecteurs.

## 2.0.0-dev.14 — 2026-09-28

- Dernières corrections : vue Glossaires (liens soulignés, plus de débordement à 390 px, titres alignés sur les autres vues, repère nommé), identifiants _yn_nonce uniques dans les formulaires du compte.

## 2.0.0-dev.13 — 2026-09-28

- Revue de sécurité : abonnements Web Push liés à la session (révoqués à la déconnexion et au changement de mot de passe), purge des nouvelles tables à la désinstallation, brouillon de glossaire en base (plus en cache objet), détection de transaction compatible MySQL 8, pause d'un tome invalidant ICS et indicateurs, listes publiques à jeton aléatoire, purge Batcache, signalements pondérés par l'ancienneté et jamais de masquage automatique pour l'équipe.

## 2.0.0-dev.12 — 2026-09-28

- Corrections du parcours final : liens des commentaires soulignés, Échap des suggestions sans fermer le menu mobile, tableau du tableau de bord défilant à 390 px, « des »/« du » dans les titres d'annonce, messages de publication exacts sans DOCX.

## 2.0.0-dev.11 — 2026-09-28

- Glossaire : lecteur YAML en temps linéaire pour les chaînes entre guillemets et les listes en ligne sur plusieurs lignes (déni de service par le CPU corrigé).

## 2.0.0-dev.10 — 2026-09-28

- Santé du site intégrée à l'espace équipe (?vue=sante, design du site, dates en français) ; Yume → Santé redirige vers la vue ; prérequis affichés une seule fois ; libellés des tâches de notifications.

## 2.0.0-dev.9 — 2026-09-28

- Glossaire : nouvelle structure Yume-Trad (graphies_refusees en notes d'équipe, genre « ? », termes_source vide, termes anglais en lang="en", terme VO identique au nom non répété).

## 2.0.0-dev.8 — 2026-09-28

- Phase 3 nouvelles pages : glossaire par œuvre (format YAML de Yume-Trad, envoi ponctuel authentifié, téléversement et historique dans l'espace équipe, notes de traduction réservées à l'équipe) ; onglets de la fiche d'œuvre et actualités par œuvre ; page « Rejoindre l'équipe » et profils publics de contributeurs sur consentement ; listes de lecture, centre de notifications et notifications navigateur ; recherche avancée insensible aux accents avec suggestions (chapitres désactivés par défaut).

## 2.0.0-dev.7 — 2026-09-28

- Routage : sous-pages d'œuvre /oeuvres/{oeuvre}/{onglet}/ déclarées par le filtre yume_sous_pages_oeuvre (préparation des actualités et du glossaire).

## 2.0.0-dev.6 — 2026-09-28

- Phase 2 améliorations : flux ICS du planning, calendrier mensuel et RSS par œuvre ; vue Indicateurs et page Santé (crons, e-mails, test Discord) ; pause d'un tome, tri « en retard d'abord », export CSV, rappels plafonnés ; signalement et modération des commentaires, badge Équipe ; lecture hors ligne, police OpenDyslexic, contraste renforcé, statistiques de lecture ; matrice de sécurité REST, DOCX et EPUB piégés, axe-core en CI ; lecteur YAML pour le glossaire.

## 2.0.0-dev.5 — 2026-09-28

- Phase 1 corrections : accessibilité (liens du journal soulignés, repère de la barre de lecture, recherches nommées, titres de Nos réseaux), accueil avec h1, partenaires du pied de page suivant le réglage, lien Contactez-nous, libellés français quelle que soit la langue, liens d'auteur sans 404, Open Graph et Twitter Cards, requêtes N+1 supprimées, vues de l'espace équipe extensibles (yume_vues_equipe).

## 2.0.0-dev.4 — 2026-09-28

- Phase 0 sécurité : intégrité des mises à jour (SHA256SUMS, hôtes autorisés), points d'entrée WordPress (REST utilisateurs, XML-RPC, en-têtes, inscription et mot de passe oublié limités), gérant sans gestion directe des comptes, liste de mise en production et avis des prérequis, Dependabot et CODEOWNERS.

## 2.0.0-dev.3 — 2026-09-26

- Page « Illustrations » avant le chapitre 1 (/lire/{œuvre}/{tome}/illustrations/) : galerie du début du tome dans le lecteur, sommaire, navigation et bouton « Commencer la lecture ».

## 2.0.0-dev.2 — 2026-09-26

- Ajout au catalogue sans annonce (formulaire, REST, CLI : cochée par défaut pour un tome déjà en ligne) et page « Lecture à compléter » de l'espace équipe.

## 2.0.0-dev.1 — 2026-09-26

- Versionnage : une version par commit (tools/build/version.php, CHANGELOG.md, hook pre-commit, contrôle en CI).

## 2.0.0-dev — 2026-09-26

Refonte complète (audit, plan, maquettes, thème `yume`, extension `yume-core`, migration, outillage) ; historique des commits avant le versionnage par commit :

- Ajouter l'audit, le plan de refonte v2 et les maquettes (4848a40)
- Adapter le plan au plan WordPress.com actuel (d6f8c15)
- Revoir la direction visuelle : crépuscule sakura et accueil bibliothèque (e3b8340)
- Alléger l'accueil : bibliothèque en menu déroulant, bannière actuelle (cb441e2)
- Poser le socle du plugin yume-core et le contrat technique (dc8892c)
- Inscrire la règle de livraison : rien sur le site sans le Go de l'équipe (300b3e1)
- Point d'étape : thème yume en cours d'écriture (non vérifié) (5ae80c2)
- Point d'étape vague 1 : travail en cours des agents (non vérifié) (a2bad55)
- Point d'étape vague 1 : analyse de migration en cours (non vérifié) (993d87a)
- Point d'étape vague 1 : parseurs et fixtures de migration (non vérifié) (8a96954)
- Vague 1 vérifiée : cœur, thème, outillage, analyse de migration (0e134af)
- Aligner docs/05 sur l'outillage réellement livré (a668ef8)
- Vague 2 : import/publication, planning/équipe, lecteur/comptes, bibliothèque, migration (9dba155)
- Contrat : précisions de la vague 2 (routes, pages, métas, ancre de reprise) (8289518)
- Point d'étape : intégration en préproduction (non vérifié) (99d4e52)
- Revue croisée : correction des 53 constats confirmés (6469348)
- Restes de la revue hors périmètre des correcteurs (8f0cced)
- PHPCS : dépôt propre (le job Normes de la CI est bloquant) (54974a0)
- Lecture : barre unique ; test local avec Playground sur le clone (691d219)
- CI : contrôle de rendu du thème sous WordPress 6.6 et la dernière version (857e5d6)
- Espace équipe : page « Membres et rôles » pour les gérants (1ae7595)
- Démo : pages du menu « Yume Novel » et Contact (9a57c28)
- Guide de test local : ponctuation (9d5aa11)
- Menu : « Contact » ouvre le Discord de Yume (réglage discord_invite) (afd61ef)
- Scan fonctionnel : 22 manques corrigés (planning, espace équipe, publication, pages, lecteurs, partenaires) (23a0020)
- Documentation : contrat technique et guide de l'équipe à jour après le scan fonctionnel (cbdad34)
- Discord : invitation par défaut discord.gg/yumenovel (menu Contact, pied de page, bandeau, réglages) (54bcb9a)
- Partenaires : logos embarqués quand la médiathèque ne les a pas (485117b)
- Discord et X à jour, FAQ réécrite, pages réelles dans la démo (89b3802)
- Espace équipe : page « Réglages » intégrée (?vue=reglages) (c9e9212)
- Pages « Nos réseaux » et « Contact » réécrites (04a0083)
- Nos réseaux : image Discord retirée (ee5d923)
