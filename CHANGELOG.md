# Journal des versions

Une entrée par version ; une version par commit (tools/build/version.php, docs/guide-developpeur.md).

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
