# Journal des décisions et des jalons

Une ligne par décision ou par jalon, dans l'ordre des dates. Les décisions de fond sont prises par
l'équipe ; la colonne « Source » dit où en retrouver la trace (document du dépôt, release GitHub,
relevé du site). Les décisions encore ouvertes sont listées à la fin. Ce journal complète
[CHANGELOG.md](../CHANGELOG.md), qui ne consigne que les versions.

## 1. Décisions et jalons

| Date | Décision ou jalon | Source |
| --- | --- | --- |
| 2026-09-24 | Audit de l'existant et plan de refonte v2 posés dans le dépôt. | `docs/01-audit-existant.md`, `docs/02-plan-refonte.md` |
| 2026-09-24 | **Rester sur le plan WordPress.com actuel** : pas de plan Business, transfert Atomic inclus, mises à jour depuis les releases GitHub à la place de GitHub Deployments. | `docs/02-plan-refonte.md` §2 et §9 |
| 2026-09-24 | **Le DOCX est la source de la lecture en ligne** : converti en chapitres, puis supprimé du serveur. | `docs/02-plan-refonte.md` §9, `docs/04-import-docx-epub-lecteur.md` |
| 2026-09-24 | **PDF et EPUB jamais hébergés sur le site** : liens externes de téléchargement seulement. | `docs/02-plan-refonte.md` §9 |
| 2026-09-24 | **Commentaires natifs WordPress** (réservés aux comptes), lien vers le Discord ; pas de commentaires Discord. | `docs/02-plan-refonte.md` §9 |
| 2026-09-24 | **Lecteur manga reporté** après la v2 : fiches et liens MangaDex seulement. | `docs/02-plan-refonte.md` §9 |
| 2026-09-24 | **Connexion Discord (OAuth) reportée** après la v2. | `docs/02-plan-refonte.md` §9 |
| 2026-09-24 | **Notifications push web prévues plus tard** (e-mail jugé suffisant au lancement). | `docs/02-plan-refonte.md` §9 |
| 2026-09-26 | Première version de développement (2.0.0-dev) : socle du plugin et du thème, CI. | `CHANGELOG.md` |
| 2026-09-28 | Notifications push web **livrées** finalement avant le lancement (2.0.0-dev.8, avec les listes de lecture et le centre de notifications), contrairement au report prévu. | `CHANGELOG.md` (2.0.0-dev.8), `docs/06-contrat-technique.md` §13 |
| 2026-09-28 | Phase 0 sécurité avant mise en production (revue de sécurité, connexion en façade, intégrité des mises à jour). | commit `0165a41`, `docs/mise-en-production.md` |
| 2026-09-29 | **Go de l'équipe** : donné avant la pose du tag v2.0.0 le 29/09/2026 (date déduite de la release et de la première sauvegarde ; le dépôt ne contient pas la trace écrite du Go). | Release v2.0.0, journal d'activité Jetpack |
| 2026-09-29 | **Jour J** : première sauvegarde Jetpack à 6 h 32 UTC, release v2.0.0 à 6 h 40 UTC, pages fonctionnelles créées par la migration à 9 h 01 (heure de Paris). Le site est en production sur la refonte depuis cette date (hébergement WordPress.com Atomic). | Relevé du site du 1er octobre 2026 (connecteur WordPress.com), releases GitHub |
| 2026-09-29 | Releases v2.0.1 et v2.0.2 : correctifs de la recette de production. | Releases GitHub, `CHANGELOG.md` |
| 2026-09-29 | Versions 2.1.0 et 2.1.2 inscrites au `CHANGELOG.md` sans release : pas de tag v2.1.0 ; tag v2.1.2 posé sans release. Releases v2.1.1 et v2.1.3 publiées (la 2.1.3 reprend le contenu de la 2.1.2). | Tags et releases GitHub, `CHANGELOG.md` |
| 2026-09-30 | Release v2.1.4 (easter egg WordEnd). Le tag a été reposé après quatre runs de release en échec ; le run 11 a réussi sur `77f6533`. | Releases GitHub, historique Actions |
| 2026-09-30 | Premier envoi de la newsletter Jetpack réussi (5 destinataires). | Relevé du site du 1er octobre 2026 |
| 2026-10-01 | Relevé de l'état du site après la bascule, consigné dans `docs/mise-en-production.md` (section « État au 1er octobre 2026 »). | Connecteur WordPress.com, GitHub |
| 2026-10-02 | **Fin d'un tome** : « Le tome est complet » publie tout maintenant (chapitres programmés compris) ; nouvelle option « Publier les liens avec le dernier chapitre » pour un tome donné en entier et programmé au rythme ; « Chapitres prévus » relevé d'office quand le fichier apporte plus de chapitres, et modifiable dans le formulaire de publication et dans « Modifier le tome » quel que soit l'état. | Demande de l'équipe, `CHANGELOG.md` (2.1.5-dev.9), `docs/06-contrat-technique.md` §8 |
| 2026-10-02 | **Planning de l'accueil et page Planning** : proposition B retenue (prochain tome à la une, file des chapitres, tomes en préparation), dernières sorties gardées en tête ; livré en 2.1.6. | Maquettes validées (`docs/guide-equipe/maquettes/Accueil.dc.html`, `PagePlanning.dc.html`), `docs/06-contrat-technique.md` §7 et §10 |

