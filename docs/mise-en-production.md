# Mise en production : liste de contrôle (WordPress.com et GitHub)

Liste à dérouler **avant d'ouvrir la v2 au public**, puis à revérifier à chaque changement d'équipe.
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
      pass* (jobs de `ci.yml` : **Syntaxe PHP**, **Normes de code (PHPCS)**, **Tests WordPress**,
      **Rendu WordPress**, tels qu'ils apparaissent après un premier run). Mainteneur seul : mettre 0 approbation
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
