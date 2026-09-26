# WordPress Playground (préproduction dans le navigateur)

[WordPress Playground](https://wordpress.org/playground/) fait tourner un WordPress complet dans le
navigateur, sans serveur ni installation. Les blueprints de ce dossier préparent un site Yume Novel
prêt à explorer : français, plugin `yume-core` et thème `yume` actifs, contenu de démonstration.
Idéal pour faire relire une version par l'équipe avant de la publier.

| Fichier | Installe | Quand l'utiliser |
| --- | --- | --- |
| `blueprint.json` | `yume-core.zip` et `yume.zip` de la **dernière release** GitHub (`releases/latest/download/…`) | Vérifier la version publiée (ou sur le point de l'être) |
| `blueprint-branche.json` | Le plugin et le thème lus **directement dans la branche** `main` du dépôt (ressource `git:directory`) | Relire du travail non publié, avant toute release |
| `blueprint-local.json` | Le plugin et le thème **de votre clone**, montés par le CLI Playground (`lancer.sh`, `lancer.ps1`) | Tester en local une branche ou des modifications non poussées |
| `lancer.sh`, `lancer.ps1` | — | Lancent Playground en local sur le clone (Mac/Linux, Windows) : voir [docs/tester-en-local.md](../../docs/tester-en-local.md) |
| `demo.php` | — | Source du contenu de démonstration (étape `runPHP`) |
| `construire.php` | — | Régénère les trois blueprints à partir de `demo.php` |

## Ouvrir

Le dépôt doit être **public** (Playground lit les fichiers sans authentification) :

- dernière release :
  `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/GNAlexandre/Yume-WordPress/main/tools/playground/blueprint.json`
- branche `main` :
  `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/GNAlexandre/Yume-WordPress/main/tools/playground/blueprint-branche.json`

On peut aussi coller le contenu d'un blueprint dans l'éditeur de blueprint de
playground.wordpress.net, ou lancer Playground en local sur le clone (Node.js 22+) :

```sh
tools/playground/lancer.sh                                                  # Mac / Linux
powershell -ExecutionPolicy Bypass -File tools\playground\lancer.ps1        # Windows
```

Puis <http://127.0.0.1:9400>. Guide pas à pas : [docs/tester-en-local.md](../../docs/tester-en-local.md).

Comptes créés : `admin` / `password` (connecté d'office), `equipe` / `equipe` (rôle Éditeur Yume :
espace équipe, publication), `lecteur` / `lecteur` (lecteur).

Contenu de démonstration (fictif) : l'œuvre « Les Lanternes de Brume-Haute » (light novel, en cours),
un tome 1 publié avec liens PDF/EPUB d'exemple et deux chapitres (dialogues, pensées, séparateur de
scène), un tome 2 planifié en traduction à 40 %, un article d'actualité, et les pages du contrat
(§11) : `/bibliotheque/`, `/planning/`, `/equipe/`, `/equipe/publier/`, `/compte/`, `/connexion/`.

## Limites

- Aucune release n'existe tant que l'équipe n'a pas donné son « Go » (contrat §0 bis) :
  `blueprint.json` échoue d'ici là, utilisez `blueprint-branche.json`.
- Tester une autre branche : `php tools/playground/construire.php --branche=ma-branche --sortie=/tmp`
  puis coller `/tmp/blueprint-branche.json` dans l'éditeur de Playground.
- Les archives de release GitHub ne sont pas servies avec les en-têtes CORS : Playground les
  télécharge via son proxy CORS. En cas d'échec de téléchargement, utiliser la variante branche.
- Tout est stocké dans l'onglet du navigateur : recharger la page repart de zéro. Les courriels ne
  partent pas ; les webhooks Discord ne sont pas configurés.

## Modifier le contenu de démonstration

1. Modifier `demo.php` (il doit rester relançable sans créer de doublon).
2. L'essayer sur l'environnement local :
   `YUME_ENV=demo tools/localenv/wp.sh eval-file tools/playground/demo.php`
3. Régénérer les blueprints : `php tools/playground/construire.php`
   (la CI vérifie qu'ils sont à jour avec `--verifier`).
