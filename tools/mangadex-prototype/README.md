# Prototype MangaDex (spike, jetable)

> **Remplacé par la vraie intégration** (module `mangadex` de Yume Core, version 2.3.0) : voir
> `docs/mangadex.md` et `docs/06-contrat-technique.md` §8 bis. Ce dossier reste pour l'historique
> des mesures ; ne pas l'installer à côté du module.

**Question :** peut-on publier les mangas de Yume Novel sur MangaDex (stockage et visibilité) et
les faire lire **sur yumenovel.fr** comme si les pages y étaient, avec le site actuel sur
WordPress.com ? Et le relais des images peut-il tenir dans WordPress (option A), ou faut-il un
service à part, par exemple un Cloudflare Worker (option B) ?

Ce dossier n'est **pas livré** avec Yume Core (`tools/` n'entre pas dans les archives). C'est un
plugin autonome à essayer d'abord **sur ton PC** ; un essai en production n'est envisageable que
bref, encadré et réversible (étape 2 ci-dessous), puisqu'il n'y a pas de préproduction.

| Fichier | Rôle |
| --- | --- |
| `yume-mangadex-proto.php` | Plugin : client de l'API MangaDex, proxy d'images, codes courts du lecteur, page Outils → Prototype MangaDex (liste blanche, mesures) |
| `lecteur.js`, `lecteur.css` | Lecteur : défilement ou page par page, sens manga (droite → gauche), qualité, crédits, bandeau de mesure |
| `mesurer.mjs` | Mesure du proxy depuis un poste (Node.js 18+) : temps, volumes, détection d'un cache de bord |

## Ce qui est vérifié (16 octobre 2026)

| Point | Constat | Conséquence |
| --- | --- | --- |
| Iframe de mangadex.org | `X-Frame-Options: DENY` | Impossible d'intégrer le lecteur de MangaDex : lecteur maison |
| Images liées depuis un autre site | Une image de `uploads.mangadex.org` demandée avec `Referer: https://yumenovel.fr/` renvoie l'image « You can read this at MangaDex » ; les nœuds MD@Home ont servi la vraie image ce jour-là, mais les règles de l'API imposent de **relayer** (« MUST proxy ») | Les images passent par notre serveur ; le navigateur du lecteur ne contacte jamais MangaDex (vérifié dans Chromium : aucune requête hors du site) |
| Limites | En-têtes `x-ratelimit-limit: 40` sur `/at-home/server/{id}` ; environ 5 requêtes/s par IP sur l'API | Le serveur du site est **une seule IP pour tous les lecteurs** : réponses mises en cache (adresse MD@Home 10 min, chapitres et séries 1 h) |
| Cache des images chez MangaDex | `Cache-Control: public, max-age=604800, immutable` | Une page à adresse donnée ne change jamais : le proxy la renvoie avec un cache d'un an |
| Poids (un chapitre webtoon de 9 pages, mesuré) | Originale (`data`) : 11,2 Mo, 1,28 Mo par page en moyenne ; économie de données (`data-saver`) : 2,9 Mo, 325 Ko par page | `data-saver` par défaut, l'originale au choix du lecteur |
| Temps de téléchargement chez MangaDex depuis PHP (environnement cloud du prototype, 72 images) | p50 de 0,48 à 0,67 s, p95 de 0,6 à 2 s par image | Acceptable, mais chaque image non mise en cache occupe un processus PHP pendant ce temps |
| Signalement `api.mangadex.network/report` (exigé pour les nœuds MD@Home) | **Non joignable depuis l’environnement cloud où le prototype a été écrit** (délai dépassé, même avec curl) : la cause, réseau de cet environnement ou MangaDex, n’est pas connue | À vérifier depuis ton PC (étape 1) : la page Outils affiche le résultat des 50 derniers signalements |
| Conditions d'usage | Créditer MangaDex et les groupes, honorer les demandes de retrait, **pas de publicité ni de service payant** (dons acceptés) | Respectées : aucune pub sur le site (confirmé par Alexandre), Ko-fi = dons |

