# Mise en production : liste de contrôle (WordPress.com et GitHub)

## État au 1er octobre 2026

La bascule a eu lieu le **29 septembre 2026** (première sauvegarde Jetpack à 6 h 32 UTC, release
v2.0.0 à 6 h 40 UTC, pages créées par la migration à 9 h 01, heure de Paris). Hébergement
WordPress.com Atomic, WordPress 7.1.2, PHP 8.4.26, Yume Core 2.1.4 et thème yume 2.1.4 actifs.
Relevé fait par le connecteur WordPress.com et GitHub ; seules les cases prouvées par ce relevé sont
cochées, les autres restent à faire ou à vérifier.

**Vérifié**

- [x] Akismet (5.7.2) actif.
- [x] Jetpack Backup actif : identifiants gérés, 4 sauvegardes, dernière réussie le 30/09 à 21 h 16 UTC.
- [x] Jetpack Scan : aucune menace.
- [x] Protection des comptes (Jetpack) active.
- [x] Inscription WordPress fermée (« Tout le monde peut s'enregistrer » décoché). Attention : le
      formulaire d'inscription du site suit ce réglage (`yume_inscriptions_ouvertes` lit
      `users_can_register`), donc aucun nouveau compte lecteur ne peut être créé tant qu'il est
      décoché.
- [x] Rôle par défaut : Abonné (affiché « Lecteur »).
- [x] Site public (*WordPress.com → Réglages → Confidentialité*).
- [x] Code à jour : release v2.1.4 installée (plugin et thème).

**Reste à faire d'après le relevé**

- [ ] **Jetpack Monitor** (surveillance des temps d'arrêt) : inactif (§4).
- [ ] **2FA** : aucune imposée ; l'extension Two Factor est installée mais inactive. Choisir entre
      Two Factor et la validation en deux étapes WordPress.com (§1).
- [ ] **4 administrateurs** (roshidere974, calumini, raiteijgarden, tournelalexandre) alors que la
      cible est un seul ; les rôles Gérant, Traducteur, Relecteur et Graphiste ne sont attribués à
      personne (seuls deux Éditeurs Yume : Cerale, Shadowadow) (§1, §2).
- [ ] **Extensions et thèmes inutilisés** : Gutenberg 24.1.0 actif (plugin de développement, à
      retirer) ; Classic Editor, Crowdsignal Dashboard, Crowdsignal Forms, Layout Grid, Two Factor
      inactifs ; thèmes Twenty Twenty-Four et Twenty Twenty-Two inutilisés. Jetpack 16.3-a.7 (alpha)
      est mis à jour par la plateforme (§4).
- [ ] **Test de restauration** : aucun consigné (§5).
- [ ] **Export XML mensuel** conservé hors WordPress.com : aucun consigné (§5).
- [ ] **Adresse e-mail d'administration** : Gmail personnel ; à passer sur une boîte de l'équipe ou
      à consigner comme choix (§1).
- [ ] **Langue des flux RSS** : `rss_language = en`, à passer en `fr` (§7).
- [ ] **Permaliens des articles** : `/%year%/%monthnum%/%day%/%postname%/` hérités de l'ancien
      site ; décision à prendre (§7).
- [ ] **Inscriptions des lecteurs** : fermées par le réglage ci-dessus ; décider si elles
      rouvrent (rôle par défaut Abonné, Akismet actif) ou si les comptes restent créés à la main (§2).
- [ ] **Modération des commentaires** désactivée (aucune mise en attente du premier commentaire) :
      à confirmer comme choix ou à activer (§7).
- [ ] **Webhooks Discord** : régénération après la préproduction non consignée (§7).
- [ ] **Règles GitHub** : ruleset de tags `v*` avec *Restrict updates* et *Restrict deletions*
      à mettre en place ou à vérifier, puisque le tag v2.1.4 a été déplacé après quatre runs de
      release en échec ; ruleset de `main` et environnement `release` à vérifier (§9.2, §9.3).
- [ ] Journal d'activité relu à J+1 et J+7, aucun nouvel administrateur : non consigné (§10).

Décisions ouvertes correspondantes : `journal-des-decisions.md` §4.

---

Liste à dérouler **avant d'ouvrir la v2 au public** (fait le 29 septembre 2026), puis à
revérifier à chaque changement d'équipe et à chaque relevé.
Elle reprend l'audit de sécurité (§3.3, AMEL-01, SEC-08, SEC-09, SEC-14) : la sécurité du site
dépend autant des réglages de l'hébergeur et de GitHub que du code.

Contexte : yumenovel.fr reste sur son **plan WordPress.com payant actuel**. La première extension
installée transfère le site sur l'hébergement **Atomic** (WordPress « complet »). On dispose alors
de l'administration WordPress (`/wp-admin/`), du tableau de bord WordPress.com
(`wordpress.com/home/yumenovel.fr`), de **SFTP** (pas de SSH ni de WP-CLI). Le code arrive par les
releases GitHub (`docs/05-pipeline-github-wordpress.md`).

Une fois `yume-core` installé, l'avis **« Prérequis de mise en production »** (administrateurs
seulement, tableau de bord, écrans Yume, Extensions, Réglages) signale ce qu'il peut détecter : langue
du site, `WP_DEBUG`/`SCRIPT_DEBUG`, `debug.log`, double authentification, rôle par défaut des
inscriptions, indexation, XML-RPC sans Jetpack. Chaque administrateur peut masquer ces rappels pour
son compte ; *Yume → Tableau de bord → Revoir* les fait réapparaître. L'avis ne remplace pas cette
liste : la plupart des points ci-dessous ne se voient pas depuis WordPress.

