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

## Règles de MangaDex respectées

User-Agent réel, aucune adresse MangaDex dans les pages (le navigateur ne contacte que le site),
au plus un autre serveur d'images après un échec, pause d'une minute après une limite (429/403),
signalement des images au service de MangaDex après la réponse (suspendu 15 min après un échec),
crédits de l'équipe et de MangaDex, lien vers chaque chapitre, aucune publicité.
