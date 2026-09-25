# Préproduction locale (tools/preprod)

Copie **locale** du futur yumenovel.fr, construite de zéro comme au jour J : ancien site
peuplé depuis l'export, migration exécutée, puis quelques jours de vie simulés (équipe,
lecteurs, publication d'un tome, planning). Elle sert à la revue par l'équipe et aux captures
comparées aux maquettes (`design/maquettes/`). **Rien n'est jamais écrit sur le site réel** :
le seed et chaque étape refusent de tourner hors environnement local.

| Fichier | Rôle |
| --- | --- |
| `construire.sh` | Construit la préproduction de zéro (≈ 40 s) |
| `preprod.php` | Étapes appelées par `construire.sh` (`wp eval-file tools/preprod/preprod.php <étape>`) |
| `lib/class-yume-preprod-images.php` | Couvertures et bannières de substitution (GD) |
| `langues/fr_FR.l10n.php` | Extrait de la traduction française du cœur de WordPress (voir plus bas) |
| `parcours.js` | Parcours Playwright : captures, erreurs de console, liens, axe-core, anciennes URL |

## Construire

Prérequis : l'environnement de `tools/localenv/` (WordPress 7.1, WP-CLI), MariaDB (moteur de la
production), l'export de l'ancien site dans `tools/migrate/export/` et, pour la publication du
tome 7 de Grimgar, `tools/fixtures/private/grimgar-t7.docx` (fichiers non versionnés).

```sh
export YUME_WP_PATH=… WP_CLI=…            # voir tools/localenv/README.md
tools/preprod/construire.sh                 # base « preprod » sous MariaDB (yume_preprod)
YUME_ENV=preprod YUME_DB_ENGINE=mysql \
  php -d upload_max_filesize=128M -d post_max_size=130M \
  -S 127.0.0.1:8090 -t "$YUME_WP_PATH" tools/localenv/router.php
```

Puis <http://127.0.0.1:8090/>. `construire.sh` supprime et recrée **sa** base à chaque
lancement (résultat identique d'une fois sur l'autre). Variables : `YUME_ENV` (défaut
`preprod`), `YUME_DB_ENGINE` (défaut `mysql` ; vide pour SQLite), `YUME_PREPROD_PORT` (8090),
`YUME_MEDIAS` (défaut `wp-content/uploads-preprod`, dossier des médias propre à cette base),
`YUME_EXPORT`, `YUME_DOCX_GRIMGAR`, `YUME_PREPROD_MDP`.

## Étapes

1. **Base neuve** et WordPress installé (extension `yume-core` et thème `yume` activés), site en
   français (`WPLANG=fr_FR`).
2. **Ancien site** : `tools/migrate/seed-local.php` (pages, articles, catégories et médias aux
   mêmes ID que la production ; les fichiers des médias sont des images de substitution).
3. **Migration** comme au jour J : `wp yume migrer --simuler` puis `wp yume migrer --yes`
   (œuvres, tomes, chapitres, pages Yume, 92 redirections 301, inscriptions ouvertes au rôle
   Lecteur, 12 articles par page).
4. **Réglages** : webhooks Discord vides (aucun appel sortant), e-mails aux lecteurs actifs.
   **Images** : la bannière actuelle du site (pièce jointe 1341 de l'ancien site, réglée par la
   migration) et les couvertures et bannières d'œuvre sont redessinées sans changer d'ID ;
   sans bannière dans le seed, une image est créée et réglée.
5. **Comptes** (voir tableau) et **compléments éditoriaux** que l'ancien site ne contient pas
   et que l'équipe saisira après la migration : équipe, source et liens de Grimgar, genres de
   sept œuvres, jours de sortie.
6. **Publication réelle du tome 7 de Grimgar** depuis le DOCX de référence, par le service de
   publication, en tant que JojoGg (éditeur) : le tome migré (liens PDF/EPUB seuls, sans
   chapitre) est **réutilisé**, ses 20 chapitres et ses illustrations sont créés et publiés en
   une seule sortie, l'annonce paraît dans « Sorties ».
7. **Planning de démonstration** (historique daté sur trois semaines) : Grimgar T.10 en
   relecture (à l'heure), Silent Witch arcs 7 et 8 **en retard**, Raven T.7 et Miss Medic T.2
   en traduction, Survival T.5 **bloqué** ; rappels du jour exécutés (journal, e-mails en file).
   **Lecteurs** : favoris, alertes, notes, positions de lecture (dont Grimgar T.7 chapitre 3),
   réglages de lecture et commentaires.

## Comptes (local uniquement)

Mot de passe commun : **`preprod`** (ou `YUME_PREPROD_MDP`) ; administrateur : `admin` / `admin`.

| Identifiant | Rôle | Dans la démonstration |
| --- | --- | --- |
| `angeloids` | Gérant | relecture de Grimgar T.10, Survival T.5 bloqué |
| `jojogg` | Éditeur Yume | a publié Grimgar T.7 ; édition de Silent Witch et Raven |
| `calumi` | Traducteur | traduction de Miss Medic T.2 (en cours), Grimgar T.10 et Silent Witch (terminées) |
| `mael7523m` | Relecteur | relecture de Silent Witch arc 8 (en retard) |
| `cerale` | Graphiste | traduction de Raven T.7 |
| `kaede` | Lectrice | Grimgar T.7 chap. 3 à 41 %, thème Sépia, Merriweather 19 px |
| `yuzu` | Lecteur | Silent Witch arc 7, thème Papier |

## Parcours et captures

```sh
npm i playwright@1.56 @axe-core/playwright     # une fois, dans un dossier de travail
NODE_PATH=<ce dossier>/node_modules node tools/preprod/parcours.js <dossier-captures> [filtre]
```

Accueil, menu Bibliothèque ouvert (bureau et mobile), bibliothèque filtrée, fiche Grimgar
(lectrice connectée et visiteur), tome 7, chapitre 1 (et panneau Paramètres), chapitre Silent
Witch migré, planning, espace équipe (traducteur et gérant), publication, compte, connexion,
actualités, article migré, recherche, 404, Yume → Migrer et anciennes URL : thèmes Nuit et
Papier (Sépia en plus pour le lecteur), 1440 et 390 px. Le rapport (`rapport.txt`) relève les
erreurs de console, les requêtes en échec, les liens internes cassés, les violations axe-core
(WCAG 2.1 AA) et vérifie les redirections 301. `YUME_CAPTURE_THEME=<fichier>` produit aussi la
capture 1200 × 900 de l'accueil (`screenshot.png` du thème).

## Limites connues de l'environnement local

- **Langue** : le WordPress local est téléchargé sans paquet de langue (wordpress.org
  injoignable). `construire.sh` installe `langues/fr_FR.l10n.php`, extrait de la traduction du
  cœur (lien d'évitement, menu mobile, mois des dates, barre d'outils, connexion), seulement si
  aucun paquet fr_FR n'est présent. En production, le paquet complet de WordPress.com s'applique.
- **Images** : substituts dessinés (dégradé, pétales, titre) ; les fichiers réels de la
  médiathèque ne sont pas téléchargeables ici.
- **Réseau** : Gravatar est injoignable (le parcours sert un avatar neutre) ; les e-mails restent
  en file (`wp_mail` sans serveur d'envoi) ; Discord n'est jamais appelé (webhooks vides).