Sources : [Acceptable Usage Policy](https://api.mangadex.org/docs/),
[Limitations and Requirements](https://api.mangadex.org/docs/2-limitations),
[Retrieving a chapter](https://api.mangadex.org/docs/04-chapter/retrieving-chapter/).

## Ce que fait le prototype

- **Lecture** : `[yume_mangadex_serie manga="uuid"]` liste les chapitres en français de la série
  (flux MangaDex), un clic ouvre le lecteur sur la même page (`?chapitre=uuid`) ;
  `[yume_mangadex chapitre="uuid"]` affiche directement un chapitre. Crédits du groupe et lien
  « Lire sur MangaDex » toujours visibles ; chapitre hébergé ailleurs (`externalUrl`) refusé.
- **Proxy**, deux voies pour comparer ce que le cache de WordPress.com garde :
  - `rest` : `/wp-json/yume-proto/v1/image/{chapitre}/{empreinte}/{qualite}/{n}` ;
  - `front` : `/?yume_mdx_image={chapitre}/{empreinte}/{qualite}/{n}` (traitée au tout début de
    WordPress ; c'est la forme qu'un cache de pages comme Batcache peut garder).
  `{empreinte}` = 8 premiers caractères du hash MD@Home : l'adresse change si le chapitre est
  remplacé sur MangaDex. Réponse `Cache-Control: public, max-age=31536000, immutable` et
  `X-Yume-Proto-Genere` (date de génération) : deux réponses avec la même date prouvent qu'un cache
  a servi la seconde sans passer par PHP.
- **Pas de relais ouvert** : seuls les chapitres présents dans le flux (en cache 1 h) des séries
  de la liste blanche (Outils → Prototype MangaDex) sont relayés ; un identifiant inconnu est
  refusé **sans aucun appel à MangaDex** ; la page est désignée par son numéro (jamais une URL),
  types d'image contrôlés, 15 Mo au plus. Vérifié : chapitre hors liste → 403, chemin forgé → 400,
  page ou version inconnue → 404.
- **Règles MangaDex** : User-Agent réel (`YumeNovel/<version> (+https://yumenovel.fr/; …)`), pas
  d'en-tête `Via`, aucune requête répétée après un 429/403, un seul nouvel essai après un échec
  d'image (nouveau nœud MD@Home, comme le demande la documentation), signalement de chaque image
  venue d'un nœud MD@Home **après** l'envoi de l'image au lecteur (`fastcgi_finish_request()` ;
  même « non bloquant », le signalement retenait la réponse 3 s ici).
- **Mesures** : chaque image relayée est notée (durée chez MangaDex, taille, cache MangaDex, nœud) ;
  la page Outils en fait la synthèse par voie et qualité.

## Essayer

Il n'y a pas de préproduction : le site WordPress.com est la production. D'où deux temps.

### Étape 1 : sur ton PC (sans risque)

Avec l'environnement de développement du dépôt (option 2 de `docs/tester-en-local.md` : sous
Windows, dans WSL avec Ubuntu), car le prototype doit appeler MangaDex depuis PHP. Playground
(option 1) n'a pas été essayé avec le prototype : ses requêtes sortantes vers MangaDex ne sont pas
vérifiées.

```sh
tools/localenv/setup.sh --source wp-cli                     # une seule fois
. ~/.local/share/yume-localenv/env.sh
export YUME_ENV=mangadex                                    # base séparée
ln -sfn "$PWD/tools/mangadex-prototype" "$YUME_WP_PATH/wp-content/plugins/yume-mangadex-proto"
tools/localenv/wp.sh plugin activate yume-mangadex-proto
tools/localenv/wp.sh option update yume_mdx_series '["<uuid de la série>"]' --format=json
tools/localenv/wp.sh post create --post_type=page --post_status=publish --post_title="Test MangaDex" \
  --post_name=test-mangadex --post_content='[yume_mangadex_serie manga="<uuid de la série>"]'
tools/localenv/serve.sh                                     # http://127.0.0.1:8080/test-mangadex/
```

À vérifier :

1. Le lecteur : liste des chapitres, défilement et page par page, sens, qualité, sur ordinateur et
   sur téléphone (même réseau : `YUME_HOTE=0.0.0.0 tools/localenv/serve.sh`, puis l'adresse du PC).
2. Outils → Prototype MangaDex (`admin` / `admin`) : **les signalements à MangaDex réussissent-ils
   depuis ta connexion ?** Ils échouaient depuis l'environnement où le prototype a été écrit.
3. Le volume relayé pour un chapitre type de vos séries (Mo par chapitre, en économie de données
   et en originale).

Les temps mesurés en local ne disent rien de WordPress.com (voir « Limites »).

### Étape 2 (facultative) : essai bref en production

La seule inconnue que le PC ne peut pas lever est le comportement de WordPress.com : son cache
garde-t-il les images relayées, et combien de requêtes simultanées PHP tient-il ? L'essai :

1. **Avant** : sauvegarde du site (Jetpack, voir `docs/mise-en-production.md`) ; choisir un
   créneau calme.
2. Zipper le dossier (`cd tools && zip -r mangadex-prototype.zip mangadex-prototype`), Extensions
   → Ajouter → Téléverser, activer ; liste blanche = **une seule** série ; page de test en
   **brouillon** (le lecteur se prévisualise connecté) ou protégée par un mot de passe.
3. Mesurer depuis ton PC, sur un chapitre de 20 pages ou plus :

   ```sh
   node tools/mangadex-prototype/mesurer.mjs https://yumenovel.fr <chapitre> --voie rest  --concurrence 4 --passes 2
   node tools/mangadex-prototype/mesurer.mjs https://yumenovel.fr <chapitre> --voie front --concurrence 4 --passes 2
   node tools/mangadex-prototype/mesurer.mjs https://yumenovel.fr <chapitre> --voie front --concurrence 8 --passes 1 --qualite data
   ```

   À relever : « servies par un cache (génération identique) » à la 2e passe, les en-têtes `x-ac`
   (cache de WordPress.com) ou `age`, le délai avant en-têtes p50/p95, les échecs (502, 503, 504) ;
   puis la page Outils → Prototype MangaDex (erreurs, signalements).
4. **Aussitôt après** : désactiver et supprimer l'extension, supprimer la page de test et les
   options `yume_mdx_series`, `yume_mdx_mesures`, `yume_mdx_rapports` (les transients `yume_mdx_*`
   expirent seuls).

Risques, tous limités par la liste blanche et la durée : charge PHP pendant les mesures (quelques
dizaines de requêtes), écriture en base à chaque image relayée (mesures), appels à MangaDex depuis
l'IP du site (au plus un flux par série et par heure, une adresse MD@Home par chapitre et par
10 min). Rien ne change pour les visiteurs tant que la page de test n'est pas publiée. Sans cet
essai, choisir d'emblée l'option B (voir ci-dessous) est le choix prudent.

### Critères de décision

| Constat lors de l'essai | Décision |
| --- | --- |
| 2e passe servie par un cache (même `X-Yume-Proto-Genere`, `x-ac` HIT) sur l'une des voies | **A** : le proxy reste dans WordPress, sur cette voie ; PHP ne travaille qu'au premier lecteur de chaque page |
| Pas de cache, mais p95 < 2 s et aucun échec à 8 requêtes simultanées | **A possible** pour un petit lectorat ; à surveiller (processus PHP occupés) |
| Pas de cache et lenteurs ou erreurs 503/504 à 8 requêtes simultanées | **B** : Worker Cloudflare (offre gratuite limitée à environ 100 000 requêtes par jour, à vérifier) sur un sous-domaine, par exemple `images.yumenovel.fr`, avec le cache de Cloudflare ; WordPress ne fait plus que les pages |
| Signalements en échec depuis ton PC et depuis le site | À éclaircir avec MangaDex (salon `#dev-talk-api` de leur Discord) avant toute mise en ligne |

## Limites de ce prototype

- **Mesures locales peu représentatives** : le serveur intégré de PHP (`tools/localenv/serve.sh`)
  traite une requête à la fois et n'envoie la réponse qu'à la fin du script, signalement compris ;
  d'où 3 à 5 s par image ici, contre environ 0,5 s de téléchargement réel. Sur WordPress.com
  (PHP-FPM), `fastcgi_finish_request()` libère le lecteur dès l'envoi.
- **Pas testé sur WordPress.com** : ni le cache de bord, ni le nombre de processus PHP, ni le
  signalement. C'est l'objet de l'étape 2.
- Flux limité aux 100 premiers chapitres en français d'une série (une page de l'API) : suffisant
  pour l'essai, à paginer pour de vrai.
