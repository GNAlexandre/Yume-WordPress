# Mangas MangaDex — lecture sur le site et procédures de l'équipe

Le site lit les chapitres en français que l'équipe publie sur MangaDex (groupe Yume Novel), sans
garder aucune image : chaque page est relayée par le site au moment de la lecture. Module
`mangadex` de l'extension Yume Core (`wp-content/plugins/yume-core/includes/mangadex/`). Détail
technique : `docs/06-contrat-technique.md` §8 bis ; décisions : `docs/journal-des-decisions.md`.

## En bref

| Élément | Fonctionnement |
| --- | --- |
| Œuvre | Type « Manga » ; « Lien MangaDex de la série » (facultatif) dans le formulaire équipe de l'œuvre |
| Tomes | Un volume MangaDex = un tome, créé par la synchronisation (ou repris s'il a été créé au planning avec « Nouveau tome », même numéro) et publié à son premier chapitre ; chapitres sans volume dans un tome « Chapitres ». Aucun tome ni chapitre à créer à la main, pas de fichier à envoyer |
| Chapitres | Créés par la synchronisation horaire (Yume → Mangas (MangaDex)), datés de leur sortie sur MangaDex |
| Images | Relayées par le site, jamais stockées ; « Économie de données » par défaut, « Originale » au choix du lecteur |
| Annonces | Première synchronisation d'une œuvre : ajout au catalogue sans annonce ; ensuite, annonces habituelles |

## Publication : un chapitre publié sur MangaDex est public tout de suite

MangaDex ne connaît pas notre planning : un chapitre publié là-bas est lisible par tous
immédiatement. Le site le reprend à la synchronisation suivante (au plus une heure) et l'annonce
comme les autres. Pour un chapitre qui ne doit pas encore sortir : ne pas le publier sur MangaDex,
ou l'y programmer.

## Chapitre envoyé deux fois sur MangaDex

Si le groupe publie deux fois le même numéro de chapitre (nouvel envoi, correction, erreur), le
site n'en garde qu'un : celui déjà en ligne, sinon le premier sorti. Les autres sont listés dans
le rapport (« Chapitres en double sur MangaDex (ignorés) ») avec leur lien : les supprimer sur
MangaDex. Si c'est le chapitre gardé qui est supprimé, le doublon prend sa place à la
synchronisation suivante. Ne sont pas des doublons : le même numéro dans deux volumes différents
(numérotation reprise à chaque volume) et les oneshots sans numéro.

## Lier une œuvre à sa série MangaDex

1. Espace équipe → Œuvres → « Nouvelle œuvre » (ou « Modifier » sur l'œuvre).
2. Type : « Manga ».
3. « Lien MangaDex de la série » : coller l'adresse de la série (`https://mangadex.org/title/…`).
   Une adresse de chapitre ou de groupe est refusée, comme une série déjà liée à une autre œuvre.
4. Publier l'œuvre : une œuvre en brouillon est ignorée par la synchronisation.
5. Yume → Mangas (MangaDex) → « Simuler (sans rien changer) » pour vérifier ce qui sera créé, puis
   laisser la synchronisation horaire faire (ou « Synchroniser maintenant »).

Vider le lien délie l'œuvre : ses chapitres déjà sur le site restent, plus rien n'est mis à jour.

## Demande de retrait

| Demande | Action | Effet sur le site | Retour en arrière |
| --- | --- | --- | --- |
| Œuvre licenciée en français, ou demande de l'éditeur ou d'un ayant droit | Espace équipe → Œuvres → état « Licenciée » (confirmation demandée) | Chapitres retirés de la lecture (brouillons), images refusées par le site, liens PDF/EPUB retirés ; la synchronisation ignore l'œuvre | Sortir de « Licenciée » : ce qui avait été retiré est remis en ligne, puis la synchronisation reprend les nouveaux chapitres |
| Un seul chapitre à retirer | Mettre le chapitre en brouillon (éditeur WordPress) | Chapitre hors ligne ; la synchronisation ne le remet jamais en ligne, même s'il est modifié sur MangaDex | Le republier à la main |
| Chapitre supprimé sur MangaDex | Rien | La synchronisation le met en brouillon (« retiré ») | Il revient seul s'il réapparaît sur MangaDex |
| Demande d'un autre groupe de traduction | Vérifier sur MangaDex que les chapitres sont bien ceux de Yume Novel (seuls ceux-là sont repris) ; sinon, la demande vise MangaDex, pas le site | — | — |

En cas d'urgence (toute la lecture manga à couper d'un coup) : l'administrateur ajoute le filtre
`add_filter( 'yume_mangadex_lecteur_pret', '__return_false' );` (extension de code ou mu-plugin).
Lecteur, proxy et synchronisation s'arrêtent ; chaque chapitre manga affiche alors « Lire ce
chapitre sur MangaDex ».

## Vérifications en production

Faites le 18/10/2026 sur yumenovel.fr (2.3.0, synchronisation active, Mikadono : 37 chapitres,
tomes 1 à 4), en lecture seule, sans être connecté.

### Cache des images

| Demande (Mikadono, chapitre 1, page 17) | Cache WordPress.com (`x-ac`) | Temps |
| --- | --- | --- |
| Économie de données (205 Ko), 1re fois | MISS : relayée depuis MangaDex | 1,84 s |
| Économie de données, 2e et 3e fois | HIT : servie par le cache, sans PHP ni MangaDex | 0,21 à 0,49 s |
| Originale (836 Ko), 1re fois | MISS | 2,18 s |
| Originale, 2e fois | HIT | 0,34 s |

- En-têtes : `Cache-Control: public, max-age=31536000, immutable`, `Content-Type: image/jpeg`,
  `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'`.
- Le cache est propre à chaque centre de données : la première demande passant par un autre
  centre repart d'un MISS.
- **Lecteur connecté** (cookie de connexion WordPress) : le cache de WordPress.com est contourné
  (`x-ac: BYPASS`). Sa première lecture d'une page passe par MangaDex ; son navigateur la garde
  ensuite un an. Négligeable au trafic actuel (environ 2 100 vues par mois).

### Stockage du site

- Médiathèque : aucune image MangaDex (seuls fichiers ajoutés depuis le 17/10 : illustrations de
  Grimgar of Fantasy and Ash T12 et Raven of the Inner Palace T7).
- Code : l'image reste en mémoire le temps de la réponse (ni fichier temporaire, ni pièce
  jointe) ; seules écritures, trois transients de quelques octets (serveur d'images du chapitre,
  10 min ; pause après une limite ; coupe-circuit du signalement).