Cocher au fur et à mesure (copie de ce fichier dans un ticket, ou impression). Qui : **A** =
administrateur du site, **G** = propriétaire du dépôt GitHub.

---

## 1. Comptes et double authentification (A)

- [ ] **Un seul compte administrateur nominal** (pas `admin`), adresse e-mail personnelle surveillée.
      Les autres responsables ont le rôle **Gérant**, jamais Administrateur.
- [ ] `Réglages → Général → Adresse e-mail d'administration` : une boîte lue réellement (alertes de
      sécurité, réinitialisations).
- [ ] Mots de passe de 12 caractères ou plus, uniques (gestionnaire de mots de passe) pour tous les
      comptes Administrateur, Gérant et Éditeur Yume.
- [ ] **2FA obligatoire pour l'administrateur et les gérants**, au choix :
  - **Connexion WordPress.com (Jetpack SSO)** : chaque personne active la validation en deux étapes
    sur `wordpress.com/me/security/two-step` (application TOTP ou clé de sécurité, pas de SMS),
    puis l'administrateur coche *Jetpack → Réglages → Sécurité → Connexion WordPress.com →
    « Exiger la validation en deux étapes »* (ce réglage est détecté par l'avis) ;
  - **ou extension [Two-Factor](https://wordpress.org/plugins/two-factor/)** : *Extensions →
    Ajouter*, puis chaque compte configure *Profil → Options de double authentification* (TOTP +
    codes de secours). L'avis signale un administrateur sans 2FA.
- [ ] Tester une connexion complète avec la 2FA **avant** de se déconnecter de la session en cours ;
      ranger les codes de secours hors ligne.
- [ ] Connexion en façade (`/connexion/`, sans wp-login.php) : lecteurs et équipe, gérants compris.
      Les **administrateurs** y sont refusés et passent par la page de connexion WordPress (lien
      « Connexion administrateur ») : vérifier, déconnecté, qu'un mauvais mot de passe de lecteur
      affiche le message sur `/connexion/` (pas de page WordPress.com), qu'un bon mot de passe mène
      à Mon compte et qu'un administrateur est renvoyé vers la page WordPress. Le formulaire limite
      lui-même les échecs (5 en 15 min par adresse IP et par compte) et déclenche `wp_login_failed`
      pour Jetpack Protect. Si l'extension Two-Factor est utilisée pour un gérant, sa 2FA s'applique
      aussi à `/connexion/` (action `wp_login`) ; la 2FA WordPress.com ne s'applique qu'à la page
      WordPress.

## 2. Rôles et comptes (A)

- [ ] *Utilisateurs* : filtrer par rôle. Aucun compte **Éditeur**, **Auteur** ou **Contributeur** du
      cœur après la migration (les rôles Yume les remplacent) ; aucun administrateur en trop.
- [ ] Supprimer (ou rétrograder en Abonné) les comptes inactifs de l'ancien site et ceux des membres
      partis ; attribuer leurs contenus à un compte de l'équipe.
- [ ] *Réglages → Général* : si « Tout le monde peut s'enregistrer » est coché, **Rôle par défaut =
      Abonné** (l'avis le vérifie).
- [ ] Le Gérant ne peut plus modifier le mot de passe ni l'e-mail des autres comptes (SEC-03) :
      vérifier avec un compte gérant que *Utilisateurs → Modifier* n'affiche pas ces champs.

## 3. Mots de passe d'application (A)

- [ ] Un mot de passe d'application **par outil et par personne** (*Profil → Mots de passe
      d'application*), nommé explicitement : « docx2chapters – poste de Calumi ».
- [ ] `docx2chapters` lit le mot de passe dans `~/.config/yume/credentials` (droits 600) ou la
      variable `YUME_APP_PASSWORD`, **jamais** en argument de ligne de commande
      (`tools/docx2chapters/README.md`, « Mot de passe d'application »).
- [ ] Révoquer les mots de passe d'application d'un membre le jour de son départ, et tout mot de
      passe collé par erreur dans Discord, un ticket ou une capture.
- [ ] Les lecteurs ne peuvent pas en créer (déjà refusé par `yume-core`).

## 4. Protection du site : Jetpack et WordPress.com (A)

- [ ] **Akismet** actif (*Jetpack → Réglages → Discussion*, ou extension Akismet) : filtre les
      commentaires et les inscriptions indésirables.
- [ ] **Jetpack Protect** : *Jetpack → Réglages → Sécurité* : protection contre les attaques par
      force brute activée ; *Jetpack → Protect* (ou *Scan* si le plan l'inclut) : aucune
      vulnérabilité ni menace signalée ; pare-feu (WAF) actif si proposé.
- [ ] **Surveillance de disponibilité** (*Jetpack → Réglages → Sécurité → Surveillance des
      temps d'arrêt*) : alerte e-mail à l'administrateur.
- [ ] **Journal d'activité** (*Jetpack → Journal d'activité*, ou `wordpress.com/activity-log/…`) :
      le consulter chaque semaine et après chaque release (connexions, changements de rôle,
      nouvelles extensions, nouveaux utilisateurs). Pour des **alertes e-mail** sur nouvel
      administrateur ou nouvelle extension, que le journal n'envoie pas, installer une extension de
      journal avec notifications (par exemple *WP Activity Log*) ou, à défaut, noter la revue
      hebdomadaire dans l'agenda de l'équipe.
- [ ] Aucune extension de débogage (Query Monitor, WP Crontrol laissée active…) en production.

## 5. Sauvegardes (A)

- [ ] **Avant la bascule** : sauvegarde complète nommée `avant-v2` (docs/02 §8) : *Outils →
      Exporter* (XML) + médiathèque + sauvegarde de l'hébergeur (Jetpack VaultPress Backup si le
      plan l'inclut, *Jetpack → Sauvegarde*), sinon UpdraftPlus vers Google Drive/Dropbox.
- [ ] **Après la bascule** : sauvegarde quotidienne automatique vérifiée (date de la dernière
      sauvegarde visible) ; export XML mensuel conservé **hors** WordPress.com.
- [ ] **Test de restauration une fois** : restaurer la sauvegarde `avant-v2` sur un site de test
      (préproduction locale, `tools/preprod/`) ou, avec Jetpack Backup, sur un site de staging
      WordPress.com ; vérifier une œuvre, un tome, la lecture en ligne, un compte lecteur.

## 6. `wp-config.php` par SFTP (A)

Accès : *WordPress.com → Réglages de l'hébergement (Hosting → Overview / Configuration) → SFTP/SSH →
« Créer des identifiants SFTP »* (le mot de passe ne s'affiche qu'une fois : le mettre dans le
gestionnaire de mots de passe). Client : FileZilla, WinSCP ou `sftp`. Le fichier est à la racine du
site (`/srv/htdocs/wp-config.php`). Si l'option SFTP n'apparaît pas, le plan ne la permet pas :
figer alors le dépôt dans *Yume → Réglages* et le noter dans le ticket.

- [ ] Télécharger une copie de `wp-config.php` avant toute modification (garder l'original).
- [ ] Ajouter **au-dessus** de la ligne `/* That's all, stop editing! */` (ou « C'est tout, ne
      touchez pas à ce qui suit ») :

  ```php
  // Yume : source des mises à jour figée (le réglage de Yume → Réglages passe en lecture seule).
  define( 'YUME_GITHUB_REPO', 'GNAlexandre/Yume-WordPress' );
  // Yume : vérification SHA256SUMS des mises à jour exigée (défaut ; ne jamais mettre false,
  // sauf dépannage ponctuel, docs/05 §7.1).
  define( 'YUME_EXIGER_EMPREINTE', true );
  ```

- [ ] **Débogage coupé** (SEC-14) : `WP_DEBUG` absent ou `false`, pas de `WP_DEBUG_DISPLAY` à
      `true`, pas de `SCRIPT_DEBUG`. WordPress.com les laisse désactivés par défaut ; les erreurs
      PHP se lisent dans *Réglages de l'hébergement → Journaux (Logs)*, pas dans un fichier du site.
- [ ] Pas de fichier `wp-content/debug.log` (le supprimer par SFTP s'il existe).
- [ ] Ne jamais déposer de copie de travail Git ni de dossier `tests/` sur le serveur : n'installer
      que les archives de release (`yume-core.zip`, `yume.zip`), qui les excluent (SEC-08).
- [ ] Recharger le site et l'administration après enregistrement ; *Yume → Réglages* affiche le
      dépôt en lecture seule.

## 7. Réglages WordPress (A)

- [ ] **Langue du site : Français (fr_FR)** (*Réglages → Général → Langue du site*) ; la langue de
      l'interface de chaque compte WordPress.com se règle à part (`wordpress.com/me/account`).
- [ ] Fuseau horaire : Paris ; format de date français.
- [ ] *Réglages → Lecture* : « Demander aux moteurs de recherche de ne pas indexer ce site »
      **décoché** le jour J, et site **public** dans *WordPress.com → Réglages → Confidentialité*
      (« Lancer le site » si le plan l'a laissé en « Bientôt disponible »).
- [ ] *Yume → Réglages → Mises à jour automatiques* : laisser coché seulement une fois la
      vérification d'empreinte en place (release contenant `includes/updater/integrite.php`) ; après
      chaque release, *Extensions* doit afficher la nouvelle version sans message « refusée ».
- [ ] **Webhooks Discord régénérés** : dans Discord, *Paramètres du salon → Intégrations →
      Webhooks*, supprimer les anciens (ceux utilisés en préproduction ou partagés), en créer de
      nouveaux et les coller dans *Yume → Réglages* ; ne jamais les coller dans un ticket ou un
      message.
- [ ] **Notifications navigateur** (*Yume → Réglages → Notifications*) : le site doit être servi en
      **HTTPS** et les tâches planifiées doivent tourner (sinon les push et la purge des notifications
      n'ont jamais lieu) : *Outils → Santé du site* sans erreur sur les tâches planifiées ; avec un
      compte lecteur, activer les notifications dans *Mon compte → Notifications* et vérifier qu'une
      sortie d'un favori s'affiche sur l'appareil. Décocher la case si ce n'est pas le cas.
- [ ] **XML-RPC** : Jetpack en a besoin sur WordPress.com ; ne pas le désactiver entièrement.
      `yume-core` retire les méthodes d'authentification exposées (SEC-06). Sans Jetpack, l'avis
      demande de le désactiver.

## 8. Vérifications depuis l'extérieur (A, poste de travail)

```sh
# En-têtes de sécurité (SEC-05) : HSTS et nosniff (WordPress.com), X-Frame-Options,
# Referrer-Policy, Permissions-Policy (yume-core).
curl -sI https://yumenovel.fr/ | grep -iE 'strict-transport|x-frame-options|x-content-type|referrer-policy|permissions-policy|content-security-policy'

# Pas de balise generator ni de fichiers de développement servis.
curl -s https://yumenovel.fr/ | grep -i 'name="generator"'                          # rien attendu
curl -so /dev/null -w '%{http_code}\n' https://yumenovel.fr/wp-content/debug.log      # 404 ou 403
curl -so /dev/null -w '%{http_code}\n' https://yumenovel.fr/wp-content/plugins/yume-core/tests/runner.php  # 404

# Liste des comptes fermée aux anonymes (SEC-04) et inscription par wp-login.php renvoyée
# vers le formulaire du site (SEC-02).
curl -so /dev/null -w '%{http_code}\n' https://yumenovel.fr/wp-json/wp/v2/users      # 401
curl -so /dev/null -w '%{http_code} %{redirect_url}\n' 'https://yumenovel.fr/wp-login.php?action=register'
```

- [ ] Chaque commande donne le résultat attendu (noter la sortie dans le ticket).
- [ ] Test sur <https://securityheaders.com/?q=yumenovel.fr> : aucun en-tête « manquant » critique.

---

## 9. GitHub (G)

Dépôt `GNAlexandre/Yume-WordPress`, **public** (les sites téléchargent les releases sans jeton).
Détail de la chaîne de publication : `docs/05-pipeline-github-wordpress.md` §7.

### 9.1 Comptes

- [ ] **2FA activée** sur tous les comptes ayant un accès en écriture (*Settings → Password and
      authentication*), application TOTP ou clé de sécurité, codes de récupération rangés hors
      ligne. Retirer les collaborateurs qui n'en ont plus besoin (*Settings → Collaborators*).
- [ ] Aucun jeton personnel (*Settings → Developer settings → Personal access tokens*) inutilisé ;
      aucun jeton à droits d'écriture stocké en secret du dépôt.

### 9.2 Règles (Settings → Rules → Rulesets)

- [ ] **Branche `main`** (*New branch ruleset*, cible « Default branch », *Active*) : *Restrict
      deletions*, *Block force pushes*, *Require a pull request before merging* (1 approbation,
      *Dismiss stale approvals*, *Require review from Code Owners*), *Require status checks to
      pass* avec tous les jobs de `ci.yml`, tels qu'ils apparaissent après un premier run :
      **Syntaxe PHP 8.1**, **8.2**, **8.3** et **8.4** ; **Normes de code (PHPCS)** ; **Tests
      WordPress** (les 4 combinaisons : 6.6 et latest, SQLite et MariaDB) ; **Rendu WordPress**
      (6.6 et latest) ; **Rendu identique sur WordPress 6.6 et la dernière** ; **Archives
      yume-core.zip et yume.zip**. Mainteneur seul : mettre 0 approbation
      requise ou s'ajouter en *Bypass list* « pull requests only », sinon aucune fusion possible.
- [ ] **Tags `v*`** (*New tag ruleset*, cible `refs/tags/v*`, *Active*) : *Restrict creations*,
      *Restrict updates*, *Restrict deletions*, *Block force pushes* ; *Bypass list* = le ou les
      mainteneurs qui publient, rien d'autre.
- [ ] Capture d'écran des deux rulesets actifs jointe au ticket de mise en production.

### 9.3 Environnement de publication (Settings → Environments)

- [ ] Environnement **`release`** : *Required reviewers* = la personne qui donne le « Go » (cocher
      *Prevent self-review* s'il y a deux mainteneurs) ; *Deployment branches and tags* =
      « Selected », règle de tag `v*`. Le job « Release GitHub » de `release.yml` attend cette
      approbation.

### 9.4 Actions (Settings → Actions → General)

- [ ] *Workflow permissions* = « Read repository contents and packages permissions » ; « Allow
      GitHub Actions to create and approve pull requests » **décoché**.
- [ ] *Actions permissions* = actions de GitHub et des créateurs vérifiés (ou liste explicite :
      `actions/*`, `shivammathur/setup-php@*`, `softprops/action-gh-release@*`).

### 9.5 Sécurité du code (Settings → Code security)

- [ ] **Dependabot alerts** et **Dependabot security updates** activés. Les mises à jour de
      version sont décrites par `.github/dependabot.yml` (actions GitHub et `tools/ci`, chaque
      lundi) : relire et fusionner leurs demandes comme les autres.
- [ ] **Secret scanning** et **Push protection** activés (gratuits pour un dépôt public).
- [ ] `.github/CODEOWNERS` en place (updater, `lib/`, `tools/build/`, `.github/`) ; il n'a d'effet
      qu'avec *Require review from Code Owners* coché au §9.2.

### 9.6 Les jobs Actions s'arrêtent en 2 secondes

Symptôme observé : chaque job de CI ou de release échoue en 1 à 3 secondes, sans aucune étape
exécutée ni journal. L'annotation du run (onglet *Summary*) dit en général : « The job was not
started because recent account payments have failed or your spending limit needs to be
increased ». C'est un problème de **facturation du compte**, pas du code : GitHub bloque toutes
les Actions d'un compte qui a un paiement en échec, même pour un dépôt public (où les minutes
sont gratuites).

- [ ] *Settings (du compte) → Billing and plans* : régler le paiement en échec ou mettre à jour le
      moyen de paiement ; résilier l'abonnement payant inutile s'il est la cause.
- [ ] *Billing → Budgets and alerts* : pas de budget Actions à 0 € qui « arrête l'utilisation »
      si le dépôt devient privé un jour.
- [ ] Relancer le dernier run (*Re-run all jobs*) : les jobs doivent durer plusieurs minutes et
      afficher leurs étapes. **Aucune release tant que la CI n'est pas verte** : `release.yml`
      rejoue toute la CI avant de publier.

---

## 10. Jour J et après

- [ ] Toutes les cases ci-dessus cochées, captures (rulesets, 2FA exigée, sauvegarde `avant-v2`)
      jointes au ticket.
- [ ] L'avis « Prérequis de mise en production » ne signale plus rien (ou seulement des rappels
      vérifiés à la main puis masqués).
- [ ] J+1 et J+7 : journal d'activité relu, sauvegardes présentes, *Extensions* à jour, aucun
      nouvel administrateur.
- [ ] À chaque départ d'un membre : rôle retiré, mots de passe d'application révoqués, webhooks
      Discord régénérés s'il y avait accès, accès GitHub retiré.

---

## 11. Statistiques d'audience : vérifications après la mise en production (2.4.0)

L'adaptateur Jetpack (`includes/statistiques/trafic.php`) a été écrit d'après le code source de
Jetpack et testé sur des réponses simulées : l'API WordPress.com Stats ne peut pas être appelée hors
du site. Les points marqués « INCERTAIN » en tête de `trafic.php` se vérifient sur yumenovel.fr,
connecté en **administrateur**, dans *Espace équipe → Site → Statistiques*, en comparant avec
*Jetpack → Statistiques* (ou wordpress.com/stats). Toute correction se fait dans `trafic.php` seul.

- [ ] Onglet **Trafic** : la ligne « Source du trafic : Jetpack (WordPress.com Stats) — dernière
      erreur : aucune » s'affiche (sinon : noter le message d'erreur ; « indisponible » = Jetpack non
      connecté). Aucun bandeau « Démonstration » (il est impossible en production).
- [ ] **7 et 30 jours** : vues et visiteurs (total et courbe jour par jour) égaux à ceux de Jetpack ;
      le dernier point est bien **aujourd'hui** (paramètre `date` = dernier jour inclus, au fuseau du
      site — hypothèse à confirmer).
- [ ] **12 mois** : série **mensuelle** de 12 points, comparée aux 12 mois précédents (paramètre
      `quantity` = 24 mois accepté ; format de la période « AAAA-MM-JJ » ou « AAAA-MM »).
- [ ] **90 jours** : 90 points (`quantity` = 180 jours accepté) ; si `stat_fields=views,visitors` est
      ignoré, les colonnes sont tout de même lues par leur nom.
- [ ] **Contenus les plus vus** : plus de 10 contenus reçus (paramètre `max` = 100 demandé ; le bloc
      « Top Posts » de Jetpack s'arrête à 30) ; « Par œuvre » et « Autres pages » cohérents avec le
      total de Jetpack (présence de `total_views` ; à défaut, total = somme des lignes reçues).
- [ ] **12 mois** pour les listes : `num` = 365 accepté par `top-posts`, `referrers`,
      `search-terms`, `country-views` (sinon la liste ne couvre qu'une partie de l'année).
- [ ] **Référents**, **Termes de recherche** (ligne « Termes masqués par les moteurs » :
      `encrypted_search_terms`), **Pays** (noms et pourcentages, jamais de ville) : listes non
      tronquées à 10 par l'API, `max` pris en compte ou ignoré sans erreur.
- [ ] Lendemain de la mise en ligne, après 3 h 10 : *Santé du site* montre la tâche
      « Statistiques quotidiennes (historique) » exécutée ; la fiche d'une œuvre lue la veille affiche
      « Historique quotidien depuis le … » et une carte **Vues** chiffrée (vues par œuvre du jour
      `top-posts` `period=day`, `max=100` : vérifier qu'une œuvre peu vue n'est pas comptée à 0 parce
      que coupée de la liste).
- [ ] Temps d'ouverture de l'onglet **Trafic** acceptable à la première visite (4 à 6 appels à
      l'API, puis cache de 15 minutes) ; aucune erreur de quota de l'API dans la ligne « dernière
      erreur » après quelques ouvertures.
- [ ] Rôles : un traducteur voit **Statistiques** (chiffres, export du classement) mais pas la liste
      nominative ; un gérant voit la liste et peut l'exporter.
- [ ] Page *Confidentialité* : la mention des statistiques internes (finalité « statistiques
      d'audience internes », favoris et alertes visibles des gérants) est en ligne une fois rédigée
      par l'équipe (décision en attente, `docs/journal-des-decisions.md` §4).

L'historique quotidien commence à la mise en ligne : rien n'est repris du passé. Les vues passées par
œuvre restent consultables dans Jetpack.

## 12. Connexion Discord : mise en service (2.5.0, A)

La connexion avec Discord (bouton « Se connecter avec Discord » / « Créer un compte avec Discord »,
rubrique *Mon compte → Connexion Discord*) est livrée **inactive** : tant que les trois éléments
ci-dessous ne sont pas en place, le site est strictement inchangé (aucun bouton, adresse de retour en
404). Elle sert aux lecteurs et aux membres de l'équipe, **jamais aux administrateurs** (ils restent
sur la page de connexion WordPress et sa validation en deux étapes). Fonctionnement :
`docs/06-contrat-technique.md` §8 quinquies.

**Rappel inscriptions** : la création de compte (formulaire du site comme Discord) suit *Réglages →
Général → Inscription* (« Tout le monde peut s'enregistrer », rôle par défaut **Abonné**). Ce réglage
était décoché à la mise en ligne : Alexandre a demandé sa réouverture avec les fonctionnalités lecteurs
(journal du 19/10/2026). Tant qu'il est décoché, *Outils → Santé du site* l'indique (test « Les
inscriptions des lecteurs sont fermées ») et Discord ne connecte que des comptes déjà liés.

### 12.1 Application sur le portail Discord (Alexandre)

- [ ] Ouvrir <https://discord.com/developers/applications> (compte Discord de Yume), **New
      Application**, nom « Yume Novel », accepter les conditions.
- [ ] Onglet **OAuth2** : copier le **Client ID** (nombre de 17 à 20 chiffres).
- [ ] Même onglet, **Reset Secret** : copier le **Client Secret** (affiché **une seule fois** : le
      mettre aussitôt dans le gestionnaire de mots de passe, jamais dans un ticket ni sur Discord).
- [ ] **Redirects**, **Add Redirect** : coller **exactement**
      `https://yumenovel.fr/wp-json/yume/v1/discord/retour` (sans barre finale, sans paramètre ;
      *Yume → Réglages*, sous **Client ID Discord**, affiche l'adresse attendue), puis **Save
      Changes**. Pour un essai en local : `http://127.0.0.1:8080/wp-json/yume/v1/discord/retour`
      (Playground n'a pas d'adresse publique : la connexion Discord n'y est pas démontrable).
- [ ] **Public Client** : laisser **désactivé** (le site garde le secret côté serveur).
- [ ] Aucun **bot**, aucune permission de serveur, aucune autre option : le site ne demande que les
      scopes `identify` et `email`.

### 12.2 Client ID et secret dans les réglages

`wp-config.php` n'est pas modifiable sur WordPress.com : les deux valeurs se saisissent dans les
réglages, depuis *Yume → Réglages*, section **Connexion Discord**, ou depuis la vue **Réglages** de
l'espace équipe (gérants et administrateurs).

- [ ] **Client ID Discord** : coller l'identifiant du client (onglet OAuth2 du portail).
- [ ] **Client Secret Discord** : coller la clé secrète (champ de type mot de passe). Elle est
      enregistrée dans les réglages du site et **jamais réaffichée** ; l'aide indique seulement
      « Une valeur est enregistrée ». Laisser le champ vide en enregistrant d'autres réglages garde la
      valeur. Pour la changer : *Réinitialiser la clé secrète* sur Discord, puis coller la nouvelle.
- [ ] Facultatif, si un jour `wp-config.php` devient modifiable : les constantes
      `YUME_DISCORD_CLIENT_SECRET` et `YUME_DISCORD_CLIENT_ID` remplacent les réglages (le champ affiche
      alors « Défini par la constante … »).

### 12.3 Activer

- [ ] *Yume → Réglages*, section **Connexion Discord** (ou vue **Réglages** de l'espace équipe pour un
      gérant) : **Client ID Discord** (si pas de constante), cocher **Connexion avec Discord** ;
      **Création de compte avec Discord** reste cochée (création au premier passage, adresse vérifiée
      par Discord, si les inscriptions sont ouvertes).
- [ ] *Outils → Santé du site* : « Connexion Discord configurée » (sinon « activée mais incomplète » :
      Client ID ou secret manquant).
- [ ] Sur `/connexion/`, en navigation privée : le bouton **Se connecter avec Discord** est présent.

### 12.4 Points à vérifier en recette (sur yumenovel.fr)

- [ ] **Création** : un compte Discord jamais venu crée un compte lecteur (pseudo = nom Discord) et
      arrive connecté sur *Mon compte* ; la rubrique **Connexion Discord** dit « Lié à … ».
- [ ] **Retour** : une seconde connexion avec Discord ne redemande pas l'autorisation (`prompt=none`) ;
      si Discord renvoie une erreur à ce stade, repasser à l'écran d'autorisation systématique avec le
      filtre `yume_discord_prompt` (`'consent'`) dans une petite extension.
- [ ] **PKCE avec un client confidentiel** : l'échange du code envoie `code_verifier` **et** le
      secret. Si Discord le refuse (message « La connexion avec Discord a échoué » à chaque essai,
      erreur `invalid_grant` dans les journaux), couper PKCE avec le filtre `yume_discord_pkce`
      (`__return_false`) : le `state` lié au navigateur reste en place.
- [ ] **Identifiants du client dans le corps** : `client_id` et `client_secret` sont envoyés dans le
      corps du `POST /oauth2/token` (pas d'en-tête `Authorization: Basic`). Discord accepte les deux
      formes ; en cas de refus `invalid_client`, vérifier d'abord le secret (Reset Secret puis
      nouveau secret collé dans les réglages), puis signaler à l'équipe technique.
- [ ] **Cache** : l'adresse `/wp-json/yume/v1/discord/retour` ne doit pas être mise en cache
      (réponse 302 avec les en-têtes de `nocache_headers()` : `Cache-Control: no-cache, …` et
      `Expires` passé ; `curl -sI "https://yumenovel.fr/wp-json/yume/v1/discord/retour?state=x&code=y"`
      deux fois de suite : même `Location`, pas d'en-tête `age:` non nul ni d'en-tête de cache de page
      de WordPress.com). Sans cela, la connexion échouerait au hasard (« La connexion avec Discord a
      expiré »).
- [ ] **Administrateur** : un compte administrateur lié ou à lier est refusé (« Les administrateurs se
      connectent par la page de connexion WordPress »), sans session ouverte.
- [ ] **Adresse déjà inscrite** : un compte Discord dont l'adresse existe sur le site n'est pas lié
      tout seul (« Un compte existe déjà avec cette adresse … ») ; se connecter avec le mot de passe,
      puis **Lier mon compte Discord** dans *Mon compte* fonctionne.
- [ ] **Délier** : demande le mot de passe actuel ; pour un compte créé avec Discord, le site propose
      d'abord de définir un mot de passe (« Se déconnecter et définir un mot de passe »).
- [ ] Page *Confidentialité* : la mention de la connexion Discord (et des signalements de coquilles,
      des recommandations) est en ligne une fois validée par l'équipe (décision en attente,
      `docs/journal-des-decisions.md`).

## 13. Comptes inscrits : vérifications après la mise en production (2.6.0)

Rien à configurer : la vue **Équipe → Comptes inscrits** apparaît pour les gérants et les
administrateurs dès l'installation. À vérifier sur yumenovel.fr, connecté en **administrateur**
puis en **gérant** :

- [ ] **Accès** : la vue s'ouvre pour un gérant et un administrateur ; un traducteur ne voit pas
      l'entrée du menu et `/equipe/?vue=comptes` lui est refusé.
- [ ] **Dernière activité** : le pied de la vue indique « relevé depuis le … » (jour de
      l'installation) ; les comptes qui ont une position de lecture ont un jour amorcé, les autres
      « inconnue » jusqu'à leur prochaine visite connectée.
- [ ] **Source** : un compte créé après l'installation porte « Formulaire » ou « Discord » ; les
      anciens portent la mention « (déduite) ».
- [ ] **E-mail** : absent de la liste et de l'export standard ; visible sur la fiche ; l'export
      **avec e-mail** n'est proposé qu'à l'administrateur.
- [ ] **Journal** : consulter une fiche puis exporter ajoute deux lignes au journal (visible de
      l'administrateur).
- [ ] **Ajouter à l'équipe** depuis la fiche d'un lecteur créé avec Discord : il apparaît dans
      *Membres et rôles* avec le rôle choisi.
- [ ] **Cache** : la vue n'est jamais servie depuis le cache de page (espace équipe connecté).

**Page Confidentialité** (texte prêt à coller par un administrateur, *Réglages → Confidentialité*,
après validation de l'équipe) :

> L'équipe de gestion du site (gérants et administrateurs) peut consulter la liste des comptes
> inscrits et, pour chaque compte, le pseudo, l'adresse e-mail, la date d'inscription, le mode
> d'inscription (formulaire ou Discord), le jour de dernière activité sur le site, les séries
> suivies, la position de lecture, les favoris, les notes, les listes publiques, les commentaires et
> les signalements de coquilles, afin d'animer la communauté et de gérer l'équipe. Aucune adresse IP
> n'est conservée à cette fin. Ces consultations et les exports sont journalisés.
