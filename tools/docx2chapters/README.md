# docx2chapters

Outil en ligne de commande (PHP ≥ 8.1, extensions `zip`, `xml`, `dom`, `mbstring` ; `curl` pour
publier) qui découpe le DOCX d'un tome — ou, en dépannage, son EPUB — en chapitres Yume, **sans
WordPress**. Il utilise exactement le convertisseur du plugin
(`wp-content/plugins/yume-core/includes/import/`, docs/04 §2 et §3, contrat §9) : ce que l'outil
affiche est ce que le formulaire `/equipe/publier/` créera.

## Commandes

```sh
# Aperçu local : resultat.json + un fichier HTML par chapitre + les images
php tools/docx2chapters/docx2chapters.php convert tome.docx --out /tmp/tome10

# Rapport seul (chapitres, mots, avertissements), rien n'est écrit
php tools/docx2chapters/docx2chapters.php analyse tome.docx
php tools/docx2chapters/docx2chapters.php analyse tome.epub --json

# Envoi au site : tome et chapitres en brouillon (ou mis à jour s'ils existent déjà).
# Mot de passe d'application lu dans ~/.config/yume/credentials ou YUME_APP_PASSWORD (voir plus bas).
php tools/docx2chapters/docx2chapters.php publish tome.docx \
  --site https://yumenovel.fr --user pseudo \
  --oeuvre 12 --numero 10 --pdf https://www.clictune.com/xxxx --epub https://www.clictune.com/yyyy \
  --traduction Calumi --relecture Angeloids --edition JojoGg

# … et publication immédiate ou programmée
php tools/docx2chapters/docx2chapters.php publish tome.docx … --publier maintenant
php tools/docx2chapters/docx2chapters.php publish tome.docx … --publier 2026-09-27T13:00

# Tome déjà paru (PDF/EPUB seuls) : ajouter la lecture en ligne sans rien annoncer
php tools/docx2chapters/docx2chapters.php publish tome.docx … --numero 3 --publier maintenant --sans-annonce
```

`php tools/docx2chapters/docx2chapters.php aide` liste toutes les options.

### convert

Écrit dans le dossier `--out` :

| Fichier | Contenu |
| --- | --- |
| `resultat.json` | Le `Result` du contrat §9 : `chapters` (numéro, nature, titre, sous-titre, blocs Gutenberg, nombre de mots, statistiques), `front_images`, `images`, `warnings`, `stats` |
| `index.html` | Sommaire, avertissements, galerie du tome (images placées avant le premier chapitre) |
| `chapitres/NN-titre.html` | Aperçu de chaque chapitre avec la typographie du lecteur (dialogues, pensées, séparateurs, notes) |
| `images/` | Illustrations gardées (JPG, PNG, WebP, GIF), extraites sans être recompressées |

Les blocs de `resultat.json` référencent les images par un jeton `{{yume-image:<clé>}}` ; sur le
site, le module publication les remplace par les pièces jointes de la médiathèque.

### publish

Envoie le fichier à `POST /wp-json/yume/v1/publications` (multipart), puis, avec `--publier`,
appelle `POST /wp-json/yume/v1/publications/{id}/publier`. Le fichier est d'abord analysé en local :
un fichier illisible n'est jamais envoyé.

