# Tester le site en local

Trois façons de voir le nouveau Yume Novel sur votre ordinateur, **sans jamais toucher
yumenovel.fr**. Dans tous les cas, on commence par cloner le dépôt et se placer sur la branche
à relire :

```sh
git clone https://github.com/GNAlexandre/Yume-WordPress.git
cd Yume-WordPress
git checkout claude/busy-babbage-4qnb27      # ou la branche de la PR à relire
```

| | Option 1 : Playground (recommandée) | Option 2 : environnement de développement | Option 3 : préproduction complète |
| --- | --- | --- | --- |
| Pour qui | Relecture, équipe | Développement, tests | Répétition de la migration |
| Installe | Node.js | PHP 8.1+, Composer, Git | Option 2 + MariaDB + export de l'ancien site |
| Windows | Oui, directement | Via WSL | Via WSL |
| Contenu | Démo fictive | Démo fictive ou vide | Vraies données de l'ancien site migrées |
| Durée | ≈ 1 min | ≈ 5 min la première fois | ≈ 1 min après installation |

## Option 1 : WordPress Playground (le plus simple)

WordPress complet exécuté par Node.js : ni PHP, ni base de données, ni Docker.

1. Installer [Node.js](https://nodejs.org/) 22 ou plus (version LTS).
2. Depuis le dossier du dépôt :
   - **Mac / Linux** : `tools/playground/lancer.sh`
   - **Windows** (PowerShell) : `powershell -ExecutionPolicy Bypass -File tools\playground\lancer.ps1`
3. Attendre « Ready! WordPress is running on http://127.0.0.1:9400 », puis ouvrir
   <http://127.0.0.1:9400>.

Vous êtes connecté en administrateur (`admin` / `password`). Autres comptes pour essayer les rôles,
dans une fenêtre de navigation privée :

| Compte | Mot de passe | Pour voir |
| --- | --- | --- |
| `equipe` | `equipe` | Espace équipe (`/equipe/`), publication d'un tome (`/equipe/publier/`) |
| `lecteur` | `lecteur` | Compte lecteur : favoris, notes, reprise de lecture, réglages du lecteur |

À essayer : l'accueil, le menu *Bibliothèque*, la fiche de l'œuvre de démonstration
(« Les Lanternes de Brume-Haute »), la lecture d'un chapitre (bouton ⚙ pour les réglages), le
planning, et **Publier un tome** avec un DOCX (par exemple `tools/fixtures/*.docx`).

Bon à savoir :

- le plugin et le thème viennent **de votre clone** : après un `git pull`, rechargez la page ;
- le site repart de zéro à chaque lancement (rien n'est conservé) ; `Ctrl+C` pour l'arrêter ;
- l'administration de WordPress peut rester en partie en anglais si la traduction n'a pas pu
  être téléchargée ; le site public est en français ;
- aucun e-mail ne part et aucun webhook Discord n'est configuré ;
- les pages institutionnelles (L'équipe, FAQ, Contact…) sont de courtes pages de démonstration ; sur
  le vrai site, ce sont les pages existantes, conservées par la migration ;
- pour commenter, il faut être connecté (par exemple avec `lecteur` / `lecteur`), comme sur le vrai
  site après la migration ;
- une adresse qui n'existe pas affiche l'accueil dans Playground au lieu de la page 404 : c'est
  propre à Playground (l'option 2 et le vrai site renvoient bien une 404).

## Option 2 : environnement de développement (PHP)

C'est l'environnement des tests automatiques et de la CI : WordPress avec SQLite, WP-CLI, plugin et
thème du dépôt liés. Prérequis et détails : [`tools/localenv/README.md`](../tools/localenv/README.md)
(sous Windows, utiliser WSL avec Ubuntu).

```sh
tools/localenv/setup.sh --source wp-cli          # une seule fois
. ~/.local/share/yume-localenv/env.sh            # dans chaque nouveau terminal
tools/localenv/wp.sh eval-file tools/playground/demo.php   # contenu de démonstration
tools/localenv/serve.sh                          # http://127.0.0.1:8080, compte admin / admin
tools/localenv/test.sh                           # lancer tous les tests
```

Ici la base est conservée d'un lancement à l'autre (`YUME_ENV` choisit une base séparée).

## Option 3 : préproduction avec les vraies données

Reconstruit une copie locale du futur site : l'ancien yumenovel.fr, puis la migration exécutée,
puis quelques jours de vie simulés (comptes, planning, publication du tome 7 de Grimgar).
Elle demande MariaDB et deux fichiers **non versionnés** : l'export de l'ancien site
(`tools/migrate/export/`) et le DOCX de Grimgar (`tools/fixtures/private/grimgar-t7.docx`).
Ces fichiers ne sont pas sur GitHub : demandez-les à l'équipe technique.
Mode d'emploi : [`tools/preprod/README.md`](../tools/preprod/README.md).