- Mesures dans une option (une écriture en base par image) : acceptable pour un essai, pas en
  production.
- Lecteur minimal : ni marque-page, ni commentaires, ni lecture hors ligne.

## Si le prototype est concluant : la vraie intégration (faite en 2.3.0, module `mangadex`)

1. **Œuvre « manga »** (le type existe déjà, taxonomie `yume_type`) avec ses identifiants
   MangaDex (série, groupe Yume Novel), et chapitres `yume_chapitre` « hébergés sur MangaDex »
   (UUID, pages, empreinte) au lieu d'un contenu importé du DOCX.
2. **Synchronisation** planifiée (WP-Cron) du flux français du groupe : nouveau chapitre sur
   MangaDex → chapitre créé sur le site, puis circuit habituel (planning, annonces Discord et
   e-mail, notifications des lecteurs qui suivent l'œuvre, commentaires, marque-page).
3. **Proxy** retenu (A ou B) limité aux chapitres de la base ; lecteur intégré au thème (réglages
   de lecture, thèmes Nuit/Papier/Sépia), crédits obligatoires, repli « Lire sur MangaDex ».
4. **Règles** : état « Licenciée » de l'œuvre = lecture masquée ; procédure pour les demandes de
   retrait d'un groupe ; rappel qu'un chapitre publié sur MangaDex y est public tout de suite (le
   site peut seulement en différer l'annonce).
5. Tests (API simulée, comme `tests/test-updater.php`), documentation, guide de l'équipe.