- **Authentification** : mot de passe d'application WordPress (Profil → Mots de passe
  d'application) d'un compte **Éditeur Yume** ou **Gérant** (capacité `yume_publier`), créé pour
  cet outil seul et nommé (« docx2chapters – poste de X ») pour pouvoir le révoquer. WordPress
  n'accepte les mots de passe d'application qu'en HTTPS (ou sur un site local). Voir
  [Mot de passe d'application](#mot-de-passe-dapplication).
- **Réutilisation** : si le tome existe déjà (même œuvre, nature et numéro — par exemple le
  brouillon créé par le planning), il est mis à jour : ses adresses sont conservées et ses
  chapitres sont remplacés en place, sans doublon. `--retirer-absents` met en brouillon les
  chapitres qui ne sont plus dans le fichier.
- **Annonce** : `--sans-annonce` met la lecture en ligne sans article d'annonce, ni message
  Discord, ni e-mail aux lecteurs (ajout au catalogue d'un tome déjà paru) ; `--avec-annonce`
  force l'annonce. Sans l'une ni l'autre, le site choisit : **sans annonce pour un tome déjà
  publié**, avec annonce pour un nouveau tome, un brouillon ou un tome programmé (paramètre REST
  `sans_annonce`, contrat §12). Les chapitres d'un tome déjà paru ajoutés sans annonce prennent la
  date de sortie du tome.
- **Liens PDF / EPUB** : uniquement des liens externes (ClicTune, Mega…). Les fichiers PDF et EPUB
  ne sont jamais hébergés sur le site.
- Le DOCX envoyé n'est pas conservé sur le serveur : il est supprimé après le découpage.

Codes de sortie : `0` réussite, `1` erreur (fichier refusé, erreur du site), `2` utilisation
incorrecte.

## Mot de passe d'application

Ne le tapez **pas** dans la ligne de commande : il resterait dans l'historique du shell et serait
visible des autres utilisateurs de la machine (`ps`). L'outil le cherche dans cet ordre :

1. `--app-password` s'il est donné (déconseillé ; l'outil affiche alors un avertissement) ;
2. la variable d'environnement `YUME_APP_PASSWORD` ;
3. le fichier d'identifiants `~/.config/yume/credentials` (ou `$XDG_CONFIG_HOME/yume/credentials`,
   ou le chemin donné par `YUME_CREDENTIALS`), qui doit n'être lisible que par vous : l'outil
   refuse un fichier lisible par d'autres.

Méthode conseillée, une fois pour toutes :

```sh
mkdir -p ~/.config/yume && chmod 700 ~/.config/yume
( umask 077; printf 'YUME_APP_PASSWORD=%s\n' "$(read -rsp 'Mot de passe d’application : ' p; echo "$p")" > ~/.config/yume/credentials )
chmod 600 ~/.config/yume/credentials
```

Le fichier contient une ligne `YUME_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx` (les lignes
commençant par `#` sont ignorées). Pour une seule session, `read -rs YUME_APP_PASSWORD && export
YUME_APP_PASSWORD` évite aussi l'historique. Sous Windows (PowerShell) :
`$env:YUME_APP_PASSWORD = Read-Host -MaskInput`.

En cas de fuite (poste perdu, capture d'écran, départ d'un membre) : Profil → Mots de passe
d'application → **Révoquer**, puis en créer un nouveau.

## Préparer le DOCX

Mêmes règles que dans le guide de l'équipe (`docs/guide-equipe.md` §5.1) : style **Titre 1** pour
chaque chapitre (« Chapitre 1 », « Prologue »…), **Titre 2** juste après pour le sous-titre ou seul
pour un chapitre spécial (« Postface »), puce « — » (ou tiret en début de paragraphe) pour les
dialogues, style **Pensée** pour les pensées, `***` / `* * *` / `◇` seuls sur une ligne pour les
changements de scène. Les images EMF/WMF (ornements Word) sont ignorées et signalées.

## Fichiers de test

`tools/fixtures/build-fixtures.php` génère les DOCX et EPUB synthétiques utilisés par les tests
(`tests/test-import.php`, `tests/test-publication.php`) :

```sh
php tools/fixtures/build-fixtures.php            # réécrit tools/fixtures/*.docx|*.epub
php tools/docx2chapters/docx2chapters.php convert tools/fixtures/regles.docx --out /tmp/regles
```

Le DOCX de travail privé se place dans `tools/fixtures/private/` (ignoré par Git) : les tests
l'utilisent s'il est présent.