- Non mesuré : l'espace disque total (non fourni par l'API de WordPress.com). À suivre dans
  *Hébergement → Vue d'ensemble*.

### Sécurité

| Contrôle (visiteur non connecté) | Résultat |
| --- | --- |
| Chapitre inconnu (autre groupe, série non liée) | 404, sans appel à MangaDex |
| Version périmée, page 0, page 999, qualité inconnue | 404 ou 400 |
| Adresse externe passée au relais (relais ouvert) | 400 |
| Chemin piégé (`../../wp-config.php`) | 404 |
| Réponses d'erreur | Jamais gardées en cache (`no-store`) |
| Synchronisation par `admin-post.php` (GET ou POST) | 400 : réservée aux gérants connectés, jeton requis |
| Chapitres en brouillon par l'API REST | 404 |
| Adresse d'un serveur d'images MangaDex dans la page du chapitre | Aucune (seulement les liens de crédit vers mangadex.org) |
| En-têtes de la page du chapitre | HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy` |
| Tome 1 de Mikadono | 7 chapitres, aucun doublon |

### Refaire le contrôle du cache

Adresse d'une page : clic droit sur une image du lecteur → « Copier l'adresse de l'image ». Lancer
deux fois de suite (PowerShell 7 ou Linux, remplacer l'adresse) :

```powershell
curl.exe -sS -o NUL -D - "https://yumenovel.fr/?yume_mdx_image=<chapitre>/<version>/data-saver/<page>" | Select-String "x-ac|cache-control"
```

Attendu : `MISS` puis `HIT` (ou `HIT` deux fois si la page a déjà été lue).

## Règles de MangaDex respectées

User-Agent réel, aucune adresse MangaDex dans les pages (le navigateur ne contacte que le site),
au plus un autre serveur d'images après un échec, pause d'une minute après une limite (429/403),
signalement des images au service de MangaDex après la réponse (suspendu 15 min après un échec),
crédits de l'équipe et de MangaDex, lien vers chaque chapitre, aucune publicité.
