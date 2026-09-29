# Journal des versions

Une entrée par version ; une version par commit (tools/build/version.php, docs/guide-developpeur.md).

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