## 2. Règle de livraison depuis le lancement

Le site est en production. Chaque version stable est taguée `vX.Y.Z` depuis `main` après un Go
explicite de l'équipe, pour chaque version ; les préversions `-beta.N` sont possibles sans Go.
Détail : `docs/06-contrat-technique.md` §0 bis.

## 3. Releases publiées

| Tag | Date | Contenu (résumé) | Remarque |
| --- | --- | --- | --- |
| v2.0.0 | 2026-09-29 | Nouveau site : thème Yume, extension Yume Core, migration, espace équipe, planning, lecture en ligne, glossaires, comptes lecteurs | Jour J |
| v2.0.1 | 2026-09-29 | Correctifs de la recette (Tous les tomes, Mes tâches, remplacement de la lecture en ligne, connexion en façade, images EMF, EPUB, dialogues, commentaires, Ko-fi, formulaires) | |
| v2.0.2 | 2026-09-29 | Correctifs de la recette (menus de l'œuvre, règles de réécriture, CI unique) | |
| (2.1.0) | 2026-09-29 | Vue « Œuvres » et formulaire « Nouvelle œuvre » | Pas de tag |
| v2.1.1 | 2026-09-29 | Œuvres (création, modification, genres, cadrage des couvertures), menu de l'équipe en rubriques, fonds tirés des couvertures, découpage manuel des chapitres | |
| (v2.1.2) | 2026-09-29 | Planning à jour pour les visiteurs, commentaires pleine largeur, reprise de migration | Tag sans release |
| v2.1.3 | 2026-09-29 | Contenu de la 2.1.2 et relance manuelle de la release | |
| v2.1.4 | 2026-09-30 | Easter egg WordEnd (« Chtholly – Bats-toi contre ton destin ») | Tag reposé après quatre runs en échec ; run 11 réussi sur `77f6533` |
| (v2.1.5-dev.9) | 2026-10-02 | Contenu de la PR #16 (publication chapitre par chapitre, états des tomes et des œuvres, guide Word) | Préversion (ignorée par les sites). Un premier tag v2.1.5 posé sur `0057124` (code en 2.1.5-dev.9) a échoué au contrôle des versions (run 12) |
| v2.1.5 | 2026-10-02 | Publication chapitre par chapitre, états des tomes et des œuvres, guide Word de l'équipe | Commit de version 2.1.5 ; tag v2.1.5 reposé sur sa fusion dans `main` |

## 4. Décisions en attente

D'après le relevé du site du 1er octobre 2026 (`docs/mise-en-production.md`, « État au 1er
octobre 2026 »). Chaque ligne attend une décision de l'équipe, puis une action de l'administrateur.

| Sujet | Constat | À décider |
| --- | --- | --- |
| Administrateurs | 4 comptes administrateurs (roshidere974, calumini, raiteijgarden, tournelalexandre) ; les rôles Gérant, Traducteur, Relecteur et Graphiste ne sont utilisés par personne. | Réduire à un seul administrateur ; passer les autres responsables en Gérant. |
| Double authentification | Aucune 2FA imposée. L'extension Two Factor est installée mais inactive. | Choisir entre l'extension Two Factor (couvre aussi `/connexion/`) et la validation en deux étapes WordPress.com (page de connexion WordPress seulement). |
| Inscriptions des lecteurs | « Tout le monde peut s'enregistrer » décoché ; le formulaire du site suit ce réglage, donc aucun nouveau compte lecteur ne peut être créé. | Rouvrir les inscriptions (rôle par défaut Abonné, Akismet actif) ou garder les comptes créés à la main. |
| Jetpack Monitor | Surveillance de disponibilité inactive. | L'activer (alerte e-mail à l'administrateur). |
| Extensions et thèmes inutilisés | Gutenberg 24.1.0 actif (plugin de développement) ; Classic Editor, Crowdsignal Dashboard, Crowdsignal Forms, Layout Grid inactifs ; thèmes Twenty Twenty-Four et Twenty Twenty-Two inutilisés. | Retirer Gutenberg et les extensions inactives ; supprimer les thèmes inutilisés (en garder un de secours si l'équipe le souhaite). |
| Test de restauration | Jetpack Backup actif (4 sauvegardes, dernière réussie le 30/09 21 h 16 UTC) ; aucun test de restauration consigné. | Planifier un test de restauration et en noter le résultat. |
| Langue des flux RSS | `rss_language = en`. | Passer en `fr`. |
| Permaliens des articles | `/%year%/%monthnum%/%day%/%postname%/` (hérités de l'ancien site). | Conserver (les anciennes adresses restent valides) ou simplifier avec redirections. |
| Mentions légales et confidentialité | La page `mentions-legales` créée par la migration est un texte de base à compléter ; la page de politique de confidentialité n'a pas été vérifiée lors du relevé. | Rédiger les mentions légales et la politique de confidentialité ; désigner cette dernière dans *Réglages → Confidentialité*. |
